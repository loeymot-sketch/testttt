#!/usr/bin/env bash
# FoodKing — EXÉCUTE complexe (PRIMARY) : **extension Codex** + compte ChatGPT Pro.
# 1) Construit le prompt : agents/codex.prompt.txt + missions/{TASK_ID}/
# 2) `codex exec` (non-interactif) → output_codex.raw.log + output_codex.json
# 3) 2e passe : auto-audit → reports/audit/GPT_SELF_AUDIT_{TASK_ID}.md
# Trace : EXECUTE_DELEGATION: codex-extension
# -----------------------------------------------------------------------------
set -euo pipefail
REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$REPO_ROOT" || exit 1

TASK_ID="${1:-}"
if [[ -z "$TASK_ID" ]]; then
  echo "Usage: bash scripts/codex-extension-execute.sh <TASK_ID>"
  echo "       npm run codex:complex -- <TASK_ID>"
  exit 1
fi
if ! [[ "$TASK_ID" =~ ^[A-Za-z0-9._-]+$ ]]; then
  echo "Invalid TASK_ID"
  exit 1
fi

MISSION_DIR="$REPO_ROOT/missions/$TASK_ID"
if [[ ! -f "$MISSION_DIR/input.json" ]]; then
  echo "Manque: $MISSION_DIR/input.json — npm run codex:prepare -- $TASK_ID"
  exit 1
fi

if [[ -n "${CODEX_EXT_MODEL:-}" ]]; then
  EXT_MODEL="$CODEX_EXT_MODEL"
else
  if [[ "${CODEX_EXT_TIER:-complex}" == "standard" ]]; then
    EXT_MODEL="${CODEX_EXT_MODEL_FAST:-gpt-5.5}"
  else
    EXT_MODEL="${CODEX_EXT_MODEL_PRO:-gpt-5.5-pro}"
  fi
fi

# shellcheck source=./codex-resolve-bin.sh
# shellcheck source=./codex-sanitize-env-for-codex-cli.sh
source "$REPO_ROOT/scripts/codex-resolve-bin.sh"
source "$REPO_ROOT/scripts/codex-sanitize-env-for-codex-cli.sh"
# Évite 401 sur un relais hérité (OPENAI_BASE_URL) alors que le flux cible = ChatGPT + api.openai.com
codex_sanitize_openai_routing_env
if ! CODEX_BIN="$(codex_resolved_bin)"; then
  echo "[FAIL] Binaire 'codex' introuvable (extension Codex CLI, compte Pro)."
  echo "      Dépôt:  cd $REPO_ROOT && npm install   (installe @openai/codex dans node_modules/)"
  echo "      Ou:     npm i -g @openai/codex"
  echo "      Puis:   lancer 'codex' (celui installé) → Sign in with ChatGPT (Pro) — pas de clé API dans le dépôt pour ce flux."
  exit 1
fi
export CODEX_BIN
echo "[codex] using: $CODEX_BIN"

PROMPT_FILE="${TMPDIR:-/tmp}/foodking-codex-prompt-$$.txt"
node "$REPO_ROOT/scripts/codex-extension-build-prompt.mjs" "$TASK_ID" > "$PROMPT_FILE"

RAW_LOG="$MISSION_DIR/output_codex.raw.log"
{
  echo "=== Codex extension — mission $TASK_ID — modèle indicatif: $EXT_MODEL (CODEX_EXT_MODEL / CODEX_EXT_TIER) ==="
  echo "EXECUTE_DELEGATION: codex-extension"
  echo "EXECUTE_EXT_MODEL: $EXT_MODEL"
  echo "=== Prompt (octets): $(wc -c < "$PROMPT_FILE") ==="
} > "$RAW_LOG"

# Non-interactif: stdin → `codex exec -`
set +e
{ cat "$PROMPT_FILE" | "$CODEX_BIN" exec - ; } >> "$RAW_LOG" 2>&1
EX=$?
set -e
if [[ $EX -ne 0 ]]; then
  {
    echo ""
    echo "=== Repli: codex exec \"<prompt>\" (argv unique) ex=$EX ==="
    "$CODEX_BIN" exec "$(cat "$PROMPT_FILE")" 2>&1
  } >> "$RAW_LOG" 2>&1 || true
fi
rm -f "$PROMPT_FILE" 2>/dev/null || true

if node "$REPO_ROOT/scripts/codex-extract-json-output.mjs" "$RAW_LOG" "$MISSION_DIR/output_codex.json" 2>/dev/null; then
  echo "[OK] $MISSION_DIR/output_codex.json"
else
  echo "[WARN] Extraction JSON échouée — éditer $MISSION_DIR/output_codex.json à partir de $RAW_LOG"
fi

REPORT_DIR="$REPO_ROOT/reports/audit"
mkdir -p "$REPORT_DIR"
AUDIT_PATH="$REPORT_DIR/GPT_SELF_AUDIT_${TASK_ID}.md"
AUDIT_RAW="$REPORT_DIR/GPT_SELF_AUDIT_RAW_${TASK_ID}.log"
SELF_P="${TMPDIR:-/tmp}/foodking-codex-audit-$$.txt"
if node "$REPO_ROOT/scripts/codex-extension-self-audit-prompt.mjs" "$TASK_ID" > "$SELF_P" 2>/dev/null; then
  { echo "=== Auto-audit GPT (2e passe) ===" ; } > "$AUDIT_RAW"
  set +e
  { cat "$SELF_P" | "$CODEX_BIN" exec - ; } >> "$AUDIT_RAW" 2>&1
  AEX=$?
  set -e
  if [[ $AEX -ne 0 ]]; then
    { echo "" ; "$CODEX_BIN" exec "$(cat "$SELF_P")" 2>&1 ; } >> "$AUDIT_RAW" 2>&1 || true
  fi
  cp "$AUDIT_RAW" "$AUDIT_PATH" 2>/dev/null || cat "$AUDIT_RAW" > "$AUDIT_PATH"
  rm -f "$SELF_P" 2>/dev/null || true
  echo "[OK] $AUDIT_PATH"
else
  echo "[--] skip auto-audit (pas d’output_codex.json exploitable)"
  rm -f "$SELF_P" 2>/dev/null || true
fi

echo "== Fin : tracer EXECUTE_DELEGATION: codex-extension dans post_execute / REPORT_FILE =="
