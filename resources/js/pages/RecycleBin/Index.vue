<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import { showToast } from '@/composables/useFlashToast';
import { ref, computed, watch } from 'vue';
import SpecularButton from '@/components/ui/SpecularButton.vue';
import { useCurrency } from '@/composables/useCurrency';
import {
    Archive,
    Trash2,
    RotateCcw,
    Eye,
    Search,
    AlertTriangle,
    CheckCircle2,
    Clock,
    User,
    Package,
    Building2,
    FileText,
    ShoppingCart,
    Truck,
    Banknote,
    MessageSquare,
    Layers,
    X,
    Filter,
    RefreshCw,
    ShieldAlert,
    Check,
    FileSpreadsheet,
    AlertCircle
} from '@lucide/vue';

interface TrashedRecord {
    id: number | string;
    type: string;
    type_label: string;
    badge: string;
    title: string;
    identifier: string;
    summary: string;
    /** The record's main amount (stored USD), or null. */
    amount?: number | null;
    deleted_at_raw: string;
    deleted_at: string;
    deleted_at_human: string;
    created_at: string;
    deleted_by?: {
        id: number;
        name: string;
        email: string;
        role: string;
    } | null;
    details: Array<{ label: string; value: string }>;
}

interface AvailableType {
    key: string;
    label: string;
    badge: string;
}

const props = defineProps<{
    records: {
        data: TrashedRecord[];
        current_page: number;
        last_page: number;
        total: number;
        from: number;
        to: number;
        links: Array<{ url: string | null; label: string; active: boolean }>;
    };
    counts: Record<string, number>;
    stats: {
        total: number;
        deleted_today: number;
        types_with_records: number;
    };
    filters: {
        type: string;
        search: string;
    };
    availableTypes: AvailableType[];
}>();

const page = usePage();
const teamSlug = computed(() => (page.props as any).currentTeam?.slug || (page.props as any).teams?.[0]?.slug || 'mr-pride');
const recycleBinIndexUrl = computed(() => {
    const path = String(page.url || '').split('?')[0] || '';
    return path.startsWith('/settings/maintenance') ? '/settings/maintenance/recycle-bin' : `/${teamSlug.value}/recycle-bin`;
});
// Record amounts are stored USD; shown in the user's display currency (header switch).
const { formatMoney } = useCurrency();

// Local filter state
const searchQuery = ref(props.filters.search || '');
const selectedType = ref(props.filters.type || 'all');
let searchTimeout: ReturnType<typeof setTimeout> | null = null;

// Filters always start again on page 1. Search waits for typing to pause; the type dropdown applies at once.
const applyFilters = (delay = 300) => {
    if (searchTimeout) clearTimeout(searchTimeout);
    // Selected rows may disappear from the filtered list, so clear them to avoid acting on hidden records.
    selectedIds.value = [];
    searchTimeout = setTimeout(() => {
        router.get(
            recycleBinIndexUrl.value,
            {
                type: selectedType.value !== 'all' ? selectedType.value : undefined,
                search: searchQuery.value ? searchQuery.value : undefined,
            },
            {
                preserveState: true,
                preserveScroll: true,
                replace: true,
            }
        );
    }, delay);
};

watch(searchQuery, () => {
    applyFilters();
});

// Keep the dropdown in step with the server (for example when an unknown type falls back to All Records).
watch(
    () => props.filters.type,
    (type) => {
        selectedType.value = type || 'all';
    }
);

// Flash toasts are handled globally by useFlashToast in AppSidebarLayout

// Selection state for bulk actions
const selectedIds = ref<Array<{ type: string; id: number | string }>>([]);

const isAllSelected = computed(() => {
    if (!props.records.data.length) return false;
    return props.records.data.every((r) =>
        selectedIds.value.some((sel) => sel.type === r.type && sel.id === r.id)
    );
});

const toggleSelectAll = () => {
    if (isAllSelected.value) {
        selectedIds.value = [];
    } else {
        selectedIds.value = props.records.data.map((r) => ({ type: r.type, id: r.id }));
    }
};

const toggleSelectItem = (record: TrashedRecord) => {
    const idx = selectedIds.value.findIndex((sel) => sel.type === record.type && sel.id === record.id);
    if (idx >= 0) {
        selectedIds.value.splice(idx, 1);
    } else {
        selectedIds.value.push({ type: record.type, id: record.id });
    }
};

const isItemSelected = (record: TrashedRecord) => {
    return selectedIds.value.some((sel) => sel.type === record.type && sel.id === record.id);
};

// ==========================================
// ACTION MODALS STATE
// ==========================================
const isProcessing = ref(false);

// View Details Modal
const showDetailsModal = ref(false);
const activeRecord = ref<TrashedRecord | null>(null);

const openDetailsModal = (record: TrashedRecord) => {
    activeRecord.value = record;
    showDetailsModal.value = true;
};

// Restore Single Modal
const showRestoreModal = ref(false);
const recordToRestore = ref<TrashedRecord | null>(null);

const openRestoreModal = (record: TrashedRecord) => {
    recordToRestore.value = record;
    showRestoreModal.value = true;
};

const confirmRestore = () => {
    if (!recordToRestore.value) return;
    isProcessing.value = true;

    router.post(
        `${recycleBinIndexUrl.value}/${recordToRestore.value.type}/${recordToRestore.value.id}/restore`,
        {},
        {
            preserveScroll: true,
            onSuccess: () => {
                const targetType = recordToRestore.value?.type;
                const targetId = recordToRestore.value?.id;
                showRestoreModal.value = false;
                if (showDetailsModal.value && activeRecord.value?.id === targetId) {
                    showDetailsModal.value = false;
                }
                recordToRestore.value = null;
                isProcessing.value = false;
                // Clean up selected items
                selectedIds.value = selectedIds.value.filter(
                    (s) => !(s.type === targetType && s.id === targetId)
                );
            },
            onError: () => {
                isProcessing.value = false;
                showToast('Failed to restore record.', 'error');
            },
            onFinish: () => {
                isProcessing.value = false;
            },
        }
    );
};

