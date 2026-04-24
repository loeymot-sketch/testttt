#!/usr/bin/env bash
# Fumigation minimale de l’extension Codex (compte Pro) — 0 clé API dans le dépôt.
# Binaire : PATH global, ou $CODEX_BIN, ou node_modules/.bin/codex (après npm install).
# Consomme ~1 requête si le CLI est authentifié (Sign in with ChatGPT).
set -euo pipefail
REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
# shellcheck source=./codex-resolve-bin.sh
source "$REPO_ROOT/scripts/codex-resolve-bin.sh"
if ! B="$(codex_resolved_bin)"; then
  echo "[codex:smoke] FAIL: binaire codex introuvable."
  echo "       1) Dans le dépôt : cd $REPO_ROOT && npm install   (@openai/codex en devDependency)"
  echo "       2) Ou global :     npm i -g @openai/codex"
  echo "       Puis :            codex  puis Sign in with ChatGPT (Pro) — pas de CODEX_API_KEY requise pour le flux Pro."
  exit 1
fi
# 1 requête modèle — via Node (timeout) pour ne pas rester bloqué indéfiniment (ex. sans TTY)
# Shell brut `codex exec` peut hang dans certains environnements; le ping Node coupe après 120s.
if node "$REPO_ROOT/scripts/codex-exec-ping.mjs" "$B" 120000 2>&1; then
  echo "[codex:smoke] OK (extension, codex exec + Pro)"
  exit 0
fi
EX=$?
# Repli: ancienne méthode (terminal interactif / CI avec TTY)
if "$B" exec "Reply with only the word: CTOK_PING_OK" 2>&1 | grep -q "CTOK_PING_OK"; then
  echo "[codex:smoke] OK (extension, exec direct)"
  exit 0
fi
if echo "Reply with only: CTOK_PING_OK" | "$B" exec - 2>&1 | grep -q "CTOK_PING_OK"; then
  echo "[codex:smoke] OK (extension, exec stdin)"
  exit 0
fi
echo "[codex:smoke] FAIL — binaire: $B"
echo "       1) npm run codex:verify-pro  (doit montrer « Logged in using ChatGPT »)"
echo "       2) Puis: $B login  si besoin, Sign in with ChatGPT (Pro)"
echo "       3) Exécute le smoke depuis Terminal.app (même dossier) si timeout Node (réseau / 1er appel froid)"
exit 2
