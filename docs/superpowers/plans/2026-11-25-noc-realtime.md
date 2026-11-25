# NOC Center Realtime Updates Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add Laravel Reverb-backed realtime updates to the operational screens while preserving a polling fallback for shared hosting and Reverb outages.

**Architecture:** Laravel emits small private-channel broadcast events after sample, alert, server, and scheduled-operation changes. Laravel Echo subscribes in the browser and triggers debounced Livewire refreshes; charts remount after morphs. A server-rendered `NOC_REALTIME` flag enables Echo on VPS deployments and leaves polling in place elsewhere.

**Tech Stack:** Laravel 13.17+, PHP 8.3/8.4, Livewire 4.4, Laravel Reverb, Laravel Echo, `pusher-js`, Vite 8, SQLite, Nginx, systemd.

**Spec:** `docs/superpowers/specs/2026-11-25-noc-realtime-design.md`

## Global Constraints

- Reverb is optional: `NOC_REALTIME=false` must keep the application fully usable through polling.
- No Node/npm requirement in production; `public/build` is built locally or in CI and committed.
- All broadcast channels are private; no public fleet, alert, or server channels.
- Event payloads contain identifiers/state only. Never broadcast alert text, context, tokens, email addresses, IPs, or query text.
- Broadcast events use `$afterCommit = true`; a rolled-back transaction must not emit a visible update.
- Ingest must return `202` even if broadcasting or the queue is unavailable.
- Existing timestamps remain UTC in storage and `Asia/Jakarta` in display.
- All UI copy stays English.
- The excluded pages are Activity, Users, and Settings. The global alert bell may still update on those pages.
- Keep one row per `operational_runs.task_key`; do not regress the RunTracker `upsert` behavior.
- `config/cache.php` keeps `serializable_classes => false`; never put Eloquent models or collections in cache.
- Run PHP only through `./tools/php.sh` / `./tools/test.sh`. Do not run host PHP.
- Each task ends with Pint clean and its focused tests passing. The final task runs the full suite and builds assets.

## Review Focus

- **Reverb outage:** ingest, alert evaluation, HTTP pages, and normal Livewire actions must still work when the Reverb daemon is stopped or the queue is backed up.
- **Inactive/deleted user:** an existing browser session must not continue receiving private fleet, server, or alert events after deactivation or deletion.
- **Stale or duplicate sample:** a replayed older sample must not make the dashboard regress to older metrics or an older health state.
- **Event burst:** 10–100 servers reporting every 60 seconds must not create an unbounded Livewire refresh loop; debounce, per-component cleanup, and a slow polling safety net are required.
- **Chart state:** a realtime refresh on server detail must preserve the selected range/tab and must not bypass the chart’s loading, empty, and error states.

---

## File Structure

### New backend files

- `app/Support/Realtime.php` — flag check + event emission helper.
- `app/Events/SampleStored.php`
- `app/Events/AlertChanged.php`
- `app/Events/ServerChanged.php`
- `app/Events/OperationsChanged.php`
- `routes/channels.php`
- `config/noc.php`
- `config/broadcasting.php`
- `config/reverb.php`
- `tests/Unit/Realtime/BroadcastEventTest.php`
- `tests/Feature/Realtime/ChannelAuthorizationTest.php`
- `tests/Feature/Realtime/RealtimeConfigTest.php`
- `tests/Feature/Realtime/RealtimeEmissionTest.php`
- `tests/Feature/Ui/WaitingPageTest.php`

### New frontend files

- `resources/js/realtime.js` — Echo lifecycle and subscription API.
- `resources/js/realtime-refresh.js` — Alpine data component for debounced Livewire refreshes.

### New deployment files

- `ops/vps/noc-reverb.service`

### Modified backend files

- `composer.json`, `composer.lock`
- `package.json`, `package-lock.json`
- `bootstrap/app.php`
- `app/Http/Controllers/Api/IngestController.php`
- `app/Alerts/AlertEngine.php`
- `app/Services/Servers/ServerService.php`
- `app/Services/Tokens/TokenService.php`
- `app/Services/Operations/RunTracker.php`
- `app/Http/Middleware/SecurityHeaders.php`
- `resources/views/layouts/app.blade.php`
- `resources/views/livewire/dashboard.blade.php`
- `resources/views/livewire/servers/server-table.blade.php`
- `resources/views/livewire/servers/show.blade.php`
- `resources/views/livewire/alerts/index.blade.php`
- `resources/views/livewire/alerts/show.blade.php`
- `resources/views/livewire/alerts/alert-bell.blade.php`
- `resources/views/livewire/servers/waiting.blade.php`
- `resources/js/app.js`
- `ops/vps/nginx.conf`
- `README.md`

