# Shared-hosting deployment

This directory holds the cPanel/shared-hosting pieces for the NOC dashboard.
The full install, environment matrix, alert-channel, backup, and restore
runbooks live in [`../../README.md`](../../README.md).

## Layout

```
~/noc/
  releases/2026-11-24T120000Z/   uploaded release (app, vendor, public/build)
  shared/.env                    persistent environment file (chmod 600)
  current -> releases/...        atomic symlink; document root is current/public
```

- Upload a release (including `vendor/` or run Composer on the host) into
  `~/noc/releases/<timestamp>/`. There is **no Node/npm on the server**:
  `public/build/manifest.json` must be built locally/CI and uploaded with the
  release.
- On the first install, create `~/noc/shared/.env` from
  [`../../.env.example`](../../.env.example), set `APP_KEY`, and point
  `DB_DATABASE` at an absolute path **outside** `public/`
  (e.g. `~/noc-data/noc.sqlite`, file 640, directory 750).
- Point the domain's document root at `~/noc/current/public`.
- Add the one-line cron job from [`cron.example`](cron.example). The schedule
  also drains the queue each minute; no supervisor is needed.
- Run [`deploy.sh`](deploy.sh) for every release. It checks PHP >= 8.3 and the
  asset manifest, runs `migrate --force --isolated`, rebuilds the caches, and
  swaps `current` atomically. It never calls `sudo`.

## First install checklist

```bash
# on the server, once
mkdir -p ~/noc/releases ~/noc/shared ~/noc-data
install -m 600 /dev/null ~/noc/shared/.env
# edit ~/noc/shared/.env: APP_KEY, APP_URL, DB_DATABASE=/home/ACCOUNT/noc-data/noc.sqlite, MAIL_*
# generate APP_KEY before the first deploy:
php -r 'echo "base64:".base64_encode(random_bytes(32)).PHP_EOL;'

# upload the release into ~/noc/releases/<timestamp>/, then:
cd ~/noc/releases/<timestamp>
ops/shared-hosting/deploy.sh --app-root="$HOME/noc" --release-dir="$PWD"
php ~/noc/current/artisan noc:create-admin
```

Then open `/settings/alerts` to configure email/Telegram and use **Send test**.

## Rollback

Keep the previous release directory. Re-point the symlink and clear the caches:

```bash
ln -sfn ~/noc/releases/<previous> ~/noc/.current.rollback
mv -Tf ~/noc/.current.rollback ~/noc/current
php ~/noc/current/artisan optimize:clear
```

Migrations are forward-only; restore the database backup taken immediately
before the deploy if a migration must be undone.
