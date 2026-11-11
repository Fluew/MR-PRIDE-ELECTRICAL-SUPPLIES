<?php

namespace App\Services\Procurement;

use App\Exceptions\WorkflowException;
use App\Models\Delivery;
use App\Models\InboundDelivery;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\StockMovement;
use App\Models\SupplierProductCost;
use App\Models\SupplierSkuMapping;
use App\Models\Team;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Inventory\SkuGenerator;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * "Record Inbound P.O. Delivery": one delivery of several purchase order products, inspected and
 * confirmed in one step.
 *
 *   select a purchase order → enter received / accepted / rejected per product → confirm
 *   → Delivery # reserved by the server → accepted units added to stock (rejected never)
 *   → purchase cost history recorded → purchase order statuses recalculated.
 *
 * A purchase order with several products is several purchase_orders rows sharing one PO number.
 * Each product of a delivery is a `deliveries` row under one InboundDelivery record. Stock and
 * inspection rules are ProcurementWorkflow's (inspect / syncAcceptedStock), so the same delivery
 * can never be added to stock twice and a correction moves stock by the difference only.
 *
 * Everything runs in one transaction with the rows locked: a failure leaves nothing half saved.
 */
class InboundReceiving
{
    public function __construct(private ProcurementWorkflow $workflow, private SkuGenerator $skus) {}

    /**
     * Purchase orders with goods still to receive (Pending or Partially Received), one entry per
     * PO number with its open products. Cancelled, draft, closed and fully received lines are left out.
     *
     * @return list<array<string, mixed>>
     */
    public function receivableOrders(int $teamId): array
    {
        $lines = PurchaseOrder::where('team_id', $teamId)->whereIn('status', PurchaseOrder::RECEIVABLE)
            ->with(['supplier:id,name', 'product:id,sku,name,unit'])
            ->orderByDesc('po_date')->orderBy('id')
            ->get();
        $progress = $this->progress($lines->pluck('id')->all());
        $mapped = $this->mappedProducts($teamId, $lines);

        return $lines->groupBy('po_number')
            ->map(function (Collection $group) use ($progress, $mapped) {
                $open = $group->map(fn (PurchaseOrder $po) => $this->linePayload($po, $progress[$po->id] ?? null, $mapped))
                    ->filter(fn ($l) => $l['remaining'] > 0)->values();
                if ($open->isEmpty()) {
                    return null;
                }
                $first = $group->first();

                return [
                    'po_number' => $first->po_number,
                    'po_date' => $first->po_date?->toDateString(),
                    'supplier' => $first->supplier ? ['id' => $first->supplier->id, 'name' => $first->supplier->name] : null,
                    'status' => $group->contains('status', PurchaseOrder::PARTIAL) ? PurchaseOrder::STATUS_LABELS[PurchaseOrder::PARTIAL] : PurchaseOrder::STATUS_LABELS[PurchaseOrder::SENT],
                    'lines' => $open->all(),
                ];
            })
            ->filter()->values()->all();
    }

