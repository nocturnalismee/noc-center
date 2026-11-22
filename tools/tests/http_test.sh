#!/bin/bash
# HTTP client tests: token secrecy, retry policy, and mock ingest endpoint.
#
# Every request goes to a loopback-only mock server; nothing leaves the host.
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

mkdir -p "$tmp/run" "$tmp/state" "$tmp/dump"
chmod 700 "$tmp/run" "$tmp/state" "$tmp/dump"

GOOD_TOKEN=goodtoken123456789
PORT=18787
BASE="http://127.0.0.1:$PORT"

cat > "$tmp/agent.conf" <<EOF
API_URL="$BASE/api/v1/ingest"
ALLOW_INSECURE_HTTP=1
SERVER_TOKEN_FILE="$tmp/token"
STATE_DIR="$tmp/state"
PANEL=none
INTERVAL_SECONDS=60
EOF
chmod 600 "$tmp/agent.conf"
printf '%s\n' "$GOOD_TOKEN" > "$tmp/token"
chmod 600 "$tmp/token"

fail() { echo "http_test: FAIL: $*" >&2; exit 1; }

# log_since LOG -> lines appended by the run started after `mark=$(wc -l < LOG)`.
mark=0
log_since() {
  local log=$1
  tail -n "+$((mark + 1))" "$log" 2>/dev/null || true
}

# start_mock PORT LOG DUMP [extra args...] -> launch the mock and wait for health.
start_mock() {
  local port=$1 log=$2 dump=$3
  shift 3
  python3 tools/mock-ingest.py --port "$port" --token "$GOOD_TOKEN" \
    --log "$log" --dump-dir "$dump" "$@" >"$tmp/mock.out" 2>&1 &
  mock_pid=$!
  local i
  for ((i = 0; i < 100; i++)); do
    if curl -fsS -o /dev/null --max-time 1 "$BASE/health" 2>/dev/null; then
      return 0
    fi
    if ! kill -0 "$mock_pid" 2>/dev/null; then
      fail "mock server exited: $(cat "$tmp/mock.out" 2>/dev/null)"
    fi
    sleep 0.1
  done
  fail "mock server did not become healthy"
}

stop_mock() {
  [[ -n $mock_pid ]] || return 0
  kill "$mock_pid" 2>/dev/null || true
  wait "$mock_pid" 2>/dev/null || true
  mock_pid=""
}

# assert_no_payload_residue -> no built payload or curl temp file in TMPDIR.
assert_no_payload_residue() {
  local left
  left=$(find "${TMPDIR:-/tmp}" -maxdepth 1 -name 'noc-agent.payload.*' -print -quit 2>/dev/null)
  [[ -z $left ]] || fail "payload temp file left behind: $left"
}

# assert_no_curl_residue DIR -> no curl config/temp file may survive a run.
assert_no_curl_residue() {
  local dir=$1 left
  left=$(find "$dir" -maxdepth 1 -name '*.curl*' -o -maxdepth 1 -name '*curl-config*' 2>/dev/null)
  [[ -z $left ]] || fail "curl temp file left behind in $dir: $left"
}

# --- 1. valid token -> exit 0, one 202, one JSON dump -----------------------
start_mock "$PORT" "$tmp/requests.log" "$tmp/dump"
mark=$(wc -l < "$tmp/requests.log")
rc=0
agent/collector.sh --config "$tmp/agent.conf" --run-dir "$tmp/run" 2>>"$tmp/collector.err" || rc=$?
[[ $rc -eq 0 ]] || fail "valid token: expected exit 0, got $rc"
grep -q ' 202 ' "$tmp/requests.log" || fail "valid token: no 202 in request log"
[[ $(log_since "$tmp/requests.log" | grep -c 'POST /api/v1/ingest 202') -eq 1 ]] \
  || fail "valid token: expected exactly one 202"
