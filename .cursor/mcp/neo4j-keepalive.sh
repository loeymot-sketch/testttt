#!/bin/bash
# FoodKing — Neo4j Aura keepalive
# Pings the AuraDB Free instance every 48h to prevent the 3-day idle auto-pause
# (and the 30-day-paused auto-delete that follows).
#
# Scheduled via ~/Library/LaunchAgents/com.foodking.neo4j-keepalive.plist
# Logs to /tmp/neo4j-keepalive.log

set -uo pipefail

_GRAPHITI_ENV_FILE="${GRAPHITI_ENV_FILE:-${HOME}/.cursor/mcp-graphiti.env}"
_PYTHON_BIN="${HOME}/graphiti/mcp_server/.venv/bin/python"
_TIMESTAMP="$(date -u +'%Y-%m-%dT%H:%M:%SZ')"

if [[ ! -f "${_GRAPHITI_ENV_FILE}" ]]; then
  echo "[${_TIMESTAMP}] ERROR: env file missing: ${_GRAPHITI_ENV_FILE}" >&2
  exit 1
fi

if [[ ! -x "${_PYTHON_BIN}" ]]; then
  echo "[${_TIMESTAMP}] ERROR: python venv missing: ${_PYTHON_BIN}" >&2
  exit 1
fi

set -a
# shellcheck source=/dev/null
source "${_GRAPHITI_ENV_FILE}"
set +a

"${_PYTHON_BIN}" - <<'PYEOF'
import os, sys
from neo4j import GraphDatabase

uri  = os.environ["NEO4J_URI"]
user = os.environ.get("NEO4J_USERNAME") or os.environ["NEO4J_USER"]
pwd  = os.environ["NEO4J_PASSWORD"]
db   = os.environ.get("NEO4J_DATABASE", "neo4j")

driver = GraphDatabase.driver(uri, auth=(user, pwd))
try:
    with driver.session(database=db) as s:
        rec = s.run("RETURN 1 AS ok, datetime() AS now").single()
        print(f"OK ok={rec['ok']} now={rec['now']} db={db} uri={uri}")
finally:
    driver.close()
PYEOF

_EXIT=$?
if [[ "${_EXIT}" -eq 0 ]]; then
  echo "[${_TIMESTAMP}] keepalive SUCCESS"
else
  echo "[${_TIMESTAMP}] keepalive FAILED exit=${_EXIT}" >&2
fi
exit "${_EXIT}"
