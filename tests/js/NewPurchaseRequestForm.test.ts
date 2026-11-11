import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { reactive } from 'vue';

/**
 * "New Purchase Request" (pages/Procurement/Index.vue): a catalog product needs only the quantity
 * (its unit is shown read-only); "Product Not Listed" (last option) adds Product Description and Unit.
 * Floating labels, nothing preselected, validated before Continue, then sent to the existing endpoint.
 */

const { sent } = vi.hoisted(() => ({ sent: [] as { url: string; data: any }[] }));
vi.mock('@inertiajs/vue3', () => ({
    Head: { render: () => null },
    router: { get: vi.fn(), post: vi.fn(), put: vi.fn(), patch: vi.fn(), delete: vi.fn(), reload: vi.fn(), visit: vi.fn(), on: vi.fn(() => () => {}) },
    usePage: () => ({ props: { currentTeam: { slug: 'mr-pride' }, auth: { user: { role: 'staff_procurement' }, can_delete: false } } }),
    useForm: (initial: Record<string, any>) => {
        const form: any = reactive({
            ...JSON.parse(JSON.stringify(initial)),
            errors: {},
            processing: false,
            reset() { Object.assign(form, JSON.parse(JSON.stringify(initial))); },
            clearErrors() { form.errors = {}; },
            setError(key: string, message: string) { form.errors = { ...form.errors, [key]: message }; },
            transform() { return form; },
            post(url: string, options: any) {
                const { errors, processing, ...data } = form;
                void errors; void processing;
                sent.push({ url, data: JSON.parse(JSON.stringify(data)) });
                options?.onSuccess?.();
            },
        });
        return form;
    },
}));
vi.mock('@/composables/useFlashToast', () => ({ showToast: vi.fn() }));
vi.mock('@/composables/useCanDelete', () => ({ useCanDelete: () => false }));
vi.mock('@/composables/useCurrency', async () => {
    const { ref } = await import('vue');

    return { useCurrency: () => ({ formatMoney: (n: unknown) => String(n), conversionNote: ref(null), displayCurrency: ref('USD') }) };
});

import ProcurementIndex from '@/pages/Procurement/Index.vue';


const PRODUCTS = [
    { id: 1, name: 'Circuit Breaker 40A', sku: 'BRK-000001', unit: 'pcs', cost_price: 10, price: 20, stock: 5, reorder_level: 10 },
    { id: 2, name: 'THHN Wire 12 AWG', sku: 'WIR-000001', unit: 'roll', cost_price: 40, price: 60, stock: 2, reorder_level: 5 },
    { id: 3, name: 'Cable Gland PG9', sku: 'GEN-000009', unit: null, cost_price: 1, price: 2, stock: 0, reorder_level: 5 },
];

let wrapper: VueWrapper;

beforeEach(async () => {
    sent.length = 0;
    wrapper = mount(ProcurementIndex, {
        attachTo: document.body,
        props: {
            requests: { data: [], current_page: 1, last_page: 1, total: 0, links: [] },
            purchaseOrders: { data: [], current_page: 1, last_page: 1, total: 0, links: [] },
            activeTab: 'requests',
            filters: { tab: 'requests' },
            metrics: { totalRequests: 0, requestedCount: 0, orderedCount: 0, deliveredCount: 0 },
            products: PRODUCTS,
            unitOptions: ['box', 'm', 'pcs', 'roll', 'set'],
            suppliers: [],
            isAdmin: false,
            statusOptions: { requests: [], purchase_orders: [] },
        },
        global: { stubs: { PurchaseOrderWorkspace: true, PurchaseOrderDocumentImport: true } },
    });
    const open = wrapper.findAll('button').find((b) => /new purchase request/i.test(b.text()));
    await open!.trigger('click');
    await flushPromises();
});

afterEach(() => {
    wrapper.unmount();
    document.body.innerHTML = '';
});

