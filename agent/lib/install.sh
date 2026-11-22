#!/bin/bash
# Shared helpers for the NOC agent installer and uninstaller.
#
# This file is sourced, never executed directly, and performs no work at
# source time other than defining variables and functions. It provides:
#
#   _err MSG / _log MSG / _warn MSG   — logging to stderr/stdout.
#   parse_common_args ARGS...         — --prefix, --dry-run, --no-systemctl
#                                       (installer/uninstaller share them).
#   resolve_paths                     — map every install path under ROOT.
#   require_root_or_prefix            — refuse non-root unless ROOT != /.
#   run CMD...                        — run (or, in a dry run, print) a command.
#   install_file SRC DEST MODE        — copy a file with an explicit mode.
#   write_file DEST MODE              — write stdin to DEST with a mode.
#   systemctl_do ARGS...              — systemctl, or print when disabled.
#
# All paths are resolved relative to ROOT so the installer can be exercised
# in a scratch prefix without touching the real system: the systemd unit
# files name these absolute paths (they are generated from the resolved
# prefix), and `systemctl` only ever runs when --no-systemctl is absent.

# --- Resolved paths (set by resolve_paths) ---------------------------------
ROOT="/"
LIBEXEC=""
CONF_DIR=""
STATE_DIR=""
UNIT_DIR=""
CONFIG_FILE=""
TOKEN_FILE=""

# --- Parsed options --------------------------------------------------------
OPT_PREFIX=""
OPT_DRY_RUN=0
OPT_NO_SYSTEMCTL=0

_err() { printf 'noc-agent: %s\n' "$*" >&2; }
_log() { printf 'noc-agent: %s\n' "$*"; }

# _warn MSG -> non-fatal warning.
_warn() { printf 'noc-agent: warning: %s\n' "$*" >&2; }

# parse_common_args ARGS... -> set OPT_PREFIX/OPT_DRY_RUN/OPT_NO_SYSTEMCTL.
# Both scripts accept the same --prefix/--dry-run/--no-systemctl options.
parse_common_args() {
  while (($#)); do
    case ${1-} in
      --prefix)
        if [[ -z ${2-} ]]; then _err "--prefix requires a directory"; return 1; fi
        OPT_PREFIX=$2; shift 2 ;;
      --dry-run) OPT_DRY_RUN=1; shift ;;
      --no-systemctl) OPT_NO_SYSTEMCTL=1; shift ;;
      *) _err "unknown option: ${1-}"; return 1 ;;
    esac
  done
  return 0
}

# resolve_paths -> normalize ROOT from OPT_PREFIX (default "/") and derive the
# installer's paths underneath it. ROOT is canonicalized with realpath -m so a
# symlinked or ".."-laden prefix cannot make a derived path escape the prefix.
resolve_paths() {
  local prefix=${OPT_PREFIX:-/}
  local base
  if [[ -z $prefix ]]; then _err "--prefix requires a non-empty directory"; return 1; fi
  # A relative prefix is taken relative to the current directory.
  if [[ $prefix != /* ]]; then prefix="$PWD/$prefix"; fi
  if which_cmd realpath; then
    ROOT=$(realpath -m -- "$prefix") || return 1
  else
    # Fallback: resolve existing components manually is not portable; refuse.
    _err "realpath is required to resolve --prefix safely"
    return 1
  fi
  [[ -z $ROOT ]] && ROOT=/
  # A real-system root contributes no prefix of its own; this keeps the
  # derived paths as /etc/... rather than //etc/....
  base=$ROOT
  [[ $ROOT == / ]] && base=""
  LIBEXEC="$base/usr/local/libexec/noc-agent"
  CONF_DIR="$base/etc/noc-agent"
  STATE_DIR="$base/var/lib/noc-agent"
  UNIT_DIR="$base/etc/systemd/system"
  CONFIG_FILE="$CONF_DIR/agent.conf"
  TOKEN_FILE="$CONF_DIR/token"
  return 0
}

# path_under_root PATH -> 0 when the canonical PATH is strictly inside the
# canonical ROOT (a symlinked parent that escapes ROOT is refused). Returns 2
# when canonical ROOT is "/", which has no prefix to bound the path; the
# caller decides whether that specific path is one it owns. Returns 1
# otherwise (including a path equal to ROOT itself).
path_under_root() {
  local path=${1-} resolved root_real
  [[ -n $path ]] || return 1
  which_cmd realpath || return 1
  resolved=$(realpath -m -- "$path") || return 1
  root_real=$(realpath -m -- "$ROOT") || return 1
  # Check the "/" case before any prefix pattern: with ROOT=/ the pattern
  # would be "//*", which no real path matches, so every legitimate
  # system path would be refused.
  if [[ $root_real == / ]]; then
    return 2
  fi
  [[ $resolved == "$root_real"/* ]] || return 1
  return 0
}

# require_root_or_prefix -> an install/uninstall that targets the real system
# (ROOT=/) requires root; a prefixed run is allowed for tests.
require_root_or_prefix() {
  if (( $(id -u) == 0 )); then return 0; fi
  if [[ $ROOT == / ]]; then
    _err "must be run as root (use --prefix DIR to install into a scratch directory)"
    return 1
  fi
  return 0
}

# run CMD... -> execute CMD, or print it in a dry run.
run() {
  if (( OPT_DRY_RUN )); then
    printf '[dry-run] %s\n' "$*"
    return 0
  fi
  "$@"
}

# run_quiet CMD... -> like run, but logs the command itself (used for tools
# whose own chatter is not useful, e.g. restorecon).
run_quiet() {
  if (( OPT_DRY_RUN )); then
    printf '[dry-run] %s\n' "$*"
    return 0
  fi
  "$@" >/dev/null 2>&1
}

# make_dir PATH MODE -> create PATH and enforce MODE.
make_dir() {
  local dir=${1-} mode=${2-755}
  run install -d -m "$mode" "$dir"
}

# install_file SRC DEST MODE -> copy SRC to DEST (owner root when root).
install_file() {
  local src=${1-} dest=${2-} mode=${3-700}
  if (( OPT_DRY_RUN )); then
    printf '[dry-run] install -m %s %s %s\n' "$mode" "$src" "$dest"
    return 0
  fi
  install -m "$mode" -o "$(id -un)" -g "$(id -gn)" "$src" "$dest"
}

# write_file DEST MODE -> write stdin to DEST with MODE (atomic replace).
write_file() {
  local dest=${1-} mode=${2-600} tmp
  if (( OPT_DRY_RUN )); then
    printf '[dry-run] write %s (mode %s)\n' "$dest" "$mode"
    cat >/dev/null
    return 0
  fi
  tmp="$dest.tmp.$$"
  ( umask 077; cat > "$tmp" ) || { rm -f "$tmp"; return 1; }
  chmod "$mode" "$tmp" || { rm -f "$tmp"; return 1; }
  mv -f "$tmp" "$dest"
}

# systemctl_do ARGS... -> systemctl when enabled, printed in a dry run, and
# skipped entirely with --no-systemctl.
systemctl_do() {
  if (( OPT_NO_SYSTEMCTL )); then
    printf '[no-systemctl] systemctl %s\n' "$*"
    return 0
  fi
  run systemctl "$@"
}

# which_cmd NAME -> 0 when NAME is on PATH.
which_cmd() { command -v "$1" >/dev/null 2>&1; }