# Plan – TEAM-WORKFLOW-2026-04-25

## TASK_ID
TEAM-WORKFLOW-2026-04-25

## PRIMARY_MODEL
Claude (orchestrateur — gouvernance/docs/scripts, **zéro** code produit FoodKing)

## INTENT (vision utilisateur — pas technique)

Faire fonctionner les agents comme une **vraie équipe** :

1. **Claude (chef)** ouvre la session, lit le contexte (ACTIVE_CYCLE, Graphiti, mémoire), écrit un **plan détaillé** + une **ta-da liste** de sous-tâches numérotées avec **difficulté** (`routine` / `complex`) et **invariants à risque** par sous-tâche.
2. **Pour chaque sous-tâche** (rituel répété) :
   - Claude pioche la prochaine `[ ]` non cochée.
   - Choix d'exécutant **automatique selon la difficulté** : `routine` → GPT-5.5 *standard* (ou sub-agent routine) ; `complex` → GPT-5.5 *pro* high/x-high (codex-extension PRIMARY).
   - Implémentation.
   - **Auto-audit GPT** (déjà : `reports/audit/GPT_SELF_AUDIT_{TASK}_{SUB}.md`).
   - **Mini-audit Claude** (terminal `claude -p`, ciblé sur la sous-tâche) → verdict `PASS` / `REWORK_SUB`.
   - Si `PASS` → coche la sous-tâche dans le plan + log.
   - Si `REWORK_SUB` → retour exécutant (max 3 retries / sous-tâche) puis HUMAN_GATE.
