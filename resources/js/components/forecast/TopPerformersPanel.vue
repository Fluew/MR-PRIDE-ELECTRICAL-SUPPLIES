<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ChevronLeft, ChevronRight, Package, Truck } from '@lucide/vue';
import { computed, ref } from 'vue';
import { useCurrency } from '@/composables/useCurrency';

/**
 * Top 5 suppliers and Top 5 most in-demand products for a week, month or year. Everything comes
 * from recorded transactions (see App\Services\Forecast\TopPerformers). Changing the filter reloads
 * only this panel, so the SMA forecast above is not recalculated.
 */
export interface TopSupplier {
    id: number;
    name: string;
    status: string;
    delivery: number | null;
    price: number | null;
    quality: number | null;
    overall: number | null;
    rank: number | null;
    classification: string | null;
    missing: string[];
}
export interface TopProduct {
    rank: number;
    id: number;
    name: string;
    sku: string | null;
    unit: string;
    unit_cost: number | null;
    suppliers: string[];
    units_sold: number;
    total_sales: number;
}
export interface TopPerformers {
    period: 'weekly' | 'monthly' | 'annual';
    offset: number;
    label: string;
    is_current: boolean;
    from: string;
    to: string;
    has_earlier: boolean;
    suppliers: TopSupplier[];
    products: TopProduct[];
}

const props = defineProps<{ data: TopPerformers }>();
const { formatMoney } = useCurrency();

const PERIODS = [
    { key: 'weekly', label: 'Weekly', current: 'This week', unit: 'week' },
    { key: 'monthly', label: 'Monthly', current: 'This month', unit: 'month' },
    { key: 'annual', label: 'Annual', current: 'This year', unit: 'year' },
] as const;
type RankKey = 'overall' | 'delivery' | 'price' | 'quality';
const RANKS: { key: RankKey; label: string }[] = [
    { key: 'overall', label: 'Overall AHP score' },
    { key: 'delivery', label: 'Delivery performance' },
    { key: 'price', label: 'Price competitiveness' },
    { key: 'quality', label: 'Quality' },
];

const loading = ref(false);
const rankBy = ref<RankKey>('overall');
const periodMeta = computed(() => PERIODS.find((p) => p.key === props.data.period) ?? PERIODS[1]);

function load(period: TopPerformers['period'], offset: number) {
    loading.value = true;
    router.reload({
        only: ['topPerformers'],
        data: { top_period: period, top_offset: offset },
        onFinish: () => (loading.value = false),
    });
}

const day = (d: string) => new Date(`${d}T00:00:00`).toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' });
const rangeText = computed(() => {
    const prefix = props.data.is_current ? `${periodMeta.value.current} so far` : props.data.label;
    return `${prefix} · ${day(props.data.from)} – ${day(props.data.to)}`;
});

/** Top 5 by the chosen criterion; suppliers without that score in the period are left out (never counted as 0). */
const topSuppliers = computed(() =>
    props.data.suppliers
        .filter((s) => s[rankBy.value] !== null)
        .sort((a, b) => (b[rankBy.value] as number) - (a[rankBy.value] as number) || (b.overall ?? -1) - (a.overall ?? -1) || a.name.localeCompare(b.name))
        .slice(0, 5),
);
const excludedSuppliers = computed(() => props.data.suppliers.filter((s) => s[rankBy.value] === null).length);

const score = (v: number | null) => (v === null ? '—' : v.toFixed(2));
const whole = (v: number) => v.toLocaleString('en-US');
</script>

