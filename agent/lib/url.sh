#!/bin/bash
# Strict API URL validation shared by the runtime config check and the
# installer. Sourced, never executed directly.
#
#   validate_api_url URL ALLOW_INSECURE_HTTP
#     0 when URL is a strict https URL, or an exact loopback http URL and
#     ALLOW_INSECURE_HTTP is 1; 1 otherwise.
#   api_url_is_loopback_http URL
#     0 only for an exact, valid http://127.0.0.1|localhost URL.
#
# The validator is deliberately stricter than curl's URL parser. In particular
# it rejects userinfo ("@"), whitespace, unbracketed IPv6, malformed bracketed
# authorities, non-numeric/out-of-range ports, and hostnames that merely start
# with a loopback literal (127.0.0.1.evil.com, localhost.evil.com, localhost.).
# This matters because curl reads "http://127.0.0.1:80@evil.com/x" as the
# userinfo "127.0.0.1:80" at host evil.com, sending the bearer token there in
# cleartext although a loose prefix check would call the URL loopback.

# _api_url_host_ok HOST -> 0 for a DNS-style hostname, an IPv4 literal, or the
# exact bracketed loopback literal [::1]. A trailing dot and empty labels are
# rejected (so "localhost." is not loopback).
_api_url_host_ok() {
  local host=${1-}
  [[ $host == '[::1]' ]] && return 0
  [[ $host =~ ^[A-Za-z0-9]([A-Za-z0-9-]{0,61}[A-Za-z0-9])?(\.[A-Za-z0-9]([A-Za-z0-9-]{0,61}[A-Za-z0-9])?)*$ ]]
}

# _api_url_port_ok PORT -> 0 for a decimal TCP port in 1..65535.
_api_url_port_ok() {
  local port=${1-}
  [[ $port =~ ^[0-9]{1,5}$ ]] || return 1
  (( 10#$port >= 1 && 10#$port <= 65535 ))
}

# validate_api_url URL ALLOW_INSECURE_HTTP -> see the header comment.
validate_api_url() {
  local url=${1-} allow=${2-0}
  local scheme authority rest host port

  [[ -n $url ]] || return 1
  # "@" is userinfo to curl: the authority that decides the connection starts
  # after it. Whitespace terminates or corrupts the URL. Reject both anywhere.
  [[ $url == *@* ]] && return 1
  [[ $url == *[[:space:]]* ]] && return 1

  # scheme://authority[/path]; the authority ends at the first /, ? or #.
  [[ $url =~ ^(https?)://([^/?#[:space:]]+)(/[^[:space:]]*)?$ ]] || return 1
  scheme=${BASH_REMATCH[1]}
  authority=${BASH_REMATCH[2]}
  rest=${BASH_REMATCH[3]-}
  [[ -n $authority ]] || return 1
  [[ $rest == *@* ]] && return 1

  host=$authority
  port=""
  if [[ $authority == \[* ]]; then
    # Bracketed IPv6: only the exact loopback literal, with an optional port.
    [[ $authority =~ ^(\[[^]]+\])(:([0-9]+))?$ ]] || return 1
    host=${BASH_REMATCH[1]}
    port=${BASH_REMATCH[3]-}
    [[ $host == '[::1]' ]] || return 1
  elif [[ $authority == *:* ]]; then
    # Exactly one colon separates host and port; a second colon would be an
    # unbracketed IPv6 address, which is not accepted.
    [[ ${authority#*:} == *:* ]] && return 1
    host=${authority%%:*}
    port=${authority#*:}
  fi
  [[ -n $host ]] || return 1
  _api_url_host_ok "$host" || return 1
  [[ -n $port ]] && { _api_url_port_ok "$port" || return 1; }

  [[ $scheme == https ]] && return 0

  # Plain http is loopback-only and opt-in. The host must be exactly one of
  # the literals; no suffix, trailing dot, or bracketed form qualifies.
  [[ $allow == 1 ]] || return 1
  [[ $host == 127.0.0.1 || $host == localhost ]] || return 1
  return 0
}

# api_url_is_loopback_http URL -> 0 only for an exact loopback http URL, i.e.
# the forms for which the installer sets ALLOW_INSECURE_HTTP=1.
api_url_is_loopback_http() {
  local url=${1-}
  [[ $url == http://* ]] || return 1
  validate_api_url "$url" 1
}
