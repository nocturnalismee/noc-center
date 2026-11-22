#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/.."

with_assets=false
args=()
for arg in "$@"; do
  if [[ "$arg" == "--with-assets" ]]; then
    with_assets=true
  else
    args+=("$arg")
  fi
done

./tools/php.sh ./vendor/bin/pint --test
./tools/php.sh php artisan test ${args[@]+"${args[@]}"}
if [[ "$with_assets" == true ]]; then npm run build; fi