3. **Quand toute la liste est cochée** → **audit global Claude terminal** (vue d'ensemble vs plan initial).
   - `PASS` → CLOSE.
   - `REWORK` → Claude **réinjecte** des sous-tâches dans la liste (boucle 1..5, déjà existant via `REMEDIATION_AUDIT_CYCLE`).
4. **Toute l'équipe partage** : Graphiti (B), missions/ (C), plans+reports (D), AGENT_ACTIVITY_LOG (procédural). Chaque agent voit où en est l'équipe.
5. **Dashboard** (1 commande humaine) : montre pour la tâche active la liste, le statut par sous-tâche, qui l'a faite, status dual-audit, et état des autres agents en parallèle.

## SUBSYSTEMS_TOUCHED
| Subsystem | Scope | Read/Write | branch_id | Dispatch |
|---|---|---|---|---|
| `docs/orchestration/TEAM_WORKFLOW.md` | nouveau doc — règle d'équipe en langage utilisateur + diagrammes | Write | No | No |
| `plans/PLAN_TEMPLATE.md` | enrichir avec section `## SUBTASKS` (table difficulté/exécutant/dual-audit) | Write | No | No |
| `scripts/team-status.sh` | nouveau dashboard équipe (lit ACTIVE_CYCLE + plan + activity log + cycles parallèles) | Write | No | No |
| `scripts/team-run-task.sh` | nouveau runner par sous-tâche (route difficulté → exécute → demande mini-audit) | Write | No | No |
| `scripts/team-audit-subtask.sh` | nouveau wrapper mini-audit Claude par sous-tâche | Write | No | No |
| `scripts/team-audit-global.sh` | nouveau wrapper audit global Claude (déjà couvert partiellement par `foodking-claude-orchestrate.sh audit` — wrapper sémantique) | Write | No | No |
| `.cursor/commands/run-cycle.md` | référencer le team workflow comme pattern par défaut pour les tâches multi-sous-tâches | Write | No | No |
| `package.json` | scripts `team:status`, `team:run`, `team:audit:sub`, `team:audit:global` | Write | No | No |
| `docs/orchestration/COMMAND_DECK.md` | ajouter la section "Team workflow" | Write | No | No |
| `missions/TEAM-WORKFLOW-2026-04-25/` | input.json + plan_excerpt.md pour le second avis GPT | Write | No | No |

## SUBSYSTEMS_OFF_LIMITS
- `app/`, `resources/`, `routes/`, `database/`, `tests/`, `bootstrap/`, `config/`, `composer.json` — **aucun** code FoodKing produit dans ce cycle.
- Frozen zones — N/A.

## INVARIANTS_AT_RISK
- None (méta-orchestration uniquement).

## GATE_CONDITIONS
- None anticipated.

## SUBTASKS — ta-da liste (exemple de format à introduire dans PLAN_TEMPLATE)

| # | Description | Difficulté | Exécutant prévu | Invariants risque | Mini-audit | Status |
|---|---|---|---|---|---|---|
| 1 | Doc TEAM_WORKFLOW.md (vision utilisateur + 2 diagrammes Mermaid) | routine | claude (manuel) | None | claude self-check | [ ] |
| 2 | Enrichir PLAN_TEMPLATE.md avec section SUBTASKS | routine | claude (manuel) | None | claude self-check | [ ] |
| 3 | scripts/team-status.sh (dashboard) | routine | claude (manuel) | None | claude self-check | [ ] |
| 4 | scripts/team-run-task.sh (router difficulté → exécute) | routine | claude (manuel) | None | claude self-check | [ ] |
| 5 | scripts/team-audit-subtask.sh (mini-audit) | routine | claude (manuel) | None | claude self-check | [ ] |
| 6 | scripts/team-audit-global.sh (wrapper audit global) | routine | claude (manuel) | None | claude self-check | [ ] |
| 7 | Wire npm scripts + run-cycle.md + COMMAND_DECK | routine | claude (manuel) | None | claude self-check | [ ] |
| 8 | Second avis GPT-5.5 pro sur le plan | n/a | codex-extension complex | None | n/a | [ ] |
| 9 | Audit global terminal (claude -p) | n/a | claude-terminal | None | n/a | [ ] |

> Note : ce cycle est entièrement **gouvernance/docs/scripts** ; pour valider le pattern « routine vs complex routing », un cycle d'application réel (TASK séparé) servira de smoke test après merge.

## Execution Steps
1. Préparer mission `missions/TEAM-WORKFLOW-2026-04-25/` (input.json + plan_excerpt.md court).
2. Lancer `npm run codex:complex -- TEAM-WORKFLOW-2026-04-25` pour **second avis GPT-5.5 pro**. Lire `output_codex.json` + `GPT_SELF_AUDIT_*.md`.
3. Fusionner retour GPT dans plan v2 (cette section, suffixée `## v2 corrections`).
4. Implémenter sous-tâches 1..7 (routine, manuel — Claude orchestrateur écrit lui-même).
5. Audit global terminal : `bash scripts/foodking-claude-orchestrate.sh audit-brief` ciblé sur ce cycle.
6. Si PASS → CLOSE. Si REWORK → réinjecter sous-tâches (max 5 cycles).

## SYMMETRY_NOTE
N/A.

## SCOPE_PRESSURE
(vide à plan-time)

## ESCALATION
(vide à plan-time)

## EXECUTE_DELEGATION
- Sous-tâches 1..7 : Claude (manuel, gouvernance/docs/scripts) — pas de code produit FoodKing → routine simple.
- Sous-tâche 8 : `codex-extension` (second avis).
- Sous-tâche 9 : `claude-terminal` (audit global).

## v2 corrections (intégration second avis GPT-5.5 pro)

### Angles morts v1 → corrections concrètes appliquées en v2

1. **État sous-tâche pas assez strict.** Machine d'état canonique par sous-tâche :
   `TODO → CLAIMED → EXECUTED → GPT_SELF_AUDITED → CLAUDE_MINI_PASS | CLAUDE_MINI_REWORK → DONE | RETRY | HUMAN_GATE`.
   Aucun `[x]` ne peut être coché avant `CLAUDE_MINI_PASS`.

2. **Identité sous-tâche faible.** Chaque sous-tâche reçoit un `SUBTASK_ID` stable : `${TASK_ID}-S01`, `-S02`, …
   Présent dans : `missions/${TASK_ID}/subtasks/SNN/`, `reports/audit/GPT_SELF_AUDIT_${TASK_ID}_SNN.md`,
   `reports/audit/CLAUDE_MINI_AUDIT_${TASK_ID}_SNN.md`, `reports/AGENT_ACTIVITY_LOG.md`.

3. **Sync multi-agents pas assez verrouillée.** `team-run-task.sh` doit :
   lire `ACTIVE_CYCLE` + refuser si `PHASE != EXECUTE`, appeler `agent-activity-log start` avant write,
   refuser si la sous-tâche est `CLAIMED` ou `DONE` par un autre agent.

4. **Mémoire partagée à préciser.** Pas de nouveau store. Discipline :
   - Code A = `docs/`, `scripts/`, `plans/`, etc.
   - Graphiti B = **décisions durables uniquement** (ADR, invariants stabilisés).
   - Missions C = briefs/inputs/outputs **par sous-tâche** (`missions/${TASK_ID}/subtasks/SNN/`).
   - Reports/Plans D = **statuts opérationnels et audits**.
   Graphiti **ne reçoit jamais** de micro-statuts volatils.

5. **Dual-audit naïf = coûteux.** Politique de batching :
   - Sous-tâche `complex` ou avec `INVARIANTS_AT_RISK ≠ None` → mini-audit Claude **obligatoire 1:1**.
   - Sous-tâche `routine` sans invariant → batching autorisé par lots de **2 à 4**, rapport unique
     `CLAUDE_MINI_AUDIT_${TASK_ID}_BATCH_${N}.md` listant les SUBTASK_ID couverts.

6. **REMEDIATION_AUDIT_CYCLE compatibilité.** Séparation stricte :
   - `REWORK_SUB` → compteur **local par sous-tâche**, max **3 retries** → HUMAN_GATE au 3e échec.
   - `REMEDIATION_AUDIT_CYCLE` global (run-cycle.md Step 5) → max **5**, réservé aux REWORK
     **post-audit global** (toute la liste cochée mais audit final fail).

7. **Dashboard incomplet.** `team-status.sh` doit afficher au minimum :
   `TASK_ID`, `PHASE`, `PLAN_FILE`, table sous-tâches (#, SUBTASK_ID, difficulté, owner, status, retry,
   GPT audit, Claude mini-audit), prochaine action éligible, collisions actives, agents en parallèle.

8. **Choix d'automatisation.** `team-run-task.sh` = **commande humaine idempotente par sous-tâche**.
   Option `--auto` (chaîne plusieurs sous-tâches sans intervention) **ne sera ajoutée qu'après** validation
   sur 1 cycle réel d'application FoodKing — pas dans ce cycle méta.

### Risques résiduels (acceptés)
- Quota Anthropic mini-audit : mitigé par batching routine.
- Sur-automatisation cachant les gates : mitigé par décision « commande humaine d'abord ».
- Dérive Graphiti : mitigé par règle « décisions durables only ».

### Scores cibles (post-implémentation)
- Sync stricte : **9/10**
- Multi-agents partagés : **8.5/10**
- Boucle Claude→GPT-impl→GPT-audit→Claude-mini→Claude-global→reloop : **9/10**

## Implementation result (2026-04-25)

Sous-tâches v2 appliquées par Claude orchestrateur (manuel, gouvernance/docs/scripts) :
- [x] `docs/orchestration/TEAM_WORKFLOW.md` — vision utilisateur + 2 diagrammes Mermaid (équipe + parallèle)
- [x] `plans/PLAN_TEMPLATE.md` — section `## SUBTASKS` + machine d'état + règle batching
- [x] `scripts/_lib-active-cycle.sh` — helper sourçable (parsers table + plain)
- [x] `scripts/team-status.sh` — dashboard lecture seule (testé OK)
- [x] `scripts/team-run-task.sh` — runner par sous-tâche (lock + route difficulté + invocation)
- [x] `scripts/team-audit-subtask.sh` — mini-audit Claude (single ou batch 2-4)
- [x] `scripts/team-audit-global.sh` — audit global (pré-vérif toutes sous-tâches DONE)
- [x] `package.json` — 4 npm scripts (`team:status`, `team:run`, `team:audit:sub`, `team:audit:global`)
- [x] `.cursor/commands/run-cycle.md` Step 5 — note dédiée cycles SUBTASKS
- [x] `docs/orchestration/COMMAND_DECK.md` — section 6 « Team workflow »
- [x] Second avis GPT-5.5 pro — `missions/TEAM-WORKFLOW-2026-04-25/output_codex.raw.log` (scores cibles confirmés 9/8.5/9)
- [x] Ré-audit Claude orchestrateur (matrice 3 demandes × mécanismes) — couverture **intégrale**

Cycle méta-orchestration. Prochaine étape (cycle séparé) : smoke test sur 1 vraie tâche d'application FoodKing.

## Audit Status
[x] Implémenté — méta cycle gouvernance (pas de code produit FoodKing)
[ ] Smoke test sur cycle d'application réel (cycle séparé recommandé)
[ ] Gate opened
