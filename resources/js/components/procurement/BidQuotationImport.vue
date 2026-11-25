<script setup lang="ts">
import { AlertCircle, Clock } from '@lucide/vue';
import { computed, ref, watch } from 'vue';

/**
 * Reads a supplier's quotation for "Record quotation", with the purchase order import logic: a
 * document (PDF, PNG, JPG; OCR for scans and photos) or a CSV with ORDER DATE, PURCHASE ORDER NO.,
 * QTY ORDERED, SUPPLIER, ITEM/SKU Number, Description, UNIT COST, U/M, EXTENDED COST.
 *
 * The values are shown to check. A bid is one product line, so the user picks the row that is the
 * supplier's offer for the requested item (suggested by SKU or description); "Use these values"
 * fills the quotation form. Nothing is saved here.
 */
type Field = 'quantity' | 'item_sku' | 'description' | 'unit_cost' | 'unit_of_measure' | 'extended_cost';

interface ReadRow {
    quantity: number | null;
    item_sku: string;
    description: string;
    unit_cost: number | null;
    unit_of_measure: string;
    document_extended_cost: number | null;
    issues: Partial<Record<Field, string>>;
}
interface Row extends Omit<ReadRow, 'quantity' | 'unit_cost'> {
    quantity: string;
    unit_cost: string;
}
export interface QuotationValues {
    supplier_id: number | '';
    unit_price: number;
    quantity: number;
    quotation_date: string;
    quotation_reference: string;
    notes: string;
}

const props = defineProps<{
    file: File;
    readUrl: string;
    suppliers: { supplier_id: number; name: string }[];
    requested: { item: string | null; sku: string | null; quantity: number | null; unit: string | null };
}>();
const emit = defineEmits<{ apply: [values: QuotationValues]; cancel: [] }>();

const reading = ref(true);
const readError = ref<string | null>(null);
const notes = ref<string[]>([]);
const ocr = ref(false);
const method = ref('');

const quotationDate = ref('');
const dateOriginal = ref<string | null>(null);
const dateIssue = ref<string | null>(null);
const reference = ref('');
const referenceIssue = ref<string | null>(null);
const supplierId = ref<number | ''>('');
const supplierName = ref<string | null>(null);
const supplierIssue = ref<string | null>(null);
const rows = ref<Row[]>([]);
const chosen = ref<number | null>(null);

async function read() {
    reading.value = true;
    readError.value = null;
    const body = new FormData();
    body.append('file', props.file);
    try {
        const response = await fetch(props.readUrl, {
            method: 'POST',
            body,
            credentials: 'same-origin',
            headers: {
                'X-CSRF-TOKEN': (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement | null)?.content ?? '',
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
        });
        const data = await response.json().catch(() => ({}));
        if (!response.ok || !data.success) {
            readError.value =
                response.status === 413
                    ? 'The file is larger than 25 MB. Please upload a smaller file.'
                    : data.message || 'The file could not be read. Please check it and try again.';
            return;
        }
        ocr.value = !!data.ocr;
        method.value = data.method ?? '';
        notes.value = data.notes ?? [];
        quotationDate.value = data.header.quotation_date.value ?? '';
        dateOriginal.value = data.header.quotation_date.original ?? null;
        dateIssue.value = data.header.quotation_date.issue ?? null;
        reference.value = data.header.reference.value ?? '';
        referenceIssue.value = data.header.reference.issue ?? null;
        supplierName.value = data.header.supplier.name ?? null;
        supplierIssue.value = data.header.supplier.issue ?? null;
        supplierId.value = props.suppliers.some((s) => s.supplier_id === data.header.supplier.supplier_id) ? data.header.supplier.supplier_id : '';
        rows.value = (data.rows as ReadRow[]).map((r) => ({
            ...r,
            issues: { ...(r.issues ?? {}) },
            quantity: r.quantity === null ? '' : String(r.quantity),
            unit_cost: r.unit_cost === null ? '' : r.unit_cost.toFixed(2),
        }));
        chosen.value = data.suggested_row ?? null;
    } catch {
        readError.value = 'The file could not be sent because of a network problem. Please check your connection and try again.';
    } finally {
        reading.value = false;
    }
}
watch(() => props.file, read, { immediate: true });

const plain = (v: string) => v.trim().replace(/,/g, '');
const quantityOf = (r: Row) => (/^\d+$/.test(plain(r.quantity)) && Number(plain(r.quantity)) >= 1 ? Number(plain(r.quantity)) : null);
const costOf = (r: Row) => (/^\d+(\.\d{1,2})?$/.test(plain(r.unit_cost)) && Number(plain(r.unit_cost)) > 0 ? Number(plain(r.unit_cost)) : null);
/** EXTENDED COST = QUANTITY × UNIT COST. */
const extendedOf = (r: Row) => {
    const q = quantityOf(r);
    const c = costOf(r);
    return q !== null && c !== null ? Math.round(q * c * 100) / 100 : null;
};
const differs = (r: Row) => {
    const e = extendedOf(r);
    return e !== null && r.document_extended_cost !== null && Math.abs(e - r.document_extended_cost) > 0.01;
};
const money = (v: number | null) => (v === null ? '—' : v.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }));

