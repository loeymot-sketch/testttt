#!/usr/bin/env bash
# Même port que le proxy : `LITELLM_PORT=4001 bash test.sh` si besoin
: "${LITELLM_PORT:=4000}"
curl "http://localhost:${LITELLM_PORT}/v1/chat/completions" \
  -H "Authorization: Bearer sk-local-bedrock" \
  -H "Content-Type: application/json" \
  -d '{
    "model": "bedrock-claude-opus",
    "messages": [{"role": "user", "content": "Réponds uniquement OK"}],
    "max_tokens": 512
  }'
