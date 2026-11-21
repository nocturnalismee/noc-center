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
