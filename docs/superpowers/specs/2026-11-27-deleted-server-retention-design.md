# Deleted Server Retention — Design

**Date:** 2026-11-27
**Status:** Proposed
**Scope:** Automatic purge of soft-deleted servers after a configurable grace period, plus an admin UI to purge one deleted server immediately.

## Intent

Deleting a server currently removes it from the fleet but keeps every historical row. That is the correct default for audit and incident history, but it leaves soft-deleted servers, metrics, rollups, alerts, deliveries, and tokens in the database forever unless an operator runs `noc:purge-server` manually.

This change makes the retention policy explicit and visible:

- a configurable grace period, in days, after which a soft-deleted server is purged automatically;
- a Settings tab where an administrator can review deleted servers, see when each one is due, and permanently purge one immediately;
- no change to the meaning of “delete server”: it remains a soft delete that keeps history until the retention policy removes it.

## Goals

- Automatically purge servers whose `deleted_at` is older than the configured grace period.
- Let an administrator change the grace period from the Settings UI, with `0` disabling automatic purge.
- Let an administrator see every soft-deleted server, its deletion time, its auto-purge time, and its data counts before taking destructive action.
- Let an administrator permanently purge one deleted server immediately, with a typed-name confirmation.
- Audit every purge with the existing `server.purged` event, recording whether it was manual or automatic.
- Keep the existing CLI `noc:purge-server` working unchanged.

## Non-goals

- Restoring a soft-deleted server. There is no restore UI in this change.
- Changing soft-delete semantics or the current `ServerPolicy::delete()` permission.
- Automatically running SQLite `VACUUM` on every purge. Removing rows does not guarantee that the main SQLite file shrinks; free pages are reused. `VACUUM` remains a separate maintenance action.
- Adding a generic retention editor for metric samples, rollups, alerts, audit, or tokens. This tab owns only deleted-server retention.
- Purging active servers. Active servers must be soft-deleted first.

## Current behaviour

- `App\Models\Server` uses `SoftDeletes`.
- `ServerService::delete()` sets `deleted_at`, revokes tokens, resolves open alerts without recovery delivery, and writes `server.deleted`.
- Metrics, rollups, alerts, deliveries, and tokens remain, and cascade only when the server row is force-deleted.
- `noc:purge-server` force-deletes one soft-deleted server and cascades its rows.
- `noc:prune` prunes metric/alert/audit/token rows by age; it never deletes a whole server.
- `SettingsRepository` stores administrator overrides as key/value rows with type casting and audit support.
- Settings pages are admin-only through `SettingPolicy::viewAny()`.

## Approaches considered

### A — One “Deleted servers” Settings tab (chosen)

Add a new admin Settings tab that contains both the grace-period form and the deleted-server list. Each row has a purge action with a typed-name confirmation and a count preview.

**Trades:** one new page/route, but the policy and the destructive action are in one place; the fleet table stays clean.

### B — “Deleted” tab inside the Servers table

Add a deleted status tab to `ServerTable`, with purge actions in the row.

**Trades:** no new route, but the fleet query and status tabs now mix active and deleted servers; the destructive action sits in the most-used operational table.

### C — Settings page without a dedicated Deleted servers tab

Put the grace-period setting in Settings and leave force-purge only in the CLI.

**Trades:** smallest UI, but the user cannot see or purge a deleted server from the dashboard, which is the main requirement.

The user chose **A**, with one refinement: “Deleted servers” lives in Settings as a new tab rather than in the Servers area.

## Design

### 1. Settings tab

Add one new tab to the Settings navigation:

- Label: **Deleted servers**
- Route: `GET /settings/deleted-servers`
- Livewire component: `App\Livewire\Settings\DeletedServers`
- Visibility: administrators only, through `SettingPolicy::viewAny(Setting::class)`.
- Settings hub entry: “Deleted servers — Auto-purge policy and permanent purge for servers removed from the fleet. Administrators.”

The tab contains two sections on one page:

1. **Auto-purge**
   - Number input `graceDays`.
   - Label: “Auto-purge after”.
   - Helper: “Days after deletion before the server and its history are removed. Set to 0 to disable automatic purge.”
   - Validation: integer, `0..3650`.
   - Save button: “Save retention policy”.
   - Saving writes `retention.deleted_server_days` as an integer, audits `settings.updated` with the key, and shows “Retention policy saved.”
   - `0` means automatic purge is disabled; manual purge remains available.

