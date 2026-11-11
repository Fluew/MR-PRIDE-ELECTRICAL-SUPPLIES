<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import { ref } from 'vue';
import AppSidebarLayout from '@/layouts/app/AppSidebarLayout.vue';
import SpecularButton from '@/components/ui/SpecularButton.vue';
import {
    Activity,
    Laptop,
    Trash2,
    ShieldAlert,
    LogOut,
    AlertTriangle,
    CheckCircle2,
    Check,
} from '@lucide/vue';

interface SessionItem {
    id: string;
    user: {
        id: number;
        name: string;
        email: string;
        role: string;
    };
    ip_address: string;
    device_name: string;
    is_current: boolean;
    last_activity: string;
}

const props = defineProps<{
    sessions: SessionItem[];
    isAdmin: boolean;
}>();

const page = usePage();

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
    confirmVariant: 'destructive',
    action: () => {},
});

const openConfirmModal = (options: ConfirmActionOptions) => {
    confirmActionInput.value = '';
    confirmOptions.value = {
        confirmText: 'Confirm & Proceed',
        confirmVariant: 'destructive',
        ...options,
    };
    showConfirmModal.value = true;
};

const handleConfirmedAction = () => {
    showConfirmModal.value = false;
    confirmOptions.value.action();
};

const promptRevokeSession = (session: SessionItem) => {
    openConfirmModal({
        title: 'Revoke Active Session',
        description: `Are you sure you want to terminate this session? The device will be signed out immediately.`,
        details: [
            { label: 'Device', value: session.device_name },
            { label: 'Account Holder', value: session.user.name },
            { label: 'IP Address', value: session.ip_address },
        ],
        confirmText: 'Revoke Session',
        confirmVariant: 'destructive',
        action: () => {
            const team = (page.props as any).currentTeam?.slug;
            router.delete(`/${team}/security/sessions/${session.id}`, {
                preserveScroll: true,
            });
        },
    });
};

const promptRevokeOtherSessions = () => {
    openConfirmModal({
        title: 'Revoke All Other Sessions',
        description: 'Terminate all active sessions across other workstations and devices? You will remain signed in on this current browser.',
        details: [
            { label: 'Scope', value: 'All devices except this workstation' },
        ],
        confirmText: 'Terminate Other Sessions',
        confirmVariant: 'destructive',
        action: () => {
            const team = (page.props as any).currentTeam?.slug;
            router.post(`/${team}/security/sessions/revoke-others`, {}, {
                preserveScroll: true,
            });
        },
    });
};
</script>

<template>
    <AppSidebarLayout :breadcrumbs="[{ title: 'Security', href: '#' }, { title: 'Active Sessions', href: '#' }]">
        <Head title="Active Sessions | MR-Pride" />

        <div class="workspace-page">
            <!-- Header Section -->
            <div class="page-header">
                <div>
                    <h1 class="page-title">
                        Active Sessions
                    </h1>
                    <p class="page-subtitle">
                        Monitor active browser sessions and revoke unauthorized or stale logins.
                    </p>
                </div>

                <div class="page-actions">
                    <SpecularButton
                        type="button"
                        variant="danger"
                        size="md"
                        :radius="12"
                        @click="promptRevokeOtherSessions"
                    >
                        <LogOut class="size-4 mr-1.5 inline" />
                        Sign Out All Other Sessions
                    </SpecularButton>
                </div>
            </div>

            <!-- Sessions Table -->
            <div class="rounded-xl border border-gray-200 bg-white overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm text-gray-600">
                        <thead class="bg-gray-50/80 border-b border-gray-200 text-xs font-semibold text-gray-700">
                            <tr>
                                <th class="px-4 py-3">Workstation / Device</th>
                                <th v-if="isAdmin" class="px-4 py-3">Account</th>
                                <th class="px-4 py-3">IP Address</th>
                                <th class="px-4 py-3">Last Activity</th>
                                <th class="px-4 py-3 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <tr v-for="s in sessions" :key="s.id" class="hover:bg-gray-50/60 transition-colors">
                                <td class="px-4 py-3">
                                    <div class="flex items-center gap-3">
                                        <div class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-gray-100 text-gray-600">
                                            <Laptop class="size-4" />
                                        </div>
                                        <div>
                                            <div class="font-semibold text-gray-900 flex items-center gap-2">
                                                <span>{{ s.device_name }}</span>
                                                <span
                                                    v-if="s.is_current"
                                                    class="inline-flex items-center rounded-full bg-emerald-50 px-2 py-0.5 text-[10px] font-semibold text-emerald-700 border border-emerald-200"
                                                >
                                                    Current Session
                                                </span>
                                            </div>
                                            <span class="text-xs text-gray-400 font-mono">{{ s.id.substring(0, 16) }}...</span>
                                        </div>
                                    </div>
                                </td>

                                <td v-if="isAdmin" class="px-4 py-3">
                                    <div class="font-medium text-gray-900">{{ s.user.name }}</div>
                                    <div class="text-xs text-gray-400">{{ s.user.email }} ({{ s.user.role }})</div>
                                </td>

                                <td class="px-4 py-3 font-mono text-xs text-gray-700">
                                    {{ s.ip_address || '—' }}
                                </td>

                                <td class="px-4 py-3 text-xs text-gray-500">
                                    {{ s.last_activity }}
                                </td>

                                <td class="px-4 py-3 text-right">
                                    <button
                                        v-if="!s.is_current"
                                        type="button"
                                        @click="promptRevokeSession(s)"
                                        class="inline-flex items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-xs font-semibold text-red-700 bg-red-50 hover:bg-red-100 border border-red-200 cursor-pointer"
                                    >
                                        <Trash2 class="size-3.5" /> Revoke
                                    </button>
                                    <span v-else class="text-xs font-semibold text-gray-400 italic">
                                        Current Device
                                    </span>
                                </td>
                            </tr>

                            <tr v-if="sessions.length === 0">
                                <td :colspan="isAdmin ? 5 : 4" class="px-4 py-12 text-center text-gray-400">
                                    No active sessions recorded.
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
                    :variant="confirmOptions.confirmVariant || 'destructive'"
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