    /**
     * Record, inspect and confirm a delivery against one purchase order.
     *
     * @param  array{po_number: string, delivery_date: string, supplier_document_number?: ?string, notes?: ?string, submission_key?: ?string,
     *     lines: list<array{purchase_order_id: int, received_quantity: int, accepted_quantity: int, rejected_quantity: int, inspection_remarks?: ?string,
     *     rejection_category?: ?string, over_receipt_reason?: ?string, product_action?: ?string, product_id?: ?int, category?: ?string, inspected?: bool}>}  $data
     */
    public function confirm(Team $team, User $user, array $data): InboundDelivery
    {
        $key = trim((string) ($data['submission_key'] ?? '')) ?: null;
        if ($key && ($done = InboundDelivery::where('submission_key', $key)->first())) {
            throw new WorkflowException("This delivery was already recorded as {$done->delivery_number}.");
        }

        return DB::transaction(function () use ($team, $user, $data, $key) {
            $lines = collect($data['lines'])->values();
            $pos = PurchaseOrder::where('team_id', $team->id)->where('po_number', $data['po_number'])
                ->whereIn('id', $lines->pluck('purchase_order_id'))->lockForUpdate()->get()->keyBy('id');
            if ($pos->isEmpty() || $pos->count() !== $lines->pluck('purchase_order_id')->unique()->count()) {
                throw new WorkflowException("A product does not belong to purchase order {$data['po_number']}. Reload the page and select the order again.", 'po_number');
            }
            $receivedLines = $lines->filter(fn ($l) => (int) $l['received_quantity'] > 0);
            if ($receivedLines->isEmpty()) {
                throw new WorkflowException('Enter the received quantity of at least one product.', 'lines');
            }
            $date = Carbon::parse($data['delivery_date'])->startOfDay();
            $this->assertDeliveryDate($date, $pos);

            $progress = $this->progress($pos->keys()->all());
            foreach ($lines as $i => $line) {
                if ((int) $line['received_quantity'] <= 0) {
                    if ((int) $line['accepted_quantity'] + (int) $line['rejected_quantity'] > 0) {
                        throw new WorkflowException('Accepted and rejected quantities need a received quantity.', "lines.{$i}.received_quantity");
                    }

                    continue;
                }
                $po = $pos[$line['purchase_order_id']];
                if (! in_array($po->status, PurchaseOrder::RECEIVABLE, true)) {
                    throw new WorkflowException("{$po->description} is ".(PurchaseOrder::STATUS_LABELS[$po->status] ?? $po->status).' on this purchase order, so nothing more can be received.', "lines.{$i}.received_quantity");
                }
                if (empty($line['inspected'])) {
                    throw new WorkflowException("Check the quality of {$po->description} before confirming the delivery.", "lines.{$i}.inspected");
                }
                $this->assertInspection($line, $i, (int) $progress[$po->id]['remaining'], $user, $po);
            }

            $header = InboundDelivery::create([
                'team_id' => $team->id,
                'delivery_number' => InboundDelivery::reserveNumber($team->id),
                'delivery_date' => $date->toDateString(),
                'purchase_order_number' => $data['po_number'],
                'supplier_id' => $pos->first()->supplier_id,
                'status' => InboundDelivery::CONFIRMED,
                'confirmed_by_id' => $user->id,
                'confirmed_at' => now(),
                'supplier_document_number' => trim((string) ($data['supplier_document_number'] ?? '')) ?: null,
                'notes' => trim((string) ($data['notes'] ?? '')) ?: null,
                'submission_key' => $key,
            ]);

            $summary = [];
            foreach ($receivedLines as $i => $line) {
                $po = $pos[$line['purchase_order_id']];
                $received = (int) $line['received_quantity'];
                $open = (int) $progress[$po->id]['remaining'];
                $product = (int) $line['accepted_quantity'] > 0 ? $this->resolveProduct($po, $line, $user, $i) : $this->knownProduct($po);

                $delivery = Delivery::create([
                    'team_id' => $team->id,
                    'inbound_delivery_id' => $header->id,
                    'delivery_date' => $header->delivery_date,
                    'delivery_number' => $header->delivery_number,
                    'supplier_document_number' => $header->supplier_document_number,
                    'purchase_order_id' => $po->id,
                    'purchase_order_number' => $po->po_number,
                    'quantity' => $received,
                    'over_receipt_reason' => $received > $open ? trim((string) $line['over_receipt_reason']) : null,
                    'over_receipt_approved_by_id' => $received > $open ? $user->id : null,
                    'unit' => $po->unit_of_measure ?: 'PCS',
                    'description' => $po->description,
                    'supplier_id' => $po->supplier_id,
                    'received_by_id' => $user->id,
                    'status' => 'Delivered',
                    'notes' => $header->notes,
                ]);
                // Inspection + Stock In of the accepted units (exactly once) + PO status.
                $this->workflow->inspect($delivery, $user, $this->inspectionData($line, $header->delivery_date->toDateString()));
                if ($product) {
                    $this->recordCost($delivery, $po, $product, $user);
                }
                $summary[] = "{$po->description}: {$received} received, {$line['accepted_quantity']} accepted, {$line['rejected_quantity']} rejected";
            }

            AuditLogger::record('inbound_delivery.confirmed', "Delivery {$header->delivery_number} confirmed for purchase order {$header->purchase_order_number}: ".implode('; ', $summary).'.', $header,
                ['purchase_order' => $header->purchase_order_number, 'delivery_date' => $header->delivery_date->toDateString(), 'lines' => $summary], $user);

            return $header->fresh(['items']);
        });
    }

