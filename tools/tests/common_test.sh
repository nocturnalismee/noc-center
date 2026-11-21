#!/bin/bash
set -euo pipefail
cd "$(dirname "$0")/../.."
source agent/lib/common.sh
fail() { echo "FAIL: $*" >&2; exit 1; }

tmp=$(mktemp -d)
trap 'rm -rf "$tmp"' EXIT
chmod 700 "$tmp"
require_secure_dir "$tmp" || fail "secure dir"
chmod 777 "$tmp"
if require_secure_dir "$tmp" 2>/dev/null; then fail "world-writable dir accepted"; fi
chmod 700 "$tmp"
ln -s "$tmp" "$tmp.link"
if require_secure_dir "$tmp.link" 2>/dev/null; then fail "symlink dir accepted"; fi

printf 'TOKEN=abcdefghijklmnop\n' > "$tmp/conf"
chmod 600 "$tmp/conf"
config_defaults
load_config "$tmp/conf"
[ "$TOKEN" = "abcdefghijklmnop" ] || fail "config source"

printf 'abcdefghijklmnop\n' > "$tmp/token"
chmod 600 "$tmp/token"
validate_token_file "$tmp/token" || fail "token"
[ "$SERVER_TOKEN" = "abcdefghijklmnop" ] || fail "token value"
chmod 644 "$tmp/token"
if validate_token_file "$tmp/token" 2>/dev/null; then fail "insecure token accepted"; fi

# Agent ID is stable and mode 600.
mkdir -p "$tmp/state"; chmod 700 "$tmp/state"
id1=$(STATE_DIR="$tmp/state" get_agent_id)
id2=$(STATE_DIR="$tmp/state" get_agent_id)
[ "$id1" = "$id2" ] || fail "agent id stable"
[ "$(stat -c %a "$tmp/state/agent-id")" = "600" ] || fail "agent id mode"

[ "$(agent/collector.sh --version)" = "noc-agent 0.1.0" ] || fail "version"
echo "common_test: PASS"
