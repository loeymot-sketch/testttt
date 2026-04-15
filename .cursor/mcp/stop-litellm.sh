#!/bin/bash
# Arrête le proxy LiteLLM persistant lancé par start-litellm-bg.sh
PID_FILE="/tmp/litellm-foodking.pid"
if [[ -f "${PID_FILE}" ]]; then
  PID=$(cat "${PID_FILE}")
  if kill -0 "${PID}" 2>/dev/null; then
    kill "${PID}" && echo "✓ LiteLLM (PID ${PID}) arrêté."
  else
    echo "PID ${PID} déjà terminé."
  fi
  rm -f "${PID_FILE}"
else
  pkill -f "litellm.*4000" 2>/dev/null && echo "✓ LiteLLM arrêté (pkill)." || echo "Rien à arrêter."
fi
