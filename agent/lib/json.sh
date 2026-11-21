#!/bin/bash
# JSON encoding helpers for the NOC agent.
#
# Pure Bash built-ins only: these functions are sourced by the agent core and
# must not depend on external commands being present on the monitored host.
#
# Metric input format (one per line):
#   key<TAB>type<TAB>value
# where type is n (number), b (boolean 0/1), or s (string).

# Number of lines rejected by the last json_object_entries call (malformed
# lines and rejected duplicates).
JSON_INVALID_COUNT=0

# _json_is_number VALUE
# Schema v1 number syntax: no leading zeros, no exponent, no surrounding
# whitespace.
_json_is_number() {
  local LC_ALL=C value=${1-}
  [[ $value =~ ^-?(0|[1-9][0-9]*)(\.[0-9]+)?$ ]]
}

# json_escape VALUE -> escaped scalar without surrounding quotes.
json_escape() {
  local LC_ALL=C value=${1-} out='' ch i
  value=${value//\\/\\\\}
  value=${value//\"/\\\"}
  value=${value//$'\n'/\\n}
  value=${value//$'\r'/\\r}
  value=${value//$'\t'/\\t}
  value=${value//$'\b'/\\b}
  value=${value//$'\f'/\\f}
  # Encode any remaining control character as \u00XX.
  if [[ $value == *[[:cntrl:]]* ]]; then
    for ((i = 0; i < ${#value}; i++)); do
      ch=${value:i:1}
      if [[ $ch == [[:cntrl:]] ]]; then
        printf -v ch '\\u%04x' "'$ch"
      fi
      out+=$ch
    done
    value=$out
  fi
  printf '%s' "$value"
}

# json_string VALUE -> JSON string including quotes.
json_string() {
  local escaped
  escaped=$(json_escape "${1-}")
  printf '"%s"' "$escaped"
}

# json_number VALUE -> valid JSON number, or 0 when VALUE is not a number.
json_number() {
  local value=${1-}
  if _json_is_number "$value"; then
    printf '%s' "$value"
  else
    printf '0'
  fi
}

# json_bool VALUE -> true/false from 1/0.
json_bool() {
  if [[ ${1-} == 1 ]]; then
    printf 'true'
  else
    printf 'false'
  fi
}

# json_object_entries FILE [PREFIX]
# Prints comma-separated "key":value entries, with no trailing comma.
# Blank lines, lines starting with '#', and lines whose key does not start
# with PREFIX are skipped. Malformed lines and duplicate keys are rejected
# without aborting; the first value for a key is kept. At most 128 entries
# are emitted. Every rejected line increments JSON_INVALID_COUNT.
json_object_entries() {
  local LC_ALL=C
  local file=${1-} prefix=${2-}
  local line key type value entry rest tabs
  local count=0
  local out=''
  local -A seen=()
  local -a entries=()

  JSON_INVALID_COUNT=0

  if [[ -z $file || ! -r $file ]]; then
    JSON_INVALID_COUNT=1
    return 0
  fi

  while IFS= read -r line || [[ -n $line ]]; do
    line=${line%$'\r'}
    [[ -z $line ]] && continue
    [[ $line == '#'* ]] && continue

    # Require exactly two tabs: key, type and value, no extra fields.
    rest=$line
    tabs=0
    while [[ $rest == *$'\t'* ]]; do
      rest=${rest#*$'\t'}
      tabs=$((tabs + 1))
    done
    if (( tabs != 2 )); then
      JSON_INVALID_COUNT=$((JSON_INVALID_COUNT + 1))
      continue
    fi

    IFS=$'\t' read -r key type value <<< "$line"

    if [[ -n $prefix && $key != "$prefix"* ]]; then
      continue
    fi

    if [[ ! $key =~ ^[A-Za-z][A-Za-z0-9_.-]{0,63}$ ]]; then
      JSON_INVALID_COUNT=$((JSON_INVALID_COUNT + 1))
      continue
    fi

    if [[ -n ${seen[$key]+x} ]]; then
      JSON_INVALID_COUNT=$((JSON_INVALID_COUNT + 1))
      continue
    fi

    case $type in
      n)
        if ! _json_is_number "$value"; then
          JSON_INVALID_COUNT=$((JSON_INVALID_COUNT + 1))
          continue
        fi
        entry="\"$key\":$value"
        ;;
      b)
        if [[ $value != 0 && $value != 1 ]]; then
          JSON_INVALID_COUNT=$((JSON_INVALID_COUNT + 1))
          continue
        fi
        if [[ $value == 1 ]]; then
          entry="\"$key\":true"
        else
          entry="\"$key\":false"
        fi
        ;;
      s)
        if (( ${#value} > 1024 )); then
          JSON_INVALID_COUNT=$((JSON_INVALID_COUNT + 1))
          continue
        fi
        entry="\"$key\":$(json_string "$value")"
        ;;
      *)
        JSON_INVALID_COUNT=$((JSON_INVALID_COUNT + 1))
        continue
        ;;
    esac

    if (( count >= 128 )); then
      JSON_INVALID_COUNT=$((JSON_INVALID_COUNT + 1))
      continue
    fi

    seen[$key]=1
    entries+=("$entry")
    count=$((count + 1))
  done < "$file"

  for entry in "${entries[@]}"; do
    if [[ -n $out ]]; then
      out+=",$entry"
    else
      out=$entry
    fi
  done
  printf '%s' "$out"
}
