<script setup lang="ts">
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { ref, computed, watch } from 'vue';
import ModuleMultiSelect from '@/components/ModuleMultiSelect.vue';
import SpecularButton from '@/components/ui/SpecularButton.vue';
import StatusBadge from '@/components/ui/StatusBadge.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import {
    Users,
    Activity,
    Laptop,
    FileText,
    ShieldAlert,
    Shield,
    ShieldCheck,
    Search,
    Plus,
    Trash2,
    Pencil,
    Check,
    CheckCircle2,
    X,
    Mail,
    Calendar,
    Clock,
    KeyRound,
    RefreshCw,
    AlertTriangle,
    Power,
    UserCheck,
    UserX,
    Copy,
    ExternalLink,
    Filter,
} from '@lucide/vue';

interface UserAccount {
    id: number;
    name: string;
    email: string;
    role: string;
    raw_role: string;
    modules?: string[];
    status: string;
    is_active: boolean;
    is_pending_activation?: boolean;
    must_change_password: boolean;
    password_changed_at?: string;
    last_login_at: string;
    joined_at: string;
    trusted_devices_count: number;
    is_superadmin: boolean;
}

interface ActiveSession {
    id: string;
    masked_id: string;
    user_id: number;
    user_name: string;
    user_email: string;
    user_role: string;
    ip_address: string;
    user_agent: string;
    browser: string;
    os: string;
    is_current: boolean;
    last_activity: string;
    last_activity_exact: string;
    is_expired: boolean;
    status: string;
}

interface TrustedDeviceItem {
    id: number;
    device_uuid: string;
    device_name: string;
    ip_address: string;
    status: string;
    raw_status: string;
    is_shared: boolean;
    is_current: boolean;
    last_used_at: string;
    created_at: string;
    enrollment_code: string | null;
    enrollment_token: string | null;
    enrollment_link: string | null;
    is_enrollment_expired: boolean;
    user: {
        id: number;
        name: string;
        email: string;
        role: string;
    } | null;
    authorized_users?: Array<{
        id: number;
        name: string;
        email: string;
        role: string;
    }>;
}

interface LogItem {
    id: number;
    category: string;
    user_id?: number;
    user_email?: string;
    target_user_id?: number;
    target_user_email?: string;
    event: string;
    action_type?: string;
    description: string;
    outcome: string;
    severity?: string;
    ip_address?: string;
    user_agent?: string;
    metadata?: any;
    created_at: string;
    user?: { id: number; name: string; email: string };
    target_user?: { id: number; name: string; email: string };
}

