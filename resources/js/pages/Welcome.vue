<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { ref } from 'vue';
import PublicLayout from '@/layouts/PublicLayout.vue';
import SpecularButton from '@/components/ui/SpecularButton.vue';
import ShinyText from '@/components/vue-bits/ShinyText.vue';
import { vWordReveal } from '@/directives/wordReveal';

// Hero heading: the words of both lines spring in one after another on page load (v-word-reveal);
// once they are all in place, a shine keeps sweeping over "Industrial Future".
// The yellow-to-olive gradient (Tailwind's `from-[#F8B803] to-[#4b5320]`, blended in OKLab) as
// plain stops, so the words while they move and the shine afterwards show exactly the same colors.
const HERO_GRADIENT = 'linear-gradient(to right, #f8b803 0%, #ca9e1a 25%, #9e8420 50%, #746b22 75%, #4b5320 100%)';
const heroRevealed = ref(false);
</script>

<template>
    <Head title="Welcome | MR-Pride Electrical Supplies" />

    <PublicLayout>
        <!-- Hero Section -->
        <section aria-label="Hero Section" class="relative bg-gray-50 overflow-hidden">
            <!-- Responsive Banner Image Container -->
            <div class="hero-banner relative w-full overflow-hidden">
                <img 
                    src="/HOME_BACKGROUND.webp" 
                    class="hero-img w-full h-full object-cover select-none pointer-events-none" 
                    alt="MR-Pride Electrical Supplies" 
                    loading="eager"
                    fetchpriority="high"
                    decoding="async"
                />
                <!-- Subtle contrast overlay for readability -->
                <div class="absolute inset-0 bg-black/15 sm:bg-black/20 pointer-events-none transition-opacity duration-300"></div>
                <!-- Smooth gradient transition to background -->
                <div class="absolute inset-0 bg-gradient-to-t from-gray-50 via-gray-50/20 to-transparent pointer-events-none"></div>
            </div>

            <!-- Content Card with Fluid Overlap and Adaptive Padding -->
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 relative z-10 -mt-12 sm:-mt-20 md:-mt-28 lg:-mt-32 pb-16 sm:pb-20 md:pb-24">
                <div class="relative text-center max-w-4xl mx-auto p-6 sm:p-8 md:p-12">
                    <!-- Soft light glow behind the text (no card): keeps it readable over the photo. -->
                    <div class="absolute -inset-x-8 -inset-y-4 -z-10 bg-[radial-gradient(closest-side,color-mix(in_oklab,var(--color-gray-50)_90%,transparent)_0%,color-mix(in_oklab,var(--color-gray-50)_60%,transparent)_55%,transparent_100%)] pointer-events-none" aria-hidden="true"></div>
                    <!-- Both lines: one word-by-word spring reveal; then the shine loops on "Industrial Future". -->
                    <h1 v-word-reveal class="text-3xl sm:text-4xl md:text-5xl lg:text-6xl font-semibold tracking-tight mb-4 sm:mb-6" @word-reveal-complete="heroRevealed = true">
                        <span class="block text-gray-900 mb-1 sm:mb-2">Powering Your</span>
                        <ShinyText
                            text="Industrial Future"
                            :data-reveal-fill="HERO_GRADIENT"
                            base-class-name="block"
                            :disabled="!heroRevealed"
                            :speed="2"
                            :delay="1"
                            color="#F8B803"
                            :base-gradient="HERO_GRADIENT"
                            shine-color="#FFFFFF"
                            :spread="120"
                            direction="left"
                            :yoyo="false"
                            :pause-on-hover="false"
                        />
                    </h1>
                    
                    <p class="mt-3 sm:mt-4 text-sm sm:text-base md:text-lg lg:text-xl text-gray-600 leading-relaxed max-w-2xl mx-auto mb-6 sm:mb-8 md:mb-10">
                        MR-Pride Electrical Supplies delivers high-performance electrical components, innovative project solutions, and unparalleled expertise for modern industrial infrastructure.
                    </p>

                    <div class="flex flex-col sm:flex-row justify-center items-center gap-3 sm:gap-4">
                        <SpecularButton 
                            href="/products"
                            variant="brand-gold"
                            size="lg"
                            class="w-full sm:w-auto px-6 sm:px-8 py-3.5 sm:py-4 font-semibold rounded-full shadow-md cursor-pointer"
                        >
                            Browse Products
                        </SpecularButton>
                        
                        <SpecularButton 
                            href="/quotation"
                            variant="outline"
                            size="lg"
                            class="w-full sm:w-auto px-6 sm:px-8 py-3.5 sm:py-4 font-semibold text-gray-900 rounded-full cursor-pointer"
                        >
                            Request Quotation
                        </SpecularButton>
                    </div>
                </div>
            </div>
        </section>

        <!-- About Us Section -->
        <section id="about" aria-labelledby="about-us-heading" class="py-24 bg-[#4b5320] text-white relative z-10 border-t border-[#3c421a] overflow-hidden">
            <!-- Subtle ambient glow background decoration -->
            <div class="absolute -top-24 -right-24 size-96 rounded-full bg-[#F8B803]/10 blur-3xl pointer-events-none"></div>
            <div class="absolute -bottom-24 -left-24 size-96 rounded-full bg-black/20 blur-3xl pointer-events-none"></div>

            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 relative z-10">
                <div class="text-center mb-16">
                    <span v-word-reveal class="text-xs font-semibold text-[#F8B803]">Our Story</span>
                    <h2 v-word-reveal id="about-us-heading" class="text-3xl sm:text-4xl font-semibold text-white mt-2 mb-4 tracking-tight">ABOUT US</h2>
                    <div class="w-24 h-1.5 bg-[#F8B803] mx-auto rounded-full"></div>
                </div>

                <div class="max-w-4xl mx-auto text-center">
                    <div class="mb-10">
                        <p v-word-reveal class="text-xl sm:text-2xl text-white/95 font-medium leading-relaxed drop-shadow-xs">
                            Established in 2020, MR-PRIDE is an electrical trading business that buys and sells electrical materials and equipment to customers such as contractors, engineers, and construction companies.
                        </p>
                    </div>
                    <div class="text-base sm:text-lg text-white/90 leading-relaxed bg-black/25 backdrop-blur-md p-8 md:p-12 rounded-xl border border-white/20 shadow-xl relative overflow-hidden text-left sm:text-center">
                        <span class="absolute top-0 left-0 w-2 h-full bg-[#F8B803]"></span>
                        <p v-word-reveal>
                            An establishment that offers list of quality products that will help to minimize your problems involving Electrical. This company is a fully Filipino Owned that is dedicated to support its clients, and future clients in their needs of any products in line, MR-Pride Electrical Supplies has devoted employees to provide products in good quality considering the words, <span class="text-[#F8B803] font-semibold">“right in time”</span>.
                        </p>
                    </div>
                </div>
            </div>
        </section>

        <!-- Call to Action -->
        <div class="py-24 relative overflow-hidden bg-gray-50 border-t border-gray-200">
            <div class="absolute inset-0 bg-[url('data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iMjAiIGhlaWdodD0iMjAiIHhtbG5zPSJodHRwOi8vd3d3LnczLm9yZy8yMDAwL3N2ZyI+PGNpcmNsZSBjeD0iMSIgY3k9IjEiIHI9IjEiIGZpbGw9IiNjY2MiLz48L3N2Zz4=')] opacity-50"></div>
            <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 relative z-10 text-center">
                <h2 v-word-reveal class="text-4xl font-semibold text-gray-900 mb-6">Ready to Power Up?</h2>
                <p v-word-reveal class="text-xl text-gray-600 mb-10">Join hundreds of industrial leaders who trust MR-Pride Electrical Supplies for their critical electrical infrastructure.</p>
                <SpecularButton 
                    href="/quotation"
                    variant="brand-gold"
                    size="lg"
                    class="inline-flex items-center justify-center px-8 py-4 text-lg font-semibold rounded-full shadow-lg cursor-pointer"
                >
                    Get in Touch Today
                </SpecularButton>
            </div>
        </div>
    </PublicLayout>
