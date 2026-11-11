<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import { useCanDelete } from '@/composables/useCanDelete';
import { useCurrency } from '@/composables/useCurrency';
import { showToast } from '@/composables/useFlashToast';
import { ref, computed, watch, defineAsyncComponent } from 'vue';
import SpecularButton from '@/components/ui/SpecularButton.vue';
import GlideSelect from '@/components/vue-bits/GlideSelect.vue';
import {
    Upload, 
    Download, 
    Plus, 
    Search, 
    Trash2, 
    Pencil, 
    Eye,
    CheckCircle2, 
    AlertCircle, 
    AlertTriangle,
    X, 
    FileSpreadsheet,
    Package,
    Check,
    ArrowUpRight,
    ArrowDownLeft,
    Sliders,
    History,
    RefreshCw,
    Building2,
    Layers
} from '@lucide/vue';

// The import wizard is a modal: it downloads after the page renders, not before.
const ImportWizard = defineAsyncComponent(() => import('@/components/import/ImportWizard.vue'));

interface Supplier {
    id: number;
    name: string;
    contact_person?: string;
    phone?: string;
}

interface StockMovement {
    id: number;
    product_id: number;
    type: 'stock_in' | 'stock_out' | 'adjustment' | 'sale';
    quantity: number;
    previous_stock: number;
    new_stock: number;
    reference_number?: string;
    reason?: string;
    remarks?: string;
    movement_date: string;
    product?: { 
        id: number; 
        name: string; 
        sku: string; 
        category?: string; 
        status?: string;
        stock?: number;
        reorder_level?: number;
    };
    user?: { id: number; name: string };
    supplier?: { id: number; name: string };
}

interface Product {
    id: number;
    sku: string;
    name: string;
    category: string;
    brand?: string | null;
    unit: string | null;
    price: number | string | null;
    cost_price: number | string | null;
    stock: number;
    missing_fields?: string[] | null;
    min_stock?: number;
    reorder_level: number;
    reorder_quantity: number;
    status: 'In Stock' | 'Low Stock' | 'Out of Stock' | 'Incomplete';
    description?: string;
    supplier_id?: number | null;
    supplier?: Supplier | null;
}

interface PaginationLink {
    url: string | null;
    label: string;
    active: boolean;
}

