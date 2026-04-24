#!/usr/bin/env bash
# FoodKing — Simulation de bout en bout (boucle) : pré-vol + CLI codex + mini mission.
# - Étape A : verify:boucle (0 requête API claude / codex en mode défaut) — binaire claude requis.
# - Étape B (sauf si INCLUDE_FULL_VERIFY) : 1× codex:smoke
# - Étape A2 (si INCLUDE_FULL_VERIFY=1) : verify:boucle:full — puis sauter B (déjà codex dans full)
# - Étape C : 1× mission BOUCLE-E2E-001 (RAW) — preuve d’exécution output_codex.json
# Usage: npm run boucle:e2e
#        INCLUDE_FULL_VERIFY=1 npm run boucle:e2e
set -Eeo pipefail
set -u
REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$REPO_ROOT" || exit 1
OUT_RE="${REPO_ROOT}/reports/execution/BOUCLE_E2E_LAST_RUN.txt"
mkdir -p "$(dirname "$OUT_RE")"

run() {
  echo "=========================================="
  echo "FoodKing — BOUCLE E2E (simulation pratique)"
  echo "REPO: $REPO_ROOT"
  echo "date: $(date -u +%Y-%m-%dT%H:%M:%SZ)"
  echo "=========================================="
  echo

  echo ">>> [A] Pré-boucle : npm run verify:boucle (0 requête claude / codex en défaut verify)"
  if npm run verify:boucle --prefix "$REPO_ROOT" 2>&1; then
    echo ">>> [A] OK"
  else
    echo ">>> [A] ÉCHEC (exit: $?) — vérifier claude sur PATH"
    return 1
  fi
  echo

  if [[ "${INCLUDE_FULL_VERIFY:-0}" == "1" ]]; then
    echo ">>> [A2] verify:boucle:full (claude smoketest + codex:smoke) — consomme un petit quota"
    if npm run verify:boucle:full --prefix "$REPO_ROOT" 2>&1; then
      echo ">>> [A2] OK — [B] skippé (déjà codex:smoke dans full)"
    else
      echo ">>> [A2] Avertissement: extrémité claude et/ou codex"
      return 3
    fi
    echo
  else
    echo ">>> [B] Connecteur OpenAI (codex) : npm run codex:smoke"
    if npm run codex:smoke --prefix "$REPO_ROOT" 2>&1; then
      echo ">>> [B] OK"
    else
      echo ">>> [B] ÉCHEC — auth codex: npm run codex:verify-pro ; doc agents/codex-extension-instructions.md"
      return 2
    fi
    echo
  fi

  MIS="BOUCLE-E2E-001"
  MIS_DIR="$REPO_ROOT/missions/$MIS"
  if [[ ! -f "$MIS_DIR/input.json" ]]; then
    echo ">>> [C] Fichier mission manquant: $MIS_DIR/input.json"
    return 3
  fi

  echo ">>> [C] Mini EXECUTE (CLI) : npm run codex:complex -- $MIS"
  if npm run codex:complex --prefix "$REPO_ROOT" -- "$MIS" 2>&1; then
    echo ">>> [C] OK"
  else
    echo ">>> [C] ÉCHEC (codex exec) — auth / scopes: voir agents/codex-extension-instructions.md"
    return 4
  fi

  OUT_JSON="$MIS_DIR/output_codex.json"
  if [[ -f "$OUT_JSON" ]]; then
    echo
    echo ">>> Sortie (extrait) :"
    head -n 8 "$OUT_JSON" || true
    echo "..."
    wc -c "$OUT_JSON" | awk '{print ">>> octets: " $1}'
  fi

  echo
  echo "=========================================="
  echo "RÉSULTAT: boucle démo — prereq + CLI codex + mission OK"
  echo "Journal: $OUT_RE"
  echo "=========================================="
  return 0
}
run 2>&1 | tee "$OUT_RE"
exit "${PIPESTATUS[0]}"
