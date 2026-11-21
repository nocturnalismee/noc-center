#!/bin/bash
set -euo pipefail
cd "$(dirname "$0")/../.."
tmp=$(mktemp -d); trap 'rm -rf "$tmp"' EXIT
mkdir -p "$tmp/bin" "$tmp/state" "$tmp/run"
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

# Block 1: simple header (User,BlockUsed,BlockLimit).
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
out=$(PATH="$tmp/bin:$PATH" agent/collector.sh --module diskquota --dry-run --config "$tmp/agent.conf" --run-dir "$tmp/run")
printf '%s\n' "$out" | grep -q '^diskquota.top.alice.used_gb	n	2.0$' || exit 1
printf '%s\n' "$out" | grep -q '^diskquota.top.bob.used_gb	n	1.0$' || exit 1
printf '%s\n' "$out" | grep -q '^diskquota.over	n	1$' || exit 1
printf '%s\n' "$out" | grep -q '^diskquota.accounts	n	2$' || exit 1
printf '%s\n' "$out" | grep -q '^diskquota.updated_at	n	' || exit 1

# Block 2: FileStatus column before BlockUsed (header mapping by name).
cat > "$tmp/bin/repquota" <<'EOF'
#!/bin/bash
cat <<'CSV'
User,FileStatus,BlockUsed,BlockLimit
dave,-,3145728,0
erin,-,0,1048576
CSV
EOF
chmod +x "$tmp/bin/repquota"
out=$(PATH="$tmp/bin:$PATH" agent/collector.sh --module diskquota --dry-run --config "$tmp/agent.conf" --run-dir "$tmp/run")
printf '%s\n' "$out" | grep -q '^diskquota.top.dave.used_gb	n	3.0$' || exit 1
printf '%s\n' "$out" | grep -q '^diskquota.accounts	n	1$' || exit 1
printf '%s\n' "$out" | grep -q '^diskquota.over	n	0$' || exit 1

# Block 3: empty body (header only) -> accounts 0, over 0, no top keys.
cat > "$tmp/bin/repquota" <<'EOF'
#!/bin/bash
cat <<'CSV'
User,BlockUsed,BlockLimit
CSV
EOF
chmod +x "$tmp/bin/repquota"
out=$(PATH="$tmp/bin:$PATH" agent/collector.sh --module diskquota --dry-run --config "$tmp/agent.conf" --run-dir "$tmp/run")
printf '%s\n' "$out" | grep -q '^diskquota.accounts	n	0$' || exit 1
printf '%s\n' "$out" | grep -q '^diskquota.over	n	0$' || exit 1
if printf '%s\n' "$out" | grep -q '^diskquota\.top\.'; then
  echo "diskquota_test: unexpected top keys in empty body" >&2; exit 1
fi

# Block 3b: modern quota-tools header (BlockStatus/FileStatus/BlockHardLimit).
cat > "$tmp/bin/repquota" <<'EOF'
#!/bin/bash
cat <<'CSV'
User,BlockStatus,FileStatus,BlockUsed,BlockSoftLimit,BlockHardLimit,BlockGrace,FileUsed,FileSoftLimit,FileHardLimit,FileGrace
frank,--,-,3145728,1048576,2097152,none,0,0,0,0
CSV
EOF
chmod +x "$tmp/bin/repquota"
out=$(PATH="$tmp/bin:$PATH" agent/collector.sh --module diskquota --dry-run --config "$tmp/agent.conf" --run-dir "$tmp/run")
printf '%s\n' "$out" | grep -q '^diskquota.top.frank.used_gb	n	3.0$' || exit 1
printf '%s\n' "$out" | grep -q '^diskquota.accounts	n	1$' || exit 1
printf '%s\n' "$out" | grep -q '^diskquota.over	n	1$' || exit 1

