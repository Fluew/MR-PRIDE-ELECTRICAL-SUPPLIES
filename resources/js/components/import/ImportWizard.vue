<script setup lang="ts">
/**
 * Universal document import: upload (CSV, Excel, PDF, PNG, JPG up to 25 MB) → extraction on
 * the server (queued; OCR for images and scanned PDFs) → editable preview → confirm.
 * Nothing is saved until staff confirm, and every rule is checked again by the server.
 */
import { usePage } from '@inertiajs/vue3';
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';

type Module = 'inventory' | 'supplier_quotation';

interface Issue { level: 'error' | 'warning' | 'info'; field: string | null; message: string }
interface DateIssue { level: 'error' | 'warning'; field: 'quoted_on' | 'valid_until'; message: string; original: string | null }
/** A date found in the document: the text as printed and its YYYY-MM-DD value (null when ambiguous or invalid). */
interface DocumentDate { original: string | null; value: string | null; status: string; format: string | null; problem: string | null; label: string; row: number }
interface Suggestion { product_id: number; sku: string; name: string; unit?: string | null; confidence: number; level: string; reason: string }
interface Row {
    id: number;
    row_number: number;
    raw: string | null;
    extracted: Record<string, any>;
    values: Record<string, any>;
    sources: Record<string, string | null> | null;
    issues: Issue[] | null;
    status: string;
    skipped: boolean;
    matched_product_id: number | null;
    matched_product?: { id: number; name: string; sku: string; unit: string | null } | null;
    suggestions: Suggestion[] | null;
}
interface Batch {
    id: number;
    module: Module;
    status: string;
    original_filename: string;
    file_type: string | null;
    file_size: number;
    extraction_method: string | null;
    page_count: number | null;
    meta: Record<string, any> | null;
    summary: Record<string, any> | null;
    error: string | null;
    uses_ocr: boolean;
    /** Supplier quotations: what to check about the quotation dates (errors block the import). */
    date_issues?: DateIssue[];
    uploaded_by: string | null;
    imported_by: string | null;
    imported_at?: string | null;
    completed_at?: string | null;
    created_at: string;
}
interface TableField { key: string; label: string; required: boolean }
/** GET imports/batches/{id}/table: the table read from the file, for choosing columns by hand. */
interface ImportTable {
    rows: string[][];
    width: number;
    total_rows: number;
    header_row: number | null;
    mapping: Record<string, number> | [];
    manual: boolean;
    columns: string[];
    fields: TableField[];
}

const props = defineProps<{ open: boolean; module: Module; suppliers?: { id: number; name: string }[] }>();
const emit = defineEmits<{ close: []; completed: [batch: Batch] }>();

const page = usePage();
const teamSlug = computed(() => (page.props as any).currentTeam?.slug || (page.props as any).team?.slug || '');
const base = computed(() => `/${teamSlug.value}/imports`);
const csrf = () => (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement | null)?.content ?? '';

const FIELDS: Record<Module, { key: string; label: string; numeric?: boolean; width?: string }[]> = {
    inventory: [
        { key: 'sku', label: 'SKU', width: 'w-28' },
        { key: 'name', label: 'Product name', width: 'min-w-56' },
        { key: 'category', label: 'Category', width: 'w-28' },
        { key: 'brand', label: 'Brand', width: 'w-28' },
        { key: 'unit', label: 'Unit', width: 'w-20' },
        { key: 'cost_price', label: 'Cost price', numeric: true, width: 'w-24' },
        { key: 'price', label: 'Selling price', numeric: true, width: 'w-24' },
    ],
    supplier_quotation: [
        { key: 'item_code', label: 'Code', width: 'w-24' },
        { key: 'description', label: 'Description', width: 'min-w-56' },
        { key: 'unit', label: 'Unit', width: 'w-20' },
        { key: 'quantity', label: 'Qty', numeric: true, width: 'w-20' },
        { key: 'unit_price', label: 'Unit price', numeric: true, width: 'w-24' },
        { key: 'amount', label: 'Amount', numeric: true, width: 'w-28' },
    ],
};
const fields = computed(() => FIELDS[props.module]);
// Import products uses a simpler preview: no OCR/extraction details, one summary instead of repeated notes.
const isInventory = computed(() => props.module === 'inventory');
const title = computed(() => (props.module === 'inventory' ? 'Import products' : 'Import supplier quotation'));

const step = ref<'upload' | 'processing' | 'review' | 'done' | 'failed'>('upload');
const error = ref('');
const busy = ref(false);
const batch = ref<Batch | null>(null);
const rows = ref<Row[]>([]);
const pager = ref({ current_page: 1, last_page: 1, total: 0 });
const filter = ref('all');
const ocrChecked = ref(false);
const history = ref<Batch[]>([]);
let timer: ReturnType<typeof setTimeout> | null = null;

// Upload form
const file = ref<File | null>(null);
// Today's date as YYYY-MM-DD in the browser's time zone (toISOString() would give the UTC date).
const today = () => {
    const d = new Date();
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
};
// The quotation date may be left blank: it is then read from the document and confirmed in the review.
const meta = ref({ supplier_id: null as number | null, quoted_on: '', valid_until: '', currency: 'PHP', tax_inclusive: false, reference: '' });

const reset = () => {
    step.value = 'upload';
    error.value = '';
    batch.value = null;
    rows.value = [];
    file.value = null;
    ocrChecked.value = false;
    filter.value = 'all';
    closeMapping();
    mapAutoOpened = null;
    loadHistory();
};
watch(() => props.open, (o) => (o ? reset() : stopPolling()));
onBeforeUnmount(() => stopPolling());

const request = async (url: string, init: RequestInit = {}) => {
    const res = await fetch(url, { ...init, headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf(), ...(init.headers ?? {}) } });
    const body = await res.json().catch(() => ({}));
    if (!res.ok) {
        const first = body?.errors ? (Object.values(body.errors)[0] as string[])?.[0] : null;
        throw new Error(first || body?.message || `Request failed (${res.status}).`);
    }

    return body;
};

