#!/usr/bin/env bash
# FoodKing — Claude Code (Anthropic) terminal orchestration wrapper
# -----------------------------------------------------------------------------
# SSOT procédurale : AGENTS.md section « Terminal allies — Claude Code »
#
# Vérifier que l’outil est disponible (bloquant si manquant) :
#   bash scripts/foodking-claude-orchestrate.sh check
#
# Test abonnement / auth API (1 requête minimale — consomme un peu de quota) :
#   bash scripts/foodking-claude-orchestrate.sh smoketest
#
# Audit / planification non interactif (consomme les quotas compte Anthropic) :
#   bash scripts/foodking-claude-orchestrate.sh audit
#   bash scripts/foodking-claude-orchestrate.sh audit "ton prompt personnalisé ici"
#   Note : en mode -p, stdin est redirigé depuis /dev/null (obligatoire dans un terminal
#   non interactif / agent — sinon Claude affiche « no stdin data » et échoue).
#
# Session interactive (lance le REPL claude) :
#   bash scripts/foodking-claude-orchestrate.sh repl
#
# Contexte disque (économie tokens) — ACTIVE_CYCLE + 12_decisions + INDEX → fichier pour Claude :
#   bash scripts/foodking-claude-orchestrate.sh context
#   bash scripts/foodking-claude-orchestrate.sh audit-brief
#
# Après une livraison (sans imposer l’audit payant) — alimentation mémoire + bref :
#   bash scripts/foodking-claude-orchestrate.sh post-execute
#   (enchaîne scripts/after-execute-memory.sh + _TERMINAL_CONTEXT_BRIEF ; ne requiert pas le binaire claude)
# -----------------------------------------------------------------------------
set -euo pipefail

REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$REPO_ROOT" || exit 1

# Écrit reports/audit/_TERMINAL_CONTEXT_BRIEF.md ; affiche le chemin absolu sur stdout.
write_terminal_context_brief() {
  mkdir -p "$REPO_ROOT/reports/audit"
  local f="$REPO_ROOT/reports/audit/_TERMINAL_CONTEXT_BRIEF.md"
  {
    echo "# Bref contexte FoodKing — alimentation terminal Claude Code"
    echo
    echo "Généré : $(date -Iseconds 2>/dev/null || date)"
    echo
    echo "## .cursor/ACTIVE_CYCLE.md (extrait, 60 premières lignes)"
    head -n 60 "$REPO_ROOT/.cursor/ACTIVE_CYCLE.md" 2>/dev/null || echo "(fichier absent)"
    echo
    echo "## Dernières entrées — memory/episodes/12_decisions_log.jsonl"
    tail -n 5 "$REPO_ROOT/memory/episodes/12_decisions_log.jsonl" 2>/dev/null || echo "(fichier absent)"
    echo
    echo "## memory/INDEX.md (début)"
    head -n 32 "$REPO_ROOT/memory/INDEX.md" 2>/dev/null || true
    echo
    echo "## Rappel"
    echo "- Neo4j/Graphiti : **pas** branché sur ce script ; lire \`memory/INDEX.md\` + JSONL, ou le MCP \`search_memory_facts\` dans **Cursor**."
    echo "- Ce fichier évite de recoller tout le chat : **réutilisé** par \`audit-brief\`."
    echo
    echo "## Post-implémentation (ordre — alimentation base + abonnement utile)"
    echo "1) \`bash scripts/after-execute-memory.sh\` — manifeste + rappel \`graphiti-ingest\` (si JSONL touchés)."
    echo "2) \`bash $REPO_ROOT/scripts/foodking-claude-orchestrate.sh context\` (ce bref) ; option utile 3) \`audit-brief\` = audit claude -p ciblé."
  } > "$f"
  echo "$f"
}

usage() {
  echo "Usage: $0 {check|smoketest|context|audit-brief|post-execute|audit|repl|help} [args...]" >&2
  echo "  check        — verify that Anthropic 'claude' (Claude Code) is on PATH" >&2
  echo "  smoketest    — one minimal claude -p call; confirms subscription/auth (uses API)" >&2
  echo "  context         — write reports/audit/_TERMINAL_CONTEXT_BRIEF.md (cycle + décisions + index mémoire)" >&2
  echo "  audit-brief     — claude -p: lit d'abord ce fichier, puis audit orchestration (Graphiti = doc, pas requête live)" >&2
  echo "  post-execute    — after livraison: after-execute-memory (manifest+ingest rappel) + context (pas audit-brief)" >&2
  echo "  audit           — claude -p with default FoodKing prompt, or: audit \"custom prompt\"" >&2
  echo "  repl            — interactive: exec claude (same as typing 'claude' in the repo root)" >&2
  echo "  help         — this message" >&2
  exit 2
}

if [[ $# -lt 1 ]]; then
  usage
fi

cmd=$1
shift

case "$cmd" in
  help|-h|--help)
    usage
    ;;
esac

# context / post-execute n'exigent pas le binaire claude (mémoire + fichiers seuls)
case "$cmd" in
  check|context|brief|post-execute|after-delivery|after-execute|smoketest|audit|audit-brief|audit-with-context|repl|shell|i) ;;
  *)
    usage
    ;;
esac

if [[ "$cmd" != "post-execute" && "$cmd" != "after-delivery" && "$cmd" != "after-execute" && "$cmd" != "context" && "$cmd" != "brief" ]]; then
  if ! command -v claude >/dev/null 2>&1; then
    echo "[foodking-claude-orchestrate] ERROR: binaire 'claude' (Claude Code) introuvable sur PATH."
    echo "  1) Cursor : Extensions → « Claude Code » (Anthropic) → Installer" >&2
    echo "  2) Ou installation CLI Anthropic, puis s'assurer que ~/.local/bin (ou l'équivalent) est dans le PATH" >&2
    echo "  3) Doc SSOT : AGENTS.md — « Anthropic **Claude Code** »" >&2
    echo "  4) Sans claude, tu peux lancer:  $0 post-execute  ou  $0 context" >&2
    exit 1
  fi