2. **Deleted servers**
   - Rows from `Server::onlyTrashed()` ordered by `deleted_at` descending, paginated 25 per page.
   - Columns: server, deleted at (Asia/Jakarta display), auto-purge date or “Disabled”, and an action.
   - If the grace period has elapsed: “Due now”.
   - If automatic purge is disabled: “Disabled”.
   - Otherwise: “In N days”.
   - A note: “Deleting a server keeps its metrics and history until purge. Automatic purge runs daily. SQLite file size may not shrink until VACUUM.”
   - Empty state: “No deleted servers. Servers removed from the fleet appear here until they are purged.”

### 2. Manual purge flow

Each row has a **Purge now** button. It opens an inline confirmation block for that row; it does not immediately delete.

The confirmation block shows:

- server name and hostname;
- counts returned by `ServerService::purgeCounts($server)`: samples, details, rollups, alerts, deliveries, tokens;
- a warning: “This permanently removes the server and its history. Audit records are kept. This cannot be undone.”;
- a text input labelled “Type <name> to confirm”;
- buttons **Purge permanently** and **Cancel**.

`purge()` in the Livewire component:

- authorizes `ServerPolicy::purge($user, $server)` (admin-only);
- trims the typed name and requires an exact match;
- calls `ServerService::purge($server, auth()->user(), 'manual')`;
- clears the confirmation, resets pagination state as needed, and shows “Server permanently deleted.”;
- leaves the audit row in place.

`ServerService::purge()` gains an optional `string $trigger = 'manual'` parameter and writes `trigger` in the `server.purged` audit metadata. Existing CLI callers keep the default.

### 3. Automatic purge

New command:

```text
php artisan noc:purge-deleted-servers [--dry-run]
```

Behaviour:

- Reads `retention.deleted_server_days` through `SettingsRepository`, defaulting to `config('retention.deleted_server_days')`.
- If the value is `0`, prints `Automatic purge is disabled (retention.deleted_server_days = 0).` and returns success.
- Cutoff: `now()->utc()->subDays(days)`. A server exactly at the cutoff is not purged; only `deleted_at < cutoff` is eligible.
- Selects `Server::onlyTrashed()->where('deleted_at', '<', $cutoff)->orderBy('deleted_at')->get()`.
- For each server, calls `ServerService::purge($server, null, 'auto')`.
- On a per-server failure, catches `Throwable`, prints the server name and a bounded error, continues with the next server, and returns failure if any server failed.
- `--dry-run` prints the eligible names and counts and writes nothing.
- Prints a summary: purged count, skipped/failed count, total rows by category.
- Does not run `VACUUM`.

Schedule in `routes/console.php`:

```php
Schedule::command('noc:purge-deleted-servers')
    ->dailyAt('04:30')
    ->timezone('Asia/Jakarta')
    ->withoutOverlapping(30);
```

04:30 is after the existing 03:30 `noc:prune` window, which reduces SQLite write-lock contention. The scheduled run is not `onOneServer()` for the same single-node reason as the existing jobs. Use `RunTracker` with task name `purge-deleted-servers` for consistency with `noc:prune`.

### 4. Policy and authorization

- Add `ServerPolicy::purge(User $user, Server $server): bool` returning `$user->isAdmin()`.
- Page access uses `SettingPolicy::viewAny()`.
- Manual purge uses `ServerPolicy::purge()`.
- The auto command has no user actor; the audit actor is `null` and `trigger` is `auto`.
- Operators and viewers cannot see the Settings tab or call the purge action.

### 5. Audit

`server.purged` metadata:

```json
{
  "server_id": "01...",
  "name": "web-01",
  "counts": {
    "samples": 4174,
    "details": 4174,
    "rollups": 922,
    "alerts": 1,
    "deliveries": 1,
    "tokens": 1
  },
  "trigger": "manual"
}
```

- `trigger` is `manual` for the existing CLI and the Settings action, `auto` for the scheduled command.
- Audit rows survive the cascade.
- The existing `tokens` integer exception in `AuditLogger` remains unchanged.

