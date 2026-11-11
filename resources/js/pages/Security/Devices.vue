<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import { ref } from 'vue';
import AppSidebarLayout from '@/layouts/app/AppSidebarLayout.vue';
import SpecularButton from '@/components/ui/SpecularButton.vue';
import {
    Laptop,
    ShieldCheck,
    ShieldAlert,
    Trash2,
    CheckCircle2,
    AlertTriangle,
    XCircle,
    KeyRound,
    Copy,
    Check,
} from '@lucide/vue';

interface DeviceItem {
    id: number;
    user: {
        id: number;
        name: string;
        email: string;
        role: string;
    };
    device_name: string;
    ip_address: string | null;
    status: 'approved' | 'pending' | 'revoked';
    is_current: boolean;
    last_used_at: string;
    created_at: string;
}

const props = defineProps<{
    devices: DeviceItem[];
    isAdmin: boolean;
    currentDeviceUuid?: string;
    adminRecoveryKey?: string | null;
}>();

const page = usePage();
const copiedKey = ref(false);

const copyRecoveryKey = () => {
    if (props.adminRecoveryKey) {
        navigator.clipboard.writeText(props.adminRecoveryKey);
        copiedKey.value = true;
        setTimeout(() => (copiedKey.value = false), 2500);
    }
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
const confirmActionInput = ref('');
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

const promptApproveDevice = (device: DeviceItem) => {
    openConfirmModal({
        title: 'Confirm Device Approval',
        description: `Authorize and approve access for "${device.device_name}"?`,
        details: [
            { label: 'Device Name', value: device.device_name },
            { label: 'Account Holder', value: `${device.user.name} (${device.user.email})` },
            { label: 'IP Address', value: device.ip_address || 'Not Recorded' },
        ],
        confirmText: 'Approve Device',
        confirmVariant: 'brand',
        action: () => approveDevice(device),
    });
};

const approveDevice = (device: DeviceItem) => {
    const team = (page.props as any).currentTeam?.slug;
    router.post(`/${team}/security/devices/${device.id}/approve`, {}, {
        preserveScroll: true,
    });
};

const promptRevokeDevice = (device: DeviceItem) => {
    openConfirmModal({
        title: 'Revoke Trusted Device',
        description: `Are you sure you want to revoke access for "${device.device_name}"? Active sessions on this device will be immediately terminated.`,
        details: [
            { label: 'Device Name', value: device.device_name },
            { label: 'Account Holder', value: device.user.name },
        ],
        confirmText: 'Revoke Access',
        confirmVariant: 'destructive',
        action: () => {
            const team = (page.props as any).currentTeam?.slug;
            router.post(`/${team}/security/devices/${device.id}/revoke`, {}, {
                preserveScroll: true,
            });
        },
    });
};

const promptDeleteDevice = (device: DeviceItem) => {
    openConfirmModal({
        title: 'Remove Trusted Device',
        description: `Permanently delete "${device.device_name}" from the trusted device registry? Active sessions on this hardware will be immediately terminated.`,
        details: [
            { label: 'Device Name', value: device.device_name },
            { label: 'Account Holder', value: device.user.name },
        ],
        confirmText: 'Remove Device',
        confirmVariant: 'destructive',
        action: () => {
            const team = (page.props as any).currentTeam?.slug;
            router.delete(`/${team}/security/devices/${device.id}`, {
                preserveScroll: true,
            });
        },
    });
};
</script>

<template>
    <AppSidebarLayout :breadcrumbs="[{ title: 'Security', href: '#' }, { title: 'Trusted Devices', href: '#' }]">
        <Head title="Trusted Devices | MR-Pride" />

        <div class="workspace-page">
            <!-- Header Section -->
            <div class="page-header">
                <div>
                    <h1 class="page-title">
                        Trusted Devices
                    </h1>
                    <p class="page-subtitle">
                        Manage recognized hardware and workstations authorized for administrative access.
                    </p>
                </div>
            </div>

            <!-- Emergency Admin Recovery Key Card (Admin Only) -->
            <div
                v-if="isAdmin && adminRecoveryKey"
                class="rounded-xl border border-amber-200 bg-amber-50/60 p-5"
            >
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                    <div class="flex items-start gap-3.5">
                        <div class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-[#4b5320] text-[#F8B803]">
                            <KeyRound class="size-5" />
                        </div>
                        <div>
                            <h3 class="text-sm font-semibold text-gray-900 flex items-center gap-2">
                                Emergency Admin Recovery Key
                                <span class="rounded bg-amber-100 px-2 py-0.5 text-[10px] font-semibold text-amber-800">
                                    Single-Use
                                </span>
                            </h3>
                            <p class="text-xs text-gray-600 mt-1 leading-relaxed max-w-2xl">
                                If you lose access to all your trusted devices or email, use this cryptographic key on the device verification screen to restore access.
                            </p>
                            <p class="mt-2 font-mono text-sm font-semibold text-gray-900 tracking-wider bg-white px-3 py-1.5 rounded-lg border border-amber-200 inline-block">
                                {{ adminRecoveryKey }}
                            </p>
                        </div>
                    </div>
                    <button
                        type="button"
                        @click="copyRecoveryKey"
                        class="inline-flex items-center gap-2 rounded-xl border border-gray-300 bg-white px-4 py-2 text-xs font-semibold text-gray-700 hover:bg-gray-50 cursor-pointer self-start sm:self-center"
                    >
                        <Check v-if="copiedKey" class="size-4 text-green-600" />
                        <Copy v-else class="size-4 text-gray-500" />
                        <span>{{ copiedKey ? 'Copied Key!' : 'Copy Key' }}</span>
                    </button>
                </div>
            </div>

            <!-- Devices Table -->
            <div class="rounded-xl border border-gray-200 bg-white overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm text-gray-600">
                        <thead class="bg-gray-50/80 border-b border-gray-200 text-xs font-semibold text-gray-700">
                            <tr>
                                <th class="px-4 py-3">Device & Workstation</th>
                                <th v-if="isAdmin" class="px-4 py-3">Account Owner</th>
                                <th class="px-4 py-3">IP Address</th>
                                <th class="px-4 py-3">Status</th>
                                <th class="px-4 py-3">Last Active</th>
                                <th class="px-4 py-3 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <tr v-for="d in devices" :key="d.id" class="hover:bg-gray-50/60 transition-colors">
                                <td class="px-4 py-3">
                                    <div class="flex items-center gap-3">
                                        <div class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-gray-100 text-gray-600">
                                            <Laptop class="size-4" />
                                        </div>
                                        <div>
                                            <div class="font-semibold text-gray-900 flex items-center gap-2">
                                                <span>{{ d.device_name }}</span>
                                                <span
                                                    v-if="d.is_current"
                                                    class="inline-flex items-center rounded-full bg-emerald-50 px-2 py-0.5 text-[10px] font-semibold text-emerald-700 border border-emerald-200"
                                                >
                                                    Current Device
                                                </span>
                                            </div>
                                            <span class="text-xs text-gray-400">Registered {{ d.created_at }}</span>
                                        </div>
                                    </div>
                                </td>

                                <td v-if="isAdmin" class="px-4 py-3">
                                    <div class="font-medium text-gray-900">{{ d.user.name }}</div>
                                    <div class="text-xs text-gray-400">{{ d.user.email }} ({{ d.user.role }})</div>
                                </td>

                                <td class="px-4 py-3 font-mono text-xs text-gray-700">
                                    {{ d.ip_address || '—' }}
                                </td>

                                <td class="px-4 py-3">
                                    <span
                                        v-if="d.status === 'approved'"
                                        class="inline-flex items-center gap-1.5 rounded-full bg-green-50 px-2.5 py-1 text-xs font-semibold text-green-700 border border-green-200"
                                    >
                                        <CheckCircle2 class="size-3.5" /> Approved
                                    </span>
                                    <span
                                        v-else-if="d.status === 'pending'"
                                        class="inline-flex items-center gap-1.5 rounded-full bg-amber-50 px-2.5 py-1 text-xs font-semibold text-amber-700 border border-amber-200"
                                    >
                                        <ShieldAlert class="size-3.5" /> Pending Verification
                                    </span>
                                    <span
                                        v-else
                                        class="inline-flex items-center gap-1.5 rounded-full bg-red-50 px-2.5 py-1 text-xs font-semibold text-red-700 border border-red-200"
                                    >
                                        <XCircle class="size-3.5" /> Revoked
                                    </span>
                                </td>

                                <td class="px-4 py-3 text-xs text-gray-500">
                                    {{ d.last_used_at }}
                                </td>

                                <td class="px-4 py-3 text-right">
                                    <div class="flex items-center justify-end gap-2">
                                        <button
                                            v-if="isAdmin && d.status !== 'approved'"
                                            type="button"
                                            @click="promptApproveDevice(d)"
                                            class="inline-flex items-center gap-1 rounded-lg px-2.5 py-1.5 text-xs font-semibold text-green-700 bg-green-50 hover:bg-green-100 border border-green-200 cursor-pointer"
                                        >
                                            <ShieldCheck class="size-3.5" /> Approve
                                        </button>

                                        <button
                                            v-if="d.status === 'approved'"
                                            type="button"
                                            @click="promptRevokeDevice(d)"
                                            class="inline-flex items-center gap-1 rounded-lg px-2.5 py-1.5 text-xs font-semibold text-amber-700 bg-amber-50 hover:bg-amber-100 border border-amber-200 cursor-pointer"
                                        >
                                            Revoke
                                        </button>

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

                            <tr v-if="devices.length === 0">
                                <td :colspan="isAdmin ? 6 : 5" class="px-4 py-12 text-center text-gray-400">
                                    No registered trusted devices found.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AppSidebarLayout>

    <!-- Action Confirmation Modal -->
    <div v-if="showConfirmModal" class="fixed inset-0 z-[60] flex items-center justify-center bg-black/60 p-4">
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

            <div v-if="confirmOptions.details && confirmOptions.details.length > 0" class="bg-gray-50 border border-gray-100 rounded-xl p-3.5 mb-4 space-y-2 text-xs">
                <div v-for="(item, idx) in confirmOptions.details" :key="idx" class="flex justify-between items-center py-0.5 border-b border-gray-100 last:border-b-0">
                    <span class="text-gray-500 font-medium">{{ item.label }}</span>
                    <span class="font-semibold text-gray-900 text-right">{{ item.value }}</span>
                </div>
            </div>

            <div v-if="confirmOptions.confirmVariant === 'destructive'" class="mt-2 mb-5">
                <label class="block text-xs font-semibold text-gray-700 mb-1.5">
                    Type <span class="font-mono font-semibold text-rose-600 bg-rose-50 px-1.5 py-0.5 rounded border border-rose-200">YES</span> if you wish to delete:
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
                    :disabled="confirmOptions.confirmVariant === 'destructive' && confirmActionInput.trim().toUpperCase() !== 'YES'"
                >
                    <Check v-if="confirmOptions.confirmVariant !== 'destructive'" class="w-4 h-4 mr-1.5 inline" />
                    <Trash2 v-else class="w-4 h-4 mr-1.5 inline" />
                    <span>{{ confirmOptions.confirmText || 'Confirm & Proceed' }}</span>
                </SpecularButton>
            </div>
        </div>
    </div>
</template>
