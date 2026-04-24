#!/usr/bin/env bash
# Résout le binaire `codex` (CLI OpenAI) sans imposer le PATH global.
# Ordre : $CODEX_BIN (si exécutable) → `codex` sur PATH → $REPO_ROOT/node_modules/.bin/codex
# Le dépôt peut dépendre de : npm i -D @openai/codex  (binaire local)
# Usage (depuis un autre script bash) :
#   source "$REPO_ROOT/scripts/codex-resolve-bin.sh"
#   B="$(codex_resolved_bin)" || { echo "..."; exit 1; }
# -----------------------------------------------------------------------------

# Répertoire racine du dépôt (au moment du *source* de ce fichier)
if [[ -z "${__FK_CODEX_RESOLVE_ROOT:-}" ]]; then
  __FK_CODEX_RESOLVE_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
fi

codex_resolved_bin() {
  local repo="${REPO_ROOT:-$__FK_CODEX_RESOLVE_ROOT}"
  if [[ -n "${CODEX_BIN:-}" ]]; then
    if [[ -x "$CODEX_BIN" ]]; then
      echo "$CODEX_BIN"
      return 0
    fi
    if command -v "$CODEX_BIN" >/dev/null 2>&1; then
      command -v "$CODEX_BIN"
      return 0
    fi
  fi
  if command -v codex >/dev/null 2>&1; then
    command -v codex
    return 0
  fi
  local local_bin="$repo/node_modules/.bin/codex"
  if [[ -x "$local_bin" ]]; then
    echo "$local_bin"
    return 0
  fi
  return 1
}
