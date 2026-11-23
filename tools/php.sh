#!/usr/bin/env bash
set -euo pipefail

# Prefer the host toolchain; fall back to the pinned Podman image for hosts
# without PHP (or CI machines that only have Podman).
if command -v php >/dev/null 2>&1; then
  case "${1:-}" in
    php)
      shift
      exec php "$@"
      ;;
    composer)
      shift
      exec composer "$@"
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
