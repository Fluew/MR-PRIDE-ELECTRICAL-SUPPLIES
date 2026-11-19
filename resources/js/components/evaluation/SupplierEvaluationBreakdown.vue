<script setup lang="ts">
/**
 * "View Calculation" for one supplier's record-based evaluation (from SupplierEvaluator).
 * Shared by the Suppliers page and Reports & Analytics.
 */
import ScoreBreakdown, { type Evaluation } from '@/components/evaluation/ScoreBreakdown.vue';
import { usePrivacyMode } from '@/composables/usePrivacyMode';

const { mask } = usePrivacyMode();

const props = defineProps<{
    evaluation: Evaluation & { period?: { from: string; to: string } };
    weights: { delivery: number; price: number; quality: number };
}>();

const c = (key: string) => props.evaluation.criteria.find((x) => x.key === key);
const pctOrDash = (v: number | null | undefined) => (v != null ? `${Number(v).toFixed(2)}%` : '—');
</script>

<template>
    <div>
        <p v-if="evaluation.period" class="mb-3 text-xs text-gray-500">Evaluation period: {{ evaluation.period.from }} to {{ evaluation.period.to }}</p>
        <dl class="mb-4 grid grid-cols-2 gap-3 text-sm sm:grid-cols-4">
            <div><dt class="text-xs text-gray-500">Completed deliveries</dt><dd class="font-mono">{{ c('delivery')?.inputs?.completed_deliveries ?? 0 }}</dd></div>
            <div><dt class="text-xs text-gray-500">On-time rate</dt><dd class="font-mono">{{ pctOrDash(c('delivery')?.inputs?.on_time_rate) }}</dd></div>
            <div><dt class="text-xs text-gray-500">Quantity fulfillment</dt><dd class="font-mono">{{ pctOrDash(c('delivery')?.inputs?.fulfillment_rate) }}</dd></div>
            <div><dt class="text-xs text-gray-500">Price comparisons (biddings + purchases)</dt><dd class="font-mono">{{ c('price')?.inputs?.comparisons?.length ?? 0 }}</dd></div>
            <div><dt class="text-xs text-gray-500">Accepted units</dt><dd class="font-mono">{{ c('quality')?.inputs?.accepted ?? 0 }}</dd></div>
            <div><dt class="text-xs text-gray-500">Rejected units</dt><dd class="font-mono">{{ c('quality')?.inputs?.rejected ?? 0 }}</dd></div>
            <div><dt class="text-xs text-gray-500">Quality acceptance</dt><dd class="font-mono">{{ c('quality')?.available ? pctOrDash(c('quality')!.score) : '—' }}</dd></div>
            <div><dt class="text-xs text-gray-500">Weights</dt><dd class="font-mono text-xs">D {{ weights.delivery * 100 }} · P {{ weights.price * 100 }} · Q {{ weights.quality * 100 }}</dd></div>
        </dl>

        <ScoreBreakdown :evaluation="evaluation">
            <template #inputs="{ criterion: cr }">
                <template v-if="cr.key === 'delivery'">
                    <p v-if="cr.inputs?.excluded_without_promised_date">{{ cr.inputs.excluded_without_promised_date }} deliveries are not counted for on-time because their purchase order has no promised delivery date.</p>
                    <div class="overflow-x-auto">
                    <table v-if="cr.inputs?.records?.length" class="mt-1 w-full text-left">
                        <thead><tr class="text-gray-500"><th>Delivery</th><th>PO</th><th>Promised</th><th>Delivered</th><th>On time</th><th class="text-right" title="Lead time the supplier quoted in the bid">Quoted days</th><th class="text-right" title="Days from the day the order was sent to the delivery">Actual days</th></tr></thead>
                        <tbody><tr v-for="r in cr.inputs.records" :key="r.delivery_number"><td>{{ r.delivery_number }}</td><td>{{ r.po_number }}<span v-if="r.bidding" class="block text-[11px] text-gray-400">{{ r.bidding }}</span></td><td>{{ r.promised }}</td><td>{{ r.delivered }}</td><td>{{ r.on_time ? 'Yes' : 'No' }}</td><td class="text-right">{{ r.quoted_lead_days ?? '—' }}</td><td class="text-right">{{ r.actual_lead_days ?? '—' }}</td></tr></tbody>
                    </table>
                    </div>
                    <div class="overflow-x-auto">
                    <table v-if="cr.inputs?.purchase_orders?.length" class="mt-2 w-full text-left">
                        <thead><tr class="text-gray-500"><th>PO</th><th class="text-right">Ordered</th><th class="text-right">Accepted</th></tr></thead>
                        <tbody><tr v-for="r in cr.inputs.purchase_orders" :key="r.po_number"><td>{{ r.po_number }}</td><td class="text-right">{{ r.ordered }}</td><td class="text-right">{{ r.accepted }}</td></tr></tbody>
                    </table>
                    </div>
                </template>
                <template v-if="cr.key === 'price'">
                    <div v-for="cmp in cr.inputs?.comparisons ?? []" :key="cmp.product.id + cmp.basis" class="mt-2">
                        <p class="font-medium text-gray-800">
                            <span class="mr-1 rounded border px-1.5 py-0.5 text-[11px] font-normal" :class="cmp.source === 'bidding' ? 'border-lime-200 bg-lime-50 text-lime-800' : 'border-gray-200 bg-gray-50 text-gray-600'">{{ cmp.source === 'bidding' ? 'Bidding' : 'Purchases' }}</span>
                            {{ cmp.product.name }} ({{ cmp.basis }}): score {{ cmp.score.toFixed(2) }}
                        </p>
                        <ul class="list-disc pl-4">
                            <li v-for="p in cmp.prices" :key="p.supplier_id">
                                <template v-if="cmp.source === 'bidding'">{{ p.supplier }}: {{ mask(p.unit_price.toFixed(2)) }} quoted for {{ p.quantity }} units</template>
                                <template v-else>
                                    {{ p.supplier }}: {{ mask(p.unit_price.toFixed(2)) }} average over {{ p.quantity }} units —
                                    {{ (p.receipts ?? []).map((i: any) => `${i.delivery_number} / PO ${i.po_number} (${i.delivery_date}, ${i.quantity} × ${i.unit_cost.toFixed(2)})`).join(', ') }}
                                </template>
                            </li>
                        </ul>
                    </div>
                </template>
                <div class="overflow-x-auto">
                <table v-if="cr.key === 'quality' && cr.inputs?.records?.length" class="mt-1 w-full text-left">
                    <thead><tr class="text-gray-500"><th>Delivery</th><th>Inspected</th><th>Result</th><th class="text-right">Accepted</th><th class="text-right">Rejected</th><th>Reason</th></tr></thead>
                    <tbody><tr v-for="r in cr.inputs.records" :key="r.delivery_number"><td>{{ r.delivery_number }}</td><td>{{ r.inspected_on }}</td><td>{{ r.result }}</td><td class="text-right">{{ r.accepted }}</td><td class="text-right">{{ r.rejected }}</td><td>{{ r.reason || '—' }}</td></tr></tbody>
                </table>
                </div>
            </template>
        </ScoreBreakdown>
    </div>
</template>
