# RUN — P11 / E03 / E04 : invariants 6/6 + terminal alimenté

**Date** : 2026-04-23

EXECUTE_DELEGATION: orchestrateur Cursor (implémentation directe — pas de `foodking-routine-implementer` sur ce lot ; logique frozen + script CI + doc)

## Objectif

- Faire passer **`scripts/check-invariants.sh` en 6/6** (E03) et **CI invariants bloquante** (E04).
- Documenter **Claude Code terminal** vs sub-agents vs Graphiti, et fournir **`context` + `audit-brief`** pour économie de tokens.

## Constats

1. **`OrderCreated` / `OrderStatusChanged`** : classes avec trait `DispatchableAfterCommit` — le grep « 5 lignes au-dessus = `DB::afterCommit(` » listait des **faux positifs** sur `::dispatch()`.
2. **Bug réel** : `FrontendOrderService` (annulation Kiosk) utilisait `event(new \App\Events\OrderStatusChanged(...))` — **ne passe pas** par `OrderStatusChanged::dispatch()` → contournait le différé commit.

## Changements

| Fichier | Rôle |
|---------|------|
| `scripts/check-invariants.sh` | Nouveau filtre `filter_dispatchableaftercommit_traits` |
| `app/Services/FrontendOrderService.php` | `OrderStatusChanged::dispatch(..., (int) $request->status)` |
| `.github/workflows/phpunit.yml` | `invariants-grep` : **retrait** de `continue-on-error` |
| `scripts/foodking-claude-orchestrate.sh` | `context`, `audit-brief`, fonction `write_terminal_context_brief` |
| `docs/orchestration/TERMINAL_CLAUDE_GRAPHITI_ORCHESTRATION.md` | Modèle mental terminal / MCP / sub-agent |
| `AGENTS.md` + `GLOBAL_SYSTEM_PRIMER.md` + `MEGA_CHECKLIST_*` | Pointers E03/E03 + 180 tâches |

## Vérifications

- `bash scripts/check-invariants.sh` → **6/6 OK**, exit 0
- `composer invariants` → exit 0
- `php artisan test tests/Feature/DispatchAfterCommitTest.php` → 6 passed
- `php artisan test tests/Unit/Services/FrontendOrderServiceTest.php` → 5 passed
- `bash scripts/foodking-claude-orchestrate.sh context` → génère `reports/audit/_TERMINAL_CONTEXT_BRIEF.md`

## Graphiti

- Ligne ajoutée dans `memory/episodes/12_decisions_log.jsonl` (ingest ciblé : `12_decisions`).
