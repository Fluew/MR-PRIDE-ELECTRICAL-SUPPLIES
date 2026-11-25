<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import { ref, computed, watch } from 'vue';
import { index as forecastIndex } from '@/actions/App/Http/Controllers/ForecastController';
import { exportCsv as forecastExport } from '@/actions/App/Http/Controllers/ForecastController';
import type { PeriodPlanning, ForecastMetrics } from '@/types/forecast';
import { LineChart as Line } from '@/lib/lazyCharts';
import { continuousForecastLine } from '@/lib/forecastLine';
import TopPerformersPanel, { type TopPerformers } from '@/components/forecast/TopPerformersPanel.vue';
import StatusBadge from '@/components/ui/StatusBadge.vue';
import { useCurrency } from '@/composables/useCurrency';
import { BarChart3, Download, Minus, Search, TrendingDown, TrendingUp, X } from '@lucide/vue';

interface RestockItem {
    id: number;
    sku: string;
    name: string;
    category?: string;
    stock: number;
    threshold: number;
    predictedNeed: number;
    urgency: 'Urgent' | 'Soon' | 'Monitor';
}

interface ProductListItem {
    id: number;
    name: string;
    sku: string;
    description?: string | null;
    category: string;
    stock: number;
    reorder_level: number | null;
    min_stock: number | null;
    price: string | number;
    unit: string | null;
}

interface ProductWindow {
    id: number;
    name: string;
    sku: string;
    category: string;
    unit: string;
    price: number;
    stock: number;
    threshold: number;
    reorderQuantity: number;
    avgMonthlyDemand: number | null;
    daysOfSupply: number | null;
    recommendedRestock: number;
    urgency: 'Safe' | 'Needs Reorder' | 'Critical Low' | 'Out of Stock';
}

interface CategoryWindow {
    name: string;
    totalItems: number;
    totalStock: number;
    lowStockItems: number;
    avgMonthlyDemand: number | null;
}

interface MetricInputPoint {
    month: string;
    ym?: string;
    value?: number;
    units?: number;
}

interface MetricWindowSummary {
    available: boolean;
    window: number;
    sum: number;
    decimal: number | null;
    rounded: number | null;
    period_total: number | null;
    inputs: MetricInputPoint[];
    reason: string | null;
    eligible_products?: number;
}

interface BacktestComparison {
    month: string;
    ym: string;
    actual: number;
    predicted_decimal: number;
    predicted_rounded: number;
    abs_error_decimal: number;
    abs_error_rounded: number;
    ape: number | null;
}

interface BacktestResult {
    rmse?: number | null;
    months: number;
    mae: number | null;
    decimal_mae: number | null;
    wape: number | null;
    decimal_wape: number | null;
    mape: number | null;
    decimal_mape: number | null;
    mape_months: number;
    zero_actual_months: number;
    total_actual: number;
    total_abs_error: number;
    comparisons: BacktestComparison[];
}

interface MetricSeriesPoint {
    month: string;
    ym: string;
    is_forecast: boolean;
    status: 'complete' | 'in_progress' | 'insufficient_history' | 'forecast';
    actual: number | null;
    sma_decimal: number | null;
    sma_rounded: number | null;
    inputs: { month: string; value: number }[];
}

interface MetricSeriesData {
    metric: 'sales_transactions' | 'products_involved' | 'units_sold';
    labels: string[];
    months: string[];
    actual: (number | null)[];
    sma_decimal: (number | null)[];
    sma_rounded: (number | null)[];
    next_month_projection: (number | null)[];
    partial_actual?: (number | null)[];
    next_month_forecast: MetricWindowSummary;
    points: MetricSeriesPoint[];
    backtest: BacktestResult;
}

interface ProductSmaWindowDetail {
    window: number;
    available: boolean;
    sum: number;
    decimal: number | null;
    forecast: number | null;
    period_forecast: number | null;
    inputs: { month: string; units: number }[];
    reason: string | null;
    trend: string;
}

interface ProductForecastRow {
    product: {
        id: number;
        name: string;
        sku: string;
        description?: string | null;
        category?: string | null;
        unit: string;
    };
    available_stock: number;
    forecast: {
        accuracy?: ForecastMetrics;
        accuracy_band?: string;
        demand_pattern?: { label: string; zero_share: number | null; intermittent: boolean };
        in_progress?: { actual?: number; label: string };
        selected_window?: number | null;
        available?: boolean;
        months: number;
        period: string;
        monthly: number | null;
        decimal_monthly?: number | null;
        period_total: number | null;
        historical_units?: number;
        trend?: 'Increasing' | 'Decreasing' | 'Stable' | 'Zero Demand' | 'Insufficient Data' | string;
        sma_3?: number | null;
        sma_3_rounded?: number | null;
        sma_6?: number | null;
        sma_6_rounded?: number | null;
        sma_12?: number | null;
        sma_12_rounded?: number | null;
        windows?: Record<number, ProductSmaWindowDetail>;
        inputs?: { month: string; units: number }[];
        reason: string | null;
    };
    classification: string | null;
    score: number | null;
    scale?: {
        score: number;
        range: string;
        action: string | null;
    } | null;
    reorder: {
        needed: boolean;
        suggested_quantity: number;
        reorder_level?: number;
        reorder_quantity?: number;
        shortfall?: number | null;
        basis: string;
    };
}

type ForecastMode = 'range' | 'period';
type DatePreset = 'last_3_months' | 'last_6_months' | 'last_12_months' | '2020_present' | 'custom';
type GraphMetric = 'sales_transactions' | 'products_involved' | 'units_sold';
type ProductFilterTab = 'all' | 'positive' | 'zero' | 'reorder' | 'high_accuracy' | 'low_accuracy' | 'intermittent';

const { formatMoney } = useCurrency();

const props = defineProps<{
    periodPlanning?: PeriodPlanning;
    topPerformers?: TopPerformers;
    fromDate: string;
    toDate: string;
    datePreset?: DatePreset;
    smaWindow: number;
    forecastMode: ForecastMode;
    selectedCategory: string;
    selectedProductId: number | null;
    categories: string[];
    productsList: ProductListItem[];
    productWindow: ProductWindow | null;
    categoryWindow: CategoryWindow | null;
    summaryCards?: {
        targetMonth: string;
        lastCompletedMonth: string;
        smaWindow: number;
        smaWindowLabel: string;
        windowRangeLabel: string;
        predictedSalesTransactions: MetricWindowSummary;
        predictedProductsInvolved: MetricWindowSummary;
        predictedUnitsSold: MetricWindowSummary;
        productsWithForecastedDemand: {
            count: number;
            rounded_positive_count: number;
            zero_demand_count: number;
            insufficient_history_count: number;
            eligible_products: number;
        };
    };
    smaAnalytics?: {
        targetMonth: string;
        targetYm: string;
        lastCompletedMonth: string;
        lastCompletedYm: string;
        selectedWindow: number;
        selectedWindowLabel: string;
        windowRangeLabel: string;
        eligibleProductCount: number;
        firstHistoryMonth: string | null;
        hasHistory: boolean;
        currentSummary: {
            window: number;
            label: string;
            range_label: string;
            target_month: string;
            available: boolean;
            reason: string | null;
            sales_transactions: MetricWindowSummary;
            products_involved: MetricWindowSummary;
            units_sold: MetricWindowSummary;
        };
        summariesByWindow: Record<number, {
            window: number;
            label: string;
            range_label: string;
            target_month: string;
            available: boolean;
            reason: string | null;
            sales_transactions: MetricWindowSummary;
            products_involved: MetricWindowSummary;
            units_sold: MetricWindowSummary;
        }>;
        metricSeries: Record<GraphMetric, MetricSeriesData>;
        backtest: Record<GraphMetric, BacktestResult>;
    };
    forecastData: {
        labels: string[];
        historical: number[];
        forecast: (number | null)[];
        hasData: boolean;
        steps: { month: string; inputs: { month: string; units: number }[]; sum: number; window: number; forecast: number }[];
        sourceRecords: { source: string; date: string; reference: string | null; units: number }[];
        sourceRecordCount: number;
        accuracy: { months: number; mae: number | null; mape: number | null; mape_months: number };
    };
    suggestedRestocks: RestockItem[];
    minDate: string;
    maxDate: string;
    forecastPeriods: Record<string, string>;
    productForecasts: ProductForecastRow[];
}>();

