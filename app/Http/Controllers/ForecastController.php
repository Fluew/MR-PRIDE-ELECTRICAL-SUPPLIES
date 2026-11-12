<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Services\Forecast\SmaForecaster;
use App\Services\Inventory\ProductPrioritizer;
use App\Support\Csv;
use App\Support\DateNormalizer;
use Carbon\Carbon;
use Illuminate\Http\Request;
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
        ]);
    }

    /**
     * Export forecast data to CSV.
     */
    public function exportCsv(Request $request): StreamedResponse
    {
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
