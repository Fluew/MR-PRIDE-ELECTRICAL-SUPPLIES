<script setup lang="ts">
import AppContent from '@/components/AppContent.vue';
import AppShell from '@/components/AppShell.vue';
import AppSidebar from '@/components/AppSidebar.vue';
import AppSidebarHeader from '@/components/AppSidebarHeader.vue';
import { Toaster } from '@/components/ui/sonner';
import { useFlashToast } from '@/composables/useFlashToast';
import type { BreadcrumbItem } from '@/types';
import { usePage } from '@inertiajs/vue3';

const page = usePage();

type Props = {
    breadcrumbs?: BreadcrumbItem[];
};

withDefaults(defineProps<Props>(), {
    breadcrumbs: () => [],
});

// Globally bridge Inertia flash → vue-sonner toasts for all authenticated pages
useFlashToast();
</script>

<template>
    <AppShell variant="sidebar">
        <a href="#workspace-content" class="skip-link">Skip to workspace</a>
        <AppSidebar />
        <AppContent variant="sidebar" class="admin-workspace min-w-0 bg-[#f7f8f5]">
            <AppSidebarHeader :breadcrumbs="breadcrumbs" />
            <div
                id="workspace-content"
                :key="page.component"
                tabindex="-1"
                class="page-enter flex min-w-0 flex-1 flex-col focus:outline-none"
            >
                <slot />
            </div>
        </AppContent>
        <Toaster />
    </AppShell>
</template>
