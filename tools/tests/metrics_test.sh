#!/bin/bash
set -euo pipefail
cd "$(dirname "$0")/../.."
source agent/lib/metrics.sh
fail() { echo "FAIL: $*" >&2; exit 1; }

# _safe_str: line-breaking and control bytes become single spaces.
[ "$(_safe_str $'a\nb\rc\td')" = 'a b c d' ] || fail "safe_str flattens whitespace"
[ "$(_safe_str $'x\001y')" = 'x y' ] || fail "safe_str flattens control byte"
[ "$(_safe_str 'plain text')" = 'plain text' ] || fail "safe_str keeps ordinary text"
[ "$(_safe_str '')" = '' ] || fail "safe_str empty"

# _safe_name: everything outside [A-Za-z0-9_.-] becomes underscore.
[ "$(_safe_name 'web 01/root')" = 'web_01_root' ] || fail "safe_name replaces space and slash"
[ "$(_safe_name 'A-Za-z0-9_.-')" = 'A-Za-z0-9_.-' ] || fail "safe_name preserves allowed set"
[ "$(_safe_name 'a@b:c')" = 'a_b_c' ] || fail "safe_name replaces other chars"

# _emit numbers: valid forms pass through verbatim.
[ "$(_emit metric.value n 12.5)" = $'metric.value\tn\t12.5' ] || fail "emit number 12.5"
[ "$(_emit metric.value n 0)" = $'metric.value\tn\t0' ] || fail "emit number 0"
[ "$(_emit metric.value n -3)" = $'metric.value\tn\t-3' ] || fail "emit negative number"
[ "$(_emit metric.value n -0.5)" = $'metric.value\tn\t-0.5' ] || fail "emit negative decimal"
[ "$(_emit metric.value n 0.1)" = $'metric.value\tn\t0.1' ] || fail "emit one-decimal number"

# _emit numbers: invalid forms are dropped.
[ -z "$(_emit metric.value n 01)" ] || fail "emit rejects leading zero"
[ -z "$(_emit metric.value n 1e3)" ] || fail "emit rejects exponent"
[ -z "$(_emit metric.value n ' 12')" ] || fail "emit rejects leading whitespace"
[ -z "$(_emit metric.value n '12 ')" ] || fail "emit rejects trailing whitespace"
[ -z "$(_emit metric.value n '')" ] || fail "emit rejects empty number"

# _emit booleans: only 0 and 1.
[ "$(_emit metric.flag b 1)" = $'metric.flag\tb\t1' ] || fail "emit boolean 1"
[ "$(_emit metric.flag b 0)" = $'metric.flag\tb\t0' ] || fail "emit boolean 0"
[ -z "$(_emit metric.flag b 2)" ] || fail "emit rejects boolean 2"
[ -z "$(_emit metric.flag b '')" ] || fail "emit rejects empty boolean"
[ -z "$(_emit metric.flag b true)" ] || fail "emit rejects 'true'"

# _emit strings: sanitized, and dropped when they become empty.
[ "$(_emit metric.name s $'Hello\tWorld')" = $'metric.name\ts\tHello World' ] \
  || fail "emit sanitizes string"
[ "$(_emit metric.name s $'a\nb')" = $'metric.name\ts\ta b' ] || fail "emit flattens newline"
# Newlines and tabs are flattened to spaces before the emptiness check, so a
# value made only of whitespace becomes spaces and is still emitted (the
# library only drops a value that is genuinely empty).
[ "$(_emit metric.name s $'\n\r\t')" = $'metric.name\ts\t   ' ] \
  || fail "emit emits whitespace-only string as spaces"
# Control bytes are flattened to spaces too, so such a value is also emitted.
[ "$(_emit metric.name s $'\001\002')" = $'metric.name\ts\t  ' ] \
  || fail "emit emits control-only string as spaces"
[ -z "$(_emit metric.name s '')" ] || fail "emit drops empty string"

# _emit keys: validated here (collector still enforces the prefix).
[ -z "$(_emit '' n 1)" ] || fail "emit rejects empty key"
[ -z "$(_emit 1metric n 1)" ] || fail "emit rejects digit-leading key"
[ -z "$(_emit .metric n 1)" ] || fail "emit rejects dot-leading key"
[ -z "$(_emit 'metric value' n 1)" ] || fail "emit rejects key with space"
key64=$(printf 'a%.0s' {1..64})
[ "$(_emit "$key64" n 1)" = "$key64"$'\tn\t1' ] || fail "emit accepts 64-char key"
key65=$(printf 'a%.0s' {1..65})
[ -z "$(_emit "$key65" n 1)" ] || fail "emit rejects 65-char key"

# _emit unknown type emits nothing.
[ -z "$(_emit metric.value x 1)" ] || fail "emit rejects unknown type"
[ -z "$(_emit metric.value '' 1)" ] || fail "emit rejects empty type"

# _emit output is exactly three tab-separated fields with a trailing newline.
line=$(_emit metric.value n 12.5; printf 'END')
[ "$line" = $'metric.value\tn\t12.5\nEND' ] || fail "emit trailing newline"
fields=$(_emit metric.value n 12.5 | awk -F'\t' '{print NF}')
[ "$fields" = 3 ] || fail "emit exactly three fields"

echo "metrics_test: PASS"
