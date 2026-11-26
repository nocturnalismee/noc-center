#!/bin/bash
set -euo pipefail
cd "$(dirname "$0")/../.."

tmp=$(mktemp -d)
trap 'rm -rf "$tmp"' EXIT
printf 'abcdefghijklmnop\n' > "$tmp/token"
chmod 600 "$tmp/token"

fail() { printf 'update_test: FAIL: %s\n' "$*" >&2; exit 1; }
checksum() { sha256sum "$1" | awk '{print $1}'; }

prefix="$tmp/prefix"
agent/install.sh --prefix "$prefix" \
  --api-url "https://monitor.example.com/api/v1/ingest" \
  --token-file "$tmp/token" --interval 30 --no-systemctl --no-prime >/dev/null

config="$prefix/etc/noc-agent/agent.conf"
installed_token="$prefix/etc/noc-agent/token"
collector="$prefix/usr/local/libexec/noc-agent/collector.sh"
updates="$prefix/var/lib/noc-agent/updates"
config_sum=$(checksum "$config")
token_sum=$(checksum "$installed_token")

# --check reports the installed and source versions without changing files.
out=$(agent/update.sh --source agent --prefix "$prefix" --check)
grep -q 'installed' <<< "$out" || fail "--check did not report installed version"
grep -q 'source' <<< "$out" || fail "--check did not report source version"
[ "$(checksum "$config")" = "$config_sum" ] || fail "--check changed the config"
[ "$(checksum "$installed_token")" = "$token_sum" ] || fail "--check changed the token"
[ ! -e "$updates" ] || fail "--check created a backup directory"

# A newer source replaces the collector but keeps config/token byte-identical.
cp -a agent "$tmp/source"
printf '\n# update-test-marker\n' >> "$tmp/source/collector.sh"
out=$(agent/update.sh --source "$tmp/source" --prefix "$prefix" --no-systemctl --no-prime)
grep -q 'update-test-marker' "$collector" || fail "collector was not updated"
[ "$(checksum "$config")" = "$config_sum" ] || fail "config changed during update"
[ "$(checksum "$installed_token")" = "$token_sum" ] || fail "token changed during update"
[ "$(stat -c %a "$config")" = 600 ] || fail "config mode changed"
[ "$(stat -c %a "$installed_token")" = 600 ] || fail "token mode changed"
[ -d "$updates" ] || fail "update did not create a backup directory"
! grep -q 'abcdefghijklmnop' <<< "$out" || fail "update printed the token"

# Dry run does not install or create another snapshot.
cp -a agent "$tmp/dry-source"
printf '\n# dry-run-marker\n' >> "$tmp/dry-source/collector.sh"
before_collector=$(checksum "$collector")
before_backups=$(find "$updates" -mindepth 1 -maxdepth 1 -type d | wc -l)
out=$(agent/update.sh --source "$tmp/dry-source" --prefix "$prefix" --dry-run --no-systemctl --no-prime)
grep -q '\[dry-run\]' <<< "$out" || fail "dry run did not print a plan"
[ "$(checksum "$collector")" = "$before_collector" ] || fail "dry run changed the collector"
[ "$(find "$updates" -mindepth 1 -maxdepth 1 -type d | wc -l)" = "$before_backups" ] || fail "dry run created a backup"

# A failed installer restores the previously installed collector.
old_collector=$(cat "$collector")
cp -a agent "$tmp/failing-source"
printf '\n# failing-marker\n' >> "$tmp/failing-source/collector.sh"
cat > "$tmp/failing-source/install.sh" <<'EOS'
#!/bin/bash
echo 'forced update failure' >&2
exit 1
EOS
chmod 755 "$tmp/failing-source/install.sh"
if agent/update.sh --source "$tmp/failing-source" --prefix "$prefix" --no-systemctl --no-prime >"$tmp/fail.out" 2>&1; then
  fail "failed installer returned success"
fi
[ "$(cat "$collector")" = "$old_collector" ] || fail "failed update did not restore the collector"
[ "$(checksum "$config")" = "$config_sum" ] || fail "failed update changed the config"
[ "$(checksum "$installed_token")" = "$token_sum" ] || fail "failed update changed the token"

# Missing source files are rejected before any backup is made.
before_backups=$(find "$updates" -mindepth 1 -maxdepth 1 -type d | wc -l)
if agent/update.sh --source "$tmp/does-not-exist" --prefix "$prefix" --no-systemctl >"$tmp/missing.out" 2>&1; then
  fail "missing source returned success"
fi
[ "$(find "$updates" -mindepth 1 -maxdepth 1 -type d | wc -l)" = "$before_backups" ] || fail "missing source created a backup"

# The source tree cannot change the paths resolved from --prefix.
cp -a agent "$tmp/prefix-source"
out=$(agent/update.sh --source "$tmp/prefix-source" --prefix "$prefix" --dry-run --no-systemctl --no-prime)
! grep -qE '(^|[[:space:]])/usr/local/libexec/noc-agent([[:space:]]|$)' <<< "$out" || fail "dry run leaked a real-system path"

echo "update_test: PASS"
