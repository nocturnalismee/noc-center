<?php

namespace Tests\Feature\Realtime;

use Tests\TestCase;

class RealtimeConfigTest extends TestCase
{
    public function test_reverb_and_realtime_defaults_are_safe(): void
    {
        $this->assertSame('reverb', config('broadcasting.connections.reverb.driver'));
        $this->assertFalse(config('noc.realtime.enabled'));
        $this->assertSame(750, config('noc.realtime.debounce_ms'));
        $this->assertArrayHasKey('key', config('reverb.apps.apps.0'));
    }
}
