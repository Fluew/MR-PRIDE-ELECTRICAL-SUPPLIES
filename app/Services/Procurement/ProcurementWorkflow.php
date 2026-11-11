<?php

namespace App\Services\Procurement;

use App\Events\Procurement\InboundDeliveryRecorded;
use App\Events\Procurement\InventoryUpdated;
use App\Events\Procurement\PurchaseOrderCreated;
use App\Events\Procurement\PurchaseOrderSent;
use App\Events\Procurement\PurchaseRequestApproved;
use App\Events\Procurement\PurchaseRequestRejected;
use App\Events\Procurement\PurchaseRequestSubmitted;
use App\Events\Procurement\QualityInspectionCompleted;
use App\Exceptions\WorkflowException;
use App\Models\Delivery;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequest;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\SupplierQuote;
use App\Models\Team;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Inventory\ProductPrioritizer;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The Inventory-to-Procurement workflow. Every state change goes through here, inside a
 * transaction with the affected rows locked, so a step can never happen twice or out of order:
 *
 *   AHP reorder recommendation → Purchase Request (staff) → approval (admin)
 *   → Purchase Order (from the approved request only) → sent to supplier
 *   → Inbound Delivery (actual quantities) → Inspection (accepted / rejected), which adds the
 *     accepted quantity to stock automatically, exactly once (rejected units never reach stock)
 *
 * An AHP recommendation never creates an order or changes stock by itself.
 */
class ProcurementWorkflow
{
    public function __construct(private ProductPrioritizer $prioritizer, private WorkflowNotifier $notifier) {}

    // ─────────────────────────── Purchase Requests ───────────────────────────

    /**
     * Create one Purchase Request per selected product. The AHP score, forecast, stock and
     * recommended quantity are taken from the server-side calculation at submission time,
     * never from the browser.
     *
     * @param  list<array{product_id: ?int, quantity: int, product_description?: ?string, unit?: ?string, justification: string, supplier_id?: int|null, quotation_id?: int|null}>  $lines
     * @param  int|null  $forecastMonths  forecasting period the staff member reviewed (3, 6 or 12)
     * @return Collection<int, PurchaseRequest>
     */
    public function submitRequests(Team $team, User $user, array $lines, bool $asDraft = false, ?int $forecastMonths = null): Collection
    {
        if ($lines === []) {
            throw new WorkflowException('Select at least one product.', 'lines');
        }
        // Unlisted products (no product id) are not compared with each other.
        $ids = array_values(array_filter(array_map(fn ($l) => (int) ($l['product_id'] ?? 0), $lines)));
        if (count($ids) !== count(array_unique($ids))) {
            throw new WorkflowException('Each product can appear only once in a submission.', 'lines');
        }

        $priorities = $this->prioritizer->forTeam($team->id, null, $forecastMonths);

        $created = DB::transaction(function () use ($team, $user, $lines, $asDraft, $priorities) {
            $out = collect();
            foreach ($lines as $i => $line) {
                if (empty($line['product_id'])) {
                    $out->push($this->createUnlistedRequest($team, $user, $line, $i, $asDraft));

                    continue;
                }
                $product = Product::where('team_id', $team->id)->where('is_active', true)->find($line['product_id'])
                    ?? throw new WorkflowException('A selected product is not an active product of this team.', "lines.{$i}.product_id");
                $qty = (int) $line['quantity'];
                if ($qty < 1) {
                    throw new WorkflowException("Requested quantity for {$product->name} must be at least 1.", "lines.{$i}.quantity");
                }
                // A catalog product has its own name and details: no separate description is kept.
                $description = null;
                // The unit is the product's own unit from the Product Catalog: staff cannot choose
                // another, and none is assumed when the product has no unit.
                $unit = trim((string) $product->unit);
                if ($unit === '') {
                    throw new WorkflowException("{$product->name} has no unit of measure in the Product Catalog. Add its unit in Inventory before requesting it.", "lines.{$i}.product_id");
                }
                $justification = trim((string) ($line['justification'] ?? ''));
                if ($justification === '') {
                    throw new WorkflowException("Give a purchase justification for {$product->name}.", "lines.{$i}.justification");
                }
                $open = PurchaseRequest::where('team_id', $team->id)->where('product_id', $product->id)
                    ->whereIn('status', [PurchaseRequest::DRAFT, PurchaseRequest::PENDING, PurchaseRequest::APPROVED])->value('request_number');
                if ($open) {
                    throw new WorkflowException("{$product->name} already has an open purchase request ({$open}).", "lines.{$i}.product_id");
                }

                $p = $priorities[$product->id] ?? null;
                $stock = $p ? collect($p['criteria'])->firstWhere('key', 'stock')['inputs']['available'] : (int) $product->stock;

                $pr = PurchaseRequest::create([
                    'team_id' => $team->id,
                    'request_number' => PurchaseRequest::generateRequestNumber($team->id),
                    'product_id' => $product->id,
                    'product_description' => $description,
                    'quotation_id' => $line['quotation_id'] ?? null,
                    'supplier_id' => $line['supplier_id'] ?? null,
                    'requested_by_id' => $user->id,
                    'quantity' => $qty,
                    'unit' => $unit,
                    'current_stock' => $stock,
                    'forecast_quantity' => $p['reorder']['forecast'] ?? null,
                    'ahp_score' => $p['score'] ?? null,
                    'ahp_classification' => $p['classification'] ?? null,
                    'recommended_quantity' => $p && $p['reorder']['needed'] ? $p['reorder']['suggested_quantity'] : null,
                    'estimated_cost' => round(((float) ($product->cost_price ?: $product->price)) * $qty, 2),
                    'status' => $asDraft ? PurchaseRequest::DRAFT : PurchaseRequest::PENDING,
                    'justification' => $justification,
                    'requested_at' => now(),
                    'submitted_at' => $asDraft ? null : now(),
                ]);
                AuditLogger::record('purchase_request.'.($asDraft ? 'drafted' : 'submitted'), "{$pr->request_number}: {$qty}".($unit ? " {$unit}" : '')." × {$product->name} requested.", $pr, [
                    'product_description' => $description, 'unit' => $unit,
                    'recommended_quantity' => $pr->recommended_quantity, 'ahp_score' => $pr->ahp_score, 'forecast' => $pr->forecast_quantity, 'current_stock' => $stock,
                ], $user);
                $out->push($pr);
            }

            return $out;
        });

        if (! $asDraft) {
            $created->each(fn (PurchaseRequest $pr) => PurchaseRequestSubmitted::dispatch($pr, $user));
        }

        return $created;
    }