interface PaginatedLogs {
    data: LogItem[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    prev_page_url: string | null;
    next_page_url: string | null;
}

const props = defineProps<{
    activeTab: 'accounts' | 'sessions' | 'devices' | 'audit' | 'security';
    isSuperAdmin: boolean;
    users?: UserAccount[];
    /** Filtered, paginated accounts for the accounts table (only sent on the accounts tab). */
    accounts?: {
        data: UserAccount[];
        current_page: number;
        last_page: number;
        total: number;
        from: number | null;
        to: number | null;
        links: Array<{ url: string | null; label: string; active: boolean }>;
    } | null;
    /** Accounts per role filter option, following the current search and status. */
    roleCounts?: Record<string, number>;
    roleFilterOptions?: Array<{ value: string; label: string }>;
    sessions?: ActiveSession[];
    devices?: TrustedDeviceItem[];
    auditLogs?: PaginatedLogs | null;
    securityLogs?: PaginatedLogs | null;
    adminRecoveryKey?: string | null;
    filters?: {
        search?: string;
        role?: string;
        status?: string;
        event_filter?: string;
        severity?: string;
        date_from?: string;
        date_to?: string;
    };
    roles?: Array<{ value: string; label: string }>;
    moduleOptions?: Array<{ value: string; label: string }>;
    temporaryPasswordNotice?: string | null;
    enrollmentNotice?: {
        device_name?: string;
        user_name: string;
        user_email: string;
        code?: string;
        link: string;
        expires_at: string;
    } | null;
}>();

const page = usePage();
const teamSlug = computed(() => (page.props as any).currentTeam?.slug || (page.props as any).team?.slug || '');
const currentAuthUserId = computed(() => (page.props as any).auth?.user?.id);

// Tab switching
const switchTab = (tab: string) => {
    router.get(
        `/${teamSlug.value}/users`,
        { tab },
        { preserveState: false, preserveScroll: true }
    );
};

// Filters
const searchQuery = ref(props.filters?.search || '');
const selectedRole = ref(props.filters?.role || 'All Roles');
// Follow the server's filter (an unknown role in the URL falls back to All Roles).
watch(
    () => props.filters?.role,
    (role) => {
        selectedRole.value = role || 'All Roles';
    }
);
const selectedStatus = ref(props.filters?.status || 'All Statuses');
const selectedSeverity = ref(props.filters?.severity || 'All Severities');
const selectedEvent = ref(props.filters?.event_filter || 'All Events');
const dateFrom = ref(props.filters?.date_from || '');
const dateTo = ref(props.filters?.date_to || '');

const isMaintenanceLogView = computed(() => ['audit', 'security'].includes(props.activeTab));

const resolveFilterEndpoint = (): string => {
    const path = String(page.url || '').split('?')[0] || '';
    if (path.startsWith('/settings/maintenance')) {
        return props.activeTab === 'security' ? '/settings/maintenance/security-logs' : '/settings/maintenance/audit-trail';
    }
    return `/${teamSlug.value}/users`;
};

let filterTimeout: ReturnType<typeof setTimeout> | null = null;
const applyFilter = () => {
    if (filterTimeout) clearTimeout(filterTimeout);
    filterTimeout = setTimeout(() => {
        const endpoint = resolveFilterEndpoint();
        router.get(
            endpoint,
            {
                tab: endpoint.startsWith('/settings/maintenance') ? undefined : props.activeTab,
                search: searchQuery.value || undefined,
                role: selectedRole.value !== 'All Roles' ? selectedRole.value : undefined,
                status: selectedStatus.value !== 'All Statuses' ? selectedStatus.value : undefined,
                severity: selectedSeverity.value !== 'All Severities' ? selectedSeverity.value : undefined,
                event_filter: selectedEvent.value !== 'All Events' ? selectedEvent.value : undefined,
                date_from: dateFrom.value || undefined,
                date_to: dateTo.value || undefined,
            },
            { preserveState: true, preserveScroll: true, replace: true }
        );
    }, 300);
};

// Copy helpers
const copiedKey = ref(false);
const copiedCode = ref(false);
const copiedLink = ref(false);

const copyToClipboard = async (text: string, type: 'key' | 'code' | 'link' = 'key') => {
    try {
        await navigator.clipboard.writeText(text);
    } catch {
        const textarea = document.createElement('textarea');
        textarea.value = text;
        document.body.appendChild(textarea);
        textarea.select();
        document.execCommand('copy');
        document.body.removeChild(textarea);
    }

    if (type === 'code') {
        copiedCode.value = true;
        setTimeout(() => { copiedCode.value = false; }, 2500);
    } else if (type === 'link') {
        copiedLink.value = true;
        setTimeout(() => { copiedLink.value = false; }, 2500);
    } else {
        copiedKey.value = true;
        setTimeout(() => { copiedKey.value = false; }, 2500);
    }
};

// Modals State
const showAddModal = ref(false);
const showEditModal = ref(false);
const showStatusModal = ref(false);
const showDeleteModal = ref(false);
const showEnrollModal = ref(false);
const showRenameDeviceModal = ref(false);
const showRevokeSessionModal = ref(false);
const showRevokeUserSessionsModal = ref(false);
const showRevokeDeviceModal = ref(false);
const showMetadataModal = ref(false);

const selectedUser = ref<UserAccount | null>(null);
const selectedSession = ref<ActiveSession | null>(null);
const selectedDevice = ref<TrustedDeviceItem | null>(null);
const selectedMetadata = ref<any>(null);

// Forms
const addForm = useForm({
    first_name: '',
    last_name: '',
    email: '',
    account_type: 'staff' as 'admin' | 'staff',
    modules: [] as string[],
});

const editForm = useForm({
    id: null as number | null,
    name: '',
    account_type: 'staff' as 'admin' | 'staff',
    modules: [] as string[],
});

const moduleOptionList = computed(() => props.moduleOptions ?? []);

// Human-readable summary of the chosen account type / modules for confirmation dialogs
const describeAssignment = (accountType: 'admin' | 'staff', modules: string[]) => {
    if (accountType === 'admin') {
        return 'Administrator (All Modules)';
    }
    const labels = moduleOptionList.value
        .filter((o) => modules.includes(o.value))
        .map((o) => o.label);
    return labels.length ? `Staff - ${labels.join(', ')}` : 'Staff';
};

const enrollForm = useForm({
    assignment_type: 'single' as 'single' | 'shared',
    user_id: '' as string | number,
    authorized_user_ids: [] as number[],
    device_name: '',
    expires_in_minutes: 5,
    direct_approve: false,
});

const showEnrollmentResultModal = ref(false);
const activeEnrollmentResult = ref<{
    device_name?: string;
    user_name: string;
    user_email: string;
    code?: string;
    link: string;
    expires_at: string;
    expires_in_minutes?: number;
} | null>(null);



const dismissedEnrollmentNotice = ref(false);

watch(
    () => props.enrollmentNotice,
    (notice) => {
        if (notice && (notice.code || notice.link)) {
            activeEnrollmentResult.value = notice;
            dismissedEnrollmentNotice.value = false;
            showEnrollmentResultModal.value = true;
        }
    },
    { immediate: true }
);

const renameDeviceForm = useForm({
    device_name: '',
});

// Modal Triggers
const openAddModal = () => {
    addForm.reset();
    addForm.clearErrors();
    addForm.first_name = '';
    addForm.last_name = '';
    addForm.email = '';
    addForm.account_type = 'staff';
    addForm.modules = [];
    showAddModal.value = true;
};

const openEditModal = (user: UserAccount) => {
    selectedUser.value = user;
    editForm.clearErrors();
    editForm.id = user.id;
    editForm.name = user.name;
    editForm.account_type = user.raw_role === 'admin' ? 'admin' : 'staff';
    editForm.modules = [...(user.modules ?? [])];
    showEditModal.value = true;
};

const openStatusModal = (user: UserAccount) => {
    selectedUser.value = user;
    showStatusModal.value = true;
};

const openDeleteModal = (user: UserAccount) => {
    deleteConfirmInput.value = '';
    selectedUser.value = user;
    showDeleteModal.value = true;
};

const openEnrollModal = (user?: UserAccount) => {
    enrollForm.reset();
    enrollForm.clearErrors();
    enrollForm.assignment_type = 'single';
    enrollForm.user_id = user ? user.id : '';
    enrollForm.authorized_user_ids = [];
    enrollForm.device_name = user ? `${user.name}'s Device` : '';
    enrollForm.expires_in_minutes = 5;
    enrollForm.direct_approve = false;
    showEnrollModal.value = true;
};

const openRevokeSessionModal = (session: ActiveSession) => {
    selectedSession.value = session;
    showRevokeSessionModal.value = true;
};

const openRevokeUserSessionsModal = (user: UserAccount) => {
    selectedUser.value = user;
    showRevokeUserSessionsModal.value = true;
};

const openRevokeDeviceModal = (device: TrustedDeviceItem) => {
    revokeDeviceConfirmInput.value = '';
    selectedDevice.value = device;
    showRevokeDeviceModal.value = true;
};

const openMetadataModal = (log: LogItem) => {
    selectedMetadata.value = log.metadata;
    showMetadataModal.value = true;
};

// Action Confirmation Modal State
interface ConfirmActionOptions {
    title: string;
    description: string;
    details?: Array<{ label: string; value: string | number }>;
    confirmText?: string;
    confirmVariant?: 'brand' | 'destructive' | 'secondary';
    action: () => void;
}

const showConfirmModal = ref(false);
const deleteConfirmInput = ref('');
const confirmActionInput = ref('');
const revokeDeviceConfirmInput = ref('');
const confirmOptions = ref<ConfirmActionOptions>({
    title: 'Confirm Action',
    description: 'Are you sure you want to proceed?',
    details: [],
    confirmText: 'Confirm & Proceed',
    confirmVariant: 'brand',
    action: () => {},
});

const openConfirmModal = (options: ConfirmActionOptions) => {
    confirmActionInput.value = '';
    confirmOptions.value = {
        confirmText: 'Confirm & Proceed',
        confirmVariant: 'brand',
        ...options,
    };
    showConfirmModal.value = true;
};

const handleConfirmedAction = () => {
    showConfirmModal.value = false;
    confirmOptions.value.action();
};

// Form Submissions & Prompts
const promptCreateUser = () => {
    addForm.clearErrors();
    let hasError = false;
    if (!addForm.first_name.trim()) {
        addForm.setError('first_name', 'First name is required.');
        hasError = true;
    }
    if (!addForm.last_name.trim()) {
        addForm.setError('last_name', 'Last name is required.');
        hasError = true;
    }
    if (!addForm.email.trim()) {
        addForm.setError('email', 'Email address is required.');
        hasError = true;
    }
    if (addForm.account_type === 'staff' && addForm.modules.length === 0) {
        addForm.setError('modules', 'Select at least one module for a staff account.');
        hasError = true;
    }
    if (hasError) return;

    const fn = addForm.first_name.trim();
    const initialPassword = 'First name + 1234 (e.g. Juan1234), must be changed at first login';
    const roleLabel = describeAssignment(addForm.account_type, addForm.modules);

    openConfirmModal({
        title: 'Confirm New User Account',
        description: `Are you sure you want to create a new user account for "${fn} ${addForm.last_name.trim()}"?`,
        details: [
            { label: 'Full Name', value: `${fn} ${addForm.last_name.trim()}` },
            { label: 'Email Address', value: addForm.email.trim() },
            { label: 'Assigned Role', value: roleLabel },
            { label: 'First-Login Password', value: initialPassword },
        ],
        confirmText: 'Create Account',
        confirmVariant: 'brand',
        action: submitAdd,
    });
};

const submitAdd = () => {
    addForm.post(`/${teamSlug.value}/users`, {
        preserveScroll: true,
        onSuccess: () => {
            showAddModal.value = false;
            addForm.reset();
        },
    });
};

const promptEditUser = () => {
    editForm.clearErrors();
    if (!editForm.name.trim()) {
        editForm.setError('name', 'Full name is required.');
        return;
    }
    if (editForm.account_type === 'staff' && editForm.modules.length === 0) {
        editForm.setError('modules', 'Select at least one module for a staff account.');
        return;
    }

    const roleLabel = describeAssignment(editForm.account_type, editForm.modules);

    openConfirmModal({
        title: 'Confirm Account Changes',
        description: `Are you sure you want to save modifications to "${editForm.name}"?`,
        details: [
            { label: 'Account Name', value: editForm.name },
            { label: 'Assigned Role', value: roleLabel },
            { label: 'Session Policy', value: 'Active sessions will be refreshed upon role update' },
        ],
        confirmText: 'Save Changes',
        confirmVariant: 'brand',
        action: submitEdit,
    });
};

const submitEdit = () => {
    if (!editForm.id) return;
    editForm.put(`/${teamSlug.value}/users/${editForm.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            showEditModal.value = false;
            editForm.reset();
        },
    });
};

const confirmStatusToggle = () => {
    if (!selectedUser.value) return;
    router.patch(`/${teamSlug.value}/users/${selectedUser.value.id}/status`, {}, {
        preserveScroll: true,
        onSuccess: () => {
            showStatusModal.value = false;
            selectedUser.value = null;
        },
    });
};

const confirmDelete = () => {
    if (!selectedUser.value || deleteConfirmInput.value !== 'YES') return;
    router.delete(`/${teamSlug.value}/users/${selectedUser.value.id}`, {
        data: { confirmation: deleteConfirmInput.value },
        preserveScroll: true,
        onSuccess: () => {
            showDeleteModal.value = false;
            selectedUser.value = null;
        },
    });
};


const promptEnrollDevice = () => {
    enrollForm.clearErrors();
    if (enrollForm.assignment_type === 'single' && !enrollForm.user_id) {
        enrollForm.setError('user_id', 'Please select an account holder.');
        return;
    }
    if (enrollForm.assignment_type === 'shared' && (!enrollForm.authorized_user_ids || !enrollForm.authorized_user_ids.length)) {
        enrollForm.setError('authorized_user_ids' as any, 'Please select at least one authorized account for this workstation.');
        return;
    }

    const targetUser = props.users?.find(u => u.id === Number(enrollForm.user_id));
    const deviceLabel = enrollForm.device_name.trim() || (enrollForm.assignment_type === 'shared' ? 'Shared Workstation' : `${targetUser?.name || 'Member'}'s Device`);

    const policyLabel = enrollForm.assignment_type === 'shared'
        ? `Shared Workstation (${enrollForm.authorized_user_ids.length} authorized accounts)`
        : `Single Account: ${targetUser?.name || 'Selected User'}`;

    const lifespanLabel = enrollForm.expires_in_minutes === 5
        ? '5 Minutes (Strict / Single-use)'
        : enrollForm.expires_in_minutes >= 60
            ? `${enrollForm.expires_in_minutes / 60} Hour(s)`
            : `${enrollForm.expires_in_minutes} Minutes`;

    openConfirmModal({
        title: 'Confirm Workstation Enrollment Invitation',
        description: `Generate a one-time enrollment link for "${deviceLabel}"?`,
        details: [
            { label: 'Workstation Policy', value: policyLabel },
            { label: 'Device Identifier', value: deviceLabel },
            { label: 'Invitation Lifespan', value: lifespanLabel },
            { label: 'Mode', value: enrollForm.direct_approve ? 'Immediate Direct Approval' : 'One-Time Enrollment Link' },
        ],
        confirmText: 'Generate Enrollment Code',
        confirmVariant: 'brand',
        action: submitEnroll,
    });
};

const submitEnroll = () => {
    enrollForm.post(`/${teamSlug.value}/users/devices/enroll`, {
        preserveScroll: true,
        onSuccess: () => {
            showEnrollModal.value = false;
            enrollForm.reset();
        },
    });
};

const openRenameDeviceModal = (device: TrustedDeviceItem) => {
    selectedDevice.value = device;
    renameDeviceForm.device_name = device.device_name;
    renameDeviceForm.clearErrors();
    showRenameDeviceModal.value = true;
};

const submitRenameDevice = () => {
    if (!selectedDevice.value) return;
    renameDeviceForm.patch(`/${teamSlug.value}/users/devices/${selectedDevice.value.id}/rename`, {
        preserveScroll: true,
        onSuccess: () => {
            showRenameDeviceModal.value = false;
            selectedDevice.value = null;
            renameDeviceForm.reset();
        },
    });
};


const confirmRevokeSession = () => {
    if (!selectedSession.value) return;
    router.delete(`/${teamSlug.value}/users/sessions/${selectedSession.value.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            showRevokeSessionModal.value = false;
            selectedSession.value = null;
        },
    });
};

const confirmRevokeUserSessions = () => {
    if (!selectedUser.value) return;
    router.post(`/${teamSlug.value}/users/${selectedUser.value.id}/sessions/revoke`, {}, {
        preserveScroll: true,
        onSuccess: () => {
            showRevokeUserSessionsModal.value = false;
            selectedUser.value = null;
        },
    });
};

const confirmRevokeDevice = () => {
    if (!selectedDevice.value || revokeDeviceConfirmInput.value !== 'YES') return;
    router.post(`/${teamSlug.value}/users/devices/${selectedDevice.value.id}/revoke`, {
        confirmation: revokeDeviceConfirmInput.value,
    }, {
        preserveScroll: true,
        onSuccess: () => {
            showRevokeDeviceModal.value = false;
            selectedDevice.value = null;
        },
    });
};

const promptApproveDevice = (device: TrustedDeviceItem) => {
    const accountDisplay = device.is_shared
        ? `Shared Workstation (${device.authorized_users?.length || 0} accounts)`
        : `${device.user?.name || 'Assigned User'} (${device.user?.email || '—'})`;

    openConfirmModal({
        title: 'Confirm Device Approval',
        description: `Authorize and approve access for "${device.device_name}"?`,
        details: [
            { label: 'Device Name', value: device.device_name },
            { label: 'Account Policy', value: accountDisplay },
            { label: 'IP Address', value: device.ip_address || 'Not Recorded' },
            { label: 'Hardware UUID', value: device.device_uuid },
        ],
        confirmText: 'Approve Device',
        confirmVariant: 'brand',
        action: () => approveDevice(device),
    });
};

const approveDevice = (device: TrustedDeviceItem) => {
    router.post(`/${teamSlug.value}/users/devices/${device.id}/approve`, {}, {
        preserveScroll: true,
    });
};

const promptDeleteDevice = (device: TrustedDeviceItem) => {
    openConfirmModal({
        title: 'Are you sure you want to permanently delete this record?',
        description: `This action cannot be undone. Related active sessions, registered trusted devices, or dependent records must only be revoked or removed when explicitly required by the operation. Removing "${device.device_name}" will permanently delete its workstation registration and terminate active sessions bound to this hardware.`,
        details: [
            { label: 'Device Name', value: device.device_name },
            { label: 'Account', value: device.user?.name || (device.is_shared ? 'Shared Workstation' : 'Unassigned') },
            { label: 'Hardware UUID', value: device.device_uuid },
        ],
        confirmText: 'Remove Device',
        confirmVariant: 'destructive',
        action: () => deleteDevice(device),
    });
};

const deleteDevice = (device: TrustedDeviceItem) => {
    router.delete(`/${teamSlug.value}/users/devices/${device.id}`, {
        data: { confirmation: 'YES' },
        preserveScroll: true,
    });
};

const promptRegenerateRecoveryKey = () => {
    openConfirmModal({
        title: 'Regenerate Emergency Admin Recovery Key',
        description: 'Are you sure you want to generate a new emergency admin recovery key? Your current key will become invalid immediately.',
        details: [
            { label: 'Security Warning', value: 'Prior recovery keys cannot be used after regeneration' },
        ],
        confirmText: 'Regenerate Key',
        confirmVariant: 'brand',
        action: regenerateRecoveryKey,
    });
};

const regenerateRecoveryKey = () => {
    router.post(`/${teamSlug.value}/users/recovery-key/regenerate`, {}, {
        preserveScroll: true,
    });
};
</script>

<template>
    <Head :title="activeTab === 'security' ? 'Security Logs | System Maintenance' : activeTab === 'audit' ? 'System Audit Trail | System Maintenance' : 'User Management & Security Administration'" />

    <component :is="isMaintenanceLogView ? SettingsLayout : 'div'">
        <div :class="isMaintenanceLogView ? 'flex min-w-0 flex-col gap-6' : 'workspace-page'">
            <!-- Header for System Maintenance Log Views -->
            <div v-if="activeTab === 'audit'" class="pb-4 border-b border-gray-200">
                <h1 class="section-title">System Audit Trail</h1>
                <p class="section-subtitle">Chronological administrative and operational audit events across the system.</p>
            </div>
            <div v-else-if="activeTab === 'security'" class="pb-4 border-b border-gray-200">
                <h1 class="section-title">Security Logs</h1>
                <p class="section-subtitle">Authentication, device verification, and access-control security events.</p>
            </div>

            <!-- Header for User Management -->
            <div v-else class="page-header">
                <div>
                    <h1 class="page-title">User Management</h1>
                    <p class="page-subtitle">Accounts, active sessions, and trusted devices.</p>
                </div>

                <!-- Top Actions -->
                <div class="page-actions">
                    <SpecularButton
                        v-if="activeTab === 'accounts'"
                        variant="brand"
                        size="md"
                        :radius="12"
                        @click="openAddModal"
                    >
                        <Plus class="w-4 h-4" />
                        Add Account
                    </SpecularButton>
                </div>
            </div>

        <!-- Banner / Notices -->
        <div
            v-if="temporaryPasswordNotice"
            class="mb-4 rounded-xl border border-amber-300 bg-amber-50/90 p-4 flex items-start gap-3"
        >
            <div class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-[#4b5320] text-[#F8B803]">
                <Shield class="size-4" />
            </div>
            <div>
                <h4 class="text-xs font-semibold text-gray-900">Temporary Credential Generated</h4>
                <p class="text-sm font-semibold text-gray-800 mt-0.5">{{ temporaryPasswordNotice }}</p>
                <p class="text-xs text-gray-500 mt-1">Please securely convey this credential to the user. They must change it upon their initial login.</p>
            </div>
        </div>

        <!-- Combined Single-Use Device Enrollment Notice Banner (Top Level) -->
        <div
            v-if="enrollmentNotice && !dismissedEnrollmentNotice"
            class="mb-5 rounded-xl border border-emerald-300 bg-emerald-50/95 p-4 sm:p-5 transition-colors animate-in fade-in duration-200"
        >
            <div class="flex items-start justify-between gap-3">
                <div class="flex items-start gap-3.5 flex-1 min-w-0">
                    <div class="flex-1 min-w-0">
                        <h4 class="text-sm font-semibold text-emerald-950">Workstation enrollment invitation</h4>
                        <p class="text-sm text-emerald-900 mt-1 leading-relaxed">
                            Send this invitation to <strong class="text-emerald-950 font-semibold">{{ enrollmentNotice.user_name }}</strong>
                            (<span class="text-emerald-800">{{ enrollmentNotice.user_email }}</span>).
                            Must be opened on their authorized device before <strong class="text-emerald-950">{{ enrollmentNotice.expires_at }}</strong>.
                        </p>

                        <!-- Controls Row: Code + Link -->
                        <div class="mt-3.5 flex flex-wrap items-center gap-3">
                            <!-- Code section -->
                            <div v-if="enrollmentNotice.code" class="flex items-center gap-1.5 bg-white border border-emerald-300 rounded-xl p-1">
                                <span class="text-[11px] font-semibold text-emerald-800 px-2">Code</span>
                                <code class="px-2.5 py-1 bg-emerald-50 text-emerald-950 rounded-lg text-sm font-mono font-semibold tracking-wider select-all border border-emerald-200">
                                    {{ enrollmentNotice.code }}
                                </code>
                                <button
                                    type="button"
                                    @click="copyToClipboard(enrollmentNotice.code, 'code')"
                                    class="inline-flex items-center gap-1 px-2.5 py-1 text-xs font-semibold text-emerald-800 hover:text-emerald-950 hover:bg-emerald-100/70 rounded-lg transition-colors cursor-pointer"
                                    title="Copy Code"
                                >
                                    <Check v-if="copiedCode" class="size-3.5 text-emerald-600" />
                                    <Copy v-else class="size-3.5 text-emerald-700" />
                                    <span>{{ copiedCode ? 'Copied!' : 'Copy Code' }}</span>
                                </button>
                            </div>

                            <!-- Link section -->
                            <div v-if="enrollmentNotice.link" class="flex items-center gap-1.5 bg-white border border-emerald-300 rounded-xl p-1 flex-1 min-w-[280px] max-w-xl">
                                <input
                                    type="text"
                                    readonly
                                    :value="enrollmentNotice.link"
                                    class="flex-1 bg-transparent border-0 px-2.5 py-1 text-xs text-gray-700 font-mono select-all focus:outline-none"
                                />
                                <button
                                    type="button"
                                    @click="copyToClipboard(enrollmentNotice.link, 'link')"
                                    class="inline-flex items-center gap-1 px-3 py-1.5 bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-semibold rounded-lg transition-colors shrink-0 cursor-pointer"
                                >
                                    <Check v-if="copiedLink" class="size-3.5 text-white" />
                                    <Copy v-else class="size-3.5 text-white" />
                                    <span>{{ copiedLink ? 'Copied!' : 'Copy Link' }}</span>
                                </button>
                            </div>
                        </div>

                        <p class="text-xs text-emerald-800 mt-2">Single use, for {{ enrollmentNotice.device_name || 'this workstation' }} only.</p>
                    </div>
                </div>

                <!-- Dismiss button -->
                <button
                    type="button"
                    @click="dismissedEnrollmentNotice = true"
                    class="p-1 text-emerald-700 hover:text-emerald-950 hover:bg-emerald-200/60 rounded-lg transition-colors cursor-pointer"
                    title="Dismiss Banner"
                >
                    <X class="size-4" />
                </button>
            </div>
        </div>

        <!-- Navigation Tabs (User Management only) -->
        <div v-if="!isMaintenanceLogView" class="border-b border-gray-200 mb-5">
            <nav class="flex space-x-2 -mb-px overflow-x-auto">
                <button
                    @click="switchTab('accounts')"
                    :class="[
                        activeTab === 'accounts'
                            ? 'border-gray-900 text-gray-900 font-medium'
                            : 'border-transparent text-gray-500 hover:text-gray-900 font-medium',
                        'whitespace-nowrap py-2.5 px-3 border-b-2 text-sm flex items-center gap-2 transition-colors'
                    ]"
                >
                    <Users class="w-4 h-4" />
                    User Accounts
                </button>

                <button
                    @click="switchTab('sessions')"
                    :class="[
                        activeTab === 'sessions'
                            ? 'border-gray-900 text-gray-900 font-medium'
                            : 'border-transparent text-gray-500 hover:text-gray-900 font-medium',
                        'whitespace-nowrap py-2.5 px-3 border-b-2 text-sm flex items-center gap-2 transition-colors'
                    ]"
                >
                    <Activity class="w-4 h-4" />
                    Active Sessions
                </button>

                <button
                    @click="switchTab('devices')"
                    :class="[
                        activeTab === 'devices'
                            ? 'border-gray-900 text-gray-900 font-medium'
                            : 'border-transparent text-gray-500 hover:text-gray-900 font-medium',
                        'whitespace-nowrap py-2.5 px-3 border-b-2 text-sm flex items-center gap-2 transition-colors'
                    ]"
                >
                    <Laptop class="w-4 h-4" />
                    Trusted Devices
                </button>
            </nav>
        </div>

        <!-- ========================================== -->
        <!-- TAB 1: USER ACCOUNTS                       -->
        <!-- ========================================== -->
        <div v-if="activeTab === 'accounts'">
            <!-- Filters -->
            <div class="mb-6 flex flex-col sm:flex-row gap-3 rounded-xl border border-gray-200 bg-white p-4">
                <div class="relative flex-1 sm:max-w-md">
                    <Search class="w-4 h-4 absolute left-3.5 top-1/2 -translate-y-1/2 text-gray-400" />
                    <input
                        v-model="searchQuery"
                        @input="applyFilter"
                        type="text"
                        placeholder="Search users by name or email..."
                        class="field-control w-full pl-10 pr-4"
                    />
                </div>
                <select
                    v-model="selectedRole"
                    @change="applyFilter"
                    class="field-control min-w-[150px] sm:w-auto"
                >
                    <option value="All Roles">All Roles ({{ roleCounts?.['All Roles'] ?? 0 }})</option>
                    <option v-for="r in roleFilterOptions" :key="r.value" :value="r.value">
                        {{ r.label }} ({{ roleCounts?.[r.value] ?? 0 }})
                    </option>
                </select>
                <select
                    v-model="selectedStatus"
                    @change="applyFilter"
                    class="field-control min-w-[140px] sm:w-auto"
                >
                    <option value="All Statuses">All Statuses</option>
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                </select>
            </div>

            <!-- Table -->
            <div class="rounded-xl bg-white border border-gray-200 p-6">
                <div class="overflow-x-auto">
                    <table class="w-full text-left border-collapse">
                        <thead>
                            <tr class="border-b border-gray-200 text-gray-500 text-xs font-semibold">
                                <th class="pb-3 font-semibold">Account / User</th>
                                <th class="pb-3 font-semibold">Email</th>
                                <th class="pb-3 font-semibold">Role</th>
                                <th class="pb-3 font-semibold">Status</th>
                                <th class="pb-3 font-semibold">Last Login</th>
                                <th class="pb-3 font-semibold text-center">Devices</th>
                                <th class="pb-3 font-semibold text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="text-sm divide-y divide-gray-100">
                            <tr v-if="!accounts?.data?.length">
                                <td colspan="7" class="py-10 text-center text-gray-500">
                                    No accounts found matching your query.
                                </td>
                            </tr>
                            <tr v-for="user in accounts?.data ?? []" :key="user.id" class="hover:bg-gray-50/70 transition-colors">
                                <td class="py-4 text-gray-900 font-semibold">
                                    <div>
                                        <div class="flex items-center gap-1.5">
                                            <span>{{ user.name }}</span>
                                            <span v-if="user.id === currentAuthUserId" class="text-[10px] bg-gray-100 text-gray-700 font-medium px-2 py-0.5 rounded-full">You</span>
                                        </div>
                                        <div v-if="user.must_change_password" class="text-[11px] text-amber-600 font-medium flex items-center gap-1 mt-0.5">
                                            <AlertTriangle class="size-3" /> Password change required
                                        </div>
                                    </div>
                                </td>
                                <td class="py-4 text-gray-600">
                                    <div class="flex items-center gap-1.5">
                                        <Mail class="size-3.5 text-gray-400" />
                                        {{ user.email }}
                                    </div>
                                </td>
                                <td class="py-4">
                                    <span
                                        :class="[
                                            'px-2.5 py-1 rounded-full text-xs font-semibold border inline-flex items-center gap-1',
                                            user.is_superadmin ? 'bg-purple-50 text-purple-700 border-purple-200' :
                                            user.raw_role === 'admin' ? 'bg-[#F8B803]/15 text-[#92400e] border-[#F8B803]/30' :
                                            user.raw_role === 'staff' ? 'bg-blue-50 text-blue-700 border-blue-200' :
                                            'bg-gray-100 text-gray-600 border-gray-200'
                                        ]"
                                    >
                                        <Shield class="size-3" />
                                        {{ user.role }}
                                    </span>
                                </td>
                                <td class="py-4">
                                    <StatusBadge :status="user.status" size="sm" />
                                </td>
                                <td class="py-4 text-gray-500 text-xs">
                                    <div class="flex items-center gap-1">
                                        <Clock class="size-3 text-gray-400" />
                                        {{ user.last_login_at }}
                                    </div>
                                </td>
                                <td class="py-4 text-center">
                                    <span class="inline-flex items-center gap-1 text-xs font-medium bg-gray-100 text-gray-700 px-2 py-0.5 rounded-md">
                                        <Laptop class="size-3" />
                                        {{ user.trusted_devices_count }}
                                    </span>
                                </td>
                                <td class="py-4 text-right">
                                    <div class="inline-flex items-center gap-2">
                                        <!-- Superadmin Enroll Device shortcut -->
                                        <button
                                            v-if="isSuperAdmin && (user.raw_role === 'admin' || user.raw_role.startsWith('staff'))"
                                            @click="openEnrollModal(user)"
                                            title="Send Device Enrollment Invitation"
                                            class="p-1.5 text-gray-500 hover:text-emerald-700 hover:bg-emerald-50 rounded-lg transition-colors"
                                        >
                                            <Laptop class="size-4" />
                                        </button>

                                        <!-- Superadmin Revoke Sessions shortcut -->
                                        <button
                                            v-if="isSuperAdmin && user.id !== currentAuthUserId"
                                            @click="openRevokeUserSessionsModal(user)"
                                            title="Revoke All Sessions"
                                            class="p-1.5 text-gray-500 hover:text-amber-700 hover:bg-amber-50 rounded-lg transition-colors"
                                        >
                                            <Power class="size-4" />
                                        </button>

                                        <!-- Edit Role -->
                                        <button
                                            v-if="!user.is_superadmin || isSuperAdmin"
                                            @click="openEditModal(user)"
                                            class="text-xs font-semibold text-gray-700 hover:text-gray-900 px-2.5 py-1 border border-gray-200 rounded-lg hover:bg-gray-50 transition-colors"
                                        >
                                            Edit
                                        </button>

                                        <!-- Toggle Status (Activate / Deactivate) -->
                                        <button
                                            v-if="isSuperAdmin && user.id !== currentAuthUserId"
                                            @click="openStatusModal(user)"
                                            :class="[
                                                'text-xs font-semibold px-2.5 py-1 border rounded-lg transition-colors',
                                                user.is_active ? 'border-amber-200 text-amber-700 hover:bg-amber-50' : 'border-emerald-200 text-emerald-700 hover:bg-emerald-50'
                                            ]"
                                        >
                                            {{ user.is_active ? 'Deactivate' : 'Activate' }}
                                        </button>

                                        <!-- Remove Account -->
                                        <button
                                            v-if="user.id !== currentAuthUserId && (!user.is_superadmin || isSuperAdmin)"
                                            @click="openDeleteModal(user)"
                                            class="p-1.5 text-gray-400 hover:text-red-600 hover:bg-red-50 rounded-lg transition-colors"
                                            title="Delete Account"
                                        >
                                            <Trash2 class="size-4" />
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Count & Pagination (follows the search, role and status filters) -->
                <div
                    v-if="accounts && accounts.total > 0"
                    class="mt-4 pt-4 border-t border-gray-100 flex flex-col sm:flex-row items-center justify-between gap-3 text-xs"
                >
                    <div class="text-gray-500">
                        Showing <strong class="text-gray-800">{{ accounts.from || 0 }}</strong> to <strong class="text-gray-800">{{ accounts.to || 0 }}</strong> of <strong class="text-gray-800">{{ accounts.total }}</strong> account{{ accounts.total === 1 ? '' : 's' }}
                    </div>

                    <div v-if="accounts.last_page > 1" class="flex flex-wrap items-center gap-1">
                        <button
                            v-for="(link, i) in accounts.links"
                            :key="i"
                            type="button"
                            :disabled="!link.url || link.active"
                            @click="link.url && router.visit(link.url, { preserveScroll: true, preserveState: true })"
                            v-html="link.label"
                            class="px-2.5 py-1 rounded-lg border text-xs font-semibold transition"
                            :class="[
                                link.active
                                    ? 'bg-[#4b5320] text-white border-[#4b5320]'
                                    : link.url
                                    ? 'bg-white text-gray-700 border-gray-200 hover:bg-gray-100'
                                    : 'bg-transparent text-gray-300 border-transparent cursor-not-allowed'
                            ]"
                        ></button>
                    </div>
                </div>
            </div>
        </div>

        <!-- ========================================== -->
        <!-- TAB 2: ACTIVE SESSIONS (Superadmin only)   -->
        <!-- ========================================== -->
        <div v-else-if="activeTab === 'sessions' && isSuperAdmin">
            <!-- Filter -->
            <div class="mb-6 flex flex-col sm:flex-row justify-between items-start sm:items-center gap-3 rounded-xl border border-gray-200 bg-white p-4">
                <div class="relative flex-1 sm:max-w-md">
                    <Search class="w-4 h-4 absolute left-3.5 top-1/2 -translate-y-1/2 text-gray-400" />
                    <input
                        v-model="searchQuery"
                        @input="applyFilter"
                        type="text"
                        placeholder="Filter sessions by user or IP address..."
                        class="field-control w-full pl-10 pr-4"
                    />
                </div>
                <div class="text-xs text-gray-500">
                    Sessions are rotated automatically and expire after 120 minutes of inactivity.
                </div>
            </div>

            <div class="rounded-xl bg-white border border-gray-200 p-6">
                <div class="overflow-x-auto">
                    <table class="w-full text-left border-collapse">
                        <thead>
                            <tr class="border-b border-gray-200 text-gray-500 text-xs font-semibold">
                                <th class="pb-3 font-semibold">Account / User</th>
                                <th class="pb-3 font-semibold">Role</th>
                                <th class="pb-3 font-semibold">Device / Browser</th>
                                <th class="pb-3 font-semibold">IP Address</th>
                                <th class="pb-3 font-semibold">Last Activity</th>
                                <th class="pb-3 font-semibold">Status</th>
                                <th class="pb-3 font-semibold text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="text-sm divide-y divide-gray-100">
                            <tr v-if="!sessions?.length">
                                <td colspan="7" class="py-10 text-center text-gray-500">
                                    No active sessions recorded.
                                </td>
                            </tr>
                            <tr v-for="s in sessions" :key="s.id" class="hover:bg-gray-50/70 transition-colors">
                                <td class="py-4">
                                    <div class="font-semibold text-gray-900 flex items-center gap-2">
                                        <span>{{ s.user_name }}</span>
                                        <span v-if="s.is_current" class="text-[10px] bg-emerald-100 text-emerald-800 font-semibold px-2 py-0.5 rounded-full border border-emerald-200">
                                            Current Session
                                        </span>
                                    </div>
                                    <div class="text-xs text-gray-500">{{ s.user_email }}</div>
                                </td>
                                <td class="py-4">
                                    <span class="text-xs font-semibold px-2 py-0.5 rounded-md bg-gray-100 text-gray-700">
                                        {{ s.user_role }}
                                    </span>
                                </td>
                                <td class="py-4">
                                    <div class="text-sm font-medium text-gray-800">{{ s.browser }}</div>
                                    <div class="text-xs text-gray-500">{{ s.os }}</div>
                                </td>
                                <td class="py-4 font-mono text-xs text-gray-600">
                                    {{ s.ip_address }}
                                </td>
                                <td class="py-4 text-xs text-gray-600" :title="s.last_activity_exact">
                                    <div class="flex items-center gap-1">
                                        <Clock class="size-3 text-gray-400" />
                                        {{ s.last_activity }}
                                    </div>
                                </td>
                                <td class="py-4">
                                    <span :class="s.status === 'Active' ? 'bg-green-50 text-green-700 border-green-200' : 'bg-gray-100 text-gray-500 border-gray-200'" class="px-2 py-0.5 rounded-full text-xs font-semibold border">
                                        {{ s.status }}
                                    </span>
                                </td>
                                <td class="py-4 text-right">
                                    <button
                                        v-if="!s.is_current"
                                        @click="openRevokeSessionModal(s)"
                                        class="text-xs font-semibold text-red-600 hover:text-red-800 px-3 py-1 bg-red-50 hover:bg-red-100 border border-red-200 rounded-lg transition-colors"
                                    >
                                        Revoke Session
                                    </button>
                                    <span v-else class="text-xs text-gray-400 italic">This Device</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- ========================================== -->
        <!-- TAB 3: TRUSTED DEVICES (1 Account, 1 Device Policy) -->
        <!-- ========================================== -->
        <div v-else-if="activeTab === 'devices'">
            <!-- Policy Banner: 1 Account, 1 Device -->
            <div class="mb-6 rounded-xl border border-blue-200 bg-blue-50/80 p-4 flex items-center justify-between gap-4">
                <div class="flex items-center gap-3">
                    <div class="flex size-9 shrink-0 items-center justify-center rounded-xl bg-blue-600 text-white">
                        <ShieldCheck class="size-5" />
                    </div>
                    <div>
                        <h4 class="text-xs font-semibold text-blue-950">1 Account, 1 Device Policy Enforced</h4>
                        <p class="text-xs text-blue-800 mt-0.5">
                            Each administrator and staff account is strictly limited to <strong>1 authorized device</strong>. Enrolling or approving a new device automatically replaces and revokes any prior device.
                        </p>
                    </div>
                </div>
                <span class="bg-blue-100 text-blue-800 text-[11px] font-semibold px-2.5 py-1 rounded-full border border-blue-300 shrink-0">
                    Active Policy
                </span>
            </div>

            <!-- Top Card: Emergency Recovery Key -->
            <div class="mb-6 rounded-xl border border-gray-200 bg-white p-6 flex flex-col md:flex-row justify-between items-start md:items-center gap-4">
                <div>
                    <h3 class="text-sm font-semibold text-gray-900 flex items-center gap-2">
                        Emergency Admin Device Recovery Key
                    </h3>
                    <p class="text-xs text-gray-500 mt-0.5">
                        If an administrator is locked out or loses access to their trusted device, this key allows emergency verification bypass.
                    </p>
                </div>
                <div class="flex items-center gap-2">
                    <code class="px-3 py-1.5 bg-gray-50 border border-gray-200 rounded-lg text-xs font-mono font-semibold text-gray-800">
                        {{ adminRecoveryKey || 'RECOVER-XXXX-XXXX-XXXX' }}
                    </code>
                    <button
                        v-if="adminRecoveryKey"
                        @click="copyToClipboard(adminRecoveryKey)"
                        class="p-2 text-gray-500 hover:text-gray-900 hover:bg-gray-100 rounded-lg transition-colors"
                        title="Copy Key"
                    >
                        <Copy class="size-4" />
                    </button>
                    <button
                        @click="promptRegenerateRecoveryKey"
                        class="inline-flex items-center gap-1 px-3 py-1.5 text-xs font-semibold text-gray-700 bg-gray-100 hover:bg-gray-200 rounded-lg transition-colors"
                    >
                        <RefreshCw class="size-3.5" />
                        Regenerate
                    </button>
                </div>
            </div>



            <!-- Filters & Actions -->
            <div class="mb-6 flex flex-col sm:flex-row justify-between items-start sm:items-center gap-3 rounded-xl border border-gray-200 bg-white p-4">
                <div class="relative flex-1 sm:max-w-md">
                    <Search class="w-4 h-4 absolute left-3.5 top-1/2 -translate-y-1/2 text-gray-400" />
                    <input
                        v-model="searchQuery"
                        @input="applyFilter"
                        type="text"
                        placeholder="Search devices by name, user, or IP..."
                        class="field-control w-full pl-10 pr-4"
                    />
                </div>
                <SpecularButton
                    variant="brand"
                    size="sm"
                    :radius="8"
                    @click="openEnrollModal()"
                >
                    <Plus class="w-4 h-4 mr-1 inline" />
                    Enroll New Device
                </SpecularButton>
            </div>

            <!-- Devices Table -->
            <div class="rounded-xl bg-white border border-gray-200 p-6">
                <div class="overflow-x-auto">
                    <table class="w-full text-left border-collapse">
                        <thead>
                            <tr class="border-b border-gray-200 text-gray-500 text-xs font-semibold">
                                <th class="pb-3 font-semibold">Device Label</th>
                                <th class="pb-3 font-semibold">Authorized Account(s)</th>
                                <th class="pb-3 font-semibold">IP Address</th>
                                <th class="pb-3 font-semibold">Status</th>
                                <th class="pb-3 font-semibold">Registered</th>
                                <th class="pb-3 font-semibold">Last Used</th>
                                <th class="pb-3 font-semibold text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="text-sm divide-y divide-gray-100">
                            <tr v-if="!devices?.length">
                                <td colspan="7" class="py-10 text-center text-gray-500">
                                    No registered or enrolled devices found.
                                </td>
                            </tr>
                            <tr v-for="d in devices" :key="d.id" class="hover:bg-gray-50/70 transition-colors">
                                <td class="py-4 font-semibold text-gray-900">
                                    <div class="flex items-center gap-2">
                                        <Laptop class="size-4 text-gray-500" />
                                        <span>{{ d.device_name }}</span>
                                        <span v-if="d.is_current" class="text-[10px] bg-emerald-100 text-emerald-800 font-semibold px-2 py-0.5 rounded-full border border-emerald-200">
                                            Current Device
                                        </span>
                                    </div>
                                    <div class="text-[11px] font-mono text-gray-400 mt-0.5">{{ d.device_uuid }}</div>
                                </td>
                                <td class="py-4">
                                    <div v-if="d.is_shared">
                                        <span class="inline-flex items-center gap-1 px-2.5 py-0.5 rounded-full text-xs font-semibold bg-purple-100 text-purple-800 border border-purple-200">
                                            <Users class="size-3" /> Shared Workstation
                                        </span>
                                        <div class="text-xs text-gray-600 mt-1 font-medium">
                                            {{ d.authorized_users?.length || 0 }} authorized accounts
                                        </div>
                                        <div v-if="d.authorized_users?.length" class="text-[11px] text-gray-400 mt-0.5 truncate max-w-xs">
                                            {{ d.authorized_users.map(u => u.name).join(', ') }}
                                        </div>
                                    </div>
                                    <div v-else-if="d.user">
                                        <div class="font-medium text-gray-900">{{ d.user.name }}</div>
                                        <div class="text-xs text-gray-500">{{ d.user.email }} ({{ d.user.role }})</div>
                                    </div>
                                    <div v-else class="text-xs text-gray-400 italic">
                                        Unassigned Workstation
                                    </div>
                                </td>
                                <td class="py-4 font-mono text-xs text-gray-600">
                                    {{ d.ip_address || '—' }}
                                </td>
                                <td class="py-4">
                                    <span
                                        :class="[
                                            'px-2.5 py-1 rounded-full text-xs font-semibold border inline-flex items-center gap-1',
                                            d.raw_status === 'approved' ? 'bg-green-50 text-green-700 border-green-200' :
                                            d.raw_status === 'pending' ? 'bg-amber-50 text-amber-700 border-amber-200' :
                                            'bg-red-50 text-red-700 border-red-200'
                                        ]"
                                    >
                                        {{ d.status }}
                                    </span>
                                    <div v-if="d.enrollment_code && !d.is_enrollment_expired" class="mt-1 flex items-center gap-1.5">
                                        <code class="px-1.5 py-0.5 bg-gray-100 border border-gray-200 rounded text-[11px] font-mono font-semibold text-gray-800">
                                            {{ d.enrollment_code }}
                                        </code>
                                        <button
                                            @click="copyToClipboard(d.enrollment_code)"
                                            class="text-[10px] text-emerald-700 hover:underline inline-flex items-center gap-0.5 font-semibold cursor-pointer"
                                            title="Copy Code"
                                        >
                                            <Copy class="size-3" /> Copy
                                        </button>
                                    </div>
                                    <div v-if="d.enrollment_link && !d.is_enrollment_expired" class="mt-0.5">
                                        <button
                                            @click="copyToClipboard(d.enrollment_link)"
                                            class="text-[10px] text-emerald-700 hover:underline inline-flex items-center gap-1 font-semibold cursor-pointer"
                                        >
                                            <ExternalLink class="size-3" /> Link
                                        </button>
                                    </div>
                                </td>
                                <td class="py-4 text-xs text-gray-500">{{ d.created_at }}</td>
                                <td class="py-4 text-xs text-gray-500">{{ d.last_used_at }}</td>
                                <td class="py-4 text-right">
                                    <div class="inline-flex items-center gap-2">
                                        <!-- Rename device -->
                                        <button
                                            @click="openRenameDeviceModal(d)"
                                            class="p-1.5 text-gray-400 hover:text-gray-900 hover:bg-gray-100 rounded-lg transition-colors cursor-pointer"
                                            title="Rename Device"
                                        >
                                            <Pencil class="size-4" />
                                        </button>

                                        <!-- Approve pending -->
                                        <button
                                            v-if="d.raw_status === 'pending'"
                                            @click="promptApproveDevice(d)"
                                            class="text-xs font-semibold text-emerald-700 hover:bg-emerald-50 px-2.5 py-1 border border-emerald-200 rounded-lg transition-colors cursor-pointer"
                                        >
                                            Approve
                                        </button>

                                        <!-- Revoke device -->
                                        <button
                                            v-if="d.raw_status !== 'revoked'"
                                            @click="openRevokeDeviceModal(d)"
                                            class="text-xs font-semibold text-red-600 hover:bg-red-50 px-2.5 py-1 border border-red-200 rounded-lg transition-colors cursor-pointer"
                                        >
                                            Revoke
                                        </button>

                                        <!-- Remove trusted device -->
                                        <button
                                            type="button"
                                            @click="promptDeleteDevice(d)"
                                            class="inline-flex size-8 items-center justify-center rounded-xl text-gray-400 hover:text-red-600 hover:bg-red-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-red-500/30 transition-colors cursor-pointer"
                                            title="Remove trusted device"
                                            aria-label="Remove trusted device"
                                        >
                                            <Trash2 class="size-4" />
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- ========================================== -->
        <!-- TAB 4: AUDIT TRAIL (Admin accessible)      -->
        <!-- ========================================== -->
        <div v-else-if="activeTab === 'audit'">
            <!-- Filter Bar -->
            <div class="mb-6 flex flex-col sm:flex-row gap-3 rounded-xl border border-gray-200 bg-white p-4">
                <div class="relative flex-1">
                    <Search class="w-4 h-4 absolute left-3.5 top-1/2 -translate-y-1/2 text-gray-400" />
                    <input
                        v-model="searchQuery"
                        @input="applyFilter"
                        type="text"
                        placeholder="Search audit trail by description, admin, or target user..."
                        class="field-control w-full pl-10 pr-4"
                    />
                </div>
                <input
                    v-model="dateFrom"
                    @change="applyFilter"
                    type="date"
                    class="bg-white border border-gray-200 rounded-xl px-3 py-2 text-xs text-gray-700"
                    title="From Date"
                />
                <input
                    v-model="dateTo"
                    @change="applyFilter"
                    type="date"
                    class="bg-white border border-gray-200 rounded-xl px-3 py-2 text-xs text-gray-700"
                    title="To Date"
                />
            </div>

            <!-- Table -->
            <div class="rounded-xl bg-white border border-gray-200 p-6">
                <div class="overflow-x-auto">
                    <table class="w-full text-left border-collapse">
                        <thead>
                            <tr class="border-b border-gray-200 text-gray-500 text-xs font-semibold">
                                <th class="pb-3 font-semibold">Timestamp</th>
                                <th class="pb-3 font-semibold">Acting Account</th>
                                <th class="pb-3 font-semibold">Action / Event</th>
                                <th class="pb-3 font-semibold">Affected Resource</th>
                                <th class="pb-3 font-semibold">Outcome</th>
                                <th class="pb-3 font-semibold">Details</th>
                            </tr>
                        </thead>
                        <tbody class="text-sm divide-y divide-gray-100">
                            <tr v-if="!auditLogs?.data?.length">
                                <td colspan="6" class="py-10 text-center text-gray-500">
                                    No administrative audit trail records found.
                                </td>
                            </tr>
                            <tr v-for="log in auditLogs?.data" :key="log.id" class="hover:bg-gray-50/70 transition-colors">
                                <td class="py-4 text-xs font-mono text-gray-500 whitespace-nowrap">
                                    {{ new Date(log.created_at).toLocaleString() }}
                                </td>
                                <td class="py-4 font-semibold text-gray-900 text-xs">
                                    {{ log.user_email || 'System' }}
                                </td>
                                <td class="py-4">
                                    <span class="font-mono text-xs font-semibold px-2 py-0.5 bg-gray-100 text-gray-800 rounded-md">
                                        {{ log.action_type || log.event }}
                                    </span>
                                </td>
                                <td class="py-4 text-xs text-gray-600">
                                    {{ log.target_user_email || log.description }}
                                </td>
                                <td class="py-4">
                                    <span
                                        :class="[
                                            'px-2 py-0.5 rounded-full text-xs font-semibold border',
                                            log.outcome === 'success' ? 'bg-green-50 text-green-700 border-green-200' : 'bg-red-50 text-red-700 border-red-200'
                                        ]"
                                    >
                                        {{ log.outcome }}
                                    </span>
                                </td>
                                <td class="py-4 text-xs">
                                    <button
                                        v-if="log.metadata"
                                        @click="openMetadataModal(log)"
                                        class="text-blue-600 hover:underline font-semibold"
                                    >
                                        View Data
                                    </button>
                                    <span v-else class="text-gray-400">—</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <div v-if="auditLogs && auditLogs.last_page > 1" class="flex justify-between items-center mt-5 pt-4 border-t border-gray-100">
                    <span class="text-xs text-gray-500">Page {{ auditLogs.current_page }} of {{ auditLogs.last_page }}</span>
                    <div class="flex gap-2">
                        <Link
                            v-if="auditLogs.prev_page_url"
                            :href="auditLogs.prev_page_url"
                            class="px-3 py-1 bg-white border border-gray-200 rounded-lg text-xs font-semibold text-gray-700 hover:bg-gray-50"
                        >
                            Previous
                        </Link>
                        <Link
                            v-if="auditLogs.next_page_url"
                            :href="auditLogs.next_page_url"
                            class="px-3 py-1 bg-white border border-gray-200 rounded-lg text-xs font-semibold text-gray-700 hover:bg-gray-50"
                        >
                            Next
                        </Link>
                    </div>
                </div>
            </div>
        </div>

        <!-- ========================================== -->
        <!-- TAB 5: SECURITY LOGS (Admin accessible)    -->
        <!-- ========================================== -->
        <div v-else-if="activeTab === 'security'">
            <!-- Filter Bar -->
            <div class="mb-6 flex flex-col sm:flex-row gap-3 rounded-xl border border-gray-200 bg-white p-4">
                <div class="relative flex-1">
                    <Search class="w-4 h-4 absolute left-3.5 top-1/2 -translate-y-1/2 text-gray-400" />
                    <input
                        v-model="searchQuery"
                        @input="applyFilter"
                        type="text"
                        placeholder="Search security events, IP address, or account..."
                        class="field-control w-full pl-10 pr-4"
                    />
                </div>
                <select
                    v-model="selectedSeverity"
                    @change="applyFilter"
                    class="field-control min-w-[140px] sm:w-auto"
                >
                    <option value="All Severities">All Severities</option>
                    <option value="info">Info</option>
                    <option value="warning">Warning</option>
                    <option value="danger">Danger</option>
                </select>
                <input
                    v-model="dateFrom"
                    @change="applyFilter"
                    type="date"
                    class="bg-white border border-gray-200 rounded-xl px-3 py-2 text-xs text-gray-700"
                    title="From Date"
                />
                <input
                    v-model="dateTo"
                    @change="applyFilter"
                    type="date"
                    class="bg-white border border-gray-200 rounded-xl px-3 py-2 text-xs text-gray-700"
                    title="To Date"
                />
            </div>

            <!-- Table -->
            <div class="rounded-xl bg-white border border-gray-200 p-6">
                <div class="overflow-x-auto">
                    <table class="w-full text-left border-collapse">
                        <thead>
                            <tr class="border-b border-gray-200 text-gray-500 text-xs font-semibold">
                                <th class="pb-3 font-semibold">Timestamp</th>
                                <th class="pb-3 font-semibold">Severity</th>
                                <th class="pb-3 font-semibold">Event</th>
                                <th class="pb-3 font-semibold">Account</th>
                                <th class="pb-3 font-semibold">IP Address</th>
                                <th class="pb-3 font-semibold">Description</th>
                                <th class="pb-3 font-semibold">Outcome</th>
                            </tr>
                        </thead>
                        <tbody class="text-sm divide-y divide-gray-100">
                            <tr v-if="!securityLogs?.data?.length">
                                <td colspan="7" class="py-10 text-center text-gray-500">
                                    No security incidents or access-control events recorded.
                                </td>
                            </tr>
                            <tr v-for="log in securityLogs?.data" :key="log.id" class="hover:bg-gray-50/70 transition-colors">
                                <td class="py-4 text-xs font-mono text-gray-500 whitespace-nowrap">
                                    {{ new Date(log.created_at).toLocaleString() }}
                                </td>
                                <td class="py-4">
                                    <span
                                        :class="[
                                            'px-2 py-0.5 rounded-full text-[11px] font-semibold border',
                                            log.severity === 'danger' ? 'bg-red-50 text-red-700 border-red-200' :
                                            log.severity === 'warning' ? 'bg-amber-50 text-amber-700 border-amber-200' :
                                            'bg-blue-50 text-blue-700 border-blue-200'
                                        ]"
                                    >
                                        {{ log.severity || 'info' }}
                                    </span>
                                </td>
                                <td class="py-4 font-mono text-xs font-semibold text-gray-800">
                                    {{ log.event }}
                                </td>
                                <td class="py-4 text-xs font-medium text-gray-700">
                                    {{ log.user_email || 'Unauthenticated' }}
                                </td>
                                <td class="py-4 font-mono text-xs text-gray-500">
                                    {{ log.ip_address || '—' }}
                                </td>
                                <td class="py-4 text-xs text-gray-600 max-w-md truncate" :title="log.description">
                                    {{ log.description }}
                                </td>
                                <td class="py-4">
                                    <span
                                        :class="[
                                            'px-2 py-0.5 rounded-full text-xs font-semibold border',
                                            log.outcome === 'success' ? 'bg-green-50 text-green-700 border-green-200' :
                                            log.outcome === 'blocked' ? 'bg-amber-50 text-amber-700 border-amber-200' :
                                            'bg-red-50 text-red-700 border-red-200'
                                        ]"
                                    >
                                        {{ log.outcome }}
                                    </span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <div v-if="securityLogs && securityLogs.last_page > 1" class="flex justify-between items-center mt-5 pt-4 border-t border-gray-100">
                    <span class="text-xs text-gray-500">Page {{ securityLogs.current_page }} of {{ securityLogs.last_page }}</span>
                    <div class="flex gap-2">
                        <Link
                            v-if="securityLogs.prev_page_url"
                            :href="securityLogs.prev_page_url"
                            class="px-3 py-1 bg-white border border-gray-200 rounded-lg text-xs font-semibold text-gray-700 hover:bg-gray-50"
                        >
                            Previous
                        </Link>
                        <Link
                            v-if="securityLogs.next_page_url"
                            :href="securityLogs.next_page_url"
                            class="px-3 py-1 bg-white border border-gray-200 rounded-lg text-xs font-semibold text-gray-700 hover:bg-gray-50"
                        >
                            Next
                        </Link>
                    </div>
                </div>
            </div>
        </div>
        </div>
    </component>

    <!-- ========================================== -->
    <!-- MODALS                                     -->
    <!-- ========================================== -->

    <!-- Add Account Modal -->
    <div v-if="showAddModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
        <div class="bg-white border border-gray-200 p-6 rounded-xl w-full max-w-md shadow-xl">
            <div class="flex justify-between items-center mb-5">
                <h3 class="text-xl font-semibold text-gray-900">Create User Account</h3>
                <button @click="showAddModal = false" class="text-gray-400 hover:text-gray-600 text-xl">&times;</button>
            </div>

            <form @submit.prevent="promptCreateUser" class="space-y-4">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div class="relative">
                        <input
                            id="add-first-name"
                            v-model="addForm.first_name"
                            type="text"
                            required
                            placeholder=" "
                            class="peer block w-full rounded-xl border border-gray-200 bg-white px-3.5 pt-5 pb-2 text-xs text-gray-900 focus:border-[#4b5320] focus:ring-1 focus:ring-[#4b5320] focus:outline-none sm:text-sm"
                            :class="addForm.errors.first_name ? 'border-red-500 focus:border-red-500 focus:ring-red-500' : ''"
                        />
                        <label
                            for="add-first-name"
                            class="pointer-events-none absolute top-3.5 left-3.5 origin-[0] -translate-y-2.5 scale-75 transform text-xs font-semibold text-gray-500 duration-200 peer-placeholder-shown:translate-y-0 peer-placeholder-shown:scale-100 peer-focus:-translate-y-2.5 peer-focus:scale-75 peer-focus:text-[#4b5320]"
                            :class="addForm.first_name ? '-translate-y-2.5 scale-75 text-[#4b5320]' : ''"
                        >
                            First Name
                        </label>
                        <span v-if="addForm.errors.first_name" class="text-xs text-red-500 mt-1 block">{{ addForm.errors.first_name }}</span>
                    </div>
                    <div class="relative">
                        <input
                            id="add-last-name"
                            v-model="addForm.last_name"
                            type="text"
                            required
                            placeholder=" "
                            class="peer block w-full rounded-xl border border-gray-200 bg-white px-3.5 pt-5 pb-2 text-xs text-gray-900 focus:border-[#4b5320] focus:ring-1 focus:ring-[#4b5320] focus:outline-none sm:text-sm"
                            :class="addForm.errors.last_name ? 'border-red-500 focus:border-red-500 focus:ring-red-500' : ''"
                        />
                        <label
                            for="add-last-name"
                            class="pointer-events-none absolute top-3.5 left-3.5 origin-[0] -translate-y-2.5 scale-75 transform text-xs font-semibold text-gray-500 duration-200 peer-placeholder-shown:translate-y-0 peer-placeholder-shown:scale-100 peer-focus:-translate-y-2.5 peer-focus:scale-75 peer-focus:text-[#4b5320]"
                            :class="addForm.last_name ? '-translate-y-2.5 scale-75 text-[#4b5320]' : ''"
                        >
                            Last Name
                        </label>
                        <span v-if="addForm.errors.last_name" class="text-xs text-red-500 mt-1 block">{{ addForm.errors.last_name }}</span>
                    </div>
                </div>

                <div class="relative">
                    <input
                        id="add-email"
                        v-model="addForm.email"
                        type="email"
                        required
                        placeholder=" "
                        class="peer block w-full rounded-xl border border-gray-200 bg-white px-3.5 pt-5 pb-2 text-xs text-gray-900 focus:border-[#4b5320] focus:ring-1 focus:ring-[#4b5320] focus:outline-none sm:text-sm"
                        :class="addForm.errors.email ? 'border-red-500 focus:border-red-500 focus:ring-red-500' : ''"
                    />
                    <label
                        for="add-email"
                        class="pointer-events-none absolute top-3.5 left-3.5 origin-[0] -translate-y-2.5 scale-75 transform text-xs font-semibold text-gray-500 duration-200 peer-placeholder-shown:translate-y-0 peer-placeholder-shown:scale-100 peer-focus:-translate-y-2.5 peer-focus:scale-75 peer-focus:text-[#4b5320]"
                        :class="addForm.email ? '-translate-y-2.5 scale-75 text-[#4b5320]' : ''"
                    >
                        Email Address
                    </label>
                    <span v-if="addForm.errors.email" class="text-xs text-red-500 mt-1 block">{{ addForm.errors.email }}</span>
                </div>

                <!-- Initial Password Notice: random, shown once -->
                <div class="rounded-xl border border-amber-200 bg-amber-50/70 p-3.5 text-xs text-amber-900 flex items-start gap-2.5">
                    <KeyRound class="w-4 h-4 text-amber-600 shrink-0 mt-0.5" />
                    <div>
                        <p class="font-semibold text-amber-950">Initial Password on First Login</p>
                        <p class="text-amber-800 mt-0.5 text-[11px] leading-relaxed">
                            The temporary password is the user's first name followed by
                            <strong class="font-semibold text-amber-950">1234</strong> (e.g. Juan1234).
                            A 5-minute enrollment code and link are emailed to them to register their device;
                            they must change the password at first login.
                        </p>
                    </div>
                </div>

                <div class="relative">
                    <select
                        id="add-account-type"
                        v-model="addForm.account_type"
                        class="peer block w-full rounded-xl border border-gray-200 bg-white px-3.5 pt-5 pb-2 text-xs text-gray-900 focus:border-[#4b5320] focus:ring-1 focus:ring-[#4b5320] focus:outline-none sm:text-sm"
                    >
                        <option value="staff">Staff (select modules)</option>
                        <option value="admin">Administrator (All Modules)</option>
                    </select>
                    <label
                        for="add-account-type"
                        class="pointer-events-none absolute top-3.5 left-3.5 origin-[0] -translate-y-2.5 scale-75 transform text-xs font-semibold text-[#4b5320]"
                    >
                        Account Type
                    </label>
                    <span v-if="addForm.errors.account_type" class="text-xs text-red-500 mt-1 block">{{ addForm.errors.account_type }}</span>
                </div>

                <ModuleMultiSelect
                    v-if="addForm.account_type === 'staff'"
                    id="add-modules"
                    v-model="addForm.modules"
                    :options="moduleOptionList"
                    label="Assigned Modules"
                    :error="addForm.errors.modules || addForm.errors['modules.0']"
                />

                <div class="flex justify-end gap-3 mt-6 pt-2 border-t border-gray-100">
                    <SpecularButton type="button" variant="secondary" size="md" :radius="10" @click="showAddModal = false">
                        Cancel
                    </SpecularButton>
                    <SpecularButton type="submit" variant="brand" size="md" :radius="10" :disabled="addForm.processing">
                        {{ addForm.processing ? 'Creating...' : 'Create Account' }}
                    </SpecularButton>
                </div>
            </form>
        </div>
    </div>

    <!-- Edit User Modal -->
    <div v-if="showEditModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
        <div class="bg-white border border-gray-200 p-6 rounded-xl w-full max-w-md shadow-xl">
            <div class="flex justify-between items-center mb-5">
                <h3 class="text-xl font-semibold text-gray-900">Edit Member Role & Account</h3>
                <button @click="showEditModal = false" class="text-gray-400 hover:text-gray-600 text-xl">&times;</button>
            </div>

            <form @submit.prevent="promptEditUser" class="space-y-4">
                <div class="relative">
                    <input
                        id="edit-name"
                        v-model="editForm.name"
                        type="text"
                        required
                        placeholder=" "
                        class="peer block w-full rounded-xl border border-gray-200 bg-white px-3.5 pt-5 pb-2 text-xs text-gray-900 focus:border-[#4b5320] focus:ring-1 focus:ring-[#4b5320] focus:outline-none sm:text-sm"
                        :class="editForm.errors.name ? 'border-red-500 focus:border-red-500 focus:ring-red-500' : ''"
                    />
                    <label
                        for="edit-name"
                        class="pointer-events-none absolute top-3.5 left-3.5 origin-[0] -translate-y-2.5 scale-75 transform text-xs font-semibold text-gray-500 duration-200 peer-placeholder-shown:translate-y-0 peer-placeholder-shown:scale-100 peer-focus:-translate-y-2.5 peer-focus:scale-75 peer-focus:text-[#4b5320]"
                        :class="editForm.name ? '-translate-y-2.5 scale-75 text-[#4b5320]' : ''"
                    >
                        Full Name
                    </label>
                    <span v-if="editForm.errors.name" class="text-xs text-red-500 mt-1 block">{{ editForm.errors.name }}</span>
                </div>
                <div class="relative">
                    <select
                        id="edit-account-type"
                        v-model="editForm.account_type"
                        class="peer block w-full rounded-xl border border-gray-200 bg-white px-3.5 pt-5 pb-2 text-xs text-gray-900 focus:border-[#4b5320] focus:ring-1 focus:ring-[#4b5320] focus:outline-none sm:text-sm"
                    >
                        <option value="staff">Staff (select modules)</option>
                        <option value="admin">Administrator (All Modules)</option>
                    </select>
                    <label
                        for="edit-account-type"
                        class="pointer-events-none absolute top-3.5 left-3.5 origin-[0] -translate-y-2.5 scale-75 transform text-xs font-semibold text-[#4b5320]"
                    >
                        Account Type
                    </label>
                </div>

                <ModuleMultiSelect
                    v-if="editForm.account_type === 'staff'"
                    id="edit-modules"
                    v-model="editForm.modules"
                    :options="moduleOptionList"
                    label="Assigned Modules"
                    :error="editForm.errors.modules || editForm.errors['modules.0']"
                />
                <p class="text-[11px] text-gray-500 mt-1">
                    Changing a member's role or modules invalidates their active sessions to enforce permissions immediately.
                </p>
                <span v-if="(editForm.errors as Record<string, string | undefined>).role" class="text-xs text-red-500 mt-1 block">{{ (editForm.errors as Record<string, string | undefined>).role }}</span>

                <div class="flex justify-end gap-3 mt-6 pt-2 border-t border-gray-100">
                    <SpecularButton type="button" variant="secondary" size="md" :radius="10" @click="showEditModal = false">
                        Cancel
                    </SpecularButton>
                    <SpecularButton type="submit" variant="brand" size="md" :radius="10" :disabled="editForm.processing">
                        {{ editForm.processing ? 'Saving...' : 'Update Account' }}
                    </SpecularButton>
                </div>
            </form>
        </div>
    </div>

    <!-- Toggle Status Confirmation Modal -->
    <div v-if="showStatusModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
        <div class="bg-white border border-gray-200 p-6 rounded-xl w-full max-w-md shadow-xl">
            <h3 class="text-xl font-semibold text-gray-900 mb-2">
                {{ selectedUser?.is_active ? 'Deactivate Account' : 'Activate Account' }}
            </h3>
            <p class="text-gray-600 text-sm mb-6">
                Are you sure you want to {{ selectedUser?.is_active ? 'deactivate' : 'activate' }}
                <span class="font-semibold text-gray-900">{{ selectedUser?.name }}</span>?
                <span v-if="selectedUser?.is_active" class="block mt-2 text-amber-700 font-medium">
                    All current active sessions will be revoked immediately and they will be blocked from logging in.
                </span>
            </p>
            <div class="flex justify-end gap-3">
                <SpecularButton variant="secondary" size="md" :radius="10" @click="showStatusModal = false">
                    Cancel
                </SpecularButton>
                <SpecularButton
                    :variant="selectedUser?.is_active ? 'destructive' : 'brand'"
                    size="md"
                    :radius="10"
                    @click="confirmStatusToggle"
                >
                    {{ selectedUser?.is_active ? 'Deactivate Account' : 'Activate Account' }}
                </SpecularButton>
            </div>
        </div>
    </div>

    <!-- Delete Account Confirmation Modal -->
    <div v-if="showDeleteModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
        <div class="bg-white border border-gray-200 p-6 rounded-xl w-full max-w-md shadow-xl">
            <h3 class="text-xl font-semibold text-gray-900 mb-2">Are you sure you want to permanently delete this record?</h3>
            <p class="text-gray-600 text-sm mb-3 leading-relaxed">
                This action cannot be undone. Related active sessions, registered trusted devices, or dependent records must only be revoked or removed when explicitly required by the operation.
            </p>
            <div class="p-3 bg-rose-50 border border-rose-200 rounded-xl text-xs text-rose-800 mb-4">
                Deleting user account <span class="font-semibold text-rose-900">{{ selectedUser?.name }}</span> ({{ selectedUser?.email }}) will immediately revoke their active sessions and registered trusted devices as required for account removal.
            </div>
            <div class="mt-4 mb-6">
                <label class="block text-xs font-semibold text-gray-700 mb-1.5">
                    Type <span class="font-mono font-semibold text-rose-600 bg-rose-50 px-1.5 py-0.5 rounded border border-rose-200">YES</span> to confirm permanent deletion:
                </label>
                <input
                    v-model="deleteConfirmInput"
                    type="text"
                    placeholder="Type YES here"
                    class="w-full px-3 py-2 text-sm font-mono rounded-xl border border-gray-300 focus:outline-none focus:border-rose-500 focus:ring-1 focus:ring-rose-500"
                />
            </div>
            <div class="flex justify-end gap-3">
                <SpecularButton variant="secondary" size="md" :radius="10" @click="showDeleteModal = false">
                    Cancel
                </SpecularButton>
                <SpecularButton
                    variant="destructive"
                    size="md"
                    :radius="10"
                    @click="confirmDelete"
                    :disabled="deleteConfirmInput !== 'YES'"
                >
                    Delete Account
                </SpecularButton>
            </div>
        </div>
    </div>

    <!-- Enroll Device Modal -->
    <div v-if="showEnrollModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
        <div class="bg-white border border-gray-200 p-6 rounded-xl w-full max-w-lg shadow-xl">
            <div class="flex justify-between items-center mb-5">
                <h3 class="text-xl font-semibold text-gray-900">Initiate Workstation Enrollment</h3>
                <button @click="showEnrollModal = false" class="text-gray-400 hover:text-gray-600 text-xl cursor-pointer">&times;</button>
            </div>

            <form @submit.prevent="promptEnrollDevice" class="space-y-4.5">
                <!-- One device, one account (shared workstations are not allowed) -->
                <div class="rounded-xl border border-gray-200 bg-gray-50 p-3 text-[11px] text-gray-600">
                    <span class="font-semibold text-gray-900">One device, one account.</span>
                    The invitation is bound to the selected user only and replaces their previous device.
                </div>
                <!-- Single Account Selector -->
                <div v-if="enrollForm.assignment_type === 'single'" class="relative">
                    <select
                        id="enroll-user"
                        v-model="enrollForm.user_id"
                        class="peer block w-full rounded-xl border border-gray-200 bg-white px-3.5 pt-5 pb-2 text-xs text-gray-900 focus:border-[#4b5320] focus:ring-1 focus:ring-[#4b5320] focus:outline-none sm:text-sm"
                    >
                        <option value="" disabled>Choose account holder...</option>
                        <option v-for="u in users" :key="u.id" :value="u.id">
                            {{ u.name }} ({{ u.email }}) — {{ u.role }}
                        </option>
                    </select>
                    <label
                        for="enroll-user"
                        class="pointer-events-none absolute top-3.5 left-3.5 origin-[0] -translate-y-2.5 scale-75 transform text-xs font-semibold text-[#4b5320]"
                    >
                        Assigned Account Holder
                    </label>
                    <span v-if="enrollForm.errors.user_id" class="text-xs text-red-500 mt-1 block">{{ enrollForm.errors.user_id }}</span>
                </div>

                <!-- Shared Workstation Multi-Select Checklist -->
                <div v-else class="space-y-1.5">
                    <label class="text-xs font-semibold text-gray-700">Select Authorized Personnel Accounts</label>
                    <div class="max-h-40 overflow-y-auto rounded-xl border border-gray-200 divide-y divide-gray-100 p-2 bg-gray-50/50">
                        <label
                            v-for="u in users"
                            :key="u.id"
                            class="flex items-center gap-2.5 p-2 hover:bg-white rounded-lg transition-colors cursor-pointer text-xs"
                        >
                            <input
                                type="checkbox"
                                :value="u.id"
                                v-model="enrollForm.authorized_user_ids"
                                class="rounded border-gray-300 text-[#4b5320] focus:ring-[#4b5320]"
                            />
                            <div>
                                <span class="font-semibold text-gray-900">{{ u.name }}</span>
                                <span class="text-gray-500 text-[11px] ml-1.5">({{ u.role }})</span>
                            </div>
                        </label>
                    </div>
                </div>

                <!-- Device Label -->
                <div class="relative">
                    <input
                        id="enroll-device-name"
                        v-model="enrollForm.device_name"
                        type="text"
                        placeholder=" "
                        class="peer block w-full rounded-xl border border-gray-200 bg-white px-3.5 pt-5 pb-2 text-xs text-gray-900 focus:border-[#4b5320] focus:ring-1 focus:ring-[#4b5320] focus:outline-none sm:text-sm"
                    />
                    <label
                        for="enroll-device-name"
                        class="pointer-events-none absolute top-3.5 left-3.5 origin-[0] -translate-y-2.5 scale-75 transform text-xs font-semibold text-gray-500 duration-200 peer-placeholder-shown:translate-y-0 peer-placeholder-shown:scale-100 peer-focus:-translate-y-2.5 peer-focus:scale-75 peer-focus:text-[#4b5320]"
                        :class="enrollForm.device_name ? '-translate-y-2.5 scale-75 text-[#4b5320]' : ''"
                    >
                        Workstation Label (e.g. Gary's iPhone, Shop Terminal 1)
                    </label>
                </div>

                <!-- Fixed 5-minute, single-use invitation (enforced on the server) -->
                <p class="text-[11px] text-gray-500">
                    The code and link are emailed to the user and expire in <strong>5 minutes</strong>. They work once.
                    The user needs the link, the code, their email and their temporary password to register this device.
                </p>
                <div class="flex justify-end gap-3 mt-6 pt-2 border-t border-gray-100">
                    <SpecularButton type="button" variant="secondary" size="md" :radius="10" @click="showEnrollModal = false">
                        Cancel
                    </SpecularButton>
                    <SpecularButton type="submit" variant="brand" size="md" :radius="10" :disabled="enrollForm.processing">
                        {{ enrollForm.processing ? 'Generating...' : 'Generate Enrollment Code' }}
                    </SpecularButton>
                </div>
            </form>
        </div>
    </div>

    <!-- Rename Device Modal -->
    <div v-if="showRenameDeviceModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
        <div class="bg-white border border-gray-200 p-6 rounded-xl w-full max-w-md shadow-xl">
            <div class="flex justify-between items-center mb-5">
                <h3 class="text-xl font-semibold text-gray-900">Rename Workstation</h3>
                <button @click="showRenameDeviceModal = false" class="text-gray-400 hover:text-gray-600 text-xl cursor-pointer">&times;</button>
            </div>

            <form @submit.prevent="submitRenameDevice" class="space-y-4">
                <div class="space-y-1.5">
                    <label for="rename-device-name" class="text-xs font-semibold text-gray-700">
                        Device / Terminal Display Name
                    </label>
                    <input
                        id="rename-device-name"
                        v-model="renameDeviceForm.device_name"
                        type="text"
                        required
                        class="block w-full rounded-xl border border-gray-200 bg-white px-3.5 py-2.5 text-sm text-gray-900 focus:border-[#4b5320] focus:ring-1 focus:ring-[#4b5320] focus:outline-none"
                    />
                    <span v-if="renameDeviceForm.errors.device_name" class="text-xs text-red-500 mt-1 block">
                        {{ renameDeviceForm.errors.device_name }}
                    </span>
                </div>

                <div class="flex justify-end gap-3 mt-6 pt-2 border-t border-gray-100">
                    <SpecularButton type="button" variant="secondary" size="md" :radius="10" @click="showRenameDeviceModal = false">
                        Cancel
                    </SpecularButton>
                    <SpecularButton type="submit" variant="brand" size="md" :radius="10" :disabled="renameDeviceForm.processing">
                        {{ renameDeviceForm.processing ? 'Saving...' : 'Save Name' }}
                    </SpecularButton>
                </div>
            </form>
        </div>
    </div>

    <!-- Revoke Session Modal -->
    <div v-if="showRevokeSessionModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
        <div class="bg-white border border-gray-200 p-6 rounded-xl w-full max-w-md shadow-xl">
            <h3 class="text-xl font-semibold text-gray-900 mb-2">Revoke Active Session</h3>
            <p class="text-gray-600 text-sm mb-6">
                Are you sure you want to revoke the session for
                <span class="font-semibold text-gray-900">{{ selectedSession?.user_name }}</span>
                ({{ selectedSession?.browser }} on {{ selectedSession?.os }})? They will be logged out immediately.
            </p>
            <div class="flex justify-end gap-3">
                <SpecularButton variant="secondary" size="md" :radius="10" @click="showRevokeSessionModal = false">
                    Cancel
                </SpecularButton>
                <SpecularButton variant="destructive" size="md" :radius="10" @click="confirmRevokeSession">
                    Revoke Session
                </SpecularButton>
            </div>
        </div>
    </div>

    <!-- Revoke All User Sessions Modal -->
    <div v-if="showRevokeUserSessionsModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
        <div class="bg-white border border-gray-200 p-6 rounded-xl w-full max-w-md shadow-xl">
            <h3 class="text-xl font-semibold text-gray-900 mb-2">Terminate All Sessions</h3>
            <p class="text-gray-600 text-sm mb-6">
                Are you sure you want to terminate all active sessions for
                <span class="font-semibold text-gray-900">{{ selectedUser?.name }}</span>?
                They will be forced to log in again across all devices.
            </p>
            <div class="flex justify-end gap-3">
                <SpecularButton variant="secondary" size="md" :radius="10" @click="showRevokeUserSessionsModal = false">
                    Cancel
                </SpecularButton>
                <SpecularButton variant="destructive" size="md" :radius="10" @click="confirmRevokeUserSessions">
                    Revoke All Sessions
                </SpecularButton>
            </div>
        </div>
    </div>

    <!-- Revoke Device Modal -->
    <div v-if="showRevokeDeviceModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
        <div class="bg-white border border-gray-200 p-6 rounded-xl w-full max-w-md shadow-xl">
            <h3 class="text-xl font-semibold text-gray-900 mb-2">Revoke Trusted Device</h3>
            <p class="text-gray-600 text-sm mb-4">
                Are you sure you want to revoke access for
                <span class="font-semibold text-gray-900">{{ selectedDevice?.device_name }}</span>
                ({{ selectedDevice?.user?.name }})?
                All active sessions on this device will be terminated immediately.
            </p>
            <div class="mt-4 mb-6">
                <label class="block text-xs font-semibold text-gray-700 mb-1.5">
                    Type <span class="font-mono font-semibold text-rose-600 bg-rose-50 px-1.5 py-0.5 rounded border border-rose-200">YES</span> to confirm permanent deletion:
                </label>
                <input
                    v-model="revokeDeviceConfirmInput"
                    type="text"
                    placeholder="Type YES here"
                    class="w-full px-3 py-2 text-sm font-mono rounded-xl border border-gray-300 focus:outline-none focus:border-rose-500 focus:ring-1 focus:ring-rose-500"
                />
            </div>
            <div class="flex justify-end gap-3">
                <SpecularButton variant="secondary" size="md" :radius="10" @click="showRevokeDeviceModal = false">
                    Cancel
                </SpecularButton>
                <SpecularButton
                    variant="destructive"
                    size="md"
                    :radius="10"
                    @click="confirmRevokeDevice"
                    :disabled="revokeDeviceConfirmInput !== 'YES'"
                >
                    Revoke Device
                </SpecularButton>
            </div>
        </div>
    </div>

    <!-- View Metadata Modal -->
    <div v-if="showMetadataModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
        <div class="bg-white border border-gray-200 p-6 rounded-xl w-full max-w-lg shadow-xl">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-lg font-semibold text-gray-900">Event Context Data</h3>
                <button @click="showMetadataModal = false" class="text-gray-400 hover:text-gray-600 text-xl">&times;</button>
            </div>
            <pre class="bg-gray-50 border border-gray-200 p-4 rounded-xl text-xs font-mono text-gray-800 overflow-x-auto max-h-96">{{ JSON.stringify(selectedMetadata, null, 2) }}</pre>
            <div class="flex justify-end mt-4">
                <SpecularButton variant="secondary" size="sm" :radius="8" @click="showMetadataModal = false">
                    Close
                </SpecularButton>
            </div>
        </div>
    </div>

    <!-- Universal Action Confirmation Modal -->
    <div v-if="showConfirmModal" class="fixed inset-0 z-[70] flex items-center justify-center bg-black/60 p-4">
        <div class="bg-white border border-gray-200 p-6 rounded-xl w-full max-w-md shadow-xl animate-in fade-in duration-150">
            <div
                :class="[
                    'w-11 h-11 rounded-full flex items-center justify-center mb-4 border',
                    confirmOptions.confirmVariant === 'destructive'
                        ? 'bg-red-50 text-red-600 border-red-100'
                        : 'bg-amber-50 text-[#F8B803] border-amber-100'
                ]"
            >
                <AlertTriangle v-if="confirmOptions.confirmVariant === 'destructive'" class="w-6 h-6" />
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

            <div v-if="confirmOptions.confirmVariant === 'destructive'" class="mt-2 mb-5">
                <label class="block text-xs font-semibold text-gray-700 mb-1.5">
                    Type <span class="font-mono font-semibold text-rose-600 bg-rose-50 px-1.5 py-0.5 rounded border border-rose-200">YES</span> to confirm permanent deletion:
                </label>
                <input
                    v-model="confirmActionInput"
                    type="text"
                    placeholder="Type YES here"
                    class="w-full px-3 py-2 text-sm font-mono rounded-xl border border-gray-300 focus:outline-none focus:border-rose-500 focus:ring-1 focus:ring-rose-500"
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
                    :variant="confirmOptions.confirmVariant || 'brand'"
                    size="md"
                    :radius="10"
                    @click="handleConfirmedAction"
                    :disabled="addForm.processing || editForm.processing || enrollForm.processing || (confirmOptions.confirmVariant === 'destructive' && confirmActionInput !== 'YES')"
                >
                    <Check v-if="confirmOptions.confirmVariant !== 'destructive'" class="w-4 h-4 mr-1.5 inline" />
                    <Trash2 v-else class="w-4 h-4 mr-1.5 inline" />
                    <span>{{ confirmOptions.confirmText || 'Confirm & Proceed' }}</span>
                </SpecularButton>
            </div>
        </div>
    </div>

    <!-- Enrollment Result Modal (Displays Code + Copyable 5-Minute Link) -->
    <div v-if="showEnrollmentResultModal && activeEnrollmentResult" class="fixed inset-0 z-[75] flex items-center justify-center bg-black/60 p-4">
        <div class="bg-white border border-gray-200 p-6 rounded-xl w-full max-w-lg shadow-xl animate-in fade-in duration-150">
            <div class="flex items-start justify-between mb-4">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-xl bg-emerald-50 border border-emerald-200 flex items-center justify-center text-emerald-700">
                        <Laptop class="w-5 h-5" />
                    </div>
                    <div>
                        <h3 class="text-lg font-semibold text-gray-900">Workstation Enrollment Created</h3>
                        <p class="text-xs text-gray-500">Invitation dispatched to registered employee</p>
                    </div>
                </div>
                <button
                    @click="showEnrollmentResultModal = false"
                    class="p-1 text-gray-400 hover:text-gray-600 rounded-lg hover:bg-gray-100 transition-colors cursor-pointer"
                >
                    <X class="w-5 h-5" />
                </button>
            </div>

            <!-- 5-minute Lifespan Notice Banner -->
            <div class="mb-5 rounded-xl border border-amber-200 bg-amber-50/70 p-3.5 flex items-start gap-3">
                <Clock class="w-5 h-5 text-amber-600 shrink-0 mt-0.5" />
                <div class="text-xs text-amber-900 leading-relaxed">
                    <div class="font-semibold text-amber-950 flex items-center gap-1.5">
                        <span>Strict 5-Minute Lifespan</span>
                        <span class="px-1.5 py-0.2 rounded text-[10px] font-semibold bg-amber-200 text-amber-900">Single-Use</span>
                    </div>
                    <p class="mt-0.5">
                        This enrollment link and code can only be opened for <strong>5 minutes</strong>. It is locked strictly to <strong>{{ activeEnrollmentResult.user_email }}</strong> and cannot be opened by anyone else even if forwarded.
                    </p>
                </div>
            </div>

            <div class="space-y-4">
                <!-- Enrollment Code Display -->
                <div>
                    <label class="block text-xs font-semibold text-gray-700 mb-1.5">
                        One-Time Enrollment Code
                    </label>
                    <div class="flex items-center gap-2">
                        <div class="flex-1 bg-gray-50 border border-gray-200 rounded-xl px-4 py-2.5 font-mono text-lg font-semibold tracking-wider text-gray-900 text-center select-all">
                            {{ activeEnrollmentResult.code }}
                        </div>
                        <button
                            type="button"
                            @click="copyToClipboard(activeEnrollmentResult.code || '', 'code')"
                            class="px-4 py-2.5 rounded-xl border border-gray-200 hover:border-gray-300 bg-white hover:bg-gray-50 text-xs font-semibold text-gray-700 transition-colors flex items-center gap-1.5 shrink-0 cursor-pointer"
                        >
                            <Check v-if="copiedCode" class="w-4 h-4 text-emerald-600" />
                            <Copy v-else class="w-4 h-4 text-gray-500" />
                            <span>{{ copiedCode ? 'Copied!' : 'Copy Code' }}</span>
                        </button>
                    </div>
                </div>

                <!-- Copyable Link Display -->
                <div>
                    <label class="block text-xs font-semibold text-gray-700 mb-1.5">
                        Copyable 5-Minute Enrollment Link
                    </label>
                    <div class="flex items-center gap-2">
                        <input
                            type="text"
                            readonly
                            :value="activeEnrollmentResult.link"
                            class="flex-1 bg-gray-50 border border-gray-200 rounded-xl px-3 py-2 text-xs font-mono text-gray-700 select-all focus:outline-none"
                        />
                        <button
                            type="button"
                            @click="copyToClipboard(activeEnrollmentResult.link, 'link')"
                            class="px-4 py-2 rounded-xl bg-[#4b5320] hover:bg-[#3d441a] text-white text-xs font-semibold transition-colors flex items-center gap-1.5 shrink-0 cursor-pointer"
                        >
                            <Check v-if="copiedLink" class="w-4 h-4 text-white" />
                            <Copy v-else class="w-4 h-4 text-white" />
                            <span>{{ copiedLink ? 'Copied!' : 'Copy Link' }}</span>
                        </button>
                    </div>
                </div>

                <!-- Recipient Info Card -->
                <div class="rounded-xl border border-gray-100 bg-gray-50 p-3 text-xs space-y-1">
                    <div class="flex justify-between">
                        <span class="text-gray-500">Intended Employee:</span>
                        <span class="font-semibold text-gray-900">{{ activeEnrollmentResult.user_name }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-500">Registered Email:</span>
                        <span class="font-semibold text-gray-900">{{ activeEnrollmentResult.user_email }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-500">Expires At:</span>
                        <span class="font-semibold text-rose-600">{{ activeEnrollmentResult.expires_at }}</span>
                    </div>
                </div>
            </div>

            <div class="flex justify-end mt-6 pt-3 border-t border-gray-100">
                <SpecularButton
                    type="button"
                    variant="brand"
                    size="md"
                    :radius="10"
                    @click="showEnrollmentResultModal = false"
                >
                    Done & Close
                </SpecularButton>
            </div>
        </div>
    </div>
</template>
