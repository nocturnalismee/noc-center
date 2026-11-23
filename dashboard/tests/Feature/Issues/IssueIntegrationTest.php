<?php

namespace Tests\Feature\Issues;

use App\Enums\AlertRule;
use App\Enums\ServerHealth;
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
