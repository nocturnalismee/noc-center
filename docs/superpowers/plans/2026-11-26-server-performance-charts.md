# Server Performance Charts Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Split the server Performance tab into separate CPU, memory, disk, load, and network charts, with CPU in percent and memory/disk in human byte units; add load and network to the Overview tab.

**Architecture:** Extend the metrics series API with exact byte metrics for raw ranges and byte-sum rollups for long ranges, falling back to percentage × current capacity for pre-migration rollup rows. Render charts through one reusable Blade component whose `data-chart-unit` selects percent, byte, load, rate, or mixed axes in Chart.js.

**Tech Stack:** Laravel 13.17, Livewire 4.4, PHP 8.3/8.4, SQLite, Chart.js 4.6, Tailwind 4.3, PHPUnit 12, Node `node:test`, Pint, Vite 8.

**Spec:** `docs/superpowers/specs/2026-11-22-noc-dashboard-design.md` (visual system and chart constraints); the chart decisions below are authoritative for this change.

## Global Constraints

- All timestamps stay UTC in storage and `Asia/Jakarta` in display.
- Charts must keep gaps blank, never zero-filled.
- Only the CPU chart is a 0–100 percent axis.
- Memory and disk axes use human byte units `KB`, `MB`, `GB`, `TB`, base 1024.
- Network axes use bytes per second and display `KB/s`, `MB/s`, `GB/s`.
- Load is a plain numeric axis, not a percentage.
- The Performance tab has five separate charts: CPU, Memory, Disk, Load, Network in/out.
- The Overview tab keeps the combined resource chart and adds one combined Load + Network chart with separate left/right axes.
- Every chart keeps the existing loading, empty, error, retry, and no-JavaScript states.
- Every chart container keeps `wire:poll` fallback behavior and realtime chart refresh.
- Do not change ingestion, alerting, retention, or the agent payload.
- Long-range byte history: new rollup rows are exact; rows written before this migration fall back to `percentage / 100 × current capacity`.
- All UI copy is English.
- Use only `./tools/php.sh` / `./tools/test.sh` for PHP; do not run host PHP.
- Run `npm run test:js` and `npm run build` after frontend changes.
- One commit per task; do not mix unrelated work.

## Review Focus

1. A range spanning pre-migration and new rollups must render a continuous byte line, not a drop to zero for old rows.
2. A server whose memory or disk capacity changed must not show a misleading byte line without the fallback being documented in the chart summary.
3. A single-sample range must remain visible on every chart unit, not only percentage charts.
4. Overview's mixed load/network chart must use the correct axis and tooltip format for each dataset; load must not be labelled as bytes.
5. Livewire live refresh must update all chart instances without clipping, destroying the wrong canvas, or losing the selected range.

---

## File Structure

### Backend

- Create: `database/migrations/2026_11_26_000100_add_byte_sums_to_metric_rollups_table.php`
- Modify: `app/Models/MetricRollup.php`
- Modify: `app/Services/Metrics/RollupService.php`
- Modify: `app/Services/Metrics/MetricSeriesRepository.php`
- Modify: `config/metrics.php`
- Modify: `app/Livewire/Servers/Show.php`

### Frontend

- Create: `resources/views/components/series-chart.blade.php`
- Create: `resources/js/chart-format.js`
- Modify: `resources/js/chart-scale.js`
- Modify: `resources/js/charts.js`
- Modify: `resources/css/app.css`
- Modify: `resources/views/livewire/servers/show.blade.php`

### Tests

- Modify: `tests/Feature/Metrics/RollupTest.php`
- Modify: `tests/Feature/Metrics/SeriesApiTest.php`
- Modify: `tests/Feature/Ui/ServerDetailTest.php`
- Create: `tests/js/chart-format.test.js`

---

## Task 1: Add exact byte sums to metric rollups

**Files:**
- Create: `database/migrations/2026_11_26_000100_add_byte_sums_to_metric_rollups_table.php`
- Modify: `app/Models/MetricRollup.php`
- Modify: `app/Services/Metrics/RollupService.php`
- Test: `tests/Feature/Metrics/RollupTest.php`

**Interfaces:**
- Produces nullable columns `metric_rollups.mem_used_bytes_sum` and `metric_rollups.disk_used_bytes_sum`.
- `RollupService::rollup()` writes exact byte sums for new 5m/1h/1d rows; a source row with no byte value leaves the target null rather than writing zero.

- [ ] **Step 1: Write the failing rollup tests**

Add to `tests/Feature/Metrics/RollupTest.php`:

