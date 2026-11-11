<script setup lang="ts">
/**
 * The printed business summary of Reports & Analytics (Print Summary button).
 *
 * One page of decisions-first information: key figures, what needs attention, then short
 * sections for sales, stock and purchasing. Only shown when printing. Built from the same data
 * as the screen, for the selected report period; stock figures are as of today.
 */
import { computed } from 'vue';
import { useCurrency } from '@/composables/useCurrency';

const props = defineProps<{
    companyName: string;
    periodLabel: string;
    metrics: {
        inventoryCount: number;
        totalValuation: number;
        totalRevenue: number;
        salesCount: number;
        totalPurchasesCount: number;
        totalPurchasesEstimatedCost: number;
    };
    stockStatus: { in_stock: number; low_stock: number; out_of_stock: number };
    categories: Array<{ category: string; count: number; stock: number; valuation: number }>;
    lowStockItems: Array<{ id: number; name: string; sku: string; stock: number; reorder_level: number; suggested_reorder: number; supplier_name: string; urgency: string }>;
    salesTrend: { labels: string[]; data: number[] };
    topSellingProducts: Array<{ name: string; sku: string; quantity: number; amount: number }>;
    conversion: { total: number; converted: number; rate: number };
    quotationStatuses: Record<string, number>;
    forecast: { labels: string[]; forecast: (number | null)[]; year: number; window: number; hasData: boolean };
    purchaseStatuses: Record<string, number>;
    suppliers: Array<{ id: number; name: string; ahp_score: number | null; classification: string | null; complete: boolean }>;
    reorderNeededCount: number;
}>();

const { formatMoney, displayCurrency } = useCurrency();
const num = (n: number) => new Intl.NumberFormat('en-PH').format(n);
const pct = (part: number, whole: number) => (whole > 0 ? Math.round((part / whole) * 100) : 0);
const printedOn = new Date().toLocaleDateString('en-PH', { dateStyle: 'long' });

// ── Sales trend: the current month is still running, so it is never compared with full months.
const now = new Date();
const currentMonthLabel = now.toLocaleDateString('en-US', { month: 'short', year: 'numeric' }); // "Nov 2026", like the server's labels
const months = computed(() =>
    props.salesTrend.labels.map((label, i) => ({ label, revenue: props.salesTrend.data[i] ?? 0, partial: label === currentMonthLabel })).slice(-6),
);
const monthRows = computed(() => {
    const best = Math.max(0, ...months.value.filter((m) => !m.partial).map((m) => m.revenue));
    const top = Math.max(1, ...months.value.map((m) => m.revenue));
    return months.value.map((m, i) => {
        const prev = months.value[i - 1];
        const change = !m.partial && prev && !prev.partial && prev.revenue > 0 ? Math.round(((m.revenue - prev.revenue) / prev.revenue) * 100) : null;
        return { ...m, change, best: !m.partial && m.revenue > 0 && m.revenue === best, bar: Math.round((m.revenue / top) * 100) };
    });
});
/** Last two complete months, for the headline trend. */
const salesChange = computed(() => {
    const full = months.value.filter((m) => !m.partial);
    const [prev, last] = full.slice(-2);
    if (!prev || !last || prev.revenue <= 0) return null;
    return { from: prev.label, to: last.label, change: Math.round(((last.revenue - prev.revenue) / prev.revenue) * 100) };
});
const averageSale = computed(() => (props.metrics.salesCount > 0 ? props.metrics.totalRevenue / props.metrics.salesCount : 0));

// ── Stock
const totalProducts = computed(() => props.stockStatus.in_stock + props.stockStatus.low_stock + props.stockStatus.out_of_stock);
const lowOnly = computed(() => Math.max(0, props.stockStatus.low_stock));
const restockFirst = computed(() => props.lowStockItems.slice(0, 8));
const topCategories = computed(() => [...props.categories].sort((a, b) => b.valuation - a.valuation).slice(0, 5));
const categoryTotal = computed(() => props.categories.reduce((s, c) => s + c.valuation, 0));
/** This month's expected demand (simple average of the previous months). */
const demandThisMonth = computed(() => {
    if (!props.forecast.hasData || props.forecast.year !== now.getFullYear()) return null;
    const value = props.forecast.forecast[now.getMonth()];
    return value === null || value === undefined ? null : { month: props.forecast.labels[now.getMonth()], units: value };
});