---

## Task 1: Install Reverb and add the feature flag

**Files:**
- Modify: `composer.json`, `composer.lock`
- Modify: `package.json`, `package-lock.json`
- Create: `config/broadcasting.php`
- Create: `config/reverb.php`
- Create: `config/noc.php`
- Modify: `.env.example`
- Test: `tests/Feature/Realtime/RealtimeConfigTest.php`

**Interfaces:**
- Consumes: existing Laravel 13 config loader.
- Produces: `config('noc.realtime.enabled')` (bool, default `false`), `config('noc.realtime.debounce_ms')` (int, default `750`), `config('broadcasting.connections.reverb')`, and `config('reverb.apps.apps.0')`.

- [ ] **Step 1: Write the failing configuration test**

```php
public function test_reverb_and_realtime_defaults_are_safe(): void
{
    $this->assertSame('reverb', config('broadcasting.connections.reverb.driver'));
    $this->assertFalse(config('noc.realtime.enabled'));
    $this->assertSame(750, config('noc.realtime.debounce_ms'));
    $this->assertArrayHasKey('key', config('reverb.apps.apps.0'));
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `./tools/test.sh --filter=RealtimeConfigTest`
Expected: FAIL because the configuration files/keys do not exist.

- [ ] **Step 3: Install the packages and publish configuration**

Run:

```bash
./tools/php.sh composer require laravel/reverb
npm install laravel-echo pusher-js
./tools/php.sh php artisan reverb:install --no-interaction
```

Then edit the generated files:
- Keep `BROADCAST_CONNECTION=log` in `.env.example`; add `NOC_REALTIME=false`.
- Ensure `config/noc.php` contains `enabled` and `debounce_ms`.
- Add the Reverb connection to `config/broadcasting.php` if the installer did not.
- Do not commit a local `.env`.

- [ ] **Step 4: Run the focused test to verify it passes**

Run: `./tools/test.sh --filter=RealtimeConfigTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add composer.json composer.lock package.json package-lock.json config/broadcasting.php config/reverb.php config/noc.php .env.example tests/Feature/Realtime/RealtimeConfigTest.php
git commit -m "feat: install Reverb and add realtime feature flag"
```

---

## Task 2: Add broadcast events, channel authorization, and the emission helper

**Files:**
- Create: `app/Support/Realtime.php`
- Create: `app/Events/SampleStored.php`
- Create: `app/Events/AlertChanged.php`
- Create: `app/Events/ServerChanged.php`
- Create: `app/Events/OperationsChanged.php`
- Create: `routes/channels.php`
- Modify: `bootstrap/app.php`
- Test: `tests/Unit/Realtime/BroadcastEventTest.php`
- Test: `tests/Feature/Realtime/ChannelAuthorizationTest.php`
- Test: `tests/Feature/Realtime/RealtimeConfigTest.php` (extend)

**Interfaces:**
- `Realtime::enabled(): bool`
- `Realtime::emit(object $event): void` — dispatches only when `noc.realtime.enabled` is true; catches and reports broadcast/queue failures so ingest and CRUD stay successful.
- `SampleStored::__construct(string $serverId, string $sampleId, string $collectedAt, string $health, string $status)`
- `AlertChanged::__construct(string $alertId, string $serverId, string $change, string $status, string $severity)`
- `ServerChanged::__construct(string $serverId, string $change)`
- `OperationsChanged::__construct(string $taskKey, string $status, string $finishedAt)`
- Each event implements `ShouldBroadcast`, sets `public bool $afterCommit = true`, and exposes `broadcastOn(): array`, `broadcastAs(): string`, `broadcastWith(): array`.

**Channels:**
- `fleet` — active user only.
- `alerts` — active user and `Gate::allows('viewAny', Alert::class)`.
- `server.{server}` — active user and `Gate::allows('view', $server)`.
- `alert.{alert}` — active user and `Gate::allows('view', $alert)`.

- [ ] **Step 1: Write the failing event tests**

```php
public function test_sample_event_uses_fleet_and_server_channels_without_secrets(): void
{
    $event = new SampleStored('srv-1', 'sample-1', '2026-11-25T01:00:00Z', 'ok', 'online');

    $this->assertSame(['private-fleet', 'private-server.srv-1'], $this->channelNames($event));
    $this->assertSame('SampleStored', $event->broadcastAs());
    $this->assertSame([
        'server_id' => 'srv-1',
        'sample_id' => 'sample-1',
        'collected_at' => '2026-11-25T01:00:00Z',
        'health' => 'ok',
        'status' => 'online',
    ], $event->broadcastWith());
}
```

Add equivalent assertions for `AlertChanged`, `ServerChanged`, and `OperationsChanged`. Assert no key named `context`, `message`, `token`, `secret`, or `password` exists in any payload.

- [ ] **Step 2: Write the failing channel tests**

Use `actingAs($user)->post('/broadcasting/auth', [...])` with a valid `socket_id` and `private-*` channel name.

```php
$this->actingAs($viewer)
    ->post('/broadcasting/auth', ['channel_name' => 'private-fleet', 'socket_id' => '1234.5678'])
    ->assertOk();
