<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ArrowLeft, Home, Compass } from '@lucide/vue';
import PublicLayout from '@/layouts/PublicLayout.vue';
</script>

<template>
    <Head title="Page Not Found" />

    <PublicLayout>
        <section
            aria-labelledby="not-found-heading"
            class="relative flex min-h-[calc(100vh-5rem-16rem)] items-center justify-center px-4 py-16 sm:px-6 sm:py-24 lg:px-8"
        >
            <!-- Ambient Background Glow matching MR-Pride brand colors -->
            <div
                class="pointer-events-none absolute inset-0 -z-10 flex items-center justify-center overflow-hidden"
                aria-hidden="true"
            >
                <div
                    class="h-[360px] w-[500px] rounded-full bg-gradient-to-tr from-[#4b5320]/15 via-[#F8B803]/10 to-transparent blur-3xl dark:from-[#4b5320]/25 dark:via-[#F8B803]/15"
                ></div>
            </div>

            <div class="mx-auto w-full max-w-xl text-center">
                <!-- Status Badge -->
                <div class="inline-flex items-center gap-2 rounded-full border border-gray-200/80 bg-white/80 px-3.5 py-1.5 text-xs font-semibold text-[#4b5320] dark:border-neutral-800 dark:bg-neutral-900/80 dark:text-[#F8B803]">
                    <Compass class="size-3.5" aria-hidden="true" />
                    <span>Error 404</span>
                </div>

                <!-- Main Heading -->
                <h1
                    id="not-found-heading"
                    class="mt-6 text-3xl font-semibold tracking-tight text-gray-900 sm:text-4xl md:text-5xl dark:text-white"
                >
                    Sorry, something went wrong!
                </h1>

                <!-- Informative Message -->
                <p
                    class="mx-auto mt-4 max-w-md text-base leading-relaxed text-gray-600 sm:text-lg dark:text-neutral-300"
                >
                    The page you were looking for doesn't exist. You may have mistyped the address.
                </p>

                <!-- Action Button -->
                <div class="mt-8 flex flex-col items-center justify-center gap-3 sm:flex-row">
                    <Link
                        href="/"
                        class="inline-flex w-full items-center justify-center gap-2 rounded-xl bg-[#4b5320] px-6 py-3.5 text-sm font-semibold text-white shadow-md transition-colors duration-150 hover:bg-[#3d441a] focus:outline-hidden focus:ring-2 focus:ring-[#4b5320] focus:ring-offset-2 sm:w-auto dark:focus:ring-offset-neutral-900"
                    >
                        <Home class="size-4" aria-hidden="true" />
                        <span>Return to home</span>
                    </Link>

                    <Link
                        href="/products"
                        class="inline-flex w-full items-center justify-center gap-2 rounded-xl border border-gray-300 bg-white px-6 py-3.5 text-sm font-semibold text-gray-700 transition-colors duration-150 hover:bg-gray-50 hover:text-gray-900 sm:w-auto dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-200 dark:hover:bg-neutral-800 dark:hover:text-white"
                    >
                        <ArrowLeft class="size-4" aria-hidden="true" />
                        <span>Browse Products</span>
                    </Link>
                </div>
            </div>
        </section>
    </PublicLayout>
</template>