    /**
     * Correct a confirmed delivery: date, supplier document, and each product's received /
     * accepted / rejected quantities and remarks. Stock moves by the difference only (10 → 8
     * accepted takes 2 units out); a change that would make stock negative is refused.
     * Who, when, the reason and every before/after value are kept in the audit trail.
     *
     * @param  array{reason: string, delivery_date?: ?string, supplier_document_number?: ?string, lines?: list<array<string, mixed>>}  $data
     */
    public function correct(InboundDelivery $header, User $user, array $data): InboundDelivery
    {
        return DB::transaction(function () use ($header, $user, $data) {
            $header = InboundDelivery::lockForUpdate()->findOrFail($header->id);
            if ($header->isCancelled()) {
                throw new WorkflowException("Delivery {$header->delivery_number} was cancelled and cannot be corrected.");
            }
            $items = Delivery::where('inbound_delivery_id', $header->id)->lockForUpdate()->get()->keyBy('id');
            $pos = PurchaseOrder::whereIn('id', $items->pluck('purchase_order_id')->filter())->lockForUpdate()->get()->keyBy('id');
            $changes = [];

            $date = isset($data['delivery_date']) ? Carbon::parse($data['delivery_date'])->startOfDay() : $header->delivery_date;
            if ($date->toDateString() !== $header->delivery_date->toDateString()) {
                $this->assertDeliveryDate($date, $pos);
                $changes['delivery_date'] = ['from' => $header->delivery_date->toDateString(), 'to' => $date->toDateString()];
                $header->update(['delivery_date' => $date->toDateString()]);
                foreach ($items as $item) {
                    $item->update(['delivery_date' => $date->toDateString()]
                        + ($item->inspected_on && $item->inspected_on->lt($date) ? ['inspected_on' => $date->toDateString()] : []));
                }
            }
            if (array_key_exists('supplier_document_number', $data)) {
                $doc = trim((string) $data['supplier_document_number']) ?: null;
                if ($doc !== $header->supplier_document_number) {
                    $changes['supplier_document_number'] = ['from' => $header->supplier_document_number, 'to' => $doc];
                    $header->update(['supplier_document_number' => $doc]);
                    Delivery::where('inbound_delivery_id', $header->id)->update(['supplier_document_number' => $doc]);
                }
            }

            foreach (array_values($data['lines'] ?? []) as $i => $line) {
                $item = $items->get((int) $line['delivery_id']);
                if (! $item) {
                    throw new WorkflowException('A product does not belong to this delivery. Reload the page and try again.', "lines.{$i}.received_quantity");
                }
                $item->refresh();
                $before = [
                    'received_quantity' => (int) $item->quantity,
                    'accepted_quantity' => $item->inspection_result === null ? null : (int) $item->accepted_quantity,
                    'rejected_quantity' => $item->inspection_result === null ? null : (int) $item->rejected_quantity,
                    'inspection_remarks' => $item->rejected_quantity > 0 ? ($item->rejection_reason ?? $item->inspection_remarks) : $item->inspection_remarks,
                ];
                $after = [
                    'received_quantity' => (int) $line['received_quantity'],
                    'accepted_quantity' => (int) $line['accepted_quantity'],
                    'rejected_quantity' => (int) $line['rejected_quantity'],
                    'inspection_remarks' => trim((string) ($line['inspection_remarks'] ?? '')) ?: null,
                ];
                $categoryChanged = (int) $line['rejected_quantity'] > 0 && ($line['rejection_category'] ?? null) && $line['rejection_category'] !== $item->rejection_category;
                if ($before === $after && ! $categoryChanged && empty($line['product_action'])) {
                    continue;
                }
                if ($after['received_quantity'] < 1) {
                    throw new WorkflowException('Received quantity must be at least 1. To undo a whole delivery, an administrator can cancel it.', "lines.{$i}.received_quantity");
                }
                $po = $item->purchase_order_id ? $pos->get($item->purchase_order_id) : null;
                // What is still open on the order without this delivery's own units.
                $open = PHP_INT_MAX;
                if ($po) {
                    $p = $this->progress([$po->id])[$po->id];
                    $own = $item->inspection_result === null ? (int) $item->quantity : (int) $item->accepted_quantity;
                    $open = max(0, (int) $p['remaining'] + $own);
                }
                $this->assertInspection($line, $i, $open, $user, $po, $item->over_receipt_reason);
                if ($po && (int) $line['accepted_quantity'] > 0) {
                    $this->resolveProduct($po, $line, $user, $i);
                }

                $over = $after['received_quantity'] > $open;
                $item->update([
                    'quantity' => $after['received_quantity'],
                    'over_receipt_reason' => $over ? (trim((string) ($line['over_receipt_reason'] ?? '')) ?: $item->over_receipt_reason) : null,
                    'over_receipt_approved_by_id' => $over ? ($item->over_receipt_approved_by_id ?? $user->id) : null,
                ]);
                $hadCost = $item->posted_at !== null;
                $this->workflow->inspect($item, $user, $this->inspectionData($line, ($item->inspected_on ?? $header->delivery_date)->toDateString()));
                if ($po && ! $hadCost && ($product = $this->knownProduct($po->fresh()))) {
                    $this->recordCost($item->fresh(), $po, $product, $user);
                }
                $changes['lines'][] = ['product' => $item->description, 'from' => $before, 'to' => $after];
            }

            if (! $changes) {
                throw new WorkflowException('Nothing was changed.');
            }
            $reason = trim((string) $data['reason']);
            AuditLogger::record('inbound_delivery.corrected', "Delivery {$header->delivery_number} corrected: {$reason}", $header, ['reason' => $reason, 'changes' => $changes], $user);

            return $header->fresh(['items']);
        });
    }

