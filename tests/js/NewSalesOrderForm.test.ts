import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

/**
 * Sales → "New Sales Order" (pages/Sales/Index.vue): staff pick an accepted quotation; customer,
 * quotation number, products, quantities, units, agreed prices and the total are filled in from it
 * (read-only). No product cards, cart or payment fields. Create Sales Order posts only the quotation.
 */

const { posts } = vi.hoisted(() => ({ posts: [] as { url: string; data: any }[] }));
vi.mock('@inertiajs/vue3', () => ({
    Head: { render: () => null },
    Link: { template: '<a><slot /></a>' },
    router: {
        get: vi.fn(), put: vi.fn(), patch: vi.fn(), delete: vi.fn(), reload: vi.fn(), visit: vi.fn(), on: vi.fn(() => () => {}),
        post: vi.fn((url: string, data: any, options: any) => {
            posts.push({ url, data });
            options?.onSuccess?.({ props: { errors: {} } });
            options?.onFinish?.();
        }),
    },
    usePage: () => ({ props: { currentTeam: { slug: 'mr-pride' }, auth: { user: { role: 'staff_sales' }, can_delete: false } } }),
}));
vi.mock('@/composables/useCanDelete', () => ({ useCanDelete: () => false }));
vi.mock('@/composables/useCurrency', async () => {
    const { ref } = await import('vue');

    return { useCurrency: () => ({ formatMoney: (n: unknown) => `$${Number(n).toFixed(2)}`, conversionNote: ref(null) }) };
});

import SalesIndex from '@/pages/Sales/Index.vue';

const QUOTATIONS = [
    {
        id: 7, quotation_number: 'Q110926-0001', client_name: 'Metro Builders Inc.', address: '12 Site Rd, Pasig City', quotation_date: '2026-11-06', accepted_on: '2026-11-09',
        subtotal: 425, discount: 5, tax_rate: 12, tax_amount: 50.4, grand_total: 470.4,
        items: [
            { id: 1, sku: 'BRK-000001', description: 'Circuit Breaker 2P 40A', quantity: 10, unit: 'pcs', unit_price: 20, amount: 200 },
            { id: 2, sku: 'WIR-000001', description: 'THHN Wire 12 AWG', quantity: 3, unit: 'roll', unit_price: 75, amount: 225 },
        ],
    },
    {
        id: 9, quotation_number: 'Q110926-0002', client_name: 'Titan Construction', address: null, quotation_date: '2026-11-07', accepted_on: '2026-11-10',
        subtotal: 60, discount: 0, tax_rate: 0, tax_amount: 0, grand_total: 60,
        items: [{ id: 3, sku: null, description: 'Custom panel board', quantity: 1, unit: null, unit_price: 60, amount: 60 }],
    },
];

let wrapper: VueWrapper;

const mountPage = async (quotations = QUOTATIONS) => {
    posts.length = 0;
    wrapper = mount(SalesIndex, {
        attachTo: document.body,
        props: {
            sales: { data: [], current_page: 1, last_page: 1, total: 0, links: [] },
            filters: {},
            metrics: { totalSales: 0, todaySales: 0, monthSales: 0, pendingPayments: 0, toVerify: 0, outstanding: 0 },
            quotations,
            options: { status: [], payment_status: [], delivery_status: [] },
        },
    });
    await wrapper.findAll('button').find((b) => b.text().includes('New Sales Order'))!.trigger('click');
    await flushPromises();
};

beforeEach(async () => mountPage());
afterEach(() => {
    wrapper.unmount();
    document.body.innerHTML = '';
});

const label = (id: string) => document.querySelector<HTMLLabelElement>(`label[for="${id}"]`)!;
const floated = (id: string) => label(id).className.includes('-translate-y-2.5 scale-75 text-[#4b5320]');
const createButton = () => wrapper.findAll('button').find((b) => b.text() === 'Create Sales Order')!;
const rows = () => wrapper.find('[role="dialog"]').findAll('tbody tr').map((r) => r.findAll('td').map((c) => c.text()));

