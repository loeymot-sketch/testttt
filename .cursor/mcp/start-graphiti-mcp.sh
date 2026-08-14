#!/bin/bash
# FoodKing — Graphiti MCP wrapper
# Invoqué par Cursor via ~/.cursor/mcp.json
#
# FAST START: si LiteLLM tourne déjà (start-litellm-bg.sh), Graphiti démarre en < 3s.
# COLD START: lance LiteLLM en background puis attend (40-90s premier run).

export PATH="/Library/Frameworks/Python.framework/Versions/3.13/bin:/opt/homebrew/bin:/opt/homebrew/sbin:/usr/local/bin:/usr/bin:/bin:/usr/sbin:/sbin:${HOME}/.local/bin:${HOME}/.cargo/bin:${PATH}"

# Cursor / Rust / Android tooling often inject DEBUG=release. LiteLLM's Click CLI
# maps env DEBUG to --debug and crashes: "Invalid value for '--debug': 'release'".
unset DEBUG LITELLM_DEBUG 2>/dev/null || true

set -uo pipefail

# Secrets Neo4j / Moonshot / OpenRouter / Willow / etc. : plus dans mcp.json.
# Fichier local uniquement (chmod 600 recommandé).
_GRAPHITI_ENV_FILE="${GRAPHITI_ENV_FILE:-${HOME}/.cursor/mcp-graphiti.env}"
if [[ -f "${_GRAPHITI_ENV_FILE}" ]]; then
  set -a
  # shellcheck source=/dev/null
  source "${_GRAPHITI_ENV_FILE}"
  set +a
  echo "[start-graphiti-mcp] Env chargé depuis ${_GRAPHITI_ENV_FILE}" >&2
else
  echo "[start-graphiti-mcp] ERREUR : ${_GRAPHITI_ENV_FILE} introuvable." >&2
  echo "  Crée ce fichier (copie depuis testttt/.cursor/mcp/mcp-graphiti.env.example) et remplis les clés." >&2
  exit 1
fi

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=/dev/null
source "${SCRIPT_DIR}/lib-litellm-health.sh"

# Repo root = parent of `.cursor/` (portable across clones / machines).
REPO_DIR="$(cd "${SCRIPT_DIR}/../.." && pwd)"
# Graphiti clone: override with GRAPHITI_DIR in mcp-graphiti.env for non-default layouts.
GRAPHITI_DIR="${GRAPHITI_DIR:-${HOME}/graphiti}"
MCP_DIR="${GRAPHITI_DIR}/mcp_server"
CONFIG="${REPO_DIR}/.cursor/mcp/litellm_config.yaml"
PROXY_PORT=4000
export GRAPHITI_LITELLM_PORT="${PROXY_PORT}"
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

if [[ -z "${MOONSHOT_API_KEY:-}" ]]; then
  echo "[start-graphiti-mcp] ERREUR : MOONSHOT_API_KEY vide." >&2
  echo "  Définis-la dans ${_GRAPHITI_ENV_FILE} (ou export avant Cursor)." >&2
  exit 1
fi

if [[ -z "${OPENROUTER_API_KEY:-}" && -z "${OPENAI_EMBEDDING_API_KEY:-}" ]]; then
  echo "[start-graphiti-mcp] AVERTISSEMENT : ni OPENROUTER_API_KEY ni OPENAI_EMBEDDING_API_KEY — embeddings LiteLLM peuvent échouer." >&2
fi

# LiteLLM litellm_config.yaml lit ce base URL pour Moonshot / Kimi (Chine vs international).
export MOONSHOT_API_BASE="${MOONSHOT_API_BASE:-https://api.moonshot.ai/v1}"

# Graphiti mcp_server/config/config.yaml uses OPENAI_API_URL for both LLM and embedder.
# OPENAI_BASE_URL alone is ignored → requests hit api.openai.com with key "litellm-proxy" (401).
if [[ -z "${OPENAI_API_URL:-}" ]]; then
  _base="${OPENAI_BASE_URL:-http://127.0.0.1:4000}"
  _base="${_base%/}"
  if [[ "${_base}" == *"/v1" ]]; then
    export OPENAI_API_URL="${_base}"
  else
    export OPENAI_API_URL="${_base}/v1"
  fi
  echo "[start-graphiti-mcp] OPENAI_API_URL non défini — export OPENAI_API_URL=${OPENAI_API_URL} (dérivé de OPENAI_BASE_URL)" >&2
fi

# Embedder defaults to same OpenAI-compatible base as the LLM unless split explicitly (Willow LLM + LiteLLM embeddings).
if [[ -z "${OPENAI_EMBEDDING_API_URL:-}" ]]; then
  export OPENAI_EMBEDDING_API_URL="${OPENAI_API_URL}"
fi
# Embedder bearer: same as LLM key unless we split LLM (external) vs embeddings (local LiteLLM).
_emb_url="${OPENAI_EMBEDDING_API_URL:-}"
_llm_url="${OPENAI_API_URL:-}"
if [[ -n "${_emb_url}" && -n "${_llm_url}" ]] && \
   [[ "${_emb_url}" == *"127.0.0.1:${PROXY_PORT}"* || "${_emb_url}" == *"localhost:${PROXY_PORT}"* ]] && \
   [[ "${_llm_url}" != *"127.0.0.1:${PROXY_PORT}"* && "${_llm_url}" != *"localhost:${PROXY_PORT}"* ]]; then
  export GRAPHITI_LITELLM_PROXY_KEY="${GRAPHITI_LITELLM_PROXY_KEY:-litellm-proxy}"
  echo "[start-graphiti-mcp] Mode hybride détecté (LLM externe + embeddings :${PROXY_PORT}) — GRAPHITI_LITELLM_PROXY_KEY=${GRAPHITI_LITELLM_PROXY_KEY}" >&2
