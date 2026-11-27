#!/bin/bash
# Shared metric-line protocol for the NOC agent.
#
# Sourced by panel plugins and system modules (never executed directly). Every
# helper only prints to stdout, so it is safe to source from any of them.
#
# A metric line is three tab-separated fields:
#   key<TAB>type<TAB>value
# where type is:
#   n  number, syntax ^-?(0|[1-9][0-9]*)(\.[0-9]+)?$ (no leading zeros,
#      no exponent, no surrounding whitespace)
#   b  boolean, exactly 0 or 1
#   s  string, sanitized to one printable line; an empty result is dropped
#
# Keys are validated here, but the collector still enforces the module/panel
# prefix and the 128-line/duplicate rules. Invalid input is dropped silently
# (no output, success): a plugin or module never fails because of one bad
# metric.

# _safe_str VALUE -> single-line printable string (control chars flattened).
_safe_str() {
  local v=${1-} i ch out=''
  v=${v//$'\n'/ }
  v=${v//$'\r'/ }
  v=${v//$'\t'/ }
  for ((i = 0; i < ${#v}; i++)); do
    ch=${v:i:1}
    [[ $ch == [[:cntrl:]] ]] && ch=' '
    out+=$ch
  done
  printf '%s' "$out"
}

# _safe_name VALUE -> service name sanitized to [A-Za-z0-9_.-].
_safe_name() {
  local v=${1-}
  v=${v//[^A-Za-z0-9_.-]/_}
  printf '%s' "$v"
}

# _emit KEY TYPE VALUE -> print one metric line when KEY and VALUE are valid.
_emit() {
  local key=${1-} type=${2-} value=${3-}
  [[ $key =~ ^[A-Za-z][A-Za-z0-9_.-]{0,63}$ ]] || return 0
  case $type in
    n)
      [[ $value =~ ^-?(0|[1-9][0-9]*)(\.[0-9]+)?$ ]] || return 0
      ;;
    b)
      [[ $value == 0 || $value == 1 ]] || return 0
      ;;
    s)
      value=$(_safe_str "$value")
      [[ -n $value ]] || return 0
      ;;
    *)
      return 0
      ;;
  esac
  printf '%s\t%s\t%s\n' "$key" "$type" "$value"
}
