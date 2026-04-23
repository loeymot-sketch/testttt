#!/usr/bin/env bash
# FoodKing — Cross-agent activity log (append-only)
# -----------------------------------------------------------------------------
# Une seule SSOT pour savoir qui touche quoi en parallèle (Cursor convs, Codex
# terminal, Claude terminal). Évite que deux agents modifient les mêmes fichiers
# à leur insu. Coût tokens ≈ tail 50 lignes (<2 KB) à l'ouverture de session.
#
# Store : extension du store D de docs/orchestration/MEMORY_MATRIX.md (rapports/cycle).
# Pas un nouveau store — append-only, lecture par tail uniquement.
#
# Usage :
#   bash scripts/agent-activity-log.sh tail [N=50]
#       affiche les N dernières lignes (lecture obligatoire en début de session)
#
#   bash scripts/agent-activity-log.sh start <AGENT> <TASK_ID> <PHASE> <SCOPE> [NOTE]
#       réclame un périmètre. Refuse (exit 2) si un autre agent a une réservation
#       active (start sans done) qui chevauche un fichier de SCOPE.
#       SCOPE = liste de chemins séparés par virgules (fichiers OU dossiers).
#       PHASE ∈ {plan, execute, validate, audit, close, manual}.
#
#   bash scripts/agent-activity-log.sh done <AGENT> <TASK_ID> [STATUS=done] [NOTE]
#       libère la réservation (STATUS ∈ {done, blocked, abandoned}).
#
#   bash scripts/agent-activity-log.sh collisions <SCOPE>
#       liste (sans écrire) les réservations actives qui chevauchent SCOPE.
#
#   bash scripts/agent-activity-log.sh active
#       liste toutes les réservations actives (start sans done).
#
# Format ligne (pipe-séparé, stable, parsable) :
#   <ISO8601_UTC> | AGENT=<name> | CONV=<short> | TASK=<id> | PHASE=<phase> | EVENT=<start|done|blocked|abandoned> | SCOPE=<csv> | NOTE=<text>
#
# Convention CONV : si CURSOR_CONV_ID ou similaire absent, on prend $$ (PID) — suffisant pour matcher start/done dans la même session.
# -----------------------------------------------------------------------------
set -euo pipefail
REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$REPO_ROOT" || exit 1

LOG="$REPO_ROOT/reports/AGENT_ACTIVITY_LOG.md"
mkdir -p "$(dirname "$LOG")"

if [[ ! -f "$LOG" ]]; then
  cat > "$LOG" <<'EOF'
# FoodKing — Agent Activity Log (append-only)

Voir `scripts/agent-activity-log.sh` (usage) et `docs/orchestration/MEMORY_MATRIX.md` (store D).
Lecture obligatoire (tail -n 50) au démarrage de toute session multi-agent.
Pas de modification rétroactive : append-only, séquentiel, sans lock disque.

EOF
fi

ts() { date -u +%Y-%m-%dT%H:%M:%SZ; }
conv_id() { printf '%s' "${CURSOR_CONV_ID:-${CONV_ID:-pid$$}}"; }

normalize_csv() {
  # supprime espaces, garde virgules, dédupe
  printf '%s' "$1" | tr -d ' ' | awk -F, '{
    n=split($0,a,",");
    for(i=1;i<=n;i++) if(a[i]!="" && !(a[i] in seen)) { seen[a[i]]=1; printf (out?",":"") a[i]; out=1 }
    print ""
  }' | head -n1
}

