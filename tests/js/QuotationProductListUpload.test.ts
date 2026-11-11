import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { h } from 'vue';

/**
 * The "Upload product list" flow on the public quotation form (pages/Quotations/Create.vue):
 * guide → file picker → upload (checked and read on the server, never reviewed by the client) → sending.
 * Inertia, the page layout and the network are replaced by test doubles.
 */

const { posted } = vi.hoisted(() => ({ posted: [] as { url: string; data: Record<string, any> }[] }));

vi.mock('@inertiajs/vue3', async () => {
    const { reactive } = await import('vue');

    return {
        Head: { render: () => null },
        useForm: (initial: Record<string, any>) => {
            let transform = (d: Record<string, any>) => d;
            const fresh = () => Object.fromEntries(Object.entries(initial).map(([k, v]) => [k, Array.isArray(v) ? [...v] : v]));
            const form: any = reactive({
                ...fresh(),
                errors: {},
                processing: false,
                progress: null,
                transform(fn: typeof transform) {
                    transform = fn;
                    return form;
                },
                post(url: string, options?: { onSuccess?: () => void }) {
                    const data = Object.fromEntries(Object.keys(initial).map((k) => [k, form[k]]));
                    posted.push({ url, data: transform(data) });
                    options?.onSuccess?.();
                },
                reset() {
                    Object.assign(form, fresh());
                },
                clearErrors() {},
            });

            return form;
        },
    };
});
vi.mock('@/directives/wordReveal', () => ({ vWordReveal: {} }));
const { passThrough } = vi.hoisted(() => ({ passThrough: { render(this: any) { return this.$slots.default?.(); }, inheritAttrs: false } }));
vi.mock('@/layouts/PublicLayout.vue', () => ({ default: passThrough }));
vi.mock('@/components/vue-bits/SpotlightCard.vue', () => ({ default: passThrough }));
vi.mock('@/components/ui/SpecularButton.vue', () => ({
    default: { inheritAttrs: false, setup: (_: unknown, { slots, attrs }: any) => () => h('button', { ...attrs, type: 'submit' }, slots.default?.()) },
}));
vi.mock('@/routes/quotations', () => ({ store: () => ({ url: '/quotation', method: 'post' }) }));
vi.mock('@/routes/quotations/product-list', () => ({ extract: () => ({ url: '/quotation/product-list/extract', method: 'post' }) }));

import Create from '@/pages/Quotations/Create.vue';

// ------------------------------------------------------------------ helpers

const EXTRACT_OK = {
    file: { hash: 'hash-1', type: 'csv' },
    method: 'csv',
    ocr: false,
    status: 'ok',
    message: 'Some values need your review. Please check the highlighted fields.',
    notes: [],
    skipped: 0,
    sheet: null,
    choice: null,
    items: [
        { ref: 'Row 2', description: 'LED Bulb 12W', quantity: 10, quantity_raw: '10', unit: 'pcs', errors: {}, checks: {}, reliable: true, duplicate_of: null },
        {
            ref: 'Row 3',
            description: 'Electrical Tape',
            quantity: null,
            quantity_raw: '1S',
            unit: 'rolls',
            errors: { quantity: '"1S" is not a valid quantity. Please enter a number.' },
            checks: {},
            reliable: false,
            duplicate_of: null,
        },
    ],
};

const json = (body: unknown, status = 200) => Promise.resolve(new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } }));

let fetchMock: ReturnType<typeof vi.fn>;
let wrapper: VueWrapper;

beforeEach(() => {
    posted.length = 0;
    let read = 0; // each file read is a different file, unless a test says otherwise
    fetchMock = vi.fn(() => json({ ...EXTRACT_OK, file: { hash: `hash-${++read}`, type: 'csv' } }));
    vi.stubGlobal('fetch', fetchMock);
    document.head.innerHTML = '<meta name="csrf-token" content="test-token">';
    wrapper = mount(Create, { props: { brands: ['Schneider Electric'] }, attachTo: document.body });
});

afterEach(() => {
    wrapper.unmount();
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
    document.body.innerHTML = '';
});

async function choose(...files: File[]) {
    const input = wrapper.get('[data-test="product-file-input"]');
    Object.defineProperty(input.element, 'files', { value: files, configurable: true });
    await input.trigger('change');
    await flushPromises();
    await flushPromises();
}

const csv = (name = 'list.csv', content = 'Description,Qty,Unit\nLED Bulb 12W,10,pcs\n') => new File([content], name, { type: 'text/csv' });
const text = () => document.body.textContent ?? '';

