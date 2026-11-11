<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { Wrench, Clock, ShieldCheck } from '@lucide/vue';

defineProps<{
    title?: string;
    message?: string;
    reason?: string;
    scheduledStartAt?: string | null;
    expectedReturnTime?: string | null;
    expectedEndAt?: string | null;
    enabledAt?: string | null;
}>();
</script>

<template>
    <Head title="System Under Maintenance - MRP ElectroDesk" />

    <div class="min-h-screen bg-gray-50 flex items-center justify-center p-4 sm:p-6 font-sans">
        <div class="w-full max-w-xl">
            <!-- Main Maintenance Card -->
            <div class="bg-white border border-gray-200/90 rounded-xl p-6 sm:p-10 shadow-xl text-center relative overflow-hidden">
                <!-- Top Accent Line -->
                <div class="absolute top-0 left-0 right-0 h-1 bg-[#4b5320]"></div>

                <!-- Wrench/Shield Icon -->
                <div class="mx-auto size-20 rounded-xl bg-amber-50 border border-amber-200/80 flex items-center justify-center text-amber-600 mb-6">
                    <Wrench class="size-9" />
                </div>

                <!-- Required Titles -->
                <h1 class="text-2xl sm:text-3xl font-semibold text-gray-900 tracking-tight">
                    {{ title || 'System Under Maintenance' }}
                </h1>
                <p class="text-sm sm:text-base text-gray-600 mt-3 font-medium leading-relaxed max-w-md mx-auto">
                    {{ message || 'MRP ElectroDesk is temporarily unavailable while we perform system maintenance.' }}
                </p>

                <!-- Maintenance Reason -->
                <div v-if="reason" class="mt-5 mx-auto max-w-md rounded-xl bg-gray-50 border border-gray-200/80 px-4 py-3 text-xs text-gray-700">
                    <span class="font-semibold text-gray-900">Maintenance Notice:</span>
                    <span class="ml-1">{{ reason }}</span>
                </div>

                <!-- Expected Return Time Badge -->
                <div v-if="expectedReturnTime" class="mt-5 inline-flex items-center gap-2 px-4 py-2 bg-amber-50 border border-amber-200 rounded-full text-xs font-semibold text-amber-900">
                    <Clock class="size-4 text-amber-600 shrink-0" />
                    <span>Expected Return: <strong class="text-amber-950 font-semibold">{{ expectedReturnTime }}</strong></span>
                </div>

                <!-- Controlled Maintenance Procedure Active -->
                <div class="mt-5 flex justify-center">
                    <div class="inline-flex items-center gap-2 px-3.5 py-1.5 rounded-full bg-gray-50 border border-gray-200/80 text-gray-700 font-semibold text-xs">
                        <ShieldCheck class="size-3.5 text-[#4b5320]" />
                        <span>Controlled Maintenance Procedure Active</span>
                    </div>
                </div>
            </div>

            <!-- Footer Branding -->
            <p class="text-center text-xs text-gray-400 mt-6 font-medium">
                MRP ElectroDesk
            </p>
        </div>
    </div>
</template>
