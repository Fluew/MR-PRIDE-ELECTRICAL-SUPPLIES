<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import { ref, computed, watch, nextTick, onMounted, onUpdated } from 'vue';
import { LineChart as Line, BarChart as Bar, DoughnutChart as Doughnut } from '@/lib/lazyCharts';
import SpecularButton from '@/components/ui/SpecularButton.vue';
import GlideSelect from '@/components/vue-bits/GlideSelect.vue';
import PrintSummary from '@/components/reports/PrintSummary.vue';
import { showToast } from '@/composables/useFlashToast';
import { type Evaluation } from '@/components/evaluation/ScoreBreakdown.vue';
import ProductPriorityBreakdown, { type ProductPriority } from '@/components/evaluation/ProductPriorityBreakdown.vue';
import SupplierEvaluationBreakdown from '@/components/evaluation/SupplierEvaluationBreakdown.vue';
import PurchaseRequestCreator, { type PrCandidate } from '@/components/procurement/PurchaseRequestCreator.vue';
import { useCurrency } from '@/composables/useCurrency';
import {
  FileText,
  Download,
  Printer,
  Calendar,
  Filter,
  RotateCcw,
  Search,
  ShoppingCart,
  TrendingUp,
  ChevronRight,
  ExternalLink,
  ChevronDown,
  Info,
  CheckCircle2,
  Clock,
  Layers,
  ArrowUpRight,
  X,
} from '@lucide/vue';


// ─────────────────────────────────────────────────────────────
// INTERFACES
// ─────────────────────────────────────────────────────────────
interface InventoryItem {
    id: number;
    sku: string;
    name: string;
    category: string;
    supplier_name: string;
    current_stock: number;
    reorder_threshold: number;
    unit: string;
    cost_price: number;
    price: number;
    total_valuation: number;
    stock_status: 'In Stock' | 'Low Stock' | 'Out of Stock';
}

interface LowStockItem {
    id: number;
    sku: string;
    name: string;
    category: string;
    stock: number;
    reorder_level: number;
    shortage: number;
    suggested_reorder: number;
    supplier_name: string;
    urgency: string;
    price: number;
}

interface SalesItem {
    id: number;
    invoice_number: string;
    client_name: string;
    sale_date: string;
    subtotal: number;
    tax_amount: number;
    grand_total: number;
    payment_method: string;
    status: string;
}

interface QuotationItemRecord {
    id: number;
    quotation_number: string;
    client_name: string;
    quotation_date: string;
    valid_until: string;
    grand_total: number;
    status: string;
    is_converted: boolean;
}

interface ProcurementItem {
    id: number;
    request_number: string;
    product_name: string;
    sku: string;
    supplier_name: string;
    requested_by: string;
    quantity: number;
    estimated_cost: number;
    status: string;
    date: string;
}

interface SupplierAhpItem {
    rank: number | null;
    id: number;
    name: string;
    contact_person: string;
    email: string;
    phone: string;
    /** 0–100, null when the evaluation lacks data. */
    ahp_score: number | null;
    classification: string | null;
    complete: boolean;
    metrics: {
        price: number | null;
        quality: number | null;
        delivery: number | null;
    };
    evaluation: Evaluation & { period?: { from: string; to: string } };
    status: string;
}

interface ActivityFeedItem {
    id: number;
    user: string;
    user_email: string;
    action: string;
    event: string;
    description: string;
    module: string;
    reference: string;
    outcome: string;
    timestamp: string;
    date: string;
    relative_time: string;
}

const props = defineProps<{
    companyName?: string;
    metrics: {
        inventoryCount: number;
        totalValuation: number;
        lowStockCount: number;
        outOfStockCount: number;
        inStockCount: number;
        quotationsCount: number;
        salesCount: number;
        totalRevenue: number;
        pendingQuotationsCount: number;
        pendingQuotationsValue: number;
        approvedQuotationsCount: number;
        totalPurchasesCount: number;
        totalPurchasesEstimatedCost: number;
        valuationBasisNote: string;
    };
    dateFilter: {
        preset: string;
        fromDate: string | null;
        toDate: string | null;
    };
    categories: string[];
    inventoryAnalytics: {
        statusDistribution: {
            in_stock: number;
            low_stock: number;
            out_of_stock: number;
        };
        byCategory: Array<{
            category: string;
            count: number;
            stock: number;
            valuation: number;
        }>;
        topProductsByValuation: Array<{
            id: number;
            name: string;
            sku: string;
            category: string;
            stock: number;
            unit: string;
            cost_price: number;
            price: number;
            valuation: number;
        }>;
        items: InventoryItem[];
    };
    lowStockAnalytics: {
        byCategory: Record<string, number>;
        severity: LowStockItem[];
        items: LowStockItem[];
    };
    quotationsSalesAnalytics: {
        salesTrend: {
            labels: string[];
            data: number[];
        };
        quotationStatusDistribution: Record<string, number>;
        topSellingProducts: Array<{
            name: string;
            sku: string;
            quantity: number;
            amount: number;
        }>;
        conversion: {
            total: number;
            approved: number;
            converted: number;
            rate: number;
        };
        salesList: SalesItem[];
        quotationsList: QuotationItemRecord[];
    };
    forecastAnalytics: {
        labels: string[];
        historical: number[];
        forecast: (number | null)[];
        year: number;
        window: number;
        hasData: boolean;
        steps: { month: string; inputs: { month: string; units: number }[]; sum: number; window: number; forecast: number }[];
        sourceRecordCount: number;
    };
    procurementAnalytics: {
        statusDistribution: Record<string, number>;
        totalEstimatedCost: number;
        requestsList: ProcurementItem[];
    };
    ahpAnalytics: {
        topSuppliers: SupplierAhpItem[];
        suppliersList: SupplierAhpItem[];
        weights: { delivery: number; price: number; quality: number };
        deliverySplit: { on_time: number; fulfillment: number };
        periodMonths: number;
        classes: Record<string, string>;
    };
    productPriority: {
        items: ProductPriority[];
        weights: { demand: number; stock: number; movement: number; supplier_availability: number };
        classes: Record<string, string> | Array<{ min: number; max?: number; label: string; action?: string | null }>;
        movementMonths: number;
        smaWindow: number;
        /** Forecasting period in months (3, 6 or 12) = SMA window of the demand criterion. */
        forecastMonths: number;
        /** months => label (3 Months, 6 Months, 1 Year). */
        forecastPeriods: Record<string, string>;
        /** Whether this user may create purchase requests (admin, procurement or inventory staff). */
        canRequestPurchase: boolean;
    };
    activityFeed?: {
        items: ActivityFeedItem[];
        modules: string[];
        byModule: Record<string, number>;
        totalCount: number;
    };
}>();

// Calculation breakdowns: same backend results as Suppliers and Demand Forecasting.
const stepByMonth = computed(() => new Map(props.forecastAnalytics.steps.map((s) => [s.month, s])));
const selectedProductPriority = ref<ProductPriority | null>(null);
const selectedSupplierEval = ref<SupplierAhpItem | null>(null);
const priorityContribution = (p: ProductPriority, key: string) => p.criteria.find((c) => c.key === key);
const pctW = (w: number) => `${Math.round(w * 100)}%`;
const priorityKeys = ['demand', 'stock', 'movement', 'supplier_availability'];

// AHP Product Prioritization: forecasting period and reorder recommendations → purchase requests.
// Staff choose; nothing is ordered automatically (requests still need admin approval).
const priorityMonths = ref<number>(Number(props.productPriority.forecastMonths) || 3);
const priorityPeriodLabel = computed(() => props.productPriority.forecastPeriods?.[String(priorityMonths.value)] ?? `${priorityMonths.value} months`);
const reorderRecommendations = computed(() => props.productPriority.items.filter((p) => p.reorder.needed));
const selectedForRequest = ref<number[]>([]);
const showPrCreator = ref(false);
const prCandidates = computed<PrCandidate[]>(() =>
    props.productPriority.items
        .filter((p) => selectedForRequest.value.includes(p.product.id))
        .map((p) => ({
            product_id: p.product.id,
            name: p.product.name,
            sku: p.product.sku,
            unit: p.product.unit,
            current_stock: p.criteria.find((c) => c.key === 'stock')?.inputs?.available ?? null,
            forecast: p.reorder.forecast,
            forecast_period: p.forecast?.period ?? null,
            ahp_score: p.score,
            classification: p.classification,
            recommended_quantity: p.reorder.needed ? p.reorder.suggested_quantity : null,
        })),
);
const onRequestsSubmitted = () => {
    showPrCreator.value = false;
    selectedForRequest.value = [];
};
// Recalculates only the prioritization (the period and other report views stay as they are).
const changePriorityPeriod = () => {
    const params = new URLSearchParams(String(page.url || '').split('?')[1] || '');
    if (priorityMonths.value === 3) params.delete('forecast_months');
    else params.set('forecast_months', String(priorityMonths.value));
    params.set('view', 'priority');
    selectedForRequest.value = [];
    router.get(`/${teamSlug.value}/reports`, Object.fromEntries(params), { preserveState: true, preserveScroll: true, replace: true, only: ['productPriority'] });
};
// Class thresholds come from config as {minScore: label} or array of {min, max, label, action}; shown highest first.
const classBands = (classes: Record<string, string> | Array<{ min: number; max?: number; label: string; action?: string | null }>) => {
    if (Array.isArray(classes)) {
        return [...classes].sort((a, b) => b.min - a.min).map((b) => ({
            min: b.min,
            range: b.max !== undefined ? `${b.min.toFixed(2)}–${b.max.toFixed(2)}` : `≥ ${b.min}`,
            label: b.action ? `${b.label} — ${b.action}` : b.label,
        }));
    }
    return Object.entries(classes).map(([min, label]) => ({
        min: Number(min),
        range: `≥ ${min}`,
        label,
    })).sort((a, b) => b.min - a.min);
};

const page = usePage();
const teamSlug = computed(() => (page.props as any).currentTeam?.slug || (page.props as any).team?.slug || '');

// Currency and number formatters. Stored amounts are USD; shown in the user's display currency
// (header switch). Money charts plot toDisplayAmount() values and label them with formatDisplayAmount().
const { formatMoney: formatCurrency, toDisplayAmount, formatDisplayAmount, displayCurrency } = useCurrency();
const formatNumber = (num: number) => {
    return new Intl.NumberFormat('en-PH').format(num);
};

// ─────────────────────────────────────────────────────────────
// ACTIVE TAB SELECTION
// ─────────────────────────────────────────────────────────────
type TabKey = 'overview' | 'inventory' | 'low-stock' | 'sales' | 'forecast' | 'procurement' | 'priority' | 'ahp' | 'activity';

// Report views offered in the view dropdown, in display order.
const reportViews: Array<{ key: TabKey; label: string }> = [
    { key: 'overview', label: 'Overview' },
    { key: 'inventory', label: 'Inventory Status' },
    { key: 'low-stock', label: 'Low Stock Audit' },
    { key: 'sales', label: 'Quotations & Sales' },
    { key: 'forecast', label: 'Demand Forecasting' },
    { key: 'procurement', label: 'Purchasing & Procurement' },
    { key: 'priority', label: 'AHP Product Prioritization' },
    { key: 'ahp', label: 'Supplier Evaluation' },
    { key: 'activity', label: 'Recent Activity Feed' },
];

// The selected view is kept in the URL (?view=...) so a refresh or shared link opens the same report.
const viewFromUrl = (): TabKey => {
    const view = new URLSearchParams(String(page.url || '').split('?')[1] || '').get('view');
    return reportViews.some((v) => v.key === view) ? (view as TabKey) : 'overview';
};
const activeTab = ref<TabKey>(viewFromUrl());

watch(activeTab, (view) => {
    const [path, query = ''] = String(page.url || '').split('?');
    const params = new URLSearchParams(query);
    if (view === 'overview') params.delete('view');
    else params.set('view', view);
    const search = params.toString();
    // Client-side only: no request, nothing reloads.
    router.replace({ url: search ? `${path}?${search}` : path, preserveState: true, preserveScroll: true });
});

// ─────────────────────────────────────────────────────────────
// REPORT SEARCH
// Inside the selected view, sections whose title or content does not contain the search text are
// hidden (display only; no data is filtered or recalculated). Other views are suggested by their
// name, section titles and topic words.
// ─────────────────────────────────────────────────────────────
const reportSearch = ref('');
const reportSearchTerm = computed(() => reportSearch.value.trim().toLowerCase());

const reportTopics: Record<TabKey, string> = {
    overview: 'monthly revenue velocity catalog stock health stock units by product category top products by inventory value kpi summary dashboard',
    inventory: 'status distribution inventory valuation by category inventory status manifest stock products sku cost price',
    'low-stock': 'stock shortage severity restock priority rules restock prioritization audit reorder out of stock low stock shortage',
    sales: 'revenue generation timeline quotation statuses quotations sales revenue conversion clients customers invoices orders',
    forecast: '12-month demand vs sma predictive model sma calculation breakdown monthly forecast table demand forecasting moving average',
    procurement: 'purchase request statuses procurement operational summary purchase orders requests ledger po pr spend purchasing',
    priority: 'ahp methodology criteria weights criteria scoring weighted contributions per product product prioritization priority reorder',
    ahp: 'supplier evaluation ranking supplier evaluation methodology ranked supplier directory suppliers vendors rating score',
    activity: 'recent activity feed audit log events user actions history',
};

/** Other report views whose name or topics match the search. */
const otherMatchingViews = computed(() => {
    const term = reportSearchTerm.value;
    if (!term) return [];
    return reportViews.filter(
        (v) => v.key !== activeTab.value && `${v.label} ${reportTopics[v.key]}`.toLowerCase().includes(term),
    );
});

const reportSectionCount = ref({ shown: 0, total: 0 });

/** Sections of the visible view: its direct children, or the cards inside a multi-card grid row. */
const currentReportSections = (): HTMLElement[] => {
    const view = document.querySelector<HTMLElement>('[data-report-view]');
    if (!view) return [];
    return Array.from(view.children).flatMap((child) =>
        child.classList.contains('grid') && child.children.length > 1
            ? (Array.from(child.children) as HTMLElement[])
            : [child as HTMLElement],
    );
};

const applyReportSearch = () => {
    const term = reportSearchTerm.value;
    const sections = currentReportSections();
    // The view's name matching the search keeps the whole view visible.
    const viewLabel = reportViews.find((v) => v.key === activeTab.value)?.label.toLowerCase() ?? '';
    const showAll = !term || viewLabel.includes(term);
    let shown = 0;

    sections.forEach((section) => {
        const match = showAll || (section.textContent || '').toLowerCase().includes(term);
        section.style.display = match ? '' : 'none';
        if (match) shown++;
    });

    // Hide grid rows whose cards are all hidden, so no empty gap is left.
    document.querySelectorAll<HTMLElement>('[data-report-view] > .grid').forEach((row) => {
        if (row.children.length <= 1) return;
        const anyVisible = Array.from(row.children).some((c) => (c as HTMLElement).style.display !== 'none');
        row.style.display = anyVisible ? '' : 'none';
    });

    // Only assign on change: this runs from onUpdated, and a new object would re-render forever.
    if (reportSectionCount.value.shown !== shown || reportSectionCount.value.total !== sections.length) {
        reportSectionCount.value = { shown, total: sections.length };
    }
};

