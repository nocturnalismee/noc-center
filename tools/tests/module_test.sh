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

# A disabled module in --module mode is a clean skip: exit 0, no output, and
# no cache file. The timer unit must not fail on a host where the module is
# turned off.
cat > "$tmp/agent-disabled.conf" <<EOF
PANEL=none
STATE_DIR="$tmp/state-disabled"
API_URL="http://127.0.0.1:18787/api/v1/ingest"
ALLOW_INSECURE_HTTP=1
SERVER_TOKEN_FILE="$tmp/token"
ENABLE_EXTERNAL=0
EOF
chmod 600 "$tmp/agent-disabled.conf"
mkdir -p "$tmp/state-disabled"
rc=0
out=$(agent/collector.sh --module external --config "$tmp/agent-disabled.conf" \
  --run-dir "$tmp/run" --module-dir "$tmp/modules" 2>"$tmp/disabled.err") || rc=$?
[ "$rc" -eq 0 ] || { echo "disabled module rc=$rc (want 0)" >&2; exit 1; }
[ -z "$out" ] || { echo "disabled module emitted metrics" >&2; exit 1; }
[ ! -e "$tmp/state-disabled/cache/external.metrics" ] || {
  echo "disabled module wrote a cache" >&2; exit 1
}
grep -q 'disabled' "$tmp/disabled.err" || { cat "$tmp/disabled.err" >&2; exit 1; }

# A module whose detect fails is not applicable: exit 0, no output, no cache,
# and collect must not run.
cat > "$tmp/modules/notapp.sh" <<EOF
#!/bin/bash
case "\${1:-}" in
  detect) exit 1 ;;
  mode) echo external ;;
  collect) touch "$tmp/notapp-ran"; printf 'notapp.value\tn\t1\n' ;;
  *) exit 2 ;;
esac
EOF
chmod +x "$tmp/modules/notapp.sh"
mkdir -p "$tmp/state-notapp"
rc=0
out=$(agent/collector.sh --module notapp --config "$tmp/agent.conf" \
  --state-dir "$tmp/state-notapp" --run-dir "$tmp/run" --module-dir "$tmp/modules" \
  2>"$tmp/notapp.err") || rc=$?
[ "$rc" -eq 0 ] || { echo "not-applicable module rc=$rc (want 0)" >&2; exit 1; }
[ -z "$out" ] || { echo "not-applicable module emitted metrics" >&2; exit 1; }
[ ! -e "$tmp/state-notapp/cache/notapp.metrics" ] || {
  echo "not-applicable module wrote a cache" >&2; exit 1
}
[ ! -e "$tmp/notapp-ran" ] || { echo "not-applicable module ran collect" >&2; exit 1; }
grep -q 'not applicable' "$tmp/notapp.err" || { cat "$tmp/notapp.err" >&2; exit 1; }

# detect exit >1 is a real error, not "not applicable": exit 4 and no cache.
cat > "$tmp/modules/detectbroken.sh" <<EOF
#!/bin/bash
case "\${1:-}" in
  detect) exit 2 ;;
  mode) echo external ;;
  collect) touch "$tmp/detectbroken-ran"; printf 'detectbroken.value\tn\t1\n' ;;
  *) exit 2 ;;
esac
EOF
chmod +x "$tmp/modules/detectbroken.sh"
mkdir -p "$tmp/state-detectbroken"
rc=0
out=$(agent/collector.sh --module detectbroken --config "$tmp/agent.conf" \
  --state-dir "$tmp/state-detectbroken" --run-dir "$tmp/run" --module-dir "$tmp/modules" \
  2>"$tmp/detectbroken.err") || rc=$?
[ "$rc" -eq 4 ] || { echo "detect-error module rc=$rc (want 4)" >&2; exit 1; }
[ -z "$out" ] || { echo "detect-error module emitted metrics" >&2; exit 1; }
[ ! -e "$tmp/state-detectbroken/cache/detectbroken.metrics" ] || {
  echo "detect-error module wrote a cache" >&2; exit 1
}
[ ! -e "$tmp/detectbroken-ran" ] || { echo "detect-error module ran collect" >&2; exit 1; }
grep -q 'detect failed' "$tmp/detectbroken.err" || { cat "$tmp/detectbroken.err" >&2; exit 1; }

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
