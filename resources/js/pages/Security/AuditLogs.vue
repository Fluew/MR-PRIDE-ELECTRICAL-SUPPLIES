<script setup lang="ts">
import { Head, router, usePage, Link } from '@inertiajs/vue3';
import { ref } from 'vue';
import AppSidebarLayout from '@/layouts/app/AppSidebarLayout.vue';
import {
    ShieldAlert,
    Search,
    Filter,
    Calendar,
    UserCheck,
    Lock,
    Key,
    Laptop,
    LogOut,
    CheckCircle2,
    AlertCircle,
} from '@lucide/vue';

interface AuditLogItem {
    id: number;
    user: {
        id: number;
        name: string;
        email: string;
        role: string;
    } | null;
    user_email: string | null;
    event: string;
    description: string;
    ip_address: string | null;
    user_agent: string | null;
    metadata: Record<string, any> | null;
    created_at: string;
    time_ago: string;
}

const props = defineProps<{
    logs: {
        data: AuditLogItem[];
        current_page: number;
        last_page: number;
        total: number;
        links: Array<{ url: string | null; label: string; active: boolean }>;
    };
    filters: {
        search?: string;
        event?: string;
    };
    availableEvents: string[];
}>();

const page = usePage();
const searchQuery = ref(props.filters.search || '');
const selectedEvent = ref(props.filters.event || 'all');

let filterTimeout: ReturnType<typeof setTimeout> | null = null;
const applyFilters = () => {
    if (filterTimeout) clearTimeout(filterTimeout);
    filterTimeout = setTimeout(() => {
        const team = (page.props as any).currentTeam?.slug;
        router.get(
            `/${team}/security/audit-logs`,
            {
                search: searchQuery.value || undefined,
                event: selectedEvent.value !== 'all' ? selectedEvent.value : undefined,
            },
            { preserveState: true, preserveScroll: true, replace: true }
        );
    }, 300);
};

const getEventColor = (event: string) => {
    if (event.includes('failed') || event.includes('revoked') || event.includes('denied')) {
        return 'bg-red-50 text-red-700 border-red-200';
    }
    if (event.includes('changed') || event.includes('recovered')) {
        return 'bg-amber-50 text-amber-700 border-amber-200';
    }
    return 'bg-emerald-50 text-emerald-700 border-emerald-200';
};
</script>

<template>
    <AppSidebarLayout :breadcrumbs="[{ title: 'Security', href: '#' }, { title: 'Audit Logs', href: '#' }]">
        <Head title="Security Audit Logs | MR-Pride" />

        <div class="workspace-page">
            <!-- Header Section -->
            <div class="page-header">
                <div>
                    <h1 class="page-title">
                        Security Audit Logs
                    </h1>
                    <p class="page-subtitle">
                        Sign-in, access, device and permission events.
                    </p>
                </div>
            </div>

            <!-- Filters Bar -->
            <div class="flex flex-col sm:flex-row items-center gap-3 rounded-xl border border-gray-200 bg-white p-4">
                <div class="relative flex-1 w-full">
                    <Search class="size-4 absolute left-3.5 top-1/2 -translate-y-1/2 text-gray-400" />
                    <input
                        v-model="searchQuery"
                        @input="applyFilters"
                        type="text"
                        placeholder="Search logs by email, IP address, description..."
                        class="field-control w-full pl-10 pr-4"
                    />
                </div>

                <div class="w-full sm:w-64">
                    <select
                        v-model="selectedEvent"
                        @change="applyFilters"
                        class="field-control w-full cursor-pointer"
                    >
                        <option value="all">All Security Events</option>
                        <option v-for="evt in availableEvents" :key="evt" :value="evt">
                            {{ evt }}
                        </option>
                    </select>
                </div>
            </div>

            <!-- Audit Logs Table -->
            <div class="rounded-xl border border-gray-200 bg-white overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm text-gray-600">
                        <thead class="bg-gray-50/80 border-b border-gray-200 text-xs font-semibold text-gray-700">
                            <tr>
                                <th class="px-4 py-3">Timestamp</th>
                                <th class="px-4 py-3">Event Type</th>
                                <th class="px-4 py-3">Account Identifier</th>
                                <th class="px-4 py-3">Description</th>
                                <th class="px-4 py-3">IP Address</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 font-mono text-xs">
                            <tr v-for="log in logs.data" :key="log.id" class="hover:bg-gray-50/60 transition-colors">
                                <td class="px-4 py-3 whitespace-nowrap text-gray-500 font-sans">
                                    <div class="font-medium text-gray-900">{{ log.created_at }}</div>
                                    <div class="text-[11px] text-gray-400">{{ log.time_ago }}</div>
                                </td>

                                <td class="px-4 py-3 whitespace-nowrap">
                                    <span
                                        class="inline-flex items-center rounded-md px-2.5 py-1 text-[11px] font-semibold border"
                                        :class="getEventColor(log.event)"
                                    >
                                        {{ log.event }}
                                    </span>
                                </td>

                                <td class="px-4 py-3 whitespace-nowrap font-sans text-xs">
                                    <div v-if="log.user" class="font-semibold text-gray-900">
                                        {{ log.user.name }}
                                        <span class="text-[10px] text-gray-400">({{ log.user.role }})</span>
                                    </div>
                                    <div class="text-gray-500">{{ log.user_email || '—' }}</div>
                                </td>

                                <td class="px-4 py-3 font-sans text-xs text-gray-800 max-w-md">
                                    {{ log.description }}
                                </td>

                                <td class="px-4 py-3 whitespace-nowrap text-gray-600 font-mono text-[11px]">
                                    {{ log.ip_address || '—' }}
                                </td>
                            </tr>

                            <tr v-if="logs.data.length === 0">
                                <td colspan="5" class="px-4 py-12 text-center text-gray-400 font-sans">
                                    No audit log entries matching criteria.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <div v-if="logs.last_page > 1" class="border-t border-gray-200 px-4 py-3 flex items-center justify-between text-xs text-gray-500">
                    <div>Showing {{ logs.data.length }} of {{ logs.total }} security events</div>
                    <div class="flex items-center gap-1">
                        <Link
                            v-for="(link, i) in logs.links"
                            :key="i"
                            :href="link.url || '#'"
                            class="px-3 py-1.5 rounded-lg border text-xs font-semibold transition-colors"
                            :class="[
                                link.active
                                    ? 'bg-[#4b5320] text-white border-[#4b5320]'
                                    : link.url
                                      ? 'bg-white text-gray-700 border-gray-200 hover:bg-gray-50'
                                      : 'bg-gray-50 text-gray-300 border-gray-100 pointer-events-none'
                            ]"
                            v-html="link.label"
                        />
                    </div>
                </div>
            </div>
        </div>
    </AppSidebarLayout>
</template>
