# Shared-hosting deployment

This directory holds the cPanel/shared-hosting pieces for the NOC dashboard.
The full install, environment matrix, alert-channel, backup, and restore
runbooks live in [`../../README.md`](../../README.md).

## Layout

```
~/noc/
  releases/2026-11-24T120000Z/   uploaded release (app, vendor, public/build)
  shared/.env                    persistent environment (chmod 600, authoritative)
  shared/storage/                persistent sessions/cache/logs/uploads
  shared/storage/logs/           scheduler and application logs
  current -> releases/...        atomic symlink; document root is current/public
~/noc-data/noc.sqlite            database, mode 640, outside public/
~/noc-backups/                   timestamped backups and backup logs
```

`deploy.sh` creates `shared/storage`, copies any release-local `storage` tree
into it on first conversion, and replaces each release's `storage` with a
symlink. File sessions, cache, logs, and public uploads therefore survive a
release swap. Keep `shared/storage` writable by the PHP user (750 directories,
640 files).

## Prerequisites (once)

1. **PHP 8.3+ CLI** and FPM. Find the absolute CLI path with `command -v php`
   (shared hosts often use `/usr/local/bin/php` or an `/opt/cpanel/.../php`
   path, not the web PHP). Record it for cron.
2. **Create directories, the database file, and the backup directory.** The
   empty SQLite file is valid; Laravel refuses to connect if it is missing.
   ```bash
   mkdir -p ~/noc/releases ~/noc/shared/storage/logs ~/noc-data ~/noc-backups
   chmod 750 ~/noc-data ~/noc-backups ~/noc/shared/storage
   install -m 640 /dev/null ~/noc-data/noc.sqlite
   ```
3. **Create `~/noc/shared/.env`** from
   [`../../.env.example`](../../.env.example), set `APP_KEY`, `APP_URL`,
   `DB_DATABASE=/home/ACCOUNT/noc-data/noc.sqlite`,
   `QUEUE_SCHEDULED_WORKER=true`, and `MAIL_*`. Generate the key before the
   first deploy:
   ```bash
   install -m 600 /dev/null ~/noc/shared/.env
   php -r 'echo "base64:".base64_encode(random_bytes(32)).PHP_EOL;'
   ```
   `shared/.env` is authoritative: `deploy.sh` symlinks it into every release
   and it replaces a release-local `.env`.
4. **Upload the release** into `~/noc/releases/<timestamp>/` with
   `public/build/manifest.json`. There is **no Node/npm on the server**; build
   assets locally/CI and upload them.
5. **Install dependencies** if `vendor/` was not uploaded:
   ```bash
   cd ~/noc/releases/<timestamp>
   composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader
   ```

## First deploy

```bash
cd ~/noc/releases/<timestamp>
ops/shared-hosting/deploy.sh --app-root="$HOME/noc" --release-dir="$PWD"
php ~/noc/current/artisan noc:create-admin
```

Then:

- Point the domain's document root at `~/noc/current/public`.
- Install the one-line cron job from [`cron.example`](cron.example) using the
  **absolute** PHP CLI path. It logs to
  `~/noc/shared/storage/logs/scheduler.log` (do not send it to `/dev/null`
  while installing; a wrong PHP path becomes invisible). The schedule also
  drains the queue each minute; no supervisor is needed.
- Open `/settings/alerts` to configure email/Telegram and use **Send test**.
  After editing `MAIL_*` in `.env`, run `php ~/noc/current/artisan
  config:cache` first (production caches configuration).

## Updates

Upload a new release and run `deploy.sh` with `--release-dir`. The script
checks PHP >= 8.3 and the asset manifest, runs `migrate --force --isolated`,
rebuilds the caches, and swaps `current` atomically. It never calls `sudo`.
When `flock` is available it also takes an exclusive lock on
`$APP_ROOT/.deploy.lock`, so concurrent deploys cannot race.

`--isolated` uses the configured cache lock. With the shared storage symlink
and `CACHE_STORE=file`, all releases share one file cache and the lock spans
releases. A release with a private cache (before first conversion) or a
different cache store only serialises itself, which is why the host-level
`flock` remains the outer guard.

## Rollback

Keep the previous release directory. Re-point the symlink, clear the caches,
and restart workers:

```bash
ln -sfn ~/noc/releases/<previous> ~/noc/.current.rollback
mv -Tf ~/noc/.current.rollback ~/noc/current
php ~/noc/current/artisan optimize:clear
php ~/noc/current/artisan queue:restart
```

The previous release still points its `storage` symlink at `shared/storage`,
so sessions and logs survive the rollback. Migrations are forward-only;
restore the database backup taken immediately before the deploy if a migration
must be undone.
