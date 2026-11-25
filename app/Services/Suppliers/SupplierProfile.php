<?php

namespace App\Services\Suppliers;

use App\Models\Product;
use App\Models\Supplier;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * The Supplier Profile shown when a supplier is clicked: what it supplies and how it performs, in
 * plain figures. Built from the same records and the same evaluation as the AHP score and the
 * performance flags (SupplierEvaluator / SupplierFlags), so the three always agree. Read-only.
 */
class SupplierProfile
{
    public function __construct(private SupplierEvaluator $evaluator) {}

    /**
     * @param  array<int, array>  $evaluations  SupplierEvaluator::forTeam() results (all suppliers of the team)
     */
    public function for(Supplier $supplier, array $evaluations): array
    {
        $evaluation = $evaluations[$supplier->id];
        $from = Carbon::parse($evaluation['period']['from']);
        $to = Carbon::parse($evaluation['period']['to'])->endOfDay();
        $deliveries = $this->evaluator->deliveries($supplier->team_id, $from, $to)->where('supplier_id', $supplier->id)->values();
        $criteria = collect($evaluation['criteria'])->keyBy('key');
        $comparisons = $criteria['price']['inputs']['comparisons'] ?? [];
        $names = $this->productNames($supplier->team_id, $deliveries);

        return [
            'flags' => app(SupplierFlags::class)->fromEvaluations($evaluations)[$supplier->id] ?? [],
            'products' => $this->products($deliveries, $comparisons, $names),
            'delivery' => $this->delivery($deliveries, $criteria['delivery'], $names),
            'price' => $this->price($supplier->id, $comparisons, $criteria['price']),
            'quality' => $this->quality($deliveries, $criteria['quality'], $names),
            'ranking' => $this->ranking($evaluation, $evaluations),
        ];
    }

    /** Products this supplier delivered in the period, with what was ordered, received and paid. */
    private function products(Collection $deliveries, array $comparisons, array $names): array
    {
        $priced = collect($comparisons)->pluck('product.id')->filter()->all();
        $rows = [];
        foreach ($deliveries->groupBy(fn ($d) => $this->productKey($d)) as $key => $items) {
            $first = $items->first();
            $orders = $items->filter(fn ($d) => $d->purchase_order_id)->unique('purchase_order_id');
            $ordered = (int) $orders->sum('po_quantity');
            $cost = (float) $orders->sum(fn ($d) => (int) $d->po_quantity * (float) $d->po_unit_cost);
            $inspected = $items->filter(fn ($d) => $d->inspection_valid);
            $timed = $items->filter(fn ($d) => $d->promised_delivery_date !== null);

            $rows[] = [
                'key' => $key,
                'name' => $names[$key]['name'] ?? $first->description ?? 'Unnamed product',
                'sku' => $names[$key]['sku'] ?? $first->po_sku,
                'unit' => strtoupper((string) ($first->po_unit ?: $first->unit ?: 'PCS')),
                'unit_price' => $ordered > 0 ? round($cost / $ordered, 2) : null,
                'quantity_ordered' => $ordered,
                'units_received' => (int) $items->sum('quantity'),
                'units_accepted' => $inspected->isEmpty() ? null : (int) $inspected->sum('accepted_quantity'),
                'deliveries' => $items->count(),
                // Which parts of the score this product's records count in.
                'counts_in' => array_values(array_filter([
                    $timed->isNotEmpty() ? 'Delivery' : null,
                    $first->po_product_id && in_array((int) $first->po_product_id, $priced, true) ? 'Price' : null,
                    $inspected->isNotEmpty() ? 'Quality' : null,
                ])),
                'history' => $items->sortByDesc('delivery_date')->map(fn ($d) => [
                    'date' => $d->delivery_date->toDateString(),
                    'delivery_number' => $d->delivery_number,
                    'po_number' => $d->po_number,
                    'quantity' => (int) $d->quantity,
                    'status' => $this->onTimeStatus($d),
                ])->values()->all(),
            ];
        }
        usort($rows, fn ($a, $b) => $b['units_received'] <=> $a['units_received'] ?: strcmp($a['name'], $b['name']));

        return $rows;
    }

    /** Each delivery against its expected date, with the totals behind the delivery score and flags. */
    private function delivery(Collection $deliveries, array $criterion, array $names): array
    {
        $rows = $deliveries->sortByDesc('delivery_date')->map(function ($d) use ($names) {
            $days = $d->purchase_order_id && $d->po_start ? (int) $d->po_start->copy()->startOfDay()->diffInDays($d->delivery_date->copy()->startOfDay(), false) : null;

            return [
                'product' => $names[$this->productKey($d)]['name'] ?? $d->description,
                'delivery_number' => $d->delivery_number,
                'po_number' => $d->po_number,
                'po_date' => $d->po_start?->toDateString(),
                'expected' => $d->promised_delivery_date?->toDateString(),
                'delivered' => $d->delivery_date->toDateString(),
                'days' => $days !== null && $days >= 0 ? $days : null,
                'status' => $this->onTimeStatus($d),
            ];
        })->values();
        $timed = $rows->filter(fn ($r) => $r['expected'] !== null);
        $onTime = $timed->where('status', 'On time')->count();
        $inputs = $criterion['inputs'];

        return [
            'score' => $criterion['available'] ? $criterion['score'] : null,
            'reason' => $criterion['reason'],
            'average_days' => $inputs['average_lead_days'] ?? null,
            'total' => $rows->count(),
            'with_expected_date' => $timed->count(),
            'on_time' => $onTime,
            'late' => $timed->count() - $onTime,
            'on_time_rate' => $timed->isEmpty() ? null : round($onTime / $timed->count() * 100, 1),
            'fulfillment_rate' => $inputs['fulfillment_rate'] ?? null,
            'rows' => $rows->all(),
        ];
    }

