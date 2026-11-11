<script setup lang="ts">
import { Skeleton } from '@/components/ui/skeleton';
</script>

<template>
    <div class="animate-pulse rounded-xl border border-gray-200 bg-white p-4">
        <Skeleton class="mb-2 h-3 w-24 rounded" />
        <Skeleton class="h-7 w-20 rounded" />
        <slot />
    </div>
</template>