// Permanent Delete Single Modal
const showForceDeleteModal = ref(false);
const recordToForceDelete = ref<TrashedRecord | null>(null);
const forceDeleteConfirmInput = ref('');
const bulkForceDeleteConfirmInput = ref('');
const emptyBinConfirmInput = ref('');

const openForceDeleteModal = (record: TrashedRecord) => {
    forceDeleteConfirmInput.value = '';
    recordToForceDelete.value = record;
    showForceDeleteModal.value = true;
};

const openBulkForceDeleteModal = () => {
    bulkForceDeleteConfirmInput.value = '';
    showBulkForceDeleteModal.value = true;
};

const openEmptyBinModal = () => {
    emptyBinConfirmInput.value = '';
    showEmptyBinModal.value = true;
};

const confirmForceDelete = () => {
    if (!recordToForceDelete.value || forceDeleteConfirmInput.value !== 'YES') return;
    isProcessing.value = true;

    router.delete(
        `${recycleBinIndexUrl.value}/${recordToForceDelete.value.type}/${recordToForceDelete.value.id}/force`,
        {
            data: { confirmation: forceDeleteConfirmInput.value },
            preserveScroll: true,
            onSuccess: () => {
                const targetType = recordToForceDelete.value?.type;
                const targetId = recordToForceDelete.value?.id;
                showForceDeleteModal.value = false;
                if (showDetailsModal.value && activeRecord.value?.id === targetId) {
                    showDetailsModal.value = false;
                }
                recordToForceDelete.value = null;
                isProcessing.value = false;
                // Clean up selected items
                selectedIds.value = selectedIds.value.filter(
                    (s) => !(s.type === targetType && s.id === targetId)
                );
            },
            onError: () => {
                isProcessing.value = false;
                showToast('Failed to permanently delete record.', 'error');
            },
            onFinish: () => {
                isProcessing.value = false;
            },
        }
    );
};

// Bulk Restore Modal
const showBulkRestoreModal = ref(false);

const confirmBulkRestore = () => {
    if (!selectedIds.value.length) return;
    isProcessing.value = true;

    router.post(
        `${recycleBinIndexUrl.value}/bulk-restore`,
        { items: selectedIds.value },
        {
            preserveScroll: true,
            onSuccess: () => {
                showBulkRestoreModal.value = false;
                selectedIds.value = [];
                isProcessing.value = false;
            },
            onError: () => {
                isProcessing.value = false;
                showToast('Failed to perform bulk restore.', 'error');
            },
            onFinish: () => {
                isProcessing.value = false;
            },
        }
    );
};

// Bulk Force Delete Modal
const showBulkForceDeleteModal = ref(false);

const confirmBulkForceDelete = () => {
    if (!selectedIds.value.length || bulkForceDeleteConfirmInput.value !== 'YES') return;
    isProcessing.value = true;

    router.post(
        `${recycleBinIndexUrl.value}/bulk-force-delete`,
        { items: selectedIds.value, confirmation: bulkForceDeleteConfirmInput.value },
        {
            preserveScroll: true,
            onSuccess: () => {
                showBulkForceDeleteModal.value = false;
                selectedIds.value = [];
                isProcessing.value = false;
            },
            onError: () => {
                isProcessing.value = false;
                showToast('Failed to permanently purge records.', 'error');
            },
            onFinish: () => {
                isProcessing.value = false;
            },
        }
    );
};

// Empty Bin Modal
const showEmptyBinModal = ref(false);

const confirmEmptyBin = () => {
    if (emptyBinConfirmInput.value !== 'YES') return;
    isProcessing.value = true;

    router.post(
        `${recycleBinIndexUrl.value}/empty`,
        { confirmation: emptyBinConfirmInput.value },
        {
            preserveScroll: true,
            onSuccess: () => {
                showEmptyBinModal.value = false;
                selectedIds.value = [];
                isProcessing.value = false;
            },
            onError: () => {
                isProcessing.value = false;
                showToast('Failed to empty recycle bin.', 'error');
            },
            onFinish: () => {
                isProcessing.value = false;
            },
        }
    );
};

// Helper to get type icon
const getTypeIcon = (type: string) => {
    switch (type) {
        case 'product':
            return Package;
        case 'supplier':
            return Building2;
        case 'client':
            return User;
        case 'quotation':
            return FileText;
        case 'purchase_order':
            return ShoppingCart;
        case 'purchase_request':
            return Layers;
        case 'commercial_invoice':
            return FileSpreadsheet;
        case 'delivery':
        case 'customer_delivery':
            return Truck;
        case 'inquiry':
            return MessageSquare;
        case 'sale':
            return Banknote;
        default:
            return Archive;
    }
};

