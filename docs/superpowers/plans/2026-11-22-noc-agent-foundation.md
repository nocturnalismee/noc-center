# NOC Agent Foundation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the Bash collector agent, panel/system modules, systemd units, installer, JSON API contract, and local mock endpoint described in the approved NOC agent design.

**Architecture:** `collector.sh` is a small Bash core that collects universal Linux metrics, selects exactly one panel plugin, runs inline system modules, reads cached external module output, builds schema-v1 JSON, and POSTs it to the dashboard. Optional `panels/*.sh` and `modules/*.sh` communicate through a typed line protocol; heavy modules run from separate systemd timers and write root-only caches, so the 60-second run stays light.

**Tech Stack:** Bash 4+, GNU coreutils, `curl`, `flock`, `systemd`; Python 3 only for the local mock endpoint and the optional Imunify JSON parser (the Imunify module is skipped when `python3` is absent).

**Spec:** `docs/superpowers/specs/2026-11-22-noc-agent-foundation-design.md`

## Global Constraints

- Production paths: config `/etc/noc-agent/agent.conf` (600), token `/etc/noc-agent/token` (600), state `/var/lib/noc-agent` (700), cache `/var/lib/noc-agent/cache` (700/files 600), runtime `/run/noc-agent` (700), install `/usr/local/libexec/noc-agent`.
- Agent version is `0.1.0`. Schema version is `1`.
- Payload limit is 65536 bytes. Drop all `panel_metrics` first, then all `extensions`; if the core alone is too large, exit 2.
- API default: `https://monitor.example.com/api/v1/ingest`. Token only in `Authorization: Bearer`, never URL, argv, logs, or payload.
- HTTP: success 200/202/204; no retry on 400/401/403/413/422; retry on 429/5xx/network; max 3 attempts; 1s then 3s backoff; `CURL_TIMEOUT=8`; `MAX_RUN_SECONDS=45`; `TimeoutStartSec=50` main, `300` module.
- External modules: `mysql.sh` inline every run; `smart.sh` monthly; `diskquota.sh` daily; `imunify.sh` every 5 minutes. No RAID module.
- All metric keys match `^[A-Za-z][A-Za-z0-9_.-]{0,63}$`; module keys must start `<module>.`. String values max 1024 bytes and contain no tab/newline. Number regex: `^-?(0|[1-9][0-9]*)(\.[0-9]+)?$`. Boolean is `0` or `1`.
- Plugin/module output is `key<TAB>type<TAB>value`; stdout contains metric lines only; diagnostics go to stderr.
- No `eval`, no shell execution of API responses, no service/config/firewall changes. All panel/module commands are read-only.
- The legacy root-level `collector-cpanel.sh` is unrelated; do not modify or overwrite it.
- Every task ends with a commit. The workspace is not a git repository; Task 1 initializes it.

## Review Focus

- Malformed plugin/module output (missing tabs, invalid numbers, duplicate keys, embedded tabs) must never produce invalid JSON or abort the whole payload. Tested in Tasks 1, 4, and 5.
- Token secrecy: a bad token must produce one request and no token in argv/journal/payload. Tested in Task 9.
- Unsafe paths and permissions (symlinked config/token/state/cache, group/world-writable dirs) must be refused. Tested in Task 2 and Task 5.
- Missing, corrupt, or stale external-module cache must leave the main payload valid and expose `cache_age_seconds`. Tested in Tasks 5-8.
- HTTP failures and slow endpoints must honor the deadline, retry only the allowed statuses, and return the specified exit code. Tested in Task 9.

---

### Task 1: Repository scaffold and JSON encoder

**Files:**
- Create: `.gitignore`
- Create: `agent/lib/json.sh`
- Create: `tools/tests/json_test.sh`
- Create: `tools/test-agent.sh` (skeleton; expanded in Task 12)

**Interfaces:**
- Produces:
  - `json_escape VALUE` → escaped scalar without quotes.
  - `json_string VALUE` → JSON string including quotes.
  - `json_number VALUE` → valid JSON number or `0`.
  - `json_bool VALUE` → `true`/`false` from `0`/`1`.
  - `json_object_entries FILE [PREFIX]` → comma-separated `"key":value` entries; validates and de-duplicates; sets `JSON_INVALID_COUNT`.

- [ ] **Step 1: Initialize the repository and write the failing JSON test**

```bash
cd /home/monitor/Documents/noc-center
git init -b main
mkdir -p agent/lib agent/panels agent/modules agent/systemd agent/config tools/tests
```

Create `.gitignore`:

```gitignore
*.tmp
*.swp
agent/config/agent.conf
agent/config/token
```

Create `tools/tests/json_test.sh` with these assertions:

```bash
#!/bin/bash
set -euo pipefail
cd "$(dirname "$0")/../.."
source agent/lib/json.sh
fail() { echo "FAIL: $*" >&2; exit 1; }

[ "$(json_escape 'a"b\c')" = 'a\"b\\c' ] || fail "escape"
[ "$(json_string 'hi')" = '"hi"' ] || fail "string"
[ "$(json_number '12.5')" = '12.5' ] || fail "number"
[ "$(json_number 'bad')" = '0' ] || fail "bad number"
[ "$(json_bool 1)" = 'true' ] || fail "bool true"
[ "$(json_bool 0)" = 'false' ] || fail "bool false"

tmp=$(mktemp)
printf 'a\tn\t42\nb\ts\thi "there"\nc\tb\t1\n' > "$tmp"
[ "$(json_object_entries "$tmp")" = '"a":42,"b":"hi \"there\"","c":true' ] \
  || fail "entries"

printf 'a\tn\t1\na\tn\t2\nbad\tx\t1\nd\tn\t3\textra\n' > "$tmp"
[ "$(json_object_entries "$tmp")" = '"a":1' ] || fail "dedupe/invalid"

printf 'mod.x\tn\t1\nother.y\tn\t2\n' > "$tmp"
[ "$(json_object_entries "$tmp" 'mod.')" = '"mod.x":1' ] || fail "prefix"
rm -f "$tmp"
echo "json_test: PASS"
```

- [ ] **Step 2: Run the test and verify it fails**

Run: `bash tools/tests/json_test.sh`
Expected: FAIL because `agent/lib/json.sh` does not exist.

- [ ] **Step 3: Implement `agent/lib/json.sh`**

Implement the five functions above. `json_object_entries` reads the file line by line, skips blank/comment lines, requires two tabs, validates key/type/value, keeps the first duplicate, enforces 128 entries, and writes no trailing comma. Use only Bash built-ins.

- [ ] **Step 4: Run the test and verify it passes**

Run: `bash tools/tests/json_test.sh`
Expected: `json_test: PASS`.

- [ ] **Step 5: Add the test runner skeleton and commit**

`tools/test-agent.sh` should start as:

```bash
#!/bin/bash
set -euo pipefail
cd "$(dirname "$0")/.."
bash tools/tests/json_test.sh
echo "test-agent: PASS"
```

