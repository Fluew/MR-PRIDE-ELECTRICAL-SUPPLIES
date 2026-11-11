<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { Archive, FileText, Shield, ShieldAlert, User, Wrench } from '@lucide/vue';
import { computed } from 'vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { toUrl } from '@/lib/utils';
import { edit as editProfile } from '@/routes/profile';
import { edit as editSecurity } from '@/routes/security';
import type { NavItem } from '@/types';

const page = usePage();
const isAdmin = computed(() => Boolean((page.props as any).auth?.is_admin));

const sidebarNavItems: NavItem[] = [
    {
        title: 'Profile',
        href: editProfile(),
        icon: User,
    },
    {
        title: 'Security',
        href: editSecurity(),
        icon: Shield,
    },
];

const maintenanceNavItems = [
    {
        key: 'maintenance',
        title: 'Maintenance Mode',
        href: '/settings/maintenance',
        icon: Wrench,
    },
    {
        key: 'recycle-bin',
        title: 'Recycle Bin',
        href: '/settings/maintenance/recycle-bin',
        icon: Archive,
    },
    {
        key: 'security-logs',
        title: 'Security Logs',
        href: '/settings/maintenance/security-logs',
        icon: ShieldAlert,
    },
    {
        key: 'audit-trail',
        title: 'System Audit Trail',
        href: '/settings/maintenance/audit-trail',
        icon: FileText,
    },
];

const { isCurrentOrParentUrl } = useCurrentUrl();

const currentFullUrl = computed(() => String(page.url || ''));
const currentPath = computed(() => {
    try {
        return new URL(currentFullUrl.value, 'http://localhost').pathname;
    } catch {
        return currentFullUrl.value.split('?')[0] || '';
    }
});

const isMaintenanceItemActive = (key: string): boolean => {
    const path = currentPath.value;
    const url = currentFullUrl.value;

    if (key === 'maintenance') {
        return path === '/settings/maintenance';
    }
    if (key === 'recycle-bin') {
        return path === '/settings/maintenance/recycle-bin' || path.endsWith('/recycle-bin');
    }
    if (key === 'security-logs') {
        return path === '/settings/maintenance/security-logs' || (path.endsWith('/users') && url.includes('tab=security'));
    }
    if (key === 'audit-trail') {
        return path === '/settings/maintenance/audit-trail' || (path.endsWith('/users') && url.includes('tab=audit'));
    }
    return false;
};

const isMaintenanceSectionActive = computed(() =>
    maintenanceNavItems.some((item) => isMaintenanceItemActive(item.key)),
);
</script>

<template>
    <div class="workspace-page">
        <Heading
            title="Settings"
            description="Manage your profile, account security, and system maintenance"
        />

        <div class="flex flex-col lg:flex-row lg:space-x-10">
            <aside class="w-full max-w-xl lg:w-56 shrink-0">
                <nav
                    class="flex flex-col space-y-1 space-x-0"
                    aria-label="Settings"
                >
                    <Button
                        v-for="item in sidebarNavItems"
                        :key="toUrl(item.href)"
                        variant="ghost"
                        :class="[
                            'w-full justify-start gap-2',
                            { 'bg-muted font-semibold': isCurrentOrParentUrl(item.href) },
                        ]"
                        as-child
                    >
                        <Link :href="item.href">
                            <component :is="item.icon" class="h-4 w-4 shrink-0" />
                            {{ item.title }}
                        </Link>
                    </Button>

                    <!-- Admin-only System Maintenance Section -->
                    <template v-if="isAdmin">
                        <Separator class="my-3" />

                        <div class="px-3 pt-1 pb-1.5 flex items-center gap-2 text-xs font-semibold uppercase tracking-wider text-gray-500">
                            <Wrench class="h-3.5 w-3.5 text-[#4b5320]" />
                            <span>System Maintenance</span>
                        </div>

                        <div class="flex flex-col space-y-1 pl-2 border-l border-gray-200 ml-3">
                            <Button
                                v-for="subItem in maintenanceNavItems"
                                :key="subItem.key"
                                variant="ghost"
                                :class="[
                                    'w-full justify-start gap-2 text-sm',
                                    { 'bg-muted font-semibold text-gray-900': isMaintenanceItemActive(subItem.key) },
                                ]"
                                as-child
                            >
                                <Link :href="subItem.href">
                                    <component :is="subItem.icon" class="h-4 w-4 shrink-0" />
                                    {{ subItem.title }}
                                </Link>
                            </Button>
                        </div>
                    </template>
                </nav>
            </aside>

            <Separator class="my-6 lg:hidden" />

            <div v-if="isMaintenanceSectionActive" class="flex-1 min-w-0">
                <slot />
            </div>
            <div v-else class="flex-1 md:max-w-2xl">
                <section class="max-w-xl space-y-12">
                    <slot />
                </section>
            </div>
        </div>
    </div>
</template>
