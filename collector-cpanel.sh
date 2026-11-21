#!/bin/bash
# ═══════════════════════════════════════════════════════════════
# collector-cpanel.sh — Server Status Monitor data collector (root cron)
# Install : /root/<dir>/collector-cpanel.sh (chmod 700), root crontab every minute.
# Consumer: <web_dir>/index.php  (root feed — CageFS blocks PHP from the full
#           process list, so root collects it and hands it to the web user).
# Config  : config.env in the same dir → WEB_USER (required) + DATA_DIR (caches;
#           DATA_DIR must be root-owned and not group/world-writable).
# Safety  : read-only collector — it never edits service/config files. It only
#           reads system state and writes its own snapshot/cache/history files
#           atomically (mktemp + rename, never through a user-controlled symlink).
# Note    : explicit PATH is required — whmapi1/cagefsctl/exim are not on cron's
#           default PATH. sleep 20 dodges the top-of-minute cron storm.
# ═══════════════════════════════════════════════════════════════
SELF_DIR="$(cd "$(dirname "$0")" 2>/dev/null && pwd)"
# Stable output parsing (decimal point, sort order, ps/df messages).
export LC_ALL=C
# Caches and temp files contain process/account data — keep them private.
umask 077
if [ "$(id -u)" -ne 0 ]; then
  echo "collector: must run as root (it reads root-only system state)" >&2
  exit 1
fi

# A root cron script must not source a config that a non-root user can modify.
CONF="$SELF_DIR/config.env"
if [ -f "$CONF" ]; then
  CONF_UID=$(stat -c %u "$CONF" 2>/dev/null || echo 1)
  CONF_MODE=$(stat -c %a "$CONF" 2>/dev/null || echo 777)
  if [ "$CONF_UID" != "0" ] || [ $(( 0$CONF_MODE & 022 )) -ne 0 ]; then
    echo "collector: refusing insecure config $CONF (must be root-owned and not group/world-writable)" >&2
    exit 1
  fi
  # shellcheck source=/dev/null
  . "$CONF"
fi
: "${WEB_USER:?config.env must define WEB_USER (e.g. WEB_USER=myuser)}"
case "$WEB_USER" in
  ''|[!A-Za-z_]*|*[!A-Za-z0-9_-]*)
    echo "collector: invalid WEB_USER '$WEB_USER'" >&2
    exit 1 ;;
esac
HOME_DIR="/home/$WEB_USER"
if ! id -u -- "$WEB_USER" >/dev/null 2>&1 || [ ! -d "$HOME_DIR" ]; then
  echo "collector: WEB_USER '$WEB_USER' does not exist or has no home directory" >&2
  exit 1