    /**
     * "Product Not Listed": a request for something that is not in the Product Catalog. It keeps
     * the staff's description, quantity and unit, has no product and no AHP snapshot, and creates
     * nothing in inventory. The product is linked or created only when the goods are received.
     */
    private function createUnlistedRequest(Team $team, User $user, array $line, int $i, bool $asDraft): PurchaseRequest
    {
        $description = trim((string) ($line['product_description'] ?? ''));
        if ($description === '') {
            throw new WorkflowException('Describe the product you need to purchase.', "lines.{$i}.product_description");
        }
        $unit = trim((string) ($line['unit'] ?? ''));
        if ($unit === '') {
            throw new WorkflowException('Select the unit.', "lines.{$i}.unit");
        }
        $qty = (int) $line['quantity'];
        if ($qty < 1) {
            throw new WorkflowException('Requested quantity must be at least 1.', "lines.{$i}.quantity");
        }
        $justification = trim((string) ($line['justification'] ?? ''));
        if ($justification === '') {
            throw new WorkflowException('Give a purchase justification.', "lines.{$i}.justification");
        }

        $pr = PurchaseRequest::create([
            'team_id' => $team->id,
            'request_number' => PurchaseRequest::generateRequestNumber($team->id),
            'product_id' => null,
            'product_description' => $description,
            'quotation_id' => $line['quotation_id'] ?? null,
            'supplier_id' => $line['supplier_id'] ?? null,
            'requested_by_id' => $user->id,
            'quantity' => $qty,
            'unit' => $unit,
            'current_stock' => null,
            'estimated_cost' => 0, // no catalog price yet: the agreed price is entered on the purchase order
            'status' => $asDraft ? PurchaseRequest::DRAFT : PurchaseRequest::PENDING,
            'justification' => $justification,
            'requested_at' => now(),
            'submitted_at' => $asDraft ? null : now(),
        ]);
        AuditLogger::record('purchase_request.'.($asDraft ? 'drafted' : 'submitted'), "{$pr->request_number}: {$qty} {$unit} × \"{$description}\" requested (product not listed in the Product Catalog).", $pr, [
            'product_not_listed' => true, 'product_description' => $description, 'unit' => $unit,
        ], $user);

        return $pr;
    }

    public function submitDraft(PurchaseRequest $pr, User $user): void
    {
        DB::transaction(function () use ($pr, $user) {
            $pr = PurchaseRequest::lockForUpdate()->findOrFail($pr->id);
            if ($pr->status !== PurchaseRequest::DRAFT) {
                throw new WorkflowException("{$pr->request_number} is {$pr->status}; only a draft can be submitted.");
            }
            if ($pr->requested_by_id !== $user->id && ! $user->isAdmin()) {
                throw new WorkflowException('Only the staff member who drafted this request can submit it.');
            }
            $pr->update(['status' => PurchaseRequest::PENDING, 'submitted_at' => now()]);
            AuditLogger::record('purchase_request.submitted', "{$pr->request_number} submitted for approval.", $pr, [], $user);
            PurchaseRequestSubmitted::dispatch($pr, $user);
        });
    }

    public function review(PurchaseRequest $pr, User $admin, bool $approve, ?string $remarks): PurchaseRequest
    {
        if (! $admin->isAdmin()) {
            throw new WorkflowException('Only an administrator can approve or reject purchase requests.');
        }
        $remarks = trim((string) $remarks) ?: null;
        if (! $approve && ! $remarks) {
            throw new WorkflowException('Give the reason for rejecting this request.', 'remarks');
        }

        $pr = DB::transaction(function () use ($pr, $admin, $approve, $remarks) {
            $pr = PurchaseRequest::lockForUpdate()->findOrFail($pr->id);
            if ($pr->status !== PurchaseRequest::PENDING) {
                throw new WorkflowException("{$pr->request_number} is {$pr->status}; only a request pending approval can be reviewed.");
            }
            $pr->update([
                'status' => $approve ? PurchaseRequest::APPROVED : PurchaseRequest::REJECTED,
                'reviewed_by_id' => $admin->id,
                'reviewed_at' => now(),
                'review_remarks' => $remarks,
            ]);
            AuditLogger::record($approve ? 'purchase_request.approved' : 'purchase_request.rejected',
                "{$pr->request_number} ".($approve ? 'approved' : 'rejected').' by '.$admin->name.($remarks ? ": {$remarks}" : '.'), $pr, [], $admin);

            if ($approve) {
                PurchaseRequestApproved::dispatch($pr, $admin);
                // Approval moves straight on: the draft order is created in the same transaction,
                // so an approved request can never be left without its purchase order.
                $po = $this->draftPurchaseOrder($pr, $admin);
                PurchaseOrderCreated::dispatch($po, $admin, true);
            } else {
                PurchaseRequestRejected::dispatch($pr, $admin);
            }

            return $pr;
        });

        return $pr->fresh();
    }

    // ─────────────────────────── Purchase Orders ───────────────────────────

