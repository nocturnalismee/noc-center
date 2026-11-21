#!/bin/bash
set -euo pipefail
cd "$(dirname "$0")/.."
bash tools/tests/json_test.sh
echo "test-agent: PASS"
