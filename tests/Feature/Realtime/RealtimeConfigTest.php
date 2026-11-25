<?php

namespace Tests\Feature\Realtime;

use Tests\TestCase;

class RealtimeConfigTest extends TestCase
{
    public function test_reverb_and_realtime_configuration_is_wired(): void
    {
        $this->assertSame('reverb', config('broadcasting.connections.reverb.driver'));
        $this->assertIsBool(config('noc.realtime.enabled'));
        $this->assertSame(750, config('noc.realtime.debounce_ms'));
        $this->assertArrayHasKey('key', config('reverb.apps.apps.0'));
        $this->assertSame(
            [parse_url((string) config('app.url'), PHP_URL_HOST)],
            config('reverb.apps.apps.0.allowed_origins'),
        );
    }
}
