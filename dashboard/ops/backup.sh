#!/usr/bin/env bash
#
# Create a timestamped, verified SQLite backup for the NOC dashboard and prune
# old copies. Run it from cron/systemd on the host (it calls the application's
# `noc:backup` command; it never prints credentials).
#
# Usage:
#   ops/backup.sh [--dest DIR] [--retention-days N] [--retention-count N]
#
# Values may also come from the environment:
#   NOC_BACKUP_DIR, NOC_BACKUP_RETENTION_DAYS, NOC_BACKUP_RETENTION_COUNT,
#   PHP_BIN (default: php).
#
# Retention is applied twice: `--retention-days` deletes copies older than N
# days, then `--retention-count` keeps only the newest N copies. `0` disables
# that rule. Both default to 14.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"

PHP_BIN="${PHP_BIN:-php}"
BACKUP_DIR="${NOC_BACKUP_DIR:-$HOME/noc-backups}"
RETENTION_DAYS="${NOC_BACKUP_RETENTION_DAYS:-14}"
RETENTION_COUNT="${NOC_BACKUP_RETENTION_COUNT:-14}"

usage() {
    cat <<'EOF'
Usage: ops/backup.sh [options]

  --dest DIR               Destination directory (default: $HOME/noc-backups)
  --retention-days N       Delete backups older than N days (0 disables)
  --retention-count N      Keep only the newest N backups (0 disables)
  -h, --help               Show this help

Environment fallbacks: NOC_BACKUP_DIR, NOC_BACKUP_RETENTION_DAYS,
NOC_BACKUP_RETENTION_COUNT, PHP_BIN.
EOF
}

require_integer() {
    local name="$1"
    local value="$2"

    if [[ ! "$value" =~ ^[0-9]+$ ]]; then
        echo "backup: $name must be a non-negative integer (got '$value')" >&2
        exit 2
    fi
}

while [[ $# -gt 0 ]]; do
    case "$1" in
        --dest)
            [[ $# -ge 2 ]] || { echo "backup: --dest needs a value" >&2; exit 2; }
            BACKUP_DIR="$2"
            shift 2
            ;;
        --dest=*)
            BACKUP_DIR="${1#*=}"
            shift
            ;;
        --retention-days)
            [[ $# -ge 2 ]] || { echo "backup: --retention-days needs a value" >&2; exit 2; }
            RETENTION_DAYS="$2"
            shift 2
            ;;
        --retention-days=*)
            RETENTION_DAYS="${1#*=}"
            shift
            ;;
        --retention-count)
            [[ $# -ge 2 ]] || { echo "backup: --retention-count needs a value" >&2; exit 2; }
            RETENTION_COUNT="$2"
            shift 2
            ;;
        --retention-count=*)
            RETENTION_COUNT="${1#*=}"
            shift
            ;;
        -h|--help)
            usage
            exit 0
            ;;
        *)
            echo "backup: unknown argument '$1'" >&2
            usage >&2
            exit 2
            ;;
    esac
done

require_integer "retention-days" "$RETENTION_DAYS"
require_integer "retention-count" "$RETENTION_COUNT"

command -v "$PHP_BIN" >/dev/null 2>&1 || {
    echo "backup: PHP binary '$PHP_BIN' not found (set PHP_BIN)" >&2
    exit 1
}

mkdir -p -- "$BACKUP_DIR"

timestamp="$(date -u +%Y%m%dT%H%M%SZ)"
target="$BACKUP_DIR/noc-$timestamp.sqlite"

if [[ -e "$target" || -L "$target" ]]; then
    echo "backup: destination already exists: $target" >&2
    exit 1
fi

cd -- "$APP_DIR"
"$PHP_BIN" artisan noc:backup --path="$target"

# Build the list once so the two retention rules cannot race each other.
mapfile -d '' backups < <(
    find "$BACKUP_DIR" -maxdepth 1 -type f -name 'noc-*.sqlite' -print0 | sort -z
)

if [[ "${#backups[@]}" -eq 0 ]]; then
    exit 0
fi

if [[ "$RETENTION_DAYS" -gt 0 ]]; then
    cutoff="$(date -u -d "$RETENTION_DAYS days ago" +%s 2>/dev/null || true)"

    if [[ -n "$cutoff" ]]; then
        for file in "${backups[@]}"; do
            [[ -f "$file" ]] || continue
            modified="$(date -u -r "$file" +%s 2>/dev/null || true)"
            [[ -n "$modified" ]] || continue

            if [[ "$modified" -lt "$cutoff" ]]; then
                rm -f -- "$file"
                echo "backup: removed expired $file"
            fi
        done
    fi
fi

# Refill the list, newest first (the timestamp suffix sorts lexicographically).
mapfile -d '' backups < <(
    find "$BACKUP_DIR" -maxdepth 1 -type f -name 'noc-*.sqlite' -print0 | sort -zr
)

if [[ "$RETENTION_COUNT" -gt 0 && "${#backups[@]}" -gt "$RETENTION_COUNT" ]]; then
    for ((index = RETENTION_COUNT; index < ${#backups[@]}; index++)); do
        rm -f -- "${backups[$index]}"
        echo "backup: removed over-count ${backups[$index]}"
    done
fi

echo "backup: complete: $target"
