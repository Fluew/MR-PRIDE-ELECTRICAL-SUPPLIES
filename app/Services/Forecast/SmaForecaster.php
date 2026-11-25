<?php

namespace App\Services\Forecast;

use App\Support\CalculationCache;
use App\Enums\ForecastPeriod;
use App\Enums\SalesOrderStatus;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\StockMovement;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * The Demand Forecasting module's Simple Moving Average: the single source of forecasted demand
 * (the Forecast page and AHP Product Prioritization both read it).
 *
 * Linked sales count once, in the order month, net of cancelled quantities. Their stock releases
 * remain traceable but add no demand. Legacy unlinked records retain the existing larger-of-
 * sales-and-movements rule, applied PER PRODUCT before aggregation. Their overlap is unknowable.
 * SMA uses complete preceding months. Opening inventory establishes observed zero-demand history;
 * products with neither inventory history nor demand remain insufficient.
 */
class SmaForecaster
{
    /** Aggregate in SQL; memory use is bounded by products × periods, not sale items. */
    public function periodProducts(int $teamId, array $productIds, ForecastPeriod $period, int $historyPeriods = 60, int $horizon = 3, int|string $window = 'auto', ?CarbonInterface $asOf = null): array
    {
        // Cached until the team's records change (see CalculationCache).
        return CalculationCache::remember($teamId, 'period-products', [$productIds, $period->value, $historyPeriods, $horizon, $window, $asOf ? Carbon::instance($asOf)->toDateTimeString() : null],
            fn () => $this->calculatePeriodProducts($teamId, $productIds, $period, $historyPeriods, $horizon, $window, $asOf));
    }

    private function calculatePeriodProducts(int $teamId, array $productIds, ForecastPeriod $period, int $historyPeriods, int $horizon, int|string $window, ?CarbonInterface $asOf): array
    {
        if ($historyPeriods < 3 || $historyPeriods > 240 || $horizon < 1 || $horizon > 24 || ($window !== 'auto' && ! in_array((int) $window, self::WINDOWS, true))) {
            throw new \InvalidArgumentException('Invalid forecast configuration.');
        }
        if (! $productIds) {
            return [];
        }
        $target = $period->start($asOf ?? now());
        $from = $period->advance($target, -$historyPeriods);
        // The query also reads the current, still-running period; it is shown as "in progress" and never feeds the model.
        $current = $period->advance($target);
        $products = Product::where('team_id', $teamId)->whereIn('id', $productIds)->get(['id', 'created_at']);
        $ids = $products->pluck('id')->all();
        $history = $this->productsWithAnyDemand($teamId, $ids, $target->copy()->subSecond());
        $driver = (new Product)->getConnection()->getDriverName();
        $salePeriod = $period->sql('sales.sale_date', $driver);
        $movementPeriod = $period->sql('movement_date', $driver);
        $linked = 'EXISTS (SELECT 1 FROM stock_movements sm WHERE sm.sale_id = sales.id AND sm.product_id = sale_items.product_id AND sm.team_id = sales.team_id)';
        $net = 'CASE WHEN sale_items.quantity > COALESCE(sale_items.cancelled_quantity, 0) THEN sale_items.quantity - COALESCE(sale_items.cancelled_quantity, 0) ELSE 0 END';
        $sales = SaleItem::query()->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->where('sales.team_id', $teamId)->whereNull('sales.deleted_at')
            ->whereIn('sales.status', SalesOrderStatus::countedInSales())
            ->whereIn('sale_items.product_id', $ids)->where('sales.sale_date', '>=', $from)->where('sales.sale_date', '<', $current)
            ->selectRaw("sale_items.product_id, $salePeriod as period_start, SUM(CASE WHEN $linked THEN $net ELSE 0 END) as linked_units, SUM(CASE WHEN $linked THEN 0 ELSE $net END) as legacy_units")
            ->groupBy('sale_items.product_id')->groupByRaw($salePeriod)->get();
        $units = [];
        foreach ($sales as $row) {
            $units[$row->product_id][$row->period_start] = ['linked' => (int) $row->linked_units, 'sales' => (int) $row->legacy_units, 'movements' => 0];
        }
        $movements = StockMovement::where('team_id', $teamId)->whereIn('product_id', $ids)
            ->whereNull('sale_id')->whereIn('type', self::DEMAND_MOVEMENT_TYPES)
            ->where('movement_date', '>=', $from)->where('movement_date', '<', $current)
            ->selectRaw("product_id, $movementPeriod as period_start, SUM(ABS(quantity)) as units")
            ->groupBy('product_id')->groupByRaw($movementPeriod)->get();
        foreach ($movements as $row) {
            $units[$row->product_id][$row->period_start]['movements'] = (int) $row->units;
        }
        $result = [];
        foreach ($products as $product) {
            $observed = isset($history[$product->id]) ? Carbon::parse($history[$product->id]) : null;
            if ($observed && $product->created_at && $product->created_at->lt($observed)) {
                $observed = $product->created_at->copy();
            }
            $start = $observed ? $period->start($observed) : $target->copy();
            if ($observed && $observed->gt($start)) {
                $start = $period->advance($start);
            }
            $start = $start->max($from);
            $points = [];
            for ($date = $start->copy(); $date->lt($target); $date = $period->advance($date)) {
                $row = $units[$product->id][$date->toDateString()] ?? [];
                $points[] = [
                    'start' => $date->toDateString(),
                    'end' => $period->advance($date)->subDay()->toDateString(),
                    'label' => $period->label($date),
                    'actual' => ($row['linked'] ?? 0) + max($row['sales'] ?? 0, $row['movements'] ?? 0),
                ];
            }
            $analysis = $this->evaluatePeriods($points, $window);
            $selected = $analysis['selected_window'];
            $values = array_column($points, 'actual');
            $row = $units[$product->id][$target->toDateString()] ?? [];
            $inProgress = ['start' => $target->toDateString(), 'end' => $current->copy()->subDay()->toDateString(), 'label' => $period->label($target), 'actual' => ($row['linked'] ?? 0) + max($row['sales'] ?? 0, $row['movements'] ?? 0)];
            $mean = $selected !== null && count($values) >= $selected ? array_sum(array_slice($values, -$selected)) / $selected : null;
            $future = [];
            for ($i = 0; $i < $horizon; $i++) {
                $date = $period->advance($target, $i + 1);
                $future[] = ['start' => $date->toDateString(), 'end' => $period->advance($date)->subDay()->toDateString(), 'label' => $period->label($date), 'forecast' => $mean];
            }
            $result[$product->id] = array_merge($analysis, [
                'period' => $period->value, 'history_periods' => count($points), 'horizon' => $horizon,
                'available' => $mean !== null, 'forecast' => $mean, 'total_forecast' => $mean === null ? null : $mean * $horizon,
                'reason' => $mean === null ? 'Insufficient complete historical periods for the selected SMA window.' : null,
                'historical' => $points, 'future' => $future, 'in_progress' => $inProgress,
            ]);
        }

        return $result;
    }

