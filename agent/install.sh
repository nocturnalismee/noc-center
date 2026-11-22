#!/bin/bash
# NOC monitoring agent installer (design §13).
#
# Installs the collector, panels, modules, systemd units, config, and token.
# Idempotent: re-running is safe and never overwrites the config or token
# unless --force is given.
#
# Options:
#   --api-url URL        API_URL for a newly created config
#   --token-file PATH    read the server token from PATH (never from argv);
#                        a newly created token file is installed mode 600
#   --interval SECONDS   main collection interval (10-3600); also written to a
#                        newly created config as INTERVAL_SECONDS
#   --prefix DIR         install everything underneath DIR (default /);
#                        required for non-root runs and automated tests
#   --dry-run            print every action without changing anything
#   --no-systemctl       never call systemctl (containers, tests, prefixes)
#   --no-prime           do not prime the external-module caches
#   --force              overwrite an existing config (and token)
#   --test               dry-run the installed collector after installing
#   --help               show this help and exit
#
# Exit codes: 0 success, 1 usage/config/token error.

set -euo pipefail
export LC_ALL=C

SELF_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib/install.sh
source "$SELF_DIR/lib/install.sh"
# shellcheck source=lib/url.sh
source "$SELF_DIR/lib/url.sh"

OPT_API_URL=""
OPT_TOKEN_FILE=""
OPT_INTERVAL=""
OPT_NO_PRIME=0
OPT_FORCE=0
OPT_TEST=0
OPT_HELP=0

TIMERS=(noc-agent.timer noc-agent-smart.timer noc-agent-diskquota.timer noc-agent-imunify.timer)
PRIME_UNITS=(noc-agent@smart.service noc-agent@diskquota.service noc-agent@imunify.service)
SCRIPTS=(collector.sh)

usage() {
  sed -n '2,/^$/p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'
}

parse_args() {
  while (($#)); do
    case ${1-} in
      --api-url) OPT_API_URL=${2-}; shift 2 ;;
      --token-file) OPT_TOKEN_FILE=${2-}; shift 2 ;;
      --interval) OPT_INTERVAL=${2-}; shift 2 ;;
      --no-prime) OPT_NO_PRIME=1; shift ;;
      --force) OPT_FORCE=1; shift ;;
      --test) OPT_TEST=1; shift ;;
      --help|-h) OPT_HELP=1; shift ;;
      --prefix)
        if [[ -z ${2-} ]]; then _err "--prefix requires a directory"; return 1; fi
        OPT_PREFIX=${2-}; shift 2 ;;
      --dry-run) OPT_DRY_RUN=1; shift ;;
      --no-systemctl) OPT_NO_SYSTEMCTL=1; shift ;;
      *) _err "unknown option: ${1-}"; usage >&2; return 1 ;;
    esac
  done
  return 0
}

# _require_cmds -> fail early when a runtime dependency is missing. Skipped in
# dry runs (see main), which only print actions.
require_cmds() {
  local missing=() c
  for c in bash curl flock install; do
    which_cmd "$c" || missing+=("$c")
  done
  if ((${#missing[@]})); then
    _err "missing required command(s): ${missing[*]}"
    return 1
  fi
  if (( ! OPT_NO_SYSTEMCTL )) && ! which_cmd systemctl; then
    _err "systemctl not found (use --no-systemctl for a prefix/container install)"
    return 1
  fi
  return 0
}

# _validate_interval VALUE -> seconds when 10-3600, else error.
validate_interval() {
  local v=${1-}
  if [[ ! $v =~ ^[0-9]+$ ]] || (( v < 10 || v > 3600 )); then
    _err "--interval must be an integer between 10 and 3600"
    return 1
  fi
  printf '%s' "$v"
}

# _token_from_file PATH -> print the token after validating its content.
_read_token() {
  local path=${1-} token
  if [[ ! -f $path || -L $path ]]; then
    _err "--token-file must name a regular (non-symlink) file"
    return 1
  fi
  token=$(<"$path")
  token=${token%$'\n'}
  token=${token%$'\r'}
  if [[ ! $token =~ ^[A-Za-z0-9._~-]{16,256}$ ]]; then
    _err "--token-file does not contain a valid token"
    return 1
  fi
  printf '%s\n' "$token"
}

# _create_config -> write agent.conf from the example with API_URL/STATE_DIR/
# INTERVAL_SECONDS applied and the token path pointed at CONF_DIR.
create_config() {
  local interval=${1-} tmp line
  if [[ -e $CONFIG_FILE && ! -L $CONFIG_FILE ]] && (( ! OPT_FORCE )); then
    _log "keeping existing config $CONFIG_FILE (use --force to replace)"
    return 0
  fi
  # Loopback http:// (local tests) also needs ALLOW_INSECURE_HTTP=1. Only an
  # exact loopback URL qualifies; the shared validator makes that decision.
  local allow=0
  api_url_is_loopback_http "$OPT_API_URL" && allow=1
  if (( OPT_DRY_RUN )); then
    printf '[dry-run] write %s (mode 600)\n' "$CONFIG_FILE"
    return 0
  fi
  tmp=$(mktemp "${TMPDIR:-/tmp}/noc-agent.conf.XXXXXX") || { _err "cannot create a temporary config"; return 1; }
  # Rewrite the install-dependent keys; keep every other documented default
  # and comment from the example verbatim.
  while IFS= read -r line || [[ -n $line ]]; do
    case $line in
      API_URL=*) printf 'API_URL="%s"\n' "$OPT_API_URL" ;;
      SERVER_TOKEN_FILE=*) printf 'SERVER_TOKEN_FILE="%s"\n' "$TOKEN_FILE" ;;
      INTERVAL_SECONDS=*) printf 'INTERVAL_SECONDS=%s\n' "$interval" ;;
      STATE_DIR=*) printf 'STATE_DIR="%s"\n' "$STATE_DIR" ;;
      ALLOW_INSECURE_HTTP=*) printf 'ALLOW_INSECURE_HTTP=%s\n' "$allow" ;;
      *) printf '%s\n' "$line" ;;
    esac
  done < "$SELF_DIR/config/agent.conf.example" > "$tmp"
  chmod 600 "$tmp"
  mv -f "$tmp" "$CONFIG_FILE"
  _log "wrote config $CONFIG_FILE"
}