const label = (id: string) => document.querySelector<HTMLLabelElement>(`label[for="${id}"]`)!;
const floated = (id: string) => label(id).className.includes('-translate-y-2.5 scale-75 text-[#4b5320]');
const continueButton = () => wrapper.findAll('button').find((b) => b.text() === 'Continue')!;
const unitText = () => document.querySelector('#pick-unit')?.textContent?.replace('Unit:', '').trim() ?? null;
const fieldIds = () => Array.from(document.querySelectorAll('[role="dialog"] form select, [role="dialog"] form textarea, [role="dialog"] form input')).map((el) => el.id);
const submitCreator = async (justification: string) => {
    await wrapper.find('#pr-j-0').setValue(justification);
    await wrapper.findAll('button').find((b) => b.text() === 'Submit for approval')!.trigger('click');
    await flushPromises();
};

describe('New Purchase Request form', () => {
    it('lists every product with "Product Not Listed" last, nothing preselected', () => {
        const product = wrapper.find<HTMLSelectElement>('#pick-product');
        expect(product.element.selectedIndex).toBe(0); // "Select Product", not the first product
        expect(product.findAll('option').map((o) => o.text())).toEqual([
            'Select Product', 'Circuit Breaker 40A (BRK-000001) · stock 5', 'THHN Wire 12 AWG (WIR-000001) · stock 2', 'Cable Gland PG9 (GEN-000009) · stock 0', 'Product Not Listed',
        ]);
        expect(label('pick-product').textContent?.trim()).toBe('Select Product');
        expect(floated('pick-product')).toBe(false);
        expect(fieldIds()).toEqual(['pick-product', 'pick-quantity']);

        // The tip, Cancel and Continue stay.
        expect(document.body.textContent).toContain('AHP Product Prioritization lists the products');
        expect(wrapper.findAll('button').some((b) => b.text() === 'Cancel')).toBe(true);
    });

    it('a catalog product shows only the quantity, with the product\'s unit beside it', async () => {
        await wrapper.find('#pick-product').setValue('2');
        expect(fieldIds()).toEqual(['pick-product', 'pick-quantity']); // no description, no unit dropdown
        expect(unitText()).toBe('roll');
        expect(document.querySelector('#pick-unit')!.tagName).toBe('SPAN'); // read-only text

        await wrapper.find('#pick-quantity').setValue('10');
        expect(['pick-product', 'pick-quantity'].every(floated)).toBe(true);
        expect(label('pick-quantity').textContent?.trim()).toBe('Required Quantity');
    });

    it('"Product Not Listed" shows description, quantity and unit, with floating labels', async () => {
        await wrapper.find('#pick-product').setValue('unlisted');
        expect(fieldIds()).toEqual(['pick-product', 'pick-description', 'pick-quantity', 'pick-unit-choice']);
        expect(unitText()).toBeNull();
        expect(label('pick-description').textContent?.trim()).toBe('Product Description');
        expect(label('pick-unit-choice').textContent?.trim()).toBe('Select Unit');
        expect(wrapper.find<HTMLSelectElement>('#pick-unit-choice').findAll('option').map((o) => o.text())).toEqual(['Select Unit', 'box', 'm', 'pcs', 'roll', 'set']);

        await wrapper.find('#pick-description').setValue('PVC junction box 6x6');
        await wrapper.find('#pick-quantity').setValue('4');
        await wrapper.find('#pick-unit-choice').setValue('pcs');
        expect(['pick-product', 'pick-description', 'pick-quantity', 'pick-unit-choice'].every(floated)).toBe(true);
        expect(label('pick-unit-choice').textContent?.trim()).toBe('Unit');
    });

    it('switching clears the description and unit; going back to a product hides them', async () => {
        await wrapper.find('#pick-product').setValue('unlisted');
        await wrapper.find('#pick-description').setValue('PVC junction box 6x6');
        await wrapper.find('#pick-unit-choice').setValue('box');
        await wrapper.find('#pick-quantity').setValue('4');

        await wrapper.find('#pick-product').setValue('1');
        expect(fieldIds()).toEqual(['pick-product', 'pick-quantity']);
        expect(unitText()).toBe('pcs');
        expect(wrapper.find<HTMLInputElement>('#pick-quantity').element.value).toBe('4'); // still applies

        await wrapper.find('#pick-product').setValue('unlisted');
        expect(wrapper.find<HTMLTextAreaElement>('#pick-description').element.value).toBe('');
        expect(wrapper.find<HTMLSelectElement>('#pick-unit-choice').element.selectedIndex).toBe(0);
    });

    it('validates the fields of each kind before continuing', async () => {
        await continueButton().trigger('click');
        await flushPromises();
        expect(document.querySelector('#pick-product-error')?.textContent).toBe('Select a product.');
        expect(document.querySelector('#pick-quantity-error')?.textContent).toBe('Enter a whole number of at least 1.');

        await wrapper.find('#pick-product').setValue('unlisted');
        await wrapper.find('#pick-description').setValue('   ');
        for (const bad of ['0', '-2', '1.5']) {
            await wrapper.find('#pick-quantity').setValue(bad);
            await continueButton().trigger('click');
            await flushPromises();
            expect(document.querySelector('#pick-quantity-error')?.textContent).toBe('Enter a whole number of at least 1.');
        }
        expect(document.querySelector('#pick-description-error')?.textContent).toBe('Describe the product you need to purchase.');
        expect(document.querySelector('#pick-unit-error')?.textContent).toBe('Select the unit.');
        expect(document.querySelector('#pick-product-error')).toBeNull();
        expect(document.querySelector('#pr-create-title')).toBeNull(); // did not continue
    });

    it('refuses a catalog product without a unit instead of assuming one', async () => {
        await wrapper.find('#pick-product').setValue('3');
        expect(unitText()).toBeNull();
        expect(document.querySelector('#pick-product-error')?.textContent).toBe('Cable Gland PG9 has no unit of measure in the Product Catalog. Add its unit in Inventory first.');
        await wrapper.find('#pick-quantity').setValue('5');
        await continueButton().trigger('click');
        await flushPromises();
        expect(document.querySelector('#pr-create-title')).toBeNull();
    });

    it('sends a catalog product with its id and quantity only', async () => {
        await wrapper.find('#pick-product').setValue('2');
        await wrapper.find('#pick-quantity').setValue('3');
        await continueButton().trigger('click');
        await flushPromises();

        expect(document.querySelector('#pr-create-title')).not.toBeNull();
        expect(document.querySelector('#pr-d-0')).toBeNull(); // no description for a catalog product
        expect(wrapper.find<HTMLInputElement>('#pr-q-0').element.value).toBe('3');
        expect(label('pr-q-0').textContent).toContain('(roll)');

        await submitCreator('Restock for the Ortiz project.');
        expect(sent).toHaveLength(1);
        expect(sent[0].url).toBe('/mr-pride/procurement/requests');
        expect(sent[0].data.lines).toEqual([{ product_id: 2, quantity: 3, product_description: null, justification: 'Restock for the Ortiz project.' }]);
    });

    it('sends "Product Not Listed" without a product id, with its description and unit', async () => {
        await wrapper.find('#pick-product').setValue('unlisted');
        await wrapper.find('#pick-description').setValue('PVC junction box 6x6, weatherproof');
        await wrapper.find('#pick-quantity').setValue('8');
        await wrapper.find('#pick-unit-choice').setValue('box');
        await continueButton().trigger('click');
        await flushPromises();

        expect(document.body.textContent).toContain('Product not listed');
        expect(wrapper.find<HTMLTextAreaElement>('#pr-d-0').element.value).toBe('PVC junction box 6x6, weatherproof');
        expect(label('pr-q-0').textContent).toContain('(box)');

        await submitCreator('Site needs these this week.');
        expect(sent).toHaveLength(1);
        expect(sent[0].data.lines).toEqual([
            { product_id: null, quantity: 8, product_description: 'PVC junction box 6x6, weatherproof', unit: 'box', justification: 'Site needs these this week.' },
        ]);
    });

    it('does not submit an unlisted product whose description is cleared in the next step', async () => {
        await wrapper.find('#pick-product').setValue('unlisted');
        await wrapper.find('#pick-description').setValue('Gland');
        await wrapper.find('#pick-quantity').setValue('1');
        await wrapper.find('#pick-unit-choice').setValue('pcs');
        await continueButton().trigger('click');
        await flushPromises();

        await wrapper.find('#pr-d-0').setValue('');
        await submitCreator('Low stock.');
        expect(sent).toHaveLength(0);
        expect(document.body.textContent).toContain('Describe the product you need to purchase.');
    });
});
