#!/usr/bin/env bash
# FoodKing — Garde-fou plafond remédiation audit (auto-remediation.mdc : max 5 tours).
# -----------------------------------------------------------------------------
# Usage :
#   bash scripts/audit-guard.sh [REPORT_FILE]
#   REPORT_FILE défaut : reports/post_execute_latest.log
#
# Si REMEDIATION_AUDIT_CYCLE (ou variantes) > 5 sans gate ouvert sous docs/gates/, exit 1.
# Sinon exit 0.
# -----------------------------------------------------------------------------
set -euo pipefail
REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
RF="${1:-$REPO_ROOT/reports/post_execute_latest.log}"

if [[ ! -f "$RF" ]]; then
  echo "audit-guard: fichier absent — $RF (skip)"
  exit 0
fi

max_cycle=0
while IFS= read -r line; do
  if [[ "$line" =~ REMEDIATION_AUDIT_CYCLE[^0-9]*([0-9]+) ]]; then
    n="${BASH_REMATCH[1]}"
    (( n > max_cycle )) && max_cycle=$n
  fi
  if [[ "$line" =~ REMEDIATION_AUDIT_CYCLE:[[:space:]]*([0-9]+)/5 ]]; then
    n="${BASH_REMATCH[1]}"
    (( n > max_cycle )) && max_cycle=$n
  fi
done < "$RF"

if [[ $max_cycle -le 5 ]]; then
  echo "audit-guard: OK — REMEDIATION max observé = $max_cycle (plafond 5)"
  exit 0
fi

if ls "$REPO_ROOT/docs/gates"/GATE_* 2>/dev/null | head -1 | grep -q .; then
  echo "audit-guard: WARN — cycle remédiation > 5 mais docs/gates/ contient des fichiers ; vérifier gate humain ouvert." >&2
fi

echo "audit-guard: ÉCHEC — REMEDIATION_AUDIT_CYCLE dépasse le plafond (observé $max_cycle > 5). Ouvrir HUMAN_GATE ou réinitialiser le compteur avec décision tracée." >&2
exit 1