// Recent imports are listed only for supplier quotations; Import products does not show them.
const showHistory = computed(() => props.module !== 'inventory');
const loadHistory = async () => {
    if (!showHistory.value) {
        history.value = [];
        return;
    }
    try {
        history.value = (await request(`${base.value}/${props.module}`)).data ?? [];
    } catch {
        history.value = [];
    }
};

const upload = async () => {
    if (!file.value) return;
    if (file.value.size > 25 * 1024 * 1024) {
        error.value = 'The file is larger than 25 MB.';
        return;
    }
    busy.value = true;
    error.value = '';
    const form = new FormData();
    form.append('file', file.value);
    if (props.module === 'supplier_quotation') {
        Object.entries(meta.value).forEach(([k, v]) => v !== null && v !== '' && form.append(k, typeof v === 'boolean' ? (v ? '1' : '0') : String(v)));
    }
    try {
        batch.value = await request(`${base.value}/${props.module}`, { method: 'POST', body: form });
        afterStatus();
    } catch (e: any) {
        error.value = e.message;
    } finally {
        busy.value = false;
    }
};

const stopPolling = () => {
    if (timer) clearTimeout(timer);
    timer = null;
};
const afterStatus = () => {
    const s = batch.value?.status;
    if (s === 'queued' || s === 'processing') {
        step.value = 'processing';
        stopPolling();
        timer = setTimeout(() => load(1), 2000);
    } else if (s === 'failed') {
        step.value = 'failed';
    } else if (s === 'completed') {
        step.value = 'done';
    } else if (s === 'ready_for_review') {
        step.value = 'review';
        if (!rows.value.length) load(1);
    } else {
        step.value = 'failed';
    }
};

const load = async (p = pager.value.current_page) => {
    if (!batch.value) return;
    try {
        const data = await request(`${base.value}/batches/${batch.value.id}?status=${filter.value}&page=${p}`);
        batch.value = data.batch;
        rows.value = data.rows.data;
        pager.value = { current_page: data.rows.current_page, last_page: data.rows.last_page, total: data.rows.total };
        afterStatus();
    } catch (e: any) {
        error.value = e.message;
    }
};
watch(filter, () => load(1));

const openBatch = (b: Batch) => {
    batch.value = b;
    rows.value = [];
    load(1);
};

const patchRow = async (row: Row, payload: Record<string, any>) => {
    if (!batch.value) return;
    error.value = '';
    try {
        await request(`${base.value}/batches/${batch.value.id}/rows/${row.id}`, { method: 'PATCH', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) });
        await load();
    } catch (e: any) {
        error.value = e.message;
    }
};
const editField = (row: Row, key: string, value: string) => {
    if (String(row.values?.[key] ?? '') === value) return;
    patchRow(row, { values: { [key]: value } });
};

// Product picker for supplier quotation lines
const searchFor = ref<number | null>(null);
const searchQ = ref('');
const searchResults = ref<{ id: number; name: string; sku: string; unit: string | null }[]>([]);
const searchProducts = async () => {
    if (!batch.value) return;
    searchResults.value = await request(`${base.value}/batches/${batch.value.id}/products?q=${encodeURIComponent(searchQ.value)}`).catch(() => []);
};

const bulk = async (action: 'skip_problems' | 'restore') => {
    if (!batch.value) return;
    busy.value = true;
    try {
        await request(`${base.value}/batches/${batch.value.id}/bulk`, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action }) });
        await load(1);
    } catch (e: any) {
        error.value = e.message;
    } finally {
        busy.value = false;
    }
};

const result = ref<{ created: number; skipped: number } | null>(null);
const confirmImport = async () => {
    if (!batch.value) return;
    busy.value = true;
    error.value = '';
    try {
        const data = await request(`${base.value}/batches/${batch.value.id}/confirm`, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ ocr_checked: ocrChecked.value }) });
        result.value = { created: data.created, skipped: data.skipped };
        batch.value = data.batch;
        step.value = 'done';
        emit('completed', data.batch);
    } catch (e: any) {
        error.value = e.message;
    } finally {
        busy.value = false;
    }
};

const cancelImport = async () => {
    if (!batch.value) return;
    try {
        await request(`${base.value}/batches/${batch.value.id}/cancel`, { method: 'POST' });
    } catch {
        // Already finished; just close.
    }
    emit('close');
};

const summary = computed(() => batch.value?.summary ?? {});
// Quotation dates (supplier quotations): what the document says, what will be saved, and corrections.
const dateIssues = computed(() => batch.value?.date_issues ?? []);
const dateBlocked = computed(() => dateIssues.value.some((i) => i.level === 'error'));
const docDate = (field: 'quoted_on' | 'valid_until') => (batch.value?.meta?.document_dates?.[field] ?? null) as DocumentDate | null;
const dateSource = (field: 'quoted_on' | 'valid_until') =>
    ({ entered: 'entered at upload', document: 'read from the document', edited: 'corrected in review' } as Record<string, string>)[batch.value?.meta?.date_sources?.[field] ?? ''] ?? '';
const dateForm = ref({ quoted_on: '', valid_until: '' });
const dateSaving = ref(false);
watch(
    () => [batch.value?.id, batch.value?.meta?.quoted_on, batch.value?.meta?.valid_until],
    () => {
        dateForm.value = { quoted_on: batch.value?.meta?.quoted_on ?? '', valid_until: batch.value?.meta?.valid_until ?? '' };
    },
    { immediate: true },
);
const saveDates = async () => {
    if (!batch.value || !dateForm.value.quoted_on) return;
    dateSaving.value = true;
    error.value = '';
    try {
        const data = await request(`${base.value}/batches/${batch.value.id}/dates`, {
            method: 'PATCH',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ quoted_on: dateForm.value.quoted_on, valid_until: dateForm.value.valid_until || null }),
        });
        batch.value = data.batch;
    } catch (e: any) {
        error.value = e.message;
    } finally {
        dateSaving.value = false;
    }
};

