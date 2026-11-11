<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { Mail, Phone, MapPin, Clock, Building, ShieldCheck } from '@lucide/vue';
import PublicLayout from '@/layouts/PublicLayout.vue';
import { vWordReveal } from '@/directives/wordReveal';
</script>

<template>
    <Head title="Contact Us | MR-Pride Electrical Supplies" />

    <PublicLayout>
        <!-- Army Green Background Page Container -->
        <div class="min-h-[calc(100vh-5rem)] bg-[#4b5320] py-14 text-white selection:bg-[#F8B803]/40 sm:py-20">
            <div class="mx-auto max-w-6xl px-4 sm:px-6 lg:px-8">
                <!-- Page Title Header -->
                <div class="mb-12 text-center sm:mb-16">
                    <span class="inline-block rounded-full bg-[#F8B803]/20 px-4 py-1 text-xs font-semibold text-[#F8B803] border border-[#F8B803]/30 mb-3">
                        Reach Out To Us
                    </span>
                    <h1 class="text-3xl font-semibold tracking-tight text-[#F8B803] sm:text-5xl">
                        Contact Us
                    </h1>
                    <p class="mx-auto mt-4 max-w-2xl text-sm sm:text-base text-white/90 leading-relaxed">
                        Need electrical supplies, product quotations, or industrial equipment procurement? 
                        Contact our sales and support team directly through the official channels below.
                    </p>
                </div>

                <!-- Contact Information Grid -->
                <div class="grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-3">
                    <!-- Email Card -->
                    <div class="flex flex-col justify-between rounded-xl border border-white/20 bg-white p-6 text-gray-900 shadow-xl transition-colors duration-200 dark:border-neutral-800 dark:bg-neutral-900 dark:text-white">
                        <div>
                            <div class="flex size-12 items-center justify-center rounded-xl bg-[#4b5320]/10 text-[#4b5320] dark:bg-neutral-800 dark:text-[#F8B803] mb-4">
                                <Mail class="size-6" />
                            </div>
                            <h2 v-word-reveal class="text-base font-semibold text-gray-900 dark:text-white">
                                Email Communications
                            </h2>
                            <p v-word-reveal class="mt-1 text-xs text-gray-500 dark:text-neutral-400">
                                Send purchase orders, quotation inquiries, and company correspondence.
                            </p>
                        </div>
                        <div class="mt-6 border-t border-gray-100 pt-2 dark:border-neutral-800">
                            <a v-word-reveal
                                href="mailto:sales.rcjlent@gmail.com"
                                class="flex items-center gap-2 py-2 text-xs font-semibold text-gray-800 hover:text-[#4b5320] hover:underline dark:text-neutral-200 dark:hover:text-[#F8B803]"
                            >
                                <span class="size-2 rounded-full bg-emerald-500 shrink-0"></span>
                                sales.rcjlent@gmail.com
                            </a>
                            <a v-word-reveal
                                href="mailto:malabanan.gary@gmail.com"
                                class="flex items-center gap-2 py-2 text-xs font-semibold text-gray-800 hover:text-[#4b5320] hover:underline dark:text-neutral-200 dark:hover:text-[#F8B803]"
                            >
                                <span class="size-2 rounded-full bg-[#F8B803] shrink-0"></span>
                                malabanan.gary@gmail.com
                            </a>
                        </div>
                    </div>

                    <!-- Phone Card -->
                    <div class="flex flex-col justify-between rounded-xl border border-white/20 bg-white p-6 text-gray-900 shadow-xl transition-colors duration-200 dark:border-neutral-800 dark:bg-neutral-900 dark:text-white">
                        <div>
                            <div class="flex size-12 items-center justify-center rounded-xl bg-[#4b5320]/10 text-[#4b5320] dark:bg-neutral-800 dark:text-[#F8B803] mb-4">
                                <Phone class="size-6" />
                            </div>
                            <h2 v-word-reveal class="text-base font-semibold text-gray-900 dark:text-white">
                                Phone & Landline
                            </h2>
                            <p v-word-reveal class="mt-1 text-xs text-gray-500 dark:text-neutral-400">
                                Call our direct sales lines for urgent orders and delivery follow-ups.
                            </p>
                        </div>
                        <div class="mt-6 border-t border-gray-100 pt-2 dark:border-neutral-800">
                            <a v-word-reveal
                                href="tel:09397366907"
                                class="flex items-center gap-2 py-2 font-mono text-xs font-semibold text-gray-800 hover:text-[#4b5320] dark:text-neutral-200 dark:hover:text-[#F8B803]"
                            >
                                <span class="size-2 rounded-full bg-emerald-500 shrink-0"></span>
                                09397366907 (Mobile / Smart)
                            </a>
                            <a v-word-reveal
                                href="tel:022444939"
                                class="flex items-center gap-2 py-2 font-mono text-xs font-semibold text-gray-800 hover:text-[#4b5320] dark:text-neutral-200 dark:hover:text-[#F8B803]"
                            >
                                <span class="size-2 rounded-full bg-[#F8B803] shrink-0"></span>
                                (02) 244-4939 (Landline)
                            </a>
                        </div>
                    </div>

                    <!-- Address Card -->
                    <div class="flex flex-col justify-between rounded-xl border border-white/20 bg-white p-6 text-gray-900 shadow-xl transition-colors duration-200 dark:border-neutral-800 dark:bg-neutral-900 dark:text-white sm:col-span-2 lg:col-span-1">
                        <div>
                            <div class="flex size-12 items-center justify-center rounded-xl bg-[#4b5320]/10 text-[#4b5320] dark:bg-neutral-800 dark:text-[#F8B803] mb-4">
                                <MapPin class="size-6" />
                            </div>
                            <h2 v-word-reveal class="text-base font-semibold text-gray-900 dark:text-white">
                                Warehouse & Office
                            </h2>
                            <p v-word-reveal class="mt-1 text-xs text-gray-500 dark:text-neutral-400">
                                Main depot for commercial distribution and warehouse pickups.
                            </p>
                        </div>
                        <div class="mt-6 border-t border-gray-100 pt-4 dark:border-neutral-800">
                            <p v-word-reveal class="text-xs leading-relaxed font-semibold text-gray-800 dark:text-neutral-200">
                                #610 ESGUERRA ST. TONDO MANILA
                            </p>
                            <p v-word-reveal class="mt-1 text-[11px] text-gray-500 dark:text-neutral-400">
                                Metro Manila, Philippines
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Additional Info Section: Business Hours & Support Notice -->
                <div class="mt-8 grid grid-cols-1 gap-6 sm:grid-cols-2">
                    <div class="flex items-start gap-4 rounded-xl border border-white/15 bg-white/10 p-5 backdrop-blur-xs">
                        <div class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-[#F8B803] text-gray-900 font-semibold">
                            <Clock class="size-5" />
                        </div>
                        <div>
                            <h3 v-word-reveal class="text-sm font-semibold text-white">Operating Hours</h3>
                            <p v-word-reveal class="mt-1 text-xs text-white/80 leading-relaxed">
                                Monday to Saturday: <strong>8:00 AM – 5:00 PM</strong><br>
                                Sunday & Official Holidays: <strong>Closed</strong>
                            </p>
                        </div>
                    </div>

                    <div class="flex items-start gap-4 rounded-xl border border-white/15 bg-white/10 p-5 backdrop-blur-xs">
                        <div class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-[#F8B803] text-gray-900 font-semibold">
                            <ShieldCheck class="size-5" />
                        </div>
                        <div>
                            <h3 v-word-reveal class="text-sm font-semibold text-white">Direct Electrical Procurement</h3>
                            <p v-word-reveal class="mt-1 text-xs text-white/80 leading-relaxed">
                                For bulk commercial tenders and urgent delivery requests, contact our direct sales lines for immediate assistance.
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </PublicLayout>
</template>
