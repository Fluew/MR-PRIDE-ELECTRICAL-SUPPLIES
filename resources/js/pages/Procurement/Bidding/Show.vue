<script setup lang="ts">
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { ArrowLeft, FileText, Paperclip, PencilLine, Plus, ScanText, Trash2 } from '@lucide/vue';
import { computed, ref } from 'vue';
import BidQuotationImport, { type QuotationValues } from '@/components/procurement/BidQuotationImport.vue';
import SpecularButton from '@/components/ui/SpecularButton.vue';
import { useCanDelete } from '@/composables/useCanDelete';
import { useCurrency } from '@/composables/useCurrency';

interface Row {
    id: number;
    supplier: { id: number; name: string };
    unit_price: number;
    quantity: number;
    total: number;
    lead_time_days: number;
    quotation_date: string;
    received_at: string;
    valid_until: string | null;
    expired: boolean;
    quotation_reference: string | null;
    notes: string | null;
    has_document: boolean;
    document_name: string | null;
    revision: number;
    status: string;
    is_lowest_price: boolean;
    is_shortest_lead_time: boolean;
    price_above_lowest_pct: number | null;
    quantity_short: number;
    supplier_score: number | null;
    supplier_classification: string | null;
}

const props = defineProps<{
    event: {
        id: number; reference: string; status: string; submission_deadline: string; deadline_passed: boolean; request_number: string | null;
        item: string | null; sku: string | null; quantity: number | null; unit: string | null; rfq_notes: string | null; award_remarks: string | null;
        cancel_reason: string | null; awarded_by: string | null; awarded_at: string | null; created_by: string | null; closed_at: string | null;
        closed_by: string | null; rfq_issued_at: string | null; awarded_supplier: string | null;
        purchase_order: { id: number; po_number: string; status: string } | null;
        request: { request_number: string; requested_by: string | null; justification: string | null };
    };
    invitations: { supplier_id: number; name: string; email: string | null; phone: string | null; contact_person: string | null; rfq_sent_at: string | null; has_bid: boolean }[];
    comparison: { requested: { quantity: number; unit: string; estimated_cost: number }; rows: Row[]; lowest_price: number | null; shortest_lead_time: number | null };
    availableSuppliers: { id: number; name: string; email: string | null }[];
    history: { at: string | null; event: string; description: string; by: string | null }[];
    isAdmin: boolean;
    minSuppliers: number;
}>();

const page = usePage();
const teamSlug = computed(() => (page.props as any).currentTeam?.slug ?? '');
const base = computed(() => `/${teamSlug.value}/procurement/bidding/${props.event.id}`);
const { formatMoney } = useCurrency();
const canDelete = useCanDelete();

const isOpen = computed(() => props.event.status === 'Open for Quotations');
const isDraft = computed(() => props.event.status === 'Draft');
const isClosed = computed(() => props.event.status === 'Closed');
const isFinal = computed(() => ['Awarded', 'Cancelled'].includes(props.event.status));
const awaiting = computed(() => props.invitations.filter((i) => !i.has_bid));

const fmt = (iso: string | null) => (iso ? new Date(iso).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' }) : '—');
const day = (d: string | null) => (d ? new Date(`${d}T00:00:00`).toLocaleDateString(undefined, { dateStyle: 'medium' }) : '—');
// The server stores UTC. A datetime-local field is the user's local clock, so it is converted to an
// exact instant (ISO, UTC) before sending, and a server instant is converted back for editing.
const toUtc = (local: string) => (local ? new Date(local).toISOString() : local);
const toLocalInput = (iso: string) => new Date(new Date(iso).getTime() - new Date(iso).getTimezoneOffset() * 60000).toISOString().slice(0, 16);
const nowLocal = () => new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 16);
const today = () => nowLocal().slice(0, 10);

const act = (path: string, data: Record<string, unknown> = {}) => router.post(`${base.value}/${path}`, data as any, { preserveScroll: true });

// ── Invite supplier ──
// ── Invite suppliers (checklist: tick several, invite them together) ──
const inviteIds = ref<number[]>([]);
const inviting = ref(false);
// The checklist is hidden behind an "Invite suppliers" button and closes after inviting.
const showInvite = ref(false);
const closeInvite = () => {
    showInvite.value = false;
    inviteIds.value = [];
};
const allTicked = computed(() => props.availableSuppliers.length > 0 && inviteIds.value.length === props.availableSuppliers.length);
const toggleAll = () => (inviteIds.value = allTicked.value ? [] : props.availableSuppliers.map((s) => s.id));
const invite = () => {
    if (!inviteIds.value.length) return;
    inviting.value = true;
    router.post(`${base.value}/suppliers`, { supplier_ids: inviteIds.value }, {
        preserveScroll: true,
        // runWorkflow redirects back on errors too: close only when nothing failed.
        onSuccess: (p) => {
            if (!Object.keys((p.props as any).errors ?? {}).length) closeInvite();
        },
        onFinish: () => (inviting.value = false),
    });
};
const uninvite = (id: number) => router.delete(`${base.value}/suppliers/${id}`, { preserveScroll: true });

