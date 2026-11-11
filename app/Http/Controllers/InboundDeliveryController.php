<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Delivery;
use App\Models\InboundDelivery;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Team;
use App\Models\User;
use App\Services\Procurement\InboundReceiving;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Inbound P.O. deliveries on the Deliveries page: record (with inspection), view, correct, and
 * delete (= cancel, administrators only). Validation and team scoping only; the rules are in
 * InboundReceiving.
 *
 * Admin and staff may record, view, inspect and correct. Only administrators may delete: the
 * route has the `admin_delete` middleware and this controller and the service check again.
 */
class InboundDeliveryController extends Controller
{
    public function __construct(private InboundReceiving $receiving) {}

    public function store(Request $request): RedirectResponse
    {
        $team = $this->team($request);
        $data = $request->validate([
            'po_number' => ['required', 'string', 'max:100'],
            'delivery_date' => ['required', 'date', 'before_or_equal:today'],
            'supplier_document_number' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'submission_key' => ['nullable', 'string', 'max:64'],
            ...$this->lineRules('purchase_order_id'),
        ], $this->messages());

        return $this->runWorkflow(function () use ($team, $request, $data) {
            $header = $this->receiving->confirm($team, $request->user(), $data);
            $accepted = (int) $header->items->sum('accepted_quantity');
            $created = Product::where('team_id', $team->id)->where('created_at', '>=', $header->created_at)
                ->whereIn('id', PurchaseOrder::whereIn('id', $header->items->pluck('purchase_order_id'))->pluck('product_id'))
                ->whereNotNull('missing_fields')->pluck('sku');

            return "Delivery {$header->delivery_number} confirmed. {$accepted} accepted unit(s) added to inventory."
                .($created->isNotEmpty() ? ' New product(s) '.$created->implode(', ').' are listed in Inventory as Incomplete: enter a selling price to make them sellable.' : '');
        });
    }