```bash
chmod +x tools/tests/json_test.sh tools/test-agent.sh
bash tools/test-agent.sh
git add .gitignore agent tools
git commit -m "chore: scaffold NOC agent and JSON encoder"
```

---

### Task 2: Config, security checks, token, agent ID, and lock

**Files:**
- Create: `agent/lib/common.sh`
- Create: `agent/collector.sh`
- Create: `tools/tests/common_test.sh`
- Modify: `tools/test-agent.sh`

**Interfaces:**
- Consumes: nothing from Task 1 except the source layout.
- Produces:
  - `config_defaults()` → sets all defaults from the spec.
  - `load_config FILE` → sources a secure config into the current shell.
  - `validate_config()` → exits 1 on an invalid value.
  - `require_secure_file PATH` / `require_secure_dir PATH` → return 1 and log on symlink, wrong owner, or group/world write.
  - `validate_token_file PATH` → sets `SERVER_TOKEN`.
  - `get_agent_id()` → prints a stable UUID; creates it atomically when writable.
  - `acquire_lock LOCKFILE` → uses fd 9 and `flock -n`; returns 1 if busy.
  - `collector.sh --help|--version` works.

- [ ] **Step 1: Write the failing common test**

Create `tools/tests/common_test.sh` asserting:

```bash
#!/bin/bash
set -euo pipefail
cd "$(dirname "$0")/../.."
source agent/lib/common.sh
fail() { echo "FAIL: $*" >&2; exit 1; }

tmp=$(mktemp -d)
trap 'rm -rf "$tmp"' EXIT
chmod 700 "$tmp"
require_secure_dir "$tmp" || fail "secure dir"
chmod 777 "$tmp"
if require_secure_dir "$tmp" 2>/dev/null; then fail "world-writable dir accepted"; fi
chmod 700 "$tmp"
ln -s "$tmp" "$tmp.link"
if require_secure_dir "$tmp.link" 2>/dev/null; then fail "symlink dir accepted"; fi

printf 'TOKEN=abcdefghijklmnop\n' > "$tmp/conf"
chmod 600 "$tmp/conf"
config_defaults
load_config "$tmp/conf"
[ "$TOKEN" = "abcdefghijklmnop" ] || fail "config source"

printf 'abcdefghijklmnop\n' > "$tmp/token"
chmod 600 "$tmp/token"
validate_token_file "$tmp/token" || fail "token"
[ "$SERVER_TOKEN" = "abcdefghijklmnop" ] || fail "token value"
chmod 644 "$tmp/token"
if validate_token_file "$tmp/token" 2>/dev/null; then fail "insecure token accepted"; fi

# Agent ID is stable and mode 600.
mkdir -p "$tmp/state"; chmod 700 "$tmp/state"
id1=$(STATE_DIR="$tmp/state" get_agent_id)
id2=$(STATE_DIR="$tmp/state" get_agent_id)
[ "$id1" = "$id2" ] || fail "agent id stable"
[ "$(stat -c %a "$tmp/state/agent-id")" = "600" ] || fail "agent id mode"

[ "$(agent/collector.sh --version)" = "noc-agent 0.1.0" ] || fail "version"
echo "common_test: PASS"
```

- [ ] **Step 2: Run the test and verify it fails**

Run: `bash tools/tests/common_test.sh`
Expected: FAIL because `agent/lib/common.sh` does not exist.

- [ ] **Step 3: Implement `agent/lib/common.sh`**

Implement the interfaces above. `config_defaults` sets every key from the spec, including `API_URL`, `SERVER_TOKEN_FILE`, `INTERVAL_SECONDS=60`, `PANEL=auto`, timeouts, `STATE_DIR`, `TLS_VERIFY=1`, `ALLOW_INSECURE_HTTP=0`, `DEBUG=0`, `ENABLE_*`, `MODULE_*`, `QUOTA_TOP_N`, `MYSQL_*`, `SMART_MAX_DISKS`.

`validate_config` must reject: interval outside 10–3600, panel detect 1–5, panel timeout 3–20, curl 3–15, max run 20–45, module detect 1–5, module timeout 1–15, heavy 30–600, quota top 1–50, MySQL max 0–50, MySQL min 1–3600, SMART max 1–64; non-0/1 booleans; a non-`auto|none|<plugin>` panel; and a URL that is not HTTPS unless `ALLOW_INSECURE_HTTP=1` and the host is `127.0.0.1` or `localhost`.

- [ ] **Step 4: Implement the `collector.sh` skeleton**

`agent/collector.sh` sources `lib/common.sh` and `lib/json.sh`, sets `SELF_DIR`, `AGENT_VERSION=0.1.0`, parses `--config`, `--state-dir`, `--run-dir`, `--module`, `--dry-run`, `--panel`, `--panel-dir`, `--module-dir`, `--max-payload-bytes`, `--help`, `--version`. For now `main` should only validate `--version`/`--help`; later tasks add collection. Store every parsed option in a variable that later tasks can consume. Make it sourceable:

```bash
if [[ "${BASH_SOURCE[0]}" == "$0" ]]; then
  main "$@"
fi
```

- [ ] **Step 5: Run the test and verify it passes**

Run: `bash tools/tests/common_test.sh`
Expected: `common_test: PASS`.

- [ ] **Step 6: Update the runner and commit**

Add `bash tools/tests/common_test.sh` to `tools/test-agent.sh`.

```bash
bash tools/test-agent.sh
git add agent tools
git commit -m "feat: add secure agent config and runtime helpers"
```

---

### Task 3: Universal metrics and schema-v1 dry-run payload

**Files:**
- Modify: `agent/collector.sh`
- Create: `tools/tests/core_test.sh`
- Modify: `tools/test-agent.sh`

**Interfaces:**
- Consumes: `config_defaults`, `load_config`, `validate_config`, `get_agent_id`, `json_*`.
- Produces:
  - `collect_core()` → fills `CPU_*`, `MEM_*`, `SWAP_*`, `DISK_*`, `INODE_PCT`, `NET_*`, `PROC_*`, `OS_*`, `KERNEL`, `UPTIME`, `HOSTNAME`.
  - `build_payload PANEL_FILE EXT_FILE` → prints schema-v1 JSON.
  - `main --dry-run` → prints JSON to stdout; no token, lock, cache write, or HTTP.

- [ ] **Step 1: Write the failing core test**

Create `tools/tests/core_test.sh`:

```bash
#!/bin/bash
set -euo pipefail
cd "$(dirname "$0")/../.."
tmp=$(mktemp -d)
trap 'rm -rf "$tmp"' EXIT
chmod 700 "$tmp"
mkdir -p "$tmp/state"; chmod 700 "$tmp/state"
cat > "$tmp/agent.conf" <<EOF
API_URL="http://127.0.0.1:18787/api/v1/ingest"
ALLOW_INSECURE_HTTP=1
SERVER_TOKEN_FILE="$tmp/token"
STATE_DIR="$tmp/state"
PANEL=none
INTERVAL_SECONDS=60
EOF
chmod 600 "$tmp/agent.conf"
printf 'abcdefghijklmnop\n' > "$tmp/token"
chmod 600 "$tmp/token"

json=$(agent/collector.sh --dry-run --config "$tmp/agent.conf" --state-dir "$tmp/state")
printf '%s' "$json" | python3 -m json.tool >/dev/null
python3 - "$json" <<'PY'
import json, sys
d = json.loads(sys.argv[1])
assert d["schema_version"] == 1
assert d["agent"]["panel"] == "none"
assert d["agent"]["version"] == "0.1.0"
assert d["agent"]["interval_seconds"] == 60
assert d["panel_metrics"] == {}
assert d["extensions"] == {}
assert d["metrics"]["cpu"]["cores"] >= 1
assert 0 <= d["metrics"]["cpu"]["usage_pct"] <= 100
assert d["metrics"]["memory"]["total_bytes"] > 0
assert d["metrics"]["disk"]["root"]["total_bytes"] > 0
assert d["metrics"]["network"]["rx_bytes_per_sec"] >= 0
assert d["system"]["os"]["kernel"]
PY
echo "core_test: PASS"
```

- [ ] **Step 2: Run the test and verify it fails**

Run: `bash tools/tests/core_test.sh`
Expected: FAIL because `main` does not produce a payload.

- [ ] **Step 3: Implement core collection**

Collect CPU and network in one 1-second window. CPU total is user+nice+system+idle+iowait+irq+softirq; `usage_pct = 100*(total-idle-iowait)/total`; `iowait_pct = 100*iowait/total`. Memory uses `MemTotal - MemAvailable` with the `MemFree+Buffers+Cached` fallback. Disk uses `df -P /` and `df -iP /`; network sums byte fields 1 and 9 after the colon for non-loopback interfaces; processes count `R` and `D` from `ps -eo state=`. Read OS data from `/etc/os-release`, kernel from `uname -r`, uptime from `/proc/uptime`, hostname from `/proc/sys/kernel/hostname`. Extend `PATH` by appending cPanel paths, never prepending them: `PATH="$PATH:/usr/local/cpanel/bin:/usr/local/cpanel/3rdparty/bin"`. This keeps fake binaries that a test prepends in front of the real ones.

- [ ] **Step 4: Implement `build_payload` and dry-run**

Build the JSON exactly as schema v1. Use `json_string`/`json_number` for every value. `panel_metrics` and `extensions` are built from the passed files via `json_object_entries`; pass `/dev/null` for empty. In dry-run: skip token validation, skip the lock, skip cache writes, use a temporary state dir if `--state-dir` is absent and euid is non-zero, print the payload, exit 0.

- [ ] **Step 5: Run the test and verify it passes**

Run: `bash tools/tests/core_test.sh`
Expected: `core_test: PASS`.

- [ ] **Step 6: Update the runner and commit**

```bash
bash tools/tests/core_test.sh
git add agent tools
git commit -m "feat: collect universal metrics and emit schema v1"
```

---

### Task 4: Panel loader and cPanel plugin

**Files:**
- Modify: `agent/collector.sh`
- Create: `agent/panels/cpanel.sh`
- Create: `agent/panels/_template.sh`
- Create: `agent/panels/README.md`
- Create: `tools/tests/panel_test.sh`
- Modify: `tools/test-agent.sh`

**Interfaces:**
- Consumes: `build_payload`, `json_object_entries`.
- Produces:
  - `select_panel()` → sets `PANEL_NAME`, `PANEL_FILE`; `none` for no panel.
  - `run_panel()` → writes validated/raw panel lines to `PANEL_TMP`; failures leave it empty.
  - `cpanel.sh detect|collect`.

- [ ] **Step 1: Write the failing panel test**

Create `tools/tests/panel_test.sh` with a fake plugin dir:

```bash
#!/bin/bash
set -euo pipefail
cd "$(dirname "$0")/../.."
tmp=$(mktemp -d); trap 'rm -rf "$tmp"' EXIT
mkdir -p "$tmp/panels" "$tmp/state"
cat > "$tmp/panels/fake.sh" <<'EOF'
#!/bin/bash
case "${1:-}" in
  detect) exit 0 ;;
  collect) printf 'fake.version\ts\t1.0\nfake.count\tn\t3\nfake.enabled\tb\t1\n' ;;
  *) exit 2 ;;
esac
EOF
chmod +x "$tmp/panels/fake.sh"
cat > "$tmp/agent.conf" <<EOF
PANEL=auto
STATE_DIR="$tmp/state"
API_URL="http://127.0.0.1:18787/api/v1/ingest"
ALLOW_INSECURE_HTTP=1
SERVER_TOKEN_FILE="$tmp/token"
EOF
chmod 600 "$tmp/agent.conf"
printf 'abcdefghijklmnop\n' > "$tmp/token"; chmod 600 "$tmp/token"
json=$(agent/collector.sh --dry-run --config "$tmp/agent.conf" --panel-dir "$tmp/panels")
python3 - "$json" <<'PY'
import json, sys
d=json.loads(sys.argv[1])
assert d["agent"]["panel"]=="fake"
assert d["panel_metrics"]=={"fake.version":"1.0","fake.count":3,"fake.enabled":True}
PY
# Explicit missing panel is a config error.
if agent/collector.sh --dry-run --config "$tmp/agent.conf" --panel-dir "$tmp/panels" --panel missing 2>/dev/null; then
  echo "missing panel accepted" >&2; exit 1
fi
echo "panel_test: PASS"
```

- [ ] **Step 2: Run the test and verify it fails**

Run: `bash tools/tests/panel_test.sh`
Expected: FAIL because the panel loader and `--panel` option do not exist.

- [ ] **Step 3: Implement panel selection and execution**

`select_panel` handles `PANEL=none`, an explicit plugin name (file must exist and be secure; missing is exit 1), and `auto` (sorted `panels/*.sh`, skip `_*`, first `detect` exit 0 within `PANEL_DETECT_TIMEOUT`). `run_panel` runs `collect` under `timeout "$PANEL_TIMEOUT"`; nonzero exit or timeout logs a warning and leaves `PANEL_TMP` empty. Invalid lines are skipped by `json_object_entries`; stderr is logged only when `DEBUG=1`.

- [ ] **Step 4: Implement the cPanel plugin**

`detect` succeeds when `/usr/local/cpanel/cpanel` is executable. `collect` emits the spec fields with timeouts: `cpanel.version`, `cpanel.accounts`, `cpanel.mailq`, `cpanel.mysql_ping`, `cpanel.webserver`, `cpanel.cagefs`, `security.csf_enabled`, `security.csf_testing`, `security.modsec`, and `service.<name>.{installed,enabled,running}` from `whmapi1 servicestatus`. Sanitize service names and string values; never write system files.

- [ ] **Step 5: Add the template and README, run tests**

`agent/panels/_template.sh` must exit 1 on `detect`, print nothing on `collect`, and serve as a copyable contract example. `agent/panels/README.md` documents the protocol and the cPanel fields.

