#!/bin/bash
# NOC monitoring agent uninstaller.
#
# Stops and disables the four timers, removes the systemd units and the
# installed collector tree, and reloads systemd. The config, token, and state
# directory (including the stable agent id) are kept by default so a reinstall
# keeps the same identity.
#
# Options:
#   --prefix DIR     uninstall underneath DIR (default /); required for
#                    non-root runs and automated tests
#   --dry-run        print every action without changing anything
#   --no-systemctl   never call systemctl (containers, tests, prefixes)
#   --purge          also remove the config directory and the state directory
#   --yes            confirm deletion with --purge when the prefix is /
#   --help           show this help and exit
#
# Exit codes: 0 success, 1 usage error.

set -euo pipefail
export LC_ALL=C

SELF_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib/install.sh
source "$SELF_DIR/lib/install.sh"

OPT_PURGE=0
OPT_YES=0
OPT_HELP=0

TIMERS=(noc-agent.timer noc-agent-smart.timer noc-agent-diskquota.timer noc-agent-imunify.timer)
UNITS=("${TIMERS[@]}" noc-agent.service noc-agent@.service)

usage() {
  sed -n '2,/^$/p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'
}

parse_args() {
  while (($#)); do
    case ${1-} in
      --purge) OPT_PURGE=1; shift ;;
      --yes) OPT_YES=1; shift ;;
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

# _is_uninstall_owned_path PATH -> 0 only for the uninstaller's own fixed,
# non-secret install paths: the systemd units and LIBEXEC. Those are removed
# on a real system (ROOT=/) without --purge. CONF_DIR and STATE_DIR are
# deliberately absent, so they always need --purge --yes.
_is_uninstall_owned_path() {
  local path=${1-} resolved candidate u
  resolved=$(realpath -m -- "$path") || return 1
  candidate=$(realpath -m -- "$LIBEXEC") || return 1
  [[ $resolved == "$candidate" ]] && return 0
  for u in "${UNITS[@]}"; do
    candidate=$(realpath -m -- "$UNIT_DIR/$u") || continue
    [[ $resolved == "$candidate" ]] && return 0
  done
  return 1
}

# _remove_path PATH ALLOW_OWNED -> rm -rf (or print in a dry run), tolerating
# absence. Refuses any path that is not strictly inside ROOT (after realpath,
# so a symlinked parent cannot escape). When ROOT is "/", only the fixed
# owned paths are removable with ALLOW_OWNED (the call site must be one of the
# unit/LIBEXEC removals, and _is_uninstall_owned_path re-checks the canonical
# path); everything else needs --purge --yes.
_remove_path() {
  local path=${1-} allow_owned=${2-0} check=0
  path_under_root "$path" || check=$?
  if (( check == 2 )); then
    if (( allow_owned )) && _is_uninstall_owned_path "$path"; then
      _log "removing real-system path $path"
    elif (( OPT_PURGE && OPT_YES )); then
      _warn "removing real-system path $path (--purge --yes)"
    else
      _err "refusing to remove $path: ROOT is / (re-run with --purge --yes to confirm)"
      return 1
    fi
  elif (( check != 0 )); then
    _err "refusing to remove $path: it resolves outside the install prefix $ROOT"
    return 1
  fi
  if (( OPT_DRY_RUN )); then
    printf '[dry-run] rm -rf %s\n' "$path"
    return 0
  fi
  if [[ -e $path || -L $path ]]; then
    rm -rf --one-file-system -- "$path"
    _log "removed $path"
  fi
  return 0
}

# remove_owned_path PATH -> the fixed unit/LIBEXEC removals. The call sites are
# explicit and limited; the canonical path is checked again in _remove_path.
remove_owned_path() { _remove_path "${1-}" 1; }

# remove_path PATH -> general removal; on ROOT=/ requires --purge --yes.
remove_path() { _remove_path "${1-}" 0; }

# stop_units -> disable the timers and stop any active unit instance.
stop_units() {
  if (( OPT_NO_SYSTEMCTL )); then
    printf '[no-systemctl] skipping systemctl disable/stop of %s\n' "${TIMERS[*]}"
    return 0
  fi
  systemctl_do disable "${TIMERS[@]}" || _warn "disabling timers failed"
  systemctl_do stop "${TIMERS[@]}" || _warn "stopping timers failed"
  systemctl_do stop noc-agent.service noc-agent@smart.service \
    noc-agent@diskquota.service noc-agent@imunify.service 2>/dev/null \
    || _warn "stopping agent units failed"
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

  (( OPT_DRY_RUN )) && _log "dry run: no changes will be made (prefix: $ROOT)"

  stop_units

  local u
  for u in "${UNITS[@]}"; do
    remove_owned_path "$UNIT_DIR/$u" || return 1
  done
  remove_owned_path "$LIBEXEC" || return 1

  if (( ! OPT_NO_SYSTEMCTL )); then
    systemctl_do daemon-reload || _warn "daemon-reload failed"
    systemctl_do reset-failed 2>/dev/null || true
  fi

  if (( OPT_PURGE )); then
    remove_path "$STATE_DIR" || return 1
    remove_path "$CONF_DIR" || return 1
  else
    _log "kept $CONF_DIR (config/token) and $STATE_DIR (agent id/cache); use --purge to remove"
  fi

  _log "uninstalled noc-agent (prefix: $ROOT)"
  return 0
}

if [[ "${BASH_SOURCE[0]}" == "$0" ]]; then
  main "$@"
fi