# Renvoie 0 si chemins se chevauchent (un préfixe de l'autre ou identiques).
overlaps() {
  local a="$1" b="$2"
  [[ "$a" == "$b" ]] && return 0
  [[ "$a" == "$b"/* ]] && return 0
  [[ "$b" == "$a"/* ]] && return 0
  return 1
}

# Imprime les lignes start sans done correspondant — format pipe brut.
# Pairing par AGENT+TASK (le CONV reste informatif : un agent qui replante peut
# toujours libérer sa réservation depuis un autre PID/conv).
list_active() {
  awk -F' \\| ' '
    /^[0-9]{4}-/ {
      ts=$1; agent=""; task=""; event="";
      for(i=2;i<=NF;i++){
        if(match($i,/^AGENT=/))  agent=substr($i,7);
        if(match($i,/^TASK=/))   task=substr($i,6);
        if(match($i,/^EVENT=/))  event=substr($i,7);
      }
      key=agent"|"task;
      if(event=="start"){ active[key]=$0; }
      else if(event=="done" || event=="blocked" || event=="abandoned"){ delete active[key]; }
    }
    END { for(k in active) print active[k] }
  ' "$LOG"
}

# Renvoie SCOPE d'une ligne pipe brute.
scope_of_line() {
  printf '%s' "$1" | awk -F' \\| ' '{ for(i=1;i<=NF;i++) if(match($i,/^SCOPE=/)) print substr($i,7) }'
}

cmd_tail() {
  local n="${1:-50}"
  tail -n "$n" "$LOG"
}

cmd_active() {
  list_active
}

cmd_collisions() {
  local scope_csv; scope_csv="$(normalize_csv "${1:?SCOPE required}")"
  local found=0
  while IFS= read -r line; do
    [[ -z "$line" ]] && continue
    local other; other="$(scope_of_line "$line")"
    IFS=',' read -r -a my <<<"$scope_csv"
    IFS=',' read -r -a th <<<"$other"
    for a in "${my[@]}"; do
      for b in "${th[@]}"; do
        if overlaps "$a" "$b"; then
          echo "COLLISION: $a <-> $b"
          echo "  $line"
          found=1
        fi
      done
    done
  done < <(list_active)
  [[ $found -eq 0 ]] && echo "no active collision for: $scope_csv" >&2
  return 0
}

cmd_start() {
  local agent="${1:?AGENT required}"
  local task="${2:?TASK_ID required}"
  local phase="${3:?PHASE required}"
  local scope="${4:?SCOPE required}"
  local note="${5:-}"
  scope="$(normalize_csv "$scope")"

  # Détecte collisions
  local hit=0
  while IFS= read -r line; do
    [[ -z "$line" ]] && continue
    local other_agent_task
    other_agent_task="$(printf '%s' "$line" | awk -F' \\| ' '{
      for(i=1;i<=NF;i++){
        if(match($i,/^AGENT=/))  a=substr($i,7);
        if(match($i,/^TASK=/))   t=substr($i,6);
      } print a"|"t }')"
    local me="$agent|$task"
    [[ "$other_agent_task" == "$me" ]] && continue  # ma propre réservation (même AGENT+TASK) : skip
    local other; other="$(scope_of_line "$line")"
    IFS=',' read -r -a my <<<"$scope"
    IFS=',' read -r -a th <<<"$other"
    for a in "${my[@]}"; do
      for b in "${th[@]}"; do
        if overlaps "$a" "$b"; then
          echo "COLLISION: $a chevauche réservation active de $other_agent_task" >&2
          echo "  $line" >&2
          hit=1
        fi
      done
    done
  done < <(list_active)

  if [[ $hit -ne 0 ]]; then
    echo >&2
    echo "REFUS: réservation impossible. Soit attendre la libération (cmd done de l'autre agent)," >&2
    echo "soit choisir un SCOPE non-chevauchant, soit forcer manuellement après accord humain." >&2
    exit 2
  fi

  printf '%s | AGENT=%s | CONV=%s | TASK=%s | PHASE=%s | EVENT=start | SCOPE=%s | NOTE=%s\n' \
    "$(ts)" "$agent" "$(conv_id)" "$task" "$phase" "$scope" "$note" >> "$LOG"
  echo "OK reserved: $scope"
}

cmd_done() {
  local agent="${1:?AGENT required}"
  local task="${2:?TASK_ID required}"
  local status="${3:-done}"
  local note="${4:-}"
  case "$status" in
    done|blocked|abandoned) ;;
    *) echo "STATUS doit être done|blocked|abandoned" >&2; exit 2;;
  esac
  printf '%s | AGENT=%s | CONV=%s | TASK=%s | PHASE=- | EVENT=%s | SCOPE=- | NOTE=%s\n' \
    "$(ts)" "$agent" "$(conv_id)" "$task" "$status" "$note" >> "$LOG"
  echo "OK released: $agent/$task ($status)"
}

case "${1:-}" in
  tail)        shift; cmd_tail "${1:-50}";;
  active)      shift || true; cmd_active;;
  collisions)  shift; cmd_collisions "${1:-}";;
  start)       shift; cmd_start "$@";;
  done)        shift; cmd_done "$@";;
  ""|help|-h|--help)
    sed -n '1,40p' "$0" >&2
    exit 0;;
  *) echo "commande inconnue: $1" >&2; exit 2;;
esac