```

Add cases:
- inactive user is not authorized (assert redirect away; channel auth must not return an auth payload);
- viewer may join `private-server.{id}` only when `Gate::allows('view', $server)`;
- viewer may join `private-alert.{id}` only when `Gate::allows('view', $alert)`;
- a server not visible to the user returns a non-200 response.

- [ ] **Step 3: Run the tests to verify they fail**

Run: `./tools/test.sh --filter='BroadcastEventTest|ChannelAuthorizationTest'`
Expected: FAIL because classes/routes/channels do not exist.

- [ ] **Step 4: Implement the four events, channel callbacks, and helper**

`app/Support/Realtime.php`:

```php
final class Realtime
{
    public static function enabled(): bool
    {
        return (bool) config('noc.realtime.enabled', false);
    }

    public static function emit(object $event): void
    {
        if (! self::enabled()) {
            return;
        }

        try {
            event($event);
        } catch (\Throwable $exception) {
            report($exception);
        }
    }
}
```

Each event returns `Illuminate\Broadcasting\PrivateChannel` instances. `broadcastAs()` returns the bare event name (`SampleStored`, `AlertChanged`, `ServerChanged`, `OperationsChanged`). `broadcastWith()` returns only the documented identifiers/state.

Register channels in `bootstrap/app.php`:

```php
->withRouting(
    web: __DIR__.'/../routes/web.php',
    api: __DIR__.'/../routes/api.php',
    commands: __DIR__.'/../routes/console.php',
    channels: __DIR__.'/../routes/channels.php',
    health: '/up',
)
```

- [ ] **Step 5: Run the focused tests to verify they pass**

Run: `./tools/test.sh --filter='BroadcastEventTest|ChannelAuthorizationTest|RealtimeConfigTest'`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Support/Realtime.php app/Events routes/channels.php bootstrap/app.php tests/Unit/Realtime tests/Feature/Realtime
git commit -m "feat: add private Reverb channels and broadcast events"
```

---

## Task 3: Add the Echo client, runtime configuration, and CSP origin

**Files:**
- Create: `resources/js/realtime.js`
- Create: `resources/js/realtime-refresh.js`
- Modify: `resources/js/app.js`
- Modify: `resources/views/layouts/app.blade.php`
- Modify: `app/Http/Middleware/SecurityHeaders.php`
- Modify: `config/noc.php`
- Test: `tests/Feature/Security/HeadersTest.php`
- Test: `tests/Feature/Ui/LayoutTest.php`

**Interfaces:**
- `bootRealtime(): Echo|null` — returns `null` when `window.__NOC_REALTIME__.enabled` is false.
- `subscribeRealtime(channel: string, event: string, handler: Function): () => void`
- `window.NocRealtime = { enabled, subscribe, status }`
- Alpine data component `nocRealtimeRefresh({ listeners, debounce, refreshCharts })`.
- `window.NocCharts.refreshSeriesCharts(root)`.

- [ ] **Step 1: Write the failing CSP/layout tests**

```php
public function test_realtime_origin_is_added_to_connect_src_only_when_enabled(): void
{
    config(['noc.realtime.enabled' => true]);
    config(['reverb.apps.apps.0.key' => 'test-key']);
    config(['reverb.apps.apps.0.options.host' => 'noc.example.com']);
    config(['reverb.apps.apps.0.options.port' => 443]);
    config(['reverb.apps.apps.0.options.scheme' => 'https']);

    $this->actingAs(User::factory()->create())
        ->get('/dashboard')
        ->assertOk()
        ->assertHeader('Content-Security-Policy', fn (string $csp) => str_contains($csp, 'wss://noc.example.com'));
}
```

