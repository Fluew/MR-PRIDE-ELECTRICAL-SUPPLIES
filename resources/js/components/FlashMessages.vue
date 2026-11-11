<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { AlertCircle, AlertTriangle, CheckCircle2, X } from '@lucide/vue';
import { computed, ref, watch } from 'vue';

/**
 * The result of the last action: success, a warning (e.g. stock shortages), or the business
 * rule that stopped it (validation key "error"). Shown inline at the top of the page.
 */
const props = withDefaults(defineProps<{ success?: boolean }>(), { success: true });

const page = usePage();
const dismissed = ref(false);

const flash = computed(() => (page.props as any).flash ?? {});
const errors = computed(() => (page.props as any).errors ?? {});

const messages = computed(() => {
    const list: { type: 'success' | 'warning' | 'error'; text: string }[] = [];
    if (errors.value.error) list.push({ type: 'error', text: errors.value.error });
    if (flash.value.error) list.push({ type: 'error', text: flash.value.error });
    if (flash.value.warning) list.push({ type: 'warning', text: flash.value.warning });
    if (props.success && flash.value.success) list.push({ type: 'success', text: flash.value.success });
    return list;
});

watch(messages, () => {
    dismissed.value = false;
});
</script>

<template>
    <div v-if="messages.length && !dismissed" class="space-y-2">
        <div
            v-for="(m, i) in messages"
            :key="i"
            :role="m.type === 'error' ? 'alert' : 'status'"
            class="flex items-start gap-3 rounded-xl border px-4 py-3 text-sm"
            :class="{
                'border-rose-200 bg-rose-50 text-rose-800': m.type === 'error',
                'border-amber-200 bg-amber-50 text-amber-800': m.type === 'warning',
                'border-emerald-200 bg-emerald-50 text-emerald-800': m.type === 'success',
            }"
        >
            <AlertCircle v-if="m.type === 'error'" class="mt-0.5 size-4 shrink-0" />
            <AlertTriangle v-else-if="m.type === 'warning'" class="mt-0.5 size-4 shrink-0" />
            <CheckCircle2 v-else class="mt-0.5 size-4 shrink-0" />
            <span class="min-w-0 flex-1">{{ m.text }}</span>
            <button type="button" class="shrink-0 rounded p-0.5 opacity-60 hover:opacity-100" aria-label="Dismiss" @click="dismissed = true">
                <X class="size-4" />
            </button>
        </div>
    </div>
</template>