// Helper to get type badge color classes
const getTypeBadgeClasses = (type: string) => {
    switch (type) {
        case 'product':
            return 'bg-emerald-50 text-emerald-700 border-emerald-200 dark:bg-emerald-950/40 dark:text-emerald-300 dark:border-emerald-800/60';
        case 'supplier':
            return 'bg-amber-50 text-amber-800 border-amber-200 dark:bg-amber-950/40 dark:text-amber-300 dark:border-amber-800/60';
        case 'client':
            return 'bg-blue-50 text-blue-700 border-blue-200 dark:bg-blue-950/40 dark:text-blue-300 dark:border-blue-800/60';
        case 'quotation':
            return 'bg-indigo-50 text-indigo-700 border-indigo-200 dark:bg-indigo-950/40 dark:text-indigo-300 dark:border-indigo-800/60';
        case 'purchase_order':
            return 'bg-cyan-50 text-cyan-800 border-cyan-200 dark:bg-cyan-950/40 dark:text-cyan-300 dark:border-cyan-800/60';
        case 'purchase_request':
            return 'bg-sky-50 text-sky-700 border-sky-200 dark:bg-sky-950/40 dark:text-sky-300 dark:border-sky-800/60';
        case 'commercial_invoice':
            return 'bg-violet-50 text-violet-700 border-violet-200 dark:bg-violet-950/40 dark:text-violet-300 dark:border-violet-800/60';
        case 'delivery':
            return 'bg-purple-50 text-purple-700 border-purple-200 dark:bg-purple-950/40 dark:text-purple-300 dark:border-purple-800/60';
        case 'customer_delivery':
            return 'bg-orange-50 text-orange-700 border-orange-200 dark:bg-orange-950/40 dark:text-orange-300 dark:border-orange-800/60';
        case 'inquiry':
            return 'bg-teal-50 text-teal-700 border-teal-200 dark:bg-teal-950/40 dark:text-teal-300 dark:border-teal-800/60';
        case 'sale':
            return 'bg-rose-50 text-rose-700 border-rose-200 dark:bg-rose-950/40 dark:text-rose-300 dark:border-rose-800/60';
        default:
            return 'bg-gray-50 text-gray-700 border-gray-200 dark:bg-gray-800 dark:text-gray-300 dark:border-gray-700';
    }
};
</script>

