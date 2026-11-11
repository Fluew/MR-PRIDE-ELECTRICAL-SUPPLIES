<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { AlertCircle, Clock, Plus, Trash2 } from '@lucide/vue';
import { computed, ref, watch } from 'vue';

/**
 * Purchase order document import (PDF, PNG, JPG): the server reads the document (OCR for scans
 * and photos) and returns ORDER DATE, PURCHASE ORDER NO., SUPPLIER and the product rows. They are
 * shown here to check and correct; nothing is saved until "Save Purchase Order".
 *
 * EXTENDED COST is always QUANTITY × UNIT COST. When the document shows a different amount, it is
 * kept beside the calculated one and flagged; it never replaces it silently.
 */
type Field =
    | 'quantity'
    | 'item_sku'
    | 'description'
    | 'unit_cost'
    | 'unit_of_measure'
    | 'extended_cost';

interface ReadRow {
    quantity: number | null;
    item_sku: string;
    description: string;
    unit_cost: number | null;
    unit_of_measure: string;
    document_extended_cost: number | null;
    issues: Partial<Record<Field, string>>;
}

interface Row {
    key: number;
    quantity: string;
    item_sku: string;
    description: string;
    unit_cost: string;
    unit_of_measure: string;
    documentExtended: number | null;
    issues: Partial<Record<Field, string>>;
}

const props = defineProps<{
    file: File;
    teamSlug: string;
    suppliers: { id: number; name: string }[];
}>();
const emit = defineEmits<{ saved: []; cancel: [] }>();

const reading = ref(true);
const readError = ref<string | null>(null);
const notes = ref<string[]>([]);
const existing = ref<string | null>(null);
const documentTotal = ref<number | null>(null);
const ocr = ref(false);
const saving = ref(false);
const saveErrors = ref<string[]>([]);

const poNumber = ref('');
const poNumberIssue = ref<string | null>(null);
const poDate = ref('');
const poDateOriginal = ref<string | null>(null);
const poDateIssue = ref<string | null>(null);
const supplierChoice = ref<string>(''); // a supplier id, "new", or ""
const supplierName = ref<string | null>(null);
const supplierIssue = ref<string | null>(null);
const rows = ref<Row[]>([]);
let nextKey = 1;

const NEW_SUPPLIER = 'new';

function toRow(r: ReadRow): Row {
    return {
        key: nextKey++,
        quantity: r.quantity === null ? '' : String(r.quantity),
        item_sku: r.item_sku ?? '',
        description: r.description ?? '',
        unit_cost: r.unit_cost === null ? '' : r.unit_cost.toFixed(2),
        unit_of_measure: r.unit_of_measure ?? '',
        documentExtended: r.document_extended_cost,
        issues: { ...(r.issues ?? {}) },
    };
}

