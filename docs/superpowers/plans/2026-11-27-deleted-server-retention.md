# Deleted Server Retention Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a configurable grace period, a daily auto-purge command, and an admin Settings tab that lists soft-deleted servers and permanently purges one after typed-name confirmation.

**Architecture:** Keep `ServerService::purge()` as the single destructive primitive. Tag its audit event with `manual` or `auto`. A new scheduled command selects only expired soft-deleted rows and calls that primitive; a new admin Livewire page exposes the same policy and primitive with a count preview and typed-name confirmation. The grace period is a typed `SettingsRepository` override, so no migration is needed.

**Tech Stack:** Laravel 13, PHP 8.3/8.4, Livewire 4, Blade, Tailwind, SQLite, PHPUnit 12, Pint.

**Spec:** `docs/superpowers/specs/2026-11-27-deleted-server-retention-design.md`

## Global Constraints

- Grace period default is **30 days**. `0` disables automatic purge. Valid range is `0..3650`.
- Only `Server::onlyTrashed()` rows may be purged. Active servers remain refused.
- Auto-purge cutoff is strict: `deleted_at < now()->utc()->subDays($days)`. A row exactly at the cutoff survives.
- Schedule: `noc:purge-deleted-servers` daily at **04:30 Asia/Jakarta**, after the existing 03:30 `noc:prune`, with `withoutOverlapping(30)`.
- Never run `VACUUM` automatically from the scheduled command.
- Audit event is `server.purged`; metadata includes `server_id`, `name`, `counts`, and `trigger` (`manual` or `auto`). Audit rows survive.
- The existing `tokens` integer exception in `AuditLogger` must remain unchanged.
- Settings UI is admin-only through `SettingPolicy::viewAny()`; manual purge additionally uses the new admin-only `ServerPolicy::purge()`.
- `noc:purge-server` keeps its current signature and behaviour; its trigger defaults to `manual`.
- No schema migration. The grace setting is a `settings` row keyed `retention.deleted_server_days`, type `int`.
- No new dependency. UI copy is English. Timestamps are stored UTC and displayed `Asia/Jakarta`.
- Use `./tools/php.sh` and `./tools/test.sh`; do not use host PHP directly. TDD for every task.
- Do not add restore, generic retention editors, or a deleted tab to the Servers table.

## Review Focus

- A server deleted exactly at the cutoff must survive; one second older must be purged. Covered by Task 2 cutoff tests.
- `retention.deleted_server_days = 0` must disable auto-purge but leave manual purge available. Covered by Task 2 disabled test and Task 3 policy test.
- A blank or mismatched confirmation name must never purge. Covered by Task 3 confirmation tests.
- One failing purge in a batch must not stop later servers and must be reported. Covered by Task 2 failure test.
- Counts shown before purge must match the rows the cascade removes, and a second purge of the same row must fail cleanly. Covered by Task 1 service tests and Task 3 count-preview test.

## File Structure

- `config/retention.php` — add `deleted_server_days`.
- `app/Services/Servers/ServerService.php` — add trigger parameter to `purge()` and audit metadata.
- `app/Policies/ServerPolicy.php` — add admin-only `purge()`.
- `app/Console/Commands/PurgeDeletedServers.php` — new scheduled command.
- `routes/console.php` — schedule the new command.
- `app/Livewire/Settings/DeletedServers.php` — new admin page component.
- `resources/views/livewire/settings/deleted-servers.blade.php` — new page view.
- `resources/views/livewire/settings/nav.blade.php` — add the new tab.
- `resources/views/livewire/settings/index.blade.php` — add the settings hub entry.
- `routes/web.php` — add `/settings/deleted-servers`.
- `tests/Feature/Services/ServerPurgeTest.php` — trigger metadata.
- `tests/Feature/Auth/PolicyMatrixTest.php` — admin-only purge policy.
- `tests/Feature/Console/PurgeDeletedServersCommandTest.php` — new command + schedule tests.
- `tests/Feature/Ui/DeletedServersTest.php` — new Settings page tests.
- `tests/Feature/Ui/SettingsTest.php` — nav/hub assertions.

---

### Task 1: Extend the purge contract and add the admin purge policy

**Files:**
- Modify: `config/retention.php`
- Modify: `app/Services/Servers/ServerService.php:266-322`
- Modify: `app/Policies/ServerPolicy.php`
- Test: `tests/Feature/Services/ServerPurgeTest.php`
- Test: `tests/Feature/Auth/PolicyMatrixTest.php`

