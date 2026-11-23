<?php

namespace Tests\Feature\Issues;

use App\Enums\AlertRule;
use App\Enums\ServerHealth;
use App\Issues\IssueDetector;
use App\Issues\IssueSet;
use App\Models\Alert;
use App\Models\MetricSample;
use App\Models\Server;
use App\Models\ServerToken;
use App\Services\Ingest\IngestResult;
use App\Services\Ingest\PayloadValidator;
use App\Services\Ingest\StoreSample;
use App\Services\SettingsRepository;
use App\Services\Tokens\TokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class IssueIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private const URI = '/api/v1/ingest';

    private TokenService $tokens;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tokens = $this->app->make(TokenService::class);

        Carbon::setTestNow('2026-11-22 12:00:00');

        RateLimiter::clear('ingest:global');
        RateLimiter::clear('ingest:fail:127.0.0.1');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_ingest_persists_the_detected_health_and_issue_codes(): void
    {
        [$server, $plain] = $this->serverWithToken();

        $body = $this->payload();
        $body['metrics']['memory']['used_pct'] = 97.0;
        $body['panel_metrics']['service.httpd.running'] = 0;
        $body['panel_metrics']['service.httpd.installed'] = 1;
        $body['panel_metrics']['service.httpd.enabled'] = 1;

        $this->ingest($plain, $body)->assertStatus(202);

        $server->refresh();

        $this->assertSame(ServerHealth::Critical, $server->health);
        $this->assertSame([AlertRule::MemoryHigh->value, AlertRule::ServiceDown->value], $server->issue_codes);
        // Issue detection never opens alerts; that is Task 9.
        $this->assertSame(0, Alert::query()->count());
    }

    public function test_a_duplicate_ingest_recomputes_health_without_adding_samples(): void
    {
        [$server, $plain] = $this->serverWithToken();

        $unhealthy = $this->payload();
        $unhealthy['metrics']['disk']['root']['used_pct'] = 86.0;

        $this->ingest($plain, $unhealthy)->assertStatus(202);
        $this->assertSame(ServerHealth::Warning, $server->refresh()->health);
        $this->assertSame([AlertRule::DiskHigh->value], $server->issue_codes);

        // The agent clears its cache and retries the *same* collection time
        // with identical values. No second sample is stored and the issue
        // state stays the same (the stored row is the source of truth).
        Carbon::setTestNow('2026-11-22 12:00:20');
        $this->ingest($plain, $unhealthy)->assertStatus(202);

        $server->refresh();

        $this->assertSame(1, MetricSample::query()->count());
        $this->assertSame(ServerHealth::Warning, $server->health);
        $this->assertSame([AlertRule::DiskHigh->value], $server->issue_codes);

        // A duplicate with *different* values still returns 202 and updates the
        // server state, but the stored sample row keeps its first values
        // (insertOrIgnore). Issue detection therefore sees the stored row, so
        // the server stays warning/disk_high. Task 9 must read `issue_codes`
        // from the server rather than re-evaluating this payload.
        Carbon::setTestNow('2026-11-22 12:01:00');
        $healthy = $this->payload();
        $healthy['agent']['collected_at'] = '2026-11-22T12:00:00Z';
        $healthy['metrics']['disk']['root']['used_pct'] = 40.0;
        $this->ingest($plain, $healthy)->assertStatus(202);

        $server->refresh();

        $this->assertSame(1, MetricSample::query()->count());
        $this->assertSame(86.0, MetricSample::query()->sole()->disk_used_pct);
        $this->assertSame(ServerHealth::Warning, $server->health);
        $this->assertSame([AlertRule::DiskHigh->value], $server->issue_codes);
    }

    public function test_an_older_duplicate_never_rewrites_the_current_health(): void
    {
        $this->assertOlderDuplicateKeepsTheCurrentHealth();
    }

    /**
     * The regression this pins: a *middle* sample replayed after a newer one
     * was stored is a real duplicate (no new row), and re-detecting on it
     * would resurrect a past incident. Here the stored newest sample is the
     * healthy one, so the replay must not turn the server back to warning.
     */
    private function assertOlderDuplicateKeepsTheCurrentHealth(): void
    {
        [$server, $plain] = $this->serverWithToken();

        // 12:00 unhealthy (warning) → 12:01 healthy: the newest sample is ok.
        foreach (['2026-11-22T12:00:00Z' => 91.0, '2026-11-22T12:01:00Z' => 40.0] as $collectedAt => $disk) {
            $body = $this->payload();
            $body['agent']['collected_at'] = $collectedAt;
            $body['metrics']['disk']['root']['used_pct'] = $disk;

            Carbon::setTestNow(Carbon::parse($collectedAt));
            $this->ingest($plain, $body)->assertStatus(202);
        }

        $this->assertSame(ServerHealth::Ok, $server->refresh()->health);
        $this->assertSame([], $server->issue_codes);

        // Replay the older, unhealthy sample.
        Carbon::setTestNow('2026-11-22 12:02:00');
        $replay = $this->payload();
        $replay['agent']['collected_at'] = '2026-11-22T12:00:00Z';
        $replay['metrics']['disk']['root']['used_pct'] = 91.0;
        $replay['metrics']['memory']['used_pct'] = 99.0;

        $this->ingest($plain, $replay)->assertStatus(202);

        $server->refresh();

        $this->assertSame(2, MetricSample::query()->count());
        $this->assertSame(ServerHealth::Ok, $server->health);
        $this->assertSame([], $server->issue_codes);
        // The duplicate is still a live proof of life for the server and token.
        // `last_sample_at` follows the *reported* collection time (Task 7
        // semantics, unchanged): it answers "when did the agent think it
        // sampled", not "how new is the newest row".
        $this->assertTrue($server->last_seen_at->equalTo(Carbon::now('UTC')));
        $this->assertSame('2026-11-22 12:00:00', $server->last_sample_at->format('Y-m-d H:i:s'));
        $this->assertSame(0, Alert::query()->count());
    }

    public function test_an_older_duplicate_response_describes_the_newest_stored_sample(): void
    {
        [$server, $plain] = $this->serverWithToken();

        $unhealthy = $this->payload();
        $unhealthy['agent']['collected_at'] = '2026-11-22T12:01:00Z';
        $unhealthy['metrics']['disk']['root']['used_pct'] = 91.0;

        $this->ingest($plain, $unhealthy)->assertStatus(202);

        // Same collection time as the stored sample, but a different payload:
        // insertOrIgnore keeps the stored 91%, so the duplicate is ignored.
        $replay = $this->payload();
        $replay['agent']['collected_at'] = '2026-11-22T12:01:00Z';

        Carbon::setTestNow('2026-11-22 12:02:00');

        $json = json_encode($replay, JSON_THROW_ON_ERROR);
        $result = $this->app->make(StoreSample::class)->store(
            ServerToken::query()->sole(),
            $this->app->make(PayloadValidator::class)->validate($json),
            strlen($json),
            hash('sha256', $json),
        );

        // The replay produced no second row and did not produce the response:
        // the returned issue set mirrors the stored row (disk 91%).
        $this->assertFalse($result->created);
        $this->assertSame('2026-11-22 12:01:00', $result->sample->collected_at->format('Y-m-d H:i:s'));
        $this->assertSame(91.0, $result->sample->disk_used_pct);
        $this->assertSame(ServerHealth::Warning, $result->issues->health);
        $this->assertSame([AlertRule::DiskHigh->value], $result->issues->codes);
        $this->assertSame(ServerHealth::Warning, $server->refresh()->health);
        $this->assertSame([AlertRule::DiskHigh->value], $server->issue_codes);
    }

    public function test_a_duplicate_of_a_middle_sample_is_judged_on_the_newest_stored_sample(): void
    {
        [$server, $plain] = $this->serverWithToken();

        // 12:00 healthy, 12:01 unhealthy (warning), 12:02 healthy.
        $bodies = ['2026-11-22T12:00:00Z' => 40.0, '2026-11-22T12:01:00Z' => 91.0, '2026-11-22T12:02:00Z' => 40.0];

        foreach ($bodies as $collectedAt => $disk) {
            $body = $this->payload();
            $body['agent']['collected_at'] = $collectedAt;
            $body['metrics']['disk']['root']['used_pct'] = $disk;

            Carbon::setTestNow(Carbon::parse($collectedAt));
            $this->ingest($plain, $body)->assertStatus(202);
        }

        $this->assertSame(ServerHealth::Ok, $server->refresh()->health);

        // Replay the *middle*, unhealthy sample. This is the regression case:
        // it must not resurrect its old warning.
        Carbon::setTestNow('2026-11-22 12:03:00');
        $middle = $this->payload();
        $middle['agent']['collected_at'] = '2026-11-22T12:01:00Z';
        $middle['metrics']['disk']['root']['used_pct'] = 91.0;

        $json = json_encode($middle, JSON_THROW_ON_ERROR);
        $result = $this->app->make(StoreSample::class)->store(
            ServerToken::query()->sole(),
            $this->app->make(PayloadValidator::class)->validate($json),
            strlen($json),
            hash('sha256', $json),
        );

        $this->assertFalse($result->created);
        $this->assertSame('2026-11-22 12:02:00', $result->sample->collected_at->format('Y-m-d H:i:s'));
        $this->assertSame(ServerHealth::Ok, $result->issues->health);
        $this->assertSame([], $result->issues->codes);

        $server->refresh();

        $this->assertSame(3, MetricSample::query()->count());
        $this->assertSame(ServerHealth::Ok, $server->health);
        $this->assertSame([], $server->issue_codes);
    }

    public function test_edge_duplicate_of_the_newest_sample_keeps_its_health(): void
    {
        [$server, $plain] = $this->serverWithToken();

        $unhealthy = $this->payload();
        $unhealthy['metrics']['disk']['root']['used_pct'] = 86.0;

        $this->ingest($plain, $unhealthy)->assertStatus(202);

        // A fast retry of the newest sample in the same wall-clock second.
        Carbon::setTestNow('2026-11-22 12:00:01');

        $this->ingest($plain, $unhealthy)->assertStatus(202);

        $server->refresh();

        $this->assertSame(1, MetricSample::query()->count());
        $this->assertSame(ServerHealth::Warning, $server->health);
        $this->assertSame([AlertRule::DiskHigh->value], $server->issue_codes);
    }

    public function test_detect_issues_at_most_one_settings_query_per_call_after_warm_up(): void
    {
        $detector = $this->app->make(IssueDetector::class);

        [$server] = $this->serverWithToken();
        $sample = $server->samples()->create([
            'collected_at' => Carbon::now(),
            'received_at' => Carbon::now(),
            'schema_version' => 1,
            'interval_seconds' => 60,
            'cpu_usage_pct' => 10,
            'cpu_iowait_pct' => 0.1,
            'cpu_cores' => 8,
            'load1' => 0.5,
            'load5' => 0.5,
            'load15' => 0.5,
            'mem_total_bytes' => 1,
            'mem_used_bytes' => 1,
            'mem_used_pct' => 10,
            'swap_total_bytes' => 0,
            'swap_used_bytes' => 0,
            'swap_used_pct' => 0,
            'disk_total_bytes' => 1,
            'disk_used_bytes' => 1,
            'disk_used_pct' => 10,
            'disk_inode_pct' => 10,
            'net_rx_bps' => 0,
            'net_tx_bps' => 0,
            'processes_running' => 1,
            'processes_blocked' => 0,
            'uptime_seconds' => 1,
            'payload_bytes' => 1,
        ]);

        $detector->detect($server, $sample, ['service.apache.running' => 0, 'service.apache.installed' => 1, 'service.apache.enabled' => 1], ['smart.status' => 'failed']);

        $queries = 0;
        DB::listen(function ($query) use (&$queries): void {
            if (str_contains($query->sql, 'settings')) {
                $queries++;
            }
        });

        $detector->detect($server, $sample, ['service.apache.running' => 0, 'service.apache.installed' => 1, 'service.apache.enabled' => 1], ['smart.status' => 'failed']);

        // A warm detection must add no settings reads at all.
        $this->assertSame(0, $queries);
    }

    public function test_cpu_high_across_three_ingests_is_detected_from_stored_samples(): void
    {
        [$server, $plain] = $this->serverWithToken();

        foreach (['2026-11-22T12:00:00Z', '2026-11-22T12:01:00Z'] as $collectedAt) {
            $body = $this->payload();
            $body['agent']['collected_at'] = $collectedAt;
            $body['metrics']['cpu']['usage_pct'] = 97.0;
            $body['metrics']['cpu']['load1'] = 0.5;

            Carbon::setTestNow(Carbon::parse($collectedAt));
            $this->ingest($plain, $body)->assertStatus(202);

            $this->assertSame(ServerHealth::Ok, $server->refresh()->health);
        }

        Carbon::setTestNow('2026-11-22 12:02:00');
        $body['agent']['collected_at'] = '2026-11-22T12:02:00Z';
        $this->ingest($plain, $body)->assertStatus(202);

        $server->refresh();

        $this->assertSame(ServerHealth::Critical, $server->health);
        $this->assertSame([AlertRule::CpuHigh->value], $server->issue_codes);
    }

    public function test_the_result_exposes_the_issue_set_that_was_persisted(): void
    {
        [$server] = $this->serverWithToken();
        $json = $this->payloadJson();
        $payload = $this->app->make(PayloadValidator::class)->validate($json);

        $result = $this->app->make(StoreSample::class)->store(
            ServerToken::query()->sole(),
            $payload,
            strlen($json),
            hash('sha256', $json),
        );

        $this->assertInstanceOf(IngestResult::class, $result);
        $this->assertInstanceOf(IssueSet::class, $result->issues);
        $this->assertSame(ServerHealth::Ok, $result->issues->health);
        $this->assertSame([], $result->issues->codes);
        $this->assertSame(ServerHealth::Ok, $server->refresh()->health);
    }

    public function test_issue_threshold_overrides_from_settings_apply_during_ingest(): void
    {
        [$server, $plain] = $this->serverWithToken();

        $this->app->make(SettingsRepository::class)->set('alerts.rules.memory_high.warning', 50, 'int');

        $body = $this->payload();
        $body['metrics']['memory']['used_pct'] = 60.0;

        $this->ingest($plain, $body)->assertStatus(202);

        $server->refresh();

        $this->assertSame(ServerHealth::Warning, $server->health);
        $this->assertSame([AlertRule::MemoryHigh->value], $server->issue_codes);
    }

    private function ingest(string $plain, array $body): TestResponse
    {
        return $this->call('POST', self::URI, [], [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer '.$plain,
            'CONTENT_TYPE' => 'application/json',
        ], json_encode($body, JSON_THROW_ON_ERROR));
    }

    /**
     * @return array{0: Server, 1: string}
     */
    private function serverWithToken(): array
    {
        $server = Server::query()->create([
            'name' => 'server-'.Str::lower(Str::ulid()),
            'hostname' => 'agent.example.test',
            'panel' => 'none',
        ]);

        $issued = $this->tokens->issue($server);

        RateLimiter::clear('ingest:token:'.$issued->token->getKey());

        return [$server, $issued->plain];
    }

    private function payload(): array
    {
        return [
            'schema_version' => 1,
            'agent' => [
                'version' => '0.1.0',
                'id' => '11111111-2222-3333-4444-555555555555',
                'hostname' => 'web01.example.test',
                'panel' => 'cpanel',
                'collected_at' => '2026-11-22T12:00:00Z',
                'interval_seconds' => 60,
            ],
            'metrics' => [
                'cpu' => [
                    'usage_pct' => 12.5,
                    'iowait_pct' => 0.3,
                    'load1' => 0.4,
                    'load5' => 0.3,
                    'load15' => 0.2,
                    'cores' => 8,
                ],
                'memory' => ['total_bytes' => 0, 'used_bytes' => 0, 'used_pct' => 20.0],
                'swap' => ['total_bytes' => 0, 'used_bytes' => 0, 'used_pct' => 0],
                'disk' => [
                    'root' => [
                        'total_bytes' => 0,
                        'used_bytes' => 0,
                        'used_pct' => 40.0,
                        'inode_pct' => 10.0,
                    ],
                ],
                'network' => ['rx_bytes_per_sec' => 0, 'tx_bytes_per_sec' => 0],
                'processes' => ['running' => 3, 'blocked' => 0],
            ],
            'panel_metrics' => [
                'cpanel.version' => '120.0.1',
                'service.apache.running' => 1,
                'service.apache.installed' => 1,
                'service.apache.enabled' => 1,
            ],
            'extensions' => ['smart.status' => 'ok'],
        ];
    }

    private function payloadJson(): string
    {
        return json_encode($this->payload(), JSON_THROW_ON_ERROR);
    }
}