const page = usePage();
const canOpenReports = computed(() => Boolean((page.props as any).auth?.is_admin) || ((page.props as any).auth?.modules ?? []).includes('reports'));
const teamSlug = computed(() => (page.props as any).currentTeam?.slug || (page.props as any).team?.slug || '');

// Local filter state
const localFromDate = ref(props.fromDate);
const localToDate = ref(props.toDate);
const localSma = ref<number>(props.smaWindow);
const localPreset = ref<DatePreset>(props.datePreset ?? (props.forecastMode === 'range' ? 'custom' : 'last_12_months'));
const localCategory = ref<string>(props.selectedCategory || 'All');
const localProductId = ref<string | number>(props.selectedProductId ? props.selectedProductId : 'All');
const localPeriod = ref(props.periodPlanning?.options.period ?? 'monthly');
const localHorizon = ref(props.periodPlanning?.options.horizon ?? 3);
const localHistory = ref(props.periodPlanning?.options.history_periods ?? 60);
const automaticWindow = ref(props.periodPlanning?.options.window === 'auto');
const periodUnit = computed(() => ({ weekly: 'weeks', monthly: 'months', annual: 'years' })[localPeriod.value]);
watch(() => props.periodPlanning, (data) => {
    if (!data) return;
    localPeriod.value = data.options.period;
    localHorizon.value = data.options.horizon;
    localHistory.value = data.options.history_periods;
    automaticWindow.value = data.options.window === 'auto';
});
function applyPeriodFilters(pageNumber = 1) {
    router.get(forecastIndex.url({ current_team: teamSlug.value }), {
        period: localPeriod.value,
        window: automaticWindow.value ? 'auto' : String(localSma.value),
        history_periods: localHistory.value, horizon: localHorizon.value,
        display_periods: localPreset.value === 'last_3_months' ? 3 : localPreset.value === 'last_6_months' ? 6 : localPreset.value === '2020_present' ? localHistory.value : 12,
        category: localCategory.value,
        product_id: localProductId.value === 'All' ? undefined : localProductId.value,
        from_date: localPreset.value === 'custom' ? localFromDate.value : undefined,
        to_date: localPreset.value === 'custom' ? localToDate.value : undefined,
        search: productSearch.value || undefined, page: pageNumber,
    }, { preserveState: true, preserveScroll: true });
}

// Interactive Line Graph metric selector & Product Table filter tab
const activeMetric = ref<GraphMetric>('units_sold');
const productTableTab = ref<ProductFilterTab>('all');
const productSearch = ref<string>('');

// Sync with incoming props
watch(() => props.selectedCategory, (val) => {
    localCategory.value = val || 'All';
});
watch(() => props.selectedProductId, (val) => {
    localProductId.value = val ? val : 'All';
});
watch(() => props.fromDate, (val) => {
    localFromDate.value = val;
});
watch(() => props.toDate, (val) => {
    localToDate.value = val;
});
watch(() => props.smaWindow, (val) => {
    localSma.value = val;
});
watch(() => props.datePreset, (val) => {
    if (val) {
        localPreset.value = val;
    }
});

const datePresetOptions: { value: DatePreset; label: string }[] = [
    { value: 'last_3_months', label: 'Last 3 Months' },
    { value: 'last_6_months', label: 'Last 6 Months' },
    { value: 'last_12_months', label: 'Last 12 Months' },
    { value: '2020_present', label: '2020–Present' },
    { value: 'custom', label: 'Custom Date Range' },
];

const smaWindowOptions: { val: number; label: string }[] = [
    { val: 3, label: '3 months (SMA 3)' },
    { val: 6, label: '6 months (SMA 6)' },
    { val: 12, label: '1 year (SMA 12)' },
];

const graphMetricOptions: { key: GraphMetric; label: string; unitLabel: string; axisTitle: string; description: string }[] = [
    {
        key: 'sales_transactions',
        label: 'Sales Transactions',
        unitLabel: 'transactions',
        axisTitle: 'Sales Orders (Transactions)',
        description: 'Count of eligible sales orders per period (confirmed, processing, completed), counted once per order.',
    },
    {
        key: 'products_involved',
        label: 'Products Involved',
        unitLabel: 'distinct products',
        axisTitle: 'Distinct Products Sold',
        description: 'Count of unique products with positive recorded demand per period.',
    },
    {
        key: 'units_sold',
        label: 'Units Sold',
        unitLabel: 'units',
        axisTitle: 'Quantity Sold (Units)',
        description: 'Net quantity per period across eligible sales orders and unlinked demand movements.',
    },
];

const activeMetricMeta = computed(() => graphMetricOptions.find((m) => m.key === activeMetric.value) ?? graphMetricOptions[2]);

// Filtered products list for dropdown based on currently chosen category
const filteredProductsForDropdown = computed(() => {
    if (!localCategory.value || localCategory.value === 'All') {
        return props.productsList;
    }
    return props.productsList.filter((p) => p.category === localCategory.value);
});

// Date range validation (only applies when Custom Date Range preset is active)
const dateError = computed(() => {
    if (localPreset.value !== 'custom') return '';
    if (!localFromDate.value || !localToDate.value) return 'Choose both a From and a To date.';
    if (localFromDate.value > localToDate.value) return 'Start date must be before end date.';
    if (localToDate.value > props.maxDate) return 'To date cannot be in the future: the range is past sales, not the forecast.';
    return '';
});

const canApply = computed(() => !dateError.value);

/**
 * Build query params keeping the SMA calculation window independent of the historical graph date filter.
 */
const selectionParams = (): Record<string, string> => {
    const params: Record<string, string> = {
        date_preset: localPreset.value,
        sma_window: String(localSma.value),
        forecast_mode: localPreset.value === 'custom' ? 'range' : 'period',
    };
    if (localPreset.value === 'custom') {
        params.from_date = localFromDate.value;
        params.to_date = localToDate.value;
    }
    return params;
};

const applyFilters = () => {
    if (!canApply.value) return;
    if (props.periodPlanning) { applyPeriodFilters(); return; }
    router.get(
        `/${teamSlug.value}/forecast`,
        {
            ...selectionParams(),
            category: localCategory.value,
            product_id: localProductId.value,
        },
        { preserveState: true, preserveScroll: true, replace: false },
    );
};

const selectDatePreset = (preset: DatePreset) => {
    if (localPreset.value === preset) return;
    localPreset.value = preset;
    applyFilters();
};

const selectPeriod = (months: number) => {
    if (localSma.value === months && !automaticWindow.value) return;
    automaticWindow.value = false;
    localSma.value = months;
    applyFilters();
};

const onCategoryChange = () => {
    if (localProductId.value !== 'All' && localCategory.value !== 'All') {
        const prod = props.productsList.find((p) => p.id === Number(localProductId.value));
        if (prod && prod.category !== localCategory.value) {
            localProductId.value = 'All';
        }
    }
    applyFilters();
};

