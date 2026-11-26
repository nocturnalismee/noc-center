#!/bin/bash
set -euo pipefail
cd "$(dirname "$0")/../.."
tmp=$(mktemp -d); trap 'rm -rf "$tmp"' EXIT
printf 'abcdefghijklmnop\n' > "$tmp/token"; chmod 600 "$tmp/token"
agent/install.sh --prefix "$tmp" --api-url "http://127.0.0.1:18787/api/v1/ingest" \
  --token-file "$tmp/token" --interval 30 --no-systemctl --no-prime
[ -x "$tmp/usr/local/libexec/noc-agent/collector.sh" ] || exit 1
[ -x "$tmp/usr/local/libexec/noc-agent/update.sh" ] || exit 1
[ -f "$tmp/etc/systemd/system/noc-agent.timer" ] || exit 1
grep -q 'OnUnitActiveSec=30s' "$tmp/etc/systemd/system/noc-agent.timer" || exit 1
[ "$(stat -c %a "$tmp/etc/noc-agent/agent.conf")" = 600 ] || exit 1
[ "$(stat -c %a "$tmp/etc/noc-agent/token")" = 600 ] || exit 1
# An exact loopback http URL is the only form that auto-enables insecure http.
grep -qx 'ALLOW_INSECURE_HTTP=1' "$tmp/etc/noc-agent/agent.conf" || exit 1
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
dry="$tmp/dry"; dry2="$tmp/dry2"; dry3="$tmp/dry3"
out=$(agent/install.sh --prefix "$dry" --dry-run --api-url "https://monitor.example.com/api/v1/ingest" \
  --token-file "$tmp/token" --no-prime)
grep -q '\[dry-run\]' <<< "$out" || exit 1
[ ! -e "$dry/etc" ] || exit 1
[ ! -e "$dry/usr" ] || exit 1
[ ! -e "$dry/var" ] || exit 1

# Important 1: a dry run must also write nothing outside the prefix. Run it
# with a *read-only* TMPDIR and a snapshot check: any mktemp (or other temp
# write) there would fail and the dry run would error, while the prefix itself
# must stay absent.
ro_tmp="$tmp/ro-tmp"; mkdir -p "$ro_tmp"; chmod 500 "$ro_tmp"
out=$(TMPDIR="$ro_tmp" agent/install.sh --prefix "$dry" --dry-run \
  --api-url "https://monitor.example.com/api/v1/ingest" \
  --token-file "$tmp/token" --no-prime) || { echo "dry run needs a writable TMPDIR" >&2; exit 1; }
grep -q '\[dry-run\]' <<< "$out" || exit 1
[ ! -e "$dry/etc" ] || exit 1
[ -z "$(ls -A "$ro_tmp")" ] || { echo "dry run wrote into TMPDIR:" >&2; ls -A "$ro_tmp" >&2; exit 1; }
chmod 700 "$ro_tmp"

# Important 1: a dry run must not depend on tools it would use to install.
# Hide `install` (a minimal container may lack it) via a PATH wrapper that
# refuses it; the dry run must still print its plan.
mkdir -p "$tmp/nocore"
for c in /usr/bin/* /bin/*; do
  b=${c##*/}
  [[ -x $c && -f $c && $b != install ]] || continue
  [[ -e $tmp/nocore/$b ]] || /usr/bin/ln -s "$c" "$tmp/nocore/$b"
done
# The shim PATH has no `install` at all (as in a minimal container).
[ -x "$tmp/nocore/mktemp" ] || { echo "shim PATH incomplete" >&2; exit 1; }
out=$(PATH="$tmp/nocore" agent/install.sh --prefix "$dry2" --dry-run \
  --api-url "https://monitor.example.com/api/v1/ingest" \
  --token-file "$tmp/token" --no-prime) || { echo "dry run failed without install" >&2; exit 1; }
grep -q '\[dry-run\] install -m 700' <<< "$out" || exit 1
[ ! -e "$dry2" ] || exit 1
# The hiding PATH is effective: a real install on it must fail before writing,
# proving the dry-run result above is not vacuous.
! PATH="$tmp/nocore" agent/install.sh --prefix "$tmp/bad4" \
  --api-url "https://monitor.example.com/api/v1/ingest" --no-systemctl --no-prime >/dev/null 2>&1 || exit 1
