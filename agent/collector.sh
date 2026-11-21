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
OPT_MAX_PAYLOAD_BYTES=""                  # --max-payload-bytes
OPT_HELP=0                                # --help
OPT_VERSION=0                             # --version

# --- Runtime state ----------------------------------------------------------
PANEL_NAME=""     # resolved panel name reported in the payload (Task 4)
PANEL_FILE=""     # path to the selected panel plugin (Task 4)
PANEL_TMP=""      # temp file holding collected panel metric lines (Task 4)
TMP_STATE_DIR=""  # temporary state dir created for non-root --dry-run

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
      --max-payload-bytes) OPT_MAX_PAYLOAD_BYTES=${2-}; shift 2 ;;
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

# cleanup_temps -> remove throwaway state/panel temp paths on exit.
cleanup_temps() {
  [[ -n ${TMP_STATE_DIR:-} ]] && rm -rf "$TMP_STATE_DIR"
  [[ -n ${PANEL_TMP:-} ]] && rm -f "$PANEL_TMP"
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

  # CLI overrides (run/module/panel dirs are consumed by later tasks).
  [[ -n $OPT_STATE_DIR ]] && STATE_DIR=$OPT_STATE_DIR
  [[ -n $OPT_PANEL ]] && PANEL=$OPT_PANEL

  # Resolve the panel (detect/auto/explicit) before building any payload so a
  # missing explicitly requested plugin is a config error, and agent.panel is
  # the selected panel name (never "auto").
  select_panel || return 1

  if (( OPT_DRY_RUN )); then
    # No token, no lock, no cache write, no HTTP. A non-root run without an
    # explicit state dir gets a throwaway state dir for a stable agent id.
    if [[ -z $OPT_STATE_DIR && $(id -u) -ne 0 ]]; then
      TMP_STATE_DIR=$(mktemp -d "${TMPDIR:-/tmp}/noc-agent.XXXXXX") 2>/dev/null || TMP_STATE_DIR=""
      [[ -n $TMP_STATE_DIR ]] && STATE_DIR=$TMP_STATE_DIR
    fi
    collect_core
    run_panel
    build_payload "${PANEL_TMP:-/dev/null}" /dev/null
    return 0
  fi

  # TODO(later tasks): take the lock, read and validate the token, run
  # panel/modules, build the payload, and POST it over HTTPS.
  return 0
}

if [[ "${BASH_SOURCE[0]}" == "$0" ]]; then
  main "$@"
fi