const onProductChange = () => {
    if (localProductId.value !== 'All') {
        const prod = props.productsList.find((p) => p.id === Number(localProductId.value));
        if (prod && prod.category && localCategory.value !== 'All' && prod.category !== localCategory.value) {
            localCategory.value = prod.category;
        }
    }
    applyFilters();
};

const selectProduct = (productId: number, category?: string) => {
    localProductId.value = productId;
    if (category && localCategory.value !== 'All' && localCategory.value !== category) {
        localCategory.value = category;
    }
    applyFilters();
};

const clearProductSelection = () => {
    localProductId.value = 'All';
    applyFilters();
};

const resetAllFilters = () => {
    localCategory.value = 'All';
    localProductId.value = 'All';
    applyFilters();
};

const onFromDateChange = () => {
    if (localFromDate.value && localToDate.value && localFromDate.value > localToDate.value) {
        localToDate.value = localFromDate.value;
    }
    applyFilters();
};

const onToDateChange = () => {
    if (localFromDate.value && localToDate.value && localToDate.value < localFromDate.value) {
        localFromDate.value = localToDate.value;
    }
    applyFilters();
};

const formatDecimal = (val: number | null | undefined): string => {
    if (val === null || val === undefined) return '—';
    return Number(val).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
};

const formatWhole = (val: number | null | undefined): string => {
    if (val === null || val === undefined) return '—';
    return Math.round(Number(val)).toLocaleString('en-PH');
};

const periodLabel = computed(() => {
    if (!props.fromDate || !props.toDate) return '';
    const dateOptions: Intl.DateTimeFormatOptions = { timeZone: 'UTC', year: 'numeric', ...(localPeriod.value === 'annual' ? {} : { month: 'short' as const }), ...(localPeriod.value === 'weekly' ? { day: 'numeric' as const } : {}) };
    const from = new Date(props.fromDate).toLocaleDateString('en-PH', dateOptions);
    const to = new Date(props.toDate).toLocaleDateString('en-PH', dateOptions);
    return `${from} – ${to}`;
});

const smaLabel = computed(() => automaticWindow.value ? 'Validated SMA per product' : `SMA ${props.smaWindow} ${periodUnit.value}`);
const forecastPeriodLabel = computed(() => props.forecastPeriods?.[String(props.smaWindow)] ?? `${props.smaWindow} Months`);
const targetMonthLabel = computed(() => props.summaryCards?.targetMonth ?? props.smaAnalytics?.targetMonth ?? 'Next Month');
const windowRangeLabel = computed(() => props.summaryCards?.windowRangeLabel ?? props.smaAnalytics?.windowRangeLabel ?? '');

// Active series from smaAnalytics for the selected metric
const currentMetricSeries = computed<MetricSeriesData | null>(() => {
    return props.smaAnalytics?.metricSeries?.[activeMetric.value] ?? null;
});
const chartWindow = computed(() => currentMetricSeries.value?.next_month_forecast.window ?? props.smaWindow);

const currentBacktest = computed<BacktestResult | null>(() => {
    return currentMetricSeries.value?.backtest ?? props.smaAnalytics?.backtest?.[activeMetric.value] ?? null;
});

// Chart title: the metric, and the product or category when one is chosen
const chartTitle = computed(() => {
    const metricName = activeMetricMeta.value.label;
    if (props.productWindow) {
        return `${metricName}: ${props.productWindow.name}`;
    }
    if (props.categoryWindow) {
        return `${metricName}: ${props.categoryWindow.name}`;
    }
        return `${metricName}: ${props.periodPlanning ? 'products on this page' : 'all products'}`;
});

// Compute projected change % from last completed month to next-month forecast for the active metric
const projectedGrowth = computed(() => {
    const series = currentMetricSeries.value;
    if (series) {
        const completedActuals = series.actual.filter((v): v is number => v !== null);
        const nextDec = series.next_month_forecast?.decimal;
        if (!completedActuals.length || nextDec === null || nextDec === undefined) return 'N/A';
        const lastHist = completedActuals[completedActuals.length - 1];
        if (lastHist === 0) {
            return nextDec > 0 ? `+${formatDecimal(nextDec)} ${activeMetricMeta.value.unitLabel}` : '0.0%';
        }
        const pct = ((nextDec - lastHist) / lastHist) * 100;
        return (pct >= 0 ? '+' : '') + pct.toFixed(1) + '%';
    }
    const hist = props.forecastData.historical;
    const fore = props.forecastData.forecast;
    if (!hist.length) return '+0.0%';
    const lastHist = hist[hist.length - 1] || 0;
    const validFore = fore.filter((v): v is number => v !== null);
    if (!validFore.length || lastHist === 0) return '+0.0%';
    const lastFore = validFore[validFore.length - 1];
    const pct = ((lastFore - lastHist) / lastHist) * 100;
    return (pct >= 0 ? '+' : '') + pct.toFixed(1) + '%';
});

// The four cards under the header, in the same plain style as the other modules
const metricCards = computed(() => {
    const s = props.summaryCards;
    if (!s) return [];
    const forecastCard = (label: string, m: MetricWindowSummary, unit: string) => ({
        label,
        value: m.available ? `${formatWhole(m.rounded)} ${unit}` : 'Insufficient data',
        muted: !m.available,
        hint: m.available ? `${s.smaWindowLabel} · exact ${formatDecimal(m.decimal)}` : (m.reason ?? ''),
    });

    return [
        forecastCard(`Orders in ${s.targetMonth}`, s.predictedSalesTransactions, 'orders'),
        forecastCard(`Units in ${s.targetMonth}`, s.predictedUnitsSold, 'units'),
        {
            label: 'Products with demand',
            value: `${s.productsWithForecastedDemand.count} of ${s.productsWithForecastedDemand.eligible_products}`,
            muted: false,
            hint: `${s.productsWithForecastedDemand.zero_demand_count} zero demand · ${s.productsWithForecastedDemand.insufficient_history_count} insufficient history`,
        },
        {
            label: 'Projected change',
            value: projectedGrowth.value,
            muted: projectedGrowth.value === 'N/A',
            hint: `${activeMetricMeta.value.label}, vs ${s.lastCompletedMonth}`,
        },
    ];
});

