#!/bin/bash
# Panel plugin template — copy to panels/<name>.sh and fill in detect/collect.
#
# Contract (see agent/panels/README.md):
#   <name>.sh detect   -> exit 0 when this panel is present, 1 otherwise,
#                         >1 on error (the core ignores this plugin).
#   <name>.sh collect  -> emit typed metric lines on stdout:
#                         key<TAB>type<TAB>value  (type: n | b | s)
#
# The core scans panels/*.sh in sorted order and skips files named _*, so this
# template is never executed as-is. It detects nothing and emits nothing.

set -u

case ${1:-} in
  detect)
    # Replace with a real presence check, e.g.:
    #   [ -x /usr/local/example/panel ] || exit 1
    exit 1
    ;;
  collect)
    # Emit typed metric lines here, e.g.:
    #   printf 'panel.version\ts\t%s\n' "$(cat /path/to/version 2>/dev/null)"
    exit 0
    ;;
  *)
    exit 2
    ;;
esac