fi
DATA_DIR="${DATA_DIR:-$SELF_DIR}"
case "$DATA_DIR" in
  /*) ;;
  *) DATA_DIR="$SELF_DIR/$DATA_DIR" ;;
esac
if [ ! -d "$DATA_DIR" ] || [ ! -w "$DATA_DIR" ]; then
  echo "collector: DATA_DIR '$DATA_DIR' is missing or not writable" >&2
  exit 1
fi
# Cache/temp names are fixed, so DATA_DIR must not be writable by the web user
# (or anyone else). Otherwise a planted symlink could make root truncate a
# system file through one of the .tmp paths. A symlinked DATA_DIR is refused
# too, so the web user cannot retarget a root-owned path.
DATA_UID=$(stat -c %u "$DATA_DIR" 2>/dev/null || echo 1)
DATA_MODE=$(stat -c %a "$DATA_DIR" 2>/dev/null || echo 777)
if [ -L "$DATA_DIR" ] || [ "$DATA_UID" != "0" ] || [ $(( 0$DATA_MODE & 022 )) -ne 0 ]; then
  echo "collector: refusing insecure DATA_DIR '$DATA_DIR' (must be a real root-owned directory, not group/world-writable)" >&2
  exit 1
fi
# Single-instance lock: when priming (a manual run) coincides with the same
# minute as cron, or a run spills over into the next cron tick, prevent two
# instances from writing the same .tmp files and producing "mv: cannot stat".
# If the lock cannot be acquired (another instance is running), exit silently;
# if the lock file cannot be opened, continue without a lock; if flock is
# unavailable, skip locking.
if command -v flock >/dev/null 2>&1; then
  if { exec 9>"/var/run/ssm-collector-$WEB_USER.lock"; } 2>/dev/null; then
    flock -n 9 || exit 0
  fi
fi
export PATH=/usr/local/cpanel/bin:/usr/local/cpanel/3rdparty/bin:/usr/sbin:/usr/bin:/sbin:/bin
sleep 20   # dodge the top-of-minute cron storm

# The history files live in the web user's home, which that user controls. Read
# them without following a symlink, so a planted link cannot make root read a
# system file and publish its contents to the dashboard. GNU dd gained
# "iflag=nofollow" in newer coreutils; fall back to an explicit -L check on
# older cPanel hosts. A timeout also avoids hanging on a planted FIFO.
DD_NOFOLLOW=0
if dd if=/dev/null iflag=nofollow status=none >/dev/null 2>&1; then
  DD_NOFOLLOW=1
fi
safe_tail() {
  [ -f "$1" ] || return 0
  [ -L "$1" ] && return 0
  if [ "$DD_NOFOLLOW" = 1 ]; then
    timeout 5 dd if="$1" iflag=nofollow status=none 2>/dev/null | tail -n "$2"
  else
    [ ! -L "$1" ] && timeout 5 tail -n "$2" "$1" 2>/dev/null
  fi
}
# Publish an already-written temp file (fd 8) atomically. chown/chmod go
# through /proc/self/fd/8, so they always touch the inode root opened — even if
# the web user replaced the temp path with a symlink meanwhile. The inode check
# before rename rejects a swapped path; mv -T never follows a destination
# symlink or directory.
publish_tmp() {
  if chown "$WEB_USER:$WEB_USER" /proc/self/fd/8 2>/dev/null &&
     chmod 640 /proc/self/fd/8 2>/dev/null &&
     [ "$(stat -Lc '%d:%i' /proc/self/fd/8 2>/dev/null)" = "$(stat -c '%d:%i' -- "$1" 2>/dev/null)" ]; then
    exec 8>&-
    if mv -fT "$1" "$2"; then
      return 0
    fi
    echo "collector: cannot replace $2" >&2
    return 1
  fi
  exec 8>&-
  rm -f "$1"
  echo "collector: refusing to publish $2 (temp file replaced, or chown/chmod failed)" >&2
  return 1
}

OUT="$HOME_DIR/.proc_snapshot"
# The web user owns this directory, so fixed temp names (and direct >> appends)
# are unsafe: a planted symlink could make root truncate or append to a system
# file. mktemp creates a fresh O_EXCL file, and mv -T atomically replaces the
# destination entry (a renamed symlink itself, never its target or a directory).
OUT_TMP=""
HIST_TMP=""
DHIST_TMP=""
cleanup_temps() {
  [ -n "${OUT_TMP:-}" ] && rm -f "$OUT_TMP"
  [ -n "${HIST_TMP:-}" ] && rm -f "$HIST_TMP"
  [ -n "${DHIST_TMP:-}" ] && rm -f "$DHIST_TMP"
}
trap cleanup_temps EXIT
OUT_TMP=$(mktemp "$HOME_DIR/.proc_snapshot.XXXXXX") || {
  echo "collector: cannot create temporary snapshot in $HOME_DIR" >&2
  exit 1
}
exec 8>"$OUT_TMP" || {
  rm -f "$OUT_TMP"
  OUT_TMP=""
  echo "collector: cannot open temporary snapshot in $HOME_DIR" >&2
  exit 1
}
if {
  echo "--- Top 15 by CPU ---"
  ps axo pid,user:19,pcpu,pmem,etime,args --sort=-pcpu | head -16 | cut -c1-280
  echo
  echo "--- Top 15 by RSS ---"
  ps axo pid,user:19,pcpu,pmem,rss,args --sort=-rss | head -16 | cut -c1-280
  echo
  echo "--- lsphp per user ---"
  ps axo user:19,comm --no-headers | awk '$2=="lsphp"{c[$1]++} END{for(u in c) printf "%d %s lsphp\n", c[u], u}' | sort -rn | head -15
  echo
  echo "--- Security checks ---"
  if [ -d /etc/csf ]; then
    [ -e /etc/csf/csf.disable ] && echo "csf disabled" || echo "csf enabled"
    T=$(awk -F'"' '/^TESTING /{print $2}' /etc/csf/csf.conf 2>/dev/null)
    [ -n "$T" ] && echo "csf_testing $T"
  fi
  M=$(awk '/^[[:space:]]*SecRuleEngine/{print $2; exit}' /etc/apache2/conf.d/modsec/modsec2.cpanel.conf 2>/dev/null)
  [ -n "$M" ] && echo "modsec $M"
  C=$(timeout 5 cagefsctl --cagefs-status 2>/dev/null | head -1)
  [ -n "$C" ] && echo "cagefs $C"
  echo
  echo "--- WHM services ---"
  timeout 10 whmapi1 servicestatus 2>/dev/null | awk '$1=="enabled:"{e=$2} $1=="installed:"{i=$2} $1=="name:"{n=$2} $1=="running:"{print "svc", n, e+0, i+0, $2+0}'
  echo
  echo "--- Root checks ---"
  echo "acct $(find /var/cpanel/users -maxdepth 1 -type f ! -name system 2>/dev/null | wc -l)"
  echo "mailq $(timeout 5 exim -bpc 2>/dev/null || echo -1)"
  echo "mysql_ping $(timeout 3 mysqladmin ping >/dev/null 2>&1 && echo 1 || echo 0)"
  echo "websrv $(pgrep -x litespeed >/dev/null 2>&1 && echo litespeed || echo apache)"
  # Inode usage (%): even when disk SPACE is free, running out of inodes crashes
  # the server (no new files can be created). Millions of small files (LiteSpeed
  # cache, etc.) consume them. PHP cannot easily read inode usage; root provides
  # it via df -i.
  echo "inode_pct $(df -iP / | awk 'NR==2{gsub("%","",$5); print $5+0}')"
  # RAID health (/proc/mdstat): in a RAID5/1 array, when one disk dies silently
  # the array keeps running — unnoticed until the second one dies. SMART is
  # already monitored by smartd via email; the gap here is ARRAY status. In the
  # "[U_]" block, _ = degraded; recovery/resync = mirror rebuild (self-healing).
  # rebuild takes precedence over degraded (it means a repair is in progress).
  # No line is printed if no array exists.
  if [ -r /proc/mdstat ]; then
    RAID=$(awk '
      match($0,/\[[U_]+\]/){b=substr($0,RSTART,RLENGTH);a++;if(b~/_/){d=1;bad=b}else ok=b}
      /(recovery|resync|reshape)[[:space:]]*=/{if(match($0,/[0-9.]+%/)){p=substr($0,RSTART,RLENGTH);r=1}}
      END{if(!a)exit; if(r)print "resync "p; else if(d)print "degraded "bad; else print "ok "ok}' /proc/mdstat)
    if [ -n "$RAID" ]; then
      echo "raid $RAID"
      # mismatch_cnt: number of MISMATCHED blocks between copies/parity in the
      # last RAID check (weekly scrub). 0 on a healthy array; >0 = risk of
      # silent data corruption ("corrupt volume"). Data can be inconsistent
      # even while the arrays are up ([UUU]). Always written, including 0 (the
      # dashboard shows 0 green and >0 orange).
      MM=0; for f in /sys/block/md*/md/mismatch_cnt; do [ -r "$f" ] && MM=$((MM + $(cat "$f" 2>/dev/null || echo 0))); done
      echo "raid_mismatch $MM"
    fi
  fi
  # SMART early warning (HOURLY cache): smartd already sends email — here we
  # show ONLY problematic disks (FAILED health / realloc / pending sectors).
  # Healthy disks never appear (alarm-only). Silently skipped if smartctl is
  # not available.
  SMC=$DATA_DIR/.smart_cache
  if command -v smartctl >/dev/null 2>&1 && { [ ! -f "$SMC" ] || [ $(( $(date +%s) - $(stat -c %Y "$SMC" 2>/dev/null || echo 0) )) -gt 3600 ]; }; then
    : > "$SMC.tmp"
    # One smartctl call per disk (-H -A) halves disk wake-ups. zram/dm/loop/sr
    # are not real SMART targets and only waste time.
    while read -r D; do
      A=$(timeout 10 smartctl -H -A "/dev/$D" 2>/dev/null)
      H=$(printf '%s\n' "$A" | awk -F: '/overall-health/{gsub(/[ !]/,"",$2);print $2}')
      RA=$(printf '%s\n' "$A" | awk '$2=="Reallocated_Sector_Ct"{print $10+0; exit}')
      PE=$(printf '%s\n' "$A" | awk '$2=="Current_Pending_Sector"{print $10+0; exit}')
      ISS=""
      [ "$H" = "FAILED" ] && ISS="FAILING"
      [ -n "$RA" ] && [ "$RA" -gt 0 ] 2>/dev/null && ISS="${ISS:+$ISS, }${RA} realloc"
      [ -n "$PE" ] && [ "$PE" -gt 0 ] 2>/dev/null && ISS="${ISS:+$ISS, }${PE} pending"
      [ -n "$ISS" ] && echo "smart_bad $D $ISS" >> "$SMC.tmp"
    done < <(lsblk -dno NAME,TYPE 2>/dev/null | awk '$2=="disk" && $1 !~ /^(zram|dm-|loop|sr)/{print $1}')
    mv "$SMC.tmp" "$SMC"
  fi
  cat "$SMC" 2>/dev/null
  # Link speed (Mbps): under CageFS, PHP cannot read /sys/class/net (like disk
  # I/O, root supplies it). ONLY interfaces that are up + physical (have a
  # device symlink) + report a valid speed (speed>0; -1 = down/virtio does not
  # report). NO cache: cat is free, and link speed updates every minute if it
  # changes. PHP compares each interface against ITS OWN speed and takes the max
  # (not the sum) — an unused port (e.g. eth2 with no IP) comes out at 0%, so
  # it does not inflate the figure.
  for IF in /sys/class/net/*; do
    N=${IF##*/}; [ "$N" = lo ] && continue
    [ -e "$IF/device" ] || continue
    [ "$(cat "$IF/operstate" 2>/dev/null)" = up ] || continue
    SP=$(cat "$IF/speed" 2>/dev/null || echo -1)
    [ "$SP" -gt 0 ] 2>/dev/null && echo "netspeed $N $SP"
  done
  # One process-state snapshot feeds all four gauges (lsphp busy/idle, D, R).
  read -r LSPHP_TOTAL LSPHP_IDLE DSTATE RSTATE < <(
    ps -eo state=,comm= | awk '
      $2=="lsphp" && ($1 ~ /^R/ || $1 ~ /^D/){t++}
      $2=="lsphp" && $1 ~ /^S/{i++}
      $1 ~ /^D/{d++}
      $1 ~ /^R/{r++}
      END{print t+0, i+0, d+0, r+0}'
  )
  echo "lsphp_total $LSPHP_TOTAL"
  echo "lsphp_idle $LSPHP_IDLE"
  # D-state: number of processes blocked on disk — the key to diagnosing
  # "high load but low CPU".
  echo "dstate $DSTATE"
  # rstate: number of running (R) processes. Load anatomy: load ~ R + D
  # (uninterruptible). Read from root because CageFS may virtualize process
  # counts per cage.
  echo "rstate $RSTATE"
  # Activity ages (dashboard chips): the etimes of the oldest matching process
  # in the ENTIRE process list — calculating it from the Top-15 CPU table was
  # misleading (pkgacct respawns for every account, so a "10-hour backup"
  # looked like 1m). The bracket trick ([p]kgacct) prevents awk from matching
  # its own command line.
  # backup: task-lifetime processes, direct observation (no threshold needed).
  # update: cPanel upcp/updatenow + system package updates (dnf/yum) —
  # among the nighttime load culprits; task-lifetime like backup, no threshold
  # needed.
  # wpt: no documented CLI lists the task queue — a CPU-thresholded (pcpu>=15)
  # process heuristic remains. The persistent sw-engine-fpm pool idles at 0%,
  # so the threshold filters it out; since WPT tasks are short-lived, the pcpu
  # average is reliable.
  # One ps snapshot is reused for all three patterns (saves two full scans).
  read -r ACT_BACKUP ACT_UPDATE ACT_WPT < <(
    ps axo etimes=,pcpu=,args= | awk '
      /[p]kgacct|[c]pbackup|cpanel\/[b]in\/backup/{if($1>b)b=$1}
      /[u]pcp|[u]pdatenow|[d]nf (upgrade|update)|[y]um (upgrade|update)/{if($1>u)u=$1}
      $2>=15 && /[w]p-toolkit|[w]ordpress-toolkit/{if($1>w)w=$1}
      END{print b+0, u+0, w+0}'
  )
  [ "$ACT_BACKUP" -gt 0 ] && echo "act_backup $ACT_BACKUP"
  [ "$ACT_UPDATE" -gt 0 ] && echo "act_update $ACT_UPDATE"
  [ "$ACT_WPT" -gt 0 ] && echo "act_wpt $ACT_WPT"
  # imunify: AUTHORITATIVE source — the agent's own records (age of the oldest
  # scan in "running" state). A process heuristic does not work here: the scan
  # runs inside the persistent rustbolit --resident, and ps pcpu is a lifetime
  # average, so it is wrong in both directions. The CLI costs ~1-2 s of python;
  # timeout-protected, once a minute is acceptable.
  # Real output verification (Jul 2026): top level {"max_count":N,"items":[...]},
  # the status field is "scan_status" (NOT "status" as documented), "started" is
  # in epoch seconds.
  # Output: "age file_count" — the file count gives the SCOPE (a weekly full
  # sweep covers thousands of files, an incremental/changed-file scan only a
  # handful). The scan representing the scope = the running one with the most
  # total_resources.
  # Output: "age file_count type". In a running scan total_resources is usually
  # 0 (Imunify fills it once counting finishes), so the real discriminator is
  # PATH: /home/<account> = that account's scan (show the account name), a
  # system path = changed-file noise (incremental).
  # 5-MINUTE CACHE: the CLI would cost ~1.2 s of CPU per minute; scan age and
  # target change slowly, so 5-minute-stale data is fine. The cache holds an
  # "age file type" line (may be empty).
  IMC=$DATA_DIR/.imunify_cache
  if [ ! -f "$IMC" ] || [ $(( $(date +%s) - $(stat -c %Y "$IMC" 2>/dev/null || echo 0) )) -gt 300 ]; then
    # Only replace the cache when the CLI + parser succeeded. A timeout or an
    # unparseable/partial JSON response keeps the previous good cache; an empty
    # (but successful) result intentionally clears it (no scan is running).
    if timeout 10 imunify360-agent malware on-demand list --json 2>/dev/null | python3 -c '