// Interactive Line Graph data (Actual historical values, SMA trend line, and Next-Month Forecast)
const chartData = computed(() => {
    const series = currentMetricSeries.value;
    const unit = activeMetricMeta.value.unitLabel;

    if (series) {
        // Separate historical SMA trend from the forecast point so both are crystal clear
        const historicalSmaTrend = series.sma_decimal.map((val, idx) =>
            series.points[idx]?.is_forecast ? null : val,
        );

        // One unbroken line: actual (solid) → forecast (dashed), joined at the last completed month and
        // passing straight over the month in progress. That month is a separate diamond at its value so
        // far (partly over, so it is kept off the line to avoid a false drop) and is never used by the model.
        const line = continuousForecastLine(series.actual, series.partial_actual ?? [], series.next_month_projection);

        return {
            labels: series.labels,
            datasets: [
                {
                    key: 'actual',
                    label: `Actual completed periods (${activeMetricMeta.value.label})`,
                    backgroundColor: 'rgba(75, 83, 32, 0.04)',
                    borderColor: '#4b5320',
                    fill: true,
                    data: line.actual,
                    tension: 0.3,
                    pointRadius: 2,
                    pointHoverRadius: 6,
                    pointBackgroundColor: '#4b5320',
                    spanGaps: false,
                },
                ...(line.partialIdx >= 0
                    ? [{
                        key: 'partial',
                        label: `In progress, not in model (${activeMetricMeta.value.label})`,
                        borderColor: '#4b5320',
                        backgroundColor: '#ffffff',
                        fill: false,
                        data: line.partial,
                        showLine: false,
                        order: -1, // drawn above the lines it sits on
                        pointStyle: 'rectRot',
                        pointRadius: 6,
                        pointHoverRadius: 8,
                        pointBorderWidth: 2,
                        pointBackgroundColor: '#ffffff',
                        pointBorderColor: '#4b5320',
                    }]
                    : []),
                {
                    key: 'sma',
                    label: props.periodPlanning ? `Holdout SMA ${chartWindow.value} (exact)` : `${smaLabel.value} Trend Line (Decimal)`,
                    backgroundColor: 'rgba(248, 184, 3, 0.06)',
                    borderColor: '#b58a16',
                    borderDash: [5, 5],
                    fill: false,
                    data: historicalSmaTrend,
                    tension: 0.3,
                    pointRadius: 2,
                    pointHoverRadius: 6,
                    pointBackgroundColor: '#b58a16',
                    spanGaps: false,
                },
                {
                    key: 'forecast',
                    label: `Future forecast (${targetMonthLabel.value} · ${unit})`,
                    backgroundColor: 'rgba(75, 83, 32, 0.08)',
                    borderColor: '#4b5320',
                    borderDash: [6, 4],
                    fill: false,
                    data: line.forecast,
                    tension: 0,
                    // Dots only on the forecast months; the line starts at the last completed month.
                    pointRadius: line.forecast.map((v, idx) => (v !== null && series.points[idx]?.is_forecast ? 6 : 0)),
                    pointHoverRadius: line.forecast.map((v, idx) => (v !== null && series.points[idx]?.is_forecast ? 8 : 0)),
                    pointBackgroundColor: '#4b5320',
                    pointBorderColor: '#ffffff',
                    pointBorderWidth: 2,
                    spanGaps: true,
                },
            ],
        };
    }

    return {
        labels: props.forecastData.labels,
        datasets: [
            {
                label: props.productWindow ? `${props.productWindow.sku} Historical Demand` : 'Historical Demand (Units)',
                backgroundColor: 'rgba(75, 83, 32, 0.04)',
                borderColor: '#4b5320',
                fill: true,
                data: props.forecastData.historical,
                tension: 0.4,
                pointRadius: 3,
                pointHoverRadius: 6,
                pointBackgroundColor: '#4b5320',
            },
            {
                label: `Forecast (${smaLabel.value})`,
                backgroundColor: 'rgba(248, 184, 3, 0.08)',
                borderColor: '#b58a16',
                borderDash: [5, 5],
                fill: true,
                data: props.forecastData.forecast,
                tension: 0.4,
                pointRadius: 3,
                pointHoverRadius: 6,
                pointBackgroundColor: '#b58a16',
            },
        ],
    };
});

const chartOptions = computed(() => {
    const series = currentMetricSeries.value;
    const unit = activeMetricMeta.value.unitLabel;

    return {
        responsive: true,
        maintainAspectRatio: false,
        interaction: {
            mode: 'index' as const,
            intersect: false,
        },
        plugins: {
            legend: {
                position: 'bottom' as const,
                align: 'start' as const,
                labels: {
                    color: '#374151',
                    font: { size: 11 },
                    boxWidth: 8,
                    boxHeight: 8,
                    padding: 16,
                    usePointStyle: true,
                    pointStyle: 'circle',
                },
            },
            tooltip: {
                backgroundColor: 'rgba(17, 24, 39, 0.94)',
                titleColor: '#F9FAFB',
                bodyColor: '#E5E7EB',
                borderColor: 'rgba(255,255,255,0.12)',
                borderWidth: 1,
                padding: 12,
                callbacks: {
                    title: (items: any[]) => {
                        if (!items.length) return '';
                        const idx = items[0].dataIndex;
                        const pt = series?.points?.[idx];
                        if (!pt) return items[0].label;
                        return pt.is_forecast
                            ? `${pt.month} — SMA-${chartWindow.value} Forecast`
                            : pt.status === 'in_progress'
                              ? `${pt.month} — Month in progress`
                              : `${pt.month} — Completed Historical Period`;
                    },
                    label: (ctx: any) => {
                        const idx = ctx.dataIndex;
                        const pt = series?.points?.[idx];
                        if (ctx.parsed.y === null || ctx.parsed.y === undefined) return '';
                        const key = ctx.dataset.key;

                        if (key === 'partial') {
                            return `  So far (in progress, not in model): ${formatWhole(ctx.parsed.y)} ${unit}`;
                        }
                        if (key === 'actual') {
                            return `  Actual Historical: ${formatWhole(ctx.parsed.y)} ${unit}${ctx.parsed.y === 0 ? ' (Zero-sales month)' : ''}`;
                        }
                        if (key === 'sma' && pt && !pt.is_forecast) {
                            return `  SMA-${chartWindow.value}: ${formatDecimal(pt.sma_decimal)} ${unit} (≈ ${formatWhole(pt.sma_rounded)} rounded)`;
                        }
                        if (key === 'forecast' && pt && pt.is_forecast) {
                            return `  Predicted ${activeMetricMeta.value.label}: ${formatDecimal(pt.sma_decimal)} ${unit} (≈ ${formatWhole(pt.sma_rounded)} whole ${unit})`;
                        }
                        return '';
                    },
                    afterBody: (items: any[]) => {
                        if (!items.length || !series) return [];
                        const idx = items[0].dataIndex;
                        const pt = series.points?.[idx];
                        if (!pt) return [];
                        const lines: string[] = [];
                        if (pt.status === 'insufficient_history') {
                            lines.push('  Status: Insufficient historical data for this window');
                        } else if (pt.inputs && pt.inputs.length) {
                            const breakdown = pt.inputs.map((i) => `${i.month}: ${i.value}`).join(', ');
                            const sum = pt.inputs.reduce((acc, i) => acc + Number(i.value || 0), 0);
                            lines.push(`  SMA-${chartWindow.value} Window: [${breakdown}]`);
                            lines.push(`  Formula: ${sum} / ${chartWindow.value} = ${formatDecimal(pt.sma_decimal)} ${unit}`);
                        }
                        return lines;
                    },
                },
            },
        },
        scales: {
            x: {
                ticks: { color: '#6b7280', font: { size: 11 }, maxRotation: 0, maxTicksLimit: 8 },
                border: { display: false },
                grid: { color: '#eff0eb' },
            },
            y: {
                title: {
                    display: true,
                    text: activeMetricMeta.value.axisTitle,
                    color: '#4b5563',
                    font: { size: 11, weight: 600 },
                },
                ticks: { color: '#6b7280', font: { size: 11 }, precision: 0 },
                border: { display: false },
                grid: { color: '#eff0eb' },
                beginAtZero: true,
            },
        },
    };
});

// Product-level demand forecast table filtering
const filteredProductForecasts = computed(() => {
    const q = productSearch.value.trim().toLowerCase();

    return props.productForecasts.filter((row) => {
        if (q) {
            const matchSku = row.product.sku?.toLowerCase().includes(q);
            const matchName = row.product.name?.toLowerCase().includes(q);
            const matchDesc = row.product.description?.toLowerCase().includes(q);
            const matchCat = row.product.category?.toLowerCase().includes(q);
            if (!matchSku && !matchName && !matchDesc && !matchCat) {
                return false;
            }
        }

        const isAvailable = row.forecast.available ?? row.forecast.monthly !== null;
        const decMonthly = row.forecast.decimal_monthly ?? row.forecast.monthly ?? 0;

        if (productTableTab.value === 'positive') {
            return isAvailable && Number(decMonthly) > 0;
        }
        if (productTableTab.value === 'zero') {
            return isAvailable && Number(decMonthly) <= 0;
        }
        if (productTableTab.value === 'reorder') {
            return row.reorder.needed;
        }
        // Accuracy filters apply to the products on this page only (metrics are computed per page).
        if (productTableTab.value === 'high_accuracy') {
            return row.forecast.accuracy_band === 'Target met';
        }
        if (productTableTab.value === 'low_accuracy') {
            return row.forecast.accuracy_band === 'Low accuracy';
        }
        if (productTableTab.value === 'intermittent') {
            return row.forecast.demand_pattern?.intermittent === true;
        }
        return true;
    });
});

