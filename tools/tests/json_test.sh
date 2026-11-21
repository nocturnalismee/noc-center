#!/bin/bash
set -euo pipefail
cd "$(dirname "$0")/../.."
source agent/lib/json.sh
fail() { echo "FAIL: $*" >&2; exit 1; }

[ "$(json_escape 'a"b\c')" = 'a\"b\\c' ] || fail "escape"
[ "$(json_string 'hi')" = '"hi"' ] || fail "string"
[ "$(json_number '12.5')" = '12.5' ] || fail "number"
[ "$(json_number 'bad')" = '0' ] || fail "bad number"
[ "$(json_bool 1)" = 'true' ] || fail "bool true"
[ "$(json_bool 0)" = 'false' ] || fail "bool false"

tmp=$(mktemp)
printf 'a\tn\t42\nb\ts\thi "there"\nc\tb\t1\n' > "$tmp"
[ "$(json_object_entries "$tmp")" = '"a":42,"b":"hi \"there\"","c":true' ] \
  || fail "entries"

printf 'a\tn\t1\na\tn\t2\nbad\tx\t1\nd\tn\t3\textra\n' > "$tmp"
[ "$(json_object_entries "$tmp")" = '"a":1' ] || fail "dedupe/invalid"

printf 'mod.x\tn\t1\nother.y\tn\t2\n' > "$tmp"
[ "$(json_object_entries "$tmp" 'mod.')" = '"mod.x":1' ] || fail "prefix"
rm -f "$tmp"
echo "json_test: PASS"
