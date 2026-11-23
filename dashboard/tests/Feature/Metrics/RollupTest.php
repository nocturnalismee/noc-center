<?php

namespace Tests\Feature\Metrics;

use App\Models\MetricRollup;
use App\Models\MetricSample;
use App\Models\Server;
use App\Services\Metrics\RollupService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Task 11: 5m/1h/1d metric rollups and backfill (spec §7.6, §11).
 *
 * Buckets are epoch-aligned UTC. `5m` is built from `metric_samples`, `1h`
 * from `5m`, and `1d` from `1h` — never a coarser bucket straight from raw
 * samples. Averages are always `sum / samples`; buckets without source rows
 * are absent rather than zero-filled; the current incomplete bucket is never
 * written; reruns upsert in place.
 */
class RollupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-23 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Aggregation from raw samples (5m)
    // ------------------------------------------------------------------

    public function test_five_minute_buckets_align_to_utc_boundaries_and_aggregate_correctly(): void
    {
        $server = $this->server();

        Carbon::setTestNow('2026-11-23 12:20:00');

        // 12:00:00 belongs to 12:00; 12:04:59 still belongs to 12:00;
        // 12:05:00 opens the next bucket.
        $this->sample($server, '2026-11-23 11:59:59', ['cpu_usage_pct' => 10]);
        $this->sample($server, '2026-11-23 12:00:00', [
            'cpu_usage_pct' => 40, 'cpu_iowait_pct' => 2, 'mem_used_pct' => 50,
            'disk_used_pct' => 70, 'load1' => 1.5, 'net_rx_bps' => 1000, 'net_tx_bps' => 500,
        ]);
        $this->sample($server, '2026-11-23 12:04:59', [
            'cpu_usage_pct' => 20, 'cpu_iowait_pct' => 4, 'mem_used_pct' => 60,
            'disk_used_pct' => 80, 'load1' => 2.5, 'net_rx_bps' => 3000, 'net_tx_bps' => 1500,
        ]);
        $this->sample($server, '2026-11-23 12:05:00', [
            'cpu_usage_pct' => 30, 'cpu_iowait_pct' => 6, 'mem_used_pct' => 55,
            'disk_used_pct' => 75, 'load1' => 0.5, 'net_rx_bps' => 2000, 'net_tx_bps' => 1000,
        ]);
        $this->sample($server, '2026-11-23 12:10:00', ['cpu_usage_pct' => 99]);

        $written = $this->service()->rollup(
            $server,
            300,
            CarbonImmutable::parse('2026-11-23 10:00:00', 'UTC'),
            CarbonImmutable::parse('2026-11-23 13:00:00', 'UTC'),
        );

        $this->assertSame(4, $written);

        $rows = $this->rollups($server, 300);
        $this->assertSame([
            '2026-11-23 11:55:00',
            '2026-11-23 12:00:00',
            '2026-11-23 12:05:00',
            '2026-11-23 12:10:00',
        ], $rows->map(fn (MetricRollup $r): string => $r->bucket_start->utc()->format('Y-m-d H:i:s'))->all());

        $bucket = $rows->first(fn (MetricRollup $r): bool => $r->bucket_start->utc()->format('H:i') === '12:00');

        $this->assertNotNull($bucket);
        $this->assertSame(300, $bucket->resolution_seconds);
        $this->assertSame(2, $bucket->samples);
        // Average is sum / samples, never averaged twice.
        $this->assertEqualsWithDelta(30.0, $bucket->cpu_usage_sum / $bucket->samples, 0.001);
        $this->assertEqualsWithDelta(60.0, $bucket->cpu_usage_sum, 0.001);
        $this->assertEqualsWithDelta(6.0, $bucket->cpu_iowait_sum, 0.001);
        $this->assertEqualsWithDelta(110.0, $bucket->mem_used_pct_sum, 0.001);
        $this->assertEqualsWithDelta(150.0, $bucket->disk_used_pct_sum, 0.001);
        $this->assertEqualsWithDelta(4.0, $bucket->load1_sum, 0.001);
        $this->assertSame(4000, $bucket->net_rx_bps_sum);
        $this->assertSame(2000, $bucket->net_tx_bps_sum);
        $this->assertEqualsWithDelta(20.0, $bucket->cpu_usage_min, 0.001);
        $this->assertEqualsWithDelta(40.0, $bucket->cpu_usage_max, 0.001);
        $this->assertEqualsWithDelta(50.0, $bucket->mem_used_pct_min, 0.001);
        $this->assertEqualsWithDelta(60.0, $bucket->mem_used_pct_max, 0.001);
        $this->assertEqualsWithDelta(70.0, $bucket->disk_used_pct_min, 0.001);
        $this->assertEqualsWithDelta(80.0, $bucket->disk_used_pct_max, 0.001);
        $this->assertEqualsWithDelta(2.5, $bucket->load1_max, 0.001);
    }

    public function test_hour_and_day_buckets_align_to_utc_boundaries(): void
    {
        $server = $this->server();

        // Two days of one sample per hour, enough for hourly and daily buckets.
        for ($hour = 0; $hour < 48; $hour++) {
            $start = CarbonImmutable::parse('2026-11-20 00:00:00', 'UTC')->addHours($hour);
            $this->sample($server, $start->format('Y-m-d H:i:s'), ['cpu_usage_pct' => 10]);
        }

        $this->service()->rollup(
            $server,
            300,
            CarbonImmutable::parse('2026-11-20 00:00:00', 'UTC'),
            CarbonImmutable::parse('2026-11-22 00:00:00', 'UTC'),
        );
        $this->service()->rollup(
            $server,
            3600,
            CarbonImmutable::parse('2026-11-20 00:00:00', 'UTC'),
            CarbonImmutable::parse('2026-11-22 00:00:00', 'UTC'),
        );
        $this->service()->rollup(
            $server,
            86400,
            CarbonImmutable::parse('2026-11-20 00:00:00', 'UTC'),
            CarbonImmutable::parse('2026-11-22 00:00:00', 'UTC'),
        );

        $hours = $this->rollups($server, 3600);
        $this->assertCount(48, $hours);
        $this->assertSame('2026-11-20 00:00:00', $hours[0]->bucket_start->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-21 23:00:00', $hours->last()->bucket_start->utc()->format('Y-m-d H:i:s'));

        $days = $this->rollups($server, 86400);
        $this->assertSame([
            '2026-11-20 00:00:00',
            '2026-11-21 00:00:00',
        ], $days->map(fn (MetricRollup $r): string => $r->bucket_start->utc()->format('Y-m-d H:i:s'))->all());
        $this->assertSame(24, $days[0]->samples);
        $this->assertSame(24, $days[1]->samples);
    }

    public function test_buckets_without_source_rows_stay_absent(): void
    {
        $server = $this->server();

        $this->sample($server, '2026-11-23 10:00:00', ['cpu_usage_pct' => 10]);
        $this->sample($server, '2026-11-23 10:30:00', ['cpu_usage_pct' => 20]);

        $written = $this->service()->rollup(
            $server,
            300,
            CarbonImmutable::parse('2026-11-23 09:00:00', 'UTC'),
            CarbonImmutable::parse('2026-11-23 11:00:00', 'UTC'),
        );

        $this->assertSame(2, $written);
        $this->assertSame(2, MetricRollup::query()->count());
        $this->assertFalse(MetricRollup::query()
            ->where('bucket_start', '2026-11-23 10:05:00')
            ->exists());
    }

    public function test_incomplete_current_bucket_is_never_written(): void
    {
        $server = $this->server();

        // The clock is 12:00:00, so 12:00–12:05 is incomplete.
        $this->sample($server, '2026-11-23 11:55:00', ['cpu_usage_pct' => 10]);
        $this->sample($server, '2026-11-23 12:00:00', ['cpu_usage_pct' => 20]);

        $this->service()->rollup(
            $server,
            300,
            CarbonImmutable::parse('2026-11-23 10:00:00', 'UTC'),
            CarbonImmutable::parse('2026-11-23 13:00:00', 'UTC'),
        );

        $this->assertSame(1, MetricRollup::query()->count());
        $this->assertSame('2026-11-23 11:55:00', MetricRollup::query()->sole()->bucket_start->utc()->format('Y-m-d H:i:s'));
    }

    public function test_a_sample_exactly_at_the_window_end_is_excluded(): void
    {
        $server = $this->server();

        // `[11:55, 12:00)` overlaps exactly the 11:55 bucket.
        $this->sample($server, '2026-11-23 11:55:00', ['cpu_usage_pct' => 10]);
        $this->sample($server, '2026-11-23 11:59:59', ['cpu_usage_pct' => 20]);
        $this->sample($server, '2026-11-23 12:00:00', ['cpu_usage_pct' => 30]);

        $this->service()->rollup(
            $server,
            300,
            CarbonImmutable::parse('2026-11-23 11:55:00', 'UTC'),
            CarbonImmutable::parse('2026-11-23 12:00:00', 'UTC'),
        );

        $bucket = MetricRollup::query()->sole();
        $this->assertSame('2026-11-23 11:55:00', $bucket->bucket_start->utc()->format('Y-m-d H:i:s'));
        $this->assertSame(2, $bucket->samples);
        $this->assertEqualsWithDelta(30.0, $bucket->cpu_usage_sum, 0.001);
    }

    // ------------------------------------------------------------------
    // Idempotency and late data
    // ------------------------------------------------------------------

    public function test_rerunning_the_same_range_is_idempotent(): void
    {
        $server = $this->server();

        $this->sample($server, '2026-11-23 11:55:00', ['cpu_usage_pct' => 10]);
        $this->sample($server, '2026-11-23 11:56:00', ['cpu_usage_pct' => 20]);

        $from = CarbonImmutable::parse('2026-11-23 11:00:00', 'UTC');
        $to = CarbonImmutable::parse('2026-11-23 12:00:00', 'UTC');

        $this->assertSame(1, $this->service()->rollup($server, 300, $from, $to));
        $first = MetricRollup::query()->sole();
        $before = $first->only([
            'samples', 'cpu_usage_sum', 'cpu_usage_min', 'cpu_usage_max', 'updated_at',
        ]);

        $this->assertSame(1, $this->service()->rollup($server, 300, $from, $to));

        $this->assertSame(1, MetricRollup::query()->count());
        $after = MetricRollup::query()->sole();
        $this->assertSame($first->id, $after->id);
        $this->assertSame($before['samples'], $after->only(['samples'])['samples']);
        $this->assertEqualsWithDelta($before['cpu_usage_sum'], $after->cpu_usage_sum, 0.001);
        $this->assertEqualsWithDelta($before['cpu_usage_min'], $after->cpu_usage_min, 0.001);
        $this->assertEqualsWithDelta($before['cpu_usage_max'], $after->cpu_usage_max, 0.001);
    }

    public function test_a_late_sample_inside_the_recompute_window_changes_the_bucket(): void
    {
        $server = $this->server();

        $this->sample($server, '2026-11-23 11:55:00', ['cpu_usage_pct' => 10]);

        $from = CarbonImmutable::parse('2026-11-23 11:00:00', 'UTC');
        $to = CarbonImmutable::parse('2026-11-23 12:00:00', 'UTC');
        $this->service()->rollup($server, 300, $from, $to);

        $this->assertSame(1, MetricRollup::query()->sole()->samples);

        // The agent retries late with an older `collected_at` inside the bucket.
        $this->sample($server, '2026-11-23 11:57:00', ['cpu_usage_pct' => 50]);

        $this->assertSame(1, $this->service()->rollup($server, 300, $from, $to));

        $bucket = MetricRollup::query()->sole();
        $this->assertSame(1, MetricRollup::query()->count());
        $this->assertSame(2, $bucket->samples);
        $this->assertEqualsWithDelta(60.0, $bucket->cpu_usage_sum, 0.001);
        $this->assertEqualsWithDelta(10.0, $bucket->cpu_usage_min, 0.001);
        $this->assertEqualsWithDelta(50.0, $bucket->cpu_usage_max, 0.001);
    }

    // ------------------------------------------------------------------
    // Derived rollups (1h from 5m, 1d from 1h)
    // ------------------------------------------------------------------

    public function test_hourly_buckets_are_weighted_from_five_minute_buckets(): void
    {
        $server = $this->server();

        // 11:00 bucket: two samples at 10 and 20; 11:05 bucket: one sample at 90.
        $this->sample($server, '2026-11-23 11:00:00', ['cpu_usage_pct' => 10, 'load1' => 1.0]);
        $this->sample($server, '2026-11-23 11:01:00', ['cpu_usage_pct' => 20, 'load1' => 4.0]);
        $this->sample($server, '2026-11-23 11:05:00', ['cpu_usage_pct' => 90, 'load1' => 2.0]);
        // A sample in the next hour makes its own 5m bucket, which must not
        // leak into the 11:00 hour.
        $this->sample($server, '2026-11-23 12:00:00', ['cpu_usage_pct' => 100, 'load1' => 9.0]);

        // A bucket only reaches the hourly rollup once it is whole.
        $from = CarbonImmutable::parse('2026-11-23 10:00:00', 'UTC');
        $to = CarbonImmutable::parse('2026-11-23 13:00:00', 'UTC');
        $this->service()->rollup($server, 300, $from, $to);
        $this->assertSame(1, $this->service()->rollup($server, 3600, $from, $to));

        $hour = $this->rollups($server, 3600)->sole();
        $this->assertNotNull($hour);
        $this->assertSame('2026-11-23 11:00:00', $hour->bucket_start->utc()->format('Y-m-d H:i:s'));
        $this->assertSame(3, $hour->samples);
        $this->assertEqualsWithDelta(120.0, $hour->cpu_usage_sum, 0.001);
        // Weighted, not average of the 5m means (15 and 90 → 52.5).
        $this->assertEqualsWithDelta(40.0, $hour->cpu_usage_sum / $hour->samples, 0.001);
        $this->assertEqualsWithDelta(10.0, $hour->cpu_usage_min, 0.001);
        $this->assertEqualsWithDelta(90.0, $hour->cpu_usage_max, 0.001);
        $this->assertEqualsWithDelta(7.0, $hour->load1_sum, 0.001);
        $this->assertEqualsWithDelta(4.0, $hour->load1_max, 0.001);
    }

    public function test_daily_buckets_are_weighted_from_hourly_buckets(): void
    {
        $server = $this->server();

        // 2026-11-21: hour 00 has one sample at 10; hour 01 has three samples at 30 each.
        $this->sample($server, '2026-11-21 00:00:00', ['cpu_usage_pct' => 10]);
        $this->sample($server, '2026-11-21 01:00:00', ['cpu_usage_pct' => 30]);
        $this->sample($server, '2026-11-21 01:10:00', ['cpu_usage_pct' => 30]);
        $this->sample($server, '2026-11-21 01:20:00', ['cpu_usage_pct' => 30]);

        $from = CarbonImmutable::parse('2026-11-20 00:00:00', 'UTC');
        $to = CarbonImmutable::parse('2026-11-23 00:00:00', 'UTC');
        $this->service()->rollup($server, 300, $from, $to);
        $this->service()->rollup($server, 3600, $from, $to);
        $this->service()->rollup($server, 86400, $from, $to);

        $day = $this->rollups($server, 86400)->sole();
        $this->assertSame('2026-11-21 00:00:00', $day->bucket_start->utc()->format('Y-m-d H:i:s'));
        $this->assertSame(4, $day->samples);
        $this->assertEqualsWithDelta(100.0, $day->cpu_usage_sum, 0.001);
        // Match calendar-hour means (420/24 = 17.5) rather than hour means (20).
        $this->assertEqualsWithDelta(25.0, $day->cpu_usage_sum / $day->samples, 0.001);
        $this->assertEqualsWithDelta(10.0, $day->cpu_usage_min, 0.001);
        $this->assertEqualsWithDelta(30.0, $day->cpu_usage_max, 0.001);
    }

    public function test_hourly_rollup_is_not_built_from_raw_samples_when_five_minute_is_absent(): void
    {
        $server = $this->server();

        $this->sample($server, '2026-11-23 11:00:00', ['cpu_usage_pct' => 10]);
        $this->sample($server, '2026-11-23 11:05:00', ['cpu_usage_pct' => 20]);

        $written = $this->service()->rollup(
            $server,
            3600,
            CarbonImmutable::parse('2026-11-23 10:00:00', 'UTC'),
            CarbonImmutable::parse('2026-11-23 12:00:00', 'UTC'),
        );

        $this->assertSame(0, $written);
        $this->assertSame(0, MetricRollup::query()->where('resolution_seconds', 3600)->count());
    }

    // ------------------------------------------------------------------
    // Command
    // ------------------------------------------------------------------

    public function test_the_command_runs_every_resolution_in_dependency_order(): void
    {
        $server = $this->server();

        // Clock 12:00. 5m covers the last 12 completed buckets (11:00–12:00),
        // 1h the last 3 (09:00–12:00), 1d the last 2 (11-21, 11-22).
        $this->sample($server, '2026-11-23 11:02:00', ['cpu_usage_pct' => 10]);
        // Seed an earlier day's 5m so the hourly chain can build 11-22.
        $this->sample($server, '2026-11-22 10:00:00', ['cpu_usage_pct' => 20]);
        $this->service()->rollup(
            $server,
            300,
            CarbonImmutable::parse('2026-11-22 10:00:00', 'UTC'),
            CarbonImmutable::parse('2026-11-22 11:00:00', 'UTC'),
        );
        $this->service()->rollup(
            $server,
            3600,
            CarbonImmutable::parse('2026-11-22 09:00:00', 'UTC'),
            CarbonImmutable::parse('2026-11-22 11:00:00', 'UTC'),
        );
        $this->service()->rollup(
            $server,
            86400,
            CarbonImmutable::parse('2026-11-21 00:00:00', 'UTC'),
            CarbonImmutable::parse('2026-11-23 00:00:00', 'UTC'),
        );

        $this->artisan('noc:rollup')
            // The command's window is its widest level's (1d: 11-21 00:00 →
            // 11-23 00:00), applied to every level in the chain.
            ->expectsOutputToContain('300s: 2 bucket(s)')
            ->expectsOutputToContain('3600s: 2 bucket(s)')
            ->expectsOutputToContain('86400s: 1 bucket(s)')
            ->assertSuccessful();

        // Seeded: 11-22 5m, 11-22 1h, 11-22 1d. The run re-upserts those and
        // adds today's 11:00 5m and 1h. The 11-21 day bucket is not built: its
        // only hourly bucket (the hand-seeded 10:00) sat in 11-22.
        $this->assertSame(2, MetricRollup::query()->where('resolution_seconds', 300)->count());
        $this->assertSame(2, MetricRollup::query()->where('resolution_seconds', 3600)->count());
        $this->assertSame(1, MetricRollup::query()->where('resolution_seconds', 86400)->count());
        $this->assertSame(1, MetricRollup::query()
            ->where('resolution_seconds', 300)
            ->where('bucket_start', '2026-11-23 11:00:00')
            ->count());
        $this->assertSame('2026-11-22 00:00:00', MetricRollup::query()
            ->where('resolution_seconds', 86400)->sole()->bucket_start->utc()->format('Y-m-d H:i:s'));
    }

    public function test_the_command_accepts_an_explicit_resolution_and_range(): void
    {
        $server = $this->server();

        $this->sample($server, '2026-11-20 05:00:00', ['cpu_usage_pct' => 10]);

        $this->artisan('noc:rollup', [
            '--resolution' => '300',
            '--from' => '2026-11-20T04:00:00Z',
            '--to' => '2026-11-20T06:00:00Z',
        ])->assertSuccessful();

        $bucket = MetricRollup::query()->sole();
        $this->assertSame('2026-11-20 05:00:00', $bucket->bucket_start->utc()->format('Y-m-d H:i:s'));
        $this->assertSame(1, $bucket->samples);
    }

    public function test_the_command_rejects_an_invalid_range_or_resolution(): void
    {
        $this->server();

        $this->artisan('noc:rollup', [
            '--from' => '2026-11-20T06:00:00Z',
            '--to' => '2026-11-20T04:00:00Z',
        ])->assertFailed();

        $this->artisan('noc:rollup', ['--resolution' => '60'])->assertFailed();
    }

    public function test_backfill_days_with_derived_resolutions_rebuilds_the_chain(): void
    {
        $server = $this->server();

        // Clock 2026-11-23 12:00. Backfill 2 days reaches 2026-11-21 12:00, so both
        // samples sit inside the window and in complete days (11-23 is running).
        $this->sample($server, '2026-11-21 14:00:00', ['cpu_usage_pct' => 10]);
        $this->sample($server, '2026-11-22 10:00:00', ['cpu_usage_pct' => 20]);

        $this->artisan('noc:rollup', ['--backfill-days' => '2'])
            // 11-21 and 11-22 samples, two days back from 12:00.
            ->expectsOutputToContain('300s: 2 bucket(s)')
            ->expectsOutputToContain('3600s: 2 bucket(s)')
            ->expectsOutputToContain('86400s: 2 bucket(s)')
            ->assertSuccessful();

        $this->assertSame(2, MetricRollup::query()->where('resolution_seconds', 300)->count());
        $this->assertSame(2, MetricRollup::query()->where('resolution_seconds', 3600)->count());
        $this->assertSame(2, MetricRollup::query()->where('resolution_seconds', 86400)->count());
        $this->assertSame(0, MetricRollup::query()
            ->where('resolution_seconds', 86400)
            ->where('bucket_start', '2026-11-23 00:00:00')
            ->count());
    }

    public function test_the_command_can_be_limited_to_one_server(): void
    {
        $wanted = $this->server();
        $other = $this->server();

        $this->sample($wanted, '2026-11-23 11:55:00', ['cpu_usage_pct' => 10]);
        $this->sample($other, '2026-11-23 11:55:00', ['cpu_usage_pct' => 20]);

        $this->artisan('noc:rollup', ['--server' => $wanted->getKey(), '--resolution' => '300'])
            ->assertSuccessful();

        $this->assertSame(1, MetricRollup::query()->count());
        $this->assertSame($wanted->getKey(), MetricRollup::query()->sole()->server_id);
    }

    public function test_the_command_fails_for_an_unknown_server_id(): void
    {
        $this->server();

        $this->artisan('noc:rollup', ['--server' => (string) Str::ulid()])
            ->expectsOutputToContain('No server found')
            ->assertFailed();

        $this->artisan('noc:rollup', ['--server' => 'not-a-ulid'])
            ->expectsOutputToContain('No server found')
            ->assertFailed();
    }

    public function test_the_command_chunks_servers_by_ulid_without_truncating_numeric_keys(): void
    {
        // ULIDs start with digits, so a numeric-string comparison would order
        // them differently from the string comparison the keys actually use.
        $servers = [];
        foreach (['9', '0', '5'] as $i => $prefix) {
            $server = $this->server();
            $server->forceFill(['id' => $prefix.Str::ulid()->toBase32()])->save();
            $servers[] = $server;
        }

        $this->assertTrue(ctype_digit((string) $servers[0]->getKey()[0]));

        foreach ($servers as $i => $server) {
            $this->sample($server, '2026-11-23 11:55:00', ['cpu_usage_pct' => 10 + $i]);
        }

        $this->artisan('noc:rollup', ['--resolution' => '300'])->assertSuccessful();

        $this->assertSame(3, MetricRollup::query()->count());
        foreach ($servers as $i => $server) {
            $rollup = MetricRollup::query()->where('server_id', $server->getKey())->sole();
            $this->assertEqualsWithDelta(10.0 + $i, $rollup->cpu_usage_sum, 0.001);
        }
    }

    // ------------------------------------------------------------------
    // Date range parsing
    // ------------------------------------------------------------------

    public function test_the_command_accepts_every_documented_timestamp_form(): void
    {
        $server = $this->server();

        // One sample per 5m bucket across the same calendar window; every
        // equivalent spelling of the range must select it.
        $this->sample($server, '2026-11-20 05:00:00', ['cpu_usage_pct' => 10]);

        $forms = [
            ['2026-11-20', '2026-11-21'],
            ['2026-11-20 04:00', '2026-11-20 06:00'],
            ['2026-11-20 04:00:00', '2026-11-20 06:00:00'],
            ['2026-11-20T04:00', '2026-11-20T06:00'],
            ['2026-11-20T04:00:00', '2026-11-20T06:00:00'],
            ['2026-11-20T04:00Z', '2026-11-20T06:00Z'],
            ['2026-11-20T04:00:00Z', '2026-11-20T06:00:00Z'],
            ['2026-11-20T04:00:00.5Z', '2026-11-20T06:00:00.5Z'],
            ['2026-11-19T04:00:00.123456Z', '2026-11-20T06:00:00.123456Z'],
        ];

        foreach ($forms as [$from, $to]) {
            MetricRollup::query()->delete();

            $this->artisan('noc:rollup', [
                '--resolution' => '300',
                '--from' => $from,
                '--to' => $to,
            ])->assertSuccessful();

            $bucket = MetricRollup::query()->sole();
            $this->assertSame(
                '2026-11-20 05:00:00',
                $bucket->bucket_start->utc()->format('Y-m-d H:i:s'),
                sprintf('Range [%s, %s) did not select 05:00.', $from, $to),
            );
        }
    }

    public function test_the_command_accepts_a_leap_day_and_converts_offsets_to_utc(): void
    {
        $server = $this->server();

        // 23:00 is itself a bucket edge, so a neighbour sample would add a
        // second bucket; one sample inside the leap day keeps the assert exact.
        $this->sample($server, '2024-02-28 23:00:00', ['cpu_usage_pct' => 10]);
        $this->sample($server, '2024-02-29 05:00:00', ['cpu_usage_pct' => 20]);
        $this->sample($server, '2024-03-01 00:00:00', ['cpu_usage_pct' => 30]);

        $this->artisan('noc:rollup', [
            '--resolution' => '300',
            '--from' => '2024-02-29',
            '--to' => '2024-03-01',
        ])->assertSuccessful();

        $this->assertSame('2024-02-29 05:00:00', MetricRollup::query()->sole()->bucket_start->utc()->format('Y-m-d H:i:s'));

        // +07:00 12:00 == 05:00 UTC: a one-offset range selects the same bucket.
        $this->artisan('noc:rollup', [
            '--resolution' => '300',
            '--from' => '2024-02-29T12:00:00+07:00',
            '--to' => '2024-02-29T12:05:00+07:00',
        ])->assertSuccessful();

        $this->assertSame(1, MetricRollup::query()->count());
    }

    public function test_the_command_rejects_invalid_timestamps_without_a_stack_trace(): void
    {
        $this->server();

        $invalid = [
            '2026-02-31',            // April 31 etc. do not exist
            '2026-13-01',
            '2026-11-20junk',        // trailing junk
            '2026-11-20T25:00:00Z',
            '2026-11-20T04:00:00Zjunk',
            '2025-02-29',            // not a leap year
            ' 2026-11-20',
            'yesterday',
        ];

        foreach ($invalid as $value) {
            $this->artisan('noc:rollup', ['--from' => $value, '--to' => '2026-11-21T00:00:00Z'])
                ->expectsOutputToContain('--from must be a valid UTC date')
                ->assertFailed();

            $this->artisan('noc:rollup', ['--from' => '2026-11-20T00:00:00Z', '--to' => $value])
                ->expectsOutputToContain('--to must be a valid UTC date')
                ->assertFailed();
        }

        $this->assertSame(0, MetricRollup::query()->count());
    }

    public function test_the_command_requires_both_range_options_together(): void
    {
        $this->server();

        $this->artisan('noc:rollup', ['--from' => '2026-11-20T04:00:00Z'])
            ->expectsOutputToContain('both --from and --to are required')
            ->assertFailed();

        $this->artisan('noc:rollup', ['--to' => '2026-11-20T06:00:00Z'])
            ->expectsOutputToContain('both --from and --to are required')
            ->assertFailed();

        // Also when combined with an otherwise valid resolution.
        $this->artisan('noc:rollup', ['--resolution' => '300', '--from' => '2026-11-20T04:00:00Z'])
            ->expectsOutputToContain('both --from and --to are required')
            ->assertFailed();
    }

    public function test_the_command_rejects_a_range_whose_start_is_not_before_its_end(): void
    {
        $this->server();

        $this->artisan('noc:rollup', [
            '--from' => '2026-11-20T06:00:00Z',
            '--to' => '2026-11-20T06:00:00Z',
        ])->expectsOutputToContain('--from must be before --to')->assertFailed();

        $this->artisan('noc:rollup', [
            '--from' => '2026-11-20T07:00:00Z',
            '--to' => '2026-11-20T06:00:00Z',
        ])->expectsOutputToContain('--from must be before --to')->assertFailed();
    }

    // ------------------------------------------------------------------
    // Cascading coarse rollups
    // ------------------------------------------------------------------

    public function test_selecting_an_hourly_rollup_also_rebuilds_its_five_minute_source(): void
    {
        $server = $this->server();

        $this->sample($server, '2026-11-23 11:00:00', ['cpu_usage_pct' => 10]);
        $this->sample($server, '2026-11-23 11:05:00', ['cpu_usage_pct' => 20]);

        // A late sample lands after the hour has begun but before this run.
        $this->sample($server, '2026-11-23 11:55:00', ['cpu_usage_pct' => 60]);

        $this->artisan('noc:rollup', ['--resolution' => '3600'])
            ->assertSuccessful();

        // 1h must exist even though 5m was never computed by a previous run.
        $hour = MetricRollup::query()->where('resolution_seconds', 3600)->sole();
        $this->assertSame(3, $hour->samples);
        $this->assertEqualsWithDelta(90.0, $hour->cpu_usage_sum, 0.001);

        // The 5m level was rebuilt first, so the hour is weighted from
        // complete 5m data (including the late sample).
        $this->assertSame(3, MetricRollup::query()->where('resolution_seconds', 300)->count());
    }

    public function test_selecting_a_daily_rollup_cascades_through_hourly_to_five_minute(): void
    {
        $server = $this->server();

        $this->sample($server, '2026-11-22 10:00:00', ['cpu_usage_pct' => 10]);
        $this->sample($server, '2026-11-22 10:30:00', ['cpu_usage_pct' => 30]);

        $this->artisan('noc:rollup', ['--resolution' => '86400'])
            ->assertSuccessful();

        $day = MetricRollup::query()->where('resolution_seconds', 86400)->sole();
        $this->assertSame(2, $day->samples);
        $this->assertEqualsWithDelta(40.0, $day->cpu_usage_sum, 0.001);
        $this->assertEqualsWithDelta(20.0, $day->cpu_usage_sum / $day->samples, 0.001);
    }

    public function test_selecting_the_five_minute_rollup_alone_writes_no_coarse_buckets(): void
    {
        $server = $this->server();

        $this->sample($server, '2026-11-23 11:00:00', ['cpu_usage_pct' => 10]);

        $this->artisan('noc:rollup', ['--resolution' => '300'])
            ->expectsOutputToContain('300s: 1 bucket(s)')
            ->doesntExpectOutputToContain('3600s:')
            ->assertSuccessful();

        $this->assertSame([300], MetricRollup::query()->pluck('resolution_seconds')->unique()->values()->all());
    }

    // ------------------------------------------------------------------
    // Completed-bucket boundary (review I1/I2 rejected: pinned behaviour)
    // ------------------------------------------------------------------

    public function test_a_bucket_completed_exactly_at_now_is_written(): void
    {
        // now == 12:05:00, so the clamp is not applied (`lastExclusive == now`)
        // and 12:00–12:05 is already complete.
        Carbon::setTestNow('2026-11-23 12:05:00');

        $server = $this->server();
        $this->sample($server, '2026-11-23 12:00:30', ['cpu_usage_pct' => 10]);

        $this->service()->rollup(
            $server,
            300,
            CarbonImmutable::parse('2026-11-23 12:00:00', 'UTC'),
            CarbonImmutable::parse('2026-11-23 12:10:00', 'UTC'),
        );

        $this->assertSame('2026-11-23 12:00:00', MetricRollup::query()->sole()->bucket_start->utc()->format('Y-m-d H:i:s'));
    }

    public function test_a_bucket_completed_inside_a_longer_window_is_written_when_now_is_mid_bucket(): void
    {
        // now == 12:05:30: the clamp is `bucketStart(now)` exclusive, i.e.
        // buckets `< 12:05`, which still includes the completed 12:00 bucket.
        Carbon::setTestNow('2026-11-23 12:05:30');

        $server = $this->server();
        $this->sample($server, '2026-11-23 12:00:30', ['cpu_usage_pct' => 10]);
        $this->sample($server, '2026-11-23 12:05:10', ['cpu_usage_pct' => 20]);

        $this->service()->rollup(
            $server,
            300,
            CarbonImmutable::parse('2026-11-23 12:00:00', 'UTC'),
            CarbonImmutable::parse('2026-11-23 12:10:00', 'UTC'),
        );

        $bucket = MetricRollup::query()->sole();
        $this->assertSame('2026-11-23 12:00:00', $bucket->bucket_start->utc()->format('Y-m-d H:i:s'));
        $this->assertSame(1, $bucket->samples);
    }

    public function test_the_guard_clamps_to_the_bucket_start_exclusive_not_the_window_end(): void
    {
        $server = $this->server();
        $this->sample($server, '2026-11-23 12:00:30', ['cpu_usage_pct' => 10]);
        $this->sample($server, '2026-11-23 12:04:30', ['cpu_usage_pct' => 20]);

        // now == 12:04:30 is still inside 12:00–12:05, so neither sample's
        // bucket is complete yet.
        Carbon::setTestNow('2026-11-23 12:04:30');

        $this->service()->rollup(
            $server,
            300,
            CarbonImmutable::parse('2026-11-23 12:00:00', 'UTC'),
            CarbonImmutable::parse('2026-11-23 12:10:00', 'UTC'),
        );

        $this->assertSame(0, MetricRollup::query()->count());

        // One second later the bucket is complete: the clamp excludes only
        // buckets starting at or after 12:05.
        Carbon::setTestNow('2026-11-23 12:05:30');

        $this->service()->rollup(
            $server,
            300,
            CarbonImmutable::parse('2026-11-23 12:00:00', 'UTC'),
            CarbonImmutable::parse('2026-11-23 12:10:00', 'UTC'),
        );

        $bucket = MetricRollup::query()->sole();
        $this->assertSame('2026-11-23 12:00:00', $bucket->bucket_start->utc()->format('Y-m-d H:i:s'));
        $this->assertSame(2, $bucket->samples);
    }

    // ------------------------------------------------------------------
    // Server scope
    // ------------------------------------------------------------------

    public function test_inactive_servers_are_rolled_up_but_soft_deleted_servers_are_not(): void
    {
        $inactive = $this->server();
        $inactive->forceFill(['is_active' => false])->save();

        $deleted = $this->server();
        $deleted->delete();

        $this->sample($inactive, '2026-11-23 11:55:00', ['cpu_usage_pct' => 10]);
        $this->sample($deleted, '2026-11-23 11:55:00', ['cpu_usage_pct' => 20]);

        $this->artisan('noc:rollup', ['--resolution' => '300'])->assertSuccessful();

        $this->assertSame(1, MetricRollup::query()->count());
        $this->assertSame($inactive->getKey(), MetricRollup::query()->sole()->server_id);
    }

    public function test_the_rollup_command_is_scheduled_every_five_minutes(): void
    {
        $schedule = $this->app->make(Schedule::class);

        $events = array_values(array_filter(
            $schedule->events(),
            // Exact command match: the shell line ends in `noc:rollup]` or a
            // quoted `'noc:rollup'`, never a longer lookalike.
            static fn (Event $event): bool => self::isRollupEvent($event),
        ));

        $this->assertCount(1, $events);
        $this->assertSame('*/5 * * * *', $events[0]->expression);
        $this->assertTrue($events[0]->withoutOverlapping);
        // Strictly below the 5-minute cadence so a killed run cannot strand it.
        $this->assertSame(4, $events[0]->expiresAt);
        $this->assertFalse($events[0]->onOneServer);
    }

    public function test_the_schedule_assertion_does_not_match_a_lookalike_command(): void
    {
        // Guard against a prefix match that would also accept
        // `noc:rollup-backfill` or `noc:rollupX`.
        $schedule = $this->app->make(Schedule::class);
        $schedule->command('noc:rollup-extra-test')->everyFiveMinutes();

        $events = array_values(array_filter(
            $schedule->events(),
            // Exact command match: the shell line ends in `noc:rollup]` or a
            // quoted `'noc:rollup'`, never a longer lookalike.
            static fn (Event $event): bool => self::isRollupEvent($event),
        ));

        $this->assertCount(1, $events);
    }

    public function test_the_command_is_idempotent_like_the_service(): void
    {
        $server = $this->server();

        $this->sample($server, '2026-11-23 11:55:00', ['cpu_usage_pct' => 10]);

        $this->artisan('noc:rollup', ['--resolution' => '300'])->assertSuccessful();

        // The upsert on the unique key preserves the original `created_at` and
        // advances `updated_at`, so a rerun is visibly a replacement.
        $first = MetricRollup::query()->sole();
        $createdAt = $first->getRawOriginal('created_at');
        $this->assertNotNull($createdAt);

        Carbon::setTestNow('2026-11-23 12:05:00');
        $this->artisan('noc:rollup', ['--resolution' => '300'])->assertSuccessful();

        $bucket = MetricRollup::query()->sole();
        $this->assertSame(1, $bucket->samples);
        $this->assertEqualsWithDelta(10.0, $bucket->cpu_usage_sum, 0.001);
        $this->assertSame($createdAt, $bucket->getRawOriginal('created_at'));
        $this->assertNotSame($createdAt, $bucket->getRawOriginal('updated_at'));
        $this->assertSame('2026-11-23 12:05:00', $bucket->updated_at->utc()->format('Y-m-d H:i:s'));
    }

    public function test_bucket_alignment_floors_negative_epoch_timestamps(): void
    {
        $server = $this->server();

        // 1969-12-31 23:59:59 is -1 epoch, shared by no following bucket at
        // minute resolution; 23:57:30 is -150s and must floor to 23:55.
        $this->sample($server, '1969-12-31 23:57:30', ['cpu_usage_pct' => 10]);
        $this->sample($server, '1969-12-31 23:59:59', ['cpu_usage_pct' => 20]);

        Carbon::setTestNow('1970-01-01 01:00:00');

        $this->service()->rollup(
            $server,
            300,
            CarbonImmutable::parse('1969-12-31 23:00:00', 'UTC'),
            CarbonImmutable::parse('1970-01-01 00:00:00', 'UTC'),
        );

        // 23:57:30 (epoch -150) and 23:59:59 (epoch -1) both floor to -300,
        // i.e. the 23:55 bucket: a truncating intdiv() would push the second
        // sample into a 23:59 bucket instead.
        $this->assertSame([
            '1969-12-31 23:55:00',
        ], MetricRollup::query()->orderBy('bucket_start')->get()
            ->map(fn (MetricRollup $r): string => $r->bucket_start->utc()->format('Y-m-d H:i:s'))
            ->all());

        $this->assertSame(
            -300,
            $this->service()->bucketStart(CarbonImmutable::createFromTimestamp(-150, 'UTC'), 300)->getTimestamp(),
        );
        $this->assertSame(
            -300,
            $this->service()->bucketStart(CarbonImmutable::createFromTimestamp(-1, 'UTC'), 300)->getTimestamp(),
        );
        $this->assertSame(
            0,
            $this->service()->bucketStart(CarbonImmutable::createFromTimestamp(0, 'UTC'), 300)->getTimestamp(),
        );
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * Match the scheduled `noc:rollup` entry exactly.
     *
     * Laravel stores the shell line as `... 'artisan' noc:rollup`; a longer
     * command such as `noc:rollup-x` must not match.
     */
    private static function isRollupEvent(Event $event): bool
    {
        return preg_match('/[\'"]?noc:rollup[\'"]?(?![[:alnum:]_:.-])/', (string) $event->command) === 1;
    }

    private function service(): RollupService
    {
        return $this->app->make(RollupService::class);
    }

    private function server(): Server
    {
        return Server::query()->create([
            'name' => 'Rollup '.Str::random(6),
            'hostname' => 'rollup-'.Str::random(8).'.example.test',
            'is_active' => true,
        ]);
    }

    /**
     * @param  array<string, int|float>  $values
     */
    private function sample(Server $server, string $collectedAt, array $values): MetricSample
    {
        return MetricSample::query()->create([
            'server_id' => $server->getKey(),
            'collected_at' => $collectedAt,
            'received_at' => $collectedAt,
            'schema_version' => 1,
            'interval_seconds' => 60,
            'cpu_usage_pct' => $values['cpu_usage_pct'] ?? 0,
            'cpu_iowait_pct' => $values['cpu_iowait_pct'] ?? 0,
            'cpu_cores' => 4,
            'load1' => $values['load1'] ?? 0,
            'load5' => 0,
            'load15' => 0,
            'mem_total_bytes' => 1000,
            'mem_used_bytes' => 0,
            'mem_used_pct' => $values['mem_used_pct'] ?? 0,
            'swap_total_bytes' => 0,
            'swap_used_bytes' => 0,
            'swap_used_pct' => 0,
            'disk_total_bytes' => 1000,
            'disk_used_bytes' => 0,
            'disk_used_pct' => $values['disk_used_pct'] ?? 0,
            'disk_inode_pct' => 0,
            'net_rx_bps' => $values['net_rx_bps'] ?? 0,
            'net_tx_bps' => $values['net_tx_bps'] ?? 0,
            'processes_running' => 1,
            'processes_blocked' => 0,
            'uptime_seconds' => 100,
            'payload_bytes' => 100,
        ]);
    }

    /**
     * @return Collection<int, MetricRollup>
     */
    private function rollups(Server $server, int $resolution): Collection
    {
        return MetricRollup::query()
            ->where('server_id', $server->getKey())
            ->where('resolution_seconds', $resolution)
            ->orderBy('bucket_start')
            ->get();
    }
}
