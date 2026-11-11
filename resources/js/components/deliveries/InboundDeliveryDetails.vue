<script setup lang="ts">
/**
 * View one inbound P.O. delivery: purchase order information, the received products with their
 * quality inspection, and the history (confirmation, corrections with old → new values, cancellation).
 * Loaded from the server each time it opens, so it always shows the saved records.
 */
import { ref, watch } from 'vue';
import { X, Pencil, Trash2, ExternalLink } from '@lucide/vue';
import { useCurrency } from '@/composables/useCurrency';
import type { DeliveryFull } from '@/types/inboundDelivery';

const props = defineProps<{ deliveryId: number | null; teamSlug: string; reloadKey?: number }>();
const emit = defineEmits<{ close: []; correct: [delivery: DeliveryFull]; delete: [delivery: DeliveryFull]; openPo: [poId: number]; post: [itemId: number, number: string] }>();

const { formatMoney } = useCurrency();
const delivery = ref<DeliveryFull | null>(null);
const loading = ref(false);
const error = ref('');

const load = async () => {
    if (!props.deliveryId) return;
    loading.value = true;
    error.value = '';
    try {
        const res = await fetch(`/${props.teamSlug}/deliveries/inbound/${props.deliveryId}`, { headers: { Accept: 'application/json' } });
        if (!res.ok) throw new Error(`HTTP ${res.status}`);
        delivery.value = await res.json();
    } catch {
        error.value = 'The delivery could not be loaded. Close this window and try again.';
    } finally {
        loading.value = false;
    }
};
watch(() => [props.deliveryId, props.reloadKey], () => { delivery.value = null; load(); }, { immediate: true });
defineExpose({ load });

