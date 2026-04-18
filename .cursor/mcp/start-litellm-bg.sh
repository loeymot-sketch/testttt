#!/bin/bash
# Lance LiteLLM proxy en background persistant pour Graphiti MCP.
# À exécuter UNE FOIS avant Cursor. Reste actif après fermeture du terminal.
#
# Usage:
#   bash .cursor/mcp/start-litellm-bg.sh
#   # attends "✓ LiteLLM prêt"
#   # puis lance/reload Cursor → graphiti MCP démarre en < 3s

export PATH="/opt/homebrew/bin:/opt/homebrew/sbin:/usr/local/bin:/usr/bin:/bin:/usr/sbin:/sbin:${HOME}/.local/bin:${HOME}/.cargo/bin:${PATH}"

# See start-graphiti-mcp.sh — DEBUG=release breaks LiteLLM Click (--debug must be boolean).
unset DEBUG LITELLM_DEBUG 2>/dev/null || true

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=/dev/null
source "${SCRIPT_DIR}/lib-litellm-health.sh"

REPO_DIR="/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt"
CONFIG="${REPO_DIR}/.cursor/mcp/litellm_config.yaml"
PROXY_PORT=4000
export GRAPHITI_LITELLM_PORT="${PROXY_PORT}"
PROXY_LOG="/tmp/litellm-foodking.log"
PID_FILE="/tmp/litellm-foodking.pid"

if [[ -z "${MOONSHOT_API_KEY:-}" ]]; then
  echo "✗ MOONSHOT_API_KEY vide. Exporte la clé Moonshot (console https://platform.moonshot.cn/) puis relance ce script."
  exit 1
fi

export MOONSHOT_API_BASE="${MOONSHOT_API_BASE:-https://api.moonshot.ai/v1}"

if curl -sf "http://127.0.0.1:${PROXY_PORT}/health" > /dev/null 2>&1 && ! graphiti_litellm_models_healthy; then
  echo "✗ Un proxy répond déjà sur :${PROXY_PORT} mais aucun modèle n'est sain (souvent clé Moonshot 401)."
  echo "  Arrête-le puis relance : bash ${REPO_DIR}/.cursor/mcp/stop-litellm.sh"
  graphiti_litellm_health_hint || true
  exit 1
fi

# Already running and models healthy?
if curl -sf "http://127.0.0.1:${PROXY_PORT}/health" > /dev/null 2>&1 && graphiti_litellm_models_healthy; then
  echo "✓ LiteLLM déjà actif sur :${PROXY_PORT} (modèles sains)"
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

# Launch with nohup so it survives terminal close
# Même logique que start-graphiti-mcp.sh : ne pas laisser OPENAI_* localhost polluer LiteLLM.
_LITELLM_ENV=(env -u DEBUG -u LITELLM_DEBUG -u OPENAI_BASE_URL -u OPENAI_API_URL -u OPENAI_API_BASE)
if [[ "${LITELLM_BIN}" == "python3 -m litellm" ]]; then
  nohup "${_LITELLM_ENV[@]}" python3 -m litellm --config "${CONFIG}" --port "${PROXY_PORT}" > "${PROXY_LOG}" 2>&1 &
else
  nohup "${_LITELLM_ENV[@]}" "${LITELLM_BIN}" --config "${CONFIG}" --port "${PROXY_PORT}" > "${PROXY_LOG}" 2>&1 &
fi

echo $! > "${PID_FILE}"
echo "PID : $(cat "${PID_FILE}")"
echo "Attente démarrage (premier run = téléchargements fastembed ~90MB)..."

for i in $(seq 1 90); do
  if graphiti_litellm_models_healthy; then
    echo "✓ LiteLLM prêt (modèles sains) en ${i}s sur :${PROXY_PORT}"
    echo "→ Lance Cursor maintenant. Graphiti MCP démarrera en < 3s."
    exit 0
  fi
  sleep 1
done

echo "✗ LiteLLM pas prêt après 90s (aucun modèle sain sur /health)."
graphiti_litellm_health_hint || true
echo "  tail -50 ${PROXY_LOG}"
exit 1
