#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
exec podman run --rm --userns=keep-id \
  -v "$ROOT:/app:Z" -w /app -e HOME=/tmp \
  localhost/hosters-php:8.4 "$@"
