<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import { ref, computed, watch } from 'vue';
import SpecularButton from '@/components/ui/SpecularButton.vue';
import StatusBadge from '@/components/ui/StatusBadge.vue';
import { useCanDelete } from '@/composables/useCanDelete';
import { useCurrency } from '@/composables/useCurrency';
import { Search, Plus, Check, CheckCircle2, Trash2 } from '@lucide/vue';

interface SaleItem {
    id: number;
    sku: string;
    product_name: string;
    quantity: number;
    unit_price: number;
    subtotal: number;
}

interface Sale {
    id: number;
    order_number: string;
    channel: 'quotation' | 'counter';
    fulfillment_type: 'delivery' | 'pickup';
    invoice_number?: string | null;
    client_name: string;
    sale_date: string;
    grand_total: number;
    amount_paid?: number;
    outstanding_balance?: number;
    status: string;
    status_label?: string;
    payment_status?: string;
    payment_status_label?: string;
    payment_terms?: string;
    is_on_hold?: boolean;
    delivery_status_label?: string | null;
    quotation?: { id: number; quotation_number: string } | null;
    fulfillment?: { id: number; status: string; fulfillment_number: string } | null;
    items?: SaleItem[];
}

type Option = { value: string; label: string };
/** An agreed line of an accepted quotation (read-only: it is copied into the Sales Order as it is). */
interface QuotationLineOption {
    id: number;
    sku?: string | null;
    description: string;
    quantity: number;
    unit?: string | null;
    unit_price: number;
    amount: number;
}

/** An accepted quotation that has no Sales Order yet. */
interface QuotationOption {
    id: number;
    quotation_number: string;
    client_name: string;
    address?: string | null;
    quotation_date: string;
    accepted_on?: string | null;
    subtotal: number;
    discount: number;
    tax_rate: number;
    tax_amount: number;
    grand_total: number;
    items: QuotationLineOption[];
}

const props = defineProps<{
    sales: {
        data: Sale[];
        current_page: number;
        last_page: number;
        total: number;
        links: any[];
    };
    filters?: {
        search?: string;
        status?: string;
        payment_status?: string;
        delivery_status?: string;
        date_from?: string | null;
        date_to?: string | null;
        sort?: string;
    };
    metrics: {
        totalSales: number;
        todaySales: number;
        monthSales: number;
        pendingPayments: number;
        toVerify: number;
        outstanding: number;
    };
    /** Accepted quotations without a Sales Order (the only source of new orders). */
    quotations?: QuotationOption[];
    options: { status: Option[]; payment_status: Option[]; delivery_status: Option[] };
}>();

const page = usePage();
const canDelete = useCanDelete();
const teamSlug = computed(() => (page.props as any).currentTeam?.slug || (page.props as any).team?.slug || '');

// Stored amounts are USD; shown in the user's display currency (header switch).
const { formatMoney: formatCurrency, conversionNote } = useCurrency();

// Search & Filter
const search = ref(props.filters?.search || '');
const selectedStatus = ref(props.filters?.status || 'All');
const selectedPayment = ref(props.filters?.payment_status || 'All');
const selectedDelivery = ref(props.filters?.delivery_status || 'All');
const dateFrom = ref(props.filters?.date_from || '');
const dateTo = ref(props.filters?.date_to || '');
const sort = ref(props.filters?.sort || 'newest');

