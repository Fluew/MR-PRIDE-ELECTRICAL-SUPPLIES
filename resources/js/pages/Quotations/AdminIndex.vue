<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import { useCanDelete } from '@/composables/useCanDelete';
import { useCurrency } from '@/composables/useCurrency';
import { showToast } from '@/composables/useFlashToast';
import { ref, computed, watch, defineAsyncComponent } from 'vue';
import SpecularButton from '@/components/ui/SpecularButton.vue';
import StatusBadge from '@/components/ui/StatusBadge.vue';
import GlideSelect from '@/components/vue-bits/GlideSelect.vue';
import { 
    FileText, 
    Plus, 
    Search, 
    Printer, 
    CheckCircle2, 
    Clock, 
    AlertTriangle, 
    XCircle, 
    Check, 
    X, 
    Trash2, 
    Pencil, 
    Eye, 
    ShoppingCart, 
    Download,
    Building2,
    Calendar,
    Phone,
    Mail,
    MapPin,
    AlertCircle,
    RotateCcw,
    Layers,
    ArrowRight,
    Upload,
    ChevronRight,
    FileSpreadsheet,
    FileUp,
    Truck,
    Paperclip,
} from '@lucide/vue';

// The import wizard is a modal: it downloads after the page renders, not before.
const ImportWizard = defineAsyncComponent(() => import('@/components/import/ImportWizard.vue'));

interface QuotationItem {
    id?: number;
    product_id?: number | null;
    sku: string;
    product_name: string;
    quantity: number;
    unit_price: number;
    discount: number;
    subtotal: number;
    // Computed by the server for catalog products (stock check, unreserved stock).
    available_stock?: number;
    shortage?: number;
    stock_status?: string;
    // The purchase raised for this quotation for the line's product (Draft quotations only).
    purchase?: { number: string; status: string } | null;
    // Internal: supplier cost when the line was priced. Never on customer documents.
    unit_cost?: number | null;
    supplier_id?: number | null;
    supplier?: { id: number; name: string } | null;
}

interface Quotation {
    id: number;
    quotation_number: string;
    client_id?: number | null;
    client_name: string;
    contact_person?: string | null;
    email?: string | null;
    phone?: string | null;
    address?: string | null;
    quotation_date: string;
    valid_until: string;
    subtotal: number;
    discount: number;
    tax_rate: number;
    tax_amount: number;
    grand_total: number;
    status: string;
    status_label?: string;
    email_status?: string;
    email_status_label?: string;
    allowed_actions?: string[];
    is_approved?: boolean;
    submitted_for_approval_at?: string | null;
    /** Short products still being purchased for this Draft: it stays Draft until they arrive. */
    waiting_for_stock?: string[];
    approved_by?: { name: string } | null;
    approved_at?: string | null;
    sent_by?: { name: string } | null;
    sent_at?: string | null;
    decision_by?: { name: string } | null;
    customer_decision_at?: string | null;
    customer_decision_source?: string | null;
    customer_decision_remarks?: string | null;
    notes?: string | null;
    terms_and_conditions?: string | null;
    prepared_by?: { name: string } | null;
    converted_to_sale_id?: number | null;
    sale?: { id: number; order_number: string; status: string; payment_status: string } | null;
    items: QuotationItem[];
    created_at?: string;
    attachment_url?: string | null;
    material_file_url?: string | null;
    material_file_name?: string | null;
    // What the client sent with a public request (or how a staff import was made).
    request_details?: {
        source?: 'public_form' | 'staff_import';
        project_type?: string;
        timeline?: string;
        // quantity and brand_name (the brand of an "Others" row) only on requests that gave them.
        brands?: { brand: string; details: string; quantity?: number; brand_name?: string }[];
        unlisted_brands?: string | null;
        // Products the client read from their uploaded lists and confirmed.
        // unit: as written in the client's file, or null (staff verify units when quoting).
        product_list?: { description: string; quantity: number; unit: string | null; source?: string | null }[] | null;
        // Rows read from each uploaded list when the request was sent (never reviewed by the client).
        extracted_products?: {
            file: string;
            status: 'read' | 'columns_not_found' | 'not_read';
            method: string | null;
            message: string | null;
            rows: { ref: string; description: string; quantity: number | null; unit: string | null; issues: string[] }[];
        }[] | null;
        file_name?: string;
        imported_by?: string | null;
        submitted_at?: string;
    } | null;
    files?: { id: number; kind: string; original_name: string; size: number; mime?: string | null; url: string }[];
}

interface Product {
    id: number;
    sku: string;
    name: string;
    category: string;
    price: number;
    cost_price?: number | null;
    supplier_id?: number | null;
    supplier_name?: string | null;
    stock: number; // available (not reserved for confirmed orders)
    on_hand?: number;
    status: string;
    stock_status?: string;
}

interface Client {
    id: number;
    company_name: string;
    contact_person?: string | null;
    email?: string | null;
    phone?: string | null;
    address?: string | null;
}

const props = defineProps<{
    quotations: {
        data: Quotation[];
        current_page: number;
        last_page: number;
        total: number;
        links: any[];
    };
    filters: {
        search?: string;
        status?: string;
        date_from?: string;
        date_to?: string;
    };
    metrics: {
        totalQuotations: number;
        pendingCount: number;
        forApprovalCount: number;
        approvedCount: number;
        acceptedCount: number;
        totalQuotedValue: number;
    };
    products: Product[];
    clients: Client[];
    nextNumber: string;
    statusOptions: { value: string; label: string; badgeClass: string }[];
    formStatuses: { value: string; label: string }[];
    decisionSources: Record<string, string>;
    canApprove: boolean;
    /** Registered suppliers: the line "Select Supplier" dropdown (all) and the supplier quotation import (active only). */
    suppliers?: { id: number; name: string; status?: string }[];
    /** Latest purchase cost per product and supplier (deliveries, purchase orders, verified supplier quotes, product cost). */
    supplierCosts?: { product_id: number; supplier_id: number; unit_cost: number; source: string; reference: string | null; date: string | null }[];
    /** Days a quotation is valid after it is approved (config company.quotation_validity_days). */
    quotationValidityDays?: number;
    /** The fixed Terms and Conditions every quotation carries (App\Support\QuotationTerms). */
    quotationTerms?: string[];
}>();

/** YYYY-MM-DD in the browser's time zone, `days` from today (toISOString() would give the UTC date). */
const localDate = (days = 0) => {
    const d = new Date();
    d.setDate(d.getDate() + days);

    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
};

const statusLabel = (value: string) => props.statusOptions.find((o) => o.value === value)?.label ?? value;
const can = (quote: Quotation | null, action: string) => !!quote?.allowed_actions?.includes(action);

const page = usePage();
const canDelete = useCanDelete();
const teamSlug = computed(() => (page.props as any).currentTeam?.slug || (page.props as any).team?.slug || '');

// Filters
const search = ref(props.filters?.search || '');
const selectedStatus = ref(props.filters?.status || 'All');
const dateFrom = ref(props.filters?.date_from || '');
const dateTo = ref(props.filters?.date_to || '');

let filterTimeout: ReturnType<typeof setTimeout> | null = null;
const applyFilters = () => {
    if (filterTimeout) clearTimeout(filterTimeout);
    filterTimeout = setTimeout(() => {
        router.get(
            `/${teamSlug.value}/quotations`,
            {
                search: search.value || undefined,
                status: selectedStatus.value !== 'All' ? selectedStatus.value : undefined,
                date_from: dateFrom.value || undefined,
                date_to: dateTo.value || undefined,
            },
            // Only the list changes; the form's product, client and supplier lists are kept.
            { preserveState: true, preserveScroll: true, replace: true, only: ['quotations', 'filters', 'metrics'] },
        );
    }, 350);
};

const resetFilters = () => {
    search.value = '';
    selectedStatus.value = 'All';
    dateFrom.value = '';
    dateTo.value = '';
    applyFilters();
};

// Single consolidated watcher — avoids double-firing vs calling applyFilters individually
watch([search, selectedStatus, dateFrom, dateTo], applyFilters);

// Modals State
const showCreateEditModal = ref(false);
const isEditing = ref(false);
const showViewModal = ref(false);
const showPrintModal = ref(false);
const showStatusModal = ref(false);
const showConvertModal = ref(false);
const showDeleteModal = ref(false);
const deleteConfirmInput = ref('');
const activeQuotation = ref<Quotation | null>(null);

// Create / Edit Form State
const selectedExistingClientId = ref<string>('');

// Floating labels (Client / Customer Information). A label sits inside its empty field and moves up
// on focus, or when the field has a value (typed, loaded with the quotation, or filled in by "Load
// Existing Client"). Text fields use an empty placeholder (" ") with Tailwind's peer-placeholder-shown;
// the dropdown has no placeholder, so its label follows its focus and selected value.
const FLOAT_FIELD = 'field-control peer pt-5 pb-1.5';
const FLOAT_LABEL_UP = '-translate-y-2.5 scale-75';
const FLOAT_LABEL =
    'pointer-events-none absolute top-3.5 left-3.5 origin-[0] text-sm font-medium text-gray-500 transition-all duration-200 ' +
    `${FLOAT_LABEL_UP} peer-placeholder-shown:translate-y-0 peer-placeholder-shown:scale-100 peer-focus:-translate-y-2.5 peer-focus:scale-75 peer-focus:text-[#4b5320]`;
const existingClientFocused = ref(false);
const existingClientLabelUp = computed(() => existingClientFocused.value || selectedExistingClientId.value !== '');
const form = ref<{
    id?: number;
    client_name: string;
    contact_person: string;
    email: string;
    phone: string;
    address: string;
    /** Shown only: the server sets it (today for a new quotation). */
    quotation_date: string;
    /** Shown only: set when the quotation is approved (approval date + validity days). */
    valid_until: string | null;
    status: string;
    notes: string;
    tax_rate: number | null;
    discount: number | null;
    items: {
        product_id: number | null;
        sku: string;
        product_name: string;
        quantity: number | null;
        unit_price: number | null;
        discount: number | null;
        /** null until a product from inventory is chosen. */
        stock: number | null;
        unit_cost: number | null;
        supplier_id: number | null;
    }[];
}>({
    client_name: '',
    contact_person: '',
    email: '',
    phone: '',
    address: '',
    quotation_date: localDate(),
    valid_until: null,
    status: 'draft',
    notes: '',
    tax_rate: 0,
    discount: null,
    items: []
});

// Watch client selection to auto-fill form
watch(selectedExistingClientId, (newId) => {
    if (!newId) return;
    const client = props.clients.find(c => c.id.toString() === newId.toString());
    if (client) {
        form.value.client_name = client.company_name;
        form.value.contact_person = client.contact_person || '';
        form.value.email = client.email || '';
        form.value.phone = client.phone || '';
        form.value.address = client.address || '';
    }
});

// Dynamic Calculation Computeds
const formSubtotal = computed(() => {
    return form.value.items.reduce((sum, item) => {
        const disc = Number(item.discount) || 0;
        const price = Number(item.unit_price) || 0;
        const qty = Number(item.quantity) || 0;
        return sum + Math.max(0, (price - disc) * qty);
    }, 0);
});

const formTaxAmount = computed(() => {
    const taxable = Math.max(0, formSubtotal.value - (Number(form.value.discount) || 0));
    return (taxable * (Number(form.value.tax_rate) || 0)) / 100;
});

const formGrandTotal = computed(() => {
    const taxable = Math.max(0, formSubtotal.value - (Number(form.value.discount) || 0));
    return taxable + formTaxAmount.value;
});

const formStatusChoices = computed(() => {
    const list = [...props.formStatuses];
    if (form.value.status && !list.some((s) => s.value === form.value.status)) {
        list.unshift({ value: form.value.status, label: statusLabel(form.value.status) });
    }
    return list;
});

// Line subtotal: (unit price − discount) × quantity, blank fields counting as 0.
const lineSubtotal = (item: { unit_price: number | null; discount: number | null; quantity: number | null }) =>
    Math.max(0, ((Number(item.unit_price) || 0) - (Number(item.discount) || 0)) * (Number(item.quantity) || 0));

// Margin over supplier cost, for staff only.
const lineMargin = (item: { unit_price: number | null; discount: number | null; unit_cost: number | null }) => {
    if (item.unit_cost == null || !(Number(item.unit_price) > 0)) return null;
    const net = Number(item.unit_price) - (Number(item.discount) || 0);
    return Math.round(((net - Number(item.unit_cost)) / net) * 1000) / 10;
};

// Line Items Methods: a new line is blank until a product is chosen.
const addItem = () => {
    form.value.items.push({
        product_id: null,
        sku: '',
        product_name: '',
        quantity: null,
        unit_price: null,
        discount: null,
        stock: null,
        unit_cost: null,
        supplier_id: null,
    });
};

// Supplier cost per line: choosing a supplier fills Unit Cost with that supplier's own latest cost
// (never another supplier's). The customer's selling price (Unit Price) is never changed by it.
const costIndex = computed(() => new Map((props.supplierCosts ?? []).map((c) => [`${c.product_id}:${c.supplier_id}`, c])));
const supplierCostFor = (item: { product_id: number | null; supplier_id: number | null }) =>
    item.product_id && item.supplier_id ? costIndex.value.get(`${item.product_id}:${item.supplier_id}`) ?? null : null;
const costSourceLabels: Record<string, string> = { delivery: 'delivery', purchase_order: 'purchase order', supplier_quote: 'supplier quotation', inventory: 'inventory cost' };
const costNote = (item: { product_id: number | null; supplier_id: number | null; unit_cost: number | null }) => {
    if (!item.supplier_id) return null;
    const c = supplierCostFor(item);
    if (!c) return { missing: true, text: 'No Supplier Price Available' };
    if (item.unit_cost !== null && Number(item.unit_cost) !== Number(c.unit_cost)) return { missing: false, text: 'Entered manually' };
    return { missing: false, text: `Latest ${costSourceLabels[c.source] ?? c.source}${c.reference ? ` ${c.reference}` : ''}${c.date ? ` · ${c.date}` : ''}` };
};
const activeSuppliers = computed(() => (props.suppliers ?? []).filter((s) => !s.status || s.status === 'Active'));
const onSupplierSelect = (index: number) => {
    const item = form.value.items[index];
    const c = supplierCostFor(item);
    item.unit_cost = c ? Number(c.unit_cost) : null;
};

const onProductSelect = (index: number, productId: number | null) => {
    if (!productId) return;
    const prod = props.products.find(p => p.id === productId);
    if (prod) {
        form.value.items[index].product_id = prod.id;
        form.value.items[index].sku = prod.sku;
        form.value.items[index].product_name = prod.name;
        form.value.items[index].unit_price = Number(prod.price);
        form.value.items[index].stock = prod.stock;
        form.value.items[index].supplier_id = prod.supplier_id ?? null;
        // The product's supplier's latest recorded cost; without a supplier, the product's own cost.
        const recorded = supplierCostFor(form.value.items[index]);
        form.value.items[index].unit_cost = recorded ? Number(recorded.unit_cost) : (!prod.supplier_id && prod.cost_price != null ? Number(prod.cost_price) : null);
    }
};

const removeItem = (index: number) => {
    form.value.items.splice(index, 1);
};

// Form Actions
const openCreateModal = () => {
    isEditing.value = false;
    selectedExistingClientId.value = '';
    form.value = {
        client_name: '',
        contact_person: '',
        email: '',
        phone: '',
        address: '',
        quotation_date: localDate(),
        valid_until: null,
        status: 'draft',
        notes: '',
        tax_rate: 0,
        discount: null,
        items: []
    };
    addItem();
    showCreateEditModal.value = true;
};

const openEditModal = (quote: Quotation) => {
    isEditing.value = true;
    selectedExistingClientId.value = quote.client_id ? quote.client_id.toString() : '';
    form.value = {
        id: quote.id,
        client_name: quote.client_name,
        contact_person: quote.contact_person || '',
        email: quote.email || '',
        phone: quote.phone || '',
        address: quote.address || '',
        quotation_date: toInputDate(quote.quotation_date),
        valid_until: quote.valid_until ? toInputDate(quote.valid_until) : null,
        // A Draft already waiting for approval stays submitted when it is saved again.
        status: quote.status === 'draft' && quote.submitted_for_approval_at ? 'submit_for_approval' : quote.status,
        notes: quote.notes || '',
        tax_rate: Number(quote.tax_rate),
        discount: Number(quote.discount),
        items: quote.items.map(item => {
            const matchedProd = props.products.find(p => p.id === item.product_id || p.sku === item.sku);
            return {
                product_id: item.product_id || null,
                sku: item.sku,
                product_name: item.product_name,
                quantity: item.quantity,
                unit_price: Number(item.unit_price),
                discount: Number(item.discount),
                stock: matchedProd ? matchedProd.stock : 0,
                unit_cost: item.unit_cost != null ? Number(item.unit_cost) : null,
                supplier_id: item.supplier_id ?? null,
            };
        })
    };
    if (form.value.items.length === 0) addItem();
    showCreateEditModal.value = true;
};

const formError = ref<string | null>(null);
const isSubmitting = ref(false);

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
    confirmText: 'Confirm & Save',
    action: () => {},
});

const openConfirmModal = (options: ConfirmActionOptions) => {
    confirmOptions.value = {
        confirmText: 'Confirm & Save',
        ...options,
    };
    showConfirmModal.value = true;
};

const handleConfirmedAction = () => {
    showConfirmModal.value = false;
    confirmOptions.value.action();
};