import sys, json, time
try:
    def ts(x):
        try:
            return float(x)
        except (TypeError, ValueError):
            return 0.0
    d = json.load(sys.stdin)
    items = d if isinstance(d, list) else (d.get("items") or d.get("data") or [])
    if not isinstance(items, list):
        items = []
    run = [s for s in items
           if s.get("scan_status", s.get("status")) == "running" and ts(s.get("started")) > 0]
    if run:
        top = max(run, key=lambda s: (s.get("total_resources") or s.get("total") or 0))
        age = int(time.time() - min(ts(s.get("started")) for s in run))
        n = top.get("total_resources") or top.get("total") or 0
        # Type label: /home/<account>/... → account name (a real account scan),
        # otherwise incremental (changed system file noise). Imunify does not
        # cleanly distinguish weekly/incremental for a running scan; we show the
        # scanned target.
        parts = [x for x in (top.get("path") or "").split("/") if x]
        if len(parts) >= 2 and parts[0] == "home":
            p = "".join(c for c in parts[1] if c.isalnum() or c in "._-")[:20] or "-"
        else:
            p = "incremental"
        print("%d %d %s" % (age, n, p), end="")
except Exception:
    sys.exit(1)' > "$IMC.tmp" 2>/dev/null; then
      mv "$IMC.tmp" "$IMC"
    else
      rm -f "$IMC.tmp"
    fi
  fi
  A=$(cat "$IMC" 2>/dev/null)
  [ -n "$A" ] && { read -r IM_AGE IM_N IM_P <<< "$A"; echo "act_imunify $IM_AGE"; echo "act_imunify_n $IM_N"; echo "act_imunify_p $IM_P"; }
  # Threads_running: threads currently processing a query — reveals the
  # "brief but many queries" scenario.
  THR=$(timeout 3 mysqladmin extended-status 2>/dev/null | awk '$2=="Threads_running"{print $4}')
  [ -n "$THR" ] && echo "mysql_thr $THR"
  # Disk R/W rate (KB/s, 1 s sample): PHP inside CageFS CANNOT SEE
  # /proc/diskstats (confirmed in a live test) — the R/W part of the IO Wait
  # metric comes from root. Whole disks only (partitions would double-count);
  # one sector = 512 bytes.
  DRE='^(sd[a-z]+|nvme[0-9]+n[0-9]+|vd[a-z]+|xvd[a-z]+)$'
  read -r R1 W1 < <(awk -v re="$DRE" '$3 ~ re {r+=$6; w+=$10} END{print r+0, w+0}' /proc/diskstats)
  sleep 1
  read -r R2 W2 < <(awk -v re="$DRE" '$3 ~ re {r+=$6; w+=$10} END{print r+0, w+0}' /proc/diskstats)
  echo "diskio_r $(( (R2-R1)*512/1024 ))"
  echo "diskio_w $(( (W2-W1)*512/1024 ))"
  echo
  echo "--- Service ages ---"
  # Runtime of the main daemons (etimes, seconds). With multiple processes, the
  # oldest one = the daemon. The dashboard shows a service younger than 1 hour
  # with a "restarted X ago" note (no color).
  # NOTE: perl daemons (lfd, cpsrvd) change their comm via $0 ("lfd - sleeping",
  # "cpsrvd (SSL) - ..."), so an exact ps -C match cannot find them — a prefix
  # match on "name" or "name + space" is used.
  ps axo etimes=,comm= | awk '
    { e=$1+0; c=$2; for(i=3;i<=NF;i++) c=c" "$i
      n=split("mariadbd mysqld litespeed lshttpd httpd exim dovecot named pdns_server pure-ftpd proftpd cpsrvd cpsrvd-dormant lfd imunify360-agen imunify-residen redis-server memcached", L, " ")
      for(j=1;j<=n;j++){ p=L[j]; if(c==p || index(c, p " ")==1){ if(e>m[p]) m[p]=e } } }
    END{ for(p in m) print "svcage", p, m[p] }'
  echo
  echo "--- Versions ---"
  # Versions shown under the card headers — included in the CSF email attachment
  # for post-update diagnosis ("which version was it broken on").
  # Format: "ver <key> <Label> <version>". Anything not found is silently
  # skipped.
  # HOURLY CACHE: versions do not change by the minute; avoids running the
  # heavy imunify360-agent CLI (~1-2 s of python) every minute.
  VC=$DATA_DIR/.ver_cache
  if [ ! -s "$VC" ] || [ $(( $(date +%s) - $(stat -c %Y "$VC" 2>/dev/null || echo 0) )) -gt 3600 ]; then
    {
      V=$(timeout 5 /usr/local/lsws/bin/lshttpd -v 2>/dev/null | head -1 | awk -F/ '{print $2}' | awk '{print $1}'); [ -n "$V" ] && echo "ver web LiteSpeed $V"
      [ -z "$V" ] && { V=$(timeout 5 httpd -v 2>/dev/null | awk -F/ '/Server version/{print $2}' | awk '{print $1}'); [ -n "$V" ] && echo "ver web Apache $V"; }
      V=$(awk 'NR==1{print $1}' /usr/local/cpanel/version 2>/dev/null); [ -n "$V" ] && echo "ver cpanel cPanel $V"
      V=$(timeout 3 mysqladmin version 2>/dev/null | awk '/Server version/{print $3}' | sed 's/-MariaDB.*//'); [ -n "$V" ] && echo "ver db MariaDB $V"
      V=$(timeout 3 exim -bV 2>/dev/null | awk 'NR==1{print $3}'); [ -n "$V" ] && echo "ver exim Exim $V"
      V=$(timeout 3 dovecot --version 2>/dev/null | awk '{print $1}'); [ -n "$V" ] && echo "ver dovecot Dovecot $V"
      V=$(timeout 3 named -v 2>/dev/null | awk '{print $2}' | sed 's/-RedHat.*//'); [ -n "$V" ] && echo "ver named BIND $V"
      # LFD comes from the same csf package — it carries the same version.
      V=$(timeout 5 csf -v 2>/dev/null | awk 'NR==1{print $2}'); [ -n "$V" ] && { echo "ver csf CSF $V"; echo "ver lfd LFD $V"; }
      V=$(timeout 5 imunify360-agent version 2>/dev/null | head -1 | awk '{print $NF}'); [ -n "$V" ] && echo "ver imunify Imunify360 $V"
      V=$(timeout 5 cagefsctl --version 2>/dev/null | awk '{print $NF}' | sed -E 's/-[0-9]+\.el[0-9].*$//'); [ -n "$V" ] && echo "ver cagefs CageFS $V"
      # rpm -q prints "package ... is not installed" to stdout on failure, so
      # filter that out instead of trusting the exit status.
      V=$(rpm -q --qf '%{VERSION}' ea-apache24-mod_security2 2>/dev/null | grep -v 'not installed'); [ -n "$V" ] && echo "ver modsec ModSecurity $V"
      V=$(rpm -q --qf '%{VERSION}' pure-ftpd 2>/dev/null | grep -v 'not installed')
      [ -z "$V" ] && V=$(timeout 3 /usr/sbin/pure-ftpd --help 2>&1 | awk 'NR==1 && $2 ~ /^v?[0-9]/{sub(/^v/,"",$2); print $2}')
      [ -n "$V" ] && echo "ver ftp Pure-FTPd $V"
      V=$(timeout 3 redis-server --version 2>/dev/null | awk -F'v=' 'NF>1{print $2}' | awk '{print $1}'); [ -n "$V" ] && echo "ver redis Redis $V"
      V=$(timeout 3 memcached -h 2>/dev/null | awk 'NR==1{print $2}'); [ -n "$V" ] && echo "ver memcached Memcached $V"
      # Keep the group successful even when the last optional probe is absent,
      # otherwise "&& mv" would skip the cache update and rerun every minute.
      true
    } > "$VC.tmp" && mv "$VC.tmp" "$VC"
  fi
  cat "$VC" 2>/dev/null
  # Unlike the cached values: uname is free and must be correct immediately
  # after a reboot.
  echo "ver kernel Linux $(uname -r)"
  echo
  echo "--- Top disk accounts ---"
  # Top cPanel ACCOUNTS by disk usage (up to 15, system users excluded). Source:
  # repquota CSV (reads the quota file, does not walk the filesystem → cheap).
  # HOURLY cache because disk usage does not change by the minute.
  # BlockUsed is located by header name, so quota-tools versions both with and
  # without the FileStatus column work. BlockUsed KB → GB.
  DAC=$DATA_DIR/.diskacct_cache
  if [ ! -s "$DAC" ] || [ $(( $(date +%s) - $(stat -c %Y "$DAC" 2>/dev/null || echo 0) )) -gt 3600 ]; then
    find /var/cpanel/users -maxdepth 1 -type f ! -name system -printf '%f\n' 2>/dev/null > "$DAC.users"
    timeout 15 repquota -a -O csv 2>/dev/null | awk -F, '
      BEGIN{u=1; b=4}
      FILENAME==ARGV[1]{cp[$1]=1; next}
      $1=="User"{for(i=1;i<=NF;i++){if($i=="User")u=i; else if($i=="BlockUsed")b=i}; next}
      ($u in cp) && $b+0>0 {printf "%d %s\n", $b, $u}
    ' "$DAC.users" - | sort -rn | head -15 | awk '{printf "diskacct %s %.1f\n", $2, $1/1048576}' > "$DAC.tmp" && mv "$DAC.tmp" "$DAC"
    rm -f "$DAC.users"
  fi
  cat "$DAC" 2>/dev/null
  echo
  echo "--- MySQL queries ---"
  timeout 3 mysql -N -B -e "SELECT id, user, IFNULL(db,'-'), time, IFNULL(state,'-'), REPLACE(REPLACE(REPLACE(LEFT(info,150),'\n',' '),'\r',' '),'\t',' ') FROM information_schema.PROCESSLIST WHERE command <> 'Sleep' AND info IS NOT NULL AND time >= 5 AND id <> CONNECTION_ID() ORDER BY time DESC LIMIT 10" 2>/dev/null || true
} >&8; then
  publish_tmp "$OUT_TMP" "$OUT" && OUT_TMP=""