let searchTimeout: ReturnType<typeof setTimeout> | null = null;
const applyFilter = () => {
    if (searchTimeout) clearTimeout(searchTimeout);
    searchTimeout = setTimeout(() => {
        router.get(
            `/${teamSlug.value}/sales`,
            {
                search: search.value || undefined,
                status: selectedStatus.value !== 'All' ? selectedStatus.value : undefined,
                payment_status: selectedPayment.value !== 'All' ? selectedPayment.value : undefined,
                delivery_status: selectedDelivery.value !== 'All' ? selectedDelivery.value : undefined,
                date_from: dateFrom.value || undefined,
                date_to: dateTo.value || undefined,
                sort: sort.value !== 'newest' ? sort.value : undefined,
            },
            { preserveState: true, preserveScroll: true, replace: true }
        );
    }, 300);
};
const hasFilters = computed(() => !!search.value || selectedStatus.value !== 'All' || selectedPayment.value !== 'All' || selectedDelivery.value !== 'All' || !!dateFrom.value || !!dateTo.value);
const resetFilters = () => {
    search.value = '';
    selectedStatus.value = 'All';
    selectedPayment.value = 'All';
    selectedDelivery.value = 'All';
    dateFrom.value = '';
    dateTo.value = '';
    sort.value = 'newest';
    applyFilter();
};
// New Sales Order: from an accepted quotation only. Customer, products, quantities and agreed
// prices come from the quotation and cannot be changed here. No payment is taken here (payments
// are recorded on the order page) and no stock moves.
const orderModalOpen = ref(false);
const selectedQuotationId = ref<number | null>(null);
const quotationSearch = ref('');
const quotationFocus = ref(false);
const quotationError = ref('');
const fulfillmentType = ref<'delivery' | 'pickup'>('delivery');
const deliveryAddress = ref('');
const isSubmitting = ref(false);

const selectedQuotation = computed(() => (props.quotations ?? []).find((q) => q.id === selectedQuotationId.value) ?? null);
// The search box appears only when the list is long enough to need it.
const showQuotationSearch = computed(() => (props.quotations ?? []).length > 8);
const quotationChoices = computed(() => {
    const term = quotationSearch.value.trim().toLowerCase();
    const all = props.quotations ?? [];
    const matches = term ? all.filter((q) => q.quotation_number.toLowerCase().includes(term) || q.client_name.toLowerCase().includes(term)) : all;
    // Keep the chosen quotation in the list while searching.
    return selectedQuotation.value && !matches.includes(selectedQuotation.value) ? [selectedQuotation.value, ...matches] : matches;
});

watch(selectedQuotation, (q) => {
    quotationError.value = '';
    fulfillmentType.value = 'delivery';
    deliveryAddress.value = q?.address ?? '';
});

const openOrderModal = () => {
    selectedQuotationId.value = null;
    quotationSearch.value = '';
    quotationError.value = '';
    fulfillmentType.value = 'delivery';
    deliveryAddress.value = '';
    orderModalOpen.value = true;
};
const closeOrderModal = () => {
    orderModalOpen.value = false;
};

const createSalesOrder = () => {
    if (!selectedQuotation.value) {
        quotationError.value = 'Select a quotation.';
        return;
    }
    isSubmitting.value = true;
    router.post(
        `/${teamSlug.value}/sales`,
        {
            quotation_id: selectedQuotation.value.id,
            fulfillment_type: fulfillmentType.value,
            delivery_address: fulfillmentType.value === 'delivery' ? deliveryAddress.value.trim() || undefined : undefined,
        },
        {
            preserveScroll: true,
            onSuccess: (visit) => {
                // A refused order comes back as a page error (errors.error), not as a success.
                const errors = (visit.props as any).errors ?? {};
                if (errors.error || errors.quotation_id) {
                    quotationError.value = errors.quotation_id ?? errors.error;
                    return;
                }
                closeOrderModal();
            },
            onError: (errors) => {
                quotationError.value = errors.quotation_id ?? errors.error ?? 'The Sales Order could not be created.';
            },
            onFinish: () => {
                isSubmitting.value = false;
            },
        },
    );
};
// Action Confirmation Modal State
interface ConfirmActionOptions {
    title: string;
    description: string;
    details?: Array<{ label: string; value: string | number }>;
    confirmText?: string;
    variant?: 'brand' | 'danger';
    action: () => void;
}