watch([reportSearchTerm, activeTab], () => nextTick(applyReportSearch));
// Report content can re-render (for example after a period change); keep the search applied.
onUpdated(applyReportSearch);
onMounted(applyReportSearch);

// ─────────────────────────────────────────────────────────────
// DATE FILTERING STATE & ACTIONS
// ─────────────────────────────────────────────────────────────
const selectedPreset = ref(props.dateFilter.preset || 'all_time');
const customFromDate = ref(props.dateFilter.fromDate || '');
const customToDate = ref(props.dateFilter.toDate || '');

const applyDateFilter = () => {
    router.get(
        `/${teamSlug.value}/reports`,
        {
            date_preset: selectedPreset.value,
            from_date: selectedPreset.value === 'custom' ? customFromDate.value : undefined,
            to_date: selectedPreset.value === 'custom' ? customToDate.value : undefined,
            // Keep the selected report view and the AHP forecasting period when the period changes.
            view: activeTab.value !== 'overview' ? activeTab.value : undefined,
            forecast_months: priorityMonths.value !== 3 ? priorityMonths.value : undefined,
        },
        { preserveState: true, preserveScroll: true, replace: false },
    );
};

const onPresetChange = () => {
    if (selectedPreset.value !== 'custom') {
        applyDateFilter();
    }
};

const resetFilters = () => {
    selectedPreset.value = 'all_time';
    customFromDate.value = '';
    customToDate.value = '';
    applyDateFilter();
};

const activeScopeLabel = computed(() => {
    switch (props.dateFilter.preset) {
        case 'today': return 'Today';
        case 'last_7_days': return 'Last 7 Days';
        case 'last_30_days': return 'Last 30 Days';
        case 'this_month': return 'This Month';
        case 'this_year': return 'This Year';
        case 'custom':
            return `Custom: ${props.dateFilter.fromDate || 'Start'} to ${props.dateFilter.toDate || 'End'}`;
        case 'all_time':
        default:
            return 'All-Time Records';
    }
});

// ─────────────────────────────────────────────────────────────
// INVENTORY TAB FILTERS & SEARCH
// ─────────────────────────────────────────────────────────────
const invSearch = ref('');
const invCategoryFilter = ref('All');
const invStatusFilter = ref('All');
const invPage = ref(1);
const invPerPage = 10;

const filteredInventory = computed(() => {
    return props.inventoryAnalytics.items.filter(item => {
        const matchesSearch = !invSearch.value ||
            item.name.toLowerCase().includes(invSearch.value.toLowerCase()) ||
            item.sku.toLowerCase().includes(invSearch.value.toLowerCase()) ||
            item.supplier_name.toLowerCase().includes(invSearch.value.toLowerCase());
        const matchesCategory = invCategoryFilter.value === 'All' || item.category === invCategoryFilter.value;
        const matchesStatus = invStatusFilter.value === 'All' || item.stock_status === invStatusFilter.value;
        return matchesSearch && matchesCategory && matchesStatus;
    });
});

const paginatedInventory = computed(() => {
    const start = (invPage.value - 1) * invPerPage;
    return filteredInventory.value.slice(start, start + invPerPage);
});
const totalInvPages = computed(() => Math.ceil(filteredInventory.value.length / invPerPage) || 1);

// ─────────────────────────────────────────────────────────────
// LOW STOCK TAB FILTERS & SEARCH
// ─────────────────────────────────────────────────────────────
const lowStockSearch = ref('');
const lowStockCategoryFilter = ref('All');
const lowStockUrgencyFilter = ref('All');

const filteredLowStock = computed(() => {
    return props.lowStockAnalytics.items.filter(item => {
        const matchesSearch = !lowStockSearch.value ||
            item.name.toLowerCase().includes(lowStockSearch.value.toLowerCase()) ||
            item.sku.toLowerCase().includes(lowStockSearch.value.toLowerCase());
        const matchesCategory = lowStockCategoryFilter.value === 'All' || item.category === lowStockCategoryFilter.value;
        const matchesUrgency = lowStockUrgencyFilter.value === 'All' || item.urgency === lowStockUrgencyFilter.value;
        return matchesSearch && matchesCategory && matchesUrgency;
    });
});

// ─────────────────────────────────────────────────────────────
// QUOTATIONS & SALES SUB-TAB & FILTERS
// ─────────────────────────────────────────────────────────────
const salesSubTab = ref<'sales' | 'quotations'>('sales');
const salesSearch = ref('');
const filteredSales = computed(() => {
    return props.quotationsSalesAnalytics.salesList.filter(s =>
        !salesSearch.value ||
        s.invoice_number.toLowerCase().includes(salesSearch.value.toLowerCase()) ||
        s.client_name.toLowerCase().includes(salesSearch.value.toLowerCase())
    );
});
const filteredQuotations = computed(() => {
    return props.quotationsSalesAnalytics.quotationsList.filter(q =>
        !salesSearch.value ||
        q.quotation_number.toLowerCase().includes(salesSearch.value.toLowerCase()) ||
        q.client_name.toLowerCase().includes(salesSearch.value.toLowerCase())
    );
});

// ─────────────────────────────────────────────────────────────
// RECENT ACTIVITY FEED TAB FILTERS & SEARCH
// ─────────────────────────────────────────────────────────────
const activitySearch = ref('');
const activityModuleFilter = ref('All');
const activityFromDate = ref('');
const activityToDate = ref('');
const activityPage = ref(1);
const activityPerPage = 15;

const activityModulesList = computed(() =>
    props.activityFeed?.modules ?? [
        'Sales',
        'Inventory',
        'Procurement',
        'Suppliers',
        'User Management',
        'System Maintenance',
    ]
);

const filteredActivities = computed<ActivityFeedItem[]>(() => {
    const list = props.activityFeed?.items ?? [];
    const q = activitySearch.value.trim().toLowerCase();

    return list.filter((item) => {
        const matchesModule = activityModuleFilter.value === 'All' || item.module === activityModuleFilter.value;
        const matchesFrom = !activityFromDate.value || (item.date && item.date >= activityFromDate.value);
        const matchesTo = !activityToDate.value || (item.date && item.date <= activityToDate.value);
        const matchesSearch =
            !q ||
            item.user.toLowerCase().includes(q) ||
            item.user_email.toLowerCase().includes(q) ||
            item.action.toLowerCase().includes(q) ||
            item.module.toLowerCase().includes(q) ||
            item.reference.toLowerCase().includes(q) ||
            item.description.toLowerCase().includes(q);

        return matchesModule && matchesFrom && matchesTo && matchesSearch;
    });
});

watch([activitySearch, activityModuleFilter, activityFromDate, activityToDate], () => {
    activityPage.value = 1;
});

const paginatedActivities = computed(() => {
    const start = (activityPage.value - 1) * activityPerPage;
    return filteredActivities.value.slice(start, start + activityPerPage);
});

const totalActivityPages = computed(() => Math.ceil(filteredActivities.value.length / activityPerPage) || 1);

const resetActivityFilters = () => {
    activitySearch.value = '';
    activityModuleFilter.value = 'All';
    activityFromDate.value = '';
    activityToDate.value = '';
    activityPage.value = 1;
};

const moduleBadgeClasses = (moduleName: string): string => {
    switch (moduleName) {
        case 'Sales':
            return 'bg-emerald-50 text-emerald-800 border-emerald-200';
        case 'Inventory':
            return 'bg-blue-50 text-blue-800 border-blue-200';
        case 'Procurement':
            return 'bg-purple-50 text-purple-800 border-purple-200';
        case 'Suppliers':
            return 'bg-cyan-50 text-cyan-800 border-cyan-200';
        case 'User Management':
            return 'bg-indigo-50 text-indigo-800 border-indigo-200';
        case 'System Maintenance':
            return 'bg-amber-50 text-amber-900 border-amber-200';
        default:
            return 'bg-gray-100 text-gray-800 border-gray-200';
    }
};

// ─────────────────────────────────────────────────────────────
// PRINT SUMMARY
// ─────────────────────────────────────────────────────────────
const printReport = () => {
    window.print();
};

// ─────────────────────────────────────────────────────────────
// TOOLBAR EXPORT
// Exports the selected report view as shown: the period, the view's own filters and the report
// search are already applied to what is sent. The server only lays it out in the chosen format.
// ─────────────────────────────────────────────────────────────
type ColumnType = 'text' | 'number' | 'money';
type Cell = string | number | null;
interface ExportSection { title: string; columns: Array<{ label: string; type: ColumnType }>; rows: Cell[][] }

const exportFormats = [
    { value: 'xlsx', label: 'Excel', tag: '.xlsx' },
    { value: 'pdf', label: 'PDF', tag: 'Print' },
    { value: 'csv', label: 'CSV', tag: '.csv' },
    { value: 'jpg', label: 'JPG', tag: 'Image' },
    { value: 'png', label: 'PNG', tag: 'Image' },
];
const EXPORT_MAX_DOCUMENT_ROWS = 2000; // ReportTableExporter::MAX_DOCUMENT_ROWS
const exportFormat = ref('xlsx');
const exporting = ref(false);

const col = (label: string, type: ColumnType = 'text') => ({ label, type });
const money = (label: string) => col(`${label} (${displayCurrency.value})`, 'money');
const amount = (usd: unknown) => Math.round(toDisplayAmount(usd) * 100) / 100;
const dateOnly = (v: string | null | undefined) => (v ? v.split('T')[0] : null);
const contribution = (p: ProductPriority, key: string): Cell => {
    const c = priorityContribution(p, key);
    return c?.available ? `${c.score!.toFixed(2)} → ${c.weighted!.toFixed(2)}` : 'Insufficient data';
};

const categoryValuationSection = (title: string): ExportSection => ({
    title,
    columns: [col('Category'), col('Products', 'number'), col('Stock Units', 'number'), money('Valuation')],
    rows: props.inventoryAnalytics.byCategory.map((c) => [c.category, c.count, c.stock, amount(c.valuation)]),
});
const revenueSection = (title: string): ExportSection => ({
    title,
    columns: [col('Period'), money('Sales Revenue')],
    rows: props.quotationsSalesAnalytics.salesTrend.labels.map((label, i) => [label, amount(props.quotationsSalesAnalytics.salesTrend.data[i])]),
});
const stockHealthSection = (title: string): ExportSection => ({
    title,
    columns: [col('Status'), col('Products', 'number')],
    rows: [
        ['In Stock', props.inventoryAnalytics.statusDistribution.in_stock],
        ['Low Stock', props.inventoryAnalytics.statusDistribution.low_stock],
        ['Out of Stock', props.inventoryAnalytics.statusDistribution.out_of_stock],
    ],
});
const countSection = (title: string, label: string, dist: Record<string, number>): ExportSection => ({
    title,
    columns: [col('Status'), col(label, 'number')],
    rows: Object.entries(dist).map(([status, n]) => [status, n]),
});

