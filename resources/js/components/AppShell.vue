<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { SidebarProvider } from '@/components/ui/sidebar';
import type { AppVariant } from '@/types';

type Props = {
    variant?: AppVariant;
};

withDefaults(defineProps<Props>(), {
    variant: 'sidebar',
});

const isOpen = usePage().props.sidebarOpen;
</script>

<template>
    <div class="min-h-screen w-full bg-[#f7f8f5] text-gray-900 font-sans selection:bg-[#F8B803]/40 selection:text-gray-900">
        <div v-if="variant === 'header'" class="flex min-h-screen w-full flex-col">
            <slot />
        </div>
        <SidebarProvider v-else :default-open="isOpen">
            <slot />
        </SidebarProvider>
    </div>
</template>