const showConfirmModal = ref(false);
const confirmDeleteInput = ref('');
const confirmOptions = ref<ConfirmActionOptions>({
    title: 'Confirm Action',
    description: 'Are you sure you want to proceed?',
    details: [],
    confirmText: 'Confirm & Record',
    variant: 'brand',
    action: () => {},
});

const openConfirmModal = (options: ConfirmActionOptions) => {
    confirmDeleteInput.value = '';
    confirmOptions.value = {
        confirmText: 'Confirm & Record',
        variant: 'brand',
        ...options,
    };
    showConfirmModal.value = true;
};

const handleConfirmedAction = () => {
    if (confirmOptions.value.variant === 'danger' && confirmDeleteInput.value !== 'YES') {
        return;
    }
    showConfirmModal.value = false;
    confirmOptions.value.action();
};

const saleToDelete = ref<Sale | null>(null);

const promptDeleteSale = (sale: Sale) => {
    saleToDelete.value = sale;
    const isCompleted = sale.status === 'completed';
    openConfirmModal({
        title: isCompleted ? 'Move Sale Transaction to Recycle Bin' : 'Archive Cancelled Order',
        description: isCompleted
            ? `Move completed order "${sale.order_number}" for ${sale.client_name} to the Recycle Bin? Its items will be returned to stock while archived, and an Admin can restore or permanently delete it from the Recycle Bin. Unrelated active sessions and trusted devices are not affected.`
            : `Archive cancelled order "${sale.order_number}" for ${sale.client_name}? Its stock was already handled when it was cancelled; it can be restored or permanently deleted from the Recycle Bin. Unrelated active sessions and trusted devices are not affected.`,
        details: [
            { label: 'Order #', value: sale.order_number },
            { label: 'Customer', value: sale.client_name },
            { label: 'Grand Total', value: formatCurrency(Number(sale.grand_total)) },
        ],
        confirmText: 'Move to Recycle Bin',
        variant: 'danger',
        action: () => deleteSale(sale),
    });
};

const deleteSale = (sale: Sale) => {
    if (confirmDeleteInput.value !== 'YES') return;
    isSubmitting.value = true;
    router.delete(`/${teamSlug.value}/sales/${sale.id}`, {
        data: { confirmation: confirmDeleteInput.value },
        preserveScroll: true,
        onFinish: () => {
            isSubmitting.value = false;
            saleToDelete.value = null;
        },
    });
};

</script>