const promptSaveQuotation = () => {
    formError.value = null;

    if (!form.value.client_name.trim()) {
        formError.value = 'Client / Company Name is required.';
        return;
    }
    if (form.value.items.length === 0) {
        formError.value = 'At least one line item must be added to the quotation.';
        return;
    }
    for (let i = 0; i < form.value.items.length; i++) {
        const item = form.value.items[i];
        if (!item.product_name.trim() || !item.sku.trim()) {
            formError.value = `Item #${i + 1}: select a product from inventory, or enter a description and SKU.`;
            return;
        }
        if (Number(item.quantity) <= 0) {
            formError.value = `Item #${i + 1} quantity must be greater than zero.`;
            return;
        }
        if (Number(item.unit_price) < 0) {
            formError.value = `Item #${i + 1} unit price cannot be negative.`;
            return;
        }
    }

    const itemCount = form.value.items.reduce((sum, item) => sum + Number(item.quantity || 0), 0);
    const totalAmount = formGrandTotal.value;

    openConfirmModal({
        title: isEditing.value ? 'Confirm Quotation Update' : 'Confirm New Quotation',
        description: isEditing.value
            ? `Are you sure you want to save changes to quotation for "${form.value.client_name}"?`
            : `Are you sure you want to generate a formal quotation for "${form.value.client_name}"?`,
        details: [
            { label: 'Client / Company', value: form.value.client_name },
            { label: 'Line Items', value: `${form.value.items.length} items (${itemCount} total units)` },
            { label: 'Total Valuation', value: formatCurrency(totalAmount) },
            { label: 'Validity Date', value: formatDate(form.value.valid_until) },
        ],
        confirmText: isEditing.value ? 'Save Changes' : 'Create Quotation',
        action: saveQuotation,
    });
};

// What is saved: blank amounts count as 0; dates and terms are set by the server.
const savePayload = () => {
    const { quotation_date: _date, valid_until: _validUntil, ...data } = form.value;
    return {
        ...data,
        tax_rate: Number(data.tax_rate) || 0,
        discount: Number(data.discount) || 0,
        items: data.items.map(({ stock: _stock, ...item }) => ({
            ...item,
            quantity: Number(item.quantity) || 0,
            unit_price: Number(item.unit_price) || 0,
            discount: Number(item.discount) || 0,
        })),
    };
};

const saveQuotation = () => {
    isSubmitting.value = true;

    if (isEditing.value && form.value.id) {
        router.put(`/${teamSlug.value}/quotations/${form.value.id}`, savePayload(), {
            onSuccess: () => {
                showCreateEditModal.value = false;
                isSubmitting.value = false;
            },
            onError: (err) => {
                formError.value = Object.values(err)[0] as string;
                isSubmitting.value = false;
            }
        });
    } else {
        router.post(`/${teamSlug.value}/quotations`, savePayload(), {
            onSuccess: () => {
                showCreateEditModal.value = false;
                isSubmitting.value = false;
            },
            onError: (err) => {
                formError.value = Object.values(err)[0] as string;
                isSubmitting.value = false;
            }
        });
    }
};

// Status Update Modal (working statuses only; approval, sending and the customer's answer have their own actions)
const targetStatus = ref<string>('');
const statusRemarks = ref('');
const manualStatusOptions = computed(() =>
    (activeQuotation.value?.allowed_actions ?? [])
        .filter((a) => a.startsWith('status:'))
        .map((a) => a.slice(7))
        .map((value) => props.statusOptions.find((o) => o.value === value)
            ?? { value, label: value === 'submit_for_approval' ? 'Submit for approval' : value, badgeClass: '' }),
);
// Lines without a selling price (e.g. a customer request's free descriptions): they block approval.
const unpricedLines = (quote: Quotation | null) => (quote?.items ?? []).filter((i) => !(Number(i.unit_price) > 0)).map((i) => i.product_name);
/** Why a status cannot be chosen right now (checked again by the server), or null. */
const statusBlockedReason = (value: string): string | null => {
    const missing = unpricedLines(activeQuotation.value);
    return value === 'submit_for_approval' && missing.length
        ? `Set a selling price for every line first. Missing: ${missing.slice(0, 3).join(', ')}${missing.length > 3 ? ` and ${missing.length - 3} more` : ''}.`
        : null;
};
const statusError = ref<string | null>(null);
const openStatusModal = (quote: Quotation) => {
    activeQuotation.value = quote;
    targetStatus.value = manualStatusOptions.value.find((o) => !statusBlockedReason(o.value))?.value ?? '';
    statusRemarks.value = '';
    statusError.value = null;
    showStatusModal.value = true;
};
const priceFromStatus = () => {
    if (!activeQuotation.value) return;
    const quote = activeQuotation.value;
    showStatusModal.value = false;
    openEditModal(quote);
};

const isUpdatingStatus = ref(false);

const promptUpdateStatus = () => {
    statusError.value = null;
    if (!activeQuotation.value || !targetStatus.value) {
        statusError.value = 'Choose the new status first.';
        return;
    }
    const blocked = statusBlockedReason(targetStatus.value);
    if (blocked) {
        statusError.value = blocked;
        return;
    }
    openConfirmModal({
        title: 'Confirm Status Change',
        description: `Change quotation ${activeQuotation.value.quotation_number} to "${statusLabel(targetStatus.value)}"?`,
        details: [
            { label: 'Quotation #', value: activeQuotation.value.quotation_number },
            { label: 'Client / Company', value: activeQuotation.value.client_name },
            { label: 'Current Status', value: activeQuotation.value.status_label ?? activeQuotation.value.status },
            { label: 'New Status', value: statusLabel(targetStatus.value) },
        ],
        confirmText: 'Update Status',
        action: updateStatusSubmit,
    });
};

const updateStatusSubmit = () => {
    if (!activeQuotation.value) return;
    isUpdatingStatus.value = true;
    router.patch(`/${teamSlug.value}/quotations/${activeQuotation.value.id}/status`, {
        status: targetStatus.value,
        remarks: statusRemarks.value || undefined,
    }, {
        preserveScroll: true,
        onSuccess: () => {
            showStatusModal.value = false;
        },
        // Refused by the server (e.g. a rule changed meanwhile): say why, in the dialog.
        onError: (errors) => {
            statusError.value = (Object.values(errors)[0] as string) || 'The status could not be changed.';
        },
        onFinish: () => {
            isUpdatingStatus.value = false;
        },
    });
};

const workflowBusy = ref(false);
const postWorkflow = (quote: Quotation, action: string, data: Record<string, any> = {}, onSuccess?: () => void) => {
    workflowBusy.value = true;
    router.post(`/${teamSlug.value}/quotations/${quote.id}/${action}`, data, {
        preserveScroll: true,
        onSuccess: () => onSuccess?.(),
        onFinish: () => {
            workflowBusy.value = false;
        },
    });
};

// Admin approval: does not email the customer and does not touch stock.
const promptApprove = (quote: Quotation) => {
    openConfirmModal({
        title: 'Approve Quotation',
        description: `Approve ${quote.quotation_number}? Nothing is emailed yet: once approved, use Send Quotation to email it to the customer.`,
        details: [
            { label: 'Total', value: formatCurrency(quote.grand_total) },
            { label: 'Valid until', value: formatDate(quote.valid_until) },
        ],
        confirmText: 'Approve',
        action: () => postWorkflow(quote, 'approve'),
    });
};

// Products are short: one purchase request per short product, for admin approval. Stays Draft.
const promptRequestStock = (quote: Quotation) => {
    const short = (quote.items ?? []).filter((i) => i.product_id && ['Insufficient Stock', 'Not Available'].includes(i.stock_status ?? '') && !i.purchase);
    openConfirmModal({
        title: 'Request Stock',
        description: `Create purchase requests for the products ${quote.quotation_number} is short of? An administrator approves them, and a purchase order is then drafted for the supplier. The quotation stays Draft until the stock arrives; it is not sent to the customer.`,
        details: short.map((i) => ({ label: i.product_name, value: `${i.quantity} needed` })),
        confirmText: 'Request Stock',
        action: () => postWorkflow(quote, 'request-stock'),
    });
};

// "Send Quotation" (approved) or "Resend" (already sent: an explicit second send).
const promptSend = (quote: Quotation) => {
    const resend = quote.status === 'sent';
    openConfirmModal({
        title: resend ? 'Resend Quotation' : 'Send Quotation',
        description: !quote.email
            ? 'This quotation has no customer email address. Edit it first, or add the email to the client.'
            : resend
              ? `${quote.quotation_number} was already emailed on ${formatDate(quote.sent_at ?? null)}. Send the PDF to ${quote.email} again?`
              : `Email ${quote.quotation_number} as a PDF to ${quote.email}? It becomes Sent once the email goes out. The PDF shows customer prices only.`,
        details: [
            { label: 'To', value: quote.email || '—' },
            { label: 'Total', value: formatCurrency(quote.grand_total) },
            { label: 'Valid until', value: formatDate(quote.valid_until) },
        ],
        confirmText: resend ? 'Send Again' : 'Send Quotation',
        action: () => postWorkflow(quote, 'send', resend ? { resend: true } : {}, () => (showViewModal.value = false)),
    });
};

// Customer's answer (recorded by staff: customers have no accounts)
const showDecisionModal = ref(false);
// An acceptance only records the answer: the Sales Order is created in Sales → New Sales Order.
const decision = ref({
    decision: 'accepted' as 'accepted' | 'rejected',
    source: 'email',
    decided_on: new Date().toISOString().slice(0, 10),
    remarks: '',
});
const openDecisionModal = (quote: Quotation) => {
    activeQuotation.value = quote;
    decision.value = {
        decision: 'accepted',
        source: 'email',
        decided_on: new Date().toISOString().slice(0, 10),
        remarks: '',
    };
    showDecisionModal.value = true;
};
const submitDecision = () => {
    if (!activeQuotation.value) return;
    const d = decision.value;
    postWorkflow(activeQuotation.value, 'decision', {
        decision: d.decision,
        source: d.source,
        decided_on: d.decided_on,
        remarks: d.remarks || undefined,
    }, () => {
        showDecisionModal.value = false;
    });
};

// Generate the Sales Order from an accepted quotation. Nothing is paid or taken from stock here.
const convertFulfillmentType = ref<'delivery' | 'pickup'>('delivery');
const convertDeliveryAddress = ref('');
const convertDeliveryInstructions = ref('');
const lowStockLines = computed(() => (activeQuotation.value?.items ?? []).filter((i) => i.product_id && (i.shortage || 0) > 0));

const openConvertModal = (quote: Quotation) => {
    activeQuotation.value = quote;
    convertFulfillmentType.value = 'delivery';
    convertDeliveryAddress.value = quote.address || '';
    convertDeliveryInstructions.value = '';
    showConvertModal.value = true;
};

const convertToSaleSubmit = () => {
    if (!activeQuotation.value) return;
    postWorkflow(activeQuotation.value, 'convert-to-sale', {
        fulfillment_type: convertFulfillmentType.value,
        delivery_address: convertFulfillmentType.value === 'delivery' ? convertDeliveryAddress.value : undefined,
        delivery_instructions: convertDeliveryInstructions.value || undefined,
    }, () => {
        showConvertModal.value = false;
    });
};
// View & Print
const openViewModal = (quote: Quotation) => {
    activeQuotation.value = quote;
    showViewModal.value = true;
};

// ------------------------------------------------------------------ client request review

const formatFileSize = (bytes: number) =>
    bytes >= 1024 * 1024 ? `${(bytes / 1024 / 1024).toFixed(1)} MB` : `${Math.max(1, Math.round(bytes / 1024))} KB`;

// Request lines staff still have to handle: not matched to a catalog product, or not priced.
const reviewItems = computed(() =>
    (activeQuotation.value?.items ?? [])
        .filter((i) => !String(i.sku).startsWith('BRAND'))
        .map((i) => ({
            ...i,
            needsMatching: !i.product_id,
            needsPrice: Number(i.unit_price) <= 0,
        })),
);
const reviewOpenCount = computed(() => reviewItems.value.filter((i) => i.needsMatching || i.needsPrice).length);
const hasClientFiles = (q: Quotation) => !!(q.material_file_url || q.attachment_url || q.files?.length);

const editFromReview = () => {
    if (!activeQuotation.value) return;
    const quote = activeQuotation.value;
    showViewModal.value = false;
    openEditModal(quote);
};
const statusFromReview = () => {
    if (!activeQuotation.value) return;
    const quote = activeQuotation.value;
    showViewModal.value = false;
    openStatusModal(quote);
};

// ------------------------------------------------------------------ import menu

const importOptions = [
    { value: 'materials', label: 'Material list', tag: 'PDF · Excel · Image' },
    { value: 'csv', label: 'Quotations (CSV)', tag: '.csv' },
    { value: 'supplier_quotation', label: 'Supplier quotation', tag: 'CSV · Excel · PDF · Image' },
];
const onImportChoice = (value: string) => {
    if (value === 'csv') openImportModal();
    else if (value === 'supplier_quotation') showSupplierQuoteImport.value = true;
    else openMaterialImportModal();
};
// Supplier quotations received by email: imported as unverified supplier quotes (inventory prices untouched).
const showSupplierQuoteImport = ref(false);

// ------------------------------------------------------------------ material-list import

const MATERIAL_EXTENSIONS = ['pdf', 'xlsx', 'xls', 'csv', 'jpg', 'jpeg', 'png'];
type MaterialField = { key: string; label: string; required: boolean };
// The first rows of the file and the columns found, for choosing the columns by hand.
type MaterialColumnChoice = {
    columns: string[];
    preview: string[][];
    header_index: number | null;
    header_detected: boolean;
    mapping: Record<string, number>;
    missing: string[];
    fields: MaterialField[];
};
const showMaterialImportModal = ref(false);
const materialImport = ref({ file: null as File | null, clientId: '', clientName: '', contactPerson: '', email: '', phone: '' });
const materialPreview = ref<({
    method: string;
    warning: string | null;
    valid_count: number;
    invalid_count: number;
    unparsed_count: number;
    confident: boolean;
    items: { description: string; quantity: number | null; unit: string | null; brand: string | null; specs?: string | null; item_code?: string | null; confidence?: string; needs_review?: boolean; warnings?: string[]; skip?: boolean; errors: string[]; match: { sku: string; name: string; price: number | null } | null }[];
} & MaterialColumnChoice) | null>(null);
const materialImportError = ref<string | null>(null);
const isReadingMaterials = ref(false);
const isCreatingFromMaterials = ref(false);

// Columns chosen by hand: the column index per field ('' = not in the file) and the header row ('' = none).
const materialColumns = ref<MaterialColumnChoice | null>(null);
const showMaterialColumns = ref(false);
const materialMapping = ref<Record<string, string>>({});
const materialHeaderRow = ref('');
const useMaterialMapping = ref(false); // send the chosen columns when reading and creating

const columnLetter = (index: number): string => {
    let letters = '';
    for (let n = index + 1; n > 0; n = Math.floor((n - 1) / 26)) letters = String.fromCharCode(65 + ((n - 1) % 26)) + letters;
    return letters;
};
const shorten = (text: string, max: number) => (text.length > max ? `${text.slice(0, max)}…` : text);
const materialColumnCount = computed(() => {
    const choice = materialColumns.value;
    if (!choice) return 0;
    return Math.min(40, Math.max(choice.columns.length, ...choice.preview.map((row) => row.length)));
});
const materialHeaderLabel = (col: number): string => {
    const row = materialHeaderRow.value === '' ? null : materialColumns.value?.preview[Number(materialHeaderRow.value)];
    return row ? String(row[col] ?? '').trim() : '';
};
const materialColumnOption = (col: number) => {
    const label = materialHeaderLabel(col);
    return `Column ${columnLetter(col)}${label ? ` · ${shorten(label, 30)}` : ''}`;
};
const materialFieldForColumn = (col: number) => materialColumns.value?.fields.find((f) => materialMapping.value[f.key] === String(col))?.label ?? null;
const materialRowOption = (row: string[], index: number) => {
    const text = row.filter((c) => String(c).trim() !== '').join(' | ');
    return `Row ${index + 1}${text ? ` · ${shorten(text, 50)}` : ''}`;
};
// Why the chosen columns cannot be applied yet (null when they can).
const materialMappingProblem = computed((): string | null => {
    const fields = materialColumns.value?.fields ?? [];
    const missing = fields.filter((f) => f.required && !materialMapping.value[f.key]);
    if (missing.length) return `Choose the ${missing.map((f) => f.label.toLowerCase()).join(' and ')} column${missing.length > 1 ? 's' : ''}.`;
    const used = new Map<string, string>();
    for (const f of fields) {
        const col = materialMapping.value[f.key] ?? '';
        if (col === '') continue;
        if (used.has(col)) return `${used.get(col)} and ${f.label} are both set to column ${columnLetter(Number(col))}. Choose a different column for each.`;
        used.set(col, f.label);
    }
    return null;
});
const setMaterialColumns = (data: MaterialColumnChoice | null) => {
    materialColumns.value = data?.preview?.length
        ? { columns: data.columns ?? [], preview: data.preview, header_index: data.header_index ?? null, header_detected: !!data.header_detected, mapping: data.mapping ?? {}, missing: data.missing ?? [], fields: data.fields ?? [] }
        : null;
    materialMapping.value = Object.fromEntries((materialColumns.value?.fields ?? []).map((f) => [f.key, data?.mapping?.[f.key] != null ? String(data.mapping[f.key]) : '']));
    materialHeaderRow.value = data?.header_index != null ? String(data.header_index) : '';
};
const resetMaterialColumns = () => {
    setMaterialColumns(null);
    showMaterialColumns.value = false;
    useMaterialMapping.value = false;
};