    /** Chronological validation selects the window; the later holdout never selects it. */
    public function evaluatePeriods(array $points, int|string $window = 'auto'): array
    {
        $values = array_column($points, 'actual');
        $count = count($values);
        $eligible = array_values(array_filter(self::WINDOWS, fn ($w) => $count >= $w + 2));
        $start = $eligible ? max($eligible) : $count;
        $split = $start + max(1, (int) floor(($count - $start) * 0.7));
        $configs = [];
        foreach (self::WINDOWS as $w) {
            $validation = [];
            $test = [];
            if (in_array($w, $eligible, true)) {
                for ($i = $start; $i < $count; $i++) {
                    $prediction = array_sum(array_slice($values, $i - $w, $w)) / $w;
                    $point = array_merge($points[$i], ['forecast' => $prediction]);
                    if ($i < $split) {
                        $validation[] = $point;
                    } else {
                        $test[] = $point;
                    }
                }
            }
            $configs[$w] = ['window' => $w, 'available' => $count >= $w, 'validation' => $this->errorMetrics($validation), 'test' => $this->errorMetrics($test)];
        }
        $ranked = $eligible;
        usort($ranked, function ($a, $b) use ($configs) {
            $left = $configs[$a]['validation'];
            $right = $configs[$b]['validation'];

            return [$left['mape'] ?? $left['mae'], $left['rmse'], $a] <=> [$right['mape'] ?? $right['mae'], $right['rmse'], $b];
        });
        $best = $ranked[0] ?? null;
        $available = array_values(array_filter(self::WINDOWS, fn ($w) => $count >= $w));
        $selected = $window === 'auto' ? ($best ?? ($available[0] ?? null)) : (int) $window;
        $metrics = $selected ? ($configs[$selected]['test'] ?? $this->errorMetrics([])) : $this->errorMetrics([]);

        return [
            'selected_window' => $selected, 'best_window' => $best, 'selection_validated' => $best !== null,
            'configurations' => $configs, 'accuracy' => $metrics,
            'validation_start' => $eligible ? $points[$start]['start'] : null,
            'test_start' => $eligible ? $points[$split]['start'] : null,
            'demand_pattern' => $this->demandPattern($values),
            'accuracy_band' => $metrics['mape'] === null ? 'Not evaluable' : ($metrics['mape'] <= 10 ? 'Target met' : ($metrics['mape'] > 30 ? 'Low accuracy' : 'Below target')),
        ];
    }

    /**
     * Intermittent = at least half of the observed periods had no demand. MAPE is unreliable for these
     * (it ignores the zero periods), so the UI points staff to WAPE and MAE instead.
     *
     * @return array{label: string, zero_share: float|null, intermittent: bool}
     */
    public function demandPattern(array $values): array
    {
        $count = count($values);
        if ($count < 3) {
            return ['label' => 'Insufficient history', 'zero_share' => null, 'intermittent' => false];
        }
        $share = count(array_filter($values, fn ($v) => $v == 0)) / $count;

        return ['label' => $share >= 0.5 ? 'Intermittent' : 'Regular', 'zero_share' => round($share * 100, 1), 'intermittent' => $share >= 0.5];
    }

    public function errorMetrics(array $points): array
    {
        $absolute = 0.0;
        $squared = 0.0;
        $percentage = 0.0;
        $nonzero = 0;
        $actualTotal = 0.0;
        foreach ($points as $point) {
            $error = abs($point['actual'] - $point['forecast']);
            $actualTotal += abs($point['actual']);
            $absolute += $error;
            $squared += $error * $error;
            if ($point['actual'] != 0) {
                $percentage += $error / abs($point['actual']) * 100;
                $nonzero++;
            }
        }
        $count = count($points);
        $mape = $nonzero ? $percentage / $nonzero : null;

        return [
            'count' => $count, 'mae' => $count ? $absolute / $count : null,
            'rmse' => $count ? sqrt($squared / $count) : null, 'mape' => $mape,
            // WAPE = total absolute error / total actual demand. Unlike MAPE it stays defined and stable when some periods are zero.
            'wape' => $actualTotal > 0 ? $absolute / $actualTotal * 100 : null, 'total_actual' => $actualTotal, 'total_abs_error' => $absolute,
            'mape_count' => $nonzero, 'zero_actual_count' => $count - $nonzero,
            'accuracy_indicator' => $mape === null ? null : max(0, 100 - $mape),
            'target_met' => $mape === null ? null : $mape <= 10, 'comparisons' => $points,
        ];
    }