    /**
     * Cancel a delivery (administrators only). The record stays, marked Cancelled with who, when
     * and why. Units it added to stock are taken back out; refused if they were already used
     * (stock would go negative).
     */
    public function cancel(InboundDelivery $header, User $user, string $reason): void
    {
        if (! $user->isAdmin()) {
            throw new WorkflowException('Only an administrator can delete a delivery record.');
        }
        DB::transaction(function () use ($header, $user, $reason) {
            $header = InboundDelivery::lockForUpdate()->findOrFail($header->id);
            if ($header->isCancelled()) {
                throw new WorkflowException("Delivery {$header->delivery_number} is already cancelled.");
            }
            $items = Delivery::where('inbound_delivery_id', $header->id)->lockForUpdate()->get();

            $reversed = [];
            foreach ($items as $item) {
                $posted = (int) $item->posted_quantity;
                $po = $item->purchase_order_id ? PurchaseOrder::find($item->purchase_order_id) : null;
                if ($posted > 0 && $po?->product_id) {
                    $product = Product::lockForUpdate()->findOrFail($po->product_id);
                    $prev = (int) $product->stock;
                    if ($prev < $posted) {
                        throw new WorkflowException("Only {$prev} units of {$product->name} are in stock, so the {$posted} units added by delivery {$header->delivery_number} cannot be taken back out. Reconcile the stock first.");
                    }
                    $product->stock = $prev - $posted;
                    $product->save();
                    $product->updateCalculatedStatus();
                    StockMovement::create([
                        'team_id' => $item->team_id,
                        'product_id' => $product->id,
                        'user_id' => $user->id,
                        'supplier_id' => $po->supplier_id,
                        'movement_key' => "inbound-delivery:{$item->id}:cancelled",
                        'type' => 'adjustment',
                        'quantity' => $posted,
                        'previous_stock' => $prev,
                        'new_stock' => $prev - $posted,
                        'reference_number' => $header->delivery_number,
                        'reason' => 'Inbound delivery cancelled',
                        'remarks' => "PO {$po->po_number} · Delivery {$header->delivery_number} cancelled: {$reason}",
                        'movement_date' => now(),
                    ]);
                    $reversed[] = "{$posted} × {$product->name}";
                }
                $item->update(['status' => 'Cancelled', 'posted_quantity' => 0]);
            }
            $header->update(['status' => InboundDelivery::CANCELLED, 'cancelled_by_id' => $user->id, 'cancelled_at' => now(), 'cancellation_reason' => $reason]);
            foreach ($items->pluck('purchase_order_id')->filter()->unique() as $poId) {
                $this->workflow->refreshPurchaseOrderStatus(PurchaseOrder::find($poId), $user);
            }
            AuditLogger::record('inbound_delivery.cancelled', "Delivery {$header->delivery_number} cancelled: {$reason}".($reversed ? ' Stock taken back out: '.implode(', ', $reversed).'.' : ''), $header,
                ['reason' => $reason, 'stock_reversed' => $reversed], $user);
        });
    }

