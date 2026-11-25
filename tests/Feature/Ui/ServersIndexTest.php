<?php

namespace Tests\Feature\Ui;

use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServersIndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_servers_page_lists_servers_instead_of_redirecting_to_the_dashboard(): void
    {
        $user = User::factory()->create(['role' => 'admin']);

        $this->server([
            'name' => 'web-01',
            'hostname' => 'web-01.nusantara.id',
        ]);

        $this->actingAs($user)
            ->get('/servers')
            ->assertOk()
            ->assertSee('Add server')
            ->assertSee('web-01')
            ->assertSee('web-01.nusantara.id')
            ->assertSee('Filter name, hostname, IP, label')
            ->assertSee('sm:w-[280px]', false);
    }

    public function test_the_search_query_parameter_filters_the_server_list(): void
    {
        $user = User::factory()->create();

        $this->server(['name' => 'web-01', 'hostname' => 'web-01.nusantara.id']);
        $this->server(['name' => 'db-01', 'hostname' => 'db-01.nusantara.id']);

        $this->actingAs($user)
            ->get('/servers?q=web-01')
            ->assertOk()
            ->assertSee('web-01.nusantara.id')
            ->assertDontSee('db-01.nusantara.id');
    }

    public function test_the_server_table_renders_realtime_hooks_when_enabled(): void
    {
        config(['noc.realtime.enabled' => true]);

        $this->actingAs(User::factory()->create())
            ->get(route('servers.index'))
            ->assertOk()
            ->assertSee('data-realtime', false)
            ->assertSee('SampleStored', false)
            ->assertSee('ServerChanged', false)
            ->assertSee('wire:poll.60s', false);
    }

    public function test_the_server_table_keeps_polling_without_realtime(): void
    {
        config(['noc.realtime.enabled' => false]);

        $this->actingAs(User::factory()->create())
            ->get(route('servers.index'))
            ->assertOk()
            ->assertDontSee('data-realtime', false)
            ->assertSee('wire:poll.30s', false);
    }

    public function test_the_servers_page_shows_the_empty_state_when_the_fleet_is_empty(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/servers')
            ->assertOk()
            ->assertSee('No servers yet');
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function server(array $attributes = []): Server
    {
        return Server::query()->create(array_merge([
            'name' => 'server-'.strtolower((string) str()->ulid()),
            'hostname' => 'agent.example.test',
            'server_type' => 'Web',
            'alerts_enabled' => true,
            'notes' => null,
            'interval_seconds' => 60,
            'health' => 'unknown',
            'issue_codes' => [],
            'is_active' => true,
            'last_seen_at' => null,
            'stale_after' => null,
            'offline_after' => null,
        ], $attributes));
    }
}
