<?php

namespace App\Services\Procurement;

use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\SupplierProductCost;
use App\Models\SupplierQuote;
use Illuminate\Support\Collection;

/**
 * The latest purchase unit cost of a product from one supplier (what we pay the supplier, never
 * the customer's selling price). Only records for that exact supplier count, so one supplier's
 * price is never shown for another.
 *
 * Sources, best first:
 *  1. received deliveries (supplier_product_costs) and purchase orders: the most recent date wins;
 *  2. verified supplier quotations in USD;
 *  3. the product's own cost price, only for the supplier the product is linked to.
 */
class SupplierCosts
{
    /**
     * @param  list<int>|null  $productIds  null = every product of the team
     * @return Collection<string, array{product_id: int, supplier_id: int, unit_cost: float, source: string, reference: ?string, date: ?string}> keyed "product:supplier"
     */
    public function latest(int $teamId, ?array $productIds = null): Collection
    {
        $scope = fn ($q) => $productIds === null ? $q : $q->whereIn('product_id', $productIds);
        $row = fn (int $p, int $s, $cost, string $source, ?string $ref, $date) => [
            'product_id' => $p, 'supplier_id' => $s, 'unit_cost' => round((float) $cost, 2),
            'source' => $source, 'reference' => $ref, 'date' => $date ? substr((string) $date, 0, 10) : null,
        ];

        $purchases = collect();
        SupplierProductCost::where('supplier_product_costs.team_id', $teamId)->whereNotNull('supplier_product_costs.supplier_id')
            ->when($productIds !== null, fn ($q) => $q->whereIn('supplier_product_costs.product_id', $productIds))
            ->leftJoin('deliveries', 'deliveries.id', '=', 'supplier_product_costs.delivery_id')
            ->orderByDesc('supplier_product_costs.recorded_on')->orderByDesc('supplier_product_costs.id')
            ->get(['supplier_product_costs.product_id', 'supplier_product_costs.supplier_id', 'unit_cost', 'recorded_on', 'deliveries.delivery_number'])
            ->each(function ($c) use ($purchases, $row) {
                $purchases->push($row($c->product_id, $c->supplier_id, $c->unit_cost, 'delivery', $c->delivery_number, $c->recorded_on));
            });
        PurchaseOrder::where('team_id', $teamId)->whereNotNull('product_id')->whereNotNull('supplier_id')->whereNotNull('unit_cost')
            ->where('status', '!=', PurchaseOrder::CANCELLED)->tap($scope)
            ->orderByDesc('po_date')->orderByDesc('id')
            ->get(['product_id', 'supplier_id', 'unit_cost', 'po_date', 'po_number'])
            ->each(function ($po) use ($purchases, $row) {
                $purchases->push($row($po->product_id, $po->supplier_id, $po->unit_cost, 'purchase_order', $po->po_number, $po->po_date));
            });

        // Most recent purchase first; on the same day a received delivery beats the order.
        $best = $purchases->sortBy([['date', 'desc'], fn ($a, $b) => ($a['source'] === 'delivery' ? 0 : 1) <=> ($b['source'] === 'delivery' ? 0 : 1)])
            ->unique(fn ($c) => $c['product_id'].':'.$c['supplier_id'])
            ->keyBy(fn ($c) => $c['product_id'].':'.$c['supplier_id']);

        SupplierQuote::where('team_id', $teamId)->whereNotNull('verified_at')->whereNotNull('product_id')->whereNotNull('supplier_id')
            ->where(fn ($q) => $q->whereNull('currency')->orWhere('currency', 'USD'))->tap($scope)
            ->orderByDesc('quoted_on')->orderByDesc('id')
            ->get(['product_id', 'supplier_id', 'unit_price', 'quoted_on', 'reference'])
            ->each(function ($q) use ($best, $row) {
                $best->put($q->product_id.':'.$q->supplier_id, $best->get($q->product_id.':'.$q->supplier_id)
                    ?? $row($q->product_id, $q->supplier_id, $q->unit_price, 'supplier_quote', $q->reference, $q->quoted_on));
            });

        Product::where('team_id', $teamId)->whereNotNull('supplier_id')->where('cost_price', '>', 0)
            ->when($productIds !== null, fn ($q) => $q->whereIn('id', $productIds))
            ->get(['id', 'supplier_id', 'cost_price'])
            ->each(function ($p) use ($best, $row) {
                $best->put($p->id.':'.$p->supplier_id, $best->get($p->id.':'.$p->supplier_id)
                    ?? $row($p->id, $p->supplier_id, $p->cost_price, 'inventory', null, null));
            });

        return $best;
    }

    /** The latest cost of one product from one supplier, or null when none is recorded. */
    public function for(int $teamId, int $productId, int $supplierId): ?array
    {
        return $this->latest($teamId, [$productId])->get($productId.':'.$supplierId);
    }
}