### 6. Data and configuration

Add to `config/retention.php`:

```php
'deleted_server_days' => 30,
```

No schema migration is required: the override is a `settings` row with key `retention.deleted_server_days`, type `int`.

Default policy: **30 days**. An administrator can set `0` to disable, or any value up to 3650 days.

### 7. Existing behaviour preserved

- `noc:purge-server` keeps its signature and default `manual` trigger.
- `noc:prune` remains unchanged.
- `ServerService::delete()` remains soft delete.
- The Servers table does not gain a deleted tab.
- Active servers are still refused by `ServerService::purge()`.
- The audit `tokens` count remains an integer.

## Edge cases

- **Grace period 0:** auto command exits successfully without purging; the Settings list shows “Disabled”; manual purge still works.
- **Existing soft-deleted servers:** they are eligible on the first scheduled run if `deleted_at` is older than the cutoff.
- **Server deleted seconds ago:** stays in the list with “In N days”.
- **Grace period changed:** the displayed auto-purge date is computed from the current setting; the command uses the same setting at run time.
- **Server name reused:** name uniqueness still includes soft-deleted rows; until purge, the old name is reserved. The deleted page is the way to free it.
- **Purge failure:** the row remains; the command reports the failure and continues. The UI shows a clean error, not a stack trace.
- **No restore:** deleting a server and then purging is final. There is no restore action in this scope.
- **VACUUM:** not automatic. The note explains that free pages may be reused and the file may not shrink until a manual `VACUUM`.
- **Concurrent manual/auto purge:** `ServerService::purge()` still requires a trashed row; a second purge of the same row fails cleanly. The UI refreshes after a successful purge.

## UI copy

Tab: **Deleted servers**

Page intro: “Servers removed from the fleet keep their history until they are purged. Automatic purge runs daily after the grace period.”

Auto-purge label: **Auto-purge after**
Helper: “Days after deletion before the server and its history are removed. Set to 0 to disable automatic purge.”

List empty state: “No deleted servers. Servers removed from the fleet appear here until they are purged.”

Purge confirmation: “This permanently removes the server and its history. Audit records are kept. This cannot be undone.”

Success: “Server permanently deleted.”

## Tests

### `tests/Feature/Ui/SettingsTest.php`
- Settings hub and nav include **Deleted servers** for admins, not for operators/viewers.
- `/settings/deleted-servers` is forbidden to operators/viewers.
- The page lists only trashed servers, ordered by deletion date.
- Saving a grace period validates `0..3650`, writes the setting, audits `settings.updated`, and shows the success message.

### New `tests/Feature/Ui/DeletedServersTest.php`
- The page shows deleted servers and their auto-purge dates.
- `0` renders “Disabled”.
- Purging requires the exact server name.
- A successful purge removes the server and cascades samples/details/rollups/alerts/deliveries/tokens, keeps audit, and writes `trigger: manual`.
- A non-admin cannot call `purge`.

### New `tests/Feature/Console/PurgeDeletedServersCommandTest.php`
- `0` disables auto-purge and purges nothing.
- Only servers with `deleted_at < cutoff` are purged.
- A server exactly at the cutoff survives.
- `--dry-run` reports counts and writes nothing.
- Auto purge writes `trigger: auto` and a null actor.
- A failure on one server does not stop the others and returns failure.
- The scheduled command is registered.

### Existing tests
- `ServerPurgeTest` covers the new optional `trigger` parameter and keeps the `tokens` integer audit assertion.
- `PurgeServerCommandTest` keeps the CLI default `manual` behavior.
- `ServerPolicy` tests add admin-only `purge`.

## Rollout

- No migration.
- Default is 30 days; the Settings tab shows the policy and each deleted server’s due date before the first scheduled run.
- Existing agents are unaffected.
- After deployment, administrators should review the Deleted servers tab and set `0` temporarily if they want to inspect old soft-deleted rows before enabling automatic purge.

## Open decisions resolved by this document

- UI placement: Settings tab **Deleted servers**.
- Grace default: 30 days; `0` disables.
- Manual confirmation: type the exact server name.
- Auto-purge schedule: daily 04:30 Asia/Jakarta.
- VACUUM: not automatic.
- Audit trigger: `manual` or `auto`.