Run: `bash tools/tests/panel_test.sh`
Expected: `panel_test: PASS`.

- [ ] **Step 6: Update the runner and commit**

```bash
bash tools/tests/panel_test.sh
git add agent tools
git commit -m "feat: add panel loader and cPanel collector plugin"
```

---

### Task 5: Module loader and MySQL module

**Files:**
- Modify: `agent/collector.sh`
- Create: `agent/modules/mysql.sh`
- Create: `agent/modules/_template.sh`
- Create: `agent/modules/README.md`
- Create: `tools/tests/module_test.sh`
- Modify: `tools/test-agent.sh`

**Interfaces:**
- Consumes: `json_object_entries`, `build_payload`.
- Produces:
  - `module_enabled NAME` → true unless `ENABLE_<UPPER>` is 0.
  - `module_mode FILE` → prints `inline` or `external`; missing/unknown output means `inline`.
  - `run_modules EXT_TMP` → runs inline modules and reads external caches.
  - `run_module_mode NAME` → validates one module, writes cache or prints dry-run lines.
  - `mysql.sh detect|mode|collect`.

- [ ] **Step 1: Write the failing module test**

Create `tools/tests/module_test.sh`:

```bash
#!/bin/bash
set -euo pipefail
cd "$(dirname "$0")/../.."
tmp=$(mktemp -d); trap 'rm -rf "$tmp"' EXIT
mkdir -p "$tmp/modules" "$tmp/state" "$tmp/run" "$tmp/bin"
cat > "$tmp/modules/inline.sh" <<'EOF'
#!/bin/bash
case "${1:-}" in
  detect) exit 0 ;;
  mode) echo inline ;;
  collect) printf 'inline.value\tn\t7\n' ;;
  *) exit 2 ;;
esac
EOF
cat > "$tmp/modules/external.sh" <<'EOF'
#!/bin/bash
case "${1:-}" in
  detect) exit 0 ;;
  mode) echo external ;;
  collect)
    [ "${FAIL:-0}" = 1 ] && exit 1
    printf 'external.value\tn\t9\nexternal.updated_at\tn\t1\n'
    ;;
  *) exit 2 ;;
esac
EOF
chmod +x "$tmp/modules"/*.sh
cat > "$tmp/agent.conf" <<EOF
PANEL=none
STATE_DIR="$tmp/state"
API_URL="http://127.0.0.1:18787/api/v1/ingest"
ALLOW_INSECURE_HTTP=1
SERVER_TOKEN_FILE="$tmp/token"
ENABLE_INLINE=1
ENABLE_EXTERNAL=1
EOF
chmod 600 "$tmp/agent.conf"
printf 'abcdefghijklmnop\n' > "$tmp/token"; chmod 600 "$tmp/token"
json=$(agent/collector.sh --dry-run --config "$tmp/agent.conf" --run-dir "$tmp/run" --module-dir "$tmp/modules")
python3 - "$json" <<'PY'
import json, sys
d=json.loads(sys.argv[1])
assert d["extensions"]["inline.value"] == 7
assert "external.value" not in d["extensions"]
PY
# Prime the external cache through module mode.
agent/collector.sh --module external --config "$tmp/agent.conf" --run-dir "$tmp/run" --module-dir "$tmp/modules"
[ "$(stat -c %a "$tmp/state/cache/external.metrics")" = 600 ] || exit 1
json=$(agent/collector.sh --dry-run --config "$tmp/agent.conf" --run-dir "$tmp/run" --module-dir "$tmp/modules")
python3 - "$json" <<'PY'
import json, sys
d=json.loads(sys.argv[1])
assert d["extensions"]["external.value"] == 9
assert "external.cache_age_seconds" in d["extensions"]
PY
# Failed external collection keeps the old cache and exits 4.
rc=0
FAIL=1 agent/collector.sh --module external --config "$tmp/agent.conf" --run-dir "$tmp/run" --module-dir "$tmp/modules" || rc=$?
[ "$rc" -eq 4 ] || exit 1
grep -q 'external.value' "$tmp/state/cache/external.metrics" || exit 1
# Disabled and invalid-prefix modules are skipped.
cat > "$tmp/modules/bad.sh" <<'EOF'
#!/bin/bash
case "${1:-}" in detect) exit 0;; mode) echo inline;; collect) printf 'other.value\tn\t1\n';; esac
EOF
chmod +x "$tmp/modules/bad.sh"
json=$(agent/collector.sh --dry-run --config "$tmp/agent.conf" --run-dir "$tmp/run" --module-dir "$tmp/modules")
python3 - "$json" <<'PY'
import json, sys
d=json.loads(sys.argv[1])
assert "other.value" not in d["extensions"]
PY
echo "module_test: PASS"
```

- [ ] **Step 2: Run the test and verify it fails**

Run: `bash tools/tests/module_test.sh`
Expected: FAIL because module loading does not exist.

- [ ] **Step 3: Implement module loading**

For each sorted `modules/*.sh` except `_*`: skip when `module_enabled` is false; run `detect` under `MODULE_DETECT_TIMEOUT`; skip on nonzero. `external` modules only contribute `$CACHE_DIR/<name>.metrics` when it is a regular non-symlink file; append `<name>.cache_age_seconds` using mtime. `inline` modules run `collect` under `MODULE_TIMEOUT`; parse/route their lines through the prefix check. Module output keys must start `<name>.`. Inline failure is a warning only.

`run_module_mode` validates the name, loads config, checks `detect`, runs `collect` under `HEAVY_MODULE_TIMEOUT`, validates that an external module emitted `<name>.updated_at`, and writes `$CACHE_DIR/<name>.metrics` atomically (temp 600 + `mv`). On any failure, keep the old cache and exit 4. `--module NAME --dry-run` prints normalized metric lines and writes nothing; `--module` does not read the token and does not call HTTP. Non-root module runs are allowed only when `--state-dir` and `--run-dir` point to directories owned by the effective UID; production defaults require root.

- [ ] **Step 4: Implement `mysql.sh`**

`detect` requires `mysql` and `mysqladmin`, and `timeout 3 mysqladmin ping` must succeed (otherwise exit 1). `mode` prints `inline`. `collect` emits `mysql.updated_at` (epoch), `mysql.available`, `mysql.threads_running` from `mysqladmin extended-status`, `mysql.long_queries`, and up to `MYSQL_MAX_QUERIES` entries `mysql.query.<i>.{user,db,state,info,time}` from `information_schema.PROCESSLIST` filtered by `time >= MYSQL_MIN_SECONDS` and `command <> 'Sleep'`. Sanitize newlines/tabs; truncate `info` to 150 chars only when `MYSQL_INCLUDE_QUERY_TEXT=1`; otherwise emit `info` as `-`. Use `timeout 3` for both commands.

- [ ] **Step 5: Add the template/README, run the test**

`agent/modules/_template.sh` implements `detect` exit 1, `mode` inline, `collect` no output. `agent/modules/README.md` documents the protocol and the external-cache rules.