const validateMaterialPreviewItem = (item: { description: string; quantity: number | null; errors: string[] }) => {
    const errs: string[] = [];
    if (!item.description || !String(item.description).trim()) {
        errs.push('Missing item description.');
    }
    const q = Number(item.quantity);
    if (item.quantity === null || item.quantity === ('' as any) || Number.isNaN(q) || q <= 0) {
        errs.push('Quantity must be greater than zero.');
    }
    item.errors = errs;
    if (materialPreview.value) {
        const active = materialPreview.value.items.filter((i) => !i.skip);
        materialPreview.value.valid_count = active.filter((i) => i.errors.length === 0).length;
        materialPreview.value.invalid_count = active.filter((i) => i.errors.length > 0).length;
    }
};

const openMaterialImportModal = () => {
    materialImport.value = { file: null, clientId: '', clientName: '', contactPerson: '', email: '', phone: '' };
    materialPreview.value = null;
    materialImportError.value = null;
    resetMaterialColumns();
    showMaterialImportModal.value = true;
};

const onMaterialClientPick = () => {
    const client = props.clients.find((c) => String(c.id) === materialImport.value.clientId);
    if (!client) return;
    materialImport.value.clientName = client.company_name;
    materialImport.value.contactPerson = client.contact_person ?? '';
    materialImport.value.email = client.email ?? '';
    materialImport.value.phone = client.phone ?? '';
};

const onMaterialFileChange = (event: Event) => {
    const input = event.target as HTMLInputElement;
    const chosen = input.files?.[0] ?? null;
    input.value = '';
    materialPreview.value = null;
    materialImportError.value = null;
    resetMaterialColumns();
    if (!chosen) return;
    const ext = chosen.name.split('.').pop()?.toLowerCase() ?? '';
    if (!MATERIAL_EXTENSIONS.includes(ext)) {
        materialImportError.value = 'Please choose a PDF, Excel (.xlsx/.xls), CSV, JPG or PNG file.';
        return;
    }
    if (chosen.size > 10 * 1024 * 1024) {
        materialImportError.value = `This file is ${formatFileSize(chosen.size)}. The limit is 10 MB.`;
        return;
    }
    materialImport.value.file = chosen;
};

const materialFormData = (execute: boolean) => {
    const data = new FormData();
    data.append('file', materialImport.value.file as File);
    data.append('client_name', materialImport.value.clientName.trim());
    if (materialImport.value.contactPerson.trim()) data.append('contact_person', materialImport.value.contactPerson.trim());
    if (materialImport.value.email.trim()) data.append('email', materialImport.value.email.trim());
    if (materialImport.value.phone.trim()) data.append('phone', materialImport.value.phone.trim());
    data.append('execute', execute ? '1' : '0');
    if (useMaterialMapping.value && materialColumns.value) {
        const chosen = Object.fromEntries(materialColumns.value.fields.map((f) => [f.key, materialMapping.value[f.key] ? Number(materialMapping.value[f.key]) : null]));
        data.append('mapping', JSON.stringify(chosen));
        data.append('header_row', materialHeaderRow.value === '' ? '-1' : materialHeaderRow.value);
    }
    if (execute && materialPreview.value?.items) {
        data.append('items', JSON.stringify(materialPreview.value.items));
    }
    return data;
};

