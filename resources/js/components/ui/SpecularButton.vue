<script setup lang="ts">
import { computed } from 'vue';
import { router } from '@inertiajs/vue3';
import { cn } from '@/lib/utils';

export interface SpecularButtonProps {
  size?: 'sm' | 'md' | 'lg' | 'default' | 'icon' | 'icon-sm' | 'icon-lg';
  variant?: 'primary' | 'brand' | 'brand-gold' | 'secondary' | 'outline' | 'destructive' | 'danger' | 'success' | 'ghost' | 'neutral' | 'default';
  radius?: number;
  tint?: string;
  tintOpacity?: number;
  blur?: number;
  textColor?: string;
  disabled?: boolean;
  type?: 'button' | 'submit' | 'reset';
  as?: any;
  href?: string | { url: string; method?: string } | any;
  class?: any;
}

const props = withDefaults(defineProps<SpecularButtonProps>(), {
  size: 'md',
  radius: 12,
  blur: 0,
  disabled: false,
  type: 'button',
  as: 'button',
});

const emit = defineEmits<{
  (e: 'click', event: MouseEvent): void;
}>();

// Variant presets tailored to MR-Pride application
const variantConfig = computed(() => {
  switch (props.variant) {
    case 'primary':
    case 'brand':
    case 'default':
      return {
        tint: '#4b5320',
        tintOpacity: 1,
        textColor: '#ffffff',
        hoverBg: '#5a6328',
        borderClass: 'border-transparent',
      };
    case 'brand-gold':
      return {
        tint: '#f8b803',
        tintOpacity: 1,
        textColor: '#111827',
        hoverBg: '#fac52e',
        borderClass: 'border-transparent',
      };
    case 'secondary':
    case 'outline':
      return {
        tint: '#ffffff',
        tintOpacity: 0.95,
        textColor: '#374151',
        hoverBg: '#f3f4f6',
        borderClass: 'border border-gray-200 dark:border-gray-700',
      };
    case 'destructive':
    case 'danger':
      return {
        tint: '#dc2626',
        tintOpacity: 1,
        textColor: '#ffffff',
        hoverBg: '#ef4444',
        borderClass: 'border-transparent',
      };
    case 'success':
      return {
        tint: '#16a34a',
        tintOpacity: 1,
        textColor: '#ffffff',
        hoverBg: '#22c55e',
        borderClass: 'border-transparent',
      };
    case 'ghost':
      return {
        tint: '#ffffff',
        tintOpacity: 0,
        textColor: 'inherit',
        hoverBg: 'rgba(0, 0, 0, 0.05)',
        borderClass: 'border-transparent',
      };
    default:
      // Neutral default
      return {
        tint: '#ffffff',
        tintOpacity: 0,
        textColor: '#f5f5f5',
        hoverBg: 'rgba(255, 255, 255, 0.1)',
        borderClass: 'border-transparent',
      };
  }
});

const resolvedTint = computed(() => props.tint ?? variantConfig.value.tint);
const resolvedTintOpacity = computed(() => props.tintOpacity ?? variantConfig.value.tintOpacity);
const resolvedTextColor = computed(() => props.textColor ?? variantConfig.value.textColor);

const resolvedTag = computed(() => {
  if (props.as && props.as !== 'button') return props.as;
  if (props.href) return 'a';
  return 'button';
});

const resolvedHref = computed(() => {
  if (!props.href) return undefined;
  if (typeof props.href === 'object' && props.href !== null && 'url' in props.href) {
    return props.href.url;
  }
  return String(props.href);
});

const sizeClass = computed(() => {
  switch (props.size) {
    case 'sm':
      return 'min-h-8 text-xs px-3 py-1.5';
    case 'lg':
      return 'min-h-12 text-base px-6 py-3 font-semibold';
    case 'icon':
      return 'size-9 p-0';
    case 'icon-sm':
      return 'size-8 p-0';
    case 'icon-lg':
      return 'size-10 p-0';
    case 'md':
    case 'default':
    default:
      return 'min-h-10 text-sm px-4 py-2 font-medium';
  }
});

const handleClick = (e: MouseEvent) => {
  if (props.disabled) {
    e.preventDefault();
    e.stopPropagation();
    return;
  }
  emit('click', e);

  if (props.href && !e.defaultPrevented && !e.metaKey && !e.ctrlKey && !e.shiftKey && e.button === 0) {
    const rawUrl = resolvedHref.value;
    if (rawUrl && !rawUrl.startsWith('http://') && !rawUrl.startsWith('https://') && !rawUrl.startsWith('//')) {
      if (rawUrl.startsWith('#')) {
        const el = document.querySelector(rawUrl);
        if (el) {
          e.preventDefault();
          el.scrollIntoView({ behavior: 'smooth' });
        }
      } else {
        e.preventDefault();
        router.visit(rawUrl);
      }
    }
  }
};
</script>

<template>
  <component
    :is="resolvedTag"
    :type="resolvedTag === 'button' ? type : undefined"
    :href="resolvedHref"
    :disabled="disabled"
    @click="handleClick"
    :class="cn(
      'specular-btn relative inline-flex items-center justify-center select-none font-medium transition-colors duration-150 disabled:pointer-events-none disabled:opacity-50 outline-none focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:ring-[#4b5320] dark:focus-visible:ring-[#f8b803]',
      sizeClass,
      variantConfig.borderClass,
      props.class
    )"
    :style="{
      '--sb-radius': `${radius}px`,
      '--sb-tint': resolvedTint,
      '--sb-tint-opacity': resolvedTintOpacity,
      '--sb-blur': `${blur}px`,
      '--sb-text-color': resolvedTextColor,
      '--sb-hover-bg': variantConfig.hoverBg,
      color: resolvedTextColor,
      backgroundColor: resolvedTintOpacity === 0 ? 'transparent' : `color-mix(in srgb, ${resolvedTint} calc(${resolvedTintOpacity} * 100%), transparent)`,
      borderRadius: `${radius}px`,
      backdropFilter: blur > 0 ? `blur(${blur}px)` : undefined,
    }"
  >
    <span class="specular-btn__label relative z-[2] inline-flex w-full items-center justify-center gap-2">
      <slot />
    </span>
  </component>
</template>

<style scoped>
.specular-btn:hover:not(:disabled) {
  background-color: var(--sb-hover-bg) !important;
}
</style>
