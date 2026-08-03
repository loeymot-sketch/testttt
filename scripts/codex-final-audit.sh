#!/usr/bin/env bash
# FoodKing — GPT final cross-audit.
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$REPO_ROOT" || exit 1

TASK_ID="${1:-}"
if [[ -z "$TASK_ID" ]]; then
  echo "Usage: bash scripts/codex-final-audit.sh <TASK_ID>"
  echo "       npm run codex:final-audit -- <TASK_ID>"
  exit 1
fi

MISSION_INPUT="missions/$TASK_ID/input.json"
MISSION_OUTPUT="missions/$TASK_ID/output_codex.json"

PLAN_FILE=""
if [[ -f "$MISSION_INPUT" ]]; then
  PLAN_FILE="$(node -e "const fs=require('fs'); const p='$MISSION_INPUT'; const j=JSON.parse(fs.readFileSync(p,'utf8')); process.stdout.write(j.plan_file || '')" 2>/dev/null || true)"
fi
if [[ -z "$PLAN_FILE" ]]; then
  PLAN_FILE="$(awk -F'|' '/PLAN_FILE/ {gsub(/`/, "", $3); gsub(/^ +| +$/, "", $3); print $3; exit}' .cursor/ACTIVE_CYCLE.md 2>/dev/null || true)"
fi
if [[ -z "$PLAN_FILE" || ! -f "$PLAN_FILE" ]]; then
  PLAN_FILE="$(ls -1t plans/*"$TASK_ID"*.md 2>/dev/null | head -1 || true)"
fi
REPORT_FILE="$(awk -F'|' '/REPORT_FILE/ {gsub(/`/, "", $3); gsub(/^ +| +$/, "", $3); print $3; exit}' .cursor/ACTIVE_CYCLE.md 2>/dev/null || true)"
[[ -z "$REPORT_FILE" || ! -f "$REPORT_FILE" ]] && REPORT_FILE="reports/post_execute_latest.log"

SELF_AUDIT="reports/audit/GPT_SELF_AUDIT_${TASK_ID}.md"
CLAUDE_CONTEXT="reports/audit/_TERMINAL_CONTEXT_BRIEF.md"
GPT_ONLY="${FOODKING_GPT_ONLY:-0}"

EXT_MODEL="${CODEX_EXT_MODEL:-${CODEX_EXT_MODEL_PRO:-gpt-5.5-pro}}"
EXT_REASONING_EFFORT="${CODEX_EXT_REASONING_EFFORT:-xhigh}"

# shellcheck source=./codex-resolve-bin.sh
# shellcheck source=./codex-sanitize-env-for-codex-cli.sh
source "$REPO_ROOT/scripts/codex-resolve-bin.sh"
source "$REPO_ROOT/scripts/codex-sanitize-env-for-codex-cli.sh"
codex_sanitize_openai_routing_env
if ! CODEX_BIN="$(codex_resolved_bin)"; then
  echo "[FAIL] Binaire 'codex' introuvable."
  exit 1
fi

REPORT_DIR="$REPO_ROOT/reports/audit"
mkdir -p "$REPORT_DIR"
OUT="$REPORT_DIR/GPT_FINAL_AUDIT_${TASK_ID}.md"
RAW="$REPORT_DIR/GPT_FINAL_AUDIT_RAW_${TASK_ID}.log"
PROMPT="${TMPDIR:-/tmp}/foodking-final-audit-${TASK_ID}-$$.txt"

if [[ "$GPT_ONLY" == "1" ]]; then
  AUDIT_CONTEXT_LINES=$(cat <<EOF
Objectif: auditer la livraison en mode GPT-only explicite. Claude est interdit pour ce run; tu ne dois pas implementer.

Lis:
- AGENTS.md
- .cursor/routing.md
- .cursor/commands/run-cycle.md
- ${PLAN_FILE:-PLAN_FILE_NOT_FOUND}
- ${MISSION_INPUT:-MISSION_INPUT_NOT_FOUND}
- ${MISSION_OUTPUT:-MISSION_OUTPUT_NOT_FOUND}
- $REPORT_FILE
- ${SELF_AUDIT:-SELF_AUDIT_NOT_FOUND}

Ce mode remplace l'exigence d'audit Claude par une verification GPT finale stricte et documentee.
EOF
)
  TRACE_REQUIREMENT="- FOODKING_GPT_ONLY=1 trace; ne pas exiger AUDIT_VERDICT Claude"