    /** Each product compared with other suppliers' prices for the same product and unit. */
    private function price(int $supplierId, array $comparisons, array $criterion): array
    {
        $rows = array_map(function ($c) use ($supplierId) {
            $prices = collect($c['prices']);
            $own = (float) $c['own_price'];
            $lowest = (float) $c['lowest_price'];

            return [
                'product' => $c['product']['name'],
                'sku' => $c['product']['sku'] ?? null,
                'basis' => $c['source'] === 'bidding' ? 'Bidding quote, '.str_replace('bidding ', '', $c['basis']) : 'Purchase price '.$c['basis'],
                'own_price' => $own,
                'others' => $prices->where('supplier_id', '!=', $supplierId)->sortBy('unit_price')
                    ->map(fn ($p) => ['supplier' => $p['supplier'], 'price' => (float) $p['unit_price']])->values()->all(),
                'lowest_price' => $lowest,
                'difference' => round($own - $lowest, 2),
                'difference_pct' => $lowest > 0 ? round(($own - $lowest) / $lowest * 100, 1) : null,
                'average_price' => round($prices->avg('unit_price'), 2),
                'rank' => $prices->filter(fn ($p) => (float) $p['unit_price'] < $own - 0.0001)->count() + 1,
                'of' => $prices->count(),
                'score' => (float) $c['score'],
            ];
        }, $comparisons);

        return [
            'score' => $criterion['available'] ? $criterion['score'] : null,
            'reason' => $criterion['reason'],
            'compared' => count($rows),
            'cheapest' => count(array_filter($rows, fn ($r) => $r['rank'] === 1)),
            'rows' => $rows,
        ];
    }

    /** Inspection results, the acceptance rate and how it moved month by month. */
    private function quality(Collection $deliveries, array $criterion, array $names): array
    {
        $inspected = $deliveries->filter(fn ($d) => $d->inspection_valid)->sortByDesc('inspected_on');
        $rows = $inspected->map(fn ($d) => [
            'product' => $names[$this->productKey($d)]['name'] ?? $d->description,
            'delivery_number' => $d->delivery_number,
            'inspected_on' => $d->inspected_on->toDateString(),
            'result' => ucwords(str_replace('_', ' ', (string) $d->inspection_result)),
            'accepted' => (int) $d->accepted_quantity,
            'rejected' => (int) $d->rejected_quantity,
            'reason' => $d->rejection_reason,
        ])->values();
        $history = $inspected->groupBy(fn ($d) => $d->inspected_on->format('Y-m'))->sortKeys()->map(function ($items, $month) {
            $acc = (int) $items->sum('accepted_quantity');
            $total = $acc + (int) $items->sum('rejected_quantity');

            return ['month' => Carbon::parse("{$month}-01")->format('M Y'), 'accepted' => $acc, 'inspected' => $total, 'rate' => $total ? round($acc / $total * 100, 1) : null];
        })->values();

        return [
            'score' => $criterion['available'] ? $criterion['score'] : null,
            'reason' => $criterion['reason'],
            'products_inspected' => $rows->pluck('product')->unique()->count(),
            'accepted' => (int) $rows->sum('accepted'),
            'rejected' => (int) $rows->sum('rejected'),
            'rows' => $rows->all(),
            'history' => $history->all(),
        ];
    }

    /** Score × weight per criterion, the final score and where it places the supplier. */
    private function ranking(array $evaluation, array $evaluations): array
    {
        $ranked = collect($evaluations)->filter(fn ($e) => $e['complete'])->sortByDesc('score')->values();

        return [
            'complete' => $evaluation['complete'],
            'score' => $evaluation['score'],
            'rank' => $evaluation['rank'],
            'ranked_of' => $evaluation['ranked_of'],
            'classification' => $evaluation['classification'],
            'missing' => $evaluation['missing'],
            'criteria' => array_map(fn ($c) => ['label' => $c['label'], 'score' => $c['available'] ? $c['score'] : null, 'weight' => $c['weight'], 'weighted' => $c['weighted']], $evaluation['criteria']),
            'top' => $ranked->take(5)->map(fn ($e) => ['name' => $e['supplier']['name'], 'score' => $e['score'], 'rank' => $e['rank'], 'self' => $e['supplier']['id'] === $evaluation['supplier']['id']])->all(),
        ];
    }

    private function onTimeStatus($d): string
    {
        if (! $d->promised_delivery_date) {
            return 'No expected date';
        }
        $late = (int) $d->promised_delivery_date->copy()->startOfDay()->diffInDays($d->delivery_date->copy()->startOfDay(), false);

        return $late <= 0 ? 'On time' : "{$late} day".($late === 1 ? '' : 's').' late';
    }

    private function productKey($d): string
    {
        return $d->po_product_id ? 'p'.$d->po_product_id : 'd'.mb_strtolower(trim((string) ($d->po_description ?: $d->description)));
    }

    /** @return array<string, array{name: string, sku: ?string}> */
    private function productNames(int $teamId, Collection $deliveries): array
    {
        $ids = $deliveries->pluck('po_product_id')->filter()->unique()->all();

        return Product::withTrashed()->where('team_id', $teamId)->whereIn('id', $ids)->get(['id', 'name', 'sku'])
            ->mapWithKeys(fn ($p) => ['p'.$p->id => ['name' => $p->name, 'sku' => $p->sku]])->all();
    }
}
