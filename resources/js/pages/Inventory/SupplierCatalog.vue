<script setup lang="ts">
/**
 * Supplier Product Catalog: what suppliers offer and at what USD purchase cost, one row per
 * product and supplier. Catalog products are not inventory stock until registered; viewing and
 * comparing change nothing. Costs are simulated staging data, not verified quotations.
 */
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { ArrowLeft, Building2, CheckCircle2, Layers, PackagePlus, Scale, Search, X } from '@lucide/vue';
import { computed, reactive, ref, watch } from 'vue';

interface CatalogRow {
    id: number;
    catalog_product_id: number;
    product_name: string;
    category: string;
    brand: string | null;
    supplier_id: number;
    supplier_name: string;
    supplier_active: boolean;
    unit_cost: number;
    currency: string;
    pricing_unit: string;
    pricing_unit_label: string | null;
    unit_confirmed: boolean;
    is_simulated: boolean;
    lowest_cost: number;
    is_lowest: boolean;
    difference_from_lowest: number;
    offers: number;
    product_id: number | null;
    product_sku: string | null;
    product_status: string | null;
}

interface Paginated<T> {
    data: T[];
    from: number | null;
    to: number | null;
    total: number;
    links: { url: string | null; label: string; active: boolean }[];
}

const props = defineProps<{
    rows: Paginated<CatalogRow>;
    summary: { products: number; prices: number; suppliers: number; registered: number };
    options: { categories: string[]; suppliers: { id: number; name: string }[] };
    filters: { search: string; category: string; supplier_id: string; registration: 'all' | 'catalog' | 'inventory'; sort: 'product' | 'price_asc' | 'price_desc' };
    unitOptions: string[];
}>();

const page = usePage();
const teamSlug = computed(() => (page.props as any).currentTeam?.slug || (page.props as any).team?.slug || '');

// Catalog costs are stored and shown in USD (the file's currency); they are never converted here.
const usd = new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' });
const money = (v: number | string | null | undefined) => usd.format(Number(v ?? 0));

// Floating-label classes (same as the Inventory product form).
const fieldClass = 'peer block w-full rounded-xl border border-gray-200 bg-white px-3.5 pt-5 pb-2 text-xs text-gray-900 focus:border-[#4b5320] focus:ring-1 focus:ring-[#4b5320] focus:outline-none sm:text-sm';
const floatLabel = 'pointer-events-none absolute top-3.5 left-3.5 origin-[0] -translate-y-2.5 scale-75 transform text-xs font-semibold text-gray-500 duration-200 peer-placeholder-shown:translate-y-0 peer-placeholder-shown:scale-100 peer-focus:-translate-y-2.5 peer-focus:scale-75 peer-focus:text-[#4b5320]';
const fixedLabel = 'pointer-events-none absolute top-3.5 left-3.5 origin-[0] -translate-y-2.5 scale-75 transform text-xs font-semibold text-[#4b5320]';

// ---------------------------------------------------------------- filters
const f = reactive({ ...props.filters });
watch(() => props.filters, (v) => Object.assign(f, v));

const apply = () => {
    router.get(
        `/${teamSlug.value}/inventory/supplier-catalog`,
        {
            search: f.search || undefined,
            category: f.category || undefined,
            supplier_id: f.supplier_id || undefined,
            registration: f.registration !== 'all' ? f.registration : undefined,
            sort: f.sort !== 'product' ? f.sort : undefined,
        },
        { preserveState: true, preserveScroll: true, replace: true, only: ['rows', 'filters'] },
    );
};
let searchTimer: ReturnType<typeof setTimeout> | undefined;
const onSearch = () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(apply, 300);
};
const clearFilters = () => {
    Object.assign(f, { search: '', category: '', supplier_id: '', registration: 'all', sort: 'product' });
    apply();
};
const hasFilters = computed(() => !!(f.search || f.category || f.supplier_id || f.registration !== 'all' || f.sort !== 'product'));
const filterRegistration = (value: 'all' | 'catalog' | 'inventory') => {
    f.registration = value;
    apply();
};

// ---------------------------------------------------------------- compare / register panel
const panel = reactive({
    open: false,
    loading: false,
    error: '',
    data: null as null | {
        catalog_product: { id: number; name: string; category: string; brand: string | null; registered_at: string | null };
        inventory_product: { id: number; sku: string; name: string; unit: string | null; stock: number; status: string; is_active: boolean } | null;
        prices: CatalogRow[];
        next_sku: string | null;
    },
});
const form = reactive({
    unit: 'pcs',
    price: '' as string | number,
    cost_price: '' as string | number,
    reorder_level: '' as string | number,
    reorder_quantity: '' as string | number,
    supplier_id: '' as string | number,
    description: '',
});
const formErrors = ref<Record<string, string>>({});
const saving = ref(false);

