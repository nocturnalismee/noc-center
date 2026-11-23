<?php

namespace Tests\Feature\Metrics;

use App\Enums\UserRole;
use App\Models\MetricRollup;
use App\Models\MetricSample;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Task 13: chart series repository and JSON endpoint (spec §7.6, §9.2, §10).
 *
 * Short ranges read `metric_samples` downsampled to at most 240 points; long
 * ranges read `metric_rollups` at their configured resolution and weight the
 * average by `sum / samples`. Gaps stay absent, never zero-filled.
 */
class SeriesApiTest extends TestCase
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
    // Source table selection
    // ------------------------------------------------------------------

    public function test_each_range_uses_only_its_expected_source_table(): void
    {
        $this->actingAs(User::factory()->create());

        $server = $this->server();
        $queries = [];

        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $expected = [
            '1h' => 'metric_samples',
            '6h' => 'metric_samples',
            '24h' => 'metric_samples',
            '7d' => 'metric_rollups',
            '30d' => 'metric_rollups',
            '90d' => 'metric_rollups',
            '1y' => 'metric_rollups',
        ];

        foreach ($expected as $range => $source) {
            $queries = [];

            $this->getJson($this->url($server, 'cpu', $range))->assertOk();

            $metricQueries = array_values(array_filter(
                $queries,
                static fn (string $sql): bool => str_contains($sql, 'metric_samples')
                    || str_contains($sql, 'metric_rollups'),
            ));

            $this->assertNotEmpty($metricQueries, "Range {$range} queried no metric table.");

            foreach ($metricQueries as $sql) {
                $this->assertStringContainsString($source, $sql, "Range {$range} used the wrong source table.");
                $this->assertStringNotContainsString(
                    $source === 'metric_samples' ? 'metric_rollups' : 'metric_samples',
                    $sql,
                    "Range {$range} touched both source tables.",
                );
            }
        }
    }

    // ------------------------------------------------------------------
    // Raw metric mapping and bucket averaging
    // ------------------------------------------------------------------

    public function test_raw_metric_values_map_to_their_documented_columns(): void
    {
        $this->actingAs(User::factory()->create());
        $server = $this->server();

        $this->sample($server, '2026-11-23 11:59:00', [
            'mem_used_pct' => 42.5,
            'swap_used_pct' => 12.25,
            'disk_inode_pct' => 33.75,
            'processes_running' => 123,
        ]);

        $cases = [
            'memory' => 42.5,
            'swap' => 12.25,
            'inode' => 33.75,
            'processes' => 123.0,
        ];

        foreach ($cases as $metric => $value) {
            $points = $this->getJson($this->url($server, $metric, '1h'))
                ->assertOk()
                ->json('points');

            $this->assertCount(1, $points, "Metric {$metric} should have one point.");
            $this->assertSame('2026-11-23T11:59:00Z', $points[0]['t']);
            $this->assertEqualsWithDelta($value, $points[0]['v'], 0.0001, "Metric {$metric} mapped wrongly.");
        }
    }

    public function test_points_are_ascending_and_a_missing_bucket_stays_absent(): void
    {
        $this->actingAs(User::factory()->create());
        $server = $this->server();

        $this->sample($server, '2026-11-23 11:00:00', ['cpu_usage_pct' => 10]);
        $this->sample($server, '2026-11-23 11:02:00', ['cpu_usage_pct' => 30]);

        $points = $this->getJson($this->url($server, 'cpu', '1h'))
            ->assertOk()
            ->json('points');

        $this->assertSame([
            ['t' => '2026-11-23T11:00:00Z', 'v' => 10],
            ['t' => '2026-11-23T11:02:00Z', 'v' => 30],
        ], $points);

        // 11:01 has no sample and must not be zero-filled.
        $this->assertNotContains('2026-11-23T11:01:00Z', array_column($points, 't'));
    }

    public function test_twenty_four_hours_of_minute_samples_downsample_to_the_cap(): void
    {
        $this->actingAs(User::factory()->create());
        $server = $this->server();

        $rows = [];
        $start = Carbon::parse('2026-11-22 12:00:00', 'UTC');

        for ($minute = 0; $minute < 1440; $minute++) {
            $at = $start->copy()->addMinutes($minute);

            $rows[] = [
                'server_id' => $server->getKey(),
                'collected_at' => $at->format('Y-m-d H:i:s'),
                'received_at' => $at->format('Y-m-d H:i:s'),
                'interval_seconds' => 60,
                'cpu_usage_pct' => $minute + 1,
                'cpu_iowait_pct' => 0,
                'cpu_cores' => 4,
                'load1' => 0,
                'load5' => 0,
                'load15' => 0,
                'mem_total_bytes' => 1,
                'mem_used_bytes' => 0,
                'mem_used_pct' => 0,
                'swap_total_bytes' => 0,
                'swap_used_bytes' => 0,
                'swap_used_pct' => 0,
                'disk_total_bytes' => 1,
                'disk_used_bytes' => 0,
                'disk_used_pct' => 0,
                'disk_inode_pct' => 0,
                'net_rx_bps' => 0,
                'net_tx_bps' => 0,
                'processes_running' => 1,
                'processes_blocked' => 0,
                'uptime_seconds' => 1,
                'payload_bytes' => 1,
            ];
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('metric_samples')->insert($chunk);
        }

        $body = $this->getJson($this->url($server, 'cpu', '24h'))
            ->assertOk()
            ->json();

        $this->assertSame('24h', $body['range']);
        $this->assertSame(360, $body['resolution']);
        $this->assertLessThanOrEqual(240, count($body['points']));
        $this->assertCount(240, $body['points']);

        // Six one-minute samples per 360s bucket; values 1..1440 are averages
        // of their bucket members, not of bucket averages.
        $this->assertSame('2026-11-22T12:00:00Z', $body['points'][0]['t']);
        $this->assertEqualsWithDelta(3.5, $body['points'][0]['v'], 0.0001);
        $this->assertSame('2026-11-23T11:54:00Z', $body['points'][239]['t']);
        $this->assertEqualsWithDelta(1437.5, $body['points'][239]['v'], 0.0001);

        $timestamps = array_column($body['points'], 't');
        $sorted = $timestamps;
        sort($sorted);
        $this->assertSame($sorted, $timestamps);
    }

    // ------------------------------------------------------------------
    // Rollup ranges
    // ------------------------------------------------------------------

    public function test_rollup_average_is_sum_over_samples_exactly(): void
    {
        $this->actingAs(User::factory()->create());
        $server = $this->server();

        // Both rows fall in the same 900s effective bucket for 7d.
        $this->rollup($server, '2026-11-20 00:00:00', 300, 4, ['cpu_usage_sum' => 100]);
        $this->rollup($server, '2026-11-20 00:05:00', 300, 2, ['cpu_usage_sum' => 50]);

        $body = $this->getJson($this->url($server, 'cpu', '7d'))
            ->assertOk()
            ->json();

        $this->assertSame(900, $body['resolution']);
        $this->assertCount(1, $body['points']);
        $this->assertSame('2026-11-20T00:00:00Z', $body['points'][0]['t']);
        $this->assertSame(25.0, (float) $body['points'][0]['v']);
    }

    public function test_seven_day_and_ninety_day_ranges_respect_the_cap_and_source_resolution(): void
    {
        $this->actingAs(User::factory()->create());
        $server = $this->server();

        // 7d reads 300s rollups; a 3600s row at the same time must be ignored.
        $this->rollup($server, '2026-11-20 00:00:00', 300, 4, ['cpu_usage_sum' => 48]);
        $this->rollup($server, '2026-11-20 00:00:00', 3600, 100, ['cpu_usage_sum' => 9000]);

        $sevenDays = $this->getJson($this->url($server, 'cpu', '7d'))
            ->assertOk()
            ->json();

        $this->assertSame(900, $sevenDays['resolution']);
        $this->assertLessThanOrEqual(1000, count($sevenDays['points']));
        $this->assertSame('2026-11-20T00:00:00Z', $sevenDays['points'][0]['t']);
        $this->assertEqualsWithDelta(12.0, $sevenDays['points'][0]['v'], 0.0001);

        // 90d reads 3600s rollups; a 300s row must not leak in.
        $this->rollup($server, '2026-10-01 00:00:00', 3600, 4, ['cpu_usage_sum' => 60]);
        $this->rollup($server, '2026-10-01 00:00:00', 300, 100, ['cpu_usage_sum' => 5000]);

        $ninetyDays = $this->getJson($this->url($server, 'cpu', '90d'))
            ->assertOk()
            ->json();

        $this->assertSame(10800, $ninetyDays['resolution']);
        $this->assertLessThanOrEqual(1000, count($ninetyDays['points']));

        $byTimestamp = array_column($ninetyDays['points'], null, 't');
        $this->assertArrayHasKey('2026-10-01T00:00:00Z', $byTimestamp);
        // 60 / 4, not the 5000 / 100 of the ignored 300s row.
        $this->assertEqualsWithDelta(15.0, $byTimestamp['2026-10-01T00:00:00Z']['v'], 0.0001);
        // The 3600s row seeded for the 7d check remains visible here.
        $this->assertEqualsWithDelta(90.0, $byTimestamp['2026-11-20T00:00:00Z']['v'], 0.0001);
    }

    public function test_rollup_points_are_ascending_and_skip_empty_buckets(): void
    {
        $this->actingAs(User::factory()->create());
        $server = $this->server();

        $this->rollup($server, '2026-11-20 00:00:00', 300, 1, ['cpu_usage_sum' => 10]);
        $this->rollup($server, '2026-11-21 00:00:00', 300, 1, ['cpu_usage_sum' => 20]);

        $points = $this->getJson($this->url($server, 'cpu', '7d'))
            ->assertOk()
            ->json('points');

        $this->assertSame([
            ['t' => '2026-11-20T00:00:00Z', 'v' => 10],
            ['t' => '2026-11-21T00:00:00Z', 'v' => 20],
        ], $points);
    }

    // ------------------------------------------------------------------
    // Validation, authorisation, soft deletes
    // ------------------------------------------------------------------

    public function test_unknown_metric_or_range_is_rejected_with_a_422_before_querying(): void
    {
        $this->actingAs(User::factory()->create());
        $server = $this->server();

        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $this->getJson($this->url($server, 'bogus', '1h'))
            ->assertStatus(422)
            ->assertJsonValidationErrors('metric');

        $this->getJson($this->url($server, 'cpu', 'bogus'))
            ->assertStatus(422)
            ->assertJsonValidationErrors('range');

        $this->assertEmpty(array_filter(
            $queries,
            static fn (string $sql): bool => str_contains($sql, 'metric_samples')
                || str_contains($sql, 'metric_rollups'),
        ));
    }

    public function test_a_guest_is_redirected_to_login(): void
    {
        $server = $this->server();

        $this->get($this->url($server, 'cpu', '24h'))->assertRedirect('/login');
    }

    public function test_a_viewer_can_read_series(): void
    {
        $viewer = User::factory()->create();
        $viewer->forceFill(['role' => UserRole::Viewer])->save();

        $server = $this->server();
        $this->sample($server, '2026-11-23 11:59:00', ['cpu_usage_pct' => 10]);

        $this->actingAs($viewer)
            ->getJson($this->url($server, 'cpu', '1h'))
            ->assertOk();
    }

    public function test_a_soft_deleted_server_returns_404(): void
    {
        $this->actingAs(User::factory()->create());
        $server = $this->server();
        $server->delete();

        $this->getJson($this->url($server, 'cpu', '24h'))->assertNotFound();
    }

    // ------------------------------------------------------------------
    // Response contract
    // ------------------------------------------------------------------

    public function test_the_response_has_the_exact_shape_and_private_cache_header(): void
    {
        $this->actingAs(User::factory()->create());
        $server = $this->server();

        $this->sample($server, '2026-11-23 11:59:00', ['mem_used_pct' => 42.5]);

        $response = $this->getJson($this->url($server, 'memory', '1h'))
            ->assertOk()
            ->assertExactJson([
                'metric' => 'memory',
                'range' => '1h',
                'resolution' => 60,
                'points' => [
                    ['t' => '2026-11-23T11:59:00Z', 'v' => 42.5],
                ],
            ]);

        $cacheControl = strtolower((string) $response->headers->get('Cache-Control'));

        $this->assertStringContainsString('private', $cacheControl);
        $this->assertStringContainsString('max-age=15', $cacheControl);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function url(Server $server, string $metric, string $range): string
    {
        return '/servers/'.$server->getKey().'/series?metric='.$metric.'&range='.$range;
    }

    private function server(int $intervalSeconds = 60): Server
    {
        return Server::query()->create([
            'name' => 'Series '.Str::random(6),
            'hostname' => 'series-'.Str::random(8).'.example.test',
            'interval_seconds' => $intervalSeconds,
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
            'swap_used_pct' => $values['swap_used_pct'] ?? 0,
            'disk_total_bytes' => 1000,
            'disk_used_bytes' => 0,
            'disk_used_pct' => $values['disk_used_pct'] ?? 0,
            'disk_inode_pct' => $values['disk_inode_pct'] ?? 0,
            'net_rx_bps' => $values['net_rx_bps'] ?? 0,
            'net_tx_bps' => $values['net_tx_bps'] ?? 0,
            'processes_running' => $values['processes_running'] ?? 1,
            'processes_blocked' => $values['processes_blocked'] ?? 0,
            'uptime_seconds' => 100,
            'payload_bytes' => 100,
        ]);
    }

    /**
     * @param  array<string, int|float>  $sums
     */
    private function rollup(
        Server $server,
        string $bucketStart,
        int $resolution,
        int $samples,
        array $sums = [],
    ): MetricRollup {
        return MetricRollup::query()->create([
            'server_id' => $server->getKey(),
            'resolution_seconds' => $resolution,
            'bucket_start' => $bucketStart,
            'samples' => $samples,
            'cpu_usage_sum' => $sums['cpu_usage_sum'] ?? 0,
            'cpu_iowait_sum' => 0,
            'mem_used_pct_sum' => $sums['mem_used_pct_sum'] ?? 0,
            'disk_used_pct_sum' => 0,
            'swap_used_pct_sum' => 0,
            'disk_inode_pct_sum' => 0,
            'load1_sum' => 0,
            'net_rx_bps_sum' => 0,
            'net_tx_bps_sum' => 0,
            'processes_running_sum' => 0,
            'processes_blocked_sum' => 0,
            'cpu_usage_min' => 0,
            'cpu_usage_max' => 0,
            'mem_used_pct_min' => 0,
            'mem_used_pct_max' => 0,
            'disk_used_pct_min' => 0,
            'disk_used_pct_max' => 0,
            'load1_max' => 0,
        ]);
    }
}
