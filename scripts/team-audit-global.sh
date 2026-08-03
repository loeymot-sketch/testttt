#!/usr/bin/env bash
# FoodKing — Audit global Claude (terminal) après que toutes les sous-tâches
# soient DONE/PASS. Wrapper sémantique sur foodking-claude-orchestrate.sh audit.
#
# Usage: bash scripts/team-audit-global.sh <TASK_ID>
#        npm run team:audit:global -- <TASK_ID>
#
# Pré-conditions :
#   - ACTIVE_CYCLE.TASK_ID = TASK_ID
#   - PHASE = AUDIT (ou VALIDATE → AUDIT)
#   - Plan : toutes les lignes SUBTASKS doivent être DONE
#   - Sinon : refus + indication des sous-tâches restantes
#
# Sortie : reports/audit/CLAUDE_AUDIT_${TASK_ID}_$(date).md
#          AUDIT_VERDICT: PASS | REWORK
#          puis reports/audit/GPT_FINAL_AUDIT_${TASK_ID}.md si Claude PASS
#
# Voir docs/orchestration/TEAM_WORKFLOW.md
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$REPO_ROOT"

TASK_ID="${1:-}"
if [[ -z "$TASK_ID" ]]; then
  echo "Usage: bash scripts/team-audit-global.sh <TASK_ID>"
  exit 2
fi

ACTIVE_CYCLE=".cursor/ACTIVE_CYCLE.md"
if [[ ! -f "$ACTIVE_CYCLE" ]]; then
  echo "[FAIL] ACTIVE_CYCLE absent."
  exit 3
fi
# shellcheck source=./_lib-active-cycle.sh
source "$REPO_ROOT/scripts/_lib-active-cycle.sh"
ACTIVE_TASK="$(read_active TASK_ID "$ACTIVE_CYCLE")"
ACTIVE_PHASE="$(read_active PHASE "$ACTIVE_CYCLE")"
PLAN_FILE="$(read_active PLAN_FILE "$ACTIVE_CYCLE")"

if [[ "$ACTIVE_TASK" != "$TASK_ID" ]]; then
  echo "[FAIL] TASK_ID demandé ≠ ACTIVE_CYCLE."
  exit 3
fi
if [[ "$ACTIVE_PHASE" != "AUDIT" && "$ACTIVE_PHASE" != "VALIDATE" ]]; then
  echo "[WARN] PHASE=$ACTIVE_PHASE — audit global attendu en PHASE=AUDIT."
  echo "       Continuer quand même ? export TEAM_AUDIT_GLOBAL_FORCE=1 pour outrepasser."
  if [[ "${TEAM_AUDIT_GLOBAL_FORCE:-0}" != "1" ]]; then
    exit 3
  fi
fi

# --- Vérif : toutes sous-tâches DONE ? --------------------------------------
if [[ -n "$PLAN_FILE" && -f "$PLAN_FILE" ]] && grep -q '^## SUBTASKS' "$PLAN_FILE"; then
  pending="$(awk '/^## SUBTASKS/,/^## /' "$PLAN_FILE" \
    | grep -E '^\|' | grep -v '^|---' | grep -v '^| SUBTASK_ID' \
    | grep -vE 'DONE[[:space:]]*\|' | grep -E "^\|[[:space:]]*${TASK_ID}-S" || true)"
  if [[ -n "$pending" ]]; then
    echo "[FAIL] Des sous-tâches ne sont pas DONE :"
    echo "$pending" | awk -F'|' '{ gsub(/^[[:space:]]+|[[:space:]]+$/,"",$2); gsub(/^[[:space:]]+|[[:space:]]+$/,"",$8); printf "   %s : %s\n", $2, $8 }'
    echo "[hint] npm run team:status — voir prochaine action."
    exit 4
  fi
fi

# --- Lancement audit global Claude terminal ---------------------------------
WRAPPER="scripts/foodking-claude-orchestrate.sh"
if [[ ! -x "$WRAPPER" ]]; then
  echo "[FAIL] $WRAPPER manquant ou non exécutable."
  exit 5
fi

echo "[team:audit:global] lancement $WRAPPER audit-brief (puis audit) …"
"$WRAPPER" context || true
"$WRAPPER" audit-brief || true

# Le script foodking-claude-orchestrate écrit déjà sous reports/audit/.
# On ajoute un alias clair pour ce cycle.
LAST_AUDIT="$(ls -1t reports/audit/CLAUDE_*AUDIT_*.md reports/audit/CLAUDE_AUDIT_*.md 2>/dev/null | head -1 || true)"
if [[ -n "$LAST_AUDIT" ]]; then
  echo "[ok] audit global produit : $LAST_AUDIT"
  verdict="$(grep -E '^AUDIT_VERDICT:' "$LAST_AUDIT" | tail -1 | sed 's/^AUDIT_VERDICT:[[:space:]]*//' | tr -d '[:space:]' || true)"
  case "$verdict" in
    PASS)
      echo "[verdict] AUDIT_VERDICT: PASS — lancement GPT_FINAL_AUDIT obligatoire."
      npm run codex:final-audit -- "$TASK_ID"
      echo "          → close seulement si GPT_FINAL_AUDIT_VERDICT: PASS."
      ;;
    REWORK)
      echo "[verdict] AUDIT_VERDICT: REWORK — réinjecter sous-tâches dans le plan."
      echo "          → REMEDIATION_AUDIT_CYCLE incrémenté (max 5 globaux)."
      ;;
    "")
      echo "[verdict] non détecté — relire $LAST_AUDIT manuellement."
      ;;
    *)
      echo "[verdict] $verdict (inattendu)."
      ;;
  esac
else
  echo "[WARN] aucun fichier d'audit récent détecté sous reports/audit/."
fi
