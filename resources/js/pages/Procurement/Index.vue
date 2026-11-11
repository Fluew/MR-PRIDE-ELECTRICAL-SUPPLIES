<script setup lang="ts">
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { useCanDelete } from '@/composables/useCanDelete';
import { useCurrency } from '@/composables/useCurrency';
import { showToast } from '@/composables/useFlashToast';
import { ref, computed, watch } from 'vue';
import SpecularButton from '@/components/ui/SpecularButton.vue';
import PurchaseRequestCreator, { type PrCandidate } from '@/components/procurement/PurchaseRequestCreator.vue';
import PurchaseOrderWorkspace from '@/components/procurement/PurchaseOrderWorkspace.vue';
import PurchaseOrderDocumentImport from '@/components/procurement/PurchaseOrderDocumentImport.vue';
import { 
    Search, 
    Plus, 
    Truck, 
    Clock, 
    CheckCircle2, 
    Check,
    XCircle, 
    Package, 
    Trash2, 
    Calendar, 
    Building2,
    FileText,
    Receipt,
    ShoppingCart,
    UploadCloud,
    AlertCircle,
    Eye
} from '@lucide/vue';

interface PurchaseOrderItem {
    id: number;
    po_date: string;
    po_number: string;
    quantity: number;
    item_sku?: string | null;
    description: string;
    unit_of_measure?: string | null;
    unit_cost: number;
    total_cost: number;
    supplier_id?: number | null;
    status: string;
    notes?: string | null;
    supplier?: { id: number; name: string };
    product_id?: number | null;
    product?: { id: number; name: string; sku: string } | null;
    promised_delivery_date?: string | null;
    deliveries_count?: number;
    purchase_request?: { id: number; request_number: string } | null;
}

interface PurchaseRequestItem {
    id: number;
    request_number: string;
    product_id: number;
    supplier_id: number;
    quantity: number;
    /** Unit of the requested quantity and the staff's own description (New Purchase Request form). */
    unit?: string | null;
    product_description?: string | null;
    estimated_cost: number;
    status: string;
    notes?: string | null;
    requested_at: string;
    submitted_at?: string | null;
    current_stock?: number | null;
    forecast_quantity?: number | null;
    ahp_score?: number | null;
    ahp_classification?: string | null;
    recommended_quantity?: number | null;
    justification?: string | null;
    reviewed_at?: string | null;
    review_remarks?: string | null;
    product?: { id: number; name: string; sku: string } | null;
    supplier?: { id: number; name: string };
    requested_by?: { id: number; name: string };
    reviewed_by?: { id: number; name: string } | null;
    purchase_order?: { id: number; po_number: string; status: string } | null;
}

interface ProductItem {
    id: number;
    name: string;
    sku: string;
    unit?: string | null;
    cost_price: number;
    price: number;
    stock: number;
    reorder_level: number;
}

interface SupplierItem {
    id: number;
    name: string;
}

const props = defineProps<{
    purchaseOrders?: {
        data: PurchaseOrderItem[];
        current_page: number;
        last_page: number;
        total: number;
        links: any[];
    };
    requests: {
        data: PurchaseRequestItem[];
        current_page: number;
        last_page: number;
        total: number;
        links: any[];
    };
    activeTab?: string;
    filters?: {
        search?: string;
        status?: string;
        supplier_id?: string | number;
        unit_of_measure?: string;
        date_from?: string;
        date_to?: string;
        tab?: string;
    };
    metrics: {
        totalRequests: number;
        requestedCount: number;
        orderedCount: number;
        deliveredCount: number;
        totalPurchaseOrders?: number;
        totalPurchaseOrderAmount?: number;
    };
    products: ProductItem[];
    /** The system's units of measure, each once ("Product Not Listed" requests). */
    unitOptions?: string[];
    suppliers: SupplierItem[];
    isAdmin: boolean;
    statusOptions: { requests: string[]; purchase_orders: string[] };
}>();

const page = usePage();
const canDelete = useCanDelete();
const teamSlug = computed(() => (page.props as any).currentTeam?.slug || (page.props as any).team?.slug || '');

// Flash toasts are handled globally by useFlashToast in AppSidebarLayout

// Stored amounts are USD; shown in the user's display currency (header switch).
const { formatMoney: formatCurrency } = useCurrency();

const formatDate = (dateStr: string) => {
    if (!dateStr) return '—';
    // Format as MONTH-DAY-YEAR e.g. 10-25-2026
    const d = new Date(dateStr);
    if (isNaN(d.getTime())) return dateStr;
    const mm = String(d.getMonth() + 1).padStart(2, '0');
    const dd = String(d.getDate()).padStart(2, '0');
    const yyyy = d.getFullYear();
    return `${mm}-${dd}-${yyyy}`;
};

// Tabs: 'purchase_orders' | 'requests'
const activeTab = ref(props.activeTab || props.filters?.tab || 'purchase_orders');

// Search & Filters state
const search = ref(props.filters?.search || '');
const selectedStatus = ref(props.filters?.status || 'All');
const selectedSupplier = ref(
    props.filters?.supplier_id !== undefined && props.filters?.supplier_id !== null && String(props.filters?.supplier_id) !== ''
        ? String(props.filters?.supplier_id) 
        : 'All'
);
const selectedUm = ref(props.filters?.unit_of_measure || 'All');

let searchTimeout: ReturnType<typeof setTimeout> | null = null;
const applyFilter = () => {
    if (searchTimeout) clearTimeout(searchTimeout);
    searchTimeout = setTimeout(() => {
        router.get(
            `/${teamSlug.value}/procurement`,
            {
                tab: activeTab.value,
                search: search.value || undefined,
                status: selectedStatus.value !== 'All' ? selectedStatus.value : undefined,
                supplier_id: selectedSupplier.value !== 'All' ? selectedSupplier.value : undefined,
                unit_of_measure: activeTab.value === 'purchase_orders' && selectedUm.value !== 'All' ? selectedUm.value : undefined,
            },
            { preserveState: true, preserveScroll: true, replace: true }
        );
    }, 300);
};

const switchTab = (tab: string) => {
    activeTab.value = tab;
    search.value = '';
    selectedStatus.value = 'All';
    selectedSupplier.value = 'All';
    selectedUm.value = 'All';
    applyFilter();
};

const resetFilters = () => {
    search.value = '';
    selectedStatus.value = 'All';
    selectedSupplier.value = 'All';
    selectedUm.value = 'All';
    applyFilter();
};

const hasActiveFilters = computed(() => {
    return !!search.value || 
        selectedStatus.value !== 'All' || 
        selectedSupplier.value !== 'All' || 
        (activeTab.value === 'purchase_orders' && selectedUm.value !== 'All');
});

// ==========================================
// 2. PURCHASE ORDERS LOGIC
// ==========================================
const showDeletePoModal = ref(false);
const selectedPo = ref<PurchaseOrderItem | null>(null);
const deletePoConfirmInput = ref('');

const openDeletePoModal = (po: PurchaseOrderItem) => {
    deletePoConfirmInput.value = '';
    selectedPo.value = po;
    showDeletePoModal.value = true;
};

const confirmDeletePo = () => {
    if (!selectedPo.value || deletePoConfirmInput.value !== 'YES') return;
    router.delete(`/${teamSlug.value}/procurement/purchase-orders/${selectedPo.value.id}`, {
        data: { confirmation: deletePoConfirmInput.value },
        preserveScroll: true,
        onSuccess: () => {
            showDeletePoModal.value = false;
            selectedPo.value = null;
            showToast('Purchase order deleted successfully.', 'success');
        },
    });
};

