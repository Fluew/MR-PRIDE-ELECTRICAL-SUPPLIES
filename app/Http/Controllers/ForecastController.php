<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Services\Forecast\SmaForecaster;
use App\Services\Inventory\ProductPrioritizer;
use App\Support\Csv;
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

        // Resolve from_date / to_date from request (fallback: current year start → today)
        $fromDate = $request->filled('from_date')
            ? Carbon::parse($request->input('from_date'))->max($minDate)
            : Carbon::create($today->year, 1, 1);

        $toDate = $request->filled('to_date')
            ? Carbon::parse($request->input('to_date'))->min($today)
            : $today->copy();

        // Guard: from must not be after to
        if ($fromDate->gt($toDate)) {
            $fromDate = Carbon::create($today->year, 1, 1);
        }

        $smaWindow = SmaForecaster::window($request->input('sma_window', SmaForecaster::DEFAULT_WINDOW));

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
            ->get(['id', 'name', 'sku', 'category', 'stock', 'reorder_level', 'min_stock', 'price', 'unit']);

        // Selected product details
        $selectedProduct = null;
        if ($productId) {
            $selectedProduct = Product::where('team_id', $team->id)->find($productId);
            if ($selectedProduct && $category !== 'All' && $selectedProduct->category !== $category) {
                $category = $selectedProduct->category;
            }
        }

        $data = $this->calculateForecastData($team->id, $fromDate, $toDate, $smaWindow, $category, $productId);

        // Product Forecasting Window data
        $productWindow = null;
        if ($selectedProduct) {
            $threshold = $selectedProduct->reorder_level ?: ($selectedProduct->min_stock ?: 10);
            $forecastNonZero = array_filter($data['forecast'], fn ($v) => $v !== null && $v > 0);
            // No forecast means no demand estimate: shown as insufficient data, never a default.
            $avgForecastDemand = ! empty($forecastNonZero) ? (int) round(array_sum($forecastNonZero) / count($forecastNonZero)) : null;
            $daysOfSupply = $avgForecastDemand ? (int) round($selectedProduct->stock / ($avgForecastDemand / 30)) : null;
            $recommendedRestock = max($selectedProduct->reorder_quantity ?: 50, ($threshold * 2) - $selectedProduct->stock);

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
            ->map(function ($p) {
                $threshold = $p->reorder_level ?: ($p->min_stock ?: 10);
                $urgency = $p->stock <= 0 ? 'Urgent' : ($p->stock <= $threshold / 2 ? 'Soon' : 'Monitor');
                $predictedNeed = max($p->reorder_quantity ?: 50, ($threshold * 2) - $p->stock);

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
        $productForecasts = collect(app(ProductPrioritizer::class)->forTeam($team->id, null, $smaWindow))
            ->only($scopeIds)
            ->sortBy(fn ($p) => [$p['reorder']['needed'] ? 0 : 1, -($p['forecast']['period_total'] ?? -1), $p['product']['name']])
            ->map(fn ($p) => [
                'product' => $p['product'],
                'available_stock' => $p['available_stock'],
                'forecast' => $p['forecast'],
                'classification' => $p['classification'],
                'score' => $p['score'],
                'reorder' => ['needed' => $p['reorder']['needed'], 'suggested_quantity' => $p['reorder']['suggested_quantity'], 'basis' => $p['reorder']['basis']],
            ])->values();

        return Inertia::render('Forecast/Index', [
            'fromDate' => $fromDate->format('Y-m-d'),
            'toDate' => $toDate->format('Y-m-d'),
            'smaWindow' => $smaWindow,
            'forecastPeriods' => SmaForecaster::PERIOD_LABELS,
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

        $today = Carbon::today();
        $minDate = Carbon::create(2020, 1, 1);

        $fromDate = $request->filled('from_date')
            ? Carbon::parse($request->input('from_date'))->max($minDate)
            : Carbon::create($today->year, 1, 1);

        $toDate = $request->filled('to_date')
            ? Carbon::parse($request->input('to_date'))->min($today)
            : $today->copy();

        if ($fromDate->gt($toDate)) {
            $fromDate = Carbon::create($today->year, 1, 1);
        }

        $smaWindow = (int) $request->input('sma_window', 3);
        $smaWindow = in_array($smaWindow, [3, 6, 12]) ? $smaWindow : 3;

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

    private function calculateForecastData(int $teamId, Carbon $fromDate, Carbon $toDate, int $smaWindow, string $category = 'All', ?int $productId = null): array
    {
        return app(SmaForecaster::class)->series($teamId, $fromDate, $toDate, $smaWindow, $category ?: 'All', $productId);
    }
}
