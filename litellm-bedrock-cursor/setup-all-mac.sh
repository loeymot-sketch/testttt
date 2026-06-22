#!/usr/bin/env bash
# Installation unique macOS : venv, LiteLLM, boto3 — puis tu lances le proxy à la main.
# Usage:  cd litellm-bedrock-cursor && bash setup-all-mac.sh
set -euo pipefail
cd "$(dirname "$0")"

echo "=== macOS — LiteLLM (Bedrock) + outils de test ==="
echo ""

if ! command -v python3 &>/dev/null; then
  echo "ERREUR: installe Python 3 :   brew install python3"
  echo "   ou: https://www.python.org/downloads/macos/"
  exit 1
fi

if [ ! -f ".env" ]; then
  if [ -f ".env.example" ]; then
    cp .env.example .env
    echo "→ Fichier .env créé depuis .env.example"
    echo "  Ouvre .env dans l’éditeur, mets TES clés AWS + LITELLM_MASTER_KEY, puis relance :"
    echo "    bash setup-all-mac.sh"
  else
    echo "ERREUR: pas de .env ni .env.example. Copie le modèle de la doc (README) dans .env"
  fi
  exit 1
fi

echo "→ Création / MAJ venv + paquets (litellm[proxy], boto3, python-dotenv)..."
python3 -m venv .venv
# shellcheck disable=SC1091
source .venv/bin/activate
python -m pip install -q -U pip
pip install -q "litellm[proxy]" boto3 python-dotenv
echo "  OK"
echo ""

if ! command -v brew &>/dev/null; then
  echo "→ (Optionnel) Homebrew: https://brew.sh — pour awscli / node plus tard"
else
  command -v aws &>/dev/null || echo "→ Optionnel : brew install awscli  puis  aws configure  (Même compte que les clés dans .env, ou skip si tu n’utilises que .env + LiteLLM)"
  command -v node &>/dev/null || echo "→ Optionnel (Claude Code) : brew install node  puis  npm i -g @anthropic/claude-code"
fi
echo ""
echo "=== Tout prêt. Commandes (à copier) ==="
echo ""
echo "1) Vérifier Bedrock (sans proxy, même clés que .env) :"
echo "   cd \"$(pwd)\" && . .venv/bin/activate && python3 diagnose_bedrock.py"
echo ""
echo "2) Démarrer le proxy (port 4001 si 4000 pris) :"
echo "   LITELLM_PORT=4001 bash start-mac-linux.sh"
echo ""
echo "3) Test HTTP :  (dans un autre terminal, proxy lancé)"
echo "   LITELLM_PORT=4001 bash test.sh"
echo ""
echo "4) Cursor : Base URL  http://127.0.0.1:4001/v1  — API key = LITELLM_MASTER_KEY de ton .env — modèle  bedrock-claude-opus"
echo ""
