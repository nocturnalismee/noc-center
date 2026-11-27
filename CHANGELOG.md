# Changelog

All notable changes to NOC Center are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.2.0] - 2026-11-26

### Added

- `agent/update.sh`, a safe upgrade wrapper for installed agents.
- Built-in update bundle URL (`https://panel.developers.web.id/agent/download`) with `NOC_AGENT_UPDATE_URL` override.
- Automatic download and extraction of the latest agent bundle when no `--source` directory is supplied.
- Update backups under `/var/lib/noc-agent/updates/`, keeping the five newest snapshots.
- Automatic rollback when the new installer fails.
- `--check`, `--dry-run`, `--test`, `--source`, `--no-systemctl`, and `--no-prime` update options.
- The updater is installed as `/usr/local/libexec/noc-agent/update.sh`.

### Changed

- Agent updates preserve `/etc/noc-agent/agent.conf` and the server token.
- New agent installs support one-command updates from the installed updater.

### Documentation

- Added the manual update, bootstrap, backup, and rollback flow to `agent/README.md`.

## [1.1.0] - 2026-11-26

### Added

- Separate server Performance charts for CPU, memory, disk, load, and network in/out.
- Byte-based memory and disk charts with `KB`/`MB`/`GB`/`TB` formatting.
- Load and network charts on the server Overview tab.
- Exact byte sums in metric rollups, with a percentage-based fallback for older rollup rows.
- Unit-aware chart axes, tooltips, and colors.
- Reusable `series-chart` Blade component with loading, empty, error, retry, and no-JavaScript states.

### Fixed

- Desktop server table is now a named, keyboard-focusable scroll region with a caption.
- Chart series responses are no longer browser-cached.
- Charts refit correctly after Livewire morphs and realtime updates.
- Chart unit changes remount the chart instead of reusing stale axes.

## [1.0.5] - 2026-11-25

### Fixed

- Retention pruning runs at 03:30 WIB instead of 03:30 UTC.
- Percentage charts keep a visible scale and render a single collected sample.

## [1.0.4] - 2026-11-25

### Fixed

- Realtime refreshes update existing chart instances in place instead of destroying the canvas.

## [1.0.3] - 2026-11-25

### Fixed

- Reverb origin checks compare the request host correctly behind proxies and alternate ports.

## [1.0.2] - 2026-11-25

### Added

- Laravel Reverb realtime updates for the dashboard, server table, server detail, charts, alerts, and waiting page.
- Echo runtime configuration, private channels, WebSocket CSP support, and queued broadcast events.
- Operational-run broadcast status and deployment documentation for Reverb on a VPS.

### Fixed

- Realtime boot, origin, and summary refresh behavior.
- Dashboard polling assertions when realtime is disabled.
- Eloquent collection caching on the dashboard.

## [1.0.1] - 2026-11-25

### Added

- Operational health runs with scheduler heartbeat, rollup/prune status, and failed-job visibility.
- Structured ingest request logging with request IDs, durations, and redacted error context.
- Chart loading, empty, error, and retry states.
- Accessible sortable table headers with `aria-sort`.
- Cached dashboard alerts/activity and server table provider/count lookups.
- Dashboard alert, collection, and activity rails.
- Usage bars and provider column on the server table.

### Fixed

- Artisan boot failure from private `execute()` overrides.
- Rollup task-key uniqueness collision by tracking one row per task.
- Negative command durations and incorrect failure/success timestamps.
- Named-route URL expectations and ingest log test isolation.
- Trusted proxy client IPs persisting through config cache.
- Agent install path from `/root` and `APP_URL` scheme normalization.

## [1.0.0] - 2026-11-24

### Added

- Bash agent for Linux and cPanel hosts, installed with systemd timers.
- Core metrics: CPU, memory, disk, load, network, uptime, and process counts.
- Panel facts for cPanel, plus SMART, disk quota, Imunify360, and MySQL process-list modules.
- Laravel dashboard built with Blade, Livewire, Alpine, and Tailwind.
- SQLite storage with WAL, retention windows, rollups, and a PostgreSQL/TimescaleDB migration path.
- Administrator, operator, and viewer roles with optional TOTP two-factor authentication.
- Server registry, one-time token reveal, token rotation grace, revocation, and per-server alert settings.
- In-app alerts, SMTP email, and optional Telegram topics with acknowledgement, resolution, recovery, reminders, and delivery retries.
- Fleet overview, server detail and history, alert center, token management, settings, users, and append-only audit log.
- Deployment and recovery runbooks, backup/restore scripts, health checks, and acceptance tests.

[Unreleased]: https://github.com/nocturnalismee/noc-center/compare/v1.2.0...HEAD
[1.2.0]: https://github.com/nocturnalismee/noc-center/compare/v1.1.0...v1.2.0
[1.1.0]: https://github.com/nocturnalismee/noc-center/compare/v1.0.5...v1.1.0
[1.0.5]: https://github.com/nocturnalismee/noc-center/compare/v1.0.4...v1.0.5
[1.0.4]: https://github.com/nocturnalismee/noc-center/compare/v1.0.3...v1.0.4
[1.0.3]: https://github.com/nocturnalismee/noc-center/compare/v1.0.2...v1.0.3
[1.0.2]: https://github.com/nocturnalismee/noc-center/compare/v1.0.1...v1.0.2
[1.0.1]: https://github.com/nocturnalismee/noc-center/compare/v1.0.0...v1.0.1
[1.0.0]: https://github.com/nocturnalismee/noc-center/releases/tag/v1.0.0