# NOC Center

Repository for the NOC monitoring stack. The Laravel dashboard lives at the
repository root; the Bash agent remains in [`agent/`](agent/README.md).

- **Dashboard** — Laravel 13 + Blade/Livewire UI, ingest API, SQLite storage,
  in-app/SMTP/optional Telegram alerts, history, and operations scripts. The
  install and deployment runbook starts below at
  [Dashboard runbook](#dashboard-runbook).
- **Agent** — a lightweight Bash collector installed on each monitored server.
  It posts a schema-v1 JSON payload over HTTPS. See
  [`agent/README.md`](agent/README.md).

## Quick start (dashboard)

```bash
cp .env.example .env
touch database/database.sqlite
./tools/php.sh composer install
./tools/php.sh php artisan key:generate
./tools/php.sh php artisan migrate
./tools/php.sh php artisan noc:create-admin
./tools/test.sh
```

Production needs no Node runtime: `public/build/` is committed and uploaded
with the release. See [Install](#install) and [Update and rollback](#update-and-rollback).

## Quick start (agent)

On each monitored server, as root. The dashboard serves the agent bundle, so
no repository checkout is needed:

```bash
# 1. Download the agent from the dashboard
sudo curl -fsSL https://monitor.example.com/agent/download -o /root/noc-agent.tar.gz
sudo rm -rf /root/noc-agent
sudo tar -xzpf /root/noc-agent.tar.gz -C /root

# 2. Store the per-server token in a root-only file
sudo install -m 600 /dev/null /root/noc-token
sudo bash -c 'read -rsp "Token: " token; printf "%s" "$token" > /root/noc-token; echo'

# 3. Install and start the timers
sudo /root/noc-agent/install.sh --api-url https://monitor.example.com/api/v1/ingest \
  --token-file /root/noc-token
```

The same command, with the token prompt, appears on the server's one-time
token reveal page. `APP_URL` must be the dashboard's public URL, not
`localhost`, when monitored servers are on other hosts.

The legacy root-level `collector-cpanel.sh` has been retired. Deployments
still running it should migrate to the agent above.

See [`agent/README.md`](agent/README.md) for prerequisites, configuration,
timers, troubleshooting, token rotation, and uninstall.

## Repository layout

| Path | Purpose |
| --- | --- |
| `app/`, `config/`, `database/`, `public/`, `resources/`, `routes/`, `tests/` | Laravel dashboard application. |
| `ops/` | Shared-hosting and VPS deployment, cron, backup, and restore files. |
| `tools/php.sh`, `tools/test.sh` | Local wrappers for Composer, Artisan, Pint, and tests. They use host PHP 8.3+ when available and fall back to Podman. |
| `tools/load-ingest.php` | Acceptance load generator for the ingest endpoint. |
| `agent/` | Agent: `collector.sh` core, `lib/`, `panels/`, `modules/`, `systemd/`, `config/`, installer and uninstaller. |
| `tools/tests/`, `tools/test-agent.sh`, `tools/mock-ingest.py` | Agent test suites, one-command runner, and local mock ingest endpoint. |
| `docs/superpowers/` | Approved design and implementation plan. |

---

# Dashboard runbook


The Laravel + Blade/Livewire dashboard for the NOC monitoring stack: ingest,
SQLite storage, alerts, history, and the operator UI. It pairs with the Bash
agent in [`agent/`](agent/README.md); the agent posts
`POST /api/v1/ingest`, the dashboard stores and reports it.

This file is the deployment and operations runbook. Design rationale lives in
[`docs/superpowers/specs/2026-11-22-noc-dashboard-design.md`](docs/superpowers/specs/2026-11-22-noc-dashboard-design.md).

- [Requirements](#requirements)
- [Local development](#local-development)
- [Install](#install)
- [Update and rollback](#update-and-rollback)
- [Environment matrix](#environment-matrix)
- [Scheduler and queue](#scheduler-and-queue)
- [Realtime updates](#realtime-updates)
- [Alert channels](#alert-channels)
- [Backup and restore](#backup-and-restore)
- [PostgreSQL / TimescaleDB migration rehearsal](#postgresql--timescaledb-migration-rehearsal)
- [Acceptance checklist](#acceptance-checklist)
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

`./tools/php.sh` prefers a host PHP 8.3+ toolchain and a host Composer. When
either is missing (or PHP is older than 8.3) it falls back to the pinned PHP
8.4 Podman image, so CI and machines without PHP keep working.

```bash
./tools/php.sh composer install
./tools/php.sh php artisan migrate
./tools/php.sh php artisan test --filter=BackupTest
./tools/test.sh                 # Pint + full suite
./tools/test.sh --with-assets   # Pint + suite + npm run build
```

The wrapper maps `php …` and `composer …` to the host tools when present;
otherwise it runs the command inside the PHP 8.4 image with the repository
mounted at `/app`. Server commands below use plain `php`; in this repository
prefix them with `./tools/php.sh php` unless you deliberately use host PHP.

## Local development

```bash
cp .env.example .env
touch database/database.sqlite
./tools/php.sh composer install
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
  shared/storage/                persistent sessions/cache/logs/uploads
  current -> releases/...        atomic symlink
~/noc-data/noc.sqlite            database (outside public/)
~/noc-backups/                   timestamped backups and logs
```

### Prerequisites (once, before the first deploy)

1. **PHP 8.3+ CLI and FPM** with the extensions listed above. Record the
   absolute CLI path for cron (`command -v php`; on shared hosts it is often
   `/usr/local/bin/php` or an `/opt/cpanel/.../php` path, not the web PHP).
2. **Upload a release** (including `vendor/` or run Composer on the host) to
   `~/noc/releases/<timestamp>/`. `public/build/manifest.json` must exist.
3. **Create the directories, backup target, and database file.** Laravel
   refuses to connect when the configured SQLite file does not exist, and an
   empty file is a valid empty database:
   ```bash
   mkdir -p ~/noc/releases ~/noc/shared/storage/logs ~/noc-data ~/noc-backups
   chmod 750 ~/noc-data ~/noc-backups ~/noc/shared/storage
   install -m 640 /dev/null ~/noc-data/noc.sqlite
   ```
   The cron redirect in `ops/shared-hosting/cron.example` writes to
   `~/noc/shared/storage/logs/scheduler.log`; that directory exists after this
   step and after `deploy.sh`.
4. **Create the shared environment file** and set at minimum `APP_NAME`,
   `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://…`, `APP_KEY`,
   `DB_DATABASE` (absolute path), `QUEUE_SCHEDULED_WORKER=true` (shared
   hosting), and the `MAIL_*` values you need. See
   [Environment matrix](#environment-matrix).
   ```bash
   install -m 600 /dev/null ~/noc/shared/.env
   # generate the key before the first deploy and paste it into shared/.env
   php -r 'echo "base64:".base64_encode(random_bytes(32)).PHP_EOL;'
   ```
   `shared/.env` is authoritative; `deploy.sh` symlinks it into every release
   and it replaces any release-local `.env`. `APP_URL` must be the canonical
   public origin: the app rejects other Host headers so password-reset links
   cannot be redirected to another domain. `APP_KEY` encrypts Telegram
   credentials and the two-factor secret and keys agent-token hashes.
   **Changing it invalidates every agent token and makes stored secrets
   unreadable.** Keep a copy of `.env` with the database backup; see
   [Backup and restore](#backup-and-restore).
5. **Install dependencies** if the release does not include `vendor/`:
   ```bash
   cd ~/noc/releases/<timestamp>
   composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader
   ```

### First deploy

1. **Run the deploy script** from the uploaded release (or pass
   `--release-dir`):
   ```bash
   cd ~/noc/releases/<timestamp>
   ops/shared-hosting/deploy.sh --app-root="$HOME/noc" --release-dir="$PWD"
   ```
   It links `shared/.env`, converts the release `storage/` into a symlink to
   `~/noc/shared/storage` (copying any existing files on the first run),
   refuses PHP < 8.3 or a missing asset manifest, runs
   `migrate --force --isolated`, rebuilds config/route/view caches, and swaps
   `current` atomically. It never calls `sudo`.
   **Run `deploy.sh` and every artisan command as the PHP-FPM user** (on shared
   hosting usually the account owner; on a VPS e.g.
   `sudo -u www-data php .../artisan ...`), or chown the SQLite file and
   `~/noc/shared/storage` to that user first. Running them as another user can
   create root-owned SQLite/WAL files that the web process cannot write.
2. **Create the first administrator once**:
   ```bash
   php ~/noc/current/artisan noc:create-admin
   ```
3. **Point the document root** at `~/noc/current/public`. `.env`, `storage/`,
   `vendor/`, and SQLite must stay outside it.
4. **Enable the scheduler** — cron line from
   [`ops/shared-hosting/cron.example`](ops/shared-hosting/cron.example) using
   the absolute PHP binary, or the systemd units in [`ops/vps/`](ops/vps/).
   The backup directory already exists from the prerequisites, so its log and
   the backup cron can be installed safely.
5. **Configure alerts** at `/settings/alerts` and press **Send test** for each
   channel (see [Alert channels](#alert-channels)).
6. **Take the first backup** and confirm it verifies:
   ```bash
   php ~/noc/current/artisan noc:backup --path="$HOME/noc-backups/noc-$(date -u +%Y%m%dT%H%M%SZ).sqlite"
   ```


## Update and rollback

Upload a new release directory, then run:

```bash
ops/shared-hosting/deploy.sh --app-root="$HOME/noc" --release-dir="$HOME/noc/releases/<new>"
```

- `migrate --force --isolated` uses the cache lock, and `deploy.sh` also takes
  an exclusive host-level `flock` on `$APP_ROOT/.deploy.lock` when `flock` is
  available, so two deploys cannot race. With shared storage and
  `CACHE_STORE=file`, the cache lock spans releases; a release with a private
  cache or a different cache store only serialises itself.
- `config:cache`, `route:cache`, and `view:cache` run **before** the symlink
  swap; a failure leaves the previous release live.
- Nginx serves through `current`, so the switch is atomic.
- `shared/.env` is authoritative: when it exists, `deploy.sh` symlinks it into
  every release and it replaces a release-local `.env`.
- `storage` is a symlink to `shared/storage`, so file sessions, cache, logs,
  and `storage/app/public` uploads survive the release swap. Keep
  `~/noc/shared/storage` writable by the PHP user (750 directories; files 640).

**Rollback:** re-point `current` at the previous release, clear the caches,
and restart workers. Migrations are forward-only; if a migration must be
undone, restore the database backup taken immediately before the deploy (see
below).

```bash
ln -sfn ~/noc/releases/<previous> ~/noc/.current.rollback
mv -Tf ~/noc/.current.rollback ~/noc/current
php ~/noc/current/artisan optimize:clear
php ~/noc/current/artisan queue:restart
```

Releases created after the shared-storage change point their `storage` symlink
at `shared/storage`, so sessions and logs remain available after a rollback.
A release created before that change still has a real `storage` directory and
does not see data written to shared storage after the upgrade; migrate it with
`deploy.sh` before relying on a rollback to it.

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
| `QUEUE_SCHEDULED_WORKER` | `true` (scheduler drains queue) | `false` (systemd worker owns it) |
| `BROADCAST_CONNECTION` | `log` | `reverb` |
| `NOC_REALTIME` | `false` (polling only) | `true` |
| `REVERB_SERVER_HOST` / `REVERB_SERVER_PORT` | unused | `127.0.0.1` / `8080` |
| `REVERB_HOST` / `REVERB_PORT` / `REVERB_SCHEME` | unused | `noc.example.com` / `443` / `https` |
| `SESSION_SECURE_COOKIE` | `true` under HTTPS | `true` under HTTPS |
| `LOG_STACK` / `LOG_LEVEL` | `daily` / `warning` | `daily` / `warning` |
| `MAIL_MAILER` | `smtp` | `smtp` |
| `REDIS_HOST` | unused | `127.0.0.1` |
| Scheduler | cron every minute | `noc-scheduler.timer` |
| Queue worker | scheduler `queue:work` per minute | `noc-queue.service` |

Notes:

- `CACHE_STORE=file` and `SESSION_DRIVER=file` avoid a database-backed cache
  competing with ingest writes on shared hosting. If the host provides Redis,
  use it. On a VPS set `QUEUE_SCHEDULED_WORKER=false` in `.env` **before**
  `config:cache` so two workers do not consume the same jobs.
- `NOC_TOKEN_GRACE_MINUTES` (default `1440`) is the rotation grace window.
  Change it in `.env` and rebuild the config cache; it affects new rotations
  only, never an existing expiry.
- `DB_TRANSACTION_MODE=IMMEDIATE` is honoured on PHP 8.4+; PHP 8.3 still uses
  `BEGIN DEFERRED`, so do not rely on `IMMEDIATE` to rule out lock contention.
- Keep `shared/storage/` and `bootstrap/cache/` writable **only** by the PHP
  user. `deploy.sh` creates `shared/storage` with 750 directories and symlinks
  each release's `storage` to it, so sessions, cache, and logs survive deploys.
- **Reverse proxy / Cloudflare:** set `TRUSTED_PROXIES` to the proxy IPs or
  CIDRs (comma-separated) so audit rows and rate limits use the real client
  IP from `X-Forwarded-For`. Use `TRUSTED_PROXIES=*` only when the origin is
  firewalled to the proxy; otherwise the header is spoofable. Restrict the
  origin to Cloudflare's published ranges and keep those ranges current.
  The value is read from `config/trustedproxy.php`; after changing `.env`, run
  `php artisan optimize:clear && php artisan config:cache`.
- **aaPanel / Nginx behind Cloudflare:** if the panel's PHP include does not
  expose the client address, configure Nginx `real_ip` instead. Replace the
  domain in the path below, then reload Nginx:

  ```bash
  conf=/www/server/panel/vhost/nginx/extension/panel.developers.web.id/cloudflare-real-ip.conf
  {
      echo 'real_ip_header CF-Connecting-IP;'
      echo 'real_ip_recursive on;'
      for ip in $(curl -fsS https://www.cloudflare.com/ips-v4) $(curl -fsS https://www.cloudflare.com/ips-v6); do
          echo "set_real_ip_from $ip;"
      done
  } | sudo tee "$conf" >/dev/null
  sudo nginx -t && sudo nginx -s reload
  ```

  After this, `REMOTE_ADDR` is the real client IP before PHP-FPM, so Activity
  no longer stores a Cloudflare edge address. Keep the generated file current
  when Cloudflare changes its ranges. If Nginx already sets `real_ip`,
  `TRUSTED_PROXIES` may stay empty; do not enable both unless you have verified
  the resulting `X-Forwarded-For` chain.
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

**Shared hosting:** one cron line is enough — see
[`ops/shared-hosting/cron.example`](ops/shared-hosting/cron.example). Use the
absolute PHP 8.3+ CLI path and a visible log file, not `/dev/null`:

```cron
* * * * * cd /home/ACCOUNT/noc/current && /usr/local/bin/php artisan schedule:run >> /home/ACCOUNT/noc/shared/storage/logs/scheduler.log 2>&1
```

The scheduled `queue:work` exists only when `QUEUE_SCHEDULED_WORKER=true`
(the default). It is not a supervisor: it stops when the queue is empty or
after 50 seconds, below the one-minute cadence. It uses `withoutOverlapping`
with a two-minute lock, so a run killed at 50 seconds blocks the **immediately
next tick**; the following tick can run again.

**VPS:** install [`ops/vps/noc-scheduler.service`](ops/vps/noc-scheduler.service)
with [`noc-scheduler.timer`](ops/vps/noc-scheduler.timer), and
[`ops/vps/noc-queue.service`](ops/vps/noc-queue.service) for one long-running
worker (`Restart=always`, `MemoryMax=512M`). Set
`QUEUE_SCHEDULED_WORKER=false` in `shared/.env` before `config:cache` (the
scheduler unit also sets it, but the cached config is authoritative) so the
schedule does not start a second worker.

```bash
sudo cp ops/vps/noc-scheduler.service ops/vps/noc-scheduler.timer ops/vps/noc-queue.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now noc-scheduler.timer noc-queue
systemctl list-timers noc-scheduler.timer
journalctl -u noc-queue -f
```

After every deploy on a VPS, run `php artisan queue:restart` (the deploy script
does this best-effort) so workers load the new release.

The systemd units run as `User=www-data`. Run `deploy.sh`, `artisan` commands,
the scheduler and the queue worker as `www-data` (for example
`sudo -u www-data php /var/www/noc/current/artisan ...`), or chown
`/var/lib/noc/noc.sqlite`, the `shared/storage` tree and `bootstrap/cache` to
`www-data`. Otherwise a root/monitor-owned SQLite file or WAL sidecar can block
the web process with `database is locked` or `unable to open database file`.

**Health:** Laravel exposes `GET /up`. Monitor it externally and add a
dead-man check: alert when `max(servers.last_seen_at)` is older than three
times the agent's reporting interval.

## Realtime updates

The dashboard, servers list, server detail and charts, alerts list/detail,
global alert bell, and the “waiting for first sample” page use private Laravel
Reverb channels when `NOC_REALTIME=true`. Activity, Users, and Settings remain
normal HTTP pages; the global bell still updates there.

Polling is always retained as a safety net: 60 seconds when Reverb is enabled,
30 seconds when disabled, and 5 seconds on the waiting page. A Reverb outage
therefore degrades to the current polling behavior instead of freezing the UI.

### Shared hosting

Keep Reverb disabled. No daemon or WebSocket port is needed:

```dotenv
BROADCAST_CONNECTION=log
NOC_REALTIME=false
```

The dashboard polls as before. `public/build/` still contains the Echo bundle;
it is inert without the flag, so disabling realtime never requires a rebuild.

### VPS

Reverb runs as a loopback-only systemd service. Nginx terminates TLS and
proxies both `/app` (browser WebSocket) and `/apps` (Laravel broadcasts) to
`127.0.0.1:8080`; never expose port 8080 publicly.

1. Generate application credentials once and put them in `shared/.env`:

   ```bash
   php -r "echo random_int(100000, 999999), PHP_EOL;"   # REVERB_APP_ID
   openssl rand -hex 16                                  # REVERB_APP_KEY
   openssl rand -hex 32                                  # REVERB_APP_SECRET
   ```

   ```dotenv
   BROADCAST_CONNECTION=reverb
   NOC_REALTIME=true
   REVERB_APP_ID=123456
   REVERB_APP_KEY=replace-with-the-generated-key
   REVERB_APP_SECRET=replace-with-the-generated-secret
   REVERB_SERVER_HOST=127.0.0.1
   REVERB_SERVER_PORT=8080
   REVERB_HOST=noc.example.com
   REVERB_PORT=443
   REVERB_SCHEME=https
   ```

   `REVERB_APP_KEY` is public by design; `REVERB_APP_SECRET` must never reach
   the browser or a log. The public client values are the production HTTPS
   host, not `127.0.0.1`.

2. Rebuild the cached configuration and install the unit:

   ```bash
   sudo -u www-data php /var/www/noc/current/artisan optimize:clear
   sudo -u www-data php /var/www/noc/current/artisan config:cache
   sudo cp ops/vps/noc-reverb.service /etc/systemd/system/
   sudo systemctl daemon-reload
   sudo systemctl enable --now noc-reverb
   ```

3. Confirm Nginx contains the `/app` and `/apps` proxy blocks from
   [`ops/vps/nginx.conf`](ops/vps/nginx.conf), reload it, and check the
   service:

   ```bash
   sudo nginx -t && sudo systemctl reload nginx
   systemctl status noc-reverb --no-pager
   journalctl -u noc-reverb -f
   ss -ltnp | grep 8080
   ```

4. Open the dashboard in two browsers, send a test sample, open an alert, and
   acknowledge/resolve it. Both browsers should update without a manual
   refresh. The browser DevTools WebSocket panel should show
   `wss://noc.example.com/app/...` connected.

After every deploy, restart the workers so they load the new release:

```bash
sudo -u www-data php /var/www/noc/current/artisan queue:restart
sudo -u www-data php /var/www/noc/current/artisan reverb:restart
```

If `reverb:restart` is unavailable after an upgrade, use
`sudo systemctl restart noc-reverb`.

To roll back realtime, set `NOC_REALTIME=false` in `shared/.env`, run
`php artisan config:cache`, and stop the service (`sudo systemctl stop
noc-reverb`). The app falls back to polling without a rebuild. Keep the unit
installed so re-enabling is only an env change plus `systemctl start`.

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
- Production caches configuration (`config:cache`). After changing `MAIL_*` or
  any other `.env` value, run
  `php ~/noc/current/artisan config:cache` before **Send test email**; a
  stale config cache keeps the old mailer. If something looks impossible, run
  `php ~/noc/current/artisan optimize:clear` and reload. Telegram credentials
  are stored through Settings, not `.env`, so they take effect on save.
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

The alert detail page lists each channel's `opened` rows (including reminder
attempts; reminders reuse the opening row, there is no `reminder` event) and
`resolved` rows with status, attempt count, and a bounded redacted error. Use
it to distinguish “queued but worker is down” (`pending`) from “SMTP rejected”
(`failed`).

## Backup and restore

Backups are **SQLite-only** in the MVP (the command fails clearly on any other
driver). `noc:backup` copies the live database with `VACUUM INTO` (consistent,
single file, readable while the dashboard runs), then:

- refuses a relative path, a directory, a symlink (including a broken one),
  an existing file, or a missing/unwritable parent directory;
- writes the copy with mode **0640** (owner read/write, group read) even if the
  operator's umask is permissive;
- runs `PRAGMA integrity_check` against the copy and deletes the copy if the
  check fails; if `VACUUM INTO` itself fails, remove the partial file manually
  after checking that no other backup process owns it;
- prints the size, `integrity_check: ok`, and the row count of every table;
- prints no credentials.

`VACUUM INTO` reads and rewrites the whole database. On a 1 GB database expect
seconds to tens of seconds on SSD, and reserve free space in the destination
directory equal to the database size. Run it off-peak and never run two
backups at once; the copy does not block writers for the full runtime, but it
is I/O-heavy.

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

The script refuses a symlinked destination directory, resolves a relative
`--dest` against the caller's directory, and writes each backup to a unique
hidden partial file before renaming it into place. Two runs in the same second
get distinct names, a failed run leaves no timestamped file, and retention
only considers real (non-hidden, non-symlink) `noc-*.sqlite` files. It runs
with `umask 027` (750 directories, 640 files).

```bash
# nightly at 02:15, keep 14 days and the newest 14 copies
15 2 * * * /home/ACCOUNT/noc/current/ops/backup.sh >> /home/ACCOUNT/noc-backups/backup.log 2>&1
```

Store copies **off the server**, encrypted (spec §14/§15.2; e.g. `age`, `gpg`,
or restic/borg). Retention alone is not a backup strategy.

### Restore

A SQLite database is not one file while WAL mode is active: `noc.sqlite`,
`noc.sqlite-wal`, and `noc.sqlite-shm` belong together. **Never copy a backup
over a live database while its sidecars remain.** A stale `-wal`/`-shm` belongs
to the old database; SQLite can replay or mix those pages into the new file,
and `PRAGMA integrity_check` may still report `ok` because the result is
internally consistent while not being the backup you intended. Move the old
database aside, delete the sidecars, then rename the verified temp copy into
place. Keep the old file until the restored data is accepted.

Run the block with a PHP 8.3+ CLI. Set `PHP_BIN` to the absolute path recorded
during installation when the default CLI is older; `set -euo pipefail` stops at
the first failure, including `artisan down` or a non-`ok` integrity result.

```bash
set -euo pipefail
PHP_BIN="${PHP_BIN:-/usr/local/bin/php}"
APP=/home/ACCOUNT/noc/current          # adjust for the VPS path, /var/www/noc
DB=/home/ACCOUNT/noc-data/noc.sqlite
BK=/home/ACCOUNT/noc-backups/noc-2026-11-24.sqlite
STAMP="$(date -u +%Y%m%dT%H%M%SZ)-$$"
TMP="$DB.restore-$STAMP"
OLD="$DB.before-restore-$STAMP"
"$PHP_BIN" -r 'exit(PHP_VERSION_ID >= 80300 ? 0 : 1);' || {
    echo "restore: $PHP_BIN is older than PHP 8.3" >&2; exit 1;
}

# 1. Verify the backup itself before touching the live database.
"$PHP_BIN" -r '$p=new PDO("sqlite:".$argv[1]); $p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); $r=$p->query("PRAGMA integrity_check")->fetchColumn(); if ($r !== "ok") { fwrite(STDERR, "backup integrity_check: $r\n"); exit(1); } echo "backup integrity_check: ok\n";' "$BK"

# 2. Stop all writers. Shared hosting: comment out the cron line and wait for a
#    running schedule:run/queue:work to finish. VPS: stop the timer, any
#    currently active oneshot scheduler run, the queue worker, and PHP-FPM.
#    Stopping the timer alone does not stop an already-running scheduler unit.
# sudo systemctl stop noc-scheduler.timer noc-scheduler.service noc-queue
# if systemctl is-active --quiet noc-scheduler.service; then
#     echo "restore: scheduler is still running" >&2; exit 1
# fi
# sudo systemctl stop php8.4-fpm

# 3. Put the web app in maintenance mode (503, no new requests reach SQLite).
"$PHP_BIN" "$APP/artisan" down

# 4. Copy the backup to a TEMP file in the SAME directory as the database.
#    Same filesystem makes the final step an atomic rename.
cp -- "$BK" "$TMP"
chmod 640 "$TMP"

# 5. Move the live database and ALL of its sidecars aside. Sidecars belong to
#    the old database; they must never sit next to the restored main file.
mv -- "$DB" "$OLD"
for suffix in -wal -shm -journal; do
    if [[ -e "$DB$suffix" ]]; then
        mv -- "$DB$suffix" "$OLD$suffix"
    fi
done
echo "restore: old database and sidecars moved to $OLD"

# 6. Atomically move the verified copy into place.
mv -- "$TMP" "$DB"
chmod 640 "$DB"

# 7. Check integrity AND the counts you expect from the backup output. A
#    missing expected table makes this exit non-zero before the app resumes.
"$PHP_BIN" -r '$p=new PDO("sqlite:".$argv[1]); $p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); $r=$p->query("PRAGMA integrity_check")->fetchColumn(); if ($r !== "ok") { fwrite(STDERR, "restore integrity_check: $r\n"); exit(1); } echo "integrity_check: ok\n"; foreach (["servers","metric_samples","alerts"] as $t) { echo $t, ": ", $p->query("SELECT COUNT(*) FROM ".$t)->fetchColumn(), PHP_EOL; }' "$DB"

# 8. Rebuild caches, leave maintenance mode, restart workers.
"$PHP_BIN" "$APP/artisan" optimize:clear
"$PHP_BIN" "$APP/artisan" config:cache
"$PHP_BIN" "$APP/artisan" route:cache
"$PHP_BIN" "$APP/artisan" view:cache
"$PHP_BIN" "$APP/artisan" up
# VPS only:
# sudo systemctl start noc-scheduler.timer noc-queue
# Shared hosting: uncomment the schedule:run cron line again.
```

If verification fails or the data is wrong, roll back before deleting `$OLD`.
Run this as its own block. Paste the exact `$OLD` path printed by the restore
block; the rollback refuses to continue without it and verifies it before
touching the live database:

```bash
set -euo pipefail
PHP_BIN="${PHP_BIN:-/usr/local/bin/php}"
APP=/home/ACCOUNT/noc/current
DB=/home/ACCOUNT/noc-data/noc.sqlite
OLD="${OLD:?paste the old database path printed by the restore block}"
FAILED="$DB.failed-restore-$(date -u +%Y%m%dT%H%M%SZ)-$$"
[[ -f "$OLD" ]] || { echo "rollback: old database not found: $OLD" >&2; exit 1; }
[[ ! -e "$FAILED" ]] || { echo "rollback: destination exists: $FAILED" >&2; exit 1; }
"$PHP_BIN" "$APP/artisan" down
mv -- "$DB" "$FAILED"
for suffix in -wal -shm -journal; do
    if [[ -e "$DB$suffix" ]]; then
        mv -- "$DB$suffix" "$FAILED$suffix"
    fi
done
mv -- "$OLD" "$DB"
for suffix in -wal -shm -journal; do
    if [[ -e "$OLD$suffix" ]]; then
        mv -- "$OLD$suffix" "$DB$suffix"
    fi
done
chmod 640 "$DB"
"$PHP_BIN" -r '$p=new PDO("sqlite:".$argv[1]); $p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); $r=$p->query("PRAGMA integrity_check")->fetchColumn(); if ($r !== "ok") { fwrite(STDERR, "rollback integrity_check: $r\n"); exit(1); } echo "rollback integrity_check: ok\n";' "$DB"
"$PHP_BIN" "$APP/artisan" optimize:clear
"$PHP_BIN" "$APP/artisan" up
# VPS: sudo systemctl start noc-scheduler.timer noc-queue
# Shared hosting: uncomment the schedule:run cron line again.
```

The rollback moves the failed restored database and its sidecars to
`$DB.failed-restore-…`, then restores the old main file with its `-wal`/`-shm`,
so pages that were only in the old WAL are not lost. Delete the failed copy
after the old data is accepted.

Restoring into a fresh SQLite file preserves server and sample counts (covered
by `tests/Feature/Ops/BackupTest.php`). The backup does **not** contain
`APP_KEY`. Use the same `APP_KEY` as when it was taken, or agent tokens
(HMAC-keyed) and encrypted secrets become invalid. If the key is lost:

- issue new agent tokens from each server's Token page;
- re-enter the Telegram bot token in `/settings/alerts`;
- reset two-factor for affected users so they can enroll again (Fortify's
  encrypted secret and recovery codes are unreadable) and purge their
  sessions:
  ```bash
  # one user
  php ~/noc/current/artisan tinker --execute="\$email = 'admin@example.com'; \Illuminate\Support\Facades\DB::table('users')->where('email', \$email)->update(['two_factor_secret' => null, 'two_factor_recovery_codes' => null, 'two_factor_confirmed_at' => null]);"
  # all users: omit the where('email', ...) clause
  php ~/noc/current/artisan tinker --execute="\Illuminate\Support\Facades\DB::table('sessions')->delete();"  # database sessions
  ```
  On a file session driver delete `~/noc/shared/storage/framework/sessions/*`;
  on Redis flush only the session keys or let them expire. These commands are
  not audited automatically; record the recovery in the audit log if required.

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
     php artisan test --filter='Ingest|Series|Alert|Prune'
   ```
   At minimum cover the ingest, series, alert, and retention suites. Do **not**
   run `BackupTest` against Postgres: `noc:backup` is SQLite-only (`VACUUM
   INTO`) and intentionally has no PostgreSQL path. Fix any driver-specific
   failure in the service layer, not with raw SQL in controllers.
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

## Acceptance checklist

Run this list against the release candidate. It is the spec §17.3/§17.4 sign-off;
anything that cannot run on the current host must be reported as a blocker, not
marked done.

### 1. Full test suite, assets, and dependency audit

```bash
./tools/test.sh --with-assets          # Pint, the full suite, then npm run build
./tools/php.sh php artisan test --filter=MvpAcceptanceTest
./tools/php.sh composer audit          # expect: no advisories
./tools/php.sh php artisan about
./tools/php.sh php artisan route:list
```

Expected: Pint clean; the acceptance test and the full suite pass; assets build
from a clean tree with a committed `public/build/manifest.json`; `composer audit`
reports no advisories; `about` shows the intended PHP/Laravel versions; every
route in `route:list` is present and no route is unexpectedly public.

### 2. Load check (100 virtual servers, 60 seconds)

Use a **scratch** SQLite database and fixture, never the development or
production database. `database/*.sqlite*` is git-ignored; still delete these
files after the run and never commit them.

```bash
touch database/load-acceptance.sqlite
./tools/php.sh env DB_DATABASE=database/load-acceptance.sqlite APP_ENV=local \
  php artisan migrate --force
./tools/php.sh env DB_DATABASE=database/load-acceptance.sqlite APP_ENV=local \
  php artisan noc:make-load-fixture --servers=100 \
  --path=database/load-acceptance.json
```

Relative paths work with both the native toolchain (repository root) and the
Podman fallback (mounted at `/app`, same working directory).

`noc:make-load-fixture` refuses `APP_ENV=production`, writes a mode-0600 JSON
file of `{name, token, payload}` entries, and reuses existing `load-NNN`
servers. The dashboard and the generator must share the same `DB_DATABASE` and
`APP_KEY` (agent tokens are keyed HMACs).

Start the app with published port 8000 (the `tools/php.sh` wrapper does not
publish ports). `--no-reload` is required so the child server inherits
`DB_DATABASE`; without it Laravel starts one server against the default
database. Then run the load tool:

```bash
podman run -d --rm --name noc-load-web --userns=keep-id \
  -v "$PWD:/app:Z" -w /app -e HOME=/tmp \
  -e DB_DATABASE=/app/database/load-acceptance.sqlite -e APP_ENV=local \
  -e PHP_CLI_SERVER_WORKERS=8 -p 8000:8000 \
  localhost/hosters-php:8.4 \
  php artisan serve --host=0.0.0.0 --port=8000 --no-reload
curl -fsS http://127.0.0.1:8000/up

# On a normal PHP host:
php tools/load-ingest.php \
  --url=http://127.0.0.1:8000/api/v1/ingest \
  --fixture=database/load-acceptance.json \
  --seconds=60 --concurrency=100 --log=storage/logs/laravel.log

# On this dev host (no host PHP): run inside the live container. Do not start a
# second container with another :Z mount of the same tree; the SELinux relabel
# can make the running server lose read access to /app.
podman exec -w /app noc-load-web php tools/load-ingest.php \
  --url=http://127.0.0.1:8000/api/v1/ingest \
  --fixture=/app/database/load-acceptance.json \
  --seconds=60 --concurrency=100 --log=/app/storage/logs/laravel.log

podman stop noc-load-web
rm -f database/load-acceptance.sqlite database/load-acceptance.sqlite-* \
      database/load-acceptance.json
```

Expected: exit 0; every response is `202` (a deliberate `--interval` below the
agent cadence may also show `429`); **zero** `database is locked` (responses and
new log lines); no 5xx; p95 ingest < 200 ms. By default the first wave is
phase-staggered across one interval, modelling agents installed at different
times; add `--no-stagger` for a 100-request burst probe. The tool exits non-zero
on a 5xx, a connection/TLS error, or any locked-database error.

### 3. Agent compatibility

Point the unmodified Bash agent at the local server, once:

```bash
TOKEN=$(python3 -c 'import json; print(json.load(open("database/load-acceptance.json"))[0]["token"])')
install -m 600 /dev/null /tmp/noc-agent-token
printf '%s\n' "$TOKEN" > /tmp/noc-agent-token
install -m 600 /dev/null /tmp/noc-agent.conf
cat > /tmp/noc-agent.conf <<'EOF'
API_URL=http://127.0.0.1:8000/api/v1/ingest
ALLOW_INSECURE_HTTP=1
SERVER_TOKEN_FILE=/tmp/noc-agent-token
STATE_DIR=/tmp/noc-agent-state
INTERVAL_SECONDS=60
PANEL=none
ENABLE_MYSQL=0
ENABLE_SMART=0
ENABLE_DISKQUOTA=0
ENABLE_IMUNIFY=0
EOF
mkdir -p /tmp/noc-agent-state /tmp/noc-agent-run
agent/collector.sh --config /tmp/noc-agent.conf \
  --state-dir /tmp/noc-agent-state --run-dir /tmp/noc-agent-run
echo "collector exit: $?"
```

Expected: exit 0, the endpoint answers `202 Accepted`, one `metric_samples` row
exists for that server, the dashboard shows it Online, and neither the token nor
the bot token appears in `storage/logs/laravel.log`, the agent output, or the
system journal. `ALLOW_INSECURE_HTTP=1` is for this loopback check only; never
set it in production. Remove `/tmp/noc-agent*` afterwards.

### 4. Security checklist

- `.env` and `*.sqlite` live **outside** `public/`; Nginx denies dotfiles and
  SQLite sidecars (`ops/vps/nginx.conf`).
- Every HTML/JSON response carries the CSP nonce policy and the
  `X-Content-Type-Options`, `Referrer-Policy`, and `Permissions-Policy` headers;
  production HTTPS adds HSTS.
- The agent token is stored only as an HMAC-SHA256 hash; plaintext is shown once
  on the reveal page (`Cache-Control: no-store`) and never in Livewire state, URLs,
  HTML after reveal, logs, notifications, audit rows, or the payload.
- The Telegram bot token is encrypted at rest with `APP_KEY`, write-only in the
  UI, and never appears in logs, exceptions, audit, or notification text.
- MySQL query text and `.info` values are admin-only and redacted in alert
  context; they are never logged.
- `APP_ENV=production` and `APP_DEBUG=false`; error pages are generic.
- Session cookies are `Secure` (under HTTPS), `HttpOnly`, and `SameSite=Lax`
  (`SESSION_SECURE_COOKIE=true`); `APP_KEY` rotation invalidates agent tokens and
  stored secrets (documented recovery below).
- Rate limits return `429` with `Retry-After`: login 5/min, password-reset
  request/update 3/min per IP, ingest 30/min per token plus 60 failed auth/min
  per IP, Telegram/email test 3/min.
- The last active administrator cannot be demoted, deactivated, or deleted.
- The audit log is append-only from the UI and every sensitive action (login,
  server/token changes, alert acknowledge/resolve, user/role/settings changes)
  is recorded.
- `composer audit` is clean and `composer.lock` is committed; backups leave the
  server encrypted and restore is rehearsed.

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
| Alerts are in-app only | Is the scheduler cron/timer running? `php artisan schedule:list`. Is `queue:work` finishing under 50 s? Check `~/noc/shared/storage/logs/laravel.log`. |
| Email `pending` forever | Queue worker not running, or `QUEUE_CONNECTION` points at a service that is down. Run `php artisan queue:work --stop-when-empty` once and read the error. |
| Email `failed` | `MAIL_*` values, outbound port, provider app password, `MAIL_FROM_ADDRESS` domain. Use **Send test email** and read the delivery row. |
| **Send test** still uses the old mailer | Production caches config: run `php artisan config:cache` after editing `.env` (or `php artisan optimize:clear` while troubleshooting). |
| Telegram `failed` | Bot token, chat ID (negative for groups), bot is a member/admin, forum `message_thread_id` belongs to that chat, outbound HTTPS to `api.telegram.org`. |
| Cron runs nothing / no error output | Use the absolute PHP 8.3+ CLI path in the cron line and redirect to a log file; `/dev/null` hides a wrong PHP binary or permission error. |
| Two workers on a VPS | Set `QUEUE_SCHEDULED_WORKER=false` in `.env` and rebuild the config cache; `noc-queue.service` owns the only long-running worker. |
| `database is locked` | Confirm `DB_JOURNAL_MODE=wal` and `DB_BUSY_TIMEOUT=5000`; keep the DB on local disk; do not run long manual queries during ingest. |
| Restore looks stale though `integrity_check` is `ok` | The old `-wal`/`-shm` sidecars were not removed. Restore again using the runbook: move the DB aside, delete sidecars, then rename the temp copy. |
| Deploy fails on manifest | `public/build/manifest.json` was not uploaded; build assets locally/CI and re-upload. Never run `npm run build` on the server. |
| `noc:backup` says destination exists | Backups never overwrite. Choose a new timestamped path or move the old copy. |
| Restored dashboard rejects agents | The backup was restored with a different `APP_KEY`; reissue tokens or restore the matching `.env`. |
| 500 after deploy | `php artisan optimize:clear`, check file permissions on `~/noc/shared/storage` and `bootstrap/cache/`, then `php artisan about`. |

See [`ops/shared-hosting/README.md`](ops/shared-hosting/README.md) for the
shared-hosting layout and rollback, and [`ops/vps/`](ops/vps/) for the Nginx
and systemd units.