interface PaginatedProducts {
    data: Product[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number;
    to: number;
    links: PaginationLink[];
}

// Product Catalog or Product Movement. (AHP Product Prioritization is in Reports and Analytics.)
type InventoryView = 'table' | 'history';


const props = defineProps<{
    products: PaginatedProducts;
    summary: {
        total: number;
        in_stock: number;
        low_stock: number;
        out_of_stock: number;
        requiring_reorder: number;
        incomplete?: number;
    };
    categories: string[];
    suppliers: Supplier[];
    recent_movements: StockMovement[];
    filters: {
        search?: string;
        category?: string;
        status?: string;
        view?: InventoryView;
    };
}>();

const page = usePage();
const canDelete = useCanDelete();
const teamSlug = computed(() => (page.props as any).currentTeam?.slug || (page.props as any).team?.slug || '');

// Filters state
const searchQuery = ref(props.filters?.search || '');
const selectedCategory = ref(props.filters?.category || 'All Categories');
const selectedStatus = ref(props.filters?.status || 'All');
// An old link may still carry ?view=prioritization: anything but Product Movement opens the catalog.
const viewOrCatalog = (view?: string | null): InventoryView => (view === 'history' ? 'history' : 'table');
const activeView = ref<InventoryView>(viewOrCatalog(props.filters?.view));

// Flash toasts are handled globally by useFlashToast in AppSidebarLayout

// Filter debounce navigation
let searchTimeout: ReturnType<typeof setTimeout> | null = null;
const triggerNavigation = () => {
    if (searchTimeout) clearTimeout(searchTimeout);
    router.get(
        `/${teamSlug.value}/inventory`,
        {
            search: searchQuery.value || undefined,
            category: selectedCategory.value !== 'All Categories' ? selectedCategory.value : undefined,
            status: selectedStatus.value !== 'All' ? selectedStatus.value : undefined,
            view: activeView.value !== 'table' ? activeView.value : undefined,
        },
        {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            // Reload only what the filters change.
            only: ['products', 'summary', 'recent_movements', 'filters'],
        }
    );
};

const applyFilters = () => {
    if (searchTimeout) clearTimeout(searchTimeout);
    searchTimeout = setTimeout(triggerNavigation, 300);
};

const filterByCategory = (cat: string) => {
    selectedCategory.value = cat;
    triggerNavigation();
};

const filterByStatus = (st: string) => {
    selectedStatus.value = st;
    triggerNavigation();
};

const changeView = (view: InventoryView) => {
    activeView.value = view;
    triggerNavigation();
};

// Movement history: the change is always new − previous stock (types store the quantity differently).
const movementLabels: Record<string, string> = {
    stock_in: 'Stock In', stock_out: 'Stock Out', sale: 'Counter Sale', sale_release: 'Delivered', return: 'Returned', adjustment: 'Adjustment',
};
const movementLabel = (m: any) => movementLabels[m.type] ?? String(m.type).replace(/_/g, ' ');
const movementChange = (m: any) => Number(m.new_stock) - Number(m.previous_stock);
const movementChangeText = (m: any) => (movementChange(m) > 0 ? '+' : '') + movementChange(m);
const movementClass = (m: any) => (m.type === 'adjustment' ? 'text-purple-700' : movementChange(m) >= 0 ? 'text-emerald-600' : 'text-amber-700');
const movementBadgeClass = (m: any) => (m.type === 'adjustment' ? 'bg-purple-50 text-purple-700 border-purple-200' : movementChange(m) >= 0 ? 'bg-emerald-50 text-emerald-700 border-emerald-200' : 'bg-amber-50 text-amber-700 border-amber-200');

// Filtered movements based on active filters
const filteredMovements = computed(() => {
    let list = props.recent_movements || [];

    if (selectedCategory.value && selectedCategory.value !== 'All Categories') {
        list = list.filter(m => m.product?.category === selectedCategory.value);
    }

    if (selectedStatus.value && selectedStatus.value !== 'All') {
        list = list.filter(m => {
            const product = m.product;
            if (!product) return false;
            if (selectedStatus.value === 'Needs Reorder') {
                const threshold = (product.reorder_level && product.reorder_level > 0) ? product.reorder_level : 10;
                return (product.stock !== undefined && product.stock <= threshold) || product.status === 'Low Stock' || product.status === 'Out of Stock';
            }
            if (selectedStatus.value === 'Out of Stock') {
                return (product.stock !== undefined && product.stock <= 0) || product.status === 'Out of Stock';
            }
            if (selectedStatus.value === 'Low Stock') {
                const threshold = (product.reorder_level && product.reorder_level > 0) ? product.reorder_level : 10;
                return (product.stock !== undefined && product.stock > 0 && product.stock <= threshold) || product.status === 'Low Stock';
            }
            if (selectedStatus.value === 'In Stock') {
                const threshold = (product.reorder_level && product.reorder_level > 0) ? product.reorder_level : 10;
                return (product.stock !== undefined && product.stock > threshold) || product.status === 'In Stock';
            }
            return product.status === selectedStatus.value;
        });
    }

    if (searchQuery.value && searchQuery.value.trim() !== '') {
        const query = searchQuery.value.toLowerCase().trim();
        list = list.filter(m => 
            m.product?.name?.toLowerCase().includes(query) ||
            m.product?.sku?.toLowerCase().includes(query) ||
            m.reference_number?.toLowerCase().includes(query) ||
            m.reason?.toLowerCase().includes(query) ||
            m.remarks?.toLowerCase().includes(query) ||
            m.user?.name?.toLowerCase().includes(query)
        );
    }

    return list;
});

// Sync filter changes when props change
watch(() => props.filters, (newFilters) => {
    if (newFilters) {
        if (newFilters.status !== undefined) selectedStatus.value = newFilters.status || 'All';
        if (newFilters.category !== undefined) selectedCategory.value = newFilters.category || 'All Categories';
        if (newFilters.search !== undefined) searchQuery.value = newFilters.search || '';
        if (newFilters.view !== undefined) activeView.value = viewOrCatalog(newFilters.view);
    }
}, { deep: true });

// Stored prices are USD; shown in the user's display currency (header switch). Values from an
// import preview may still be text such as "1,200.00".
const { formatMoney } = useCurrency();
const formatPrice = (val: string | number | null | undefined): string => {
    const num = typeof val === 'number' ? val : parseFloat(String(val ?? '').replace(/[^0-9.-]/g, ''));
    return formatMoney(Number.isNaN(num) ? 0 : num);
};

const formatDate = (dateStr: string | undefined): string => {
    if (!dateStr) return 'N/A';
    try {
        const d = new Date(dateStr);
        return d.toLocaleDateString('en-PH', { month: 'short', day: 'numeric', year: 'numeric', hour: '2-digit', minute: '2-digit' });
    } catch {
        return dateStr;
    }
};

const getStatusClass = (status: string) => {
    if (status === 'Out of Stock') return 'bg-rose-50 text-rose-700 border-rose-200';
    if (status === 'Low Stock') return 'bg-amber-50 text-amber-700 border-amber-200';
    return 'bg-emerald-50 text-emerald-700 border-emerald-200';
};

const hasNumVal = (v: unknown): boolean => v !== null && v !== undefined && v !== '';

// ==========================================
// MODALS STATE
// ==========================================
const showAddEditModal = ref(false);
const isEditing = ref(false);
const editingProductId = ref<number | null>(null);
const showDeleteModal = ref(false);
const deleteConfirmInput = ref('');
const showViewModal = ref(false);
const showStockInModal = ref(false);
const showStockOutModal = ref(false);
const showAdjustModal = ref(false);
const showImportModal = ref(false);

// Confirmation Modal State
interface ConfirmActionOptions {
    title: string;
    description: string;
    details?: Array<{ label: string; value: string | number }>;
    confirmText?: string;
    confirmVariant?: 'brand' | 'success' | 'brand-gold' | 'secondary' | 'destructive';
    action: () => void;
}

const showConfirmModal = ref(false);
const confirmOptions = ref<ConfirmActionOptions>({
    title: 'Confirm Action',
    description: 'Are you sure you want to proceed?',
    details: [],
    confirmText: 'Confirm & Save',
    confirmVariant: 'brand',
    action: () => {},
});

const openConfirmModal = (options: ConfirmActionOptions) => {
    confirmOptions.value = {
        confirmText: 'Confirm & Save',
        confirmVariant: 'brand',
        ...options,
    };
    showConfirmModal.value = true;
};

const handleConfirmedAction = () => {
    showConfirmModal.value = false;
    confirmOptions.value.action();
};

const selectedProduct = ref<Product | null>(null);
const productHistoryLoading = ref(false);
const productMovements = ref<StockMovement[]>([]);

// Product Form
const formProduct = ref({
    name: '',
    sku: '',
    category: 'Breakers',
    brand: '',
    unit: 'pcs',
    price: 0,
    cost_price: 0,
    stock: 0,
    reorder_level: 10,
    reorder_quantity: 50,
    description: '',
    supplier_id: null as number | null,
});

const formErrors = ref<Record<string, string>>({});
const isSubmitting = ref(false);

// SKU: generated by the server from the category (BRK-000001, …). A new product shows the next
// one for its category (assigned when saved); an existing product's SKU never changes.
const skuLoading = ref(false);
const skuCopied = ref(false);
const loadNextSku = async () => {
    if (isEditing.value || !formProduct.value.category) return;
    skuLoading.value = true;
    try {
        const res = await fetch(`/${teamSlug.value}/inventory/next-sku?category=${encodeURIComponent(formProduct.value.category)}`, { headers: { Accept: 'application/json' } });
        if (res.ok) formProduct.value.sku = (await res.json()).sku ?? '';
    } catch {
        formProduct.value.sku = '';
    } finally {
        skuLoading.value = false;
    }
};
watch(() => formProduct.value.category, () => {
    if (showAddEditModal.value && !isEditing.value) loadNextSku();
});
const copySku = async () => {
    const sku = formProduct.value.sku;
    if (!sku) return;
    try {
        await navigator.clipboard.writeText(sku);
    } catch {
        // Clipboard API needs HTTPS or localhost; fall back for the office network.
        const el = document.createElement('textarea');
        el.value = sku;
        document.body.appendChild(el);
        el.select();
        document.execCommand('copy');
        el.remove();
    }
    skuCopied.value = true;
    setTimeout(() => (skuCopied.value = false), 1500);
};

// Stock In Form
const formStockIn = ref({
    product_id: null as number | null,
    quantity: 1,
    supplier_id: null as number | null,
    reference_number: '',
    movement_date: new Date().toISOString().slice(0, 10),
    remarks: '',
});

// Stock Out Form
const formStockOut = ref({
    product_id: null as number | null,
    quantity: 1,
    reference_number: '',
    reason: 'Internal Use',
    movement_date: new Date().toISOString().slice(0, 10),
    remarks: '',
});

// Stock Adjustment Form
const formAdjust = ref({
    product_id: null as number | null,
    physical_count: 0,
    reason: 'Physical Count Correction',
    remarks: '',
});


// ==========================================
// CRUD & MODAL HANDLERS
// ==========================================
const openAddModal = () => {
    isEditing.value = false;
    editingProductId.value = null;
    formErrors.value = {};
    isSubmitting.value = false;
    formProduct.value = {
        name: '',
        sku: '',
        category: props.categories[0] || 'Breakers',
        brand: '',
        unit: 'pcs',
        price: 100,
        cost_price: 70,
        stock: 10,
        reorder_level: 10,
        reorder_quantity: 50,
        description: '',
        supplier_id: props.suppliers[0]?.id || null,
    };
    showAddEditModal.value = true;
    loadNextSku();
};

const openEditModal = (product: Product) => {
    isEditing.value = true;
    editingProductId.value = product.id;
    formErrors.value = {};
    isSubmitting.value = false;
    formProduct.value = {
        name: product.name,
        sku: product.sku,
        category: product.category,
        brand: product.brand || '',
        unit: product.unit || 'pcs',
        price: Number(product.price) || 0,
        cost_price: Number(product.cost_price) || 0,
        stock: product.stock,
        reorder_level: product.reorder_level || product.min_stock || 10,
        reorder_quantity: product.reorder_quantity || 50,
        description: product.description || '',
        supplier_id: product.supplier_id || null,
    };
    showAddEditModal.value = true;
};

const promptSaveProduct = () => {
    formErrors.value = {};
    if (!formProduct.value.name || !formProduct.value.name.trim()) {
        formErrors.value.name = 'Product name is required.';
        return;
    }

    if (formProduct.value.price === null || formProduct.value.price === undefined || formProduct.value.price === ('' as any) || Number(formProduct.value.price) < 0) {
        formErrors.value.price = 'Selling price must be greater than or equal to 0.';
        return;
    }

    if (isEditing.value) {
        openConfirmModal({
            title: 'Confirm Product Changes',
            description: `Are you sure you want to save modifications to "${formProduct.value.name}"?`,
            details: [
                { label: 'SKU', value: formProduct.value.sku },
                { label: 'Category', value: formProduct.value.category },
                { label: 'Selling Price', value: formatPrice(formProduct.value.price) },
                { label: 'Current Stock', value: `${formProduct.value.stock ?? 0} ${formProduct.value.unit || 'pcs'}` },
                { label: 'Reorder Level', value: `${formProduct.value.reorder_level ?? 10} ${formProduct.value.unit || 'pcs'}` },
            ],
            confirmText: 'Save Changes',
            confirmVariant: 'brand',
            action: saveProduct,
        });
    } else {
        openConfirmModal({
            title: 'Confirm New Product',
            description: `Are you sure you want to add this new product to the inventory catalog?`,
            details: [
                { label: 'Product Name', value: formProduct.value.name },
                { label: 'SKU', value: formProduct.value.sku ? `${formProduct.value.sku} (assigned when saved)` : 'Assigned when saved' },
                { label: 'Category', value: formProduct.value.category },
                { label: 'Selling Price', value: formatPrice(formProduct.value.price) },
                { label: 'Initial Stock', value: `${formProduct.value.stock ?? 0} ${formProduct.value.unit || 'pcs'}` },
                { label: 'Reorder Level', value: `${formProduct.value.reorder_level ?? 10} ${formProduct.value.unit || 'pcs'}` },
            ],
            confirmText: 'Add Product',
            confirmVariant: 'brand',
            action: saveProduct,
        });
    }
};

const saveProduct = () => {
    isSubmitting.value = true;
    formErrors.value = {};

    const url = isEditing.value 
        ? `/${teamSlug.value}/inventory/products/${editingProductId.value}`
        : `/${teamSlug.value}/inventory/products`;

    const method = isEditing.value ? 'put' : 'post';

    router[method](url, formProduct.value, {
        onSuccess: () => {
            showAddEditModal.value = false;
            isSubmitting.value = false;
        },
        onError: (errs) => {
            formErrors.value = errs;
            isSubmitting.value = false;
            showToast('Please check the form for errors.', 'error');
        },
        onFinish: () => {
            isSubmitting.value = false;
        },
    });
};

const openViewModal = async (product: Product) => {
    selectedProduct.value = product;
    showViewModal.value = true;
    productHistoryLoading.value = true;
    productMovements.value = [];

    try {
        const res = await fetch(`/${teamSlug.value}/inventory/history?product_id=${product.id}`, {
            headers: { 'Accept': 'application/json' }
        });
        const data = await res.json();
        productMovements.value = data.data || [];
    } catch {
        productMovements.value = [];
    } finally {
        productHistoryLoading.value = false;
    }
};

const openDeleteModal = (product: Product) => {
    isSubmitting.value = false;
    deleteConfirmInput.value = '';
    selectedProduct.value = product;
    showDeleteModal.value = true;
};

const deleteProduct = () => {
    if (!selectedProduct.value || deleteConfirmInput.value !== 'YES') return;
    isSubmitting.value = true;
    router.delete(`/${teamSlug.value}/inventory/products/${selectedProduct.value.id}`, {
        data: { confirmation: deleteConfirmInput.value },
        onSuccess: () => {
            showDeleteModal.value = false;
            selectedProduct.value = null;
            isSubmitting.value = false;
        },
        onError: () => {
            isSubmitting.value = false;
            showToast('Failed to remove product.', 'error');
        },
        onFinish: () => {
            isSubmitting.value = false;
        },
    });
};

// ==========================================
// STOCK-IN HANDLER
// ==========================================
const openStockInModal = (product?: Product) => {
    isSubmitting.value = false;
    formErrors.value = {};
    formStockIn.value = {
        product_id: product ? product.id : (props.products?.data?.[0]?.id || null),
        quantity: 10,
        supplier_id: product?.supplier_id || props.suppliers[0]?.id || null,
        reference_number: `PO-${Date.now().toString().slice(-4)}`,
        movement_date: new Date().toISOString().slice(0, 10),
        remarks: '',
    };
    showStockInModal.value = true;
};

const promptStockIn = () => {
    formErrors.value = {};
    if (!formStockIn.value.product_id) {
        formErrors.value.product_id = 'Please select a product.';
        return;
    }
    if (!formStockIn.value.quantity || formStockIn.value.quantity < 1) {
        formErrors.value.quantity = 'Quantity must be at least 1.';
        return;
    }

    const prod = props.products?.data?.find(p => p.id === formStockIn.value.product_id);
    const supplier = props.suppliers?.find(s => s.id === formStockIn.value.supplier_id);
    const currentStock = prod ? prod.stock : 0;
    const newStock = currentStock + Number(formStockIn.value.quantity);

    openConfirmModal({
        title: 'Confirm Stock-In Receipt',
        description: `Are you sure you want to record an incoming stock entry for this product?`,
        details: [
            { label: 'Product', value: prod ? `${prod.name} (${prod.sku})` : 'Selected Product' },
            { label: 'Incoming Quantity', value: `+${formStockIn.value.quantity} ${prod?.unit || 'pcs'}` },
            { label: 'Resulting Stock', value: `${newStock} ${prod?.unit || 'pcs'} (from ${currentStock})` },
            { label: 'Reference / PO', value: formStockIn.value.reference_number || 'N/A' },
            { label: 'Supplier', value: supplier?.name || 'Internal / Not Specified' },
        ],
        confirmText: 'Confirm Stock In',
        confirmVariant: 'success',
        action: submitStockIn,
    });
};

const submitStockIn = () => {
    isSubmitting.value = true;
    formErrors.value = {};

    router.post(`/${teamSlug.value}/inventory/stock-in`, formStockIn.value, {
        onSuccess: () => {
            showStockInModal.value = false;
            isSubmitting.value = false;
        },
        onError: (errs) => {
            formErrors.value = errs;
            isSubmitting.value = false;
            showToast(errs.quantity || 'Failed to record Stock-In.', 'error');
        },
        onFinish: () => {
            isSubmitting.value = false;
        },
    });
};

// ==========================================
// STOCK-OUT HANDLER
// ==========================================
const openStockOutModal = (product?: Product) => {
    isSubmitting.value = false;
    formErrors.value = {};
    const prod = product || props.products?.data?.[0] || null;
    formStockOut.value = {
        product_id: prod?.id || null,
        quantity: 1,
        reference_number: `SO-${Date.now().toString().slice(-4)}`,
        reason: 'Internal Use',
        movement_date: new Date().toISOString().slice(0, 10),
        remarks: '',
    };
    showStockOutModal.value = true;
};

const promptStockOut = () => {
    formErrors.value = {};
    if (!formStockOut.value.product_id) {
        formErrors.value.product_id = 'Please select a product.';
        return;
    }
    const prod = props.products?.data?.find(p => p.id === formStockOut.value.product_id);
    if (!prod) return;

    if (!formStockOut.value.quantity || formStockOut.value.quantity < 1) {
        formErrors.value.quantity = 'Quantity must be at least 1.';
        return;
    }
    if (formStockOut.value.quantity > prod.stock) {
        formErrors.value.quantity = `Quantity exceeds available stock (${prod.stock} ${prod.unit || 'pcs'}).`;
        return;
    }

    const currentStock = prod.stock;
    const remainingStock = Math.max(0, currentStock - Number(formStockOut.value.quantity));

    openConfirmModal({
        title: 'Confirm Stock-Out Deduction',
        description: `Are you sure you want to record this outgoing stock dispatch?`,
        details: [
            { label: 'Product', value: `${prod.name} (${prod.sku})` },
            { label: 'Quantity to Deduct', value: `-${formStockOut.value.quantity} ${prod.unit || 'pcs'}` },
            { label: 'Remaining Stock', value: `${remainingStock} ${prod.unit || 'pcs'} (from ${currentStock})` },
            { label: 'Reference / SO', value: formStockOut.value.reference_number || 'N/A' },
            { label: 'Reason / Purpose', value: formStockOut.value.reason || 'General Dispatch' },
        ],
        confirmText: 'Confirm Stock Out',
        confirmVariant: 'brand-gold',
        action: submitStockOut,
    });
};

const submitStockOut = () => {
    isSubmitting.value = true;
    formErrors.value = {};

    router.post(`/${teamSlug.value}/inventory/stock-out`, formStockOut.value, {
        onSuccess: () => {
            showStockOutModal.value = false;
            isSubmitting.value = false;
        },
        onError: (errs) => {
            formErrors.value = errs;
            isSubmitting.value = false;
            showToast(errs.quantity || 'Failed to record Stock-Out.', 'error');
        },
        onFinish: () => {
            isSubmitting.value = false;
        },
    });
};

// Selected product stock available for Stock Out validation helper
const currentSelectedStockOutProduct = computed(() => {
    return props.products?.data?.find(p => p.id === formStockOut.value.product_id);
});

// ==========================================
// STOCK ADJUSTMENT HANDLER
// ==========================================
const openAdjustModal = (product?: Product) => {
    isSubmitting.value = false;
    formErrors.value = {};
    const prod = product || props.products?.data?.[0] || null;
    formAdjust.value = {
        product_id: prod?.id || null,
        physical_count: prod?.stock || 0,
        reason: 'Physical Count Correction',
        remarks: '',
    };
    showAdjustModal.value = true;
};

const promptAdjust = () => {
    formErrors.value = {};
    if (!formAdjust.value.product_id) {
        formErrors.value.product_id = 'Please select a product.';
        return;
    }
    const prod = props.products?.data?.find(p => p.id === formAdjust.value.product_id);
    if (!prod) return;

    const currentStock = prod.stock;
    const physicalCount = Number(formAdjust.value.physical_count);
    const variance = physicalCount - currentStock;
    const varianceSign = variance >= 0 ? `+${variance}` : `${variance}`;

    openConfirmModal({
        title: 'Confirm Stock Count Adjustment',
        description: `Are you sure you want to apply this physical count correction? This will be recorded in the audit history.`,
        details: [
            { label: 'Product', value: `${prod.name} (${prod.sku})` },
            { label: 'System Stock Count', value: `${currentStock} ${prod.unit || 'pcs'}` },
            { label: 'New Physical Count', value: `${physicalCount} ${prod.unit || 'pcs'}` },
            { label: 'Stock Variance', value: `${varianceSign} ${prod.unit || 'pcs'}` },
            { label: 'Adjustment Reason', value: formAdjust.value.reason },
        ],
        confirmText: 'Commit Adjustment',
        confirmVariant: 'brand',
        action: submitAdjust,
    });
};

const submitAdjust = () => {
    isSubmitting.value = true;
    formErrors.value = {};

    router.post(`/${teamSlug.value}/inventory/adjust`, formAdjust.value, {
        onSuccess: () => {
            showAdjustModal.value = false;
            isSubmitting.value = false;
        },
        onError: (errs) => {
            formErrors.value = errs;
            isSubmitting.value = false;
            showToast(errs.physical_count || 'Failed to adjust stock.', 'error');
        },
        onFinish: () => {
            isSubmitting.value = false;
        },
    });
};

// ==========================================
// CSV IMPORT & EXPORT
// ==========================================
const openImportModal = () => {
    showImportModal.value = true;
};

// Export: the chosen format, with the filters currently applied to the list.
const exportFormats = [
    { value: 'xlsx', label: 'Excel', tag: '.xlsx' },
    { value: 'csv', label: 'CSV', tag: '.csv' },
    { value: 'pdf', label: 'PDF', tag: 'Print' },
    { value: 'png', label: 'PNG', tag: 'Image' },
    { value: 'jpg', label: 'JPG', tag: 'Image' },
];
const EXPORT_MAX_DOCUMENT_ROWS = 2000; // InventoryExporter::MAX_DOCUMENT_ROWS
const exportFormat = ref('xlsx');

const exportInventory = () => {
    const format = exportFormat.value;
    const total = props.products?.total ?? 0;
    if (['pdf', 'png', 'jpg'].includes(format) && total > EXPORT_MAX_DOCUMENT_ROWS) {
        showToast(`${total} products is more than a ${format.toUpperCase()} export can hold (${EXPORT_MAX_DOCUMENT_ROWS}). Filter the list, or export to Excel or CSV.`, 'error');
        return;
    }

    const params = new URLSearchParams({ format });
    if (props.filters?.search) params.set('search', props.filters.search);
    if (props.filters?.category && props.filters.category !== 'All Categories') params.set('category', props.filters.category);
    if (props.filters?.status && props.filters.status !== 'All') params.set('status', props.filters.status);

    window.location.href = `/${teamSlug.value}/inventory/export?${params.toString()}`;

    const label = exportFormats.find((f) => f.value === format)?.label ?? format;
    const many = ['png', 'jpg'].includes(format) && total > 50 ? ` (${Math.ceil(total / 50)} images in a .zip)` : '';
    showToast(`Preparing ${total} product${total === 1 ? '' : 's'} as ${label}${many}...`, 'success');
};
</script>

<template>
    <Head title="Inventory Management" />

