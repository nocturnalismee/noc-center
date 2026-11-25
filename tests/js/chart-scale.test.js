import assert from 'node:assert/strict';
import test from 'node:test';
import { percentageScaleBounds, seriesPointRadius } from '../../resources/js/chart-scale.js';

test('percentage charts always span zero to one hundred', () => {
    const bounds = percentageScaleBounds();

    assert.equal(bounds.min, 0);
    assert.equal(bounds.max, 100);
    assert.equal(bounds.ticks.stepSize, 20);
});

test('a single sample is drawn as a visible point', () => {
    assert.equal(seriesPointRadius(1), 2);
    assert.equal(seriesPointRadius(2), 0);
    assert.equal(seriesPointRadius(0), 0);
});
