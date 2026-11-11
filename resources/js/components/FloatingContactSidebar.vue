<script setup lang="ts">
import { ref, onMounted, onUnmounted } from 'vue';
import { X } from '@lucide/vue';

type ActiveTab = 'email' | 'support' | null;

const activeTab = ref<ActiveTab>(null);
const isPinned = ref(false);
const sidebarRef = ref<HTMLElement | null>(null);

let hoverTimeout: ReturnType<typeof setTimeout> | null = null;
let leaveTimeout: ReturnType<typeof setTimeout> | null = null;

const contactDetails = {
    phones: [
        {
            label: 'Mobile (Smart / Viber)',
            number: '09397366907',
            href: 'tel:09397366907',
        },
        {
            label: 'Landline (Manila)',
            number: '022444939',
            href: 'tel:022444939',
        },
    ],
    emails: [
        {
            label: 'Commercial & Quotations',
            email: 'sales.rcjlent@gmail.com',
            href: 'mailto:sales.rcjlent@gmail.com?subject=Inquiry%20from%20MR-Pride%20Website',
        },
        {
            label: 'Direct Consultation',
            email: 'malabanan.gary@gmail.com',
            href: 'mailto:malabanan.gary@gmail.com?subject=Consultation%20Inquiry%20-%20MR-Pride',
        },
    ],
};

function openTab(tab: 'email' | 'support', pin = false) {
    if (leaveTimeout) clearTimeout(leaveTimeout);
    if (hoverTimeout) clearTimeout(hoverTimeout);

    activeTab.value = tab;
    if (pin) {
        isPinned.value = true;
    }
}

function handleMouseEnter(tab: 'email' | 'support') {
    if (window.matchMedia('(hover: hover)').matches) {
        if (leaveTimeout) clearTimeout(leaveTimeout);
        hoverTimeout = setTimeout(() => {
            activeTab.value = tab;
        }, 120);
    }
}

function handleMouseLeave() {
    if (isPinned.value) return;
    if (hoverTimeout) clearTimeout(hoverTimeout);
    leaveTimeout = setTimeout(() => {
        activeTab.value = null;
    }, 280);
}

function cancelLeaveTimeout() {
    if (leaveTimeout) clearTimeout(leaveTimeout);
}

function toggleTab(tab: 'email' | 'support') {
    if (activeTab.value === tab && isPinned.value) {
        closePanel();
    } else {
        openTab(tab, true);
    }
}

function closePanel() {
    activeTab.value = null;
    isPinned.value = false;
    if (leaveTimeout) clearTimeout(leaveTimeout);
    if (hoverTimeout) clearTimeout(hoverTimeout);
}

function handleKeydown(e: KeyboardEvent) {
    if (e.key === 'Escape' && activeTab.value) {
        closePanel();
    }
}

function handleClickOutside(e: MouseEvent | TouchEvent) {
    if (sidebarRef.value && !sidebarRef.value.contains(e.target as Node)) {
        closePanel();
    }
}

onMounted(() => {
    window.addEventListener('keydown', handleKeydown);
    window.addEventListener('click', handleClickOutside);
    window.addEventListener('touchstart', handleClickOutside, {
        passive: true,
    });
});

onUnmounted(() => {
    window.removeEventListener('keydown', handleKeydown);
    window.removeEventListener('click', handleClickOutside);
    window.removeEventListener('touchstart', handleClickOutside);
    if (hoverTimeout) clearTimeout(hoverTimeout);
    if (leaveTimeout) clearTimeout(leaveTimeout);
});
</script>

