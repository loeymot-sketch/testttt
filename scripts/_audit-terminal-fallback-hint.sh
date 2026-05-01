#!/usr/bin/env bash
# Message stderr commun : repli audit quand le terminal Claude (-p) échoue ou est indisponible.
# Sourçable : source "$REPO_ROOT/scripts/_audit-terminal-fallback-hint.sh"
# Usage : audit_terminal_fallback_hint_stderr [fichier_log_optionnel]
audit_terminal_fallback_hint_stderr() {
  local logfile="${1:-}"
  local root="${REPO_ROOT:-$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)}"
  echo "" >&2
  echo "[FoodKing] === Repli AUDIT / mini-audit — terminal Claude indisponible ou erreur (-p) ===" >&2
  echo "[FoodKing] Ne pas arrêter le cycle : même checklist via Task **foodking-planner-orchestrator** (recommandé)." >&2
  echo "[FoodKing] Traces : AUDIT_CHANNEL: cursor-session + AUDIT_FALLBACK_REASON: <1 ligne>" >&2
  echo "[FoodKing] Optionnel : AUDIT_SUBAGENT_FALLBACK: foodking-planner-orchestrator" >&2
  echo "[FoodKing] Doc : $root/docs/orchestration/AUDIT_TERMINAL_QUOTA_FALLBACK.md" >&2
  if [[ -n "$logfile" && -f "$logfile" ]] && grep -Eiq 'rate.?limit|429|quota|exhausted|capacity|usage.?limit|billing|overload|throttl' "$logfile" 2>/dev/null; then
    echo "[FoodKing] (hint) Indices quota / rate-limit dans la sortie — repli planner-orchestrator prioritaire." >&2
  fi
}
