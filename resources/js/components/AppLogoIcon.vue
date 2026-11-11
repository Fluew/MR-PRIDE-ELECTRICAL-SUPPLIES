<script setup lang="ts">
import type { HTMLAttributes } from 'vue';
import { cn } from '@/lib/utils';

type Props = {
    class?: HTMLAttributes['class'];
    className?: HTMLAttributes['class'];
};

const props = defineProps<Props>();
</script>

<template>
    <img
        src="/LOGO_MR_PRIDE.webp"
        alt="MR-Pride Logo"
        :class="cn('object-contain', props.class, props.className)"
        v-bind="$attrs"
    />
</template>
