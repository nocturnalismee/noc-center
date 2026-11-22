#!/bin/bash
# NOC monitoring agent — collector core.
#
# Lightweight Bash collector that gathers universal metrics, runs exactly one
# panel plugin, runs inline system modules, reads cached external-module
# output, builds a schema-v1 JSON payload, and posts it over HTTPS.
#
# This file is also sourceable so tests can inspect its functions and
# variables without executing main.

set -u
export LC_ALL=C

SELF_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
AGENT_VERSION="0.1.0"

# shellcheck source=lib/common.sh
source "$SELF_DIR/lib/common.sh"
# shellcheck source=lib/json.sh
source "$SELF_DIR/lib/json.sh"

# --- Parsed command-line state ---------------------------------------------
# These variables hold the result of argument parsing. Later tasks consume
# them to drive config loading, collection, and sending.
CONFIG_FILE="/etc/noc-agent/agent.conf"   # --config
OPT_STATE_DIR=""                          # --state-dir
OPT_RUN_DIR=""                            # --run-dir
OPT_MODULE=""                             # --module
OPT_DRY_RUN=0                             # --dry-run
OPT_PANEL=""                              # --panel
OPT_PANEL_DIR=""                          # --panel-dir
OPT_MODULE_DIR=""                         # --module-dir
MAX_PAYLOAD_BYTES=65536                   # --max-payload-bytes
OPT_HELP=0                                # --help
OPT_VERSION=0                             # --version

# --- Runtime state ----------------------------------------------------------
PANEL_NAME=""     # resolved panel name reported in the payload (Task 4)
PANEL_FILE=""     # path to the selected panel plugin (Task 4)
PANEL_TMP=""      # temp file holding collected panel metric lines (Task 4)
EXT_TMP=""        # temp file holding collected module metric lines (Task 5)
TMP_STATE_DIR=""  # temporary state dir created for non-root --dry-run
PAYLOAD_FILE=""   # private temp file holding the final payload (Task 10)

usage() {
  cat <<'EOF'
Usage: collector.sh [options]

Options:
  --config PATH           Config file (default /etc/noc-agent/agent.conf)
  --state-dir PATH        Override STATE_DIR
  --run-dir PATH          Override the runtime (lock/temp) directory
  --module NAME           Run a single module and write/refresh its cache
  --dry-run               Build and print the payload; no token, no HTTP
  --panel NAME            Force a panel: auto | none | <plugin name>
  --panel-dir PATH        Directory to scan for panel plugins
  --module-dir PATH       Directory to scan for system modules
  --max-payload-bytes N   Override the payload size limit
  --help                  Show this help and exit
  --version               Print the version and exit
EOF
}

parse_args() {
  while (($#)); do
    case ${1-} in
      --config) CONFIG_FILE=${2-}; shift 2 ;;
      --state-dir) OPT_STATE_DIR=${2-}; shift 2 ;;
      --run-dir) OPT_RUN_DIR=${2-}; shift 2 ;;
      --module) OPT_MODULE=${2-}; shift 2 ;;
      --dry-run) OPT_DRY_RUN=1; shift ;;
      --panel) OPT_PANEL=${2-}; shift 2 ;;
      --panel-dir) OPT_PANEL_DIR=${2-}; shift 2 ;;
      --module-dir) OPT_MODULE_DIR=${2-}; shift 2 ;;
      --max-payload-bytes) MAX_PAYLOAD_BYTES=${2-}; shift 2 ;;
      --help) OPT_HELP=1; shift ;;
      --version) OPT_VERSION=1; shift ;;
      *) _err "collector: unknown option: ${1-}"; usage >&2; return 1 ;;
    esac
  done
  return 0
}