// PO Import: CSV, or a purchase order document (PDF, PNG, JPG) read into an editable preview.
const showPoImportModal = ref(false);
const poImportFile = ref<File | null>(null);
const poDocumentFile = ref<File | null>(null);
const isValidatingPoCsv = ref(false);
const isImportingPoCsv = ref(false);
const poCsvValidationResult = ref<any>(null);
const poCsvError = ref('');
const PO_DOCUMENT_TYPES = ['pdf', 'png', 'jpg', 'jpeg'];
const PO_DOCUMENT_MAX_BYTES = 25 * 1024 * 1024;

const openPoImportModal = () => {
    poImportFile.value = null;
    poDocumentFile.value = null;
    poCsvValidationResult.value = null;
    poCsvError.value = '';
    showPoImportModal.value = true;
};

const handlePoFileChange = (e: Event) => {
    const input = e.target as HTMLInputElement;
    const file = input.files?.[0];
    if (!file) {
        return;
    }
    poImportFile.value = null;
    poDocumentFile.value = null;
    poCsvValidationResult.value = null;
    poCsvError.value = '';
    const ext = file.name.split('.').pop()?.toLowerCase() ?? '';
    if (PO_DOCUMENT_TYPES.includes(ext)) {
        if (file.size > PO_DOCUMENT_MAX_BYTES) {
            poCsvError.value = 'The document is larger than 25 MB. Please upload a smaller file.';
        } else {
            poDocumentFile.value = file; // read by PurchaseOrderDocumentImport
        }
    } else if (['csv', 'txt'].includes(ext)) {
        poImportFile.value = file;
        validatePoCsv();
    } else {
        poCsvError.value = 'Choose a PDF, PNG or JPG purchase order document, or a CSV file.';
    }
    input.value = '';
};

const onPoDocumentSaved = () => {
    showPoImportModal.value = false;
    poDocumentFile.value = null;
    showToast('Purchase order imported successfully!', 'success');
};

