<script setup lang="ts">
import { ref, computed, onMounted, onBeforeUnmount, watch, nextTick } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { ArrowRight, X, Menu, ExternalLink } from '@lucide/vue';
import gsap from 'gsap';

export interface StaggeredMenuItem {
  name: string;
  href: string;
  icon?: any;
}

export interface StaggeredMenuSocialItem {
  name: string;
  href: string;
  icon?: any;
}

export interface StaggeredMenuProps {
  position?: 'left' | 'right';
  colors?: string[];
  items: StaggeredMenuItem[];
  socialItems?: StaggeredMenuSocialItem[];
  displaySocials?: boolean;
  displayItemNumbering?: boolean;
  closeOnClickAway?: boolean;
  accentColor?: string;
  quotationHref?: string;
}

const props = withDefaults(defineProps<StaggeredMenuProps>(), {
  position: 'right',
  colors: () => ['#1e250c', '#333b14', '#4b5320'],
  socialItems: () => [
    {
      name: 'Facebook',
      href: 'https://web.facebook.com/mrprideelectricalsupplies',
    },
  ],
  displaySocials: true,
  displayItemNumbering: true,
  closeOnClickAway: true,
  accentColor: '#F8B803',
});

const page = usePage();
const isOpen = ref(false);
const isAnimating = ref(false);

