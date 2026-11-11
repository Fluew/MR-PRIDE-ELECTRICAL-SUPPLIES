<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import {
    ArrowDown,
    Check,
    Wrench,
    Zap,
    Layers,
    ShieldCheck,
    Search,
    X,
    RotateCcw,
} from '@lucide/vue';
import { ref, computed } from 'vue';
import LogoLoop from '@/components/ui/LogoLoop.vue';
import {
    hardwarePartners,
    electricalPartners,
    pipePartners,
    safetyPartners,
} from '@/components/products/brandPartnersData';
import PublicLayout from '@/layouts/PublicLayout.vue';
import { vWordReveal } from '@/directives/wordReveal';

const categories = [
    {
        id: 'hardware-machinery',
        label: 'Hardware & Machinery',
        code: 'A',
        icon: Wrench,
        title: 'Hardware, Power Tools, Hand Tools & Machineries',
        description:
            'Equip every job with dependable tools and machinery, from everyday repairs to demanding workshop and construction projects.',
        image: '/PRODUCT_A.webp',
        imageAlt:
            'Hardware and machinery brand collection supplied by MR-Pride',
        caption: 'Tools & machinery • Our brand collection',
        products: [
            'Portable Welding Machines',
            'Portable Diesel Generators',
            'Portable Gasoline Generators',
            'Diesel Engines',
            'Gasoline Engines',
            'Electric Motors',
            'Submersible Pumps',
            'Water Pumps',
            'Power Tools',
            'Hand Tools',
            'Other related hardware and machinery',
        ],
        brands: [
            'DeWalt',
            'Makita',
            'Powerhouse',
            'Stanley',
            'STIHL',
            'Yale',
            'Zekoki',
            'Jr. Kawasaki',
            'Lotus',
            'Yamato',
            'Butterfly',
            'Tacoma',
            'Hoyoma',
            'DCA',
            'Ingco',
        ],
    },
    {
        id: 'electrical-materials',
        label: 'Electrical Materials',
        code: 'B',
        icon: Zap,
        title: 'Electrical Materials',
        description:
            'Find the essentials for reliable electrical installations, power distribution, and lighting in residential, commercial, and industrial spaces.',
        image: '/PRODUCT_B.webp',
        imageAlt: 'Electrical materials brand collection supplied by MR-Pride',
        caption: 'Electrical supplies • Our brand collection',
        products: [
            'Circuit Breakers',
            'Electrical Wires and Cables',
            'Lighting Products',
            'Switches',
            'Electrical Outlets',
            'Panel Boards',
            'Power Transformers',
            'Other electrical materials and accessories',
        ],
        brands: [
            'Himel',
            'ROYU',
            'Panasonic',
            'Phelps Dodge',
            'General Electric',
            'Koten',
            'Schneider Electric',
            'Philflex',
            'Duraflex',
            'Hypertech',
            'Pureflex',
            'Paciflex',
        ],
    },
    {
        id: 'pipes-conduits',
        label: 'Pipes & Conduits',
        code: 'C',
        icon: Layers,
        title: 'Pipes & Conduits',
        description:
            'Build a dependable foundation for piping and electrical systems with pipes, conduits, and fittings for a wide range of installations.',
        image: '/PRODUCT_C.webp',
        imageAlt: 'Pipe and conduit brand collection supplied by MR-Pride',
        caption: 'Pipes & conduits • Our brand collection',
        products: [
            'EMT Pipes',
            'PVC Pipes',
            'IMC Pipes',
            'RSC Pipes',
            'Conduits',
            'Fittings',
            'Other related piping and electrical conduit products',
        ],
        brands: [
            'Crown Pipes',
            'Emerald',
            'Neltex',
            'Nichi',
            'McGill',
            'Dupro',
            'Nikon',
            'Panasonic',
            'Atlanta',
        ],
    },
    {
        id: 'safety-products',
        label: 'Safety Products',
        code: 'D',
        icon: ShieldCheck,
        title: 'Safety Products',
        description:
            'Support a safer workplace with fire protection, protective equipment, and safety essentials for industrial and electrical work.',
        image: '/PRODUCT_D.webp',
        imageAlt: 'A selection of green and red fire extinguishers',
        caption: 'Workplace essentials • Fire protection & safety',
        products: [
            'Fire Extinguishers',
            'Safety Equipment',
            'Protective Equipment',
            'Other industrial and electrical safety products',
        ],
        brands: ['3M', 'Honeywell', 'MSA Safety', 'Ansell', 'Kimberly-Clark'],
    },
];