else
  exec 8>&-
  rm -f "$OUT_TMP"
  OUT_TMP=""
fi

# --- sparkline history (3 s window, mid-minute, 35-line buffer) ---
HIST="$HOME_DIR/.metrics_history"
read -r T1 I1 W1 < <(awk '/^cpu /{print $2+$3+$4+$5+$6+$7+$8, $5, $6}' /proc/stat)
sleep 3
read -r T2 I2 W2 < <(awk '/^cpu /{print $2+$3+$4+$5+$6+$7+$8, $5, $6}' /proc/stat)
TD=$(( T2-T1 )); IDL=$(( I2-I1 )); WIO=$(( W2-W1 ))
CPU=0; IOW=0
if [ "$TD" -gt 0 ]; then
  # CPU excludes idle AND iowait; iowait is reported separately as IOW.
  # Subtracting only idle would count the same time twice.
  CPU=$(( 100*(TD-IDL-WIO)/TD ))
  IOW=$(( 100*WIO/TD ))
fi
read -r L1 L5 L15 _ < /proc/loadavg
# Fall back to MemFree+Buffers+Cached on kernels without MemAvailable.
RAM=$(awk '/^MemTotal/{t=$2} /^MemAvailable/{a=$2} /^MemFree/{f=$2} /^Buffers/{buf=$2} /^Cached/{c=$2} END{if(t>0){if(!a)a=f+buf+c; printf "%d",(t-a)*100/t}}' /proc/meminfo)
DSK=$(df -P / | awk 'NR==2{gsub("%","",$5);print $5}')
WRK=$(ps -eo state=,comm= | awk '$2=="lsphp" && ($1 ~ /^R/ || $1 ~ /^D/){c++} END{print c+0}')
# Network speed: total of /proc/net/dev 1 s apart (excluding lo), KB/s.
# Split on the colon, not on whitespace: long names (enp4s..., wlp2s0) have no
# leading padding, while short names (eth0, lo) do. Byte counters are fields
# 1 and 9 after the colon; field 3/11 would be packets.
read -r RX1 TX1 < <(awk -F': *' 'NR>2{n=$1;gsub(/^[[:space:]]+/,"",n);if(n=="lo")next;split($2,a,/[[:space:]]+/);rx+=a[1];tx+=a[9]} END{print rx+0,tx+0}' /proc/net/dev)
sleep 1
read -r RX2 TX2 < <(awk -F': *' 'NR>2{n=$1;gsub(/^[[:space:]]+/,"",n);if(n=="lo")next;split($2,a,/[[:space:]]+/);rx+=a[1];tx+=a[9]} END{print rx+0,tx+0}' /proc/net/dev)
RXK=$(( (RX2 - RX1) / 1024 )); TXK=$(( (TX2 - TX1) / 1024 ))
MQ=$(timeout 5 exim -bpc 2>/dev/null || echo 0)
# 13th column: the most CPU-hungry process of that minute ("comm:cpu"). The
# dashboard attaches this to load alerts ("High load ... — top: pigz 72%");
# written on the same line, so its timing matches the metrics exactly.
# Spaces/colons are converted to underscores.
TOPP=$(ps axo pcpu,comm --sort=-pcpu --no-headers | head -1 | awk '{n=$2; for(i=3;i<=NF;i++) n=n"_"$i; gsub(/:/,"_",n); printf "%s:%d", n, $1}')
# Rewrite through a fresh temp file instead of >>: the target is in a directory
# the web user controls, so appending could follow a planted symlink. Keep the
# same 35-line window (34 old lines + the new one).
HIST_TMP=$(mktemp "$HOME_DIR/.metrics_history.XXXXXX") || {
  echo "collector: cannot create temporary metrics history in $HOME_DIR" >&2
  exit 1
}
exec 8>"$HIST_TMP" || {
  rm -f "$HIST_TMP"
  HIST_TMP=""
  echo "collector: cannot open temporary metrics history in $HOME_DIR" >&2
  exit 1
}
if {
  safe_tail "$HIST" 34
  echo "$(date +%H:%M) $L1 $L5 $L15 $CPU $RAM $DSK $IOW $WRK $RXK $TXK $MQ ${TOPP:--}"
} >&8; then
  publish_tmp "$HIST_TMP" "$HIST" && HIST_TMP=""
