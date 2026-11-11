<?php

namespace App\Services\Inventory;

use App\Models\Product;
use App\Models\ProductSupplier;
use App\Services\Forecast\SmaForecaster;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * AHP Product Prioritization: a weighted replenishment-priority score (0–100) per active product.
 * It ranks products; it never decides or authorizes a purchase.
 *
 * Each criterion is normalized to 0–100, multiplied by its fixed weight (config/evaluation.php),
 * and the weighted results are added. A criterion without enough data is reported as unavailable
 * with the reason: it is never treated as 0 and its weight is never moved to other criteria, so
 * such a product gets no final score or classification.
 *
 * The 0–100 weighted sum is also expressed on the 1–5 AHP priority scale (1 + 4 × score / 100);
 * the classification uses that 1–5 value (4.21–5.00 Very High — Reorder Immediately, …).
 */
class ProductPrioritizer
{
    public function __construct(private SmaForecaster $forecaster) {}

    /**
     * @param  int|null  $forecastMonths  forecasting period chosen by staff (3, 6 or 12): the SMA window and the months the reorder advice covers
     * @return array<int, array> keyed by product id, highest complete score first
     */
    public function forTeam(int $teamId, ?CarbonInterface $now = null, ?int $forecastMonths = null): array
    {
        $now = Carbon::instance($now ?? now());
        $config = config('evaluation.product_priority');
        $weights = $config['weights'];
        $months = SmaForecaster::window($forecastMonths ?? SmaForecaster::DEFAULT_WINDOW);

        $products = Product::where('team_id', $teamId)->where('is_active', true)
            ->orderBy('name')->get(['id', 'name', 'sku', 'stock', 'unit', 'reorder_level', 'reorder_quantity', 'created_at']);
        if ($products->isEmpty()) {
            return [];
        }
        $ids = $products->pluck('id')->all();

        $reserved = Product::batchReservedQuantities($ids);
        $forecasts = $this->forecaster->forProducts($teamId, $ids, $months, (int) $config['movement_months'], $now);
        $availability = $this->supplierAvailability($teamId, $ids);

        $available = [];
        foreach ($products as $p) {
            $available[$p->id] = max(0, (int) $p->stock - (int) ($reserved[$p->id] ?? 0));
        }

        // References for normalizing: the largest value among comparable (active) products with data.
        $maxStock = max($available);
        $maxStockProduct = $products->firstWhere('id', array_search($maxStock, $available, true));
        $forecastValues = array_filter(array_map(fn ($f) => $f['available'] ? $f['forecast'] : null, $forecasts), fn ($v) => $v !== null);
        $maxForecast = $forecastValues ? max($forecastValues) : null;
        $movementValues = array_map(fn ($f) => $f['movement_units'], array_filter($forecasts, fn ($f) => $f['has_records']));
        $maxMovement = $movementValues ? max($movementValues) : null;

        $results = [];
        foreach ($products as $p) {
            $f = $forecasts[$p->id];
            $criteria = [
                $this->demandCriterion($f, $maxForecast, $weights['demand']),
                $this->stockCriterion($p, $available[$p->id], (int) ($reserved[$p->id] ?? 0), $maxStock, $maxStockProduct, $weights['stock']),
                $this->movementCriterion($f, $maxMovement, $weights['movement']),
                $this->availabilityCriterion($availability[$p->id] ?? null, $weights['supplier_availability']),
            ];

            $complete = collect($criteria)->every(fn ($c) => $c['available']);
            $weightedSum = round(collect($criteria)->where('available', true)->sum('weighted'), 2);
            $missing = collect($criteria)->where('available', false)->pluck('label')->all();
            $band = $complete ? $this->band($weightedSum) : null;

            $results[$p->id] = [
                'product' => ['id' => $p->id, 'name' => $p->name, 'sku' => $p->sku, 'unit' => $p->unit ?: 'pcs'],
                'complete' => $complete,
                'score' => $complete ? $weightedSum : null,
                'partial_score' => $complete ? null : $weightedSum,
                'weight_available' => round(collect($criteria)->where('available', true)->sum('weight') * 100),
                'classification' => $band['label'] ?? null,
                'scale' => $complete ? [
                    'score' => $band['scale_score'],
                    'min' => $band['min'],
                    'max' => $band['max'],
                    'range' => number_format($band['min'], 2).'–'.number_format($band['max'], 2),
                    'action' => $band['action'],
                    'formula' => '1 + 4 × ('.number_format($weightedSum, 2).' / 100) = '.number_format($band['scale_score'], 2),
                ] : null,
                'missing' => $missing,
                'criteria' => $criteria,
                'final_formula' => $complete
                    ? implode(' + ', array_map(fn ($c) => number_format($c['weighted'], 2), $criteria)).' = '.number_format($weightedSum, 2)
                    : null,
                'available_stock' => $available[$p->id],
                'forecast' => [
                    'months' => $months,
                    'period' => SmaForecaster::PERIOD_LABELS[$months],
                    'monthly' => $f['forecast'],
                    'period_total' => $f['period_forecast'],
                    'reason' => $f['reason'],
                ],
                'reorder' => $this->reorderRecommendation($p, $available[$p->id], $f),
                'calculated_at' => $now->toIso8601String(),
            ];
        }

        uasort($results, fn ($a, $b) => [$b['complete'], $b['score'] ?? $b['partial_score']] <=> [$a['complete'], $a['score'] ?? $a['partial_score']]);

        return $results;
    }