# _os_release_field KEY -> print the value of KEY= from /etc/os-release.
# os-release values are shell-quoted; surrounding quotes are stripped.
_os_release_field() {
  local key=${1-} line val
  line=$(grep -m1 "^$key=" /etc/os-release 2>/dev/null) || return 0
  val=${line#*=}
  val=${val%$'\r'}
  val=${val#\"}; val=${val%\"}
  val=${val#\'}; val=${val%\'}
  printf '%s' "$val"
}

# collect_core -> fill the CPU_*, MEM_*, SWAP_*, DISK_*, INODE_PCT, NET_*,
# PROC_*, OS_*, KERNEL, UPTIME, and HOSTNAME variables with universal metrics.
# CPU and network are sampled across a single 1-second window.
collect_core() {
  local cpu_before cpu_after net_before net_after
  local total_before idle_before iowait_before total_after idle_after iowait_after
  local dt di dw rx_before tx_before rx_after tx_after
  local mem_total_kb=0 mem_avail_kb=0 mem_free_kb=0 mem_buffers_kb=0 mem_cached_kb=0
  local mem_used_kb=0
  local swap_total_kb=0 swap_free_kb=0
  local disk_total_blk=0 disk_used_blk=0
  local loadavg uptime_raw

  # Defaults so build_payload never reads an unset value.
  CPU_USAGE_PCT=0; CPU_IOWAIT_PCT=0
  CPU_LOAD1=0; CPU_LOAD5=0; CPU_LOAD15=0; CPU_CORES=0
  MEM_TOTAL=0; MEM_USED=0; MEM_USED_PCT=0
  SWAP_TOTAL=0; SWAP_USED=0; SWAP_USED_PCT=0
  DISK_TOTAL=0; DISK_USED=0; DISK_USED_PCT=0; INODE_PCT=0
  NET_RX=0; NET_TX=0
  PROC_RUNNING=0; PROC_BLOCKED=0
  OS_NAME=""; OS_VERSION=""; KERNEL=""; UPTIME=0; HOSTNAME=""

  # CPU total is user+nice+system+idle+iowait+irq+softirq (fields 2..8).
  cpu_before=$(awk '/^cpu / {print $2+$3+$4+$5+$6+$7+$8, $5, $6; exit}' /proc/stat)
  # Network sums receive bytes ($2) and transmit bytes ($10), i.e. byte
  # fields 1 and 9 after the colon, for non-loopback interfaces.
  net_before=$(awk 'NR<3{next}{i=$1; sub(/:$/,"",i); if(i!="lo"){rx+=$2; tx+=$10}} END{print rx+0, tx+0}' /proc/net/dev)
  sleep 1
  cpu_after=$(awk '/^cpu / {print $2+$3+$4+$5+$6+$7+$8, $5, $6; exit}' /proc/stat)
  net_after=$(awk 'NR<3{next}{i=$1; sub(/:$/,"",i); if(i!="lo"){rx+=$2; tx+=$10}} END{print rx+0, tx+0}' /proc/net/dev)

  read -r total_before idle_before iowait_before <<< "$cpu_before"
  read -r total_after idle_after iowait_after <<< "$cpu_after"
  dt=$((total_after - total_before))
  di=$((idle_after - idle_before))
  dw=$((iowait_after - iowait_before))
  CPU_USAGE_PCT=$(awk -v t="$dt" -v i="$di" -v w="$dw" \
    'BEGIN{if (t>0) printf "%.1f", 100*(t-i-w)/t; else printf "0"}')
  CPU_IOWAIT_PCT=$(awk -v t="$dt" -v w="$dw" \
    'BEGIN{if (t>0) printf "%.1f", 100*w/t; else printf "0"}')

  read -r rx_before tx_before <<< "$net_before"
  read -r rx_after tx_after <<< "$net_after"
  NET_RX=$((rx_after - rx_before))
  NET_TX=$((tx_after - tx_before))
  (( NET_RX < 0 )) && NET_RX=0
  (( NET_TX < 0 )) && NET_TX=0

  # Load average and core count.
  loadavg=$(< /proc/loadavg)
  read -r CPU_LOAD1 CPU_LOAD5 CPU_LOAD15 _ <<< "$loadavg"
  if command -v nproc >/dev/null 2>&1; then
    CPU_CORES=$(nproc)
  else
    CPU_CORES=$(grep -c '^processor' /proc/cpuinfo)
  fi
  [[ $CPU_CORES =~ ^[0-9]+$ ]] || CPU_CORES=1

  # Memory: MemTotal - MemAvailable, falling back to MemFree+Buffers+Cached.
  mem_total_kb=$(awk '/^MemTotal:/{print $2}' /proc/meminfo); : "${mem_total_kb:=0}"
  mem_avail_kb=$(awk '/^MemAvailable:/{print $2}' /proc/meminfo); : "${mem_avail_kb:=0}"
  if (( mem_avail_kb > 0 )); then
    mem_used_kb=$((mem_total_kb - mem_avail_kb))
  else
    mem_free_kb=$(awk '/^MemFree:/{print $2}' /proc/meminfo); : "${mem_free_kb:=0}"
    mem_buffers_kb=$(awk '/^Buffers:/{print $2}' /proc/meminfo); : "${mem_buffers_kb:=0}"
    mem_cached_kb=$(awk '/^Cached:/{print $2}' /proc/meminfo); : "${mem_cached_kb:=0}"
    mem_used_kb=$((mem_total_kb - mem_free_kb - mem_buffers_kb - mem_cached_kb))
  fi
  MEM_TOTAL=$((mem_total_kb * 1024))
  MEM_USED=$((mem_used_kb * 1024))
  MEM_USED_PCT=$(awk -v u="$MEM_USED" -v t="$MEM_TOTAL" \
    'BEGIN{if (t>0) printf "%.1f", 100*u/t; else printf "0"}')

  # Swap.
  swap_total_kb=$(awk '/^SwapTotal:/{print $2}' /proc/meminfo); : "${swap_total_kb:=0}"
  swap_free_kb=$(awk '/^SwapFree:/{print $2}' /proc/meminfo); : "${swap_free_kb:=0}"
  SWAP_TOTAL=$((swap_total_kb * 1024))
  SWAP_USED=$(((swap_total_kb - swap_free_kb) * 1024))
  SWAP_USED_PCT=$(awk -v u="$SWAP_USED" -v t="$SWAP_TOTAL" \
    'BEGIN{if (t>0) printf "%.1f", 100*u/t; else printf "0"}')

  # Disk: root filesystem via df -P (1K blocks) and df -iP (inode percent).
  disk_total_blk=$(df -P / | awk 'NR==2{print $2}'); : "${disk_total_blk:=0}"
  disk_used_blk=$(df -P / | awk 'NR==2{print $3}'); : "${disk_used_blk:=0}"
  DISK_TOTAL=$((disk_total_blk * 1024))
  DISK_USED=$((disk_used_blk * 1024))
  DISK_USED_PCT=$(awk -v u="$DISK_USED" -v t="$DISK_TOTAL" \
    'BEGIN{if (t>0) printf "%.1f", 100*u/t; else printf "0"}')
  INODE_PCT=$(df -iP / | awk 'NR==2{sub("%","",$5); print $5}'); : "${INODE_PCT:=0}"

  # Processes in runnable (R) and uninterruptible (D) states.
  PROC_RUNNING=$(ps -eo state= 2>/dev/null | awk '$1 ~ /^R/{n++} END{print n+0}')
  PROC_BLOCKED=$(ps -eo state= 2>/dev/null | awk '$1 ~ /^D/{n++} END{print n+0}')

  # OS identity.
  OS_NAME=$(_os_release_field NAME)
  OS_VERSION=$(_os_release_field VERSION_ID)
  [[ -n $OS_VERSION ]] || OS_VERSION=$(_os_release_field VERSION)
  KERNEL=$(uname -r 2>/dev/null); KERNEL=${KERNEL%$'\n'}
  read -r uptime_raw _ <<< "$(< /proc/uptime)"
  UPTIME=${uptime_raw%%.*}
  [[ $UPTIME =~ ^[0-9]+$ ]] || UPTIME=0
  HOSTNAME=$(< /proc/sys/kernel/hostname)
}

# build_payload PANEL_FILE EXT_FILE -> print the schema-v1 JSON payload.
# Every value is emitted through json_string/json_number; panel_metrics and
# extensions are built from the given metric-line files (pass /dev/null for
# an empty object).
build_payload() {
  local panel_file=${1:-/dev/null}
  local ext_file=${2:-/dev/null}
  local id collected_at panel_metrics extensions panel

  id=$(get_agent_id)
  collected_at=$(date -u +%Y-%m-%dT%H:%M:%SZ 2>/dev/null)
  panel_metrics=$(json_object_entries "$panel_file")
  extensions=$(json_object_entries "$ext_file")
  panel=${PANEL_NAME:-${PANEL:-none}}

  printf '{'
  printf '"schema_version":%s,' "$(json_number 1)"
  printf '"agent":{'
  printf '"version":%s,' "$(json_string "$AGENT_VERSION")"
  printf '"id":%s,' "$(json_string "$id")"
  printf '"hostname":%s,' "$(json_string "${HOSTNAME:-}")"
  printf '"panel":%s,' "$(json_string "$panel")"
  printf '"collected_at":%s,' "$(json_string "$collected_at")"
  printf '"interval_seconds":%s' "$(json_number "${INTERVAL_SECONDS:-0}")"
  printf '},'
  printf '"system":{'
  printf '"os":{'
  printf '"name":%s,' "$(json_string "${OS_NAME:-}")"
  printf '"version":%s,' "$(json_string "${OS_VERSION:-}")"
  printf '"kernel":%s' "$(json_string "${KERNEL:-}")"
  printf '},'
  printf '"uptime_seconds":%s' "$(json_number "${UPTIME:-0}")"
  printf '},'
  printf '"metrics":{'
  printf '"cpu":{'
  printf '"usage_pct":%s,' "$(json_number "${CPU_USAGE_PCT:-0}")"
  printf '"iowait_pct":%s,' "$(json_number "${CPU_IOWAIT_PCT:-0}")"
  printf '"load1":%s,' "$(json_number "${CPU_LOAD1:-0}")"
  printf '"load5":%s,' "$(json_number "${CPU_LOAD5:-0}")"
  printf '"load15":%s,' "$(json_number "${CPU_LOAD15:-0}")"
  printf '"cores":%s' "$(json_number "${CPU_CORES:-0}")"
  printf '},'
  printf '"memory":{'
  printf '"total_bytes":%s,' "$(json_number "${MEM_TOTAL:-0}")"
  printf '"used_bytes":%s,' "$(json_number "${MEM_USED:-0}")"
  printf '"used_pct":%s' "$(json_number "${MEM_USED_PCT:-0}")"
  printf '},'
  printf '"swap":{'
  printf '"total_bytes":%s,' "$(json_number "${SWAP_TOTAL:-0}")"
  printf '"used_bytes":%s,' "$(json_number "${SWAP_USED:-0}")"
  printf '"used_pct":%s' "$(json_number "${SWAP_USED_PCT:-0}")"
  printf '},'
  printf '"disk":{"root":{'
  printf '"total_bytes":%s,' "$(json_number "${DISK_TOTAL:-0}")"
  printf '"used_bytes":%s,' "$(json_number "${DISK_USED:-0}")"
  printf '"used_pct":%s,' "$(json_number "${DISK_USED_PCT:-0}")"
  printf '"inode_pct":%s' "$(json_number "${INODE_PCT:-0}")"
  printf '}},'
  printf '"network":{'
  printf '"rx_bytes_per_sec":%s,' "$(json_number "${NET_RX:-0}")"
  printf '"tx_bytes_per_sec":%s' "$(json_number "${NET_TX:-0}")"
  printf '},'
  printf '"processes":{'
  printf '"running":%s,' "$(json_number "${PROC_RUNNING:-0}")"
  printf '"blocked":%s' "$(json_number "${PROC_BLOCKED:-0}")"
  printf '}'
  printf '},'
  printf '"panel_metrics":{%s},' "$panel_metrics"
  printf '"extensions":{%s}' "$extensions"
  printf '}\n'
}

# _panel_dir -> the panel plugin directory (CLI override or default).
_panel_dir() {
  if [[ -n ${OPT_PANEL_DIR:-} ]]; then
    printf '%s' "$OPT_PANEL_DIR"
  else
    printf '%s' "$SELF_DIR/panels"
  fi
}

# select_panel -> resolve PANEL into PANEL_NAME and PANEL_FILE.
#   PANEL=none    -> no panel (PANEL_NAME=none, PANEL_FILE empty).
#   PANEL=<name>  -> use panels/<name>.sh; it must exist and be secure, and a
#                    missing/insecure plugin is a config error (return 1).
#   PANEL=auto    -> scan panels/*.sh in sorted order (skipping _*), picking
#                    the first plugin whose `detect` exits 0 within
#                    PANEL_DETECT_TIMEOUT; none found -> PANEL_NAME=none.
select_panel() {
  local dir f base plugin
  dir=$(_panel_dir)
  PANEL_FILE=""
  case ${PANEL:-none} in
    none)
      PANEL_NAME="none"
      ;;
    auto)
      PANEL_NAME="none"
      if [[ -d $dir ]]; then
        for f in "$dir"/*.sh; do
          [[ -e $f ]] || continue
          base=${f##*/}
          [[ $base == _* ]] && continue
          require_secure_file "$f" 2>/dev/null || continue
          if timeout "$PANEL_DETECT_TIMEOUT" "$f" detect >/dev/null 2>&1; then
            PANEL_NAME=${base%.sh}
            PANEL_FILE=$f
            break
          fi
        done
      fi
      ;;
    *)
      plugin=$dir/$PANEL.sh
      if [[ ! -e $plugin || -L $plugin ]]; then
        _err "config error: PANEL plugin '$PANEL' not found in $dir"
        return 1
      fi
      if ! require_secure_file "$plugin"; then
        _err "config error: PANEL plugin '$plugin' is not a secure regular file"
        return 1
      fi
      PANEL_NAME=$PANEL
      PANEL_FILE=$plugin
      ;;
  esac
  return 0
}