    /** Full details of one delivery (JSON, for the View and Correct dialogs). */
    public function show(Request $request): JsonResponse
    {
        $header = $this->find($request);
        $header->load(['supplier:id,name', 'confirmedBy:id,name', 'cancelledBy:id,name',
            'items' => fn ($q) => $q->orderBy('id'),
            'items.purchaseOrder:id,po_number,po_date,quantity,item_sku,description,unit_cost,unit_of_measure,total_cost,status,product_id',
            'items.purchaseOrder.product:id,sku,name', 'items.inspectedBy:id,name', 'items.invoices:id,delivery_id,invoice_number,status']);
        $progress = $this->receiving->progress($header->items->pluck('purchase_order_id')->filter()->unique()->values()->all());
        $poDate = $header->items->map(fn ($d) => $d->purchaseOrder?->po_date)->filter()->min();

        $history = AuditLog::where(fn ($q) => $q->where(fn ($w) => $w->where('subject_type', 'InboundDelivery')->where('subject_id', $header->id))
            ->orWhere(fn ($w) => $w->where('subject_type', 'Delivery')->whereIn('subject_id', $header->items->pluck('id'))))
            ->with('user:id,name')->orderBy('created_at')->orderBy('id')->limit(100)
            ->get(['id', 'user_id', 'event', 'description', 'metadata', 'created_at']);

        return response()->json([
            'id' => $header->id,
            'delivery_number' => $header->delivery_number,
            'delivery_date' => $header->delivery_date->toDateString(),
            'purchase_order_number' => $header->purchase_order_number,
            'order_date' => $poDate?->toDateString(),
            'supplier' => $header->supplier?->name,
            'supplier_document_number' => $header->supplier_document_number,
            'notes' => $header->notes,
            'status' => $header->status,
            'confirmed_by' => $header->confirmedBy?->name,
            'confirmed_at' => $header->confirmed_at?->toIso8601String(),
            'cancelled_by' => $header->cancelledBy?->name,
            'cancelled_at' => $header->cancelled_at?->toIso8601String(),
            'cancellation_reason' => $header->cancellation_reason,
            'can_delete' => (bool) $request->user()?->isAdmin() && ! $header->isCancelled(),
            'items' => $header->items->map(function (Delivery $d) use ($progress) {
                $po = $d->purchaseOrder;
                $p = $po ? ($progress[$po->id] ?? null) : null;
                // Open on the order without this delivery's own units (the most it may be corrected to).
                $own = $d->status === 'Cancelled' ? 0 : ($d->inspection_result === null ? (int) $d->quantity : (int) $d->accepted_quantity);

                return [
                    'id' => $d->id,
                    'purchase_order_id' => $po?->id,
                    'item_sku' => $po?->item_sku,
                    'description' => $d->description,
                    'ordered_quantity' => $po ? (int) $po->quantity : null,
                    'previously_received' => $p ? max(0, $p['received'] - ($d->status === 'Cancelled' ? 0 : (int) $d->quantity)) : null,
                    'remaining' => $p ? $p['remaining'] + $own : null,
                    'received_quantity' => (int) $d->quantity,
                    'accepted_quantity' => $d->inspection_result === null ? null : (int) $d->accepted_quantity,
                    'rejected_quantity' => $d->inspection_result === null ? null : (int) $d->rejected_quantity,
                    'unit_cost' => $po?->unit_cost !== null ? (float) $po->unit_cost : null,
                    'unit_of_measure' => $po?->unit_of_measure ?? $d->unit,
                    'extended_cost' => $po?->total_cost !== null ? (float) $po->total_cost : null,
                    'quality_status' => $d->qualityStatus(),
                    'inspection_remarks' => (int) $d->rejected_quantity > 0 ? ($d->rejection_reason ?? $d->inspection_remarks) : $d->inspection_remarks,
                    'rejection_category' => $d->rejection_category,
                    'resolution_status' => $d->resolution_status,
                    'over_receipt_reason' => $d->over_receipt_reason,
                    'inspected_on' => $d->inspected_on?->toDateString(),
                    'inspected_by' => $d->inspectedBy?->name,
                    'posted_quantity' => (int) $d->posted_quantity,
                    'posted_at' => $d->posted_at?->toIso8601String(),
                    'status' => $d->status,
                    'product' => $po?->product ? ['id' => $po->product->id, 'sku' => $po->product->sku, 'name' => $po->product->name] : null,
                    // Inspected before stock was added automatically: the manual fallback still applies.
                    'can_post' => $d->inspection_result !== null && ! $d->posted_at && (int) $d->accepted_quantity > 0 && $d->status !== 'Cancelled',
                ];
            })->values(),
            'history' => $history->map(fn (AuditLog $log) => [
                'id' => $log->id,
                'event' => $log->event,
                'description' => $log->description,
                'by' => $log->user?->name ?? 'System',
                'at' => $log->created_at?->toIso8601String(),
                'changes' => $log->metadata['changes'] ?? null,
                'reason' => $log->metadata['reason'] ?? null,
            ])->values(),
        ]);
    }