    /** Adapt period aggregates to the existing dashboard's three chart metrics. */
    public function periodAnalytics(int $teamId, array $analyses, ForecastPeriod $period, array $options): array
    {
        $target = $period->start(now());
        $points = [];
        foreach ($analyses as $analysis) {
            foreach ($analysis['historical'] as $point) {
                $key = $point['start'];
                $points[$key] ??= ['start' => $key, 'end' => $point['end'], 'label' => $point['label'], 'units_sold' => 0, 'products_involved' => 0, 'sales_transactions' => 0];
                $points[$key]['units_sold'] += $point['actual'];
                $points[$key]['products_involved'] += $point['actual'] > 0 ? 1 : 0;
            }
        }
        ksort($points);
        // The running period: shown on the chart for visibility, never used to train, validate or forecast.
        $currentKey = $target->toDateString();
        $partial = ['start' => $currentKey, 'label' => $period->label($target), 'units_sold' => 0, 'products_involved' => 0, 'sales_transactions' => 0];
        foreach ($analyses as $analysis) {
            $running = $analysis['in_progress']['actual'] ?? 0;
            $partial['units_sold'] += $running;
            $partial['products_involved'] += $running > 0 ? 1 : 0;
        }
        if ($analyses) {
            $partialSql = $period->sql('sales.sale_date', (new Sale)->getConnection()->getDriverName());
            $partial['sales_transactions'] = (int) Sale::query()->where('sales.team_id', $teamId)->whereIn('sales.status', SalesOrderStatus::countedInSales())
                ->where('sales.sale_date', '>=', $target)->where('sales.sale_date', '<', $period->advance($target))
                ->whereHas('items', fn ($q) => $q->whereIn('product_id', array_keys($analyses))->whereColumn('quantity', '>', 'cancelled_quantity'))
                ->count();
        }
        if ($points) {
            $sql = $period->sql('sales.sale_date', (new Sale)->getConnection()->getDriverName());
            $transactions = Sale::query()->where('sales.team_id', $teamId)->whereIn('sales.status', SalesOrderStatus::countedInSales())
                ->where('sales.sale_date', '>=', array_key_first($points))->where('sales.sale_date', '<', $target)
                ->whereHas('items', fn ($q) => $q->whereIn('product_id', array_keys($analyses))->whereColumn('quantity', '>', 'cancelled_quantity'))
                ->selectRaw("$sql as period_start, COUNT(*) as total")->groupByRaw($sql)->get();
            foreach ($transactions as $row) {
                if (isset($points[$row->period_start])) {
                    $points[$row->period_start]['sales_transactions'] = (int) $row->total;
                }
            }
        }
        $displayFrom = $options['from_date'] ?? $period->advance($target, -(int) ($options['display_periods'] ?? 12))->toDateString();
        $displayTo = $options['to_date'] ?? $target->copy()->subDay()->toDateString();
        $metricSeries = [];
        $summaries = [];
        $backtests = [];
        foreach (['sales_transactions', 'products_involved', 'units_sold'] as $metric) {
            $history = array_values(array_map(fn ($point) => $point + ['actual' => $point[$metric]], $points));
            $evaluation = $this->evaluatePeriods($history, $options['window']);
            $window = $evaluation['selected_window'] ?? 3;
            $available = count($history) >= $window;
            $inputs = array_map(fn ($p) => ['month' => $p['label'], 'value' => $p['actual']], array_slice($history, -$window));
            $mean = $available ? array_sum(array_column($inputs, 'value')) / $window : null;
            $summary = ['available' => $available, 'window' => $window, 'sum' => array_sum(array_column($inputs, 'value')), 'decimal' => $mean, 'rounded' => $mean === null ? null : (int) round($mean), 'period_total' => $mean === null ? null : $mean * (int) $options['horizon'], 'inputs' => $inputs, 'reason' => $available ? null : 'Insufficient complete historical periods.'];
            $accuracy = $evaluation['accuracy'];
            $comparisons = array_map(fn ($p) => ['month' => $p['label'], 'ym' => $p['start'], 'actual' => $p['actual'], 'predicted_decimal' => $p['forecast'], 'predicted_rounded' => (int) round($p['forecast']), 'abs_error_decimal' => abs($p['actual'] - $p['forecast']), 'abs_error_rounded' => abs($p['actual'] - round($p['forecast'])), 'ape' => $p['actual'] ? abs($p['actual'] - $p['forecast']) / $p['actual'] * 100 : null], $accuracy['comparisons']);
            $totalActual = array_sum(array_column($comparisons, 'actual'));
            $totalError = array_sum(array_column($comparisons, 'abs_error_decimal'));
            $backtest = $accuracy + ['months' => $accuracy['count'], 'decimal_mae' => $accuracy['mae'], 'decimal_mape' => $accuracy['mape'], 'wape' => $totalActual ? $totalError / $totalActual * 100 : null, 'decimal_wape' => $totalActual ? $totalError / $totalActual * 100 : null, 'mape_months' => $accuracy['mape_count'], 'zero_actual_months' => $accuracy['zero_actual_count'], 'total_actual' => $totalActual, 'total_abs_error' => $totalError];
            $backtest['comparisons'] = $comparisons;
            $predictions = array_column($accuracy['comparisons'], 'forecast', 'start');
            $chartPoints = [];
            foreach ($history as $point) {
                if ($point['end'] < $displayFrom || $point['start'] > $displayTo) {
                    continue;
                }
                $predicted = $predictions[$point['start']] ?? null;
                $chartPoints[] = ['month' => $point['label'], 'ym' => $point['start'], 'is_forecast' => false, 'status' => 'complete', 'actual' => $point['actual'], 'sma_decimal' => $predicted, 'sma_rounded' => $predicted === null ? null : (int) round($predicted), 'inputs' => []];
            }
            if ($history && ! ($options['to_date'] ?? null)) {
                $chartPoints[] = ['month' => $partial['label'].' (in progress)', 'ym' => $currentKey, 'is_forecast' => false, 'status' => 'in_progress', 'actual' => null, 'partial_actual' => $partial[$metric], 'sma_decimal' => null, 'sma_rounded' => null, 'inputs' => []];
            }
            for ($i = 1; $i <= (int) $options['horizon']; $i++) {
                $date = $period->advance($target, $i);
                $chartPoints[] = ['month' => $period->label($date), 'ym' => $date->toDateString(), 'is_forecast' => true, 'status' => $available ? 'forecast' : 'insufficient_history', 'actual' => null, 'sma_decimal' => $mean, 'sma_rounded' => $summary['rounded'], 'inputs' => $inputs];
            }
            $metricSeries[$metric] = ['metric' => $metric, 'labels' => array_column($chartPoints, 'month'), 'months' => array_column($chartPoints, 'ym'), 'actual' => array_column($chartPoints, 'actual'), 'partial_actual' => array_map(fn ($p) => $p['partial_actual'] ?? null, $chartPoints), 'sma_decimal' => array_column($chartPoints, 'sma_decimal'), 'sma_rounded' => array_column($chartPoints, 'sma_rounded'), 'next_month_projection' => array_map(fn ($p) => $p['is_forecast'] ? $p['sma_decimal'] : null, $chartPoints), 'next_month_forecast' => $summary, 'points' => $chartPoints, 'backtest' => $backtest];
            $summaries[$metric] = $summary;
            $backtests[$metric] = $backtest;
        }
        $window = $summaries['units_sold']['window'];
        $label = 'SMA '.$window.' '.match ($period) {
            ForecastPeriod::Weekly => 'weeks', ForecastPeriod::Monthly => 'months', ForecastPeriod::Annual => 'years'
        };
        $range = $period->label($period->advance($target, -$window)).' – '.$period->label($period->advance($target, -1));

        return ['targetMonth' => $period->label($period->advance($target)), 'lastCompletedMonth' => $period->label($period->advance($target, -1)), 'selectedWindow' => $window, 'selectedWindowLabel' => $label, 'windowRangeLabel' => $range, 'eligibleProductCount' => count($analyses), 'hasHistory' => (bool) $points, 'metricSeries' => $metricSeries, 'backtest' => $backtests, 'currentSummary' => $summaries, 'fromDate' => $displayFrom, 'toDate' => $displayTo];
    }

    public const WINDOWS = [3, 6, 12];

    public const DEFAULT_WINDOW = 3;

    /** How staff see the forecasting periods: the months averaged and the months forecast ahead. */
    public const PERIOD_LABELS = [3 => '3 Months', 6 => '6 Months', 12 => '1 Year'];

    public const DEMAND_MOVEMENT_TYPES = ['sale', 'sale_release', 'out'];

    public static function window(mixed $value): int
    {
        $value = (int) $value;

        return in_array($value, self::WINDOWS, true) ? $value : self::DEFAULT_WINDOW;
    }

    /**
     * Demand series and SMA for the Forecast page (a product, a category, or everything).
     *
     * @return array{labels: list<string>, months: list<string>, historical: list<int>, forecast: list<int|null>, hasData: bool, steps: list<array>, sourceRecords: list<array>, sourceRecordCount: int}
     */
    public function series(int $teamId, CarbonInterface $from, CarbonInterface $to, int $window, string $category = 'All', ?int $productId = null): array
    {
        // Cached until the team's records change (see CalculationCache).
        return CalculationCache::remember($teamId, 'sma-series', [Carbon::instance($from)->toDateTimeString(), Carbon::instance($to)->toDateTimeString(), $window, $category, $productId],
            fn () => $this->calculateSeries($teamId, $from, $to, $window, $category, $productId));
    }

