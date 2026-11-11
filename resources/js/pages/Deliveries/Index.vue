<script setup lang="ts">
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { useCanDelete } from '@/composables/useCanDelete';
import { showToast } from '@/composables/useFlashToast';
import { ref, computed, watch } from 'vue';
import SpecularButton from '@/components/ui/SpecularButton.vue';
import PurchaseOrderWorkspace from '@/components/procurement/PurchaseOrderWorkspace.vue';
import InboundDeliveryRecorder from '@/components/deliveries/InboundDeliveryRecorder.vue';
import InboundDeliveryDetails from '@/components/deliveries/InboundDeliveryDetails.vue';
import type { DeliveryFull, ReceivableOrder } from '@/types/inboundDelivery';
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
    Download,
    AlertCircle,
    Eye,
    Phone,
    MapPin,
    User as UserIcon,
    AlertTriangle,
    RotateCcw,
    Camera,
    Image as ImageIcon,
    History,
    UserCheck,
    Navigation,
    ShieldAlert,
    ExternalLink,
    Pencil
} from '@lucide/vue';

interface AttemptLogItem {
    id: number;
    attempt_number: number;
    previous_status: string;
    new_status: string;
    staff_id: number;
    staff?: { id: number; name: string };
    failure_reason?: string | null;
    remarks?: string | null;
    proof_image?: string | null;
    proof_url?: string | null;
    attempt_date: string;
}

interface OutboundDeliveryItem {
    id: number;
    fulfillment_number: string;
    fulfillment_type: string;
    recipient_name: string;
    contact_number: string;
    delivery_address?: string | null;
    delivery_schedule?: string | null;
    instructions?: string | null;
    status: string;
    status_label?: string;
    allowed_actions?: string[];
    status_choices?: StatusChoice[];
    /** Three failed attempts and not yet rescheduled by an admin. */
    needs_reschedule?: boolean;
    /** Why an admin cannot delete this delivery yet (null = deletable). */
    delete_blocked_reason?: string | null;
    open_release?: ReleaseItem | null;
    releases?: ReleaseItem[];
    attempt_count: number;
    remaining_attempts: number;
    attempts_display: string;
    proof_of_delivery?: string | null;
    proof_url?: string | null;
    handled_by_id?: number | null;
    handled_by?: { id: number; name: string; email: string; role: string } | null;
    dispatched_at?: string | null;
    delivered_at?: string | null;
    rescheduled_at?: string | null;
    rescheduled_by?: { id: number; name: string } | null;
    notes?: string | null;
    sale?: {
        id: number;
        order_number: string;
        invoice_number?: string | null;
        client_name: string;
        grand_total: number;
        sale_date: string;
        status?: string;
        channel?: string;
        hold_reason?: string | null;
        items?: SaleLine[];
    } | null;
    commercial_invoice?: {
        id: number;
        invoice_number: string;
        total_cost: number;
    } | null;
    attempt_logs?: AttemptLogItem[];
}

interface StatusChoice {
    action: string;
    status: string | null;
    label: string;
    needs: 'quantities' | 'receipt' | 'attempt' | 'schedule' | 'reason' | null;
}

interface SaleLine {
    id: number;
    sku: string;
    product_name: string;
    quantity: number;
    unit_price: number;
    reserved_quantity: number;
    released_quantity: number;
    delivered_quantity: number;
    cancelled_quantity: number;
    remaining_quantity: number;
    releasable_quantity: number;
}

interface ReleaseItem {
    id: number;
    release_number: string;
    type: 'delivery' | 'pickup';
    status: string;
    dispatched_at?: string | null;
    delivered_at?: string | null;
    received_by_name?: string | null;
    return_reason?: string | null;
    proof_url?: string | null;
    created_at: string;
    items: { id: number; sale_item_id: number; quantity: number; delivered_quantity: number; returned_quantity: number; sale_item?: { sku: string; product_name: string } | null }[];
}

/** One inbound P.O. delivery record (Delivery Date, Delivery #); its products are opened with View. */
interface InboundDeliveryItem {
    id: number;
    delivery_date: string;
    delivery_number: string;
    purchase_order_number?: string | null;
    status: 'confirmed' | 'cancelled';
    supplier?: { id: number; name: string } | null;
    confirmed_by?: { id: number; name: string } | null;
    items_count: number;
    received_total?: number | string | null;
    accepted_total?: number | string | null;
    rejected_total?: number | string | null;
    quality_status: string;
}

interface ProductItem {
    id: number;
    name: string;
    sku: string;
    cost_price: number;
    stock: number;
    unit?: string | null;
    is_active?: boolean;
}

interface SupplierItem {
    id: number;
    name: string;
}

interface PurchaseOrderItem {
    id: number;
    po_number: string;
    item_sku?: string | null;
    description: string;
    unit_of_measure?: string | null;
    quantity: number;
    supplier_id?: number | null;
    status?: string;
    remaining?: number;
}

const props = defineProps<{
    activeTab: 'outbound' | 'inbound';
    isDeliveryStaff: boolean;
    isAdmin: boolean;
    outboundDeliveries?: {
        data: OutboundDeliveryItem[];
        current_page: number;
        last_page: number;
        total: number;
        links: any[];
    };
    outboundMetrics?: {
        totalAssigned: number;
        pending: number;
        inTransit: number;
        delivered: number;
        failed: number;
        attemptsRemaining: number;
    };
    deliveryStaffList?: Array<{ id: number; name: string; email: string; role: string }>;
    statusOptions?: Array<{ value: string; label: string; badgeClass: string }>;
    canPrepare?: boolean;
    inboundDeliveries?: {
        data: InboundDeliveryItem[];
        current_page: number;
        last_page: number;
        total: number;
        links: any[];
    } | null;
    inboundMetrics?: {
        totalDeliveries: number;
        totalQuantityDelivered: number;
        linkedPosCount: number;
        deliveredCount: number;
    } | null;
    products?: ProductItem[];
    suppliers?: SupplierItem[];
    purchaseOrders?: PurchaseOrderItem[];
    receivableOrders?: ReceivableOrder[];
    productCategories?: string[];
    filters?: {
        tab?: string;
        search?: string;
        status?: string;
        staff_id?: string | number;
        date_from?: string;
        date_to?: string;
    };
}>();

const page = usePage();
const canDelete = useCanDelete();
const teamSlug = computed(() => {
    const pageTeam = (page.props as any).currentTeam?.slug || (page.props as any).team?.slug;
    if (pageTeam) return pageTeam;
    if (typeof window !== 'undefined') {
        const pathSegments = window.location.pathname.split('/').filter(Boolean);
        if (pathSegments.length > 0 && pathSegments[0] !== 'deliveries') {
            return pathSegments[0];
        }
    }
    return 'mr-pride';
});

// Flash toasts are handled globally by useFlashToast in AppSidebarLayout

const formatDate = (dateStr?: string | null) => {
    if (!dateStr) return '—';
    const d = new Date(dateStr);
    if (isNaN(d.getTime())) return dateStr;
    return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
};

