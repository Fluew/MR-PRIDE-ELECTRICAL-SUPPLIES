<script setup lang="ts">
type Props = {
    title: string;
    description?: string;
    variant?: 'default' | 'small';
};

withDefaults(defineProps<Props>(), {
    variant: 'default',
});
</script>

<template>
    <header>
        <h2
            :class="
                variant === 'small' ? 'section-title' : 'page-title'
            "
        >
            {{ title }}
        </h2>
        <p v-if="description" :class="variant === 'small' ? 'section-subtitle' : 'page-subtitle'">
            {{ description }}
        </p>
    </header>
</template>
