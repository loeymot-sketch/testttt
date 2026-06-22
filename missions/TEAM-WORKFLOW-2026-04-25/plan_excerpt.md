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

## Audit Status
[ ] Pending
[ ] Passed — cycle closed
[ ] Gate opened
