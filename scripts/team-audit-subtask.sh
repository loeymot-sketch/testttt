#!/usr/bin/env bash
# FoodKing — Mini-audit Claude par sous-tâche (ou par lot).
# Usage:
#   bash scripts/team-audit-subtask.sh <TASK_ID> <SUBTASK_ID>
#   bash scripts/team-audit-subtask.sh <TASK_ID> --batch <SUB_A> <SUB_B> [<SUB_C> <SUB_D>]
#
# Construit un brief court (plan + diff + GPT self-audit) et lance `claude -p`.
# Écrit reports/audit/CLAUDE_MINI_AUDIT_${TASK_ID}_${SUBTASK_ID}.md
#  ou   reports/audit/CLAUDE_MINI_AUDIT_${TASK_ID}_BATCH_${N}.md
#
# Sortie normalisée dans le rapport :
#   MINI_AUDIT_VERDICT: PASS | REWORK_SUB | ESCALATE
#
# Voir docs/orchestration/TEAM_WORKFLOW.md
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$REPO_ROOT"
# shellcheck source=./_audit-terminal-fallback-hint.sh
source "$REPO_ROOT/scripts/_audit-terminal-fallback-hint.sh"

TASK_ID="${1:-}"
shift || true

if [[ -z "$TASK_ID" || $# -eq 0 ]]; then
  echo "Usage:"
  echo "  bash scripts/team-audit-subtask.sh <TASK_ID> <SUBTASK_ID>"
  echo "  bash scripts/team-audit-subtask.sh <TASK_ID> --batch <SUB_A> <SUB_B> [...]"
  exit 2
fi

MODE="single"
SUBS=()
if [[ "$1" == "--batch" ]]; then
  MODE="batch"
  shift
  while [[ $# -gt 0 ]]; do SUBS+=("$1"); shift; done
  if (( ${#SUBS[@]} < 2 || ${#SUBS[@]} > 4 )); then
    echo "[FAIL] batch requiert 2 à 4 SUBTASK_ID."
    exit 2
  fi
else
  SUBS=("$1")
fi

ACTIVE_CYCLE=".cursor/ACTIVE_CYCLE.md"
# shellcheck source=./_lib-active-cycle.sh
source "$REPO_ROOT/scripts/_lib-active-cycle.sh"
PLAN_FILE="$(read_active PLAN_FILE "$ACTIVE_CYCLE")"

# --- Eligibility check pour le batching --------------------------------------
if [[ "$MODE" == "batch" && -n "$PLAN_FILE" && -f "$PLAN_FILE" ]]; then
  for sub in "${SUBS[@]}"; do
    row="$(awk '/^## SUBTASKS/,/^## /' "$PLAN_FILE" | grep -E "^\|[[:space:]]*${sub}[[:space:]]*\|" | head -1 || true)"
    if [[ -z "$row" ]]; then
      echo "[FAIL] $sub absent du plan."
      exit 3
    fi
    diff_col="$(echo "$row" | awk -F'|' '{ gsub(/^[[:space:]]+|[[:space:]]+$/,"",$4); print $4 }')"
    inv_col="$(echo "$row" | awk -F'|' '{ gsub(/^[[:space:]]+|[[:space:]]+$/,"",$6); print $6 }')"
    pol_col="$(echo "$row" | awk -F'|' '{ gsub(/^[[:space:]]+|[[:space:]]+$/,"",$7); print $7 }')"
    if [[ "$diff_col" == "complex" || "$inv_col" != "None" || "$pol_col" != "batch-eligible" ]]; then
      echo "[FAIL] $sub n'est pas batch-eligible (diff=$diff_col / invariants=$inv_col / policy=$pol_col)."
      echo "       Lancer un mini-audit 1:1 à la place."
      exit 4
    fi
  done
fi

# --- Construction du brief ---------------------------------------------------
TS="$(date +%Y%m%d_%H%M%S)"
mkdir -p reports/audit

if [[ "$MODE" == "single" ]]; then
  REPORT="reports/audit/CLAUDE_MINI_AUDIT_${TASK_ID}_${SUBS[0]}.md"
  HEADER="# Mini-audit Claude — ${SUBS[0]}"
else
  REPORT="reports/audit/CLAUDE_MINI_AUDIT_${TASK_ID}_BATCH_${TS}.md"
  HEADER="# Mini-audit Claude (BATCH) — ${SUBS[*]}"
fi

BRIEF="$(mktemp -t foodking-mini-audit-brief.XXXXXX)"
trap 'rm -f "$BRIEF"' EXIT

{
  echo "# Brief mini-audit Claude"
  echo "TASK_ID: $TASK_ID"
  echo "MODE: $MODE"
  echo "SUBTASKS: ${SUBS[*]}"
  echo "PLAN_FILE: $PLAN_FILE"
  echo
  echo "## Plan SUBTASKS extrait"
  if [[ -n "$PLAN_FILE" && -f "$PLAN_FILE" ]]; then
    awk '/^## SUBTASKS/,/^## /{ if (!/^## [^S]/) print }' "$PLAN_FILE" | head -50
  fi
  echo
  for sub in "${SUBS[@]}"; do
    echo "## --- $sub ---"
    gpt_audit="reports/audit/GPT_SELF_AUDIT_${TASK_ID}_${sub}.md"
    [[ -f "reports/audit/GPT_SELF_AUDIT_${sub}.md" ]] && gpt_audit="reports/audit/GPT_SELF_AUDIT_${sub}.md"
    if [[ -f "$gpt_audit" ]]; then
      echo "### GPT self-audit ($gpt_audit)"
      head -200 "$gpt_audit"
    else
      echo "### (pas de GPT self-audit trouvé pour $sub)"
    fi
    echo
    sub_dir="missions/${TASK_ID}/subtasks/${sub}"
    if [[ -f "$sub_dir/output_codex.json" ]]; then
      echo "### Mission output ($sub_dir/output_codex.json) — head 80"
      head -80 "$sub_dir/output_codex.json"
    fi
    echo
  done
  echo "## git diff (dernières 200 lignes par fichier modifié, max 2000 lignes)"
  git diff --stat 2>/dev/null | head -20 || true
  echo
  git diff 2>/dev/null | head -2000 || true
} > "$BRIEF"

# --- Construction du prompt --------------------------------------------------
PROMPT="$(mktemp -t foodking-mini-audit-prompt.XXXXXX)"
trap 'rm -f "$BRIEF" "$PROMPT"' EXIT

{
  echo "Tu es Claude, mini-auditeur de sous-tâche FoodKing."
  echo "Lis le brief ci-dessous (plan + GPT self-audit + diff)."
  echo "Vérifie : invariants FoodKing respectés, scope respecté, qualité d'implémentation,"
  echo "couverture vs description de la sous-tâche."
  echo
  echo "Format de sortie OBLIGATOIRE en fin de réponse :"
  echo "  MINI_AUDIT_VERDICT: PASS"
  echo "  ou"
  echo "  MINI_AUDIT_VERDICT: REWORK_SUB"
  echo "  ou"
  echo "  MINI_AUDIT_VERDICT: ESCALATE"
  echo
  echo "Si REWORK_SUB : liste les 1-3 corrections précises à appliquer."
  echo "Si ESCALATE : explique pourquoi le scope dépasse la sous-tâche (HUMAN_GATE recommandé)."
  echo
  echo "=== BRIEF ==="
  cat "$BRIEF"
} > "$PROMPT"

# --- Lancement claude -p -----------------------------------------------------
if ! command -v claude >/dev/null 2>&1; then
  echo "[WARN] binaire 'claude' indisponible — écriture du brief uniquement."
  {
    echo "$HEADER"
    echo "_(claude CLI absent — mini-audit non exécuté ; brief conservé pour repli orchestrateur)_"
    echo
    echo "## Brief"
    echo '```'
    cat "$BRIEF"
    echo '```'
    echo
    echo "## Suite (ne pas bloquer le cycle)"
    echo "Invoquer le Task Cursor **\`foodking-planner-orchestrator\`** avec ce brief + \`.cursor/context/audit-context.md\` (mini-audit équivalent), puis tracer \`AUDIT_CHANNEL: cursor-session\` + \`AUDIT_FALLBACK_REASON: claude_cli_absent\`."
    echo "Doc : \`docs/orchestration/AUDIT_TERMINAL_QUOTA_FALLBACK.md\`."
    echo
    echo "MINI_AUDIT_VERDICT: ESCALATE"
  } > "$REPORT"
  audit_terminal_fallback_hint_stderr
  echo "[ok] brief écrit : $REPORT (verdict ESCALATE — voir repli planner ci-dessus stderr)"
  exit 0
fi

echo "[mini-audit] lancement claude -p (mode non-interactif)…"
tmp_claude="$(mktemp "${TMPDIR:-/tmp}/fk-mini-audit-claude.XXXXXX")"
set +e
cat "$PROMPT" | claude -p --add-dir "$REPO_ROOT" >"$tmp_claude" 2>&1
claude_ex=$?
set -e
{
  echo "$HEADER"
  echo
  echo "_Généré par scripts/team-audit-subtask.sh — $(date)_"
  echo
  echo "## Verdict & analyse"
  echo
  cat "$tmp_claude"
} > "$REPORT"
rm -f "$tmp_claude"
if [[ "$claude_ex" -ne 0 ]]; then
  echo "[WARN] claude -p exit $claude_ex — consulter le rapport et appliquer le repli." >&2
  audit_terminal_fallback_hint_stderr "$REPORT"
fi

echo "[ok] $REPORT"

# --- Affichage verdict --------------------------------------------------------
verdict="$(grep -E '^MINI_AUDIT_VERDICT:' "$REPORT" | tail -1 | sed 's/^MINI_AUDIT_VERDICT:[[:space:]]*//' | tr -d '[:space:]')"
if [[ -z "$verdict" ]]; then
  echo "[WARN] verdict non détecté dans $REPORT — relire manuellement."
  exit 0
fi
case "$verdict" in
  PASS)         echo "[verdict] PASS — marquer la(les) sous-tâche(s) DONE dans le plan." ;;
  REWORK_SUB)   echo "[verdict] REWORK_SUB — incrémenter retry, relancer team:run." ;;
  ESCALATE)     echo "[verdict] ESCALATE — créer docs/gates/GATE_${TASK_ID}_$(date +%F).md (HUMAN_GATE)." ;;
  *)            echo "[verdict] inconnu : $verdict" ;;
esac
