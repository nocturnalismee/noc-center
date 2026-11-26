#!/bin/bash
# NOC monitoring agent updater.
#
# Upgrades an existing install from a newer source tree. The installer remains
# the only component that writes the install; this wrapper verifies the source,
# snapshots the previous install, preserves config/token, and rolls back when
# the new installer fails.
#
# Options:
#   --source DIR       newer agent source tree (default: this script's directory)
#   --prefix DIR       update underneath DIR (default /); required for tests
#   --check            print installed/source versions and exit
#   --dry-run          print the update plan without changing anything
#   --no-systemctl     never call systemctl (containers, tests, prefixes)
#   --no-prime         do not prime external-module caches
#   --test             run the installer's post-install collector test
#   --help             show this help and exit
#
# Exit codes: 0 success/check, 1 usage or precondition failure, 2 install failed
# and rollback completed.

set -euo pipefail
export LC_ALL=C

SELF_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib/install.sh
source "$SELF_DIR/lib/install.sh"

OPT_SOURCE=""
OPT_NO_PRIME=0
OPT_TEST=0
OPT_CHECK=0
OPT_HELP=0

TIMERS=(noc-agent.timer noc-agent-smart.timer noc-agent-diskquota.timer noc-agent-imunify.timer)
UNITS=(noc-agent.service noc-agent@.service "${TIMERS[@]}")

usage() {
  sed -n '2,/^$/p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'
}

parse_args() {
  while (($#)); do
    case ${1-} in
      --source)
        if [[ -z ${2-} ]]; then _err "--source requires a directory"; return 1; fi
        OPT_SOURCE=$2; shift 2 ;;
      --no-prime) OPT_NO_PRIME=1; shift ;;
      --test) OPT_TEST=1; shift ;;
      --check) OPT_CHECK=1; shift ;;
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

version_of() {
  sed -n 's/^AGENT_VERSION="\([^"]*\)".*/\1/p' "$1" 2>/dev/null | head -n 1
}

run_installer() {
  local source=${1-}
  shift
  local -a args=()

  [[ -n $OPT_PREFIX ]] && args+=(--prefix "$OPT_PREFIX")
  (( OPT_NO_SYSTEMCTL )) && args+=(--no-systemctl)
  (( OPT_NO_PRIME )) && args+=(--no-prime)
  (( OPT_TEST )) && args+=(--test)
  args+=("$@")

  bash "$source/install.sh" "${args[@]}"
}

restore() {
  local backup=${1-} unit

  _err "restoring previous install from $backup"
  rm -rf -- "$LIBEXEC"
  cp -a -- "$backup/libexec" "$LIBEXEC"
  cp -a -- "$backup/agent.conf" "$CONFIG_FILE"
  cp -a -- "$backup/token" "$TOKEN_FILE"

  for unit in "${UNITS[@]}"; do
    cp -a -- "$backup/$unit" "$UNIT_DIR/$unit"
  done

  systemctl_do daemon-reload || _warn "rollback: daemon-reload failed"
  systemctl_do enable --now "${TIMERS[@]}" || _warn "rollback: enabling timers failed"
}

prune_backups() {
  local -a backups=()
  local i

  mapfile -t backups < <(
    find "$STATE_DIR/updates" -mindepth 1 -maxdepth 1 -type d -printf '%T@ %p\n' \
      | sort -rn \
      | cut -d' ' -f2-
  )

  for ((i = 5; i < ${#backups[@]}; i++)); do
    rm -rf -- "${backups[i]}" || _warn "could not remove old update backup ${backups[i]}"
  done
}

main() {
  parse_args "$@" || { usage >&2; return 1; }
  if (( OPT_HELP )); then
    usage
    return 0
  fi

  resolve_paths || return 1
  require_root_or_prefix || return 1

  if [[ $ROOT != / ]] && (( $(id -u) != 0 )) && [[ ${NOC_AGENT_ALLOW_SYSTEMCTL:-0} != 1 ]]; then
    OPT_NO_SYSTEMCTL=1
  fi

  local source=${OPT_SOURCE:-$SELF_DIR}
  if [[ ! -d $source ]]; then
    _err "--source is not a directory: $source"
    return 1
  fi
  source=$(cd "$source" && pwd -P) || return 1

  local required
  for required in install.sh collector.sh lib/install.sh; do
    if [[ ! -f "$source/$required" ]]; then
      _err "source is missing $required: $source"
      return 1
    fi
  done

  if [[ ! -f $CONFIG_FILE || ! -f $TOKEN_FILE || ! -x $LIBEXEC/collector.sh ]]; then
    _err "no existing agent install found under $ROOT; run install.sh first"
    return 1
  fi

  local installed_version source_version
  installed_version=$(version_of "$LIBEXEC/collector.sh")
  source_version=$(version_of "$source/collector.sh")
  installed_version=${installed_version:-unknown}
  source_version=${source_version:-unknown}

  if (( OPT_CHECK )); then
    printf 'noc-agent installed: %s\n' "$installed_version"
    printf 'noc-agent source:    %s\n' "$source_version"
    return 0
  fi

  _log "updating noc-agent $installed_version -> $source_version"

  if (( OPT_DRY_RUN )); then
    _log "would back up $LIBEXEC and units to $STATE_DIR/updates/<timestamp>"
    run_installer "$source" --dry-run
    return 0
  fi

  which_cmd flock || { _err "flock is required for update"; return 1; }
  make_dir "$STATE_DIR" 700
  make_dir "$STATE_DIR/updates" 700

  exec 9>"$STATE_DIR/update.lock"
  if ! flock -n 9; then
    _err "another agent update is already running"
    return 1
  fi

  local backup
  backup="$STATE_DIR/updates/$(date -u +%Y%m%dT%H%M%SZ)-$$"
  make_dir "$backup" 700
  cp -a -- "$LIBEXEC" "$backup/libexec"
  cp -a -- "$CONFIG_FILE" "$backup/agent.conf"
  cp -a -- "$TOKEN_FILE" "$backup/token"

  local unit
  for unit in "${UNITS[@]}"; do
    cp -a -- "$UNIT_DIR/$unit" "$backup/$unit"
  done

  _log "backup: $backup"

  local status=0
  set +e
  run_installer "$source"
  status=$?
  set -e

  if (( status != 0 )); then
    restore "$backup"
    _err "update failed; previous install restored"
    return 2
  fi

  prune_backups
  _log "updated noc-agent to $source_version"
  return 0
}

main "$@"
