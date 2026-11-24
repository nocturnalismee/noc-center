# NOC monitoring agent

A lightweight Bash collector for monitoring Linux servers from a central NOC
dashboard. Every interval it collects universal system metrics, adds
panel-specific metrics (cPanel today), reads cached output of heavier system
modules (SMART, disk quota, Imunify360) plus the live MySQL process list, builds
one JSON payload (schema v1), and posts it over HTTPS.

It runs from systemd timers, needs no daemon, no build step, and no language
runtime for the core. The only optional runtime dependency is `python3`, used
by the Imunify module when present.

- Works on **non-panel servers** and on **cPanel** servers.
- Other panels (Plesk, DirectAdmin, CyberPanel, aaPanel) are supported by the
  plugin *contract* only; their plugins are not implemented yet.
- **RAID is not monitored** (neither software `mdadm` nor hardware
  controllers), and SMART data behind hardware RAID controllers (Dell PERC,
  LSI, HP Smart Array) is out of scope for v1.

> Not to be confused with the legacy root-level `collector-cpanel.sh`. That is
> an older, standalone cPanel/cron collector. The agent lives entirely under
> `agent/` and is installed by `agent/install.sh` (see the repository
> [`README.md`](../README.md)).

---

## 1. Architecture

```text
systemd timer (default every 60s)
        │
        ▼
agent/collector.sh  (main mode, root)
  ├─ loads /etc/noc-agent/agent.conf + token        (secrets, root-only)
  ├─ takes the lock /run/noc-agent/noc-agent.lock   (flock -n)
  ├─ collects universal metrics (/proc, df, ps, uname)
  ├─ selects exactly ONE panel plugin → runs its `collect`
  ├─ runs inline system modules (mysql)
  ├─ reads external-module caches: smart, diskquota, imunify
  ├─ builds the schema-v1 JSON payload (≤ 64 KiB, with fallback)
  └─ HTTPS POST with Authorization: Bearer <token>
        │
        │  separate timer per external module
        ▼
systemd noc-agent@{smart,diskquota,imunify}.service
  └─ collector.sh --module <name>
       └─ validates the module output, writes $STATE_DIR/cache/<name>.metrics
          atomically (mode 600); the main run only reads that cache
```

Heavy checks are **never** run every minute:

| Module | Mode | When it runs | Cache file |
| --- | --- | --- | --- |
| `mysql` | inline | every main run | — |
| `smart` | external | monthly timer; primed at install | `cache/smart.metrics` |
| `diskquota` | external | daily timer; primed at install | `cache/diskquota.metrics` |
| `imunify` | external | every 5 minutes; primed at install | `cache/imunify.metrics` |

### Source layout

| Path | Purpose |
| --- | --- |
| `agent/collector.sh` | Core: CLI, config, core metrics, panel/module loader, payload, HTTP, fallback, exit codes. |
| `agent/lib/common.sh` | Config defaults/validation, secure-file checks, token loading, agent ID, lock. |
| `agent/lib/json.sh` | Pure-Bash JSON encoder and typed metric-line validator. |
| `agent/lib/install.sh` | Shared helpers for the installer/uninstaller (paths, dry-run-aware actions). |
| `agent/panels/cpanel.sh` | cPanel panel plugin (the first and only panel plugin). |
| `agent/panels/_template.sh`, `agent/panels/README.md` | Plugin contract and starting point. |
| `agent/modules/{mysql,smart,diskquota,imunify}.sh` | System modules. |
| `agent/modules/_template.sh`, `agent/modules/README.md` | Module contract and starting point. |
| `agent/systemd/` | Service, module template, and four timer units. |
| `agent/config/agent.conf.example` | Fully commented config with every key and its default. |
| `agent/install.sh`, `agent/uninstall.sh` | Installer and uninstaller. |
| `tools/mock-ingest.py` | Loopback mock of the ingest endpoint (testing only). |
| `tools/test-agent.sh`, `tools/tests/` | One-command test runner and the test suites. |

---

## 2. Requirements

On the **monitored server**:

- Linux with **systemd** and **Bash 4+**.
- Root to install and to run (panel and module commands need root). Non-root
  runs are supported only for `--dry-run` and tests.
