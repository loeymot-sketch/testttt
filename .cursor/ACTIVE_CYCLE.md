# Active Cycle – FoodKing

**Méta (SSOT `run-cycle.md` Step 0 + `AGENTS.md` § *Authoritative … cycle state*)** — requis pour que l’orchestrateur ne s’arrête pas sur *« RUNNER_MODE not set »*.

| Champ | Valeur actuelle |
| --- | --- |
| **RUNNER_MODE** | `single-session` |
| **PHASE** | `AUDIT` — Claude PASS #2 le 2026-08-24 ; canal GPT final INDISPONIBLE (binaire Codex ENOENT + HTTP 400 modèle). Clôture suspendue à une décision humaine sur le canal manquant. |
| **MASTER_TASK_ID** | |
| **TASK_ID** | `CAISSE-SUPERVISOR-CONTROL-20260823` |
| **PLAN_FILE** | `plans/PLAN_CAISSE-SUPERVISOR-CONTROL-20260823_2026-08-23.md` |
| **REPORT_FILE** | `reports/execution/RUN_CAISSE-SUPERVISOR-CONTROL-20260823_2026-08-23.md` |
| **AUDIT_SOURCE** | `Reprise superviseur Claude Code 2026-08-24 : VALIDATE terminée (41 backend, 3609 Vitest, E2E + parcours obligatoires verts, diff gelé vide). Trois audits adverses indépendants ont rendu REWORK ; 11 findings remédiés, 2 écartés après vérification. Nouvel AUDIT_VERDICT: PASS — reports/audit/CLAUDE_AUDIT_CAISSE-SUPERVISOR-CONTROL-20260823_2026-08-24.md` |
| **CONTINUATION_HANDOFF** | `missions/CAISSE-SUPERVISOR-CONTROL-20260823/CLAUDE_CODE_HANDOFF.md` |
| **PARENT_CYCLE** | `GOAL-WHEEL-EXPERIENCE-20260823 parked at human UX gate; not approved or closed` |
| **SUBSYSTEMS_TOUCHED** | `POS system health/offline/a11y, dashboard SLA/date presets, kiosk idle/product keyboard activation, Playwright critical sync harness and safe E2E cleanup` |
| **INVARIANTS_AT_RISK** | `branch_id fiscal health exactness; fail-closed observability; no unsigned offline order replay` |
| **GATE_CONDITIONS** | `Gate UX Roue toujours PENDING_HUMAN_GATE (hors scope, non auto-approuvable). Escalades ouvertes au propriétaire : garde de production absente sur foodking:ensure-admin ; BROADCAST_DRIVER non-pusher renvoyant ok en dur dans HealthzController ; requête SLA sans borne basse dans DashboardService ; disposition de la page caisse (grille de vente sous la ligne de flottaison en 1366x768).` |
| **GATE_FILE** | `None for this cycle; Wheel gate preserved separately at docs/gates/GATE_WHEEL_EXPERIENCE_UX_SIGNOFF_2026-08-23.md` |

> **ACTIVE_PRIMARY** : `CAISSE_V1_MASTERPLAY` (un seul cycle peut être actif à la fois — voir B03 méga-checklist).
> Dernier cycle archivé : `docs/orchestration/cycles/CYCLE_CV1-V1.5C-SYNC-STOCK-HEAL-MASTER_2026-05-04.md`

---

## CYCLE_W10_EXECUTION_CLOSEOUT (READ_ONLY_SECONDARY — mémoire 180 + MCP global + commit + CI + prod)

**TASK_ID** : `P_EXEC_CLOSEOUT_GRAPHITI_CI_PROD_2026-04-22`  
**Plan SSOT** : `plans/PLAN_EXECUTION_CLOSEOUT_GRAPHITI_CI_PROD_2026-04-22.md`  
**Ordre** : Piste A (POS+Centrale : PLAN-MEM-1) ∥ Piste B (humain : PLAN-MEM-3) → C (smoke) → D (commit sur « go commit ») → E (CI) → F (prod J-7→J+7).  
**Gate mémoire** : `python3 memory/verify.py` → count **≥ 175** (180 idéal) avant de considérer PLAN-MEM-1 **CLOSED**.

- **Vérif locale (2026-04-22)** : `python3 memory/verify.py` → **count = 182**, smoke `search_memory_facts` OK — gate **satisfaite** pour clôturer l'ingestion côté seuil d'épisodes (suite : commit / CI / prod selon plan `PLAN_EXECUTION_CLOSEOUT_*`).

**Gouvernance globale (2e passe 2026-04-22)** : primer multi-agents + Graphiti vivant + tokens « zéro effet négatif » → **`docs/orchestration/GLOBAL_SYSTEM_PRIMER.md`** + rapport **`reports/audit/AUDIT_SECOND_PASS_GLOBAL_GOVERNANCE_REPORT_2026-04-22.md`**.

**Statut Train A 2026-04-26** : W10 n'est plus primaire pendant la préparation release Caisse V1. Toute reprise W10 doit créer un cycle dédié ou repasser par une décision humaine.

---

## CAISSE_V1_MASTERPLAY (ACTIVE_PRIMARY — 2026-04-25 → Train A 2026-04-27)

**Phase** : finition Caisse V1 (POS + Kiosk + KDS + Centrale + Fiscal + Ops).
**Plan parent** : `plans/PLAN_CAISSE_V1_GPT_MASTERPLAY_2026-04-25.md`
**Plan DAG autoritaire** : `plans/PLAN_CAISSE_V1_SUPER_MASTER_2026-04-25.md`
**Boucle d'exécution** : `plans/masterplay/MASTERPLAY_DISCIPLINE.md` + `plans/masterplay/MASTERPLAY_QUEUE.md` + `scripts/run-masterplay.sh`
**Statut temps réel** : `reports/masterplay/status.json`
**Train A V1** : `reports/audit/PHASE2_PLAN_TRAINS_REWORKED_2026-04-27.md`
**Gates humaines Train A** : `docs/gates/GATE_PHASE2_TRAIN_A_HUMAN_DECISIONS_2026-04-26.md`
**Manifeste Phase A ciblée** : `docs/PHASE_A_CLOSED.md`

**Règle** : tout `TASK_ID` au format `CV1-MXX-…` passe par la masterplay (cf. `AGENTS.md` § "Caisse V1 — Masterplay loop", `.cursor/rules/global.mdc` § "Caisse V1 — Masterplay loop", `.cursor/commands/run-cycle.md` Step 0 item 0). **NE PAS** ouvrir un `run-cycle` standard sur un `CV1-MXX-…`.

**Règle Train A** : A.1/A.2/A.3 sont de la persistance/gouvernance release. D-M13 reste bloqué tant que la migration unique `(branch_id, queue_number)` n'a pas reçu son signoff humain final.

---

## Archive

Tous les cycles **CLOSED / COMPLETED PASSED** (W4 → W9, NF525, etc.) ont été déplacés dans **`.cursor/ACTIVE_CYCLE_ARCHIVE.md`** pour réduire le coût de lecture du parcours obligatoire (audit 2026-04-24, mission `T-PARCOURS-OPTIMIZE-001`).

- **Lecture humaine** : ouvrir `.cursor/ACTIVE_CYCLE_ARCHIVE.md`.
- **Lecture agent** : **non requise** sauf instruction explicite du plan ou du chat (ex. "reprend le rationale du cycle W9").
- **Recherche** : `rg "CYCLE_W9_" .cursor/ACTIVE_CYCLE_ARCHIVE.md` ou `git log --follow .cursor/ACTIVE_CYCLE.md`.
