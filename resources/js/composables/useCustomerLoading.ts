import { router } from '@inertiajs/vue3';
import { ref } from 'vue';

// Fast (and prefetched) page changes never show the cover; only slow ones do.
const SHOW_AFTER_MS = 200;
// Once shown, keep it up briefly so it doesn't flash on and off.
const MIN_VISIBLE_MS = 300;

const isVisible = ref(false);
let initialized = false;
let showTimer: ReturnType<typeof setTimeout> | null = null;
let hideTimer: ReturnType<typeof setTimeout> | null = null;
let shownAt = 0;

function clearTimers(): void {
    if (showTimer) clearTimeout(showTimer);
    if (hideTimer) clearTimeout(hideTimer);
    showTimer = hideTimer = null;
}

export function useCustomerLoading() {
    function init(): void {
        if (initialized || typeof window === 'undefined') return;
        initialized = true;

        router.on('start', (event) => {
            const visit = event.detail.visit;
            // Only full page changes; form posts and partial reloads keep the page usable.
            if (visit.method !== 'get' || visit.only.length > 0 || visit.prefetch) return;

            clearTimers();
            showTimer = setTimeout(() => {
                isVisible.value = true;
                shownAt = Date.now();
            }, SHOW_AFTER_MS);
        });

        router.on('finish', () => {
            if (showTimer) {
                clearTimeout(showTimer);
                showTimer = null;
            }
            if (!isVisible.value) return;

            const remaining = Math.max(0, MIN_VISIBLE_MS - (Date.now() - shownAt));
            hideTimer = setTimeout(() => {
                isVisible.value = false;
            }, remaining);
        });
    }

    return { isVisible, init };
}
