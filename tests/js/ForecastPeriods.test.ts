import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import type { PeriodPlanning } from '@/types/forecast';

const { visit } = vi.hoisted(() => ({ visit: vi.fn() }));
vi.mock('@inertiajs/vue3', () => ({
    Head: { render: () => null },
    Link: { template: '<a><slot /></a>' },
    router: { get: visit },
    usePage: () => ({ props: { currentTeam: { slug: 'test' }, auth: { is_admin: true }, errors: {} } }),
}));
vi.mock('@/lib/lazyCharts', () => ({ LineChart: { props: ['data', 'options'], template: '<div data-chart />' } }));
vi.mock('@/composables/useCurrency', () => ({ useCurrency: () => ({ formatMoney: (value: number) => String(value) }) }));

import ForecastIndex from '@/pages/Forecast/Index.vue';

const data: PeriodPlanning = {
    options: { period: 'monthly', window: 'auto', history_periods: 60, horizon: 3, category: 'All', search: '', product_id: null, from_date: null, to_date: null },
    products: { data: [], current_page: 1, last_page: 2, total: 30, from: 1, to: 25 },
    categories: ['Lighting'], training_end: '2026-10-31', forecast_start: '2026-12-01',
};

const legacyProps = {
    fromDate: '2025-11-01', toDate: '2026-10-31', smaWindow: 3, forecastMode: 'period' as const,
    selectedCategory: 'All', selectedProductId: null, categories: ['Lighting'], productsList: [], productWindow: null, categoryWindow: null,
    forecastData: { labels: [], historical: [], forecast: [], hasData: false, steps: [], sourceRecords: [], sourceRecordCount: 0, accuracy: { months: 0, mae: null, mape: null, mape_months: 0 } },
    suggestedRestocks: [], minDate: '1900-01-01', maxDate: '2026-10-31', forecastPeriods: {}, productForecasts: [],
};

describe('period forecasting controls', () => {
    it('submits independent period window history and horizon values', async () => {
        const wrapper = mount(ForecastIndex, { props: { ...legacyProps, periodPlanning: data } });
        await wrapper.findAll('select')[0].setValue('annual');
        await wrapper.findAll('button').find((button) => button.text() === 'SMA 6')!.trigger('click');
        await wrapper.findAll('input[type="number"]')[0].setValue(2);
        await wrapper.findAll('input[type="number"]')[1].setValue(24);
        expect(visit).toHaveBeenLastCalledWith('/test/forecast', expect.objectContaining({ period: 'annual', window: '6', history_periods: 24, horizon: 2, page: 1 }), expect.any(Object));
        wrapper.unmount();
    });

    it('preserves filters while changing the server page and retains the original detail section', async () => {
        const wrapper = mount(ForecastIndex, { props: { ...legacyProps, selectedCategory: 'Lighting', periodPlanning: { ...data, options: { ...data.options, period: 'weekly', category: 'Lighting' } } } });
        await wrapper.findAll('button').find((button) => button.text() === 'Next')!.trigger('click');
        expect(visit).toHaveBeenLastCalledWith('/test/forecast', expect.objectContaining({ period: 'weekly', category: 'Lighting', page: 2 }), expect.any(Object));
        expect(wrapper.text()).toContain('No products match this selection.');
        expect(wrapper.text()).toContain('How the forecast is calculated');
        wrapper.unmount();
    });
});
