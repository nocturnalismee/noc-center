<?php

namespace Tests\Feature\Ui;

use App\Livewire\Settings\DeletedServers;
use App\Models\AuditEvent;
use App\Models\Server;
use App\Models\User;
use App\Services\Servers\ServerService;
use App\Services\SettingsRepository;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

class DeletedServersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-27 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_operators_and_viewers_cannot_open_the_page(): void
    {
        $operator = User::factory()->create(['role' => 'operator']);
        $viewer = User::factory()->create(['role' => 'viewer']);

        $this->actingAs($operator)->get('/settings/deleted-servers')->assertForbidden();
        $this->actingAs($viewer)->get('/settings/deleted-servers')->assertForbidden();
    }

    public function test_the_page_lists_only_soft_deleted_servers(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $active = $this->server('active-01');
        $deleted = $this->server('deleted-01');
        $deleted->delete();

        $this->actingAs($admin)
            ->get('/settings/deleted-servers')
            ->assertOk()
            ->assertSee('deleted-01')
            ->assertDontSee('active-01');

        $this->assertNotSoftDeleted('servers', ['id' => $active->getKey()]);
    }

    public function test_the_grace_period_is_saved_and_audited(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin);

        Livewire::test(DeletedServers::class)
            ->set('graceDays', '0')
            ->call('savePolicy')
            ->assertHasNoErrors()
            ->assertSet('statusMessage', 'Retention policy saved.');

        $this->assertSame(0, app(SettingsRepository::class)->get('retention.deleted_server_days'));
        $this->assertTrue(AuditEvent::query()
            ->where('action', 'settings.updated')
            ->where('meta_json->key', 'retention.deleted_server_days')
            ->exists());
    }

    public function test_a_policy_change_audits_the_old_and_new_values(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        app(SettingsRepository::class)->set('retention.deleted_server_days', 45, 'int');

        $this->actingAs($admin);

        Livewire::test(DeletedServers::class)
            ->set('graceDays', '0')
            ->call('savePolicy')
            ->assertHasNoErrors();

        $event = AuditEvent::query()
            ->where('action', 'settings.updated')
            ->where('meta_json->key', 'retention.deleted_server_days')
            ->sole();

        $this->assertSame(45, $event->meta_json['old']);
        $this->assertSame(0, $event->meta_json['new']);
    }

    public function test_an_unchanged_policy_writes_no_audit_event(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        app(SettingsRepository::class)->set('retention.deleted_server_days', 45, 'int');

        $this->actingAs($admin);

        Livewire::test(DeletedServers::class)
            ->set('graceDays', '45')
            ->call('savePolicy')
            ->assertHasNoErrors()
            ->assertSet('statusMessage', 'Retention policy saved.');

        $this->assertSame(0, AuditEvent::query()->where('action', 'settings.updated')->count());
    }

    public function test_the_grace_period_must_be_between_zero_and_3650_days(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin);

        Livewire::test(DeletedServers::class)
            ->set('graceDays', '3651')
            ->call('savePolicy')
            ->assertHasErrors('graceDays');

        $this->assertDatabaseMissing('settings', ['key' => 'retention.deleted_server_days']);

        Livewire::test(DeletedServers::class)
            ->set('graceDays', '0')
            ->call('savePolicy')
            ->assertHasNoErrors();

        $this->assertSame(0, app(SettingsRepository::class)->get('retention.deleted_server_days'));
    }

    public function test_a_mismatched_confirmation_name_does_not_purge(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $server = $this->server('deleted-01');
        $server->delete();

        $this->actingAs($admin);

        Livewire::test(DeletedServers::class)
            ->call('startPurge', $server->getKey())
            ->set('confirmName', 'wrong')
            ->call('purge')
            ->assertHasErrors('confirmName');

        $this->assertNotNull(Server::withTrashed()->find($server->getKey()));
    }

    public function test_manual_purge_removes_the_server_and_records_the_trigger(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $server = $this->server('deleted-01');
        $server->delete();

        $this->actingAs($admin);

        Livewire::test(DeletedServers::class)
            ->call('startPurge', $server->getKey())
            ->set('confirmName', 'deleted-01')
            ->call('purge')
            ->assertSet('statusMessage', 'Server permanently deleted.');

        $this->assertNull(Server::withTrashed()->find($server->getKey()));
        $event = AuditEvent::query()->where('action', 'server.purged')->sole();
        $this->assertSame('manual', $event->meta_json['trigger']);
        $this->assertSame($admin->getKey(), $event->user_id);
    }

    /**
     * R1: the auto-purge date and state for each grace-period branch.
     */
    public function test_the_page_renders_the_auto_purge_state_for_each_grace_period_branch(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $due = $this->server('due-01');
        $young = $this->server('young-01');

        $due->forceFill(['deleted_at' => now()->subDays(45)])->save();
        $young->forceFill(['deleted_at' => now()->subDays(5)])->save();

        $this->actingAs($admin)
            ->get('/settings/deleted-servers')
            ->assertOk()
            ->assertSee('Due now')
            ->assertSee('In 25 days')
            // 2026-10-13 12:00 UTC + 30 days = 2026-11-12 12:00 UTC = 19:00 WIB.
            ->assertSee('13 Oct 2026 · 19:00 WIB')
            // 2026-11-22 12:00 UTC = 2026-11-22 19:00 WIB.
            ->assertSee('22 Nov 2026 · 19:00 WIB')
            // 2026-12-22 12:00 UTC = 2026-12-22 19:00 WIB.
            ->assertSee('22 Dec 2026')
            ->assertSee('Deleted at')
            ->assertSee('Auto-purge');
    }

    /**
     * R1/I3: a saved grace period of 0 disables auto-purge on a fresh page,
     * in the list column and in the policy summary — not merely in the input.
     */
    public function test_a_zero_day_grace_period_renders_the_disabled_state_on_a_fresh_page(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $server = $this->server('deleted-01');
        $server->delete();

        app(SettingsRepository::class)->set('retention.deleted_server_days', 0, 'int');

        $this->actingAs($admin)
            ->get('/settings/deleted-servers')
            ->assertOk()
            // I1: the policy summary shows the disabled copy…
            ->assertSee('Automatic purge is')
            // …and does not echo a "0 days" sentence or an auto-purge date.
            ->assertDontSee('purged automatically 0 days')
            ->assertDontSee('Deleted servers are purged automatically')
            // I3: the list's Auto-purge column says Disabled for the row.
            ->assertSeeInOrder(['deleted-01', 'Disabled'])
            ->assertDontSee('In 30 days');

        Livewire::test(DeletedServers::class)
            ->assertSet('graceDays', '0');
    }

    /**
     * M2: the summary sentence is built from the integer policy value, so it
     * never echoes unvalidated input. The save itself is still rejected.
     */
    public function test_the_policy_summary_never_echoes_invalid_grace_input(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin);

        $component = Livewire::test(DeletedServers::class)
            ->set('graceDays', 'abc')
            ->call('savePolicy')
            ->assertHasErrors('graceDays');

        $this->assertStringNotContainsString('purged automatically abc', $component->html());

        $negative = Livewire::test(DeletedServers::class)
            ->set('graceDays', '-5')
            ->call('savePolicy')
            ->assertHasErrors('graceDays');

        $this->assertStringNotContainsString('purged automatically', $negative->html());
    }

    /**
     * R2: mounting is admin-gated, and so is the purge action itself.
     *
     * Livewire's authorised-attribute support is not part of the test boot
     * path, so the action is proven directly: a mounted admin component left
     * in an operator session must throw on purge(), even with the exact name.
     */
    public function test_an_operator_cannot_invoke_the_purge_action(): void
    {
        $operator = User::factory()->create(['role' => 'operator']);

        $this->assertPurgeActionIsDenied($operator);
    }

    public function test_a_viewer_cannot_invoke_the_purge_action(): void
    {
        $viewer = User::factory()->create(['role' => 'viewer']);

        $this->assertPurgeActionIsDenied($viewer);
    }

    private function assertPurgeActionIsDenied(User $nonAdmin): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $server = $this->server('deleted-01');
        $server->delete();

        $this->actingAs($admin);

        $component = Livewire::test(DeletedServers::class)
            ->call('startPurge', $server->getKey())
            ->set('confirmName', 'deleted-01');

        $this->actingAs($nonAdmin);

        try {
            $component->instance()->purge();
            $this->fail('A non-admin reached the purge action.');
        } catch (AuthorizationException) {
            // Expected: ServerPolicy::purge() denies non-admins.
        }

        $this->assertNotNull(Server::withTrashed()->find($server->getKey()));
        $this->assertFalse(AuditEvent::query()->where('action', 'server.purged')->exists());
    }

    /**
     * A non-admin cannot save the retention policy either: savePolicy() is
     * re-authorised on the action, not only when the page mounts.
     */
    public function test_an_operator_cannot_save_the_retention_policy(): void
    {
        $this->assertPolicySaveIsDenied(User::factory()->create(['role' => 'operator']));
    }

    public function test_a_viewer_cannot_save_the_retention_policy(): void
    {
        $this->assertPolicySaveIsDenied(User::factory()->create(['role' => 'viewer']));
    }

    private function assertPolicySaveIsDenied(User $nonAdmin): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        app(SettingsRepository::class)->set('retention.deleted_server_days', 30, 'int');

        $this->actingAs($admin);

        $component = Livewire::test(DeletedServers::class);

        $this->actingAs($nonAdmin);

        $component->instance()->graceDays = '0';

        try {
            $component->instance()->savePolicy();
            $this->fail('A non-admin saved the retention policy.');
        } catch (AuthorizationException) {
            // Expected: SettingPolicy::viewAny() denies non-admins.
        }

        $this->assertSame(30, app(SettingsRepository::class)->get('retention.deleted_server_days'));
        $this->assertFalse(AuditEvent::query()
            ->where('action', 'settings.updated')
            ->where('meta_json->key', 'retention.deleted_server_days')
            ->exists());
    }

    /**
     * A row purged between render and click must report cleanly, not 404.
     */
    public function test_starting_a_purge_for_an_already_purged_server_reports_cleanly(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $server = $this->server('deleted-01');
        $server->delete();

        $this->actingAs($admin);

        $component = Livewire::test(DeletedServers::class);

        // Another actor purges the row while this page is still open.
        $server->forceDelete();

        $component->call('startPurge', $server->getKey())
            ->assertOk()
            ->assertSet('statusMessage', 'That server was already purged.')
            ->assertSet('confirmingId', null)
            ->assertSet('confirmCounts', []);
    }

    public function test_purging_a_confirmed_server_that_vanished_reports_cleanly(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $server = $this->server('deleted-01');
        $server->delete();

        $this->actingAs($admin);

        $component = Livewire::test(DeletedServers::class)
            ->call('startPurge', $server->getKey())
            ->set('confirmName', 'deleted-01');

        // The row disappears after the confirmation was rendered.
        $server->forceDelete();

        $component->call('purge')
            ->assertOk()
            ->assertSet('statusMessage', 'That server was already purged.')
            ->assertSet('confirmingId', null);

        $this->assertSame(0, AuditEvent::query()->where('action', 'server.purged')->count());
    }

    /**
     * R3: the confirmation preview shows the real counts for the server.
     */
    public function test_starting_a_purge_previews_the_cascade_counts(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $server = $this->server('deleted-01');
        $server->samples()->create([
            'collected_at' => now()->subMinute(),
            'received_at' => now()->subMinute(),
            'schema_version' => 1,
            'interval_seconds' => 60,
            'cpu_usage_pct' => 10.0,
            'cpu_iowait_pct' => 0.1,
            'cpu_cores' => 2,
            'load1' => 0.1,
            'load5' => 0.1,
            'load15' => 0.1,
            'mem_total_bytes' => 1_000,
            'mem_used_bytes' => 100,
            'mem_used_pct' => 10.0,
            'swap_total_bytes' => 0,
            'swap_used_bytes' => 0,
            'swap_used_pct' => 0,
            'disk_total_bytes' => 1_000,
            'disk_used_bytes' => 100,
            'disk_used_pct' => 10.0,
            'disk_inode_pct' => 10.0,
            'net_rx_bps' => 0,
            'net_tx_bps' => 0,
            'processes_running' => 1,
            'processes_blocked' => 0,
            'uptime_seconds' => 100,
            'payload_bytes' => 10,
        ]);
        $server->delete();

        $expected = app(ServerService::class)->purgeCounts($server);
        $this->assertSame(1, $expected['samples']);

        $this->actingAs($admin);

        Livewire::test(DeletedServers::class)
            ->call('startPurge', $server->getKey())
            ->assertSet('confirmingId', $server->getKey())
            ->assertSet('confirmCounts', $expected)
            ->assertSee('Samples')
            ->assertSee('Deliveries');
    }

    private function server(string $name): Server
    {
        return Server::query()->create([
            'name' => $name,
            'hostname' => $name.'.example.test',
            'is_active' => true,
        ]);
    }
}