const fmtDate = (d: string | null | undefined) => {
    if (!d) return '—';
    const date = new Date(d.length <= 10 ? `${d}T00:00:00` : d);
    return isNaN(date.getTime()) ? d : date.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
};
const fmtDateTime = (d: string | null | undefined) => {
    if (!d) return '—';
    const date = new Date(d);
    return isNaN(date.getTime()) ? d : date.toLocaleString('en-US', { month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit' });
};
const money = (v: number | null) => (v === null ? '—' : formatMoney(v));
const statusClass: Record<string, string> = {
    Passed: 'border-emerald-200 bg-emerald-50 text-emerald-700',
    'Partially Passed': 'border-amber-200 bg-amber-50 text-amber-800',
    Failed: 'border-rose-200 bg-rose-50 text-rose-700',
    'Pending Inspection': 'border-gray-200 bg-gray-50 text-gray-700',
};
const fieldLabels: Record<string, string> = { received_quantity: 'received', accepted_quantity: 'accepted', rejected_quantity: 'rejected', inspection_remarks: 'remarks' };
const changedFields = (from: Record<string, unknown>, to: Record<string, unknown>) =>
    Object.keys(to).filter((k) => (from[k] ?? null) !== (to[k] ?? null)).map((k) => `${fieldLabels[k] ?? k} ${from[k] ?? '—'} → ${to[k] ?? '—'}`);
</script>

<template>
    <div v-if="deliveryId" class="fixed inset-0 z-50 flex items-center justify-center bg-black/70 p-4" @click.self="emit('close')">
        <div class="flex max-h-[92vh] w-full max-w-6xl flex-col overflow-hidden rounded-xl border border-gray-200 bg-card shadow-xl" role="dialog" aria-modal="true" aria-labelledby="inbound-details-title" data-test="inbound-details">
            <div class="flex items-start justify-between gap-3 border-b border-gray-200 px-6 py-4">
                <div>
                    <h3 id="inbound-details-title" class="text-lg font-semibold text-foreground">Delivery {{ delivery?.delivery_number ?? '' }}</h3>
                    <p v-if="delivery" class="mt-0.5 text-xs text-muted-foreground">
                        Confirmed by {{ delivery.confirmed_by ?? '—' }} on {{ fmtDateTime(delivery.confirmed_at) }}
                    </p>
                </div>
                <button type="button" class="rounded-lg p-1 text-muted-foreground hover:bg-gray-100" aria-label="Close" @click="emit('close')"><X class="size-5" /></button>
            </div>

            <div class="min-h-0 flex-1 space-y-6 overflow-y-auto px-6 py-5">
                <p v-if="loading" class="text-sm text-muted-foreground">Loading…</p>
                <p v-else-if="error" class="text-sm text-rose-600" role="alert">{{ error }}</p>
                <template v-else-if="delivery">
                    <div v-if="delivery.status === 'cancelled'" class="rounded-xl border border-rose-200 bg-rose-50 p-3.5 text-sm text-rose-800">
                        Cancelled by {{ delivery.cancelled_by ?? '—' }} on {{ fmtDateTime(delivery.cancelled_at) }}: {{ delivery.cancellation_reason }}.
                        Its stock was taken back out; the record is kept for the audit trail.
                    </div>

                    <section class="grid grid-cols-2 gap-3 lg:grid-cols-6">
                        <div class="rounded-xl border border-gray-200 px-4 py-3"><div class="text-xs text-muted-foreground">Delivery Date</div><div class="text-sm font-medium">{{ fmtDate(delivery.delivery_date) }}</div></div>
                        <div class="rounded-xl border border-gray-200 px-4 py-3"><div class="text-xs text-muted-foreground">Delivery #</div><div class="font-mono text-sm font-semibold">{{ delivery.delivery_number }}</div></div>
                        <div class="rounded-xl border border-gray-200 px-4 py-3"><div class="text-xs text-muted-foreground">Purchase Order Number</div><div class="font-mono text-sm font-medium">{{ delivery.purchase_order_number ?? '—' }}</div></div>
                        <div class="rounded-xl border border-gray-200 px-4 py-3"><div class="text-xs text-muted-foreground">Order Date</div><div class="text-sm font-medium">{{ fmtDate(delivery.order_date) }}</div></div>
                        <div class="rounded-xl border border-gray-200 px-4 py-3"><div class="text-xs text-muted-foreground">Supplier</div><div class="text-sm font-medium">{{ delivery.supplier ?? '—' }}</div></div>
                        <div class="rounded-xl border border-gray-200 px-4 py-3"><div class="text-xs text-muted-foreground">Supplier doc. no.</div><div class="text-sm font-medium">{{ delivery.supplier_document_number ?? '—' }}</div></div>
                    </section>

                    <section>
                        <h4 class="mb-2 text-sm font-semibold text-foreground">Received products</h4>
                        <div class="overflow-x-auto rounded-xl border border-gray-200">
                            <table class="w-full text-left text-xs">
                                <thead class="border-b border-gray-200 bg-gray-50 font-semibold text-muted-foreground">
                                    <tr>
                                        <th class="whitespace-nowrap px-3 py-2">Item/SKU Number</th>
                                        <th class="px-3 py-2">Description</th>
                                        <th class="whitespace-nowrap px-3 py-2 text-right">Ordered</th>
                                        <th class="whitespace-nowrap px-3 py-2 text-right">Received</th>
                                        <th class="whitespace-nowrap px-3 py-2 text-right">Accepted</th>
                                        <th class="whitespace-nowrap px-3 py-2 text-right">Rejected</th>
                                        <th class="whitespace-nowrap px-3 py-2 text-right">Unit Cost</th>
                                        <th class="whitespace-nowrap px-3 py-2">U/M</th>
                                        <th class="whitespace-nowrap px-3 py-2 text-right">Extended Cost</th>
                                        <th class="whitespace-nowrap px-3 py-2">Quality</th>
                                        <th class="min-w-40 px-3 py-2">Inspection Remarks</th>
                                        <th class="whitespace-nowrap px-3 py-2">Inventory</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100">
                                    <tr v-for="i in delivery.items" :key="i.id" :class="i.status === 'Cancelled' ? 'text-gray-400' : ''">
                                        <td class="whitespace-nowrap px-3 py-2 font-mono">{{ i.item_sku || '—' }}</td>
                                        <td class="min-w-48 px-3 py-2">{{ i.description }}</td>
                                        <td class="px-3 py-2 text-right">{{ i.ordered_quantity ?? '—' }}</td>
                                        <td class="px-3 py-2 text-right font-medium">{{ i.received_quantity }}</td>
                                        <td class="px-3 py-2 text-right">{{ i.accepted_quantity ?? '—' }}</td>
                                        <td class="px-3 py-2 text-right">{{ i.rejected_quantity ?? '—' }}</td>
                                        <td class="whitespace-nowrap px-3 py-2 text-right">{{ money(i.unit_cost) }}</td>
                                        <td class="px-3 py-2">{{ i.unit_of_measure || '—' }}</td>
                                        <td class="whitespace-nowrap px-3 py-2 text-right">{{ money(i.extended_cost) }}</td>
                                        <td class="whitespace-nowrap px-3 py-2">
                                            <span class="inline-flex rounded-md border px-2 py-0.5 font-medium" :class="statusClass[i.quality_status] ?? statusClass['Pending Inspection']">{{ i.quality_status }}</span>
                                            <div v-if="(i.rejected_quantity ?? 0) > 0" class="mt-0.5 text-muted-foreground">{{ i.resolution_status ? `Rejected units ${i.resolution_status.replace('_', ' ')}` : 'Rejected units unresolved' }}</div>
                                        </td>
                                        <td class="px-3 py-2 text-muted-foreground">{{ i.inspection_remarks || '—' }}</td>
                                        <td class="whitespace-nowrap px-3 py-2">
                                            <div v-if="i.product" class="font-mono">{{ i.product.sku }}</div>
                                            <div v-if="i.posted_quantity > 0" class="text-emerald-700">{{ i.posted_quantity }} in stock</div>
                                            <button v-else-if="i.can_post" type="button" class="mt-0.5 rounded-lg border border-[#4b5320] px-2 py-0.5 font-medium text-[#4b5320] hover:bg-gray-50" @click="emit('post', i.id, delivery.delivery_number)">
                                                Post {{ i.accepted_quantity }} to inventory
                                            </button>
                                            <button v-if="i.purchase_order_id" type="button" class="mt-0.5 inline-flex items-center gap-1 text-brand hover:underline" @click="emit('openPo', i.purchase_order_id)">
                                                <ExternalLink class="size-3" /> Open PO
                                            </button>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </section>

                    <section v-if="delivery.history.length">
                        <h4 class="mb-2 text-sm font-semibold text-foreground">History</h4>
                        <ol class="space-y-2">
                            <li v-for="h in delivery.history" :key="h.id" class="rounded-xl border border-gray-200 px-4 py-2.5 text-xs">
                                <div class="flex flex-wrap justify-between gap-2">
                                    <span class="font-medium text-foreground">{{ h.by }}</span>
                                    <span class="text-muted-foreground">{{ fmtDateTime(h.at) }}</span>
                                </div>
                                <p class="mt-0.5 text-gray-700">{{ h.description }}</p>
                                <ul v-if="h.changes" class="mt-1 list-disc pl-4 text-muted-foreground">
                                    <li v-if="h.changes.delivery_date">Delivery date {{ h.changes.delivery_date.from }} → {{ h.changes.delivery_date.to }}</li>
                                    <li v-if="h.changes.supplier_document_number">Supplier doc. {{ h.changes.supplier_document_number.from ?? '—' }} → {{ h.changes.supplier_document_number.to ?? '—' }}</li>
                                    <li v-for="(l, k) in h.changes.lines ?? []" :key="k">{{ l.product }}: {{ changedFields(l.from, l.to).join(', ') || 'no quantity change' }}</li>
                                </ul>
                            </li>
                        </ol>
                    </section>
                </template>
            </div>

            <div class="flex flex-col-reverse gap-3 border-t border-gray-200 px-6 py-4 sm:flex-row sm:items-center sm:justify-end">
                <button type="button" class="secondary-button" @click="emit('close')">Close</button>
                <button v-if="delivery?.can_delete" type="button" class="inline-flex min-h-10 items-center justify-center gap-2 rounded-xl border border-rose-200 px-4 py-2 text-sm font-semibold text-rose-600 hover:bg-rose-50" data-test="delete-delivery" @click="emit('delete', delivery!)">
                    <Trash2 class="size-4" /> Delete
                </button>
                <button v-if="delivery && delivery.status !== 'cancelled'" type="button" class="brand-button" data-test="correct-delivery" @click="emit('correct', delivery!)">
                    <Pencil class="size-4" /> Correct details
                </button>
            </div>
        </div>
    </div>
</template>