**Interfaces:**
- Produces: `ServerService::purge(Server $server, ?User $actor = null, string $trigger = 'manual'): array`
- Produces: `ServerPolicy::purge(User $user, Server $server): bool`
- Produces: `config('retention.deleted_server_days')` = `30`

- [ ] **Step 1: Write the failing service tests**

Add to `tests/Feature/Services/ServerPurgeTest.php`:

```php
public function test_purge_records_the_manual_trigger_by_default(): void
{
    $server = $this->deletedServerWithHistory();

    $this->servers->purge($server);

    $event = AuditEvent::query()->where('action', 'server.purged')->sole();

    $this->assertSame('manual', $event->meta_json['trigger']);
}

public function test_purge_records_the_auto_trigger_when_given(): void
{
    $server = $this->deletedServerWithHistory();

    $this->servers->purge($server, null, 'auto');

    $event = AuditEvent::query()->where('action', 'server.purged')->sole();

    $this->assertSame('auto', $event->meta_json['trigger']);
    $this->assertNull($event->user_id);
}
```

- [ ] **Step 2: Run the service tests and verify RED**

Run:

```bash
./tools/php.sh php artisan test tests/Feature/Services/ServerPurgeTest.php
```

Expected: both tests fail because `meta_json['trigger']` is missing.

- [ ] **Step 3: Add the trigger parameter**

In `ServerService::purge()`, change the signature to:

```php
public function purge(Server $server, ?User $actor = null, string $trigger = 'manual'): array
```

Add `'trigger' => $trigger` to the `server.purged` audit metadata. Do not change the count keys, transaction boundary, or `trashed()` guard.

- [ ] **Step 4: Add the policy test**

In `tests/Feature/Auth/PolicyMatrixTest.php`, add this row to the existing matrix:

```php
'ServerPolicy purge' => ['purge', 'server', true, false, false],
```

The columns are admin, operator, viewer.

- [ ] **Step 5: Implement the policy**

Add to `app/Policies/ServerPolicy.php`:

```php
public function purge(User $user, Server $server): bool
{
    return $user->isAdmin();
}
```

- [ ] **Step 6: Add the config default**

In `config/retention.php`, add:

```php
// Soft-deleted servers are retained this many days before automatic purge.
// 0 disables automatic purge; manual purge remains available.
'deleted_server_days' => 30,
```

- [ ] **Step 7: Run the focused tests and verify GREEN**

Run:

```bash
./tools/php.sh php artisan test tests/Feature/Services/ServerPurgeTest.php tests/Feature/Auth/PolicyMatrixTest.php
```

Expected: PASS.

- [ ] **Step 8: Run the full gate**

Run:

```bash
./tools/test.sh
```

Expected: Pint clean, all tests pass.

- [ ] **Step 9: Commit**

```bash
git add config/retention.php app/Services/Servers/ServerService.php app/Policies/ServerPolicy.php tests/Feature/Services/ServerPurgeTest.php tests/Feature/Auth/PolicyMatrixTest.php
git commit -m "feat: add purge trigger and admin purge policy"
```

---

### Task 2: Add the scheduled auto-purge command

**Files:**
- Create: `app/Console/Commands/PurgeDeletedServers.php`
- Modify: `routes/console.php`
- Test: `tests/Feature/Console/PurgeDeletedServersCommandTest.php`

**Interfaces:**
- Consumes: `ServerService::purge(Server, ?User, string $trigger = 'manual'): array`
- Consumes: `SettingsRepository::get(string, mixed): mixed`
- Consumes: `Server::onlyTrashed()`
- Produces: artisan command `noc:purge-deleted-servers {--dry-run}`

- [ ] **Step 1: Write the failing command tests**

Create `tests/Feature/Console/PurgeDeletedServersCommandTest.php` with `RefreshDatabase`, `Carbon::setTestNow('2026-11-27 12:00:00')`, and these cases:

```php
public function test_zero_days_disables_automatic_purge(): void
{
    app(SettingsRepository::class)->set('retention.deleted_server_days', 0, 'int');
    $server = $this->deletedAt('old-01', now()->subYear());

    $this->artisan('noc:purge-deleted-servers')
        ->expectsOutputToContain('Automatic purge is disabled')
        ->assertSuccessful();

    $this->assertNotNull(Server::withTrashed()->find($server->getKey()));
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
        ->expectsOutputToContain('Would purge server [old-01]')
        ->expectsOutputToContain('Dry run: nothing was deleted.')
        ->assertSuccessful();

    $this->assertNotNull(Server::withTrashed()->find($server->getKey()));
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
```

Also add:

