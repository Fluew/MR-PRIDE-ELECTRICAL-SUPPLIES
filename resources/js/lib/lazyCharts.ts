import { defineAsyncComponent } from 'vue';

/**
 * Chart.js + vue-chartjs load on demand, the first time a chart is shown, so pages with charts
 * (Reports, Forecast) render their tables and figures without waiting for the chart library.
 * The library is downloaded and registered once and shared by every chart.
 */
let library: Promise<typeof import('vue-chartjs')> | null = null;

function loadCharts() {
    library ??= Promise.all([import('chart.js'), import('vue-chartjs')]).then(([chartjs, vueChartjs]) => {
        chartjs.Chart.register(
            chartjs.CategoryScale,
            chartjs.LinearScale,
            chartjs.PointElement,
            chartjs.LineElement,
            chartjs.BarElement,
            chartjs.ArcElement,
            chartjs.Filler,
            chartjs.Title,
            chartjs.Tooltip,
            chartjs.Legend,
        );

        return vueChartjs;
    });

    return library;
}

export const LineChart = defineAsyncComponent(() => loadCharts().then((m) => m.Line));
export const BarChart = defineAsyncComponent(() => loadCharts().then((m) => m.Bar));
export const DoughnutChart = defineAsyncComponent(() => loadCharts().then((m) => m.Doughnut));