// ── Record / revise quotation (same fields) ──
const blank = () => ({
    supplier_id: '' as number | '', unit_price: '' as number | '', quantity: (props.event.quantity ?? '') as number | '', lead_time_days: '' as number | '',
    quotation_date: today(), received_at: nowLocal(), valid_until: '', quotation_reference: '', notes: '', document: null as File | null, reason: '',
});
const form = useForm(blank());
const editing = ref<Row | null>(null);
// The quotation form is a pop-up card: opened by "Record quotation" or a row's "Revise".
const showBidForm = ref(false);

const startRecord = () => {
    editing.value = null;
    form.defaults(blank()).reset();
    form.clearErrors();
    showBidForm.value = true;
};
const closeBidForm = () => {
    showBidForm.value = false;
    importFile.value = null;
    readFrom.value = null;
    entryMode.value = 'manual';
    editing.value = null;
    form.defaults(blank()).reset();
    form.clearErrors();
};
const startRevise = (r: Row) => {
    editing.value = r;
    showBidForm.value = true;
    form.defaults({
        supplier_id: r.supplier.id, unit_price: r.unit_price, quantity: r.quantity, lead_time_days: r.lead_time_days, quotation_date: r.quotation_date,
        received_at: toLocalInput(r.received_at), valid_until: r.valid_until ?? '', quotation_reference: r.quotation_reference ?? '', notes: r.notes ?? '', document: null, reason: '',
    }).reset();
    form.clearErrors();
};
const submitBid = () => {
    const url = editing.value ? `${base.value}/bids/${editing.value.id}` : `${base.value}/bids`;
    form.transform((d) => ({ ...d, received_at: toUtc(d.received_at), document: d.document ?? undefined })).post(url, {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: closeBidForm,
    });
};

// ── Read the quotation from a document (OCR) or CSV, same logic as the purchase order import ──
const importFile = ref<File | null>(null);
const importInput = ref<HTMLInputElement | null>(null);
const readFrom = ref<string | null>(null);
// How the quotation is recorded: typed in by hand, or uploaded and read (OCR / CSV).
const entryMode = ref<'manual' | 'upload'>('manual');
const setEntryMode = (mode: 'manual' | 'upload') => {
    entryMode.value = mode;
    if (mode === 'manual') {
        // Manual entry has no file: a read in progress and the attached file are dropped; typed values stay.
        importFile.value = null;
        readFrom.value = null;
        form.document = null;
    }
};
const onImportFile = (e: Event) => {
    const input = e.target as HTMLInputElement;
    importFile.value = input.files?.[0] ?? null;
    input.value = ''; // the same file can be chosen again
};
const applyRead = (v: QuotationValues) => {
    if (v.supplier_id !== '') form.supplier_id = v.supplier_id;
    form.unit_price = v.unit_price;
    form.quantity = v.quantity;
    if (v.quotation_date) form.quotation_date = v.quotation_date;
    if (v.quotation_reference) form.quotation_reference = v.quotation_reference;
    form.notes = form.notes ? `${form.notes}\n${v.notes}` : v.notes;
    form.document = importFile.value; // the file read is attached as the quotation
    readFrom.value = importFile.value?.name ?? null;
    importFile.value = null;
};

// ── Cancel / award ──
const cancelling = ref(false);
const cancelReason = ref('');
const confirmCancel = () => router.post(`${base.value}/cancel`, { reason: cancelReason.value }, { preserveScroll: true, onSuccess: () => ((cancelling.value = false), (cancelReason.value = '')) });

const deleting = ref(false);
const deleteConfirm = ref('');
const openDelete = () => {
    deleteConfirm.value = '';
    deleting.value = true;
};
const confirmDelete = () => {
    if (deleteConfirm.value !== 'YES') return;
    router.delete(base.value, { data: { confirmation: deleteConfirm.value }, onFinish: () => (deleting.value = false) });
};

const awarding = ref<Row | null>(null);
const awardRemarks = ref('');
const needsRemarks = computed(() => !!awarding.value && (props.comparison.rows.length < props.minSuppliers || awarding.value.quantity_short > 0));
const confirmAward = () => {
    if (!awarding.value) return;
    router.post(`${base.value}/award`, { bid_id: awarding.value.id, remarks: awardRemarks.value }, { preserveScroll: true, onSuccess: () => ((awarding.value = null), (awardRemarks.value = '')) });
};

