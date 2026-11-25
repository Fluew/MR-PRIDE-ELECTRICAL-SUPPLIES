<?php

namespace App\Services\Forecast;

use App\Enums\ForecastPeriod;
use App\Enums\SalesOrderStatus;
use App\Models\Delivery;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Supplier;
use App\Services\Suppliers\SupplierEvaluator;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Top 5 suppliers and Top 5 most in-demand products for one week, month or year, for the Demand
 * Forecasting page. Read-only and calculated from recorded transactions only:
 *  - Suppliers: the AHP Supplier Evaluation (delivery performance, price competitiveness, quality,
 *    overall score) over the records dated in the period. A criterion without records in the
 *    period is "insufficient data", never 0.
 *  - Products: units sold and sales amount from confirmed sales in the period, net of cancelled
 *    quantities (the same sales the SMA forecast counts).
 *
 * The SMA forecast is not affected by anything here.
 */
class TopPerformers
{
    public const LIMIT = 5;

    public function __construct(private SupplierEvaluator $evaluator) {}

    /**
     * @param  int  $offset  0 = the current period (to date), 1 = the one before, …
     */
    public function for(int $teamId, ForecastPeriod $period, int $offset = 0, ?CarbonInterface $now = null): array
    {
        $now = Carbon::instance($now ?? now());
        $offset = max(0, $offset);
        $start = $period->advance($period->start($now), -$offset);
        $end = $offset === 0 ? $now->copy()->endOfDay() : $period->advance($start)->subDay()->endOfDay();
        $earliest = $this->earliestRecord($teamId);

        return [
            'period' => $period->value,
            'offset' => $offset,
            'label' => $period->label($start),
            'is_current' => $offset === 0,
            'from' => $start->toDateString(),
            'to' => $end->toDateString(),
            // Older periods can be browsed back to the first recorded sale or delivery.
            'has_earlier' => $earliest !== null && $start->gt($period->start($earliest)),
            'suppliers' => $this->suppliers($teamId, $start, $end),
            'products' => $this->products($teamId, $start, $end),
        ];
    }

    /**
     * Every supplier with at least one criterion in the period, with its criterion scores. The page
     * ranks them by the criterion chosen (overall AHP score by default) and shows the top 5.
     */
    private function suppliers(int $teamId, Carbon $from, Carbon $to): array
    {
        $rows = [];
        foreach ($this->evaluator->forRange($teamId, $from, $to) as $result) {
            $criteria = collect($result['criteria'])->keyBy('key');
            if ($criteria->every(fn ($c) => ! $c['available'])) {
                continue;
            }
            $rows[] = [
                'id' => $result['supplier']['id'],
                'name' => $result['supplier']['name'],
                'status' => $result['supplier']['status'],
                'delivery' => $criteria['delivery']['score'] ?? null,
                'price' => $criteria['price']['score'] ?? null,
                'quality' => $criteria['quality']['score'] ?? null,
                'overall' => $result['score'],
                'rank' => $result['rank'],
                'classification' => $result['classification'],
                'missing' => $result['missing'],
            ];
        }

        return $rows;
    }

    /** Top 5 products by units sold in the period (ties: higher sales amount, then name). */
    private function products(int $teamId, Carbon $from, Carbon $to): array
    {
        $net = 'CASE WHEN sale_items.quantity > COALESCE(sale_items.cancelled_quantity, 0) THEN sale_items.quantity - COALESCE(sale_items.cancelled_quantity, 0) ELSE 0 END';
        // The line amount for the units kept: subtotal (quantity × price − discount) × kept / ordered.
        $amount = "CASE WHEN sale_items.quantity > 0 THEN sale_items.subtotal * ($net) / sale_items.quantity ELSE 0 END";

        $top = SaleItem::query()
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->where('sales.team_id', $teamId)->whereNull('sales.deleted_at')
            ->whereIn('sales.status', SalesOrderStatus::countedInSales())
            ->whereNotNull('sale_items.product_id')
            ->whereBetween('sales.sale_date', [$from->toDateString(), $to->toDateString()])
            ->groupBy('sale_items.product_id')
            ->selectRaw("sale_items.product_id, SUM($net) as units, SUM($amount) as sales, MAX(sale_items.product_name) as sold_as")
            ->havingRaw("SUM($net) > 0")
            ->orderByDesc('units')->orderByDesc('sales')->orderBy('sale_items.product_id')
            ->limit(self::LIMIT)
            ->get();
        if ($top->isEmpty()) {
            return [];
        }

        $ids = $top->pluck('product_id')->all();
        $products = Product::withTrashed()->where('team_id', $teamId)->whereIn('id', $ids)
            ->get(['id', 'name', 'sku', 'unit', 'cost_price', 'supplier_id'])->keyBy('id');
        $suppliers = $this->productSuppliers($teamId, $products->all());

        return $top->values()->map(function ($row, $i) use ($products, $suppliers) {
            $p = $products->get($row->product_id);

            return [
                'rank' => $i + 1,
                'id' => (int) $row->product_id,
                'name' => $p?->name ?? $row->sold_as,
                'sku' => $p?->sku,
                'unit' => $p?->unit ?: 'pcs',
                'unit_cost' => $p?->cost_price === null ? null : (float) $p->cost_price,
                'suppliers' => $suppliers[$row->product_id] ?? [],
                'units_sold' => (int) $row->units,
                'total_sales' => round((float) $row->sales, 2),
            ];
        })->all();
    }

    /**
     * Supplier names per product: the product's main supplier first, then the suppliers recorded as
     * offering it (product–supplier availability).
     *
     * @param  array<int, Product>  $products
     * @return array<int, list<string>>
     */
    private function productSuppliers(int $teamId, array $products): array
    {
        $ids = array_keys($products);
        $offered = DB::table('product_supplier')->join('suppliers', 'suppliers.id', '=', 'product_supplier.supplier_id')
            ->where('product_supplier.team_id', $teamId)->whereIn('product_supplier.product_id', $ids)
            ->where('product_supplier.is_available', true)->whereNull('suppliers.deleted_at')
            ->orderBy('suppliers.name')->get(['product_supplier.product_id', 'suppliers.id', 'suppliers.name'])
            ->groupBy('product_id');
        $main = Supplier::where('team_id', $teamId)->whereIn('id', array_filter(array_map(fn ($p) => $p->supplier_id, $products)))
            ->pluck('name', 'id');

        $names = [];
        foreach ($products as $id => $p) {
            $list = $p->supplier_id && isset($main[$p->supplier_id]) ? [$main[$p->supplier_id]] : [];
            foreach ($offered->get($id, collect()) as $s) {
                $list[] = $s->name;
            }
            $names[$id] = array_values(array_unique($list));
        }

        return $names;
    }

    private function earliestRecord(int $teamId): ?Carbon
    {
        $dates = array_filter([
            Sale::where('team_id', $teamId)->whereIn('status', SalesOrderStatus::countedInSales())->min('sale_date'),
            Delivery::where('team_id', $teamId)->min('delivery_date'),
        ]);

        return $dates ? Carbon::parse(min($dates)) : null;
    }
}