    private function calculateSeries(int $teamId, CarbonInterface $from, CarbonInterface $to, int $window, string $category, ?int $productId): array
    {
        $window = self::window($window);
        $from = Carbon::instance($from)->copy();
        $to = Carbon::instance($to)->copy();
        if ($from->gt($to)) {
            throw new \InvalidArgumentException('The forecast start must not follow its end.');
        }
        $periods = $this->months($from, $to);
        $scope = fn (Builder $q, string $productColumn) => $productId
            ? $q->where($productColumn, $productId)
            : ($category !== 'All' && $category !== '' ? $q->whereHas('product', fn ($p) => $p->where('category', $category)) : $q);

        $records = $this->records($teamId, $from->copy()->startOfDay(), $to->copy()->endOfDay()->min(now()), $scope);
        $ids = Product::where('team_id', $teamId)->when($productId, fn ($q) => $q->whereKey($productId))
            ->when(! $productId && $category !== 'All' && $category !== '', fn ($q) => $q->where('category', $category))->pluck('id')->all();
        // Same end as the records above: the whole last day (to now), not its first moment.
        $history = $this->productsWithAnyDemand($teamId, $ids, $to->copy()->endOfDay()->min(now()));
        $created = Product::where('team_id', $teamId)->whereIn('id', array_keys($history))->min('created_at');
        $firstHistory = $history ? Carbon::parse(min(array_filter([min($history), $created])))->startOfMonth() : null;
        $byMonth = $this->totalsByMonth($records);

        $historical = [];
        foreach ($periods as $p) {
            $t = $byMonth[$p->format('Y-m')] ?? ['movements' => 0, 'sales' => 0];
            $historical[] = max($t['movements'], $t['sales']);
        }

        $forecast = array_fill(0, count($periods), null);
        $steps = [];
        for ($i = $window; $i < count($periods); $i++) {
            if (! $firstHistory || $firstHistory->gt($periods[$i - $window]) || $from->gt($periods[$i - $window]) || $periods[$i]->gt(Carbon::today()->startOfMonth())) {
                continue;
            }
            $slice = array_slice($historical, $i - $window, $window);
            $forecast[$i] = (int) round(array_sum($slice) / $window);
            $steps[] = [
                'month' => $periods[$i]->format('M Y'),
                'inputs' => array_map(fn ($k) => ['month' => $periods[$k]->format('M Y'), 'units' => $historical[$k]], range($i - $window, $i - 1)),
                'sum' => array_sum($slice),
                'window' => $window,
                'forecast' => $forecast[$i],
            ];
        }

        usort($records, fn ($a, $b) => strcmp($b['date'], $a['date']));

        return [
            'labels' => array_map(fn ($d) => $d->format('M Y'), $periods),
            'months' => array_map(fn ($d) => $d->format('Y-m'), $periods),
            'historical' => $historical,
            'forecast' => $forecast,
            'hasData' => $firstHistory !== null,
            'accuracy' => $this->accuracy($periods, $historical, $forecast, $to),
            'steps' => $steps,
            'sourceRecords' => array_slice($records, 0, 200),
            'sourceRecordCount' => count($records),
        ];
    }

    /**
     * Next-month forecast per product, from the `window` complete months before the current one.
     * A product is insufficient when it has no demand record ever, or did not exist for the whole window.
     *
     * @param  list<int>  $productIds
     * @return array<int, array{available: bool, forecast: int|null, window: int, inputs: list<array>, reason: string|null, movement_units: int, movement_months: int, movement_record_count: int}>
     */
    public function forProducts(int $teamId, array $productIds, int $window = self::DEFAULT_WINDOW, int $movementMonths = 12, ?CarbonInterface $today = null, bool $optimize = false): array
    {
        $window = self::window($window);
        $movementMonths = max(1, $movementMonths);
        $today = Carbon::instance($today ?? Carbon::today())->startOfDay();
        $targetMonth = $today->copy()->startOfMonth();
        $windowStart = $targetMonth->copy()->subMonths($window);
        $movementStart = $targetMonth->copy()->subMonths($movementMonths);
        $maxWindowStart = $targetMonth->copy()->subMonths(max(12, $window, $movementMonths));
        $lastComplete = $targetMonth->copy()->subDay()->endOfDay();
        $earliest = $windowStart->copy()->min($movementStart)->min($maxWindowStart);

        $records = $this->records($teamId, $earliest, $lastComplete, fn (Builder $q, string $col) => $q->whereIn($col, $productIds), aggregate: true);
        $everHad = $this->productsWithAnyDemand($teamId, $productIds, $lastComplete);

        $byProduct = [];
        foreach ($records as $r) {
            $byProduct[$r['product_id']][] = $r;
        }

        $products = Product::where('team_id', $teamId)->whereIn('id', $productIds)->get(['id', 'created_at'])->keyBy('id');
        $result = [];

        foreach ($productIds as $id) {
            $totals = $this->totalsByMonth($byProduct[$id] ?? []);
            $monthUnits = fn (Carbon $m) => max($totals[$m->format('Y-m')]['movements'] ?? 0, $totals[$m->format('Y-m')]['sales'] ?? 0);

            // History starts at whichever is earlier: the product being added or its first demand record.
            $historyStart = isset($everHad[$id]) ? Carbon::parse($everHad[$id]) : null;
            $created = $products->get($id)?->created_at;
            if ($historyStart && $created && $created->lt($historyStart)) {
                $historyStart = $created->copy();
            }

            $windowsData = [];
            foreach (self::WINDOWS as $w) {
                $wStart = $targetMonth->copy()->subMonths($w);
                $wInputs = [];
                for ($m = $wStart->copy(); $m->lt($targetMonth); $m->addMonth()) {
                    $wInputs[] = ['month' => $m->format('M Y'), 'units' => $monthUnits($m)];
                }
                $wReason = null;
                if (! $historyStart) {
                    $wReason = 'No sales or outgoing stock records exist for this product.';
                } elseif ($historyStart->gt($wStart)) {
                    $wReason = "History starts {$historyStart->format('M j, Y')}: fewer than {$w} full months before this month.";
                }
                $wSum = array_sum(array_column($wInputs, 'units'));
                $wAvg = $wSum / $w;
                $wAvailable = $wReason === null;

                $windowsData[$w] = [
                    'window' => $w,
                    'available' => $wAvailable,
                    'sum' => $wSum,
                    'decimal' => $wAvailable ? round($wAvg, 2) : null,
                    'forecast' => $wAvailable ? (int) round($wAvg) : null,
                    'period_forecast' => $wAvailable ? (int) round($wAvg * $w) : null,
                    'inputs' => $wInputs,
                    'reason' => $wReason,
                    'trend' => $this->calculateTrend($wInputs, $wAvailable),
                ];
            }

            $movementUnits = 0;
            for ($m = $movementStart->copy(); $m->lt($targetMonth); $m->addMonth()) {
                $movementUnits += $monthUnits($m);
            }

            $selectedWindowData = $windowsData[$window];
            $result[$id] = [
                'available' => $selectedWindowData['available'],
                'has_records' => isset($everHad[$id]),
                // Monthly SMA, and the demand expected over the next `window` months (SMA × months).
                'forecast' => $selectedWindowData['forecast'],
                'decimal_forecast' => $selectedWindowData['decimal'],
                'period_forecast' => $selectedWindowData['period_forecast'],
                'historical_units' => $selectedWindowData['sum'],
                'window' => $window,
                'inputs' => $selectedWindowData['inputs'],
                'reason' => $selectedWindowData['reason'],
                'trend' => $selectedWindowData['trend'],
                'sma_3' => $windowsData[3]['decimal'],
                'sma_3_rounded' => $windowsData[3]['forecast'],
                'sma_6' => $windowsData[6]['decimal'],
                'sma_6_rounded' => $windowsData[6]['forecast'],
                'sma_12' => $windowsData[12]['decimal'],
                'sma_12_rounded' => $windowsData[12]['forecast'],
                'windows' => $windowsData,
                'movement_units' => $movementUnits,
                'movement_months' => $movementMonths,
                'movement_record_count' => array_sum(array_column(array_filter($byProduct[$id] ?? [], fn ($r) => $r['date'] >= $movementStart->toDateString()), 'source_count')),
            ];
        }

        if ($optimize) {
            foreach (array_chunk($productIds, 100) as $batch) {
                $analyses = $this->periodProducts($teamId, $batch, ForecastPeriod::Monthly, 60, $window, 'auto', $today);
                foreach ($analyses as $id => $analysis) {
                    // No sales or outgoing movements at all: keep the specific "no records" reason and unavailable state.
                    if (! $result[$id]['has_records']) {
                        continue;
                    }
                    $selected = $analysis['selected_window'];
                    $result[$id]['window'] = $selected ?? self::DEFAULT_WINDOW;
                    $result[$id]['horizon_months'] = $window;
                    $result[$id]['available'] = $analysis['available'];
                    $result[$id]['forecast'] = $analysis['forecast'] === null ? null : (int) round($analysis['forecast']);
                    $result[$id]['decimal_forecast'] = $analysis['forecast'] === null ? null : round($analysis['forecast'], 2);
                    $result[$id]['period_forecast'] = $analysis['total_forecast'] === null ? null : (int) round($analysis['total_forecast']);
                    $result[$id]['reason'] = $analysis['reason'];
                    $result[$id]['accuracy'] = $analysis['accuracy'];
                    $result[$id]['best_window'] = $analysis['best_window'];
                    $result[$id]['inputs'] = array_map(fn ($point) => ['month' => $point['label'], 'units' => $point['actual']], array_slice($analysis['historical'], -($selected ?? 3)));
                    $result[$id]['historical_units'] = array_sum(array_column($result[$id]['inputs'], 'units'));
                    $result[$id]['trend'] = $this->calculateTrend($result[$id]['inputs'], $analysis['available']);
                }
            }
        }

        return $result;
    }

