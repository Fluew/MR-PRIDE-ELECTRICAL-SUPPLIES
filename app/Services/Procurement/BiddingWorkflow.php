<?php

namespace App\Services\Procurement;

use App\Events\Procurement\PurchaseOrderCreated;
use App\Exceptions\WorkflowException;
use App\Models\BiddingEvent;
use App\Models\BiddingSupplier;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\Supplier;
use App\Models\SupplierBid;
use App\Models\Team;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Suppliers\SupplierEvaluator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Staff-managed bidding. Suppliers never use the system: staff send an RFQ, record the quotations that
 * come back, compare them, and only an administrator (the owner) awards one. The award drafts the
 * purchase order from the winning quotation. Nothing here invents a price, date or score.
 */
class BiddingWorkflow
{
    /** Competition needs at least two invited suppliers before the RFQ goes out. */
    public const MIN_SUPPLIERS = 2;

    // ───────────────────────────── Events ─────────────────────────────

    /** An approved request that has no purchase order and no live bidding event can go to bidding. */
    public function eligibility(PurchaseRequest $pr): ?string
    {
        if ($pr->status !== PurchaseRequest::APPROVED) {
            return "{$pr->request_number} is {$pr->status}; only an approved purchase request can go to bidding.";
        }
        if (PurchaseOrder::withTrashed()->where('purchase_request_id', $pr->id)->exists()) {
            return "{$pr->request_number} already has a purchase order.";
        }
        $live = BiddingEvent::where('purchase_request_id', $pr->id)->where('status', '!=', BiddingEvent::CANCELLED)->first();

        return $live ? "{$pr->request_number} already has bidding event {$live->reference}." : null;
    }

    /** @param  array{supplier_ids: list<int>, submission_deadline: string, rfq_notes?: ?string}  $data */
    public function createEvent(Team $team, User $user, PurchaseRequest $pr, array $data): BiddingEvent
    {
        return DB::transaction(function () use ($team, $user, $pr, $data) {
            $pr = PurchaseRequest::where('team_id', $team->id)->lockForUpdate()->findOrFail($pr->id);
            if ($reason = $this->eligibility($pr)) {
                throw new WorkflowException($reason, 'purchase_request_id');
            }
            $deadline = \Illuminate\Support\Carbon::parse($data['submission_deadline'])->setTimezone(config('app.timezone'));
            if ($deadline->isPast()) {
                throw new WorkflowException('The submission deadline must be in the future.', 'submission_deadline');
            }

            $event = BiddingEvent::create([
                'team_id' => $team->id,
                'purchase_request_id' => $pr->id,
                'reference' => $this->nextReference($team->id),
                'status' => BiddingEvent::DRAFT,
                'submission_deadline' => $deadline,
                'rfq_notes' => trim((string) ($data['rfq_notes'] ?? '')) ?: null,
                'created_by_id' => $user->id,
            ]);
            foreach (array_unique($data['supplier_ids'] ?? []) as $supplierId) {
                $this->addInvitation($event, (int) $supplierId, $user);
            }
            AuditLogger::record('bidding.created', "Bidding event {$event->reference} created from {$pr->request_number}.", $event,
                ['purchase_request' => $pr->request_number, 'suppliers' => $event->invitations()->count()], $user);

            return $event;
        });
    }

    public function invite(BiddingEvent $event, User $user, int $supplierId): void
    {
        DB::transaction(function () use ($event, $user, $supplierId) {
            $event = BiddingEvent::lockForUpdate()->findOrFail($event->id);
            if (! in_array($event->status, [BiddingEvent::DRAFT, BiddingEvent::OPEN], true)) {
                throw new WorkflowException("{$event->reference} is {$event->status}; suppliers can no longer be invited.");
            }
            $this->addInvitation($event, $supplierId, $user);
        });
    }