    // ─────────────────────────── helpers ───────────────────────────

    /**
     * Per purchase order line: received (delivered), accepted, rejected and still open units.
     * Same rules as ProcurementWorkflow::summary: cancelled deliveries do not count, and rejected
     * units stay owed.
     *
     * @param  list<int>  $poIds
     * @return array<int, array{ordered: int, received: int, accepted: int, rejected: int, remaining: int}>
     */
    public function progress(array $poIds): array
    {
        if (! $poIds) {
            return [];
        }
        $deliveries = Delivery::whereIn('purchase_order_id', $poIds)
            ->where(fn ($q) => $q->whereNull('status')->orWhere('status', '!=', 'Cancelled'))
            ->get(['purchase_order_id', 'quantity', 'accepted_quantity', 'rejected_quantity', 'inspection_result'])
            ->groupBy('purchase_order_id');

        return PurchaseOrder::whereIn('id', $poIds)->get(['id', 'quantity'])->mapWithKeys(function ($po) use ($deliveries) {
            $d = $deliveries->get($po->id, collect());
            $accepted = (int) $d->sum('accepted_quantity');
            $uninspected = (int) $d->whereNull('inspection_result')->sum('quantity');

            return [$po->id => [
                'ordered' => (int) $po->quantity,
                'received' => (int) $d->sum('quantity'),
                'accepted' => $accepted,
                'rejected' => (int) $d->sum('rejected_quantity'),
                'remaining' => max(0, (int) $po->quantity - $accepted - $uninspected),
            ]];
        })->all();
    }

    /** @return array<string, mixed> */
    public function linePayload(PurchaseOrder $po, ?array $progress, Collection $mapped): array
    {
        $product = $po->product ?? $mapped->get($po->supplier_id.'|'.$po->item_sku);

        return [
            'id' => $po->id,
            'quantity' => (int) $po->quantity,
            'item_sku' => $po->item_sku,
            'description' => $po->description,
            'unit_cost' => $po->unit_cost !== null ? (float) $po->unit_cost : null,
            'unit_of_measure' => $po->unit_of_measure,
            'extended_cost' => $po->total_cost !== null ? (float) $po->total_cost : round((int) $po->quantity * (float) $po->unit_cost, 2),
            'status' => $po->status,
            'status_label' => $po->status_label,
            'previously_received' => (int) ($progress['received'] ?? 0),
            'previously_accepted' => (int) ($progress['accepted'] ?? 0),
            'remaining' => (int) ($progress['remaining'] ?? $po->quantity),
            // Our inventory product: linked on the order, or through the supplier's SKU mapping.
            'product' => $product ? ['id' => $product->id, 'sku' => $product->sku, 'name' => $product->name, 'via' => $po->product_id ? 'purchase_order' : 'supplier_sku'] : null,
        ];
    }