    /**
     * The draft Purchase Order for a just-approved request (called inside the approval
     * transaction). One order per request: the PR row is locked and purchase_request_id is unique.
     *
     * Pre-filled from records only, for staff to confirm before sending:
     *  - supplier: the one named on the request, else the product's usual supplier, else the
     *    supplier with the lowest verified, unexpired quote for the product;
     *  - price: that supplier's latest verified, unexpired quote in the product's unit;
     *    otherwise left at 0 and the order cannot be sent until staff enter the agreed price.
     */
    private function draftPurchaseOrder(PurchaseRequest $pr, User $by): PurchaseOrder
    {
        if (PurchaseOrder::withTrashed()->where('purchase_request_id', $pr->id)->exists()) {
            throw new WorkflowException("{$pr->request_number} already has a purchase order.");
        }
        if ($pr->isUnlisted()) {
            return $this->draftUnlistedPurchaseOrder($pr, $by);
        }
        $product = $pr->product ?? throw new WorkflowException('The product of this request no longer exists.');
        $quotes = SupplierQuote::where('team_id', $pr->team_id)->where('product_id', $product->id)->whereNotNull('verified_at')
            ->where(fn ($q) => $q->whereNull('valid_until')->orWhereDate('valid_until', '>=', today()))
            ->whereRaw('LOWER(unit) = ?', [mb_strtolower((string) ($product->unit ?: 'pcs'))])
            ->whereHas('supplier', fn ($s) => $s->where('status', 'Active'))
            ->orderByDesc('quoted_on')->get();

        $supplierId = $pr->supplier_id ?: $product->supplier_id;
        $supplierSource = $pr->supplier_id ? 'named on the purchase request' : ($product->supplier_id ? "the product's usual supplier" : null);
        if (! $supplierId && $quotes->isNotEmpty()) {
            $supplierId = $quotes->sortBy('unit_price')->first()->supplier_id;
            $supplierSource = 'lowest verified quote';
        }
        if ($supplierId && ! Supplier::where('team_id', $pr->team_id)->where('status', 'Active')->whereKey($supplierId)->exists()) {
            [$supplierId, $supplierSource] = [null, null];
        }
        $quote = $supplierId ? $quotes->firstWhere('supplier_id', $supplierId) : null;
        $unitCost = $quote ? round((float) $quote->unit_price, 2) : 0.0;

        $prefill = array_filter([
            $supplierSource ? "Supplier pre-filled ({$supplierSource})." : 'Supplier to be chosen.',
            $quote ? "Price pre-filled from verified quote {$quote->reference} of {$quote->quoted_on->format('Y-m-d')}." : 'Agreed price to be entered.',
        ]);

        $po = PurchaseOrder::create([
            'team_id' => $pr->team_id,
            'purchase_request_id' => $pr->id,
            'po_date' => today(),
            'po_number' => PurchaseOrder::generatePoNumber($pr->team_id),
            'quantity' => $pr->quantity,
            'product_id' => $product->id,
            'item_sku' => $product->sku,
            'description' => $product->name,
            'unit_of_measure' => strtoupper($product->unit ?: 'PCS'),
            'unit_cost' => $unitCost,
            'total_cost' => round($pr->quantity * $unitCost, 2),
            'supplier_id' => $supplierId,
            'prepared_by_id' => $by->id,
            'status' => PurchaseOrder::DRAFT,
            'notes' => implode(' ', $prefill),
        ]);
        $pr->update(['status' => PurchaseRequest::CONVERTED, 'ordered_at' => now()]);
        AuditLogger::record('purchase_order.created', "Draft purchase order {$po->po_number} created automatically from approved {$pr->request_number}. ".implode(' ', $prefill), $po,
            ['purchase_request' => $pr->request_number, 'automatic' => true, 'quote_id' => $quote?->id], $by);

        return $po;
    }

    /**
     * Draft order for a "Product Not Listed" request: the staff's description and unit, no product
     * and no SKU. Staff choose the supplier and enter the agreed price before sending. When the goods
     * arrive, Record Inbound P.O. Delivery asks to link them to a catalog product or create one.
     */
    private function draftUnlistedPurchaseOrder(PurchaseRequest $pr, User $by): PurchaseOrder
    {
        $supplierId = $pr->supplier_id && Supplier::where('team_id', $pr->team_id)->where('status', 'Active')->whereKey($pr->supplier_id)->exists() ? $pr->supplier_id : null;
        $prefill = [
            'Product not listed in the Product Catalog: it is linked to a catalog product or created when the goods are received.',
            $supplierId ? 'Supplier pre-filled (named on the purchase request).' : 'Supplier to be chosen.',
            'Agreed price to be entered.',
        ];

        $po = PurchaseOrder::create([
            'team_id' => $pr->team_id,
            'purchase_request_id' => $pr->id,
            'po_date' => today(),
            'po_number' => PurchaseOrder::generatePoNumber($pr->team_id),
            'quantity' => $pr->quantity,
            'product_id' => null,
            'item_sku' => null,
            'description' => $pr->product_description,
            'unit_of_measure' => strtoupper($pr->unit ?: 'PCS'),
            'unit_cost' => 0,
            'total_cost' => 0,
            'supplier_id' => $supplierId,
            'prepared_by_id' => $by->id,
            'status' => PurchaseOrder::DRAFT,
            'notes' => implode(' ', $prefill),
        ]);
        $pr->update(['status' => PurchaseRequest::CONVERTED, 'ordered_at' => now()]);
        AuditLogger::record('purchase_order.created', "Draft purchase order {$po->po_number} created automatically from approved {$pr->request_number} (product not listed). ".implode(' ', $prefill), $po,
            ['purchase_request' => $pr->request_number, 'automatic' => true, 'product_not_listed' => true], $by);

        return $po;
    }