    public function uninvite(BiddingEvent $event, User $user, int $supplierId): void
    {
        DB::transaction(function () use ($event, $user, $supplierId) {
            $event = BiddingEvent::lockForUpdate()->findOrFail($event->id);
            if (! in_array($event->status, [BiddingEvent::DRAFT, BiddingEvent::OPEN], true)) {
                throw new WorkflowException("{$event->reference} is {$event->status}; the invitation list is locked.");
            }
            if (SupplierBid::where('bidding_event_id', $event->id)->where('supplier_id', $supplierId)->exists()) {
                throw new WorkflowException('This supplier already has a recorded quotation, so it cannot be removed from the invitation list.');
            }
            $invitation = BiddingSupplier::where('bidding_event_id', $event->id)->where('supplier_id', $supplierId)->firstOrFail();
            if ($event->status === BiddingEvent::OPEN && $event->invitations()->count() <= self::MIN_SUPPLIERS) {
                throw new WorkflowException('An open bidding needs at least '.self::MIN_SUPPLIERS.' invited suppliers.');
            }
            $invitation->delete();
            AuditLogger::record('bidding.supplier_removed', "Supplier removed from {$event->reference}.", $event, ['supplier_id' => $supplierId], $user);
        });
    }

    /** Draft → Open: the RFQ is ready to send to the invited suppliers. */
    public function issue(BiddingEvent $event, User $user): BiddingEvent
    {
        return DB::transaction(function () use ($event, $user) {
            $event = BiddingEvent::lockForUpdate()->findOrFail($event->id);
            if ($event->status !== BiddingEvent::DRAFT) {
                throw new WorkflowException("{$event->reference} is {$event->status}; only a draft can be issued.");
            }
            if ($event->invitations()->count() < self::MIN_SUPPLIERS) {
                throw new WorkflowException('Invite at least '.self::MIN_SUPPLIERS.' registered suppliers before issuing the RFQ.', 'suppliers');
            }
            if ($event->submission_deadline->isPast()) {
                throw new WorkflowException('The submission deadline has passed. Cancel this event and create a new one with a later deadline.', 'submission_deadline');
            }
            $event->update(['status' => BiddingEvent::OPEN, 'rfq_issued_at' => now()]);
            $event->invitations()->whereNull('rfq_sent_at')->update(['rfq_sent_at' => now()]);
            AuditLogger::record('bidding.issued', "{$event->reference} issued: RFQ sent to invited suppliers.", $event, [], $user);

            return $event;
        });
    }

    public function close(BiddingEvent $event, User $user): BiddingEvent
    {
        return DB::transaction(function () use ($event, $user) {
            $event = BiddingEvent::lockForUpdate()->findOrFail($event->id);
            if ($event->status !== BiddingEvent::OPEN) {
                throw new WorkflowException("{$event->reference} is {$event->status}; only an open bidding can be closed.");
            }
            $event->update(['status' => BiddingEvent::CLOSED, 'closed_at' => now(), 'closed_by_id' => $user->id]);
            AuditLogger::record('bidding.closed', "{$event->reference} closed to new quotations with {$event->bids()->count()} recorded.", $event, [], $user);

            return $event;
        });
    }

