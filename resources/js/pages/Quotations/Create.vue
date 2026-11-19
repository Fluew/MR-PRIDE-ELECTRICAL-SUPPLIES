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
import { digitsOnly, emailError, phoneError, requiredTextError, wholeQuantityError, type PhoneRules } from '@/lib/validation';
import { store } from '@/routes/quotations';
import { extract as extractProductList } from '@/routes/quotations/product-list';

const props = defineProps<{
    brands?: string[];
    phoneRules: PhoneRules;
    /** Extensions the server reads (one list, from the server). */
    productFileTypes?: string[];
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
    // Chosen from the list (never typed); the number below is digits only.
    phoneCountry: props.phoneRules.default,
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

const PRODUCT_FILE_EXTENSIONS = props.productFileTypes?.length ? props.productFileTypes : ['pdf', 'xls', 'xlsx', 'csv', 'jpg', 'jpeg', 'png'];
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
            productFileError.value = `${file.name}: this file type is not supported. Please choose a PDF, Word, Excel, PowerPoint, text, CSV or picture file.`;
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
    recheckBrandRows();
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
    recheckBrandRows();
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
    recheckBrandRows();
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
    recheckBrandRows();
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

// ------------------------------------------------------------------ contact details: checked while typing and before sending

type ContactKey = 'company' | 'contactName' | 'email' | 'phone';

function contactError(key: ContactKey): string | null {
    switch (key) {
        case 'company':
            return requiredTextError(form.company, 'Enter your company name.');
        case 'contactName':
            return requiredTextError(form.contactName, 'Enter the contact name.');
        case 'email':
            return emailError(form.email);
        case 'phone':
            return phoneError(props.phoneRules, form.phoneCountry, form.phone);
    }
}

// Shows or clears the message under one field.
function validateContact(key: ContactKey): boolean {
    const message = contactError(key);
    if (message) {
        form.setError(key, message);
    } else {
        form.clearErrors(key);
    }
    return message === null;
}

// While typing: an error that is showing goes away as soon as the value is right; a new one is shown when the
// field is left (blur) or the form is sent.
function onContactInput(key: ContactKey) {
    if (form.errors[key] && contactError(key) === null) {
        form.clearErrors(key);
    }
}

const phoneCountryInfo = computed(() => props.phoneRules.countries.find((c) => c.code === form.phoneCountry));

// The number accepts digits only: other characters are never entered, whether typed or pasted.
function onPhoneInput() {
    const digits = digitsOnly(form.phone);
    if (digits !== form.phone) {
        form.phone = digits;
    }
    onContactInput('phone');
}

const CONTROL_KEYS = ['Backspace', 'Delete', 'Tab', 'Enter', 'ArrowLeft', 'ArrowRight', 'Home', 'End'];
function digitsKeydown(event: KeyboardEvent) {
    if (event.ctrlKey || event.metaKey || event.altKey || CONTROL_KEYS.includes(event.key)) {
        return;
    }
    if (!/^\d$/.test(event.key)) {
        event.preventDefault();
    }
}

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

// Checked while typing and before sending. A product row needs a brand (or Others), a description and a whole-number
// quantity. The one exception is an uploaded product list: it names the products, so brand becomes optional, and a
// row left empty is simply ignored. Others also needs the brand name.
const brandsError = ref<string | null>(null);
type BrandField = 'brand' | 'brandName' | 'details' | 'quantity';
const brandRowErrors = ref<Record<number, Partial<Record<BrandField, string>>>>({});

const hasProductList = computed(() => form.productFiles.length > 0);
const rowIsBlank = (item: BrandItem) => !item.brand && !item.brandName.trim() && !item.details.trim() && !item.quantity.trim();

function rowErrors(item: BrandItem): Partial<Record<BrandField, string>> {
    const row: Partial<Record<BrandField, string>> = {};
    if (rowIsBlank(item)) {
        return row;
    }
    // With a product list a row that only names a brand is a preference and needs nothing more.
    const preferenceOnly = hasProductList.value && item.brand !== '' && !item.details.trim() && !item.quantity.trim() && item.brand !== OTHERS;
    if (!item.brand && !hasProductList.value) {
        row.brand = 'Select a brand, or choose Others if it is not listed.';
    }
    if (item.brand === OTHERS && item.brandName.trim() === '') {
        row.brandName = 'Please enter the brand name.';
    }
    if (!preferenceOnly) {
        if (item.details.trim() === '') {
            row.details = 'Describe the product you need.';
        }
        const quantity = wholeQuantityError(item.quantity);
        if (quantity) {
            row.quantity = quantity;
        }
    }
    return row;
}

function checkBrandRows(): boolean {
    brandsError.value = null;
    const errors: Record<number, Partial<Record<BrandField, string>>> = {};
    form.brandItems.forEach((item, index) => {
        const row = rowErrors(item);
        if (Object.keys(row).length) {
            errors[index] = row;
        }
    });
    brandRowErrors.value = errors;

    if (Object.keys(errors).length) {
        brandsError.value = 'Please check the highlighted product fields.';
    } else if (!hasProductList.value && form.brandItems.every(rowIsBlank)) {
        brandsError.value = 'Add at least one product (brand, description and quantity), or upload a product list.';
    }
    return brandsError.value === null;
}

// Re-checks once something was already flagged (for example after a product list is uploaded or removed, which
// changes whether a brand is required), so obsolete errors disappear.
function recheckBrandRows() {
    if (brandsError.value || Object.keys(brandRowErrors.value).length) {
        checkBrandRows();
    }
}

// "Others – Brand Not Listed" adds the Brand Name field: the cursor moves there. The description
// and quantity already typed are kept when switching between brands.
async function onBrandChange(index: number) {
    brandsError.value = null;
    delete brandRowErrors.value[index]?.brand;
    delete brandRowErrors.value[index]?.brandName;
    if (form.brandItems[index]?.brand === OTHERS) {
        await nextTick();
        document.getElementById(`brand-name-${index}`)?.focus();
    }
}

// While typing: a showing error goes away as soon as the value is right.
function clearBrandError(index: number, field: BrandField) {
    const item = form.brandItems[index];
    if (!item || !brandRowErrors.value[index]?.[field]) {
        return;
    }
    if (!rowErrors(item)[field]) {
        delete brandRowErrors.value[index][field];
        if (!Object.keys(brandRowErrors.value[index]).length) {
            delete brandRowErrors.value[index];
        }
        if (!Object.keys(brandRowErrors.value).length) {
            brandsError.value = null;
        }
    }
}

// Shown when the field is left.
function checkBrandField(index: number, field: BrandField) {
    const item = form.brandItems[index];
    const message = item ? rowErrors(item)[field] : undefined;
    if (message) {
        brandRowErrors.value[index] = { ...(brandRowErrors.value[index] ?? {}), [field]: message };
    }
}

// The quantity accepts digits only, typed or pasted.
function onQuantityInput(index: number) {
    const item = form.brandItems[index];
    const digits = digitsOnly(item.quantity);
    if (digits !== item.quantity) {
        item.quantity = digits;
    }
    clearBrandError(index, 'quantity');
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

async function submit() {
    if (form.processing) {
        return;
    }

    // Every contact field is checked (and its message shown) before anything is sent.
    const contactOk = (['company', 'contactName', 'email', 'phone'] as ContactKey[]).map((key) => validateContact(key)).every(Boolean);
    const rowsOk = checkBrandRows();
    if (!contactOk || !rowsOk) {
        await nextTick();
        formElement.value?.querySelector<HTMLElement>('[aria-invalid="true"]')?.focus();
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
            // Only rows left completely blank are dropped. A row without a brand is kept: with a product list attached the
            // server accepts it, and without one the server refuses it (the form already checked).
            brands: data.brandItems
                .filter((item) => !rowIsBlank(item))
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
                // The message is at the top of the card and the page keeps its scroll position, so bring it into view
                // explicitly (after the layout has settled) rather than relying on focus alone.
                requestAnimationFrame(() => {
                    successMessage.value?.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    successMessage.value?.focus({ preventScroll: true });
                });
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
                    novalidate
                    @submit.prevent="submit"
                >
                    <!-- Client Details with Floating Labels -->
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div v-for="field in fields.filter((f) => f.key !== 'phone')" :key="field.key">
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
                                    @input="onContactInput(field.key)"
                                    @blur="validateContact(field.key)"
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

                        <!-- Contact number: the country code is chosen from the list, the number is digits only -->
                        <div>
                            <div class="flex gap-2">
                                <select
                                    id="phoneCountry"
                                    v-model="form.phoneCountry"
                                    name="phoneCountry"
                                    aria-label="Country code"
                                    class="field-control w-[6.5rem] shrink-0 px-2.5 text-xs sm:text-sm"
                                    :class="form.errors.phoneCountry ? 'border-red-500' : ''"
                                    :title="phoneCountryInfo?.name"
                                    @change="validateContact('phone')"
                                >
                                    <option v-for="c in phoneRules.countries" :key="c.code" :value="c.code" :title="c.name">{{ c.iso }} {{ c.code }}</option>
                                </select>
                                <div class="relative min-w-0 flex-1">
                                    <input
                                        id="phone"
                                        v-model="form.phone"
                                        name="phone"
                                        type="tel"
                                        inputmode="numeric"
                                        autocomplete="tel-national"
                                        :maxlength="phoneCountryInfo?.max ?? 12"
                                        placeholder=" "
                                        required
                                        class="peer block w-full rounded-xl border border-gray-200 bg-gray-50/50 px-3.5 pt-5 pb-2 text-xs text-gray-900 focus:border-[#4b5320] focus:bg-white focus:ring-1 focus:ring-[#4b5320] focus:outline-none sm:text-sm"
                                        :class="form.errors.phone ? 'border-red-500 focus:border-red-500 focus:ring-red-500' : ''"
                                        :aria-invalid="!!form.errors.phone"
                                        :aria-describedby="form.errors.phone ? 'phone-error' : undefined"
                                        @keydown="digitsKeydown"
                                        @input="onPhoneInput"
                                        @blur="validateContact('phone')"
                                    />
                                    <label
                                        for="phone"
                                        class="pointer-events-none absolute top-3.5 left-3.5 origin-[0] -translate-y-2.5 scale-75 transform text-xs font-semibold text-gray-500 duration-200 peer-placeholder-shown:translate-y-0 peer-placeholder-shown:scale-100 peer-focus:-translate-y-2.5 peer-focus:scale-75 peer-focus:text-[#4b5320]"
                                        :class="form.phone ? '-translate-y-2.5 scale-75 text-[#4b5320]' : ''"
                                    >
                                        Phone number
                                    </label>
                                </div>
                            </div>
                            <p v-if="form.errors.phone || form.errors.phoneCountry" id="phone-error" class="mt-1.5 text-xs text-red-600">
                                {{ form.errors.phone || form.errors.phoneCountry }}
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
                                            :class="brandRowErrors[index]?.brand ? 'border-red-500' : ''"
                                            :aria-invalid="!!brandRowErrors[index]?.brand"
                                            :aria-describedby="brandRowErrors[index]?.brand ? `brand-select-${index}-error` : undefined"
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
                                        <p v-if="brandRowErrors[index]?.brand" :id="`brand-select-${index}-error`" class="mt-1.5 text-xs text-red-600">
                                            {{ brandRowErrors[index]?.brand }}
                                        </p>
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
                                                    @blur="checkBrandField(index, 'brandName')"
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
                                                    :required="!hasProductList"
                                                    class="peer block w-full resize-y rounded-xl border bg-gray-50/50 px-3.5 pt-5 pb-2 text-xs text-gray-900 focus:bg-white focus:ring-1 focus:outline-none sm:text-sm"
                                                    :class="brandRowErrors[index]?.details ? 'border-red-500 focus:border-red-500 focus:ring-red-500' : 'border-gray-200 focus:border-[#4b5320] focus:ring-[#4b5320]'"
                                                    :aria-invalid="!!brandRowErrors[index]?.details"
                                                    :aria-describedby="brandRowErrors[index]?.details ? `brand-details-${index}-error` : undefined"
                                                    @input="clearBrandError(index, 'details')"
                                                    @blur="checkBrandField(index, 'details')"
                                                ></textarea>
                                                <label
                                                    :for="`brand-details-${index}`"
                                                    class="pointer-events-none absolute top-3.5 left-3.5 origin-[0] -translate-y-2.5 scale-75 transform text-xs font-semibold text-gray-500 duration-200 peer-placeholder-shown:translate-y-0 peer-placeholder-shown:scale-100 peer-focus:-translate-y-2.5 peer-focus:scale-75 peer-focus:text-[#4b5320]"
                                                    :class="floatingLabelClass(!!item.details)"
                                                >
                                                    Description<span v-if="!hasProductList || item.brand === OTHERS" class="text-red-600"> *</span>
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
                                                    inputmode="numeric"
                                                    maxlength="7"
                                                    placeholder=" "
                                                    class="peer block w-full rounded-xl border bg-gray-50/50 px-3.5 pt-5 pb-2 text-xs text-gray-900 focus:bg-white focus:ring-1 focus:outline-none sm:text-sm"
                                                    :class="brandRowErrors[index]?.quantity ? 'border-red-500 focus:border-red-500 focus:ring-red-500' : 'border-gray-200 focus:border-[#4b5320] focus:ring-[#4b5320]'"
                                                    :aria-invalid="!!brandRowErrors[index]?.quantity"
                                                    :aria-describedby="brandRowErrors[index]?.quantity ? `brand-quantity-${index}-error` : undefined"
                                                    @keydown="digitsKeydown"
                                                    @input="onQuantityInput(index)"
                                                    @blur="checkBrandField(index, 'quantity')"
                                                />
                                                <label
                                                    :for="`brand-quantity-${index}`"
                                                    class="pointer-events-none absolute top-3.5 left-3.5 origin-[0] -translate-y-2.5 scale-75 transform text-xs font-semibold text-gray-500 duration-200 peer-placeholder-shown:translate-y-0 peer-placeholder-shown:scale-100 peer-focus:-translate-y-2.5 peer-focus:scale-75 peer-focus:text-[#4b5320]"
                                                    :class="floatingLabelClass(!!item.quantity)"
                                                >
                                                    Quantity<span v-if="!hasProductList" class="text-red-600"> *</span>
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
                                <p class="mt-1 text-[11px] text-gray-400">PDF, Word, Excel, PowerPoint, text, CSV or pictures such as JPG, PNG, TIFF, WEBP, HEIC (handwritten or printed product lists) · Up to {{ MAX_PRODUCT_FILES }} files, {{ MAX_PRODUCT_FILE_MB }} MB each</p>
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