/** Price groups by pricing unit: costs in different units are not compared with each other. */
const unitGroups = computed(() => {
    const groups: Record<string, CatalogRow[]> = {};
    for (const p of panel.data?.prices ?? []) (groups[p.pricing_unit] ??= []).push(p);
    return Object.entries(groups).map(([unit, prices]) => ({ unit, prices, unconfirmed: prices.some((p) => !p.unit_confirmed) }));
});
const unitToConfirm = computed(() => unitGroups.value.some((g) => g.unconfirmed));

const openPanel = async (catalogProductId: number) => {
    Object.assign(panel, { open: true, loading: true, error: '', data: null });
    formErrors.value = {};
    try {
        const res = await fetch(`/${teamSlug.value}/inventory/supplier-catalog/${catalogProductId}`, { headers: { Accept: 'application/json' } });
        if (!res.ok) throw new Error(`Could not load the supplier prices (${res.status}).`);
        panel.data = await res.json();
        // Prefill only the unit given by the price list; nothing else (no supplier or price is chosen for staff).
        Object.assign(form, {
            unit: panel.data?.prices[0]?.pricing_unit ?? 'pcs',
            price: '', cost_price: '', reorder_level: '', reorder_quantity: '', supplier_id: '', description: '',
        });
    } catch (e: any) {
        panel.error = e.message;
    } finally {
        panel.loading = false;
    }
};

const register = () => {
    if (!panel.data) return;
    saving.value = true;
    formErrors.value = {};
    const blankToNull = (v: string | number) => (v === '' || v === null ? null : v);
    router.post(
        `/${teamSlug.value}/inventory/supplier-catalog/${panel.data.catalog_product.id}/register`,
        {
            unit: form.unit,
            price: blankToNull(form.price),
            cost_price: blankToNull(form.cost_price),
            reorder_level: blankToNull(form.reorder_level),
            reorder_quantity: blankToNull(form.reorder_quantity),
            supplier_id: blankToNull(form.supplier_id),
            description: form.description || null,
        },
        {
            preserveScroll: true,
            preserveState: true,
            onError: (errors) => (formErrors.value = errors as Record<string, string>),
            onSuccess: () => {
                const errs = (page.props as any).errors ?? {};
                if (Object.keys(errs).length) {
                    formErrors.value = errs;
                    return;
                }
                openPanel(panel.data!.catalog_product.id);
            },
            onFinish: () => (saving.value = false),
        },
    );
};

const statusClass = (status: string | null) =>
    ({
        'In Stock': 'bg-emerald-50 text-emerald-700 border-emerald-200',
        'Low Stock': 'bg-amber-50 text-amber-700 border-amber-200',
        'Out of Stock': 'bg-rose-50 text-rose-700 border-rose-200',
        Incomplete: 'bg-amber-50 text-amber-800 border-amber-200',
    })[status ?? ''] ?? 'bg-gray-50 text-gray-700 border-gray-200';
</script>

