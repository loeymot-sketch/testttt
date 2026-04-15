#!/bin/bash
# FoodKing — Graphiti MCP wrapper
# Invoqué par Cursor via ~/.cursor/mcp.json
#
# FAST START: si LiteLLM tourne déjà (start-litellm-bg.sh), Graphiti démarre en < 3s.
# COLD START: lance LiteLLM en background puis attend (40-90s premier run).

export PATH="/opt/homebrew/bin:/opt/homebrew/sbin:/usr/local/bin:/usr/bin:/bin:/usr/sbin:/sbin:${HOME}/.local/bin:${HOME}/.cargo/bin:${PATH}"

set -uo pipefail

REPO_DIR="/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt"
GRAPHITI_DIR="/Users/1millnonstop/graphiti"
MCP_DIR="${GRAPHITI_DIR}/mcp_server"
CONFIG="${REPO_DIR}/.cursor/mcp/litellm_config.yaml"
PROXY_PORT=4000
PROXY_LOG="/tmp/litellm-foodking.log"
LITELLM_TIMEOUT="${LITELLM_TIMEOUT:-90}"

CURL_BIN="$(command -v curl 2>/dev/null || true)"
[[ -z "${CURL_BIN}" && -x /usr/bin/curl ]] && CURL_BIN="/usr/bin/curl"
[[ -z "${CURL_BIN}" ]] && CURL_BIN="curl"

# --- Cleanup: only kill LiteLLM if WE started it ---
LITELLM_PID=""
cleanup() {
  if [[ -n "${LITELLM_PID}" ]]; then
    kill "${LITELLM_PID}" 2>/dev/null || true
  fi
}
trap cleanup EXIT INT TERM

# --- Resolve litellm ---
LITELLM_BIN=""
if command -v litellm &>/dev/null; then
  LITELLM_BIN="$(command -v litellm)"
elif python3 -c "import litellm" &>/dev/null; then
  LITELLM_BIN="python3 -m litellm"
else
  echo "[start-graphiti-mcp] ERREUR : litellm introuvable. PATH=${PATH}" >&2
  echo "  pip3 install 'litellm[proxy]' fastembed" >&2
  exit 1
fi

# --- Resolve uv ---
UV_BIN=""
for p in "$(command -v uv 2>/dev/null)" "${HOME}/.local/bin/uv" "${HOME}/.cargo/bin/uv" "/opt/homebrew/bin/uv" "/usr/local/bin/uv"; do
  if [[ -n "${p}" && -x "${p}" ]]; then
    UV_BIN="${p}"
    break
  fi
done
if [[ -z "${UV_BIN}" ]] && python3 -m uv --version &>/dev/null; then
  UV_BIN="python3 -m uv"
fi
if [[ -z "${UV_BIN}" ]]; then
  echo "[start-graphiti-mcp] ERREUR : uv introuvable." >&2
  exit 1
fi

if [[ ! -f "${MCP_DIR}/main.py" ]]; then
  echo "[start-graphiti-mcp] ERREUR : ${MCP_DIR}/main.py introuvable." >&2
  exit 1
fi

echo "[start-graphiti-mcp] litellm=${LITELLM_BIN}  uv=${UV_BIN}" >&2

# Neo4j env alias
if [[ -z "${NEO4J_USER:-}" && -n "${NEO4J_USERNAME:-}" ]]; then
  export NEO4J_USER="${NEO4J_USERNAME}"
fi
GRAPHITI_MCP_ARGS=(--transport stdio --database-provider neo4j)
echo "[start-graphiti-mcp] Neo4j user=${NEO4J_USER:-?} provider=neo4j (stdio)" >&2

# --- LiteLLM: check if already running before launching ---
if "${CURL_BIN}" -sf "http://localhost:${PROXY_PORT}/health" > /dev/null 2>&1; then
  echo "[start-graphiti-mcp] LiteLLM déjà actif sur :${PROXY_PORT} — skip démarrage" >&2
else
  echo "[start-graphiti-mcp] LiteLLM absent — démarrage (attente max ${LITELLM_TIMEOUT}s)..." >&2
  if [[ "${LITELLM_BIN}" == "python3 -m litellm" ]]; then
    python3 -m litellm --config "${CONFIG}" --port "${PROXY_PORT}" > "${PROXY_LOG}" 2>&1 &
  else
    "${LITELLM_BIN}" --config "${CONFIG}" --port "${PROXY_PORT}" > "${PROXY_LOG}" 2>&1 &
  fi
  LITELLM_PID=$!

  for i in $(seq 1 "${LITELLM_TIMEOUT}"); do
    if "${CURL_BIN}" -sf "http://localhost:${PROXY_PORT}/health" > /dev/null 2>&1; then
      echo "[start-graphiti-mcp] LiteLLM prêt en ${i}s (PID ${LITELLM_PID})" >&2
      break
    fi
    if ! kill -0 "${LITELLM_PID}" 2>/dev/null; then
      echo "[start-graphiti-mcp] ERREUR : LiteLLM terminé." >&2
      tail -n 20 "${PROXY_LOG}" >&2 || true
      exit 1
    fi
    if [[ "${i}" -eq "${LITELLM_TIMEOUT}" ]]; then
      echo "[start-graphiti-mcp] ERREUR : timeout ${LITELLM_TIMEOUT}s" >&2
      tail -n 20 "${PROXY_LOG}" >&2 || true
      exit 1
    fi
    sleep 1
  done
fi

# --- Graphiti MCP server ---
echo "[start-graphiti-mcp] Démarrage Graphiti MCP server..." >&2
cd "${MCP_DIR}" || exit 1
if [[ "${UV_BIN}" == "python3 -m uv" ]]; then
  exec python3 -m uv run python main.py "${GRAPHITI_MCP_ARGS[@]}"
fi
exec "${UV_BIN}" run python main.py "${GRAPHITI_MCP_ARGS[@]}"
