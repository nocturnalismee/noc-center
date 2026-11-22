#!/bin/bash
# Payload-size fallback tests: drop all panel_metrics first, then all
# extensions, and exit 2 when even the core payload cannot fit.
#
# Everything runs with --dry-run against a loopback-only mock server used as a
# tripwire: the dry run must never send an HTTP request.
set -euo pipefail
cd "$(dirname "$0")/../.."
tmp=$(mktemp -d)
mock_pid=""
cleanup() {
  if [[ -n $mock_pid ]]; then
    kill "$mock_pid" 2>/dev/null || true
    wait "$mock_pid" 2>/dev/null || true
  fi
  rm -rf "$tmp"
}
trap cleanup EXIT
chmod 700 "$tmp"

mkdir -p "$tmp/panels" "$tmp/modules" "$tmp/state" "$tmp/run" "$tmp/dump" "$tmp/no-modules"
chmod 700 "$tmp/panels" "$tmp/modules" "$tmp/state" "$tmp/run" "$tmp/dump" "$tmp/no-modules"

PORT=18791
BASE="http://127.0.0.1:$PORT"
TOKEN=payloadtoken1234567

fail() { echo "payload_test: FAIL: $*" >&2; exit 1; }
: > "$tmp/collector.err"

# The panel emits 100 string metrics of 1000 characters (about 103 KiB).
cat > "$tmp/panels/fake.sh" <<'EOF'
#!/bin/bash
case "${1:-}" in
  detect) exit 0 ;;
  collect)
    pad=$(printf 'p%.0s' $(seq 1 1000))
    for i in $(seq 1 100); do
      printf 'fake.m%03d\ts\t%s\n' "$i" "$pad"
    done
    ;;
  *) exit 2 ;;
esac
EOF
# The module emits a small inline extension (SMALL_VALUE controls its size).
cat > "$tmp/modules/fake.sh" <<'EOF'
#!/bin/bash
case "${1:-}" in
  detect) exit 0 ;;
  mode) echo inline ;;
  collect) printf 'fake.ext\ts\t%s\n' "${SMALL_VALUE:-small}" ;;
  *) exit 2 ;;
esac
EOF
chmod 755 "$tmp/panels/fake.sh" "$tmp/modules/fake.sh"

cat > "$tmp/agent.conf" <<EOF
API_URL="$BASE/api/v1/ingest"
ALLOW_INSECURE_HTTP=1
SERVER_TOKEN_FILE="$tmp/token"
STATE_DIR="$tmp/state"
PANEL=fake
INTERVAL_SECONDS=60
EOF
chmod 600 "$tmp/agent.conf"
printf '%s\n' "$TOKEN" > "$tmp/token"
chmod 600 "$tmp/token"

# run_dry LIMIT [ENV=VAL...] -> run the collector in dry-run mode and print JSON.
run_dry() {
  local limit=$1
  shift
  env "$@" agent/collector.sh --dry-run --config "$tmp/agent.conf" \
    --panel-dir "$tmp/panels" --module-dir "$tmp/modules" \
    --run-dir "$tmp/run" --max-payload-bytes "$limit" 2>>"$tmp/collector.err"
}

