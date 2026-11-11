<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed, shallowRef } from 'vue';
import PendingInvitationsModal from '@/components/PendingInvitationsModal.vue';
import { useCurrency } from '@/composables/useCurrency';
import { dashboard } from '@/routes';
import { index as quotationsRoute } from '@/routes/admin/quotations';
import { index as inventoryRoute } from '@/routes/inventory';
import type { DashboardInvitation, Team } from '@/types';
// Chart.js loads on demand (shared with Reports and Forecast).
import { LineChart } from '@/lib/lazyCharts';


const chartOptions = shallowRef({
    responsive: true,
    maintainAspectRatio: false,
    animation: { duration: 400 },
    plugins: {
        legend: { labels: { color: '#333' } },
    },
    scales: {
        x: { ticks: { color: '#666' }, grid: { color: '#eee' } },
        y: { ticks: { color: '#666' }, grid: { color: '#eee' } },
    },
});

interface ActivityItem {
    id: string;
    type: string;
    status: string;
    class: string;
}

interface DashboardStats {
    totalProducts?: number;
    lowStockCount?: number;
    todaySales?: number;
    topSupplier?: {
        name: string;
        score: string;
    };
}

interface ForecastSeries {
    labels: string[];
    historical: number[];
    forecast: (number | null)[];
    window: number;
    hasData: boolean;
    sourceRecordCount: number;
}

const props = defineProps<{
    pendingInvitations?: DashboardInvitation[];
    stats?: DashboardStats;
    recentActivity?: ActivityItem[];
    forecastSeries?: ForecastSeries | null;
    isFirstLogin?: boolean;
}>();

const chartData = computed(() => ({
    labels: props.forecastSeries?.labels ?? ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'],
    datasets: [
        {
            label: 'Historical Demand (Units)',
            backgroundColor: '#4ade80',
            borderColor: '#4ade80',
            data: props.forecastSeries?.historical ?? [],
            tension: 0.3,
        },
        {
            label: `Forecast (SMA-${props.forecastSeries?.window ?? 3})`,
            backgroundColor: '#F8B803',
            borderColor: '#F8B803',
            borderDash: [5, 5],
            data: props.forecastSeries?.forecast ?? [],
            tension: 0.3,
        },
    ],
}));

const page = usePage();
const currentUser = computed(() => (page.props as any).auth?.user);

const firstName = computed(() => {
    if (currentUser.value?.first_name) {
        return currentUser.value.first_name;
    }
    const name = currentUser.value?.name?.trim() || '';
    if (!name) return 'User';
    return name.split(' ')[0] || 'User';
});

const isFirstTimeLogin = computed(() => {
    if (props.isFirstLogin !== undefined) {
        return Boolean(props.isFirstLogin);
    }
    return Boolean((page.props as any).auth?.is_first_login);
});

const { formatMoney } = useCurrency();
const formatCurrency = (val?: number) => formatMoney(val, { decimals: 0 });

const displayActivities = computed(() => {
    if (props.recentActivity && props.recentActivity.length > 0) {
        return props.recentActivity;
    }

    return [
        {
            id: 'No recent activity',
            type: 'System',
            status: 'Idle',
            class: 'bg-gray-500/10 text-gray-500 border-gray-500/20',
        },
    ];
});

defineOptions({
    layout: (props: { currentTeam?: Team | null }) => ({
        breadcrumbs: [
            {
                title: 'Dashboard',
                href: props.currentTeam
                    ? dashboard(props.currentTeam.slug)
                    : '/',
            },
        ],
    }),
});
</script>

