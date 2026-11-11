<script setup lang="ts">
/**
 * Full-page skeleton shown while Dashboard async data (charts, stats) loads.
 * Matches the Dashboard layout: greeting, stat cards, chart + activity feed.
 */
import { Skeleton } from '@/components/ui/skeleton';
import CardSkeleton from './CardSkeleton.vue';
</script>

<template>
    <div class="workspace-page animate-pulse">
        <!-- Header skeleton -->
        <div class="flex flex-col gap-2">
            <Skeleton class="h-7 w-48 rounded" />
            <Skeleton class="h-4 w-64 rounded" />
        </div>
        <!-- Action buttons -->
        <div class="flex gap-3">
            <Skeleton class="h-10 w-36 rounded-lg" />
            <Skeleton class="h-10 w-40 rounded-lg" />
        </div>
        <!-- Stat cards -->
        <div class="grid grid-cols-2 gap-3 xl:grid-cols-4">
            <CardSkeleton v-for="i in 4" :key="i" />
        </div>
        <!-- Chart + Activity -->
        <div class="grid gap-6 xl:grid-cols-[minmax(0,1.65fr)_minmax(320px,1fr)]">
            <div class="rounded-xl border border-gray-200 bg-white p-6">
                <Skeleton class="mb-4 h-5 w-56 rounded" />
                <div class="flex h-[280px] items-end gap-2 sm:h-[300px]">
                    <div v-for="i in 11" :key="i" class="flex-1 rounded-t bg-gray-100" :style="`height:${25 + Math.random() * 55}%`" />
                </div>
            </div>
            <div class="rounded-xl border border-gray-200 bg-white p-6">
                <Skeleton class="mb-4 h-5 w-40 rounded" />
                <div v-for="i in 4" :key="i" class="flex items-center gap-3 border-b border-gray-100 py-4">
                    <div class="flex-1">
                        <Skeleton class="mb-2 h-4 w-3/4 rounded" />
                        <Skeleton class="h-3 w-1/3 rounded" />
                    </div>
                    <Skeleton class="h-6 w-16 rounded-full" />
                </div>
            </div>
        </div>
    </div>
</template>
