#!/usr/bin/env bash
#
# Deploy one uploaded release directory on shared hosting (no sudo, no Node).
#
# Layout the script expects/creates:
#
#   $APP_ROOT/
#     releases/<timestamp>/     uploaded application release
#     shared/.env               persistent environment file (symlinked in)
#     shared/storage/           optional persistent storage (documented)
#     current -> releases/<ts>  atomic symlink
#
# The release is the directory two levels above this script (the dashboard
# root). Point the document root at `$APP_ROOT/current/public`.
#
# Usage:
#   ops/shared-hosting/deploy.sh [--app-root DIR] [--release-dir DIR]
#
# Environment fallbacks: NOC_APP_ROOT (default: $HOME/noc), NOC_RELEASE_DIR,
# PHP_BIN (default: php), COMPOSER_BIN (default: composer).
#
# The script refuses to continue if PHP is older than 8.3 or if
# `public/build/manifest.json` is missing. It applies `--isolated` migrations
# and then rebuilds the config/route/view caches before switching `current`, so
# a failed migration or cache build leaves the previous release live.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
DEFAULT_RELEASE_DIR="$(cd "$SCRIPT_DIR/../.." && pwd)"

APP_ROOT="${NOC_APP_ROOT:-$HOME/noc}"
RELEASE_DIR="${NOC_RELEASE_DIR:-$DEFAULT_RELEASE_DIR}"
PHP_BIN="${PHP_BIN:-php}"
COMPOSER_BIN="${COMPOSER_BIN:-composer}"

usage() {
    cat <<'EOF'
Usage: ops/shared-hosting/deploy.sh [options]

  --app-root DIR      Deployment root containing releases/, shared/, current
                      (default: $HOME/noc, or NOC_APP_ROOT)
  --release-dir DIR   Application release to activate
                      (default: the dashboard root containing this script)
  -h, --help          Show this help

Environment fallbacks: NOC_APP_ROOT, NOC_RELEASE_DIR, PHP_BIN, COMPOSER_BIN.
EOF
}

die() {
    echo "deploy: $*" >&2
    exit 1
}

while [[ $# -gt 0 ]]; do
    case "$1" in
        --app-root)
            [[ $# -ge 2 ]] || die "--app-root needs a value"
            APP_ROOT="$2"
            shift 2
            ;;
        --app-root=*)
            APP_ROOT="${1#*=}"
            shift
            ;;
        --release-dir)
            [[ $# -ge 2 ]] || die "--release-dir needs a value"
            RELEASE_DIR="$2"
            shift 2
            ;;
        --release-dir=*)
            RELEASE_DIR="${1#*=}"
            shift
            ;;
        -h|--help)
            usage
            exit 0
            ;;
        *)
            die "unknown argument '$1' (try --help)"
            ;;
    esac
done

[[ -n "$APP_ROOT" ]] || die "--app-root must not be empty"
mkdir -p -- "$APP_ROOT"
APP_ROOT="$(cd -- "$APP_ROOT" && pwd)"

[[ -d "$RELEASE_DIR" ]] || die "release directory does not exist: $RELEASE_DIR"
RELEASE_DIR="$(cd -- "$RELEASE_DIR" && pwd)"

command -v "$PHP_BIN" >/dev/null 2>&1 || die "PHP binary '$PHP_BIN' not found (set PHP_BIN)"

php_version="$("$PHP_BIN" -r 'echo PHP_VERSION;')"
if ! "$PHP_BIN" -r 'exit(PHP_VERSION_ID >= 80300 ? 0 : 1);'; then
    die "PHP 8.3 or newer is required; '$PHP_BIN' reports $php_version"
fi

[[ -f "$RELEASE_DIR/artisan" ]] || die "not an application release (artisan not found): $RELEASE_DIR"

[[ -f "$RELEASE_DIR/public/build/manifest.json" ]] || die \
    "public/build/manifest.json is missing; build assets locally or in CI and upload them (the server has no Node/npm)"

mkdir -p -- "$APP_ROOT/releases" "$APP_ROOT/shared"

# A persistent .env is preferred so releases never carry credentials. If the
# release was uploaded with its own .env, it is used as-is.
if [[ -f "$APP_ROOT/shared/.env" ]]; then
    ln -sfn -- "$APP_ROOT/shared/.env" "$RELEASE_DIR/.env"
elif [[ ! -f "$RELEASE_DIR/.env" ]]; then
    die "no .env found; create $APP_ROOT/shared/.env (chmod 600) or include one in the release"
fi

# Writable directories required by Laravel even before the first request.
mkdir -p -- \
    "$RELEASE_DIR/storage/app/public" \
    "$RELEASE_DIR/storage/framework/cache/data" \
    "$RELEASE_DIR/storage/framework/sessions" \
    "$RELEASE_DIR/storage/framework/views" \
    "$RELEASE_DIR/storage/logs"

if [[ ! -f "$RELEASE_DIR/vendor/autoload.php" ]]; then
    command -v "$COMPOSER_BIN" >/dev/null 2>&1 || die \
        "vendor/ is missing and composer is not installed; upload vendor/ from CI or install composer"
    (cd -- "$RELEASE_DIR" && "$COMPOSER_BIN" install --no-dev --prefer-dist --no-interaction --optimize-autoloader)
fi

cd -- "$RELEASE_DIR"

echo "deploy: PHP $php_version; release $RELEASE_DIR"
echo "deploy: running migrations"
"$PHP_BIN" artisan migrate --force --isolated

echo "deploy: caching config, routes, and views"
"$PHP_BIN" artisan config:cache
"$PHP_BIN" artisan route:cache
"$PHP_BIN" artisan view:cache

# Atomically point `current` at the new release: create the new link beside it
# and rename over the old one, so no request can observe a missing `current`.
current_tmp="$APP_ROOT/.current.$$"
ln -sfn -- "$RELEASE_DIR" "$current_tmp"
mv -Tf -- "$current_tmp" "$APP_ROOT/current"

# Best-effort for a long-running VPS worker; cron-driven workers pick up the
# release on their next tick anyway.
"$PHP_BIN" artisan queue:restart >/dev/null 2>&1 || true

echo "deploy: current -> $RELEASE_DIR"
echo "deploy: done"
