<?php

namespace App\Services\Suppliers;

/**
 * Performance flags for the Supplier page, derived from the Supplier Evaluation results
 * (SupplierEvaluator), i.e. from recorded deliveries, purchase prices / bidding quotes and
 * inspections in the evaluation period. Nothing is typed in or stored: flags are recalculated with
 * the evaluation, so they always match the scores shown beside them.
 *
 * Every flag compares suppliers with each other, so it needs at least two active suppliers with
 * the data (four for Fast / Slow, which are quartiles). Equal values share a flag. Inactive
 * suppliers are not flagged.
 */
class SupplierFlags
{
    /** Flag key => label, in display order. */
    public const FLAGS = [
        'best_overall' => 'Best Overall Supplier',
        'fastest_delivery' => 'Fastest Delivery',
        'fast_delivery' => 'Fast Delivery',
        'slow_delivery' => 'Slow Delivery',
        'lowest_price' => 'Lowest Price',
        'highest_price' => 'Highest Price',
        'best_quality' => 'Best Quality',
    ];

    private const MIN_COMPARED = 2;

    private const MIN_FOR_QUARTILES = 4;

    /**
     * @param  array<int, array>  $evaluations  SupplierEvaluator results keyed by supplier id
     * @return array<int, list<array{key: string, label: string, reason: string}>> keyed by supplier id (only flagged suppliers)
     */
    public function fromEvaluations(array $evaluations): array
    {
        $metrics = [];
        foreach ($evaluations as $id => $e) {
            if (($e['supplier']['status'] ?? 'Active') !== 'Active') {
                continue;
            }
            $criteria = collect($e['criteria'] ?? [])->keyBy('key');
            $delivery = $criteria['delivery']['inputs'] ?? [];
            $metrics[$id] = [
                'lead' => $delivery['average_lead_days'] ?? null,
                'lead_count' => (int) ($delivery['lead_time_deliveries'] ?? 0),
                'price' => ($criteria['price']['available'] ?? false) ? (float) $criteria['price']['score'] : null,
                'price_count' => count($criteria['price']['inputs']['comparisons'] ?? []),
                'quality' => ($criteria['quality']['available'] ?? false) ? (float) $criteria['quality']['score'] : null,
                'inspected' => (int) (($criteria['quality']['inputs']['accepted'] ?? 0) + ($criteria['quality']['inputs']['rejected'] ?? 0)),
                'overall' => $e['complete'] ? (float) $e['score'] : null,
            ];
        }

        $flags = [];
        $add = function (int $id, string $key, string $reason) use (&$flags) {
            $flags[$id][$key] = ['key' => $key, 'label' => self::FLAGS[$key], 'reason' => $reason];
        };
        // supplier id => value, for the suppliers that have it.
        $values = fn (string $field) => array_filter(array_map(fn ($m) => $m[$field], $metrics), fn ($v) => $v !== null);

        // Best overall: the highest complete AHP score.
        $overall = $values('overall');
        if (count($overall) >= self::MIN_COMPARED) {
            $best = max($overall);
            foreach ($overall as $id => $v) {
                if ($this->same($v, $best)) {
                    $add($id, 'best_overall', 'Highest AHP score ('.number_format($v, 2).') of '.count($overall).' evaluated suppliers.');
                }
            }
        }

        // Delivery speed: average days from order to delivery.
        $leads = $values('lead');
        if (count($leads) >= self::MIN_COMPARED) {
            $fastest = min($leads);
            $sorted = array_values($leads);
            sort($sorted);
            // Fast / Slow: the quickest and the slowest quarter (⌈n / 4⌉ suppliers each; ties included).
            $quartiles = count($sorted) >= self::MIN_FOR_QUARTILES;
            $quarter = (int) ceil(count($sorted) / 4);
            $p25 = $quartiles ? $sorted[$quarter - 1] : null;
            $p75 = $quartiles ? $sorted[count($sorted) - $quarter] : null;
            foreach ($leads as $id => $v) {
                $basis = 'Average '.number_format($v, 1).' days from order to delivery ('.$metrics[$id]['lead_count'].' deliveries)';
                if ($this->same($v, $fastest)) {
                    $add($id, 'fastest_delivery', "{$basis}: the shortest of ".count($leads).' suppliers.');
                }
                if ($quartiles && $v <= $p25 + 0.0001) {
                    $add($id, 'fast_delivery', "{$basis}: among the fastest quarter (≤ ".number_format($p25, 1).' days).');
                } elseif ($quartiles && $v >= $p75 - 0.0001 && $v > $fastest) {
                    $add($id, 'slow_delivery', "{$basis}: among the slowest quarter (≥ ".number_format($p75, 1).' days).');
                }
            }
        }

        // Price: price competitiveness (lowest price offered ÷ this supplier's price × 100, averaged).
        $prices = $values('price');
        if (count($prices) >= self::MIN_COMPARED) {
            [$low, $high] = [max($prices), min($prices)];
            foreach ($prices as $id => $v) {
                $basis = 'Price competitiveness '.number_format($v, 2).' over '.$metrics[$id]['price_count'].' price comparison(s)';
                if ($this->same($v, $low)) {
                    $add($id, 'lowest_price', "{$basis}: the most competitive of ".count($prices).' suppliers.');
                } elseif ($this->same($v, $high)) {
                    $add($id, 'highest_price', "{$basis}: the least competitive of ".count($prices).' suppliers.');
                }
            }
        }

        // Quality: accepted ÷ inspected quantity.
        $quality = $values('quality');
        if (count($quality) >= self::MIN_COMPARED) {
            $best = max($quality);
            foreach ($quality as $id => $v) {
                if ($this->same($v, $best)) {
                    $add($id, 'best_quality', 'Accepted '.number_format($v, 2).'% of '.number_format($metrics[$id]['inspected']).' inspected units: the best of '.count($quality).' suppliers.');
                }
            }
        }

        // In display order.
        return array_map(fn ($set) => array_values(array_filter(array_map(fn ($key) => $set[$key] ?? null, array_keys(self::FLAGS)))), $flags);
    }

    private function same(float $a, float $b): bool
    {
        return abs($a - $b) < 0.005;
    }
}
