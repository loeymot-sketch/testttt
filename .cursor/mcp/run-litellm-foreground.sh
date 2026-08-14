#!/bin/bash
# FoodKing — LiteLLM foreground runner for launchd.
# Unlike start-litellm-bg.sh, this process stays in the foreground so launchd
# can supervise and restart the actual proxy instead of looping a bootstrapper.

set -euo pipefail

export PATH="/Library/Frameworks/Python.framework/Versions/3.13/bin:/opt/homebrew/bin:/opt/homebrew/sbin:/usr/local/bin:/usr/bin:/bin:/usr/sbin:/sbin:${HOME}/.local/bin:${HOME}/.cargo/bin:${PATH}"
unset DEBUG LITELLM_DEBUG 2>/dev/null || true

_GRAPHITI_ENV_FILE="${GRAPHITI_ENV_FILE:-${HOME}/.cursor/mcp-graphiti.env}"
if [[ -f "${_GRAPHITI_ENV_FILE}" ]]; then
  set -a
  # shellcheck source=/dev/null
  source "${_GRAPHITI_ENV_FILE}"
  set +a
else
  echo "[run-litellm-foreground] ERREUR : ${_GRAPHITI_ENV_FILE} introuvable." >&2
  exit 1
fi

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_DIR="$(cd "${SCRIPT_DIR}/../.." && pwd)"
CONFIG="${REPO_DIR}/.cursor/mcp/litellm_config.yaml"
PROXY_PORT="${GRAPHITI_LITELLM_PORT:-4000}"

if [[ -z "${MOONSHOT_API_KEY:-}" ]]; then
  echo "[run-litellm-foreground] ERREUR : MOONSHOT_API_KEY vide." >&2
  exit 1
fi

export MOONSHOT_API_BASE="${MOONSHOT_API_BASE:-https://api.moonshot.ai/v1}"

if command -v litellm &>/dev/null; then
  LITELLM_CMD=(litellm)
elif python3 -c "import litellm" &>/dev/null; then
  LITELLM_CMD=(python3 -m litellm)
else
  echo "[run-litellm-foreground] ERREUR : litellm introuvable. Installe : pip3 install 'litellm[proxy]' fastembed" >&2
  exit 1
fi

echo "[run-litellm-foreground] exec ${LITELLM_CMD[*]} --config ${CONFIG} --port ${PROXY_PORT}" >&2
exec env -u DEBUG -u LITELLM_DEBUG -u OPENAI_BASE_URL -u OPENAI_API_URL -u OPENAI_API_BASE \
  "${LITELLM_CMD[@]}" --config "${CONFIG}" --port "${PROXY_PORT}"
