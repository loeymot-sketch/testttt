# RUN — méga-checklist lot 4 (E06, A05, B17, C02) — 2026-04-24

**Scope** : suite « continue » après P11 invariants + terminal — fermeture compteur checklist + garde-fous CI + doc routage.

## Livrables

| ID | Contenu |
|----|---------|
| **E06** | `vitest.yml` : `on.push.branches` = `[main, develop]` (aligné PRs). |
| **A05** | `phpunit.yml` : step manifest exécute `memory-jsonl-manifest.sh --check reports/memory/jsonl_manifest.json` ; `reports/memory/jsonl_manifest.json` regénéré après +1 ligne dans `12_decisions_log.jsonl`. |
| **B17** | Ligne `EXECUTE_DELEGATION:` sur 3 rapports d’exécution listés B17 (V14, P13, W6 A11Y). |
| **C02** | `docs/orchestration/ROUTING_MATRIX.md` ; pointeurs dans `AGENTS.md` et `docs/orchestration/GLOBAL_SYSTEM_PRIMER.md`. |

## Mémoire

- `memory/INDEX.md` : épisode **12** → **24** lignes.
- `memory/episodes/12_decisions_log.jsonl` : entrée Décision lot 2026-04-24.
- Ingest : `bash bin/graphiti-ingest.sh 12_decisions` — **24/24** envoyés, **0** échecs (local).

## Vérifications locales

- `bash scripts/memory-jsonl-manifest.sh --check reports/memory/jsonl_manifest.json` → **OK**

## Compteur méga-checklist

**34** `[x]` · **3** `[~]` · **143** `[ ]` (total 180 tâches).

**EXECUTE_DELEGATION** : orchestrateur session (Cursor) — alignement `foodking-routine-implementer` sur les tâches purement doc/CI/config de ce lot.
