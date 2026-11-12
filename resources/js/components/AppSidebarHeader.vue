<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { ArrowUpRight } from '@lucide/vue';
import { computed } from 'vue';
import Breadcrumbs from '@/components/Breadcrumbs.vue';
import CurrencyToggle from '@/components/CurrencyToggle.vue';
import NotificationBell from '@/components/NotificationBell.vue';
import { SidebarTrigger } from '@/components/ui/sidebar';
import { home } from '@/routes';
import type { BreadcrumbItem } from '@/types';
withDefaults(defineProps<{ breadcrumbs?: BreadcrumbItem[] }>(), {
    breadcrumbs: () => [],
});
const page = usePage();
const section = computed(() => {
    const titles: Record<string, string> = {
        Dashboard: 'Overview',
        Inventory: 'Inventory',
        Suppliers: 'Suppliers',
        Quotations: 'Quotations',
        Procurement: 'Procurement',
        Deliveries: 'Deliveries',
        Sales: 'Sales',
        Forecast: 'Demand Forecasting',
        Reports: 'Reports & Analytics',
        Users: 'User Management',
        RecycleBin: 'Recycle Bin',
        Security: 'Security Administration',
        Inquiries: 'Inquiries',
        Products: 'Product Catalog',
        settings: 'Settings',
        teams: 'Teams',
    };

    return titles[page.component.split('/')[0]] ?? 'Workspace';
});
</script>
<template>
    <header
        class="sticky top-0 z-30 flex min-h-16 shrink-0 items-center justify-between gap-3 border-b border-gray-200/80 bg-white/95 px-4 backdrop-blur-sm sm:px-6 lg:px-8"
    >
        <div class="flex min-w-0 items-center gap-3">
            <SidebarTrigger
                aria-label="Toggle workspace navigation"
                class="size-10 rounded-xl text-gray-600 hover:bg-gray-100"
            /><span aria-hidden="true" class="h-5 w-px bg-gray-200"></span
            ><Breadcrumbs
                v-if="breadcrumbs.length"
                :breadcrumbs="breadcrumbs"
            /><span
                v-else
                class="truncate text-sm font-semibold text-gray-700"
                >{{ section }}</span
            >
        </div>
        <div class="flex shrink-0 items-center gap-1 sm:gap-3">
            <CurrencyToggle v-if="page.props.currency" />
            <NotificationBell v-if="page.props.currentTeam" />
            <span class="hidden max-w-40 truncate text-xs font-medium text-gray-500 xl:block">{{
                page.props.currentTeam?.name ?? 'MR-Pride'
            }}</span
            ><Link
                :href="home()"
                class="inline-flex items-center gap-2 rounded-lg px-3 py-2 text-xs font-semibold text-[#4b5320] transition-colors hover:bg-[#4b5320]/10"
                ><span class="hidden sm:inline">Public website</span
                ><ArrowUpRight class="size-4" aria-hidden="true" /><span
                    class="sr-only sm:hidden"
                    >Public website</span
                ></Link
            >
        </div>
    </header>
</template>