# run_panel -> run the selected plugin's `collect` under timeout, writing its
# raw metric lines to PANEL_TMP. A nonzero exit or timeout logs a warning and
# leaves PANEL_TMP empty (core metrics are still sent). Plugin stderr is logged
# only when DEBUG=1.
run_panel() {
  PANEL_TMP=""
  [[ -n $PANEL_FILE ]] || return 0
  local out err rc
  out=$(mktemp "${TMPDIR:-/tmp}/noc-agent.panel.XXXXXX") 2>/dev/null || return 0
  err=$(mktemp "${TMPDIR:-/tmp}/noc-agent.panel-err.XXXXXX") 2>/dev/null || err=""
  rc=0
  timeout "$PANEL_TIMEOUT" "$PANEL_FILE" collect >"$out" 2>"${err:-/dev/null}"
  rc=$?
  if (( rc != 0 )); then
    _err "panel '$PANEL_NAME': collect failed (rc=$rc); panel metrics omitted"
    if (( ${DEBUG:-0} )) && [[ -n $err && -s $err ]]; then
      while IFS= read -r line; do
        _err "panel '$PANEL_NAME': $line"
      done < "$err"
    fi
    rm -f "$out" "${err:-}"
    PANEL_TMP=""
    return 0
  fi
  rm -f "${err:-}"
  PANEL_TMP=$out
  return 0
}

