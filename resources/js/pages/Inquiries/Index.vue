<script setup lang="ts">
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { showToast } from '@/composables/useFlashToast';
import { ref, computed } from 'vue';
import {
    Search,
    MessageSquare,
    Mail,
    Phone,
    Clock,
    CheckCircle2,
    AlertCircle,
    Trash2,
    Calendar,
    Send,
    UserCheck,
    Filter,
    RefreshCw,
    Eye,
    X,
    FileText,
    Check,
    AlertTriangle,
    Shield,
    CornerDownRight,
    Loader2
} from '@lucide/vue';

interface InquiryItem {
    id: number;
    team_id: number;
    name: string;
    email: string;
    phone?: string | null;
    subject?: string | null;
    message: string;
    status: 'unread' | 'read' | 'in_progress' | 'resolved' | 'archived';
    priority: 'low' | 'normal' | 'high' | 'urgent';
    assigned_to_id?: number | null;
    admin_notes?: string | null;
    reply_message?: string | null;
    replied_at?: string | null;
    replied_by_id?: number | null;
    ip_address?: string | null;
    user_agent?: string | null;
    created_at: string;
    updated_at: string;
    assigned_to?: {
        id: number;
        name: string;
        email: string;
        role: string;
    } | null;
    replied_by?: {
        id: number;
        name: string;
        email: string;
    } | null;
}

interface StaffMember {
    id: number;
    name: string;
    email: string;
    role: string;
}

const props = defineProps<{
    inquiries: {
        data: InquiryItem[];
        current_page: number;
        last_page: number;
        total: number;
        from: number | null;
        to: number | null;
        links: Array<{
            url: string | null;
            label: string;
            active: boolean;
        }>;
    };
    metrics: {
        totalInquiries: number;
        unreadCount: number;
        inProgressCount: number;
        resolvedCount: number;
        todayCount: number;
    };
    filters?: {
        search?: string;
        status?: string;
        priority?: string;
        date_from?: string;
        date_to?: string;
    };
    staffMembers: StaffMember[];
}>();

const page = usePage();
const teamSlug = computed(() => {
    const pageTeam = (page.props as any).currentTeam?.slug || (page.props as any).team?.slug;
    if (pageTeam) return pageTeam;
    if (typeof window !== 'undefined') {
        const pathSegments = window.location.pathname.split('/').filter(Boolean);
        if (pathSegments.length > 0 && pathSegments[0] !== 'inquiries') {
            return pathSegments[0];
        }
    }
    return 'mr-pride';
});

// Flash toasts are handled globally by useFlashToast in AppSidebarLayout

// Filter states
const search = ref(props.filters?.search || '');
const selectedStatus = ref(props.filters?.status || 'all');
const selectedPriority = ref(props.filters?.priority || 'all');
const dateFrom = ref(props.filters?.date_from || '');
const dateTo = ref(props.filters?.date_to || '');

let searchDebounce: ReturnType<typeof setTimeout> | null = null;
const applyFilter = () => {
    if (searchDebounce) clearTimeout(searchDebounce);
    searchDebounce = setTimeout(() => {
        router.get(
            `/${teamSlug.value}/inquiries`,
            {
                search: search.value || undefined,
                status: selectedStatus.value !== 'all' ? selectedStatus.value : undefined,
                priority: selectedPriority.value !== 'all' ? selectedPriority.value : undefined,
                date_from: dateFrom.value || undefined,
                date_to: dateTo.value || undefined,
            },
            { preserveState: true, preserveScroll: true, replace: true }
        );
    }, 300);
};

const resetFilters = () => {
    search.value = '';
    selectedStatus.value = 'all';
    selectedPriority.value = 'all';
    dateFrom.value = '';
    dateTo.value = '';
    applyFilter();
};

const hasActiveFilters = computed(() => {
    return !!search.value ||
        selectedStatus.value !== 'all' ||
        selectedPriority.value !== 'all' ||
        !!dateFrom.value ||
        !!dateTo.value;
});

// Active detail/reply modal
const selectedInquiry = ref<InquiryItem | null>(null);
const showDetailModal = ref(false);

const replyForm = useForm({
    reply_message: '',
    status: 'resolved',
});

const notesForm = useForm({
    admin_notes: '',
});

const assignForm = useForm({
    assigned_to_id: null as number | null,
});

