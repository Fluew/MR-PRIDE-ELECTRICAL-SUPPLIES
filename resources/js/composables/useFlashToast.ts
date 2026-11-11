import { router, usePage } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import { watch } from 'vue';

type FlashType = 'success' | 'error' | 'warning' | 'info';

/**
 * Watches Inertia shared flash data and fires vue-sonner toasts automatically.
 *
 * Call once in a layout that wraps authenticated pages (e.g. AppSidebarLayout).
 * Replaces every per-page showNotification() helper, the manual FlashMessages component,
 * and the old `Inertia::flash('toast')` pathway (which is kept for backward compat).
 *
 * Supports: ->with('success'|'error'|'warning'|'info', 'message')
 */
export function useFlashToast(): void {
    const page = usePage();

    // Track the last message we showed to avoid duplicates on the same navigation
    let lastMessage = '';
    let lastTimestamp = 0;

    const fire = (type: FlashType, message: string) => {
        const now = Date.now();
        // Deduplicate: same message within 500ms is a duplicate
        if (message === lastMessage && now - lastTimestamp < 500) return;
        lastMessage = message;
        lastTimestamp = now;
        toast[type](message);
    };

    // Watch Inertia's shared flash props for standard with('success', ...) style
    watch(
        () => {
            const flash = (page.props as any).flash;
            return flash
                ? { s: flash.success, e: flash.error, w: flash.warning, i: flash.info }
                : null;
        },
        (flash) => {
            if (!flash) return;
            if (flash.e) fire('error', flash.e);
            if (flash.w) fire('warning', flash.w);
            if (flash.s) fire('success', flash.s);
            if (flash.i) fire('info', flash.i);
        },
        { immediate: true, deep: true },
    );
}

/**
 * Fires a toast directly from component code (for client-side-only messages,
 * e.g. after an Inertia onSuccess callback).
 */
export function showToast(message: string, type: FlashType = 'success'): void {
    toast[type](message);
}
