#!/bin/bash
# Imunify360 scan-status module for the NOC agent.
#
# Contract (see agent/modules/README.md):
#   imunify.sh detect   -> exit 0 when imunify360-agent and python3 are usable,
#                          1 otherwise (module skipped, core unaffected)
#   imunify.sh mode     -> print "external"
#   imunify.sh collect  -> emit typed metric lines on stdout (key<TAB>type<TAB>value)
#
# This is an external module: the core never runs it on the main collection
# run. A separate systemd timer (Task 11) runs `collector.sh --module imunify`
# every 5 minutes; the core validates the output and atomically writes the
# cache. This script only reads Imunify's own scan records and prints metrics —
# it never writes the cache itself and never writes to system files.
#
# Read-only by construction: the only external command is
# `imunify360-agent malware on-demand list --json` (a read-only status listing)
# wrapped in `timeout`. JSON is parsed by a small embedded python3 program that
# only reads stdin and prints one sanitized line; it never executes or evals
# parsed content. python3 is an optional dependency used here only (the spec
# allows it for the Imunify parser); without it the module is skipped.
#
# When the CLI or the parser fails, collect exits 1 so the core preserves the
# previous cache (exit 4). A successful "no running scan" response still emits
# the full field set, with zero values: imunify.scanning 0, imunify.age_s 0,
# imunify.files 0 and imunify.target "none" (an empty string would be dropped).
# RAID is a non-goal.

set -u
export LC_ALL=C

# shellcheck source=../lib/metrics.sh
source "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/../lib/metrics.sh" || exit 2

# _parse_json -> read JSON on stdin and print one line "age files target" when a
# scan is running, nothing when none is, and exit 1 on invalid JSON.
# Mirrors the legacy parser: the top level may be a list or an object with an
# "items" or "data" list; status comes from "scan_status" then "status" and only
# "running" counts; "started" may be an integer or a numeric string. The
# representative scan is the running one with the most total_resources/total;
# its target is /home/<account> -> <account>, otherwise "incremental".
# Target is truncated to 20 characters (spec limit).
_parse_json() {
  timeout 5 python3 -c '
import json, re, sys, time

try:
    d = json.load(sys.stdin)
    items = d if isinstance(d, list) else (d.get("items") or d.get("data") or [])
    if not isinstance(items, list):
        items = []

    def ts(x):
        try:
            return float(x)
        except (TypeError, ValueError):
            return 0.0

    def num(x):
        try:
            return int(float(x))
        except (TypeError, ValueError):
            return 0

    scans = [s for s in items if isinstance(s, dict)]
    run = [s for s in scans
           if (s.get("scan_status") or s.get("status")) == "running"
           and ts(s.get("started")) > 0]
    if not run:
        sys.exit(0)
    top = max(run, key=lambda s: (num(s.get("total_resources") or s.get("total") or 0)))
    age = int(time.time() - min(ts(s.get("started")) for s in run))
    files = num(top.get("total_resources") or top.get("total") or 0)
    parts = [x for x in str(top.get("path") or "").split("/") if x]
    if len(parts) >= 2 and parts[0] == "home":
        target = "".join(c for c in parts[1] if c.isalnum() or c in "._-")[:20] or "incremental"
    else:
        target = "incremental"
    sys.stdout.write("%d %d %s\n" % (age, files, target))
except SystemExit:
    raise
except Exception:
    sys.exit(1)
' 2>/dev/null
}

case ${1:-} in
  detect)
    command -v imunify360-agent >/dev/null 2>&1 || exit 1
    command -v python3 >/dev/null 2>&1 || exit 1
    exit 0
    ;;
  mode)
    printf 'external\n'
    ;;
  collect)
    updated_at=$(date +%s 2>/dev/null)
    [[ $updated_at =~ ^[0-9]+$ ]] || updated_at=0

    json=$(timeout 10 imunify360-agent malware on-demand list --json 2>/dev/null)
    rc=$?
    if (( rc != 0 )) || [[ -z $json ]]; then
      exit 1
    fi
    parsed=$(printf '%s' "$json" | _parse_json)
    rc=$?
    if (( rc != 0 )); then
      exit 1
    fi

    _emit imunify.updated_at n "$updated_at"

    if [[ -z $parsed ]]; then
      _emit imunify.scanning b 0
      _emit imunify.age_s n 0
      _emit imunify.files n 0
      _emit imunify.target s none
      exit 0
    fi

    read -r age files target <<< "$parsed"
    [[ $age =~ ^[0-9]+$ ]] || age=0
    [[ $files =~ ^[0-9]+$ ]] || files=0
    target=$(_safe_str "$target")
    target=${target:0:20}

    _emit imunify.scanning b 1
    _emit imunify.age_s n "$age"
    _emit imunify.files n "$files"
    _emit imunify.target s "$target"

    exit 0
    ;;
  *)
    exit 2
    ;;
esac