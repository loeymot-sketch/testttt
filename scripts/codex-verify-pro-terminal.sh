#!/usr/bin/env bash
# Vérif. terminal : binaire @openai/codex + compte (ChatGPT / Pro) — ne consomme pas d’appel modèle.
# Usage : bash scripts/codex-verify-pro-terminal.sh
# Exit 0 si binaire OK et login status indique une session valide.
set -euo pipefail
REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
# shellcheck source=./codex-resolve-bin.sh
source "$REPO_ROOT/scripts/codex-resolve-bin.sh"
if ! BIN="$(codex_resolved_bin)"; then
  echo "[codex:verify] FAIL: binaire introuvable — npm install dans le dépôt ou npm i -g @openai/codex"
  exit 1
fi

echo "=== 1) Binaire Codex (CLI) ==="
echo "Path: $BIN"
"$BIN" --version
echo
echo "=== 2) Session (Sign in with ChatGPT / Pro) ==="
STATUS_OUT="$("$BIN" login status 2>&1)" || true
echo "$STATUS_OUT"
if echo "$STATUS_OUT" | grep -qiE "Logged in|ChatGPT|chatgpt"; then
  echo
  echo "[codex:verify] OK — connecté (flux ChatGPT / compte Pro attendu côté CLI)"
  echo "=== Prochaine étape (1 requête modèle) : npm run codex:smoke  (timeout intégré) ==="
  exit 0
fi
if echo "$STATUS_OUT" | grep -qi "not.*logged|no.*credentials|log in"; then
  echo
  echo "[codex:verify] FAIL — pas de session. Lance: $BIN login  (ou codex) puis connecte le compte Pro."
  exit 1
fi
# Ambigu : on reste en succès binaire, warning
echo
echo "[codex:verify] WARNING — statut auth inattendu. Essaie: $BIN login"
exit 2
