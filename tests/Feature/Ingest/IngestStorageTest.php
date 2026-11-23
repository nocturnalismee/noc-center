<?php

namespace Tests\Feature\Ingest;

use App\Enums\ServerHealth;
use App\Models\MetricDetail;
use App\Models\MetricSample;
use App\Models\Server;
use App\Models\ServerToken;
use App\Services\Ingest\IngestResult;
use App\Services\Ingest\PayloadValidator;
use App\Services\Ingest\StoreSample;
use App\Services\Tokens\TokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class IngestStorageTest extends TestCase
{
    use RefreshDatabase;

    private const URI = '/api/v1/ingest';

    private TokenService $tokens;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tokens = $this->app->make(TokenService::class);

        Carbon::setTestNow('2026-11-22 12:00:00');

        $this->clearRateLimits();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_one_accepted_payload_stores_one_sample_one_detail_and_server_state(): void
    {
        [$server, $plain] = $this->serverWithToken();

        $this->ingest($plain)->assertStatus(202);

        $sample = MetricSample::query()->sole();

        $this->assertSame($server->getKey(), $sample->server_id);
        $this->assertSame('2026-11-22 12:00:00', $sample->collected_at->toDateTimeString());
        $this->assertSame('2026-11-22 12:00:00', $sample->received_at->toDateTimeString());
        $this->assertSame(1, $sample->schema_version);
        $this->assertSame('0.1.0', $sample->agent_version);
        $this->assertSame(60, $sample->interval_seconds);
        $this->assertSame(strlen($this->payloadJson()), $sample->payload_bytes);
        $this->assertSame(hash('sha256', $this->payloadJson()), $sample->payload_hash);

        $detail = MetricDetail::query()->sole();

        $this->assertSame($sample->getKey(), $detail->sample_id);
        $this->assertSame(['cpanel.version' => '120.0.1', 'service.apache.running' => true], $detail->panel_metrics);
        $this->assertSame(['smart.status' => 'ok', 'smart.disks' => 2], $detail->extensions);
        $this->assertSame(strlen($this->payloadJson()), $detail->size_bytes);
    }

    public function test_the_core_metric_columns_are_mapped_from_the_payload(): void
    {
        [$server, $plain] = $this->serverWithToken();
        $body = $this->payload();

        $body['metrics']['cpu'] = [
            'usage_pct' => 42.25,
            'iowait_pct' => 3.5,
            'load1' => 1.125,
            'load5' => 2.5,
            'load15' => 3.75,
            'cores' => 16,
        ];
        $body['metrics']['memory'] = ['total_bytes' => 8_000_000_000, 'used_bytes' => 4_000_000_000, 'used_pct' => 50.5];
        $body['metrics']['swap'] = ['total_bytes' => 2_000_000_000, 'used_bytes' => 100_000_000, 'used_pct' => 5.25];
        $body['metrics']['disk']['root'] = [
            'total_bytes' => 100_000_000_000,
            'used_bytes' => 61_000_000_000,
            'used_pct' => 61.5,
            'inode_pct' => 12.75,
        ];
        $body['metrics']['network'] = ['rx_bytes_per_sec' => 12_345_678, 'tx_bytes_per_sec' => 8_765_432];
        $body['metrics']['processes'] = ['running' => 271, 'blocked' => 4];
        $body['system']['uptime_seconds'] = 987_654;

        $this->ingest($plain, $this->payloadJson($body))->assertStatus(202);

        $sample = MetricSample::query()->sole();

        $this->assertSame(42.25, $sample->cpu_usage_pct);
        $this->assertSame(3.5, $sample->cpu_iowait_pct);
        $this->assertSame(16, $sample->cpu_cores);
        $this->assertSame(1.125, $sample->load1);
        $this->assertSame(2.5, $sample->load5);
        $this->assertSame(3.75, $sample->load15);
        $this->assertSame(8_000_000_000, $sample->mem_total_bytes);
        $this->assertSame(4_000_000_000, $sample->mem_used_bytes);
        $this->assertSame(50.5, $sample->mem_used_pct);
        $this->assertSame(2_000_000_000, $sample->swap_total_bytes);
        $this->assertSame(100_000_000, $sample->swap_used_bytes);
        $this->assertSame(5.25, $sample->swap_used_pct);
        $this->assertSame(100_000_000_000, $sample->disk_total_bytes);
        $this->assertSame(61_000_000_000, $sample->disk_used_bytes);
        $this->assertSame(61.5, $sample->disk_used_pct);
        $this->assertSame(12.75, $sample->disk_inode_pct);
        $this->assertSame(12_345_678, $sample->net_rx_bps);
        $this->assertSame(8_765_432, $sample->net_tx_bps);
        $this->assertSame(271, $sample->processes_running);
        $this->assertSame(4, $sample->processes_blocked);
        $this->assertSame(987_654, $sample->uptime_seconds);

        $server->refresh();

        $this->assertSame(42.25, $server->cpu_usage_pct);
        $this->assertSame(3.5, $server->cpu_iowait_pct);
        $this->assertSame(1.125, $server->load1);
        $this->assertSame(2.5, $server->load5);
        $this->assertSame(3.75, $server->load15);
        $this->assertSame(50.5, $server->mem_used_pct);
        $this->assertSame(5.25, $server->swap_used_pct);
        $this->assertSame(61.5, $server->disk_used_pct);
        $this->assertSame(12.75, $server->disk_inode_pct);
        $this->assertSame(12_345_678, $server->net_rx_bps);
        $this->assertSame(8_765_432, $server->net_tx_bps);
        $this->assertSame(271, $server->processes_running);
        $this->assertSame(4, $server->processes_blocked);
        $this->assertSame(987_654, $server->uptime_seconds);
    }

    public function test_the_server_identity_and_interval_are_updated(): void
    {
        [$server, $plain] = $this->serverWithToken();

        $this->ingest($plain)->assertStatus(202);

        $server->refresh();

        $this->assertSame('web01.example.test', $server->hostname);
        $this->assertSame('cpanel', $server->panel);
        $this->assertSame('AlmaLinux', $server->os_name);
        $this->assertSame('9.4', $server->os_version);
        $this->assertSame('5.14.0-362.8.1.el9_3.x86_64', $server->kernel);
        $this->assertSame('0.1.0', $server->agent_version);
        $this->assertSame(60, $server->interval_seconds);
        $this->assertSame(ServerHealth::Ok, $server->health);
        $this->assertSame([], $server->issue_codes);
    }

    public function test_an_absent_optional_system_block_keeps_the_stored_identity(): void
    {
        [$server, $plain] = $this->serverWithToken();

        $body = $this->payload();
        unset($body['system'], $body['agent']['version']);

        $this->ingest($plain, $this->payloadJson($body))->assertStatus(202);

        $server->refresh();

        // Nothing was reported, so the stored identity is untouched.
        $this->assertSame('Debian', $server->os_name);
        $this->assertSame('12', $server->os_version);
        $this->assertSame('6.1.0', $server->kernel);
        $this->assertSame('0.0.9', $server->agent_version);
        $this->assertNull(MetricSample::query()->sole()->agent_version);
    }

    public function test_the_liveness_thresholds_come_from_the_dashboard_clock_and_reported_interval(): void
    {
        [, $plain] = $this->serverWithToken();

        $this->ingest($plain)->assertStatus(202);

        $server = Server::query()->sole();

        $this->assertSame('2026-11-22 12:00:00', $server->last_seen_at->toDateTimeString());
        $this->assertSame('2026-11-22 12:00:00', $server->last_sample_at->toDateTimeString());
        // max(2.5 x 60, 150) = 150 and max(5 x 60, 300) = 300.
        $this->assertSame('2026-11-22 12:02:30', $server->stale_after->toDateTimeString());
        $this->assertSame('2026-11-22 12:05:00', $server->offline_after->toDateTimeString());
    }

    public function test_a_long_interval_widens_the_liveness_thresholds(): void
    {
        [, $plain] = $this->serverWithToken();

        $body = $this->payload();
        $body['agent']['interval_seconds'] = 600;

        $this->ingest($plain, $this->payloadJson($body))->assertStatus(202);

        $server = Server::query()->sole();

        // max(2.5 x 600, 150) = 1500 and max(5 x 600, 300) = 3000.
        $this->assertSame('2026-11-22 12:25:00', $server->stale_after->toDateTimeString());
        $this->assertSame('2026-11-22 12:50:00', $server->offline_after->toDateTimeString());
    }

    public function test_a_backdated_sample_updates_the_last_sample_without_moving_last_seen(): void
    {
        [, $plain] = $this->serverWithToken();

        $body = $this->payload();
        $body['agent']['collected_at'] = '2026-11-22T11:30:00Z';

        $this->ingest($plain, $this->payloadJson($body))->assertStatus(202);

        $server = Server::query()->sole();

        $this->assertSame('2026-11-22 12:00:00', $server->last_seen_at->toDateTimeString());
        $this->assertSame('2026-11-22 11:50:00', $server->last_sample_at->toDateTimeString());
        $this->assertSame('2026-11-22 11:50:00', MetricSample::query()->sole()->collected_at->toDateTimeString());
    }

    public function test_the_token_is_marked_used_once_per_minute(): void
    {
        [$server, $plain] = $this->serverWithToken();
        $token = ServerToken::query()->sole();

        $this->assertNull($token->last_used_at);

        Carbon::setTestNow('2026-11-22 12:00:00');
        $this->ingest($plain)->assertStatus(202);

        $token->refresh();
        $this->assertSame('2026-11-22 12:00:00', $token->last_used_at->toDateTimeString());
        // The column exists; Task 7 records only the timestamp (see report).
        $this->assertNull($token->last_used_ip);

        Carbon::setTestNow('2026-11-22 12:00:30');
        $body = $this->payload();
        $body['agent']['collected_at'] = '2026-11-22T12:00:30Z';
        $this->ingest($plain, $this->payloadJson($body))->assertStatus(202);

        $token->refresh();
        $this->assertSame('2026-11-22 12:00:00', $token->last_used_at->toDateTimeString());

        Carbon::setTestNow('2026-11-22 12:01:00');
        $body['agent']['collected_at'] = '2026-11-22T12:01:00Z';
        $this->ingest($plain, $this->payloadJson($body))->assertStatus(202);

        $token->refresh();
        $this->assertSame('2026-11-22 12:01:00', $token->last_used_at->toDateTimeString());
        // 12:00:00, 12:00:30 and 12:01:00 are three distinct collection times.
        $this->assertCount(3, MetricSample::query()->get());
    }

    public function test_an_inactive_server_stores_nothing(): void
    {
        [$server, $plain] = $this->serverWithToken(['is_active' => false]);

        $this->ingest($plain)->assertStatus(403);

        $this->assertSame(0, MetricSample::query()->count());
        $this->assertNull($server->refresh()->last_seen_at);
    }

    public function test_the_action_reports_the_stored_sample_and_server(): void
    {
        [$server, $plain] = $this->serverWithToken();
        $body = $this->payloadJson();
        $payload = $this->app->make(PayloadValidator::class)->validate($body);
        $token = ServerToken::query()->sole();

        $result = $this->app->make(StoreSample::class)->store($token, $payload, strlen($body), hash('sha256', $body));

        $this->assertInstanceOf(IngestResult::class, $result);
        $this->assertTrue($result->created);
        $this->assertSame($server->getKey(), $result->server->getKey());
        $this->assertSame($server->getKey(), $result->sample->server_id);
        $this->assertNotNull($result->sample->getKey());
    }

    private function ingest(string $plain, ?string $body = null): TestResponse
    {
        return $this->call('POST', self::URI, [], [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer '.$plain,
            'CONTENT_TYPE' => 'application/json',
        ], $body ?? $this->payloadJson());
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array{0: Server, 1: string}
     */
    private function serverWithToken(array $attributes = []): array
    {
        $server = Server::query()->create(array_merge([
            'name' => 'server-'.Str::lower(Str::ulid()),
            'hostname' => 'agent.example.test',
            'panel' => 'none',
            'os_name' => 'Debian',
            'os_version' => '12',
            'kernel' => '6.1.0',
            'agent_version' => '0.0.9',
        ], $attributes));

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
            'system' => [
                'os' => [
                    'name' => 'AlmaLinux',
                    'version' => '9.4',
                    'kernel' => '5.14.0-362.8.1.el9_3.x86_64',
                ],
                'uptime_seconds' => 123456,
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
                'memory' => ['total_bytes' => 0, 'used_bytes' => 0, 'used_pct' => 0],
                'swap' => ['total_bytes' => 0, 'used_bytes' => 0, 'used_pct' => 0],
                'disk' => [
                    'root' => [
                        'total_bytes' => 0,
                        'used_bytes' => 0,
                        'used_pct' => 0,
                        'inode_pct' => 0,
                    ],
                ],
                'network' => ['rx_bytes_per_sec' => 0, 'tx_bytes_per_sec' => 0],
                'processes' => ['running' => 3, 'blocked' => 0],
            ],
            'panel_metrics' => [
                'cpanel.version' => '120.0.1',
                'service.apache.running' => true,
            ],
            'extensions' => ['smart.status' => 'ok', 'smart.disks' => 2],
        ];
    }

    private function payloadJson(?array $body = null): string
    {
        return json_encode($body ?? $this->payload(), JSON_THROW_ON_ERROR);
    }

    private function clearRateLimits(): void
    {
        RateLimiter::clear('ingest:global');
        RateLimiter::clear('ingest:fail:127.0.0.1');
    }
}
