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

# I2: a failed mktemp for the module temp file must not turn /dev/null into a
# cleanup target. Force only the collector's ext temp to fail, and replace rm
# with a wrapper that records any attempt to remove /dev/null (it would still
# forward everything else). A dry run must succeed, keep /dev/null intact, and
# never attempt that removal.
real_mktemp=$(command -v mktemp)
real_rm=$(command -v rm)
fakebin="$tmp/fakebin"; mkdir -p "$fakebin"
rmlog="$tmp/rm-devnull-attempts"
: > "$rmlog"
cat > "$fakebin/mktemp" <<EOF
#!/bin/bash
for a in "\$@"; do
  case \$a in
    *noc-agent.ext.*) echo "mktemp: forced failure" >&2; exit 1 ;;
  esac
done
exec "$real_mktemp" "\$@"
EOF
cat > "$fakebin/rm" <<EOF
#!/bin/bash
for a in "\$@"; do
  if [[ \$a == /dev/null ]]; then
    printf '%s\n' "attempted to remove /dev/null" >> "$rmlog"
    exit 99
  fi
done
exec "$real_rm" "\$@"
EOF
chmod 700 "$fakebin/mktemp" "$fakebin/rm"
json=$(PATH="$fakebin:$PATH" agent/collector.sh --dry-run --config "$tmp/agent.conf" --state-dir "$tmp/state") \
  || { echo "dry run failed when the ext mktemp failed" >&2; exit 1; }
printf '%s' "$json" | python3 -m json.tool >/dev/null \
  || { echo "payload is not valid JSON after a failed ext mktemp" >&2; exit 1; }
[ -c /dev/null ] || { echo "/dev/null is missing or not a character device" >&2; exit 1; }
if [ -s "$rmlog" ]; then
  echo "cleanup tried to remove /dev/null:" >&2
  cat "$rmlog" >&2
  exit 1
fi

# Direct unit check: the cleanup helper refuses /dev/null even when called with
# it; the fake rm would record the attempt instead of deleting the device.
: > "$rmlog"
( PATH="$fakebin:$PATH"; source agent/collector.sh; _cleanup_temp_file /dev/null )
if [ -s "$rmlog" ]; then
  echo "_cleanup_temp_file tried to remove /dev/null:" >&2
  cat "$rmlog" >&2
  exit 1
fi
# Static check: the failed-mktemp sentinel stays empty, never /dev/null.
if grep -q 'EXT_TMP=/dev/null' agent/collector.sh; then
  echo "collector still falls back to EXT_TMP=/dev/null" >&2
  exit 1
fi

echo "core_test: PASS"
