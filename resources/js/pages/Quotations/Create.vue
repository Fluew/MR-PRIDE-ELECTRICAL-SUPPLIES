<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import {
    ArrowRight,
    CheckCircle2,
    ClipboardList,
    Send,
    MapPin,
    Plus,
    Trash2,
    AlertCircle,
    Info,
    UploadCloud,
} from '@lucide/vue';
import { nextTick, ref, computed } from 'vue';
import { vWordReveal } from '@/directives/wordReveal';
import SpotlightCard from '@/components/vue-bits/SpotlightCard.vue';
import SpecularButton from '@/components/ui/SpecularButton.vue';
import ProductListGuide from '@/components/quotation/ProductListGuide.vue';
import PublicLayout from '@/layouts/PublicLayout.vue';
import { parseQuantity } from '@/lib/quantity';
import { store } from '@/routes/quotations';
import { extract as extractProductList } from '@/routes/quotations/product-list';

const props = defineProps<{
    brands?: string[];
}>();

// One brand row: a catalog brand (Description + Quantity), or "Others" (Brand Name + Description + Quantity).
interface BrandItem {
    brand: string;
    brandName: string;
    details: string;
    quantity: string;
}

const emptyBrandItem = (): BrandItem => ({ brand: '', brandName: '', details: '', quantity: '' });

const form = useForm({
    company: '',
    contactName: '',
    email: '',
    phone: '',
    projectType: 'Commercial Building',
    timeline: '1-3 months',
    brandItems: [emptyBrandItem()] as BrandItem[],
    // Optional files listing specific products.
    productFiles: [] as File[],
});

const submittedSuccess = ref(false);
const formElement = ref<HTMLFormElement>();
const successMessage = ref<HTMLElement>();
// Errors for keys that are not form fields (brands, upload limits).
const formErrors = computed(() => form.errors as Record<string, string | undefined>);

// ------------------------------------------------------------------ product-list files

const PRODUCT_FILE_EXTENSIONS = ['pdf', 'xls', 'xlsx', 'csv', 'jpg', 'jpeg', 'png'];
const PRODUCT_FILE_ACCEPT = PRODUCT_FILE_EXTENSIONS.map((e) => `.${e}`).join(',');
const MAX_PRODUCT_FILES = 5;
const MAX_PRODUCT_FILE_MB = 25;
const MAX_PRODUCT_FILE_BYTES = MAX_PRODUCT_FILE_MB * 1024 * 1024;
const FILE_TOO_LARGE = `File size exceeds the ${MAX_PRODUCT_FILE_MB} MB limit. Please upload a smaller file.`;
const MAX_PRODUCT_FILES_BYTES = MAX_PRODUCT_FILES * MAX_PRODUCT_FILE_BYTES; // five full-size files
const productFileError = ref<string | null>(null);

function formatSize(bytes: number) {
    return bytes >= 1024 * 1024 ? `${(bytes / 1024 / 1024).toFixed(1)} MB` : `${Math.max(1, Math.round(bytes / 1024))} KB`;
}

function addProductFiles(event: Event) {
    const input = event.target as HTMLInputElement;
    const chosen = Array.from(input.files ?? []);
    input.value = '';
    productFileError.value = null;

    for (const file of chosen) {
        const ext = file.name.split('.').pop()?.toLowerCase() ?? '';
        if (!PRODUCT_FILE_EXTENSIONS.includes(ext)) {
            productFileError.value = `${file.name}: please choose a PDF, Excel, CSV, JPG or PNG file.`;
            continue;
        }
        if (file.size === 0) {
            productFileError.value = `${file.name} is empty.`;
            continue;
        }
        if (file.size > MAX_PRODUCT_FILE_BYTES) {
            productFileError.value = `${file.name} (${formatSize(file.size)}): ${FILE_TOO_LARGE}`;
            continue;
        }
        if (form.productFiles.some((f) => f.name === file.name && f.size === file.size)) {
            continue; // already selected
        }
        if (form.productFiles.length >= MAX_PRODUCT_FILES) {
            productFileError.value = `You can attach up to ${MAX_PRODUCT_FILES} files.`;
            break;
        }
        if (form.productFiles.reduce((sum, f) => sum + f.size, 0) + file.size > MAX_PRODUCT_FILES_BYTES) {
            productFileError.value = `Product-list files can be up to ${MAX_PRODUCT_FILES_BYTES / 1024 / 1024} MB together.`;
            break;
        }
        form.productFiles.push(file);
        queueCheck(addUpload(file));
    }
}

