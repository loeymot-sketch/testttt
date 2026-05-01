#!/usr/bin/env bash
# FoodKing — **Pont terminal** : depuis une session **Codex** (`exec` ou TUI) qui a le droit de lancer du bash,
# appeler l’audit **Claude** (même contrat que `AGENTS.md` — binaire `claude` + wrapper).
#
# Usage (depuis la racine du dépôt) :
#   bash scripts/codex-invoke-claude-audit.sh "Lis reports/audit/FOO.md. Tâche : synthèse P0, français."
#
# Tout l’intelligence est dans le **prompt** ; ici = routage fiable (cwd + claude -p).
set -euo pipefail
REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$REPO_ROOT" || exit 1
if [[ $# -lt 1 ]]; then
  echo "Usage: $0 'prompt passé à foodking-claude-orchestrate.sh audit' [...args concaténés]" >&2
  exit 2
fi
exec bash "$REPO_ROOT/scripts/foodking-claude-orchestrate.sh" audit "$*"