const accuracyBadgeClass = (band?: string): string => {
    switch (band) {
        case 'Target met':
            return 'border-green-200 bg-green-50 text-green-700';
        case 'Low accuracy':
            return 'border-red-200 bg-red-50 text-red-700';
        case 'Below target':
            return 'border-amber-200 bg-amber-50 text-amber-700';
        default:
            return 'border-gray-200 bg-gray-50 text-gray-600';
    }
};

const trendBadgeClass = (trend?: string): string => {
    switch (trend) {
        case 'Increasing':
            return 'bg-emerald-50 text-emerald-700 border-emerald-200';
        case 'Decreasing':
            return 'bg-amber-50 text-amber-800 border-amber-200';
        case 'Stable':
            return 'bg-blue-50 text-blue-700 border-blue-200';
        case 'Zero Demand':
            return 'bg-gray-100 text-gray-600 border-gray-200';
        default:
            return 'bg-rose-50 text-rose-700 border-rose-200';
    }
};

const exportForecast = () => {
    if (props.periodPlanning) {
        window.location.href = forecastExport.url({ current_team: teamSlug.value }, { query: { ...props.periodPlanning.options, page: undefined } });
        return;
    }
    const params = new URLSearchParams({
        ...selectionParams(),
        category: localCategory.value,
        product_id: String(localProductId.value),
    });
    window.location.href = `/${teamSlug.value}/forecast/export-csv?${params}`;
};

const goToProcurement = (product?: { name: string; sku: string }) => {
    router.visit(`/${teamSlug.value}/procurement`);
};
</script>