// ── Purchasing and suppliers
const pendingApproval = computed(() => props.purchaseStatuses['Pending Approval'] ?? 0);
const purchaseRows = computed(() => [
    { label: 'Waiting for approval', value: pendingApproval.value },
    { label: 'Approved, not yet ordered', value: props.purchaseStatuses['Approved'] ?? 0 },
    { label: 'Ordered from supplier', value: props.purchaseStatuses['Converted to Purchase Order'] ?? 0 },
    { label: 'Rejected or cancelled', value: props.purchaseStatuses['Rejected'] ?? 0 },
]);
const topSuppliers = computed(() => props.suppliers.filter((s) => s.complete && s.ahp_score !== null).slice(0, 3));
const awaitingReply = computed(() => props.quotationStatuses['sent'] ?? 0);

// ── Needs attention: only what is actually wrong, most serious first.
type Level = 'urgent' | 'warning' | 'info';
const attention = computed(() => {
    const items: Array<{ level: Level; text: string }> = [];
    const plural = (n: number, one: string, many: string) => `${num(n)} ${n === 1 ? one : many}`;
    if (props.stockStatus.out_of_stock > 0) {
        items.push({ level: 'urgent', text: `${plural(props.stockStatus.out_of_stock, 'product is', 'products are')} out of stock and cannot be sold.` });
    }
    if (lowOnly.value > 0) {
        items.push({ level: 'warning', text: `${plural(lowOnly.value, 'product is', 'products are')} running low and should be restocked soon.` });
    }
    if (salesChange.value && salesChange.value.change <= -20) {
        items.push({ level: 'warning', text: `Sales dropped ${Math.abs(salesChange.value.change)}% from ${salesChange.value.from} to ${salesChange.value.to}.` });
    }
    if (pendingApproval.value > 0) {
        items.push({ level: 'warning', text: `${plural(pendingApproval.value, 'purchase request is', 'purchase requests are')} waiting for approval.` });
    }
    if (props.reorderNeededCount > 0) {
        items.push({ level: 'info', text: `${plural(props.reorderNeededCount, 'product should', 'products should')} be reordered based on expected demand.` });
    }
    if (awaitingReply.value > 0) {
        items.push({ level: 'info', text: `${plural(awaitingReply.value, 'quotation is', 'quotations are')} waiting for the customer's reply. Follow up to close the sale.` });
    }
    return items;
});
const levelStyle: Record<Level, { dot: string; label: string }> = {
    urgent: { dot: 'bg-rose-600', label: 'Urgent' },
    warning: { dot: 'bg-amber-500', label: 'Soon' },
    info: { dot: 'bg-sky-600', label: 'Note' },
};
</script>

