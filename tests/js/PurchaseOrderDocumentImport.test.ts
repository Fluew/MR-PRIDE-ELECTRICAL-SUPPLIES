import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

/**
 * The purchase order document preview (components/procurement/PurchaseOrderDocumentImport.vue):
 * values read from the document are shown for checking and correcting; EXTENDED COST is always
 * QUANTITY × UNIT COST; nothing is saved until "Save Purchase Order".
 */

const { posts } = vi.hoisted(() => ({ posts: [] as { url: string; data: any }[] }));
vi.mock('@inertiajs/vue3', () => ({
    router: {
        post: (url: string, data: any, options: any) => {
            posts.push({ url, data });
            options?.onSuccess?.({ props: { errors: {} } });
            options?.onFinish?.();
        },
    },
}));

import PurchaseOrderDocumentImport from '@/components/procurement/PurchaseOrderDocumentImport.vue';

const READ = {
    success: true,
    method: 'ocr',
    ocr: true,
    existing: null,
    document_total: 2850.79,
    calculated_total: 2850.79,
    notes: ['Read with text recognition (OCR): compare every value with the document before saving.'],
    header: {
        po_number: { value: '4000005769', issue: null },
        po_date: { value: '2023-01-13', original: '1/13/23', issue: 'The year is written as "23" and was read as 2023. Check the date.' },
        supplier: { name: 'SURANGEL & SONS CONSTRUCTION', supplier_id: null, matched_name: null, issue: '"SURANGEL & SONS CONSTRUCTION" is not in the supplier list. Choose a supplier, or add it as a new supplier.' },
    },
    rows: [
        { quantity: 2, item_sku: '3P50A', description: 'MCB 3P, 50A IC60N SCHNEIDER', unit_cost: 62, unit_of_measure: 'PC', document_extended_cost: 124, calculated_cost: 124, issues: {} },
        { quantity: 3, item_sku: '3P32A', description: 'MCB 3P, 32A IC60N SCHNEIDER', unit_cost: 29.5, unit_of_measure: 'PC', document_extended_cost: 85.5, calculated_cost: 88.5, issues: { extended_cost: 'The document shows 85.50, but QTY ORDERED × UNIT COST = 88.50.' } },
        { quantity: 1, item_sku: '0NEMA1', description: 'PANEL BOARD NEMA 1', unit_cost: 2200, unit_of_measure: '', document_extended_cost: 2200, calculated_cost: 2200, issues: { unit_of_measure: 'U/M was not found. Enter it (PCS, BOX, SET, …).' } },
    ],
};

let wrapper: VueWrapper;
let fetchMock: ReturnType<typeof vi.fn>;

async function mountWith(response: unknown, status = 200) {
    fetchMock = vi.fn(() => Promise.resolve(new Response(JSON.stringify(response), { status, headers: { 'Content-Type': 'application/json' } })));
    vi.stubGlobal('fetch', fetchMock);
    wrapper = mount(PurchaseOrderDocumentImport, {
        props: { file: new File(['x'], 'surangel-po.png', { type: 'image/png' }), teamSlug: 'mr-pride', suppliers: [{ id: 4, name: 'Volt Supply' }] },
        attachTo: document.body,
    });
    await flushPromises();
    await flushPromises();
}

beforeEach(() => {
    posts.length = 0;
});
afterEach(() => {
    wrapper?.unmount();
    vi.unstubAllGlobals();
    document.body.innerHTML = '';
});

const input = (label: string) => wrapper.get(`input[aria-label="${label}"]`);
const extended = () => wrapper.findAll('[data-test="po-doc-extended"]').map((e) => e.text());