<template>
    <Head title="Sales Management" />

    <div class="workspace-page">
        <!-- Header -->
        <div class="page-header">
            <div>
                <h1 class="page-title">Sales Orders</h1>
                <p class="page-subtitle">Orders from accepted quotations: payments, invoices and delivery in one place.</p>
            </div>
            <SpecularButton variant="brand" size="md" :radius="12" @click="openOrderModal" class="w-full sm:w-auto">
                <Plus class="w-4 h-4" />
                New Sales Order
            </SpecularButton>
        </div>

        <!-- Metrics -->
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
            <div
                v-for="card in [
                    { label: 'Sales today (confirmed)', value: formatCurrency(metrics.todaySales), alert: '' },
                    { label: 'Sales this month', value: formatCurrency(metrics.monthSales), alert: '' },
                    { label: 'Payments to verify', value: metrics.toVerify, alert: Number(metrics.toVerify) > 0 ? 'text-amber-600' : '' },
                    { label: 'Outstanding balances', value: formatCurrency(metrics.outstanding), alert: '' },
                ]"
                :key="card.label"
                class="bg-white border border-gray-200 rounded-xl px-4 py-3"
            >
                <div class="text-xs text-gray-500">{{ card.label }}</div>
                <div :class="card.alert || 'text-gray-900'" class="mt-1 text-2xl font-semibold">{{ card.value }}</div>
            </div>
        </div>

        <!-- Filters -->
        <div class="rounded-xl border border-gray-200 bg-white p-4">
            <div class="flex flex-col gap-3 md:flex-row md:flex-wrap md:items-center">
                <div class="relative min-w-0 md:min-w-64 md:flex-1">
                    <Search class="pointer-events-none absolute left-3.5 top-1/2 size-4 -translate-y-1/2 text-gray-400" />
                    <input v-model="search" @input="applyFilter" type="search" aria-label="Search orders"
                           placeholder="Order #, quotation #, invoice # or customer" class="field-control pl-10" />
                </div>
                <select v-model="selectedStatus" @change="applyFilter" aria-label="Order status" class="field-control md:w-44">
                    <option value="All">All order statuses</option>
                    <option v-for="o in options.status" :key="o.value" :value="o.value">{{ o.label }}</option>
                </select>
                <select v-model="selectedPayment" @change="applyFilter" aria-label="Payment status" class="field-control md:w-44">
                    <option value="All">All payment statuses</option>
                    <option v-for="o in options.payment_status" :key="o.value" :value="o.value">{{ o.label }}</option>
                </select>
                <select v-model="selectedDelivery" @change="applyFilter" aria-label="Delivery status" class="field-control md:w-44">
                    <option value="All">All delivery statuses</option>
                    <option v-for="o in options.delivery_status" :key="o.value" :value="o.value">{{ o.label }}</option>
                </select>
                <input v-model="dateFrom" @change="applyFilter" type="date" aria-label="From date" class="field-control md:w-40" />
                <input v-model="dateTo" @change="applyFilter" type="date" aria-label="To date" class="field-control md:w-40" />
                <select v-model="sort" @change="applyFilter" aria-label="Sort" class="field-control md:w-44">
                    <option value="newest">Newest first</option>
                    <option value="oldest">Oldest first</option>
                    <option value="total_desc">Largest total</option>
                    <option value="balance_desc">Largest balance</option>
                </select>
                <button v-if="hasFilters" type="button" class="secondary-button shrink-0" @click="resetFilters">Reset</button>
            </div>
        </div>

        <!-- Orders -->
        <div class="rounded-xl border border-gray-200 bg-white overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm text-gray-600">
                    <thead class="border-b border-gray-200">
                        <tr>
                            <th class="whitespace-nowrap">Order</th>
                            <th class="whitespace-nowrap">Customer</th>
                            <th class="whitespace-nowrap text-right">Total</th>
                            <th class="whitespace-nowrap text-right">Paid / Balance</th>
                            <th class="whitespace-nowrap">Order</th>
                            <th class="whitespace-nowrap">Payment</th>
                            <th class="whitespace-nowrap">Delivery</th>
                            <th class="whitespace-nowrap text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <tr v-if="!sales.data.length">
                            <td colspan="8" class="py-10 text-center text-gray-500">
                                No orders match. Create one from an accepted quotation with New Sales Order.
                            </td>
                        </tr>
                        <tr v-for="sale in sales.data" :key="sale.id">
                            <td class="whitespace-nowrap">
                                <a :href="`/${teamSlug}/sales/${sale.id}`" class="font-mono font-semibold text-gray-900 hover:underline">{{ sale.order_number }}</a>
                                <div class="text-[11px] text-gray-500">
                                    {{ new Date(sale.sale_date).toLocaleDateString() }}
                                    · {{ sale.channel === 'counter' ? 'Counter' : (sale.quotation?.quotation_number ?? 'Quotation') }}
                                </div>
                                <div v-if="sale.invoice_number" class="font-mono text-[11px] text-gray-400">{{ sale.invoice_number }}</div>
                            </td>
                            <td class="min-w-40 font-medium text-gray-900">{{ sale.client_name }}</td>
                            <td class="whitespace-nowrap text-right font-semibold text-gray-900">{{ formatCurrency(Number(sale.grand_total)) }}</td>
                            <td class="whitespace-nowrap text-right text-xs">
                                <div class="text-gray-900">{{ formatCurrency(Number(sale.amount_paid || 0)) }}</div>
                                <div v-if="Number(sale.outstanding_balance || 0) > 0" class="font-semibold text-rose-600">{{ formatCurrency(Number(sale.outstanding_balance)) }} due</div>
                            </td>
                            <td class="whitespace-nowrap"><StatusBadge :status="sale.status_label ?? sale.status" size="sm" /><div v-if="sale.is_on_hold" class="mt-0.5 text-[11px] font-medium text-amber-700">On hold</div></td>
                            <td class="whitespace-nowrap">
                                <StatusBadge :status="sale.payment_status_label ?? sale.payment_status" size="sm" />
                                <div v-if="sale.payment_terms && sale.payment_terms !== 'full'" class="mt-0.5 text-[11px] text-gray-500">{{ sale.payment_terms === 'credit' ? 'Credit terms' : 'Down payment' }}</div>
                            </td>
                            <td class="whitespace-nowrap">
                                <StatusBadge v-if="sale.delivery_status_label" :status="sale.delivery_status_label" size="sm" />
                                <span v-else class="text-xs text-gray-400">Starts when confirmed</span>
                                <div class="mt-0.5 text-[11px] text-gray-500">{{ sale.fulfillment_type === 'pickup' ? 'Pickup' : 'Delivery' }}</div>
                            </td>
                            <td class="whitespace-nowrap text-right">
                                <div class="inline-flex items-center gap-1.5">
                                    <a :href="`/${teamSlug}/sales/${sale.id}`" class="secondary-button min-h-8 px-3 text-xs">Open</a>
                                    <button v-if="canDelete && ['cancelled', 'completed'].includes(sale.status)" type="button" title="Move to Recycle Bin"
                                            class="rounded-lg p-1.5 text-gray-400 hover:bg-rose-50 hover:text-rose-600" @click="promptDeleteSale(sale)">
                                        <Trash2 class="size-4" />
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <div v-if="sales.last_page > 1" class="flex flex-col gap-3 border-t border-gray-100 p-4 sm:flex-row sm:items-center sm:justify-between">
                <span class="text-xs text-gray-500">Showing {{ sales.data.length }} of {{ sales.total }} orders</span>
                <div class="flex flex-wrap gap-1">
                    <button
                        v-for="(link, i) in sales.links"
                        :key="i"
                        :disabled="!link.url || link.active"
                        @click="link.url && router.visit(link.url, { preserveScroll: true })"
                        v-html="link.label"
                        :class="[
                            'inline-flex h-8 min-w-8 items-center justify-center rounded-lg border px-3 text-xs transition-colors',
                            link.active ? 'bg-brand text-white border-brand font-semibold' : 'bg-white border-gray-200 text-gray-600 hover:bg-gray-50',
                            !link.url ? 'opacity-50 cursor-not-allowed' : ''
                        ]"
                    />
                </div>
            </div>
        </div>
        <!-- New Sales Order (from an accepted quotation) -->
        <div v-if="orderModalOpen" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4" @click.self="closeOrderModal">
            <form class="flex max-h-[92vh] w-full max-w-3xl flex-col overflow-hidden rounded-xl border border-gray-200 bg-white shadow-xl" role="dialog" aria-modal="true" aria-labelledby="so-title" novalidate @submit.prevent="createSalesOrder">
                <div class="flex items-start justify-between gap-3 border-b border-gray-100 px-6 py-4">
                    <div>
                        <h2 id="so-title" class="text-lg font-semibold text-gray-900">New Sales Order</h2>
                        <p class="text-xs text-gray-500">From a quotation the customer accepted. Products, quantities and agreed prices are taken from it as they are.</p>
                    </div>
                    <button type="button" class="text-xl font-semibold text-gray-400 hover:text-gray-600" aria-label="Close" @click="closeOrderModal">&times;</button>
                </div>

                <div class="flex-1 space-y-4 overflow-y-auto px-6 py-4">
                    <div v-if="!(quotations ?? []).length" class="rounded-xl border border-dashed border-gray-200 px-4 py-6 text-center text-sm text-gray-500">
                        No accepted quotations are waiting for a Sales Order. Record the customer's acceptance in Quotations first.
                    </div>
                    <template v-else>
                        <!-- Search (long lists only) -->
                        <div v-if="showQuotationSearch" class="relative">
                            <Search class="pointer-events-none absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-gray-400" />
                            <input id="so-quotation-search" v-model="quotationSearch" type="search" placeholder="Search quotation number or customer" aria-label="Search quotations" class="field-control w-full pl-9" />
                        </div>

                        <!-- Select Quotation (floating label) -->
                        <div>
                            <div class="relative">
                                <select id="so-quotation" v-model="selectedQuotationId" required
                                        :class="['peer block w-full rounded-xl border bg-white px-3.5 pt-5 pb-2 text-xs text-gray-900 focus:border-[#4b5320] focus:ring-1 focus:ring-[#4b5320] focus:outline-none sm:text-sm', quotationError ? 'border-rose-400' : 'border-gray-200', selectedQuotationId === null && !quotationFocus ? 'text-transparent' : '']"
                                        :aria-invalid="!!quotationError" :aria-describedby="quotationError ? 'so-quotation-error' : undefined"
                                        @focus="quotationFocus = true" @blur="quotationFocus = false">
                                    <option :value="null" disabled>Select Quotation</option>
                                    <option v-for="q in quotationChoices" :key="q.id" :value="q.id" class="text-gray-900">{{ q.quotation_number }} — {{ q.client_name }}</option>
                                </select>
                                <label for="so-quotation"
                                       :class="['pointer-events-none absolute top-3.5 left-3.5 origin-[0] transform text-xs font-semibold duration-200 sm:text-sm', selectedQuotationId !== null || quotationFocus ? '-translate-y-2.5 scale-75 text-[#4b5320]' : 'text-gray-500']">Select Quotation</label>
                            </div>
                            <p v-if="quotationError" id="so-quotation-error" class="mt-1 text-xs text-rose-600">{{ quotationError }}</p>
                            <p v-else-if="showQuotationSearch && quotationSearch && !quotationChoices.length" class="mt-1 text-xs text-gray-500">No accepted quotation matches "{{ quotationSearch }}".</p>
                        </div>

                        <!-- Auto-filled, read-only -->
                        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                            <div class="relative">
                                <input id="so-customer" :value="selectedQuotation?.client_name ?? ''" type="text" readonly tabindex="-1" placeholder=" "
                                       class="peer block w-full cursor-default rounded-xl border border-gray-200 bg-gray-50 px-3.5 pt-5 pb-2 text-xs text-gray-900 focus:outline-none sm:text-sm" />
                                <label for="so-customer" :class="['pointer-events-none absolute top-3.5 left-3.5 origin-[0] transform text-xs font-semibold duration-200 sm:text-sm', selectedQuotation ? '-translate-y-2.5 scale-75 text-[#4b5320]' : 'text-gray-500']">Customer Name</label>
                            </div>
                            <div class="relative">
                                <input id="so-quotation-number" :value="selectedQuotation?.quotation_number ?? ''" type="text" readonly tabindex="-1" placeholder=" "
                                       class="peer block w-full cursor-default rounded-xl border border-gray-200 bg-gray-50 px-3.5 pt-5 pb-2 font-mono text-xs text-gray-900 focus:outline-none sm:text-sm" />
                                <label for="so-quotation-number" :class="['pointer-events-none absolute top-3.5 left-3.5 origin-[0] transform text-xs font-semibold duration-200 sm:text-sm', selectedQuotation ? '-translate-y-2.5 scale-75 text-[#4b5320]' : 'text-gray-500']">Quotation Number</label>
                            </div>
                        </div>

                        <!-- Sales Order Summary -->
                        <section aria-labelledby="so-summary-title">
                            <h3 id="so-summary-title" class="mb-2 text-sm font-semibold text-gray-900">Sales Order Summary</h3>
                            <div class="overflow-x-auto rounded-xl border border-gray-200">
                                <table class="w-full text-left text-sm">
                                    <thead class="bg-gray-50 text-xs text-gray-600">
                                        <tr>
                                            <th scope="col" class="px-3 py-2 font-semibold">Product Description</th>
                                            <th scope="col" class="px-3 py-2 text-right font-semibold whitespace-nowrap">QTY Ordered</th>
                                            <th scope="col" class="px-3 py-2 font-semibold">Unit</th>
                                            <th scope="col" class="px-3 py-2 text-right font-semibold whitespace-nowrap">Unit Price</th>
                                            <th scope="col" class="px-3 py-2 text-right font-semibold">Amount</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-100">
                                        <tr v-if="!selectedQuotation">
                                            <td colspan="5" class="px-3 py-6 text-center text-xs text-gray-400">Select a quotation to see its products.</td>
                                        </tr>
                                        <tr v-for="line in selectedQuotation?.items ?? []" :key="line.id">
                                            <td class="px-3 py-2">
                                                <div class="text-gray-900">{{ line.description }}</div>
                                                <div v-if="line.sku" class="font-mono text-[11px] text-gray-500">{{ line.sku }}</div>
                                            </td>
                                            <td class="px-3 py-2 text-right font-mono">{{ line.quantity }}</td>
                                            <td class="px-3 py-2 text-gray-700">{{ line.unit || '—' }}</td>
                                            <td class="px-3 py-2 text-right font-mono whitespace-nowrap">{{ formatCurrency(line.unit_price) }}</td>
                                            <td class="px-3 py-2 text-right font-mono whitespace-nowrap">{{ formatCurrency(line.amount) }}</td>
                                        </tr>
                                    </tbody>
                                    <tfoot v-if="selectedQuotation" class="border-t border-gray-200 text-sm">
                                        <tr v-if="selectedQuotation.discount > 0 || selectedQuotation.tax_amount > 0">
                                            <td colspan="4" class="px-3 pt-2 text-right text-xs text-gray-500">Subtotal</td>
                                            <td class="px-3 pt-2 text-right font-mono whitespace-nowrap">{{ formatCurrency(selectedQuotation.subtotal) }}</td>
                                        </tr>
                                        <tr v-if="selectedQuotation.discount > 0">
                                            <td colspan="4" class="px-3 text-right text-xs text-gray-500">Discount</td>
                                            <td class="px-3 text-right font-mono whitespace-nowrap">-{{ formatCurrency(selectedQuotation.discount) }}</td>
                                        </tr>
                                        <tr v-if="selectedQuotation.tax_amount > 0">
                                            <td colspan="4" class="px-3 text-right text-xs text-gray-500">VAT ({{ selectedQuotation.tax_rate }}%)</td>
                                            <td class="px-3 text-right font-mono whitespace-nowrap">{{ formatCurrency(selectedQuotation.tax_amount) }}</td>
                                        </tr>
                                        <tr>
                                            <td colspan="4" class="px-3 py-2 text-right font-semibold text-gray-900">Total Amount</td>
                                            <td id="so-total" class="px-3 py-2 text-right font-mono text-base font-semibold whitespace-nowrap text-gray-900">{{ formatCurrency(selectedQuotation.grand_total) }}</td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                            <p v-if="conversionNote" class="mt-1 text-[11px] text-gray-500">{{ conversionNote }}</p>
                        </section>

                        <!-- How the order reaches the customer (Outbound Delivery uses it) -->
                        <div v-if="selectedQuotation" class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                            <fieldset class="sm:col-span-1">
                                <legend class="field-label">Fulfillment</legend>
                                <div class="grid grid-cols-2 gap-2 sm:grid-cols-1">
                                    <label v-for="opt in [{ v: 'delivery', l: 'Delivery' }, { v: 'pickup', l: 'Customer pickup' }]" :key="opt.v"
                                           :class="fulfillmentType === opt.v ? 'border-[#4b5320] bg-[#4b5320]/5' : 'border-gray-200'"
                                           class="flex cursor-pointer items-center gap-2 rounded-xl border px-3 py-2 text-sm">
                                        <input v-model="fulfillmentType" type="radio" name="so-fulfillment" :value="opt.v" class="accent-[#4b5320]" />
                                        {{ opt.l }}
                                    </label>
                                </div>
                            </fieldset>
                            <div v-if="fulfillmentType === 'delivery'" class="sm:col-span-2">
                                <label class="field-label" for="so-address">Delivery address</label>
                                <textarea id="so-address" v-model="deliveryAddress" rows="3" maxlength="500" class="field-control w-full"></textarea>
                            </div>
                        </div>

                        <p class="text-xs text-gray-500">The order starts as Pending payment. Record the customer's payment on the order page; stock is reserved when the order is confirmed and taken out by Outbound Delivery.</p>
                    </template>
                </div>

                <div class="flex flex-col-reverse gap-2 border-t border-gray-100 bg-gray-50/50 px-6 py-3 sm:flex-row sm:justify-end">
                    <button type="button" class="secondary-button" @click="closeOrderModal">Cancel</button>
                    <button type="submit" class="brand-button" :disabled="isSubmitting || !(quotations ?? []).length">{{ isSubmitting ? 'Creating…' : 'Create Sales Order' }}</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Action Confirmation Modal -->
    <div v-if="showConfirmModal" class="fixed inset-0 z-[60] flex items-center justify-center bg-black/60 p-4">
        <div class="bg-white border border-gray-200 p-6 rounded-xl w-full max-w-md shadow-xl animate-in fade-in duration-150">
            <div
                :class="[
                    'w-11 h-11 rounded-full flex items-center justify-center mb-4 border',
                    confirmOptions.variant === 'danger'
                        ? 'bg-red-50 text-red-600 border-red-100'
                        : 'bg-amber-50 text-[#F8B803] border-amber-100'
                ]"
            >
                <Trash2 v-if="confirmOptions.variant === 'danger'" class="w-6 h-6" />
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

            <div v-if="confirmOptions.variant === 'danger'" class="mt-4 mb-6">
                <label class="block text-xs font-semibold text-gray-700 mb-1.5">
                    Type <span class="font-mono font-semibold text-rose-600 bg-rose-50 px-1.5 py-0.5 rounded border border-rose-200">YES</span> to confirm deletion:
                </label>
                <input
                    v-model="confirmDeleteInput"
                    type="text"
                    placeholder="Type YES here"
                    class="w-full px-3 py-2 text-sm font-mono rounded-xl border border-gray-300 focus:outline-none focus:border-rose-500 focus:ring-1 focus:ring-rose-500"
                    @keyup.enter="confirmDeleteInput === 'YES' && handleConfirmedAction()"
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
                    :variant="confirmOptions.variant === 'danger' ? 'danger' : 'brand'"
                    size="md"
                    :radius="10"
                    @click="handleConfirmedAction"
                    :disabled="isSubmitting || (confirmOptions.variant === 'danger' && confirmDeleteInput !== 'YES')"
                >
                    <Trash2 v-if="confirmOptions.variant === 'danger'" class="w-4 h-4 mr-1.5 inline" />
                    <Check v-else class="w-4 h-4 mr-1.5 inline" />
                    <span>{{ confirmOptions.confirmText || 'Confirm' }}</span>
                </SpecularButton>
            </div>
        </div>
    </div>
</template>
