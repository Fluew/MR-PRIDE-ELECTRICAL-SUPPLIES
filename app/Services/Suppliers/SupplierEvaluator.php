<?php

namespace App\Services\Suppliers;

use App\Models\Delivery;
use App\Models\Supplier;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * AHP-based Supplier Evaluation. Every score is calculated from recorded transactions only:
 * - Delivery Performance (40%): inbound deliveries against purchase orders with a promised date,
 *   and the accepted quantity recorded at inspection.
 * - Price Competitiveness (35%): purchase order unit costs of accepted inbound deliveries, compared
 *   with other suppliers' costs for the same product and unit.
 * - Product Quality (25%): accepted / inspected quantities from staff inspections.
 * Score = Σ (criterion score × AHP weight). Suppliers with a complete score are ranked (1 = best)
 * to help staff choose; nothing selects or orders from a supplier automatically. A criterion
 * without records is "insufficient data", never 0, and such a supplier gets no final score or
 * rank. Nobody can type a score in.
 */
class SupplierEvaluator
{
    /** @return array<int, array> keyed by supplier id; complete scores carry rank (1 = highest) and ranked_of */
    public function forTeam(int $teamId, ?CarbonInterface $now = null): array
    {
        $now = Carbon::instance($now ?? now());
        $periodStart = $now->copy()->subMonths((int) config('evaluation.supplier.period_months'))->startOfDay();

        $suppliers = Supplier::where('team_id', $teamId)->orderBy('name')->get(['id', 'name', 'status']);
        $deliveries = $this->deliveries($teamId, $periodStart, $now);
        $priceGroups = $this->purchaseCostGroups($teamId, $periodStart, $now);

        $results = [];
        foreach ($suppliers as $s) {
            $mine = $deliveries->where('supplier_id', $s->id);
            $results[$s->id] = $this->evaluate($s, $mine, $priceGroups, $periodStart, $now);
        }

        return $this->rank($results);
    }

    /**
     * Rank suppliers with a complete score, highest first. Equal scores share a rank (1, 2, 2, 4).
     * Suppliers without a complete score are not ranked (rank null).
     *
     * @param  array<int, array>  $results
     * @return array<int, array>
     */
    private function rank(array $results): array
    {
        $scores = collect($results)->filter(fn ($r) => $r['complete'])->map(fn ($r) => $r['score'])->sortDesc();
        $count = $scores->count();
        foreach ($results as $id => $r) {
            $results[$id]['rank'] = $r['complete'] ? $scores->filter(fn ($s) => $s > $r['score'])->count() + 1 : null;
            $results[$id]['ranked_of'] = $count;
        }

        return $results;
    }

    public function forSupplier(Supplier $supplier, ?CarbonInterface $now = null): array
    {
        return $this->forTeam($supplier->team_id, $now)[$supplier->id];
    }

    public function classify(float $score): string
    {
        foreach (config('evaluation.supplier.classes') as $min => $label) {
            if ($score >= $min) {
                return $label;
            }
        }

        return 'Needs Improvement';
    }

    private function evaluate(Supplier $s, Collection $deliveries, array $priceGroups, Carbon $from, Carbon $now): array
    {
        $weights = config('evaluation.supplier.weights');
        $criteria = [
            $this->deliveryCriterion($deliveries, $weights['delivery']),
            $this->priceCriterion($s->id, $priceGroups, $weights['price']),
            $this->qualityCriterion($deliveries, $weights['quality']),
        ];

        $complete = collect($criteria)->every(fn ($c) => $c['available']);
        $sum = round(collect($criteria)->where('available', true)->sum('weighted'), 2);

        return [
            'supplier' => ['id' => $s->id, 'name' => $s->name, 'status' => $s->status],
            'complete' => $complete,
            'score' => $complete ? $sum : null,
            'partial_score' => $complete ? null : $sum,
            'weight_available' => round(collect($criteria)->where('available', true)->sum('weight') * 100),
            'classification' => $complete ? $this->classify($sum) : null,
            'missing' => collect($criteria)->where('available', false)->pluck('label')->all(),
            'criteria' => $criteria,
            'final_formula' => $complete
                ? implode(' + ', array_map(fn ($c) => number_format($c['weighted'], 2), $criteria)).' = '.number_format($sum, 2)
                : null,
            'period' => ['from' => $from->toDateString(), 'to' => $now->toDateString()],
            'calculated_at' => $now->toIso8601String(),
        ];
    }