describe('purchase order document preview', () => {
    it('sends the document to be read and shows the nine fields for checking', async () => {
        await mountWith(READ);

        expect(fetchMock.mock.calls[0][0]).toBe('/mr-pride/procurement/purchase-orders/read-document');
        expect((wrapper.get('#po_doc_number').element as HTMLInputElement).value).toBe('4000005769');
        expect((wrapper.get('#po_doc_date').element as HTMLInputElement).value).toBe('2023-01-13');
        expect(wrapper.text()).toContain('On the document: 1/13/23');
        expect(wrapper.text()).toContain('read as 2023');
        // A new supplier name is offered, and preselected, as "Add new supplier".
        expect((wrapper.get('#po_doc_supplier').element as HTMLSelectElement).selectedOptions[0].text.trim()).toBe('Add new supplier: SURANGEL & SONS CONSTRUCTION');
        expect(wrapper.findAll('thead th').map((t) => t.text()).slice(0, 6)).toEqual(['QTY ORDERED', 'ITEM/SKU Number', 'Description', 'UNIT COST', 'U/M', 'EXTENDED COST']);
        expect(wrapper.findAll('[data-test="po-doc-row"]')).toHaveLength(3);
        expect((input('ITEM/SKU Number, product 3').element as HTMLInputElement).value).toBe('0NEMA1'); // leading zero kept
        expect(extended()).toEqual(['124.00', '88.50', '2,200.00']);
        expect(wrapper.get('[data-test="po-doc-total"]').text()).toBe('2,412.50');
    });

    it('flags a document EXTENDED COST that differs, and recalculates when QTY ORDERED or UNIT COST changes', async () => {
        await mountWith(READ);
        expect(wrapper.text()).toContain('The document shows 85.50; QTY ORDERED × UNIT COST = 88.50.');

        await input('QTY ORDERED, product 1').setValue('5');
        await input('UNIT COST, product 1').setValue('60.50');
        expect(extended()[0]).toBe('302.50');
        expect(wrapper.text()).toContain('The document shows 124.00; QTY ORDERED × UNIT COST = 302.50.');
        expect(wrapper.get('[data-test="po-doc-total"]').text()).toBe('2,591.00');
    });

    it('lets the user add and remove product rows', async () => {
        await mountWith(READ);
        await wrapper.findAll('button').find((b) => b.text().includes('Add product'))!.trigger('click');
        expect(wrapper.findAll('[data-test="po-doc-row"]')).toHaveLength(4);

        await wrapper.get('button[aria-label="Remove product 2"]').trigger('click');
        expect(wrapper.findAll('[data-test="po-doc-row"]')).toHaveLength(3);
        expect((input('ITEM/SKU Number, product 2').element as HTMLInputElement).value).toBe('0NEMA1');
    });

    it('does not save while a required value is missing, and saves the corrected order when confirmed', async () => {
        await mountWith(READ);

        await wrapper.get('[data-test="po-doc-save"]').trigger('click');
        expect(posts).toHaveLength(0);
        expect(wrapper.text()).toContain('1 product row(s) have missing or invalid values.');
        expect(wrapper.text()).toContain('U/M was not found');

        await input('U/M, product 3').setValue('PC');
        await wrapper.get('[data-test="po-doc-save"]').trigger('click');
        await flushPromises();

        expect(posts).toHaveLength(1);
        expect(posts[0].url).toBe('/mr-pride/procurement/purchase-orders/import-document');
        expect(posts[0].data).toEqual({
            po_number: '4000005769',
            po_date: '2023-01-13',
            supplier_id: null,
            new_supplier_name: 'SURANGEL & SONS CONSTRUCTION',
            file_name: 'surangel-po.png',
            rows: [
                { quantity: 2, item_sku: '3P50A', description: 'MCB 3P, 50A IC60N SCHNEIDER', unit_cost: 62, unit_of_measure: 'PC' },
                { quantity: 3, item_sku: '3P32A', description: 'MCB 3P, 32A IC60N SCHNEIDER', unit_cost: 29.5, unit_of_measure: 'PC' },
                { quantity: 1, item_sku: '0NEMA1', description: 'PANEL BOARD NEMA 1', unit_cost: 2200, unit_of_measure: 'PC' },
            ],
        });
        expect(wrapper.emitted('saved')).toHaveLength(1);
    });

    it('uses an existing supplier when the user chooses one', async () => {
        await mountWith({ ...READ, rows: READ.rows.slice(0, 2) });
        await wrapper.get('#po_doc_supplier').setValue('4');
        await wrapper.get('[data-test="po-doc-save"]').trigger('click');

        expect(posts[0].data).toMatchObject({ supplier_id: 4, new_supplier_name: null });
    });

    it('blocks saving a purchase order number that already exists', async () => {
        await mountWith({ ...READ, existing: '4000005769', rows: READ.rows.slice(0, 2) });

        expect(wrapper.text()).toContain('Purchase order 4000005769 already exists');
        expect(wrapper.get('[data-test="po-doc-save"]').attributes()).toHaveProperty('disabled');
    });

    it('shows a clear message when the document cannot be read', async () => {
        await mountWith({ success: false, message: 'Purchase order documents must be PDF, PNG or JPG files.' }, 422);

        expect(wrapper.text()).toContain('Purchase order documents must be PDF, PNG or JPG files.');
        expect(wrapper.find('[data-test="po-doc-save"]').exists()).toBe(false);
    });
});
