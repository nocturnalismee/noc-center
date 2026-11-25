<?php

namespace Tests\Feature\Realtime;

use App\Alerts\AlertEngine;
use App\Enums\AlertRule;
use App\Events\AlertChanged;
use App\Events\SampleStored;
use App\Models\MetricSample;
use App\Models\Server;
use App\Models\User;
use App\Services\Tokens\TokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class RealtimeEmissionTest extends TestCase
{
    use RefreshDatabase;

    private const URI = '/api/v1/ingest';

    private TokenService $tokens;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tokens = $this->app->make(TokenService::class);

        Carbon::setTestNow('2026-11-25 12:00:00');

        RateLimiter::clear('ingest:global');
        RateLimiter::clear('ingest:fail:127.0.0.1');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_accepted_ingest_emits_sample_stored_when_realtime_is_enabled(): void
    {
        config(['noc.realtime.enabled' => true]);
        Event::fake([SampleStored::class]);

        [$server, $plain] = $this->serverWithToken();

        $this->ingest($plain)->assertStatus(202);

        $sample = MetricSample::query()->sole();

        Event::assertDispatched(SampleStored::class);

        $event = Event::dispatched(SampleStored::class)->last()[0];

        $this->assertSame((string) $server->getKey(), $event->serverId);
        $this->assertSame((string) $sample->getKey(), $event->sampleId);
        $this->assertNotSame('', $event->collectedAt);
        $this->assertNotSame('', $event->health);
        $this->assertNotSame('', $event->status);
    }

    public function test_accepted_ingest_does_not_emit_sample_stored_when_realtime_is_disabled(): void
    {
        config(['noc.realtime.enabled' => false]);
        Event::fake([SampleStored::class]);

        [, $plain] = $this->serverWithToken();

        $this->ingest($plain)->assertStatus(202);

        Event::assertNotDispatched(SampleStored::class);
    }

    public function test_a_second_sample_emits_the_newest_stored_sample(): void
    {
        config(['noc.realtime.enabled' => true]);
        Event::fake([SampleStored::class]);

        [, $plain] = $this->serverWithToken();

        $this->ingest($plain, $this->payloadJson($this->payload('2026-11-25T12:00:00Z')))->assertStatus(202);

        Carbon::setTestNow('2026-11-25 12:01:00');

        $this->ingest($plain, $this->payloadJson($this->payload('2026-11-25T12:01:00Z')))->assertStatus(202);

        $latest = MetricSample::query()->orderByDesc('received_at')->firstOrFail();

        Event::assertDispatched(SampleStored::class, function (SampleStored $event) use ($latest): bool {
            return $event->sampleId === (string) $latest->getKey();
        });
    }

    public function test_opening_an_alert_emits_alert_changed_once(): void
    {
        config(['noc.realtime.enabled' => true]);
        Event::fake([AlertChanged::class]);

        [$server] = $this->serverWithToken();

        $alert = $this->app->make(AlertEngine::class)->syncRule(
            $server,
            AlertRule::CpuHigh,
            true,
            ['severity' => 'warning'],
        );

        $this->app->make(AlertEngine::class)->syncRule(
            $server,
            AlertRule::CpuHigh,
            true,
            ['severity' => 'warning'],
        );

        Event::assertDispatchedTimes(AlertChanged::class, 1);
        Event::assertDispatched(AlertChanged::class, function (AlertChanged $event) use ($alert, $server): bool {
            return $event->alertId === (string) $alert->getKey()
                && $event->serverId === (string) $server->getKey()
                && $event->change === 'opened'
                && $event->status === 'open'
                && $event->severity === 'warning';
        });
    }

    public function test_acknowledging_an_alert_emits_alert_changed(): void
    {
        config(['noc.realtime.enabled' => true]);
        Event::fake([AlertChanged::class]);

        [$server] = $this->serverWithToken();
        $engine = $this->app->make(AlertEngine::class);
        $alert = $engine->syncRule($server, AlertRule::CpuHigh, true, ['severity' => 'warning']);
        $actor = User::factory()->create();

        $engine->acknowledge($alert, $actor);

        Event::assertDispatched(AlertChanged::class, function (AlertChanged $event) use ($alert, $server): bool {
            return $event->alertId === (string) $alert->getKey()
                && $event->serverId === (string) $server->getKey()
                && $event->change === 'acknowledged'
                && $event->status === 'acknowledged';
        });
    }

    public function test_resolving_an_alert_emits_alert_changed(): void
    {
        config(['noc.realtime.enabled' => true]);
        Event::fake([AlertChanged::class]);

        [$server] = $this->serverWithToken();
        $engine = $this->app->make(AlertEngine::class);
        $alert = $engine->syncRule($server, AlertRule::CpuHigh, true, ['severity' => 'warning']);
        $actor = User::factory()->create();

        $engine->resolve($alert, $actor, 'Fixed');

        Event::assertDispatched(AlertChanged::class, function (AlertChanged $event) use ($alert, $server): bool {
            return $event->alertId === (string) $alert->getKey()
                && $event->serverId === (string) $server->getKey()
                && $event->change === 'resolved'
                && $event->status === 'resolved';
        });
    }

    public function test_alert_events_are_not_emitted_when_realtime_is_disabled(): void
    {
        config(['noc.realtime.enabled' => false]);
        Event::fake([AlertChanged::class]);

        [$server] = $this->serverWithToken();

        $this->app->make(AlertEngine::class)->syncRule(
            $server,
            AlertRule::CpuHigh,
            true,
            ['severity' => 'warning'],
        );

        Event::assertNotDispatched(AlertChanged::class);
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
        RateLimiter::clear('ingest:global');
        RateLimiter::clear('ingest:fail:127.0.0.1');

        return [$server, $issued->plain];
    }

    private function ingest(string $plain, ?string $body = null): TestResponse
    {
        return $this->call('POST', self::URI, [], [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer '.$plain,
            'CONTENT_TYPE' => 'application/json',
        ], $body ?? $this->payloadJson($this->payload()));
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(?string $collectedAt = null): array
    {
        return [
            'schema_version' => 1,
            'agent' => [
                'version' => '0.1.0',
                'id' => '11111111-2222-3333-4444-555555555555',
                'hostname' => 'web01.example.test',
                'panel' => 'cpanel',
                'collected_at' => $collectedAt ?? '2026-11-25T12:00:00Z',
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

    private function payloadJson(array $body): string
    {
        return json_encode($body, JSON_THROW_ON_ERROR);
    }
}