    /**
     * Staff review of a draft order: confirm or change the supplier, quantity, agreed price and
     * terms. A quantity different from the approved one needs a reason; more needs an admin.
     *
     * @param  array{supplier_id: int, quantity: int, unit_cost: float|string, po_date?: ?string, promised_delivery_date?: ?string, payment_terms?: ?string, quantity_adjustment_reason?: ?string, notes?: ?string}  $data
     */
    public function updateDraftPurchaseOrder(PurchaseOrder $po, User $user, array $data): PurchaseOrder
    {
        return DB::transaction(function () use ($po, $user, $data) {
            $po = PurchaseOrder::lockForUpdate()->findOrFail($po->id);
            if ($po->status !== PurchaseOrder::DRAFT) {
                throw new WorkflowException("Purchase order {$po->po_number} is {$po->status}; only a draft can be changed.");
            }
            $supplier = Supplier::where('team_id', $po->team_id)->where('status', 'Active')->find($data['supplier_id'])
                ?? throw new WorkflowException('Select an active registered supplier.', 'supplier_id');
            $approved = (int) ($po->purchaseRequest?->quantity ?? $po->quantity);
            $qty = (int) $data['quantity'];
            $reason = trim((string) ($data['quantity_adjustment_reason'] ?? '')) ?: null;
            if ($qty < 1) {
                throw new WorkflowException('Ordered quantity must be at least 1.', 'quantity');
            }
            if ($qty !== $approved) {
                if (! $reason) {
                    throw new WorkflowException("The ordered quantity differs from the approved {$approved}; give the reason for the adjustment.", 'quantity_adjustment_reason');
                }
                if ($qty > $approved && ! $user->isAdmin()) {
                    throw new WorkflowException("Ordering more than the approved {$approved} units needs an administrator.", 'quantity');
                }
            }
            $unitCost = round((float) $data['unit_cost'], 2);
            if ($unitCost <= 0) {
                throw new WorkflowException('Enter the agreed unit price.', 'unit_cost');
            }

            $before = $po->only(['supplier_id', 'quantity', 'unit_cost', 'payment_terms', 'promised_delivery_date']);
            $po->update([
                'supplier_id' => $supplier->id,
                'quantity' => $qty,
                'unit_cost' => $unitCost,
                'total_cost' => round($qty * $unitCost, 2),
                'promised_delivery_date' => $data['promised_delivery_date'] ?? null,
                'payment_terms' => $data['payment_terms'] ?? null,
                'quantity_adjustment_reason' => $qty !== $approved ? $reason : null,
                'notes' => $data['notes'] ?? $po->notes,
            ]);
            $po->purchaseRequest?->update(['supplier_id' => $supplier->id]);
            AuditLogger::record('purchase_order.draft_updated', "Draft purchase order {$po->po_number} confirmed: {$qty} × {$unitCost} from {$supplier->name}.", $po, ['before' => $before], $user);

            return $po;
        });
    }

    /**
     * Administrator correction of a purchase order as recorded: the Order Date (for every line of
     * the PO number, it is the document's date), the inventory product a line is linked to (an
     * existing product only; nothing is created), and the promised delivery date. A reason is
     * required and every before/after value goes to the audit trail. Quantities, prices and
     * descriptions are never changed here.
     *
     * @param  array{reason: string, po_date?: ?string, product_id?: ?int, promised_delivery_date?: ?string}  $data
     */
    public function correctPurchaseOrder(PurchaseOrder $po, User $admin, array $data): PurchaseOrder
    {
        if (! $admin->isAdmin()) {
            throw new WorkflowException('Only an administrator can correct a purchase order.');
        }
        $reason = trim((string) ($data['reason'] ?? ''));
        if ($reason === '') {
            throw new WorkflowException('Give the reason for the correction.', 'reason');
        }

        return DB::transaction(function () use ($po, $admin, $data, $reason) {
            $po = PurchaseOrder::lockForUpdate()->findOrFail($po->id);
            $lines = PurchaseOrder::where('team_id', $po->team_id)->where('po_number', $po->po_number)->lockForUpdate()->get();
            $changes = [];

            if (array_key_exists('po_date', $data) && $data['po_date']) {
                $date = Carbon::parse($data['po_date'])->startOfDay();
                if ($date->toDateString() !== $po->po_date?->toDateString()) {
                    if ($date->isAfter(today())) {
                        throw new WorkflowException('The order date cannot be in the future.', 'po_date');
                    }
                    $firstDelivery = Delivery::whereIn('purchase_order_id', $lines->pluck('id'))
                        ->where(fn ($q) => $q->whereNull('status')->orWhere('status', '!=', 'Cancelled'))->min('delivery_date');
                    if ($firstDelivery && $date->isAfter(Carbon::parse($firstDelivery))) {
                        throw new WorkflowException('The order date cannot be after the first delivery ('.Carbon::parse($firstDelivery)->format('M j, Y').').', 'po_date');
                    }
                    $changes['po_date'] = ['from' => $po->po_date?->toDateString(), 'to' => $date->toDateString(), 'lines' => $lines->count()];
                    PurchaseOrder::whereIn('id', $lines->pluck('id'))->update(['po_date' => $date->toDateString()]);
                }
            }

            if (array_key_exists('product_id', $data) && (int) ($data['product_id'] ?? 0) !== (int) $po->product_id) {
                $productId = $data['product_id'] ? (int) $data['product_id'] : null;
                if ($productId && ! Product::where('team_id', $po->team_id)->whereKey($productId)->exists()) {
                    throw new WorkflowException('Choose an existing product of this team.', 'product_id');
                }
                if (Delivery::where('purchase_order_id', $po->id)->where('posted_quantity', '>', 0)->exists()) {
                    throw new WorkflowException('Accepted units of this purchase order are already in stock under its current product, so the product cannot be changed. Correct the stock first.', 'product_id');
                }
                $changes['product_id'] = ['from' => $po->product_id, 'to' => $productId];
                $po->update(['product_id' => $productId]);
            }

            if (array_key_exists('promised_delivery_date', $data)) {
                $promised = $data['promised_delivery_date'] ?: null;
                if ($promised !== $po->promised_delivery_date?->toDateString()) {
                    $orderDate = PurchaseOrder::whereKey($po->id)->value('po_date');
                    if ($promised && $orderDate && Carbon::parse($promised)->lt(Carbon::parse($orderDate))) {
                        throw new WorkflowException('The promised delivery date cannot be before the order date.', 'promised_delivery_date');
                    }
                    $changes['promised_delivery_date'] = ['from' => $po->promised_delivery_date?->toDateString(), 'to' => $promised];
                    $po->update(['promised_delivery_date' => $promised]);
                }
            }

            if (! $changes) {
                throw new WorkflowException('Nothing was changed.');
            }
            AuditLogger::record('purchase_order.corrected', "Purchase order {$po->po_number} corrected by {$admin->name}: {$reason}", $po, ['reason' => $reason, 'changes' => $changes], $admin);

            return $po->fresh();
        });
    }