Run: `bash tools/tests/module_test.sh`
Expected: `module_test: PASS`.

- [ ] **Step 6: Update the runner and commit**

```bash
bash tools/tests/module_test.sh
git add agent tools
git commit -m "feat: add module runtime and MySQL process-list collector"
```

---

### Task 6: SMART module

**Files:**
- Create: `agent/modules/smart.sh`
- Create: `tools/tests/smart_test.sh`
- Modify: `tools/test-agent.sh`

**Interfaces:**
- Consumes: module protocol.
- Produces external metrics: `smart.updated_at`, `smart.disks`, `smart.bad_count`, `smart.status`, `smart.detail`, `smart.disk.<dev>.{health,realloc,pending}`.

- [ ] **Step 1: Write the failing SMART test**

Create `tools/tests/smart_test.sh` with a fake `lsblk` and `smartctl` early in `PATH`:

```bash
#!/bin/bash
set -euo pipefail
cd "$(dirname "$0")/../.."
tmp=$(mktemp -d); trap 'rm -rf "$tmp"' EXIT
mkdir -p "$tmp/bin" "$tmp/state" "$tmp/run"
cat > "$tmp/bin/lsblk" <<'EOF'
#!/bin/bash
printf 'sda disk\nzram0 disk\n'
EOF
cat > "$tmp/bin/smartctl" <<'EOF'
#!/bin/bash
cat <<'OUT'
SMART overall-health self-assessment test result: PASSED
  5 Reallocated_Sector_Ct   0x0033   100   100   010    Pre-fail  Always       -       2
197 Current_Pending_Sector  0x0012   100   100   000    Old_age   Always       -       1
OUT
EOF
chmod +x "$tmp/bin"/*
cat > "$tmp/agent.conf" <<EOF
PANEL=none
STATE_DIR="$tmp/state"
API_URL="http://127.0.0.1:18787/api/v1/ingest"
ALLOW_INSECURE_HTTP=1
SERVER_TOKEN_FILE="$tmp/token"
ENABLE_SMART=1
SMART_MAX_DISKS=8
EOF
chmod 600 "$tmp/agent.conf"
printf 'abcdefghijklmnop\n' > "$tmp/token"; chmod 600 "$tmp/token"
out=$(PATH="$tmp/bin:$PATH" agent/collector.sh --module smart --dry-run --config "$tmp/agent.conf" --run-dir "$tmp/run")
printf '%s\n' "$out" | grep -q '^smart.status	s	' || exit 1
printf '%s\n' "$out" | grep -q '^smart.bad_count	n	1$' || exit 1
printf '%s\n' "$out" | grep -q '^smart.disk.sda.realloc	n	2$' || exit 1
printf '%s\n' "$out" | grep -q '^smart.disk.sda.pending	n	1$' || exit 1
PATH="$tmp/bin:$PATH" agent/collector.sh --module smart --config "$tmp/agent.conf" --run-dir "$tmp/run"
[ "$(stat -c %a "$tmp/state/cache/smart.metrics")" = 600 ] || exit 1
echo "smart_test: PASS"
```

- [ ] **Step 2: Run the test and verify it fails**

Run: `bash tools/tests/smart_test.sh`
Expected: FAIL because `smart.sh` does not exist.

- [ ] **Step 3: Implement `smart.sh`**

`detect` requires `smartctl`. `mode` prints `external`. Enumerate whole disks with `lsblk -dno NAME,TYPE`, keep `TYPE=disk`, exclude `zram`, `dm-`, `loop`, `sr`, cap at `SMART_MAX_DISKS`. Run one `timeout 10 smartctl -H -A /dev/<dev>` per disk. Parse overall health, `Reallocated_Sector_Ct`, and `Current_Pending_Sector` from the 10th whitespace field. Emit per-disk health (`PASSED`/`FAILED`/`UNKNOWN`), realloc, pending. `bad_count` counts FAILED or realloc/pending >0; `status` is `failed` if any FAILED, `warning` if any non-zero attribute, `ok` if all checked healthy, `unknown` if no disk could be checked. `detail` is a sanitized summary (max 512 chars). Always emit `smart.updated_at` and `smart.disks`.

- [ ] **Step 4: Run the test and verify it passes**

Run: `bash tools/tests/smart_test.sh`
Expected: `smart_test: PASS`.

- [ ] **Step 5: Test the failure path**

Use the same fake `smartctl`, run `--module smart` once to create the cache, make `smartctl` exit 1, run again, and assert exit 4 and unchanged cache. Add this as a second test block in `smart_test.sh`.

- [ ] **Step 6: Update the runner and commit**

```bash
bash tools/tests/smart_test.sh
git add agent tools
git commit -m "feat: add monthly SMART health module"
```

---

### Task 7: Disk quota module

**Files:**
- Create: `agent/modules/diskquota.sh`
- Create: `tools/tests/diskquota_test.sh`
- Modify: `tools/test-agent.sh`

**Interfaces:**
- Produces external metrics: `diskquota.updated_at`, `diskquota.accounts`, `diskquota.over`, `diskquota.top.<user>.used_gb`.

- [ ] **Step 1: Write the failing disk quota test**

Create `tools/tests/diskquota_test.sh` with a fake `repquota`:

```bash
#!/bin/bash
set -euo pipefail
cd "$(dirname "$0")/../.."
tmp=$(mktemp -d); trap 'rm -rf "$tmp"' EXIT
mkdir -p "$tmp/bin" "$tmp/state"
cat > "$tmp/bin/repquota" <<'EOF'
#!/bin/bash
cat <<'CSV'
User,BlockUsed,BlockLimit
alice,2097152,0
bob,1048576,524288
carol,0,0
CSV
EOF
chmod +x "$tmp/bin/repquota"
cat > "$tmp/agent.conf" <<EOF
PANEL=none
STATE_DIR="$tmp/state"
API_URL="http://127.0.0.1:18787/api/v1/ingest"
ALLOW_INSECURE_HTTP=1
SERVER_TOKEN_FILE="$tmp/token"
ENABLE_DISKQUOTA=1
QUOTA_TOP_N=15
EOF
chmod 600 "$tmp/agent.conf"
printf 'abcdefghijklmnop\n' > "$tmp/token"; chmod 600 "$tmp/token"
out=$(PATH="$tmp/bin:$PATH" agent/collector.sh --module diskquota --dry-run --config "$tmp/agent.conf")
printf '%s\n' "$out" | grep -q '^diskquota.top.alice.used_gb	n	2.0$' || exit 1
printf '%s\n' "$out" | grep -q '^diskquota.over	n	1$' || exit 1
printf '%s\n' "$out" | grep -q '^diskquota.accounts	n	2$' || exit 1
echo "diskquota_test: PASS"
```

- [ ] **Step 2: Run the test and verify it fails**

Run: `bash tools/tests/diskquota_test.sh`
Expected: FAIL because `diskquota.sh` does not exist.

- [ ] **Step 3: Implement `diskquota.sh`**

