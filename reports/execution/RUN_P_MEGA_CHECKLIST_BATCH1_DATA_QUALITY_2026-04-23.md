# RUN — MEGA_CHECKLIST lot 1 (qualité documentaire / données)

**EXECUTE_DELEGATION:** foodking-routine-implementer  
**TASK_ID:** P_MEGA_CHECKLIST_BATCH1_DATA_QUALITY_2026-04-23  
**Date:** 2026-04-23

## Fichiers modifiés (remplacements par sous-tâche)

### Sous-tâche 1 — `foodking-invariants` → `project-invariants` (référence fichier)

| Fichier | Remplacements |
|---------|----------------|
| `.cursor/rules/global.mdc` | 2 |
| `.cursor/rules/project-continuity.mdc` | 1 |
| `.cursor/agents/app-planner-orchestrator.md` | 1 |
| `.cursor/agents/app-routine-implementer.md` | 1 |
| `docs/orchestration/GLOBAL_SYSTEM_PRIMER.md` | 2 |
| `tasks/execute-2026-04-20/01_EXECUTE_P11_RETURNED_IDEMPOTENCY.md` | 2 |
| **Total sous-tâche 1** | **9** |

### Sous-tâche 2 — `IdempotencyTest` → `IdempotencyBranchScopedTest`

| Fichier | Remplacements |
|---------|----------------|
| `memory/episodes/10_tests_coverage.jsonl` | 2 |

### Sous-tâche 3 — `ACTIVE_CYCLE.md` (bannière B03 + en-tête HOTFIX)

| Fichier | Modification |
|---------|----------------|
| `.cursor/ACTIVE_CYCLE.md` | 1 bloc inséré (2 lignes + ligne vide) + 1 en-tête section remplacé |

### Sous-tâche 4 — `project-handoff` SKILL (alwaysApply)

| Fichier | Remplacements |
|---------|----------------|
| `.cursor/skills/project-handoff/SKILL.md` | 1 |

---

## Vérifications post-édit

### Sous-tâche 1 — Grep `foodking-invariants` (dépôt)

Commande: recherche de la sous-chaîne `foodking-invariants` hors ce rapport.

**Résultat attendu** : plus de correspondance dans les 6 cibles corrigées ; persistance **uniquement** dans :

- `memory/episodes/12_decisions_log.jsonl` (conservation volontaire du contexte d’audit)
- `reports/audit/MEGA_AUDIT_SYSTEM_OPERATION_AGENTIC_2026-04-23.md`
- `docs/orchestration/MEGA_CHECKLIST_AUTONOMY_AND_MEMORY.md`

*(Ce fichier RUN peut citer le terme dans la narration ci-dessus ; les sources canoniques d’inventaire du bug restent les trois chemins listés.)*

### Sous-tâche 2 — Grep `IdempotencyTest` dans `10_tests_coverage.jsonl`

- `IdempotencyTest` : **aucune** occurrence dans `memory/episodes/10_tests_coverage.jsonl`
- `IdempotencyBranchScopedTest` : **2** occurrences (lignes 2 et 5, champs `episode_body`)

### Sous-tâche 3 — Grep `IN_PROGRESS|IN PROGRESS` dans `.cursor/ACTIVE_CYCLE.md`

- **1** ligne concordante : l’en-tête `CYCLE_W10_EXECUTION_CLOSEOUT (IN_PROGRESS — …)`  
- L’ancien en-tête HOTFIX en « IN PROGRESS » a été remplacé par `CLOSED_PENDING_CI_MONITORING — …`

---

- [x] LOT 1 livré
