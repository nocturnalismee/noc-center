# VPS scheduling with cron

The systemd units in this directory are optional. For this application a
plain crontab is enough: the scheduler and the queue worker are both
short-lived commands, and the queue worker is designed to stop on its own.

Use one model only. Do **not** run the systemd units and these cron entries at
the same time, or the queue will be drained twice.

- **systemd:** `noc-scheduler.timer` + `noc-queue.service`
- **cron:** this document

## Prerequisites

Install the application at `/var/www/noc/current` (override every path below),
keep the SQLite database outside the release, and make the shared storage
writable by the PHP user:

```bash
sudo install -d -m 750 -o www-data -g www-data /var/www/noc/shared/storage/logs
```

Set the queue flag in the shared environment file **before** rebuilding the
config cache. The scheduler must not start its own worker when cron starts a
separate one:

```dotenv
# /var/www/noc/shared/.env
QUEUE_SCHEDULED_WORKER=false
```

```bash
cd /var/www/noc/current
sudo -u www-data /usr/bin/php8.4 artisan config:cache
```

Use the PHP 8.3+ CLI binary (`command -v php8.4` or `command -v php`). The
examples use `/usr/bin/php8.4`; replace it if your distribution uses another
path.

If you use Redis for `QUEUE_CONNECTION`, confirm it is running before enabling
the queue cron.

## Crontab

Edit the crontab of the user that owns the application files and the database
(usually `www-data`):

```bash
sudo crontab -u www-data -e
```

Add:

```cron
SHELL=/bin/bash
PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
MAILTO=""

# Scheduler: liveness, reminders, rollups, retention, backup checks.
* * * * * cd /var/www/noc/current && flock -n /tmp/noc-scheduler.lock /usr/bin/php8.4 artisan schedule:run >> /var/www/noc/shared/storage/logs/scheduler.log 2>&1

# Queue: email and Telegram delivery. It exits when empty or after 50 seconds.
* * * * * cd /var/www/noc/current && flock -n /tmp/noc-queue.lock /usr/bin/php8.4 artisan queue:work --stop-when-empty --max-time=50 --tries=3 --sleep=1 >> /var/www/noc/shared/storage/logs/queue.log 2>&1
```

Why these details:

- `flock -n` prevents two copies of the same job from starting when the
  previous minute is still running.
- `--max-time=50` leaves ten seconds before the next cron tick.
- `--stop-when-empty` makes the worker exit instead of waiting forever for a
  job; no supervisor is required.
- Redirect to a real file. `/dev/null` hides a wrong PHP binary, a permission
  error, or a failed migration.
- `cd` is required because cron starts in the user's home directory, not in the
  release directory.

Disable the systemd units first if they were enabled previously:

```bash
sudo systemctl disable --now noc-scheduler.timer noc-queue.service
```

## Verify

```bash
# The schedule is registered and points at the current release.
cd /var/www/noc/current
sudo -u www-data /usr/bin/php8.4 artisan schedule:list

# Run both jobs once, in the foreground, before waiting for a cron tick.
sudo -u www-data /usr/bin/php8.4 artisan schedule:run
sudo -u www-data /usr/bin/php8.4 artisan queue:work --stop-when-empty --max-time=50 --tries=3 --sleep=1

# Confirm cron is invoking the jobs and read the output.
grep CRON /var/log/syslog | tail -20
tail -n 50 /var/www/noc/shared/storage/logs/scheduler.log
tail -n 50 /var/www/noc/shared/storage/logs/queue.log
```

A working deployment shows a new `schedule:run` line every minute and no
`Could not open input file`, `Permission denied`, or `database is locked`
errors.

## Optional: daily backup

`ops/backup.sh` can also run from the same crontab. Keep the backup directory
outside the release and copy it off the server, encrypted:

```cron
15 3 * * * cd /var/www/noc/current && PHP_BIN=/usr/bin/php8.4 bash ops/backup.sh --dest /var/backups/noc --retention-days 14 --retention-count 30 >> /var/www/noc/shared/storage/logs/backup.log 2>&1
```

The script refuses to overwrite an existing backup and prunes old copies. A
local copy is not a full backup: copy it to encrypted off-server storage and
test a restore on a schedule.

## After a deploy

`deploy.sh` flips the `current` symlink atomically and calls
`queue:restart`; the next cron tick uses the new release automatically. No
systemd reload is needed. If you changed `.env`, run `config:cache` as shown
above before relying on the new values.

## Troubleshooting

| Symptom | Check |
| --- | --- |
| No alerts or rollups | `artisan schedule:list`; `grep CRON`; the scheduler log. |
| `Could not open input file` | Wrong `cd` path or wrong PHP binary in cron. |
| `Permission denied` on logs or SQLite | Run cron as the PHP user; check `750` directories and `640` files. |
| `database is locked` repeatedly | Confirm one scheduler/queue model is active; check heavy ingest and `DB_BUSY_TIMEOUT`. |
| Email/Telegram not sent | The queue cron is running, `QUEUE_CONNECTION` is reachable, and `MAIL_*`/Telegram settings were applied with `config:cache`. |
