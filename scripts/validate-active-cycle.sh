#!/usr/bin/env bash
# FoodKing — Valide que .cursor/ACTIVE_CYCLE.md utilise une PHASE canonique (run-cycle.md).
# -----------------------------------------------------------------------------
# Usage :
#   bash scripts/validate-active-cycle.sh
#   VERIFY_ACTIVE_CYCLE_STRICT=1 bash scripts/validate-active-cycle.sh   # exit 1 si PHASE invalide
#
# Phases acceptées : PLAN | EXECUTE | VALIDATE | AUDIT | CLOSED | GATE | (none)
# Valeurs rejetées (strict) : IN_PROGRESS, vide ambigu, ou autre texte libre.
# -----------------------------------------------------------------------------
set -euo pipefail
REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ACTIVE="$REPO_ROOT/.cursor/ACTIVE_CYCLE.md"
STRICT="${VERIFY_ACTIVE_CYCLE_STRICT:-0}"

if [[ ! -f "$ACTIVE" ]]; then
  echo "validate-active-cycle: fichier absent : $ACTIVE" >&2
  [[ "$STRICT" == "1" ]] && exit 1 || exit 0
fi

# Extrait la valeur PHASE depuis le tableau markdown | **PHASE** | `VALUE` |
phase_raw=""
while IFS= read -r line; do
  if [[ "$line" =~ \*\*PHASE\*\* ]]; then
    # prend le contenu entre backticks ou après le dernier |
    if [[ "$line" =~ \`([^\`]+)\` ]]; then
      phase_raw="${BASH_REMATCH[1]}"
      # trim espaces et parenthèses explicatives
      phase_raw="${phase_raw%% (*}"
      phase_raw="${phase_raw%% *—*}"
      break
    fi
  fi
done < "$ACTIVE"

if [[ -z "$phase_raw" ]]; then
  echo "validate-active-cycle: PHASE non détectée dans le tableau — revoir le format | **PHASE** | \`...\` |"
  [[ "$STRICT" == "1" ]] && exit 1 || exit 0
fi

canonical=(PLAN EXECUTE VALIDATE AUDIT CLOSED GATE none)
ok=0
p_upper=$(echo "$phase_raw" | tr '[:lower:]' '[:upper:]')
for c in "${canonical[@]}"; do
  cu=$(echo "$c" | tr '[:lower:]' '[:upper:]')
  if [[ "$p_upper" == "$cu" ]]; then
    ok=1
    break
  fi
done

if [[ $ok -eq 1 ]]; then
  echo "validate-active-cycle: OK — PHASE=$phase_raw"
  exit 0
fi

echo "validate-active-cycle: WARN — PHASE non canonique : '$phase_raw' (attendu : PLAN, EXECUTE, VALIDATE, AUDIT, CLOSED, GATE, ou none)." >&2
echo "  Corriger .cursor/ACTIVE_CYCLE.md pour aligner run-cycle.md (éviter IN_PROGRESS ou libellés métier seuls)." >&2

if [[ "$STRICT" == "1" ]]; then
  exit 1
fi
exit 0
