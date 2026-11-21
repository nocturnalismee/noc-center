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

main() {
  parse_args "$@" || return 1

  if (( OPT_VERSION )); then
    printf 'noc-agent %s\n' "$AGENT_VERSION"
    return 0
  fi

  if (( OPT_HELP )); then
    usage
    return 0
  fi

  # TODO(later tasks): load and validate config, apply CLI overrides, take the
  # lock, collect metrics, run panel/modules, build the payload, and POST it.
  return 0
}

if [[ "${BASH_SOURCE[0]}" == "$0" ]]; then
  main "$@"
fi