`detect` requires `repquota`. `mode` prints `external`. Run `timeout 15 repquota -a -O csv`. Parse the header by name (`User`, `BlockUsed`, optional `BlockLimit`) rather than fixed positions. Count users with `BlockUsed > 0`; count `over` where `BlockLimit > 0 && BlockUsed > BlockLimit`; emit the top `QUOTA_TOP_N` users as `diskquota.top.<sanitized_user>.used_gb` with one decimal (`BlockUsed / 1048576`). Emit `diskquota.updated_at` and `diskquota.accounts`. If the CSV is unreadable or has no header, exit 1 so the cache is preserved.

- [ ] **Step 4: Add the old-format/empty tests and run**

Add two blocks: one CSV with a `FileStatus` column before `BlockUsed` (verify header mapping), and one empty body with only the header (expect `accounts 0`, `over 0`, no top keys). Run:

`bash tools/tests/diskquota_test.sh`
Expected: `diskquota_test: PASS`.

- [ ] **Step 5: Update the runner and commit**

```bash
bash tools/tests/diskquota_test.sh
git add agent tools
git commit -m "feat: add daily per-account disk quota module"
```

---

### Task 8: Imunify module

**Files:**
- Create: `agent/modules/imunify.sh`
- Create: `tools/tests/imunify_test.sh`
- Modify: `tools/test-agent.sh`

**Interfaces:**
- Produces external metrics: `imunify.updated_at`, `imunify.scanning`, `imunify.age_s`, `imunify.files`, `imunify.target`.

- [ ] **Step 1: Write the failing Imunify test**

Create `tools/tests/imunify_test.sh` with a fake `imunify360-agent`:

```bash
#!/bin/bash
set -euo pipefail
cd "$(dirname "$0")/../.."
tmp=$(mktemp -d); trap 'rm -rf "$tmp"' EXIT
mkdir -p "$tmp/bin" "$tmp/state"
cat > "$tmp/bin/imunify360-agent" <<'EOF'
#!/bin/bash
cat <<'JSON'
{"items":[{"scan_status":"running","started":1700000000,"total_resources":123,"path":"/home/alice/public_html"}]}
JSON
EOF
chmod +x "$tmp/bin/imunify360-agent"
cat > "$tmp/agent.conf" <<EOF
PANEL=none
STATE_DIR="$tmp/state"
API_URL="http://127.0.0.1:18787/api/v1/ingest"
ALLOW_INSECURE_HTTP=1
SERVER_TOKEN_FILE="$tmp/token"
ENABLE_IMUNIFY=1
EOF
chmod 600 "$tmp/agent.conf"
printf 'abcdefghijklmnop\n' > "$tmp/token"; chmod 600 "$tmp/token"
out=$(PATH="$tmp/bin:$PATH" agent/collector.sh --module imunify --dry-run --config "$tmp/agent.conf")
printf '%s\n' "$out" | grep -q '^imunify.scanning	b	1$' || exit 1
printf '%s\n' "$out" | grep -q '^imunify.files	n	123$' || exit 1
printf '%s\n' "$out" | grep -q '^imunify.target	s	alice$' || exit 1
echo "imunify_test: PASS"
```

- [ ] **Step 2: Run the test and verify it fails**

Run: `bash tools/tests/imunify_test.sh`
Expected: FAIL because `imunify.sh` does not exist.

- [ ] **Step 3: Implement `imunify.sh`**

`detect` requires `imunify360-agent` and `python3`; if `python3` is missing, exit 1 (module skipped, core unaffected). `mode` prints `external`. Run `timeout 10 imunify360-agent malware on-demand list --json` and parse it with a small embedded Python program that mirrors the legacy parser: accept `items` or `data`, accept integer/string `started`, only `scan_status`/`status == running`, choose the running scan with the largest `total_resources`/`total`, derive account target from `/home/<account>/...` else `incremental`, and emit a single line `age files target`. On invalid JSON or CLI failure exit 1. In Bash, emit `imunify.updated_at`, `imunify.scanning`, and either the parsed values or `scanning 0` with empty age/files/target.

- [ ] **Step 4: Run the test and verify it passes**

Run: `bash tools/tests/imunify_test.sh`
Expected: `imunify_test: PASS`.

- [ ] **Step 5: Add invalid-JSON/no-scan tests**

Add a fake CLI that prints `not-json` and assert `--module imunify` exits 4 while preserving an existing cache. Add a valid empty-items response and assert `imunify.scanning 0`.

- [ ] **Step 6: Update the runner and commit**

```bash
bash tools/tests/imunify_test.sh
git add agent tools
git commit -m "feat: add five-minute Imunify scan-status module"
```

---

### Task 9: HTTP client, token secrecy, retries, and mock endpoint

**Files:**
- Modify: `agent/collector.sh`
- Create: `tools/mock-ingest.py`
- Create: `tools/tests/http_test.sh`
- Modify: `tools/test-agent.sh`

**Interfaces:**
- Consumes: `build_payload`, config/token helpers.
- Produces:
  - `post_payload PAYLOAD_FILE` → returns 0 on 2xx, 3 after retries/network failure, 1 on config error.
  - `tools/mock-ingest.py` with `--port`, `--token`, `--fail-first`, `--delay`, `--log`, `--dump-dir`.

- [ ] **Step 1: Write the failing HTTP test**

Create `tools/tests/http_test.sh`. Use `python3 tools/mock-ingest.py --port 18787 --token goodtoken123456789 --log "$tmp/requests.log" --dump-dir "$tmp/dump"` in the background, and wait for `GET /health` to return 200. The test config sets `API_URL="http://127.0.0.1:18787/api/v1/ingest"`, `ALLOW_INSECURE_HTTP=1`, `PANEL=none`, a temporary `RUN_DIR`, `STATE_DIR`, and a token file created with `printf 'goodtoken123456789\n' > "$tmp/token"` and mode 600.

Prepare writable directories before starting the mock:

```bash
mkdir -p "$tmp/run" "$tmp/state" "$tmp/dump"
chmod 700 "$tmp/run" "$tmp/state" "$tmp/dump"
```

Assertions:

```bash
# valid token -> exit 0, one 202, one JSON dump
agent/collector.sh --config "$tmp/agent.conf" --run-dir "$tmp/run"
grep -q ' 202 ' "$tmp/requests.log" || exit 1
count=$(find "$tmp/dump" -type f | wc -l)
[ "$count" -eq 1 ] || exit 1

# bad token -> exit 3, exactly one 401, token not in logs
printf 'badtoken123456789\n' > "$tmp/token"; chmod 600 "$tmp/token"
rc=0
agent/collector.sh --config "$tmp/agent.conf" --run-dir "$tmp/run" || rc=$?
[ "$rc" -eq 3 ] || exit 1
[ "$(grep -c ' 401 ' "$tmp/requests.log")" -eq 1 ] || exit 1
! grep -q 'badtoken123456789' "$tmp/requests.log" || exit 1
```

