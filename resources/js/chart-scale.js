import { formatBytes, formatLoad, formatRate } from './chart-format.js';

/**
 * Chart scales and point visibility.
 *
 * CPU is pinned to 0-100 percent. Memory and disk use a zero-based byte
 * axis, network uses bytes per second, and load is a plain number. Keep a
 * single collected sample visible instead of hiding it behind a zero-radius
 * line point.
 */

export function percentageScaleBounds() {
    return {
        min: 0,
        max: 100,
        ticks: { stepSize: 20 },
    };
}

export function chartAxis(unit) {
    if (unit === 'percent') {
        return percentageScaleBounds();
    }

    const formatter = unit === 'bytes'
        ? formatBytes
        : unit === 'rate'
            ? formatRate
            : formatLoad;

    return {
        min: 0,
        ticks: { callback: (value) => formatter(value) },
    };
}

export function seriesPointRadius(pointCount) {
    return Number(pointCount) === 1 ? 2 : 0;
}

/**
 * Livewire morphs can reset the chart canvas width/height attributes to the
 * server-rendered defaults. Chart.js keeps its internal size, so the next
 * paint is clipped to a 300x150 corner until resize() recomputes it.
 */
export function fitChartToContainer(chart) {
    if (!chart || typeof chart.resize !== 'function') {
        return;
    }

    chart.resize();
    chart.update('none');
}
