# RUN — terminal Claude (orchestration) + E04 invariants CI

**Date** : 2026-04-23

EXECUTE_DELEGATION: (orchestrateur session Cursor + implémentation directe; pas de subagent Task `foodking-routine-implementer` sur ce lot — scripts shell + workflow CI ciblés)

## Objectif

- Vérifier que l’exécutable **`claude`** (Claude Code) est bien invoquable depuis le terminal.
- Fournir un **wrapper fiable** aligné `AGENTS.md` (`check` / `audit` / `repl`) et **corriger** toute casse (syntaxe bash, heredoc).
- **E04 (méga-checklist)** : brancher `scripts/check-invariants.sh` en CI (visibilité), sans bloquer le merge tant que **E03 / P11** n’a pas remis 6/6 invariants au vert.
- `composer invariants` pour exécution locale identique.

## Résultats

| Vérification | Résultat |
|---|---|
| `bash scripts/foodking-claude-orchestrate.sh check` | **OK** — ex. `2.1.90 (Claude Code)` ; path `~/.local/bin/claude` |
| `composer invariants` | Invoque le script — **exit 1** aujourd’hui (P11 : invariant 4/6 dispatch) — **attendu** |
| `bash -n` sur le wrapper | **OK** après correctif : substitut `$(` + heredoc **interdit** dès qu’un `)` apparaît dans le prompt — remplacé par `IFS= read -r -d '' PROMPT <<'END'` |
| CI | Job **`invariants-grep`** dans `.github/workflows/phpunit.yml` : `continue-on-error: true` — à retirer quand 6/6 |
| Graphiti | `12_decisions_log.jsonl` +1 ligne, ingest `12_decisions` **22/22** sent, 0 fail |

## Fichiers

- `scripts/foodking-claude-orchestrate.sh` (exécutable)
- `AGENTS.md` — paragraphe wrapper + 180 tâches checklist
- `.github/workflows/phpunit.yml` — job `invariants-grep`
- `composer.json` — script `invariants`
- `docs/orchestration/MEGA_CHECKLIST_AUTONOMY_AND_MEMORY.md` — E04 en `[~]`, footer mis à jour

## Prochaine étape (hors ce RUN)

- **E03 / P11** : `ShouldDispatchAfterCommit` sur `OrderCreated` (frozen zones) → puis retirer `continue-on-error` sur l’étape invariants (merge bloqué sur régression invariants).
