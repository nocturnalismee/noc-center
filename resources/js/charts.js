import { chartAxis, fitChartToContainer, seriesPointRadius } from './chart-scale.js';
import { formatMetricValue } from './chart-format.js';

const DEFAULT_SERIES = [
    { metric: 'cpu', label: 'CPU', color: '--chart-cpu' },
    { metric: 'memory', label: 'Memory', color: '--chart-memory' },
    { metric: 'disk', label: 'Disk', color: '--chart-disk' },
];

function cssColor(name) {
    return getComputedStyle(document.documentElement).getPropertyValue(name).trim();
}

const JAKARTA_TIME_ZONE = 'Asia/Jakarta';

const jakartaTickTime = new Intl.DateTimeFormat('en-GB', {
    timeZone: JAKARTA_TIME_ZONE,
    hour: '2-digit',
    minute: '2-digit',
    hour12: false,
});

const jakartaTickDay = new Intl.DateTimeFormat('en-GB', {
    timeZone: JAKARTA_TIME_ZONE,
    day: '2-digit',
    month: 'short',
});

const jakartaTooltip = new Intl.DateTimeFormat('en-GB', {
    timeZone: JAKARTA_TIME_ZONE,
    day: '2-digit',
    month: 'short',
    hour: '2-digit',
    minute: '2-digit',
    hour12: false,
});

function formatJakarta(value, formatter) {
    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return '';
    }

    return formatter.format(date).replace(',', '');
}

function parseSeries(el) {
    try {
        const parsed = JSON.parse(el.dataset.series || '[]');

        return Array.isArray(parsed) && parsed.length > 0 ? parsed : DEFAULT_SERIES;
    } catch {
        return DEFAULT_SERIES;
    }
}

/**
 * Merge timestamped points into one dataset per series.
 * A missing timestamp between two points leaves a null so Chart.js breaks the line;
 * the API omits gaps instead of filling them with zero.
 */
function mergePoints(payloads, fallbackStepSeconds) {
    const byTime = new Map();

    payloads.forEach((payload, index) => {
        for (const point of payload.points || []) {
            const timestamp = Date.parse(point.t);

            if (!Number.isFinite(timestamp)) {
                continue;
            }

            if (!byTime.has(timestamp)) {
                byTime.set(timestamp, new Array(payloads.length).fill(null));
            }

            const value = Number(point.v);
            byTime.get(timestamp)[index] = Number.isFinite(value) ? value : null;
        }
    });

    const times = [...byTime.keys()].sort((a, b) => a - b);
    const step = Math.max(
        1,
        ...payloads.map((payload) => Number(payload.resolution) || 0),
        Number(fallbackStepSeconds) || 0,
    ) * 1000;
    const merged = [];

    times.forEach((timestamp, index) => {
        if (index > 0 && timestamp - times[index - 1] > step * 2.5) {
            merged.push({
                x: Math.round((times[index - 1] + timestamp) / 2),
                values: new Array(payloads.length).fill(null),
            });
        }

        merged.push({ x: timestamp, values: byTime.get(timestamp) });
    });

    return merged;
}

async function fetchPayload(series) {
    const response = await fetch(series.url, {
        headers: { Accept: 'application/json' },
        credentials: 'same-origin',
    });

    if (!response.ok) {
        throw new Error(`Series request failed (${response.status})`);
    }

    return response.json();
}

async function loadSeriesData(el, series) {
    const [chartModule, , ...payloads] = await Promise.all([
        import('chart.js/auto'),
        import('chartjs-adapter-date-fns'),
        ...series.map((line) => fetchPayload(line)),
    ]);

    return {
        Chart: chartModule.default,
        merged: mergePoints(payloads, Number(el.dataset.interval) || 60),
    };
}