count=$(find "$tmp/dump" -type f | wc -l)
[[ $count -eq 1 ]] || fail "valid token: expected one dump, got $count"
python3 -m json.tool "$tmp/dump"/*.json >/dev/null \
  || fail "valid token: dumped payload is not valid JSON"
assert_no_curl_residue "$tmp/run"
assert_no_payload_residue
stop_mock

# --- 2. bad token -> exit 3, exactly one 401, no retry ---------------------
printf 'badtoken123456789\n' > "$tmp/token"
chmod 600 "$tmp/token"
start_mock "$PORT" "$tmp/requests.log" "$tmp/dump"
mark=$(wc -l < "$tmp/requests.log")
rc=0
agent/collector.sh --config "$tmp/agent.conf" --run-dir "$tmp/run" 2>>"$tmp/collector.err" || rc=$?
[[ $rc -eq 3 ]] || fail "bad token: expected exit 3, got $rc"
[[ $(log_since "$tmp/requests.log" | grep -c ' 401 ') -eq 1 ]] \
  || fail "bad token: expected exactly one 401 (no retry)"
assert_no_payload_residue
! grep -q 'badtoken123456789' "$tmp/requests.log" || fail "bad token: token leaked into request log"
! grep -q 'badtoken123456789' "$tmp/mock.out" || fail "bad token: token leaked into mock output"
[[ $(find "$tmp/dump" -type f | wc -l) -eq 1 ]] || fail "bad token: rejected payload must not be dumped"
assert_no_curl_residue "$tmp/run"
stop_mock
printf '%s\n' "$GOOD_TOKEN" > "$tmp/token"
chmod 600 "$tmp/token"

# --- 3. first attempt 500, then 202 ---------------------------------------
start_mock "$PORT" "$tmp/requests.log" "$tmp/dump" --fail-first 1
mark=$(wc -l < "$tmp/requests.log")
rc=0
agent/collector.sh --config "$tmp/agent.conf" --run-dir "$tmp/run" 2>>"$tmp/collector.err" || rc=$?
[[ $rc -eq 0 ]] || fail "fail-first: expected exit 0, got $rc"
codes=$(log_since "$tmp/requests.log" | awk '/POST \/api\/v1\/ingest/ {print $3}' | tr '\n' ' ')
[[ $codes == "500 202 " ]] || fail "fail-first: expected 500 then 202, got '$codes'"
[[ $(find "$tmp/dump" -type f | wc -l) -eq 2 ]] || fail "fail-first: expected two dumps total"
assert_no_curl_residue "$tmp/run"
stop_mock

# --- 3b. 429 is retried (same policy as 5xx) -----------------------------
start_mock "$PORT" "$tmp/requests.log" "$tmp/dump" --fail-first 1 --fail-status 429
mark=$(wc -l < "$tmp/requests.log")
rc=0
agent/collector.sh --config "$tmp/agent.conf" --run-dir "$tmp/run" 2>>"$tmp/collector.err" || rc=$?
[[ $rc -eq 0 ]] || fail "429: expected exit 0, got $rc"
codes=$(log_since "$tmp/requests.log" | awk '/POST \/api\/v1\/ingest/ {print $3}' | tr '\n' ' ')
[[ $codes == "429 202 " ]] || fail "429: expected 429 then 202, got '$codes'"
stop_mock

# --- 4. 400 -> no retry ----------------------------------------------------
start_mock "$PORT" "$tmp/requests.log" "$tmp/dump" --status-400
mark=$(wc -l < "$tmp/requests.log")
rc=0
agent/collector.sh --config "$tmp/agent.conf" --run-dir "$tmp/run" 2>>"$tmp/collector.err" || rc=$?
[[ $rc -eq 3 ]] || fail "400: expected exit 3, got $rc"
[[ $(log_since "$tmp/requests.log" | grep -c ' 400 ') -eq 1 ]] || fail "400: expected exactly one request"
assert_no_curl_residue "$tmp/run"
stop_mock

# --- 5. 413 -> no retry ----------------------------------------------------
start_mock "$PORT" "$tmp/requests.log" "$tmp/dump" --max-bytes 100
mark=$(wc -l < "$tmp/requests.log")
rc=0
agent/collector.sh --config "$tmp/agent.conf" --run-dir "$tmp/run" 2>>"$tmp/collector.err" || rc=$?
[[ $rc -eq 3 ]] || fail "413: expected exit 3, got $rc"
[[ $(log_since "$tmp/requests.log" | grep -c ' 413 ') -eq 1 ]] || fail "413: expected exactly one request"
assert_no_curl_residue "$tmp/run"
stop_mock

# --- 6. network failure -> retries -> exit 3 -------------------------------
rc=0
agent/collector.sh --config "$tmp/agent.conf" --run-dir "$tmp/run" 2>"$tmp/refused.err" || rc=$?
[[ $rc -eq 3 ]] || fail "connection refused: expected exit 3, got $rc"
assert_no_payload_residue
! grep -q "$GOOD_TOKEN" "$tmp/refused.err" || fail "token leaked into network-error log"
assert_no_curl_residue "$tmp/run"

# --- 7. token never in curl argv (slow server keeps curl alive) -----------
start_mock "$PORT" "$tmp/requests.log" "$tmp/dump" --delay 2
agent/collector.sh --config "$tmp/agent.conf" --run-dir "$tmp/run" >"$tmp/collector.out" 2>"$tmp/collector.err" &
collector_pid=$!
seen_curl=0
saw_cfg=0
for ((i = 0; i < 400; i++)); do
  for pid in $(pgrep -x curl 2>/dev/null || true); do
    cmdline=$(tr '\0' ' ' 2>/dev/null < "/proc/$pid/cmdline" || true)
    [[ -z $cmdline ]] && continue
    # Only the agent's own request (identified by its run-dir config path).
    [[ $cmdline == *"$tmp/run/noc-agent.curl."* ]] || continue
    seen_curl=1
    [[ $cmdline != *"$GOOD_TOKEN"* ]] || fail "token leaked into curl argv: $cmdline"
    envs=$(tr '\0' '\n' 2>/dev/null < "/proc/$pid/environ" || true)
    [[ $envs != *"$GOOD_TOKEN"* ]] || fail "token leaked into curl environment"
  done
  # 8. the live curl config is mode 600 and carries no token in its name.
  f=$(find "$tmp/run" -maxdepth 1 -type f -name 'noc-agent.curl.*' -print -quit 2>/dev/null || true)
  if [[ -n $f && -f $f ]]; then
    saw_cfg=1
    mode=$(stat -c '%a' "$f" 2>/dev/null) || mode=""
    [[ -z $mode || $mode == 600 ]] || fail "curl config mode is $mode, expected 600"
    # The writer may still be filling the file (or have just unlinked it);
    # only a complete file is checked.
    if grep -q '^write-out = ' "$f" 2>/dev/null; then
      grep -q '^header = "Authorization: Bearer ' "$f" 2>/dev/null || fail "curl config lacks the Authorization header"
      grep -q '^header = "X-Noc-Agent-Version: 0.1.0"' "$f" 2>/dev/null || fail "curl config lacks the version header"
    fi
  fi
  kill -0 "$collector_pid" 2>/dev/null || break
  sleep 0.02
done
rc=0
wait "$collector_pid" || rc=$?
[[ $rc -eq 0 ]] || fail "delayed server: expected exit 0, got $rc"
[[ $seen_curl -eq 1 ]] || fail "delayed server: never observed the curl process"
[[ $saw_cfg -eq 1 ]] || fail "delayed server: never observed the temporary curl config"
! grep -q "$GOOD_TOKEN" "$tmp/collector.err" || fail "token leaked into collector stderr"
! grep -q "$GOOD_TOKEN" "$tmp/collector.out" || fail "token leaked into collector stdout"
assert_no_curl_residue "$tmp/run"
stop_mock

# --- 9. dry-run performs no HTTP and needs no token ------------------------
rm -f "$tmp/token"
start_mock "$PORT" "$tmp/dry.log" "$tmp/dump"
mark=$(wc -l < "$tmp/dry.log")
run_before=$(find "$tmp/run" -maxdepth 1 -type f | sort)
out=$(agent/collector.sh --dry-run --config "$tmp/agent.conf" --run-dir "$tmp/run")
printf '%s' "$out" | python3 -m json.tool >/dev/null || fail "dry-run output is not JSON"
run_after=$(find "$tmp/run" -maxdepth 1 -type f | sort)
[[ $run_before == "$run_after" ]] || fail "dry-run wrote files into the run dir"
[[ -z $(log_since "$tmp/dry.log" | grep 'POST') ]] || fail "dry-run sent an HTTP request"
[[ $run_before == "$run_after" ]] || fail "dry-run wrote files into the run dir"
stop_mock

# --- 9. retry classification for the codes not covered by a mock run ------
# Sourced, not executed: no main, so these helpers are pure.
(
  # shellcheck source=../../agent/collector.sh
  source agent/collector.sh
  [ "$(_http_status_class 200)" = success ] || exit 1
  [ "$(_http_status_class 202)" = success ] || exit 1
  [ "$(_http_status_class 204)" = success ] || exit 1
  for c in 400 401 403 413 422 404 409; do
    [ "$(_http_status_class "$c")" = reject ] || exit 1
  done
  for c in 429 500 502 503 504 000; do
    [ "$(_http_status_class "$c")" = retry ] || exit 1
  done
) || fail "HTTP status retry classification is wrong"

# --- 10. MAX_RUN_SECONDS deadline stops the retry loop --------------------
# Sourced, so the run budget can be set directly; the slow mock makes every
# attempt consume the whole budget.
TMP_RUN=$tmp/run
start_mock "$PORT" "$tmp/requests.log" "$tmp/dump" --fail-first 100 --delay 1
mark=$(wc -l < "$tmp/requests.log")
rc=0
(
  # shellcheck source=../../agent/collector.sh
  source agent/collector.sh
  config_defaults
  API_URL="$BASE/api/v1/ingest"
  ALLOW_INSECURE_HTTP=1
  SERVER_TOKEN="$GOOD_TOKEN"
  MAX_RUN_SECONDS=1
  CURL_TIMEOUT=8
  OPT_RUN_DIR=$TMP_RUN
  export TMP_RUN
  RUN_START_EPOCH=0
  printf '{}' > "$tmp/deadline.json"
  post_payload "$tmp/deadline.json"
) 2>"$tmp/deadline.err" || rc=$?
[[ $rc -eq 3 ]] || { cat "$tmp/deadline.err" >&2; fail "deadline: expected exit 3, got $rc"; }
reqs=$(log_since "$tmp/requests.log" | grep -c 'POST /api/v1/ingest' || true)
[[ $reqs -ge 1 && $reqs -le 2 ]] || fail "deadline: expected the loop to stop early, got $reqs attempts"
grep -Eq 'deadline|budget|not delivered' "$tmp/deadline.err" || fail "deadline: no stderr note about the deadline"
assert_no_curl_residue "$tmp/run"
stop_mock

echo "http_test: PASS"
