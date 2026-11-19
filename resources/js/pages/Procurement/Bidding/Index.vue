<script setup lang="ts">
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { ArrowLeft, Plus, Trash2 } from '@lucide/vue';
import { computed, ref } from 'vue';
import SpecularButton from '@/components/ui/SpecularButton.vue';
import { useCanDelete } from '@/composables/useCanDelete';

interface EventRow {
    id: number;
    reference: string;
    status: string;
    submission_deadline: string;
    deadline_passed: boolean;
    request_number: string | null;
    item: string | null;
    sku: string | null;
    quantity: number | null;
    unit: string | null;
    invited_count: number;
    bid_count: number;
    awarded_supplier: string | null;
    awarded_at: string | null;
    po_number: string | null;
}

const props = defineProps<{
    events: { data: EventRow[]; links: { url: string | null; label: string; active: boolean }[]; total: number };
    eligibleRequests: { id: number; request_number: string; quantity: number; unit: string; name: string; sku: string | null }[];
    suppliers: { id: number; name: string; email: string | null }[];
    statuses: string[];
    filters: { status?: string | null };
    isAdmin: boolean;
    minSuppliers: number;
}>();

const page = usePage();
// Only administrators see Delete; the server enforces it too (admin_delete).
const canDelete = useCanDelete();
const teamSlug = computed(() => (page.props as any).currentTeam?.slug ?? '');
const base = computed(() => `/${teamSlug.value}/procurement/bidding`);

const status = ref(props.filters.status ?? '');
const applyFilter = () => router.get(base.value, status.value ? { status: status.value } : {}, { preserveState: true, replace: true });

const showCreate = ref(false);
const form = useForm({ purchase_request_id: '' as number | '', supplier_ids: [] as number[], submission_deadline: '', rfq_notes: '' });
const minDeadline = computed(() => new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 16));
const openCreate = () => {
    form.reset();
    form.clearErrors();
    showCreate.value = true;
};
// The deadline field is the user's local clock; send the exact instant (UTC) so the server and every user agree on it.
const submit = () =>
    form
        .transform((d) => ({ ...d, submission_deadline: d.submission_deadline ? new Date(d.submission_deadline).toISOString() : d.submission_deadline }))
        .post(base.value, { preserveScroll: true, onSuccess: () => (showCreate.value = false) });

// ── Delete (admin only) ──
const deleting = ref<EventRow | null>(null);
const deleteConfirm = ref('');
const openDelete = (e: EventRow) => {
    deleteConfirm.value = '';
    deleting.value = e;
};
const confirmDelete = () => {
    if (!deleting.value || deleteConfirm.value !== 'YES') return;
    router.delete(`${base.value}/${deleting.value.id}`, {
        data: { confirmation: deleteConfirm.value },
        preserveScroll: true,
        onFinish: () => (deleting.value = null),
    });
};