else
  AUDIT_CONTEXT_LINES=$(cat <<EOF
Objectif: auditer la livraison APRES validation et audit Claude. Tu ne dois pas implementer.

Lis:
- AGENTS.md
- .cursor/routing.md
- .cursor/commands/run-cycle.md
- ${PLAN_FILE:-PLAN_FILE_NOT_FOUND}
- ${MISSION_INPUT:-MISSION_INPUT_NOT_FOUND}
- ${MISSION_OUTPUT:-MISSION_OUTPUT_NOT_FOUND}
- $REPORT_FILE
- ${SELF_AUDIT:-SELF_AUDIT_NOT_FOUND}
- ${CLAUDE_CONTEXT:-CLAUDE_CONTEXT_NOT_FOUND}
EOF
)
  TRACE_REQUIREMENT="- presence de AUDIT_VERDICT: PASS Claude ou fallback Cursor trace"
fi

cat > "$PROMPT" <<PROMPT_EOF
Tu es le second avis final GPT-5.5-pro/xhigh du cycle FoodKing.

$AUDIT_CONTEXT_LINES

Inspecte aussi le diff git courant et les fichiers modifies si necessaire.
Pour les missions CV1 masterplay, le worktree peut contenir des diffs cumulatifs de missions deja fermees: audite le perimetre de $TASK_ID via missions/$TASK_ID/input.json, missions/$TASK_ID/output_codex.json, le self-audit, le bloc du rapport, et l'allowlist. Ne transforme pas un diff global non lie a $TASK_ID en REWORK, mais exige un REWORK si $TASK_ID touche hors allowlist, si une validation mandatory manque, ou si un invariant est affaibli.

Verifie:
- implementation strictement dans le plan
- tests et validations declares
- invariants FoodKing: pricing backend SSOT, OrderStatus enum, branch_id isolation, dispatch after commit, frozen zones, symmetry OrderService/FrontendOrderService
$TRACE_REQUIREMENT
- aucun close possible si risque non traite

Sortie obligatoire en Markdown court avec ces lignes exactes:
GPT_FINAL_AUDIT_CHANNEL: codex-extension
FOODKING_GPT_ONLY: $GPT_ONLY
GPT_FINAL_AUDIT_MODEL: $EXT_MODEL
GPT_FINAL_AUDIT_REASONING_EFFORT: $EXT_REASONING_EFFORT
GPT_FINAL_AUDIT_VERDICT: PASS | REWORK | ESCALATE

Puis liste les corrections requises si REWORK/ESCALATE.
PROMPT_EOF

{
  echo "=== GPT final audit — $TASK_ID ==="
  echo "PLAN_FILE: ${PLAN_FILE:-}"
  echo "REPORT_FILE: $REPORT_FILE"
  echo "GPT_FINAL_AUDIT_MODEL: $EXT_MODEL"
  echo "GPT_FINAL_AUDIT_REASONING_EFFORT: $EXT_REASONING_EFFORT"
  git diff --name-only || true
} > "$RAW"

"$CODEX_BIN" exec \
  -m "$EXT_MODEL" \
  -c "model_reasoning_effort=\"${EXT_REASONING_EFFORT}\"" \
  -o "$OUT" \
  - < "$PROMPT" >> "$RAW" 2>&1

rm -f "$PROMPT" 2>/dev/null || true
if grep -Eq '^GPT_FINAL_AUDIT_VERDICT:[[:space:]]*PASS([[:space:]]|$)' "$OUT"; then
  echo "[OK] $OUT"
  exit 0
fi

echo "[FAIL] $OUT did not report GPT_FINAL_AUDIT_VERDICT: PASS"
exit 1