Add a second mock run with `--fail-first 1`; assert the request log contains `500` then `202` and the collector exits 0. Add a third run with `--delay 2`; while the collector is running, locate the curl process and assert its `/proc/<pid>/cmdline` does not contain the token. Assert no `*.curl` or curl-config temp file remains in `$RUN_DIR`.

- [ ] **Step 2: Run the test and verify it fails**

Run: `bash tools/tests/http_test.sh`
Expected: FAIL because `post_payload` and the mock endpoint do not exist.

- [ ] **Step 3: Implement `tools/mock-ingest.py`**

Use only Python's standard library (`http.server`, `json`, `argparse`, `threading`). `POST /api/v1/ingest` requires `Authorization: Bearer <token>`; reject bad tokens with 401, invalid JSON with 400, bodies over 65536 bytes with 413, and anything else with 405. Return `202 {"ok":true}` on success. `GET /health` returns `200 {"ok":true}` without auth. `--fail-first N` returns 500 for the first N valid requests. `--delay SECONDS` sleeps before responding. `--log FILE` appends `METHOD PATH STATUS BYTES`. `--dump-dir DIR` writes accepted bodies as `payload-<timestamp>-<pid>.json`. Never log the token.

- [ ] **Step 4: Implement `post_payload`**

Create a 600 temp curl config in `$RUN_DIR`, delete it via trap. Put `Authorization`, `Content-Type`, `Accept`, `User-Agent: noc-agent/0.1.0`, and `X-Noc-Agent-Version: 0.1.0` in the config; use `data-binary = "@PAYLOAD_FILE"`. Use `--tlsv1.2` and `--proto '=https'`, or `=http,https` only when `ALLOW_INSECURE_HTTP=1`. Capture the HTTP code with `--write-out '%{http_code}'` and the body in a temp file. Return 0 for 2xx, retry 429/5xx/000 up to 3 attempts with 1s/3s backoff, and return 3 after failure. Do not retry 400/401/403/413/422. Enforce `MAX_RUN_SECONDS`.

- [ ] **Step 5: Run the test and verify it passes**

Run: `bash tools/tests/http_test.sh`
Expected: `http_test: PASS`.

- [ ] **Step 6: Update the runner and commit**

```bash
bash tools/tests/http_test.sh
git add agent tools
git commit -m "feat: add token-safe HTTPS ingest and mock endpoint"
```

---

### Task 10: Payload-size fallback and error paths

**Files:**
- Modify: `agent/collector.sh`
- Create: `tools/tests/payload_test.sh`
- Modify: `tools/test-agent.sh`

**Interfaces:**
- Consumes: `build_payload`, `post_payload`.
- Produces: `send_with_fallback PANEL_FILE EXT_FILE` → writes `$PAYLOAD_FILE`, applying the 64 KiB drop order and exit 2 when the core cannot fit.

- [ ] **Step 1: Write the failing payload test**

Create `tools/tests/payload_test.sh` with a fake panel/module dir. A fake panel emits 100 string metrics of 1000 characters. Run `--dry-run --max-payload-bytes 4096`; assert `panel_metrics == {}`, `extensions` still has a small module value, and the core metrics remain. Then make the extension also large and assert `extensions == {}`. Finally run with a forced tiny limit (for example `--max-payload-bytes 100`); assert exit 2 and a clear error on stderr.

- [ ] **Step 2: Run the test and verify it fails**

Run: `bash tools/tests/payload_test.sh`
Expected: FAIL because the payload fallback does not exist.

- [ ] **Step 3: Implement `send_with_fallback`**

Build the full payload to a temp file. If its size exceeds `MAX_PAYLOAD_BYTES`, rebuild with `/dev/null` as the panel file and log a warning. If still too large, rebuild with `/dev/null` for both panel and extensions. If the core-only payload is still too large, log an error and return 2. Send only the final payload. Keep all temp files in the cleanup trap.

- [ ] **Step 4: Run the test and verify it passes**

Run: `bash tools/tests/payload_test.sh`
Expected: `payload_test: PASS`.

- [ ] **Step 5: Update the runner and commit**

```bash
bash tools/tests/payload_test.sh
git add agent tools
git commit -m "feat: drop optional metrics before failing oversized payloads"
```

---

### Task 11: systemd units, config example, installer, and uninstaller

**Files:**
- Create: `agent/systemd/noc-agent.service`
- Create: `agent/systemd/noc-agent.timer.in`
- Create: `agent/systemd/noc-agent@.service`
- Create: `agent/systemd/noc-agent-smart.timer`
- Create: `agent/systemd/noc-agent-diskquota.timer`
- Create: `agent/systemd/noc-agent-imunify.timer`
- Create: `agent/config/agent.conf.example`
- Create: `agent/install.sh`
- Create: `agent/uninstall.sh`
- Create: `tools/tests/install_test.sh`
- Modify: `tools/test-agent.sh`

**Interfaces:**
- Consumes: all agent scripts and module units.
- Produces: an idempotent installer and uninstaller with `--prefix`, `--dry-run`, `--no-systemctl`, `--no-prime`, `--force`, `--test`, and `--purge`.

- [ ] **Step 1: Write the failing installer test**

Create `tools/tests/install_test.sh`:

```bash
#!/bin/bash
set -euo pipefail
cd "$(dirname "$0")/../.."
tmp=$(mktemp -d); trap 'rm -rf "$tmp"' EXIT
printf 'abcdefghijklmnop\n' > "$tmp/token"; chmod 600 "$tmp/token"
agent/install.sh --prefix "$tmp" --api-url "http://127.0.0.1:18787/api/v1/ingest" \
  --token-file "$tmp/token" --interval 30 --no-systemctl --no-prime
[ -x "$tmp/usr/local/libexec/noc-agent/collector.sh" ] || exit 1
[ -f "$tmp/etc/systemd/system/noc-agent.timer" ] || exit 1
grep -q 'OnUnitActiveSec=30s' "$tmp/etc/systemd/system/noc-agent.timer" || exit 1
[ "$(stat -c %a "$tmp/etc/noc-agent/agent.conf")" = 600 ] || exit 1
[ "$(stat -c %a "$tmp/etc/noc-agent/token")" = 600 ] || exit 1
agent/collector.sh --dry-run --config "$tmp/etc/noc-agent/agent.conf" \
  --state-dir "$tmp/var/lib/noc-agent" >/dev/null
agent/uninstall.sh --prefix "$tmp" --no-systemctl
[ ! -e "$tmp/usr/local/libexec/noc-agent" ] || exit 1
[ -e "$tmp/etc/noc-agent/agent.conf" ] || exit 1
agent/install.sh --prefix "$tmp" --token-file "$tmp/token" --no-systemctl --no-prime >/dev/null
agent/uninstall.sh --prefix "$tmp" --no-systemctl --purge
[ ! -e "$tmp/etc/noc-agent" ] || exit 1
echo "install_test: PASS"
```

- [ ] **Step 2: Run the test and verify it fails**

Run: `bash tools/tests/install_test.sh`
Expected: FAIL because the installer does not exist.

- [ ] **Step 3: Add the systemd units and config example**