function edit(r: Row, field: 'quantity' | 'unit_cost', value: string) {
    r[field] = value;
    delete r.issues[field];
    delete r.issues.extended_cost;
}
function messages(r: Row): string[] {
    const list = (Object.entries(r.issues) as [Field, string][]).filter(([f]) => f !== 'extended_cost').map(([, m]) => m);
    if (quantityOf(r) === null && !r.issues.quantity) list.push('QTY must be a whole number of at least 1.');
    if (costOf(r) === null && !r.issues.unit_cost) list.push('UNIT COST must be an amount above 0 with up to 2 decimals.');
    if (differs(r)) list.push(`The document shows ${money(r.document_extended_cost)}; QTY × UNIT COST = ${money(extendedOf(r))}.`);
    else if (r.issues.extended_cost) list.push(r.issues.extended_cost);
    return list;
}

const selected = computed(() => (chosen.value === null ? null : (rows.value[chosen.value] ?? null)));
const errors = computed(() => {
    const e: string[] = [];
    if (supplierId.value === '') e.push('Choose the invited supplier who sent this quotation.');
    if (!selected.value) e.push('Choose the product row that is the offer for the requested item.');
    else {
        if (quantityOf(selected.value) === null) e.push('The chosen row needs a valid QTY.');
        if (costOf(selected.value) === null) e.push('The chosen row needs a valid UNIT COST.');
    }
    if (quotationDate.value && quotationDate.value > new Date().toISOString().slice(0, 10)) e.push('The quotation date cannot be in the future.');
    return e;
});
const showErrors = ref(false);

function apply() {
    showErrors.value = true;
    if (errors.value.length || !selected.value) return;
    const r = selected.value;
    const unitNote = props.requested.unit && r.unit_of_measure && r.unit_of_measure.toLowerCase() !== props.requested.unit.toLowerCase()
        ? ` Quoted per ${r.unit_of_measure} (requested in ${props.requested.unit}).`
        : '';
    emit('apply', {
        supplier_id: supplierId.value,
        unit_price: costOf(r)!,
        quantity: quantityOf(r)!,
        quotation_date: quotationDate.value,
        quotation_reference: reference.value.trim(),
        notes: `Read from ${props.file.name}: ${[r.item_sku, r.description].filter(Boolean).join(' · ')}.${unitNote}`.slice(0, 2000),
    });
}
</script>

