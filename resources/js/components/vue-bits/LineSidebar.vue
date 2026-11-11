<script setup lang="ts">
import { ref, computed, onBeforeUnmount } from 'vue';
import { Link } from '@inertiajs/vue3';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { useSidebar } from '@/components/ui/sidebar';
import type { NavItem } from '@/types';

export interface LineSidebarProps {
  items: NavItem[];
  label?: string;
  accentColor?: string;
  proximityRadius?: number;
  maxShift?: number;
  displayNumbers?: boolean;
}

const props = withDefaults(defineProps<LineSidebarProps>(), {
  label: 'Operations',
  accentColor: '#F8B803',
  proximityRadius: 130,
  maxShift: 5,
  displayNumbers: true,
});

const { isCurrentUrl } = useCurrentUrl();
const { isMobile, setOpenMobile, state } = useSidebar();

const isCollapsed = computed(() => state.value === 'collapsed');

function closeOnMobile() {
  if (isMobile.value) {
    setOpenMobile(false);
  }
}

// Proximity effect: each item gets a --proximity value (0..1) that its CSS turns into a shift and
// icon scale. It is written straight to the DOM per frame, so Vue never re-renders the list.
const listContainerRef = ref<HTMLElement | null>(null);
const itemEls: HTMLElement[] = [];
let centers: number[] = [];
const current: number[] = [];
let target: number[] = [];
let frame: number | null = null;

function setItemRef(el: any, index: number) {
  if (el) {
    itemEls[index] = (el.$el || el) as HTMLElement;
  }
}

// Item positions only change on scroll or collapse, so measure once per hover, not per move.
function measure() {
  centers = itemEls.map((el) => {
    const rect = el.getBoundingClientRect();
    return rect.top + rect.height / 2;
  });
}

function handleMouseMove(e: MouseEvent) {
  if (isCollapsed.value) return;
  if (centers.length === 0) measure();

  const radius = props.proximityRadius;
  target = centers.map((center) => {
    const distance = Math.abs(e.clientY - center);
    if (distance >= radius) return 0;
    const t = 1 - distance / radius;
    return t * t * (3 - 2 * t); // smoothstep
  });
  animate();
}

function forgetPositions() {
  centers = [];
}

function handleMouseLeave() {
  forgetPositions();
  target = target.map(() => 0);
  animate();
}

function animate() {
  if (frame === null) frame = requestAnimationFrame(tick);
}

// Eases each item toward its target and stops as soon as everything has settled.
function tick() {
  frame = null;
  let moving = false;

  itemEls.forEach((el, i) => {
    const from = current[i] ?? 0;
    const to = target[i] ?? 0;
    const next = Math.abs(to - from) > 0.003 ? from + (to - from) * 0.18 : to;

    if (next !== to) moving = true;
    if (next !== from) {
      current[i] = next;
      el.style.setProperty('--proximity', next.toFixed(3));
    }
  });

  if (moving) animate();
}

onBeforeUnmount(() => {
  if (frame !== null) cancelAnimationFrame(frame);
});
</script>

<template>
  <div class="line-sidebar-container flex flex-col w-full select-none">
    <!-- Category Eyebrow Label -->
    <div
      v-if="label"
      class="mb-2 px-3 text-[10px] font-bold tracking-[0.16em] text-white/50 uppercase transition-opacity duration-300 group-data-[collapsible=icon]:hidden"
    >
      {{ label }}
    </div>

    <!-- Navigation List with Proximity Trackers -->
    <nav
      ref="listContainerRef"
      @mousemove="handleMouseMove"
      @mouseleave="handleMouseLeave"
      @wheel.passive="forgetPositions"
      class="relative flex flex-col gap-1 w-full"
      aria-label="Admin Operations"
    >
      <div
        v-for="(item, index) in items"
        :key="item.title"
        :ref="(el) => setItemRef(el, index)"
        class="relative w-full group/item"
      >
        <Link
          :href="item.href"
          prefetch
          @click="closeOnMobile"
          :aria-current="isCurrentUrl(item.href) ? 'page' : undefined"
          :title="isCollapsed ? item.title : undefined"
          class="relative flex items-center w-full min-h-11 rounded-xl px-3 transition-colors duration-200 outline-none focus-visible:ring-2 focus-visible:ring-[#F8B803] group-data-[collapsible=icon]:justify-center group-data-[collapsible=icon]:px-0 group-data-[collapsible=icon]:size-10 group-data-[collapsible=icon]:mx-auto"
          :class="[
            isCurrentUrl(item.href)
              ? 'bg-[#F8B803] font-bold text-gray-950 shadow-sm'
              : 'text-white/80 hover:text-white hover:bg-white/10'
          ]"
          :style="isCollapsed ? undefined : { transform: `translateX(calc(var(--proximity, 0) * ${maxShift}px))` }"
        >

          <!-- Item Index Numbering (e.g. 01, 02) -->
          <span
            v-if="displayNumbers && !isCollapsed"
            class="text-[10px] font-mono tracking-wider transition-colors duration-200 mr-2 group-data-[collapsible=icon]:hidden"
            :class="[
              isCurrentUrl(item.href)
                ? 'text-gray-950/70 font-bold'
                : 'text-white/40 group-hover/item:text-[#F8B803]'
            ]"
          >
            0{{ index + 1 }}
          </span>

          <!-- Lucide Icon with Proximity Scale Effect -->
          <component
            v-if="item.icon"
            :is="item.icon"
            class="size-4.5 shrink-0 transition-transform duration-200"
            :class="[
              isCurrentUrl(item.href)
                ? 'text-gray-950 stroke-[2.2]'
                : 'text-white/85 group-hover/item:text-white stroke-[1.8]'
            ]"
            :style="isCurrentUrl(item.href) ? undefined : { transform: 'scale(calc(1 + var(--proximity, 0) * 0.15))' }"
          />

          <!-- Navigation Label Title -->
          <span
            class="ml-3 truncate text-xs sm:text-sm tracking-tight transition-opacity duration-200 group-data-[collapsible=icon]:hidden"
            :class="[
              isCurrentUrl(item.href)
                ? 'text-gray-950 font-bold'
                : 'text-white/85 group-hover/item:text-white font-medium'
            ]"
          >
            {{ item.title }}
          </span>
        </Link>
      </div>
    </nav>
  </div>
</template>

<style scoped>
.line-sidebar-container {
  will-change: transform;
}
</style>