const currentPath = computed(() => page.url.split(/[?#]/)[0]);

// DOM template refs
const containerRef = ref<HTMLElement | null>(null);
const backdropRef = ref<HTMLElement | null>(null);
const panelRef = ref<HTMLElement | null>(null);
const layerRefs = ref<HTMLElement[]>([]);
const itemRefs = ref<HTMLElement[]>([]);
const ctaRef = ref<HTMLElement | null>(null);
const socialsRef = ref<HTMLElement | null>(null);
const headerRef = ref<HTMLElement | null>(null);

let openTimeline: gsap.core.Timeline | null = null;

function setLayerRef(el: any, index: number) {
  if (el) layerRefs.value[index] = el;
}

function setItemRef(el: any, index: number) {
  if (el) itemRefs.value[index] = el;
}

// Lock/unlock body scroll
function toggleBodyScroll(lock: boolean) {
  if (typeof document === 'undefined') return;
  if (lock) {
    document.body.style.overflow = 'hidden';
    document.body.style.touchAction = 'none';
  } else {
    document.body.style.overflow = '';
    document.body.style.touchAction = '';
  }
}

// Open animation using GSAP timeline
function openMenu() {
  if (isOpen.value || isAnimating.value) return;
  isOpen.value = true;
  isAnimating.value = true;
  toggleBodyScroll(true);

  nextTick(() => {
    if (openTimeline) {
      openTimeline.kill();
    }

    const directionMultiplier = props.position === 'left' ? -1 : 1;
    const fromX = 100 * directionMultiplier;

    // Reset initial states
    gsap.set(backdropRef.value, { opacity: 0 });
    gsap.set(layerRefs.value, { xPercent: fromX });
    gsap.set(panelRef.value, { xPercent: fromX });
    gsap.set(itemRefs.value, { opacity: 0, y: 30 });
    if (headerRef.value) gsap.set(headerRef.value, { opacity: 0, y: -15 });
    if (ctaRef.value) gsap.set(ctaRef.value, { opacity: 0, y: 25 });
    if (socialsRef.value) gsap.set(socialsRef.value, { opacity: 0, y: 20 });

    openTimeline = gsap.timeline({
      onComplete: () => {
        isAnimating.value = false;
      },
    });

    // 1. Fade in backdrop
    openTimeline.to(backdropRef.value, {
      opacity: 1,
      duration: 0.35,
      ease: 'power2.out',
    });

    // 2. Cascade staggered background underlays
    if (layerRefs.value.length > 0) {
      openTimeline.to(
        layerRefs.value,
        {
          xPercent: 0,
          duration: 0.5,
          stagger: 0.08,
          ease: 'power3.out',
        },
        '-=0.25'
      );
    }

    // 3. Slide in main panel
    openTimeline.to(
      panelRef.value,
      {
        xPercent: 0,
        duration: 0.55,
        ease: 'power4.out',
      },
      '-=0.4'
    );

    // 4. Reveal header
    if (headerRef.value) {
      openTimeline.to(
        headerRef.value,
        {
          opacity: 1,
          y: 0,
          duration: 0.4,
          ease: 'power3.out',
        },
        '-=0.3'
      );
    }

    // 5. Stagger menu items
    if (itemRefs.value.length > 0) {
      openTimeline.to(
        itemRefs.value,
        {
          opacity: 1,
          y: 0,
          duration: 0.45,
          stagger: 0.06,
          ease: 'power3.out',
        },
        '-=0.35'
      );
    }

    // 6. Reveal CTA & Socials
    if (ctaRef.value) {
      openTimeline.to(
        ctaRef.value,
        {
          opacity: 1,
          y: 0,
          duration: 0.4,
          ease: 'power3.out',
        },
        '-=0.25'
      );
    }

    if (socialsRef.value) {
      openTimeline.to(
        socialsRef.value,
        {
          opacity: 1,
          y: 0,
          duration: 0.35,
          ease: 'power2.out',
        },
        '-=0.2'
      );
    }
  });
}

// Close animation
function closeMenu() {
  if (!isOpen.value || isAnimating.value) return;
  isAnimating.value = true;

  if (openTimeline) {
    openTimeline.kill();
  }

  const directionMultiplier = props.position === 'left' ? -1 : 1;
  const toX = 100 * directionMultiplier;

  const closeTimeline = gsap.timeline({
    onComplete: () => {
      isOpen.value = false;
      isAnimating.value = false;
      toggleBodyScroll(false);
    },
  });

  // Fade out items quickly
  closeTimeline.to(
    [itemRefs.value, ctaRef.value, socialsRef.value, headerRef.value],
    {
      opacity: 0,
      y: 15,
      duration: 0.2,
      ease: 'power2.in',
    }
  );

  // Slide out main panel and underlays
  closeTimeline.to(
    [panelRef.value, ...layerRefs.value],
    {
      xPercent: toX,
      duration: 0.35,
      stagger: 0.04,
      ease: 'power3.in',
    },
    '-=0.1'
  );

  // Fade out backdrop
  closeTimeline.to(
    backdropRef.value,
    {
      opacity: 0,
      duration: 0.25,
      ease: 'power2.in',
    },
    '-=0.2'
  );
}

function handleLinkClick() {
  closeMenu();
}

function handleBackdropClick() {
  if (props.closeOnClickAway) {
    closeMenu();
  }
}

// Close on window resize above 1024px (desktop)
function handleResize() {
  if (typeof window !== 'undefined' && window.innerWidth > 1024) {
    if (isOpen.value) {
      closeMenu();
    }
  }
}

// Keyboard escape handler
function handleKeydown(e: KeyboardEvent) {
  if (e.key === 'Escape' && isOpen.value) {
    closeMenu();
  }
}

// Watch Inertia route changes to close menu
watch(
  () => page.url,
  () => {
    if (isOpen.value) {
      closeMenu();
    }
  }
);

// Teleports are not part of the server-rendered HTML, so only mount after hydration.
const mounted = ref(false);

onMounted(() => {
  mounted.value = true;
  window.addEventListener('resize', handleResize, { passive: true });
  window.addEventListener('keydown', handleKeydown);
});

onBeforeUnmount(() => {
  if (typeof window !== 'undefined') {
    window.removeEventListener('resize', handleResize);
    window.removeEventListener('keydown', handleKeydown);
    toggleBodyScroll(false);
  }
  if (openTimeline) {
    openTimeline.kill();
  }
});
</script>

<template>
  <div ref="containerRef" class="staggered-menu-root lg:hidden">
    <!-- Menu Trigger Button -->
    <button
      type="button"
      @click="isOpen ? closeMenu() : openMenu()"
      :aria-expanded="isOpen"
      :aria-label="isOpen ? 'Close navigation menu' : 'Open navigation menu'"
      class="relative z-50 flex size-11 items-center justify-center rounded-xl border border-gray-200 bg-white text-[#4b5320] shadow-2xs transition-all hover:bg-gray-50 hover:border-[#4b5320]/40 active:scale-95 cursor-pointer focus:outline-none focus-visible:ring-2 focus-visible:ring-[#4b5320]"
    >
      <span class="sr-only">{{ isOpen ? 'Close menu' : 'Open menu' }}</span>
      <div class="relative size-5 flex items-center justify-center">
        <!-- Animated 3-line / X hamburger transition -->
        <span
          class="absolute h-0.5 w-5 rounded-full bg-[#4b5320] transition-transform duration-300 ease-in-out"
          :class="isOpen ? 'rotate-45' : '-translate-y-1.5'"
        ></span>
        <span
          class="absolute h-0.5 w-5 rounded-full bg-[#4b5320] transition-opacity duration-200"
          :class="isOpen ? 'opacity-0' : 'opacity-100'"
        ></span>
        <span
          class="absolute h-0.5 w-5 rounded-full bg-[#4b5320] transition-transform duration-300 ease-in-out"
          :class="isOpen ? '-rotate-45' : 'translate-y-1.5'"
        ></span>
      </div>
    </button>

    <!-- Teleport Fullscreen Menu Overlay to Body to prevent clipping -->
    <Teleport v-if="mounted" to="body">
      <div
        v-if="isOpen || isAnimating"
        class="fixed inset-0 z-[100] lg:hidden overflow-hidden"
        role="dialog"
        aria-modal="true"
        aria-label="Mobile Navigation"
      >
        <!-- Backdrop Overlay -->
        <div
          ref="backdropRef"
          @click="handleBackdropClick"
          class="absolute inset-0 bg-black/60 backdrop-blur-xs transition-opacity"
          aria-hidden="true"
        ></div>

        <!-- Underlay Staggered Background Layers -->
        <div
          v-for="(color, index) in colors"
          :key="index"
          :ref="(el) => setLayerRef(el, index)"
          class="absolute inset-y-0 w-full max-w-sm sm:max-w-md shadow-2xl pointer-events-none"
          :class="position === 'left' ? 'left-0' : 'right-0'"
          :style="{
            backgroundColor: color,
            zIndex: 10 + index,
          }"
        ></div>

        <!-- Main Content Drawer Panel -->
        <aside
          ref="panelRef"
          class="absolute inset-y-0 w-full max-w-sm sm:max-w-md bg-[#1d230d] text-white flex flex-col justify-between shadow-2xl overflow-y-auto"
          :class="position === 'left' ? 'left-0 border-r border-[#3b4219]' : 'right-0 border-l border-[#3b4219]'"
          :style="{ zIndex: 10 + colors.length }"
        >
          <!-- Top Panel Header -->
          <div ref="headerRef" class="p-6 border-b border-white/10 flex items-center justify-between">
            <Link
              href="/"
              @click="handleLinkClick"
              class="flex items-center gap-3 group"
            >
              <img
                src="/LOGO_MR_PRIDE.webp"
                alt="MR-Pride"
                class="size-10 object-contain drop-shadow"
              />
              <div class="flex flex-col leading-tight">
                <span class="text-base font-extrabold tracking-tight text-white group-hover:text-[#F8B803] transition-colors">
                  MR-PRIDE<span class="text-[#F8B803]">.</span>
                </span>
                <span class="text-[9px] font-semibold tracking-widest text-[#F8B803]/80 uppercase">
                  Electrical Supplies
                </span>
              </div>
            </Link>

            <!-- Close Drawer Button -->
            <button
              type="button"
              @click="closeMenu"
              aria-label="Close menu"
              class="flex size-10 items-center justify-center rounded-xl bg-white/10 hover:bg-white/20 text-white/90 hover:text-white transition-all cursor-pointer"
            >
              <X class="size-5" />
            </button>
          </div>

          <!-- Staggered Navigation Items -->
          <div class="flex-1 px-6 py-8 flex flex-col justify-center">
            <span class="text-[10px] font-bold tracking-[0.2em] text-[#F8B803] uppercase mb-4">
              Navigation
            </span>

            <nav class="space-y-2">
              <div
                v-for="(item, idx) in items"
                :key="item.name"
                :ref="(el) => setItemRef(el, idx)"
                class="overflow-hidden"
              >
                <Link
                  :href="item.href"
                  :aria-current="currentPath === item.href ? 'page' : undefined"
                  @click="handleLinkClick"
                  class="group flex items-center justify-between py-3.5 px-4 rounded-2xl transition-all duration-200"
                  :class="
                    currentPath === item.href
                      ? 'bg-white/15 text-[#F8B803] font-bold shadow-sm'
                      : 'text-white/85 hover:bg-white/10 hover:text-white'
                  "
                >
                  <div class="flex items-center gap-3.5">
                    <!-- Item Numbering (01, 02, 03) -->
                    <span
                      v-if="displayItemNumbering"
                      class="text-xs font-mono tracking-wider font-bold"
                      :class="currentPath === item.href ? 'text-[#F8B803]' : 'text-white/40 group-hover:text-[#F8B803]'"
                    >
                      0{{ idx + 1 }}
                    </span>
                    <span class="text-lg font-bold tracking-tight">
                      {{ item.name }}
                    </span>
                  </div>

                  <ArrowRight
                    class="size-4 opacity-0 -translate-x-2 transition-all duration-200 group-hover:opacity-100 group-hover:translate-x-0"
                    :class="currentPath === item.href ? 'opacity-100 translate-x-0 text-[#F8B803]' : 'text-white/60'"
                  />
                </Link>
              </div>
            </nav>

            <!-- Request Quotation CTA Button in Staggered Menu -->
            <div ref="ctaRef" class="mt-8 pt-6 border-t border-white/10">
              <Link
                :href="quotationHref || '/quotation'"
                @click="handleLinkClick"
                class="flex items-center justify-center gap-2.5 w-full py-3.5 px-5 rounded-xl bg-[#F8B803] hover:bg-[#fac52e] text-gray-950 font-bold text-sm shadow-md transition-all active:scale-[0.98]"
              >
                <span>Request a Quotation</span>
                <ArrowRight class="size-4" />
              </Link>
            </div>
          </div>

          <!-- Bottom Footer & Social Links -->
          <div
            v-if="displaySocials && socialItems && socialItems.length > 0"
            ref="socialsRef"
            class="p-6 border-t border-white/10 bg-black/20"
          >
            <div class="flex items-center justify-between text-xs text-white/60">
              <span>Connect with us</span>
              <div class="flex items-center gap-3">
                <a
                  v-for="social in socialItems"
                  :key="social.name"
                  :href="social.href"
                  target="_blank"
                  rel="noopener noreferrer"
                  class="inline-flex items-center gap-1.5 font-semibold text-white/80 hover:text-[#F8B803] transition-colors"
                >
                  <svg
                    v-if="social.name.toLowerCase().includes('facebook')"
                    class="size-3.5 fill-[#1877F2] bg-white rounded-full p-0.5"
                    viewBox="0 0 24 24"
                    aria-hidden="true"
                  >
                    <path
                      fill-rule="evenodd"
                      clip-rule="evenodd"
                      d="M22 12c0-5.523-4.477-10-10-10S2 6.477 2 12c0 4.991 3.657 9.128 8.438 9.878v-6.987h-2.54V12h2.54V9.797c0-2.506 1.492-3.89 3.777-3.89 1.094 0 2.238.195 2.238.195v2.46h-1.26c-1.243 0-1.63.771-1.63 1.562V12h2.773l-.443 2.89h-2.33v6.988C18.343 21.128 22 16.991 22 12z"
                    />
                  </svg>
                  <span>{{ social.name }}</span>
                  <ExternalLink class="size-3 opacity-60" />
                </a>
              </div>
            </div>
          </div>
        </aside>
      </div>
    </Teleport>
  </div>
</template>

<style scoped>
/* Smooth touch performance for mobile drawers */
aside {
  -webkit-overflow-scrolling: touch;
}
</style>
