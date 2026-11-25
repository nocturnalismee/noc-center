<?php

namespace Tests\Unit\Realtime;

use App\Events\SampleStored;
use App\Support\Realtime;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class RealtimeHelperTest extends TestCase
{
    public function test_emit_does_nothing_when_realtime_is_disabled(): void
    {
        config(['noc.realtime.enabled' => false]);
        Event::fake([SampleStored::class]);

        Realtime::emit(new SampleStored('srv-1', 'sample-1', '2026-11-25T01:00:00Z', 'ok', 'online'));

        Event::assertNotDispatched(SampleStored::class);
    }

    public function test_emit_dispatches_when_realtime_is_enabled(): void
    {
        config(['noc.realtime.enabled' => true]);
        Event::fake([SampleStored::class]);

        Realtime::emit(new SampleStored('srv-1', 'sample-1', '2026-11-25T01:00:00Z', 'ok', 'online'));

        Event::assertDispatched(SampleStored::class);
    }

    public function test_emit_swallows_a_broadcast_failure(): void
    {
        config(['noc.realtime.enabled' => true]);

        Event::listen(SampleStored::class, function (): void {
            throw new \RuntimeException('Reverb is unavailable.');
        });

        Realtime::emit(new SampleStored('srv-1', 'sample-1', '2026-11-25T01:00:00Z', 'ok', 'online'));

        $this->addToAssertionCount(1);
    }
}