const canConfirm = computed(() => step.value === 'review' && !mapSaving.value && !dateSaving.value && !dateBlocked.value && (summary.value.needs_review ?? 0) === 0 && (summary.value.ready ?? 0) > 0 && (!batch.value?.uses_ocr || ocrChecked.value));
const methodLabel = (m: string | null) => ({ csv: 'CSV parser', spreadsheet: 'Excel reader', 'pdf-text': 'PDF text (Poppler)', 'pdf-ocr': 'Scanned PDF: Poppler + Tesseract OCR', ocr: 'Image: Tesseract OCR' } as Record<string, string>)[m ?? ''] ?? m ?? '—';
const statusLabel: Record<string, string> = { ready: 'Ready', needs_review: 'Needs review', duplicate: 'Duplicate', existing: 'Already in inventory', skipped: 'Skipped', imported: 'Imported' };
const statusText = (s: string) => (isInventory.value && s === 'duplicate' ? 'Duplicate in file' : statusLabel[s] ?? s);
// Filter chips: for products, the three groups that matter, plus the others only when they have rows.
const filterChips = computed<[string, string][]>(() => {
    if (!isInventory.value) {
        return [['all', 'All'], ['attention', 'Needs attention'], ['ready', 'Ready'], ['needs_review', 'Needs review'], ['duplicate', 'Duplicates'], ['existing', 'Already in inventory'], ['skipped', 'Skipped'], ['imported', 'Imported']];
    }
    const chips: [string, string][] = [['all', 'All'], ['ready', 'Ready'], ['needs_review', 'Needs review'], ['existing', 'Already in inventory']];
    for (const [k, l] of [['duplicate', 'Duplicate in file'], ['skipped', 'Skipped'], ['imported', 'Imported']] as [string, string][]) {
        if ((summary.value[k] ?? 0) > 0) chips.push([k, l]);
    }

    return chips;
});
const statusClass = (s: string) => ({
    ready: 'border-emerald-200 bg-emerald-50 text-emerald-700',
    needs_review: 'border-rose-200 bg-rose-50 text-rose-700',
    duplicate: 'border-amber-200 bg-amber-50 text-amber-800',
    existing: 'border-gray-200 bg-gray-50 text-gray-600',
    skipped: 'border-gray-200 bg-gray-50 text-gray-500',
    imported: 'border-emerald-200 bg-emerald-50 text-emerald-700',
}[s] ?? 'border-gray-200 bg-gray-50 text-gray-700');
const issueClass = (l: string) => (l === 'error' ? 'text-rose-700' : l === 'warning' ? 'text-amber-700' : 'text-gray-500');
const sourceBadge = (row: Row, key: string) => ({ document: 'doc', calculated: 'calc', edited: 'edited', generated: 'gen' } as Record<string, string>)[row.sources?.[key] ?? ''] ?? '';
const confidenceLevel = (row: Row) => row.sources?._confidence ?? (row.status === 'needs_review' ? 'low' : batch.value?.uses_ocr ? 'medium' : 'high');
const confidenceLabel = (row: Row) => ({ high: 'High confidence', medium: 'Medium confidence', low: 'Low confidence · verify' } as Record<string, string>)[confidenceLevel(row)] ?? 'Medium confidence';
const confidenceClass = (row: Row) => ({
    high: 'border-emerald-200 bg-emerald-50/70 text-emerald-700',
    medium: 'border-amber-200 bg-amber-50/70 text-amber-800',
    low: 'border-rose-200 bg-rose-50/70 text-rose-700',
}[confidenceLevel(row)] ?? 'border-gray-200 bg-gray-50 text-gray-600');
const display = (v: any) => (v === null || v === undefined ? '' : String(v));
// Empty cells: products get a generated SKU, other empty fields stay blank (no "NULL" text).
const cellPlaceholder = (row: Row, key: string) => {
    if (row.values?.[key] !== null && row.values?.[key] !== undefined) return '';
    if (!isInventory.value) return 'NULL';

    return key === 'sku' ? 'Auto' : '';
};
const fieldIssue = (row: Row, key: string) => (row.issues ?? []).find((i) => i.field === key && i.level === 'error');
const locked = (row: Row) => row.status === 'imported' || step.value !== 'review';

// Failed batch: what went wrong, by the stage the server recorded.
const failureLine = computed(() => ({
    reading: 'The file could not be read.',
    saving: 'The file was read, but the preview could not be saved.',
    upload: 'The upload could not be recorded.',
    importing: 'Nothing was imported.',
} as Record<string, string>)[summary.value.failure?.stage ?? ''] ?? '');

// Errors appear at the top of the window; bring them into view (e.g. after a failed confirm).
const errorBox = ref<HTMLElement | null>(null);
watch(error, (e) => e && nextTick(() => errorBox.value?.scrollIntoView({ block: 'nearest', behavior: 'smooth' })));

// Choosing columns by hand, when the headings were not recognised or staff want other columns.
const mapOpen = ref(false);
const mapLoading = ref(false);
const mapSaving = ref(false);
const table = ref<ImportTable | null>(null);
const mapHeader = ref<number | null>(null);
const mapColumns = ref<Record<string, number | null>>({});
let mapAutoOpened: number | null = null;
const needsMapping = computed(() => step.value === 'review' && (summary.value.header_found === false || (summary.value.missing_columns?.length ?? 0) > 0));