```php
public function test_one_failure_does_not_stop_the_batch_and_returns_failure(): void
{
    app(SettingsRepository::class)->set('retention.deleted_server_days', 30, 'int');
    $first = $this->deletedAt('bad-01', now()->subDays(31));
    $second = $this->deletedAt('good-01', now()->subDays(31));

    $this->mock(ServerService::class, function ($mock) use ($first, $second): void {
        $mock->shouldReceive('purge')
            ->twice()
            ->andReturnUsing(function (Server $server) use ($first): array {
                if ($server->getKey() === $first->getKey()) {
                    throw new RuntimeException('database is locked');
                }

                return ['samples' => 0, 'details' => 0, 'rollups' => 0, 'alerts' => 0, 'deliveries' => 0, 'tokens' => 0];
            });
    });

    $this->artisan('noc:purge-deleted-servers')
        ->expectsOutputToContain('Purge failed for [bad-01]')
        ->assertFailed();

    $this->assertNotNull(Server::withTrashed()->find($first->getKey()));
    $this->assertNotNull(Server::withTrashed()->find($second->getKey()));
}
```

Fixture helper:

```php
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
```

Add a schedule test:

```php
public function test_the_command_is_scheduled_daily_at_04_30_jakarta(): void
{
    $schedule = $this->app->make(Schedule::class);

    $events = array_values(array_filter(
        $schedule->events(),
        static fn (Event $event): bool => preg_match('/[\'"]?noc:purge-deleted-servers[\'"]?(?![[:alnum:]_:.-])/', (string) $event->command) === 1,
    ));

    $this->assertCount(1, $events);
    $this->assertSame('30 4 * * *', $events[0]->expression);
    $this->assertSame('Asia/Jakarta', $events[0]->timezone);
    $this->assertTrue($events[0]->withoutOverlapping);
    $this->assertSame(30, $events[0]->expiresAt);
    $this->assertFalse($events[0]->onOneServer);
}
```

Add `use Illuminate\Console\Scheduling\Event;` and `use App\Services\SettingsRepository;` to this test file.

- [ ] **Step 2: Run the tests and verify RED**

Run:

```bash
./tools/php.sh php artisan test tests/Feature/Console/PurgeDeletedServersCommandTest.php
```

Expected: fail because the command does not exist / schedule entry missing.

- [ ] **Step 3: Implement the command**

Create `app/Console/Commands/PurgeDeletedServers.php`:

```php
class PurgeDeletedServers extends Command
{
    protected $signature = 'noc:purge-deleted-servers
        {--dry-run : Report eligible servers without deleting anything}';

    protected $description = 'Permanently purge soft-deleted servers past the retention grace period';

    public function handle(
        ServerService $servers,
        SettingsRepository $settings,
        RunTracker $tracker,
    ): int {
        return $tracker->run(
            (bool) $this->option('dry-run') ? 'purge-deleted-servers-dry-run' : 'purge-deleted-servers',
            fn (): int => $this->perform($servers, $settings),
        );
    }
}
```

`perform()`:

- reads `(int) $settings->get('retention.deleted_server_days', config('retention.deleted_server_days'))`;
- clamps to `>= 0`;
- if `0`, prints `Automatic purge is disabled (retention.deleted_server_days = 0).` and returns `SUCCESS`;
- cutoff `CarbonImmutable::now('UTC')->subDays($days)`;
- selects `Server::onlyTrashed()->where('deleted_at', '<', $cutoff)->orderBy('deleted_at')->get()`;
- dry-run calls `$servers->purgeCounts($server)` for the counts, prints `Would purge server [name]: 1 samples, ...` and `Dry run: nothing was deleted.`; returns `SUCCESS`;
- real run calls `$servers->purge($server, null, 'auto')` per server;
- per-server `Throwable`: prints `Purge failed for [name]: <bounded message>`, increments failures, continues;
- prints `Purged N deleted servers.` (or `No deleted servers were due.`);
- returns `FAILURE` if any failed, otherwise `SUCCESS`.

The command must not call `VACUUM`.

- [ ] **Step 4: Add the schedule**

In `routes/console.php`, after the `noc:prune` entry:

```php
Schedule::command('noc:purge-deleted-servers')
    ->dailyAt('04:30')
    ->timezone('Asia/Jakarta')
    ->withoutOverlapping(30);
```

- [ ] **Step 5: Run focused tests and verify GREEN**

```bash
./tools/php.sh php artisan test tests/Feature/Console/PurgeDeletedServersCommandTest.php
```

Expected: PASS.

