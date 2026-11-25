<?php

namespace Tests\Unit\Realtime;

use App\Events\AlertChanged;
use App\Events\OperationsChanged;
use App\Events\SampleStored;
use App\Events\ServerChanged;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Tests\TestCase;

class BroadcastEventTest extends TestCase
{
    public function test_sample_event_uses_fleet_and_server_channels_without_secrets(): void
    {
        $event = new SampleStored('srv-1', 'sample-1', '2026-11-25T01:00:00Z', 'ok', 'online');

        $this->assertSame(['private-fleet', 'private-server.srv-1'], $this->channelNames($event));
        $this->assertSame('SampleStored', $event->broadcastAs());
        $this->assertSame([
            'server_id' => 'srv-1',
            'sample_id' => 'sample-1',
            'collected_at' => '2026-11-25T01:00:00Z',
            'health' => 'ok',
            'status' => 'online',
        ], $event->broadcastWith());
        $this->assertNoSensitiveKeys($event->broadcastWith());
        $this->assertInstanceOf(ShouldBroadcast::class, $event);
        $this->assertTrue($event->afterCommit);
    }

    public function test_alert_event_uses_alerts_alert_and_server_channels(): void
    {
        $event = new AlertChanged('alert-1', 'srv-1', 'opened', 'open', 'critical');

        $this->assertSame([
            'private-alerts',
            'private-alert.alert-1',
            'private-server.srv-1',
        ], $this->channelNames($event));
        $this->assertSame('AlertChanged', $event->broadcastAs());
        $this->assertSame([
            'alert_id' => 'alert-1',
            'server_id' => 'srv-1',
            'change' => 'opened',
            'status' => 'open',
            'severity' => 'critical',
        ], $event->broadcastWith());
        $this->assertNoSensitiveKeys($event->broadcastWith());
        $this->assertInstanceOf(ShouldBroadcast::class, $event);
        $this->assertTrue($event->afterCommit);
    }

    public function test_server_event_uses_fleet_and_server_channels(): void
    {
        $event = new ServerChanged('srv-1', 'updated');

        $this->assertSame(['private-fleet', 'private-server.srv-1'], $this->channelNames($event));
        $this->assertSame('ServerChanged', $event->broadcastAs());
        $this->assertSame(['server_id' => 'srv-1', 'change' => 'updated'], $event->broadcastWith());
        $this->assertNoSensitiveKeys($event->broadcastWith());
        $this->assertInstanceOf(ShouldBroadcast::class, $event);
        $this->assertTrue($event->afterCommit);
    }

    public function test_operations_event_uses_the_fleet_channel(): void
    {
        $event = new OperationsChanged('rollup', 'succeeded', '2026-11-25T01:00:00Z');

        $this->assertSame(['private-fleet'], $this->channelNames($event));
        $this->assertSame('OperationsChanged', $event->broadcastAs());
        $this->assertSame([
            'task_key' => 'rollup',
            'status' => 'succeeded',
            'finished_at' => '2026-11-25T01:00:00Z',
        ], $event->broadcastWith());
        $this->assertNoSensitiveKeys($event->broadcastWith());
        $this->assertInstanceOf(ShouldBroadcast::class, $event);
        $this->assertTrue($event->afterCommit);
    }

    /**
     * @return list<string>
     */
    private function channelNames(object $event): array
    {
        return array_map(
            static fn (object $channel): string => $channel->name,
            $event->broadcastOn(),
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function assertNoSensitiveKeys(array $payload): void
    {
        foreach (['context', 'context_json', 'message', 'token', 'secret', 'password'] as $key) {
            $this->assertArrayNotHasKey($key, $payload);
        }
    }
}
