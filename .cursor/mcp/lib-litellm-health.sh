# shellcheck shell=bash
# Shared helpers for Graphiti MCP + start-litellm-bg.sh
# LiteLLM returns HTTP 200 on /health even when every upstream model is unhealthy
# (e.g. Moonshot 401). Cursor must wait for at least one healthy model.

graphiti_litellm_port() {
  echo "${GRAPHITI_LITELLM_PORT:-4000}"
}

# Exit 0 if /health JSON reports ready models for Graphiti (healthy_count > 0
# or non-empty healthy_endpoints). Exit 1 otherwise or on network/parse error.
graphiti_litellm_models_healthy() {
  local port json curl_bin
  port="$(graphiti_litellm_port)"
  curl_bin="${CURL_BIN:-}"
  [[ -z "${curl_bin}" ]] && curl_bin="$(command -v curl 2>/dev/null || true)"
  [[ -z "${curl_bin}" && -x /usr/bin/curl ]] && curl_bin="/usr/bin/curl"
  [[ -z "${curl_bin}" ]] && curl_bin="curl"

  if ! json="$("${curl_bin}" -sf "http://127.0.0.1:${port}/health" 2>/dev/null)"; then
    return 1
  fi

  python3 -c '
import json, sys
raw = sys.argv[1]
try:
    d = json.loads(raw)
except json.JSONDecodeError:
    sys.exit(1)
hc = d.get("healthy_count")
if isinstance(hc, int) and hc > 0:
    sys.exit(0)
he = d.get("healthy_endpoints") or []
if isinstance(he, list) and len(he) > 0:
    sys.exit(0)
sys.exit(1)
' "${json}" 2>/dev/null
}

graphiti_litellm_embeddings_healthy() {
  local port json curl_bin
  port="$(graphiti_litellm_port)"
  curl_bin="${CURL_BIN:-}"
  [[ -z "${curl_bin}" ]] && curl_bin="$(command -v curl 2>/dev/null || true)"
  [[ -z "${curl_bin}" && -x /usr/bin/curl ]] && curl_bin="/usr/bin/curl"
  [[ -z "${curl_bin}" ]] && curl_bin="curl"

  if ! json="$("${curl_bin}" -sS --max-time 20 "http://127.0.0.1:${port}/v1/embeddings" \
    -H "Content-Type: application/json" \
    -H "Authorization: Bearer ${GRAPHITI_LITELLM_PROXY_KEY:-litellm-proxy}" \
    -d '{"model":"text-embedding-3-small","input":"foodking graphiti health"}' 2>/dev/null)"; then
    return 1
  fi

  python3 -c '
import json, sys
try:
    d = json.loads(sys.argv[1])
except json.JSONDecodeError:
    sys.exit(1)
if d.get("error"):
    sys.exit(1)
data = d.get("data") or []
emb = data[0].get("embedding") if data and isinstance(data[0], dict) else None
sys.exit(0 if isinstance(emb, list) and len(emb) > 0 else 1)
' "${json}" 2>/dev/null
}

graphiti_litellm_ready() {
  graphiti_litellm_models_healthy && graphiti_litellm_embeddings_healthy
}

# Print a short human-readable hint from /health (first unhealthy error only).
graphiti_litellm_health_hint() {
  local port json curl_bin
  port="$(graphiti_litellm_port)"
  curl_bin="${CURL_BIN:-}"
  [[ -z "${curl_bin}" ]] && curl_bin="$(command -v curl 2>/dev/null || true)"
  [[ -z "${curl_bin}" && -x /usr/bin/curl ]] && curl_bin="/usr/bin/curl"
  [[ -z "${curl_bin}" ]] && curl_bin="curl"
  json="$("${curl_bin}" -sf "http://127.0.0.1:${port}/health" 2>/dev/null)" || {
    echo "(impossible de lire http://127.0.0.1:${port}/health)"
    return
  }
  python3 -c '
import json, sys
try:
    d = json.loads(sys.argv[1])
except json.JSONDecodeError:
    print("(réponse /health non JSON)")
    sys.exit(0)
ue = d.get("unhealthy_endpoints") or []
if not ue:
    print("healthy_count=%s — pas de détail unhealthy_endpoints." % d.get("healthy_count"))
    sys.exit(0)
err = (ue[0] or {}).get("error") or ""
if "401" in err or "Authentication" in err or "Invalid Authentication" in err:
    print("Cause probable : clé API Moonshot invalide ou expirée (401). Mets à jour MOONSHOT_API_KEY dans ~/.cursor/mcp.json puis recharge le MCP.")
else:
    print(err[:400])
' "${json}" 2>/dev/null || true

  if ! graphiti_litellm_embeddings_healthy; then
    echo "Embedding smoke KO : /v1/embeddings text-embedding-3-small ne renvoie pas de vecteur utilisable."
    echo "Vérifie OPENROUTER_API_KEY / OPENAI_EMBEDDING_API_KEY, OPENAI_EMBEDDING_API_URL, et la route embedding LiteLLM."
  fi
}