Add the counterpart assertion: with `noc.realtime.enabled=false`, the CSP does not add a WebSocket origin beyond `'self'`.

Add a layout assertion that the nonce-protected `window.__NOC_REALTIME__` object is rendered with `enabled`, `key`, `host`, `port`, and `scheme`, and that no app secret appears in the HTML.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `./tools/test.sh --filter='HeadersTest|LayoutTest'`
Expected: FAIL because the CSP and runtime script do not exist.

- [ ] **Step 3: Implement the frontend runtime**

`resources/js/realtime.js` must read only `window.__NOC_REALTIME__`, create Echo with `broadcaster: 'reverb'`, `wsHost`, `wsPort`, `wssPort`, `forceTLS`, and `enabledTransports: ['ws', 'wss']`, and never read `REVERB_APP_SECRET`.

`resources/js/realtime-refresh.js` must:
- subscribe to each `{ channel, event }` listener;
- debounce refreshes to the configured interval;
- call `$wire.$refresh()` and then `window.NocCharts?.refreshSeriesCharts(this.$el)` when `refreshCharts` is true;
- unsubscribe in `destroy()`.

`resources/js/app.js` must boot realtime after `Livewire.start()`, expose `window.NocCharts`, and register the Alpine data component.

- [ ] **Step 4: Add the Blade runtime config and CSP origin**

Render the nonce-protected script in `layouts/app.blade.php`. Build the WebSocket origin from the Reverb scheme/host/port and append it to `connect-src` only when realtime is enabled. Keep `'self'` in the list.

- [ ] **Step 5: Run the focused tests and build**

Run:

```bash
./tools/test.sh --filter='HeadersTest|LayoutTest'
npm run build
```

Expected: tests PASS and Vite build succeeds.

- [ ] **Step 6: Commit**

```bash
git add resources/js/realtime.js resources/js/realtime-refresh.js resources/js/app.js resources/views/layouts/app.blade.php app/Http/Middleware/SecurityHeaders.php config/noc.php tests/Feature/Security/HeadersTest.php tests/Feature/Ui/LayoutTest.php public/build public/build/manifest.json
git commit -m "feat: add Echo runtime configuration and WebSocket CSP"
```

---

## Task 4: Emit `SampleStored` after ingest

**Files:**
- Modify: `app/Http/Controllers/Api/IngestController.php`
- Test: `tests/Feature/Realtime/RealtimeEmissionTest.php`

**Interfaces:**
- Consumes: `App\Events\SampleStored`, `App\Support\Realtime`.
- Produces: one `SampleStored` event per accepted ingest request when realtime is enabled.

- [ ] **Step 1: Write the failing ingest emission test**

```php
public function test_accepted_ingest_emits_sample_stored_when_realtime_is_enabled(): void
{
    config(['noc.realtime.enabled' => true]);
    Event::fake([SampleStored::class]);

    $this->postIngest($this->validPayload());

    Event::assertDispatched(SampleStored::class, function (SampleStored $event): bool {
        return $event->serverId !== ''
            && $event->sampleId !== ''
            && $event->status !== '';
    });
}
```

Add a test with `noc.realtime.enabled=false` asserting no event is dispatched. Add a duplicate/replay test asserting the payload points at the newest stored sample, not a stale one.

- [ ] **Step 2: Run the test to verify it fails**

Run: `./tools/test.sh --filter=RealtimeEmissionTest`
Expected: FAIL because ingest does not emit the event.

- [ ] **Step 3: Emit after alert evaluation**

In `IngestController::__invoke()`, after the existing `$this->alerts->evaluate(...)` try/catch, call `Realtime::emit(new SampleStored(...))` using the refreshed `$result->server`, `$result->sample`, and `$result->issues->health`. Do not move the emission inside the storage transaction.

- [ ] **Step 4: Run the focused test and full ingest tests**

Run:

```bash
./tools/test.sh --filter=RealtimeEmissionTest
./tools/test.sh --filter='IngestAuthTest|IngestIdempotencyTest|IngestStorageTest|PayloadValidationTest'
```

