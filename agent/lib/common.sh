#!/bin/bash
# Shared runtime helpers for the NOC agent core.
#
# This file is sourced (never executed directly). It defines:
#
#   config_defaults()      — set every config key to its documented default.
#   load_config FILE       — source a securely-owned config file.
#   validate_config()      — exit 1 when any config value is invalid.
#   require_secure_file P  — reject symlinks, wrong owner, group/world write.
#   require_secure_dir P   — same checks for a directory.
#   validate_token_file P  — set SERVER_TOKEN from a secure token file.
#   get_agent_id()         — print a stable UUID, created atomically.
#   acquire_lock LOCKFILE  — flock(1) on fd 9; return 1 when busy.
#
# Security model (design §10): the agent runs as one effective UID (root in
# production). Config, token, state, run, and cache paths must be owned by
# that UID, must not be symlinks, and must not be group/world-writable. Token
# files are additionally required to be unreadable by group/others, since the
# token is the only authorization secret.

# shellcheck source=lib/url.sh
source "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/url.sh"

# ---------------------------------------------------------------------------
# Small helpers
# ---------------------------------------------------------------------------

_err() { printf 'noc-agent: %s\n' "$*" >&2; }

# _owned_by_me PATH -> 0 when PATH is owned by the effective UID.
_owned_by_me() {
  local owner
  owner=$(stat -c '%u' "$1" 2>/dev/null) || return 1
  [[ $owner == "$(id -u)" ]]
}

# ---------------------------------------------------------------------------
# Config defaults (design §11)
# ---------------------------------------------------------------------------

config_defaults() {
  API_URL="https://monitor.example.com/api/v1/ingest"
  SERVER_TOKEN_FILE="/etc/noc-agent/token"
  INTERVAL_SECONDS=60
  PANEL="auto"
  PANEL_DETECT_TIMEOUT=2
  PANEL_TIMEOUT=10
  CURL_TIMEOUT=8
  MAX_RUN_SECONDS=45
  STATE_DIR="/var/lib/noc-agent"
  TLS_VERIFY=1
  ALLOW_INSECURE_HTTP=0
  DEBUG=0

  ENABLE_MYSQL=1
  ENABLE_SMART=1
  ENABLE_DISKQUOTA=1
  ENABLE_IMUNIFY=1
  MODULE_DETECT_TIMEOUT=2
  MODULE_TIMEOUT=5
  HEAVY_MODULE_TIMEOUT=240
  QUOTA_TOP_N=15
  MYSQL_MAX_QUERIES=10
  MYSQL_MIN_SECONDS=5
  MYSQL_INCLUDE_QUERY_TEXT=1
  SMART_MAX_DISKS=32
}

# ---------------------------------------------------------------------------
# Secure path checks
# ---------------------------------------------------------------------------