const fmtDate = (iso: string | null) => (iso ? new Date(iso).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' }) : '—');
const badge = (s: string) =>
    ({
        Draft: 'bg-gray-50 text-gray-700 border-gray-200',
        'Open for Quotations': 'bg-blue-50 text-blue-700 border-blue-200',
        Closed: 'bg-amber-50 text-amber-700 border-amber-200',
        Awarded: 'bg-emerald-50 text-emerald-700 border-emerald-200',
        Cancelled: 'bg-rose-50 text-rose-700 border-rose-200',
    })[s] ?? 'bg-gray-50 text-gray-700 border-gray-200';
</script>

<template>
    <Head title="Bidding Events" />

    <div class="workspace-page">
        <div class="page-header">
            <div>
                <Link :href="`/${teamSlug}/procurement`" class="mb-1 inline-flex items-center gap-1 text-xs text-gray-500 hover:text-gray-800"><ArrowLeft class="size-3.5" aria-hidden="true" /> Procurement</Link>
                <h1 class="page-title">Bidding Events</h1>
                <p class="page-subtitle">Request quotations from registered suppliers, record what they send, compare, and let the owner award.</p>
            </div>
            <div class="page-actions">
                <button type="button" class="brand-button inline-flex items-center gap-1.5" data-test="new-bidding" @click="openCreate"><Plus class="size-4" aria-hidden="true" /> New bidding event</button>
            </div>
        </div>

        <div class="panel flex flex-col items-stretch justify-between gap-3 p-4 sm:flex-row sm:items-center">
            <p class="text-sm text-gray-600">
                {{ eligibleRequests.length }} approved purchase request{{ eligibleRequests.length === 1 ? '' : 's' }} waiting for bidding.
                <span class="text-gray-400">Suppliers do not log in: staff send the RFQ and record the quotations they receive.</span>
            </p>
            <select v-model="status" aria-label="Filter by status" class="field-control sm:w-56" @change="applyFilter">
                <option value="">All statuses</option>
                <option v-for="s in statuses" :key="s" :value="s">{{ s }}</option>
            </select>
        </div>

        <div class="panel overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="border-b border-gray-200 bg-gray-50 text-xs text-gray-500 uppercase">
                        <tr>
                            <th class="px-4 py-3">Reference</th>
                            <th class="px-4 py-3">Item</th>
                            <th class="px-4 py-3 text-right">Qty</th>
                            <th class="px-4 py-3">Deadline</th>
                            <th class="px-4 py-3 text-center">Invited / quotes</th>
                            <th class="px-4 py-3">Status</th>
                            <th class="px-4 py-3">Result</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <tr v-for="e in events.data" :key="e.id">
                            <td class="px-4 py-3 font-mono text-xs font-semibold text-gray-900">{{ e.reference }}<div class="font-sans font-normal text-gray-400">{{ e.request_number }}</div></td>
                            <td class="px-4 py-3">{{ e.item }}<div v-if="e.sku" class="font-mono text-xs text-gray-400">{{ e.sku }}</div></td>
                            <td class="px-4 py-3 text-right font-mono">{{ e.quantity }} <span class="font-sans text-xs text-gray-500">{{ e.unit }}</span></td>
                            <td class="px-4 py-3 text-xs whitespace-nowrap">{{ fmtDate(e.submission_deadline) }}<div v-if="e.deadline_passed && ['Draft', 'Open for Quotations'].includes(e.status)" class="text-amber-700">deadline passed</div></td>
                            <td class="px-4 py-3 text-center font-mono">{{ e.invited_count }} / {{ e.bid_count }}</td>
                            <td class="px-4 py-3"><span :class="badge(e.status)" class="rounded-full border px-2.5 py-0.5 text-xs font-medium whitespace-nowrap">{{ e.status }}</span></td>
                            <td class="px-4 py-3 text-xs">
                                <template v-if="e.awarded_supplier">{{ e.awarded_supplier }}<div class="font-mono text-blue-700">PO {{ e.po_number }}</div></template>
                                <span v-else class="text-gray-400">—</span>
                            </td>
                            <td class="px-4 py-3 text-right whitespace-nowrap">
                                <Link :href="`${base}/${e.id}`" class="inline-flex items-center rounded-lg border border-gray-200 px-2.5 py-1 text-xs font-medium hover:bg-gray-50">Open</Link>
                                <button
                                    v-if="canDelete && e.status !== 'Awarded'"
                                    type="button"
                                    class="ml-1 inline-flex items-center gap-1 rounded-lg border border-red-200 px-2.5 py-1 text-xs font-medium text-red-600 hover:bg-red-50"
                                    :aria-label="`Delete ${e.reference}`"
                                    data-test="delete-bidding"
                                    @click="openDelete(e)"
                                >
                                    <Trash2 class="size-3.5" aria-hidden="true" /> Delete
                                </button>
                            </td>
                        </tr>
                        <tr v-if="!events.data.length"><td colspan="8" class="py-10 text-center text-gray-500">No bidding events yet. Approve a purchase request for bidding, then create an event.</td></tr>
                    </tbody>
                </table>
            </div>
            <div v-if="events.links.length > 3" class="flex flex-wrap gap-1 border-t border-gray-100 p-3">
                <button v-for="(l, i) in events.links" :key="i" :disabled="!l.url || l.active" class="rounded border px-2.5 py-1 text-xs" :class="l.active ? 'border-[#4b5320] bg-[#4b5320] text-white' : 'border-gray-200 hover:bg-gray-50'" @click="l.url && router.visit(l.url, { preserveScroll: true })" v-html="l.label" />
            </div>
        </div>
    </div>

    <!-- Delete (administrators only): same type-YES confirmation as the rest of the system -->
    <div v-if="deleting" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
        <div class="panel w-full max-w-md p-6 shadow-xl" role="dialog" aria-modal="true" aria-labelledby="bid-del-title">
            <h3 id="bid-del-title" class="mb-2 text-xl font-semibold text-gray-900">Delete Bidding Event</h3>
            <p class="mb-2 text-sm text-gray-600">
                Move bidding event <span class="font-semibold text-gray-900">{{ deleting.reference }}</span> to the Recycle Bin?
            </p>
            <p class="mb-4 text-xs text-gray-500">
                {{ `Its ${deleting.bid_count} recorded quotation${deleting.bid_count === 1 ? '' : 's'} move with it, and ${deleting.request_number} can be sent to bidding again.` }} An Admin can restore or permanently delete this record from the Recycle Bin.
            </p>
            <div class="mt-4 mb-6">
                <label class="mb-1.5 block text-xs font-semibold text-gray-700" for="bid-del-title-confirm">
                    Type <span class="rounded border border-rose-200 bg-rose-50 px-1.5 py-0.5 font-mono font-semibold text-rose-600">YES</span> to confirm deletion:
                </label>
                <input
                    id="bid-del-title-confirm"
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
                <SpecularButton variant="secondary" size="md" :radius="10" @click="deleting = null">Cancel</SpecularButton>
                <SpecularButton variant="destructive" size="md" :radius="10" :disabled="deleteConfirm !== 'YES'" data-test="confirm-delete-bidding" @click="confirmDelete">Delete Event</SpecularButton>
            </div>
        </div>
    </div>

    <!-- Create bidding event -->
    <div v-if="showCreate" class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4" @click.self="showCreate = false">
        <form class="panel max-h-[90vh] w-full max-w-xl overflow-y-auto p-6 shadow-xl" role="dialog" aria-modal="true" aria-labelledby="bid-create-title" @submit.prevent="submit">
            <h3 id="bid-create-title" class="text-base font-semibold text-gray-900">New bidding event</h3>
            <p v-if="!eligibleRequests.length" class="mt-3 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800">
                No purchase request is waiting. An administrator must approve a request <strong>for bidding</strong> in Procurement → Purchase Requests first.
            </p>
            <template v-else>
                <div class="mt-4">
                    <label class="field-label" for="bid-pr">Approved purchase request</label>
                    <select id="bid-pr" v-model="form.purchase_request_id" required class="field-control w-full">
                        <option value="" disabled>Select a request</option>
                        <option v-for="r in eligibleRequests" :key="r.id" :value="r.id">{{ r.request_number }} · {{ r.name }} · {{ r.quantity }} {{ r.unit }}</option>
                    </select>
                    <p v-if="form.errors.purchase_request_id" class="mt-1 text-xs text-rose-600">{{ form.errors.purchase_request_id }}</p>
                </div>
                <fieldset class="mt-4">
                    <legend class="field-label">Invited suppliers (registered, active) — at least {{ minSuppliers }} before issuing</legend>
                    <div class="mt-1 max-h-44 space-y-1 overflow-y-auto rounded-lg border border-gray-200 p-2">
                        <label v-for="s in suppliers" :key="s.id" class="flex cursor-pointer items-center gap-2 rounded px-1 py-1 text-sm hover:bg-gray-50">
                            <input v-model="form.supplier_ids" type="checkbox" :value="s.id" /> {{ s.name }} <span v-if="s.email" class="text-xs text-gray-400">{{ s.email }}</span>
                        </label>
                        <p v-if="!suppliers.length" class="p-2 text-sm text-gray-500">No active suppliers. Add suppliers in Supplier Management.</p>
                    </div>
                    <p v-if="form.errors.supplier_ids || (form.errors as any)['supplier_ids.0']" class="mt-1 text-xs text-rose-600">{{ form.errors.supplier_ids || (form.errors as any)['supplier_ids.0'] }}</p>
                </fieldset>
                <div class="mt-4">
                    <label class="field-label" for="bid-deadline">Submission deadline</label>
                    <input id="bid-deadline" v-model="form.submission_deadline" type="datetime-local" :min="minDeadline" required class="field-control w-full" />
                    <p v-if="form.errors.submission_deadline" class="mt-1 text-xs text-rose-600">{{ form.errors.submission_deadline }}</p>
                </div>
                <div class="mt-4">
                    <label class="field-label" for="bid-notes">Notes for suppliers (optional, printed on the RFQ)</label>
                    <textarea id="bid-notes" v-model="form.rfq_notes" rows="2" class="field-control w-full"></textarea>
                </div>
            </template>
            <div class="mt-5 flex justify-end gap-2">
                <button type="button" class="secondary-button" @click="showCreate = false">Close</button>
                <button v-if="eligibleRequests.length" type="submit" class="brand-button" :disabled="form.processing">Create event</button>
            </div>
        </form>
    </div>
</template>
