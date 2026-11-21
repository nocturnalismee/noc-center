#!/bin/bash
# SMART health module for the NOC agent.
#
# Contract (see agent/modules/README.md):
#   smart.sh detect   -> exit 0 when smartctl/lsblk are usable, 1 otherwise
#   smart.sh mode     -> print "external"
#   smart.sh collect  -> emit typed metric lines on stdout (key<TAB>type<TAB>value)
#
# This is an external module: the core never runs it on the main collection
# run. A separate systemd timer (Task 11) runs `collector.sh --module smart`;
# the core validates the output and atomically writes the cache. This script
# only reads SMART state and prints metrics — it never writes the cache itself
# and never writes to system files.
#
# Read-only by construction: it only runs `lsblk -dno NAME,TYPE` and
# `smartctl -H -A /dev/<dev>` (one read-only call per disk), all wrapped in
# `timeout`. It does not touch RAID arrays (RAID is a non-goal).

set -u
export LC_ALL=C

# _safe_str VALUE -> single-line printable string (control chars flattened).
_safe_str() {
  local v=${1-} i ch out=''
  v=${v//$'\n'/ }
  v=${v//$'\r'/ }
  v=${v//$'\t'/ }
  for ((i = 0; i < ${#v}; i++)); do
    ch=${v:i:1}
    [[ $ch == [[:cntrl:]] ]] && ch=' '
    out+=$ch
  done
  printf '%s' "$out"
}

# _safe_name VALUE -> device name sanitized to [A-Za-z0-9_.-].
_safe_name() {
  local v=${1-}
  v=${v//[^A-Za-z0-9_.-]/_}
  printf '%s' "$v"
}

# _emit KEY TYPE VALUE -> print one metric line when KEY and VALUE are valid.
_emit() {
  local key=${1-} type=${2-} value=${3-}
  [[ $key =~ ^[A-Za-z][A-Za-z0-9_.-]{0,63}$ ]] || return 0
  case $type in
    n)
      [[ $value =~ ^-?(0|[1-9][0-9]*)(\.[0-9]+)?$ ]] || return 0
      ;;
    b)
      [[ $value == 0 || $value == 1 ]] || return 0
      ;;
    s)
      value=$(_safe_str "$value")
      [[ -n $value ]] || return 0
      ;;
    *)
      return 0
      ;;
  esac
  printf '%s\t%s\t%s\n' "$key" "$type" "$value"
}

# _parse_smart OUTPUT -> print "HEALTH REALLOC PENDING".
# Overall health comes from the self-assessment line; Reallocated_Sector_Ct and
# Current_Pending_Sector are read from the 10th whitespace field (the raw
# value) of their attribute rows.
_parse_smart() {
  local out=${1-}
  printf '%s\n' "$out" | awk '
    /SMART overall-health self-assessment test result:/ { h = $NF }
    {
      for (i = 1; i <= 2; i++) {
        if ($i == "Reallocated_Sector_Ct")  realloc = $(i + 8)
        if ($i == "Current_Pending_Sector") pending = $(i + 8)
      }
    }
    END {
      if (h == "") h = "UNKNOWN"
      if (realloc == "" || realloc == "-") realloc = 0
      if (pending == "" || pending == "-") pending = 0
      printf "%s %s %s\n", h, realloc, pending
    }
  '
}

case ${1:-} in
  detect)
    command -v smartctl >/dev/null 2>&1 || exit 1
    command -v lsblk >/dev/null 2>&1 || exit 1
    exit 0
    ;;
  mode)
    printf 'external\n'
    ;;
  collect)
    updated_at=$(date +%s 2>/dev/null)
    [[ $updated_at =~ ^[0-9]+$ ]] || updated_at=0
    _emit smart.updated_at n "$updated_at"

    max=${SMART_MAX_DISKS:-32}
    [[ $max =~ ^[0-9]+$ ]] || max=32

    # Enumerate whole disks: keep TYPE=disk, skip virtual/optical devices, and
    # cap the number of disks we probe at SMART_MAX_DISKS.
    lsblk_out=$(timeout 5 lsblk -dno NAME,TYPE 2>/dev/null)
    disks=()
    while read -r name type; do
      [[ -n $name ]] || continue
      [[ $type == disk ]] || continue
      case $name in
        zram*|dm-*|loop*|sr*) continue ;;
      esac
      disks+=("$name")
      (( ${#disks[@]} >= max )) && break
    done <<< "$lsblk_out"

    _emit smart.disks n "${#disks[@]}"

    if (( ${#disks[@]} == 0 )); then
      _emit smart.bad_count n 0
      _emit smart.status s unknown
      _emit smart.detail s "no SMART-capable disks"
      exit 0
    fi

    bad=0
    any_failed=0
    any_warn=0
    checked=0
    detail=''

    for dev in "${disks[@]}"; do
      key=$(_safe_name "$dev")
      out=$(timeout 10 smartctl -H -A "/dev/$dev" 2>/dev/null)
      rc=$?
      if (( rc != 0 )); then
        _emit "smart.disk.$key.health" s UNKNOWN
        _emit "smart.disk.$key.realloc" n 0
        _emit "smart.disk.$key.pending" n 0
        detail="${detail}${dev}:unknown "
        continue
      fi
      checked=$((checked + 1))

      parsed=$(_parse_smart "$out")
      read -r health realloc pending <<< "$parsed"
      [[ $realloc =~ ^[0-9]+$ ]] || realloc=0
      [[ $pending =~ ^[0-9]+$ ]] || pending=0

      case $health in
        PASSED) ;;
        FAILED) any_failed=1 ;;
        *) health=UNKNOWN ;;
      esac

      _emit "smart.disk.$key.health" s "$health"
      _emit "smart.disk.$key.realloc" n "$realloc"
      _emit "smart.disk.$key.pending" n "$pending"

      if [[ $health == FAILED || $realloc -gt 0 || $pending -gt 0 ]]; then
        bad=$((bad + 1))
      fi
      if [[ $realloc -gt 0 || $pending -gt 0 ]]; then
        any_warn=1
      fi
      detail="${detail}${dev}:${health,,} "
    done

    # If smartctl failed for every disk, the module cannot produce useful data:
    # fail so the core preserves the previous cache (exit 4).
    if (( checked == 0 )); then
      exit 1
    fi

    _emit smart.bad_count n "$bad"

    if (( any_failed )); then
      status=failed
    elif (( any_warn )); then
      status=warning
    else
      status=ok
    fi
    _emit smart.status s "$status"

    detail=$(_safe_str "$detail")
    _emit smart.detail s "${detail:0:512}"

    exit 0
    ;;
  *)
    exit 2
    ;;
esac
