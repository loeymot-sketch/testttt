#!/usr/bin/env bash
# FoodKing — Session opener (agrégation lecture seule)
# -----------------------------------------------------------------------------
# Un seul appel pour qu'un agent (humain ou IA) qui démarre une nouvelle
# conversation ait TOUT le contexte SSOT en une lecture :
#
#   1. Bloc d'ouverture imposé (SESSION_OPENING_ENFORCEMENT.md)
#   2. État du cycle actif (.cursor/ACTIVE_CYCLE.md résumé)
#   3. Réservations actives + 50 dernières lignes du log d'activité
#   4. Vérification environnement (npm run verify:boucle)
#
# IMPORTANT : ce script n'écrit RIEN. Pas de nouveau store. Pas de duplication
# de doctrine. Il agrège des fichiers existants (Store D + bloc texte).
#
# Usage :
#   bash scripts/session-open.sh [--no-verify]
#   npm run session:open
# -----------------------------------------------------------------------------
set -euo pipefail
REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$REPO_ROOT" || exit 1

NO_VERIFY=0
for arg in "$@"; do
  case "$arg" in
    --no-verify) NO_VERIFY=1 ;;
    -h|--help)
      sed -n '1,20p' "$0" >&2; exit 0 ;;
  esac
done

bar() { printf '\n=========================================================================\n%s\n-------------------------------------------------------------------------\n' "$1"; }

bar "1) Bloc d'ouverture (à coller en haut de toute nouvelle conversation)"
if [[ -f docs/orchestration/SESSION_OPENING_ENFORCEMENT.md ]]; then
  cat docs/orchestration/SESSION_OPENING_ENFORCEMENT.md
else
  echo "(absent : docs/orchestration/SESSION_OPENING_ENFORCEMENT.md — créer ce fichier)"
fi

bar "2) Cycle actif — .cursor/ACTIVE_CYCLE.md (extrait clés)"
if [[ -f .cursor/ACTIVE_CYCLE.md ]]; then
  # shellcheck source=./_lib-active-cycle.sh
  source "$REPO_ROOT/scripts/_lib-active-cycle.sh"
  found=0
  for k in TASK_ID PHASE RUNNER_MODE PRIMARY_EXECUTION_MODEL REASONING_EFFORT PRIMARY_MODEL PLAN_FILE REPORT_FILE GATE_FILE; do
    v="$(read_active "$k" .cursor/ACTIVE_CYCLE.md)"
    if [[ -n "$v" ]]; then
      printf "%s: %s\n" "$k" "$v"
      found=1
    fi
  done
  [[ "$found" == "0" ]] && echo "(aucune clé reconnue — fichier vide ou format inattendu)"
else
  echo "(absent : .cursor/ACTIVE_CYCLE.md)"
fi

bar "3) Réservations actives (cross-agent — qui touche quoi maintenant)"
if [[ -x scripts/agent-activity-log.sh ]]; then
  bash scripts/agent-activity-log.sh active 2>/dev/null || echo "(aucune réservation active)"
  echo
  echo "--- 20 dernières lignes du log ---"
  bash scripts/agent-activity-log.sh tail 20 2>/dev/null || true
else
  echo "(scripts/agent-activity-log.sh introuvable ou non exécutable)"
fi

if [[ $NO_VERIFY -eq 0 ]]; then
  bar "4) Environnement (npm run verify:boucle — 0 requête API)"
  if command -v npm >/dev/null 2>&1; then
    npm run --silent verify:boucle 2>&1 || echo "(verify:boucle a échoué — voir sortie ci-dessus)"
  else
    echo "(npm absent du PATH — impossible de vérifier l'environnement)"
  fi
else
  bar "4) Environnement (verify:boucle skippé via --no-verify)"
fi

bar "Prochaines étapes"
cat <<'EOF'
- Si TASK_ID vide ou cycle CLOSED → démarrer : `run-cycle <NEW_TASK_ID>`
- Si TASK_ID en cours        → reprendre la PHASE indiquée (run-cycle.md Step correspondant)
- Avant toute édition produit → `bash scripts/preflight-execute.sh <TASK_ID> --scope "<csv>"`
- Après l'EXÉCUTE             → `bash scripts/post-execute-guard.sh <TASK_ID>`
- À la fin                    → `bash scripts/agent-activity-log.sh done <AGENT> <TASK_ID> done "résumé"`

Documentation : docs/orchestration/COMMAND_DECK.md
EOF