# --- Module loader (Task 5) ------------------------------------------------
#
# A system module is an executable Bash script in the module directory. Unlike
# panels (exactly one per run), the core runs every applicable inline module
# and reads the cache of every applicable external module. See
# agent/modules/README.md for the full contract.

# _module_dir -> the system module directory (CLI override or default).
_module_dir() {
  if [[ -n ${OPT_MODULE_DIR:-} ]]; then
    printf '%s' "$OPT_MODULE_DIR"
  else
    printf '%s' "$SELF_DIR/modules"
  fi
}

# _cache_dir -> the module cache directory (derived from STATE_DIR).
_cache_dir() {
  printf '%s' "${STATE_DIR}/cache"
}

# _run_dir -> the runtime directory (CLI override or default).
_run_dir() {
  if [[ -n ${OPT_RUN_DIR:-} ]]; then
    printf '%s' "$OPT_RUN_DIR"
  else
    printf '%s' "${RUN_DIR:-/run/noc-agent}"
  fi
}

# module_enabled NAME -> 0 (true) unless ENABLE_<UPPER> is exactly 0.
module_enabled() {
  local name=${1-} upper var val
  upper=$(printf '%s' "$name" | tr '[:lower:]' '[:upper:]')
  var="ENABLE_$upper"
  val=${!var-}
  [[ $val == 0 ]] && return 1
  return 0
}

# module_mode FILE -> print "inline" or "external". Missing/unknown output is
# treated as inline.
module_mode() {
  local file=${1-} out
  out=$(timeout "$MODULE_DETECT_TIMEOUT" "$file" mode 2>/dev/null) || out=""
  case ${out:-} in
    external) printf 'external' ;;
    *) printf 'inline' ;;
  esac
}

# _route_lines SRC PREFIX DEST -> append SRC lines whose key starts with PREFIX
# (blank/comment lines skipped). This is the per-module prefix check: a module
# may only emit keys beginning "<name>.".
_route_lines() {
  local src=${1-} prefix=${2-} dest=${3-}
  local line
  while IFS= read -r line || [[ -n $line ]]; do
    line=${line%$'\r'}
    [[ -z $line ]] && continue
    [[ $line == '#'* ]] && continue
    [[ $line == "$prefix"* ]] || continue
    printf '%s\n' "$line" >> "$dest"
  done < "$src"
}

# _has_key FILE KEY -> 0 when a metric line's key equals KEY exactly.
_has_key() {
  local file=${1-} want=${2-} line key
  while IFS=$'\t' read -r key _ || [[ -n ${key-} ]]; do
    [[ ${key-} == "$want" ]] && return 0
  done < "$file"
  return 1
}

# _run_inline FILE NAME DEST -> run an inline module's collect under
# MODULE_TIMEOUT, routing its (prefix-filtered) lines into DEST. Failure is a
# warning only and never aborts the payload.
_run_inline() {
  local file=${1-} name=${2-} dest=${3-}
  local out rc
  out=$(mktemp "${TMPDIR:-/tmp}/noc-agent.mod.XXXXXX") 2>/dev/null || return 0
  rc=0
  timeout "$MODULE_TIMEOUT" "$file" collect >"$out" 2>/dev/null
  rc=$?
  if (( rc != 0 )); then
    _err "module '$name': collect failed (rc=$rc); metrics omitted"
    rm -f "$out"
    return 0
  fi
  _route_lines "$out" "$name." "$dest"
  rm -f "$out"
  return 0
}

