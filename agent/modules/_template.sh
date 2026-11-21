#!/bin/bash
# System module template — copy to modules/<name>.sh and fill in the three
# subcommands below.
#
# Contract (see agent/modules/README.md):
#   <name>.sh detect   -> exit 0 when this module is applicable, 1 otherwise,
#                         >1 on error (the core ignores this module).
#   <name>.sh mode     -> print "inline" or "external" (default inline).
#   <name>.sh collect  -> emit typed metric lines on stdout:
#                         key<TAB>type<TAB>value  (type: n | b | s)
#                         Every key must start with "<name>.".
#
# The core scans modules/*.sh in sorted order and skips files named _*, so this
# template is never executed as-is. It detects nothing and emits nothing.

set -u

case ${1:-} in
  detect)
    # Replace with a real presence check, e.g.:
    #   command -v some-cli >/dev/null 2>&1 || exit 1
    exit 1
    ;;
  mode)
    printf 'inline\n'
    ;;
  collect)
    # Emit typed metric lines here, e.g.:
    #   printf 'name.value\tn\t%s\n' "$(some-cli ... 2>/dev/null)"
    exit 0
    ;;
  *)
    exit 2
    ;;
esac