    /** Products linked to the supplier SKUs of these lines, keyed "supplier|sku". */
    public function mappedProducts(int $teamId, Collection $lines): Collection
    {
        $unlinked = $lines->whereNull('product_id')->filter(fn ($po) => $po->supplier_id && $po->item_sku);
        if ($unlinked->isEmpty()) {
            return collect();
        }

        return SupplierSkuMapping::where('team_id', $teamId)
            ->whereIn('supplier_id', $unlinked->pluck('supplier_id')->unique())
            ->whereIn('supplier_sku', $unlinked->pluck('item_sku')->unique())
            ->with('product:id,sku,name,unit')->get()
            ->filter(fn ($m) => $m->product)
            ->mapWithKeys(fn ($m) => [$m->supplier_id.'|'.$m->supplier_sku => $m->product]);
    }

    private function assertDeliveryDate(Carbon $date, Collection $pos): void
    {
        if ($date->isAfter(today())) {
            throw new WorkflowException('The delivery date cannot be in the future.', 'delivery_date');
        }
        $ordered = $pos->max(fn ($po) => $po->po_date);
        if ($ordered && $date->lt(Carbon::parse($ordered)->startOfDay())) {
            throw new WorkflowException('The delivery date cannot be before the purchase order date ('.Carbon::parse($ordered)->format('M j, Y').').', 'delivery_date');
        }
    }

    /** Quantities and remarks of one product, checked before anything is saved. */
    private function assertInspection(array $line, int $i, int $open, User $user, ?PurchaseOrder $po, ?string $approvedOverReason = null): void
    {
        $received = (int) $line['received_quantity'];
        $accepted = (int) $line['accepted_quantity'];
        $rejected = (int) $line['rejected_quantity'];
        $name = $po?->description ?? 'this product';
        if ($accepted < 0 || $rejected < 0) {
            throw new WorkflowException("Accepted and rejected quantities of {$name} cannot be negative.", "lines.{$i}.accepted_quantity");
        }
        if ($accepted + $rejected !== $received) {
            throw new WorkflowException("{$name}: accepted ({$accepted}) plus rejected ({$rejected}) must equal the {$received} received.", "lines.{$i}.accepted_quantity");
        }
        if ($received > $open) {
            $reason = trim((string) ($line['over_receipt_reason'] ?? '')) ?: $approvedOverReason;
            if (! $user->isAdmin() || ! $reason) {
                throw new WorkflowException("{$name}: only {$open} units are still owed on this purchase order. Receiving more needs an administrator and a reason.", "lines.{$i}.received_quantity");
            }
        }
        if ($rejected > 0 && ! trim((string) ($line['inspection_remarks'] ?? ''))) {
            throw new WorkflowException("{$name}: describe why {$rejected} units were rejected in the inspection remarks.", "lines.{$i}.inspection_remarks");
        }
    }

    private function inspectionData(array $line, string $inspectedOn): array
    {
        $rejected = (int) $line['rejected_quantity'];
        $remarks = trim((string) ($line['inspection_remarks'] ?? '')) ?: null;

        return [
            'accepted_quantity' => (int) $line['accepted_quantity'],
            'rejected_quantity' => $rejected,
            'inspected_on' => $inspectedOn,
            'inspection_remarks' => $remarks,
            'rejection_reason' => $rejected > 0 ? $remarks : null,
            'rejection_category' => $rejected > 0 ? (($line['rejection_category'] ?? null) ?: 'other') : null,
        ];
    }

    /** The inventory product of a line without asking: linked on the order, or by the supplier's SKU mapping. */
    private function knownProduct(PurchaseOrder $po): ?Product
    {
        if ($po->product_id) {
            return Product::find($po->product_id);
        }

        return SupplierSkuMapping::productFor($po->team_id, $po->supplier_id, $po->item_sku);
    }