async function read() {
    reading.value = true;
    readError.value = null;
    const body = new FormData();
    body.append('file', props.file);
    try {
        const response = await fetch(
            `/${props.teamSlug}/procurement/purchase-orders/read-document`,
            {
                method: 'POST',
                body,
                credentials: 'same-origin',
                headers: {
                    'X-CSRF-TOKEN':
                        (
                            document.querySelector(
                                'meta[name="csrf-token"]',
                            ) as HTMLMetaElement | null
                        )?.content ?? '',
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            },
        );
        const data = await response.json().catch(() => ({}));
        if (!response.ok || !data.success) {
            readError.value =
                response.status === 413
                    ? 'The document is larger than 25 MB. Please upload a smaller file.'
                    : data.message ||
                      'The document could not be read. Please check the file and try again.';
            return;
        }
        ocr.value = !!data.ocr;
        notes.value = data.notes ?? [];
        existing.value = data.existing ?? null;
        documentTotal.value = data.document_total ?? null;
        poNumber.value = data.header.po_number.value ?? '';
        poNumberIssue.value = data.header.po_number.issue;
        poDate.value = data.header.po_date.value ?? '';
        poDateOriginal.value = data.header.po_date.original;
        poDateIssue.value = data.header.po_date.issue;
        supplierName.value = data.header.supplier.name;
        supplierIssue.value = data.header.supplier.issue;
        // A matched supplier is preselected; a confidently read new name is offered as "Add new supplier".
        supplierChoice.value = data.header.supplier.supplier_id
            ? String(data.header.supplier.supplier_id)
            : data.header.supplier.name &&
                !String(data.header.supplier.issue ?? '').includes(
                    'could not be confirmed',
                )
              ? NEW_SUPPLIER
              : '';
        rows.value = (data.rows as ReadRow[]).map(toRow);
    } catch {
        readError.value =
            'The document could not be sent because of a network problem. Please check your connection and try again.';
    } finally {
        reading.value = false;
    }
}

watch(() => props.file, read, { immediate: true });

// ------------------------------------------------------------------ values and checks

const QTY = /^\d+$/;
const MONEY = /^\d+(\.\d{1,2})?$/;
const plain = (v: string) => v.trim().replace(/,/g, '');

function quantityOf(row: Row): number | null {
    return QTY.test(plain(row.quantity)) && Number(plain(row.quantity)) >= 1
        ? Number(plain(row.quantity))
        : null;
}
function costOf(row: Row): number | null {
    return MONEY.test(plain(row.unit_cost))
        ? Number(plain(row.unit_cost))
        : null;
}
/** EXTENDED COST = QUANTITY × UNIT COST. */
function extendedOf(row: Row): number | null {
    const q = quantityOf(row);
    const c = costOf(row);
    return q !== null && c !== null ? Math.round(q * c * 100) / 100 : null;
}
const differs = (row: Row) => {
    const e = extendedOf(row);
    return (
        e !== null &&
        row.documentExtended !== null &&
        Math.abs(e - row.documentExtended) > 0.01
    );
};

function errorOf(row: Row, field: Field): string | null {
    switch (field) {
        case 'quantity':
            return quantityOf(row) === null
                ? row.quantity.trim() === ''
                    ? 'QTY ORDERED is missing.'
                    : 'QTY ORDERED must be a whole number of at least 1.'
                : null;
        case 'unit_cost':
            return costOf(row) === null
                ? row.unit_cost.trim() === ''
                    ? 'UNIT COST is missing.'
                    : 'UNIT COST must be an amount with up to 2 decimals.'
                : null;
        case 'description':
            return row.description.trim() === ''
                ? 'Description is missing.'
                : null;
        case 'unit_of_measure':
            return row.unit_of_measure.trim() === '' ? 'U/M is missing.' : null;
        default:
            return null;
    }
}
/** A validation error, or else what the reader flagged and is not edited yet. */
function messageOf(row: Row, field: Field): string | null {
    const error = errorOf(row, field);
    if (error) {
        return row.issues[field] ?? error; // the reader's own wording while the value is unedited
    }
    if (field === 'extended_cost') {
        return differs(row)
            ? `The document shows ${money(row.documentExtended)}; QTY ORDERED × UNIT COST = ${money(extendedOf(row))}.`
            : (row.issues.extended_cost ?? null);
    }
    return row.issues[field] ?? null;
}
const hasError = (row: Row, field: Field) => errorOf(row, field) !== null;
const isFlagged = (row: Row, field: Field) =>
    !hasError(row, field) && !!messageOf(row, field);

function edit(row: Row, field: Exclude<Field, 'extended_cost'>, value: string) {
    row[field] = value;
    delete row.issues[field];
    if (field === 'quantity' || field === 'unit_cost') {
        delete row.issues.extended_cost; // recalculated; a document difference is still shown
    }
}

const total = computed(
    () =>
        Math.round(
            rows.value.reduce((sum, r) => sum + (extendedOf(r) ?? 0), 0) * 100,
        ) / 100,
);
const reviewCount = computed(
    () =>
        rows.value.filter((r) =>
            (
                [
                    'quantity',
                    'item_sku',
                    'description',
                    'unit_cost',
                    'unit_of_measure',
                    'extended_cost',
                ] as Field[]
            ).some((f) => messageOf(r, f)),
        ).length,
);

function money(value: number | null): string {
    return value === null
        ? '—'
        : value.toLocaleString('en-US', {
              minimumFractionDigits: 2,
              maximumFractionDigits: 2,
          });
}

function addRow() {
    rows.value.push({
        key: nextKey++,
        quantity: '',
        item_sku: '',
        description: '',
        unit_cost: '',
        unit_of_measure: 'PCS',
        documentExtended: null,
        issues: {},
    });
}
function removeRow(row: Row) {
    rows.value.splice(rows.value.indexOf(row), 1);
}

// ------------------------------------------------------------------ saving

const headerErrors = computed(() => {
    const errors: string[] = [];
    if (!/^[A-Za-z0-9][A-Za-z0-9\-/_.]*$/.test(poNumber.value.trim()))
        errors.push('Enter the PURCHASE ORDER NO.');
    if (!/^\d{4}-\d{2}-\d{2}$/.test(poDate.value))
        errors.push('Enter the ORDER DATE.');
    if (rows.value.length === 0) errors.push('Add at least one product.');
    return errors;
});
const rowErrorCount = computed(
    () =>
        rows.value.filter((r) =>
            (
                [
                    'quantity',
                    'description',
                    'unit_cost',
                    'unit_of_measure',
                ] as Field[]
            ).some((f) => hasError(r, f)),
        ).length,
);
const canSave = computed(
    () =>
        !reading.value &&
        !saving.value &&
        !existing.value &&
        headerErrors.value.length === 0 &&
        rowErrorCount.value === 0,
);

function save() {
    saveErrors.value = [];
    if (!canSave.value) {
        saveErrors.value = [
            ...headerErrors.value,
            ...(rowErrorCount.value
                ? [
                      `${rowErrorCount.value} product row(s) have missing or invalid values.`,
                  ]
                : []),
        ];
        return;
    }
    saving.value = true;
    router.post(
        `/${props.teamSlug}/procurement/purchase-orders/import-document`,
        {
            po_number: poNumber.value.trim(),
            po_date: poDate.value,
            supplier_id:
                supplierChoice.value && supplierChoice.value !== NEW_SUPPLIER
                    ? Number(supplierChoice.value)
                    : null,
            new_supplier_name:
                supplierChoice.value === NEW_SUPPLIER
                    ? supplierName.value
                    : null,
            file_name: props.file.name,
            rows: rows.value.map((r) => ({
                quantity: quantityOf(r),
                item_sku: r.item_sku.trim(),
                description: r.description.trim(),
                unit_cost: costOf(r),
                unit_of_measure: r.unit_of_measure.trim(),
            })),
        },
        {
            preserveScroll: true,
            onSuccess: (page) => {
                const errors = (page.props as any).errors ?? {};
                if (Object.keys(errors).length === 0) {
                    emit('saved');
                }
            },
            onError: (errors) => {
                saveErrors.value = Object.values(errors) as string[];
            },
            onFinish: () => {
                saving.value = false;
            },
        },
    );
}

const inputClass = (row: Row, field: Field) =>
    hasError(row, field)
        ? 'border-red-400 focus:ring-red-400'
        : isFlagged(row, field)
          ? 'border-amber-400 focus:ring-amber-400'
          : 'border-gray-200 focus:ring-[#4b5320]';
</script>

<template>
    <div data-test="po-document-import">
        <div
            v-if="reading"
            class="py-8 text-center text-xs text-gray-500"
            aria-live="polite"
        >
            <Clock class="mx-auto mb-1 h-5 w-5 animate-spin text-[#F8B803]" />
            Reading {{ file.name }}… Scanned documents and photos can take a
            minute.
        </div>

        <div
            v-else-if="readError"
            class="flex items-start gap-2 rounded-xl border border-red-200 bg-red-50 p-3 text-xs text-red-700"
            role="alert"
        >
            <AlertCircle class="mt-0.5 h-4 w-4 shrink-0 text-red-500" />
            <span>{{ readError }}</span>
        </div>

        <div v-else class="space-y-4">
            <div
                v-if="existing"
                class="flex items-start gap-2 rounded-xl border border-red-200 bg-red-50 p-3 text-xs text-red-700"
                role="alert"
            >
                <AlertCircle class="mt-0.5 h-4 w-4 shrink-0 text-red-500" />
                <span
                    >Purchase order {{ existing }} already exists, so it cannot
                    be imported again. Change the PURCHASE ORDER NO. if this is
                    a different order.</span
                >
            </div>
            <ul
                v-if="notes.length"
                class="space-y-1 rounded-xl border border-amber-200 bg-amber-50/60 p-3 text-xs text-amber-800"
            >
                <li
                    v-for="(n, i) in notes"
                    :key="i"
                    class="flex items-start gap-1.5"
                >
                    <AlertCircle class="mt-0.5 h-3.5 w-3.5 shrink-0" />{{ n }}
                </li>
            </ul>

            <!-- ORDER DATE, PURCHASE ORDER NO., SUPPLIER -->
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                <div>
                    <label
                        for="po_doc_date"
                        class="mb-1 block text-xs font-semibold text-gray-700"
                        >ORDER DATE <span class="text-rose-600">*</span></label
                    >
                    <input
                        id="po_doc_date"
                        v-model="poDate"
                        type="date"
                        class="field-control"
                        :class="poDateIssue ? 'border-amber-400' : ''"
                        @input="poDateIssue = null"
                    />
                    <p
                        v-if="poDateOriginal"
                        class="mt-1 text-[11px] text-gray-500"
                    >
                        On the document: {{ poDateOriginal }}
                    </p>
                    <p
                        v-if="poDateIssue"
                        class="mt-1 text-[11px] text-amber-700"
                    >
                        {{ poDateIssue }}
                    </p>
                </div>
                <div>
                    <label
                        for="po_doc_number"
                        class="mb-1 block text-xs font-semibold text-gray-700"
                        >PURCHASE ORDER NO.
                        <span class="text-rose-600">*</span></label
                    >
                    <input
                        id="po_doc_number"
                        v-model="poNumber"
                        type="text"
                        maxlength="100"
                        class="field-control font-mono"
                        :class="poNumberIssue ? 'border-amber-400' : ''"
                        @input="
                            poNumberIssue = null;
                            existing = null;
                        "
                    />
                    <p
                        v-if="poNumberIssue"
                        class="mt-1 text-[11px] text-amber-700"
                    >
                        {{ poNumberIssue }}
                    </p>
                </div>
                <div>
                    <label
                        for="po_doc_supplier"
                        class="mb-1 block text-xs font-semibold text-gray-700"
                        >SUPPLIER</label
                    >
                    <select
                        id="po_doc_supplier"
                        v-model="supplierChoice"
                        class="field-control"
                        :class="supplierIssue ? 'border-amber-400' : ''"
                        @change="supplierIssue = null"
                    >
                        <option value="">— Choose supplier —</option>
                        <option v-if="supplierName" :value="NEW_SUPPLIER">
                            Add new supplier: {{ supplierName }}
                        </option>
                        <option
                            v-for="s in suppliers"
                            :key="s.id"
                            :value="String(s.id)"
                        >
                            {{ s.name }}
                        </option>
                    </select>
                    <p
                        v-if="supplierName"
                        class="mt-1 text-[11px] text-gray-500"
                    >
                        On the document: {{ supplierName }}
                    </p>
                    <p
                        v-if="supplierIssue"
                        class="mt-1 text-[11px] text-amber-700"
                    >
                        {{ supplierIssue }}
                    </p>
                </div>
            </div>

            <!-- Products -->
            <div class="overflow-x-auto rounded-xl border border-gray-200">
                <table class="w-full min-w-[820px] text-left text-xs">
                    <caption class="sr-only">
                        Products read from the purchase order document
                    </caption>
                    <thead class="bg-gray-50 text-gray-600">
                        <tr>
                            <th scope="col" class="w-24 p-2 font-semibold whitespace-nowrap">
                                QTY ORDERED
                            </th>
                            <th scope="col" class="w-28 p-2 font-semibold">
                                ITEM/SKU Number
                            </th>
                            <th scope="col" class="p-2 font-semibold">
                                Description
                            </th>
                            <th
                                scope="col"
                                class="w-28 p-2 text-right font-semibold"
                            >
                                UNIT COST
                            </th>
                            <th scope="col" class="w-20 p-2 font-semibold">
                                U/M
                            </th>
                            <th
                                scope="col"
                                class="w-36 p-2 text-right font-semibold"
                            >
                                EXTENDED COST
                            </th>
                            <th scope="col" class="w-10 p-2">
                                <span class="sr-only">Remove</span>
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <tr v-if="!rows.length">
                            <td
                                colspan="7"
                                class="p-4 text-center text-gray-500"
                            >
                                No products were read. Use "Add product" to
                                enter them.
                            </td>
                        </tr>
                        <tr
                            v-for="(row, i) in rows"
                            :key="row.key"
                            data-test="po-doc-row"
                        >
                            <td class="p-2 align-top">
                                <input
                                    :value="row.quantity"
                                    type="text"
                                    inputmode="numeric"
                                    class="w-full rounded-md border bg-white px-2 py-1.5 focus:ring-1 focus:outline-none"
                                    :class="inputClass(row, 'quantity')"
                                    :aria-label="`QTY ORDERED, product ${i + 1}`"
                                    :aria-invalid="hasError(row, 'quantity')"
                                    @input="
                                        edit(
                                            row,
                                            'quantity',
                                            ($event.target as HTMLInputElement)
                                                .value,
                                        )
                                    "
                                />
                                <p
                                    v-if="messageOf(row, 'quantity')"
                                    class="mt-1"
                                    :class="
                                        hasError(row, 'quantity')
                                            ? 'text-red-600'
                                            : 'text-amber-700'
                                    "
                                >
                                    {{ messageOf(row, 'quantity') }}
                                </p>
                            </td>
                            <td class="p-2 align-top">
                                <input
                                    :value="row.item_sku"
                                    type="text"
                                    maxlength="100"
                                    class="w-full rounded-md border bg-white px-2 py-1.5 font-mono focus:ring-1 focus:outline-none"
                                    :class="inputClass(row, 'item_sku')"
                                    :aria-label="`ITEM/SKU Number, product ${i + 1}`"
                                    @input="
                                        edit(
                                            row,
                                            'item_sku',
                                            ($event.target as HTMLInputElement)
                                                .value,
                                        )
                                    "
                                />
                                <p
                                    v-if="messageOf(row, 'item_sku')"
                                    class="mt-1 text-amber-700"
                                >
                                    {{ messageOf(row, 'item_sku') }}
                                </p>
                            </td>
                            <td class="p-2 align-top">
                                <input
                                    :value="row.description"
                                    type="text"
                                    maxlength="500"
                                    class="w-full rounded-md border bg-white px-2 py-1.5 focus:ring-1 focus:outline-none"
                                    :class="inputClass(row, 'description')"
                                    :aria-label="`Description, product ${i + 1}`"
                                    :aria-invalid="hasError(row, 'description')"
                                    @input="
                                        edit(
                                            row,
                                            'description',
                                            ($event.target as HTMLInputElement)
                                                .value,
                                        )
                                    "
                                />
                                <p
                                    v-if="messageOf(row, 'description')"
                                    class="mt-1"
                                    :class="
                                        hasError(row, 'description')
                                            ? 'text-red-600'
                                            : 'text-amber-700'
                                    "
                                >
                                    {{ messageOf(row, 'description') }}
                                </p>
                            </td>
                            <td class="p-2 align-top">
                                <input
                                    :value="row.unit_cost"
                                    type="text"
                                    inputmode="decimal"
                                    class="w-full rounded-md border bg-white px-2 py-1.5 text-right focus:ring-1 focus:outline-none"
                                    :class="inputClass(row, 'unit_cost')"
                                    :aria-label="`UNIT COST, product ${i + 1}`"
                                    :aria-invalid="hasError(row, 'unit_cost')"
                                    @input="
                                        edit(
                                            row,
                                            'unit_cost',
                                            ($event.target as HTMLInputElement)
                                                .value,
                                        )
                                    "
                                />
                                <p
                                    v-if="messageOf(row, 'unit_cost')"
                                    class="mt-1"
                                    :class="
                                        hasError(row, 'unit_cost')
                                            ? 'text-red-600'
                                            : 'text-amber-700'
                                    "
                                >
                                    {{ messageOf(row, 'unit_cost') }}
                                </p>
                            </td>
                            <td class="p-2 align-top">
                                <input
                                    :value="row.unit_of_measure"
                                    type="text"
                                    maxlength="50"
                                    class="w-full rounded-md border bg-white px-2 py-1.5 uppercase focus:ring-1 focus:outline-none"
                                    :class="inputClass(row, 'unit_of_measure')"
                                    :aria-label="`U/M, product ${i + 1}`"
                                    :aria-invalid="
                                        hasError(row, 'unit_of_measure')
                                    "
                                    @input="
                                        edit(
                                            row,
                                            'unit_of_measure',
                                            ($event.target as HTMLInputElement)
                                                .value,
                                        )
                                    "
                                />
                                <p
                                    v-if="messageOf(row, 'unit_of_measure')"
                                    class="mt-1"
                                    :class="
                                        hasError(row, 'unit_of_measure')
                                            ? 'text-red-600'
                                            : 'text-amber-700'
                                    "
                                >
                                    {{ messageOf(row, 'unit_of_measure') }}
                                </p>
                            </td>
                            <td class="p-2 text-right align-top">
                                <!-- Calculated: QUANTITY × UNIT COST -->
                                <div
                                    class="py-1.5 font-semibold text-gray-900 tabular-nums"
                                    :class="
                                        differs(row) ? 'text-amber-800' : ''
                                    "
                                    data-test="po-doc-extended"
                                >
                                    {{ money(extendedOf(row)) }}
                                </div>
                                <p
                                    v-if="messageOf(row, 'extended_cost')"
                                    class="mt-1 text-left text-amber-700"
                                >
                                    {{ messageOf(row, 'extended_cost') }}
                                </p>
                            </td>
                            <td class="p-2 text-right align-top">
                                <button
                                    type="button"
                                    class="rounded p-1 text-gray-400 hover:bg-red-50 hover:text-red-600"
                                    :aria-label="`Remove product ${i + 1}`"
                                    @click="removeRow(row)"
                                >
                                    <Trash2 class="h-3.5 w-3.5" />
                                </button>
                            </td>
                        </tr>
                    </tbody>
                    <tfoot class="border-t border-gray-200 bg-gray-50">
                        <tr>
                            <td
                                colspan="5"
                                class="p-2 text-right font-semibold text-gray-700"
                            >
                                Total purchase order amount
                            </td>
                            <td
                                class="p-2 text-right font-semibold text-gray-900 tabular-nums"
                                data-test="po-doc-total"
                            >
                                {{ money(total) }}
                            </td>
                            <td></td>
                        </tr>
                        <tr
                            v-if="
                                documentTotal !== null &&
                                Math.abs(documentTotal - total) > 0.01
                            "
                        >
                            <td
                                colspan="7"
                                class="p-2 text-right text-amber-700"
                            >
                                The document's total is
                                {{ money(documentTotal) }}.
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <div class="flex flex-wrap items-center justify-between gap-2">
                <button
                    type="button"
                    class="secondary-button min-h-8 px-3 py-1.5 text-xs"
                    @click="addRow"
                >
                    <Plus class="h-3.5 w-3.5" /> Add product
                </button>
                <span class="text-xs text-gray-500" aria-live="polite">
                    {{ rows.length }} product(s)<template v-if="reviewCount">
                        · {{ reviewCount }} to check</template
                    ><template v-if="ocr"> · read by OCR</template>
                </span>
            </div>

            <ul
                v-if="saveErrors.length"
                class="space-y-0.5 rounded-xl border border-red-200 bg-red-50 p-3 text-xs text-red-700"
                role="alert"
            >
                <li v-for="(e, i) in saveErrors" :key="i">{{ e }}</li>
            </ul>
        </div>

        <div class="mt-6 flex justify-end gap-3 border-t border-gray-100 pt-3">
            <button
                type="button"
                class="secondary-button"
                @click="emit('cancel')"
            >
                Cancel
            </button>
            <button
                v-if="!reading && !readError"
                type="button"
                class="brand-button"
                :disabled="saving || !!existing"
                data-test="po-doc-save"
                @click="save"
            >
                {{ saving ? 'Saving…' : 'Save Purchase Order' }}
            </button>
        </div>
    </div>
</template>
