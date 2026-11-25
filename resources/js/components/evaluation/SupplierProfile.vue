<script setup lang="ts">
import { computed, ref } from 'vue';
import { useCurrency } from '@/composables/useCurrency';

/**
 * The Supplier Profile tabs (Overview, Products, Delivery, Price, Quality). Every figure comes from
 * the backend (App\Services\Suppliers\SupplierProfile), from the same records as the AHP score and
 * the performance flags. Nothing is calculated here except formatting.
 */
export type ProfileSection = 'overview' | 'products' | 'delivery' | 'price' | 'quality';

interface Flag { key: string; label: string; reason: string }
export interface SupplierProfileData {
    flags: Flag[];
    products: {
        key: string; name: string; sku: string | null; unit: string; unit_price: number | null; quantity_ordered: number;
        units_received: number; units_accepted: number | null; deliveries: number; counts_in: string[];
        history: { date: string; delivery_number: string; po_number: string | null; quantity: number; status: string }[];
    }[];
    delivery: {
        score: number | null; reason: string | null; average_days: number | null; total: number; with_expected_date: number;
        on_time: number; late: number; on_time_rate: number | null; fulfillment_rate: number | null;
        rows: { product: string; delivery_number: string; po_number: string | null; po_date: string | null; expected: string | null; delivered: string; days: number | null; status: string }[];
    };
    price: {
        score: number | null; reason: string | null; compared: number; cheapest: number;
        rows: { product: string; sku: string | null; basis: string; own_price: number; others: { supplier: string; price: number }[]; lowest_price: number; difference: number; difference_pct: number | null; average_price: number; rank: number; of: number; score: number }[];
    };
    quality: {
        score: number | null; reason: string | null; products_inspected: number; accepted: number; rejected: number;
        rows: { product: string; delivery_number: string; inspected_on: string; result: string; accepted: number; rejected: number; reason: string | null }[];
        history: { month: string; accepted: number; inspected: number; rate: number | null }[];
    };
    ranking: {
        complete: boolean; score: number | null; rank: number | null; ranked_of: number; classification: string | null; missing: string[];
        criteria: { label: string; score: number | null; weight: number; weighted: number | null }[];
        top: { name: string; score: number; rank: number; self: boolean }[];
    };
}

const props = defineProps<{ profile: SupplierProfileData; section: ProfileSection }>();
const { formatMoney } = useCurrency();

const n2 = (v: number | null | undefined) => (v === null || v === undefined ? '—' : v.toFixed(2));
const whole = (v: number) => v.toLocaleString('en-US');
const pct = (w: number) => `${Math.round(w * 100)}%`;
const statusClass = (s: string) => (s === 'On time' ? 'text-emerald-700' : s.endsWith('late') ? 'text-rose-700' : 'text-gray-400');
const FLAG_STYLE: Record<string, string> = {
    best_overall: 'bg-[#4b5320]/10 text-[#4b5320]',
    fastest_delivery: 'bg-blue-100 text-blue-800',
    fast_delivery: 'bg-sky-100 text-sky-800',
    slow_delivery: 'bg-rose-100 text-rose-800',
    lowest_price: 'bg-emerald-100 text-emerald-800',
    highest_price: 'bg-amber-100 text-amber-800',
    best_quality: 'bg-violet-100 text-violet-800',
};

const r = computed(() => props.profile.ranking);
/** Plain titles and one-line explanations, in the evaluation's order: delivery, price, quality. */
const PLAIN = [
    { title: 'Delivery', text: 'How often orders arrive on time and complete.' },
    { title: 'Price', text: 'How close its prices are to the cheapest supplier.' },
    { title: 'Quality', text: 'Share of delivered units that pass inspection.' },
];
const openProduct = ref<string | null>(null);
</script>

