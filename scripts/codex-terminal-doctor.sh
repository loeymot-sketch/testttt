#!/usr/bin/env bash
# FoodKing — Prouver que le **CLI Codex** (OpenAI) fonctionne dans le terminal.
#
# Règle d’or (à afficher) :
#   L’**extension / app Codex dans Cursor** = login dans l’IDE.
#   Le **Terminal** n’hérite pas de ce login : c’est le binaire `codex` (npm) qui
#   a sa propre session (`codex login` → « Logged in using ChatGPT »).
#   Même compte Pro possible des deux côtés, **deux canaux d’auth distincts**.
#
# Usage: bash scripts/codex-terminal-doctor.sh
#   ou   npm run codex:doctor
# Exit 0 = binaire + auth + exec ping OK
set -euo pipefail
REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$REPO_ROOT" || exit 1

echo "╔══════════════════════════════════════════════════════════════════════════╗"
echo "║  Codex — docteur terminal (CLI ≠ extension Cursor)                       ║"
echo "╚══════════════════════════════════════════════════════════════════════════╝"
echo
echo "→ Rappel : l’extension Codex connectée ne suffit pas toute seule : le CLI doit"
echo "  exister (npm) et taper \`codex login\` une fois (ChatGPT / Pro) pour ce binaire."
echo

LOCAL_CODEX="$REPO_ROOT/node_modules/.bin/codex"
GLOBAL_CODEX=""
if command -v codex >/dev/null 2>&1; then
  GLOBAL_CODEX=$(command -v codex)
fi

if [[ ! -x "$LOCAL_CODEX" ]]; then
  echo "[1/4] binaire local absent — npm install (installe @openai/codex) …"
  (cd "$REPO_ROOT" && npm install) || {
    echo "[FAIL] npm install a échoué."
    exit 1
  }
else
  echo "[1/4] binaire local: OK  ($LOCAL_CODEX)"
fi

# shellcheck source=./codex-resolve-bin.sh
# shellcheck source=./codex-sanitize-env-for-codex-cli.sh
source "$REPO_ROOT/scripts/codex-resolve-bin.sh"
source "$REPO_ROOT/scripts/codex-sanitize-env-for-codex-cli.sh"
# Même diagnostic que l’EXÉCUTE : ne pas hériter d’un proxy hérité
codex_sanitize_openai_routing_env
if ! RESOLVED=$(codex_resolved_bin); then
  echo "[FAIL] Aucun binaire codex résolvable après npm install."
  exit 1
fi
echo "       → résolution scripts:  $RESOLVED"
if [[ -n "$GLOBAL_CODEX" ]]; then
  echo "       → codex sur PATH:    $GLOBAL_CODEX (optionnel, pratique)"
else
  echo "       → (pas de \`codex\` sur PATH — normal si pas de npm -g; npm run utilise node_modules/)"
fi
echo
echo "[2/4] version"
"$RESOLVED" --version
echo

echo "[3/4] session CLI (indépendante de l’extension) —  \`codex login status\` :"
if ! ST=$( "$RESOLVED" login status 2>&1); then
  echo "$ST"
  echo "[FAIL] status login a échoué."
  exit 1
fi
echo "$ST"
if ! echo "$ST" | grep -qiE "Logged in|ChatGPT|chatgpt"; then
  echo
  echo "[4/4] ÉCHEC auth — lance en interactif (une fois) :"
  echo "       $  $RESOLVED login"
  echo "   ou: $  $RESOLVED   puis menu connexion (Sign in with ChatGPT, Pro)"
  echo "   Puis relance:  npm run codex:doctor"
  exit 2
fi
echo
echo "[4/4] appel modèle (1 requête) — CTOK_PING_OK …"
if ! node "$REPO_ROOT/scripts/codex-exec-ping.mjs" "$RESOLVED" 120000; then
  echo "[FAIL] codex exec a échoué (réseau, quota, ou 1er cold start — réessaie)."
  exit 3
fi

echo
echo "=== OK: Codex **CLI** opérationnel dans le terminal (même dépôt).       ==="
echo "===   Tu peux lancer: npm run codex:smoke  &&  npm run codex:complex -- <TASK>   ==="
exit 0
