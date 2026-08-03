#!/usr/bin/env bash
# FoodKing — Graphiti memory ingestion wrapper.
#
# Usage:
#   bash bin/graphiti-ingest.sh                  # ingère tous les memory/episodes/*.jsonl
#   bash bin/graphiti-ingest.sh 03_domain        # filtre par sous-chaîne du nom de fichier
#   DRY_RUN=1 bash bin/graphiti-ingest.sh        # n'envoie rien, affiche seulement
#
# Pré-requis :
#   - ~/.cursor/mcp-graphiti.env contient les clés Neo4j / LLM
#   - python3 ≥ 3.10 disponible
#   - litellm installé (le start script vérifie)

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"
INGESTER="${REPO_ROOT}/memory/ingest.py"

if [[ ! -f "${INGESTER}" ]]; then
  echo "[graphiti-ingest] ERREUR : ${INGESTER} introuvable." >&2
  exit 1
fi

if ! command -v python3 >/dev/null 2>&1; then
  echo "[graphiti-ingest] ERREUR : python3 introuvable." >&2
  exit 1
fi

cd "${REPO_ROOT}"

export PYTHONUNBUFFERED=1

# Si memory/ingest.env existe, on l'utilise pour override la config Cursor (Willow → OpenRouter etc.)
INGEST_ENV_FILE="${REPO_ROOT}/memory/ingest.env"
if [[ -f "${INGEST_ENV_FILE}" ]]; then
  export GRAPHITI_ENV_FILE="${INGEST_ENV_FILE}"
  echo "[graphiti-ingest] using override env: ${INGEST_ENV_FILE}"
fi

exec python3 "${INGESTER}" "$@"
