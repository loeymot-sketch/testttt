#!/usr/bin/env bash
# FoodKing — Post-execute guard (vérification AVANT VALIDATE)
# -----------------------------------------------------------------------------
# Objectif : empêcher de basculer en VALIDATE si l'EXECUTE qui vient de tourner
# n'a pas laissé les preuves attendues. C'est ici (pas en preflight) que la
# discipline EXECUTE_DELEGATION + scope-respecté doit s'imposer.
#
# Vérifie :
#   1. Si `git diff --name-only HEAD` (changements non commités) OU
#      `git diff --name-only` montre des changements produit, alors :
#        a. Le `reports/post_execute_latest.log` doit contenir `EXECUTE_DELEGATION:`
#           OU le `RUN_FILE` (--run) si fourni.
#        b. Les fichiers modifiés DOIVENT être couverts par la réservation
#           active de cet AGENT pour ce TASK_ID dans `reports/AGENT_ACTIVITY_LOG.md`.
#
#   2. Sinon (aucun fichier modifié) : exit 0.
#
# Codes :
#   0  OK
#   1  EXECUTE_DELEGATION manquant
#   4  diff hors scope réservé
#   5  argument manquant
#
# Usage :
#   bash scripts/post-execute-guard.sh <TASK_ID> [--agent=cursor-claude] [--run=reports/.../RUN_X.md]
# -----------------------------------------------------------------------------
set -euo pipefail
REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$REPO_ROOT" || exit 1

TASK_ID=""
AGENT="${AGENT:-cursor-claude}"
RUN_FILE=""

while [[ $# -gt 0 ]]; do
  case "$1" in
    --agent=*) AGENT="${1#--agent=}"; shift ;;
    --agent)   AGENT="${2:?agent value required}"; shift 2 ;;
    --run=*)   RUN_FILE="${1#--run=}"; shift ;;
    --run)     RUN_FILE="${2:?run path required}"; shift 2 ;;
    -h|--help) sed -n '1,25p' "$0" >&2; exit 0 ;;
    -*)        echo "post-execute-guard: option inconnue: $1" >&2; exit 5 ;;
    *)         [[ -z "$TASK_ID" ]] && TASK_ID="$1" || { echo "TASK_ID déjà fourni" >&2; exit 5; }; shift ;;
  esac
done

[[ -z "$TASK_ID" ]] && { echo "post-execute-guard: TASK_ID requis." >&2; exit 5; }

# 1. Liste des fichiers modifiés (staged + unstaged + untracked tracked-able)
# bash 3.2 macOS-friendly (pas de mapfile)
changed=()
while IFS= read -r line; do
  # format porcelain: XY filename — strip 3 premiers chars
  fname="${line:3}"
  # déguillemète si nécessaire
  fname="${fname%\"}"
  fname="${fname#\"}"
  [[ -n "$fname" ]] && changed+=("$fname")
done < <(git status --porcelain 2>/dev/null || true)

if [[ ${#changed[@]} -eq 0 ]]; then
  echo "post-execute-guard: aucun changement détecté (git status propre) → OK."
  exit 0
fi

echo "post-execute-guard: ${#changed[@]} fichier(s) modifié(s) — vérifications…"

# 2. Vérifie EXECUTE_DELEGATION dans post_execute_latest.log OU RUN_FILE explicite.
delegation_proof=0
candidates=()
[[ -f reports/post_execute_latest.log ]] && candidates+=("reports/post_execute_latest.log")
[[ -n "$RUN_FILE" && -f "$RUN_FILE" ]] && candidates+=("$RUN_FILE")

for c in "${candidates[@]}"; do
  if grep -q '^EXECUTE_DELEGATION:' "$c" 2>/dev/null || grep -q 'EXECUTE_DELEGATION:' "$c" 2>/dev/null; then
    delegation_proof=1
    echo "  ✓ EXECUTE_DELEGATION trouvé dans $c"
    break
  fi
done

if [[ $delegation_proof -eq 0 ]]; then
  echo "post-execute-guard: ÉCHEC — aucune ligne 'EXECUTE_DELEGATION:' trouvée." >&2
  echo "  Cherché dans :" >&2
  printf '    - %s\n' "${candidates[@]:-(aucun candidat)}" >&2
  echo "  Ajoute la ligne dans le RUN_FILE / post_execute_latest.log courant :" >&2
  echo "    EXECUTE_DELEGATION: codex-extension|foodking-complex-implementer (codex-extension-fallback)|..." >&2
  exit 1
fi

# 3. Vérifie que chaque fichier modifié est dans le scope réservé.
LOG="$REPO_ROOT/reports/AGENT_ACTIVITY_LOG.md"
if [[ ! -f "$LOG" ]]; then
  echo "post-execute-guard: pas de log d'activité — impossible de vérifier scope." >&2
  exit 4
fi

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
  echo "post-execute-guard: aucune réservation active pour AGENT=$AGENT TASK=$TASK_ID." >&2
  echo "  Toute modif sans réservation = violation discipline." >&2
  exit 4
fi

IFS=',' read -r -a reserved <<<"$reserved_scope"
out_of_scope=()
for f in "${changed[@]}"; do
  in=0
  for r in "${reserved[@]}"; do
    [[ -z "$r" ]] && continue
    if [[ "$f" == "$r" ]] || [[ "$f" == "$r"/* ]]; then in=1; break; fi
  done
  [[ $in -eq 0 ]] && out_of_scope+=("$f")
done

if [[ ${#out_of_scope[@]} -gt 0 ]]; then
  echo "post-execute-guard: ÉCHEC — fichier(s) modifié(s) HORS scope réservé." >&2
  printf '  - %s\n' "${out_of_scope[@]}" >&2
  echo "  Scope réservé : $reserved_scope" >&2
  echo "  Soit ces modifs sont accidentelles (revert), soit étendre la réservation." >&2
  exit 4
fi

echo "post-execute-guard: OK — délégation prouvée + ${#changed[@]} fichier(s) tous dans scope."
exit 0