<template>
    <Head title="Demand Forecasting" />

    <div class="workspace-page analytics-workspace">
        <!-- Header -->
        <div class="page-header">
            <div>
                <p class="section-eyebrow mb-2">Demand planning</p>
                <h1 class="page-title">Demand Forecasting</h1>
                <p class="page-subtitle">Demand from a simple moving average (SMA) of completed sales periods.</p>
            </div>
            <div class="page-actions">
                <a v-if="canOpenReports" :href="`/${teamSlug}/reports?view=priority${automaticWindow ? '' : `&forecast_months=${smaWindow}`}`" class="secondary-button">
                    <BarChart3 class="size-4" />
                    AHP Product Prioritization
                </a>
                <button type="button" class="secondary-button" @click="exportForecast">
                    <Download class="size-4" />
                    Export CSV
                </button>
            </div>
        </div>

        <!-- Metrics -->
        <div v-if="summaryCards" class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <div v-for="card in metricCards" :key="card.label" class="analytics-stat">
                <div class="text-xs text-gray-500">{{ card.label }}</div>
                <div :class="card.muted ? 'text-base text-amber-700' : 'text-2xl text-gray-900'" class="mt-3 font-semibold tracking-tight tabular-nums">{{ card.value }}</div>
                <div class="mt-2 text-xs leading-relaxed text-gray-500" :title="card.hint">{{ card.hint }}</div>
            </div>
        </div>

        <!-- Filters -->
        <div class="panel p-5 sm:p-6">
            <div class="grid grid-cols-1 items-end gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <label class="flex flex-col gap-2 text-xs font-medium text-gray-600">
                    Forecasting period
                    <select v-model="localPeriod" class="field-control" @change="applyPeriodFilters()">
                        <option value="weekly">Weekly</option><option value="monthly">Monthly</option><option value="annual">Annual</option>
                    </select>
                </label>
                <fieldset class="min-w-0">
                    <legend class="mb-2 text-xs font-medium text-gray-600">Moving average</legend>
                    <div class="analytics-segments">
                        <button type="button" :aria-pressed="automaticWindow" :class="automaticWindow ? 'bg-white text-brand shadow-xs' : 'text-gray-500 hover:text-gray-900'" class="min-h-10 flex-1 rounded-lg px-4 text-sm font-medium transition-colors" @click="automaticWindow = true; applyPeriodFilters()">Auto</button>
                        <button v-for="opt in smaWindowOptions" :key="opt.val" type="button"
                            :aria-pressed="!automaticWindow && localSma === opt.val" :title="`${opt.val} ${periodUnit}`"
                            :class="!automaticWindow && localSma === opt.val ? 'bg-white text-brand shadow-xs' : 'text-gray-500 hover:text-gray-900'"
                            class="min-h-10 flex-1 rounded-lg px-4 text-sm font-medium transition-colors"
                            @click="selectPeriod(opt.val)">SMA {{ opt.val }}</button>
                    </div>
                </fieldset>
                <label class="flex flex-col gap-2 text-xs font-medium text-gray-600">Future periods<input v-model.number="localHorizon" type="number" min="1" max="24" class="field-control" @change="applyPeriodFilters()" /></label>
                <label class="flex flex-col gap-2 text-xs font-medium text-gray-600">Historical periods<input v-model.number="localHistory" type="number" min="3" max="240" class="field-control" @change="applyPeriodFilters()" /></label>
                <label class="flex flex-col gap-2 text-xs font-medium text-gray-600">
                    Chart range
                    <select :value="localPreset" class="field-control" @change="selectDatePreset(($event.target as HTMLSelectElement).value as DatePreset)">
                        <option v-for="preset in datePresetOptions" :key="preset.value" :value="preset.value">{{ props.periodPlanning ? preset.label.replace('Months', periodUnit).replace('2020–Present', 'All model history') : preset.label }}</option>
                    </select>
                </label>
                <template v-if="localPreset === 'custom'">
                    <label class="flex flex-col gap-2 text-xs font-medium text-gray-600">
                        From
                        <input v-model="localFromDate" type="date" :min="minDate" :max="localToDate || maxDate" class="field-control" @change="onFromDateChange" />
                    </label>
                    <label class="flex flex-col gap-2 text-xs font-medium text-gray-600">
                        To
                        <input v-model="localToDate" type="date" :min="localFromDate || minDate" :max="maxDate" class="field-control" @change="onToDateChange" />
                    </label>
                </template>
                <label class="flex flex-col gap-2 text-xs font-medium text-gray-600">
                    Category
                    <select v-model="localCategory" class="field-control" @change="onCategoryChange">
                        <option value="All">All categories</option>
                        <option v-for="cat in categories" :key="cat" :value="cat">{{ cat }}</option>
                    </select>
                </label>
                <label class="flex min-w-0 flex-col gap-2 text-xs font-medium text-gray-600 md:flex-1">
                    Product
                    <select v-model="localProductId" class="field-control" @change="onProductChange">
                        <option value="All">All products</option>
                        <option v-for="p in filteredProductsForDropdown" :key="p.id" :value="p.id">{{ p.name }} ({{ p.sku }})</option>
                    </select>
                </label>
                <button v-if="localCategory !== 'All' || localProductId !== 'All'" type="button" class="secondary-button shrink-0" @click="resetAllFilters">Reset</button>
            </div>
            <p class="mt-5 border-t border-gray-100 pt-4 text-xs leading-relaxed text-gray-500">
                Forecast for <span class="font-medium text-gray-700">{{ targetMonthLabel }}</span> from {{ windowRangeLabel || 'the completed months' }} ({{ smaLabel }}).
                The chart range ({{ periodLabel }}) only changes what is shown, not the forecast.
            </p>
            <p v-if="dateError" class="mt-1 text-xs font-medium text-rose-600" role="alert">{{ dateError }}</p>
            <p v-for="(error, key) in page.props.errors" :key="key" class="mt-1 text-xs font-medium text-rose-600" role="alert">{{ error }}</p>
            <p v-if="periodPlanning" class="mt-2 text-xs text-gray-500">Complete history ends {{ periodPlanning.training_end }}. Upcoming full periods start {{ periodPlanning.forecast_start }}. Summary cards and chart cover the {{ periodPlanning.products.data.length }} products on this page; AHP retains its monthly planning basis.</p>
        </div>

        <!-- Selected product -->
        <section v-if="productWindow" class="panel p-5 sm:p-6" aria-labelledby="product-window-title">
            <div class="flex flex-col gap-3 xl:flex-row xl:items-start xl:justify-between">
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2">
                        <h2 id="product-window-title" class="section-title">{{ productWindow.name }}</h2>
                        <StatusBadge :status="productWindow.urgency" size="sm" />
                    </div>
                    <p class="section-subtitle">
                        <span class="font-mono">{{ productWindow.sku }}</span> · {{ productWindow.category }} · {{ formatMoney(productWindow.price) }} per {{ productWindow.unit }}
                    </p>
                </div>
                <div class="flex shrink-0 gap-2">
                    <button type="button" class="brand-button" @click="goToProcurement(productWindow)">Order replenishment</button>
                    <button type="button" class="secondary-button" aria-label="Close product" @click="clearProductSelection"><X class="size-4" /></button>
                </div>
            </div>
            <dl class="mt-4 grid grid-cols-2 gap-3 md:grid-cols-4">
                <div class="rounded-xl border border-gray-200 px-4 py-3">
                    <dt class="text-xs text-gray-500">On hand</dt>
                    <dd :class="productWindow.stock <= productWindow.threshold ? 'text-amber-600' : 'text-gray-900'" class="mt-1 text-xl font-semibold">{{ productWindow.stock }} {{ productWindow.unit }}</dd>
                    <dd class="text-xs text-gray-500">Reorder at {{ productWindow.threshold }}</dd>
                </div>
                <div class="rounded-xl border border-gray-200 px-4 py-3">
                    <dt class="text-xs text-gray-500">Demand per period ({{ smaLabel }})</dt>
                    <dd class="mt-1 text-xl font-semibold text-gray-900">{{ productWindow.avgMonthlyDemand !== null ? `${productWindow.avgMonthlyDemand} ${productWindow.unit}` : 'Insufficient data' }}</dd>
                </div>
                <div class="rounded-xl border border-gray-200 px-4 py-3">
                    <dt class="text-xs text-gray-500">Days of supply</dt>
                    <dd :class="productWindow.daysOfSupply !== null && productWindow.daysOfSupply <= 14 ? 'text-rose-600' : 'text-gray-900'" class="mt-1 text-xl font-semibold">
                        {{ productWindow.daysOfSupply !== null ? `${productWindow.daysOfSupply} days` : 'Insufficient data' }}
                    </dd>
                </div>
                <div class="rounded-xl border border-gray-200 px-4 py-3">
                    <dt class="text-xs text-gray-500">Suggested order</dt>
                    <dd class="mt-1 text-xl font-semibold text-gray-900">{{ productWindow.recommendedRestock }} {{ productWindow.unit }}</dd>
                    <dd class="text-xs text-gray-500">About {{ formatMoney(productWindow.recommendedRestock * productWindow.price, { decimals: 0 }) }}</dd>
                </div>
            </dl>
        </section>

        <!-- Selected category -->
        <section v-else-if="categoryWindow" class="panel p-5 sm:p-6" aria-labelledby="category-window-title">
            <h2 id="category-window-title" class="section-title">{{ categoryWindow.name }}</h2>
            <p class="section-subtitle">Demand of all {{ categoryWindow.totalItems }} active products in this category. Choose a product to see its own forecast.</p>
            <dl class="mt-4 grid grid-cols-2 gap-3 md:grid-cols-4">
                <div class="rounded-xl border border-gray-200 px-4 py-3">
                    <dt class="text-xs text-gray-500">Active products</dt>
                    <dd class="mt-1 text-xl font-semibold text-gray-900">{{ categoryWindow.totalItems }}</dd>
                </div>
                <div class="rounded-xl border border-gray-200 px-4 py-3">
                    <dt class="text-xs text-gray-500">Units on hand</dt>
                    <dd class="mt-1 text-xl font-semibold text-gray-900">{{ categoryWindow.totalStock }}</dd>
                </div>
                <div class="rounded-xl border border-gray-200 px-4 py-3">
                    <dt class="text-xs text-gray-500">Need reorder</dt>
                    <dd :class="categoryWindow.lowStockItems > 0 ? 'text-amber-600' : 'text-gray-900'" class="mt-1 text-xl font-semibold">{{ categoryWindow.lowStockItems }}</dd>
                </div>
                <div class="rounded-xl border border-gray-200 px-4 py-3">
                    <dt class="text-xs text-gray-500">Monthly demand</dt>
                    <dd class="mt-1 text-xl font-semibold text-gray-900">{{ categoryWindow.avgMonthlyDemand !== null ? `${categoryWindow.avgMonthlyDemand} units` : 'Insufficient data' }}</dd>
                </div>
            </dl>
        </section>

        <!-- Chart -->
        <section class="panel p-5 sm:p-6" aria-labelledby="forecast-chart-title">
            <div class="flex flex-col gap-3 xl:flex-row xl:items-start xl:justify-between">
                <div class="min-w-0">
                    <h2 id="forecast-chart-title" class="section-title">{{ chartTitle }}</h2>
                    <p class="section-subtitle">{{ activeMetricMeta.description }}</p>
                </div>
                <div class="analytics-segments max-w-full overflow-x-auto" role="tablist" aria-label="Chart metric">
                    <button
                        v-for="m in graphMetricOptions"
                        :key="m.key"
                        type="button"
                        role="tab"
                        :aria-selected="activeMetric === m.key"
                        :class="activeMetric === m.key ? 'bg-white text-gray-900 shadow-xs' : 'text-gray-600 hover:text-gray-900'"
                        class="whitespace-nowrap min-h-10 rounded-lg px-3 py-2 text-xs font-medium transition-colors"
                        @click="activeMetric = m.key"
                    >
                        {{ m.label }}
                    </button>
                </div>
            </div>

            <p v-if="currentMetricSeries" class="mt-5 rounded-xl bg-gray-50 px-4 py-3 text-sm leading-relaxed text-gray-600 tabular-nums">
                {{ targetMonthLabel }} forecast:
                <template v-if="currentMetricSeries.next_month_forecast.available">
                    <span class="font-semibold text-gray-900">{{ formatWhole(currentMetricSeries.next_month_forecast.rounded) }} {{ activeMetricMeta.unitLabel }}</span>
                    <span class="text-gray-500"> (exact {{ formatDecimal(currentMetricSeries.next_month_forecast.decimal) }})</span>
                </template>
                <span v-else class="text-amber-700">insufficient history. {{ currentMetricSeries.next_month_forecast.reason }}</span>
            </p>

            <div class="mt-4 h-[300px] min-w-0 sm:h-[380px]">
                <Line :data="chartData" :options="chartOptions" />
            </div>
        </section>

        <!-- Top 5 suppliers and products: own Weekly / Monthly / Annual filter, independent of the SMA settings -->
        <TopPerformersPanel v-if="topPerformers" :data="topPerformers" />

        <!-- Product forecasts -->
        <section class="panel overflow-hidden" aria-labelledby="product-forecast-title">
            <div class="flex flex-col gap-3 border-b border-gray-100 p-5 sm:p-6 2xl:flex-row 2xl:items-center 2xl:justify-between">
                <div>
                    <h2 id="product-forecast-title" class="section-title">Product forecasts</h2>
                    <p class="section-subtitle">{{ smaLabel }} per product for {{ targetMonthLabel }}, with the AHP priority and reorder advice it feeds.</p>
                </div>
                <div class="flex flex-col gap-2 sm:flex-row">
                    <div class="relative min-w-0 sm:w-64">
                        <Search class="pointer-events-none absolute top-1/2 left-3.5 size-4 -translate-y-1/2 text-gray-400" />
                        <input v-model="productSearch" type="search" placeholder="SKU, name or category" aria-label="Search products" class="field-control pl-10" @keyup.enter="props.periodPlanning && applyPeriodFilters()" />
                    </div>
                    <select v-model="productTableTab" aria-label="Show products" class="field-control sm:w-52">
                        <option value="all">All products ({{ productForecasts.length }})</option>
                        <option value="positive">With demand ({{ summaryCards?.productsWithForecastedDemand.count ?? 0 }})</option>
                        <option value="zero">Zero demand ({{ summaryCards?.productsWithForecastedDemand.zero_demand_count ?? 0 }})</option>
                        <option value="reorder">Needs reorder</option>
                        <template v-if="periodPlanning">
                            <option value="high_accuracy">High accuracy (MAPE ≤ 10%)</option>
                            <option value="low_accuracy">Low accuracy (MAPE &gt; 30%)</option>
                            <option value="intermittent">Intermittent demand</option>
                        </template>
                    </select>
                </div>
            </div>

            <div class="overflow-x-auto" tabindex="0" role="region" aria-label="Product forecasts table">
                <table class="w-full text-left text-sm text-gray-600 tabular-nums">
                    <thead class="border-b border-gray-200">
                        <tr>
                            <th class="whitespace-nowrap">Product</th>
                            <th class="whitespace-nowrap text-right">Available</th>
                            <th class="whitespace-nowrap text-right" :title="`Units sold in ${windowRangeLabel}`">Sold, SMA window</th>
                            <th class="whitespace-nowrap text-right">Forecast / period</th>
                            <th class="whitespace-nowrap">Trend</th>
                            <th class="whitespace-nowrap">AHP priority</th>
                            <th class="whitespace-nowrap">Reorder</th>
                            <template v-if="periodPlanning"><th>SMA</th><th>MAE</th><th>MAPE</th><th title="Weighted APE: total absolute error ÷ total actual demand. Stays defined when some periods are zero.">WAPE</th><th>RMSE</th><th>Accuracy</th></template>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <tr v-for="row in filteredProductForecasts" :key="row.product.id" class="align-top">
                            <td class="min-w-56">
                                <button type="button" class="text-left font-medium text-gray-900 hover:text-[#4b5320] hover:underline" @click="selectProduct(row.product.id, row.product.category ?? undefined)">
                                    {{ row.product.name }}
                                </button>
                                <div class="font-mono text-xs text-gray-500">{{ row.product.sku }}</div>
                            </td>
                            <td class="whitespace-nowrap text-right">{{ row.available_stock }} {{ row.product.unit }}</td>
                            <td class="whitespace-nowrap text-right" :title="row.forecast.inputs?.map((i) => `${i.month}: ${i.units}`).join(', ') ?? ''">
                                {{ row.forecast.historical_units ?? 0 }}
                            </td>
                            <td class="whitespace-nowrap text-right">
                                <template v-if="row.forecast.monthly !== null">
                                    <span class="font-medium text-gray-900">{{ row.forecast.monthly }} {{ row.product.unit }}</span>
                                    <div class="text-xs text-gray-500">exact {{ formatDecimal(row.forecast.decimal_monthly ?? row.forecast.monthly) }}</div>
                                </template>
                                <span v-else class="text-xs text-amber-700" :title="row.forecast.reason ?? ''">Insufficient data</span>
                            </td>
                            <td class="whitespace-nowrap">
                                <span :class="trendBadgeClass(row.forecast.trend)" class="inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-xs font-medium">
                                    <TrendingUp v-if="row.forecast.trend === 'Increasing'" class="size-3" />
                                    <TrendingDown v-else-if="row.forecast.trend === 'Decreasing'" class="size-3" />
                                    <Minus v-else class="size-3" />
                                    {{ row.forecast.trend ?? (row.forecast.monthly !== null ? 'Stable' : 'Insufficient data') }}
                                </span>
                            </td>
                            <td class="whitespace-nowrap">
                                <div class="text-gray-800">{{ row.classification ?? 'Incomplete data' }}</div>
                                <div v-if="row.score !== null" class="text-xs text-gray-500">Score {{ row.score.toFixed(2) }}</div>
                            </td>
                            <td class="min-w-40" :title="row.reorder.basis">
                                <span v-if="row.reorder.needed" class="font-medium text-amber-700">Reorder {{ row.reorder.suggested_quantity }} {{ row.product.unit }}</span>
                                <span v-else class="text-gray-500">Not needed</span>
                            </td>
                            <template v-if="periodPlanning">
                                <td>{{ row.forecast.selected_window ?? 'N/A' }} {{ periodUnit }}</td>
                                <td>{{ formatDecimal(row.forecast.accuracy?.mae) }}</td>
                                <td>{{ formatDecimal(row.forecast.accuracy?.mape) }}{{ row.forecast.accuracy?.mape == null ? '' : '%' }}</td>
                                <td>{{ formatDecimal(row.forecast.accuracy?.wape) }}{{ row.forecast.accuracy?.wape == null ? '' : '%' }}</td>
                                <td>{{ formatDecimal(row.forecast.accuracy?.rmse) }}</td>
                                <td><span :class="accuracyBadgeClass(row.forecast.accuracy_band)" class="inline-flex rounded-full border px-2 py-0.5 text-xs font-medium">{{ row.forecast.accuracy_band }}</span>
                                    <div v-if="row.forecast.demand_pattern?.intermittent" class="mt-1 text-xs text-amber-700" :title="`${row.forecast.demand_pattern.zero_share}% of periods had no demand. MAPE ignores those periods; rely on WAPE and MAE.`">Intermittent demand · use WAPE</div><div class="text-xs text-gray-500">{{ formatDecimal(row.forecast.accuracy?.accuracy_indicator) }}{{ row.forecast.accuracy?.accuracy_indicator == null ? '' : '%' }} · {{ row.forecast.accuracy?.count ?? 0 }} tested</div></td>
                            </template>
                        </tr>
                        <tr v-if="!filteredProductForecasts.length">
                            <td :colspan="periodPlanning ? 13 : 7" class="py-8 text-center text-gray-500">No products match this selection.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p class="border-t border-gray-100 px-4 py-3 text-xs text-gray-500 sm:px-6">
                A product without enough complete {{ periodUnit }} for its SMA window shows "Insufficient data". AHP priority and reorder advice retain their monthly planning basis.
            </p>
        </section>

        <!-- How it is calculated -->
        <div v-if="periodPlanning" class="flex items-center justify-between gap-4 text-sm">
            <span>{{ periodPlanning.products.from ?? 0 }}–{{ periodPlanning.products.to ?? 0 }} of {{ periodPlanning.products.total }} products</span>
            <div class="flex gap-2"><button class="secondary-button" :disabled="periodPlanning.products.current_page <= 1" @click="applyPeriodFilters(periodPlanning.products.current_page - 1)">Previous</button><button class="secondary-button" :disabled="periodPlanning.products.current_page >= periodPlanning.products.last_page" @click="applyPeriodFilters(periodPlanning.products.current_page + 1)">Next</button></div>
        </div>
        <section class="panel p-5 sm:p-6" aria-labelledby="sma-calc-title">
            <h2 id="sma-calc-title" class="section-title">How the forecast is calculated</h2>
            <p class="section-subtitle">
                SMA = sum of the selected window's completed {{ periodUnit }} ÷ its window size. The current incomplete period is never included.
                The exact value is kept for analysis; the whole number is rounded half up.
            </p>
            <details v-if="periodPlanning" class="mt-4 rounded-xl border border-gray-200 px-4 py-3">
                <summary class="cursor-pointer text-sm font-medium text-gray-800">SMA 3 / 6 / 12 validation and holdout results</summary>
                <p class="mt-3 text-xs text-gray-500">Shared chronological validation selects the lowest MAPE (MAE if all actuals are zero), then RMSE, then the smaller window. Later holdout data never select the window. MAE/RMSE include zeros; MAPE excludes them. Target: MAPE ≤ 10%. Indicator = max(0, 100 − MAPE), not a guarantee. Future periods hold the latest SMA constant.</p>
                <div class="mt-3 max-h-96 overflow-auto"><table class="w-full text-left text-xs"><thead><tr><th>Product</th><th>Window</th><th>Phase</th><th>MAE</th><th>MAPE</th><th>RMSE</th><th>Tested / MAPE / zero actuals</th></tr></thead><tbody><template v-for="row in periodPlanning.products.data" :key="row.product.id"><template v-for="config in row.analysis.configurations" :key="config.window"><tr v-for="phase in (['validation', 'test'] as const)" :key="phase"><td>{{ row.product.name }}</td><td>{{ config.window }} {{ periodUnit }}</td><td>{{ phase === 'test' ? 'Holdout' : 'Validation' }}</td><td>{{ formatDecimal(config[phase].mae) }}</td><td>{{ formatDecimal(config[phase].mape) }}</td><td>{{ formatDecimal(config[phase].rmse) }}</td><td>{{ config[phase].count }} / {{ config[phase].mape_count }} / {{ config[phase].zero_actual_count }}</td></tr></template></template></tbody></table></div>
            </details>

            <div v-if="!forecastData.hasData" class="mt-4 rounded-xl border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-800">
                No recorded demand exists for this selection, so there is no forecast. Nothing is estimated.
            </div>

            <div v-else class="mt-4 divide-y divide-gray-100 rounded-xl border border-gray-200">
                <details v-if="currentBacktest" class="group px-4 py-3">
                    <summary class="cursor-pointer text-sm font-medium text-gray-800">Forecast accuracy: {{ activeMetricMeta.label }}</summary>
                    <dl class="mt-3 grid grid-cols-2 gap-3 md:grid-cols-4">
                        <div class="rounded-xl bg-gray-50 px-4 py-3">
                            <dt class="text-xs text-gray-500">Periods tested</dt>
                            <dd class="mt-1 text-lg font-semibold text-gray-900">{{ currentBacktest.months }}</dd>
                        </div>
                        <div class="rounded-xl bg-gray-50 px-4 py-3">
                            <dt class="text-xs text-gray-500">Mean absolute error</dt>
                            <dd class="mt-1 text-lg font-semibold text-gray-900">{{ currentBacktest.mae !== null ? `${formatDecimal(currentBacktest.mae)} ${activeMetricMeta.unitLabel}` : 'N/A' }}</dd>
                        </div>
                        <div class="rounded-xl bg-gray-50 px-4 py-3">
                            <dt class="text-xs text-gray-500">{{ periodPlanning ? 'RMSE' : 'WAPE' }}</dt>
                            <dd class="mt-1 text-lg font-semibold text-gray-900">{{ periodPlanning ? formatDecimal(currentBacktest.rmse) : currentBacktest.wape !== null ? `${formatDecimal(currentBacktest.wape)}%` : 'N/A' }}</dd>
                        </div>
                        <div class="rounded-xl bg-gray-50 px-4 py-3">
                            <dt class="text-xs text-gray-500">MAPE ({{ currentBacktest.mape_months }} non-zero periods)</dt>
                            <dd class="mt-1 text-lg font-semibold text-gray-900">{{ currentBacktest.mape !== null ? `${formatDecimal(currentBacktest.mape)}%` : 'N/A' }}</dd>
                        </div>
                    </dl>
                    <div v-if="currentBacktest.comparisons?.length" class="mt-3 max-h-72 overflow-auto rounded-xl border border-gray-100">
                        <table class="w-full text-left text-xs">
                            <thead>
                                <tr>
                                    <th>Period</th>
                                    <th class="text-right">Actual</th>
                                    <th class="text-right">Forecast (exact)</th>
                                    <th class="text-right">Forecast</th>
                                    <th class="text-right">Error</th>
                                    <th class="text-right">Error %</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                <tr v-for="c in currentBacktest.comparisons" :key="c.ym">
                                    <td class="font-medium text-gray-800">{{ c.month }}</td>
                                    <td class="text-right">{{ c.actual }}</td>
                                    <td class="text-right">{{ formatDecimal(c.predicted_decimal) }}</td>
                                    <td class="text-right">{{ c.predicted_rounded }}</td>
                                    <td class="text-right">{{ c.abs_error_rounded }}</td>
                                    <td class="text-right">{{ c.ape !== null ? `${formatDecimal(c.ape)}%` : 'N/A (zero actual)' }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </details>

                <details v-if="!periodPlanning" class="px-4 py-3">
                    <summary class="cursor-pointer text-sm font-medium text-gray-800">Step-by-step calculation ({{ forecastData.steps.length }} months)</summary>
                    <ul class="mt-2 space-y-1 font-mono text-xs text-gray-700">
                        <li v-for="step in forecastData.steps" :key="step.month">
                            {{ step.month }}: ({{ step.inputs.map((i) => i.units).join(' + ') }}) ÷ {{ step.window }} = {{ step.sum }} ÷ {{ step.window }} ≈ <strong>{{ step.forecast }}</strong>
                        </li>
                        <li v-if="!forecastData.steps.length" class="font-sans text-gray-500">The chart range is shorter than {{ smaWindow }} months, so it has no forecast month yet.</li>
                    </ul>
                </details>

                <details v-if="!periodPlanning" class="px-4 py-3">
                    <summary class="cursor-pointer text-sm font-medium text-gray-800">Source records ({{ forecastData.sourceRecordCount }})</summary>
                    <div class="mt-2 max-h-80 overflow-auto">
                        <table class="w-full text-left text-xs">
                            <thead>
                                <tr><th>Date</th><th>Source</th><th>Reference</th><th class="text-right">Units</th></tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                <tr v-for="(r, i) in forecastData.sourceRecords" :key="i">
                                    <td class="whitespace-nowrap">{{ r.date }}</td><td>{{ r.source }}</td><td>{{ r.reference }}</td><td class="text-right">{{ r.units }}</td>
                                </tr>
                            </tbody>
                        </table>
                        <p v-if="forecastData.sourceRecordCount > forecastData.sourceRecords.length" class="mt-1 text-xs text-gray-500">
                            Showing the latest {{ forecastData.sourceRecords.length }}. Export the CSV for the monthly totals.
                        </p>
                    </div>
                </details>
            </div>
        </section>
    </div>
</template>
