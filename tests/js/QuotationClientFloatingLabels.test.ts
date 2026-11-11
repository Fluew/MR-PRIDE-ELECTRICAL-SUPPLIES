import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

/**
 * Floating labels in the staff quotation form's "Client / Customer Information" section
 * (pages/Quotations/AdminIndex.vue): labels sit inside empty fields and float up on focus or
 * when a value is present — typed, or filled in by "Load Existing Client".
 */

vi.mock('@inertiajs/vue3', () => ({
    Head: { render: () => null },
    router: { get: vi.fn(), post: vi.fn(), put: vi.fn(), patch: vi.fn(), delete: vi.fn(), reload: vi.fn(), visit: vi.fn(), on: vi.fn(() => () => {}) },
    usePage: () => ({ props: { currentTeam: { slug: 'mr-pride' }, auth: { user: { role: 'admin' }, can_delete: true } } }),
}));
vi.mock('@/composables/useFlashToast', () => ({ showToast: vi.fn() }));
vi.mock('@/composables/useCurrency', async () => {
    const { ref } = await import('vue');

    return { useCurrency: () => ({ formatMoney: (n: unknown) => String(n), conversionNote: ref(null), displayCurrency: ref('USD') }) };
});

import AdminIndex from '@/pages/Quotations/AdminIndex.vue';

const CLIENT = { id: 7, company_name: 'Metro Builders Inc.', contact_person: 'Engr. Ana Reyes', email: 'ana@metro.example', phone: '09171234567', address: '12 Site Rd, Pasig City' };

let wrapper: VueWrapper;

beforeEach(async () => {
    wrapper = mount(AdminIndex, {
        attachTo: document.body,
        props: {
            quotations: { data: [], current_page: 1, last_page: 1, total: 0, links: [] },
            filters: {},
            metrics: { totalQuotations: 0, pendingCount: 0, forApprovalCount: 0, approvedCount: 0, acceptedCount: 0, totalQuotedValue: 0 },
            products: [],
            clients: [CLIENT],
            nextNumber: 'Q110926-PROD',
            statusOptions: [],
            formStatuses: [{ value: 'draft', label: 'Draft' }],
            decisionSources: {},
            canApprove: true,
        },
    });
    // Open "New Quotation".
    const create = wrapper.findAll('button').find((b) => /new quotation|create quotation/i.test(b.text()));
    await create!.trigger('click');
    await flushPromises();
});

afterEach(() => {
    wrapper.unmount();
    document.body.innerHTML = '';
});

const FIELDS = [
    ['q_client_name', 'Company / Client Name *'],
    ['q_contact_person', 'Contact Person'],
    ['q_email', 'Email Address'],
    ['q_phone', 'Contact / Phone Number'],
    ['q_address', 'Project Site / Billing Address'],
] as const;

describe('Client / Customer Information floating labels', () => {
    it('places a floating label on every text field, tied to its input and after it (peer)', () => {
        for (const [id, text] of FIELDS) {
            const input = wrapper.get(`#${id}`);
            const label = wrapper.get(`label[for="${id}"]`);

            expect(label.text().replace(/\s+/g, ' ').trim()).toBe(text);
            expect(input.attributes('placeholder')).toBe(' '); // the label is the placeholder
            expect(input.classes()).toEqual(expect.arrayContaining(['field-control', 'peer', 'pt-5', 'pb-1.5']));
            expect(input.element.nextElementSibling).toBe(label.element);
            // Inside the empty field; up on focus or when there is a value; smooth movement.
            expect(label.classes()).toEqual(
                expect.arrayContaining(['absolute', 'peer-placeholder-shown:translate-y-0', 'peer-placeholder-shown:scale-100', 'peer-focus:-translate-y-2.5', 'peer-focus:scale-75', '-translate-y-2.5', 'scale-75', 'transition-all']),
            );
        }
        expect(wrapper.get('#q_client_name').attributes()).toHaveProperty('required');
        expect(wrapper.get('label[for="q_client_name"] span').text()).toBe('*');
    });

    it('floats the dropdown label on focus or when a client is selected, and keeps it inside otherwise', async () => {
        const select = wrapper.get('#q_existing_client');
        const label = () => wrapper.get('label[for="q_existing_client"]');
        const emptyOption = () => select.findAll('option')[0].text();

        expect(label().text()).toBe('Load Existing Client');
        expect(label().classes()).not.toContain('-translate-y-2.5');
        expect(emptyOption()).toBe(''); // nothing under the label

        await select.trigger('focus');
        expect(label().classes()).toEqual(expect.arrayContaining(['-translate-y-2.5', 'scale-75', 'text-[#4b5320]']));
        expect(emptyOption()).toBe('-- Choose Existing Client --');

        await select.trigger('blur');
        expect(label().classes()).not.toContain('-translate-y-2.5');

        await select.setValue('7');
        await select.trigger('blur');
        expect(label().classes()).toContain('-translate-y-2.5'); // a client is selected
    });

    it('still fills the client details when an existing client is loaded, so their labels float', async () => {
        await wrapper.get('#q_existing_client').setValue('7');
        await flushPromises();

        const values = FIELDS.map(([id]) => (wrapper.get(`#${id}`).element as HTMLInputElement).value);
        expect(values).toEqual([CLIENT.company_name, CLIENT.contact_person, CLIENT.email, CLIENT.phone, CLIENT.address]);
        // A filled field does not show its (empty) placeholder, so peer-placeholder-shown no longer applies
        // and the label keeps its raised default position (-translate-y-2.5 scale-75).
        for (const [id] of FIELDS) {
            expect((wrapper.get(`#${id}`).element as HTMLInputElement).value).not.toBe('');
        }

        // The details can still be edited by hand.
        await wrapper.get('#q_contact_person').setValue('Juan Dela Cruz');
        expect((wrapper.get('#q_contact_person').element as HTMLInputElement).value).toBe('Juan Dela Cruz');
    });
});
