#!/usr/bin/env bash
# FoodKing — Vérification opérationnelle de la « boucle » terminal-first
# (EXECUTE : codex-extension / CLI codex + Pro PRIMARY ; AUDIT : claude terminal PRIMARY)
# -----------------------------------------------------------------------------
# Par défaut : zéro appel API / zéro quota (sauf check --version de claude si présent).
#   bash scripts/verify-orchestration-boucle.sh
#
# Avec preuves d'extremité (consomme un minimum de crédits) :
#   VERIFY_BILLING_FULL=1 bash scripts/verify-orchestration-boucle.sh
#   → 1x claude -p "TERMINAL_OK" (abonnement Anthropic)
#   → 1x npm run codex:smoke (CLI `codex` + compte ChatGPT Pro, pas de clé dans le dépôt)
#
# Exit 0 = environnement prêt à exécuter le plan (au moins : claude + smoke codex en mode FULL)
# Exit 1 = binaire claude manquant (bloquant pour AUDIT PRIMARY)
# Exit 2 = claude OK mais smoke codex échoué (en mode FULL)
# Exit 3 = claude smoketest a échoué (en mode FULL)
# -----------------------------------------------------------------------------
set -euo pipefail
REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$REPO_ROOT" || exit 1

FULL="${VERIFY_BILLING_FULL:-0}"
echo "=== verify-orchestration-boucle (VERIFY_BILLING_FULL=${FULL}) ==="
echo

ok_claude=0
ok_codex=0
ok_claude_api=0

# 1) Claude CLI (obligatoire pour AUDIT PRIMARY en terminal)
if command -v claude >/dev/null 2>&1; then
  ver=$(claude --version 2>&1 | head -1)
  path_line=$(command -v claude)
  echo "[OK] claude on PATH: $path_line"
  echo "     $ver"
  ok_claude=1
else
  echo "[FAIL] binaire 'claude' (Claude Code) introuvable sur PATH — AUDIT PRIMARY (terminal) impossible."
  echo "       Install / PATH : voir AGENTS.md § Terminal allies, ou : bash scripts/foodking-claude-orchestrate.sh check"
  echo
  echo "=== RÉSULTAT: BLOCKING (exit 1) — utiliser AUDIT fallback cursor-session + AUDIT_FALLBACK_REASON: ==="
  exit 1
fi

# 2) Vérif. API terminal (optionnel) — 1 requête minimale
if [[ "$FULL" == "1" ]]; then
  echo
  echo "[--] VERIFY_BILLING_FULL=1 : smoketest claude (1 requête API) …"
  if bash "$REPO_ROOT/scripts/foodking-claude-orchestrate.sh" smoketest; then
    ok_claude_api=1
    echo "[OK] claude terminal API / abonnement — réponse contient TERMINAL_OK"
  else
    echo "[FAIL] claude smoketest — auth / réseau / quota, ou sortie inattendue. Debug: FOODKING_CLAUDE_SMOKE_DEBUG=1 bash $REPO_ROOT/scripts/foodking-claude-orchestrate.sh smoketest"
    echo
    echo "=== RÉSULTAT: claude API FAIL (exit 3) — boucle opérationnelle en mode dégradé (audit session) ==="
    exit 3
  fi
else
  echo
  echo "[--] claude API non testé (défaut). Pour 1x test API : VERIFY_BILLING_FULL=1 $0"
fi

# 3) codex extension / CLI (optionnel) — 1 requête smoke (compte ChatGPT Pro, pas de clé API requise)
# shellcheck source=./codex-resolve-bin.sh
# shellcheck disable=SC1091
if [[ "$FULL" == "1" ]]; then
  echo
  echo "[--] VERIFY_BILLING_FULL=1 : npm run codex:smoke (CLI codex, compte Pro) …"
  source "$REPO_ROOT/scripts/codex-resolve-bin.sh"
  if B="$(codex_resolved_bin)"; then
    echo "[--] binaire codex: $B"
  else
    echo "[FAIL] binaire 'codex' introuvable. Dans le dépôt: npm install (@openai/codex)  OU  global: npm i -g @openai/codex"
    exit 2
  fi
  if npm run codex:smoke --prefix "$REPO_ROOT" 2>&1; then
    ok_codex=1
    echo "[OK] codex-extension (npm run codex:smoke) — codex exec repond"
  else
    echo "[FAIL] codex:smoke — auth: lancer le binaire codex (voir ci-dessus) puis Sign in with ChatGPT (Pro) ; évent. codex auth logout + reconnect."
    echo
    echo "=== RÉSULTAT: codex CLI FAIL (exit 2) — EXÉCUTE : fallback foodking-complex-implementer + FALLBACK_REASON:  |  urgence: npm run codex:smoke:proxy-legacy (proxy+clé) ==="
    exit 2
  fi
else
  echo
  echo "[--] codex (extension Pro) non teste (defaut). FULL: npm run codex:smoke avec VERIFY_BILLING_FULL=1"
fi

# 4) Fichiers procéduraux
echo
if [[ -f "$REPO_ROOT/.cursor/commands/run-cycle.md" ]]; then
  if grep -q "AUDIT_CHANNEL: claude-terminal" "$REPO_ROOT/.cursor/commands/run-cycle.md" 2>/dev/null; then
    echo "[OK] run-cycle.md : AUDIT terminal PRIMARY documenté (grep AUDIT_CHANNEL: claude-terminal)"
  else
    echo "[WARN] run-cycle.md : attente d'une section AUDIT claude-terminal PRIMARY"
  fi
fi
if [[ -f "$REPO_ROOT/docs/orchestration/CODEX_API_DELEGATION.md" ]]; then
  if grep -q "terminal" "$REPO_ROOT/docs/orchestration/CODEX_API_DELEGATION.md" 2>/dev/null; then
    echo "[OK] CODEX_API_DELEGATION.md : section terminal-first présente"
  fi
fi

echo
echo "=== RÉSULTAT: boucle gouvernée (terminal-first) — binaire claude=$ok_claude, claude API smoke=$ok_claude_api, codex smoke=$ok_codex ==="
if [[ "$FULL" == "1" ]] && [[ $ok_claude_api -eq 1 ]] && [[ $ok_codex -eq 1 ]]; then
  echo "ALL GREEN: prêt production procédurale (extremities OK)."
elif [[ $ok_claude -eq 1 ]]; then
  echo "CONDITIONAL: binaire OK ; lancer VERIFY_BILLING_FULL=1 pour prouver les deux canaux API."
else
  echo "UNEXPECTED"
  exit 1
fi
exit 0