    /**
     * Determine demand trend from the completed window inputs.
     *
     * @param  list<array{month: string, units: int|float}>  $inputs
     */
    public function calculateTrend(array $inputs, bool $available): string
    {
        if (! $available) {
            return 'Insufficient Data';
        }

        $units = array_map(fn ($row) => (float) ($row['units'] ?? 0), $inputs);
        $total = array_sum($units);
        if ($total <= 0) {
            return 'Zero Demand';
        }

        $count = count($units);
        if ($count < 2) {
            return 'Stable';
        }

        $half = intdiv($count + 1, 2);
        $earlier = array_slice($units, 0, $half);
        $recent = array_slice($units, $count - $half, $half);

        $earlierAvg = array_sum($earlier) / count($earlier);
        $recentAvg = array_sum($recent) / count($recent);
        $diff = $recentAvg - $earlierAvg;
        $threshold = max(0.5, $earlierAvg * 0.05);

        if (abs($diff) <= $threshold) {
            return 'Stable';
        }

        return $diff > 0 ? 'Increasing' : 'Decreasing';
    }

    /**
     * Complete SMA analytics for the Demand Forecasting module:
     * - Next-month forecast summary for Sales Transactions, Products Involved, and Units Sold (SMA 3, 6, 12)
     * - Interactive Line Graph series for all 3 metrics (decoupled from historical display filter so filtering never truncates the SMA calculation window)
     * - Rolling historical backtesting accuracy (MAE, WAPE, MAPE) with safe zero-demand handling
     */
    public function analytics(
        int $teamId,
        CarbonInterface $displayFrom,
        CarbonInterface $displayTo,
        int $window = self::DEFAULT_WINDOW,
        string $category = 'All',
        ?int $productId = null,
        ?CarbonInterface $today = null,
    ): array {
        $window = self::window($window);
        $today = Carbon::instance($today ?? Carbon::today())->copy()->startOfDay();
        $targetMonth = $today->copy()->startOfMonth();
        $lastCompletedMonth = $targetMonth->copy()->subMonth();
        $lastCompleteEnd = $targetMonth->copy()->subDay()->endOfDay();

        $displayFromMonth = Carbon::instance($displayFrom)->copy()->startOfMonth();
        $displayToMonth = Carbon::instance($displayTo)->copy()->startOfMonth()->min($lastCompletedMonth);
        if ($displayFromMonth->gt($displayToMonth)) {
            $displayFromMonth = $displayToMonth->copy();
        }
        $displayMonths = $this->months($displayFromMonth, $displayToMonth);

        // Always query enough preceding completed months to compute SMA-3/6/12 and rolling SMA for the first displayed month.
        $lookbackMonths = max(12, $window);
        $queryFromMonth = $displayFromMonth->copy()->subMonths($lookbackMonths)->min($targetMonth->copy()->subMonths(12));
        $queryFrom = $queryFromMonth->copy()->startOfDay();
        $queryTo = $lastCompleteEnd->copy();
        $allMonths = $this->months($queryFromMonth, $lastCompletedMonth);

        $scope = fn (Builder $q, string $productColumn) => $productId
            ? $q->where($productColumn, $productId)
            : ($category !== 'All' && $category !== '' ? $q->whereHas('product', fn ($p) => $p->where('category', $category)) : $q);

        $productsQuery = Product::where('team_id', $teamId)
            ->when($productId, fn ($q) => $q->whereKey($productId))
            ->when(! $productId && $category !== 'All' && $category !== '', fn ($q) => $q->where('category', $category));

        $allScopedIds = (clone $productsQuery)->pluck('id')->all();
        $activeScopedIds = (clone $productsQuery)->where('is_active', true)->pluck('id')->all();
        $eligibleProductIds = $activeScopedIds ?: $allScopedIds;
        $eligibleProductCount = count($eligibleProductIds);

        $records = $this->records($teamId, $queryFrom, $queryTo, $scope, aggregate: true);
        $monthlyDemand = $this->monthlyProductDemand($records);
        $salesTransactionsByMonth = $this->monthlySalesTransactions($teamId, $queryFrom, $queryTo, $category, $productId);

        $history = $this->productsWithAnyDemand($teamId, $allScopedIds, $lastCompleteEnd);
        $created = $history ? Product::where('team_id', $teamId)->whereIn('id', array_keys($history))->min('created_at') : null;
        $earliestSaleDate = $this->earliestEligibleSaleDate($teamId, $lastCompleteEnd, $category, $productId);
        $candidateDates = array_filter([
            $history ? min($history) : null,
            $created ? Carbon::parse($created)->toDateString() : null,
            $earliestSaleDate,
        ]);
        $firstHistory = $candidateDates ? Carbon::parse(min($candidateDates))->startOfMonth() : null;

        $unitsSoldByMonth = [];
        $productsInvolvedByMonth = [];
        foreach ($allMonths as $m) {
            $ym = $m->format('Y-m');
            $productRows = $monthlyDemand[$ym] ?? [];
            $units = 0;
            $distinctProducts = 0;
            foreach ($productRows as $pid => $netUnits) {
                if ($netUnits > 0) {
                    $units += $netUnits;
                    $distinctProducts++;
                }
            }
            if ($eligibleProductCount > 0) {
                $distinctProducts = min($eligibleProductCount, $distinctProducts);
            }
            $unitsSoldByMonth[$ym] = $units;
            $productsInvolvedByMonth[$ym] = $distinctProducts;
            $salesTransactionsByMonth[$ym] ??= 0;
        }

        $metricMaps = [
            'sales_transactions' => $salesTransactionsByMonth,
            'products_involved' => $productsInvolvedByMonth,
            'units_sold' => $unitsSoldByMonth,
        ];

        // Next-month forecasts for SMA 3, 6, 12 across all 3 required metrics
        $summariesByWindow = [];
        foreach (self::WINDOWS as $w) {
            $winStart = $targetMonth->copy()->subMonths($w);
            $winMonths = $this->months($winStart, $lastCompletedMonth);
            $available = $firstHistory !== null && $firstHistory->lte($winStart);
            $reason = null;
            if (! $firstHistory) {
                $reason = 'No recorded sales or demand history exists yet.';
            } elseif ($firstHistory->gt($winStart)) {
                $reason = "History starts {$firstHistory->format('M Y')}: fewer than {$w} completed calendar months before {$targetMonth->format('M Y')}.";
            }

            $metricsOut = [];
            foreach ($metricMaps as $metricKey => $byMonth) {
                $inputs = [];
                foreach ($winMonths as $wm) {
                    $ym = $wm->format('Y-m');
                    $inputs[] = [
                        'month' => $wm->format('M Y'),
                        'ym' => $ym,
                        'value' => $byMonth[$ym] ?? 0,
                    ];
                }
                $sum = array_sum(array_column($inputs, 'value'));
                $rawAvg = $sum / $w;
                if ($metricKey === 'products_involved' && $eligibleProductCount > 0) {
                    $rawAvg = min((float) $eligibleProductCount, $rawAvg);
                }
                $rounded = $available ? (int) round($rawAvg) : null;
                if ($metricKey === 'products_involved' && $rounded !== null && $eligibleProductCount > 0) {
                    $rounded = min($eligibleProductCount, $rounded);
                }

                $metricsOut[$metricKey] = [
                    'available' => $available,
                    'window' => $w,
                    'sum' => $sum,
                    'decimal' => $available ? round($rawAvg, 2) : null,
                    'rounded' => $rounded,
                    'period_total' => $available ? (int) round($rawAvg * $w) : null,
                    'inputs' => $inputs,
                    'reason' => $reason,
                ];
            }

            $summariesByWindow[$w] = [
                'window' => $w,
                'label' => "SMA {$w} Months",
                'range_label' => $winStart->format('M Y').' – '.$lastCompletedMonth->format('M Y'),
                'from_month' => $winStart->format('Y-m'),
                'to_month' => $lastCompletedMonth->format('Y-m'),
                'target_month' => $targetMonth->format('M Y'),
                'target_ym' => $targetMonth->format('Y-m'),
                'available' => $available,
                'reason' => $reason,
                'sales_transactions' => $metricsOut['sales_transactions'],
                'products_involved' => $metricsOut['products_involved'],
                'units_sold' => $metricsOut['units_sold'],
            ];
        }

        // Build chart series and rolling backtesting for each metric
        $allYmIndex = [];
        foreach ($allMonths as $idx => $m) {
            $allYmIndex[$m->format('Y-m')] = $idx;
        }

        $metricSeries = [];
        $backtestByMetric = [];
        foreach ($metricMaps as $metricKey => $byMonth) {
            $allValues = array_map(fn (Carbon $m) => $byMonth[$m->format('Y-m')] ?? 0, $allMonths);

            $labels = [];
            $months = [];
            $actuals = [];
            $smaDecimals = [];
            $smaRounded = [];
            $nextMonthLine = [];
            $pointDetails = [];

            foreach ($displayMonths as $dm) {
                $ym = $dm->format('Y-m');
                $label = $dm->format('M Y');
                $idx = $allYmIndex[$ym] ?? null;

                $hasCompleteHistoryForMonth = $firstHistory !== null && $dm->gte($firstHistory);
                $actualVal = $hasCompleteHistoryForMonth ? ($byMonth[$ym] ?? 0) : null;

                $smaDec = null;
                $smaInt = null;
                $smaInputs = [];
                if ($idx !== null && $idx >= $window && $firstHistory !== null && $firstHistory->lte($allMonths[$idx - $window])) {
                    $slice = array_slice($allValues, $idx - $window, $window);
                    $raw = array_sum($slice) / $window;
                    if ($metricKey === 'products_involved' && $eligibleProductCount > 0) {
                        $raw = min((float) $eligibleProductCount, $raw);
                    }
                    $smaDec = round($raw, 2);
                    $smaInt = (int) round($raw);
                    if ($metricKey === 'products_involved' && $eligibleProductCount > 0) {
                        $smaInt = min($eligibleProductCount, $smaInt);
                    }
                    for ($k = $idx - $window; $k < $idx; $k++) {
                        $smaInputs[] = [
                            'month' => $allMonths[$k]->format('M Y'),
                            'value' => $allValues[$k],
                        ];
                    }
                }

                $labels[] = $label;
                $months[] = $ym;
                $actuals[] = $actualVal;
                $smaDecimals[] = $smaDec;
                $smaRounded[] = $smaInt;
                $nextMonthLine[] = null;
                $pointDetails[] = [
                    'month' => $label,
                    'ym' => $ym,
                    'is_forecast' => false,
                    'status' => $hasCompleteHistoryForMonth ? 'complete' : 'insufficient_history',
                    'actual' => $actualVal,
                    'sma_decimal' => $smaDec,
                    'sma_rounded' => $smaInt,
                    'inputs' => $smaInputs,
                ];
            }

            // Connect the last completed historical point to the next-month forecast point for visual continuity
            $nextForecast = $summariesByWindow[$window][$metricKey];
            if (! empty($actuals)) {
                $lastIdx = count($actuals) - 1;
                $nextMonthLine[$lastIdx] = $actuals[$lastIdx] ?? $smaDecimals[$lastIdx];
            }

            $forecastLabel = $targetMonth->format('M Y').' (Forecast)';
            $labels[] = $forecastLabel;
            $months[] = $targetMonth->format('Y-m');
            $actuals[] = null;
            $smaDecimals[] = $nextForecast['decimal'];
            $smaRounded[] = $nextForecast['rounded'];
            $nextMonthLine[] = $nextForecast['decimal'];
            $pointDetails[] = [
                'month' => $targetMonth->format('M Y'),
                'ym' => $targetMonth->format('Y-m'),
                'is_forecast' => true,
                'status' => $nextForecast['available'] ? 'forecast' : 'insufficient_history',
                'actual' => null,
                'sma_decimal' => $nextForecast['decimal'],
                'sma_rounded' => $nextForecast['rounded'],
                'inputs' => array_map(fn ($in) => ['month' => $in['month'], 'value' => $in['value']], $nextForecast['inputs']),
            ];

            $backtest = $this->calculateBacktest($pointDetails);
            $backtestByMetric[$metricKey] = $backtest;

            $metricSeries[$metricKey] = [
                'metric' => $metricKey,
                'labels' => $labels,
                'months' => $months,
                'actual' => $actuals,
                'sma_decimal' => $smaDecimals,
                'sma_rounded' => $smaRounded,
                'next_month_projection' => $nextMonthLine,
                'next_month_forecast' => $nextForecast,
                'points' => $pointDetails,
                'backtest' => $backtest,
            ];
        }

        return [
            'targetMonth' => $targetMonth->format('M Y'),
            'targetYm' => $targetMonth->format('Y-m'),
            'lastCompletedMonth' => $lastCompletedMonth->format('M Y'),
            'lastCompletedYm' => $lastCompletedMonth->format('Y-m'),
            'selectedWindow' => $window,
            'selectedWindowLabel' => "SMA {$window} Months",
            'windowRangeLabel' => $summariesByWindow[$window]['range_label'],
            'eligibleProductCount' => $eligibleProductCount,
            'firstHistoryMonth' => $firstHistory?->format('M Y'),
            'hasHistory' => $firstHistory !== null,
            'currentSummary' => $summariesByWindow[$window],
            'summariesByWindow' => $summariesByWindow,
            'metricSeries' => $metricSeries,
            'backtest' => $backtestByMetric,
        ];
    }