const openDetailModal = (item: InquiryItem) => {
    selectedInquiry.value = item;
    replyForm.reply_message = '';
    replyForm.status = 'resolved';
    notesForm.admin_notes = item.admin_notes || '';
    assignForm.assigned_to_id = item.assigned_to_id || null;
    showDetailModal.value = true;

    // Automatically mark unread inquiry as read when opened
    if (item.status === 'unread') {
        router.patch(`/${teamSlug.value}/inquiries/${item.id}/status`, { status: 'read' }, {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                if (selectedInquiry.value && selectedInquiry.value.id === item.id) {
                    selectedInquiry.value.status = 'read';
                }
            }
        });
    }
};

const closeDetailModal = () => {
    showDetailModal.value = false;
    selectedInquiry.value = null;
    replyForm.reset();
    notesForm.reset();
};

const submitStatusChange = (inquiry: InquiryItem, newStatus: string) => {
    router.patch(`/${teamSlug.value}/inquiries/${inquiry.id}/status`, { status: newStatus }, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            showToast(`Inquiry #${inquiry.id} marked as ${newStatus}.`, 'success');
            if (selectedInquiry.value && selectedInquiry.value.id === inquiry.id) {
                selectedInquiry.value.status = newStatus as any;
            }
        },
        onError: () => {
            showToast('Failed to update status.', 'error');
        }
    });
};

const submitPriorityChange = (inquiry: InquiryItem, newPriority: string) => {
    router.patch(`/${teamSlug.value}/inquiries/${inquiry.id}/priority`, { priority: newPriority }, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            showToast(`Inquiry priority set to ${newPriority}.`, 'success');
            if (selectedInquiry.value && selectedInquiry.value.id === inquiry.id) {
                selectedInquiry.value.priority = newPriority as any;
            }
        },
        onError: () => {
            showToast('Failed to update priority.', 'error');
        }
    });
};

const submitNotes = () => {
    if (!selectedInquiry.value) return;
    notesForm.patch(`/${teamSlug.value}/inquiries/${selectedInquiry.value.id}/notes`, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            showToast('Internal notes saved successfully.', 'success');
            if (selectedInquiry.value) {
                selectedInquiry.value.admin_notes = notesForm.admin_notes;
            }
        },
        onError: () => {
            showToast('Failed to save notes.', 'error');
        }
    });
};

const submitAssignment = () => {
    if (!selectedInquiry.value) return;
    assignForm.patch(`/${teamSlug.value}/inquiries/${selectedInquiry.value.id}/assign`, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            showToast('Representative assignment updated.', 'success');
            const found = props.staffMembers.find(s => s.id === Number(assignForm.assigned_to_id));
            if (selectedInquiry.value) {
                selectedInquiry.value.assigned_to_id = assignForm.assigned_to_id;
                selectedInquiry.value.assigned_to = found || null;
            }
        },
        onError: () => {
            showToast('Failed to assign representative.', 'error');
        }
    });
};

const submitReply = () => {
    if (!selectedInquiry.value || !replyForm.reply_message.trim()) return;
    replyForm.post(`/${teamSlug.value}/inquiries/${selectedInquiry.value.id}/reply`, {
        preserveScroll: true,
        onSuccess: () => {
            showToast('Reply successfully sent to customer and inquiry updated!', 'success');
            closeDetailModal();
        },
        onError: () => {
            showToast('Failed to send reply.', 'error');
        }
    });
};

// Delete Modal Logic
const showDeleteModal = ref(false);
const inquiryToDelete = ref<InquiryItem | null>(null);
const isDeleting = ref(false);
const deleteConfirmInput = ref('');

const openDeleteModal = (item: InquiryItem) => {
    deleteConfirmInput.value = '';
    inquiryToDelete.value = item;
    showDeleteModal.value = true;
};

const closeDeleteModal = () => {
    deleteConfirmInput.value = '';
    showDeleteModal.value = false;
    inquiryToDelete.value = null;
    isDeleting.value = false;
};