<template>
    <div class="space-y-6 text-sm">
        <!-- ─────────── Overview: the three scores, the total, and why the badges ─────────── -->
        <template v-if="section === 'overview'">
            <div class="grid gap-4 md:grid-cols-3">
                <div v-for="(c, i) in r.criteria" :key="c.label" class="rounded-2xl border border-gray-200 p-5">
                    <div class="flex items-center justify-between">
                        <span class="text-sm font-medium text-gray-700">{{ PLAIN[i]?.title ?? c.label }}</span>
                        <span class="rounded-full bg-gray-100 px-2 py-0.5 text-xs text-gray-600">counts {{ pct(c.weight) }}</span>
                    </div>
                    <template v-if="c.score !== null">
                        <div class="mt-3 text-3xl font-semibold text-gray-900">{{ n2(c.score) }}<span class="text-base font-normal text-gray-400"> / 100</span></div>
                        <div class="mt-3 h-2 rounded-full bg-gray-100"><div class="h-2 rounded-full bg-[#4b5320]" :style="{ width: `${Math.min(100, c.score)}%` }"></div></div>
                        <p class="mt-3 text-xs text-gray-500">{{ PLAIN[i]?.text }} Adds <strong class="text-gray-800">{{ n2(c.weighted) }}</strong> points.</p>
                    </template>
                    <template v-else>
                        <div class="mt-3 text-lg font-semibold text-amber-700">Insufficient Data</div>
                        <p class="mt-2 text-xs text-gray-500">Not enough records yet. It is not counted as 0.</p>
                    </template>
                </div>
            </div>

            <div class="rounded-2xl bg-gray-50 px-5 py-4">
                <template v-if="r.complete">
                    <p class="text-gray-700">
                        Final score:
                        <span class="font-mono">{{ r.criteria.map((c) => n2(c.weighted)).join(' + ') }} =</span>
                        <strong class="text-lg text-gray-900">{{ n2(r.score) }} / 100</strong>
                    </p>
                    <p class="mt-1 text-sm text-gray-600">Rank <strong>{{ r.rank }}</strong> of {{ r.ranked_of }} suppliers · {{ r.classification }}</p>
                </template>
                <p v-else class="text-amber-800">No final score yet: {{ r.missing.join(' and ') }} {{ r.missing.length > 1 ? 'need' : 'needs' }} more records.</p>
            </div>

            <div>
                <h4 class="text-base font-semibold text-gray-900">Why these badges</h4>
                <ul v-if="profile.flags.length" class="mt-3 grid gap-2 md:grid-cols-2">
                    <li v-for="f in profile.flags" :key="f.key" class="rounded-xl border border-gray-200 p-3">
                        <span :class="FLAG_STYLE[f.key]" class="rounded px-2 py-0.5 text-xs font-medium">{{ f.label }}</span>
                        <p class="mt-2 text-sm text-gray-700">{{ f.reason }}</p>
                    </li>
                </ul>
                <p v-else class="mt-2 text-gray-500">No badges right now. A badge is given when this supplier is the best (or worst) of all suppliers on the same records.</p>
            </div>
        </template>

        <!-- ─────────── Products ─────────── -->
        <template v-else-if="section === 'products'">
            <p class="text-gray-600">Products delivered by this supplier in the evaluation period. Click a product to see its deliveries.</p>
            <div v-if="profile.products.length" class="overflow-x-auto rounded-xl border border-gray-200">
                <table class="w-full min-w-[720px] text-left text-sm">
                    <thead class="bg-gray-50 text-gray-500">
                        <tr><th class="px-3 py-2.5">Product</th><th class="px-3 py-2.5 text-right">Unit price</th><th class="px-3 py-2.5 text-right">Qty ordered</th><th class="px-3 py-2.5 text-right">Units received</th><th class="px-3 py-2.5">Counts in score</th></tr>
                    </thead>
                    <tbody>
                        <template v-for="p in profile.products" :key="p.key">
                            <tr class="cursor-pointer border-t border-gray-100 hover:bg-gray-50" @click="openProduct = openProduct === p.key ? null : p.key">
                                <td class="px-3 py-2.5">
                                    <span class="font-medium text-gray-900">{{ openProduct === p.key ? '▾' : '▸' }} {{ p.name }}</span>
                                    <span v-if="p.sku" class="block pl-3 font-mono text-[11px] text-gray-500">{{ p.sku }}</span>
                                </td>
                                <td class="px-3 py-2.5 text-right font-mono">{{ p.unit_price === null ? '—' : formatMoney(p.unit_price) }} <span class="font-sans text-gray-400">/ {{ p.unit }}</span></td>
                                <td class="px-3 py-2.5 text-right font-mono">{{ whole(p.quantity_ordered) }}</td>
                                <td class="px-3 py-2.5 text-right font-mono">{{ whole(p.units_received) }}<span class="block font-sans text-[11px] text-gray-400">{{ p.deliveries }} deliver{{ p.deliveries === 1 ? 'y' : 'ies' }}</span></td>
                                <td class="px-3 py-2.5">
                                    <span v-for="c in p.counts_in" :key="c" class="mr-1 rounded bg-gray-100 px-1.5 py-0.5 text-[11px] text-gray-700">{{ c }}</span>
                                    <span v-if="!p.counts_in.length" class="text-gray-400">—</span>
                                </td>
                            </tr>
                            <tr v-if="openProduct === p.key" class="bg-gray-50/60">
                                <td colspan="5" class="px-4 pb-3">
                                    <table class="w-full text-left">
                                        <thead class="text-gray-500"><tr><th class="py-1">Delivered</th><th>Delivery</th><th>PO</th><th class="text-right">Qty</th><th class="text-right">Status</th></tr></thead>
                                        <tbody><tr v-for="h in p.history" :key="h.delivery_number + h.date"><td class="py-1">{{ h.date }}</td><td>{{ h.delivery_number }}</td><td>{{ h.po_number ?? '—' }}</td><td class="text-right">{{ whole(h.quantity) }}</td><td class="text-right" :class="statusClass(h.status)">{{ h.status }}</td></tr></tbody>
                                    </table>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
            <p v-else class="text-amber-700">Insufficient Data: no confirmed delivery from this supplier in the evaluation period.</p>
            <p class="text-xs text-gray-500">"Counts in score" shows which parts of the evaluation each product's records are used in.</p>
        </template>

        <!-- ─────────── Delivery ─────────── -->
        <template v-else-if="section === 'delivery'">
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                <div class="rounded-xl bg-gray-50 p-3"><div class="text-xs text-gray-500">Average delivery time</div><div class="text-lg font-semibold">{{ profile.delivery.average_days === null ? '—' : `${profile.delivery.average_days} days` }}</div></div>
                <div class="rounded-xl bg-gray-50 p-3"><div class="text-xs text-gray-500">On-time rate</div><div class="text-lg font-semibold">{{ profile.delivery.on_time_rate === null ? '—' : `${profile.delivery.on_time_rate}%` }}</div></div>
                <div class="rounded-xl bg-emerald-50 p-3"><div class="text-xs text-emerald-700">On time</div><div class="text-lg font-semibold text-emerald-800">{{ profile.delivery.on_time }}</div></div>
                <div class="rounded-xl bg-rose-50 p-3"><div class="text-xs text-rose-700">Late</div><div class="text-lg font-semibold text-rose-800">{{ profile.delivery.late }}</div></div>
            </div>
            <p class="text-gray-700">
                <template v-if="profile.delivery.score !== null">Delivery score: <strong>{{ n2(profile.delivery.score) }} / 100</strong> (on-time rate counts 60%, receiving the full ordered quantity counts 40%<template v-if="profile.delivery.fulfillment_rate !== null">: {{ profile.delivery.fulfillment_rate }}% received</template>).</template>
                <span v-else class="text-amber-700">Delivery score: Insufficient Data. {{ profile.delivery.reason }}</span>
            </p>
            <div v-if="profile.delivery.rows.length" class="overflow-x-auto rounded-xl border border-gray-200">
                <table class="w-full min-w-[760px] text-left text-sm">
                    <thead class="bg-gray-50 text-gray-500"><tr><th class="px-3 py-2.5">Product</th><th class="px-3 py-2.5">PO date</th><th class="px-3 py-2.5">Expected</th><th class="px-3 py-2.5">Delivered</th><th class="px-3 py-2.5 text-right">Days taken</th><th class="px-3 py-2.5 text-right">Result</th></tr></thead>
                    <tbody>
                        <tr v-for="d in profile.delivery.rows" :key="d.delivery_number + d.delivered" class="border-t border-gray-100">
                            <td class="px-3 py-2.5"><span class="text-gray-900">{{ d.product }}</span><span class="block text-[11px] text-gray-400">{{ d.delivery_number }}<template v-if="d.po_number"> · PO {{ d.po_number }}</template></span></td>
                            <td class="px-3 py-2.5">{{ d.po_date ?? '—' }}</td>
                            <td class="px-3 py-2.5">{{ d.expected ?? '—' }}</td>
                            <td class="px-3 py-2.5">{{ d.delivered }}</td>
                            <td class="px-3 py-2.5 text-right font-mono">{{ d.days ?? '—' }}</td>
                            <td class="px-3 py-2.5 text-right font-medium" :class="statusClass(d.status)">{{ d.status }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p class="text-xs text-gray-500">Days taken = from the purchase order date to the delivery date. Fastest / Fast / Slow Delivery badges compare this supplier's average with other suppliers.</p>
        </template>

        <!-- ─────────── Price ─────────── -->
        <template v-else-if="section === 'price'">
            <p class="text-gray-700">
                <template v-if="profile.price.score !== null">Price score: <strong>{{ n2(profile.price.score) }} / 100</strong>. Cheapest in <strong>{{ profile.price.cheapest }} of {{ profile.price.compared }}</strong> price comparisons.</template>
                <span v-else class="text-amber-700">Price score: Insufficient Data. {{ profile.price.reason }}</span>
            </p>
            <div v-for="(p, i) in profile.price.rows" :key="i" class="rounded-xl border border-gray-200 p-3">
                <div class="flex flex-wrap items-start justify-between gap-2">
                    <div>
                        <div class="font-medium text-gray-900">{{ p.product }} <span v-if="p.sku" class="font-mono text-[11px] text-gray-500">{{ p.sku }}</span></div>
                        <div class="text-[11px] text-gray-500">{{ p.basis }}</div>
                    </div>
                    <span class="rounded px-1.5 py-0.5 text-xs font-medium" :class="p.rank === 1 ? 'bg-emerald-100 text-emerald-800' : p.rank === p.of ? 'bg-amber-100 text-amber-800' : 'bg-gray-100 text-gray-700'">
                        {{ p.rank === 1 ? 'Cheapest' : p.rank === p.of ? 'Most expensive' : `#${p.rank}` }} of {{ p.of }}
                    </span>
                </div>
                <div class="mt-2 grid gap-1 text-xs sm:grid-cols-2">
                    <div>This supplier: <strong class="font-mono">{{ formatMoney(p.own_price) }}</strong></div>
                    <div>Average of all suppliers: <span class="font-mono">{{ formatMoney(p.average_price) }}</span></div>
                    <div>
                        Difference from lowest:
                        <span class="font-mono" :class="p.difference > 0 ? 'text-rose-700' : 'text-emerald-700'">{{ p.difference > 0 ? '+' : '' }}{{ formatMoney(p.difference) }}<template v-if="p.difference_pct"> ({{ p.difference_pct > 0 ? '+' : '' }}{{ p.difference_pct }}%)</template></span>
                    </div>
                    <div>Other suppliers: <span v-for="(o, k) in p.others" :key="k" class="mr-2 whitespace-nowrap">{{ o.supplier }} <span class="font-mono">{{ formatMoney(o.price) }}</span></span></div>
                </div>
            </div>
            <p class="text-xs text-gray-500">Only the same product with the same unit (or the same bidding) is compared. Score per product = lowest price ÷ this supplier's price × 100.</p>
        </template>

        <!-- ─────────── Quality ─────────── -->
        <template v-else-if="section === 'quality'">
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                <div class="rounded-xl bg-gray-50 p-3"><div class="text-xs text-gray-500">Acceptance rate</div><div class="text-lg font-semibold">{{ profile.quality.score === null ? '—' : `${n2(profile.quality.score)}%` }}</div></div>
                <div class="rounded-xl bg-gray-50 p-3"><div class="text-xs text-gray-500">Products inspected</div><div class="text-lg font-semibold">{{ profile.quality.products_inspected }}</div></div>
                <div class="rounded-xl bg-emerald-50 p-3"><div class="text-xs text-emerald-700">Accepted units</div><div class="text-lg font-semibold text-emerald-800">{{ whole(profile.quality.accepted) }}</div></div>
                <div class="rounded-xl bg-rose-50 p-3"><div class="text-xs text-rose-700">Rejected units</div><div class="text-lg font-semibold text-rose-800">{{ whole(profile.quality.rejected) }}</div></div>
            </div>
            <p v-if="profile.quality.score === null" class="text-amber-700">Quality score: Insufficient Data. {{ profile.quality.reason }}</p>

            <div v-if="profile.quality.history.length">
                <h4 class="font-semibold text-gray-900">Acceptance rate by month</h4>
                <div class="mt-2 space-y-1.5">
                    <div v-for="h in profile.quality.history" :key="h.month" class="flex items-center gap-2 text-xs">
                        <span class="w-16 shrink-0 text-gray-500">{{ h.month }}</span>
                        <div class="h-2 flex-1 rounded-full bg-gray-100"><div class="h-2 rounded-full bg-[#4b5320]" :style="{ width: `${h.rate ?? 0}%` }"></div></div>
                        <span class="w-24 shrink-0 text-right font-mono">{{ h.rate }}% <span class="text-gray-400">({{ whole(h.inspected) }})</span></span>
                    </div>
                </div>
            </div>

            <div v-if="profile.quality.rows.length" class="overflow-x-auto rounded-xl border border-gray-200">
                <table class="w-full min-w-[680px] text-left text-sm">
                    <thead class="bg-gray-50 text-gray-500"><tr><th class="px-3 py-2.5">Product</th><th class="px-3 py-2.5">Inspected</th><th class="px-3 py-2.5">Result</th><th class="px-3 py-2.5 text-right">Accepted</th><th class="px-3 py-2.5 text-right">Rejected</th></tr></thead>
                    <tbody>
                        <tr v-for="q in profile.quality.rows" :key="q.delivery_number + q.inspected_on" class="border-t border-gray-100">
                            <td class="px-3 py-2.5"><span class="text-gray-900">{{ q.product }}</span><span class="block text-[11px] text-gray-400">{{ q.delivery_number }}</span></td>
                            <td class="px-3 py-2.5">{{ q.inspected_on }}</td>
                            <td class="px-3 py-2.5">{{ q.result }}<span v-if="q.reason" class="block text-[11px] text-gray-400">{{ q.reason }}</span></td>
                            <td class="px-3 py-2.5 text-right font-mono text-emerald-700">{{ whole(q.accepted) }}</td>
                            <td class="px-3 py-2.5 text-right font-mono" :class="q.rejected ? 'text-rose-700' : 'text-gray-400'">{{ whole(q.rejected) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p class="text-xs text-gray-500">Acceptance rate = accepted units ÷ inspected units × 100, from goods-receipt inspections.</p>
        </template>
    </div>
</template>
