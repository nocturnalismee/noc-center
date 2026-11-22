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
ENABLE_IMUNIFY=1
EOF
chmod 600 "$tmp/agent.conf"
printf 'abcdefghijklmnop\n' > "$tmp/token"; chmod 600 "$tmp/token"

# Block 1: a running account scan -> scanning 1, files, account target.
cat > "$tmp/bin/imunify360-agent" <<'EOF'
#!/bin/bash
cat <<'JSON'
{"items":[{"scan_status":"running","started":1700000000,"total_resources":123,"path":"/home/alice/public_html"}]}
JSON
EOF
chmod +x "$tmp/bin/imunify360-agent"
out=$(PATH="$tmp/bin:$PATH" agent/collector.sh --module imunify --dry-run --config "$tmp/agent.conf" --run-dir "$tmp/run")
printf '%s\n' "$out" | grep -q '^imunify.updated_at	n	' || exit 1
printf '%s\n' "$out" | grep -q '^imunify.scanning	b	1$' || exit 1
printf '%s\n' "$out" | grep -q '^imunify.age_s	n	' || exit 1
printf '%s\n' "$out" | grep -q '^imunify.files	n	123$' || exit 1
printf '%s\n' "$out" | grep -q '^imunify.target	s	alice$' || exit 1

# Block 2: alternate shapes -> "data" container, string "started", "status"
# instead of "scan_status", system path -> incremental, target truncated.
cat > "$tmp/bin/imunify360-agent" <<'EOF'
#!/bin/bash
cat <<'JSON'
{"max_count":3,"data":[{"status":"finished","started":"1699000000","total":5,"path":"/home/alice"},
{"status":"running","started":"1699500000","total":42,"path":"/var/lib/whatever"},
{"status":"running","started":"1699600000","total":7,"path":"/home/averyveryverylongaccountname/www"}]}
JSON
EOF
chmod +x "$tmp/bin/imunify360-agent"
out=$(PATH="$tmp/bin:$PATH" agent/collector.sh --module imunify --dry-run --config "$tmp/agent.conf" --run-dir "$tmp/run")
printf '%s\n' "$out" | grep -q '^imunify.scanning	b	1$' || exit 1
printf '%s\n' "$out" | grep -q '^imunify.files	n	42$' || exit 1
printf '%s\n' "$out" | grep -q '^imunify.age_s	n	[0-9][0-9]*$' || exit 1
if printf '%s\n' "$out" | grep -q '^imunify.target	s	alice$'; then
  echo "imunify_test: finished scan leaked into target" >&2; exit 1
fi

# Block 2b: account target is truncated to the spec limit of 20 characters.
cat > "$tmp/bin/imunify360-agent" <<'EOF'
#!/bin/bash
cat <<'JSON'
{"items":[{"scan_status":"running","started":1700000000,"total_resources":9,"path":"/home/averyveryverylongaccountname/www"}]}
JSON
EOF
chmod +x "$tmp/bin/imunify360-agent"
out=$(PATH="$tmp/bin:$PATH" agent/collector.sh --module imunify --dry-run --config "$tmp/agent.conf" --run-dir "$tmp/run")
printf '%s\n' "$out" | grep -q '^imunify.target	s	averyveryverylongacc$' || exit 1

# Block 3: system path only -> incremental.
cat > "$tmp/bin/imunify360-agent" <<'EOF'
#!/bin/bash
cat <<'JSON'
{"items":[{"scan_status":"running","started":1700000000,"total_resources":0,"path":"/usr/bin/foo"}]}
JSON
EOF
chmod +x "$tmp/bin/imunify360-agent"
out=$(PATH="$tmp/bin:$PATH" agent/collector.sh --module imunify --dry-run --config "$tmp/agent.conf" --run-dir "$tmp/run")
printf '%s\n' "$out" | grep -q '^imunify.target	s	incremental$' || exit 1
printf '%s\n' "$out" | grep -q '^imunify.files	n	0$' || exit 1

# Block 4: valid empty-items response -> scanning 0 with the full idle field
# set: zero age/files and target "none".
cat > "$tmp/bin/imunify360-agent" <<'EOF'
#!/bin/bash
cat <<'JSON'
{"max_count":0,"items":[]}
JSON
EOF
chmod +x "$tmp/bin/imunify360-agent"
out=$(PATH="$tmp/bin:$PATH" agent/collector.sh --module imunify --dry-run --config "$tmp/agent.conf" --run-dir "$tmp/run")
printf '%s\n' "$out" | grep -q '^imunify.scanning	b	0$' || exit 1
printf '%s\n' "$out" | grep -q '^imunify.age_s	n	0$' || exit 1
printf '%s\n' "$out" | grep -q '^imunify.files	n	0$' || exit 1
printf '%s\n' "$out" | grep -q '^imunify.target	s	none$' || exit 1

# Block 5: successful run writes the cache; invalid JSON -> exit 4 and the
# previous cache is preserved byte for byte.
cat > "$tmp/bin/imunify360-agent" <<'EOF'
#!/bin/bash
cat <<'JSON'
{"items":[{"scan_status":"running","started":1700000000,"total_resources":123,"path":"/home/alice/public_html"}]}
JSON
EOF
chmod +x "$tmp/bin/imunify360-agent"
PATH="$tmp/bin:$PATH" agent/collector.sh --module imunify --config "$tmp/agent.conf" --run-dir "$tmp/run"
[ "$(stat -c %a "$tmp/state/cache/imunify.metrics")" = 600 ] || exit 1
cp "$tmp/state/cache/imunify.metrics" "$tmp/cache.before"
cat > "$tmp/bin/imunify360-agent" <<'EOF'
#!/bin/bash
printf 'not-json\n'
EOF
chmod +x "$tmp/bin/imunify360-agent"
rc=0
PATH="$tmp/bin:$PATH" agent/collector.sh --module imunify --config "$tmp/agent.conf" --run-dir "$tmp/run" || rc=$?
[ "$rc" -eq 4 ] || { echo "imunify_test: invalid JSON rc=$rc (want 4)" >&2; exit 1; }
cmp -s "$tmp/state/cache/imunify.metrics" "$tmp/cache.before" || exit 1

# Block 6: CLI failure -> exit 4, cache preserved.
cat > "$tmp/bin/imunify360-agent" <<'EOF'
#!/bin/bash
exit 1
EOF
chmod +x "$tmp/bin/imunify360-agent"
rc=0
PATH="$tmp/bin:$PATH" agent/collector.sh --module imunify --config "$tmp/agent.conf" --run-dir "$tmp/run" || rc=$?
[ "$rc" -eq 4 ] || { echo "imunify_test: CLI failure rc=$rc (want 4)" >&2; exit 1; }
cmp -s "$tmp/state/cache/imunify.metrics" "$tmp/cache.before" || exit 1

# Block 7: module detection needs both the CLI and python3.
rm -f "$tmp/bin/imunify360-agent"
rc=0
bash agent/modules/imunify.sh detect || rc=$?
[ "$rc" -eq 1 ] || { echo "imunify_test: detect without CLI rc=$rc (want 1)" >&2; exit 1; }
[ "$(bash agent/modules/imunify.sh mode)" = external ] || exit 1

echo "imunify_test: PASS"