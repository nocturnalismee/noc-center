# NOC Dashboard

The Laravel + Blade/Livewire dashboard for the NOC monitoring stack: ingest,
SQLite storage, alerts, history, and the operator UI. It pairs with the Bash
agent in [`../agent/`](../agent/README.md); the agent posts
`POST /api/v1/ingest`, the dashboard stores and reports it.

This file is the deployment and operations runbook. Design rationale lives in
[`../docs/superpowers/specs/2026-11-22-noc-dashboard-design.md`](../docs/superpowers/specs/2026-11-22-noc-dashboard-design.md).

- [Requirements](#requirements)
- [Local development](#local-development)
- [Install](#install)
- [Update and rollback](#update-and-rollback)
- [Environment matrix](#environment-matrix)
- [Scheduler and queue](#scheduler-and-queue)
- [Alert channels](#alert-channels)
- [Backup and restore](#backup-and-restore)
- [PostgreSQL / TimescaleDB migration rehearsal](#postgresql--timescaledb-migration-rehearsal)
- [Security checklist](#security-checklist)
- [Troubleshooting](#troubleshooting)

## Requirements

**Server**

- PHP **8.3 or newer** (8.4 recommended) CLI + FPM with `pdo_sqlite`,
  `sqlite3`, `mbstring`, `openssl`, `tokenizer`, `xml`, `ctype`, `json`,
  `fileinfo`, `curl`, and `intl`.
- SQLite 3 on local disk (not NFS). No MySQL server is required.
- **No Node/npm on the server.** `public/build/` (JS, CSS, fonts, and
  `manifest.json`) is built locally or in CI and uploaded with the release.
- A web server pointed at `current/public` (Nginx example in
  [`ops/vps/nginx.conf`](ops/vps/nginx.conf)).
- Outbound SMTP and, if Telegram is used, HTTPS to `api.telegram.org`.
- A cron entry (shared hosting) or systemd timer (VPS) for `schedule:run`.

**Local development**

This repository's development host intentionally has no PHP or Composer. Use
the Podman wrappers; never call host `php`, `composer`, or `artisan`.

```bash
./tools/php.sh composer install
./tools/php.sh php artisan migrate
./tools/php.sh php artisan test --filter=BackupTest
./tools/test.sh                 # Pint + full suite
./tools/test.sh --with-assets   # Pint + suite + npm run build
```

`./tools/php.sh` runs any command inside the PHP 8.4 image with the repository
mounted at `/app`. Server commands below use plain `php`; on this development
host, prefix them with `./tools/php.sh php`.

## Local development

```bash
cp .env.example .env
./tools/php.sh php artisan key:generate
./tools/php.sh php artisan migrate
./tools/php.sh php artisan noc:create-admin --name="Operator" \
    --email=operator@example.com --password='a-long-local-password'
./tools/php.sh php artisan test
```

The wrapper does not publish container ports; it is for Composer, Artisan,
and the test suite. To preview the UI in a browser, run the container with
`-p 8000:8000` and `php artisan serve --host=0.0.0.0 --port=8000`, or use a
real deployment host. Tests use an in-memory SQLite database and the array
mail/cache/queue drivers (see `phpunit.xml`), so they never touch a real
mailbox or the development database.

## Install

The release layout is shared-hosting friendly and also works on a VPS:

```
~/noc/
  releases/2026-11-24T120000Z/   uploaded release
  shared/.env                    persistent, chmod 600
  current -> releases/...        atomic symlink
~/noc-data/noc.sqlite            database (outside public/)
```

1. **Upload a release** (including `vendor/` or run Composer on the host) to
   `~/noc/releases/<timestamp>/`. `public/build/manifest.json` must exist.
2. **Create the shared environment file** and set at minimum `APP_NAME`,
   `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://…`, `APP_KEY`,
   `DB_DATABASE` (absolute path), and the `MAIL_*` values you need. See
   [Environment matrix](#environment-matrix).
   ```bash
   install -m 600 /dev/null ~/noc/shared/.env
   # generate the key before the first deploy and paste it into shared/.env
   php -r 'echo "base64:".base64_encode(random_bytes(32)).PHP_EOL;'
   ```
   `APP_KEY` encrypts Telegram credentials and the two-factor secret and keys
   agent-token hashes. **Changing it invalidates every agent token and makes
   stored secrets unreadable.** Keep the backup of `.env` with the database
   backup; see [Backup and restore](#backup-and-restore).
3. **Run the deploy script** from the uploaded release (or pass
   `--release-dir`):
   ```bash
   cd ~/noc/releases/<timestamp>
   ops/shared-hosting/deploy.sh --app-root="$HOME/noc" --release-dir="$PWD"
   ```
   It links `shared/.env` into the release, refuses PHP < 8.3 or a missing
   asset manifest, runs `migrate --force --isolated`, rebuilds
   config/route/view caches, and swaps `current` atomically. It never calls
   `sudo`.
4. **Create the first administrator once**:
   ```bash
   php ~/noc/current/artisan noc:create-admin
   ```
5. **Point the document root** at `~/noc/current/public`. `.env`, `storage/`,
   `vendor/`, and SQLite must stay outside it.
6. **Enable the scheduler** — cron line from
   [`ops/shared-hosting/cron.example`](ops/shared-hosting/cron.example), or
   the systemd units in [`ops/vps/`](ops/vps/).
7. **Configure alerts** at `/settings/alerts` and press **Send test** for each
   channel (see [Alert channels](#alert-channels)).
8. **Take the first backup** and confirm it verifies:
   ```bash
   php ~/noc/current/artisan noc:backup --path="$HOME/noc-backups/noc-$(date -u +%Y%m%dT%H%M%SZ).sqlite"
   ```

## Update and rollback

Upload a new release directory, then run:

```bash
ops/shared-hosting/deploy.sh --app-root="$HOME/noc" --release-dir="$HOME/noc/releases/<new>"
```

- `migrate --force --isolated` uses the cache lock so two deploys cannot race.
- `config:cache`, `route:cache`, and `view:cache` run **before** the symlink
  swap; a failure leaves the previous release live.
- Nginx serves through `current`, so the switch is atomic.
- If `.env` is empty, `deploy.sh` links `shared/.env` into the release.

**Rollback:** re-point `current` at the previous release and clear the caches.
Migrations are forward-only; if a migration must be undone, restore the
database backup taken immediately before the deploy (see below).

```bash
ln -sfn ~/noc/releases/<previous> ~/noc/.current.rollback
mv -Tf ~/noc/.current.rollback ~/noc/current
php ~/noc/current/artisan optimize:clear
```

## Environment matrix

Only the values that differ by host are listed. Keep secrets out of the
database; `MAIL_*` and `APP_KEY` live in `.env` (chmod 600).

| Variable | Shared hosting | VPS (small) |
| --- | --- | --- |
| `APP_ENV` / `APP_DEBUG` | `production` / `false` | `production` / `false` |
| `DB_CONNECTION` | `sqlite` | `sqlite` |
| `DB_DATABASE` | `/home/ACCOUNT/noc-data/noc.sqlite` | `/var/lib/noc/noc.sqlite` |
| `DB_BUSY_TIMEOUT` | `5000` | `5000` |
| `DB_JOURNAL_MODE` | `wal` | `wal` |
| `DB_SYNCHRONOUS` | `normal` | `normal` |
| `DB_TRANSACTION_MODE` | `IMMEDIATE` | `IMMEDIATE` |
| `CACHE_STORE` | `file` | `redis` |
| `SESSION_DRIVER` | `file` | `redis` |
| `QUEUE_CONNECTION` | `database` | `redis` |
| `SESSION_SECURE_COOKIE` | `true` under HTTPS | `true` under HTTPS |
| `LOG_STACK` / `LOG_LEVEL` | `daily` / `warning` | `daily` / `warning` |
| `MAIL_MAILER` | `smtp` | `smtp` |
| `REDIS_HOST` | unused | `127.0.0.1` |
| Scheduler | cron every minute | `noc-scheduler.timer` |
| Queue worker | scheduler `queue:work` per minute | `noc-queue.service` |

Notes:

- `CACHE_STORE=file` and `SESSION_DRIVER=file` avoid a database-backed cache
  competing with ingest writes on shared hosting. If the host provides Redis,
  use it.
- `DB_TRANSACTION_MODE=IMMEDIATE` is honoured on PHP 8.4+; PHP 8.3 still uses
  `BEGIN DEFERRED`, so do not rely on `IMMEDIATE` to rule out lock contention.
- Keep `storage/` and `bootstrap/cache/` writable **only** by the PHP user.
- On a VPS, Redis must be installed and listening on loopback; the queue and
  scheduler systemd units assume the application is at `/var/www/noc/current`.

## Scheduler and queue

The schedule lives in `routes/console.php` and runs:

| Command | Cadence | Purpose |
| --- | --- | --- |
| `noc:evaluate-liveness` | every minute | stale/offline alerts |
| `noc:alert-reminders` | every 5 minutes | reminders and retries |
| `noc:rollup` | every 5 minutes | 5m/1h/1d rollups |
| `noc:prune` | daily 03:30 | retention (spec §7.11) |
| `queue:work --stop-when-empty --max-time=50 --tries=3 --sleep=1` | every minute | email/Telegram + queued jobs |

**Shared hosting:** one cron line is enough —
[`ops/shared-hosting/cron.example`](ops/shared-hosting/cron.example):

```cron
* * * * * cd /home/ACCOUNT/noc/current && php artisan schedule:run >> /dev/null 2>&1
```

The scheduled `queue:work` has no supervisor and stops when the queue is empty
or after 50 seconds, which is below the one-minute cadence. It uses
`withoutOverlapping`; a killed run cannot block the next minute forever.

**VPS:** install [`ops/vps/noc-scheduler.service`](ops/vps/noc-scheduler.service)
with [`noc-scheduler.timer`](ops/vps/noc-scheduler.timer), and
[`ops/vps/noc-queue.service`](ops/vps/noc-queue.service) for one long-running
worker (`Restart=always`, `MemoryMax=512M`).

```bash
sudo cp ops/vps/noc-scheduler.service ops/vps/noc-scheduler.timer ops/vps/noc-queue.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now noc-scheduler.timer noc-queue
systemctl list-timers noc-scheduler.timer
journalctl -u noc-queue -f
```

After every deploy on a VPS, run `php artisan queue:restart` (the deploy script
does this best-effort) so workers load the new release.

**Health:** Laravel exposes `GET /up`. Monitor it externally and add a
dead-man check: alert when `max(servers.last_seen_at)` is older than three
times the agent's reporting interval.

## Alert channels

Every alert exists in the dashboard first. **In-app alerts never depend on
email, Telegram, a queue worker, or outbound network.** The alert bell polls
every 30 seconds, counts open/acknowledged alerts, and plays a same-origin
chime when a newer open alert appears. If email and Telegram are down, use the
in-app alert and delivery rows.

Email and Telegram are delivered by queued jobs. If the queue worker is not
running, deliveries stay `pending` and no message leaves the server; fix the
scheduler/worker and `noc:alert-reminders` retries them.

### Email (SMTP)

Recipients are **active admin and operator users with “Email alerts” enabled**
in their profile (`users.notify_email`, default on). Configure the server-side
mailer in `.env`:

```dotenv
MAIL_MAILER=smtp
MAIL_HOST=smtp.example.com
MAIL_PORT=587
MAIL_USERNAME=noc@example.com
MAIL_PASSWORD=change-me
MAIL_ENCRYPTION=null
MAIL_SCHEME=null
MAIL_FROM_ADDRESS="noc@example.com"
MAIL_FROM_NAME="${APP_NAME}"
```

- Port 587 uses STARTTLS; port 465 is detected as implicit TLS. Laravel 13
  reads `MAIL_SCHEME` (leave `null` unless you need `smtp`/`smtps`).
  `MAIL_ENCRYPTION` is kept for older hosting guides but is not read by this
  version.
- Shared hosting must allow **outbound TCP to the SMTP host and port**.
  Many hosts block 25; prefer 587 or 465.
- Then open `/settings/alerts` and press **Send test email**. The button is
  admin-only, rate-limited (3 per minute), and audited. It does not accept a
  destination address.
- A failed address cannot block other recipients: the channel sends one
  message per recipient and records a redacted note. The delivery row on the
  alert detail page shows the outcome.

### Telegram (optional)

1. In Telegram, talk to **@BotFather**, create a bot, and copy its token
   (`123456789:AA…`). Never paste it into a ticket, log, or chat.
2. Add the bot to the target group or channel. For a channel, make the bot an
   administrator so it may post.
3. Get the **chat ID**:
   - Send a message in the group, then call
     `https://api.telegram.org/bot<TOKEN>/getUpdates` and copy
     `message.chat.id`. Group/supergroup IDs are negative (`-100…`).
   - Or use a public channel username (`@channelname`).
4. (Forum topics only) Enable **Topics** in the group, open the topic, send a
   message there, call `getUpdates`, and copy the message's
   `message_thread_id`. It is a positive integer. Leave the field blank for
   the General topic or a non-forum chat.
5. In `/settings/alerts`, enable Telegram, paste the bot token (write-only,
   encrypted with `APP_KEY`, never shown again), enter the chat ID and optional
   thread ID, save, and press **Send test message**.
6. Allow **outbound HTTPS to `api.telegram.org` (443)**. The host is fixed; no
   user-supplied URL is used.

Acknowledge **stops reminders** until the alert resolves (acknowledged alerts
are skipped by `noc:alert-reminders`). Resolve sends **one recovery message per
channel** that had an opening delivery. Email and Telegram fail independently;
a failed channel never suppresses the other or the in-app alert. The full
Telegram URL contains the bot token and is never logged, stored, or shown.

### Delivery log

The alert detail page lists each channel's `opened`, reminder, and `resolved`
rows with status, attempt count, and a bounded redacted error. Use it to
distinguish “queued but worker is down” (`pending`) from “SMTP rejected”
(`failed`).

## Backup and restore

Backups are **SQLite-only** in the MVP (the command fails clearly on any other
driver). `noc:backup` copies the live database with `VACUUM INTO` (consistent,
single file, readable while the dashboard runs), then:

- refuses a relative path, a directory, a symlink (including a broken one),
  an existing file, or a missing/unwritable parent directory;
- runs `PRAGMA integrity_check` against the copy and deletes it if the check
  fails;
- prints the size, `integrity_check: ok`, and the row count of every table;
- prints no credentials.

```bash
# production server (absolute path; the directory must exist)
php ~/noc/current/artisan noc:backup --path=/home/ACCOUNT/noc-backups/noc-2026-11-24.sqlite

# local development wrapper
./tools/php.sh php artisan noc:backup --path=/tmp/noc-backup.sqlite
```

`ops/backup.sh` adds a UTC timestamp and retention. It reads
`NOC_BACKUP_DIR` (default `$HOME/noc-backups`),
`NOC_BACKUP_RETENTION_DAYS` (default 14, `0` disables), and
`NOC_BACKUP_RETENTION_COUNT` (default 14, `0` disables), or the equivalent
`--dest`, `--retention-days`, and `--retention-count` flags.

```bash
# nightly at 02:15, keep 14 days and the newest 14 copies
15 2 * * * /home/ACCOUNT/noc/current/ops/backup.sh >> /home/ACCOUNT/noc-backups/backup.log 2>&1
```

Store copies **off the server**, encrypted (spec §14/§15.2; e.g. `age`, `gpg`,
or restic/borg). Retention alone is not a backup strategy.

**Restore** (brief maintenance window; stop the scheduler and queue first):

```bash
# 1. Stop writers: comment out the cron line; on a VPS:
sudo systemctl stop noc-queue noc-scheduler.timer

# 2. Verify the copy before touching the live database
php -r '$p=new PDO("sqlite:/home/ACCOUNT/noc-backups/noc-2026-11-24.sqlite"); echo $p->query("PRAGMA integrity_check")->fetchColumn(), PHP_EOL;'

# 3. Replace the database with the verified copy (keep the old file just in case)
mv ~/noc-data/noc.sqlite ~/noc-data/noc.sqlite.before-restore
cp ~/noc-backups/noc-2026-11-24.sqlite ~/noc-data/noc.sqlite
chmod 640 ~/noc-data/noc.sqlite

# 4. Clear caches and restart the scheduler/queue
php ~/noc/current/artisan optimize:clear
sudo systemctl start noc-scheduler.timer noc-queue   # VPS only
```

Restoring into a fresh SQLite file preserves server and sample counts (covered
by `tests/Feature/Ops/BackupTest.php`). The backup does **not** contain
`APP_KEY`. Use the same `APP_KEY` as when it was taken, or agent tokens
(HMAC-keyed) and Telegram/two-factor secrets become invalid. If the key is
lost, issue new agent tokens from each server's Token page and re-enter the
Telegram bot token.

## PostgreSQL / TimescaleDB migration rehearsal

SQLite + rollups is the supported MVP. Move only when one of the spec §16
thresholds is crossed: **about 300 servers at a 15-second interval, 50 million
raw sample rows, or p95 ingest write > 50 ms.** Rehearse on a copy, never in
place.

1. **Start a separate PostgreSQL 16 container** (do not reuse the production
   SQLite file in place):
   ```bash
   podman run --rm -d --name noc-pg16 \
     -e POSTGRES_PASSWORD=noc-rehearsal \
     -e POSTGRES_DB=noc \
     -p 5432:5432 postgres:16
   ```
2. **Point the working environment at it.** Back up `.env` first (for
   example `cp .env .env.sqlite-backup`) and edit the working `.env`, or use a
   dedicated rehearsal checkout. Keep the real file untouched:
   ```dotenv
   DB_CONNECTION=pgsql
   DB_HOST=127.0.0.1
   DB_PORT=5432
   DB_DATABASE=noc
   DB_USERNAME=postgres
   DB_PASSWORD=noc-rehearsal
   ```
   When PHP runs in the Podman dev image, use
   `DB_HOST=host.containers.internal` instead of `127.0.0.1`, because
   `127.0.0.1` inside the container is not the host.
3. **Run the same migrations**:
   ```bash
   ./tools/php.sh php artisan config:clear
   ./tools/php.sh php artisan migrate --force
   ```
   The migrations must be portable: ULIDs, JSON columns, UTC timestamps, and
   `insertOrIgnore`/`upsert` only. No SQLite-only SQL is used.
4. **Replay a real backup** into Postgres. `noc:backup` produces SQLite, so
   use pgloader (the spec §16 recommendation) or an equivalent one-off export/
   import:
   ```bash
   # SQLite -> PostgreSQL; adjust the mount path and credentials
   podman run --rm --network host -v "$PWD/backups:/backups:ro" \
     dimitri/pgloader:latest \
     pgloader sqlite:///backups/noc.sqlite \
             pgsql://postgres:noc-rehearsal@127.0.0.1:5432/noc
   ```
   Compare row counts for `servers`, `metric_samples`, `metric_details`,
   `metric_rollups`, `alerts`, `alert_deliveries`, and `audit_events`.
5. **Run the suite against Postgres.** The dev image does not ship
   `pdo_pgsql`; check with `./tools/php.sh php -m | grep pdo_pgsql`. If it is
   missing, use a PHP 8.4 image that includes it and inject the same `DB_*`
   values (the wrapper does not forward host environment variables):
   ```bash
   podman run --rm --userns=keep-id -v "$PWD:/app:Z" -w /app -e HOME=/tmp \
     -e DB_CONNECTION=pgsql -e DB_HOST=host.containers.internal \
     -e DB_PORT=5432 -e DB_DATABASE=noc \
     -e DB_USERNAME=postgres -e DB_PASSWORD=noc-rehearsal \
     <your-php-image-with-pdo_pgsql> \
     php artisan test --filter='Ingest|Series|Alert|Prune|Backup'
   ```
   At minimum cover the ingest, series, alert, retention, and backup suites.
   Fix any driver-specific failure in the service layer, not with raw SQL in
   controllers.
6. **TimescaleDB (optional)**: on a TimescaleDB-enabled Postgres, convert
   `metric_samples` to a hypertable on `collected_at` (partition by
   `server_id` only if measurements justify it):
   ```sql
   CREATE EXTENSION IF NOT EXISTS timescaledb;
   SELECT create_hypertable('metric_samples', 'collected_at', if_not_exists => TRUE);
   ```
   Move rollups to continuous aggregates gradually; keep the
   `metric_rollups` table as the fallback.
7. **Switch back** to SQLite by restoring the original `.env`
   (`mv .env.sqlite-backup .env`) and running `php artisan config:clear`. If the
   rehearsal became the real migration, cut over `DB_CONNECTION=pgsql`, verify
   ingest, charts, alerts, retention, and backup, and keep the SQLite file
   read-only until the next verification.

## Security checklist

- Document root is `current/public`; `.env`, `storage/`, `vendor/`, and SQLite
  are outside it. `ops/vps/nginx.conf` also denies dotfiles and SQLite paths.
- `.env` is mode 600; the SQLite file 640 and its directory 750.
- `APP_ENV=production`, `APP_DEBUG=false`, HTTPS enforced, HSTS emitted.
- Sessions are `Secure`, `HttpOnly`, `SameSite=Lax`; rotating `APP_KEY`
  invalidates agent tokens and stored secrets.
- `composer audit` is clean before each release; the lockfile is committed.
- `GET /up` is monitored externally; log rotation keeps 14 days.
- Backups leave the server encrypted; restore is rehearsed periodically.
- Telegram bot tokens and two-factor secrets are encrypted with `APP_KEY` and
  never returned to the browser after saving.

## Troubleshooting

| Symptom | Check |
| --- | --- |
| Alerts are in-app only | Is the scheduler cron/timer running? `php artisan schedule:list`. Is `queue:work` finishing under 50 s? Check `storage/logs/laravel.log`. |
| Email `pending` forever | Queue worker not running, or `QUEUE_CONNECTION` points at a service that is down. Run `php artisan queue:work --stop-when-empty` once and read the error. |
| Email `failed` | `MAIL_*` values, outbound port, provider app password, `MAIL_FROM_ADDRESS` domain. Use **Send test email** and read the delivery row. |
| Telegram `failed` | Bot token, chat ID (negative for groups), bot is a member/admin, forum `message_thread_id` belongs to that chat, outbound HTTPS to `api.telegram.org`. |
| `database is locked` | Confirm `DB_JOURNAL_MODE=wal` and `DB_BUSY_TIMEOUT=5000`; keep the DB on local disk; do not run long manual queries during ingest. |
| Deploy fails on manifest | `public/build/manifest.json` was not uploaded; build assets locally/CI and re-upload. Never run `npm run build` on the server. |
| `noc:backup` says destination exists | Backups never overwrite. Choose a new timestamped path or move the old copy. |
| Restored dashboard rejects agents | The backup was restored with a different `APP_KEY`; reissue tokens or restore the matching `.env`. |
| 500 after deploy | `php artisan optimize:clear`, check file permissions on `storage/` and `bootstrap/cache/`, then `php artisan about`. |

See [`ops/shared-hosting/README.md`](ops/shared-hosting/README.md) for the
shared-hosting layout and rollback, and [`ops/vps/`](ops/vps/) for the Nginx
and systemd units.