function chartOptions(el, series) {
    const divider = cssColor('--divider');
    const muted = cssColor('--text-muted');
    const unit = el.dataset.chartUnit || 'percent';
    const axis = chartAxis(unit === 'mixed' ? 'load' : unit);
    const yTicks = axis.ticks ?? {};
    const y = {
        ...axis,
        grid: { color: divider, drawTicks: false },
        border: { display: false },
        ticks: {
            ...yTicks,
            color: muted,
            font: { size: 11 },
            padding: 8,
        },
    };
    const scales = {
        x: {
            type: 'time',
            time: { tooltipFormat: 'dd MMM HH:mm', displayFormats: { hour: 'HH:mm', day: 'dd MMM' } },
            grid: { color: divider, drawTicks: false },
            border: { color: divider },
            ticks: {
                color: muted,
                font: { size: 11 },
                maxRotation: 0,
                autoSkipPadding: 18,
                callback(value) {
                    const span = Number(this.max) - Number(this.min);
                    const formatter = span > 2 * 24 * 60 * 60 * 1000 ? jakartaTickDay : jakartaTickTime;

                    return formatJakarta(value, formatter);
                },
            },
        },
        y,
    };

    if (unit === 'mixed') {
        const rateAxis = chartAxis('rate');
        const rateTicks = rateAxis.ticks ?? {};

        scales.y1 = {
            ...rateAxis,
            position: 'right',
            grid: { display: false },
            border: { display: false },
            ticks: {
                ...rateTicks,
                color: muted,
                font: { size: 11 },
                padding: 8,
            },
        };
    }

    return {
        responsive: true,
        maintainAspectRatio: false,
        animation: false,
        interaction: { mode: 'index', intersect: false },
        plugins: {
            legend: { display: false },
            tooltip: {
                backgroundColor: cssColor('--surface-2'),
                borderColor: cssColor('--border'),
                borderWidth: 1,
                titleColor: cssColor('--text'),
                bodyColor: cssColor('--text-sub'),
                padding: 10,
                displayColors: true,
                callbacks: {
                    title: (items) => {
                        if (!Array.isArray(items) || items.length === 0) {
                            return '';
                        }

                        const timestamp = items[0]?.parsed?.x;

                        return timestamp === undefined || timestamp === null
                            ? ''
                            : `${formatJakarta(timestamp, jakartaTooltip)} WIB`;
                    },
                    label: (context) => {
                        const line = series[context.datasetIndex] ?? {};
                        const format = line.format ?? (unit === 'mixed' ? 'load' : unit);

                        return `${context.dataset.label}: ${formatMetricValue(context.parsed?.y, format)}`;
                    },
                },
            },
        },
        scales,
    };
}

function buildDatasets(series, merged) {
    return series.map((line, index) => ({
        label: line.label,
        data: merged.map((entry) => ({ x: entry.x, y: entry.values[index] })),
        borderColor: cssColor(line.color || DEFAULT_SERIES[index]?.color || '--chart-cpu'),
        backgroundColor: 'transparent',
        borderWidth: 1.8,
        yAxisID: line.axis === 'y1' ? 'y1' : 'y',
        pointRadius: seriesPointRadius(merged.length),
        pointHitRadius: 8,
        spanGaps: false,
        tension: 0,
    }));
}

function setChartState(el, state, message = '') {
    const status = el.querySelector('[data-chart-status]');
    const error = el.querySelector('[data-chart-error]');
    const retry = el.querySelector('[data-chart-retry]');

    el.setAttribute('aria-busy', state === 'loading' ? 'true' : 'false');

    if (status) {
        status.hidden = !['loading', 'empty'].includes(state);
        status.textContent = message;
    }

    if (error) {
        error.hidden = state !== 'error';
    }

    if (retry) {
        retry.disabled = state === 'loading';
    }
}

/**
 * Mount a time-series chart from one or more `servers.series` URLs.
 *
 * @param {HTMLElement} el
 * @param {string|null} url Optional single-series URL when the element has no data-series payload.
 */
