#!/bin/bash
set -euo pipefail
cd "$(dirname "$0")/../.."
tmp=$(mktemp -d); trap 'rm -rf "$tmp"' EXIT
printf 'abcdefghijklmnop\n' > "$tmp/token"; chmod 600 "$tmp/token"
agent/install.sh --prefix "$tmp" --api-url "http://127.0.0.1:18787/api/v1/ingest" \
  --token-file "$tmp/token" --interval 30 --no-systemctl --no-prime
[ -x "$tmp/usr/local/libexec/noc-agent/collector.sh" ] || exit 1
[ -f "$tmp/etc/systemd/system/noc-agent.timer" ] || exit 1
grep -q 'OnUnitActiveSec=30s' "$tmp/etc/systemd/system/noc-agent.timer" || exit 1
[ "$(stat -c %a "$tmp/etc/noc-agent/agent.conf")" = 600 ] || exit 1
[ "$(stat -c %a "$tmp/etc/noc-agent/token")" = 600 ] || exit 1
agent/collector.sh --dry-run --config "$tmp/etc/noc-agent/agent.conf" \
  --state-dir "$tmp/var/lib/noc-agent" >/dev/null
agent/uninstall.sh --prefix "$tmp" --no-systemctl
[ ! -e "$tmp/usr/local/libexec/noc-agent" ] || exit 1
[ -e "$tmp/etc/noc-agent/agent.conf" ] || exit 1
agent/install.sh --prefix "$tmp" --token-file "$tmp/token" --no-systemctl --no-prime >/dev/null
agent/uninstall.sh --prefix "$tmp" --no-systemctl --purge
[ ! -e "$tmp/etc/noc-agent" ] || exit 1

# --- Installer contract checks (all under $tmp; no systemctl) --------------

# Unit file naming follows the installer's prefix rather than hardcoding /usr.
agent/install.sh --prefix "$tmp" --api-url "https://monitor.example.com/api/v1/ingest" \
  --token-file "$tmp/token" --interval 15 --no-systemctl --no-prime >/dev/null
unit="$tmp/etc/systemd/system"
grep -qx "ExecStart=$tmp/usr/local/libexec/noc-agent/collector.sh" "$unit/noc-agent.service" || exit 1
grep -qx "ExecStart=$tmp/usr/local/libexec/noc-agent/collector.sh --module %i" \
  "$unit/noc-agent@.service" || exit 1
grep -qx 'Type=oneshot' "$unit/noc-agent.service" || exit 1
grep -qx 'TimeoutStartSec=50' "$unit/noc-agent.service" || exit 1
grep -qx 'TimeoutStartSec=300' "$unit/noc-agent@.service" || exit 1
grep -qx 'OnUnitActiveSec=15s' "$unit/noc-agent.timer" || exit 1
grep -qx 'OnBootSec=30s' "$unit/noc-agent.timer" || exit 1
for t in smart diskquota imunify; do
  [ -f "$unit/noc-agent-$t.timer" ] || exit 1
done
grep -qxF 'OnCalendar=*-*-01 03:15:00' "$unit/noc-agent-smart.timer" || exit 1
grep -qx 'RandomizedDelaySec=15m' "$unit/noc-agent-smart.timer" || exit 1
grep -qx 'Persistent=true' "$unit/noc-agent-smart.timer" || exit 1
grep -qxF 'OnCalendar=*-*-* 03:45:00' "$unit/noc-agent-diskquota.timer" || exit 1
grep -qx 'RandomizedDelaySec=10m' "$unit/noc-agent-diskquota.timer" || exit 1
grep -qx 'Persistent=true' "$unit/noc-agent-diskquota.timer" || exit 1
grep -qx 'OnBootSec=2m' "$unit/noc-agent-imunify.timer" || exit 1
grep -qx 'OnUnitActiveSec=5m' "$unit/noc-agent-imunify.timer" || exit 1

# Executables (collector, libs, panels, modules) are mode 700.
[ "$(stat -c %a "$tmp/usr/local/libexec/noc-agent/collector.sh")" = 700 ] || exit 1
[ "$(stat -c %a "$tmp/usr/local/libexec/noc-agent/lib/common.sh")" = 700 ] || exit 1
[ "$(stat -c %a "$tmp/usr/local/libexec/noc-agent/modules/smart.sh")" = 700 ] || exit 1
[ "$(stat -c %a "$tmp/usr/local/libexec/noc-agent/panels/cpanel.sh")" = 700 ] || exit 1
[ "$(stat -c %a "$tmp/etc/noc-agent")" = 700 ] || exit 1
[ "$(stat -c %a "$tmp/var/lib/noc-agent")" = 700 ] || exit 1
[ "$(stat -c %a "$tmp/var/lib/noc-agent/cache")" = 700 ] || exit 1