// Search & Filter State
const searchQuery = ref('');
const selectedCategory = ref<string>('all');

const totalProductCount = computed(() => {
    return categories.reduce((sum, c) => sum + c.products.length, 0);
});

function getCategoryPartners(code: string) {
    switch (code) {
        case 'A':
            return hardwarePartners;
        case 'B':
            return electricalPartners;
        case 'C':
            return pipePartners;
        case 'D':
            return safetyPartners;
        default:
            return [];
    }
}

function getCategoryDirection(code: string): 'left' | 'right' {
    switch (code) {
        case 'B':
        case 'D':
            return 'right';
        case 'A':
        case 'C':
        default:
            return 'left';
    }
}

// Reactive filtering logic
const filteredCategories = computed(() => {
    const q = searchQuery.value.trim().toLowerCase();

    return categories
        .map((cat) => {
            if (!q) {
                return {
                    ...cat,
                    matchingProducts: cat.products,
                    matchingBrands: [] as string[],
                    hasMatches: true,
                };
            }

            const catMatches =
                cat.title.toLowerCase().includes(q) ||
                cat.label.toLowerCase().includes(q) ||
                cat.description.toLowerCase().includes(q);

            const matchingProducts = cat.products.filter((p) =>
                p.toLowerCase().includes(q),
            );

            const matchingBrands = cat.brands.filter((b) =>
                b.toLowerCase().includes(q),
            );

            const partnerMatches = getCategoryPartners(cat.code).filter((p) =>
                p.name?.toLowerCase().includes(q),
            );

            const hasMatches =
                catMatches ||
                matchingProducts.length > 0 ||
                matchingBrands.length > 0 ||
                partnerMatches.length > 0;

            return {
                ...cat,
                matchingProducts:
                    matchingProducts.length > 0
                        ? matchingProducts
                        : catMatches || matchingBrands.length > 0
                          ? cat.products
                          : [],
                matchingBrands: Array.from(
                    new Set([
                        ...matchingBrands,
                        ...partnerMatches.map((p) => p.name),
                    ]),
                ),
                hasMatches,
            };
        })
        .filter((cat) => cat.hasMatches);
});

const totalMatchesCount = computed(() => {
    return filteredCategories.value.reduce(
        (sum, c) => sum + c.matchingProducts.length,
        0,
    );
});

const resetFilters = () => {
    searchQuery.value = '';
};

const setQuickSearch = (term: string) => {
    searchQuery.value = term;
};

function scrollToCategory(event: MouseEvent, id: string) {
    if (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) {
        return;
    }

    selectedCategory.value = id;
    const section = document.getElementById(id);

    if (!section) {
        return;
    }

    event.preventDefault();
    window.history.replaceState(window.history.state, '', `#${id}`);
    section.focus({ preventScroll: true });
    section.scrollIntoView({
        behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches
            ? 'instant'
            : 'smooth',
        block: 'start',
    });
}

</script>