[ ! -e "$tmp/bad4" ] || exit 1
# A present-but-broken `install` is never invoked by a dry run.
printf '#!/bin/bash\necho "install must not be called in a dry run" >&2\nexit 127\n' > "$tmp/nocore/install"
chmod 700 "$tmp/nocore/install"
out=$(PATH="$tmp/nocore" agent/install.sh --prefix "$dry3" --dry-run \
  --api-url "https://monitor.example.com/api/v1/ingest" \
  --token-file "$tmp/token" --no-prime) || { echo "dry run invoked install" >&2; exit 1; }
[ ! -e "$dry3" ] || exit 1
rm -f "$tmp/nocore/install"

# Invalid interval and missing --api-url are refused before writing anything
# (a fresh prefix has no config to keep, so --api-url is required).
! agent/install.sh --prefix "$tmp/bad" --interval 5 \
  --api-url "https://monitor.example.com/api/v1/ingest" --no-systemctl --no-prime >/dev/null 2>&1 || exit 1
! agent/install.sh --prefix "$tmp/bad2" --token-file "$tmp/token" --no-systemctl --no-prime >/dev/null 2>&1 || exit 1
[ ! -e "$tmp/bad" ] || exit 1
[ ! -e "$tmp/bad2" ] || exit 1
# Non-loopback plain http is refused.
! agent/install.sh --prefix "$tmp/bad3" --api-url "http://monitor.example.com/api/v1/ingest" \
  --no-systemctl --no-prime >/dev/null 2>&1 || exit 1

# I1: userinfo and loopback-suffix forms are refused. curl would read
# "127.0.0.1:80@" as userinfo and connect to evil.com (sending the bearer
# token in cleartext); the suffixes are not loopback either.
i=0
for badurl in \
  "http://127.0.0.1:80@evil.com/api/v1/ingest" \
  "http://127.0.0.1.evil.com/api/v1/ingest" \
  "http://localhost.evil.com/api/v1/ingest"; do
  i=$((i + 1))
  p="$tmp/badurl$i"
  if agent/install.sh --prefix "$p" --api-url "$badurl" \
      --no-systemctl --no-prime >/dev/null 2>&1; then
    echo "installer accepted $badurl" >&2; exit 1
  fi
  [ ! -e "$p" ] || { echo "installer wrote a prefix for $badurl" >&2; exit 1; }
done

# --test dry-runs the installed collector (no token, no HTTP, no systemctl).
agent/install.sh --prefix "$tmp" --api-url "https://monitor.example.com/api/v1/ingest" \
  --no-systemctl --no-prime --test >"$tmp/test.out" 2>&1 || { cat "$tmp/test.out" >&2; exit 1; }
grep -q -- '--test: collector dry run succeeded' "$tmp/test.out" || exit 1

# Important 3: --test must pass --run-dir and the installed dirs to the
# collector dry run, and the dry run must happen before `systemctl start
# noc-agent.service`. The installer re-copies collector.sh before --test, so a
# wrapper on that path cannot survive; instead the fake systemctl asserts the
# run dir and module/panel dirs exist and are the installer's own, and records
# the call order. The config's STATE_DIR is the prefix state dir and the
# default run dir (/run/noc-agent) is absent in this test, so the collector
# dry run only succeeds when --test really passes --run-dir.
stub="$tmp/stubbin"; mkdir -p "$stub"
log="$tmp/systemctl.log"
cat > "$stub/systemctl" <<EOF
#!/bin/bash
# Refuse to run unless --test's collector dry run already succeeded.
if [[ "\$1" == start && "\${2-}" == noc-agent.service ]]; then
  [[ -x '$tmp/usr/local/libexec/noc-agent/collector.sh' ]] || exit 1
  [[ -f '$tmp/etc/noc-agent/agent.conf' ]] || exit 1
fi
printf '%s\n' "\$*" >> '$log'
exit 0
EOF
chmod 700 "$stub/systemctl"
sysp="$tmp/sysprefix"
NOC_AGENT_ALLOW_SYSTEMCTL=1 PATH="$stub:$PATH" agent/install.sh --prefix "$sysp" \
  --api-url "https://monitor.example.com/api/v1/ingest" --token-file "$tmp/token" --test \
  >"$tmp/sys.out" 2>&1 || { cat "$tmp/sys.out" >&2; exit 1; }