# require_secure_file PATH -> 0 when secure; 1 (and a log) otherwise.
require_secure_file() {
  local path=${1-} mode
  if [[ -z $path ]]; then _err "require_secure_file: empty path"; return 1; fi
  if [[ -L $path ]]; then _err "require_secure_file: $path is a symlink"; return 1; fi
  if [[ ! -f $path ]]; then _err "require_secure_file: $path is not a regular file"; return 1; fi
  if ! _owned_by_me "$path"; then _err "require_secure_file: $path is not owned by uid $(id -u)"; return 1; fi
  mode=$(stat -c '%a' "$path") || return 1
  if (( 8#$mode & 0022 )); then
    _err "require_secure_file: $path is group/world writable"
    return 1
  fi
  return 0
}

# require_secure_dir PATH -> 0 when secure; 1 (and a log) otherwise.
require_secure_dir() {
  local path=${1-} mode
  if [[ -z $path ]]; then _err "require_secure_dir: empty path"; return 1; fi
  if [[ -L $path ]]; then _err "require_secure_dir: $path is a symlink"; return 1; fi
  if [[ ! -d $path ]]; then _err "require_secure_dir: $path is not a directory"; return 1; fi
  if ! _owned_by_me "$path"; then _err "require_secure_dir: $path is not owned by uid $(id -u)"; return 1; fi
  mode=$(stat -c '%a' "$path") || return 1
  if (( 8#$mode & 0022 )); then
    _err "require_secure_dir: $path is group/world writable"
    return 1
  fi
  return 0
}

# ---------------------------------------------------------------------------
# Config loading and validation
# ---------------------------------------------------------------------------

# load_config FILE -> source a securely-owned config file into the shell and
# export the non-secret module tuning settings, so external modules run as
# subprocesses see the configured values (not just their built-in defaults).
load_config() {
  local path=${1-}
  require_secure_file "$path" || return 1
  # shellcheck disable=SC1090
  source "$path" || return 1
  # Exact whitelist: never export API_URL, the token, or path settings.
  export QUOTA_TOP_N MYSQL_MAX_QUERIES MYSQL_MIN_SECONDS \
    MYSQL_INCLUDE_QUERY_TEXT SMART_MAX_DISKS
}

# _check_range NAME MIN MAX -> 0 when $NAME is an integer within [MIN, MAX].
_check_range() {
  local name=${1-} min=${2-} max=${3-}
  local val
  val=${!name-}
  if [[ ! $val =~ ^[0-9]+$ ]]; then
    _err "config error: $name must be an integer"
    return 1
  fi
  if (( val < min || val > max )); then
    _err "config error: $name must be $min-$max"
    return 1
  fi
  return 0
}

# _validate_panel VALUE -> 0 for auto|none or a plugin identifier.
_validate_panel() {
  local panel=${1-}
  case $panel in
    auto|none) return 0 ;;
  esac
  [[ $panel =~ ^[A-Za-z0-9][A-Za-z0-9_.-]*$ ]]
}

# validate_config -> exit 1 on the first invalid value (design §11 ranges).
validate_config() {
  _check_range INTERVAL_SECONDS 10 3600 || exit 1
  _check_range PANEL_DETECT_TIMEOUT 1 5 || exit 1
  _check_range PANEL_TIMEOUT 3 20 || exit 1
  _check_range CURL_TIMEOUT 3 15 || exit 1
  _check_range MAX_RUN_SECONDS 20 45 || exit 1
  _check_range MODULE_DETECT_TIMEOUT 1 5 || exit 1
  _check_range MODULE_TIMEOUT 1 15 || exit 1
  _check_range HEAVY_MODULE_TIMEOUT 30 600 || exit 1
  _check_range QUOTA_TOP_N 1 50 || exit 1
  _check_range MYSQL_MAX_QUERIES 0 50 || exit 1
  _check_range MYSQL_MIN_SECONDS 1 3600 || exit 1
  _check_range SMART_MAX_DISKS 1 64 || exit 1

  local b val
  for b in TLS_VERIFY ALLOW_INSECURE_HTTP DEBUG \
           ENABLE_MYSQL ENABLE_SMART ENABLE_DISKQUOTA ENABLE_IMUNIFY \
           MYSQL_INCLUDE_QUERY_TEXT; do
    val=${!b-}
    if [[ $val != 0 && $val != 1 ]]; then
      _err "config error: $b must be 0 or 1"
      exit 1
    fi
  done

  _validate_panel "${PANEL-}" \
    || { _err "config error: PANEL must be auto, none, or a plugin name"; exit 1; }

  validate_api_url "${API_URL-}" "${ALLOW_INSECURE_HTTP-0}" \
    || { _err "config error: API_URL must be https (or http://127.0.0.1|localhost with ALLOW_INSECURE_HTTP=1)"; exit 1; }

  return 0
}

# ---------------------------------------------------------------------------
# Token
# ---------------------------------------------------------------------------

# validate_token_file PATH -> set SERVER_TOKEN from a secure token file.
# Returns 1 (and logs) on symlink, wrong owner, group/world access, or a
# token that does not match ^[A-Za-z0-9._~-]{16,256}$ (design §10.2).
validate_token_file() {
  local path=${1-} mode content
  require_secure_file "$path" || return 1
  # A token file is the one secret: reject any group/other access, not just
  # group/world write (so a 644 token is refused even though it is not
  # writable).
  mode=$(stat -c '%a' "$path") || return 1
  if (( 8#$mode & 0077 )); then
    _err "validate_token_file: $path is readable/writable by group or others"
    return 1
  fi
  content=$(<"$path") || return 1
  content=${content%$'\n'}
  content=${content%$'\r'}
  if [[ ! $content =~ ^[A-Za-z0-9._~-]{16,256}$ ]]; then
    _err "validate_token_file: $path does not contain a valid token"
    return 1
  fi
  SERVER_TOKEN=$content
  return 0
}

# ---------------------------------------------------------------------------
# Agent ID
# ---------------------------------------------------------------------------

# _gen_uuid -> print a (version-4 style) UUID without external dependencies.
_gen_uuid() {
  local u hex
  if [[ -r /proc/sys/kernel/random/uuid ]]; then
    IFS= read -r u < /proc/sys/kernel/random/uuid
    if [[ $u =~ ^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$ ]]; then
      printf '%s\n' "$u"
      return 0
    fi
  fi
  if command -v uuidgen >/dev/null 2>&1; then
    if u=$(uuidgen 2>/dev/null); then
      printf '%s\n' "$u"
      return 0
    fi
  fi
  hex=$(od -An -N16 -tx1 /dev/urandom 2>/dev/null | tr -d ' \n')
  if [[ ${#hex} -eq 32 ]]; then
    printf '%s\n' "${hex:0:8}-${hex:8:4}-4${hex:13:3}-8${hex:17:3}-${hex:20:12}"
    return 0
  fi
  # Last resort: not cryptographically strong, but agent.id is diagnostic only.
  printf -v u '%04x%04x-%04x-4%03x-8%03x-%04x%04x%04x' \
    "$RANDOM" "$RANDOM" "$RANDOM" "$((RANDOM & 0xfff))" "$((RANDOM & 0xfff))" \
    "$RANDOM" "$RANDOM" "$RANDOM"
  printf '%s\n' "$u"
}

# get_agent_id -> print the stable agent UUID, creating $STATE_DIR/agent-id
# (mode 600) atomically when the state dir is writable.
get_agent_id() {
  local dir=${STATE_DIR:-/var/lib/noc-agent}
  local file=$dir/agent-id
  local id tmp
  if [[ -f $file && ! -L $file ]]; then
    id=$(<"$file")
    id=${id%$'\n'}
    if [[ -n $id ]]; then
      printf '%s\n' "$id"
      return 0
    fi
  fi
  id=$(_gen_uuid)
  if [[ -d $dir && -w $dir ]]; then
    tmp="$dir/.agent-id.$$"
    ( umask 077; printf '%s\n' "$id" > "$tmp" ) 2>/dev/null
    if [[ -f $tmp ]]; then
      chmod 600 "$tmp" 2>/dev/null
      mv -f "$tmp" "$file" 2>/dev/null || rm -f "$tmp"
    fi
  fi
  printf '%s\n' "$id"
  return 0
}

# ---------------------------------------------------------------------------
# Lock
# ---------------------------------------------------------------------------

# acquire_lock LOCKFILE -> open fd 9 on LOCKFILE and flock -n it.
# Returns 0 when the lock is held, 1 when it is busy (design §7 step 7).
acquire_lock() {
  local lockfile=${1-}
  if [[ -z $lockfile ]]; then _err "acquire_lock: empty lockfile"; return 1; fi
  exec 9>"$lockfile" 2>/dev/null || { _err "acquire_lock: cannot open $lockfile"; return 1; }
  if flock -n 9 2>/dev/null; then
    return 0
  fi
  exec 9>&- 2>/dev/null
  return 1
}