<template>
    <Head title="Product List | MR-Pride Electrical Supplies">
        <meta
            name="description"
            content="Explore MR-Pride's hardware, power tools, electrical materials, pipes, conduits, and safety products from trusted brand partners."
        />
    </Head>
    <PublicLayout>
        <!-- Header Banner Section -->
        <header
            class="relative overflow-hidden border-b border-[#3c421a] bg-black text-white"
        >
            <div class="pointer-events-none absolute inset-0 z-0">
                <img
                    src="/PRODUCT_BACKGROUND.webp"
                    alt=""
                    class="h-full w-full object-cover object-right opacity-80 lg:object-center"
                    aria-hidden="true"
                />
                <!-- Army green gradient overlay with ~50% opacity -->
                <div
                    class="absolute inset-0 bg-gradient-to-r from-[#4b5320]/80 via-[#4b5320]/50 to-[#4b5320]/30"
                    aria-hidden="true"
                ></div>
                <!-- Contrast gradient for text legibility -->
                <div
                    class="absolute inset-0 bg-gradient-to-r from-black/70 via-black/20 to-transparent"
                    aria-hidden="true"
                ></div>
                <div
                    class="absolute inset-0 bg-gradient-to-t from-black/60 via-transparent to-transparent"
                    aria-hidden="true"
                ></div>
            </div>

            <div
                class="relative z-10 mx-auto max-w-7xl px-4 pt-14 pb-10 sm:px-6 sm:pt-20 lg:px-8"
            >
                <p v-word-reveal
                    class="mb-5 flex items-center gap-3 text-xs font-semibold text-[#F8B803]"
                >
                    <span
                        class="h-0.5 w-9 bg-[#F8B803]"
                        aria-hidden="true"
                    ></span
                    >MR-Pride Electrical Supplies
                </p>
                <div
                    class="flex flex-col justify-between gap-6 lg:flex-row lg:items-end"
                >
                    <div class="max-w-3xl">
                        <h1 v-word-reveal
                            class="text-4xl font-semibold tracking-tight text-white sm:text-6xl"
                        >
                            PRODUCT LIST<span class="text-[#F8B803]">.</span>
                        </h1>
                        <p v-word-reveal
                            class="mt-5 max-w-2xl text-base leading-8 text-gray-200 sm:text-lg"
                        >
                            Explore our wide range of hardware, electrical,
                            industrial, piping, and safety products from trusted
                            brands.
                        </p>
                    </div>
                    <p v-word-reveal
                        class="flex items-center gap-3 text-sm font-medium text-gray-300"
                    >
                        <span class="text-3xl font-semibold text-[#F8B803]"
                            >04</span
                        >Product categories
                    </p>
                </div>

                <!-- Search Bar Aligned Along the 4 Clickable Routes -->
                <div class="relative mt-8 w-full">
                    <Search
                        class="pointer-events-none absolute top-1/2 left-4 size-5 -translate-y-1/2 text-[#F8B803]"
                    />
                    <input
                        v-model="searchQuery"
                        type="text"
                        placeholder="Search by product, brand, equipment (e.g. Breaker, Makita, PVC, Welding)..."
                        class="w-full rounded-xl border border-white/20 bg-black/60 py-3.5 pr-12 pl-12 text-sm text-white shadow-lg backdrop-blur-md transition-colors placeholder:text-gray-300 focus:border-[#F8B803] focus:bg-black/80 focus:ring-2 focus:ring-[#F8B803]/30 focus:outline-none sm:text-base"
                    />
                    <button
                        v-if="searchQuery"
                        @click="searchQuery = ''"
                        class="absolute top-1/2 right-4 -translate-y-1/2 cursor-pointer rounded-full p-1 text-gray-400 transition-colors hover:text-white"
                        title="Clear search"
                    >
                        <X class="size-5" />
                    </button>
                </div>

                <!-- Active Search Results Feedback (Inside Banner) -->
                <div
                    v-if="searchQuery"
                    class="mt-3 flex items-center justify-between rounded-xl border border-white/10 bg-black/40 px-4 py-2.5 text-xs text-gray-300 backdrop-blur-sm"
                >
                    <div class="flex items-center gap-2">
                        <span>
                            Showing
                            <strong class="text-white">{{
                                totalMatchesCount
                            }}</strong>
                            items in
                            <strong class="text-[#F8B803]">{{
                                filteredCategories.length
                            }}</strong>
                            categories
                        </span>
                        <span>
                            matching "<span class="font-semibold text-white">{{
                                searchQuery
                            }}</span
                            >"
                        </span>
                    </div>
                    <button
                        @click="searchQuery = ''"
                        class="inline-flex cursor-pointer items-center gap-1 font-semibold text-[#F8B803] hover:underline"
                    >
                        <RotateCcw class="size-3" />
                        Clear search
                    </button>
                </div>

                <!-- Category Quick Navigation (The 4 Clickable Routes) -->
                <nav
                    aria-label="Product categories quick navigation"
                    class="mt-5 grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4"
                >
                    <a
                        v-for="category in categories"
                        :key="category.id"
                        :href="`#${category.id}`"
                        :class="[
                            'group flex items-center gap-3 rounded-xl border px-4 py-4 text-sm font-semibold backdrop-blur-md transition-colors focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-[#F8B803]',
                            selectedCategory === category.id
                                ? 'border-[#F8B803] bg-black/85 text-[#F8B803]'
                                : 'border-white/20 bg-black/50 text-white hover:border-[#F8B803] hover:bg-black/75',
                        ]"
                        @click="scrollToCategory($event, category.id)"
                    >
                        <component
                            :is="category.icon"
                            class="size-5 shrink-0 text-[#F8B803] transition-transform"
                            aria-hidden="true"
                        />
                        {{ category.label }}
                        <ArrowDown
                            class="ml-auto size-4 shrink-0 text-gray-300 transition-colors group-hover:text-[#F8B803]"
                            aria-hidden="true"
                        />
                    </a>
                </nav>
            </div>
        </header>

        <!-- Main Product Sections Container -->
        <div
            class="mx-auto flex max-w-7xl flex-col gap-10 px-4 py-12 sm:gap-14 sm:px-6 lg:px-8 lg:py-16"
        >
            <!-- No Matches Empty State -->
            <div
                v-if="filteredCategories.length === 0"
                class="rounded-xl border border-gray-200 bg-white p-10 text-center sm:p-14"
            >
                <div
                    class="mx-auto mb-4 flex size-16 items-center justify-center rounded-xl bg-[#4b5320]/10 text-[#4b5320]"
                >
                    <Search class="size-8" />
                </div>
                <h3 class="mb-2 text-xl font-semibold text-gray-900">
                    No products or brands found
                </h3>
                <p class="mx-auto mb-6 max-w-md text-sm text-gray-500">
                    We couldn't find any products or brand partners matching
                    "<strong class="text-gray-800">{{ searchQuery }}</strong
                    >".
                </p>
                <div
                    class="mb-8 flex flex-wrap items-center justify-center gap-2"
                >
                    <span class="text-xs font-medium text-gray-400"
                        >Try searching for:</span
                    >
                    <button
                        v-for="term in [
                            'Circuit Breakers',
                            'DeWalt',
                            'Generators',
                            'EMT Pipes',
                            'Makita',
                            'Fire Extinguishers',
                            'Himel',
                            'Transformers',
                        ]"
                        :key="term"
                        @click="setQuickSearch(term)"
                        class="cursor-pointer rounded-lg bg-gray-100 px-3 py-1 text-xs font-semibold text-gray-700 transition-colors hover:bg-[#F8B803]/20 hover:text-gray-900"
                    >
                        {{ term }}
                    </button>
                </div>
                <button
                    @click="resetFilters"
                    class="inline-flex cursor-pointer items-center gap-1.5 rounded-xl bg-[#4b5320] px-6 py-3 text-xs font-semibold text-white transition-colors hover:bg-[#3d441a]"
                >
                    <RotateCcw class="size-3.5" />
                    Clear search
                </button>
            </div>

            <!-- Categories List -->
            <section
                v-for="category in filteredCategories"
                :id="category.id"
                :key="category.id"
                tabindex="-1"
                :aria-labelledby="`${category.id}-title`"
                class="category-section scroll-mt-24 overflow-hidden rounded-xl border border-gray-200 bg-white transition-[box-shadow,border-color] duration-300 hover:border-[#4b5320]/30 focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-[#4b5320]"
            >
                <div
                    class="grid gap-8 p-6 sm:p-9 lg:grid-cols-[1.15fr_1fr] lg:gap-12 lg:p-10"
                >
                    <div>
                        <div v-word-reveal
                            class="mb-5 flex items-center gap-3 text-xs font-semibold text-[#4b5320]"
                        >
                            <span
                                class="flex size-9 items-center justify-center rounded-lg bg-[#4b5320]/10 text-sm font-semibold"
                                >{{ category.code }}</span
                            >Product category
                        </div>
                        <h2 v-word-reveal
                            :id="`${category.id}-title`"
                            class="max-w-xl text-2xl leading-tight font-semibold tracking-tight text-gray-900 sm:text-3xl"
                        >
                            {{ category.title }}
                        </h2>
                        <p v-word-reveal
                            class="mt-4 text-sm leading-7 text-gray-600 sm:text-base"
                        >
                            {{ category.description }}
                        </p>

                        <!-- Matching Brands Highlight when searching -->
                        <div
                            v-if="
                                category.matchingBrands &&
                                category.matchingBrands.length > 0 &&
                                searchQuery
                            "
                            class="mt-5 rounded-xl border border-[#F8B803]/25 bg-[#F8B803]/10 p-3.5 text-xs text-gray-800"
                        >
                            <span
                                class="mb-1 block text-[11px] font-semibold text-gray-900"
                                >Matching Brand Partners:</span
                            >
                            <div class="mt-1 flex flex-wrap gap-1.5">
                                <span
                                    v-for="b in category.matchingBrands"
                                    :key="b"
                                    class="rounded-md border border-[#F8B803]/30 bg-white px-2 py-0.5 font-semibold text-gray-900"
                                >
                                    {{ b }}
                                </span>
                            </div>
                        </div>

                        <!-- Product List -->
                        <div class="mt-7 flex items-center justify-between">
                            <h3 v-word-reveal
                                class="text-xs font-semibold text-gray-500"
                            >
                                Our product range
                            </h3>
                            <span
                                v-if="searchQuery"
                                class="text-xs font-medium text-emerald-600"
                            >
                                {{ category.matchingProducts.length }}
                                {{
                                    category.matchingProducts.length === 1
                                        ? 'item'
                                        : 'items'
                                }}
                                found
                            </span>
                        </div>

                        <ul class="mt-4 grid gap-x-5 gap-y-3 sm:grid-cols-2">
                            <li
                                v-for="product in category.matchingProducts"
                                :key="product"
                                class="flex items-start gap-2 text-sm leading-6 text-gray-700"
                            >
                                <Check
                                    class="mt-1 size-4 shrink-0 text-[#4b5320]"
                                    aria-hidden="true"
                                />
                                <span
                                    v-if="
                                        searchQuery &&
                                        product
                                            .toLowerCase()
                                            .includes(searchQuery.toLowerCase())
                                    "
                                    class="rounded bg-[#F8B803]/25 px-1.5 py-0.5 font-semibold text-gray-900"
                                >
                                    {{ product }}
                                </span>
                                <span v-else>
                                    {{ product }}
                                </span>
                            </li>
                        </ul>
                    </div>

                    <figure
                        class="group flex min-w-0 flex-col self-start overflow-hidden rounded-xl border border-gray-200 bg-white lg:sticky lg:top-36"
                    >
                        <div
                            class="flex aspect-[6/5] items-center justify-center overflow-hidden p-6 sm:p-8"
                        >
                            <img
                                :src="category.image"
                                :alt="category.imageAlt"
                                :loading="
                                    category.code === 'A' ? 'eager' : 'lazy'
                                "
                                decoding="async"
                                class="h-full w-full object-contain transition-transform duration-500 motion-reduce:transform-none motion-reduce:transition-none"
                            />
                        </div>
                        <figcaption v-word-reveal
                            class="border-t border-gray-100 bg-gray-50 px-5 py-4 text-xs leading-5 font-medium text-gray-500"
                        >
                            {{ category.caption }}
                        </figcaption>
                    </figure>
                </div>

                <!-- Brand Partners Carousel -->
                <div
                    class="border-t border-gray-100 bg-gray-50/70 px-4 py-8 sm:px-8 sm:py-10"
                >
                    <div class="mb-5 flex items-center justify-between">
                        <h3 v-word-reveal
                            class="text-xs font-semibold text-gray-900 sm:text-sm"
                        >
                            Brand Partners
                        </h3>
                        <span v-word-reveal
                            class="hidden text-[11px] font-semibold text-gray-400 sm:inline"
                        >
                            Authorized Manufacturers & Suppliers
                        </span>
                    </div>
                    <LogoLoop
                        :logos="getCategoryPartners(category.code)"
                        :speed="70"
                        :direction="getCategoryDirection(category.code)"
                        :logo-height="55"
                        :gap="50"
                        :hover-speed="0"
                        :scale-on-hover="true"
                        :fade-out="true"
                        fade-out-color="#f9fafb"
                        :aria-label="`${category.label} brand partners`"
                        class="py-2"
                    />
                </div>
            </section>
        </div>
    </PublicLayout>
</template>

<style scoped>
@media (prefers-reduced-motion: reduce) {
    .category-section {
        transition: none;
    }
}
</style>
