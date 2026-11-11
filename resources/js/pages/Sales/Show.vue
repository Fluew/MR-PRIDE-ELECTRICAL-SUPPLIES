<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { AlertTriangle, ArrowLeft, Download, ExternalLink, FileText, Plus, Truck } from '@lucide/vue';
import { computed, ref } from 'vue';
import StatusBadge from '@/components/ui/StatusBadge.vue';
import { useCurrency } from '@/composables/useCurrency';

interface Line {
    id: number;
    sku: string;
    product_name: string;
    quantity: number;
    unit_price: number;
    discount: number;
    subtotal: number;
    reserved_quantity: number;
    deducted_quantity: number;
    released_quantity: number;
    delivered_quantity: number;
    cancelled_quantity: number;
    remaining_quantity: number;
    releasable_quantity: number;
    product_id?: number | null;
}

interface Payment {
    id: number;
    method: string;
    submitted_amount: number;
    submitted_reference?: string | null;
    payment_date: string;
    remarks?: string | null;
    status: 'submitted' | 'under_verification' | 'verified' | 'rejected' | 'refunded';
    status_label: string;
    verified_reference?: string | null;
    verified_amount?: number | null;
    verified_payment_date?: string | null;
    verified_at?: string | null;
    verification_remarks?: string | null;
    rejection_reason?: string | null;
    refund_reason?: string | null;
    created_at: string;
    proof_url?: string | null;
    recorded_by?: { name: string } | null;
    verified_by?: { name: string } | null;
    rejected_by?: { name: string } | null;
    receipt?: { id: number; receipt_number: string; pdf_url: string } | null;
}

interface Invoice {
    id: number;
    invoice_number: string;
    status: 'issued' | 'cancelled';
    grand_total: number;
    issued_at: string;
    cancelled_at?: string | null;
    cancellation_reason?: string | null;
    pdf_url: string;
    issued_by?: { name: string } | null;
}

interface Release {
    id: number;
    release_number: string;
    type: 'delivery' | 'pickup';
    status: string;
    dispatched_at?: string | null;
    delivered_at?: string | null;
    received_by_name?: string | null;
    returned_at?: string | null;
    return_reason?: string | null;
    proof_url?: string | null;
    created_at: string;
    items: { id: number; quantity: number; delivered_quantity: number; returned_quantity: number; sale_item?: { sku: string; product_name: string } | null }[];
}

interface HistoryEntry {
    id: number;
    attempt_number: number;
    previous_status: string;
    new_status: string;
    failure_reason?: string | null;
    remarks?: string | null;
    attempt_date: string;
    staff?: { name: string } | null;
}

const props = defineProps<{
    sale: {
        id: number;
        order_number: string;
        channel: 'quotation' | 'counter';
        fulfillment_type: 'delivery' | 'pickup';
        invoice_number?: string | null;
        client_name: string;
        contact_person?: string | null;
        email?: string | null;
        phone?: string | null;
        address?: string | null;
        delivery_instructions?: string | null;
        sale_date: string;
        subtotal: number;
        discount: number;
        tax_amount: number;
        grand_total: number;
        amount_paid: number;
        outstanding_balance: number;
        status: string;
        status_label: string;
        payment_status: string;
        payment_status_label: string;
        payment_terms: 'full' | 'down_payment' | 'credit';
        required_down_payment?: number | null;
        credit_days?: number | null;
        credit_due_date?: string | null;
        confirmed_at?: string | null;
        completed_at?: string | null;
        cancelled_at?: string | null;
        cancellation_reason?: string | null;
        hold_reason?: string | null;
        is_on_hold?: boolean;
        notes?: string | null;
        quotation?: { id: number; quotation_number: string } | null;
        processed_by?: { name: string } | null;
        confirmed_by?: { name: string } | null;
        terms_approved_by?: { name: string } | null;
        items: Line[];
        payments: Payment[];
        receipts: { id: number; receipt_number: string; amount_received: number; balance_after: number; generated_at: string; pdf_url: string }[];
        invoices: Invoice[];
        releases: Release[];
        fulfillment?: {
            id: number;
            fulfillment_number: string;
            status: string;
            status_label: string;
            attempts_display: string;
            handled_by?: { name: string } | null;
            delivery_schedule?: string | null;
            attempt_logs: HistoryEntry[];
        } | null;
    };
    deliveryStatusLabel?: string | null;
    notifications: { id: number; category: string; type: string; notifiable_type?: string; notifiable_id?: number; recipient?: string | null; subject?: string | null; status: string; attempts: number; error?: string | null; sent_at?: string | null; created_at: string }[];
    activity: { id: number; event: string; description: string; user_email?: string | null; outcome: string; created_at: string }[];
    paymentMethods: string[];
    can: { verify: boolean; admin: boolean };
    company: { bir_registered: boolean };
}>();

const page = usePage();
const teamSlug = computed(() => (page.props as any).currentTeam?.slug || '');
const base = computed(() => `/${teamSlug.value}/sales/${props.sale.id}`);
const { formatMoney } = useCurrency();

