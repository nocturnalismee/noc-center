import assert from 'node:assert/strict';
import test from 'node:test';
import { formatBytes, formatLoad, formatMetricValue, formatRate } from '../../resources/js/chart-format.js';

test('bytes use KB, MB, GB, and TB', () => {
    assert.equal(formatBytes(0), '0 B');
    assert.equal(formatBytes(1024), '1 KB');
    assert.equal(formatBytes(1.5 * 1024 ** 2), '1.5 MB');
    assert.equal(formatBytes(2 * 1024 ** 4), '2 TB');
});

test('network rates append per second', () => {
    assert.equal(formatRate(1024), '1 KB/s');
    assert.equal(formatMetricValue(1024, 'rate'), '1 KB/s');
});

test('load and percent keep their own formatting', () => {
    assert.equal(formatLoad(1.234), '1.23');
    assert.equal(formatMetricValue(42.4, 'percent'), '42%');
});
