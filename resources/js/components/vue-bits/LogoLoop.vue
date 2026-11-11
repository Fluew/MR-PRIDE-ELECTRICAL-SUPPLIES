<script setup lang="ts">
import { ref, computed, onMounted, onUnmounted, watch, nextTick } from 'vue';
import { cn } from '@/lib/utils';

export interface LogoItem {
  src?: string;
  alt: string;
  name?: string;
  href?: string;
}

export interface LogoLoopProps {
  logos: (LogoItem | string)[];
  speed?: number;
  direction?: 'left' | 'right';
  logoHeight?: number;
  gap?: number;
  hoverSpeed?: number;
  scaleOnHover?: boolean;
  fadeOut?: boolean;
  fadeOutColor?: string;
  ariaLabel?: string;
  class?: any;
}

const props = withDefaults(defineProps<LogoLoopProps>(), {
  speed: 70,
  direction: 'left',
  logoHeight: 55,
  gap: 50,
  hoverSpeed: 0,
  scaleOnHover: true,
  fadeOut: true,
  fadeOutColor: '#ffffff',
  ariaLabel: 'Brand partners',
});

const normalizedLogos = computed<LogoItem[]>(() => {
  return (props.logos || []).map((item) => {
    if (typeof item === 'string') {
      return { src: item, alt: 'Brand partner logo' };
    }
    return {
      src: item.src,
      alt: item.alt || item.name || 'Brand partner logo',
      name: item.name,
      href: item.href,
    };
  });
});

const containerRef = ref<HTMLElement | null>(null);
const trackRef = ref<HTMLElement | null>(null);
const sequenceRef = ref<HTMLElement | null>(null);

const isHovered = ref(false);
const sequenceWidth = ref(0);
const repeatCount = ref(3);

let rafId: number | null = null;
let lastTime = 0;
let currentOffset = 0;
let currentSpeed = props.speed;
let resizeObserver: ResizeObserver | null = null;

const calculateDimensions = () => {
  if (!sequenceRef.value || !containerRef.value) return;

  const seqWidth = sequenceRef.value.offsetWidth;
  const contWidth = containerRef.value.offsetWidth;

  if (seqWidth > 0) {
    sequenceWidth.value = seqWidth;
    // Ensure the track has enough sequences to seamlessly cover viewport + 1 full loop
    const needed = Math.max(2, Math.ceil(contWidth / seqWidth) + 1);
    repeatCount.value = needed;
  }
};

const updateAnimation = (now: number) => {
  if (!lastTime) lastTime = now;
  const dt = Math.min((now - lastTime) / 1000, 0.1);
  lastTime = now;

  const targetSpeed = isHovered.value ? props.hoverSpeed : props.speed;
  // Smoothly accelerate or decelerate
  currentSpeed += (targetSpeed - currentSpeed) * Math.min(dt * 8, 1);

  const seqW = sequenceWidth.value;

  if (seqW > 0 && currentSpeed !== 0) {
    const isReducedMotion = typeof window !== 'undefined' && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    if (!isReducedMotion) {
      if (props.direction === 'left') {
        currentOffset -= currentSpeed * dt;
        if (currentOffset <= -seqW) {
          currentOffset += seqW;
        }
      } else {
        currentOffset += currentSpeed * dt;
        if (currentOffset >= 0) {
          currentOffset -= seqW;
        }
      }

      if (trackRef.value) {
        trackRef.value.style.transform = `translate3d(${currentOffset}px, 0, 0)`;
      }
    }
  }

  rafId = requestAnimationFrame(updateAnimation);
};

const onImageLoad = () => {
  calculateDimensions();
};

onMounted(() => {
  nextTick(() => {
    calculateDimensions();

    if (props.direction === 'right' && sequenceWidth.value > 0) {
      currentOffset = -sequenceWidth.value;
    }

    if (containerRef.value) {
      resizeObserver = new ResizeObserver(() => {
        calculateDimensions();
      });
      resizeObserver.observe(containerRef.value);
      if (sequenceRef.value) {
        resizeObserver.observe(sequenceRef.value);
      }
    }

    lastTime = performance.now();
    rafId = requestAnimationFrame(updateAnimation);
  });
});

onUnmounted(() => {
  if (rafId !== null) {
    cancelAnimationFrame(rafId);
  }
  if (resizeObserver) {
    resizeObserver.disconnect();
  }
});

watch(
  () => [props.logos, props.gap, props.logoHeight],
  () => {
    nextTick(() => {
      calculateDimensions();
    });
  },
  { deep: true }
);