async function submit() {
    await wrapper.get('form').trigger('submit');
    await flushPromises();
}

// ------------------------------------------------------------------ tests

describe('upload guide', () => {
    it('shows the guide before the file picker, and opens the picker on "Continue to Upload"', async () => {
        const click = vi.spyOn(HTMLInputElement.prototype, 'click').mockImplementation(() => {});

        // The existing upload area and file input are kept.
        expect(wrapper.find('[data-test="product-list-upload"]').exists()).toBe(true);
        expect(wrapper.get('[data-test="product-file-input"]').attributes('accept')).toBe('.pdf,.xls,.xlsx,.csv,.jpg,.jpeg,.png');
        expect(wrapper.get('[data-test="product-file-input"]').attributes()).toHaveProperty('multiple');

        await wrapper.get('[data-test="product-list-upload-button"]').trigger('click');
        await flushPromises();

        const guide = document.querySelector('[data-test="product-list-guide"]')!;
        expect(guide).not.toBeNull();
        expect(guide.getAttribute('role')).toBe('dialog');
        expect(guide.textContent).toContain('Product List Upload Guide');
        expect(guide.textContent).toContain('Please make sure your document contains the following two columns so we can accurately identify your requested products.');
        expect(guide.textContent).toContain('Description, Desc, Product, Item, Material');
        expect(guide.textContent).toContain('Quantity, Qty, Qnty, Quant');
        expect(guide.textContent).toContain('Keep product descriptions and quantities aligned.');
        expect(guide.textContent).toContain('Make sure both required headers are present.');
        // Unit is no longer a required column.
        expect(guide.textContent).not.toContain('UOM');
        expect(guide.querySelectorAll('table')[1].querySelectorAll('th').length).toBe(2);
        expect(guide.textContent).toContain('Electrical Wire 2.0mm²');
        expect(guide.textContent).toContain('Maximum 5 files, 25 MB each.');
        expect(click).not.toHaveBeenCalled();

        (document.querySelector('[data-test="product-list-guide-continue"]') as HTMLButtonElement).click();
        await flushPromises();

        expect(click).toHaveBeenCalledTimes(1);
        expect(document.querySelector('[data-test="product-list-guide"]')).toBeNull();
    });

    it('closes the guide on Cancel without opening the picker', async () => {
        const click = vi.spyOn(HTMLInputElement.prototype, 'click').mockImplementation(() => {});
        await wrapper.get('[data-test="product-list-upload-button"]').trigger('click');
        await flushPromises();

        const cancel = [...document.querySelectorAll('[data-test="product-list-guide"] button')].find((b) => b.textContent?.trim() === 'Cancel') as HTMLButtonElement;
        cancel.click();
        await flushPromises();

        expect(click).not.toHaveBeenCalled();
        expect(document.querySelector('[data-test="product-list-guide"]')).toBeNull();
    });
});