# run_modules EXT_TMP -> run inline modules and read external module caches,
# writing validated metric lines to EXT_TMP. A missing/insecure module is
# skipped; an inline failure is a warning only. External caches contribute only
# when they are a regular non-symlink file; a cache_age_seconds metric is
# appended from the file's mtime.
run_modules() {
  local ext_tmp=${1:-/dev/null}
  local dir f base name mode cache now mtime age
  : > "$ext_tmp"
  dir=$(_module_dir)
  [[ -d $dir ]] || return 0
  for f in "$dir"/*.sh; do
    [[ -e $f ]] || continue
    base=${f##*/}
    [[ $base == _* ]] && continue
    name=${base%.sh}
    module_enabled "$name" || continue
    require_secure_file "$f" 2>/dev/null || continue
    if ! timeout "$MODULE_DETECT_TIMEOUT" "$f" detect >/dev/null 2>&1; then
      continue
    fi
    case $(module_mode "$f") in
      external)
        cache=$(_cache_dir)/$name.metrics
        if [[ -f $cache && ! -L $cache ]]; then
          now=$(date +%s)
          mtime=$(stat -c %Y "$cache" 2>/dev/null) || mtime=""
          if [[ -n $mtime && $mtime =~ ^[0-9]+$ ]]; then
            age=$((now - mtime))
            (( age < 0 )) && age=0
          else
            age=0
          fi
          _route_lines "$cache" "$name." "$ext_tmp"
          printf '%s.cache_age_seconds\tn\t%s\n' "$name" "$age" >> "$ext_tmp"
        fi
        ;;
      *)
        _run_inline "$f" "$name" "$ext_tmp"
        ;;
    esac
  done
  return 0
}

# run_module_mode NAME -> validate and run exactly one module, writing its cache
# atomically (temp 600 + mv) or, with --dry-run, printing normalized metric
# lines and writing nothing. It never reads the token and never sends HTTP.
# Returns 4 on any failure (invalid name, missing/insecure module, disabled,
# detect failure, collect failure, or an external module missing updated_at),
# preserving any previous cache.
run_module_mode() {
  local name=${1-} dir f out route cache tmp rc mode
  [[ $name =~ ^[A-Za-z0-9][A-Za-z0-9_.-]*$ ]] || { _err "module: invalid name '$name'"; return 4; }
  dir=$(_module_dir)
  f=$dir/$name.sh
  [[ -e $f && ! -L $f ]] || { _err "module: '$name' not found in $dir"; return 4; }
  require_secure_file "$f" || return 4
  module_enabled "$name" || { _err "module: '$name' is disabled"; return 4; }

  if ! timeout "$MODULE_DETECT_TIMEOUT" "$f" detect >/dev/null 2>&1; then
    _err "module: '$name' not applicable (detect failed)"
    return 4
  fi
  mode=$(module_mode "$f")

  out=$(mktemp "${TMPDIR:-/tmp}/noc-agent.modmode.XXXXXX") 2>/dev/null || return 4
  rc=0
  timeout "$HEAVY_MODULE_TIMEOUT" "$f" collect >"$out" 2>/dev/null
  rc=$?
  if (( rc != 0 )); then
    _err "module: '$name' collect failed (rc=$rc)"
    rm -f "$out"
    return 4
  fi

  route=$(mktemp "${TMPDIR:-/tmp}/noc-agent.modroute.XXXXXX") 2>/dev/null \
    || { rm -f "$out"; return 4; }
  _route_lines "$out" "$name." "$route"
  rm -f "$out"

  if [[ $mode == external ]] && ! _has_key "$route" "$name.updated_at"; then
    _err "module: '$name' did not emit $name.updated_at"
    rm -f "$route"
    return 4
  fi

  if (( OPT_DRY_RUN )); then
    cat "$route"
    rm -f "$route"
    return 0
  fi

  cache=$(_cache_dir)/$name.metrics
  mkdir -p "$(_cache_dir)" 2>/dev/null || { rm -f "$route"; return 4; }
  tmp=$(mktemp "$(_cache_dir)/.${name}.metrics.XXXXXX") 2>/dev/null \
    || { rm -f "$route"; return 4; }
  ( umask 077; cat "$route" > "$tmp" ) 2>/dev/null
  chmod 600 "$tmp" 2>/dev/null
  mv -f "$tmp" "$cache" 2>/dev/null || { rm -f "$tmp" "$route"; return 4; }
  rm -f "$route"
  return 0
}

# --- HTTP client (Task 9) ---------------------------------------------------
#
# post_payload sends a payload file to API_URL with a bearer token. It is
# normally called from main(), whose exit trap (cleanup_temps) removes the
# curl config on every path, so post_payload itself never installs a trap
# (that would shadow main's and leak the payload temp file). The token is the
# only secret the agent holds (design §10.2), so:
#   * it is read from a secure token file, never from argv;
#   * it is passed to curl only inside a mode-600 config file, never as -H;
#   * its exact value is never logged, printed, or stored in the payload;
#   * the config file is removed on every return path (and at exit).
#
# Retry policy (design §9.2): success 200/202/204; no retry for 400/401/403/
# 413/422 (and any other non-retryable 4xx); retry 429, 5xx, and network/TLS
# failures (curl code 000) up to 3 attempts with 1s and 3s backoff, each
# attempt bounded by CURL_TIMEOUT and the whole loop bounded by
# MAX_RUN_SECONDS. Result: 0 on success, 3 when the payload was not delivered
# (including rejected 4xx), 1 on a configuration/local error.