const formatDateTime = (dateStr?: string | null) => {
    if (!dateStr) return '—';
    const d = new Date(dateStr);
    if (isNaN(d.getTime())) return dateStr;
    return d.toLocaleString('en-US', { month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit', hour12: true });
};

// Current Tab State
const currentTab = ref<'outbound' | 'inbound'>(props.activeTab || 'outbound');
const switchTab = (tab: 'outbound' | 'inbound') => {
    currentTab.value = tab;
    router.get(
        `/${teamSlug.value}/deliveries`,
        { tab, search: search.value || undefined },
        { preserveState: true, preserveScroll: true, replace: true }
    );
};

// Search & Filter State
const search = ref(props.filters?.search || '');
const selectedStatus = ref(props.filters?.status || 'All');
const selectedStaff = ref(props.filters?.staff_id !== undefined ? String(props.filters.staff_id) : 'All');
const dateFrom = ref(props.filters?.date_from || '');
const dateTo = ref(props.filters?.date_to || '');

let filterTimeout: ReturnType<typeof setTimeout> | null = null;
const applyFilter = () => {
    if (filterTimeout) clearTimeout(filterTimeout);
    filterTimeout = setTimeout(() => {
        router.get(
            `/${teamSlug.value}/deliveries`,
            {
                tab: currentTab.value,
                search: search.value || undefined,
                status: selectedStatus.value !== 'All' ? selectedStatus.value : undefined,
                staff_id: selectedStaff.value !== 'All' ? selectedStaff.value : undefined,
                date_from: dateFrom.value || undefined,
                date_to: dateTo.value || undefined,
            },
            { preserveState: true, preserveScroll: true, replace: true }
        );
    }, 300);
};

const resetFilters = () => {
    search.value = '';
    selectedStatus.value = 'All';
    selectedStaff.value = 'All';
    dateFrom.value = '';
    dateTo.value = '';
    applyFilter();
};

const hasActiveFilters = computed(() => {
    return !!search.value || selectedStatus.value !== 'All' || selectedStaff.value !== 'All' || !!dateFrom.value || !!dateTo.value;
});

// Helper: Status badge styling (codes and labels come from the server)
const getStatusBadgeClass = (status: string) =>
    props.statusOptions?.find((o) => o.value === status)?.badgeClass ?? 'bg-gray-50 text-muted-foreground border-gray-200';
const statusLabel = (status: string) => props.statusOptions?.find((o) => o.value === status)?.label ?? status;
const can = (item: OutboundDeliveryItem, action: string) => !!item.allowed_actions?.includes(action);
const releaseStatusText: Record<string, string> = { preparing: 'Being prepared', ready: 'Ready', dispatched: 'On the way', delivered: 'Received', returned: 'Returned', cancelled: 'Cancelled' };
const outstanding = (item: OutboundDeliveryItem) => (item.sale?.items ?? []).filter((l) => l.remaining_quantity > 0);
const getAttemptBadgeClass = (count: number) => {
    if (count === 0) return 'bg-emerald-50 text-emerald-600 border-emerald-200';
    if (count === 1) return 'bg-blue-50 text-blue-600 border-blue-200';
    if (count === 2) return 'bg-amber-50 text-amber-600 border-amber-200 font-semibold';
    return 'bg-rose-50 text-rose-600 border-rose-200 font-semibold';
};

// ==========================================
// OUTBOUND DELIVERY ACTION MODALS
// ==========================================
const selectedFulfillment = ref<OutboundDeliveryItem | null>(null);

// 1. Details Modal
const showDetailsModal = ref(false);
const openDetails = (item: OutboundDeliveryItem) => {
    selectedFulfillment.value = item;
    showDetailsModal.value = true;
};

// "Update status": one form for every status change this user may make now. The choices and
// the rules come from the server; the form only asks for what the chosen change needs.
const failureReasons = ['Customer unavailable', 'Incorrect or incomplete address', 'Customer refused delivery', 'Customer unreachable', 'Other reason'];
const showStatusUpdate = ref(false);
const statusChoice = ref<StatusChoice | null>(null);
const statusSaving = ref(false);
const statusForm = ref({
    remarks: '',
    reason: '',
    failure_reason: failureReasons[0],
    recipient_name: '',
    quantities: {} as Record<number, number>,
    delivery_schedule: '',
    reset_attempts: true,
    photo: null as File | null,
});
const openStatusUpdate = (item: OutboundDeliveryItem) => {
    selectedFulfillment.value = item;
    statusChoice.value = item.status_choices?.[0] ?? null;
    resetStatusForm();
    showStatusUpdate.value = true;
};
const resetStatusForm = () => {
    const item = selectedFulfillment.value;
    const choice = statusChoice.value;
    const quantities = choice?.needs === 'quantities'
        ? Object.fromEntries((item?.sale?.items ?? []).map((l) => [l.id, l.releasable_quantity]))
        : Object.fromEntries((item?.open_release?.items ?? []).map((i) => [i.sale_item_id, i.quantity]));
    statusForm.value = {
        remarks: '',
        reason: '',
        failure_reason: failureReasons[0],
        recipient_name: choice?.action === 'deliver' ? item?.recipient_name ?? '' : '',
        quantities,
        delivery_schedule: new Date(Date.now() + 86400000).toISOString().slice(0, 16),
        reset_attempts: true,
        photo: null,
    };
};
watch(statusChoice, () => resetStatusForm());
const statusNeedsPhoto = computed(() => statusChoice.value?.action === 'deliver');
const canSubmitStatus = computed(() => {
    const c = statusChoice.value;
    const f = statusForm.value;
    if (!c || statusSaving.value) return false;
    if (c.needs === 'quantities') return Object.values(f.quantities).some((q) => Number(q) > 0);
    if (c.needs === 'reason') return f.reason.trim().length > 0;
    if (c.needs === 'attempt') return f.remarks.trim().length >= 3;
    if (c.needs === 'schedule') return !!f.delivery_schedule;
    if (c.needs === 'receipt') return !!f.recipient_name.trim() && (!statusNeedsPhoto.value || !!f.photo);
    return true;
});
const submitStatusUpdate = () => {
    const item = selectedFulfillment.value;
    const c = statusChoice.value;
    if (!item || !c || !canSubmitStatus.value) return;
    const f = statusForm.value;
    const data: Record<string, any> = { action: c.action, remarks: f.remarks || undefined };
    if (c.needs === 'quantities') data.quantities = f.quantities;
    if (c.needs === 'reason') data.reason = f.reason.trim();
    if (c.needs === 'attempt') Object.assign(data, { reason: f.failure_reason, remarks: f.remarks, attempt_photo: f.photo ?? undefined });
    if (c.needs === 'schedule') Object.assign(data, { delivery_schedule: f.delivery_schedule, reset_attempts: f.reset_attempts ? 1 : 0, notes: f.remarks || undefined });
    if (c.needs === 'receipt') Object.assign(data, { quantities: f.quantities, recipient_name: f.recipient_name.trim(), proof_of_delivery: f.photo ?? undefined });
    statusSaving.value = true;
    router.post(`/${teamSlug.value}/deliveries/fulfillments/${item.id}/status`, data, {
        preserveScroll: true,
        forceFormData: true,
        onSuccess: () => {
            showStatusUpdate.value = false;
        },
        onError: (err: any) => showToast(Object.values(err)[0] as string || 'The status could not be updated.', 'error'),
        onFinish: () => (statusSaving.value = false),
    });
};

// Prepare a release: choose what goes out next (defaults to everything still to send).
const showPrepareModal = ref(false);
const prepareQuantities = ref<Record<number, number>>({});
const prepareRemarks = ref('');
const preparing = ref(false);
const openPrepare = (item: OutboundDeliveryItem) => {
    selectedFulfillment.value = item;
    prepareQuantities.value = Object.fromEntries((item.sale?.items ?? []).map((l) => [l.id, l.releasable_quantity]));
    prepareRemarks.value = '';
    showPrepareModal.value = true;
};
const submitPrepare = () => {
    if (!selectedFulfillment.value) return;
    preparing.value = true;
    router.post(`/${teamSlug.value}/deliveries/fulfillments/${selectedFulfillment.value.id}/releases`, {
        quantities: prepareQuantities.value,
        remarks: prepareRemarks.value || undefined,
    }, {
        preserveScroll: true,
        onSuccess: () => {
            showPrepareModal.value = false;
        },
        onError: (err: any) => showToast(err.items || err.error || 'The release could not be prepared.', 'error'),
        onFinish: () => (preparing.value = false),
    });
};

const postAction = (url: string, data: Record<string, any> = {}, done?: () => void) => {
    router.post(url, data, {
        preserveScroll: true,
        onSuccess: () => done?.(),
        onError: (err: any) => showToast(err.error || err.reason || Object.values(err)[0] as string || 'The action could not be completed.', 'error'),
    });
};
const markReady = (item: OutboundDeliveryItem) => item.open_release && postAction(`/${teamSlug.value}/deliveries/releases/${item.open_release.id}/ready`);

// Reason prompts: cancel a release that has not left, or record goods returned to the warehouse.
const reasonPrompt = ref<{ title: string; help: string; url: string; confirm: string } | null>(null);
const reasonText = ref('');
const askCancelRelease = (item: OutboundDeliveryItem) => {
    if (!item.open_release) return;
    reasonText.value = '';
    reasonPrompt.value = { title: `Cancel ${item.open_release.release_number}`, help: 'The items go back to the order and can be released again later.', url: `/${teamSlug.value}/deliveries/releases/${item.open_release.id}/cancel`, confirm: 'Cancel release' };
};
const askReturn = (item: OutboundDeliveryItem) => {
    reasonText.value = '';
    reasonPrompt.value = { title: 'Goods returned to the warehouse', help: 'Record this only when the goods are physically back. They go back into stock and stay held for this order.', url: `/${teamSlug.value}/deliveries/fulfillments/${item.id}/return`, confirm: 'Record return' };
};
const submitReason = () => {
    if (!reasonPrompt.value || !reasonText.value.trim()) return;
    postAction(reasonPrompt.value.url, { reason: reasonText.value.trim() }, () => (reasonPrompt.value = null));
};

// 2. Start Delivery Modal
const showStartModal = ref(false);
const openStartDelivery = (item: OutboundDeliveryItem) => {
    selectedFulfillment.value = item;
    showStartModal.value = true;
};
const startDeliveryForm = useForm({});

// Inbound P.O. deliveries: View (details), Correct (admin and staff), Delete (admin: cancels and reverses stock).
const workspacePoId = ref<number | null>(null);
const viewingDeliveryId = ref<number | null>(null);
const detailsReloadKey = ref(0);
const correctingDelivery = ref<DeliveryFull | null>(null);
const showRecorder = ref(false);
const openRecorder = () => {
    correctingDelivery.value = null;
    showRecorder.value = true;
};
const openCorrection = (d: DeliveryFull) => {
    correctingDelivery.value = d;
    showRecorder.value = true;
};
const loadingCorrectionId = ref<number | null>(null);
const openCorrectionById = async (id: number) => {
    loadingCorrectionId.value = id;
    try {
        const res = await fetch(`/${teamSlug.value}/deliveries/inbound/${id}`, { headers: { Accept: 'application/json' } });
        if (!res.ok) throw new Error(`HTTP ${res.status}`);
        openCorrection(await res.json());
    } catch {
        showToast('The delivery could not be loaded. Please try again.', 'error');
    } finally {
        loadingCorrectionId.value = null;
    }
};
const closeRecorder = () => {
    showRecorder.value = false;
    correctingDelivery.value = null;
};
const onRecorderSaved = () => {
    closeRecorder();
    detailsReloadKey.value++;
};
// Accepted units go into stock automatically at inspection; this fallback posts deliveries inspected before that.
const postToInventory = (itemId: number, number: string) => {
    router.post(`/${teamSlug.value}/deliveries/${itemId}/post-inventory`, {}, {
        preserveScroll: true,
        onError: (errors) => showToast(Object.values(errors).join(' '), 'error'),
        onSuccess: () => {
            showToast(`Accepted units of ${number} added to inventory.`, 'success');
            detailsReloadKey.value++;
        },
    });
};
const inboundQualityClass: Record<string, string> = {
    Passed: 'border-emerald-200 bg-emerald-50 text-emerald-700',
    'Partially Passed': 'border-amber-200 bg-amber-50 text-amber-800',
    Failed: 'border-rose-200 bg-rose-50 text-rose-700',
    'Pending Inspection': 'border-amber-200 bg-amber-50 text-amber-800',
    Cancelled: 'border-gray-200 bg-gray-50 text-gray-500',
};
const goToInboundPage = (pageNumber: number) => {
    router.get(window.location.pathname, { ...(props.filters ?? {}), tab: 'inbound', inbound_page: pageNumber }, { preserveScroll: true, preserveState: true, only: ['inboundDeliveries'] });
};
const confirmStartDelivery = () => {
    if (!selectedFulfillment.value) return;
    startDeliveryForm.post(`/${teamSlug.value}/deliveries/fulfillments/${selectedFulfillment.value.id}/start`, {
        preserveScroll: true,
        onSuccess: () => {
            showStartModal.value = false;
            showToast(`Delivery ${selectedFulfillment.value?.fulfillment_number} is out for delivery.`, 'success');
        },
        onError: (err: any) => {
            showToast(err.error || 'Failed to start delivery.', 'error');
        }
    });
};

// 3. Record Failed Attempt Modal
const showAttemptModal = ref(false);
const openRecordAttempt = (item: OutboundDeliveryItem) => {
    selectedFulfillment.value = item;
    attemptForm.reset();
    attemptPhotoPreview.value = null;
    showAttemptModal.value = true;
};
const attemptForm = useForm({
    reason: 'Customer unavailable',
    remarks: '',
    attempt_photo: null as File | null,
});
const attemptPhotoPreview = ref<string | null>(null);
const handleAttemptPhotoChange = (e: Event) => {
    const input = e.target as HTMLInputElement;
    if (input.files && input.files[0]) {
        const file = input.files[0];
        attemptForm.attempt_photo = file;
        attemptPhotoPreview.value = URL.createObjectURL(file);
    }
};
const submitFailedAttempt = () => {
    if (!selectedFulfillment.value) return;
    attemptForm.post(`/${teamSlug.value}/deliveries/fulfillments/${selectedFulfillment.value.id}/attempt`, {
        preserveScroll: true,
        onSuccess: () => {
            showAttemptModal.value = false;
            showToast('Delivery attempt recorded successfully.', 'warning');
        },
        onError: (err: any) => {
            showToast(err.reason || err.remarks || 'Failed to record attempt.', 'error');
        }
    });
};

// 4. Mark Delivered Modal (Proof Photo Upload)
const showDeliveredModal = ref(false);
const openMarkDelivered = (item: OutboundDeliveryItem) => {
    selectedFulfillment.value = item;
    deliveredForm.reset();
    deliveredForm.recipient_name = item.fulfillment_type === 'pickup' ? '' : item.recipient_name;
    deliveredForm.quantities = Object.fromEntries((item.open_release?.items ?? []).map((i) => [i.sale_item_id, i.quantity]));
    deliveryPhotoPreview.value = null;
    showDeliveredModal.value = true;
};
const deliveredForm = useForm({
    recipient_name: '',
    remarks: '',
    proof_of_delivery: null as File | null,
    quantities: {} as Record<number, number>,
});
const isPickupDelivery = computed(() => selectedFulfillment.value?.fulfillment_type === 'pickup');
const deliveryPhotoPreview = ref<string | null>(null);
const handleProofPhotoChange = (e: Event) => {
    const input = e.target as HTMLInputElement;
    if (input.files && input.files[0]) {
        const file = input.files[0];
        deliveredForm.proof_of_delivery = file;
        deliveryPhotoPreview.value = URL.createObjectURL(file);
    }
};
const submitDelivered = () => {
    if (!selectedFulfillment.value) return;
    deliveredForm.post(`/${teamSlug.value}/deliveries/fulfillments/${selectedFulfillment.value.id}/deliver`, {
        preserveScroll: true,
        onSuccess: () => {
            showDeliveredModal.value = false;
        },
        onError: (err: any) => {
            showToast(err.proof_of_delivery || err.recipient_name || err.items || err.error || 'Failed to complete delivery.', 'error');
        }
    });
};

// 5. Assign Staff Modal (Admin only)
const showAssignModal = ref(false);
const openAssignStaff = (item: OutboundDeliveryItem) => {
    selectedFulfillment.value = item;
    assignForm.staff_id = item.handled_by_id ? String(item.handled_by_id) : '';
    showAssignModal.value = true;
};
const assignForm = useForm({
    staff_id: '',
});
const submitAssignStaff = () => {
    if (!selectedFulfillment.value) return;
    assignForm.post(`/${teamSlug.value}/deliveries/fulfillments/${selectedFulfillment.value.id}/assign`, {
        preserveScroll: true,
        onSuccess: () => {
            showAssignModal.value = false;
            showToast('Delivery assigned successfully.', 'success');
        }
    });
};

// 6. Reschedule Modal (Admin only)
const showRescheduleModal = ref(false);
const openReschedule = (item: OutboundDeliveryItem) => {
    selectedFulfillment.value = item;
    rescheduleForm.reset();
    rescheduleForm.delivery_schedule = new Date(Date.now() + 86400000).toISOString().slice(0, 16);
    rescheduleForm.reset_attempts = true;
    showRescheduleModal.value = true;
};
const rescheduleForm = useForm({
    delivery_schedule: '',
    staff_id: '',
    reset_attempts: true,
    notes: '',
});
const submitReschedule = () => {
    if (!selectedFulfillment.value) return;
    rescheduleForm.post(`/${teamSlug.value}/deliveries/fulfillments/${selectedFulfillment.value.id}/reschedule`, {
        preserveScroll: true,
        onSuccess: () => {
            showRescheduleModal.value = false;
            showToast('Delivery successfully rescheduled.', 'success');
        }
    });
};

// 7. Proof Viewer Modal
const showProofViewerModal = ref(false);
const viewingProofUrl = ref<string | null>(null);
const viewingProofTitle = ref('');
const openProofViewer = (url: string, title: string) => {
    viewingProofUrl.value = url;
    viewingProofTitle.value = title;
    showProofViewerModal.value = true;
};

// Delete an inbound delivery (admin only): kept as Cancelled with the reason; its stock is taken back out.
const deliveryToDelete = ref<{ id: number; delivery_number: string } | null>(null);
const deleteInboundConfirmInput = ref('');
const deleteInboundReason = ref('');
const deletingInbound = ref(false);
const confirmDeleteInbound = (d: { id: number; delivery_number: string }) => {
    deliveryToDelete.value = d;
    deleteInboundConfirmInput.value = '';
    deleteInboundReason.value = '';
};
const executeDeleteInbound = () => {
    if (!deliveryToDelete.value || deleteInboundConfirmInput.value !== 'YES' || !deleteInboundReason.value.trim()) return;
    deletingInbound.value = true;
    router.delete(`/${teamSlug.value}/deliveries/inbound/${deliveryToDelete.value.id}`, {
        data: { confirmation: 'YES', reason: deleteInboundReason.value.trim() },
        preserveScroll: true,
        onSuccess: () => {
            deliveryToDelete.value = null;
            detailsReloadKey.value++;
        },
        onError: (errors) => showToast(Object.values(errors).join(' '), 'error'),
        onFinish: () => {
            deletingInbound.value = false;
        },
    });
};

// Customer (outbound) delivery deletion: admin only, moves the record to the Recycle Bin.
const outboundToDelete = ref<{ id: number; fulfillment_number: string; recipient_name?: string } | null>(null);
const deleteOutboundConfirmInput = ref('');
const deletingOutbound = ref(false);
const confirmDeleteOutbound = (item: { id: number; fulfillment_number: string; recipient_name?: string }) => {
    outboundToDelete.value = item;
    deleteOutboundConfirmInput.value = '';
};
const executeDeleteOutbound = () => {
    if (!outboundToDelete.value || deleteOutboundConfirmInput.value !== 'YES') return;
    deletingOutbound.value = true;
    router.delete(`/${teamSlug.value}/deliveries/fulfillments/${outboundToDelete.value.id}`, {
        data: { confirmation: deleteOutboundConfirmInput.value },
        preserveScroll: true,
        onSuccess: () => {
            outboundToDelete.value = null;
            deleteOutboundConfirmInput.value = '';
        },
        onFinish: () => {
            deletingOutbound.value = false;
        },
    });
};
</script>

<template>
    <Head title="Deliveries — MR-PRIDE" />

    <div class="workspace-page">
        <!-- Top Header & Role Indicator -->
        <div class="page-header">
            <div class="min-w-0">
                <h1 class="page-title">
                    {{ isDeliveryStaff ? 'Delivery Staff Dashboard' : 'Deliveries & Logistics' }}
                </h1>
                <p class="page-subtitle">
                    {{ isDeliveryStaff
                        ? 'Your assigned deliveries, attempts and handovers.'
                        : 'Customer deliveries, driver assignments and supplier PO receiving.' }}
                </p>
            </div>

            <!-- Tab Switcher (Visible to Admin & Non-Driver Staff) -->
            <div v-if="!isDeliveryStaff" class="flex w-full items-center gap-1 overflow-x-auto rounded-xl border border-gray-200 bg-white p-1 sm:w-auto sm:self-start lg:self-auto">
                <button
                    @click="switchTab('outbound')"
                    class="flex flex-1 shrink-0 items-center justify-center gap-2 whitespace-nowrap rounded-lg px-3.5 py-2 text-sm font-medium transition-colors sm:flex-none"
                    :class="currentTab === 'outbound' ? 'bg-gray-100 text-gray-900' : 'text-gray-500 hover:text-gray-900'"
                >
                    <Truck class="size-4" />
                    Customer Deliveries
                    <span class="text-xs text-gray-400">{{ outboundMetrics?.totalAssigned ?? 0 }}</span>
                </button>
                <button 
                    @click="switchTab('inbound')"
                    class="flex flex-1 shrink-0 items-center justify-center gap-2 whitespace-nowrap rounded-lg px-3.5 py-2 text-sm font-medium transition-colors sm:flex-none"
                    :class="currentTab === 'inbound' ? 'bg-gray-100 text-gray-900' : 'text-gray-500 hover:text-gray-900'"
                >
                    <Package class="size-4" />
                    Inbound PO Receiving
                    <span class="text-xs text-gray-400">{{ inboundMetrics?.totalDeliveries ?? 0 }}</span>
                </button>
            </div>
        </div>

        <!-- ========================================================= -->
        <!-- TAB 1: OUTBOUND CUSTOMER DELIVERIES (Driver Dashboard)    -->
        <!-- ========================================================= -->
        <template v-if="currentTab === 'outbound'">
            <!-- Summary Metric Cards (6 core metrics requested). Colour only marks counts that need attention. -->
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
                <div
                    v-for="card in [
                        { label: isDeliveryStaff ? 'Assigned to you' : 'Total assigned', value: outboundMetrics?.totalAssigned ?? 0, alert: '' },
                        { label: 'To prepare', value: outboundMetrics?.pending ?? 0, alert: 'text-amber-600' },
                        { label: 'In transit', value: outboundMetrics?.inTransit ?? 0, alert: '' },
                        { label: 'Delivered', value: outboundMetrics?.delivered ?? 0, alert: '' },
                        { label: 'Attempt failed', value: outboundMetrics?.failed ?? 0, alert: 'text-rose-600' },
                        { label: 'Attempts left', value: outboundMetrics?.attemptsRemaining ?? 0, alert: '' },
                    ]"
                    :key="card.label"
                    class="rounded-xl border border-gray-200 bg-white px-4 py-3"
                >
                    <div class="text-xs text-gray-500">{{ card.label }}</div>
                    <div :class="card.value > 0 && card.alert ? card.alert : 'text-gray-900'" class="mt-1 text-2xl font-semibold">{{ card.value }}</div>
                </div>
            </div>

            <!-- Search & Filters Toolbar -->
            <div class="rounded-xl border border-gray-200 bg-white p-4">
                <div class="flex flex-col gap-3 md:flex-row md:flex-wrap md:items-center">
                    <!-- Search Input -->
                    <div class="relative min-w-0 md:min-w-64 md:flex-1">
                        <Search class="pointer-events-none absolute left-3.5 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                        <input
                            v-model="search"
                            @input="applyFilter"
                            type="search"
                            aria-label="Search deliveries"
                            placeholder="Search order #, recipient, address, phone or delivery #"
                            class="field-control pl-10"
                        />
                    </div>

                    <!-- Status Filter -->
                    <select
                        v-model="selectedStatus"
                        @change="applyFilter"
                        aria-label="Filter by status"
                        class="field-control md:w-56"
                    >
                        <option value="All">All statuses</option>
                        <option v-for="o in statusOptions" :key="o.value" :value="o.value">{{ o.label }}</option>
                    </select>

                    <!-- Staff Filter (Admin only) -->
                    <select
                        v-if="isAdmin"
                        v-model="selectedStaff"
                        @change="applyFilter"
                        aria-label="Filter by driver"
                        class="field-control md:w-56"
                    >
                        <option value="All">All Delivery Drivers</option>
                        <option v-for="staff in deliveryStaffList" :key="staff.id" :value="String(staff.id)">
                            {{ staff.name }} ({{ staff.role }})
                        </option>
                    </select>

                    <!-- Clear Filters -->
                    <button
                        v-if="hasActiveFilters"
                        @click="resetFilters"
                        type="button"
                        class="secondary-button shrink-0"
                    >
                        <RotateCcw class="size-4" />
                        Reset
                    </button>
                </div>
            </div>

            <!-- Outbound Delivery Cards / Table (Mobile Optimized) -->
            <div class="space-y-4">
                <!-- Empty State -->
                <div v-if="!outboundDeliveries?.data || outboundDeliveries.data.length === 0" class="flex flex-col items-center justify-center rounded-xl border border-dashed border-gray-200 bg-card/40 p-12 text-center">
                    <Truck class="size-12 text-muted-foreground/40" />
                    <h3 class="mt-4 text-base font-semibold text-foreground">No Delivery Tasks Found</h3>
                    <p class="mt-1 text-sm text-muted-foreground max-w-md">
                        {{ isDeliveryStaff 
                            ? 'You currently have no delivery tasks assigned. Check back later or contact your operations administrator.' 
                            : 'No customer order fulfillments match your selected filters.' }}
                    </p>
                </div>

                <!-- Deliveries List -->
                <div v-else class="grid grid-cols-1 gap-4 lg:grid-cols-2">
                    <div
                        v-for="item in outboundDeliveries.data"
                        :key="item.id"
                        class="flex min-w-0 flex-col justify-between rounded-xl border border-gray-200 bg-white p-5"
                    >
                        <!-- Top Row: Reference, Status & Attempt Badge -->
                        <div>
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                                        <span class="font-mono text-sm font-semibold text-foreground">
                                            {{ item.fulfillment_number }}
                                        </span>
                                        <span v-if="item.sale" class="font-mono text-xs text-muted-foreground">
                                            • {{ item.sale.order_number }}
                                        </span>
                                        <span class="text-xs text-muted-foreground">• {{ item.fulfillment_type === 'pickup' ? 'Pickup' : 'Delivery' }}</span>
                                    </div>
                                    <div class="mt-2 flex flex-wrap items-center gap-2">
                                        <span class="inline-flex items-center gap-1 rounded-md border px-2 py-0.5 text-xs font-medium" :class="getStatusBadgeClass(item.status)">
                                            {{ item.status_label ?? item.status }}
                                        </span>
                                        <!-- Attempt Counter Badge (0/3, 1/3, 2/3, 3/3) -->
                                        <span v-if="item.fulfillment_type !== 'pickup' && (item.status === 'in_transit' || (item.status === 'ready_for_delivery' && item.attempt_count > 0))" class="inline-flex items-center gap-1 rounded-md border px-2 py-0.5 text-xs" :class="getAttemptBadgeClass(item.attempt_count)">
                                            <RotateCcw class="size-3" />
                                            Attempt: {{ item.attempts_display }}
                                        </span>
                                    </div>
                                </div>

                                <!-- Driver Assignment Badge -->
                                <div class="shrink-0 text-right">
                                    <span v-if="item.handled_by" class="inline-flex items-center gap-1 text-xs text-muted-foreground">
                                        <UserIcon class="size-3" />
                                        {{ item.handled_by.name }}
                                    </span>
                                    <span v-else class="inline-flex items-center gap-1 rounded-md bg-amber-50 px-1.5 py-0.5 text-xs font-medium text-amber-600">
                                        Unassigned
                                    </span>
                                </div>
                            </div>

                            <!-- Customer Information Block (Read-only as required) -->
                            <div class="mt-4 space-y-2.5 rounded-xl border border-gray-200 bg-gray-50 p-4">
                                <!-- Customer Name -->
                                <div class="flex min-w-0 flex-wrap items-center gap-x-2 gap-y-0.5 text-sm font-semibold text-foreground">
                                    <UserCheck class="size-4 text-brand shrink-0" />
                                    <span class="break-words">{{ item.recipient_name }}</span>
                                    <span v-if="item.sale?.client_name && item.sale.client_name !== item.recipient_name" class="text-xs text-muted-foreground font-normal">
                                        ({{ item.sale.client_name }})
                                    </span>
                                </div>

                                <!-- Address -->
                                <div class="flex items-start gap-2 text-xs text-muted-foreground">
                                    <MapPin class="size-4 text-rose-600 shrink-0" />
                                    <span class="min-w-0 break-words leading-relaxed">{{ item.delivery_address || 'No address specified' }}</span>
                                </div>

                                <!-- Contact Phone (Click to call for mobile driver convenience) -->
                                <div class="flex flex-wrap items-center justify-between gap-2 border-t border-gray-200 pt-2.5 text-xs">
                                    <a :href="'tel:' + item.contact_number" class="flex items-center gap-2 font-medium text-brand hover:underline">
                                        <Phone class="size-4 shrink-0" />
                                        <span>{{ item.contact_number }}</span>
                                    </a>
                                    <span v-if="item.delivery_schedule" class="text-muted-foreground flex items-center gap-1.5">
                                        <Calendar class="size-3.5" />
                                        {{ formatDate(item.delivery_schedule) }}
                                    </span>
                                </div>
                            </div>

                            <!-- What is on its way, and what is still owed -->
                            <div v-if="item.open_release" class="mt-3 rounded-xl border border-blue-200 bg-blue-50 px-3 py-2 text-xs text-blue-900">
                                <span class="font-mono font-semibold">{{ item.open_release.release_number }}</span> · {{ releaseStatusText[item.open_release.status] ?? item.open_release.status }}:
                                {{ item.open_release.items.map((i) => `${i.sale_item?.product_name} × ${i.quantity}`).join(', ') }}
                            </div>
                            <p v-if="outstanding(item).length && !['cancelled', 'delivered'].includes(item.status)" class="mt-2 text-xs text-muted-foreground">
                                Still owed: {{ outstanding(item).map((l) => `${l.product_name} × ${l.remaining_quantity}`).join(', ') }}
                            </p>

                            <!-- Remarks / Notes Preview if any -->
                            <p v-if="item.notes" class="mt-3 line-clamp-2 text-xs italic text-muted-foreground">
                                "{{ item.notes }}"
                            </p>
                        </div>

                        <!-- Action Buttons Bar -->
                        <div class="mt-4 flex flex-wrap items-center justify-between gap-2 border-t border-gray-200 pt-4">
                            <!-- Left: Details and Proof -->
                            <div class="flex flex-wrap items-center gap-2">
                                <button
                                    @click="openDetails(item)"
                                    class="inline-flex h-8 items-center gap-1.5 whitespace-nowrap rounded-lg border border-gray-200 bg-gray-50 px-3 text-xs font-semibold text-muted-foreground transition-colors hover:bg-gray-100 hover:text-foreground"
                                >
                                    <Eye class="size-3.5" />
                                    Details & History
                                </button>

                                <button
                                    v-if="item.proof_url"
                                    @click="openProofViewer(item.proof_url, `Proof of Delivery: ${item.fulfillment_number}`)"
                                    class="inline-flex h-8 items-center gap-1.5 whitespace-nowrap rounded-lg border border-emerald-200 bg-emerald-50 px-3 text-xs font-semibold text-emerald-600 transition-colors hover:bg-emerald-500/20"
                                >
                                    <ImageIcon class="size-3.5" />
                                    Proof Photo
                                </button>
                            </div>

                            <!-- Right: the status update (admin and the assigned delivery staff) -->
                            <div class="flex flex-wrap items-center justify-end gap-2">
                                <span v-if="item.sale?.hold_reason" class="inline-flex h-8 items-center gap-1.5 whitespace-nowrap rounded-lg border border-amber-200 bg-amber-50 px-3 text-xs font-semibold text-amber-700" :title="item.sale.hold_reason">
                                    Order on hold
                                </span>
                                <span v-if="item.needs_reschedule"
                                      class="inline-flex h-8 items-center gap-1.5 whitespace-nowrap rounded-lg border border-rose-200 bg-rose-50 px-3 text-xs font-semibold text-rose-600">
                                    <ShieldAlert class="size-3.5" />
                                    3/3 attempts: admin must reschedule
                                </span>
                                <button v-if="can(item, 'assign') && !item.handled_by_id" @click="openAssignStaff(item)"
                                        class="inline-flex h-8 items-center gap-1.5 whitespace-nowrap rounded-lg border border-gray-200 bg-gray-100 px-3 text-xs font-semibold text-foreground hover:bg-gray-200">
                                    <UserCheck class="size-3.5" />
                                    Assign driver
                                </button>
                                <button v-if="item.status_choices?.length" @click="openStatusUpdate(item)"
                                        class="inline-flex h-8 items-center gap-1.5 whitespace-nowrap rounded-lg bg-brand px-3 text-xs font-semibold text-white transition-colors hover:bg-[#3b4219]">
                                    <RotateCcw class="size-3.5" />
                                    Update status
                                </button>
                                <!-- Admin only (the server also refuses staff). Unfinished deliveries cannot be deleted. -->
                                <button
                                    v-if="canDelete"
                                    type="button"
                                    @click="confirmDeleteOutbound(item)"
                                    :disabled="!!item.delete_blocked_reason"
                                    :title="item.delete_blocked_reason ? `Cannot delete: ${item.delete_blocked_reason}` : 'Delete this delivery record (moves it to the Recycle Bin)'"
                                    :aria-label="`Delete delivery ${item.fulfillment_number}`"
                                    class="inline-flex h-8 items-center gap-1.5 whitespace-nowrap rounded-lg border border-rose-200 bg-rose-50 px-3 text-xs font-semibold text-rose-600 transition-colors hover:bg-rose-100 disabled:cursor-not-allowed disabled:opacity-50 disabled:hover:bg-rose-50"
                                >
                                    <Trash2 class="size-3.5" />
                                    Delete
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Pagination -->
                <div v-if="outboundDeliveries?.links && outboundDeliveries.links.length > 3" class="flex flex-col gap-3 border-t border-gray-200 pt-4 sm:flex-row sm:items-center sm:justify-between">
                    <div class="text-xs text-muted-foreground">
                        Showing {{ (outboundDeliveries.current_page - 1) * 12 + 1 }} to {{ Math.min(outboundDeliveries.current_page * 12, outboundDeliveries.total) }} of {{ outboundDeliveries.total }} deliveries
                    </div>
                    <div class="flex flex-wrap items-center gap-1">
                        <button
                            v-for="(link, i) in outboundDeliveries.links"
                            :key="i"
                            @click="link.url && router.get(link.url, {}, { preserveScroll: true })"
                            :disabled="!link.url || link.active"
                            v-html="link.label"
                            class="inline-flex h-8 min-w-8 items-center justify-center rounded-lg px-3 text-xs font-medium transition-colors"
                            :class="{
                                'bg-brand text-white font-semibold': link.active,
                                'border border-gray-200 text-muted-foreground hover:text-foreground hover:bg-gray-50': !link.active && link.url,
                                'opacity-40 cursor-not-allowed': !link.url
                            }"
                        />
                    </div>
                </div>
            </div>
        </template>

        <!-- ========================================================= -->
        <!-- TAB 2: INBOUND SUPPLIER RECEIVING (Preserved)             -->
        <!-- ========================================================= -->
        <template v-else-if="!isDeliveryStaff && currentTab === 'inbound'">
            <!-- Inbound Action Bar -->
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <p class="text-sm text-muted-foreground">Supplier purchase order deliveries received into the warehouse. Accepted units are added to inventory when a delivery is confirmed.</p>
                <div class="flex shrink-0 items-center gap-2">
                    <button
                        type="button"
                        @click="openRecorder"
                        class="brand-button w-full sm:w-auto"
                        data-test="record-inbound"
                    >
                        <Plus class="size-4" />
                        Record Inbound P.O. Delivery
                    </button>
                </div>
            </div>

            <!-- Inbound Deliveries Table: one row per delivery record -->
            <div class="overflow-hidden rounded-xl border border-gray-200 bg-white">
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm" data-test="inbound-table">
                        <thead class="border-b border-gray-200 bg-gray-50 text-xs font-semibold text-muted-foreground">
                            <tr>
                                <th class="whitespace-nowrap px-4 py-3">Delivery Date</th>
                                <th class="whitespace-nowrap px-4 py-3">Delivery #</th>
                                <th class="whitespace-nowrap px-4 py-3">PO No.</th>
                                <th class="whitespace-nowrap px-4 py-3">Supplier</th>
                                <th class="whitespace-nowrap px-4 py-3 text-right">Products</th>
                                <th class="whitespace-nowrap px-4 py-3 text-right">Received</th>
                                <th class="whitespace-nowrap px-4 py-3">Quality</th>
                                <th class="whitespace-nowrap px-4 py-3 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <tr v-if="!inboundDeliveries?.data || inboundDeliveries.data.length === 0">
                                <td colspan="8" class="p-8 text-center text-sm text-muted-foreground">No inbound supplier delivery records found.</td>
                            </tr>
                            <tr v-for="item in inboundDeliveries?.data" :key="item.id" class="hover:bg-gray-50" :class="item.status === 'cancelled' ? 'text-gray-400' : ''">
                                <td class="whitespace-nowrap px-4 py-3 text-xs">{{ formatDate(item.delivery_date) }}</td>
                                <td class="whitespace-nowrap px-4 py-3 font-mono text-xs font-semibold" :class="item.status === 'cancelled' ? 'line-through' : 'text-foreground'">{{ item.delivery_number }}</td>
                                <td class="whitespace-nowrap px-4 py-3 font-mono text-xs text-brand">{{ item.purchase_order_number || '—' }}</td>
                                <td class="px-4 py-3 text-xs">{{ item.supplier?.name || '—' }}</td>
                                <td class="whitespace-nowrap px-4 py-3 text-right text-xs">{{ item.items_count }}</td>
                                <td class="whitespace-nowrap px-4 py-3 text-right text-xs">
                                    <span class="font-semibold">{{ Number(item.received_total ?? 0) }}</span>
                                    <div class="text-muted-foreground">{{ Number(item.accepted_total ?? 0) }} accepted · {{ Number(item.rejected_total ?? 0) }} rejected</div>
                                </td>
                                <td class="px-4 py-3 text-xs">
                                    <span class="inline-flex items-center rounded-md border px-2 py-0.5 font-medium" :class="inboundQualityClass[item.quality_status] ?? inboundQualityClass.Cancelled">{{ item.quality_status }}</span>
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-right">
                                    <button type="button" class="mr-1 inline-flex items-center gap-1 rounded-lg border border-gray-200 px-2 py-1 text-xs font-medium hover:bg-gray-50" :data-test="`view-delivery-${item.id}`" @click="viewingDeliveryId = item.id">
                                        <Eye class="size-3.5" /> View
                                    </button>
                                    <button
                                        v-if="item.status !== 'cancelled'"
                                        type="button"
                                        class="mr-1 inline-flex items-center gap-1 rounded-lg border border-gray-200 px-2 py-1 text-xs font-medium hover:bg-gray-50"
                                        :disabled="loadingCorrectionId === item.id"
                                        @click="openCorrectionById(item.id)"
                                    >
                                        <Pencil class="size-3.5" /> {{ item.quality_status === 'Pending Inspection' ? 'Inspect' : 'Correct' }}
                                    </button>
                                    <button
                                        v-if="canDelete && isAdmin && item.status !== 'cancelled'"
                                        type="button"
                                        class="rounded-lg p-1 text-muted-foreground hover:bg-rose-50 hover:text-rose-600"
                                        :aria-label="`Delete delivery ${item.delivery_number}`"
                                        @click="confirmDeleteInbound(item)"
                                    >
                                        <Trash2 class="size-4" />
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div v-if="(inboundDeliveries?.last_page ?? 1) > 1" class="flex items-center justify-between border-t border-gray-200 px-4 py-3 text-xs text-muted-foreground">
                    <span>Page {{ inboundDeliveries?.current_page }} of {{ inboundDeliveries?.last_page }} · {{ inboundDeliveries?.total }} deliveries</span>
                    <div class="flex gap-2">
                        <button type="button" class="secondary-button min-h-8 px-3 py-1 text-xs" :disabled="(inboundDeliveries?.current_page ?? 1) <= 1" @click="goToInboundPage((inboundDeliveries?.current_page ?? 1) - 1)">Previous</button>
                        <button type="button" class="secondary-button min-h-8 px-3 py-1 text-xs" :disabled="(inboundDeliveries?.current_page ?? 1) >= (inboundDeliveries?.last_page ?? 1)" @click="goToInboundPage((inboundDeliveries?.current_page ?? 1) + 1)">Next</button>
                    </div>
                </div>
            </div>
        </template>
    </div>

    <!-- ========================================================= -->
    <!-- MODAL 1: START DELIVERY CONFIRMATION                      -->
    <!-- ========================================================= -->
    <div v-if="showStartModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/70 p-4">
        <div class="max-h-[90vh] w-full max-w-md overflow-y-auto rounded-xl border border-gray-200 bg-card p-6 shadow-xl">
            <div class="flex items-center gap-3 text-blue-600">
                <Navigation class="size-6 shrink-0" />
                <h3 class="text-lg font-semibold text-foreground">{{ selectedFulfillment?.status === 'ready_for_delivery' && (selectedFulfillment?.attempt_count ?? 0) > 0 ? 'Dispatch again' : 'Dispatch release' }}</h3>
            </div>
            <p class="mt-3 text-sm text-muted-foreground leading-relaxed">
                Confirm that <strong class="text-foreground">{{ selectedFulfillment?.open_release?.release_number ?? selectedFulfillment?.fulfillment_number }}</strong> is leaving for <strong class="text-foreground">{{ selectedFulfillment?.recipient_name }}</strong>.
                The items are taken out of stock now (only on the first dispatch). The customer is emailed that the order is on its way.
            </p>
            <div class="mt-4 rounded-xl border border-gray-200 bg-gray-50 p-3 text-xs text-muted-foreground">
                <div class="font-medium text-foreground">Address:</div>
                <div>{{ selectedFulfillment?.delivery_address }}</div>
            </div>
            <div class="mt-6 flex flex-col-reverse gap-3 border-t border-gray-200 pt-4 sm:flex-row sm:items-center sm:justify-end">
                <button
                    @click="showStartModal = false"
                    type="button"
                    class="secondary-button"
                >
                    Cancel
                </button>
                <button
                    @click="confirmStartDelivery"
                    :disabled="startDeliveryForm.processing"
                    class="brand-button"
                >
                    <Navigation class="size-4" />
                    Confirm Out for Delivery
                </button>
            </div>
        </div>
    </div>

    <!-- ========================================================= -->
    <!-- MODAL 2: RECORD FAILED ATTEMPT (Strict 3-Limit Enforced)  -->
    <!-- ========================================================= -->
    <div v-if="showAttemptModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/70 p-4">
        <div class="max-h-[90vh] w-full max-w-lg overflow-y-auto rounded-xl border border-amber-200 bg-card p-6 shadow-xl">
            <div class="flex flex-wrap items-center justify-between gap-2 border-b border-gray-200 pb-3">
                <div class="flex items-center gap-2.5 text-amber-600">
                    <AlertTriangle class="size-5 shrink-0" />
                    <h3 class="text-lg font-semibold text-foreground">Record Unsuccessful Attempt</h3>
                </div>
                <span class="rounded-md border border-amber-200 bg-amber-50 px-2 py-0.5 text-xs font-semibold text-amber-700">
                    Recording Attempt {{ (selectedFulfillment?.attempt_count ?? 0) + 1 }} of 3
                </span>
            </div>

            <!-- Warning notice if this is attempt 3 -->
            <div v-if="(selectedFulfillment?.attempt_count ?? 0) >= 2" class="mt-4 flex items-start gap-2.5 rounded-xl border border-rose-200 bg-rose-50 p-3 text-xs text-rose-700">
                <ShieldAlert class="size-4 shrink-0 mt-0.5 text-rose-600" />
                <div>
                    <strong>CRITICAL NOTICE (Final Attempt 3 of 3):</strong>
                    This will reach the strict 3-attempt limit. The delivery will automatically lock to <em>Maximum Attempts Reached</em> and will require administrator follow-up.
                </div>
            </div>

            <form @submit.prevent="submitFailedAttempt" class="mt-4 space-y-4">
                <!-- Reason Dropdown -->
                <div>
                    <label class="field-label">Failure Reason</label>
                    <select
                        v-model="attemptForm.reason"
                        required
                        class="field-control"
                    >
                        <option value="Customer unavailable">Customer unavailable</option>
                        <option value="Incorrect or incomplete address">Incorrect or incomplete address</option>
                        <option value="Customer refused delivery">Customer refused delivery</option>
                        <option value="Customer unreachable">Customer unreachable (unresponsive on phone)</option>
                        <option value="Other reason">Other reason (explain in remarks)</option>
                    </select>
                </div>

                <!-- Remarks Textarea -->
                <div>
                    <label class="field-label">Detailed Remarks / Attempt Notes</label>
                    <textarea
                        v-model="attemptForm.remarks"
                        required
                        rows="3"
                        placeholder="Provide details about what happened during this attempt..."
                        class="field-control"
                    />
                </div>

                <!-- Optional Photo Evidence -->
                <div>
                    <label class="field-label">Optional Photo Evidence (Closed gate, site signage, etc.)</label>
                    <input
                        type="file"
                        accept="image/jpeg,image/png,image/jpg"
                        @change="handleAttemptPhotoChange"
                        class="mt-1.5 w-full rounded-xl border border-gray-200 bg-white p-2 text-xs text-muted-foreground file:mr-3 file:rounded-lg file:border-0 file:bg-brand file:px-3 file:py-1 file:text-xs file:font-semibold file:text-white"
                    />
                    <div v-if="attemptPhotoPreview" class="mt-2">
                        <img :src="attemptPhotoPreview" class="h-28 w-auto rounded-lg object-cover border border-gray-200" />
                    </div>
                </div>

                <div class="mt-6 flex flex-col-reverse gap-3 border-t border-gray-200 pt-4 sm:flex-row sm:items-center sm:justify-end">
                    <button
                        @click="showAttemptModal = false"
                        type="button"
                        class="secondary-button"
                    >
                        Cancel
                    </button>
                    <button
                        type="submit"
                        :disabled="attemptForm.processing"
                        class="inline-flex min-h-10 items-center justify-center gap-2 rounded-xl bg-amber-600 px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-amber-700 disabled:pointer-events-none disabled:opacity-50"
                    >
                        <AlertTriangle class="size-4" />
                        Record Attempt {{ (selectedFulfillment?.attempt_count ?? 0) + 1 }}/3
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- ========================================================= -->
    <!-- MODAL 3: MARK DELIVERED WITH PHOTO PROOF                  -->
    <!-- ========================================================= -->
    <div v-if="showDeliveredModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/70 p-4">
        <div class="max-h-[90vh] w-full max-w-lg overflow-y-auto rounded-xl border border-emerald-200 bg-card p-6 shadow-xl">
            <div class="flex items-center gap-2.5 border-b border-gray-200 pb-3 text-emerald-600">
                <Camera class="size-5 shrink-0" />
                <h3 class="text-lg font-semibold text-foreground">{{ isPickupDelivery ? 'Record pickup' : 'Record delivery' }}</h3>
            </div>

            <form @submit.prevent="submitDelivered" class="mt-4 space-y-4">
                <!-- Quantities received (anything not received goes back into stock) -->
                <div v-if="selectedFulfillment?.open_release">
                    <span class="field-label">Quantities received</span>
                    <div class="divide-y divide-gray-100 rounded-xl border border-gray-200">
                        <div v-for="ri in selectedFulfillment.open_release.items" :key="ri.id" class="flex items-center justify-between gap-3 px-3 py-2 text-sm">
                            <span class="min-w-0 truncate">{{ ri.sale_item?.product_name }} <span class="text-xs text-muted-foreground">(sent {{ ri.quantity }})</span></span>
                            <input v-model.number="deliveredForm.quantities[ri.sale_item_id]" type="number" min="0" :max="ri.quantity" class="field-control w-24 text-right" :aria-label="`Received of ${ri.sale_item?.product_name}`" />
                        </div>
                    </div>
                    <p class="mt-1 text-xs text-muted-foreground">Anything not received goes back into stock and stays owed to the customer.</p>
                </div>

                <!-- Proof Photo Upload (required for deliveries) -->
                <div>
                    <label class="field-label mb-0">
                        Photo proof <span v-if="!isPickupDelivery" class="text-rose-600">* (JPG or PNG required)</span><span v-else class="text-muted-foreground">(optional)</span>
                    </label>
                    <p class="mt-1 text-xs text-muted-foreground">Capture signed invoice, delivery receipt, or received package with customer.</p>
                    <div class="mt-2 flex flex-col items-center justify-center rounded-xl border-2 border-dashed border-emerald-200 bg-white p-4 text-center hover:border-emerald-500/50">
                        <input
                            type="file"
                            accept="image/jpeg,image/png,image/jpg"
                            :required="!isPickupDelivery"
                            @change="handleProofPhotoChange"
                            class="w-full cursor-pointer text-xs text-muted-foreground file:mr-3 file:rounded-lg file:border-0 file:bg-emerald-600 file:px-3 file:py-1.5 file:text-xs file:font-bold file:text-white"
                        />
                        <div v-if="deliveryPhotoPreview" class="mt-3 relative w-full">
                            <img :src="deliveryPhotoPreview" class="mx-auto max-h-48 rounded-lg object-contain border border-gray-200 shadow-md" />
                            <span class="mt-1 block text-center text-[10px] text-emerald-600">Preview ready for upload</span>
                        </div>
                    </div>
                </div>

                <!-- Recipient Confirmed Name -->
                <div>
                    <label class="field-label">{{ isPickupDelivery ? 'Picked up by (name) *' : 'Received by (name / title)' }}</label>
                    <input
                        v-model="deliveredForm.recipient_name"
                        type="text"
                        placeholder="Person who physically received the package"
                        class="field-control"
                    />
                </div>

                <!-- Handover Remarks -->
                <div>
                    <label class="field-label">Delivery Remarks</label>
                    <textarea
                        v-model="deliveredForm.remarks"
                        rows="2"
                        placeholder="Package condition, gate pass details, or recipient remarks..."
                        class="field-control"
                    />
                </div>

                <div class="mt-6 flex flex-col-reverse gap-3 border-t border-gray-200 pt-4 sm:flex-row sm:items-center sm:justify-end">
                    <button
                        @click="showDeliveredModal = false"
                        type="button"
                        class="secondary-button"
                    >
                        Cancel
                    </button>
                    <button
                        type="submit"
                        :disabled="deliveredForm.processing || (!isPickupDelivery && !deliveredForm.proof_of_delivery) || (isPickupDelivery && !deliveredForm.recipient_name.trim())"
                        class="brand-button"
                    >
                        <Check class="size-4" />
                        {{ isPickupDelivery ? 'Confirm pickup' : 'Confirm delivery' }}
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- ========================================================= -->
    <!-- MODAL 4: DETAILS & AUDIT ATTEMPT HISTORY DRAWER           -->
    <!-- ========================================================= -->
    <div v-if="showDetailsModal && selectedFulfillment" class="fixed inset-0 z-50 flex items-center justify-center bg-black/70 p-4">
        <div class="w-full max-w-2xl max-h-[90vh] overflow-y-auto rounded-xl border border-gray-200 bg-card p-6 shadow-xl">
            <!-- Header -->
            <div class="flex items-start justify-between gap-3 border-b border-gray-200 pb-4">
                <div>
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="font-mono text-lg font-semibold text-foreground">{{ selectedFulfillment.fulfillment_number }}</span>
                        <span class="inline-flex items-center rounded-md border px-2 py-0.5 text-xs font-medium" :class="getStatusBadgeClass(selectedFulfillment.status)">
                            {{ selectedFulfillment.status_label ?? selectedFulfillment.status }}
                        </span>
                        <span class="inline-flex items-center rounded-md border px-2 py-0.5 text-xs" :class="getAttemptBadgeClass(selectedFulfillment.attempt_count)">
                            {{ selectedFulfillment.attempts_display }}
                        </span>
                    </div>
                    <div class="mt-1 text-xs text-muted-foreground">
                        Order Fulfillment Details & Delivery Attempt History
                    </div>
                </div>
                <button @click="showDetailsModal = false" class="rounded-lg p-1.5 text-muted-foreground hover:bg-gray-100 hover:text-foreground">
                    <XCircle class="size-5" />
                </button>
            </div>

            <!-- Customer & Delivery Specs Grid -->
            <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
                <!-- Customer Details -->
                <div class="rounded-xl border border-gray-200 bg-gray-50 p-4 space-y-2">
                    <div class="text-xs font-semibold text-muted-foreground">Customer Information</div>
                    <div class="text-sm font-semibold text-foreground">{{ selectedFulfillment.recipient_name }}</div>
                    <div class="text-xs text-muted-foreground flex items-center gap-1.5">
                        <Phone class="size-3.5 text-brand" />
                        <a :href="'tel:' + selectedFulfillment.contact_number" class="hover:underline">{{ selectedFulfillment.contact_number }}</a>
                    </div>
                    <div class="text-xs text-muted-foreground flex items-start gap-1.5 pt-1">
                        <MapPin class="size-3.5 text-rose-600 shrink-0 mt-0.5" />
                        <span>{{ selectedFulfillment.delivery_address || 'No address specified' }}</span>
                    </div>
                </div>

                <!-- Order Details -->
                <div class="rounded-xl border border-gray-200 bg-gray-50 p-4 space-y-2">
                    <div class="text-xs font-semibold text-muted-foreground">Order & Logistics</div>
                    <div class="flex items-center justify-between text-xs">
                        <span class="text-muted-foreground">Sales Order:</span>
                        <span class="font-mono font-semibold text-foreground">{{ selectedFulfillment.sale?.order_number || 'N/A' }}</span>
                    </div>
                    <div class="flex items-center justify-between text-xs">
                        <span class="text-muted-foreground">Assigned Driver:</span>
                        <span class="font-semibold text-foreground">{{ selectedFulfillment.handled_by?.name || 'Unassigned' }}</span>
                    </div>
                    <div class="flex items-center justify-between text-xs">
                        <span class="text-muted-foreground">Scheduled Date:</span>
                        <span>{{ formatDate(selectedFulfillment.delivery_schedule) }}</span>
                    </div>
                    <div v-if="selectedFulfillment.delivered_at" class="flex items-center justify-between text-xs text-emerald-600">
                        <span>Delivered At:</span>
                        <span class="font-medium">{{ formatDateTime(selectedFulfillment.delivered_at) }}</span>
                    </div>
                </div>
            </div>

            <!-- Order Items breakdown -->
            <div v-if="selectedFulfillment.sale?.items && selectedFulfillment.sale.items.length > 0" class="mt-4 rounded-xl border border-gray-200 bg-gray-50 p-4">
                <div class="text-xs font-semibold text-muted-foreground mb-2">Order quantities</div>
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-xs">
                        <thead><tr><th class="py-1">Item</th><th class="py-1 text-right">Ordered</th><th class="py-1 text-right">Sent</th><th class="py-1 text-right">Received</th><th class="py-1 text-right">Remaining</th></tr></thead>
                        <tbody>
                            <tr v-for="line in selectedFulfillment.sale.items" :key="line.id" class="border-t border-gray-200">
                                <td class="py-1 text-foreground">{{ line.product_name }}</td>
                                <td class="py-1 text-right">{{ line.quantity }}</td>
                                <td class="py-1 text-right">{{ line.released_quantity }}</td>
                                <td class="py-1 text-right">{{ line.delivered_quantity }}</td>
                                <td class="py-1 text-right font-semibold">{{ line.remaining_quantity }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div v-if="selectedFulfillment.releases?.length" class="mt-3 space-y-1">
                    <div v-for="r in selectedFulfillment.releases" :key="r.id" class="flex flex-wrap items-center justify-between gap-2 text-xs">
                        <span><span class="font-mono font-semibold">{{ r.release_number }}</span> · {{ releaseStatusText[r.status] ?? r.status }}<span v-if="r.received_by_name"> · received by {{ r.received_by_name }}</span></span>
                        <a :href="`/${teamSlug}/deliveries/releases/${r.id}/pdf`" target="_blank" class="text-brand hover:underline">{{ r.type === 'pickup' ? 'Release slip' : 'Delivery receipt' }}</a>
                    </div>
                </div>
            </div>

            <!-- Chronological Attempt History (Requirement 7) -->
            <div class="mt-6">
                <div class="flex items-center justify-between">
                    <h4 class="text-xs font-semibold text-muted-foreground flex items-center gap-1.5">
                        Delivery Attempt & Audit History
                    </h4>
                    <span class="text-[11px] text-muted-foreground">{{ selectedFulfillment.attempt_logs?.length ?? 0 }} log entries</span>
                </div>

                <div class="mt-3 space-y-3">
                    <div v-if="!selectedFulfillment.attempt_logs || selectedFulfillment.attempt_logs.length === 0" class="rounded-xl border border-dashed border-gray-200 p-4 text-center text-xs text-muted-foreground">
                        No delivery attempt entries logged yet.
                    </div>

                    <div
                        v-for="log in selectedFulfillment.attempt_logs"
                        :key="log.id"
                        class="rounded-xl border border-gray-200 bg-gray-50 p-3 text-xs space-y-1.5"
                    >
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="font-semibold text-foreground">
                                    {{ log.attempt_number > 0 ? `Failed attempt #${log.attempt_number}` : 'Status change' }}
                                </span>
                                <span class="rounded border px-1.5 py-0.5 text-[10px] font-medium" :class="getStatusBadgeClass(log.new_status)">
                                    {{ statusLabel(log.new_status) }}
                                </span>
                            </div>
                            <span class="text-[11px] text-muted-foreground">{{ formatDateTime(log.attempt_date) }}</span>
                        </div>

                        <div v-if="log.failure_reason" class="text-rose-600 font-medium">
                            Reason: {{ log.failure_reason }}
                        </div>

                        <div class="text-muted-foreground leading-relaxed">
                            {{ log.remarks }}
                        </div>

                        <div class="flex items-center justify-between pt-1 text-[11px] text-muted-foreground/70">
                            <span>By: {{ log.staff?.name || 'System' }}</span>
                            <button
                                v-if="log.proof_url"
                                @click="openProofViewer(log.proof_url, `Attempt #${log.attempt_number} Photo`)"
                                class="text-brand hover:underline flex items-center gap-1 font-medium"
                            >
                                <ImageIcon class="size-3" />
                                View Photo
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <div class="mt-6 flex flex-col-reverse gap-3 border-t border-gray-200 pt-4 sm:flex-row sm:items-center sm:justify-end">
                <button
                    @click="showDetailsModal = false"
                    class="secondary-button"
                >
                    Close
                </button>
            </div>
        </div>
    </div>

    <!-- ========================================================= -->
    <!-- MODAL 5: ASSIGN DRIVER (Admin only)                       -->
    <!-- ========================================================= -->
    <div v-if="showAssignModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/70 p-4">
        <div class="max-h-[90vh] w-full max-w-md overflow-y-auto rounded-xl border border-gray-200 bg-card p-6 shadow-xl">
            <h3 class="text-lg font-semibold text-foreground">Assign Delivery Driver</h3>
            <p class="mt-1 text-xs text-muted-foreground">Select a registered staff member to handle delivery {{ selectedFulfillment?.fulfillment_number }}.</p>

            <form @submit.prevent="submitAssignStaff" class="mt-4 space-y-4">
                <div>
                    <label class="field-label">Delivery Staff Member</label>
                    <select
                        v-model="assignForm.staff_id"
                        required
                        class="field-control"
                    >
                        <option value="" disabled>Select Driver</option>
                        <option v-for="staff in deliveryStaffList" :key="staff.id" :value="String(staff.id)">
                            {{ staff.name }} ({{ staff.role }})
                        </option>
                    </select>
                </div>

                <div class="mt-6 flex flex-col-reverse gap-3 border-t border-gray-200 pt-4 sm:flex-row sm:items-center sm:justify-end">
                    <button
                        @click="showAssignModal = false"
                        type="button"
                        class="secondary-button"
                    >
                        Cancel
                    </button>
                    <button
                        type="submit"
                        :disabled="assignForm.processing || !assignForm.staff_id"
                        class="brand-button"
                    >
                        Assign Driver
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- ========================================================= -->
    <!-- MODAL 6: RESCHEDULE DELIVERY (Admin only)                 -->
    <!-- ========================================================= -->
    <div v-if="showRescheduleModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/70 p-4">
        <div class="max-h-[90vh] w-full max-w-md overflow-y-auto rounded-xl border border-gray-200 bg-card p-6 shadow-xl">
            <h3 class="text-lg font-semibold text-foreground">Reschedule Delivery & Reset Limit</h3>
            <p class="mt-1 text-xs text-muted-foreground">Authorize a new delivery window and grant fresh attempt slots.</p>

            <form @submit.prevent="submitReschedule" class="mt-4 space-y-4">
                <div>
                    <label class="field-label">New Delivery Date & Time</label>
                    <input
                        v-model="rescheduleForm.delivery_schedule"
                        type="datetime-local"
                        required
                        class="field-control"
                    />
                </div>

                <div class="flex items-center gap-2">
                    <input
                        id="resetAttempts"
                        v-model="rescheduleForm.reset_attempts"
                        type="checkbox"
                        class="size-4 rounded border-gray-200 bg-background text-brand focus:ring-brand/20"
                    />
                    <label for="resetAttempts" class="text-xs font-medium text-foreground cursor-pointer">
                        Reset Attempt Counter back to 0 of 3 attempts
                    </label>
                </div>

                <div>
                    <label class="field-label">Authorization Notes</label>
                    <textarea
                        v-model="rescheduleForm.notes"
                        rows="2"
                        placeholder="Reason for reschedule authorization (e.g. customer requested postponement)..."
                        class="field-control"
                    />
                </div>

                <div class="mt-6 flex flex-col-reverse gap-3 border-t border-gray-200 pt-4 sm:flex-row sm:items-center sm:justify-end">
                    <button
                        @click="showRescheduleModal = false"
                        type="button"
                        class="secondary-button"
                    >
                        Cancel
                    </button>
                    <button
                        type="submit"
                        :disabled="rescheduleForm.processing"
                        class="brand-button"
                    >
                        Authorize Reschedule
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- ========================================================= -->
    <!-- MODAL 7: PHOTO PROOF VIEWER                               -->
    <!-- ========================================================= -->
    <div v-if="showProofViewerModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/80 p-4">
        <div class="max-h-[90vh] w-full max-w-lg overflow-y-auto rounded-xl border border-gray-200 bg-card p-6 shadow-xl">
            <div class="flex items-center justify-between gap-3 border-b border-gray-200 pb-3">
                <h3 class="text-sm font-semibold text-foreground">{{ viewingProofTitle }}</h3>
                <button @click="showProofViewerModal = false" class="text-muted-foreground hover:text-foreground">
                    <XCircle class="size-5" />
                </button>
            </div>
            <div class="mt-4 flex items-center justify-center bg-black/40 rounded-xl p-2">
                <img :src="viewingProofUrl || ''" class="max-h-[70vh] max-w-full rounded-lg object-contain shadow-lg" />
            </div>
            <div class="mt-6 flex flex-col-reverse gap-3 border-t border-gray-200 pt-4 sm:flex-row sm:items-center sm:justify-end">
                <button
                    @click="showProofViewerModal = false"
                    class="secondary-button"
                >
                    Close
                </button>
            </div>
        </div>
    </div>

    <!-- ========================================================= -->
    <!-- UPDATE STATUS                                             -->
    <!-- ========================================================= -->
    <div v-if="showStatusUpdate && selectedFulfillment" class="fixed inset-0 z-50 flex items-center justify-center bg-black/70 p-4">
        <form @submit.prevent="submitStatusUpdate" class="max-h-[90vh] w-full max-w-lg overflow-y-auto rounded-xl border border-gray-200 bg-card p-6 shadow-xl">
            <h3 class="text-lg font-semibold text-foreground">Update delivery status</h3>
            <p class="mt-1 text-xs text-muted-foreground">
                {{ selectedFulfillment.fulfillment_number }} · {{ selectedFulfillment.sale?.order_number }} · now
                <span class="font-medium text-foreground">{{ selectedFulfillment.status_label }}</span>
                <span v-if="selectedFulfillment.fulfillment_type === 'pickup'"> · customer pickup</span>
            </p>

            <fieldset class="mt-4 space-y-2">
                <legend class="field-label">New status</legend>
                <label v-for="c in selectedFulfillment.status_choices" :key="c.action"
                       :class="statusChoice?.action === c.action ? 'border-[#4b5320] bg-[#4b5320]/5' : 'border-gray-200'"
                       class="flex cursor-pointer items-center gap-3 rounded-xl border p-3 text-sm">
                    <input v-model="statusChoice" type="radio" :value="c" class="accent-[#4b5320]" />
                    <span class="min-w-0 flex-1 text-foreground">{{ c.label }}</span>
                    <span v-if="c.status" class="shrink-0 rounded-md border px-2 py-0.5 text-[11px] font-medium" :class="getStatusBadgeClass(c.status)">{{ statusLabel(c.status) }}</span>
                </label>
            </fieldset>

            <div v-if="statusChoice" class="mt-4 space-y-4">
                <!-- Preparing: what goes out on this release -->
                <div v-if="statusChoice.needs === 'quantities'">
                    <span class="field-label">Items for this {{ selectedFulfillment.fulfillment_type === 'pickup' ? 'pickup' : 'trip' }}</span>
                    <div class="divide-y divide-gray-100 rounded-xl border border-gray-200">
                        <div v-for="line in selectedFulfillment.sale?.items ?? []" :key="line.id" class="flex items-center justify-between gap-3 px-3 py-2 text-sm">
                            <div class="min-w-0">
                                <div class="truncate font-medium text-foreground">{{ line.product_name }}</div>
                                <div class="text-xs text-muted-foreground">Ordered {{ line.quantity }} · delivered {{ line.delivered_quantity }} · can send {{ line.releasable_quantity }}</div>
                            </div>
                            <input v-model.number="statusForm.quantities[line.id]" type="number" min="0" :max="line.releasable_quantity" :disabled="line.releasable_quantity === 0"
                                   class="field-control w-24 text-right" :aria-label="`Quantity of ${line.product_name}`" />
                        </div>
                    </div>
                    <p class="mt-1 text-xs text-muted-foreground">Anything left out stays owed for a later release.</p>
                </div>

                <!-- Delivered: what was received, by whom, with proof -->
                <template v-if="statusChoice.needs === 'receipt'">
                    <div v-if="selectedFulfillment.open_release">
                        <span class="field-label">Quantities received</span>
                        <div class="divide-y divide-gray-100 rounded-xl border border-gray-200">
                            <div v-for="ri in selectedFulfillment.open_release.items" :key="ri.id" class="flex items-center justify-between gap-3 px-3 py-2 text-sm">
                                <span class="min-w-0 truncate">{{ ri.sale_item?.product_name }} <span class="text-xs text-muted-foreground">(sent {{ ri.quantity }})</span></span>
                                <input v-model.number="statusForm.quantities[ri.sale_item_id]" type="number" min="0" :max="ri.quantity" class="field-control w-24 text-right" :aria-label="`Received of ${ri.sale_item?.product_name}`" />
                            </div>
                        </div>
                        <p class="mt-1 text-xs text-muted-foreground">If something was not received, it goes back into stock and stays owed; the status returns to Preparing.</p>
                    </div>
                    <div>
                        <label class="field-label" for="su-name">{{ selectedFulfillment.fulfillment_type === 'pickup' ? 'Picked up by (name)' : 'Received by (name)' }}</label>
                        <input id="su-name" v-model="statusForm.recipient_name" required maxlength="255" class="field-control" />
                    </div>
                    <div>
                        <label class="field-label" for="su-photo">Photo proof {{ statusNeedsPhoto ? '(required, JPG or PNG)' : '(optional)' }}</label>
                        <input id="su-photo" type="file" accept="image/jpeg,image/png" :required="statusNeedsPhoto" class="field-control py-1.5 text-xs"
                               @change="statusForm.photo = ($event.target as HTMLInputElement).files?.[0] ?? null" />
                    </div>
                </template>

                <!-- Delivery Attempt Failed -->
                <template v-if="statusChoice.needs === 'attempt'">
                    <p class="rounded-xl border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800">
                        Attempt {{ selectedFulfillment.attempt_count + 1 }} of 3. After the third, an administrator has to reschedule. The customer is emailed.
                    </p>
                    <div>
                        <label class="field-label" for="su-failure">Reason</label>
                        <select id="su-failure" v-model="statusForm.failure_reason" class="field-control">
                            <option v-for="r in failureReasons" :key="r" :value="r">{{ r }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="field-label" for="su-photo-attempt">Photo (optional)</label>
                        <input id="su-photo-attempt" type="file" accept="image/jpeg,image/png" class="field-control py-1.5 text-xs"
                               @change="statusForm.photo = ($event.target as HTMLInputElement).files?.[0] ?? null" />
                    </div>
                </template>

                <!-- Reschedule after a failed attempt -->
                <template v-if="statusChoice.needs === 'schedule'">
                    <div>
                        <label class="field-label" for="su-date">New delivery date and time</label>
                        <input id="su-date" v-model="statusForm.delivery_schedule" type="datetime-local" required class="field-control" />
                    </div>
                    <label class="flex items-center gap-2 text-sm text-foreground">
                        <input v-model="statusForm.reset_attempts" type="checkbox" class="accent-[#4b5320]" /> Reset the attempt counter to 0 of 3
                    </label>
                </template>

                <div v-if="statusChoice.needs === 'reason'">
                    <label class="field-label" for="su-reason">Reason</label>
                    <textarea id="su-reason" v-model="statusForm.reason" rows="2" maxlength="500" required class="field-control"></textarea>
                </div>

                <div v-if="statusChoice.needs !== 'reason'">
                    <label class="field-label" for="su-remarks">{{ statusChoice.needs === 'attempt' ? 'What happened' : 'Remarks (optional)' }}</label>
                    <textarea id="su-remarks" v-model="statusForm.remarks" rows="2" maxlength="1000" :required="statusChoice.needs === 'attempt'" class="field-control"></textarea>
                </div>

                <p v-if="statusChoice.status && ['ready_for_delivery', 'in_transit', 'delivered', 'cancelled'].includes(statusChoice.status)" class="text-xs text-muted-foreground">
                    The customer is emailed about this change.
                </p>
            </div>

            <div class="mt-6 flex flex-col-reverse gap-3 border-t border-gray-200 pt-4 sm:flex-row sm:items-center sm:justify-end">
                <button type="button" class="secondary-button" @click="showStatusUpdate = false">Cancel</button>
                <button type="submit" class="brand-button" :disabled="!canSubmitStatus">{{ statusSaving ? 'Saving…' : 'Update status' }}</button>
            </div>
        </form>
    </div>

    <!-- ========================================================= -->
    <!-- PREPARE RELEASE                                           -->
    <!-- ========================================================= -->
    <div v-if="showPrepareModal && selectedFulfillment" class="fixed inset-0 z-50 flex items-center justify-center bg-black/70 p-4">
        <form @submit.prevent="submitPrepare" class="max-h-[90vh] w-full max-w-lg overflow-y-auto rounded-xl border border-gray-200 bg-card p-6 shadow-xl">
            <h3 class="text-lg font-semibold text-foreground">Prepare release</h3>
            <p class="mt-1 text-xs text-muted-foreground">
                {{ selectedFulfillment.sale?.order_number }} · {{ selectedFulfillment.recipient_name }}. Choose what goes out on this
                {{ selectedFulfillment.fulfillment_type === 'pickup' ? 'pickup' : 'trip' }}; the rest stays owed for a later release.
            </p>
            <div class="mt-4 divide-y divide-gray-100 rounded-xl border border-gray-200">
                <div v-for="line in selectedFulfillment.sale?.items ?? []" :key="line.id" class="flex items-center justify-between gap-3 px-3 py-2 text-sm">
                    <div class="min-w-0">
                        <div class="truncate font-medium text-foreground">{{ line.product_name }}</div>
                        <div class="text-xs text-muted-foreground">Ordered {{ line.quantity }} · delivered {{ line.delivered_quantity }} · can release {{ line.releasable_quantity }}</div>
                    </div>
                    <input v-model.number="prepareQuantities[line.id]" type="number" min="0" :max="line.releasable_quantity" :disabled="line.releasable_quantity === 0"
                           class="field-control w-24 text-right" :aria-label="`Quantity of ${line.product_name}`" />
                </div>
            </div>
            <label class="field-label mt-4" for="prepare-remarks">Remarks (optional)</label>
            <textarea id="prepare-remarks" v-model="prepareRemarks" rows="2" maxlength="1000" class="field-control" placeholder="e.g. second truck, breakers only"></textarea>
            <div class="mt-6 flex flex-col-reverse gap-3 border-t border-gray-200 pt-4 sm:flex-row sm:items-center sm:justify-end">
                <button type="button" class="secondary-button" @click="showPrepareModal = false">Cancel</button>
                <button type="submit" class="brand-button" :disabled="preparing || !Object.values(prepareQuantities).some((q) => Number(q) > 0)">
                    <Package class="size-4" /> Start preparing
                </button>
            </div>
        </form>
    </div>

    <!-- ========================================================= -->
    <!-- REASON PROMPT (cancel release / returned)                 -->
    <!-- ========================================================= -->
    <div v-if="reasonPrompt" class="fixed inset-0 z-50 flex items-center justify-center bg-black/70 p-4">
        <form @submit.prevent="submitReason" class="max-h-[90vh] w-full max-w-md overflow-y-auto rounded-xl border border-gray-200 bg-card p-6 shadow-xl">
            <h3 class="text-lg font-semibold text-foreground">{{ reasonPrompt.title }}</h3>
            <p class="mt-1 text-xs text-muted-foreground">{{ reasonPrompt.help }}</p>
            <label class="field-label mt-4" for="delivery-reason">Reason</label>
            <textarea id="delivery-reason" v-model="reasonText" rows="3" maxlength="500" required class="field-control"></textarea>
            <div class="mt-6 flex flex-col-reverse gap-3 border-t border-gray-200 pt-4 sm:flex-row sm:items-center sm:justify-end">
                <button type="button" class="secondary-button" @click="reasonPrompt = null">Back</button>
                <button type="submit" class="brand-button" :disabled="!reasonText.trim()">{{ reasonPrompt.confirm }}</button>
            </div>
        </form>
    </div>

    <!-- ========================================================= -->
    <!-- INBOUND P.O. DELIVERY: record / correct, view, delete      -->
    <!-- ========================================================= -->
    <InboundDeliveryRecorder
        :open="showRecorder"
        :team-slug="teamSlug"
        :is-admin="!!isAdmin"
        :orders="receivableOrders ?? []"
        :products="products ?? []"
        :categories="productCategories ?? []"
        :delivery="correctingDelivery"
        @close="closeRecorder"
        @saved="onRecorderSaved"
    />
    <InboundDeliveryDetails
        :delivery-id="viewingDeliveryId"
        :team-slug="teamSlug"
        :reload-key="detailsReloadKey"
        @close="viewingDeliveryId = null"
        @correct="openCorrection"
        @delete="confirmDeleteInbound"
        @open-po="(id) => (workspacePoId = id)"
        @post="postToInventory"
    />

    <!-- Delete (cancel) an inbound delivery: admin only -->
    <div v-if="deliveryToDelete" class="fixed inset-0 z-[60] flex items-center justify-center bg-black/70 p-4" @click.self="deliveryToDelete = null">
        <form class="max-h-[90vh] w-full max-w-md overflow-y-auto rounded-xl border border-rose-200 bg-card p-6 shadow-xl" role="dialog" aria-modal="true" aria-labelledby="delete-inbound-title" @submit.prevent="executeDeleteInbound">
            <h3 id="delete-inbound-title" class="text-base font-semibold text-foreground">Delete delivery {{ deliveryToDelete.delivery_number }}</h3>
            <p class="mt-2 text-xs leading-relaxed text-muted-foreground">
                The record is kept as Cancelled for the audit trail. The units it added to inventory are taken back out and the purchase order's receiving status is recalculated.
                It is refused if those units were already used (stock would go negative).
            </p>
            <label for="delete-inbound-reason" class="field-label mt-4">Reason</label>
            <textarea id="delete-inbound-reason" v-model="deleteInboundReason" rows="2" maxlength="500" required class="field-control"></textarea>
            <label for="delete-inbound-confirm" class="mt-3 mb-1.5 block text-xs font-semibold text-gray-700">
                Type <span class="rounded border border-rose-200 bg-rose-50 px-1.5 py-0.5 font-mono font-semibold text-rose-600">YES</span> to confirm:
            </label>
            <input id="delete-inbound-confirm" v-model="deleteInboundConfirmInput" type="text" placeholder="Type YES here" autocomplete="off"
                   class="w-full rounded-xl border border-gray-300 px-3 py-2 font-mono text-sm focus:border-rose-500 focus:outline-none focus:ring-1 focus:ring-rose-500" />
            <div class="mt-6 flex flex-col-reverse gap-3 border-t border-gray-200 pt-4 sm:flex-row sm:items-center sm:justify-end">
                <button type="button" class="secondary-button" @click="deliveryToDelete = null">Cancel</button>
                <button
                    type="submit"
                    :disabled="deleteInboundConfirmInput !== 'YES' || !deleteInboundReason.trim() || deletingInbound"
                    class="inline-flex min-h-10 items-center justify-center rounded-xl bg-rose-600 px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-rose-700 disabled:cursor-not-allowed disabled:opacity-50"
                >
                    {{ deletingInbound ? 'Deleting…' : 'Delete' }}
                </button>
            </div>
        </form>
    </div>
    <!-- Delete customer delivery confirmation (admin only) -->
    <div v-if="outboundToDelete" class="fixed inset-0 z-50 flex items-center justify-center bg-black/70 p-4" @click.self="outboundToDelete = null">
        <div class="max-h-[90vh] w-full max-w-md overflow-y-auto rounded-xl border border-rose-200 bg-card p-6 shadow-xl" role="dialog" aria-modal="true" aria-labelledby="delete-outbound-title">
            <h3 id="delete-outbound-title" class="text-base font-semibold text-foreground">Delete Customer Delivery</h3>
            <p class="mt-2 text-xs text-muted-foreground leading-relaxed">
                Move delivery <span class="font-mono font-semibold text-foreground">{{ outboundToDelete.fulfillment_number }}</span><template v-if="outboundToDelete.recipient_name"> for {{ outboundToDelete.recipient_name }}</template>
                to the Recycle Bin? The sales order, its stock records and its status history are kept. An Admin can restore it from the Recycle Bin. The deletion is recorded in the audit trail.
            </p>
            <div class="mt-4 mb-5">
                <label for="delete-outbound-confirm" class="block text-xs font-semibold text-gray-700 mb-1.5">
                    Type <span class="font-mono font-semibold text-rose-600 bg-rose-50 px-1.5 py-0.5 rounded border border-rose-200">YES</span> to confirm deletion:
                </label>
                <input
                    id="delete-outbound-confirm"
                    v-model="deleteOutboundConfirmInput"
                    type="text"
                    placeholder="Type YES here"
                    class="w-full px-3 py-2 text-sm font-mono rounded-xl border border-gray-300 focus:outline-none focus:border-rose-500 focus:ring-1 focus:ring-rose-500"
                    @keyup.enter="deleteOutboundConfirmInput === 'YES' && executeDeleteOutbound()"
                />
            </div>
            <div class="mt-6 flex flex-col-reverse gap-3 border-t border-gray-200 pt-4 sm:flex-row sm:items-center sm:justify-end">
                <button @click="outboundToDelete = null" type="button" class="secondary-button">Cancel</button>
                <button
                    @click="executeDeleteOutbound"
                    type="button"
                    :disabled="deleteOutboundConfirmInput !== 'YES' || deletingOutbound"
                    class="inline-flex min-h-10 items-center justify-center rounded-xl bg-rose-600 px-4 py-2 text-sm font-semibold text-white transition-colors hover:bg-rose-700 disabled:opacity-50 disabled:cursor-not-allowed"
                >
                    {{ deletingOutbound ? 'Deleting...' : 'Delete' }}
                </button>
            </div>
        </div>
    </div>

    <PurchaseOrderWorkspace :po-id="workspacePoId" :is-admin="!!isAdmin" @close="workspacePoId = null; detailsReloadKey++; router.reload({ only: ['inboundDeliveries', 'purchaseOrders', 'receivableOrders', 'inboundMetrics'] })" />
</template>