    /**
     * The inventory product the accepted units go into, linked to the purchase order line.
     * Never matched by description: the line's own product, the supplier's SKU mapping, or the
     * user's explicit choice (link to an existing product / create a new one with a new SKU).
     */
    private function resolveProduct(PurchaseOrder $po, array $line, User $user, int $i): Product
    {
        $product = $this->knownProduct($po);
        $how = $po->product_id ? null : ($product ? 'supplier SKU mapping' : null);

        if (! $product) {
            $action = $line['product_action'] ?? null;
            if ($action === 'link') {
                $product = Product::where('team_id', $po->team_id)->find((int) ($line['product_id'] ?? 0));
                if (! $product) {
                    throw new WorkflowException("Choose the inventory product for {$po->description}.", "lines.{$i}.product_id");
                }
                $how = 'linked by '.$user->name;
            } elseif ($action === 'create') {
                $product = $this->createProduct($po, $line['category'] ?? null);
                $how = 'new product';
            } else {
                throw new WorkflowException("{$po->description} is not in inventory yet. Choose whether to link it to an existing product or create a new one.", "lines.{$i}.product_action");
            }
        }

        if (! $po->product_id) {
            $po->update(['product_id' => $product->id]);
            AuditLogger::record('purchase_order.product_linked', "Purchase order {$po->po_number}: {$po->description} linked to {$product->sku} {$product->name} ({$how}).", $po, ['product_id' => $product->id, 'supplier_sku' => $po->item_sku], $user);
        }
        if ($po->supplier_id && $po->item_sku) {
            // The supplier's code is remembered for next time; our own SKU is never replaced.
            SupplierSkuMapping::firstOrCreate(
                ['team_id' => $po->team_id, 'supplier_id' => $po->supplier_id, 'supplier_sku' => $po->item_sku],
                ['product_id' => $product->id, 'created_by_id' => $user->id],
            );
        }
        if (! $product->supplier_id && $po->supplier_id) {
            $product->update(['supplier_id' => $po->supplier_id]);
        }

        return $product;
    }

    /**
     * A new inventory product for a purchase order line: our own SKU (category sequence), the
     * supplier, purchase cost and U/M. It has no selling price yet, so it is saved Incomplete
     * (not sellable) until one is entered on the Inventory page. Stock is added by the inspection.
     */
    private function createProduct(PurchaseOrder $po, ?string $category): Product
    {
        $category = trim((string) $category) ?: 'General';
        $product = Product::create([
            'team_id' => $po->team_id,
            'sku' => $this->skus->next($po->team_id, $category),
            'name' => mb_substr($po->description, 0, 255),
            'category' => $category,
            'unit' => $po->unit_of_measure ? mb_strtolower($po->unit_of_measure) : null,
            'cost_price' => $po->unit_cost,
            'price' => null,
            'stock' => 0,
            'supplier_id' => $po->supplier_id,
            'description' => "Created from purchase order {$po->po_number}".($po->item_sku ? " (supplier SKU {$po->item_sku})" : '').'.',
            'is_active' => false,
            'missing_fields' => $po->unit_of_measure ? ['price'] : ['unit', 'price'],
        ]);
        $product->forceFill(['status' => 'Incomplete'])->save();
        Cache::forget("team:{$po->team_id}:categories"); // the Inventory category filter shows a new category at once

        return $product;
    }

    /** Purchase cost history: what this supplier charged for the product on this delivery. */
    private function recordCost(Delivery $delivery, PurchaseOrder $po, Product $product, User $user): void
    {
        if ($po->unit_cost === null || SupplierProductCost::where('delivery_id', $delivery->id)->exists()) {
            return;
        }
        SupplierProductCost::create([
            'team_id' => $delivery->team_id,
            'supplier_id' => $po->supplier_id,
            'product_id' => $product->id,
            'supplier_sku' => $po->item_sku,
            'unit_cost' => $po->unit_cost,
            'unit_of_measure' => $po->unit_of_measure,
            'purchase_order_id' => $po->id,
            'delivery_id' => $delivery->id,
            'recorded_on' => $delivery->delivery_date,
            'recorded_by_id' => $user->id,
        ]);
    }
}
