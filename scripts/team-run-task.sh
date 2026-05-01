#!/usr/bin/env bash
# FoodKing — Team workflow : runner par sous-tâche.
# Usage:  bash scripts/team-run-task.sh <TASK_ID> <SUBTASK_ID>
#         npm run team:run -- <TASK_ID> <SUBTASK_ID>
#
# Pipeline (commande humaine idempotente) :
#   1. Vérifie ACTIVE_CYCLE / PHASE = EXECUTE
#   2. Lit la table SUBTASKS du plan, extrait : difficulté, owner planifié, invariants
#   3. agent-activity-log start (atomique flock) — refus si collision
#   4. Route :  toute sous-tâche produit → npm run codex:complex -- <TASK_ID>-<SUBTASK_ID>
#               fallback → foodking-complex-implementer si codex indisponible
#   5. Après exécution, exige GPT_SELF_AUDIT (si exécutant Codex)
#   6. Appelle team-audit-subtask.sh (mini-audit Claude)
#   7. Verdict PASS → marque DONE dans le plan + agent-activity-log done
#      Verdict REWORK_SUB → incrémente retry (max 3) → HUMAN_GATE au 3e
#
# Voir docs/orchestration/TEAM_WORKFLOW.md
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$REPO_ROOT"

TASK_ID="${1:-}"
SUBTASK_ID="${2:-}"

if [[ -z "$TASK_ID" || -z "$SUBTASK_ID" ]]; then
  echo "Usage: bash scripts/team-run-task.sh <TASK_ID> <SUBTASK_ID>"
  echo "Ex:    bash scripts/team-run-task.sh ORD-FIX-2026-04-25 ORD-FIX-2026-04-25-S03"
  exit 2
fi

ACTIVE_CYCLE=".cursor/ACTIVE_CYCLE.md"
ACTIVITY="bash scripts/agent-activity-log.sh"
AGENT="${TEAM_RUN_AGENT:-cursor-claude}"

# shellcheck source=./_lib-active-cycle.sh
source "$REPO_ROOT/scripts/_lib-active-cycle.sh"

# --- 1. Vérifications ACTIVE_CYCLE -------------------------------------------
if [[ ! -f "$ACTIVE_CYCLE" ]]; then
  echo "[FAIL] ACTIVE_CYCLE absent — démarrer un cycle (run-cycle <TASK_ID>) d'abord."
  exit 3
fi
ACTIVE_TASK="$(read_active TASK_ID "$ACTIVE_CYCLE")"
ACTIVE_PHASE="$(read_active PHASE "$ACTIVE_CYCLE")"
PLAN_FILE="$(read_active PLAN_FILE "$ACTIVE_CYCLE")"

if [[ "$ACTIVE_TASK" != "$TASK_ID" ]]; then
  echo "[FAIL] TASK_ID demandé ($TASK_ID) ≠ ACTIVE_CYCLE.TASK_ID ($ACTIVE_TASK)."
  exit 3
fi
if [[ "$ACTIVE_PHASE" != "EXECUTE" ]]; then
  echo "[FAIL] PHASE=$ACTIVE_PHASE — team:run requiert PHASE=EXECUTE."
  exit 3
fi
if [[ -z "$PLAN_FILE" || ! -f "$PLAN_FILE" ]]; then
  echo "[FAIL] PLAN_FILE introuvable ($PLAN_FILE)."
  exit 3
fi

# --- 2. Extraction de la ligne SUBTASK ---------------------------------------
ROW="$(awk '/^## SUBTASKS/,/^## /' "$PLAN_FILE" | grep -E "^\|[[:space:]]*${SUBTASK_ID}[[:space:]]*\|" | head -1 || true)"
if [[ -z "$ROW" ]]; then
  echo "[FAIL] SUBTASK_ID $SUBTASK_ID non trouvée dans la table SUBTASKS de $PLAN_FILE."
  exit 4
fi

