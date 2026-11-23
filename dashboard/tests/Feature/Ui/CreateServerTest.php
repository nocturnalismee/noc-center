<?php

namespace Tests\Feature\Ui;

use App\Livewire\Servers\Create;
use App\Livewire\Servers\Waiting;
use App\Models\Server;
use App\Models\ServerToken;
use App\Models\User;
use App\Services\Servers\ServerService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PDOException;
use Tests\TestCase;

class CreateServerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-23 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_guests_are_redirected_to_the_sign_in_screen(): void
    {
        $server = $this->server();

        $this->get('/servers/create')->assertRedirect('/login');
        $this->get(route('servers.token-reveal', $server))->assertRedirect('/login');
        $this->get(route('servers.waiting', $server))->assertRedirect('/login');
    }

    public function test_a_viewer_cannot_open_the_wizard_reveal_or_waiting_page(): void
    {
        $viewer = User::factory()->create();
        $server = $this->server();

        $this->actingAs($viewer)->get('/servers/create')->assertForbidden();
        $this->actingAs($viewer)->get(route('servers.token-reveal', $server))->assertForbidden();
        $this->actingAs($viewer)->get(route('servers.waiting', $server))->assertForbidden();
    }

    public function test_operators_and_admins_can_open_the_wizard(): void
    {
        foreach (['operator', 'admin'] as $role) {
            $user = User::factory()->create(['role' => $role]);

            $this->actingAs($user)
                ->get('/servers/create')
                ->assertOk()
                ->assertSee('Add server')
                ->assertSee('Details')
                ->assertSee('Review')
                ->assertSee('Install');
        }
    }

    public function test_the_operator_can_open_the_reveal_and_waiting_pages(): void
    {
        $operator = User::factory()->create(['role' => 'operator']);
        $server = $this->server();

        $this->actingAs($operator)
            ->withSession(['issued_token' => $this->payload($server)])
            ->get(route('servers.token-reveal', $server))
            ->assertOk();

        $this->actingAs($operator)
            ->get(route('servers.waiting', $server))
            ->assertOk()
            ->assertSee('Waiting for first check-in');
    }

    public function test_step_one_requires_name_and_hostname_and_stays_on_step_one(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'operator']));

        Livewire::test(Create::class)
            ->call('goToReview')
            ->assertHasErrors(['name' => 'required', 'hostname' => 'required'])
            ->assertSet('step', 1)
            ->assertSee('Details');
    }

    public function test_step_one_enforces_interval_and_notes_bounds(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'operator']));

        Livewire::test(Create::class)
            ->set('name', 'web-03')
            ->set('hostname', 'web-03.nusantara.id')
            ->set('interval_seconds', '9')
            ->set('notes', str_repeat('a', 2001))
            ->call('goToReview')
            ->assertHasErrors([
                'interval_seconds' => 'between',
                'notes' => 'max',
            ])
            ->assertSet('step', 1);
    }

    public function test_step_one_rejects_a_duplicate_name_without_a_server_error(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'operator']));
        $this->server(['name' => 'web-03']);

        Livewire::test(Create::class)
            ->set('name', 'web-03')
            ->set('hostname', 'other.nusantara.id')
            ->call('goToReview')
            ->assertHasErrors(['name' => 'unique'])
            ->assertSet('step', 1);
    }

    public function test_step_two_shows_a_review_and_back_keeps_the_values(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'operator']));

        Livewire::test(Create::class)
            ->set('name', 'web-03')
            ->set('hostname', 'web-03.nusantara.id')
            ->set('server_type', 'Web')
            ->set('interval_seconds', '120')
            ->set('notes', 'Primary Laravel application server')
            ->call('goToReview')
            ->assertSet('step', 2)
            ->assertSee('Review')
            ->assertSee('web-03')
            ->assertSee('web-03.nusantara.id')
            ->assertSee('Web')
            ->assertSee('Primary Laravel application server')
            ->call('back')
            ->assertSet('step', 1)
            ->assertSet('name', 'web-03')
            ->assertSet('interval_seconds', '120');
    }

    public function test_an_invalid_review_submit_returns_to_step_one_with_errors(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'operator']));

        $component = Livewire::test(Create::class)
            ->set('name', 'web-03')
            ->set('hostname', 'web-03.nusantara.id')
            ->call('goToReview')
            ->assertSet('step', 2);

        // A competing row can appear between review and submit.
        $this->server(['name' => 'web-03']);

        $component
            ->call('create')
            ->assertSet('step', 1)
            ->assertHasErrors(['name' => 'unique']);

        $this->assertSame(1, Server::query()->count());
        $this->assertSame(0, ServerToken::query()->count());
    }

    public function test_a_unique_race_inside_the_service_surfaces_as_a_form_error(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'operator']));

        $previous = new PDOException('UNIQUE constraint failed: servers.name', 23000);
        $previous->errorInfo = ['23000', 19, 'UNIQUE constraint failed: servers.name'];

        $this->mock(ServerService::class, function ($mock) use ($previous): void {
            $mock->shouldReceive('create')->once()->andThrow(
                new QueryException('sqlite', 'insert into "servers" ("name") values (?)', ['web-03'], $previous),
            );
        });

        Livewire::test(Create::class)
            ->set('name', 'web-03')
            ->set('hostname', 'web-03.nusantara.id')
            ->call('goToReview')
            ->call('create')
            ->assertSet('step', 1)
            ->assertHasErrors(['name']);

        $this->assertSame(0, Server::query()->count());
        $this->assertSame(0, ServerToken::query()->count());
    }

    public function test_a_successful_submit_creates_the_server_and_token_and_redirects(): void
    {
        $operator = User::factory()->create(['role' => 'operator']);
        $this->actingAs($operator);

        $component = Livewire::test(Create::class)
            ->set('name', 'web-03')
            ->set('hostname', 'web-03.nusantara.id')
            ->set('server_type', 'Web')
            ->set('interval_seconds', '120')
            ->set('notes', 'Primary Laravel application server')
            ->call('goToReview')
            ->call('create')
            ->assertHasNoErrors();

        $server = Server::query()->where('name', 'web-03')->firstOrFail();

        $component->assertRedirect(route('servers.token-reveal', $server));

        $this->assertSame('web-03.nusantara.id', $server->hostname);
        $this->assertSame('Web', $server->server_type);
        $this->assertSame(120, $server->interval_seconds);
        $this->assertSame('Primary Laravel application server', $server->notes);
        $this->assertTrue($server->alerts_enabled);

        $this->assertSame(1, ServerToken::query()->count());

        $payload = session('issued_token');

        $this->assertIsArray($payload);
        $this->assertSame(['server_id', 'plain', 'prefix'], array_keys($payload));
        $this->assertSame($server->getKey(), $payload['server_id']);
        $this->assertSame('noc_', substr($payload['plain'], 0, 4));
        $this->assertSame(substr($payload['plain'], 0, 8), $payload['prefix']);

        $component->assertDontSee($payload['plain']);

        // The flashed payload survives the redirect and is revealed once.
        $this->actingAs($operator)
            ->get(route('servers.token-reveal', $server))
            ->assertOk()
            ->assertSee($payload['plain']);

        $this->actingAs($operator)
            ->get(route('servers.token-reveal', $server))
            ->assertNotFound();

        $stored = json_encode(DB::table('server_tokens')->get())
            .json_encode(DB::table('audit_events')->get());

        $this->assertStringNotContainsString($payload['plain'], (string) $stored);

        $token = ServerToken::query()->firstOrFail();

        $this->assertNotSame($payload['plain'], $token->token_hash);
        $this->assertSame($payload['prefix'], $token->token_prefix);
        $this->assertSame($operator->getKey(), $token->created_by);
    }

    public function test_the_wizard_never_renders_a_token_before_the_reveal(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'operator']));

        Livewire::test(Create::class)
            ->set('name', 'web-03')
            ->set('hostname', 'web-03.nusantara.id')
            ->call('goToReview')
            ->assertDontSee('noc_')
            ->assertDontSee('issued_token');
    }

    public function test_the_reveal_page_shows_the_token_once_with_details_and_no_store(): void
    {
        $operator = User::factory()->create(['role' => 'operator']);
        $server = $this->server();
        $payload = $this->payload($server);

        $this->actingAs($operator)
            ->withSession(['issued_token' => $payload])
            ->get(route('servers.token-reveal', $server))
            ->assertOk()
            ->assertSee('Server created')
            ->assertSee($server->name)
            ->assertSee($server->hostname)
            ->assertSee($payload['plain'])
            ->assertSee($payload['prefix'])
            ->assertSee('Copy this token now. It will not be shown again.')
            ->assertSee('curl -fsSL '.config('app.url').'/agent/install.sh')
            ->assertSee('--token '.$payload['plain'])
            ->assertSee(route('servers.waiting', $server), false)
            ->assertSee('Never')
            ->assertSee('24 hours')
            ->assertSee('HMAC-SHA256')
            ->assertHeader('Cache-Control')
            ->assertHeaderContains('Cache-Control', 'no-store');

        $this->actingAs($operator)
            ->get(route('servers.token-reveal', $server))
            ->assertNotFound()
            ->assertDontSee($payload['plain']);
    }

    public function test_the_reveal_page_returns_404_for_a_missing_malformed_or_mismatched_payload(): void
    {
        $operator = User::factory()->create(['role' => 'operator']);
        $server = $this->server();
        $other = $this->server(['name' => 'db-01', 'hostname' => 'db-01.nusantara.id']);

        $this->actingAs($operator)
            ->withSession(['issued_token' => ['server_id' => $other->getKey(), 'plain' => $this->plain(), 'prefix' => 'noc_abc']])
            ->get(route('servers.token-reveal', $server))
            ->assertNotFound();

        $this->actingAs($operator)
            ->withSession(['issued_token' => ['server_id' => $server->getKey(), 'plain' => 'not-a-token', 'prefix' => 'noc_abc']])
            ->get(route('servers.token-reveal', $server))
            ->assertNotFound();

        $this->actingAs($operator)
            ->withSession(['issued_token' => ['server_id' => $server->getKey()]])
            ->get(route('servers.token-reveal', $server))
            ->assertNotFound();

        $this->actingAs($operator)
            ->withSession(['issued_token' => ['server_id' => $server->getKey(), 'plain' => $this->plain(), 'prefix' => 'noc_nope']])
            ->get(route('servers.token-reveal', $server))
            ->assertNotFound();
    }

    public function test_the_waiting_page_polls_until_the_server_is_seen_then_stops(): void
    {
        $operator = User::factory()->create(['role' => 'operator']);
        $server = $this->server();

        $this->actingAs($operator);

        $component = Livewire::test(Waiting::class, ['server' => $server])
            ->assertSee('Waiting for first check-in')
            ->assertSeeHtml('wire:poll.5s.visible="poll"');

        Carbon::setTestNow('2026-11-23 12:04:59');
        $component->call('poll')
            ->assertSee('Waiting for first check-in')
            ->assertSeeHtml('wire:poll.5s.visible="poll"');

        $server->forceFill(['last_seen_at' => now()])->save();

        $component->call('poll')
            ->assertSee('Agent connected')
            ->assertSee(route('servers.show', $server), false)
            ->assertDontSeeHtml('wire:poll.5s.visible="poll"');
    }

    public function test_the_waiting_page_stops_polling_after_ten_minutes(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'operator']));
        $server = $this->server();

        $component = Livewire::test(Waiting::class, ['server' => $server]);

        Carbon::setTestNow('2026-11-23 12:10:00');

        $component->call('poll')
            ->assertSee('Troubleshooting')
            ->assertSee('systemctl status noc-agent')
            ->assertSee('journalctl -u noc-agent -n 50')
            ->assertDontSeeHtml('wire:poll.5s.visible="poll"')
            ->assertDontSee('Agent connected');
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function server(array $attributes = []): Server
    {
        return Server::query()->create([
            'name' => $attributes['name'] ?? 'web-01',
            'hostname' => $attributes['hostname'] ?? 'web-01.nusantara.id',
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
        ]);
    }

    /**
     * @return array{server_id: string, plain: string, prefix: string}
     */
    private function payload(Server $server): array
    {
        $plain = $this->plain();

        return [
            'server_id' => $server->getKey(),
            'plain' => $plain,
            'prefix' => substr($plain, 0, 8),
        ];
    }

    private function plain(): string
    {
        return 'noc_'.rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }
}
