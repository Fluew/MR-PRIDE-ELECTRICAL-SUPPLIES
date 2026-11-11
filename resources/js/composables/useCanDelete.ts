import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

/**
 * Only administrators may delete records (or use destructive "Replace" imports). This only controls UI visibility —
 * the server enforces the same rule (see the `admin_delete` route middleware).
 */
export function useCanDelete() {
    const page = usePage();

    // `auth.can_delete` is set by the server from User::isAdmin().
    return computed(() => Boolean((page.props as any).auth?.can_delete));
}
