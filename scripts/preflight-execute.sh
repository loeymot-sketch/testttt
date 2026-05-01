#!/usr/bin/env bash
# FoodKing — Preflight EXECUTE (garde-fou exécutable AVANT édition produit)
# -----------------------------------------------------------------------------
# Refuse l'EXECUTE si la discipline n'est pas en place.
#
# Modes :
#   --mode=product       (défaut) : édition produit (app/, resources/, routes/, ...)
#                                   → exige réservation `start` active pour TASK + AGENT,
#                                     et que SCOPE déclaré ⊆ scope réservé.
#   --mode=governance    : doc/orchestration/plan/report → exige cohérence ACTIVE_CYCLE
#                          (TASK_ID lu correspond au TASK passé).
#   --mode=read-only     : exit 0 (aucune écriture prévue).
#
# Bypass humain explicite (journalisé, JAMAIS silencieux) :
#   --override="raison libre courte"
#       → exit 0 + ligne EVENT=override dans reports/AGENT_ACTIVITY_LOG.md.
#
# Codes de sortie :
#   0  OK
#   2  collision / réservation manquante
#   3  ACTIVE_CYCLE incohérent
#   4  scope déclaré dépasse scope réservé
#   5  argument manquant / mauvais usage
#
# Usage :
#   bash scripts/preflight-execute.sh <TASK_ID> --scope "f1,f2,dir/"
#   bash scripts/preflight-execute.sh <TASK_ID> --mode=governance
#   bash scripts/preflight-execute.sh <TASK_ID> --override="hotfix humain validé X"
# -----------------------------------------------------------------------------
set -euo pipefail
REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$REPO_ROOT" || exit 1

TASK_ID=""
SCOPE_CSV=""
MODE="product"
OVERRIDE=""
AGENT="${AGENT:-cursor-claude}"

while [[ $# -gt 0 ]]; do
  case "$1" in
    --scope=*)    SCOPE_CSV="${1#--scope=}"; shift ;;
    --scope)      SCOPE_CSV="${2:?scope value required}"; shift 2 ;;
    --mode=*)     MODE="${1#--mode=}"; shift ;;
    --mode)       MODE="${2:?mode value required}"; shift 2 ;;
    --agent=*)    AGENT="${1#--agent=}"; shift ;;
    --override=*) OVERRIDE="${1#--override=}"; shift ;;
    --override)   OVERRIDE="${2:?override reason required}"; shift 2 ;;
    -h|--help)
      sed -n '1,30p' "$0" >&2; exit 0 ;;
    -*)
      echo "preflight: option inconnue: $1" >&2; exit 5 ;;
    *)
      [[ -z "$TASK_ID" ]] && TASK_ID="$1" || { echo "preflight: TASK_ID déjà fourni" >&2; exit 5; }
      shift ;;
  esac
done

if [[ -z "$TASK_ID" ]]; then
  echo "preflight: TASK_ID requis (premier argument)." >&2
  exit 5
fi

LOG="$REPO_ROOT/reports/AGENT_ACTIVITY_LOG.md"
ACTIVE="$REPO_ROOT/.cursor/ACTIVE_CYCLE.md"

ts() { date -u +%Y-%m-%dT%H:%M:%SZ; }

# Override : journalise et sort OK.
if [[ -n "$OVERRIDE" ]]; then
  mkdir -p "$(dirname "$LOG")"
  printf '%s | AGENT=%s | CONV=pid%s | TASK=%s | PHASE=execute | EVENT=override | SCOPE=%s | NOTE=preflight-bypass: %s\n' \
    "$(ts)" "$AGENT" "$$" "$TASK_ID" "${SCOPE_CSV:-N/A}" "$OVERRIDE" >> "$LOG"
  echo "preflight: OVERRIDE accepté (journalisé). Raison : $OVERRIDE"
  exit 0
fi

# Mode read-only : aucune édition prévue, on laisse passer.
if [[ "$MODE" == "read-only" ]]; then
  echo "preflight: mode=read-only → OK (aucune édition prévue)."
  exit 0
fi