<template>
    <div class="space-y-4" data-test="bid-quotation-import">
        <div v-if="reading" class="py-8 text-center text-xs text-gray-500" aria-live="polite">
            <Clock class="mx-auto mb-1 h-5 w-5 animate-spin text-[#F8B803]" />
            Reading {{ file.name }}… Scanned documents and photos can take a minute.
        </div>

        <div v-else-if="readError" class="flex items-start gap-2 rounded-xl border border-red-200 bg-red-50 p-3 text-xs text-red-700" role="alert">
            <AlertCircle class="mt-0.5 h-4 w-4 shrink-0 text-red-500" />
            <span>{{ readError }}</span>
        </div>

        <template v-else>
            <ul v-if="notes.length" class="space-y-1 rounded-xl border border-amber-200 bg-amber-50/60 p-3 text-xs text-amber-800">
                <li v-for="(n, i) in notes" :key="i" class="flex items-start gap-1.5"><AlertCircle class="mt-0.5 h-3.5 w-3.5 shrink-0" />{{ n }}</li>
            </ul>

            <!-- ORDER DATE → quotation date, PURCHASE ORDER NO. → supplier's quotation no., SUPPLIER -->
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                <div>
                    <label for="bq_date" class="field-label">Quotation date (ORDER DATE)</label>
                    <input id="bq_date" v-model="quotationDate" type="date" class="field-control w-full" :class="dateIssue ? 'border-amber-400' : ''" @input="dateIssue = null" />
                    <p v-if="dateOriginal" class="mt-1 text-[11px] text-gray-500">On the document: {{ dateOriginal }}</p>
                    <p v-if="dateIssue" class="mt-1 text-[11px] text-amber-700">{{ dateIssue }}</p>
                </div>
                <div>
                    <label for="bq_ref" class="field-label">Quotation no. (PURCHASE ORDER NO.)</label>
                    <input id="bq_ref" v-model="reference" type="text" maxlength="100" class="field-control w-full font-mono" :class="referenceIssue ? 'border-amber-400' : ''" @input="referenceIssue = null" />
                    <p v-if="referenceIssue" class="mt-1 text-[11px] text-amber-700">{{ referenceIssue }}</p>
                </div>
                <div>
                    <label for="bq_supplier" class="field-label">SUPPLIER</label>
                    <select id="bq_supplier" v-model="supplierId" class="field-control w-full" :class="supplierIssue ? 'border-amber-400' : ''" @change="supplierIssue = null">
                        <option value="">— Choose invited supplier —</option>
                        <option v-for="s in suppliers" :key="s.supplier_id" :value="s.supplier_id">{{ s.name }}</option>
                    </select>
                    <p v-if="supplierName" class="mt-1 text-[11px] text-gray-500">On the document: {{ supplierName }}</p>
                    <p v-if="supplierIssue" class="mt-1 text-[11px] text-amber-700">{{ supplierIssue }}</p>
                </div>
            </div>

            <p class="text-xs text-gray-600">
                Requested: <strong>{{ requested.item ?? '—' }}</strong><span v-if="requested.sku" class="font-mono"> ({{ requested.sku }})</span> ·
                {{ requested.quantity ?? '—' }} {{ requested.unit }}. Choose the row that is the supplier's offer for it.
            </p>

            <div class="overflow-x-auto rounded-xl border border-gray-200">
                <table class="w-full min-w-[760px] text-left text-xs">
                    <caption class="sr-only">Products read from the supplier's quotation</caption>
                    <thead class="bg-gray-50 text-gray-600">
                        <tr>
                            <th scope="col" class="w-10 p-2"><span class="sr-only">Use</span></th>
                            <th scope="col" class="w-24 p-2 font-semibold whitespace-nowrap">QTY</th>
                            <th scope="col" class="w-28 p-2 font-semibold">ITEM/SKU Number</th>
                            <th scope="col" class="p-2 font-semibold">Description</th>
                            <th scope="col" class="w-28 p-2 text-right font-semibold">UNIT COST</th>
                            <th scope="col" class="w-16 p-2 font-semibold">U/M</th>
                            <th scope="col" class="w-28 p-2 text-right font-semibold">EXTENDED COST</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <tr v-if="!rows.length">
                            <td colspan="7" class="p-4 text-center text-gray-500">No products were read. Close this and enter the quotation by hand.</td>
                        </tr>
                        <tr v-for="(r, i) in rows" :key="i" :class="chosen === i ? 'bg-[#4b5320]/5' : ''" data-test="bq-row">
                            <td class="p-2 align-top">
                                <input v-model="chosen" type="radio" name="bq_row" :value="i" :aria-label="`Use product ${i + 1}`" class="mt-1.5" />
                            </td>
                            <td class="p-2 align-top">
                                <input :value="r.quantity" type="text" inputmode="numeric" class="w-full rounded-md border px-2 py-1.5"
                                    :class="quantityOf(r) === null ? 'border-red-400' : r.issues.quantity ? 'border-amber-400' : 'border-gray-200'"
                                    :aria-label="`QTY, product ${i + 1}`" @input="edit(r, 'quantity', ($event.target as HTMLInputElement).value)" />
                            </td>
                            <td class="p-2 align-top font-mono">{{ r.item_sku || '—' }}</td>
                            <td class="p-2 align-top">
                                {{ r.description || '—' }}
                                <ul v-if="messages(r).length" class="mt-1 space-y-0.5 text-amber-700">
                                    <li v-for="(m, k) in messages(r)" :key="k">{{ m }}</li>
                                </ul>
                            </td>
                            <td class="p-2 align-top">
                                <input :value="r.unit_cost" type="text" inputmode="decimal" class="w-full rounded-md border px-2 py-1.5 text-right"
                                    :class="costOf(r) === null ? 'border-red-400' : r.issues.unit_cost ? 'border-amber-400' : 'border-gray-200'"
                                    :aria-label="`UNIT COST, product ${i + 1}`" @input="edit(r, 'unit_cost', ($event.target as HTMLInputElement).value)" />
                            </td>
                            <td class="p-2 align-top">{{ r.unit_of_measure || '—' }}</td>
                            <td class="p-2 text-right align-top font-semibold tabular-nums" :class="differs(r) ? 'text-amber-800' : 'text-gray-900'">{{ money(extendedOf(r)) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p class="text-xs text-gray-500">{{ rows.length }} product(s)<template v-if="ocr"> · read by OCR: compare every value with the document</template><template v-else-if="method === 'csv'"> · read from CSV</template></p>

            <ul v-if="showErrors && errors.length" class="space-y-0.5 rounded-xl border border-red-200 bg-red-50 p-3 text-xs text-red-700" role="alert">
                <li v-for="(e, i) in errors" :key="i">{{ e }}</li>
            </ul>
        </template>

        <div class="flex justify-end gap-2 border-t border-gray-100 pt-3">
            <button type="button" class="secondary-button" @click="emit('cancel')">Cancel</button>
            <button v-if="!reading && !readError && rows.length" type="button" class="brand-button" data-test="bq-apply" @click="apply">Use these values</button>
        </div>
    </div>
</template>
