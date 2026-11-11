<script setup lang="ts">
import LineSidebar from '@/components/vue-bits/LineSidebar.vue';
import type { NavItem } from '@/types';

withDefaults(defineProps<{ items: NavItem[]; label?: string }>(), {
    label: 'Operations',
});
</script>

<template>
    <div class="px-3 py-2 transition-all duration-300 ease-in-out group-data-[collapsible=icon]:px-1.5 group-data-[collapsible=icon]:py-2">
        <LineSidebar :items="items" :label="label" />
    </div>
</template>
