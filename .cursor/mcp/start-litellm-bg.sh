#!/bin/bash
# Lance LiteLLM proxy en background persistant pour Graphiti MCP.
# À exécuter UNE FOIS avant Cursor. Reste actif après fermeture du terminal.
#
# Usage:
#   bash .cursor/mcp/start-litellm-bg.sh
#   # attends "✓ LiteLLM prêt"
#   # puis lance/reload Cursor → graphiti MCP démarre en < 3s

export PATH="/opt/homebrew/bin:/opt/homebrew/sbin:/usr/local/bin:/usr/bin:/bin:/usr/sbin:/sbin:${HOME}/.local/bin:${HOME}/.cargo/bin:${PATH}"

REPO_DIR="/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt"
CONFIG="${REPO_DIR}/.cursor/mcp/litellm_config.yaml"
PROXY_PORT=4000
PROXY_LOG="/tmp/litellm-foodking.log"
PID_FILE="/tmp/litellm-foodking.pid"

# Already running?
if curl -sf "http://localhost:${PROXY_PORT}/health" > /dev/null 2>&1; then
  echo "✓ LiteLLM déjà actif sur :${PROXY_PORT}"
  exit 0
fi

# Resolve litellm binary
LITELLM_BIN=""
if command -v litellm &>/dev/null; then
  LITELLM_BIN="$(command -v litellm)"
elif python3 -c "import litellm" &>/dev/null; then
  LITELLM_BIN="python3 -m litellm"
else
  echo "✗ litellm introuvable. Installe : pip install 'litellm[proxy]' fastembed"
  exit 1
fi

echo "Démarrage LiteLLM (${LITELLM_BIN}) sur :${PROXY_PORT}..."
echo "Log : ${PROXY_LOG}"

# Export Moonshot key for litellm config
export MOONSHOT_API_KEY="${MOONSHOT_API_KEY:-sk-y1YkFnGIzurQp6Y2ftX9flsY7rZhEkKQXNOW8Z12OTHjtucb}"

# Launch with nohup so it survives terminal close
if [[ "${LITELLM_BIN}" == "python3 -m litellm" ]]; then
  nohup python3 -m litellm --config "${CONFIG}" --port "${PROXY_PORT}" > "${PROXY_LOG}" 2>&1 &
else
  nohup "${LITELLM_BIN}" --config "${CONFIG}" --port "${PROXY_PORT}" > "${PROXY_LOG}" 2>&1 &
fi

echo $! > "${PID_FILE}"
echo "PID : $(cat "${PID_FILE}")"
echo "Attente démarrage (premier run = téléchargements fastembed ~90MB)..."

for i in $(seq 1 90); do
  if curl -sf "http://localhost:${PROXY_PORT}/health" > /dev/null 2>&1; then
    echo "✓ LiteLLM prêt en ${i}s sur :${PROXY_PORT}"
    echo "→ Lance Cursor maintenant. Graphiti MCP démarrera en < 3s."
    exit 0
  fi
  sleep 1
done

echo "✗ LiteLLM pas prêt après 90s."
echo "  tail -50 ${PROXY_LOG}"
exit 1