export async function mountSeriesChart(el, url = null) {
    if (!(el instanceof HTMLElement) || el.dataset.chartMounted === 'true') {
        return;
    }

    const canvas = el.querySelector('canvas');

    if (!canvas) {
        return;
    }

    const series = url
        ? [{ ...DEFAULT_SERIES[0], url }]
        : parseSeries(el);
    const valid = series.filter((line) => typeof line.url === 'string' && line.url !== '');

    if (valid.length === 0) {
        return;
    }

    el.dataset.chartMounted = 'true';
    setChartState(el, 'loading', 'Loading chart data…');

    if (el.dataset.chartHasSample === 'false') {
        setChartState(el, 'empty', 'No samples received yet.');
        return;
    }

    try {
        const { Chart, merged } = await loadSeriesData(el, valid);

        if (merged.length === 0) {
            setChartState(el, 'empty', 'No metric points are available for this range.');
            return;
        }

        el.chart = new Chart(canvas, {
            type: 'line',
            data: { datasets: buildDatasets(valid, merged) },
            options: chartOptions(el, valid),
        });

        el.dataset.chartReady = 'true';
        setChartState(el, 'ready');
    } catch (error) {
        console.warn('Series chart unavailable', error);
        el.dataset.chartError = 'true';
        setChartState(el, 'error');
    }
}

/**
 * Update an already-mounted chart without destroying its canvas.
 *
 * Realtime refreshes should leave the previous line visible while the next
 * payload is loading. A destroy-and-remount creates a blank chart for the
 * duration of the three series requests.
 */
export async function updateSeriesChart(el) {
    if (!(el instanceof HTMLElement)) {
        return;
    }

    const canvas = el.querySelector('canvas');

    if (!canvas) {
        return;
    }

    if (!el.chart || el.chart.canvas !== canvas) {
        el.chart?.destroy();
        el.chart = null;
        delete el.dataset.chartMounted;
        delete el.dataset.chartReady;
        delete el.dataset.chartError;
        await mountSeriesChart(el);

        return;
    }

    const series = parseSeries(el);
    const valid = series.filter((line) => typeof line.url === 'string' && line.url !== '');

    if (valid.length === 0) {
        return;
    }

    if (el.dataset.chartHasSample === 'false') {
        el.chart.destroy();
        el.chart = null;
        delete el.dataset.chartMounted;
        delete el.dataset.chartReady;
        delete el.dataset.chartError;
        setChartState(el, 'empty', 'No samples received yet.');

        return;
    }

    try {
        const { merged } = await loadSeriesData(el, valid);

        if (merged.length === 0) {
            setChartState(el, 'empty', 'No metric points are available for this range.');

            return;
        }

        el.chart.data.datasets = buildDatasets(valid, merged);
        fitChartToContainer(el.chart);
        el.dataset.chartReady = 'true';
        setChartState(el, 'ready');
    } catch (error) {
        console.warn('Series chart refresh unavailable', error);
        el.dataset.chartError = 'true';
        setChartState(el, 'error');
    }
}

export function initSeriesCharts(root = document) {
    root.querySelectorAll('[data-series-chart]').forEach((el) => {
        mountSeriesChart(el);
    });
}

export function refreshSeriesCharts(root = document) {
    root.querySelectorAll('[data-series-chart]').forEach((el) => {
        el.chart?.destroy();
        el.chart = null;
        delete el.dataset.chartMounted;
        delete el.dataset.chartReady;
        delete el.dataset.chartError;
        mountSeriesChart(el);
    });
}

export function updateSeriesCharts(root = document) {
    root.querySelectorAll('[data-series-chart]').forEach((el) => {
        updateSeriesChart(el);
    });
}

export function fitSeriesCharts(root = document) {
    root.querySelectorAll('[data-series-chart]').forEach((el) => {
        fitChartToContainer(el.chart);
    });
}

if (typeof document !== 'undefined') {
    document.addEventListener('click', (event) => {
        const target = event.target;

        if (!(target instanceof Element)) {
            return;
        }

        const retry = target.closest('[data-chart-retry]');
        const chart = retry?.closest('[data-series-chart]');

        if (chart) {
            event.preventDefault();
            chart.chart?.destroy();
            chart.chart = null;
            delete chart.dataset.chartMounted;
            delete chart.dataset.chartReady;
            delete chart.dataset.chartError;
            mountSeriesChart(chart);
        }
    });
}

if (typeof document !== 'undefined') {
    document.addEventListener('theme-changed', () => refreshSeriesCharts());
}