    public function cancel(BiddingEvent $event, User $user, string $reason): BiddingEvent
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new WorkflowException('Give the reason for cancelling this bidding.', 'reason');
        }

        return DB::transaction(function () use ($event, $user, $reason) {
            $event = BiddingEvent::lockForUpdate()->findOrFail($event->id);
            if ($event->isFinal()) {
                throw new WorkflowException("{$event->reference} is {$event->status} and cannot be cancelled.");
            }
            $event->update(['status' => BiddingEvent::CANCELLED, 'cancel_reason' => $reason]);
            AuditLogger::record('bidding.cancelled', "{$event->reference} cancelled: {$reason}", $event, [], $user);

            return $event;
        });
    }

    // ───────────────────────────── Quotations ─────────────────────────────

    /**
     * Record a quotation a supplier sent. Only for an invited supplier, once, and only while the bidding is
     * open. The "received" time is when it actually arrived: it cannot be after the deadline.
     *
     * @param  array{supplier_id: int, unit_price: float|string, quantity: int, lead_time_days: int, quotation_date: string, received_at?: ?string, valid_until?: ?string, quotation_reference?: ?string, notes?: ?string}  $data
     */
    public function recordBid(BiddingEvent $event, User $user, array $data, ?UploadedFile $document = null): SupplierBid
    {
        return DB::transaction(function () use ($event, $user, $data, $document) {
            $event = BiddingEvent::lockForUpdate()->findOrFail($event->id);
            $this->assertAcceptsBids($event);
            if (! BiddingSupplier::where('bidding_event_id', $event->id)->where('supplier_id', $data['supplier_id'])->exists()) {
                throw new WorkflowException('Only an invited supplier can have a quotation recorded for this bidding.', 'supplier_id');
            }
            if (SupplierBid::where('bidding_event_id', $event->id)->where('supplier_id', $data['supplier_id'])->exists()) {
                throw new WorkflowException('A quotation from this supplier is already recorded. Revise the existing one instead of adding another.', 'supplier_id');
            }
            $fields = $this->bidFields($event, $data);

            $bid = SupplierBid::create($fields + [
                'bidding_event_id' => $event->id,
                'supplier_id' => $data['supplier_id'],
                'status' => SupplierBid::SUBMITTED,
                'revision' => 1,
                'recorded_by_id' => $user->id,
            ] + $this->storeDocument($event, $document));
            AuditLogger::record('bidding.bid_recorded', "Quotation recorded for {$event->reference} from {$bid->supplier->name}.", $bid,
                ['unit_price' => (float) $bid->unit_price, 'quantity' => $bid->quantity, 'lead_time_days' => $bid->lead_time_days, 'received_at' => $bid->received_at->toIso8601String(), 'has_document' => (bool) $bid->document_path], $user);

            return $bid;
        });
    }

    /** A supplier changed its quotation: the same record is revised, with the reason and the old values kept in the audit trail. */
    public function reviseBid(SupplierBid $bid, User $user, array $data, string $reason, ?UploadedFile $document = null): SupplierBid
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new WorkflowException('Say why this quotation is being revised (for example, "supplier sent a lower price by email on 12 Nov").', 'reason');
        }

        return DB::transaction(function () use ($bid, $user, $data, $reason, $document) {
            $bid = SupplierBid::lockForUpdate()->findOrFail($bid->id);
            $event = BiddingEvent::lockForUpdate()->findOrFail($bid->bidding_event_id);
            $this->assertAcceptsBids($event);
            $data['supplier_id'] = $bid->supplier_id;
            $before = $bid->only(['unit_price', 'quantity', 'lead_time_days', 'quotation_date', 'received_at', 'valid_until', 'quotation_reference', 'document_name']);

            $update = $this->bidFields($event, $data) + ['revision' => $bid->revision + 1, 'recorded_by_id' => $user->id];
            if ($document) {
                if ($bid->document_path) {
                    Storage::disk('local')->delete($bid->document_path);
                }
                $update += $this->storeDocument($event, $document);
            }
            $bid->update($update);
            AuditLogger::record('bidding.bid_revised', "Quotation from {$bid->supplier->name} revised (revision {$bid->revision}) for {$event->reference}: {$reason}", $bid,
                ['before' => $before, 'after' => $bid->only(array_keys($before)), 'reason' => $reason], $user);

            return $bid;
        });
    }

    // ───────────────────────────── Comparison & award ─────────────────────────────

    /**
     * Side-by-side comparison of the recorded quotations. Informational only: nothing is awarded
     * automatically. Supplier scores are shown only when the Supplier module has a complete evaluation.
     *
     * @return array{requested: array, rows: list<array>, lowest_price: ?float, shortest_lead_time: ?int}
     */
    public function comparison(BiddingEvent $event): array
    {
        $event->loadMissing(['purchaseRequest.product:id,name,sku,unit', 'bids.supplier:id,name']);
        $pr = $event->purchaseRequest;
        $evaluations = app(SupplierEvaluator::class)->forTeam($event->team_id);

        $bids = $event->bids;
        $lowest = $bids->isEmpty() ? null : (float) $bids->min('unit_price');
        $fastest = $bids->isEmpty() ? null : (int) $bids->min('lead_time_days');

        $rows = $bids->map(function (SupplierBid $bid) use ($pr, $lowest, $fastest, $evaluations) {
            $evaluation = $evaluations[$bid->supplier_id] ?? null;

            return [
                'id' => $bid->id,
                'supplier' => ['id' => $bid->supplier_id, 'name' => $bid->supplier->name],
                'unit_price' => (float) $bid->unit_price,
                'quantity' => $bid->quantity,
                'total' => $bid->total(),
                'lead_time_days' => $bid->lead_time_days,
                'quotation_date' => $bid->quotation_date->toDateString(),
                'received_at' => $bid->received_at->toIso8601String(),
                'valid_until' => $bid->valid_until?->toDateString(),
                'expired' => $bid->valid_until?->isPast() && ! $bid->valid_until->isToday(),
                'quotation_reference' => $bid->quotation_reference,
                'notes' => $bid->notes,
                'has_document' => (bool) $bid->document_path,
                'document_name' => $bid->document_name,
                'revision' => $bid->revision,
                'status' => $bid->status,
                'is_lowest_price' => $lowest !== null && (float) $bid->unit_price === $lowest,
                'is_shortest_lead_time' => $fastest !== null && $bid->lead_time_days === $fastest,
                'price_above_lowest_pct' => $lowest ? round(((float) $bid->unit_price - $lowest) / $lowest * 100, 1) : null,
                'quantity_short' => max(0, $pr->quantity - $bid->quantity),
                'supplier_score' => ($evaluation['complete'] ?? false) ? $evaluation['score'] : null,
                'supplier_classification' => ($evaluation['complete'] ?? false) ? ($evaluation['classification'] ?? null) : null,
            ];
        })->sortBy([['unit_price', 'asc'], ['lead_time_days', 'asc']])->values()->all();

        return [
            'requested' => ['quantity' => $pr->quantity, 'unit' => $pr->unit ?: ($pr->product?->unit ?: 'pcs'), 'estimated_cost' => (float) $pr->estimated_cost],
            'rows' => $rows,
            'lowest_price' => $lowest,
            'shortest_lead_time' => $fastest,
        ];
    }

    /**
     * Owner decision. Only an administrator can award; staff cannot. The award is final: it records who and
     * when, keeps a snapshot of every quotation as it stood, marks the bids, and drafts the purchase order
     * from the winning quotation (price, quantity and lead time are carried over as quoted).
     */
    public function award(BiddingEvent $event, User $admin, int $bidId, ?string $remarks): BiddingEvent
    {
        if (! $admin->isAdmin()) {
            throw new WorkflowException('Only the owner or an administrator can award a bidding.');
        }
        $remarks = trim((string) $remarks) ?: null;

        $event = DB::transaction(function () use ($event, $admin, $bidId, $remarks) {
            $event = BiddingEvent::lockForUpdate()->findOrFail($event->id);
            if ($event->status !== BiddingEvent::CLOSED) {
                throw new WorkflowException("{$event->reference} is {$event->status}; close the bidding to new quotations before awarding it.", 'bid_id');
            }
            $pr = PurchaseRequest::lockForUpdate()->findOrFail($event->purchase_request_id);
            if ($pr->status !== PurchaseRequest::APPROVED || PurchaseOrder::withTrashed()->where('purchase_request_id', $pr->id)->exists()) {
                throw new WorkflowException("{$pr->request_number} is no longer waiting for an order, so it cannot be awarded.");
            }
            $bid = SupplierBid::where('bidding_event_id', $event->id)->with('supplier')->find($bidId)
                ?? throw new WorkflowException('Choose one of the quotations recorded for this bidding.', 'bid_id');
            if ($bid->supplier->status !== 'Active') {
                throw new WorkflowException("{$bid->supplier->name} is not an active supplier.", 'bid_id');
            }
            if ($bid->valid_until && $bid->valid_until->lt(today())) {
                throw new WorkflowException("The quotation from {$bid->supplier->name} expired on {$bid->valid_until->format('M j, Y')}. Ask the supplier to confirm it and revise the quotation, or choose another.", 'bid_id');
            }
            $bidCount = $event->bids()->count();
            if ($bidCount < self::MIN_SUPPLIERS && ! $remarks) {
                throw new WorkflowException('Only one quotation was received. Explain in the remarks why it is being accepted.', 'remarks');
            }
            if ($bid->quantity < $pr->quantity && ! $remarks) {
                throw new WorkflowException("This supplier quotes {$bid->quantity} of the {$pr->quantity} requested. Explain in the remarks how the shortfall will be handled.", 'remarks');
            }

            $snapshot = $this->comparison($event)['rows'];
            SupplierBid::where('bidding_event_id', $event->id)->update(['status' => SupplierBid::NOT_AWARDED]);
            $bid->update(['status' => SupplierBid::AWARDED]);
            $po = $this->draftPurchaseOrder($event, $pr, $bid, $admin);
            $event->update([
                'status' => BiddingEvent::AWARDED, 'awarded_bid_id' => $bid->id, 'awarded_by_id' => $admin->id, 'awarded_at' => now(),
                'award_remarks' => $remarks, 'award_snapshot' => $snapshot, 'purchase_order_id' => $po->id,
            ]);
            $pr->update(['status' => PurchaseRequest::CONVERTED, 'ordered_at' => now(), 'supplier_id' => $bid->supplier_id]);
            AuditLogger::record('bidding.awarded', "{$event->reference} awarded to {$bid->supplier->name} by {$admin->name}; draft purchase order {$po->po_number} created.", $event,
                ['bid_id' => $bid->id, 'unit_price' => (float) $bid->unit_price, 'quantity' => $bid->quantity, 'bids_compared' => $bidCount, 'remarks' => $remarks], $admin);
            PurchaseOrderCreated::dispatch($po, $admin, true);

            return $event;
        });

        return $event->fresh();
    }

    /**
     * The quoted lead time counts from the day the order is sent to the supplier, not from the day it was
     * awarded. When a purchase order drafted from a bidding is sent and staff have left its promised delivery
     * date as the award set it, the date moves to send date + the winner's quoted lead time. A date staff
     * changed on purpose is respected.
     */
    public function alignPromisedDateToSend(PurchaseOrder $po): void
    {
        $event = BiddingEvent::where('purchase_order_id', $po->id)->with('awardedBid')->first();
        if (! $event || ! $event->awardedBid || ! $event->awarded_at || ! $po->promised_delivery_date) {
            return;
        }
        $lead = (int) $event->awardedBid->lead_time_days;
        $awardDefault = $event->awarded_at->copy()->startOfDay()->addDays($lead)->toDateString();
        if ($po->promised_delivery_date->toDateString() !== $awardDefault) {
            return;
        }
        $aligned = today()->addDays($lead)->toDateString();
        if ($aligned !== $awardDefault) {
            $po->update(['promised_delivery_date' => $aligned]);
            AuditLogger::record('purchase_order.promised_date_aligned', "Promised delivery date of {$po->po_number} set to {$aligned}: {$lead} quoted days from the day it was sent ({$event->reference}).", $po,
                ['from' => $awardDefault, 'to' => $aligned, 'quoted_lead_days' => $lead], null);
        }
    }

    // ───────────────────────────── Helpers ─────────────────────────────

    private function addInvitation(BiddingEvent $event, int $supplierId, User $user): void
    {
        $supplier = Supplier::where('team_id', $event->team_id)->find($supplierId);
        if (! $supplier) {
            throw new WorkflowException('Choose a registered supplier.', 'supplier_ids');
        }
        if ($supplier->status !== 'Active') {
            throw new WorkflowException("{$supplier->name} is not an active supplier and cannot be invited.", 'supplier_ids');
        }
        if (BiddingSupplier::where('bidding_event_id', $event->id)->where('supplier_id', $supplierId)->exists()) {
            throw new WorkflowException("{$supplier->name} is already invited.", 'supplier_ids');
        }
        BiddingSupplier::create(['bidding_event_id' => $event->id, 'supplier_id' => $supplierId, 'invited_by_id' => $user->id, 'rfq_sent_at' => $event->status === BiddingEvent::OPEN ? now() : null]);
    }

    private function assertAcceptsBids(BiddingEvent $event): void
    {
        if (! $event->acceptsBids()) {
            throw new WorkflowException("{$event->reference} is {$event->status}; quotations can only be recorded while it is open.");
        }
    }

    /** Validated, normalised bid columns. Late quotations (received after the deadline) are refused. */
    private function bidFields(BiddingEvent $event, array $data): array
    {
        // Convert to the app time zone before saving: a value sent with an offset (e.g. +08:00) must be stored as the same instant.
        $received = ! empty($data['received_at']) ? \Illuminate\Support\Carbon::parse($data['received_at'])->setTimezone(config('app.timezone')) : now();
        if ($received->gt(now()->addMinute())) {
            throw new WorkflowException('The date and time received cannot be in the future.', 'received_at');
        }
        if ($received->gt($event->submission_deadline)) {
            throw new WorkflowException('This quotation was received after the submission deadline ('.$event->submission_deadline->format('M j, Y g:i A').') and cannot be accepted.', 'received_at');
        }
        $quotationDate = \Illuminate\Support\Carbon::parse($data['quotation_date'])->startOfDay();
        // Allow one day of slack: it is already tomorrow in time zones ahead of the server's UTC.
        if ($quotationDate->gt(today()->addDay())) {
            throw new WorkflowException('The quotation date cannot be in the future.', 'quotation_date');
        }
        $validUntil = ! empty($data['valid_until']) ? \Illuminate\Support\Carbon::parse($data['valid_until'])->startOfDay() : null;
        if ($validUntil && $validUntil->lt($quotationDate)) {
            throw new WorkflowException('"Valid until" cannot be before the quotation date.', 'valid_until');
        }

        return [
            'unit_price' => round((float) $data['unit_price'], 2),
            'quantity' => (int) $data['quantity'],
            'lead_time_days' => (int) $data['lead_time_days'],
            'quotation_date' => $quotationDate->toDateString(),
            'received_at' => $received,
            'valid_until' => $validUntil?->toDateString(),
            'quotation_reference' => trim((string) ($data['quotation_reference'] ?? '')) ?: null,
            'notes' => trim((string) ($data['notes'] ?? '')) ?: null,
        ];
    }

    /** @return array{document_path?: string, document_name?: string} */
    private function storeDocument(BiddingEvent $event, ?UploadedFile $file): array
    {
        if (! $file) {
            return [];
        }

        return [
            'document_path' => $file->storeAs("bidding/{$event->team_id}/{$event->id}", bin2hex(random_bytes(8)).'.'.($file->guessExtension() ?: 'bin'), 'local'),
            'document_name' => mb_substr($file->getClientOriginalName(), 0, 255),
        ];
    }

    private function nextReference(int $teamId): string
    {
        $prefix = 'BID-'.now()->format('Y').'-';
        $last = BiddingEvent::withTrashed()->where('team_id', $teamId)->where('reference', 'like', $prefix.'%')->lockForUpdate()->orderByDesc('reference')->value('reference');
        $next = $last ? ((int) substr($last, strlen($prefix))) + 1 : 1;

        return $prefix.str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }

    /** The draft purchase order for the awarded quotation, in the same shape the approval flow creates. */
    private function draftPurchaseOrder(BiddingEvent $event, PurchaseRequest $pr, SupplierBid $bid, User $by): PurchaseOrder
    {
        $product = $pr->isUnlisted() ? null : ($pr->product ?? throw new WorkflowException('The product of this request no longer exists.'));
        $unit = $product?->unit ?: ($pr->unit ?: 'pcs');
        $notes = "Awarded from bidding {$event->reference} to {$bid->supplier->name} at {$bid->unit_price} per {$unit}"
            .($bid->quotation_reference ? " (quotation {$bid->quotation_reference})" : '').'. Price, quantity and lead time are as quoted.'
            .($bid->quantity < $pr->quantity ? " Quantity quoted {$bid->quantity} of {$pr->quantity} requested." : '');

        $po = PurchaseOrder::create([
            'team_id' => $pr->team_id,
            'purchase_request_id' => $pr->id,
            'po_date' => today(),
            'po_number' => PurchaseOrder::generatePoNumber($pr->team_id),
            'quantity' => $bid->quantity,
            'product_id' => $product?->id,
            'item_sku' => $product?->sku,
            'description' => $product?->name ?? $pr->product_description,
            'unit_of_measure' => strtoupper($unit),
            'unit_cost' => $bid->unit_price,
            'total_cost' => $bid->total(),
            'supplier_id' => $bid->supplier_id,
            'promised_delivery_date' => today()->addDays($bid->lead_time_days),
            'prepared_by_id' => $by->id,
            'status' => PurchaseOrder::DRAFT,
            'notes' => $notes,
        ]);
        AuditLogger::record('purchase_order.created', "Draft purchase order {$po->po_number} created from the awarded quotation of {$event->reference}.", $po,
            ['purchase_request' => $pr->request_number, 'bidding_event' => $event->reference, 'bid_id' => $bid->id], $by);

        return $po;
    }
}