function removeProductFile(index: number) {
    const file = form.productFiles[index];
    const upload = uploads.value.find((u) => u.file === file);
    if (upload) {
        removeUpload(upload);
    } else {
        form.productFiles.splice(index, 1);
    }
    productFileError.value = null;
}

// ------------------------------------------------------------------ reading the product lists

// The guide is shown before the file picker opens.
const guideOpen = ref(false);
const productFileInput = ref<HTMLInputElement>();

function openGuide() {
    if (form.productFiles.length < MAX_PRODUCT_FILES) {
        guideOpen.value = true;
    }
}

function continueToUpload() {
    productFileInput.value?.click(); // still inside the click, so browsers open the picker
    guideOpen.value = false;
}

// Each file is checked on the server as soon as it is chosen (its type, damage, a photo instead of
// a list) and read for our team. The client only sees that it was uploaded: the products read from
// it are reviewed by staff when they prepare the quotation, never shown or confirmed here.
type UploadState = 'checking' | 'uploaded';

interface Upload {
    id: number;
    file: File;
    state: UploadState;
    hash: string | null;
    controller: AbortController | null;
}

const UPLOAD_CONFIRMATION = 'Product list uploaded successfully. Our team will review the product details when preparing your quotation.';

let uploadIds = 1;
const uploads = ref<Upload[]>([]);

const uploadFor = (file: File) => uploads.value.find((u) => u.file === file);
const showUploadConfirmation = computed(
    () => uploads.value.some((u) => u.state === 'uploaded') && !uploads.value.some((u) => u.state === 'checking'),
);

function addUpload(file: File): Upload {
    uploads.value.push({ id: uploadIds++, file, state: 'checking', hash: null, controller: null });

    return uploads.value[uploads.value.length - 1];
}

function removeUpload(upload: Upload) {
    upload.controller?.abort();
    uploads.value = uploads.value.filter((u) => u !== upload);
    form.productFiles = form.productFiles.filter((f) => f !== upload.file);
}

// Files are checked one after another, so a big scan does not slow the others down.
let checkQueue: Promise<void> = Promise.resolve();
function queueCheck(upload: Upload) {
    upload.state = 'checking';
    checkQueue = checkQueue.then(() => checkProductList(upload));
}

const csrfToken = () => (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement | null)?.content ?? '';