# Colonnes attendues : | SUBTASK_ID | Description | Difficulty | Owner | Invariants | Mini-audit | Status | Retry |
DIFFICULTY="$(echo "$ROW" | awk -F'|' '{ gsub(/^[[:space:]]+|[[:space:]]+$/,"",$4); print $4 }')"
OWNER="$(echo "$ROW" | awk -F'|' '{ gsub(/^[[:space:]]+|[[:space:]]+$/,"",$5); print $5 }')"
INVARIANTS="$(echo "$ROW" | awk -F'|' '{ gsub(/^[[:space:]]+|[[:space:]]+$/,"",$6); print $6 }')"
MINIPOL="$(echo "$ROW" | awk -F'|' '{ gsub(/^[[:space:]]+|[[:space:]]+$/,"",$7); print $7 }')"
STATUS="$(echo "$ROW" | awk -F'|' '{ gsub(/^[[:space:]]+|[[:space:]]+$/,"",$8); print $8 }')"
RETRY="$(echo "$ROW" | awk -F'|' '{ gsub(/^[[:space:]]+|[[:space:]]+$/,"",$9); print $9 }')"

echo "[team:run] SUBTASK=$SUBTASK_ID  diff=$DIFFICULTY  owner=$OWNER  invariants=$INVARIANTS  policy=$MINIPOL  status=$STATUS  retry=$RETRY"

if [[ "$STATUS" == "DONE" ]]; then
  echo "[skip] sous-tâche déjà DONE."
  exit 0
fi

if [[ "$RETRY" =~ ^[0-9]+$ ]] && (( RETRY >= 3 )); then
  echo "[FAIL] RETRY=$RETRY ≥ 3 — HUMAN_GATE requis (créer docs/gates/GATE_${TASK_ID}_$(date +%F).md)."
  exit 5
fi

# --- 3. Réservation scope (atomique) -----------------------------------------
SCOPE_DIR="missions/${TASK_ID}/subtasks/${SUBTASK_ID}"
mkdir -p "$SCOPE_DIR"
RESERVE_NOTE="team:run ${SUBTASK_ID} (${DIFFICULTY})"

# Le scope strict est lu depuis missions/.../scope.txt si présent, sinon SCOPE_DIR
SCOPE=""
if [[ -f "$SCOPE_DIR/scope.txt" ]]; then
  SCOPE="$(tr '\n' ',' < "$SCOPE_DIR/scope.txt" | sed 's/,$//')"
else
  SCOPE="$SCOPE_DIR/"
fi

set +e
$ACTIVITY start "$AGENT" "$SUBTASK_ID" execute "$SCOPE" "$RESERVE_NOTE"
RC=$?
set -e
if [[ $RC -ne 0 ]]; then
  echo "[FAIL] activity_log start refusé (collision ou erreur). Voir 'npm run team:status'."
  exit 6
fi

# --- 4. Routage difficulté ---------------------------------------------------
case "$DIFFICULTY" in
  routine)
    echo "[route] difficulté=routine → cycles de finition: exécution GPT obligatoire :"
    echo "         npm run codex:prepare -- $SUBTASK_ID && npm run codex:complex -- $SUBTASK_ID"
    echo "         (Composer / foodking-routine-implementer = validation/report only)"
    echo
    echo "[next]   Lance l'exécution puis ré-exécute :"
    echo "         npm run team:audit:sub -- $TASK_ID $SUBTASK_ID"
    ;;
  complex)
    echo "[route] difficulté=complex → npm run codex:complex -- $SUBTASK_ID (PRIMARY)"
    echo
    echo "[next]   Préparer missions/$SUBTASK_ID/input.json puis :"
    echo "         npm run codex:prepare -- $SUBTASK_ID && npm run codex:complex -- $SUBTASK_ID"
    echo "         puis npm run team:audit:sub -- $TASK_ID $SUBTASK_ID"
    ;;
  *)
    echo "[FAIL] difficulté inconnue : '$DIFFICULTY' (attendu: routine|complex)"
    $ACTIVITY done "$AGENT" "$SUBTASK_ID" abandoned "difficulté inconnue" || true
    exit 7
    ;;
esac

echo
echo "[team:run] Réservation active. Pour libérer après PASS :"
echo "           bash scripts/agent-activity-log.sh done $AGENT $SUBTASK_ID done \"PASS mini-audit\""
echo
echo "Note: ce wrapper est volontairement non auto-chaîné (commande humaine idempotente)."
echo "      L'option --auto sera ajoutée après smoke test sur cycle réel d'application."
