import assert from 'node:assert/strict';
import test from 'node:test';
import { chartAxis, fitChartToContainer, percentageScaleBounds, seriesPointRadius } from '../../resources/js/chart-scale.js';

test('percentage charts always span zero to one hundred', () => {
    const bounds = percentageScaleBounds();

    assert.equal(bounds.min, 0);
    assert.equal(bounds.max, 100);
    assert.equal(bounds.ticks.stepSize, 20);
});

test('non-percentage axes start at zero and format their unit', () => {
    assert.equal(chartAxis('bytes').ticks.callback(1024), '1 KB');
    assert.equal(chartAxis('rate').ticks.callback(1024), '1 KB/s');
    assert.equal(chartAxis('load').ticks.callback(1.234), '1.23');
});

test('a single sample is drawn as a visible point', () => {
    assert.equal(seriesPointRadius(1), 2);
    assert.equal(seriesPointRadius(2), 0);
    assert.equal(seriesPointRadius(0), 0);
});

test('a chart is refitted to its container after a Livewire morph', () => {
    const calls = [];
    const chart = {
        resize: () => calls.push('resize'),
        update: (mode) => calls.push(`update:${mode}`),
    };

    fitChartToContainer(chart);

    assert.deepEqual(calls, ['resize', 'update:none']);
});
