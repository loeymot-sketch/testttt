#!/usr/bin/env bash
# Après chaque supplémentation (ADR, invariant, rôle agent, JSONL…)
# -----------------------------------------------------------------------------
# 1) Régénère reports/memory/jsonl_manifest.json (obligatoire si memory/episodes a bougé).
# 2) Vérifie cohérence (même logique que CI).
# 3) Indique quels `bin/graphiti-ingest.sh <filtre>` lancer selon `git status`.
# 4) Rappel verify.py (optionnel) + chaîne terminal Claude (context + audit-brief).
#
# Usage (depuis la racine du dépôt) :
#   bash scripts/after-execute-memory.sh
#   bash scripts/after-execute-memory.sh --no-verify  # ne lance pas --check (déconseillé)
# -----------------------------------------------------------------------------
set -euo pipefail
REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$REPO_ROOT" || exit 1

do_check=1
if [[ "${1:-}" == "--no-verify" ]]; then
  do_check=0
fi

echo "[after-execute-memory] 1/4 — Régénération du manifeste JSONL (SHA)…"
bash scripts/memory-jsonl-manifest.sh

if [[ $do_check -eq 1 ]]; then
  echo "[after-execute-memory] 2/4 — Vérif: jsonl ↔ manifeste (comme CI phpunit)…"
  if ! bash scripts/memory-jsonl-manifest.sh --check reports/memory/jsonl_manifest.json; then
    echo "[after-execute-memory] ÉCHEC : le manifeste ne correspond pas. Corriger puis recommencer." >&2
    exit 1
  fi
  echo "[after-execute-memory]   OK: manifeste aligné."
else
  echo "[after-execute-memory] 2/4 — (skip --check, mode --no-verify)"
fi

echo "[after-execute-memory] 3/4 — Ingestion Graphiti (rappel par fichier modifié / non suivi) :"
if command -v git >/dev/null 2>&1 && git -C "$REPO_ROOT" rev-parse --show-toplevel >/dev/null 2>&1; then
  jsonl_list=()
  jsonl_out()
  {
    ( cd "$REPO_ROOT" && {
      git diff --name-only HEAD
      git diff --name-only --cached
      git ls-files -o --exclude-standard -- "memory/episodes"
    } 2>/dev/null | sort -u | grep 'memory/episodes/.*\.jsonl$' || true
    )
  }
  while IFS= read -r rel; do
    [[ -n "$rel" ]] && jsonl_list+=("$rel")
  done < <(jsonl_out)
  if [[ ${#jsonl_list[@]} -eq 0 || -z "${jsonl_list[0]:-}" ]]; then
    echo "  (aucun .jsonl diffèrent de HEAD côté git) — la mémoire disque est propre; après une édition manuelle, relance quand c'est en working tree."
  else
    for rel in "${jsonl_list[@]}"; do
      base=$(basename "$rel" .jsonl)
      filt="02"
      if [[ "$base" =~ ^([0-9]{2})_ ]]; then
        filt="${BASH_REMATCH[1]}"
      fi
      echo "  →  bash bin/graphiti-ingest.sh $filt   # $rel"
    done
  fi
else
  echo "  (git indisponible) — Après changement d'un .jsonl :  bash bin/graphiti-ingest.sh <préfixe numérique ou sous-chaîne>."
fi

echo
echo "[after-execute-memory] 4/4 — Optionnel (abonnement / Graphiti) :"
echo "  python3 memory/verify.py   # fume Graphiti local (neo4j + MCP) si la stack tourne"
echo "  bash scripts/foodking-claude-orchestrate.sh audit-brief  # lit le bref disque + audit claude -p (crédits ciblés)"
echo "  (déjà fait ci-dessus : manifeste + rappel ingest) — le bref disque :  bash scripts/foodking-claude-orchestrate.sh context"
echo
echo "[after-execute-memory] Terminé. Committe memory/episodes/*.jsonl et reports/memory/jsonl_manifest.json dans le même lot (CI A05 --check)."
