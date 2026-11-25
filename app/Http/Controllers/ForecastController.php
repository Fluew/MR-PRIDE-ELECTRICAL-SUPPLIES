<?php

namespace App\Http\Controllers;

use App\Enums\ForecastPeriod;
use App\Models\Product;
use App\Services\Forecast\SmaForecaster;
use App\Services\Forecast\TopPerformers;
use App\Services\Inventory\ProductPrioritizer;
use App\Support\Csv;
use App\Support\DateNormalizer;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ForecastController extends Controller
{
    /**
     * Display demand forecast page with SMA predictive analytics.
     */
    public function index(Request $request): Response
    {
        // Changing the Top 5 period reloads only that panel: the SMA forecast is not recalculated.
        if ($request->header('X-Inertia-Partial-Component') === 'Forecast/Index'
            && $request->header('X-Inertia-Partial-Data') === 'topPerformers') {
            return Inertia::render('Forecast/Index', ['topPerformers' => fn () => $this->topPerformers($request)]);
        }

        if ($this->usesPeriods($request)) {
            return $this->periodPage($request);
        }

        $team = $request->user()->currentTeam;
        abort_if(! $team, 403, 'No active team selected.');

        $today = Carbon::today();
        $minDate = Carbon::create(2020, 1, 1);

        ['mode' => $mode, 'preset' => $datePreset, 'from' => $fromDate, 'to' => $toDate, 'window' => $smaWindow] = $this->forecastSelection($request);

        $category = $request->input('category', 'All');
        $productId = $request->filled('product_id') && $request->input('product_id') !== 'All'
            ? (int) $request->input('product_id')
            : null;

        // Categories list
        $categories = Product::where('team_id', $team->id)
            ->distinct()
            ->pluck('category')
            ->filter()
            ->values()
            ->toArray();
        if (empty($categories)) {
            $categories = ['Breakers', 'Wiring', 'Lighting', 'Controls', 'Tools', 'General'];
        }

        // Active products list for item selector
        $productsListQuery = Product::where('team_id', $team->id)->where('is_active', true);
        if ($category && $category !== 'All') {
            $productsListQuery->where('category', $category);
        }
        $productsList = $productsListQuery
            ->orderBy('name')
            ->get(['id', 'name', 'sku', 'description', 'category', 'stock', 'reorder_level', 'min_stock', 'price', 'unit']);

        // Selected product details
        $selectedProduct = null;
        if ($productId) {
            $selectedProduct = Product::where('team_id', $team->id)->find($productId);
            if ($selectedProduct && $category !== 'All' && $selectedProduct->category !== $category) {
                $category = $selectedProduct->category;
            }
        }

        $forecaster = app(SmaForecaster::class);
        $priorities = app(ProductPrioritizer::class)->forTeam($team->id, null, $smaWindow);
        $data = $this->calculateForecastData($team->id, $fromDate, $toDate, $smaWindow, $category, $productId);
        $analytics = $forecaster->analytics($team->id, $fromDate, $toDate, $smaWindow, $category ?: 'All', $productId, $today);

        // Product Forecasting Window data
        $productWindow = null;
        if ($selectedProduct) {
            $threshold = $selectedProduct->reorder_level ?: ($selectedProduct->min_stock ?: 10);
            $forecastNonZero = array_filter($data['forecast'], fn ($v) => $v !== null);
            // No forecast means no demand estimate: shown as insufficient data, never a default.
            $avgForecastDemand = $priorities[$selectedProduct->id]['forecast']['monthly'] ?? null;
            $daysOfSupply = $avgForecastDemand ? (int) round($selectedProduct->stock / ($avgForecastDemand / 30)) : null;
            $recommendedRestock = $priorities[$selectedProduct->id]['reorder']['suggested_quantity'] ?? 0;

            $urgency = 'Safe';
            if ($selectedProduct->stock <= 0) {
                $urgency = 'Out of Stock';
            } elseif ($selectedProduct->stock <= $threshold / 2) {
                $urgency = 'Critical Low';
            } elseif ($selectedProduct->stock <= $threshold) {
                $urgency = 'Needs Reorder';
            }

            $productWindow = [
                'id' => $selectedProduct->id,
                'name' => $selectedProduct->name,
                'sku' => $selectedProduct->sku,
                'category' => $selectedProduct->category,
                'unit' => $selectedProduct->unit ?: 'pcs',
                'price' => (float) $selectedProduct->price,
                'stock' => $selectedProduct->stock,
                'threshold' => $threshold,
                'reorderQuantity' => $selectedProduct->reorder_quantity ?: 50,
                'avgMonthlyDemand' => $avgForecastDemand,
                'daysOfSupply' => $daysOfSupply,
                'recommendedRestock' => max(0, $recommendedRestock),
                'urgency' => $urgency,
            ];
        }

        // Category Window data
        $categoryWindow = null;
        if ($category && $category !== 'All') {
            $catProducts = Product::where('team_id', $team->id)->where('is_active', true)->where('category', $category)->get();
            $totalStock = $catProducts->sum('stock');
            $lowCount = $catProducts->filter(fn ($p) => $p->stock <= ($p->reorder_level ?: 10))->count();
            $forecastNonZero = array_filter($data['forecast'], fn ($v) => $v !== null && $v > 0);
            $avgForecast = ! empty($forecastNonZero) ? (int) round(array_sum($forecastNonZero) / count($forecastNonZero)) : null;

            $categoryWindow = [
                'name' => $category,
                'totalItems' => $catProducts->count(),
                'totalStock' => $totalStock,
                'lowStockItems' => $lowCount,
                'avgMonthlyDemand' => $avgForecast,
            ];
        }

        // Restock suggestions query filtered by selected scope
        $restockQuery = Product::where('team_id', $team->id)->where('is_active', true);
        if ($productId) {
            $restockQuery->where('id', $productId);
        } elseif ($category && $category !== 'All') {
            $restockQuery->where('category', $category);
        }

        $suggestedRestocks = $restockQuery
            ->whereRaw('stock <= COALESCE(reorder_level, min_stock, 10)')
            ->orderBy('stock')
            ->limit(10)
            ->get(['id', 'sku', 'name', 'category', 'stock', 'reorder_level', 'min_stock', 'reorder_quantity', 'price'])
            ->map(function ($p) use ($priorities) {
                $threshold = $p->reorder_level ?: ($p->min_stock ?: 10);
                $urgency = $p->stock <= 0 ? 'Urgent' : ($p->stock <= $threshold / 2 ? 'Soon' : 'Monitor');
                $predictedNeed = $priorities[$p->id]['reorder']['suggested_quantity'] ?? 0;

                return [
                    'id' => $p->id,
                    'sku' => $p->sku,
                    'name' => $p->name,
                    'category' => $p->category,
                    'stock' => $p->stock,
                    'threshold' => $threshold,
                    'predictedNeed' => $predictedNeed,
                    'urgency' => $urgency,
                ];
            });

        // Review: each product's forecast for the chosen period, with the AHP priority and reorder
        // advice it feeds (the same ProductPrioritizer results as Reports → AHP Product Prioritization).
        $scopeIds = $productId ? [$productId] : $productsList->pluck('id')->all();
        $productForecasts = collect($priorities)
            ->only($scopeIds)
            ->sortBy(fn ($p) => [$p['reorder']['needed'] ? 0 : 1, -($p['forecast']['period_total'] ?? -1), $p['product']['name']])
            ->map(fn ($p) => [
                'product' => $p['product'],
                'available_stock' => $p['available_stock'],
                'forecast' => $p['forecast'],
                'classification' => $p['classification'],
                'score' => $p['score'],
                'scale' => $p['scale'] ?? null,
                'reorder' => [
                    'needed' => $p['reorder']['needed'],
                    'suggested_quantity' => $p['reorder']['suggested_quantity'],
                    'reorder_level' => $p['reorder']['reorder_level'] ?? 0,
                    'reorder_quantity' => $p['reorder']['reorder_quantity'] ?? 0,
                    'shortfall' => $p['reorder']['shortfall'] ?? null,
                    'basis' => $p['reorder']['basis'],
                ],
            ])->values();

        $positiveDemandCount = $productForecasts->filter(fn ($p) => ($p['forecast']['available'] ?? false) && (($p['forecast']['decimal_monthly'] ?? 0) > 0))->count();
        $roundedPositiveDemandCount = $productForecasts->filter(fn ($p) => ($p['forecast']['available'] ?? false) && (($p['forecast']['monthly'] ?? 0) > 0))->count();
        $zeroDemandCount = $productForecasts->filter(fn ($p) => ($p['forecast']['available'] ?? false) && (($p['forecast']['decimal_monthly'] ?? 0) <= 0))->count();
        $insufficientHistoryCount = $productForecasts->filter(fn ($p) => ! ($p['forecast']['available'] ?? false))->count();

        $summaryCards = [
            'targetMonth' => $analytics['targetMonth'],
            'lastCompletedMonth' => $analytics['lastCompletedMonth'],
            'smaWindow' => $smaWindow,
            'smaWindowLabel' => $analytics['selectedWindowLabel'],
            'windowRangeLabel' => $analytics['windowRangeLabel'],
            'predictedSalesTransactions' => $analytics['currentSummary']['sales_transactions'],
            'predictedProductsInvolved' => $analytics['currentSummary']['products_involved'] + [
                'eligible_products' => $analytics['eligibleProductCount'],
            ],
            'predictedUnitsSold' => $analytics['currentSummary']['units_sold'],
            'productsWithForecastedDemand' => [
                'count' => $positiveDemandCount,
                'rounded_positive_count' => $roundedPositiveDemandCount,
                'zero_demand_count' => $zeroDemandCount,
                'insufficient_history_count' => $insufficientHistoryCount,
                'eligible_products' => $productForecasts->count(),
            ],
        ];

        return Inertia::render('Forecast/Index', [
            'fromDate' => $fromDate->format('Y-m-d'),
            'toDate' => $toDate->format('Y-m-d'),
            'datePreset' => $datePreset,
            'forecastMode' => $mode,
            'smaWindow' => $smaWindow,
            'forecastPeriods' => SmaForecaster::PERIOD_LABELS,
            'summaryCards' => $summaryCards,
            'smaAnalytics' => $analytics,
            'productForecasts' => $productForecasts,
            'selectedCategory' => $category,
            'selectedProductId' => $productId,
            'categories' => $categories,
            'productsList' => $productsList,
            'productWindow' => $productWindow,
            'categoryWindow' => $categoryWindow,
            'forecastData' => $data,
            'suggestedRestocks' => $suggestedRestocks,
            'minDate' => $minDate->format('Y-m-d'),
            'maxDate' => $today->format('Y-m-d'),
            'topPerformers' => fn () => $this->topPerformers($request),
        ]);
    }

    /**
     * Top 5 suppliers and products for the Weekly / Monthly / Annual filter (top_period) and how many
     * periods back (top_offset, 0 = the current period to date). Independent of the SMA settings.
     */
    private function topPerformers(Request $request): array
    {
        $team = $request->user()->currentTeam;
        abort_if(! $team, 403, 'No active team selected.');
        $data = $request->validate([
            'top_period' => ['sometimes', 'string', Rule::in(['weekly', 'monthly', 'annual'])],
            'top_offset' => ['sometimes', 'integer', 'min:0', 'max:1200'],
        ]);

        return app(TopPerformers::class)->for($team->id, ForecastPeriod::from($data['top_period'] ?? 'monthly'), (int) ($data['top_offset'] ?? 0));
    }

    /**
     * Export forecast data to CSV.
     */
    public function exportCsv(Request $request): StreamedResponse
    {
        if ($this->usesPeriods($request)) {
            return $this->exportPeriods($request);
        }

        $team = $request->user()->currentTeam;
        abort_if(! $team, 403, 'No active team selected.');

        ['from' => $fromDate, 'to' => $toDate, 'window' => $smaWindow] = $this->forecastSelection($request);

        $category = $request->input('category', 'All');
        $productId = $request->filled('product_id') && $request->input('product_id') !== 'All'
            ? (int) $request->input('product_id')
            : null;

        $data = $this->calculateForecastData($team->id, $fromDate, $toDate, $smaWindow, $category, $productId);
        $fileName = "demand_forecast_{$fromDate->format('Y-m-d')}_to_{$toDate->format('Y-m-d')}_sma{$smaWindow}.csv";

        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$fileName}\"",
            'Pragma' => 'no-cache',
            'Cache-Control' => 'must-revalidate, post-check=0, pre-check=0',
            'Expires' => '0',
        ];

        return response()->stream(function () use ($data, $smaWindow) {
            $file = fopen('php://output', 'w');
            Csv::put($file, ['Month', 'Historical Demand (Units)', "Forecast SMA-{$smaWindow} (Units)"]);

            foreach ($data['labels'] as $idx => $month) {
                Csv::put($file, [
                    $month,
                    $data['historical'][$idx] ?? 'N/A',
                    $data['forecast'][$idx] ?? 'N/A',
                ]);
            }

            fclose($file);
        }, 200, $headers);
    }

    /** Months of history shown before the forecast window in Forecasting Period mode. */
    private const PERIOD_HISTORY_MONTHS = 12;

    private function usesPeriods(Request $request): bool
    {
        return $request->has('period') || ! $request->hasAny(['sma_window', 'forecast_mode', 'date_preset', 'from_date', 'to_date']);
    }

    private function periodOptions(Request $request): array
    {
        $team = $request->user()->currentTeam;
        abort_if(! $team, 403, 'No active team selected.');
        $periodInput = $request->input('period', 'monthly');
        $period = is_string($periodInput) ? (ForecastPeriod::tryFrom($periodInput) ?? ForecastPeriod::Monthly) : ForecastPeriod::Monthly;
        $lastComplete = $period->start(now())->subDay()->toDateString();
        $validated = $request->validate([
            'period' => ['sometimes', 'string', 'in:weekly,monthly,annual'],
            'window' => ['sometimes', 'in:auto,3,6,12'],
            'history_periods' => ['sometimes', 'integer', 'min:3', 'max:240'],
            'horizon' => ['sometimes', 'integer', 'min:1', 'max:24'],
            'display_periods' => ['sometimes', 'integer', 'min:1', 'max:240'],
            'category' => ['nullable', 'string', 'max:255'],
            'search' => ['nullable', 'string', 'max:100'],
            'product_id' => ['nullable', 'integer', Rule::exists('products', 'id')->where('team_id', $team->id)->whereNull('deleted_at')],
            'from_date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:'.$lastComplete],
            'to_date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:'.$lastComplete, ...($request->filled('from_date') ? ['after_or_equal:from_date'] : [])],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        return array_merge(['period' => 'monthly', 'window' => 'auto', 'history_periods' => 60, 'display_periods' => 12, 'horizon' => 3, 'category' => 'All', 'search' => '', 'product_id' => null, 'from_date' => null, 'to_date' => null], $validated);
    }

    private function periodQuery(int $teamId, array $options): Builder
    {
        return Product::where('team_id', $teamId)->where('is_active', true)
            ->when($options['category'] && $options['category'] !== 'All', fn ($q) => $q->where('category', $options['category']))
            ->when($options['product_id'], fn ($q) => $q->whereKey($options['product_id']))
            ->when($options['search'], fn ($q) => $q->where(fn ($q) => $q->where('name', 'like', '%'.$options['search'].'%')->orWhere('sku', 'like', '%'.$options['search'].'%')));
    }

    private function periodPage(Request $request): Response
    {
        $options = $this->periodOptions($request);
        $teamId = $request->user()->currentTeam->id;
        $products = $this->periodQuery($teamId, $options)->orderBy('name')->orderBy('id')
            ->paginate(25, ['id', 'name', 'sku', 'category', 'stock', 'unit', 'description', 'price', 'reorder_level', 'reorder_quantity', 'min_stock'])->withQueryString();
        $period = ForecastPeriod::from($options['period']);
        $forecaster = app(SmaForecaster::class);
        $analysis = $forecaster->periodProducts($teamId, $products->pluck('id')->all(), $period, (int) $options['history_periods'], (int) $options['horizon'], $options['window']);
        $analytics = $forecaster->periodAnalytics($teamId, $analysis, $period, $options);
        $priorities = app(ProductPrioritizer::class)->forTeam($teamId);
        $productList = $products->items();
        $rows = [];
        foreach ($products as $product) {
            $a = $analysis[$product->id];
            $inputs = array_map(fn ($point) => ['month' => $point['label'], 'units' => $point['actual']], array_slice($a['historical'], -($a['selected_window'] ?? 3)));
            $priority = $priorities[$product->id] ?? [];
            $rows[] = ['product' => $product, 'available_stock' => $priority['available_stock'] ?? $product->stock,
                'forecast' => ['available' => $a['available'], 'months' => $a['selected_window'] ?? 3, 'period' => $period->value, 'monthly' => $a['forecast'] === null ? null : (int) round($a['forecast']), 'decimal_monthly' => $a['forecast'], 'period_total' => $a['total_forecast'], 'historical_units' => array_sum(array_column($inputs, 'units')), 'inputs' => $inputs, 'reason' => $a['reason'], 'trend' => $forecaster->calculateTrend($inputs, $a['available']), 'accuracy' => $a['accuracy'], 'accuracy_band' => $a['accuracy_band'], 'demand_pattern' => $a['demand_pattern'], 'in_progress' => $a['in_progress'], 'selected_window' => $a['selected_window']],
                'classification' => $priority['classification'] ?? null, 'score' => $priority['score'] ?? null, 'reorder' => $priority['reorder'] ?? ['needed' => false, 'suggested_quantity' => 0, 'basis' => 'Monthly AHP recommendation unavailable.']];
        }
        $series = $analytics['metricSeries']['units_sold'];
        $selectedProduct = $options['product_id'] ? $products->firstWhere('id', (int) $options['product_id']) : null;
        $productWindow = null;
        if ($selectedProduct) {
            $priority = $priorities[$selectedProduct->id] ?? [];
            $threshold = $selectedProduct->reorder_level ?: ($selectedProduct->min_stock ?: 10);
            $monthlyDemand = $priority['forecast']['monthly'] ?? null;
            $productWindow = ['id' => $selectedProduct->id, 'name' => $selectedProduct->name, 'sku' => $selectedProduct->sku, 'category' => $selectedProduct->category, 'unit' => $selectedProduct->unit ?: 'pcs', 'price' => (float) $selectedProduct->price, 'stock' => $selectedProduct->stock, 'threshold' => $threshold, 'reorderQuantity' => $selectedProduct->reorder_quantity ?? 0, 'avgMonthlyDemand' => $analysis[$selectedProduct->id]['forecast'], 'daysOfSupply' => $monthlyDemand ? (int) round($selectedProduct->stock / ($monthlyDemand / 30)) : null, 'recommendedRestock' => $priority['reorder']['suggested_quantity'] ?? 0, 'urgency' => $selectedProduct->stock <= 0 ? 'Out of Stock' : ($selectedProduct->stock <= $threshold / 2 ? 'Critical Low' : ($selectedProduct->stock <= $threshold ? 'Needs Reorder' : 'Safe'))];
        }
        $categories = Product::where('team_id', $teamId)->whereNotNull('category')->distinct()->orderBy('category')->pluck('category');
        $products->through(fn ($product) => ['product' => $product, 'analysis' => $analysis[$product->id]]);

        return Inertia::render('Forecast/Index', [
            'fromDate' => $analytics['fromDate'], 'toDate' => $analytics['toDate'],
            'datePreset' => $options['from_date'] || $options['to_date'] ? 'custom' : 'last_'.($options['display_periods'] ?? 12).'_months',
            'forecastMode' => 'period', 'smaWindow' => $analytics['selectedWindow'], 'forecastPeriods' => SmaForecaster::PERIOD_LABELS,
            'selectedCategory' => $options['category'], 'selectedProductId' => $options['product_id'],
            'categories' => $categories, 'productsList' => $productList, 'productWindow' => $productWindow, 'categoryWindow' => null,
            'productForecasts' => $rows, 'suggestedRestocks' => [], 'minDate' => '1900-01-01', 'maxDate' => $period->start(now())->subDay()->toDateString(),
            'smaAnalytics' => $analytics,
            'forecastData' => ['labels' => $series['labels'], 'historical' => $series['actual'], 'forecast' => $series['sma_decimal'], 'hasData' => $analytics['hasHistory'], 'steps' => [], 'sourceRecords' => [], 'sourceRecordCount' => 0, 'accuracy' => $series['backtest']],
            'summaryCards' => ['targetMonth' => $analytics['targetMonth'], 'lastCompletedMonth' => $analytics['lastCompletedMonth'], 'smaWindow' => $analytics['selectedWindow'], 'smaWindowLabel' => $analytics['selectedWindowLabel'], 'windowRangeLabel' => $analytics['windowRangeLabel'], 'predictedSalesTransactions' => $analytics['currentSummary']['sales_transactions'], 'predictedProductsInvolved' => $analytics['currentSummary']['products_involved'], 'predictedUnitsSold' => $analytics['currentSummary']['units_sold'], 'productsWithForecastedDemand' => ['count' => count(array_filter($analysis, fn ($a) => $a['forecast'] > 0)), 'rounded_positive_count' => count(array_filter($analysis, fn ($a) => round($a['forecast'] ?? 0) > 0)), 'zero_demand_count' => count(array_filter($analysis, fn ($a) => $a['available'] && $a['forecast'] == 0)), 'insufficient_history_count' => count(array_filter($analysis, fn ($a) => ! $a['available'])), 'eligible_products' => count($analysis)]],
            'periodPlanning' => [
                'options' => $options, 'products' => $products,
                'categories' => $categories,
                'training_end' => $period->start(now())->subDay()->toDateString(),
                'forecast_start' => $period->advance($period->start(now()))->toDateString(),
            ],
            'topPerformers' => fn () => $this->topPerformers($request),
        ]);
    }

    private function exportPeriods(Request $request): StreamedResponse
    {
        $options = $this->periodOptions($request);
        $teamId = $request->user()->currentTeam->id;
        $period = ForecastPeriod::from($options['period']);
        $query = $this->periodQuery($teamId, $options);
        $displayFrom = $options['from_date'] ?? $period->advance($period->start(now()), -(int) $options['display_periods'])->toDateString();
        $displayTo = $options['to_date'] ?? $period->start(now())->subDay()->toDateString();

        return response()->streamDownload(function () use ($query, $teamId, $period, $options, $displayFrom, $displayTo) {
            $file = fopen('php://output', 'w');
            Csv::put($file, ['SKU', 'Product', 'Period', 'Record type', 'Start', 'End', 'Actual units', 'Forecast units', 'SMA window', 'MAE', 'MAPE (%)', 'WAPE (%)', 'RMSE', 'Evaluated periods', 'MAPE periods', 'Zero actual periods', 'Accuracy indicator (%)', 'Target met']);
            $query->chunkById(100, function ($products) use ($file, $teamId, $period, $options, $displayFrom, $displayTo) {
                $analyses = app(SmaForecaster::class)->periodProducts($teamId, $products->pluck('id')->all(), $period, (int) $options['history_periods'], (int) $options['horizon'], $options['window']);
                foreach ($products as $product) {
                    $analysis = $analyses[$product->id];
                    foreach ($analysis['historical'] as $point) {
                        if ($point['end'] < $displayFrom || $point['start'] > $displayTo) {
                            continue;
                        }
                        Csv::put($file, [$product->sku, $product->name, $period->value, 'Historical demand', $point['start'], $point['end'], $point['actual'], '', $analysis['selected_window']]);
                    }
                    foreach ($analysis['future'] as $point) {
                        Csv::put($file, [$product->sku, $product->name, $period->value, 'Future forecast', $point['start'], $point['end'], '', $point['forecast'] ?? 'N/A', $analysis['selected_window']]);
                    }
                    foreach ($analysis['configurations'] as $config) {
                        foreach (['validation', 'test'] as $phase) {
                            $metrics = $config[$phase];
                            Csv::put($file, [$product->sku, $product->name, $period->value, $phase, '', '', '', '', $config['window'], $metrics['mae'] ?? 'N/A', $metrics['mape'] ?? 'N/A', $metrics['wape'] ?? 'N/A', $metrics['rmse'] ?? 'N/A', $metrics['count'], $metrics['mape_count'], $metrics['zero_actual_count'], $metrics['accuracy_indicator'] ?? 'N/A', $metrics['target_met'] === null ? 'N/A' : ($metrics['target_met'] ? 'Yes' : 'No')]);
                            foreach ($metrics['comparisons'] as $point) {
                                Csv::put($file, [$product->sku, $product->name, $period->value, $phase.' comparison', $point['start'], $point['end'], $point['actual'], $point['forecast'], $config['window']]);
                            }
                        }
                    }
                }
            });
            fclose($file);
        }, 'demand_forecast_'.$period->value.'.csv', ['Content-Type' => 'text/csv']);
    }

    /**
     * The Forecast Selection:
     * - When `date_preset` is explicitly supplied (`last_3_months`, `last_6_months`, `last_12_months`,
     *   `2020_present`, `custom`), the historical graph display range follows the preset while the
     *   selected `sma_window` (3, 6, or 12) is preserved independently.
     * - Legacy requests without `date_preset` retain the exact two-mode (`period` vs `range`) behavior.
     *
     * @return array{mode: string, preset: string, from: Carbon, to: Carbon, window: int}
     */
    private function forecastSelection(Request $request): array
    {
        $today = Carbon::today();
        $minDate = Carbon::create(2020, 1, 1);

        $preset = $request->input('date_preset');
        $validPresets = ['last_3_months', 'last_6_months', 'last_12_months', '2020_present', 'custom'];

        if (is_string($preset) && in_array($preset, $validPresets, true)) {
            $window = SmaForecaster::window($request->input('sma_window', SmaForecaster::DEFAULT_WINDOW));
            $to = $today->copy();

            if ($preset === 'last_3_months') {
                $from = $today->copy()->startOfMonth()->subMonths(3)->max($minDate);
            } elseif ($preset === 'last_6_months') {
                $from = $today->copy()->startOfMonth()->subMonths(6)->max($minDate);
            } elseif ($preset === 'last_12_months') {
                $from = $today->copy()->startOfMonth()->subMonths(12)->max($minDate);
            } elseif ($preset === '2020_present') {
                $from = $minDate->copy();
            } else {
                $from = DateNormalizer::carbonOrNull($request->input('from_date'))?->max($minDate)
                    ?? Carbon::create($today->year, 1, 1);
                $to = DateNormalizer::carbonOrNull($request->input('to_date'))?->min($today)
                    ?? $today->copy();
                if ($from->gt($to)) {
                    $from = $to->copy()->startOfYear()->max($minDate);
                    $to = $to->max($minDate);
                }
            }

            return [
                'mode' => $preset === 'custom' ? 'range' : 'period',
                'preset' => $preset,
                'from' => $from,
                'to' => $to,
                'window' => $window,
            ];
        }

        $mode = $request->input('forecast_mode');
        if (! in_array($mode, ['range', 'period'], true)) {
            $hasDates = $request->filled('from_date') || $request->filled('to_date');
            $mode = $hasDates && ! $request->filled('sma_window') ? 'range' : 'period';
        }

        if ($mode === 'period') {
            $window = SmaForecaster::window($request->input('sma_window', SmaForecaster::DEFAULT_WINDOW));
            $from = $today->copy()->startOfMonth()->subMonths($window + self::PERIOD_HISTORY_MONTHS)->max($minDate);

            return ['mode' => $mode, 'preset' => 'last_12_months', 'from' => $from, 'to' => $today->copy(), 'window' => $window];
        }

        // A date that cannot be read is treated as not given, never a server error.
        $from = DateNormalizer::carbonOrNull($request->input('from_date'))?->max($minDate)
            ?? Carbon::create($today->year, 1, 1);
        $to = DateNormalizer::carbonOrNull($request->input('to_date'))?->min($today)
            ?? $today->copy();
        if ($from->gt($to)) {
            $from = $to->copy()->startOfYear()->max($minDate);
            $to = $to->max($minDate);
        }

        return ['mode' => $mode, 'preset' => 'custom', 'from' => $from, 'to' => $to, 'window' => SmaForecaster::DEFAULT_WINDOW];
    }

    private function calculateForecastData(int $teamId, Carbon $fromDate, Carbon $toDate, int $smaWindow, string $category = 'All', ?int $productId = null): array
    {
        return app(SmaForecaster::class)->series($teamId, $fromDate, $toDate, $smaWindow, $category ?: 'All', $productId);
    }
}