<template>
    <div class="print-summary text-[11px] leading-snug text-gray-900">
        <!-- Title block -->
        <div class="flex items-end justify-between border-b-2 border-[#4b5320] pb-2">
            <div>
                <p class="text-[10px] font-semibold tracking-wide text-[#4b5320]">{{ companyName }}</p>
                <h1 class="text-xl font-semibold">Business Summary</h1>
            </div>
            <div class="text-right text-[10px] text-gray-600">
                <p>Period: <strong class="text-gray-900">{{ periodLabel }}</strong></p>
                <p>Printed {{ printedOn }} · Amounts in {{ displayCurrency }}</p>
            </div>
        </div>

        <!-- 1. Key figures -->
        <section class="mt-4 break-inside-avoid">
            <h2 class="section-title">Key figures</h2>
            <div class="grid grid-cols-3 gap-2">
                <div class="tile">
                    <p class="tile-label">Sales revenue</p>
                    <p class="tile-value">{{ formatMoney(metrics.totalRevenue) }}</p>
                    <p class="tile-note">
                        {{ num(metrics.salesCount) }} completed {{ metrics.salesCount === 1 ? 'sale' : 'sales' }}
                        <template v-if="salesChange">
                            · <span :class="salesChange.change >= 0 ? 'text-emerald-700' : 'text-rose-700'" class="font-semibold">{{ salesChange.change >= 0 ? '▲' : '▼' }} {{ Math.abs(salesChange.change) }}%</span> vs {{ salesChange.from.split(' ')[0] }}
                        </template>
                    </p>
                </div>
                <div class="tile">
                    <p class="tile-label">Average sale</p>
                    <p class="tile-value">{{ formatMoney(averageSale) }}</p>
                    <p class="tile-note">Per completed sale</p>
                </div>
                <div class="tile">
                    <p class="tile-label">Quotations won</p>
                    <p class="tile-value">{{ conversion.rate }}%</p>
                    <p class="tile-note">{{ num(conversion.converted) }} of {{ num(conversion.total) }} became sales</p>
                </div>
                <div class="tile">
                    <p class="tile-label">Stock value</p>
                    <p class="tile-value">{{ formatMoney(metrics.totalValuation) }}</p>
                    <p class="tile-note">{{ num(metrics.inventoryCount) }} products, at cost</p>
                </div>
                <div class="tile" :class="stockStatus.out_of_stock > 0 ? 'tile-alert' : ''">
                    <p class="tile-label">Stock problems</p>
                    <p class="tile-value" :class="stockStatus.out_of_stock > 0 ? 'text-rose-700' : ''">{{ num(stockStatus.out_of_stock + lowOnly) }}</p>
                    <p class="tile-note">{{ num(stockStatus.out_of_stock) }} out of stock · {{ num(lowOnly) }} low</p>
                </div>
                <div class="tile">
                    <p class="tile-label">Purchasing</p>
                    <p class="tile-value">{{ formatMoney(metrics.totalPurchasesEstimatedCost) }}</p>
                    <p class="tile-note">{{ num(metrics.totalPurchasesCount) }} purchase {{ metrics.totalPurchasesCount === 1 ? 'request' : 'requests' }}</p>
                </div>
            </div>
        </section>

        <!-- 2. Needs attention -->
        <section class="mt-4 break-inside-avoid">
            <h2 class="section-title">Needs attention</h2>
            <ul v-if="attention.length" class="space-y-1">
                <li v-for="(a, i) in attention" :key="i" class="flex items-start gap-2">
                    <span class="mt-[3px] inline-block h-2 w-2 shrink-0 rounded-full" :class="levelStyle[a.level].dot"></span>
                    <span><strong class="font-semibold">{{ levelStyle[a.level].label }}:</strong> {{ a.text }}</span>
                </li>
            </ul>
            <p v-else class="text-gray-600">Nothing needs urgent attention.</p>

            <template v-if="restockFirst.length">
                <h3 class="mt-3 mb-1 text-[11px] font-semibold">Restock first</h3>
                <table class="summary-table">
                    <thead>
                        <tr><th>Product</th><th class="r">In stock</th><th class="r">Reorder at</th><th class="r">Suggested order</th><th>Supplier</th><th>Status</th></tr>
                    </thead>
                    <tbody>
                        <tr v-for="p in restockFirst" :key="p.id">
                            <td>{{ p.name }} <span class="text-gray-500">({{ p.sku }})</span></td>
                            <td class="r font-semibold" :class="p.stock <= 0 ? 'text-rose-700' : 'text-amber-700'">{{ num(p.stock) }}</td>
                            <td class="r">{{ num(p.reorder_level) }}</td>
                            <td class="r">{{ num(p.suggested_reorder) }}</td>
                            <td>{{ p.supplier_name }}</td>
                            <td :class="p.stock <= 0 ? 'text-rose-700 font-semibold' : ''">{{ p.urgency }}</td>
                        </tr>
                    </tbody>
                </table>
                <p v-if="lowStockItems.length > restockFirst.length" class="mt-1 text-[10px] text-gray-500">
                    And {{ num(lowStockItems.length - restockFirst.length) }} more. See the Low Stock Audit report for the full list.
                </p>
            </template>
        </section>

        <!-- 3. Sales -->
        <section class="mt-4 break-inside-avoid">
            <h2 class="section-title">Sales</h2>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <h3 class="mb-1 text-[11px] font-semibold">Revenue by month</h3>
                    <table class="summary-table">
                        <thead><tr><th>Month</th><th class="r">Revenue</th><th class="w-[30%]"></th><th class="r">Change</th></tr></thead>
                        <tbody>
                            <tr v-for="m in monthRows" :key="m.label" :class="m.best ? 'font-semibold' : ''">
                                <td>{{ m.label }}<span v-if="m.partial" class="font-normal text-gray-500"> (so far)</span><span v-if="m.best" class="font-normal text-gray-500"> (best)</span></td>
                                <td class="r">{{ formatMoney(m.revenue) }}</td>
                                <td><div class="h-1.5 rounded-full bg-[#4b5320]" :style="{ width: `${Math.max(m.bar, m.revenue > 0 ? 2 : 0)}%` }"></div></td>
                                <td class="r" :class="m.change === null ? 'text-gray-400' : m.change >= 0 ? 'text-emerald-700' : 'text-rose-700'">
                                    {{ m.change === null ? '—' : `${m.change >= 0 ? '▲' : '▼'} ${Math.abs(m.change)}%` }}
                                </td>
                            </tr>
                            <tr v-if="!monthRows.length"><td colspan="4" class="text-gray-500">No sales in this period.</td></tr>
                        </tbody>
                    </table>
                </div>
                <div>
                    <h3 class="mb-1 text-[11px] font-semibold">Best-selling products</h3>
                    <table class="summary-table">
                        <thead><tr><th>Product</th><th class="r">Units</th><th class="r">Revenue</th></tr></thead>
                        <tbody>
                            <tr v-for="p in topSellingProducts.slice(0, 5)" :key="p.sku + p.name">
                                <td>{{ p.name }}</td>
                                <td class="r">{{ num(p.quantity) }}</td>
                                <td class="r">{{ formatMoney(p.amount) }}</td>
                            </tr>
                            <tr v-if="!topSellingProducts.length"><td colspan="3" class="text-gray-500">No products sold in this period.</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

        <!-- 4. Stock -->
        <section class="mt-4 break-inside-avoid">
            <h2 class="section-title">Stock <span class="font-normal text-gray-500">(as of today)</span></h2>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <h3 class="mb-1 text-[11px] font-semibold">Stock health</h3>
                    <div v-if="totalProducts" class="mb-1.5 flex h-2.5 overflow-hidden rounded-full bg-gray-100">
                        <div class="bg-emerald-600" :style="{ width: `${pct(stockStatus.in_stock, totalProducts)}%` }"></div>
                        <div class="bg-amber-500" :style="{ width: `${pct(lowOnly, totalProducts)}%` }"></div>
                        <div class="bg-rose-600" :style="{ width: `${pct(stockStatus.out_of_stock, totalProducts)}%` }"></div>
                    </div>
                    <table class="summary-table">
                        <tbody>
                            <tr><td><span class="legend bg-emerald-600"></span>In stock</td><td class="r">{{ num(stockStatus.in_stock) }}</td><td class="r text-gray-500">{{ pct(stockStatus.in_stock, totalProducts) }}%</td></tr>
                            <tr><td><span class="legend bg-amber-500"></span>Running low</td><td class="r">{{ num(lowOnly) }}</td><td class="r text-gray-500">{{ pct(lowOnly, totalProducts) }}%</td></tr>
                            <tr><td><span class="legend bg-rose-600"></span>Out of stock</td><td class="r font-semibold" :class="stockStatus.out_of_stock ? 'text-rose-700' : ''">{{ num(stockStatus.out_of_stock) }}</td><td class="r text-gray-500">{{ pct(stockStatus.out_of_stock, totalProducts) }}%</td></tr>
                        </tbody>
                    </table>
                    <p class="mt-1.5 text-[10px] text-gray-600">
                        <template v-if="demandThisMonth">Expected demand for {{ demandThisMonth.month }}: <strong class="text-gray-900">{{ num(demandThisMonth.units) }} units</strong> (average of the last {{ forecast.window }} months).</template>
                        <template v-else>Not enough sales history yet to estimate this month's demand.</template>
                    </p>
                </div>
                <div>
                    <h3 class="mb-1 text-[11px] font-semibold">Where stock value is held</h3>
                    <table class="summary-table">
                        <thead><tr><th>Category</th><th class="r">Products</th><th class="r">Value</th><th class="r">Share</th></tr></thead>
                        <tbody>
                            <tr v-for="c in topCategories" :key="c.category">
                                <td>{{ c.category }}</td>
                                <td class="r">{{ num(c.count) }}</td>
                                <td class="r">{{ formatMoney(c.valuation) }}</td>
                                <td class="r text-gray-500">{{ pct(c.valuation, categoryTotal) }}%</td>
                            </tr>
                            <tr v-if="!topCategories.length"><td colspan="4" class="text-gray-500">No products yet.</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

        <!-- 5. Purchasing and suppliers -->
        <section class="mt-4 break-inside-avoid">
            <h2 class="section-title">Purchasing and suppliers</h2>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <h3 class="mb-1 text-[11px] font-semibold">Purchase requests</h3>
                    <table class="summary-table">
                        <tbody>
                            <tr v-for="r in purchaseRows" :key="r.label">
                                <td>{{ r.label }}</td>
                                <td class="r" :class="r.label === 'Waiting for approval' && r.value > 0 ? 'font-semibold text-amber-700' : ''">{{ num(r.value) }}</td>
                            </tr>
                            <tr class="font-semibold"><td>Total planned spend</td><td class="r">{{ formatMoney(metrics.totalPurchasesEstimatedCost) }}</td></tr>
                        </tbody>
                    </table>
                </div>
                <div>
                    <h3 class="mb-1 text-[11px] font-semibold">Best suppliers</h3>
                    <table v-if="topSuppliers.length" class="summary-table">
                        <thead><tr><th>Supplier</th><th class="r">Score (out of 100)</th><th>Rating</th></tr></thead>
                        <tbody>
                            <tr v-for="s in topSuppliers" :key="s.id">
                                <td>{{ s.name }}</td>
                                <td class="r font-semibold">{{ Number(s.ahp_score).toFixed(1) }}</td>
                                <td>{{ s.classification ?? '—' }}</td>
                            </tr>
                        </tbody>
                    </table>
                    <p v-else class="text-gray-600">Not enough delivery, price and quality records yet to rate suppliers.</p>
                    <p class="mt-1 text-[10px] text-gray-500">Scores come from on-time delivery, price and quality of received goods.</p>
                </div>
            </div>
        </section>

        <p class="mt-5 border-t border-gray-300 pt-1.5 text-[9px] text-gray-500">
            Sales, quotation and purchasing figures cover {{ periodLabel.toLowerCase() === 'all-time records' ? 'all records' : `the period: ${periodLabel}` }}.
            Stock figures are as of the print date. For details, open the matching report in Reports &amp; Analytics.
        </p>
    </div>
