# Purge Server Command — Design

**Date:** 2026-11-27
**Status:** Approved in conversation

## Intent

Server deletion is intentionally a soft delete: the row keeps its identity and
metrics are pruned later by age-based retention. Operators still need a
deliberate way to permanently remove a soft-deleted server and all of its
history when the data is no longer wanted.

## Goals

- Permanently delete one soft-deleted server and every row that cascades from it.
- Make the destructive action explicit, previewable, and auditable.
- Optionally reclaim SQLite file space after the purge.
- Keep the existing soft-delete behaviour unchanged.

## Non-goals

- No UI button in this change.
- No scheduled/background purge.
- No change to retention windows.
- No automatic backup file.
- No purge of active servers.

## Command

```bash
php artisan noc:purge-server {name}
php artisan noc:purge-server {name} --force
php artisan noc:purge-server {name} --dry-run
php artisan noc:purge-server {name} --force --vacuum
```

- `name` is the unique server name, including soft-deleted rows.
- The command refuses an active server. The operator must soft-delete it first.
- Without `--force`, the command asks for confirmation.
- `--dry-run` prints the counts that would be deleted and changes nothing.
- `--vacuum` runs SQLite `VACUUM` after the purge and prints the database size
  before and after. It is skipped with a warning for non-SQLite drivers.
- Before purging, the command prints a reminder to create a backup with
  `php artisan noc:backup --path=...`.

## Deletion

- Count samples, sample details, rollups, alerts, alert deliveries, and tokens
  for the server.
- In one transaction:
  - write an append-only `server.purged` audit event with the server id, name,
    and counts;
  - force-delete the server row.
- Foreign-key cascades remove samples, details, rollups, alerts, deliveries,
  and tokens. Audit rows are not deleted.
- Return the counts so the command can print them.

## Test surface

- Service: counts, force delete, cascade, audit, active-server refusal.
- Command: active refusal, unknown name, dry-run, confirmation path, force path,
  and output containing the counts.
- Existing retention and soft-delete tests remain unchanged.