# Active Cycle – FoodKing

**Méta (SSOT `run-cycle.md` Step 0 + `AGENTS.md` § *Authoritative … cycle state*)** — requis pour que l’orchestrateur ne s’arrête pas sur *« RUNNER_MODE not set »*.

| Champ | Valeur actuelle |
| --- | --- |
| **RUNNER_MODE** | `single-session` |
| **PHASE** | `CLOSED` — double audit PASS ; V1 livrée désactivée, activation réelle toujours gelée par gate humaine. |
| **MASTER_TASK_ID** | |
| **TASK_ID** | `VOICE-ORDER-ASSIST-V1-20260830` |
| **PLAN_FILE** | `plans/PLAN_VOICE-ORDER-ASSIST-V1-20260830_2026-08-30.md` |
| **REPORT_FILE** | `reports/execution/RUN_VOICE-ORDER-ASSIST-V1-20260830_2026-08-30.md` |
| **AUDIT_SOURCE** | `claude-terminal claude-opus-4-7/high — PASS, TERMINAL_AUDIT_OK: 1` |
| **CONTINUATION_HANDOFF** | `missions/VOICE-ORDER-ASSIST-V1-20260830/execute_brief.md` |
| **PARENT_CYCLE** | `None. Previous CAISSE-SUPERVISOR-CONTROL-20260823 remains suspended at GPT final channel decision; its plan/report/handoff are preserved and must not be rewritten.` |
| **SUBSYSTEMS_TOUCHED** | `Free Pro/Asterisk voice gateway, Deepgram STT, voice transcript cache/ActionLog, catalog-bounded order draft, POS assistant panel, existing phone-order UI handoff` |
| **INVARIANTS_AT_RISK** | `backend pricing SSOT; branch_id isolation; PII transcript retention; frozen wizard invocation without edit` |
| **GATE_CONDITIONS** | `No schema/auth/frozen/payment/status gate planned. Implementation may proceed, but production activation is blocked until caller-notice wording + real Free Pro call receive human-verification sign-off.` |
| **GATE_FILE** | `None for disabled implementation/validation. Deferred production activation checklist: docs/gates/GATE_VOICE-ORDER-ASSIST-V1-20260830_REAL_CALL_2026-08-30.md. Stop now only if implementation requires migration, auth middleware change, frozen edit or out-of-scope branch logic.` |

> **ACTIVE_PRIMARY** : `VOICE-ORDER-ASSIST-V1-20260830` (cycle standard non-`CV1-MXX`; l'ancienne section Masterplay ci-dessous reste une référence historique, pas un second cycle actif).
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
