<?php

namespace App\Http\Controllers;

use App\Enums\DeliveryStatus;
use App\Enums\SalesOrderStatus;
use App\Exceptions\WorkflowException;
use App\Models\Delivery;
use App\Models\DeliveryAttemptLog;
use App\Models\DeliveryRelease;
use App\Models\InboundDelivery;
use App\Models\OrderFulfillment;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\Team;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Delivery\FulfillmentService;
use App\Services\Documents\DocumentPdf;
use App\Services\Procurement\InboundReceiving;
use App\Services\Procurement\ProcurementWorkflow;
use App\Services\Sales\SalesOrderService;
use App\Support\DateNormalizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DeliveryController extends Controller
{
    /**
     * Display deliveries monitoring module and delivery staff dashboard.
     */
    public function index(Request $request): Response
    {
        $team = $this->getTeam($request);
        abort_if(! $team, 403, 'No active team selected.');

        $user = $request->user();
        $isAdmin = $user->isAdmin();
        $isDeliveryStaff = ! $isAdmin && ($user->role === 'staff_deliveries' || in_array('deliveries', $user->assignedModules(), true));

        // Active tab: delivery staff are restricted to outbound customer deliveries.
        $activeTab = $isDeliveryStaff ? 'outbound' : $request->input('tab', 'outbound');

        // ---------------------------------------------------------
        // 1. OUTBOUND CUSTOMER DELIVERIES (Order Fulfillments)
        // ---------------------------------------------------------
        $outboundQuery = OrderFulfillment::where('team_id', $team->id)
            ->with([
                'team:id,slug', // proof_url needs the team slug on every row
                'sale:id,order_number,invoice_number,client_name,grand_total,sale_date,status,channel,fulfillment_type,hold_reason',
                'sale.items:id,sale_id,sku,product_name,quantity,unit_price,reserved_quantity,deducted_quantity,released_quantity,delivered_quantity,cancelled_quantity',
                'releases.items.saleItem:id,sku,product_name',
                'releases.acknowledgedBy:id,name',
                'commercialInvoice:id,invoice_number,total_cost',
                'handledBy:id,name,email,role',
                'rescheduledBy:id,name',
                'attemptLogs.staff:id,name',
            ]);

        // RBAC: Delivery staff can view ONLY deliveries assigned to them
        if ($isDeliveryStaff) {
            $outboundQuery->where('handled_by_id', $user->id);
        } elseif ($request->filled('staff_id') && $request->input('staff_id') !== 'All') {
            $outboundQuery->where('handled_by_id', (int) $request->input('staff_id'));
        }

        // Filters
        $search = $request->input('search');
        if ($search) {
            $outboundQuery->where(function ($q) use ($search) {
                $q->where('fulfillment_number', 'like', "%{$search}%")
                    ->orWhere('recipient_name', 'like', "%{$search}%")
                    ->orWhere('delivery_address', 'like', "%{$search}%")
                    ->orWhere('contact_number', 'like', "%{$search}%")
                    ->orWhereHas('sale', fn ($sq) => $sq->where('order_number', 'like', "%{$search}%")->orWhere('invoice_number', 'like', "%{$search}%")->orWhere('client_name', 'like', "%{$search}%"));
            });
        }

        $status = $request->input('status', 'All');
        if ($status && $status !== 'All') {
            $groups = self::STATUS_GROUPS;
            $outboundQuery->whereIn('status', $groups[$status] ?? [$status]);
        }
        $dateFrom = $request->input('date_from');
        if ($dateFrom) {
            $outboundQuery->whereDate('delivery_schedule', '>=', $dateFrom);
        }

        $dateTo = $request->input('date_to');
        if ($dateTo) {
            $outboundQuery->whereDate('delivery_schedule', '<=', $dateTo);
        }

        $outboundDeliveries = $outboundQuery
            ->latest('id')
            ->paginate(12, ['*'], 'outbound_page')
            ->withQueryString();

        // Outbound Metrics - computed in a single aggregate query
        $toPrepareList = "'".implode("','", self::STATUS_GROUPS['to_prepare'])."'";
        $deliveredList = "'".implode("','", self::STATUS_GROUPS['delivered'])."'";
        $maxAttempts = OrderFulfillment::MAX_ATTEMPTS;

        $metricsRow = OrderFulfillment::where('team_id', $team->id)
            ->when($isDeliveryStaff, fn ($q) => $q->where('handled_by_id', $user->id))
            ->selectRaw("
                COUNT(*) as total_assigned,
                SUM(CASE WHEN status IN ({$toPrepareList}) AND attempt_count = 0 THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN status = 'in_transit' THEN 1 ELSE 0 END) as in_transit,
                SUM(CASE WHEN status IN ({$deliveredList}) THEN 1 ELSE 0 END) as delivered,
                SUM(CASE WHEN status = 'ready_for_delivery' AND attempt_count > 0 THEN 1 ELSE 0 END) as failed,
                SUM(CASE WHEN status = 'in_transit' AND attempt_count < {$maxAttempts} THEN 1 ELSE 0 END) as attempts_remaining
            ")
            ->first();

        $outboundMetrics = [
            'totalAssigned' => (int) ($metricsRow->total_assigned ?? 0),
            'pending' => (int) ($metricsRow->pending ?? 0),
            'inTransit' => (int) ($metricsRow->in_transit ?? 0),
            'delivered' => (int) ($metricsRow->delivered ?? 0),
            'failed' => (int) ($metricsRow->failed ?? 0),
            'attemptsRemaining' => (int) ($metricsRow->attempts_remaining ?? 0),
        ];

        $outboundDeliveries->getCollection()->each(function (OrderFulfillment $f) use ($user) {
            $f->setAttribute('allowed_actions', $this->allowedActions($f, $user));
            $f->setAttribute('status_choices', $this->statusChoices($f, $user));
            $open = $f->releases->first(fn ($r) => in_array($r->status, DeliveryRelease::OPEN, true));
            $f->setAttribute('open_release', $open);
            $f->setAttribute('needs_reschedule', $f->needsReschedule($open));
            // Delete button state (admins only; the server checks again on delete).
            $f->setAttribute('delete_blocked_reason', $user->isAdmin() ? self::fulfillmentDeleteBlockedReason($f) : null);
        });
        // Available Delivery Staff list (for Admin assignment dropdown)
        $deliveryStaffList = [];
        if ($isAdmin) {
            $deliveryStaffList = User::where('current_team_id', $team->id)
                ->where('status', 'Active')
                ->where(function ($q) {
                    $q->where('role', 'staff_deliveries')
                        ->orWhere('role', 'like', 'staff%')
                        ->orWhereIn('role', ['admin', 'owner']);
                })
                ->orderBy('name')
                ->get(['id', 'name', 'email', 'role']);
        }

        // ---------------------------------------------------------
        // 2. INBOUND SUPPLIER DELIVERIES (Receiving Log)
        // ---------------------------------------------------------
        $inboundDeliveries = null;
        $inboundMetrics = null;
        $products = [];
        $suppliers = [];
        $purchaseOrders = [];

        if (! $isDeliveryStaff) {
            // One row per delivery record (Delivery Date, Delivery #); its products are opened with View.
            $inboundQuery = InboundDelivery::where('team_id', $team->id)
                ->whereHas('items')
                ->with(['supplier:id,name', 'confirmedBy:id,name'])
                ->withCount('items')
                ->withSum(['items as received_total' => fn ($q) => $q->where(fn ($w) => $w->whereNull('status')->orWhere('status', '!=', 'Cancelled'))], 'quantity')
                ->withSum('items as accepted_total', 'accepted_quantity')
                ->withSum('items as rejected_total', 'rejected_quantity')
                ->withCount(['items as uninspected_count' => fn ($q) => $q->whereNull('inspection_result')]);

            if ($search && $activeTab === 'inbound') {
                $inboundQuery->where(function ($q) use ($search) {
                    $q->where('delivery_number', 'like', "%{$search}%")
                        ->orWhere('purchase_order_number', 'like', "%{$search}%")
                        ->orWhereHas('items', fn ($iq) => $iq->where('description', 'like', "%{$search}%"))
                        ->orWhereHas('supplier', fn ($sq) => $sq->where('name', 'like', "%{$search}%"));
                });
            }

            if ($dateFrom && $activeTab === 'inbound') {
                $inboundQuery->whereDate('delivery_date', '>=', $dateFrom);
            }
            if ($dateTo && $activeTab === 'inbound') {
                $inboundQuery->whereDate('delivery_date', '<=', $dateTo);
            }

            $inboundDeliveries = $inboundQuery->latest('delivery_date')->latest('id')->paginate(15, ['*'], 'inbound_page')->withQueryString();
            $inboundDeliveries->getCollection()->each(function (InboundDelivery $d) {
                $d->setAttribute('quality_status', match (true) {
                    $d->isCancelled() => 'Cancelled',
                    (int) $d->uninspected_count > 0 => 'Pending Inspection',
                    (int) $d->rejected_total === 0 => 'Passed',
                    (int) $d->accepted_total === 0 => 'Failed',
                    default => 'Partially Passed',
                });
            });

            $inboundRow = InboundDelivery::where('inbound_deliveries.team_id', $team->id)->where('inbound_deliveries.status', InboundDelivery::CONFIRMED)
                ->join('deliveries', 'deliveries.inbound_delivery_id', '=', 'inbound_deliveries.id')
                ->whereNull('deliveries.deleted_at')
                ->selectRaw("
                    COUNT(DISTINCT inbound_deliveries.id) as total_deliveries,
                    COALESCE(SUM(deliveries.quantity), 0) as total_qty,
                    COUNT(DISTINCT CASE WHEN inbound_deliveries.purchase_order_number IS NOT NULL AND inbound_deliveries.purchase_order_number != '' THEN inbound_deliveries.purchase_order_number END) as linked_pos,
                    COUNT(DISTINCT CASE WHEN deliveries.inspection_result IS NOT NULL THEN inbound_deliveries.id END) as delivered_count
                ")
                ->first();

            $inboundMetrics = [
                'totalDeliveries' => (int) ($inboundRow->total_deliveries ?? 0),
                'totalQuantityDelivered' => (int) ($inboundRow->total_qty ?? 0),
                'linkedPosCount' => (int) ($inboundRow->linked_pos ?? 0),
                'deliveredCount' => (int) ($inboundRow->delivered_count ?? 0),
            ];

            // For linking a received product that is not in inventory yet (incomplete products included).
            $products = Product::where('team_id', $team->id)
                ->orderBy('name')
                ->get(['id', 'name', 'sku', 'cost_price', 'stock', 'unit', 'is_active']);

            $suppliers = Supplier::where('team_id', $team->id)
                ->where('status', 'Active')
                ->orderBy('name')
                ->get(['id', 'name']);

            // Only orders sent to the supplier can receive goods.
            $purchaseOrders = PurchaseOrder::where('team_id', $team->id)
                ->whereIn('status', PurchaseOrder::RECEIVABLE)
                ->with('supplier:id,name')
                ->latest('po_date')
                ->get(['id', 'po_number', 'item_sku', 'description', 'unit_of_measure', 'quantity', 'supplier_id', 'status']);
            $workflow = app(ProcurementWorkflow::class);
            $purchaseOrders->each(fn (PurchaseOrder $po) => $po->setAttribute('remaining', $workflow->summary($po)['remaining']));
        }

        return Inertia::render('Deliveries/Index', [
            'activeTab' => $activeTab,
            'isDeliveryStaff' => $isDeliveryStaff,
            'isAdmin' => $isAdmin,
            'outboundDeliveries' => $outboundDeliveries,
            'outboundMetrics' => $outboundMetrics,
            'statusOptions' => collect(DeliveryStatus::cases())->map(fn ($c) => ['value' => $c->value, 'label' => $c->label(), 'badgeClass' => $c->badgeClass()])->values(),
            'canPrepare' => $this->canPrepare($user),
            'deliveryStaffList' => $deliveryStaffList,
            'inboundDeliveries' => $inboundDeliveries,
            'inboundMetrics' => $inboundMetrics,
            'products' => $products,
            'suppliers' => $suppliers,
            'purchaseOrders' => $purchaseOrders,
            // "Record Inbound P.O. Delivery": orders with goods still owed, grouped by PO number.
            'receivableOrders' => fn () => $isDeliveryStaff ? [] : app(InboundReceiving::class)->receivableOrders($team->id),
            'productCategories' => fn () => $isDeliveryStaff ? [] : Product::where('team_id', $team->id)->whereNotNull('category')->distinct()->orderBy('category')->pluck('category'),
            'filters' => [
                'tab' => $activeTab,
                'search' => $search,
                'status' => $status,
                'staff_id' => $request->input('staff_id', 'All'),
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
            ],
        ]);
    }

    /** Delivery status filters on the page → status codes. */
    private const STATUS_GROUPS = [
        'to_prepare' => ['pending', 'ready_for_delivery'],
        'delivered' => ['delivered'],
    ];

    /** What each "Update status" choice does, the status it leads to, and what it asks for. */
    private const STATUS_CHOICES = [
        'prepare' => ['status' => 'pending', 'label' => 'Pending: start preparing the items', 'needs' => 'quantities'],
        'ready' => ['status' => 'ready_for_delivery', 'label' => 'Ready for Delivery', 'needs' => null],
        'dispatch' => ['status' => 'in_transit', 'label' => 'In Transit', 'needs' => null],
        'retry' => ['status' => 'in_transit', 'label' => 'In Transit: try again', 'needs' => null],
        'deliver' => ['status' => 'delivered', 'label' => 'Delivered (customer received it)', 'needs' => 'receipt'],
        'handover' => ['status' => 'delivered', 'label' => 'Delivered (customer picked it up)', 'needs' => 'receipt'],
        'attempt' => ['status' => 'ready_for_delivery', 'label' => 'Ready for Delivery: delivery attempt failed', 'needs' => 'attempt'],
        'reschedule' => ['status' => 'ready_for_delivery', 'label' => 'Ready for Delivery: new delivery date', 'needs' => 'schedule'],
        'return' => ['status' => 'pending', 'label' => 'Pending: the goods came back to the warehouse', 'needs' => 'reason'],
        'cancel_release' => ['status' => null, 'label' => 'Undo: cancel the release being prepared', 'needs' => 'reason'],
        'cancel_delivery' => ['status' => 'cancelled', 'label' => 'Cancelled: stop delivering what is left', 'needs' => 'reason'],
    ];

    /**
     * One "Update status" action for admins and the assigned delivery staff (warehouse staff for
     * preparing and pickups). The choice must be one this user is allowed to make now; each one
     * runs the same rules as its own button.
     */
    public function updateStatus(Request $request, mixed $current_team = null, mixed $fulfillment = null): RedirectResponse
    {
        $fulfillment = $this->authorizedFulfillment($request, $fulfillment, $current_team);
        $fulfillment->loadMissing(['sale', 'releases']);
        $user = $request->user();
        $validated = $request->validate([
            'action' => ['required', Rule::in(array_keys(self::STATUS_CHOICES))],
            'remarks' => ['nullable', 'string', 'max:1000'],
            'reason' => ['nullable', 'string', 'max:500'],
            'quantities' => ['nullable', 'array'],
            'quantities.*' => ['integer', 'min:0'],
            'delivery_schedule' => ['nullable', 'date'],
            'reset_attempts' => ['nullable', 'boolean'],
        ]);
        $action = $validated['action'];
        abort_unless(in_array($action, $this->allowedActions($fulfillment, $user), true), 403, 'You cannot make this status change.');

        return match ($action) {
            'prepare' => $this->prepareRelease($request, $current_team, $fulfillment),
            'ready' => $this->markReady($request, $current_team, $fulfillment->openRelease()),
            'dispatch', 'retry' => $this->startDelivery($request, $current_team, $fulfillment),
            'deliver', 'handover' => $this->markDelivered($request, $current_team, $fulfillment),
            'attempt' => $this->recordAttempt($request, $current_team, $fulfillment),
            'reschedule' => $this->reschedule($request, $current_team, $fulfillment),
            'return' => $this->returnToWarehouse($request, $current_team, $fulfillment),
            'cancel_release' => $this->cancelRelease($request, $current_team, $fulfillment->openRelease()),
            'cancel_delivery' => $this->runWorkflow(function () use ($fulfillment, $validated, $user) {
                $reason = trim((string) ($validated['reason'] ?? '')) ?: throw new WorkflowException('Give a reason for cancelling the delivery.', 'reason');
                app(SalesOrderService::class)->cancelRemaining($fulfillment->sale, null, $reason, $user);

                return "Delivery {$fulfillment->fulfillment_number}: the remaining items will not be delivered.";
            }),
        };
    }

    /** @return list<array{action: string, status: ?string, label: string, needs: ?string}> */
    private function statusChoices(OrderFulfillment $f, User $user): array
    {
        return collect($this->allowedActions($f, $user))
            ->filter(fn ($a) => isset(self::STATUS_CHOICES[$a]))
            ->map(fn ($a) => ['action' => $a] + self::STATUS_CHOICES[$a])
            ->values()->all();
    }

    /** Start a release: choose the items and quantities that go out next. */
    public function prepareRelease(Request $request, mixed $current_team = null, mixed $fulfillment = null): RedirectResponse
    {
        $fulfillment = $this->authorizedFulfillment($request, $fulfillment, $current_team);
        abort_unless($this->canPrepare($request->user()) || $fulfillment->handled_by_id === $request->user()->id, 403, 'You cannot prepare releases.');
        $validated = $request->validate([
            'quantities' => ['required', 'array'],
            'quantities.*' => ['integer', 'min:0'],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ]);

        return $this->runWorkflow(function () use ($fulfillment, $validated, $request) {
            $release = app(FulfillmentService::class)->prepareRelease($fulfillment, $validated['quantities'], $request->user(), $validated['remarks'] ?? null);

            return "Release {$release->release_number} is being prepared.";
        });
    }

    public function markReady(Request $request, mixed $current_team = null, mixed $release = null): RedirectResponse
    {
        $release = $this->authorizedRelease($request, $release, $current_team);
        abort_unless($this->canPrepare($request->user()) || $release->fulfillment->handled_by_id === $request->user()->id, 403);

        return $this->runWorkflow(function () use ($release, $request) {
            app(FulfillmentService::class)->markReady($release, $request->user());

            return "{$release->release_number} is ready".($release->type === 'pickup' ? ' for pickup.' : ' for dispatch.');
        });
    }

    public function cancelRelease(Request $request, mixed $current_team = null, mixed $release = null): RedirectResponse
    {
        $release = $this->authorizedRelease($request, $release, $current_team);
        abort_unless($this->canPrepare($request->user()), 403);
        $reason = $request->validate(['reason' => ['required', 'string', 'max:500']])['reason'];

        return $this->runWorkflow(function () use ($release, $reason, $request) {
            app(FulfillmentService::class)->cancelRelease($release, $request->user(), $reason);

            return "{$release->release_number} cancelled.";
        });
    }

    /** Out for delivery: dispatch the ready release (stock leaves now), or go again after a failure/reschedule. */
    public function startDelivery(Request $request, mixed $current_team = null, mixed $fulfillment = null): RedirectResponse
    {
        $fulfillment = $this->authorizedFulfillment($request, $fulfillment, $current_team);
        $this->assertDriver($request, $fulfillment);

        return $this->runWorkflow(function () use ($fulfillment, $request) {
            $service = app(FulfillmentService::class);
            $release = $fulfillment->openRelease() ?? throw new WorkflowException('Prepare a release and mark it ready first.');
            // Goods already on the truck (failed attempt or new date): go again. Otherwise dispatch.
            if ($release->status === DeliveryRelease::DISPATCHED) {
                $service->retryDispatch($fulfillment, $request->user());
            } else {
                $service->dispatch($release, $request->user());
            }

            return "Delivery {$fulfillment->fulfillment_number} is out for delivery.";
        });
    }

    public function recordAttempt(Request $request, mixed $current_team = null, mixed $fulfillment = null): RedirectResponse
    {
        $fulfillment = $this->authorizedFulfillment($request, $fulfillment, $current_team);
        $this->assertDriver($request, $fulfillment);

        $validated = $request->validate([
            'reason' => ['required', 'string', Rule::in(['Customer unavailable', 'Incorrect or incomplete address', 'Customer refused delivery', 'Customer unreachable', 'Other reason'])],
            'remarks' => ['required', 'string', 'min:3', 'max:1000'],
            'attempt_photo' => ['nullable', 'file', 'image', 'mimes:jpeg,jpg,png', 'max:5120'],
        ]);

        $photo = $request->file('attempt_photo')?->store('proofs/deliveries/attempts', 'local');

        return $this->runWorkflow(function () use ($fulfillment, $validated, $photo, $request) {
            try {
                $attempt = app(FulfillmentService::class)->recordFailedAttempt($fulfillment, $validated['reason'], $validated['remarks'], $photo, $request->user());
            } catch (\Throwable $e) {
                if ($photo) {
                    Storage::disk('local')->delete($photo);
                }
                throw $e;
            }

            return $attempt >= OrderFulfillment::MAX_ATTEMPTS
                ? 'Attempt 3/3 recorded. The limit is reached: an administrator has to reschedule or the goods have to be returned.'
                : "Attempt {$attempt}/".OrderFulfillment::MAX_ATTEMPTS.' recorded.';
        });
    }

    /**
     * Record what the customer received from the current release (all of it by default). For
     * deliveries a photo proof is required; for pickups the receiver's name is enough.
     */
    public function markDelivered(Request $request, mixed $current_team = null, mixed $fulfillment = null): RedirectResponse
    {
        $fulfillment = $this->authorizedFulfillment($request, $fulfillment, $current_team);
        $pickup = $fulfillment->isPickup();
        $pickup ? abort_unless($this->canPrepare($request->user()) || $fulfillment->handled_by_id === $request->user()->id, 403) : $this->assertDriver($request, $fulfillment);

        $validated = $request->validate([
            'proof_of_delivery' => [$pickup ? 'nullable' : 'required', 'file', 'image', 'mimes:jpeg,jpg,png', 'max:5120'],
            'recipient_name' => [$pickup ? 'required' : 'nullable', 'string', 'max:255'],
            'remarks' => ['nullable', 'string', 'max:1000'],
            'quantities' => ['nullable', 'array'],
            'quantities.*' => ['integer', 'min:0'],
        ], [
            'proof_of_delivery.required' => 'Proof of delivery photo is required to mark this order as Delivered.',
            'proof_of_delivery.image' => 'The proof of delivery must be a valid image file (JPG or PNG).',
            'proof_of_delivery.mimes' => 'Only JPEG, JPG, and PNG image formats are accepted.',
            'proof_of_delivery.max' => 'The proof of delivery image size cannot exceed 5 MB.',
            'recipient_name.required' => 'Enter the name of the person who picked up the order.',
        ]);

        $release = $fulfillment->openRelease();
        if (! $release) {
            return back()->withErrors(['error' => 'There is no release on its way for this order.']);
        }

        $proof = $request->file('proof_of_delivery')?->store('proofs/deliveries', 'local');

        return $this->runWorkflow(function () use ($release, $validated, $proof, $fulfillment, $request) {
            try {
                $status = app(FulfillmentService::class)->recordReceipt($release, $validated['quantities'] ?? [], ($validated['recipient_name'] ?? null) ?: ($fulfillment->recipient_name ?: 'Customer'), $proof, $validated['remarks'] ?? null, $request->user());
            } catch (\Throwable $e) {
                if ($proof) {
                    Storage::disk('local')->delete($proof);
                }
                throw $e;
            }

            return $status === DeliveryStatus::Delivered
                ? "Delivery {$fulfillment->fulfillment_number}: everything has been received."
                : "Delivery {$fulfillment->fulfillment_number}: partial delivery recorded. The remaining quantities stay outstanding.";
        });
    }

    /** The goods came back to the warehouse (stock is put back and held for the order). */
    public function returnToWarehouse(Request $request, mixed $current_team = null, mixed $fulfillment = null): RedirectResponse
    {
        $fulfillment = $this->authorizedFulfillment($request, $fulfillment, $current_team);
        $this->assertDriver($request, $fulfillment);
        $reason = $request->validate(['reason' => ['required', 'string', 'max:500']])['reason'];

        return $this->runWorkflow(function () use ($fulfillment, $reason, $request) {
            $release = $fulfillment->openRelease() ?? throw new WorkflowException('There is no release on its way for this order.');
            app(FulfillmentService::class)->returnRelease($release, $reason, $request->user());

            return "{$release->release_number} returned to the warehouse. The stock is back and held for the order.";
        });
    }

    /** Stream a release's proof of delivery. */
    public function releaseProof(Request $request, mixed $current_team = null, mixed $release = null): StreamedResponse
    {
        $release = $this->authorizedRelease($request, $release, $current_team);
        $user = $request->user();
        abort_unless($user->isAdmin() || $this->canPrepare($user) || $release->fulfillment->handled_by_id === $user->id || $user->hasModule('sales'), 403);
        abort_if(! $release->proof_path || ! Storage::disk('local')->exists($release->proof_path), 404);

        return Storage::disk('local')->response($release->proof_path, null, ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    /** Printable delivery receipt / release slip for one release. */
    public function releasePdf(Request $request, mixed $current_team = null, mixed $release = null)
    {
        $release = $this->authorizedRelease($request, $release, $current_team);

        return response(app(DocumentPdf::class)->deliveryReceipt($release), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$release->release_number.'.pdf"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /** Warehouse staff (and administrators) prepare releases and hand over pickups. */
    private function canPrepare(User $user): bool
    {
        return $user->isAdmin() || $user->hasAnyModule(['inventory', 'procurement']);
    }

    private function assertDriver(Request $request, OrderFulfillment $fulfillment): void
    {
        $user = $request->user();
        if (! $user->isAdmin()) {
            abort_if($fulfillment->handled_by_id !== $user->id, 403, 'You are not assigned to this delivery.');
        }
    }

    /** @return list<string> */
    private function allowedActions(OrderFulfillment $f, User $user): array
    {
        $status = $f->status;
        if (! $status) {
            return [];
        }
        $pickup = $f->isPickup();
        $driver = $user->isAdmin() || $f->handled_by_id === $user->id;
        $warehouse = $this->canPrepare($user) || $f->handled_by_id === $user->id;
        $open = $f->releases->first(fn ($r) => in_array($r->status, DeliveryRelease::OPEN, true));
        $orderOpen = in_array($f->sale?->status, [SalesOrderStatus::Confirmed, SalesOrderStatus::Processing], true);
        $actions = [];

        if ($warehouse && $orderOpen && ! $open && $status === DeliveryStatus::Pending) {
            $actions[] = 'prepare';
        }
        if ($warehouse && $open?->status === DeliveryRelease::PREPARING) {
            $actions[] = 'ready';
        }
        if ($this->canPrepare($user) && $open && in_array($open->status, [DeliveryRelease::PREPARING, DeliveryRelease::READY], true)) {
            $actions[] = 'cancel_release';
        }
        if (! $pickup && $driver && $open?->status === DeliveryRelease::READY && $orderOpen && ! $f->sale?->hold_reason) {
            $actions[] = 'dispatch';
        }
        if ($pickup && $warehouse && $open?->status === DeliveryRelease::READY) {
            $actions[] = 'handover';
        }
        if (! $pickup && $driver && $status === DeliveryStatus::InTransit) {
            $actions[] = 'deliver';
            if ($f->attempt_count < OrderFulfillment::MAX_ATTEMPTS) {
                $actions[] = 'attempt';
            }
        }
        // Goods still on the truck while Ready for Delivery: after a failed attempt or a reschedule.
        $onTruck = $f->goodsOnTruck($open);
        if (! $pickup && $driver && $status === DeliveryStatus::ReadyForDelivery && $onTruck && ! $f->needsReschedule($open)) {
            $actions[] = 'retry';
        }
        if (! $pickup && $driver && $onTruck && $status !== DeliveryStatus::InTransit) {
            $actions[] = 'return';
        }
        if ($user->isAdmin() && $f->awaitingRedelivery($open)) {
            $actions[] = 'reschedule';
        }
        $owed = $f->sale?->items?->contains(fn ($l) => $l->releasable_quantity > 0) ?? false;
        if ($user->isAdmin() && $orderOpen && ! $open && $owed && $status === DeliveryStatus::Pending) {
            $actions[] = 'cancel_delivery';
        }
        if ($user->isAdmin() && ! $status->isFinal()) {
            $actions[] = 'assign';
        }

        return $actions;
    }

    private function authorizedFulfillment(Request $request, mixed $fulfillment, mixed $current_team): OrderFulfillment
    {
        $fulfillment = $this->resolveFulfillment($fulfillment, $current_team, $request);
        $team = $this->getTeam($request, $current_team);
        abort_if(! $team || $fulfillment->team_id !== $team->id, 403, 'Unauthorized delivery access.');

        return $fulfillment;
    }

    private function authorizedRelease(Request $request, mixed $release, mixed $current_team): DeliveryRelease
    {
        $team = $this->getTeam($request, $current_team);
        $id = $release instanceof DeliveryRelease ? $release->id : (int) ($release ?? $request->route('release'));
        $release = DeliveryRelease::with('fulfillment')->where('team_id', $team?->id)->findOrFail($id);

        return $release;
    }

    /**
     * Assign or reassign delivery staff (Administrator / Operations Staff only).
     */
    public function assignStaff(Request $request, mixed $current_team = null, mixed $fulfillment = null): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->isAdmin(), 403, 'Only administrators or authorized operations staff can assign deliveries.');

        $fulfillment = $this->resolveFulfillment($fulfillment, $current_team, $request);
        $team = $this->getTeam($request, $current_team);
        abort_if(! $team || $fulfillment->team_id !== $team->id, 403, 'Unauthorized delivery access.');

        $validated = $request->validate([
            'staff_id' => ['required', 'integer', Rule::exists('users', 'id')],
        ]);

        $assignedUser = User::findOrFail($validated['staff_id']);
        $fulfillment->handled_by_id = $assignedUser->id;
        $fulfillment->save();

        DeliveryAttemptLog::create([
            'team_id' => $team->id,
            'order_fulfillment_id' => $fulfillment->id,
            'previous_status' => $fulfillment->status?->value ?? 'none',
            'new_status' => $fulfillment->status?->value ?? 'none',
            'attempt_number' => 0,
            'staff_id' => $user->id,
            'remarks' => "Administrator assigned delivery to {$assignedUser->name}.",
            'attempt_date' => now(),
        ]);

        return back()->with('success', "Delivery {$fulfillment->fulfillment_number} assigned to {$assignedUser->name}.");
    }

    /**
     * Reschedule delivery / reset attempts after 3-attempt limit (Admin/Ops only).
     */
    public function reschedule(Request $request, mixed $current_team = null, mixed $fulfillment = null): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->isAdmin(), 403, 'Only administrators can authorize rescheduling.');

        $fulfillment = $this->resolveFulfillment($fulfillment, $current_team, $request);
        $team = $this->getTeam($request, $current_team);
        abort_if(! $team || $fulfillment->team_id !== $team->id, 403, 'Unauthorized delivery access.');

        $validated = $request->validate([
            'delivery_schedule' => ['required', 'date'],
            'staff_id' => ['nullable', 'integer', Rule::exists('users', 'id')],
            'notes' => ['nullable', 'string', 'max:1000'],
            'reset_attempts' => ['nullable', 'boolean'],
        ]);

        return $this->runWorkflow(function () use ($fulfillment, $user, $validated) {
            app(FulfillmentService::class)->reschedule($fulfillment, $validated['delivery_schedule'], (bool) ($validated['reset_attempts'] ?? false), $validated['notes'] ?? null, $validated['staff_id'] ?? null, $user);

            return "Delivery {$fulfillment->fulfillment_number} rescheduled.";
        });
    }

    /**
     * Stream fulfillment proof of delivery image securely.
     */
    public function fulfillmentProof(Request $request, mixed $current_team = null, mixed $fulfillment = null): StreamedResponse
    {
        $fulfillment = $this->resolveFulfillment($fulfillment, $current_team, $request);
        $team = $this->getTeam($request, $current_team);
        abort_if(! $team || $fulfillment->team_id !== $team->id, 403, 'Unauthorized delivery access.');

        $user = $request->user();
        if (! $user->isAdmin()) {
            abort_if($fulfillment->handled_by_id !== $user->id, 403, 'You are not authorized to view this delivery proof.');
        }

        abort_if(! $fulfillment->proof_of_delivery, 404, 'No proof of delivery uploaded.');

        if (! Storage::disk('local')->exists($fulfillment->proof_of_delivery)) {
            abort(404, 'Proof of delivery file missing.');
        }

        return Storage::disk('local')->response($fulfillment->proof_of_delivery, null, [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Stream attempt photo proof securely.
     */
    public function attemptProof(Request $request, mixed $current_team = null, mixed $attemptLog = null): StreamedResponse
    {
        $team = $this->getTeam($request, $current_team);
        abort_if(! $team, 403);

        $logId = is_numeric($attemptLog) ? (int) $attemptLog : ($attemptLog?->id ?? (int) $request->route('attemptLog'));
        $log = DeliveryAttemptLog::where('team_id', $team->id)->where('id', $logId)->firstOrFail();

        $user = $request->user();
        if (! $user->isAdmin()) {
            abort_if($log->staff_id !== $user->id && $log->orderFulfillment?->handled_by_id !== $user->id, 403);
        }

        abort_if(! $log->proof_image || ! Storage::disk('local')->exists($log->proof_image), 404, 'Attempt photo missing.');

        return Storage::disk('local')->response($log->proof_image, null, [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    // -------------------------------------------------------------
    // INBOUND SUPPLIER DELIVERY METHODS (Preserved for compatibility)
    // -------------------------------------------------------------

    /**
     * Delete an inbound delivery record.
     */
    public function destroy(Request $request, mixed $current_team = null, mixed $delivery = null): RedirectResponse
    {
        abort_unless($request->user()?->isAdmin(), 403, 'Only administrators can delete records.');
        if ($request->has('confirmation')) {
            $request->validate(['confirmation' => ['required', 'string', Rule::in(['YES'])]]);
        }
        $delivery = $this->resolveDelivery($delivery, $current_team, $request);
        $team = $this->getTeam($request, $current_team);
        abort_if(! $team || $delivery->team_id !== $team->id, 403);

        if ($delivery->posted_at !== null) {
            return back()->withErrors(['delivery' => 'Deliveries whose accepted units have already been posted to inventory cannot be deleted.']);
        }

        $delivery->delete();

        return back()->with('success', 'Delivery record deleted successfully.');
    }

    /**
     * Archive (soft delete) a customer delivery record. Admin only: the route also has the
     * `admin_delete` middleware. The record goes to the Recycle Bin, where an Admin can restore it.
     */
    public function destroyFulfillment(Request $request, mixed $current_team = null, mixed $fulfillment = null): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user?->isAdmin(), 403, 'Only administrators can delete records.');
        $request->validate(['confirmation' => ['sometimes', 'required', 'string', Rule::in(['YES'])]]);

        $fulfillment = $this->authorizedFulfillment($request, $fulfillment, $current_team);
        $fulfillment->loadMissing('releases');

        if ($reason = $this->fulfillmentDeleteBlockedReason($fulfillment)) {
            return back()->with('error', "Delivery {$fulfillment->fulfillment_number} cannot be deleted: {$reason}");
        }

        $fulfillment->delete();

        AuditLogger::audit(
            'delivery.customer_delivery_deleted',
            "Moved customer delivery {$fulfillment->fulfillment_number} to the Recycle Bin.",
            $user,
            null,
            [
                'order_fulfillment_id' => $fulfillment->id,
                'fulfillment_number' => $fulfillment->fulfillment_number,
                'sale_id' => $fulfillment->sale_id,
                'status' => $fulfillment->status?->value,
                'deleted_at' => $fulfillment->deleted_at?->toIso8601String(),
            ],
            'success',
            $request
        );

        return back()->with('success', "Delivery {$fulfillment->fulfillment_number} was moved to the Recycle Bin.");
    }

    /**
     * Why a customer delivery must not be deleted yet, or null if it can be.
     *
     * Only finished deliveries (delivered or cancelled, with no trip being prepared or on the road)
     * can be archived, so an order is never left without its delivery record mid-workflow.
     */
    public static function fulfillmentDeleteBlockedReason(OrderFulfillment $fulfillment): ?string
    {
        if (! in_array($fulfillment->status, [DeliveryStatus::Delivered, DeliveryStatus::Cancelled], true)) {
            return 'only delivered or cancelled deliveries can be deleted.';
        }

        if ($fulfillment->releases->contains(fn ($r) => in_array($r->status, DeliveryRelease::OPEN, true))) {
            return 'a release is still being prepared or is on the road.';
        }

        return null;
    }

    /**
     * Process CSV Import for Deliveries.
     */
    public function importCsv(Request $request): JsonResponse|RedirectResponse
    {
        $team = $this->getTeam($request);
        abort_if(! $team, 404, 'Team not found.');

        $request->validate([
            'file' => ['required', 'file', 'extensions:csv,txt'],
            'execute' => ['nullable', 'boolean'],
        ]);

        $file = $request->file('file');
        $filePath = $file->getRealPath() ?: $file->getPathname();
        $content = file_get_contents($filePath);
        $rawLines = preg_split('/\r\n|\n|\r/', trim($content));

        if (empty($rawLines) || count($rawLines) < 2) {
            return response()->json([
                'success' => false,
                'message' => 'The uploaded CSV file is empty or missing headers.',
                'valid_rows' => [],
                'invalid_rows' => [],
            ], 422);
        }

        $firstLine = $rawLines[0];
        $delimiter = ',';
        if (substr_count($firstLine, "\t") > substr_count($firstLine, ',')) {
            $delimiter = "\t";
        } elseif (substr_count($firstLine, ';') > substr_count($firstLine, ',')) {
            $delimiter = ';';
        }

        $lines = [];
        foreach ($rawLines as $rawLine) {
            if (trim($rawLine) === '') {
                continue;
            }
            $row = str_getcsv($rawLine, $delimiter);
            if (! empty(array_filter($row, fn ($v) => $v !== null && trim((string) $v) !== ''))) {
                $lines[] = $row;
            }
        }

        if (count($lines) < 2) {
            return response()->json([
                'success' => false,
                'message' => 'The uploaded CSV file contains no data rows.',
                'valid_rows' => [],
                'invalid_rows' => [],
            ], 422);
        }

        $rawHeaders = $lines[0];
        $headers = array_map(function ($h) {
            $cleaned = preg_replace('/^\xEF\xBB\xBF/', '', (string) $h);

            return preg_replace('/[^a-z0-9]/', '', strtolower($cleaned));
        }, $rawHeaders);

        $dateIdx = $this->findHeaderIndex($headers, ['date', 'deliverydate']);
        $delNumIdx = $this->findHeaderIndex($headers, ['deliverynumber', 'deliveryno', 'delno', 'delivery']);
        $poNumIdx = $this->findHeaderIndex($headers, ['purchaseordernumber', 'purchaseorderno', 'pono', 'ponumber', 'purchaseorder', 'po']);
        $qtyIdx = $this->findHeaderIndex($headers, ['quantity', 'qty', 'count']);
        $unitIdx = $this->findHeaderIndex($headers, ['unit', 'um', 'uom', 'unitofmeasure', 'measure']);
        $descIdx = $this->findHeaderIndex($headers, ['description', 'desc', 'itemdescription', 'item', 'product']);

        if ($dateIdx === -1 || $qtyIdx === -1 || $descIdx === -1) {
            return response()->json([
                'success' => false,
                'message' => 'Required column headers missing. CSV must include headers: Date, Delivery Number, Purchase Order Number, Quantity, Unit, Description.',
                'valid_rows' => [],
                'invalid_rows' => [],
            ], 422);
        }

        $validRows = [];
        $invalidRows = [];

        for ($i = 1; $i < count($lines); $i++) {
            $row = $lines[$i];
            if (empty(array_filter($row))) {
                continue;
            }

            $rowNumber = $i + 1;
            $errors = [];

            $rawDate = isset($row[$dateIdx]) ? trim($row[$dateIdx]) : '';
            $parsedDate = null;
            $displayDate = $rawDate;

            // Like the other CSV templates, the DATE column is MONTH-DAY-YEAR; that order settles
            // ambiguous dates, and invalid dates (13-40-2026) are rejected instead of being saved.
            $date = DateNormalizer::parse($rawDate, DateNormalizer::MDY);
            if ($date['status'] === DateNormalizer::EMPTY) {
                $errors[] = 'Date is required.';
            } elseif ($date['value'] === null) {
                $errors[] = $date['problem'];
            } else {
                $parsedDate = $date['value'];
            }

            $delNumber = $delNumIdx !== -1 && isset($row[$delNumIdx]) ? trim($row[$delNumIdx]) : '';
            $poNumber = $poNumIdx !== -1 && isset($row[$poNumIdx]) ? trim($row[$poNumIdx]) : '';

            $rawQty = isset($row[$qtyIdx]) ? trim(str_replace(',', '', $row[$qtyIdx])) : '';
            $qty = 0;
            if ($rawQty === '' || ! is_numeric($rawQty) || (int) $rawQty <= 0) {
                $errors[] = 'Quantity must be a positive integer.';
            } else {
                $qty = (int) $rawQty;
            }

            $unit = $unitIdx !== -1 && isset($row[$unitIdx]) && trim($row[$unitIdx]) !== '' ? trim($row[$unitIdx]) : 'pcs';
            $desc = isset($row[$descIdx]) ? trim($row[$descIdx]) : '';
            if ($desc === '') {
                $errors[] = 'Description is required.';
            }

            if (! empty($errors)) {
                $invalidRows[] = [
                    'row' => $rowNumber,
                    'errors' => $errors,
                    'data' => [
                        'date' => $displayDate,
                        'delivery_number' => $delNumber,
                        'po_number' => $poNumber,
                        'quantity' => $rawQty,
                        'unit' => $unit,
                        'description' => $desc,
                    ],
                ];
            } else {
                $validRows[] = [
                    'row' => $rowNumber,
                    'delivery_date' => $parsedDate,
                    'original_date' => $rawDate,
                    'delivery_number' => $delNumber,
                    'purchase_order_number' => $poNumber,
                    'quantity' => $qty,
                    'unit' => $unit,
                    'description' => $desc,
                ];
            }
        }

        if ($request->boolean('execute')) {
            if (empty($validRows)) {
                return response()->json([
                    'success' => false,
                    'message' => 'No valid rows found to import.',
                ], 422);
            }

            DB::transaction(function () use ($validRows, $team, $request) {
                foreach ($validRows as $item) {
                    $delNum = ! empty($item['delivery_number'])
                        ? $item['delivery_number']
                        : Delivery::generateDeliveryNumber($team->id, $item['delivery_date']);

                    $poId = null;
                    $supplierId = null;
                    if (! empty($item['purchase_order_number'])) {
                        $foundPo = PurchaseOrder::where('team_id', $team->id)
                            ->where('po_number', $item['purchase_order_number'])
                            ->first();
                        if ($foundPo) {
                            $poId = $foundPo->id;
                            $supplierId = $foundPo->supplier_id;
                        }
                    }

                    Delivery::create([
                        'team_id' => $team->id,
                        'delivery_date' => $item['delivery_date'],
                        'delivery_number' => $delNum,
                        'purchase_order_number' => $item['purchase_order_number'] ?: null,
                        'purchase_order_id' => $poId,
                        'quantity' => $item['quantity'],
                        'unit' => $item['unit'],
                        'description' => $item['description'],
                        'supplier_id' => $supplierId,
                        'received_by_id' => $request->user()?->id,
                        'status' => 'Delivered',
                        'notes' => 'Imported via CSV',
                    ]);
                }
            });

            if ($request->wantsJson()) {
                return response()->json([
                    'success' => true,
                    'message' => 'Successfully imported '.count($validRows).' delivery record(s).',
                    'count' => count($validRows),
                    'invalid_count' => count($invalidRows),
                ]);
            }

            return back()->with('success', 'Successfully imported '.count($validRows).' delivery record(s).');
        }

        return response()->json([
            'success' => true,
            'summary' => [
                'total_rows' => count($lines) - 1,
                'valid_count' => count($validRows),
                'invalid_count' => count($invalidRows),
            ],
            'valid_rows' => $validRows,
            'invalid_rows' => $invalidRows,
        ]);
    }

    /**
     * Find header index matching candidate strings.
     */
    protected function findHeaderIndex(array $headers, array $candidates): int
    {
        foreach ($headers as $index => $header) {
            foreach ($candidates as $candidate) {
                if ($header === $candidate) {
                    return $index;
                }
            }
        }

        return -1;
    }

    /**
     * Helper to retrieve team.
     */
    protected function getTeam(Request $request, mixed $teamParam = null): ?Team
    {
        if ($teamParam instanceof Team) {
            return $teamParam;
        }

        if (is_string($teamParam) && ! empty($teamParam)) {
            $found = Team::where('slug', $teamParam)->first();
            if ($found) {
                return $found;
            }
        }

        $team = $request->route('current_team') ?? $request->route('team') ?? $teamParam;
        if (is_string($team)) {
            $found = Team::where('slug', $team)->first();
            if ($found) {
                return $found;
            }
        }
        if ($team instanceof Team) {
            return $team;
        }

        return $request->user()?->currentTeam;
    }

    /**
     * Resolve delivery from route parameter.
     */
    protected function resolveDelivery(mixed $delivery, mixed $fallback = null, ?Request $request = null): Delivery
    {
        if ($delivery instanceof Delivery) {
            return $delivery;
        }

        $candidates = array_filter([
            $delivery,
            $fallback,
            $request?->route('delivery'),
        ], fn ($val) => $val !== null && $val !== '');

        foreach ($candidates as $cand) {
            if ($cand instanceof Delivery) {
                return $cand;
            }
            if (is_numeric($cand)) {
                $found = Delivery::find((int) $cand);
                if ($found) {
                    return $found;
                }
            }
            if (is_string($cand) && ! empty($cand)) {
                $found = Delivery::where('delivery_number', $cand)->first();
                if ($found) {
                    return $found;
                }
            }
        }

        abort(404, 'Delivery record not found.');
    }

    /**
     * Resolve order fulfillment from route parameters.
     */
    protected function resolveFulfillment(mixed $fulfillment, mixed $fallback = null, ?Request $request = null): OrderFulfillment
    {
        if ($fulfillment instanceof OrderFulfillment) {
            return $fulfillment;
        }

        $candidates = array_filter([
            $fulfillment,
            $fallback,
            $request?->route('fulfillment'),
        ], fn ($val) => $val !== null && $val !== '');

        foreach ($candidates as $cand) {
            if ($cand instanceof OrderFulfillment) {
                return $cand;
            }
            if (is_numeric($cand)) {
                $found = OrderFulfillment::find((int) $cand);
                if ($found) {
                    return $found;
                }
            }
            if (is_string($cand) && ! empty($cand)) {
                $found = OrderFulfillment::where('fulfillment_number', $cand)->first();
                if ($found) {
                    return $found;
                }
            }
        }

        abort(404, 'Order fulfillment record not found.');
    }
}
