<script setup lang="ts">
/**
 * In-app notifications for workflow steps (purchase requests, orders, deliveries, invoices, stock
 * postings). Polls quietly once a minute; opening the list marks it read.
 */
import { router, usePage } from '@inertiajs/vue3';
import { Bell } from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

interface Item { id: string; title: string; message: string; url: string | null; read: boolean; at: string }

const page = usePage();
const teamSlug = computed(() => (page.props as any).currentTeam?.slug || (page.props as any).team?.slug || '');
const open = ref(false);
const unread = ref(0);
const items = ref<Item[]>([]);
let timer: ReturnType<typeof setInterval> | null = null;

const csrf = () => (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement | null)?.content ?? '';

const load = async () => {
    if (!teamSlug.value) return;
    try {
        const res = await fetch(`/${teamSlug.value}/notifications`, { headers: { Accept: 'application/json' } });
        if (!res.ok) return;
        const data = await res.json();
        unread.value = data.unread;
        items.value = data.items;
    } catch {
        // Offline or signed out: keep the last known list.
    }
};

const toggle = async () => {
    open.value = !open.value;
    if (open.value && unread.value > 0) {
        await fetch(`/${teamSlug.value}/notifications/read`, { method: 'POST', headers: { 'X-CSRF-TOKEN': csrf(), Accept: 'application/json', 'Content-Type': 'application/json' }, body: '{}' }).catch(() => null);
        unread.value = 0;
    }
};

const go = (item: Item) => {
    open.value = false;
    if (item.url) router.visit(item.url);
};

onMounted(() => {
    load();
    timer = setInterval(load, 60000);
});
onBeforeUnmount(() => { if (timer) clearInterval(timer); });
</script>

<template>
    <div class="relative">
        <button type="button" class="relative rounded-lg p-2 text-gray-500 hover:bg-gray-100 hover:text-gray-900" :aria-label="`Notifications${unread ? `, ${unread} unread` : ''}`" :aria-expanded="open" @click="toggle">
            <Bell class="size-4" />
            <span v-if="unread" class="absolute -right-0.5 -top-0.5 min-w-4 rounded-full bg-rose-600 px-1 text-center text-[10px] font-semibold leading-4 text-white">{{ unread > 9 ? '9+' : unread }}</span>
        </button>
        <div v-if="open" class="absolute right-0 z-50 mt-2 w-80 max-w-[calc(100vw-2rem)] overflow-hidden rounded-xl border border-gray-200 bg-white" role="dialog" aria-label="Notifications">
            <div class="border-b border-gray-100 px-4 py-2 text-sm font-medium text-gray-900">Notifications</div>
            <ul class="max-h-96 overflow-y-auto">
                <li v-if="!items.length" class="px-4 py-6 text-center text-xs text-gray-500">No notifications.</li>
                <li v-for="n in items" :key="n.id">
                    <button type="button" class="block w-full border-b border-gray-50 px-4 py-2.5 text-left hover:bg-gray-50" :class="n.read ? '' : 'bg-amber-50/40'" @click="go(n)">
                        <div class="text-sm font-medium text-gray-900">{{ n.title }}</div>
                        <div class="text-xs text-gray-600">{{ n.message }}</div>
                        <div class="mt-0.5 text-[11px] text-gray-400">{{ new Date(n.at).toLocaleString() }}</div>
                    </button>
                </li>
            </ul>
        </div>
    </div>
</template>