    <div class="workspace-page relative">
        <!-- Header & Action Controls -->
        <div class="page-header">
            <div>
                <h1 class="page-title">Inventory Management</h1>
                <p class="page-subtitle">Stock levels, stock movements and reorder status.</p>
            </div>
            
            <div class="page-actions">
                <!-- Stock Movements Segmented Group -->
                <div class="inline-flex items-center h-10 rounded-xl border border-gray-200 bg-white p-1">
                    <button
                        type="button"
                        @click="openStockInModal()"
                        class="inline-flex items-center h-full gap-1.5 px-3 text-xs font-medium rounded-lg text-gray-700 hover:bg-gray-100 transition-colors cursor-pointer"
                        title="Manual stock in. Supplier deliveries are added automatically at inspection."
                    >
                        <ArrowDownLeft class="w-3.5 h-3.5 text-gray-500" />
                        <span>Stock In</span>
                    </button>

                    <div class="h-4 w-px bg-gray-200 mx-0.5"></div>

                    <button
                        type="button"
                        @click="openStockOutModal()"
                        class="inline-flex items-center h-full gap-1.5 px-3 text-xs font-medium rounded-lg text-gray-700 hover:bg-gray-100 transition-colors cursor-pointer"
                        title="Manual stock out. Customer deliveries are deducted automatically when delivered."
                    >
                        <ArrowUpRight class="w-3.5 h-3.5 text-gray-500" />
                        <span>Stock Out</span>
                    </button>

                    <div class="h-4 w-px bg-gray-200 mx-0.5"></div>

                    <button
                        type="button"
                        @click="openAdjustModal()"
                        class="inline-flex items-center h-full gap-1.5 px-3 text-xs font-medium rounded-lg text-gray-700 hover:bg-gray-100 transition-colors cursor-pointer"
                        title="Perform physical count adjustment"
                    >
                        <Sliders class="w-3.5 h-3.5 text-gray-500" />
                        <span>Adjust</span>
                    </button>
                </div>

                <!-- Export / Import Group -->
                <div class="inline-flex items-center h-10 rounded-xl border border-gray-200 bg-white p-1">
                    <GlideSelect
                        :options="exportFormats"
                        :value="exportFormat"
                        ariaLabel="Export format"
                        showTags
                        accentColor="#4b5320"
                        surfaceColor="#ffffff"
                        highlightColor="#f3f4f6"
                        textColor="#374151"
                        size="md"
                        :radius="12"
                        :menuWidth="168"
                        placement="bottom"
                        align="left"
                        className="z-30 text-xs"
                        @change="(value: string) => (exportFormat = value)"
                    />

                    <button
                        type="button"
                        @click="exportInventory"
                        class="inline-flex items-center h-full gap-1.5 px-3 text-xs font-medium rounded-lg text-gray-700 hover:bg-gray-100 transition-colors cursor-pointer"
                        title="Download the inventory list (with the current filters) in the chosen format"
                    >
                        <Download class="w-3.5 h-3.5 text-gray-500" />
                        <span>Export</span>
                    </button>

                    <div class="h-4 w-px bg-gray-200 mx-0.5"></div>

                    <button
                        type="button"
                        @click="openImportModal"
                        class="inline-flex items-center h-full gap-1.5 px-3 text-xs font-medium rounded-lg text-gray-700 hover:bg-gray-100 transition-colors cursor-pointer"
                        title="Import products from a PDF, Excel, CSV, JPG or PNG file"
                    >
                        <Upload class="w-3.5 h-3.5 text-gray-500" />
                        <span>Import</span>
                    </button>
                </div>

                <!-- Add Product Button (Aligned on the right) -->
                <SpecularButton
                    variant="brand"
                    size="md"
                    :radius="12"
                    @click="openAddModal"
                    class="h-10 text-xs font-semibold shrink-0"
                >
                    <Plus class="w-4 h-4 text-white" />
                    <span>Add Product</span>
                </SpecularButton>
            </div>
        </div>