    private function deliveryCriterion(Collection $deliveries, float $weight): array
    {
        $split = config('evaluation.supplier.delivery_split');
        $timed = $deliveries->filter(fn ($d) => $d->promised_delivery_date !== null);
        $onTime = $timed->filter(fn ($d) => $d->delivery_date->lte($d->promised_delivery_date));

        // Quantity fulfillment per purchase order, from inspected deliveries (accepted units, capped at the ordered quantity).
        $byPo = $deliveries->filter(fn ($d) => $d->purchase_order_id && $d->accepted_quantity !== null)->groupBy('purchase_order_id');
        $ordered = 0;
        $accepted = 0;
        $poRows = [];
        foreach ($byPo as $poId => $rows) {
            $poQty = (int) $rows->first()->po_quantity;
            $acc = min($poQty, (int) $rows->sum('accepted_quantity'));
            $ordered += $poQty;
            $accepted += $acc;
            $poRows[] = ['po_number' => $rows->first()->po_number, 'ordered' => $poQty, 'accepted' => $acc];
        }

        $base = [
            'key' => 'delivery', 'label' => 'Inbound Delivery Performance', 'weight' => $weight,
            'source' => 'Inbound deliveries linked to purchase orders with a promised delivery date, and inspection results',
            'inputs' => [
                'completed_deliveries' => $timed->count(),
                'on_time_deliveries' => $onTime->count(),
                'ordered_quantity' => $ordered,
                'accepted_quantity' => $accepted,
                'excluded_without_promised_date' => $deliveries->count() - $timed->count(),
                'records' => $timed->map(fn ($d) => [
                    'delivery_number' => $d->delivery_number,
                    'po_number' => $d->po_number,
                    'promised' => $d->promised_delivery_date->toDateString(),
                    'delivered' => $d->delivery_date->toDateString(),
                    'on_time' => $d->delivery_date->lte($d->promised_delivery_date),
                ])->values()->all(),
                'purchase_orders' => $poRows,
            ],
        ];

        $reasons = [];
        if ($timed->isEmpty()) {
            $reasons[] = 'no completed delivery is linked to a purchase order with a promised delivery date';
        }
        if ($ordered === 0) {
            $reasons[] = 'no delivery against a purchase order has an inspection with accepted quantities';
        }
        if ($reasons) {
            return $base + ['available' => false, 'score' => null, 'weighted' => null, 'reason' => 'Insufficient data: '.implode('; ', $reasons).'.'];
        }

        $onTimeRate = round($onTime->count() / $timed->count() * 100, 2);
        $fulfillRate = round($accepted / $ordered * 100, 2);
        $score = round($onTimeRate * $split['on_time'] + $fulfillRate * $split['fulfillment'], 2);
        $base['inputs']['on_time_rate'] = $onTimeRate;
        $base['inputs']['fulfillment_rate'] = $fulfillRate;

        return $base + $this->scored($score, $weight,
            '(On-Time Rate × 0.60) + (Quantity Fulfillment Rate × 0.40)',
            "On-time: ({$onTime->count()} / {$timed->count()}) × 100 = ".number_format($onTimeRate, 2)
            ."; Fulfillment: ({$accepted} / {$ordered}) × 100 = ".number_format($fulfillRate, 2)
            .'; ('.number_format($onTimeRate, 2).' × 0.60) + ('.number_format($fulfillRate, 2).' × 0.40) = '.number_format($score, 2));
    }

