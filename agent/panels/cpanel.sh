#!/bin/bash
# cPanel panel plugin for the NOC agent.
#
# Contract (see agent/panels/README.md):
#   cpanel.sh detect   -> exit 0 when this host runs cPanel, 1 otherwise
#   cpanel.sh collect  -> emit typed metric lines on stdout (key<TAB>type<TAB>value)
#
# Read-only by construction: it only reads cPanel/system state and prints
# metrics to stdout. It never writes to system files, never restarts or
# reconfigures services, and every external command is wrapped in `timeout`.
# A missing/failed probe simply omits that metric; `collect` still exits 0.

set -u
export LC_ALL=C

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

case ${1:-} in
  detect)
    # cPanel is present when its main binary is executable.
    [[ -x /usr/local/cpanel/cpanel ]]
    ;;
  collect)
    # cpanel.version
    v=$(head -1 /usr/local/cpanel/version 2>/dev/null)
    [[ -n $v ]] && _emit cpanel.version s "$v"

    # cpanel.accounts (cPanel user files, excluding the system pseudo-user)
    n=$(find /var/cpanel/users -maxdepth 1 -type f ! -name system 2>/dev/null | wc -l)
    _emit cpanel.accounts n "$((n))"

    # cpanel.mailq (exim queue length)
    mq=$(timeout 5 exim -bpc 2>/dev/null)
    [[ $mq =~ ^[0-9]+$ ]] && _emit cpanel.mailq n "$mq"

    # cpanel.mysql_ping (boolean)
    if timeout 3 mysqladmin ping >/dev/null 2>&1; then
      _emit cpanel.mysql_ping b 1
    else
      _emit cpanel.mysql_ping b 0
    fi

    # cpanel.webserver (LiteSpeed if its daemon is running, else Apache)
    web=apache
    pgrep -x litespeed >/dev/null 2>&1 && web=litespeed
    _emit cpanel.webserver s "$web"

    # cpanel.cagefs
    cagefs=$(timeout 5 cagefsctl --cagefs-status 2>/dev/null | head -1)
    [[ -n $cagefs ]] && _emit cpanel.cagefs s "$cagefs"

    # security.csf_enabled (boolean; disabled by the presence of csf.disable)
    if [[ -d /etc/csf ]]; then
      if [[ -e /etc/csf/csf.disable ]]; then
        _emit security.csf_enabled b 0
      else
        _emit security.csf_enabled b 1
      fi
    fi

    # security.csf_testing (TESTING setting from csf.conf)
    testing=$(awk -F'"' '/^TESTING /{print $2; exit}' /etc/csf/csf.conf 2>/dev/null)
    [[ -n $testing ]] && _emit security.csf_testing s "$testing"

    # security.modsec (SecRuleEngine mode)
    modsec=$(awk '/^[[:space:]]*SecRuleEngine/{print $2; exit}' \
      /etc/apache2/conf.d/modsec/modsec2.cpanel.conf 2>/dev/null)
    [[ -n $modsec ]] && _emit security.modsec s "$modsec"

    # service.<name>.{installed,enabled,running} from whmapi1 servicestatus.
    # Service names are sanitized to [A-Za-z0-9_.-]; values coerced to 0/1.
    timeout 10 whmapi1 servicestatus 2>/dev/null | awk '
      $1 == "name:"      { n = $2 }
      $1 == "installed:" { i = $2 }
      $1 == "enabled:"   { e = $2 }
      $1 == "running:"   {
        r = $2
        if (n != "") {
          gsub(/[^A-Za-z0-9_.-]/, "_", n)
          if (n != "") {
            printf "service.%s.installed\tb\t%d\n", n, i + 0
            printf "service.%s.enabled\tb\t%d\n",   n, e + 0
            printf "service.%s.running\tb\t%d\n",   n, r + 0
          }
          n = ""
        }
      }
    '
    exit 0
    ;;
  *)
    exit 2
    ;;
esac
