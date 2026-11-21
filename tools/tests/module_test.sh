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
