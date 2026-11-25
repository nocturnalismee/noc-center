/**
 * Chart scales and point visibility for the percentage series.
 *
 * The dashboard plots CPU, memory, and disk percentages. Always pin the
 * axis to 0-100 so a low-value fleet does not get clipped above the visible
 * range, and keep a single collected sample visible instead of hiding it
 * behind a zero-radius line point.
 */

export function percentageScaleBounds() {
    return {
        min: 0,
        max: 100,
        ticks: { stepSize: 20 },
    };
}

export function seriesPointRadius(pointCount) {
    return Number(pointCount) === 1 ? 2 : 0;
}
