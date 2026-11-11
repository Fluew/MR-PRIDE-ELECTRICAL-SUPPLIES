<script setup lang="ts">
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { useCanDelete } from '@/composables/useCanDelete';
import { ref, computed, watch } from 'vue';
import SpecularButton from '@/components/ui/SpecularButton.vue';
import StatusBadge from '@/components/ui/StatusBadge.vue';
import { type Evaluation } from '@/components/evaluation/ScoreBreakdown.vue';
import SupplierEvaluationBreakdown from '@/components/evaluation/SupplierEvaluationBreakdown.vue';
import { Search, Plus, Phone, Mail, Building2, CheckCircle2, Check, Trash2 } from '@lucide/vue';

interface Supplier {
    id: number;
    name: string;
    contact_person?: string | null;
    phone?: string | null;
    email?: string | null;
    address?: string | null;
    status: 'Active' | 'Inactive';
    evaluation: SupplierEvaluation | null;
}

interface SupplierEvaluation extends Evaluation {
    period: { from: string; to: string };
    /** 1 = highest AHP score; null without a complete score. */
    rank?: number | null;
    ranked_of?: number;
}

interface QuoteRecord {
    id: number;
    product: { id: number; name: string; sku: string } | null;
    reference: string | null;
    unit_price: number;
    currency: 'USD' | 'PHP';
    unit: string;
    tax_inclusive: boolean;
    quoted_on: string;
    valid_until: string | null;
    expired: boolean;
    verified_at: string | null;
    verified_by: { name: string } | null;
}

interface AvailabilityRecord {
    id: number;
    product: { id: number; name: string; sku: string } | null;
    is_available: boolean;
    notes: string | null;
    verified_at: string | null;
    verified_by: { name: string } | null;
}

const props = defineProps<{
    suppliers: {
        data: Supplier[];
        current_page: number;
        last_page: number;
        total: number;
        links: any[];
    };
    filters?: {
        search?: string;
        status?: string;
        sort?: string;
    };
    weights: { delivery: number; price: number; quality: number };
    periodMonths: number;
    products: { id: number; name: string; sku: string; unit: string | null }[];
    canVerify: boolean;
}>();

const page = usePage();
const canDelete = useCanDelete();
const teamSlug = computed(() => (page.props as any).currentTeam?.slug || (page.props as any).team?.slug || '');

// Search & Filter
const search = ref(props.filters?.search || '');
const selectedStatus = ref(props.filters?.status || 'All');
const selectedSort = ref(props.filters?.sort || 'score_desc');
let searchTimeout: ReturnType<typeof setTimeout> | null = null;

const triggerNavigation = () => {
    if (searchTimeout) clearTimeout(searchTimeout);
    router.get(
        `/${teamSlug.value}/suppliers`,
        {
            search: search.value || undefined,
            status: selectedStatus.value !== 'All' ? selectedStatus.value : undefined,
            sort: selectedSort.value !== 'score_desc' ? selectedSort.value : undefined,
        },
        { preserveState: true, preserveScroll: true, replace: true }
    );
};

const applySearch = () => {
    if (searchTimeout) clearTimeout(searchTimeout);
    searchTimeout = setTimeout(triggerNavigation, 300);
};

const applyFilter = () => {
    triggerNavigation();
};

watch(() => props.filters, (newFilters) => {
    if (newFilters) {
        if (newFilters.search !== undefined) search.value = newFilters.search || '';
        if (newFilters.status !== undefined) selectedStatus.value = newFilters.status || 'All';
        if (newFilters.sort !== undefined) selectedSort.value = newFilters.sort || 'score_desc';
    }
}, { deep: true });

// Modals
const showAddModal = ref(false);
const showEditModal = ref(false);
const showDeleteModal = ref(false);
const deleteConfirmInput = ref('');
const selectedSupplier = ref<Supplier | null>(null);

// Form
const form = useForm({
    id: null as number | null,
    name: '',
    contact_person: '',
    phone: '',
    email: '',
    address: '',
    status: 'Active' as 'Active' | 'Inactive',
});

const openAddModal = () => {
    form.reset();
    form.clearErrors();
    form.status = 'Active';
    showAddModal.value = true;
};

const openEditModal = (supplier: Supplier) => {
    selectedSupplier.value = supplier;
    form.clearErrors();
    form.id = supplier.id;
    form.name = supplier.name;
    form.contact_person = supplier.contact_person || '';
    form.phone = supplier.phone || '';
    form.email = supplier.email || '';
    form.address = supplier.address || '';
    form.status = supplier.status;
    showEditModal.value = true;
};

const openDeleteModal = (supplier: Supplier) => {
    selectedSupplier.value = supplier;
    deleteConfirmInput.value = '';
    showDeleteModal.value = true;
};

// Action Confirmation Modal State
interface ConfirmActionOptions {
    title: string;
    description: string;
    details?: Array<{ label: string; value: string | number }>;
    confirmText?: string;
    variant?: 'brand' | 'destructive';
    action: () => void;
}

const showConfirmModal = ref(false);
const confirmActionInput = ref('');
const confirmOptions = ref<ConfirmActionOptions>({
    title: 'Confirm Action',
    description: 'Are you sure you want to proceed?',
    details: [],
    confirmText: 'Confirm & Save',
    variant: 'brand',
    action: () => {},
});