    /**
     * Per-month, per-product net demand units (respecting linked sales and unlinked movements rules).
     *
     * @return array<string, array<int, int>>
     */
    private function monthlyProductDemand(array $records): array
    {
        $products = [];
        foreach ($records as $r) {
            if (! ($r['counted'] ?? true) || ! $r['product_id']) {
                continue;
            }
            $ym = substr($r['date'], 0, 7);
            $key = $r['source'] === 'Sales Order item' ? (($r['linked'] ?? false) ? 'linked' : 'sales') : 'movements';
            $products[$ym][$r['product_id']] ??= ['movements' => 0, 'sales' => 0, 'linked' => 0];
            $products[$ym][$r['product_id']][$key] += max(0, (int) $r['units']);
        }

        $monthly = [];
        foreach ($products as $ym => $rows) {
            foreach ($rows as $pid => $bucket) {
                $monthly[$ym][(int) $pid] = $bucket['linked'] + max($bucket['sales'], $bucket['movements']);
            }
        }

        return $monthly;
    }

    /**
     * Monthly count of distinct eligible sales transactions (`Sale` orders in `SalesOrderStatus::countedInSales()`,
     * not soft-deleted, with net-positive items when items exist). Each order is counted once.
     *
     * @return array<string, int>
     */
    private function monthlySalesTransactions(int $teamId, Carbon $from, Carbon $to, string $category = 'All', ?int $productId = null): array
    {
        $query = Sale::query()
            ->where('sales.team_id', $teamId)
            ->whereNull('sales.deleted_at')
            ->whereIn('sales.status', SalesOrderStatus::countedInSales())
            ->whereBetween('sales.sale_date', [$from, $to]);

        if ($productId) {
            $query->whereHas('items', fn (Builder $q) => $q
                ->where('product_id', $productId)
                ->whereRaw('quantity > COALESCE(cancelled_quantity, 0)'));
        } elseif ($category !== 'All' && $category !== '') {
            $query->whereHas('items', fn (Builder $q) => $q
                ->whereRaw('quantity > COALESCE(cancelled_quantity, 0)')
                ->whereHas('product', fn (Builder $p) => $p->where('category', $category)));
        } else {
            $query->where(function (Builder $q) {
                $q->whereDoesntHave('items')
                    ->orWhereHas('items', fn (Builder $iq) => $iq->whereRaw('quantity > COALESCE(cancelled_quantity, 0)'));
            });
        }

        $sales = $query->select(['sales.id', 'sales.sale_date'])->distinct()->get();

        $counts = [];
        foreach ($sales as $sale) {
            $ym = Carbon::parse($sale->sale_date)->format('Y-m');
            $counts[$ym] = ($counts[$ym] ?? 0) + 1;
        }

        return $counts;
    }

