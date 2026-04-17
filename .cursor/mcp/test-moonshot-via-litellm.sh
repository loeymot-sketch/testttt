#!/bin/bash
# Teste le chaînage : client HTTP → LiteLLM (:4000) → **Moonshot/Kimi** (chat).
# Indépendant de Graphiti et des embeddings OpenAI.
#
# Usage:
#   bash .cursor/mcp/test-moonshot-via-litellm.sh
#
# Prérequis : LiteLLM sur le port (ex. `bash .cursor/mcp/start-litellm-bg.sh`).

set -euo pipefail

export PATH="/opt/homebrew/bin:/opt/homebrew/sbin:/usr/local/bin:/usr/bin:/bin:/usr/sbin:/sbin:${HOME}/.local/bin:${HOME}/.cargo/bin:${PATH}"
unset DEBUG LITELLM_DEBUG 2>/dev/null || true

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=/dev/null
source "${SCRIPT_DIR}/lib-litellm-health.sh"

export PROXY_URL="${LITELLM_HTTP:-http://127.0.0.1:4000}"
export GRAPHITI_LITELLM_PORT="${GRAPHITI_LITELLM_PORT:-4000}"
# Même clé factice que Graphiti → LiteLLM (voir ~/.cursor/mcp.json)
API_KEY="${OPENAI_API_KEY:-litellm-proxy}"
# Modèle « catalogue » LiteLLM → routé vers moonshot dans litellm_config.yaml
MODEL="${LITELLM_TEST_CHAT_MODEL:-gpt-4o-mini}"

echo "== 1) Health ${PROXY_URL}/health"
if ! curl -sf "${PROXY_URL}/health" > /dev/null; then
  echo "ERREUR: rien ne répond sur ${PROXY_URL}. Démarre LiteLLM puis réessaie." >&2
  exit 1
fi
python3 - <<'PY'
import json, os, sys, urllib.request
base = os.environ["PROXY_URL"].rstrip("/")
with urllib.request.urlopen(base + "/health", timeout=20) as r:
    d = json.load(r)
hc = d.get("healthy_count") or 0
uc = len(d.get("unhealthy_endpoints") or [])
print(f"   healthy_count={hc}  unhealthy_endpoints={uc}")
if hc < 1:
    print("ERREUR: aucun modèle sain sur le proxy (vérifie MOONSHOT_API_KEY / MOONSHOT_API_BASE).", file=sys.stderr)
    sys.exit(2)
PY

echo "== 2) POST ${PROXY_URL}/v1/chat/completions (model=${MODEL} → Kimi/Moonshot)"
TMP="$(mktemp)"
EMB_TMP="$(mktemp)"
trap 'rm -f "${TMP}" "${EMB_TMP}"' EXIT
export MODEL
curl -sS "${PROXY_URL}/v1/chat/completions" \
  -H "Authorization: Bearer ${API_KEY}" \
  -H "Content-Type: application/json" \
  -d "$(python3 -c 'import json,os; print(json.dumps({"model":os.environ["MODEL"],"messages":[{"role":"user","content":"Réponds un seul mot: PONG"}],"max_tokens":24}))')" \
  -o "${TMP}"

python3 - <<PY
import json, os, sys
path = "${TMP}"
with open(path) as f:
    raw = f.read()
try:
    d = json.loads(raw)
except json.JSONDecodeError:
    print("ERREUR: réponse non-JSON:", raw[:600], file=sys.stderr)
    sys.exit(3)
if "error" in d:
    print("ERREUR API:", json.dumps(d["error"], ensure_ascii=False)[:900], file=sys.stderr)
    sys.exit(4)
choices = d.get("choices") or []
if not choices:
    print("ERREUR: pas de choices:", raw[:600], file=sys.stderr)
    sys.exit(5)
msg = (choices[0].get("message") or {}).get("content") or ""
print("   Contenu:", repr(msg.strip()[:200]))
if not str(msg).strip():
    sys.exit(6)
print("")
print("OK — Kimi/Moonshot répond via LiteLLM (chat).")
PY

echo ""
echo "== 3) (Optionnel) POST ${PROXY_URL}/v1/embeddings — souvent OpenAI, pas Kimi"
HTTP="$(curl -sS -o "${EMB_TMP}" -w "%{http_code}" "${PROXY_URL}/v1/embeddings" \
  -H "Authorization: Bearer ${API_KEY}" \
  -H "Content-Type: application/json" \
  -d '{"model":"text-embedding-3-small","input":"ping"}' || true)"
if [[ "${HTTP}" == "200" ]]; then
  echo "   Embeddings: HTTP 200"
else
  echo "   Embeddings: HTTP ${HTTP:-?} (normal si quota OpenAI / clé absente — **sans lien** avec le test Kimi ci-dessus)"
fi

echo ""
echo "Si l’étape 2 affiche « OK », Moonshot/Kimi est **fonctionnel** derrière LiteLLM."
echo "Les erreurs Graphiti sur search_* concernent en général **embeddings**, pas ce chat."
