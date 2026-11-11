<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { useCustomerLoading } from '@/composables/useCustomerLoading';

const { isVisible, init } = useCustomerLoading();
// Teleports are not part of the server-rendered HTML, so only mount after hydration.
const mounted = ref(false);

onMounted(() => {
    mounted.value = true;
    init();
});
</script>

<template>
    <Teleport v-if="mounted" to="body">
        <Transition
            enter-active-class="transition-opacity duration-200 ease-out"
            enter-from-class="opacity-0"
            enter-to-class="opacity-100"
            leave-active-class="transition-opacity duration-200 ease-in"
            leave-from-class="opacity-100"
            leave-to-class="opacity-0"
        >
            <div
                v-if="isVisible"
                role="status"
                aria-live="polite"
                aria-label="Loading page"
                class="fixed inset-0 z-[99999] flex flex-col items-center justify-between overflow-hidden bg-white/95 backdrop-blur-2xl select-none dark:bg-[#0a0a0a]/95"
            >
                <!-- Subtle Ambient Glow Orbs -->
                <div
                    class="pointer-events-none absolute -top-24 -right-24 size-96 rounded-full bg-gradient-to-br from-[#F8B803]/15 to-transparent blur-3xl"
                ></div>
                <div
                    class="pointer-events-none absolute -bottom-24 -left-24 size-96 rounded-full bg-gradient-to-tr from-[#4b5320]/15 to-transparent blur-3xl"
                ></div>

                <!-- Top Spacer -->
                <div class="pointer-events-none pt-10 opacity-0 sm:pt-14">
                    <span class="text-xs">MR-PRIDE</span>
                </div>

                <!-- Center Section: Logo + Ambient Ring + Indeterminate Progress Indicator -->
                <div
                    class="relative my-auto flex flex-col items-center justify-center px-4"
                >
                    <!-- Glowing Aura behind Logo -->
                    <div class="relative flex items-center justify-center">
                        <div
                            class="absolute -inset-4 animate-pulse rounded-full bg-gradient-to-tr from-[#F8B803]/30 via-[#4b5320]/20 to-[#F8B803]/30 blur-xl"
                        ></div>

                        <!-- Circular Logo Plate -->
                        <div
                            class="animate-logo-float relative flex size-28 items-center justify-center rounded-full border border-gray-200/90 bg-white p-3 shadow-2xl sm:size-36 dark:border-neutral-800 dark:bg-neutral-900"
                        >
                            <img
                                src="/LOGO_MR_PRIDE.webp"
                                alt="MR-Pride Electrical Supplies"
                                class="pointer-events-none size-full object-contain drop-shadow-md select-none"
                                loading="eager"
                                decoding="sync"
                            />
                        </div>
                    </div>

                    <!-- Sleek Progress Track -->
                    <div
                        class="relative mt-8 h-1 w-48 overflow-hidden rounded-full bg-gray-200/80 shadow-inner sm:w-56 dark:bg-neutral-800/80"
                    >
                        <div
                            class="animate-progress-slide h-full w-1/2 rounded-full bg-gradient-to-r from-[#4b5320] via-[#F8B803] to-[#4b5320]"
                        ></div>
                    </div>

                    <!-- Subtle Status Text -->
                    <span
                        class="mt-3 animate-pulse text-xs font-semibold tracking-wider text-gray-500 dark:text-neutral-400"
                    >
                        Loading...
                    </span>
                </div>

                <!-- Bottom Section: App Name & Tagline -->
                <div class="pb-10 text-center sm:pb-12">
                    <h2
                        class="text-xl font-black tracking-[0.25em] text-gray-900 uppercase drop-shadow-xs sm:text-2xl dark:text-white"
                    >
                        MR-PRIDE
                    </h2>
                    <p
                        class="mt-1 text-[10px] font-bold tracking-[0.3em] text-[#4b5320] uppercase sm:text-xs dark:text-[#F8B803]"
                    >
                        Electrical Supplies
                    </p>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>

<style scoped>
@keyframes logo-float {
    0%,
    100% {
        transform: translateY(0) scale(1);
    }
    50% {
        transform: translateY(-4px) scale(1.02);
    }
}

@keyframes progress-slide {
    0% {
        transform: translateX(-120%);
    }
    50% {
        transform: translateX(60%);
    }
    100% {
        transform: translateX(220%);
    }
}

.animate-logo-float {
    animation: logo-float 2.4s ease-in-out infinite;
}

.animate-progress-slide {
    animation: progress-slide 1.5s ease-in-out infinite;
}

@media (prefers-reduced-motion: reduce) {
    .animate-logo-float,
    .animate-progress-slide {
        animation: none;
    }
}
</style>
