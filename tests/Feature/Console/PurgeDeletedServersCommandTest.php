<?php

namespace Tests\Feature\Console;

use App\Models\AuditEvent;
use App\Models\Server;
use App\Models\ServerToken;
use App\Services\SettingsRepository;
use Carbon\CarbonInterface;
use Illuminate\Console\Scheduling\Event as ScheduleEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event as EventFacade;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * `noc:purge-deleted-servers` (deleted-server retention plan).
 *
 * The scheduled command purges soft-deleted servers once they are past the
 * retention grace period. A setting of 0 disables it, the cutoff is strict,
 * a dry run counts without writing, failures do not stop the batch, and the
 * purge is audited with the `auto` trigger and no actor.
 */
class PurgeDeletedServersCommandTest extends TestCase
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

    public function test_zero_days_disables_automatic_purge(): void
    {
        app(SettingsRepository::class)->set('retention.deleted_server_days', 0, 'int');
        $server = $this->deletedAt('old-01', now()->subYear());

        $this->artisan('noc:purge-deleted-servers')
            ->expectsOutputToContain('Automatic purge is disabled')
            ->assertSuccessful();

        $this->assertNotNull(Server::withTrashed()->find($server->getKey()));
    }

    public function test_negative_days_are_clamped_to_zero_and_disable_purge(): void
    {
        app(SettingsRepository::class)->set('retention.deleted_server_days', -30, 'int');
        $server = $this->deletedAt('old-01', now()->subYear());

        $this->artisan('noc:purge-deleted-servers')
            ->expectsOutputToContain('Automatic purge is disabled')
            ->assertSuccessful();

        $this->assertNotNull(Server::withTrashed()->find($server->getKey()));
        $this->assertSame(0, AuditEvent::query()->where('action', 'server.purged')->count());
    }

    public function test_only_servers_older_than_the_cutoff_are_purged(): void
    {
        app(SettingsRepository::class)->set('retention.deleted_server_days', 30, 'int');
        $old = $this->deletedAt('old-01', now()->subDays(31));
        $edge = $this->deletedAt('edge-01', now()->subDays(30));
        $young = $this->deletedAt('young-01', now()->subDays(29));

        $this->artisan('noc:purge-deleted-servers')->assertSuccessful();

        $this->assertNull(Server::withTrashed()->find($old->getKey()));
        $this->assertNotNull(Server::withTrashed()->find($edge->getKey()));
        $this->assertNotNull(Server::withTrashed()->find($young->getKey()));
    }

    public function test_dry_run_reports_counts_and_changes_nothing(): void
    {
        app(SettingsRepository::class)->set('retention.deleted_server_days', 30, 'int');
        $server = $this->deletedAt('old-01', now()->subDays(31));
        $this->token($server);

        $this->artisan('noc:purge-deleted-servers', ['--dry-run' => true])
            ->expectsOutput('Would purge server [old-01]: 0 samples, 0 details, 0 rollups, 0 alerts, 0 deliveries, 1 tokens.')
            ->expectsOutputToContain('Dry run: nothing was deleted.')
            ->assertSuccessful();

        $this->assertNotNull(Server::withTrashed()->find($server->getKey()));
        $this->assertSame(1, ServerToken::query()->count());
        $this->assertSame(0, AuditEvent::query()->where('action', 'server.purged')->count());
    }

    public function test_auto_purge_writes_auto_trigger_and_no_actor(): void
    {
        app(SettingsRepository::class)->set('retention.deleted_server_days', 30, 'int');
        $server = $this->deletedAt('old-01', now()->subDays(31));

        $this->artisan('noc:purge-deleted-servers')->assertSuccessful();

        $event = AuditEvent::query()->where('action', 'server.purged')->sole();
        $this->assertSame('auto', $event->meta_json['trigger']);
        $this->assertNull($event->user_id);
    }

    public function test_one_failure_does_not_stop_the_batch_and_returns_failure(): void
    {
        app(SettingsRepository::class)->set('retention.deleted_server_days', 30, 'int');
        $bad = $this->deletedAt('bad-01', now()->subDays(31));
        $good = $this->deletedAt('good-01', now()->subDays(31));
        $badKey = (string) $bad->getKey();

        // Real service, real cascade: only the first server's force delete
        // fails, so the command must log it and carry on with the second.
        $listener = function (Server $server) use ($badKey): void {
            if ((string) $server->getKey() === $badKey) {
                throw new RuntimeException('database is locked');
            }
        };

        EventFacade::listen('eloquent.forceDeleting: '.Server::class, $listener);

        try {
            $this->artisan('noc:purge-deleted-servers')
                ->expectsOutputToContain('Purge failed for [bad-01]')
                ->expectsOutputToContain('Purged 1 deleted servers.')
                ->assertFailed();
        } finally {
            EventFacade::forget('eloquent.forceDeleting: '.Server::class);
        }

        $this->assertNotNull(Server::withTrashed()->find($badKey));
        $this->assertNull(Server::withTrashed()->find($good->getKey()));

        $event = AuditEvent::query()->where('action', 'server.purged')->sole();
        $this->assertSame($good->getKey(), $event->meta_json['server_id']);
        $this->assertSame('auto', $event->meta_json['trigger']);
    }

    public function test_nothing_due_reports_cleanly(): void
    {
        app(SettingsRepository::class)->set('retention.deleted_server_days', 30, 'int');
        $server = $this->deletedAt('young-01', now()->subDays(5));

        $this->artisan('noc:purge-deleted-servers')
            ->expectsOutputToContain('No deleted servers were due.')
            ->assertSuccessful();

        $this->assertNotNull(Server::withTrashed()->find($server->getKey()));
    }

    public function test_the_command_is_scheduled_daily_at_04_30_jakarta(): void
    {
        $schedule = $this->app->make(Schedule::class);

        $events = array_values(array_filter(
            $schedule->events(),
            static fn (ScheduleEvent $event): bool => preg_match('/[\'"]?noc:purge-deleted-servers[\'"]?(?![[:alnum:]_:.-])/', (string) $event->command) === 1,
        ));

        $this->assertCount(1, $events);
        $this->assertSame('30 4 * * *', $events[0]->expression);
        $this->assertSame('Asia/Jakarta', $events[0]->timezone);
        $this->assertTrue($events[0]->withoutOverlapping);
        $this->assertSame(30, $events[0]->expiresAt);
        $this->assertFalse($events[0]->onOneServer);
    }

    // ------------------------------------------------------------------
    // Fixtures
    // ------------------------------------------------------------------

    private function deletedAt(string $name, CarbonInterface $deletedAt): Server
    {
        $server = Server::query()->create([
            'name' => $name,
            'hostname' => $name.'.example.test',
            'is_active' => true,
        ]);

        $server->forceFill(['deleted_at' => $deletedAt])->save();

        return $server;
    }

    private function token(Server $server): void
    {
        ServerToken::query()->create([
            'server_id' => $server->getKey(),
            'token_hash' => hash('sha256', Str::random(32)),
            'token_prefix' => Str::random(8),
        ]);
    }
}
