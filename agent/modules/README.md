# System modules

A system module is an executable Bash script in `agent/modules/<name>.sh`. Unlike
panel plugins (exactly one per run), the core runs **every applicable** module.
Modules add optional metrics that are not tied to any panel, and their failure
must never abort the core payload.

The core scans `modules/*.sh` in sorted order and skips files whose name starts
with `_` (so `_template.sh` is never executed).

## Contract

A module must handle three subcommands and reject anything else:

```text
<name>.sh detect
  exit 0  = this module is applicable
  exit 1  = not applicable → the core skips it
  exit >1 = error → the core ignores this module

<name>.sh mode
  stdout  = "inline" or "external" (missing/unknown output is treated as inline)

<name>.sh collect
  stdout  = one metric per line: key<TAB>type<TAB>value
  exit 0  = success
  exit >0 = failure
```

### Metric line format

Same as panel plugins, with one extra rule:

```text
key<TAB>type<TAB>value
```

- `key`: `^[A-Za-z][A-Za-z0-9_.-]{0,63}$` (max 64 chars), and **must** start
  with `<name>.` (e.g. `mysql.threads_running`).
- `type`: `n` (number), `b` (boolean `0`/`1`), or `s` (string, max 1024 bytes,
  no tabs/newlines).
- Blank lines and lines starting with `#` are ignored.
- Max 128 metrics per module; duplicate keys are rejected (first value wins).

Lines whose key does not start with `<name>.` are dropped by the core. Malformed
lines are rejected by the JSON validation path without aborting the payload.

## Modes

- **inline**: run by the main collector on every run, under `MODULE_TIMEOUT`
  (default 5s). Used for cheap per-run data such as the MySQL process list.
- **external**: never run by the main collector. A separate systemd timer runs
  `collector.sh --module <name>` to collect the data; the core validates the
  output and writes `$STATE_DIR/cache/<name>.metrics` atomically (temp file mode
  600, then `mv`). The main run only reads that cache.

## External-cache rules

- An external module must emit `<name>.updated_at` (epoch number) on every
  successful `collect`, even when there are no findings. The core rejects (exit
  4) an external module that omits it and preserves the previous cache.
- The cache is written **by the core**, never by the module itself. Modules must
  not write files outside the metric protocol.
- On the main run, the core appends `<name>.cache_age_seconds` (from the cache
  file's mtime) so the dashboard can flag stale data.
- A missing, symlinked, or non-regular cache file is ignored; a module with no
  successful cache simply contributes no metrics (not zero values).
- The cache is only read when the module's `detect` still succeeds and the
  module is enabled.
- In `--module` mode, a disabled module or one whose `detect` exits 1 is a
  clean skip: exit 0, no output, and no cache write. A missing/insecure module,
  a `detect` error (exit >1), a failed `collect`, or an external module missing
  `updated_at` returns 4.

## Enable / disable

A module is enabled unless its `ENABLE_<UPPER>` config value is exactly `0`
(e.g. `ENABLE_MYSQL=0` disables `mysql.sh`). A disabled module is neither run
nor read from cache.

## Provided modules

| Module | Mode | Schedule | Notes |
|---|---|---|---|
| `mysql.sh` | inline | every run | process list, `mysqladmin extended-status` |

`_template.sh` is a copyable starting point (detects nothing, emits nothing).