    private function priceCriterion(int $supplierId, array $groups, float $weight): array
    {
        $comparisons = [];
        foreach ($groups as $g) {
            $mine = collect($g['prices'])->firstWhere('supplier_id', $supplierId);
            if (! $mine || count($g['prices']) < 2) {
                continue;
            }
            $lowest = min(array_column($g['prices'], 'unit_price'));
            $comparisons[] = [
                'product' => $g['product'],
                'basis' => $g['basis'],
                'own_price' => $mine['unit_price'],
                'lowest_price' => $lowest,
                'score' => round($lowest / $mine['unit_price'] * 100, 2),
                'prices' => $g['prices'],
            ];
        }

        $base = [
            'key' => 'price', 'label' => 'Price Competitiveness', 'weight' => $weight,
            'source' => 'Purchase order unit costs of accepted inbound deliveries in the evaluation period, for the same product and unit',
            'inputs' => ['comparisons' => $comparisons],
        ];

        if (! $comparisons) {
            return $base + ['available' => false, 'score' => null, 'weighted' => null,
                'reason' => 'Insufficient data: no accepted delivery from this supplier can be compared with another supplier\'s accepted delivery of the same product and unit.'];
        }

        $score = round(array_sum(array_column($comparisons, 'score')) / count($comparisons), 2);
        $parts = array_map(fn ($c) => '('.number_format($c['lowest_price'], 2).' / '.number_format($c['own_price'], 2).') × 100 = '.number_format($c['score'], 2), $comparisons);

        return $base + $this->scored($score, $weight,
            'Average over comparable products of (Lowest Purchase Unit Cost / Supplier\'s Purchase Unit Cost) × 100',
            implode('; ', $parts).(count($comparisons) > 1 ? '; average = '.number_format($score, 2) : ''));
    }

    private function qualityCriterion(Collection $deliveries, float $weight): array
    {
        $inspected = $deliveries->filter(fn ($d) => $d->accepted_quantity !== null && $d->rejected_quantity !== null);
        $acc = (int) $inspected->sum('accepted_quantity');
        $rej = (int) $inspected->sum('rejected_quantity');
        $total = $acc + $rej;

        $base = [
            'key' => 'quality', 'label' => 'Product Quality', 'weight' => $weight,
            'source' => 'Goods-receipt inspection records',
            'inputs' => [
                'inspections' => $inspected->count(),
                'accepted' => $acc,
                'rejected' => $rej,
                'records' => $inspected->map(fn ($d) => [
                    'delivery_number' => $d->delivery_number,
                    'inspected_on' => $d->inspected_on?->toDateString(),
                    'result' => $d->inspection_result,
                    'accepted' => (int) $d->accepted_quantity,
                    'rejected' => (int) $d->rejected_quantity,
                    'reason' => $d->rejection_reason,
                ])->values()->all(),
            ],
        ];

        if ($total === 0) {
            return $base + ['available' => false, 'score' => null, 'weighted' => null, 'reason' => 'Insufficient data: no goods-receipt inspection has been recorded for this supplier.'];
        }

        $score = round($acc / $total * 100, 2);

        return $base + $this->scored($score, $weight,
            '(Total Accepted Quantity / Total Inspected Quantity) × 100',
            "({$acc} / {$total}) × 100 = ".number_format($score, 2));
    }

    private function scored(float $score, float $weight, string $formula, string $substitution): array
    {
        $weighted = round($score * $weight, 2);

        return [
            'available' => true,
            'score' => $score,
            'weighted' => $weighted,
            'formula' => $formula,
            'substitution' => $substitution,
            'weighted_formula' => number_format($score, 2).' × '.number_format($weight, 2).' = '.number_format($weighted, 2),
            'reason' => null,
        ];
    }

    /**
     * Inbound deliveries in the period (not cancelled or archived), with their purchase order.
     * A delivery number recorded twice for the same purchase order counts once.
     */
    private function deliveries(int $teamId, Carbon $from, Carbon $to): Collection
    {
        return Delivery::query()
            ->leftJoin('purchase_orders', function ($j) {
                $j->on('purchase_orders.id', '=', 'deliveries.purchase_order_id')->whereNull('purchase_orders.deleted_at');
            })
            ->where('deliveries.team_id', $teamId)
            ->whereBetween('deliveries.delivery_date', [$from->toDateString(), $to->toDateString()])
            ->where(fn ($q) => $q->whereNull('deliveries.status')->orWhere('deliveries.status', '!=', 'Cancelled'))
            ->orderBy('deliveries.id')
            ->get([
                'deliveries.*',
                'purchase_orders.po_number',
                'purchase_orders.quantity as po_quantity',
                'purchase_orders.promised_delivery_date',
                'purchase_orders.supplier_id as po_supplier_id',
            ])
            ->each(function ($d) {
                $d->supplier_id = $d->supplier_id ?: $d->po_supplier_id;
                $d->promised_delivery_date = $d->promised_delivery_date ? Carbon::parse($d->promised_delivery_date) : null;
                if (! $d->purchase_order_id) {
                    $d->promised_delivery_date = null;
                }
            })
            ->unique(fn ($d) => ($d->purchase_order_id ?: 'none').'|'.mb_strtolower(trim($d->delivery_number)))
            ->values();
    }

