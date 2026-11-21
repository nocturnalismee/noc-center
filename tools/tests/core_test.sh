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
