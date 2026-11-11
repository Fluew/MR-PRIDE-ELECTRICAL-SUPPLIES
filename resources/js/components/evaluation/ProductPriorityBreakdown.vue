<script setup lang="ts">
/**
 * "View Calculation" for one AHP Product Prioritization result (from ProductPrioritizer).
 * Shared by Inventory and Reports & Analytics so both show the same backend numbers.
 */
import ScoreBreakdown, { type Evaluation } from '@/components/evaluation/ScoreBreakdown.vue';

export interface ReorderRecommendation {
    needed: boolean;
    label: string;
    suggested_quantity: number;
    reorder_level: number;
    reorder_quantity: number;
    forecast: number | null;
    shortfall: number | null;
    basis: string;
}

/** SMA forecast for the forecasting period staff chose (3 Months, 6 Months, 1 Year). */
export interface PriorityForecast {
    months: number;
    period: string;
    monthly: number | null;
    period_total: number | null;
    reason: string | null;
}

export interface ProductPriority extends Evaluation {
    product: { id: number; name: string; sku: string; unit: string };
    available_stock?: number;
    forecast?: PriorityForecast;
    reorder: ReorderRecommendation;
}

const props = defineProps<{ priority: ProductPriority }>();

const input = (key: string): Record<string, any> => props.priority.criteria.find((c) => c.key === key)?.inputs ?? {};
const has = (key: string) => props.priority.criteria.find((c) => c.key === key)?.available ?? false;
</script>

<template>
    <div>
        <dl class="mb-4 grid grid-cols-2 gap-3 text-sm sm:grid-cols-4">
            <div><dt class="text-xs text-gray-500">Available stock</dt><dd class="font-mono">{{ input('stock').available }} {{ priority.product.unit }}</dd></div>
            <div>
                <dt class="text-xs text-gray-500">Forecast, next {{ priority.forecast?.period ?? 'month' }}</dt>
                <dd class="font-mono">{{ priority.forecast?.period_total ?? input('demand').forecast ?? 'Insufficient data' }}<span v-if="priority.forecast?.monthly != null" class="text-xs text-gray-500"> ({{ priority.forecast.monthly }}/month)</span></dd>
            </div>
            <div><dt class="text-xs text-gray-500">Units moved ({{ input('movement').months }} mo)</dt><dd class="font-mono">{{ has('movement') ? input('movement').units : 'Insufficient data' }}</dd></div>
            <div><dt class="text-xs text-gray-500">Verified suppliers</dt><dd class="font-mono">{{ input('supplier_availability').verified ? `${input('supplier_availability').available} of ${input('supplier_availability').verified} available` : 'None recorded' }}</dd></div>
        </dl>

        <ScoreBreakdown :evaluation="priority">
            <template #inputs="{ criterion }">
                <div v-if="criterion.key === 'demand' && criterion.inputs?.months?.length" class="font-mono">
                    SMA inputs: {{ criterion.inputs.months.map((m: any) => `${m.month} ${m.units}`).join(', ') }}
                    <span v-if="criterion.inputs.forecast !== null"> → ({{ criterion.inputs.months.map((m: any) => m.units).join(' + ') }}) / {{ criterion.inputs.months.length }} ≈ {{ criterion.inputs.forecast }}</span>
                    <span v-if="criterion.inputs.max_forecast !== null">; highest forecast among active products: {{ criterion.inputs.max_forecast }}</span>
                </div>
                <div v-if="criterion.key === 'stock'">
                    On hand {{ criterion.inputs?.on_hand }} − reserved {{ criterion.inputs?.reserved }} = available {{ criterion.inputs?.available }}.
                    Maximum stock reference: {{ criterion.inputs?.max_stock }} (the largest available stock among active products{{ criterion.inputs?.max_stock_product ? `, ${criterion.inputs.max_stock_product}` : '' }}).
                    Lower stock gives a higher score, so it raises replenishment priority.
                </div>
                <div v-if="criterion.key === 'movement' && criterion.available">
                    {{ criterion.inputs?.units }} units from {{ criterion.inputs?.record_count }} records in the last {{ criterion.inputs?.months }} complete months; highest among active products: {{ criterion.inputs?.max_units }}.
                </div>
                <ul v-if="criterion.key === 'supplier_availability' && criterion.inputs?.suppliers?.length" class="list-disc pl-4">
                    <li v-for="s in criterion.inputs.suppliers" :key="s.name">{{ s.name }}: {{ s.is_available ? 'available' : 'not available' }} (verified {{ s.verified_at }})</li>
                </ul>
            </template>
        </ScoreBreakdown>

        <div class="mt-4 rounded-xl border px-3 py-2 text-sm" :class="priority.reorder.needed ? 'border-amber-200 bg-amber-50 text-amber-900' : 'border-gray-200 bg-gray-50 text-gray-700'">
            <p class="font-medium">
                Reorder recommendation: {{ priority.reorder.label }}<template v-if="priority.reorder.needed"> — {{ priority.reorder.suggested_quantity }} {{ priority.product.unit }}</template>
            </p>
            <p class="mt-0.5 text-xs">{{ priority.reorder.basis }}</p>
            <p class="mt-0.5 text-xs opacity-75">Advice only; purchases still go through Procurement.</p>
        </div>
    </div>
</template>