    /**
     * Each supplier's purchase unit cost per product and unit, from accepted inbound deliveries
     * dated in the period: Σ (accepted × PO unit cost) / Σ accepted. Cancelled deliveries and
     * rejected units do not count.
     *
     * @return list<array{product: array, basis: string, prices: list<array{supplier_id: int, supplier: ?string, unit_price: float, quantity: int, receipts: list<array>}>}>
     */
    private function purchaseCostGroups(int $teamId, Carbon $from, Carbon $to): array
    {
        $rows = Delivery::query()
            ->join('purchase_orders', 'purchase_orders.id', '=', 'deliveries.purchase_order_id')
            ->join('suppliers', 'suppliers.id', '=', 'purchase_orders.supplier_id')
            ->join('products', 'products.id', '=', 'purchase_orders.product_id')
            ->where('deliveries.team_id', $teamId)
            ->where(fn ($q) => $q->whereNull('deliveries.status')->orWhere('deliveries.status', '!=', 'Cancelled'))
            ->where('deliveries.accepted_quantity', '>', 0)
            ->where('purchase_orders.unit_cost', '>', 0)
            ->whereNull('suppliers.deleted_at')
            ->whereBetween('deliveries.delivery_date', [$from->toDateString(), $to->toDateString()])
            ->orderBy('deliveries.delivery_date')->orderBy('deliveries.id')
            ->get([
                'deliveries.purchase_order_id', 'deliveries.delivery_number', 'deliveries.delivery_date', 'deliveries.accepted_quantity',
                'purchase_orders.po_number', 'purchase_orders.unit_cost', 'purchase_orders.unit_of_measure', 'purchase_orders.supplier_id', 'purchase_orders.product_id',
                'suppliers.name as supplier_name', 'products.name as product_name', 'products.sku as product_sku',
            ])
            // The same delivery recorded twice counts once (as in the delivery criterion).
            ->unique(fn ($d) => $d->purchase_order_id.'|'.mb_strtolower(trim((string) $d->delivery_number)));

        $groups = [];
        foreach ($rows as $r) {
            $unit = strtoupper(trim((string) ($r->unit_of_measure ?: 'PCS')));
            $key = "{$r->product_id}|{$unit}";
            $groups[$key] ??= ['product' => ['id' => $r->product_id, 'name' => $r->product_name, 'sku' => $r->product_sku], 'basis' => "per {$unit}", 'suppliers' => []];
            $groups[$key]['suppliers'][$r->supplier_id] ??= ['supplier_id' => (int) $r->supplier_id, 'supplier' => $r->supplier_name, 'quantity' => 0, 'amount' => 0.0, 'receipts' => []];
            $row = &$groups[$key]['suppliers'][$r->supplier_id];
            $row['quantity'] += (int) $r->accepted_quantity;
            $row['amount'] += (int) $r->accepted_quantity * (float) $r->unit_cost;
            $row['receipts'][] = ['delivery_number' => $r->delivery_number, 'delivery_date' => substr((string) $r->delivery_date, 0, 10), 'po_number' => $r->po_number,
                'quantity' => (int) $r->accepted_quantity, 'unit_cost' => (float) $r->unit_cost];
            unset($row);
        }

        return array_values(array_map(fn ($g) => [
            'product' => $g['product'],
            'basis' => $g['basis'],
            'prices' => array_values(array_map(fn ($r) => [
                'supplier_id' => $r['supplier_id'],
                'supplier' => $r['supplier'],
                'unit_price' => round($r['amount'] / $r['quantity'], 2),
                'quantity' => $r['quantity'],
                'receipts' => $r['receipts'],
            ], $g['suppliers'])),
        ], $groups));
    }
}