- Required commands: `bash`, `curl`, `flock`, `install` (coreutils), plus the
  usual GNU utilities (`awk`, `sed`, `grep`, `ps`, `df`, `uname`, `timeout`,
  `stat`, `realpath`).
- Outbound **HTTPS** to the dashboard ingest endpoint. No inbound port, no SSH
  from the dashboard.
- Optional, per module (the module is skipped when its tool is missing):
  - `smartctl` + `lsblk` for SMART (usually the `smartmontools` package);
  - `repquota` for disk quota (quota tools);
  - `mysql` / `mysqladmin` for the MySQL process list, able to authenticate
    non-interactively, i.e. with credentials in a MySQL client defaults file
    such as `/root/.my.cnf` (for the MySQL client, `~/.my.cnf`); the agent never
    passes credentials on the command line;
  - `imunify360-agent` **and** `python3` for Imunify360 scan status. This is the
    only place `python3` is used — the core agent never needs it.
- On SELinux-enabled hosts, `restorecon` is used by the installer when present
  (best effort). cPanel servers usually run SELinux permissive/disabled.

On the **dashboard side** you need an ingest endpoint that accepts
`POST /api/v1/ingest` (see §9) and a per-server token. `tools/mock-ingest.py`
lets you test the whole path locally first.

---

## 3. Create the token

Tokens are created on the dashboard, one per server. They are **not** generated
by the agent. The accepted format is `^[A-Za-z0-9._~-]{16,256}$` (16–256
characters from that set). Use a long random value, e.g.:

```bash
python3 -c 'import secrets; print(secrets.token_urlsafe(32))'
```

Put it in a root-only file and hand that file to the installer (never pass the
token on the command line):

```bash
install -m 600 /dev/null /root/noc-token
printf '%s\n' 'THE-TOKEN-FROM-THE-DASHBOARD' > /root/noc-token
chmod 600 /root/noc-token
```

The installer copies it to `/etc/noc-agent/token` (mode 600). You can also
create that file by hand later:

```bash
install -d -m 700 /etc/noc-agent
install -m 600 /root/noc-token /etc/noc-agent/token
```

---

## 4. Install

The installer is idempotent: re-running it never overwrites an existing config
or token unless you pass `--force`.

### Install from the dashboard (recommended)

Every token reveal page shows this flow. The dashboard builds the bundle
from its own `agent/` directory; no repository checkout is needed on the
monitored server.

```bash
curl -fsSL https://monitor.example.com/agent/download -o /tmp/noc-agent.tar.gz
tar -xzpf /tmp/noc-agent.tar.gz -C /tmp

install -m 600 /dev/null /root/noc-token
read -rsp "Token: " token && printf '%s' "$token" > /root/noc-token

/tmp/noc-agent/install.sh --api-url https://monitor.example.com/api/v1/ingest \
  --token-file /root/noc-token
```

`APP_URL` on the dashboard must be the public URL that monitored servers can
reach. Downloading the bundle manually is also supported:
`https://monitor.example.com/agent/download` returns `noc-agent.tar.gz`.

### Install from a source checkout

Run the installer from the agent source directory as root:

```bash
# minimal install: config + token + units + timers, start timers, prime caches
agent/install.sh --api-url https://monitor.example.com/api/v1/ingest \
  --token-file /root/noc-token

# custom interval (seconds, 10–3600) and an extra validation run afterwards
agent/install.sh --api-url https://monitor.example.com/api/v1/ingest \
  --token-file /root/noc-token --interval 120 --test

# preview every action without changing anything
agent/install.sh --api-url https://monitor.example.com/api/v1/ingest \
  --token-file /root/noc-token --dry-run

# automated / container / test install into a scratch prefix
agent/install.sh --prefix /tmp/noc-prefix \
  --api-url https://monitor.example.com/api/v1/ingest \
  --token-file /root/noc-token --no-systemctl --no-prime
```

What it installs:

| Path | Mode | Contents |
| --- | --- | --- |
| `/usr/local/libexec/noc-agent/` | 755 | `collector.sh`, `lib/`, `panels/`, `modules/` (files mode 700) |
| `/etc/noc-agent/agent.conf` | 600 | Config (created from the example; kept if it exists) |
| `/etc/noc-agent/token` | 600 | Server token (kept if it exists) |
| `/var/lib/noc-agent/` | 700 | Agent ID and module cache |
| `/var/lib/noc-agent/cache/` | 700 | `<module>.metrics` files, mode 600 |
| `/etc/systemd/system/noc-agent{,-sm,-diskquota,-imunify}.*` | 644 | Services and timers |