// Read the file; withColumns = use the columns chosen by hand instead of finding them from the headers.
const readMaterialFile = async (withColumns = false) => {
    if (!materialImport.value.file) {
        materialImportError.value = 'Please choose a file first.';
        return;
    }
    if (!materialImport.value.clientName.trim()) {
        materialImportError.value = 'Please enter the client or company name.';
        return;
    }
    isReadingMaterials.value = true;
    materialImportError.value = null;
    materialPreview.value = null;
    useMaterialMapping.value = withColumns && !!materialColumns.value;
    try {
        const response = await fetch(`/${teamSlug.value}/quotations/import-materials`, {
            method: 'POST',
            body: materialFormData(false),
            headers: {
                'X-CSRF-TOKEN': (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content || '',
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
        });
        const data = await response.json().catch(() => ({}));
        if (response.ok && data.success) {
            materialPreview.value = data;
            setMaterialColumns(data); // the columns used, for "Change columns"
            showMaterialColumns.value = false;
        } else {
            const firstError = data?.errors ? (Object.values(data.errors).flat()[0] as string) : null;
            materialImportError.value = firstError || data.message || 'The file could not be read.';
            if (data?.needs_mapping) {
                // The columns could not be found (or the chosen ones hold no materials): let staff choose them.
                setMaterialColumns(data);
                showMaterialColumns.value = !!materialColumns.value;
            } else if (!withColumns) {
                resetMaterialColumns();
            }
        }
    } catch {
        materialImportError.value = 'Network error. Please check your connection and try again.';
    } finally {
        isReadingMaterials.value = false;
    }
};

const applyMaterialColumns = () => {
    if (materialMappingProblem.value || isReadingMaterials.value) return;
    readMaterialFile(true);
};

const createFromMaterials = () => {
    if (!materialImport.value.file || !materialPreview.value || isCreatingFromMaterials.value) return;
    isCreatingFromMaterials.value = true;
    router.post(`/${teamSlug.value}/quotations/import-materials`, materialFormData(true) as any, {
        preserveScroll: true,
        onSuccess: () => {
            showMaterialImportModal.value = false;
        },
        onError: (errors) => {
            materialImportError.value = (Object.values(errors)[0] as string) || 'The quotation could not be created.';
        },
        onFinish: () => {
            isCreatingFromMaterials.value = false;
        },
    });
};

// The official quotation document: the preview, Print, the PDF download and the PNG/JPG export
// all come from the same server-rendered PDF, so they always match.
const documentFrame = ref<HTMLIFrameElement | null>(null);
const documentError = ref('');
const imageBusy = ref<'' | 'png' | 'jpg'>('');
const documentUrl = (kind: 'pdf' | 'png' | 'jpg', download = false) => {
    const id = activeQuotation.value?.id;
    if (!id) return '#';

    return kind === 'pdf'
        ? `/${teamSlug.value}/quotations/${id}/pdf${download ? '?download=1' : ''}`
        : `/${teamSlug.value}/quotations/${id}/image?format=${kind}`;
};

const openPrintModal = (quote: Quotation) => {
    activeQuotation.value = quote;
    documentError.value = '';
    showPrintModal.value = true;
};

// Prints the PDF shown in the preview (no page navigation or buttons on paper).
const triggerPrint = () => {
    try {
        documentFrame.value?.contentWindow?.focus();
        documentFrame.value?.contentWindow?.print();
    } catch {
        // The browser's PDF viewer would not print from the preview: open the PDF to print it there.
        window.open(documentUrl('pdf'), '_blank', 'noopener');
    }
};

const downloadImage = async (format: 'png' | 'jpg') => {
    imageBusy.value = format;
    documentError.value = '';
    try {
        const res = await fetch(documentUrl(format), { credentials: 'same-origin' });
        if (!res.ok) {
            const body = await res.json().catch(() => null);
            throw new Error(body?.message || `The ${format.toUpperCase()} could not be created (error ${res.status}). Download the PDF instead.`);
        }
        const link = document.createElement('a');
        link.href = URL.createObjectURL(await res.blob());
        link.download = `Quotation-${activeQuotation.value?.quotation_number}.${format}`;
        link.click();
        setTimeout(() => URL.revokeObjectURL(link.href), 1000);
    } catch (e: any) {
        documentError.value = e.message;
    } finally {
        imageBusy.value = '';
    }
};

// Delete
const openDeleteModal = (quote: Quotation) => {
    deleteConfirmInput.value = '';
    activeQuotation.value = quote;
    showDeleteModal.value = true;
};

const confirmDelete = () => {
    if (!activeQuotation.value || deleteConfirmInput.value !== 'YES') return;
    router.delete(`/${teamSlug.value}/quotations/${activeQuotation.value.id}`, {
        data: { confirmation: deleteConfirmInput.value },
        onSuccess: () => {
            showDeleteModal.value = false;
        }
    });
};

// Date & Format Helpers
const toInputDate = (dateStr: string | null | undefined): string => {
    if (!dateStr) return '';
    const clean = dateStr.includes('T') ? dateStr.split('T')[0] : dateStr.split(' ')[0];
    const parts = clean.split('-');
    if (parts.length === 3) {
        if (parts[0].length === 4) return clean; // YYYY-MM-DD
        if (parts[2].length === 4) return `${parts[2]}-${parts[0].padStart(2, '0')}-${parts[1].padStart(2, '0')}`; // MM-DD-YYYY -> YYYY-MM-DD
    }
    return clean;
};

// Formats strictly as MONTH-DAY-YEAR (e.g. 10-25-2026 or 01-04-2023)
const formatDate = (dateStr: string | null | undefined): string => {
    if (!dateStr) return '—';
    const clean = dateStr.includes('T') ? dateStr.split('T')[0] : dateStr.split(' ')[0];
    const parts = clean.split('-');
    if (parts.length === 3) {
        if (parts[0].length === 4) {
            // YYYY-MM-DD -> MM-DD-YYYY
            return `${parts[1]}-${parts[2]}-${parts[0]}`;
        }
        return clean;
    }
    const d = new Date(dateStr);
    if (!isNaN(d.getTime())) {
        const mm = String(d.getMonth() + 1).padStart(2, '0');
        const dd = String(d.getDate()).padStart(2, '0');
        const yyyy = d.getFullYear();
        return `${mm}-${dd}-${yyyy}`;
    }
    return dateStr;
};

const isExpired = (validUntil: string | null | undefined, status: string): boolean => {
    if (!validUntil || ['expired', 'rejected', 'accepted'].includes(status)) return false;
    const cleanDate = toInputDate(validUntil);
    const today = new Date().toISOString().slice(0, 10);
    return cleanDate < today;
};

// Stored amounts are USD; shown in the user's display currency (header switch). Price inputs
// stay in USD, the currency the quotation is saved in.
const { formatMoney: formatCurrency, conversionNote } = useCurrency();

// Row Expansion State for Multi-Item Quotes
const expandedQuoteIds = ref<number[]>([]);
const toggleRowExpand = (id: number) => {
    const idx = expandedQuoteIds.value.indexOf(id);
    if (idx > -1) {
        expandedQuoteIds.value.splice(idx, 1);
    } else {
        expandedQuoteIds.value.push(id);
    }
};
const isRowExpanded = (id: number) => expandedQuoteIds.value.includes(id);

const getQuoteTotalQuantity = (quote: Quotation): number => {
    if (!quote.items || quote.items.length === 0) return 0;
    return quote.items.reduce((sum, item) => sum + (Number(item.quantity) || 0), 0);
};

// Flash toasts are handled globally by useFlashToast in AppSidebarLayout

// Live Preview of Generated Quote Number in Create Modal
const currentPreviewQuoteNumber = computed(() => {
    if (isEditing.value && activeQuotation.value) {
        return activeQuotation.value.quotation_number;
    }
    const dStr = form.value.quotation_date;
    let mdy = '';
    if (dStr) {
        const parts = dStr.split('-');
        if (parts.length === 3) {
            // YYYY-MM-DD
            const yy = parts[0].slice(-2);
            const mm = parts[1].padStart(2, '0');
            const dd = parts[2].padStart(2, '0');
            mdy = `${mm}${dd}${yy}`;
        }
    }
    if (!mdy) {
        const now = new Date();
        const mm = String(now.getMonth() + 1).padStart(2, '0');
        const dd = String(now.getDate()).padStart(2, '0');
        const yy = String(now.getFullYear()).slice(-2);
        mdy = `${mm}${dd}${yy}`;
    }
    const sku = form.value.items[0]?.sku || '001';
    const cleanSku = sku.replace(/[^A-Za-z0-9]/g, '').toUpperCase() || '001';
    return `Q${mdy}-${cleanSku}`;
});

// CSV Import Modal State & Methods
const showImportModal = ref(false);
const fileInputRef = ref<HTMLInputElement | null>(null);
const importedFile = ref<File | null>(null);
const isDragging = ref(false);
const importMode = ref<'append' | 'replace'>('append');
const defaultClientName = ref('Direct Customer');
const isValidatingCsv = ref(false);
const isImportingCsv = ref(false);
const csvValidationResult = ref<{
    existing_count?: number;
    total_rows: number;
    valid_count: number;
    invalid_count: number;
    valid_rows: any[];
    invalid_rows: any[];
} | null>(null);
const csvError = ref<string | null>(null);

const openImportModal = () => {
    importedFile.value = null;
    csvValidationResult.value = null;
    csvError.value = null;
    isValidatingCsv.value = false;
    isImportingCsv.value = false;
    showImportModal.value = true;
};

const triggerFileSelect = () => {
    fileInputRef.value?.click();
};

const onFileInputChange = (event: Event) => {
    const target = event.target as HTMLInputElement;
    if (target.files && target.files[0]) {
        processUploadedCsv(target.files[0]);
    }
};

const onFileDrop = (event: DragEvent) => {
    isDragging.value = false;
    if (event.dataTransfer?.files && event.dataTransfer.files[0]) {
        processUploadedCsv(event.dataTransfer.files[0]);
    }
};

const processUploadedCsv = async (file: File) => {
    if (!file.name.toLowerCase().endsWith('.csv') && file.type !== 'text/csv' && file.type !== 'application/vnd.ms-excel') {
        csvError.value = 'Please select a valid comma-separated (.csv) file.';
        importedFile.value = null;
        csvValidationResult.value = null;
        return;
    }

    importedFile.value = file;
    csvError.value = null;
    isValidatingCsv.value = true;
    csvValidationResult.value = null;

    const formData = new FormData();
    formData.append('file', file);
    formData.append('execute', '0');

    const token = (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content || '';

    try {
        const response = await fetch(`/${teamSlug.value}/quotations/import-csv`, {
            method: 'POST',
            body: formData,
            headers: {
                'X-CSRF-TOKEN': token,
                'Accept': 'application/json',
            }
        });
        const data = await response.json();
        if (response.ok && data.success) {
            csvValidationResult.value = data;
        } else {
            csvError.value = data.message || 'Failed to validate CSV structure.';
            csvValidationResult.value = null;
        }
    } catch (err: any) {
        csvError.value = err.message || 'Failed to process CSV file.';
        csvValidationResult.value = null;
    } finally {
        isValidatingCsv.value = false;
    }
};

const downloadSampleCsv = () => {
    const headers = 'DATE,QUOTE NUMBER,QUANTITY,DESCRIPTION,UNIT PRICE,AMOUNT\n';
    const sampleRows = [
        '10-25-2026,Q102526-068SSCR2,5,"0.68mm Stainless Steel Conduit Rod 2m",450.00,2250.00',
        '10-25-2026,Q102526-042BRK01,10,"Circuit Breaker 2P 40A Rail Mounted",320.00,3200.00',
        '01-04-2023,Q010423-068SSCR2,8,"0.68mm Stainless Steel Conduit Rod 2m",450.00,3600.00'
    ].join('\n');

    const blob = new Blob([headers + sampleRows], { type: 'text/csv;charset=utf-8;' });
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = 'mr_pride_quotations_template.csv';
    link.click();
    URL.revokeObjectURL(link.href);
    showToast('Sample CSV template downloaded with required headers!', 'info');
};

const executeCsvImport = () => {
    if (!importedFile.value || !csvValidationResult.value || csvValidationResult.value.valid_count === 0) {
        showToast('No valid rows available to import.', 'error');
        return;
    }

    isImportingCsv.value = true;
    const formData = new FormData();
    formData.append('file', importedFile.value);
    formData.append('mode', importMode.value);
    formData.append('default_client_name', defaultClientName.value);
    formData.append('execute', '1');

    router.post(`/${teamSlug.value}/quotations/import-csv`, formData as any, {
        onSuccess: () => {
            // The server's message says how many were imported and which were skipped.
            showImportModal.value = false;
            isImportingCsv.value = false;
        },
        onError: (err) => {
            isImportingCsv.value = false;
            const msg = Object.values(err)[0] as string;
            showToast(msg || 'Failed to import CSV.', 'error');
        }
    });
};
</script>

<template>
    <Head title="Quotation Management | MRP ElectroDesk" />

    <div class="workspace-page">
        <!-- Page Title & Main Action -->
        <div class="page-header">
            <div>
                <h1 class="page-title">Quotation Management</h1>
                <p class="page-subtitle">Estimates, client approvals and conversion to sales.</p>
            </div>
            <div class="page-actions">
                <div class="rounded-xl border border-gray-200 bg-white p-[3px]" data-test="quotation-import-menu">
                    <GlideSelect
                        :options="importOptions"
                        value=""
                        placeholder="Import"
                        :placeholderMuted="false"
                        ariaLabel="Import"
                        accentColor="#4b5320"
                        surfaceColor="#ffffff"
                        highlightColor="#f3f4f6"
                        textColor="#374151"
                        size="md"
                        :radius="12"
                        :menuWidth="232"
                        align="right"
                        @change="onImportChoice"
                    >
                        <template #leading>
                            <Upload class="size-3.5 text-gray-500" aria-hidden="true" />
                        </template>
                    </GlideSelect>
                </div>

                <SpecularButton
                    variant="brand"
                    size="md"
                    :radius="12"
                    @click="openCreateModal"
                >
                    <Plus class="w-4 h-4" />
                    <span>Create Quotation</span>
                </SpecularButton>
            </div>
        </div>

        <!-- Metrics Overview -->
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
            <div
                v-for="card in [
                    { label: 'Requests to review', value: metrics.pendingCount },
                    { label: 'Waiting for approval', value: metrics.forApprovalCount },
                    { label: 'Accepted, ready for order', value: metrics.acceptedCount },
                    { label: 'Active pipeline value', value: formatCurrency(metrics.totalQuotedValue) },
                ]"
                :key="card.label"
                class="bg-white px-4 py-3 rounded-xl border border-gray-200"
            >
                <p class="text-xs text-gray-500">{{ card.label }}</p>
                <p class="text-2xl font-semibold text-gray-900 mt-1">{{ card.value }}</p>
            </div>
        </div>

        <!-- Filter & Search Controls -->
        <div class="flex flex-col md:flex-row gap-3 items-center justify-between rounded-xl border border-gray-200 bg-white p-4">
            <div class="flex flex-col sm:flex-row gap-3 w-full md:w-auto flex-1">
                <!-- Search -->
                <div class="relative flex-1 sm:max-w-xs">
                    <Search class="w-4 h-4 text-gray-400 absolute left-3 top-3" />
                    <input v-model="search"
                           type="text"
                           placeholder="Search quote #, client, email..."
                           class="field-control w-full pl-9 pr-4" />
                </div>

                <!-- Status Filter -->
                <div class="w-full sm:w-44">
                    <select v-model="selectedStatus"
                            class="field-control w-full">
                        <option value="All">All Statuses</option>
                        <option v-for="opt in statusOptions" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
                    </select>
                </div>

                <!-- Date Range -->
                <div class="flex items-center gap-2">
                    <input v-model="dateFrom" type="date" title="Quotation Date From" class="field-control sm:w-auto" />
                    <span class="text-gray-400 text-xs">to</span>
                    <input v-model="dateTo" type="date" title="Quotation Date To" class="field-control sm:w-auto" />
                </div>
            </div>

            <div class="flex items-center gap-2 w-full md:w-auto justify-end">
                <button v-if="search || selectedStatus !== 'All' || dateFrom || dateTo"
                        @click="resetFilters"
                        class="px-3 py-2 text-xs font-medium text-gray-600 hover:text-gray-900 hover:bg-gray-100 rounded-lg flex items-center gap-1.5 transition-colors">
                    <RotateCcw class="w-3.5 h-3.5" />
                    <span>Reset</span>
                </button>
            </div>
        </div>

        <!-- Quotations Table Card -->
        <div class="flex-1 rounded-xl bg-white border border-gray-200 p-6 flex flex-col justify-between">
            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse">
                    <thead>
                        <tr class="border-b border-gray-200 text-gray-500 text-xs">
                            <th class="pb-3 font-semibold">DATE</th>
                            <th class="pb-3 font-semibold">QUOTE NUMBER</th>
                            <th class="pb-3 font-semibold text-center">QUANTITY</th>
                            <th class="pb-3 font-semibold">DESCRIPTION</th>
                            <th class="pb-3 font-semibold text-right">UNIT PRICE</th>
                            <th class="pb-3 font-semibold text-right">AMOUNT</th>
                            <th class="pb-3 font-semibold text-right">ACTIONS</th>
                        </tr>
                    </thead>
                    <tbody class="text-sm divide-y divide-gray-100">
                        <template v-for="quote in quotations.data" :key="quote.id">
                            <tr class="hover:bg-gray-50/80 transition-colors group">
                                <!-- DATE (MONTH-DAY-YEAR, eg 10-25-2026) -->
                                <td class="py-4 whitespace-nowrap">
                                    <span class="font-semibold text-gray-900 text-xs sm:text-sm">{{ formatDate(quote.quotation_date) }}</span>
                                    <span v-if="isExpired(quote.valid_until, quote.status)" 
                                          class="ml-1.5 px-1.5 py-0.2 rounded text-[10px] bg-amber-100 text-amber-800 font-semibold inline-block">
                                        Expired
                                    </span>
                                </td>

                                <!-- QUOTE NUMBER (eg Q010423-068SSCR2) -->
                                <td class="py-4">
                                    <div class="flex items-center gap-1.5">
                                        <button v-if="quote.items && quote.items.length > 1" 
                                                type="button"
                                                @click="toggleRowExpand(quote.id)" 
                                                class="-m-1 p-1.5 rounded text-gray-400 hover:text-gray-700 hover:bg-gray-100 transition-colors"
                                                :aria-expanded="isRowExpanded(quote.id)"
                                                :title="isRowExpanded(quote.id) ? 'Collapse line items' : 'Expand line items'">
                                            <ChevronRight :class="['w-3.5 h-3.5 transition-transform duration-150', isRowExpanded(quote.id) ? 'rotate-90 text-[#4b5320]' : '']" />
                                        </button>
                                        <div>
                                            <span class="font-mono text-xs sm:text-sm font-semibold text-gray-900 block">{{ quote.quotation_number }}</span>
                                            <div class="flex items-center gap-1.5 mt-0.5">
                                                <StatusBadge :status="quote.status_label ?? quote.status" size="sm" />
                                                <span v-if="quote.status === 'draft' && quote.is_approved" class="rounded-md border border-blue-200 bg-blue-50 px-1.5 py-0.5 text-[10px] font-medium text-blue-700">Approved, not sent</span>
                                                <span v-else-if="quote.status === 'draft' && quote.submitted_for_approval_at" class="rounded-md border border-amber-200 bg-amber-50 px-1.5 py-0.5 text-[10px] font-medium text-amber-700">Awaiting approval</span>
                                                <span v-else-if="quote.waiting_for_stock?.length" class="rounded-md border border-amber-200 bg-amber-50 px-1.5 py-0.5 text-[10px] font-medium text-amber-700" :title="`Being purchased: ${quote.waiting_for_stock.join(', ')}`">Waiting for stock</span>
                                                <span class="text-[11px] text-gray-500 truncate max-w-[130px]" :title="quote.client_name">{{ quote.client_name }}</span>
                                            </div>
                                        </div>
                                    </div>
                                </td>

                                <!-- QUANTITY -->
                                <td class="py-4 text-center">
                                    <span class="font-semibold text-sm text-gray-900">{{ getQuoteTotalQuantity(quote) }}</span>
                                    <span v-if="quote.items && quote.items.length > 1" class="block text-[10px] text-gray-400">
                                        ({{ quote.items.length }} items)
                                    </span>
                                </td>

                                <!-- DESCRIPTION -->
                                <td class="py-4">
                                    <div v-if="quote.items && quote.items.length > 0">
                                        <p class="text-xs sm:text-sm text-gray-900 font-semibold leading-snug line-clamp-1" :title="quote.items[0].product_name">
                                            {{ quote.items[0].product_name }}
                                        </p>
                                        <div class="flex items-center gap-1.5 mt-0.5">
                                            <span class="font-mono text-[10px] text-gray-500 bg-gray-100 px-1.5 py-0.2 rounded border border-gray-200">
                                                {{ quote.items[0].sku }}
                                            </span>
                                            <button v-if="quote.items.length > 1" 
                                                    type="button"
                                                    class="text-[11px] text-[#4b5320] font-semibold hover:underline flex items-center gap-0.5" 
                                                    @click="toggleRowExpand(quote.id)">
                                                <span>+{{ quote.items.length - 1 }} more</span>
                                            </button>
                                        </div>
                                    </div>
                                    <div v-else class="text-gray-400 text-xs italic">
                                        {{ quote.notes || '—' }}
                                    </div>
                                </td>

                                <!-- UNIT PRICE -->
                                <td class="py-4 text-right whitespace-nowrap">
                                    <div v-if="quote.items && quote.items.length === 1">
                                        <span class="text-xs sm:text-sm font-semibold text-gray-800">
                                            {{ formatCurrency(quote.items[0].unit_price) }}
                                        </span>
                                    </div>
                                    <div v-else-if="quote.items && quote.items.length > 1">
                                        <span class="text-xs font-semibold text-gray-700 block">
                                            {{ formatCurrency(quote.items[0].unit_price) }}
                                        </span>
                                        <span class="text-[10px] text-gray-400 block font-normal">(first item)</span>
                                    </div>
                                    <div v-else class="text-gray-400 text-xs">—</div>
                                </td>

                                <!-- AMOUNT -->
                                <td class="py-4 text-right whitespace-nowrap">
                                    <span class="text-sm sm:text-base font-semibold text-gray-900 block">{{ formatCurrency(quote.grand_total || quote.subtotal) }}</span>
                                    <a v-if="quote.sale" :href="`/${teamSlug}/sales/${quote.sale.id}`" class="block text-[10px] text-blue-600 font-mono font-semibold hover:underline">
                                        {{ quote.sale.order_number }}
                                    </a>
                                    <span v-else-if="quote.email_status && quote.email_status !== 'not_sent'" class="block text-[10px]" :class="quote.email_status === 'failed' ? 'text-rose-600 font-semibold' : 'text-gray-500'">
                                        Email: {{ quote.email_status_label }}
                                    </span>
                                </td>

                                <!-- ACTIONS -->
                                <td class="py-4 text-right whitespace-nowrap">
                                    <div class="inline-flex items-center gap-1.5">
                                        <!-- The next step for this quotation -->
                                        <SpecularButton v-if="can(quote, 'request_stock')" variant="secondary" size="sm" :radius="8" :disabled="workflowBusy" @click="promptRequestStock(quote)" title="Some products are short: request a purchase for the missing quantities">
                                            <span>Request stock</span>
                                        </SpecularButton>
                                        <SpecularButton v-if="can(quote, 'approve')" variant="brand" size="sm" :radius="8" :disabled="workflowBusy" @click="promptApprove(quote)" title="Approve this quotation (administrator)">
                                            <CheckCircle2 class="w-3.5 h-3.5" />
                                            <span>Approve</span>
                                        </SpecularButton>
                                        <SpecularButton v-if="can(quote, 'send')" variant="brand" size="sm" :radius="8" :disabled="workflowBusy" @click="promptSend(quote)" title="Email the quotation PDF to the customer">
                                            <Mail class="w-3.5 h-3.5" />
                                            <span>Send</span>
                                        </SpecularButton>
                                        <SpecularButton v-if="can(quote, 'decision')" variant="secondary" size="sm" :radius="8" @click="openDecisionModal(quote)" title="Record the customer's acceptance or rejection">
                                            <span>Customer answer</span>
                                        </SpecularButton>
                                        <button v-if="can(quote, 'resend')" @click="promptSend(quote)" :disabled="workflowBusy" title="Send the quotation again"
                                                class="p-1.5 text-gray-600 hover:text-gray-900 hover:bg-gray-100 rounded-lg transition-colors">
                                            <Mail class="w-4 h-4" />
                                        </button>
                                        <SpecularButton
                                            v-if="can(quote, 'convert')"
                                            variant="success"
                                            size="sm"
                                            :radius="8"
                                            @click="openConvertModal(quote)"
                                            title="Generate the Sales Order from this accepted quotation"
                                        >
                                            <ShoppingCart class="w-3.5 h-3.5" />
                                            <span>Generate Sales Order</span>
                                        </SpecularButton>

                                        <!-- Review what the client sent -->
                                        <button @click="openViewModal(quote)"
                                                title="Review request details and files"
                                                aria-label="Review request"
                                                class="p-1.5 text-gray-600 hover:text-gray-900 hover:bg-gray-100 rounded-lg transition-colors relative">
                                            <Eye class="w-4 h-4" />
                                            <span v-if="hasClientFiles(quote)" class="absolute top-1 right-1 size-1.5 rounded-full bg-[#4b5320]" aria-hidden="true"></span>
                                        </button>

                                        <!-- Update Status Quick Action -->
                                        <button v-if="quote.allowed_actions?.some((a) => a.startsWith('status:'))" @click="openStatusModal(quote)" 
                                                title="Update Status"
                                                class="px-2 py-1 bg-white border border-gray-200 hover:border-[#F8B803] text-gray-700 rounded-lg text-xs transition-colors">
                                            Status
                                        </button>

                                        <!-- Print / Preview -->
                                        <button @click="openPrintModal(quote)" 
                                                title="Quotation document: preview, print, PDF, PNG/JPG"
                                                class="p-1.5 text-gray-600 hover:text-gray-900 hover:bg-gray-100 rounded-lg transition-colors">
                                            <Printer class="w-4 h-4 text-[#4b5320]" />
                                        </button>

                                        <!-- Edit (only while it is being prepared) -->
                                        <button v-if="can(quote, 'edit')" @click="openEditModal(quote)" 
                                                title="Edit Quotation"
                                                class="p-1.5 text-gray-600 hover:text-[#4b5320] hover:bg-[#F8B803]/10 rounded-lg transition-colors">
                                            <Pencil class="w-4 h-4" />
                                        </button>

                                        <!-- Delete (Only Draft/Cancelled/Rejected) -->
                                        <button v-if="canDelete" @click="openDeleteModal(quote)" 
                                                title="Delete Quotation"
                                                class="p-1.5 text-gray-400 hover:text-rose-600 hover:bg-rose-50 rounded-lg transition-colors">
                                            <Trash2 class="w-4 h-4" />
                                        </button>
                                    </div>
                                </td>
                            </tr>

                            <!-- Expanded Details for Multi-Item Quotes -->
                            <tr v-if="isRowExpanded(quote.id)" class="bg-gray-50/70 border-b border-gray-100">
                                <td colspan="7" class="p-3 pl-8">
                                    <div class="rounded-xl border border-gray-200 bg-white p-3.5">
                                        <div class="text-[11px] font-semibold text-gray-500 mb-2 flex items-center justify-between">
                                            <span>Quotation Line Items ({{ quote.items.length }})</span>
                                            <span class="text-gray-400 font-mono text-[10px]">{{ quote.quotation_number }}</span>
                                        </div>
                                        <table class="w-full text-left text-xs">
                                            <thead>
                                                <tr class="text-gray-400 border-b border-gray-100 pb-1 text-[11px]">
                                                    <th class="pb-1 font-medium">SKU</th>
                                                    <th class="pb-1 font-medium">Description</th>
                                                    <th class="pb-1 font-medium text-center">Quantity</th>
                                                    <th class="pb-1 font-medium text-right">Unit Price</th>
                                                    <th class="pb-1 font-medium text-right">Amount</th>
                                                    <th v-if="quote.status === 'draft'" class="pb-1 pl-3 font-medium">Stock</th>
                                                </tr>
                                            </thead>
                                            <tbody class="divide-y divide-gray-50">
                                                <tr v-for="(item, idx) in quote.items" :key="idx" class="hover:bg-gray-50/50">
                                                    <td class="py-1.5 font-mono text-gray-600 text-[11px]">{{ item.sku }}</td>
                                                    <td class="py-1.5 text-gray-900 font-medium">{{ item.product_name }}</td>
                                                    <td class="py-1.5 text-center font-semibold text-gray-800">{{ item.quantity }}</td>
                                                    <td class="py-1.5 text-right text-gray-700">{{ formatCurrency(item.unit_price) }}</td>
                                                    <td class="py-1.5 text-right font-semibold text-gray-900">{{ formatCurrency(item.subtotal || (item.quantity * item.unit_price)) }}</td>
                                                    <td v-if="quote.status === 'draft'" class="py-1.5 pl-3 text-[11px]" :class="['Insufficient Stock', 'Not Available'].includes(item.stock_status ?? '') ? 'text-amber-700' : 'text-gray-500'">
                                                        {{ item.stock_status ?? '—' }}<template v-if="item.purchase"> · {{ item.purchase.number }} ({{ item.purchase.status }})</template>
                                                    </td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>
                                </td>
                            </tr>
                        </template>

                        <!-- Empty State -->
                        <tr v-if="quotations.data.length === 0">
                            <td colspan="7" class="py-14 text-center text-gray-400">
                                <FileText class="w-12 h-12 mx-auto mb-2 text-gray-300" />
                                <p class="text-base font-semibold text-gray-700">No Quotations Found</p>
                                <p class="text-xs text-gray-400 mt-1 max-w-sm mx-auto">
                                    No quotations match the active filters. Click "+ Create Quotation" to generate a new customer estimate.
                                </p>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Pagination Bar -->
            <div v-if="quotations.total > 0" class="mt-5 pt-4 border-t border-gray-100 flex flex-col sm:flex-row justify-between items-center gap-3 text-sm text-gray-500">
                <span>
                    Showing <strong class="text-gray-800">{{ (quotations.current_page - 1) * 10 + 1 }}</strong> to 
                    <strong class="text-gray-800">{{ Math.min(quotations.current_page * 10, quotations.total) }}</strong> of 
                    <strong class="text-gray-800">{{ quotations.total }}</strong> quotations
                </span>
                <div class="flex items-center gap-2">
                    <button v-for="(link, i) in quotations.links" 
                            :key="i"
                            :disabled="!link.url || link.active"
                            @click="link.url && router.get(link.url, {}, { only: ['quotations', 'filters', 'metrics'] })"
                            v-html="link.label"
                            :class="[
                                link.active ? 'bg-[#4b5320] text-white font-semibold' : 'bg-white text-gray-700 border border-gray-200 hover:bg-gray-50',
                                !link.url ? 'opacity-40 cursor-not-allowed' : 'cursor-pointer'
                            ]"
                            class="px-3 py-1 text-xs rounded-lg transition-colors">
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- ========================================================================= -->
    <!-- CREATE / EDIT QUOTATION MODAL -->
    <!-- ========================================================================= -->
    <div v-if="showCreateEditModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4 overflow-y-auto">
        <div class="bg-white border border-gray-200 rounded-xl w-full max-w-5xl shadow-xl overflow-hidden flex flex-col max-h-[92vh] animate-in fade-in duration-150">
            <!-- Modal Header -->
            <div class="px-6 py-4 border-b border-gray-100 flex justify-between items-center bg-gray-50/70">
                <div class="flex items-center gap-3">
                    <div class="p-2 bg-[#F8B803]/20 text-[#4b5320] rounded-lg">
                        <FileText class="w-6 h-6" />
                    </div>
                    <div>
                        <h3 class="text-xl font-semibold text-gray-900">
                            {{ isEditing ? `Edit Quotation (${form.id})` : 'Create New Quotation' }}
                        </h3>
                        <p class="text-xs text-gray-500">Select items from inventory with live pricing and dynamic total calculations</p>
                    </div>
                </div>
                <button @click="showCreateEditModal = false" class="text-gray-400 hover:text-gray-600 p-1.5 rounded-lg hover:bg-gray-100">
                    <X class="w-5 h-5" />
                </button>
            </div>

            <!-- Error Banner -->
            <div v-if="formError" class="mx-6 mt-4 p-3.5 bg-rose-50 border border-rose-200 text-rose-700 text-xs rounded-xl flex items-center gap-2">
                <AlertCircle class="w-4 h-4 shrink-0 text-rose-600" />
                <span class="font-medium">{{ formError }}</span>
            </div>

            <!-- Modal Body -->
            <div class="p-6 overflow-y-auto space-y-6">
                <!-- Section 1: Client Information -->
                <div class="bg-gray-50/50 p-4 rounded-xl border border-gray-200">
                    <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-2 mb-3">
                        <h4 class="text-xs font-semibold text-gray-700 flex items-center gap-1.5">
                            <span>Client / Customer Information</span>
                        </h4>

                        <!-- Quick Select Existing Client (floating label driven by focus and the selected value) -->
                        <div class="relative w-full sm:w-64" data-test="existing-client">
                            <select
                                id="q_existing_client"
                                v-model="selectedExistingClientId"
                                class="field-control pt-5 pb-1.5"
                                @focus="existingClientFocused = true"
                                @blur="existingClientFocused = false"
                            >
                                <!-- Empty while the label sits inside the field, so the two never overlap -->
                                <option value="">{{ existingClientLabelUp ? '-- Choose Existing Client --' : '' }}</option>
                                <option v-for="c in clients" :key="c.id" :value="c.id">{{ c.company_name }}</option>
                            </select>
                            <label
                                for="q_existing_client"
                                class="pointer-events-none absolute top-3.5 left-3.5 origin-[0] text-sm font-medium transition-all duration-200"
                                :class="[existingClientLabelUp ? FLOAT_LABEL_UP : '', existingClientFocused ? 'text-[#4b5320]' : 'text-gray-500']"
                            >
                                Load Existing Client
                            </label>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                        <div class="relative">
                            <input id="q_client_name" v-model="form.client_name" type="text" placeholder=" " :class="FLOAT_FIELD" required />
                            <label for="q_client_name" :class="FLOAT_LABEL">Company / Client Name <span class="text-rose-600">*</span></label>
                        </div>
                        <div class="relative">
                            <input id="q_contact_person" v-model="form.contact_person" type="text" placeholder=" " :class="FLOAT_FIELD" />
                            <label for="q_contact_person" :class="FLOAT_LABEL">Contact Person</label>
                        </div>
                        <div class="relative">
                            <input id="q_email" v-model="form.email" type="email" placeholder=" " :class="FLOAT_FIELD" />
                            <label for="q_email" :class="FLOAT_LABEL">Email Address</label>
                        </div>
                        <div class="relative">
                            <input id="q_phone" v-model="form.phone" type="text" placeholder=" " :class="FLOAT_FIELD" />
                            <label for="q_phone" :class="FLOAT_LABEL">Contact / Phone Number</label>
                        </div>
                        <div class="relative md:col-span-2">
                            <input id="q_address" v-model="form.address" type="text" placeholder=" " :class="FLOAT_FIELD" />
                            <label for="q_address" :class="FLOAT_LABEL">Project Site / Billing Address</label>
                        </div>
                    </div>
                </div>

                <!-- Section 2: Quotation Metadata -->
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                    <h4 class="sm:col-span-3 text-xs font-semibold text-gray-700">Quotation Details</h4>
                    <!-- Quotation Number Badge Preview -->
                    <div class="sm:col-span-3 px-3.5 py-2 rounded-xl border border-amber-200/80 bg-amber-50/50 flex flex-col sm:flex-row sm:items-center justify-between gap-1.5">
                        <div class="flex items-center gap-2">
                            <span class="text-xs font-semibold text-[#4b5320]">Quotation Number:</span>
                            <span class="font-mono text-sm font-semibold text-gray-900 bg-white px-2 py-0.5 rounded border border-amber-200">{{ currentPreviewQuoteNumber }}</span>
                        </div>
                        <span class="text-[11px] text-gray-500">Format: Q&lt;MMDDYY&gt;-&lt;PRODUCT_ID&gt; (eg Q010423-068SSCR2)</span>
                    </div>

                    <!-- Quotation Date: set by the system -->
                    <div>
                        <span class="field-label">Quotation Date</span>
                        <div class="field-control flex items-center bg-gray-50 text-gray-700">{{ formatDate(form.quotation_date) }}</div>
                        <p class="mt-1 text-[11px] text-gray-500">{{ isEditing ? 'Set when the quotation was created.' : 'Auto-generated: today.' }}</p>
                    </div>

                    <!-- Valid Until: set when approved -->
                    <div>
                        <span class="field-label">Valid Until</span>
                        <div class="field-control flex items-center bg-gray-50" :class="form.valid_until ? 'text-gray-700' : 'text-gray-400'">
                            {{ form.valid_until ? formatDate(form.valid_until) : 'Set when approved' }}
                        </div>
                        <p class="mt-1 text-[11px] text-gray-500">Automatically {{ quotationValidityDays ?? 3 }} days after the quotation is approved.</p>
                    </div>

                    <!-- Status: a new quotation is a Draft; approval is a separate step -->
                    <div>
                        <label for="q_status" class="field-label">Status</label>
                        <div v-if="!isEditing" class="field-control flex items-center bg-gray-50 text-gray-700">Draft</div>
                        <select v-else id="q_status" v-model="form.status" class="field-control">
                            <option v-for="s in formStatusChoices" :key="s.value" :value="s.value">{{ s.label }}</option>
                        </select>
                    </div>
                </div>

                <!-- Section 3: Quotation Line Items (Inventory Integration) -->
                <div>
                    <div class="flex justify-between items-center mb-2 pb-1 border-b border-gray-100">
                        <div>
                            <h4 class="text-xs font-semibold text-gray-700 flex items-center gap-1.5">
                                <span>Quotation Line Items</span>
                            </h4>
                            <p class="text-[11px] text-gray-400">Products are populated directly from inventory with real-time stock verification.</p>
                        </div>
                        <button type="button" @click="addItem" class="px-3 py-1.5 bg-[#F8B803]/20 hover:bg-[#F8B803]/30 text-[#4b5320] font-semibold rounded-lg text-xs transition-colors flex items-center gap-1">
                            <Plus class="w-3.5 h-3.5" />
                            <span>Add Product</span>
                        </button>
                    </div>

                    <!-- Items Table -->
                    <div class="border border-gray-200 rounded-xl overflow-x-auto">
                        <table class="w-full text-left text-xs border-collapse">
                            <thead class="bg-gray-50 text-gray-600 border-b border-gray-200">
                                <tr>
                                    <th class="p-2.5 font-semibold w-5/12">Description</th>
                                    <th class="p-2.5 font-semibold w-24 text-center">Stock</th>
                                    <th class="p-2.5 font-semibold w-20">Quantity *</th>
                                    <th class="p-2.5 font-semibold w-44" title="Internal only: never shown to the customer">Supplier &amp; Unit Cost (USD)</th>
                                    <th class="p-2.5 font-semibold w-28">Unit Price (USD)</th>
                                    <th class="p-2.5 font-semibold w-24">Discount (USD)</th>
                                    <th class="p-2.5 font-semibold text-right w-28">Subtotal</th>
                                    <th class="p-2.5 text-center w-10"></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 bg-white">
                                <tr v-for="(item, index) in form.items" :key="index" class="hover:bg-gray-50/60 transition-colors">
                                    <!-- Product Dropdown -->
                                    <td class="p-2.5">
                                        <select :value="item.product_id" 
                                                @change="onProductSelect(index, Number(($event.target as HTMLSelectElement).value))"
                                                class="w-full bg-white border border-gray-200 rounded p-1.5 text-xs text-gray-900 focus:border-[#F8B803] focus:outline-none mb-1">
                                            <option :value="null">-- Select Product from Inventory --</option>
                                            <option v-for="p in products" :key="p.id" :value="p.id">
                                                {{ p.name }} ({{ p.sku }}) - {{ formatCurrency(p.price) }} [Stock: {{ p.stock }}]
                                            </option>
                                        </select>
                                        <div class="flex items-center gap-2">
                                            <input v-model="item.product_name" type="text" placeholder="Description" aria-label="Description" class="flex-1 bg-gray-50/50 border border-gray-200 rounded p-1 text-[11px] text-gray-900 focus:outline-none" />
                                            <input v-model="item.sku" type="text" placeholder="SKU" class="w-28 font-mono bg-gray-50/50 border border-gray-200 rounded p-1 text-[11px] text-gray-500 focus:outline-none" />
                                        </div>
                                    </td>

                                    <!-- Stock & Stock Warning -->
                                    <td class="p-2.5 text-center">
                                        <span v-if="item.stock === null" class="text-gray-400">—</span>
                                        <template v-else>
                                            <span :class="item.stock <= 0 ? 'bg-rose-100 text-rose-700' : ((Number(item.quantity) || 0) > item.stock ? 'bg-amber-100 text-amber-800' : 'bg-emerald-100 text-emerald-700')"
                                                  class="px-2 py-0.5 rounded-full text-[10px] font-semibold block">
                                                {{ item.stock }} available
                                            </span>
                                            <span v-if="(Number(item.quantity) || 0) > item.stock" class="text-[10px] text-rose-600 font-semibold block mt-1 leading-tight">
                                                ⚠️ Insufficient stock
                                            </span>
                                        </template>
                                    </td>

                                    <!-- Qty -->
                                    <td class="p-2.5">
                                        <input v-model.number="item.quantity" type="number" min="1" class="w-full bg-white border border-gray-200 rounded p-1.5 text-xs text-center font-semibold text-gray-900 focus:border-[#F8B803] focus:outline-none" />
                                    </td>

                                    <!-- Supplier cost (internal) -->
                                    <td class="p-2.5">
                                        <label class="sr-only" :for="`q-supplier-${index}`">Select Supplier</label>
                                        <select :id="`q-supplier-${index}`" v-model="item.supplier_id" @change="onSupplierSelect(index)" class="mb-1 w-full bg-white border border-gray-200 rounded p-1.5 text-xs text-gray-900 focus:border-[#F8B803] focus:outline-none" data-test="line-supplier">
                                            <option :value="null">Select Supplier</option>
                                            <option v-for="s in suppliers ?? []" :key="s.id" :value="s.id">{{ s.name }}{{ s.status && s.status !== 'Active' ? ` (${s.status})` : '' }}</option>
                                        </select>
                                        <input v-model.number="item.unit_cost" type="number" min="0" step="0.01" placeholder="—" class="w-full bg-gray-50 border border-gray-200 rounded p-1.5 text-xs text-right text-gray-700 focus:border-[#F8B803] focus:outline-none" />
                                        <span v-if="costNote(item)" class="block text-[10px] mt-0.5 leading-tight" :class="costNote(item)?.missing ? 'text-amber-700 font-semibold' : 'text-gray-500'" data-test="cost-note">{{ costNote(item)?.text }}<template v-if="costNote(item)?.missing"> – enter the cost manually</template></span>
                                        <span v-if="lineMargin(item) !== null" class="block text-[10px] mt-0.5 text-right" :class="(lineMargin(item) ?? 0) < 0 ? 'text-rose-600 font-semibold' : 'text-gray-500'">Margin {{ lineMargin(item) }}%</span>
                                    </td>

                                    <!-- Unit Price -->
                                    <td class="p-2.5">
                                        <input v-model.number="item.unit_price" type="number" min="0" step="0.01" class="w-full bg-white border border-gray-200 rounded p-1.5 text-xs text-right font-medium text-gray-900 focus:border-[#F8B803] focus:outline-none" />
                                    </td>

                                    <!-- Discount -->
                                    <td class="p-2.5">
                                        <input v-model.number="item.discount" type="number" min="0" step="0.01" class="w-full bg-white border border-gray-200 rounded p-1.5 text-xs text-right text-gray-600 focus:border-[#F8B803] focus:outline-none" />
                                    </td>

                                    <!-- Item Subtotal -->
                                    <td class="p-2.5 text-right font-mono font-semibold text-gray-900">
                                        {{ formatCurrency(lineSubtotal(item)) }}
                                    </td>

                                    <!-- Delete Item -->
                                    <td class="p-2.5 text-center">
                                        <button type="button" @click="removeItem(index)" class="text-gray-400 hover:text-rose-600 p-1 rounded hover:bg-rose-50" :disabled="form.items.length === 1">
                                            <Trash2 class="w-3.5 h-3.5" />
                                        </button>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Section 4: Notes, Terms, & Financial Calculation Summary -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6 pt-2 border-t border-gray-100">
                    <!-- Left: Notes & Terms -->
                    <div class="space-y-3">
                        <h4 class="text-xs font-semibold text-gray-700">Additional Information</h4>
                        <div>
                            <label for="q_notes" class="field-label">Internal Notes / Scope Description</label>
                            <textarea id="q_notes" v-model="form.notes" rows="3" placeholder="Enter notes" class="field-control"></textarea>
                        </div>

                        <!-- Fixed terms: the same on every quotation, not editable -->
                        <div>
                            <span class="field-label">Terms &amp; Conditions</span>
                            <div class="max-h-56 overflow-y-auto rounded-xl border border-gray-200 bg-gray-50 px-3.5 py-3 text-[11px] leading-relaxed text-gray-700">
                                <p class="mb-1.5 font-semibold text-gray-900">TERMS AND CONDITIONS</p>
                                <ol class="list-decimal space-y-1 pl-4">
                                    <li v-for="(term, i) in quotationTerms ?? []" :key="i" class="whitespace-pre-line">{{ term }}</li>
                                </ol>
                            </div>
                        </div>
                    </div>

                    <!-- Right: Dynamic Totals Panel -->
                    <div class="bg-gray-50/80 p-4 rounded-xl border border-gray-200 flex flex-col justify-between">
                        <h4 class="text-xs font-semibold text-gray-700 mb-3">Quotation Summary</h4>

                        <div class="space-y-2 text-xs">
                            <div class="flex justify-between text-gray-600">
                                <span>Items Subtotal:</span>
                                <span class="font-mono font-semibold">{{ formatCurrency(formSubtotal) }}</span>
                            </div>

                            <div class="flex justify-between items-center text-gray-600">
                                <label for="q_discount">Quotation Discount:</label>
                                <input id="q_discount" v-model.number="form.discount" type="number" min="0" step="0.01" placeholder="0.00" class="w-24 bg-white border border-gray-200 rounded p-1 text-xs text-right font-mono font-semibold text-rose-600 focus:outline-none" />
                            </div>

                            <div class="flex justify-between items-center text-gray-600">
                                <div class="flex items-center gap-1">
                                    <label for="q_vat">VAT (%):</label>
                                </div>
                                <div class="flex items-center gap-2">
                                    <input id="q_vat" v-model.number="form.tax_rate" type="number" min="0" max="100" step="0.5" class="w-16 bg-white border border-gray-200 rounded p-1 text-xs text-right font-mono text-gray-800 focus:outline-none" />
                                    <span class="font-mono font-semibold">{{ formatCurrency(formTaxAmount) }}</span>
                                </div>
                            </div>

                            <div class="pt-3 border-t border-gray-200 flex justify-between items-baseline">
                                <span class="text-sm font-semibold text-gray-900">Grand Total:</span>
                                <span class="text-2xl font-semibold text-[#4b5320] font-mono">{{ formatCurrency(formGrandTotal) }}</span>
                            </div>
                        </div>

                        <div class="mt-4 p-2 bg-emerald-50 rounded-lg border border-emerald-200 text-[11px] text-emerald-800 flex items-center gap-1.5">
                            <CheckCircle2 class="w-3.5 h-3.5 shrink-0 text-emerald-600" />
                            <span>Creating a quotation does not deduct inventory stock.</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Modal Footer -->
            <div class="px-6 py-4 border-t border-gray-100 bg-gray-50 flex justify-end items-center gap-3">
                <SpecularButton
                    type="button"
                    variant="secondary"
                    size="md"
                    :radius="10"
                    @click="showCreateEditModal = false"
                >
                    Cancel
                </SpecularButton>
                <SpecularButton
                    type="button" 
                    variant="brand"
                    size="md"
                    :radius="10"
                    @click="promptSaveQuotation" 
                    :disabled="isSubmitting"
                >
                    <Check class="w-4 h-4" />
                    <span>{{ isEditing ? 'Update Quotation' : 'Save Quotation' }}</span>
                </SpecularButton>
            </div>
        </div>
    </div>

    <!-- ========================================================================= -->
    <!-- UPDATE STATUS MODAL -->
    <!-- ========================================================================= -->
    <div v-if="showStatusModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
        <div class="bg-white border border-gray-200 p-6 rounded-xl w-full max-w-md shadow-xl animate-in fade-in duration-150">
            <h3 class="text-lg font-semibold text-gray-900 mb-1">Update Quotation Status</h3>
            <p class="text-xs text-gray-500 mb-4">Quotation: {{ activeQuotation?.quotation_number }} ({{ activeQuotation?.client_name }})</p>

            <div class="space-y-3">
                <label class="block text-xs font-semibold text-gray-700">Select New Status</label>
                <p class="text-[11px] text-gray-500">Approval, sending and the customer's answer have their own buttons.</p>
                <div class="grid grid-cols-1 gap-2">
                    <label v-for="opt in manualStatusOptions" :key="opt.value"
                           :class="statusBlockedReason(opt.value)
                               ? 'border-gray-200 bg-gray-50 cursor-not-allowed'
                               : targetStatus === opt.value ? 'border-[#F8B803] bg-[#F8B803]/10 ring-1 ring-[#F8B803] cursor-pointer' : 'border-gray-200 bg-white cursor-pointer'"
                           class="border rounded-xl p-3 transition-colors">
                        <div class="flex items-center gap-2">
                            <input type="radio" v-model="targetStatus" :value="opt.value" :disabled="!!statusBlockedReason(opt.value)" class="accent-[#4b5320]" @change="statusError = null" />
                            <span class="text-sm font-semibold" :class="statusBlockedReason(opt.value) ? 'text-gray-400' : 'text-gray-900'">{{ opt.label }}</span>
                        </div>
                        <p v-if="statusBlockedReason(opt.value)" class="mt-1.5 pl-6 text-xs text-amber-700">
                            {{ statusBlockedReason(opt.value) }}
                            <button type="button" class="font-semibold text-[#4b5320] underline hover:no-underline" @click.prevent="priceFromStatus">Edit &amp; price items</button>
                        </p>
                    </label>
                    <p v-if="!manualStatusOptions.length" class="text-sm text-gray-500">No status change is available for this quotation right now.</p>
                </div>
                <div v-if="statusError" role="alert" class="flex items-start gap-2 rounded-xl border border-rose-200 bg-rose-50 p-3 text-xs text-rose-700">
                    <AlertCircle class="mt-0.5 size-4 shrink-0" />
                    <span>{{ statusError }}</span>
                </div>
                <textarea v-model="statusRemarks" rows="2" maxlength="1000" placeholder="Remarks (optional), e.g. waiting for supplier price on item 3" class="field-control"></textarea>
            </div>

            <div class="flex justify-end gap-3 mt-6 pt-3 border-t border-gray-100">
                <SpecularButton
                    type="button"
                    variant="secondary"
                    size="md"
                    :radius="10"
                    @click="showStatusModal = false"
                >
                    Cancel
                </SpecularButton>
                <SpecularButton
                    type="button"
                    variant="brand"
                    size="md"
                    :radius="10"
                    :disabled="isUpdatingStatus || !targetStatus"
                    @click="promptUpdateStatus"
                >
                    {{ isUpdatingStatus ? 'Updating...' : 'Update Status' }}
                </SpecularButton>
            </div>
        </div>
    </div>

    <!-- ========================================================================= -->
    <!-- GENERATE SALES ORDER -->
    <!-- ========================================================================= -->
    <div v-if="showConvertModal && activeQuotation" class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4">
        <div class="max-h-[90vh] w-full max-w-lg overflow-y-auto rounded-xl border border-gray-200 bg-white p-6 shadow-xl">
            <h3 class="text-lg font-semibold text-gray-900">Generate Sales Order</h3>
            <p class="mt-1 text-xs text-gray-500">From accepted quotation {{ activeQuotation.quotation_number }} for {{ activeQuotation.client_name }}.</p>

            <div class="mt-4 space-y-2 rounded-xl border border-gray-200 bg-gray-50 p-4 text-xs">
                <div class="flex justify-between"><span class="text-gray-500">Items</span><span class="font-semibold text-gray-900">{{ activeQuotation.items.length }} line(s), {{ getQuoteTotalQuantity(activeQuotation) }} unit(s)</span></div>
                <div class="flex justify-between"><span class="text-gray-500">Total (copied from the quotation)</span><span class="font-semibold text-gray-900">{{ formatCurrency(activeQuotation.grand_total) }}</span></div>
                <div class="flex justify-between"><span class="text-gray-500">Customer email</span><span class="font-semibold text-gray-900">{{ activeQuotation.email || 'None: no email will be sent' }}</span></div>
            </div>

            <div class="mt-4 space-y-4">
                <div>
                    <span class="field-label">Fulfillment</span>
                    <div class="grid grid-cols-2 gap-2">
                        <label v-for="opt in [{ v: 'delivery', l: 'Delivery' }, { v: 'pickup', l: 'Customer pickup' }]" :key="opt.v"
                               :class="convertFulfillmentType === opt.v ? 'border-[#4b5320] bg-[#4b5320]/5' : 'border-gray-200'"
                               class="flex cursor-pointer items-center gap-2 rounded-xl border p-3 text-sm">
                            <input v-model="convertFulfillmentType" type="radio" :value="opt.v" class="accent-[#4b5320]" />
                            {{ opt.l }}
                        </label>
                    </div>
                </div>
                <div v-if="convertFulfillmentType === 'delivery'">
                    <label class="field-label" for="convert-address">Delivery address</label>
                    <textarea id="convert-address" v-model="convertDeliveryAddress" rows="2" maxlength="500" class="field-control"></textarea>
                </div>
                <div>
                    <label class="field-label" for="convert-notes">Delivery or pickup instructions (optional)</label>
                    <textarea id="convert-notes" v-model="convertDeliveryInstructions" rows="2" maxlength="1000" class="field-control"></textarea>
                </div>
            </div>

            <div v-if="lowStockLines.length" class="mt-4 rounded-xl border border-amber-200 bg-amber-50 p-3 text-xs text-amber-900">
                <p class="font-semibold">Not enough available stock for:</p>
                <ul class="mt-1 list-disc pl-4">
                    <li v-for="i in lowStockLines" :key="i.id ?? i.sku">{{ i.product_name }}: {{ i.available_stock ?? 0 }} available, {{ i.quantity }} ordered</li>
                </ul>
                <p class="mt-1">The order can still be created; what is available is reserved when it is confirmed.</p>
            </div>

            <p class="mt-4 text-xs text-gray-600">
                The order starts as <strong>Pending Payment</strong>. Nothing is taken from stock and no payment is recorded now.
                The customer is not emailed at this step.
            </p>

            <div class="mt-6 flex flex-col-reverse gap-3 border-t border-gray-200 pt-4 sm:flex-row sm:justify-end">
                <button type="button" class="secondary-button" @click="showConvertModal = false">Cancel</button>
                <button type="button" class="brand-button" :disabled="workflowBusy" @click="convertToSaleSubmit">
                    <ShoppingCart class="w-4 h-4" />
                    {{ workflowBusy ? 'Creating…' : 'Generate Sales Order' }}
                </button>
            </div>
        </div>
    </div>

    <!-- ========================================================================= -->
    <!-- CUSTOMER'S ANSWER -->
    <!-- ========================================================================= -->
    <div v-if="showDecisionModal && activeQuotation" class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4">
        <form class="max-h-[90vh] w-full max-w-md overflow-y-auto rounded-xl border border-gray-200 bg-white p-6 shadow-xl" @submit.prevent="submitDecision">
            <h3 class="text-lg font-semibold text-gray-900">Customer's answer</h3>
            <p class="mt-1 text-xs text-gray-500">{{ activeQuotation.quotation_number }} · sent {{ formatDate(activeQuotation.sent_at ?? null) }} · valid until {{ formatDate(activeQuotation.valid_until) }}</p>

            <div class="mt-4 space-y-4">
                <div class="grid grid-cols-2 gap-2">
                    <label v-for="opt in [{ v: 'accepted', l: 'Accepted' }, { v: 'rejected', l: 'Rejected' }]" :key="opt.v"
                           :class="decision.decision === opt.v ? (opt.v === 'accepted' ? 'border-emerald-500 bg-emerald-50' : 'border-rose-400 bg-rose-50') : 'border-gray-200'"
                           class="flex cursor-pointer items-center gap-2 rounded-xl border p-3 text-sm font-medium">
                        <input v-model="decision.decision" type="radio" :value="opt.v" class="accent-[#4b5320]" />
                        {{ opt.l }}
                    </label>
                </div>
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <div>
                        <label class="field-label" for="decision-source">Received by</label>
                        <select id="decision-source" v-model="decision.source" class="field-control">
                            <option v-for="(label, key) in decisionSources" :key="key" :value="key">{{ label }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="field-label" for="decision-date">Date</label>
                        <input id="decision-date" v-model="decision.decided_on" type="date" :max="new Date().toISOString().slice(0, 10)" class="field-control" />
                    </div>
                </div>
                <div>
                    <label class="field-label" for="decision-remarks">Remarks (optional)</label>
                    <textarea id="decision-remarks" v-model="decision.remarks" rows="2" maxlength="1000" placeholder="e.g. PO number, who confirmed, reason for rejecting" class="field-control"></textarea>
                </div>
                <div v-if="decision.decision === 'accepted'" class="rounded-xl border border-emerald-200 bg-emerald-50 px-3 py-2 text-xs text-emerald-800">
                    The quotation becomes Accepted. Create its Sales Order next in Sales → New Sales Order. The customer is not emailed at this step.
                </div>
            </div>

            <div class="mt-6 flex flex-col-reverse gap-3 border-t border-gray-200 pt-4 sm:flex-row sm:justify-end">
                <button type="button" class="secondary-button" @click="showDecisionModal = false">Cancel</button>
                <button type="submit" class="brand-button" :disabled="workflowBusy">{{ decision.decision === 'accepted' ? 'Save acceptance' : 'Save answer' }}</button>
            </div>
        </form>
    </div>

    <!-- ========================================================================= -->
    <!-- CLIENT REQUEST REVIEW -->
    <!-- ========================================================================= -->
    <div v-if="showViewModal && activeQuotation" class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4 overflow-y-auto" @keydown.esc="showViewModal = false">
        <div role="dialog" aria-modal="true" aria-labelledby="review-title" class="bg-white border border-gray-200 rounded-xl w-full max-w-3xl shadow-xl flex flex-col max-h-[90vh]">
            <div class="px-6 py-4 border-b border-gray-100 flex items-start justify-between gap-4">
                <div class="min-w-0">
                    <h3 id="review-title" class="text-lg font-semibold text-gray-900">Request {{ activeQuotation.quotation_number }}</h3>
                    <p class="text-xs text-gray-500 mt-0.5">
                        {{ activeQuotation.request_details?.source === 'staff_import' ? `Imported from ${activeQuotation.request_details.file_name ?? 'a file'}${activeQuotation.request_details.imported_by ? ` by ${activeQuotation.request_details.imported_by}` : ''}` : activeQuotation.request_details ? 'Submitted from the quotation request form' : 'Created by staff' }}
                        · {{ formatDate(activeQuotation.request_details?.submitted_at || activeQuotation.created_at || activeQuotation.quotation_date) }}
                    </p>
                </div>
                <div class="flex items-center gap-2 shrink-0">
                    <StatusBadge :status="activeQuotation.status_label ?? activeQuotation.status" />
                    <button type="button" @click="showViewModal = false" class="text-gray-400 hover:text-gray-600 p-1 rounded-lg hover:bg-gray-100" aria-label="Close">
                        <X class="w-5 h-5" />
                    </button>
                </div>
            </div>

            <div class="p-6 overflow-y-auto space-y-6 text-sm">
                <dl class="grid grid-cols-2 gap-3 rounded-xl border border-gray-200 bg-gray-50 p-3 text-xs sm:grid-cols-4">
                    <div><dt class="text-gray-500">Approved</dt><dd class="font-medium text-gray-900">{{ activeQuotation.approved_by ? `${activeQuotation.approved_by.name}, ${formatDate(activeQuotation.approved_at ?? null)}` : 'Not yet' }}</dd></div>
                    <div><dt class="text-gray-500">Sent</dt><dd class="font-medium text-gray-900">{{ activeQuotation.sent_at ? formatDate(activeQuotation.sent_at) : 'Not yet' }}</dd></div>
                    <div><dt class="text-gray-500">Email</dt><dd class="font-medium" :class="activeQuotation.email_status === 'failed' ? 'text-rose-600' : 'text-gray-900'">{{ activeQuotation.email_status_label ?? 'Not sent' }}</dd></div>
                    <div><dt class="text-gray-500">Customer</dt><dd class="font-medium text-gray-900">{{ activeQuotation.customer_decision_at ? `${activeQuotation.status === 'rejected' ? 'Rejected' : 'Accepted'} (${decisionSources[activeQuotation.customer_decision_source ?? ''] ?? '—'})` : 'No answer yet' }}</dd></div>
                </dl>
                <p class="text-xs text-gray-500">
                    Client-submitted information. Product matches, prices and availability are not confirmed until you review and price the quotation.
                </p>

                <section>
                    <h4 class="text-xs font-medium text-gray-500 mb-2">Client</h4>
                    <dl class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-1.5">
                        <div class="flex gap-2"><dt class="text-gray-500 w-24 shrink-0">Company</dt><dd class="text-gray-900 font-medium min-w-0 break-words">{{ activeQuotation.client_name }}</dd></div>
                        <div class="flex gap-2"><dt class="text-gray-500 w-24 shrink-0">Contact</dt><dd class="text-gray-900 min-w-0 break-words">{{ activeQuotation.contact_person || '—' }}</dd></div>
                        <div class="flex gap-2"><dt class="text-gray-500 w-24 shrink-0">Email</dt><dd class="text-gray-900 min-w-0 break-all">{{ activeQuotation.email || '—' }}</dd></div>
                        <div class="flex gap-2"><dt class="text-gray-500 w-24 shrink-0">Phone</dt><dd class="text-gray-900 min-w-0">{{ activeQuotation.phone || '—' }}</dd></div>
                    </dl>
                </section>

                <section v-if="activeQuotation.request_details?.project_type || activeQuotation.request_details?.timeline">
                    <h4 class="text-xs font-medium text-gray-500 mb-2">Project</h4>
                    <dl class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-1.5">
                        <div class="flex gap-2"><dt class="text-gray-500 w-24 shrink-0">Type</dt><dd class="text-gray-900">{{ activeQuotation.request_details?.project_type || '—' }}</dd></div>
                        <div class="flex gap-2"><dt class="text-gray-500 w-24 shrink-0">Timeline</dt><dd class="text-gray-900">{{ activeQuotation.request_details?.timeline || '—' }}</dd></div>
                    </dl>
                </section>

                <section v-if="activeQuotation.request_details?.brands?.length || activeQuotation.request_details?.unlisted_brands">
                    <h4 class="text-xs font-medium text-gray-500 mb-2">Brands requested</h4>
                    <ul class="space-y-1">
                        <li v-for="(b, i) in activeQuotation.request_details?.brands ?? []" :key="i" class="text-gray-900">
                            <span class="font-medium">{{ b.brand }}</span><span v-if="b.brand_name" class="text-gray-900"> ({{ b.brand_name }})</span><span v-if="b.details" class="text-gray-600"> — {{ b.details }}</span><span v-if="b.quantity != null" class="text-gray-600"> · Qty {{ b.quantity }}</span>
                        </li>
                    </ul>
                    <p v-if="activeQuotation.request_details?.unlisted_brands" class="mt-1.5 whitespace-pre-line text-gray-700">
                        <span class="text-gray-500">Other brands (not in our catalog):</span> {{ activeQuotation.request_details.unlisted_brands }}
                    </p>
                </section>

                <section v-if="activeQuotation.request_details?.product_list?.length">
                    <h4 class="text-xs font-medium text-gray-500 mb-2">Products from the client's list (confirmed by the client)</h4>
                    <div class="overflow-x-auto border border-gray-200 rounded-lg">
                        <table class="w-full text-left text-xs">
                            <thead class="bg-gray-50 text-gray-500">
                                <tr>
                                    <th class="p-2 font-medium">Description</th>
                                    <th class="p-2 font-medium text-right">Quantity</th>
                                    <th class="p-2 font-medium">Unit</th>
                                    <th class="p-2 font-medium">File</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                <tr v-for="(p, i) in activeQuotation.request_details.product_list" :key="i">
                                    <td class="p-2 text-gray-900">{{ p.description }}</td>
                                    <td class="p-2 text-right text-gray-900 tabular-nums">{{ p.quantity }}</td>
                                    <td class="p-2 text-gray-700">{{ p.unit || '—' }}</td>
                                    <td class="p-2 text-gray-500 truncate max-w-[10rem]" :title="p.source ?? ''">{{ p.source || 'Typed by the client' }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>

                <section v-if="activeQuotation.request_details?.extracted_products?.length" data-test="extracted-products">
                    <h4 class="text-xs font-medium text-gray-500 mb-1">Products read from the uploaded lists</h4>
                    <p class="text-xs text-gray-500 mb-2">Read automatically and not checked by the client. Verify each row against the file before quoting.</p>
                    <div v-for="(f, fi) in activeQuotation.request_details.extracted_products" :key="fi" class="mb-3 last:mb-0">
                        <p class="text-xs font-medium text-gray-700 mb-1">
                            {{ f.file }}<span v-if="f.method === 'ocr' || f.method === 'pdf-ocr'" class="font-normal text-gray-500"> · read by OCR</span>
                        </p>
                        <p v-if="f.message" class="text-xs text-amber-700">{{ f.message }}</p>
                        <div v-if="f.rows.length" class="overflow-x-auto border border-gray-200 rounded-lg">
                            <table class="w-full text-left text-xs">
                                <thead class="bg-gray-50 text-gray-500">
                                    <tr>
                                        <th class="p-2 font-medium">Description</th>
                                        <th class="p-2 font-medium text-right">Quantity</th>
                                        <th class="p-2 font-medium">Unit</th>
                                        <th class="p-2 font-medium">Check</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100">
                                    <tr v-for="(r, ri) in f.rows" :key="ri" :class="r.issues.length ? 'bg-amber-50/50' : ''">
                                        <td class="p-2 text-gray-900">{{ r.description || '—' }}</td>
                                        <td class="p-2 text-right text-gray-900 tabular-nums">{{ r.quantity ?? '—' }}</td>
                                        <td class="p-2 text-gray-700">{{ r.unit || '—' }}</td>
                                        <td class="p-2 text-amber-700">{{ r.issues.join(' ') }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </section>

                <section>
                    <h4 class="text-xs font-medium text-gray-500 mb-2">Files</h4>
                    <ul v-if="hasClientFiles(activeQuotation)" class="divide-y divide-gray-100 border border-gray-200 rounded-lg">
                        <li v-if="activeQuotation.material_file_url" class="flex items-center gap-3 px-3 py-2">
                            <span class="text-xs text-gray-500 w-28 shrink-0">Material list</span>
                            <span class="min-w-0 flex-1 truncate text-gray-900" :title="activeQuotation.material_file_name ?? ''">{{ activeQuotation.material_file_name || 'Material list file' }}</span>
                            <a :href="activeQuotation.material_file_url" target="_blank" rel="noopener" class="text-xs font-medium text-[#4b5320] hover:underline shrink-0">Open</a>
                        </li>
                        <li v-for="f in activeQuotation.files ?? []" :key="f.id" class="flex items-center gap-3 px-3 py-2">
                            <span class="text-xs text-gray-500 w-28 shrink-0">Product list</span>
                            <span class="min-w-0 flex-1 truncate text-gray-900" :title="f.original_name">{{ f.original_name }}</span>
                            <span class="text-xs text-gray-400 shrink-0">{{ formatFileSize(f.size) }}</span>
                            <a :href="f.url" target="_blank" rel="noopener" class="text-xs font-medium text-[#4b5320] hover:underline shrink-0">Open</a>
                        </li>
                        <li v-if="activeQuotation.attachment_url" class="flex items-center gap-3 px-3 py-2">
                            <span class="text-xs text-gray-500 w-28 shrink-0">Specs / BOQ / plans</span>
                            <span class="min-w-0 flex-1 truncate text-gray-900">Specification document</span>
                            <a :href="activeQuotation.attachment_url" target="_blank" rel="noopener" class="text-xs font-medium text-[#4b5320] hover:underline shrink-0">Open</a>
                        </li>
                    </ul>
                    <p v-else class="text-gray-500">No files attached.</p>
                </section>

                <section>
                    <div class="flex flex-wrap items-baseline justify-between gap-2 mb-2">
                        <h4 class="text-xs font-medium text-gray-500">Materials ({{ reviewItems.length }})</h4>
                        <p v-if="reviewOpenCount" class="text-xs text-amber-700">{{ reviewOpenCount }} line(s) need product matching or a price</p>
                    </div>
                    <div v-if="reviewItems.length" class="border border-gray-200 rounded-lg overflow-x-auto max-h-72 overflow-y-auto">
                        <table class="w-full text-left text-xs">
                            <thead class="bg-gray-50 text-gray-500 sticky top-0">
                                <tr>
                                    <th class="px-3 py-2 font-medium">Description</th>
                                    <th class="px-3 py-2 font-medium text-right">Qty</th>
                                    <th class="px-3 py-2 font-medium text-right">Unit price</th>
                                    <th class="px-3 py-2 font-medium">To do</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                <tr v-for="(item, i) in reviewItems" :key="item.id ?? i">
                                    <td class="px-3 py-2 text-gray-900">
                                        {{ item.product_name }}
                                        <span v-if="item.product_id" class="block font-mono text-[10px] text-gray-400">{{ item.sku }}</span>
                                    </td>
                                    <td class="px-3 py-2 text-right text-gray-900">{{ item.quantity }}</td>
                                    <td class="px-3 py-2 text-right text-gray-900 whitespace-nowrap">{{ item.needsPrice ? '—' : formatCurrency(item.unit_price) }}</td>
                                    <td class="px-3 py-2 text-amber-700 whitespace-nowrap">
                                        {{ [item.needsMatching ? 'Match product' : '', item.needsPrice ? 'Set price' : ''].filter(Boolean).join(' · ') }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <p v-else class="text-gray-500">
                        No material lines yet.<template v-if="activeQuotation.material_file_url"> Read the items from the material list file, then add them with “Edit &amp; price items”.</template>
                    </p>
                </section>

                <details v-if="activeQuotation.notes" class="text-xs">
                    <summary class="cursor-pointer font-medium text-gray-600">Notes</summary>
                    <pre class="mt-2 whitespace-pre-wrap font-sans text-gray-700">{{ activeQuotation.notes }}</pre>
                </details>
            </div>

            <div class="px-6 py-4 border-t border-gray-100 bg-gray-50 flex flex-wrap justify-end gap-2">
                <SpecularButton variant="secondary" size="md" :radius="10" @click="openPrintModal(activeQuotation)">Print / Download</SpecularButton>
                <SpecularButton variant="secondary" size="md" :radius="10" @click="statusFromReview">Update status</SpecularButton>
                <SpecularButton :variant="can(activeQuotation, 'send') ? 'secondary' : 'brand'" size="md" :radius="10" @click="editFromReview">Edit &amp; price items</SpecularButton>
                <SpecularButton v-if="can(activeQuotation, 'resend')" variant="secondary" size="md" :radius="10" :disabled="workflowBusy" @click="promptSend(activeQuotation)">
                    <Mail class="w-4 h-4" /> Resend Quotation
                </SpecularButton>
                <SpecularButton v-if="can(activeQuotation, 'send')" variant="brand" size="md" :radius="10" :disabled="workflowBusy" @click="promptSend(activeQuotation)">
                    <Mail class="w-4 h-4" /> Send Quotation
                </SpecularButton>
            </div>
        </div>
    </div>

    <!-- ========================================================================= -->
    <!-- IMPORT A MATERIAL LIST AS A DRAFT QUOTATION -->
    <!-- ========================================================================= -->
    <div v-if="showMaterialImportModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4 overflow-y-auto">
        <div role="dialog" aria-modal="true" aria-labelledby="material-import-title" class="bg-white border border-gray-200 rounded-xl w-full max-w-3xl shadow-xl flex flex-col max-h-[90vh]">
            <div class="px-6 py-4 border-b border-gray-100 flex items-start justify-between gap-4">
                <div>
                    <h3 id="material-import-title" class="text-lg font-semibold text-gray-900">Import material list</h3>
                    <p class="text-xs text-gray-500 mt-0.5">Creates a draft quotation from a PDF, Excel, CSV, JPG or PNG file (up to 10 MB). Check the lines and set prices afterwards.</p>
                </div>
                <button type="button" @click="showMaterialImportModal = false" class="text-gray-400 hover:text-gray-600 p-1 rounded-lg hover:bg-gray-100" aria-label="Close">
                    <X class="w-5 h-5" />
                </button>
            </div>

            <div class="p-6 overflow-y-auto space-y-5 text-sm">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div v-if="clients.length" class="sm:col-span-2">
                        <label for="mi-client" class="block text-xs font-medium text-gray-700 mb-1">Existing client (optional)</label>
                        <select id="mi-client" v-model="materialImport.clientId" @change="onMaterialClientPick" class="w-full py-2 px-3 bg-white border border-gray-200 rounded-lg text-sm focus:border-gray-400 focus:outline-none">
                            <option value="">Choose a client…</option>
                            <option v-for="c in clients" :key="c.id" :value="String(c.id)">{{ c.company_name }}</option>
                        </select>
                    </div>
                    <div>
                        <label for="mi-company" class="block text-xs font-medium text-gray-700 mb-1">Client / company name <span class="text-red-600">*</span></label>
                        <input id="mi-company" v-model="materialImport.clientName" maxlength="255" class="w-full py-2 px-3 bg-white border border-gray-200 rounded-lg text-sm focus:border-gray-400 focus:outline-none" />
                    </div>
                    <div>
                        <label for="mi-contact" class="block text-xs font-medium text-gray-700 mb-1">Contact person</label>
                        <input id="mi-contact" v-model="materialImport.contactPerson" maxlength="255" class="w-full py-2 px-3 bg-white border border-gray-200 rounded-lg text-sm focus:border-gray-400 focus:outline-none" />
                    </div>
                    <div>
                        <label for="mi-email" class="block text-xs font-medium text-gray-700 mb-1">Email</label>
                        <input id="mi-email" v-model="materialImport.email" type="email" maxlength="255" class="w-full py-2 px-3 bg-white border border-gray-200 rounded-lg text-sm focus:border-gray-400 focus:outline-none" />
                    </div>
                    <div>
                        <label for="mi-phone" class="block text-xs font-medium text-gray-700 mb-1">Phone</label>
                        <input id="mi-phone" v-model="materialImport.phone" maxlength="50" class="w-full py-2 px-3 bg-white border border-gray-200 rounded-lg text-sm focus:border-gray-400 focus:outline-none" />
                    </div>
                </div>

                <div>
                    <span class="block text-xs font-medium text-gray-700 mb-1">Material list file <span class="text-red-600">*</span></span>
                    <div class="flex flex-wrap items-center gap-3">
                        <label class="inline-flex cursor-pointer items-center gap-1.5 rounded-lg border border-gray-200 bg-white px-3 py-2 text-xs font-medium text-gray-700 hover:bg-gray-50 focus-within:outline-2 focus-within:outline-[#4b5320]">
                            <Upload class="size-3.5 text-gray-500" />
                            <span>{{ materialImport.file ? 'Choose another file' : 'Choose file' }}</span>
                            <input type="file" class="sr-only" accept=".pdf,.xlsx,.xls,.csv,.jpg,.jpeg,.png" @change="onMaterialFileChange" />
                        </label>
                        <span v-if="materialImport.file" class="min-w-0 truncate text-xs text-gray-700">{{ materialImport.file.name }} · {{ formatFileSize(materialImport.file.size) }}</span>
                    </div>
                </div>

                <p v-if="materialImportError" class="text-xs font-medium text-red-600" role="alert">{{ materialImportError }}</p>
                <p v-if="isReadingMaterials" class="text-xs text-gray-500" role="status">Reading the file… photos and scanned PDFs can take up to a minute.</p>

                <!-- Choosing the columns by hand, when the headers are not recognised -->
                <section v-if="showMaterialColumns && materialColumns" aria-labelledby="mi-columns-title" class="space-y-3 rounded-lg border border-gray-200 p-3">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <h4 id="mi-columns-title" class="text-xs font-semibold text-gray-900">Choose the columns</h4>
                            <p class="mt-0.5 text-xs text-gray-500">Pick the row with the column headers, then the column that holds each field. Rows above the header row are skipped.</p>
                        </div>
                        <button v-if="materialPreview" type="button" class="text-[11px] text-gray-500 underline hover:text-gray-800" @click="showMaterialColumns = false">Close</button>
                    </div>

                    <div class="border border-gray-200 rounded-lg overflow-auto max-h-56">
                        <table class="w-full text-left text-xs">
                            <caption class="sr-only">First rows of the file</caption>
                            <thead class="bg-gray-50 text-gray-500 sticky top-0">
                                <tr>
                                    <th scope="col" class="px-2 py-1.5 font-medium w-10">Row</th>
                                    <th v-for="col in materialColumnCount" :key="col" scope="col" class="px-2 py-1.5 font-medium align-bottom whitespace-nowrap">
                                        <span class="font-mono">{{ columnLetter(col - 1) }}</span>
                                        <span v-if="materialFieldForColumn(col - 1)" class="ml-1 rounded-full border border-[#4b5320]/30 bg-[#4b5320]/5 px-1.5 py-0.5 text-[10px] font-semibold text-[#4b5320]">{{ materialFieldForColumn(col - 1) }}</span>
                                        <span v-if="materialHeaderLabel(col - 1)" class="block max-w-[10rem] truncate font-normal text-gray-500">{{ materialHeaderLabel(col - 1) }}</span>
                                    </th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                <tr
                                    v-for="(row, r) in materialColumns.preview"
                                    :key="r"
                                    :class="String(r) === materialHeaderRow ? 'bg-gray-50 font-semibold text-gray-900' : materialHeaderRow !== '' && r < Number(materialHeaderRow) ? 'text-gray-400' : 'text-gray-700'"
                                >
                                    <td class="px-2 py-1 font-mono text-gray-400">{{ r + 1 }}</td>
                                    <td v-for="col in materialColumnCount" :key="col" class="px-2 py-1 max-w-[12rem] truncate" :title="row[col - 1] ?? ''">{{ row[col - 1] ?? '' }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div>
                        <label for="mi-header-row" class="block text-xs font-medium text-gray-700 mb-1">Header row</label>
                        <select id="mi-header-row" v-model="materialHeaderRow" class="w-full py-1.5 px-2 bg-white border border-gray-200 rounded-lg text-xs focus:border-gray-400 focus:outline-none">
                            <option value="">No header row (every row is a material)</option>
                            <option v-for="(row, r) in materialColumns.preview" :key="r" :value="String(r)">{{ materialRowOption(row, r) }}</option>
                        </select>
                    </div>
                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                        <div v-for="field in materialColumns.fields" :key="field.key">
                            <label :for="`mi-col-${field.key}`" class="block text-xs font-medium text-gray-700 mb-1">{{ field.label }} <span v-if="field.required" class="text-red-600">*</span></label>
                            <select :id="`mi-col-${field.key}`" v-model="materialMapping[field.key]" class="w-full py-1.5 px-2 bg-white border border-gray-200 rounded-lg text-xs focus:border-gray-400 focus:outline-none">
                                <option value="">— Not in file —</option>
                                <option v-for="col in materialColumnCount" :key="col" :value="String(col - 1)">{{ materialColumnOption(col - 1) }}</option>
                            </select>
                        </div>
                    </div>

                    <div class="flex flex-wrap items-center justify-end gap-3">
                        <p v-if="materialMappingProblem" class="text-xs text-gray-500" aria-live="polite">{{ materialMappingProblem }}</p>
                        <SpecularButton variant="brand" size="sm" :radius="8" :disabled="!!materialMappingProblem || isReadingMaterials" @click="applyMaterialColumns">
                            {{ isReadingMaterials ? 'Reading…' : 'Apply columns' }}
                        </SpecularButton>
                    </div>
                </section>

                <div v-if="materialPreview" class="space-y-2">
                    <div class="flex flex-wrap items-baseline justify-between gap-2">
                        <p class="text-xs text-gray-600">
                            {{ materialPreview.valid_count }} line(s) will be imported<template v-if="materialPreview.invalid_count">, {{ materialPreview.invalid_count }} skipped (missing description or quantity)</template><template v-if="materialPreview.unparsed_count">, {{ materialPreview.unparsed_count }} line(s) of the file not readable as materials</template>.
                        </p>
                        <button v-if="materialColumns && !showMaterialColumns" type="button" class="text-[11px] font-medium text-[#4b5320] underline hover:text-gray-900" @click="showMaterialColumns = true">Change columns</button>
                    </div>
                    <p v-if="materialPreview.warning" class="text-xs text-amber-700">{{ materialPreview.warning }}</p>
                    <div class="border border-gray-200 rounded-lg overflow-x-auto max-h-64 overflow-y-auto">
                        <table class="w-full text-left text-xs">
                            <thead class="bg-gray-50 text-gray-500 sticky top-0">
                                <tr>
                                    <th class="px-3 py-2 font-medium">Description</th>
                                    <th class="px-3 py-2 font-medium text-right w-24">Qty</th>
                                    <th class="px-3 py-2 font-medium w-20">Unit</th>
                                    <th class="px-3 py-2 font-medium">Catalog match</th>
                                    <th class="px-2 py-2 font-medium text-right"><span class="sr-only">Action</span></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                <tr v-for="(item, i) in materialPreview.items" :key="i" :class="item.skip ? 'opacity-50 bg-gray-50' : item.errors.length ? 'bg-red-50/40' : ''">
                                    <td class="px-3 py-1.5">
                                        <input
                                            v-model="item.description"
                                            type="text"
                                            :disabled="item.skip"
                                            placeholder="NULL"
                                            class="w-full rounded border px-2 py-1 text-xs placeholder:font-mono placeholder:text-[10px] placeholder:text-gray-300 focus:border-[#4b5320] focus:outline-none"
                                            :class="item.errors.length && !item.description ? 'border-red-300 bg-red-50/40' : 'border-gray-200 bg-white'"
                                            @input="validateMaterialPreviewItem(item)"
                                        />
                                        <span v-if="item.errors.length && !item.skip" class="mt-0.5 block text-[11px] text-red-600">{{ item.errors.join(' ') }}</span>
                                        <span v-if="item.warnings?.length && !item.skip" class="mt-0.5 block text-[11px] text-amber-700">{{ item.warnings.join(' ') }}</span>
                                    </td>
                                    <td class="px-3 py-1.5 text-right whitespace-nowrap">
                                        <input
                                            v-model.number="item.quantity"
                                            type="number"
                                            min="1"
                                            step="any"
                                            :disabled="item.skip"
                                            placeholder="NULL"
                                            class="w-20 rounded border px-2 py-1 text-right text-xs placeholder:font-mono placeholder:text-[10px] placeholder:text-gray-300 focus:border-[#4b5320] focus:outline-none"
                                            :class="item.errors.length && (!item.quantity || item.quantity <= 0) ? 'border-red-300 bg-red-50/40' : 'border-gray-200 bg-white'"
                                            @input="validateMaterialPreviewItem(item)"
                                        />
                                    </td>
                                    <td class="px-3 py-1.5 whitespace-nowrap">
                                        <input
                                            v-model="item.unit"
                                            type="text"
                                            :disabled="item.skip"
                                            placeholder="NULL"
                                            class="w-16 rounded border border-gray-200 bg-white px-2 py-1 text-xs placeholder:font-mono placeholder:text-[10px] placeholder:text-gray-300 focus:border-[#4b5320] focus:outline-none"
                                        />
                                    </td>
                                    <td class="px-3 py-1.5">
                                        <div class="flex flex-wrap items-center gap-1">
                                            <span
                                                class="rounded-full border px-1.5 py-0.5 text-[10px]"
                                                :class="item.errors.length || item.confidence === 'low' ? 'border-rose-200 bg-rose-50 text-rose-700' : item.confidence === 'medium' || item.needs_review ? 'border-amber-200 bg-amber-50 text-amber-800' : 'border-emerald-200 bg-emerald-50 text-emerald-700'"
                                            >
                                                {{ item.errors.length || item.confidence === 'low' ? 'Low · verify' : item.confidence === 'medium' || item.needs_review ? 'Medium' : 'High' }}
                                            </span>
                                            <template v-if="item.match">
                                                <span class="font-medium">{{ item.match.name }}</span>
                                                <span class="font-mono text-[10px] text-gray-400">{{ item.match.sku }}</span>
                                            </template>
                                            <span v-else class="text-amber-700">Needs matching and a price</span>
                                        </div>
                                    </td>
                                    <td class="px-2 py-1.5 text-right whitespace-nowrap">
                                        <button
                                            type="button"
                                            class="text-[11px] text-gray-500 underline hover:text-gray-800"
                                            @click="item.skip = !item.skip; validateMaterialPreviewItem(item)"
                                        >
                                            {{ item.skip ? 'Restore' : 'Skip' }}
                                        </button>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="px-6 py-4 border-t border-gray-100 bg-gray-50 flex flex-wrap justify-end gap-2">
                <SpecularButton variant="secondary" size="md" :radius="10" @click="showMaterialImportModal = false">Cancel</SpecularButton>
                <SpecularButton variant="secondary" size="md" :radius="10" :disabled="!materialImport.file || isReadingMaterials" @click="readMaterialFile(useMaterialMapping)">
                    {{ isReadingMaterials ? 'Reading…' : materialPreview ? 'Read again' : 'Read file' }}
                </SpecularButton>
                <SpecularButton variant="brand" size="md" :radius="10" :disabled="!materialPreview || isCreatingFromMaterials" @click="createFromMaterials">
                    {{ isCreatingFromMaterials ? 'Creating…' : 'Create draft quotation' }}
                </SpecularButton>
            </div>
        </div>
    </div>

    <!-- ========================================================================= -->
    <!-- OFFICIAL QUOTATION: preview, print, PDF and PNG/JPG are all the same document -->
    <!-- ========================================================================= -->
    <div v-if="showPrintModal && activeQuotation" class="fixed inset-0 z-50 flex items-center justify-center bg-black/70 p-2 sm:p-3" role="dialog" aria-modal="true" aria-labelledby="quote-doc-title">
        <div class="bg-white border border-gray-200 rounded-xl w-full max-w-[1600px] shadow-xl overflow-hidden flex flex-col h-[97vh]">
            <div class="px-6 py-3 border-b border-gray-100 bg-gray-50 flex flex-wrap justify-between items-center gap-3">
                <div id="quote-doc-title" class="flex items-center gap-2 text-sm font-semibold text-gray-700">
                    <Printer class="w-4 h-4 text-[#4b5320]" />
                    <span>Quotation #{{ activeQuotation.quotation_number }}</span>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <button type="button" @click="triggerPrint" class="px-4 py-2 bg-[#4b5320] text-white font-semibold text-xs rounded-lg hover:opacity-95 flex items-center gap-2">
                        <Printer class="w-4 h-4" />
                        <span>Print</span>
                    </button>
                    <a :href="documentUrl('pdf', true)" class="px-3 py-2 bg-white border border-gray-200 text-gray-700 font-semibold text-xs rounded-lg hover:bg-gray-50 flex items-center gap-1.5">
                        <Download class="w-4 h-4" />
                        <span>PDF</span>
                    </a>
                    <button type="button" :disabled="!!imageBusy" @click="downloadImage('png')" class="px-3 py-2 bg-white border border-gray-200 text-gray-700 font-semibold text-xs rounded-lg hover:bg-gray-50 disabled:opacity-60">
                        {{ imageBusy === 'png' ? 'Preparing…' : 'PNG' }}
                    </button>
                    <button type="button" :disabled="!!imageBusy" @click="downloadImage('jpg')" class="px-3 py-2 bg-white border border-gray-200 text-gray-700 font-semibold text-xs rounded-lg hover:bg-gray-50 disabled:opacity-60">
                        {{ imageBusy === 'jpg' ? 'Preparing…' : 'JPG' }}
                    </button>
                    <button type="button" aria-label="Close" @click="showPrintModal = false" class="text-gray-400 hover:text-gray-600 p-1.5 rounded-lg hover:bg-gray-100">
                        <X class="w-5 h-5" />
                    </button>
                </div>
            </div>
            <p v-if="documentError" class="px-6 py-2 text-xs text-rose-700 bg-rose-50 border-b border-rose-100" role="alert">{{ documentError }}</p>
            <iframe ref="documentFrame" :src="documentUrl('pdf')" class="flex-1 w-full bg-gray-100" title="Official quotation preview"></iframe>
        </div>
    </div>

    <!-- ========================================================================= -->
    <!-- CONFIRM DELETE MODAL -->
    <!-- ========================================================================= -->
    <div v-if="showDeleteModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
        <div class="bg-white border border-gray-200 p-6 rounded-xl w-full max-w-md shadow-xl animate-in fade-in duration-150">
            <div class="w-12 h-12 rounded-full bg-rose-50 text-rose-600 flex items-center justify-center mb-4">
                <Trash2 class="w-6 h-6" />
            </div>
            <h3 class="text-xl font-semibold text-gray-900 mb-2">Delete Quotation</h3>
            <p class="text-gray-600 text-sm mb-2 leading-relaxed">
                Move quotation <strong class="text-gray-900">"{{ activeQuotation?.quotation_number }}"</strong> for {{ activeQuotation?.client_name }} to the Recycle Bin?
            </p>
            <p class="text-gray-500 text-xs mb-4 leading-relaxed">
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
                    @click="confirmDelete"
                    :disabled="deleteConfirmInput !== 'YES'"
                >
                    Delete
                </SpecularButton>
            </div>
        </div>
    </div>

    <!-- Action Confirmation Modal -->
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
                    :disabled="isSubmitting"
                >
                    <Check class="w-4 h-4 mr-1.5 inline" />
                    <span>{{ confirmOptions.confirmText || 'Confirm & Save' }}</span>
                </SpecularButton>
            </div>
        </div>
    </div>

    <!-- ========================================================================= -->
    <!-- CSV IMPORT MODAL -->
    <!-- ========================================================================= -->
    <div v-if="showImportModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4 overflow-y-auto">
        <div class="bg-white border border-gray-200 rounded-xl w-full max-w-3xl shadow-xl overflow-hidden flex flex-col max-h-[92vh] animate-in fade-in duration-150">
            <!-- Modal Header -->
            <div class="px-6 py-4 border-b border-gray-100 flex justify-between items-center bg-gray-50/70">
                <div class="flex items-center gap-3">
                    <div class="p-2 bg-[#F8B803]/20 text-[#4b5320] rounded-lg">
                        <FileSpreadsheet class="w-6 h-6" />
                    </div>
                    <div>
                        <h3 class="text-xl font-semibold text-gray-900">Import Quotations via CSV</h3>
                        <p class="text-xs text-gray-500">Upload proposal estimates and line items in bulk</p>
                    </div>
                </div>
                <button @click="showImportModal = false" class="text-gray-400 hover:text-gray-600 p-1.5 rounded-lg hover:bg-gray-100">
                    <X class="w-5 h-5" />
                </button>
            </div>

            <!-- Modal Content -->
            <div class="p-6 overflow-y-auto space-y-4 text-sm">
                <!-- CSV Headers & Format Notice -->
                <div class="p-3.5 bg-amber-50/70 border border-amber-200 rounded-xl space-y-2 text-xs text-amber-900">
                    <div class="flex items-center justify-between">
                        <span class="font-semibold flex items-center gap-1.5">
                            <AlertCircle class="w-4 h-4 text-amber-600" />
                            Required CSV Column Headers
                        </span>
                        <button type="button" @click="downloadSampleCsv" class="font-semibold text-[#4b5320] hover:underline flex items-center gap-1">
                            <Download class="w-3.5 h-3.5" />
                            Download Sample Template
                        </button>
                    </div>
                    <div class="font-mono bg-white p-2 rounded-lg border border-amber-200 text-[11px] text-gray-800 overflow-x-auto whitespace-nowrap">
                        DATE, QUOTE NUMBER, QUANTITY, DESCRIPTION, UNIT PRICE, AMOUNT
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-2 pt-1 text-[11px] text-gray-700">
                        <div>
                            <span class="font-semibold text-gray-900">DATE Format:</span> MONTH-DAY-YEAR <span class="text-gray-500 font-mono">(eg 10-25-2026)</span>
                        </div>
                        <div>
                            <span class="font-semibold text-gray-900">QUOTE NUMBER:</span> Q&lt;MMDDYY&gt;-&lt;PRODUCT_ID&gt; <span class="text-gray-500 font-mono">(eg Q010423-068SSCR2)</span>
                        </div>
                    </div>
                </div>

                <!-- Dropzone -->
                <div 
                    @dragover.prevent="isDragging = true" 
                    @dragleave.prevent="isDragging = false" 
                    @drop.prevent="onFileDrop"
                    @click="triggerFileSelect"
                    :class="[
                        'border-2 border-dashed rounded-xl p-6 text-center cursor-pointer transition-colors',
                        isDragging ? 'border-[#4b5320] bg-[#4b5320]/5' : 'border-gray-300 hover:border-[#F8B803] bg-gray-50/40'
                    ]"
                >
                    <input ref="fileInputRef" type="file" accept=".csv,text/csv" class="hidden" @change="onFileInputChange" />
                    <FileUp class="w-10 h-10 text-[#4b5320] mx-auto mb-2 opacity-80" />
                    <p class="font-semibold text-gray-800 text-sm">
                        {{ importedFile ? importedFile.name : 'Click to select or drag and drop your CSV file here' }}
                    </p>
                    <p class="text-xs text-gray-400 mt-1">
                        {{ importedFile ? `${(importedFile.size / 1024).toFixed(1)} KB` : 'CSV files with comma or tab delimited headers' }}
                    </p>
                </div>

                <!-- Validation Status / Spinner -->
                <div v-if="isValidatingCsv" class="p-4 bg-gray-50 rounded-xl text-center text-gray-500 text-xs flex items-center justify-center gap-2">
                    <Clock class="w-4 h-4 animate-spin text-[#4b5320]" />
                    <span>Validating CSV structure and required headers...</span>
                </div>

                <!-- Error Message -->
                <div v-if="csvError" class="p-3.5 bg-rose-50 border border-rose-200 rounded-xl flex items-start gap-2.5 text-xs text-rose-800">
                    <AlertCircle class="w-4 h-4 text-rose-600 shrink-0 mt-0.5" />
                    <div>
                        <strong class="font-semibold block">CSV Parsing / Validation Notice</strong>
                        <span>{{ csvError }}</span>
                    </div>
                </div>

                <!-- Validation Preview Summary -->
                <div v-if="csvValidationResult" class="space-y-3">
                    <div class="grid grid-cols-3 gap-2">
                        <div class="p-2.5 rounded-xl border border-gray-200 bg-gray-50 text-center">
                            <span class="text-[10px] font-semibold text-gray-400 block">Total Rows</span>
                            <span class="text-base font-semibold text-gray-800">{{ csvValidationResult.total_rows }}</span>
                        </div>
                        <div class="p-2.5 rounded-xl border border-emerald-200 bg-emerald-50/60 text-center">
                            <span class="text-[10px] font-semibold text-emerald-600 block">Valid Rows</span>
                            <span class="text-base font-semibold text-emerald-700">{{ csvValidationResult.valid_count }}</span>
                        </div>
                        <div class="p-2.5 rounded-xl border border-rose-200 bg-rose-50/60 text-center">
                            <span class="text-[10px] font-semibold text-rose-600 block">Invalid Rows</span>
                            <span class="text-base font-semibold text-rose-700">{{ csvValidationResult.invalid_count }}</span>
                        </div>
                    </div>

                    <p v-if="csvValidationResult.existing_count" class="text-xs text-amber-700">
                        {{ csvValidationResult.existing_count }} quotation number(s) already exist and will be skipped{{ importMode === 'replace' ? '' : ', so nothing is duplicated' }}.
                        <template v-if="importMode === 'replace'"> Replace mode will overwrite them instead.</template>
                    </p>

                    <!-- Configuration Options -->
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 pt-2">
                        <div>
                            <label class="block text-xs font-semibold text-gray-700 mb-1">Import Mode Strategy</label>
                            <select v-model="importMode" class="w-full text-xs p-2 rounded-lg border border-gray-200 bg-white focus:outline-none focus:border-[#4b5320]">
                                <option value="append">Add new only (skip quote numbers that already exist)</option>
                                <option v-if="canDelete" value="replace">Replace (Overwrite existing quote with CSV items)</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-gray-700 mb-1">Default Customer / Client Name</label>
                            <input v-model="defaultClientName" type="text" placeholder="e.g. Direct Customer" class="w-full text-xs p-2 rounded-lg border border-gray-200 bg-white focus:outline-none focus:border-[#4b5320]" />
                        </div>
                    </div>

                    <!-- Live Valid Rows Preview Table -->
                    <div v-if="csvValidationResult.valid_rows && csvValidationResult.valid_rows.length > 0" class="border border-gray-200 rounded-xl overflow-hidden max-h-48 overflow-y-auto">
                        <table class="w-full text-left text-xs">
                            <thead class="bg-gray-50 border-b border-gray-200 text-gray-500 text-[10px]">
                                <tr>
                                    <th class="p-2 font-semibold">DATE</th>
                                    <th class="p-2 font-semibold">QUOTE NUMBER</th>
                                    <th class="p-2 font-semibold text-center">QTY</th>
                                    <th class="p-2 font-semibold">DESCRIPTION</th>
                                    <th class="p-2 font-semibold text-right">UNIT PRICE</th>
                                    <th class="p-2 font-semibold text-right">AMOUNT</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                <tr v-for="(vRow, idx) in csvValidationResult.valid_rows" :key="idx" class="hover:bg-gray-50/50">
                                    <td class="p-2 font-semibold text-gray-900 whitespace-nowrap">{{ vRow.display_date }}</td>
                                    <td class="p-2 font-mono text-[11px] font-semibold text-gray-800 whitespace-nowrap">{{ vRow.quote_number }}<span v-if="vRow.exists" class="ml-1.5 font-sans text-[10px] font-medium text-amber-700">exists</span></td>
                                    <td class="p-2 text-center font-semibold text-gray-700">{{ vRow.quantity }}</td>
                                    <td class="p-2 text-gray-900 truncate max-w-[160px]" :title="vRow.description">{{ vRow.description }}</td>
                                    <td class="p-2 text-right text-gray-600 whitespace-nowrap">{{ formatCurrency(vRow.unit_price) }}</td>
                                    <td class="p-2 text-right font-semibold text-gray-900 whitespace-nowrap">{{ formatCurrency(vRow.amount) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- Invalid Rows Listing -->
                    <div v-if="csvValidationResult.invalid_rows && csvValidationResult.invalid_rows.length > 0" class="p-3 bg-rose-50 border border-rose-200 rounded-xl space-y-1">
                        <span class="text-xs font-semibold text-rose-800 block">Errors in Uploaded CSV:</span>
                        <div class="text-[11px] text-rose-700 max-h-24 overflow-y-auto space-y-1">
                            <div v-for="(inv, i) in csvValidationResult.invalid_rows" :key="i">
                                Row {{ inv.row_number }}: {{ inv.errors?.join(', ') }}
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Modal Footer -->
            <div class="px-6 py-4 bg-gray-50 border-t border-gray-100 flex justify-between items-center">
                <button type="button" @click="downloadSampleCsv" class="text-xs font-semibold text-gray-600 hover:text-gray-900 flex items-center gap-1.5">
                    <Download class="w-3.5 h-3.5 text-[#4b5320]" />
                    <span>Download CSV Template</span>
                </button>
                <div class="flex items-center gap-2">
                    <SpecularButton variant="secondary" size="md" :radius="10" @click="showImportModal = false">
                        Cancel
                    </SpecularButton>
                    <SpecularButton 
                        variant="brand" 
                        size="md" 
                        :radius="10" 
                        :disabled="!importedFile || isImportingCsv || !csvValidationResult || csvValidationResult.valid_count === 0"
                        @click="executeCsvImport"
                    >
                        <Clock v-if="isImportingCsv" class="w-4 h-4 animate-spin mr-1.5" />
                        <Upload v-else class="w-4 h-4 mr-1.5" />
                        <span>{{ isImportingCsv ? 'Importing...' : 'Execute Import' }}</span>
                    </SpecularButton>
                </div>
            </div>
        </div>
    </div>

    <ImportWizard :open="showSupplierQuoteImport" module="supplier_quotation" :suppliers="activeSuppliers" @close="showSupplierQuoteImport = false" />
</template>
