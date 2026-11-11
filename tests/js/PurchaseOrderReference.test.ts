import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { reactive } from 'vue';

/**
 * Procurement → Purchase Orders as a read-only reference of supplier documents: no Promised
 * Delivery / Request / Status columns and no "Product & date" action; the Open view shows the nine
 * PO fields for every line, has no order-date input, and offers a reasoned correction to admins only.
 */

const { patches, role } = vi.hoisted(() => ({ patches: [] as { url: string; data: any }[], role: { value: 'staff_procurement' } }));
vi.mock('@inertiajs/vue3', () => ({
    Head: { render: () => null },
    router: {
        get: vi.fn(), post: vi.fn(), put: vi.fn(), delete: vi.fn(), reload: vi.fn(), visit: vi.fn(), on: vi.fn(() => () => {}),
        patch: vi.fn((url: string, data: any) => patches.push({ url, data })),
    },
    usePage: () => ({ props: { currentTeam: { slug: 'mr-pride' }, auth: { user: { role: role.value }, can_delete: false }, errors: {} } }),
    useForm: (initial: Record<string, any>) => reactive({ ...initial, errors: {}, processing: false, clearErrors() {}, reset() {}, transform() { return this; }, post() {}, patch() {} }),
}));
vi.mock('@/composables/useFlashToast', () => ({ showToast: vi.fn() }));
vi.mock('@/composables/useCanDelete', () => ({ useCanDelete: () => false }));
vi.mock('@/composables/useCurrency', async () => {
    const { ref } = await import('vue');

    return { useCurrency: () => ({ formatMoney: (n: unknown) => `$${Number(n).toFixed(2)}`, conversionNote: ref(null), displayCurrency: ref('USD') }) };
});

import ProcurementIndex from '@/pages/Procurement/Index.vue';
import PurchaseOrderWorkspace from '@/components/procurement/PurchaseOrderWorkspace.vue';

const PO = {
    id: 11, po_number: '4000005769', po_date: '2026-11-02', quantity: 2, item_sku: '3P50A', description: 'MCB 3P, 50A IC60N SCHNEIDER', unit_cost: 62, total_cost: 124,
    unit_of_measure: 'PC', status: 'Sent to Supplier', supplier_id: 3, supplier: { id: 3, name: 'Surangel and Sons' }, product_id: null, promised_delivery_date: null, purchase_request: null,
};

let wrapper: VueWrapper;
afterEach(() => {
    wrapper?.unmount();
    document.body.innerHTML = '';
    vi.unstubAllGlobals();
});

describe('Purchase order list', () => {
    it('shows the nine PO fields and only the Open action', () => {
        wrapper = mount(ProcurementIndex, {
            props: {
                requests: { data: [], current_page: 1, last_page: 1, total: 0, links: [] },
                purchaseOrders: { data: [PO], current_page: 1, last_page: 1, total: 1, links: [] },
                activeTab: 'purchase_orders', filters: { tab: 'purchase_orders' },
                metrics: { totalRequests: 0, requestedCount: 0, orderedCount: 0, deliveredCount: 0 },
                products: [], unitOptions: [], suppliers: [], isAdmin: true, statusOptions: { requests: [], purchase_orders: [] },
            },
            global: { stubs: { PurchaseOrderWorkspace: true, PurchaseOrderDocumentImport: true } },
        });

        const headers = wrapper.findAll('table').at(0)!.findAll('thead th').map((t) => t.text());
        expect(headers).toEqual(['ORDER DATE', 'PURCHASE ORDER NO.', 'QTY ORDERED', 'SUPPLIER', 'ITEM/SKU Number', 'Description', 'UNIT COST', 'U/M', 'EXTENDED COST', 'Actions']);
        const row = wrapper.findAll('table').at(0)!.find('tbody tr');
        expect(row.findAll('td')).toHaveLength(10);
        expect(row.findAll('button').map((b) => b.text())).toEqual(['Open']);
        expect(wrapper.text()).not.toContain('Product & date');
        expect(wrapper.text()).not.toContain('Promised Delivery');
    });
});

describe('Open view', () => {
    const openWorkspace = async (isAdmin: boolean) => {
        role.value = isAdmin ? 'admin' : 'staff_procurement';
        patches.length = 0;
        vi.stubGlobal('fetch', vi.fn(async () => ({
            ok: true,
            json: async () => ({
                purchase_order: PO,
                lines: [
                    { id: 11, po_date: '2026-11-02', po_number: '4000005769', supplier: 'Surangel and Sons', quantity: 2, item_sku: '3P50A', description: 'MCB 3P, 50A IC60N SCHNEIDER', unit_cost: 62, unit_of_measure: 'PC', extended_cost: 124, product_id: null },
                    { id: 12, po_date: '2026-11-02', po_number: '4000005769', supplier: 'Surangel and Sons', quantity: 3, item_sku: '3P32A', description: 'MCB 3P, 32A IC60N SCHNEIDER', unit_cost: 29.5, unit_of_measure: 'PC', extended_cost: 88.5, product_id: null },
                ],
                summary: { ordered: 2, delivered: 0, uninspected: 0, accepted: 0, rejected: 0, posted: 0, remaining: 2 },
                deliveries: [], rejection_categories: {}, suppliers: [], resolutions: [],
            }),
        })));
        wrapper = mount(PurchaseOrderWorkspace, { attachTo: document.body, props: { poId: 11, isAdmin, products: [{ id: 5, name: 'MCB 50A', sku: 'BRK-000001' }] } });
        await flushPromises();
    };

    it('shows the purchase order as recorded, every line, read-only', async () => {
        await openWorkspace(false);
        const details = wrapper.find('[aria-labelledby="po-ws-details"]');
        expect(details.text()).toContain('ORDER DATE');
        expect(details.text()).toContain('2026-11-02');
        expect(details.text()).toContain('4000005769');
        expect(details.text()).toContain('Surangel and Sons');
        expect(details.findAll('thead th').map((t) => t.text())).toEqual(['QUANTITY', 'ITEM/SKU Number', 'Description', 'UNIT COST', 'U/M', 'EXTENDED COST']);
        expect(details.findAll('tbody tr').map((r) => r.findAll('td').map((c) => c.text()))).toEqual([
            ['2', '3P50A', 'MCB 3P, 50A IC60N SCHNEIDER', '$62.00', 'PC', '$124.00'],
            ['3', '3P32A', 'MCB 3P, 32A IC60N SCHNEIDER', '$29.50', 'PC', '$88.50'],
        ]);
        expect(details.findAll('input, select, textarea')).toHaveLength(0);
        // No order-date input and no correction for staff.
        expect(wrapper.find('#dr-date').exists()).toBe(false);
        expect(wrapper.text()).not.toContain('Correct recorded details');
    });

    it('lets an administrator correct the recorded details with a reason', async () => {
        await openWorkspace(true);
        await wrapper.findAll('button').find((b) => b.text().includes('Correct recorded details'))!.trigger('click');
        await wrapper.find('#co-date').setValue('2026-11-01');
        await wrapper.find('#co-product').setValue('5');
        await wrapper.find('#co-reason').setValue('Typed from the wrong page.');
        await wrapper.find('form').trigger('submit');

        expect(patches).toEqual([{ url: '/mr-pride/procurement/purchase-orders/11/correction', data: { po_date: '2026-11-01', product_id: 5, promised_delivery_date: null, reason: 'Typed from the wrong page.' } }]);
    });
});