Expected: all PASS, and ingest still returns `202` when the queue is unavailable.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Api/IngestController.php tests/Feature/Realtime/RealtimeEmissionTest.php
git commit -m "feat: broadcast stored samples to fleet channels"
```

---

## Task 5: Emit `AlertChanged` on open, acknowledge, and resolve

**Files:**
- Modify: `app/Alerts/AlertEngine.php`
- Test: `tests/Feature/Realtime/RealtimeEmissionTest.php` (extend)
- Test: `tests/Feature/Alerts/AlertEngineTest.php` (extend)

**Interfaces:**
- Consumes: `App\Events\AlertChanged`, `App\Support\Realtime`.
- Produces: one `AlertChanged` event with `change` `opened`, `acknowledged`, or `resolved`.

- [ ] **Step 1: Write the failing alert emission tests**

```php
Event::fake([AlertChanged::class]);

app(AlertEngine::class)->syncRule($server, AlertRule::CpuHigh, true, ['severity' => 'warning']);

Event::assertDispatched(AlertChanged::class, fn (AlertChanged $event): bool =>
    $event->change === 'opened' && $event->serverId === $server->id
);
```

Add equivalent tests for `acknowledge()` and `resolve()` with real actor IDs, and assert the event is not dispatched when `noc.realtime.enabled=false`.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `./tools/test.sh --filter=RealtimeEmissionTest`
Expected: FAIL because the engine only writes alerts.

- [ ] **Step 3: Emit after the transaction succeeds**

Use `Realtime::emit(new AlertChanged(...))`:
- after a new alert is created in `syncRule()`;
- after a successful `acknowledge()`;
- after a successful `resolve()`.

Do not emit on every sample `touch()`; the `SampleStored` event already refreshes the dashboard age and health.

- [ ] **Step 4: Run the focused tests**

Run:

```bash
./tools/test.sh --filter='RealtimeEmissionTest|AlertEngineTest'
```

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Alerts/AlertEngine.php tests/Feature/Realtime/RealtimeEmissionTest.php tests/Feature/Alerts/AlertEngineTest.php
git commit -m "feat: broadcast alert state changes"
```

---

## Task 6: Emit `ServerChanged` for management actions

**Files:**
- Modify: `app/Services/Servers/ServerService.php`
- Modify: `app/Services/Tokens/TokenService.php`
- Test: `tests/Feature/Realtime/RealtimeEmissionTest.php` (extend)
- Test: `tests/Feature/Servers/ServerServiceTest.php` (extend)
- Test: `tests/Feature/Tokens/TokenServiceTest.php` (extend)

**Interfaces:**
- Consumes: `App\Events\ServerChanged`, `App\Support\Realtime`.
- Produces: `ServerChanged` with `created`, `updated`, `deleted`, `token_rotated`, or `token_revoked`.

- [ ] **Step 1: Write the failing server-event tests**

```php
Event::fake([ServerChanged::class]);
app(ServerService::class)->create(['name' => 'web-01', 'hostname' => 'web-01.example.com'], $admin);

Event::assertDispatched(ServerChanged::class, fn (ServerChanged $event): bool =>
    $event->change === 'created' && $event->serverId !== ''
);
```

Add cases for update, soft delete, token rotation, and token revocation. Assert no event is emitted for per-sample `Server` updates.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `./tools/test.sh --filter='ServerServiceTest|TokenServiceTest|RealtimeEmissionTest'`
Expected: FAIL because the services only write/audit.

- [ ] **Step 3: Emit after each service transaction**

Place `Realtime::emit(new ServerChanged(...))` after the transaction returns successfully. For delete, use the server key captured before soft deletion. For token rotation/revocation, use the token’s `server_id`. Do not add an observer on `Server::saved()`.

- [ ] **Step 4: Run the focused tests**

Run:

```bash
./tools/test.sh --filter='ServerServiceTest|TokenServiceTest|RealtimeEmissionTest'
```

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Servers/ServerService.php app/Services/Tokens/TokenService.php tests/Feature/Realtime/RealtimeEmissionTest.php tests/Feature/Servers/ServerServiceTest.php tests/Feature/Tokens/TokenServiceTest.php
git commit -m "feat: broadcast server and token management changes"
```

---

## Task 7: Emit `OperationsChanged` from RunTracker

**Files:**
- Modify: `app/Services/Operations/RunTracker.php`
- Test: `tests/Feature/Realtime/RealtimeEmissionTest.php` (extend)
- Test: `tests/Feature/Metrics/RollupTest.php` (extend)
- Test: `tests/Feature/Retention/PruneTest.php` (extend)

**Interfaces:**
- Consumes: `App\Events\OperationsChanged`, `App\Support\Realtime`.
- Produces: one event on successful and failed completion with the task key and final status.

- [ ] **Step 1: Write the failing operation-event tests**

```php
Event::fake([OperationsChanged::class]);

app(RunTracker::class)->run('rollup', fn (): int => 0);