    /** Classification of a 0–100 weighted score, via its 1–5 AHP scale value. */
    public function classify(float $score): string
    {
        return $this->band($score)['label'];
    }

    /** 0–100 weighted score → 1–5 AHP priority scale: 1 + 4 × (score / 100), rounded to 2 decimals. */
    public static function toScale(float $score): float
    {
        $scale = config('evaluation.product_priority.scale');
        $min = (float) $scale['min'];
        $max = (float) $scale['max'];

        return round(min($max, max($min, $min + ($max - $min) * ($score / 100))), 2);
    }

    /**
     * The 1–5 band of a 0–100 weighted score (bands from config/evaluation.php).
     *
     * @return array{label: string, action: ?string, min: float, max: float, scale_score: float}
     */
    public function band(float $score): array
    {
        $value = self::toScale($score);
        $classes = config('evaluation.product_priority.classes');
        foreach ($classes as $class) {
            if ($value >= (float) $class['min']) {
                return ['label' => $class['label'], 'action' => $class['action'] ?? null, 'min' => (float) $class['min'], 'max' => (float) $class['max'], 'scale_score' => $value];
            }
        }
        $last = end($classes);

        return ['label' => $last['label'], 'action' => $last['action'] ?? null, 'min' => (float) $last['min'], 'max' => (float) $last['max'], 'scale_score' => $value];
    }

    /**
     * Reorder recommendation from the product's own reorder settings and its SMA forecast.
     * Reorder when available stock is at or below the reorder level, or below the demand
     * forecast for the chosen period (SMA × months). Suggested quantity = the larger of the
     * reorder quantity and the forecast shortfall. It is advice only; purchasing still goes
     * through Procurement.
     */
    private function reorderRecommendation(Product $p, int $available, array $f): array
    {
        $level = (int) ($p->reorder_level ?? 0);
        $qty = (int) ($p->reorder_quantity ?? 0);
        $forecast = $f['available'] ? (int) $f['period_forecast'] : null;
        $shortfall = $forecast !== null ? max(0, $forecast - $available) : null;
        $period = SmaForecaster::PERIOD_LABELS[$f['window']] ?? "{$f['window']} months";

        $reasons = [];
        if ($available <= $level) {
            $reasons[] = "available stock {$available} ≤ reorder level {$level}";
        }
        if ($shortfall) {
            $reasons[] = "available stock {$available} < forecast demand {$forecast} for the next {$period}";
        }
        $needed = $reasons !== [];
        $suggested = $needed ? max($qty, (int) $shortfall) : 0;

        return [
            'needed' => $needed,
            'label' => $needed ? 'Reorder' : 'No reorder needed',
            'suggested_quantity' => $suggested,
            'reorder_level' => $level,
            'reorder_quantity' => $qty,
            'forecast' => $forecast,
            'shortfall' => $shortfall,
            'basis' => $needed
                ? 'Reorder because '.implode(' and ', $reasons).". Suggested = max(reorder quantity {$qty}, forecast shortfall ".($shortfall ?? 0).") = {$suggested}."
                : "Available stock {$available} is above the reorder level {$level}".($forecast !== null ? " and covers the forecast demand {$forecast} for the next {$period}" : ' (no SMA forecast: insufficient sales history)').'.',
        ];
    }

    private function demandCriterion(array $f, ?int $maxForecast, float $weight): array
    {
        $base = $this->criterion('demand', 'Demand Forecasting', $weight, 'SMA-'.$f['window'].' forecast from the Demand Forecasting module');
        $base['inputs'] = ['forecast' => $f['forecast'], 'max_forecast' => $maxForecast, 'months' => $f['inputs']];

        if (! $f['available']) {
            return $base + ['available' => false, 'score' => null, 'weighted' => null, 'reason' => $f['reason']];
        }

        $score = $maxForecast > 0 ? round($f['forecast'] / $maxForecast * 100, 2) : 0.0;

        return $base + $this->scored($score, $weight,
            '(Forecasted Demand / Maximum Forecasted Demand) × 100',
            $maxForecast > 0 ? "({$f['forecast']} / {$maxForecast}) × 100 = ".number_format($score, 2) : 'No comparable product has forecasted demand above 0, so the score is 0.00');
    }

