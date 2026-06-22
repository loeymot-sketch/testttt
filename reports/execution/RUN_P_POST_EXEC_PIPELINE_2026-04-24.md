# RUN — pipeline post-supplémentation (mémoire + terminal + abonnement)

**Date** : 2026-04-24  
**Contexte** : alimentation de base terminée avant phase design POS (demande : enchaîner correctement JSONL, manifeste, Graphiti, terminal Claude **sans** gaspiller l’abonnement).

## Livrables

| Fichier / script | Rôle |
|------------------|------|
| `scripts/after-execute-memory.sh` | Régén manifeste, `memory-jsonl-manifest.sh --check` sur `.files`, rappels `graphiti-ingest` selon `git status`, rappel `verify.py` + enchaînement `context` / `audit-brief`. |
| `scripts/memory-jsonl-manifest.sh` | `--check` : compare **uniquement** la clé `files` (hors `generated_at`) ; **write** : n’écrase pas le fichier sur disque si les SHA sont inchangés. |
| `scripts/foodking-claude-orchestrate.sh` | Sous-commande `post-execute` ; `context` / `post-execute` **sans** exiger `claude` sur le PATH. |
| `AGENTS.md` + `TERMINAL_CLAUDE_…` + `GLOBAL_SYSTEM_PRIMER` §4.2/4.3 | SSOT : ordre d’exécution + rôle de l’abonnement ciblé (`audit-brief` après bref). |
| `.cursor/hooks/post-edit-check.sh` | Rappel `after-execute-memory` sur édition JSONL. |
| `memory/episodes/12_decisions_log.jsonl` + `memory/INDEX.md` | Décision enregistrée, index 12 → 25 lignes. |

**EXECUTE_DELEGATION** : orchestrateur session Cursor (pas de sub-agent exécutor pour ce lot doc/sh).

## Vérifs

- `bash scripts/after-execute-memory.sh` → 0
- `bash scripts/memory-jsonl-manifest.sh --check reports/memory/jsonl_manifest.json` → 0
- Ingest ciblé : `bash bin/graphiti-ingest.sh 12` (recommandé après commit local).