# Start of the run's wall-clock budget; set by main() and by post_payload()
# when called directly (e.g. from a test).
RUN_START_EPOCH=0

_agent_now() { date +%s; }

# _run_start_epoch -> print RUN_START_EPOCH, initializing it on first use.
_run_start_epoch() {
  if (( ${RUN_START_EPOCH:-0} == 0 )); then
    RUN_START_EPOCH=$(_agent_now)
  fi
  printf '%s' "$RUN_START_EPOCH"
}

# _run_elapsed -> seconds since the run started.
_run_elapsed() {
  local start
  start=$(_run_start_epoch)
  printf '%s' $(( $(_agent_now) - start ))
}

# _run_budget_left -> remaining seconds of MAX_RUN_SECONDS (0 when expired).
_run_budget_left() {
  local used left
  used=$(_run_elapsed)
  left=$(( ${MAX_RUN_SECONDS:-45} - used ))
  if (( left < 0 )); then left=0; fi
  printf '%s' "$left"
}

# _http_status_class CODE -> success | retry | reject for an HTTP status.
_http_status_class() {
  local code=${1-}
  case $code in
    200|202|204) printf 'success' ;;
    429) printf 'retry' ;;
    5??) printf 'retry' ;;
    4??) printf 'reject' ;;
    *) printf 'retry' ;;
  esac
}

