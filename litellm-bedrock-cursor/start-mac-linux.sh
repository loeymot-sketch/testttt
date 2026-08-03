#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")"

if [ ! -f ".env" ]; then
  echo "Missing .env file"
  exit 1
fi

python3 -m venv .venv
source .venv/bin/activate
pip install --upgrade pip
pip install "litellm[proxy]" boto3 python-dotenv

set -a
source .env
set +a

# Défaut 4000. Si le port est pris : `LITELLM_PORT=4001 bash start-mac-linux.sh`
: "${LITELLM_PORT:=4000}"
echo "LiteLLM: http://0.0.0.0:${LITELLM_PORT}  (OpenAI: http://127.0.0.1:${LITELLM_PORT}/v1 )"
exec litellm --config config.yaml --port "${LITELLM_PORT}"