# _install_token -> install the token file from --token-file, mode 600.
install_token() {
  if [[ -z $OPT_TOKEN_FILE ]]; then
    _warn "no --token-file given; $TOKEN_FILE must be created separately (mode 600)"
    return 0
  fi
  if [[ -e $TOKEN_FILE && ! -L $TOKEN_FILE ]] && (( ! OPT_FORCE )); then
    _log "keeping existing token $TOKEN_FILE (use --force to replace)"
    return 0
  fi
  _read_token "$OPT_TOKEN_FILE" | write_file "$TOKEN_FILE" 600
  _log "installed token $TOKEN_FILE"
}

# _install_scripts -> copy collector, libraries, panels, and modules mode 700.
install_scripts() {
  local f base
  make_dir "$LIBEXEC" 755
  make_dir "$LIBEXEC/lib" 755
  make_dir "$LIBEXEC/panels" 755
  make_dir "$LIBEXEC/modules" 755
  for f in "${SCRIPTS[@]}"; do
    install_file "$SELF_DIR/$f" "$LIBEXEC/$f" 700
  done
  for f in "$SELF_DIR"/lib/*.sh; do
    install_file "$f" "$LIBEXEC/lib/${f##*/}" 700
  done
  for f in "$SELF_DIR"/panels/*.sh "$SELF_DIR"/modules/*.sh; do
    base=${f%/*}
    install_file "$f" "$LIBEXEC/${base##*/}/${f##*/}" 700
  done
}

# _install_units -> install the service units and timers. The main service
# and module template are rendered from @LIBEXEC@; the main timer from
# @INTERVAL@.
install_units() {
  local interval=${1-} f
  make_dir "$UNIT_DIR" 755
  sed "s|@LIBEXEC@|$LIBEXEC|g" "$SELF_DIR/systemd/noc-agent.service" \
    | write_file "$UNIT_DIR/noc-agent.service" 644
  sed "s|@LIBEXEC@|$LIBEXEC|g" "$SELF_DIR/systemd/noc-agent@.service" \
    | write_file "$UNIT_DIR/noc-agent@.service" 644
  sed "s|@INTERVAL@|${interval}s|g" "$SELF_DIR/systemd/noc-agent.timer.in" \
    | write_file "$UNIT_DIR/noc-agent.timer" 644
  for f in noc-agent-smart.timer noc-agent-diskquota.timer noc-agent-imunify.timer; do
    install_file "$SELF_DIR/systemd/$f" "$UNIT_DIR/$f" 644
  done
}

# _restorecon -> label the new paths when SELinux tooling is available.
# Never fatal (design §10.6): a missing restorecon is not an error.
restorecon_paths() {
  if ! which_cmd restorecon; then
    _log "restorecon not available; skipping SELinux relabel"
    return 0
  fi
  local p
  for p in "$LIBEXEC" "$CONF_DIR" "$STATE_DIR"; do
    [[ -e $p ]] || continue
    if ! run_quiet restorecon -RF "$p"; then
      _warn "restorecon -RF $p failed; if SELinux is enforcing add a semanage fcontext rule for it"
    fi
  done
}

