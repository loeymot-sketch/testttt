#!/usr/bin/env bash
# scripts/check-active-cycle.sh
# Item B06 — vérifie qu'au plus un cycle est IN_PROGRESS dans .cursor/ACTIVE_CYCLE.md

set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ACTIVE="$REPO_ROOT/.cursor/ACTIVE_CYCLE.md"

if [[ ! -f "$ACTIVE" ]]; then
  echo "[check-active-cycle] INFO: fichier .cursor/ACTIVE_CYCLE.md absent"
  exit 0
fi

inprog=()
while IFS= read -r line; do
  inprog+=("$line")
done < <(grep -E '^## .*(IN_PROGRESS|IN PROGRESS)' "$ACTIVE" || true)
n=${#inprog[@]}

if [[ "$n" -gt 1 ]]; then
  echo "[check-active-cycle] WARN: $n cycles IN_PROGRESS simultanés — un seul cycle PRIMARY autorisé (item B03 méga-checklist)."
  echo "En-têtes concernés :"
  for line in "${inprog[@]}"; do
    echo "  $line"
  done
elif [[ "$n" -eq 1 ]]; then
  echo "[check-active-cycle] OK: 1 cycle IN_PROGRESS"
  echo "  ${inprog[0]}"
else
  echo "[check-active-cycle] INFO: aucun cycle IN_PROGRESS — état idle ou tous CLOSED"
fi

exit 0