describe('uploading a product list (no client review)', () => {
    const CONFIRMATION = 'Product list uploaded successfully. Our team will review the product details when preparing your quotation.';

    it('checks the file on the server and shows only a simple confirmation, never the extracted products', async () => {
        const file = csv();
        await choose(file);

        expect(fetchMock).toHaveBeenCalledTimes(1);
        const [url, init] = fetchMock.mock.calls[0];
        expect(url).toBe('/quotation/product-list/extract');
        expect(((init.body as FormData).get('file') as File).name).toBe('list.csv');
        expect(init.headers['X-CSRF-TOKEN']).toBe('test-token');

        expect(wrapper.get('[data-test="product-list-uploaded"]').text()).toBe(CONFIRMATION);
        expect(text()).toContain('list.csv'); // the file name stays visible
        // No review interface, product rows, statuses or OCR messages.
        expect(wrapper.find('[data-test="product-list-review"]').exists()).toBe(false);
        expect(wrapper.find('[data-test="product-row"]').exists()).toBe(false);
        for (const hidden of [
            'Review the products from your list',
            'Confirm products',
            'Needs Review',
            'Unconfirmed',
            'Looks right',
            'Discard list',
            'Add row',
            'Unit in your file',
            'LED Bulb 12W',
            'Some values need your review',
            'is not a valid quantity',
        ]) {
            expect(text()).not.toContain(hidden);
        }
    });

    it('submits without any confirmation step, sending the files and no client-reviewed products', async () => {
        const file = csv();
        await choose(file);
        await submit();

        expect(posted).toHaveLength(1);
        expect(posted[0].data.productFiles).toEqual([file]);
        expect(posted[0].data).not.toHaveProperty('products');
        expect(text()).not.toContain('Please confirm the products');
    });

    it('does not show reading problems: a list whose columns were not found is still just uploaded', async () => {
        fetchMock.mockImplementationOnce(() =>
            json({
                ...EXTRACT_OK,
                status: 'needs_mapping',
                message: 'Unable to identify the required headers. Please include Description and Quantity. You can also choose the columns below.',
                items: [],
                choice: { columns: ['Thing', 'How many'], preview: [['Thing', 'How many']], header_index: null, mapping: {}, missing: ['description', 'quantity'], ambiguous: [] },
            }),
        );
        await choose(csv('odd-headers.csv'));

        expect(wrapper.get('[data-test="product-list-uploaded"]').text()).toBe(CONFIRMATION);
        expect(text()).not.toContain('Unable to identify the required headers');
        expect(wrapper.find('[data-test="product-list-columns"]').exists()).toBe(false);
    });

    it('keeps a valid file the server could not read automatically (OCR unavailable, server error)', async () => {
        fetchMock
            .mockImplementationOnce(() => json({ message: 'We cannot read scanned documents or pictures automatically right now.', reason: 'ocr_unavailable', keep_file: true }, 422))
            .mockImplementationOnce(() => json({ message: 'Unable to process this file. Please check the document and try again.', reason: 'server_error', keep_file: true }, 500));
        const scan = csv('scan.csv');
        const other = csv('other.csv');
        await choose(scan, other);

        expect(text()).not.toContain('We cannot read scanned documents');
        expect(text()).not.toContain('Unable to process this file');
        expect(wrapper.get('[data-test="product-list-uploaded"]').text()).toBe(CONFIRMATION);
        await submit();
        expect(posted[0].data.productFiles).toEqual([scan, other]);
    });

    it('keeps the file when the check cannot reach the server; it is checked again on submit', async () => {
        fetchMock.mockImplementationOnce(() => Promise.reject(new TypeError('Failed to fetch')));
        const file = csv();
        await choose(file);

        expect(text()).not.toContain('network');
        expect(wrapper.get('[data-test="product-list-uploaded"]').text()).toBe(CONFIRMATION);
        await submit();
        expect(posted[0].data.productFiles).toEqual([file]);
    });

    it('removes a file that cannot be sent (wrong type, damaged, a photo), with its message', async () => {
        fetchMock.mockImplementationOnce(() => json({ message: 'We could not find a written product list in this file.', reason: 'no_text', keep_file: false }, 422));
        await choose(new File(['x'], 'breaker-photo.jpg', { type: 'image/jpeg' }));

        expect(text()).toContain('breaker-photo.jpg: We could not find a written product list in this file.');
        expect(wrapper.find('[data-test="product-list-uploaded"]').exists()).toBe(false);
        await submit();
        expect(posted[0].data.productFiles).toEqual([]);
    });

    it('lets the client remove a file and upload another one instead', async () => {
        await choose(csv('first.csv'));
        await wrapper.get('button[aria-label="Remove first.csv"]').trigger('click');
        expect(text()).not.toContain('first.csv');
        expect(wrapper.find('[data-test="product-list-uploaded"]').exists()).toBe(false);

        const replacement = csv('replacement.csv', 'Description,Qty\nTape,4\n');
        await choose(replacement);
        await submit();
        expect(posted[0].data.productFiles).toEqual([replacement]);
    });

    it('cancels an upload that is still being checked', async () => {
        let signal: AbortSignal | undefined;
        fetchMock.mockImplementationOnce(
            (_url: string, init: RequestInit) =>
                new Promise((_resolve, reject) => {
                    signal = init.signal!;
                    signal.addEventListener('abort', () => reject(new DOMException('Aborted', 'AbortError')));
                }),
        );
        await choose(csv('slow-scan.csv'));
        expect(text()).toContain('Uploading…');

        await wrapper.get('button[aria-label="Cancel uploading slow-scan.csv"]').trigger('click');
        await flushPromises();

        expect(signal?.aborted).toBe(true);
        expect(text()).not.toContain('slow-scan.csv');
    });

    it('does not block sending while a file is still being checked', async () => {
        fetchMock.mockImplementationOnce(() => new Promise(() => {}));
        const file = csv();
        await choose(file);

        await submit();
        expect(posted).toHaveLength(1);
        expect(posted[0].data.productFiles).toEqual([file]);
    });

    it('shows the 25 MB limit under the upload button', () => {
        expect(wrapper.get('[data-test="product-list-upload"]').text()).toContain(
            'PDF, Excel, CSV, JPG or PNG (handwritten or printed product lists) · Up to 5 files, 25 MB each',
        );
    });

    it('rejects files over 25 MB before sending them, and accepts files up to 25 MB', async () => {
        const sized = (name: string, bytes: number) => {
            const file = new File(['Description,Qty\nLED,1\n'], name, { type: 'text/csv' });
            Object.defineProperty(file, 'size', { value: bytes });
            return file;
        };

        await choose(sized('too-big.pdf', 25 * 1024 * 1024 + 1));
        expect(text()).toContain('too-big.pdf (25.0 MB): File size exceeds the 25 MB limit. Please upload a smaller file.');
        expect(fetchMock).not.toHaveBeenCalled();

        await choose(sized('scan-1.pdf', 25 * 1024 * 1024), sized('scan-2.pdf', 24 * 1024 * 1024), sized('scan-3.pdf', 12 * 1024 * 1024));
        expect(text()).not.toContain('File size exceeds');
        expect(fetchMock).toHaveBeenCalledTimes(3);
    });

    it('shows the 25 MB message when the server refuses a file as too large', async () => {
        fetchMock.mockImplementationOnce(() => Promise.resolve(new Response('', { status: 413 })));
        await choose(csv('huge.csv'));

        expect(text()).toContain('huge.csv: File size exceeds the 25 MB limit. Please upload a smaller file.');
    });

    it('rejects more than five files', async () => {
        await choose(...[1, 2, 3, 4, 5, 6].map((i) => csv(`list-${i}.csv`, `Description,Qty\nItem ${i},1\n`)));
        expect(text()).toContain('You can attach up to 5 files.');
        expect(fetchMock).toHaveBeenCalledTimes(5);
    });

    it('keeps the same file only once, even under another name', async () => {
        fetchMock.mockImplementation(() => json(EXTRACT_OK)); // the server reports the same content hash both times
        await choose(csv('list.csv'));
        await choose(csv('list-copy.csv'));

        expect(text()).toContain('list-copy.csv is the same file as list.csv, so it was not added again.');
        await submit();
        expect(posted[0].data.productFiles.map((f: File) => f.name)).toEqual(['list.csv']);
    });
});

