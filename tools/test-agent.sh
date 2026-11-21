#!/bin/bash
set -euo pipefail
cd "$(dirname "$0")/.."
bash tools/tests/json_test.sh
bash tools/tests/common_test.sh
bash tools/tests/core_test.sh
bash tools/tests/panel_test.sh
echo "test-agent: PASS"