# _prime_and_test -> start the timers, prime the caches, then (with --test)
# validate the installed config with one dry run.
activate() {
  if (( OPT_NO_SYSTEMCTL )); then
    printf '[no-systemctl] skipping daemon-reload/enable/start of %s\n' "${TIMERS[*]}"
    test_collector
    return 0
  fi
  systemctl_do daemon-reload
  systemctl_do enable --now "${TIMERS[@]}"
  if (( ! OPT_NO_PRIME )); then
    # Priming failures never fail the install; the timers retry later.
    local u
    for u in "${PRIME_UNITS[@]}"; do
      systemctl_do start --no-block "$u" || _warn "priming $u failed"
    done
  fi
  test_collector
}

# test_collector -> with --test, validate the installed config with one
# dry-run of the installed collector (no token, no HTTP), then trigger a real
# run through systemd when systemctl is in use. Skipped in dry runs.
test_collector() {
  (( OPT_TEST )) || return 0
  if (( OPT_DRY_RUN )); then
    printf '[dry-run] %s --dry-run --config %s --state-dir %s --run-dir %s\n' \
      "$LIBEXEC/collector.sh" "$CONFIG_FILE" "$STATE_DIR" "$STATE_DIR"
    return 0
  fi
  if [[ ! -f $CONFIG_FILE ]]; then
    _warn "--test: $CONFIG_FILE does not exist; skipping the config dry run"
    return 0
  fi
  # The collector dry run validates the installed config against the installed
  # state/run dirs; it reads no token and performs no HTTP. --module-dir and
  # --panel-dir keep it exercising the installed modules/panels, not $SELF_DIR.
  if ! "$LIBEXEC/collector.sh" --dry-run --config "$CONFIG_FILE" \
      --state-dir "$STATE_DIR" --run-dir "$STATE_DIR" \
      --module-dir "$LIBEXEC/modules" --panel-dir "$LIBEXEC/panels" >/dev/null; then
    _err "--test: the installed collector failed its dry run"
    return 1
  fi
  _log "--test: collector dry run succeeded"
  if (( ! OPT_NO_SYSTEMCTL )); then
    # Then trigger a real run through systemd (spec §13 step 10).
    systemctl_do start noc-agent.service || _warn "--test: starting noc-agent.service failed"
  fi
  return 0
}

main() {
  parse_args "$@" || { usage >&2; return 1; }
  if (( OPT_HELP )); then
    usage
    return 0
  fi
  resolve_paths || return 1
  require_root_or_prefix || return 1
  # A prefixed, non-root install cannot manage the host's systemd; behave as
  # if --no-systemctl were given instead of failing on systemctl permissions.
  # NOC_AGENT_ALLOW_SYSTEMCTL=1 opts out (tests use it with a stubbed
  # systemctl on PATH; it never reaches the host's real systemd).
  if [[ $ROOT != / ]] && (( $(id -u) != 0 )) && [[ ${NOC_AGENT_ALLOW_SYSTEMCTL:-0} != 1 ]]; then
    OPT_NO_SYSTEMCTL=1
  fi
  # A dry run only prints planned actions, so it must not depend on tools it
  # would need to install anything (e.g. `install` in a minimal container).
  if (( ! OPT_DRY_RUN )); then
    require_cmds || return 1
  fi

  # The config is only created when absent (or --force), so API_URL/interval
  # are required only when they would actually be written.
  local interval
  interval=$(validate_interval "${OPT_INTERVAL:-60}") || return 1
  # A fresh, empty prefix has nothing to keep, so --api-url is required only
  # when this run would actually write a config (absent, or --force).
  if [[ ! -e $CONFIG_FILE || -L $CONFIG_FILE ]] || (( OPT_FORCE )); then
    if [[ -z $OPT_API_URL ]]; then
      _err "--api-url is required to create $CONFIG_FILE"
      return 1
    fi
    # The installer accepts exactly the runtime's forms; allow=1 only widens
    # the check to the exact loopback http literals it then records as
    # ALLOW_INSECURE_HTTP=1 in the config.
    if ! validate_api_url "$OPT_API_URL" 1; then
      _err "--api-url must be https (or http://127.0.0.1|localhost for tests)"
      return 1
    fi
  fi
  if [[ -n $OPT_TOKEN_FILE ]]; then
    _read_token "$OPT_TOKEN_FILE" >/dev/null || return 1
  fi

  (( OPT_DRY_RUN )) && _log "dry run: no changes will be made (prefix: $ROOT)"

  make_dir "$CONF_DIR" 700
  make_dir "$STATE_DIR" 700
  make_dir "$STATE_DIR/cache" 700
  install_scripts
  install_units "$interval"
  create_config "$interval"
  install_token
  restorecon_paths
  activate

  _log "installed noc-agent (prefix: $ROOT)"
  return 0
}

main "$@"