async function checkProductList(upload: Upload) {
    if (!uploads.value.includes(upload) || upload.state !== 'checking') {
        return; // removed, or sent with the request, while waiting
    }
    const controller = new AbortController();
    upload.controller = controller;

    const body = new FormData();
    body.append('file', upload.file);

    let response: Response | null = null;
    let data: Record<string, any> = {};
    try {
        response = await fetch(extractProductList().url, {
            method: 'POST',
            body,
            signal: controller.signal,
            credentials: 'same-origin',
            headers: { 'X-CSRF-TOKEN': csrfToken(), Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        });
        data = await response.json().catch(() => ({}));
    } catch {
        if (controller.signal.aborted) {
            return; // cancelled by the client
        }
        // Not checked now (network): the file is still sent, and checked again on the server.
    } finally {
        upload.controller = null;
    }
    if (!uploads.value.includes(upload)) {
        return;
    }

    // Only a file that cannot be sent at all is reported; reading problems are for our team.
    if (response?.status === 413) {
        removeUpload(upload);
        productFileError.value = `${upload.file.name}: ${FILE_TOO_LARGE}`;
        return;
    }
    if (response?.status === 422 && data.keep_file === false) {
        removeUpload(upload);
        productFileError.value = `${upload.file.name}: ${typeof data.message === 'string' && data.message ? data.message : 'Unable to process this file. Please check the document and try again.'}`;
        return;
    }

    // The same file under another name: kept once.
    const hash = response?.ok && typeof data.file?.hash === 'string' ? data.file.hash : null;
    const same = hash ? uploads.value.find((u) => u !== upload && u.hash === hash) : undefined;
    if (same) {
        removeUpload(upload);
        productFileError.value = `${upload.file.name} is the same file as ${same.file.name}, so it was not added again.`;
        return;
    }

    upload.hash = hash;
    upload.state = 'uploaded';
}


function previewProductFile(file: File) {
    const url = URL.createObjectURL(file);
    window.open(url, '_blank', 'noopener');
    setTimeout(() => URL.revokeObjectURL(url), 60000);
}

const canPreview = (file: File) => /\.(pdf|jpe?g|png)$/i.test(file.name);

const productFileServerError = computed(() => {
    const key = Object.keys(form.errors).find((k) => k.startsWith('productFiles'));
    return key ? (form.errors as Record<string, string>)[key] : null;
});

const fields = [
    {
        key: 'company',
        label: 'Company name',
        type: 'text',
        autocomplete: 'organization',
        placeholder: 'Your company',
        maxlength: 255,
    },
    {
        key: 'contactName',
        label: 'Contact name',
        type: 'text',
        autocomplete: 'name',
        placeholder: 'Your full name',
        maxlength: 255,
    },
    {
        key: 'email',
        label: 'Email address',
        type: 'email',
        autocomplete: 'email',
        placeholder: 'you@company.com',
        maxlength: 255,
    },
    {
        key: 'phone',
        label: 'Phone number',
        type: 'tel',
        autocomplete: 'tel',
        placeholder: 'Your contact number',
        maxlength: 50,
    },
] as const;

// "Others": a brand that is not in the list; the client describes the product in their own words.
const OTHERS = 'Others';

// A catalog brand already chosen in another row cannot be chosen again ("Others" can).
function isBrandSelectedElsewhere(brand: string, currentRowIndex: number) {
    return form.brandItems.some(
        (item, idx) => idx !== currentRowIndex && item.brand === brand,
    );
}

// A new row needs the last one filled; at most 50 rows (the server's limit).
const canAddBrandRow = computed(
    () =>
        form.brandItems.every((item) => item.brand !== '') &&
        form.brandItems.length < 50,
);

// Checked before sending: a description or quantity needs a brand (or Others); Others needs a
// brand name and a description; a quantity, when given, must be a number greater than zero.
const brandsError = ref<string | null>(null);
type BrandField = 'brandName' | 'details' | 'quantity';
const brandRowErrors = ref<Record<number, Partial<Record<BrandField, string>>>>({});

function checkBrandRows(): boolean {
    brandsError.value = null;
    const errors: Record<number, Partial<Record<BrandField, string>>> = {};
    form.brandItems.forEach((item, index) => {
        const row: Partial<Record<BrandField, string>> = {};
        if (item.brand === OTHERS && item.brandName.trim() === '') {
            row.brandName = 'Please enter the brand name.';
        }
        if (item.brand === OTHERS && item.details.trim() === '') {
            row.details = 'Please describe the product you need.';
        }
        if (item.quantity.trim() !== '') {
            const quantity = parseQuantity(item.quantity);
            if (quantity.error) {
                row.quantity = quantity.error;
            }
        }
        if (Object.keys(row).length) {
            errors[index] = row;
        }
    });
    brandRowErrors.value = errors;

    if (form.brandItems.some((item) => !item.brand && (item.details.trim() !== '' || item.quantity.trim() !== ''))) {
        brandsError.value = 'Choose a brand for each product description, or choose Others if the brand is not listed.';
    } else if (Object.keys(errors).length) {
        brandsError.value = 'Please check the highlighted brand fields.';
    }
    return brandsError.value === null;
}

// "Others – Brand Not Listed" adds the Brand Name field: the cursor moves there. The description
// and quantity already typed are kept when switching between brands.
async function onBrandChange(index: number) {
    brandsError.value = null;
    delete brandRowErrors.value[index];
    if (form.brandItems[index]?.brand === OTHERS) {
        await nextTick();
        document.getElementById(`brand-name-${index}`)?.focus();
    }
}

function clearBrandError(index: number, field: BrandField) {
    brandsError.value = null;
    if (brandRowErrors.value[index]) {
        delete brandRowErrors.value[index][field];
    }
}

// Floating labels stay small and raised while a field has a value (as on the contact fields).
const floatingLabelClass = (filled: boolean) => (filled ? '-translate-y-2.5 scale-75 text-[#4b5320]' : '');

function addBrandRow() {
    if (!canAddBrandRow.value) {
        return;
    }
    form.brandItems.push(emptyBrandItem());
}

function removeBrandRow(index: number) {
    brandRowErrors.value = {};
    if (form.brandItems.length > 1) {
        form.brandItems.splice(index, 1);
    } else {
        form.brandItems[0] = emptyBrandItem();
    }
}

function submit() {
    if (form.processing) {
        return;
    }

    if (!checkBrandRows()) {
        return;
    }

    // Files still being checked are sent as they are: the server checks and reads them again.
    for (const upload of uploads.value) {
        if (upload.state === 'checking') {
            upload.controller?.abort();
            upload.state = 'uploaded';
        }
    }

    submittedSuccess.value = false;
    form
        .transform((data) => ({
            ...data,
            brands: data.brandItems
                .filter((item) => item.brand.trim() !== '')
                .map((item) => ({
                    brand: item.brand.trim(),
                    brand_name: item.brand === OTHERS ? item.brandName.trim() : '',
                    details: item.details.trim(),
                    quantity: item.quantity.trim(),
                })),
        }))
        .post(store().url, {
            preserveScroll: true,
            onSuccess: async () => {
                submittedSuccess.value = true;
                form.reset();
                form.brandItems = [emptyBrandItem()];
                form.productFiles = [];
                productFileError.value = null;
                uploads.value = [];
                await nextTick();
                successMessage.value?.focus();
            },
            onError: async () => {
                await nextTick();
                formElement.value
                    ?.querySelector<HTMLElement>('[aria-invalid="true"]')
                    ?.focus();
            },
        });
}
</script>

<template>
    <Head title="Request a Quotation | MR-Pride" />
    <PublicLayout>
        <div
            class="mx-auto grid max-w-7xl gap-10 px-4 py-14 sm:px-6 lg:grid-cols-[0.85fr_1.15fr] lg:gap-16 lg:px-8 lg:py-20"
        >
            <div class="self-start lg:sticky lg:top-28">
                <p v-word-reveal class="section-eyebrow">Plan your next project</p>
                <h1 v-word-reveal
                    class="mt-5 text-4xl leading-tight font-semibold tracking-tight sm:text-5xl"
                >
                    The right supplies.<br /><span class="text-[#4b5320]"
                        >A quote for your needs.</span
                    >
                </h1>
                <p v-word-reveal class="mt-6 text-base leading-8 text-gray-600">
                    Tell us about your project and the brands you prefer. Our
                    team will review your requirements and help you find the
                    right products.
                </p>
                <details
                    class="mt-7 rounded-xl border border-gray-200 bg-white p-4"
                >
                    <summary v-word-reveal
                        class="cursor-pointer text-sm font-semibold text-[#4b5320]"
                    >
                        What happens after you submit?
                    </summary>
                    <ol class="mt-5 grid gap-6">
                        <li
                            v-for="(step, index) in [
                                {
                                    title: 'Share your requirements',
                                    text: 'Share your project details and the brands you prefer.',
                                    icon: ClipboardList,
                                },
                                {
                                    title: 'Our team reviews your request',
                                    text: 'We check every item, match products and prepare verified pricing.',
                                    icon: CheckCircle2,
                                },
                                {
                                    title: 'Receive your formal quotation',
                                    text: 'We contact you with a detailed, itemized quote.',
                                    icon: Send,
                                },
                            ]"
                            :key="step.title"
                            class="flex gap-4"
                        >
                            <span
                                class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-[#4b5320]/10 text-sm font-semibold text-[#4b5320]"
                                >0{{ index + 1 }}</span
                            >
                            <div>
                                <h2 class="text-sm font-semibold">
                                    {{ step.title }}
                                </h2>
                                <p class="mt-1 text-sm leading-6 text-gray-500">
                                    {{ step.text }}
                                </p>
                            </div>
                        </li>
                    </ol>
                </details>
                <div
                    class="mt-6 flex gap-3 border-t border-gray-200 pt-6 text-sm leading-6 text-gray-500"
                >
                    <MapPin class="size-5 shrink-0 text-[#4b5320]" />
                    <p v-word-reveal>#610 Esguerra St., Tondo<br />Manila, Philippines</p>
                </div>
            </div>
            <SpotlightCard class="p-6 sm:p-9">
                <h2 class="text-xl font-semibold">Request a quotation</h2>
                <div
                    v-if="submittedSuccess"
                    ref="successMessage"
                    tabindex="-1"
                    role="status"
                    class="mt-6 rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm leading-6 text-emerald-800"
                >
                    <p class="font-semibold">Your request has been received.</p>
                    <p>
                        Our team will review your request and contact you
                        shortly with an official itemized quotation.
                    </p>
                </div>
                <form
                    ref="formElement"
                    class="mt-7 space-y-6"
                    :aria-busy="form.processing"
                    @submit.prevent="submit"
                >
                    <!-- Client Details with Floating Labels -->
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div v-for="field in fields" :key="field.key">
                            <div class="relative">
                                <input
                                    :id="field.key"
                                    v-model="form[field.key]"
                                    :name="field.key"
                                    :type="field.type"
                                    :autocomplete="field.autocomplete"
                                    :maxlength="field.maxlength"
                                    placeholder=" "
                                    required
                                    class="peer block w-full rounded-xl border border-gray-200 bg-gray-50/50 px-3.5 pt-5 pb-2 text-xs text-gray-900 focus:border-[#4b5320] focus:bg-white focus:ring-1 focus:ring-[#4b5320] focus:outline-none sm:text-sm"
                                    :class="
                                        form.errors[field.key]
                                            ? 'border-red-500 focus:border-red-500 focus:ring-red-500'
                                            : ''
                                    "
                                    :aria-invalid="!!form.errors[field.key]"
                                    :aria-describedby="
                                        form.errors[field.key]
                                            ? `${field.key}-error`
                                            : undefined
                                    "
                                />
                                <label
                                    :for="field.key"
                                    class="pointer-events-none absolute top-3.5 left-3.5 origin-[0] -translate-y-2.5 scale-75 transform text-xs font-semibold text-gray-500 duration-200 peer-placeholder-shown:translate-y-0 peer-placeholder-shown:scale-100 peer-focus:-translate-y-2.5 peer-focus:scale-75 peer-focus:text-[#4b5320]"
                                    :class="
                                        form[field.key]
                                            ? '-translate-y-2.5 scale-75 text-[#4b5320]'
                                            : ''
                                    "
                                >
                                    {{ field.label }}
                                </label>
                            </div>
                            <p
                                v-if="form.errors[field.key]"
                                :id="`${field.key}-error`"
                                class="mt-1.5 text-xs text-red-600"
                            >
                                {{ form.errors[field.key] }}
                            </p>
                        </div>
                    </div>

                    <!-- Project Info -->
                    <div class="grid gap-5 sm:grid-cols-2">
                        <div>
                            <label for="projectType" class="field-label"
                                >Project type</label
                            ><select
                                id="projectType"
                                v-model="form.projectType"
                                class="field-control"
                                :aria-invalid="!!form.errors.projectType"
                                aria-describedby="projectType-error"
                            >
                                <option>Commercial Building</option>
                                <option>Industrial Facility</option>
                                <option>Infrastructure/Public Works</option>
                                <option>Residential Complex</option>
                                <option>Other</option>
                            </select>
                            <p
                                id="projectType-error"
                                class="mt-2 text-xs text-red-600"
                            >
                                {{ form.errors.projectType }}
                            </p>
                        </div>
                        <div>
                            <label for="timeline" class="field-label"
                                >Expected timeline</label
                            ><select
                                id="timeline"
                                v-model="form.timeline"
                                class="field-control"
                                :aria-invalid="!!form.errors.timeline"
                                aria-describedby="timeline-error"
                            >
                                <option>Immediate (ASAP)</option>
                                <option value="1-3 months">1–3 months</option>
                                <option value="3-6 months">3–6 months</option>
                                <option>Over 6 months</option>
                            </select>
                            <p
                                id="timeline-error"
                                class="mt-2 text-xs text-red-600"
                            >
                                {{ form.errors.timeline }}
                            </p>
                        </div>
                    </div>

                    <!-- Brands -->
                    <div
                        class="space-y-5 rounded-xl border border-gray-200 bg-gray-50/50 p-5"
                    >
                        <!-- Catalog brands (optional) -->
                        <div>
                            <div class="mb-3">
                                <label
                                    class="field-label mb-0 font-semibold text-gray-900"
                                >
                                    Brands
                                </label>
                                <p class="mt-0.5 text-xs text-gray-500">
                                    Select a brand and describe the product you
                                    need. If the brand is not listed, choose
                                    Others.
                                </p>
                            </div>

                            <!-- Empty catalog: "Others" still works -->
                            <div
                                v-if="!brands || brands.length === 0"
                                class="mb-4 rounded-xl border border-gray-200 bg-white p-4 text-center text-xs text-gray-500"
                            >
                                <Info
                                    class="mx-auto mb-1 size-4 text-gray-400"
                                />
                                No brands are listed in our catalog yet. Choose
                                Others and describe the product you need.
                            </div>

                            <!-- Brand rows -->
                            <div class="space-y-4">
                                <div
                                    v-for="(item, index) in form.brandItems"
                                    :key="index"
                                    class="rounded-xl border border-gray-200 bg-white p-4 transition-colors hover:border-gray-300 space-y-2.5"
                                >
                                    <!-- Header: Brand label & Delete icon -->
                                    <div class="flex items-center justify-between">
                                        <span class="text-xs font-semibold text-gray-800">
                                            Brand {{ form.brandItems.length > 1 ? `#${index + 1}` : '' }}
                                        </span>
                                        <button
                                            type="button"
                                            :disabled="form.brandItems.length === 1 && !item.brand && !item.brandName && !item.details && !item.quantity"
                                            class="cursor-pointer rounded-lg p-1.5 text-gray-400 transition-colors hover:bg-red-50 hover:text-red-600 disabled:pointer-events-none disabled:opacity-30"
                                            title="Remove this brand"
                                            aria-label="Remove brand"
                                            @click="removeBrandRow(index)"
                                        >
                                            <Trash2 class="size-4" />
                                        </button>
                                    </div>

                                    <!-- Dropdown (Select a Brand) -->
                                    <div>
                                        <select
                                            v-model="item.brand"
                                            :aria-label="`Brand ${index + 1}`"
                                            class="field-control w-full px-3 py-2 text-sm"
                                            @change="onBrandChange(index)"
                                        >
                                            <option value="">
                                                Select a Brand
                                            </option>
                                            <option
                                                v-for="brand in brands"
                                                :key="brand"
                                                :value="brand"
                                                :disabled="isBrandSelectedElsewhere(brand, index)"
                                            >
                                                {{ brand }}
                                            </option>
                                            <!-- The only way to ask for a brand that is not in the list -->
                                            <option :value="OTHERS">
                                                Others – Brand Not Listed
                                            </option>
                                        </select>
                                    </div>

                                    <!-- Floating-label fields: Brand Name (Others only), Description and Quantity.
                                         The description is in the client's own words, never matched to our inventory. -->
                                    <div class="grid gap-2.5 sm:grid-cols-[1fr_9rem]" :data-test="`brand-fields-${index}`">
                                        <div v-if="item.brand === OTHERS" class="sm:col-span-2">
                                            <div class="relative">
                                                <input
                                                    :id="`brand-name-${index}`"
                                                    v-model="item.brandName"
                                                    type="text"
                                                    maxlength="100"
                                                    placeholder=" "
                                                    required
                                                    class="peer block w-full rounded-xl border bg-gray-50/50 px-3.5 pt-5 pb-2 text-xs text-gray-900 focus:bg-white focus:ring-1 focus:outline-none sm:text-sm"
                                                    :class="brandRowErrors[index]?.brandName ? 'border-red-500 focus:border-red-500 focus:ring-red-500' : 'border-gray-200 focus:border-[#4b5320] focus:ring-[#4b5320]'"
                                                    :aria-invalid="!!brandRowErrors[index]?.brandName"
                                                    :aria-describedby="brandRowErrors[index]?.brandName ? `brand-name-${index}-error` : undefined"
                                                    @input="clearBrandError(index, 'brandName')"
                                                />
                                                <label
                                                    :for="`brand-name-${index}`"
                                                    class="pointer-events-none absolute top-3.5 left-3.5 origin-[0] -translate-y-2.5 scale-75 transform text-xs font-semibold text-gray-500 duration-200 peer-placeholder-shown:translate-y-0 peer-placeholder-shown:scale-100 peer-focus:-translate-y-2.5 peer-focus:scale-75 peer-focus:text-[#4b5320]"
                                                    :class="floatingLabelClass(!!item.brandName)"
                                                >
                                                    Brand Name<span class="text-red-600"> *</span>
                                                </label>
                                            </div>
                                            <p v-if="brandRowErrors[index]?.brandName" :id="`brand-name-${index}-error`" class="mt-1.5 text-xs text-red-600">
                                                {{ brandRowErrors[index]?.brandName }}
                                            </p>
                                        </div>

                                        <div>
                                            <div class="relative">
                                                <textarea
                                                    :id="`brand-details-${index}`"
                                                    v-model="item.details"
                                                    rows="2"
                                                    maxlength="1000"
                                                    placeholder=" "
                                                    :required="item.brand === OTHERS"
                                                    class="peer block w-full resize-y rounded-xl border bg-gray-50/50 px-3.5 pt-5 pb-2 text-xs text-gray-900 focus:bg-white focus:ring-1 focus:outline-none sm:text-sm"
                                                    :class="brandRowErrors[index]?.details ? 'border-red-500 focus:border-red-500 focus:ring-red-500' : 'border-gray-200 focus:border-[#4b5320] focus:ring-[#4b5320]'"
                                                    :aria-invalid="!!brandRowErrors[index]?.details"
                                                    :aria-describedby="brandRowErrors[index]?.details ? `brand-details-${index}-error` : undefined"
                                                    @input="clearBrandError(index, 'details')"
                                                ></textarea>
                                                <label
                                                    :for="`brand-details-${index}`"
                                                    class="pointer-events-none absolute top-3.5 left-3.5 origin-[0] -translate-y-2.5 scale-75 transform text-xs font-semibold text-gray-500 duration-200 peer-placeholder-shown:translate-y-0 peer-placeholder-shown:scale-100 peer-focus:-translate-y-2.5 peer-focus:scale-75 peer-focus:text-[#4b5320]"
                                                    :class="floatingLabelClass(!!item.details)"
                                                >
                                                    Description<span v-if="item.brand === OTHERS" class="text-red-600"> *</span>
                                                </label>
                                            </div>
                                            <p v-if="brandRowErrors[index]?.details" :id="`brand-details-${index}-error`" class="mt-1.5 text-xs text-red-600">
                                                {{ brandRowErrors[index]?.details }}
                                            </p>
                                        </div>

                                        <div>
                                            <div class="relative">
                                                <input
                                                    :id="`brand-quantity-${index}`"
                                                    v-model="item.quantity"
                                                    type="text"
                                                    inputmode="decimal"
                                                    maxlength="15"
                                                    placeholder=" "
                                                    class="peer block w-full rounded-xl border bg-gray-50/50 px-3.5 pt-5 pb-2 text-xs text-gray-900 focus:bg-white focus:ring-1 focus:outline-none sm:text-sm"
                                                    :class="brandRowErrors[index]?.quantity ? 'border-red-500 focus:border-red-500 focus:ring-red-500' : 'border-gray-200 focus:border-[#4b5320] focus:ring-[#4b5320]'"
                                                    :aria-invalid="!!brandRowErrors[index]?.quantity"
                                                    :aria-describedby="brandRowErrors[index]?.quantity ? `brand-quantity-${index}-error` : undefined"
                                                    @input="clearBrandError(index, 'quantity')"
                                                />
                                                <label
                                                    :for="`brand-quantity-${index}`"
                                                    class="pointer-events-none absolute top-3.5 left-3.5 origin-[0] -translate-y-2.5 scale-75 transform text-xs font-semibold text-gray-500 duration-200 peer-placeholder-shown:translate-y-0 peer-placeholder-shown:scale-100 peer-focus:-translate-y-2.5 peer-focus:scale-75 peer-focus:text-[#4b5320]"
                                                    :class="floatingLabelClass(!!item.quantity)"
                                                >
                                                    Quantity
                                                </label>
                                            </div>
                                            <p v-if="brandRowErrors[index]?.quantity" :id="`brand-quantity-${index}-error`" class="mt-1.5 text-xs text-red-600">
                                                {{ brandRowErrors[index]?.quantity }}
                                            </p>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Add another brand -->
                            <div
                                class="mt-3 flex items-center justify-between"
                            >
                                <button
                                    type="button"
                                    :disabled="!canAddBrandRow"
                                    class="inline-flex cursor-pointer items-center gap-1.5 rounded-xl border border-gray-200 bg-white px-3.5 py-2 text-xs font-semibold text-gray-700 transition-colors hover:border-[#4b5320] hover:bg-[#4b5320]/5 hover:text-[#4b5320] disabled:cursor-not-allowed disabled:opacity-50"
                                    @click="addBrandRow"
                                >
                                    <Plus class="size-3.5" />
                                    <span>Add Another Brand</span>
                                </button>
                            </div>

                            <!-- Optional: files naming the specific products wanted -->
                            <div class="mt-4" data-test="product-list-upload">
                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    <div class="min-w-0">
                                        <p class="text-xs font-semibold text-gray-800">Want to specify particular products?</p>
                                        <p class="text-xs text-gray-500">
                                            Upload a product list or select a file containing the materials you need.
                                        </p>
                                    </div>
                                    <button
                                        type="button"
                                        class="inline-flex shrink-0 cursor-pointer items-center gap-1.5 rounded-lg border border-gray-200 bg-white px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50 focus-visible:outline-2 focus-visible:outline-[#4b5320]"
                                        :class="form.productFiles.length >= MAX_PRODUCT_FILES ? 'pointer-events-none opacity-50' : ''"
                                        :disabled="form.productFiles.length >= MAX_PRODUCT_FILES"
                                        aria-haspopup="dialog"
                                        data-test="product-list-upload-button"
                                        @click="openGuide"
                                    >
                                        <UploadCloud class="size-3.5 text-gray-500" />
                                        <span>Upload product list</span>
                                    </button>
                                    <!-- Opened by "Continue to Upload" in the guide -->
                                    <input
                                        ref="productFileInput"
                                        type="file"
                                        multiple
                                        class="sr-only"
                                        tabindex="-1"
                                        aria-hidden="true"
                                        :accept="PRODUCT_FILE_ACCEPT"
                                        :disabled="form.productFiles.length >= MAX_PRODUCT_FILES"
                                        data-test="product-file-input"
                                        @change="addProductFiles"
                                    />
                                </div>
                                <ul v-if="form.productFiles.length" class="mt-2 divide-y divide-gray-100 rounded-lg border border-gray-200 bg-white">
                                    <li v-for="(f, i) in form.productFiles" :key="f.name + f.size" class="px-3 py-1.5 text-xs">
                                        <div class="flex items-center gap-2">
                                            <span class="min-w-0 flex-1 truncate text-gray-800" :title="f.name">{{ f.name }}</span>
                                            <span class="shrink-0 text-gray-400">{{ formatSize(f.size) }}</span>
                                            <button v-if="canPreview(f)" type="button" class="shrink-0 font-medium text-gray-600 hover:text-gray-900" @click="previewProductFile(f)">Preview</button>
                                            <button type="button" class="shrink-0 font-medium text-red-600 hover:text-red-700" :aria-label="`Remove ${f.name}`" @click="removeProductFile(i)">Remove</button>
                                        </div>
                                        <!-- Only shown while the file is being checked; what is read from it is for our team -->
                                        <p v-if="uploadFor(f)?.state === 'checking'" class="mt-0.5 flex items-center gap-2 text-gray-500" aria-live="polite">
                                            <span>Uploading…</span>
                                            <button type="button" class="font-medium text-gray-600 hover:text-gray-900" :aria-label="`Cancel uploading ${f.name}`" @click="removeUpload(uploadFor(f)!)">Cancel</button>
                                        </p>
                                    </li>
                                </ul>
                                <p class="mt-1 text-[11px] text-gray-400">PDF, Excel, CSV, JPG or PNG (handwritten or printed product lists) · Up to {{ MAX_PRODUCT_FILES }} files, {{ MAX_PRODUCT_FILE_MB }} MB each</p>
                                <p v-if="productFileError || productFileServerError" class="mt-1 text-xs font-medium text-red-600" role="alert">
                                    {{ productFileError || productFileServerError }}
                                </p>
                                <p v-if="showUploadConfirmation" class="mt-2 flex items-start gap-1.5 text-xs text-emerald-700" role="status" data-test="product-list-uploaded">
                                    <CheckCircle2 class="mt-0.5 size-3.5 shrink-0" />
                                    <span>{{ UPLOAD_CONFIRMATION }}</span>
                                </p>

                                <ProductListGuide v-model:open="guideOpen" :max-files="MAX_PRODUCT_FILES" :max-file-mb="MAX_PRODUCT_FILE_MB" @continue="continueToUpload" />
                            </div>
                        </div>

                        <!-- Inline validation notice -->
                        <div
                            v-if="brandsError || formErrors.brands || formErrors.upload"
                            class="flex items-center gap-2 rounded-xl border border-red-200 bg-red-50 p-3 text-xs font-medium text-red-700"
                            role="alert"
                        >
                            <AlertCircle class="size-4 shrink-0 text-red-600" />
                            <span>{{ brandsError || formErrors.brands || formErrors.upload }}</span>
                        </div>
                    </div>

                    <!-- Saving failed on the server: everything entered is still in the form -->
                    <div
                        v-if="formErrors.submit"
                        class="flex items-center gap-2 rounded-xl border border-red-200 bg-red-50 p-3 text-xs font-medium text-red-700"
                        role="alert"
                    >
                        <AlertCircle class="size-4 shrink-0 text-red-600" />
                        <span>{{ formErrors.submit }}</span>
                    </div>

                    <!-- Submit Button -->
                    <SpecularButton
                        type="submit"
                        variant="brand"
                        size="lg"
                        :disabled="form.processing"
                        class="w-full cursor-pointer font-semibold text-white shadow-md transition-colors"
                    >
                        <span>{{
                            form.processing
                                ? form.progress?.percentage && form.progress.percentage < 100
                                    ? `Uploading… ${form.progress.percentage}%`
                                    : 'Sending your request…'
                                : 'Submit Quotation Request'
                        }}</span>
                        <ArrowRight v-if="!form.processing" class="size-4" />
                    </SpecularButton>
                </form>
            </SpotlightCard>
        </div>
    </PublicLayout>
</template>
