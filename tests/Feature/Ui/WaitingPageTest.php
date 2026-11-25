<?php

namespace Tests\Feature\Ui;

use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WaitingPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_waiting_page_renders_realtime_hook_and_keeps_the_five_second_fallback(): void
    {
        config(['noc.realtime.enabled' => true, 'noc.realtime.debounce_ms' => 500]);

        $admin = User::factory()->create(['role' => 'admin']);
        $server = $this->server();

        $this->actingAs($admin)
            ->get(route('servers.waiting', $server))
            ->assertOk()
            ->assertSee('data-realtime="waiting"', false)
            ->assertSee("channel: 'server.{$server->getKey()}'", false)
            ->assertSee("event: 'SampleStored'", false)
            ->assertSee('method: \'poll\'', false)
            ->assertSee('wire:poll.5s', false);
    }

    public function test_the_waiting_page_only_polls_when_realtime_is_disabled(): void
    {
        config(['noc.realtime.enabled' => false]);

        $admin = User::factory()->create(['role' => 'admin']);
        $server = $this->server();

        $this->actingAs($admin)
            ->get(route('servers.waiting', $server))
            ->assertOk()
            ->assertDontSee('data-realtime', false)
            ->assertDontSee('SampleStored', false)
            ->assertSee('wire:poll.5s', false);
    }

    private function server(): Server
    {
        return Server::query()->create([
            'name' => 'web-01',
            'hostname' => 'web-01.nusantara.id',
            'server_type' => 'Web',
            'alerts_enabled' => true,
            'notes' => null,
            'interval_seconds' => 60,
        ]);
    }
}