    private function earliestEligibleSaleDate(int $teamId, CarbonInterface $asOf, string $category = 'All', ?int $productId = null): ?string
    {
        $query = Sale::query()
            ->where('sales.team_id', $teamId)
            ->whereNull('sales.deleted_at')
            ->whereIn('sales.status', SalesOrderStatus::countedInSales())
            ->where('sales.sale_date', '<=', $asOf);

        if ($productId) {
            $query->whereHas('items', fn (Builder $q) => $q
                ->where('product_id', $productId)
                ->whereRaw('quantity > COALESCE(cancelled_quantity, 0)'));
        } elseif ($category !== 'All' && $category !== '') {
            $query->whereHas('items', fn (Builder $q) => $q
                ->whereRaw('quantity > COALESCE(cancelled_quantity, 0)')
                ->whereHas('product', fn (Builder $p) => $p->where('category', $category)));
        } else {
            $query->where(function (Builder $q) {
                $q->whereDoesntHave('items')
                    ->orWhereHas('items', fn (Builder $iq) => $iq->whereRaw('quantity > COALESCE(cancelled_quantity, 0)'));
            });
        }

        $minDate = $query->min('sales.sale_date');

        return $minDate ? Carbon::parse($minDate)->toDateString() : null;
    }

    /**
     * Rolling historical backtesting accuracy over completed months.
     * Computes MAE, WAPE (safe with zero actual demand months; null when total actual demand is 0), and MAPE.
     */
    private function calculateBacktest(array $points): array
    {
        $roundedErrors = [];
        $decimalErrors = [];
        $actuals = [];
        $apeRounded = [];
        $apeDecimal = [];
        $zeroActualMonths = 0;
        $comparisons = [];

        foreach ($points as $pt) {
            if (($pt['is_forecast'] ?? false) || $pt['actual'] === null || $pt['sma_rounded'] === null || $pt['sma_decimal'] === null) {
                continue;
            }

            $actual = (int) $pt['actual'];
            $predRounded = (int) $pt['sma_rounded'];
            $predDecimal = (float) $pt['sma_decimal'];

            $errRounded = abs($actual - $predRounded);
            $errDecimal = abs($actual - $predDecimal);

            $actuals[] = $actual;
            $roundedErrors[] = $errRounded;
            $decimalErrors[] = $errDecimal;

            $monthApe = null;
            if ($actual > 0) {
                $monthApe = round(($errRounded / $actual) * 100, 2);
                $apeRounded[] = ($errRounded / $actual) * 100;
                $apeDecimal[] = ($errDecimal / $actual) * 100;
            } else {
                $zeroActualMonths++;
            }

            $comparisons[] = [
                'month' => $pt['month'],
                'ym' => $pt['ym'],
                'actual' => $actual,
                'predicted_decimal' => $predDecimal,
                'predicted_rounded' => $predRounded,
                'abs_error_decimal' => round($errDecimal, 2),
                'abs_error_rounded' => $errRounded,
                'ape' => $monthApe,
            ];
        }

        $count = count($roundedErrors);
        $totalActual = array_sum($actuals);
        $totalRoundedError = array_sum($roundedErrors);
        $totalDecimalError = array_sum($decimalErrors);

        return [
            'months' => $count,
            'mae' => $count > 0 ? round($totalRoundedError / $count, 2) : null,
            'decimal_mae' => $count > 0 ? round($totalDecimalError / $count, 2) : null,
            'wape' => ($count > 0 && $totalActual > 0) ? round(($totalRoundedError / $totalActual) * 100, 2) : null,
            'decimal_wape' => ($count > 0 && $totalActual > 0) ? round(($totalDecimalError / $totalActual) * 100, 2) : null,
            'mape' => $apeRounded ? round(array_sum($apeRounded) / count($apeRounded), 2) : null,
            'decimal_mape' => $apeDecimal ? round(array_sum($apeDecimal) / count($apeDecimal), 2) : null,
            'mape_months' => count($apeRounded),
            'zero_actual_months' => $zeroActualMonths,
            'total_actual' => $totalActual,
            'total_abs_error' => $totalRoundedError,
            'comparisons' => $comparisons,
        ];
    }