const columnLetter = (i: number) => {
    let name = '';
    for (let n = i + 1; n > 0; n = Math.floor((n - 1) / 26)) name = String.fromCharCode(65 + ((n - 1) % 26)) + name;

    return name;
};
// Heading text of a column in the chosen header row ('' when there is none).
const columnHeading = (i: number) => {
    if (!table.value || mapHeader.value === null) return '';
    const cell = table.value.rows[mapHeader.value]?.[i];
    if (cell !== undefined) return cell.trim();
    // Header row beyond the preview: use the server's label (it says "Column X" when empty).
    const label = mapHeader.value === table.value.header_row ? (table.value.columns[i] ?? '') : '';

    return label === `Column ${columnLetter(i)}` ? '' : label;
};
const columnOption = (i: number) => `Column ${columnLetter(i)}${columnHeading(i) ? ` · ${columnHeading(i)}` : ''}`;
const fieldAt = (i: number) => table.value?.fields.find((f) => mapColumns.value[f.key] === i)?.label ?? '';
const headerOptions = computed(() => {
    const options = (table.value?.rows ?? []).map((_, r) => r);
    for (const r of [table.value?.header_row, mapHeader.value]) if (r !== null && r !== undefined && !options.includes(r)) options.push(r);

    return options.sort((a, b) => a - b);
});
// Same checks as the server: the required field has a column, and no column is used twice.
const clashes = computed(() => {
    const byColumn = new Map<number, string[]>();
    for (const f of table.value?.fields ?? []) {
        const c = mapColumns.value[f.key] ?? null;
        if (c !== null) byColumn.set(c, [...(byColumn.get(c) ?? []), f.key]);
    }

    return [...byColumn.entries()].filter(([, keys]) => keys.length > 1);
});
const clashFor = (key: string) => clashes.value.some(([, keys]) => keys.includes(key));
const mapProblems = computed(() => {
    const fieldsByKey = Object.fromEntries((table.value?.fields ?? []).map((f) => [f.key, f]));
    const problems = (table.value?.fields ?? [])
        .filter((f) => f.required && (mapColumns.value[f.key] ?? null) === null)
        .map((f) => `Choose the column that holds the ${f.label.toLowerCase()}.`);
    for (const [c, keys] of clashes.value) {
        problems.push(`${keys.map((k) => fieldsByKey[k]?.label ?? k).join(' and ')} cannot all use column ${columnLetter(c)}. Choose a different column for one of them.`);
    }

    return problems;
});

const closeMapping = () => {
    mapOpen.value = false;
    table.value = null;
};
const openMapping = async () => {
    if (!batch.value) return;
    mapOpen.value = true;
    mapLoading.value = true;
    table.value = null;
    error.value = '';
    try {
        const data: ImportTable = await request(`${base.value}/batches/${batch.value.id}/table`);
        const current: Record<string, number> = Array.isArray(data.mapping) ? {} : data.mapping;
        table.value = data;
        mapHeader.value = data.header_row;
        mapColumns.value = Object.fromEntries(data.fields.map((f) => [f.key, current[f.key] ?? null]));
    } catch (e: any) {
        error.value = e.message;
        mapOpen.value = false;
    } finally {
        mapLoading.value = false;
    }
};
const applyMapping = async () => {
    if (!batch.value || mapProblems.value.length) return;
    mapSaving.value = true;
    error.value = '';
    try {
        const data = await request(`${base.value}/batches/${batch.value.id}/mapping`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ header_row: mapHeader.value, columns: mapColumns.value }),
        });
        batch.value = data.batch;
        rows.value = [];
        closeMapping();
        // The rows were replaced: show them from page 1 (changing the filter reloads by itself).
        if (filter.value === 'all') await load(1);
        else filter.value = 'all';
    } catch (e: any) {
        error.value = e.message;
    } finally {
        mapSaving.value = false;
    }
};
// Open the panel once per batch when the server could not find the columns.
watch(needsMapping, (needed) => {
    if (needed && batch.value && mapAutoOpened !== batch.value.id) {
        mapAutoOpened = batch.value.id;
        openMapping();
    }
});
</script>