- [ ] **Step 6: Run the full gate**

```bash
./tools/test.sh
```

Expected: Pint clean, all tests pass.

- [ ] **Step 7: Commit**

```bash
git add app/Console/Commands/PurgeDeletedServers.php routes/console.php tests/Feature/Console/PurgeDeletedServersCommandTest.php
git commit -m "feat: auto-purge deleted servers after grace period"
```

---

### Task 3: Add the Settings “Deleted servers” tab

**Files:**
- Create: `app/Livewire/Settings/DeletedServers.php`
- Create: `resources/views/livewire/settings/deleted-servers.blade.php`
- Modify: `routes/web.php:46-50`
- Modify: `resources/views/livewire/settings/nav.blade.php`
- Modify: `resources/views/livewire/settings/index.blade.php`
- Test: `tests/Feature/Ui/DeletedServersTest.php`
- Test: `tests/Feature/Ui/SettingsTest.php`

**Interfaces:**
- Consumes: `ServerPolicy::purge(User, Server): bool`
- Consumes: `ServerService::purge(Server, ?User, string $trigger = 'manual'): array`
- Consumes: `ServerService::purgeCounts(Server): array`
- Consumes: `SettingsRepository::get/set`
- Produces: route `settings.deleted-servers`
- Produces: Livewire component `App\Livewire\Settings\DeletedServers`

- [ ] **Step 1: Write the failing Settings tests**

In `tests/Feature/Ui/SettingsTest.php`, extend the role-aware/nav assertions so admins see:

```php
->assertSee('href="'.route('settings.deleted-servers').'"', false)
```

and operators/viewers do not.

Create `tests/Feature/Ui/DeletedServersTest.php` with `RefreshDatabase` and `Carbon::setTestNow('2026-11-27 12:00:00')`:

```php
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
```

Add a validation test for `'3651'` failing `graceDays` and `'0'` passing.

Add this fixture helper to the same test class:

```php
private function server(string $name): Server
{
    return Server::query()->create([
        'name' => $name,
        'hostname' => $name.'.example.test',
        'is_active' => true,
    ]);
}
```

- [ ] **Step 2: Run the tests and verify RED**

```bash
./tools/php.sh php artisan test tests/Feature/Ui/DeletedServersTest.php tests/Feature/Ui/SettingsTest.php
```

Expected: fail because the route/component do not exist.

- [ ] **Step 3: Implement the Livewire component**

Create `app/Livewire/Settings/DeletedServers.php` with `WithPagination` and:

```php
public string $graceDays = '30';
public ?string $statusMessage = null;
public ?string $confirmingId = null;
public string $confirmName = '';
public array $confirmCounts = [];

public function mount(): void
{
    Gate::authorize('viewAny', Setting::class);
    $this->graceDays = (string) app(SettingsRepository::class)
        ->get('retention.deleted_server_days', config('retention.deleted_server_days'));
}

public function savePolicy(): void
{
    Gate::authorize('viewAny', Setting::class);
    $this->validate(['graceDays' => ['required', 'integer', 'between:0,3650']]);

    $settings = app(SettingsRepository::class);
    $current = (int) $settings->get('retention.deleted_server_days', config('retention.deleted_server_days'));
    $next = (int) $this->graceDays;

    if ($current !== $next) {
        $settings->set('retention.deleted_server_days', $next, 'int');
        app(AuditLogger::class)->log('settings.updated', null, [
            'key' => 'retention.deleted_server_days',
        ]);
    }

    $this->statusMessage = 'Retention policy saved.';
}

public function startPurge(string $serverId): void
{
    $server = Server::onlyTrashed()->findOrFail($serverId);
    Gate::authorize('purge', $server);

    $this->confirmingId = $serverId;
    $this->confirmName = '';
    $this->confirmCounts = app(ServerService::class)->purgeCounts($server);
    $this->resetErrorBag();
}

public function cancelPurge(): void
{
    $this->confirmingId = null;
    $this->confirmName = '';
    $this->confirmCounts = [];
    $this->resetErrorBag();
}

public function purge(): void
{
    $server = Server::onlyTrashed()->findOrFail((string) $this->confirmingId);
    Gate::authorize('purge', $server);

    if (trim($this->confirmName) !== $server->name) {
        $this->addError('confirmName', 'Type the server name exactly to purge it.');

        return;
    }

    try {
        app(ServerService::class)->purge($server, auth()->user(), 'manual');
    } catch (Throwable) {
        $this->addError('purge', 'Purge failed. The server was not deleted.');

        return;
    }

    $this->cancelPurge();
    $this->resetPage();
    $this->statusMessage = 'Server permanently deleted.';
}
```