# Block 3c: configured QUOTA_TOP_N reaches the module subprocess. A non-default
# value (1) with two usage rows must emit exactly one top key.
cat > "$tmp/agent-top1.conf" <<EOF
PANEL=none
STATE_DIR="$tmp/state"
API_URL="http://127.0.0.1:18787/api/v1/ingest"
ALLOW_INSECURE_HTTP=1
SERVER_TOKEN_FILE="$tmp/token"
ENABLE_DISKQUOTA=1
QUOTA_TOP_N=1
EOF
chmod 600 "$tmp/agent-top1.conf"
cat > "$tmp/bin/repquota" <<'EOF'
#!/bin/bash
cat <<'CSV'
User,BlockUsed,BlockLimit
alice,3145728,0
bob,2097152,0
CSV
EOF
chmod +x "$tmp/bin/repquota"
out=$(PATH="$tmp/bin:$PATH" agent/collector.sh --module diskquota --dry-run --config "$tmp/agent-top1.conf" --run-dir "$tmp/run")
printf '%s\n' "$out" | grep -q '^diskquota.top.alice.used_gb	n	3.0$' || exit 1
top_count=$(printf '%s\n' "$out" | grep -c '^diskquota\.top\.' || true)
[ "$top_count" -eq 1 ] || {
  echo "diskquota_test: expected 1 top key with QUOTA_TOP_N=1, got $top_count" >&2
  exit 1
}
if printf '%s\n' "$out" | grep -q '^diskquota\.top\.bob\.'; then
  echo "diskquota_test: QUOTA_TOP_N=1 did not limit top keys" >&2; exit 1
fi

# Block 4: cPanel restriction (only accounts present in the user dir count).
mkdir -p "$tmp/cpanel-users"
: > "$tmp/cpanel-users/alice"
: > "$tmp/cpanel-users/bob"
: > "$tmp/cpanel-users/system"  # excluded pseudo-user
cat > "$tmp/bin/repquota" <<'EOF'
#!/bin/bash
cat <<'CSV'
User,BlockUsed,BlockLimit
alice,2097152,0
bob,1048576,524288
carol,3145728,0
CSV
EOF
chmod +x "$tmp/bin/repquota"
out=$(DISKQUOTA_CPANEL_USERS_DIR="$tmp/cpanel-users" PATH="$tmp/bin:$PATH" \
  agent/collector.sh --module diskquota --dry-run --config "$tmp/agent.conf" --run-dir "$tmp/run")
printf '%s\n' "$out" | grep -q '^diskquota.accounts	n	2$' || exit 1
printf '%s\n' "$out" | grep -q '^diskquota.over	n	1$' || exit 1
printf '%s\n' "$out" | grep -q '^diskquota.top.alice.used_gb	n	2.0$' || exit 1
if printf '%s\n' "$out" | grep -q 'carol'; then
  echo "diskquota_test: non-cpanel user carol leaked" >&2; exit 1
fi

# Block 5: failed collection (repquota fails) -> exit 4 and cache preserved.
cat > "$tmp/bin/repquota" <<'EOF'
#!/bin/bash
cat <<'CSV'
User,BlockUsed,BlockLimit
alice,2097152,0
CSV
EOF
chmod +x "$tmp/bin/repquota"
PATH="$tmp/bin:$PATH" agent/collector.sh --module diskquota --config "$tmp/agent.conf" --run-dir "$tmp/run"
[ "$(stat -c %a "$tmp/state/cache/diskquota.metrics")" = 600 ] || exit 1
cp "$tmp/state/cache/diskquota.metrics" "$tmp/cache.before"
cat > "$tmp/bin/repquota" <<'EOF'
#!/bin/bash
printf 'garbage with no header\n' >&2
exit 1
EOF
chmod +x "$tmp/bin/repquota"
rc=0
PATH="$tmp/bin:$PATH" agent/collector.sh --module diskquota --config "$tmp/agent.conf" --run-dir "$tmp/run" || rc=$?
[ "$rc" -eq 4 ] || exit 1
cmp -s "$tmp/state/cache/diskquota.metrics" "$tmp/cache.before" || exit 1

echo "diskquota_test: PASS"