    /** Correct the delivery date, supplier document, quantities and inspection (admin and staff). */
    public function update(Request $request): RedirectResponse
    {
        $header = $this->find($request);
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
            'delivery_date' => ['nullable', 'date', 'before_or_equal:today'],
            'supplier_document_number' => ['nullable', 'string', 'max:100'],
            ...$this->lineRules('delivery_id', false),
        ], $this->messages() + ['reason.required' => 'Give the reason for the correction.']);

        return $this->runWorkflow(function () use ($header, $request, $data) {
            $this->receiving->correct($header, $request->user(), $data);

            return "Delivery {$header->delivery_number} corrected. Stock was adjusted by the difference only.";
        });
    }

    /** "Delete" keeps the record as Cancelled (audit trail) and takes its stock back out. Admin only. */
    public function destroy(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->isAdmin(), 403, 'Only administrators can delete delivery records.');
        $header = $this->find($request);
        $data = $request->validate([
            'confirmation' => ['required', Rule::in(['YES'])],
            'reason' => ['required', 'string', 'max:500'],
        ], ['confirmation.*' => 'Type YES to confirm.', 'reason.required' => 'Give the reason for deleting this delivery.']);

        return $this->runWorkflow(function () use ($header, $request, $data) {
            $this->receiving->cancel($header, $request->user(), trim($data['reason']));

            return "Delivery {$header->delivery_number} cancelled. Its stock was taken back out and the purchase order was updated.";
        });
    }

    /** @return array<string, mixed> */
    private function lineRules(string $idField, bool $required = true): array
    {
        return [
            'lines' => [$required ? 'required' : 'nullable', 'array', $required ? 'min:1' : 'min:0', 'max:100'],
            "lines.*.{$idField}" => ['required', 'integer'],
            'lines.*.received_quantity' => ['required', 'integer', 'min:0', 'max:1000000'],
            'lines.*.accepted_quantity' => ['required', 'integer', 'min:0', 'max:1000000'],
            'lines.*.rejected_quantity' => ['required', 'integer', 'min:0', 'max:1000000'],
            'lines.*.inspection_remarks' => ['nullable', 'string', 'max:1000'],
            'lines.*.rejection_category' => ['nullable', Rule::in(Delivery::REJECTION_CATEGORIES)],
            'lines.*.over_receipt_reason' => ['nullable', 'string', 'max:1000'],
            'lines.*.product_action' => ['nullable', Rule::in(['link', 'create'])],
            'lines.*.product_id' => ['nullable', 'integer'],
            'lines.*.category' => ['nullable', 'string', 'max:100'],
            // "Quality checked": every received product must be inspected before the delivery is confirmed.
            'lines.*.inspected' => ['nullable', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    private function messages(): array
    {
        return [
            'po_number.required' => 'Select a purchase order.',
            'delivery_date.before_or_equal' => 'The delivery date cannot be in the future.',
            'lines.required' => 'Select a purchase order with products to receive.',
            'lines.*.received_quantity.required' => 'Enter the received quantity of every product (0 if it did not arrive).',
            'lines.*.received_quantity.min' => 'Received quantity cannot be negative.',
            'lines.*.accepted_quantity.required' => 'Enter the accepted quantity.',
            'lines.*.accepted_quantity.min' => 'Accepted quantity cannot be negative.',
            'lines.*.rejected_quantity.required' => 'Enter the rejected quantity (0 if none).',
            'lines.*.rejected_quantity.min' => 'Rejected quantity cannot be negative.',
            'lines.*.*.integer' => 'Quantities must be whole numbers.',
        ];
    }

    private function team(Request $request): Team
    {
        $param = $request->route('current_team');
        $team = $param instanceof Team ? $param : (is_string($param) && $param !== '' ? Team::where('slug', $param)->first() : null);
        $team ??= $request->user()?->currentTeam;
        abort_if(! $team, 403, 'No active team selected.');
        $this->assertNotDeliveryStaff($request->user());

        return $team;
    }

    /** Delivery drivers see only customer deliveries (same rule as the Deliveries page). */
    private function assertNotDeliveryStaff(?User $user): void
    {
        abort_if($user && ! $user->isAdmin() && ($user->role === 'staff_deliveries' || in_array('deliveries', $user->assignedModules(), true)), 403, 'Inbound deliveries are handled by procurement and inventory staff.');
    }

    private function find(Request $request): InboundDelivery
    {
        $id = $request->route('inboundDelivery');
        $id = $id instanceof InboundDelivery ? $id->getKey() : $id;
        abort_unless(is_numeric($id), 404);

        return InboundDelivery::where('team_id', $this->team($request)->id)->findOrFail((int) $id);
    }
}