Main service: `Type=oneshot`, `User=root`, `Group=root`, `ExecStart=/usr/local/libexec/noc-agent/collector.sh`, `TimeoutStartSec=50`, `Nice=10`, `IOSchedulingClass=best-effort`, `IOSchedulingPriority=7`, `StateDirectory=noc-agent`, `StateDirectoryMode=0700`, `RuntimeDirectory=noc-agent`, `RuntimeDirectoryMode=0700`, `UMask=0077`, and the hardening list from the spec. Template service uses `ExecStart=... --module %i` and `TimeoutStartSec=300`. Timers use the exact schedules in the spec: smart `*-*-01 03:15:00` + 15m randomized delay + persistent; diskquota `*-*-* 03:45:00` + 10m + persistent; imunify `OnBootSec=2m`, `OnUnitActiveSec=5m`; main `OnBootSec=30s`, `OnUnitActiveSec=@INTERVAL@`.

The config example must contain every key from the spec with the documented defaults, including the `ENABLE_*` flags and all module limits. It must not contain a real token.

- [ ] **Step 4: Implement `install.sh`**

Resolve `ROOT` from `--prefix`; map `LIBEXEC`, `CONF_DIR`, `STATE_DIR`, and `UNIT_DIR` under it. Refuse a non-root install when `ROOT=/`; allow non-root when `--prefix` is set for tests. `--dry-run` prints commands and changes nothing. Install scripts with mode 700 and root ownership when root; install config example to `agent.conf` only when absent or `--force`; install the token from `--token-file` with mode 600; generate the main timer from `@INTERVAL@`; copy the three external timers. Run `restorecon -RF` only when available. Unless `--no-systemctl`, run `daemon-reload`, enable and start the four timers, then prime caches with `systemctl start --no-block noc-agent@smart.service noc-agent@diskquota.service noc-agent@imunify.service` unless `--no-prime`. `--test` runs a dry-run of the installed collector.

- [ ] **Step 5: Implement `uninstall.sh` and run the test**

`uninstall.sh` stops/disables the four timers, removes the unit files and `LIBEXEC`, and reloads systemd unless `--no-systemctl`. It keeps `CONF_DIR` and `STATE_DIR` by default; `--purge` removes them. Run:

`bash tools/tests/install_test.sh`
Expected: `install_test: PASS`.

- [ ] **Step 6: Verify the units and commit**

Run `systemd-analyze verify` against the installed units, or against the source units after substituting a test interval and creating a dummy executable at the `ExecStart` path. Investigate and fix any warnings; do not suppress them blindly.

```bash
bash tools/tests/install_test.sh
git add agent tools
git commit -m "feat: add systemd units and idempotent installer"
```

---

### Task 12: End-to-end runner, documentation, and final verification

**Files:**
- Modify: `tools/test-agent.sh`
- Create: `agent/README.md`
- Modify: `README.md` (create if absent; keep the legacy collector note)
- Modify: `docs/superpowers/specs/2026-11-22-noc-agent-foundation-design.md` only if implementation reveals a necessary clarification.

**Interfaces:**
- Produces: one command that runs every test; operator-facing install/run/troubleshoot documentation; final acceptance evidence.

- [ ] **Step 1: Make `tools/test-agent.sh` run every test**

Run, in order: `json_test.sh`, `common_test.sh`, `core_test.sh`, `panel_test.sh`, `module_test.sh`, `smart_test.sh`, `diskquota_test.sh`, `imunify_test.sh`, `http_test.sh`, `payload_test.sh`, `install_test.sh`. Then run `bash -n` on every `agent/**/*.sh` and `tools/**/*.sh`, and ShellCheck if available. Print `test-agent: ALL PASS` only when all pass.

- [ ] **Step 2: Write the operator documentation**

Document: install prerequisites (`root`, `curl`, `flock`, `systemd`), token creation, `install.sh` examples, how to inspect timers, how to run a safe dry-run, cache locations and cache-age fields, how to rotate the token, how to uninstall, and the fact that RAID is not monitored. Mention the optional `python3` requirement for Imunify only. Include the legacy `collector-cpanel.sh` note so operators do not confuse it with the new agent.

- [ ] **Step 3: Run the full local verification**

```bash
bash tools/test-agent.sh
SHELLCHECK=/tmp/opencode/shellcheck-v0.10.0/shellcheck
"$SHELLCHECK" -S style agent/collector.sh agent/lib/*.sh agent/panels/*.sh agent/modules/*.sh agent/install.sh agent/uninstall.sh tools/tests/*.sh
tmp=$(mktemp -d); trap 'rm -rf "$tmp"' EXIT
mkdir -p "$tmp/state"
cat > "$tmp/agent.conf" <<EOF
PANEL=none
STATE_DIR="$tmp/state"
API_URL="http://127.0.0.1:18787/api/v1/ingest"
ALLOW_INSECURE_HTTP=1
SERVER_TOKEN_FILE="$tmp/token"
EOF
chmod 600 "$tmp/agent.conf"
printf 'abcdefghijklmnop\n' > "$tmp/token"; chmod 600 "$tmp/token"
agent/collector.sh --dry-run --config "$tmp/agent.conf" | python3 -m json.tool >/dev/null
```

The dry-run command is the real acceptance test: a non-panel payload must be valid JSON. Verify exit codes 0/1/2/3/4 with the tests.

- [ ] **Step 4: Run a mock end-to-end check**

Start `tools/mock-ingest.py` with a test token, run the installed collector in a temp prefix, and confirm a 202 plus a stored payload. Confirm a bad token returns 3 with one request and no token in the logs.

- [ ] **Step 5: Commit and hand off**

```bash
git add .
git commit -m "docs: document NOC agent installation and verification"
```

After the commit, report the test output and any deviations from the plan. Do not deploy to a real server in this task.

---

## Self-Review Notes

- **Spec coverage:** Tasks 1-2 cover config/security/token/ID/lock; Task 3 covers core metrics/schema; Task 4 covers panel detection and cPanel; Tasks 5-8 cover module runtime and all four modules; Task 9 covers API/retry/token secrecy/mock; Task 10 covers payload fallback; Task 11 covers systemd/install; Task 12 covers acceptance and docs.
- **Type consistency:** `json_object_entries`, `build_payload`, `post_payload`, `run_panel`, `run_modules`, and `run_module_mode` are named consistently across tasks. Every module emits the `<module>.` prefix declared in its task.
- **Review Focus mapping:** malformed output → Tasks 1/4/5; token secrecy → Task 9; unsafe paths → Tasks 2/5; cache staleness → Tasks 5-8; HTTP deadline/retry → Task 9.
- **Known builder calls:** Imunify uses `python3` only when present and skips otherwise; `--max-payload-bytes`, `--panel-dir`, `--module-dir`, and `--run-dir` are test/operability options; `--prefix` permits non-root test installs while `/` requires root; RAID is deliberately absent.
- **Proportion:** The plan carries interfaces and tests, not full function bodies. Implementers should read the approved spec for exact payload fields and security rules.