const badge = (s: string) =>
    ({
        Draft: 'bg-gray-50 text-gray-700 border-gray-200',
        'Open for Quotations': 'bg-blue-50 text-blue-700 border-blue-200',
        Closed: 'bg-amber-50 text-amber-700 border-amber-200',
        Awarded: 'bg-emerald-50 text-emerald-700 border-emerald-200',
        Cancelled: 'bg-rose-50 text-rose-700 border-rose-200',
    })[s] ?? 'bg-gray-50 text-gray-700 border-gray-200';
const errorList = computed(() => Object.values(form.errors).filter(Boolean));
const topErrors = computed(() => Object.values((page.props as any).errors ?? {}).filter(Boolean) as string[]);
</script>

<template>
    <Head :title="event.reference" />

    <div class="workspace-page">
        <div class="page-header">
            <div>
                <Link :href="`/${teamSlug}/procurement/bidding`" class="mb-1 inline-flex items-center gap-1 text-xs text-gray-500 hover:text-gray-800"><ArrowLeft class="size-3.5" aria-hidden="true" /> Bidding events</Link>
                <h1 class="page-title flex flex-wrap items-center gap-3">
                    {{ event.reference }}
                    <span :class="badge(event.status)" class="rounded-full border px-2.5 py-0.5 text-xs font-medium" data-test="event-status">{{ event.status }}</span>
                </h1>
                <p class="page-subtitle">{{ event.item }} · {{ event.quantity }} {{ event.unit }} · from {{ event.request.request_number }}</p>
            </div>
            <div class="page-actions flex flex-wrap gap-2">
                <a v-if="!isDraft" :href="`${base}/rfq`" target="_blank" rel="noopener" class="secondary-button inline-flex items-center gap-1.5"><FileText class="size-4" aria-hidden="true" /> RFQ document</a>
                <button v-if="isDraft" type="button" class="brand-button" data-test="issue-rfq" @click="act('issue')">Issue RFQ</button>
                <button v-if="isOpen" type="button" class="secondary-button" data-test="close-bidding" @click="act('close')">Close quotations</button>
                <button v-if="!isFinal" type="button" class="secondary-button text-rose-700" @click="cancelling = true">Cancel event</button>
                <button v-if="canDelete && event.status !== 'Awarded'" type="button" class="inline-flex items-center gap-1.5 rounded-lg border border-red-200 px-3 py-2 text-sm font-medium text-red-600 hover:bg-red-50" data-test="delete-bidding" @click="openDelete">
                    <Trash2 class="size-4" aria-hidden="true" /> Delete
                </button>
            </div>
        </div>

        <div v-if="topErrors.length" class="rounded-lg border border-rose-200 bg-rose-50 p-3 text-sm text-rose-800" role="alert">
            <p v-for="(e, i) in topErrors" :key="i">{{ e }}</p>
        </div>

        <!-- Summary -->
        <div class="panel grid gap-4 p-4 text-sm sm:grid-cols-2 lg:grid-cols-4">
            <div><div class="text-xs text-gray-500">Submission deadline</div><div class="font-medium">{{ fmt(event.submission_deadline) }}</div><div v-if="event.deadline_passed && (isDraft || isOpen)" class="text-xs text-amber-700">deadline passed</div></div>
            <div><div class="text-xs text-gray-500">RFQ issued</div><div class="font-medium">{{ fmt(event.rfq_issued_at) }}</div></div>
            <div><div class="text-xs text-gray-500">Created by</div><div class="font-medium">{{ event.created_by ?? '—' }}</div></div>
            <div><div class="text-xs text-gray-500">Estimated cost (request)</div><div class="font-medium">{{ formatMoney(comparison.requested.estimated_cost) }}</div></div>
            <div v-if="event.rfq_notes" class="sm:col-span-2 lg:col-span-4"><div class="text-xs text-gray-500">Notes on the RFQ</div><div class="whitespace-pre-line">{{ event.rfq_notes }}</div></div>
            <div v-if="event.cancel_reason" class="sm:col-span-2 lg:col-span-4"><div class="text-xs text-gray-500">Cancelled because</div><div>{{ event.cancel_reason }}</div></div>
        </div>

        <!-- Awarded banner -->
        <div v-if="event.status === 'Awarded'" class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-900" data-test="award-banner">
            Awarded to <strong>{{ event.awarded_supplier }}</strong> by {{ event.awarded_by }} on {{ fmt(event.awarded_at) }}.
            <template v-if="event.purchase_order">
                Draft purchase order <strong class="font-mono">{{ event.purchase_order.po_number }}</strong> ({{ event.purchase_order.status }}) was created from the awarded quotation: review and send it in
                <Link :href="`/${teamSlug}/procurement`" class="underline">Procurement</Link>.
            </template>
            <div v-if="event.award_remarks" class="mt-1 text-emerald-800">Owner's remarks: {{ event.award_remarks }}</div>
        </div>

        <!-- Invited suppliers -->
        <section class="panel p-4" aria-labelledby="inv-title">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h2 id="inv-title" class="section-title">Invited suppliers</h2>
                <p class="text-xs text-gray-500">{{ invitations.length }} invited · {{ awaiting.length }} awaiting a quotation · at least {{ minSuppliers }} needed to issue</p>
            </div>
            <ul class="mt-3 divide-y divide-gray-100">
                <li v-for="i in invitations" :key="i.supplier_id" class="flex flex-wrap items-center justify-between gap-2 py-2 text-sm">
                    <div>
                        <span class="font-medium text-gray-900">{{ i.name }}</span>
                        <span class="ml-2 text-xs text-gray-500">{{ [i.contact_person, i.email, i.phone].filter(Boolean).join(' · ') }}</span>
                    </div>
                    <div class="flex items-center gap-3 text-xs">
                        <span :class="i.has_bid ? 'text-emerald-700' : 'text-gray-400'">{{ i.has_bid ? 'Quotation recorded' : 'Awaiting quotation' }}</span>
                        <button v-if="(isDraft || isOpen) && !i.has_bid" type="button" class="text-rose-600 underline" @click="uninvite(i.supplier_id)">Remove</button>
                    </div>
                </li>
            </ul>
            <div v-if="(isDraft || isOpen) && !showInvite" class="mt-3 border-t border-gray-100 pt-3">
                <button
                    type="button"
                    class="secondary-button"
                    data-test="invite-toggle"
                    aria-controls="invite-checklist"
                    :aria-expanded="false"
                    :disabled="!availableSuppliers.length"
                    :title="availableSuppliers.length ? '' : 'Every active supplier is already invited'"
                    @click="showInvite = true"
                >
                    <Plus class="size-4" aria-hidden="true" /> Invite suppliers
                </button>
            </div>
            <div v-if="(isDraft || isOpen) && showInvite" id="invite-checklist" role="group" aria-labelledby="invite-more" class="mt-3 border-t border-gray-100 pt-3" data-test="invite-checklist">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <p id="invite-more" class="text-xs font-semibold text-gray-700">Invite suppliers <span class="font-normal text-gray-500">(tick all you want, then invite)</span></p>
                    <div class="flex items-center gap-3">
                        <button v-if="availableSuppliers.length > 1" type="button" class="text-xs text-[#4b5320] underline" @click="toggleAll">{{ allTicked ? 'Clear all' : 'Select all' }}</button>
                        <button type="button" class="text-lg leading-none text-gray-400 hover:text-gray-600" aria-label="Close the supplier list" :aria-expanded="true" aria-controls="invite-checklist" @click="closeInvite">&times;</button>
                    </div>
                </div>
                <div v-if="availableSuppliers.length" class="mt-2 grid max-h-56 grid-cols-1 gap-1 overflow-y-auto rounded-lg border border-gray-200 p-2 sm:grid-cols-2">
                    <label v-for="s in availableSuppliers" :key="s.id" class="flex cursor-pointer items-center gap-2 rounded px-1.5 py-1 text-sm hover:bg-gray-50">
                        <input v-model="inviteIds" type="checkbox" :value="s.id" />
                        <span class="min-w-0 truncate">{{ s.name }} <span v-if="s.email" class="text-xs text-gray-400">{{ s.email }}</span></span>
                    </label>
                </div>
                <p v-else class="mt-2 text-xs text-gray-500">Every active supplier is already invited.</p>
                <p v-if="(page.props as any).errors?.supplier_ids" class="mt-1 text-xs text-rose-600" role="alert">{{ (page.props as any).errors.supplier_ids }}</p>
                <div v-if="availableSuppliers.length" class="mt-2 flex justify-end gap-2">
                    <button type="button" class="secondary-button" @click="closeInvite">Cancel</button>
                    <button type="button" class="brand-button" data-test="invite-selected" :disabled="!inviteIds.length || inviting" @click="invite">
                        {{ inviting ? 'Inviting…' : inviteIds.length ? `Invite ${inviteIds.length} supplier${inviteIds.length === 1 ? '' : 's'}` : 'Invite' }}
                    </button>
                </div>
            </div>
        </section>

        <!-- Comparison -->
        <section class="panel overflow-hidden" aria-labelledby="cmp-title" data-test="comparison">
            <div class="flex flex-wrap items-start justify-between gap-3 p-4">
                <div>
                    <h2 id="cmp-title" class="section-title">Bid comparison</h2>
                    <p class="section-subtitle">
                        {{ comparison.rows.length }} of {{ invitations.length }} invited suppliers have a recorded quotation. Requested: {{ comparison.requested.quantity }} {{ comparison.requested.unit }}.
                        Nothing is awarded automatically: the owner decides.
                    </p>
                </div>
                <button
                    v-if="isOpen"
                    type="button"
                    class="brand-button inline-flex items-center gap-1.5"
                    :disabled="!awaiting.length"
                    :title="awaiting.length ? 'Record a quotation a supplier sent you' : 'Every invited supplier already has a quotation'"
                    data-test="record-quotation"
                    @click="startRecord"
                >
                    <Plus class="size-4" aria-hidden="true" /> Record quotation
                </button>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="border-y border-gray-200 bg-gray-50 text-xs text-gray-500 uppercase">
                        <tr>
                            <th class="px-4 py-3">Supplier</th>
                            <th class="px-4 py-3 text-right">Unit price</th>
                            <th class="px-4 py-3 text-right">Qty offered</th>
                            <th class="px-4 py-3 text-right">Total</th>
                            <th class="px-4 py-3 text-right">Lead time</th>
                            <th class="px-4 py-3">Quotation</th>
                            <th class="px-4 py-3">Supplier score</th>
                            <th class="px-4 py-3">Flags</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <tr v-for="r in comparison.rows" :key="r.id" :class="r.status === 'Awarded' ? 'bg-emerald-50/60' : ''">
                            <td class="px-4 py-3 font-medium text-gray-900">{{ r.supplier.name }}<div v-if="r.status !== 'Submitted'" class="text-xs" :class="r.status === 'Awarded' ? 'text-emerald-700' : 'text-gray-400'">{{ r.status }}</div></td>
                            <td class="px-4 py-3 text-right font-mono" :class="r.is_lowest_price ? 'font-semibold text-emerald-700' : ''">{{ formatMoney(r.unit_price) }}<div v-if="r.price_above_lowest_pct" class="font-sans text-xs text-gray-400">+{{ r.price_above_lowest_pct }}% vs lowest</div></td>
                            <td class="px-4 py-3 text-right font-mono" :class="r.quantity_short ? 'text-amber-700' : ''">{{ r.quantity }}</td>
                            <td class="px-4 py-3 text-right font-mono">{{ formatMoney(r.total) }}</td>
                            <td class="px-4 py-3 text-right font-mono" :class="r.is_shortest_lead_time ? 'font-semibold text-emerald-700' : ''">{{ r.lead_time_days }} d</td>
                            <td class="px-4 py-3 text-xs text-gray-600">
                                {{ day(r.quotation_date) }}<span v-if="r.quotation_reference" class="font-mono"> · {{ r.quotation_reference }}</span>
                                <div>valid until {{ day(r.valid_until) }}</div>
                                <div class="text-gray-400">received {{ fmt(r.received_at) }} · rev {{ r.revision }}</div>
                                <a v-if="r.has_document" :href="`${base}/bids/${r.id}/document`" class="inline-flex items-center gap-1 text-[#4b5320] underline"><Paperclip class="size-3" aria-hidden="true" /> {{ r.document_name }}</a>
                            </td>
                            <td class="px-4 py-3 text-xs">
                                <template v-if="r.supplier_score !== null">{{ r.supplier_score.toFixed(1) }}<div class="text-gray-400">{{ r.supplier_classification }}</div></template>
                                <span v-else class="text-gray-400" title="The Supplier module has no complete evaluation for this supplier yet.">not evaluated</span>
                            </td>
                            <td class="px-4 py-3 text-xs">
                                <div class="flex flex-wrap gap-1">
                                    <span v-if="r.is_lowest_price" class="rounded bg-emerald-100 px-1.5 py-0.5 text-emerald-800">Lowest price</span>
                                    <span v-if="r.is_shortest_lead_time" class="rounded bg-blue-100 px-1.5 py-0.5 text-blue-800">Fastest</span>
                                    <span v-if="r.quantity_short" class="rounded bg-amber-100 px-1.5 py-0.5 text-amber-800">{{ r.quantity_short }} short</span>
                                    <span v-if="r.expired" class="rounded bg-rose-100 px-1.5 py-0.5 text-rose-800">Expired</span>
                                </div>
                            </td>
                            <td class="px-4 py-3 text-right whitespace-nowrap">
                                <button v-if="isOpen" type="button" class="rounded-lg border border-gray-200 px-2.5 py-1 text-xs font-medium hover:bg-gray-50" @click="startRevise(r)">Revise</button>
                                <button v-if="isClosed && isAdmin" type="button" class="ml-1 rounded-lg border border-[#4b5320] px-2.5 py-1 text-xs font-medium text-[#4b5320] hover:bg-gray-50" data-test="award-button" @click="awarding = r">Award</button>
                            </td>
                        </tr>
                        <tr v-if="!comparison.rows.length"><td colspan="9" class="py-8 text-center text-gray-500">No quotations recorded yet.</td></tr>
                    </tbody>
                </table>
            </div>
            <p v-if="isClosed && !isAdmin" class="border-t border-gray-100 p-3 text-xs text-gray-500">Quotations are closed. Only the owner (administrator) can award this bidding.</p>
            <p v-if="isClosed && isAdmin && !comparison.rows.length" class="border-t border-gray-100 p-3 text-xs text-gray-500">No quotations were recorded, so there is nothing to award. Cancel this event and send the request to bidding again.</p>
        </section>

        <!-- History -->
        <section class="panel p-4" aria-labelledby="hist-title">
            <h2 id="hist-title" class="section-title">Bidding history</h2>
            <ol class="mt-3 space-y-2 border-l border-gray-200 pl-4 text-sm">
                <li v-for="(h, i) in history" :key="i">
                    <div class="text-gray-800">{{ h.description }}</div>
                    <div class="text-xs text-gray-400">{{ fmt(h.at) }}<template v-if="h.by"> · {{ h.by }}</template></div>
                </li>
                <li v-if="!history.length" class="text-gray-400">No activity yet.</li>
            </ol>
        </section>
    </div>

    <!-- Record / revise quotation (pop-up card) -->
    <div v-if="showBidForm && isOpen" class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4">
        <section class="panel max-h-[92vh] w-full max-w-3xl overflow-y-auto p-6 shadow-xl" role="dialog" aria-modal="true" aria-labelledby="rec-title" data-test="bid-form">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <h2 id="rec-title" class="section-title">{{ editing ? `Revise quotation: ${editing.supplier.name} (revision ${editing.revision} → ${editing.revision + 1})` : 'Record a supplier quotation' }}</h2>
                    <p class="section-subtitle">Enter exactly what the supplier sent you by email or on paper. Do not estimate: leave out anything the supplier did not state.</p>
                </div>
                <button type="button" class="text-2xl leading-none text-gray-400 hover:text-gray-600" aria-label="Close" @click="closeBidForm">&times;</button>
            </div>
            <!-- How to record: type it in, or upload the supplier's file and have it read -->
            <div class="mt-4 grid grid-cols-1 gap-2 sm:grid-cols-2" role="radiogroup" aria-label="How to record the quotation">
                <button
                    v-for="m in ([
                        { key: 'manual', title: 'Enter manually', text: 'Type in the values from the supplier\'s email or paper.' },
                        { key: 'upload', title: 'Upload file', text: 'PDF, PNG or JPG read automatically (OCR), or a CSV.' },
                    ] as const)"
                    :key="m.key"
                    type="button"
                    role="radio"
                    :aria-checked="entryMode === m.key"
                    :data-test="`bid-mode-${m.key}`"
                    class="flex items-start gap-2 rounded-xl border p-3 text-left text-xs transition"
                    :class="entryMode === m.key ? 'border-[#4b5320] bg-[#4b5320]/5 ring-1 ring-[#4b5320]' : 'border-gray-200 hover:border-gray-300'"
                    @click="setEntryMode(m.key)"
                >
                    <component :is="m.key === 'manual' ? PencilLine : ScanText" class="mt-0.5 size-4 shrink-0 text-[#4b5320]" aria-hidden="true" />
                    <span><strong class="block text-sm text-gray-900">{{ m.title }}</strong><span class="text-gray-600">{{ m.text }}</span></span>
                </button>
            </div>

            <!-- Upload: the file is read, checked here, then fills the form below -->
            <div v-if="entryMode === 'upload' && importFile" class="mt-4">
                <BidQuotationImport
                    :file="importFile"
                    :read-url="`${base}/read-quotation`"
                    :suppliers="editing ? [{ supplier_id: editing.supplier.id, name: editing.supplier.name }] : awaiting"
                    :requested="{ item: event.item, sku: event.sku, quantity: event.quantity, unit: event.unit }"
                    @apply="applyRead"
                    @cancel="importFile = null"
                />
            </div>
            <div v-else-if="entryMode === 'upload'" class="mt-4 rounded-xl border border-dashed border-gray-300 bg-gray-50/60 p-4 text-xs text-gray-600">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <p class="min-w-0 flex-1">
                        <template v-if="readFrom"><span class="text-emerald-700">Filled from <strong>{{ readFrom }}</strong> (attached).</span> Check the values below and enter the lead time, then save. Or upload a different file.</template>
                        <template v-else>
                            Upload the supplier's quotation: a PDF, PNG or JPG is read automatically (OCR for scans and photos), or a CSV with ORDER DATE, PURCHASE ORDER NO.,
                            QTY ORDERED, SUPPLIER, ITEM/SKU Number, Description, UNIT COST, U/M, EXTENDED COST. You check the values before anything is saved.
                        </template>
                    </p>
                    <button type="button" class="brand-button min-h-8 px-3 py-1.5 text-xs" data-test="bid-read-document" @click="importInput?.click()">
                        <ScanText class="size-3.5" aria-hidden="true" /> {{ readFrom ? 'Upload another file' : 'Choose file' }}
                    </button>
                    <input ref="importInput" type="file" accept=".pdf,.png,.jpg,.jpeg,.csv,.txt" class="hidden" @change="onImportFile" />
                </div>
            </div>
            <!-- The form: always for manual entry; after a file was read for upload (to check, add the lead time, save) -->
            <form v-show="entryMode === 'manual' || (!importFile && readFrom)" class="mt-4 grid gap-3 sm:grid-cols-2" @submit.prevent="submitBid">
                <div v-if="!editing" class="sm:col-span-2">
                    <label class="field-label" for="b-supplier">Supplier</label>
                    <select id="b-supplier" v-model="form.supplier_id" required class="field-control w-full">
                        <option value="" disabled>Select an invited supplier</option>
                        <option v-for="i in awaiting" :key="i.supplier_id" :value="i.supplier_id">{{ i.name }}</option>
                    </select>
                </div>
                <div><label class="field-label" for="b-price">Unit price (USD)</label><input id="b-price" v-model="form.unit_price" type="number" step="0.01" min="0.01" required class="field-control w-full" /></div>
                <div><label class="field-label" for="b-qty">Quantity offered ({{ event.unit }})</label><input id="b-qty" v-model="form.quantity" type="number" min="1" required class="field-control w-full" /></div>
                <div><label class="field-label" for="b-lead">Delivery lead time (days)</label><input id="b-lead" v-model="form.lead_time_days" type="number" min="0" required class="field-control w-full" /></div>
                <div><label class="field-label" for="b-ref">Supplier's quotation no.</label><input id="b-ref" v-model="form.quotation_reference" type="text" maxlength="100" class="field-control w-full" /></div>
                <div><label class="field-label" for="b-qdate">Quotation date</label><input id="b-qdate" v-model="form.quotation_date" type="date" :max="today()" required class="field-control w-full" /></div>
                <div><label class="field-label" for="b-valid">Valid until</label><input id="b-valid" v-model="form.valid_until" type="date" :min="form.quotation_date" class="field-control w-full" /></div>
                <div class="sm:col-span-2"><label class="field-label" for="b-recv">Date and time received</label><input id="b-recv" v-model="form.received_at" type="datetime-local" required class="field-control w-full" /></div>
                <div class="sm:col-span-2"><label class="field-label" for="b-notes">Notes (terms, conditions)</label><textarea id="b-notes" v-model="form.notes" rows="2" class="field-control w-full"></textarea></div>
                <!-- Upload mode only: the file that was read is attached as the quotation document -->
                <p v-if="entryMode === 'upload' && form.document" class="flex items-center gap-1.5 text-xs text-gray-600 sm:col-span-2">
                    <Paperclip class="size-3.5" aria-hidden="true" /> Attached quotation: <strong class="text-gray-800">{{ form.document.name }}</strong>
                </p>
                <div v-if="editing" class="sm:col-span-2"><label class="field-label" for="b-reason">Reason for the revision (required)</label><input id="b-reason" v-model="form.reason" type="text" maxlength="500" required class="field-control w-full" /></div>
                <div class="flex items-end justify-end gap-2 sm:col-span-2">
                    <ul v-if="errorList.length" class="mr-auto text-xs text-rose-600" role="alert"><li v-for="(e, i) in errorList" :key="i">{{ e }}</li></ul>
                    <button type="button" class="secondary-button" @click="closeBidForm">Cancel</button>
                    <button type="submit" class="brand-button" :disabled="form.processing || (!editing && !awaiting.length)">{{ editing ? 'Save revision' : 'Record quotation' }}</button>
                </div>
            </form>
        </section>
    </div>

    <!-- Delete (administrators only): same type-YES confirmation as the rest of the system -->
    <div v-if="deleting" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
        <div class="panel w-full max-w-md p-6 shadow-xl" role="dialog" aria-modal="true" aria-labelledby="del-title">
            <h3 id="del-title" class="mb-2 text-xl font-semibold text-gray-900">Delete Bidding Event</h3>
            <p class="mb-2 text-sm text-gray-600">
                Move bidding event <span class="font-semibold text-gray-900">{{ event.reference }}</span> to the Recycle Bin?
            </p>
            <p class="mb-4 text-xs text-gray-500">
                {{ `Its ${comparison.rows.length} recorded quotation${comparison.rows.length === 1 ? '' : 's'} move with it, and ${event.request_number} can be sent to bidding again.` }} An Admin can restore or permanently delete this record from the Recycle Bin.
            </p>
            <div class="mt-4 mb-6">
                <label class="mb-1.5 block text-xs font-semibold text-gray-700" for="del-title-confirm">
                    Type <span class="rounded border border-rose-200 bg-rose-50 px-1.5 py-0.5 font-mono font-semibold text-rose-600">YES</span> to confirm deletion:
                </label>
                <input
                    id="del-title-confirm"
                    v-model="deleteConfirm"
                    type="text"
                    placeholder="Type YES here"
                    autocomplete="off"
                    class="w-full rounded-xl border border-gray-300 px-3 py-2 font-mono text-sm focus:border-rose-500 focus:ring-1 focus:ring-rose-500 focus:outline-none"
                    data-test="delete-confirm-input"
                    @keyup.enter="deleteConfirm === 'YES' && confirmDelete()"
                />
            </div>
            <div class="flex justify-end gap-3">
                <SpecularButton variant="secondary" size="md" :radius="10" @click="deleting = false">Cancel</SpecularButton>
                <SpecularButton variant="destructive" size="md" :radius="10" :disabled="deleteConfirm !== 'YES'" data-test="confirm-delete-bidding" @click="confirmDelete">Delete Event</SpecularButton>
            </div>
        </div>
    </div>

    <!-- Cancel -->
    <div v-if="cancelling" class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4" @click.self="cancelling = false">
        <div class="panel w-full max-w-md p-6 shadow-xl" role="dialog" aria-modal="true" aria-labelledby="cancel-title">
            <h3 id="cancel-title" class="text-base font-semibold text-gray-900">Cancel {{ event.reference }}?</h3>
            <p class="mt-1 text-sm text-gray-600">The purchase request stays approved and can be sent to bidding again. Recorded quotations are kept for history.</p>
            <label class="field-label mt-3" for="cancel-reason">Reason</label>
            <textarea id="cancel-reason" v-model="cancelReason" rows="2" class="field-control w-full"></textarea>
            <div class="mt-4 flex justify-end gap-2">
                <button type="button" class="secondary-button" @click="cancelling = false">Keep it</button>
                <button type="button" class="brand-button" :disabled="!cancelReason.trim()" @click="confirmCancel">Cancel event</button>
            </div>
        </div>
    </div>

    <!-- Award (owner) -->
    <div v-if="awarding" class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4" @click.self="awarding = null">
        <div class="panel w-full max-w-lg p-6 shadow-xl" role="dialog" aria-modal="true" aria-labelledby="award-title" data-test="award-dialog">
            <h3 id="award-title" class="text-base font-semibold text-gray-900">Award {{ event.reference }} to {{ awarding.supplier.name }}</h3>
            <dl class="mt-3 grid grid-cols-2 gap-2 text-sm">
                <div><dt class="text-xs text-gray-500">Unit price</dt><dd class="font-mono">{{ formatMoney(awarding.unit_price) }}</dd></div>
                <div><dt class="text-xs text-gray-500">Quantity offered</dt><dd class="font-mono">{{ awarding.quantity }} of {{ comparison.requested.quantity }}</dd></div>
                <div><dt class="text-xs text-gray-500">Total</dt><dd class="font-mono">{{ formatMoney(awarding.total) }}</dd></div>
                <div><dt class="text-xs text-gray-500">Lead time</dt><dd class="font-mono">{{ awarding.lead_time_days }} days</dd></div>
            </dl>
            <p class="mt-3 text-sm text-gray-600">A draft purchase order is created from this quotation. This decision is recorded with your name and the time, and cannot be undone.</p>
            <label class="field-label mt-3" for="award-remarks">Remarks{{ needsRemarks ? ' (required here)' : ' (optional)' }}</label>
            <textarea id="award-remarks" v-model="awardRemarks" rows="2" class="field-control w-full"></textarea>
            <p v-if="needsRemarks" class="mt-1 text-xs text-amber-700">{{ comparison.rows.length < minSuppliers ? 'Only one quotation was received. ' : '' }}{{ awarding.quantity_short ? `This supplier quotes ${awarding.quantity_short} fewer than requested. ` : '' }}Explain the decision.</p>
            <div class="mt-4 flex justify-end gap-2">
                <button type="button" class="secondary-button" @click="awarding = null">Cancel</button>
                <button type="button" class="brand-button" :disabled="needsRemarks && !awardRemarks.trim()" data-test="confirm-award" @click="confirmAward">Confirm award</button>
            </div>
        </div>
    </div>
</template>