```php
public function test_five_minute_rollups_sum_exact_memory_and_disk_bytes(): void
{
    $server = $this->server();

    $this->sample($server, '2026-11-23 11:59:00', [
        'mem_used_bytes' => 1_000,
        'disk_used_bytes' => 2_000,
    ]);
    $this->sample($server, '2026-11-23 11:59:30', [
        'mem_used_bytes' => 3_000,
        'disk_used_bytes' => 4_000,
    ]);

    app(RollupService::class)->rollup(
        $server,
        RollupService::FIVE_MINUTES,
        CarbonImmutable::parse('2026-11-23 11:55:00', 'UTC'),
        CarbonImmutable::parse('2026-11-23 12:00:00', 'UTC'),
    );

    $rollup = MetricRollup::query()
        ->where('server_id', $server->id)
        ->where('resolution_seconds', 300)
        ->firstOrFail();

    $this->assertSame(4_000, $rollup->mem_used_bytes_sum);
    $this->assertSame(6_000, $rollup->disk_used_bytes_sum);
}
```

Add a second test that inserts a legacy rollup row with null byte sums and asserts `RollupService` leaves the derived 1h byte sum null, not zero.

- [ ] **Step 2: Run the test to verify it fails**

Run: `./tools/test.sh --filter=RollupTest`
Expected: FAIL because the columns and aggregation do not exist.

- [ ] **Step 3: Add the nullable migration and model fields**

Create:

```php
Schema::table('metric_rollups', function (Blueprint $table) {
    $table->unsignedBigInteger('mem_used_bytes_sum')->nullable()->after('mem_used_pct_sum');
    $table->unsignedBigInteger('disk_used_bytes_sum')->nullable()->after('disk_used_pct_sum');
});
```

`down()` drops both columns. Add both columns to `MetricRollup::$fillable` and cast them to `integer`.

- [ ] **Step 4: Extend `RollupService`**

Add:

```php
private const BYTE_SUMS = [
    'mem_used_bytes_sum' => ['mem_used_bytes_sum', 'mem_used_bytes'],
    'disk_used_bytes_sum' => ['disk_used_bytes_sum', 'disk_used_bytes'],
];
```

In `fromSamples()` select `mem_used_bytes` and `disk_used_bytes`. In `add()` accumulate a byte target only when the row has a non-null source value; use a new `nullableValue()` helper rather than `value()` so a missing legacy value does not become `0`. In `emptyAggregate()` initialize both targets to `null`. Add both columns to the `persist()` upsert list.

- [ ] **Step 5: Run the focused tests**

Run:

```bash
./tools/test.sh --filter=RollupTest
```

Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_11_26_000100_add_byte_sums_to_metric_rollups_table.php app/Models/MetricRollup.php app/Services/Metrics/RollupService.php tests/Feature/Metrics/RollupTest.php
git commit -m "feat: add exact byte sums to metric rollups"
```

---

## Task 2: Expose memory and disk byte series

**Files:**
- Modify: `config/metrics.php`
- Modify: `app/Services/Metrics/MetricSeriesRepository.php`
- Test: `tests/Feature/Metrics/SeriesApiTest.php`

**Interfaces:**
- New metrics: `memory_bytes` (`mem_used_bytes` / `mem_used_bytes_sum`) and `disk_bytes` (`disk_used_bytes` / `disk_used_bytes_sum`).
- `MetricSeriesRepository::series()` returns the existing `{metric, range, resolution, points}` shape; points are bytes as floats.
- If a rollup row has `mem_used_bytes_sum`/`disk_used_bytes_sum` null, derive the value from `*_used_pct_sum / samples / 100 × latest total bytes`; if no total is available, omit the point.

- [ ] **Step 1: Write the failing series tests**

Add to `tests/Feature/Metrics/SeriesApiTest.php`:

```php
public function test_raw_memory_and_disk_byte_metrics_use_their_exact_columns(): void
{
    $this->actingAs(User::factory()->create());
    $server = $this->server();

    $this->sample($server, '2026-11-23 11:59:00', [
        'mem_used_bytes' => 1_500_000_000,
        'disk_used_bytes' => 20_000_000_000,
    ]);

    $this->assertSame(1_500_000_000.0, $this->getJson($this->url($server, 'memory_bytes', '1h'))->json('points.0.v'));
    $this->assertSame(20_000_000_000.0, $this->getJson($this->url($server, 'disk_bytes', '1h'))->json('points.0.v'));
}
```

Add a rollup test with `mem_used_bytes_sum` and `disk_used_bytes_sum`, and a legacy fallback test where the byte sum is null but `mem_used_pct_sum`/`disk_used_pct_sum` and the latest capacity are present. Assert the derived byte value.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `./tools/test.sh --filter=SeriesApiTest`
Expected: FAIL because `memory_bytes` and `disk_bytes` are unknown metrics.

- [ ] **Step 3: Add the metric configuration**

In `config/metrics.php`:

```php
'memory_bytes' => [
    'raw' => 'mem_used_bytes',
    'rollup' => 'mem_used_bytes_sum',
    'fallback_rollup' => 'mem_used_pct_sum',
    'total' => 'mem_total_bytes',
],
'disk_bytes' => [
    'raw' => 'disk_used_bytes',
    'rollup' => 'disk_used_bytes_sum',
    'fallback_rollup' => 'disk_used_pct_sum',
    'total' => 'disk_total_bytes',
],
```

- [ ] **Step 4: Teach the repository the fallback**

In `series()`, resolve the latest metric sample total once when `total` is configured. Pass the fallback column and total to `rollupPoints()`. For each rollup row:

```php
$value = $row->{$column}; // null for pre-migration byte sums

