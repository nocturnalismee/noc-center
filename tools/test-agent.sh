#!/bin/bash
# Run every NOC agent test suite and the static checks.
#
#   1. the unit/contract suites, in order;
#   2. `bash -n` on every agent/**/*.sh and tools/**/*.sh;
#   3. ShellCheck (if available) on the agent scripts and the test suites;
#   4. the acceptance dry run: a non-panel `collector.sh --dry-run` payload
#      must be valid JSON (validated with `python3 -m json.tool`);
#   5. the mock end-to-end check: the installed collector posts a real payload
#      to tools/mock-ingest.py on loopback (202 + stored payload), and a bad
#      token gets exactly one 401, exit 3, and no token in the log.
#
# `test-agent: ALL PASS` is printed only when every step passes.
#
# ShellCheck selection: $SHELLCHECK when set, else `shellcheck` on PATH, else
# the static binary under /tmp/opencode (if the implementer unpacked it).
# ShellCheck is optional (the design says "if available").
#
set -euo pipefail
cd "$(dirname "$0")/.."

SUITES=(
  json_test.sh
  metrics_test.sh
  common_test.sh
  core_test.sh
  panel_test.sh
  module_test.sh
  smart_test.sh
  diskquota_test.sh
  imunify_test.sh
  http_test.sh
  payload_test.sh
  install_test.sh
  update_test.sh
)

# The loopback port for the mock end-to-end check. http_test.sh also uses
# 18787, but it has already exited (and released the port) by the time this
# runs; override with NOC_AGENT_TEST_PORT if needed.
PORT=${NOC_AGENT_TEST_PORT:-18787}
TOKEN=abcdefghijklmnop

TMPDIR_T="$(mktemp -d "${TMPDIR:-/tmp}/test-agent.XXXXXX")"
MOCK_PID=""
cleanup() {
  if [ -n "$MOCK_PID" ]; then
    kill "$MOCK_PID" 2>/dev/null || true
    wait "$MOCK_PID" 2>/dev/null || true
  fi
  rm -rf "$TMPDIR_T"
}
trap cleanup EXIT
chmod 700 "$TMPDIR_T"

fail() { printf 'test-agent: FAIL: %s\n' "$*" >&2; exit 1; }

# wait_for_health URL -> poll the mock's unauthenticated /health endpoint.
wait_for_health() {
  local url=$1
  local http_status attempt=0
  while [ "$attempt" -lt 10 ]; do
    attempt=$((attempt + 1))
    http_status=$(curl -sS -o /dev/null -w '%{http_code}' --max-time 2 "$url" 2>/dev/null || true)
    [ "$http_status" = 200 ] && return 0
    sleep 0.2
  done
  return 1
}

echo "test-agent: unit and contract suites"
for s in "${SUITES[@]}"; do
  [ -f "tools/tests/$s" ] || fail "missing suite tools/tests/$s"
  bash "tools/tests/$s"
done