# _curl_quote VALUE -> VALUE escaped for a quoted curl config value.
# Only characters that would break config parsing need escaping; tokens are
# restricted to ^[A-Za-z0-9._~-]{16,256}$ by validate_token_file.
_curl_quote() {
  local value=${1-}
  value=${value//\\/\\\\}
  value=${value//\"/\\\"}
  printf '%s' "$value"
}

# _curl_config_file -> path of the mode-600 curl config for this run.
_curl_config_file() {
  printf '%s/noc-agent.curl.%s.conf' "$(_run_dir)" "$$"
}

# _http_cleanup -> remove the curl config and its response-body temps.
# Called at the end of post_payload and from cleanup_temps on exit.
_http_cleanup() {
  local cfg
  cfg=$(_curl_config_file)
  if [[ -e $cfg || -e $cfg.body || -e $cfg.body.err ]]; then
    rm -f "$cfg" "$cfg.body" "$cfg.body.err"
  fi
  return 0
}

# _write_curl_config CFG PAYLOAD_FILE -> write the mode-600 curl config.
# The Authorization header lives only here, so it never appears in argv.
_write_curl_config() {
  local cfg=${1-} payload=${2-} proto
  local old_umask
  if [[ ${ALLOW_INSECURE_HTTP:-0} == 1 && ${API_URL-} == http://* ]]; then
    proto='=http,https'
  else
    proto='=https'
  fi
  old_umask=$(umask)
  umask 077
  if ! {
    printf 'url = "%s"\n' "$(_curl_quote "${API_URL-}")"
    printf 'request = "POST"\n'
    printf 'header = "Authorization: Bearer %s"\n' "$(_curl_quote "${SERVER_TOKEN-}")"
    printf 'header = "Content-Type: application/json"\n'
    printf 'header = "Accept: application/json"\n'
    printf 'header = "User-Agent: noc-agent/%s"\n' "$AGENT_VERSION"
    printf 'header = "X-Noc-Agent-Version: %s"\n' "$AGENT_VERSION"
    printf 'proto = "%s"\n' "$proto"
    printf 'tlsv1.2\n'
    printf 'data-binary = "@%s"\n' "$(_curl_quote "$payload")"
    printf 'write-out = "%%{http_code}"\n'
    printf 'silent\n'
    printf 'show-error\n'
  } > "$cfg"; then
    umask "$old_umask"
    return 1
  fi
  umask "$old_umask"
  chmod 600 "$cfg" 2>/dev/null || return 1
  return 0
}

# _http_attempt CFG BODY_FILE -> run one curl attempt; print the HTTP status
# code ("000" for network/TLS failure). The response body goes to BODY_FILE
# and is never parsed or executed.
_http_attempt() {
  local cfg=${1-} body=${2-} budget code rc
  local -a args
  budget=$(_run_budget_left)
  (( budget <= 0 )) && { printf '000'; return 0; }
  args=(--config "$cfg" --max-time "$CURL_TIMEOUT" --connect-timeout "$CURL_TIMEOUT"
        --output "$body" --write-out '%{http_code}')
  # TLS verification is on by default; TLS_VERIFY=0 is a local-test escape.
  if [[ ${TLS_VERIFY:-1} != 1 ]]; then
    args+=(--insecure)
  fi
  code=$(timeout "$budget" curl "${args[@]}" 2>"$body.err") || rc=$?
  rc=${rc:-0}
  if (( rc != 0 )); then
    printf '000'
    return 0
  fi
  code=${code//$'\r'/}
  code=${code//$'\n'/}
  [[ $code =~ ^[0-9]{3}$ ]] || code=000
  printf '%s' "$code"
}

# post_payload PAYLOAD_FILE -> 0 on 2xx, 3 when the payload was not delivered
# (retries exhausted, network/TLS failure, or a non-retryable rejection),
# 1 on a local/config error before any send was possible.
post_payload() {
  local payload=${1-}
  local cfg body code class attempt delay budget
  local -a backoff=(1 3)

  if [[ -z $payload || ! -f $payload ]]; then
    _err "http: payload file not found"
    return 1
  fi
  if [[ -z ${API_URL:-} ]]; then
    _err "http: API_URL is empty"
    return 1
  fi
  if [[ -z ${SERVER_TOKEN:-} ]]; then
    _err "http: server token is not loaded"
    return 1
  fi

  cfg=$(_curl_config_file)
  body="$cfg.body"
  # Remove stale files from an interrupted earlier run; cleanup_temps removes
  # them on exit.
  _http_cleanup
  if ! _write_curl_config "$cfg" "$payload"; then
    _err "http: cannot write the curl config in $(_run_dir)"
    _http_cleanup
    return 1
  fi

  for ((attempt = 1; attempt <= 3; attempt++)); do
    budget=$(_run_budget_left)
    if (( budget <= 0 )); then
      _err "http: run deadline (MAX_RUN_SECONDS=${MAX_RUN_SECONDS}) reached before attempt $attempt"
      break
    fi
    code=$(_http_attempt "$cfg" "$body")
    class=$(_http_status_class "$code")
    rm -f "$body" "$body.err"
    case $class in
      success)
        _http_cleanup
        return 0
        ;;
      reject)
        # Never include the token or the response body in the log.
        _err "http: payload rejected (HTTP $code); not retrying"
        _http_cleanup
        return 3
        ;;
    esac
    if (( attempt < 3 )); then
      delay=${backoff[attempt-1]}
      # Do not sleep past the run deadline; fail fast instead.
      budget=$(_run_budget_left)
      if (( budget <= delay )); then
        _err "http: HTTP $code on attempt $attempt; run deadline (MAX_RUN_SECONDS=${MAX_RUN_SECONDS}) leaves no time to retry"
        break
      fi
      _err "http: HTTP $code on attempt $attempt; retrying in ${delay}s"
      sleep "$delay"
    else
      _err "http: HTTP $code on attempt $attempt; giving up"
    fi
  done

  _http_cleanup
  _err "http: payload was not delivered (exit 3)"
  return 3
}

# --- Payload size fallback (Task 10) ---------------------------------------
#
# The ingest endpoint accepts at most 64 KiB per body (design §9.1). When the
# full payload is too large, optional sections are dropped whole — never
# partially — in this order (design §9.5):
#   1. all panel_metrics;
#   2. all extensions;
#   3. if the core alone still does not fit, the run fails with exit 2.
# Schema v1 has no "essential" marker, so this order is the only policy.

# _payload_too_large FILE -> 0 when FILE is strictly larger than the limit.
_payload_too_large() {
  local file=${1-} size
  size=$(wc -c < "$file" 2>/dev/null) || return 1
  size=${size//[[:space:]]/}
  [[ $size =~ ^[0-9]+$ ]] || return 1
  (( size > MAX_PAYLOAD_BYTES ))
}

# send_with_fallback PANEL_FILE EXT_FILE -> build the schema-v1 payload into
# $PAYLOAD_FILE, dropping optional sections as needed to fit MAX_PAYLOAD_BYTES.
# On --dry-run it prints the final payload and performs no HTTP, token, or
# lock work. Otherwise it passes only the final payload to post_payload and
# returns post_payload's 0/3. Returns 1 on a local error and 2 when the
# core-only payload is still too large. $PAYLOAD_FILE stays in cleanup_temps.
send_with_fallback() {
  local panel_file=${1:-/dev/null}
  local ext_file=${2:-/dev/null}
  local size

  PAYLOAD_FILE=$(mktemp "${TMPDIR:-/tmp}/noc-agent.payload.XXXXXX") 2>/dev/null \
    || { _err "payload: cannot create the payload temp file"; return 1; }
  chmod 600 "$PAYLOAD_FILE" 2>/dev/null

  # 1. Full payload (core + panel + extensions).
  build_payload "$panel_file" "$ext_file" > "$PAYLOAD_FILE" \
    || { _err "payload: cannot build the payload"; return 1; }

  if _payload_too_large "$PAYLOAD_FILE"; then
    # 2. Drop all panel_metrics; keep extensions and the core.
    _err "payload: larger than MAX_PAYLOAD_BYTES (${MAX_PAYLOAD_BYTES}); dropping all panel_metrics"
    build_payload /dev/null "$ext_file" > "$PAYLOAD_FILE" \
      || { _err "payload: cannot build the payload"; return 1; }
  fi

  if _payload_too_large "$PAYLOAD_FILE"; then
    # 3. Drop all extensions too; keep the core only.
    _err "payload: still larger than MAX_PAYLOAD_BYTES (${MAX_PAYLOAD_BYTES}) without panel_metrics; dropping all extensions"
    build_payload /dev/null /dev/null > "$PAYLOAD_FILE" \
      || { _err "payload: cannot build the payload"; return 1; }
  fi

  if _payload_too_large "$PAYLOAD_FILE"; then
    # 4. The core alone cannot fit: fail loudly instead of sending a partial
    #    schema-v1 payload.
    size=$(wc -c < "$PAYLOAD_FILE" 2>/dev/null)
    size=${size//[[:space:]]/}
    _err "payload: core payload (${size:-?} bytes) is too large for MAX_PAYLOAD_BYTES (${MAX_PAYLOAD_BYTES}); cannot send (exit 2)"
    return 2
  fi

  if (( OPT_DRY_RUN )); then
    # Dry run: no token, no lock, no HTTP — just the final payload.
    cat "$PAYLOAD_FILE"
    return 0
  fi

  post_payload "$PAYLOAD_FILE" || return $?
  return 0
}

# _cleanup_temp_file PATH -> unlink PATH only when it is a non-empty regular
# file that is not /dev/null. A failed mktemp leaves the variable empty; the
# explicit /dev/null refusal keeps a fallback sentinel from ever unlinking the
# host device node when the collector runs as root.
_cleanup_temp_file() {
  local path=${1-}
  [[ -n $path ]] || return 0
  [[ $path == /dev/null ]] && return 0
  [[ -f $path && ! -L $path ]] || return 0
  rm -f -- "$path"
}

# cleanup_temps -> remove throwaway state/panel/module/HTTP temp paths on exit.
# Note: keep every redirection argument lowercase-safe; LC_ALL=C makes Bash
# treat an uppercase "2>/dev/null" as "2>~err" and silently break the call.
cleanup_temps() {
  _http_cleanup
  _cleanup_temp_file "${PANEL_TMP:-}"
  _cleanup_temp_file "${EXT_TMP:-}"
  _cleanup_temp_file "${PAYLOAD_FILE:-}"
  [[ -n ${TMP_STATE_DIR:-} ]] && rm -rf -- "$TMP_STATE_DIR"
  return 0
}

main() {
  parse_args "$@" || return 1

  trap cleanup_temps EXIT

  umask 077
  # Extend PATH with cPanel paths (append only, never prepend) so panel CLIs
  # are found while a test's fake binaries in front of PATH still win.
  PATH="$PATH:/usr/local/cpanel/bin:/usr/local/cpanel/3rdparty/bin"

  if (( OPT_VERSION )); then
    printf 'noc-agent %s\n' "$AGENT_VERSION"
    return 0
  fi

  if (( OPT_HELP )); then
    usage
    return 0
  fi

  config_defaults
  load_config "$CONFIG_FILE" || return 1
  validate_config || return 1

  # Validate the size limit before collecting anything so a bad flag fails fast.
  if [[ ! $MAX_PAYLOAD_BYTES =~ ^[0-9]+$ ]] || (( MAX_PAYLOAD_BYTES < 1 )); then
    _err "config error: MAX_PAYLOAD_BYTES must be a positive integer"
    return 1
  fi

  # CLI overrides (run/module/panel dirs are consumed by later tasks).
  [[ -n $OPT_STATE_DIR ]] && STATE_DIR=$OPT_STATE_DIR
  [[ -n $OPT_PANEL ]] && PANEL=$OPT_PANEL

  # Module mode: run exactly one module and write/refresh its cache. It never
  # reads the token and never sends HTTP. Non-root runs require state and run
  # dirs owned by the effective UID; production defaults require root.
  if [[ -n $OPT_MODULE ]]; then
    if (( OPT_DRY_RUN == 0 )); then
      require_secure_dir "$STATE_DIR" || return 1
      require_secure_dir "$(_run_dir)" || return 1
    fi
    run_module_mode "$OPT_MODULE" || return $?
    return 0
  fi

  # Resolve the panel (detect/auto/explicit) before building any payload so a
  # missing explicitly requested plugin is a config error, and agent.panel is
  # the selected panel name (never "auto").
  select_panel || return 1

  if (( OPT_DRY_RUN )); then
    # No token, no lock, no HTTP. A non-root run whose state dir is not owned
    # by the effective UID (e.g. the production /var/lib/noc-agent default)
    # gets a throwaway state dir for a stable agent id; otherwise the
    # configured state dir is used directly so module caches are readable.
    if (( $(id -u) != 0 )) && ! _owned_by_me "$STATE_DIR"; then
      TMP_STATE_DIR=$(mktemp -d "${TMPDIR:-/tmp}/noc-agent.XXXXXX") 2>/dev/null || TMP_STATE_DIR=""
      [[ -n $TMP_STATE_DIR ]] && STATE_DIR=$TMP_STATE_DIR
    fi
    collect_core
    run_panel
    # An empty sentinel (not /dev/null) when mktemp fails: cleanup_temps must
    # never be handed a host device node to unlink. The read-side fallbacks
    # below turn an empty value into /dev/null.
    EXT_TMP=$(mktemp "${TMPDIR:-/tmp}/noc-agent.ext.XXXXXX") 2>/dev/null || EXT_TMP=""
    run_modules "$EXT_TMP"
    send_with_fallback "${PANEL_TMP:-/dev/null}" "$EXT_TMP"
    return $?
  fi

  # Real run: the state and run dirs must be owned by the effective UID and
  # not group/world writable. The run dir holds the lock and the token-bearing
  # curl config, so it is validated before anything is written there.
  require_secure_dir "$STATE_DIR" || return 1
  require_secure_dir "$(_run_dir)" || return 1

  # One run at a time: a busy lock means a previous run is still going.
  if ! acquire_lock "$(_run_dir)/noc-agent.lock"; then
    _err "another collector run holds the lock; skipping"
    return 0
  fi

  RUN_START_EPOCH=$(_agent_now)

  # The token is read from its secure file only for a real run, never for
  # --dry-run, and never from argv.
  validate_token_file "$SERVER_TOKEN_FILE" || return 1

  collect_core
  run_panel
  # See the dry-run branch: an empty sentinel keeps /dev/null out of
  # cleanup_temps when mktemp fails.
  EXT_TMP=$(mktemp "${TMPDIR:-/tmp}/noc-agent.ext.XXXXXX") 2>/dev/null || EXT_TMP=""
  run_modules "$EXT_TMP"
  # send_with_fallback builds the payload into a private temp file and drops
  # optional sections until it fits MAX_PAYLOAD_BYTES; only the final payload
  # is sent.
  send_with_fallback "${PANEL_TMP:-/dev/null}" "$EXT_TMP" || return $?
  return 0
}

if [[ "${BASH_SOURCE[0]}" == "$0" ]]; then
  main "$@"
fi
