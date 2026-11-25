<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\BiddingEvent;
use App\Models\PurchaseRequest;
use App\Models\Supplier;
use App\Models\SupplierBid;
use App\Models\Team;
use App\Services\AuditLogger;
use App\Services\Documents\DocumentPdf;
use App\Services\MaterialList\ExtractionException;
use App\Services\Procurement\BiddingWorkflow;
use App\Services\Procurement\BidQuotationReader;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Throwable;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Staff-managed supplier bidding (RFQ). Admin and Procurement staff run it; awarding is owner-only (admin).
 * Suppliers have no accounts and never use this system.
 */
class BiddingController extends Controller
{
    public function __construct(private BiddingWorkflow $workflow) {}

    public function index(Request $request): Response
    {
        $team = $this->team($request);
        $status = $request->input('status');

        $events = BiddingEvent::where('team_id', $team->id)
            ->with(['purchaseRequest:id,request_number,product_id,product_description,quantity,unit', 'purchaseRequest.product:id,name,sku', 'awardedBid.supplier:id,name', 'purchaseOrder:id,po_number'])
            ->withCount(['invitations', 'bids'])
            ->when(in_array($status, BiddingEvent::STATUSES, true), fn ($q) => $q->where('status', $status))
            ->latest('id')->paginate(15)->withQueryString()
            ->through(fn (BiddingEvent $e) => $this->eventSummary($e));

        $eligible = PurchaseRequest::where('team_id', $team->id)->where('status', PurchaseRequest::APPROVED)
            ->whereDoesntHave('purchaseOrder')
            ->whereDoesntHave('biddingEvents', fn ($q) => $q->where('status', '!=', BiddingEvent::CANCELLED))
            ->with('product:id,name,sku,unit')->orderBy('id')->get()
            ->map(fn (PurchaseRequest $pr) => [
                'id' => $pr->id, 'request_number' => $pr->request_number, 'quantity' => $pr->quantity, 'unit' => $pr->unit ?: ($pr->product?->unit ?: 'pcs'),
                'name' => $pr->product?->name ?? $pr->product_description ?? '—', 'sku' => $pr->product?->sku,
            ]);

        return Inertia::render('Procurement/Bidding/Index', [
            'events' => $events,
            'eligibleRequests' => $eligible,
            'suppliers' => $this->activeSuppliers($team),
            'statuses' => BiddingEvent::STATUSES,
            'filters' => ['status' => $status],
            'isAdmin' => $request->user()->isAdmin(),
            'minSuppliers' => BiddingWorkflow::MIN_SUPPLIERS,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $team = $this->team($request);
        $data = $request->validate([
            'purchase_request_id' => ['required', 'integer', Rule::exists('purchase_requests', 'id')->where('team_id', $team->id)],
            'supplier_ids' => ['required', 'array', 'min:1'],
            'supplier_ids.*' => ['integer', 'distinct'],
            'submission_deadline' => ['required', 'date', 'after:now'],
            'rfq_notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $pr = PurchaseRequest::where('team_id', $team->id)->findOrFail($data['purchase_request_id']);
        $event = null;

        $response = $this->runWorkflow(function () use ($team, $request, $pr, $data, &$event) {
            $event = $this->workflow->createEvent($team, $request->user(), $pr, $data);

            return "{$event->reference} created. Review the invited suppliers, then issue the RFQ.";
        });

        return $event ? redirect("/{$team->slug}/procurement/bidding/{$event->id}")->with('success', session('success')) : $response;
    }

    public function show(Request $request): Response
    {
        $team = $this->team($request);
        $event = $this->event($request);
        $event->load(['purchaseRequest.product:id,name,sku,unit', 'purchaseRequest.requestedBy:id,name', 'invitations.supplier:id,name,email,phone,contact_person', 'createdBy:id,name', 'awardedBy:id,name', 'purchaseOrder:id,po_number,status', 'closedBy:id,name']);
        $pr = $event->purchaseRequest;
        $comparison = $this->workflow->comparison($event);
        $bidSupplierIds = $event->bids()->pluck('supplier_id')->all();

        $bidIds = $event->bids()->pluck('id');
        $history = AuditLog::where(fn ($q) => $q
            ->where(fn ($e) => $e->where('subject_type', class_basename($event))->where('subject_id', $event->id))
            ->orWhere(fn ($b) => $b->where('subject_type', class_basename(SupplierBid::class))->whereIn('subject_id', $bidIds)))
            ->with('user:id,name')->orderBy('id')->get()
            ->map(fn ($log) => ['at' => $log->created_at?->toIso8601String(), 'event' => $log->event, 'description' => $log->description, 'by' => $log->user?->name, 'metadata' => $log->metadata]);

        return Inertia::render('Procurement/Bidding/Show', [
            'event' => $this->eventSummary($event) + [
                'rfq_notes' => $event->rfq_notes, 'award_remarks' => $event->award_remarks, 'cancel_reason' => $event->cancel_reason,
                'awarded_by' => $event->awardedBy?->name, 'created_by' => $event->createdBy?->name,
                'closed_at' => $event->closed_at?->toIso8601String(), 'closed_by' => $event->closedBy?->name,
                'rfq_issued_at' => $event->rfq_issued_at?->toIso8601String(),
                'purchase_order' => $event->purchaseOrder ? ['id' => $event->purchaseOrder->id, 'po_number' => $event->purchaseOrder->po_number, 'status' => $event->purchaseOrder->status] : null,
                'request' => ['request_number' => $pr->request_number, 'requested_by' => $pr->requestedBy?->name, 'justification' => $pr->justification],
            ],
            'invitations' => $event->invitations->map(fn ($i) => [
                'supplier_id' => $i->supplier_id, 'name' => $i->supplier->name, 'email' => $i->supplier->email, 'phone' => $i->supplier->phone,
                'contact_person' => $i->supplier->contact_person, 'rfq_sent_at' => $i->rfq_sent_at?->toIso8601String(), 'has_bid' => in_array($i->supplier_id, $bidSupplierIds, true),
            ])->values(),
            'comparison' => $comparison,
            'availableSuppliers' => $this->activeSuppliers($team)->reject(fn ($s) => $event->invitations->contains('supplier_id', $s['id']))->values(),
            'history' => $history,
            'isAdmin' => $request->user()->isAdmin(),
            'minSuppliers' => BiddingWorkflow::MIN_SUPPLIERS,
        ]);
    }

    public function invite(Request $request): RedirectResponse
    {
        $event = $this->event($request);
        // A checklist sends supplier_ids[]; a single supplier_id is still accepted.
        $data = $request->validate([
            'supplier_ids' => ['required_without:supplier_id', 'array', 'min:1', 'max:100'],
            'supplier_ids.*' => ['integer', 'distinct'],
            'supplier_id' => ['required_without:supplier_ids', 'integer'],
        ], [
            'supplier_ids.required_without' => 'Tick at least one supplier to invite.',
            'supplier_ids.min' => 'Tick at least one supplier to invite.',
        ]);
        $ids = $data['supplier_ids'] ?? [(int) $data['supplier_id']];

        return $this->runWorkflow(function () use ($event, $request, $ids) {
            $this->workflow->invite($event, $request->user(), $ids);

            return count($ids) === 1 ? 'Supplier invited.' : count($ids).' suppliers invited.';
        });
    }

    public function uninvite(Request $request): RedirectResponse
    {
        $event = $this->event($request);

        return $this->runWorkflow(function () use ($event, $request) {
            $this->workflow->uninvite($event, $request->user(), (int) $request->route('supplier'));

            return 'Supplier removed from the invitation list.';
        });
    }

    public function issue(Request $request): RedirectResponse
    {
        $event = $this->event($request);

        return $this->runWorkflow(function () use ($event, $request) {
            $this->workflow->issue($event, $request->user());

            return "{$event->reference} is open. Download the RFQ and send it to the invited suppliers.";
        });
    }

    public function close(Request $request): RedirectResponse
    {
        $event = $this->event($request);

        return $this->runWorkflow(function () use ($event, $request) {
            $this->workflow->close($event, $request->user());

            return "{$event->reference} is closed. Compare the quotations; the owner can now award it.";
        });
    }

    public function cancel(Request $request): RedirectResponse
    {
        $event = $this->event($request);
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);

        return $this->runWorkflow(function () use ($event, $request, $data) {
            $this->workflow->cancel($event, $request->user(), $data['reason']);

            return "{$event->reference} cancelled. The purchase request can be sent to bidding again.";
        });
    }

    public function recordBid(Request $request): RedirectResponse
    {
        $event = $this->event($request);
        $data = $request->validate($this->bidRules($event, true));

        return $this->runWorkflow(function () use ($event, $request, $data) {
            $bid = $this->workflow->recordBid($event, $request->user(), $data, $request->file('document'));

            return "Quotation from {$bid->supplier->name} recorded.";
        });
    }

    /**
     * Read a supplier's quotation for "Record quotation": a document (PDF, PNG, JPG; OCR for scans)
     * or a CSV with the purchase order import columns. Returns the values to check; nothing is saved.
     */
    public function readQuotation(Request $request, BidQuotationReader $reader): JsonResponse
    {
        $event = $this->event($request);
        if ($event->status !== BiddingEvent::OPEN) {
            return response()->json(['success' => false, 'message' => 'Quotations can only be recorded while the bidding is open.'], 422);
        }

        $validator = Validator::make($request->all(), [
            'file' => ['required', 'file', 'max:'.BidQuotationReader::MAX_KB, 'extensions:pdf,png,jpg,jpeg,csv,txt'],
        ], [
            'file.required' => 'Choose the quotation document or CSV to read.',
            'file.max' => 'The file is larger than 25 MB. Please upload a smaller file.',
            'file.uploaded' => 'The file could not be uploaded. It may be larger than 25 MB.',
            'file.extensions' => 'Quotations can be read from PDF, PNG, JPG or CSV files.',
        ]);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => $validator->errors()->first()], 422);
        }

        $file = $request->file('file');
        if (! app()->runningUnitTests()) {
            @set_time_limit(300); // OCR of a scanned document can take a while
        }

        try {
            $result = $reader->read($file->getRealPath() ?: $file->getPathname(), $file->getClientOriginalName(), $event);
        } catch (ExtractionException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            Log::error('Bidding quotation could not be read: '.$e->getMessage(), ['exception' => $e]);

            return response()->json(['success' => false, 'message' => 'The file could not be read. Please check it and try again.'], 500);
        }

        return response()->json(['success' => true, 'file_name' => mb_substr($file->getClientOriginalName(), 0, 200)] + $result);
    }