        <!-- Inventory Summary (click a count to filter the list) -->
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
            <button
                v-for="card in [
                    { status: 'All', label: 'Total products', value: summary.total, alert: '' },
                    { status: 'Out of Stock', label: 'Out of stock', value: summary.out_of_stock, alert: 'text-rose-600' },
                    { status: 'Low Stock', label: 'Low stock', value: summary.low_stock, alert: 'text-amber-600' },
                    { status: 'In Stock', label: 'In stock', value: summary.in_stock, alert: '' },
                ]"
                :key="card.status"
                type="button"
                @click="filterByStatus(card.status)"
                :class="selectedStatus === card.status ? 'border-gray-900' : 'border-gray-200 hover:bg-gray-50'"
                class="text-left px-4 py-3 rounded-xl border bg-white transition-colors"
            >
                <div class="text-xs text-gray-500">{{ card.label }}</div>
                <div :class="card.value > 0 && card.alert ? card.alert : 'text-gray-900'" class="mt-1 text-2xl font-semibold">{{ card.value }}</div>
            </button>
        </div>

        <!-- Search & Filter Controls -->
        <div class="flex flex-wrap items-center gap-3 rounded-xl border border-gray-200 bg-white p-4">
            <div class="relative flex-1 min-w-[220px] max-w-md">
                <Search class="w-4 h-4 text-gray-400 absolute left-3 top-3" />
                <input v-model="searchQuery" 
                       @input="applyFilters"
                       type="text" 
                       placeholder="Search products by name, SKU, specs..." 
                       class="field-control w-full pl-9 pr-4" />
            </div>

            <div class="w-48">
                <select v-model="selectedCategory" 
                        @change="filterByCategory(selectedCategory)"
                        class="field-control w-full">
                    <option value="All Categories">All Categories</option>
                    <option v-for="cat in categories" :key="cat" :value="cat">{{ cat }}</option>
                </select>
            </div>

            <div class="w-44">
                <select v-model="selectedStatus" 
                        @change="filterByStatus(selectedStatus)"
                        class="field-control w-full">
                    <option value="All">All Statuses</option>
                    <option value="Needs Reorder">Needs Reorder ({{ summary.requiring_reorder }})</option>
                    <option value="Incomplete">Incomplete: missing details ({{ summary.incomplete ?? 0 }})</option>
                    <option value="Out of Stock">Out of Stock</option>
                    <option value="Low Stock">Low Stock</option>
                    <option value="In Stock">In Stock</option>
                </select>
            </div>

            <!-- Inventory view switcher (the module's only navigation): catalog, movements -->
            <div class="w-64">
                <select v-model="activeView"
                        @change="changeView(activeView)"
                        aria-label="Inventory view"
                        class="field-control w-full">
                    <option value="table">Product Catalog ({{ products.total }})</option>
                    <option value="history">Product Movement ({{ filteredMovements.length }})</option>
                </select>
            </div>
        </div>

        <!-- ========================================================================= -->
        <!-- VIEW 1: PRODUCT TABLE CARD -->
        <!-- ========================================================================= -->
        <div v-if="activeView === 'table'" class="flex-1 rounded-xl bg-white border border-gray-200 p-6 flex flex-col justify-between">
            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse">
                    <thead>
                        <tr class="border-b border-gray-200 text-gray-500 text-xs">
                            <th class="pb-3 font-semibold">SKU</th>
                            <th class="pb-3 font-semibold">Product Name</th>
                            <th class="pb-3 font-semibold">Category</th>
                            <th class="pb-3 font-semibold">Unit Price</th>
                            <th class="pb-3 font-semibold text-center">Stock</th>
                            <th class="pb-3 font-semibold">Reorder Level</th>
                            <th class="pb-3 font-semibold">Status</th>
                            <th class="pb-3 font-semibold text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="text-sm divide-y divide-gray-100">
                        <tr v-for="product in products.data" :key="product.id" class="hover:bg-gray-50/80 transition-colors group">
                            <td class="py-3.5 text-gray-500 font-mono text-xs">{{ product.sku }}</td>
                            <td class="py-3.5">
                                <div class="font-medium text-gray-900 hover:text-[#4b5320] cursor-pointer" @click="openViewModal(product)">
                                    {{ product.name }}
                                </div>
                                <div v-if="product.missing_fields?.length" class="text-[11px] text-amber-700 mt-0.5">
                                    Missing {{ product.missing_fields.map((f) => f.replace('_', ' ')).join(', ') }}: edit to complete<template v-if="product.missing_fields.some((f) => ['unit', 'price'].includes(f))"> and activate</template>
                                </div>
                                <div v-if="product.supplier" class="text-[11px] text-gray-400 flex items-center gap-1 mt-0.5">
                                    <Building2 class="w-3 h-3 text-gray-400" />
                                    <span>{{ product.supplier.name }}</span>
                                </div>
                            </td>
                            <td class="py-3.5">
                                <span class="px-2.5 py-0.5 rounded-md text-xs font-medium bg-gray-100 text-gray-700">
                                    {{ product.category }}
                                </span>
                            </td>
                            <td class="py-3.5 text-gray-900 font-medium">{{ product.price === null ? '—' : formatPrice(product.price) }}</td>
                            <td class="py-3.5 text-center">
                                <span class="font-semibold text-gray-900">{{ product.stock }}</span>
                                <span class="text-xs text-gray-400 ml-1">({{ product.unit || 'pcs' }})</span>
                            </td>
                            <td class="py-3.5 text-gray-600 text-xs">
                                <span>{{ product.reorder_level || product.min_stock || 10 }} {{ product.unit || 'pcs' }}</span>
                            </td>
                            <td class="py-3.5">
                                <span :class="getStatusClass(product.status)" class="px-2.5 py-0.5 rounded-full text-xs font-medium border inline-flex items-center">
                                    {{ product.status }}
                                </span>
                            </td>
                            <td class="py-3.5 text-right">
                                <div class="inline-flex items-center gap-1">
                                    <button @click="openViewModal(product)" 
                                            class="p-1.5 text-gray-500 hover:text-gray-900 hover:bg-gray-100 rounded-md transition-colors cursor-pointer"
                                            title="View Product Details & Movement History">
                                        <Eye class="w-4 h-4" />
                                    </button>
                                    <button @click="openStockInModal(product)" 
                                            class="p-1.5 text-gray-500 hover:text-gray-900 hover:bg-gray-100 rounded-md transition-colors cursor-pointer"
                                            title="Quick Stock-In">
                                        <ArrowDownLeft class="w-4 h-4" />
                                    </button>
                                    <button @click="openStockOutModal(product)" 
                                            class="p-1.5 text-gray-500 hover:text-gray-900 hover:bg-gray-100 rounded-md transition-colors cursor-pointer"
                                            title="Quick Stock-Out">
                                        <ArrowUpRight class="w-4 h-4" />
                                    </button>
                                    <button @click="openEditModal(product)" 
                                            class="p-1.5 text-gray-500 hover:text-[#4b5320] hover:bg-[#F8B803]/10 rounded-md transition-colors cursor-pointer"
                                            title="Edit Product">
                                        <Pencil class="w-4 h-4" />
                                    </button>
                                    <button v-if="canDelete" @click="openDeleteModal(product)" 
                                            class="p-1.5 text-gray-400 hover:text-rose-600 hover:bg-rose-50 rounded-md transition-colors cursor-pointer"
                                            title="Archive/Delete Product">
                                        <Trash2 class="w-4 h-4" />
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="products.data.length === 0">
                            <td colspan="8" class="py-14 text-center text-gray-400">
                                <Package class="w-12 h-12 mx-auto mb-2 opacity-30 text-[#4b5320]" />
                                <p class="text-base font-semibold text-gray-700">No products found</p>
                                <p class="text-xs text-gray-400 mt-1">Try clearing your filters or create a new product using the button above.</p>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Pagination Bar -->
            <div class="mt-5 pt-4 border-t border-gray-100 flex flex-col sm:flex-row justify-between items-center gap-3 text-sm text-gray-500">
                <span>
                    Showing <strong class="text-gray-800">{{ products.from || 0 }}</strong> to 
                    <strong class="text-gray-800">{{ products.to || 0 }}</strong> of 
                    <strong class="text-gray-800">{{ products.total }}</strong> products
                </span>
                <div class="flex items-center gap-1.5">
                    <template v-for="(link, idx) in products.links" :key="idx">
                        <button v-if="link.url"
                                @click="router.visit(link.url, { only: ['products', 'filters'] })"
                                :class="[
                                    link.active ? 'bg-[#4b5320] text-white font-semibold' : 'bg-white border border-gray-200 text-gray-700 hover:bg-gray-50',
                                    'px-3 py-1.5 rounded-lg text-xs transition-colors'
                                ]"
                                v-html="link.label">
                        </button>
                        <span v-else-if="link.label.includes('Previous') || link.label.includes('Next')" 
                              class="px-3 py-1.5 text-xs text-gray-400 border border-gray-100 rounded-lg cursor-not-allowed opacity-50"
                              v-html="link.label">
                        </span>
                    </template>
                </div>
            </div>
        </div>

        <!-- ========================================================================= -->
        <!-- VIEW 2: PRODUCT MOVEMENT (AUDIT TRAIL) -->
        <!-- ========================================================================= -->
        <div v-else-if="activeView === 'history'" class="flex-1 rounded-xl bg-white border border-gray-200 p-6 flex flex-col">
            <div class="flex items-center justify-between pb-4 mb-4 border-b border-gray-100">
                <div>
                    <h3 class="text-base font-semibold text-gray-900">Inventory Movement Audit Trail</h3>
                    <p class="text-xs text-gray-500">Chronological history of all stock inflows, deductions, sales, and corrections.</p>
                </div>
                <button @click="router.reload()" class="p-2 text-gray-500 hover:text-gray-800 hover:bg-gray-100 rounded-lg text-xs flex items-center gap-1">
                    <RefreshCw class="w-3.5 h-3.5" />
                    <span>Refresh</span>
                </button>
            </div>

            <div class="overflow-x-auto flex-1">
                <table class="w-full text-left border-collapse text-xs">
                    <thead>
                        <tr class="border-b border-gray-200 text-gray-500">
                            <th class="pb-3 font-semibold">Date & Time</th>
                            <th class="pb-3 font-semibold">SKU / Product</th>
                            <th class="pb-3 font-semibold">Type</th>
                            <th class="pb-3 font-semibold text-center">Qty Change</th>
                            <th class="pb-3 font-semibold text-center">Prev &rarr; New</th>
                            <th class="pb-3 font-semibold">Reference</th>
                            <th class="pb-3 font-semibold">Logged By</th>
                            <th class="pb-3 font-semibold">Reason / Remarks</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <tr v-for="m in filteredMovements" :key="m.id" class="hover:bg-gray-50/60">
                            <td class="py-3 text-gray-500 whitespace-nowrap">{{ formatDate(m.movement_date) }}</td>
                            <td class="py-3">
                                <div class="font-semibold text-gray-900">{{ m.product?.name || 'N/A' }}</div>
                                <div class="flex items-center gap-1.5 mt-0.5 flex-wrap">
                                    <span class="font-mono text-gray-500 text-[11px]">{{ m.product?.sku }}</span>
                                    <span v-if="m.product?.category" class="px-1.5 py-0.2 rounded text-[10px] font-medium bg-gray-100 text-gray-600 border border-gray-200">
                                        {{ m.product?.category }}
                                    </span>
                                    <span v-if="m.product?.status" :class="getStatusClass(m.product?.status)" class="px-1.5 py-0.2 rounded-full text-[10px] font-semibold border">
                                        {{ m.product?.status }}
                                    </span>
                                </div>
                            </td>
                            <td class="py-3">
                                <span class="px-2 py-0.5 rounded text-[11px] font-semibold border" :class="movementBadgeClass(m)">
                                    {{ movementLabel(m) }}
                                </span>
                            </td>
                            <td class="py-3 text-center font-semibold" :class="movementClass(m)">
                                {{ movementChangeText(m) }}
                            </td>
                            <td class="py-3 text-center text-gray-600 font-mono text-[11px]">
                                {{ m.previous_stock }} &rarr; <strong class="text-gray-900">{{ m.new_stock }}</strong>
                            </td>
                            <td class="py-3 font-mono text-gray-700 text-[11px]">{{ m.reference_number || '—' }}</td>
                            <td class="py-3 text-gray-600">{{ m.user?.name || 'System' }}</td>
                            <td class="py-3 text-gray-500">
                                <span class="font-medium text-gray-800 block">{{ m.reason || '—' }}</span>
                                <span class="text-[11px] text-gray-400 block">{{ m.remarks }}</span>
                            </td>
                        </tr>
                        <tr v-if="filteredMovements.length === 0">
                            <td colspan="8" class="py-10 text-center text-gray-400">
                                {{ (props.recent_movements && props.recent_movements.length > 0) ? 'No stock movement records match the selected category and status filters.' : 'No stock movement records logged yet.' }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- ========================================================================= -->
    <!-- VIEW PRODUCT DETAILS MODAL -->
    <!-- ========================================================================= -->
    <div v-if="showViewModal && selectedProduct" class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4 overflow-y-auto">
        <div class="bg-white border border-gray-200 rounded-xl w-full max-w-2xl shadow-xl overflow-hidden flex flex-col max-h-[90vh] animate-in fade-in duration-150">
            <div class="px-6 py-4 border-b border-gray-100 flex justify-between items-center bg-gray-50/50">
                <div class="flex items-center gap-3">
                    <div class="p-2 bg-[#F8B803]/20 text-[#4b5320] rounded-lg">
                        <Package class="w-5 h-5" />
                    </div>
                    <div>
                        <h3 class="text-lg font-semibold text-gray-900">{{ selectedProduct.name }}</h3>
                        <p class="text-xs text-gray-500 font-mono">{{ selectedProduct.sku }} &bull; {{ selectedProduct.category }}</p>
                    </div>
                </div>
                <button @click="showViewModal = false" class="text-gray-400 hover:text-gray-600 p-1 rounded-lg hover:bg-gray-100">
                    <X class="w-5 h-5" />
                </button>
            </div>

            <div class="p-6 overflow-y-auto space-y-6">
                <!-- Metrics Grid -->
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 bg-gray-50/80 p-4 rounded-xl border border-gray-200/80">
                    <div>
                        <span class="text-[11px] text-gray-500 font-semibold block">Current Stock</span>
                        <span class="text-xl font-semibold text-gray-900">{{ selectedProduct.stock }}</span>
                        <span class="text-xs text-gray-400 ml-1">({{ selectedProduct.unit || 'pcs' }})</span>
                    </div>
                    <div>
                        <span class="text-[11px] text-gray-500 font-semibold block">Selling Price</span>
                        <span class="text-xl font-semibold text-gray-900">{{ formatPrice(selectedProduct.price) }}</span>
                    </div>
                    <div>
                        <span class="text-[11px] text-gray-500 font-semibold block">Cost Price</span>
                        <span class="text-xl font-semibold text-gray-700">{{ formatPrice(selectedProduct.cost_price) }}</span>
                    </div>
                    <div>
                        <span class="text-[11px] text-gray-500 font-semibold block">Stock Status</span>
                        <span :class="getStatusClass(selectedProduct.status)" class="px-2 py-0.5 rounded-full text-xs font-semibold border inline-block mt-1">
                            {{ selectedProduct.status }}
                        </span>
                    </div>
                </div>

                <!-- Reorder & Supplier Details -->
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-xs">
                    <div class="border border-gray-200 rounded-xl p-4 space-y-2 bg-white">
                        <h4 class="font-semibold text-gray-900 text-[11px] flex items-center gap-1.5">
                            Replenishment Metrics
                        </h4>
                        <div class="flex justify-between py-1 border-b border-gray-100">
                            <span class="text-gray-500">Reorder Level Threshold:</span>
                            <span class="font-semibold text-gray-800">{{ selectedProduct.reorder_level || selectedProduct.min_stock || 10 }} {{ selectedProduct.unit || 'pcs' }}</span>
                        </div>
                        <div class="flex justify-between py-1 border-b border-gray-100">
                            <span class="text-gray-500">Suggested Reorder Quantity:</span>
                            <span class="font-semibold text-gray-800">{{ selectedProduct.reorder_quantity || 50 }} {{ selectedProduct.unit || 'pcs' }}</span>
                        </div>
                        <div class="flex justify-between py-1">
                            <span class="text-gray-500">Unit of Measurement:</span>
                            <span class="font-semibold text-gray-800">{{ selectedProduct.unit || 'pcs' }}</span>
                        </div>
                    </div>

                    <div class="border border-gray-200 rounded-xl p-4 space-y-2 bg-white">
                        <h4 class="font-semibold text-gray-900 text-[11px] flex items-center gap-1.5">
                            Associated Supplier
                        </h4>
                        <div class="flex justify-between py-1 border-b border-gray-100">
                            <span class="text-gray-500">Supplier Name:</span>
                            <span class="font-semibold text-gray-800">{{ selectedProduct.supplier?.name || 'No assigned supplier' }}</span>
                        </div>
                        <div class="flex justify-between py-1 border-b border-gray-100">
                            <span class="text-gray-500">Contact Person:</span>
                            <span class="font-semibold text-gray-800">{{ selectedProduct.supplier?.contact_person || 'N/A' }}</span>
                        </div>
                        <div class="flex justify-between py-1">
                            <span class="text-gray-500">Contact Phone:</span>
                            <span class="font-semibold text-gray-800">{{ selectedProduct.supplier?.phone || 'N/A' }}</span>
                        </div>
                    </div>
                </div>

                <div v-if="selectedProduct.description" class="border border-gray-200 rounded-xl p-4 bg-gray-50/50 text-xs">
                    <span class="font-semibold text-gray-700 text-[11px] block mb-1">Product Description</span>
                    <p class="text-gray-600 leading-relaxed">{{ selectedProduct.description }}</p>
                </div>

                <!-- Recent Movement History for this product -->
                <div>
                    <h4 class="font-semibold text-gray-900 text-xs mb-3 flex items-center gap-1.5">
                        Recent Stock Movement History
                    </h4>

                    <div v-if="productHistoryLoading" class="py-8 text-center text-gray-400 text-xs">
                        <RefreshCw class="w-5 h-5 animate-spin mx-auto mb-2 text-[#4b5320]" />
                        Loading stock movements...
                    </div>

                    <div v-else-if="productMovements.length > 0" class="border border-gray-200 rounded-xl overflow-hidden max-h-48 overflow-y-auto">
                        <table class="w-full text-left text-xs border-collapse">
                            <thead class="bg-gray-50 text-gray-500 border-b border-gray-200 sticky top-0">
                                <tr>
                                    <th class="p-2 font-semibold">Date</th>
                                    <th class="p-2 font-semibold">Type</th>
                                    <th class="p-2 font-semibold text-center">Qty</th>
                                    <th class="p-2 font-semibold text-center">Prev &rarr; New</th>
                                    <th class="p-2 font-semibold">Reference</th>
                                    <th class="p-2 font-semibold">Reason</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 bg-white">
                                <tr v-for="m in productMovements" :key="m.id" class="hover:bg-gray-50">
                                    <td class="p-2 text-gray-500 whitespace-nowrap">{{ formatDate(m.movement_date) }}</td>
                                    <td class="p-2 font-semibold">{{ movementLabel(m) }}</td>
                                    <td class="p-2 text-center font-semibold" :class="movementClass(m)">
                                        {{ movementChangeText(m) }}
                                    </td>
                                    <td class="p-2 text-center text-gray-600 font-mono text-[11px]">
                                        {{ m.previous_stock }} &rarr; {{ m.new_stock }}
                                    </td>
                                    <td class="p-2 font-mono text-gray-500 text-[11px]">{{ m.reference_number || '—' }}</td>
                                    <td class="p-2 text-gray-700">{{ m.reason || '—' }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div v-else class="text-xs text-gray-400 py-4 text-center border border-dashed rounded-xl">
                        No movement history recorded yet for this item.
                    </div>
                </div>
            </div>

            <div class="px-6 py-4 border-t border-gray-100 bg-gray-50 flex justify-end gap-2">
                <button @click="openEditModal(selectedProduct); showViewModal = false;" class="px-4 py-2 bg-white border border-gray-200 text-gray-700 hover:bg-gray-100 rounded-lg text-xs font-semibold">
                    Edit Product
                </button>
                <button @click="showViewModal = false" class="px-4 py-2 bg-gray-800 text-white hover:bg-gray-900 rounded-lg text-xs font-semibold">
                    Close
                </button>
            </div>
        </div>
    </div>

    <!-- ========================================================================= -->
    <!-- ADD / EDIT PRODUCT MODAL -->
    <!-- ========================================================================= -->
    <div v-if="showAddEditModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4 overflow-y-auto">
        <div class="bg-white border border-gray-200 p-6 rounded-xl w-full max-w-xl shadow-xl animate-in fade-in duration-150 my-8">
            <div class="flex justify-between items-center mb-5 pb-3 border-b border-gray-100">
                <div>
                    <h3 class="text-xl font-semibold text-gray-900">
                        {{ isEditing ? 'Edit Product' : 'Add New Electrical Product' }}
                    </h3>
                    <p class="text-xs text-gray-500">Configure catalog product details, pricing, and replenishment thresholds.</p>
                </div>
                <button @click="showAddEditModal = false" class="text-gray-400 hover:text-gray-600">
                    <X class="w-5 h-5" />
                </button>
            </div>

            <form @submit.prevent="promptSaveProduct" novalidate class="space-y-4">
                <!-- Product Name -->
                <div class="relative">
                    <input id="prod_name"
                           v-model="formProduct.name" 
                           type="text" 
                           placeholder=" " 
                           class="peer block w-full rounded-xl border border-gray-200 bg-white px-3.5 pt-5 pb-2 text-xs text-gray-900 focus:border-[#4b5320] focus:ring-1 focus:ring-[#4b5320] focus:outline-none sm:text-sm" 
                           required />
                    <label for="prod_name"
                           class="pointer-events-none absolute top-3.5 left-3.5 origin-[0] -translate-y-2.5 scale-75 transform text-xs font-semibold text-gray-500 duration-200 peer-placeholder-shown:translate-y-0 peer-placeholder-shown:scale-100 peer-focus:-translate-y-2.5 peer-focus:scale-75 peer-focus:text-[#4b5320]"
                           :class="formProduct.name ? '-translate-y-2.5 scale-75 text-[#4b5320]' : ''">
                        Product Name
                    </label>
                    <span v-if="formErrors.name" class="text-rose-600 text-xs mt-1 block">{{ formErrors.name }}</span>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <!-- SKU: generated from the category by the server; read-only, can be copied -->
                    <div>
                        <div class="relative">
                            <input id="prod_sku"
                                   :value="skuLoading ? '' : formProduct.sku"
                                   type="text"
                                   readonly
                                   :placeholder="skuLoading ? 'Generating…' : ' '"
                                   aria-describedby="prod_sku_hint"
                                   class="block w-full rounded-xl border border-gray-200 bg-gray-50 px-3.5 pt-5 pb-2 pr-16 text-xs font-mono text-gray-900 focus:outline-none sm:text-sm" />
                            <label for="prod_sku" class="pointer-events-none absolute top-3.5 left-3.5 origin-[0] -translate-y-2.5 scale-75 transform text-xs font-semibold text-[#4b5320]">SKU</label>
                            <button type="button" class="absolute top-1/2 right-2 -translate-y-1/2 rounded-lg px-2 py-1 text-xs font-medium text-[#4b5320] hover:bg-gray-100 disabled:text-gray-300"
                                    :disabled="!formProduct.sku || skuLoading" :aria-label="`Copy SKU ${formProduct.sku}`" @click="copySku">
                                {{ skuCopied ? 'Copied' : 'Copy' }}
                            </button>
                        </div>
                        <p id="prod_sku_hint" class="mt-1 text-[11px] text-gray-500">{{ isEditing ? 'Does not change with the category.' : 'Next for this category; assigned when saved.' }}</p>
                        <span v-if="formErrors.sku" class="text-rose-600 text-xs mt-1 block">{{ formErrors.sku }}</span>
                    </div>

                    <!-- Category -->
                    <div class="relative">
                        <select id="prod_category"
                                v-model="formProduct.category" 
                                class="peer block w-full rounded-xl border border-gray-200 bg-white px-3.5 pt-5 pb-2 text-xs text-gray-900 focus:border-[#4b5320] focus:ring-1 focus:ring-[#4b5320] focus:outline-none sm:text-sm">
                            <option v-for="cat in categories" :key="cat" :value="cat">{{ cat }}</option>
                        </select>
                        <label for="prod_category"
                               class="pointer-events-none absolute top-3.5 left-3.5 origin-[0] -translate-y-2.5 scale-75 transform text-xs font-semibold text-[#4b5320]">
                            Category
                        </label>
                        <span v-if="formErrors.category" class="text-rose-600 text-xs mt-1 block">{{ formErrors.category }}</span>
                    </div>
                </div>

                <!-- Brand (optional; feeds the public quotation brand list) -->
                <div class="relative">
                    <input id="prod_brand"
                           v-model="formProduct.brand"
                           type="text"
                           maxlength="100"
                           placeholder=" "
                           class="peer block w-full rounded-xl border border-gray-200 bg-white px-3.5 pt-5 pb-2 text-xs text-gray-900 focus:border-[#4b5320] focus:ring-1 focus:ring-[#4b5320] focus:outline-none sm:text-sm" />
                    <label for="prod_brand"
                           class="pointer-events-none absolute top-3.5 left-3.5 origin-[0] -translate-y-2.5 scale-75 transform text-xs font-semibold text-gray-500 duration-200 peer-placeholder-shown:translate-y-0 peer-placeholder-shown:scale-100 peer-focus:-translate-y-2.5 peer-focus:scale-75 peer-focus:text-[#4b5320]"
                           :class="formProduct.brand ? '-translate-y-2.5 scale-75 text-[#4b5320]' : ''">
                        Brand (optional)
                    </label>
                    <span v-if="formErrors.brand" class="text-rose-600 text-xs mt-1 block">{{ formErrors.brand }}</span>
                </div>

                <div class="grid grid-cols-3 gap-3">
                    <!-- Unit of Measure -->
                    <div class="relative">
                        <input id="prod_unit"
                               v-model="formProduct.unit" 
                               type="text" 
                               placeholder=" " 
                               class="peer block w-full rounded-xl border border-gray-200 bg-white px-3.5 pt-5 pb-2 text-xs text-gray-900 focus:border-[#4b5320] focus:ring-1 focus:ring-[#4b5320] focus:outline-none sm:text-sm" />
                        <label for="prod_unit"
                               class="pointer-events-none absolute top-3.5 left-3.5 origin-[0] -translate-y-2.5 scale-75 transform text-xs font-semibold text-gray-500 duration-200 peer-placeholder-shown:translate-y-0 peer-placeholder-shown:scale-100 peer-focus:-translate-y-2.5 peer-focus:scale-75 peer-focus:text-[#4b5320]"
                               :class="formProduct.unit ? '-translate-y-2.5 scale-75 text-[#4b5320]' : ''">
                            Unit of Measure
                        </label>
                    </div>

                    <!-- Cost Price -->
                    <div class="relative">
                        <input id="prod_cost_price"
                               v-model.number="formProduct.cost_price" 
                               type="number" 
                               step="0.01" 
                               min="0"
                               placeholder=" "
                               class="peer block w-full rounded-xl border border-gray-200 bg-white px-3.5 pt-5 pb-2 text-xs text-gray-900 focus:border-[#4b5320] focus:ring-1 focus:ring-[#4b5320] focus:outline-none sm:text-sm" />
                        <label for="prod_cost_price"
                               class="pointer-events-none absolute top-3.5 left-3.5 origin-[0] -translate-y-2.5 scale-75 transform text-xs font-semibold text-gray-500 duration-200 peer-placeholder-shown:translate-y-0 peer-placeholder-shown:scale-100 peer-focus:-translate-y-2.5 peer-focus:scale-75 peer-focus:text-[#4b5320]"
                               :class="hasNumVal(formProduct.cost_price) ? '-translate-y-2.5 scale-75 text-[#4b5320]' : ''">
                            Cost Price (USD)
                        </label>
                    </div>

                    <!-- Selling Price -->
                    <div class="relative">
                        <input id="prod_price"
                               v-model.number="formProduct.price" 
                               type="number" 
                               step="0.01" 
                               min="0"
                               placeholder=" "
                               class="peer block w-full rounded-xl border border-gray-200 bg-white px-3.5 pt-5 pb-2 text-xs text-gray-900 focus:border-[#4b5320] focus:ring-1 focus:ring-[#4b5320] focus:outline-none sm:text-sm" 
                               required />
                        <label for="prod_price"
                               class="pointer-events-none absolute top-3.5 left-3.5 origin-[0] -translate-y-2.5 scale-75 transform text-xs font-semibold text-gray-500 duration-200 peer-placeholder-shown:translate-y-0 peer-placeholder-shown:scale-100 peer-focus:-translate-y-2.5 peer-focus:scale-75 peer-focus:text-[#4b5320]"
                               :class="hasNumVal(formProduct.price) ? '-translate-y-2.5 scale-75 text-[#4b5320]' : ''">
                            Selling Price (USD)
                        </label>
                        <span v-if="formErrors.price" class="text-rose-600 text-xs mt-1 block">{{ formErrors.price }}</span>
                    </div>
                </div>

                <div class="grid grid-cols-3 gap-3">
                    <!-- Initial Stock -->
                    <div v-if="!isEditing" class="relative">
                        <input id="prod_stock"
                               v-model.number="formProduct.stock" 
                               type="number" 
                               min="0" 
                               placeholder=" "
                               class="peer block w-full rounded-xl border border-gray-200 bg-white px-3.5 pt-5 pb-2 text-xs text-gray-900 focus:border-[#4b5320] focus:ring-1 focus:ring-[#4b5320] focus:outline-none sm:text-sm" 
                               required />
                        <label for="prod_stock"
                               class="pointer-events-none absolute top-3.5 left-3.5 origin-[0] -translate-y-2.5 scale-75 transform text-xs font-semibold text-gray-500 duration-200 peer-placeholder-shown:translate-y-0 peer-placeholder-shown:scale-100 peer-focus:-translate-y-2.5 peer-focus:scale-75 peer-focus:text-[#4b5320]"
                               :class="hasNumVal(formProduct.stock) ? '-translate-y-2.5 scale-75 text-[#4b5320]' : ''">
                            Initial Stock
                        </label>
                        <span v-if="formErrors.stock" class="text-rose-600 text-xs mt-1 block">{{ formErrors.stock }}</span>
                    </div>

                    <!-- Reorder Level -->
                    <div class="relative">
                        <input id="prod_reorder_level"
                               v-model.number="formProduct.reorder_level" 
                               type="number" 
                               min="0" 
                               placeholder=" "
                               class="peer block w-full rounded-xl border border-gray-200 bg-white px-3.5 pt-5 pb-2 text-xs text-gray-900 focus:border-[#4b5320] focus:ring-1 focus:ring-[#4b5320] focus:outline-none sm:text-sm" 
                               required />
                        <label for="prod_reorder_level"
                               class="pointer-events-none absolute top-3.5 left-3.5 origin-[0] -translate-y-2.5 scale-75 transform text-xs font-semibold text-gray-500 duration-200 peer-placeholder-shown:translate-y-0 peer-placeholder-shown:scale-100 peer-focus:-translate-y-2.5 peer-focus:scale-75 peer-focus:text-[#4b5320]"
                               :class="hasNumVal(formProduct.reorder_level) ? '-translate-y-2.5 scale-75 text-[#4b5320]' : ''">
                            Reorder Level
                        </label>
                        <span class="text-[10px] text-gray-400 mt-1 block">Trigger Low Stock status</span>
                    </div>

                    <!-- Reorder Quantity -->
                    <div class="relative">
                        <input id="prod_reorder_quantity"
                               v-model.number="formProduct.reorder_quantity" 
                               type="number" 
                               min="0" 
                               placeholder=" "
                               class="peer block w-full rounded-xl border border-gray-200 bg-white px-3.5 pt-5 pb-2 text-xs text-gray-900 focus:border-[#4b5320] focus:ring-1 focus:ring-[#4b5320] focus:outline-none sm:text-sm" />
                        <label for="prod_reorder_quantity"
                               class="pointer-events-none absolute top-3.5 left-3.5 origin-[0] -translate-y-2.5 scale-75 transform text-xs font-semibold text-gray-500 duration-200 peer-placeholder-shown:translate-y-0 peer-placeholder-shown:scale-100 peer-focus:-translate-y-2.5 peer-focus:scale-75 peer-focus:text-[#4b5320]"
                               :class="hasNumVal(formProduct.reorder_quantity) ? '-translate-y-2.5 scale-75 text-[#4b5320]' : ''">
                            Reorder Quantity
                        </label>
                        <span class="text-[10px] text-gray-400 mt-1 block">Suggested order batch</span>
                    </div>
                </div>

                <div class="grid grid-cols-1 gap-4">
                    <!-- Supplier -->
                    <div class="relative">
                        <select id="prod_supplier"
                                v-model="formProduct.supplier_id" 
                                class="peer block w-full rounded-xl border border-gray-200 bg-white px-3.5 pt-5 pb-2 text-xs text-gray-900 focus:border-[#4b5320] focus:ring-1 focus:ring-[#4b5320] focus:outline-none sm:text-sm">
                            <option :value="null">-- No Supplier Assigned --</option>
                            <option v-for="sup in suppliers" :key="sup.id" :value="sup.id">
                                {{ sup.name }} {{ sup.contact_person ? `(${sup.contact_person})` : '' }}
                            </option>
                        </select>
                        <label for="prod_supplier"
                               class="pointer-events-none absolute top-3.5 left-3.5 origin-[0] -translate-y-2.5 scale-75 transform text-xs font-semibold text-[#4b5320]">
                            Supplier
                        </label>
                    </div>

                    <!-- Description -->
                    <div class="relative">
                        <textarea id="prod_description"
                                  v-model="formProduct.description" 
                                  rows="2" 
                                  placeholder=" " 
                                  class="peer block w-full rounded-xl border border-gray-200 bg-white px-3.5 pt-5 pb-2 text-xs text-gray-900 focus:border-[#4b5320] focus:ring-1 focus:ring-[#4b5320] focus:outline-none sm:text-sm"></textarea>
                        <label for="prod_description"
                               class="pointer-events-none absolute top-3.5 left-3.5 origin-[0] -translate-y-2.5 scale-75 transform text-xs font-semibold text-gray-500 duration-200 peer-placeholder-shown:translate-y-0 peer-placeholder-shown:scale-100 peer-focus:-translate-y-2.5 peer-focus:scale-75 peer-focus:text-[#4b5320]"
                               :class="formProduct.description ? '-translate-y-2.5 scale-75 text-[#4b5320]' : ''">
                            Description / Technical Notes
                        </label>
                    </div>
                </div>

                <div class="flex justify-end gap-3 pt-4 border-t border-gray-100">
                    <SpecularButton
                        type="button"
                        variant="secondary"
                        size="md"
                        :radius="10"
                        @click="showAddEditModal = false"
                    >
                        Cancel
                    </SpecularButton>
                    <SpecularButton
                        type="button"
                        variant="brand"
                        size="md"
                        :radius="10"
                        :disabled="isSubmitting"
                        @click="promptSaveProduct"
                    >
                        <RefreshCw v-if="isSubmitting" class="w-4 h-4 animate-spin" />
                        <Check v-else class="w-4 h-4" />
                        <span>{{ isSubmitting ? 'Saving...' : (isEditing ? 'Update Product' : 'Save Product') }}</span>
                    </SpecularButton>
                </div>
            </form>
        </div>
    </div>

    <!-- ========================================================================= -->
    <!-- STOCK-IN MODAL -->
    <!-- ========================================================================= -->
    <div v-if="showStockInModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4 overflow-y-auto">
        <div class="bg-white border border-gray-200 p-6 rounded-xl w-full max-w-lg shadow-xl animate-in fade-in duration-150">
            <div class="flex justify-between items-center mb-4 pb-3 border-b border-gray-100">
                <div class="flex items-center gap-2.5">
                    <div class="p-2 bg-emerald-50 text-emerald-700 rounded-lg">
                        <ArrowDownLeft class="w-5 h-5" />
                    </div>
                    <div>
                        <h3 class="text-lg font-semibold text-gray-900">Record Stock-In Inflow</h3>
                        <p class="text-xs text-gray-500">Only for stock outside a purchase order. Supplier deliveries are added automatically when inspected.</p>
                    </div>
                </div>
                <button @click="showStockInModal = false" class="text-gray-400 hover:text-gray-600">
                    <X class="w-5 h-5" />
                </button>
            </div>

            <form @submit.prevent="promptStockIn" class="space-y-4 text-xs">
                <!-- Target Product -->
                <div class="relative">
                    <select id="stockin_product"
                            v-model="formStockIn.product_id" 
                            class="peer block w-full rounded-xl border border-gray-200 bg-white px-3.5 pt-5 pb-2 text-xs text-gray-900 focus:border-[#4b5320] focus:ring-1 focus:ring-[#4b5320] focus:outline-none sm:text-sm" required>
                        <option v-for="p in products.data" :key="p.id" :value="p.id">
                            {{ p.sku }} &mdash; {{ p.name }} (Current: {{ p.stock }} {{ p.unit || 'pcs' }})
                        </option>
                    </select>
                    <label for="stockin_product"
                           class="pointer-events-none absolute top-3.5 left-3.5 origin-[0] -translate-y-2.5 scale-75 transform text-xs font-semibold text-[#4b5320]">
                        Target Product
                    </label>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <!-- Incoming Quantity -->
                    <div class="relative">
                        <input id="stockin_qty"
                               v-model.number="formStockIn.quantity" 
                               type="number" 
                               min="1" 
                               placeholder=" "
                               class="peer block w-full rounded-xl border border-gray-200 bg-white px-3.5 pt-5 pb-2 text-xs text-gray-900 focus:border-[#4b5320] focus:ring-1 focus:ring-[#4b5320] focus:outline-none font-semibold sm:text-sm" 
                               required />
                        <label for="stockin_qty"
                               class="pointer-events-none absolute top-3.5 left-3.5 origin-[0] -translate-y-2.5 scale-75 transform text-xs font-semibold text-gray-500 duration-200 peer-placeholder-shown:translate-y-0 peer-placeholder-shown:scale-100 peer-focus:-translate-y-2.5 peer-focus:scale-75 peer-focus:text-[#4b5320]"
                               :class="hasNumVal(formStockIn.quantity) ? '-translate-y-2.5 scale-75 text-[#4b5320]' : ''">
                            Incoming Quantity
                        </label>
                        <span v-if="formErrors.quantity" class="text-rose-600 text-xs mt-1 block">{{ formErrors.quantity }}</span>
                    </div>

                    <!-- Reference Number -->
                    <div class="relative">
                        <input id="stockin_ref"
                               v-model="formStockIn.reference_number" 
                               type="text" 
                               placeholder=" " 
                               class="peer block w-full rounded-xl border border-gray-200 bg-white px-3.5 pt-5 pb-2 text-xs font-mono text-gray-900 focus:border-[#4b5320] focus:ring-1 focus:ring-[#4b5320] focus:outline-none sm:text-sm" />
                        <label for="stockin_ref"
                               class="pointer-events-none absolute top-3.5 left-3.5 origin-[0] -translate-y-2.5 scale-75 transform text-xs font-semibold text-gray-500 duration-200 peer-placeholder-shown:translate-y-0 peer-placeholder-shown:scale-100 peer-focus:-translate-y-2.5 peer-focus:scale-75 peer-focus:text-[#4b5320]"
                               :class="formStockIn.reference_number ? '-translate-y-2.5 scale-75 text-[#4b5320]' : ''">
                            Reference Number
                        </label>
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <!-- Supplier -->
                    <div class="relative">
                        <select id="stockin_supplier"
                                v-model="formStockIn.supplier_id" 
                                class="peer block w-full rounded-xl border border-gray-200 bg-white px-3.5 pt-5 pb-2 text-xs text-gray-900 focus:border-[#4b5320] focus:ring-1 focus:ring-[#4b5320] focus:outline-none sm:text-sm">
                            <option :value="null">-- Standard Restock --</option>
                            <option v-for="s in suppliers" :key="s.id" :value="s.id">{{ s.name }}</option>
                        </select>
                        <label for="stockin_supplier"
                               class="pointer-events-none absolute top-3.5 left-3.5 origin-[0] -translate-y-2.5 scale-75 transform text-xs font-semibold text-[#4b5320]">
                            Supplier
                        </label>
                    </div>

                    <!-- Date Received -->
                    <div class="relative">
                        <input id="stockin_date"
                               v-model="formStockIn.movement_date" 
                               type="date" 
                               class="peer block w-full rounded-xl border border-gray-200 bg-white px-3.5 pt-5 pb-2 text-xs text-gray-900 focus:border-[#4b5320] focus:ring-1 focus:ring-[#4b5320] focus:outline-none sm:text-sm" />
                        <label for="stockin_date"
                               class="pointer-events-none absolute top-3.5 left-3.5 origin-[0] -translate-y-2.5 scale-75 transform text-xs font-semibold text-[#4b5320]">
                            Date Received
                        </label>
                    </div>
                </div>

                <!-- Remarks -->
                <div class="relative">
                    <input id="stockin_remarks"
                           v-model="formStockIn.remarks" 
                           type="text" 
                           placeholder=" " 
                           class="peer block w-full rounded-xl border border-gray-200 bg-white px-3.5 pt-5 pb-2 text-xs text-gray-900 focus:border-[#4b5320] focus:ring-1 focus:ring-[#4b5320] focus:outline-none sm:text-sm" />
                    <label for="stockin_remarks"
                           class="pointer-events-none absolute top-3.5 left-3.5 origin-[0] -translate-y-2.5 scale-75 transform text-xs font-semibold text-gray-500 duration-200 peer-placeholder-shown:translate-y-0 peer-placeholder-shown:scale-100 peer-focus:-translate-y-2.5 peer-focus:scale-75 peer-focus:text-[#4b5320]"
                           :class="formStockIn.remarks ? '-translate-y-2.5 scale-75 text-[#4b5320]' : ''">
                        Remarks / Note
                    </label>
                </div>

                <div class="flex justify-end gap-2 pt-3 border-t border-gray-100">
                    <SpecularButton
                        type="button"
                        variant="secondary"
                        size="md"
                        :radius="10"
                        @click="showStockInModal = false"
                    >
                        Cancel
                    </SpecularButton>
                    <SpecularButton
                        type="submit"
                        variant="success"
                        size="md"
                        :radius="10"
                        :disabled="isSubmitting"
                    >
                        Record Stock In
                    </SpecularButton>
                </div>
            </form>
        </div>
    </div>

    <!-- ========================================================================= -->
    <!-- STOCK-OUT MODAL -->
    <!-- ========================================================================= -->
    <div v-if="showStockOutModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4 overflow-y-auto">
        <div class="bg-white border border-gray-200 p-6 rounded-xl w-full max-w-lg shadow-xl animate-in fade-in duration-150">
            <div class="flex justify-between items-center mb-4 pb-3 border-b border-gray-100">
                <div class="flex items-center gap-2.5">
                    <div class="p-2 bg-amber-50 text-amber-700 rounded-lg">
                        <ArrowUpRight class="w-5 h-5" />
                    </div>
                    <div>
                        <h3 class="text-lg font-semibold text-gray-900">Record Stock-Out Deduction</h3>
                        <p class="text-xs text-gray-500">Only for stock leaving outside a sales order. Customer deliveries are deducted automatically when delivered.</p>
                    </div>
                </div>
                <button @click="showStockOutModal = false" class="text-gray-400 hover:text-gray-600">
                    <X class="w-5 h-5" />
                </button>
            </div>

            <form @submit.prevent="promptStockOut" class="space-y-4 text-xs">
                <!-- Target Product -->
                <div class="relative">
                    <select id="stockout_product"
                            v-model="formStockOut.product_id" 
                            class="peer block w-full rounded-xl border border-gray-200 bg-white px-3.5 pt-5 pb-2 text-xs text-gray-900 focus:border-[#4b5320] focus:ring-1 focus:ring-[#4b5320] focus:outline-none sm:text-sm" required>
                        <option v-for="p in products.data" :key="p.id" :value="p.id">
                            {{ p.sku }} &mdash; {{ p.name }} (Available: {{ p.stock }} {{ p.unit || 'pcs' }})
                        </option>
                    </select>
                    <label for="stockout_product"
                           class="pointer-events-none absolute top-3.5 left-3.5 origin-[0] -translate-y-2.5 scale-75 transform text-xs font-semibold text-[#4b5320]">
                        Target Product
                    </label>
                </div>

                <div v-if="currentSelectedStockOutProduct" class="p-2.5 bg-gray-50 rounded-lg text-xs flex justify-between items-center">
                    <span class="text-gray-600">Available Stock:</span>
                    <strong :class="currentSelectedStockOutProduct.stock === 0 ? 'text-rose-600' : 'text-emerald-700'" class="font-semibold text-sm">
                        {{ currentSelectedStockOutProduct.stock }} {{ currentSelectedStockOutProduct.unit || 'pcs' }}
                    </strong>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <!-- Deduction Quantity -->
                    <div class="relative">
                        <input id="stockout_qty"
                               v-model.number="formStockOut.quantity" 
                               type="number" 
                               min="1" 
                               :max="currentSelectedStockOutProduct?.stock || 99999"
                               placeholder=" "
                               class="peer block w-full rounded-xl border border-gray-200 bg-white px-3.5 pt-5 pb-2 text-xs text-gray-900 focus:border-[#4b5320] focus:ring-1 focus:ring-[#4b5320] focus:outline-none font-semibold sm:text-sm" 
                               required />
                        <label for="stockout_qty"
                               class="pointer-events-none absolute top-3.5 left-3.5 origin-[0] -translate-y-2.5 scale-75 transform text-xs font-semibold text-gray-500 duration-200 peer-placeholder-shown:translate-y-0 peer-placeholder-shown:scale-100 peer-focus:-translate-y-2.5 peer-focus:scale-75 peer-focus:text-[#4b5320]"
                               :class="hasNumVal(formStockOut.quantity) ? '-translate-y-2.5 scale-75 text-[#4b5320]' : ''">
                            Deduction Quantity
                        </label>
                        <span v-if="formErrors.quantity" class="text-rose-600 text-xs mt-1 block">{{ formErrors.quantity }}</span>
                        <span v-else-if="currentSelectedStockOutProduct && formStockOut.quantity > currentSelectedStockOutProduct.stock" class="text-rose-600 text-xs mt-1 block">
                            Insufficient stock. Available: {{ currentSelectedStockOutProduct.stock }}
                        </span>
                    </div>

                    <!-- Reason for Deduction -->
                    <div class="relative">
                        <select id="stockout_reason"
                                v-model="formStockOut.reason" 
                                class="peer block w-full rounded-xl border border-gray-200 bg-white px-3.5 pt-5 pb-2 text-xs text-gray-900 focus:border-[#4b5320] focus:ring-1 focus:ring-[#4b5320] focus:outline-none sm:text-sm" required>
                            <option>Internal Use</option>
                            <option>Damaged / Write-off</option>
                            <option>Returned to Supplier</option>
                            <option>Sample / Demo Transfer</option>
                        </select>
                        <label for="stockout_reason"
                               class="pointer-events-none absolute top-3.5 left-3.5 origin-[0] -translate-y-2.5 scale-75 transform text-xs font-semibold text-[#4b5320]">
                            Reason for Deduction
                        </label>
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <!-- Reference Number -->
                    <div class="relative">
                        <input id="stockout_ref"
                               v-model="formStockOut.reference_number" 
                               type="text" 
                               placeholder=" " 
                               class="peer block w-full rounded-xl border border-gray-200 bg-white px-3.5 pt-5 pb-2 text-xs font-mono text-gray-900 focus:border-[#4b5320] focus:ring-1 focus:ring-[#4b5320] focus:outline-none sm:text-sm" />
                        <label for="stockout_ref"
                               class="pointer-events-none absolute top-3.5 left-3.5 origin-[0] -translate-y-2.5 scale-75 transform text-xs font-semibold text-gray-500 duration-200 peer-placeholder-shown:translate-y-0 peer-placeholder-shown:scale-100 peer-focus:-translate-y-2.5 peer-focus:scale-75 peer-focus:text-[#4b5320]"
                               :class="formStockOut.reference_number ? '-translate-y-2.5 scale-75 text-[#4b5320]' : ''">
                            Reference Number
                        </label>
                    </div>

                    <!-- Date -->
                    <div class="relative">
                        <input id="stockout_date"
                               v-model="formStockOut.movement_date" 
                               type="date" 
                               class="peer block w-full rounded-xl border border-gray-200 bg-white px-3.5 pt-5 pb-2 text-xs text-gray-900 focus:border-[#4b5320] focus:ring-1 focus:ring-[#4b5320] focus:outline-none sm:text-sm" />
                        <label for="stockout_date"
                               class="pointer-events-none absolute top-3.5 left-3.5 origin-[0] -translate-y-2.5 scale-75 transform text-xs font-semibold text-[#4b5320]">
                            Date
                        </label>
                    </div>
                </div>

                <!-- Remarks -->
                <div class="relative">
                    <input id="stockout_remarks"
                           v-model="formStockOut.remarks" 
                           type="text" 
                           placeholder=" " 
                           class="peer block w-full rounded-xl border border-gray-200 bg-white px-3.5 pt-5 pb-2 text-xs text-gray-900 focus:border-[#4b5320] focus:ring-1 focus:ring-[#4b5320] focus:outline-none sm:text-sm" />
                    <label for="stockout_remarks"
                           class="pointer-events-none absolute top-3.5 left-3.5 origin-[0] -translate-y-2.5 scale-75 transform text-xs font-semibold text-gray-500 duration-200 peer-placeholder-shown:translate-y-0 peer-placeholder-shown:scale-100 peer-focus:-translate-y-2.5 peer-focus:scale-75 peer-focus:text-[#4b5320]"
                           :class="formStockOut.remarks ? '-translate-y-2.5 scale-75 text-[#4b5320]' : ''">
                        Remarks
                    </label>
                </div>

                <div class="flex justify-end gap-2 pt-3 border-t border-gray-100">
                    <SpecularButton
                        type="button"
                        variant="secondary"
                        size="md"
                        :radius="10"
                        @click="showStockOutModal = false"
                    >
                        Cancel
                    </SpecularButton>
                    <SpecularButton
                        type="submit"
                        variant="brand-gold"
                        size="md"
                        :radius="10"
                        :disabled="isSubmitting || (currentSelectedStockOutProduct && formStockOut.quantity > currentSelectedStockOutProduct.stock)"
                    >
                        Record Stock Out
                    </SpecularButton>
                </div>
            </form>
        </div>
    </div>

    <!-- ========================================================================= -->
    <!-- STOCK ADJUSTMENT MODAL -->
    <!-- ========================================================================= -->
    <div v-if="showAdjustModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4 overflow-y-auto">
        <div class="bg-white border border-gray-200 p-6 rounded-xl w-full max-w-lg shadow-xl animate-in fade-in duration-150">
            <div class="flex justify-between items-center mb-4 pb-3 border-b border-gray-100">
                <div class="flex items-center gap-2.5">
                    <div class="p-2 bg-purple-50 text-purple-700 rounded-lg">
                        <Sliders class="w-5 h-5" />
                    </div>
                    <div>
                        <h3 class="text-lg font-semibold text-gray-900">Physical Stock Count Adjustment</h3>
                        <p class="text-xs text-gray-500">Correct physical inventory discrepancies, damaged units, or write-offs.</p>
                    </div>
                </div>
                <button @click="showAdjustModal = false" class="text-gray-400 hover:text-gray-600">
                    <X class="w-5 h-5" />
                </button>
            </div>

            <form @submit.prevent="promptAdjust" class="space-y-4 text-xs">
                <!-- Target Product -->
                <div class="relative">
                    <select id="adjust_product"
                            v-model="formAdjust.product_id" 
                            class="peer block w-full rounded-xl border border-gray-200 bg-white px-3.5 pt-5 pb-2 text-xs text-gray-900 focus:border-[#4b5320] focus:ring-1 focus:ring-[#4b5320] focus:outline-none sm:text-sm" required>
                        <option v-for="p in products.data" :key="p.id" :value="p.id">
                            {{ p.sku }} &mdash; {{ p.name }} (System Stock: {{ p.stock }} {{ p.unit || 'pcs' }})
                        </option>
                    </select>
                    <label for="adjust_product"
                           class="pointer-events-none absolute top-3.5 left-3.5 origin-[0] -translate-y-2.5 scale-75 transform text-xs font-semibold text-[#4b5320]">
                        Target Product
                    </label>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <!-- Actual Physical Count -->
                    <div class="relative">
                        <input id="adjust_physical_count"
                               v-model.number="formAdjust.physical_count" 
                               type="number" 
                               min="0" 
                               placeholder=" "
                               class="peer block w-full rounded-xl border border-gray-200 bg-white px-3.5 pt-5 pb-2 text-xs text-gray-900 focus:border-[#4b5320] focus:ring-1 focus:ring-[#4b5320] focus:outline-none font-semibold sm:text-sm" 
                               required />
                        <label for="adjust_physical_count"
                               class="pointer-events-none absolute top-3.5 left-3.5 origin-[0] -translate-y-2.5 scale-75 transform text-xs font-semibold text-gray-500 duration-200 peer-placeholder-shown:translate-y-0 peer-placeholder-shown:scale-100 peer-focus:-translate-y-2.5 peer-focus:scale-75 peer-focus:text-[#4b5320]"
                               :class="hasNumVal(formAdjust.physical_count) ? '-translate-y-2.5 scale-75 text-[#4b5320]' : ''">
                            Actual Physical Count
                        </label>
                        <span v-if="formErrors.physical_count" class="text-rose-600 text-xs mt-1 block">{{ formErrors.physical_count }}</span>
                    </div>

                    <!-- Adjustment Reason -->
                    <div class="relative">
                        <select id="adjust_reason"
                                v-model="formAdjust.reason" 
                                class="peer block w-full rounded-xl border border-gray-200 bg-white px-3.5 pt-5 pb-2 text-xs text-gray-900 focus:border-[#4b5320] focus:ring-1 focus:ring-[#4b5320] focus:outline-none sm:text-sm" required>
                            <option>Physical Count Correction</option>
                            <option>Damaged</option>
                            <option>Lost</option>
                            <option>Data Correction</option>
                        </select>
                        <label for="adjust_reason"
                               class="pointer-events-none absolute top-3.5 left-3.5 origin-[0] -translate-y-2.5 scale-75 transform text-xs font-semibold text-[#4b5320]">
                            Adjustment Reason
                        </label>
                    </div>
                </div>

                <!-- Reason / Audit Remark -->
                <div class="relative">
                    <input id="adjust_remarks"
                           v-model="formAdjust.remarks" 
                           type="text" 
                           placeholder=" " 
                           class="peer block w-full rounded-xl border border-gray-200 bg-white px-3.5 pt-5 pb-2 text-xs text-gray-900 focus:border-[#4b5320] focus:ring-1 focus:ring-[#4b5320] focus:outline-none sm:text-sm" 
                           required />
                    <label for="adjust_remarks"
                           class="pointer-events-none absolute top-3.5 left-3.5 origin-[0] -translate-y-2.5 scale-75 transform text-xs font-semibold text-gray-500 duration-200 peer-placeholder-shown:translate-y-0 peer-placeholder-shown:scale-100 peer-focus:-translate-y-2.5 peer-focus:scale-75 peer-focus:text-[#4b5320]"
                           :class="formAdjust.remarks ? '-translate-y-2.5 scale-75 text-[#4b5320]' : ''">
                        Reason / Audit Remark
                    </label>
                </div>

                <div class="flex justify-end gap-2 pt-3 border-t border-gray-100">
                    <SpecularButton
                        type="button"
                        variant="secondary"
                        size="md"
                        :radius="10"
                        @click="showAdjustModal = false"
                    >
                        Cancel
                    </SpecularButton>
                    <SpecularButton
                        type="submit"
                        variant="brand"
                        size="md"
                        :radius="10"
                        :disabled="isSubmitting"
                    >
                        Commit Adjustment
                    </SpecularButton>
                </div>
            </form>
        </div>
    </div>

    <ImportWizard :open="showImportModal" module="inventory" @close="showImportModal = false" @completed="router.reload({ only: ['products', 'summary'] })" />

    <!-- ========================================================================= -->
    <!-- CONFIRM DELETE / ARCHIVE MODAL -->
    <!-- ========================================================================= -->
    <div v-if="showDeleteModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
        <div class="bg-white border border-gray-200 p-6 rounded-xl w-full max-w-md shadow-xl animate-in fade-in duration-150">
            <div class="w-12 h-12 rounded-full bg-rose-50 text-rose-600 flex items-center justify-center mb-4">
                <Trash2 class="w-6 h-6" />
            </div>
            <h3 class="text-xl font-semibold text-gray-900 mb-2">Are you sure you want to permanently delete this record?</h3>
            <p class="text-gray-600 text-sm mb-2 leading-relaxed">
                This action cannot be undone. Related active sessions, registered trusted devices, or dependent records must only be revoked or removed when explicitly required by the operation.
            </p>
            <p class="text-gray-600 text-xs mb-4 leading-relaxed">
                Product: <strong class="text-gray-900">"{{ selectedProduct?.name }}"</strong> (SKU: {{ selectedProduct?.sku }}). If this item is referenced by quotations, stock movements, or historical sales, it will be safely archived without corrupting transaction records.
            </p>
            <div class="mt-4 mb-6">
                <label class="block text-xs font-semibold text-gray-700 mb-1.5">
                    Type <span class="font-mono font-semibold text-rose-600 bg-rose-50 px-1.5 py-0.5 rounded border border-rose-200">YES</span> to confirm permanent deletion:
                </label>
                <input
                    v-model="deleteConfirmInput"
                    type="text"
                    placeholder="Type YES here"
                    class="w-full px-3 py-2 text-sm font-mono rounded-xl border border-gray-300 focus:outline-none focus:border-rose-500 focus:ring-1 focus:ring-rose-500"
                    @keyup.enter="deleteConfirmInput === 'YES' && deleteProduct()"
                />
            </div>
            <div class="flex justify-end gap-3">
                <SpecularButton
                    type="button"
                    variant="secondary"
                    size="md"
                    :radius="10"
                    @click="showDeleteModal = false"
                >
                    Cancel
                </SpecularButton>
                <SpecularButton
                    type="button"
                    variant="destructive"
                    size="md"
                    :radius="10"
                    @click="deleteProduct"
                    :disabled="isSubmitting || deleteConfirmInput !== 'YES'"
                >
                    Confirm Removal
                </SpecularButton>
            </div>
        </div>
    </div>

    <!-- ========================================================================= -->
    <!-- ACTION CONFIRMATION MODAL -->
    <!-- ========================================================================= -->
    <div v-if="showConfirmModal" class="fixed inset-0 z-[60] flex items-center justify-center bg-black/60 p-4">
        <div class="bg-white border border-gray-200 p-6 rounded-xl w-full max-w-md shadow-xl animate-in fade-in duration-150">
            <div class="w-11 h-11 rounded-full bg-amber-50 text-[#F8B803] flex items-center justify-center mb-4 border border-amber-100">
                <CheckCircle2 class="w-6 h-6" />
            </div>
            <h3 class="text-xl font-semibold text-gray-900 mb-1.5">{{ confirmOptions.title }}</h3>
            <p class="text-gray-600 text-sm mb-4 leading-relaxed">
                {{ confirmOptions.description }}
            </p>

            <!-- Details Callout -->
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
                    :variant="confirmOptions.confirmVariant || 'brand'"
                    size="md"
                    :radius="10"
                    @click="handleConfirmedAction"
                    :disabled="isSubmitting"
                >
                    <Check class="w-4 h-4 mr-1.5" />
                    <span>{{ confirmOptions.confirmText || 'Confirm & Save' }}</span>
                </SpecularButton>
            </div>
        </div>
    </div>
</template>