Steps performed: dependency check → directories → scripts → units (the main
service and module template are rendered with the install prefix, the main
timer with `INTERVAL_SECONDS`) → config (only if absent) → token → `restorecon`
(if available) → `systemctl daemon-reload` → `enable --now` the four timers →
prime the three external-module caches in the background (prime failures only
warn; the timers retry later) → with `--test`, one `--dry-run` of the installed
collector (validates config, no token, no HTTP) and a `systemctl start
noc-agent.service`.

Installer options:

| Option | Meaning |
| --- | --- |
| `--api-url URL` | Ingest URL for a newly created config. Must be `https://` (or `http://127.0.0.1`/`http://localhost` for local tests). |
| `--token-file PATH` | Read the token from a regular, non-symlink file. |
| `--interval SECONDS` | Main interval, 10–3600 (default 60). |
| `--prefix DIR` | Install everything under `DIR` (default `/`). Required for non-root runs. |
| `--dry-run` | Print every planned action; change nothing. |
| `--no-systemctl` | Never call `systemctl` (containers, prefixes, tests). |
| `--no-prime` | Do not start the external-module units after install. |
| `--force` | Replace an existing config (and token). |
| `--test` | Dry-run the installed collector, then start one real run. |
| `--help` | Show usage. |

A prefixed non-root install automatically behaves like `--no-systemctl`
(a scratch install cannot manage the host's systemd). `http://` URLs are
accepted only for `127.0.0.1`/`localhost`, and the generated config then also
sets `ALLOW_INSECURE_HTTP=1`.

---

## 5. Timers and schedules

| Unit | Schedule | Purpose |
| --- | --- | --- |
| `noc-agent.timer` → `noc-agent.service` | `OnBootSec=30s`, then every `INTERVAL_SECONDS` (default 60s), `AccuracySec=1s` | Main collection run |
| `noc-agent-smart.timer` → `noc-agent@smart.service` | Monthly: `OnCalendar=*-*-01 03:15:00`, random delay ≤ 15m | SMART health cache |
| `noc-agent-diskquota.timer` → `noc-agent@diskquota.service` | Daily: `OnCalendar=*-*-* 03:45:00`, random delay ≤ 10m | Disk quota cache |
| `noc-agent-imunify.timer` → `noc-agent@imunify.service` | `OnBootSec=2m`, then every 5m, `AccuracySec=30s` | Imunify360 scan-status cache |

Inspect them with:

```bash
systemctl list-timers 'noc-agent*'
systemctl status noc-agent.timer
systemctl cat noc-agent.timer               # includes the rendered OnUnitActiveSec
journalctl -u noc-agent.service -n 50 --no-pager
journalctl -u 'noc-agent@*' --since today --no-pager

# run one collection (or one module) by hand, exactly as the timer does
systemctl start noc-agent.service
systemctl start noc-agent@smart.service     # / @diskquota / @imunify
```

Changing `INTERVAL_SECONDS`: edit `/etc/noc-agent/agent.conf`, then re-run
`agent/install.sh` (it re-renders the timer without touching existing config or
token) or edit `/etc/systemd/system/noc-agent.timer` and run
`systemctl daemon-reload && systemctl restart noc-agent.timer`.

---

## 6. Run a safe dry run

`--dry-run` builds the payload, prints it to stdout, and performs **no** token
read, **no** HTTP request, and **no** lock. It is safe on a live server:

```bash
/usr/local/libexec/noc-agent/collector.sh --dry-run \
  --config /etc/noc-agent/agent.conf | python3 -m json.tool

# validate a single module's output without writing its cache
/usr/local/libexec/noc-agent/collector.sh --module smart --dry-run \
  --config /etc/noc-agent/agent.conf

# check one run's output on a scratch config (as any user)
agent/collector.sh --dry-run --config /tmp/test-agent.conf
```

Useful collector options:

| Option | Meaning |
| --- | --- |
| `--config PATH` | Config file (default `/etc/noc-agent/agent.conf`). |
| `--state-dir PATH` | Override `STATE_DIR` (agent ID and cache). |
| `--run-dir PATH` | Override the runtime dir for the lock and curl config (default `/run/noc-agent`). |
| `--module NAME` | Run one module and atomically refresh its cache; no HTTP, no token. |
| `--dry-run` | Print the payload (or the module's metric lines) without side effects. |
| `--panel NAME` | Force `auto`, `none`, or a plugin name (e.g. `cpanel`). |
| `--panel-dir PATH`, `--module-dir PATH` | Scan alternative plugin/module directories. |
| `--max-payload-bytes N` | Override the 65536-byte payload limit. |
| `--help`, `--version` | Usage and version. |

---

## 7. Configuration

The config is a Bash file sourced by the collector. It must be owned by the
running user and not group/world-writable (the installer creates it mode 600).
Unknown keys are ignored; invalid values make the run exit 1 with a
`config error` message. The full annotated template is
[`agent/config/agent.conf.example`](config/agent.conf.example).

| Key | Default | Range / values | Notes |
| --- | --- | --- | --- |
| `API_URL` | `https://monitor.example.com/api/v1/ingest` | `https://…` | `http://127.0.0.1`/`localhost` only with `ALLOW_INSECURE_HTTP=1`. |
| `SERVER_TOKEN_FILE` | `/etc/noc-agent/token` | path | Root-owned, mode 600, one token. |
| `INTERVAL_SECONDS` | `60` | 10–3600 | Must match the timer (see §5). |
| `PANEL` | `auto` | `auto`, `none`, or a plugin name | `auto` detects; `none` forces non-panel. |
| `PANEL_DETECT_TIMEOUT` | `2` | 1–5 s | Per-plugin `detect` timeout. |
| `PANEL_TIMEOUT` | `10` | 3–20 s | Panel `collect` timeout. |
| `CURL_TIMEOUT` | `8` | 3–15 s | Per HTTP attempt. |
| `MAX_RUN_SECONDS` | `45` | 20–45 s | Whole-run budget; below the unit's `TimeoutStartSec=50`. |
| `STATE_DIR` | `/var/lib/noc-agent` | path | Agent ID and `cache/`. |
| `TLS_VERIFY` | `1` | 0/1 | Keep 1 in production. |
| `ALLOW_INSECURE_HTTP` | `0` | 0/1 | Local testing only. |
| `DEBUG` | `0` | 0/1 | With 1, panel stderr is echoed to the journal on failure. |
| `ENABLE_MYSQL` | `1` | 0/1 | `0` disables the module (not run, cache not sent). |
| `ENABLE_SMART` | `1` | 0/1 | |
| `ENABLE_DISKQUOTA` | `1` | 0/1 | |
| `ENABLE_IMUNIFY` | `1` | 0/1 | |
| `MODULE_DETECT_TIMEOUT` | `2` | 1–5 s | |
| `MODULE_TIMEOUT` | `5` | 1–15 s | Inline module budget. |
| `HEAVY_MODULE_TIMEOUT` | `240` | 30–600 s | `--module` budget; below `TimeoutStartSec=300`. |
| `QUOTA_TOP_N` | `15` | 1–50 | How many users are reported by disk quota. |
| `MYSQL_MAX_QUERIES` | `10` | 0–50 | 0 disables the process list. |
| `MYSQL_MIN_SECONDS` | `5` | 1–3600 | Only queries running at least this long are reported. |
| `MYSQL_INCLUDE_QUERY_TEXT` | `1` | 0/1 | Set `0` if query text is sensitive. |
| `SMART_MAX_DISKS` | `32` | 1–64 | |

`ENABLE_<MODULE>=0` disables a module completely. When `python3`, `smartctl`,
`repquota`, or `imunify360-agent` is missing, the affected module simply does
not run; the core payload is unaffected. In `--module` mode a disabled or
not-applicable module logs `skipped` and exits `0` without touching its cache,
so an optional module's timer does not report a failed unit on every run.

---

## 8. Cache locations and cache-age fields

| Path | Contents |
| --- | --- |
| `/var/lib/noc-agent/agent-id` | Stable diagnostic agent ID, created on first run (not an auth secret). |
| `/var/lib/noc-agent/cache/<module>.metrics` | External-module output, written atomically by the collector (mode 600, 700 dir). |
| `/run/noc-agent/noc-agent.lock` | Run lock (one collection at a time). |
| `/run/noc-agent/noc-agent.curl.<pid>.conf` | Short-lived mode-600 curl config holding the token; removed on every exit path. |
| `/tmp/noc-agent.*` | Private (0600) temp files for panel/module output and the payload; removed on exit. |

Each external module contributes its cached metrics **plus**
`<module>.cache_age_seconds`, the age of the cache file in seconds, so the
dashboard can flag stale data:

- `smart.cache_age_seconds`, `diskquota.cache_age_seconds`,
  `imunify.cache_age_seconds`.
- A module that has **never** succeeded contributes no fields at all (which
  means "no data yet", not zero). A stale cache keeps being sent with a growing
  age until the module succeeds again; a failed module run preserves the
  previous cache.
- Module output also carries the module's own `<module>.updated_at` (epoch
  seconds) written at collection time.

Useful checks:

```bash
ls -l /var/lib/noc-agent/cache/
stat -c '%n %y %a' /var/lib/noc-agent/cache/*.metrics
```

---

## 9. API contract

```http
POST {API_URL}                                  (default /api/v1/ingest)
Authorization: Bearer <server-token>
Content-Type: application/json
Accept: application/json
User-Agent: noc-agent/0.1.0
X-Noc-Agent-Version: 0.1.0

<body: schema-v1 JSON, at most 64 KiB>
```

The token is sent only in the header, from a temporary mode-600 curl file (it
never appears in `argv`, `ps`, URLs, logs, payloads, or error messages).

Responses:

| Response | Agent behaviour |
| --- | --- |
| `200`, `202`, `204` | Success (exit 0). |
| `400`, `401`, `403`, `413`, `422`, other 4xx | Rejected; **no retry**; exit 3. Logged status only, never the token. |
| `429`, `5xx`, network/TLS failure | Retry; up to 3 attempts, backoff 1s then 3s, each attempt bounded by `CURL_TIMEOUT`, all bounded by `MAX_RUN_SECONDS`; then exit 3. |

The response body is never parsed or executed.

Payload (schema v1), abridged:

```json
{
  "schema_version": 1,
  "agent": {
    "version": "0.1.0", "id": "<uuid>", "hostname": "web01",
    "panel": "cpanel", "collected_at": "2026-11-22T12:00:00Z",
    "interval_seconds": 60
  },
  "system": { "os": { "name": "AlmaLinux", "version": "9.4", "kernel": "5.14.0" },
              "uptime_seconds": 123456 },
  "metrics": {
    "cpu": { "usage_pct": 12.5, "iowait_pct": 0.3, "load1": 0.4, "load5": 0.3,
             "load15": 0.2, "cores": 8 },
    "memory": { "total_bytes": 0, "used_bytes": 0, "used_pct": 0 },
    "swap": { "total_bytes": 0, "used_bytes": 0, "used_pct": 0 },
    "disk": { "root": { "total_bytes": 0, "used_bytes": 0, "used_pct": 0,
                        "inode_pct": 0 } },
    "network": { "rx_bytes_per_sec": 0, "tx_bytes_per_sec": 0 },
    "processes": { "running": 3, "blocked": 0 }
  },
  "panel_metrics": { "cpanel.version": "120.0.1", "service.apache.running": true },
  "extensions": { "smart.status": "ok", "smart.disks": 2,
                  "smart.cache_age_seconds": 86400 }
}
```

- Every metric field above is always present; unavailable values are `0`, so
  the schema is stable.
- `panel_metrics` and `extensions` are always present (possibly `{}`) and are
  **flat** maps of string/number/boolean values.
- If the full payload exceeds 64 KiB, all of `panel_metrics` is dropped first,
  then all of `extensions`; if the core alone is still too large the run fails
  with exit 2.

Panel-specific fields (cPanel): `cpanel.version`, `cpanel.accounts`,
`cpanel.mailq`, `cpanel.mysql_ping`, `cpanel.webserver`, `cpanel.cagefs`,
`security.csf_enabled`, `security.csf_testing`, `security.modsec`, and
`service.<name>.{installed,enabled,running}`.

Module fields:

| Module | Fields |
| --- | --- |
| MySQL | `mysql.available`, `mysql.threads_running`, `mysql.long_queries`, `mysql.updated_at`, `mysql.query.<i>.{user,db,state,info,time}` |
| SMART | `smart.updated_at`, `smart.disks`, `smart.bad_count`, `smart.status` (`ok`/`warning`/`failed`/`unknown`), `smart.detail`, `smart.disk.<dev>.{health,realloc,pending,power_on_hours,written_gb}`, `smart.cache_age_seconds` |
| Disk quota | `diskquota.updated_at`, `diskquota.accounts`, `diskquota.over`, `diskquota.top.<user>.used_gb`, `diskquota.cache_age_seconds` |
| Imunify | `imunify.updated_at`, `imunify.scanning`, `imunify.age_s`, `imunify.files`, `imunify.target`, `imunify.cache_age_seconds` |

For SMART, `power_on_hours` and `written_gb` are `0` when the drive does not
report them. `written_gb` is lifetime bytes written (NVMe `Data Units Written`
× 512000 bytes; ATA `Total_LBAs_Written` × 512 bytes as a best-effort
estimate), not the manufacturer's TBW endurance rating.

### Exit codes

| Code | Meaning |
| --- | --- |
| `0` | Success, or a run skipped because another run holds the lock. |
| `1` | Invalid config, token, or state/run directory (local error). |
| `2` | The core payload alone exceeds the size limit. |
| `3` | Delivery failed (retries exhausted, network/TLS error, or a non-retryable rejection). |
| `4` | `--module` mode: module missing/insecure, `detect` error (exit >1), or collection/validation failed; the previous cache is preserved. Disabled/not-applicable modules exit `0` and write no cache. |

Plugin and inline-module failures never change the exit code; they only log a
warning and omit those metrics.

---

## 10. Security model

- **One secret.** The per-server token is the only authorization value. It is
  read only from `SERVER_TOKEN_FILE` (never from `argv`), passed to curl only via
  a mode-600 config file in the run dir, and never logged, printed, or put in
  the payload. Agent ID is diagnostic, not authentication.
- **Secure files.** Config, token, state, cache, and run paths must be owned by
  the effective UID, must not be symlinks, and must not be group/world
  writable. The token file must not be accessible by group/others at all.
  Anything else is a hard error (exit 1). Cache files are read only when they
  are regular, non-symlink files under `STATE_DIR`.
- **Transport.** HTTPS only, TLS ≥ 1.2, certificate verification on
  (`TLS_VERIFY=1`); plain HTTP is accepted only for loopback in tests.
  `curl --proto` restricts the scheme.
- **Read-only.** The agent never edits configs, never restarts or disables
  services, and never touches the firewall or packages. Panel and module
  commands are read-only (`smartctl -H -A`, `repquota`, MySQL
  `PROCESSLIST`/status, Imunify `list --json`, cPanel read-only queries).
- **Bounded execution.** Every external command runs under `timeout`; panel,
  module, HTTP, and whole-run budgets are configurable and validated.
- **Strict output handling.** Panel/module output is parsed as typed
  `key<TAB>type<TAB>value` lines (validated keys, numbers, booleans, strings;
  duplicates and malformed lines dropped; module lines must carry the module
  prefix). Nothing from external output is ever `eval`ed.
- **systemd hardening.** Units run `Type=oneshot` as root with
  `NoNewPrivileges=yes`, `ProtectSystem=full`, `ProtectHome=read-only`,
  `PrivateTmp=yes`, `ProtectKernelTunables=yes`,
  `ProtectKernelModules=yes`, `ProtectControlGroups=yes`,
  `RestrictSUIDSGID=yes`, `LockPersonality=yes`,
  `RestrictAddressFamilies=AF_INET AF_INET6 AF_UNIX`,
  `SystemCallArchitectures=native`, plus `StateDirectory`/`RuntimeDirectory`
  mode 0700 and `UMask=0077`. `ProtectSystem=full` (not `strict`) keeps panel
  CLIs able to write their own logs under `/var`; if a specific CLI needs more,
  add a narrow `ReadWritePaths=` rather than weakening the whole unit.
- **SELinux.** If `restorecon` is available, the installer labels
  `/usr/local/libexec/noc-agent`, `/etc/noc-agent`, and `/var/lib/noc-agent`;
  failures are warnings with a `semanage fcontext` hint and never disable
  SELinux.
- **MySQL query text** may contain sensitive data: it is truncated to 150
  characters, flattened to one line, never logged, and can be turned off with
  `MYSQL_INCLUDE_QUERY_TEXT=0`.
- **SMART can spin up sleeping disks.** That is why SMART runs monthly, not
  every minute; keep `smartd` for real-time disk alerts.

---

## 11. Panel plugin and module contracts

Both contracts are covered in detail in
[`agent/panels/README.md`](panels/README.md) and
[`agent/modules/README.md`](modules/README.md). In short:

**Panel plugin** (`agent/panels/<name>.sh`, exactly one per run):

```text
<name>.sh detect    exit 0 = this panel is present, 1 = not, >1 = error (skip)
<name>.sh collect   stdout = key<TAB>type<TAB>value lines; exit 0 = ok
```

`PANEL=auto` runs `detect` under `PANEL_DETECT_TIMEOUT` in sorted order and
picks the first match; `PANEL=<name>` skips detection and fails with exit 1 if
the plugin is missing or insecure; `PANEL=none` sends no panel metrics. A
failing `collect` only omits `panel_metrics`. Plugins are read-only and must
wrap their commands in `timeout`. `_template.sh` is never executed.

**System module** (`agent/modules/<name>.sh`, all applicable modules run):

```text
<name>.sh detect    exit 0 = applicable
<name>.sh mode      print "inline" or "external"
<name>.sh collect   stdout = key<TAB>type<TAB>value lines, keys must start "<name>."
```

Inline modules run on every main run under `MODULE_TIMEOUT`; external modules
are run by `collector.sh --module <name>` under a separate timer and cached.
An external module must emit `<name>.updated_at` on every successful collect.
The core — never the module — writes the cache atomically. Add a new plugin or
module by dropping a file into the directory; the core needs no changes.

---

## 12. Testing

Everything runs locally without touching the host's systemd or config:

```bash
bash tools/test-agent.sh
```

This runs, in order: `json`, `common`, `core`, `panel`, `module`, `smart`,
`diskquota`, `imunify`, `http`, `payload`, and `install` suites, then
`bash -n` on every `agent/**/*.sh` and `tools/**/*.sh`, then ShellCheck when
available (`SHELLCHECK=/path` or `shellcheck` on `PATH`; skipped otherwise;
SC2034/SC1091 false positives are exempted — every other warning fails),
then the acceptance dry run (non-panel `--dry-run` payload validated with
`python3 -m json.tool`), then a mock end-to-end check (install into a scratch
prefix, post to `tools/mock-ingest.py`, expect `202` and a stored payload; then
a bad token → exactly one `401`, exit 3, no token in the logs). It prints
`test-agent: ALL PASS` only when everything passes.

Individual suites, and a manual mock:

```bash
bash tools/tests/http_test.sh
python3 tools/mock-ingest.py --token "$(printf 'test%.0s' {1..5})" --port 8787 \
  --log /tmp/req.log --dump-dir /tmp/dumps
# then point a scratch config's API_URL at http://127.0.0.1:8787/api/v1/ingest
# with ALLOW_INSECURE_HTTP=1 and run collector.sh with --run-dir /tmp/run
```

SMART, quota, MySQL, and Imunify tests use fake binaries in front of `PATH`;
they never touch real disks, quotas, or databases.

---

## 13. Troubleshooting

| Symptom | Check |
| --- | --- |
| Exit 1 with `require_secure_file`/`require_secure_dir` | Config/token/state/run path is a symlink, owned by another user, or group/world writable. Fix owner/mode (`chmod 600` config/token, `chmod 700` state/run). |
| Exit 1 with `token … does not contain a valid token` | Token must match `^[A-Za-z0-9._~-]{16,256}$` and the file must be group/other-inaccessible. |
| Exit 1 with `API_URL must be https` | Use `https://`, or loopback HTTP with `ALLOW_INSECURE_HTTP=1` for tests only. |
| Exit 3, `payload rejected (HTTP 401/403)` | Token wrong or revoked on the dashboard; rotate it (§14). |
| Exit 3, `HTTP 5xx on attempt …` | Dashboard problem; the agent retried 3 times. Nothing to fix on the server. |
| Exit 3, network/TLS | Firewall/DNS; certificate chain (keep `TLS_VERIFY=1` and install the CA if needed). |
| Exit 2, `core payload … too large` | Lower `MYSQL_MAX_QUERIES` (or `MYSQL_INCLUDE_QUERY_TEXT=0`); the core alone should never reach 64 KiB. |
| Exit 4 from `--module` | Module missing/insecure, `detect` error (exit >1), or collection/validation failed; the old cache is kept. Run the module by hand to see its error. A disabled or not-applicable module is a clean skip (`0`, no cache). |
| `another collector run holds the lock` | A previous run is still going; nothing is sent this round (exit 0). |
| No panel metrics | `PANEL` set to `none`/wrong name, or `detect` failed. Check with `PANEL=auto` and `journalctl -u noc-agent.service`. |
| `smart`/`diskquota`/`imunify` fields missing | The timer has not yet succeeded: check `systemctl status noc-agent@<name>.service`, `journalctl -u noc-agent@<name>`, and `ls -l /var/lib/noc-agent/cache/`. |
| `imunify` always missing | `imunify360-agent` or `python3` absent — the module is skipped by design. |
| Stale fields | `*.cache_age_seconds` keeps growing: the module timer keeps failing. |
| Timer ran but nothing in the dashboard | Run `collector.sh --dry-run` (config OK?), then `journalctl -u noc-agent.service` for the HTTP exit code. |
| Exit 2 in journal for `--module`, or no cache after install | Priming failed or `--no-prime` was used; the timer will retry at its next schedule. |

---

## 14. Rotate the token

1. Issue a new token on the dashboard for this server.
2. Replace the file contents; no config change or restart is needed (the token
   is re-read on every run):

   ```bash
   install -m 600 /dev/null /etc/noc-agent/token.new
   printf '%s\n' 'NEW-TOKEN' > /etc/noc-agent/token.new
   mv -f /etc/noc-agent/token.new /etc/noc-agent/token
   ```

3. The next run uses the new token. Revoke the old token on the dashboard.

If the dashboard refuses both tokens during the switch, run
`systemctl start noc-agent.service` and check `journalctl` for the exit code.

---

## 15. Uninstall

```bash
# stop and disable the timers, remove the units and the installed collector
agent/uninstall.sh

# preview
agent/uninstall.sh --dry-run

# also remove the config/token and state (agent ID + cache)
agent/uninstall.sh --purge     # on a real system, confirmed with --yes

# scratch prefix (tests)
agent/uninstall.sh --prefix /tmp/noc-prefix --no-systemctl --purge
```

By default the config, token, and state directories are **kept** so a reinstall
keeps the same identity. `--purge` removes them (on the real system `/` it
requires `--yes`); the uninstaller refuses to delete anything outside the
install prefix. `--no-systemctl` skips all systemd calls.

---

## 16. Known limitations

- **No RAID monitoring.** Software RAID (`mdadm`) and hardware RAID
  controllers (PERC/LSI/HP) are not collected; schema v1 has no RAID field.
  Use the controller's own tooling for array health.
- **SMART behind hardware RAID controllers is not visible** to `smartctl` in v1
  (no `-d megaraid,…`-style passthrough). Only disks directly visible to the OS
  are reported, and SMART state is monthly.
- **cPanel is the only implemented panel.** Plesk, DirectAdmin, CyberPanel,
  aaPanel and others are supported by the plugin contract only; their plugins
  do not exist yet.
- **No dashboard, storage, alerting, or offline spooling** in this stage: if
  the endpoint is unreachable the payload for that run is dropped (exit 3) and
  the next timer run tries again.
- **No auto-update.** Upgrading means re-running the installer from a newer
  source tree.
- **systemd only** (no SysV/OpenRC); Linux only.
- **Root required in production**, because panel and module commands need it.
- Cache is read from `STATE_DIR` only; no multi-filesystem disk breakdown,
  only the root filesystem plus per-account quota data.
- Each external module runs under its own systemd unit, so different modules
  can overlap in time; cache writes are atomic and per-module, so this is not
  corrupting, but there is no cross-module lock.