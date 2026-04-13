#!/usr/bin/env sh
# FoodKing bot — minimal bootstrap (Unix). Does not start a daemon.
set -e
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
mkdir -p "$ROOT/state" "$ROOT/logs"
echo "bot root: $ROOT"
echo "state/ and logs/ ensured. Copy bot/config/*.example.json to real configs when ready."
# Optional: resolve FOODKING_REPO_ROOT to repo parent if bot/ is nested; caller may export explicitly.
exit 0
