<?php

namespace Tests\Feature\Ingest;

use App\Models\MetricDetail;
use App\Models\MetricSample;
use App\Models\Server;
use App\Models\ServerToken;
use App\Services\Ingest\PayloadValidator;
use App\Services\Ingest\StoreSample;
use App\Services\Tokens\TokenService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class IngestIdempotencyTest extends TestCase
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

    public function test_a_retried_payload_does_not_add_rows_and_still_returns_202(): void
    {
        [, $plain] = $this->serverWithToken();

        $this->ingest($plain)->assertStatus(202);
        $this->assertSame(1, MetricSample::query()->count());

        Carbon::setTestNow('2026-11-22 12:00:20');

        $second = $this->ingest($plain);

        $second->assertStatus(202);
        $second->assertExactJson(['status' => 'accepted']);
        $this->assertStringContainsString('no-store', (string) $second->headers->get('Cache-Control'));

        $this->assertSame(1, MetricSample::query()->count());
        $this->assertSame(1, MetricDetail::query()->count());
    }

    public function test_a_duplicate_advances_the_server_state(): void
    {
        [$server, $plain] = $this->serverWithToken();

        $this->ingest($plain)->assertStatus(202);

        Carbon::setTestNow('2026-11-22 12:00:20');
        $this->ingest($plain)->assertStatus(202);

        $server->refresh();

        $this->assertSame('2026-11-22 12:00:20', $server->last_seen_at->toDateTimeString());
        $this->assertSame('2026-11-22 12:00:00', $server->last_sample_at->toDateTimeString());
        $this->assertSame('2026-11-22 12:02:50', $server->stale_after->toDateTimeString());
        $this->assertSame('2026-11-22 12:05:20', $server->offline_after->toDateTimeString());
    }

    public function test_a_duplicate_does_not_rewrite_the_stored_detail(): void
    {
        [, $plain] = $this->serverWithToken();

        $this->ingest($plain)->assertStatus(202);

        DB::table('metric_details')->update(['extensions' => json_encode(['sentinel' => true])]);

        $this->ingest($plain)->assertStatus(202);

        $this->assertSame(1, MetricDetail::query()->count());
        $this->assertSame(['sentinel' => true], MetricDetail::query()->sole()->extensions);
    }

    public function test_a_duplicate_reports_that_nothing_was_created(): void
    {
        [, $plain] = $this->serverWithToken();
        $body = $this->payloadJson();
        $payload = $this->app->make(PayloadValidator::class)->validate($body);
        $store = $this->app->make(StoreSample::class);
        $token = ServerToken::query()->sole();

        $first = $store->store($token, $payload, strlen($body), hash('sha256', $body));
        $second = $store->store($token, $payload, strlen($body), hash('sha256', $body));

        $this->assertTrue($first->created);
        $this->assertFalse($second->created);
        $this->assertSame($first->sample->getKey(), $second->sample->getKey());
        $this->assertSame(1, MetricSample::query()->count());
        $this->assertSame(1, MetricDetail::query()->count());
    }

    public function test_a_different_collected_at_creates_a_second_sample(): void
    {
        [, $plain] = $this->serverWithToken();

        $this->ingest($plain)->assertStatus(202);

        $body = $this->payload();
        $body['agent']['collected_at'] = '2026-11-22T12:01:00Z';

        $this->ingest($plain, $this->payloadJson($body))->assertStatus(202);

        $this->assertSame(2, MetricSample::query()->count());
        $this->assertSame(2, MetricDetail::query()->count());
    }

    public function test_the_same_timestamp_on_another_server_creates_its_own_sample(): void
    {
        [, $first] = $this->serverWithToken();
        [, $second] = $this->serverWithToken();

        $this->ingest($first)->assertStatus(202);
        $this->ingest($second)->assertStatus(202);

        $this->assertSame(2, MetricSample::query()->count());
        $this->assertSame(2, MetricSample::query()->distinct()->count('server_id'));
    }

    public function test_an_existing_duplicate_insert_never_throws(): void
    {
        [, $plain] = $this->serverWithToken();
        $body = $this->payloadJson();
        $payload = $this->app->make(PayloadValidator::class)->validate($body);
        $token = ServerToken::query()->sole();
        $store = $this->app->make(StoreSample::class);

        // An equivalent row already exists (as if an earlier request had
        // stored it): the second insert is ignored rather than throwing. This
        // is not a true two-process race; it pins existing-duplicate behaviour.
        DB::table('metric_samples')->insert($this->rawSample($token->server_id, '2026-11-22 12:00:00'));

        try {
            $result = $store->store($token, $payload, strlen($body), hash('sha256', $body));
        } catch (QueryException $exception) {
            $this->fail('A duplicate insert must not surface a QueryException: '.$exception->getMessage());
        }

        $this->assertFalse($result->created);
        $this->assertSame(1, MetricSample::query()->count());
        $this->assertSame('2026-11-22 12:00:00', $result->sample->collected_at->toDateTimeString());
    }

    public function test_racing_writers_leave_exactly_one_row(): void
    {
        [, $plainA] = $this->serverWithToken();
        $server = Server::query()->sole();
        $body = $this->payloadJson();
        $payload = $this->app->make(PayloadValidator::class)->validate($body);
        $token = ServerToken::query()->firstOrFail();
        $store = $this->app->make(StoreSample::class);

        // Both writers believe the sample does not exist yet (no read-then-
        // write check): each inserts and ignores the loser's conflict.
        $this->assertTrue($store->store($token, $payload, strlen($body), null)->created);
        $this->assertFalse($store->store($token, $payload, strlen($body), null)->created);

        $this->assertSame(1, MetricSample::query()->where('server_id', $server->getKey())->count());
        $this->assertSame(1, MetricDetail::query()->count());
    }

    public function test_the_payload_hash_is_optional_for_the_storage_action(): void
    {
        [, $plain] = $this->serverWithToken();
        $body = $this->payloadJson();
        $payload = $this->app->make(PayloadValidator::class)->validate($body);

        $result = $this->app->make(StoreSample::class)->store(ServerToken::query()->sole(), $payload, strlen($body), null);

        $this->assertNull($result->sample->payload_hash);
        $this->assertSame(strlen($body), $result->sample->payload_bytes);
    }

    public function test_a_server_absent_from_the_database_cannot_be_stored(): void
    {
        [$server, $plain] = $this->serverWithToken();
        $token = ServerToken::query()->sole();
        $body = $this->payloadJson();

        $server->forceDelete();

        // A token whose server vanished must fail loudly rather than writing
        // an orphan sample (the foreign key would reject it anyway).
        try {
            $this->app->make(StoreSample::class)->store(
                $token, $this->app->make(PayloadValidator::class)->validate($body), strlen($body), null,
            );
            $this->fail('An orphan sample must not be stored.');
        } catch (ModelNotFoundException|QueryException) {
            $this->assertSame(0, MetricSample::query()->count());
        }
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
        ], $attributes));

        $issued = $this->tokens->issue($server);

        RateLimiter::clear('ingest:token:'.$issued->token->getKey());

        return [$server, $issued->plain];
    }

    /**
     * @return array<string, mixed>
     */
    private function rawSample(string $serverId, string $collectedAt): array
    {
        $now = now()->toDateTimeString();

        return [
            'server_id' => $serverId,
            'collected_at' => $collectedAt,
            'received_at' => $now,
            'schema_version' => 1,
            'interval_seconds' => 60,
            'cpu_usage_pct' => 12.5,
            'cpu_iowait_pct' => 0.3,
            'cpu_cores' => 8,
            'load1' => 0.4,
            'load5' => 0.3,
            'load15' => 0.2,
            'mem_total_bytes' => 0,
            'mem_used_bytes' => 0,
            'mem_used_pct' => 0,
            'swap_total_bytes' => 0,
            'swap_used_bytes' => 0,
            'swap_used_pct' => 0,
            'disk_total_bytes' => 0,
            'disk_used_bytes' => 0,
            'disk_used_pct' => 0,
            'disk_inode_pct' => 0,
            'net_rx_bps' => 0,
            'net_tx_bps' => 0,
            'processes_running' => 3,
            'processes_blocked' => 0,
            'uptime_seconds' => 123456,
            'payload_bytes' => 1,
            'payload_hash' => null,
            'created_at' => $now,
        ];
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
                'os' => ['name' => 'AlmaLinux', 'version' => '9.4', 'kernel' => '5.14.0'],
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
                'disk' => ['root' => ['total_bytes' => 0, 'used_bytes' => 0, 'used_pct' => 0, 'inode_pct' => 0]],
                'network' => ['rx_bytes_per_sec' => 0, 'tx_bytes_per_sec' => 0],
                'processes' => ['running' => 3, 'blocked' => 0],
            ],
            'panel_metrics' => ['cpanel.version' => '120.0.1'],
            'extensions' => ['smart.status' => 'ok'],
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
