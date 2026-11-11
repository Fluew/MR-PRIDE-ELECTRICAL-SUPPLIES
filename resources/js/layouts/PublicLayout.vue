<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { ArrowRight, MapPin } from '@lucide/vue';
import { computed } from 'vue';
import { home } from '@/routes';
import { index as company } from '@/routes/company';
import { index as contact } from '@/routes/contact';
import { index as products } from '@/routes/products';
import { create as quotation } from '@/routes/quotations';
import { vWordReveal } from '@/directives/wordReveal';
import StaggeredMenu from '@/components/vue-bits/StaggeredMenu.vue';
import CustomerLoadingScreen from '@/components/CustomerLoadingScreen.vue';
import FloatingContactSidebar from '@/components/FloatingContactSidebar.vue';
import CookieConsent from '@/components/CookieConsent.vue';
import { Toaster } from '@/components/ui/sonner';

const page = usePage();
const currentPath = computed(() => page.url.split(/[?#]/)[0]);
const navigation = [
    { name: 'Home', href: home().url },
    { name: 'Products', href: products().url },
    { name: 'Company Info', href: company().url },
    { name: 'Contact Us', href: contact().url },
];

const year = new Date().getFullYear();
</script>
<template>
    <div
        class="public-site min-h-screen bg-gray-50 font-sans text-gray-900 selection:bg-[#F8B803]/40 dark:bg-[#0a0a0a] dark:text-gray-100"
    >
        <!-- Full-screen Customer Loading Screen (starting, refreshing, route navigation) -->
        <CustomerLoadingScreen />

        <!-- Floating Contact & Support Sidebar (Fixed to Right Edge) -->
        <FloatingContactSidebar />

        <!-- Cookie Preferences & Privacy Popup -->
        <CookieConsent />
        <Toaster />

        <a href="#main-content" class="skip-link">Skip to content</a>
        <header
            class="fixed inset-x-0 top-0 z-50 border-b border-gray-200/80 bg-white/95 backdrop-blur-xl dark:border-neutral-800 dark:bg-neutral-900/95"
        >
            <nav
                aria-label="Main navigation"
                class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8"
            >
                <div class="flex h-20 items-center justify-between gap-4">
                    <Link
                        :href="home()"
                        aria-label="MR-Pride home"
                        class="flex shrink-0 items-center gap-3 rounded-lg"
                    >
                        <img
                            src="/LOGO_MR_PRIDE.webp"
                            alt=""
                            class="size-11 object-contain"
                        />
                        <span class="flex flex-col gap-1 leading-none"
                            ><span
                                class="text-lg font-extrabold tracking-tight text-gray-900 dark:text-white"
                                >MR-PRIDE</span
                            ><span
                                class="text-[9px] font-semibold tracking-[0.16em] text-gray-500 uppercase dark:text-neutral-400"
                                >Electrical Supplies</span
                            ></span
                        >
                    </Link>

                    <!-- Desktop Navigation: Visible above 1024px (lg:flex) -->
                    <div class="hidden items-center gap-2 lg:flex">
                        <Link
                            v-for="item in navigation"
                            :key="item.name"
                            :href="item.href"
                            :aria-current="
                                currentPath === item.href ? 'page' : undefined
                            "
                            class="rounded-lg px-4 py-2.5 text-sm font-semibold transition-colors"
                            :class="
                                currentPath === item.href
                                    ? 'bg-[#4b5320]/10 text-[#4b5320] dark:bg-[#F8B803]/15 dark:text-[#F8B803]'
                                    : 'text-gray-600 hover:bg-gray-100 hover:text-gray-900 dark:text-neutral-300 dark:hover:bg-neutral-800 dark:hover:text-white'
                            "
                            >{{ item.name }}</Link
                        >
                        <Link :href="quotation()" class="brand-button ml-3"
                            >Request Quotation<ArrowRight
                                class="size-4"
                                aria-hidden="true"
                        /></Link>
                    </div>

                    <!-- Mobile & Tablet Responsive StaggeredMenu (1024px and below: block lg:hidden) -->
                    <StaggeredMenu
                        :items="navigation"
                        :quotation-href="quotation().url"
                        :colors="['#1e250c', '#333b14', '#4b5320']"
                    />
                </div>
            </nav>
        </header>
        <main id="main-content" tabindex="-1" class="pt-20 focus:outline-none">
            <!-- Unauthorized Device Notification Banner -->
            <div
                v-if="
                    page.props.device_notice ||
                    (page.props.flash as any)?.device_notice
                "
                class="flex items-center justify-center gap-3 border-b border-amber-400/30 bg-gradient-to-r from-amber-600 via-amber-700 to-[#4b5320] px-6 py-4 text-center text-sm font-semibold text-white shadow-lg"
            >
                <span
                    class="size-2.5 animate-ping rounded-full bg-amber-300"
                ></span>
                <span>{{
                    page.props.device_notice ||
                    (page.props.flash as any)?.device_notice
                }}</span>
            </div>
            <slot />
        </main>
        <footer
            class="border-t border-gray-200 bg-white dark:border-neutral-800 dark:bg-neutral-950"
        >
            <div class="mx-auto max-w-7xl px-4 pt-14 pb-7 sm:px-6 lg:px-8">
                <div class="grid gap-10 pb-12 md:grid-cols-[2fr_1fr_1fr]">
                    <div>
                        <p v-word-reveal class="text-lg font-extrabold text-[#4b5320] dark:text-[#F8B803]">MR-PRIDE<span class="text-[#F8B803]">.</span></p>
                        <p v-word-reveal class="mt-4 max-w-sm text-sm leading-7 text-gray-600 dark:text-neutral-300">
                            Electrical, hardware, and industrial supplies for your next project. Quality products. Trusted brand partners.
                        </p>
                    </div>
                    <div>
                        <h2 v-word-reveal class="text-xs font-bold tracking-widest text-gray-500 uppercase dark:text-neutral-400">Explore</h2>
                        <ul class="mt-3.5 grid text-sm font-medium">
                            <li
                                v-for="item in navigation"
                                :key="item.name"
                            >
                                <Link
                                    :href="item.href"
                                    class="inline-block py-1.5 text-gray-700 hover:text-[#4b5320] hover:underline dark:text-neutral-300 dark:hover:text-[#F8B803]"
                                    ><span v-word-reveal>{{ item.name }}</span></Link>
                            </li>
                            <li>
                                <Link
                                    :href="quotation()"
                                    class="inline-block py-1.5 text-gray-700 hover:text-[#4b5320] hover:underline dark:text-neutral-300 dark:hover:text-[#F8B803]"
                                    ><span v-word-reveal>Request Quotation</span></Link>
                            </li>
                        </ul>
                    </div>
                    <div>
                        <h2 v-word-reveal class="text-xs font-bold tracking-widest text-gray-500 uppercase dark:text-neutral-400">Visit our office</h2>
                        <p
                            class="mt-5 flex gap-2 text-sm leading-7 text-gray-600 dark:text-neutral-300"
                        >
                            <MapPin
                                class="mt-1 size-4 shrink-0 text-[#4b5320] dark:text-[#F8B803]"
                                aria-hidden="true"
                            /><span v-word-reveal>#610 Esguerra St.<br />Tondo, Manila, Philippines</span>
                        </p>
                    </div>
                </div>
            </div>
            <!-- Bottom Copyright & Social Bar in Army Green -->
            <div class="border-t border-[#3e451a] bg-[#4b5320] py-5 text-white">
                <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
                    <div
                        class="flex flex-col gap-3 text-xs text-white/90 sm:flex-row sm:items-center sm:justify-between"
                    >
                        <p v-word-reveal>© {{ year }} MR-Pride Electrical Supplies. All rights reserved.</p>
                        <div class="flex items-center gap-3">
                            <span v-word-reveal class="text-white/80">Hardware · Electrical · Industrial · Safety</span>
                            <span class="text-white/40">·</span>
                            <a
                                href="https://web.facebook.com/mrprideelectricalsupplies"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="inline-flex items-center gap-1.5 font-medium text-white transition-colors hover:text-[#F8B803]"
                                title="Follow MR-Pride on Facebook"
                            >
                                <svg
                                    class="size-3.5 rounded-full bg-white fill-[#1877F2] p-0.5"
                                    viewBox="0 0 24 24"
                                    aria-hidden="true"
                                >
                                    <path
                                        fill-rule="evenodd"
                                        clip-rule="evenodd"
                                        d="M22 12c0-5.523-4.477-10-10-10S2 6.477 2 12c0 4.991 3.657 9.128 8.438 9.878v-6.987h-2.54V12h2.54V9.797c0-2.506 1.492-3.89 3.777-3.89 1.094 0 2.238.195 2.238.195v2.46h-1.26c-1.243 0-1.63.771-1.63 1.562V12h2.773l-.443 2.89h-2.33v6.988C18.343 21.128 22 16.991 22 12z"
                                    />
                                </svg>
                                <span v-word-reveal>Facebook</span>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </footer>
    </div>
</template>