    private function stockCriterion(Product $p, int $available, int $reserved, int $maxStock, ?Product $maxProduct, float $weight): array
    {
        $base = $this->criterion('stock', 'Current Stock', $weight, 'Inventory records (on hand minus units reserved for confirmed orders)');
        $base['inputs'] = ['on_hand' => (int) $p->stock, 'reserved' => $reserved, 'available' => $available, 'max_stock' => $maxStock, 'max_stock_product' => $maxProduct?->name];

        $score = $maxStock > 0 ? round((1 - $available / $maxStock) * 100, 2) : 100.0;

        return $base + $this->scored($score, $weight,
            '(1 − (Available Stock / Maximum Stock Reference)) × 100',
            $maxStock > 0 ? "(1 − ({$available} / {$maxStock})) × 100 = ".number_format($score, 2) : 'No active product has available stock, so every product scores 100.00');
    }

    private function movementCriterion(array $f, ?int $maxMovement, float $weight): array
    {
        $base = $this->criterion('movement', 'Product Movement', $weight, "Units sold or released in the last {$f['movement_months']} complete months");
        $base['inputs'] = ['units' => $f['movement_units'], 'max_units' => $maxMovement, 'months' => $f['movement_months'], 'record_count' => $f['movement_record_count']];

        if (! $f['has_records']) {
            return $base + ['available' => false, 'score' => null, 'weighted' => null, 'reason' => 'No sales or outgoing stock records exist for this product.'];
        }

        $score = $maxMovement > 0 ? round($f['movement_units'] / $maxMovement * 100, 2) : 0.0;

        return $base + $this->scored($score, $weight,
            '(Product Sales Quantity / Maximum Sales Quantity) × 100',
            $maxMovement > 0 ? "({$f['movement_units']} / {$maxMovement}) × 100 = ".number_format($score, 2) : 'No comparable product sold any units in the period, so the score is 0.00');
    }

    private function availabilityCriterion(?array $a, float $weight): array
    {
        $base = $this->criterion('supplier_availability', 'Supplier Availability', $weight, 'Verified supplier availability records (active suppliers only)');
        $base['inputs'] = $a ?? ['available' => 0, 'verified' => 0, 'suppliers' => []];

        if (! $a || $a['verified'] === 0) {
            return $base + ['available' => false, 'score' => null, 'weighted' => null, 'reason' => 'No verified supplier availability record exists for this product.'];
        }

        // Fewer suppliers able to supply means harder sourcing, so a higher priority.
        $ratio = round($a['available'] / $a['verified'] * 100, 2);
        $score = round(100 - $ratio, 2);

        return $base + $this->scored($score, $weight,
            '(1 − (Verified Available Suppliers / Verified Active Suppliers)) × 100',
            "(1 − ({$a['available']} / {$a['verified']})) × 100 = ".number_format($score, 2)." (availability ratio {$ratio}%)");
    }

    private function criterion(string $key, string $label, float $weight, string $source): array
    {
        return ['key' => $key, 'label' => $label, 'weight' => $weight, 'source' => $source];
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

    /** @return array<int, array{available: int, verified: int, suppliers: list<array>}> */
    private function supplierAvailability(int $teamId, array $productIds): array
    {
        $rows = ProductSupplier::query()
            ->join('suppliers', 'suppliers.id', '=', 'product_supplier.supplier_id')
            ->where('product_supplier.team_id', $teamId)
            ->whereIn('product_supplier.product_id', $productIds)
            ->whereNotNull('product_supplier.verified_at')
            ->whereNull('suppliers.deleted_at')
            ->where('suppliers.status', 'Active')
            ->get(['product_supplier.product_id', 'product_supplier.is_available', 'product_supplier.verified_at', 'suppliers.name']);

        $out = [];
        foreach ($rows as $r) {
            $out[$r->product_id] ??= ['available' => 0, 'verified' => 0, 'suppliers' => []];
            $out[$r->product_id]['verified']++;
            $out[$r->product_id]['available'] += $r->is_available ? 1 : 0;
            $out[$r->product_id]['suppliers'][] = [
                'name' => $r->name,
                'is_available' => (bool) $r->is_available,
                'verified_at' => Carbon::parse($r->verified_at)->toDateString(),
            ];
        }

        return $out;
    }
}