Event::assertDispatched(OperationsChanged::class, fn (OperationsChanged $event): bool =>
    $event->taskKey === 'rollup' && $event->status === 'succeeded'
);
```

Add a failing-operation test and a realtime-disabled test.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `./tools/test.sh --filter='RollupTest|PruneTest|RealtimeEmissionTest'`
Expected: FAIL because RunTracker does not emit.

- [ ] **Step 3: Emit after the status row is saved**

Emit after both the success and exception paths have written `finished_at` and `status`. Preserve the existing rethrow behavior and do not let a broadcast failure change the command exit code.

- [ ] **Step 4: Run the focused tests**

Run:

```bash
./tools/test.sh --filter='RollupTest|PruneTest|RealtimeEmissionTest'
```

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Operations/RunTracker.php tests/Feature/Realtime/RealtimeEmissionTest.php tests/Feature/Metrics/RollupTest.php tests/Feature/Retention/PruneTest.php
git commit -m "feat: broadcast scheduled operation status"
```

---

## Task 8: Wire the dashboard and server table to realtime

**Files:**
- Modify: `resources/views/livewire/dashboard.blade.php`
- Modify: `resources/views/livewire/servers/server-table.blade.php`
- Test: `tests/Feature/Ui/DashboardTest.php`
- Test: `tests/Feature/Ui/ServersIndexTest.php`

**Interfaces:**
- Consumes: `nocRealtimeRefresh` Alpine data, `fleet`, `alerts`, `operations`, `server.{id}` channels.
- Produces: dashboard and table refresh without manual reload.

- [ ] **Step 1: Write the failing render tests**

```php
public function test_dashboard_renders_realtime_hooks_when_enabled(): void
{
    config(['noc.realtime.enabled' => true]);

    $this->actingAs(User::factory()->create())
        ->get('/dashboard')
        ->assertOk()
        ->assertSee('data-realtime', false)
        ->assertSee('SampleStored', false);
}
```

Add a disabled-mode assertion that `wire:poll` remains present and no Echo hook is rendered. Add the same for the servers table.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `./tools/test.sh --filter='DashboardTest|ServersIndexTest'`
Expected: FAIL because the Blade roots do not contain realtime hooks.

- [ ] **Step 3: Add the Alpine root and fallback polling**

Dashboard root subscribes to `fleet:SampleStored`, `alerts:AlertChanged`, and `fleet:OperationsChanged`, debounces 750 ms, and refreshes the component. The server table subscribes to `fleet:SampleStored` and `fleet:ServerChanged`; per-server channels belong to the detail page, not the fleet table.

Use `wire:poll.60s.visible="pollRefresh"` when realtime is enabled and `wire:poll.30s.visible="pollRefresh"` when disabled. Keep `pollRefresh` dispatching `fleet-summary-refresh` so the parent summary still updates in both modes.

- [ ] **Step 4: Run the focused tests**

Run:

```bash
./tools/test.sh --filter='DashboardTest|ServersIndexTest'
```

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add resources/views/livewire/dashboard.blade.php resources/views/livewire/servers/server-table.blade.php tests/Feature/Ui/DashboardTest.php tests/Feature/Ui/ServersIndexTest.php
git commit -m "feat: refresh dashboard and server table from Reverb"
```

---

## Task 9: Wire server detail and chart refresh

**Files:**
- Modify: `app/Livewire/Servers/Show.php`
- Modify: `resources/views/livewire/servers/show.blade.php`
- Modify: `resources/js/realtime-refresh.js`
- Test: `tests/Feature/Ui/ServerDetailTest.php`

**Interfaces:**
- `Show::pollRefresh(): void` — triggers a normal Livewire re-render.
- Show subscribes to `server.{id}:SampleStored`, `server.{id}:ServerChanged`, and `server.{id}:AlertChanged`.
- Chart refresh calls `refreshSeriesCharts()` after the morph and preserves `tab` and `range`.

- [ ] **Step 1: Write the failing server-detail test**

```php
public function test_server_detail_renders_realtime_hooks_and_fallback_poll(): void
{
    config(['noc.realtime.enabled' => true]);

    $this->actingAs($admin)
        ->get(route('servers.show', $server))
        ->assertOk()
        ->assertSee('data-realtime', false)
        ->assertSee('wire:poll.60s', false);
}
```

Add a disabled-mode assertion for the 30 s fallback and a test that a tab/range query string still renders the same selected state.

- [ ] **Step 2: Run the test to verify it fails**

Run: `./tools/test.sh --filter=ServerDetailTest`
Expected: FAIL because server detail has no realtime root/poll.

- [ ] **Step 3: Implement the root and chart refresh**

Add the Alpine root with the three server-channel listeners and `refreshCharts: true`. Add `pollRefresh()` to `Show`. Ensure chart remount happens after `$wire.$refresh()` settles, not before the Livewire morph.

- [ ] **Step 4: Run the focused tests**

Run:

```bash
./tools/test.sh --filter=ServerDetailTest
npm run build
```

Expected: tests PASS, build PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Livewire/Servers/Show.php resources/views/livewire/servers/show.blade.php resources/js/realtime-refresh.js tests/Feature/Ui/ServerDetailTest.php public/build public/build/manifest.json
git commit -m "feat: live-refresh server detail and charts"
```

