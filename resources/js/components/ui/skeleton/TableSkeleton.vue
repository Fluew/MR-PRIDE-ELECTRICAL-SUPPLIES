<script setup lang="ts">
import { Skeleton } from '@/components/ui/skeleton';

withDefaults(
    defineProps<{
        rows?: number;
        columns?: number;
    }>(),
    { rows: 5, columns: 4 },
);
</script>

<template>
    <div class="w-full animate-pulse">
        <!-- Table header -->
        <div class="flex gap-4 border-b border-gray-200 px-4 py-3">
            <Skeleton v-for="c in columns" :key="'h' + c" class="h-4 flex-1 rounded" />
        </div>
        <!-- Table rows -->
        <div v-for="r in rows" :key="r" class="flex items-center gap-4 border-b border-gray-100 px-4 py-3.5">
            <Skeleton v-for="c in columns" :key="'r' + r + 'c' + c" class="h-3.5 flex-1 rounded" />
        </div>
    </div>
</template>