`render()` starts with `Gate::authorize('viewAny', Setting::class)` so a Livewire re-render is also admin-gated, then passes:

```php
'deletedServers' => Server::onlyTrashed()->orderByDesc('deleted_at')->paginate(25),
'graceDays' => (int) $this->graceDays,
```

Add helpers `autoPurgeAt(Server $server): ?CarbonInterface` and `autoPurgeState(Server $server): string` returning `Disabled` when `graceDays === 0`, `Due now` when due, otherwise `In N days`.

- [ ] **Step 4: Create the view**

Create `resources/views/livewire/settings/deleted-servers.blade.php` with:

- title/breadcrumb `Settings / Deleted servers`;
- `@include('livewire.settings.nav')`;
- `<x-status-toast :message="$statusMessage" />`;
- page intro exactly: “Servers removed from the fleet keep their history until they are purged. Automatic purge runs daily after the grace period.”;
- the **Auto-purge after** form (`wire:submit="savePolicy"`) with a number input `graceDays`, helper exactly: “Days after deletion before the server and its history are removed. Set to 0 to disable automatic purge.”, and **Save retention policy**;
- the deleted-server list with columns Server, Deleted at, Auto-purge, Action; render deleted-at as `$server->deleted_at->timezone('Asia/Jakarta')->format('d M Y · H:i').' WIB'` and the auto-purge date as `d M Y`;
- empty state exactly: “No deleted servers. Servers removed from the fleet appear here until they are purged.”;
- per-row **Purge now** button calling `startPurge($server->id)`;
- inline confirmation when `$confirmingId === $server->id`, showing counts, the typed-name input, **Purge permanently** (`wire:click="purge"`), and **Cancel**;
- confirmation copy exactly: “This permanently removes the server and its history. Audit records are kept. This cannot be undone.”;
- the note that automatic purge runs daily and SQLite may not shrink until `VACUUM`.

Use existing classes (`border-t border-border`, `field`, `primary-button`, `secondary-button`, `text-text-sub`, etc.).

- [ ] **Step 5: Wire navigation and route**

In `routes/web.php`, add:

```php
Route::livewire('/settings/deleted-servers', DeletedServers::class)
    ->name('settings.deleted-servers');
```

In `resources/views/livewire/settings/nav.blade.php`, add an admin section after Alerts:

```php
$sections[] = [
    'label' => 'Deleted servers',
    'href' => route('settings.deleted-servers'),
    'active' => request()->is('settings/deleted-servers'),
];
```

In `resources/views/livewire/settings/index.blade.php`, add the admin entry:

```php
'label' => 'Deleted servers',
'href' => route('settings.deleted-servers'),
'description' => 'Auto-purge policy and permanent purge for servers removed from the fleet.',
'meta' => 'Administrators',
```

- [ ] **Step 6: Run focused tests and verify GREEN**

```bash
./tools/php.sh php artisan test tests/Feature/Ui/DeletedServersTest.php tests/Feature/Ui/SettingsTest.php tests/Feature/Auth/PolicyMatrixTest.php
```

Expected: PASS.

- [ ] **Step 7: Run the full gate**

```bash
./tools/test.sh
```

Expected: Pint clean, all tests pass.

- [ ] **Step 8: Commit**

```bash
git add app/Livewire/Settings/DeletedServers.php resources/views/livewire/settings/deleted-servers.blade.php routes/web.php resources/views/livewire/settings/nav.blade.php resources/views/livewire/settings/index.blade.php tests/Feature/Ui/DeletedServersTest.php tests/Feature/Ui/SettingsTest.php
git commit -m "feat: add deleted servers settings tab"
```

---

## Verification checklist

- [ ] Grace period can be set from Settings, including `0` to disable.
- [ ] Admin sees the Deleted servers tab; operator and viewer do not.
- [ ] Deleted servers list excludes active servers.
- [ ] Manual purge requires the exact name and shows counts before deleting.
- [ ] Manual purge deletes samples/details/rollups/alerts/deliveries/tokens, keeps audit, and records `trigger: manual`.
- [ ] Auto command purges only `deleted_at < cutoff`, records `trigger: auto`, and skips when days = 0.
- [ ] One failed auto purge does not stop later servers and returns failure.
- [ ] Schedule is daily 04:30 Asia/Jakarta and does not run VACUUM.
- [ ] `noc:purge-server` still works with a `manual` trigger.
- [ ] Full `./tools/test.sh` is Pint clean and green.