else
  export GRAPHITI_LITELLM_PROXY_KEY="${GRAPHITI_LITELLM_PROXY_KEY:-${OPENAI_API_KEY:-}}"
fi

echo "[start-graphiti-mcp] litellm=${LITELLM_BIN}  uv=${UV_BIN}" >&2

# Neo4j env alias
if [[ -z "${NEO4J_USER:-}" && -n "${NEO4J_USERNAME:-}" ]]; then
  export NEO4J_USER="${NEO4J_USERNAME}"
fi
GRAPHITI_MCP_ARGS=(--transport stdio --database-provider neo4j)
# Optional: route LLM / embedder to different model IDs (e.g. Willow « Model ID »).
if [[ -n "${GRAPHITI_LLM_MODEL:-}" ]]; then
  GRAPHITI_MCP_ARGS+=(--model "${GRAPHITI_LLM_MODEL}")
  echo "[start-graphiti-mcp] LLM model override: ${GRAPHITI_LLM_MODEL}" >&2
fi
if [[ -n "${GRAPHITI_EMBEDDER_MODEL:-}" ]]; then
  GRAPHITI_MCP_ARGS+=(--embedder-model "${GRAPHITI_EMBEDDER_MODEL}")
  echo "[start-graphiti-mcp] Embedder model override: ${GRAPHITI_EMBEDDER_MODEL}" >&2
fi
echo "[start-graphiti-mcp] Neo4j user=${NEO4J_USER:-?} provider=neo4j (stdio)" >&2

# --- LiteLLM: check if already running before launching ---
if "${CURL_BIN}" -sf "http://127.0.0.1:${PROXY_PORT}/health" > /dev/null 2>&1; then
  echo "[start-graphiti-mcp] LiteLLM répond sur :${PROXY_PORT} — skip démarrage process" >&2
else
  echo "[start-graphiti-mcp] LiteLLM absent — démarrage (attente max ${LITELLM_TIMEOUT}s)..." >&2
  # Cursor injecte OPENAI_* vers localhost pour Graphiti ; si LiteLLM les hérite, le client
  # OpenAI des embeddings ignore api.openai.com du YAML et re-hit le proxy → 403 Kimi.
  _LITELLM_ENV=(env -u DEBUG -u LITELLM_DEBUG -u OPENAI_BASE_URL -u OPENAI_API_URL -u OPENAI_API_BASE)
  if [[ "${LITELLM_BIN}" == "python3 -m litellm" ]]; then
    "${_LITELLM_ENV[@]}" python3 -m litellm --config "${CONFIG}" --port "${PROXY_PORT}" > "${PROXY_LOG}" 2>&1 &
  else
    "${_LITELLM_ENV[@]}" "${LITELLM_BIN}" --config "${CONFIG}" --port "${PROXY_PORT}" > "${PROXY_LOG}" 2>&1 &
  fi
  LITELLM_PID=$!

  for i in $(seq 1 "${LITELLM_TIMEOUT}"); do
    if graphiti_litellm_ready; then
      echo "[start-graphiti-mcp] LiteLLM prêt (chat + embeddings sains) en ${i}s (PID ${LITELLM_PID})" >&2
      break
    fi
    if ! kill -0 "${LITELLM_PID}" 2>/dev/null; then
      echo "[start-graphiti-mcp] ERREUR : LiteLLM terminé." >&2
      tail -n 20 "${PROXY_LOG}" >&2 || true
      exit 1
    fi
    if [[ "${i}" -eq "${LITELLM_TIMEOUT}" ]]; then
      echo "[start-graphiti-mcp] ERREUR : timeout ${LITELLM_TIMEOUT}s — LiteLLM incomplet (chat ou embeddings KO)." >&2
      graphiti_litellm_health_hint >&2 || true
      tail -n 30 "${PROXY_LOG}" >&2 || true
      exit 1
    fi
    sleep 1
  done
fi

if ! graphiti_litellm_ready; then
  echo "[start-graphiti-mcp] ERREUR : LiteLLM sur :${PROXY_PORT} répond mais Graphiti n'a pas chat + embeddings utilisables." >&2
  echo "  Graphiti ne peut pas démarrer tant que le proxy ne répond pas aussi sur /v1/embeddings." >&2
  graphiti_litellm_health_hint >&2 || true
  echo "  Si un vieux LiteLLM tourne avec une mauvaise clé : bash ${REPO_DIR}/.cursor/mcp/stop-litellm.sh puis recharge le MCP Graphiti dans Cursor." >&2
  exit 1
fi

# --- Graphiti MCP server ---
echo "[start-graphiti-mcp] Démarrage Graphiti MCP server..." >&2
cd "${MCP_DIR}" || exit 1
if [[ "${UV_BIN}" == "python3 -m uv" ]]; then
  exec python3 -m uv run python main.py "${GRAPHITI_MCP_ARGS[@]}"
fi
exec "${UV_BIN}" run python main.py "${GRAPHITI_MCP_ARGS[@]}"