    public function sendPurchaseOrder(PurchaseOrder $po, User $user): void
    {
        DB::transaction(function () use ($po, $user) {
            $po = PurchaseOrder::lockForUpdate()->findOrFail($po->id);
            if ($po->status !== PurchaseOrder::DRAFT) {
                throw new WorkflowException("Purchase order {$po->po_number} is {$po->status}; only a draft can be sent.");
            }
            if (! $po->supplier_id) {
                throw new WorkflowException('Confirm the supplier of the purchase order before sending it.', 'supplier_id');
            }
            if (! $po->product_id && ! $po->purchaseRequest?->isUnlisted()) {
                throw new WorkflowException('The product of this purchase order no longer exists.', 'supplier_id');
            }
            if ((float) $po->unit_cost <= 0) {
                throw new WorkflowException('Enter the agreed unit price before sending the purchase order.', 'unit_cost');
            }
            $po->update(['status' => PurchaseOrder::SENT, 'sent_at' => now()]);
            AuditLogger::record('purchase_order.sent', "Purchase order {$po->po_number} sent to the supplier.", $po, [], $user);
            PurchaseOrderSent::dispatch($po, $user);
        });
    }

    public function cancelPurchaseOrder(PurchaseOrder $po, User $user, ?string $reason): void
    {
        $reason = trim((string) $reason) ?: throw new WorkflowException('Give the reason for cancelling.', 'reason');
        DB::transaction(function () use ($po, $user, $reason) {
            $po = PurchaseOrder::lockForUpdate()->findOrFail($po->id);
            if (! in_array($po->status, [PurchaseOrder::DRAFT, PurchaseOrder::SENT], true)) {
                throw new WorkflowException("Purchase order {$po->po_number} is {$po->status} and cannot be cancelled.");
            }
            if ($po->deliveries()->exists()) {
                throw new WorkflowException('Goods were already received against this order; close it instead of cancelling.');
            }
            $po->update(['status' => PurchaseOrder::CANCELLED, 'cancellation_reason' => $reason]);
            AuditLogger::record('purchase_order.cancelled', "Purchase order {$po->po_number} cancelled: {$reason}", $po, [], $user);
        });
    }

    /**
     * Close a fully delivered order once everything accepted is posted to stock. An admin may
     * also close a partially delivered order (the supplier will not deliver the rest), with a reason.
     */
    public function closePurchaseOrder(PurchaseOrder $po, User $user, ?string $reason = null): void
    {
        DB::transaction(function () use ($po, $user, $reason) {
            $po = PurchaseOrder::lockForUpdate()->findOrFail($po->id);
            $reason = trim((string) $reason) ?: null;
            if ($po->status === PurchaseOrder::PARTIAL) {
                if (! $user->isAdmin() || ! $reason) {
                    throw new WorkflowException('Only an administrator can close a partially delivered order, with a reason.', 'reason');
                }
            } elseif ($po->status !== PurchaseOrder::FULL) {
                throw new WorkflowException("Purchase order {$po->po_number} is {$po->status} and cannot be closed.");
            }
            $s = $this->summary($po);
            if ($s['uninspected'] > 0 || $s['awaiting_posting'] > 0) {
                throw new WorkflowException('Inspect every delivery and post all accepted units to inventory before closing.');
            }
            $po->update(['status' => PurchaseOrder::CLOSED, 'closed_at' => now(), 'notes' => $reason ? trim(($po->notes ? $po->notes."\n" : '')."Closed short: {$reason}") : $po->notes]);
            AuditLogger::record('purchase_order.closed', "Purchase order {$po->po_number} closed".($reason ? ": {$reason}" : '.'), $po, $s, $user);
        });
    }

    /**
     * Quantities of an order: delivered, inspected, accepted, rejected, posted to stock, still open.
     *
     * @return array{ordered: int, delivered: int, uninspected: int, accepted: int, rejected: int, unresolved_rejected: int, posted: int, awaiting_posting: int, remaining: int}
     */
    public function summary(PurchaseOrder $po): array
    {
        // Cancelled deliveries no longer count: their goods were taken back out of stock.
        $deliveries = Delivery::where('purchase_order_id', $po->id)->where(fn ($q) => $q->whereNull('status')->orWhere('status', '!=', 'Cancelled'))->get();
        $accepted = (int) $deliveries->sum(fn ($d) => (int) $d->accepted_quantity);
        $uninspected = (int) $deliveries->whereNull('inspection_result')->sum('quantity');
        $posted = (int) $deliveries->sum(fn ($d) => (int) $d->posted_quantity);

        return [
            'ordered' => (int) $po->quantity,
            'delivered' => (int) $deliveries->sum('quantity'),
            'uninspected' => $uninspected,
            'accepted' => $accepted,
            'rejected' => (int) $deliveries->sum(fn ($d) => (int) $d->rejected_quantity),
            // Rejected units not yet settled with the supplier (returned, replaced, credited, written off).
            'unresolved_rejected' => (int) $deliveries->whereNull('resolution_status')->sum(fn ($d) => (int) $d->rejected_quantity),
            'posted' => $posted,
            'awaiting_posting' => (int) $deliveries->whereNull('posted_at')->sum(fn ($d) => (int) $d->accepted_quantity),
            // Rejected units do not count as delivered: the supplier still owes them.
            'remaining' => max(0, (int) $po->quantity - $accepted - $uninspected),
        ];
    }