if ($value === null && $fallbackColumn !== null && $total !== null && isset($row->{$fallbackColumn})) {
    $value = ((float) $row->{$fallbackColumn} / $samples / 100) * $total;
}

if ($value === null) {
    continue;
}
```

Do not turn a missing byte value into `0`. Keep the existing weighted mean behavior for exact values.

- [ ] **Step 5: Run the focused tests**

Run:

```bash
./tools/test.sh --filter=SeriesApiTest
```

Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add config/metrics.php app/Services/Metrics/MetricSeriesRepository.php tests/Feature/Metrics/SeriesApiTest.php
git commit -m "feat: expose memory and disk byte series"
```

---

## Task 3: Add unit-aware chart formatting and axes

**Files:**
- Create: `resources/js/chart-format.js`
- Modify: `resources/js/chart-scale.js`
- Modify: `resources/js/charts.js`
- Modify: `resources/css/app.css`
- Test: `tests/js/chart-format.test.js`

**Interfaces:**
- `formatBytes(value): string` — `0 B`, `1 KB`, `1.5 MB`, `1 GB`, `1 TB` (base 1024).
- `formatRate(value): string` — `formatBytes(value) + '/s'`.
- `formatLoad(value): string` — two decimal places, no unit.
- `formatMetricValue(value, unit): string` where unit is `percent|bytes|rate|load`.
- `chartAxis(unit): object` — Chart.js scale options for the unit; `mixed` handled by the caller.
- Chart containers use `data-chart-unit="percent|bytes|rate|load|mixed"`.
- Mixed series carry `axis: "y"|"y1"` and `format: "load"|"rate"`.

- [ ] **Step 1: Write the failing JS tests**

Create `tests/js/chart-format.test.js`:

```js
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
```

- [ ] **Step 2: Run the JS test to verify it fails**

Run: `npm run test:js`
Expected: FAIL because `chart-format.js` does not exist.

- [ ] **Step 3: Implement `chart-format.js` and axis helpers**

Implement `formatBytes` with 1024-based units and sensible decimal places (zero decimals for whole numbers, one decimal for values below 10). Implement `formatRate`, `formatLoad`, and `formatMetricValue`.

Add `chartAxis(unit)` to `chart-scale.js`:
- `percent`: existing `percentageScaleBounds()`.
- `bytes`: `min: 0`, ticks callback `formatBytes`.
- `rate`: `min: 0`, ticks callback `formatRate`.
- `load`: `min: 0`, ticks callback `formatLoad`.
- `mixed`: the caller builds `y` (load) and `y1` (rate).

- [ ] **Step 4: Wire chart options to the container unit**

In `charts.js`:
- Read `data-chart-unit` from the chart element.
- Pass the unit and series metadata into `chartOptions(el, series)`.
- Build `y` from `chartAxis(unit)`.
- For `mixed`, add `y1` on the right with rate formatting and no grid.
- In `buildDatasets`, set `yAxisID` from `line.axis` and keep `line.format` for tooltips.
- Add a tooltip `label` callback that formats each dataset with `line.format ?? unit`.
- Keep the existing time-axis, gaps, single-point radius, and `fitChartToContainer` behavior.

- [ ] **Step 5: Add chart color tokens**

