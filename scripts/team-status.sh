#!/usr/bin/env bash
# FoodKing — Team workflow dashboard (lecture seule).
# Affiche : ACTIVE_CYCLE, SUBTASKS table, agents actifs, prochaine action éligible.
# N'écrit RIEN. Voir docs/orchestration/TEAM_WORKFLOW.md.
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$REPO_ROOT"

ACTIVE_CYCLE=".cursor/ACTIVE_CYCLE.md"
ACTIVITY_LOG="reports/AGENT_ACTIVITY_LOG.md"

bold() { printf "\033[1m%s\033[0m\n" "$1"; }
dim()  { printf "\033[2m%s\033[0m\n" "$1"; }
warn() { printf "\033[33m%s\033[0m\n" "$1"; }
ok()   { printf "\033[32m%s\033[0m\n" "$1"; }

# shellcheck source=./_lib-active-cycle.sh
source "$REPO_ROOT/scripts/_lib-active-cycle.sh"

bold "==[ TEAM STATUS ]=="
echo

# --- 1. ACTIVE_CYCLE summary -------------------------------------------------
bold "1. Active cycle (.cursor/ACTIVE_CYCLE.md)"
if [[ -f "$ACTIVE_CYCLE" ]]; then
  for k in TASK_ID PHASE RUNNER_MODE PRIMARY_EXECUTION_MODEL REASONING_EFFORT PRIMARY_MODEL PLAN_FILE REPORT_FILE GATE_FILE; do
    v="$(read_active "$k" "$ACTIVE_CYCLE")"
    [[ -n "$v" ]] && printf "   %-15s %s\n" "$k:" "$v"
  done
else
  warn "   (aucun ACTIVE_CYCLE.md trouvé)"
fi
echo

# --- 2. SUBTASKS table -------------------------------------------------------
bold "2. SUBTASKS (table extraite du plan actif)"
PLAN_FILE="$(read_active PLAN_FILE "$ACTIVE_CYCLE")"

if [[ -n "$PLAN_FILE" && -f "$PLAN_FILE" ]]; then
  echo "   Plan: $PLAN_FILE"
  echo
  if grep -q '^## SUBTASKS' "$PLAN_FILE"; then
    awk '/^## SUBTASKS/,/^## /{ if (!/^## /) print }' "$PLAN_FILE" \
      | grep -E '^\|.*\|.*\|' \
      | sed 's/^/   /'
  else
    dim "   (aucune section ## SUBTASKS dans ce plan — workflow monolithique)"
  fi
else
  dim "   (pas de plan actif référencé)"
fi
echo

# --- 3. Agents actifs (réservations en cours) --------------------------------
bold "3. Agents actifs (reports/AGENT_ACTIVITY_LOG.md — réservations non libérées)"
if [[ -f "$ACTIVITY_LOG" && -x "scripts/agent-activity-log.sh" ]]; then
  bash scripts/agent-activity-log.sh active 2>/dev/null | sed 's/^/   /' || true
else
  dim "   (log indisponible)"
fi
echo

# --- 4. Dernier mouvement (10 dernières lignes du log) -----------------------
bold "4. Derniers mouvements (tail 10 du log d'activité)"
if [[ -f "$ACTIVITY_LOG" ]]; then
  bash scripts/agent-activity-log.sh tail 10 2>/dev/null | sed 's/^/   /' || true
else
  dim "   (log indisponible)"
fi
echo

# --- 5. Audits trouvés pour le cycle actif -----------------------------------
bold "5. Audits trouvés (reports/audit/ filtrés par TASK_ID)"
TASK_ID="$(read_active TASK_ID "$ACTIVE_CYCLE")"
if [[ -n "$TASK_ID" && -d "reports/audit" ]]; then
  found=0
  for prefix in GPT_SELF_AUDIT CLAUDE_MINI_AUDIT CLAUDE_AUDIT; do
    while IFS= read -r f; do
      [[ -z "$f" ]] && continue
      printf "   %-72s %s\n" "$f" "$(stat -f '%Sm' -t '%Y-%m-%d %H:%M' "$f" 2>/dev/null || stat -c '%y' "$f" 2>/dev/null | cut -d. -f1)"
      found=1
    done < <(find reports/audit -maxdepth 2 -type f -name "${prefix}_${TASK_ID}*" 2>/dev/null | sort)
  done
  [[ "$found" == "0" ]] && dim "   (aucun audit trouvé pour $TASK_ID)"
else
  dim "   (pas de TASK_ID actif)"
fi
echo

# --- 6. Prochaine action suggérée -------------------------------------------
bold "6. Prochaine action suggérée"
if [[ -z "$TASK_ID" ]]; then
  dim "   Aucun cycle actif. Démarrer : run-cycle <TASK_ID>"
elif [[ -n "$PLAN_FILE" && -f "$PLAN_FILE" ]] && grep -q '^## SUBTASKS' "$PLAN_FILE"; then
  next_todo="$(awk '/^## SUBTASKS/,/^## /' "$PLAN_FILE" \
    | grep -E '^\|' | grep -v '^|---' | grep -v '^| SUBTASK_ID' \
    | grep -E 'TODO[[:space:]]*\|' | head -1 \
    | awk -F'|' '{ gsub(/^[[:space:]]+|[[:space:]]+$/,"",$2); print $2 }' || true)"
  if [[ -n "$next_todo" ]]; then
    ok "   → npm run team:run -- $TASK_ID $next_todo"
  else
    rework="$(awk '/^## SUBTASKS/,/^## /' "$PLAN_FILE" \
      | grep -E '^\|' | grep -E 'CLAUDE_MINI_REWORK|RETRY' | head -1 \
      | awk -F'|' '{ gsub(/^[[:space:]]+|[[:space:]]+$/,"",$2); print $2 }' || true)"
    if [[ -n "$rework" ]]; then
      warn "   → REWORK_SUB pending : npm run team:run -- $TASK_ID $rework"
    else
      ok "   → Toutes les sous-tâches DONE. Lancer audit global : npm run team:audit:global -- $TASK_ID"
    fi
  fi
else
  dim "   (pas de section SUBTASKS — suivre run-cycle.md classique)"
fi
echo