describe('brand rows', () => {
    const labels = (index: number) =>
        wrapper.get(`[data-test="brand-fields-${index}"]`).findAll('label').map((l) => l.text().replace(/\s+/g, ' ').trim());

    it('shows Description and Quantity for a listed brand, and Brand Name, Description and Quantity for Others', async () => {
        await wrapper.get('select[aria-label="Brand 1"]').setValue('Schneider Electric');
        expect(labels(0)).toEqual(['Description', 'Quantity']);

        // Floating labels, like the contact fields: an empty placeholder and a label that rises.
        for (const field of wrapper.get('[data-test="brand-fields-0"]').findAll('input, textarea')) {
            expect(field.attributes('placeholder')).toBe(' ');
            expect(field.classes()).toContain('peer');
        }
        expect(wrapper.get('label[for="brand-quantity-0"]').classes()).toContain('peer-placeholder-shown:translate-y-0');

        await wrapper.get('select[aria-label="Brand 1"]').setValue('Others');
        expect(labels(0)).toEqual(['Brand Name *', 'Description *', 'Quantity']);

        // No Unit field anywhere on the client form.
        expect(wrapper.text()).not.toMatch(/\bUnit\b/);
    });

    it('sends each brand row with its description and quantity, and the brand name for Others', async () => {
        await wrapper.get('select[aria-label="Brand 1"]').setValue('Schneider Electric');
        await wrapper.get('#brand-details-0').setValue('Miniature circuit breaker 20A');
        await wrapper.get('#brand-quantity-0').setValue('50');
        await wrapper.findAll('button').find((b) => b.text() === 'Add Another Brand')!.trigger('click');
        await wrapper.get('select[aria-label="Brand 2"]').setValue('Others');
        await wrapper.get('#brand-name-1').setValue('Omron');
        await wrapper.get('#brand-details-1').setValue('Relay 24V');
        await wrapper.get('#brand-quantity-1').setValue('2.5');

        await submit();

        expect(posted[0].data.brands).toEqual([
            { brand: 'Schneider Electric', brand_name: '', details: 'Miniature circuit breaker 20A', quantity: '50' },
            { brand: 'Others', brand_name: 'Omron', details: 'Relay 24V', quantity: '2.5' },
        ]);
    });

    it('asks for the brand name and description of an Others row, and a valid quantity', async () => {
        await wrapper.get('select[aria-label="Brand 1"]').setValue('Others');
        await wrapper.get('#brand-quantity-0').setValue('ten');

        await submit();

        expect(posted).toHaveLength(0);
        expect(text()).toContain('Please enter the brand name.');
        expect(text()).toContain('Please describe the product you need.');
        expect(text()).toContain('Quantity must be a number.');
        expect(wrapper.get('#brand-name-0').attributes('aria-invalid')).toBe('true');
        expect(wrapper.get('#brand-quantity-0').attributes('aria-invalid')).toBe('true');

        // Fixing a field clears its message.
        await wrapper.get('#brand-name-0').setValue('Omron');
        expect(text()).not.toContain('Please enter the brand name.');
    });

    it('has no separate "brands not listed" checkbox: Others in the dropdown is the only way', async () => {
        expect(wrapper.find('[data-test="unlisted-brands"]').exists()).toBe(false);
        expect(wrapper.find('#unlisted-brands-field').exists()).toBe(false);
        expect(wrapper.find('input[type="checkbox"]').exists()).toBe(false);
        expect(wrapper.text()).not.toContain("I'm looking for brands not listed");

        const options = wrapper.get('select[aria-label="Brand 1"]').findAll('option').map((o) => o.text());
        expect(options).toEqual(['Select a Brand', 'Schneider Electric', 'Others – Brand Not Listed']);
    });

    it('switches between a listed brand and Others, keeping what was typed and showing one Brand Name field', async () => {
        const select = wrapper.get('select[aria-label="Brand 1"]');
        await select.setValue('Schneider Electric');
        await wrapper.get('#brand-details-0').setValue('Relay 24V');
        await wrapper.get('#brand-quantity-0').setValue('4');

        await select.setValue('Others');
        await flushPromises();
        expect(wrapper.findAll('#brand-name-0')).toHaveLength(1);
        expect(document.activeElement?.id).toBe('brand-name-0'); // the cursor moves to Brand Name
        expect((wrapper.get('#brand-details-0').element as HTMLTextAreaElement).value).toBe('Relay 24V');
        expect((wrapper.get('#brand-quantity-0').element as HTMLInputElement).value).toBe('4');

        // Back to a listed brand: Brand Name disappears and is not sent, the product stays.
        await wrapper.get('#brand-name-0').setValue('Omron');
        await select.setValue('Schneider Electric');
        expect(wrapper.find('#brand-name-0').exists()).toBe(false);
        expect(labels(0)).toEqual(['Description', 'Quantity']);

        await submit();
        expect(posted[0].data.brands).toEqual([{ brand: 'Schneider Electric', brand_name: '', details: 'Relay 24V', quantity: '4' }]);
        // Nothing left of the removed checkbox is sent.
        expect(posted[0].data).not.toHaveProperty('hasUnlistedBrands');
        expect(posted[0].data).not.toHaveProperty('unlistedBrands');
    });

    it('requires Brand Name only for Others rows', async () => {
        await wrapper.get('select[aria-label="Brand 1"]').setValue('Schneider Electric');
        await wrapper.findAll('button').find((b) => b.text() === 'Add Another Brand')!.trigger('click');
        await wrapper.get('select[aria-label="Brand 2"]').setValue('Others');
        await wrapper.get('#brand-details-1').setValue('Floodlight 200W');

        await submit();
        expect(posted).toHaveLength(0);
        expect(wrapper.findAll('input[id^="brand-name-"]').map((e) => e.attributes('id'))).toEqual(['brand-name-1']);
        expect(wrapper.find('#brand-name-1-error').text()).toBe('Please enter the brand name.');

        await wrapper.get('#brand-name-1').setValue('Philips');
        await submit();
        expect(posted[0].data.brands).toEqual([
            { brand: 'Schneider Electric', brand_name: '', details: '', quantity: '' },
            { brand: 'Others', brand_name: 'Philips', details: 'Floodlight 200W', quantity: '' },
        ]);
    });

    it('accepts a listed brand with no description or quantity, as before', async () => {
        await wrapper.get('select[aria-label="Brand 1"]').setValue('Schneider Electric');
        await submit();

        expect(posted[0].data.brands).toEqual([{ brand: 'Schneider Electric', brand_name: '', details: '', quantity: '' }]);
    });
});
