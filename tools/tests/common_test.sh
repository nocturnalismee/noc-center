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

# Shared strict API URL validation (runtime and installer use this one).
validate_api_url "https://monitor.example.com/api/v1/ingest" 0 || fail "valid https rejected"
validate_api_url "https://monitor.example.com:8443/api/v1/ingest" 0 || fail "valid https port rejected"
validate_api_url "https://127.0.0.1/api/v1/ingest" 0 || fail "https IPv4 rejected"
validate_api_url "http://127.0.0.1:18787/api/v1/ingest" 1 || fail "loopback http rejected with allow=1"
validate_api_url "http://localhost:18787/api/v1/ingest" 1 || fail "localhost http rejected with allow=1"
if validate_api_url "http://127.0.0.1:18787/api/v1/ingest" 0; then
  fail "loopback http accepted with allow=0"
fi
if validate_api_url "http://localhost/api/v1/ingest" 0; then
  fail "localhost http accepted with allow=0"
fi
# Userinfo (curl would connect to evil.com), loopback suffixes, trailing dots,
# bracketed IPv6 for http, and malformed authorities are all refused.
for bad_url in \
  "http://127.0.0.1:80@evil.com/x" \
  "http://127.0.0.1.evil.com/x" \
  "http://localhost.evil.com/x" \
  "http://localhost./x" \
  "http://[::1]/x" \
  "https://" \
  "https:///x" \
  "https://user@example.com/x" \
  "https://host:abc/x" \
  "https://host:99999/x" \
  "https://host..example.com/x"; do
  if validate_api_url "$bad_url" 1; then fail "accepted invalid API URL: $bad_url"; fi
done

[ "$(agent/collector.sh --version)" = "noc-agent 0.1.0" ] || fail "version"
echo "common_test: PASS"