<template>
    <Head title="Supplier Product Catalog" />

    <div class="workspace-page relative">
        <div class="page-header">
            <div>
                <h1 class="page-title">Supplier Product Catalog</h1>
                <p class="page-subtitle">Products offered by suppliers and each supplier's unit cost (USD), before inventory registration or procurement.</p>
            </div>
            <div class="page-actions">
                <Link :href="`/${teamSlug}/inventory`" class="secondary-button inline-flex h-10 items-center gap-1.5 text-xs">
                    <ArrowLeft class="h-3.5 w-3.5" /> Inventory
                </Link>
            </div>
        </div>

        <p class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-2.5 text-xs text-amber-900">
            <strong>Simulated supplier costs for staging, not verified supplier quotations.</strong>
            Catalog products are not inventory stock: they have no stock, reorder level or sales history until registered, and registering one adds no stock and creates no purchase order.
        </p>

        <!-- Summary (click to filter) -->
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
            <button type="button" class="rounded-xl border bg-white px-4 py-3 text-left transition-colors" :class="f.registration === 'all' ? 'border-gray-900' : 'border-gray-200 hover:bg-gray-50'" @click="filterRegistration('all')">
                <div class="text-xs text-gray-500">Catalog products</div>
                <div class="mt-2 text-2xl font-semibold tracking-tight tabular-nums text-gray-900">{{ summary.products }}</div>
            </button>
            <div class="panel p-4 sm:p-5">
                <div class="text-xs text-gray-500">Supplier prices</div>
                <div class="mt-2 text-2xl font-semibold tracking-tight tabular-nums text-gray-900">{{ summary.prices }}</div>
            </div>
            <div class="panel p-4 sm:p-5">
                <div class="text-xs text-gray-500">Suppliers</div>
                <div class="mt-2 text-2xl font-semibold tracking-tight tabular-nums text-gray-900">{{ summary.suppliers }}</div>
            </div>
            <button type="button" class="rounded-xl border bg-white px-4 py-3 text-left transition-colors" :class="f.registration === 'inventory' ? 'border-gray-900' : 'border-gray-200 hover:bg-gray-50'" @click="filterRegistration('inventory')">
                <div class="text-xs text-gray-500">Registered in inventory</div>
                <div class="mt-2 text-2xl font-semibold tracking-tight tabular-nums text-gray-900">{{ summary.registered }} <span class="text-sm font-normal text-gray-400">/ {{ summary.products }}</span></div>
            </button>
        </div>

        <!-- Search & filters (floating labels) -->
        <div class="grid grid-cols-1 gap-3 panel p-4 sm:grid-cols-2 lg:grid-cols-6">
            <div class="relative lg:col-span-2">
                <input id="cat-search" v-model="f.search" type="search" placeholder=" " :class="[fieldClass, 'pr-9']" @input="onSearch" />
                <label for="cat-search" :class="floatLabel">Search product, brand, category or supplier</label>
                <Search class="pointer-events-none absolute top-1/2 right-3 h-4 w-4 -translate-y-1/2 text-gray-400" />
            </div>
            <div class="relative">
                <select id="cat-category" v-model="f.category" :class="fieldClass" @change="apply">
                    <option value="">All categories</option>
                    <option v-for="c in options.categories" :key="c" :value="c">{{ c }}</option>
                </select>
                <label for="cat-category" :class="fixedLabel">Category</label>
            </div>
            <div class="relative">
                <select id="cat-supplier" v-model="f.supplier_id" :class="fieldClass" @change="apply">
                    <option value="">All suppliers</option>
                    <option v-for="s in options.suppliers" :key="s.id" :value="String(s.id)">{{ s.name }}</option>
                </select>
                <label for="cat-supplier" :class="fixedLabel">Supplier</label>
            </div>
            <div class="relative">
                <select id="cat-registration" v-model="f.registration" :class="fieldClass" @change="apply">
                    <option value="all">Catalog and inventory</option>
                    <option value="catalog">Catalog only (not registered)</option>
                    <option value="inventory">Registered in inventory</option>
                </select>
                <label for="cat-registration" :class="fixedLabel">Inventory status</label>
            </div>
            <div class="relative">
                <select id="cat-sort" v-model="f.sort" :class="fieldClass" @change="apply">
                    <option value="product">Product, then lowest cost</option>
                    <option value="price_asc">Unit cost: lowest first</option>
                    <option value="price_desc">Unit cost: highest first</option>
                </select>
                <label for="cat-sort" :class="fixedLabel">Sort by</label>
            </div>
            <div v-if="hasFilters" class="flex items-center sm:col-span-2 lg:col-span-6">
                <button type="button" class="text-xs font-medium text-[#4b5320] hover:underline" @click="clearFilters">Clear search and filters</button>
            </div>
        </div>

        <!-- One row per product and supplier -->
        <div class="flex flex-1 flex-col justify-between panel p-6">
            <div class="overflow-x-auto">
                <table class="w-full border-collapse text-left">
                    <thead>
                        <tr class="border-b border-gray-200 text-xs text-gray-500">
                            <th class="pb-3 font-semibold">Product Name</th>
                            <th class="pb-3 font-semibold">Category</th>
                            <th class="pb-3 font-semibold">Supplier Company</th>
                            <th class="pb-3 text-right font-semibold">Unit Cost (USD)</th>
                            <th class="pb-3 text-right font-semibold">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 text-sm">
                        <tr v-for="row in rows.data" :key="row.id" class="transition-colors hover:bg-gray-50/80">
                            <td class="max-w-md py-3.5 pr-4 align-top">
                                <button type="button" class="text-left font-medium text-gray-900 hover:text-[#4b5320]" @click="openPanel(row.catalog_product_id)">{{ row.product_name }}</button>
                                <div class="mt-1 flex flex-wrap items-center gap-1.5 text-[11px]">
                                    <span v-if="row.brand" class="text-gray-500">{{ row.brand }}</span>
                                    <span v-if="row.product_id" class="inline-flex items-center gap-1 rounded-full border border-emerald-200 bg-emerald-50 px-2 py-0.5 font-medium text-emerald-700">
                                        <CheckCircle2 class="h-3 w-3" /> In inventory · <span class="font-mono">{{ row.product_sku }}</span>
                                    </span>
                                    <span v-else class="rounded-full border border-gray-200 bg-gray-50 px-2 py-0.5 font-medium text-gray-600">Catalog only</span>
                                </div>
                            </td>
                            <td class="py-3.5 pr-4 align-top">
                                <span class="rounded-md bg-gray-100 px-2.5 py-0.5 text-xs font-medium whitespace-nowrap text-gray-700">{{ row.category }}</span>
                            </td>
                            <td class="py-3.5 pr-4 align-top">
                                <div class="flex items-start gap-1.5 text-gray-800">
                                    <Building2 class="mt-0.5 h-3.5 w-3.5 shrink-0 text-gray-400" />
                                    <span>{{ row.supplier_name }}</span>
                                </div>
                            </td>
                            <td class="py-3.5 text-right align-top whitespace-nowrap">
                                <div class="font-semibold text-gray-900">{{ money(row.unit_cost) }} <span class="text-xs font-normal text-gray-500">/ {{ row.pricing_unit }}</span></div>
                                <div class="mt-0.5 text-[11px]">
                                    <span v-if="row.offers > 1 && row.is_lowest" class="rounded-full border border-emerald-200 bg-emerald-50 px-2 py-0.5 font-medium text-emerald-700">Lowest of {{ row.offers }}</span>
                                    <span v-else-if="row.offers > 1" class="text-gray-500">+{{ money(row.difference_from_lowest) }} vs lowest {{ money(row.lowest_cost) }}</span>
                                    <span v-else class="text-gray-400">Only supplier</span>
                                </div>
                                <div v-if="!row.unit_confirmed" class="mt-0.5 text-[11px] text-amber-700" :title="row.pricing_unit_label ?? ''">Unit to confirm (pc or set)</div>
                            </td>
                            <td class="py-3.5 text-right align-top">
                                <button type="button" class="inline-flex items-center gap-1 rounded-md p-1.5 text-xs text-gray-600 transition-colors hover:bg-gray-100 hover:text-gray-900" :title="`Compare all ${row.offers} supplier cost(s)`" @click="openPanel(row.catalog_product_id)">
                                    <Scale class="h-4 w-4" />
                                    <span class="hidden xl:inline">{{ row.product_id ? 'Compare' : 'Compare / register' }}</span>
                                </button>
                            </td>
                        </tr>
                        <tr v-if="rows.data.length === 0">
                            <td colspan="5" class="py-14 text-center text-gray-400">
                                <Layers class="mx-auto mb-2 h-12 w-12 text-[#4b5320] opacity-30" />
                                <p class="text-base font-semibold text-gray-700">No supplier prices found</p>
                                <p class="mt-1 text-xs text-gray-400">{{ hasFilters ? 'Try clearing the search and filters.' : 'Import the supplier price list with php artisan catalog:import-supplier-prices.' }}</p>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="mt-5 flex flex-col items-center justify-between gap-3 border-t border-gray-100 pt-4 text-sm text-gray-500 sm:flex-row">
                <span>
                    Showing <strong class="text-gray-800">{{ rows.from || 0 }}</strong> to <strong class="text-gray-800">{{ rows.to || 0 }}</strong> of
                    <strong class="text-gray-800">{{ rows.total }}</strong> supplier prices
                </span>
                <div class="flex flex-wrap items-center gap-1.5">
                    <template v-for="(link, idx) in rows.links" :key="idx">
                        <button
                            v-if="link.url"
                            type="button"
                            :class="[link.active ? 'bg-[#4b5320] font-semibold text-white' : 'border border-gray-200 bg-white text-gray-700 hover:bg-gray-50', 'rounded-lg px-3 py-1.5 text-xs transition-colors']"
                            @click="router.visit(link.url, { only: ['rows', 'filters'], preserveScroll: true, preserveState: true })"
                            v-html="link.label"
                        ></button>
                        <span v-else-if="link.label.includes('Previous') || link.label.includes('Next')" class="cursor-not-allowed rounded-lg border border-gray-100 px-3 py-1.5 text-xs text-gray-400 opacity-50" v-html="link.label"></span>
                    </template>
                </div>
            </div>
        </div>
    </div>

    <!-- Compare suppliers / register in inventory -->
    <div v-if="panel.open" class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-black/50 p-4" @click.self="panel.open = false">
        <div class="my-8 w-full max-w-3xl panel p-6 shadow-xl" role="dialog" aria-modal="true" aria-labelledby="cat-panel-title">
            <div class="mb-4 flex items-start justify-between gap-4 border-b border-gray-100 pb-3">
                <div>
                    <h3 id="cat-panel-title" class="text-lg font-semibold text-gray-900">{{ panel.data?.catalog_product.name ?? 'Supplier prices' }}</h3>
                    <p v-if="panel.data" class="text-xs text-gray-500">
                        {{ panel.data.catalog_product.category }}<template v-if="panel.data.catalog_product.brand"> · {{ panel.data.catalog_product.brand }}</template>
                    </p>
                </div>
                <button type="button" class="text-gray-400 hover:text-gray-600" aria-label="Close" @click="panel.open = false"><X class="h-5 w-5" /></button>
            </div>

            <p v-if="panel.loading" class="py-10 text-center text-sm text-gray-500">Loading supplier prices…</p>
            <p v-else-if="panel.error" class="rounded-xl border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700">{{ panel.error }}</p>

            <template v-else-if="panel.data">
                <!-- All suppliers of this product, lowest first, per pricing unit -->
                <section v-for="g in unitGroups" :key="g.unit" class="mb-4">
                    <h4 class="mb-2 text-xs font-semibold tracking-wide text-gray-500 uppercase">
                        {{ g.prices.length }} supplier{{ g.prices.length === 1 ? '' : 's' }} · cost per {{ g.unit }}
                        <span v-if="g.unconfirmed" class="ml-1 font-normal text-amber-700 normal-case">(source unit "pc or set": confirm with the supplier)</span>
                    </h4>
                    <table class="w-full text-left text-sm">
                        <thead>
                            <tr class="border-b border-gray-200 text-xs text-gray-500">
                                <th class="pb-2 font-semibold">Supplier Company</th>
                                <th class="pb-2 text-right font-semibold">Unit Cost (USD)</th>
                                <th class="pb-2 text-right font-semibold">vs lowest</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <tr v-for="p in g.prices" :key="p.id">
                                <td class="py-2 pr-3">
                                    {{ p.supplier_name }}
                                    <span v-if="!p.supplier_active" class="ml-1 text-[11px] text-gray-400">(inactive supplier)</span>
                                </td>
                                <td class="py-2 text-right font-medium whitespace-nowrap text-gray-900">{{ money(p.unit_cost) }}</td>
                                <td class="py-2 text-right text-xs whitespace-nowrap">
                                    <span v-if="p.is_lowest" class="rounded-full border border-emerald-200 bg-emerald-50 px-2 py-0.5 font-medium text-emerald-700">Lowest</span>
                                    <span v-else class="text-gray-500">+{{ money(p.difference_from_lowest) }} ({{ ((p.difference_from_lowest / p.lowest_cost) * 100).toFixed(1) }}%)</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </section>
                <p v-if="unitGroups.length > 1" class="mb-4 text-xs text-amber-800">Costs in different pricing units are listed separately and are not compared with each other.</p>

                <!-- Already in inventory -->
                <section v-if="panel.data.inventory_product" class="rounded-xl border border-emerald-200 bg-emerald-50/60 px-4 py-3 text-sm">
                    <p class="font-medium text-emerald-900">
                        In inventory as <span class="font-mono">{{ panel.data.inventory_product.sku }}</span>
                        <span :class="statusClass(panel.data.inventory_product.status)" class="ml-2 rounded-full border px-2 py-0.5 text-[11px] font-medium">{{ panel.data.inventory_product.status }}</span>
                    </p>
                    <p class="mt-1 text-xs text-emerald-900/80">
                        Stock {{ panel.data.inventory_product.stock }} {{ panel.data.inventory_product.unit || '' }}. Supplier costs are recorded as unverified quotes on the product: verify them in
                        Suppliers to use them in Supplier Evaluation and purchase order pricing. Stock is added only by receiving deliveries or stock movements.
                    </p>
                </section>

                <!-- Register in inventory -->
                <form v-else class="space-y-4 rounded-xl border border-gray-200 p-4" novalidate @submit.prevent="register">
                    <div>
                        <h4 class="flex items-center gap-1.5 text-sm font-semibold text-gray-900"><PackagePlus class="h-4 w-4 text-[#4b5320]" /> Register in inventory</h4>
                        <p class="mt-0.5 text-xs text-gray-500">
                            Creates the inventory product with SKU <span class="font-mono font-medium text-gray-700">{{ panel.data.next_sku }}</span> (assigned when saved) and stock 0.
                            No stock, stock movement, purchase order or delivery is created. Every supplier's cost above is kept as an unverified quote.
                        </p>
                    </div>
                    <p v-if="formErrors.error" class="rounded-xl border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700">{{ formErrors.error }}</p>

                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                        <div class="relative">
                            <select id="reg-unit" v-model="form.unit" :class="fieldClass" required>
                                <option v-for="u in unitOptions" :key="u" :value="u">{{ u }}</option>
                            </select>
                            <label for="reg-unit" :class="fixedLabel">Stock unit (required)</label>
                            <span v-if="formErrors.unit" class="mt-1 block text-xs text-rose-600">{{ formErrors.unit }}</span>
                            <span v-else-if="unitToConfirm" class="mt-1 block text-[11px] text-amber-700">The price list says "pc or set": confirm the unit.</span>
                        </div>
                        <div class="relative">
                            <input id="reg-price" v-model="form.price" type="number" min="0" step="0.01" placeholder=" " :class="fieldClass" />
                            <label for="reg-price" :class="floatLabel">Selling price (USD, optional)</label>
                            <span v-if="formErrors.price" class="mt-1 block text-xs text-rose-600">{{ formErrors.price }}</span>
                            <span v-else class="mt-1 block text-[11px] text-gray-500">Blank: saved Incomplete (not sellable) until entered.</span>
                        </div>
                        <div class="relative">
                            <input id="reg-cost" v-model="form.cost_price" type="number" min="0" step="0.01" placeholder=" " :class="fieldClass" />
                            <label for="reg-cost" :class="floatLabel">Purchase cost (USD, optional)</label>
                            <span v-if="formErrors.cost_price" class="mt-1 block text-xs text-rose-600">{{ formErrors.cost_price }}</span>
                        </div>
                        <div class="relative sm:col-span-3">
                            <select id="reg-supplier" v-model="form.supplier_id" :class="fieldClass">
                                <option value="">No usual supplier yet</option>
                                <option v-for="p in panel.data.prices" :key="p.supplier_id" :value="p.supplier_id">{{ p.supplier_name }} — {{ money(p.unit_cost) }} / {{ p.pricing_unit }}</option>
                            </select>
                            <label for="reg-supplier" :class="fixedLabel">Usual supplier (optional, your choice)</label>
                            <span v-if="formErrors.supplier_id" class="mt-1 block text-xs text-rose-600">{{ formErrors.supplier_id }}</span>
                        </div>
                        <div class="relative">
                            <input id="reg-rl" v-model="form.reorder_level" type="number" min="0" step="1" placeholder=" " :class="fieldClass" />
                            <label for="reg-rl" :class="floatLabel">Reorder level (optional)</label>
                            <span v-if="formErrors.reorder_level" class="mt-1 block text-xs text-rose-600">{{ formErrors.reorder_level }}</span>
                        </div>
                        <div class="relative">
                            <input id="reg-rq" v-model="form.reorder_quantity" type="number" min="0" step="1" placeholder=" " :class="fieldClass" />
                            <label for="reg-rq" :class="floatLabel">Reorder quantity (optional)</label>
                            <span v-if="formErrors.reorder_quantity" class="mt-1 block text-xs text-rose-600">{{ formErrors.reorder_quantity }}</span>
                        </div>
                        <p class="self-center text-[11px] text-gray-500">Blank reorder values are not generated: set them later on the Inventory page.</p>
                    </div>

                    <div class="flex justify-end gap-2">
                        <button type="button" class="secondary-button" @click="panel.open = false">Cancel</button>
                        <button type="submit" class="brand-button" :disabled="saving">{{ saving ? 'Registering…' : 'Register in inventory' }}</button>
                    </div>
                </form>
            </template>
        </div>
    </div>
</template>