</template>

<style scoped>
.print-summary {
    -webkit-print-color-adjust: exact;
    print-color-adjust: exact;
}
.section-title {
    margin-bottom: 0.4rem;
    border-bottom: 1px solid #d1d5db;
    padding-bottom: 0.15rem;
    font-size: 12px;
    font-weight: 600;
    color: #4b5320;
}
.tile {
    border: 1px solid #e5e7eb;
    border-radius: 0.5rem;
    padding: 0.45rem 0.6rem;
}
.tile-alert {
    border-color: #fecdd3;
    background: #fff1f2;
}
.tile-label {
    font-size: 10px;
    color: #4b5563;
}
.tile-value {
    margin-top: 0.1rem;
    font-size: 15px;
    font-weight: 600;
}
.tile-note {
    font-size: 9.5px;
    color: #6b7280;
}
.summary-table {
    width: 100%;
    border-collapse: collapse;
}
.summary-table th {
    border-bottom: 1px solid #9ca3af;
    padding: 0.2rem 0.3rem;
    text-align: left;
    font-size: 9.5px;
    font-weight: 600;
    color: #4b5563;
}
.summary-table td {
    border-bottom: 1px solid #f3f4f6;
    padding: 0.2rem 0.3rem;
    vertical-align: middle;
}
.summary-table .r {
    text-align: right;
    white-space: nowrap;
}
.legend {
    display: inline-block;
    width: 0.5rem;
    height: 0.5rem;
    margin-right: 0.35rem;
    border-radius: 9999px;
}
</style>
