<script setup lang="ts">
/**
 * Submit Purchase Requests for one or more products. The recommended quantity, AHP score and
 * forecast shown here come from the backend; the server takes its own snapshot on submit.
 */
import { useForm, usePage } from '@inertiajs/vue3';
import { computed, watch } from 'vue';

export interface PrCandidate {
    /** null = "Product Not Listed": not in the Product Catalog (description and unit given instead). */
    product_id: number | null;
    name: string;
    sku: string;
    /** A catalog product's unit (shown only; the server uses the product's), or the unit chosen for an unlisted product. */
    unit?: string | null;
    current_stock: number | null;
    /** Demand forecast for the chosen period, and that period's label (e.g. "6 Months"). */
    forecast: number | null;
    forecast_period?: string | null;
    ahp_score: number | null;
    classification: string | null;
    recommended_quantity: number | null;
    /** From the New Purchase Request form: the staff's description and the quantity they entered. */
    description?: string | null;
    requested_quantity?: number | null;
}

const props = defineProps<{ open: boolean; candidates: PrCandidate[]; forecastMonths?: number }>();
const emit = defineEmits<{ close: []; submitted: [] }>();

const page = usePage();
const teamSlug = computed(() => (page.props as any).currentTeam?.slug || (page.props as any).team?.slug || '');

const form = useForm({
    lines: [] as { product_id: number | null; quantity: number; product_description: string | null; unit?: string; justification: string }[],
    draft: false,
    forecast_months: null as number | null,
});

watch(
    () => [props.open, props.candidates],
    () => {
        if (!props.open) return;
        form.clearErrors();
        form.draft = false;
        form.forecast_months = props.forecastMonths ?? null;
        form.lines = props.candidates.map((c) => ({
            product_id: c.product_id,
            // The quantity typed on the New Purchase Request form, else the AHP recommendation, else 1.
            quantity: c.requested_quantity && c.requested_quantity > 0 ? c.requested_quantity : c.recommended_quantity && c.recommended_quantity > 0 ? c.recommended_quantity : 1,
            product_description: c.description ?? null,
            // Only an unlisted product sends its unit; a catalog product's unit is taken by the server.
            ...(c.product_id === null && c.unit ? { unit: c.unit } : {}),
            justification: c.recommended_quantity
                ? `AHP reorder recommendation: available ${c.current_stock ?? '—'}, forecast demand ${c.forecast ?? 'n/a'} for the next ${c.forecast_period ?? 'period'}.`
                : '',
        }));
    },
    { immediate: true },
);

const err = (i: number, field: string) => (form.errors as Record<string, string>)[`lines.${i}.${field}`];

const submit = (draft: boolean) => {
    // A description entered on the New Purchase Request form stays required here.
    form.clearErrors();
    const missing = form.lines.findIndex((l) => l.product_description !== null && !l.product_description.trim());
    if (missing >= 0) {
        form.setError(`lines.${missing}.product_description` as any, 'Describe the product you need to purchase.');
        return;
    }
    form.draft = draft;
    form.post(`/${teamSlug.value}/procurement/requests`, {
        preserveScroll: true,
        onSuccess: () => emit('submitted'),
    });
};
</script>

