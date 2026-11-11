<script setup lang="ts">
// Adapted from Vue Bits SpotlightCard; see LICENSE.md.
import { ref, useTemplateRef } from 'vue';
const {
    as = 'div',
    className = '',
    spotlightColor = 'rgba(248, 184, 3, 0.12)',
} = defineProps<{ as?: string; className?: string; spotlightColor?: string }>();
const divRef = useTemplateRef<HTMLDivElement>('divRef');
const isFocused = ref(false);
const position = ref({ x: 0, y: 0 });
const opacity = ref(0);
function handleMouseMove(event: MouseEvent) {
    if (!divRef.value || isFocused.value) {
        return;
    }

    const rect = divRef.value.getBoundingClientRect();
    position.value = {
        x: event.clientX - rect.left,
        y: event.clientY - rect.top,
    };
}
function handleFocus() {
    isFocused.value = true;
    position.value = { x: (divRef.value?.clientWidth ?? 0) / 2, y: 0 };
    opacity.value = 1;
}
function handleBlur(event: FocusEvent) {
    if (divRef.value?.contains(event.relatedTarget as Node)) {
        return;
    }

    isFocused.value = false;
    opacity.value = 0;
}
</script>
<template>
    <component
        :is="as"
        ref="divRef"
        :class="[
            'relative isolate rounded-2xl border border-gray-200 bg-white',
            className,
        ]"
        @mousemove="handleMouseMove"
        @focusin="handleFocus"
        @focusout="handleBlur"
        @mouseenter="opacity = 1"
        @mouseleave="opacity = isFocused ? 1 : 0"
    >
        <div
            aria-hidden="true"
            class="pointer-events-none absolute inset-0 -z-10 rounded-[inherit] transition-opacity duration-300 motion-reduce:hidden"
            :style="{
                opacity,
                background: `radial-gradient(350px circle at ${position.x}px ${position.y}px, ${spotlightColor}, transparent 80%)`,
            }"
        />
        <slot />
    </component>
</template>
