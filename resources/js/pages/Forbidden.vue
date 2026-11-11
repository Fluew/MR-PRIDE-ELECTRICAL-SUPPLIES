<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ArrowLeft, ShieldAlert } from '@lucide/vue';

defineProps<{
    message: string;
    homeUrl: string;
}>();

const goBack = () => window.history.back();
</script>

<template>
    <Head title="Access Denied" />

    <!-- Standalone page: no public or dashboard layout, so it is safe to show to anyone. -->
    <main
        aria-labelledby="forbidden-heading"
        class="relative flex min-h-screen items-center justify-center bg-gray-50 px-4 py-16 sm:px-6 dark:bg-neutral-950"
    >
        <div class="pointer-events-none absolute inset-0 -z-0 flex items-center justify-center overflow-hidden" aria-hidden="true">
            <div class="h-[360px] w-[500px] rounded-full bg-gradient-to-tr from-[#4b5320]/15 via-[#F8B803]/10 to-transparent blur-3xl dark:from-[#4b5320]/25 dark:via-[#F8B803]/15"></div>
        </div>

        <div class="relative mx-auto w-full max-w-xl text-center">
            <div class="inline-flex items-center gap-2 rounded-full border border-gray-200/80 bg-white/80 px-3.5 py-1.5 text-xs font-semibold text-[#4b5320] dark:border-neutral-800 dark:bg-neutral-900/80 dark:text-[#F8B803]">
                <ShieldAlert class="size-3.5" aria-hidden="true" />
                <span>Error 403</span>
            </div>

            <h1 id="forbidden-heading" class="mt-6 text-3xl font-semibold tracking-tight text-gray-900 sm:text-4xl dark:text-white">
                Access denied
            </h1>

            <p class="mx-auto mt-4 max-w-md text-base leading-relaxed text-gray-600 sm:text-lg dark:text-neutral-300">
                {{ message }}
            </p>

            <div class="mt-8 flex flex-col items-center justify-center gap-3 sm:flex-row">
                <Link
                    :href="homeUrl"
                    class="inline-flex w-full items-center justify-center gap-2 rounded-xl bg-[#4b5320] px-6 py-3.5 text-sm font-semibold text-white shadow-md transition-colors duration-150 hover:bg-[#3d441a] focus:outline-hidden focus:ring-2 focus:ring-[#4b5320] focus:ring-offset-2 sm:w-auto dark:focus:ring-offset-neutral-900"
                >
                    <span>Go to my workspace</span>
                </Link>

                <button
                    type="button"
                    @click="goBack"
                    class="inline-flex w-full items-center justify-center gap-2 rounded-xl border border-gray-300 bg-white px-6 py-3.5 text-sm font-semibold text-gray-700 transition-colors duration-150 hover:bg-gray-50 hover:text-gray-900 sm:w-auto dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200 dark:hover:bg-neutral-800 dark:hover:text-white"
                >
                    <ArrowLeft class="size-4" aria-hidden="true" />
                    <span>Go back</span>
                </button>
            </div>
        </div>
    </main>
</template>