<template>
    <Head title="Dashboard" />

    <PendingInvitationsModal
        v-if="pendingInvitations && pendingInvitations.length > 0"
        :invitations="pendingInvitations"
    />

    <div class="workspace-page">
        <!-- Dashboard Header -->
        <div class="page-header">
            <div>
                <h1 class="page-title">Hello, {{ firstName }}!</h1>
                <p class="page-subtitle">
                    <template v-if="isFirstTimeLogin">
                        Welcome to MRP ElectroDesk. Let's get started.
                    </template>
                    <template v-else>
                        Welcome back to MRP ElectroDesk.
                    </template>
                </p>
            </div>

            <div v-if="$page.props.currentTeam" class="page-actions">
                <Link
                    :href="inventoryRoute($page.props.currentTeam.slug)"
                    class="brand-button"
                    >Manage inventory</Link
                ><Link
                    :href="quotationsRoute($page.props.currentTeam.slug)"
                    class="secondary-button"
                    >Review quotations</Link
                >
            </div>
        </div>
        <!-- Stat Cards -->
        <div class="grid grid-cols-2 gap-3 xl:grid-cols-4">
            <div class="rounded-xl border border-gray-200 bg-white px-4 py-3">
                <p class="text-xs text-gray-500">Total products</p>
                <p class="mt-1 text-2xl font-semibold text-gray-900">{{ stats?.totalProducts?.toLocaleString() ?? 0 }}</p>
            </div>
            <div class="rounded-xl border border-gray-200 bg-white px-4 py-3">
                <p class="text-xs text-gray-500">Low stock items</p>
                <p :class="(stats?.lowStockCount ?? 0) > 0 ? 'text-red-600' : 'text-gray-900'" class="mt-1 text-2xl font-semibold">
                    {{ stats?.lowStockCount?.toLocaleString() ?? 0 }}
                </p>
            </div>
            <div class="rounded-xl border border-gray-200 bg-white px-4 py-3">
                <p class="text-xs text-gray-500">Today's sales</p>
                <p class="mt-1 text-2xl font-semibold text-gray-900">{{ formatCurrency(stats?.todaySales) }}</p>
            </div>
            <div class="rounded-xl border border-gray-200 bg-white px-4 py-3">
                <p class="text-xs text-gray-500">Top supplier (AHP)</p>
                <p class="mt-1 truncate text-lg font-semibold text-gray-900">{{ stats?.topSupplier?.name ?? 'No supplier yet' }}</p>
                <p v-if="stats?.topSupplier?.score" class="text-xs text-gray-500">Score {{ stats.topSupplier.score }}</p>
            </div>
        </div>

        <div
            class="grid gap-6 xl:grid-cols-[minmax(0,1.65fr)_minmax(320px,1fr)]"
        >
            <!-- Chart Area — lazy-loaded via Suspense, skeleton shown while loading -->
            <div
                class="min-w-0 rounded-xl border border-gray-200 bg-white p-6"
            >
                <div class="mb-4 flex flex-wrap items-baseline justify-between gap-2">
                    <h3 class="text-base font-semibold text-gray-900">
                        Sales &amp; Demand Forecast (SMA)
                    </h3>
                    <span v-if="forecastSeries && !forecastSeries.hasData" class="text-xs text-amber-700">
                        No recorded sales or outgoing stock movements yet
                    </span>
                </div>
                <div class="h-[280px] w-full sm:h-[300px]">
                    <Suspense>
                        <LineChart :data="chartData" :options="chartOptions" />
                        <template #fallback>
                            <div
                                class="flex h-full animate-pulse flex-col justify-end gap-3 pb-4"
                            >
                                <div class="flex h-full items-end gap-2">
                                    <div
                                        v-for="i in 11"
                                        :key="i"
                                        class="flex-1 rounded-t bg-gray-200"
                                        :style="`height:${30 + Math.random() * 60}%`"
                                    ></div>
                                </div>
                                <div class="flex gap-2">
                                    <div
                                        v-for="i in 11"
                                        :key="i"
                                        class="h-2 flex-1 rounded bg-gray-100"
                                    ></div>
                                </div>
                            </div>
                        </template>
                    </Suspense>
                </div>
            </div>

            <!-- Recent Activity Feed -->
            <div
                class="max-h-[400px] overflow-y-auto rounded-xl border border-gray-200 bg-white p-6"
            >
                <h3 class="mb-4 text-base font-semibold text-gray-900">
                    Recent Activity Feed
                </h3>
                <ul class="divide-y divide-gray-100">
                    <li
                        v-for="(activity, idx) in displayActivities"
                        :key="activity.id || idx"
                        class="flex items-center justify-between gap-3 py-4"
                    >
                        <div class="min-w-0">
                            <p
                                class="text-sm font-semibold break-words text-gray-900"
                            >
                                {{ activity.id }}
                            </p>
                            <p class="mt-1 text-xs text-gray-500">
                                {{ activity.type }}
                            </p>
                        </div>
                        <span
                            :class="activity.class"
                            class="shrink-0 rounded-full border px-2.5 py-1 text-xs font-medium"
                            >{{ activity.status }}</span
                        >
                    </li>
                </ul>
            </div>
        </div>
    </div>
</template>
