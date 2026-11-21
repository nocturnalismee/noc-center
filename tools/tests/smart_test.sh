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

# Failure path: smartctl exits 1 for every disk -> collect fails -> exit 4 and
# the previous cache is preserved unchanged.
cp "$tmp/state/cache/smart.metrics" "$tmp/cache.before"
cat > "$tmp/bin/smartctl" <<'EOF'
#!/bin/bash
exit 1
EOF
chmod +x "$tmp/bin/smartctl"
rc=0
PATH="$tmp/bin:$PATH" agent/collector.sh --module smart --config "$tmp/agent.conf" --run-dir "$tmp/run" || rc=$?
[ "$rc" -eq 4 ] || exit 1
cmp -s "$tmp/state/cache/smart.metrics" "$tmp/cache.before" || exit 1

echo "smart_test: PASS"