# log_since LOG -> stderr lines appended after the mark set by new_log_mark.
mark=0
new_log_mark() { mark=$(wc -l < "$tmp/collector.err" 2>/dev/null || true); mark=${mark//[[:space:]]/}; mark=${mark:-0}; }
log_since() { tail -n "+$((mark + 1))" "$1" 2>/dev/null || true; }

# A loopback mock server acts as an HTTP tripwire for every dry run below.
python3 tools/mock-ingest.py --port "$PORT" --token "$TOKEN" \
  --log "$tmp/requests.log" --dump-dir "$tmp/dump" >"$tmp/mock.out" 2>&1 &
mock_pid=$!
for ((i = 0; i < 100; i++)); do
  curl -fsS -o /dev/null --max-time 1 "$BASE/health" 2>/dev/null && break
  kill -0 "$mock_pid" 2>/dev/null || fail "mock server exited: $(cat "$tmp/mock.out" 2>/dev/null)"
  (( i == 99 )) && fail "mock server did not become healthy"
  sleep 0.1
done
: > "$tmp/requests.log"

# --- 1. oversized panel metrics are dropped, small extensions survive ------
new_log_mark
json=$(run_dry 4096)
python3 - "$json" "$TOKEN" <<'PY' || fail "panel fallback payload is wrong"
import json, sys
raw, token = sys.argv[1], sys.argv[2]
d = json.loads(raw)
assert token not in raw, "token leaked into the payload"
assert d["panel_metrics"] == {}, "panel_metrics must be emptied entirely"
assert d["extensions"] == {"fake.ext": "small"}, "small extension must survive"
assert d["extensions"].get("fake.ext") is not None
# Core metrics must remain intact.
assert d["schema_version"] == 1
assert d["agent"]["panel"] == "fake"
assert d["metrics"]["cpu"]["cores"] >= 1
assert d["metrics"]["memory"]["total_bytes"] > 0
assert d["metrics"]["disk"]["root"]["total_bytes"] > 0
assert d["metrics"]["network"]["rx_bytes_per_sec"] >= 0
assert d["system"]["os"]["kernel"]
PY
grep -Eq 'payload.*dropping all panel_metrics' <(log_since "$tmp/collector.err") \
  || fail "no warning about dropped panel_metrics"

# --- 2. oversized extensions -> they are emptied (no partial keys) -------
# The panel is forced to none here so extensions are the only oversized
# section; a small panel plus a large extension must still empty extensions.
big=$(printf 'x%.0s' $(seq 1 1000))
new_log_mark
json=$(env SMALL_VALUE="$big" agent/collector.sh --dry-run \
  --config "$tmp/agent.conf" --panel none --panel-dir "$tmp/panels" \
  --module-dir "$tmp/modules" --run-dir "$tmp/run" \
  --max-payload-bytes 1024 2>>"$tmp/collector.err")
python3 - "$json" <<'PY' || fail "extensions fallback payload is wrong"
import json, sys
d = json.loads(sys.argv[1])
assert d["panel_metrics"] == {}, "panel_metrics must stay empty"
assert d["extensions"] == {}, "extensions must be emptied entirely"
assert d["metrics"]["memory"]["total_bytes"] > 0
assert d["agent"]["id"]
PY
grep -Eq 'payload.*dropping all extensions' <(log_since "$tmp/collector.err") \
  || fail "no warning about dropped extensions"

# --- 3. core alone cannot fit -> exit 2, clear stderr, no send -------------
# Measure the core-only payload first so the forced limit always sits below it,
# whatever the host's integer sizes are.
core_only=$(agent/collector.sh --dry-run --config "$tmp/agent.conf" \
  --panel none --module-dir "$tmp/no-modules" \
  --run-dir "$tmp/run" 2>/dev/null) || fail "core-only dry run failed"
core_only=$(printf '%s' "$core_only" | tr -d '\n')
tiny=$(( ${#core_only} - 10 ))
(( tiny > 0 )) || fail "cannot derive a tiny payload limit"

new_log_mark
rc=0
out=$(run_dry "$tiny" SMALL_VALUE="$big") || rc=$?
[[ $rc -eq 2 ]] || fail "core too large: expected exit 2, got $rc"
[[ -z $out ]] || fail "core too large: nothing should be printed on stdout"
grep -Eqi 'payload.*(too large|exceed)|cannot.*core' <(log_since "$tmp/collector.err") \
  || fail "core too large: no clear error on stderr"

# --- 4. no dry run performed any HTTP or wrote into the run dir ------------
[[ -z $(cat "$tmp/requests.log" 2>/dev/null) ]] || fail "a dry run sent an HTTP request"
[[ -z $(find "$tmp/dump" -type f -print -quit) ]] || fail "a dry run reached the mock dump dir"
[[ -z $(find "$tmp/run" -mindepth 1 -print -quit) ]] || fail "a dry run wrote into the run dir"

# --- 5. default limit is 64 KiB -------------------------------------------
(
  # shellcheck source=../../agent/collector.sh
  source agent/collector.sh
  config_defaults
  [[ $MAX_PAYLOAD_BYTES == 65536 ]] || { echo "default $MAX_PAYLOAD_BYTES" >&2; exit 1; }
) || fail "default MAX_PAYLOAD_BYTES must be 65536"

# --- 6. no payload temp files are left behind ------------------------------
left=$(find "${TMPDIR:-/tmp}" -maxdepth 1 -name 'noc-agent.payload.*' -print -quit 2>/dev/null)
[[ -z $left ]] || fail "payload temp file left behind: $left"

echo "payload_test: PASS"