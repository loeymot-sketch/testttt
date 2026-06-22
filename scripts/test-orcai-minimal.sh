#!/usr/bin/env bash
# Test minimal sur endpoint Anthropic-compatible (ex. orcai.cc) — NE PAS COMMITTER .env.orcai
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

if [[ ! -f ".env.orcai" ]]; then
  echo "Manque .env.orcai — copie depuis .env.orcai.example et renseigne les valeurs (hors git)."
  exit 1
fi

set -a
# shellcheck disable=SC1091
source ".env.orcai"
set +a

BASE="${ANTHROPIC_BASE_URL:-https://api.orcai.cc}"
BASE="${BASE%/}"
MODEL="${ORCAI_MODEL:-claude-opus-4-20250514}"
MAX_OUT="${ORCAI_TEST_MAX_TOKENS:-512}"

export MODEL MAX_OUT
BODY=$(python3 <<'PY'
import json, os
print(json.dumps({
    "model": os.environ["MODEL"],
    "max_tokens": int(os.environ["MAX_OUT"]),
    "messages": [{"role": "user", "content": "Réponds uniquement le mot OK."}],
}))
PY
)

echo "POST ${BASE}/v1/messages  model=${MODEL}  max_tokens=${MAX_OUT}"
curl -sS -w "\nHTTP %{http_code}\n" -X POST "${BASE}/v1/messages" \
  -H "Content-Type: application/json" \
  -H "anthropic-version: 2023-06-01" \
  -H "x-api-key: ${ANTHROPIC_AUTH_TOKEN}" \
  -d "$BODY"
