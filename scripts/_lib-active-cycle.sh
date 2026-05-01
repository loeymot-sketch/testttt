#!/usr/bin/env bash
# Helper sourçable : extrait une clé d'ACTIVE_CYCLE.md (formats supportés :
#   1) Table markdown : | **KEY** | `value` |   ou   | **KEY** | value |
#   2) Plain          : KEY: value
# Usage:
#   source scripts/_lib-active-cycle.sh
#   v="$(read_active TASK_ID .cursor/ACTIVE_CYCLE.md)"
read_active() {
  local key="$1" file="$2"
  [[ ! -f "$file" ]] && return 0
  local v
  v="$(awk -F'\\|' -v k="$key" '
    {
      for (i=1; i<=NF; i++) {
        cell=$i; gsub(/^[[:space:]]+|[[:space:]]+$/, "", cell)
        gsub(/\*\*/, "", cell)
        if (cell == k) {
          val=$(i+1); gsub(/^[[:space:]]+|[[:space:]]+$/, "", val)
          gsub(/`/, "", val)
          print val; exit
        }
      }
    }' "$file")"
  if [[ -z "$v" ]]; then
    v="$(awk -F': *' -v k="$key" '$1==k { print $2; exit }' "$file" | tr -d '\r')"
  fi
  printf "%s" "$v"
}