describe('New Sales Order', () => {
    it('offers accepted quotations only, by number and customer, with nothing selected and no POS parts', () => {
        expect(document.querySelector('#so-title')?.textContent).toBe('New Sales Order');
        const select = wrapper.find<HTMLSelectElement>('#so-quotation');
        expect(select.element.selectedIndex).toBe(0);
        expect(select.findAll('option').map((o) => o.text())).toEqual(['Select Quotation', 'Q110926-0001 — Metro Builders Inc.', 'Q110926-0002 — Titan Construction']);
        expect(label('so-quotation').textContent?.trim()).toBe('Select Quotation');
        expect(floated('so-quotation')).toBe(false);

        const text = document.body.textContent ?? '';
        for (const gone of ['Select Products', 'Cart Items', 'Payment reference number', 'Amount paid', 'Proof of Payment', 'GCash', 'Counter Sale']) {
            expect(text).not.toContain(gone);
        }
        expect(document.querySelector('#so-quotation-search')).toBeNull(); // short list: no search box
        expect(wrapper.findAll('button').some((b) => b.text() === 'Cancel')).toBe(true);
    });

    it('fills in the customer, quotation number, lines and total from the selected quotation, read-only', async () => {
        await wrapper.find('#so-quotation').setValue('7');

        expect(wrapper.find<HTMLInputElement>('#so-customer').element.value).toBe('Metro Builders Inc.');
        expect(wrapper.find<HTMLInputElement>('#so-quotation-number').element.value).toBe('Q110926-0001');
        expect(wrapper.find('#so-customer').attributes('readonly')).toBeDefined();
        expect(wrapper.find('#so-quotation-number').attributes('readonly')).toBeDefined();
        expect(['so-quotation', 'so-customer', 'so-quotation-number'].every(floated)).toBe(true);

        expect(wrapper.find('[role="dialog"]').findAll('thead th').map((t) => t.text())).toEqual(['Product Description', 'QTY Ordered', 'Unit', 'Unit Price', 'Amount']);
        expect(rows()).toEqual([
            ['Circuit Breaker 2P 40A  BRK-000001', '10', 'pcs', '$20.00', '$200.00'],
            ['THHN Wire 12 AWG  WIR-000001', '3', 'roll', '$75.00', '$225.00'],
        ].map((r) => [r[0].replace('  ', ''), ...r.slice(1)]));
        expect(document.querySelector('#so-total')?.textContent).toBe('$470.40');
        expect(document.body.textContent).toContain('VAT (12%)');
        expect(wrapper.find<HTMLTextAreaElement>('#so-address').element.value).toBe('12 Site Rd, Pasig City');

        // Nothing in the summary can be edited.
        expect(wrapper.find('[role="dialog"]').findAll('tbody input').length).toBe(0);
    });

    it('requires a quotation before creating', async () => {
        await createButton().trigger('click');
        await flushPromises();
        expect(document.querySelector('#so-quotation-error')?.textContent).toBe('Select a quotation.');
        expect(posts).toHaveLength(0);
    });

    it('creates the order from the quotation only (no products, prices or payment are sent)', async () => {
        await wrapper.find('#so-quotation').setValue('9');
        expect(rows()).toEqual([['Custom panel board', '1', '—', '$60.00', '$60.00']]);
        await wrapper.find('input[value="pickup"]').setValue(true);
        await createButton().trigger('click');
        await flushPromises();

        expect(posts).toEqual([{ url: '/mr-pride/sales', data: { quotation_id: 9, fulfillment_type: 'pickup', delivery_address: undefined } }]);
        expect(document.querySelector('#so-title')).toBeNull(); // closed after success
    });

    it('says when no accepted quotation is waiting', async () => {
        wrapper.unmount();
        await mountPage([]);
        expect(document.body.textContent).toContain('No accepted quotations are waiting for a Sales Order');
        expect(createButton().attributes('disabled')).toBeDefined();
    });

    it('adds a search box for long lists and filters by number or customer', async () => {
        wrapper.unmount();
        const many = Array.from({ length: 10 }, (_, i) => ({ ...QUOTATIONS[1], id: 100 + i, quotation_number: `Q-${100 + i}`, client_name: i === 4 ? 'Pacific Steel' : 'Titan Construction' }));
        await mountPage(many);

        await wrapper.find('#so-quotation-search').setValue('pacific');
        expect(wrapper.find('#so-quotation').findAll('option').map((o) => o.text())).toEqual(['Select Quotation', 'Q-104 — Pacific Steel']);
    });
});