</template>

<style scoped>
/* Responsive hero banner container scaling seamlessly with viewport width */
.hero-banner {
    width: 100%;
    /* Fluid height scaling using clamp() from mobile up to large screens */
    height: clamp(260px, 44vw, 640px);
    min-height: 240px;
    background-color: #f9fafb;
}

/* Hero image element with responsive focal points */
.hero-img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    /* Mobile-first focal point: focus on tools and branding */
    object-position: 18% center;
    transition: object-position 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    transform: translateZ(0);
    will-change: object-position;
}

/* Tablet portrait (640px - 1023px) */
@media (min-width: 640px) {
    .hero-img {
        object-position: 28% center;
    }
}

/* Tablet landscape & Laptop (1024px - 1439px) */
@media (min-width: 1024px) {
    .hero-img {
        object-position: center center;
    }
}

/* Desktop & Ultrawide (1440px+) */
@media (min-width: 1440px) {
    .hero-banner {
        height: clamp(520px, 36vw, 680px);
    }
    .hero-img {
        object-position: center center;
    }
}

@media (min-width: 1920px) {
    .hero-banner {
        height: clamp(580px, 32vw, 760px);
    }
    .hero-img {
        object-position: center 38%;
    }
}

/* Mobile Portrait Mode */
@media (max-width: 639px) and (orientation: portrait) {
    .hero-banner {
        height: clamp(260px, 42vh, 380px);
    }
    .hero-img {
        object-position: 16% center;
    }
}
</style>
