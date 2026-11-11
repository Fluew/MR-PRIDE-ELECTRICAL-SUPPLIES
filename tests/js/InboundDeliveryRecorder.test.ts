import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { reactive } from 'vue';

/**
 * "Record Inbound P.O. Delivery" (components/deliveries/InboundDeliveryRecorder.vue): the purchase
 * order's details come from the server data, each received product must pass the quality check
 * rules before Confirm Delivery is enabled, and the payload carries only what the user entered.
 */

const { sent } = vi.hoisted(() => ({ sent: [] as { method: string; url: string; data: any }[] }));
vi.mock('@inertiajs/vue3', () => ({
    useForm: (initial: Record<string, unknown>) => {
        let transformer = (d: any) => d;
        const form: any = reactive({
            ...initial,
            errors: {},
            processing: false,
            reset() { Object.assign(form, initial); },
            clearErrors() { form.errors = {}; },
            transform(fn: (d: any) => any) { transformer = fn; return form; },
            post(url: string, options: any) { sent.push({ method: 'post', url, data: transformer({ ...form }) }); options?.onSuccess?.(); },
            put(url: string, options: any) { sent.push({ method: 'put', url, data: transformer({ ...form }) }); options?.onSuccess?.(); },
        });
        return form;
    },
}));
vi.mock('@/composables/useCurrency', () => ({ useCurrency: () => ({ formatMoney: (v: number) => `$${Number(v).toFixed(2)}` }) }));

import InboundDeliveryRecorder from '@/components/deliveries/InboundDeliveryRecorder.vue';

const ORDERS = [
    {
        po_number: 'PO-7001',
        po_date: '2026-11-02',
        supplier: { id: 3, name: 'Surangel Supply' },
        status: 'Pending',
        lines: [
            { id: 11, quantity: 20, item_sku: 'SUR-BRK40', description: 'Circuit Breaker 40A', unit_cost: 12.5, unit_of_measure: 'PCS', extended_cost: 250, previously_received: 0, remaining: 20, product: { id: 1, sku: 'BRK-000001', name: 'Circuit Breaker 40A', via: 'purchase_order' } },
            { id: 12, quantity: 10, item_sku: 'SUR-THHN12', description: 'THHN Wire 12 AWG', unit_cost: 40, unit_of_measure: 'ROLL', extended_cost: 400, previously_received: 4, remaining: 6, product: null },
        ],
    },
];

let wrapper: VueWrapper;
const mountRecorder = (props: Record<string, unknown> = {}) => {
    wrapper = mount(InboundDeliveryRecorder, {
        props: { open: true, teamSlug: 'mr-pride', isAdmin: false, orders: ORDERS, products: [{ id: 9, sku: 'WIR-000009', name: 'Wire 12' }], categories: [], ...props },
        attachTo: document.body,
    });
};
const confirmButton = () => wrapper.get('[data-test="confirm-delivery"]');
const row = (i: number) => wrapper.get(`[data-test="quality-row-${i}"]`);

beforeEach(() => {
    sent.length = 0;
});
afterEach(() => wrapper?.unmount());