watch(
  () => props.direction,
  (newDir) => {
    if (newDir === 'right' && currentOffset === 0 && sequenceWidth.value > 0) {
      currentOffset = -sequenceWidth.value;
    }
  }
);
</script>

<template>
  <div
    ref="containerRef"
    role="region"
    :aria-label="ariaLabel"
    :class="cn('logo-loop relative w-full overflow-hidden select-none', props.class)"
    @mouseenter="isHovered = true"
    @mouseleave="isHovered = false"
  >
    <!-- Left edge fade-out gradient -->
    <div
      v-if="fadeOut"
      class="pointer-events-none absolute inset-y-0 left-0 z-10 w-12 sm:w-24 transition-opacity"
      :style="{
        background: `linear-gradient(to right, ${fadeOutColor} 0%, transparent 100%)`,
      }"
      aria-hidden="true"
    />

    <!-- Right edge fade-out gradient -->
    <div
      v-if="fadeOut"
      class="pointer-events-none absolute inset-y-0 right-0 z-10 w-12 sm:w-24 transition-opacity"
      :style="{
        background: `linear-gradient(to left, ${fadeOutColor} 0%, transparent 100%)`,
      }"
      aria-hidden="true"
    />

    <!-- Infinite horizontal track -->
    <div
      ref="trackRef"
      class="logo-loop__track flex items-center will-change-transform"
      :style="{ willChange: 'transform' }"
    >
      <!-- Base sequence (primary track with accessible links/alt text) -->
      <div
        ref="sequenceRef"
        class="logo-loop__sequence flex shrink-0 items-center"
        :style="{ gap: `${gap}px`, paddingRight: `${gap}px` }"
      >
        <template v-for="(logo, index) in normalizedLogos" :key="`base-${index}`">
          <component
            :is="logo.href ? 'a' : 'div'"
            :href="logo.href"
            :target="logo.href ? '_blank' : undefined"
            :rel="logo.href ? 'noopener noreferrer' : undefined"
            class="logo-loop__item flex shrink-0 items-center justify-center transition-all duration-300"
            :class="[
              scaleOnHover ? 'hover:scale-110 active:scale-95' : '',
              'opacity-80 grayscale-[20%] hover:opacity-100 hover:grayscale-0',
            ]"
            :style="{ height: `${logoHeight}px` }"
          >
            <img
              v-if="logo.src"
              :src="logo.src"
              :alt="logo.alt"
              :title="logo.alt"
              loading="lazy"
              class="h-full w-auto max-w-none object-contain pointer-events-none"
              @load="onImageLoad"
            />
            <span
              v-else
              class="px-3 py-1.5 text-xs font-semibold text-gray-700 bg-gray-100 rounded-lg whitespace-nowrap"
            >
              {{ logo.alt }}
            </span>
          </component>
        </template>
      </div>

      <!-- Repeated sequences for seamless wrap-around (marked aria-hidden to prevent duplicate screen reader output) -->
      <div
        v-for="rep in repeatCount"
        :key="`rep-${rep}`"
        aria-hidden="true"
        class="logo-loop__sequence flex shrink-0 items-center"
        :style="{ gap: `${gap}px`, paddingRight: `${gap}px` }"
      >
        <template v-for="(logo, index) in normalizedLogos" :key="`rep-${rep}-${index}`">
          <div
            class="logo-loop__item flex shrink-0 items-center justify-center transition-all duration-300"
            :class="[
              scaleOnHover ? 'hover:scale-110 active:scale-95' : '',
              'opacity-80 grayscale-[20%] hover:opacity-100 hover:grayscale-0',
            ]"
            :style="{ height: `${logoHeight}px` }"
          >
            <img
              v-if="logo.src"
              :src="logo.src"
              :alt="logo.alt"
              loading="lazy"
              class="h-full w-auto max-w-none object-contain pointer-events-none"
            />
            <span
              v-else
              class="px-3 py-1.5 text-xs font-semibold text-gray-700 bg-gray-100 rounded-lg whitespace-nowrap"
            >
              {{ logo.alt }}
            </span>
          </div>
        </template>
      </div>
    </div>
  </div>
</template>

<style scoped>
.logo-loop {
  -webkit-mask-image: linear-gradient(to right, transparent 0%, black 5%, black 95%, transparent 100%);
  mask-image: linear-gradient(to right, transparent 0%, black 5%, black 95%, transparent 100%);
}
@media (prefers-reduced-motion: reduce) {
  .logo-loop__track {
    overflow-x: auto;
    scroll-behavior: smooth;
    transform: none !important;
  }
}
</style>