<template>
    <div v-if="open" class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-2 sm:p-4" @click.self="step === 'review' ? null : emit('close')">
        <div class="flex max-h-[96vh] w-full max-w-7xl flex-col overflow-hidden rounded-xl border border-gray-200 bg-white" role="dialog" aria-modal="true" aria-labelledby="import-title">
            <div class="flex items-start justify-between gap-3 border-b border-gray-100 px-5 py-4">
                <div>
                    <h3 id="import-title" class="text-base font-semibold text-gray-900">{{ title }}</h3>
                    <p v-if="batch" class="text-xs text-gray-500">
                        {{ batch.original_filename }}<template v-if="!isInventory"> · {{ methodLabel(batch.extraction_method) }}<template v-if="batch.page_count"> · {{ batch.page_count }} page(s)</template></template>
                        · <a :href="`${base}/batches/${batch.id}/file`" class="text-[#4b5320] underline">original file</a>
                    </p>
                    <p v-else class="text-xs text-gray-500">CSV, Excel (.xls/.xlsx), PDF, PNG or JPG, up to 25 MB. Nothing is saved until you confirm the preview.</p>
                </div>
                <button type="button" class="text-xl text-gray-400 hover:text-gray-600" aria-label="Close" @click="emit('close')">&times;</button>
            </div>

            <div class="flex-1 space-y-4 overflow-y-auto px-5 py-4 text-sm">
                <p v-if="error" ref="errorBox" class="rounded-xl border border-rose-200 bg-rose-50 px-3 py-2 text-rose-700" role="alert">{{ error }}</p>

                <!-- Upload -->
                <form v-if="step === 'upload'" class="space-y-4" @submit.prevent="upload">
                    <div>
                        <label class="field-label" for="imp-file">Document</label>
                        <input id="imp-file" type="file" accept=".csv,.txt,.xls,.xlsx,.pdf,.png,.jpg,.jpeg" class="field-control w-full" required @change="file = ($event.target as HTMLInputElement).files?.[0] ?? null" />
                        <p v-if="isInventory" class="mt-1 text-xs text-gray-500">Only new products are added. Stock quantities are not imported.</p>
                        <p v-else class="mt-1 text-xs text-gray-500">
                            Spreadsheets and CSV are read directly; digital PDFs through their text; photos and scanned PDFs through OCR (every page).
                            Saves supplier quotes for review and admin verification. Inventory prices are never changed.
                        </p>
                    </div>
                    <div v-if="module === 'supplier_quotation'" class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                        <div>
                            <label class="field-label" for="imp-sup">Supplier</label>
                            <select id="imp-sup" v-model="meta.supplier_id" class="field-control w-full" required>
                                <option :value="null" disabled>Choose…</option>
                                <option v-for="s in suppliers ?? []" :key="s.id" :value="s.id">{{ s.name }}</option>
                            </select>
                        </div>
                        <div><label class="field-label" for="imp-ref">Quotation no.</label><input id="imp-ref" v-model="meta.reference" class="field-control w-full" /></div>
                        <div>
                            <label class="field-label" for="imp-cur">Currency</label>
                            <select id="imp-cur" v-model="meta.currency" class="field-control w-full"><option value="PHP">PHP</option><option value="USD">USD</option></select>
                        </div>
                        <div>
                            <label class="field-label" for="imp-date">Quotation date</label>
                            <input id="imp-date" v-model="meta.quoted_on" type="date" class="field-control w-full" :max="today()" aria-describedby="imp-date-hint" />
                            <p id="imp-date-hint" class="mt-1 text-[11px] text-gray-500">Leave blank to read it from the document.</p>
                        </div>
                        <div><label class="field-label" for="imp-valid">Valid until</label><input id="imp-valid" v-model="meta.valid_until" type="date" class="field-control w-full" :min="meta.quoted_on || undefined" /></div>
                        <label class="mt-6 flex items-center gap-2 text-sm"><input v-model="meta.tax_inclusive" type="checkbox" class="rounded border-gray-300" /> Prices include tax</label>
                    </div>
                    <div class="flex justify-end">
                        <button class="brand-button" :disabled="busy || !file">{{ busy ? 'Uploading…' : 'Upload and read' }}</button>
                    </div>

                    <div v-if="showHistory && history.length" class="border-t border-gray-100 pt-3">
                        <h4 class="mb-2 text-xs font-medium text-gray-500">Recent imports</h4>
                        <ul class="divide-y divide-gray-100 text-xs">
                            <li v-for="h in history" :key="h.id" class="flex items-center justify-between gap-2 py-1.5">
                                <span class="truncate">{{ h.original_filename }} · {{ new Date(h.created_at).toLocaleString() }} · {{ h.uploaded_by ?? '—' }}<template v-if="h.status === 'completed' && (h.completed_at ?? h.imported_at)"> · Imported {{ new Date((h.completed_at ?? h.imported_at) as string).toLocaleString() }}</template></span>
                                <span class="flex items-center gap-2">
                                    <span :class="statusClass(h.status === 'completed' ? 'imported' : h.status === 'failed' ? 'needs_review' : 'existing')" class="rounded-full border px-2 py-0.5">{{ h.status.replace(/_/g, ' ') }}</span>
                                    <button v-if="['ready_for_review', 'queued', 'processing', 'completed'].includes(h.status)" type="button" class="text-[#4b5320] underline" @click="openBatch(h)">Open</button>
                                </span>
                            </li>
                        </ul>
                    </div>
                </form>

                <div v-else-if="step === 'processing'" class="py-10 text-center text-gray-600" aria-live="polite">
                    <p class="font-medium">Reading the document…</p>
                    <p v-if="isInventory" class="text-xs text-gray-500">Photos and scanned PDFs can take a few minutes.</p>
                    <p v-else class="text-xs text-gray-500">Scanned PDFs and photos go through OCR page by page; long documents can take a few minutes. You can close this window and open the import again later.</p>
                </div>

                <div v-else-if="step === 'failed'" class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-rose-800" role="alert">
                    <p class="font-medium">The import failed.<template v-if="summary.failure?.stage !== 'importing'"> Nothing was saved.</template></p>
                    <p v-if="failureLine" class="text-sm">{{ failureLine }}</p>
                    <p class="text-sm">{{ batch?.error || 'No details were recorded for this import.' }}</p>
                    <button type="button" class="secondary-button mt-3" @click="reset">Upload another file</button>
                </div>

                <!-- Review -->
                <template v-else-if="step === 'review' || step === 'done'">
                    <div v-if="step === 'done' && isInventory" class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-emerald-800" role="status">
                        <p class="font-medium">{{ result?.created ?? summary.created ?? summary.imported ?? 0 }} product(s) added to inventory.</p>
                        <ul class="mt-1 space-y-0.5 text-sm">
                            <li v-if="summary.existing">{{ summary.existing }} already in inventory, not imported.</li>
                            <li v-if="summary.duplicate">{{ summary.duplicate }} duplicate(s) in the file, not imported.</li>
                            <li v-if="summary.skipped">{{ summary.skipped }} skipped.</li>
                            <li v-if="summary.inactive">{{ summary.inactive }} saved as inactive (no unit or selling price). Find them under the "Incomplete" filter.</li>
                            <li>Stock was not imported. Add stock through Stock In or a purchase order.</li>
                        </ul>
                    </div>
                    <div v-else-if="step === 'done'" class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-emerald-800">
                        Import completed<template v-if="batch?.imported_by"> by {{ batch.imported_by }}</template>:
                        {{ result?.created ?? batch?.summary?.created ?? batch?.summary?.imported }} {{ module === 'inventory' ? 'product(s) created' : 'supplier quote(s) saved for verification' }}.
                        <template v-if="module === 'inventory'"> Products with missing values are inactive and listed under the "Incomplete" filter.</template>
                    </div>

                    <div class="flex flex-wrap items-center gap-2 text-xs">
                        <button v-for="[k, l] in filterChips" :key="k"
                                type="button" class="rounded-full border px-2.5 py-1" :class="filter === k ? 'border-[#4b5320] bg-[#4b5320]/10 text-[#4b5320]' : 'border-gray-200 text-gray-600'" @click="filter = k">
                            {{ l }}<template v-if="k === 'all'"> ({{ summary.total ?? 0 }})</template><template v-else-if="k === 'attention'"> ({{ (summary.needs_review ?? 0) + (summary.duplicate ?? 0) }})</template><template v-else-if="summary[k] !== undefined"> ({{ summary[k] }})</template>
                        </button>
                        <span class="flex-1"></span>
                        <template v-if="step === 'review'">
                            <button type="button" class="secondary-button !py-1 text-xs" :disabled="busy || !summary.needs_review" @click="bulk('skip_problems')">Skip all rows needing review</button>
                            <button type="button" class="secondary-button !py-1 text-xs" :disabled="busy || !summary.skipped" @click="bulk('restore')">Restore skipped</button>
                            <button v-if="batch?.status === 'ready_for_review' && !mapOpen" type="button" class="secondary-button !py-1 text-xs" :disabled="busy" @click="openMapping">Change columns</button>
                        </template>
                    </div>

                    <p v-if="needsMapping" class="rounded-xl border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800">
                        {{ summary.mapping_message || 'The column headings in this file were not recognised. Choose the header row and the column of each field below.' }}
                        <button v-if="!mapOpen" type="button" class="ml-1 font-medium text-[#4b5320] underline" @click="openMapping">Choose columns</button>
                    </p>

                    <!-- Quotation dates: as printed in the document, as saved (YYYY-MM-DD), and corrections -->
                    <section v-if="module === 'supplier_quotation' && batch" class="space-y-3 rounded-xl border p-4" :class="dateBlocked ? 'border-rose-200' : 'border-gray-200'" aria-labelledby="dates-title">
                        <div>
                            <h4 id="dates-title" class="text-sm font-semibold text-gray-900">Quotation dates</h4>
                            <p class="text-xs text-gray-500">Dates are saved as YYYY-MM-DD. A date that could be read two ways is never guessed: set it below.</p>
                        </div>
                        <dl class="grid grid-cols-1 gap-3 text-xs sm:grid-cols-2">
                            <div v-for="[f, l] in ([['quoted_on', 'Quotation date'], ['valid_until', 'Valid until']] as const)" :key="f" class="rounded-lg bg-gray-50 px-3 py-2">
                                <dt class="font-medium text-gray-600">{{ l }}</dt>
                                <dd class="mt-0.5">
                                    <span class="font-mono text-sm text-gray-900">{{ batch.meta?.[f] || 'Not set' }}</span>
                                    <span v-if="dateSource(f)" class="ml-1 text-gray-500">· {{ dateSource(f) }}</span>
                                </dd>
                                <dd v-if="docDate(f)" class="mt-0.5 text-gray-500">
                                    In the document: <span class="font-mono text-gray-800">"{{ docDate(f)?.original }}"</span> next to "{{ docDate(f)?.label }}"<template v-if="docDate(f)?.value"> → {{ docDate(f)?.value }} ({{ docDate(f)?.format }})</template>
                                </dd>
                                <dd v-else-if="step === 'review'" class="mt-0.5 text-gray-400">Not found in the document.</dd>
                            </div>
                        </dl>
                        <ul v-if="dateIssues.length" class="space-y-1 text-xs" role="list">
                            <li v-for="(issue, i) in dateIssues" :key="i" :class="issueClass(issue.level)">{{ issue.message }}</li>
                        </ul>
                        <form v-if="step === 'review'" class="flex flex-wrap items-end gap-3" @submit.prevent="saveDates">
                            <div>
                                <label class="field-label" for="rev-date">Quotation date</label>
                                <input id="rev-date" v-model="dateForm.quoted_on" type="date" class="field-control" :max="today()" required />
                            </div>
                            <div>
                                <label class="field-label" for="rev-valid">Valid until</label>
                                <input id="rev-valid" v-model="dateForm.valid_until" type="date" class="field-control" :min="dateForm.quoted_on || undefined" />
                            </div>
                            <button class="secondary-button !py-1.5 text-xs" :disabled="dateSaving || !dateForm.quoted_on">{{ dateSaving ? 'Saving…' : 'Save dates' }}</button>
                        </form>
                    </section>

                    <!-- Choose columns by hand -->
                    <section v-if="mapOpen && step === 'review'" class="space-y-3 rounded-xl border border-gray-200 p-4" aria-labelledby="map-title">
                        <div class="flex items-start justify-between gap-3">
                            <div>
                                <h4 id="map-title" class="text-sm font-semibold text-gray-900">Choose columns</h4>
                                <p class="text-xs text-gray-500">Pick the row that holds the column headings, then the column of each field. Fields marked * are required.</p>
                            </div>
                            <button type="button" class="text-xl text-gray-400 hover:text-gray-600" aria-label="Close column choice" @click="closeMapping">&times;</button>
                        </div>

                        <p v-if="mapLoading" class="text-xs text-gray-500" aria-live="polite">Loading the table read from the file…</p>
                        <template v-else-if="table">
                            <p v-if="!table.width" class="text-xs text-gray-600">No table columns were read from this file, so there are no columns to choose. Check the original file, or upload a clearer copy.</p>
                            <template v-else>
                                <div class="max-h-64 overflow-auto rounded-xl border border-gray-200">
                                    <table class="w-full text-left text-xs">
                                        <caption class="sr-only">First rows read from the file</caption>
                                        <thead class="sticky top-0 bg-gray-50 text-gray-500">
                                            <tr>
                                                <th scope="col" class="px-2 py-1.5">Row</th>
                                                <th v-for="c in table.width" :key="c" scope="col" class="whitespace-nowrap px-2 py-1.5">
                                                    {{ columnLetter(c - 1) }}<span v-if="columnHeading(c - 1)" class="font-normal"> · {{ columnHeading(c - 1) }}</span>
                                                    <span v-if="fieldAt(c - 1)" class="block font-normal text-[#4b5320]">{{ fieldAt(c - 1) }}</span>
                                                </th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <tr v-for="(cells, r) in table.rows" :key="r" class="border-t border-gray-100" :class="r === mapHeader ? 'bg-[#4b5320]/10 font-medium text-gray-900' : mapHeader !== null && r < mapHeader ? 'text-gray-400' : 'text-gray-700'">
                                                <th scope="row" class="px-2 py-1 font-normal text-gray-400">{{ r + 1 }}<span v-if="r === mapHeader" class="sr-only"> (header row)</span></th>
                                                <td v-for="(cell, c) in cells" :key="c" class="max-w-48 truncate px-2 py-1" :title="cell">{{ cell }}</td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                                <p v-if="table.total_rows > table.rows.length" class="text-[10px] text-gray-500">Showing the first {{ table.rows.length }} of {{ table.total_rows }} rows. Rows above the header row are not imported.</p>
                                <p v-else class="text-[10px] text-gray-500">Rows above the header row are not imported.</p>

                                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
                                    <div>
                                        <label class="field-label" for="map-header">Header row</label>
                                        <select id="map-header" v-model="mapHeader" class="field-control w-full">
                                            <option :value="null">No header row</option>
                                            <option v-for="r in headerOptions" :key="r" :value="r">Row {{ r + 1 }}</option>
                                        </select>
                                    </div>
                                    <div v-for="f in table.fields" :key="f.key">
                                        <label class="field-label" :for="`map-${f.key}`">
                                            {{ f.label }}<span v-if="f.required" class="text-rose-600" aria-hidden="true"> *</span><span v-if="f.required" class="sr-only"> (required)</span>
                                        </label>
                                        <select :id="`map-${f.key}`" v-model="mapColumns[f.key]" class="field-control w-full" :class="clashFor(f.key) || (f.required && mapColumns[f.key] === null) ? 'border-rose-300 bg-rose-50' : ''"
                                                :required="f.required" :aria-invalid="clashFor(f.key) || (f.required && mapColumns[f.key] === null)">
                                            <option :value="null">— Not in file —</option>
                                            <option v-for="c in table.width" :key="c" :value="c - 1">{{ columnOption(c - 1) }}</option>
                                        </select>
                                    </div>
                                </div>

                                <ul v-if="mapProblems.length" class="space-y-0.5 text-xs text-rose-700" role="alert">
                                    <li v-for="(p, n) in mapProblems" :key="n">{{ p }}</li>
                                </ul>
                                <p class="text-xs text-amber-800">Applying columns rebuilds the preview; changes you made to rows are discarded.</p>
                                <div class="flex justify-end gap-2">
                                    <button type="button" class="secondary-button" :disabled="mapSaving" @click="closeMapping">Cancel</button>
                                    <button type="button" class="brand-button" :disabled="mapSaving || mapProblems.length > 0" @click="applyMapping">{{ mapSaving ? 'Applying…' : 'Apply columns' }}</button>
                                </div>
                            </template>
                        </template>
                    </section>

                    <p v-if="summary.duplicate_batch && step === 'review'" class="rounded-xl border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800">
                        <template v-if="isInventory">This file was imported before<template v-if="summary.duplicate_batch.completed_at"> on {{ new Date(summary.duplicate_batch.completed_at).toLocaleDateString() }}</template>. Products already in inventory are marked below.</template>
                        <template v-else>This exact file ({{ summary.duplicate_batch.filename }}) was already imported previously<template v-if="summary.duplicate_batch.completed_at"> on {{ new Date(summary.duplicate_batch.completed_at).toLocaleString() }}</template>. Any records that already exist are marked below so they will not be duplicated.</template>
                    </p>
                    <p v-if="isInventory && step === 'review' && summary.stock_in_file" class="text-xs text-gray-600">
                        Stock quantities in the file are not imported. Add stock through Stock In or a purchase order.
                    </p>
                    <p v-if="!isInventory && batch?.uses_ocr && step === 'review'" class="rounded-xl border border-amber-200 bg-amber-50 px-3 py-2 text-amber-800">
                        This document was read by OCR. OCR helps with typing but is not a source of truth: compare every value with the original file before importing.
                    </p>
                    <details v-if="!isInventory && summary.unparsed_lines?.length" class="text-xs text-gray-600">
                        <summary class="cursor-pointer">Text above the table, not imported ({{ summary.unparsed_lines.length }} lines)</summary>
                        <ul class="mt-1 list-disc pl-5"><li v-for="(l, i) in summary.unparsed_lines" :key="i">{{ l }}</li></ul>
                    </details>

                    <div class="overflow-x-auto rounded-xl border border-gray-200">
                        <table class="w-full text-left text-xs">
                            <thead class="bg-gray-50 text-gray-500">
                                <tr>
                                    <th class="px-2 py-2">Row</th>
                                    <th v-for="f in fields" :key="f.key" class="px-2 py-2" :class="f.numeric ? 'text-right' : ''">{{ f.label }}</th>
                                    <th v-if="module === 'supplier_quotation'" class="px-2 py-2">Inventory product</th>
                                    <th class="px-2 py-2">{{ isInventory ? 'Status' : 'Status and checks' }}</th>
                                    <th class="px-2 py-2"><span class="sr-only">Skip</span></th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-if="!rows.length"><td :colspan="fields.length + 4" class="px-3 py-6 text-center text-gray-500">No rows in this view.</td></tr>
                                <tr v-for="row in rows" :key="row.id" class="border-t border-gray-100 align-top" :class="row.skipped ? 'opacity-60' : ''">
                                    <td class="px-2 py-1.5 text-gray-400">{{ row.row_number }}</td>
                                    <td v-for="f in fields" :key="f.key" class="px-1 py-1" :class="f.width">
                                        <input :value="display(row.values?.[f.key])" :disabled="locked(row)" :inputmode="f.numeric ? 'decimal' : 'text'" :placeholder="cellPlaceholder(row, f.key)" :aria-label="`${f.label}, row ${row.row_number}`"
                                               class="w-full rounded-lg border px-2 py-1 placeholder:text-[10px] placeholder:font-mono placeholder:text-gray-300" :class="[f.numeric ? 'text-right font-mono' : '', fieldIssue(row, f.key) ? 'border-rose-300 bg-rose-50' : 'border-gray-200']"
                                               @change="editField(row, f.key, ($event.target as HTMLInputElement).value)" />
                                        <div v-if="!isInventory && sourceBadge(row, f.key)" class="mt-0.5 text-[10px] text-gray-500">
                                            {{ sourceBadge(row, f.key) === 'doc' ? 'extracted' : sourceBadge(row, f.key) === 'calc' ? 'system-calculated' : sourceBadge(row, f.key) === 'gen' ? 'system-generated' : 'user-edited' }}<template v-if="row.extracted?.[f.key] && sourceBadge(row, f.key) === 'edited'"> · doc: {{ row.extracted[f.key] }}</template>
                                        </div>
                                        <div v-else-if="!isInventory && (row.values?.[f.key] === null || row.values?.[f.key] === undefined)" class="mt-0.5 text-[10px] font-mono text-gray-400">NULL (missing)</div>
                                    </td>
                                    <td v-if="module === 'supplier_quotation'" class="min-w-56 px-1 py-1">
                                        <div v-if="row.matched_product" class="flex items-center justify-between gap-1">
                                            <span><span class="font-medium">{{ row.matched_product.name }}</span> <span class="font-mono text-gray-500">{{ row.matched_product.sku }}</span></span>
                                            <button v-if="!locked(row)" type="button" class="text-gray-400 hover:text-gray-700" aria-label="Clear product" @click="patchRow(row, { product_id: null })">&times;</button>
                                        </div>
                                        <template v-else-if="!locked(row)">
                                            <div v-for="s in row.suggestions ?? []" :key="s.product_id">
                                                <button type="button" class="text-left text-[#4b5320] underline" @click="patchRow(row, { product_id: s.product_id })">{{ s.name }} ({{ s.sku }}, {{ s.confidence }}%)</button>
                                            </div>
                                            <div v-if="searchFor === row.id" class="mt-1">
                                                <input v-model="searchQ" class="w-full rounded-lg border border-gray-200 px-2 py-1" placeholder="Search name or SKU" aria-label="Search products" @input="searchProducts" />
                                                <button v-for="p in searchResults" :key="p.id" type="button" class="block text-left hover:underline" @click="patchRow(row, { product_id: p.id }); searchFor = null">{{ p.name }} <span class="font-mono text-gray-500">{{ p.sku }}</span></button>
                                            </div>
                                            <button v-else type="button" class="mt-0.5 text-gray-500 underline" @click="searchFor = row.id; searchQ = row.values?.description ?? ''; searchProducts()">Find product…</button>
                                        </template>
                                    </td>
                                    <td class="min-w-64 px-2 py-1.5">
                                        <div class="flex flex-wrap items-center gap-1">
                                            <span :class="statusClass(row.status)" class="rounded-full border px-2 py-0.5">{{ statusText(row.status) }}</span>
                                            <span v-if="!isInventory" :class="confidenceClass(row)" class="rounded-full border px-2 py-0.5 text-[10px]">{{ confidenceLabel(row) }}</span>
                                        </div>
                                        <ul class="mt-1 space-y-0.5">
                                            <li v-for="(i, n) in row.issues ?? []" :key="n" :class="issueClass(i.level)">{{ i.message }}</li>
                                        </ul>
                                        <p v-if="!isInventory && row.raw" class="mt-0.5 truncate text-[10px] text-gray-400" :title="row.raw">Read: {{ row.raw }}</p>
                                    </td>
                                    <td class="px-2 py-1.5">
                                        <label v-if="!locked(row) && row.status !== 'existing'" class="flex items-center gap-1 whitespace-nowrap">
                                            <input type="checkbox" :checked="row.skipped" class="rounded border-gray-300" @change="patchRow(row, { skipped: ($event.target as HTMLInputElement).checked })" /> Skip
                                        </label>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <div v-if="pager.last_page > 1" class="flex items-center justify-end gap-2 text-xs">
                        <button type="button" class="secondary-button !py-1" :disabled="pager.current_page <= 1" @click="load(pager.current_page - 1)">Previous</button>
                        <span>Page {{ pager.current_page }} of {{ pager.last_page }} ({{ pager.total }} rows)</span>
                        <button type="button" class="secondary-button !py-1" :disabled="pager.current_page >= pager.last_page" @click="load(pager.current_page + 1)">Next</button>
                    </div>
                </template>
            </div>

            <div v-if="step === 'review'" class="flex flex-wrap items-center justify-end gap-3 border-t border-gray-100 bg-gray-50/50 px-5 py-3">
                <label v-if="batch?.uses_ocr" class="mr-auto flex items-center gap-2 text-sm">
                    <input v-model="ocrChecked" type="checkbox" class="rounded border-gray-300" /> I checked the values against the {{ isInventory ? 'file' : 'original document' }}
                </label>
                <span v-if="dateBlocked" class="text-xs text-rose-700">Set the quotation date before importing.</span>
                <span v-if="isInventory && summary.needs_review" class="text-xs text-rose-700">Fix or skip the {{ summary.needs_review }} row(s) that need review.</span>
                <span v-else-if="!isInventory" class="text-xs text-gray-500">{{ summary.ready ?? 0 }} ready · {{ summary.needs_review ?? 0 }} need review</span>
                <button type="button" class="secondary-button" @click="cancelImport">Cancel import</button>
                <button type="button" class="brand-button" :disabled="busy || !canConfirm" @click="confirmImport">{{ busy ? 'Importing…' : (isInventory ? `Import ${summary.ready ?? 0} product(s)` : `Import ${summary.ready ?? 0} row(s)`) }}</button>
            </div>
            <div v-else-if="step === 'done'" class="flex justify-end gap-2 border-t border-gray-100 px-5 py-3">
                <button type="button" class="secondary-button" @click="reset">Import another file</button>
                <button type="button" class="brand-button" @click="emit('close')">Close</button>
            </div>
        </div>
    </div>
</template>