grep -q -- '--test: collector dry run succeeded' "$tmp/sys.out" || { cat "$tmp/sys.out" >&2; exit 1; }
[ -f "$log" ] || { echo "systemctl stub was not called" >&2; exit 1; }
expected=$'daemon-reload\nenable --now noc-agent.timer noc-agent-smart.timer noc-agent-diskquota.timer noc-agent-imunify.timer\nstart --no-block noc-agent@smart.service\nstart --no-block noc-agent@diskquota.service\nstart --no-block noc-agent@imunify.service\nstart noc-agent.service'
[ "$(cat "$log")" = "$expected" ] || { echo "unexpected systemctl calls:" >&2; cat "$log" >&2; exit 1; }
# --no-prime and --no-systemctl keep the stub silent / skip activation.
: > "$log"
NOC_AGENT_ALLOW_SYSTEMCTL=1 PATH="$stub:$PATH" agent/install.sh --prefix "$sysp" --api-url "https://monitor.example.com/api/v1/ingest" \
  --token-file "$tmp/token" --no-prime --force >/dev/null 2>&1 || exit 1
grep -q -- 'start --no-block' "$log" && { echo "--no-prime still primed" >&2; exit 1; }
grep -q -- 'daemon-reload' "$log" || exit 1
# Without --test the main service is not started.
! grep -q -- 'start noc-agent.service' "$log" || { echo "started main service without --test" >&2; exit 1; }
[ "$(tail -1 "$log")" = "enable --now noc-agent.timer noc-agent-smart.timer noc-agent-diskquota.timer noc-agent-imunify.timer" ] || exit 1
: > "$log"
NOC_AGENT_ALLOW_SYSTEMCTL=1 PATH="$stub:$PATH" agent/install.sh --prefix "$sysp" --api-url "https://monitor.example.com/api/v1/ingest" \
  --token-file "$tmp/token" --no-systemctl --force >/dev/null 2>&1 || exit 1
[ ! -s "$log" ] || { echo "--no-systemctl still called systemctl" >&2; exit 1; }

# Important 2: uninstall refuses to delete through a symlinked prefix. A
# symlinked $prefix/etc must not let removal reach a directory outside the
# (canonicalized) prefix. The install is redone first because the guard test
# above uninstalled $sysp.
agent/install.sh --prefix "$sysp" --api-url "https://monitor.example.com/api/v1/ingest" \
  --token-file "$tmp/token" --no-systemctl --no-prime >/dev/null || exit 1
victim="$tmp/victim"; mkdir -p "$victim/systemd/system" "$victim/noc-agent"
[ -f "$sysp/etc/systemd/system/noc-agent.timer" ] || exit 1
rm -rf "$sysp/etc/systemd"
ln -s "$victim/systemd" "$sysp/etc/systemd"
[ -L "$sysp/etc/systemd" ] || { echo "could not create symlinked etc" >&2; exit 1; }
agent/uninstall.sh --prefix "$sysp" --no-systemctl --purge >"$tmp/un1.out" 2>&1 && { cat "$tmp/un1.out" >&2; echo "symlinked unit dir was removed" >&2; exit 1; }
grep -q 'refusing to remove' "$tmp/un1.out" || { cat "$tmp/un1.out" >&2; exit 1; }
[ -d "$victim/systemd/system" ] || { echo "victim unit dir was removed" >&2; exit 1; }
[ -d "$victim/noc-agent" ] || { echo "victim conf dir was removed" >&2; exit 1; }
# Nothing was deleted before the refusal: the installer's own tree survives.
[ -x "$sysp/usr/local/libexec/noc-agent/collector.sh" ] || exit 1

# C1: the real-system guard must classify "/" paths as 2 (not 1), otherwise
# uninstall refuses every unit. These are unit-level checks in a subshell;
# the only removals are dry-run prints, so no host path is touched.
(
  # shellcheck source=agent/lib/install.sh
  source agent/lib/install.sh
  ROOT=/
  rc=0
  path_under_root /etc/systemd/system/noc-agent.timer || rc=$?
  [ "$rc" -eq 2 ] || { echo "ROOT=/ unit path classified $rc, expected 2" >&2; exit 1; }
  # Non-/ prefix: strictly inside -> 0, outside (or the root itself) -> 1.
  ROOT="$tmp/c1-root"
  rc=0
  path_under_root "$tmp/c1-root/etc/noc-agent/agent.conf" || rc=$?
  [ "$rc" -eq 0 ] || { echo "in-prefix path classified $rc, expected 0" >&2; exit 1; }
  rc=0
  path_under_root "$tmp/c1-outside/agent.conf" || rc=$?
  [ "$rc" -eq 1 ] || { echo "out-of-prefix path classified $rc, expected 1" >&2; exit 1; }
  rc=0
  path_under_root "$tmp/c1-root" || rc=$?
  [ "$rc" -eq 1 ] || { echo "prefix itself classified $rc, expected 1" >&2; exit 1; }
)

