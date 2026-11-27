# Purge Server Command Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a deliberate CLI path to permanently delete a soft-deleted server and its cascading history.

**Architecture:** A `ServerService::purge()` domain operation counts and force-deletes one soft-deleted server inside a transaction, writes a `server.purged` audit event, and returns the counts. A thin `noc:purge-server` command handles lookup, active-server refusal, confirmation, dry-run, output, and optional SQLite `VACUUM`.

**Tech Stack:** Laravel 13, PHP 8.4, PHPUnit, SQLite, existing `AuditLogger` and `ServerService`.

**Spec:** `docs/superpowers/specs/2026-11-27-noc-purge-server-design.md`

## Global Constraints

- Only soft-deleted servers may be purged; active servers must be refused.
- No automatic backup; print the backup reminder.
- No UI, scheduler, retention, or soft-delete behaviour changes.
- Audit events are append-only and survive the purge.
- Use `dashboard/tools/php.sh` / `tools/test.sh`; never host PHP directly.
- All output and copy English.
- TDD: failing test first, then implementation.

## Review Focus

- Purging a soft-deleted server must not leave samples, details, rollups, alerts, deliveries, or tokens behind.
- An active server must never be force-deleted by this command.
- `--dry-run` must not write or delete anything, including audit rows.
- `--force` must bypass confirmation; without it, a declined confirmation must abort safely.
- `--vacuum` must not run inside the deletion transaction and must be skipped for non-SQLite drivers.

---

### Task 1: Server purge domain operation

**Files:**
- Modify: `app/Services/Servers/ServerService.php`
- Test: `tests/Feature/Services/ServerPurgeTest.php`

**Interfaces:**
- Consumes: existing `AuditLogger`, `Server`, `Alert`, `MetricSample`, `MetricRollup`, `ServerToken`, `AlertDelivery`, `User`.
- Produces:
  - `public function purgeCounts(Server $server): array` returning `samples`, `details`, `rollups`, `alerts`, `deliveries`, `tokens` as ints.
  - `public function purge(Server $server, ?User $actor = null): array` returning the same keys.

- [ ] **Step 1: Write the failing service tests**

Create `tests/Feature/Services/ServerPurgeTest.php` with `RefreshDatabase`. Create a server, mark it deleted, and create one sample, one detail, one rollup, one alert, one delivery, and one token. Assert `purgeCounts()` and `purge()` return 1 for every key; the server is gone from `withTrashed()`, and the related rows are gone. Assert an audit event `server.purged` exists with `target_id` equal to the server ULID and `server_id` in `meta_json`. Add separate tests that `purge()` throws `LogicException` for an active server and does not delete it.

- [ ] **Step 2: Run the service tests to verify RED**

Run: `./tools/php.sh php artisan test tests/Feature/Services/ServerPurgeTest.php`
Expected: FAIL because `purgeCounts()` / `purge()` do not exist.

- [ ] **Step 3: Implement the service operation**

Add the two public methods to `ServerService`. Count through `DB::table()` joins:

- `metric_samples` by `server_id`
- `metric_details` joined through `metric_samples`
- `metric_rollups` by `server_id`
- `alerts` by `server_id`
- `alert_deliveries` joined through `alerts`
- `server_tokens` by `server_id`

In `purge()`:
- throw `LogicException` when `! $server->trashed()`;
- compute counts;
- `DB::transaction()`: write `AuditLogger::log('server.purged', $server, ['server_id' => $server->getKey(), 'name' => $server->name, 'counts' => $counts], $actor?->getKey())`, then `$server->forceDelete()`;
- return counts.

- [ ] **Step 4: Run the service tests to verify GREEN**

Run: `./tools/php.sh php artisan test tests/Feature/Services/ServerPurgeTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Servers/ServerService.php tests/Feature/Services/ServerPurgeTest.php
git commit -m "feat: add permanent server purge service"
```

---

### Task 2: `noc:purge-server` command

**Files:**
- Create: `app/Console/Commands/PurgeServer.php`
- Test: `tests/Feature/Console/PurgeServerCommandTest.php`

**Interfaces:**
- Consumes: `ServerService::purgeCounts()` and `ServerService::purge()` from Task 1.
- Produces: `php artisan noc:purge-server {name} {--force} {--dry-run} {--vacuum}`.

- [ ] **Step 1: Write the failing command tests**

Create `tests/Feature/Console/PurgeServerCommandTest.php` with `RefreshDatabase`. Cover:

1. Unknown name → `FAILURE`, output contains `Server not found`.
2. Active server → `FAILURE`, output contains `soft-delete`, server still exists.
3. Soft-deleted server with a token and sample → `--dry-run` → `SUCCESS`, output contains `Would purge`, rows still exist, no `server.purged` audit.
4. Soft-deleted server → `--force` → `SUCCESS`, output contains `Purged server`, server and related rows gone, `server.purged` audit exists.
5. Soft-deleted server without `--force` → `expectsConfirmation('Permanently purge server [name] and all of its data? This cannot be undone.', 'no')` → `FAILURE`, server still exists.
6. Same confirmation answered `yes` → `SUCCESS`, server gone.
7. `--force --vacuum` on the test SQLite connection → `SUCCESS`, output contains `VACUUM complete`.

- [ ] **Step 2: Run the command tests to verify RED**

Run: `./tools/php.sh php artisan test tests/Feature/Console/PurgeServerCommandTest.php`
Expected: FAIL because the command does not exist.

- [ ] **Step 3: Implement the command**

Create `PurgeServer` with the signature above and `handle(ServerService $servers)`.

- Find `Server::withTrashed()->where('name', $name)->first()`.
- Print `Server not found: {name}` and return `FAILURE` when absent.
- Print an active-server refusal mentioning `soft-delete` and return `FAILURE`.
- Print `Would purge server [name]: ...` and return `SUCCESS` for `--dry-run`.
- Print the backup reminder:
  `Before purging, create a backup: php artisan noc:backup --path=/absolute/path.sqlite`
- Without `--force`, ask the exact confirmation string above; return `FAILURE` on no.
- Call `$servers->purge($server)`.
- Print `Purged server [name]: {samples} samples, {details} details, {rollups} rollups, {alerts} alerts, {deliveries} deliveries, {tokens} tokens.`
- For `--vacuum`, only when `DB::connection()->getDriverName() === 'sqlite'`: capture the database file size when it is a real file, run `DB::statement('VACUUM')`, then print `VACUUM complete` with before/after sizes when available. For non-SQLite, warn and skip.

- [ ] **Step 4: Run the command tests to verify GREEN**

Run: `./tools/php.sh php artisan test tests/Feature/Console/PurgeServerCommandTest.php`
Expected: PASS.

- [ ] **Step 5: Run the full gates**

Run:
```bash
./tools/test.sh
```
Expected: Pint clean and all tests pass.

- [ ] **Step 6: Commit**

```bash
git add app/Console/Commands/PurgeServer.php tests/Feature/Console/PurgeServerCommandTest.php
git commit -m "feat: add noc:purge-server command"
```