echo "test-agent: bash -n"
shopt -s nullglob
scripts=(agent/*.sh agent/*/*.sh tools/*.sh tools/tests/*.sh)
shopt -u nullglob
[ "${#scripts[@]}" -gt 0 ] || fail "no shell scripts found"
# `bash -n` reads exactly one script: every further argument is that script's
# $1, $2, … and is silently not parsed. So check each file in its own call.
for f in "${scripts[@]}"; do
  bash -n "$f" || fail "syntax error in $f"
done
echo "test-agent: syntax OK (${#scripts[@]} files)"

echo "test-agent: shellcheck"
SC=""
if [ -n "${SHELLCHECK:-}" ] && [ -x "$SHELLCHECK" ]; then
  SC=$SHELLCHECK
elif command -v shellcheck >/dev/null 2>&1; then
  SC=$(command -v shellcheck)
else
  for c in /tmp/opencode/shellcheck-v0.10.0/shellcheck \
           /tmp/opencode/*/shellcheck /tmp/opencode/shellcheck; do
    if [ -x "$c" ]; then SC=$c; break; fi
  done
fi
# SC2034 ("appears unused") is a false positive for this codebase: the config,
# installer, and token helpers assign variables in the parent shell that
# sourced libraries and later code read (SERVER_TOKEN, STATE_DIR, OPT_PREFIX,
# ...), and for variables set inside a $(...) subshell. SC1091 is "cannot
# follow `source`", which the `# shellcheck source=` comments already annotate.
# Both are exempted; every other warning/error fails.
SC_EXCLUDE=(SC2034 SC1091)
if [ -n "$SC" ]; then
  "$SC" -S warning -e "$(IFS=,; echo "${SC_EXCLUDE[*]}")" \
    agent/collector.sh agent/lib/*.sh agent/panels/*.sh \
    agent/modules/*.sh agent/install.sh agent/update.sh agent/uninstall.sh \
    tools/test-agent.sh tools/tests/*.sh \
    || fail "shellcheck reported problems ($SC)"
  echo "test-agent: shellcheck OK ($SC)"
else
  echo "test-agent: shellcheck not available; skipped (set SHELLCHECK=/path)"
fi

echo "test-agent: acceptance dry run (non-panel JSON)"
mkdir -p "$TMPDIR_T/state"
chmod 700 "$TMPDIR_T/state"
cat > "$TMPDIR_T/agent.conf" <<EOF
PANEL=none
STATE_DIR="$TMPDIR_T/state"
API_URL="http://127.0.0.1:$PORT/api/v1/ingest"
ALLOW_INSECURE_HTTP=1
SERVER_TOKEN_FILE="$TMPDIR_T/token"
EOF
chmod 600 "$TMPDIR_T/agent.conf"
printf '%s\n' "$TOKEN" > "$TMPDIR_T/token"
chmod 600 "$TMPDIR_T/token"
if ! agent/collector.sh --dry-run --config "$TMPDIR_T/agent.conf" > "$TMPDIR_T/payload.json"; then
  fail "collector --dry-run exited non-zero"
fi
python3 -m json.tool < "$TMPDIR_T/payload.json" > "$TMPDIR_T/pretty.json" \
  || fail "dry-run payload is not valid JSON"
# The payload must be a complete non-panel schema-v1 document.
python3 - "$TMPDIR_T/payload.json" <<'PY' || fail "dry-run payload failed the schema check"
import json, sys
doc = json.load(open(sys.argv[1]))
assert doc["schema_version"] == 1, "schema_version"
assert doc["agent"]["panel"] == "none", "panel must be none"
assert isinstance(doc["panel_metrics"], dict) and doc["panel_metrics"] == {}, "panel_metrics"
assert isinstance(doc["extensions"], dict), "extensions"
for path in (("metrics","cpu","usage_pct"), ("metrics","memory","used_pct"),
             ("metrics","swap","used_pct"), ("metrics","disk","root","inode_pct"),
             ("metrics","network","rx_bytes_per_sec"),
             ("metrics","processes","running"), ("system","uptime_seconds")):
    cur = doc
    for key in path:
        assert key in cur, "missing " + ".".join(path)
        cur = cur[key]
PY
echo "test-agent: dry run JSON OK"

echo "test-agent: mock end-to-end"
mkdir -p "$TMPDIR_T/e2e/dump" "$TMPDIR_T/e2e/run"
chmod 700 "$TMPDIR_T/e2e" "$TMPDIR_T/e2e/dump" "$TMPDIR_T/e2e/run"
python3 tools/mock-ingest.py --port "$PORT" --token "$TOKEN" \
  --log "$TMPDIR_T/e2e/requests.log" --dump-dir "$TMPDIR_T/e2e/dump" \
  2> "$TMPDIR_T/e2e/mock.err" &
MOCK_PID=$!
wait_for_health "http://127.0.0.1:$PORT/health" || fail "mock ingest did not start"

# Install into a scratch prefix (no systemctl, no host changes) and run the
# *installed* collector against the mock.
printf '%s\n' "$TOKEN" > "$TMPDIR_T/e2e/token"
chmod 600 "$TMPDIR_T/e2e/token"
agent/install.sh --prefix "$TMPDIR_T/e2e/prefix" \
  --api-url "http://127.0.0.1:$PORT/api/v1/ingest" \
  --token-file "$TMPDIR_T/e2e/token" --no-systemctl --no-prime > /dev/null
PREFIX="$TMPDIR_T/e2e/prefix"
CONF="$PREFIX/etc/noc-agent/agent.conf"
STATE="$PREFIX/var/lib/noc-agent"
COLLECTOR="$PREFIX/usr/local/libexec/noc-agent/collector.sh"
[ -x "$COLLECTOR" ] || fail "installed collector missing"

mkdir -p "$TMPDIR_T/e2e/run"
rc=0
"$COLLECTOR" --config "$CONF" --state-dir "$STATE" --run-dir "$TMPDIR_T/e2e/run" \
  > "$TMPDIR_T/e2e/run1.out" 2>&1 || rc=$?
[ "$rc" -eq 0 ] || { cat "$TMPDIR_T/e2e/run1.out" >&2; fail "valid token: expected exit 0, got $rc"; }
grep -qx 'POST /api/v1/ingest 202 [0-9]*' "$TMPDIR_T/e2e/requests.log" \
  || { cat "$TMPDIR_T/e2e/requests.log" >&2; fail "expected one 202 response"; }
[ "$(grep -c 'POST /api/v1/ingest 202' "$TMPDIR_T/e2e/requests.log")" -eq 1 ] \
  || fail "expected exactly one accepted request"
dump=$(find "$TMPDIR_T/e2e/dump" -maxdepth 1 -type f -name 'payload-*.json' | head -1)
[ -n "$dump" ] || fail "mock did not store a payload"
python3 -m json.tool < "$dump" > /dev/null \
  || fail "stored payload is not valid JSON"
echo "test-agent: installed collector -> 202 and stored payload"

# Bad token: the server must see exactly one 401, the agent must exit 3, and
# the token must never appear in the agent's output.
printf '%s\n' 'badtoken123456789' > "$TMPDIR_T/e2e/token.bad"
chmod 600 "$TMPDIR_T/e2e/token.bad"
agent/install.sh --prefix "$TMPDIR_T/e2e/prefix-bad" \
  --api-url "http://127.0.0.1:$PORT/api/v1/ingest" \
  --token-file "$TMPDIR_T/e2e/token.bad" --no-systemctl --no-prime > /dev/null
BADPREFIX="$TMPDIR_T/e2e/prefix-bad"
mkdir -p "$TMPDIR_T/e2e/run-bad"
: > "$TMPDIR_T/e2e/requests.log"
rc=0
"$BADPREFIX/usr/local/libexec/noc-agent/collector.sh" \
  --config "$BADPREFIX/etc/noc-agent/agent.conf" \
  --state-dir "$BADPREFIX/var/lib/noc-agent" \
  --run-dir "$TMPDIR_T/e2e/run-bad" > "$TMPDIR_T/e2e/run2.out" 2>&1 || rc=$?
[ "$rc" -eq 3 ] || { cat "$TMPDIR_T/e2e/run2.out" >&2; fail "bad token: expected exit 3, got $rc"; }
[ "$(wc -l < "$TMPDIR_T/e2e/requests.log")" -eq 1 ] \
  || { cat "$TMPDIR_T/e2e/requests.log" >&2; fail "bad token: expected exactly one request"; }
grep -q ' 401 ' "$TMPDIR_T/e2e/requests.log" || fail "bad token: expected a 401"
if grep -q 'badtoken123456789' "$TMPDIR_T/e2e/run2.out" "$TMPDIR_T/e2e/requests.log"; then
  fail "bad token value leaked into the agent output or the request log"
fi
echo "test-agent: bad token -> one 401, exit 3, no token in logs"

echo "test-agent: ALL PASS"