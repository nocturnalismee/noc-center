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
#
# The backup is first written to a unique hidden partial file and then renamed
# into place, so a crashed run never leaves a timestamped file that looks
# valid. Two runs in the same second still get distinct names.

set -euo pipefail

# Directories are 750 and files 640; the command re-asserts 0640 on the copy.
umask 027

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
ORIGINAL_PWD="$(pwd -P)"

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

die() {
    echo "backup: $*" >&2
    exit 1
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
            [[ $# -ge 2 && -n "$2" ]] || die "--dest needs a non-empty value"
            BACKUP_DIR="$2"
            shift 2
            ;;
        --dest=*)
            BACKUP_DIR="${1#*=}"
            [[ -n "$BACKUP_DIR" ]] || die "--dest needs a non-empty value"
            shift
            ;;
        --retention-days)
            [[ $# -ge 2 ]] || die "--retention-days needs a value"
            RETENTION_DAYS="$2"
            shift 2
            ;;
        --retention-days=*)
            RETENTION_DAYS="${1#*=}"
            shift
            ;;
        --retention-count)
            [[ $# -ge 2 ]] || die "--retention-count needs a value"
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

command -v "$PHP_BIN" >/dev/null 2>&1 || die "PHP binary '$PHP_BIN' not found (set PHP_BIN)"

# Resolve a relative destination against the caller's directory, not APP_DIR
# (the script changes directory before invoking Artisan). A symlinked
# destination is refused so the backup cannot be redirected off-site or into
# the public tree by a planted link.
case "$BACKUP_DIR" in
    /*) ;;
    *) BACKUP_DIR="$ORIGINAL_PWD/$BACKUP_DIR" ;;
esac

[[ -L "$BACKUP_DIR" ]] && die "destination is a symlink: $BACKUP_DIR"

mkdir -p -- "$BACKUP_DIR"

[[ -L "$BACKUP_DIR" ]] && die "destination is a symlink: $BACKUP_DIR"

BACKUP_DIR="$(cd -- "$BACKUP_DIR" && pwd -P)"
chmod 750 -- "$BACKUP_DIR" 2>/dev/null || true

timestamp="$(date -u +%Y%m%dT%H%M%SZ)"
suffix="$(date -u +%N 2>/dev/null || true)"
[[ -n "$suffix" ]] || suffix="$$"
suffix="$suffix-$$-$RANDOM"

target="$BACKUP_DIR/noc-$timestamp-$suffix.sqlite"
partial="$BACKUP_DIR/.noc-$timestamp-$suffix.partial.sqlite"

if [[ -e "$target" || -L "$target" ]]; then
    die "destination already exists: $target"
fi

cleanup_partial() {
    if [[ -e "$partial" ]]; then
        rm -f -- "$partial"
    fi
}
trap cleanup_partial EXIT

cd -- "$APP_DIR"
"$PHP_BIN" artisan noc:backup --path="$partial"

# The command wrote and verified the partial copy. Rename atomically; this is
# the only step that exposes a file with the final timestamped name.
mv -f -- "$partial" "$target"
chmod 640 -- "$target" 2>/dev/null || true
trap - EXIT

echo "backup: complete: $target"

# Retention only considers regular, non-hidden timestamped backups. Partial
# files and symlinks are never deleted or counted.
mapfile -d '' backups < <(
    find "$BACKUP_DIR" -maxdepth 1 -type f ! -type l ! -name '.*' -name 'noc-*.sqlite' -print0 | sort -z
)

if [[ "${#backups[@]}" -eq 0 ]]; then
    exit 0
fi

if [[ "$RETENTION_DAYS" -gt 0 ]]; then
    cutoff="$(date -u -d "$RETENTION_DAYS days ago" +%s 2>/dev/null || true)"

    if [[ -z "$cutoff" ]]; then
        echo "backup: cannot calculate the retention cutoff; skipping the age rule" >&2
    else
        for file in "${backups[@]}"; do
            [[ -f "$file" && ! -L "$file" ]] || continue
            modified="$(date -u -r "$file" +%s 2>/dev/null || true)"
            [[ -n "$modified" ]] || continue

            if [[ "$modified" -lt "$cutoff" ]]; then
                rm -f -- "$file"
                echo "backup: removed expired $file"
            fi
        done
    fi
fi

# Refill the list, newest first (the timestamp/suffix sorts lexicographically).
mapfile -d '' backups < <(
    find "$BACKUP_DIR" -maxdepth 1 -type f ! -type l ! -name '.*' -name 'noc-*.sqlite' -print0 | sort -zr
)

if [[ "$RETENTION_COUNT" -gt 0 && "${#backups[@]}" -gt "$RETENTION_COUNT" ]]; then
    for ((index = RETENTION_COUNT; index < ${#backups[@]}; index++)); do
        [[ -f "${backups[$index]}" && ! -L "${backups[$index]}" ]] || continue
        rm -f -- "${backups[$index]}"
        echo "backup: removed over-count ${backups[$index]}"
    done
fi

# A SIGKILL can leave a hidden partial. It is never a valid backup and is
# excluded from retention, so clear old ones without touching a concurrent run.
find "$BACKUP_DIR" -maxdepth 1 -type f ! -type l -name '.noc-*.partial.sqlite' -mtime +1 -delete 2>/dev/null || true
