<?php

namespace App\Http\Controllers;

use App\Enums\QuotationStatus;
use App\Enums\SalesOrderStatus;
use App\Http\Middleware\EnsureRole;
use App\Models\AuditLog;
use App\Models\Product;
use App\Models\PurchaseRequest;
use App\Models\Quotation;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Supplier;
use App\Services\Forecast\SmaForecaster;
use App\Services\Inventory\ProductPrioritizer;
use App\Services\Reports\ReportTableExporter;
use App\Services\Suppliers\SupplierEvaluator;
use App\Support\Csv;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    /**
     * Display the comprehensive Reports & Analytics dashboard.
     */
    public function index(Request $request): Response
    {
        $team = $request->user()->currentTeam;
        abort_if(! $team, 403, 'No active team selected.');

        // Date range filtering
        $datePreset = $request->input('date_preset', 'all_time');
        $today = Carbon::today();
        $fromDate = null;
        $toDate = $today->copy()->endOfDay();

        switch ($datePreset) {
            case 'today':
                $fromDate = $today->copy()->startOfDay();
                break;
            case 'last_7_days':
                $fromDate = $today->copy()->subDays(6)->startOfDay();
                break;
            case 'last_30_days':
                $fromDate = $today->copy()->subDays(29)->startOfDay();
                break;
            case 'this_month':
                $fromDate = $today->copy()->startOfMonth();
                break;
            case 'this_year':
                $fromDate = $today->copy()->startOfYear();
                break;
            case 'custom':
                if ($request->filled('from_date')) {
                    $fromDate = Carbon::parse($request->input('from_date'))->startOfDay();
                }
                if ($request->filled('to_date')) {
                    $toDate = Carbon::parse($request->input('to_date'))->endOfDay();
                }
                break;
            case 'all_time':
            default:
                $datePreset = 'all_time';
                $fromDate = null;
                break;
        }

        // ─────────────────────────────────────────────────────────────
        // 1. SECTION A: OVERALL BUSINESS PERFORMANCE OVERVIEW & KPIS
        // ─────────────────────────────────────────────────────────────
        $activeProductsQuery = Product::where('team_id', $team->id)->where('is_active', true);
        $inventoryCount = $activeProductsQuery->count();

        // Accurate Catalog Valuation: stock * COALESCE(cost_price, price)
        $totalValuation = (float) Product::where('team_id', $team->id)
            ->where('is_active', true)
            ->selectRaw('SUM(stock * COALESCE(cost_price, price)) as total_val')
            ->value('total_val') ?? 0.0;

        // Differentiate Low Stock and Out of Stock
        $outOfStockCount = Product::where('team_id', $team->id)
            ->where('is_active', true)
            ->where('stock', '<=', 0)
            ->count();

        $lowStockCount = Product::where('team_id', $team->id)
            ->where('is_active', true)
            ->whereRaw('stock <= COALESCE(reorder_level, min_stock, 10)')
            ->count();

        $inStockCount = max(0, $inventoryCount - $lowStockCount);

        // Sales Metrics (filtered by date range)
        $salesQuery = Sale::where('team_id', $team->id)->whereIn('status', SalesOrderStatus::countedInSales());
        if ($fromDate) {
            $salesQuery->where('sale_date', '>=', $fromDate)->where('sale_date', '<=', $toDate);
        }
        $salesCount = $salesQuery->count();
        $totalRevenue = (float) $salesQuery->sum('grand_total');

        // Quotations Metrics (filtered by date range)
        $quotesQuery = Quotation::where('team_id', $team->id);
        if ($fromDate) {
            $quotesQuery->where('quotation_date', '>=', $fromDate)->where('quotation_date', '<=', $toDate);
        }
        $quotationsCount = $quotesQuery->count();
        $pendingQuotationsCount = (clone $quotesQuery)->where('status', 'Pending')->count();
        $pendingQuotationsValue = (float) (clone $quotesQuery)->where('status', 'Pending')->sum('grand_total');
        $approvedQuotationsCount = (clone $quotesQuery)->where(fn ($q) => $q->whereNotNull('approved_at')->orWhereIn('status', [QuotationStatus::Sent->value, QuotationStatus::Accepted->value]))->count();
        $convertedQuotationsCount = (clone $quotesQuery)->whereNotNull('converted_to_sale_id')->count();

        // Procurement Metrics (filtered by date range)
        $procurementQuery = PurchaseRequest::where('team_id', $team->id);
        if ($fromDate) {
            $procurementQuery->where('created_at', '>=', $fromDate)->where('created_at', '<=', $toDate);
        }
        $totalPurchasesCount = $procurementQuery->count();
        $totalPurchasesEstimatedCost = (float) $procurementQuery->sum('estimated_cost');

        // ─────────────────────────────────────────────────────────────
        // 2. SECTION B: MODULE-SPECIFIC ANALYTICS DATA
        // ─────────────────────────────────────────────────────────────

        // A. Inventory Status Analytics
        $allProducts = Product::where('team_id', $team->id)
            ->where('is_active', true)
            ->with('supplier:id,name')
            ->orderBy('name')
            ->get();

        $inventoryByCategory = [];
        $categoriesSet = [];
        foreach ($allProducts as $p) {
            $cat = $p->category ?: 'General';
            $categoriesSet[$cat] = true;
            if (! isset($inventoryByCategory[$cat])) {
                $inventoryByCategory[$cat] = [
                    'category' => $cat,
                    'count' => 0,
                    'stock' => 0,
                    'valuation' => 0.0,
                ];
            }
            $costBasis = $p->cost_price ?: $p->price;
            $val = $p->stock * (float) $costBasis;
            $inventoryByCategory[$cat]['count']++;
            $inventoryByCategory[$cat]['stock'] += $p->stock;
            $inventoryByCategory[$cat]['valuation'] += $val;
        }

        $topProductsByValuation = $allProducts->map(function ($p) {
            $costBasis = $p->cost_price ?: $p->price;
            $valuation = $p->stock * (float) $costBasis;

            return [
                'id' => $p->id,
                'name' => $p->name,
                'sku' => $p->sku,
                'category' => $p->category ?: 'General',
                'stock' => $p->stock,
                'unit' => $p->unit ?: 'pcs',
                'cost_price' => (float) $p->cost_price,
                'price' => (float) $p->price,
                'valuation' => round($valuation, 2),
            ];
        })->sortByDesc('valuation')->values()->take(8);

        $inventoryTableItems = $allProducts->map(function ($p) {
            $threshold = $p->reorder_level ?: ($p->min_stock ?: 10);
            $costBasis = $p->cost_price ?: $p->price;
            $valuation = $p->stock * (float) $costBasis;

            $status = 'In Stock';
            if ($p->stock <= 0) {
                $status = 'Out of Stock';
            } elseif ($p->stock <= $threshold) {
                $status = 'Low Stock';
            }

            return [
                'id' => $p->id,
                'sku' => $p->sku,
                'name' => $p->name,
                'category' => $p->category ?: 'General',
                'supplier_name' => $p->supplier?->name ?? 'Unassigned',
                'current_stock' => $p->stock,
                'reorder_threshold' => $threshold,
                'unit' => $p->unit ?: 'pcs',
                'cost_price' => (float) $p->cost_price,
                'price' => (float) $p->price,
                'total_valuation' => round($valuation, 2),
                'stock_status' => $status,
            ];
        });

        // B. Low Stock Alert Analytics & Prioritization
        $lowStockProducts = $allProducts->filter(function ($p) {
            $threshold = $p->reorder_level ?: ($p->min_stock ?: 10);

            return $p->stock <= $threshold;
        });

        $lowStockByCategory = [];
        foreach ($lowStockProducts as $p) {
            $cat = $p->category ?: 'General';
            $lowStockByCategory[$cat] = ($lowStockByCategory[$cat] ?? 0) + 1;
        }

        $lowStockTableItems = $lowStockProducts->map(function ($p) {
            $threshold = $p->reorder_level ?: ($p->min_stock ?: 10);
            $shortage = max(0, $threshold - $p->stock);
            $suggestedReorder = max($p->reorder_quantity ?: 50, ($threshold * 2) - $p->stock);

            $urgency = 'Low Stock';
            $sortRank = 2;
            if ($p->stock <= 0) {
                $urgency = 'Out of Stock';
                $sortRank = 1;
            } elseif ($p->stock <= $threshold / 2) {
                $urgency = 'Critical Low';
                $sortRank = 1.5;
            }

            return [
                'id' => $p->id,
                'sku' => $p->sku,
                'name' => $p->name,
                'category' => $p->category ?: 'General',
                'stock' => $p->stock,
                'reorder_level' => $threshold,
                'shortage' => $shortage,
                'suggested_reorder' => $suggestedReorder,
                'supplier_name' => $p->supplier?->name ?? 'Unassigned',
                'urgency' => $urgency,
                'sort_rank' => $sortRank,
                'price' => (float) $p->price,
            ];
        })->sortBy('sort_rank')->values();

        // C. Quotations & Sales Analytics
        // Monthly sales trend over the past 6 months (or custom range)
        $trendPeriods = [];
        $tCursor = ($fromDate ?: $today->copy()->subMonths(5))->copy()->startOfMonth();
        $tEnd = $toDate->copy()->startOfMonth();
        while ($tCursor->lte($tEnd)) {
            $trendPeriods[] = $tCursor->copy();
            $tCursor->addMonth();
        }
        if (count($trendPeriods) > 12) {
            $trendPeriods = array_slice($trendPeriods, -12);
        }

        $salesMovements = Sale::where('team_id', $team->id)
            ->whereIn('status', SalesOrderStatus::countedInSales())
            ->where('sale_date', '>=', $trendPeriods[0]->copy()->startOfMonth())
            ->where('sale_date', '<=', $trendPeriods[count($trendPeriods) - 1]->copy()->endOfMonth())
            ->get(['sale_date', 'grand_total'])
            ->groupBy(fn (Sale $s) => $s->sale_date?->format('Y-m'))
            ->map(fn ($group) => (object) [
                'monthly_rev' => (float) $group->sum('grand_total'),
                'count' => $group->count(),
            ]);

        $salesTrendLabels = [];
        $salesTrendData = [];
        foreach ($trendPeriods as $tp) {
            $ym = $tp->format('Y-m');
            $salesTrendLabels[] = $tp->format('M Y');
            $salesTrendData[] = (float) ($salesMovements[$ym]->monthly_rev ?? 0.0);
        }

        // Quotation Status breakdown
        $quotationStatusCounts = Quotation::where('team_id', $team->id)
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        // Top Selling Products from SaleItem
        $topSellingItems = SaleItem::whereHas('sale', function ($q) use ($team, $fromDate, $toDate) {
            $q->where('team_id', $team->id)->whereIn('status', SalesOrderStatus::countedInSales());
            if ($fromDate) {
                $q->where('sale_date', '>=', $fromDate)->where('sale_date', '<=', $toDate);
            }
        })
            ->selectRaw('product_name, sku, SUM(quantity) as total_qty, SUM(subtotal) as total_amount')
            ->groupBy('product_name', 'sku')
            ->orderByDesc('total_qty')
            ->limit(7)
            ->get()
            ->map(fn ($item) => [
                'name' => $item->product_name,
                'sku' => $item->sku,
                'quantity' => (int) $item->total_qty,
                'amount' => (float) $item->total_amount,
            ]);

        // Conversion calculation
        $conversionRate = $quotationsCount > 0 ? round(($convertedQuotationsCount / $quotationsCount) * 100, 1) : 0.0;

        // Sales table list
        $salesTableItems = Sale::where('team_id', $team->id)
            ->when($fromDate, fn ($q) => $q->where('sale_date', '>=', $fromDate)->where('sale_date', '<=', $toDate))
            ->latest('sale_date')
            ->limit(100)
            ->get()
            ->map(fn ($s) => [
                'id' => $s->id,
                'invoice_number' => $s->invoice_number ?: $s->order_number,
                'order_number' => $s->order_number,
                'client_name' => $s->client_name,
                'sale_date' => $s->sale_date?->format('Y-m-d') ?? 'N/A',
                'subtotal' => (float) $s->subtotal,
                'tax_amount' => (float) $s->tax_amount,
                'grand_total' => (float) $s->grand_total,
                'payment_method' => $s->payment_method ?? 'Cash',
                'status' => $s->status?->label(),
                'payment_status' => $s->payment_status?->label(),
            ]);

        // Quotations table list
        $quotationsTableItems = Quotation::where('team_id', $team->id)
            ->when($fromDate, fn ($q) => $q->where('quotation_date', '>=', $fromDate)->where('quotation_date', '<=', $toDate))
            ->latest('quotation_date')
            ->limit(100)
            ->get()
            ->map(fn ($q) => [
                'id' => $q->id,
                'quotation_number' => $q->quotation_number,
                'client_name' => $q->client_name,
                'quotation_date' => $q->quotation_date?->format('Y-m-d') ?? 'N/A',
                'valid_until' => $q->valid_until?->format('Y-m-d') ?? 'N/A',
                'grand_total' => (float) $q->grand_total,
                'status' => $q->status?->label() ?? '',
                'is_converted' => ! empty($q->converted_to_sale_id),
            ]);

        // D. Demand Forecast Analysis (Historical vs 3-Mo SMA)
        $forecastMonths = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
        $curYear = (int) $today->year;
        // Same SMA-3 as the Demand Forecasting module; no invented history when there are no records.
        $yearSeries = app(SmaForecaster::class)->series($team->id, Carbon::create($curYear, 1, 1), Carbon::create($curYear, 12, 31), 3);
        $historicalDemand = $yearSeries['historical'];
        $smaForecast = $yearSeries['forecast'];

        // E. Procurement & Purchasing Analytics
        $procurementStatusCounts = PurchaseRequest::where('team_id', $team->id)
            ->selectRaw('status, COUNT(*) as count, SUM(estimated_cost) as total_cost')
            ->groupBy('status')
            ->get()
            ->keyBy('status');

        $procurementTableItems = PurchaseRequest::where('team_id', $team->id)
            ->when($fromDate, fn ($q) => $q->where('created_at', '>=', $fromDate)->where('created_at', '<=', $toDate))
            ->with(['product:id,name,sku', 'supplier:id,name', 'requestedBy:id,name'])
            ->latest('id')
            ->limit(100)
            ->get()
            ->map(fn ($pr) => [
                'id' => $pr->id,
                'request_number' => $pr->request_number,
                'product_name' => $pr->product?->name ?? ($pr->product_description !== null ? 'Not listed: '.$pr->product_description : 'Unknown Item'),
                'sku' => $pr->product?->sku ?? 'N/A',
                'supplier_name' => $pr->supplier?->name ?? 'N/A',
                'requested_by' => $pr->requestedBy?->name ?? 'Staff',
                'quantity' => $pr->quantity,
                'estimated_cost' => (float) $pr->estimated_cost,
                'status' => $pr->status,
                'date' => $pr->requested_at?->format('Y-m-d') ?? $pr->created_at->format('Y-m-d'),
            ]);

        // AHP Product Prioritization forecasting period (?forecast_months=3|6|12).
        $priorityMonths = SmaForecaster::window($request->input('forecast_months', SmaForecaster::DEFAULT_WINDOW));

        // F. Supplier AHP Prioritization Analytics
        $ahpRankingItems = $this->rankedSupplierEvaluations($team->id)->values()->map(function ($row, $idx) {
            [$s, $e] = $row;

            return [
                'rank' => $e['rank'] ?? null, // from SupplierEvaluator (equal scores share a rank)
                'id' => $s->id,
                'name' => $s->name,
                'contact_person' => $s->contact_person ?? 'N/A',
                'email' => $s->email ?? 'N/A',
                'phone' => $s->phone ?? 'N/A',
                'ahp_score' => $e['score'],
                'classification' => $e['classification'],
                'complete' => $e['complete'],
                'metrics' => collect($e['criteria'])->mapWithKeys(fn ($c) => [$c['key'] => $c['score']])->all(),
                'evaluation' => $e,
                'status' => $s->status,
            ];
        });

        // G. Recent Activity Feed (sourced directly from System Audit Trail / AuditLog)
        $activityModules = [
            'Sales',
            'Inventory',
            'Procurement',
            'Suppliers',
            'User Management',
            'System Maintenance',
        ];

        $auditQuery = AuditLog::with(['user:id,name,email', 'targetUser:id,name,email'])
            ->when($fromDate, fn ($q) => $q->where('created_at', '>=', $fromDate)->where('created_at', '<=', $toDate))
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if ($request->filled('activity_from')) {
            $auditQuery->whereDate('created_at', '>=', $request->input('activity_from'));
        }
        if ($request->filled('activity_to')) {
            $auditQuery->whereDate('created_at', '<=', $request->input('activity_to'));
        }

        $rawAuditLogs = $auditQuery->limit(300)->get();

        $activityByModule = array_fill_keys($activityModules, 0);
        $activityItems = $rawAuditLogs->map(function (AuditLog $log) use (&$activityByModule) {
            $mapped = $this->transformAuditLogForFeed($log);
            $mod = $mapped['module'];
            $activityByModule[$mod] = ($activityByModule[$mod] ?? 0) + 1;

            return $mapped;
        })->values();

        if ($request->filled('activity_module') && $request->input('activity_module') !== 'All') {
            $reqMod = $request->input('activity_module');
            $activityItems = $activityItems->where('module', $reqMod)->values();
        }

        return Inertia::render('Reports/Index', [
            // Standard / Preserved Metrics
            'metrics' => [
                'inventoryCount' => $inventoryCount,
                'totalValuation' => $totalValuation,
                'lowStockCount' => $lowStockCount,
                'outOfStockCount' => $outOfStockCount,
                'inStockCount' => $inStockCount,
                'quotationsCount' => $quotationsCount,
                'salesCount' => $salesCount,
                'totalRevenue' => $totalRevenue,
                'pendingQuotationsCount' => $pendingQuotationsCount,
                'pendingQuotationsValue' => $pendingQuotationsValue,
                'approvedQuotationsCount' => $approvedQuotationsCount,
                'totalPurchasesCount' => $totalPurchasesCount,
                'totalPurchasesEstimatedCost' => $totalPurchasesEstimatedCost,
                'valuationBasisNote' => 'Valuation based on actual recorded cost price (with selling price fallback) × available stock.',
            ],

            // Active Date Filter Info
            'dateFilter' => [
                'preset' => $datePreset,
                'fromDate' => $fromDate ? $fromDate->format('Y-m-d') : null,
                'toDate' => $toDate ? $toDate->format('Y-m-d') : null,
            ],

            // For the printed business summary.
            'companyName' => config('company.name'),

            // Available Categories & Products
            'categories' => array_keys($categoriesSet),

            // Module Analytics Packages
            'inventoryAnalytics' => [
                'statusDistribution' => [
                    'in_stock' => $inStockCount,
                    'low_stock' => max(0, $lowStockCount - $outOfStockCount),
                    'out_of_stock' => $outOfStockCount,
                ],
                'byCategory' => array_values($inventoryByCategory),
                'topProductsByValuation' => $topProductsByValuation,
                'items' => $inventoryTableItems,
            ],

            'lowStockAnalytics' => [
                'byCategory' => $lowStockByCategory,
                'severity' => $lowStockTableItems->take(8)->values(),
                'items' => $lowStockTableItems,
            ],

            'quotationsSalesAnalytics' => [
                'salesTrend' => [
                    'labels' => $salesTrendLabels,
                    'data' => $salesTrendData,
                ],
                'quotationStatusDistribution' => $quotationStatusCounts,
                'topSellingProducts' => $topSellingItems,
                'conversion' => [
                    'total' => $quotationsCount,
                    'approved' => $approvedQuotationsCount,
                    'converted' => $convertedQuotationsCount,
                    'rate' => $conversionRate,
                ],
                'salesList' => $salesTableItems,
                'quotationsList' => $quotationsTableItems,
            ],

            'forecastAnalytics' => [
                'labels' => $forecastMonths,
                'historical' => $historicalDemand,
                'forecast' => $smaForecast,
                'year' => $curYear,
                'window' => 3,
                'hasData' => $yearSeries['hasData'],
                'steps' => $yearSeries['steps'],
                'sourceRecordCount' => $yearSeries['sourceRecordCount'],
            ],

            'procurementAnalytics' => [
                'statusDistribution' => [
                    'Draft' => (int) ($procurementStatusCounts['Draft']->count ?? 0),
                    'Pending Approval' => (int) (($procurementStatusCounts['Pending Approval']->count ?? 0) + ($procurementStatusCounts['Requested']->count ?? 0)),
                    'Approved' => (int) ($procurementStatusCounts['Approved']->count ?? 0),
                    'Converted to Purchase Order' => (int) (($procurementStatusCounts['Converted to Purchase Order']->count ?? 0) + ($procurementStatusCounts['Ordered']->count ?? 0) + ($procurementStatusCounts['Delivered']->count ?? 0)),
                    'Rejected' => (int) (($procurementStatusCounts['Rejected']->count ?? 0) + ($procurementStatusCounts['Cancelled']->count ?? 0)),
                ],
                'totalEstimatedCost' => $totalPurchasesEstimatedCost,
                'requestsList' => $procurementTableItems,
            ],

            'ahpAnalytics' => [
                'topSuppliers' => $ahpRankingItems->take(6)->values(),
                'suppliersList' => $ahpRankingItems,
                'weights' => config('evaluation.supplier.weights'),
                'deliverySplit' => config('evaluation.supplier.delivery_split'),
                'periodMonths' => (int) config('evaluation.supplier.period_months'),
                'classes' => config('evaluation.supplier.classes'),
            ],

            // AHP Product Prioritization (moved here from Inventory): rankings, reorder
            // recommendations and the forecasting period (3, 6 or 12 months = the SMA window).
            'productPriority' => [
                'items' => array_values(app(ProductPrioritizer::class)->forTeam($team->id, null, $priorityMonths)),
                'weights' => config('evaluation.product_priority.weights'),
                'classes' => config('evaluation.product_priority.classes'),
                'movementMonths' => (int) config('evaluation.product_priority.movement_months'),
                'smaWindow' => $priorityMonths,
                'forecastMonths' => $priorityMonths,
                'forecastPeriods' => SmaForecaster::PERIOD_LABELS,
                // Same rule as the purchase request routes (admin, procurement or inventory staff).
                'canRequestPurchase' => EnsureRole::allows($request->user(), ['admin', 'staff_procurement', 'staff_inventory']),
            ],

            // Recent Activity Feed (System Audit Trail)
            'activityFeed' => [
                'items' => $activityItems,
                'modules' => $activityModules,
                'byModule' => $activityByModule,
                'totalCount' => $activityItems->count(),
            ],
        ]);
    }

    /**
     * Export the report view on screen, as filtered, in the chosen format. The page sends the
     * tables it shows (period, view filters and report search already applied); this only lays
     * them out as Excel, PDF, CSV, PNG or JPG.
     */
    public function export(Request $request, ReportTableExporter $exporter): SymfonyResponse
    {
        $team = $request->user()->currentTeam;
        abort_if(! $team, 403);

        // The page downloads the file with fetch(), so problems come back as JSON (this app renders
        // JSON errors only for api/* routes by default).
        try {
            $data = $request->validate([
                'format' => ['required', Rule::in(ReportTableExporter::FORMATS)],
                'title' => ['required', 'string', 'max:120'],
                'scope' => ['nullable', 'string', 'max:300'],
                'sections' => ['required', 'array', 'min:1', 'max:'.ReportTableExporter::MAX_SECTIONS],
            ]);

            return $exporter->download($data['format'], $exporter->normalize($data['sections']), [
                'title' => $data['title'],
                'team' => $team->name,
                'scope' => $data['scope'] ?: 'All records',
            ]);
        } catch (ValidationException $e) {
            return response()->json(['message' => collect($e->errors())->flatten()->first(), 'errors' => $e->errors()], 422);
        }
    }

    /**
     * Export Inventory Status to CSV (supports category and status filters).
     */
    public function exportInventory(Request $request): StreamedResponse
    {
        $team = $request->user()->currentTeam;
        abort_if(! $team, 403);

        $category = $request->input('category');
        $status = $request->input('status');

        $query = Product::where('team_id', $team->id)->where('is_active', true)->with('supplier:id,name');

        if ($category && $category !== 'All') {
            $query->where('category', $category);
        }

        $products = $query->get();

        if ($status && $status !== 'All') {
            $products = $products->filter(function ($p) use ($status) {
                $thresh = $p->reorder_level ?: ($p->min_stock ?: 10);
                if ($status === 'Out of Stock') {
                    return $p->stock <= 0;
                }
                if ($status === 'Low Stock') {
                    return $p->stock > 0 && $p->stock <= $thresh;
                }
                if ($status === 'In Stock') {
                    return $p->stock > $thresh;
                }

                return true;
            });
        }

        $fileName = 'inventory_status_'.date('Y-m-d').'.csv';

        return $this->streamCsv($fileName, ['SKU', 'Product Name', 'Category', 'Unit', 'Price (PHP)', 'Cost Price (PHP)', 'Current Stock', 'Total Valuation (PHP)', 'Supplier', 'Status'], function ($file) use ($products) {
            foreach ($products as $p) {
                $costBasis = $p->cost_price ?: $p->price;
                $val = $p->stock * (float) $costBasis;
                $thresh = $p->reorder_level ?: ($p->min_stock ?: 10);
                $st = $p->stock <= 0 ? 'Out of Stock' : ($p->stock <= $thresh ? 'Low Stock' : 'In Stock');

                Csv::put($file, [
                    $p->sku,
                    $p->name,
                    $p->category ?: 'General',
                    $p->unit ?? 'pcs',
                    number_format($p->price, 2),
                    number_format($p->cost_price ?? 0, 2),
                    $p->stock,
                    number_format($val, 2),
                    $p->supplier?->name ?? 'Unassigned',
                    $st,
                ]);
            }
        });
    }

    /**
     * Export Low Stock Alert to CSV (supports category filter).
     */
    public function exportLowStock(Request $request): StreamedResponse
    {
        $team = $request->user()->currentTeam;
        abort_if(! $team, 403);

        $category = $request->input('category');

        $query = Product::where('team_id', $team->id)
            ->where('is_active', true)
            ->whereRaw('stock <= COALESCE(reorder_level, min_stock, 10)')
            ->with('supplier:id,name');

        if ($category && $category !== 'All') {
            $query->where('category', $category);
        }

        $products = $query->get()->sortBy(fn ($p) => $p->stock <= 0 ? 0 : $p->stock);

        $fileName = 'low_stock_audit_'.date('Y-m-d').'.csv';

        return $this->streamCsv($fileName, ['SKU', 'Product Name', 'Category', 'Current Stock', 'Reorder Level', 'Stock Shortage', 'Suggested Reorder', 'Primary Supplier', 'Urgency Status'], function ($file) use ($products) {
            foreach ($products as $p) {
                $lvl = $p->reorder_level ?: ($p->min_stock ?: 10);
                $shortage = max(0, $lvl - $p->stock);
                $suggested = max($p->reorder_quantity ?: 50, ($lvl * 2) - $p->stock);
                $urgency = $p->stock <= 0 ? 'CRITICAL - Out of Stock' : ($p->stock <= $lvl / 2 ? 'URGENT' : 'LOW STOCK');

                Csv::put($file, [
                    $p->sku,
                    $p->name,
                    $p->category ?: 'General',
                    $p->stock,
                    $lvl,
                    $shortage,
                    $suggested,
                    $p->supplier?->name ?? 'Unassigned',
                    $urgency,
                ]);
            }
        });
    }

    /**
     * Export Quotations and Sales to CSV.
     */
    public function exportQuotationsSales(Request $request): StreamedResponse
    {
        $team = $request->user()->currentTeam;
        abort_if(! $team, 403);

        $exportType = $request->input('type', 'sales'); // 'sales', 'quotations', or 'both'

        if ($exportType === 'quotations') {
            $fileName = 'quotations_report_'.date('Y-m-d').'.csv';
            $quotes = Quotation::where('team_id', $team->id)->latest('quotation_date')->get();

            return $this->streamCsv($fileName, ['Quotation Number', 'Client Name', 'Date', 'Valid Until', 'Subtotal (PHP)', 'Tax (PHP)', 'Grand Total (PHP)', 'Status', 'Converted to Sale'], function ($file) use ($quotes) {
                foreach ($quotes as $q) {
                    Csv::put($file, [
                        $q->quotation_number,
                        $q->client_name,
                        $q->quotation_date?->format('Y-m-d') ?? 'N/A',
                        $q->valid_until?->format('Y-m-d') ?? 'N/A',
                        number_format($q->subtotal, 2),
                        number_format($q->tax_amount, 2),
                        number_format($q->grand_total, 2),
                        $q->status?->label() ?? '',
                        $q->converted_to_sale_id ? 'Yes' : 'No',
                    ]);
                }
            });
        }

        $fileName = 'sales_report_'.date('Y-m-d').'.csv';
        $sales = Sale::where('team_id', $team->id)->latest('sale_date')->get();

        return $this->streamCsv($fileName, ['Invoice Number', 'Client Name', 'Sale Date', 'Subtotal (PHP)', 'Tax (PHP)', 'Grand Total (PHP)', 'Payment Method', 'Status'], function ($file) use ($sales) {
            foreach ($sales as $s) {
                Csv::put($file, [
                    $s->invoice_number,
                    $s->client_name,
                    $s->sale_date?->format('Y-m-d H:i') ?? 'N/A',
                    number_format($s->subtotal, 2),
                    number_format($s->tax_amount, 2),
                    number_format($s->grand_total, 2),
                    $s->payment_method ?? 'Cash',
                    $s->status?->label(),
                ]);
            }
        });
    }

    /**
     * Export Forecast Demand to CSV.
     */
    public function exportForecast(Request $request): StreamedResponse
    {
        $team = $request->user()->currentTeam;
        abort_if(! $team, 403);

        $year = (int) $request->input('year', date('Y'));
        $fileName = "forecast_analysis_{$year}_".date('Y-m-d').'.csv';

        $labels = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
        $series = app(SmaForecaster::class)->series($team->id, Carbon::create($year, 1, 1), Carbon::create($year, 12, 31), 3);

        return $this->streamCsv($fileName, ['Month', 'Recorded Demand (Units)', 'SMA-3 Forecast (Units)'], function ($file) use ($labels, $series) {
            foreach ($labels as $idx => $mLabel) {
                Csv::put($file, [$mLabel, $series['historical'][$idx], $series['forecast'][$idx] ?? 'N/A']);
            }
        });
    }

    /**
     * Export Procurement / Purchase Requests to CSV.
     */
    public function exportProcurement(Request $request): StreamedResponse
    {
        $team = $request->user()->currentTeam;
        abort_if(! $team, 403);

        $fileName = 'procurement_report_'.date('Y-m-d').'.csv';
        $requests = PurchaseRequest::where('team_id', $team->id)
            ->with(['product:id,name,sku', 'supplier:id,name', 'requestedBy:id,name'])
            ->latest('id')
            ->get();

        return $this->streamCsv($fileName, ['Request Number', 'Product SKU', 'Product Name', 'Supplier', 'Quantity', 'Estimated Cost (PHP)', 'Requested By', 'Status', 'Date'], function ($file) use ($requests) {
            foreach ($requests as $r) {
                Csv::put($file, [
                    $r->request_number,
                    $r->product?->sku ?? 'N/A',
                    $r->product?->name ?? 'N/A',
                    $r->supplier?->name ?? 'N/A',
                    $r->quantity,
                    number_format($r->estimated_cost, 2),
                    $r->requestedBy?->name ?? 'N/A',
                    $r->status,
                    $r->requested_at?->format('Y-m-d') ?? $r->created_at->format('Y-m-d'),
                ]);
            }
        });
    }

    /**
     * Export Supplier AHP Rankings to CSV.
     */
    public function exportAhp(Request $request): StreamedResponse
    {
        $team = $request->user()->currentTeam;
        abort_if(! $team, 403);

        $fileName = 'supplier_ahp_report_'.date('Y-m-d').'.csv';
        $rows = $this->rankedSupplierEvaluations($team->id);
        $na = fn ($v) => $v === null ? 'Insufficient data' : number_format($v, 2);

        return $this->streamCsv($fileName, ['Rank', 'Supplier Name', 'Contact Person', 'Email', 'Phone', 'Evaluation Score (0-100)', 'Classification', 'Delivery Score', 'Price Score', 'Quality Score', 'Evaluation Period', 'Calculated At', 'Status'], function ($file) use ($rows, $na) {
            foreach ($rows->values() as $idx => [$s, $e]) {
                $scores = collect($e['criteria'])->mapWithKeys(fn ($c) => [$c['key'] => $c['score']]);
                Csv::put($file, [
                    $e['complete'] ? $idx + 1 : '—',
                    $s->name,
                    $s->contact_person ?? 'N/A',
                    $s->email ?? 'N/A',
                    $s->phone ?? 'N/A',
                    $na($e['score']),
                    $e['classification'] ?? 'Not classified (insufficient data)',
                    $na($scores['delivery']),
                    $na($scores['price']),
                    $na($scores['quality']),
                    $e['period']['from'].' to '.$e['period']['to'],
                    $e['calculated_at'],
                    $s->status,
                ]);
            }
        });
    }

    /**
     * Export Recent Activity Feed (System Audit Trail) to CSV.
     */
    public function exportActivityFeed(Request $request): StreamedResponse
    {
        $team = $request->user()->currentTeam;
        abort_if(! $team, 403);

        $moduleFilter = $request->input('module', 'All');
        $fromDate = $request->input('from_date');
        $toDate = $request->input('to_date');

        $query = AuditLog::with(['user:id,name,email', 'targetUser:id,name,email'])
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if ($fromDate) {
            $query->whereDate('created_at', '>=', $fromDate);
        }
        if ($toDate) {
            $query->whereDate('created_at', '<=', $toDate);
        }

        $logs = $query->limit(500)->get()->map(fn (AuditLog $log) => $this->transformAuditLogForFeed($log));

        if ($moduleFilter && $moduleFilter !== 'All') {
            $logs = $logs->where('module', $moduleFilter)->values();
        }

        $fileName = 'recent_activity_feed_'.date('Y-m-d').'.csv';

        return $this->streamCsv($fileName, ['Timestamp', 'User', 'User Email', 'Action Performed', 'Affected Module', 'Record Reference', 'Description', 'Outcome'], function ($file) use ($logs) {
            foreach ($logs as $item) {
                Csv::put($file, [
                    $item['timestamp'],
                    $item['user'],
                    $item['user_email'],
                    $item['action'],
                    $item['module'],
                    $item['reference'],
                    $item['description'],
                    $item['outcome'],
                ]);
            }
        });
    }

    /**
     * Transform an AuditLog record into a normalized Recent Activity Feed row.
     *
     * @return array<string, mixed>
     */
    private function transformAuditLogForFeed(AuditLog $log): array
    {
        $userName = $log->user?->name
            ?: ($log->user_email ? explode('@', $log->user_email)[0] : 'System');
        $userEmail = $log->user_email ?: ($log->user?->email ?? 'system@mrpride.local');

        return [
            'id' => $log->id,
            'user' => $userName,
            'user_email' => $userEmail,
            'action' => $this->resolveAuditActionLabel($log),
            'event' => (string) ($log->event ?: $log->action_type ?: 'system.event'),
            'description' => (string) ($log->description ?? ''),
            'module' => $this->resolveAuditModule($log),
            'reference' => $this->resolveAuditReference($log),
            'outcome' => (string) ($log->outcome ?: 'success'),
            'timestamp' => $log->created_at?->format('M d, Y h:i:s A') ?? 'N/A',
            'date' => $log->created_at?->format('Y-m-d') ?? '',
            'relative_time' => $log->created_at?->diffForHumans() ?? '',
        ];
    }

    /**
     * Classify an AuditLog record into its affected system module.
     */
    private function resolveAuditModule(AuditLog $log): string
    {
        $event = strtolower((string) ($log->event ?: $log->action_type));
        $subjectType = strtolower((string) $log->subject_type);

        if (
            str_starts_with($event, 'system.maintenance') ||
            str_starts_with($event, 'maintenance.') ||
            str_starts_with($event, 'recycle_bin.') ||
            str_contains($event, 'recycle_bin')
        ) {
            return 'System Maintenance';
        }

        if (
            str_starts_with($event, 'sale.') ||
            str_starts_with($event, 'sales.') ||
            str_starts_with($event, 'quotation.') ||
            str_starts_with($event, 'pos.') ||
            in_array($subjectType, ['sale', 'saleitem', 'quotation', 'quotationitem'], true)
        ) {
            return 'Sales';
        }

        if (
            str_starts_with($event, 'product.') ||
            str_starts_with($event, 'inventory.') ||
            str_starts_with($event, 'stock.') ||
            in_array($subjectType, ['product', 'stockmovement', 'inventoryimport'], true)
        ) {
            return 'Inventory';
        }

        if (
            str_starts_with($event, 'purchase_request.') ||
            str_starts_with($event, 'purchase_order.') ||
            str_starts_with($event, 'procurement.') ||
            str_starts_with($event, 'delivery.') ||
            str_starts_with($event, 'supplier_invoice.') ||
            str_starts_with($event, 'invoice.') ||
            in_array($subjectType, ['purchaserequest', 'purchaseorder', 'purchaseorderitem', 'delivery', 'deliveryitem', 'supplierinvoice'], true)
        ) {
            return 'Procurement';
        }

        if (
            str_starts_with($event, 'supplier.') ||
            str_starts_with($event, 'supplier_quote.') ||
            str_starts_with($event, 'supplier_availability.') ||
            in_array($subjectType, ['supplier', 'supplierquote', 'productsupplier'], true)
        ) {
            return 'Suppliers';
        }

        if (
            str_starts_with($event, 'user.') ||
            str_starts_with($event, 'account.') ||
            str_starts_with($event, 'session.') ||
            str_starts_with($event, 'device.') ||
            str_starts_with($event, 'auth.') ||
            in_array($subjectType, ['user', 'trusteddevice'], true) ||
            $log->category === 'security'
        ) {
            return 'User Management';
        }

        return 'System Maintenance';
    }

    /**
     * Extract a concise human-readable action label from an AuditLog entry.
     */
    private function resolveAuditActionLabel(AuditLog $log): string
    {
        $raw = (string) ($log->event ?: $log->action_type ?: 'Activity Recorded');
        $normalized = str_replace(['.', '_', '-'], ' ', $raw);
        $normalized = preg_replace('/^system\s+/i', '', $normalized) ?? $normalized;

        return ucwords(trim($normalized));
    }

    /**
     * Extract the affected record reference from an AuditLog entry.
     */
    private function resolveAuditReference(AuditLog $log): string
    {
        $meta = is_array($log->metadata) ? $log->metadata : [];

        $directKeys = [
            'order_number',
            'invoice_number',
            'quotation_number',
            'request_number',
            'po_number',
            'delivery_number',
            'reference_number',
            'reference',
            'sku',
            'device_name',
            'device_uuid',
            'label',
            'name',
        ];

        foreach ($directKeys as $key) {
            if (! empty($meta[$key]) && is_scalar($meta[$key])) {
                return (string) $meta[$key];
            }
        }

        foreach (['sale', 'quotation', 'product', 'purchase_request', 'purchase_order', 'supplier', 'delivery'] as $nested) {
            if (! empty($meta[$nested]) && is_array($meta[$nested])) {
                foreach ($directKeys as $key) {
                    if (! empty($meta[$nested][$key]) && is_scalar($meta[$nested][$key])) {
                        return (string) $meta[$nested][$key];
                    }
                }
            }
        }

        $desc = (string) ($log->description ?? '');
        if (preg_match('/\b((?:SO|INV|QT|PR|PO|DEL|GRN|SI|INIT|IN|OUT|ADJ)-[A-Z0-9-]+|[A-Z]{2,6}-\d{3,6})\b/', $desc, $matches)) {
            return $matches[1];
        }

        if (! empty($log->target_user_email)) {
            return $log->target_user_email;
        }

        if (! empty($log->subject_type) && ! empty($log->subject_id)) {
            return "{$log->subject_type} #{$log->subject_id}";
        }

        $event = strtolower((string) $log->event);
        if (str_contains($event, 'maintenance')) {
            return 'Maintenance Mode';
        }
        if (str_contains($event, 'recycle_bin')) {
            return 'Recycle Bin';
        }

        return 'System';
    }

    /**
     * Suppliers with their record-based evaluation: complete scores first (highest first), then the rest by name.
     *
     * @return Collection<int, array{0: Supplier, 1: array}>
     */
    private function rankedSupplierEvaluations(int $teamId): Collection
    {
        $evaluations = app(SupplierEvaluator::class)->forTeam($teamId);

        return Supplier::where('team_id', $teamId)->orderBy('name')->get()
            ->map(fn ($s) => [$s, $evaluations[$s->id]])
            ->sortBy([
                fn ($a, $b) => $b[1]['complete'] <=> $a[1]['complete'],
                fn ($a, $b) => ($b[1]['score'] ?? -1) <=> ($a[1]['score'] ?? -1),
            ]);
    }

    /**
     * Helper to stream a CSV download.
     */
    private function streamCsv(string $fileName, array $headersList, callable $callback): StreamedResponse
    {
        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$fileName}\"",
            'Pragma' => 'no-cache',
            'Cache-Control' => 'must-revalidate, post-check=0, pre-check=0',
            'Expires' => '0',
        ];

        return response()->stream(function () use ($headersList, $callback) {
            $file = fopen('php://output', 'w');
            // Add UTF-8 BOM for Microsoft Excel auto-encoding
            fprintf($file, chr(0xEF).chr(0xBB).chr(0xBF));
            Csv::put($file, $headersList);
            $callback($file);
            fclose($file);
        }, 200, $headers);
    }
}