const confirmDelete = () => {
    if (!inquiryToDelete.value) return;
    isDeleting.value = true;
    router.delete(`/${teamSlug.value}/inquiries/${inquiryToDelete.value.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            closeDeleteModal();
            if (showDetailModal.value) closeDetailModal();
            showToast('Inquiry message removed successfully.', 'success');
        },
        onError: () => {
            isDeleting.value = false;
            showToast('Failed to delete inquiry message.', 'error');
        }
    });
};

const formatDate = (dateStr?: string | null) => {
    if (!dateStr) return 'N/A';
    try {
        const d = new Date(dateStr);
        return d.toLocaleDateString('en-US', {
            month: 'short',
            day: 'numeric',
            year: 'numeric',
            hour: '2-digit',
            minute: '2-digit',
        });
    } catch {
        return dateStr;
    }
};

</script>

<template>
    <Head title="Customer Messages & Inquiries | Admin Dashboard" />

    <div class="workspace-page">
        <!-- Page Header -->
        <div class="page-header">
            <div>
                <h1 class="page-title">Messages / Inquiries</h1>
                <p class="page-subtitle">Messages from the Contact Us page: assign and reply.</p>
            </div>

            <!-- Header Actions -->
            <div class="page-actions">
                <button
                    type="button"
                    @click="router.reload()"
                    class="inline-flex cursor-pointer items-center gap-2 rounded-xl border border-gray-200 bg-white px-3.5 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 transition-colors"
                    title="Refresh data"
                >
                    <RefreshCw class="size-4 text-gray-500" />
                    <span>Refresh</span>
                </button>
            </div>
        </div>

        <!-- Metrics: colour only marks unread messages -->
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-5">
            <div
                v-for="card in [
                    { label: 'Total', value: metrics.totalInquiries, alert: '' },
                    { label: 'Unread', value: metrics.unreadCount, alert: 'text-amber-600' },
                    { label: 'In progress', value: metrics.inProgressCount, alert: '' },
                    { label: 'Resolved', value: metrics.resolvedCount, alert: '' },
                    { label: 'Received today', value: metrics.todayCount, alert: '' },
                ]"
                :key="card.label"
                class="rounded-xl border border-gray-200 bg-white px-4 py-3"
            >
                <div class="text-xs text-gray-500">{{ card.label }}</div>
                <div :class="card.value > 0 && card.alert ? card.alert : 'text-gray-900'" class="mt-1 text-2xl font-semibold">{{ card.value }}</div>
            </div>
        </div>

        <!-- Filters & Search Bar -->
        <div class="rounded-xl border border-gray-200 bg-white p-4">
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-12">
                <!-- Search input -->
                <div class="relative lg:col-span-4">
                    <Search class="pointer-events-none absolute left-3.5 top-1/2 size-4 -translate-y-1/2 text-gray-400" />
                    <input
                        v-model="search"
                        @input="applyFilter"
                        type="text"
                        placeholder="Search customer, email, subject, message..."
                        class="field-control w-full pl-9 pr-3.5"
                    />
                </div>

                <!-- Status Filter -->
                <div class="lg:col-span-2">
                    <select
                        v-model="selectedStatus"
                        @change="applyFilter"
                        class="field-control w-full"
                    >
                        <option value="all">All Statuses</option>
                        <option value="unread">Unread</option>
                        <option value="read">Read</option>
                        <option value="in_progress">In Progress</option>
                        <option value="resolved">Resolved</option>
                        <option value="archived">Archived</option>
                    </select>
                </div>

                <!-- Priority Filter -->
                <div class="lg:col-span-2">
                    <select
                        v-model="selectedPriority"
                        @change="applyFilter"
                        class="field-control w-full"
                    >
                        <option value="all">All Priorities</option>
                        <option value="urgent">Urgent</option>
                        <option value="high">High</option>
                        <option value="normal">Normal</option>
                        <option value="low">Low</option>
                    </select>
                </div>

                <!-- Date From -->
                <div class="lg:col-span-2">
                    <input
                        v-model="dateFrom"
                        @change="applyFilter"
                        type="date"
                        title="From Date"
                        class="field-control w-full"
                    />
                </div>

                <!-- Date To -->
                <div class="lg:col-span-2 flex items-center gap-2">
                    <input
                        v-model="dateTo"
                        @change="applyFilter"
                        type="date"
                        title="To Date"
                        class="field-control w-full"
                    />
                    <button
                        v-if="hasActiveFilters"
                        @click="resetFilters"
                        type="button"
                        class="cursor-pointer rounded-xl border border-gray-200 bg-gray-100 p-2 text-gray-500 hover:bg-gray-200 hover:text-gray-800 transition"
                        title="Reset Filters"
                    >
                        <X class="size-4" />
                    </button>
                </div>
            </div>
        </div>

        <!-- Inquiries Table -->
        <div class="rounded-xl border border-gray-200 bg-white overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs sm:text-sm text-gray-700">
                    <thead class="border-b border-gray-200 bg-gray-50/80 text-[11px] font-semibold text-gray-500">
                        <tr>
                            <th class="px-4 py-3">ID / Date</th>
                            <th class="px-4 py-3">Customer</th>
                            <th class="px-4 py-3">Subject & Preview</th>
                            <th class="px-4 py-3">Status</th>
                            <th class="px-4 py-3">Priority</th>
                            <th class="px-4 py-3">Assigned To</th>
                            <th class="px-4 py-3 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <tr
                            v-for="item in inquiries.data"
                            :key="item.id"
                            class="hover:bg-gray-50/70 transition"
                            :class="{ 'bg-amber-50/30 font-semibold text-gray-900': item.status === 'unread' }"
                        >
                            <!-- ID / Date -->
                            <td class="px-4 py-3 whitespace-nowrap">
                                <div class="font-mono text-xs font-semibold text-gray-900">#{{ item.id }}</div>
                                <div class="text-[11px] text-gray-400 flex items-center gap-1 mt-0.5">
                                    <Clock class="size-3" />
                                    {{ formatDate(item.created_at) }}
                                </div>
                            </td>

                            <!-- Customer Info -->
                            <td class="px-4 py-3 whitespace-nowrap">
                                <div class="font-semibold text-gray-900">{{ item.name }}</div>
                                <div class="flex items-center gap-1.5 text-xs text-gray-500 mt-0.5">
                                    <Mail class="size-3 text-gray-400" />
                                    <a :href="`mailto:${item.email}`" class="hover:text-[#4b5320] hover:underline">{{ item.email }}</a>
                                </div>
                                <div v-if="item.phone" class="flex items-center gap-1.5 text-[11px] text-gray-400 mt-0.5">
                                    <Phone class="size-3 text-gray-400" />
                                    <span>{{ item.phone }}</span>
                                </div>
                            </td>

                            <!-- Subject & Snippet -->
                            <td class="px-4 py-3 max-w-xs sm:max-w-md">
                                <div class="font-semibold text-gray-900 truncate">
                                    {{ item.subject || 'General Inquiry' }}
                                </div>
                                <div class="text-xs text-gray-500 line-clamp-2 mt-0.5">
                                    {{ item.message }}
                                </div>
                                <div v-if="item.reply_message" class="mt-1 flex items-center gap-1 text-[11px] font-medium text-emerald-700">
                                    <CornerDownRight class="size-3 text-emerald-600" />
                                    <span>Replied {{ formatDate(item.replied_at) }}</span>
                                </div>
                            </td>

                            <!-- Status -->
                            <td class="px-4 py-3 whitespace-nowrap">
                                <span
                                    class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-semibold"
                                    :class="{
                                        'bg-amber-100 text-amber-900 border border-amber-300': item.status === 'unread',
                                        'bg-gray-100 text-gray-700 border border-gray-200': item.status === 'read',
                                        'bg-blue-100 text-blue-900 border border-blue-200': item.status === 'in_progress',
                                        'bg-emerald-100 text-emerald-900 border border-emerald-300': item.status === 'resolved',
                                        'bg-neutral-100 text-neutral-600 border border-neutral-300': item.status === 'archived',
                                    }"
                                >
                                    <span
                                        class="size-1.5 rounded-full"
                                        :class="{
                                            'bg-amber-500': item.status === 'unread',
                                            'bg-gray-400': item.status === 'read',
                                            'bg-blue-500': item.status === 'in_progress',
                                            'bg-emerald-500': item.status === 'resolved',
                                            'bg-neutral-400': item.status === 'archived',
                                        }"
                                    ></span>
                                    <span class="capitalize">{{ item.status.replace('_', ' ') }}</span>
                                </span>
                            </td>

                            <!-- Priority -->
                            <td class="px-4 py-3 whitespace-nowrap">
                                <span
                                    class="inline-flex items-center rounded-md px-2 py-0.5 text-[11px] font-semibold"
                                    :class="{
                                        'bg-rose-100 text-rose-800 border border-rose-300': item.priority === 'urgent',
                                        'bg-orange-100 text-orange-800 border border-orange-200': item.priority === 'high',
                                        'bg-gray-100 text-gray-700': item.priority === 'normal',
                                        'bg-slate-100 text-slate-600': item.priority === 'low',
                                    }"
                                >
                                    {{ item.priority }}
                                </span>
                            </td>

                            <!-- Assigned To -->
                            <td class="px-4 py-3 whitespace-nowrap">
                                <div v-if="item.assigned_to" class="flex items-center gap-1.5 text-xs font-semibold text-gray-800">
                                    <Shield class="size-3.5 text-[#4b5320]" />
                                    <span>{{ item.assigned_to.name }}</span>
                                </div>
                                <div v-else class="text-xs text-gray-400 italic">
                                    Unassigned
                                </div>
                            </td>

                            <!-- Actions -->
                            <td class="px-4 py-3 text-right whitespace-nowrap">
                                <div class="inline-flex items-center gap-1.5">
                                    <button
                                        type="button"
                                        @click="openDetailModal(item)"
                                        class="cursor-pointer inline-flex items-center gap-1 rounded-lg bg-[#4b5320] px-3 py-1.5 text-xs font-semibold text-white hover:bg-[#393f18] transition"
                                        title="View message and reply"
                                    >
                                        <Eye class="size-3.5" />
                                        <span>View & Reply</span>
                                    </button>

                                    <button
                                        type="button"
                                        @click="openDeleteModal(item)"
                                        class="cursor-pointer rounded-lg p-1.5 text-gray-400 hover:bg-rose-50 hover:text-rose-600 transition"
                                        title="Delete inquiry"
                                    >
                                        <Trash2 class="size-4" />
                                    </button>
                                </div>
                            </td>
                        </tr>

                        <!-- Empty state -->
                        <tr v-if="!inquiries.data || inquiries.data.length === 0">
                            <td colspan="7" class="py-12 text-center">
                                <div class="mx-auto flex size-12 items-center justify-center rounded-xl bg-gray-100 text-gray-400">
                                    <MessageSquare class="size-6" />
                                </div>
                                <h3 class="mt-3 text-sm font-semibold text-gray-900">No Messages or Inquiries Found</h3>
                                <p class="mt-1 text-xs text-gray-500">
                                    {{ hasActiveFilters ? 'No inquiries match your selected filters.' : 'All customer inquiries submitted via the Contact Us page will appear here.' }}
                                </p>
                                <button
                                    v-if="hasActiveFilters"
                                    @click="resetFilters"
                                    class="mt-3 text-xs font-semibold text-[#4b5320] hover:underline"
                                >
                                    Clear all filters
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <div
                v-if="inquiries.data && inquiries.data.length > 0 && inquiries.links.length > 3"
                class="flex flex-col sm:flex-row items-center justify-between gap-3 border-t border-gray-200 bg-gray-50/50 px-4 py-3 text-xs text-gray-600"
            >
                <div>
                    Showing <span class="font-semibold text-gray-900">{{ inquiries.from }}</span> to <span class="font-semibold text-gray-900">{{ inquiries.to }}</span> of <span class="font-semibold text-gray-900">{{ inquiries.total }}</span> messages
                </div>
                <div class="flex items-center gap-1">
                    <template v-for="(link, i) in inquiries.links" :key="i">
                        <span
                            v-if="!link.url"
                            class="px-2.5 py-1 text-gray-300 rounded-md border border-transparent"
                            v-html="link.label"
                        />
                        <button
                            v-else
                            type="button"
                            @click="router.visit(link.url, { preserveScroll: true })"
                            class="px-2.5 py-1 rounded-md border text-xs font-medium transition cursor-pointer"
                            :class="link.active ? 'bg-[#4b5320] border-[#4b5320] text-white font-bold' : 'bg-white border-gray-200 text-gray-700 hover:bg-gray-100'"
                            v-html="link.label"
                        />
                    </template>
                </div>
            </div>
        </div>
    </div>

    <!-- DETAIL & REPLY MODAL -->
    <div
        v-if="showDetailModal && selectedInquiry"
        class="fixed inset-0 z-50 flex items-center justify-center overflow-y-auto bg-black/60 p-4 animate-in fade-in duration-150"
    >
        <div class="relative w-full max-w-3xl rounded-xl bg-white shadow-xl overflow-hidden max-h-[90vh] flex flex-col my-auto border border-gray-200">
            <!-- Modal Header -->
            <div class="flex items-center justify-between border-b border-gray-200 bg-gray-50/90 px-6 py-4">
                <div class="flex items-center gap-3">
                    <div class="flex size-9 items-center justify-center rounded-xl bg-[#4b5320] text-[#F8B803]">
                        <MessageSquare class="size-4.5" />
                    </div>
                    <div>
                        <div class="flex items-center gap-2">
                            <h2 class="text-base font-semibold text-gray-900">Inquiry #{{ selectedInquiry.id }}</h2>
                            <span
                                class="inline-flex items-center rounded-md px-2 py-0.5 text-[10px] font-semibold"
                                :class="{
                                    'bg-amber-100 text-amber-900': selectedInquiry.status === 'unread',
                                    'bg-gray-100 text-gray-800': selectedInquiry.status === 'read',
                                    'bg-blue-100 text-blue-900': selectedInquiry.status === 'in_progress',
                                    'bg-emerald-100 text-emerald-900': selectedInquiry.status === 'resolved',
                                    'bg-neutral-100 text-neutral-800': selectedInquiry.status === 'archived',
                                }"
                            >
                                {{ selectedInquiry.status.replace('_', ' ') }}
                            </span>
                        </div>
                        <p class="text-xs text-gray-500 mt-0.5">Received on {{ formatDate(selectedInquiry.created_at) }}</p>
                    </div>
                </div>

                <button
                    type="button"
                    @click="closeDetailModal"
                    class="cursor-pointer rounded-lg p-1.5 text-gray-400 hover:bg-gray-200 hover:text-gray-700 transition"
                >
                    <X class="size-5" />
                </button>
            </div>

            <!-- Modal Content (Scrollable) -->
            <div class="overflow-y-auto p-6 space-y-6 flex-1">
                <!-- Customer Information Box -->
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 rounded-xl border border-gray-200 bg-gray-50/50 p-4 text-xs">
                    <div>
                        <span class="text-[11px] font-semibold text-gray-400">Customer Name</span>
                        <div class="text-sm font-semibold text-gray-900 mt-0.5">{{ selectedInquiry.name }}</div>
                    </div>
                    <div>
                        <span class="text-[11px] font-semibold text-gray-400">Email</span>
                        <div class="text-sm font-semibold text-[#4b5320] mt-0.5">
                            <a :href="`mailto:${selectedInquiry.email}`" class="hover:underline">{{ selectedInquiry.email }}</a>
                        </div>
                    </div>
                    <div>
                        <span class="text-[11px] font-semibold text-gray-400">Phone</span>
                        <div class="text-sm font-semibold text-gray-900 mt-0.5">{{ selectedInquiry.phone || 'Not provided' }}</div>
                    </div>
                </div>

                <!-- Subject & Customer Message -->
                <div class="space-y-2">
                    <div class="text-xs font-semibold text-gray-500">Subject</div>
                    <div class="text-base font-semibold text-gray-900 bg-white p-3 rounded-xl border border-gray-200">
                        {{ selectedInquiry.subject || 'General Inquiry' }}
                    </div>

                    <div class="text-xs font-semibold text-gray-500 pt-2">Message Body</div>
                    <div class="rounded-xl border border-gray-200 bg-gray-50/60 p-4 text-sm leading-relaxed text-gray-800 whitespace-pre-line">
                        {{ selectedInquiry.message }}
                    </div>
                </div>

                <!-- Status, Priority, & Assignment Quick Controls -->
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 pt-2 border-t border-gray-100">
                    <!-- Status selector -->
                    <div>
                        <label class="block text-xs font-semibold text-gray-700 mb-1">Update Status</label>
                        <select
                            :value="selectedInquiry.status"
                            @change="submitStatusChange(selectedInquiry, ($event.target as HTMLSelectElement).value)"
                            class="w-full rounded-xl border border-gray-300 bg-white px-3 py-2 text-xs font-semibold text-gray-900 focus:border-[#4b5320] focus:ring-1 focus:ring-[#4b5320]"
                        >
                            <option value="unread">Unread</option>
                            <option value="read">Read</option>
                            <option value="in_progress">In Progress</option>
                            <option value="resolved">Resolved</option>
                            <option value="archived">Archived</option>
                        </select>
                    </div>

                    <!-- Priority selector -->
                    <div>
                        <label class="block text-xs font-semibold text-gray-700 mb-1">Priority</label>
                        <select
                            :value="selectedInquiry.priority"
                            @change="submitPriorityChange(selectedInquiry, ($event.target as HTMLSelectElement).value)"
                            class="w-full rounded-xl border border-gray-300 bg-white px-3 py-2 text-xs font-semibold text-gray-900 focus:border-[#4b5320] focus:ring-1 focus:ring-[#4b5320]"
                        >
                            <option value="low">Low</option>
                            <option value="normal">Normal</option>
                            <option value="high">High</option>
                            <option value="urgent">Urgent</option>
                        </select>
                    </div>

                    <!-- Assignee selector -->
                    <div>
                        <label class="block text-xs font-semibold text-gray-700 mb-1">Assign Representative</label>
                        <select
                            v-model="assignForm.assigned_to_id"
                            @change="submitAssignment"
                            class="w-full rounded-xl border border-gray-300 bg-white px-3 py-2 text-xs font-semibold text-gray-900 focus:border-[#4b5320] focus:ring-1 focus:ring-[#4b5320]"
                        >
                            <option :value="null">Unassigned</option>
                            <option v-for="staff in staffMembers" :key="staff.id" :value="staff.id">
                                {{ staff.name }} ({{ staff.role }})
                            </option>
                        </select>
                    </div>
                </div>

                <!-- Internal Admin Notes -->
                <div class="rounded-xl border border-gray-200 bg-amber-50/30 p-4 space-y-2">
                    <div class="flex items-center justify-between">
                        <label class="text-xs font-semibold text-amber-900 flex items-center gap-1.5">
                            Internal Staff Notes (Private)
                        </label>
                        <button
                            type="button"
                            @click="submitNotes"
                            :disabled="notesForm.processing"
                            class="cursor-pointer rounded-lg bg-amber-600 px-2.5 py-1 text-xs font-semibold text-white hover:bg-amber-700 disabled:opacity-50"
                        >
                            Save Notes
                        </button>
                    </div>
                    <textarea
                        v-model="notesForm.admin_notes"
                        rows="2"
                        placeholder="Add internal notes about this inquiry, customer preference, or call summary..."
                        class="w-full rounded-lg border border-amber-200 bg-white p-2.5 text-xs text-gray-900 focus:border-amber-500 focus:outline-none focus:ring-1 focus:ring-amber-500"
                    ></textarea>
                </div>

                <!-- Previous Reply Section (If Replied) -->
                <div v-if="selectedInquiry.reply_message" class="rounded-xl border border-emerald-200 bg-emerald-50/40 p-4 space-y-2">
                    <div class="flex items-center justify-between text-xs font-semibold text-emerald-900">
                        <span class="flex items-center gap-1.5">
                            <CheckCircle2 class="size-4 text-emerald-600" />
                            Previous Response to Customer
                        </span>
                        <span class="text-[11px] text-emerald-700 font-normal">
                            Sent on {{ formatDate(selectedInquiry.replied_at) }}
                            <span v-if="selectedInquiry.replied_by">by {{ selectedInquiry.replied_by.name }}</span>
                        </span>
                    </div>
                    <div class="rounded-lg bg-white p-3 text-xs leading-relaxed text-gray-800 whitespace-pre-line border border-emerald-100">
                        {{ selectedInquiry.reply_message }}
                    </div>
                </div>

                <!-- Reply Composer -->
                <div class="space-y-3 pt-2 border-t border-gray-200">
                    <div class="flex items-center justify-between">
                        <h3 class="text-sm font-semibold text-gray-900 flex items-center gap-2">
                            {{ selectedInquiry.reply_message ? 'Send Another Reply' : 'Send Customer Response' }}
                        </h3>
                        <span class="text-xs text-gray-500">Will be emailed to <strong class="text-gray-900">{{ selectedInquiry.email }}</strong></span>
                    </div>

                    <textarea
                        v-model="replyForm.reply_message"
                        rows="4"
                        required
                        placeholder="Write your email response to the customer here. Be professional and helpful..."
                        class="w-full rounded-xl border border-gray-300 p-3 text-xs sm:text-sm text-gray-900 focus:border-[#4b5320] focus:ring-1 focus:ring-[#4b5320] focus:outline-none"
                    ></textarea>

                    <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3">
                        <div class="flex items-center gap-2">
                            <label class="text-xs text-gray-600 font-medium">After sending, set status to:</label>
                            <select
                                v-model="replyForm.status"
                                class="rounded-lg border border-gray-300 bg-white px-2.5 py-1 text-xs font-semibold text-gray-800"
                            >
                                <option value="resolved">Resolved</option>
                                <option value="in_progress">In Progress</option>
                                <option value="read">Read</option>
                            </select>
                        </div>

                        <button
                            type="button"
                            @click="submitReply"
                            :disabled="replyForm.processing || !replyForm.reply_message.trim()"
                            class="cursor-pointer inline-flex items-center gap-2 rounded-xl bg-[#4b5320] px-5 py-2.5 text-xs font-semibold text-white shadow-md hover:bg-[#393f18] transition disabled:opacity-50 disabled:cursor-not-allowed"
                        >
                            <Loader2 v-if="replyForm.processing" class="size-4 animate-spin" />
                            <Send v-else class="size-4" />
                            <span>{{ replyForm.processing ? 'Sending...' : 'Send Reply via Email' }}</span>
                        </button>
                    </div>
                </div>
            </div>

            <!-- Modal Footer -->
            <div class="flex items-center justify-between border-t border-gray-200 bg-gray-50 px-6 py-3.5 text-xs">
                <span class="text-gray-400">IP: {{ selectedInquiry.ip_address || 'Unknown' }}</span>
                <button
                    type="button"
                    @click="closeDetailModal"
                    class="cursor-pointer rounded-xl border border-gray-300 bg-white px-4 py-2 font-semibold text-gray-700 hover:bg-gray-100 transition"
                >
                    Close
                </button>
            </div>
        </div>
    </div>

    <!-- DELETE CONFIRMATION MODAL -->
    <div
        v-if="showDeleteModal && inquiryToDelete"
        class="fixed inset-0 z-50 flex items-center justify-center overflow-y-auto bg-black/60 p-4 animate-in fade-in duration-150"
    >
        <div class="relative w-full max-w-md rounded-xl bg-white p-6 shadow-xl border border-gray-200">
            <div class="flex items-center gap-3 text-rose-600">
                <div class="flex size-10 items-center justify-center rounded-xl bg-rose-100">
                    <Trash2 class="size-5" />
                </div>
                <div>
                    <h3 class="text-base font-semibold text-gray-900">Delete Inquiry Message?</h3>
                    <p class="text-xs text-gray-500">Inquiry #{{ inquiryToDelete.id }} from {{ inquiryToDelete.name }}</p>
                </div>
            </div>

            <p class="mt-4 text-xs leading-relaxed text-gray-600">
                Are you sure you want to permanently delete this message inquiry? This action cannot be undone and will remove it from the inquiries log.
            </p>

            <div class="mt-4 mb-2">
                <label class="block text-xs font-semibold text-gray-700 mb-1.5">
                    Type <span class="font-mono font-semibold text-rose-600 bg-rose-50 px-1.5 py-0.5 rounded border border-rose-200">YES</span> if you wish to delete:
                </label>
                <input
                    v-model="deleteConfirmInput"
                    type="text"
                    placeholder="Type YES here"
                    class="w-full px-3 py-2 text-sm font-mono rounded-xl border border-gray-300 focus:outline-none focus:border-rose-500 focus:ring-1 focus:ring-rose-500"
                />
            </div>

            <div class="mt-6 flex items-center justify-end gap-3">
                <button
                    type="button"
                    @click="closeDeleteModal"
                    class="cursor-pointer rounded-xl border border-gray-300 bg-white px-4 py-2 text-xs font-semibold text-gray-700 hover:bg-gray-50 transition"
                >
                    Cancel
                </button>
                <button
                    type="button"
                    @click="confirmDelete"
                    :disabled="isDeleting || deleteConfirmInput.trim().toUpperCase() !== 'YES'"
                    class="cursor-pointer inline-flex items-center gap-1.5 rounded-xl bg-rose-600 px-4 py-2 text-xs font-semibold text-white hover:bg-rose-700 transition disabled:opacity-50"
                >
                    <Loader2 v-if="isDeleting" class="size-3.5 animate-spin" />
                    <span>{{ isDeleting ? 'Deleting...' : 'Delete Permanently' }}</span>
                </button>
            </div>
        </div>
    </div>
</template>