    public function reviseBid(Request $request): RedirectResponse
    {
        $event = $this->event($request);
        $bid = SupplierBid::where('bidding_event_id', $event->id)->findOrFail((int) $request->route('bid'));
        $data = $request->validate($this->bidRules($event, false) + ['reason' => ['required', 'string', 'max:500']]);

        return $this->runWorkflow(function () use ($bid, $request, $data) {
            $bid = $this->workflow->reviseBid($bid, $request->user(), $data, $data['reason'], $request->file('document'));

            return "Quotation from {$bid->supplier->name} revised (revision {$bid->revision}).";
        });
    }

    /** Owner decision. Admin only; the service enforces it again. */
    public function award(Request $request): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403, 'Only the owner or an administrator can award a bidding.');
        $event = $this->event($request);
        $data = $request->validate(['bid_id' => ['required', 'integer'], 'remarks' => ['nullable', 'string', 'max:1000']]);

        return $this->runWorkflow(function () use ($event, $request, $data) {
            $awarded = $this->workflow->award($event, $request->user(), (int) $data['bid_id'], $data['remarks'] ?? null);

            return "{$event->reference} awarded to {$awarded->awardedBid->supplier->name}. Draft purchase order {$awarded->purchaseOrder->po_number} created for staff to review and send.";
        });
    }

    /**
     * Archive a bidding event (administrators only, enforced by the admin_delete route middleware and again here).
     * An awarded event is kept: its purchase order depends on it. The event and its quotations go to the Recycle Bin.
     */
    public function destroy(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->isAdmin(), 403, 'Only administrators can delete records.');
        // Same rule as the rest of the system: the administrator must type YES to confirm.
        $request->validate(['confirmation' => ['required', 'string', Rule::in(['YES'])]]);
        $team = $this->team($request);
        $event = $this->event($request);

        $error = DB::transaction(function () use (&$event, $request) {
            $event = BiddingEvent::lockForUpdate()->findOrFail($event->id);
            if ($event->status === BiddingEvent::AWARDED || $event->purchase_order_id) {
                return "{$event->reference} cannot be deleted: it was awarded and its purchase order is linked to it. Awarded biddings are kept for the audit trail.";
            }
            AuditLogger::record('bidding.deleted', "{$event->reference} ({$event->status}) deleted by {$request->user()->name}; its {$event->bids()->count()} quotation(s) are archived with it.", $event,
                ['status' => $event->status, 'purchase_request_id' => $event->purchase_request_id], $request->user());
            $event->delete();

            return null;
        });

        $index = redirect("/{$team->slug}/procurement/bidding");

        return $error ? $index->withErrors(['error' => $error]) : $index->with('success', "{$event->reference} deleted. The purchase request can be sent to bidding again.");
    }

    public function rfq(Request $request): HttpResponse
    {
        $event = $this->event($request);
        abort_if($event->status === BiddingEvent::DRAFT, 422, 'Issue the RFQ before downloading it.');

        return response(app(DocumentPdf::class)->rfq($event), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="RFQ-'.$event->reference.'.pdf"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function document(Request $request): StreamedResponse
    {
        $event = $this->event($request);
        $bid = SupplierBid::where('bidding_event_id', $event->id)->findOrFail((int) $request->route('bid'));
        abort_unless($bid->document_path && Storage::disk('local')->exists($bid->document_path), 404);

        return Storage::disk('local')->download($bid->document_path, $bid->document_name ?: 'quotation');
    }

    // ─────────────────────────────── helpers ───────────────────────────────

    /** @return array<string, mixed> */
    private function bidRules(BiddingEvent $event, bool $creating): array
    {
        return [
            'supplier_id' => $creating ? ['required', 'integer'] : ['sometimes', 'integer'],
            'unit_price' => ['required', 'numeric', 'gt:0', 'max:99999999.99'],
            'quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
            'lead_time_days' => ['required', 'integer', 'min:0', 'max:730'],
            'quotation_date' => ['required', 'date_format:Y-m-d'],
            'received_at' => ['nullable', 'date'],
            'valid_until' => ['nullable', 'date_format:Y-m-d'],
            'quotation_reference' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'document' => ['nullable', 'file', 'extensions:pdf,jpg,jpeg,png,doc,docx,xls,xlsx,csv,txt', 'max:25600'],
        ];
    }

    private function eventSummary(BiddingEvent $e): array
    {
        $pr = $e->purchaseRequest;

        return [
            'id' => $e->id, 'reference' => $e->reference, 'status' => $e->status,
            'submission_deadline' => $e->submission_deadline->toIso8601String(),
            'deadline_passed' => $e->submission_deadline->isPast(),
            'request_number' => $pr?->request_number,
            'item' => $pr ? ($pr->product?->name ?? $pr->product_description ?? '—') : null,
            'sku' => $pr?->product?->sku,
            'quantity' => $pr?->quantity, 'unit' => $pr ? ($pr->unit ?: ($pr->product?->unit ?: 'pcs')) : null,
            'invited_count' => $e->invitations_count ?? $e->invitations->count(),
            'bid_count' => $e->bids_count ?? $e->bids()->count(),
            'awarded_supplier' => $e->awardedBid?->supplier?->name,
            'awarded_at' => $e->awarded_at?->toIso8601String(),
            'po_number' => $e->purchaseOrder?->po_number,
        ];
    }

    private function activeSuppliers(Team $team)
    {
        return Supplier::where('team_id', $team->id)->where('status', 'Active')->orderBy('name')->get(['id', 'name', 'email'])
            ->map(fn ($s) => ['id' => $s->id, 'name' => $s->name, 'email' => $s->email])->values();
    }

    private function event(Request $request): BiddingEvent
    {
        return BiddingEvent::where('team_id', $this->team($request)->id)->findOrFail((int) $request->route('biddingEvent'));
    }

    private function team(Request $request): Team
    {
        $param = $request->route('current_team');
        $team = $param instanceof Team ? $param : (is_string($param) && $param !== '' ? Team::where('slug', $param)->first() : null);
        $team ??= $request->user()?->currentTeam;
        abort_if(! $team, 403, 'No active team selected.');

        return $team;
    }
}