    /**
     * Delivery status from actual cumulative records: accepted units, not the fact that a delivery
     * exists. Received (Fully Delivered) only when every ordered unit was accepted and no rejected
     * units are still waiting for a resolution with the supplier.
     */
    public function refreshPurchaseOrderStatus(PurchaseOrder $po, ?User $user = null): void
    {
        $po = PurchaseOrder::lockForUpdate()->findOrFail($po->id);
        if (! in_array($po->status, [PurchaseOrder::SENT, PurchaseOrder::PARTIAL, PurchaseOrder::FULL], true)) {
            return;
        }
        $s = $this->summary($po);
        $status = match (true) {
            $s['accepted'] >= $s['ordered'] && $s['unresolved_rejected'] === 0 => PurchaseOrder::FULL,
            $s['delivered'] > 0 => PurchaseOrder::PARTIAL,
            default => PurchaseOrder::SENT,
        };
        if ($status !== $po->status) {
            $old = $po->status;
            $po->update(['status' => $status]);
            AuditLogger::record('purchase_order.status_changed', "Purchase order {$po->po_number}: {$old} → {$status} ({$s['accepted']} of {$s['ordered']} accepted).", $po, $s, $user);
        }
    }

    // ─────────────────────────── Inbound deliveries & inspection ───────────────────────────

    /**
     * Record goods actually received against a sent order. Quantities above what is still open
     * need an administrator and a recorded reason.
     *
     * @param  array{delivery_date: string, quantity: int, supplier_document_number?: ?string, notes?: ?string, over_receipt_reason?: ?string, delivery_number?: ?string}  $data
     */
    public function recordDelivery(PurchaseOrder $po, User $user, array $data): Delivery
    {
        [$delivery, $remaining] = DB::transaction(function () use ($po, $user, $data) {
            $po = PurchaseOrder::lockForUpdate()->findOrFail($po->id);
            if (! in_array($po->status, PurchaseOrder::RECEIVABLE, true)) {
                throw new WorkflowException("Purchase order {$po->po_number} is {$po->status}. Goods can be received only on an order sent to the supplier.", 'purchase_order_id');
            }
            if (! $po->product_id && $po->purchaseRequest?->isUnlisted()) {
                // Accepted units must go into stock, so the product is chosen first.
                throw new WorkflowException("{$po->description} is not in the Product Catalog yet. Record this delivery in Deliveries → Record Inbound P.O. Delivery, where you link it to a catalog product or create it.", 'purchase_order_id');
            }
            if (Carbon::parse($data['delivery_date'])->lt($po->po_date)) {
                throw new WorkflowException('The delivery date cannot be before the purchase order date.', 'delivery_date');
            }
            $qty = (int) $data['quantity'];
            if ($qty < 1) {
                throw new WorkflowException('Delivered quantity must be at least 1.', 'quantity');
            }
            $remaining = $this->summary($po)['remaining'];
            $reason = trim((string) ($data['over_receipt_reason'] ?? '')) ?: null;
            if ($qty > $remaining) {
                if (! $user->isAdmin() || ! $reason) {
                    throw new WorkflowException("Only {$remaining} units are still open on {$po->po_number}. Receiving more needs an administrator and a reason.", 'quantity');
                }
            }

            $delivery = Delivery::create([
                'team_id' => $po->team_id,
                'delivery_date' => $data['delivery_date'],
                'delivery_number' => trim((string) ($data['delivery_number'] ?? '')) ?: Delivery::generateDeliveryNumber($po->team_id, $data['delivery_date']),
                'supplier_document_number' => $data['supplier_document_number'] ?? null,
                'purchase_order_id' => $po->id,
                'purchase_order_number' => $po->po_number,
                'quantity' => $qty,
                'over_receipt_reason' => $qty > $remaining ? $reason : null,
                'over_receipt_approved_by_id' => $qty > $remaining ? $user->id : null,
                'unit' => $po->unit_of_measure ?: 'PCS',
                'description' => $po->description,
                'supplier_id' => $po->supplier_id,
                'received_by_id' => $user->id,
                'status' => 'Delivered',
                'notes' => $data['notes'] ?? null,
            ]);
            AuditLogger::record('inbound_delivery.recorded', "Delivery {$delivery->delivery_number}: {$qty} units received against {$po->po_number} ({$remaining} were open).", $delivery,
                ['ordered' => $po->quantity, 'open_before' => $remaining, 'over_receipt_reason' => $delivery->over_receipt_reason], $user);
            $this->refreshPurchaseOrderStatus($po, $user);
            // The delivery itself is the quality-inspection task: "Pending Inspection" until inspected.
            InboundDeliveryRecorded::dispatch($delivery, $user, $remaining);

            return [$delivery, $remaining];
        });

        return $delivery;
    }

