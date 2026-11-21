# Panel plugins

A panel plugin is an executable Bash script in `agent/panels/<name>.sh`. The
collector core runs **exactly one** panel plugin per run (or none), and the
plugin's only job is to add panel-specific metrics on top of the universal core
metrics. Panel plugins must be **read-only**: they read panel/system state and
print metrics to stdout — they never write system files, never reconfigure or
restart services, and every external command is wrapped in `timeout`.

## Contract

A plugin must handle two subcommands and reject anything else:

```text
<name>.sh detect
  exit 0  = this panel is present → the core selects this plugin
  exit 1  = not this panel
  exit >1 = error → the core ignores this plugin and keeps scanning

<name>.sh collect
  stdout  = one metric per line: key<TAB>type<TAB>value
  exit 0  = success
  exit >0 = failure → panel_metrics is omitted, core metrics are still sent
```

The core scans `panels/*.sh` in sorted order and skips files whose name starts
with `_` (so `_template.sh` is never executed). Selection:

- `PANEL=auto` (default): run each plugin's `detect` under
  `PANEL_DETECT_TIMEOUT` (default 2s) and pick the first that exits 0.
- `PANEL=<name>`: skip `detect`, run `panels/<name>.sh` directly. A missing or
  insecure plugin is a **config error** (the collector exits 1).
- `PANEL=none`: no panel; `agent.panel` is `"none"` and `panel_metrics` is `{}`.

`collect` runs under `PANEL_TIMEOUT` (default 10s). A nonzero exit or a timeout
logs a warning and leaves `panel_metrics` empty — the universal core metrics
are always sent. Plugin stderr is logged only when `DEBUG=1`.

## Metric line format

One metric per line, three tab-separated fields:

```text
key<TAB>type<TAB>value
```

- `key`: `^[A-Za-z][A-Za-z0-9_.-]{0,63}$` (max 64 chars, dot-separated names).
- `type`:
  - `n` = number, syntax `^-?(0|[1-9][0-9]*)(\.[0-9]+)?$`
  - `b` = boolean, value `0` or `1`
  - `s` = string, max 1024 bytes, no tabs/newlines
- Blank lines and lines starting with `#` are ignored.
- Max 128 metrics per plugin; duplicate keys are rejected (first value wins).

Malformed lines are skipped by the core; the plugin should sanitize string
values (strip control characters) and service names (to `[A-Za-z0-9_.-]`)
itself so valid data is not silently dropped.

The resulting `panel_metrics` object is **flat**: string/number/boolean values
only — no arrays or nested objects in schema v1.

## Example

```bash
#!/bin/bash
set -u

case ${1:-} in
  detect)
    [ -x /usr/local/example/panel ] || exit 1
    ;;
  collect)
    printf 'panel.version\ts\t%s\n' "$(cat /usr/local/example/version 2>/dev/null)"
    printf 'panel.accounts\tn\t%s\n' "$(find /var/example/users -maxdepth 1 -type f | wc -l)"
    ;;
  *)
    exit 2
    ;;
esac
```

## cPanel plugin (`cpanel.sh`)

`detect` succeeds when `/usr/local/cpanel/cpanel` is executable. `collect`
emits these fields (each probe is individually guarded and timed out):

| Field | Type | Source |
|---|---|---|
| `cpanel.version` | string | `/usr/local/cpanel/version` |
| `cpanel.accounts` | number | count of `/var/cpanel/users/*` (excluding `system`) |
| `cpanel.mailq` | number | `exim -bpc` (timeout 5s) |
| `cpanel.mysql_ping` | boolean | `mysqladmin ping` (timeout 3s) |
| `cpanel.webserver` | string | `litespeed` if its daemon runs, else `apache` |
| `cpanel.cagefs` | string | `cagefsctl --cagefs-status` (timeout 5s) |
| `security.csf_enabled` | boolean | `/etc/csf` present and no `/etc/csf/csf.disable` |
| `security.csf_testing` | string | `TESTING` from `/etc/csf/csf.conf` |
| `security.modsec` | string | `SecRuleEngine` from the cPanel modsec conf |
| `service.<name>.installed` | boolean | `whmapi1 servicestatus` (timeout 10s) |
| `service.<name>.enabled` | boolean | `whmapi1 servicestatus` |
| `service.<name>.running` | boolean | `whmapi1 servicestatus` |

Service names are sanitized to `[A-Za-z0-9_.-]`. If `whmapi1` is missing or
fails, the plugin still succeeds with the metrics it managed to collect. Heavy
metrics (SMART, disk quota, Imunify, MySQL process list) are **not** collected
by this plugin — those are system modules shared with non-panel hosts.