const validatePoCsv = async () => {
    if (!poImportFile.value) return;
    isValidatingPoCsv.value = true;
    poCsvError.value = '';

    const formData = new FormData();
    formData.append('file', poImportFile.value);
    formData.append('execute', '0');

    const token = (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content || '';

    try {
        const response = await fetch(`/${teamSlug.value}/procurement/purchase-orders/import-csv`, {
            method: 'POST',
            body: formData,
            headers: {
                'X-CSRF-TOKEN': token,
                'Accept': 'application/json',
            }
        });
        const data = await response.json();
        if (response.ok && data.success) {
            poCsvValidationResult.value = data;
        } else {
            poCsvError.value = data.message || 'Failed to validate CSV structure.';
            poCsvValidationResult.value = null;
        }
    } catch (err: any) {
        poCsvError.value = err.message || 'Failed to process CSV file.';
        poCsvValidationResult.value = null;
    } finally {
        isValidatingPoCsv.value = false;
    }
};

const executePoCsvImport = () => {
    if (!poImportFile.value || !poCsvValidationResult.value || poCsvValidationResult.value.summary?.valid_count === 0) {
        showToast('No valid rows available to import.', 'error');
        return;
    }

    isImportingPoCsv.value = true;
    const formData = new FormData();
    formData.append('file', poImportFile.value);
    formData.append('execute', '1');

    router.post(`/${teamSlug.value}/procurement/purchase-orders/import-csv`, formData as any, {
        onSuccess: () => {
            showPoImportModal.value = false;
            isImportingPoCsv.value = false;
            showToast('Purchase orders imported successfully!', 'success');
        },
        onError: (err) => {
            isImportingPoCsv.value = false;
            const msg = Object.values(err)[0] as string;
            showToast(msg || 'Failed to import CSV.', 'error');
        }
    });
};

// ==========================================
// 3. PURCHASE REQUESTS LOGIC
// ==========================================
const showDeleteModal = ref(false);
const selectedRequest = ref<PurchaseRequestItem | null>(null);
const actionBusy = ref(false);

// New request. A catalog product: only the quantity (its unit comes from the Product Catalog,
// read-only). "Product Not Listed": a description, the quantity and a unit from the system's units;
// nothing is added to inventory. Then the request creator (justification, submit).
const UNLISTED = 'unlisted' as const;
const showProductPicker = ref(false);
const pickedProductId = ref<number | typeof UNLISTED | null>(null);
const pickedDescription = ref('');
const pickedQuantity = ref<number | string | null>(null);
const pickedUnitChoice = ref('');
const pickerFocus = ref<'' | 'product' | 'unit'>('');
const pickerErrors = ref<{ product?: string; description?: string; quantity?: string; unit?: string }>({});
const prCandidates = ref<PrCandidate[]>([]);
const showPrCreator = ref(false);
const isUnlisted = computed(() => pickedProductId.value === UNLISTED);
const pickedProduct = computed(() => (typeof pickedProductId.value === 'number' ? props.products.find((x) => x.id === pickedProductId.value) ?? null : null));
const pickedUnit = computed(() => (pickedProduct.value?.unit ?? '').trim());
// A catalog product without a unit is not given one: staff must add it in Inventory first.
const missingUnitMessage = computed(() =>
    pickedProduct.value && !pickedUnit.value ? `${pickedProduct.value.name} has no unit of measure in the Product Catalog. Add its unit in Inventory first.` : '',
);
// Switching between a catalog product and "Product Not Listed" clears the fields that no longer apply.
watch(isUnlisted, () => {
    pickedDescription.value = '';
    pickedUnitChoice.value = '';
    pickerErrors.value = { ...pickerErrors.value, description: undefined, unit: undefined };
});
const openNewRequest = () => {
    // Nothing is preselected: staff choose the product themselves.
    pickedProductId.value = null;
    pickedDescription.value = '';
    pickedQuantity.value = null;
    pickedUnitChoice.value = '';
    pickerErrors.value = {};
    showProductPicker.value = true;
};
// Positive whole numbers only: no zero, negatives, decimals or empty values.
const wholeQuantity = (v: number | string | null): number | null => {
    const text = String(v ?? '').trim();
    return /^\d+$/.test(text) && Number(text) >= 1 ? Number(text) : null;
};
const validateNewRequest = () => {
    const errors: typeof pickerErrors.value = {};
    if (isUnlisted.value) {
        if (!pickedDescription.value.trim()) errors.description = 'Describe the product you need to purchase.';
        if (!pickedUnitChoice.value) errors.unit = 'Select the unit.';
    } else if (!pickedProduct.value) {
        errors.product = 'Select a product.';
    } else if (missingUnitMessage.value) {
        errors.product = missingUnitMessage.value;
    }
    if (wholeQuantity(pickedQuantity.value) === null) errors.quantity = 'Enter a whole number of at least 1.';
    pickerErrors.value = errors;
    return Object.keys(errors).length === 0;
};
const continueNewRequest = () => {
    if (!validateNewRequest()) return;
    const common = { forecast: null, ahp_score: null, classification: null, recommended_quantity: null, requested_quantity: wholeQuantity(pickedQuantity.value) };
    if (isUnlisted.value) {
        const description = pickedDescription.value.trim();
        prCandidates.value = [{ ...common, product_id: null, name: description, sku: '', current_stock: null, unit: pickedUnitChoice.value, description }];
    } else {
        const p = pickedProduct.value!;
        // The unit is shown only; the server saves the product's own unit.
        prCandidates.value = [{ ...common, product_id: p.id, name: p.name, sku: p.sku, current_stock: p.stock, unit: pickedUnit.value }];
    }
    showProductPicker.value = false;
    showPrCreator.value = true;
};
// Floating labels (same look as the other forms): up when focused or filled.
const floatField = 'peer block w-full rounded-xl border bg-white px-3.5 pt-5 pb-2 text-xs text-gray-900 focus:border-[#4b5320] focus:ring-1 focus:ring-[#4b5320] focus:outline-none sm:text-sm';
const floatLabel = 'pointer-events-none absolute top-3.5 left-3.5 origin-[0] transform text-xs font-semibold duration-200 sm:text-sm';
const floatUp = '-translate-y-2.5 scale-75 text-[#4b5320]';

// Review (admin) and conversion to a purchase order.
const reviewing = ref<PurchaseRequestItem | null>(null);
const reviewForm = useForm({ decision: 'approve' as 'approve' | 'reject', remarks: '' });
const openReview = (req: PurchaseRequestItem) => {
    reviewing.value = req;
    reviewForm.reset();
    reviewForm.clearErrors();
};
const submitReview = (decision: 'approve' | 'reject') => {
    if (!reviewing.value) return;
    reviewForm.decision = decision;
    reviewForm.post(`/${teamSlug.value}/procurement/requests/${reviewing.value.id}/review`, {
        preserveScroll: true,
        onSuccess: () => { if (!Object.keys(reviewForm.errors).length) reviewing.value = null; },
    });
};

const submitDraftRequest = (req: PurchaseRequestItem) => {
    router.post(`/${teamSlug.value}/procurement/requests/${req.id}/submit`, {}, { preserveScroll: true });
};

// Purchase order workspace (deliveries, inspection, stock posting).
const workspacePoId = ref<number | null>(null);
const closeWorkspace = () => {
    workspacePoId.value = null;
    router.reload({ only: ['purchaseOrders', 'requests', 'metrics'] });
};

const prStatusClass = (st: string) => ({
    'Draft': 'bg-gray-50 text-gray-700 border-gray-200',
    'Pending Approval': 'bg-blue-50 text-blue-700 border-blue-200',
    'Approved': 'bg-emerald-50 text-emerald-700 border-emerald-200',
    'Rejected': 'bg-rose-50 text-rose-700 border-rose-200',
    'Converted to Purchase Order': 'bg-gray-100 text-gray-700 border-gray-200',
    'Sent to Supplier': 'bg-blue-50 text-blue-700 border-blue-200',
    'Partially Delivered': 'bg-amber-50 text-amber-800 border-amber-200',
    'Fully Delivered': 'bg-emerald-50 text-emerald-700 border-emerald-200',
    'Closed': 'bg-gray-100 text-gray-700 border-gray-200',
    'Cancelled': 'bg-rose-50 text-rose-700 border-rose-200',
    'Pending Verification': 'bg-blue-50 text-blue-700 border-blue-200',
    'Flagged': 'bg-rose-50 text-rose-700 border-rose-200',
    'Verified': 'bg-emerald-50 text-emerald-700 border-emerald-200',
}[st] ?? 'bg-gray-50 text-gray-700 border-gray-200');

// Action Confirmation Modal State
interface ConfirmActionOptions {
    title: string;
    description: string;
    details?: Array<{ label: string; value: string | number }>;
    confirmText?: string;
    action: () => void;
}

const showConfirmModal = ref(false);
const confirmOptions = ref<ConfirmActionOptions>({
    title: 'Confirm Action',
    description: 'Are you sure you want to proceed?',
    details: [],
    confirmText: 'Confirm & Submit',
    action: () => {},
});

const openConfirmModal = (options: ConfirmActionOptions) => {
    confirmOptions.value = {
        confirmText: 'Confirm & Submit',
        ...options,
    };
    showConfirmModal.value = true;
};

const handleConfirmedAction = () => {
    showConfirmModal.value = false;
    confirmOptions.value.action();
};

const deletingRequest = ref(false);

const openDeleteModal = (req: PurchaseRequestItem) => {
    selectedRequest.value = req;
    showDeleteModal.value = true;
};

// Admin only (the server enforces it too). The redirect reloads the request list and tab counts;
// the success or error message arrives as a flash toast.
const confirmDelete = () => {
    if (!selectedRequest.value || deletingRequest.value) return;
    deletingRequest.value = true;
    router.delete(`/${teamSlug.value}/procurement/${selectedRequest.value.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            showDeleteModal.value = false;
            selectedRequest.value = null;
        },
        onError: () => {
            showToast('The purchase request could not be deleted.', 'error');
        },
        onFinish: () => {
            deletingRequest.value = false;
        },
    });
};
</script>

<template>
    <Head title="Procurement Monitoring" />

    <div class="workspace-page">
        <!-- Page Header -->
        <div class="page-header">
            <div>
                <h1 class="page-title">Procurement & Sourcing</h1>
                <p class="page-subtitle">Purchase orders and purchase requests.</p>
            </div>

            <!-- Contextual Actions -->
            <div class="page-actions">
                <template v-if="activeTab === 'purchase_orders'">
                    <SpecularButton
                        variant="secondary"
                        size="md"
                        :radius="12"
                        @click="openPoImportModal"
                    >
                        <UploadCloud class="w-4 h-4 text-gray-500" />
                        <span>Import Purchase Order</span>
                    </SpecularButton>
                </template>

                <template v-else>
                    <SpecularButton
                        variant="brand"
                        size="md"
                        :radius="12"
                        @click="openNewRequest"
                    >
                        <Plus class="w-4 h-4" />
                        <span>New Purchase Request</span>
                    </SpecularButton>
                </template>
            </div>
        </div>

        <!-- Tab Switcher -->
        <div class="flex items-center gap-2 border-b border-gray-200 pb-3 overflow-x-auto">
            <button
                type="button"
                @click="switchTab('purchase_orders')"
                :class="[
                    'flex items-center gap-2 px-3.5 py-2 rounded-lg text-sm font-medium transition-colors cursor-pointer whitespace-nowrap',
                    activeTab === 'purchase_orders'
                        ? 'bg-gray-100 text-gray-900'
                        : 'text-gray-500 hover:text-gray-900 hover:bg-gray-50'
                ]"
            >
                <ShoppingCart class="w-4 h-4" />
                <span>Purchase Orders</span>
                <span 
                    v-if="metrics.totalPurchaseOrders !== undefined" 
                    class="ml-1 px-2 py-0.5 text-xs rounded-full bg-black/10 text-gray-900 font-semibold"
                >
                    {{ metrics.totalPurchaseOrders }}
                </span>
            </button>

            <button
                type="button"
                @click="switchTab('requests')"
                :class="[
                    'flex items-center gap-2 px-3.5 py-2 rounded-lg text-sm font-medium transition-colors cursor-pointer whitespace-nowrap',
                    activeTab === 'requests'
                        ? 'bg-gray-100 text-gray-900'
                        : 'text-gray-500 hover:text-gray-900 hover:bg-gray-50'
                ]"
            >
                <Package class="w-4 h-4" />
                <span>Purchase Requests</span>
                <span class="ml-1 px-2 py-0.5 text-xs rounded-full bg-black/10 text-gray-900 font-semibold">
                    {{ metrics.totalRequests }}
                </span>
            </button>
        </div>

        <!-- Metrics Cards Section -->
        <div v-if="activeTab === 'purchase_orders'" class="grid grid-cols-2 gap-3">
            <div class="bg-white border border-gray-200 rounded-xl px-4 py-3">
                <div class="flex items-center justify-between text-gray-500 text-xs font-semibold mb-1">
                    <span>Total Purchase Orders</span>
                    <ShoppingCart class="w-4 h-4 text-blue-500" />
                </div>
                <div class="text-2xl font-semibold text-gray-900">{{ metrics.totalPurchaseOrders || 0 }}</div>
                <div class="text-xs text-gray-500 mt-1">Issued vendor POs</div>
            </div>

            <div class="bg-white border border-gray-200 rounded-xl px-4 py-3">
                <div class="flex items-center justify-between text-gray-500 text-xs font-semibold mb-1">
                    <span>Total PO Amount</span>
                    <Receipt class="w-4 h-4 text-[#F8B803]" />
                </div>
                <div class="text-2xl font-semibold text-[#B45309]">{{ formatCurrency(metrics.totalPurchaseOrderAmount || 0) }}</div>
                <div class="text-xs text-gray-500 mt-1">Total PO commitment</div>
            </div>
        </div>

        <div v-else class="grid grid-cols-2 lg:grid-cols-4 gap-3">
            <div class="bg-white border border-gray-200 rounded-xl px-4 py-3">
                <div class="flex items-center justify-between text-gray-500 text-xs font-semibold mb-1">
                    <span>Total Requests</span>
                    <Package class="w-4 h-4 text-gray-400" />
                </div>
                <div class="text-2xl font-semibold text-gray-900">{{ metrics.totalRequests }}</div>
                <div class="text-xs text-gray-500 mt-1">All lifecycle stages</div>
            </div>

            <div class="bg-white border border-gray-200 rounded-xl px-4 py-3">
                <div class="flex items-center justify-between text-gray-500 text-xs font-semibold mb-1">
                    <span>Pending approval</span>
                    <Clock class="w-4 h-4 text-blue-500" />
                </div>
                <div class="text-2xl font-semibold text-blue-600">{{ metrics.requestedCount }}</div>
                <div class="text-xs text-gray-500 mt-1">Waiting for an administrator</div>
            </div>

            <div class="bg-white border border-gray-200 rounded-xl px-4 py-3">
                <div class="flex items-center justify-between text-gray-500 text-xs font-semibold mb-1">
                    <span>Approved</span>
                    <Truck class="w-4 h-4 text-[#F8B803]" />
                </div>
                <div class="text-2xl font-semibold text-gray-900">{{ metrics.orderedCount }}</div>
                <div class="text-xs text-gray-500 mt-1">Ready for a purchase order</div>
            </div>

            <div class="bg-white border border-gray-200 rounded-xl px-4 py-3">
                <div class="flex items-center justify-between text-gray-500 text-xs font-semibold mb-1">
                    <span>Converted</span>
                    <CheckCircle2 class="w-4 h-4 text-emerald-500" />
                </div>
                <div class="text-2xl font-semibold text-gray-900">{{ metrics.deliveredCount }}</div>
                <div class="text-xs text-gray-500 mt-1">Turned into purchase orders</div>
            </div>
        </div>

        <!-- Search & Filters Bar (Beside search bar) -->
        <div class="flex flex-col lg:flex-row items-stretch lg:items-center justify-between gap-3 bg-white p-4 rounded-xl border border-gray-200">
            <!-- Search Bar -->
            <div class="relative flex-1 max-w-md">
                <Search class="w-4 h-4 absolute left-3.5 top-1/2 -translate-y-1/2 text-gray-400" />
                <input
                    v-model="search"
                    @input="applyFilter"
                    type="text"
                    :placeholder="
                        activeTab === 'purchase_orders'
                            ? 'Search by PO #, SKU, description or supplier...'
                            : 'Search by request #, product or supplier...'
                    "
                    class="field-control w-full pl-10 pr-4"
                />
            </div>

            <!-- Filters beside search bar -->
            <div class="flex flex-wrap items-center gap-2.5">
                <!-- Status Filter (Tailored per tab) -->
                <div class="relative min-w-[140px]">
                    <select
                        v-model="selectedStatus"
                        @change="applyFilter"
                        class="field-control w-full cursor-pointer"
                    >
                        <option value="All">All Statuses</option>
                        <option v-for="st in (statusOptions as any)[activeTab] ?? []" :key="st" :value="st">{{ st }}</option>
                    </select>
                </div>

                <!-- Supplier Filter (For all tabs) -->
                <div class="relative min-w-[150px]">
                    <select
                        v-model="selectedSupplier"
                        @change="applyFilter"
                        class="field-control w-full cursor-pointer"
                    >
                        <option value="All">All Suppliers</option>
                        <option v-for="s in suppliers" :key="s.id" :value="String(s.id)">{{ s.name }}</option>
                    </select>
                </div>

                <!-- U/M Filter (Specifically for Purchase Orders) -->
                <div v-if="activeTab === 'purchase_orders'" class="relative min-w-[110px]">
                    <select
                        v-model="selectedUm"
                        @change="applyFilter"
                        class="field-control w-full cursor-pointer"
                    >
                        <option value="All">All U/M</option>
                        <option value="PCS">PCS</option>
                        <option value="BOX">BOX</option>
                        <option value="MTR">MTR</option>
                        <option value="KG">KG</option>
                        <option value="SET">SET</option>
                        <option value="ROLL">ROLL</option>
                    </select>
                </div>

                <!-- Reset Filters Button -->
                <button
                    v-if="hasActiveFilters"
                    type="button"
                    @click="resetFilters"
                    class="inline-flex items-center gap-1.5 px-3 py-2 text-xs font-semibold text-gray-600 hover:text-red-600 bg-gray-100 hover:bg-red-50 border border-gray-200 hover:border-red-200 rounded-xl transition-colors cursor-pointer"
                    title="Reset all search and filter conditions"
                >
                    <XCircle class="w-3.5 h-3.5" />
                    <span>Reset Filters</span>
                </button>
            </div>
        </div>

        <!-- ======================================================== -->
        <!-- TAB 2: PURCHASE ORDERS TABLE -->
        <!-- ======================================================== -->
        <div v-if="activeTab === 'purchase_orders'" class="rounded-xl border border-gray-200 bg-white overflow-hidden flex flex-col">
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm text-gray-600">
                    <thead class="bg-gray-50 text-xs text-gray-500 border-b border-gray-200">
                        <tr>
                            <th class="px-4 py-3 font-semibold text-gray-900 tracking-wider">ORDER DATE</th>
                            <th class="px-4 py-3 font-semibold text-gray-900 tracking-wider">PURCHASE ORDER NO.</th>
                            <th class="px-4 py-3 font-semibold text-gray-900 text-center tracking-wider">QTY ORDERED</th>
                            <th class="px-4 py-3 font-semibold text-gray-900 tracking-wider">SUPPLIER</th>
                            <th class="px-4 py-3 font-semibold text-gray-900 tracking-wider">ITEM/SKU Number</th>
                            <th class="px-4 py-3 font-semibold text-gray-900 tracking-wider">Description</th>
                            <th class="px-4 py-3 font-semibold text-gray-900 text-right tracking-wider">UNIT COST</th>
                            <th class="px-4 py-3 font-semibold text-gray-900 text-center tracking-wider">U/M</th>
                            <th class="px-4 py-3 font-semibold text-gray-900 text-right tracking-wider">EXTENDED COST</th>
                            <th class="px-4 py-3 font-semibold text-gray-900 text-right tracking-wider">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <tr v-if="!purchaseOrders?.data?.length">
                            <td colspan="10" class="px-4 py-10 text-center text-gray-500">
                                <div class="max-w-md mx-auto space-y-2">
                                    <ShoppingCart class="w-8 h-8 mx-auto text-gray-300" />
                                    <p class="font-medium text-gray-700">No purchase orders found</p>
                                    <p class="text-xs text-gray-400">Purchase orders are prepared from approved purchase requests (Purchase Requests tab).</p>
                                </div>
                            </td>
                        </tr>
                        <tr v-for="po in purchaseOrders?.data" :key="po.id" class="hover:bg-gray-50/80 transition-colors">
                            <td class="px-4 py-3 text-gray-700 font-medium whitespace-nowrap">{{ formatDate(po.po_date) }}</td>
                            <td class="px-4 py-3 font-mono font-semibold text-blue-700 whitespace-nowrap">{{ po.po_number }}</td>
                            <td class="px-4 py-3 text-center font-semibold text-gray-900">{{ po.quantity }}</td>
                            <td class="px-4 py-3 text-gray-600 whitespace-nowrap">{{ po.supplier?.name || '—' }}</td>
                            <td class="px-4 py-3 font-mono text-xs font-semibold text-gray-800 whitespace-nowrap">{{ po.item_sku || '—' }}</td>
                            <td class="px-4 py-3 text-gray-900 font-medium max-w-sm truncate">{{ po.description }}</td>
                            <td class="px-4 py-3 text-right font-medium text-gray-800 whitespace-nowrap">{{ formatCurrency(po.unit_cost) }}</td>
                            <td class="px-4 py-3 text-center text-xs font-semibold text-gray-700">{{ po.unit_of_measure || 'PCS' }}</td>
                            <td class="px-4 py-3 text-right font-semibold text-gray-900 whitespace-nowrap">{{ formatCurrency(po.total_cost) }}</td>
                            <td class="px-4 py-3 text-right whitespace-nowrap">
                                <button type="button" class="mr-1 inline-flex items-center px-2.5 py-1 text-xs font-medium border border-[#4b5320] text-[#4b5320] rounded-lg hover:bg-gray-50" @click="workspacePoId = po.id">Open</button>
                                <button
                                    v-if="canDelete"
                                    type="button"
                                    @click="openDeletePoModal(po)"
                                    class="inline-flex items-center gap-1 px-2.5 py-1 text-xs font-medium text-red-600 hover:text-red-700 hover:bg-red-50 border border-red-200 rounded-lg transition-colors cursor-pointer"
                                    title="Delete order"
                                >
                                    <Trash2 class="w-3.5 h-3.5" />
                                    <span>Delete</span>
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Purchase Orders Pagination -->
            <div v-if="purchaseOrders && purchaseOrders.total > purchaseOrders.data.length" class="p-4 flex items-center justify-between border-t border-gray-100">
                <span class="text-xs text-gray-500">
                    Showing {{ purchaseOrders.data.length }} of {{ purchaseOrders.total }} purchase orders
                </span>
                <div class="flex gap-1">
                    <button
                        v-for="(link, i) in purchaseOrders.links"
                        :key="i"
                        :disabled="!link.url || link.active"
                        @click="link.url && router.visit(link.url, { preserveScroll: true })"
                        v-html="link.label"
                        :class="[
                            'px-3 py-1 text-xs rounded-lg border transition-colors',
                            link.active ? 'bg-[#F8B803] text-gray-900 border-[#F8B803] font-semibold' : 'bg-white border-gray-200 text-gray-600 hover:bg-gray-50',
                            !link.url ? 'opacity-50 cursor-not-allowed' : ''
                        ]"
                    />
                </div>
            </div>
        </div>

        <!-- ======================================================== -->
        <!-- TAB 3: PURCHASE REQUESTS TABLE -->
        <!-- ======================================================== -->
        <div v-else class="rounded-xl border border-gray-200 bg-white overflow-hidden flex flex-col">
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm text-gray-600">
                    <thead class="bg-gray-50 text-xs text-gray-500 border-b border-gray-200">
                        <tr>
                            <th class="px-4 py-3 font-medium">Request ID</th>
                            <th class="px-4 py-3 font-medium">Item Name</th>
                            <th class="px-4 py-3 font-medium">Supplier</th>
                            <th class="px-4 py-3 font-medium text-center">Requested / Recommended</th>
                            <th class="px-4 py-3 font-medium">Stock · Forecast · AHP</th>
                            <th class="px-4 py-3 font-medium">Submitted</th>
                            <th class="px-4 py-3 font-medium text-center">Status</th>
                            <th class="px-4 py-3 font-medium text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <tr v-if="!requests.data.length">
                            <td colspan="8" class="px-4 py-8 text-center text-gray-500">
                                No purchase requests found. Create them from Reports &amp; Analytics → AHP Product Prioritization, or with "New Purchase Request".
                            </td>
                        </tr>
                        <tr v-for="req in requests.data" :key="req.id" class="hover:bg-gray-50 transition-colors align-top">
                            <td class="px-4 py-3 font-mono font-semibold text-[#b45309]">{{ req.request_number }}</td>
                            <td class="px-4 py-3">
                                <div v-if="req.product" class="font-semibold text-gray-900">{{ req.product.name }}</div>
                                <template v-else-if="req.product_description">
                                    <span class="rounded-full border border-amber-200 bg-amber-50 px-2 py-0.5 text-[11px] font-medium text-amber-700">Product not listed</span>
                                    <div class="mt-1 max-w-xs font-semibold text-gray-900">{{ req.product_description }}</div>
                                </template>
                                <div v-else class="font-semibold text-gray-900">—</div>
                                <div v-if="req.justification" class="max-w-xs text-xs text-gray-500">{{ req.justification }}</div>
                                <div class="text-xs text-gray-400">by {{ req.requested_by?.name ?? '—' }}</div>
                            </td>
                            <td class="px-4 py-3 text-gray-600">{{ req.supplier?.name || '—' }}</td>
                            <td class="px-4 py-3 text-center font-mono">
                                <span class="font-semibold text-gray-900">{{ req.quantity }}</span><span v-if="req.unit" class="font-sans text-xs text-gray-500"> {{ req.unit }}</span> / {{ req.recommended_quantity ?? '—' }}
                                <div class="text-xs text-gray-400">{{ formatCurrency(Number(req.estimated_cost)) }}</div>
                            </td>
                            <td class="px-4 py-3 font-mono text-xs text-gray-600">
                                {{ req.current_stock ?? '—' }} · {{ req.forecast_quantity ?? 'n/a' }} · {{ req.ahp_score != null ? Number(req.ahp_score).toFixed(2) : 'incomplete' }}
                                <div class="font-sans text-gray-400">{{ req.ahp_classification ?? '' }}</div>
                            </td>
                            <td class="px-4 py-3 text-gray-500 text-xs">{{ req.submitted_at ? formatDate(req.submitted_at) : 'Draft' }}</td>
                            <td class="px-4 py-3 text-center">
                                <span :class="prStatusClass(req.status)" class="rounded-full border px-2.5 py-0.5 text-xs font-medium">{{ req.status }}</span>
                                <div v-if="req.reviewed_by" class="mt-1 text-xs text-gray-500">{{ req.reviewed_by.name }} · {{ formatDate(req.reviewed_at ?? '') }}</div>
                                <div v-if="req.review_remarks" class="max-w-[12rem] text-xs text-gray-500">{{ req.review_remarks }}</div>
                                <div v-if="req.purchase_order" class="mt-1 font-mono text-xs text-blue-700">PO {{ req.purchase_order.po_number }}</div>
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex items-center justify-end gap-2">
                                    <button v-if="req.status === 'Draft'" type="button" class="inline-flex items-center px-2.5 py-1 text-xs font-medium border border-gray-200 rounded-lg hover:bg-gray-50" @click="submitDraftRequest(req)">Submit</button>
                                    <button v-if="isAdmin && req.status === 'Pending Approval'" type="button" class="inline-flex items-center px-2.5 py-1 text-xs font-medium border border-[#4b5320] text-[#4b5320] rounded-lg hover:bg-gray-50" @click="openReview(req)">Review</button>
                                    <button v-if="req.purchase_order" type="button" class="inline-flex items-center px-2.5 py-1 text-xs font-medium border border-gray-200 rounded-lg hover:bg-gray-50" @click="workspacePoId = req.purchase_order.id">Open PO</button>
                                    <button
                                        v-if="canDelete && ['Draft', 'Pending Approval', 'Rejected'].includes(req.status)"
                                        type="button"
                                        @click="openDeleteModal(req)"
                                        class="inline-flex items-center gap-1 px-2.5 py-1 text-xs font-medium text-red-600 hover:text-red-700 hover:bg-red-50 border border-red-200 rounded-lg transition-colors cursor-pointer"
                                        title="Delete purchase request"
                                    >
                                        <Trash2 class="w-3.5 h-3.5" />
                                        <span>Delete</span>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <div v-if="requests.total > requests.data.length" class="p-4 flex items-center justify-between border-t border-gray-100">
                <span class="text-xs text-gray-500">
                    Showing {{ requests.data.length }} of {{ requests.total }} requests
                </span>
                <div class="flex gap-1">
                    <button
                        v-for="(link, i) in requests.links"
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

    <!-- ======================================================== -->
    <!-- MODAL: IMPORT PURCHASE ORDERS CSV -->
    <!-- ======================================================== -->
    <div v-if="showPoImportModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
        <div class="bg-white border border-gray-200 p-6 rounded-xl w-full shadow-xl max-h-[90vh] overflow-y-auto" :class="poDocumentFile ? 'max-w-5xl' : 'max-w-2xl'">
            <div class="flex justify-between items-center mb-4 border-b border-gray-100 pb-3">
                <div>
                    <h3 class="text-xl font-semibold text-gray-900">Import Purchase Orders</h3>
                    <p class="text-xs text-gray-500">
                        A purchase order document (PDF, PNG, JPG) is read automatically and shown for checking, or a CSV with
                        <code class="bg-gray-100 px-1 py-0.5 rounded font-semibold text-gray-800">ORDER DATE, PURCHASE ORDER NO., QTY ORDERED, SUPPLIER, ITEM/SKU Number, Description, UNIT COST, U/M, EXTENDED COST</code>
                    </p>
                </div>
                <button @click="showPoImportModal = false" class="text-gray-400 hover:text-gray-600 text-xl cursor-pointer">&times;</button>
            </div>

            <!-- File Input -->
            <div class="mb-4">
                <label for="po_import_file" class="block text-xs font-semibold text-gray-700 mb-2">Select a purchase order document (PDF, PNG, JPG, up to 25 MB) or a CSV file</label>
                <input
                    id="po_import_file"
                    type="file"
                    accept=".pdf,.png,.jpg,.jpeg,.csv,.txt"
                    data-test="po-import-file"
                    @change="handlePoFileChange"
                    class="w-full text-xs text-gray-600 file:mr-4 file:py-2 file:px-4 file:rounded-xl file:border-0 file:text-xs file:font-semibold file:bg-[#F8B803] file:text-gray-900 hover:file:opacity-90 cursor-pointer border border-gray-200 rounded-xl p-2"
                />
            </div>

            <!-- A purchase order document: read, checked and corrected, then saved (has its own buttons) -->
            <PurchaseOrderDocumentImport
                v-if="poDocumentFile"
                :file="poDocumentFile"
                :team-slug="teamSlug"
                :suppliers="suppliers ?? []"
                @saved="onPoDocumentSaved"
                @cancel="showPoImportModal = false"
            />

            <!-- Loading State -->
            <div v-if="isValidatingPoCsv" class="py-6 text-center text-gray-500 text-xs">
                <Clock class="w-5 h-5 mx-auto animate-spin mb-1 text-[#F8B803]" />
                Validating CSV rows...
            </div>

            <!-- Error Message -->
            <div v-if="poCsvError" class="p-3 mb-4 rounded-xl bg-red-50 border border-red-200 text-xs text-red-700 flex items-start gap-2">
                <AlertCircle class="w-4 h-4 shrink-0 text-red-500 mt-0.5" />
                <span>{{ poCsvError }}</span>
            </div>

            <!-- Preview Results -->
            <div v-if="poCsvValidationResult" class="space-y-4">
                <div class="flex gap-4 p-3 bg-gray-50 rounded-xl border border-gray-100 text-xs">
                    <div>
                        <span class="text-gray-500">Total Rows:</span>
                        <span class="ml-1 font-semibold text-gray-900">{{ poCsvValidationResult.summary.total_rows }}</span>
                    </div>
                    <div>
                        <span class="text-gray-500">Valid:</span>
                        <span class="ml-1 font-semibold text-emerald-600">{{ poCsvValidationResult.summary.valid_count }}</span>
                    </div>
                    <div>
                        <span class="text-gray-500">Invalid:</span>
                        <span class="ml-1 font-semibold text-red-600">{{ poCsvValidationResult.summary.invalid_count }}</span>
                    </div>
                </div>

                <!-- Preview Table -->
                <div v-if="poCsvValidationResult.valid_rows?.length" class="max-h-60 overflow-y-auto border border-gray-200 rounded-xl">
                    <table class="w-full text-left text-xs text-gray-600">
                        <thead class="bg-gray-50 font-semibold text-gray-700 border-b border-gray-200 sticky top-0">
                            <tr>
                                <th class="p-2">Date</th>
                                <th class="p-2">Purchase Order No.</th>
                                <th class="p-2 text-center">QTY ORDERED</th>
                                <th class="p-2">ITEM/SKU</th>
                                <th class="p-2">Description</th>
                                <th class="p-2 text-center">U/M</th>
                                <th class="p-2 text-right">Unit Cost</th>
                                <th class="p-2 text-right">Total</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <tr v-for="r in poCsvValidationResult.valid_rows.slice(0, 10)" :key="r.row_number">
                                <td class="p-2 whitespace-nowrap">{{ r.display_date || r.date }}</td>
                                <td class="p-2 font-mono font-semibold text-blue-700 whitespace-nowrap">{{ r.po_number }}</td>
                                <td class="p-2 text-center font-semibold">{{ r.quantity }}</td>
                                <td class="p-2 font-mono text-xs">{{ r.item_sku || '—' }}</td>
                                <td class="p-2 max-w-xs truncate">{{ r.description }}</td>
                                <td class="p-2 text-center font-semibold">{{ r.unit_of_measure }}</td>
                                <td class="p-2 text-right whitespace-nowrap">{{ formatCurrency(r.unit_cost) }}</td>
                                <td class="p-2 text-right font-semibold whitespace-nowrap">{{ formatCurrency(r.total_cost) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Modal Footer -->
            <div v-if="!poDocumentFile" class="flex justify-end gap-3 mt-6 pt-3 border-t border-gray-100">
                <SpecularButton
                    type="button"
                    variant="secondary"
                    size="md"
                    :radius="10"
                    @click="showPoImportModal = false"
                >
                    Cancel
                </SpecularButton>
                <SpecularButton
                    type="button"
                    variant="brand"
                    size="md"
                    :radius="10"
                    :disabled="isImportingPoCsv || !poCsvValidationResult || poCsvValidationResult.summary?.valid_count === 0"
                    @click="executePoCsvImport"
                >
                    {{ isImportingPoCsv ? 'Importing...' : `Import ${poCsvValidationResult?.summary?.valid_count || ''} Purchase Orders` }}
                </SpecularButton>
            </div>
        </div>
    </div>

    <!-- ======================================================== -->
    <!-- MODAL: DELETE PURCHASE ORDER -->
    <!-- ======================================================== -->
    <div v-if="showDeletePoModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
        <div class="bg-white border border-gray-200 p-6 rounded-xl w-full max-w-md shadow-xl">
            <h3 class="text-xl font-semibold text-gray-900 mb-2">Delete Purchase Order</h3>
            <p class="text-gray-600 text-sm mb-2">
                Move purchase order <span class="font-semibold text-gray-900">{{ selectedPo?.po_number }}</span> to the Recycle Bin?
            </p>
            <p class="text-gray-500 text-xs mb-4">
                An Admin can restore or permanently delete this record from the Recycle Bin. Unrelated active sessions and trusted devices are not affected.
            </p>
            <div class="mt-4 mb-6">
                <label class="block text-xs font-semibold text-gray-700 mb-1.5">
                    Type <span class="font-mono font-semibold text-rose-600 bg-rose-50 px-1.5 py-0.5 rounded border border-rose-200">YES</span> to confirm deletion:
                </label>
                <input
                    v-model="deletePoConfirmInput"
                    type="text"
                    placeholder="Type YES here"
                    class="w-full px-3 py-2 text-sm font-mono rounded-xl border border-gray-300 focus:outline-none focus:border-rose-500 focus:ring-1 focus:ring-rose-500"
                    @keyup.enter="deletePoConfirmInput === 'YES' && confirmDeletePo()"
                />
            </div>
            <div class="flex justify-end gap-3">
                <SpecularButton
                    variant="secondary"
                    size="md"
                    :radius="10"
                    @click="showDeletePoModal = false"
                >
                    Cancel
                </SpecularButton>
                <SpecularButton
                    variant="destructive"
                    size="md"
                    :radius="10"
                    @click="confirmDeletePo"
                    :disabled="deletePoConfirmInput !== 'YES'"
                >
                    Delete Order
                </SpecularButton>
            </div>
        </div>
    </div>

    <!-- ======================================================== -->
    <!-- MODAL: DELETE PURCHASE REQUEST -->
    <!-- ======================================================== -->
    <div v-if="showDeleteModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
        <div class="bg-white border border-gray-200 p-6 rounded-xl w-full max-w-md shadow-xl" role="dialog" aria-modal="true" aria-labelledby="delete-pr-title">
            <h3 id="delete-pr-title" class="text-xl font-semibold text-gray-900 mb-2">Delete Purchase Request?</h3>
            <p class="text-gray-600 text-sm mb-4">
                Are you sure you want to delete this purchase request? This action cannot be undone.
            </p>
            <div class="bg-gray-50 border border-gray-100 rounded-xl p-3.5 mb-6 text-xs text-gray-600">
                <span class="font-mono font-semibold text-gray-900">{{ selectedRequest?.request_number }}</span><template v-if="selectedRequest?.product?.name"> · {{ selectedRequest.product.name }}</template>
                <span class="block mt-1 text-gray-500">Its product, supplier, quotations and purchase orders are not changed.</span>
            </div>
            <div class="flex justify-end gap-3">
                <SpecularButton
                    variant="secondary"
                    size="md"
                    :radius="10"
                    :disabled="deletingRequest"
                    @click="showDeleteModal = false"
                >
                    Cancel
                </SpecularButton>
                <SpecularButton
                    variant="destructive"
                    size="md"
                    :radius="10"
                    @click="confirmDelete"
                    :disabled="deletingRequest"
                >
                    {{ deletingRequest ? 'Deleting...' : 'Delete' }}
                </SpecularButton>
            </div>
        </div>
    </div>

    <!-- ======================================================== -->
    <!-- MODAL: ACTION CONFIRMATION -->
    <!-- ======================================================== -->
    <div v-if="showConfirmModal" class="fixed inset-0 z-[60] flex items-center justify-center bg-black/60 p-4">
        <div class="bg-white border border-gray-200 p-6 rounded-xl w-full max-w-md shadow-xl animate-in fade-in duration-150">
            <div class="w-11 h-11 rounded-full bg-amber-50 text-[#F8B803] flex items-center justify-center mb-4 border border-amber-100">
                <CheckCircle2 class="w-6 h-6" />
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
                    variant="brand"
                    size="md"
                    :radius="10"
                    @click="handleConfirmedAction"
                    :disabled="actionBusy"
                >
                    <Check class="w-4 h-4 mr-1.5 inline" />
                    <span>{{ confirmOptions.confirmText || 'Confirm & Submit' }}</span>
                </SpecularButton>
            </div>
        </div>
    </div>
    <!-- Workflow: new request product picker → request creator -->
    <div v-if="showProductPicker" class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4" @click.self="showProductPicker = false">
        <div class="w-full max-w-md rounded-xl border border-gray-200 bg-white p-6" role="dialog" aria-modal="true" aria-labelledby="pick-title">
            <h3 id="pick-title" class="text-base font-semibold text-gray-900">New purchase request</h3>
            <p class="mb-3 text-xs text-gray-500">Tip: Reports &amp; Analytics → AHP Product Prioritization lists the products the system recommends reordering.</p>
            <form class="space-y-3" novalidate @submit.prevent="continueNewRequest">
                <!-- 1. Product -->
                <div>
                    <div class="relative">
                        <select id="pick-product" v-model="pickedProductId" required
                                :class="[floatField, pickerErrors.product ? 'border-rose-400' : 'border-gray-200', pickedProductId === null && pickerFocus !== 'product' ? 'text-transparent' : '']"
                                :aria-invalid="!!(pickerErrors.product || missingUnitMessage)" :aria-describedby="pickerErrors.product || missingUnitMessage ? 'pick-product-error' : undefined"
                                @focus="pickerFocus = 'product'" @blur="pickerFocus = ''" @change="pickerErrors.product = undefined">
                            <option :value="null" disabled>Select Product</option>
                            <option v-for="p in products" :key="p.id" :value="p.id" class="text-gray-900">{{ p.name }} ({{ p.sku }}) · stock {{ p.stock }}</option>
                            <option :value="UNLISTED" class="text-gray-900">Product Not Listed</option>
                        </select>
                        <label for="pick-product" :class="[floatLabel, pickedProductId !== null || pickerFocus === 'product' ? floatUp : 'text-gray-500']">Select Product</label>
                    </div>
                    <p v-if="pickerErrors.product || missingUnitMessage" id="pick-product-error" class="mt-1 text-xs text-rose-600">{{ pickerErrors.product || missingUnitMessage }}</p>
                </div>

                <!-- Product Not Listed: the staff's description of what to buy -->
                <div v-if="isUnlisted">
                    <div class="relative">
                        <textarea id="pick-description" v-model="pickedDescription" rows="3" maxlength="1000" placeholder=" " required
                                  :class="[floatField, 'resize-y', pickerErrors.description ? 'border-rose-400' : 'border-gray-200']"
                                  :aria-invalid="!!pickerErrors.description" :aria-describedby="pickerErrors.description ? 'pick-description-error' : undefined"
                                  @input="pickerErrors.description = undefined"></textarea>
                        <label for="pick-description"
                               :class="[floatLabel, 'text-gray-500 peer-placeholder-shown:translate-y-0 peer-placeholder-shown:scale-100 peer-focus:-translate-y-2.5 peer-focus:scale-75 peer-focus:text-[#4b5320]', pickedDescription ? floatUp : '']">Product Description</label>
                    </div>
                    <p v-if="pickerErrors.description" id="pick-description-error" class="mt-1 text-xs text-rose-600">{{ pickerErrors.description }}</p>
                </div>

                <!-- Required quantity (positive whole number); a catalog product's unit is shown beside it -->
                <div>
                    <div class="relative">
                        <input id="pick-quantity" v-model="pickedQuantity" type="number" min="1" step="1" inputmode="numeric" placeholder=" " required
                               :class="[floatField, pickedUnit ? 'pr-20' : '', pickerErrors.quantity ? 'border-rose-400' : 'border-gray-200']"
                               :aria-invalid="!!pickerErrors.quantity" :aria-describedby="[pickerErrors.quantity ? 'pick-quantity-error' : '', pickedUnit ? 'pick-unit' : ''].filter(Boolean).join(' ') || undefined"
                               @keydown="['.', ',', '-', '+', 'e', 'E'].includes($event.key) && $event.preventDefault()"
                               @input="pickerErrors.quantity = undefined" />
                        <label for="pick-quantity"
                               :class="[floatLabel, 'text-gray-500 peer-placeholder-shown:translate-y-0 peer-placeholder-shown:scale-100 peer-focus:-translate-y-2.5 peer-focus:scale-75 peer-focus:text-[#4b5320]', pickedQuantity !== null && pickedQuantity !== '' ? floatUp : '']">Required Quantity</label>
                        <!-- The product's unit (Product Catalog), read-only -->
                        <span v-if="pickedUnit" id="pick-unit" class="pointer-events-none absolute inset-y-0 right-3.5 flex items-center pt-2 text-xs font-medium text-gray-500 sm:text-sm"><span class="sr-only">Unit: </span>{{ pickedUnit }}</span>
                    </div>
                    <p v-if="pickerErrors.quantity" id="pick-quantity-error" class="mt-1 text-xs text-rose-600">{{ pickerErrors.quantity }}</p>
                </div>

                <!-- Product Not Listed: unit from the system's units -->
                <div v-if="isUnlisted">
                    <div class="relative">
                        <select id="pick-unit-choice" v-model="pickedUnitChoice" required
                                :class="[floatField, pickerErrors.unit ? 'border-rose-400' : 'border-gray-200', !pickedUnitChoice && pickerFocus !== 'unit' ? 'text-transparent' : '']"
                                :aria-invalid="!!pickerErrors.unit" :aria-describedby="pickerErrors.unit ? 'pick-unit-error' : undefined"
                                @focus="pickerFocus = 'unit'" @blur="pickerFocus = ''" @change="pickerErrors.unit = undefined">
                            <option value="" disabled>Select Unit</option>
                            <option v-for="u in unitOptions ?? []" :key="u" :value="u" class="text-gray-900">{{ u }}</option>
                        </select>
                        <label for="pick-unit-choice" :class="[floatLabel, pickedUnitChoice || pickerFocus === 'unit' ? floatUp : 'text-gray-500']">{{ pickedUnitChoice || pickerFocus === 'unit' ? 'Unit' : 'Select Unit' }}</label>
                    </div>
                    <p v-if="pickerErrors.unit" id="pick-unit-error" class="mt-1 text-xs text-rose-600">{{ pickerErrors.unit }}</p>
                </div>

                <div class="flex justify-end gap-2 pt-1">
                    <button type="button" class="secondary-button" @click="showProductPicker = false">Cancel</button>
                    <button type="submit" class="brand-button">Continue</button>
                </div>
            </form>
        </div>
    </div>
    <PurchaseRequestCreator :open="showPrCreator" :candidates="prCandidates" @close="showPrCreator = false" @submitted="showPrCreator = false; showToast('Purchase request saved.')" />

    <!-- Workflow: admin review -->
    <div v-if="reviewing" class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4" @click.self="reviewing = null">
        <div class="w-full max-w-lg rounded-xl border border-gray-200 bg-white p-6" role="dialog" aria-modal="true" aria-labelledby="review-title">
            <h3 id="review-title" class="text-base font-semibold text-gray-900">Review {{ reviewing.request_number }}</h3>
            <dl class="my-3 grid grid-cols-2 gap-2 text-sm">
                <div><dt class="text-xs text-gray-500">Product</dt><dd>{{ reviewing.product?.name ?? (reviewing.product_description ? 'Not listed in the Product Catalog' : '—') }}</dd></div>
                <div><dt class="text-xs text-gray-500">Requested by</dt><dd>{{ reviewing.requested_by?.name ?? '—' }}</dd></div>
                <div><dt class="text-xs text-gray-500">Requested / recommended</dt><dd class="font-mono">{{ reviewing.quantity }}<template v-if="reviewing.unit"> {{ reviewing.unit }}</template> / {{ reviewing.recommended_quantity ?? '—' }}</dd></div>
                <div><dt class="text-xs text-gray-500">Stock at request · SMA forecast</dt><dd class="font-mono">{{ reviewing.current_stock ?? '—' }} · {{ reviewing.forecast_quantity ?? 'n/a' }}</dd></div>
                <div><dt class="text-xs text-gray-500">AHP score</dt><dd class="font-mono">{{ reviewing.ahp_score != null ? Number(reviewing.ahp_score).toFixed(2) : 'Incomplete data' }} {{ reviewing.ahp_classification ?? '' }}</dd></div>
                <div><dt class="text-xs text-gray-500">Estimated cost</dt><dd>{{ formatCurrency(Number(reviewing.estimated_cost)) }}</dd></div>
                <div v-if="reviewing.product_description" class="col-span-2"><dt class="text-xs text-gray-500">Product description (not listed: no inventory record until the goods are received)</dt><dd class="whitespace-pre-line">{{ reviewing.product_description }}</dd></div>
                <div class="col-span-2"><dt class="text-xs text-gray-500">Justification</dt><dd>{{ reviewing.justification || '—' }}</dd></div>
            </dl>
            <label class="field-label" for="review-remarks">Remarks (required to reject)</label>
            <textarea id="review-remarks" v-model="reviewForm.remarks" rows="2" class="field-control w-full"></textarea>
            <p v-for="(e, k) in reviewForm.errors" :key="k" class="mt-1 text-xs text-rose-600">{{ e }}</p>
            <div class="mt-4 flex justify-end gap-2">
                <button type="button" class="secondary-button" @click="reviewing = null">Close</button>
                <button type="button" class="secondary-button text-rose-700" :disabled="reviewForm.processing" @click="submitReview('reject')">Reject</button>
                <button type="button" class="brand-button" :disabled="reviewForm.processing" @click="submitReview('approve')">Approve</button>
            </div>
        </div>
    </div>


    <PurchaseOrderWorkspace :po-id="workspacePoId" :is-admin="isAdmin" :products="products" @close="closeWorkspace" />

</template>
