#!/bin/bash
# MySQL process-list module for the NOC agent.
#
# Contract (see agent/modules/README.md):
#   mysql.sh detect   -> exit 0 when mysql/mysqladmin are usable, 1 otherwise
#   mysql.sh mode     -> print "inline"
#   mysql.sh collect  -> emit typed metric lines on stdout (key<TAB>type<TAB>value)
#
# Read-only by construction: it only runs `mysqladmin ping` / `mysqladmin
# extended-status` and a SELECT against information_schema.PROCESSLIST, all
# wrapped in `timeout 3`. No credentials are passed on argv (they come from
# the client defaults file, e.g. /root/.my.cnf), and query text is never
# written to logs.

set -u
export LC_ALL=C

# shellcheck source=../lib/metrics.sh
source "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/../lib/metrics.sh" || exit 2

case ${1:-} in
  detect)
    command -v mysql >/dev/null 2>&1 || exit 1
    command -v mysqladmin >/dev/null 2>&1 || exit 1
    timeout 3 mysqladmin ping >/dev/null 2>&1 || exit 1
    exit 0
    ;;
  mode)
    printf 'inline\n'
    ;;
  collect)
    max=${MYSQL_MAX_QUERIES:-10}
    min=${MYSQL_MIN_SECONDS:-5}
    [[ $max =~ ^[0-9]+$ ]] || max=10
    [[ $min =~ ^[0-9]+$ ]] || min=5

    updated_at=$(date +%s 2>/dev/null)
    [[ $updated_at =~ ^[0-9]+$ ]] || updated_at=0
    _emit mysql.updated_at n "$updated_at"

    if timeout 3 mysqladmin ping >/dev/null 2>&1; then
      _emit mysql.available b 1
      threads=$(timeout 3 mysqladmin extended-status 2>/dev/null \
        | awk -F'|' '$2 ~ /Threads_running/ { gsub(/^[ \t]+|[ \t]+$/, "", $3); print $3; exit }')
      [[ $threads =~ ^[0-9]+$ ]] && _emit mysql.threads_running n "$threads"
    else
      _emit mysql.available b 0
    fi

    # Read-only process list. Newlines/tabs in query text are flattened to
    # spaces inside SQL so the tab-separated rows parse reliably; query text is
    # truncated to 150 bytes (or suppressed) before emission and never logged.
    out=$(timeout 3 mysql -N -B --raw -e \
      "SELECT user, db, state, REPLACE(REPLACE(REPLACE(IFNULL(info,''), '\n',' '),'\r',' '),'\t',' '), time FROM information_schema.PROCESSLIST WHERE command <> 'Sleep' AND time >= $min ORDER BY time DESC LIMIT $max" \
      2>/dev/null)

    count=0
    i=0
    while IFS=$'\t' read -r user db state info qtime; do
      [[ -n $user || -n $db || -n $state || -n $info || -n $qtime ]] || continue
      count=$((count + 1))
      if (( i < max )); then
        _emit "mysql.query.$i.user" s "$user"
        _emit "mysql.query.$i.db" s "$db"
        _emit "mysql.query.$i.state" s "$state"
        if [[ ${MYSQL_INCLUDE_QUERY_TEXT:-1} == 1 ]]; then
          _emit "mysql.query.$i.info" s "${info:0:150}"
        else
          _emit "mysql.query.$i.info" s "-"
        fi
        [[ $qtime =~ ^[0-9]+$ ]] && _emit "mysql.query.$i.time" n "$qtime"
        i=$((i + 1))
      fi
    done <<< "$out"

    _emit mysql.long_queries n "$count"
    exit 0
    ;;
  *)
    exit 2
    ;;
esac