    /** @return list<Carbon> */
    private function months(Carbon $from, Carbon $to): array
    {
        $periods = [];
        for ($c = $from->copy()->startOfMonth(); $c->lte($to->copy()->startOfMonth()); $c->addMonth()) {
            $periods[] = $c->copy();
        }

        return $periods;
    }

    /**
     * Every source record in the range, so each forecast can be traced back.
     *
     * @return list<array{source: string, product_id: int|null, date: string, reference: string|null, units: int}>
     */
    private function records(int $teamId, Carbon $from, Carbon $to, callable $scope, bool $aggregate = false): array
    {
        $movements = StockMovement::where('team_id', $teamId)
            ->whereIn('type', self::DEMAND_MOVEMENT_TYPES)
            ->whereBetween('movement_date', [$from, $to]);
        $scope($movements, 'product_id');

        if ($aggregate) {
            $sql = ForecastPeriod::Monthly->sql('movement_date', $movements->getConnection()->getDriverName());
            $rows = $movements->selectRaw("product_id, $sql as period_start, CASE WHEN sale_id IS NULL THEN 1 ELSE 0 END as counted, SUM(ABS(quantity)) as units, COUNT(*) as source_count")
                ->groupBy('product_id', 'counted')->groupByRaw($sql)->get()
                ->map(fn ($m) => ['source' => 'Stock movement', 'product_id' => $m->product_id, 'date' => $m->period_start, 'reference' => null, 'units' => (int) $m->units, 'counted' => (bool) $m->counted, 'linked' => false, 'source_count' => (int) $m->source_count])->all();
        } else {
            $rows = $movements->get(['id', 'product_id', 'type', 'quantity', 'movement_date', 'reference_number', 'sale_id'])
                ->map(fn ($m) => [
                    'source' => 'Stock movement ('.$m->type.')',
                    'product_id' => $m->product_id,
                    'date' => Carbon::parse($m->movement_date)->toDateString(),
                    'reference' => $m->reference_number ?: "Movement #{$m->id}",
                    'units' => abs((int) $m->quantity),
                    'counted' => $m->sale_id === null,
                    'linked' => false,
                ])->all();
        }

        $sales = SaleItem::query()
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->whereNull('sales.deleted_at')
            ->where('sales.team_id', $teamId)
            ->whereIn('sales.status', SalesOrderStatus::countedInSales())
            ->whereBetween('sales.sale_date', [$from, $to]);
        $scope($sales, 'sale_items.product_id');

        $sales->selectRaw('EXISTS (SELECT 1 FROM stock_movements sm WHERE sm.sale_id = sales.id AND sm.product_id = sale_items.product_id AND sm.team_id = sales.team_id) as linked');
        if ($aggregate) {
            $sql = ForecastPeriod::Monthly->sql('sales.sale_date', $sales->getConnection()->getDriverName());
            $sales->selectRaw("sale_items.product_id, $sql as period_start, SUM(CASE WHEN sale_items.quantity > COALESCE(sale_items.cancelled_quantity, 0) THEN sale_items.quantity - COALESCE(sale_items.cancelled_quantity, 0) ELSE 0 END) as units, COUNT(*) as source_count")
                ->groupBy('sale_items.product_id', 'linked')->groupByRaw($sql);
            foreach ($sales->get() as $s) {
                $rows[] = ['source' => 'Sales Order item', 'product_id' => $s->product_id, 'date' => $s->period_start, 'reference' => null, 'units' => (int) $s->units, 'counted' => true, 'linked' => (bool) $s->linked, 'source_count' => (int) $s->source_count];
            }

            return $rows;
        }
        foreach ($sales->addSelect(['sale_items.product_id', 'sale_items.quantity', 'sale_items.cancelled_quantity', 'sales.sale_date', 'sales.order_number', 'sales.id as sale_id'])->get() as $s) {
            $rows[] = [
                'source' => 'Sales Order item',
                'product_id' => $s->product_id,
                'date' => Carbon::parse($s->sale_date)->toDateString(),
                'reference' => $s->order_number ?: "Sale #{$s->sale_id}",
                'units' => max(0, (int) $s->quantity - (int) $s->cancelled_quantity),
                'counted' => true,
                'linked' => (bool) $s->linked,
            ];
        }

        return $rows;
    }

    /** @return array<string, array{movements: int, sales: int}> */
    private function totalsByMonth(array $records): array
    {
        $products = [];
        foreach ($records as $r) {
            if (! ($r['counted'] ?? true)) {
                continue;
            }
            $ym = substr($r['date'], 0, 7);
            $key = $r['source'] === 'Sales Order item' ? (($r['linked'] ?? false) ? 'linked' : 'sales') : 'movements';
            $products[$ym][$r['product_id']] ??= ['movements' => 0, 'sales' => 0, 'linked' => 0];
            $products[$ym][$r['product_id']][$key] += max(0, $r['units']);
        }
        $totals = [];
        foreach ($products as $ym => $rows) {
            $totals[$ym] = ['sales' => array_sum(array_map(fn ($r) => $r['linked'] + max($r['sales'], $r['movements']), $rows)), 'movements' => 0];
        }

        return $totals;
    }

    /** Backtest completed months only. MAPE excludes zero actuals; MAE includes them. */
    private function accuracy(array $periods, array $actual, array $forecast, Carbon $to): array
    {
        $errors = [];
        $percent = [];
        foreach ($periods as $i => $month) {
            if ($forecast[$i] === null || $month->copy()->endOfMonth()->gt($to->copy()->endOfDay()) || $month->copy()->endOfMonth()->gte(now())) {
                continue;
            }
            $error = abs($actual[$i] - $forecast[$i]);
            $errors[] = $error;
            if ($actual[$i] > 0) {
                $percent[] = $error / $actual[$i] * 100;
            }
        }

        return ['months' => count($errors), 'mae' => $errors ? round(array_sum($errors) / count($errors), 2) : null,
            'mape' => $percent ? round(array_sum($percent) / count($percent), 2) : null, 'mape_months' => count($percent)];
    }

    /** Earliest recorded demand or opening inventory, bounded by the evaluation date. */
    private function productsWithAnyDemand(int $teamId, array $productIds, CarbonInterface $asOf): array
    {
        $first = [];
        $add = function ($rows) use (&$first) {
            foreach ($rows as $id => $date) {
                $date = Carbon::parse($date)->toDateString();
                if ($id && (! isset($first[(int) $id]) || $date < $first[(int) $id])) {
                    $first[(int) $id] = $date;
                }
            }
        };

        $add(StockMovement::where('team_id', $teamId)->whereIn('type', [...self::DEMAND_MOVEMENT_TYPES, 'stock_in'])
            ->where('movement_date', '<=', $asOf)
            ->whereIn('product_id', $productIds)->groupBy('product_id')
            ->selectRaw('product_id, MIN(movement_date) as first_date')->pluck('first_date', 'product_id'));
        $add(SaleItem::query()->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->whereNull('sales.deleted_at')->where('sales.team_id', $teamId)
            ->whereIn('sales.status', SalesOrderStatus::countedInSales())
            ->where('sales.sale_date', '<=', $asOf)
            ->whereIn('sale_items.product_id', $productIds)->groupBy('sale_items.product_id')
            ->selectRaw('sale_items.product_id as pid, MIN(sales.sale_date) as first_date')->pluck('first_date', 'pid'));

        return $first;
    }
}
