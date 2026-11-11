<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import { ref, computed, watch } from 'vue';
import { LineChart as Line } from '@/lib/lazyCharts';
import SpecularButton from '@/components/ui/SpecularButton.vue';
import { useCurrency } from '@/composables/useCurrency';
import {
  TrendingUp,
  Download,
  ArrowRight,
  Calendar,
  SlidersHorizontal,
  Info,
  Package,
  Layers,
  AlertTriangle,
  CheckCircle2,
  Clock,
  ShoppingCart,
  X,
  Sparkles,
  RotateCcw,
} from '@lucide/vue';


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

// Stored prices are USD; shown in the user's display currency (header switch).
const { formatMoney } = useCurrency();

const props = defineProps<{
    fromDate: string;
    toDate: string;
    smaWindow: number;
    selectedCategory: string;
    selectedProductId: number | null;
    categories: string[];
    productsList: ProductListItem[];
    productWindow: ProductWindow | null;
    categoryWindow: CategoryWindow | null;
    forecastData: {
        labels: string[];
        historical: number[];
        forecast: (number | null)[];
        hasData: boolean;
        steps: { month: string; inputs: { month: string; units: number }[]; sum: number; window: number; forecast: number }[];
        sourceRecords: { source: string; date: string; reference: string | null; units: number }[];
        sourceRecordCount: number;
    };
    suggestedRestocks: RestockItem[];
    minDate: string;
    maxDate: string;
    /** Forecasting periods: months => label (3 Months, 6 Months, 1 Year). */
    forecastPeriods: Record<string, string>;
    /** Each product's forecast for the chosen period, with the AHP priority and reorder advice it feeds. */
    productForecasts: {
        product: { id: number; name: string; sku: string; unit: string };
        available_stock: number;
        forecast: { months: number; period: string; monthly: number | null; period_total: number | null; reason: string | null };
        classification: string | null;
        score: number | null;
        reorder: { needed: boolean; suggested_quantity: number; basis: string };
    }[];
}>();

const page = usePage();
// AHP Product Prioritization is in Reports and Analytics: link to it only for users who can open Reports.
const canOpenReports = computed(() => Boolean((page.props as any).auth?.is_admin) || ((page.props as any).auth?.modules ?? []).includes('reports'));
const teamSlug = computed(() => (page.props as any).currentTeam?.slug || (page.props as any).team?.slug || '');

// Local filter state
const localFromDate = ref(props.fromDate);
const localToDate   = ref(props.toDate);
const localSma      = ref<number>(props.smaWindow);
const localCategory = ref<string>(props.selectedCategory || 'All');
const localProductId = ref<string | number>(props.selectedProductId ? props.selectedProductId : 'All');

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

// Filtered products list for dropdown based on currently chosen category
const filteredProductsForDropdown = computed(() => {
    if (!localCategory.value || localCategory.value === 'All') {
        return props.productsList;
    }
    return props.productsList.filter(p => p.category === localCategory.value);
});

// Date range validation
const dateError = computed(() => {
    if (!localFromDate.value || !localToDate.value) return '';
    if (localFromDate.value > localToDate.value) return 'Start date must be before end date.';
    if (localToDate.value > props.maxDate) return 'To date cannot be in the future.';
    return '';
});

const canApply = computed(() => !dateError.value && localFromDate.value && localToDate.value);

const applyFilters = () => {
    if (!canApply.value) return;
    router.get(
        `/${teamSlug.value}/forecast`,
        {
            from_date:  localFromDate.value,
            to_date:    localToDate.value,
            sma_window: localSma.value,
            category:   localCategory.value,
            product_id: localProductId.value,
        },
        { preserveState: true, preserveScroll: true, replace: false },
    );
};

// Category change handler
const onCategoryChange = () => {
    // If selected product doesn't belong to the new category, reset product to 'All'
    if (localProductId.value !== 'All' && localCategory.value !== 'All') {
        const prod = props.productsList.find(p => p.id === Number(localProductId.value));
        if (prod && prod.category !== localCategory.value) {
            localProductId.value = 'All';
        }
    }
    applyFilters();
};

