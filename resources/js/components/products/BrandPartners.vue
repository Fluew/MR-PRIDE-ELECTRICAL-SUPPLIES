<script setup lang="ts">
import { computed } from 'vue';
import LogoLoop, { type LogoItem } from '@/components/ui/LogoLoop.vue';

export interface LegacyBrandPartner {
    name: string;
    logo?: string;
}

const props = withDefaults(
    defineProps<{
        logos?: (LogoItem | string)[];
        brands?: LegacyBrandPartner[];
        sheet?: { src: string; width: number; height: number; regions: string[] };
        direction?: 'left' | 'right';
        ariaLabel?: string;
        speed?: number;
        logoHeight?: number;
        gap?: number;
        fadeOutColor?: string;
    }>(),
    {
        direction: 'left',
        speed: 70,
        logoHeight: 52,
        gap: 48,
        fadeOutColor: '#f9fafb',
    }
);

const activeLogos = computed<LogoItem[]>(() => {
    if (props.logos && props.logos.length > 0) {
        return props.logos.map((item) =>
            typeof item === 'string' ? { src: item, alt: 'Brand partner logo' } : item
        );
    }
    if (props.brands && props.brands.length > 0) {
        return props.brands.map((b) => ({
            name: b.name,
            alt: b.name,
            src: b.logo,
        }));
    }
    return [];
});
</script>

<template>
    <div class="border-t border-gray-100 bg-gray-50/70 px-4 py-8 sm:px-8 sm:py-10">
        <div class="mb-5 flex items-center justify-between">
            <h3 class="text-xs sm:text-sm font-semibold text-gray-900">
                Brand Partners
            </h3>
            <span class="text-[11px] font-semibold text-gray-400 hidden sm:inline">
                Authorized Manufacturers & Suppliers
            </span>
        </div>

        <LogoLoop
            v-if="activeLogos.length > 0"
            :logos="activeLogos"
            :speed="speed"
            :direction="direction"
            :logo-height="logoHeight"
            :gap="gap"
            :hover-speed="0"
            :scale-on-hover="true"
            :fade-out="true"
            :fade-out-color="fadeOutColor"
            :aria-label="ariaLabel || 'Brand partners'"
            class="py-2"
        />

        <div
            v-else
            class="rounded-xl border border-dashed border-gray-300 bg-white px-5 py-6 text-center text-sm text-gray-500"
        >
            Brand partner logos coming soon.
        </div>
    </div>
</template>