    /**
     * Goods-receipt inspection. The result is derived from the quantities, never entered as a rating.
     * The accepted units are added to stock right away, in the same transaction; rejected units
     * are held and never reach stock. Admin and staff may correct it later; a correction moves
     * stock by the difference only and is refused if stock would go negative.
     *
     * Rejected units may be broken down into damaged and defective; the rest are rejected for
     * another reason (wrong item, nonconforming…), given by the category.
     *
     * @param  array{accepted_quantity: int, rejected_quantity: int, damaged_quantity?: ?int, defective_quantity?: ?int, inspected_on: string, inspection_remarks?: ?string, rejection_reason?: ?string, rejection_category?: ?string}  $data
     */
    public function inspect(Delivery $delivery, User $user, array $data): Delivery
    {
        $delivery = DB::transaction(function () use ($delivery, $user, $data) {
            $delivery = Delivery::lockForUpdate()->findOrFail($delivery->id);
            $po = $delivery->purchase_order_id ? PurchaseOrder::find($delivery->purchase_order_id) : null;
            if ($po?->status === PurchaseOrder::CANCELLED) {
                throw new WorkflowException("Purchase order {$po->po_number} is cancelled, so its goods cannot be inspected into stock.");
            }
            // Admin and staff may both correct an inspection; stock then moves by the difference only.
            $accepted = (int) $data['accepted_quantity'];
            $rejected = (int) $data['rejected_quantity'];
            if ((int) $delivery->quantity !== $accepted + $rejected) {
                throw new WorkflowException("Accepted plus rejected units must equal the {$delivery->quantity} units received.", 'accepted_quantity');
            }
            $damaged = max(0, (int) ($data['damaged_quantity'] ?? 0));
            $defective = max(0, (int) ($data['defective_quantity'] ?? 0));
            if ($damaged + $defective > $rejected) {
                throw new WorkflowException("Damaged plus defective units ({$damaged} + {$defective}) cannot be more than the {$rejected} rejected.", 'damaged_quantity');
            }
            $category = $data['rejection_category'] ?? null;
            if ($rejected > 0 && ! $category) {
                // Fully explained by the breakdown: the larger part names the category.
                $category = $damaged + $defective === $rejected ? ($damaged >= $defective ? 'damaged' : 'defective') : null;
            }
            if ($rejected > 0 && (! trim((string) ($data['rejection_reason'] ?? '')) || ! $category)) {
                throw new WorkflowException('Give the rejection category and reason for the rejected units.', 'rejection_reason');
            }
            $result = match (true) {
                $rejected === 0 => 'accepted',
                $accepted === 0 => 'rejected',
                default => 'partially_accepted',
            };

            $before = $delivery->only(['accepted_quantity', 'rejected_quantity', 'inspection_result', 'inspected_on']);
            $delivery->update([
                'accepted_quantity' => $accepted,
                'rejected_quantity' => $rejected,
                'inspection_result' => $result,
                'inspection_number' => $delivery->inspection_number ?: 'INSP-'.$delivery->delivery_number,
                'inspected_on' => $data['inspected_on'],
                'inspected_by_id' => $user->id,
                'inspection_remarks' => $data['inspection_remarks'] ?? null,
                'rejection_reason' => $rejected > 0 ? $data['rejection_reason'] : null,
                'rejection_category' => $rejected > 0 ? $category : null,
                'damaged_quantity' => $rejected > 0 ? $damaged : null,
                'defective_quantity' => $rejected > 0 ? $defective : null,
                'resolution_status' => $rejected > 0 ? $delivery->resolution_status : null,
            ]);
            AuditLogger::record(
                $before['inspection_result'] ? 'inbound_delivery.inspection_corrected' : 'inbound_delivery.inspected',
                "Delivery {$delivery->delivery_number} inspected: {$accepted} accepted, {$rejected} rejected.",
                $delivery,
                ['before' => $before['inspection_result'] ? $before : null, 'result' => $result],
                $user,
            );

            // Stock In: the accepted units go into stock now (a correction moves only the difference).
            $movement = $this->syncAcceptedStock($delivery, $user);

            if ($delivery->purchaseOrder) {
                $this->refreshPurchaseOrderStatus($delivery->purchaseOrder, $user);
            }
            QualityInspectionCompleted::dispatch($delivery, $user);
            if ($movement?->type === 'stock_in') { // first posting only, not a correction
                InventoryUpdated::dispatch($delivery->fresh(), $user, $movement);
            }

            return $delivery;
        });

        return $delivery;
    }

    /** Record how rejected units were settled with the supplier (return, replacement, credit…). */
    public function resolveRejection(Delivery $delivery, User $user, string $resolution, ?string $remarks): void
    {
        if (! in_array($resolution, Delivery::RESOLUTIONS, true)) {
            throw new WorkflowException('Choose how the rejected units were resolved.', 'resolution_status');
        }
        DB::transaction(function () use ($delivery, $user, $resolution, $remarks) {
            $delivery = Delivery::lockForUpdate()->findOrFail($delivery->id);
            if ((int) $delivery->rejected_quantity === 0) {
                throw new WorkflowException('This delivery has no rejected units.');
            }
            $delivery->update(['resolution_status' => $resolution, 'resolution_remarks' => $remarks, 'resolved_at' => now()]);
            AuditLogger::record('inbound_delivery.rejection_resolved', "Delivery {$delivery->delivery_number}: {$delivery->rejected_quantity} rejected units {$resolution}.", $delivery, ['remarks' => $remarks], $user);
            if ($delivery->purchaseOrder) {
                $this->refreshPurchaseOrderStatus($delivery->purchaseOrder, $user);
            }
        });
    }

    // ─────────────────────────── Inventory posting ───────────────────────────

    /**
     * Manual posting: a fallback for deliveries inspected before stock was added at inspection.
     * Normally the inspection posts the stock itself (inspect). Same rules: inspection recorded,
     * accepted units, and never twice (stock_movements.movement_key is unique).
     */
    public function postToInventory(Delivery $delivery, User $user): StockMovement
    {
        return DB::transaction(function () use ($delivery, $user) {
            $delivery = Delivery::lockForUpdate()->findOrFail($delivery->id);
            if ($delivery->posted_at) {
                throw new WorkflowException("Delivery {$delivery->delivery_number} was already posted to inventory on {$delivery->posted_at->format('M j, Y')}.");
            }
            if ($delivery->inspection_result === null) {
                throw new WorkflowException('Inspect the delivery before posting to inventory.');
            }
            $accepted = (int) $delivery->accepted_quantity;
            if ($accepted < 1) {
                throw new WorkflowException('No units were accepted at inspection, so nothing can be posted.');
            }
            $movement = $this->postAccepted($delivery, $user, false);
            InventoryUpdated::dispatch($delivery->fresh(), $user, $movement);

            return $movement;
        });
    }