const openConfirmModal = (options: ConfirmActionOptions) => {
    confirmActionInput.value = '';
    confirmOptions.value = {
        confirmText: 'Confirm & Save',
        variant: 'brand',
        ...options,
    };
    showConfirmModal.value = true;
};

const handleConfirmedAction = () => {
    if (confirmOptions.value.variant === 'destructive' && confirmActionInput.value !== 'YES') {
        return;
    }
    showConfirmModal.value = false;
    confirmOptions.value.action();
};

const promptSaveSupplier = () => {
    if (!form.name.trim()) return;

    if (showEditModal.value) {
        openConfirmModal({
            title: 'Confirm Supplier Changes',
            description: `Are you sure you want to save modifications to "${form.name}"?`,
            details: [
                { label: 'Company Name', value: form.name },
                { label: 'Contact Person', value: form.contact_person || 'N/A' },
                { label: 'Phone', value: form.phone || 'N/A' },
                { label: 'Status', value: form.status },
            ],
            confirmText: 'Save Changes',
            action: submitEdit,
        });
    } else {
        openConfirmModal({
            title: 'Confirm New Supplier',
            description: `Are you sure you want to register "${form.name}" as an active supplier?`,
            details: [
                { label: 'Company Name', value: form.name },
                { label: 'Contact Person', value: form.contact_person || 'N/A' },
                { label: 'Phone', value: form.phone || 'N/A' },
                { label: 'Status', value: form.status },
            ],
            confirmText: 'Add Supplier',
            action: submitAdd,
        });
    }
};

const submitAdd = () => {
    form.post(`/${teamSlug.value}/suppliers`, {
        preserveScroll: true,
        onSuccess: () => {
            showAddModal.value = false;
            form.reset();
        },
    });
};

