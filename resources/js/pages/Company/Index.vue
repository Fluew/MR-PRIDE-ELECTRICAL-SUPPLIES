<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { Target, Eye, Star, Info } from '@lucide/vue';
import SpotlightCard from '@/components/vue-bits/SpotlightCard.vue';
import PublicLayout from '@/layouts/PublicLayout.vue';
import { vWordReveal } from '@/directives/wordReveal';
</script>

<template>
    <Head title="Company Information | MR-Pride Electrical Supplies" />

    <PublicLayout>
        <!-- Hero Section -->
        <div
            class="relative overflow-hidden border-b border-[#3b4219] bg-[#353b16] py-14 sm:py-20 text-white"
        >
            <img
                src="/COMPANY_BACKGROUND.webp"
                alt=""
                aria-hidden="true"
                decoding="async"
                class="pointer-events-none absolute inset-0 size-full object-cover object-center opacity-35 select-none"
            />
            <!-- Army green gradient 75% opacity -->
            <div
                class="absolute inset-0 bg-gradient-to-r from-[#4b5320]/90 via-[#4b5320]/75 to-[#4b5320]/60"
                aria-hidden="true"
            ></div>
            <div class="relative z-10 mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <div class="max-w-3xl">
                    <div v-word-reveal
                        class="mb-6 inline-flex items-center gap-2 rounded-full border border-white/20 bg-white/15 px-3 py-1 text-sm font-semibold text-white backdrop-blur-md"
                    >
                        <Info class="h-4 w-4 text-[#F8B803]" />
                        Company Profile
                    </div>
                    <h1 v-word-reveal
                        class="mb-6 text-4xl leading-tight font-semibold tracking-tight text-[#F8B803] md:text-5xl"
                    >
                        About MR-Pride Electrical Supplies
                    </h1>
                    <p v-word-reveal class="text-xl leading-relaxed text-white">
                        MR-Pride Electrical Supplies is an entity that offers
                        list of quality products that will help to minimize your
                        problems involving Electrical. Our office is located at
                        #610 Esguerra St. Tondo Manila that is built to
                        accommodate the increasing demand of our country, in
                        terms of Electrical supply solution. This company is a
                        fully Filipino Owned that is dedicated to support its
                        clients, and future clients in their needs of any
                        products in line, MR-Pride Electrical Supplies has
                        devoted employees to provide products in good quality
                        considering the words, “right in time.”
                    </p>
                </div>
            </div>
        </div>

        <!-- Vision and Mission Section -->
        <div class="bg-white py-14 sm:py-20">
            <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                    <!-- Vision -->
                    <SpotlightCard
                        class="group relative overflow-hidden rounded-xl border border-gray-100 bg-gray-50 p-6 transition-colors duration-300 hover:border-[#F8B803]/30 sm:p-9"
                    >
                        <div
                            class="absolute -top-4 -right-4 text-[#F8B803] opacity-10 transition-transform duration-500"
                        >
                            <Eye class="h-48 w-48" />
                        </div>
                        <div class="relative z-10">
                            <div
                                class="mb-6 flex h-14 w-14 items-center justify-center rounded-xl border border-gray-100 bg-white"
                            >
                                <Eye class="h-7 w-7 text-[#F8B803]" />
                            </div>
                            <h2 v-word-reveal class="mb-4 text-3xl font-semibold text-gray-900">
                                Vision
                            </h2>
                            <p v-word-reveal class="text-lg leading-relaxed text-gray-600">
                                To become a premier, trusted distributor and supplier in the field of electrical materials and supplies, conducting honest dealing with all stakeholders while expanding our reach nationwide.
                            </p>
                        </div>
                    </SpotlightCard>

                    <!-- Mission -->
                    <SpotlightCard
                        class="group relative overflow-hidden rounded-xl border border-gray-100 bg-gray-50 p-6 transition-colors duration-300 hover:border-[#4b5320]/30 sm:p-9"
                    >
                        <div
                            class="absolute -top-4 -right-4 text-[#4b5320] opacity-10 transition-transform duration-500"
                        >
                            <Target class="h-48 w-48" />
                        </div>
                        <div class="relative z-10">
                            <div
                                class="mb-6 flex h-14 w-14 items-center justify-center rounded-xl border border-gray-100 bg-white"
                            >
                                <Target class="h-7 w-7 text-[#4b5320]" />
                            </div>
                            <h2 v-word-reveal class="mb-4 text-3xl font-semibold text-gray-900">
                                Mission
                            </h2>
                            <p v-word-reveal class="text-lg leading-relaxed text-gray-600">
                                To offer superior quality electrical materials at fair and competitive prices, establishing lasting partnerships with contractors and engineers, and serving with character and purpose.
                            </p>
                        </div>
                    </SpotlightCard>
                </div>
            </div>
        </div>

        <!-- Core Values Section -->
        <div class="border-t border-[#3b4219] bg-[#4b5320] py-14 sm:py-20 text-white">
            <div class="mx-auto max-w-7xl px-4 text-center sm:px-6 lg:px-8">
                <div class="mb-16">
                    <div
                        class="mx-auto mb-6 flex h-16 w-16 items-center justify-center rounded-xl border border-white/20 bg-white/10 backdrop-blur-md"
                    >
                        <Star class="h-8 w-8 text-[#F8B803]" />
                    </div>
                    <h2 v-word-reveal
                        class="mb-4 text-3xl font-semibold text-white md:text-4xl"
                    >
                        Our Core Values
                    </h2>
                    <div
                        class="mx-auto h-1 w-24 rounded-full bg-[#F8B803]"
                    ></div>
                </div>

                <div
                    class="mx-auto flex max-w-4xl flex-wrap justify-center gap-4 md:gap-8"
                >
                    <div v-word-reveal
                        class="rounded-full border border-white/30 bg-white px-8 py-4 text-xl font-semibold text-gray-900 shadow-md transition-colors duration-300 hover:border-[#F8B803]"
                    >
                        Honesty
                    </div>
                    <div v-word-reveal
                        class="rounded-full border border-white/30 bg-white px-8 py-4 text-xl font-semibold text-gray-900 shadow-md transition-colors duration-300 hover:border-[#F8B803]"
                    >
                        Respect
                    </div>
                    <div v-word-reveal
                        class="rounded-full border border-white/30 bg-white px-8 py-4 text-xl font-semibold text-gray-900 shadow-md transition-colors duration-300 hover:border-[#F8B803]"
                    >
                        Passion
                    </div>
                    <div v-word-reveal
                        class="rounded-full border border-white/30 bg-white px-8 py-4 text-xl font-semibold text-gray-900 shadow-md transition-colors duration-300 hover:border-[#F8B803]"
                    >
                        Quality
                    </div>
                    <div v-word-reveal
                        class="rounded-full border border-white/30 bg-white px-8 py-4 text-xl font-semibold text-gray-900 shadow-md transition-colors duration-300 hover:border-[#F8B803]"
                    >
                        Commitment
                    </div>
                </div>
            </div>
        </div>
    </PublicLayout>
</template>