fi

case "$cmd" in
  check)
    ver_line=$(claude --version 2>&1 | head -1)
    path_line=$(command -v claude)
    echo "[foodking-claude-orchestrate] OK: ${ver_line}"
    echo "[foodking-claude-orchestrate] path: ${path_line}"
    echo "[foodking-claude-orchestrate] Next:  bash $0 repl   (session interactive)"
    echo "[foodking-claude-orchestrate]   ou:  bash $0 smoketest  (test abonnement API)"
    echo "[foodking-claude-orchestrate]   ou:  bash $0 context  (fichier + audit-brief = intelligence sans gaspiller tokens)"
    echo "[foodking-claude-orchestrate]   ou:  bash $0 audit  (passe d'orchestration non interactive, -p)"
    echo "[foodking-claude-orchestrate]   ou:  bash $0 post-execute  (after-execute-memory + context, sans audit-brief)"
    ;;
  context|brief)
    f=$(write_terminal_context_brief)
    echo "[foodking-claude-orchestrate] Contexte disque : $f"
    echo "[foodking-claude-orchestrate] Ensuite :  $0 audit-brief"
    ;;
  audit-brief|audit-with-context)
    write_terminal_context_brief >/dev/null
    # Chemin relatif = résolvable par Claude via --add-dir
    exec claude -p "Tu es l'orchestrateur FoodKing (rôle AGENTS.md). Lis EN PREMIER le fichier reports/audit/_TERMINAL_CONTEXT_BRIEF.md (cycle, dernières décisions JSONL, index mémoire). Neo4j/Graphiti n'est pas requêtable ici : traite ce fichier comme alimentation mémoire. Ensuite, si utile, ouvre AGENTS.md et .cursor/ACTIVE_CYCLE.md. Tâche : audit court en français, puces : cycle actif, 5 prochaines priorités parmi la méga-checklist [ ], rappel EXECUTE_DELEGATION sur les RUN, ce qui devrait devenir une ligne 12_decisions + ingest. Pas de code dans les frozen zones." --add-dir "$REPO_ROOT" </dev/null
    ;;
  post-execute|after-delivery|after-execute)
    if [[ -f "$REPO_ROOT/scripts/after-execute-memory.sh" ]]; then
      bash "$REPO_ROOT/scripts/after-execute-memory.sh" "$@"
    else
      echo "[foodking-claude-orchestrate] ERROR: scripts/after-execute-memory.sh introuvable." >&2
      exit 1
    fi
    f=$(write_terminal_context_brief)
    echo "[foodking-claude-orchestrate] post-execute: mémoire + bref $f"
    echo "[foodking-claude-orchestrate] optionnel (crédits) :  $0 audit-brief"
    ;;
  smoketest|ping|auth-test)
    # Sans stdin, claude -p se plaint en TTY partiel / agent — /dev/null force le mode « prompt seul ».
    out=$(claude -p "Reply with exactly the single word: TERMINAL_OK" --add-dir "$REPO_ROOT" </dev/null 2>&1) || {
      echo "[foodking-claude-orchestrate] ERROR: claude -p a échoué (auth, réseau, ou quota). Sortie:" >&2
      echo "$out" >&2
      exit 1
    }
    if echo "$out" | grep -q "TERMINAL_OK"; then
      echo "[foodking-claude-orchestrate] OK: abonnement / auth API — réponse contient TERMINAL_OK"
      echo "$out" | head -5
    else
      echo "[foodking-claude-orchestrate] WARN: sortie inattendue (attendu TERMINAL_OK) :" >&2
      echo "$out" >&2
      exit 1
    fi
    ;;
  audit|orchestrate)
    if [[ $# -ge 1 ]]; then
      PROMPT="$*"
    else
      # Pas de $() ici : tout parenthèse ou « 1) » dans le texte casserait l’analyseur bash.
      IFS= read -r -d '' PROMPT <<'FOODKING_ORCH_EOF' || true
Tu es l'orchestrateur FoodKing (équivalent au rôle décrit dans AGENTS.md : plan, audit, pas d'implémentation applicative ici).
Utilise tes outils de lecture sur ce dépôt (workspace trusté) pour consulter au minimum :
- AGENTS.md
- .cursor/ACTIVE_CYCLE.md
- docs/orchestration/MEGA_CHECKLIST_AUTONOMY_AND_MEMORY.md
- docs/orchestration/GLOBAL_SYSTEM_PRIMER.md
Tâche de sortie, en français, puces courtes :
1) Cycle actif : nom + PHASE d'après ACTIVE_CYCLE
2) Les 5 prochaines tâches les plus importantes parmi les items encore [ ] de la méga-checklist (priorité P0 / P1)
3) Un rappel sur la sentinel EXECUTE_DELEGATION sur reports/**/RUN_*.md
4) Si des décisions durables doivent aller dans memory/episodes/*.jsonl + Graphiti, le dire explicitement
Ne propose pas de patch code dans les frozen zones (OrderService, etc.) : orchestration seule.
FOODKING_ORCH_EOF
    fi
    # -p: print mode. Prompt doit suivre -p (sinon claude: « Input must be provided »). </dev/null = agent non TTY.
    exec claude -p "$PROMPT" --add-dir "$REPO_ROOT" </dev/null
    ;;
  repl|shell|i)
    exec claude
    ;;
  help|-h|--help)
    usage
    ;;
  *)
    usage
    ;;
esac