/** The tables of each report view, built from the same filtered lists the page displays. */
const exportSections = (): ExportSection[] => {
    const m = props.metrics;
    switch (activeTab.value) {
        case 'overview': {
            const top = props.ahpAnalytics.topSuppliers[0];
            const conv = props.quotationsSalesAnalytics.conversion;
            return [
                {
                    title: 'Key Figures',
                    columns: [col('Measure'), col('Value')],
                    rows: [
                        ['Catalog valuation (at cost price)', formatCurrency(m.totalValuation)],
                        ['Active catalog items', formatNumber(m.inventoryCount)],
                        ['Low stock items', formatNumber(m.lowStockCount)],
                        ['Out of stock items', formatNumber(m.outOfStockCount)],
                        ['Sales revenue', formatCurrency(m.totalRevenue)],
                        ['Completed sales', formatNumber(m.salesCount)],
                        ['Procurement spend', formatCurrency(m.totalPurchasesEstimatedCost)],
                        ['Purchase requests', formatNumber(m.totalPurchasesCount)],
                        ['Pending quotations', `${m.pendingQuotationsCount} (${formatCurrency(m.pendingQuotationsValue)})`],
                        ['Quotation conversion', `${conv.rate}% (${conv.converted} of ${conv.total})`],
                        ['Top supplier (AHP)', top ? `${top.name}${top.complete ? ` (${Number(top.ahp_score).toFixed(2)})` : ' (insufficient data)'}` : 'No evaluation yet'],
                    ],
                },
                revenueSection('Monthly Revenue Velocity'),
                {
                    title: 'Catalog Stock Health',
                    columns: [col('Status'), col('Products', 'number')],
                    rows: [['In Stock', m.inStockCount], ['Low Stock', m.lowStockCount - m.outOfStockCount], ['Out of Stock', m.outOfStockCount]],
                },
                categoryValuationSection('Stock Units by Product Category'),
                {
                    title: 'Top Products by Inventory Value',
                    columns: [col('SKU'), col('Product'), col('Category'), col('Stock', 'number'), col('Unit'), money('Unit Cost'), money('Valuation')],
                    rows: props.inventoryAnalytics.topProductsByValuation.map((p) => [p.sku, p.name, p.category, p.stock, p.unit, amount(p.cost_price || p.price), amount(p.valuation)]),
                },
            ];
        }
        case 'inventory':
            return [
                stockHealthSection('Status Distribution'),
                categoryValuationSection('Inventory Valuation by Category'),
                {
                    title: 'Inventory Status Manifest',
                    columns: [col('SKU'), col('Product Name'), col('Category'), col('Supplier'), col('Stock', 'number'), col('Unit'), col('Threshold', 'number'), money('Cost / Unit'), money('Total Valuation'), col('Status')],
                    rows: filteredInventory.value.map((i) => [i.sku, i.name, i.category, i.supplier_name, i.current_stock, i.unit, i.reorder_threshold, amount(i.cost_price || i.price), amount(i.total_valuation), i.stock_status]),
                },
            ];
        case 'low-stock':
            return [
                {
                    title: 'Stock Shortage Severity',
                    columns: [col('Product'), col('SKU'), col('Shortage (Units)', 'number')],
                    rows: props.lowStockAnalytics.severity.map((i) => [i.name, i.sku, i.shortage]),
                },
                {
                    title: 'Restock Prioritization Audit',
                    columns: [col('Product Name'), col('SKU'), col('Category'), col('Available Stock', 'number'), col('Threshold', 'number'), col('Deficit Shortage', 'number'), col('Suggested Reorder', 'number'), col('Primary Supplier'), col('Urgency')],
                    rows: filteredLowStock.value.map((i) => [i.name, i.sku, i.category, i.stock, i.reorder_level, i.shortage, i.suggested_reorder, i.supplier_name, i.urgency]),
                },
            ];
        case 'sales':
            return [
                revenueSection('Revenue Generation Timeline'),
                countSection('Quotation Statuses', 'Quotations', props.quotationsSalesAnalytics.quotationStatusDistribution),
                salesSubTab.value === 'sales'
                    ? {
                          title: 'Sales Invoices',
                          columns: [col('Invoice #'), col('Customer Name'), col('Sale Date'), money('Subtotal'), money('Tax'), money('Grand Total'), col('Payment'), col('Status')],
                          rows: filteredSales.value.map((s) => [s.invoice_number, s.client_name, s.sale_date, amount(s.subtotal), amount(s.tax_amount), amount(s.grand_total), s.payment_method, s.status]),
                      }
                    : {
                          title: 'Customer Quotations',
                          columns: [col('Quote #'), col('Customer Name'), col('Date Issued'), col('Valid Until'), money('Quoted Amount'), col('Status'), col('Converted')],
                          rows: filteredQuotations.value.map((q) => [q.quotation_number, q.client_name, dateOnly(q.quotation_date), dateOnly(q.valid_until), amount(q.grand_total), q.status, q.is_converted ? 'Yes' : 'No']),
                      },
            ];
        case 'forecast': {
            const f = props.forecastAnalytics;
            return [{
                title: `Monthly Forecast Table (Year ${f.year})`,
                columns: [col('Period'), col('Historical Demand (Units)', 'number'), col(`SMA-${f.window} Forecast (Units)`, 'number'), col('Step-by-step SMA')],
                rows: f.labels.map((label, i) => {
                    const step = stepByMonth.value.get(`${label} ${f.year}`);
                    return [`${label} ${f.year}`, f.historical[i], f.forecast[i] ?? 'N/A',
                        step ? `(${step.inputs.map((x) => x.units).join(' + ')}) / ${f.window} = ${step.sum} / ${f.window}` : `Needs ${f.window} prior months`];
                }),
            }];
        }
        case 'procurement': {
            const dist = props.procurementAnalytics.statusDistribution;
            return [
                countSection('Purchase Request Statuses', 'Requests', dist),
                {
                    title: 'Procurement Operational Summary',
                    columns: [col('Measure'), col('Value')],
                    rows: [
                        ['Total requisitions', formatNumber(m.totalPurchasesCount)],
                        ['Pending / approved', formatNumber((dist['Pending Approval'] || 0) + (dist['Approved'] || 0))],
                        ['Converted to PO', formatNumber(dist['Converted to Purchase Order'] || 0)],
                        ['Total committed spend', formatCurrency(props.procurementAnalytics.totalEstimatedCost)],
                    ],
                },
                {
                    title: 'Purchase Orders & Requests Ledger',
                    columns: [col('Request #'), col('Item Name'), col('SKU'), col('Supplier'), col('Requested By'), col('Qty', 'number'), money('Est. Cost'), col('Status'), col('Date')],
                    rows: props.procurementAnalytics.requestsList.map((r) => [r.request_number, r.product_name, r.sku, r.supplier_name, r.requested_by, r.quantity, amount(r.estimated_cost), r.status, r.date]),
                },
            ];
        }
        case 'priority': {
            const w = props.productPriority.weights;
            return [
                {
                    title: 'AHP Methodology and Criteria Weights',
                    columns: [col('Criterion'), col('Weight')],
                    rows: [['Demand Forecasting', pctW(w.demand)], ['Current Stock', pctW(w.stock)], ['Product Movement', pctW(w.movement)], ['Supplier Availability', pctW(w.supplier_availability)]],
                },
                {
                    title: 'Weighted Contributions per Product',
                    columns: [col('Rank'), col('Product'), col('SKU'), col(`Forecast, next ${priorityPeriodLabel.value}`, 'number'), col('Demand'), col('Current Stock'), col('Movement'), col('Supplier Avail.'), col('AHP Score', 'number'), col('1–5 Scale', 'number'), col('Priority'), col('Reorder')],
                    rows: props.productPriority.items.map((p, i) => [
                        p.complete ? `#${i + 1}` : '—', p.product.name, p.product.sku, p.forecast?.period_total ?? null,
                        ...priorityKeys.map((k) => contribution(p, k)),
                        p.complete ? Number(p.score!.toFixed(2)) : 'Incomplete',
                        p.scale ? Number(p.scale.score.toFixed(2)) : null,
                        `${p.classification ?? 'Not classified'}${p.scale?.action ? ` — ${p.scale.action}` : ''}`,
                        p.reorder.needed ? `Reorder ${p.reorder.suggested_quantity}` : 'No',
                    ]),
                },
            ];
        }
        case 'ahp': {
            const w = props.ahpAnalytics.weights;
            const metric = (v: number | null | undefined): Cell => (v != null ? Number(Number(v).toFixed(2)) : null);
            return [
                {
                    title: 'Supplier Evaluation Weights',
                    columns: [col('Criterion'), col('Weight')],
                    rows: [['Inbound Delivery Performance', pctW(w.delivery)], ['Price Competitiveness', pctW(w.price)], ['Product Quality', pctW(w.quality)]],
                },
                {
                    title: 'Ranked Supplier Directory',
                    columns: [col('Rank'), col('Supplier Name'), col('Contact Person'), col('Phone'), col('Email'), col('Evaluation Score', 'number'), col(`Delivery (${pctW(w.delivery)})`, 'number'), col(`Price (${pctW(w.price)})`, 'number'), col(`Quality (${pctW(w.quality)})`, 'number'), col('Classification'), col('Status')],
                    rows: props.ahpAnalytics.suppliersList.map((s) => [
                        s.rank ? `#${s.rank}` : '—', s.name, s.contact_person, s.phone, s.email,
                        s.complete ? metric(s.ahp_score) : 'Insufficient data',
                        metric(s.metrics?.delivery), metric(s.metrics?.price), metric(s.metrics?.quality),
                        s.classification ?? 'Not classified', s.status,
                    ]),
                },
            ];
        }
        case 'activity':
            return [
                {
                    title: 'Activity by Module',
                    columns: [col('Module'), col('Events', 'number')],
                    rows: activityModulesList.value.map((mod) => [mod, props.activityFeed?.byModule?.[mod] ?? 0]),
                },
                {
                    title: 'Recent Activity Feed',
                    columns: [col('Timestamp'), col('User'), col('User Email'), col('Action Performed'), col('Description'), col('Affected Module'), col('Record Reference'), col('Outcome')],
                    rows: filteredActivities.value.map((a) => [a.timestamp, a.user, a.user_email, a.action, a.description, a.module, a.reference, a.outcome]),
                },
            ];
    }
    return [];
};

/** The filters behind the export, for the file's subtitle. */
const exportScope = () => {
    const parts = [`Period: ${activeScopeLabel.value}`];
    const add = (label: string, value: string, unset = 'All') => {
        if (value && value !== unset) parts.push(`${label}: ${value}`);
    };
    if (activeTab.value === 'inventory') {
        add('Category', invCategoryFilter.value);
        add('Status', invStatusFilter.value);
        add('Search', invSearch.value.trim() && `"${invSearch.value.trim()}"`);
    } else if (activeTab.value === 'low-stock') {
        add('Category', lowStockCategoryFilter.value);
        add('Search', lowStockSearch.value.trim() && `"${lowStockSearch.value.trim()}"`);
    } else if (activeTab.value === 'sales') {
        add('Search', salesSearch.value.trim() && `"${salesSearch.value.trim()}"`);
    } else if (activeTab.value === 'activity') {
        add('Module', activityModuleFilter.value);
        add('From', activityFromDate.value);
        add('To', activityToDate.value);
        add('Search', activitySearch.value.trim() && `"${activitySearch.value.trim()}"`);
    }
    add('Report search', reportSearchTerm.value && `"${reportSearch.value.trim()}"`);
    return parts.join(' · ');
};

const exportReport = async () => {
    const viewLabel = reportViews.find((v) => v.key === activeTab.value)?.label ?? 'Report';
    const term = reportSearchTerm.value;
    // Like the page: with a report search, only the sections that contain it (unless the view's name matches).
    let sections = exportSections();
    if (term && !viewLabel.toLowerCase().includes(term)) {
        sections = sections.filter((s) => [s.title, ...s.columns.map((c) => c.label), ...s.rows.flat()].join(' ').toLowerCase().includes(term));
    }
    if (!sections.length) {
        showToast(`Nothing in ${viewLabel} matches "${reportSearch.value.trim()}" to export.`, 'error');
        return;
    }

    const format = exportFormat.value;
    const rowCount = sections.reduce((n, s) => n + s.rows.length, 0);
    if (['pdf', 'png', 'jpg'].includes(format) && rowCount > EXPORT_MAX_DOCUMENT_ROWS) {
        showToast(`${rowCount} rows is more than a ${format.toUpperCase()} export can hold (${EXPORT_MAX_DOCUMENT_ROWS}). Narrow the filters, or export to Excel or CSV.`, 'error');
        return;
    }

    const label = exportFormats.find((f) => f.value === format)?.label ?? format;
    exporting.value = true;
    showToast(`Preparing ${viewLabel} as ${label}...`, 'success');
    try {
        const csrf = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';
        const res = await fetch(`/${teamSlug.value}/reports/export`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf },
            body: JSON.stringify({ format, title: viewLabel, scope: exportScope(), sections }),
        });
        if (!res.ok) {
            const body = await res.json().catch(() => null);
            throw new Error(body?.message || `The export failed (error ${res.status}).`);
        }
        const name = /filename="?([^"]+)"?/.exec(res.headers.get('Content-Disposition') ?? '')?.[1] ?? `report.${format}`;
        const url = URL.createObjectURL(await res.blob());
        const link = Object.assign(document.createElement('a'), { href: url, download: name });
        document.body.appendChild(link);
        link.click();
        link.remove();
        setTimeout(() => URL.revokeObjectURL(url), 1000);
    } catch (e) {
        showToast(e instanceof Error ? e.message : 'The export failed.', 'error');
    } finally {
        exporting.value = false;
    }
};

const goToProcurement = () => {
    router.visit(`/${teamSlug.value}/procurement`);
};

// ─────────────────────────────────────────────────────────────
// CHART CONFIGURATIONS
// ─────────────────────────────────────────────────────────────

// 1. Stock Status Donut Chart
const stockDonutData = computed(() => ({
    labels: ['In Stock', 'Low Stock', 'Out of Stock'],
    datasets: [{
        data: [
            props.inventoryAnalytics.statusDistribution.in_stock,
            props.inventoryAnalytics.statusDistribution.low_stock,
            props.inventoryAnalytics.statusDistribution.out_of_stock,
        ],
        backgroundColor: ['#10b981', '#f59e0b', '#ef4444'],
        borderWidth: 2,
        borderColor: '#ffffff',
    }],
}));

// 2. Category Inventory Bar Chart
const categoryBarData = computed(() => ({
    labels: props.inventoryAnalytics.byCategory.map(c => c.category),
    datasets: [
        {
            label: 'Total Stock (Units)',
            data: props.inventoryAnalytics.byCategory.map(c => c.stock),
            backgroundColor: 'rgba(75, 83, 32, 0.85)', // brand green
            borderRadius: 6,
        },
    ],
}));

// 3. Top Products by Value Horizontal Bar Chart
const topValueBarData = computed(() => ({
    labels: props.inventoryAnalytics.topProductsByValuation.map(p => p.name.length > 18 ? p.name.substring(0, 18) + '...' : p.name),
    datasets: [{
        label: `Total Valuation (${displayCurrency.value})`,
        data: props.inventoryAnalytics.topProductsByValuation.map(p => toDisplayAmount(p.valuation)),
        backgroundColor: 'rgba(248, 184, 3, 0.85)', // gold
        borderRadius: 6,
    }],
}));

// 4. Low Stock Severity Horizontal Bar Chart
const lowStockSeverityData = computed(() => ({
    labels: props.lowStockAnalytics.severity.map(p => p.name.length > 18 ? p.name.substring(0, 18) + '...' : p.name),
    datasets: [{
        label: 'Stock Shortage (Units Below Threshold)',
        data: props.lowStockAnalytics.severity.map(p => p.shortage),
        backgroundColor: '#ef4444',
        borderRadius: 6,
    }],
}));

// 5. Sales Revenue Trend Line Chart
const salesRevenueTrendData = computed(() => ({
    labels: props.quotationsSalesAnalytics.salesTrend.labels,
    datasets: [{
        label: `Sales Revenue (${displayCurrency.value})`,
        data: props.quotationsSalesAnalytics.salesTrend.data.map(v => toDisplayAmount(v)),
        borderColor: '#10b981',
        backgroundColor: 'rgba(16, 185, 129, 0.1)',
        fill: true,
        tension: 0.35,
        pointBackgroundColor: '#10b981',
        pointRadius: 4,
    }],
}));

// 6. Quotation Status Donut Chart
const quotationStatusDonutData = computed(() => {
    const dist = props.quotationsSalesAnalytics.quotationStatusDistribution;
    return {
        labels: Object.keys(dist),
        datasets: [{
            data: Object.values(dist),
            backgroundColor: ['#f59e0b', '#10b981', '#ef4444', '#3b82f6', '#94a3b8', '#64748b'],
            borderWidth: 2,
            borderColor: '#ffffff',
        }],
    };
});

// 7. Top Selling Products Horizontal Bar Chart
const topSellingBarData = computed(() => ({
    labels: props.quotationsSalesAnalytics.topSellingProducts.map(p => p.name.length > 16 ? p.name.substring(0, 16) + '...' : p.name),
    datasets: [{
        label: 'Units Sold',
        data: props.quotationsSalesAnalytics.topSellingProducts.map(p => p.quantity),
        backgroundColor: '#4b5320',
        borderRadius: 6,
    }],
}));

// 8. Forecast Line Chart (Historical vs SMA)
const forecastChartData = computed(() => ({
    labels: props.forecastAnalytics.labels,
    datasets: [
        {
            label: 'Recorded Demand (Units)',
            data: props.forecastAnalytics.historical,
            borderColor: '#10b981',
            backgroundColor: 'rgba(16, 185, 129, 0.1)',
            fill: true,
            tension: 0.35,
            pointRadius: 3,
        },
        {
            label: 'SMA-3 Forecast',
            data: props.forecastAnalytics.forecast,
            borderColor: '#F8B803',
            borderDash: [5, 5],
            backgroundColor: 'transparent',
            tension: 0.35,
            pointRadius: 4,
            pointBackgroundColor: '#F8B803',
        },
    ],
}));

// 9. Procurement Status Donut Chart
const procurementDonutData = computed(() => {
    const dist = props.procurementAnalytics.statusDistribution;
    return {
        labels: Object.keys(dist),
        datasets: [{
            data: Object.values(dist),
            backgroundColor: ['#94a3b8', '#f59e0b', '#3b82f6', '#10b981', '#ef4444'],
            borderWidth: 2,
            borderColor: '#ffffff',
        }],
    };
});

// 10. AHP Top Suppliers Bar Chart
const ahpBarData = computed(() => ({
    labels: props.ahpAnalytics.topSuppliers.filter(s => s.complete).map(s => s.name),
    datasets: [{
        label: 'Supplier Evaluation Score (0–100)',
        data: props.ahpAnalytics.topSuppliers.filter(s => s.complete).map(s => s.ahp_score),
        backgroundColor: '#4b5320',
        borderRadius: 6,
    }],
}));

// Shared Chart Options
const standardChartOptions = {
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
        legend: {
            position: 'top' as const,
            labels: { boxWidth: 12, usePointStyle: true, font: { size: 11, weight: 600 } },
        },
    },
};