<template>
    <section class="panel p-5 sm:p-6" aria-labelledby="top-performers-title" data-test="top-performers">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h2 id="top-performers-title" class="section-title">Top suppliers and products</h2>
                <p class="section-subtitle">From recorded deliveries, inspections, biddings and confirmed sales in the selected period.</p>
            </div>
            <div class="analytics-segments max-w-full overflow-x-auto" role="tablist" aria-label="Top 5 period">
                <button
                    v-for="p in PERIODS"
                    :key="p.key"
                    type="button"
                    role="tab"
                    :aria-selected="data.period === p.key"
                    :data-test="`top-period-${p.key}`"
                    :class="data.period === p.key ? 'bg-white text-gray-900 shadow-xs' : 'text-gray-600 hover:text-gray-900'"
                    class="min-h-10 whitespace-nowrap rounded-lg px-3 py-2 text-xs font-medium transition-colors"
                    :disabled="loading"
                    @click="data.period !== p.key && load(p.key, 0)"
                >
                    {{ p.label }}
                </button>
            </div>
        </div>

        <!-- Which week / month / year -->
        <div class="mt-3 flex flex-wrap items-center gap-2 text-sm">
            <button type="button" class="secondary-button min-h-8 px-2 py-1" :disabled="loading || !data.has_earlier" :aria-label="`Previous ${periodMeta.unit}`" @click="load(data.period, data.offset + 1)">
                <ChevronLeft class="size-4" aria-hidden="true" />
            </button>
            <span class="font-medium text-gray-900" aria-live="polite" data-test="top-range">{{ rangeText }}</span>
            <button type="button" class="secondary-button min-h-8 px-2 py-1" :disabled="loading || data.is_current" :aria-label="`Next ${periodMeta.unit}`" @click="load(data.period, data.offset - 1)">
                <ChevronRight class="size-4" aria-hidden="true" />
            </button>
            <button v-if="!data.is_current" type="button" class="text-xs text-[#4b5320] underline" :disabled="loading" @click="load(data.period, 0)">{{ periodMeta.current }}</button>
            <span v-if="loading" class="text-xs text-gray-500">Loading…</span>
        </div>

        <div class="mt-5 grid gap-6 xl:grid-cols-2" :class="loading ? 'opacity-60' : ''">
            <!-- Top 5 suppliers -->
            <div>
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h3 class="flex items-center gap-2 text-sm font-semibold text-gray-900"><Truck class="size-4 text-[#4b5320]" aria-hidden="true" /> Top 5 suppliers</h3>
                    <label class="flex items-center gap-2 text-xs text-gray-600">
                        Rank by
                        <select v-model="rankBy" class="field-control min-h-8 py-1 text-xs" data-test="top-rank-by">
                            <option v-for="r in RANKS" :key="r.key" :value="r.key">{{ r.label }}</option>
                        </select>
                    </label>
                </div>
                <div class="mt-2 overflow-x-auto rounded-xl border border-gray-200">
                    <table class="w-full min-w-[520px] text-left text-xs">
                        <caption class="sr-only">Top 5 suppliers ranked by {{ RANKS.find((r) => r.key === rankBy)?.label }}</caption>
                        <thead class="bg-gray-50 text-gray-600">
                            <tr>
                                <th scope="col" class="w-8 p-2">#</th>
                                <th scope="col" class="p-2">Supplier</th>
                                <th v-for="r in RANKS.slice(1)" :key="r.key" scope="col" class="p-2 text-right" :class="rankBy === r.key ? 'text-gray-900' : ''">{{ r.label.split(' ')[0] }}</th>
                                <th scope="col" class="p-2 text-right" :class="rankBy === 'overall' ? 'text-gray-900' : ''">AHP score</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <tr v-for="(s, i) in topSuppliers" :key="s.id" data-test="top-supplier-row">
                                <td class="p-2 font-semibold text-gray-500">{{ i + 1 }}</td>
                                <td class="p-2">
                                    <span class="font-medium text-gray-900">{{ s.name }}</span>
                                    <span v-if="s.overall === null" class="block text-[11px] text-amber-700" :title="`Insufficient data: ${s.missing.join(', ')}`">No overall score: {{ s.missing.join(', ') }} missing</span>
                                </td>
                                <td v-for="r in RANKS.slice(1)" :key="r.key" class="p-2 text-right tabular-nums" :class="rankBy === r.key ? 'font-semibold text-gray-900' : 'text-gray-600'">{{ score(s[r.key]) }}</td>
                                <td class="p-2 text-right tabular-nums" :class="rankBy === 'overall' ? 'font-semibold text-gray-900' : 'text-gray-600'">
                                    {{ score(s.overall) }}
                                    <span v-if="s.classification" class="block text-[11px] font-normal text-gray-500">{{ s.classification }}</span>
                                </td>
                            </tr>
                            <tr v-if="!topSuppliers.length">
                                <td colspan="6" class="p-4 text-center text-gray-500">
                                    No supplier has {{ rankBy === 'overall' ? 'a complete evaluation' : 'records for this criterion' }} in this {{ periodMeta.unit }}. Try a longer period or an earlier one.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p class="mt-1.5 text-[11px] text-gray-500">
                    Scores 0–100 from the AHP Supplier Evaluation over this period only.
                    <template v-if="excludedSuppliers"> {{ excludedSuppliers }} supplier(s) with records but no {{ rankBy === 'overall' ? 'complete score' : 'score for this criterion' }} are not ranked (insufficient data is never counted as 0).</template>
                </p>
            </div>

            <!-- Top 5 products -->
            <div>
                <h3 class="flex items-center gap-2 text-sm font-semibold text-gray-900"><Package class="size-4 text-[#4b5320]" aria-hidden="true" /> Top 5 most in-demand products</h3>
                <div class="mt-2 overflow-x-auto rounded-xl border border-gray-200">
                    <table class="w-full min-w-[560px] text-left text-xs">
                        <caption class="sr-only">Top 5 products by units sold</caption>
                        <thead class="bg-gray-50 text-gray-600">
                            <tr>
                                <th scope="col" class="w-8 p-2">#</th>
                                <th scope="col" class="p-2">Product</th>
                                <th scope="col" class="p-2">Supplier(s)</th>
                                <th scope="col" class="p-2 text-right">Unit cost</th>
                                <th scope="col" class="p-2 text-right text-gray-900">Units sold</th>
                                <th scope="col" class="p-2 text-right">Total sales</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <tr v-for="p in data.products" :key="p.id" data-test="top-product-row">
                                <td class="p-2 font-semibold text-gray-500">{{ p.rank }}</td>
                                <td class="p-2">
                                    <span class="font-medium text-gray-900">{{ p.name }}</span>
                                    <span v-if="p.sku" class="block font-mono text-[11px] text-gray-500">{{ p.sku }}</span>
                                </td>
                                <td class="p-2 text-gray-600">{{ p.suppliers.length ? p.suppliers.join(', ') : '—' }}</td>
                                <td class="p-2 text-right tabular-nums text-gray-600">{{ p.unit_cost === null ? '—' : formatMoney(p.unit_cost) }}</td>
                                <td class="p-2 text-right font-semibold tabular-nums text-gray-900">{{ whole(p.units_sold) }} <span class="font-normal text-gray-500">{{ p.unit }}</span></td>
                                <td class="p-2 text-right tabular-nums text-gray-900">{{ formatMoney(p.total_sales) }}</td>
                            </tr>
                            <tr v-if="!data.products.length">
                                <td colspan="6" class="p-4 text-center text-gray-500">No confirmed sales in this {{ periodMeta.unit }}.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p class="mt-1.5 text-[11px] text-gray-500">Units sold and total sales from confirmed sales, net of cancelled quantities. Unit cost is the product's current cost price.</p>
            </div>
        </div>
    </section>
</template>
