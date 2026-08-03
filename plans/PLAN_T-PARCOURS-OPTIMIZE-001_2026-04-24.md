# PLAN — T-PARCOURS-OPTIMIZE-001 — Optimisation parcours obligatoire (qualité-positive)

**Date** : 2026-04-24
**TASK_ID** : `T-PARCOURS-OPTIMIZE-001`
**PRIMARY_MODEL** : `gpt-5.4` via `codex-terminal` (pour la rédaction intelligente)
**Auditeur** : `claude-terminal` (PRIMARY) avec fallback session
**Cycle** : `run-cycle T-PARCOURS-OPTIMIZE-001`

---

## Objectif

Réduire le **coût démarrage de session** (parcours obligatoire) de **~35 k tokens** à **~22 k tokens** **sans amputer aucune règle** ni dégrader la qualité de gouvernance.

## Constat (audit 2026-04-24)

| Surface | Tokens actuels | Type |
|---|---:|---|
| `.cursor/rules/*.mdc` (alwaysApply) | ~7 470 | Récurrent par tour — incompressible |
| `AGENTS.md` | ~7 510 | Première lecture — *gros mais utile* |
| `GLOBAL_SYSTEM_PRIMER.md` | ~2 680 | OK |
| `run-cycle.md` | ~2 825 | OK |
| `ACTIVE_CYCLE.md` | **~11 435** | **85% = cycles COMPLETED → archive** |
| `MEMORY_MATRIX` + `routing` | ~3 345 | OK |
| **TOTAL** | **~35 265** | |

## Modifications (qualité-positive uniquement, **rien d'amputé**)

### M1 — Split `.cursor/ACTIVE_CYCLE.md` (gain ≈ 9 000 tokens, 0 perte)

- **Garder** : entête `ACTIVE_PRIMARY` (l. 1-5) + cycle `CYCLE_W10_EXECUTION_CLOSEOUT` IN_PROGRESS (l. 6-17) + pointeur 1-ligne vers archive.
- **Déplacer vers** `.cursor/ACTIVE_CYCLE_ARCHIVE.md` (nouveau) : tous les cycles `COMPLETED PASSED` (W9, W9_NF525, ainsi que W4-W8 si présents).
- **Garantie** : index humain et CI inchangés (les rapports `reports/audit/AUDIT_*` restent canoniques).

### M2 — `AGENTS.md` : ajouter `## 0. Quick start contract` en tête (gain de **lisibilité**, 0 perte)

- **Ne supprime rien.** Ajoute une mini-section ~40 lignes en position 0 :
  - Liste **des 5 sections strictement nécessaires** pour démarrer un cycle borné en production (Parcours obligatoire, Model Roles, Authoritative cycle, Stop Conditions, MCP).
  - Liste des **sections de référence** (Legacy workflow, Implementation rules…) à lire **uniquement quand pertinent**.
  - Donne le **chemin court** (« si vous savez déjà ce qu'est FoodKing, lisez §3+§5+§7+§9 et ouvrez `run-cycle.md` »).
- **Effet** : un agent intelligent peut prioriser sa lecture sans perdre de gouvernance ; un humain garde l'intégralité.

## Hors scope

- **Pas** de modification des `.cursor/rules/*.mdc` (déjà bien dimensionnés).
- **Pas** de modification de `GLOBAL_SYSTEM_PRIMER.md`, `run-cycle.md`, `routing.md`, `MEMORY_MATRIX.md`, `CODEX_API_DELEGATION.md`.
- **Pas** d'amputation d'aucun chapitre existant d'`AGENTS.md`.

## Délégation

- **EXECUTE PRIMARY** : `codex-terminal` (gpt-5.4) — pour la **rédaction du Quick start contract** (intelligence requise : choisir les bonnes sections prioritaires, formuler concis et inattaquable).
- **EXECUTE local mécanique** : split `ACTIVE_CYCLE.md` exécuté ici (Cursor) — split déterministe sans valeur ajoutée IA.
- **AUDIT PRIMARY** : `claude-terminal` (`bash scripts/foodking-claude-orchestrate.sh audit-brief T-PARCOURS-OPTIMIZE-001`).

## Critères de validation

1. `wc -c .cursor/ACTIVE_CYCLE.md` < 6 000 (était 45 739).
2. `.cursor/ACTIVE_CYCLE_ARCHIVE.md` contient tous les cycles `COMPLETED PASSED` retirés (rg `CYCLE_W` doit retourner les mêmes IDs cumulés).
3. `AGENTS.md` ouvre par `## 0. Quick start contract` ; toutes les sections existantes (3..fin) sont préservées byte-pour-byte (`diff` sur les lignes 18+ après l'insertion).
4. `npm run verify:boucle` reste vert ; `bash scripts/agent-activity-log.sh tail 5` montre la réservation puis sa libération.
5. Recompte tokens parcours : objectif **< 22 000 tokens**.

## Traces obligatoires

- `EXECUTE_DELEGATION: codex-terminal` (pour M2) + `EXECUTE_DELEGATION: cursor-direct` (pour M1).
- `AUDIT_CHANNEL: claude-terminal` + `TERMINAL_AUDIT_OK: 1` (si claude OK), sinon `AUDIT_CHANNEL: cursor-session` + `AUDIT_FALLBACK_REASON:`.
- `agent-activity-log.sh start` au début EXECUTE, `done` au CLOSE.
- Décision durable → `memory/episodes/12_decisions_log.jsonl` + regen manifeste.