const submitEdit = () => {
    if (!form.id) return;
    form.put(`/${teamSlug.value}/suppliers/${form.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            showEditModal.value = false;
            form.reset();
        },
    });
};

const confirmDelete = () => {
    if (!selectedSupplier.value || deleteConfirmInput.value !== 'YES') return;
    router.delete(`/${teamSlug.value}/suppliers/${selectedSupplier.value.id}`, {
        data: { confirmation: deleteConfirmInput.value },
        preserveScroll: true,
        onSuccess: () => {
            showDeleteModal.value = false;
            selectedSupplier.value = null;
        },
    });
};

// ---- Supplier Evaluation (calculated by the backend from transactions; read-only here)
const evalSupplier = ref<Supplier | null>(null);
const evalTab = ref<'evaluation' | 'quotes' | 'availability'>('evaluation');
const quotes = ref<QuoteRecord[]>([]);
const availability = ref<AvailabilityRecord[]>([]);
const recordsLoading = ref(false);
const recordsError = ref<string | null>(null);

const scoreLabel = (e: SupplierEvaluation | null) => (e?.complete ? e.score!.toFixed(2) : null);
const classBadge = (e: SupplierEvaluation | null) => {
    switch (e?.classification) {
        case 'Excellent':
        case 'Very Good': return 'success';
        case 'Good': return 'info';
        case 'Fair': return 'warning';
        case 'Needs Improvement': return 'danger';
        default: return 'neutral';
    }
};

const loadRecords = async () => {
    if (!evalSupplier.value) return;
    recordsLoading.value = true;
    recordsError.value = null;
    try {
        const res = await fetch(`/${teamSlug.value}/suppliers/${evalSupplier.value.id}/records`, {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        });
        if (!res.ok) throw new Error(`HTTP ${res.status}`);
        const data = await res.json();
        quotes.value = data.quotes;
        availability.value = data.availability;
    } catch {
        recordsError.value = 'The supplier records could not be loaded. Please try again.';
    } finally {
        recordsLoading.value = false;
    }
};

const openEvaluation = (supplier: Supplier, tab: 'evaluation' | 'quotes' | 'availability' = 'evaluation') => {
    evalSupplier.value = supplier;
    evalTab.value = tab;
    quotes.value = [];
    availability.value = [];
    void loadRecords();
};

// Keep the open supplier in step with the reloaded page (scores change when records change).
watch(() => props.suppliers.data, (rows) => {
    if (evalSupplier.value) {
        evalSupplier.value = rows.find((r) => r.id === evalSupplier.value!.id) ?? evalSupplier.value;
    }
});

const today = new Date().toISOString().slice(0, 10);
const quoteForm = useForm({ product_id: '' as number | '', reference: '', unit_price: '' as number | '', currency: 'USD', unit: 'pcs', tax_inclusive: false, quoted_on: today, valid_until: '', notes: '' });
const availabilityForm = useForm({ product_id: '' as number | '', is_available: true, notes: '' });

const afterRecordChange = { preserveScroll: true, preserveState: true, onSuccess: () => void loadRecords() };

const submitQuote = () => {
    if (!evalSupplier.value) return;
    quoteForm.transform((d) => ({ ...d, valid_until: d.valid_until || null, reference: d.reference || null, notes: d.notes || null }))
        .post(`/${teamSlug.value}/suppliers/${evalSupplier.value.id}/quotes`, {
            ...afterRecordChange,
            onSuccess: () => {
                quoteForm.reset();
                void loadRecords();
            },
        });
};
const submitAvailability = () => {
    if (!evalSupplier.value) return;
    availabilityForm.post(`/${teamSlug.value}/suppliers/${evalSupplier.value.id}/availability`, {
        ...afterRecordChange,
        onSuccess: () => {
            availabilityForm.reset();
            void loadRecords();
        },
    });
};
const verifyRecord = (kind: 'quotes' | 'availability', id: number) =>
    router.post(`/${teamSlug.value}/${kind === 'quotes' ? 'supplier-quotes' : 'supplier-availability'}/${id}/verify`, {}, afterRecordChange);
const removeRecord = (kind: 'quotes' | 'availability', id: number) =>
    openConfirmModal({
        title: 'Are you sure you want to permanently delete this record?',
        description: `This action cannot be undone. Related active sessions, registered trusted devices, or dependent records must only be revoked or removed when explicitly required by the operation. The ${kind === 'quotes' ? 'price quote' : 'product availability'} record will be permanently removed and supplier evaluation recalculated.`,
        confirmText: 'Permanently Delete',
        variant: 'destructive',
        action: () =>
            router.delete(`/${teamSlug.value}/${kind === 'quotes' ? 'supplier-quotes' : 'supplier-availability'}/${id}`, {
                ...afterRecordChange,
                data: { confirmation: 'YES' },
            }),
    });
const money = (amount: number, currency: string) =>
    new Intl.NumberFormat(currency === 'PHP' ? 'en-PH' : 'en-US', { style: 'currency', currency }).format(amount);</script>

<template>
    <Head title="Supplier Management" />

    <div class="workspace-page">
        <!-- Header -->
        <div class="page-header">
            <div>
                <h1 class="page-title">Supplier Management</h1>
                <p class="page-subtitle">Supplier contacts and record-based performance evaluations.</p>
            </div>
            <div class="page-actions">
                <SpecularButton
                    variant="brand"
                    size="md"
                    :radius="12"
                    @click="openAddModal"
                >
                    <Plus class="w-4 h-4 mr-1.5 inline" />
                    Add Supplier
                </SpecularButton>
            </div>
        </div>

        <!-- Filter Bar -->
        <div class="flex flex-wrap items-center gap-3 rounded-xl border border-gray-200 bg-white p-4">
            <div class="relative flex-1 min-w-[240px] max-w-md">
                <Search class="w-4 h-4 absolute left-3.5 top-1/2 -translate-y-1/2 text-gray-400" />
                <input
                    v-model="search"
                    @input="applySearch"
                    type="text"
                    placeholder="Search by supplier name, contact, phone..."
                    class="field-control w-full pl-10 pr-4"
                />
            </div>

            <div class="w-40">
                <select
                    v-model="selectedStatus"
                    @change="applyFilter"
                    class="field-control w-full font-medium cursor-pointer"
                >
                    <option value="All">All Statuses</option>
                    <option value="Active">Active</option>
                    <option value="Inactive">Inactive</option>
                </select>
            </div>

            <div class="w-48">
                <select
                    v-model="selectedSort"
                    @change="applyFilter"
                    class="field-control w-full font-medium cursor-pointer"
                >
                    <option value="score_desc">Highest evaluation score</option>
                    <option value="name_asc">Name (A-Z)</option>
                    <option value="name_desc">Name (Z-A)</option>
                </select>
            </div>
        </div>

        <!-- Suppliers Table -->
        <div class="flex-1 rounded-xl bg-white border border-gray-200 p-6">
            <p class="mb-3 text-xs text-gray-500">
                AHP score = Delivery Performance × {{ Math.round(weights.delivery * 100) }}% + Price Competitiveness × {{ Math.round(weights.price * 100) }}% + Product Quality × {{ Math.round(weights.quality * 100) }}%,
                from the last {{ periodMonths }} months of inbound deliveries, inspections and purchase order costs. The ranking helps you choose; the system never selects or orders from a supplier.
            </p>
            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse">
                    <thead>
                        <tr class="border-b border-gray-200 text-gray-500 text-sm">
                            <th class="pb-3 font-medium">Rank</th>
                            <th class="pb-3 font-medium">Company Name</th>
                            <th class="pb-3 font-medium">Contact Person</th>
                            <th class="pb-3 font-medium">Phone & Email</th>
                            <th class="pb-3 font-medium">Evaluation</th>
                            <th class="pb-3 font-medium">Status</th>
                            <th class="pb-3 font-medium text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="text-sm">
                        <tr v-if="!suppliers.data.length">
                            <td colspan="7" class="py-8 text-center text-gray-500">
                                No suppliers found. Click "+ Add Supplier" to register your first supplier.
                            </td>
                        </tr>
                        <tr v-for="supplier in suppliers.data" :key="supplier.id" class="border-b border-gray-100 hover:bg-gray-50 transition-colors group">
                            <td class="py-4 pr-3 font-mono text-sm" :title="supplier.evaluation?.rank ? `Rank ${supplier.evaluation.rank} of ${supplier.evaluation.ranked_of} scored suppliers` : 'Not ranked: insufficient data'">
                                <span v-if="supplier.evaluation?.rank" class="font-semibold text-gray-900">#{{ supplier.evaluation.rank }}</span>
                                <span v-else class="text-gray-400">—</span>
                            </td>
                            <td class="py-4 text-gray-900 font-semibold flex items-center gap-2">
                                <Building2 class="w-4 h-4 text-gray-400" />
                                {{ supplier.name }}
                            </td>
                            <td class="py-4 text-gray-600">{{ supplier.contact_person || '—' }}</td>
                            <td class="py-4 text-gray-600 text-xs space-y-0.5">
                                <div v-if="supplier.phone" class="flex items-center gap-1.5">
                                    <Phone class="w-3.5 h-3.5 text-gray-400" />
                                    {{ supplier.phone }}
                                </div>
                                <div v-if="supplier.email" class="flex items-center gap-1.5">
                                    <Mail class="w-3.5 h-3.5 text-gray-400" />
                                    {{ supplier.email }}
                                </div>
                            </td>
                            <td class="py-4">
                                <button type="button" class="text-left" @click="openEvaluation(supplier)">
                                    <template v-if="supplier.evaluation?.complete">
                                        <span class="block font-mono text-base font-semibold text-gray-900">{{ scoreLabel(supplier.evaluation) }}</span>
                                        <StatusBadge :status="supplier.evaluation.classification!" :variant="classBadge(supplier.evaluation)" size="sm" />
                                    </template>
                                    <template v-else>
                                        <span class="block text-xs font-medium text-amber-700">Insufficient data</span>
                                        <span class="text-[11px] text-gray-500 underline">View details</span>
                                    </template>
                                </button>
                            </td>
                            <td class="py-4">
                                <StatusBadge :status="supplier.status" size="sm" />
                            </td>
                            <td class="py-4 text-right">
                                <div class="inline-flex items-center justify-end gap-1.5">
                                    <SpecularButton
                                        variant="secondary"
                                        size="sm"
                                        :radius="8"
                                        @click="openEvaluation(supplier)"
                                    >
                                        Evaluation
                                    </SpecularButton>
                                    <SpecularButton
                                        variant="secondary"
                                        size="sm"
                                        :radius="8"
                                        @click="openEditModal(supplier)"
                                    >
                                        Edit
                                    </SpecularButton>
                                    <SpecularButton
                                        v-if="canDelete"
                                        variant="danger"
                                        size="sm"
                                        :radius="8"
                                        @click="openDeleteModal(supplier)"
                                    >
                                        Delete
                                    </SpecularButton>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <div v-if="suppliers.total > suppliers.data.length" class="mt-6 flex items-center justify-between border-t border-gray-100 pt-4">
                <span class="text-xs text-gray-500">
                    Showing {{ suppliers.data.length }} of {{ suppliers.total }} suppliers
                </span>
                <div class="flex gap-1">
                    <button
                        v-for="(link, i) in suppliers.links"
                        :key="i"
                        :disabled="!link.url || link.active"
                        @click="link.url && router.visit(link.url, { preserveScroll: true })"
                        v-html="link.label"
                        :class="[
                            'px-3 py-1 text-xs rounded-lg border transition-colors',
                            link.active ? 'bg-[#F8B803] text-white border-[#F8B803] font-semibold' : 'bg-white border-gray-200 text-gray-600 hover:bg-gray-50',
                            !link.url ? 'opacity-50 cursor-not-allowed' : ''
                        ]"
                    />
                </div>
            </div>
        </div>
    </div>

    <!-- Add / Edit Modal -->
    <div v-if="showAddModal || showEditModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
        <div class="bg-white border border-gray-200 p-6 rounded-xl w-full max-w-lg shadow-xl">
            <div class="flex justify-between items-center mb-5">
                <h3 class="text-xl font-semibold text-gray-900">{{ showEditModal ? 'Edit Supplier' : 'Add Supplier' }}</h3>
                <button @click="showAddModal = false; showEditModal = false" class="text-gray-400 hover:text-gray-600">&times;</button>
            </div>

            <form @submit.prevent="promptSaveSupplier" class="space-y-4">
                <div class="relative">
                    <input
                        id="supplier-name"
                        v-model="form.name"
                        type="text"
                        required
                        placeholder=" "
                        class="peer block w-full rounded-xl border border-gray-200 bg-white px-3.5 pt-5 pb-2 text-xs text-gray-900 focus:border-[#4b5320] focus:ring-1 focus:ring-[#4b5320] focus:outline-none sm:text-sm"
                        :class="form.errors.name ? 'border-red-500 focus:border-red-500 focus:ring-red-500' : ''"
                    />
                    <label
                        for="supplier-name"
                        class="pointer-events-none absolute top-3.5 left-3.5 origin-[0] -translate-y-2.5 scale-75 transform text-xs font-semibold text-gray-500 duration-200 peer-placeholder-shown:translate-y-0 peer-placeholder-shown:scale-100 peer-focus:-translate-y-2.5 peer-focus:scale-75 peer-focus:text-[#4b5320]"
                        :class="form.name ? '-translate-y-2.5 scale-75 text-[#4b5320]' : ''"
                    >
                        Company Name
                    </label>
                    <span v-if="form.errors.name" class="text-xs text-red-500 mt-1 block">{{ form.errors.name }}</span>
                </div>

                <div class="relative">
                    <input
                        id="supplier-contact"
                        v-model="form.contact_person"
                        type="text"
                        placeholder=" "
                        class="peer block w-full rounded-xl border border-gray-200 bg-white px-3.5 pt-5 pb-2 text-xs text-gray-900 focus:border-[#4b5320] focus:ring-1 focus:ring-[#4b5320] focus:outline-none sm:text-sm"
                    />
                    <label
                        for="supplier-contact"
                        class="pointer-events-none absolute top-3.5 left-3.5 origin-[0] -translate-y-2.5 scale-75 transform text-xs font-semibold text-gray-500 duration-200 peer-placeholder-shown:translate-y-0 peer-placeholder-shown:scale-100 peer-focus:-translate-y-2.5 peer-focus:scale-75 peer-focus:text-[#4b5320]"
                        :class="form.contact_person ? '-translate-y-2.5 scale-75 text-[#4b5320]' : ''"
                    >
                        Contact Person
                    </label>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div class="relative">
                        <input
                            id="supplier-phone"
                            v-model="form.phone"
                            type="text"
                            placeholder=" "
                            class="peer block w-full rounded-xl border border-gray-200 bg-white px-3.5 pt-5 pb-2 text-xs text-gray-900 focus:border-[#4b5320] focus:ring-1 focus:ring-[#4b5320] focus:outline-none sm:text-sm"
                        />
                        <label
                            for="supplier-phone"
                            class="pointer-events-none absolute top-3.5 left-3.5 origin-[0] -translate-y-2.5 scale-75 transform text-xs font-semibold text-gray-500 duration-200 peer-placeholder-shown:translate-y-0 peer-placeholder-shown:scale-100 peer-focus:-translate-y-2.5 peer-focus:scale-75 peer-focus:text-[#4b5320]"
                            :class="form.phone ? '-translate-y-2.5 scale-75 text-[#4b5320]' : ''"
                        >
                            Phone
                        </label>
                    </div>
                    <div class="relative">
                        <input
                            id="supplier-email"
                            v-model="form.email"
                            type="email"
                            placeholder=" "
                            class="peer block w-full rounded-xl border border-gray-200 bg-white px-3.5 pt-5 pb-2 text-xs text-gray-900 focus:border-[#4b5320] focus:ring-1 focus:ring-[#4b5320] focus:outline-none sm:text-sm"
                        />
                        <label
                            for="supplier-email"
                            class="pointer-events-none absolute top-3.5 left-3.5 origin-[0] -translate-y-2.5 scale-75 transform text-xs font-semibold text-gray-500 duration-200 peer-placeholder-shown:translate-y-0 peer-placeholder-shown:scale-100 peer-focus:-translate-y-2.5 peer-focus:scale-75 peer-focus:text-[#4b5320]"
                            :class="form.email ? '-translate-y-2.5 scale-75 text-[#4b5320]' : ''"
                        >
                            Email
                        </label>
                    </div>
                </div>

                <div class="relative">
                    <textarea
                        id="supplier-address"
                        v-model="form.address"
                        rows="2"
                        placeholder=" "
                        class="peer block w-full rounded-xl border border-gray-200 bg-white px-3.5 pt-5 pb-2 text-xs text-gray-900 focus:border-[#4b5320] focus:ring-1 focus:ring-[#4b5320] focus:outline-none sm:text-sm"
                    ></textarea>
                    <label
                        for="supplier-address"
                        class="pointer-events-none absolute top-3.5 left-3.5 origin-[0] -translate-y-2.5 scale-75 transform text-xs font-semibold text-gray-500 duration-200 peer-placeholder-shown:translate-y-0 peer-placeholder-shown:scale-100 peer-focus:-translate-y-2.5 peer-focus:scale-75 peer-focus:text-[#4b5320]"
                        :class="form.address ? '-translate-y-2.5 scale-75 text-[#4b5320]' : ''"
                    >
                        Address / Notes
                    </label>
                </div>

                <div class="relative">
                    <select
                        id="supplier-status"
                        v-model="form.status"
                        class="peer block w-full rounded-xl border border-gray-200 bg-white px-3.5 pt-5 pb-2 text-xs text-gray-900 focus:border-[#4b5320] focus:ring-1 focus:ring-[#4b5320] focus:outline-none sm:text-sm"
                    >
                        <option value="Active">Active</option>
                        <option value="Inactive">Inactive</option>
                    </select>
                    <label
                        for="supplier-status"
                        class="pointer-events-none absolute top-3.5 left-3.5 origin-[0] -translate-y-2.5 scale-75 transform text-xs font-semibold text-[#4b5320]"
                    >
                        Status
                    </label>
                </div>

                <div class="flex justify-end gap-3 mt-6 pt-2 border-t border-gray-100">
                    <SpecularButton
                        type="button"
                        variant="secondary"
                        size="md"
                        :radius="10"
                        @click="showAddModal = false; showEditModal = false"
                    >
                        Cancel
                    </SpecularButton>
                    <SpecularButton
                        type="submit"
                        variant="brand"
                        size="md"
                        :radius="10"
                        :disabled="form.processing"
                    >
                        {{ form.processing ? 'Saving...' : 'Save Supplier' }}
                    </SpecularButton>
                </div>
            </form>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div v-if="showDeleteModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
        <div class="bg-white border border-gray-200 p-6 rounded-xl w-full max-w-md shadow-xl">
            <h3 class="text-xl font-semibold text-gray-900 mb-2">Delete Supplier</h3>
            <p class="text-gray-600 text-sm mb-2">Move supplier <span class="font-semibold text-gray-900">{{ selectedSupplier?.name }}</span> to the Recycle Bin?</p>
            <p class="text-gray-500 text-xs mb-4">
                An Admin can restore or permanently delete this record from the Recycle Bin. Unrelated active sessions and trusted devices are not affected.
            </p>
            
            <div class="mt-4 mb-6">
                <label class="block text-xs font-semibold text-gray-700 mb-1.5">
                    Type <span class="font-mono font-semibold text-rose-600 bg-rose-50 px-1.5 py-0.5 rounded border border-rose-200">YES</span> to confirm deletion:
                </label>
                <input
                    v-model="deleteConfirmInput"
                    type="text"
                    placeholder="Type YES here"
                    class="w-full px-3 py-2 text-sm font-mono rounded-xl border border-gray-300 focus:outline-none focus:border-rose-500 focus:ring-1 focus:ring-rose-500"
                    @keyup.enter="deleteConfirmInput === 'YES' && confirmDelete()"
                />
            </div>

            <div class="flex justify-end gap-3">
                <SpecularButton
                    variant="secondary"
                    size="md"
                    :radius="10"
                    @click="showDeleteModal = false"
                >
                    Cancel
                </SpecularButton>
                <SpecularButton
                    variant="destructive"
                    size="md"
                    :radius="10"
                    @click="confirmDelete"
                    :disabled="deleteConfirmInput !== 'YES'"
                >
                    Delete Supplier
                </SpecularButton>
            </div>
        </div>
    </div>

    <!-- Supplier Evaluation -->
    <div v-if="evalSupplier" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4" @click.self="evalSupplier = null">
        <div class="flex max-h-[92vh] w-full max-w-3xl flex-col overflow-hidden rounded-xl border border-gray-200 bg-white" role="dialog" aria-modal="true" aria-labelledby="eval-title">
            <div class="flex items-start justify-between gap-3 border-b border-gray-100 px-6 py-4">
                <div>
                    <h3 id="eval-title" class="text-lg font-semibold text-gray-900">{{ evalSupplier.name }}</h3>
                    <p v-if="evalSupplier.evaluation" class="text-xs text-gray-500">
                        Evaluation period {{ evalSupplier.evaluation.period.from }} to {{ evalSupplier.evaluation.period.to }} ({{ periodMonths }} months)
                    </p>
                </div>
                <div class="flex items-start gap-3">
                    <div v-if="evalSupplier.evaluation" class="text-right">
                        <div class="text-xl font-semibold text-gray-900">{{ evalSupplier.evaluation.complete ? `${scoreLabel(evalSupplier.evaluation)} / 100` : 'Insufficient data' }}</div>
                        <div class="text-xs text-gray-500">
                            {{ evalSupplier.evaluation.classification ?? 'Not classified' }}<template v-if="evalSupplier.evaluation.rank"> · rank {{ evalSupplier.evaluation.rank }} of {{ evalSupplier.evaluation.ranked_of }}</template>
                        </div>
                    </div>
                    <button type="button" class="text-xl text-gray-400 hover:text-gray-600" aria-label="Close" @click="evalSupplier = null">&times;</button>
                </div>
            </div>

            <div class="flex gap-1 border-b border-gray-100 px-6 text-sm" role="tablist">
                <button v-for="t in [{ k: 'evaluation', l: 'Evaluation' }, { k: 'quotes', l: 'Price quotes' }, { k: 'availability', l: 'Product availability' }]" :key="t.k"
                        type="button" role="tab" :aria-selected="evalTab === t.k"
                        :class="evalTab === t.k ? 'border-[#4b5320] font-semibold text-[#4b5320]' : 'border-transparent text-gray-500'"
                        class="border-b-2 px-3 py-2" @click="evalTab = t.k as any">{{ t.l }}</button>
            </div>

            <div class="overflow-y-auto px-6 py-4">
                <!-- Evaluation -->
                <template v-if="evalTab === 'evaluation' && evalSupplier.evaluation">
                    <SupplierEvaluationBreakdown :evaluation="evalSupplier.evaluation" :weights="weights" />
                    <p class="mt-3 text-xs text-gray-500">
                        Scores cannot be entered or edited. Record purchase orders with a promised date (Procurement), inspect inbound deliveries (Deliveries), and record quotes here; an administrator verifies quotes.
                    </p>
                </template>

                <p v-if="evalTab !== 'evaluation' && recordsLoading" class="text-sm text-gray-500">Loading records…</p>
                <p v-if="evalTab !== 'evaluation' && recordsError" class="rounded-xl border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700">{{ recordsError }}</p>

                <!-- Price quotes -->
                <template v-if="evalTab === 'quotes'">
                    <form class="grid grid-cols-1 gap-3 rounded-xl border border-gray-200 p-4 sm:grid-cols-2" @submit.prevent="submitQuote">
                        <div class="sm:col-span-2">
                            <label class="field-label" for="q-product">Product</label>
                            <select id="q-product" v-model="quoteForm.product_id" class="field-control" required>
                                <option value="" disabled>Choose a product</option>
                                <option v-for="p in products" :key="p.id" :value="p.id">{{ p.name }} ({{ p.sku }})</option>
                            </select>
                            <p v-if="quoteForm.errors.product_id" class="mt-1 text-xs text-rose-600">{{ quoteForm.errors.product_id }}</p>
                        </div>
                        <div>
                            <label class="field-label" for="q-price">Unit price</label>
                            <input id="q-price" v-model="quoteForm.unit_price" type="number" min="0.01" step="0.01" class="field-control" required />
                            <p v-if="quoteForm.errors.unit_price" class="mt-1 text-xs text-rose-600">{{ quoteForm.errors.unit_price }}</p>
                        </div>
                        <div class="grid grid-cols-2 gap-2">
                            <div>
                                <label class="field-label" for="q-currency">Currency</label>
                                <select id="q-currency" v-model="quoteForm.currency" class="field-control"><option>USD</option><option>PHP</option></select>
                            </div>
                            <div>
                                <label class="field-label" for="q-unit">Unit</label>
                                <input id="q-unit" v-model="quoteForm.unit" type="text" maxlength="50" class="field-control" required />
                            </div>
                        </div>
                        <div>
                            <label class="field-label" for="q-quoted">Quote date</label>
                            <input id="q-quoted" v-model="quoteForm.quoted_on" type="date" :max="today" class="field-control" required />
                            <p v-if="quoteForm.errors.quoted_on" class="mt-1 text-xs text-rose-600">{{ quoteForm.errors.quoted_on }}</p>
                        </div>
                        <div>
                            <label class="field-label" for="q-valid">Valid until (optional)</label>
                            <input id="q-valid" v-model="quoteForm.valid_until" type="date" :min="quoteForm.quoted_on" class="field-control" />
                            <p v-if="quoteForm.errors.valid_until" class="mt-1 text-xs text-rose-600">{{ quoteForm.errors.valid_until }}</p>
                        </div>
                        <div>
                            <label class="field-label" for="q-ref">Quote reference (optional)</label>
                            <input id="q-ref" v-model="quoteForm.reference" type="text" maxlength="100" class="field-control" />
                        </div>
                        <label class="flex items-center gap-2 self-end pb-2 text-sm text-gray-700">
                            <input v-model="quoteForm.tax_inclusive" type="checkbox" class="accent-[#4b5320]" /> Price includes tax
                        </label>
                        <div class="flex justify-end sm:col-span-2">
                            <button type="submit" class="brand-button" :disabled="quoteForm.processing">Record quote</button>
                        </div>
                    </form>
                    <p class="mt-2 text-xs text-gray-500">Quotes are compared only with other suppliers' quotes for the same product, unit, currency and tax basis. Expired and unverified quotes are not counted.</p>

                    <div v-if="quotes.length" class="mt-4 overflow-x-auto">

                    <table class="w-full text-left text-sm">
                        <thead><tr class="text-xs text-gray-500"><th class="py-1">Product</th><th class="text-right">Price</th><th>Terms</th><th>Dates</th><th>Status</th><th></th></tr></thead>
                        <tbody>
                            <tr v-for="q in quotes" :key="q.id" class="border-t border-gray-100">
                                <td class="py-2">{{ q.product?.name }}<div class="text-xs text-gray-500">{{ q.reference || 'No reference' }}</div></td>
                                <td class="text-right font-mono">{{ money(q.unit_price, q.currency) }}</td>
                                <td class="text-xs">per {{ q.unit }}, {{ q.tax_inclusive ? 'tax incl.' : 'tax excl.' }}</td>
                                <td class="text-xs">{{ q.quoted_on }}<span v-if="q.valid_until"> → {{ q.valid_until }}</span></td>
                                <td class="text-xs">
                                    <StatusBadge v-if="q.expired" status="Expired" size="sm" />
                                    <StatusBadge v-else-if="q.verified_at" status="Verified" size="sm" />
                                    <StatusBadge v-else status="Pending review" size="sm" />
                                    <div v-if="q.verified_by" class="text-gray-500">by {{ q.verified_by.name }}</div>
                                </td>
                                <td class="whitespace-nowrap text-right">
                                    <button v-if="canVerify && !q.verified_at" type="button" class="text-xs font-medium text-[#4b5320] underline" @click="verifyRecord('quotes', q.id)">Verify</button>
                                    <button v-if="canDelete" type="button" class="ml-2 text-xs text-rose-600 underline" @click="removeRecord('quotes', q.id)">Remove</button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    </div>
                    <p v-else-if="!recordsLoading" class="mt-4 text-sm text-gray-500">No quotes recorded for this supplier.</p>
                </template>

                <!-- Product availability -->
                <template v-if="evalTab === 'availability'">
                    <form class="grid grid-cols-1 gap-3 rounded-xl border border-gray-200 p-4 sm:grid-cols-3" @submit.prevent="submitAvailability">
                        <div class="sm:col-span-2">
                            <label class="field-label" for="a-product">Product</label>
                            <select id="a-product" v-model="availabilityForm.product_id" class="field-control" required>
                                <option value="" disabled>Choose a product</option>
                                <option v-for="p in products" :key="p.id" :value="p.id">{{ p.name }} ({{ p.sku }})</option>
                            </select>
                        </div>
                        <div>
                            <label class="field-label" for="a-available">Can supply now?</label>
                            <select id="a-available" v-model="availabilityForm.is_available" class="field-control">
                                <option :value="true">Yes, available</option>
                                <option :value="false">No, not available</option>
                            </select>
                        </div>
                        <div class="sm:col-span-3">
                            <label class="field-label" for="a-notes">Notes (optional)</label>
                            <input id="a-notes" v-model="availabilityForm.notes" type="text" maxlength="1000" placeholder="e.g. confirmed by phone with sales rep" class="field-control" />
                        </div>
                        <div class="flex justify-end sm:col-span-3">
                            <button type="submit" class="brand-button" :disabled="availabilityForm.processing">Save availability</button>
                        </div>
                    </form>
                    <p class="mt-2 text-xs text-gray-500">Used by AHP Product Prioritization (Supplier Availability) once an administrator verifies it. Changing a record needs verifying again.</p>

                    <div v-if="availability.length" class="mt-4 overflow-x-auto">

                    <table class="w-full text-left text-sm">
                        <thead><tr class="text-xs text-gray-500"><th class="py-1">Product</th><th>Can supply</th><th>Status</th><th></th></tr></thead>
                        <tbody>
                            <tr v-for="a in availability" :key="a.id" class="border-t border-gray-100">
                                <td class="py-2">{{ a.product?.name }}<div v-if="a.notes" class="text-xs text-gray-500">{{ a.notes }}</div></td>
                                <td>{{ a.is_available ? 'Yes' : 'No' }}</td>
                                <td class="text-xs">
                                    <StatusBadge :status="a.verified_at ? 'Verified' : 'Pending review'" size="sm" />
                                    <div v-if="a.verified_by" class="text-gray-500">by {{ a.verified_by.name }}</div>
                                </td>
                                <td class="whitespace-nowrap text-right">
                                    <button v-if="canVerify && !a.verified_at" type="button" class="text-xs font-medium text-[#4b5320] underline" @click="verifyRecord('availability', a.id)">Verify</button>
                                    <button v-if="canDelete" type="button" class="ml-2 text-xs text-rose-600 underline" @click="removeRecord('availability', a.id)">Remove</button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    </div>
                    <p v-else-if="!recordsLoading" class="mt-4 text-sm text-gray-500">No availability recorded for this supplier.</p>
                </template>
            </div>
        </div>
    </div>

    <!-- Action Confirmation Modal -->
    <div v-if="showConfirmModal" class="fixed inset-0 z-[60] flex items-center justify-center bg-black/60 p-4">
        <div class="bg-white border border-gray-200 p-6 rounded-xl w-full max-w-md shadow-xl animate-in fade-in duration-150">
            <div
                :class="[
                    'w-11 h-11 rounded-full flex items-center justify-center mb-4 border',
                    confirmOptions.variant === 'destructive'
                        ? 'bg-rose-50 text-rose-600 border-rose-100'
                        : 'bg-amber-50 text-[#F8B803] border-amber-100'
                ]"
            >
                <Trash2 v-if="confirmOptions.variant === 'destructive'" class="w-6 h-6" />
                <CheckCircle2 v-else class="w-6 h-6" />
            </div>
            <h3 class="text-xl font-semibold text-gray-900 mb-1.5">{{ confirmOptions.title }}</h3>
            <p class="text-gray-600 text-sm mb-4 leading-relaxed">
                {{ confirmOptions.description }}
            </p>

            <div v-if="confirmOptions.details && confirmOptions.details.length > 0" class="bg-gray-50 border border-gray-100 rounded-xl p-3.5 mb-5 space-y-2 text-xs">
                <div v-for="(item, idx) in confirmOptions.details" :key="idx" class="flex justify-between items-center py-0.5 border-b border-gray-100 last:border-b-0">
                    <span class="text-gray-500 font-medium">{{ item.label }}</span>
                    <span class="font-semibold text-gray-900 text-right">{{ item.value }}</span>
                </div>
            </div>

            <div v-if="confirmOptions.variant === 'destructive'" class="mt-4 mb-6">
                <label class="block text-xs font-semibold text-gray-700 mb-1.5">
                    Type <span class="font-mono font-semibold text-rose-600 bg-rose-50 px-1.5 py-0.5 rounded border border-rose-200">YES</span> to confirm permanent deletion:
                </label>
                <input
                    v-model="confirmActionInput"
                    type="text"
                    placeholder="Type YES here"
                    class="w-full px-3 py-2 text-sm font-mono rounded-xl border border-gray-300 focus:outline-none focus:border-rose-500 focus:ring-1 focus:ring-rose-500"
                    @keyup.enter="confirmActionInput === 'YES' && handleConfirmedAction()"
                />
            </div>

            <div class="flex justify-end gap-3">
                <SpecularButton
                    type="button"
                    variant="secondary"
                    size="md"
                    :radius="10"
                    @click="showConfirmModal = false"
                >
                    Cancel
                </SpecularButton>
                <SpecularButton
                    type="button"
                    :variant="confirmOptions.variant === 'destructive' ? 'destructive' : 'brand'"
                    size="md"
                    :radius="10"
                    @click="handleConfirmedAction"
                    :disabled="form.processing || (confirmOptions.variant === 'destructive' && confirmActionInput !== 'YES')"
                >
                    <Trash2 v-if="confirmOptions.variant === 'destructive'" class="w-4 h-4 mr-1.5" />
                    <Check v-else class="w-4 h-4 mr-1.5" />
                    <span>{{ confirmOptions.confirmText || 'Confirm & Save' }}</span>
                </SpecularButton>
            </div>
        </div>
    </div>
</template>