else
  exec 8>&-
  rm -f "$HIST_TMP"
  HIST_TMP=""
fi

# --- Disk growth history (ONE line per DAY: "YYYY-MM-DD used_gb") ---
# For capacity planning: the dashboard computes GB/week from the first and last
# point and estimates the time remaining to 80%. Not rewritten on the same day;
# ~1 year = 365 lines.
DHIST="$HOME_DIR/.disk_history"
DTODAY=$(date +%F)
if [ "$(safe_tail "$DHIST" 1 | awk '{print $1}')" != "$DTODAY" ]; then
  DUSED=$(df -P / | awk 'NR==2{printf "%d", $3/1048576}')  # used GB
  # Same symlink-safe rewrite as the metrics history; 399 old + 1 new = 400.
  DHIST_TMP=$(mktemp "$HOME_DIR/.disk_history.XXXXXX") || {
    echo "collector: cannot create temporary disk history in $HOME_DIR" >&2
    exit 1
  }
  exec 8>"$DHIST_TMP" || {
    rm -f "$DHIST_TMP"
    DHIST_TMP=""
    echo "collector: cannot open temporary disk history in $HOME_DIR" >&2
    exit 1
  }
  if {
    safe_tail "$DHIST" 399
    echo "$DTODAY $DUSED"
  } >&8; then
    publish_tmp "$DHIST_TMP" "$DHIST" && DHIST_TMP=""
  else
    exec 8>&-
    rm -f "$DHIST_TMP"
    DHIST_TMP=""
  fi
fi