---

## Task 10: Wire alerts index, alert detail, and the bell

**Files:**
- Modify: `app/Livewire/Alerts/Index.php`
- Modify: `app/Livewire/Alerts/Show.php`
- Modify: `resources/views/livewire/alerts/index.blade.php`
- Modify: `resources/views/livewire/alerts/show.blade.php`
- Modify: `resources/views/livewire/alerts/alert-bell.blade.php`
- Test: `tests/Feature/Ui/AlertCenterTest.php`

**Interfaces:**
- `Alerts\Index::pollRefresh(): void`.
- `Alerts\Show::pollRefresh(): void`.
- Index subscribes to `alerts:AlertChanged`; Show subscribes to `alert.{id}:AlertChanged` and `server.{id}:AlertChanged`; Bell calls `$wire.call('refresh')` on `alerts:AlertChanged` so the existing `alert-created` sound logic still runs.

- [ ] **Step 1: Write the failing alert UI tests**

```php
public function test_alert_pages_render_realtime_hooks_and_fallbacks(): void
{
    config(['noc.realtime.enabled' => true]);

    $this->actingAs($admin)
        ->get(route('alerts.index'))
        ->assertOk()
        ->assertSee('data-realtime', false)
        ->assertSee('wire:poll.60s', false);

    $this->actingAs($admin)
        ->get(route('alerts.show', $alert))
        ->assertOk()
        ->assertSee('data-realtime', false)
        ->assertSee('wire:poll.60s', false);
}
```

Add a bell assertion that the `AlertChanged` hook calls `refresh` and that the fallback poll remains.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `./tools/test.sh --filter=AlertCenterTest`
Expected: FAIL because alerts pages only re-render on interaction.

- [ ] **Step 3: Implement the hooks and fallback polls**

Use the same conditional 60 s/30 s fallback pattern. Keep alert filters, pagination, and resolved/acknowledged state in the query string; a refresh must not reset them.

- [ ] **Step 4: Run the focused tests**

Run:

