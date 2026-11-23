<?php

namespace Tests\Feature\Ui;

use App\Livewire\Servers\Edit;
use App\Livewire\Servers\Tokens;
use App\Models\Alert;
use App\Models\AlertDelivery;
use App\Models\AuditEvent;
use App\Models\Server;
use App\Models\ServerToken;
use App\Models\User;
use App\Services\Tokens\TokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class TokenManagementTest extends TestCase
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

    public function test_guests_are_redirected_from_token_and_edit_pages(): void
    {
        $server = $this->server();

        $this->get(route('servers.tokens', $server))->assertRedirect('/login');
        $this->get(route('servers.edit', $server))->assertRedirect('/login');
    }

    public function test_a_viewer_can_read_the_token_list_but_sees_no_actions(): void
    {
        $viewer = User::factory()->create();
        $server = $this->server();
        $issued = app(TokenService::class)->issue($server, $viewer, 'Initial agent token');

        $this->actingAs($viewer)
            ->get(route('servers.tokens', $server))
            ->assertOk()
            ->assertSee('Agent tokens')
            ->assertSee($issued->token->token_prefix)
            ->assertSee('Initial agent token')
            ->assertSee('Active')
            ->assertSee($viewer->name)
            ->assertSee('Read-only view')
            ->assertDontSeeHtml('wire:click="rotate"')
            ->assertDontSee('Revoke');

        $this->actingAs($viewer)
            ->get(route('servers.edit', $server))
            ->assertForbidden();
    }

    public function test_a_viewer_cannot_call_rotate(): void
    {
        $viewer = User::factory()->create();
        $server = $this->server();
        app(TokenService::class)->issue($server);

        $this->actingAs($viewer);

        Livewire::test(Tokens::class, ['server' => $server])
            ->call('rotate')
            ->assertStatus(403);
    }

    public function test_a_viewer_cannot_call_revoke(): void
    {
        $viewer = User::factory()->create();
        $server = $this->server();
        $issued = app(TokenService::class)->issue($server);

        $this->actingAs($viewer);

        Livewire::test(Tokens::class, ['server' => $server])
            ->call('startRevoke', $issued->token->getKey())
            ->assertStatus(403);
    }

    public function test_a_viewer_cannot_open_the_edit_form(): void
    {
        $viewer = User::factory()->create();
        $server = $this->server();

        $this->actingAs($viewer);

        Livewire::test(Edit::class, ['server' => $server])->assertStatus(403);
    }

    public function test_rotation_reveals_the_new_token_once_and_keeps_the_old_token_in_grace(): void
    {
        $operator = User::factory()->create(['role' => 'operator']);
        $server = $this->server();
        $tokens = app(TokenService::class);
        $old = $tokens->issue($server);

        $this->actingAs($operator);

        $component = Livewire::test(Tokens::class, ['server' => $server])
            ->call('rotate')
            ->assertHasNoErrors()
            ->assertRedirect(route('servers.token-reveal', $server));

        $payload = session('issued_token');

        $this->assertIsArray($payload);
        $this->assertSame(['server_id', 'token_cipher', 'prefix'], array_keys($payload));
        $this->assertSame($server->getKey(), $payload['server_id']);

        $plain = Crypt::decryptString($payload['token_cipher']);

        $this->assertMatchesRegularExpression('/^noc_[A-Za-z0-9_-]{43}$/', $plain);
        $this->assertNotSame($old->plain, $plain);
        $this->assertSame(substr($plain, 0, 8), $payload['prefix']);
        $this->assertStringNotContainsString($plain, (string) json_encode($payload));
        $component->assertDontSee($plain);

        $this->assertSame(
            now()->addMinutes(1440)->toDateTimeString(),
            $old->token->fresh()->expires_at?->toDateTimeString(),
        );

        $this->assertNotNull($tokens->authenticate($old->plain));
        $this->assertNotNull($tokens->authenticate($plain));

        $this->actingAs($operator)
            ->get(route('servers.tokens', $server))
            ->assertOk()
            ->assertSee('Grace ends')
            ->assertSee('in 1d 0h');

        $this->actingAs($operator)
            ->get(route('servers.token-reveal', $server))
            ->assertOk()
            ->assertSee($plain)
            ->assertHeaderContains('Cache-Control', 'no-store');

        $this->actingAs($operator)
            ->get(route('servers.token-reveal', $server))
            ->assertNotFound()
            ->assertDontSee($plain);

        Carbon::setTestNow('2026-11-24 12:00:01');

        $this->assertNull($tokens->authenticate($old->plain));
        $this->assertNotNull($tokens->authenticate($plain));

        $event = AuditEvent::query()->where('action', 'token.rotated')->sole();

        $this->assertSame($operator->getKey(), $event->user_id);
        $this->assertSame(2, ServerToken::query()->count());
    }

    public function test_revoke_requires_the_exact_server_name_and_takes_effect_immediately(): void
    {
        $operator = User::factory()->create(['role' => 'operator']);
        $server = $this->server(['name' => 'web-01']);
        $tokens = app(TokenService::class);
        $issued = $tokens->issue($server);

        $this->actingAs($operator);

        $component = Livewire::test(Tokens::class, ['server' => $server])
            ->call('startRevoke', $issued->token->getKey())
            ->set('revokeName', 'web-02')
            ->call('revoke')
            ->assertHasErrors(['revokeName'])
            ->assertSet('revokingTokenId', $issued->token->getKey());

        $this->assertNull($issued->token->fresh()->revoked_at);
        $this->assertSame(0, AuditEvent::query()->where('action', 'token.revoked')->count());

        $component
            ->set('revokeName', 'web-01')
            ->call('revoke')
            ->assertHasNoErrors()
            ->assertSet('revokingTokenId', null)
            ->assertSee('Token revoked.');

        $this->assertSame(
            now()->toDateTimeString(),
            $issued->token->fresh()->revoked_at?->toDateTimeString(),
        );
        $this->assertNull($tokens->authenticate($issued->plain));

        $event = AuditEvent::query()->where('action', 'token.revoked')->sole();

        $this->assertSame($operator->getKey(), $event->user_id);
        $this->assertSame((string) $issued->token->getKey(), $event->target_id);
    }

    public function test_edit_updates_every_field_and_audits_without_resetting_health(): void
    {
        $operator = User::factory()->create(['role' => 'operator']);
        $server = $this->server([
            'name' => 'web-01',
            'hostname' => 'web-01.nusantara.id',
            'health' => 'critical',
            'issue_codes' => ['disk_high'],
        ]);

        $this->actingAs($operator);

        Livewire::test(Edit::class, ['server' => $server])
            ->assertSee('web-01')
            ->assertSet('hostname', 'web-01.nusantara.id')
            ->set('name', 'web-01b')
            ->set('hostname', 'web-01b.nusantara.id')
            ->set('server_type', 'Application')
            ->set('provider', 'Nusantara Cloud')
            ->set('location', 'Jakarta')
            ->set('ip_address', '10.20.30.40')
            ->set('label', 'JHD-001')
            ->set('interval_seconds', '300')
            ->set('notes', 'Primary web node')
            ->set('alerts_enabled', false)
            ->call('save')
            ->assertHasNoErrors()
            ->assertSee('Server updated.');

        $server->refresh();

        $this->assertSame('web-01b', $server->name);
        $this->assertSame('web-01b.nusantara.id', $server->hostname);
        $this->assertSame('Application', $server->server_type);
        $this->assertSame('Nusantara Cloud', $server->provider);
        $this->assertSame('Jakarta', $server->location);
        $this->assertSame('10.20.30.40', $server->ip_address);
        $this->assertSame('JHD-001', $server->label);
        $this->assertSame(300, $server->interval_seconds);
        $this->assertSame('Primary web node', $server->notes);
        $this->assertFalse($server->alerts_enabled);
        $this->assertSame('critical', $server->health->value);
        $this->assertSame(['disk_high'], $server->issue_codes);

        $event = AuditEvent::query()->where('action', 'server.updated')->sole();

        $this->assertSame($operator->getKey(), $event->user_id);
        $this->assertSame((string) $server->getKey(), $event->target_id);
        $this->assertFalse($event->meta_json['alerts_enabled']);
    }

    public function test_edit_normalizes_blank_optional_fields_and_allows_its_own_name(): void
    {
        $operator = User::factory()->create(['role' => 'operator']);
        $server = $this->server([
            'provider' => 'Nusantara Cloud',
            'location' => 'Jakarta',
            'ip_address' => '10.0.0.5',
            'label' => 'JHD-001',
            'notes' => 'Old notes',
        ]);

        $this->actingAs($operator);

        Livewire::test(Edit::class, ['server' => $server])
            ->set('provider', '')
            ->set('location', '')
            ->set('ip_address', '')
            ->set('label', '')
            ->set('notes', '')
            ->call('save')
            ->assertHasNoErrors();

        $server->refresh();

        $this->assertNull($server->provider);
        $this->assertNull($server->location);
        $this->assertNull($server->ip_address);
        $this->assertNull($server->label);
        $this->assertNull($server->notes);
    }

    public function test_edit_rejects_duplicate_and_soft_deleted_names(): void
    {
        $operator = User::factory()->create(['role' => 'operator']);
        $server = $this->server(['name' => 'web-01']);
        $other = $this->server(['name' => 'db-01', 'hostname' => 'db-01.nusantara.id']);

        $this->actingAs($operator);

        Livewire::test(Edit::class, ['server' => $server])
            ->set('name', 'db-01')
            ->call('save')
            ->assertHasErrors('name');

        $other->delete();

        Livewire::test(Edit::class, ['server' => $server])
            ->set('name', 'db-01')
            ->call('save')
            ->assertHasErrors('name');

        $this->assertSame('web-01', $server->fresh()->name);
    }

    public function test_turning_alerts_off_closes_open_and_acknowledged_alerts_without_recovery(): void
    {
        $operator = User::factory()->create(['role' => 'operator']);
        $server = $this->server();

        $open = $this->alert($server, ['rule_key' => 'cpu_high', 'severity' => 'warning']);
        $acknowledged = $this->alert($server, [
            'rule_key' => 'disk_high',
            'severity' => 'critical',
            'status' => 'acknowledged',
            'acknowledged_at' => now()->subMinutes(5),
            'acknowledged_by' => $operator->getKey(),
        ]);

        $this->actingAs($operator);

        Livewire::test(Edit::class, ['server' => $server])
            ->set('alerts_enabled', false)
            ->call('save')
            ->assertHasNoErrors();

        foreach ([$open, $acknowledged] as $alert) {
            $alert->refresh();

            $this->assertSame('resolved', $alert->status->value);
            $this->assertNull($alert->active_key);
            $this->assertSame('Alerts disabled for this server', $alert->resolution_note);
            $this->assertSame($operator->getKey(), $alert->resolved_by);
        }

        $this->assertSame(0, AlertDelivery::query()->count());

        $disabled = AuditEvent::query()->where('action', 'server.alerts_disabled')->sole();

        $this->assertSame($operator->getKey(), $disabled->user_id);
        $this->assertSame(2, $disabled->meta_json['closed_count']);

        $this->actingAs($operator);

        Livewire::test(Edit::class, ['server' => $server->fresh()])
            ->set('alerts_enabled', true)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(2, Alert::query()->where('status', 'resolved')->count());
        $this->assertSame(0, Alert::query()->whereIn('status', ['open', 'acknowledged'])->count());
    }

    public function test_delete_requires_the_exact_name_then_soft_deletes_and_closes_everything(): void
    {
        $operator = User::factory()->create(['role' => 'operator']);
        $server = $this->server(['name' => 'web-01']);
        $tokens = app(TokenService::class);
        $first = $tokens->issue($server);
        $second = $tokens->issue($server);
        $tokens->revoke($second->token, $operator);

        $alert = $this->alert($server, ['rule_key' => 'cpu_high']);

        $this->actingAs($operator);

        $component = Livewire::test(Edit::class, ['server' => $server])
            ->set('deleteName', 'web-02')
            ->call('deleteServer')
            ->assertHasErrors(['deleteName']);

        $this->assertNotSoftDeleted($server);

        $component
            ->set('deleteName', 'web-01')
            ->call('deleteServer')
            ->assertHasNoErrors()
            ->assertRedirect(route('dashboard'));

        $this->assertSoftDeleted($server);
        $this->assertNotNull($first->token->fresh()->revoked_at);
        $this->assertNotNull($second->token->fresh()->revoked_at);
        $this->assertNull($tokens->authenticate($first->plain));

        $alert->refresh();

        $this->assertSame('resolved', $alert->status->value);
        $this->assertSame('Server deleted', $alert->resolution_note);
        $this->assertNull($alert->active_key);
        $this->assertSame(0, AlertDelivery::query()->count());

        $event = AuditEvent::query()->where('action', 'server.deleted')->sole();

        $this->assertSame($operator->getKey(), $event->user_id);
        $this->assertSame((string) $server->getKey(), $event->target_id);
        $this->assertSame(1, $event->meta_json['revoked_count']);
        $this->assertSame(1, $event->meta_json['resolved_count']);
    }

    public function test_servers_index_redirects_to_the_dashboard(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/servers')
            ->assertRedirect('/dashboard');
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function server(array $attributes = []): Server
    {
        return Server::query()->create(array_merge([
            'name' => 'server-'.Str::lower(Str::ulid()),
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

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function alert(Server $server, array $attributes = []): Alert
    {
        $rule = $attributes['rule_key'] ?? 'cpu_high';

        return Alert::query()->create(array_merge([
            'server_id' => $server->getKey(),
            'rule_key' => $rule,
            'severity' => 'warning',
            'status' => 'open',
            'active_key' => $server->getKey().':'.$rule,
            'title' => Str::headline($rule),
            'message' => 'Threshold crossed on '.$server->name,
            'context_json' => ['value' => 95, 'threshold' => 90],
            'first_seen_at' => now()->subMinutes(10),
            'last_seen_at' => now()->subMinute(),
            'notification_count' => 0,
        ], $attributes));
    }
}