Add `--chart-load` and `--chart-network-out` to both theme blocks and to `@theme inline`:
- light: load `#475569`, network-out `#0f766e`
- dark: load `#cbd5e1`, network-out `#2dd4bf`

- [ ] **Step 6: Run JS tests and build**

Run:

```bash
npm run test:js
npm run build
```

Expected: JS tests PASS and Vite build succeeds.

- [ ] **Step 7: Commit**

```bash
git add resources/js/chart-format.js resources/js/chart-scale.js resources/js/charts.js resources/css/app.css tests/js/chart-format.test.js public/build public/build/manifest.json
git commit -m "feat: format chart axes by metric unit"
```

---

## Task 4: Add the reusable chart component and split Performance charts

**Files:**
- Create: `resources/views/components/series-chart.blade.php`
- Modify: `app/Livewire/Servers/Show.php`
- Modify: `resources/views/livewire/servers/show.blade.php`
- Test: `tests/Feature/Ui/ServerDetailTest.php`

**Interfaces:**
- `Show::charts(string $range): array` returns a keyed array of chart definitions:
  - Performance: `cpu`, `memory`, `disk`, `load`, `network`.
  - Overview: `resources`, `load-network`.
- Each definition contains `key`, `title`, `subtitle`, `unit`, `series`, `summary`.
- Each series contains `metric`, `label`, `color`, `url`, and optional `axis`/`format`.
- `<x-series-chart :chart="$chart" :sample="$sample" :interval="$server->interval_seconds" />` renders one figure with the existing chart states.

- [ ] **Step 1: Write the failing Performance rendering test**

Add to `tests/Feature/Ui/ServerDetailTest.php`:

```php
public function test_performance_tab_renders_five_unit_aware_charts(): void
{
    $viewer = User::factory()->create();
    $server = $this->server();
    $this->sample($server);

    $response = $this->actingAs($viewer)->get(route('servers.show', [
        'server' => $server,
        'tab' => 'performance',
        'range' => '7d',
    ]));

    $response->assertOk()
        ->assertSee('data-chart-key="cpu"', false)
        ->assertSee('data-chart-unit="percent"', false)
        ->assertSee('data-chart-key="memory"', false)
        ->assertSee('data-chart-unit="bytes"', false)
        ->assertSee('data-chart-key="disk"', false)
        ->assertSee('data-chart-key="load"', false)
        ->assertSee('data-chart-key="network"', false)
        ->assertSee('metric=memory_bytes', false)
        ->assertSee('metric=disk_bytes', false)
        ->assertSee('metric=rx', false)
        ->assertSee('metric=tx', false)
        ->assertSee('range=7d', false);
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `./tools/test.sh --filter=ServerDetailTest`
Expected: FAIL because there is still one combined chart.

- [ ] **Step 3: Implement `Show::charts()`**

Build the chart definitions with exact URLs:
- CPU: `[cpu]`, unit `percent`, color `--chart-cpu`.
- Memory: `[memory_bytes]`, unit `bytes`, color `--chart-memory`.
- Disk: `[disk_bytes]`, unit `bytes`, color `--chart-disk`.
- Load: `[load]`, unit `load`, color `--chart-load`.
- Network: `[rx, tx]`, unit `rate`, colors `--chart-network`, `--chart-network-out`.
- Overview resources: `[cpu, memory, disk]`, unit `percent` (overview keeps the compact percentage comparison).
- Overview load-network: `[load, rx, tx]`, unit `mixed`; load uses `axis: y`, `format: load`; rx/tx use `axis: y1`, `format: rate`.

Use the existing `series` route with the correct metric and range. Keep the current tab/range normalization.

- [ ] **Step 4: Implement the Blade component**

The component must render:
- `<figure data-chart-key="...">`
- a heading and subtitle
- a legend with a colored hairline per series
- a `data-series-chart` container with `data-chart-unit`, `data-series`, `data-interval`, `data-chart-has-sample`, `aria-busy`
- `data-chart-status`, `data-chart-error`, retry button, empty overlay, and `noscript`
- one summary sentence below

Keep the existing nonce/CSP-safe JSON encoding and `role="img"` canvas labels. Use a 240–280px height for Performance charts and 280px for Overview.

- [ ] **Step 5: Replace the Performance markup with five components**

In the `performance` tab, render the 5 chart definitions in a responsive single-column stack (or two columns on very wide screens) with the existing range links, data-as-of line, and the note that missing data stays blank.

Replace the old `test_the_chart_container_carries_each_series_url_and_the_selected_range` test with the new five-chart test above. Do not keep assertions that expect one combined Performance container or a single `data-series-url` on that tab.

- [ ] **Step 6: Run focused tests and build**

Run:

```bash
./tools/test.sh --filter=ServerDetailTest
npm run build
```

Expected: PASS and build success.

- [ ] **Step 7: Commit**

```bash
git add resources/views/components/series-chart.blade.php app/Livewire/Servers/Show.php resources/views/livewire/servers/show.blade.php tests/Feature/Ui/ServerDetailTest.php public/build public/build/manifest.json
git commit -m "feat: split server performance into unit-aware charts"
```

---

## Task 5: Add Overview load and network chart

**Files:**
- Modify: `resources/views/livewire/servers/show.blade.php`
- Modify: `tests/Feature/Ui/ServerDetailTest.php`

**Interfaces:**
- Overview renders the existing `resources` chart plus a `load-network` chart with unit `mixed`.
- The mixed chart has a left load axis (`y`) and a right network rate axis (`y1`).

- [ ] **Step 1: Write the failing Overview test**

```php
public function test_overview_adds_a_combined_load_and_network_chart(): void
{
    $viewer = User::factory()->create();
    $server = $this->server();
    $this->sample($server);

    $this->actingAs($viewer)
        ->get(route('servers.show', ['server' => $server, 'tab' => 'overview']))
        ->assertOk()
        ->assertSee('data-chart-key="resources"', false)
        ->assertSee('data-chart-key="load-network"', false)
        ->assertSee('data-chart-unit="mixed"', false)
        ->assertSee('metric=load', false)
        ->assertSee('metric=rx', false)
        ->assertSee('metric=tx', false);
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `./tools/test.sh --filter=ServerDetailTest`
Expected: FAIL because Overview only renders the resource chart.

- [ ] **Step 3: Render the second Overview chart**

Replace the Overview inline chart block with `<x-series-chart>` for `resources`, then add `<x-series-chart>` for `load-network` in the same section. Keep the existing 1-hour overview range and the active issues/server facts aside. Update the chart summary copy to mention load and network.

- [ ] **Step 4: Run focused tests and build**

Run:

```bash
./tools/test.sh --filter=ServerDetailTest
npm run test:js
npm run build
```

Expected: all PASS and build success.

- [ ] **Step 5: Commit**

```bash
git add resources/views/livewire/servers/show.blade.php tests/Feature/Ui/ServerDetailTest.php public/build public/build/manifest.json
git commit -m "feat: add load and network to server overview"
```

---

## Task 6: Full verification and release preparation

**Files:**
- No production files unless a verification failure requires one.

- [ ] **Step 1: Run the full PHP and JS suites**

Run:

```bash
./tools/test.sh
npm run test:js
```

Expected: Pint clean; PHP suite passes; JS suite passes. Record exact counts.

- [ ] **Step 2: Build production assets**

Run: `npm run build`
Expected: success; `public/build/manifest.json` points at the new asset hashes.

- [ ] **Step 3: Verify both realtime modes**

Run the server-detail and overview tests with `NOC_REALTIME=false` and `NOC_REALTIME=true`; confirm the chart containers still render and the fallback poll remains.

- [ ] **Step 4: Manual smoke test**

On a server with samples:
- Performance shows CPU %, Memory bytes, Disk bytes, Load, and Network in/out.
- Overview shows the resource chart plus the combined load/network chart.
- Switch 1h → 7d → 30d; byte charts remain populated (exact or derived) and gaps stay blank.
- Trigger a live sample; all charts update without clipping or losing the range.
- Toggle light/dark; chart colors remain legible.

- [ ] **Step 5: Commit**

If verification required no changes, do not create an empty commit. If it did, commit the fix separately with a focused message.

- [ ] **Step 6: Report**

Report: commit list, PHP/JS test counts, build asset, manual smoke result, and any deferred data caveat (pre-migration byte rollups use percentage × current capacity).

---

## Self-Review

- [ ] Performance has separate CPU, Memory, Disk, Load, and Network charts.
- [ ] CPU is the only percent axis; memory/disk use bytes; network uses bytes/s; load is numeric.
- [ ] Overview keeps the combined resource chart and adds a combined load/network chart with correct axes.
- [ ] Exact bytes are used for raw samples and newly written rollups; legacy rollups fall back without zero-filling.
- [ ] Every chart retains loading, empty, error, retry, and no-JS states.
- [ ] Realtime refresh updates all charts and preserves the selected range.
- [ ] Light/dark colors and single-point visibility are covered.