const horizontalBarOptions = {
    indexAxis: 'y' as const,
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
        legend: { display: false },
    },
    scales: {
        x: { grid: { color: '#f3f4f6' }, ticks: { font: { size: 10 } } },
        y: { ticks: { font: { size: 11 } } },
    },
};

// Money charts: axis ticks and tooltips in the display currency (the plotted values already are).
const moneyTick = (value: number | string) => formatDisplayAmount(value, { decimals: 0 });
const moneyTooltip = (axis: 'x' | 'y') => ({
    callbacks: {
        label: (ctx: any) => `${ctx.dataset.label ?? ''}: ${formatDisplayAmount(ctx.parsed?.[axis])}`,
    },
});

const moneyLineOptions = computed(() => ({
    ...standardChartOptions,
    plugins: { ...standardChartOptions.plugins, tooltip: moneyTooltip('y') },
    scales: { y: { ticks: { callback: moneyTick } } },
}));

const moneyHorizontalBarOptions = computed(() => ({
    ...horizontalBarOptions,
    plugins: { ...horizontalBarOptions.plugins, tooltip: moneyTooltip('x') },
    scales: {
        ...horizontalBarOptions.scales,
        x: { ...horizontalBarOptions.scales.x, ticks: { ...horizontalBarOptions.scales.x.ticks, callback: moneyTick } },
    },
}));
</script>

