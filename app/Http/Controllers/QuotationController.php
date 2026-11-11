<?php

namespace App\Http\Controllers;

use App\Enums\EmailStatus;
use App\Enums\QuotationStatus;
use App\Exceptions\QuotationNumberException;
use App\Exceptions\WorkflowException;
use App\Models\Client;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\Supplier;
use App\Models\Team;
use App\Models\User;
use App\Services\Documents\DocumentImage;
use App\Services\Documents\DocumentPdf;
use App\Services\MaterialList\ExtractionException;
use App\Services\MaterialList\FileReader;
use App\Services\MaterialList\HeaderMapper;
use App\Services\MaterialList\MaterialParser;
use App\Services\MaterialList\ProductMatcher;
use App\Services\Procurement\SupplierCosts;
use App\Services\Quotations\QuotationStock;
use App\Services\Quotations\QuotationWorkflow;
use App\Services\Sales\SalesOrderService;
use App\Support\DateNormalizer;
use App\Support\QuotationTerms;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class QuotationController extends Controller
{
    /**
     * Display a listing of quotations.
     */
    public function index(Request $request): Response
    {
        $team = $this->getTeam($request);
        abort_if(! $team, 404, 'Team not found.');

        // Approved or sent quotations past their validity date expire (cached for 60s to prevent redundant DB hits)
        Cache::remember("team:{$team->id}:quotation_expire_check", 60, function () use ($team) {
            return app(QuotationWorkflow::class)->expireDue($team->id);
        });

        $query = Quotation::query()
            ->with(['items.product', 'items.supplier:id,name', 'client', 'preparedBy:id,name', 'approvedBy:id,name', 'sentBy:id,name', 'decisionBy:id,name', 'sale:id,order_number,status,payment_status', 'sale.fulfillment', 'files', 'purchaseRequests.purchaseOrder'])
            ->where('team_id', $team->id);

        // Search query
        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('quotation_number', 'like', "%{$search}%")
                    ->orWhere('client_name', 'like', "%{$search}%")
                    ->orWhere('contact_person', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        // Status filter
        if ($status = $request->input('status')) {
            if ($status !== 'All') {
                $query->where('status', $status);
            }
        }

        // Date range filter
        if ($dateFrom = $request->input('date_from')) {
            $query->whereDate('quotation_date', '>=', $dateFrom);
        }
        if ($dateTo = $request->input('date_to')) {
            $query->whereDate('quotation_date', '<=', $dateTo);
        }

        $quotations = $query->latest('quotation_date')
            ->latest('id')
            ->paginate(10)
            ->withQueryString();

        // Summary metrics using SQL aggregates instead of loading all models into PHP memory
        $baseMetricsQuery = fn () => Quotation::where('team_id', $team->id);
        $metrics = [
            'totalQuotations' => $baseMetricsQuery()->count(),
            // Drafts staff are preparing, and drafts waiting for the administrator.
            'pendingCount' => $baseMetricsQuery()->where('status', QuotationStatus::Draft->value)->whereNull('submitted_for_approval_at')->count(),
            'forApprovalCount' => $baseMetricsQuery()->where('status', QuotationStatus::Draft->value)->whereNotNull('submitted_for_approval_at')->whereNull('approved_at')->count(),
            'approvedCount' => $baseMetricsQuery()->where('status', QuotationStatus::Sent->value)->count(),
            'acceptedCount' => $baseMetricsQuery()->where('status', QuotationStatus::Accepted->value)->count(),
            'totalQuotedValue' => (float) $baseMetricsQuery()->whereIn('status', [
                QuotationStatus::Sent->value,
                QuotationStatus::Accepted->value,
            ])->sum('grand_total'),
        ];

        // The form's lookup lists below are closures: a search, filter or page change reloads only the
        // list ('quotations', 'filters', 'metrics'), so these are not rebuilt and resent each time.

        // Inventory products for selection: available = stock not reserved for confirmed orders.
        // Supplier cost and supplier are internal and only shown on this staff page.
        $productList = null;
        $products = function () use ($team, &$productList) {
            if ($productList !== null) {
                return $productList;
            }
            $rawProducts = Product::where('team_id', $team->id)
                ->with('supplier:id,name')
                ->orderBy('name')
                ->get(['id', 'sku', 'name', 'category', 'price', 'cost_price', 'supplier_id', 'stock', 'status', 'reorder_level', 'min_stock']);

            $reservedMap = Product::batchReservedQuantities($rawProducts->pluck('id')->all());

            return $productList = $rawProducts->map(function (Product $p) use ($reservedMap) {
                $reserved = $reservedMap[$p->id] ?? 0;
                $p->setAttribute('precomputed_reserved_quantity', $reserved);
                $available = max(0, (int) $p->stock - $reserved);

                return [
                    'id' => $p->id, 'sku' => $p->sku, 'name' => $p->name, 'category' => $p->category, 'price' => $p->price,
                    'cost_price' => $p->cost_price, 'supplier_id' => $p->supplier_id, 'supplier_name' => $p->supplier?->name,
                    'stock' => $available, 'on_hand' => (int) $p->stock, 'status' => $p->status,
                    'stock_status' => $p->stockStatusFor(1, $available),
                ];
            });
        };

        // Existing client records for auto-complete/quick pick
        $clients = fn () => Client::where('team_id', $team->id)
            ->orderBy('company_name')
            ->get(['id', 'company_name', 'contact_person', 'email', 'phone', 'address']);

        // Default next quotation number (shown only; the real one is reserved when saving)
        $nextNumber = fn () => Quotation::peekNumber($team->id, $products()->first()['sku'] ?? null);

        // Per-line stock condition for the requested quantity (batch resolved without N+1 queries).
        $itemProductIds = $quotations->getCollection()->flatMap(fn ($q) => $q->items->pluck('product_id'))->filter()->unique()->all();
        $itemReservedMap = Product::batchReservedQuantities($itemProductIds);

        $quotations->getCollection()->each(function (Quotation $q) use ($itemReservedMap) {
            $q->items->each(function ($item) use ($itemReservedMap) {
                if ($item->product) {
                    $reserved = $itemReservedMap[$item->product->id] ?? 0;
                    $item->product->setAttribute('precomputed_reserved_quantity', $reserved);
                    $available = max(0, (int) $item->product->stock - $reserved);
                    $item->setAttribute('stock_status', $item->product->stockStatusFor((int) $item->quantity, $available));
                } else {
                    $item->setAttribute('stock_status', 'Not in catalog');
                }
            });
            $this->attachStockFlow($q);
            $q->setAttribute('allowed_actions', $this->allowedActions($q, request()->user()));
        });

        return Inertia::render('Quotations/AdminIndex', [
            'quotations' => $quotations,
            'quotationValidityDays' => (int) config('company.quotation_validity_days'),
            'quotationTerms' => QuotationTerms::lines(),
            'filters' => $request->only(['search', 'status', 'date_from', 'date_to']),
            'metrics' => $metrics,
            'products' => $products,
            'clients' => $clients,
            'nextNumber' => $nextNumber,
            'statusOptions' => collect(QuotationStatus::cases())->map(fn ($s) => [
                'value' => $s->value,
                'label' => $s->label(),
                'badgeClass' => $s->badgeClass(),
            ]),
            // How the form saves a quotation: a Draft, or a Draft submitted for approval.
            'formStatuses' => [
                ['value' => QuotationStatus::Draft->value, 'label' => 'Draft'],
                ['value' => self::SUBMIT_FOR_APPROVAL, 'label' => 'Draft – submit for approval'],
            ],
            'decisionSources' => QuotationWorkflow::DECISION_SOURCES,
            'canApprove' => (bool) $request->user()?->isAdmin(),
            // Registered suppliers: the line "Select Supplier" dropdown (all) and the supplier quotation import (active).
            'suppliers' => fn () => Supplier::where('team_id', $team->id)->orderBy('name')->get(['id', 'name', 'status']),
            // Latest purchase cost per product and supplier, so choosing a supplier fills the Unit Cost.
            'supplierCosts' => fn () => app(SupplierCosts::class)->latest($team->id)->values(),
        ]);
    }

    /**
     * Draft quotations with short products: each line's purchase for this quotation
     * (purchase: {number, status}), the products it is waiting for (waiting_for_stock), and
     * whether stock can still be requested (can_request_stock).
     */
    private function attachStockFlow(Quotation $q): void
    {
        $q->setAttribute('waiting_for_stock', []);
        $q->setAttribute('can_request_stock', false);
        if ($q->status !== QuotationStatus::Draft) {
            return;
        }

        $stock = app(QuotationStock::class);
        $purchases = $stock->purchases($q);
        $q->items->each(function ($item) use ($purchases) {
            $pr = $item->product_id ? $purchases->get($item->product_id) : null;
            $item->setAttribute('purchase', $pr ? [
                'number' => $pr->purchaseOrder?->po_number ?? $pr->request_number,
                'status' => $pr->purchaseOrder?->status ?? $pr->status,
            ] : null);
        });

        $shortages = $stock->shortages($q);
        $waiting = $shortages->filter(fn ($s) => ($pr = $purchases->get($s['product_id'])) && $stock->inProgress($pr));
        $q->setAttribute('waiting_for_stock', $waiting->pluck('name')->values()->all());
        $q->setAttribute('can_request_stock', $shortages->count() > $waiting->count());
        $q->unsetRelation('purchaseRequests');
    }

    /**
     * What the current user can do with a quotation next (buttons on the page).
     *
     * @return list<string>
     */
    private function allowedActions(Quotation $quotation, ?User $user): array
    {
        $status = $quotation->status;
        $actions = [];
        if ($status->isEditable()) {
            $actions[] = 'edit';
        }
        if ($quotation->can_request_stock) {
            $actions[] = 'request_stock';
        }
        if ($status === QuotationStatus::Draft && ! $quotation->submitted_for_approval_at && ! $quotation->waiting_for_stock) {
            $actions[] = 'status:'.self::SUBMIT_FOR_APPROVAL;
        }
        foreach (QuotationStatus::manual() as $manual) {
            if ($status->canMoveTo($manual)) {
                $actions[] = 'status:'.$manual->value;
            }
        }
        if ($status === QuotationStatus::Draft && $quotation->submitted_for_approval_at && ! $quotation->approved_at && $user?->isAdmin()) {
            $actions[] = 'approve';
        }
        // Approved and not emailed yet: "Send Quotation". Already Sent: an explicit "Resend".
        if ($status === QuotationStatus::Draft && $quotation->approved_at) {
            $actions[] = 'send';
        }
        if ($status === QuotationStatus::Sent) {
            $actions[] = 'resend';
        }
        if ($status === QuotationStatus::Sent) {
            $actions[] = 'decision';
        }
        if ($status->canConvertToSale() && ! $quotation->converted_to_sale_id) {
            $actions[] = 'convert';
        }

        return $actions;
    }

    /**
     * Store a newly created quotation.
     */
    public function store(Request $request): RedirectResponse
    {
        $team = $this->getTeam($request);
        abort_if(! $team, 404, 'Team not found.');

        $validated = $request->validate([
            'client_name' => ['required', 'string', 'max:255'],
            'contact_person' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:500'],
            // Set by the system: the quotation date is today, Valid Until is set when it is approved.
            'quotation_date' => ['nullable', 'date'],
            'valid_until' => ['nullable', 'date'],
            'status' => ['required', Rule::in(self::FORM_STATUSES)],
            'notes' => ['nullable', 'string', 'max:2000'],
            'terms_and_conditions' => ['nullable', 'string', 'max:2000'],
            'tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'discount' => ['nullable', 'numeric', 'min:0'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['nullable', 'integer'],
            'items.*.sku' => ['required', 'string', 'max:100'],
            'items.*.product_name' => ['required', 'string', 'max:255'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
            'items.*.unit_cost' => ['nullable', 'numeric', 'min:0'],
            'items.*.supplier_id' => ['nullable', 'integer', Rule::exists('suppliers', 'id')->where('team_id', $team->id)],
            'items.*.discount' => ['nullable', 'numeric', 'min:0'],
        ]);

        try {
            $quotation = $this->createStaffQuotation($request, $team, $validated);
        } catch (QuotationNumberException|QueryException $e) {
            return back()->withErrors(['quotation_number' => $this->savingFailed($e, $team, 'create')]);
        }
        $this->submitIfChosen($quotation, $validated['status'], $request->user());

        return back()->with('success', 'Quotation created successfully.');
    }

    /** The form's "Draft – submit for approval" choice, through the same rules as the action. */
    private function submitIfChosen(Quotation $quotation, string $choice, User $user): void
    {
        if ($choice !== self::SUBMIT_FOR_APPROVAL) {
            return;
        }
        try {
            app(QuotationWorkflow::class)->submitForApproval($quotation, $user);
        } catch (WorkflowException $e) {
            session()->flash('warning', "Saved as Draft, not submitted for approval: {$e->getMessage()}");
        }
    }

    /** Validated staff form → Draft quotation and its items, in one transaction (retried on deadlock). */
    private function createStaffQuotation(Request $request, Team $team, array $validated): Quotation
    {
        return DB::transaction(function () use ($team, $request, $validated) {
            // Find or create client
            $client = Client::firstOrCreate(
                [
                    'team_id' => $team->id,
                    'company_name' => $validated['client_name'],
                ],
                [
                    'contact_person' => $validated['contact_person'] ?? null,
                    'email' => $validated['email'] ?? null,
                    'phone' => $validated['phone'] ?? null,
                    'address' => $validated['address'] ?? null,
                ]
            );

            // Compute financial totals accurately
            [$itemsData, $subtotal] = $this->buildItems($validated['items'], $team);

            $overallDiscount = (float) ($validated['discount'] ?? 0);
            $taxableAmount = max(0, $subtotal - $overallDiscount);
            $taxRate = isset($validated['tax_rate']) ? (float) $validated['tax_rate'] : 0.0;
            $taxAmount = round($taxableAmount * ($taxRate / 100), 2);
            $grandTotal = $taxableAmount + $taxAmount;

            $firstSku = $validated['items'][0]['sku'] ?? null;

            $quotation = Quotation::createWithNumber([
                'team_id' => $team->id,
                'client_id' => $client->id,
                'client_name' => $validated['client_name'],
                'contact_person' => $validated['contact_person'] ?? null,
                'email' => $validated['email'] ?? null,
                'phone' => $validated['phone'] ?? null,
                'address' => $validated['address'] ?? null,
                // Set by the system: today's date; Valid Until is set when the quotation is approved.
                'quotation_date' => now()->toDateString(),
                'valid_until' => null,
                'subtotal' => $subtotal,
                'discount' => $overallDiscount,
                'tax_rate' => $taxRate,
                'tax_amount' => $taxAmount,
                'grand_total' => $grandTotal,
                'status' => QuotationStatus::Draft,
                'notes' => $validated['notes'] ?? null,
                'terms_and_conditions' => QuotationTerms::text(),
                'prepared_by_id' => $request->user()->id,
            ], $firstSku, now()->toDateString());

            foreach ($itemsData as $data) {
                $quotation->items()->create($data);
            }

            return $quotation;
        }, 3);
    }

    /**
     * Log why a quotation could not be saved and return a message that is safe to show staff
     * (no SQL). The transaction has rolled back, so nothing was half-saved.
     */
    private function savingFailed(Throwable $e, Team $team, string $action): string
    {
        Log::error("Quotation {$action} failed for team {$team->id}: ".$e->getMessage(), ['exception' => $e]);

        return $e instanceof QuotationNumberException || $e instanceof UniqueConstraintViolationException
            ? 'A quotation number could not be assigned because another quotation was saved at the same moment. Nothing was saved. Please try again.'
            : 'The quotation could not be saved because of a database problem. Nothing was saved. Please try again, or contact the administrator if it keeps happening.';
    }

    /**
     * Update the specified quotation.
     */
    public function update(Request $request, mixed $current_team = null, mixed $quotation = null): RedirectResponse
    {
        $quotation = $this->resolveQuotation($quotation, $current_team);
        $team = $this->getTeam($request, $current_team);
        abort_if(! $team || $quotation->team_id !== $team->id, 403, 'Unauthorized quotation access.');

        $validated = $request->validate([
            'client_name' => ['required', 'string', 'max:255'],
            'contact_person' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:500'],
            // Set by the system: the quotation date is today, Valid Until is set when it is approved.
            'quotation_date' => ['nullable', 'date'],
            'valid_until' => ['nullable', 'date'],
            'status' => ['required', Rule::in(self::FORM_STATUSES)],
            'notes' => ['nullable', 'string', 'max:2000'],
            'terms_and_conditions' => ['nullable', 'string', 'max:2000'],
            'tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'discount' => ['nullable', 'numeric', 'min:0'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['nullable', 'integer'],
            'items.*.sku' => ['required', 'string', 'max:100'],
            'items.*.product_name' => ['required', 'string', 'max:255'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
            'items.*.unit_cost' => ['nullable', 'numeric', 'min:0'],
            'items.*.supplier_id' => ['nullable', 'integer', Rule::exists('suppliers', 'id')->where('team_id', $team->id)],
            'items.*.discount' => ['nullable', 'numeric', 'min:0'],
        ]);

        if (! $quotation->status->isEditable()) {
            return back()->withErrors(['error' => "A quotation that is {$quotation->status->label()} cannot be edited. Revise it first (it will need approval again)."]);
        }

        DB::transaction(function () use ($quotation, $validated, $team) {
            [$itemsData, $subtotal] = $this->buildItems($validated['items'], $team);

            $overallDiscount = (float) ($validated['discount'] ?? 0);
            $taxableAmount = max(0, $subtotal - $overallDiscount);
            $taxRate = isset($validated['tax_rate']) ? (float) $validated['tax_rate'] : (float) $quotation->tax_rate;
            $taxAmount = round($taxableAmount * ($taxRate / 100), 2);
            $grandTotal = $taxableAmount + $taxAmount;

            $quotation->update([
                'client_name' => $validated['client_name'],
                'contact_person' => $validated['contact_person'] ?? null,
                'email' => $validated['email'] ?? null,
                'phone' => $validated['phone'] ?? null,
                'address' => $validated['address'] ?? null,
                // The quotation date stays as created. The approval is cleared below, so the
                // validity date is too: it is set again when the quotation is re-approved.
                'valid_until' => null,
                'subtotal' => $subtotal,
                'discount' => $overallDiscount,
                'tax_rate' => $taxRate,
                'tax_amount' => $taxAmount,
                'grand_total' => $grandTotal,
                'status' => QuotationStatus::Draft,
                'notes' => $validated['notes'] ?? null,
                'terms_and_conditions' => QuotationTerms::text(),
                // A changed quotation needs a fresh approval.
                'approved_by_id' => null, 'approved_at' => null,
            ] + ($validated['status'] === self::SUBMIT_FOR_APPROVAL ? [] : [
                // Saved as a plain Draft: it is no longer waiting for approval.
                'submitted_for_approval_at' => null,
            ]));

            // Re-sync line items
            $quotation->items()->delete();
            foreach ($itemsData as $data) {
                $quotation->items()->create($data);
            }
        });

        $this->submitIfChosen($quotation->fresh(), $validated['status'], $request->user());

        return back()->with('success', 'Quotation updated successfully.');
    }

    /**
     * Change status by hand (back to Draft, or Rejected), or submit a Draft for approval.
     * Approval, sending and the customer's decision have their own actions.
     */
    public function updateStatus(Request $request, mixed $current_team = null, mixed $quotation = null): RedirectResponse
    {
        $quotation = $this->authorizedQuotation($request, $quotation, $current_team);
        $validated = $request->validate([
            'status' => ['required', Rule::in([...array_map(fn ($s) => $s->value, QuotationStatus::manual()), self::SUBMIT_FOR_APPROVAL])],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ]);

        return $this->runWorkflow(function () use ($quotation, $request, $validated) {
            if ($validated['status'] === self::SUBMIT_FOR_APPROVAL) {
                app(QuotationWorkflow::class)->submitForApproval($quotation, $request->user(), $validated['remarks'] ?? null);

                return 'Quotation submitted for approval.';
            }
            $to = QuotationStatus::from($validated['status']);
            app(QuotationWorkflow::class)->changeStatus($quotation, $to, $request->user(), $validated['remarks'] ?? null);

            return "Status changed to {$to->label()}.";
        });
    }

    /** Admin approval. Does not email the customer and does not touch stock. */
    public function approve(Request $request, mixed $current_team = null, mixed $quotation = null): RedirectResponse
    {
        $quotation = $this->authorizedQuotation($request, $quotation, $current_team);
        abort_unless($request->user()->isAdmin(), 403, 'Only administrators can approve quotations.');
        $remarks = $request->validate(['remarks' => ['nullable', 'string', 'max:1000']])['remarks'] ?? null;

        return $this->runWorkflow(function () use ($quotation, $request, $remarks) {
            $approved = app(QuotationWorkflow::class)->approve($quotation, $request->user(), $remarks);

            return "Quotation {$approved->quotation_number} approved. Use Send Quotation to email it to the customer.";
        });
    }

    /** Products are short: one purchase request per short product, for admin approval. The quotation stays Draft. */
    public function requestStock(Request $request, mixed $current_team = null, mixed $quotation = null): RedirectResponse
    {
        $quotation = $this->authorizedQuotation($request, $quotation, $current_team);

        return $this->runWorkflow(function () use ($quotation, $request) {
            [$created, $skipped] = app(QuotationWorkflow::class)->requestStock($quotation, $request->user());
            if ($skipped !== []) {
                session()->flash('warning', 'Not requested: '.implode(' ', $skipped));
            }

            return 'Purchase request'.($created->count() > 1 ? 's ' : ' ').$created->pluck('request_number')->implode(', ')
                .' sent to an administrator for approval. The quotation stays Draft until the stock arrives.';
        });
    }

    /** "Send Quotation" / "Resend": email the approved quotation PDF to the customer now. */
    public function send(Request $request, mixed $current_team = null, mixed $quotation = null): RedirectResponse
    {
        $quotation = $this->authorizedQuotation($request, $quotation, $current_team);
        $request->validate(['resend' => ['sometimes', 'boolean']]);
        $resend = $request->boolean('resend');

        return $this->runWorkflow(function () use ($quotation, $request, $resend) {
            [$sent, $log] = app(QuotationWorkflow::class)->send($quotation, $request->user(), $resend);
            if ($log->status !== EmailStatus::Sent) {
                throw new WorkflowException("The email to {$log->recipient} could not be sent".($log->error ? ": {$log->error}" : '.').' The quotation was not marked as sent. Try again.');
            }

            return "Quotation {$sent->quotation_number} was emailed to {$log->recipient} and is now Sent.";
        });
    }

    /** Record the customer's acceptance or rejection, as received by staff. */
    public function recordDecision(Request $request, mixed $current_team = null, mixed $quotation = null): RedirectResponse
    {
        $quotation = $this->authorizedQuotation($request, $quotation, $current_team);
        $validated = $request->validate([
            'decision' => ['required', Rule::in(['accepted', 'rejected'])],
            'source' => ['required', Rule::in(array_keys(QuotationWorkflow::DECISION_SOURCES))],
            'decided_on' => ['nullable', 'date', 'before_or_equal:today'],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ]);

        return $this->runWorkflow(function () use ($quotation, $request, $validated) {
            $accepted = $validated['decision'] === 'accepted';
            app(QuotationWorkflow::class)->recordDecision(
                $quotation, $accepted, $validated['source'], $validated['remarks'] ?? null, $request->user(), $validated['decided_on'] ?? null,
            );

            return $accepted
                ? "Acceptance recorded for {$quotation->quotation_number}. Create its Sales Order in Sales → New Sales Order."
                : "Customer rejection recorded for {$quotation->quotation_number}.";
        });
    }

    /**
     * Generate the Sales Order from an accepted quotation. Nothing is paid or taken from stock:
     * the order waits for payment (or approved terms) before it is confirmed.
     */
    public function convertToSale(Request $request, mixed $current_team = null, mixed $quotation = null): RedirectResponse
    {
        $quotation = $this->authorizedQuotation($request, $quotation, $current_team);
        if ($request->filled('fulfillment_type')) {
            $request->merge(['fulfillment_type' => strtolower((string) $request->input('fulfillment_type'))]);
        }
        $validated = $request->validate([
            'fulfillment_type' => ['nullable', Rule::in(['delivery', 'pickup'])],
            'delivery_address' => ['nullable', 'string', 'max:500'],
            'delivery_instructions' => ['nullable', 'string', 'max:1000'],
        ]);

        return $this->runWorkflow(function () use ($quotation, $request, $validated) {
            [$sale, $warnings] = app(SalesOrderService::class)->createFromQuotation($quotation, $request->user(), [
                'fulfillment_type' => $validated['fulfillment_type'] ?? 'delivery',
                'address' => $validated['delivery_address'] ?? null,
                'delivery_instructions' => $validated['delivery_instructions'] ?? null,
            ]);
            if ($warnings) {
                session()->flash('warning', 'Stock check: '.implode(' ', $warnings));
            }

            return "Sales Order {$sale->order_number} created (pending payment). Record the customer's payment on the order page.";
        });
    }

    /** The customer-facing quotation PDF (no supplier costs or internal notes). */
    public function pdf(Request $request, mixed $current_team = null, mixed $quotation = null)
    {
        $quotation = $this->authorizedQuotation($request, $quotation, $current_team);
        $sent = $request->boolean('sent') && $quotation->sent_pdf_path && Storage::disk('local')->exists($quotation->sent_pdf_path);
        $content = $sent ? Storage::disk('local')->get($quotation->sent_pdf_path) : app(DocumentPdf::class)->quotation($quotation);

        return response($content, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => ($request->boolean('download') ? 'attachment' : 'inline').'; filename="Quotation-'.$quotation->quotation_number.'.pdf"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /** The official quotation as a PNG or JPG image, rendered from the same PDF (all pages stacked). */
    public function image(Request $request, mixed $current_team = null, mixed $quotation = null)
    {
        $quotation = $this->authorizedQuotation($request, $quotation, $current_team);
        $format = $request->query('format') === 'jpg' ? 'jpg' : 'png';
        $images = app(DocumentImage::class);
        abort_unless($images->available(), 503, 'Image export is not available on the server right now (Poppler pdftoppm is not installed). Download the PDF instead.');

        try {
            $content = $images->fromPdf(app(DocumentPdf::class)->quotation($quotation), $format);
        } catch (\RuntimeException $e) {
            abort(503, $e->getMessage().' Download the PDF instead.');
        }

        return response($content, 200, [
            'Content-Type' => $format === 'jpg' ? 'image/jpeg' : 'image/png',
            'Content-Disposition' => 'attachment; filename="Quotation-'.$quotation->quotation_number.'.'.$format.'"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    private function authorizedQuotation(Request $request, mixed $quotation, mixed $current_team): Quotation
    {
        $quotation = $this->resolveQuotation($quotation, $current_team);
        $team = $this->getTeam($request, $current_team);
        abort_if(! $team || $quotation->team_id !== $team->id, 403, 'Unauthorized quotation access.');

        return $quotation;
    }

    /**
     * Statuses a quotation can be saved with from the staff form.
     */
    /** Not a status: "submit this Draft for approval" (form choice and status action). */
    private const SUBMIT_FOR_APPROVAL = 'submit_for_approval';

    private const FORM_STATUSES = ['draft', self::SUBMIT_FOR_APPROVAL];

    /**
     * Validated form lines → rows to save, and the subtotal. The supplier cost and supplier are
     * kept with the line (internal) and never change the selling price. Without a cost entered:
     * the selected supplier's latest recorded cost (never another supplier's), or the product's
     * own cost when no supplier is selected.
     *
     * @return array{0: list<array<string, mixed>>, 1: float}
     */
    private function buildItems(array $items, Team $team): array
    {
        $products = Product::where('team_id', $team->id)->whereIn('id', collect($items)->pluck('product_id')->filter())->get()->keyBy('id');
        $costs = $products->isEmpty() ? collect() : app(SupplierCosts::class)->latest($team->id, $products->keys()->all());
        $rows = [];
        $subtotal = 0.0;

        foreach ($items as $item) {
            $discount = (float) ($item['discount'] ?? 0);
            $price = (float) $item['unit_price'];
            $qty = (int) $item['quantity'];
            $lineTotal = max(0, ($price - $discount) * $qty);
            $subtotal += $lineTotal;
            $product = isset($item['product_id']) ? $products->get((int) $item['product_id']) : null;

            $rows[] = [
                'product_id' => $product?->id,
                'sku' => $item['sku'],
                'product_name' => $item['product_name'],
                'quantity' => $qty,
                'unit_price' => $price,
                'unit_cost' => match (true) {
                    isset($item['unit_cost']) && $item['unit_cost'] !== '' => (float) $item['unit_cost'],
                    ! empty($item['supplier_id']) => $product ? ($costs->get($product->id.':'.$item['supplier_id'])['unit_cost'] ?? null) : null,
                    default => $product?->cost_price,
                },
                'supplier_id' => ($item['supplier_id'] ?? null) ?: $product?->supplier_id,
                'discount' => $discount,
                'subtotal' => $lineTotal,
            ];
        }

        return [$rows, $subtotal];
    }

    /**
     * Download or view attached quotation specification file.
     */
    public function downloadAttachment(Request $request, mixed $current_team = null, mixed $quotation = null)
    {
        $quotation = $this->resolveQuotation($quotation, $current_team);
        $team = $this->getTeam($request, $current_team);
        abort_if(! $team || $quotation->team_id !== $team->id, 403, 'Unauthorized quotation access.');

        // ?file=materials: the client's original material-list file instead of the general attachment.
        $isMaterialFile = $request->query('file') === 'materials';
        $path = $isMaterialFile ? $quotation->material_file_path : $quotation->attachment_path;
        abort_if(! $path, 404, 'No attachment associated with this quotation.');
        abort_if(! Storage::disk('local')->exists($path), 404, 'Attachment file not found.');

        return Storage::disk('local')->response(
            $path,
            $isMaterialFile ? ($quotation->material_file_name ?: basename($path)) : null,
            ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']
        );
    }

    /**
     * Open a product-list file the client sent with a request. Staff only (route group), and
     * only for a quotation of the current team; the file must belong to that quotation.
     */
    public function downloadFile(Request $request, mixed $current_team = null, mixed $quotation = null, mixed $file = null)
    {
        $quotation = $this->resolveQuotation($quotation ?? $request->route('quotation'));
        $team = $this->getTeam($request, $current_team);
        abort_if(! $team || $quotation->team_id !== $team->id, 403, 'Unauthorized quotation access.');

        $record = $quotation->files()->whereKey((int) ($file ?? $request->route('file')))->first();
        abort_if(! $record || ! Storage::disk('local')->exists($record->path), 404, 'File not found.');

        return Storage::disk('local')->response($record->path, $record->original_name, [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Import a material list (PDF, Excel, CSV, JPG or PNG) as a new Draft quotation.
     *
     * Without `execute` it only reads the file and returns the lines for staff to check. With
     * `execute` it creates the quotation: lines whose product code matches the catalog get the
     * catalog price, every other line is a custom item at ₱0.00 for staff to price. The file's
     * fingerprint is stored, so importing the same file again is refused instead of duplicated.
     *
     * Columns are found from the header row. When they cannot be (no description or quantity
     * column), the reply has `needs_mapping` with the first rows of the file, so staff choose the
     * columns and send them back as `mapping` ({field: column index}) and `header_row`.
     */
    public function importMaterials(Request $request, FileReader $reader): JsonResponse|RedirectResponse
    {
        $team = $this->getTeam($request);
        abort_if(! $team, 404, 'Team not found.');

        try {
            $request->validate([
                'file' => ['required', 'file', 'max:10240', 'extensions:'.implode(',', FileReader::EXTENSIONS)],
                'client_name' => ['required', 'string', 'max:255'],
                'contact_person' => ['nullable', 'string', 'max:255'],
                'email' => ['nullable', 'email', 'max:255'],
                'phone' => ['nullable', 'string', 'max:50'],
                'execute' => ['nullable', 'boolean'],
                'items' => ['nullable'],
                'mapping' => ['nullable'],
                'header_row' => ['nullable', 'integer', 'min:-1', 'max:49'],
            ], [
                'file.max' => 'The file is larger than 10 MB. Please upload a smaller file.',
                'file.extensions' => 'This file type is not supported. Please upload a PDF, Excel (.xlsx/.xls), CSV, JPG or PNG file.',
                'client_name.required' => 'Please enter the client or company name.',
                'header_row.integer' => 'Choose the header row from the rows shown, or "No header row".',
                'header_row.min' => 'Choose the header row from the rows shown, or "No header row".',
                'header_row.max' => 'Choose the header row from the rows shown, or "No header row".',
            ]);
        } catch (ValidationException $e) {
            if ($request->boolean('execute')) {
                throw $e;
            }

            // The preview is read by the page's script, which needs JSON rather than a redirect.
            return response()->json(['success' => false, 'message' => collect($e->errors())->flatten()->first(), 'errors' => $e->errors()], 422);
        }

        $fail = fn (string $message, int $status = 422, array $extra = []) => $request->boolean('execute')
            ? back()->withErrors(['file' => $message])
            : response()->json(['success' => false, 'message' => $message] + $extra, $status);

        $file = $request->file('file');
        $path = $file->getRealPath() ?: $file->getPathname();
        $hash = hash_file('sha256', $path);

        $duplicate = Quotation::where('team_id', $team->id)->where('source_file_sha256', $hash)->first(['id', 'quotation_number']);
        if ($duplicate) {
            return $fail("This file was already imported as quotation {$duplicate->quotation_number}. Open that quotation instead of importing it again.", 409, ['duplicate_of' => $duplicate->quotation_number]);
        }

        // Columns chosen by staff (null: find them from the header row).
        $mapping = $this->chosenMaterialColumns($request->input('mapping'));
        if (is_string($mapping)) {
            return $fail($mapping);
        }
        $headerRow = $request->filled('header_row') && (int) $request->input('header_row') >= 0 ? (int) $request->input('header_row') : null;

        if (! app()->runningUnitTests()) { // OCR of a scanned file can take a while
            @set_time_limit(180);
        }

        try {
            $type = $reader->detectType($path, $file->getClientOriginalName());
            $table = in_array($type, ['pdf', 'image'], true)
                ? $reader->readTable($path, $type, fn (array $cells, bool $words = false) => HeaderMapper::isMaterialHeader($cells, $words))
                : null;
            // A PDF or image is read as a table when its header row was found, or when staff chose
            // the columns. Otherwise its text is read line by line ("10 pcs Circuit breaker").
            $read = $table !== null && (($table['aligned'] ?? false) || $mapping !== null)
                ? $table
                : $reader->read($path, $type);
            // The rows staff choose columns from: the table, or the lines of the text split at column gaps.
            $rows = $read['rows'] ?? $table['rows'] ?? self::materialTextRows($read['pages'] ?? []);
            $fromTable = $mapping !== null || isset($read['rows']);

            $parsed = match (true) {
                $mapping !== null => MaterialParser::parseTable($rows, $mapping, $headerRow, $read['method']),
                $fromTable => MaterialParser::parseTable($rows, null, null, $read['method']),
                default => MaterialParser::parseText(MaterialParser::cleanLines($read['pages']), $read['method']),
            };
        } catch (ExtractionException $e) {
            return $fail(str_replace(['Please type your material list below instead.', ', or type your material list below', 'Please type your material list below, or upload an Excel/CSV file.'], ['', '', 'Please upload an Excel or CSV file instead.'], $e->getMessage()));
        }

        // What staff see to choose the columns: the table read, or the text's rows when they look like one.
        $choice = $fromTable ? $parsed : (self::looksLikeTable($rows) ? MaterialParser::parseTable($rows, null, null, $read['method']) : null);

        // Chosen columns must include the description and the quantity.
        if ($mapping !== null && ! $parsed['confident']) {
            return $fail($parsed['warnings'][0] ?? HeaderMapper::describeMissing($mapping), 422, ['needs_mapping' => true] + $this->materialColumnChoice($parsed));
        }

        // If executing with user-edited preview items, apply user corrections before matching and saving.
        $edited = false;
        if ($request->boolean('execute') && $request->filled('items')) {
            $rawEdited = $request->input('items');
            $decoded = is_string($rawEdited) ? json_decode($rawEdited, true) : (is_array($rawEdited) ? $rawEdited : null);
            if (is_array($decoded)) {
                $editedItems = [];
                foreach (array_slice($decoded, 0, 200) as $idx => $entry) {
                    if (! is_array($entry) || ! empty($entry['skip'])) {
                        continue;
                    }
                    $desc = trim((string) ($entry['description'] ?? ''));
                    $qtyRaw = $entry['quantity'] ?? null;
                    $qty = is_numeric($qtyRaw) ? (float) $qtyRaw : null;
                    $errors = [];
                    if ($desc === '') {
                        $errors[] = 'Missing item description.';
                    }
                    if ($qty === null || $qty <= 0) {
                        $errors[] = 'Quantity must be greater than zero.';
                    }
                    $text = fn (string $key, int $max) => isset($entry[$key]) && is_scalar($entry[$key]) && trim((string) $entry[$key]) !== ''
                        ? mb_substr(trim((string) $entry[$key]), 0, $max)
                        : null;
                    $editedItems[] = [
                        'line' => $idx + 1,
                        'description' => $desc,
                        'quantity' => $qty !== null && $qty > 0 ? (fmod($qty, 1.0) === 0.0 ? (int) $qty : round($qty, 3)) : null,
                        'unit' => $text('unit', 30),
                        'brand' => $text('brand', 100),
                        'specs' => $text('specs', 500),
                        'unit_price' => null,
                        'remarks' => null,
                        'item_code' => $text('item_code', 60), // kept so the catalog match by product code stays
                        'confidence' => 'high',
                        'needs_review' => false,
                        'warnings' => [],
                        'errors' => $errors,
                    ];
                }
                $parsed['items'] = $editedItems;
                $edited = true;
            }
        }

        $items = (new ProductMatcher($team->id))->matchAll($parsed['items']);
        $valid = array_values(array_filter($items, fn ($i) => ($i['errors'] ?? []) === []));
        $ocr = in_array($read['method'], ['ocr', 'pdf-ocr'], true);

        if ($valid === []) {
            if ($edited) {
                return $fail('No line has both a description and a quantity greater than zero. Correct a line, or restore a skipped one.');
            }

            return $fail(
                $this->noMaterialsMessage($choice, $fromTable, $mapping !== null),
                422,
                $choice !== null && $choice['preview'] !== [] ? ['needs_mapping' => true] + $this->materialColumnChoice($choice) : [],
            );
        }

        if (! $request->boolean('execute')) {
            return response()->json([
                'success' => true,
                'method' => $read['method'],
                'warning' => $ocr ? 'Read with text recognition (OCR). Check every line against the file after importing.' : null,
                'valid_count' => count($valid),
                'invalid_count' => count($items) - count($valid),
                'unparsed_count' => count($parsed['unparsed_lines'] ?? []),
                'confident' => (bool) ($parsed['confident'] ?? false),
                // The columns used, so staff can change them ("Change columns").
                ...$this->materialColumnChoice($choice ?? []),
                'items' => array_map(fn ($i) => [
                    'description' => $i['description'],
                    'quantity' => $i['quantity'],
                    'unit' => $i['unit'],
                    'brand' => $i['brand'],
                    'specs' => $i['specs'] ?? null,
                    'item_code' => $i['item_code'] ?? null,
                    'confidence' => $i['extraction_confidence'] ?? $i['confidence'] ?? 'medium',
                    'needs_review' => (bool) ($i['needs_review'] ?? false),
                    'warnings' => $i['warnings'] ?? [],
                    'errors' => $i['errors'] ?? [],
                    'match' => $i['match'] ? ['sku' => $i['match']['sku'], 'name' => $i['match']['name'], 'price' => $i['match']['price']] : null,
                ], array_slice($items, 0, 200)),
            ]);
        }

        $storedFile = null;
        try {
            $quotation = $this->createImportedQuotation($request, $team, $file, $hash, $valid, $parsed, $storedFile);
        } catch (QuotationNumberException|QueryException $e) {
            if ($storedFile) {
                Storage::disk('local')->delete($storedFile);
            }

            return $fail($this->savingFailed($e, $team, 'material-list import'), 503);
        }

        return back()->with('success', "Created draft quotation {$quotation->quotation_number} with {$quotation->items()->count()} line(s). Check the lines against the original file and set the prices.");
    }

    /**
     * The material-list columns staff chose: {field => column index}, sent as JSON or an array.
     * Null when none were sent. A string is a message for staff when the choice cannot be used.
     *
     * @return array<string, int>|string|null
     */
    private function chosenMaterialColumns(mixed $raw): array|string|null
    {
        if ($raw === null || $raw === '' || $raw === 'null') {
            return null;
        }
        $decoded = is_string($raw) ? json_decode($raw, true) : $raw;
        if (! is_array($decoded)) {
            return 'The chosen columns could not be read. Please choose the columns again.';
        }

        $mapping = [];
        foreach ($decoded as $field => $column) {
            // Unknown fields are ignored; null or '' is "not in the file".
            if (! is_string($field) || ! isset(HeaderMapper::SYNONYMS[$field]) || $column === null || $column === '') {
                continue;
            }
            $index = is_int($column) || (is_string($column) && ctype_digit($column)) ? (int) $column : -1;
            if ($index < 0 || $index >= FileReader::MAX_COLS) {
                return 'The column chosen for '.mb_strtolower(HeaderMapper::LABELS[$field]).' is not a column of the file. Please choose the columns again.';
            }
            $taken = array_search($index, $mapping, true);
            if ($taken !== false) {
                return '"'.HeaderMapper::LABELS[$taken].'" and "'.HeaderMapper::LABELS[$field].'" are both set to column '
                    .self::materialColumnLetter($index).'. Choose a different column for each.';
            }
            $mapping[$field] = $index;
        }

        return $mapping;
    }

    /**
     * What staff need to choose the columns themselves: the first rows of the file, the column
     * labels, the header row and columns found so far, and the fields they can choose.
     *
     * @param  array<string, mixed>  $table  a MaterialParser::parseTable() result ([] when there is none)
     */
    private function materialColumnChoice(array $table): array
    {
        return [
            'columns' => array_map(fn ($c) => Str::limit((string) $c, 60), $table['columns'] ?? []),
            'preview' => array_map(fn ($row) => array_map(fn ($c) => Str::limit((string) $c, 80), $row), $table['preview'] ?? []),
            'header_index' => $table['header_index'] ?? null,
            'header_detected' => (bool) ($table['header_detected'] ?? false),
            'mapping' => (object) (array) ($table['mapping'] ?? []),
            'missing' => $table['missing'] ?? HeaderMapper::REQUIRED,
            'fields' => array_map(fn (string $key) => [
                'key' => $key,
                'label' => HeaderMapper::LABELS[$key],
                'required' => in_array($key, HeaderMapper::REQUIRED, true),
            ], array_keys(HeaderMapper::LABELS)),
        ];
    }

    /**
     * Why no material line could be read, for staff: which required columns are missing (and the
     * columns found), or that the columns were found but no row has a description and a quantity.
     *
     * @param  array<string, mixed>|null  $choice  the table staff can choose columns from
     */
    private function noMaterialsMessage(?array $choice, bool $fromTable, bool $chosen): string
    {
        // A document with no table and no lines such as "10 pcs Circuit breaker".
        if ($choice === null || (! $fromTable && ! $choice['header_detected'] && ! $choice['confident'])) {
            return 'No table with a description and a quantity column, and no lines like "10 pcs Circuit breaker", were found in this file. '
                .($choice !== null ? 'Choose the description and quantity columns below, or upload an Excel or CSV file.' : 'Please check the file, or upload an Excel or CSV file.');
        }
        if (! $choice['confident']) {
            return $choice['warnings'][0] ?? HeaderMapper::describeMissing((array) $choice['mapping'], $choice['columns']);
        }

        // The columns are there, but no row has both values.
        $mapping = (array) $choice['mapping'];
        $label = fn (string $field) => trim((string) ($choice['columns'][$mapping[$field]] ?? ''));
        $named = ! preg_match('/^(Column [A-Z]+)?$/', $label('description')) && ! preg_match('/^(Column [A-Z]+)?$/', $label('quantity'));
        $columns = $named
            ? 'The "'.Str::limit($label('description'), 40).'" and "'.Str::limit($label('quantity'), 40).'" columns were '.($chosen ? 'chosen' : 'found')
            : 'Column '.self::materialColumnLetter($mapping['description']).' (description) and column '.self::materialColumnLetter($mapping['quantity']).' (quantity) were chosen';
        $rows = count($choice['items']);

        return match ($rows) {
            0 => "{$columns}, but there are no rows below the header row. Check the file, or choose a different header row.",
            1 => "{$columns}, but the only row does not have both a description and a quantity greater than zero. Check the file, or choose different columns.",
            default => "{$columns}, but none of the {$rows} rows has both a description and a quantity greater than zero. Check the file, or choose different columns.",
        };
    }

    /**
     * The lines of a document's text as rows, split where the text leaves a column gap.
     *
     * @param  array<int, string>  $pages
     * @return array<int, array<int, string>>
     */
    private static function materialTextRows(array $pages): array
    {
        return array_map(fn (string $line) => explode("\t", $line), MaterialParser::cleanLines($pages));
    }

    /** Whether rows look like a table: at least two rows with two or more filled cells. */
    private static function looksLikeTable(array $rows): bool
    {
        $multi = 0;
        foreach (array_slice($rows, 0, 50) as $row) {
            $filled = array_filter((array) $row, fn ($c) => is_scalar($c) && trim((string) $c) !== '');
            if (count($filled) >= 2 && ++$multi >= 2) {
                return true;
            }
        }

        return false;
    }

    /** Spreadsheet column letter: 0 → A, 26 → AA. */
    private static function materialColumnLetter(int $index): string
    {
        $letters = '';
        for ($i = $index + 1; $i > 0; $i = intdiv($i - 1, 26)) {
            $letters = chr(65 + (($i - 1) % 26)).$letters;
        }

        return $letters;
    }

    /**
     * The draft quotation for an imported material list, in one transaction (retried on deadlock).
     * $storedFile receives the stored copy of the file, for clean-up if saving fails.
     */
    private function createImportedQuotation(Request $request, Team $team, UploadedFile $file, string $hash, array $valid, array $parsed, ?string &$storedFile): Quotation
    {
        $storedFile = $file->storeAs('quotations/attachments', 'material-list-'.Str::uuid().'.'.strtolower($file->getClientOriginalExtension()), 'local');

        return DB::transaction(function () use ($request, $team, $file, $hash, $valid, $parsed, $storedFile) {
            $client = Client::firstOrCreate(
                ['team_id' => $team->id, 'company_name' => $request->input('client_name')],
                ['contact_person' => $request->input('contact_person'), 'email' => $request->input('email'), 'phone' => $request->input('phone')]
            );

            $subtotal = 0.0;
            $lines = [];
            foreach ($valid as $item) {
                $price = $item['match'] && (float) $item['match']['price'] > 0 ? round((float) $item['match']['price'], 2) : 0.0;
                $lines[] = [
                    'product_id' => $item['match']['product_id'] ?? null,
                    'sku' => $item['match']['sku'] ?? 'CUSTOM',
                    'product_name' => Str::limit($item['match']['name'] ?? trim($item['description'].($item['brand'] ? " — {$item['brand']}" : '')), 250),
                    'quantity' => (int) $item['quantity'],
                    'unit_price' => $price,
                    'discount' => 0.00,
                    'subtotal' => round($price * (int) $item['quantity'], 2),
                ];
                $subtotal += round($price * (int) $item['quantity'], 2);
            }

            $taxRate = (float) config('quotation.tax_rate', 12);
            $taxAmount = round($subtotal * $taxRate / 100, 2);
            $unpriced = count(array_filter($lines, fn ($l) => $l['unit_price'] <= 0));
            $originalName = Str::limit(basename(str_replace('\\', '/', $file->getClientOriginalName())), 200, '');

            $quotation = Quotation::createWithNumber([
                'team_id' => $team->id,
                'client_id' => $client->id,
                'client_name' => $client->company_name,
                'contact_person' => $request->input('contact_person') ?: $client->contact_person,
                'email' => $request->input('email') ?: $client->email,
                'phone' => $request->input('phone') ?: $client->phone,
                'quotation_date' => now()->toDateString(),
                'valid_until' => null, // set when approved
                'subtotal' => round($subtotal, 2),
                'discount' => 0.00,
                'tax_rate' => $taxRate,
                'tax_amount' => $taxAmount,
                'grand_total' => round($subtotal + $taxAmount, 2),
                'status' => QuotationStatus::Draft,
                'notes' => "Imported from material list file: {$originalName}\n".count($lines).' line(s) read'
                    .($unpriced ? ", {$unpriced} need pricing and product matching by staff." : '.')
                    .(count($parsed['unparsed_lines'] ?? []) ? "\n".count($parsed['unparsed_lines']).' line(s) of the file could not be read as materials: check the original file.' : ''),
                'terms_and_conditions' => $this->defaultTerms(),
                'prepared_by_id' => $request->user()?->id,
                'material_file_path' => $storedFile,
                'material_file_name' => $originalName,
                'source_file_sha256' => $hash,
                'request_details' => [
                    'source' => 'staff_import',
                    'file_name' => $originalName,
                    'imported_by' => $request->user()?->name,
                    'submitted_at' => now()->toIso8601String(),
                ],
            ], collect($lines)->firstWhere('product_id')['sku'] ?? null);
            $quotation->items()->createMany($lines);

            return $quotation;
        }, 3);
    }

    /**
     * Delete the specified quotation.
     */
    public function destroy(Request $request, mixed $current_team = null, mixed $quotation = null): RedirectResponse
    {
        abort_unless($request->user()?->isAdmin(), 403, 'Only administrators can delete records.');
        if ($request->has('confirmation')) {
            $request->validate(['confirmation' => ['required', 'string', Rule::in(['YES'])]]);
        }
        $quotation = $this->resolveQuotation($quotation, $current_team);
        $team = $this->getTeam($request, $current_team);
        abort_if(! $team || $quotation->team_id !== $team->id, 403, 'Unauthorized quotation access.');

        $quotation->delete();

        return back()->with('success', 'Quotation deleted.');
    }

    /**
     * Helper to retrieve team from request or route parameter.
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
     * Resolve quotation from route parameters.
     */
    protected function resolveQuotation(mixed $quotation, mixed $fallback = null): Quotation
    {
        if ($quotation instanceof Quotation) {
            return $quotation;
        }

        if (is_numeric($quotation)) {
            return Quotation::findOrFail((int) $quotation);
        }

        if ($fallback instanceof Quotation) {
            return $fallback;
        }

        if (is_numeric($fallback)) {
            return Quotation::findOrFail((int) $fallback);
        }

        abort(404, 'Quotation not found.');
    }

    /** The fixed Terms and Conditions every quotation carries (see QuotationTerms). */
    protected function defaultTerms(): string
    {
        return QuotationTerms::text();
    }

    /**
     * Process CSV Import for Quotations.
     * Required headers: DATE, QUOTE NUMBER, QUANTITY, DESCRIPTION, UNIT PRICE, AMOUNT
     */
    public function importCsv(Request $request): JsonResponse|RedirectResponse
    {
        $team = $this->getTeam($request);
        abort_if(! $team, 404, 'Team not found.');

        $request->validate([
            'file' => ['required', 'file', 'extensions:csv,txt'],
            'mode' => ['nullable', 'in:append,replace'],
            'execute' => ['nullable', 'boolean'],
            'default_client_name' => ['nullable', 'string', 'max:255'],
        ]);

        // Replace mode archives (inventory) or overwrites (quotations) existing records, so it is admin-only.
        abort_if($request->input('mode') === 'replace' && ! $request->user()->isAdmin(), 403, 'Only administrators can use Replace mode.');

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

        // Determine delimiter from first line (support tab, comma, semicolon)
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

        // Clean headers (remove UTF-8 BOM, trim, lowercase, strip non-alphanumeric)
        $rawHeaders = $lines[0];
        $headers = array_map(function ($h) {
            $cleaned = preg_replace('/^\xEF\xBB\xBF/', '', (string) $h);

            return preg_replace('/[^a-z0-9]/', '', strtolower($cleaned));
        }, $rawHeaders);

        $dateIdx = $this->findHeaderIndex($headers, ['date', 'quotedate', 'quotationdate']);
        $quoteNumIdx = $this->findHeaderIndex($headers, ['quotenumber', 'quoteno', 'quotationnumber', 'quote', 'quotation']);
        $qtyIdx = $this->findHeaderIndex($headers, ['quantity', 'qty', 'count']);
        $descIdx = $this->findHeaderIndex($headers, ['description', 'productname', 'itemname', 'product', 'item', 'desc']);
        $priceIdx = $this->findHeaderIndex($headers, ['unitprice', 'price', 'rate', 'cost', 'unitcost']);
        $amountIdx = $this->findHeaderIndex($headers, ['amount', 'total', 'subtotal', 'totalamount', 'linetotal']);

        if ($dateIdx === -1 || $qtyIdx === -1 || $descIdx === -1 || $priceIdx === -1) {
            return response()->json([
                'success' => false,
                'message' => 'Required column headers missing. CSV must include headers: DATE, QUOTE NUMBER, QUANTITY, DESCRIPTION, UNIT PRICE, AMOUNT.',
                'valid_rows' => [],
                'invalid_rows' => [],
            ], 422);
        }

        $validRows = [];
        $invalidRows = [];
        $autoNumbers = []; // date => number shown for rows without a quote number

        for ($i = 1; $i < count($lines); $i++) {
            $row = $lines[$i];
            if (empty(array_filter($row))) {
                continue;
            }
            $normDescHeader = preg_replace('/[^a-z0-9]/', '', strtolower(trim((string) ($row[$descIdx] ?? ''))));
            if (in_array($normDescHeader, ['description', 'productname', 'itemname', 'product', 'item', 'desc'], true) && ! is_numeric(trim((string) ($row[$qtyIdx] ?? '')))) {
                continue; // skip repeated header row
            }

            $rowNumber = $i + 1;
            $errors = [];

            // 1. DATE: the template's DATE column is MONTH-DAY-YEAR (eg 10-25-2026, 01-04-2023), so
            // that order settles ambiguous dates; unambiguous DD-MM-YYYY and YYYY-MM-DD dates are
            // accepted, and invalid ones are rejected (see DateNormalizer).
            $rawDate = isset($row[$dateIdx]) ? trim($row[$dateIdx]) : '';
            $parsedDate = null;
            $displayDate = $rawDate;

            $date = DateNormalizer::parse($rawDate, DateNormalizer::MDY);
            if ($date['status'] === DateNormalizer::EMPTY) {
                $errors[] = 'Date is required.';
            } elseif ($date['value'] === null) {
                $errors[] = $date['problem'];
            } else {
                $parsedDate = $date['value'];
                $displayDate = Carbon::createFromFormat('!Y-m-d', $parsedDate)->format('m-d-Y');
            }

            // 2. QUANTITY
            $rawQty = isset($row[$qtyIdx]) ? preg_replace('/[^0-9]/', '', (string) $row[$qtyIdx]) : '0';
            $qty = is_numeric($rawQty) ? (int) $rawQty : 0;
            if ($qty <= 0) {
                $errors[] = 'Quantity must be at least 1.';
            }

            // 3. DESCRIPTION
            $desc = isset($row[$descIdx]) ? trim($row[$descIdx]) : '';
            if ($desc === '') {
                $errors[] = 'Description is required.';
            }

            // 4. UNIT PRICE
            $rawPrice = isset($row[$priceIdx]) ? preg_replace('/[^0-9.-]/', '', (string) $row[$priceIdx]) : '0';
            $unitPrice = is_numeric($rawPrice) ? (float) $rawPrice : 0.00;
            if ($unitPrice < 0) {
                $errors[] = 'Unit price cannot be negative.';
            }

            // 5. AMOUNT
            $rawAmount = $amountIdx !== -1 && isset($row[$amountIdx]) ? preg_replace('/[^0-9.-]/', '', (string) $row[$amountIdx]) : '';
            $amount = (is_numeric($rawAmount) && (float) $rawAmount > 0)
                ? (float) $rawAmount
                : round($qty * $unitPrice, 2);

            // 6. QUOTE NUMBER (e.g. Q010423-068SSCR2)
            $rawQuoteNum = $quoteNumIdx !== -1 && isset($row[$quoteNumIdx]) ? trim($row[$quoteNumIdx]) : '';
            $extractedSku = null;
            $autoNumber = $rawQuoteNum === '';

            if (! $autoNumber) {
                if (preg_match('/^[Qq]\d{6,8}-([A-Za-z0-9_-]+)$/', $rawQuoteNum, $skuMatch)) {
                    $extractedSku = strtoupper($skuMatch[1]);
                } elseif (str_contains($rawQuoteNum, '-')) {
                    $parts = explode('-', $rawQuoteNum, 2);
                    $extractedSku = strtoupper(trim($parts[1]));
                }
            } else {
                // No number in the file: rows of the same day become one quotation, numbered when
                // it is saved. The preview only shows the number it would probably get.
                $extractedSku = 'PROD';
                $rawQuoteNum = $autoNumbers[$parsedDate ?? ''] ??= Quotation::peekNumber($team->id, $extractedSku, $parsedDate);
            }

            if (! empty($errors)) {
                $invalidRows[] = [
                    'row_number' => $rowNumber,
                    'date' => $rawDate,
                    'quote_number' => $rawQuoteNum,
                    'quantity' => $rawQty,
                    'description' => $desc,
                    'unit_price' => $rawPrice,
                    'amount' => $rawAmount,
                    'errors' => $errors,
                ];
            } else {
                $validRows[] = [
                    'row_number' => $rowNumber,
                    'date' => $parsedDate,
                    'original_date' => $rawDate,
                    'display_date' => $displayDate,
                    'quote_number' => $rawQuoteNum,
                    'auto_number' => $autoNumber,
                    'sku' => $extractedSku ?: 'SKU-'.strtoupper(substr(md5($desc), 0, 6)),
                    'quantity' => $qty,
                    'description' => $desc,
                    'unit_price' => $unitPrice,
                    'amount' => $amount,
                ];
            }
        }

        // Quote numbers already in the system are skipped on import (never duplicated), unless an
        // administrator chooses Replace mode. Archived quotations still own their numbers.
        $existingNumbers = Quotation::withTrashed()
            ->where('team_id', $team->id)
            ->whereIn('quotation_number', array_unique(array_column($validRows, 'quote_number')))
            ->pluck('quotation_number')->map(fn ($n) => mb_strtolower($n))->flip();
        foreach ($validRows as &$validRow) {
            $validRow['exists'] = ! $validRow['auto_number'] && isset($existingNumbers[mb_strtolower($validRow['quote_number'])]);
        }
        unset($validRow);

        // Preview validation mode
        if (! $request->boolean('execute')) {
            return response()->json([
                'success' => true,
                'existing_count' => count(array_unique(array_column(array_filter($validRows, fn ($r) => $r['exists']), 'quote_number'))),
                'total_rows' => count($lines) - 1,
                'valid_count' => count($validRows),
                'invalid_count' => count($invalidRows),
                'valid_rows' => array_slice($validRows, 0, 50),
                'invalid_rows' => $invalidRows,
            ]);
        }

        if (empty($validRows)) {
            return back()->withErrors(['error' => 'No valid rows found to import from the CSV.']);
        }

        $mode = $request->input('mode', 'append');
        $defaultClientName = $request->input('default_client_name') ?: 'Direct Customer';

        // Group items by Quote Number (rows without one: by day, numbered on save)
        $grouped = [];
        foreach ($validRows as $item) {
            $qNum = $item['auto_number'] ? 'auto|'.$item['date'] : $item['quote_number'];
            $grouped[$qNum][] = $item;
        }

        $skipped = [];
        try {
            $this->importCsvGroups($request, $team, $grouped, $mode, $defaultClientName, $skipped);
        } catch (QuotationNumberException|QueryException $e) {
            $message = $this->savingFailed($e, $team, 'CSV import');
            if ($e instanceof UniqueConstraintViolationException) {
                $message = 'A quote number in this file was used by another quotation while importing. Nothing was imported. Please run the import again.';
            }

            return $request->wantsJson()
                ? response()->json(['success' => false, 'message' => $message], 409)
                : back()->withErrors(['error' => $message]);
        }

        $imported = count($grouped) - count($skipped);
        $message = "Imported {$imported} quotation(s)."
            .($skipped ? ' Skipped '.count($skipped).' that already exist: '.implode(', ', array_slice($skipped, 0, 5)).(count($skipped) > 5 ? '…' : '').'.' : '');

        if ($request->wantsJson()) {
            return response()->json(['success' => true, 'message' => $message, 'skipped' => $skipped]);
        }

        return back()->with('success', $message);
    }

    /**
     * Save the grouped CSV rows as quotations, all in one transaction (retried on deadlock).
     *
     * @param  array<string, array<int, array<string, mixed>>>  $grouped  quote number (or "auto|<date>") => rows
     * @param  array<int, string>  $skipped  receives the quote numbers that already exist
     */
    private function importCsvGroups(Request $request, Team $team, array $grouped, string $mode, string $defaultClientName, array &$skipped): void
    {
        DB::transaction(function () use ($team, $grouped, $mode, $defaultClientName, $request, &$skipped) {
            $skipped = [];
            $client = Client::firstOrCreate(
                [
                    'team_id' => $team->id,
                    'company_name' => $defaultClientName,
                ],
                [
                    'contact_person' => 'Direct Quotation Contact',
                ]
            );

            foreach ($grouped as $quoteNumber => $items) {
                $first = $items[0];
                $qDate = $first['date'];
                $validUntil = Carbon::parse($qDate)->addDays((int) config('company.quotation_validity_days'))->toDateString();
                $totalAmount = array_sum(array_column($items, 'amount'));

                $auto = (bool) $first['auto_number'];
                $existing = $auto ? null : Quotation::withTrashed()->where('team_id', $team->id)->where('quotation_number', $quoteNumber)->first();

                if ($existing && $mode === 'replace' && ! $existing->trashed()) {
                    $quotation = $existing;
                    $quotation->update([
                        'quotation_date' => $qDate,
                        'valid_until' => $validUntil,
                        'subtotal' => $totalAmount,
                        'grand_total' => $totalAmount,
                        'status' => QuotationStatus::Draft,
                    ]);
                    $quotation->items()->delete();
                } elseif ($existing) {
                    // Importing the same file again must not create a second copy of the quotation,
                    // and an archived quotation keeps its number (it is never overwritten from here).
                    $skipped[] = $quoteNumber;

                    continue;
                } else {
                    $attributes = [
                        'team_id' => $team->id,
                        'client_id' => $client->id,
                        'client_name' => $client->company_name,
                        'quotation_date' => $qDate,
                        'valid_until' => $validUntil,
                        'subtotal' => $totalAmount,
                        'discount' => 0.00,
                        'tax_rate' => 0.00,
                        'tax_amount' => 0.00,
                        'grand_total' => $totalAmount,
                        'status' => QuotationStatus::Draft,
                        'notes' => 'Imported via CSV file with headers: DATE, QUOTE NUMBER, QUANTITY, DESCRIPTION, UNIT PRICE, AMOUNT',
                        'terms_and_conditions' => $this->defaultTerms(),
                        'prepared_by_id' => $request->user()?->id,
                    ];
                    // The file's own number is kept as written; rows without one get the next free number.
                    $quotation = $auto
                        ? Quotation::createWithNumber($attributes, 'PROD', $qDate)
                        : Quotation::create(['quotation_number' => $quoteNumber] + $attributes);
                }

                foreach ($items as $item) {
                    $matchedProduct = Product::where('team_id', $team->id)
                        ->where(function ($q) use ($item) {
                            $q->where('sku', $item['sku'])
                                ->orWhere('name', $item['description']);
                        })->first();

                    $quotation->items()->create([
                        'product_id' => $matchedProduct?->id,
                        'sku' => $matchedProduct?->sku ?? $item['sku'],
                        'product_name' => $item['description'],
                        'quantity' => $item['quantity'],
                        'unit_price' => $item['unit_price'],
                        'discount' => 0.00,
                        'subtotal' => $item['amount'],
                    ]);
                }
            }
        }, 3);
    }

    /**
     * Helper to find matching header index.
     */
    protected function findHeaderIndex(array $headers, array $candidates): int
    {
        foreach ($candidates as $cand) {
            $idx = array_search($cand, $headers, true);
            if ($idx !== false) {
                return (int) $idx;
            }
        }
        foreach ($candidates as $cand) {
            foreach ($headers as $idx => $h) {
                if (str_contains($h, $cand)) {
                    return (int) $idx;
                }
            }
        }

        return -1;
    }
}
