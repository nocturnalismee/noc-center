#!/bin/bash
# Disk quota module for the NOC agent.
#
# Contract (see agent/modules/README.md):
#   diskquota.sh detect   -> exit 0 when repquota is usable, 1 otherwise
#   diskquota.sh mode     -> print "external"
#   diskquota.sh collect  -> emit typed metric lines on stdout (key<TAB>type<TAB>value)
#
# This is an external module: the core never runs it on the main collection
# run. A separate systemd timer (Task 11) runs `collector.sh --module
# diskquota`; the core validates the output and atomically writes the cache.
# This script only runs read-only `repquota -a -O csv` and prints metrics — it
# never writes the cache itself and never writes to system files.
#
# When the cPanel user directory (/var/cpanel/users) exists the module
# restricts to cPanel accounts (one file per account, minus the "system"
# pseudo-user); otherwise it uses every user reported by repquota. RAID is a
# non-goal.

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

# _safe_name VALUE -> username sanitized to [A-Za-z0-9_.-].
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

# _col_index HEADER NAME -> 1-based column index of NAME in a CSV header, or
# empty when absent. Header cells are stripped of surrounding quotes/spaces.
_col_index() {
  local header=${1-} name=${2-}
  printf '%s\n' "$header" | awk -v n="$name" -F, '
    {
      for (i = 1; i <= NF; i++) {
        c = $i
        gsub(/^[ \t"\r]+|[ \t"\r]+$/, "", c)
        if (c == n) { print i; exit }
      }
    }'
}

case ${1:-} in
  detect)
    command -v repquota >/dev/null 2>&1 || exit 1
    exit 0
    ;;
  mode)
    printf 'external\n'
    ;;
  collect)
    updated_at=$(date +%s 2>/dev/null)
    [[ $updated_at =~ ^[0-9]+$ ]] || updated_at=0
    _emit diskquota.updated_at n "$updated_at"

    topn=${QUOTA_TOP_N:-15}
    [[ $topn =~ ^[0-9]+$ ]] || topn=15
    (( topn < 1 )) && topn=1

    # cPanel restriction: when the cPanel user dir exists, only cPanel
    # accounts (files, minus the "system" pseudo-user) count.
    cpanel_dir=${DISKQUOTA_CPANEL_USERS_DIR:-/var/cpanel/users}
    cpanel_active=0
    declare -A cpanel_user=()
    if [[ -d $cpanel_dir ]]; then
      cpanel_active=1
      while IFS= read -r u; do
        [[ -n $u ]] && cpanel_user[$u]=1
      done < <(find "$cpanel_dir" -maxdepth 1 -type f ! -name system -printf '%f\n' 2>/dev/null)
    fi

    csv=$(timeout 15 repquota -a -O csv 2>/dev/null)
    rc=$?
    if (( rc != 0 )) || [[ -z $csv ]]; then
      exit 1
    fi

    # Map columns by header name (User, BlockUsed, and a limit column) so we
    # tolerate the newer BlockStatus/FileStatus columns and any ordering.
    # Older quota-tools name the limit "BlockLimit"; newer releases split it
    # into "BlockSoftLimit"/"BlockHardLimit" — use the hard limit for "over".
    header=$(printf '%s\n' "$csv" | sed -n '1p')
    [[ -n $header ]] || exit 1
    u_col=$(_col_index "$header" User)
    b_col=$(_col_index "$header" BlockUsed)
    [[ -n $u_col && -n $b_col ]] || exit 1
    l_col=$(_col_index "$header" BlockLimit)
    [[ -n $l_col ]] || l_col=$(_col_index "$header" BlockHardLimit)
    l_col=${l_col:-0}

    body=$(printf '%s\n' "$csv" | sed '1d')
    parsed=$(printf '%s\n' "$body" | awk -F, -v u="$u_col" -v b="$b_col" -v l="$l_col" '
      function strip(x) {
        gsub(/^[ \t"\r]+|[ \t"\r]+$/, "", x)
        return x
      }
      {
        user = strip($u)
        used = strip($b)
        limit = (l > 0 && l <= NF) ? strip($l) : ""
        if (user == "") next
        if (used !~ /^[0-9]+$/) next
        printf "%s\t%s\t%s\n", user, used, limit
      }')

    accounts=0
    over=0
    rows=''
    while IFS=$'\t' read -r user used limit; do
      [[ -n $user ]] || continue
      if (( cpanel_active )) && [[ -z ${cpanel_user["$user"]-} ]]; then
        continue
      fi
      [[ $used =~ ^[0-9]+$ ]] || continue
      (( used > 0 )) || continue
      accounts=$((accounts + 1))
      if [[ $limit =~ ^[0-9]+$ ]] && (( limit > 0 && used > limit )); then
        over=$((over + 1))
      fi
      rows+="$used"$'\t'"$user"$'\n'
    done <<< "$parsed"

    _emit diskquota.accounts n "$accounts"
    _emit diskquota.over n "$over"

    while IFS=$'\t' read -r used user; do
      [[ -n $user ]] || continue
      key_user=$(_safe_name "$user")
      [[ -n $key_user ]] || continue
      gb=$(awk -v u="$used" 'BEGIN{printf "%.1f", u/1048576}')
      _emit "diskquota.top.$key_user.used_gb" n "$gb"
    done < <(printf '%s' "$rows" | sort -rn | head -n "$topn")

    exit 0
    ;;
  *)
    exit 2
    ;;
esac
