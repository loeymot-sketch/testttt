#!/usr/bin/env bash
# FoodKing — mandatory GPT plan review before EXECUTE.
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$REPO_ROOT" || exit 1

TASK_ID="${1:-}"
if [[ -z "$TASK_ID" ]]; then
  echo "Usage: bash scripts/codex-plan-review.sh <TASK_ID>"
  echo "       npm run codex:plan-review -- <TASK_ID>"
  exit 1
fi

PLAN_FILE="$(awk -F'|' '/PLAN_FILE/ {gsub(/`/, "", $3); gsub(/^ +| +$/, "", $3); print $3; exit}' .cursor/ACTIVE_CYCLE.md 2>/dev/null || true)"
if [[ -z "$PLAN_FILE" || ! -f "$PLAN_FILE" ]]; then
  PLAN_FILE="$(ls -1t plans/*"$TASK_ID"*.md 2>/dev/null | head -1 || true)"
fi
if [[ -z "$PLAN_FILE" || ! -f "$PLAN_FILE" ]]; then
  echo "[FAIL] Plan file not found for TASK_ID=$TASK_ID"
  exit 1
fi

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
OUT="$REPORT_DIR/GPT_PLAN_REVIEW_${TASK_ID}.md"
RAW="$REPORT_DIR/GPT_PLAN_REVIEW_RAW_${TASK_ID}.log"
PROMPT="${TMPDIR:-/tmp}/foodking-plan-review-${TASK_ID}-$$.txt"

cat > "$PROMPT" <<PROMPT_EOF
Tu es le second avis GPT-5.5-pro/xhigh du cycle FoodKing.

Objectif: auditer le plan AVANT toute execution. Tu ne dois pas implementer.

Lis:
- AGENTS.md
- .cursor/routing.md
- .cursor/commands/run-cycle.md
- $PLAN_FILE

Verifie:
- scope borne et coherent avec SUBSYSTEMS_TOUCHED
- invariants FoodKing: pricing backend SSOT, OrderStatus enum, branch_id isolation, dispatch after commit, frozen zones, symmetry OrderService/FrontendOrderService
- gates et stop conditions
- strategie de tests suffisante
- absence d'implementation routine/Composer pour les changements produit

Sortie obligatoire en Markdown court avec ces lignes exactes:
PLAN_REVIEW_CHANNEL: codex-extension
PLAN_REVIEW_MODEL: $EXT_MODEL
PLAN_REVIEW_REASONING_EFFORT: $EXT_REASONING_EFFORT
PLAN_REVIEW_VERDICT: PASS | REWORK | ESCALATE

Puis liste les corrections requises si REWORK/ESCALATE.
PROMPT_EOF

{
  echo "=== GPT plan review — $TASK_ID ==="
  echo "PLAN_FILE: $PLAN_FILE"
  echo "PLAN_REVIEW_MODEL: $EXT_MODEL"
  echo "PLAN_REVIEW_REASONING_EFFORT: $EXT_REASONING_EFFORT"
} > "$RAW"

"$CODEX_BIN" exec \
  -m "$EXT_MODEL" \
  -c "model_reasoning_effort=\"${EXT_REASONING_EFFORT}\"" \
  -o "$OUT" \
  - < "$PROMPT" >> "$RAW" 2>&1

rm -f "$PROMPT" 2>/dev/null || true
echo "[OK] $OUT"
