<script setup lang="ts">
import { ChevronDown } from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

type Option = { value: string; label: string };

const props = defineProps<{
    modelValue: string[];
    options: Option[];
    label: string;
    id: string;
    error?: string;
}>();

const emit = defineEmits<{
    (e: 'update:modelValue', value: string[]): void;
}>();

const open = ref(false);
const root = ref<HTMLElement | null>(null);

const selectedLabels = computed(() =>
    props.options
        .filter((o) => props.modelValue.includes(o.value))
        .map((o) => o.label),
);

const summary = computed(() => {
    if (selectedLabels.value.length === 0) {
        return 'Select modules…';
    }
    if (selectedLabels.value.length <= 2) {
        return selectedLabels.value.join(', ');
    }
    return `${selectedLabels.value.length} modules selected`;
});

function toggle(value: string) {
    const next = props.modelValue.includes(value)
        ? props.modelValue.filter((v) => v !== value)
        : // keep assignment order stable: options order
          props.options
              .map((o) => o.value)
              .filter((v) => v === value || props.modelValue.includes(v));
    emit('update:modelValue', next);
}

function onDocumentClick(event: MouseEvent) {
    if (root.value && !root.value.contains(event.target as Node)) {
        open.value = false;
    }
}

function onKeydown(event: KeyboardEvent) {
    if (event.key === 'Escape') {
        open.value = false;
    }
}

onMounted(() => document.addEventListener('click', onDocumentClick));
onBeforeUnmount(() => document.removeEventListener('click', onDocumentClick));
</script>

<template>
    <div ref="root" class="relative" @keydown="onKeydown">
        <button
            :id="id"
            type="button"
            class="peer flex w-full items-center justify-between rounded-xl border bg-white px-3.5 pt-5 pb-2 text-left text-xs text-gray-900 focus:border-[#4b5320] focus:ring-1 focus:ring-[#4b5320] focus:outline-none sm:text-sm"
            :class="error ? 'border-red-500' : 'border-gray-200'"
            aria-haspopup="listbox"
            :aria-expanded="open"
            @click="open = !open"
        >
            <span
                class="truncate"
                :class="selectedLabels.length ? '' : 'text-gray-400'"
                >{{ summary }}</span
            >
            <ChevronDown
                class="ml-2 size-4 shrink-0 text-gray-400 transition-transform"
                :class="open ? 'rotate-180' : ''"
            />
        </button>
        <label
            :for="id"
            class="pointer-events-none absolute top-3.5 left-3.5 origin-[0] -translate-y-2.5 scale-75 transform text-xs font-semibold text-[#4b5320]"
        >
            {{ label }}
        </label>

        <div
            v-if="open"
            role="listbox"
            aria-multiselectable="true"
            class="absolute z-20 mt-1 max-h-60 w-full overflow-y-auto rounded-xl border border-gray-200 bg-white p-1.5 shadow-lg"
        >
            <label
                v-for="option in options"
                :key="option.value"
                class="flex cursor-pointer items-center gap-2.5 rounded-lg px-2.5 py-2 text-xs text-gray-800 select-none hover:bg-gray-50 sm:text-sm"
            >
                <input
                    type="checkbox"
                    class="size-4 cursor-pointer rounded border-gray-300 text-[#4b5320] focus:ring-[#4b5320]"
                    :checked="modelValue.includes(option.value)"
                    :value="option.value"
                    @change="toggle(option.value)"
                />
                <span>{{ option.label }}</span>
            </label>
        </div>

        <span v-if="error" class="mt-1 block text-xs text-red-500">{{
            error
        }}</span>
    </div>
</template>