const fmtDate = (v?: string | null) => (v ? new Date(v).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }) : '—');
const fmtDateTime = (v?: string | null) => (v ? new Date(v).toLocaleString('en-US', { month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit' }) : '—');
const today = new Date().toISOString().slice(0, 10);

const isOpen = computed(() => !['completed', 'cancelled'].includes(props.sale.status));
const isConfirmed = computed(() => ['confirmed', 'processing'].includes(props.sale.status));
const pendingPayments = computed(() => props.sale.payments.filter((p) => ['submitted', 'under_verification'].includes(p.status)));
const hasShortage = computed(() => isConfirmed.value && props.sale.items.some((l) => l.product_id && l.quantity - l.cancelled_quantity - l.deducted_quantity - l.reserved_quantity > 0));
const canComplete = computed(
    () => isConfirmed.value && props.sale.items.every((l) => l.remaining_quantity === 0) && props.sale.fulfillment?.status === 'delivered'
        && (props.sale.payment_terms !== 'full' || Number(props.sale.outstanding_balance) <= 0),
);
const termsLabel = computed(() => {
    if (props.sale.payment_terms === 'credit') return `Credit terms, ${props.sale.credit_days} days${props.sale.credit_due_date ? ` (due ${fmtDate(props.sale.credit_due_date)})` : ''}`;
    if (props.sale.payment_terms === 'down_payment') return `Down payment of ${formatMoney(props.sale.required_down_payment ?? 0)} before confirmation`;
    return 'Full payment before confirmation';
});
const statusText = (code: string) => code.replace(/_/g, ' ').replace(/^\w/, (c) => c.toUpperCase());

// ------------------------------------------------------------------------------ actions
const busy = ref(false);
const post = (url: string, data: Record<string, any> = {}, onSuccess?: () => void, files = false) => {
    busy.value = true;
    router.post(url, data, {
        preserveScroll: true,
        forceFormData: files,
        onSuccess: () => onSuccess?.(),
        onFinish: () => {
            busy.value = false;
        },
    });
};

// Record a payment the customer submitted.
const showPayment = ref(false);
const paymentForm = ref({ method: 'Bank Transfer', amount: '' as number | '', reference: '', payment_date: today, remarks: '', proof: null as File | null, idempotency_key: '' });
const openPayment = () => {
    paymentForm.value = {
        method: props.paymentMethods[0] ?? 'Bank Transfer',
        amount: Number(props.sale.outstanding_balance) > 0 ? Number(props.sale.outstanding_balance) : '',
        reference: '',
        payment_date: today,
        remarks: '',
        proof: null,
        idempotency_key: crypto.randomUUID(),
    };
    showPayment.value = true;
};
const submitPayment = () => post(`${base.value}/payments`, { ...paymentForm.value, proof: paymentForm.value.proof ?? undefined }, () => (showPayment.value = false), true);

// Verify against the actual bank / GCash transaction.
const verifying = ref<Payment | null>(null);
const verifyForm = ref({ reference: '', amount: 0 as number, payment_date: today, remarks: '' });
const openVerify = (p: Payment) => {
    verifying.value = p;
    verifyForm.value = { reference: p.submitted_reference ?? '', amount: Number(p.submitted_amount), payment_date: p.payment_date?.slice(0, 10) ?? today, remarks: '' };
    if (p.status === 'submitted') router.post(`${base.value}/payments/${p.id}/review`, {}, { preserveScroll: true, preserveState: true });
};
const submitVerify = () => {
    if (!verifying.value) return;
    post(`${base.value}/payments/${verifying.value.id}/verify`, verifyForm.value, () => (verifying.value = null));
};

// Reason prompts: reject / refund a payment, hold / cancel the order, cancel the remaining quantity, cancel an invoice.
const reasonPrompt = ref<{ title: string; help: string; confirm: string; danger?: boolean; url: string; extra?: Record<string, any>; reissue?: boolean } | null>(null);
const reason = ref('');
const reissue = ref(true);
const ask = (p: NonNullable<typeof reasonPrompt.value>) => {
    reason.value = '';
    reissue.value = true;
    reasonPrompt.value = p;
};
const submitReason = () => {
    if (!reasonPrompt.value || !reason.value.trim()) return;
    const extra = { ...(reasonPrompt.value.extra ?? {}), ...(reasonPrompt.value.reissue ? { reissue: reissue.value } : {}) };
    post(reasonPrompt.value.url, { reason: reason.value.trim(), ...extra }, () => (reasonPrompt.value = null));
};

// Payment terms (administrator)
const showTerms = ref(false);
const termsForm = ref({ payment_terms: props.sale.payment_terms, required_down_payment: props.sale.required_down_payment ?? '', credit_days: props.sale.credit_days ?? 30 });
const submitTerms = () => post(`${base.value}/terms`, termsForm.value, () => (showTerms.value = false));

const retryEmail = (id: number) => post(`${base.value}/notifications/${id}/retry`);

// "Send Commercial Invoice": manual, available once the order is confirmed. Each invoice shows
// when it was last emailed (from the email log); a second send is an explicit Resend.
const canSendInvoice = computed(() => props.can.verify && ['confirmed', 'processing', 'completed'].includes(props.sale.status) && !!props.sale.email);
const invoiceEmails = (inv: Invoice) => props.notifications.filter((n) => n.notifiable_type === 'SalesInvoice' && n.notifiable_id === inv.id && n.type === 'invoice_issued');
const lastInvoiceSend = (inv: Invoice) => invoiceEmails(inv).find((n) => n.status === 'sent') ?? null;
const invoiceSending = (inv: Invoice) => invoiceEmails(inv)[0]?.status === 'pending';
const invoiceSendFailed = (inv: Invoice) => invoiceEmails(inv)[0]?.status === 'failed';
const sendingInvoice = ref<{ invoice: Invoice; resend: boolean } | null>(null);
const confirmSendInvoice = () => {
    if (!sendingInvoice.value) return;
    const { invoice, resend } = sendingInvoice.value;
    post(`${base.value}/invoices/${invoice.id}/send`, { resend }, () => (sendingInvoice.value = null));
};
</script>

<template>
    <Head :title="`${sale.order_number} — Sales Order`" />

    <div class="workspace-page">
        <!-- Header -->
        <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
            <div class="min-w-0">
                <Link :href="`/${teamSlug}/sales`" class="mb-2 inline-flex items-center gap-1 text-xs text-gray-500 hover:text-gray-900">
                    <ArrowLeft class="size-3.5" /> Sales Orders
                </Link>
                <h1 class="page-title font-mono">{{ sale.order_number }}</h1>
                <p class="page-subtitle">
                    {{ sale.client_name }} · {{ fmtDate(sale.sale_date) }} ·
                    <template v-if="sale.quotation">
                        from <Link :href="`/${teamSlug}/quotations?search=${sale.quotation.quotation_number}`" class="font-mono hover:underline">{{ sale.quotation.quotation_number }}</Link>
                    </template>
                    <template v-else>counter sale</template>
                </p>
                <div class="mt-3 flex flex-wrap items-center gap-2 text-xs">
                    <span class="text-gray-500">Order</span><StatusBadge :status="sale.status_label" />
                    <span class="ml-2 text-gray-500">Payment</span><StatusBadge :status="sale.payment_status_label" />
                    <span class="ml-2 text-gray-500">{{ sale.fulfillment_type === 'pickup' ? 'Pickup' : 'Delivery' }}</span>
                    <StatusBadge v-if="deliveryStatusLabel" :status="deliveryStatusLabel" />
                    <span v-else class="text-gray-400">starts when the order is confirmed</span>
                </div>
            </div>
            <div class="flex flex-wrap gap-2">
                <a :href="`${base}/pdf`" target="_blank" class="secondary-button"><Download class="size-4" /> Sales Order PDF</a>
                <button v-if="sale.status !== 'cancelled'" type="button" class="brand-button" @click="openPayment"><Plus class="size-4" /> Record payment</button>
            </div>
        </div>

        <div v-if="sale.is_on_hold" class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">On hold: {{ sale.hold_reason }}</div>
        <div v-if="sale.status === 'cancelled'" class="rounded-xl border border-gray-200 bg-gray-50 px-4 py-3 text-sm text-gray-700">
            Cancelled {{ fmtDate(sale.cancelled_at) }}: {{ sale.cancellation_reason }}
            <span v-if="Number(sale.amount_paid) > 0" class="font-medium"> · {{ formatMoney(sale.amount_paid) }} in verified payments is still on record; record a refund if money was returned.</span>
        </div>

        <!-- Summary -->
        <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
            <div class="panel px-4 py-3"><div class="text-xs text-gray-500">Order total</div><div class="mt-1 text-2xl font-semibold text-gray-900">{{ formatMoney(sale.grand_total) }}</div></div>
            <div class="panel px-4 py-3"><div class="text-xs text-gray-500">Verified payments</div><div class="mt-1 text-2xl font-semibold text-gray-900">{{ formatMoney(sale.amount_paid) }}</div></div>
            <div class="panel px-4 py-3"><div class="text-xs text-gray-500">Balance</div><div class="mt-1 text-2xl font-semibold" :class="Number(sale.outstanding_balance) > 0 ? 'text-rose-600' : 'text-gray-900'">{{ formatMoney(sale.outstanding_balance) }}</div></div>
            <div class="panel px-4 py-3">
                <div class="text-xs text-gray-500">Payment terms</div>
                <div class="mt-1 text-sm font-medium text-gray-900">{{ termsLabel }}</div>
                <div v-if="sale.terms_approved_by" class="text-[11px] text-gray-500">Approved by {{ sale.terms_approved_by.name }}</div>
                <button v-if="can.admin && sale.status === 'pending'" type="button" class="mt-1 text-xs font-medium text-brand hover:underline" @click="showTerms = true">Change terms</button>
            </div>
        </div>

        <!-- Order actions -->
        <div v-if="isOpen" class="panel flex flex-wrap items-center gap-2 p-4">
            <span class="mr-2 text-sm font-medium text-gray-700">Order actions</span>
            <button v-if="canComplete" type="button" class="brand-button" :disabled="busy" @click="post(`${base}/complete`)">Mark order completed</button>
            <span v-else-if="isConfirmed" class="text-xs text-gray-500">The order can be completed when everything is delivered{{ sale.payment_terms === 'full' ? ' and paid' : '' }}.</span>
            <button v-if="hasShortage" type="button" class="secondary-button" :disabled="busy" @click="post(`${base}/reserve`)">Reserve arrived stock</button>
            <template v-if="can.admin">
                <button v-if="isConfirmed && sale.items.some((l) => l.releasable_quantity > 0)" type="button" class="secondary-button"
                        @click="ask({ title: 'Cancel remaining quantities', help: 'Everything not yet delivered or on its way stops being owed. Reserved stock is released. Totals are not changed: adjust the invoice if needed.', confirm: 'Cancel remaining', danger: true, url: `${base}/cancel-remaining` })">
                    Cancel remaining quantities
                </button>
                <button v-if="!sale.is_on_hold" type="button" class="secondary-button" @click="ask({ title: 'Put order on hold', help: 'The order keeps its status, but nothing is confirmed or released until it is resumed.', confirm: 'Put on hold', url: `${base}/hold` })">Hold</button>
                <button v-else type="button" class="secondary-button" :disabled="busy" @click="post(`${base}/resume`)">Resume</button>
                <button v-if="sale.items.every((l) => l.delivered_quantity === 0)" type="button" class="inline-flex min-h-10 items-center rounded-xl border border-rose-200 bg-white px-4 py-2 text-sm font-medium text-rose-600 hover:bg-rose-50"
                        @click="ask({ title: `Cancel order ${sale.order_number}`, help: 'Reserved stock is released, an issued invoice is cancelled and the customer is emailed. Verified payments stay on record.', confirm: 'Cancel order', danger: true, url: `${base}/cancel` })">
                    Cancel order
                </button>
            </template>
        </div>

        <!-- Items -->
        <section class="panel overflow-hidden">
            <div class="flex items-center justify-between border-b border-gray-200 px-4 py-3">
                <h2 class="text-sm font-semibold text-gray-900">Items</h2>
                <span class="text-xs text-gray-500">Reserved = held in stock for this order · Released = sent out</span>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-left">
                    <thead>
                        <tr>
                            <th>Product</th>
                            <th class="text-right">Ordered</th>
                            <th class="text-right">Reserved</th>
                            <th class="text-right">Released</th>
                            <th class="text-right">Delivered</th>
                            <th class="text-right">Cancelled</th>
                            <th class="text-right">Remaining</th>
                            <th class="text-right">Unit price</th>
                            <th class="text-right">Amount</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <tr v-for="l in sale.items" :key="l.id">
                            <td class="min-w-48"><div class="font-medium text-gray-900">{{ l.product_name }}</div><div class="font-mono text-[11px] text-gray-500">{{ l.sku }}</div></td>
                            <td class="text-right">{{ l.quantity }}</td>
                            <td class="text-right">{{ l.reserved_quantity }}</td>
                            <td class="text-right">{{ l.released_quantity }}</td>
                            <td class="text-right">{{ l.delivered_quantity }}</td>
                            <td class="text-right">{{ l.cancelled_quantity || '—' }}</td>
                            <td class="text-right font-semibold" :class="l.remaining_quantity > 0 ? 'text-amber-700' : 'text-emerald-700'">{{ l.remaining_quantity }}</td>
                            <td class="whitespace-nowrap text-right">{{ formatMoney(l.unit_price) }}</td>
                            <td class="whitespace-nowrap text-right font-medium text-gray-900">{{ formatMoney(l.subtotal) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div class="flex flex-col items-end gap-1 border-t border-gray-200 px-4 py-3 text-sm">
                <div class="flex w-56 justify-between"><span class="text-gray-500">Subtotal</span><span>{{ formatMoney(sale.subtotal) }}</span></div>
                <div v-if="Number(sale.discount) > 0" class="flex w-56 justify-between"><span class="text-gray-500">Discount</span><span>-{{ formatMoney(sale.discount) }}</span></div>
                <div class="flex w-56 justify-between"><span class="text-gray-500">Tax</span><span>{{ formatMoney(sale.tax_amount) }}</span></div>
                <div class="flex w-56 justify-between font-semibold text-gray-900"><span>Total</span><span>{{ formatMoney(sale.grand_total) }}</span></div>
            </div>
        </section>

        <div class="grid grid-cols-1 gap-6 xl:grid-cols-2">
            <!-- Payments -->
            <section class="panel overflow-hidden xl:col-span-2">
                <div class="flex items-center justify-between border-b border-gray-200 px-4 py-3">
                    <h2 class="text-sm font-semibold text-gray-900">Payments</h2>
                    <span v-if="pendingPayments.length" class="text-xs font-medium text-amber-700">{{ pendingPayments.length }} waiting for verification</span>
                </div>
                <p v-if="!sale.payments.length" class="px-4 py-6 text-center text-sm text-gray-500">No payments recorded yet.</p>
                <div v-else class="overflow-x-auto">
                    <table class="w-full text-left">
                        <thead>
                            <tr><th>Submitted</th><th>Method / reference</th><th class="text-right">Amount</th><th>Status</th><th>Verified</th><th class="text-right">Actions</th></tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <tr v-for="p in sale.payments" :key="p.id">
                                <td class="whitespace-nowrap text-xs">{{ fmtDate(p.payment_date) }}<div class="text-gray-500">by {{ p.recorded_by?.name ?? '—' }}</div></td>
                                <td class="text-xs"><div class="font-medium text-gray-900">{{ p.method }}</div><div class="font-mono text-gray-500">{{ p.submitted_reference || 'no reference' }}</div>
                                    <a v-if="p.proof_url" :href="p.proof_url" target="_blank" class="inline-flex items-center gap-1 text-brand hover:underline"><ExternalLink class="size-3" /> Proof</a></td>
                                <td class="whitespace-nowrap text-right">{{ formatMoney(p.submitted_amount) }}</td>
                                <td><StatusBadge :status="p.status_label" /><div v-if="p.rejection_reason || p.refund_reason" class="mt-0.5 max-w-48 text-[11px] text-gray-500">{{ p.rejection_reason || p.refund_reason }}</div></td>
                                <td class="text-xs">
                                    <template v-if="p.verified_at">
                                        <div class="font-medium text-gray-900">{{ formatMoney(p.verified_amount ?? 0) }} · <span class="font-mono">{{ p.verified_reference }}</span></div>
                                        <div class="text-gray-500">{{ p.verified_by?.name }}, {{ fmtDateTime(p.verified_at) }}</div>
                                        <a v-if="p.receipt" :href="p.receipt.pdf_url" target="_blank" class="text-brand hover:underline">Receipt {{ p.receipt.receipt_number }}</a>
                                    </template>
                                    <span v-else class="text-gray-400">—</span>
                                </td>
                                <td class="whitespace-nowrap text-right">
                                    <template v-if="can.verify && ['submitted', 'under_verification'].includes(p.status)">
                                        <button type="button" class="brand-button min-h-8 px-3 text-xs" @click="openVerify(p)">Verify</button>
                                        <button type="button" class="ml-1 secondary-button min-h-8 px-3 text-xs" @click="ask({ title: 'Reject payment', help: 'The payment stays on record as rejected and the customer is told it could not be verified.', confirm: 'Reject payment', danger: true, url: `${base}/payments/${p.id}/reject` })">Reject</button>
                                    </template>
                                    <button v-if="can.admin && p.status === 'verified'" type="button" class="secondary-button min-h-8 px-3 text-xs"
                                            @click="ask({ title: 'Record refund', help: 'Record this only after the money was returned. The payment and its receipt stay on record; it no longer counts as paid.', confirm: 'Record refund', danger: true, url: `${base}/payments/${p.id}/refund` })">Refund</button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <!-- Invoices and receipts -->
            <section class="panel overflow-hidden">
                <div class="border-b border-gray-200 px-4 py-3"><h2 class="text-sm font-semibold text-gray-900">Sales invoices</h2></div>
                <p v-if="!sale.invoices.length" class="px-4 py-6 text-center text-sm text-gray-500">Issued when the order is confirmed.</p>
                <ul v-else class="divide-y divide-gray-100">
                    <li v-for="inv in sale.invoices" :key="inv.id" class="flex flex-wrap items-center justify-between gap-2 px-4 py-3 text-sm">
                        <div>
                            <a :href="inv.pdf_url" target="_blank" class="inline-flex items-center gap-1 font-mono font-medium text-gray-900 hover:underline"><FileText class="size-4" /> {{ inv.invoice_number }}</a>
                            <div class="text-xs text-gray-500">{{ fmtDate(inv.issued_at) }} · {{ formatMoney(inv.grand_total) }}<template v-if="inv.status === 'cancelled'"> · cancelled: {{ inv.cancellation_reason }}</template></div>
                            <div v-if="inv.status === 'issued'" class="mt-0.5 text-xs">
                                <span v-if="invoiceSending(inv)" class="text-amber-700">Sending…</span>
                                <span v-else-if="lastInvoiceSend(inv)" class="text-emerald-700">Emailed {{ fmtDateTime(lastInvoiceSend(inv)!.sent_at) }} to {{ lastInvoiceSend(inv)!.recipient }}</span>
                                <span v-else class="text-gray-500">Not emailed to the customer yet</span>
                                <span v-if="invoiceSendFailed(inv)" class="text-rose-600"> · last attempt failed</span>
                            </div>
                        </div>
                        <div class="flex flex-wrap items-center gap-2">
                            <StatusBadge v-if="inv.status === 'issued' && lastInvoiceSend(inv)" status="Sent" />
                            <StatusBadge :status="inv.status === 'issued' ? 'Issued' : 'Cancelled'" />
                            <template v-if="canSendInvoice && inv.status === 'issued'">
                                <button v-if="!lastInvoiceSend(inv)" type="button" class="brand-button" :disabled="busy || invoiceSending(inv)" @click="sendingInvoice = { invoice: inv, resend: false }">
                                    Send Commercial Invoice
                                </button>
                                <button v-else type="button" class="secondary-button" :disabled="busy || invoiceSending(inv)" @click="sendingInvoice = { invoice: inv, resend: true }">
                                    Resend
                                </button>
                            </template>
                            <button v-if="can.admin && inv.status === 'issued'" type="button" class="text-xs font-medium text-rose-600 hover:underline"
                                    @click="ask({ title: `Cancel invoice ${inv.invoice_number}`, help: 'Issued invoices are never edited. Cancel it with a reason; a corrected replacement can be issued, then sent with Send Commercial Invoice.', confirm: 'Cancel invoice', danger: true, url: `${base}/invoices/${inv.id}/cancel`, reissue: true })">Cancel</button>
                        </div>
                    </li>
                </ul>
                <p v-if="!company.bir_registered" class="border-t border-gray-100 px-4 py-2 text-[11px] text-gray-500">System-generated documents; not BIR-registered invoices.</p>
            </section>

            <section class="panel overflow-hidden">
                <div class="border-b border-gray-200 px-4 py-3"><h2 class="text-sm font-semibold text-gray-900">Acknowledgment receipts</h2></div>
                <p v-if="!sale.receipts.length" class="px-4 py-6 text-center text-sm text-gray-500">One receipt per verified payment.</p>
                <ul v-else class="divide-y divide-gray-100">
                    <li v-for="r in sale.receipts" :key="r.id" class="flex items-center justify-between gap-2 px-4 py-3 text-sm">
                        <a :href="r.pdf_url" target="_blank" class="inline-flex items-center gap-1 font-mono font-medium text-gray-900 hover:underline"><FileText class="size-4" /> {{ r.receipt_number }}</a>
                        <span class="text-xs text-gray-500">{{ formatMoney(r.amount_received) }} received · balance {{ formatMoney(r.balance_after) }}</span>
                    </li>
                </ul>
            </section>

            <!-- Delivery -->
            <section class="panel overflow-hidden xl:col-span-2">
                <div class="flex flex-wrap items-center justify-between gap-2 border-b border-gray-200 px-4 py-3">
                    <h2 class="text-sm font-semibold text-gray-900">{{ sale.fulfillment_type === 'pickup' ? 'Pickup' : 'Delivery' }}</h2>
                    <Link v-if="sale.fulfillment" :href="`/${teamSlug}/deliveries?search=${sale.order_number}`" class="inline-flex items-center gap-1 text-xs font-medium text-brand hover:underline"><Truck class="size-3.5" /> Manage on the Deliveries page</Link>
                </div>
                <div v-if="!sale.fulfillment" class="px-4 py-6 text-center text-sm text-gray-500">
                    The delivery record is created when the order is confirmed{{ sale.payment_terms === 'credit' ? ' (approved credit terms)' : ' (after the payment is verified)' }}.
                    <div v-if="sale.address && sale.fulfillment_type === 'delivery'" class="mt-1 text-xs">Deliver to: {{ sale.address }}</div>
                </div>
                <div v-else class="grid grid-cols-1 gap-0 lg:grid-cols-2">
                    <div class="border-b border-gray-200 p-4 lg:border-r lg:border-b-0">
                        <dl class="grid grid-cols-2 gap-3 text-xs">
                            <div><dt class="text-gray-500">Delivery no.</dt><dd class="font-mono font-medium text-gray-900">{{ sale.fulfillment.fulfillment_number }}</dd></div>
                            <div><dt class="text-gray-500">Status</dt><dd><StatusBadge :status="sale.fulfillment.status_label" /></dd></div>
                            <div><dt class="text-gray-500">Driver</dt><dd class="font-medium text-gray-900">{{ sale.fulfillment.handled_by?.name ?? 'Unassigned' }}</dd></div>
                            <div><dt class="text-gray-500">Attempts (current trip)</dt><dd class="font-medium text-gray-900">{{ sale.fulfillment.attempts_display }}</dd></div>
                        </dl>
                        <h3 class="mt-4 mb-2 text-xs font-semibold text-gray-700">Releases</h3>
                        <p v-if="!sale.releases.length" class="text-xs text-gray-500">Nothing released yet.</p>
                        <div v-for="r in sale.releases" :key="r.id" class="mb-2 rounded-xl border border-gray-200 p-3 text-xs">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <span class="font-mono font-medium text-gray-900">{{ r.release_number }}</span>
                                <span class="text-gray-500">{{ statusText(r.status) }} · {{ fmtDate(r.delivered_at || r.dispatched_at || r.created_at) }}</span>
                            </div>
                            <ul class="mt-1 space-y-0.5 text-gray-600">
                                <li v-for="i in r.items" :key="i.id">{{ i.sale_item?.product_name }}: {{ i.quantity }} sent<span v-if="r.status === 'delivered' || r.status === 'returned'">, {{ i.delivered_quantity }} received<span v-if="i.returned_quantity">, {{ i.returned_quantity }} back to stock</span></span></li>
                            </ul>
                            <div class="mt-1 flex flex-wrap gap-3">
                                <span v-if="r.received_by_name" class="text-gray-500">Received by {{ r.received_by_name }}</span>
                                <span v-if="r.return_reason" class="text-rose-600">Returned: {{ r.return_reason }}</span>
                                <a :href="`/${teamSlug}/deliveries/releases/${r.id}/pdf`" target="_blank" class="text-brand hover:underline">{{ r.type === 'pickup' ? 'Release slip' : 'Delivery receipt' }}</a>
                                <a v-if="r.proof_url" :href="r.proof_url" target="_blank" class="text-brand hover:underline">Proof photo</a>
                            </div>
                        </div>
                    </div>
                    <div class="p-4">
                        <h3 class="mb-2 text-xs font-semibold text-gray-700">Status history</h3>
                        <ol class="space-y-2">
                            <li v-for="h in sale.fulfillment.attempt_logs" :key="h.id" class="border-l-2 pl-3 text-xs" :class="h.attempt_number > 0 ? 'border-rose-300' : 'border-gray-200'">
                                <div class="font-medium text-gray-900">{{ h.attempt_number > 0 ? `Failed attempt ${h.attempt_number}` : statusText(h.new_status) }}</div>
                                <div v-if="h.failure_reason" class="text-rose-600">{{ h.failure_reason }}</div>
                                <div v-if="h.remarks" class="text-gray-600">{{ h.remarks }}</div>
                                <div class="text-gray-400">{{ fmtDateTime(h.attempt_date) }} · {{ h.staff?.name ?? 'System' }}</div>
                            </li>
                        </ol>
                    </div>
                </div>
            </section>

            <!-- Emails -->
            <section class="panel overflow-hidden">
                <div class="border-b border-gray-200 px-4 py-3"><h2 class="text-sm font-semibold text-gray-900">Customer emails</h2></div>
                <p v-if="!notifications.length" class="px-4 py-6 text-center text-sm text-gray-500">{{ sale.email ? 'No emails yet.' : 'No customer email address on this order.' }}</p>
                <ul v-else class="divide-y divide-gray-100">
                    <li v-for="n in notifications" :key="n.id" class="px-4 py-3 text-xs">
                        <div class="flex items-start justify-between gap-2">
                            <span class="font-medium text-gray-900">{{ n.subject || statusText(n.type) }}</span>
                            <StatusBadge :status="n.status === 'sent' ? 'Sent' : n.status === 'failed' ? 'Failed' : n.status === 'skipped' ? 'No email address' : 'Pending'" />
                        </div>
                        <div class="text-gray-500">{{ statusText(n.category) }} · {{ n.recipient || '—' }} · {{ fmtDateTime(n.sent_at || n.created_at) }}<template v-if="n.attempts > 1"> · {{ n.attempts }} attempts</template></div>
                        <div v-if="n.error && n.status !== 'sent'" class="mt-0.5 text-rose-600">{{ n.error }}</div>
                        <button v-if="n.status === 'failed'" type="button" class="mt-1 font-medium text-brand hover:underline" :disabled="busy" @click="retryEmail(n.id)">Send again</button>
                    </li>
                </ul>
                <p class="border-t border-gray-100 px-4 py-2 text-[11px] text-gray-500">"Sent" means the email service accepted the message; delivery to the inbox is not confirmed.</p>
            </section>

            <!-- Activity -->
            <section class="panel overflow-hidden">
                <div class="border-b border-gray-200 px-4 py-3"><h2 class="text-sm font-semibold text-gray-900">Activity</h2></div>
                <ol class="max-h-[28rem] divide-y divide-gray-100 overflow-y-auto">
                    <li v-for="a in activity" :key="a.id" class="px-4 py-2.5 text-xs">
                        <div :class="a.outcome === 'failure' ? 'text-rose-700' : 'text-gray-800'">{{ a.description }}</div>
                        <div class="text-gray-400">{{ fmtDateTime(a.created_at) }} · {{ a.user_email || 'System' }}</div>
                    </li>
                    <li v-if="!activity.length" class="px-4 py-6 text-center text-sm text-gray-500">No activity yet.</li>
                </ol>
            </section>
        </div>
    </div>

    <!-- Record payment -->
    <div v-if="showPayment" class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4">
        <form class="max-h-[90vh] w-full max-w-md overflow-y-auto rounded-xl border border-gray-200 bg-white p-6 shadow-xl" @submit.prevent="submitPayment">
            <h3 class="text-lg font-semibold text-gray-900">Record payment</h3>
            <p class="mt-1 text-xs text-gray-500">Record what the customer says they paid. It counts only after you verify it against the actual bank or GCash transaction.</p>
            <div class="mt-4 space-y-4">
                <div class="grid grid-cols-2 gap-3">
                    <div><label class="field-label" for="p-method">Method</label>
                        <select id="p-method" v-model="paymentForm.method" class="field-control"><option v-for="m in paymentMethods" :key="m" :value="m">{{ m }}</option></select></div>
                    <div><label class="field-label" for="p-amount">Amount (USD)</label>
                        <input id="p-amount" v-model.number="paymentForm.amount" type="number" min="0.01" step="0.01" required class="field-control" /></div>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div><label class="field-label" for="p-ref">Customer's reference</label>
                        <input id="p-ref" v-model="paymentForm.reference" maxlength="100" class="field-control" placeholder="e.g. BDO-12345" /></div>
                    <div><label class="field-label" for="p-date">Payment date</label>
                        <input id="p-date" v-model="paymentForm.payment_date" type="date" :max="today" required class="field-control" /></div>
                </div>
                <div><label class="field-label" for="p-proof">Proof of payment (optional)</label>
                    <input id="p-proof" type="file" accept="image/jpeg,image/png,image/webp,application/pdf" class="field-control py-1.5 text-xs" @change="paymentForm.proof = ($event.target as HTMLInputElement).files?.[0] ?? null" />
                    <p class="mt-1 text-[11px] text-gray-500">A screenshot is not proof that the money arrived; check the actual transaction when verifying.</p></div>
                <div><label class="field-label" for="p-remarks">Remarks (optional)</label>
                    <textarea id="p-remarks" v-model="paymentForm.remarks" rows="2" maxlength="1000" class="field-control"></textarea></div>
            </div>
            <div class="mt-6 flex flex-col-reverse gap-3 border-t border-gray-200 pt-4 sm:flex-row sm:justify-end">
                <button type="button" class="secondary-button" @click="showPayment = false">Cancel</button>
                <button type="submit" class="brand-button" :disabled="busy">{{ busy ? 'Saving…' : 'Record payment' }}</button>
            </div>
        </form>
    </div>

    <!-- Verify payment -->
    <div v-if="verifying" class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4">
        <form class="max-h-[90vh] w-full max-w-lg overflow-y-auto rounded-xl border border-gray-200 bg-white p-6 shadow-xl" @submit.prevent="submitVerify">
            <h3 class="text-lg font-semibold text-gray-900">Verify payment</h3>
            <p class="mt-1 text-xs text-gray-500">Find this payment in the {{ verifying.method === 'GCash' ? 'GCash' : 'bank' }} records first. Enter what you actually see there.</p>
            <dl class="mt-4 grid grid-cols-2 gap-3 rounded-xl border border-gray-200 bg-gray-50 p-3 text-xs">
                <div><dt class="text-gray-500">Customer submitted</dt><dd class="font-medium text-gray-900">{{ formatMoney(verifying.submitted_amount) }} · {{ verifying.method }}</dd></div>
                <div><dt class="text-gray-500">Their reference</dt><dd class="font-mono font-medium text-gray-900">{{ verifying.submitted_reference || '—' }}</dd></div>
                <div><dt class="text-gray-500">Order balance</dt><dd class="font-medium text-gray-900">{{ formatMoney(sale.outstanding_balance) }}</dd></div>
                <div><dt class="text-gray-500">Proof</dt><dd><a v-if="verifying.proof_url" :href="verifying.proof_url" target="_blank" class="text-brand hover:underline">Open proof</a><span v-else class="text-gray-400">none</span></dd></div>
            </dl>
            <img v-if="verifying.proof_url && !verifying.proof_url.endsWith('.pdf')" :src="verifying.proof_url" alt="Proof of payment" class="mt-3 max-h-64 w-full rounded-lg border border-gray-200 object-contain" />
            <div class="mt-4 space-y-4">
                <div class="grid grid-cols-2 gap-3">
                    <div><label class="field-label" for="v-ref">Verified transaction reference</label>
                        <input id="v-ref" v-model="verifyForm.reference" required maxlength="100" class="field-control font-mono" /></div>
                    <div><label class="field-label" for="v-amount">Amount actually received</label>
                        <input id="v-amount" v-model.number="verifyForm.amount" type="number" min="0.01" step="0.01" required class="field-control" /></div>
                </div>
                <div><label class="field-label" for="v-date">Actual payment date</label>
                    <input id="v-date" v-model="verifyForm.payment_date" type="date" :max="today" required class="field-control" /></div>
                <div><label class="field-label" for="v-remarks">Verification remarks</label>
                    <textarea id="v-remarks" v-model="verifyForm.remarks" rows="2" maxlength="1000" class="field-control" placeholder="e.g. Seen in BDO statement, Oct 30"></textarea></div>
            </div>
            <div class="mt-6 flex flex-col-reverse gap-3 border-t border-gray-200 pt-4 sm:flex-row sm:justify-end">
                <button type="button" class="secondary-button" @click="verifying = null">Close</button>
                <button type="submit" class="brand-button" :disabled="busy">{{ busy ? 'Verifying…' : 'Confirm payment' }}</button>
            </div>
        </form>
    </div>

    <!-- Payment terms (administrator) -->
    <div v-if="showTerms" class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4">
        <form class="max-h-[90vh] w-full max-w-md overflow-y-auto rounded-xl border border-gray-200 bg-white p-6 shadow-xl" @submit.prevent="submitTerms">
            <h3 class="text-lg font-semibold text-gray-900">Payment terms</h3>
            <p class="mt-1 text-xs text-gray-500">Approved terms decide when the order is confirmed. Credit terms confirm it now.</p>
            <div class="mt-4 space-y-3">
                <label v-for="t in [{ v: 'full', l: 'Full payment before confirmation' }, { v: 'down_payment', l: 'Down payment, balance later' }, { v: 'credit', l: 'Credit terms' }]" :key="t.v"
                       :class="termsForm.payment_terms === t.v ? 'border-[#4b5320] bg-[#4b5320]/5' : 'border-gray-200'" class="flex cursor-pointer items-center gap-2 rounded-xl border p-3 text-sm">
                    <input v-model="termsForm.payment_terms" type="radio" :value="t.v" class="accent-[#4b5320]" /> {{ t.l }}
                </label>
                <div v-if="termsForm.payment_terms === 'down_payment'"><label class="field-label" for="t-down">Required down payment (USD)</label>
                    <input id="t-down" v-model.number="termsForm.required_down_payment" type="number" min="0.01" :max="sale.grand_total" step="0.01" required class="field-control" /></div>
                <div v-if="termsForm.payment_terms === 'credit'"><label class="field-label" for="t-days">Credit days</label>
                    <input id="t-days" v-model.number="termsForm.credit_days" type="number" min="1" max="365" required class="field-control" /></div>
                <p v-if="termsForm.payment_terms === 'credit'" class="flex items-start gap-2 rounded-xl border border-amber-200 bg-amber-50 p-3 text-xs text-amber-800">
                    <AlertTriangle class="size-4 shrink-0" /> The order will be confirmed, stock reserved and the invoice issued now, before any payment.
                </p>
            </div>
            <div class="mt-6 flex flex-col-reverse gap-3 border-t border-gray-200 pt-4 sm:flex-row sm:justify-end">
                <button type="button" class="secondary-button" @click="showTerms = false">Cancel</button>
                <button type="submit" class="brand-button" :disabled="busy">Approve terms</button>
            </div>
        </form>
    </div>

    <!-- Reason prompt -->
    <div v-if="reasonPrompt" class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4">
        <form class="max-h-[90vh] w-full max-w-md overflow-y-auto rounded-xl border border-gray-200 bg-white p-6 shadow-xl" @submit.prevent="submitReason">
            <h3 class="text-lg font-semibold text-gray-900">{{ reasonPrompt.title }}</h3>
            <p class="mt-1 text-xs text-gray-500">{{ reasonPrompt.help }}</p>
            <label class="field-label mt-4" for="reason">Reason</label>
            <textarea id="reason" v-model="reason" rows="3" maxlength="500" required class="field-control"></textarea>
            <label v-if="reasonPrompt.reissue" class="mt-3 flex items-center gap-2 text-sm text-gray-700">
                <input v-model="reissue" type="checkbox" class="accent-[#4b5320]" /> Issue a corrected replacement (send it afterwards with Send Commercial Invoice)
            </label>
            <div class="mt-6 flex flex-col-reverse gap-3 border-t border-gray-200 pt-4 sm:flex-row sm:justify-end">
                <button type="button" class="secondary-button" @click="reasonPrompt = null">Back</button>
                <button type="submit" :disabled="busy || !reason.trim()"
                        :class="reasonPrompt.danger ? 'inline-flex min-h-10 items-center justify-center rounded-xl bg-rose-600 px-4 py-2 text-sm font-semibold text-white hover:bg-rose-700 disabled:opacity-50' : 'brand-button'">
                    {{ reasonPrompt.confirm }}
                </button>
            </div>
        </form>
    </div>

    <!-- Send / resend the commercial invoice -->
    <div v-if="sendingInvoice" class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4" @keydown.esc="sendingInvoice = null">
        <form class="w-full max-w-md rounded-xl border border-gray-200 bg-white p-6 shadow-xl" role="dialog" aria-modal="true" aria-labelledby="send-invoice-title" @submit.prevent="confirmSendInvoice">
            <h3 id="send-invoice-title" class="text-lg font-semibold text-gray-900">
                {{ sendingInvoice.resend ? 'Resend' : 'Send' }} commercial invoice {{ sendingInvoice.invoice.invoice_number }}
            </h3>
            <p class="mt-1 text-sm text-gray-600">
                The invoice PDF is emailed to <strong class="text-gray-900">{{ sale.email }}</strong>.
                <template v-if="sendingInvoice.resend"> It was already emailed {{ fmtDateTime(lastInvoiceSend(sendingInvoice.invoice)?.sent_at) }}; the customer receives it again.</template>
            </p>
            <div class="mt-6 flex flex-col-reverse gap-3 border-t border-gray-200 pt-4 sm:flex-row sm:justify-end">
                <button type="button" class="secondary-button" @click="sendingInvoice = null">Back</button>
                <button type="submit" class="brand-button" :disabled="busy">{{ busy ? 'Sending…' : sendingInvoice.resend ? 'Send again' : 'Send invoice' }}</button>
            </div>
        </form>
    </div>
</template>