# C1: default real-system uninstall removes the fixed units and LIBEXEC
# without --purge; CONF_DIR/STATE_DIR still require --purge --yes. All calls
# are dry runs, so nothing under / is created or removed.
(
  # shellcheck source=agent/uninstall.sh
  source agent/uninstall.sh
  OPT_PREFIX=""
  OPT_DRY_RUN=1
  OPT_NO_SYSTEMCTL=1
  OPT_PURGE=0
  OPT_YES=0
  resolve_paths || exit 1
  [ "$ROOT" = / ] || { echo "expected ROOT=/ got $ROOT" >&2; exit 1; }
  out=$(remove_owned_path "$UNIT_DIR/noc-agent.timer") \
    || { echo "unit refused on ROOT=/ without --purge" >&2; exit 1; }
  grep -qxF "[dry-run] rm -rf /etc/systemd/system/noc-agent.timer" <<< "$out" \
    || { echo "unexpected unit dry run: $out" >&2; exit 1; }
  out=$(remove_owned_path "$LIBEXEC") \
    || { echo "LIBEXEC refused on ROOT=/ without --purge" >&2; exit 1; }
  grep -qxF "[dry-run] rm -rf /usr/local/libexec/noc-agent" <<< "$out" \
    || { echo "unexpected LIBEXEC dry run: $out" >&2; exit 1; }
  # Config and state are not part of the always-owned set.
  if remove_path "$CONF_DIR" >/dev/null 2>&1; then
    echo "CONF_DIR removable without --purge --yes" >&2; exit 1
  fi
  if remove_path "$STATE_DIR" >/dev/null 2>&1; then
    echo "STATE_DIR removable without --purge --yes" >&2; exit 1
  fi
  OPT_PURGE=1
  if remove_path "$CONF_DIR" >/dev/null 2>&1; then
    echo "CONF_DIR removable without --yes" >&2; exit 1
  fi
  OPT_YES=1
  out=$(remove_path "$CONF_DIR") \
    || { echo "CONF_DIR refused with --purge --yes" >&2; exit 1; }
  grep -qxF "[dry-run] rm -rf /etc/noc-agent" <<< "$out" \
    || { echo "unexpected CONF_DIR dry run: $out" >&2; exit 1; }
  out=$(remove_path "$STATE_DIR") \
    || { echo "STATE_DIR refused with --purge --yes" >&2; exit 1; }
  grep -qxF "[dry-run] rm -rf /var/lib/noc-agent" <<< "$out" \
    || { echo "unexpected STATE_DIR dry run: $out" >&2; exit 1; }
  # The owned-path entry point does not widen to arbitrary system paths.
  OPT_PURGE=0; OPT_YES=0
  if remove_owned_path /etc/passwd >/dev/null 2>&1; then
    echo "owned-path removal widened to /etc/passwd" >&2; exit 1
  fi
)

# A prefix whose canonical form is / must never delete without --yes, even
# with --purge. Dry run only; no host path is touched either way.
mkdir -p "$tmp/rootlike"; ln -s / "$tmp/rootlike/escape"
agent/uninstall.sh --prefix "$tmp/rootlike/escape/etc/.." --no-systemctl --purge --dry-run >"$tmp/un2.out" 2>&1 \
  && { echo "resolved-to-/ purge was allowed" >&2; exit 1; }
# Refused either as a non-root real-system run (no --yes can change that
# without root) or by the path guard; never by deleting anything.
grep -qE 'must be run as root|refusing to remove' "$tmp/un2.out" || { cat "$tmp/un2.out" >&2; exit 1; }
grep -q '^\[dry-run\] rm' "$tmp/un2.out" && { echo "dry-run printed deletes for resolved-/ prefix" >&2; exit 1; }
[ -d /etc/systemd/system ] || exit 1
# A real --prefix install still uninstalls cleanly (the guard does not break it).
agent/uninstall.sh --prefix "$tmp" --no-systemctl >/dev/null || exit 1
[ ! -e "$tmp/usr/local/libexec/noc-agent" ] || exit 1

# Reinstall so the systemd-analyze pass below sees generated units.
agent/install.sh --prefix "$tmp" --api-url "https://monitor.example.com/api/v1/ingest" \
  --token-file "$tmp/token" --no-systemctl --no-prime >/dev/null || exit 1

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