describe('Record Inbound P.O. Delivery', () => {
    it('shows the selected purchase order and its products from the order data', async () => {
        mountRecorder();
        await wrapper.get('[data-test="po-search"]').setValue('surangel');
        await wrapper.get('[data-test="po-option-PO-7001"]').trigger('click');

        expect(wrapper.get('[data-test="po-info-number"]').text()).toBe('PO-7001');
        expect(wrapper.text()).toContain('Surangel Supply');
        const table = wrapper.get('[data-test="ordered-products"]').text();
        expect(table).toContain('SUR-THHN12');
        expect(table).toContain('$400.00');
        expect(row(1).text()).toContain('Not in inventory yet');
        expect(confirmButton().attributes('disabled')).toBeDefined(); // nothing received yet
    });

    it('works out accepted from received and rejected, and requires remarks, a product decision and the quality check', async () => {
        mountRecorder();
        await wrapper.get('[data-test="po-option-PO-7001"]').trigger('click');

        await row(1).get('#ib-rec-1').setValue(6);
        expect((row(1).get('#ib-acc-1').element as HTMLInputElement).value).toBe('6');
        await row(1).get('#ib-rej-1').setValue(2);
        expect((row(1).get('#ib-acc-1').element as HTMLInputElement).value).toBe('4');
        expect(row(1).get('[data-test="quality-status"]').text()).toBe('Partially Passed');

        const problems = row(1).text();
        expect(problems).toContain('Describe why units were rejected');
        expect(problems).toContain('link it to an existing product or create a new one');
        expect(problems).toContain('Quality checked');
        expect(confirmButton().attributes('disabled')).toBeDefined();

        await row(1).get('#ib-rem-1').setValue('Two rolls crushed');
        await row(1).get('input[type="radio"][value="create"]').setValue(true);
        await row(1).get('[data-test="inspected-1"]').setValue(true);
        expect(confirmButton().attributes('disabled')).toBeUndefined();

        await wrapper.get('[data-test="inbound-recorder"]').trigger('submit');
        await flushPromises();

        expect(sent).toHaveLength(1);
        expect(sent[0].url).toBe('/mr-pride/deliveries/inbound');
        expect(sent[0].data.po_number).toBe('PO-7001');
        expect(sent[0].data.submission_key).toBeTruthy();
        expect(sent[0].data.lines[0]).toMatchObject({ purchase_order_id: 11, received_quantity: 0 });
        expect(sent[0].data.lines[1]).toMatchObject({
            purchase_order_id: 12, received_quantity: 6, accepted_quantity: 4, rejected_quantity: 2, inspected: true,
            inspection_remarks: 'Two rolls crushed', product_action: 'create', category: 'General',
        });
        expect(wrapper.emitted('saved')).toHaveLength(1);
    });

    it('refuses more than is still owed for staff', async () => {
        mountRecorder();
        await wrapper.get('[data-test="po-option-PO-7001"]').trigger('click');
        await row(1).get('#ib-rec-1').setValue(7);
        expect(row(1).text()).toContain('Only 6 still owed');
    });

    it('corrects a confirmed delivery with a reason', async () => {
        mountRecorder({
            delivery: {
                id: 5, delivery_number: 'DEL-110926-001', delivery_date: '2026-11-09', purchase_order_number: 'PO-7001', order_date: '2026-11-02',
                supplier: 'Surangel Supply', supplier_document_number: null, status: 'confirmed',
                items: [{ id: 51, purchase_order_id: 11, item_sku: 'SUR-BRK40', description: 'Circuit Breaker 40A', ordered_quantity: 20, previously_received: 0, remaining: 20,
                    received_quantity: 10, accepted_quantity: 10, rejected_quantity: 0, unit_cost: 12.5, unit_of_measure: 'PCS', extended_cost: 250,
                    quality_status: 'Passed', inspection_remarks: null, rejection_category: null, over_receipt_reason: null, status: 'Delivered', product: { id: 1, sku: 'BRK-000001', name: 'Circuit Breaker 40A' } }],
            },
        });
        expect(wrapper.text()).toContain('Correct delivery DEL-110926-001');
        await row(0).get('#ib-acc-0').setValue(8);
        expect((row(0).get('#ib-rej-0').element as HTMLInputElement).value).toBe('2');
        await row(0).get('#ib-rem-0').setValue('Cracked housing');
        expect(confirmButton().attributes('disabled')).toBeDefined(); // reason missing
        await wrapper.get('#ib-reason').setValue('Found cracked after unpacking');
        await wrapper.get('[data-test="inbound-recorder"]').trigger('submit');

        expect(sent[0]).toMatchObject({ method: 'put', url: '/mr-pride/deliveries/inbound/5' });
        expect(sent[0].data.reason).toBe('Found cracked after unpacking');
        expect(sent[0].data.lines[0]).toMatchObject({ delivery_id: 51, received_quantity: 10, accepted_quantity: 8, rejected_quantity: 2 });
    });
});