// Product change handler
const onProductChange = () => {
    if (localProductId.value !== 'All') {
        const prod = props.productsList.find(p => p.id === Number(localProductId.value));
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
    localSma.value = 3;
    applyFilters();
};

// Auto-apply when SMA interval changes
watch(localSma, () => {
    if (canApply.value) applyFilters();
});

// Prevent invalid ranges reactively
const onFromDateChange = () => {
    if (localFromDate.value > localToDate.value) {
        localToDate.value = localFromDate.value;
    }
};
const onToDateChange = () => {
    if (localToDate.value < localFromDate.value) {
        localFromDate.value = localToDate.value;
    }
};

// Computed period label for display
const periodLabel = computed(() => {
    if (!props.fromDate || !props.toDate) return '';
    const from = new Date(props.fromDate).toLocaleDateString('en-PH', { month: 'short', year: 'numeric' });
    const to   = new Date(props.toDate).toLocaleDateString('en-PH', { month: 'short', year: 'numeric' });
    return `${from} – ${to}`;
});

const smaLabel = computed(() => {
    if (props.smaWindow === 12) return '12-Month SMA';
    if (props.smaWindow === 6)  return '6-Month SMA';
    return '3-Month SMA';
});
const forecastPeriodLabel = computed(() => props.forecastPeriods?.[String(props.smaWindow)] ?? `${props.smaWindow} months`);

// Dynamic chart title based on scope
const chartTitle = computed(() => {
    if (props.productWindow) {
        return `${props.productWindow.name} — Demand Trend`;
    }
    if (props.categoryWindow) {
        return `${props.categoryWindow.name} Category — Demand Trend`;
    }
    return `Warehouse Total Demand & Trend`;
});

const chartSubtitle = computed(() => {
    if (props.productWindow) {
        return `Item SKU: ${props.productWindow.sku} · ${props.productWindow.category} · Demand forecast based on historical out movements`;
    }
    if (props.categoryWindow) {
        return `Aggregated demand across ${props.categoryWindow.totalItems} products in ${props.categoryWindow.name}`;
    }
    return `Aggregated across all products · Period: ${periodLabel.value}`;
});

// Compute projected growth % from the forecast vs historical tail
const projectedGrowth = computed(() => {
    const hist = props.forecastData.historical;
    const fore = props.forecastData.forecast;
    if (!hist.length) return '+0.0%';
    const lastHist = hist[hist.length - 1] || 0;
    const validFore = fore.filter(v => v !== null) as number[];
    if (!validFore.length || lastHist === 0) return '+0.0%';
    const lastFore = validFore[validFore.length - 1];
    const pct = ((lastFore - lastHist) / lastHist) * 100;
    return (pct >= 0 ? '+' : '') + pct.toFixed(1) + '%';
});

// Chart config
const chartData = computed(() => ({
    labels: props.forecastData.labels,
    datasets: [
        {
            label: props.productWindow ? `${props.productWindow.sku} Historical Demand` : 'Historical Demand (Units)',
            backgroundColor: 'rgba(16, 185, 129, 0.1)',
            borderColor: '#10b981',
            fill: true,
            data: props.forecastData.historical,
            tension: 0.4,
            pointRadius: 3,
            pointHoverRadius: 6,
            pointBackgroundColor: '#10b981',
        },
        {
            label: `Forecast (${smaLabel.value})`,
            backgroundColor: 'rgba(248, 184, 3, 0.08)',
            borderColor: '#F8B803',
            borderDash: [5, 5],
            fill: true,
            data: props.forecastData.forecast,
            tension: 0.4,
            pointRadius: 3,
            pointHoverRadius: 6,
            pointBackgroundColor: '#F8B803',
        },
    ],
}));

const chartOptions = {
    responsive: true,
    maintainAspectRatio: false,
    interaction: {
        mode: 'index' as const,
        intersect: false,
    },
    plugins: {
        legend: {
            position: 'top' as const,
            labels: {
                color: '#374151',
                font: { weight: 600, size: 12 },
                boxWidth: 16,
                padding: 18,
                usePointStyle: true,
                pointStyle: 'circle',
            },
        },
        tooltip: {
            backgroundColor: 'rgba(17, 24, 39, 0.92)',
            titleColor: '#F9FAFB',
            bodyColor: '#D1FAE5',
            borderColor: 'rgba(255,255,255,0.08)',
            borderWidth: 1,
            padding: 12,
            callbacks: {
                label: (ctx: any) => `  ${ctx.dataset.label}: ${ctx.parsed.y ?? '—'} units`,
            },
        },
    },
    scales: {
        x: {
            ticks: { color: '#6b7280', font: { size: 11 } },
            grid: { color: '#f3f4f6' },
        },
        y: {
            ticks: { color: '#6b7280', font: { size: 11 } },
            grid: { color: '#f3f4f6' },
            beginAtZero: true,
        },
    },
};

const exportForecast = () => {
    const params = new URLSearchParams({
        from_date:  localFromDate.value,
        to_date:    localToDate.value,
        sma_window: String(localSma.value),
        category:   localCategory.value,
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

    <div class="workspace-page">
        <!-- ─── Header ─────────────────────────────────────────── -->
        <div class="page-header">
            <div>
                <h1 class="page-title">Demand Forecasting</h1>
                <p class="page-subtitle">Moving-average (SMA) forecasts per category and item, from past sales.</p>
            </div>
            <div class="page-actions">
                <SpecularButton
                    variant="secondary"
                    size="md"
                    :radius="12"
                    class="w-full sm:w-auto shrink-0"
                    @click="exportForecast"
                >
                    <Download class="w-4 h-4 mr-1.5 inline" />
                    Export CSV
                </SpecularButton>
            </div>
        </div>

        <!-- ─── Filter Controls (Category, Item, Date Range, SMA) ─── -->
        <div class="bg-white border border-gray-200 rounded-xl p-4">
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-12 gap-3.5 items-end">

                <!-- Category Selector -->
                <div class="lg:col-span-3 flex flex-col gap-1">
                    <label class="text-xs font-semibold text-gray-500 flex items-center gap-1.5">
                        Category
                    </label>
                    <select
                        v-model="localCategory"
                        @change="onCategoryChange"
                        class="field-control font-medium sm:w-auto"
                    >
                        <option value="All">All Categories</option>
                        <option v-for="cat in categories" :key="cat" :value="cat">
                            {{ cat }}
                        </option>
                    </select>
                </div>

                <!-- Product / Item Selector -->
                <div class="lg:col-span-3 flex flex-col gap-1">
                    <label class="text-xs font-semibold text-gray-500 flex items-center justify-between">
                        <span class="flex items-center gap-1.5">
                            Product Window
                        </span>
                        <button
                            v-if="localProductId !== 'All'"
                            @click="clearProductSelection"
                            class="text-[11px] text-gray-400 hover:text-red-600 font-normal transition-colors"
                        >
                            Clear
                        </button>
                    </label>
                    <select
                        v-model="localProductId"
                        @change="onProductChange"
                        class="field-control font-medium sm:w-auto"
                    >
                        <option value="All">All Items (Aggregated)</option>
                        <option
                            v-for="p in filteredProductsForDropdown"
                            :key="p.id"
                            :value="p.id"
                        >
                            {{ p.name }} ({{ p.sku }})
                        </option>
                    </select>
                </div>

                <!-- From Date -->
                <div class="lg:col-span-2 flex flex-col gap-1">
                    <label class="text-xs font-semibold text-gray-500 flex items-center gap-1">
                        From
                    </label>
                    <input
                        v-model="localFromDate"
                        type="date"
                        :min="minDate"
                        :max="localToDate || maxDate"
                        @change="onFromDateChange"
                        class="field-control font-medium sm:w-auto"
                    />
                </div>

                <!-- To Date -->
                <div class="lg:col-span-2 flex flex-col gap-1">
                    <label class="text-xs font-semibold text-gray-500 flex items-center gap-1">
                        To
                    </label>
                    <input
                        v-model="localToDate"
                        type="date"
                        :min="localFromDate || minDate"
                        :max="maxDate"
                        @change="onToDateChange"
                        class="field-control font-medium sm:w-auto"
                    />
                </div>

                <!-- SMA Interval & Actions -->
                <div class="lg:col-span-2 flex flex-col gap-1">
                    <label class="text-xs font-semibold text-gray-500 flex items-center gap-1">
                        Forecasting period
                    </label>
                    <div class="flex gap-1" role="group" aria-label="Forecasting period">
                        <button
                            v-for="opt in [{ label: '3 Months', val: 3 }, { label: '6 Months', val: 6 }, { label: '1 Year', val: 12 }]"
                            :key="opt.val"
                            @click="localSma = opt.val"
                            :class="[
                                'flex-1 py-2 rounded-xl text-xs font-semibold border transition-colors text-center',
                                localSma === opt.val
                                    ? 'bg-[#F8B803] text-white border-[#F8B803]'
                                    : 'bg-white text-gray-600 border-gray-200 hover:border-[#F8B803] hover:text-[#92400e]'
                            ]"
                        >
                            {{ opt.label }}
                        </button>
                    </div>
                </div>
            </div>

            <!-- Validation Error & Active Scope Info -->
            <div class="mt-3 pt-3 border-t border-gray-100 flex flex-wrap items-center justify-between gap-2 text-xs">
                <div class="flex items-center gap-2 text-gray-500">
                    <span class="font-medium text-gray-700">Forecasting Scope:</span>
                    <span v-if="productWindow" class="text-[#4b5320] font-semibold flex items-center gap-1">
                        <Package class="w-3.5 h-3.5" />
                        {{ productWindow.name }} ({{ productWindow.sku }})
                    </span>
                    <span v-else-if="categoryWindow" class="text-[#92400e] font-semibold flex items-center gap-1">
                        <Layers class="w-3.5 h-3.5" />
                        Category: {{ categoryWindow.name }}
                    </span>
                    <span v-else class="text-gray-600">
                        All Categories &amp; Products (Warehouse Total)
                    </span>
                </div>

                <div v-if="dateError" class="flex items-center gap-1.5 text-rose-600 font-medium">
                    <Info class="w-3.5 h-3.5 shrink-0" />
                    {{ dateError }}
                </div>

                <div v-if="localCategory !== 'All' || localProductId !== 'All'" class="flex items-center gap-2">
                    <button
                        @click="resetAllFilters"
                        class="text-xs text-gray-500 hover:text-gray-800 flex items-center gap-1 font-medium transition-colors"
                    >
                        <RotateCcw class="w-3 h-3" />
                        Reset Scope
                    </button>
                </div>
            </div>
        </div>

        <!-- ─── DEDICATED PRODUCT WINDOW FOR FORECASTING ────────── -->
        <div v-if="productWindow" class="bg-white border border-gray-200 rounded-xl p-6">
            <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-4 mb-4 pb-4 border-b border-gray-200/70">
                <div class="flex items-start sm:items-center gap-3">
                    <div class="w-11 h-11 rounded-xl bg-[#4b5320] text-white flex items-center justify-center shrink-0">
                        <Package class="w-6 h-6 text-[#F8B803]" />
                    </div>
                    <div>
                        <div class="flex flex-wrap items-center gap-2">
                            <h2 class="text-lg font-semibold text-gray-900 tracking-tight">{{ productWindow.name }}</h2>
                            <span class="text-xs font-mono font-semibold px-2 py-0.5 rounded bg-gray-100 text-gray-700 border border-gray-200">
                                {{ productWindow.sku }}
                            </span>
                            <span class="text-xs font-semibold px-2 py-0.5 rounded bg-[#4b5320]/10 text-[#4b5320]">
                                {{ productWindow.category }}
                            </span>
                            <span
                                :class="[
                                    'text-xs font-semibold px-2.5 py-0.5 rounded-full',
                                    productWindow.urgency === 'Out of Stock' ? 'bg-red-100 text-red-700 border border-red-200' :
                                    productWindow.urgency === 'Critical Low' ? 'bg-rose-100 text-rose-800 border border-rose-200' :
                                    productWindow.urgency === 'Needs Reorder' ? 'bg-amber-100 text-amber-800 border border-amber-200' :
                                    'bg-emerald-100 text-emerald-800 border border-emerald-200'
                                ]"
                            >
                                {{ productWindow.urgency }}
                            </span>
                        </div>
                        <p class="text-xs text-gray-500 mt-0.5">
                            Unit Price: <span class="font-semibold text-gray-800">{{ formatMoney(productWindow.price) }}</span>
                            &nbsp;·&nbsp; Unit: <span class="font-medium text-gray-700">{{ productWindow.unit }}</span>
                        </p>
                    </div>
                </div>

                <div class="flex items-center gap-2 self-start lg:self-center">
                    <SpecularButton
                        variant="brand"
                        size="sm"
                        :radius="8"
                        @click="goToProcurement(productWindow)"
                    >
                        <ShoppingCart class="w-3.5 h-3.5 mr-1.5 inline" />
                        Order Replenishment
                    </SpecularButton>
                    <button
                        @click="clearProductSelection"
                        class="p-2 rounded-xl text-gray-400 hover:text-gray-700 hover:bg-white/80 border border-gray-200/60 transition-colors"
                        title="Close Product Window"
                    >
                        <X class="w-4 h-4" />
                    </button>
                </div>
            </div>

            <!-- Product Window Metrics Grid -->
            <div class="grid grid-cols-2 md:grid-cols-4 gap-3.5">
                <!-- Stock vs Reorder Level -->
                <div class="bg-white/90 rounded-xl p-3.5 border border-gray-200">
                    <div class="text-[11px] font-semibold text-gray-500 mb-1 flex items-center justify-between">
                        <span>On-Hand Stock</span>
                        <span class="text-[10px] text-gray-400">Min: {{ productWindow.threshold }}</span>
                    </div>
                    <div class="text-2xl font-semibold text-gray-900 tracking-tight">
                        {{ productWindow.stock }} <span class="text-xs font-medium text-gray-500">{{ productWindow.unit }}</span>
                    </div>
                    <div class="mt-2 w-full bg-gray-100 h-1.5 rounded-full overflow-hidden">
                        <div
                            :class="[
                                'h-full transition-colors duration-500',
                                productWindow.stock <= 0 ? 'w-0' :
                                productWindow.stock <= productWindow.threshold ? 'bg-amber-500' : 'bg-emerald-500'
                            ]"
                            :style="{ width: `${Math.min(100, Math.max(8, (productWindow.stock / (productWindow.threshold * 2 || 20)) * 100))}%` }"
                        ></div>
                    </div>
                    <p class="text-[10px] text-gray-400 mt-1.5">
                        {{ productWindow.stock <= productWindow.threshold ? 'Below safety threshold' : 'Stock level healthy' }}
                    </p>
                </div>

                <!-- Monthly Forecast Demand -->
                <div class="bg-white/90 rounded-xl p-3.5 border border-gray-200">
                    <div class="text-[11px] font-semibold text-gray-500 mb-1 flex items-center justify-between">
                        <span>Monthly Demand</span>
                        <Sparkles class="w-3.5 h-3.5 text-[#F8B803]" />
                    </div>
                    <div class="text-2xl font-semibold text-gray-900 tracking-tight">
                        <template v-if="productWindow.avgMonthlyDemand !== null">~{{ productWindow.avgMonthlyDemand }} <span class="text-xs font-medium text-gray-500">{{ productWindow.unit }}/mo</span></template>
                        <span v-else class="text-sm font-medium text-gray-500">Insufficient data</span>
                    </div>
                    <p class="text-[11px] text-gray-500 mt-2">
                        <template v-if="productWindow.avgMonthlyDemand !== null">Burn velocity: <strong class="text-gray-800">{{ (productWindow.avgMonthlyDemand / 30).toFixed(1) }}</strong> {{ productWindow.unit }}/day</template>
                        <template v-else>No sales records in this period, so there is no forecast.</template>
                    </p>
                </div>

                <!-- Days of Supply / Runway -->
                <div class="bg-white/90 rounded-xl p-3.5 border border-gray-200">
                    <div class="text-[11px] font-semibold text-gray-500 mb-1 flex items-center justify-between">
                        <span>Inventory Runway</span>
                        <Clock class="w-3.5 h-3.5 text-blue-500" />
                    </div>
                    <div
                        :class="[
                            'text-2xl font-semibold tracking-tight',
                            productWindow.daysOfSupply === null ? 'text-gray-500' :
                            productWindow.daysOfSupply <= 7 ? 'text-red-600' :
                            productWindow.daysOfSupply <= 20 ? 'text-amber-600' : 'text-emerald-700'
                        ]"
                    >
                        <template v-if="productWindow.daysOfSupply !== null">{{ productWindow.daysOfSupply }} <span class="text-xs font-medium text-gray-500">days</span></template>
                        <span v-else class="text-sm font-medium">Insufficient data</span>
                    </div>
                    <p class="text-[11px] text-gray-500 mt-2">
                        {{ productWindow.daysOfSupply === null ? 'Needs a demand forecast' : productWindow.daysOfSupply <= 14 ? 'Stockout risk imminent' : 'Estimated supply at current pace' }}
                    </p>
                </div>

                <!-- Recommended Restock -->
                <div class="bg-white/90 rounded-xl p-3.5 border border-gray-200">
                    <div class="text-[11px] font-semibold text-gray-500 mb-1 flex items-center justify-between">
                        <span>Suggested Order</span>
                        <TrendingUp class="w-3.5 h-3.5 text-[#4b5320]" />
                    </div>
                    <div class="text-2xl font-semibold text-[#4b5320] tracking-tight">
                        +{{ productWindow.recommendedRestock }} <span class="text-xs font-medium text-gray-500">{{ productWindow.unit }}</span>
                    </div>
                    <p class="text-[11px] text-gray-500 mt-2">
                        Est. Cost: <strong class="text-gray-800">{{ formatMoney(productWindow.recommendedRestock * productWindow.price, { decimals: 0 }) }}</strong>
                    </p>
                </div>
            </div>
        </div>

        <!-- ─── CATEGORY WINDOW (When category selected but no single product) ─── -->
        <div v-else-if="categoryWindow" class="bg-white border border-gray-200 rounded-xl p-6">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-4 pb-4 border-b border-amber-200/60">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-xl bg-[#F8B803] text-white flex items-center justify-center shrink-0">
                        <Layers class="w-5 h-5 text-gray-900" />
                    </div>
                    <div>
                        <div class="flex items-center gap-2">
                            <h2 class="text-lg font-semibold text-gray-900 tracking-tight">{{ categoryWindow.name }} Category Window</h2>
                            <span class="text-xs font-semibold px-2 py-0.5 rounded-full bg-amber-100 text-amber-900">
                                {{ categoryWindow.totalItems }} Active Items
                            </span>
                        </div>
                        <p class="text-xs text-gray-500 mt-0.5">
                            Demand forecast aggregated across all products categorized under {{ categoryWindow.name }}.
                        </p>
                    </div>
                </div>

                <div class="text-xs text-gray-500 bg-white/80 px-3 py-1.5 rounded-xl border border-amber-200/60">
                    💡 <em>Tip: Select an individual item from the Product dropdown to view its specific stock runway.</em>
                </div>
            </div>

            <!-- Category Window Metrics Grid -->
            <div class="grid grid-cols-2 md:grid-cols-4 gap-3.5">
                <div class="bg-white/90 rounded-xl p-3.5 border border-gray-200">
                    <span class="text-[11px] font-semibold text-gray-500 block mb-1">Total Category Items</span>
                    <div class="text-2xl font-semibold text-gray-900 tracking-tight">{{ categoryWindow.totalItems }}</div>
                    <span class="text-[11px] text-gray-400">Active catalog SKUs</span>
                </div>
                <div class="bg-white/90 rounded-xl p-3.5 border border-gray-200">
                    <span class="text-[11px] font-semibold text-gray-500 block mb-1">On-Hand Category Stock</span>
                    <div class="text-2xl font-semibold text-gray-900 tracking-tight">{{ categoryWindow.totalStock }}</div>
                    <span class="text-[11px] text-gray-400">Total units in warehouse</span>
                </div>
                <div class="bg-white/90 rounded-xl p-3.5 border border-gray-200">
                    <span class="text-[11px] font-semibold text-gray-500 block mb-1">Needs Reorder</span>
                    <div :class="['text-2xl font-semibold tracking-tight', categoryWindow.lowStockItems > 0 ? 'text-amber-600' : 'text-emerald-600']">
                        {{ categoryWindow.lowStockItems }}
                    </div>
                    <span class="text-[11px] text-gray-400">Items below threshold</span>
                </div>
                <div class="bg-white/90 rounded-xl p-3.5 border border-gray-200">
                    <span class="text-[11px] font-semibold text-gray-500 block mb-1">Monthly Forecast Velocity</span>
                    <div class="text-2xl font-semibold text-[#4b5320] tracking-tight">{{ categoryWindow.avgMonthlyDemand !== null ? `~${categoryWindow.avgMonthlyDemand}` : 'Insufficient data' }}</div>
                    <span class="text-[11px] text-gray-400">Predicted units/month</span>
                </div>
            </div>
        </div>

        <!-- ─── Main Forecasting Grid ──────────────────────────── -->
        <div class="grid lg:grid-cols-3 gap-6">

            <!-- Chart Panel -->
            <div class="lg:col-span-2 rounded-xl bg-white border border-gray-200 p-6 flex flex-col">
                <div class="flex flex-col sm:flex-row justify-between sm:items-start gap-2 mb-5">
                    <div>
                        <h3 class="text-xl font-semibold text-gray-900 leading-tight">
                            {{ chartTitle }}
                        </h3>
                        <p class="text-xs text-gray-400 mt-0.5">
                            {{ chartSubtitle }}
                        </p>
                    </div>
                    <span class="text-xs font-semibold px-3 py-1 bg-[#F8B803]/10 text-[#92400e] border border-[#F8B803]/30 rounded-full whitespace-nowrap self-start">
                        {{ smaLabel }}
                    </span>
                </div>

                <div class="flex-1 min-h-[400px]">
                    <Line :data="chartData" :options="chartOptions" />
                </div>
            </div>

            <!-- Sidebar -->
            <div class="flex flex-col gap-5">

                <!-- Projected Growth Card -->
                <div class="rounded-xl bg-white border border-gray-200 p-5">
                    <div class="flex items-center gap-2 text-[#92400e] font-semibold text-sm mb-2">
                        <TrendingUp class="w-4 h-4" />
                        <span>Projected Growth</span>
                    </div>
                    <p class="text-gray-600 text-xs leading-relaxed mb-4">
                        Estimated demand trend based on {{ smaLabel }} applied over the selected period.
                        Maintain a safety buffer above forecasted values.
                    </p>
                    <div class="text-4xl font-semibold text-gray-900 tracking-tight">{{ projectedGrowth }}</div>
                    <span class="text-xs text-gray-400">Projected period trend (forecast vs. last historical)</span>
                </div>

                <!-- SMA Legend -->
                <div class="rounded-xl bg-white border border-gray-200 p-5">
                    <h4 class="text-sm font-semibold text-gray-800 mb-3">How SMA Works</h4>
                    <div class="space-y-2 text-xs text-gray-600 leading-relaxed">
                        <div class="flex gap-2">
                            <span class="w-2 h-2 rounded-full bg-emerald-500 mt-1 shrink-0"></span>
                            <span><strong>Historical</strong> — actual units moved out of warehouse per month</span>
                        </div>
                        <div class="flex gap-2">
                            <span class="w-2 h-2 rounded-full bg-[#F8B803] mt-1 shrink-0"></span>
                            <span><strong>SMA Forecast</strong> — plain rolling average of the last <strong>{{ props.smaWindow }}</strong> complete months of actual demand</span>
                        </div>
                        <div class="mt-3 p-2.5 bg-gray-50 rounded-lg border border-gray-100 text-gray-500 font-mono text-[10px] leading-snug">
                            SMA({{ smaWindow }}) = (D₁ + D₂ + … + Dₙ) / {{ smaWindow }}
                        </div>
                    </div>
                </div>

                <!-- Restock Alerts (Clickable to switch Product Window) -->
                <div class="flex-1 rounded-xl bg-white border border-gray-200 p-5 overflow-hidden flex flex-col">
                    <div class="flex justify-between items-center mb-3">
                        <div>
                            <h3 class="text-base font-semibold text-gray-900">Restock Alerts</h3>
                            <span class="text-[11px] text-gray-400">Click item to open product forecast</span>
                        </div>
                        <span class="text-xs text-gray-400 font-medium">{{ suggestedRestocks.length }} items</span>
                    </div>

                    <div class="space-y-2.5 flex-1 overflow-y-auto pr-0.5 max-h-[460px]">
                        <div v-if="!suggestedRestocks.length" class="text-center text-gray-400 text-xs py-8">
                            No restock alerts for this selection.
                        </div>
                        <div
                            v-for="item in suggestedRestocks"
                            :key="item.id"
                            :class="[
                                'p-3 rounded-xl border transition-colors cursor-pointer relative group',
                                item.id === Number(localProductId)
                                    ? 'border-[#F8B803] bg-[#F8B803]/10 ring-2 ring-[#F8B803]/40'
                                    : item.urgency === 'Urgent' ? 'border-red-200 bg-red-50/50 hover:border-red-300' :
                                      item.urgency === 'Soon'   ? 'border-amber-200 bg-amber-50/40 hover:border-amber-300' :
                                                                  'border-gray-200 bg-gray-50/60 hover:border-gray-300'
                            ]"
                            @click="selectProduct(item.id, item.category)"
                        >
                            <div class="flex justify-between items-start mb-1">
                                <div>
                                    <span class="font-semibold text-gray-900 text-xs leading-tight line-clamp-1 group-hover:text-[#4b5320] transition-colors">
                                        {{ item.name }}
                                    </span>
                                    <span class="text-[10px] text-gray-400 font-mono">{{ item.sku }}</span>
                                </div>
                                <span
                                    :class="[
                                        'text-[10px] font-semibold px-2 py-0.5 rounded-full shrink-0 ml-2',
                                        item.urgency === 'Urgent' ? 'bg-red-100 text-red-700' :
                                        item.urgency === 'Soon'   ? 'bg-amber-100 text-amber-800' :
                                                                    'bg-gray-200 text-gray-700'
                                    ]"
                                >
                                    {{ item.urgency }}
                                </span>
                            </div>
                            <p class="text-[11px] text-gray-500 mb-2">
                                Stock: <strong class="text-gray-800">{{ item.stock }}</strong>
                                &nbsp;·&nbsp; Need: <strong class="text-gray-800">{{ item.predictedNeed }}</strong>
                            </p>
                            <div class="flex items-center justify-between pt-1 border-t border-gray-100">
                                <span class="text-[10px] text-[#4b5320] font-semibold group-hover:underline">
                                    {{ item.id === Number(localProductId) ? 'Currently Inspected' : 'Open in Forecast Window →' }}
                                </span>
                                <button
                                    @click.stop="goToProcurement(item)"
                                    class="py-1 px-2 bg-white border border-gray-200 hover:border-[#F8B803] hover:bg-[#F8B803]/5 text-gray-700 text-[10px] font-semibold rounded-md transition-colors flex items-center gap-1"
                                >
                                    <span>Procure</span>
                                    <ArrowRight class="w-2.5 h-2.5 text-[#F8B803]" />
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <section class="panel p-6" aria-labelledby="product-forecast-heading">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <div>
                    <h2 id="product-forecast-heading" class="text-base font-semibold text-gray-900">Product forecast review: next {{ forecastPeriodLabel }}</h2>
                    <p class="mt-1 text-sm text-gray-500">
                        Average monthly demand over the last {{ smaWindow }} complete months (SMA-{{ smaWindow }}) × {{ smaWindow }} months.
                        The same forecast drives AHP Product Prioritization and the reorder recommendations.
                    </p>
                </div>
                <a v-if="canOpenReports" :href="`/${teamSlug}/reports?view=priority&forecast_months=${smaWindow}`" class="text-sm font-medium text-[#4b5320] hover:underline">Open AHP prioritization</a>
            </div>
            <div class="mt-3 overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead>
                        <tr class="text-xs text-gray-500">
                            <th class="py-2 font-medium">Product</th>
                            <th class="py-2 text-right font-medium">Available</th>
                            <th class="py-2 text-right font-medium">Avg / month</th>
                            <th class="py-2 text-right font-medium">Forecast, next {{ forecastPeriodLabel }}</th>
                            <th class="py-2 pl-4 font-medium">AHP priority</th>
                            <th class="py-2 pl-4 font-medium">Reorder advice</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in productForecasts" :key="row.product.id" class="border-t border-gray-100 align-top">
                            <td class="py-2">
                                <div class="font-medium text-gray-900">{{ row.product.name }}</div>
                                <div class="font-mono text-xs text-gray-500">{{ row.product.sku }}</div>
                            </td>
                            <td class="py-2 text-right font-mono">{{ row.available_stock }}</td>
                            <td class="py-2 text-right font-mono">{{ row.forecast.monthly ?? '—' }}</td>
                            <td class="py-2 text-right font-mono">
                                <span v-if="row.forecast.period_total != null" class="font-semibold">{{ row.forecast.period_total }} {{ row.product.unit }}</span>
                                <span v-else class="font-sans text-xs text-amber-700" :title="row.forecast.reason ?? ''">Insufficient data</span>
                            </td>
                            <td class="py-2 pl-4 text-xs">{{ row.classification ?? 'Incomplete data' }}<span v-if="row.score != null" class="text-gray-500"> ({{ row.score.toFixed(2) }})</span></td>
                            <td class="py-2 pl-4 text-xs" :title="row.reorder.basis">
                                <span v-if="row.reorder.needed" class="font-medium text-amber-700">Reorder {{ row.reorder.suggested_quantity }} {{ row.product.unit }}</span>
                                <span v-else class="text-gray-500">No reorder needed</span>
                            </td>
                        </tr>
                        <tr v-if="!productForecasts.length">
                            <td colspan="6" class="py-6 text-center text-sm text-gray-400">No active products in this selection.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p class="mt-2 text-xs text-gray-500">Products without {{ smaWindow }} full months of history show "Insufficient data"; nothing is estimated. Reorder advice is advice only: purchases go through Procurement.</p>
        </section>

        <section class="panel p-6" aria-labelledby="sma-calc-heading">
            <h2 id="sma-calc-heading" class="text-base font-semibold text-gray-900">How this forecast is calculated</h2>
            <p class="mt-1 text-sm text-gray-500">
                SMA-{{ smaWindow }}: each month's forecast is the plain average of the {{ smaWindow }} months before it.
                Monthly demand is the larger of outgoing stock movements and units on confirmed, processing or completed Sales Orders.
            </p>
            <div v-if="!forecastData.hasData" class="mt-3 rounded-xl border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-800">
                Insufficient data: no sales or outgoing stock records exist for this selection and period, so no forecast is shown. Nothing is estimated.
            </div>
            <template v-else>
                <details class="mt-3">
                    <summary class="text-sm font-medium text-[#4b5320]">View Calculation ({{ forecastData.steps.length }} forecast months)</summary>
                    <p class="mt-2 font-mono text-xs text-gray-600">SMA = (Sum of demand in the previous {{ smaWindow }} months) / {{ smaWindow }}</p>
                    <ul class="mt-2 space-y-1 font-mono text-xs text-gray-700">
                        <li v-for="step in forecastData.steps" :key="step.month">
                            {{ step.month }}: ({{ step.inputs.map((i) => i.units).join(' + ') }}) / {{ step.window }} = {{ step.sum }} / {{ step.window }} ≈ <strong>{{ step.forecast }}</strong>
                        </li>
                        <li v-if="!forecastData.steps.length" class="font-sans text-gray-500">The period is shorter than {{ smaWindow }} months, so there is no forecast month yet.</li>
                    </ul>
                </details>
                <details class="mt-2">
                    <summary class="text-sm font-medium text-[#4b5320]">Source records ({{ forecastData.sourceRecordCount }})</summary>
                    <div class="mt-2 max-h-80 overflow-auto">
                        <table class="w-full text-left text-xs">
                            <thead><tr><th class="py-1">Date</th><th>Source</th><th>Reference</th><th class="text-right">Units</th></tr></thead>
                            <tbody>
                                <tr v-for="(r, i) in forecastData.sourceRecords" :key="i" class="border-t border-gray-100">
                                    <td class="py-1">{{ r.date }}</td><td>{{ r.source }}</td><td>{{ r.reference }}</td><td class="text-right">{{ r.units }}</td>
                                </tr>
                            </tbody>
                        </table>
                        <p v-if="forecastData.sourceRecordCount > forecastData.sourceRecords.length" class="mt-1 text-xs text-gray-500">Showing the latest {{ forecastData.sourceRecords.length }}; export the CSV for the monthly totals.</p>
                    </div>
                </details>
            </template>
        </section>
    </div>
</template>