<template>
    <div v-if="open" class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4" @click.self="emit('close')">
        <div class="flex max-h-[92vh] w-full max-w-3xl flex-col overflow-hidden rounded-xl border border-gray-200 bg-white" role="dialog" aria-modal="true" aria-labelledby="pr-create-title">
            <div class="border-b border-gray-100 px-6 py-4">
                <h3 id="pr-create-title" class="text-base font-semibold text-gray-900">Create purchase request</h3>
                <p class="text-xs text-gray-500">One request per product. An administrator must approve it before a purchase order can be made. Nothing is ordered and stock does not change yet.</p>
            </div>
            <div class="space-y-3 overflow-y-auto px-6 py-4">
                <p v-if="form.errors.lines" class="rounded-xl border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700">{{ form.errors.lines }}</p>
                <p v-if="(form.errors as any).error" class="rounded-xl border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700">{{ (form.errors as any).error }}</p>
                <div v-for="(line, i) in form.lines" :key="line.product_id ?? `unlisted-${i}`" class="rounded-xl border border-gray-200 p-3">
                    <div class="flex flex-wrap items-baseline justify-between gap-2">
                        <div>
                            <template v-if="line.product_id === null">
                                <span class="rounded-full border border-amber-200 bg-amber-50 px-2 py-0.5 text-[11px] font-medium text-amber-700">Product not listed</span>
                                <div class="mt-1 text-xs text-gray-500">Not in the Product Catalog: nothing is added to inventory until the goods are received.</div>
                            </template>
                            <template v-else>
                                <div class="font-medium text-gray-900">{{ candidates[i]?.name }}</div>
                                <div class="font-mono text-xs text-gray-500">{{ candidates[i]?.sku }}</div>
                            </template>
                        </div>
                        <dl v-if="line.product_id !== null" class="flex gap-4 text-xs text-gray-600">
                            <div><dt class="text-gray-400">Available</dt><dd class="font-mono">{{ candidates[i]?.current_stock ?? '—' }}</dd></div>
                            <div><dt class="text-gray-400">Forecast, next {{ candidates[i]?.forecast_period ?? 'period' }}</dt><dd class="font-mono">{{ candidates[i]?.forecast ?? 'Insufficient data' }}</dd></div>
                            <div><dt class="text-gray-400">AHP score</dt><dd class="font-mono">{{ candidates[i]?.ahp_score != null ? candidates[i]!.ahp_score!.toFixed(2) : 'Incomplete' }}</dd></div>
                            <div><dt class="text-gray-400">Recommended</dt><dd class="font-mono">{{ candidates[i]?.recommended_quantity ?? '—' }}</dd></div>
                        </dl>
                    </div>
                    <div class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-4">
                        <div v-if="line.product_description !== null" class="sm:col-span-4">
                            <label class="field-label" :for="`pr-d-${i}`">Product description</label>
                            <textarea :id="`pr-d-${i}`" v-model="line.product_description" rows="2" maxlength="1000" class="field-control w-full" required></textarea>
                            <p v-if="err(i, 'product_description')" class="mt-1 text-xs text-rose-600">{{ err(i, 'product_description') }}</p>
                        </div>
                        <div>
                            <label class="field-label" :for="`pr-q-${i}`">Requested quantity<template v-if="candidates[i]?.unit"> ({{ candidates[i]!.unit }})</template></label>
                            <input :id="`pr-q-${i}`" v-model.number="line.quantity" type="number" min="1" step="1" class="field-control w-full" />
                            <p v-if="err(i, 'quantity')" class="mt-1 text-xs text-rose-600">{{ err(i, 'quantity') }}</p>
                        </div>
                        <div class="sm:col-span-3">
                            <label class="field-label" :for="`pr-j-${i}`">Purchase justification</label>
                            <textarea :id="`pr-j-${i}`" v-model="line.justification" rows="2" class="field-control w-full" required></textarea>
                            <p v-if="err(i, 'justification')" class="mt-1 text-xs text-rose-600">{{ err(i, 'justification') }}</p>
                            <p v-if="err(i, 'product_id')" class="mt-1 text-xs text-rose-600">{{ err(i, 'product_id') }}</p>
                        </div>
                    </div>
                </div>
            </div>
            <div class="flex justify-end gap-2 border-t border-gray-100 bg-gray-50/50 px-6 py-3">
                <button type="button" class="secondary-button" @click="emit('close')">Cancel</button>
                <button type="button" class="secondary-button" :disabled="form.processing" @click="submit(true)">Save as draft</button>
                <button type="button" class="brand-button" :disabled="form.processing || !form.lines.length" @click="submit(false)">Submit for approval</button>
            </div>
        </div>
    </div>
</template>