<template>
    <!-- Mobile Backdrop Overlay for easy tap-to-dismiss -->
    <Transition
        enter-active-class="transition-opacity duration-200 ease-out"
        enter-from-class="opacity-0"
        enter-to-class="opacity-100"
        leave-active-class="transition-opacity duration-150 ease-in"
        leave-from-class="opacity-100"
        leave-to-class="opacity-0"
    >
        <div
            v-if="activeTab"
            class="fixed inset-0 z-40 bg-black/40 backdrop-blur-xs md:hidden"
            aria-hidden="true"
            @click="closePanel"
        />
    </Transition>

    <!--
        Floating Sidebar Container:
        Uses flexbox alignment with NO transform on the parent so child fixed/absolute elements
        are NEVER trapped or crushed into the dock width.
    -->
    <aside
        ref="sidebarRef"
        aria-label="Floating Contact and Support Bar"
        class="pointer-events-none fixed inset-y-0 right-0 z-40 flex items-center justify-end select-none"
        @mouseleave="handleMouseLeave"
    >
        <!-- Stacked Shortcut Buttons Dock -->
        <div class="relative flex flex-col items-end gap-2.5">
            <!-- Button 1: Consultation Email with its dedicated flyout -->
            <div class="relative flex items-center">
                <button
                    type="button"
                    :aria-expanded="activeTab === 'email'"
                    aria-controls="consultation-email-panel"
                    aria-label="Consultation Email"
                    class="group pointer-events-auto flex cursor-pointer items-center rounded-l-2xl max-sm:px-2 max-sm:py-3 max-sm:[writing-mode:vertical-rl] border-y border-l border-white/20 bg-gradient-to-r from-[#4b5320] to-[#3a4119] px-3.5 py-2.5 text-white shadow-xl transition-all duration-300 ease-out hover:-translate-x-3 hover:border-[#F8B803] hover:from-[#576025] hover:to-[#434b1d] hover:shadow-2xl focus-visible:ring-2 focus-visible:ring-[#F8B803] focus-visible:ring-offset-2 focus-visible:outline-none sm:px-4 sm:py-3 sm:hover:-translate-x-4"
                    :class="[
                        activeTab === 'email'
                            ? '-translate-x-3 border-[#F8B803] bg-[#3a4119] shadow-2xl ring-1 ring-[#F8B803]/50 sm:-translate-x-4'
                            : '',
                    ]"
                    @mouseenter="handleMouseEnter('email')"
                    @click="toggleTab('email')"
                >
                    <span
                        class="text-xs font-bold tracking-wide whitespace-nowrap text-white drop-shadow-xs sm:text-sm"
                    >
                        Consultation Email
                    </span>
                </button>

                <!--
                    Flyout 1: Dedicated to Consultation Email
                    - Mobile (< md): Centered bottom sheet (fixed inset-x-4 bottom-6 max-w-sm mx-auto)
                    - Desktop (md+): Left-attached flyout directly beside the button (right-full mr-3.5 top-1/2 -translate-y-1/2 w-80)
                -->
                <Transition name="flyout-slide">
                    <div
                        v-if="activeTab === 'email'"
                        id="consultation-email-panel"
                        role="dialog"
                        aria-modal="false"
                        aria-label="Consultation Email"
                        class="flyout-card pointer-events-auto fixed inset-x-4 bottom-6 z-50 mx-auto max-h-[82vh] w-auto max-w-sm overflow-y-auto rounded-3xl border border-gray-200/90 bg-white/98 p-5 text-gray-900 shadow-2xl ring-1 ring-black/5 backdrop-blur-2xl md:absolute md:top-1/2 md:right-full md:bottom-auto md:left-auto md:mr-3.5 md:max-h-[calc(100vh-4rem)] md:w-[320px] md:max-w-none dark:border-neutral-800 dark:bg-[#121212]/98 dark:text-gray-100"
                        @mouseenter="cancelLeaveTimeout"
                    >
                        <div
                            class="mb-3.5 flex items-center justify-between border-b border-gray-100 pb-2.5 dark:border-neutral-800"
                        >
                            <div>
                                <h3
                                    class="text-xs font-black tracking-widest text-[#4b5320] uppercase dark:text-[#F8B803]"
                                >
                                    Consultation Email
                                </h3>
                                <p
                                    class="text-[11px] font-medium text-gray-500 dark:text-neutral-400"
                                >
                                    MR-Pride Electrical Supplies
                                </p>
                            </div>
                            <button
                                type="button"
                                aria-label="Close"
                                class="cursor-pointer rounded-full p-1 text-gray-400 transition-colors hover:bg-gray-100 hover:text-gray-700 dark:hover:bg-neutral-800 dark:hover:text-white"
                                @click="closePanel"
                            >
                                <X class="size-4" />
                            </button>
                        </div>

                        <div class="space-y-2">
                            <div
                                v-for="item in contactDetails.emails"
                                :key="item.email"
                                class="rounded-xl border border-gray-100 bg-white p-3 text-xs shadow-2xs dark:border-neutral-800/80 dark:bg-neutral-950"
                            >
                                <span
                                    class="block text-[10px] font-semibold text-gray-400"
                                >
                                    {{ item.label }}
                                </span>
                                <a
                                    :href="item.href"
                                    class="mt-1 block font-medium break-all text-gray-900 transition-colors hover:text-[#4b5320] dark:text-white dark:hover:text-[#F8B803]"
                                >
                                    {{ item.email }}
                                </a>
                            </div>
                        </div>
                    </div>
                </Transition>
            </div>

            <!-- Button 2: Get Support with its dedicated flyout -->
            <div class="relative flex items-center">
                <button
                    type="button"
                    :aria-expanded="activeTab === 'support'"
                    aria-controls="get-support-panel"
                    aria-label="Get Support"
                    class="group pointer-events-auto flex cursor-pointer items-center rounded-l-2xl max-sm:px-2 max-sm:py-3 max-sm:[writing-mode:vertical-rl] border-y border-l border-white/20 bg-gradient-to-r from-[#3a4119] to-[#2d3313] px-3.5 py-2.5 text-white shadow-xl transition-all duration-300 ease-out hover:-translate-x-3 hover:border-[#F8B803] hover:from-[#474f1f] hover:to-[#363d17] hover:shadow-2xl focus-visible:ring-2 focus-visible:ring-[#F8B803] focus-visible:ring-offset-2 focus-visible:outline-none sm:px-4 sm:py-3 sm:hover:-translate-x-4"
                    :class="[
                        activeTab === 'support'
                            ? '-translate-x-3 border-[#F8B803] bg-[#2d3313] shadow-2xl ring-1 ring-[#F8B803]/50 sm:-translate-x-4'
                            : '',
                    ]"
                    @mouseenter="handleMouseEnter('support')"
                    @click="toggleTab('support')"
                >
                    <span
                        class="text-xs font-bold tracking-wide whitespace-nowrap text-white drop-shadow-xs sm:text-sm"
                    >
                        Get Support
                    </span>
                </button>

                <!--
                    Flyout 2: Dedicated to Get Support
                    - Mobile (< md): Centered bottom sheet (fixed inset-x-4 bottom-6 max-w-sm mx-auto)
                    - Desktop (md+): Left-attached flyout directly beside the button (right-full mr-3.5 top-1/2 -translate-y-1/2 w-80)
                -->
                <Transition name="flyout-slide">
                    <div
                        v-if="activeTab === 'support'"
                        id="get-support-panel"
                        role="dialog"
                        aria-modal="false"
                        aria-label="Get Support"
                        class="flyout-card pointer-events-auto fixed inset-x-4 bottom-6 z-50 mx-auto max-h-[82vh] w-auto max-w-sm overflow-y-auto rounded-3xl border border-gray-200/90 bg-white/98 p-5 text-gray-900 shadow-2xl ring-1 ring-black/5 backdrop-blur-2xl md:absolute md:top-1/2 md:right-full md:bottom-auto md:left-auto md:mr-3.5 md:max-h-[calc(100vh-4rem)] md:w-[320px] md:max-w-none dark:border-neutral-800 dark:bg-[#121212]/98 dark:text-gray-100"
                        @mouseenter="cancelLeaveTimeout"
                    >
                        <div
                            class="mb-3.5 flex items-center justify-between border-b border-gray-100 pb-2.5 dark:border-neutral-800"
                        >
                            <div>
                                <h3
                                    class="text-xs font-black tracking-widest text-[#4b5320] uppercase dark:text-[#F8B803]"
                                >
                                    Get Support
                                </h3>
                                <p
                                    class="text-[11px] font-medium text-gray-500 dark:text-neutral-400"
                                >
                                    Hotlines & Phone Inquiries
                                </p>
                            </div>
                            <button
                                type="button"
                                aria-label="Close"
                                class="cursor-pointer rounded-full p-1 text-gray-400 transition-colors hover:bg-gray-100 hover:text-gray-700 dark:hover:bg-neutral-800 dark:hover:text-white"
                                @click="closePanel"
                            >
                                <X class="size-4" />
                            </button>
                        </div>

                        <div class="space-y-2">
                            <div
                                v-for="phone in contactDetails.phones"
                                :key="phone.number"
                                class="rounded-xl border border-gray-100 bg-white p-3 text-xs shadow-2xs dark:border-neutral-800/80 dark:bg-neutral-950"
                            >
                                <span
                                    class="block text-[10px] font-semibold text-gray-400"
                                >
                                    {{ phone.label }}
                                </span>
                                <a
                                    :href="phone.href"
                                    class="mt-1 block font-mono font-bold break-all text-gray-900 transition-colors hover:text-[#4b5320] dark:text-white dark:hover:text-[#F8B803]"
                                >
                                    {{ phone.number }}
                                </a>
                            </div>
                        </div>
                    </div>
                </Transition>
            </div>
        </div>
    </aside>
</template>

<style scoped>
/* Base transition timing */
.flyout-slide-enter-active,
.flyout-slide-leave-active {
    transition:
        opacity 0.25s cubic-bezier(0.16, 1, 0.3, 1),
        transform 0.25s cubic-bezier(0.16, 1, 0.3, 1);
}

/* Mobile (< 768px): slide up cleanly from bottom */
@media (max-width: 767px) {
    .flyout-slide-enter-from,
    .flyout-slide-leave-to {
        opacity: 0;
        transform: translateY(1.5rem) scale(0.96);
    }
    .flyout-slide-enter-to,
    .flyout-slide-leave-from {
        opacity: 1;
        transform: translateY(0) scale(1);
    }
}

/* Desktop (>= 768px): anchored vertically at center (-50%), slides horizontally in/out from dock */
@media (min-width: 768px) {
    .flyout-card {
        transform: translateY(-50%);
    }

    .flyout-slide-enter-from,
    .flyout-slide-leave-to {
        opacity: 0;
        transform: translateY(-50%) translateX(1rem) scale(0.98);
    }

    .flyout-slide-enter-to,
    .flyout-slide-leave-from {
        opacity: 1;
        transform: translateY(-50%) translateX(0) scale(1);
    }
}
</style>
