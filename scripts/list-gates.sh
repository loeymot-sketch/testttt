#!/usr/bin/env bash
# scripts/list-gates.sh
# Item D01 — inventaire statut des fichiers docs/gates/*.md

set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$REPO_ROOT"

shopt -s nullglob
gates=(docs/gates/*.md)

open_files=()
closed_files=()
unknown_files=()

classify_file() {
  local file="$1"
  local base
  base=$(basename "$file")
  if grep -qiE 'APPROUVÉ|APPROVED|CLOSED|RESOLVED' "$file"; then
    closed_files+=("$base")
    return
  fi
  if grep -qiE 'PENDING|AWAITING|DRAFT' "$file" || grep -qiw 'OPEN' "$file"; then
    open_files+=("$base")
    return
  fi
  unknown_files+=("$base")
}

for g in "${gates[@]}"; do
  classify_file "$g"
done

print_joined() {
  local -a items=("$@")
  if ((${#items[@]} == 0)); then
    echo "(aucun)"
    return
  fi
  printf '%s ' "${items[@]}"
  echo
}

echo "GATES INVENTORY (docs/gates/) :"
echo -n "  OPEN    : ${#open_files[@]} → "
if ((${#open_files[@]} == 0)); then echo "(aucun)"; else print_joined "${open_files[@]}"; fi
echo -n "  CLOSED  : ${#closed_files[@]} → "
if ((${#closed_files[@]} == 0)); then echo "(aucun)"; else print_joined "${closed_files[@]}"; fi
echo -n "  UNKNOWN : ${#unknown_files[@]} → "
if ((${#unknown_files[@]} == 0)); then echo "(aucun)"; else print_joined "${unknown_files[@]}"; fi
echo "TOTAL   : ${#gates[@]}"

exit 0
