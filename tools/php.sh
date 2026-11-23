#!/usr/bin/env bash
set -euo pipefail

# Prefer a host toolchain that satisfies the project's PHP floor; otherwise
# fall back to the pinned Podman image (used by CI and older workstations).
native_php() {
  command -v php >/dev/null 2>&1 \
    && php -r 'exit(PHP_VERSION_ID >= 80300 ? 0 : 1);' >/dev/null 2>&1
}

if native_php; then
  case "${1:-}" in
    php)
      shift
      exec php "$@"
      ;;
    composer)
      if command -v composer >/dev/null 2>&1; then
        shift
        exec composer "$@"
      fi
      ;;
    *)
      exec "$@"
      ;;
  esac
fi

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
exec podman run --rm --userns=keep-id \
  -v "$ROOT:/app:Z" -w /app -e HOME=/tmp \
  localhost/hosters-php:8.4 "$@"