    /**
     * Bring stock in line with the inspection: the first time, post the accepted units; after an
     * admin correction, add or take away only the difference. Call inside a transaction with the
     * delivery row locked.
     */
    public function syncAcceptedStock(Delivery $delivery, User $user): ?StockMovement
    {
        $po = $delivery->purchase_order_id ? PurchaseOrder::find($delivery->purchase_order_id) : null;
        if (! $po?->product_id) {
            return null; // an older or imported delivery not linked to a product: there is nothing to stock
        }
        $accepted = (int) $delivery->accepted_quantity;
        if (! $delivery->posted_at) {
            return $accepted > 0 ? $this->postAccepted($delivery, $user, true) : null;
        }

        $diff = $accepted - (int) $delivery->posted_quantity;
        if ($diff === 0) {
            return null;
        }
        $po = $this->postablePurchaseOrder($delivery);
        $product = Product::lockForUpdate()->findOrFail($po->product_id);
        $prev = (int) $product->stock;
        if ($prev + $diff < 0) {
            throw new WorkflowException("Only {$prev} units of {$product->name} are in stock, so the {$delivery->posted_quantity} units added from delivery {$delivery->delivery_number} cannot be reduced to {$accepted}. Correct the stock count first.", 'accepted_quantity');
        }
        $product->stock = $prev + $diff;
        $product->save();
        $product->updateCalculatedStatus();

        $n = StockMovement::where('movement_key', 'like', "inbound-delivery:{$delivery->id}:correction:%")->count() + 1;
        $movement = StockMovement::create([
            'team_id' => $delivery->team_id,
            'product_id' => $product->id,
            'user_id' => $user->id,
            'supplier_id' => $po->supplier_id,
            'movement_key' => "inbound-delivery:{$delivery->id}:correction:{$n}",
            'type' => 'adjustment',
            'quantity' => abs($diff),
            'previous_stock' => $prev,
            'new_stock' => $prev + $diff,
            'reference_number' => $delivery->delivery_number,
            'reason' => 'Inspection corrected',
            'remarks' => "PO {$po->po_number} · Inspection {$delivery->inspection_number}: accepted {$delivery->posted_quantity} → {$accepted}",
            'movement_date' => now(),
        ]);
        $delivery->update(['posted_quantity' => $accepted]);
        AuditLogger::record('inventory.received', "Inspection of delivery {$delivery->delivery_number} corrected: stock ".($diff > 0 ? '+' : '')."{$diff} × {$product->name} ({$prev} → ".($prev + $diff).').', $delivery,
            ['purchase_order' => $po->po_number, 'accepted_before' => $accepted - $diff, 'accepted_after' => $accepted], $user);

        return $movement;
    }

    private function postablePurchaseOrder(Delivery $delivery): PurchaseOrder
    {
        $po = $delivery->purchase_order_id ? PurchaseOrder::find($delivery->purchase_order_id) : null;
        if (! $po || ! $po->product_id) {
            throw new WorkflowException('The delivery must belong to a purchase order with a product.');
        }
        if ($po->status === PurchaseOrder::CANCELLED) {
            throw new WorkflowException("Purchase order {$po->po_number} is cancelled, so its goods cannot be added to stock.");
        }

        return $po;
    }

    /** Call inside a transaction with the delivery row locked. Rejected units are never posted. */
    private function postAccepted(Delivery $delivery, User $user, bool $automatic): StockMovement
    {
        $accepted = (int) $delivery->accepted_quantity;
        $po = $this->postablePurchaseOrder($delivery);
        $key = "inbound-delivery:{$delivery->id}";
        if (StockMovement::where('movement_key', $key)->exists()) {
            throw new WorkflowException("Delivery {$delivery->delivery_number} was already added to stock.");
        }

        $product = Product::lockForUpdate()->findOrFail($po->product_id);
        $prev = (int) $product->stock;
        $product->stock = $prev + $accepted;
        $product->save();
        if (method_exists($product, 'updateCalculatedStatus')) {
            $product->updateCalculatedStatus();
        }

        $movement = StockMovement::create([
            'team_id' => $delivery->team_id,
            'product_id' => $product->id,
            'user_id' => $user->id,
            'supplier_id' => $po->supplier_id,
            'movement_key' => $key,
            'type' => 'stock_in',
            'quantity' => $accepted,
            'previous_stock' => $prev,
            'new_stock' => $prev + $accepted,
            'reference_number' => $delivery->delivery_number,
            'reason' => 'Accepted supplier delivery',
            'remarks' => "PO {$po->po_number} · Delivery {$delivery->delivery_number} · Inspection {$delivery->inspection_number}"
                .((int) $delivery->rejected_quantity > 0 ? " · {$delivery->rejected_quantity} rejected units excluded" : ''),
            'movement_date' => now(),
        ]);
        $delivery->update(['posted_quantity' => $accepted, 'posted_at' => now(), 'posted_by_id' => $user->id, 'stock_movement_id' => $movement->id]);
        AuditLogger::record('inventory.received', ($automatic ? '[Automatic] ' : '')."{$accepted} × {$product->name} added to stock from delivery {$delivery->delivery_number} ({$prev} → ".($prev + $accepted).').', $delivery,
            ['purchase_order' => $po->po_number, 'inspection' => $delivery->inspection_number, 'rejected_excluded' => (int) $delivery->rejected_quantity, 'automatic' => $automatic], $user);

        return $movement;
    }
}
