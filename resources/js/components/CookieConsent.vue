<script setup lang="ts">
import { ref, onMounted } from 'vue';
import { Cookie, X, Check, Shield } from '@lucide/vue';

const CONSENT_STORAGE_KEY = 'mrpride_cookie_consent';

const isVisible = ref(false);
// Teleports are not part of the server-rendered HTML, so only mount after hydration.
const mounted = ref(false);

onMounted(() => {
    mounted.value = true;
    try {
        const consent = localStorage.getItem(CONSENT_STORAGE_KEY);
        if (!consent) {
            // Polite delay so it appears smoothly after initial load
            setTimeout(() => {
                isVisible.value = true;
            }, 800);
        }
    } catch {
        // Fallback if localStorage is inaccessible
        isVisible.value = true;
    }
});

function acceptAll() {
    try {
        localStorage.setItem(CONSENT_STORAGE_KEY, 'accepted');
    } catch {
        // ignore storage errors
    }
    isVisible.value = false;
}

function declineNonEssential() {
    try {
        localStorage.setItem(CONSENT_STORAGE_KEY, 'essential_only');
    } catch {
        // ignore storage errors
    }
    isVisible.value = false;
}

function dismiss() {
    try {
        localStorage.setItem(CONSENT_STORAGE_KEY, 'dismissed');
    } catch {
        // ignore storage errors
    }
    isVisible.value = false;
}
</script>

<template>
    <Teleport v-if="mounted" to="body">
        <Transition
            enter-active-class="transition duration-400 ease-out"
            enter-from-class="opacity-0 translate-y-8 scale-95"
            enter-to-class="opacity-100 translate-y-0 scale-100"
            leave-active-class="transition duration-250 ease-in"
            leave-from-class="opacity-100 translate-y-0 scale-100"
            leave-to-class="opacity-0 translate-y-8 scale-95"
        >
            <div
                v-if="isVisible"
                role="dialog"
                aria-modal="false"
                aria-labelledby="cookie-popup-title"
                aria-describedby="cookie-popup-desc"
                class="fixed bottom-4 left-4 z-50 w-[calc(100vw-2rem)] max-w-sm rounded-3xl border border-gray-200/90 bg-white/95 p-5 text-gray-900 shadow-2xl backdrop-blur-xl select-none sm:bottom-6 sm:left-6 sm:max-w-md sm:p-6 dark:border-neutral-800 dark:bg-[#121212]/95 dark:text-gray-100"
            >
                <div class="flex items-start justify-between gap-3">
                    <div class="flex items-center gap-3">
                        <div
                            class="flex size-10 shrink-0 items-center justify-center rounded-2xl bg-[#4b5320] text-[#F8B803] shadow-inner"
                        >
                            <Cookie class="size-5" />
                        </div>
                        <div>
                            <h2
                                id="cookie-popup-title"
                                class="text-sm font-bold tracking-tight text-gray-900 dark:text-white"
                            >
                                Cookie Preferences
                            </h2>
                            <p
                                class="text-[11px] font-semibold tracking-wider text-gray-400 uppercase"
                            >
                                Privacy & Analytics
                            </p>
                        </div>
                    </div>

                    <button
                        type="button"
                        aria-label="Close cookie notice"
                        class="cursor-pointer rounded-full p-1 text-gray-400 transition-colors hover:bg-gray-100 hover:text-gray-700 dark:hover:bg-neutral-800 dark:hover:text-white"
                        @click="dismiss"
                    >
                        <X class="size-4" />
                    </button>
                </div>

                <p
                    id="cookie-popup-desc"
                    class="mt-3.5 text-xs leading-relaxed text-gray-600 dark:text-neutral-300"
                >
                    We use cookies to improve your browsing experience, provide
                    secure session management, and understand site interactions
                    for MR-Pride Electrical Supplies.
                </p>

                <div
                    class="mt-4 flex flex-col gap-2 sm:flex-row sm:items-center"
                >
                    <button
                        type="button"
                        class="inline-flex cursor-pointer items-center justify-center gap-1.5 rounded-xl bg-[#4b5320] px-4 py-2.5 text-xs font-bold text-white shadow-md transition-all hover:bg-[#383e18] hover:shadow-lg focus:ring-2 focus:ring-[#F8B803] focus:ring-offset-2 focus:outline-none"
                        @click="acceptAll"
                    >
                        <Check class="size-3.5 text-[#F8B803]" />
                        <span>Accept All</span>
                    </button>

                    <button
                        type="button"
                        class="inline-flex cursor-pointer items-center justify-center gap-1.5 rounded-xl border border-gray-200 bg-gray-100 px-4 py-2.5 text-xs font-bold text-gray-700 transition-colors hover:bg-gray-200 focus:outline-none dark:border-neutral-800 dark:bg-neutral-800 dark:text-neutral-200 dark:hover:bg-neutral-700"
                        @click="declineNonEssential"
                    >
                        <Shield
                            class="size-3.5 text-gray-400 dark:text-neutral-400"
                        />
                        <span>Essential Only</span>
                    </button>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>