```bash
./tools/test.sh --filter=AlertCenterTest
```

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Livewire/Alerts/Index.php app/Livewire/Alerts/Show.php resources/views/livewire/alerts tests/Feature/Ui/AlertCenterTest.php
git commit -m "feat: refresh alert screens and bell from Reverb"
```

---

## Task 11: Wire the waiting page

**Files:**
- Modify: `app/Livewire/Servers/Waiting.php`
- Modify: `resources/views/livewire/servers/waiting.blade.php`
- Create: `tests/Feature/Ui/WaitingPageTest.php`

**Interfaces:**
- Waiting subscribes to `server.{id}:SampleStored` and calls `$wire.call('poll')`.
- The existing 5 s polling remains as the fallback and timeout clock.

- [ ] **Step 1: Write the failing waiting-page test**

```php
public function test_waiting_page_renders_realtime_hook_and_keeps_five_second_fallback(): void
{
    config(['noc.realtime.enabled' => true]);

    $this->actingAs($admin)
        ->get(route('servers.waiting', $server))
        ->assertOk()
        ->assertSee('data-realtime', false)
        ->assertSee('wire:poll.5s', false);
}
```

Add a disabled-mode assertion that the page still polls every 5 s.

- [ ] **Step 2: Run the test to verify it fails**

Run: `./tools/test.sh --filter=WaitingPageTest`
Expected: FAIL because the page has no realtime hook.

- [ ] **Step 3: Implement the hook**

Add the server-channel listener and call the existing `poll()` method on event. When `connected` becomes true, the existing “Agent connected” section and “Open server” link must render without a page reload.

- [ ] **Step 4: Run the focused test**

Run: `./tools/test.sh --filter=WaitingPageTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Livewire/Servers/Waiting.php resources/views/livewire/servers/waiting.blade.php tests/Feature/Ui/WaitingPageTest.php
git commit -m "feat: live-update waiting page on first sample"
```

---

## Task 12: Add VPS deployment artifacts and environment documentation

**Files:**
- Create: `ops/vps/noc-reverb.service`
- Modify: `ops/vps/nginx.conf`
- Modify: `README.md`
- Modify: `.env.example`
- Test: shell validation and config checks

**Interfaces:**
- `noc-reverb.service` runs `php artisan reverb:start --host=127.0.0.1 --port=8080` as `www-data`.
- Nginx proxies `/app` and `/apps` to `127.0.0.1:8080` with WebSocket upgrade headers.
- README documents VPS and shared-hosting modes and `NOC_REALTIME`.

- [ ] **Step 1: Add the systemd unit**

Use the existing queue/scheduler unit style. Include `Restart=always`, `RestartSec=5`, `MemoryMax=512M`, `After=network-online.target`, and `Environment=APP_ENV=production`.

- [ ] **Step 2: Update Nginx**

Add `/app` and `/apps` proxy locations, preserving the existing TLS and PHP-FPM blocks. Include `proxy_http_version 1.1`, `Upgrade`, `Connection "Upgrade"`, `Host`, `X-Forwarded-For`, and `X-Forwarded-Proto`.

- [ ] **Step 3: Document environment and rollout**

Add the VPS env matrix, the shared-hosting `NOC_REALTIME=false` path, install commands, `systemctl enable --now noc-reverb`, `reverb:restart` after config changes, and the manual smoke test.

- [ ] **Step 4: Validate**

Run:

```bash
systemd-analyze verify ops/vps/noc-reverb.service 2>/dev/null || true
./tools/php.sh php artisan route:list
```

Expected: no unit-file errors; `/broadcasting/auth` is registered.

- [ ] **Step 5: Commit**

```bash
git add ops/vps/noc-reverb.service ops/vps/nginx.conf README.md .env.example
git commit -m "docs: add Reverb VPS deployment configuration"
```

---

## Task 13: End-to-end verification and release

**Files:**
- No new production files unless a verification gap requires one.
- Update `docs/superpowers/specs/2026-11-25-noc-realtime-design.md` only if implementation forces a design correction.

- [ ] **Step 1: Run the full PHP suite**

Run: `./tools/test.sh`
Expected: Pint passed; all tests pass. Record the exact test/assertion counts.

- [ ] **Step 2: Build production assets**

Run: `npm run build`
Expected: success; `public/build/manifest.json` references the new JS/CSS hashes.

- [ ] **Step 3: Verify both modes**

Run the suite once with `NOC_REALTIME=false` and once with `NOC_REALTIME=true` for the realtime UI tests. Confirm:
- disabled mode renders only polling;
- enabled mode renders Echo hooks and still renders the slower polling safety net.

- [ ] **Step 4: Manual VPS smoke test**

With two browsers and a test agent:
1. Send a sample; dashboard and server detail update without reload.
2. Open an alert; alerts list, detail, and bell update.
3. Acknowledge and resolve; both screens update.
4. Rotate a token; server detail/list update.
5. Stop `noc-reverb`; ingest still returns `202`, and the 60 s polling safety net continues to refresh.
6. Start `noc-reverb` again; live updates resume without a full page reload.

- [ ] **Step 5: Release commit and tag**

```bash
git add -A
git commit -m "release: add Reverb realtime updates"
git tag -a v1.1.0 -m "NOC Center v1.1.0"
git push origin main
git push origin v1.1.0
```

If this ships as a patch instead, use `v1.0.2` and state that Reverb is opt-in.

---

## Self-Review Checklist

- [ ] Every goal in the design has a task: dashboard, server table, server detail, alerts, bell, waiting, fallback, CSP, deployment.
- [ ] No task resurrects Eloquent model/collection caching.
- [ ] `SampleStored` is emitted after commit and never inside the storage transaction.
- [ ] Alert events are not emitted on every `touch()`.
- [ ] Server events are not emitted from per-sample `Server` updates.
- [ ] Inactive-user authorization is explicitly tested.
- [ ] Reverb-down behavior is explicitly tested and documented.
- [ ] Chart refresh happens after the Livewire morph and preserves tab/range.
- [ ] New assets are committed with the manifest.
- [ ] Activity, Users, and Settings pages remain without realtime hooks; the global bell is the only exception.
