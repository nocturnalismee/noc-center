<?php

namespace Tests\Feature\Ui;

use App\Livewire\Alerts\AlertBell;
use App\Livewire\Alerts\Index;
use App\Livewire\Alerts\Show;
use App\Models\Alert;
use App\Models\AlertDelivery;
use App\Models\AuditEvent;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class AlertCenterTest extends TestCase
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

    public function test_guests_are_redirected_and_a_viewer_can_read_the_list_and_detail(): void
    {
        $server = $this->server();
        $alert = $this->alert($server, ['title' => 'Alpha alert']);
        $viewer = User::factory()->create();

        $this->get(route('alerts.index'))->assertRedirect('/login');
        $this->get(route('alerts.show', $alert))->assertRedirect('/login');

        $this->actingAs($viewer)
            ->get(route('alerts.index'))
            ->assertOk()
            ->assertSee('Alpha alert')
            ->assertSee('Open');

        $this->actingAs($viewer)
            ->get(route('alerts.show', $alert))
            ->assertOk()
            ->assertSee('Alpha alert')
            ->assertSee('Read-only')
            ->assertDontSee('Acknowledge')
            ->assertDontSee('Resolve alert');
    }

    public function test_index_filters_by_status_severity_and_server(): void
    {
        $operator = User::factory()->create(['role' => 'operator']);
        $web = $this->server(['name' => 'web-01']);
        $db = $this->server(['name' => 'db-01', 'hostname' => 'db-01.nusantara.id']);

        $this->alert($web, ['title' => 'Alpha alert', 'rule_key' => 'cpu_high', 'severity' => 'warning']);
        $this->alert($web, [
            'title' => 'Beta alert',
            'rule_key' => 'memory_high',
            'severity' => 'critical',
            'status' => 'acknowledged',
            'acknowledged_at' => now()->subMinutes(3),
            'acknowledged_by' => $operator->getKey(),
        ]);
        $this->alert($web, [
            'title' => 'Gamma alert',
            'rule_key' => 'disk_high',
            'severity' => 'warning',
            'status' => 'resolved',
            'active_key' => null,
            'resolved_at' => now()->subMinute(),
        ]);
        $this->alert($db, ['title' => 'Delta alert', 'rule_key' => 'load_high', 'severity' => 'critical']);

        $this->actingAs($operator);

        Livewire::test(Index::class)
            ->assertSet('status', 'open')
            ->assertSee('Alpha alert')
            ->assertSee('Delta alert')
            ->assertDontSee('Beta alert')
            ->assertDontSee('Gamma alert')
            ->set('status', 'acknowledged')
            ->assertSee('Beta alert')
            ->assertDontSee('Alpha alert')
            ->set('status', 'all')
            ->set('severity', 'critical')
            ->assertSee('Beta alert')
            ->assertSee('Delta alert')
            ->assertDontSee('Alpha alert')
            ->assertDontSee('Gamma alert')
            ->set('severity', 'all')
            ->set('serverId', $db->getKey())
            ->assertSee('Delta alert')
            ->assertDontSee('Alpha alert')
            ->assertDontSee('Beta alert');
    }

    public function test_index_paginates_twenty_five_newest_first(): void
    {
        $operator = User::factory()->create(['role' => 'operator']);
        $server = $this->server();

        for ($i = 1; $i <= 30; $i++) {
            $this->alert($server, [
                'title' => sprintf('Alert %02d', $i),
                'rule_key' => 'cpu_high',
                'active_key' => $server->getKey().':cpu-'.$i,
                'last_seen_at' => now()->subMinutes(30 - $i),
            ]);
        }

        $this->actingAs($operator);

        Livewire::test(Index::class)
            ->assertViewHas('alerts', fn ($alerts): bool => $alerts->count() === 25
                && $alerts->first()->title === 'Alert 30'
                && $alerts->last()->title === 'Alert 06');
    }

    public function test_detail_redacts_query_text_and_credentials(): void
    {
        $viewer = User::factory()->create();
        $server = $this->server();
        $alert = $this->alert($server, [
            'context_json' => [
                'value' => 96,
                'query' => "SELECT * FROM users WHERE email = 'secret@example.com'",
                'panel.query.info' => 'SELECT password FROM accounts WHERE id = 42',
                'nested' => [
                    'query' => "DELETE FROM sessions WHERE token = 'abcdef'",
                    'bot_token' => '123456:AAH-nested-secret',
                ],
                'bot_token' => '987654:AAH-secret-token',
            ],
        ]);

        $response = $this->actingAs($viewer)->get(route('alerts.show', $alert));

        $response->assertOk()
            ->assertSee('Context')
            ->assertSee('[redacted]')
            ->assertDontSee('SELECT * FROM users WHERE email = ?')
            ->assertDontSee('SELECT password FROM accounts WHERE id = ?')
            ->assertDontSee('secret@example.com')
            ->assertDontSee('abcdef')
            ->assertDontSee('123456:AAH-nested-secret')
            ->assertDontSee('987654:AAH-secret-token');

        $html = (string) $response->getContent();

        $this->assertStringNotContainsString('AAH-', $html);
        $this->assertStringNotContainsString('SELECT * FROM', $html);
        $this->assertStringNotContainsString('DELETE FROM', $html);
        $this->assertStringNotContainsString("'abcdef'", $html);
    }

    public function test_detail_shows_not_configured_telegram_and_the_delivery_panel(): void
    {
        $viewer = User::factory()->create();
        $server = $this->server();
        $alert = $this->alert($server);

        $this->delivery($alert, 'email', 'opened', 'sent', [
            'attempts' => 1,
            'recipient_count' => 3,
            'last_attempt_at' => now()->subMinute(),
            'sent_at' => now()->subMinute(),
            'error' => 'Delivered to 2 of 3 recipients.',
        ]);

        $response = $this->actingAs($viewer)->get(route('alerts.show', $alert));

        $response->assertOk()
            ->assertSee('Delivery')
            ->assertSee('In-app')
            ->assertSee('Delivered')
            ->assertSee('Email')
            ->assertSee('Sent')
            ->assertSee('1 attempt')
            ->assertSee('Delivered to 2 of 3 recipients.')
            ->assertSee('Telegram')
            ->assertSee('Not configured');
    }

    public function test_detail_shows_a_redacted_failure_and_a_recovery_row_after_resolve(): void
    {
        config([
            'alerts.channels.telegram.enabled' => true,
            'alerts.channels.telegram.chat_id' => '-1001234567890',
            'alerts.channels.telegram.bot_token' => '123456:AAH-secret-token',
        ]);

        $operator = User::factory()->create(['role' => 'operator']);
        $server = $this->server();
        $alert = $this->alert($server);

        $this->delivery($alert, 'email', 'opened', 'sent', [
            'attempts' => 1,
            'recipient_count' => 2,
            'last_attempt_at' => now()->subMinute(),
            'sent_at' => now()->subMinute(),
        ]);
        $this->delivery($alert, 'telegram', 'opened', 'failed', [
            'attempts' => 3,
            'last_attempt_at' => now()->subMinute(),
            'error' => 'Telegram responded 401: Unauthorized (https://api.telegram.org/bot123456:AAH-secret-token/sendMessage)',
        ]);

        $this->actingAs($operator);

        $this->get(route('alerts.show', $alert))
            ->assertOk()
            ->assertSee('Failed')
            ->assertSee('3 attempts')
            ->assertSee('[telegram]')
            ->assertDontSee('AAH-secret-token');

        Livewire::test(Show::class, ['alert' => $alert])
            ->set('note', 'Disk usage is back under the threshold.')
            ->call('resolve')
            ->assertHasNoErrors();

        $this->assertSame(2, AlertDelivery::query()->where('event', 'resolved')->count());

        $recoveryStatus = ucfirst((string) AlertDelivery::query()
            ->where('alert_id', $alert->getKey())
            ->where('channel', 'email')
            ->where('event', 'resolved')
            ->value('status'));

        $this->get(route('alerts.show', $alert->fresh()))
            ->assertOk()
            ->assertSee('Recovery')
            ->assertSee($recoveryStatus)
            ->assertDontSee('AAH-secret-token');
    }

    public function test_acknowledge_does_not_resolve_and_writes_an_audit_event(): void
    {
        $operator = User::factory()->create(['role' => 'operator']);
        $server = $this->server();
        $alert = $this->alert($server);

        $this->actingAs($operator);

        Livewire::test(Show::class, ['alert' => $alert])
            ->call('acknowledge')
            ->assertHasNoErrors()
            ->assertSee('Alert acknowledged.');

        $alert->refresh();

        $this->assertSame('acknowledged', $alert->status->value);
        $this->assertSame($operator->getKey(), $alert->acknowledged_by);
        $this->assertNull($alert->resolved_at);
        $this->assertNotNull($alert->active_key);
        $this->assertSame($server->getKey(), explode(':', $alert->active_key)[0]);

        $event = AuditEvent::query()->where('action', 'alert.acknowledged')->sole();

        $this->assertSame($operator->getKey(), $event->user_id);
        $this->assertSame((string) $alert->getKey(), $event->target_id);
    }

    public function test_acknowledge_stops_reminders(): void
    {
        $operator = User::factory()->create(['role' => 'operator']);
        $alert = $this->alert($this->server());

        $this->delivery($alert, 'email', 'opened', 'sent', [
            'attempts' => 1,
            'sent_at' => now()->subHours(7),
            'last_attempt_at' => now()->subHours(7),
        ]);

        $this->actingAs($operator);

        Livewire::test(Show::class, ['alert' => $alert])->call('acknowledge')->assertHasNoErrors();

        Carbon::setTestNow('2026-11-23 20:00:00');
        $this->artisan('noc:alert-reminders')->assertSuccessful();

        $delivery = AlertDelivery::query()->where('alert_id', $alert->getKey())->sole();

        $this->assertSame(1, $delivery->attempts);
        $this->assertSame('sent', $delivery->status);
        $this->assertSame('acknowledged', $alert->fresh()->status->value);
    }

    public function test_resolve_on_an_already_resolved_alert_does_not_validate_the_note(): void
    {
        $operator = User::factory()->create(['role' => 'operator']);
        $alert = $this->alert($this->server(), [
            'status' => 'resolved',
            'active_key' => null,
            'resolved_at' => now()->subMinute(),
            'resolution_note' => 'Issue cleared',
        ]);

        $this->actingAs($operator);

        Livewire::test(Show::class, ['alert' => $alert])
            ->set('note', '')
            ->call('resolve')
            ->assertHasNoErrors()
            ->assertSee('This alert is already resolved.');

        $this->assertSame(0, AuditEvent::query()->where('action', 'alert.resolved')->count());
    }

    public function test_a_failed_action_clears_the_previous_success_message(): void
    {
        $operator = User::factory()->create(['role' => 'operator']);
        $alert = $this->alert($this->server());

        $this->actingAs($operator);

        $component = Livewire::test(Show::class, ['alert' => $alert])
            ->call('acknowledge')
            ->assertSee('Alert acknowledged.');

        $component
            ->set('note', '   ')
            ->call('resolve')
            ->assertHasErrors(['note' => 'required'])
            ->assertDontSee('Alert acknowledged.');
    }

    public function test_resolve_requires_a_note_and_records_the_actor(): void
    {
        $operator = User::factory()->create(['role' => 'operator']);
        $server = $this->server();
        $alert = $this->alert($server);

        $this->actingAs($operator);

        Livewire::test(Show::class, ['alert' => $alert])
            ->set('note', '   ')
            ->call('resolve')
            ->assertHasErrors(['note' => 'required']);

        $this->assertSame('open', $alert->fresh()->status->value);
        $this->assertSame(0, AuditEvent::query()->where('action', 'alert.resolved')->count());

        Livewire::test(Show::class, ['alert' => $alert])
            ->set('note', 'Disk usage back under 85%.')
            ->call('resolve')
            ->assertHasNoErrors()
            ->assertSee('Alert resolved.')
            ->assertSee('that actually opened this alert');

        $alert->refresh();

        $this->assertSame('resolved', $alert->status->value);
        $this->assertSame($operator->getKey(), $alert->resolved_by);
        $this->assertSame('Disk usage back under 85%.', $alert->resolution_note);
        $this->assertNotNull($alert->resolved_at);
        $this->assertNull($alert->active_key);

        $event = AuditEvent::query()->where('action', 'alert.resolved')->sole();

        $this->assertSame($operator->getKey(), $event->user_id);
        $this->assertTrue($event->meta_json['notified']);
    }

    public function test_viewer_cannot_acknowledge_directly(): void
    {
        $viewer = User::factory()->create();
        $alert = $this->alert($this->server());

        $this->actingAs($viewer);

        Livewire::test(Show::class, ['alert' => $alert])
            ->call('acknowledge')
            ->assertStatus(403);
    }

    public function test_viewer_cannot_resolve_directly(): void
    {
        $viewer = User::factory()->create();
        $alert = $this->alert($this->server());

        $this->actingAs($viewer);

        Livewire::test(Show::class, ['alert' => $alert])
            ->set('note', 'Viewer cannot resolve.')
            ->call('resolve')
            ->assertStatus(403);
    }

    public function test_the_bell_counts_open_and_acknowledged_alerts_and_excludes_resolved(): void
    {
        $operator = User::factory()->create(['role' => 'operator']);
        $server = $this->server();

        $this->alert($server, ['rule_key' => 'cpu_high']);
        $this->alert($server, [
            'rule_key' => 'memory_high',
            'status' => 'acknowledged',
            'acknowledged_at' => now()->subMinute(),
            'acknowledged_by' => $operator->getKey(),
        ]);
        $this->alert($server, [
            'rule_key' => 'disk_high',
            'status' => 'resolved',
            'active_key' => null,
            'resolved_at' => now()->subMinute(),
        ]);

        $this->actingAs(User::factory()->create(['role' => 'viewer']));

        Livewire::test(AlertBell::class)
            ->assertViewHas('count', 2)
            ->assertSeeHtml('wire:poll.30s.visible="refresh"')
            ->assertSee('href="/alerts"', false)
            ->assertSeeHtml('bg-critical');
    }

    public function test_the_bell_does_not_chime_on_mount(): void
    {
        $this->alert($this->server());

        Livewire::test(AlertBell::class)
            ->assertViewHas('count', 1)
            ->assertNotDispatched('alert-created');
    }

    public function test_the_bell_dispatches_when_a_new_open_alert_appears(): void
    {
        $server = $this->server();
        $this->alert($server, ['rule_key' => 'cpu_high']);

        $component = Livewire::test(AlertBell::class)
            ->assertViewHas('count', 1)
            ->assertNotDispatched('alert-created');

        Carbon::setTestNow('2026-11-23 12:00:02');

        $this->alert($server, ['rule_key' => 'disk_high']);

        $component
            ->call('refresh')
            ->assertDispatched('alert-created')
            ->assertViewHas('count', 2);
    }

    public function test_the_bell_does_not_dispatch_when_an_alert_is_acknowledged_or_resolved(): void
    {
        $server = $this->server();
        $first = $this->alert($server, ['rule_key' => 'cpu_high']);

        Carbon::setTestNow('2026-11-23 12:00:02');

        $second = $this->alert($server, [
            'rule_key' => 'disk_high',
            'last_seen_at' => now()->addMinute(),
        ]);

        $component = Livewire::test(AlertBell::class)
            ->assertViewHas('count', 2)
            ->assertNotDispatched('alert-created');

        $second->forceFill([
            'status' => 'acknowledged',
            'acknowledged_at' => now(),
        ])->save();

        $component
            ->call('refresh')
            ->assertNotDispatched('alert-created')
            ->assertViewHas('count', 2);

        $first->forceFill([
            'status' => 'resolved',
            'active_key' => null,
            'resolved_at' => now(),
        ])->save();

        $component
            ->call('refresh')
            ->assertNotDispatched('alert-created')
            ->assertViewHas('count', 1);
    }

    public function test_the_shell_includes_the_alert_sound_control_and_asset(): void
    {
        $response = $this->actingAs(User::factory()->create())->get('/dashboard');

        $response->assertOk()
            ->assertSee('data-alert-sound-audio', false)
            ->assertSee('sounds/alert.wav', false)
            ->assertSee('preload="auto"', false)
            ->assertSee('data-alert-sound-toggle', false)
            ->assertSee('data-alert-sound-on', false)
            ->assertSee('data-alert-sound-off', false)
            ->assertSee('aria-label="Alert sound"', false)
            ->assertSee('aria-pressed="true"', false);

        $controller = (string) file_get_contents(resource_path('js/alert-sound.js'));

        $this->assertStringContainsString('alert-created', $controller);
        $this->assertStringContainsString('noc-alert-sound', $controller);
        $this->assertStringContainsString('pointerdown', $controller);
        $this->assertStringContainsString('keydown', $controller);
        $this->assertStringContainsString('pending', $controller);

        $app = (string) file_get_contents(resource_path('js/app.js'));

        $this->assertStringContainsString("from './alert-sound'", $app);
        $this->assertStringContainsString('initAlertSound', $app);

        $wav = public_path('sounds/alert.wav');

        $this->assertFileExists($wav);
        $this->assertGreaterThan(20000, filesize($wav));

        $header = (string) file_get_contents($wav, false, null, 0, 12);

        $this->assertSame('RIFF', substr($header, 0, 4));
        $this->assertSame('WAVE', substr($header, 8, 4));
    }

    public function test_the_detail_page_renders_timestamps_in_jakarta_time(): void
    {
        $viewer = User::factory()->create();
        $alert = $this->alert($this->server(), [
            'first_seen_at' => Carbon::parse('2026-11-23 12:00:00', 'UTC'),
        ]);

        $this->actingAs($viewer)
            ->get(route('alerts.show', $alert))
            ->assertOk()
            ->assertSee('23 Nov 2026 · 19:00 WIB');
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

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function delivery(Alert $alert, string $channel, string $event, string $status, array $attributes = []): AlertDelivery
    {
        return AlertDelivery::query()->create(array_merge([
            'alert_id' => $alert->getKey(),
            'channel' => $channel,
            'event' => $event,
            'status' => $status,
            'recipient_count' => 0,
            'attempts' => 0,
            'error' => null,
        ], $attributes));
    }
}
