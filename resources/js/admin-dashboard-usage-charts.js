import ApexCharts from 'apexcharts';

const TREND_ROOT_ID = 'admin-dashboard-token-trend';
const DONUT_ROOT_ID = 'admin-dashboard-token-donut';
const PAYLOAD_ID = 'admin-dashboard-usage-charts-payload';

/** @type {ApexCharts|null} */
let trendChart = null;
/** @type {ApexCharts|null} */
let donutChart = null;
let lastSignature = '';

function readPayload() {
    const node = document.getElementById(PAYLOAD_ID);
    if (! node) {
        return null;
    }

    const raw = (node.textContent || '').trim();
    if (raw === '') {
        return null;
    }

    try {
        return JSON.parse(raw);
    } catch (error) {
        console.error('[AdminDashboardCharts] payload parse failed', error);

        return null;
    }
}

function signature(payload) {
    if (! payload) {
        return '';
    }

    return JSON.stringify({
        labels: payload.labels ?? [],
        total: payload.total_series ?? [],
        seo: payload.seo_series ?? [],
        seeding: payload.seeding_series ?? [],
        slices: payload.slices ?? [],
        center: payload.center_total ?? 0,
    });
}

function destroyCharts() {
    if (trendChart) {
        try {
            trendChart.destroy();
        } catch {
            // ignore Livewire morph races
        }
        trendChart = null;
    }

    if (donutChart) {
        try {
            donutChart.destroy();
        } catch {
            // ignore
        }
        donutChart = null;
    }

    const trendRoot = document.getElementById(TREND_ROOT_ID);
    if (trendRoot) {
        trendRoot.innerHTML = '';
    }

    const donutRoot = document.getElementById(DONUT_ROOT_ID);
    if (donutRoot) {
        donutRoot.innerHTML = '';
    }
}

function buildTrendOptions(payload) {
    return {
        chart: {
            type: 'bar',
            height: 260,
            toolbar: { show: false },
            fontFamily: 'inherit',
            animations: { enabled: false },
        },
        series: [
            { name: 'Tổng', data: payload.total_series ?? [] },
            { name: 'SEO', data: payload.seo_series ?? [] },
            { name: 'Seeding', data: payload.seeding_series ?? [] },
        ],
        colors: ['#0ea5e9', '#10b981', '#8b5cf6'],
        plotOptions: {
            bar: {
                columnWidth: '55%',
                borderRadius: 2,
            },
        },
        dataLabels: { enabled: false },
        stroke: { show: true, width: 1, colors: ['transparent'] },
        xaxis: {
            categories: payload.labels ?? [],
            labels: {
                rotate: -45,
                rotateAlways: (payload.labels?.length ?? 0) > 14,
                hideOverlappingLabels: true,
                trim: true,
                style: { fontSize: '10px' },
            },
            axisBorder: { show: false },
            axisTicks: { show: false },
        },
        yaxis: {
            labels: {
                formatter: (value) => Number(value).toLocaleString(),
                style: { fontSize: '10px' },
            },
        },
        grid: {
            borderColor: '#e5e7eb',
            strokeDashArray: 3,
            padding: { left: 8, right: 8 },
        },
        legend: {
            position: 'top',
            horizontalAlign: 'right',
            fontSize: '11px',
        },
        tooltip: {
            y: {
                formatter: (value) => `${Number(value).toLocaleString()} tokens`,
            },
        },
        responsive: [
            {
                breakpoint: 768,
                options: {
                    chart: { height: 220 },
                    legend: { position: 'bottom' },
                },
            },
        ],
    };
}

function buildDonutOptions(payload) {
    const slices = Array.isArray(payload.slices) ? payload.slices : [];
    const labels = slices.map((s) => s.name);
    const series = slices.map((s) => Number(s.tokens) || 0);
    const colors = slices.map((s) => s.color).filter(Boolean);

    return {
        chart: {
            type: 'donut',
            height: 260,
            fontFamily: 'inherit',
            animations: { enabled: false },
        },
        series,
        labels,
        colors: colors.length === series.length ? colors : ['#10b981', '#8b5cf6', '#0ea5e9', '#f59e0b', '#94a3b8'],
        legend: {
            position: 'bottom',
            fontSize: '11px',
        },
        dataLabels: { enabled: false },
        plotOptions: {
            pie: {
                donut: {
                    size: '68%',
                    labels: {
                        show: true,
                        name: { show: true, fontSize: '11px' },
                        value: {
                            show: true,
                            fontSize: '14px',
                            fontWeight: 700,
                            formatter: (val) => Number(val).toLocaleString(),
                        },
                        total: {
                            show: true,
                            label: 'tokens',
                            fontSize: '11px',
                            formatter: () => Number(payload.center_total || 0).toLocaleString(),
                        },
                    },
                },
            },
        },
        tooltip: {
            y: {
                formatter: (value) => `${Number(value).toLocaleString()} tokens`,
            },
        },
    };
}

function renderCharts() {
    const payload = readPayload();
    const nextSig = signature(payload);
    if (! payload) {
        destroyCharts();
        lastSignature = '';

        return;
    }

    if (nextSig === lastSignature && trendChart && donutChart) {
        return;
    }

    destroyCharts();
    lastSignature = nextSig;

    const trendRoot = document.getElementById(TREND_ROOT_ID);
    const donutRoot = document.getElementById(DONUT_ROOT_ID);
    const hasTrend = (payload.max_tokens ?? 0) > 0 && Array.isArray(payload.labels) && payload.labels.length > 0;
    const hasDonut = Array.isArray(payload.slices) && payload.slices.length > 0 && (payload.center_total ?? 0) > 0;

    if (trendRoot && hasTrend) {
        trendChart = new ApexCharts(trendRoot, buildTrendOptions(payload));
        trendChart.render();
    }

    if (donutRoot && hasDonut) {
        donutChart = new ApexCharts(donutRoot, buildDonutOptions(payload));
        donutChart.render();
    }
}

function boot() {
    renderCharts();
}

document.addEventListener('DOMContentLoaded', boot);
document.addEventListener('livewire:navigated', boot);
document.addEventListener('livewire:init', () => {
    if (window.Livewire?.hook) {
        window.Livewire.hook('morph.updated', () => {
            queueMicrotask(boot);
        });
    }
});

window.addEventListener('resize', () => {
    if (trendChart) {
        trendChart.windowResizeHandler?.();
    }
    if (donutChart) {
        donutChart.windowResizeHandler?.();
    }
});