# Mode governance : on vérifie juste la cohérence ACTIVE_CYCLE.
if [[ "$MODE" == "governance" ]]; then
  if [[ ! -f "$ACTIVE" ]]; then
    echo "preflight: ACTIVE_CYCLE absent — incohérent." >&2; exit 3
  fi
  active_task=$(awk -F': *' '/^TASK_ID:/ { print $2; exit }' "$ACTIVE" | tr -d ' \r')
  if [[ -n "$active_task" && "$active_task" != "$TASK_ID" ]]; then
    echo "preflight: TASK_ID demandé '$TASK_ID' ≠ ACTIVE_CYCLE '$active_task'." >&2
    echo "          Si nouveau cycle → archiver/reset l'ancien d'abord." >&2
    exit 3
  fi
  echo "preflight: mode=governance, ACTIVE_CYCLE cohérent → OK."
  exit 0
fi

# Mode product (défaut) : exige réservation active scope-aware.
if [[ "$MODE" != "product" ]]; then
  echo "preflight: mode inconnu '$MODE' (attendu: product|governance|read-only)" >&2; exit 5
fi

if [[ -z "$SCOPE_CSV" ]]; then
  echo "preflight: --scope obligatoire en mode=product (csv des fichiers/dossiers à éditer)." >&2
  echo "          Ex : --scope=\"app/Services/X.php,resources/js/components/Y.vue\"" >&2
  exit 5
fi

if [[ ! -f "$LOG" ]]; then
  echo "preflight: $LOG inexistant. Lance d'abord :" >&2
  echo "  bash scripts/agent-activity-log.sh start $AGENT $TASK_ID execute \"$SCOPE_CSV\" \"<note>\"" >&2
  exit 2
fi

# Cherche réservation active pour AGENT+TASK (start sans done).
reserved_scope=$(awk -F' \\| ' -v ag="$AGENT" -v tk="$TASK_ID" '
  /^[0-9]{4}-/ {
    a=""; t=""; e=""; s="";
    for(i=2;i<=NF;i++){
      if(match($i,/^AGENT=/))  a=substr($i,7);
      if(match($i,/^TASK=/))   t=substr($i,6);
      if(match($i,/^EVENT=/))  e=substr($i,7);
      if(match($i,/^SCOPE=/))  s=substr($i,7);
    }
    if (a==ag && t==tk) {
      if (e=="start")        active_scope=s;
      else if (e=="done" || e=="blocked" || e=="abandoned") active_scope="";
    }
  }
  END { print active_scope }
' "$LOG")

if [[ -z "$reserved_scope" ]]; then
  echo "preflight: AUCUNE réservation active pour AGENT=$AGENT TASK=$TASK_ID." >&2
  echo "  Réserve d'abord : bash scripts/agent-activity-log.sh start $AGENT $TASK_ID execute \"$SCOPE_CSV\" \"<note>\"" >&2
  echo "  (ou --override=\"raison\" si exception humaine documentée)" >&2
  exit 2
fi

# Vérifie : chaque path déclaré ⊆ au moins un path du scope réservé.
not_covered=()
IFS=',' read -r -a declared <<<"$SCOPE_CSV"
IFS=',' read -r -a reserved <<<"$reserved_scope"

is_covered() {
  local d="$1" r
  for r in "${reserved[@]}"; do
    [[ -z "$r" ]] && continue
    [[ "$d" == "$r" ]] && return 0
    [[ "$d" == "$r"/* ]] && return 0
  done
  return 1
}

for d in "${declared[@]}"; do
  d="${d// /}"
  [[ -z "$d" ]] && continue
  if ! is_covered "$d"; then
    not_covered+=("$d")
  fi
done

if [[ ${#not_covered[@]} -gt 0 ]]; then
  echo "preflight: SCOPE déclaré dépasse SCOPE réservé." >&2
  printf '  - hors scope: %s\n' "${not_covered[@]}" >&2
  echo "  Scope réservé : $reserved_scope" >&2
  echo "  Élargis la réservation (start avec scope étendu) ou réduis le SCOPE déclaré." >&2
  exit 4
fi

echo "preflight: OK — AGENT=$AGENT TASK=$TASK_ID, SCOPE déclaré couvert par réservation active."
exit 0