# Config defaults: every documented key is present, no real token.
conf="$tmp/etc/noc-agent/agent.conf"
for key in API_URL SERVER_TOKEN_FILE INTERVAL_SECONDS PANEL PANEL_DETECT_TIMEOUT \
  PANEL_TIMEOUT CURL_TIMEOUT MAX_RUN_SECONDS STATE_DIR TLS_VERIFY \
  ALLOW_INSECURE_HTTP DEBUG ENABLE_MYSQL ENABLE_SMART ENABLE_DISKQUOTA \
  ENABLE_IMUNIFY MODULE_DETECT_TIMEOUT MODULE_TIMEOUT HEAVY_MODULE_TIMEOUT \
  QUOTA_TOP_N MYSQL_MAX_QUERIES MYSQL_MIN_SECONDS MYSQL_INCLUDE_QUERY_TEXT \
  SMART_MAX_DISKS; do
  grep -q "^$key=" "$conf" || { echo "missing config key $key" >&2; exit 1; }
done
grep -qx "SERVER_TOKEN_FILE=\"$tmp/etc/noc-agent/token\"" "$conf" || exit 1
grep -qx "STATE_DIR=\"$tmp/var/lib/noc-agent\"" "$conf" || exit 1
grep -qx 'ALLOW_INSECURE_HTTP=0' "$conf" || exit 1
grep -qx 'INTERVAL_SECONDS=15' "$conf" || exit 1
# The example ships defaults, never a token value.
! grep -q 'abcdefghijklmnop' agent/config/agent.conf.example || exit 1

# The config is sourced cleanly and validates with the installed core defaults.
agent/collector.sh --dry-run --config "$conf" --state-dir "$tmp/var/lib/noc-agent" >/dev/null

# A second install keeps an existing config and token (idempotent, no --force).
sed -i 's/^DEBUG=0$/DEBUG=1/' "$conf"
agent/install.sh --prefix "$tmp" --api-url "https://elsewhere.example.com/api/v1/ingest" \
  --token-file "$tmp/token" --interval 20 --no-systemctl --no-prime >/dev/null
grep -qx 'DEBUG=1' "$conf" || exit 1
grep -qx "API_URL=\"https://monitor.example.com/api/v1/ingest\"" "$conf" || exit 1
grep -qx 'OnUnitActiveSec=20s' "$unit/noc-agent.timer" || exit 1
# --force replaces the config.
agent/install.sh --prefix "$tmp" --api-url "https://elsewhere.example.com/api/v1/ingest" \
  --token-file "$tmp/token" --interval 20 --no-systemctl --no-prime --force >/dev/null
grep -qx 'DEBUG=0' "$conf" || exit 1
grep -qx "API_URL=\"https://elsewhere.example.com/api/v1/ingest\"" "$conf" || exit 1

# Dry run prints planned actions and changes nothing.
dry="$tmp/dry"
out=$(agent/install.sh --prefix "$dry" --dry-run --api-url "https://monitor.example.com/api/v1/ingest" \
  --token-file "$tmp/token" --no-prime)
grep -q '\[dry-run\]' <<< "$out" || exit 1
[ ! -e "$dry/etc" ] || exit 1
[ ! -e "$dry/usr" ] || exit 1
[ ! -e "$dry/var" ] || exit 1

# Invalid interval and missing --api-url are refused before writing anything.
! agent/install.sh --prefix "$tmp/bad" --interval 5 \
  --api-url "https://monitor.example.com/api/v1/ingest" --no-systemctl --no-prime >/dev/null 2>&1 || exit 1
! agent/install.sh --prefix "$tmp/bad2" --token-file "$tmp/token" --no-systemctl --no-prime >/dev/null 2>&1 || exit 1
[ ! -e "$tmp/bad" ] || exit 1
[ ! -e "$tmp/bad2" ] || exit 1
# Non-loopback plain http is refused.
! agent/install.sh --prefix "$tmp/bad3" --api-url "http://monitor.example.com/api/v1/ingest" \
  --no-systemctl --no-prime >/dev/null 2>&1 || exit 1

# --test dry-runs the installed collector (no token, no HTTP, no systemctl).
agent/install.sh --prefix "$tmp" --api-url "https://monitor.example.com/api/v1/ingest" \
  --no-systemctl --no-prime --test >"$tmp/test.out" 2>&1 || { cat "$tmp/test.out" >&2; exit 1; }
grep -q -- '--test: collector dry run succeeded' "$tmp/test.out" || exit 1

# systemd-analyze verify accepts the generated units (when available).
# The units already point at the installed collector, so verify runs in place.
if command -v systemd-analyze >/dev/null 2>&1; then
  if ! systemd-analyze verify "$unit"/*.timer "$unit"/*.service 2>"$tmp/verify.err"; then
    cat "$tmp/verify.err" >&2
    exit 1
  fi
  # Fail on a real diagnostic about our own files. systemd-analyze may note
  # that the unit is not loaded from the host's unit path or that an
  # ExecStart binary is not in the host's known set; those are expected for a
  # prefixed install and are not suppressed here.
  if grep -E "noc-agent(-[a-z]+)?(@)?\.(timer|service)" "$tmp/verify.err" >/dev/null; then
    cat "$tmp/verify.err" >&2
    exit 1
  fi
fi
agent/uninstall.sh --prefix "$tmp" --no-systemctl --purge >/dev/null
[ ! -e "$tmp/etc/noc-agent" ] || exit 1
[ ! -e "$tmp/usr/local/libexec/noc-agent" ] || exit 1
echo "install_test: PASS"