<template>
    <Head title="Archive / Recycle Bin | Admin" />

    <div class="flex min-w-0 flex-col gap-6">
        <!-- Page Header -->
        <div class="page-header">
            <div>
                <h1 class="section-title">Archive / Recycle Bin</h1>
                <p class="section-subtitle">Restore deleted records or delete them permanently. Every action is logged.</p>
            </div>

            <!-- Header Action Buttons -->
            <div class="page-actions">
                <SpecularButton
                    type="button"
                    variant="secondary"
                    size="md"
                    :radius="12"
                    @click="router.reload()"
                >
                    <RefreshCw class="w-4 h-4 text-gray-500" />
                    <span>Refresh</span>
                </SpecularButton>

                <!-- Destructive, rarely used: kept quiet so it is never mistaken for the main action. -->
                <SpecularButton
                    type="button"
                    variant="secondary"
                    size="md"
                    :radius="12"
                    textColor="#e11d48"
                    :disabled="stats.total === 0 || isProcessing"
                    @click="openEmptyBinModal"
                >
                    <Trash2 class="w-4 h-4" />
                    <span>Empty Recycle Bin</span>
                </SpecularButton>
            </div>
        </div>

        <!-- Search Bar & Record Type Filter -->
        <div class="space-y-3 rounded-xl border border-gray-200 bg-white p-4">
            <div class="flex flex-col md:flex-row gap-3 justify-between items-stretch md:items-center">
                <div class="flex flex-col sm:flex-row gap-3 flex-1 min-w-0">
                <!-- Search Box -->
                <div class="relative flex-1 max-w-md">
                    <Search class="w-4 h-4 text-gray-400 absolute left-3.5 top-1/2 -translate-y-1/2" />
                    <input
                        v-model="searchQuery"
                        type="text"
                        placeholder="Search archived records by title, SKU, or user..."
                        class="field-control w-full pl-9 pr-4"
                    />
                    <button
                        v-if="searchQuery"
                        @click="searchQuery = ''; applyFilters();"
                        class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600"
                    >
                        <X class="w-3.5 h-3.5" />
                    </button>
                </div>

                <!-- Record Type Filter (counts follow the current search) -->
                <select
                    v-model="selectedType"
                    @change="applyFilters(0)"
                    aria-label="Filter by record type"
                    class="field-control w-full sm:w-60 shrink-0 cursor-pointer"
                >
                    <option value="all">All Records ({{ counts.all || 0 }})</option>
                    <option v-for="t in availableTypes" :key="t.key" :value="t.key">
                        {{ t.label }} ({{ counts[t.key] || 0 }})
                    </option>
                </select>
                </div>

                <!-- Record Count Indicator -->
                <div class="text-xs text-gray-500 dark:text-gray-400 flex items-center gap-1.5 self-center">
                    <Filter class="w-3.5 h-3.5 text-gray-400" />
                    <span>Showing {{ records.from || 0 }}–{{ records.to || 0 }} of {{ records.total }}<template v-if="stats.deleted_today"> · {{ stats.deleted_today }} deleted today</template></span>
                </div>
            </div>
        </div>

        <!-- Bulk Action Floating / Sticky Bar (When items are checked) -->
        <div
            v-if="selectedIds.length > 0"
            class="p-3 bg-gray-900 text-white rounded-xl shadow-xl flex flex-wrap items-center justify-between gap-3 animate-in fade-in duration-150"
        >
            <div class="flex items-center gap-3">
                <span class="text-xs font-semibold px-2.5 py-1 rounded-lg bg-white/10">
                    {{ selectedIds.length }} item{{ selectedIds.length === 1 ? '' : 's' }} selected
                </span>
                <span class="text-xs text-gray-400 hidden sm:inline">Choose an operation for the selected archived records</span>
            </div>

            <div class="flex items-center gap-2">
                <button
                    type="button"
                    @click="showBulkRestoreModal = true"
                    class="px-3 py-1.5 rounded-lg bg-emerald-600 hover:bg-emerald-500 text-white text-xs font-semibold transition flex items-center gap-1.5"
                >
                    <RotateCcw class="w-3.5 h-3.5" />
                    <span>Restore Selected</span>
                </button>

                <button
                    type="button"
                    @click="openBulkForceDeleteModal"
                    class="px-3 py-1.5 rounded-lg bg-rose-600 hover:bg-rose-500 text-white text-xs font-semibold transition flex items-center gap-1.5"
                >
                    <Trash2 class="w-3.5 h-3.5" />
                    <span>Permanently Delete Selected</span>
                </button>

                <button
                    type="button"
                    @click="selectedIds = []"
                    class="px-2.5 py-1.5 rounded-lg bg-white/10 hover:bg-white/20 text-gray-300 text-xs transition"
                >
                    Clear Selection
                </button>
            </div>
        </div>

        <!-- Trashed Records Table Container -->
        <div class="bg-white dark:bg-gray-800/90 border border-gray-200/80 dark:border-gray-700/80 rounded-xl overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse">
                    <thead class="bg-gray-50/80 dark:bg-gray-900/60 border-b border-gray-200/80 dark:border-gray-700/80 text-[11px] font-semibold text-gray-500 dark:text-gray-400">
                        <tr>
                            <th class="px-4 py-3 w-10 text-center">
                                <input
                                    type="checkbox"
                                    :checked="isAllSelected"
                                    @change="toggleSelectAll"
                                    class="rounded text-[#4b5320] focus:ring-[#4b5320] cursor-pointer"
                                />
                            </th>
                            <th class="px-4 py-3">Record Type</th>
                            <th class="px-4 py-3">Title & Identifier</th>
                            <th class="px-4 py-3">Summary / Details</th>
                            <th class="px-4 py-3">Deleted Date</th>
                            <th class="px-4 py-3">Deleted By</th>
                            <th class="px-4 py-3 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700/60 text-xs">
                        <tr
                            v-for="record in records.data"
                            :key="`${record.type}-${record.id}`"
                            class="hover:bg-gray-50/80 dark:hover:bg-gray-700/30 transition-colors"
                            :class="isItemSelected(record) ? 'bg-[#4b5320]/5 dark:bg-[#4b5320]/10' : ''"
                        >
                            <!-- Checkbox -->
                            <td class="px-4 py-3 text-center">
                                <input
                                    type="checkbox"
                                    :checked="isItemSelected(record)"
                                    @change="toggleSelectItem(record)"
                                    class="rounded text-[#4b5320] focus:ring-[#4b5320] cursor-pointer"
                                />
                            </td>

                            <!-- Record Type Badge -->
                            <td class="px-4 py-3 whitespace-nowrap">
                                <span
                                    class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg text-[11px] font-semibold border"
                                    :class="getTypeBadgeClasses(record.type)"
                                >
                                    <component :is="getTypeIcon(record.type)" class="w-3.5 h-3.5 shrink-0" />
                                    <span>{{ record.type_label }}</span>
                                </span>
                            </td>

                            <!-- Title & Identifier -->
                            <td class="px-4 py-3 max-w-xs">
                                <div class="font-semibold text-gray-900 dark:text-white truncate text-xs sm:text-sm">
                                    {{ record.title }}
                                </div>
                                <div class="text-[11px] text-gray-500 dark:text-gray-400 font-mono mt-0.5 truncate">
                                    {{ record.identifier }}
                                </div>
                            </td>

                            <!-- Summary / Details -->
                            <td class="px-4 py-3 max-w-sm">
                                <span class="text-xs text-gray-700 dark:text-gray-300 font-medium leading-relaxed">
                                    <template v-if="record.amount !== null && record.amount !== undefined">{{ formatMoney(record.amount) }}<template v-if="record.summary"> • </template></template>{{ record.summary || (record.amount === null || record.amount === undefined ? '—' : '') }}
                                </span>
                            </td>

                            <!-- Deleted Date -->
                            <td class="px-4 py-3 whitespace-nowrap">
                                <div class="font-semibold text-gray-900 dark:text-gray-200">
                                    {{ record.deleted_at_human }}
                                </div>
                                <div class="text-[10px] text-gray-400 dark:text-gray-500 mt-0.5">
                                    {{ record.deleted_at }}
                                </div>
                            </td>

                            <!-- Deleted By User -->
                            <td class="px-4 py-3 whitespace-nowrap">
                                <div v-if="record.deleted_by" class="flex items-center gap-2">
                                    <div class="w-6 h-6 rounded-full bg-[#4b5320]/15 dark:bg-[#F8B803]/20 text-[#4b5320] dark:text-[#F8B803] flex items-center justify-center font-semibold text-[10px]">
                                        {{ record.deleted_by.name ? record.deleted_by.name.charAt(0).toUpperCase() : 'U' }}
                                    </div>
                                    <div>
                                        <div class="font-semibold text-gray-900 dark:text-gray-100 leading-tight">
                                            {{ record.deleted_by.name }}
                                        </div>
                                        <div class="text-[10px] text-gray-400">
                                            {{ record.deleted_by.email }}
                                        </div>
                                    </div>
                                </div>
                                <div v-else class="text-gray-400 text-[11px] italic">
                                    Previous / System Deletion
                                </div>
                            </td>

                            <!-- Actions -->
                            <td class="px-4 py-3 text-right whitespace-nowrap">
                                <div class="inline-flex items-center gap-1.5">
                                    <!-- View Details Button -->
                                    <button
                                        type="button"
                                        @click="openDetailsModal(record)"
                                        class="p-1.5 rounded-lg border border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 transition"
                                        title="View Record Details"
                                    >
                                        <Eye class="w-3.5 h-3.5" />
                                    </button>

                                    <!-- Restore Button -->
                                    <button
                                        type="button"
                                        @click="openRestoreModal(record)"
                                        class="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-lg bg-emerald-50 dark:bg-emerald-950/40 text-emerald-700 dark:text-emerald-300 hover:bg-emerald-100 dark:hover:bg-emerald-900/60 border border-emerald-200 dark:border-emerald-800 text-xs font-semibold transition"
                                        title="Restore record to active use"
                                    >
                                        <RotateCcw class="w-3.5 h-3.5" />
                                        <span>Restore</span>
                                    </button>

                                    <!-- Permanently Delete Button -->
                                    <button
                                        type="button"
                                        @click="openForceDeleteModal(record)"
                                        class="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-lg bg-rose-50 dark:bg-rose-950/40 text-rose-700 dark:text-rose-300 hover:bg-rose-100 dark:hover:bg-rose-900/60 border border-rose-200 dark:border-rose-800 text-xs font-semibold transition"
                                        title="Permanently erase from database"
                                    >
                                        <Trash2 class="w-3.5 h-3.5" />
                                        <span>Delete</span>
                                    </button>
                                </div>
                            </td>
                        </tr>

                        <!-- Empty State -->
                        <tr v-if="records.data.length === 0">
                            <td colspan="7" class="py-16 text-center">
                                <div class="max-w-md mx-auto space-y-3">
                                    <div class="w-14 h-14 rounded-xl bg-gray-100 dark:bg-gray-800 text-gray-400 mx-auto flex items-center justify-center">
                                        <Archive class="w-7 h-7" />
                                    </div>
                                    <h3 class="text-base font-semibold text-gray-900 dark:text-white">
                                        {{ searchQuery || selectedType !== 'all' ? 'No Matching Archived Records' : 'Recycle Bin is Empty' }}
                                    </h3>
                                    <p class="text-xs text-gray-500 dark:text-gray-400 leading-relaxed">
                                        {{ searchQuery || selectedType !== 'all'
                                            ? 'No archived items match your current filter criteria. Try resetting the filters.'
                                            : 'Deleted items from inventory, suppliers, clients, quotations, deliveries, and inquiries will safely appear here for recovery.' }}
                                    </p>
                                    <div v-if="searchQuery || selectedType !== 'all'" class="pt-2">
                                        <SpecularButton
                                            type="button"
                                            variant="secondary"
                                            size="sm"
                                            :radius="8"
                                            @click="searchQuery = ''; selectedType = 'all'; applyFilters();"
                                        >
                                            Reset Filters
                                        </SpecularButton>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Pagination Bar -->
            <div
                v-if="records.total > 0"
                class="px-4 py-3 bg-gray-50/60 dark:bg-gray-900/40 border-t border-gray-200/80 dark:border-gray-700/80 flex flex-col sm:flex-row items-center justify-between gap-3 text-xs"
            >
                <div class="text-gray-500 dark:text-gray-400">
                    Showing <strong class="text-gray-800 dark:text-gray-200">{{ records.from || 0 }}</strong> to <strong class="text-gray-800 dark:text-gray-200">{{ records.to || 0 }}</strong> of <strong class="text-gray-800 dark:text-gray-200">{{ records.total }}</strong> records
                </div>

                <div class="flex items-center gap-1">
                    <button
                        v-for="(link, i) in records.links"
                        :key="i"
                        :disabled="!link.url || link.active"
                        @click="link.url && router.visit(link.url, { preserveScroll: true, preserveState: true })"
                        v-html="link.label"
                        class="px-2.5 py-1 rounded-lg border text-xs font-semibold transition"
                        :class="[
                            link.active
                                ? 'bg-[#4b5320] text-white border-[#4b5320]'
                                : link.url
                                ? 'bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300 border-gray-200 dark:border-gray-700 hover:bg-gray-100'
                                : 'bg-transparent text-gray-300 dark:text-gray-600 border-transparent cursor-not-allowed'
                        ]"
                    ></button>
                </div>
            </div>
        </div>
    </div>

    <!-- ========================================================================= -->
    <!-- VIEW DETAILS MODAL -->
    <!-- ========================================================================= -->
    <div
        v-if="showDetailsModal && activeRecord"
        class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4 overflow-y-auto"
    >
        <div class="bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl w-full max-w-2xl shadow-xl overflow-hidden animate-in fade-in duration-150 my-8">
            <!-- Modal Header -->
            <div class="px-6 py-4 border-b border-gray-100 dark:border-gray-700 flex justify-between items-start">
                <div class="flex items-start gap-3">
                    <div class="p-2.5 rounded-xl bg-amber-50 dark:bg-amber-950/60 text-[#F8B803] border border-amber-100 dark:border-amber-800 shrink-0 mt-0.5">
                        <component :is="getTypeIcon(activeRecord.type)" class="w-6 h-6" />
                    </div>
                    <div>
                        <div class="flex items-center gap-2">
                            <span
                                class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-[10px] font-semibold border"
                                :class="getTypeBadgeClasses(activeRecord.type)"
                            >
                                {{ activeRecord.type_label }}
                            </span>
                            <span class="text-xs text-gray-400">ID: {{ activeRecord.id }}</span>
                        </div>
                        <h3 class="text-lg font-semibold text-gray-900 dark:text-white mt-1">{{ activeRecord.title }}</h3>
                        <p class="text-xs text-gray-500 font-mono">{{ activeRecord.identifier }}</p>
                    </div>
                </div>

                <button @click="showDetailsModal = false" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-200">
                    <X class="w-5 h-5" />
                </button>
            </div>

            <!-- Modal Body -->
            <div class="p-6 space-y-5 max-h-[70vh] overflow-y-auto">
                <!-- Deletion Metadata Callout -->
                <div class="p-4 rounded-xl bg-gray-50 dark:bg-gray-900/60 border border-gray-200/80 dark:border-gray-700/80 space-y-2 text-xs">
                    <div class="flex items-center justify-between py-1 border-b border-gray-200/60 dark:border-gray-700/60">
                        <span class="text-gray-500 font-medium">Deleted When:</span>
                        <span class="font-semibold text-gray-900 dark:text-white">{{ activeRecord.deleted_at }} ({{ activeRecord.deleted_at_human }})</span>
                    </div>

                    <div class="flex items-center justify-between py-1 border-b border-gray-200/60 dark:border-gray-700/60">
                        <span class="text-gray-500 font-medium">Deleted By User:</span>
                        <span class="font-semibold text-gray-900 dark:text-white">
                            {{ activeRecord.deleted_by ? `${activeRecord.deleted_by.name} (${activeRecord.deleted_by.email} • ${activeRecord.deleted_by.role})` : 'System / Previous Admin' }}
                        </span>
                    </div>

                    <div class="flex items-center justify-between py-1">
                        <span class="text-gray-500 font-medium">Original Record Created:</span>
                        <span class="font-semibold text-gray-700 dark:text-gray-300">{{ activeRecord.created_at }}</span>
                    </div>
                </div>

                <!-- Attributes Table -->
                <div>
                    <h4 class="text-xs font-semibold text-gray-500 dark:text-gray-400 mb-2">Original Record Properties</h4>
                    <div class="border border-gray-200 dark:border-gray-700 rounded-xl overflow-x-auto">
                        <table class="w-full text-left text-xs divide-y divide-gray-100 dark:divide-gray-700">
                            <tbody class="divide-y divide-gray-100 dark:divide-gray-700 bg-white dark:bg-gray-800">
                                <tr v-for="(attr, idx) in activeRecord.details" :key="idx" class="hover:bg-gray-50/50 dark:hover:bg-gray-700/30">
                                    <td class="p-2.5 font-semibold text-gray-500 dark:text-gray-400 w-1/3 bg-gray-50/50 dark:bg-gray-900/30">
                                        {{ attr.label }}
                                    </td>
                                    <td class="p-2.5 text-gray-900 dark:text-gray-100 break-words">
                                        {{ attr.value }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Modal Footer -->
            <div class="px-6 py-4 bg-gray-50 dark:bg-gray-900/60 border-t border-gray-100 dark:border-gray-700 flex justify-between items-center">
                <SpecularButton
                    type="button"
                    variant="destructive"
                    size="md"
                    :radius="10"
                    @click="openForceDeleteModal(activeRecord)"
                >
                    <Trash2 class="w-4 h-4 mr-1.5" />
                    <span>Permanently Delete</span>
                </SpecularButton>

                <div class="flex items-center gap-2">
                    <SpecularButton
                        type="button"
                        variant="secondary"
                        size="md"
                        :radius="10"
                        @click="showDetailsModal = false"
                    >
                        Close
                    </SpecularButton>
                    <SpecularButton
                        type="button"
                        variant="brand"
                        size="md"
                        :radius="10"
                        @click="openRestoreModal(activeRecord)"
                    >
                        <RotateCcw class="w-4 h-4 mr-1.5" />
                        <span>Restore Record</span>
                    </SpecularButton>
                </div>
            </div>
        </div>
    </div>

    <!-- ========================================================================= -->
    <!-- RESTORE SINGLE CONFIRMATION MODAL -->
    <!-- ========================================================================= -->
    <div
        v-if="showRestoreModal && recordToRestore"
        class="fixed inset-0 z-[60] flex items-center justify-center bg-black/60 p-4"
    >
        <div class="bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-6 rounded-xl w-full max-w-md shadow-xl animate-in fade-in duration-150">
            <div class="w-12 h-12 rounded-full bg-emerald-50 dark:bg-emerald-950/60 text-emerald-600 flex items-center justify-center mb-4 border border-emerald-100 dark:border-emerald-800">
                <RotateCcw class="w-6 h-6" />
            </div>
            <h3 class="text-xl font-semibold text-gray-900 dark:text-white mb-2">Restore Archived Record</h3>
            <p class="text-gray-600 dark:text-gray-300 text-sm mb-4 leading-relaxed">
                Are you sure you want to restore <strong class="text-gray-900 dark:text-white">"{{ recordToRestore.title }}"</strong> ({{ recordToRestore.type_label }}) back to active status?
            </p>
            <div class="p-3 bg-emerald-50/50 dark:bg-emerald-950/30 rounded-xl border border-emerald-100 dark:border-emerald-800/40 text-xs text-emerald-800 dark:text-emerald-300 mb-6">
                This record will immediately become visible and usable again in the <strong>{{ recordToRestore.type_label }}</strong> module.
            </div>
            <div class="flex justify-end gap-3">
                <SpecularButton
                    type="button"
                    variant="secondary"
                    size="md"
                    :radius="10"
                    @click="showRestoreModal = false"
                >
                    Cancel
                </SpecularButton>
                <SpecularButton
                    type="button"
                    variant="brand"
                    size="md"
                    :radius="10"
                    :disabled="isProcessing"
                    @click="confirmRestore"
                >
                    <Check class="w-4 h-4 mr-1.5" />
                    <span>{{ isProcessing ? 'Restoring...' : 'Confirm Restore' }}</span>
                </SpecularButton>
            </div>
        </div>
    </div>

    <!-- ========================================================================= -->
    <!-- PERMANENT DELETE SINGLE CONFIRMATION MODAL -->
    <!-- ========================================================================= -->
    <div
        v-if="showForceDeleteModal && recordToForceDelete"
        class="fixed inset-0 z-[60] flex items-center justify-center bg-black/60 p-4"
    >
        <div class="bg-white dark:bg-gray-800 border border-rose-200 dark:border-rose-900/60 p-6 rounded-xl w-full max-w-md shadow-xl animate-in fade-in duration-150">
            <div class="w-12 h-12 rounded-full bg-rose-50 dark:bg-rose-950/60 text-rose-600 flex items-center justify-center mb-4 border border-rose-100 dark:border-rose-800">
                <AlertTriangle class="w-6 h-6" />
            </div>
            <h3 class="text-xl font-semibold text-gray-900 dark:text-white mb-2">Are you sure you want to permanently delete this record?</h3>
            <p class="text-gray-600 dark:text-gray-300 text-sm mb-3 leading-relaxed">
                This action cannot be undone. Related active sessions, registered trusted devices, or dependent records must only be revoked or removed when explicitly required by the operation.
            </p>
            <div class="p-3.5 bg-rose-50 dark:bg-rose-950/40 rounded-xl border border-rose-200 dark:border-rose-800/60 text-xs text-rose-800 dark:text-rose-300 mb-4 space-y-1">
                <div class="font-semibold flex items-center gap-1.5">
                    <ShieldAlert class="w-4 h-4 text-rose-600 shrink-0" />
                    <span>Target Record: {{ recordToForceDelete.title }} ({{ recordToForceDelete.type_label }})</span>
                </div>
                <p>Permanently purging this archived {{ recordToForceDelete.type_label.toLowerCase() }} record removes it from the database and logs an audit entry. Unrelated active sessions and trusted devices are not affected.</p>
            </div>
            <div class="mt-2 mb-6">
                <label class="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1.5">
                    Type <span class="font-mono font-semibold text-rose-600 bg-rose-50 dark:bg-rose-950 px-1.5 py-0.5 rounded border border-rose-200 dark:border-rose-800">YES</span> to confirm permanent deletion:
                </label>
                <input
                    v-model="forceDeleteConfirmInput"
                    type="text"
                    placeholder="Type YES here"
                    class="w-full px-3 py-2 text-sm font-mono rounded-xl border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-white focus:outline-none focus:border-rose-500 focus:ring-1 focus:ring-rose-500"
                />
            </div>
            <div class="flex justify-end gap-3">
                <SpecularButton
                    type="button"
                    variant="secondary"
                    size="md"
                    :radius="10"
                    @click="showForceDeleteModal = false"
                >
                    Cancel
                </SpecularButton>
                <SpecularButton
                    type="button"
                    variant="destructive"
                    size="md"
                    :radius="10"
                    :disabled="isProcessing || forceDeleteConfirmInput !== 'YES'"
                    @click="confirmForceDelete"
                >
                    <Trash2 class="w-4 h-4 mr-1.5" />
                    <span>{{ isProcessing ? 'Purging...' : 'Yes, Permanently Delete' }}</span>
                </SpecularButton>
            </div>
        </div>
    </div>

    <!-- ========================================================================= -->
    <!-- BULK RESTORE CONFIRMATION MODAL -->
    <!-- ========================================================================= -->
    <div
        v-if="showBulkRestoreModal"
        class="fixed inset-0 z-[60] flex items-center justify-center bg-black/60 p-4"
    >
        <div class="bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-6 rounded-xl w-full max-w-md shadow-xl animate-in fade-in duration-150">
            <div class="w-12 h-12 rounded-full bg-emerald-50 dark:bg-emerald-950/60 text-emerald-600 flex items-center justify-center mb-4 border border-emerald-100 dark:border-emerald-800">
                <RotateCcw class="w-6 h-6" />
            </div>
            <h3 class="text-xl font-semibold text-gray-900 dark:text-white mb-2">Bulk Restore Records</h3>
            <p class="text-gray-600 dark:text-gray-300 text-sm mb-4 leading-relaxed">
                Are you sure you want to restore all <strong class="text-gray-900 dark:text-white">{{ selectedIds.length }} selected records</strong> back to active status?
            </p>
            <div class="flex justify-end gap-3">
                <SpecularButton
                    type="button"
                    variant="secondary"
                    size="md"
                    :radius="10"
                    @click="showBulkRestoreModal = false"
                >
                    Cancel
                </SpecularButton>
                <SpecularButton
                    type="button"
                    variant="brand"
                    size="md"
                    :radius="10"
                    :disabled="isProcessing"
                    @click="confirmBulkRestore"
                >
                    <Check class="w-4 h-4 mr-1.5" />
                    <span>{{ isProcessing ? 'Restoring...' : `Restore ${selectedIds.length} Items` }}</span>
                </SpecularButton>
            </div>
        </div>
    </div>

    <!-- ========================================================================= -->
    <!-- BULK FORCE DELETE CONFIRMATION MODAL -->
    <!-- ========================================================================= -->
    <div
        v-if="showBulkForceDeleteModal"
        class="fixed inset-0 z-[60] flex items-center justify-center bg-black/60 p-4"
    >
        <div class="bg-white dark:bg-gray-800 border border-rose-200 dark:border-rose-900/60 p-6 rounded-xl w-full max-w-md shadow-xl animate-in fade-in duration-150">
            <div class="w-12 h-12 rounded-full bg-rose-50 dark:bg-rose-950/60 text-rose-600 flex items-center justify-center mb-4 border border-rose-100 dark:border-rose-800">
                <AlertTriangle class="w-6 h-6" />
            </div>
            <h3 class="text-xl font-semibold text-gray-900 dark:text-white mb-2">Are you sure you want to permanently delete this record?</h3>
            <p class="text-gray-600 dark:text-gray-300 text-sm mb-3 leading-relaxed">
                This action cannot be undone. Related active sessions, registered trusted devices, or dependent records must only be revoked or removed when explicitly required by the operation.
            </p>
            <div class="p-3 bg-rose-50 dark:bg-rose-950/40 rounded-xl border border-rose-200 dark:border-rose-800 text-xs text-rose-800 dark:text-rose-300 mb-4">
                You are about to permanently purge <strong>{{ selectedIds.length }} selected records</strong> from the database. All eligible records will be permanently erased and logged in the audit trail without revoking unrelated sessions or trusted devices.
            </div>
            <div class="mt-2 mb-6">
                <label class="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1.5">
                    Type <span class="font-mono font-semibold text-rose-600 bg-rose-50 dark:bg-rose-950 px-1.5 py-0.5 rounded border border-rose-200 dark:border-rose-800">YES</span> to confirm permanent deletion:
                </label>
                <input
                    v-model="bulkForceDeleteConfirmInput"
                    type="text"
                    placeholder="Type YES here"
                    class="w-full px-3 py-2 text-sm font-mono rounded-xl border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-white focus:outline-none focus:border-rose-500 focus:ring-1 focus:ring-rose-500"
                />
            </div>
            <div class="flex justify-end gap-3">
                <SpecularButton
                    type="button"
                    variant="secondary"
                    size="md"
                    :radius="10"
                    @click="showBulkForceDeleteModal = false"
                >
                    Cancel
                </SpecularButton>
                <SpecularButton
                    type="button"
                    variant="destructive"
                    size="md"
                    :radius="10"
                    :disabled="isProcessing || bulkForceDeleteConfirmInput !== 'YES'"
                    @click="confirmBulkForceDelete"
                >
                    <Trash2 class="w-4 h-4 mr-1.5" />
                    <span>{{ isProcessing ? 'Purging...' : `Purge ${selectedIds.length} Items` }}</span>
                </SpecularButton>
            </div>
        </div>
    </div>

    <!-- ========================================================================= -->
    <!-- EMPTY RECYCLE BIN CONFIRMATION MODAL -->
    <!-- ========================================================================= -->
    <div
        v-if="showEmptyBinModal"
        class="fixed inset-0 z-[60] flex items-center justify-center bg-black/60 p-4"
    >
        <div class="bg-white dark:bg-gray-800 border border-rose-200 dark:border-rose-900/60 p-6 rounded-xl w-full max-w-md shadow-xl animate-in fade-in duration-150">
            <div class="w-12 h-12 rounded-full bg-rose-50 dark:bg-rose-950/60 text-rose-600 flex items-center justify-center mb-4 border border-rose-100 dark:border-rose-800">
                <Trash2 class="w-6 h-6" />
            </div>
            <h3 class="text-xl font-semibold text-gray-900 dark:text-white mb-2">Are you sure you want to permanently delete this record?</h3>
            <p class="text-gray-600 dark:text-gray-300 text-sm mb-3 leading-relaxed">
                This action cannot be undone. Related active sessions, registered trusted devices, or dependent records must only be revoked or removed when explicitly required by the operation.
            </p>
            <div class="p-3.5 bg-rose-50 dark:bg-rose-950/40 rounded-xl border border-rose-200 dark:border-rose-800 text-xs text-rose-800 dark:text-rose-300 mb-4 space-y-1">
                <span class="font-semibold block">Empty Entire Recycle Bin ({{ stats.total }} records)</span>
                <span>Every eligible archived item in the Recycle Bin will be permanently erased and logged to the audit trail. Unrelated active sessions and trusted devices are not affected.</span>
            </div>
            <div class="mt-2 mb-6">
                <label class="block text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1.5">
                    Type <span class="font-mono font-semibold text-rose-600 bg-rose-50 dark:bg-rose-950 px-1.5 py-0.5 rounded border border-rose-200 dark:border-rose-800">YES</span> to confirm permanent deletion:
                </label>
                <input
                    v-model="emptyBinConfirmInput"
                    type="text"
                    placeholder="Type YES here"
                    class="w-full px-3 py-2 text-sm font-mono rounded-xl border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-900 text-gray-900 dark:text-white focus:outline-none focus:border-rose-500 focus:ring-1 focus:ring-rose-500"
                />
            </div>
            <div class="flex justify-end gap-3">
                <SpecularButton
                    type="button"
                    variant="secondary"
                    size="md"
                    :radius="10"
                    @click="showEmptyBinModal = false"
                >
                    Cancel
                </SpecularButton>
                <SpecularButton
                    type="button"
                    variant="destructive"
                    size="md"
                    :radius="10"
                    :disabled="isProcessing || emptyBinConfirmInput !== 'YES'"
                    @click="confirmEmptyBin"
                >
                    <Trash2 class="w-4 h-4 mr-1.5" />
                    <span>{{ isProcessing ? 'Emptying...' : 'Yes, Empty Recycle Bin' }}</span>
                </SpecularButton>
            </div>
        </div>
    </div>
</template>