<template>
    <Head title="Reports & Analytics" />

    <div class="workspace-page">
        <!-- ─── Print Summary (printed instead of the screen; hidden on screen) ─── -->
        <PrintSummary
            class="hidden print:block"
            :company-name="companyName ?? 'MR-PRIDE Electrical Supplies'"
            :period-label="activeScopeLabel"
            :metrics="metrics"
            :stock-status="inventoryAnalytics.statusDistribution"
            :categories="inventoryAnalytics.byCategory"
            :low-stock-items="lowStockAnalytics.items"
            :sales-trend="quotationsSalesAnalytics.salesTrend"
            :top-selling-products="quotationsSalesAnalytics.topSellingProducts"
            :conversion="quotationsSalesAnalytics.conversion"
            :quotation-statuses="quotationsSalesAnalytics.quotationStatusDistribution"
            :forecast="forecastAnalytics"
            :purchase-statuses="procurementAnalytics.statusDistribution"
            :suppliers="ahpAnalytics.suppliersList"
            :reorder-needed-count="productPriority.items.filter((p) => p.reorder.needed).length"
        />

        <!-- ─── Main Header (Screen Only) ─────────────────────────── -->
        <div class="print:hidden page-header">
            <div>
                <h1 class="page-title">Reports &amp; Analytics</h1>
                <p class="page-subtitle">Sales, inventory and forecast reports for any period.</p>
            </div>

            <!-- Top Action (exports are in the report toolbar below) -->
            <div class="page-actions">
                <SpecularButton
                    variant="secondary"
                    size="md"
                    :radius="12"
                    @click="printReport"
                    class="w-full sm:w-auto"
                    title="Print a short business summary for the selected period"
                >
                    <Printer class="w-4 h-4 mr-1.5 inline text-gray-700" />
                    Print Summary
                </SpecularButton>
            </div>
        </div>

        <!-- ─── SECTION A: BUSINESS PERFORMANCE OVERVIEW & KPIS ───── -->
        <div class="space-y-4 print:hidden">
            <!-- Global Date & Scope Filter Bar -->
            <div class="print:hidden rounded-xl border border-gray-200 bg-white p-4">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div class="flex flex-wrap items-center gap-2.5">
                        <span class="text-sm font-medium text-gray-700 flex items-center gap-1.5">
                            <Calendar class="w-3.5 h-3.5 text-gray-400" />
                            Report Period:
                        </span>

                        <select
                            v-model="selectedPreset"
                            @change="onPresetChange"
                            class="field-control sm:w-auto"
                        >
                            <option value="all_time">All-Time Records</option>
                            <option value="today">Today</option>
                            <option value="last_7_days">Last 7 Days</option>
                            <option value="last_30_days">Last 30 Days</option>
                            <option value="this_month">This Month</option>
                            <option value="this_year">This Year</option>
                            <option value="custom">Custom Date Range</option>
                        </select>

                        <!-- Custom Date Pickers -->
                        <template v-if="selectedPreset === 'custom'">
                            <input
                                v-model="customFromDate"
                                type="date"
                                class="field-control sm:w-auto"
                            />
                            <span class="text-xs text-gray-400">to</span>
                            <input
                                v-model="customToDate"
                                type="date"
                                class="field-control sm:w-auto"
                            />
                            <button type="button"
                                @click="applyDateFilter"
                                class="brand-button"
                            >
                                Apply
                            </button>
                        </template>
                    </div>

                    <div class="flex items-center gap-2">
                        <span class="text-xs text-gray-500 bg-gray-100 px-2.5 py-1 rounded-lg font-medium">
                            Scope: <strong class="text-gray-800">{{ activeScopeLabel }}</strong>
                        </span>

                        <button
                            v-if="selectedPreset !== 'all_time'"
                            @click="resetFilters"
                            class="text-xs text-gray-500 hover:text-gray-900 flex items-center gap-1 font-medium transition-colors ml-1"
                            title="Reset date filters"
                        >
                            <RotateCcw class="w-3.5 h-3.5" />
                            Reset
                        </button>
                    </div>
                </div>
            </div>

            <!-- Primary KPIs: value plus one supporting detail. Cards that open a tab say so on hover. -->
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
                <div class="bg-white border border-gray-200 rounded-xl px-4 py-3" :title="metrics.valuationBasisNote">
                    <div class="text-xs text-gray-500">Catalog valuation</div>
                    <div class="mt-1 text-2xl font-semibold text-gray-900">{{ formatCurrency(metrics.totalValuation) }}</div>
                    <div class="mt-1 text-xs text-gray-500">{{ metrics.inventoryCount }} items at cost price</div>
                </div>

                <button type="button" @click="activeTab = 'low-stock'" class="text-left bg-white border border-gray-200 rounded-xl px-4 py-3 hover:bg-gray-50 transition-colors">
                    <div class="text-xs text-gray-500">Low stock</div>
                    <div :class="metrics.lowStockCount > 0 ? 'text-amber-600' : 'text-gray-900'" class="mt-1 text-2xl font-semibold">{{ metrics.lowStockCount }} items</div>
                    <div class="mt-1 text-xs" :class="metrics.outOfStockCount > 0 ? 'text-rose-600' : 'text-gray-500'">{{ metrics.outOfStockCount }} out of stock</div>
                </button>

                <button type="button" @click="activeTab = 'sales'" class="text-left bg-white border border-gray-200 rounded-xl px-4 py-3 hover:bg-gray-50 transition-colors">
                    <div class="text-xs text-gray-500">Sales revenue</div>
                    <div class="mt-1 text-2xl font-semibold text-gray-900">{{ formatCurrency(metrics.totalRevenue) }}</div>
                    <div class="mt-1 text-xs text-gray-500">{{ metrics.salesCount }} completed sales</div>
                </button>

                <button type="button" @click="activeTab = 'procurement'" class="text-left bg-white border border-gray-200 rounded-xl px-4 py-3 hover:bg-gray-50 transition-colors">
                    <div class="text-xs text-gray-500">Procurement spend</div>
                    <div class="mt-1 text-2xl font-semibold text-gray-900">{{ formatCurrency(metrics.totalPurchasesEstimatedCost) }}</div>
                    <div class="mt-1 text-xs text-gray-500">{{ metrics.totalPurchasesCount }} purchase requests</div>
                </button>
            </div>

            <!-- Secondary indicators -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-x-6 gap-y-2 text-sm">
                <div class="flex items-center justify-between border-b border-gray-100 py-2">
                    <span class="text-gray-500">Pending quotations</span>
                    <span class="font-medium text-gray-900">{{ metrics.pendingQuotationsCount }} ({{ formatCurrency(metrics.pendingQuotationsValue) }})</span>
                </div>
                <div class="flex items-center justify-between border-b border-gray-100 py-2">
                    <span class="text-gray-500">Quotation conversion</span>
                    <span class="font-medium text-gray-900">
                        {{ quotationsSalesAnalytics.conversion.rate }}% ({{ quotationsSalesAnalytics.conversion.converted }} of {{ quotationsSalesAnalytics.conversion.total }})
                    </span>
                </div>
                <div class="flex items-center justify-between border-b border-gray-100 py-2">
                    <span class="text-gray-500">Top supplier (AHP)</span>
                    <span class="font-medium text-gray-900">
                        {{ ahpAnalytics.topSuppliers[0]?.name || 'No evaluation yet' }}<template v-if="ahpAnalytics.topSuppliers[0]?.complete"> ({{ Number(ahpAnalytics.topSuppliers[0].ahp_score).toFixed(2) }})</template><template v-else-if="ahpAnalytics.topSuppliers[0]"> (insufficient data)</template>
                    </span>
                </div>
            </div>
        </div>

        <!-- ─── SECTION B: INTERACTIVE ANALYTICS & MODULE REPORT TABS ─ -->
        <div class="space-y-6 print:hidden">
            <!-- Report View Dropdown + Report Search -->
            <div class="print:hidden space-y-2">
                <div class="flex flex-col md:flex-row md:items-center gap-2">
                    <label for="report-view" class="sr-only">Report view</label>
                    <select
                        id="report-view"
                        v-model="activeTab"
                        class="field-control w-full md:w-64 lg:w-72 shrink-0 cursor-pointer"
                    >
                        <option v-for="view in reportViews" :key="view.key" :value="view.key">{{ view.label }}</option>
                    </select>

                    <div class="relative w-full md:max-w-md md:flex-1">
                        <Search class="w-4 h-4 text-gray-400 absolute left-3 top-1/2 -translate-y-1/2 pointer-events-none" />
                        <input
                            v-model="reportSearch"
                            type="search"
                            aria-label="Search reports"
                            placeholder="Search reports..."
                            class="field-control w-full pl-9 pr-9"
                        />
                        <button
                            v-if="reportSearch"
                            type="button"
                            @click="reportSearch = ''"
                            class="absolute right-2.5 top-1/2 -translate-y-1/2 p-0.5 text-gray-400 hover:text-gray-700"
                            aria-label="Clear search"
                        >
                            <X class="w-3.5 h-3.5" />
                        </button>
                    </div>

                    <!-- Export: the selected view, with its filters, in the chosen format (same controls as Inventory). -->
                    <div class="inline-flex items-center self-start md:self-auto md:ml-auto h-10 shrink-0 rounded-xl border border-gray-200 bg-white p-1">
                        <GlideSelect
                            :options="exportFormats"
                            :value="exportFormat"
                            ariaLabel="Export format"
                            showTags
                            accentColor="#4b5320"
                            surfaceColor="#ffffff"
                            highlightColor="#f3f4f6"
                            textColor="#374151"
                            size="md"
                            :radius="12"
                            :menuWidth="168"
                            placement="bottom"
                            align="left"
                            className="z-30 text-xs"
                            @change="(value: string) => (exportFormat = value)"
                        />

                        <button
                            type="button"
                            @click="exportReport"
                            :disabled="exporting"
                            class="inline-flex items-center h-full gap-1.5 px-3 text-xs font-medium rounded-lg text-gray-700 hover:bg-gray-100 transition-colors cursor-pointer disabled:cursor-wait disabled:opacity-60"
                            title="Download the selected report view (with the current filters) in the chosen format"
                        >
                            <Download class="w-3.5 h-3.5 text-gray-500" />
                            <span>{{ exporting ? 'Exporting...' : 'Export' }}</span>
                        </button>
                    </div>
                </div>

                <!-- Search result summary and matches in other report views -->
                <div v-if="reportSearchTerm" class="flex flex-wrap items-center gap-x-3 gap-y-1.5 text-xs text-gray-500">
                    <span>
                        <template v-if="reportSectionCount.shown">
                            {{ reportSectionCount.shown }} of {{ reportSectionCount.total }} sections in
                            <strong class="text-gray-800">{{ reportViews.find((v) => v.key === activeTab)?.label }}</strong>
                            match "{{ reportSearch.trim() }}"
                        </template>
                        <template v-else>
                            Nothing in <strong class="text-gray-800">{{ reportViews.find((v) => v.key === activeTab)?.label }}</strong>
                            matches "{{ reportSearch.trim() }}"
                        </template>
                    </span>
                    <template v-if="otherMatchingViews.length">
                        <span>Also in:</span>
                        <button
                            v-for="view in otherMatchingViews"
                            :key="view.key"
                            type="button"
                            @click="activeTab = view.key"
                            class="px-2 py-0.5 rounded-lg border border-gray-200 bg-white text-gray-700 hover:bg-gray-50 transition-colors"
                        >
                            {{ view.label }}
                        </button>
                    </template>
                </div>
            </div>

            <!-- ═════════════════════════════════════════════════════ -->
            <!-- TAB 1: OVERVIEW DASHBOARD                             -->
            <!-- ═════════════════════════════════════════════════════ -->
            <div v-if="activeTab === 'overview'" data-report-view class="space-y-6">
                <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                    <!-- Sales Revenue Trend -->
                    <div class="lg:col-span-2 bg-white border border-gray-200 rounded-xl p-6 flex flex-col">
                        <div class="flex justify-between items-start mb-4">
                            <div>
                                <h3 class="text-base font-semibold text-gray-900">Monthly Revenue Velocity</h3>
                                <p class="text-xs text-gray-400">Actual sales income aggregated per billing cycle</p>
                            </div>
                            <span class="text-xs font-semibold px-2.5 py-1 bg-emerald-50 text-emerald-700 rounded-full">
                                Total: {{ formatCurrency(metrics.totalRevenue) }}
                            </span>
                        </div>
                        <div class="h-64">
                            <Line :data="salesRevenueTrendData" :options="moneyLineOptions" />
                        </div>
                    </div>

                    <!-- Stock Health Donut -->
                    <div class="bg-white border border-gray-200 rounded-xl p-6 flex flex-col justify-between">
                        <div>
                            <h3 class="text-base font-semibold text-gray-900 mb-1">Catalog Stock Health</h3>
                            <p class="text-xs text-gray-400 mb-4">Real-time breakdown of warehouse thresholds</p>
                            <div class="h-48 relative flex items-center justify-center">
                                <Doughnut :data="stockDonutData" :options="standardChartOptions" />
                            </div>
                        </div>
                        <div class="grid grid-cols-3 gap-2 text-center pt-3 border-t border-gray-100 text-xs">
                            <div>
                                <span class="block text-emerald-600 font-semibold">{{ metrics.inStockCount }}</span>
                                <span class="text-gray-400 text-[10px]">In Stock</span>
                            </div>
                            <div>
                                <span class="block text-amber-600 font-semibold">{{ metrics.lowStockCount - metrics.outOfStockCount }}</span>
                                <span class="text-gray-400 text-[10px]">Low Stock</span>
                            </div>
                            <div>
                                <span class="block text-rose-600 font-semibold">{{ metrics.outOfStockCount }}</span>
                                <span class="text-gray-400 text-[10px]">Out of Stock</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Row 2: Categories & Top Products -->
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                    <!-- Inventory by Category Bar Chart -->
                    <div class="bg-white border border-gray-200 rounded-xl p-6">
                        <div class="flex justify-between items-start mb-4">
                            <div>
                                <h3 class="text-base font-semibold text-gray-900">Stock Units by Product Category</h3>
                                <p class="text-xs text-gray-400">Inventory volume distribution across classifications</p>
                            </div>
                        </div>
                        <div class="h-60">
                            <Bar :data="categoryBarData" :options="standardChartOptions" />
                        </div>
                    </div>

                    <!-- Top Products by Valuation -->
                    <div class="bg-white border border-gray-200 rounded-xl p-6 flex flex-col">
                        <div class="flex justify-between items-start mb-4">
                            <div>
                                <h3 class="text-base font-semibold text-gray-900">Top Products by Inventory Value</h3>
                                <p class="text-xs text-gray-400">Capital tied up in current on-hand warehouse stock</p>
                            </div>
                            <button
                                @click="activeTab = 'inventory'"
                                class="text-xs font-semibold text-[#4b5320] hover:underline flex items-center gap-1"
                            >
                                View Table <ArrowUpRight class="w-3.5 h-3.5" />
                            </button>
                        </div>
                        <div class="h-60">
                            <Bar :data="topValueBarData" :options="moneyHorizontalBarOptions" />
                        </div>
                    </div>
                </div>
            </div>

            <!-- ═════════════════════════════════════════════════════ -->
            <!-- TAB 2: INVENTORY STATUS REPORT                        -->
            <!-- ═════════════════════════════════════════════════════ -->
            <div v-else-if="activeTab === 'inventory'" data-report-view class="space-y-6">
                <!-- Visualizations -->
                <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                    <div class="bg-white border border-gray-200 rounded-xl p-6">
                        <h4 class="text-sm font-semibold text-gray-900 mb-1">Status Distribution</h4>
                        <p class="text-xs text-gray-400 mb-3">Ratio of healthy vs understocked catalog items</p>
                        <div class="h-52">
                            <Doughnut :data="stockDonutData" :options="standardChartOptions" />
                        </div>
                    </div>

                    <div class="lg:col-span-2 bg-white border border-gray-200 rounded-xl p-6">
                        <h4 class="text-sm font-semibold text-gray-900 mb-1">Inventory Valuation by Category</h4>
                        <p class="text-xs text-gray-400 mb-3">Capital value allocated across product lines</p>
                        <div class="h-52">
                            <Bar :data="topValueBarData" :options="moneyHorizontalBarOptions" />
                        </div>
                    </div>
                </div>

                <!-- Section C: Filterable Detailed Inventory Table -->
                <div class="bg-white border border-gray-200 rounded-xl overflow-hidden">
                    <div class="p-4 border-b border-gray-200 flex flex-col sm:flex-row justify-between items-start sm:items-center gap-3">
                        <div>
                            <h3 class="text-base font-semibold text-gray-900">Inventory Status Manifest</h3>
                            <p class="text-xs text-gray-500">Live catalog quantities, threshold levels, and valuation bases</p>
                        </div>

                        <!-- Table Controls -->
                        <div class="flex flex-wrap items-center gap-2.5 w-full sm:w-auto">
                            <!-- Search -->
                            <div class="relative flex-1 sm:w-48">
                                <Search class="w-3.5 h-3.5 absolute left-3 top-2.5 text-gray-400" />
                                <input
                                    v-model="invSearch"
                                    type="text"
                                    placeholder="Search items..."
                                    class="w-full bg-gray-50 border border-gray-200 rounded-xl pl-8 pr-3 py-1.5 text-xs text-gray-900 focus:outline-none focus:border-gray-400"
                                />
                            </div>

                            <!-- Category Filter -->
                            <select
                                v-model="invCategoryFilter"
                                class="bg-white border border-gray-200 rounded-lg px-2.5 py-1.5 text-xs text-gray-900 font-medium focus:outline-none"
                            >
                                <option value="All">All Categories</option>
                                <option v-for="cat in categories" :key="cat" :value="cat">{{ cat }}</option>
                            </select>

                            <!-- Status Filter -->
                            <select
                                v-model="invStatusFilter"
                                class="bg-white border border-gray-200 rounded-lg px-2.5 py-1.5 text-xs text-gray-900 font-medium focus:outline-none"
                            >
                                <option value="All">All Statuses</option>
                                <option value="In Stock">In Stock</option>
                                <option value="Low Stock">Low Stock</option>
                                <option value="Out of Stock">Out of Stock</option>
                            </select>
                        </div>
                    </div>

                    <!-- Table -->
                    <div class="overflow-x-auto">
                        <table class="w-full text-left border-collapse text-xs">
                            <thead>
                                <tr class="bg-gray-50/80 border-b border-gray-200 text-gray-600 font-semibold text-[11px]">
                                    <th class="py-3 px-4">SKU</th>
                                    <th class="py-3 px-4">Product Name</th>
                                    <th class="py-3 px-4">Category</th>
                                    <th class="py-3 px-4">Supplier</th>
                                    <th class="py-3 px-4 text-center">Stock</th>
                                    <th class="py-3 px-4 text-center">Threshold</th>
                                    <th class="py-3 px-4 text-right">Cost / Unit</th>
                                    <th class="py-3 px-4 text-right">Total Valuation</th>
                                    <th class="py-3 px-4 text-center">Status</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                <tr v-if="!paginatedInventory.length">
                                    <td colspan="9" class="py-8 text-center text-gray-400">
                                        No matching inventory products found.
                                    </td>
                                </tr>
                                <tr
                                    v-for="item in paginatedInventory"
                                    :key="item.id"
                                    class="hover:bg-gray-50/70 transition-colors"
                                >
                                    <td class="py-3 px-4 font-mono font-medium text-gray-700">{{ item.sku }}</td>
                                    <td class="py-3 px-4 font-semibold text-gray-900">{{ item.name }}</td>
                                    <td class="py-3 px-4 text-gray-600">
                                        <span class="px-2 py-0.5 rounded bg-gray-100 text-gray-700 text-[10px] font-medium">
                                            {{ item.category }}
                                        </span>
                                    </td>
                                    <td class="py-3 px-4 text-gray-600">{{ item.supplier_name }}</td>
                                    <td class="py-3 px-4 text-center font-semibold text-gray-900">{{ item.current_stock }} {{ item.unit }}</td>
                                    <td class="py-3 px-4 text-center text-gray-500">{{ item.reorder_threshold }}</td>
                                    <td class="py-3 px-4 text-right font-medium text-gray-700">{{ formatCurrency(item.cost_price || item.price) }}</td>
                                    <td class="py-3 px-4 text-right font-semibold text-gray-900">{{ formatCurrency(item.total_valuation) }}</td>
                                    <td class="py-3 px-4 text-center">
                                        <span
                                            :class="[
                                                'px-2 py-0.5 rounded-full text-[10px] font-semibold',
                                                item.stock_status === 'Out of Stock' ? 'bg-red-100 text-red-700 border border-red-200' :
                                                item.stock_status === 'Low Stock' ? 'bg-amber-100 text-amber-800 border border-amber-200' :
                                                'bg-emerald-100 text-emerald-800 border border-emerald-200'
                                            ]"
                                        >
                                            {{ item.stock_status }}
                                        </span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- Pagination -->
                    <div class="p-3 border-t border-gray-100 flex items-center justify-between text-xs text-gray-500">
                        <span>Showing {{ filteredInventory.length }} items</span>
                        <div class="flex gap-1" v-if="totalInvPages > 1">
                            <button
                                v-for="p in totalInvPages"
                                :key="p"
                                @click="invPage = p"
                                :class="[
                                    'w-7 h-7 rounded-lg font-semibold flex items-center justify-center transition-colors',
                                    invPage === p ? 'bg-[#4b5320] text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200'
                                ]"
                            >
                                {{ p }}
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ═════════════════════════════════════════════════════ -->
            <!-- TAB 3: LOW STOCK AUDIT REPORT                         -->
            <!-- ═════════════════════════════════════════════════════ -->
            <div v-else-if="activeTab === 'low-stock'" data-report-view class="space-y-6">
                <!-- Visualizations -->
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                    <div class="bg-white border border-gray-200 rounded-xl p-6">
                        <h4 class="text-sm font-semibold text-gray-900 mb-1">Stock Shortage Severity</h4>
                        <p class="text-xs text-gray-400 mb-3">Units below configured minimum reorder thresholds</p>
                        <div class="h-56">
                            <Bar :data="lowStockSeverityData" :options="horizontalBarOptions" />
                        </div>
                    </div>

                    <div class="bg-white border border-gray-200 rounded-xl p-6 flex flex-col justify-between">
                        <div>
                            <h4 class="text-sm font-semibold text-gray-900 mb-1">Restock Priority Rules</h4>
                            <p class="text-xs text-gray-400 mb-3">Automated replenishment calculation methodology</p>
                            <div class="space-y-2 text-xs text-gray-600">
                                <div class="p-3 rounded-xl bg-red-50 border border-red-200 flex items-start gap-2">
                                    <span class="w-2.5 h-2.5 rounded-full bg-red-600 mt-1 shrink-0"></span>
                                    <div>
                                        <strong class="text-red-900">Critical Out of Stock:</strong> Current quantity ≤ 0.
                                        Suggested replenishment = configured reorder qty or 2× safety threshold.
                                    </div>
                                </div>
                                <div class="p-3 rounded-xl bg-amber-50 border border-amber-200 flex items-start gap-2">
                                    <span class="w-2.5 h-2.5 rounded-full bg-amber-600 mt-1 shrink-0"></span>
                                    <div>
                                        <strong class="text-amber-900">Urgent Threshold:</strong> Current quantity ≤ 50% of reorder level.
                                        Immediate procurement requisition recommended.
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="pt-4 border-t border-gray-100 flex justify-between items-center text-xs">
                            <span class="text-gray-500 font-medium">Affecting {{ lowStockAnalytics.items.length }} products</span>
                            <SpecularButton variant="brand" size="sm" :radius="8" @click="goToProcurement">
                                <ShoppingCart class="w-3.5 h-3.5 mr-1 inline" />
                                Open Procurement
                            </SpecularButton>
                        </div>
                    </div>
                </div>

                <!-- Prioritization Table -->
                <div class="bg-white border border-gray-200 rounded-xl overflow-hidden">
                    <div class="p-4 border-b border-gray-200 flex flex-col sm:flex-row justify-between items-start sm:items-center gap-3">
                        <div>
                            <h3 class="text-base font-semibold text-gray-900">Restock Prioritization Audit</h3>
                            <p class="text-xs text-gray-500">Ordered by shortage severity (Out of Stock items first)</p>
                        </div>

                        <div class="flex flex-wrap items-center gap-2 w-full sm:w-auto">
                            <div class="relative flex-1 sm:w-44">
                                <Search class="w-3.5 h-3.5 absolute left-3 top-2.5 text-gray-400" />
                                <input
                                    v-model="lowStockSearch"
                                    type="text"
                                    placeholder="Search shortage..."
                                    class="w-full bg-gray-50 border border-gray-200 rounded-xl pl-8 pr-3 py-1.5 text-xs text-gray-900 focus:outline-none"
                                />
                            </div>

                            <select
                                v-model="lowStockCategoryFilter"
                                class="bg-white border border-gray-200 rounded-lg px-2.5 py-1.5 text-xs text-gray-900 font-medium focus:outline-none"
                            >
                                <option value="All">All Categories</option>
                                <option v-for="cat in categories" :key="cat" :value="cat">{{ cat }}</option>
                            </select>
                        </div>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="w-full text-left border-collapse text-xs">
                            <thead>
                                <tr class="bg-gray-50/80 border-b border-gray-200 text-gray-600 font-semibold text-[11px]">
                                    <th class="py-3 px-4">Product Name</th>
                                    <th class="py-3 px-4">SKU</th>
                                    <th class="py-3 px-4 text-center">Available Stock</th>
                                    <th class="py-3 px-4 text-center">Threshold</th>
                                    <th class="py-3 px-4 text-center">Deficit Shortage</th>
                                    <th class="py-3 px-4 text-center font-semibold text-[#4b5320]">Suggested Reorder</th>
                                    <th class="py-3 px-4">Primary Supplier</th>
                                    <th class="py-3 px-4 text-center">Urgency</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                <tr v-if="!filteredLowStock.length">
                                    <td colspan="8" class="py-8 text-center text-gray-400">
                                        No products currently below reorder thresholds.
                                    </td>
                                </tr>
                                <tr
                                    v-for="item in filteredLowStock"
                                    :key="item.id"
                                    :class="[
                                        'transition-colors',
                                        item.stock <= 0 ? 'bg-red-50/30 hover:bg-red-50/60' : 'hover:bg-gray-50/70'
                                    ]"
                                >
                                    <td class="py-3 px-4 font-semibold text-gray-900">{{ item.name }}</td>
                                    <td class="py-3 px-4 font-mono text-gray-600">{{ item.sku }}</td>
                                    <td class="py-3 px-4 text-center font-semibold" :class="item.stock <= 0 ? 'text-red-600' : 'text-amber-600'">
                                        {{ item.stock }}
                                    </td>
                                    <td class="py-3 px-4 text-center text-gray-500">{{ item.reorder_level }}</td>
                                    <td class="py-3 px-4 text-center font-semibold text-red-600">-{{ item.shortage }}</td>
                                    <td class="py-3 px-4 text-center font-semibold text-[#4b5320]">+{{ item.suggested_reorder }} units</td>
                                    <td class="py-3 px-4 text-gray-600">{{ item.supplier_name }}</td>
                                    <td class="py-3 px-4 text-center">
                                        <span
                                            :class="[
                                                'px-2.5 py-0.5 rounded-full text-[10px] font-semibold',
                                                item.urgency === 'Out of Stock' ? 'bg-red-100 text-red-700 border border-red-200' :
                                                item.urgency === 'Critical Low' ? 'bg-rose-100 text-rose-800 border border-rose-200' :
                                                'bg-amber-100 text-amber-800 border border-amber-200'
                                            ]"
                                        >
                                            {{ item.urgency }}
                                        </span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- ═════════════════════════════════════════════════════ -->
            <!-- TAB 4: QUOTATIONS & SALES REPORT                      -->
            <!-- ═════════════════════════════════════════════════════ -->
            <div v-else-if="activeTab === 'sales'" data-report-view class="space-y-6">
                <!-- Visualizations -->
                <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                    <div class="lg:col-span-2 bg-white border border-gray-200 rounded-xl p-6">
                        <div class="flex justify-between items-start mb-4">
                            <div>
                                <h4 class="text-sm font-semibold text-gray-900">Revenue Generation Timeline</h4>
                                <p class="text-xs text-gray-400">Settled invoices excluding pending or rejected quotes</p>
                            </div>
                            <span class="text-xs font-semibold px-2 py-0.5 bg-emerald-50 text-emerald-800 rounded">
                                Completed: {{ formatCurrency(metrics.totalRevenue) }}
                            </span>
                        </div>
                        <div class="h-56">
                            <Line :data="salesRevenueTrendData" :options="moneyLineOptions" />
                        </div>
                    </div>

                    <div class="bg-white border border-gray-200 rounded-xl p-6">
                        <h4 class="text-sm font-semibold text-gray-900 mb-1">Quotation Statuses</h4>
                        <p class="text-xs text-gray-400 mb-3">Conversion funnel breakdown</p>
                        <div class="h-44">
                            <Doughnut :data="quotationStatusDonutData" :options="standardChartOptions" />
                        </div>
                        <div class="text-center text-xs text-gray-500 mt-2 font-medium">
                            Conversion Rate: <strong class="text-emerald-700">{{ quotationsSalesAnalytics.conversion.rate }}%</strong>
                        </div>
                    </div>
                </div>

                <!-- Sub-tab Selector for Sales vs Quotations -->
                <div class="bg-white border border-gray-200 rounded-xl overflow-hidden">
                    <div class="p-4 border-b border-gray-200 flex flex-col sm:flex-row justify-between items-start sm:items-center gap-3">
                        <div class="flex items-center gap-2">
                            <button
                                @click="salesSubTab = 'sales'"
                                :class="[
                                    'px-3 py-1.5 rounded-xl text-xs font-semibold transition-colors',
                                    salesSubTab === 'sales'
                                        ? 'bg-gray-100 text-gray-900'
                                        : 'bg-gray-100 text-gray-600 hover:bg-gray-200'
                                ]"
                            >
                                Sales Invoices ({{ quotationsSalesAnalytics.salesList.length }})
                            </button>
                            <button
                                @click="salesSubTab = 'quotations'"
                                :class="[
                                    'px-3 py-1.5 rounded-xl text-xs font-semibold transition-colors',
                                    salesSubTab === 'quotations'
                                        ? 'bg-gray-100 text-gray-900'
                                        : 'bg-gray-100 text-gray-600 hover:bg-gray-200'
                                ]"
                            >
                                Customer Quotations ({{ quotationsSalesAnalytics.quotationsList.length }})
                            </button>
                        </div>

                        <div class="flex items-center gap-2">
                            <div class="relative w-44">
                                <Search class="w-3.5 h-3.5 absolute left-3 top-2.5 text-gray-400" />
                                <input
                                    v-model="salesSearch"
                                    type="text"
                                    placeholder="Search records..."
                                    class="w-full bg-gray-50 border border-gray-200 rounded-xl pl-8 pr-3 py-1.5 text-xs text-gray-900 focus:outline-none"
                                />
                            </div>
                        </div>
                    </div>

                    <!-- Sales Table -->
                    <div v-if="salesSubTab === 'sales'" class="overflow-x-auto">
                        <table class="w-full text-left border-collapse text-xs">
                            <thead>
                                <tr class="bg-gray-50/80 border-b border-gray-200 text-gray-600 font-semibold text-[11px]">
                                    <th class="py-3 px-4">Invoice #</th>
                                    <th class="py-3 px-4">Customer Name</th>
                                    <th class="py-3 px-4">Sale Date</th>
                                    <th class="py-3 px-4 text-right">Subtotal</th>
                                    <th class="py-3 px-4 text-right">Tax</th>
                                    <th class="py-3 px-4 text-right">Grand Total</th>
                                    <th class="py-3 px-4">Payment</th>
                                    <th class="py-3 px-4 text-center">Status</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                <tr v-if="!filteredSales.length">
                                    <td colspan="8" class="py-8 text-center text-gray-400">No matching sales records found.</td>
                                </tr>
                                <tr v-for="s in filteredSales" :key="s.id" class="hover:bg-gray-50/70 transition-colors">
                                    <td class="py-3 px-4 font-mono font-semibold text-gray-900">{{ s.invoice_number }}</td>
                                    <td class="py-3 px-4 font-medium text-gray-800">{{ s.client_name }}</td>
                                    <td class="py-3 px-4 text-gray-500">{{ s.sale_date }}</td>
                                    <td class="py-3 px-4 text-right text-gray-600">{{ formatCurrency(s.subtotal) }}</td>
                                    <td class="py-3 px-4 text-right text-gray-600">{{ formatCurrency(s.tax_amount) }}</td>
                                    <td class="py-3 px-4 text-right font-semibold text-gray-900">{{ formatCurrency(s.grand_total) }}</td>
                                    <td class="py-3 px-4 text-gray-600">{{ s.payment_method }}</td>
                                    <td class="py-3 px-4 text-center">
                                        <span class="px-2 py-0.5 rounded-full text-[10px] font-semibold bg-emerald-100 text-emerald-800">
                                            {{ s.status }}
                                        </span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- Quotations Table -->
                    <div v-else class="overflow-x-auto">
                        <table class="w-full text-left border-collapse text-xs">
                            <thead>
                                <tr class="bg-gray-50/80 border-b border-gray-200 text-gray-600 font-semibold text-[11px]">
                                    <th class="py-3 px-4">Quote #</th>
                                    <th class="py-3 px-4">Customer Name</th>
                                    <th class="py-3 px-4">Date Issued</th>
                                    <th class="py-3 px-4">Valid Until</th>
                                    <th class="py-3 px-4 text-right">Quoted Amount</th>
                                    <th class="py-3 px-4 text-center">Status</th>
                                    <th class="py-3 px-4 text-center">Converted</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                <tr v-if="!filteredQuotations.length">
                                    <td colspan="7" class="py-8 text-center text-gray-400">No matching quotation records found.</td>
                                </tr>
                                <tr v-for="q in filteredQuotations" :key="q.id" class="hover:bg-gray-50/70 transition-colors">
                                    <td class="py-3 px-4 font-mono font-semibold text-gray-900">{{ q.quotation_number }}</td>
                                    <td class="py-3 px-4 font-medium text-gray-800">{{ q.client_name }}</td>
                                    <td class="py-3 px-4 text-gray-500">{{ q.quotation_date ? q.quotation_date.split('T')[0] : '—' }}</td>
                                    <td class="py-3 px-4 text-gray-500">{{ q.valid_until ? q.valid_until.split('T')[0] : '—' }}</td>
                                    <td class="py-3 px-4 text-right font-semibold text-gray-900">{{ formatCurrency(q.grand_total) }}</td>
                                    <td class="py-3 px-4 text-center">
                                        <span class="px-2 py-0.5 rounded-full text-[10px] font-semibold bg-gray-100 text-gray-700">
                                            {{ q.status }}
                                        </span>
                                    </td>
                                    <td class="py-3 px-4 text-center">
                                        <span v-if="q.is_converted" class="text-emerald-600 font-semibold flex items-center justify-center gap-1">
                                            <CheckCircle2 class="w-3.5 h-3.5" /> Converted
                                        </span>
                                        <span v-else class="text-gray-400">—</span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- ═════════════════════════════════════════════════════ -->
            <!-- TAB 5: DEMAND FORECASTING                             -->
            <!-- ═════════════════════════════════════════════════════ -->
            <div v-else-if="activeTab === 'forecast'" data-report-view class="space-y-6">
                <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                    <div class="lg:col-span-2 bg-white border border-gray-200 rounded-xl p-6">
                        <div class="flex justify-between items-start mb-4">
                            <div>
                                <h4 class="text-sm font-semibold text-gray-900">12-Month Demand vs SMA Predictive Model</h4>
                                <p class="text-xs text-gray-400">Calculated from warehouse outgoing stock movements</p>
                            </div>
                        </div>
                        <div class="h-64">
                            <Line :data="forecastChartData" :options="standardChartOptions" />
                        </div>
                    </div>

                    <div class="bg-white border border-gray-200 rounded-xl p-6 flex flex-col justify-between">
                        <div>
                            <h4 class="text-sm font-semibold text-gray-900 mb-2">SMA Calculation Transparent Breakdown</h4>
                            <p class="text-xs text-gray-500 mb-4 leading-relaxed">
                                {{ forecastAnalytics.window }}-month Simple Moving Average: the plain average of the {{ forecastAnalytics.window }} previous months.
                                Monthly demand = the larger of outgoing stock movements and units on confirmed, processing or completed Sales Orders.
                                Months with no records count as 0; nothing is estimated.
                            </p>
                            <div class="p-3 bg-gray-50 border border-gray-100 rounded-xl font-mono text-xs text-gray-700 space-y-1">
                                <div>SMA = (Sum of sales during selected period) / (Number of periods)</div>
                                <div>SMA-3 = (Sales Month 1 + Sales Month 2 + Sales Month 3) / 3</div>
                                <div class="text-[#4b5320] font-semibold">Forecast for next month = SMA</div>
                            </div>
                            <p v-if="!forecastAnalytics.hasData" class="mt-3 rounded-lg border border-amber-200 bg-amber-50 px-2.5 py-1.5 text-[11px] text-amber-800">
                                Insufficient data: no sales or outgoing stock records for {{ forecastAnalytics.year }}, so no forecast is shown.
                            </p>
                            <p v-else class="text-[11px] text-gray-400 mt-3">
                                Based on {{ forecastAnalytics.sourceRecordCount }} recorded transactions. Per-product SMA inputs are in the AHP Product Prioritization tab (View Calculation → Demand Forecasting).
                            </p>
                        </div>

                        <SpecularButton
                            variant="brand"
                            size="sm"
                            :radius="8"
                            @click="router.visit(`/${teamSlug}/forecast`)"
                            class="w-full mt-4"
                        >
                            Open Interactive Forecast Module →
                        </SpecularButton>
                    </div>
                </div>

                <!-- Monthly Forecast Table -->
                <div class="bg-white border border-gray-200 rounded-xl overflow-hidden">
                    <div class="p-4 border-b border-gray-200">
                        <h3 class="text-base font-semibold text-gray-900">Monthly Forecast Table (Year {{ forecastAnalytics.year }})</h3>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left border-collapse text-xs">
                            <thead>
                                <tr class="bg-gray-50/80 border-b border-gray-200 text-gray-600 font-semibold text-[11px]">
                                    <th class="py-3 px-4">Period</th>
                                    <th class="py-3 px-4 text-center">Historical Demand (Units)</th>
                                    <th class="py-3 px-4 text-center">SMA-3 Forecast (Units)</th>
                                    <th class="py-3 px-4 text-right">Step-by-step SMA</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                <tr v-for="(mLabel, idx) in forecastAnalytics.labels" :key="mLabel" class="hover:bg-gray-50/70">
                                    <td class="py-3 px-4 font-semibold text-gray-900">{{ mLabel }} {{ forecastAnalytics.year }}</td>
                                    <td class="py-3 px-4 text-center font-medium">{{ forecastAnalytics.historical[idx] }} units</td>
                                    <td class="py-3 px-4 text-center font-semibold text-[#4b5320]">
                                        {{ forecastAnalytics.forecast[idx] !== null ? `${forecastAnalytics.forecast[idx]} units` : '— (N/A)' }}
                                    </td>
                                    <td class="py-3 px-4 text-right text-gray-500">
                                        <template v-if="stepByMonth.get(`${mLabel} ${forecastAnalytics.year}`)">
                                            ({{ stepByMonth.get(`${mLabel} ${forecastAnalytics.year}`)!.inputs.map((i) => i.units).join(' + ') }}) / {{ forecastAnalytics.window }}
                                            = {{ stepByMonth.get(`${mLabel} ${forecastAnalytics.year}`)!.sum }} / {{ forecastAnalytics.window }}
                                        </template>
                                        <template v-else>Needs {{ forecastAnalytics.window }} prior months</template>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- ═════════════════════════════════════════════════════ -->
            <!-- TAB 6: PURCHASING & PROCUREMENT                       -->
            <!-- ═════════════════════════════════════════════════════ -->
            <div v-else-if="activeTab === 'procurement'" data-report-view class="space-y-6">
                <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                    <div class="bg-white border border-gray-200 rounded-xl p-6">
                        <h4 class="text-sm font-semibold text-gray-900 mb-1">Purchase Request Statuses</h4>
                        <p class="text-xs text-gray-400 mb-3">Requisition fulfillment distribution</p>
                        <div class="h-48">
                            <Doughnut :data="procurementDonutData" :options="standardChartOptions" />
                        </div>
                    </div>

                    <div class="lg:col-span-2 bg-white border border-gray-200 rounded-xl p-6 flex flex-col justify-between">
                        <div>
                            <h4 class="text-sm font-semibold text-gray-900 mb-1">Procurement Operational Summary</h4>
                            <p class="text-xs text-gray-400 mb-4">Total estimated acquisition commitments</p>
                            <div class="grid grid-cols-3 gap-4">
                                <div class="p-3 bg-gray-50 rounded-xl border border-gray-100">
                                    <span class="text-xs text-gray-500 font-semibold block">Total Requisitions</span>
                                    <span class="text-xl font-semibold text-gray-900">{{ metrics.totalPurchasesCount }}</span>
                                </div>
                                <div class="p-3 bg-blue-50 rounded-xl border border-blue-100">
                                    <span class="text-xs text-blue-800 font-semibold block">Pending / Approved</span>
                                    <span class="text-xl font-semibold text-blue-900">{{ (procurementAnalytics.statusDistribution['Pending Approval'] || 0) + (procurementAnalytics.statusDistribution['Approved'] || 0) }}</span>
                                </div>
                                <div class="p-3 bg-emerald-50 rounded-xl border border-emerald-100">
                                    <span class="text-xs text-emerald-800 font-semibold block">Converted to PO</span>
                                    <span class="text-xl font-semibold text-emerald-900">{{ procurementAnalytics.statusDistribution['Converted to Purchase Order'] || 0 }}</span>
                                </div>
                            </div>
                        </div>

                        <div class="pt-4 border-t border-gray-100 flex justify-between items-center text-xs">
                            <span class="text-gray-500">Total Committed Spend: <strong class="text-gray-900 font-semibold">{{ formatCurrency(procurementAnalytics.totalEstimatedCost) }}</strong></span>
                        </div>
                    </div>
                </div>

                <!-- Requisitions Table -->
                <div class="bg-white border border-gray-200 rounded-xl overflow-hidden">
                    <div class="p-4 border-b border-gray-200 flex justify-between items-center">
                        <h3 class="text-base font-semibold text-gray-900">Purchase Orders &amp; Requests Ledger</h3>
                        <SpecularButton variant="brand" size="sm" :radius="8" @click="goToProcurement">
                            <ShoppingCart class="w-3.5 h-3.5 mr-1 inline" />
                            Manage Procurement
                        </SpecularButton>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left border-collapse text-xs">
                            <thead>
                                <tr class="bg-gray-50/80 border-b border-gray-200 text-gray-600 font-semibold text-[11px]">
                                    <th class="py-3 px-4">Request #</th>
                                    <th class="py-3 px-4">Item Name (SKU)</th>
                                    <th class="py-3 px-4">Supplier</th>
                                    <th class="py-3 px-4">Requested By</th>
                                    <th class="py-3 px-4 text-center">Qty</th>
                                    <th class="py-3 px-4 text-right">Est. Cost</th>
                                    <th class="py-3 px-4 text-center">Status</th>
                                    <th class="py-3 px-4">Date</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                <tr v-if="!procurementAnalytics.requestsList.length">
                                    <td colspan="8" class="py-8 text-center text-gray-400">No purchase requests found.</td>
                                </tr>
                                <tr v-for="r in procurementAnalytics.requestsList" :key="r.id" class="hover:bg-gray-50/70">
                                    <td class="py-3 px-4 font-mono font-semibold text-gray-900">{{ r.request_number }}</td>
                                    <td class="py-3 px-4">
                                        <div class="font-semibold text-gray-900">{{ r.product_name }}</div>
                                        <div class="text-[10px] text-gray-400 font-mono">{{ r.sku }}</div>
                                    </td>
                                    <td class="py-3 px-4 text-gray-700">{{ r.supplier_name }}</td>
                                    <td class="py-3 px-4 text-gray-600">{{ r.requested_by }}</td>
                                    <td class="py-3 px-4 text-center font-semibold text-gray-900">{{ r.quantity }}</td>
                                    <td class="py-3 px-4 text-right font-semibold text-gray-900">{{ formatCurrency(r.estimated_cost) }}</td>
                                    <td class="py-3 px-4 text-center">
                                        <span
                                            :class="[
                                                'px-2 py-0.5 rounded-full text-[10px] font-semibold',
                                                ['Converted to Purchase Order', 'Delivered'].includes(r.status) ? 'bg-emerald-100 text-emerald-800' :
                                                ['Approved', 'Ordered'].includes(r.status) ? 'bg-blue-100 text-blue-800' :
                                                ['Pending Approval', 'Requested'].includes(r.status) ? 'bg-amber-100 text-amber-800' :
                                                ['Rejected', 'Cancelled'].includes(r.status) ? 'bg-rose-100 text-rose-800' : 'bg-gray-100 text-gray-700'
                                            ]"
                                        >
                                            {{ r.status }}
                                        </span>
                                    </td>
                                    <td class="py-3 px-4 text-gray-500">{{ r.date }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- ═════════════════════════════════════════════════════ -->
            <!-- TAB 7: AHP PRODUCT PRIORITIZATION                     -->
            <!-- ═════════════════════════════════════════════════════ -->
            <div v-else-if="activeTab === 'priority'" data-report-view class="space-y-6">
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                    <div class="bg-white border border-gray-200 rounded-xl p-6">
                        <h4 class="text-sm font-semibold text-gray-900 mb-2">AHP Methodology and Criteria Weights</h4>
                        <p class="text-xs text-gray-500 mb-3 leading-relaxed">
                            Each criterion is normalized to a 0–100 score, multiplied by its fixed weight, and the weighted results are added.
                            A higher score means a higher replenishment priority. Weights are the approved fixed configuration and cannot be changed in the UI.
                        </p>
                        <table class="w-full text-left text-xs">
                            <thead><tr class="text-gray-500"><th class="py-1">Criterion</th><th class="text-right">Weight</th></tr></thead>
                            <tbody>
                                <tr class="border-t border-gray-100"><td class="py-1.5">Demand Forecasting</td><td class="text-right font-mono">{{ pctW(productPriority.weights.demand) }}</td></tr>
                                <tr class="border-t border-gray-100"><td class="py-1.5">Current Stock</td><td class="text-right font-mono">{{ pctW(productPriority.weights.stock) }}</td></tr>
                                <tr class="border-t border-gray-100"><td class="py-1.5">Product Movement</td><td class="text-right font-mono">{{ pctW(productPriority.weights.movement) }}</td></tr>
                                <tr class="border-t border-gray-100"><td class="py-1.5">Supplier Availability</td><td class="text-right font-mono">{{ pctW(productPriority.weights.supplier_availability) }}</td></tr>
                            </tbody>
                        </table>
                        <p class="mt-3 p-2.5 rounded-lg bg-gray-50 border border-gray-100 font-mono text-[11px] text-gray-700">
                            AHP Score = (Demand × {{ productPriority.weights.demand }}) + (Current Stock × {{ productPriority.weights.stock }}) + (Product Movement × {{ productPriority.weights.movement }}) + (Supplier Availability × {{ productPriority.weights.supplier_availability }}) · 1–5 Scale = 1 + 4 × (Score / 100)
                        </p>
                        <p class="mt-2 text-[11px] text-gray-500">
                            <span v-for="b in classBands(productPriority.classes)" :key="b.min" class="mr-2">{{ b.range }}: {{ b.label }}</span>
                        </p>
                    </div>

                    <div class="bg-white border border-gray-200 rounded-xl p-6">
                        <h4 class="text-sm font-semibold text-gray-900 mb-2">Criteria Scoring (normalized 0–100)</h4>
                        <div class="space-y-2 text-xs">
                            <div class="p-2.5 rounded-lg bg-gray-50 border border-gray-100">
                                <p class="font-semibold text-gray-800">Demand Forecasting</p>
                                <p class="font-mono text-[11px] text-gray-600">(SMA-{{ productPriority.smaWindow }} forecast / Highest forecast) × 100</p>
                                <p class="text-[11px] text-gray-500">Higher forecasted demand → higher priority.</p>
                            </div>
                            <div class="p-2.5 rounded-lg bg-gray-50 border border-gray-100">
                                <p class="font-semibold text-gray-800">Current Stock</p>
                                <p class="font-mono text-[11px] text-gray-600">(1 − Available stock / Highest available stock) × 100</p>
                                <p class="text-[11px] text-gray-500">Available = on hand − reserved. Lower stock → higher priority.</p>
                            </div>
                            <div class="p-2.5 rounded-lg bg-gray-50 border border-gray-100">
                                <p class="font-semibold text-gray-800">Product Movement</p>
                                <p class="font-mono text-[11px] text-gray-600">(Units moved / Highest units moved) × 100</p>
                                <p class="text-[11px] text-gray-500">Last {{ productPriority.movementMonths }} complete months. Faster-moving → higher priority.</p>
                            </div>
                            <div class="p-2.5 rounded-lg bg-gray-50 border border-gray-100">
                                <p class="font-semibold text-gray-800">Supplier Availability</p>
                                <p class="font-mono text-[11px] text-gray-600">(1 − Available suppliers / Verified suppliers) × 100</p>
                                <p class="text-[11px] text-gray-500">Fewer suppliers able to supply → harder sourcing → higher priority.</p>
                            </div>
                            <div class="p-2.5 rounded-lg bg-gray-50 border border-gray-100">
                                <p class="font-semibold text-gray-800">Reorder recommendation</p>
                                <p class="text-[11px] text-gray-500">Reorder when available stock ≤ reorder level or &lt; SMA forecast. Suggested quantity = max(reorder quantity, forecast − available).</p>
                            </div>
                            <p class="text-[11px] text-amber-700">A criterion without data is "insufficient data": it is not counted as 0, its weight is not moved, and the product gets no final score.</p>
                        </div>
                    </div>
                </div>

                <section class="bg-white border border-gray-200 rounded-xl p-4 flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between print:hidden" aria-labelledby="reorder-heading">
                    <div class="flex items-center gap-2">
                        <label for="ahp-period" class="text-xs font-medium text-gray-600">Forecasting period</label>
                        <select id="ahp-period" v-model.number="priorityMonths" class="field-control w-auto" @change="changePriorityPeriod">
                            <option v-for="(label, months) in productPriority.forecastPeriods" :key="months" :value="Number(months)">{{ label }}</option>
                        </select>
                    </div>
                    <div class="lg:flex-1 lg:px-4">
                        <h3 id="reorder-heading" class="text-sm font-semibold text-gray-900">Reorder recommendations ({{ reorderRecommendations.length }})</h3>
                        <p v-if="productPriority.canRequestPurchase" class="text-xs text-gray-500">Tick products below, then create purchase requests for admin approval. Nothing is ordered and stock does not change until goods are received and inspected.</p>
                        <p v-else class="text-xs text-gray-500">Purchase requests are created by administrators, procurement or inventory staff.</p>
                    </div>
                    <div v-if="productPriority.canRequestPurchase" class="flex flex-wrap gap-2">
                        <button type="button" class="secondary-button" :disabled="!reorderRecommendations.length" @click="selectedForRequest = reorderRecommendations.map((p) => p.product.id)">Select all recommended</button>
                        <button type="button" class="brand-button" :disabled="!selectedForRequest.length" @click="showPrCreator = true">Create purchase request ({{ selectedForRequest.length }})</button>
                    </div>
                </section>

                <div class="bg-white border border-gray-200 rounded-xl overflow-hidden">
                    <div class="p-4 border-b border-gray-200">
                        <h3 class="text-base font-semibold text-gray-900">Weighted Contributions per Product</h3>
                        <p class="text-xs text-gray-500">Score (0–100) → weighted contribution for each criterion. Forecast for the next {{ priorityPeriodLabel }}.</p>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left border-collapse text-xs">
                            <thead>
                                <tr class="bg-gray-50/80 border-b border-gray-200 text-gray-600 font-semibold text-[11px]">
                                    <th v-if="productPriority.canRequestPurchase" class="py-3 pl-4 w-8 print:hidden"><span class="sr-only">Select</span></th>
                                    <th class="py-3 px-4 text-center">Rank</th>
                                    <th class="py-3 px-4">Product</th>
                                    <th class="py-3 px-4 text-right">Forecast, next {{ priorityPeriodLabel }}</th>
                                    <th class="py-3 px-4 text-center">Demand</th>
                                    <th class="py-3 px-4 text-center">Current Stock</th>
                                    <th class="py-3 px-4 text-center">Movement</th>
                                    <th class="py-3 px-4 text-center">Supplier Avail.</th>
                                    <th class="py-3 px-4 text-center text-[#4b5320]">AHP Score</th>
                                    <th class="py-3 px-4">Priority</th>
                                    <th class="py-3 px-4">Reorder</th>
                                    <th class="py-3 px-4 text-center">Calculation</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                <tr v-if="!productPriority.items.length">
                                    <td :colspan="productPriority.canRequestPurchase ? 12 : 11" class="py-8 text-center text-gray-400">No active products.</td>
                                </tr>
                                <tr v-for="(p, i) in productPriority.items" :key="p.product.id" class="hover:bg-gray-50/70">
                                    <td v-if="productPriority.canRequestPurchase" class="py-3 pl-4 print:hidden"><input v-model="selectedForRequest" type="checkbox" :value="p.product.id" :aria-label="`Select ${p.product.name}`" class="rounded border-gray-300" /></td>
                                    <td class="py-3 px-4 text-center font-semibold text-gray-700">{{ p.complete ? `#${i + 1}` : '—' }}</td>
                                    <td class="py-3 px-4">
                                        <div class="font-semibold text-gray-900">{{ p.product.name }}</div>
                                        <div class="font-mono text-[11px] text-gray-500">{{ p.product.sku }}</div>
                                    </td>
                                    <td class="py-3 px-4 text-right font-mono">
                                        <div>{{ p.forecast?.period_total ?? '—' }}</div>
                                        <div v-if="p.forecast?.monthly != null" class="text-[10px] text-gray-500">{{ p.forecast.monthly }}/month</div>
                                    </td>
                                    <td v-for="k in priorityKeys" :key="k" class="py-3 px-4 text-center font-mono">
                                        <template v-if="priorityContribution(p, k)?.available">{{ priorityContribution(p, k)!.score!.toFixed(2) }} → {{ priorityContribution(p, k)!.weighted!.toFixed(2) }}</template>
                                        <span v-else class="font-sans text-amber-700">Insufficient data</span>
                                    </td>
                                    <td class="py-3 px-4 text-center font-semibold text-[#4b5320]">
                                        <div>{{ p.complete ? p.score!.toFixed(2) : 'Incomplete' }}</div>
                                        <div v-if="p.scale" class="font-mono text-[10px] font-normal text-gray-500">{{ p.scale.score.toFixed(2) }} / 5.00</div>
                                    </td>
                                    <td class="py-3 px-4 text-gray-700">
                                        {{ p.classification ?? 'Not classified' }}<template v-if="p.scale?.action"> — {{ p.scale.action }}</template>
                                    </td>
                                    <td class="py-3 px-4" :class="p.reorder.needed ? 'text-amber-700 font-medium' : 'text-gray-500'">{{ p.reorder.needed ? `Reorder ${p.reorder.suggested_quantity} ${p.product.unit ?? ''}`.trim() : 'No' }}</td>
                                    <td class="py-3 px-4 text-center"><button type="button" class="text-xs font-medium text-[#4b5320] hover:underline" @click="selectedProductPriority = p">View Calculation</button></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- ═════════════════════════════════════════════════════ -->
            <!-- TAB 8: SUPPLIER EVALUATION                    -->
            <!-- ═════════════════════════════════════════════════════ -->
            <div v-else-if="activeTab === 'ahp'" data-report-view class="space-y-6">
                <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                    <div class="lg:col-span-2 bg-white border border-gray-200 rounded-xl p-6">
                        <div class="flex justify-between items-start mb-4">
                            <div>
                                <h4 class="text-sm font-semibold text-gray-900">Supplier Evaluation Ranking</h4>
                                <p class="text-xs text-gray-400">Weighted scores from delivery, quotation and inspection records</p>
                            </div>
                        </div>
                        <div class="h-60">
                            <Bar :data="ahpBarData" :options="horizontalBarOptions" />
                        </div>
                    </div>

                    <div class="bg-white border border-gray-200 rounded-xl p-6 flex flex-col justify-between">
                        <div>
                            <h4 class="text-sm font-semibold text-gray-900 mb-2">Supplier Evaluation Methodology</h4>
                            <p class="text-xs text-gray-500 mb-3 leading-relaxed">
                                Calculated only from recorded transactions in the last {{ ahpAnalytics.periodMonths }} months. Nobody can enter or edit a score.
                                Weights are the approved fixed configuration (config/evaluation.php).
                            </p>
                            <div class="space-y-2 text-xs">
                                <div class="p-2.5 rounded-lg bg-gray-50 border border-gray-100">
                                    <div class="flex justify-between"><span class="font-semibold text-gray-800">1. Inbound Delivery Performance</span><span class="font-mono">{{ pctW(ahpAnalytics.weights.delivery) }}</span></div>
                                    <p class="mt-1 font-mono text-[11px] text-gray-600">(On-time rate × {{ ahpAnalytics.deliverySplit.on_time }}) + (Quantity fulfillment × {{ ahpAnalytics.deliverySplit.fulfillment }})</p>
                                    <p class="text-[11px] text-gray-500">Deliveries vs. the purchase order's promised date; accepted vs. ordered quantity.</p>
                                </div>
                                <div class="p-2.5 rounded-lg bg-gray-50 border border-gray-100">
                                    <div class="flex justify-between"><span class="font-semibold text-gray-800">2. Price Competitiveness</span><span class="font-mono">{{ pctW(ahpAnalytics.weights.price) }}</span></div>
                                    <p class="mt-1 font-mono text-[11px] text-gray-600">Average of (Lowest purchase unit cost / Supplier's purchase unit cost) × 100</p>
                                    <p class="text-[11px] text-gray-500">Purchase order unit costs of accepted deliveries, for the same product and unit.</p>
                                </div>
                                <div class="p-2.5 rounded-lg bg-gray-50 border border-gray-100">
                                    <div class="flex justify-between"><span class="font-semibold text-gray-800">3. Product Quality</span><span class="font-mono">{{ pctW(ahpAnalytics.weights.quality) }}</span></div>
                                    <p class="mt-1 font-mono text-[11px] text-gray-600">(Accepted units / Inspected units) × 100</p>
                                    <p class="text-[11px] text-gray-500">Goods-receipt inspection records.</p>
                                </div>
                                <p class="p-2.5 rounded-lg bg-gray-50 border border-gray-100 font-mono text-[11px] text-gray-700">
                                    Score = (Delivery × {{ ahpAnalytics.weights.delivery }}) + (Price × {{ ahpAnalytics.weights.price }}) + (Quality × {{ ahpAnalytics.weights.quality }})
                                </p>
                                <p class="text-[11px] text-gray-500">
                                    <span v-for="b in classBands(ahpAnalytics.classes)" :key="b.min" class="mr-2">{{ b.label }} {{ b.range }}</span>
                                </p>
                                <p class="text-[11px] text-amber-700">A criterion without records is "insufficient data": it is not counted as 0, and the supplier gets no final score or rank.</p>
                            </div>
                        </div>

                        <SpecularButton
                            variant="brand"
                            size="sm"
                            :radius="8"
                            @click="router.visit(`/${teamSlug}/suppliers`)"
                            class="w-full mt-4"
                        >
                            Open Supplier Module →
                        </SpecularButton>
                    </div>
                </div>

                <!-- Supplier Ranking Table -->
                <div class="bg-white border border-gray-200 rounded-xl overflow-hidden">
                    <div class="p-4 border-b border-gray-200">
                        <h3 class="text-base font-semibold text-gray-900">Ranked Supplier Directory</h3>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left border-collapse text-xs">
                            <thead>
                                <tr class="bg-gray-50/80 border-b border-gray-200 text-gray-600 font-semibold text-[11px]">
                                    <th class="py-3 px-4 text-center">Rank</th>
                                    <th class="py-3 px-4">Supplier Name</th>
                                    <th class="py-3 px-4">Contact Person</th>
                                    <th class="py-3 px-4">Phone / Email</th>
                                    <th class="py-3 px-4 text-center font-semibold text-[#4b5320]">Evaluation Score</th>
                                    <th class="py-3 px-4 text-center">Delivery ({{ pctW(ahpAnalytics.weights.delivery) }})</th>
                                    <th class="py-3 px-4 text-center">Price ({{ pctW(ahpAnalytics.weights.price) }})</th>
                                    <th class="py-3 px-4 text-center">Quality ({{ pctW(ahpAnalytics.weights.quality) }})</th>
                                    <th class="py-3 px-4 text-center">Classification</th>
                                    <th class="py-3 px-4 text-center">Status</th>
                                    <th class="py-3 px-4 text-center">Calculation</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                <tr v-if="!ahpAnalytics.suppliersList.length">
                                    <td colspan="11" class="py-8 text-center text-gray-400">No suppliers registered.</td>
                                </tr>
                                <tr v-for="s in ahpAnalytics.suppliersList" :key="s.id" class="hover:bg-gray-50/70">
                                    <td class="py-3 px-4 text-center font-semibold text-gray-700">{{ s.rank ? `#${s.rank}` : '—' }}</td>
                                    <td class="py-3 px-4 font-semibold text-gray-900">{{ s.name }}</td>
                                    <td class="py-3 px-4 text-gray-700">{{ s.contact_person }}</td>
                                    <td class="py-3 px-4 text-gray-500 font-mono">{{ s.phone }}</td>
                                    <td class="py-3 px-4 text-center font-semibold text-[#4b5320] text-sm">
                                        {{ s.complete ? Number(s.ahp_score).toFixed(2) : 'Insufficient data' }}
                                    </td>
                                    <td v-for="k in (['delivery', 'price', 'quality'] as const)" :key="k" class="py-3 px-4 text-center text-gray-600">{{ s.metrics?.[k] != null ? Number(s.metrics[k]).toFixed(2) : '—' }}</td>
                                    <td class="py-3 px-4 text-center text-gray-600">{{ s.classification ?? 'Not classified' }}</td>
                                    <td class="py-3 px-4 text-center">
                                        <span class="px-2 py-0.5 rounded-full text-[10px] font-semibold bg-emerald-100 text-emerald-800">
                                            {{ s.status }}
                                        </span>
                                    </td>
                                    <td class="py-3 px-4 text-center"><button type="button" class="text-xs font-medium text-[#4b5320] hover:underline" @click="selectedSupplierEval = s">View Calculation</button></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- ═════════════════════════════════════════════════════ -->
            <!-- TAB 9: RECENT ACTIVITY FEED (SYSTEM AUDIT TRAIL)      -->
            <!-- ═════════════════════════════════════════════════════ -->
            <div v-else-if="activeTab === 'activity'" data-report-view class="space-y-6">
                <!-- Module Activity Summary Cards -->
                <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3">
                    <button
                        v-for="mod in activityModulesList"
                        :key="mod"
                        type="button"
                        @click="activityModuleFilter = activityModuleFilter === mod ? 'All' : mod"
                        :class="[
                            'text-left rounded-xl border px-3.5 py-3 transition-colors cursor-pointer',
                            activityModuleFilter === mod
                                ? 'bg-[#4b5320]/10 border-[#4b5320] ring-1 ring-[#4b5320]'
                                : 'bg-white border-gray-200 hover:bg-gray-50'
                        ]"
                    >
                        <div class="text-[11px] font-medium text-gray-500 truncate">{{ mod }}</div>
                        <div class="mt-1 text-xl font-semibold text-gray-900">
                            {{ activityFeed?.byModule?.[mod] ?? 0 }}
                        </div>
                        <div class="mt-0.5 text-[10px] text-gray-400">recorded events</div>
                    </button>
                </div>

                <!-- Activity Feed Table & Filter Controls -->
                <div class="bg-white border border-gray-200 rounded-xl overflow-hidden">
                    <div class="p-4 border-b border-gray-200 flex flex-col lg:flex-row justify-between items-start lg:items-center gap-3">
                        <div>
                            <h3 class="text-base font-semibold text-gray-900">Recent Activity Feed</h3>
                            <p class="text-xs text-gray-500">
                                Chronological operational and administrative activities sourced directly from the System Audit Trail.
                            </p>
                        </div>

                        <!-- Filter Controls: Search + Module Filter + Date Range + CSV Export -->
                        <div class="flex flex-wrap items-center gap-2 w-full lg:w-auto">
                            <!-- Search -->
                            <div class="relative flex-1 sm:w-52">
                                <Search class="w-3.5 h-3.5 absolute left-3 top-2.5 text-gray-400" />
                                <input
                                    v-model="activitySearch"
                                    type="text"
                                    placeholder="Search user, action, reference..."
                                    class="w-full bg-gray-50 border border-gray-200 rounded-xl pl-8 pr-3 py-1.5 text-xs text-gray-900 focus:outline-none focus:border-gray-400"
                                />
                            </div>

                            <!-- Module Filter -->
                            <select
                                v-model="activityModuleFilter"
                                aria-label="Filter by module"
                                class="bg-white border border-gray-200 rounded-lg px-2.5 py-1.5 text-xs text-gray-900 font-medium focus:outline-none focus:border-gray-400"
                            >
                                <option value="All">All Modules</option>
                                <option v-for="mod in activityModulesList" :key="mod" :value="mod">
                                    {{ mod }}
                                </option>
                            </select>

                            <!-- Date Range From / To -->
                            <input
                                v-model="activityFromDate"
                                type="date"
                                title="From Date"
                                aria-label="Activity From Date"
                                class="bg-white border border-gray-200 rounded-lg px-2.5 py-1 text-xs text-gray-900 focus:outline-none focus:border-gray-400"
                            />
                            <span class="text-xs text-gray-400">to</span>
                            <input
                                v-model="activityToDate"
                                type="date"
                                title="To Date"
                                aria-label="Activity To Date"
                                class="bg-white border border-gray-200 rounded-lg px-2.5 py-1 text-xs text-gray-900 focus:outline-none focus:border-gray-400"
                            />

                            <button
                                v-if="activitySearch || activityModuleFilter !== 'All' || activityFromDate || activityToDate"
                                type="button"
                                @click="resetActivityFilters"
                                class="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-lg text-xs font-medium text-gray-600 hover:text-gray-900 hover:bg-gray-100 transition-colors"
                            >
                                <RotateCcw class="w-3.5 h-3.5" />
                                Reset
                            </button>
                        </div>
                    </div>

                    <!-- Activity Feed Table -->
                    <div class="overflow-x-auto">
                        <table class="w-full text-left border-collapse text-xs">
                            <thead>
                                <tr class="bg-gray-50/80 border-b border-gray-200 text-gray-600 font-semibold text-[11px]">
                                    <th class="py-3 px-4">Timestamp</th>
                                    <th class="py-3 px-4">User</th>
                                    <th class="py-3 px-4">Action Performed</th>
                                    <th class="py-3 px-4">Affected Module</th>
                                    <th class="py-3 px-4">Record Reference</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                <tr v-if="!paginatedActivities.length">
                                    <td colspan="5" class="py-10 text-center text-gray-400">
                                        No recorded activities match the selected filters.
                                    </td>
                                </tr>
                                <tr
                                    v-for="item in paginatedActivities"
                                    :key="item.id"
                                    class="hover:bg-gray-50/70 transition-colors"
                                >
                                    <td class="py-3 px-4 whitespace-nowrap">
                                        <div class="font-mono text-gray-800 font-medium">{{ item.timestamp }}</div>
                                        <div v-if="item.relative_time" class="text-[10px] text-gray-400">{{ item.relative_time }}</div>
                                    </td>
                                    <td class="py-3 px-4 whitespace-nowrap">
                                        <div class="font-semibold text-gray-900">{{ item.user }}</div>
                                        <div class="text-[11px] text-gray-500">{{ item.user_email }}</div>
                                    </td>
                                    <td class="py-3 px-4">
                                        <div class="font-semibold text-gray-900">{{ item.action }}</div>
                                        <div class="text-[11px] text-gray-600 leading-relaxed">{{ item.description }}</div>
                                    </td>
                                    <td class="py-3 px-4 whitespace-nowrap">
                                        <span
                                            :class="[
                                                'px-2.5 py-0.5 rounded-full text-[11px] font-semibold border',
                                                moduleBadgeClasses(item.module)
                                            ]"
                                        >
                                            {{ item.module }}
                                        </span>
                                    </td>
                                    <td class="py-3 px-4 whitespace-nowrap">
                                        <span class="px-2 py-0.5 rounded bg-gray-100 border border-gray-200 font-mono text-[11px] font-semibold text-gray-800">
                                            {{ item.reference }}
                                        </span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- Pagination -->
                    <div class="p-3 border-t border-gray-100 flex items-center justify-between text-xs text-gray-500">
                        <span>Showing {{ paginatedActivities.length }} of {{ filteredActivities.length }} activities</span>
                        <div class="flex gap-1" v-if="totalActivityPages > 1">
                            <button
                                v-for="p in totalActivityPages"
                                :key="p"
                                type="button"
                                @click="activityPage = p"
                                :class="[
                                    'w-7 h-7 rounded-lg font-semibold flex items-center justify-center transition-colors',
                                    activityPage === p ? 'bg-[#4b5320] text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200'
                                ]"
                            >
                                {{ p }}
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Purchase requests from the AHP reorder recommendations (same dialog as Procurement). -->
        <PurchaseRequestCreator v-if="productPriority.canRequestPurchase" :open="showPrCreator" :candidates="prCandidates" :forecast-months="priorityMonths" @close="showPrCreator = false" @submitted="onRequestsSubmitted" />

        <!-- Calculation breakdown modals (same components as Suppliers) -->
        <div v-if="selectedProductPriority" class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4 print:hidden" @click.self="selectedProductPriority = null">
            <div class="flex max-h-[92vh] w-full max-w-2xl flex-col overflow-hidden rounded-xl border border-gray-200 bg-white" role="dialog" aria-modal="true" aria-labelledby="rp-priority-title">
                <div class="flex items-start justify-between gap-3 border-b border-gray-100 px-6 py-4">
                    <div>
                        <h3 id="rp-priority-title" class="text-base font-semibold text-gray-900">{{ selectedProductPriority.product.name }}</h3>
                        <p class="font-mono text-xs text-gray-500">{{ selectedProductPriority.product.sku }}</p>
                    </div>
                    <div class="text-right">
                        <div class="text-xl font-semibold text-gray-900">{{ selectedProductPriority.complete ? `${selectedProductPriority.score!.toFixed(2)} / 100` : 'Incomplete' }}</div>
                        <div class="text-xs text-gray-500">
                            <template v-if="selectedProductPriority.scale">{{ selectedProductPriority.scale.score.toFixed(2) }} / 5.00 · </template>{{ selectedProductPriority.classification ?? 'Not classified' }}<template v-if="selectedProductPriority.scale?.action"> — {{ selectedProductPriority.scale.action }}</template>
                        </div>
                    </div>
                </div>
                <div class="overflow-y-auto px-6 py-4"><ProductPriorityBreakdown :priority="selectedProductPriority" /></div>
                <div class="flex justify-end border-t border-gray-100 bg-gray-50/50 px-6 py-3">
                    <button type="button" class="secondary-button" @click="selectedProductPriority = null">Close</button>
                </div>
            </div>
        </div>

        <div v-if="selectedSupplierEval" class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4 print:hidden" @click.self="selectedSupplierEval = null">
            <div class="flex max-h-[92vh] w-full max-w-3xl flex-col overflow-hidden rounded-xl border border-gray-200 bg-white" role="dialog" aria-modal="true" aria-labelledby="rp-supplier-title">
                <div class="flex items-start justify-between gap-3 border-b border-gray-100 px-6 py-4">
                    <h3 id="rp-supplier-title" class="text-base font-semibold text-gray-900">{{ selectedSupplierEval.name }}</h3>
                    <div class="text-right">
                        <div class="text-xl font-semibold text-gray-900">{{ selectedSupplierEval.complete ? `${Number(selectedSupplierEval.ahp_score).toFixed(2)} / 100` : 'Insufficient data' }}</div>
                        <div class="text-xs text-gray-500">{{ selectedSupplierEval.rank ? `Rank #${selectedSupplierEval.rank} · ` : '' }}{{ selectedSupplierEval.classification ?? 'Not classified' }}</div>
                    </div>
                </div>
                <div class="overflow-y-auto px-6 py-4"><SupplierEvaluationBreakdown :evaluation="selectedSupplierEval.evaluation" :weights="ahpAnalytics.weights" /></div>
                <div class="flex justify-end border-t border-gray-100 bg-gray-50/50 px-6 py-3">
                    <button type="button" class="secondary-button" @click="selectedSupplierEval = null">Close</button>
                </div>
            </div>
        </div>
    </div>
</template>

<style>
@media print {
    /* Hide all system chrome, sidebar, and buttons */
    nav, aside, header, .print\\:hidden, button, select, input {
        display: none !important;
    }
    body, .workspace-page {
        padding: 0 !important;
        margin: 0 !important;
        background: #ffffff !important;
        color: #000000 !important;
    }
    table {
        page-break-inside: auto;
    }
    tr {
        page-break-inside: avoid;
        page-break-after: auto;
    }
}
</style>
