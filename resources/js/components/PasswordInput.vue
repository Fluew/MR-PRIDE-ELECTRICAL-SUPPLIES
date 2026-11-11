<script setup lang="ts">
import { Eye, EyeOff } from '@lucide/vue';
import { ref, useTemplateRef } from 'vue';
import type { HTMLAttributes } from 'vue';
import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';

defineOptions({ inheritAttrs: false });

const props = defineProps<{
    class?: HTMLAttributes['class'];
}>();

const showPassword = ref(false);
const inputRef = useTemplateRef('inputRef');

defineExpose({
    $el: inputRef,
    focus: () => inputRef.value?.$el?.focus(),
});
</script>

<template>
    <div class="relative">
        <Input
            ref="inputRef"
            v-bind="$attrs"
            :type="showPassword ? 'text' : 'password'"
            :class="cn(props.class, 'pr-11')"
        />
        <button
            type="button"
            tabindex="-1"
            @click="showPassword = !showPassword"
            :class="
                cn(
                    'absolute inset-y-0 right-0 flex cursor-pointer items-center rounded-r-md px-3.5 text-muted-foreground transition-colors hover:text-foreground focus-visible:ring-[3px] focus-visible:ring-ring focus-visible:outline-none',
                )
            "
            :aria-label="showPassword ? 'Hide password' : 'Show password'"
            :aria-pressed="showPassword"
        >
            <EyeOff v-if="showPassword" class="size-4 shrink-0" />
            <Eye v-else class="size-4 shrink-0" />
        </button>
    </div>
</template>
