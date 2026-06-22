=== Auto-audit GPT (2e passe) ===
OpenAI Codex v0.125.0 (research preview)
--------
workdir: /Users/1millnonstop/Downloads/projet/foodking-web/web/testttt
model: gpt-5.5
provider: openai
approval: never
sandbox: workspace-write [workdir, /tmp, $TMPDIR, /Users/1millnonstop/.codex/memories]
reasoning effort: xhigh
reasoning summaries: none
session id: 019dc543-8af1-7151-8637-f73354a6a91c
--------
user
Tu effectues l’**auto-audit GPT** (2e passe) d’un cycle FoodKing — **pas** l’audit Claude terminal.

**Contexte** : le JSON ci-dessous est la proposition d’implémentation (format agents/codex.prompt.txt) pour la mission `CV1-M20-RUNBOOKS-SKELETON`.


**JSON d’implémentation (à recouper)** :
```json
{
  "files_to_modify": [
    "reports/runbooks/RUNBOOK_TPE_FAILURE_2026-04-25.md",
    "reports/runbooks/RUNBOOK_PRINTER_FAILURE_2026-04-25.md",
    "reports/runbooks/RUNBOOK_KIOSK_NETWORK_LOSS_2026-04-25.md",
    "reports/runbooks/RUNBOOK_DISPATCH_QUEUE_SATURATED_2026-04-25.md",
    "reports/runbooks/RUNBOOK_OUTBOX_BLOCKED_2026-04-25.md",
    "reports/runbooks/RUNBOOK_FISCAL_SEQUENCE_BREAK_2026-04-25.md",
    "reports/runbooks/RUNBOOK_KDS_MULTISCREEN_DESYNC_2026-04-25.md",
    "reports/runbooks/RUNBOOK_ROLLBACK_CANARY_2026-04-25.md",
    "reports/runbooks/RUNBOOK_INDEX_2026-04-25.md"
  ],
  "implementation_steps": [
    "Read required mission context, allowlist, masterplay discipline, plan sections, super-master gates, and FoodKing invariants.",
    "Verified referenced files and line anchors by read-only inspection before writing runbooks.",
    "Created exactly 8 runbooks plus 1 index under reports/runbooks/.",
    "Validated line counts, H1/H2 structure, gate status wording, absence of code blocks, absence of gate approval markers, and file:line citation count."
  ],
  "code_blocks": [
    {
      "path": "reports/runbooks/RUNBOOK_TPE_FAILURE_2026-04-25.md",
      "op": "create",
      "excerpt": "Created full 309-line Markdown runbook with trigger, symptoms, 10-step diagnostic, P0/P1/P2 actions, escalation matrix, exit checklist, post-mortem template, and references."
    },
    {
      "path": "reports/runbooks/RUNBOOK_PRINTER_FAILURE_2026-04-25.md",
      "op": "create",
      "excerpt": "Created full 313-line Markdown runbook covering ESC/POS service, TCP vs Null transport, Printer model/admin, cash drawer, fallback handling, and references."
    },
    {
      "path": "reports/runbooks/RUNBOOK_KIOSK_NETWORK_LOSS_2026-04-25.md",
      "op": "create",
      "excerpt": "Created full 311-line Markdown runbook covering offline_ local IDs, polling/network loss, kiosk-event observability, menu cache invalidation, and GATE_OFFLINE_SCOPE_V1 constraints."
    },
    {
      "path": "reports/runbooks/RUNBOOK_DISPATCH_QUEUE_SATURATED_2026-04-25.md",
      "op": "create",
      "excerpt": "Created full 311-line Markdown runbook covering Horizon, failed jobs, DispatchDomainEventsJob idempotency/envelope/final failure, preflight, metrics, and worker scaling without code edits."
    },
    {
      "path": "reports/runbooks/RUNBOOK_OUTBOX_BLOCKED_2026-04-25.md",
      "op": "create",
      "excerpt": "Created full 311-line Markdown runbook covering DomainEvent stale/failed scopes, outbox rescue/retry commands, outbox producers, EventContract, after-commit dispatch, and dashboard evidence."
    },
    {
      "path": "reports/runbooks/RUNBOOK_FISCAL_SEQUENCE_BREAK_2026-04-25.md",
      "op": "create",
      "excerpt": "Created full 317-line Markdown runbook covering fiscal sequence, audit chain, Z/X reports, archive evidence, preflight fiscal checks, mandatory L4 NF525 escalation, and no sequence patching."
    },
    {
      "path": "reports/runbooks/RUNBOOK_KDS_MULTISCREEN_DESYNC_2026-04-25.md",
      "op": "create",
      "excerpt": "Created full 310-line Markdown runbook covering KDS filter, changeStatus lock, expected_status gate gap, frontend cap 50, OrderStatusTransition read-only evidence, and no transition rewrites."
    },
    {
      "path": "reports/runbooks/RUNBOOK_ROLLBACK_CANARY_2026-04-25.md",
      "op": "create",
      "excerpt": "Created full 323-line Markdown runbook covering all M-15 flags, canary predicates, rollback order, backend/frontend/build legacy targets, DB/migration rollback delegation to M-13, and preflight checks."
    },
    {
      "path": "reports/runbooks/RUNBOOK_INDEX_2026-04-25.md",
      "op": "create",
      "excerpt": "Created full 82-line Markdown index with status, decision map, transverse links matrix, usage procedure, and maintenance guards."
    }
  ],
  "risks": [
    "DOC_GAP: rollback canary ne peut pas s'appuyer sur un FeatureFlagService centralisé — les flags M-15 sont documentés comme emplacements à créer et OPS_DEBT à porter en M-21+."
  ],
  "notes": "8 runbooks + 1 index créés. Line counts: TPE 309, PRINTER 313, KIOSK_NETWORK 311, DISPATCH_QUEUE 311, OUTBOX 311, FISCAL 317, KDS 310, ROLLBACK 323, INDEX 82. Total file:line citations: 368. Gates referenced: GATE_PAYMENT_LEDGER_V1, GATE_VERIFY_P0_FROZEN_CONSOLIDATED_2026-04-20, GATE_OFFLINE_SCOPE_V1, GATE_FISCAL_KIOSK_V1, GATE_SCHEMA_MIGRATIONS_V1, GATE_KDS_BUMP_V1, GATE_GO_NO_GO_CAISSE_V1. Aucun fichier produit modifié.",
  "execution_trace": {
    "delegation": "codex-extension",
    "invariants_considered": [
      "1-Backend SSOT pricing (TPE / Stripe)",
      "2-OrderStatus enum authoritative (KDS desync, fiscal)",
      "3-branch_id isolation (fiscal seq, KDS sync, outbox)",
      "4-Dispatch after commit (outbox blocked, dispatch saturated)",
      "5-OrderService/FrontendOrderService symmetry (TPE confirm, kiosk recovery)",
      "6-Frozen zones (rollback canary)"
    ]
  }
}
```

**Livrable** (Markdown uniquement, en français) :
# AUTO_AUDIT_GPT — CV1-M20-RUNBOOKS-SKELETON

## 1. Conformité au plan / scope
(Énumérer manques ou dérives ; si scope élargi sans escale → **ESCALATE**)

## 2. Invariants FoodKing
Pour chacun : OK / RISQUE / N/A
- pricing_ssot (backend seul)
- order_status (enum, pas de strings)
- branch_id
- commit_before_dispatch
- frozen_zones
- order_service_symmetry (si un des deux services touché)

## 3. Verdict
Une ligne : `VERDICT: PASS` | `VERDICT: NEEDS_FIX` | `VERDICT: ESCALATE` + 1–3 phrases.

codex
Je recoupe le JSON avec les artefacts présents dans le dépôt, surtout les runbooks créés et le cadre Masterplay/M20. Je reste en lecture seule et je produirai uniquement le Markdown d’auto-audit demandé.
exec
/bin/zsh -lc "sed -n '1,220p' plans/masterplay/MASTERPLAY_QUEUE.md" in /Users/1millnonstop/Downloads/projet/foodking-web/web/testttt
exec
/bin/zsh -lc "sed -n '1,220p' .cursor/ACTIVE_CYCLE.md" in /Users/1millnonstop/Downloads/projet/foodking-web/web/testttt
exec
/bin/zsh -lc "sed -n '1,220p' plans/masterplay/MASTERPLAY_DISCIPLINE.md" in /Users/1millnonstop/Downloads/projet/foodking-web/web/testttt
exec
/bin/zsh -lc "sed -n '1,220p' AGENTS.md" in /Users/1millnonstop/Downloads/projet/foodking-web/web/testttt
 succeeded in 0ms:
# Active Cycle – FoodKing

**Méta (SSOT `run-cycle.md` Step 0 + `AGENTS.md` § *Authoritative … cycle state*)** — requis pour que l’orchestrateur ne s’arrête pas sur *« RUNNER_MODE not set »*.

| Champ | Valeur actuelle |
| --- | --- |
| **RUNNER_MODE** | `single-session` |
| **PHASE (cycle W10)** | `IN_PROGRESS` (détail = section `CYCLE_W10_…` ci-dessous) |
| **TASK_ID** | `P_EXEC_CLOSEOUT_GRAPHITI_CI_PROD_2026-04-22` |
| **PLAN_FILE** | `plans/PLAN_EXECUTION_CLOSEOUT_GRAPHITI_CI_PROD_2026-04-22.md` |
| **REPORT_FILE** | `reports/post_execute_latest.log` (append — preuve `EXECUTE_DELEGATION` / `AUDIT_*`) |

> **ACTIVE_PRIMARY** : `CYCLE_W10_EXECUTION_CLOSEOUT` (un seul cycle peut être actif à la fois — voir B03 méga-checklist).
> Cycles plus anciens en lecture seule = **archive** déplacée dans **`.cursor/ACTIVE_CYCLE_ARCHIVE.md`** (lecture humaine / forensique uniquement, **non requise** par le parcours obligatoire).

## CYCLE_W10_EXECUTION_CLOSEOUT (IN_PROGRESS — mémoire 180 + MCP global + commit + CI + prod)

**TASK_ID** : `P_EXEC_CLOSEOUT_GRAPHITI_CI_PROD_2026-04-22`  
**Plan SSOT** : `plans/PLAN_EXECUTION_CLOSEOUT_GRAPHITI_CI_PROD_2026-04-22.md`  
**Ordre** : Piste A (POS+Centrale : PLAN-MEM-1) ∥ Piste B (humain : PLAN-MEM-3) → C (smoke) → D (commit sur « go commit ») → E (CI) → F (prod J-7→J+7).  
**Gate mémoire** : `python3 memory/verify.py` → count **≥ 175** (180 idéal) avant de considérer PLAN-MEM-1 **CLOSED**.

- **Vérif locale (2026-04-22)** : `python3 memory/verify.py` → **count = 182**, smoke `search_memory_facts` OK — gate **satisfaite** pour clôturer l'ingestion côté seuil d'épisodes (suite : commit / CI / prod selon plan `PLAN_EXECUTION_CLOSEOUT_*`).

**Gouvernance globale (2e passe 2026-04-22)** : primer multi-agents + Graphiti vivant + tokens « zéro effet négatif » → **`docs/orchestration/GLOBAL_SYSTEM_PRIMER.md`** + rapport **`reports/audit/AUDIT_SECOND_PASS_GLOBAL_GOVERNANCE_REPORT_2026-04-22.md`**.

---

## CAISSE_V1_MASTERPLAY (ACTIVE — 2026-04-25)

**Phase** : finition Caisse V1 (POS + Kiosk + KDS + Centrale + Fiscal + Ops).
**Plan parent** : `plans/PLAN_CAISSE_V1_GPT_MASTERPLAY_2026-04-25.md`
**Plan DAG autoritaire** : `plans/PLAN_CAISSE_V1_SUPER_MASTER_2026-04-25.md`
**Boucle d'exécution** : `plans/masterplay/MASTERPLAY_DISCIPLINE.md` + `plans/masterplay/MASTERPLAY_QUEUE.md` + `scripts/run-masterplay.sh`
**Statut temps réel** : `reports/masterplay/status.json`

**Règle** : tout `TASK_ID` au format `CV1-MXX-…` passe par la masterplay (cf. `AGENTS.md` § "Caisse V1 — Masterplay loop", `.cursor/rules/global.mdc` § "Caisse V1 — Masterplay loop", `.cursor/commands/run-cycle.md` Step 0 item 0). **NE PAS** ouvrir un `run-cycle` standard sur un `CV1-MXX-…`.

---

## Archive

Tous les cycles **CLOSED / COMPLETED PASSED** (W4 → W9, NF525, etc.) ont été déplacés dans **`.cursor/ACTIVE_CYCLE_ARCHIVE.md`** pour réduire le coût de lecture du parcours obligatoire (audit 2026-04-24, mission `T-PARCOURS-OPTIMIZE-001`).

- **Lecture humaine** : ouvrir `.cursor/ACTIVE_CYCLE_ARCHIVE.md`.
- **Lecture agent** : **non requise** sauf instruction explicite du plan ou du chat (ex. "reprend le rationale du cycle W9").
- **Recherche** : `rg "CYCLE_W9_" .cursor/ACTIVE_CYCLE_ARCHIVE.md` ou `git log --follow .cursor/ACTIVE_CYCLE.md`.

 succeeded in 0ms:
# MASTERPLAY_QUEUE — Caisse V1

**Source de vérité de l'orchestration en boucle** : `bash scripts/run-masterplay.sh` lit cette file et exécute en série.

**Discipline** : `plans/masterplay/MASTERPLAY_DISCIPLINE.md`.  
**Plan parent** : `plans/PLAN_CAISSE_V1_GPT_MASTERPLAY_2026-04-25.md`.

## Légende statut
- `PENDING` — pas encore lancé
- `RUNNING` — codex exec en cours (ne pas relancer)
- `EXECUTED` — codex exec terminé, attend audit
- `AUDIT_PASS` — `AUDIT_VERDICT: PASS` Claude
- `FINAL_PASS` — `GPT_FINAL_AUDIT_VERDICT: PASS`
- `CLOSED` — mémoire ingestée + activity-log done
- `REWORK` — audit a demandé rework
- `BLOCKED` — gate humain ou dépendance manquante

## Légende vague
- `WAVE_A` — NO-GATE, parallélisable, démarre immédiatement
- `WAVE_B` — POST-GATE, séquencé selon DAG

## File d'exécution

| ORDER | TASK_ID | MISSION | WAVE | DEPENDS_ON | STATUS | NOTE |
|-------|---------|---------|------|------------|--------|------|
| 01 |CV1-M19-MEMORY-DISCIPLINE| M-19 | WAVE_A | — | CLOSED | Crée squelettes JSONL pour les 22 missions |
| 02 | CV1-M01-TRACEABILITY-MATRIX | M-01 | WAVE_A | — | CLOSED | Matrice findings → tasks → tests → gates |
| 03 | CV1-M02-SENTINEL-BASELINE | M-02 | WAVE_A | CV1-M01 | PENDING | 18 sentinels fail-first + 4 lints |
| 04 | CV1-M12-LEGACY-GUARDS-CI | M-12 | WAVE_A | — | EXECUTED | Lint imports + bundle scan + workflow (recovered: extractor JSON fix) |
| 05 | CV1-M16-HARDWARE-LAB | M-16 | WAVE_A | — | EXECUTED | Checklist hardware signable (recovered: JSON valid, files materialized) |
| 06 | CV1-M18-TEST-ARCHITECTURE | M-18 | WAVE_A | CV1-M02 | PENDING | Grille couverture + plan campagne |
| 07 | CV1-M20-RUNBOOKS-SKELETON | M-20 | WAVE_A | — | RUNNING | 8 runbooks ops (TPE, printer, kiosk net, dispatch, outbox, fiscal, KDS, rollback) |
| 08 | CV1-M21A-QUICKWINS-LOT0 | M-21a | WAVE_A | — | PENDING | POS: discount v-model + Swiper RTL + focustrap dead |
| 09 | CV1-M03-GATES-DRAFT | M-03 | WAVE_A | CV1-M01 | PENDING | 8 briefs gates Caisse V1 (frozen, fiscal, ledger A/B, kds, schema, offline, web, stripe) |
| 10 | CV1-M09-BRANCH-ISOLATION | M-09 | WAVE_B | CV1-M03(gates), CV1-M02 | BLOCKED | Gate frozen requis |
| 11 | CV1-M06-POS-REVENUE-GUARDS | M-06 | WAVE_B | CV1-M09, CV1-M03 | BLOCKED | Gate frozen + payment_prop_mutation |
| 12 | CV1-M05-ORDER-QUOTE | M-05 | WAVE_B | CV1-M03 | BLOCKED | Gate schema |
| 13 | CV1-M04A-PAYMENT-LEDGER-FULL | M-04A | WAVE_B | CV1-M03 (ledger=A) | BLOCKED | Gate ledger=A |
| 14 | CV1-M04B-PAYMENT-PILOT-RESTRICT | M-04B | WAVE_B | CV1-M03 (ledger=B) | BLOCKED | Gate ledger=B |
| 15 | CV1-M08-FISCAL-Z-NF525 | M-08 | WAVE_B | CV1-M03 (fiscal+schema) | BLOCKED | Gates fiscal + schema |
| 16 | CV1-M07-KDS-RELEASE | M-07 | WAVE_B | CV1-M03 (kds_bump) | BLOCKED | Gate kds_bump |
| 17 | CV1-M10-OS-FOS-SYMMETRY | M-10 | WAVE_B | CV1-M06, CV1-M09 | BLOCKED | Après guards POS + branch |
| 18 | CV1-M11-KIOSK-RUNTIME | M-11 | WAVE_B | CV1-M03 (offline+fiscal) | BLOCKED | Gates offline + fiscal |
| 19 | CV1-M17-WEB-STRIPE-SCOPE | M-17 | WAVE_B | CV1-M03 (web+stripe) | BLOCKED | Gates web + stripe |
| 20 | CV1-M13-MIGRATIONS-SAFETY | M-13 | WAVE_B | CV1-M03 (schema) | BLOCKED | Gate schema |
| 21 | CV1-M14-OPS-PREFLIGHT | M-14 | WAVE_B | CV1-M13 | BLOCKED | — |
| 22 | CV1-M15-ROLLOUT-CANARY | M-15 | WAVE_B | CV1-M04*, CV1-M08, CV1-M14 | BLOCKED | — |
| 23 | CV1-M21B-PAYMENT-REFACTOR | M-21b | WAVE_B | CV1-M03 (prop_mutation) | BLOCKED | Gate payment_prop_mutation |
| 24 | CV1-M22-POST-LAUNCH-OBSERVABILITY | M-22 | WAVE_B | CV1-M14, CV1-M15 | BLOCKED | — |

## Ce que le runner exécute

À chaque tour de boucle, le runner :
1. Lit cette table.
2. Prend la **première** ligne au statut `PENDING`.
3. Vérifie que toutes ses `DEPENDS_ON` sont au statut `CLOSED`. Sinon → skip.
4. Vérifie que `missions/<TASK_ID>/input.json` et `execute_brief.md` existent. Sinon → marque `BLOCKED note=missing-mission-files`.
5. `start` activity-log → `npm run codex:complex -- <TASK_ID>` → `done` activity-log.
6. Mise à jour statut : `EXECUTED`.
7. Audit Claude terminal automatique (si activé `--with-audit`) → `AUDIT_PASS` ou `REWORK`.
8. Si `AUDIT_PASS` : `npm run codex:final-audit -- <TASK_ID>` → `FINAL_PASS`.
9. Si `FINAL_PASS` : ingestion mémoire + `done` → `CLOSED`.
10. Loop.

## Statut initial (à la création)

Les 6 missions Vague A préparées par M-19/M-01/M-02/M-12/M-16/M-18 sont au statut `PENDING`. Les autres `TODO_NEXT` (à créer après le premier round) ou `BLOCKED` (gates).

## Mise à jour manuelle

Le runner met à jour la colonne `STATUS` automatiquement (sed sur cette table). Tu peux aussi éditer manuellement entre 2 runs (ex: marquer `BLOCKED → PENDING` après gate signé).

 succeeded in 0ms:
# MASTERPLAY DISCIPLINE — Caisse V1 (loop master)

> **But** : règles strictes que le runner et chaque mission GPT respectent en boucle, pendant des heures, jusqu'à finition de toutes les missions de `MASTERPLAY_QUEUE.md`. Lecture obligatoire avant de lancer `bash scripts/run-masterplay.sh`.

## 1. Autorité

| Source | Rôle |
|--------|------|
| `AGENTS.md` | Parcours obligatoire, cycle FoodKing |
| `plans/PLAN_CAISSE_V1_SUPER_MASTER_2026-04-25.md` | DAG autoritaire (ordre, gates) |
| `plans/PLAN_CAISSE_V1_GPT_MASTERPLAY_2026-04-25.md` | Catalogue 22 missions M-XX (objectifs, allowlist) |
| `plans/masterplay/MASTERPLAY_QUEUE.md` | File d'exécution courante |
| `plans/masterplay/MASTERPLAY_DISCIPLINE.md` | (ce fichier) règles d'exécution |
| `.cursor/rules/*.mdc` | Toujours appliquées |
| `docs/gates/GATE_LOG.md` | État des gates humains |

## 2. Boucle d'exécution (run-masterplay.sh)

```
LOOP {
  1. tail activity log (~500 tokens)
  2. find next PENDING task in MASTERPLAY_QUEUE with all DEPENDS_ON == CLOSED
  3. if none → break (all done or all blocked)
  4. verify missions/<TASK_ID>/input.json + execute_brief.md exist
  5. activity-log start codex-extension <TASK_ID> execute "<allowlist CSV>" "<note>"
     if exit 2 (collision) → MARK BLOCKED note=collision, continue loop
  6. update status: RUNNING
  7. npm run codex:complex -- <TASK_ID>     (génère output_codex.json + GPT_SELF_AUDIT)
  8. update status: EXECUTED
  9. activity-log done codex-extension <TASK_ID> done "<résumé court>"
 10. (option --with-audit) bash scripts/foodking-claude-orchestrate.sh audit-brief <TASK_ID>
       if PASS → status: AUDIT_PASS
       if REWORK → status: REWORK ; increment REWORK_COUNT ; if >=5 → BLOCKED note=human_gate
 11. (option --with-final) npm run codex:final-audit -- <TASK_ID>
       if PASS → status: FINAL_PASS
 12. if FINAL_PASS:
       bash scripts/after-execute-memory.sh
       update status: CLOSED
 13. sleep INTER_TASK_PAUSE_SECONDS (default 5s)
 14. continue LOOP
}
```

## 3. Garde-fous (non négociables)

### 3.1 Allowlist stricte par mission
Codex modifie **uniquement** les fichiers listés dans `missions/<TASK_ID>/input.json.allowlist`. Si modification hors liste détectée à l'audit → `REWORK`.

### 3.2 Frozen zones
Aucune édition d'un fichier frozen sans gate signé dans `docs/gates/GATE_LOG.md`. Le runner **refuse** de lancer une mission marquée `BLOCKED` jusqu'à ce que le statut soit changé manuellement après signature.

### 3.3 Invariants FoodKing — `REWORK` automatique
- Pricing client-authoritative
- Status littéral numérique (`status: 16`)
- `branch_id` LIKE
- Dispatch dans transaction
- OS ou FOS modifié sans `SYMMETRY_NOTE`
- Frozen modifié sans gate

### 3.4 Pas de gate auto-approuvée
Codex peut **rédiger** options ; aucune mission ne coche `[x] Approved`. Si une mission le tente → `REWORK` + `risks: ["ESCALATION: gate self-approved"]`.

### 3.5 Tests obligatoires
Chaque `mandatory_tests` listé doit être lancé et reporté dans le rapport. Échec → `REWORK`.

### 3.6 Diff minimal
Aucun renommage opportuniste, aucun refactor non demandé, aucune optimisation collatérale. Si ajout justifié → `notes` du JSON.

### 3.7 Activity log
`start` avant chaque mission ; `done` après. Sans cela → réservation fantôme = autres agents bloqués. Le runner enforce.

### 3.8 Mémoire
À CLOSE : compléter `memory/episodes/caisse_v1_<topic>_*.jsonl` (squelettes créés par M-19) puis `bash scripts/after-execute-memory.sh`. Si Graphiti UP : `bash bin/graphiti-ingest.sh` + `python3 memory/verify.py`.

## 4. Boucles de rework

- Max **5 cycles `REWORK`** consécutifs sur la même mission. Au 5e → `BLOCKED note=human_gate_required`.
- Max **3 cycles healing** consécutifs (cf. CLAUDE.md §8) avant escalade.
- Toute escalation → écrite dans `reports/masterplay/ESCALATIONS_<date>.md`.

## 5. Pause / arrêt

- `Ctrl-C` arrête la boucle proprement (mission en cours finit, runner s'arrête après).
- `touch reports/masterplay/STOP` → le runner s'arrête à la fin de la mission courante.
- `touch reports/masterplay/PAUSE` → le runner pause entre les missions tant que le fichier existe.

## 6. Logs

- `reports/masterplay/run_<ISO>.log` : log de la boucle.
- `reports/masterplay/status.json` : état temps réel (mission courante, compteurs).
- `missions/<TASK_ID>/output_codex.raw.log` : raw codex.
- `missions/<TASK_ID>/output_codex.json` : json structuré.
- `reports/audit/GPT_SELF_AUDIT_<TASK_ID>.md` : self-audit GPT.
- `reports/post_execute_latest.log` : trace `EXECUTE_DELEGATION`.
- `reports/AGENT_ACTIVITY_LOG.md` : start/done.

## 7. Audit Claude (en fin de boucle, manuel)

Quand toutes les missions sont `CLOSED` (ou `BLOCKED` documentés) :

```
bash scripts/foodking-claude-orchestrate.sh context
bash scripts/foodking-claude-orchestrate.sh audit
```

Sortie attendue : verdict transversal Caisse V1 (chaîne sync borne→centrale→POS→KDS→fiscal). Le verdict détermine `GO/HOLD/NO-GO` pour `GATE_GO_NO_GO_CAISSE_V1`.

## 8. Critères d'arrêt anormal

- 3 missions consécutives en `REWORK` → halt + alerte humaine.
- Activity-log refuse 3 fois → halt (collision permanente).
- `npm run codex:complex` échoue 3 fois sur la même mission (binaire codex KO) → halt.
- `claude` terminal indisponible 3 fois consécutives → continue avec fallback subagent + `AUDIT_FALLBACK_REASON: terminal-unavailable`.

## 9. Token discipline

- Le prompt envoyé à codex contient : template `agents/codex.prompt.txt` + `input.json` + `execute_brief.md` + (optionnel) `graphiti_context.md`, `plan_excerpt.md`, `cycle_snapshot.md`.
- Pas de duplication : pas de re-coller AGENTS.md ou super master plan dans chaque mission.
- Cap typique d'un prompt : ≤ 30 KB. Au-delà → splitter la mission.

## 10. Anti-pattern interdits

- ❌ Lancer 2 missions en parallèle sur les mêmes fichiers (collision activity-log).
- ❌ Modifier `MASTERPLAY_QUEUE.md` pendant que le runner tourne (sauf marquer BLOCKED → PENDING après gate).
- ❌ Skipper l'audit Claude pour aller plus vite.
- ❌ Marquer CLOSED manuellement sans double PASS (PASS Claude + PASS Codex final).
- ❌ Ignorer un `risks: ["ESCALATION: ..."]` dans output_codex.json.

---

`MASTERPLAY_DISCIPLINE_VERSION: 1.0` · `STRICT_MODE: ON`

 succeeded in 0ms:
# FoodKing – Cursor Agent Operating Contract

## 0. Quick start contract — read this first

Commence ici. Ne lance aucune action tant que tu n'as pas lu les priorités P0.
Ce contrat fixe l'ordre minimal de lecture pour comprendre le repo en moins de 60 secondes.
Lis complètement ce qui est marqué obligatoire ; diffère seulement ce qui est explicitement classé P2.

### Reading priority

| Priority | What to read | Why |
| --- | --- | --- |
| P0 | `AGENTS.md §1 Parcours obligatoire` | Cadre impératif de travail, ordre de lecture, discipline de cycle. |
| P0 | `.cursor/ACTIVE_CYCLE.md` (continuation) | État courant du cycle actif, contexte vivant, reprise sans divergence. |
| P0 | `.cursor/rules/global.mdc` (auto-attaché — mentionné pour info) | Règles globales toujours applicables, même si déjà injectées par l'outil. |
| P0 | `plans/masterplay/MASTERPLAY_DISCIPLINE.md` + `plans/masterplay/MASTERPLAY_QUEUE.md` (Caisse V1 actif) | **Pendant la phase Caisse V1** : règles d'or de la boucle GPT + file d'exécution. Tout agent qui touche une mission `CV1-MXX-…` DOIT lire ces deux fichiers avant d'agir. |
| P1 | `docs/orchestration/GLOBAL_SYSTEM_PRIMER.md` | Vocabulaire, architecture d'orchestration, invariants transverses. |
| P1 | `.cursor/commands/run-cycle.md` | Procédure exacte pour démarrer un cycle borné correctement. |
| P1 | `docs/orchestration/MEMORY_MATRIX.md` | Où chercher la mémoire utile sans relire tout le dépôt. |
| P1 | `.cursor/routing.md` | Routage des tâches, choix du bon canal, limites d'intervention. |
| P2 | `docs/orchestration/CODEX_API_DELEGATION.md` (quand EXECUTE complexe) | À lire si tu délègues ou exécutes du complexe (uniquement **CLI `codex`**, pas d’exécuteur HTTP dans le dépôt). |
| P2 | `.cursor/ACTIVE_CYCLE_ARCHIVE.md` (forensique humain) | Historique utile pour audit humain, pas requis au démarrage. |
| P2 | `docs/orchestration/EXPORT_CONFIG_CODEX_GPT_API_ET_AUDIT_PERSISTANCE.md` (nouveau poste) | Configuration et persistance utiles surtout lors d'un nouvel environnement. |

Règle simple : P0 avant toute action, P1 avant tout EXECUTE, P2 seulement à la demande du sujet.
Si un doute persiste après P0+P1, arrête-toi et relis ; n'improvise pas.

### One-line bootstrap

```bash
npm run verify:boucle
bash scripts/agent-activity-log.sh tail 50
run-cycle <TASK_ID>
```

### Caisse V1 — Masterplay loop (actif)

Pendant la phase de finition Caisse V1 (POS + Kiosk + KDS + Centrale + Fiscal), l'orchestration passe par la **MASTERPLAY** :

```bash
# Lire d'abord (obligatoire)
cat plans/masterplay/MASTERPLAY_DISCIPLINE.md
cat plans/masterplay/MASTERPLAY_QUEUE.md
cat plans/masterplay/GO.md

# Lancer la boucle (Codex extension complexe + audit Claude terminal + audit Codex final)
bash scripts/run-masterplay.sh --with-audit --with-final
```

- **Plan parent** : `plans/PLAN_CAISSE_V1_GPT_MASTERPLAY_2026-04-25.md` (catalogue 22 missions M-XX, ancrages file:line)
- **Plan autoritaire DAG** : `plans/PLAN_CAISSE_V1_SUPER_MASTER_2026-04-25.md`
- **Statut temps réel** : `reports/masterplay/status.json` + `reports/masterplay/run_*.log`
- Tout `TASK_ID` au format `CV1-MXX-…` est gouverné par la masterplay (allowlist, frozen, gates, REWORK max 5).
- Hors phase Caisse V1 : repasser au `run-cycle <TASK_ID>` standard.

**Anti-répétition (nouvel onglet / agent parallèle)** : copier d’abord le bloc de `docs/orchestration/SESSION_OPENING_ENFORCEMENT.md` — même discipline, moins d’oubli de `ACTIVE_CYCLE` / `run-cycle`.

Utilise `run-cycle <TASK_ID>` pour initier tout cycle borné.
Les deux autres commandes servent à vérifier l'état local et le journal d'activité avant d'exécuter.

### Quality-first, not token-cheap

Lis P0 et P1 intégralement, sans skim. Les économies de tokens ne se font jamais sur la substance des règles, seulement sur la répétition, le bruit et les relectures inutiles. En cas de tension entre vitesse et rigueur, applique la rigueur ; voir `.cursor/rules/global.mdc § Token Discipline`.

### If you're a new human contributor

Commence par `docs/orchestration/EXPORT_CONFIG_CODEX_GPT_API_ET_AUDIT_PERSISTANCE.md` pour préparer ton poste et comprendre le mode de persistance attendu.
Lis-le après P0, puis enchaîne sur P1 avant toute contribution effective.

---

## 1. Parcours obligatoire — **nouvelle conversation** **et** **continuation** (production, non négociable)

> **Objectif** : qu’**à chaque** session (premier message ou 500e message), l’exécutant sache **quel** chemin suivre — **sans** supposer un historique de chat. Tout est **dans le dépôt** ; l’histoire de conversation **n’est pas** la SSOT.

**Règle d’or** : *aucune* modification de code **produit** (hors `plans/`, `reports/`, `docs/gates/`, `missions/`, JSONL gouvernance) **dans le cadre d’un travail borné** sans **(a)** parcours ci-dessous **et** **(b)** cycle `run-cycle` + plan actif, sauf **exception** explicite humaine (notée dans le plan / gate).

| Étape | Action | Quand / pourquoi |
|-------|--------|------------------|
| **0. Continuation** | Lire **`.cursor/ACTIVE_CYCLE.md`**. | Si `PHASE` n’est **pas** vide et le cycle n’est **pas** archivé : **reprendre** ce `TASK_ID` / ce `PLAN_FILE` / ce `REPORT_FILE` — **ne pas** dupliquer un second cycle fantôme. Si humain confirme **nouveau** sujet : réinitialiser / nouveau `TASK_ID` selon `run-cycle` Step 0. |
| **1. Lecture initiale** | Lire **ce fichier** (`AGENTS.md`) **puis** **`docs/orchestration/GLOBAL_SYSTEM_PRIMER.md`** (table §1 = ordre complet : routing, `run-cycle`, Graphiti, `MEMORY_MATRIX`, etc.). | **Avant** tout code ou plan non trivial. Même en « continuation » si le contexte a dérivé ou l’onglet a été rafraîchi. |
| **2. Cycle structuré (SSOT procédurale)** | Toute tâche avec **`TASK_ID`** : suivre **`.cursor/commands/run-cycle.md`** et la commande **`run-cycle <TASK_ID>`** (ou équivalent explicite dans le chat : enchaîner les **Steps 0 → 5** sans en sauter). | **C’est** le programme de production en boucle : `PLAN Claude → PLAN_REVIEW GPT → EXECUTE GPT → VALIDATE → AUDIT Claude → GPT_FINAL_AUDIT → [GATE \| CLOSE]`. Aucun « close » sans double audit PASS. |
| **3. Vérification d’environnement** | `npm run verify:boucle` (0 requête par défaut). **Si le terminal ne “voit” pas Codex alors que l’extension est connectée** : l’auth IDE n’alimente pas le CLI — lancer `npm run codex:doctor` (npm + `login status` + 1 requête). **Rapide :** `npm run codex:verify-pro`. **Complet :** `npm run verify:boucle:full`. Encart : `agents/codex-extension-instructions.md`. | Même en **Step 5** : l’`EXECUTE` (CLI `codex`) requiert le binaire + session Pro sur **ce** binaire. Voir `run-cycle` Step 0 item 8. |
| **4. Secrets & outils machine** | Binaire **`claude`** sur le **`PATH`** (Claude Code CLI) pour l’**AUDIT** PRIMARY en terminal. Binaire **`codex`** (CLI OpenAI) : *Sign in with ChatGPT* **Pro** — **pas** de clé API dans le dépôt. Résolution : `PATH` **ou** `node_modules/.bin/codex` après **`npm install`** (dépendance **`@openai/codex`**) **ou** `npm i -g @openai/codex`. **Ne pas** mélanger avec une clé *Platform* restreinte dans l’environnement (provoque des 401 *scopes* sur l’API Responses) — `npm run codex:audit-bleed` aide. (Option) MCP Graphiti selon `~/.cursor/mcp.json`. | Sans `claude` : noter dès le **plan** l’`AUDIT` fallback + `AUDIT_FALLBACK_REASON`. Sans binaire `codex` : pas d’`EXECUTE` complexe PRIMARY (sub-agent + `FALLBACK_REASON` ou `npm install` + auth Pro). |
| **5. Traces & mémoire (déjà dans ce fichier)** | **`EXECUTE_DELEGATION:`** avant VALIDATE ; **`AUDIT_CHANNEL:`** + **`TERMINAL_AUDIT_OK: 1`** si audit terminal OK ; `docs/orchestration/MEMORY_MATRIX.md` ; `scripts/agent-activity-log.sh` (tail / start / done). | Traçabilité = **même** qualité en prod sur N agents parallèles. |

**Ce n’est pas optionnel** pour travailler « en production FoodKing » : c’est le **contrat** d’onboarding. Les **règles** `.cursor/rules/*.mdc` (dont **`global.mdc` — alwaysApply**) et ce fichier **s’imposent** **à** tout modèle, **dans** toute conversation, dès l’ouverture du dépôt.

**Pour un humain / nouveau compte** : mêmes étapes ; la doc **`docs/orchestration/EXPORT_CONFIG_CODEX_GPT_API_ET_AUDIT_PERSISTANCE.md`** regroupe l’**export** config API + persistance des règles hors-chat.

## Engine
Cursor local agent. No cloud orchestration. No external framework.
Auto/Premium routing: disabled. Model selection is explicit per cycle.

## Global system primer (multi-agents, Graphiti, tokens — lecture clé)

Tout nouvel intervenant (session Cursor, sub-agent Task, CLI terminal, humain) qui touche **orchestration**, **mémoire**, ou **discipline de contexte** : lire **`docs/orchestration/GLOBAL_SYSTEM_PRIMER.md`** après ce fichier. Y sont définis : ordre de lecture obligatoire, `codex-extension` GPT-5.5-pro/xhigh, fallback `foodking-complex-implementer`, fallback audit `foodking-planner-orchestrator`, terminal **`claude` / `codex`**, **mise à jour continue de Graphiti**, et la politique **« intelligence max — zéro optimisation négative »** (tokens : supprimer le gaspillage, pas la substance). Pour audits longs et robustesse **opération + agentique + mémoire** : **`docs/orchestration/MEGA_CHECKLIST_AUTONOMY_AND_MEMORY.md`** (180 tâches) et le narratif **`reports/audit/MEGA_AUDIT_SYSTEM_OPERATION_AGENTIC_2026-04-23.md`**.

**Discipline mémoire (qui écrit où, qui lit quoi, quand)** : **`docs/orchestration/MEMORY_MATRIX.md`** — matrice unique des **4 stores autorisés** (Code A · Graphiti+JSONL B · Missions C · Rapports D), table d'écriture par phase, ordre de lecture pour une nouvelle session, anti-patterns. Aucun nouveau store de mémoire ne peut être ajouté sans gate `docs/gates/GATE_MEMORY_*`. Décisions 2026-04-23 sur OpenSpace et claude-mem : **non intégrés**, justifications dans la matrice.

**Synchro multi-agents (cross-conv, cross-terminal)** : `.cursor/rules/cross-agent-sync.mdc` (alwaysApply) + `reports/AGENT_ACTIVITY_LOG.md` (append-only) + `scripts/agent-activity-log.sh` (`tail | start | done | collisions | active`). Au démarrage de session : `tail 50` (~500 tokens). Avant édition produit (Step 2 EXECUTE) : `start` (refus exit 2 si collision). À la clôture (Step 5 CLOSE) : `done`. Évite que deux agents (Cursor convs / `codex-extension` / `claude-terminal` / humain) modifient les mêmes fichiers à leur insu. **Doctrine étendue** (Graphiti = mémoire partagée, rôles Claude vs Codex, anti-patterns) : **`docs/orchestration/MULTI_AGENT_ORCHESTRATION.md`**.

## Workflow
PLAN Claude → PLAN_REVIEW GPT-5.5-pro/xhigh → EXECUTE GPT → VALIDATE → AUDIT Claude → GPT_FINAL_AUDIT → [HUMAN GATE | CLOSE]

No phase may be skipped. Dual audit (`AUDIT_VERDICT: PASS` + `GPT_FINAL_AUDIT_VERDICT: PASS`) always precedes close.

## Model Roles
| Model | Role | Channel (priorité **qualité maximale / zéro raccourci token**) |
|---|---|---|
| Claude | Plan, architect, orchestrate, **audit** | **PLAN** : le plus souvent en **session Cursor** (orchestrateur) — c’est l’entretien cerveau, pas l’appel `codex`. **AUDIT (après chaque implementation)** : **PRIMAIRE = terminal** `bash scripts/foodking-claude-orchestrate.sh` (`context` → `audit` ou `audit-brief`) — s’appuie sur l’**abonnement Anthropic (Claude Code CLI)**, *pas* sur l’orchestrateur de modèles de Cursor. **FALLBACK AUDIT** (terminal HS, **quota / rate limit / session Anthropic saturée**, réseau) : **ne pas arrêter** — même checklist via Task **`foodking-planner-orchestrator`** (recommandé) ou session Cursor **modèle Claude** ; tracer **`AUDIT_CHANNEL: cursor-session`** + **`AUDIT_FALLBACK_REASON:`** + optionnel **`AUDIT_SUBAGENT_FALLBACK: foodking-planner-orchestrator`**. Doc : `docs/orchestration/AUDIT_TERMINAL_QUOTA_FALLBACK.md`. Voir `run-cycle.md` Step 5. |
| **GPT-5.5 / GPT-5.5-pro** | **PLAN_REVIEW + toute implémentation + auto-audit + GPT_FINAL_AUDIT** | **`codex-extension`** — `npm run codex:plan-review`, `npm run codex:complex`, `npm run codex:final-audit` (CLI `codex` + `codex exec`, **compte ChatGPT Pro**, modèle `gpt-5.5-pro`, `model_reasoning_effort=xhigh` par défaut ; voir `agents/codex-extension-instructions.md`) : c’est l’exécutant unique des changements produit pendant les cycles de finition. Le wrapper produit l’**auto-audit** `reports/audit/GPT_SELF_AUDIT_{TASK_ID}.md`; en plus, `run-cycle` impose une **revue GPT du plan** avant EXECUTE et une **revue GPT finale** après le PASS Claude. N’emprunte **pas** l’orchestrateur de modèles **Cursor** ; facturation **OpenAI / ChatGPT Pro** sur le compte connecté au CLI. |
| GPT-5.5 (fallback) | Complex implementation (FALLBACK only) | **Sub-agent** `foodking-complex-implementer` (Task Cursor) — consomme l’**usage** des modèles de l’**abonnement Cursor**. **Uniquement** si `codex` / l’exécution `codex exec` a échoué (≥2 tentatives documentées) ou binaire indispo. |
| Composer | Validation/report only | Plus d’implémentation routine pendant les cycles de finition. Composer peut résumer, exécuter/rapporter des validations, mais toute correction produit repart en EXECUTE GPT. |

**Qui décide (orchestrateur = Claude, « max smart ») + rôle principal de Codex** : **Claude** porte l’**autorité** sur **PLAN** (périmètre, invariants, stratégie de test), l’interprétation des gates, et **re-plan** après `REWORK`. **GPT-5.5-pro/xhigh** est le second avis obligatoire sur le plan, l’exécutant unique des changements produit, l’auto-auditeur de sa livraison, puis le second avis final après l’audit Claude. **Clôture** : il faut le double vert `AUDIT_VERDICT: PASS` (Claude terminal ou fallback Cursor tracé) **et** `GPT_FINAL_AUDIT_VERDICT: PASS`. En conflit Claude/GPT : pas de close ; Claude re-planifie ou ouvre gate selon le risque. Le **fait** code / test l’emporte sur la croyance.

**Principe unique (symétrique) — à valider en prod sur chaque cycle :** **PLAN Claude → PLAN_REVIEW GPT → EXECUTE GPT → self-audit GPT → VALIDATE → AUDIT Claude → GPT_FINAL_AUDIT**. Le repli EXECUTE vers `foodking-complex-implementer` n’intervient qu’en défaut du chemin `codex exec` documenté, avec `FALLBACK_*` explicite ; **audit terminal Claude** → en défaut **`foodking-planner-orchestrator`** (ou session Claude) avec traces `AUDIT_*` explicites. Preuve d’environnement : `bash scripts/verify-orchestration-boucle.sh` ; smoke complet : `VERIFY_BILLING_FULL=1`.

One PRIMARY_EXECUTION_MODEL per cycle. Roles are explicit and layered; review checkpoints do not authorize scope expansion.
Full routing policy: `.cursor/routing.md`. Naming: the **PRIMARY complex implementer** is the **FoodKing Codex Complex Implementer** (slug `codex-extension`); see `docs/orchestration/CODEX_API_DELEGATION.md`.

## Authoritative multi-agent bounded cycle (SSOT)

For **TASK_ID-driven** work in Cursor, this path is **authoritative** and overrides any conflicting step elsewhere in this document:

1. **Command:** `.cursor/commands/run-cycle.md` (invoke with a `TASK_ID`, e.g. `run-cycle SMOKE-001`).
2. **Cycle state:** `.cursor/ACTIVE_CYCLE.md` (`RUNNER_MODE: single-session`, `PHASE`, `PLAN_FILE`, `REPORT_FILE`, completion rows).
3. **Plan artifact:** `plans/PLAN_[TASK_ID]_[DATE].md` per `.cursor/context/plan-context.md` (from `plans/PLAN_TEMPLATE.md` when applicable).
4. **Phase instructions:** `.cursor/context/plan-context.md` (PLAN), `.cursor/context/execute-context.md` (EXECUTE), `.cursor/context/audit-context.md` (AUDIT); VALIDATE per `run-cycle.md` when `validate-context.md` is absent.

**EXECUTE delegation (GPT only; PRIMARY first, FALLBACK only on failure):**

- **Routine implementation disabled during finishing cycles** : no product edit via Composer / `foodking-routine-implementer`. Small edits still route through GPT to keep the same quality chain.
- **PRIMARY** : **`codex-extension`** — **FoodKing Codex Complex Implementer** (CLI `codex`, compte **ChatGPT Pro**, `gpt-5.5-pro`, `model_reasoning_effort=xhigh`). Procédure :
  1. Préparer `missions/{TASK_ID}/input.json` (+ optionnels : `graphiti_context.md` issu de `search_memory_facts(group_ids=["foodking"])`, `plan_excerpt.md`, `execute_brief.md`, `cycle_snapshot.md` — fusionnés par le script d’assemblage de prompt).
  2. Lancer `npm run codex:complex -- {TASK_ID}` (`bash scripts/codex-extension-execute.sh`) ; le wrapper passe explicitement `-m ${CODEX_EXT_MODEL_PRO:-gpt-5.5-pro}` et `model_reasoning_effort=${CODEX_EXT_REASONING_EFFORT:-xhigh}`. (Instructions custom : `agents/codex-extension-instructions.md`.) **Bootstrap** : `npm run codex:prepare -- {TASK_ID}`.
  3. Appliquer `missions/{TASK_ID}/output_codex.json` ; consommer l’**auto-audit** `reports/audit/GPT_SELF_AUDIT_{TASK_ID}.md` (généré par le wrapper).
  4. Tracer `EXECUTE_DELEGATION: codex-extension` dans `reports/post_execute_latest.log` et le `REPORT_FILE`.
- **Complexe — FALLBACK (uniquement si `codex exec` est HS après reprises, ou binaire manquant)** : `Task` → `foodking-complex-implementer` — tracer `EXECUTE_DELEGATION: foodking-complex-implementer (codex-extension-fallback)` + `FALLBACK_REASON:`. 

Référence complète : **`docs/orchestration/CODEX_API_DELEGATION.md`** (naming, fallback contract, audit handoff, token discipline, schéma boucle). Procédure cycle : `.cursor/commands/run-cycle.md`. La trace `EXECUTE_DELEGATION` dans le rapport est **obligatoire** pour passer en VALIDATE.

**Clôture vs. audit :** Après `VALIDATE`, l’**audit** **Claude** (terminal `foodking-claude-orchestrate.sh` en PRIMARY, fallback Cursor si quota/rate-limit/terminal HS) écrit `AUDIT_VERDICT: PASS|REWORK`, puis GPT écrit `GPT_FINAL_AUDIT_VERDICT: PASS|REWORK|ESCALATE`. **Pas** de `CLOSED` sans double PASS. Sur `REWORK`, boucle `replan (orchestration Claude) → missions + EXECUTE GPT → self-audit GPT → VALIDATE → re-audit Claude → GPT final`, avec `REMEDIATION_AUDIT_CYCLE` 1..5 — au 5e `REWORK` sans double PASS, **HUMAN_GATE** (détail : Step 5 de `run-cycle.md` et `auto-remediation.mdc`).

Sections below labeled **Legacy workflow** remain valid for **PR-centric / review-loop** habits but **do not replace** this SSOT for bounded cycles.

## Source of truth (extended)
- README.md
- docs/PROJECT_CONTINUITY_AND_VISION.md
- docs/ARCHITECTURE.md
- docs/API_MAP.md
- docs/AUTHZ_MATRIX.md
- docs/ORDER_FLOW.md
- docs/DEVICE_FLOW.md
- docs/BUSINESS_RULES.md
- docs/CORE_MODULES.md
- docs/DATABASE_SCHEMA_CORE.md
- docs/ERROR_HANDLING.md
- docs/SECURITY_NOTES.md
- docs/TEST_PLAN.md
- docs/MASSIVE_TEST_PLAN.md
- docs/SAAS_VISION.md
- docs/CONTRIBUTING_QA_BOTS.md
- workflows/qa-loop.md
- workflows/task-routing.md
- workflows/report-format.md
- workflows/task-status.md
- reports/README.md
- docs/orchestration/MEGA_CHECKLIST_AUTONOMY_AND_MEMORY.md
- docs/orchestration/WORKFLOW_AUDIT_GRAPHIQUE_2026-04-23.md
- docs/orchestration/TERMINAL_CLAUDE_GRAPHITI_ORCHESTRATION.md
- docs/orchestration/ROUTING_MATRIX.md

## Stop Conditions
Halt and generate a gate brief on any of:
- Gate trigger detected
- Scope expansion beyond declared boundary
- FoodKing invariant violation
- Two consecutive validation failures
- Planning ambiguity unresolvable from task context
- **`codex` / `codex exec` indisponible après reprises (auth, binaire, ou échec ≥2 sur la même tâche)** : basculer sur le fallback `Task → foodking-complex-implementer` et **noter explicitement** `EXECUTE_DELEGATION: foodking-complex-implementer (codex-extension-fallback)` + `FALLBACK_REASON:`.
- **Audit en terminal (PRIMARY) indisponible ou limité** (`claude` absent, **quota / rate limit / session Anthropic saturée**, auth, réseau — après **1 retry** documenté de `context` + `audit-brief` ou `audit`) : **continuer le cycle** — même checklist `audit-context.md` via Task **`foodking-planner-orchestrator`** (recommandé) ou session Cursor **Claude** ; **`AUDIT_CHANNEL: cursor-session`** + **`AUDIT_FALLBACK_REASON:`** (obligatoire) + optionnel **`AUDIT_SUBAGENT_FALLBACK: foodking-planner-orchestrator`**. Voir `docs/orchestration/AUDIT_TERMINAL_QUOTA_FALLBACK.md`. **Ne jamais** omettre la raison.

## FoodKing Non-Negotiables
- Backend is pricing SSOT — no frontend price logic
- `OrderStatus` enum is authoritative — no hardcoded strings
- `branch_id` = business data isolation — no cross-branch data bleed
- Dispatch strictly after DB commit
- `OrderService` / `FrontendOrderService` symmetry mandatory on any order change
- Frozen zones require gate clearance before any edit

## MCP

### Phase 1 — Filesystem
Filesystem MCP only for repo reads where applicable.

### Phase 2 — Graphiti (mémoire inter-cycles — **présent dans toutes les sessions où le serveur est enregistré**)

**Objectif** : décisions d’architecture, invariants, sync borne↔POS↔KDS, fiscal NF525, historique de cycles — récupérables sans relire des centaines de fichiers.

| Élément | Détail |
|--------|--------|
| **Enregistrement Cursor (obligatoire côté humain)** | Fusionner le bloc `graphiti` dans **`~/.cursor/mcp.json`** (Settings → MCP). Modèle : **`.cursor/mcp/graphiti.json.example`**. Le dépôt ne peut pas injecter un MCP automatiquement dans l’IDE. |
| **Règle agent (automatique dès que le MCP est chargé)** | Voir **`.cursor/rules/graphiti-memory.mdc`** (always-on) + **`global.mdc`** : avant toute tâche non triviale, appeler au moins **`search_memory_facts`** (et optionnellement **`search_memory_nodes`**) avec `group_ids=["foodking"]`. |
| **Après AUDIT / CLOSE** | Si `add_memory` est disponible : enregistrer les décisions durables (ADR, gate, invariant clarifié). |
| **Si Graphiti absent de la session** | **Ne pas bloquer** PLAN / EXECUTE : une ligne « Graphiti non chargé » + secours **`memory/INDEX.md`** + lecture ciblée des JSONL sous `memory/episodes/`. |
| **Server** | Zep Graphiti — wrapper local **`.cursor/mcp/start-graphiti-mcp.sh`** (voir exemple JSON). Clone typique : `/Users/1millnonstop/graphiti`. |
| **Backend** | Neo4j (ex. Aura) — credentials hors repo. |
| **Dépannage** | **`.cursor/mcp/GRAPHITI_TROUBLESHOOTING.md`** (LiteLLM, embeddings, redémarrage proxy). |
| **Group ID** | Toujours **`foodking`**. |
| **Ingestion / vérif locale** | `memory/ingest.py`, `memory/verify.py`, `bin/graphiti-ingest.sh` ; index des domaines **`memory/INDEX.md`**. |

**Intégration bounded cycle** : la commande **`.cursor/commands/run-cycle.md`** inclut l’appel Graphiti en **Step 0 item 5** (query avant PLAN).

### Phase 3 — Playwright MCP (tests E2E sur flows critiques FoodKing)

- Package : `@playwright/mcp@latest` (npx, pas d’install global)
- Browser : Chromium
- BASE_URL : `http://localhost:8000`

codex
Le cycle actif confirme que `CV1-M20-RUNBOOKS-SKELETON` relève de la Masterplay Caisse V1, avec statut `RUNNING` dans la queue. Je vérifie maintenant l’allowlist mission, le brief et les runbooks réels pour distinguer conformité documentaire et dérive de scope.
exec
/bin/zsh -lc "sed -n '1,240p' missions/CV1-M20-RUNBOOKS-SKELETON/input.json" in /Users/1millnonstop/Downloads/projet/foodking-web/web/testttt
 succeeded in 0ms:
{
  "task_id": "CV1-M20-RUNBOOKS-SKELETON",
  "mission_id": "M-20",
  "plan_file": "plans/PLAN_CAISSE_V1_GPT_MASTERPLAY_2026-04-25.md",
  "primary_model": "codex-extension",
  "model": "gpt-5.5-pro",
  "reasoning_effort": "xhigh",
  "objective": "Produire 8 runbooks ops Caisse V1 prêts à signer : panne TPE, panne imprimante, perte réseau kiosk, queue dispatch saturée, outbox blocked, fiscal sequence break, KDS multi-screen desync, rollback canary. Chaque runbook : trigger, diagnostic, actions correctives par criticité, escalation matrix, post-mortem template. Aucun code.",
  "instruction": "Crée les 8 runbooks markdown sous reports/runbooks/. Aucun code, aucun script. JSON unique selon agents/codex.prompt.txt.",
  "allowlist": [
    "reports/runbooks/RUNBOOK_TPE_FAILURE_2026-04-25.md",
    "reports/runbooks/RUNBOOK_PRINTER_FAILURE_2026-04-25.md",
    "reports/runbooks/RUNBOOK_KIOSK_NETWORK_LOSS_2026-04-25.md",
    "reports/runbooks/RUNBOOK_DISPATCH_QUEUE_SATURATED_2026-04-25.md",
    "reports/runbooks/RUNBOOK_OUTBOX_BLOCKED_2026-04-25.md",
    "reports/runbooks/RUNBOOK_FISCAL_SEQUENCE_BREAK_2026-04-25.md",
    "reports/runbooks/RUNBOOK_KDS_MULTISCREEN_DESYNC_2026-04-25.md",
    "reports/runbooks/RUNBOOK_ROLLBACK_CANARY_2026-04-25.md",
    "reports/runbooks/RUNBOOK_INDEX_2026-04-25.md"
  ],
  "off_limits": ["app/**", "resources/**", "routes/**", "database/**", "tests/**", "scripts/**", "config/**", ".cursor/**", "AGENTS.md"],
  "invariants_at_risk": [],
  "gate_conditions": [],
  "mandatory_tests": [],
  "self_audit_checklist": [
    "8 runbooks créés + 1 index",
    "Chaque runbook a : trigger, symptômes observables, diagnostic step-by-step, actions par criticité (P0/P1/P2), escalation matrix, post-mortem template",
    "Chaque runbook réfère aux endpoints/services/scripts FoodKing réels (sans inventer)",
    "Runbook ROLLBACK_CANARY décrit feature flags + rollback DB + rollback frontend (build legacy)",
    "Index liste les 8 runbooks avec quand utiliser quoi",
    "Aucun fichier produit modifié"
  ],
  "rollback": { "feature_flag": null, "max_window_days": 0, "predicates": [] },
  "graphiti_query": "Caisse V1 runbooks ops",
  "memory_episode_to_write_on_close": "memory/episodes/caisse_v1_runbooks_skeleton_2026-04-25.jsonl",
  "claude_audit_prompt_id": "audit-prompt-runbooks"
}

exec
/bin/zsh -lc "sed -n '1,260p' missions/CV1-M20-RUNBOOKS-SKELETON/execute_brief.md" in /Users/1millnonstop/Downloads/projet/foodking-web/web/testttt
 succeeded in 0ms:
J'ai assez de contexte (allowlist, services réels, plan parent). Je rédige le brief.

# EXECUTE BRIEF — CV1-M20-RUNBOOKS-SKELETON (M-20)

## INVIOLABLE

1. Lectures obligatoires **dans cet ordre** :
   - `AGENTS.md` (parcours obligatoire FoodKing — section *Authoritative multi-agent bounded cycle*)
   - `missions/CV1-M20-RUNBOOKS-SKELETON/input.json` (allowlist + off_limits — autorité)
   - `plans/PLAN_CAISSE_V1_GPT_MASTERPLAY_2026-04-25.md` §0 (doctrine), §2 (cartographie file:line), §4 mission **M-20** + §4 mission **M-15** (flags canary)
   - `plans/PLAN_CAISSE_V1_SUPER_MASTER_2026-04-25.md` §3 (gates), §4 (PLAN-13/14/15/20), §7 (PLAN-15 rollout)
   - `plans/masterplay/MASTERPLAY_DISCIPLINE.md` §3 (garde-fous)
   - `.cursor/rules/project-invariants.mdc` (6 invariants — cités sans être violés)
2. **Allowlist stricte — UNIQUEMENT ces 9 chemins** (NEW tous) :
   - `reports/runbooks/RUNBOOK_TPE_FAILURE_2026-04-25.md`
   - `reports/runbooks/RUNBOOK_PRINTER_FAILURE_2026-04-25.md`
   - `reports/runbooks/RUNBOOK_KIOSK_NETWORK_LOSS_2026-04-25.md`
   - `reports/runbooks/RUNBOOK_DISPATCH_QUEUE_SATURATED_2026-04-25.md`
   - `reports/runbooks/RUNBOOK_OUTBOX_BLOCKED_2026-04-25.md`
   - `reports/runbooks/RUNBOOK_FISCAL_SEQUENCE_BREAK_2026-04-25.md`
   - `reports/runbooks/RUNBOOK_KDS_MULTISCREEN_DESYNC_2026-04-25.md`
   - `reports/runbooks/RUNBOOK_ROLLBACK_CANARY_2026-04-25.md`
   - `reports/runbooks/RUNBOOK_INDEX_2026-04-25.md`
3. **Off-limits absolu** (cf. `input.json.off_limits`) : `app/**`, `resources/**`, `routes/**`, `database/**`, `tests/**`, `scripts/**`, `config/**`, `.cursor/**`, `AGENTS.md`. Toute écriture hors `reports/runbooks/` ⇒ `risks: ["SCOPE_PRESSURE: <path>"]` + STOP.
4. **Aucune signature de gate**. Tu peux **citer** un gate (`GATE_FISCAL_KIOSK_V1`, etc.) ; jamais cocher `[x] Approved`. Aucun runbook ne porte de décision GO/NO-GO — il documente la procédure technique.
5. **Aucun code**. Pas de PHP, pas de JS, pas de SQL exécutable. Snippets shell **autorisés uniquement** s'ils citent un script déjà existant (`php artisan foodking:outbox:rescue`, `php artisan app:preflight-production`) — jamais d'invention de commande.

## OBJECTIF EXACT

Produire **8 runbooks ops + 1 index** sous `reports/runbooks/` qui décrivent, pour chaque incident critique Caisse V1, : (a) **trigger machine-détectable**, (b) **symptômes observables** côté ops/utilisateur, (c) **diagnostic step-by-step** ancré sur services/jobs/commandes FoodKing **réels** (file:line obligatoire), (d) **actions correctives par criticité P0/P1/P2**, (e) **escalation matrix** (rôle, délai, canal), (f) **template post-mortem** réutilisable. Aucune invention : tout pointeur code doit exister actuellement dans le repo. L'index liste les 8 runbooks avec colonne "quand utiliser" et "first responder".

## CARTOGRAPHIE PRÉ-ANALYSÉE (file:line vérifiés — utilise-la)

### TPE / paiement kiosk (RUNBOOK_TPE_FAILURE)
- Bridge HW front : `resources/js/components/frontend/kiosk/KioskPaymentComponent.vue:393-414` (CB/TR), `_invokeTpe` L473-501.
- Confirm backend : `app/Http/Controllers/Frontend/OrderController.php:77-151` (`paymentConfirm`), `app/Services/FrontendOrderService.php:791` (`finalizePaidKioskOrder`).
- Cleanup race : `app/Jobs/CleanupStalePendingKioskOrders.php`.
- Audit fiscal : `app/Services/Fiscal/AuditLogService.php`.
- Enum gateway : `app/Enums/PaymentGateway.php`.

### Printer ESC/POS (RUNBOOK_PRINTER_FAILURE)
- Service : `app/Services/Hardware/EscPosPrinterService.php`, `app/Services/Hardware/EscPosCommandBuilder.php`.
- Transports : `app/Services/Hardware/PrinterTransport/TcpPrinterTransport.php`, `NullPrinterTransport.php`, interface `PrinterTransportInterface.php`.
- Modèle : `app/Models/Printer.php`. Admin CRUD : `app/Http/Controllers/Admin/PrinterController.php`, `app/Http/Resources/PrinterResource.php`, `app/Http/Requests/Admin/PrinterRequest.php`.
- Tiroir-caisse : `app/Http/Controllers/Admin/Pos/CashDrawerController.php`. Boot : `app/Providers/AppServiceProvider.php`.

### Kiosk network loss (RUNBOOK_KIOSK_NETWORK_LOSS)
- Queue offline : `resources/js/helpers/kioskOfflineQueue.js:135,330` (prefix `offline_`).
- Détection : `resources/js/components/frontend/kiosk/KioskPaymentComponent.vue:292`, fallback total L297-305.
- Polling/cancel : `resources/js/components/frontend/kiosk/KioskWaitingComponent.vue:195-198,258-305,392`.
- Heartbeat backend : `app/Http/Controllers/Frontend/KioskEventController.php`.
- Cache menu : `app/Listeners/InvalidateKioskMenuCacheOnCatalogChange.php`, `InvalidateKioskMenuCacheOnItemAvailabilityChanged.php`.
- Gate associé : `GATE_OFFLINE_SCOPE_V1` (refus CB/TR offline V1).

### Dispatch queue saturated (RUNBOOK_DISPATCH_QUEUE_SATURATED)
- Worker : `app/Jobs/DispatchDomainEventsJob.php` (lignes-clés L62-89 idempotency, L154 envelope check, L177-208 final failure).
- Jobs voisins : `app/Jobs/CleanupStalePendingKioskOrders.php`, `app/Jobs/SendFcmNotificationJob.php`.
- Config : `config/queue.php`. Preflight : `app/Console/Commands/PreflightProductionCommand.php`.
- Métriques : `app/Services/Observability/SyncMetricsRecorder.php`, `app/Http/Controllers/Admin/Observability/SyncOverviewController.php`.

### Outbox blocked (RUNBOOK_OUTBOX_BLOCKED)
- Modèle : `app/Models/DomainEvent.php` (scope `stale`).
- Persistance : `app/Listeners/PersistOrderCreatedToOutbox.php`, `PersistOrderStatusChangedToOutbox.php`, `PersistOrderTableChangedToOutbox.php`, `PersistItemAvailabilityChangedToOutbox.php`.
- Commandes ops : `app/Console/Commands/OutboxRescueCommand.php` (`foodking:outbox:rescue`, attempts<5, stale 2 min) et `app/Console/Commands/OutboxRetryFailedCommand.php`.
- Contract : `app/Domain/Events/EventContract.php`. After-commit : `app/Events/Concerns/DispatchableAfterCommit.php`.

### Fiscal sequence break (RUNBOOK_FISCAL_SEQUENCE_BREAK)
- Séquence : `app/Services/Fiscal/FiscalSequenceService.php`. Audit : `app/Services/Fiscal/AuditLogService.php`.
- Z/X reports : `app/Services/Fiscal/ZReportService.php`, `XReportService.php`. Archive : `app/Console/Commands/FiscalArchiveCommand.php`. Config : `config/fiscal.php`.
- Référence backend : `app/Services/OrderService.php`, `app/Services/PaymentService.php` (consommateurs séquence).
- Gate : `GATE_FISCAL_KIOSK_V1` (politique kiosk paid). Escalade NF525 : humain obligatoire (CLAUDE.md §8).

### KDS multi-screen desync (RUNBOOK_KDS_MULTISCREEN_DESYNC)
- Service : `app/Services/KitchenDisplaySystemOrderService.php:53-54` (filtre statuts), `:117-168` (changeStatus + lock + transition).
- Request : `app/Http/Requests/OrderStatusRequest.php:15-35,45-47` (manque `expected_status` body — gate `GATE_KDS_BUMP_V1`).
- Front : `resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue:130` (Swiper), L786-793 (cap 50).
- Transition log : `app/Models/OrderStatusTransition.php`.

### Rollback canary (RUNBOOK_ROLLBACK_CANARY)
- Flags M-15 (cf. masterplay §4 M-15) : `payment_ledger_v1`, `pos_revenue_guards`, `kds_strict_release`, `quote_v1`, `fiscal_z_v1`, `kiosk_offline_strict`. Lieu d'implémentation à venir → **citer comme à créer** (ne pas inventer chemin de code).
- Predicates : `payment_success_rate < 95% / 5min`, `fiscal_anomaly > 0`, `kds_error_rate > 5%` (super master §3 PLAN-15).
- Preflight prod : `app/Console/Commands/PreflightProductionCommand.php` (CRITICAL/WARNING).
- Build legacy / cutover : voir M-12 (legacy guards). Migrations down : voir M-13 (`MIGRATIONS_*` runbooks à venir, NE PAS dupliquer).

## SPÉCIFICATION DÉTAILLÉE — STRUCTURE COMMUNE PAR RUNBOOK

Chaque fichier `reports/runbooks/RUNBOOK_<NAME>_2026-04-25.md` contient **exactement** ces sections, dans cet ordre, avec ce balisage :

1. `# RUNBOOK — <Titre humain>` (titre H1 unique, datage `2026-04-25`).
2. Bandeau métadonnées (liste à puces) :
   - `Status: DRAFT_SKELETON_NOT_SIGNED`
   - `Owner (DRAFT): <BE | DevOps | Ops | BE+FE | DBA | NF525-QA>` — **proposition**, non engagement.
   - `Severity ceiling: P0`
   - `Plan source: PLAN-20 (super master) / M-20 (masterplay)`
   - `Linked gates: <liste gates pertinents ou "(none)">`
   - `Last reviewed: 2026-04-25 (initial skeleton)`
3. `## 1. Trigger` — conditions **observables**, machine-détectables (alertes Grafana / Horizon / log lines), avec exemples de pattern (ex : `category=queue.dispatch_domain_events.failed` → cf. `DispatchDomainEventsJob.php:216`).
4. `## 2. Symptômes utilisateur / ops` — vues côté caissier, kiosk, KDS, ops dashboard.
5. `## 3. Diagnostic step-by-step` — liste numérotée 5–10 étapes, chacune avec : commande à lancer (existante uniquement), fichier:line à inspecter, décision de bifurcation. Aucune invention.
6. `## 4. Actions correctives par criticité` — 3 sous-sections :
   - `### 4.1 P0 — production caissière bloquée` (≤ 5 min de réponse)
   - `### 4.2 P1 — dégradé mais opérable` (≤ 30 min)
   - `### 4.3 P2 — anomalie collectée pour post-mortem` (≤ 24 h)
   Chaque action : précondition / action / vérification post-action / impact attendu.
7. `## 5. Escalation matrix` — table Markdown : `| Niveau | Trigger | Rôle | Canal | Délai max | Action irréversible? |`. Au minimum 3 niveaux (L1 ops, L2 BE/DevOps oncall, L3 humain CTO/NF525). Pour `RUNBOOK_FISCAL_SEQUENCE_BREAK` → ajouter ligne **L4 NF525 / Conseil** obligatoire.
8. `## 6. Vérifications de sortie` — checklist `[ ]` reproductible : preuves attendues (logs, dashboards, tickets fermés, séquence fiscale recalée).
9. `## 7. Template post-mortem` — sections fixes : *Timeline UTC*, *Impact (commandes, revenue, fiscal, branches)*, *Cause racine*, *Détection (auto/manuelle/délai)*, *Réponse (ce qui a marché / pas marché)*, *Actions correctives (P0/P1/P2 + propriétaire + deadline)*, *Liens incidents passés*.
10. `## 8. Références` — liste explicite des `file:line` cités, des gates concernés, des plans/missions liés (`PLAN-XX`, `M-XX`).

### Particularités par runbook

- **TPE_FAILURE** : section §3 doit distinguer (a) bridge HW timeout (front, retry ×3 KioskPaymentComponent), (b) `paymentConfirm` 401/403/422 backend, (c) cleanup tardif vs confirm tardif (cf. M-06 cleanup race). Gate à citer : `GATE_PAYMENT_LEDGER_V1`, `GATE_VERIFY_P0_FROZEN_CONSOLIDATED_2026-04-20`.
- **PRINTER_FAILURE** : différencier `TcpPrinterTransport` injoignable vs `NullPrinterTransport` (mode dégradé), file d'attente ESC/POS, fallback PDF/email. Action P1 : basculer transport via `Printer` model. Vérifier aussi tiroir (`CashDrawerController`).
- **KIOSK_NETWORK_LOSS** : §3 doit traiter (a) détection `online`/`offline` côté SW, (b) prefix `offline_` (interdit toute confusion ID serveur), (c) refus serveur CB/TR offline (gate `GATE_OFFLINE_SCOPE_V1` option A par défaut), (d) reconciliation à reconnexion. **Aucune** instruction qui contournerait le refus serveur.
- **DISPATCH_QUEUE_SATURATED** : utiliser `php artisan horizon:status`, `php artisan queue:failed`, `app:preflight-production`. Différencier saturation worker vs failed jobs vs lock contention. P1 inclut scaling worker (sans toucher code).
- **OUTBOX_BLOCKED** : §3 doit lister `php artisan foodking:outbox:rescue` (max 5 attempts), `OutboxRetryFailedCommand`, requête de comptage `DomainEvent` stale par status, dashboard `SyncOverviewController`. P0 : pas d'écriture brute en DB sans approbation humaine.
- **FISCAL_SEQUENCE_BREAK** : sequencing irréversible → §4.1 = **freeze caisse + escalade L4 immédiate**. Aucun runbook ne propose de "patcher" la séquence. Citer `FiscalArchiveCommand`, `AuditLogService`, `config/fiscal.php`. NF525 evidence à conserver.
- **KDS_MULTISCREEN_DESYNC** : §3 doit identifier conflit bump 2 écrans (manque `expected_status` body — gate `GATE_KDS_BUMP_V1`). Action P1 : recharger l'écran perdant, P2 : recompter `OrderStatusTransition`. **Aucune** action qui réécrit transitions.
- **ROLLBACK_CANARY** : §3 doit nommer **chaque flag M-15** + sa cible de rollback (front bundle, backend service, migration down). §4.1 : ordre d'extinction (paiement → fiscal → KDS → kiosk offline). Citer `PreflightProductionCommand` pour validation post-rollback. **NE PAS** rédiger les runbooks de migrations (réservés à M-13).

### Index `RUNBOOK_INDEX_2026-04-25.md`

- `# RUNBOOKS CAISSE V1 — INDEX (2026-04-25)`.
- Section `## 0. Statut` : `INDEX_STATUS: DRAFT_SKELETON`, lien `MASTERPLAY M-20`.
- Section `## 1. Carte de décision` : table `| Symptôme initial | Runbook | First responder | Severity ceiling |` (8 lignes, une par runbook).
- Section `## 2. Liens transverses` : matrice `| Runbook | Plans liés | Gates liés | Métriques clés |`.
- Section `## 3. Procédure d'usage` : 5 étapes (alerte → choix runbook → exécution diagnostic → action selon criticité → post-mortem template).
- Section `## 4. Maintenance` : qui revoit / cadence / déclencheur de mise à jour (changement file:line ⇒ MAJ obligatoire).

## RÈGLES DE QUALITÉ

1. **Aucune ligne de code produit**. Snippets shell autorisés **uniquement** sur scripts/commandes existants (cf. cartographie ci-dessus). Aucune commande inventée.
2. **Chaque diagnostic étape ⇒ ancrage `file:line`** parmi la cartographie. Si tu cites un fichier hors cartographie, tu **dois** l'avoir lu (Read) et tu cites la ligne réelle ; sinon tu n'écris pas la ligne.
3. **Aucun gate signé**. Tu peux écrire `cf. GATE_XXX (PENDING_HUMAN_GATE)` ; jamais "Approved", jamais "validé".
4. **Aucune décision NF525** : pour fiscal, escalade humaine **obligatoire** dès §4.1.
5. **Diff minimal** : 9 fichiers créés, zéro modification ailleurs. Pas de `README` annexe, pas de "while I'm here".
6. **Format Markdown** : H1 unique, H2 numérotés `## 1.` à `## 8.`, tables Markdown valides, listes `-`, encodage UTF-8 sans BOM, fin de fichier `\n`.
7. **Date figée** : `2026-04-25` partout. Pas de date relative ("today", "demain").
8. **Cohérence vocab** : `OrderStatus enum`, `branch_id` strict, `dispatch after commit`, `frozen zones` — usage conforme aux invariants même en prose.
9. **Densité** : chaque runbook 250–500 lignes Markdown ; index 80–150 lignes. Pas de blabla.
10. **Aucune mention de fichiers inexistants** : avant de citer un chemin, garantir qu'il existe (cartographie ci-dessus = whitelist).

## LIVRABLES dans `output_codex.json`

```json
{
  "files_to_modify": [
    "reports/runbooks/RUNBOOK_TPE_FAILURE_2026-04-25.md",
    "reports/runbooks/RUNBOOK_PRINTER_FAILURE_2026-04-25.md",
    "reports/runbooks/RUNBOOK_KIOSK_NETWORK_LOSS_2026-04-25.md",
    "reports/runbooks/RUNBOOK_DISPATCH_QUEUE_SATURATED_2026-04-25.md",
    "reports/runbooks/RUNBOOK_OUTBOX_BLOCKED_2026-04-25.md",
    "reports/runbooks/RUNBOOK_FISCAL_SEQUENCE_BREAK_2026-04-25.md",
    "reports/runbooks/RUNBOOK_KDS_MULTISCREEN_DESYNC_2026-04-25.md",
    "reports/runbooks/RUNBOOK_ROLLBACK_CANARY_2026-04-25.md",
    "reports/runbooks/RUNBOOK_INDEX_2026-04-25.md"
  ],
  "code_blocks": [
    { "path": "reports/runbooks/RUNBOOK_TPE_FAILURE_2026-04-25.md", "op": "create", "excerpt": "<contenu complet>" },
    { "path": "reports/runbooks/RUNBOOK_PRINTER_FAILURE_2026-04-25.md", "op": "create", "excerpt": "<contenu complet>" },
    { "path": "reports/runbooks/RUNBOOK_KIOSK_NETWORK_LOSS_2026-04-25.md", "op": "create", "excerpt": "<contenu complet>" },
    { "path": "reports/runbooks/RUNBOOK_DISPATCH_QUEUE_SATURATED_2026-04-25.md", "op": "create", "excerpt": "<contenu complet>" },
    { "path": "reports/runbooks/RUNBOOK_OUTBOX_BLOCKED_2026-04-25.md", "op": "create", "excerpt": "<contenu complet>" },
    { "path": "reports/runbooks/RUNBOOK_FISCAL_SEQUENCE_BREAK_2026-04-25.md", "op": "create", "excerpt": "<contenu complet>" },
    { "path": "reports/runbooks/RUNBOOK_KDS_MULTISCREEN_DESYNC_2026-04-25.md", "op": "create", "excerpt": "<contenu complet>" },
    { "path": "reports/runbooks/RUNBOOK_ROLLBACK_CANARY_2026-04-25.md", "op": "create", "excerpt": "<contenu complet>" },
    { "path": "reports/runbooks/RUNBOOK_INDEX_2026-04-25.md", "op": "create", "excerpt": "<index complet>" }
  ],
  "risks": [],
  "notes": "8 runbooks + index créés ; tous DRAFT_SKELETON_NOT_SIGNED ; ancrages file:line conformes cartographie M-20 ; aucun code modifié ; aucun gate signé.",
  "execution_trace": {
    "delegation": "codex-extension",
    "invariants_considered": [
      "pricing-ssot",
      "order-status-enum",
      "branch-id-isolation",
      "dispatch-after-commit",
      "os-fos-symmetry",
      "frozen-zones"
    ]
  }
}
```

`GPT_SELF_AUDIT_CV1-M20-RUNBOOKS-SKELETON.md` doit cocher chaque item de `self_audit_checklist` avec evidence (chemin du runbook + ligne où la preuve apparaît).

## INTERDITS

- Toucher tout fichier hors les 9 chemins de l'allowlist (en particulier : `app/**`, `resources/**`, `routes/**`, `database/**`, `tests/**`, `scripts/**`, `config/**`, `.cursor/**`, `AGENTS.md`).
- Approuver, signer, ou marquer "validated" un quelconque gate.
- Inventer un chemin de fichier, une commande artisan, un nom de service, une métrique, une route HTTP.
- Dupliquer le contenu d'`input.json` dans le brief ou dans un runbook.
- Décider une politique fiscale ou un seuil de rollout : ces décisions sont humaines (CLAUDE.md §8 + super master §3).
- Réécrire un runbook géré ailleurs (migrations DB → M-13 / `MIGRATIONS_*`, NE PAS empiéter).
- Ajouter une signature `Co-Authored-By` ou un changelog dans les runbooks (skeleton seulement).
- Modifier le statut `INDEX_STATUS` ou `Status:` au-delà de `DRAFT_SKELETON_*`.
- Faire un `git add` ou un commit (la mission ne le demande pas).

## SI BLOCAGE

- **Fichier référence introuvable** (chemin de la cartographie absent du repo au moment de l'écriture) : retire la ligne de diagnostic concernée, ajoute `risks: ["EVIDENCE_MISSING: <path> attendu par RUNBOOK_<X>"]`, continue les autres runbooks.
- **Ambiguïté de criticité** (incertain entre P0 et P1) : retiens **P0** (principe FoodKing : *blocked > silently dangerous*) et note la décision dans la section §4 du runbook concerné.
- **Conflit avec un gate non signé** : ne propose **aucune** action qui présume l'option A/B/C choisie ; documente les 2-3 options possibles en §4 et marque `Linked gates:` en tête.
- **Doute sur un identifiant de service / job** : grep dans `app/` (lecture seule) avant d'écrire ; si toujours ambigu, ajoute `risks: ["AMBIGUITY: <symbol> — clarification humaine"]` et omets le pointeur plutôt que d'inventer.
- **Allowlist contredite par une nécessité technique** (ex : besoin d'ajouter un script ops) : **NE PAS** étendre l'allowlist. Émet `risks: ["SCOPE_PRESSURE: <reason>"]` et stoppe la mission ; la décision d'élargissement appartient au cycle Claude/humain.
.md", "op": "create", "excerpt": "<markdown complet>" },
    { "path": "reports/runbooks/RUNBOOK_DISPATCH_QUEUE_SATURATED_2026-04-25.md", "op": "create", "excerpt": "<markdown complet>" },
    { "path": "reports/runbooks/RUNBOOK_OUTBOX_BLOCKED_2026-04-25.md", "op": "create", "excerpt": "<markdown complet>" },
    { "path": "reports/runbooks/RUNBOOK_FISCAL_SEQUENCE_BREAK_2026-04-25.md", "op": "create", "excerpt": "<markdown complet>" },
    { "path": "reports/runbooks/RUNBOOK_KDS_MULTISCREEN_DESYNC_2026-04-25.md", "op": "create", "excerpt": "<markdown complet>" },
    { "path": "reports/runbooks/RUNBOOK_ROLLBACK_CANARY_2026-04-25.md", "op": "create", "excerpt": "<markdown complet>" },
    { "path": "reports/runbooks/RUNBOOK_INDEX_2026-04-25.md", "op": "create", "excerpt": "<markdown complet>" }
  ],
  "risks": [],
  "notes": "8 runbooks + index — totaux: <N> lignes, <K> file:line cités, gates référencés: <liste>. Aucun fichier produit modifié.",
  "execution_trace": {
    "delegation": "codex-extension",
    "invariants_considered": [
      "1-Backend SSOT pricing (TPE / Stripe)",
      "2-OrderStatus enum authoritative (KDS desync, fiscal)",
      "3-branch_id isolation (fiscal seq, KDS sync, outbox)",
      "4-Dispatch after commit (outbox blocked, dispatch saturated)",
      "5-OrderService/FrontendOrderService symmetry (TPE confirm, kiosk recovery)",
      "6-Frozen zones (rollback canary)"
    ]
  }
}
```

`notes` doit chiffrer : nombre de lignes Markdown par runbook, nombre total de `file:line` cités (≥ 25 attendus globalement), gates référencés. Aucun champ inventé hors squelette.

## INTERDITS
- Toucher quoi que ce soit hors allowlist — y compris créer un `CHANGELOG.md`, un `README.md`, un fichier `.gitkeep`. Si le besoin émerge → `risks: ["SCOPE_PRESSURE: ..."]` et stop.
- Modifier `app/`, `resources/`, `routes/`, `database/`, `tests/`, `scripts/`, `config/`, `.cursor/`, `AGENTS.md`, `plans/PLAN_CAISSE_V1_*`.
- Cocher un gate `[x] Approved`, écrire `STATUS: APPROVED` ou équivalent.
- Inventer un service, un job, une commande artisan, une route. Tout chemin / signature cité doit être vérifiable dans le repo.
- Inclure du code exécutable (PHP, JS, bash compilable, SQL DML). Les commandes mentionnées sont en backtick inline, pas en bloc shebang.
- Décrire des manœuvres business / RH / commerciales : tu opères au plan technique-ops uniquement, le reste = escalation L2/L3/L4.
- Dupliquer le contenu d'`input.json` ou des plans dans les runbooks.
- Utiliser des phrases d'introduction du type « Ce runbook décrit… ». Aller direct au trigger.
- Suggérer un rollback `git push --force` ou un `migrate:fresh` : interdit, escalation humaine obligatoire.

## SI BLOCAGE
- Service cité absent du repo (ex: pas de `FeatureFlagService` central pour `RUNBOOK_ROLLBACK_CANARY`) → documenter la **réalité** (settings dispersés, env, build legacy) et lever `risks: ["DOC_GAP: rollback canary ne peut pas s'appuyer sur un FeatureFlag service centralisé — mention OPS_DEBT à porter en M-21+"]`. Ne pas inventer de service.
- Endpoint cité dans la cartographie introuvable → lire le fichier, ajuster la citation, sinon `risks: ["CARTO_DRIFT: <chemin> non vérifiable"]` et omettre la citation dans le runbook concerné.
- Ambiguïté de criticité (P0 vs P1) → choisir la sévérité la **plus haute** + noter dans `notes` du JSON.
- Conflit avec un invariant `.cursor/rules/project-invariants.mdc` (ex: une action proposée violerait l'invariant 4 dispatch-after-commit) → reformuler l'action pour respecter l'invariant et noter dans `## 8. Invariants applicables` du runbook.
- Fichier d'allowlist déjà existant (improbable, `reports/runbooks/` actuellement vide) → `op: "create"` quand même = remplacement complet ; ne pas merger silencieusement avec un éventuel contenu antérieur sans le citer dans `notes`.
- Doute sur format escalation matrix / post-mortem template (manque référence interne FoodKing) → utiliser le squelette défini en section « SPÉCIFICATION DÉTAILLÉE » de ce brief, ne pas inventer un autre format.
- Self-audit checklist `input.json.self_audit_checklist` non satisfait à la fin → ne **pas** retourner le JSON ; recommencer le runbook concerné.

exec
/bin/zsh -lc 'find missions/CV1-M20-RUNBOOKS-SKELETON -maxdepth 1 -type f -print | sort' in /Users/1millnonstop/Downloads/projet/foodking-web/web/testttt
 succeeded in 0ms:
missions/CV1-M20-RUNBOOKS-SKELETON/_claude_brief.err.log
missions/CV1-M20-RUNBOOKS-SKELETON/execute_brief.md
missions/CV1-M20-RUNBOOKS-SKELETON/input.json
missions/CV1-M20-RUNBOOKS-SKELETON/output_codex.json
missions/CV1-M20-RUNBOOKS-SKELETON/output_codex.raw.log

exec
/bin/zsh -lc "rg --files missions/CV1-M20-RUNBOOKS-SKELETON reports/runbooks plans | rg '(CV1-M20|RUNBOOK_|PLAN_CAISSE_V1|MASTERPLAY|SUPER_MASTER)'" in /Users/1millnonstop/Downloads/projet/foodking-web/web/testttt
 succeeded in 0ms:
reports/runbooks/RUNBOOK_ROLLBACK_CANARY_2026-04-25.md
reports/runbooks/RUNBOOK_KIOSK_NETWORK_LOSS_2026-04-25.md
reports/runbooks/RUNBOOK_INDEX_2026-04-25.md
reports/runbooks/RUNBOOK_PRINTER_FAILURE_2026-04-25.md
reports/runbooks/RUNBOOK_TPE_FAILURE_2026-04-25.md
reports/runbooks/RUNBOOK_DISPATCH_QUEUE_SATURATED_2026-04-25.md
reports/runbooks/RUNBOOK_KDS_MULTISCREEN_DESYNC_2026-04-25.md
reports/runbooks/RUNBOOK_OUTBOX_BLOCKED_2026-04-25.md
reports/runbooks/RUNBOOK_FISCAL_SEQUENCE_BREAK_2026-04-25.md
plans/PLAN_SIM_MASTERPLAY_2026-04-25.md
plans/PLAN_CAISSE_V1_SUPER_MASTER_2026-04-25.md
plans/PLAN_CAISSE_V1_HYPER_EXEC_GPT_2026-04-25.md
missions/CV1-M20-RUNBOOKS-SKELETON/execute_brief.md
missions/CV1-M20-RUNBOOKS-SKELETON/input.json
plans/PLAN_CAISSE_V1_MEGA_CORRECTION_2026-04-25.md
plans/PLAN_CAISSE_V1_GPT_MASTERPLAY_2026-04-25.md
plans/masterplay/MASTERPLAY_QUEUE.md
plans/masterplay/MASTERPLAY_DISCIPLINE.md

exec
/bin/zsh -lc "sed -n '1,260p' missions/CV1-M20-RUNBOOKS-SKELETON/output_codex.json" in /Users/1millnonstop/Downloads/projet/foodking-web/web/testttt
 succeeded in 0ms:
{
  "files_to_modify": [
    "reports/runbooks/RUNBOOK_TPE_FAILURE_2026-04-25.md",
    "reports/runbooks/RUNBOOK_PRINTER_FAILURE_2026-04-25.md",
    "reports/runbooks/RUNBOOK_KIOSK_NETWORK_LOSS_2026-04-25.md",
    "reports/runbooks/RUNBOOK_DISPATCH_QUEUE_SATURATED_2026-04-25.md",
    "reports/runbooks/RUNBOOK_OUTBOX_BLOCKED_2026-04-25.md",
    "reports/runbooks/RUNBOOK_FISCAL_SEQUENCE_BREAK_2026-04-25.md",
    "reports/runbooks/RUNBOOK_KDS_MULTISCREEN_DESYNC_2026-04-25.md",
    "reports/runbooks/RUNBOOK_ROLLBACK_CANARY_2026-04-25.md",
    "reports/runbooks/RUNBOOK_INDEX_2026-04-25.md"
  ],
  "implementation_steps": [
    "Read required mission context, allowlist, masterplay discipline, plan sections, super-master gates, and FoodKing invariants.",
    "Verified referenced files and line anchors by read-only inspection before writing runbooks.",
    "Created exactly 8 runbooks plus 1 index under reports/runbooks/.",
    "Validated line counts, H1/H2 structure, gate status wording, absence of code blocks, absence of gate approval markers, and file:line citation count."
  ],
  "code_blocks": [
    {
      "path": "reports/runbooks/RUNBOOK_TPE_FAILURE_2026-04-25.md",
      "op": "create",
      "excerpt": "Created full 309-line Markdown runbook with trigger, symptoms, 10-step diagnostic, P0/P1/P2 actions, escalation matrix, exit checklist, post-mortem template, and references."
    },
    {
      "path": "reports/runbooks/RUNBOOK_PRINTER_FAILURE_2026-04-25.md",
      "op": "create",
      "excerpt": "Created full 313-line Markdown runbook covering ESC/POS service, TCP vs Null transport, Printer model/admin, cash drawer, fallback handling, and references."
    },
    {
      "path": "reports/runbooks/RUNBOOK_KIOSK_NETWORK_LOSS_2026-04-25.md",
      "op": "create",
      "excerpt": "Created full 311-line Markdown runbook covering offline_ local IDs, polling/network loss, kiosk-event observability, menu cache invalidation, and GATE_OFFLINE_SCOPE_V1 constraints."
    },
    {
      "path": "reports/runbooks/RUNBOOK_DISPATCH_QUEUE_SATURATED_2026-04-25.md",
      "op": "create",
      "excerpt": "Created full 311-line Markdown runbook covering Horizon, failed jobs, DispatchDomainEventsJob idempotency/envelope/final failure, preflight, metrics, and worker scaling without code edits."
    },
    {
      "path": "reports/runbooks/RUNBOOK_OUTBOX_BLOCKED_2026-04-25.md",
      "op": "create",
      "excerpt": "Created full 311-line Markdown runbook covering DomainEvent stale/failed scopes, outbox rescue/retry commands, outbox producers, EventContract, after-commit dispatch, and dashboard evidence."
    },
    {
      "path": "reports/runbooks/RUNBOOK_FISCAL_SEQUENCE_BREAK_2026-04-25.md",
      "op": "create",
      "excerpt": "Created full 317-line Markdown runbook covering fiscal sequence, audit chain, Z/X reports, archive evidence, preflight fiscal checks, mandatory L4 NF525 escalation, and no sequence patching."
    },
    {
      "path": "reports/runbooks/RUNBOOK_KDS_MULTISCREEN_DESYNC_2026-04-25.md",
      "op": "create",
      "excerpt": "Created full 310-line Markdown runbook covering KDS filter, changeStatus lock, expected_status gate gap, frontend cap 50, OrderStatusTransition read-only evidence, and no transition rewrites."
    },
    {
      "path": "reports/runbooks/RUNBOOK_ROLLBACK_CANARY_2026-04-25.md",
      "op": "create",
      "excerpt": "Created full 323-line Markdown runbook covering all M-15 flags, canary predicates, rollback order, backend/frontend/build legacy targets, DB/migration rollback delegation to M-13, and preflight checks."
    },
    {
      "path": "reports/runbooks/RUNBOOK_INDEX_2026-04-25.md",
      "op": "create",
      "excerpt": "Created full 82-line Markdown index with status, decision map, transverse links matrix, usage procedure, and maintenance guards."
    }
  ],
  "risks": [
    "DOC_GAP: rollback canary ne peut pas s'appuyer sur un FeatureFlagService centralisé — les flags M-15 sont documentés comme emplacements à créer et OPS_DEBT à porter en M-21+."
  ],
  "notes": "8 runbooks + 1 index créés. Line counts: TPE 309, PRINTER 313, KIOSK_NETWORK 311, DISPATCH_QUEUE 311, OUTBOX 311, FISCAL 317, KDS 310, ROLLBACK 323, INDEX 82. Total file:line citations: 368. Gates referenced: GATE_PAYMENT_LEDGER_V1, GATE_VERIFY_P0_FROZEN_CONSOLIDATED_2026-04-20, GATE_OFFLINE_SCOPE_V1, GATE_FISCAL_KIOSK_V1, GATE_SCHEMA_MIGRATIONS_V1, GATE_KDS_BUMP_V1, GATE_GO_NO_GO_CAISSE_V1. Aucun fichier produit modifié.",
  "execution_trace": {
    "delegation": "codex-extension",
    "invariants_considered": [
      "1-Backend SSOT pricing (TPE / Stripe)",
      "2-OrderStatus enum authoritative (KDS desync, fiscal)",
      "3-branch_id isolation (fiscal seq, KDS sync, outbox)",
      "4-Dispatch after commit (outbox blocked, dispatch saturated)",
      "5-OrderService/FrontendOrderService symmetry (TPE confirm, kiosk recovery)",
      "6-Frozen zones (rollback canary)"
    ]
  }
}
exec
/bin/zsh -lc 'rg -n "M-20|PLAN-20|RUNBOOK|runbook|CV1-M20|M-15|ROLLBACK|Feature|flags|canary" plans/PLAN_CAISSE_V1_GPT_MASTERPLAY_2026-04-25.md' in /Users/1millnonstop/Downloads/projet/foodking-web/web/testttt
 succeeded in 0ms:
23:> *« Codex concepts, Claude sequence »* — primitives Codex (`OrderIntent`, `OrderQuote`, `PaymentProof`, `KitchenRelease`), **séquence Claude** : sécurité/branches/POS d'abord, puis quote, puis paiement, puis fiscal, puis KDS/release, puis kiosk runtime, puis ops/canary, puis UX finitions.  
178:`M-01` matrice complète · `M-02` sentinels · `M-12` legacy guards CI · `M-16` hardware lab · `M-18` test architecture · `M-19` mémoire · `M-20` runbook squelette · `M-21a` quickwins UX (LOT-0 finitions).
182:`M-09` branch isolation → `M-06` POS guards (incl. `payment-confirm` durci) → `M-05` `OrderQuote` → `M-04A` *xor* `M-04B` paiement → `M-08` fiscal Z → `M-07` KDS release → `M-10` symétrie OS/FOS → `M-11` kiosk runtime → `M-17` web/Stripe → `M-13` migrations → `M-14` ops → `M-15` rollout → `M-21b` payment refactor + 401 retry → `M-22` post-launch.
214:**Allowlist** : `tests/Feature/Sentinels/*` (NEW), `tests/js/sentinels/*` (NEW), `tests/Playwright/sentinels/*` (NEW), `reports/sentinels/CAISSE_V1_BASELINE_RUN_2026-04-25.log` (NEW).
220:1. `PaymentConfirmAbilitySentinelTest` (Feature, P0) — POST sur `frontend/order/{id}/payment-confirm` avec user **non-kiosk** → attendu **403/422**, `payment_status` inchangé. Ancrage : `app/Http/Controllers/Frontend/OrderController.php:85-118`.
221:2. `PaymentConfirmCrossBranchSentinelTest` (Feature) — utilisateur kiosk machine branche A confirme commande branche B → 403, mutation = 0.
226:7. `OrderListBranchExactnessSentinelTest` (Feature, P0) — query-param `branch_id=1` ne fuit pas en LIKE vers `10/100`. Ancrage : `OrderService.php:151,194,230,267,1920` + `FrontendOrderService.php:99`.
231:12. `KdsTransitionWhitelistSentinelTest` (Feature) — chef KDS PREPARING → CANCELED **422** ; whitelist {ACCEPT, PREPARING, PREPARED}. Ancrage : `app/Http/Requests/OrderStatusRequest.php:45-47`.
264:- `tests/Feature/Payment/PaymentLedgerStateMachineTest.php`, `PaymentLedgerIdempotencyTest.php`, `PaymentLedgerRefundTest.php`, `PaymentLedgerVoidTest.php`, `StripeCentsConversionTest.php`.
270:**Rollback** : flag `payment_ledger_v1=off` ; runbook dans `docs/runbooks/PAYMENT_LEDGER_ROLLBACK.md`.
387:**Allowlist** : `app/Services/OrderService.php`, `app/Services/FrontendOrderService.php`, `tests/Feature/Branch/*` (NEW), `scripts/lint-fk-branch-isolation.sh` (NEW).
399:**Allowlist** : `tests/Feature/Symmetry/OrderServicesContractTest.php` (NEW), `docs/orchestration/OS_FOS_SYMMETRY_MATRIX_2026-04-25.md` (NEW). Code produit *seulement* si gap critique détecté → escalade gate.
421:**But** : dry-run + rehearsal staging full-volume + backup + Up/Down testés + runbook par migration.
423:**Allowlist** : runbooks `docs/runbooks/MIGRATIONS_*.md`, scripts `scripts/db/dry-run.sh`, `scripts/db/rehearsal.sh`, `scripts/db/backup.sh`, tests `MigrationDryRunTest.php`, `MigrationRollbackTest.php`.
435:### 🟠 M-15 — `CAISSE_V1_ROLLOUT_CANARY_2026-04-25` (NO-GATE après M-04+M-08)
467:### 🟢 M-20 — `CAISSE_V1_RUNBOOKS_SKELETON_2026-04-25` (NO-GATE)
469:**But** : squelette `docs/runbooks/CAISSE_V1_*` (ORDER_FLOW, BUSINESS_RULES, AUTHZ_MATRIX). Pas de contenu inventé — pointeurs vers code/services.
489:### 🟠 M-22 — `CAISSE_V1_POST_LAUNCH_OBSERVABILITY_2026-04-25` (NO-GATE après M-15)
587:J0 (immédiat, parallèle) : M-01, M-02, M-12, M-16, M-18, M-19, M-20, M-21a
596:J22-J25                  : M-14 ops, M-15 rollout/canary
611:- [ ] M-13 rehearsal staging full-volume OK ; M-14 preflight green ; M-15 canary drill exécuté.
625:4. **Revue rollback** : drill exécuté, predicates testés, runbooks à jour.

exec
/bin/zsh -lc 'rg -n "PLAN-20|PLAN-15|RUNBOOK|runbook|gate|GATE_|canary|rollback|fiscal|KDS|offline" plans/PLAN_CAISSE_V1_SUPER_MASTER_2026-04-25.md' in /Users/1millnonstop/Downloads/projet/foodking-web/web/testttt
 succeeded in 0ms:
13:The previous master plan is useful but not sufficient as an execution artifact. Claude’s adversarial review upgrades it into a plan-of-plans DAG with 22 subplans, 10 gates, traceability, runtime/ops, migration safety, canary/rollback, hardware readiness, and post-launch observability.
15:Product code remains blocked. Work that can begin immediately is limited to no-code, test-only, documentation, traceability, gate preparation, CI/static scans, hardware preparation, memory discipline, and selected quick wins that do not touch frozen zones.
30:| `reports/audit/MASTER_REVIEW_POS_KDS_FINITIONS_CLAUDE_2026-04-26.md` | POS/KDS finishing source. |
44:- Produce and maintain traceability from findings to plans/tasks/tests/gates.
45:- Run human gates before product code.
46:- In parallel with gates, execute no-code/test-only/prep work.
52:| `GATE_FROZEN_ZONES_CAISSE_V1` | Exact frozen zones opened for V1 | A open all scoped, B refuse, C partial allowlist | C partial allowlist by method/surface | PLAN-04A/B, PLAN-06, PLAN-09 |
53:| `GATE_FISCAL_KIOSK_V1` | Kiosk paid order fiscal policy | A kiosk Z direct, B POS finalizes, C no paid kiosk V1 | C if no auditable Z, B if POS finalization ready | PLAN-08, PLAN-11 |
54:| `GATE_PAYMENT_LEDGER_V1` | Payment ledger or restricted pilot | A ledger full, B restricted pilot | B for pilot, A only if broad payments mandatory | PLAN-04A vs PLAN-04B |
55:| `GATE_KDS_BUMP_V1` | KDS bump authority | A local, B server expected_status | B with feature flag | PLAN-07 |
56:| `GATE_SCHEMA_MIGRATIONS_V1` | Migrations allowed | A all, B subset, C none | A with rehearsal and backup | PLAN-04, PLAN-05, PLAN-08, PLAN-13 |
57:| `GATE_PAYMENT_PROP_MUTATION_2026-04-26` | PaymentComponent correction | A emit/parent, B local data copy | A | PLAN-06, PLAN-21 |
58:| `GATE_VERIFY_P0_FROZEN_CONSOLIDATED_2026-04-20` | Prior P0 frozen cycles signed | A all, B subset, C reverify | A if evidence exists, C otherwise | PLAN-06, PLAN-09 |
59:| `GATE_OFFLINE_SCOPE_V1` | Offline scope V1 | A cash-only, B card with ledger queue, C no offline | A cash-only, backend refuses CB/TR | PLAN-11 |
60:| `GATE_WEB_PAYMENT_SCOPE_V1` | Web/table/Stripe active? | A active, B off V1 | B unless mandatory | PLAN-17 |
61:| `GATE_STRIPE_CENTS_ACTIVE` | Stripe cents fix priority | A Stripe active => P0, B off V1 | Depends on web-payment gate | PLAN-17 |
68:| PLAN-01 | GOVERNANCE_TRACEABILITY_MATRIX | Map findings to tasks/tests/gates | PLAN-00 | none | Claude + QA | `reports/audit/TRACEABILITY_MATRIX_CAISSE_V1_2026-04-25.md` |
70:| PLAN-03 | HUMAN_GATES_RESOLUTION | Sign 10 gates | PLAN-00, PLAN-02 | all gates | Human | `docs/gates/GATE_*.md` |
75:| PLAN-07 | KDS_RELEASE_AND_TRANSITIONS | release predicate, whitelist, expected_status, overflow | PLAN-02, PLAN-03 | KDS bump | Codex | KDS safe transitions |
76:| PLAN-08 | FISCAL_Z_RECONCILIATION | fiscal policy, Z, refunds, voids, HMAC | PLAN-03 | fiscal, schema | Codex + QA NF525 | fiscal proof |
79:| PLAN-11 | KIOSK_RUNTIME_OFFLINE_POLICY | kiosk offline, enum, menu, machine, admin PIN | PLAN-03 | offline, fiscal | Codex | kiosk runtime safe |
81:| PLAN-13 | MIGRATION_DATA_SAFETY | dry-run, rehearsal, backups, rollback | PLAN-03 | schema | Codex + DBA | migration runbooks |
83:| PLAN-15 | ROLLOUT_CANARY_ROLLBACK | feature flags, canary, rollback predicates | PLAN-04, PLAN-08 | none | DevOps + BE | rollout runbook |
88:| PLAN-20 | DOCUMENTATION_AND_RUNBOOK | ORDER_FLOW, BUSINESS_RULES, runbooks | PLAN-04..PLAN-08 | none | Tech writer + Claude | docs/runbooks |
89:| PLAN-21 | UX_FINITIONS_POS_KDS_KIOSK | discount v-model, RTL, i18n, focustrap, locale | PLAN-00 | prop mutation only for payment component | FE/Codex | UX finish tests |
90:| PLAN-22 | POST_LAUNCH_OBSERVABILITY_AND_ANOMALY | anomaly detection and post-launch cadence | PLAN-14, PLAN-15 | none | DevOps + QA | dashboards/on-call |
103:  -> PLAN-20 skeleton
124:  -> PLAN-15
128:  -> PLAN-20 final docs
130:PLAN-14 + PLAN-15 + PLAN-16 + PLAN-18
141:| PLAN-01 traceability matrix | no-code | finding/task/test/gate matrix |
143:| PLAN-03 gate dossiers | docs/human | 10 gate files ready for signature |
148:| PLAN-20 runbook skeleton | docs | runbook table of contents |
157:Objective: no P0/P1 finding can exist without a mapped task, test, owner, and gate.
167:- 0 gate-dependent findings without gate.
199:- linked gate if any.
244:### PLAN-07 — KDS
260:- kiosk fiscal option A/B/C implementation;
275:- KDS list;
276:- fiscal Z;
297:- rollback;
300:- canary rollout;
301:- rollback predicates.
309:| KdsTransitionWhitelistTest | PHP Feature | KDS | PLAN-07 | yes |
310:| KdsExpectedStatusConflictTest | PHP Feature concurrency | KDS | PLAN-07 | yes |
320:| QueueNumberUniquenessTest | PHP concurrency | POS/KDS | PLAN-09/13 | yes |
323:| ZAggregationKioskRoutingTest | PHP Feature | fiscal | PLAN-08 | yes |
324:| RefundPreZTest / RefundPostZTest | PHP Feature | fiscal | PLAN-08 | yes |
325:| FiscalSealingHmacTest | PHP Unit | fiscal | PLAN-08 | yes |
333:| RolloutCanaryDrillTest | drill | rollout | PLAN-15 | yes |
376:- [ ] 10 gates signed or explicitly resolved.
387:- [ ] KDS release implemented.
400:- [ ] Documentation/runbooks current.
405:- [ ] `GATE_GO_NO_GO_CAISSE_V1` signed.
422:3. PLAN-03 gate dossiers.

exec
/bin/zsh -lc "sed -n '1,220p' .cursor/rules/project-invariants.mdc" in /Users/1millnonstop/Downloads/projet/foodking-web/web/testttt
 succeeded in 0ms:
---
description: FoodKing domain invariants. Non-negotiable. Violation triggers an immediate gate at any phase.
globs: ["**/*"]
alwaysApply: true
---

# FoodKing Domain Invariants

Violation of any invariant at any phase triggers an immediate gate.
Claude checks at plan and Claude audit. GPT-5.5 (delivered via **`codex-extension`** (CLI `codex`, compte ChatGPT Pro) primarily, sub-agent `foodking-complex-implementer` only as fallback) checks at plan review, execution, self-audit, and final GPT audit. Composer is validation/report only and halts/logs on contact.

---

## 1. Backend Pricing is SSOT
The Laravel backend is the sole source of truth for all pricing.
No Vue component, Pinia store, computed property, or JS utility may calculate, derive, adjust, or override a price.
The frontend displays values returned by the backend — nothing more.

Violation trigger: any pricing logic outside the Laravel backend.

---

## 2. OrderStatus Enum is Authoritative
`OrderStatus` values are defined in one authoritative enum in the codebase.
No hardcoded string values for order status on backend or frontend.
New statuses are added to the enum first, then referenced everywhere else.
GPT-5.5 must locate and cite the enum file in the plan before implementing any order-status logic.

Violation trigger: string literal used where an `OrderStatus` enum value is required.

---

## 3. branch_id is Business Data Isolation
`branch_id` is a business-level data boundary. It is not a git concept.
Every query and mutation that returns or modifies tenant or branch-scoped data must be filtered by `branch_id`.
No query may return data across `branch_id` boundaries.
No mutation may affect records outside the authorized `branch_id` scope.
If a cycle touches data access logic, the plan must explicitly declare whether `branch_id` isolation is affected and how it is maintained.

Violation trigger: query or mutation that crosses `branch_id` boundaries without explicit plan authorization.

---

## 4. Dispatch After DB Commit
Event and job dispatch occurs strictly after the DB transaction commits.
No dispatch inside a transaction block before commit.
No dispatch in a `finally` clause that could execute before commit confirmation.
GPT-5.5 must verify commit-before-dispatch for every cycle that touches the event or job layer.

Violation trigger: dispatch call that can execute before DB commit completes.

---

## 5. OrderService / FrontendOrderService Symmetry
If either `OrderService` or `FrontendOrderService` is modified in a cycle, the other must be reviewed.
Symmetry review is not optional.
GPT-5.5 logs any asymmetry risk under `SYMMETRY_NOTE` in the plan file before completing execution.
Claude confirms symmetry status during audit. An unresolved `SYMMETRY_NOTE` blocks cycle close.

Violation trigger: one service modified without a completed symmetry review of the other.

---

## 6. Frozen Zones
Frozen zone files must not be edited without explicit human gate clearance in `docs/gates/`.
Reading a frozen zone file for context is permitted.
A plan that requires a frozen zone edit must include a gate brief before implementation begins — not after.
Claude confirms the current frozen zone list at plan phase whenever a potentially frozen file is in scope.

Violation trigger: edit to a frozen zone file without a cleared and logged gate.

---

## Enforcement by Phase
| Phase | Model | Responsibility |
|---|---|---|
| PLAN | Claude | Identify which invariants are at risk; list under `INVARIANTS_AT_RISK` in plan file |
| PLAN_REVIEW | GPT-5.5 | Challenge plan completeness, gates, test strategy, and invariant coverage before implementation |
| EXECUTE | GPT-5.5 | Respect all invariants; stop and log `ESCALATION` on any conflict |
| VALIDATE | Composer / local tooling | Run declared checks, summarize results, and route any product correction back to GPT |
| AUDIT | Claude | Confirm every declared invariant was respected; gate on any violation found |
| GPT_FINAL_AUDIT | GPT-5.5 | Provide second final opinion after Claude audit; close requires `PASS` from both audits |

exec
/bin/zsh -lc 'wc -l reports/runbooks/RUNBOOK_*_2026-04-25.md' in /Users/1millnonstop/Downloads/projet/foodking-web/web/testttt
 succeeded in 0ms:
     311 reports/runbooks/RUNBOOK_DISPATCH_QUEUE_SATURATED_2026-04-25.md
     317 reports/runbooks/RUNBOOK_FISCAL_SEQUENCE_BREAK_2026-04-25.md
      82 reports/runbooks/RUNBOOK_INDEX_2026-04-25.md
     310 reports/runbooks/RUNBOOK_KDS_MULTISCREEN_DESYNC_2026-04-25.md
     311 reports/runbooks/RUNBOOK_KIOSK_NETWORK_LOSS_2026-04-25.md
     311 reports/runbooks/RUNBOOK_OUTBOX_BLOCKED_2026-04-25.md
     313 reports/runbooks/RUNBOOK_PRINTER_FAILURE_2026-04-25.md
     323 reports/runbooks/RUNBOOK_ROLLBACK_CANARY_2026-04-25.md
     309 reports/runbooks/RUNBOOK_TPE_FAILURE_2026-04-25.md
    2587 total

exec
/bin/zsh -lc 'rg -n "```|'"\\[x\\]|Approved|APPROVED|validated|VALIDATED|GO/NO-GO|STATUS: APPROVED|Status: APPROVED|migrate:fresh|push --force|FeatureFlagService|GATE_\" reports/runbooks/RUNBOOK_*_2026-04-25.md" in /Users/1millnonstop/Downloads/projet/foodking-web/web/testttt
 exited 1 in 0ms:
zsh:1: unmatched "

exec
/bin/zsh -lc 'rg -n "''^#{1,6} " reports/runbooks/RUNBOOK_*_2026-04-25.md' in /Users/1millnonstop/Downloads/projet/foodking-web/web/testttt
 succeeded in 0ms:
reports/runbooks/RUNBOOK_ROLLBACK_CANARY_2026-04-25.md:1:# RUNBOOK — Rollback canary Caisse V1 (2026-04-25)
reports/runbooks/RUNBOOK_ROLLBACK_CANARY_2026-04-25.md:10:## 1. Trigger
reports/runbooks/RUNBOOK_ROLLBACK_CANARY_2026-04-25.md:29:## 2. Symptômes utilisateur / ops
reports/runbooks/RUNBOOK_ROLLBACK_CANARY_2026-04-25.md:40:## 3. Diagnostic step-by-step
reports/runbooks/RUNBOOK_ROLLBACK_CANARY_2026-04-25.md:113:## 4. Actions correctives par criticité
reports/runbooks/RUNBOOK_ROLLBACK_CANARY_2026-04-25.md:114:### 4.1 P0 — production caissière bloquée
reports/runbooks/RUNBOOK_ROLLBACK_CANARY_2026-04-25.md:147:### 4.2 P1 — dégradé mais opérable
reports/runbooks/RUNBOOK_ROLLBACK_CANARY_2026-04-25.md:175:### 4.3 P2 — anomalie collectée pour post-mortem
reports/runbooks/RUNBOOK_ROLLBACK_CANARY_2026-04-25.md:204:## 5. Escalation matrix
reports/runbooks/RUNBOOK_ROLLBACK_CANARY_2026-04-25.md:212:## 6. Vérifications de sortie
reports/runbooks/RUNBOOK_ROLLBACK_CANARY_2026-04-25.md:244:## 7. Template post-mortem
reports/runbooks/RUNBOOK_ROLLBACK_CANARY_2026-04-25.md:297:## 8. Références
reports/runbooks/RUNBOOK_PRINTER_FAILURE_2026-04-25.md:1:# RUNBOOK — Panne imprimante ESC/POS / tiroir-caisse (2026-04-25)
reports/runbooks/RUNBOOK_PRINTER_FAILURE_2026-04-25.md:10:## 1. Trigger
reports/runbooks/RUNBOOK_PRINTER_FAILURE_2026-04-25.md:29:## 2. Symptômes utilisateur / ops
reports/runbooks/RUNBOOK_PRINTER_FAILURE_2026-04-25.md:40:## 3. Diagnostic step-by-step
reports/runbooks/RUNBOOK_PRINTER_FAILURE_2026-04-25.md:105:## 4. Actions correctives par criticité
reports/runbooks/RUNBOOK_PRINTER_FAILURE_2026-04-25.md:106:### 4.1 P0 — production caissière bloquée
reports/runbooks/RUNBOOK_PRINTER_FAILURE_2026-04-25.md:134:### 4.2 P1 — dégradé mais opérable
reports/runbooks/RUNBOOK_PRINTER_FAILURE_2026-04-25.md:167:### 4.3 P2 — anomalie collectée pour post-mortem
reports/runbooks/RUNBOOK_PRINTER_FAILURE_2026-04-25.md:196:## 5. Escalation matrix
reports/runbooks/RUNBOOK_PRINTER_FAILURE_2026-04-25.md:204:## 6. Vérifications de sortie
reports/runbooks/RUNBOOK_PRINTER_FAILURE_2026-04-25.md:236:## 7. Template post-mortem
reports/runbooks/RUNBOOK_PRINTER_FAILURE_2026-04-25.md:289:## 8. Références
reports/runbooks/RUNBOOK_TPE_FAILURE_2026-04-25.md:1:# RUNBOOK — Panne TPE / paiement kiosk (2026-04-25)
reports/runbooks/RUNBOOK_TPE_FAILURE_2026-04-25.md:10:## 1. Trigger
reports/runbooks/RUNBOOK_TPE_FAILURE_2026-04-25.md:29:## 2. Symptômes utilisateur / ops
reports/runbooks/RUNBOOK_TPE_FAILURE_2026-04-25.md:40:## 3. Diagnostic step-by-step
reports/runbooks/RUNBOOK_TPE_FAILURE_2026-04-25.md:105:## 4. Actions correctives par criticité
reports/runbooks/RUNBOOK_TPE_FAILURE_2026-04-25.md:106:### 4.1 P0 — production caissière bloquée
reports/runbooks/RUNBOOK_TPE_FAILURE_2026-04-25.md:139:### 4.2 P1 — dégradé mais opérable
reports/runbooks/RUNBOOK_TPE_FAILURE_2026-04-25.md:167:### 4.3 P2 — anomalie collectée pour post-mortem
reports/runbooks/RUNBOOK_TPE_FAILURE_2026-04-25.md:196:## 5. Escalation matrix
reports/runbooks/RUNBOOK_TPE_FAILURE_2026-04-25.md:204:## 6. Vérifications de sortie
reports/runbooks/RUNBOOK_TPE_FAILURE_2026-04-25.md:235:## 7. Template post-mortem
reports/runbooks/RUNBOOK_TPE_FAILURE_2026-04-25.md:288:## 8. Références
reports/runbooks/RUNBOOK_KDS_MULTISCREEN_DESYNC_2026-04-25.md:1:# RUNBOOK — KDS multi-screen desync / bump concurrent (2026-04-25)
reports/runbooks/RUNBOOK_KDS_MULTISCREEN_DESYNC_2026-04-25.md:10:## 1. Trigger
reports/runbooks/RUNBOOK_KDS_MULTISCREEN_DESYNC_2026-04-25.md:29:## 2. Symptômes utilisateur / ops
reports/runbooks/RUNBOOK_KDS_MULTISCREEN_DESYNC_2026-04-25.md:40:## 3. Diagnostic step-by-step
reports/runbooks/RUNBOOK_KDS_MULTISCREEN_DESYNC_2026-04-25.md:105:## 4. Actions correctives par criticité
reports/runbooks/RUNBOOK_KDS_MULTISCREEN_DESYNC_2026-04-25.md:106:### 4.1 P0 — production caissière bloquée
reports/runbooks/RUNBOOK_KDS_MULTISCREEN_DESYNC_2026-04-25.md:134:### 4.2 P1 — dégradé mais opérable
reports/runbooks/RUNBOOK_KDS_MULTISCREEN_DESYNC_2026-04-25.md:167:### 4.3 P2 — anomalie collectée pour post-mortem
reports/runbooks/RUNBOOK_KDS_MULTISCREEN_DESYNC_2026-04-25.md:196:## 5. Escalation matrix
reports/runbooks/RUNBOOK_KDS_MULTISCREEN_DESYNC_2026-04-25.md:204:## 6. Vérifications de sortie
reports/runbooks/RUNBOOK_KDS_MULTISCREEN_DESYNC_2026-04-25.md:236:## 7. Template post-mortem
reports/runbooks/RUNBOOK_KDS_MULTISCREEN_DESYNC_2026-04-25.md:289:## 8. Références
reports/runbooks/RUNBOOK_FISCAL_SEQUENCE_BREAK_2026-04-25.md:1:# RUNBOOK — Rupture séquence fiscale NF525 (2026-04-25)
reports/runbooks/RUNBOOK_FISCAL_SEQUENCE_BREAK_2026-04-25.md:10:## 1. Trigger
reports/runbooks/RUNBOOK_FISCAL_SEQUENCE_BREAK_2026-04-25.md:29:## 2. Symptômes utilisateur / ops
reports/runbooks/RUNBOOK_FISCAL_SEQUENCE_BREAK_2026-04-25.md:40:## 3. Diagnostic step-by-step
reports/runbooks/RUNBOOK_FISCAL_SEQUENCE_BREAK_2026-04-25.md:105:## 4. Actions correctives par criticité
reports/runbooks/RUNBOOK_FISCAL_SEQUENCE_BREAK_2026-04-25.md:106:### 4.1 P0 — production caissière bloquée
reports/runbooks/RUNBOOK_FISCAL_SEQUENCE_BREAK_2026-04-25.md:139:### 4.2 P1 — dégradé mais opérable
reports/runbooks/RUNBOOK_FISCAL_SEQUENCE_BREAK_2026-04-25.md:167:### 4.3 P2 — anomalie collectée pour post-mortem
reports/runbooks/RUNBOOK_FISCAL_SEQUENCE_BREAK_2026-04-25.md:196:## 5. Escalation matrix
reports/runbooks/RUNBOOK_FISCAL_SEQUENCE_BREAK_2026-04-25.md:205:## 6. Vérifications de sortie
reports/runbooks/RUNBOOK_FISCAL_SEQUENCE_BREAK_2026-04-25.md:239:## 7. Template post-mortem
reports/runbooks/RUNBOOK_FISCAL_SEQUENCE_BREAK_2026-04-25.md:292:## 8. Références
reports/runbooks/RUNBOOK_DISPATCH_QUEUE_SATURATED_2026-04-25.md:1:# RUNBOOK — Queue dispatch saturée / events domaine (2026-04-25)
reports/runbooks/RUNBOOK_DISPATCH_QUEUE_SATURATED_2026-04-25.md:10:## 1. Trigger
reports/runbooks/RUNBOOK_DISPATCH_QUEUE_SATURATED_2026-04-25.md:29:## 2. Symptômes utilisateur / ops
reports/runbooks/RUNBOOK_DISPATCH_QUEUE_SATURATED_2026-04-25.md:40:## 3. Diagnostic step-by-step
reports/runbooks/RUNBOOK_DISPATCH_QUEUE_SATURATED_2026-04-25.md:105:## 4. Actions correctives par criticité
reports/runbooks/RUNBOOK_DISPATCH_QUEUE_SATURATED_2026-04-25.md:106:### 4.1 P0 — production caissière bloquée
reports/runbooks/RUNBOOK_DISPATCH_QUEUE_SATURATED_2026-04-25.md:134:### 4.2 P1 — dégradé mais opérable
reports/runbooks/RUNBOOK_DISPATCH_QUEUE_SATURATED_2026-04-25.md:167:### 4.3 P2 — anomalie collectée pour post-mortem
reports/runbooks/RUNBOOK_DISPATCH_QUEUE_SATURATED_2026-04-25.md:196:## 5. Escalation matrix
reports/runbooks/RUNBOOK_DISPATCH_QUEUE_SATURATED_2026-04-25.md:204:## 6. Vérifications de sortie
reports/runbooks/RUNBOOK_DISPATCH_QUEUE_SATURATED_2026-04-25.md:236:## 7. Template post-mortem
reports/runbooks/RUNBOOK_DISPATCH_QUEUE_SATURATED_2026-04-25.md:289:## 8. Références
reports/runbooks/RUNBOOK_KIOSK_NETWORK_LOSS_2026-04-25.md:1:# RUNBOOK — Perte réseau kiosk / queue offline (2026-04-25)
reports/runbooks/RUNBOOK_KIOSK_NETWORK_LOSS_2026-04-25.md:10:## 1. Trigger
reports/runbooks/RUNBOOK_KIOSK_NETWORK_LOSS_2026-04-25.md:29:## 2. Symptômes utilisateur / ops
reports/runbooks/RUNBOOK_KIOSK_NETWORK_LOSS_2026-04-25.md:40:## 3. Diagnostic step-by-step
reports/runbooks/RUNBOOK_KIOSK_NETWORK_LOSS_2026-04-25.md:105:## 4. Actions correctives par criticité
reports/runbooks/RUNBOOK_KIOSK_NETWORK_LOSS_2026-04-25.md:106:### 4.1 P0 — production caissière bloquée
reports/runbooks/RUNBOOK_KIOSK_NETWORK_LOSS_2026-04-25.md:139:### 4.2 P1 — dégradé mais opérable
reports/runbooks/RUNBOOK_KIOSK_NETWORK_LOSS_2026-04-25.md:167:### 4.3 P2 — anomalie collectée pour post-mortem
reports/runbooks/RUNBOOK_KIOSK_NETWORK_LOSS_2026-04-25.md:196:## 5. Escalation matrix
reports/runbooks/RUNBOOK_KIOSK_NETWORK_LOSS_2026-04-25.md:204:## 6. Vérifications de sortie
reports/runbooks/RUNBOOK_KIOSK_NETWORK_LOSS_2026-04-25.md:236:## 7. Template post-mortem
reports/runbooks/RUNBOOK_KIOSK_NETWORK_LOSS_2026-04-25.md:289:## 8. Références
reports/runbooks/RUNBOOK_OUTBOX_BLOCKED_2026-04-25.md:1:# RUNBOOK — Outbox bloquée / DomainEvent stale (2026-04-25)
reports/runbooks/RUNBOOK_OUTBOX_BLOCKED_2026-04-25.md:10:## 1. Trigger
reports/runbooks/RUNBOOK_OUTBOX_BLOCKED_2026-04-25.md:29:## 2. Symptômes utilisateur / ops
reports/runbooks/RUNBOOK_OUTBOX_BLOCKED_2026-04-25.md:40:## 3. Diagnostic step-by-step
reports/runbooks/RUNBOOK_OUTBOX_BLOCKED_2026-04-25.md:105:## 4. Actions correctives par criticité
reports/runbooks/RUNBOOK_OUTBOX_BLOCKED_2026-04-25.md:106:### 4.1 P0 — production caissière bloquée
reports/runbooks/RUNBOOK_OUTBOX_BLOCKED_2026-04-25.md:139:### 4.2 P1 — dégradé mais opérable
reports/runbooks/RUNBOOK_OUTBOX_BLOCKED_2026-04-25.md:167:### 4.3 P2 — anomalie collectée pour post-mortem
reports/runbooks/RUNBOOK_OUTBOX_BLOCKED_2026-04-25.md:196:## 5. Escalation matrix
reports/runbooks/RUNBOOK_OUTBOX_BLOCKED_2026-04-25.md:204:## 6. Vérifications de sortie
reports/runbooks/RUNBOOK_OUTBOX_BLOCKED_2026-04-25.md:236:## 7. Template post-mortem
reports/runbooks/RUNBOOK_OUTBOX_BLOCKED_2026-04-25.md:289:## 8. Références
reports/runbooks/RUNBOOK_INDEX_2026-04-25.md:1:# RUNBOOKS CAISSE V1 — INDEX (2026-04-25)
reports/runbooks/RUNBOOK_INDEX_2026-04-25.md:3:## 0. Statut
reports/runbooks/RUNBOOK_INDEX_2026-04-25.md:10:## 1. Carte de décision
reports/runbooks/RUNBOOK_INDEX_2026-04-25.md:22:## 2. Liens transverses
reports/runbooks/RUNBOOK_INDEX_2026-04-25.md:34:## 3. Procédure d'usage
reports/runbooks/RUNBOOK_INDEX_2026-04-25.md:46:## 4. Maintenance

exec
/bin/zsh -lc 'git status --short -- reports/runbooks missions/CV1-M20-RUNBOOKS-SKELETON app resources routes database tests scripts config .cursor AGENTS.md plans/PLAN_CAISSE_V1_GPT_MASTERPLAY_2026-04-25.md plans/PLAN_CAISSE_V1_SUPER_MASTER_2026-04-25.md' in /Users/1millnonstop/Downloads/projet/foodking-web/web/testttt
 succeeded in 0ms:
 M .cursor/ACTIVE_CYCLE.md
 M .cursor/commands/run-cycle.md
 M .cursor/context/audit-context.md
 M .cursor/context/execute-context.md
 M .cursor/context/plan-context.md
 M .cursor/routing.md
 M .cursor/rules/auto-remediation.mdc
 M .cursor/rules/claude.mdc
 M .cursor/rules/composer.mdc
 M .cursor/rules/context-hygiene.mdc
 M .cursor/rules/cross-agent-sync.mdc
 M .cursor/rules/global-operating-principles.md
 M .cursor/rules/global.mdc
 M .cursor/rules/gpt.mdc
 M .cursor/rules/project-invariants.mdc
 M .cursor/rules/scope.mdc
 M AGENTS.md
 M app/Services/FrontendOrderService.php
 M scripts/agent-activity-log.sh
 M scripts/codex-extension-execute.sh
 M scripts/codex-extract-json-output.mjs
 M scripts/foodking-claude-orchestrate.sh
 M tests/Feature/DispatchAfterCommitTest.php
?? missions/CV1-M20-RUNBOOKS-SKELETON/
?? plans/PLAN_CAISSE_V1_GPT_MASTERPLAY_2026-04-25.md
?? plans/PLAN_CAISSE_V1_SUPER_MASTER_2026-04-25.md
?? reports/runbooks/
?? scripts/_audit-terminal-fallback-hint.sh
?? scripts/_lib-active-cycle.sh
?? scripts/_masterplay-claude-brief.sh
?? scripts/check-traceability.sh
?? scripts/codex-final-audit.sh
?? scripts/codex-invoke-claude-audit.sh
?? scripts/codex-plan-review.sh
?? scripts/lint-fk-archive-banner.sh
?? scripts/lint-fk-legacy-imports.sh
?? scripts/lint-fk-legacy-routes.sh
?? scripts/post-execute-guard.sh
?? scripts/preflight-execute.sh
?? scripts/run-masterplay.sh
?? scripts/scan-bundle-legacy.sh
?? scripts/session-open.sh
?? scripts/team-audit-global.sh
?? scripts/team-audit-subtask.sh
?? scripts/team-run-task.sh
?? scripts/team-status.sh

codex
Les fichiers et les totaux correspondent au JSON, et la structure H1/H2 attendue est présente. Un point de prudence apparaît : le worktree global contient déjà des fichiers hors mission modifiés, donc je vais formuler le verdict sur le périmètre M20 vérifiable sans attribuer ces changements à cette exécution.
exec
/bin/zsh -lc "rg -n '"'```|'"\\[x\\]|Approved|APPROVED|validated|VALIDATED|STATUS: APPROVED|Status: APPROVED|migrate:fresh|push --force|FeatureFlagService|GATE_' reports/runbooks/RUNBOOK_*_2026-04-25.md" in /Users/1millnonstop/Downloads/projet/foodking-web/web/testttt
exec
/bin/zsh -lc "rg -o '[A-Za-z0-9_./-]+\\.(php|js|vue|md|json|yml|yaml|sh):[0-9]+' reports/runbooks/RUNBOOK_*_2026-04-25.md | wc -l" in /Users/1millnonstop/Downloads/projet/foodking-web/web/testttt
 succeeded in 0ms:
     368

 succeeded in 0ms:
reports/runbooks/RUNBOOK_FISCAL_SEQUENCE_BREAK_2026-04-25.md:7:- Linked gates: GATE_FISCAL_KIOSK_V1, GATE_SCHEMA_MIGRATIONS_V1
reports/runbooks/RUNBOOK_FISCAL_SEQUENCE_BREAK_2026-04-25.md:306:- Gate: `GATE_FISCAL_KIOSK_V1` (PENDING_HUMAN_GATE ou à drafter; aucune approbation dans ce runbook).
reports/runbooks/RUNBOOK_FISCAL_SEQUENCE_BREAK_2026-04-25.md:307:- Gate: `GATE_SCHEMA_MIGRATIONS_V1` (PENDING_HUMAN_GATE ou à drafter; aucune approbation dans ce runbook).
reports/runbooks/RUNBOOK_TPE_FAILURE_2026-04-25.md:7:- Linked gates: GATE_PAYMENT_LEDGER_V1, GATE_VERIFY_P0_FROZEN_CONSOLIDATED_2026-04-20
reports/runbooks/RUNBOOK_TPE_FAILURE_2026-04-25.md:298:- Gate: `GATE_PAYMENT_LEDGER_V1` (PENDING_HUMAN_GATE ou à drafter; aucune approbation dans ce runbook).
reports/runbooks/RUNBOOK_TPE_FAILURE_2026-04-25.md:299:- Gate: `GATE_VERIFY_P0_FROZEN_CONSOLIDATED_2026-04-20` (PENDING_HUMAN_GATE ou à drafter; aucune approbation dans ce runbook).
reports/runbooks/RUNBOOK_KDS_MULTISCREEN_DESYNC_2026-04-25.md:7:- Linked gates: GATE_KDS_BUMP_V1
reports/runbooks/RUNBOOK_KDS_MULTISCREEN_DESYNC_2026-04-25.md:287:  - Le manque `expected_status` body est un sujet gate `GATE_KDS_BUMP_V1`, pas une correction ops.
reports/runbooks/RUNBOOK_KDS_MULTISCREEN_DESYNC_2026-04-25.md:300:- Gate: `GATE_KDS_BUMP_V1` (PENDING_HUMAN_GATE ou à drafter; aucune approbation dans ce runbook).
reports/runbooks/RUNBOOK_INDEX_2026-04-25.md:25:| TPE failure | PLAN-06, PLAN-20, M-20 | GATE_PAYMENT_LEDGER_V1, GATE_VERIFY_P0_FROZEN_CONSOLIDATED_2026-04-20 | taux confirm backend, 401/403/422, timeout TPE |
reports/runbooks/RUNBOOK_INDEX_2026-04-25.md:27:| Kiosk network loss | PLAN-11, PLAN-20, M-20 | GATE_OFFLINE_SCOPE_V1 | `offline_`, polling failures, kiosk-event |
reports/runbooks/RUNBOOK_INDEX_2026-04-25.md:30:| Fiscal sequence break | PLAN-08, PLAN-13, PLAN-20, M-20 | GATE_FISCAL_KIOSK_V1, GATE_SCHEMA_MIGRATIONS_V1 | fiscal lock, Z chain, archive verify, preflight fiscal |
reports/runbooks/RUNBOOK_INDEX_2026-04-25.md:31:| KDS multi-screen desync | PLAN-07, PLAN-20, M-20 | GATE_KDS_BUMP_V1 | KDS 409, cap 50, transition count |
reports/runbooks/RUNBOOK_INDEX_2026-04-25.md:32:| Rollback canary | PLAN-15, PLAN-20, M-20 | GATE_GO_NO_GO_CAISSE_V1 + gates feature | payment_success_rate, fiscal_anomaly, kds_error_rate, preflight |
reports/runbooks/RUNBOOK_KIOSK_NETWORK_LOSS_2026-04-25.md:7:- Linked gates: GATE_OFFLINE_SCOPE_V1
reports/runbooks/RUNBOOK_KIOSK_NETWORK_LOSS_2026-04-25.md:110:  - Action: Refuser le contournement; basculer vers procédure autorisée par `GATE_OFFLINE_SCOPE_V1` sans paiement carte offline.
reports/runbooks/RUNBOOK_KIOSK_NETWORK_LOSS_2026-04-25.md:301:- Gate: `GATE_OFFLINE_SCOPE_V1` (PENDING_HUMAN_GATE ou à drafter; aucune approbation dans ce runbook).
reports/runbooks/RUNBOOK_ROLLBACK_CANARY_2026-04-25.md:7:- Linked gates: GATE_GO_NO_GO_CAISSE_V1, GATE_PAYMENT_LEDGER_V1, GATE_FISCAL_KIOSK_V1, GATE_KDS_BUMP_V1, GATE_OFFLINE_SCOPE_V1
reports/runbooks/RUNBOOK_ROLLBACK_CANARY_2026-04-25.md:34:- Aucun FeatureFlagService centralisé n’est cité; lieux à créer par M-15.
reports/runbooks/RUNBOOK_ROLLBACK_CANARY_2026-04-25.md:295:  - DOC_GAP: pas de FeatureFlagService centralisé vérifié; les emplacements flags sont à créer par M-15.
reports/runbooks/RUNBOOK_ROLLBACK_CANARY_2026-04-25.md:309:- Gate: `GATE_GO_NO_GO_CAISSE_V1` (PENDING_HUMAN_GATE ou à drafter; aucune approbation dans ce runbook).
reports/runbooks/RUNBOOK_ROLLBACK_CANARY_2026-04-25.md:310:- Gate: `GATE_PAYMENT_LEDGER_V1` (PENDING_HUMAN_GATE ou à drafter; aucune approbation dans ce runbook).
reports/runbooks/RUNBOOK_ROLLBACK_CANARY_2026-04-25.md:311:- Gate: `GATE_FISCAL_KIOSK_V1` (PENDING_HUMAN_GATE ou à drafter; aucune approbation dans ce runbook).
reports/runbooks/RUNBOOK_ROLLBACK_CANARY_2026-04-25.md:312:- Gate: `GATE_KDS_BUMP_V1` (PENDING_HUMAN_GATE ou à drafter; aucune approbation dans ce runbook).
reports/runbooks/RUNBOOK_ROLLBACK_CANARY_2026-04-25.md:313:- Gate: `GATE_OFFLINE_SCOPE_V1` (PENDING_HUMAN_GATE ou à drafter; aucune approbation dans ce runbook).

exec
/bin/zsh -lc "rg -n 'Status:|INDEX_STATUS|Owner|Severity ceiling|Plan source|Linked gates|Last reviewed|Action irréversible|L4|NF525|patch|réécrit|écriture brute|offline_|expected_status|OrderStatus|branch_id|after.?commit|commit|FrontendOrderService|OrderService' reports/runbooks/RUNBOOK_*_2026-04-25.md" in /Users/1millnonstop/Downloads/projet/foodking-web/web/testttt
 succeeded in 0ms:
reports/runbooks/RUNBOOK_PRINTER_FAILURE_2026-04-25.md:3:- Status: DRAFT_SKELETON_NOT_SIGNED
reports/runbooks/RUNBOOK_PRINTER_FAILURE_2026-04-25.md:4:- Owner (DRAFT): Ops
reports/runbooks/RUNBOOK_PRINTER_FAILURE_2026-04-25.md:5:- Severity ceiling: P0
reports/runbooks/RUNBOOK_PRINTER_FAILURE_2026-04-25.md:6:- Plan source: PLAN-20 (super master) / M-20 (masterplay)
reports/runbooks/RUNBOOK_PRINTER_FAILURE_2026-04-25.md:7:- Linked gates: (none)
reports/runbooks/RUNBOOK_PRINTER_FAILURE_2026-04-25.md:8:- Last reviewed: 2026-04-25 (initial skeleton)
reports/runbooks/RUNBOOK_PRINTER_FAILURE_2026-04-25.md:11:- Log `[EscPosPrinterService] print failed` avec `printer_id`, `branch_id`, `station` et erreur transport.
reports/runbooks/RUNBOOK_PRINTER_FAILURE_2026-04-25.md:26:- Toute alerte cross-branch doit être découpée par `branch_id` avant action.
reports/runbooks/RUNBOOK_PRINTER_FAILURE_2026-04-25.md:36:- Côté audit: absence de preuve `branch_id` ou absence d’horodatage UTC rend la sortie non recevable.
reports/runbooks/RUNBOOK_PRINTER_FAILURE_2026-04-25.md:37:- Côté produit: ne jamais compenser par une règle prix frontend ou un changement de statut hors `OrderStatus enum`.
reports/runbooks/RUNBOOK_PRINTER_FAILURE_2026-04-25.md:45:   - Preuve à conserver: capture horodatée UTC + branch_id + extrait de journal sans PII.
reports/runbooks/RUNBOOK_PRINTER_FAILURE_2026-04-25.md:46:   - Invariant à vérifier: branch_id du printer conservé..
reports/runbooks/RUNBOOK_PRINTER_FAILURE_2026-04-25.md:51:   - Preuve à conserver: capture horodatée UTC + branch_id + extrait de journal sans PII.
reports/runbooks/RUNBOOK_PRINTER_FAILURE_2026-04-25.md:57:   - Preuve à conserver: capture horodatée UTC + branch_id + extrait de journal sans PII.
reports/runbooks/RUNBOOK_PRINTER_FAILURE_2026-04-25.md:58:   - Invariant à vérifier: aucun contournement branch_id..
reports/runbooks/RUNBOOK_PRINTER_FAILURE_2026-04-25.md:63:   - Preuve à conserver: capture horodatée UTC + branch_id + extrait de journal sans PII.
reports/runbooks/RUNBOOK_PRINTER_FAILURE_2026-04-25.md:69:   - Preuve à conserver: capture horodatée UTC + branch_id + extrait de journal sans PII.
reports/runbooks/RUNBOOK_PRINTER_FAILURE_2026-04-25.md:70:   - Invariant à vérifier: branch_id printer strict..
reports/runbooks/RUNBOOK_PRINTER_FAILURE_2026-04-25.md:75:   - Preuve à conserver: capture horodatée UTC + branch_id + extrait de journal sans PII.
reports/runbooks/RUNBOOK_PRINTER_FAILURE_2026-04-25.md:81:   - Preuve à conserver: capture horodatée UTC + branch_id + extrait de journal sans PII.
reports/runbooks/RUNBOOK_PRINTER_FAILURE_2026-04-25.md:87:   - Preuve à conserver: capture horodatée UTC + branch_id + extrait de journal sans PII.
reports/runbooks/RUNBOOK_PRINTER_FAILURE_2026-04-25.md:88:   - Invariant à vérifier: branch_id auth du caissier..
reports/runbooks/RUNBOOK_PRINTER_FAILURE_2026-04-25.md:93:   - Preuve à conserver: capture horodatée UTC + branch_id + extrait de journal sans PII.
reports/runbooks/RUNBOOK_PRINTER_FAILURE_2026-04-25.md:99:   - Preuve à conserver: capture horodatée UTC + branch_id + extrait de journal sans PII.
reports/runbooks/RUNBOOK_PRINTER_FAILURE_2026-04-25.md:125:  - Action: Limiter le traitement et les communications à ce `branch_id`.
reports/runbooks/RUNBOOK_PRINTER_FAILURE_2026-04-25.md:126:  - Vérification post-action: Vérifier la présence de `branch_id` dans app/Services/Hardware/EscPosPrinterService.php:16-36.
reports/runbooks/RUNBOOK_PRINTER_FAILURE_2026-04-25.md:133:- Sortie P0: ticket incident mis à jour avec heure UTC, `branch_id`, owner, et prochaine décision.
reports/runbooks/RUNBOOK_PRINTER_FAILURE_2026-04-25.md:149:  - Vérification post-action: Noter l’heure UTC et le `branch_id` dans le ticket.
reports/runbooks/RUNBOOK_PRINTER_FAILURE_2026-04-25.md:166:- Sortie P1: ticket incident mis à jour avec heure UTC, `branch_id`, owner, et prochaine décision.
reports/runbooks/RUNBOOK_PRINTER_FAILURE_2026-04-25.md:194:- Sortie P2: ticket incident mis à jour avec heure UTC, `branch_id`, owner, et prochaine décision.
reports/runbooks/RUNBOOK_PRINTER_FAILURE_2026-04-25.md:197:| Niveau | Trigger | Rôle | Canal | Délai max | Action irréversible? |
reports/runbooks/RUNBOOK_PRINTER_FAILURE_2026-04-25.md:206:- [ ] `branch_id` concerné documenté; si global, justification explicite par rôle Admin/global.
reports/runbooks/RUNBOOK_PRINTER_FAILURE_2026-04-25.md:207:- [ ] Aucune écriture brute DB effectuée depuis ce runbook.
reports/runbooks/RUNBOOK_PRINTER_FAILURE_2026-04-25.md:211:- [ ] `OrderStatus enum` respecté; aucun statut littéral nouveau demandé.
reports/runbooks/RUNBOOK_PRINTER_FAILURE_2026-04-25.md:212:- [ ] `dispatch after commit` respecté; aucune relance ne contourne l’outbox.
reports/runbooks/RUNBOOK_PRINTER_FAILURE_2026-04-25.md:218:- [ ] Escalade L3/L4 déclenchée si seuil atteint.
reports/runbooks/RUNBOOK_PRINTER_FAILURE_2026-04-25.md:239:  - Champ obligatoire: branches touchées, avec `branch_id` explicite ou justification globale.
reports/runbooks/RUNBOOK_PRINTER_FAILURE_2026-04-25.md:245:  - Champ obligatoire: branches touchées, avec `branch_id` explicite ou justification globale.
reports/runbooks/RUNBOOK_PRINTER_FAILURE_2026-04-25.md:251:  - Champ obligatoire: branches touchées, avec `branch_id` explicite ou justification globale.
reports/runbooks/RUNBOOK_PRINTER_FAILURE_2026-04-25.md:257:  - Champ obligatoire: branches touchées, avec `branch_id` explicite ou justification globale.
reports/runbooks/RUNBOOK_PRINTER_FAILURE_2026-04-25.md:263:  - Champ obligatoire: branches touchées, avec `branch_id` explicite ou justification globale.
reports/runbooks/RUNBOOK_PRINTER_FAILURE_2026-04-25.md:269:  - Champ obligatoire: branches touchées, avec `branch_id` explicite ou justification globale.
reports/runbooks/RUNBOOK_PRINTER_FAILURE_2026-04-25.md:275:  - Champ obligatoire: branches touchées, avec `branch_id` explicite ou justification globale.
reports/runbooks/RUNBOOK_PRINTER_FAILURE_2026-04-25.md:281:  - OrderStatus enum authoritative: ne pas demander une correction par littéral numérique hors flux existant.
reports/runbooks/RUNBOOK_PRINTER_FAILURE_2026-04-25.md:282:  - branch_id isolation: toute preuve, capture, dashboard ou ticket doit porter la branche concernée.
reports/runbooks/RUNBOOK_PRINTER_FAILURE_2026-04-25.md:283:  - dispatch after commit: ne pas recommander un dispatch manuel qui contourne les garanties after-commit.
reports/runbooks/RUNBOOK_PRINTER_FAILURE_2026-04-25.md:284:  - OrderService / FrontendOrderService symmetry: toute divergence constatée devient point M-10, pas patch ops.
reports/runbooks/RUNBOOK_PRINTER_FAILURE_2026-04-25.md:309:- Invariant: `OrderStatus enum` authoritative.
reports/runbooks/RUNBOOK_PRINTER_FAILURE_2026-04-25.md:310:- Invariant: `branch_id` isolation strict.
reports/runbooks/RUNBOOK_PRINTER_FAILURE_2026-04-25.md:311:- Invariant: dispatch after commit.
reports/runbooks/RUNBOOK_PRINTER_FAILURE_2026-04-25.md:312:- Invariant: OrderService / FrontendOrderService symmetry.
reports/runbooks/RUNBOOK_KDS_MULTISCREEN_DESYNC_2026-04-25.md:3:- Status: DRAFT_SKELETON_NOT_SIGNED
reports/runbooks/RUNBOOK_KDS_MULTISCREEN_DESYNC_2026-04-25.md:4:- Owner (DRAFT): BE+FE
reports/runbooks/RUNBOOK_KDS_MULTISCREEN_DESYNC_2026-04-25.md:5:- Severity ceiling: P0
reports/runbooks/RUNBOOK_KDS_MULTISCREEN_DESYNC_2026-04-25.md:6:- Plan source: PLAN-20 (super master) / M-20 (masterplay)
reports/runbooks/RUNBOOK_KDS_MULTISCREEN_DESYNC_2026-04-25.md:7:- Linked gates: GATE_KDS_BUMP_V1
reports/runbooks/RUNBOOK_KDS_MULTISCREEN_DESYNC_2026-04-25.md:8:- Last reviewed: 2026-04-25 (initial skeleton)
reports/runbooks/RUNBOOK_KDS_MULTISCREEN_DESYNC_2026-04-25.md:13:- Request status ne porte pas `expected_status` body.
reports/runbooks/RUNBOOK_KDS_MULTISCREEN_DESYNC_2026-04-25.md:15:- OrderStatusTransition montre deux transitions proches ou absence transition attendue.
reports/runbooks/RUNBOOK_KDS_MULTISCREEN_DESYNC_2026-04-25.md:17:- Trigger evidence 1: signal à corréler avec `app/Services/KitchenDisplaySystemOrderService.php:53-54`.
reports/runbooks/RUNBOOK_KDS_MULTISCREEN_DESYNC_2026-04-25.md:18:- Trigger evidence 2: signal à corréler avec `app/Services/KitchenDisplaySystemOrderService.php:117-168`.
reports/runbooks/RUNBOOK_KDS_MULTISCREEN_DESYNC_2026-04-25.md:19:- Trigger evidence 3: signal à corréler avec `app/Services/KitchenDisplaySystemOrderService.php:130-133`.
reports/runbooks/RUNBOOK_KDS_MULTISCREEN_DESYNC_2026-04-25.md:20:- Trigger evidence 4: signal à corréler avec `app/Services/KitchenDisplaySystemOrderService.php:150-152`.
reports/runbooks/RUNBOOK_KDS_MULTISCREEN_DESYNC_2026-04-25.md:21:- Trigger evidence 5: signal à corréler avec `app/Services/KitchenDisplaySystemOrderService.php:158-165`.
reports/runbooks/RUNBOOK_KDS_MULTISCREEN_DESYNC_2026-04-25.md:22:- Trigger evidence 6: signal à corréler avec `app/Http/Requests/OrderStatusRequest.php:15-35`.
reports/runbooks/RUNBOOK_KDS_MULTISCREEN_DESYNC_2026-04-25.md:23:- Trigger evidence 7: signal à corréler avec `app/Http/Requests/OrderStatusRequest.php:45-47`.
reports/runbooks/RUNBOOK_KDS_MULTISCREEN_DESYNC_2026-04-25.md:26:- Toute alerte cross-branch doit être découpée par `branch_id` avant action.
reports/runbooks/RUNBOOK_KDS_MULTISCREEN_DESYNC_2026-04-25.md:32:- Aucune transition ne doit être réécrite depuis ops.
reports/runbooks/RUNBOOK_KDS_MULTISCREEN_DESYNC_2026-04-25.md:34:- Branch Manager ou Chef doit rester dans son `branch_id`.
reports/runbooks/RUNBOOK_KDS_MULTISCREEN_DESYNC_2026-04-25.md:36:- Côté audit: absence de preuve `branch_id` ou absence d’horodatage UTC rend la sortie non recevable.
reports/runbooks/RUNBOOK_KDS_MULTISCREEN_DESYNC_2026-04-25.md:37:- Côté produit: ne jamais compenser par une règle prix frontend ou un changement de statut hors `OrderStatus enum`.
reports/runbooks/RUNBOOK_KDS_MULTISCREEN_DESYNC_2026-04-25.md:43:   - Fichier:line à inspecter: `app/Services/KitchenDisplaySystemOrderService.php:53-54`.
reports/runbooks/RUNBOOK_KDS_MULTISCREEN_DESYNC_2026-04-25.md:45:   - Preuve à conserver: capture horodatée UTC + branch_id + extrait de journal sans PII.
reports/runbooks/RUNBOOK_KDS_MULTISCREEN_DESYNC_2026-04-25.md:46:   - Invariant à vérifier: OrderStatus enum..
reports/runbooks/RUNBOOK_KDS_MULTISCREEN_DESYNC_2026-04-25.md:49:   - Fichier:line à inspecter: `app/Services/KitchenDisplaySystemOrderService.php:117-168`.
reports/runbooks/RUNBOOK_KDS_MULTISCREEN_DESYNC_2026-04-25.md:51:   - Preuve à conserver: capture horodatée UTC + branch_id + extrait de journal sans PII.
reports/runbooks/RUNBOOK_KDS_MULTISCREEN_DESYNC_2026-04-25.md:52:   - Invariant à vérifier: branch_id check..
reports/runbooks/RUNBOOK_KDS_MULTISCREEN_DESYNC_2026-04-25.md:53:3. Identifier manque expected_status
reports/runbooks/RUNBOOK_KDS_MULTISCREEN_DESYNC_2026-04-25.md:55:   - Fichier:line à inspecter: `app/Http/Requests/OrderStatusRequest.php:45-47`.
reports/runbooks/RUNBOOK_KDS_MULTISCREEN_DESYNC_2026-04-25.md:56:   - Décision de bifurcation: absence expected_status = gate KDS bump; documenter, pas patch ops.
reports/runbooks/RUNBOOK_KDS_MULTISCREEN_DESYNC_2026-04-25.md:57:   - Preuve à conserver: capture horodatée UTC + branch_id + extrait de journal sans PII.
reports/runbooks/RUNBOOK_KDS_MULTISCREEN_DESYNC_2026-04-25.md:61:   - Fichier:line à inspecter: `app/Http/Requests/OrderStatusRequest.php:15-35`.
reports/runbooks/RUNBOOK_KDS_MULTISCREEN_DESYNC_2026-04-25.md:63:   - Preuve à conserver: capture horodatée UTC + branch_id + extrait de journal sans PII.
reports/runbooks/RUNBOOK_KDS_MULTISCREEN_DESYNC_2026-04-25.md:64:   - Invariant à vérifier: OrderStatus enum et permissions..
reports/runbooks/RUNBOOK_KDS_MULTISCREEN_DESYNC_2026-04-25.md:69:   - Preuve à conserver: capture horodatée UTC + branch_id + extrait de journal sans PII.
reports/runbooks/RUNBOOK_KDS_MULTISCREEN_DESYNC_2026-04-25.md:75:   - Preuve à conserver: capture horodatée UTC + branch_id + extrait de journal sans PII.
reports/runbooks/RUNBOOK_KDS_MULTISCREEN_DESYNC_2026-04-25.md:79:   - Fichier:line à inspecter: `app/Models/OrderStatusTransition.php:11-25`.
reports/runbooks/RUNBOOK_KDS_MULTISCREEN_DESYNC_2026-04-25.md:80:   - Décision de bifurcation: double transition = post-mortem; aucune réécriture.
reports/runbooks/RUNBOOK_KDS_MULTISCREEN_DESYNC_2026-04-25.md:81:   - Preuve à conserver: capture horodatée UTC + branch_id + extrait de journal sans PII.
reports/runbooks/RUNBOOK_KDS_MULTISCREEN_DESYNC_2026-04-25.md:85:   - Fichier:line à inspecter: `app/Services/KitchenDisplaySystemOrderService.php:130-133`.
reports/runbooks/RUNBOOK_KDS_MULTISCREEN_DESYNC_2026-04-25.md:87:   - Preuve à conserver: capture horodatée UTC + branch_id + extrait de journal sans PII.
reports/runbooks/RUNBOOK_KDS_MULTISCREEN_DESYNC_2026-04-25.md:88:   - Invariant à vérifier: branch_id strict..
reports/runbooks/RUNBOOK_KDS_MULTISCREEN_DESYNC_2026-04-25.md:91:   - Fichier:line à inspecter: `app/Services/KitchenDisplaySystemOrderService.php:150-152`.
reports/runbooks/RUNBOOK_KDS_MULTISCREEN_DESYNC_2026-04-25.md:93:   - Preuve à conserver: capture horodatée UTC + branch_id + extrait de journal sans PII.
reports/runbooks/RUNBOOK_KDS_MULTISCREEN_DESYNC_2026-04-25.md:94:   - Invariant à vérifier: OrderStatus enum..
reports/runbooks/RUNBOOK_KDS_MULTISCREEN_DESYNC_2026-04-25.md:97:   - Fichier:line à inspecter: `app/Services/KitchenDisplaySystemOrderService.php:158-165`.
reports/runbooks/RUNBOOK_KDS_MULTISCREEN_DESYNC_2026-04-25.md:99:   - Preuve à conserver: capture horodatée UTC + branch_id + extrait de journal sans PII.
reports/runbooks/RUNBOOK_KDS_MULTISCREEN_DESYNC_2026-04-25.md:111:  - Vérification post-action: Comparer incident et ancrage app/Services/KitchenDisplaySystemOrderService.php:53-54.
reports/runbooks/RUNBOOK_KDS_MULTISCREEN_DESYNC_2026-04-25.md:116:  - Vérification post-action: Conserver captures dashboard et journal lié à app/Services/KitchenDisplaySystemOrderService.php:117-168.
reports/runbooks/RUNBOOK_KDS_MULTISCREEN_DESYNC_2026-04-25.md:125:  - Action: Limiter le traitement et les communications à ce `branch_id`.
reports/runbooks/RUNBOOK_KDS_MULTISCREEN_DESYNC_2026-04-25.md:126:  - Vérification post-action: Vérifier la présence de `branch_id` dans app/Services/KitchenDisplaySystemOrderService.php:53-54.
reports/runbooks/RUNBOOK_KDS_MULTISCREEN_DESYNC_2026-04-25.md:131:  - Vérification post-action: Joindre les références app/Services/KitchenDisplaySystemOrderService.php:53-54 et app/Services/KitchenDisplaySystemOrderService.php:117-168.
reports/runbooks/RUNBOOK_KDS_MULTISCREEN_DESYNC_2026-04-25.md:133:- Sortie P0: ticket incident mis à jour avec heure UTC, `branch_id`, owner, et prochaine décision.
reports/runbooks/RUNBOOK_KDS_MULTISCREEN_DESYNC_2026-04-25.md:144:  - Vérification post-action: Comparer les symptômes au fichier app/Services/KitchenDisplaySystemOrderService.php:53-54.
reports/runbooks/RUNBOOK_KDS_MULTISCREEN_DESYNC_2026-04-25.md:149:  - Vérification post-action: Noter l’heure UTC et le `branch_id` dans le ticket.
reports/runbooks/RUNBOOK_KDS_MULTISCREEN_DESYNC_2026-04-25.md:166:- Sortie P1: ticket incident mis à jour avec heure UTC, `branch_id`, owner, et prochaine décision.
reports/runbooks/RUNBOOK_KDS_MULTISCREEN_DESYNC_2026-04-25.md:172:  - Vérification post-action: Ancrer le ticket sur app/Services/KitchenDisplaySystemOrderService.php:53-54.
reports/runbooks/RUNBOOK_KDS_MULTISCREEN_DESYNC_2026-04-25.md:194:- Sortie P2: ticket incident mis à jour avec heure UTC, `branch_id`, owner, et prochaine décision.
reports/runbooks/RUNBOOK_KDS_MULTISCREEN_DESYNC_2026-04-25.md:197:| Niveau | Trigger | Rôle | Canal | Délai max | Action irréversible? |
reports/runbooks/RUNBOOK_KDS_MULTISCREEN_DESYNC_2026-04-25.md:206:- [ ] `branch_id` concerné documenté; si global, justification explicite par rôle Admin/global.
reports/runbooks/RUNBOOK_KDS_MULTISCREEN_DESYNC_2026-04-25.md:207:- [ ] Aucune écriture brute DB effectuée depuis ce runbook.
reports/runbooks/RUNBOOK_KDS_MULTISCREEN_DESYNC_2026-04-25.md:211:- [ ] `OrderStatus enum` respecté; aucun statut littéral nouveau demandé.
reports/runbooks/RUNBOOK_KDS_MULTISCREEN_DESYNC_2026-04-25.md:212:- [ ] `dispatch after commit` respecté; aucune relance ne contourne l’outbox.
reports/runbooks/RUNBOOK_KDS_MULTISCREEN_DESYNC_2026-04-25.md:218:- [ ] Escalade L3/L4 déclenchée si seuil atteint.
reports/runbooks/RUNBOOK_KDS_MULTISCREEN_DESYNC_2026-04-25.md:225:- [ ] Preuve 1 reliée à `app/Services/KitchenDisplaySystemOrderService.php:53-54` dans le ticket ou dashboard.
reports/runbooks/RUNBOOK_KDS_MULTISCREEN_DESYNC_2026-04-25.md:226:- [ ] Preuve 2 reliée à `app/Services/KitchenDisplaySystemOrderService.php:117-168` dans le ticket ou dashboard.
reports/runbooks/RUNBOOK_KDS_MULTISCREEN_DESYNC_2026-04-25.md:227:- [ ] Preuve 3 reliée à `app/Services/KitchenDisplaySystemOrderService.php:130-133` dans le ticket ou dashboard.
reports/runbooks/RUNBOOK_KDS_MULTISCREEN_DESYNC_2026-04-25.md:228:- [ ] Preuve 4 reliée à `app/Services/KitchenDisplaySystemOrderService.php:150-152` dans le ticket ou dashboard.
reports/runbooks/RUNBOOK_KDS_MULTISCREEN_DESYNC_2026-04-25.md:229:- [ ] Preuve 5 reliée à `app/Services/KitchenDisplaySystemOrderService.php:158-165` dans le ticket ou dashboard.
reports/runbooks/RUNBOOK_KDS_MULTISCREEN_DESYNC_2026-04-25.md:230:- [ ] Preuve 6 reliée à `app/Http/Requests/OrderStatusRequest.php:15-35` dans le ticket ou dashboard.
reports/runbooks/RUNBOOK_KDS_MULTISCREEN_DESYNC_2026-04-25.md:231:- [ ] Preuve 7 reliée à `app/Http/Requests/OrderStatusRequest.php:45-47` dans le ticket ou dashboard.
reports/runbooks/RUNBOOK_KDS_MULTISCREEN_DESYNC_2026-04-25.md:234:- [ ] Preuve 10 reliée à `app/Models/OrderStatusTransition.php:11-25` dans le ticket ou dashboard.
reports/runbooks/RUNBOOK_KDS_MULTISCREEN_DESYNC_2026-04-25.md:239:  - Champ obligatoire: branches touchées, avec `branch_id` explicite ou justification globale.
reports/runbooks/RUNBOOK_KDS_MULTISCREEN_DESYNC_2026-04-25.md:245:  - Champ obligatoire: branches touchées, avec `branch_id` explicite ou justification globale.
reports/runbooks/RUNBOOK_KDS_MULTISCREEN_DESYNC_2026-04-25.md:251:  - Champ obligatoire: branches touchées, avec `branch_id` explicite ou justification globale.
reports/runbooks/RUNBOOK_KDS_MULTISCREEN_DESYNC_2026-04-25.md:257:  - Champ obligatoire: branches touchées, avec `branch_id` explicite ou justification globale.
reports/runbooks/RUNBOOK_KDS_MULTISCREEN_DESYNC_2026-04-25.md:263:  - Champ obligatoire: branches touchées, avec `branch_id` explicite ou justification globale.
reports/runbooks/RUNBOOK_KDS_MULTISCREEN_DESYNC_2026-04-25.md:269:  - Champ obligatoire: branches touchées, avec `branch_id` explicite ou justification globale.
reports/runbooks/RUNBOOK_KDS_MULTISCREEN_DESYNC_2026-04-25.md:275:  - Champ obligatoire: branches touchées, avec `branch_id` explicite ou justification globale.
reports/runbooks/RUNBOOK_KDS_MULTISCREEN_DESYNC_2026-04-25.md:281:  - OrderStatus enum authoritative: ne pas demander une correction par littéral numérique hors flux existant.
reports/runbooks/RUNBOOK_KDS_MULTISCREEN_DESYNC_2026-04-25.md:282:  - branch_id isolation: toute preuve, capture, dashboard ou ticket doit porter la branche concernée.
reports/runbooks/RUNBOOK_KDS_MULTISCREEN_DESYNC_2026-04-25.md:283:  - dispatch after commit: ne pas recommander un dispatch manuel qui contourne les garanties after-commit.
reports/runbooks/RUNBOOK_KDS_MULTISCREEN_DESYNC_2026-04-25.md:284:  - OrderService / FrontendOrderService symmetry: toute divergence constatée devient point M-10, pas patch ops.
reports/runbooks/RUNBOOK_KDS_MULTISCREEN_DESYNC_2026-04-25.md:287:  - Le manque `expected_status` body est un sujet gate `GATE_KDS_BUMP_V1`, pas une correction ops.
reports/runbooks/RUNBOOK_KDS_MULTISCREEN_DESYNC_2026-04-25.md:290:- `app/Services/KitchenDisplaySystemOrderService.php:53-54`
reports/runbooks/RUNBOOK_KDS_MULTISCREEN_DESYNC_2026-04-25.md:291:- `app/Services/KitchenDisplaySystemOrderService.php:117-168`
reports/runbooks/RUNBOOK_KDS_MULTISCREEN_DESYNC_2026-04-25.md:292:- `app/Services/KitchenDisplaySystemOrderService.php:130-133`
reports/runbooks/RUNBOOK_KDS_MULTISCREEN_DESYNC_2026-04-25.md:293:- `app/Services/KitchenDisplaySystemOrderService.php:150-152`
reports/runbooks/RUNBOOK_KDS_MULTISCREEN_DESYNC_2026-04-25.md:294:- `app/Services/KitchenDisplaySystemOrderService.php:158-165`
reports/runbooks/RUNBOOK_KDS_MULTISCREEN_DESYNC_2026-04-25.md:295:- `app/Http/Requests/OrderStatusRequest.php:15-35`
reports/runbooks/RUNBOOK_KDS_MULTISCREEN_DESYNC_2026-04-25.md:296:- `app/Http/Requests/OrderStatusRequest.php:45-47`
reports/runbooks/RUNBOOK_KDS_MULTISCREEN_DESYNC_2026-04-25.md:299:- `app/Models/OrderStatusTransition.php:11-25`
reports/runbooks/RUNBOOK_KDS_MULTISCREEN_DESYNC_2026-04-25.md:306:- Invariant: `OrderStatus enum` authoritative.
reports/runbooks/RUNBOOK_KDS_MULTISCREEN_DESYNC_2026-04-25.md:307:- Invariant: `branch_id` isolation strict.
reports/runbooks/RUNBOOK_KDS_MULTISCREEN_DESYNC_2026-04-25.md:308:- Invariant: dispatch after commit.
reports/runbooks/RUNBOOK_KDS_MULTISCREEN_DESYNC_2026-04-25.md:309:- Invariant: OrderService / FrontendOrderService symmetry.
reports/runbooks/RUNBOOK_FISCAL_SEQUENCE_BREAK_2026-04-25.md:1:# RUNBOOK — Rupture séquence fiscale NF525 (2026-04-25)
reports/runbooks/RUNBOOK_FISCAL_SEQUENCE_BREAK_2026-04-25.md:3:- Status: DRAFT_SKELETON_NOT_SIGNED
reports/runbooks/RUNBOOK_FISCAL_SEQUENCE_BREAK_2026-04-25.md:4:- Owner (DRAFT): NF525-QA
reports/runbooks/RUNBOOK_FISCAL_SEQUENCE_BREAK_2026-04-25.md:5:- Severity ceiling: P0
reports/runbooks/RUNBOOK_FISCAL_SEQUENCE_BREAK_2026-04-25.md:6:- Plan source: PLAN-20 (super master) / M-20 (masterplay)
reports/runbooks/RUNBOOK_FISCAL_SEQUENCE_BREAK_2026-04-25.md:7:- Linked gates: GATE_FISCAL_KIOSK_V1, GATE_SCHEMA_MIGRATIONS_V1
reports/runbooks/RUNBOOK_FISCAL_SEQUENCE_BREAK_2026-04-25.md:8:- Last reviewed: 2026-04-25 (initial skeleton)
reports/runbooks/RUNBOOK_FISCAL_SEQUENCE_BREAK_2026-04-25.md:15:- AuditLogService refuse `branch_id` null ou lock chain indisponible.
reports/runbooks/RUNBOOK_FISCAL_SEQUENCE_BREAK_2026-04-25.md:26:- Toute alerte cross-branch doit être découpée par `branch_id` avant action.
reports/runbooks/RUNBOOK_FISCAL_SEQUENCE_BREAK_2026-04-25.md:31:- Ops ne peut plus garantir séquence gap-free ou archive NF525.
reports/runbooks/RUNBOOK_FISCAL_SEQUENCE_BREAK_2026-04-25.md:36:- Côté audit: absence de preuve `branch_id` ou absence d’horodatage UTC rend la sortie non recevable.
reports/runbooks/RUNBOOK_FISCAL_SEQUENCE_BREAK_2026-04-25.md:37:- Côté produit: ne jamais compenser par une règle prix frontend ou un changement de statut hors `OrderStatus enum`.
reports/runbooks/RUNBOOK_FISCAL_SEQUENCE_BREAK_2026-04-25.md:44:   - Décision de bifurcation: lock ou sequence break = P0 + L4; ne pas patcher.
reports/runbooks/RUNBOOK_FISCAL_SEQUENCE_BREAK_2026-04-25.md:45:   - Preuve à conserver: capture horodatée UTC + branch_id + extrait de journal sans PII.
reports/runbooks/RUNBOOK_FISCAL_SEQUENCE_BREAK_2026-04-25.md:46:   - Invariant à vérifier: branch_id fiscal positif..
reports/runbooks/RUNBOOK_FISCAL_SEQUENCE_BREAK_2026-04-25.md:51:   - Preuve à conserver: capture horodatée UTC + branch_id + extrait de journal sans PII.
reports/runbooks/RUNBOOK_FISCAL_SEQUENCE_BREAK_2026-04-25.md:57:   - Preuve à conserver: capture horodatée UTC + branch_id + extrait de journal sans PII.
reports/runbooks/RUNBOOK_FISCAL_SEQUENCE_BREAK_2026-04-25.md:62:   - Décision de bifurcation: open conflict = escalade NF525; pas de second Z manuel.
reports/runbooks/RUNBOOK_FISCAL_SEQUENCE_BREAK_2026-04-25.md:63:   - Preuve à conserver: capture horodatée UTC + branch_id + extrait de journal sans PII.
reports/runbooks/RUNBOOK_FISCAL_SEQUENCE_BREAK_2026-04-25.md:68:   - Décision de bifurcation: close fail = freeze + L4; ne pas recomposer signature.
reports/runbooks/RUNBOOK_FISCAL_SEQUENCE_BREAK_2026-04-25.md:69:   - Preuve à conserver: capture horodatée UTC + branch_id + extrait de journal sans PII.
reports/runbooks/RUNBOOK_FISCAL_SEQUENCE_BREAK_2026-04-25.md:70:   - Invariant à vérifier: signature HMAC non réécrite..
reports/runbooks/RUNBOOK_FISCAL_SEQUENCE_BREAK_2026-04-25.md:74:   - Décision de bifurcation: X incohérent confirme besoin L4; X ne corrige rien.
reports/runbooks/RUNBOOK_FISCAL_SEQUENCE_BREAK_2026-04-25.md:75:   - Preuve à conserver: capture horodatée UTC + branch_id + extrait de journal sans PII.
reports/runbooks/RUNBOOK_FISCAL_SEQUENCE_BREAK_2026-04-25.md:78:   - Commande / observation: `php artisan foodking:fiscal:archive <branch_id> --from=<YYYY-MM-DD> --to=<YYYY-MM-DD>`
reports/runbooks/RUNBOOK_FISCAL_SEQUENCE_BREAK_2026-04-25.md:80:   - Décision de bifurcation: archive abort = preuve incident; ne pas utiliser --no-verify sans L4.
reports/runbooks/RUNBOOK_FISCAL_SEQUENCE_BREAK_2026-04-25.md:81:   - Preuve à conserver: capture horodatée UTC + branch_id + extrait de journal sans PII.
reports/runbooks/RUNBOOK_FISCAL_SEQUENCE_BREAK_2026-04-25.md:82:   - Invariant à vérifier: NF525 evidence..
reports/runbooks/RUNBOOK_FISCAL_SEQUENCE_BREAK_2026-04-25.md:87:   - Preuve à conserver: capture horodatée UTC + branch_id + extrait de journal sans PII.
reports/runbooks/RUNBOOK_FISCAL_SEQUENCE_BREAK_2026-04-25.md:91:   - Fichier:line à inspecter: `app/Services/OrderService.php:900-910`.
reports/runbooks/RUNBOOK_FISCAL_SEQUENCE_BREAK_2026-04-25.md:93:   - Preuve à conserver: capture horodatée UTC + branch_id + extrait de journal sans PII.
reports/runbooks/RUNBOOK_FISCAL_SEQUENCE_BREAK_2026-04-25.md:94:   - Invariant à vérifier: OrderService consommateur fiscal..
reports/runbooks/RUNBOOK_FISCAL_SEQUENCE_BREAK_2026-04-25.md:98:   - Décision de bifurcation: cashback sans audit = L4; ne pas corriger transaction brute.
reports/runbooks/RUNBOOK_FISCAL_SEQUENCE_BREAK_2026-04-25.md:99:   - Preuve à conserver: capture horodatée UTC + branch_id + extrait de journal sans PII.
reports/runbooks/RUNBOOK_FISCAL_SEQUENCE_BREAK_2026-04-25.md:110:  - Action: Freeze caisse de la branche + escalade L4 NF525 immédiate; aucune tentative de patch séquence.
reports/runbooks/RUNBOOK_FISCAL_SEQUENCE_BREAK_2026-04-25.md:111:  - Vérification post-action: Ticket contient branch_id, dernière séquence connue, Z/X status, archive evidence.
reports/runbooks/RUNBOOK_FISCAL_SEQUENCE_BREAK_2026-04-25.md:114:  - Précondition: Rupture séquence fiscale NF525 bloque encaissement ou préparation sur une branche.
reports/runbooks/RUNBOOK_FISCAL_SEQUENCE_BREAK_2026-04-25.md:130:  - Action: Limiter le traitement et les communications à ce `branch_id`.
reports/runbooks/RUNBOOK_FISCAL_SEQUENCE_BREAK_2026-04-25.md:131:  - Vérification post-action: Vérifier la présence de `branch_id` dans app/Services/Fiscal/FiscalSequenceService.php:57-94.
reports/runbooks/RUNBOOK_FISCAL_SEQUENCE_BREAK_2026-04-25.md:138:- Sortie P0: ticket incident mis à jour avec heure UTC, `branch_id`, owner, et prochaine décision.
reports/runbooks/RUNBOOK_FISCAL_SEQUENCE_BREAK_2026-04-25.md:142:  - Précondition: Rupture séquence fiscale NF525 dégrade le service sans arrêt total.
reports/runbooks/RUNBOOK_FISCAL_SEQUENCE_BREAK_2026-04-25.md:149:  - Vérification post-action: Noter l’heure UTC et le `branch_id` dans le ticket.
reports/runbooks/RUNBOOK_FISCAL_SEQUENCE_BREAK_2026-04-25.md:166:- Sortie P1: ticket incident mis à jour avec heure UTC, `branch_id`, owner, et prochaine décision.
reports/runbooks/RUNBOOK_FISCAL_SEQUENCE_BREAK_2026-04-25.md:194:- Sortie P2: ticket incident mis à jour avec heure UTC, `branch_id`, owner, et prochaine décision.
reports/runbooks/RUNBOOK_FISCAL_SEQUENCE_BREAK_2026-04-25.md:197:| Niveau | Trigger | Rôle | Canal | Délai max | Action irréversible? |
reports/runbooks/RUNBOOK_FISCAL_SEQUENCE_BREAK_2026-04-25.md:202:| L4 | Séquence, Z, archive ou audit chain compromise | NF525 / Conseil / humain fiscal | Appel direct + dossier incident | Immédiat | Oui, toute décision fiscale |
reports/runbooks/RUNBOOK_FISCAL_SEQUENCE_BREAK_2026-04-25.md:207:- [ ] Dossier NF525 evidence exporté ou explicitement conservé si archive abort.
reports/runbooks/RUNBOOK_FISCAL_SEQUENCE_BREAK_2026-04-25.md:209:- [ ] `branch_id` concerné documenté; si global, justification explicite par rôle Admin/global.
reports/runbooks/RUNBOOK_FISCAL_SEQUENCE_BREAK_2026-04-25.md:210:- [ ] Aucune écriture brute DB effectuée depuis ce runbook.
reports/runbooks/RUNBOOK_FISCAL_SEQUENCE_BREAK_2026-04-25.md:214:- [ ] `OrderStatus enum` respecté; aucun statut littéral nouveau demandé.
reports/runbooks/RUNBOOK_FISCAL_SEQUENCE_BREAK_2026-04-25.md:215:- [ ] `dispatch after commit` respecté; aucune relance ne contourne l’outbox.
reports/runbooks/RUNBOOK_FISCAL_SEQUENCE_BREAK_2026-04-25.md:221:- [ ] Escalade L3/L4 déclenchée si seuil atteint.
reports/runbooks/RUNBOOK_FISCAL_SEQUENCE_BREAK_2026-04-25.md:242:  - Champ obligatoire: branches touchées, avec `branch_id` explicite ou justification globale.
reports/runbooks/RUNBOOK_FISCAL_SEQUENCE_BREAK_2026-04-25.md:248:  - Champ obligatoire: branches touchées, avec `branch_id` explicite ou justification globale.
reports/runbooks/RUNBOOK_FISCAL_SEQUENCE_BREAK_2026-04-25.md:254:  - Champ obligatoire: branches touchées, avec `branch_id` explicite ou justification globale.
reports/runbooks/RUNBOOK_FISCAL_SEQUENCE_BREAK_2026-04-25.md:260:  - Champ obligatoire: branches touchées, avec `branch_id` explicite ou justification globale.
reports/runbooks/RUNBOOK_FISCAL_SEQUENCE_BREAK_2026-04-25.md:266:  - Champ obligatoire: branches touchées, avec `branch_id` explicite ou justification globale.
reports/runbooks/RUNBOOK_FISCAL_SEQUENCE_BREAK_2026-04-25.md:272:  - Champ obligatoire: branches touchées, avec `branch_id` explicite ou justification globale.
reports/runbooks/RUNBOOK_FISCAL_SEQUENCE_BREAK_2026-04-25.md:278:  - Champ obligatoire: branches touchées, avec `branch_id` explicite ou justification globale.
reports/runbooks/RUNBOOK_FISCAL_SEQUENCE_BREAK_2026-04-25.md:284:  - OrderStatus enum authoritative: ne pas demander une correction par littéral numérique hors flux existant.
reports/runbooks/RUNBOOK_FISCAL_SEQUENCE_BREAK_2026-04-25.md:285:  - branch_id isolation: toute preuve, capture, dashboard ou ticket doit porter la branche concernée.
reports/runbooks/RUNBOOK_FISCAL_SEQUENCE_BREAK_2026-04-25.md:286:  - dispatch after commit: ne pas recommander un dispatch manuel qui contourne les garanties after-commit.
reports/runbooks/RUNBOOK_FISCAL_SEQUENCE_BREAK_2026-04-25.md:287:  - OrderService / FrontendOrderService symmetry: toute divergence constatée devient point M-10, pas patch ops.
reports/runbooks/RUNBOOK_FISCAL_SEQUENCE_BREAK_2026-04-25.md:290:  - Aucune ligne ne propose de patcher, renuméroter ou reconstruire une séquence fiscale.
reports/runbooks/RUNBOOK_FISCAL_SEQUENCE_BREAK_2026-04-25.md:303:- `app/Services/OrderService.php:900-910`
reports/runbooks/RUNBOOK_FISCAL_SEQUENCE_BREAK_2026-04-25.md:304:- `app/Services/OrderService.php:1602-1624`
reports/runbooks/RUNBOOK_FISCAL_SEQUENCE_BREAK_2026-04-25.md:313:- Invariant: `OrderStatus enum` authoritative.
reports/runbooks/RUNBOOK_FISCAL_SEQUENCE_BREAK_2026-04-25.md:314:- Invariant: `branch_id` isolation strict.
reports/runbooks/RUNBOOK_FISCAL_SEQUENCE_BREAK_2026-04-25.md:315:- Invariant: dispatch after commit.
reports/runbooks/RUNBOOK_FISCAL_SEQUENCE_BREAK_2026-04-25.md:316:- Invariant: OrderService / FrontendOrderService symmetry.
reports/runbooks/RUNBOOK_KIOSK_NETWORK_LOSS_2026-04-25.md:3:- Status: DRAFT_SKELETON_NOT_SIGNED
reports/runbooks/RUNBOOK_KIOSK_NETWORK_LOSS_2026-04-25.md:4:- Owner (DRAFT): BE+FE
reports/runbooks/RUNBOOK_KIOSK_NETWORK_LOSS_2026-04-25.md:5:- Severity ceiling: P0
reports/runbooks/RUNBOOK_KIOSK_NETWORK_LOSS_2026-04-25.md:6:- Plan source: PLAN-20 (super master) / M-20 (masterplay)
reports/runbooks/RUNBOOK_KIOSK_NETWORK_LOSS_2026-04-25.md:7:- Linked gates: GATE_OFFLINE_SCOPE_V1
reports/runbooks/RUNBOOK_KIOSK_NETWORK_LOSS_2026-04-25.md:8:- Last reviewed: 2026-04-25 (initial skeleton)
reports/runbooks/RUNBOOK_KIOSK_NETWORK_LOSS_2026-04-25.md:11:- Kiosk crée un `localKey` préfixé `offline_` au lieu d’un identifiant serveur.
reports/runbooks/RUNBOOK_KIOSK_NETWORK_LOSS_2026-04-25.md:12:- Écran waiting détecte `offline_` et suspend polling serveur.
reports/runbooks/RUNBOOK_KIOSK_NETWORK_LOSS_2026-04-25.md:26:- Toute alerte cross-branch doit être découpée par `branch_id` avant action.
reports/runbooks/RUNBOOK_KIOSK_NETWORK_LOSS_2026-04-25.md:34:- Caissier doit distinguer ID `offline_` et order id serveur.
reports/runbooks/RUNBOOK_KIOSK_NETWORK_LOSS_2026-04-25.md:36:- Côté audit: absence de preuve `branch_id` ou absence d’horodatage UTC rend la sortie non recevable.
reports/runbooks/RUNBOOK_KIOSK_NETWORK_LOSS_2026-04-25.md:37:- Côté produit: ne jamais compenser par une règle prix frontend ou un changement de statut hors `OrderStatus enum`.
reports/runbooks/RUNBOOK_KIOSK_NETWORK_LOSS_2026-04-25.md:44:   - Décision de bifurcation: préfixe `offline_` = local only; ne jamais le traiter comme order id serveur.
reports/runbooks/RUNBOOK_KIOSK_NETWORK_LOSS_2026-04-25.md:45:   - Preuve à conserver: capture horodatée UTC + branch_id + extrait de journal sans PII.
reports/runbooks/RUNBOOK_KIOSK_NETWORK_LOSS_2026-04-25.md:46:   - Invariant à vérifier: branch_id local ne remplace pas serveur..
reports/runbooks/RUNBOOK_KIOSK_NETWORK_LOSS_2026-04-25.md:51:   - Preuve à conserver: capture horodatée UTC + branch_id + extrait de journal sans PII.
reports/runbooks/RUNBOOK_KIOSK_NETWORK_LOSS_2026-04-25.md:57:   - Preuve à conserver: capture horodatée UTC + branch_id + extrait de journal sans PII.
reports/runbooks/RUNBOOK_KIOSK_NETWORK_LOSS_2026-04-25.md:63:   - Preuve à conserver: capture horodatée UTC + branch_id + extrait de journal sans PII.
reports/runbooks/RUNBOOK_KIOSK_NETWORK_LOSS_2026-04-25.md:69:   - Preuve à conserver: capture horodatée UTC + branch_id + extrait de journal sans PII.
reports/runbooks/RUNBOOK_KIOSK_NETWORK_LOSS_2026-04-25.md:75:   - Preuve à conserver: capture horodatée UTC + branch_id + extrait de journal sans PII.
reports/runbooks/RUNBOOK_KIOSK_NETWORK_LOSS_2026-04-25.md:80:   - Décision de bifurcation: cancel avec `offline_` ne doit pas devenir mutation serveur.
reports/runbooks/RUNBOOK_KIOSK_NETWORK_LOSS_2026-04-25.md:81:   - Preuve à conserver: capture horodatée UTC + branch_id + extrait de journal sans PII.
reports/runbooks/RUNBOOK_KIOSK_NETWORK_LOSS_2026-04-25.md:82:   - Invariant à vérifier: OrderStatus enum; pas de littéral nouveau..
reports/runbooks/RUNBOOK_KIOSK_NETWORK_LOSS_2026-04-25.md:87:   - Preuve à conserver: capture horodatée UTC + branch_id + extrait de journal sans PII.
reports/runbooks/RUNBOOK_KIOSK_NETWORK_LOSS_2026-04-25.md:88:   - Invariant à vérifier: branch_id côté serveur ignoré depuis payload..
reports/runbooks/RUNBOOK_KIOSK_NETWORK_LOSS_2026-04-25.md:93:   - Preuve à conserver: capture horodatée UTC + branch_id + extrait de journal sans PII.
reports/runbooks/RUNBOOK_KIOSK_NETWORK_LOSS_2026-04-25.md:94:   - Invariant à vérifier: branch_id cache..
reports/runbooks/RUNBOOK_KIOSK_NETWORK_LOSS_2026-04-25.md:99:   - Preuve à conserver: capture horodatée UTC + branch_id + extrait de journal sans PII.
reports/runbooks/RUNBOOK_KIOSK_NETWORK_LOSS_2026-04-25.md:130:  - Action: Limiter le traitement et les communications à ce `branch_id`.
reports/runbooks/RUNBOOK_KIOSK_NETWORK_LOSS_2026-04-25.md:131:  - Vérification post-action: Vérifier la présence de `branch_id` dans resources/js/helpers/kioskOfflineQueue.js:134-145.
reports/runbooks/RUNBOOK_KIOSK_NETWORK_LOSS_2026-04-25.md:138:- Sortie P0: ticket incident mis à jour avec heure UTC, `branch_id`, owner, et prochaine décision.
reports/runbooks/RUNBOOK_KIOSK_NETWORK_LOSS_2026-04-25.md:149:  - Vérification post-action: Noter l’heure UTC et le `branch_id` dans le ticket.
reports/runbooks/RUNBOOK_KIOSK_NETWORK_LOSS_2026-04-25.md:166:- Sortie P1: ticket incident mis à jour avec heure UTC, `branch_id`, owner, et prochaine décision.
reports/runbooks/RUNBOOK_KIOSK_NETWORK_LOSS_2026-04-25.md:194:- Sortie P2: ticket incident mis à jour avec heure UTC, `branch_id`, owner, et prochaine décision.
reports/runbooks/RUNBOOK_KIOSK_NETWORK_LOSS_2026-04-25.md:197:| Niveau | Trigger | Rôle | Canal | Délai max | Action irréversible? |
reports/runbooks/RUNBOOK_KIOSK_NETWORK_LOSS_2026-04-25.md:206:- [ ] `branch_id` concerné documenté; si global, justification explicite par rôle Admin/global.
reports/runbooks/RUNBOOK_KIOSK_NETWORK_LOSS_2026-04-25.md:207:- [ ] Aucune écriture brute DB effectuée depuis ce runbook.
reports/runbooks/RUNBOOK_KIOSK_NETWORK_LOSS_2026-04-25.md:211:- [ ] `OrderStatus enum` respecté; aucun statut littéral nouveau demandé.
reports/runbooks/RUNBOOK_KIOSK_NETWORK_LOSS_2026-04-25.md:212:- [ ] `dispatch after commit` respecté; aucune relance ne contourne l’outbox.
reports/runbooks/RUNBOOK_KIOSK_NETWORK_LOSS_2026-04-25.md:218:- [ ] Escalade L3/L4 déclenchée si seuil atteint.
reports/runbooks/RUNBOOK_KIOSK_NETWORK_LOSS_2026-04-25.md:239:  - Champ obligatoire: branches touchées, avec `branch_id` explicite ou justification globale.
reports/runbooks/RUNBOOK_KIOSK_NETWORK_LOSS_2026-04-25.md:245:  - Champ obligatoire: branches touchées, avec `branch_id` explicite ou justification globale.
reports/runbooks/RUNBOOK_KIOSK_NETWORK_LOSS_2026-04-25.md:251:  - Champ obligatoire: branches touchées, avec `branch_id` explicite ou justification globale.
reports/runbooks/RUNBOOK_KIOSK_NETWORK_LOSS_2026-04-25.md:257:  - Champ obligatoire: branches touchées, avec `branch_id` explicite ou justification globale.
reports/runbooks/RUNBOOK_KIOSK_NETWORK_LOSS_2026-04-25.md:263:  - Champ obligatoire: branches touchées, avec `branch_id` explicite ou justification globale.
reports/runbooks/RUNBOOK_KIOSK_NETWORK_LOSS_2026-04-25.md:269:  - Champ obligatoire: branches touchées, avec `branch_id` explicite ou justification globale.
reports/runbooks/RUNBOOK_KIOSK_NETWORK_LOSS_2026-04-25.md:275:  - Champ obligatoire: branches touchées, avec `branch_id` explicite ou justification globale.
reports/runbooks/RUNBOOK_KIOSK_NETWORK_LOSS_2026-04-25.md:281:  - OrderStatus enum authoritative: ne pas demander une correction par littéral numérique hors flux existant.
reports/runbooks/RUNBOOK_KIOSK_NETWORK_LOSS_2026-04-25.md:282:  - branch_id isolation: toute preuve, capture, dashboard ou ticket doit porter la branche concernée.
reports/runbooks/RUNBOOK_KIOSK_NETWORK_LOSS_2026-04-25.md:283:  - dispatch after commit: ne pas recommander un dispatch manuel qui contourne les garanties after-commit.
reports/runbooks/RUNBOOK_KIOSK_NETWORK_LOSS_2026-04-25.md:284:  - OrderService / FrontendOrderService symmetry: toute divergence constatée devient point M-10, pas patch ops.
reports/runbooks/RUNBOOK_KIOSK_NETWORK_LOSS_2026-04-25.md:287:  - Tout ID `offline_` est local; la reconciliation seule peut produire un id serveur.
reports/runbooks/RUNBOOK_KIOSK_NETWORK_LOSS_2026-04-25.md:307:- Invariant: `OrderStatus enum` authoritative.
reports/runbooks/RUNBOOK_KIOSK_NETWORK_LOSS_2026-04-25.md:308:- Invariant: `branch_id` isolation strict.
reports/runbooks/RUNBOOK_KIOSK_NETWORK_LOSS_2026-04-25.md:309:- Invariant: dispatch after commit.
reports/runbooks/RUNBOOK_KIOSK_NETWORK_LOSS_2026-04-25.md:310:- Invariant: OrderService / FrontendOrderService symmetry.
reports/runbooks/RUNBOOK_OUTBOX_BLOCKED_2026-04-25.md:3:- Status: DRAFT_SKELETON_NOT_SIGNED
reports/runbooks/RUNBOOK_OUTBOX_BLOCKED_2026-04-25.md:4:- Owner (DRAFT): BE+DevOps
reports/runbooks/RUNBOOK_OUTBOX_BLOCKED_2026-04-25.md:5:- Severity ceiling: P0
reports/runbooks/RUNBOOK_OUTBOX_BLOCKED_2026-04-25.md:6:- Plan source: PLAN-20 (super master) / M-20 (masterplay)
reports/runbooks/RUNBOOK_OUTBOX_BLOCKED_2026-04-25.md:7:- Linked gates: (none)
reports/runbooks/RUNBOOK_OUTBOX_BLOCKED_2026-04-25.md:8:- Last reviewed: 2026-04-25 (initial skeleton)
reports/runbooks/RUNBOOK_OUTBOX_BLOCKED_2026-04-25.md:16:- KDS/POS ne reçoivent plus broadcasts malgré DB commit.
reports/runbooks/RUNBOOK_OUTBOX_BLOCKED_2026-04-25.md:21:- Trigger evidence 5: signal à corréler avec `app/Listeners/PersistOrderStatusChangedToOutbox.php:19-40`.
reports/runbooks/RUNBOOK_OUTBOX_BLOCKED_2026-04-25.md:26:- Toute alerte cross-branch doit être découpée par `branch_id` avant action.
reports/runbooks/RUNBOOK_OUTBOX_BLOCKED_2026-04-25.md:31:- Ops voit événements pending sans `dispatched_at`.
reports/runbooks/RUNBOOK_OUTBOX_BLOCKED_2026-04-25.md:33:- Branches affectées visibles dans `domain_events.branch_id`.
reports/runbooks/RUNBOOK_OUTBOX_BLOCKED_2026-04-25.md:36:- Côté audit: absence de preuve `branch_id` ou absence d’horodatage UTC rend la sortie non recevable.
reports/runbooks/RUNBOOK_OUTBOX_BLOCKED_2026-04-25.md:37:- Côté produit: ne jamais compenser par une règle prix frontend ou un changement de statut hors `OrderStatus enum`.
reports/runbooks/RUNBOOK_OUTBOX_BLOCKED_2026-04-25.md:45:   - Preuve à conserver: capture horodatée UTC + branch_id + extrait de journal sans PII.
reports/runbooks/RUNBOOK_OUTBOX_BLOCKED_2026-04-25.md:46:   - Invariant à vérifier: pas d’écriture brute DB..
reports/runbooks/RUNBOOK_OUTBOX_BLOCKED_2026-04-25.md:51:   - Preuve à conserver: capture horodatée UTC + branch_id + extrait de journal sans PII.
reports/runbooks/RUNBOOK_OUTBOX_BLOCKED_2026-04-25.md:52:   - Invariant à vérifier: dispatch via job existant..
reports/runbooks/RUNBOOK_OUTBOX_BLOCKED_2026-04-25.md:57:   - Preuve à conserver: capture horodatée UTC + branch_id + extrait de journal sans PII.
reports/runbooks/RUNBOOK_OUTBOX_BLOCKED_2026-04-25.md:59:4. Contrôler after-commit producer order created
reports/runbooks/RUNBOOK_OUTBOX_BLOCKED_2026-04-25.md:62:   - Décision de bifurcation: afterCommit présent = producer conforme; chercher dispatch job.
reports/runbooks/RUNBOOK_OUTBOX_BLOCKED_2026-04-25.md:63:   - Preuve à conserver: capture horodatée UTC + branch_id + extrait de journal sans PII.
reports/runbooks/RUNBOOK_OUTBOX_BLOCKED_2026-04-25.md:64:   - Invariant à vérifier: dispatch after commit..
reports/runbooks/RUNBOOK_OUTBOX_BLOCKED_2026-04-25.md:65:5. Contrôler after-commit status changed
reports/runbooks/RUNBOOK_OUTBOX_BLOCKED_2026-04-25.md:67:   - Fichier:line à inspecter: `app/Listeners/PersistOrderStatusChangedToOutbox.php:19-40`.
reports/runbooks/RUNBOOK_OUTBOX_BLOCKED_2026-04-25.md:68:   - Décision de bifurcation: status event persiste branch_id; vérifier payload contract.
reports/runbooks/RUNBOOK_OUTBOX_BLOCKED_2026-04-25.md:69:   - Preuve à conserver: capture horodatée UTC + branch_id + extrait de journal sans PII.
reports/runbooks/RUNBOOK_OUTBOX_BLOCKED_2026-04-25.md:70:   - Invariant à vérifier: OrderStatus enum source..
reports/runbooks/RUNBOOK_OUTBOX_BLOCKED_2026-04-25.md:75:   - Preuve à conserver: capture horodatée UTC + branch_id + extrait de journal sans PII.
reports/runbooks/RUNBOOK_OUTBOX_BLOCKED_2026-04-25.md:76:   - Invariant à vérifier: branch_id channel..
reports/runbooks/RUNBOOK_OUTBOX_BLOCKED_2026-04-25.md:81:   - Preuve à conserver: capture horodatée UTC + branch_id + extrait de journal sans PII.
reports/runbooks/RUNBOOK_OUTBOX_BLOCKED_2026-04-25.md:82:   - Invariant à vérifier: branch_id payload clair..
reports/runbooks/RUNBOOK_OUTBOX_BLOCKED_2026-04-25.md:87:   - Preuve à conserver: capture horodatée UTC + branch_id + extrait de journal sans PII.
reports/runbooks/RUNBOOK_OUTBOX_BLOCKED_2026-04-25.md:89:9. Vérifier dispatch after commit trait
reports/runbooks/RUNBOOK_OUTBOX_BLOCKED_2026-04-25.md:91:   - Fichier:line à inspecter: `app/Events/Concerns/DispatchableAfterCommit.php:8-42`.
reports/runbooks/RUNBOOK_OUTBOX_BLOCKED_2026-04-25.md:93:   - Preuve à conserver: capture horodatée UTC + branch_id + extrait de journal sans PII.
reports/runbooks/RUNBOOK_OUTBOX_BLOCKED_2026-04-25.md:94:   - Invariant à vérifier: commit_before_dispatch..
reports/runbooks/RUNBOOK_OUTBOX_BLOCKED_2026-04-25.md:98:   - Décision de bifurcation: recent_failures oriente event_type et branch_id.
reports/runbooks/RUNBOOK_OUTBOX_BLOCKED_2026-04-25.md:99:   - Preuve à conserver: capture horodatée UTC + branch_id + extrait de journal sans PII.
reports/runbooks/RUNBOOK_OUTBOX_BLOCKED_2026-04-25.md:100:   - Invariant à vérifier: branch_id strict..
reports/runbooks/RUNBOOK_OUTBOX_BLOCKED_2026-04-25.md:110:  - Action: Utiliser uniquement les commandes rescue/retry existantes et escalader L2; aucune écriture brute DB.
reports/runbooks/RUNBOOK_OUTBOX_BLOCKED_2026-04-25.md:111:  - Vérification post-action: recent_failures baisse et events reçoivent `dispatched_at`.
reports/runbooks/RUNBOOK_OUTBOX_BLOCKED_2026-04-25.md:112:  - Impact attendu: Temps réel restauré sans casser commit-before-dispatch.
reports/runbooks/RUNBOOK_OUTBOX_BLOCKED_2026-04-25.md:130:  - Action: Limiter le traitement et les communications à ce `branch_id`.
reports/runbooks/RUNBOOK_OUTBOX_BLOCKED_2026-04-25.md:131:  - Vérification post-action: Vérifier la présence de `branch_id` dans app/Models/DomainEvent.php:33-48.
reports/runbooks/RUNBOOK_OUTBOX_BLOCKED_2026-04-25.md:138:- Sortie P0: ticket incident mis à jour avec heure UTC, `branch_id`, owner, et prochaine décision.
reports/runbooks/RUNBOOK_OUTBOX_BLOCKED_2026-04-25.md:149:  - Vérification post-action: Noter l’heure UTC et le `branch_id` dans le ticket.
reports/runbooks/RUNBOOK_OUTBOX_BLOCKED_2026-04-25.md:166:- Sortie P1: ticket incident mis à jour avec heure UTC, `branch_id`, owner, et prochaine décision.
reports/runbooks/RUNBOOK_OUTBOX_BLOCKED_2026-04-25.md:194:- Sortie P2: ticket incident mis à jour avec heure UTC, `branch_id`, owner, et prochaine décision.
reports/runbooks/RUNBOOK_OUTBOX_BLOCKED_2026-04-25.md:197:| Niveau | Trigger | Rôle | Canal | Délai max | Action irréversible? |
reports/runbooks/RUNBOOK_OUTBOX_BLOCKED_2026-04-25.md:206:- [ ] `branch_id` concerné documenté; si global, justification explicite par rôle Admin/global.
reports/runbooks/RUNBOOK_OUTBOX_BLOCKED_2026-04-25.md:207:- [ ] Aucune écriture brute DB effectuée depuis ce runbook.
reports/runbooks/RUNBOOK_OUTBOX_BLOCKED_2026-04-25.md:211:- [ ] `OrderStatus enum` respecté; aucun statut littéral nouveau demandé.
reports/runbooks/RUNBOOK_OUTBOX_BLOCKED_2026-04-25.md:212:- [ ] `dispatch after commit` respecté; aucune relance ne contourne l’outbox.
reports/runbooks/RUNBOOK_OUTBOX_BLOCKED_2026-04-25.md:218:- [ ] Escalade L3/L4 déclenchée si seuil atteint.
reports/runbooks/RUNBOOK_OUTBOX_BLOCKED_2026-04-25.md:229:- [ ] Preuve 5 reliée à `app/Listeners/PersistOrderStatusChangedToOutbox.php:19-40` dans le ticket ou dashboard.
reports/runbooks/RUNBOOK_OUTBOX_BLOCKED_2026-04-25.md:233:- [ ] Preuve 9 reliée à `app/Events/Concerns/DispatchableAfterCommit.php:8-42` dans le ticket ou dashboard.
reports/runbooks/RUNBOOK_OUTBOX_BLOCKED_2026-04-25.md:234:- [ ] Preuve 10 reliée à `app/Jobs/DispatchDomainEventsJob.php:62-89` dans le ticket ou dashboard.
reports/runbooks/RUNBOOK_OUTBOX_BLOCKED_2026-04-25.md:239:  - Champ obligatoire: branches touchées, avec `branch_id` explicite ou justification globale.
reports/runbooks/RUNBOOK_OUTBOX_BLOCKED_2026-04-25.md:245:  - Champ obligatoire: branches touchées, avec `branch_id` explicite ou justification globale.
reports/runbooks/RUNBOOK_OUTBOX_BLOCKED_2026-04-25.md:251:  - Champ obligatoire: branches touchées, avec `branch_id` explicite ou justification globale.
reports/runbooks/RUNBOOK_OUTBOX_BLOCKED_2026-04-25.md:257:  - Champ obligatoire: branches touchées, avec `branch_id` explicite ou justification globale.
reports/runbooks/RUNBOOK_OUTBOX_BLOCKED_2026-04-25.md:263:  - Champ obligatoire: branches touchées, avec `branch_id` explicite ou justification globale.
reports/runbooks/RUNBOOK_OUTBOX_BLOCKED_2026-04-25.md:269:  - Champ obligatoire: branches touchées, avec `branch_id` explicite ou justification globale.
reports/runbooks/RUNBOOK_OUTBOX_BLOCKED_2026-04-25.md:275:  - Champ obligatoire: branches touchées, avec `branch_id` explicite ou justification globale.
reports/runbooks/RUNBOOK_OUTBOX_BLOCKED_2026-04-25.md:281:  - OrderStatus enum authoritative: ne pas demander une correction par littéral numérique hors flux existant.
reports/runbooks/RUNBOOK_OUTBOX_BLOCKED_2026-04-25.md:282:  - branch_id isolation: toute preuve, capture, dashboard ou ticket doit porter la branche concernée.
reports/runbooks/RUNBOOK_OUTBOX_BLOCKED_2026-04-25.md:283:  - dispatch after commit: ne pas recommander un dispatch manuel qui contourne les garanties after-commit.
reports/runbooks/RUNBOOK_OUTBOX_BLOCKED_2026-04-25.md:284:  - OrderService / FrontendOrderService symmetry: toute divergence constatée devient point M-10, pas patch ops.
reports/runbooks/RUNBOOK_OUTBOX_BLOCKED_2026-04-25.md:294:- `app/Listeners/PersistOrderStatusChangedToOutbox.php:19-40`
reports/runbooks/RUNBOOK_OUTBOX_BLOCKED_2026-04-25.md:298:- `app/Events/Concerns/DispatchableAfterCommit.php:8-42`
reports/runbooks/RUNBOOK_OUTBOX_BLOCKED_2026-04-25.md:299:- `app/Jobs/DispatchDomainEventsJob.php:62-89`
reports/runbooks/RUNBOOK_OUTBOX_BLOCKED_2026-04-25.md:307:- Invariant: `OrderStatus enum` authoritative.
reports/runbooks/RUNBOOK_OUTBOX_BLOCKED_2026-04-25.md:308:- Invariant: `branch_id` isolation strict.
reports/runbooks/RUNBOOK_OUTBOX_BLOCKED_2026-04-25.md:309:- Invariant: dispatch after commit.
reports/runbooks/RUNBOOK_OUTBOX_BLOCKED_2026-04-25.md:310:- Invariant: OrderService / FrontendOrderService symmetry.
reports/runbooks/RUNBOOK_ROLLBACK_CANARY_2026-04-25.md:3:- Status: DRAFT_SKELETON_NOT_SIGNED
reports/runbooks/RUNBOOK_ROLLBACK_CANARY_2026-04-25.md:4:- Owner (DRAFT): DevOps
reports/runbooks/RUNBOOK_ROLLBACK_CANARY_2026-04-25.md:5:- Severity ceiling: P0
reports/runbooks/RUNBOOK_ROLLBACK_CANARY_2026-04-25.md:6:- Plan source: PLAN-20 (super master) / M-20 (masterplay)
reports/runbooks/RUNBOOK_ROLLBACK_CANARY_2026-04-25.md:7:- Linked gates: GATE_GO_NO_GO_CAISSE_V1, GATE_PAYMENT_LEDGER_V1, GATE_FISCAL_KIOSK_V1, GATE_KDS_BUMP_V1, GATE_OFFLINE_SCOPE_V1
reports/runbooks/RUNBOOK_ROLLBACK_CANARY_2026-04-25.md:8:- Last reviewed: 2026-04-25 (initial skeleton)
reports/runbooks/RUNBOOK_ROLLBACK_CANARY_2026-04-25.md:26:- Toute alerte cross-branch doit être découpée par `branch_id` avant action.
reports/runbooks/RUNBOOK_ROLLBACK_CANARY_2026-04-25.md:36:- Côté audit: absence de preuve `branch_id` ou absence d’horodatage UTC rend la sortie non recevable.
reports/runbooks/RUNBOOK_ROLLBACK_CANARY_2026-04-25.md:37:- Côté produit: ne jamais compenser par une règle prix frontend ou un changement de statut hors `OrderStatus enum`.
reports/runbooks/RUNBOOK_ROLLBACK_CANARY_2026-04-25.md:45:   - Preuve à conserver: capture horodatée UTC + branch_id + extrait de journal sans PII.
reports/runbooks/RUNBOOK_ROLLBACK_CANARY_2026-04-25.md:51:   - Preuve à conserver: capture horodatée UTC + branch_id + extrait de journal sans PII.
reports/runbooks/RUNBOOK_ROLLBACK_CANARY_2026-04-25.md:52:   - Invariant à vérifier: OrderService/FOS symmetry..
reports/runbooks/RUNBOOK_ROLLBACK_CANARY_2026-04-25.md:57:   - Preuve à conserver: capture horodatée UTC + branch_id + extrait de journal sans PII.
reports/runbooks/RUNBOOK_ROLLBACK_CANARY_2026-04-25.md:58:   - Invariant à vérifier: OrderStatus enum..
reports/runbooks/RUNBOOK_ROLLBACK_CANARY_2026-04-25.md:63:   - Preuve à conserver: capture horodatée UTC + branch_id + extrait de journal sans PII.
reports/runbooks/RUNBOOK_ROLLBACK_CANARY_2026-04-25.md:69:   - Preuve à conserver: capture horodatée UTC + branch_id + extrait de journal sans PII.
reports/runbooks/RUNBOOK_ROLLBACK_CANARY_2026-04-25.md:70:   - Invariant à vérifier: NF525 evidence..
reports/runbooks/RUNBOOK_ROLLBACK_CANARY_2026-04-25.md:74:   - Décision de bifurcation: `kiosk_offline_strict` cible offline; ne jamais activer CB/TR offline sans gate.
reports/runbooks/RUNBOOK_ROLLBACK_CANARY_2026-04-25.md:75:   - Preuve à conserver: capture horodatée UTC + branch_id + extrait de journal sans PII.
reports/runbooks/RUNBOOK_ROLLBACK_CANARY_2026-04-25.md:81:   - Preuve à conserver: capture horodatée UTC + branch_id + extrait de journal sans PII.
reports/runbooks/RUNBOOK_ROLLBACK_CANARY_2026-04-25.md:87:   - Preuve à conserver: capture horodatée UTC + branch_id + extrait de journal sans PII.
reports/runbooks/RUNBOOK_ROLLBACK_CANARY_2026-04-25.md:93:   - Preuve à conserver: capture horodatée UTC + branch_id + extrait de journal sans PII.
reports/runbooks/RUNBOOK_ROLLBACK_CANARY_2026-04-25.md:99:   - Preuve à conserver: capture horodatée UTC + branch_id + extrait de journal sans PII.
reports/runbooks/RUNBOOK_ROLLBACK_CANARY_2026-04-25.md:105:- Mapping cible `fiscal_z_v1`: backend fiscal Z; rollback DB/migration réservé M-13; décision NF525 humaine obligatoire.
reports/runbooks/RUNBOOK_ROLLBACK_CANARY_2026-04-25.md:106:- Mapping cible `kiosk_offline_strict`: bundle frontend kiosk + garde backend offline; CB/TR offline reste interdit sans gate.
reports/runbooks/RUNBOOK_ROLLBACK_CANARY_2026-04-25.md:138:  - Action: Limiter le traitement et les communications à ce `branch_id`.
reports/runbooks/RUNBOOK_ROLLBACK_CANARY_2026-04-25.md:139:  - Vérification post-action: Vérifier la présence de `branch_id` dans plans/PLAN_CAISSE_V1_GPT_MASTERPLAY_2026-04-25.md:437.
reports/runbooks/RUNBOOK_ROLLBACK_CANARY_2026-04-25.md:146:- Sortie P0: ticket incident mis à jour avec heure UTC, `branch_id`, owner, et prochaine décision.
reports/runbooks/RUNBOOK_ROLLBACK_CANARY_2026-04-25.md:157:  - Vérification post-action: Noter l’heure UTC et le `branch_id` dans le ticket.
reports/runbooks/RUNBOOK_ROLLBACK_CANARY_2026-04-25.md:174:- Sortie P1: ticket incident mis à jour avec heure UTC, `branch_id`, owner, et prochaine décision.
reports/runbooks/RUNBOOK_ROLLBACK_CANARY_2026-04-25.md:202:- Sortie P2: ticket incident mis à jour avec heure UTC, `branch_id`, owner, et prochaine décision.
reports/runbooks/RUNBOOK_ROLLBACK_CANARY_2026-04-25.md:205:| Niveau | Trigger | Rôle | Canal | Délai max | Action irréversible? |
reports/runbooks/RUNBOOK_ROLLBACK_CANARY_2026-04-25.md:214:- [ ] `branch_id` concerné documenté; si global, justification explicite par rôle Admin/global.
reports/runbooks/RUNBOOK_ROLLBACK_CANARY_2026-04-25.md:215:- [ ] Aucune écriture brute DB effectuée depuis ce runbook.
reports/runbooks/RUNBOOK_ROLLBACK_CANARY_2026-04-25.md:219:- [ ] `OrderStatus enum` respecté; aucun statut littéral nouveau demandé.
reports/runbooks/RUNBOOK_ROLLBACK_CANARY_2026-04-25.md:220:- [ ] `dispatch after commit` respecté; aucune relance ne contourne l’outbox.
reports/runbooks/RUNBOOK_ROLLBACK_CANARY_2026-04-25.md:226:- [ ] Escalade L3/L4 déclenchée si seuil atteint.
reports/runbooks/RUNBOOK_ROLLBACK_CANARY_2026-04-25.md:247:  - Champ obligatoire: branches touchées, avec `branch_id` explicite ou justification globale.
reports/runbooks/RUNBOOK_ROLLBACK_CANARY_2026-04-25.md:253:  - Champ obligatoire: branches touchées, avec `branch_id` explicite ou justification globale.
reports/runbooks/RUNBOOK_ROLLBACK_CANARY_2026-04-25.md:259:  - Champ obligatoire: branches touchées, avec `branch_id` explicite ou justification globale.
reports/runbooks/RUNBOOK_ROLLBACK_CANARY_2026-04-25.md:265:  - Champ obligatoire: branches touchées, avec `branch_id` explicite ou justification globale.
reports/runbooks/RUNBOOK_ROLLBACK_CANARY_2026-04-25.md:271:  - Champ obligatoire: branches touchées, avec `branch_id` explicite ou justification globale.
reports/runbooks/RUNBOOK_ROLLBACK_CANARY_2026-04-25.md:277:  - Champ obligatoire: branches touchées, avec `branch_id` explicite ou justification globale.
reports/runbooks/RUNBOOK_ROLLBACK_CANARY_2026-04-25.md:283:  - Champ obligatoire: branches touchées, avec `branch_id` explicite ou justification globale.
reports/runbooks/RUNBOOK_ROLLBACK_CANARY_2026-04-25.md:289:  - OrderStatus enum authoritative: ne pas demander une correction par littéral numérique hors flux existant.
reports/runbooks/RUNBOOK_ROLLBACK_CANARY_2026-04-25.md:290:  - branch_id isolation: toute preuve, capture, dashboard ou ticket doit porter la branche concernée.
reports/runbooks/RUNBOOK_ROLLBACK_CANARY_2026-04-25.md:291:  - dispatch after commit: ne pas recommander un dispatch manuel qui contourne les garanties after-commit.
reports/runbooks/RUNBOOK_ROLLBACK_CANARY_2026-04-25.md:292:  - OrderService / FrontendOrderService symmetry: toute divergence constatée devient point M-10, pas patch ops.
reports/runbooks/RUNBOOK_ROLLBACK_CANARY_2026-04-25.md:319:- Invariant: `OrderStatus enum` authoritative.
reports/runbooks/RUNBOOK_ROLLBACK_CANARY_2026-04-25.md:320:- Invariant: `branch_id` isolation strict.
reports/runbooks/RUNBOOK_ROLLBACK_CANARY_2026-04-25.md:321:- Invariant: dispatch after commit.
reports/runbooks/RUNBOOK_ROLLBACK_CANARY_2026-04-25.md:322:- Invariant: OrderService / FrontendOrderService symmetry.
reports/runbooks/RUNBOOK_DISPATCH_QUEUE_SATURATED_2026-04-25.md:1:# RUNBOOK — Queue dispatch saturée / events domaine (2026-04-25)
reports/runbooks/RUNBOOK_DISPATCH_QUEUE_SATURATED_2026-04-25.md:3:- Status: DRAFT_SKELETON_NOT_SIGNED
reports/runbooks/RUNBOOK_DISPATCH_QUEUE_SATURATED_2026-04-25.md:4:- Owner (DRAFT): DevOps
reports/runbooks/RUNBOOK_DISPATCH_QUEUE_SATURATED_2026-04-25.md:5:- Severity ceiling: P0
reports/runbooks/RUNBOOK_DISPATCH_QUEUE_SATURATED_2026-04-25.md:6:- Plan source: PLAN-20 (super master) / M-20 (masterplay)
reports/runbooks/RUNBOOK_DISPATCH_QUEUE_SATURATED_2026-04-25.md:7:- Linked gates: (none)
reports/runbooks/RUNBOOK_DISPATCH_QUEUE_SATURATED_2026-04-25.md:8:- Last reviewed: 2026-04-25 (initial skeleton)
reports/runbooks/RUNBOOK_DISPATCH_QUEUE_SATURATED_2026-04-25.md:12:- Commande `queue:failed` liste `DispatchDomainEventsJob` ou voisins récurrents.
reports/runbooks/RUNBOOK_DISPATCH_QUEUE_SATURATED_2026-04-25.md:13:- Log final failure contient `[DispatchDomainEventsJob] Final failure dispatching domain event`.
reports/runbooks/RUNBOOK_DISPATCH_QUEUE_SATURATED_2026-04-25.md:14:- Sentry breadcrumb `category=queue.dispatch_domain_events.failed` présent.
reports/runbooks/RUNBOOK_DISPATCH_QUEUE_SATURATED_2026-04-25.md:15:- Dashboard sync montre p95 dispatch au-dessus de 2000 ms.
reports/runbooks/RUNBOOK_DISPATCH_QUEUE_SATURATED_2026-04-25.md:16:- KDS/POS ne reçoivent plus d’événements malgré transactions commitées.
reports/runbooks/RUNBOOK_DISPATCH_QUEUE_SATURATED_2026-04-25.md:17:- Trigger evidence 1: signal à corréler avec `app/Jobs/DispatchDomainEventsJob.php:62-89`.
reports/runbooks/RUNBOOK_DISPATCH_QUEUE_SATURATED_2026-04-25.md:18:- Trigger evidence 2: signal à corréler avec `app/Jobs/DispatchDomainEventsJob.php:153-161`.
reports/runbooks/RUNBOOK_DISPATCH_QUEUE_SATURATED_2026-04-25.md:19:- Trigger evidence 3: signal à corréler avec `app/Jobs/DispatchDomainEventsJob.php:177-208`.
reports/runbooks/RUNBOOK_DISPATCH_QUEUE_SATURATED_2026-04-25.md:20:- Trigger evidence 4: signal à corréler avec `app/Jobs/DispatchDomainEventsJob.php:208-220`.
reports/runbooks/RUNBOOK_DISPATCH_QUEUE_SATURATED_2026-04-25.md:26:- Toute alerte cross-branch doit être découpée par `branch_id` avant action.
reports/runbooks/RUNBOOK_DISPATCH_QUEUE_SATURATED_2026-04-25.md:34:- Plusieurs branches peuvent être touchées; scoper dashboard par `branch_id`.
reports/runbooks/RUNBOOK_DISPATCH_QUEUE_SATURATED_2026-04-25.md:36:- Côté audit: absence de preuve `branch_id` ou absence d’horodatage UTC rend la sortie non recevable.
reports/runbooks/RUNBOOK_DISPATCH_QUEUE_SATURATED_2026-04-25.md:37:- Côté produit: ne jamais compenser par une règle prix frontend ou un changement de statut hors `OrderStatus enum`.
reports/runbooks/RUNBOOK_DISPATCH_QUEUE_SATURATED_2026-04-25.md:45:   - Preuve à conserver: capture horodatée UTC + branch_id + extrait de journal sans PII.
reports/runbooks/RUNBOOK_DISPATCH_QUEUE_SATURATED_2026-04-25.md:49:   - Fichier:line à inspecter: `app/Jobs/DispatchDomainEventsJob.php:177-208`.
reports/runbooks/RUNBOOK_DISPATCH_QUEUE_SATURATED_2026-04-25.md:50:   - Décision de bifurcation: final failures dispatch = outbox/event issue; voisins = traiter lane spécifique.
reports/runbooks/RUNBOOK_DISPATCH_QUEUE_SATURATED_2026-04-25.md:51:   - Preuve à conserver: capture horodatée UTC + branch_id + extrait de journal sans PII.
reports/runbooks/RUNBOOK_DISPATCH_QUEUE_SATURATED_2026-04-25.md:52:   - Invariant à vérifier: dispatch after commit..
reports/runbooks/RUNBOOK_DISPATCH_QUEUE_SATURATED_2026-04-25.md:55:   - Fichier:line à inspecter: `app/Jobs/DispatchDomainEventsJob.php:62-89`.
reports/runbooks/RUNBOOK_DISPATCH_QUEUE_SATURATED_2026-04-25.md:57:   - Preuve à conserver: capture horodatée UTC + branch_id + extrait de journal sans PII.
reports/runbooks/RUNBOOK_DISPATCH_QUEUE_SATURATED_2026-04-25.md:61:   - Fichier:line à inspecter: `app/Jobs/DispatchDomainEventsJob.php:153-161`.
reports/runbooks/RUNBOOK_DISPATCH_QUEUE_SATURATED_2026-04-25.md:63:   - Preuve à conserver: capture horodatée UTC + branch_id + extrait de journal sans PII.
reports/runbooks/RUNBOOK_DISPATCH_QUEUE_SATURATED_2026-04-25.md:67:   - Fichier:line à inspecter: `app/Jobs/DispatchDomainEventsJob.php:208-220`.
reports/runbooks/RUNBOOK_DISPATCH_QUEUE_SATURATED_2026-04-25.md:69:   - Preuve à conserver: capture horodatée UTC + branch_id + extrait de journal sans PII.
reports/runbooks/RUNBOOK_DISPATCH_QUEUE_SATURATED_2026-04-25.md:75:   - Preuve à conserver: capture horodatée UTC + branch_id + extrait de journal sans PII.
reports/runbooks/RUNBOOK_DISPATCH_QUEUE_SATURATED_2026-04-25.md:76:   - Invariant à vérifier: dispatch async prod..
reports/runbooks/RUNBOOK_DISPATCH_QUEUE_SATURATED_2026-04-25.md:80:   - Décision de bifurcation: notifications saturées ≠ dispatch high saturé.
reports/runbooks/RUNBOOK_DISPATCH_QUEUE_SATURATED_2026-04-25.md:81:   - Preuve à conserver: capture horodatée UTC + branch_id + extrait de journal sans PII.
reports/runbooks/RUNBOOK_DISPATCH_QUEUE_SATURATED_2026-04-25.md:86:   - Décision de bifurcation: cleanup seul = incident kiosk pending, pas queue dispatch globale.
reports/runbooks/RUNBOOK_DISPATCH_QUEUE_SATURATED_2026-04-25.md:87:   - Preuve à conserver: capture horodatée UTC + branch_id + extrait de journal sans PII.
reports/runbooks/RUNBOOK_DISPATCH_QUEUE_SATURATED_2026-04-25.md:88:   - Invariant à vérifier: OrderStatus enum..
reports/runbooks/RUNBOOK_DISPATCH_QUEUE_SATURATED_2026-04-25.md:89:9. Lire métrique dispatch latency
reports/runbooks/RUNBOOK_DISPATCH_QUEUE_SATURATED_2026-04-25.md:93:   - Preuve à conserver: capture horodatée UTC + branch_id + extrait de journal sans PII.
reports/runbooks/RUNBOOK_DISPATCH_QUEUE_SATURATED_2026-04-25.md:99:   - Preuve à conserver: capture horodatée UTC + branch_id + extrait de journal sans PII.
reports/runbooks/RUNBOOK_DISPATCH_QUEUE_SATURATED_2026-04-25.md:100:   - Invariant à vérifier: branch_id strict dashboard..
reports/runbooks/RUNBOOK_DISPATCH_QUEUE_SATURATED_2026-04-25.md:109:  - Précondition: Queue dispatch saturée / events domaine bloque encaissement ou préparation sur une branche.
reports/runbooks/RUNBOOK_DISPATCH_QUEUE_SATURATED_2026-04-25.md:111:  - Vérification post-action: Comparer incident et ancrage app/Jobs/DispatchDomainEventsJob.php:62-89.
reports/runbooks/RUNBOOK_DISPATCH_QUEUE_SATURATED_2026-04-25.md:116:  - Vérification post-action: Conserver captures dashboard et journal lié à app/Jobs/DispatchDomainEventsJob.php:153-161.
reports/runbooks/RUNBOOK_DISPATCH_QUEUE_SATURATED_2026-04-25.md:125:  - Action: Limiter le traitement et les communications à ce `branch_id`.
reports/runbooks/RUNBOOK_DISPATCH_QUEUE_SATURATED_2026-04-25.md:126:  - Vérification post-action: Vérifier la présence de `branch_id` dans app/Jobs/DispatchDomainEventsJob.php:62-89.
reports/runbooks/RUNBOOK_DISPATCH_QUEUE_SATURATED_2026-04-25.md:131:  - Vérification post-action: Joindre les références app/Jobs/DispatchDomainEventsJob.php:62-89 et app/Jobs/DispatchDomainEventsJob.php:153-161.
reports/runbooks/RUNBOOK_DISPATCH_QUEUE_SATURATED_2026-04-25.md:133:- Sortie P0: ticket incident mis à jour avec heure UTC, `branch_id`, owner, et prochaine décision.
reports/runbooks/RUNBOOK_DISPATCH_QUEUE_SATURATED_2026-04-25.md:142:  - Précondition: Queue dispatch saturée / events domaine dégrade le service sans arrêt total.
reports/runbooks/RUNBOOK_DISPATCH_QUEUE_SATURATED_2026-04-25.md:144:  - Vérification post-action: Comparer les symptômes au fichier app/Jobs/DispatchDomainEventsJob.php:62-89.
reports/runbooks/RUNBOOK_DISPATCH_QUEUE_SATURATED_2026-04-25.md:149:  - Vérification post-action: Noter l’heure UTC et le `branch_id` dans le ticket.
reports/runbooks/RUNBOOK_DISPATCH_QUEUE_SATURATED_2026-04-25.md:166:- Sortie P1: ticket incident mis à jour avec heure UTC, `branch_id`, owner, et prochaine décision.
reports/runbooks/RUNBOOK_DISPATCH_QUEUE_SATURATED_2026-04-25.md:172:  - Vérification post-action: Ancrer le ticket sur app/Jobs/DispatchDomainEventsJob.php:62-89.
reports/runbooks/RUNBOOK_DISPATCH_QUEUE_SATURATED_2026-04-25.md:194:- Sortie P2: ticket incident mis à jour avec heure UTC, `branch_id`, owner, et prochaine décision.
reports/runbooks/RUNBOOK_DISPATCH_QUEUE_SATURATED_2026-04-25.md:197:| Niveau | Trigger | Rôle | Canal | Délai max | Action irréversible? |
reports/runbooks/RUNBOOK_DISPATCH_QUEUE_SATURATED_2026-04-25.md:206:- [ ] `branch_id` concerné documenté; si global, justification explicite par rôle Admin/global.
reports/runbooks/RUNBOOK_DISPATCH_QUEUE_SATURATED_2026-04-25.md:207:- [ ] Aucune écriture brute DB effectuée depuis ce runbook.
reports/runbooks/RUNBOOK_DISPATCH_QUEUE_SATURATED_2026-04-25.md:211:- [ ] `OrderStatus enum` respecté; aucun statut littéral nouveau demandé.
reports/runbooks/RUNBOOK_DISPATCH_QUEUE_SATURATED_2026-04-25.md:212:- [ ] `dispatch after commit` respecté; aucune relance ne contourne l’outbox.
reports/runbooks/RUNBOOK_DISPATCH_QUEUE_SATURATED_2026-04-25.md:218:- [ ] Escalade L3/L4 déclenchée si seuil atteint.
reports/runbooks/RUNBOOK_DISPATCH_QUEUE_SATURATED_2026-04-25.md:225:- [ ] Preuve 1 reliée à `app/Jobs/DispatchDomainEventsJob.php:62-89` dans le ticket ou dashboard.
reports/runbooks/RUNBOOK_DISPATCH_QUEUE_SATURATED_2026-04-25.md:226:- [ ] Preuve 2 reliée à `app/Jobs/DispatchDomainEventsJob.php:153-161` dans le ticket ou dashboard.
reports/runbooks/RUNBOOK_DISPATCH_QUEUE_SATURATED_2026-04-25.md:227:- [ ] Preuve 3 reliée à `app/Jobs/DispatchDomainEventsJob.php:177-208` dans le ticket ou dashboard.
reports/runbooks/RUNBOOK_DISPATCH_QUEUE_SATURATED_2026-04-25.md:228:- [ ] Preuve 4 reliée à `app/Jobs/DispatchDomainEventsJob.php:208-220` dans le ticket ou dashboard.
reports/runbooks/RUNBOOK_DISPATCH_QUEUE_SATURATED_2026-04-25.md:239:  - Champ obligatoire: branches touchées, avec `branch_id` explicite ou justification globale.
reports/runbooks/RUNBOOK_DISPATCH_QUEUE_SATURATED_2026-04-25.md:245:  - Champ obligatoire: branches touchées, avec `branch_id` explicite ou justification globale.
reports/runbooks/RUNBOOK_DISPATCH_QUEUE_SATURATED_2026-04-25.md:251:  - Champ obligatoire: branches touchées, avec `branch_id` explicite ou justification globale.
reports/runbooks/RUNBOOK_DISPATCH_QUEUE_SATURATED_2026-04-25.md:257:  - Champ obligatoire: branches touchées, avec `branch_id` explicite ou justification globale.
reports/runbooks/RUNBOOK_DISPATCH_QUEUE_SATURATED_2026-04-25.md:263:  - Champ obligatoire: branches touchées, avec `branch_id` explicite ou justification globale.
reports/runbooks/RUNBOOK_DISPATCH_QUEUE_SATURATED_2026-04-25.md:269:  - Champ obligatoire: branches touchées, avec `branch_id` explicite ou justification globale.
reports/runbooks/RUNBOOK_DISPATCH_QUEUE_SATURATED_2026-04-25.md:275:  - Champ obligatoire: branches touchées, avec `branch_id` explicite ou justification globale.
reports/runbooks/RUNBOOK_DISPATCH_QUEUE_SATURATED_2026-04-25.md:281:  - OrderStatus enum authoritative: ne pas demander une correction par littéral numérique hors flux existant.
reports/runbooks/RUNBOOK_DISPATCH_QUEUE_SATURATED_2026-04-25.md:282:  - branch_id isolation: toute preuve, capture, dashboard ou ticket doit porter la branche concernée.
reports/runbooks/RUNBOOK_DISPATCH_QUEUE_SATURATED_2026-04-25.md:283:  - dispatch after commit: ne pas recommander un dispatch manuel qui contourne les garanties after-commit.
reports/runbooks/RUNBOOK_DISPATCH_QUEUE_SATURATED_2026-04-25.md:284:  - OrderService / FrontendOrderService symmetry: toute divergence constatée devient point M-10, pas patch ops.
reports/runbooks/RUNBOOK_DISPATCH_QUEUE_SATURATED_2026-04-25.md:290:- `app/Jobs/DispatchDomainEventsJob.php:62-89`
reports/runbooks/RUNBOOK_DISPATCH_QUEUE_SATURATED_2026-04-25.md:291:- `app/Jobs/DispatchDomainEventsJob.php:153-161`
reports/runbooks/RUNBOOK_DISPATCH_QUEUE_SATURATED_2026-04-25.md:292:- `app/Jobs/DispatchDomainEventsJob.php:177-208`
reports/runbooks/RUNBOOK_DISPATCH_QUEUE_SATURATED_2026-04-25.md:293:- `app/Jobs/DispatchDomainEventsJob.php:208-220`
reports/runbooks/RUNBOOK_DISPATCH_QUEUE_SATURATED_2026-04-25.md:307:- Invariant: `OrderStatus enum` authoritative.
reports/runbooks/RUNBOOK_DISPATCH_QUEUE_SATURATED_2026-04-25.md:308:- Invariant: `branch_id` isolation strict.
reports/runbooks/RUNBOOK_DISPATCH_QUEUE_SATURATED_2026-04-25.md:309:- Invariant: dispatch after commit.
reports/runbooks/RUNBOOK_DISPATCH_QUEUE_SATURATED_2026-04-25.md:310:- Invariant: OrderService / FrontendOrderService symmetry.
reports/runbooks/RUNBOOK_TPE_FAILURE_2026-04-25.md:3:- Status: DRAFT_SKELETON_NOT_SIGNED
reports/runbooks/RUNBOOK_TPE_FAILURE_2026-04-25.md:4:- Owner (DRAFT): BE+FE
reports/runbooks/RUNBOOK_TPE_FAILURE_2026-04-25.md:5:- Severity ceiling: P0
reports/runbooks/RUNBOOK_TPE_FAILURE_2026-04-25.md:6:- Plan source: PLAN-20 (super master) / M-20 (masterplay)
reports/runbooks/RUNBOOK_TPE_FAILURE_2026-04-25.md:7:- Linked gates: GATE_PAYMENT_LEDGER_V1, GATE_VERIFY_P0_FROZEN_CONSOLIDATED_2026-04-20
reports/runbooks/RUNBOOK_TPE_FAILURE_2026-04-25.md:8:- Last reviewed: 2026-04-25 (initial skeleton)
reports/runbooks/RUNBOOK_TPE_FAILURE_2026-04-25.md:22:- Trigger evidence 6: signal à corréler avec `app/Services/FrontendOrderService.php:791`.
reports/runbooks/RUNBOOK_TPE_FAILURE_2026-04-25.md:26:- Toute alerte cross-branch doit être découpée par `branch_id` avant action.
reports/runbooks/RUNBOOK_TPE_FAILURE_2026-04-25.md:36:- Côté audit: absence de preuve `branch_id` ou absence d’horodatage UTC rend la sortie non recevable.
reports/runbooks/RUNBOOK_TPE_FAILURE_2026-04-25.md:37:- Côté produit: ne jamais compenser par une règle prix frontend ou un changement de statut hors `OrderStatus enum`.
reports/runbooks/RUNBOOK_TPE_FAILURE_2026-04-25.md:45:   - Preuve à conserver: capture horodatée UTC + branch_id + extrait de journal sans PII.
reports/runbooks/RUNBOOK_TPE_FAILURE_2026-04-25.md:51:   - Preuve à conserver: capture horodatée UTC + branch_id + extrait de journal sans PII.
reports/runbooks/RUNBOOK_TPE_FAILURE_2026-04-25.md:57:   - Preuve à conserver: capture horodatée UTC + branch_id + extrait de journal sans PII.
reports/runbooks/RUNBOOK_TPE_FAILURE_2026-04-25.md:62:   - Décision de bifurcation: 401/403 = auth/ownership; ne pas forcer branch_id.
reports/runbooks/RUNBOOK_TPE_FAILURE_2026-04-25.md:63:   - Preuve à conserver: capture horodatée UTC + branch_id + extrait de journal sans PII.
reports/runbooks/RUNBOOK_TPE_FAILURE_2026-04-25.md:64:   - Invariant à vérifier: branch_id et user ownership restent stricts..
reports/runbooks/RUNBOOK_TPE_FAILURE_2026-04-25.md:69:   - Preuve à conserver: capture horodatée UTC + branch_id + extrait de journal sans PII.
reports/runbooks/RUNBOOK_TPE_FAILURE_2026-04-25.md:75:   - Preuve à conserver: capture horodatée UTC + branch_id + extrait de journal sans PII.
reports/runbooks/RUNBOOK_TPE_FAILURE_2026-04-25.md:76:   - Invariant à vérifier: dispatch après commit non contourné..
reports/runbooks/RUNBOOK_TPE_FAILURE_2026-04-25.md:79:   - Fichier:line à inspecter: `app/Services/FrontendOrderService.php:791`.
reports/runbooks/RUNBOOK_TPE_FAILURE_2026-04-25.md:81:   - Preuve à conserver: capture horodatée UTC + branch_id + extrait de journal sans PII.
reports/runbooks/RUNBOOK_TPE_FAILURE_2026-04-25.md:82:   - Invariant à vérifier: OrderService / FrontendOrderService symmetry..
reports/runbooks/RUNBOOK_TPE_FAILURE_2026-04-25.md:87:   - Preuve à conserver: capture horodatée UTC + branch_id + extrait de journal sans PII.
reports/runbooks/RUNBOOK_TPE_FAILURE_2026-04-25.md:88:   - Invariant à vérifier: OrderStatus enum, pas de statut littéral..
reports/runbooks/RUNBOOK_TPE_FAILURE_2026-04-25.md:93:   - Preuve à conserver: capture horodatée UTC + branch_id + extrait de journal sans PII.
reports/runbooks/RUNBOOK_TPE_FAILURE_2026-04-25.md:99:   - Preuve à conserver: capture horodatée UTC + branch_id + extrait de journal sans PII.
reports/runbooks/RUNBOOK_TPE_FAILURE_2026-04-25.md:130:  - Action: Limiter le traitement et les communications à ce `branch_id`.
reports/runbooks/RUNBOOK_TPE_FAILURE_2026-04-25.md:131:  - Vérification post-action: Vérifier la présence de `branch_id` dans resources/js/components/frontend/kiosk/KioskPaymentComponent.vue:393-414.
reports/runbooks/RUNBOOK_TPE_FAILURE_2026-04-25.md:138:- Sortie P0: ticket incident mis à jour avec heure UTC, `branch_id`, owner, et prochaine décision.
reports/runbooks/RUNBOOK_TPE_FAILURE_2026-04-25.md:149:  - Vérification post-action: Noter l’heure UTC et le `branch_id` dans le ticket.
reports/runbooks/RUNBOOK_TPE_FAILURE_2026-04-25.md:166:- Sortie P1: ticket incident mis à jour avec heure UTC, `branch_id`, owner, et prochaine décision.
reports/runbooks/RUNBOOK_TPE_FAILURE_2026-04-25.md:194:- Sortie P2: ticket incident mis à jour avec heure UTC, `branch_id`, owner, et prochaine décision.
reports/runbooks/RUNBOOK_TPE_FAILURE_2026-04-25.md:197:| Niveau | Trigger | Rôle | Canal | Délai max | Action irréversible? |
reports/runbooks/RUNBOOK_TPE_FAILURE_2026-04-25.md:206:- [ ] `branch_id` concerné documenté; si global, justification explicite par rôle Admin/global.
reports/runbooks/RUNBOOK_TPE_FAILURE_2026-04-25.md:207:- [ ] Aucune écriture brute DB effectuée depuis ce runbook.
reports/runbooks/RUNBOOK_TPE_FAILURE_2026-04-25.md:211:- [ ] `OrderStatus enum` respecté; aucun statut littéral nouveau demandé.
reports/runbooks/RUNBOOK_TPE_FAILURE_2026-04-25.md:212:- [ ] `dispatch after commit` respecté; aucune relance ne contourne l’outbox.
reports/runbooks/RUNBOOK_TPE_FAILURE_2026-04-25.md:218:- [ ] Escalade L3/L4 déclenchée si seuil atteint.
reports/runbooks/RUNBOOK_TPE_FAILURE_2026-04-25.md:230:- [ ] Preuve 6 reliée à `app/Services/FrontendOrderService.php:791` dans le ticket ou dashboard.
reports/runbooks/RUNBOOK_TPE_FAILURE_2026-04-25.md:238:  - Champ obligatoire: branches touchées, avec `branch_id` explicite ou justification globale.
reports/runbooks/RUNBOOK_TPE_FAILURE_2026-04-25.md:244:  - Champ obligatoire: branches touchées, avec `branch_id` explicite ou justification globale.
reports/runbooks/RUNBOOK_TPE_FAILURE_2026-04-25.md:250:  - Champ obligatoire: branches touchées, avec `branch_id` explicite ou justification globale.
reports/runbooks/RUNBOOK_TPE_FAILURE_2026-04-25.md:256:  - Champ obligatoire: branches touchées, avec `branch_id` explicite ou justification globale.
reports/runbooks/RUNBOOK_TPE_FAILURE_2026-04-25.md:262:  - Champ obligatoire: branches touchées, avec `branch_id` explicite ou justification globale.
reports/runbooks/RUNBOOK_TPE_FAILURE_2026-04-25.md:268:  - Champ obligatoire: branches touchées, avec `branch_id` explicite ou justification globale.
reports/runbooks/RUNBOOK_TPE_FAILURE_2026-04-25.md:274:  - Champ obligatoire: branches touchées, avec `branch_id` explicite ou justification globale.
reports/runbooks/RUNBOOK_TPE_FAILURE_2026-04-25.md:280:  - OrderStatus enum authoritative: ne pas demander une correction par littéral numérique hors flux existant.
reports/runbooks/RUNBOOK_TPE_FAILURE_2026-04-25.md:281:  - branch_id isolation: toute preuve, capture, dashboard ou ticket doit porter la branche concernée.
reports/runbooks/RUNBOOK_TPE_FAILURE_2026-04-25.md:282:  - dispatch after commit: ne pas recommander un dispatch manuel qui contourne les garanties after-commit.
reports/runbooks/RUNBOOK_TPE_FAILURE_2026-04-25.md:283:  - OrderService / FrontendOrderService symmetry: toute divergence constatée devient point M-10, pas patch ops.
reports/runbooks/RUNBOOK_TPE_FAILURE_2026-04-25.md:294:- `app/Services/FrontendOrderService.php:791`
reports/runbooks/RUNBOOK_TPE_FAILURE_2026-04-25.md:305:- Invariant: `OrderStatus enum` authoritative.
reports/runbooks/RUNBOOK_TPE_FAILURE_2026-04-25.md:306:- Invariant: `branch_id` isolation strict.
reports/runbooks/RUNBOOK_TPE_FAILURE_2026-04-25.md:307:- Invariant: dispatch after commit.
reports/runbooks/RUNBOOK_TPE_FAILURE_2026-04-25.md:308:- Invariant: OrderService / FrontendOrderService symmetry.
reports/runbooks/RUNBOOK_INDEX_2026-04-25.md:4:- INDEX_STATUS: DRAFT_SKELETON
reports/runbooks/RUNBOOK_INDEX_2026-04-25.md:6:- Last reviewed: 2026-04-25 (initial skeleton)
reports/runbooks/RUNBOOK_INDEX_2026-04-25.md:11:| Symptôme initial | Runbook | First responder | Severity ceiling |
reports/runbooks/RUNBOOK_INDEX_2026-04-25.md:15:| Kiosk affiche `offline_`, polling suspendu, réseau perdu, replay en attente | `RUNBOOK_KIOSK_NETWORK_LOSS_2026-04-25.md` | BE+FE | P0 |
reports/runbooks/RUNBOOK_INDEX_2026-04-25.md:18:| Séquence fiscale, Z/X report, archive NF525 ou audit chain suspecte | `RUNBOOK_FISCAL_SEQUENCE_BREAK_2026-04-25.md` | NF525-QA | P0 |
reports/runbooks/RUNBOOK_INDEX_2026-04-25.md:27:| Kiosk network loss | PLAN-11, PLAN-20, M-20 | GATE_OFFLINE_SCOPE_V1 | `offline_`, polling failures, kiosk-event |
reports/runbooks/RUNBOOK_INDEX_2026-04-25.md:28:| Dispatch queue saturated | PLAN-14, PLAN-20, M-20 | (none) | Horizon status, queue failed, dispatch latency p95 |
reports/runbooks/RUNBOOK_INDEX_2026-04-25.md:35:1. Réception alerte: classer par symptôme initial, `branch_id`, heure UTC, surface touchée.
reports/runbooks/RUNBOOK_INDEX_2026-04-25.md:40:- Règle commune: aucun runbook ne signe un gate, ne modifie code produit, ou ne contourne `branch_id`.
reports/runbooks/RUNBOOK_INDEX_2026-04-25.md:42:- Règle commune: `OrderStatus enum` reste autoritaire; les statuts numériques existants ne deviennent pas nouveau contrat ops.
reports/runbooks/RUNBOOK_INDEX_2026-04-25.md:43:- Règle commune: dispatch after commit; relances outbox uniquement via commandes existantes citées.
reports/runbooks/RUNBOOK_INDEX_2026-04-25.md:47:- Owner maintenance (DRAFT): DevOps + BE + NF525-QA selon runbook.
reports/runbooks/RUNBOOK_INDEX_2026-04-25.md:52:- Déclencheur obligatoire: si un gate passe de `TO_DRAFT` à signé hors runbook, mettre à jour `Linked gates` sans cocher ici.
reports/runbooks/RUNBOOK_INDEX_2026-04-25.md:60:- Maintenance Dispatch queue: revoir `RUNBOOK_DISPATCH_QUEUE_SATURATED_2026-04-25.md` si monitoring, gate, ou file:line associé évolue.
reports/runbooks/RUNBOOK_INDEX_2026-04-25.md:68:- Maintenance guard: Les actions L3/L4 doivent être copiées dans le ticket incident, pas seulement dans chat.
reports/runbooks/RUNBOOK_INDEX_2026-04-25.md:76:- Maintenance guard: Un changement de gate référencé doit mettre à jour uniquement la ligne `Linked gates` du runbook concerné.
reports/runbooks/RUNBOOK_INDEX_2026-04-25.md:79:- Maintenance guard: Les procédures NF525 restent soumises à escalade humaine avant toute action irréversible.

exec
/bin/zsh -lc "rg -n 'php artisan|SQL|select |SELECT |update |UPDATE |delete |DELETE |insert |INSERT |bash |npm |yarn |pnpm |git ' reports/runbooks/RUNBOOK_*_2026-04-25.md" in /Users/1millnonstop/Downloads/projet/foodking-web/web/testttt
 succeeded in 0ms:
reports/runbooks/RUNBOOK_TPE_FAILURE_2026-04-25.md:90:   - Commande / observation: `php artisan app:preflight-production`
reports/runbooks/RUNBOOK_ROLLBACK_CANARY_2026-04-25.md:84:   - Commande / observation: `php artisan app:preflight-production`
reports/runbooks/RUNBOOK_ROLLBACK_CANARY_2026-04-25.md:90:   - Commande / observation: `php artisan app:preflight-production`
reports/runbooks/RUNBOOK_OUTBOX_BLOCKED_2026-04-25.md:42:   - Commande / observation: Observation dashboard ou comptage read-only L2; aucune commande SQL dans ce runbook.
reports/runbooks/RUNBOOK_OUTBOX_BLOCKED_2026-04-25.md:48:   - Commande / observation: `php artisan foodking:outbox:rescue`
reports/runbooks/RUNBOOK_OUTBOX_BLOCKED_2026-04-25.md:54:   - Commande / observation: `php artisan foodking:outbox:retry-failed --since=1h`
reports/runbooks/RUNBOOK_OUTBOX_BLOCKED_2026-04-25.md:287:  - Le comptage stale se fait en lecture seule par L2/DBA; ce runbook ne fournit pas de SQL exécutable.
reports/runbooks/RUNBOOK_FISCAL_SEQUENCE_BREAK_2026-04-25.md:54:   - Commande / observation: `php artisan app:preflight-production`
reports/runbooks/RUNBOOK_FISCAL_SEQUENCE_BREAK_2026-04-25.md:78:   - Commande / observation: `php artisan foodking:fiscal:archive <branch_id> --from=<YYYY-MM-DD> --to=<YYYY-MM-DD>`
reports/runbooks/RUNBOOK_FISCAL_SEQUENCE_BREAK_2026-04-25.md:84:   - Commande / observation: `php artisan app:preflight-production`
reports/runbooks/RUNBOOK_DISPATCH_QUEUE_SATURATED_2026-04-25.md:42:   - Commande / observation: `php artisan horizon:status`
reports/runbooks/RUNBOOK_DISPATCH_QUEUE_SATURATED_2026-04-25.md:48:   - Commande / observation: `php artisan queue:failed`
reports/runbooks/RUNBOOK_DISPATCH_QUEUE_SATURATED_2026-04-25.md:72:   - Commande / observation: `php artisan app:preflight-production`
reports/runbooks/RUNBOOK_DISPATCH_QUEUE_SATURATED_2026-04-25.md:78:   - Commande / observation: `php artisan queue:failed`
reports/runbooks/RUNBOOK_DISPATCH_QUEUE_SATURATED_2026-04-25.md:84:   - Commande / observation: `php artisan queue:failed`
reports/runbooks/RUNBOOK_KDS_MULTISCREEN_DESYNC_2026-04-25.md:78:   - Commande / observation: Observation DBA/L2 read-only; aucune commande SQL dans ce runbook.
reports/runbooks/RUNBOOK_KDS_MULTISCREEN_DESYNC_2026-04-25.md:96:   - Commande / observation: Observation L2 read-only; aucune commande SQL dans ce runbook.

exec
/bin/zsh -lc "rg -n \"protected \\"'$signature|protected static '"\\"'$defaultName|AsCommand|->command'"\\(\" app/Console/Commands/PreflightProductionCommand.php app/Console/Commands/OutboxRescueCommand.php app/Console/Commands/OutboxRetryFailedCommand.php app/Console/Commands/FiscalArchiveCommand.php" in /Users/1millnonstop/Downloads/projet/foodking-web/web/testttt
 exited 1 in 0ms:
exec
/bin/zsh -lc "rg -n 'Status: DRAFT_SKELETON_NOT_SIGNED|## 7\\. Template post-mortem|\\| Niveau \\| Trigger \\| Rôle \\| Canal \\| Délai max \\| Action irréversible\\? \\||L4|NF525|ne propose pas|ne fournit pas|aucune commande SQL|aucune écriture brute|aucune réécriture|contournement|frontend|prix|payment_status|status: [0-9]|OrderStatus::|OrderStatus enum|status littéral|chaine|chaîne' reports/runbooks/RUNBOOK_*_2026-04-25.md" in /Users/1millnonstop/Downloads/projet/foodking-web/web/testttt
 succeeded in 0ms:
reports/runbooks/RUNBOOK_FISCAL_SEQUENCE_BREAK_2026-04-25.md:1:# RUNBOOK — Rupture séquence fiscale NF525 (2026-04-25)
reports/runbooks/RUNBOOK_FISCAL_SEQUENCE_BREAK_2026-04-25.md:3:- Status: DRAFT_SKELETON_NOT_SIGNED
reports/runbooks/RUNBOOK_FISCAL_SEQUENCE_BREAK_2026-04-25.md:4:- Owner (DRAFT): NF525-QA
reports/runbooks/RUNBOOK_FISCAL_SEQUENCE_BREAK_2026-04-25.md:12:- ZReportService refuse open/close ou détecte chaîne invalide.
reports/runbooks/RUNBOOK_FISCAL_SEQUENCE_BREAK_2026-04-25.md:31:- Ops ne peut plus garantir séquence gap-free ou archive NF525.
reports/runbooks/RUNBOOK_FISCAL_SEQUENCE_BREAK_2026-04-25.md:37:- Côté produit: ne jamais compenser par une règle prix frontend ou un changement de statut hors `OrderStatus enum`.
reports/runbooks/RUNBOOK_FISCAL_SEQUENCE_BREAK_2026-04-25.md:44:   - Décision de bifurcation: lock ou sequence break = P0 + L4; ne pas patcher.
reports/runbooks/RUNBOOK_FISCAL_SEQUENCE_BREAK_2026-04-25.md:62:   - Décision de bifurcation: open conflict = escalade NF525; pas de second Z manuel.
reports/runbooks/RUNBOOK_FISCAL_SEQUENCE_BREAK_2026-04-25.md:68:   - Décision de bifurcation: close fail = freeze + L4; ne pas recomposer signature.
reports/runbooks/RUNBOOK_FISCAL_SEQUENCE_BREAK_2026-04-25.md:74:   - Décision de bifurcation: X incohérent confirme besoin L4; X ne corrige rien.
reports/runbooks/RUNBOOK_FISCAL_SEQUENCE_BREAK_2026-04-25.md:80:   - Décision de bifurcation: archive abort = preuve incident; ne pas utiliser --no-verify sans L4.
reports/runbooks/RUNBOOK_FISCAL_SEQUENCE_BREAK_2026-04-25.md:82:   - Invariant à vérifier: NF525 evidence..
reports/runbooks/RUNBOOK_FISCAL_SEQUENCE_BREAK_2026-04-25.md:98:   - Décision de bifurcation: cashback sans audit = L4; ne pas corriger transaction brute.
reports/runbooks/RUNBOOK_FISCAL_SEQUENCE_BREAK_2026-04-25.md:110:  - Action: Freeze caisse de la branche + escalade L4 NF525 immédiate; aucune tentative de patch séquence.
reports/runbooks/RUNBOOK_FISCAL_SEQUENCE_BREAK_2026-04-25.md:114:  - Précondition: Rupture séquence fiscale NF525 bloque encaissement ou préparation sur une branche.
reports/runbooks/RUNBOOK_FISCAL_SEQUENCE_BREAK_2026-04-25.md:138:- Sortie P0: ticket incident mis à jour avec heure UTC, `branch_id`, owner, et prochaine décision.
reports/runbooks/RUNBOOK_FISCAL_SEQUENCE_BREAK_2026-04-25.md:142:  - Précondition: Rupture séquence fiscale NF525 dégrade le service sans arrêt total.
reports/runbooks/RUNBOOK_FISCAL_SEQUENCE_BREAK_2026-04-25.md:162:  - Précondition: Le contournement dure plus de 30 minutes.
reports/runbooks/RUNBOOK_FISCAL_SEQUENCE_BREAK_2026-04-25.md:166:- Sortie P1: ticket incident mis à jour avec heure UTC, `branch_id`, owner, et prochaine décision.
reports/runbooks/RUNBOOK_FISCAL_SEQUENCE_BREAK_2026-04-25.md:194:- Sortie P2: ticket incident mis à jour avec heure UTC, `branch_id`, owner, et prochaine décision.
reports/runbooks/RUNBOOK_FISCAL_SEQUENCE_BREAK_2026-04-25.md:197:| Niveau | Trigger | Rôle | Canal | Délai max | Action irréversible? |
reports/runbooks/RUNBOOK_FISCAL_SEQUENCE_BREAK_2026-04-25.md:202:| L4 | Séquence, Z, archive ou audit chain compromise | NF525 / Conseil / humain fiscal | Appel direct + dossier incident | Immédiat | Oui, toute décision fiscale |
reports/runbooks/RUNBOOK_FISCAL_SEQUENCE_BREAK_2026-04-25.md:207:- [ ] Dossier NF525 evidence exporté ou explicitement conservé si archive abort.
reports/runbooks/RUNBOOK_FISCAL_SEQUENCE_BREAK_2026-04-25.md:213:- [ ] Aucune logique prix frontend introduite ou recommandée.
reports/runbooks/RUNBOOK_FISCAL_SEQUENCE_BREAK_2026-04-25.md:214:- [ ] `OrderStatus enum` respecté; aucun statut littéral nouveau demandé.
reports/runbooks/RUNBOOK_FISCAL_SEQUENCE_BREAK_2026-04-25.md:221:- [ ] Escalade L3/L4 déclenchée si seuil atteint.
reports/runbooks/RUNBOOK_FISCAL_SEQUENCE_BREAK_2026-04-25.md:222:- [ ] Monitoring revenu au niveau nominal ou contournement documenté.
reports/runbooks/RUNBOOK_FISCAL_SEQUENCE_BREAK_2026-04-25.md:239:## 7. Template post-mortem
reports/runbooks/RUNBOOK_FISCAL_SEQUENCE_BREAK_2026-04-25.md:283:  - Backend pricing is SSOT: ne pas recalculer un montant côté frontend pendant la réponse incident.
reports/runbooks/RUNBOOK_FISCAL_SEQUENCE_BREAK_2026-04-25.md:284:  - OrderStatus enum authoritative: ne pas demander une correction par littéral numérique hors flux existant.
reports/runbooks/RUNBOOK_FISCAL_SEQUENCE_BREAK_2026-04-25.md:313:- Invariant: `OrderStatus enum` authoritative.
reports/runbooks/RUNBOOK_KIOSK_NETWORK_LOSS_2026-04-25.md:3:- Status: DRAFT_SKELETON_NOT_SIGNED
reports/runbooks/RUNBOOK_KIOSK_NETWORK_LOSS_2026-04-25.md:16:- CB/TR offline tenté alors que gate V1 refuse tout contournement serveur.
reports/runbooks/RUNBOOK_KIOSK_NETWORK_LOSS_2026-04-25.md:19:- Trigger evidence 3: signal à corréler avec `resources/js/components/frontend/kiosk/KioskPaymentComponent.vue:292`.
reports/runbooks/RUNBOOK_KIOSK_NETWORK_LOSS_2026-04-25.md:20:- Trigger evidence 4: signal à corréler avec `resources/js/components/frontend/kiosk/KioskPaymentComponent.vue:297-305`.
reports/runbooks/RUNBOOK_KIOSK_NETWORK_LOSS_2026-04-25.md:21:- Trigger evidence 5: signal à corréler avec `resources/js/components/frontend/kiosk/KioskWaitingComponent.vue:195-198`.
reports/runbooks/RUNBOOK_KIOSK_NETWORK_LOSS_2026-04-25.md:22:- Trigger evidence 6: signal à corréler avec `resources/js/components/frontend/kiosk/KioskWaitingComponent.vue:258-305`.
reports/runbooks/RUNBOOK_KIOSK_NETWORK_LOSS_2026-04-25.md:23:- Trigger evidence 7: signal à corréler avec `resources/js/components/frontend/kiosk/KioskWaitingComponent.vue:388-392`.
reports/runbooks/RUNBOOK_KIOSK_NETWORK_LOSS_2026-04-25.md:37:- Côté produit: ne jamais compenser par une règle prix frontend ou un changement de statut hors `OrderStatus enum`.
reports/runbooks/RUNBOOK_KIOSK_NETWORK_LOSS_2026-04-25.md:55:   - Fichier:line à inspecter: `resources/js/components/frontend/kiosk/KioskPaymentComponent.vue:292`.
reports/runbooks/RUNBOOK_KIOSK_NETWORK_LOSS_2026-04-25.md:61:   - Fichier:line à inspecter: `resources/js/components/frontend/kiosk/KioskPaymentComponent.vue:297-305`.
reports/runbooks/RUNBOOK_KIOSK_NETWORK_LOSS_2026-04-25.md:67:   - Fichier:line à inspecter: `resources/js/components/frontend/kiosk/KioskWaitingComponent.vue:195-198`.
reports/runbooks/RUNBOOK_KIOSK_NETWORK_LOSS_2026-04-25.md:73:   - Fichier:line à inspecter: `resources/js/components/frontend/kiosk/KioskWaitingComponent.vue:258-305`.
reports/runbooks/RUNBOOK_KIOSK_NETWORK_LOSS_2026-04-25.md:79:   - Fichier:line à inspecter: `resources/js/components/frontend/kiosk/KioskWaitingComponent.vue:388-392`.
reports/runbooks/RUNBOOK_KIOSK_NETWORK_LOSS_2026-04-25.md:82:   - Invariant à vérifier: OrderStatus enum; pas de littéral nouveau..
reports/runbooks/RUNBOOK_KIOSK_NETWORK_LOSS_2026-04-25.md:110:  - Action: Refuser le contournement; basculer vers procédure autorisée par `GATE_OFFLINE_SCOPE_V1` sans paiement carte offline.
reports/runbooks/RUNBOOK_KIOSK_NETWORK_LOSS_2026-04-25.md:138:- Sortie P0: ticket incident mis à jour avec heure UTC, `branch_id`, owner, et prochaine décision.
reports/runbooks/RUNBOOK_KIOSK_NETWORK_LOSS_2026-04-25.md:162:  - Précondition: Le contournement dure plus de 30 minutes.
reports/runbooks/RUNBOOK_KIOSK_NETWORK_LOSS_2026-04-25.md:166:- Sortie P1: ticket incident mis à jour avec heure UTC, `branch_id`, owner, et prochaine décision.
reports/runbooks/RUNBOOK_KIOSK_NETWORK_LOSS_2026-04-25.md:194:- Sortie P2: ticket incident mis à jour avec heure UTC, `branch_id`, owner, et prochaine décision.
reports/runbooks/RUNBOOK_KIOSK_NETWORK_LOSS_2026-04-25.md:197:| Niveau | Trigger | Rôle | Canal | Délai max | Action irréversible? |
reports/runbooks/RUNBOOK_KIOSK_NETWORK_LOSS_2026-04-25.md:210:- [ ] Aucune logique prix frontend introduite ou recommandée.
reports/runbooks/RUNBOOK_KIOSK_NETWORK_LOSS_2026-04-25.md:211:- [ ] `OrderStatus enum` respecté; aucun statut littéral nouveau demandé.
reports/runbooks/RUNBOOK_KIOSK_NETWORK_LOSS_2026-04-25.md:218:- [ ] Escalade L3/L4 déclenchée si seuil atteint.
reports/runbooks/RUNBOOK_KIOSK_NETWORK_LOSS_2026-04-25.md:219:- [ ] Monitoring revenu au niveau nominal ou contournement documenté.
reports/runbooks/RUNBOOK_KIOSK_NETWORK_LOSS_2026-04-25.md:227:- [ ] Preuve 3 reliée à `resources/js/components/frontend/kiosk/KioskPaymentComponent.vue:292` dans le ticket ou dashboard.
reports/runbooks/RUNBOOK_KIOSK_NETWORK_LOSS_2026-04-25.md:228:- [ ] Preuve 4 reliée à `resources/js/components/frontend/kiosk/KioskPaymentComponent.vue:297-305` dans le ticket ou dashboard.
reports/runbooks/RUNBOOK_KIOSK_NETWORK_LOSS_2026-04-25.md:229:- [ ] Preuve 5 reliée à `resources/js/components/frontend/kiosk/KioskWaitingComponent.vue:195-198` dans le ticket ou dashboard.
reports/runbooks/RUNBOOK_KIOSK_NETWORK_LOSS_2026-04-25.md:230:- [ ] Preuve 6 reliée à `resources/js/components/frontend/kiosk/KioskWaitingComponent.vue:258-305` dans le ticket ou dashboard.
reports/runbooks/RUNBOOK_KIOSK_NETWORK_LOSS_2026-04-25.md:231:- [ ] Preuve 7 reliée à `resources/js/components/frontend/kiosk/KioskWaitingComponent.vue:388-392` dans le ticket ou dashboard.
reports/runbooks/RUNBOOK_KIOSK_NETWORK_LOSS_2026-04-25.md:236:## 7. Template post-mortem
reports/runbooks/RUNBOOK_KIOSK_NETWORK_LOSS_2026-04-25.md:280:  - Backend pricing is SSOT: ne pas recalculer un montant côté frontend pendant la réponse incident.
reports/runbooks/RUNBOOK_KIOSK_NETWORK_LOSS_2026-04-25.md:281:  - OrderStatus enum authoritative: ne pas demander une correction par littéral numérique hors flux existant.
reports/runbooks/RUNBOOK_KIOSK_NETWORK_LOSS_2026-04-25.md:292:- `resources/js/components/frontend/kiosk/KioskPaymentComponent.vue:292`
reports/runbooks/RUNBOOK_KIOSK_NETWORK_LOSS_2026-04-25.md:293:- `resources/js/components/frontend/kiosk/KioskPaymentComponent.vue:297-305`
reports/runbooks/RUNBOOK_KIOSK_NETWORK_LOSS_2026-04-25.md:294:- `resources/js/components/frontend/kiosk/KioskWaitingComponent.vue:195-198`
reports/runbooks/RUNBOOK_KIOSK_NETWORK_LOSS_2026-04-25.md:295:- `resources/js/components/frontend/kiosk/KioskWaitingComponent.vue:258-305`
reports/runbooks/RUNBOOK_KIOSK_NETWORK_LOSS_2026-04-25.md:296:- `resources/js/components/frontend/kiosk/KioskWaitingComponent.vue:388-392`
reports/runbooks/RUNBOOK_KIOSK_NETWORK_LOSS_2026-04-25.md:307:- Invariant: `OrderStatus enum` authoritative.
reports/runbooks/RUNBOOK_INDEX_2026-04-25.md:18:| Séquence fiscale, Z/X report, archive NF525 ou audit chain suspecte | `RUNBOOK_FISCAL_SEQUENCE_BREAK_2026-04-25.md` | NF525-QA | P0 |
reports/runbooks/RUNBOOK_INDEX_2026-04-25.md:41:- Règle commune: backend pricing SSOT; les montants frontend ne sont jamais preuve de règlement serveur.
reports/runbooks/RUNBOOK_INDEX_2026-04-25.md:42:- Règle commune: `OrderStatus enum` reste autoritaire; les statuts numériques existants ne deviennent pas nouveau contrat ops.
reports/runbooks/RUNBOOK_INDEX_2026-04-25.md:47:- Owner maintenance (DRAFT): DevOps + BE + NF525-QA selon runbook.
reports/runbooks/RUNBOOK_INDEX_2026-04-25.md:68:- Maintenance guard: Les actions L3/L4 doivent être copiées dans le ticket incident, pas seulement dans chat.
reports/runbooks/RUNBOOK_INDEX_2026-04-25.md:79:- Maintenance guard: Les procédures NF525 restent soumises à escalade humaine avant toute action irréversible.
reports/runbooks/RUNBOOK_PRINTER_FAILURE_2026-04-25.md:3:- Status: DRAFT_SKELETON_NOT_SIGNED
reports/runbooks/RUNBOOK_PRINTER_FAILURE_2026-04-25.md:37:- Côté produit: ne jamais compenser par une règle prix frontend ou un changement de statut hors `OrderStatus enum`.
reports/runbooks/RUNBOOK_PRINTER_FAILURE_2026-04-25.md:58:   - Invariant à vérifier: aucun contournement branch_id..
reports/runbooks/RUNBOOK_PRINTER_FAILURE_2026-04-25.md:133:- Sortie P0: ticket incident mis à jour avec heure UTC, `branch_id`, owner, et prochaine décision.
reports/runbooks/RUNBOOK_PRINTER_FAILURE_2026-04-25.md:140:  - Impact attendu: Station rétablie ou contournement visible par ops.
reports/runbooks/RUNBOOK_PRINTER_FAILURE_2026-04-25.md:162:  - Précondition: Le contournement dure plus de 30 minutes.
reports/runbooks/RUNBOOK_PRINTER_FAILURE_2026-04-25.md:166:- Sortie P1: ticket incident mis à jour avec heure UTC, `branch_id`, owner, et prochaine décision.
reports/runbooks/RUNBOOK_PRINTER_FAILURE_2026-04-25.md:194:- Sortie P2: ticket incident mis à jour avec heure UTC, `branch_id`, owner, et prochaine décision.
reports/runbooks/RUNBOOK_PRINTER_FAILURE_2026-04-25.md:197:| Niveau | Trigger | Rôle | Canal | Délai max | Action irréversible? |
reports/runbooks/RUNBOOK_PRINTER_FAILURE_2026-04-25.md:210:- [ ] Aucune logique prix frontend introduite ou recommandée.
reports/runbooks/RUNBOOK_PRINTER_FAILURE_2026-04-25.md:211:- [ ] `OrderStatus enum` respecté; aucun statut littéral nouveau demandé.
reports/runbooks/RUNBOOK_PRINTER_FAILURE_2026-04-25.md:218:- [ ] Escalade L3/L4 déclenchée si seuil atteint.
reports/runbooks/RUNBOOK_PRINTER_FAILURE_2026-04-25.md:219:- [ ] Monitoring revenu au niveau nominal ou contournement documenté.
reports/runbooks/RUNBOOK_PRINTER_FAILURE_2026-04-25.md:236:## 7. Template post-mortem
reports/runbooks/RUNBOOK_PRINTER_FAILURE_2026-04-25.md:280:  - Backend pricing is SSOT: ne pas recalculer un montant côté frontend pendant la réponse incident.
reports/runbooks/RUNBOOK_PRINTER_FAILURE_2026-04-25.md:281:  - OrderStatus enum authoritative: ne pas demander une correction par littéral numérique hors flux existant.
reports/runbooks/RUNBOOK_PRINTER_FAILURE_2026-04-25.md:309:- Invariant: `OrderStatus enum` authoritative.
reports/runbooks/RUNBOOK_KDS_MULTISCREEN_DESYNC_2026-04-25.md:3:- Status: DRAFT_SKELETON_NOT_SIGNED
reports/runbooks/RUNBOOK_KDS_MULTISCREEN_DESYNC_2026-04-25.md:14:- KDS frontend atteint cap 50 ou affiche vues divergentes.
reports/runbooks/RUNBOOK_KDS_MULTISCREEN_DESYNC_2026-04-25.md:37:- Côté produit: ne jamais compenser par une règle prix frontend ou un changement de statut hors `OrderStatus enum`.
reports/runbooks/RUNBOOK_KDS_MULTISCREEN_DESYNC_2026-04-25.md:46:   - Invariant à vérifier: OrderStatus enum..
reports/runbooks/RUNBOOK_KDS_MULTISCREEN_DESYNC_2026-04-25.md:64:   - Invariant à vérifier: OrderStatus enum et permissions..
reports/runbooks/RUNBOOK_KDS_MULTISCREEN_DESYNC_2026-04-25.md:78:   - Commande / observation: Observation DBA/L2 read-only; aucune commande SQL dans ce runbook.
reports/runbooks/RUNBOOK_KDS_MULTISCREEN_DESYNC_2026-04-25.md:80:   - Décision de bifurcation: double transition = post-mortem; aucune réécriture.
reports/runbooks/RUNBOOK_KDS_MULTISCREEN_DESYNC_2026-04-25.md:94:   - Invariant à vérifier: OrderStatus enum..
reports/runbooks/RUNBOOK_KDS_MULTISCREEN_DESYNC_2026-04-25.md:96:   - Commande / observation: Observation L2 read-only; aucune commande SQL dans ce runbook.
reports/runbooks/RUNBOOK_KDS_MULTISCREEN_DESYNC_2026-04-25.md:133:- Sortie P0: ticket incident mis à jour avec heure UTC, `branch_id`, owner, et prochaine décision.
reports/runbooks/RUNBOOK_KDS_MULTISCREEN_DESYNC_2026-04-25.md:162:  - Précondition: Le contournement dure plus de 30 minutes.
reports/runbooks/RUNBOOK_KDS_MULTISCREEN_DESYNC_2026-04-25.md:166:- Sortie P1: ticket incident mis à jour avec heure UTC, `branch_id`, owner, et prochaine décision.
reports/runbooks/RUNBOOK_KDS_MULTISCREEN_DESYNC_2026-04-25.md:194:- Sortie P2: ticket incident mis à jour avec heure UTC, `branch_id`, owner, et prochaine décision.
reports/runbooks/RUNBOOK_KDS_MULTISCREEN_DESYNC_2026-04-25.md:197:| Niveau | Trigger | Rôle | Canal | Délai max | Action irréversible? |
reports/runbooks/RUNBOOK_KDS_MULTISCREEN_DESYNC_2026-04-25.md:210:- [ ] Aucune logique prix frontend introduite ou recommandée.
reports/runbooks/RUNBOOK_KDS_MULTISCREEN_DESYNC_2026-04-25.md:211:- [ ] `OrderStatus enum` respecté; aucun statut littéral nouveau demandé.
reports/runbooks/RUNBOOK_KDS_MULTISCREEN_DESYNC_2026-04-25.md:218:- [ ] Escalade L3/L4 déclenchée si seuil atteint.
reports/runbooks/RUNBOOK_KDS_MULTISCREEN_DESYNC_2026-04-25.md:219:- [ ] Monitoring revenu au niveau nominal ou contournement documenté.
reports/runbooks/RUNBOOK_KDS_MULTISCREEN_DESYNC_2026-04-25.md:236:## 7. Template post-mortem
reports/runbooks/RUNBOOK_KDS_MULTISCREEN_DESYNC_2026-04-25.md:280:  - Backend pricing is SSOT: ne pas recalculer un montant côté frontend pendant la réponse incident.
reports/runbooks/RUNBOOK_KDS_MULTISCREEN_DESYNC_2026-04-25.md:281:  - OrderStatus enum authoritative: ne pas demander une correction par littéral numérique hors flux existant.
reports/runbooks/RUNBOOK_KDS_MULTISCREEN_DESYNC_2026-04-25.md:306:- Invariant: `OrderStatus enum` authoritative.
reports/runbooks/RUNBOOK_DISPATCH_QUEUE_SATURATED_2026-04-25.md:3:- Status: DRAFT_SKELETON_NOT_SIGNED
reports/runbooks/RUNBOOK_DISPATCH_QUEUE_SATURATED_2026-04-25.md:37:- Côté produit: ne jamais compenser par une règle prix frontend ou un changement de statut hors `OrderStatus enum`.
reports/runbooks/RUNBOOK_DISPATCH_QUEUE_SATURATED_2026-04-25.md:88:   - Invariant à vérifier: OrderStatus enum..
reports/runbooks/RUNBOOK_DISPATCH_QUEUE_SATURATED_2026-04-25.md:133:- Sortie P0: ticket incident mis à jour avec heure UTC, `branch_id`, owner, et prochaine décision.
reports/runbooks/RUNBOOK_DISPATCH_QUEUE_SATURATED_2026-04-25.md:162:  - Précondition: Le contournement dure plus de 30 minutes.
reports/runbooks/RUNBOOK_DISPATCH_QUEUE_SATURATED_2026-04-25.md:166:- Sortie P1: ticket incident mis à jour avec heure UTC, `branch_id`, owner, et prochaine décision.
reports/runbooks/RUNBOOK_DISPATCH_QUEUE_SATURATED_2026-04-25.md:194:- Sortie P2: ticket incident mis à jour avec heure UTC, `branch_id`, owner, et prochaine décision.
reports/runbooks/RUNBOOK_DISPATCH_QUEUE_SATURATED_2026-04-25.md:197:| Niveau | Trigger | Rôle | Canal | Délai max | Action irréversible? |
reports/runbooks/RUNBOOK_DISPATCH_QUEUE_SATURATED_2026-04-25.md:210:- [ ] Aucune logique prix frontend introduite ou recommandée.
reports/runbooks/RUNBOOK_DISPATCH_QUEUE_SATURATED_2026-04-25.md:211:- [ ] `OrderStatus enum` respecté; aucun statut littéral nouveau demandé.
reports/runbooks/RUNBOOK_DISPATCH_QUEUE_SATURATED_2026-04-25.md:218:- [ ] Escalade L3/L4 déclenchée si seuil atteint.
reports/runbooks/RUNBOOK_DISPATCH_QUEUE_SATURATED_2026-04-25.md:219:- [ ] Monitoring revenu au niveau nominal ou contournement documenté.
reports/runbooks/RUNBOOK_DISPATCH_QUEUE_SATURATED_2026-04-25.md:236:## 7. Template post-mortem
reports/runbooks/RUNBOOK_DISPATCH_QUEUE_SATURATED_2026-04-25.md:280:  - Backend pricing is SSOT: ne pas recalculer un montant côté frontend pendant la réponse incident.
reports/runbooks/RUNBOOK_DISPATCH_QUEUE_SATURATED_2026-04-25.md:281:  - OrderStatus enum authoritative: ne pas demander une correction par littéral numérique hors flux existant.
reports/runbooks/RUNBOOK_DISPATCH_QUEUE_SATURATED_2026-04-25.md:307:- Invariant: `OrderStatus enum` authoritative.
reports/runbooks/RUNBOOK_ROLLBACK_CANARY_2026-04-25.md:3:- Status: DRAFT_SKELETON_NOT_SIGNED
reports/runbooks/RUNBOOK_ROLLBACK_CANARY_2026-04-25.md:37:- Côté produit: ne jamais compenser par une règle prix frontend ou un changement de statut hors `OrderStatus enum`.
reports/runbooks/RUNBOOK_ROLLBACK_CANARY_2026-04-25.md:56:   - Décision de bifurcation: `kds_strict_release` cible KDS backend/frontend; recharger écrans après rollback.
reports/runbooks/RUNBOOK_ROLLBACK_CANARY_2026-04-25.md:58:   - Invariant à vérifier: OrderStatus enum..
reports/runbooks/RUNBOOK_ROLLBACK_CANARY_2026-04-25.md:62:   - Décision de bifurcation: `quote_v1` cible backend quote; aucun recalcul frontend.
reports/runbooks/RUNBOOK_ROLLBACK_CANARY_2026-04-25.md:70:   - Invariant à vérifier: NF525 evidence..
reports/runbooks/RUNBOOK_ROLLBACK_CANARY_2026-04-25.md:103:- Mapping cible `kds_strict_release`: backend KDS + bundle frontend KDS; rollback frontend = retour build legacy/cutover M-12 si humain l’ordonne.
reports/runbooks/RUNBOOK_ROLLBACK_CANARY_2026-04-25.md:104:- Mapping cible `quote_v1`: backend quote SSOT + affichage frontend consommateur; aucun recalcul prix frontend pendant rollback.
reports/runbooks/RUNBOOK_ROLLBACK_CANARY_2026-04-25.md:105:- Mapping cible `fiscal_z_v1`: backend fiscal Z; rollback DB/migration réservé M-13; décision NF525 humaine obligatoire.
reports/runbooks/RUNBOOK_ROLLBACK_CANARY_2026-04-25.md:106:- Mapping cible `kiosk_offline_strict`: bundle frontend kiosk + garde backend offline; CB/TR offline reste interdit sans gate.
reports/runbooks/RUNBOOK_ROLLBACK_CANARY_2026-04-25.md:146:- Sortie P0: ticket incident mis à jour avec heure UTC, `branch_id`, owner, et prochaine décision.
reports/runbooks/RUNBOOK_ROLLBACK_CANARY_2026-04-25.md:170:  - Précondition: Le contournement dure plus de 30 minutes.
reports/runbooks/RUNBOOK_ROLLBACK_CANARY_2026-04-25.md:174:- Sortie P1: ticket incident mis à jour avec heure UTC, `branch_id`, owner, et prochaine décision.
reports/runbooks/RUNBOOK_ROLLBACK_CANARY_2026-04-25.md:202:- Sortie P2: ticket incident mis à jour avec heure UTC, `branch_id`, owner, et prochaine décision.
reports/runbooks/RUNBOOK_ROLLBACK_CANARY_2026-04-25.md:205:| Niveau | Trigger | Rôle | Canal | Délai max | Action irréversible? |
reports/runbooks/RUNBOOK_ROLLBACK_CANARY_2026-04-25.md:218:- [ ] Aucune logique prix frontend introduite ou recommandée.
reports/runbooks/RUNBOOK_ROLLBACK_CANARY_2026-04-25.md:219:- [ ] `OrderStatus enum` respecté; aucun statut littéral nouveau demandé.
reports/runbooks/RUNBOOK_ROLLBACK_CANARY_2026-04-25.md:226:- [ ] Escalade L3/L4 déclenchée si seuil atteint.
reports/runbooks/RUNBOOK_ROLLBACK_CANARY_2026-04-25.md:227:- [ ] Monitoring revenu au niveau nominal ou contournement documenté.
reports/runbooks/RUNBOOK_ROLLBACK_CANARY_2026-04-25.md:244:## 7. Template post-mortem
reports/runbooks/RUNBOOK_ROLLBACK_CANARY_2026-04-25.md:288:  - Backend pricing is SSOT: ne pas recalculer un montant côté frontend pendant la réponse incident.
reports/runbooks/RUNBOOK_ROLLBACK_CANARY_2026-04-25.md:289:  - OrderStatus enum authoritative: ne pas demander une correction par littéral numérique hors flux existant.
reports/runbooks/RUNBOOK_ROLLBACK_CANARY_2026-04-25.md:319:- Invariant: `OrderStatus enum` authoritative.
reports/runbooks/RUNBOOK_TPE_FAILURE_2026-04-25.md:3:- Status: DRAFT_SKELETON_NOT_SIGNED
reports/runbooks/RUNBOOK_TPE_FAILURE_2026-04-25.md:17:- Trigger evidence 1: signal à corréler avec `resources/js/components/frontend/kiosk/KioskPaymentComponent.vue:393-414`.
reports/runbooks/RUNBOOK_TPE_FAILURE_2026-04-25.md:18:- Trigger evidence 2: signal à corréler avec `resources/js/components/frontend/kiosk/KioskPaymentComponent.vue:473-501`.
reports/runbooks/RUNBOOK_TPE_FAILURE_2026-04-25.md:19:- Trigger evidence 3: signal à corréler avec `resources/js/components/frontend/kiosk/KioskPaymentComponent.vue:562-570`.
reports/runbooks/RUNBOOK_TPE_FAILURE_2026-04-25.md:37:- Côté produit: ne jamais compenser par une règle prix frontend ou un changement de statut hors `OrderStatus enum`.
reports/runbooks/RUNBOOK_TPE_FAILURE_2026-04-25.md:43:   - Fichier:line à inspecter: `resources/js/components/frontend/kiosk/KioskPaymentComponent.vue:393-414`.
reports/runbooks/RUNBOOK_TPE_FAILURE_2026-04-25.md:49:   - Fichier:line à inspecter: `resources/js/components/frontend/kiosk/KioskPaymentComponent.vue:473-501`.
reports/runbooks/RUNBOOK_TPE_FAILURE_2026-04-25.md:55:   - Fichier:line à inspecter: `resources/js/components/frontend/kiosk/KioskPaymentComponent.vue:562-570`.
reports/runbooks/RUNBOOK_TPE_FAILURE_2026-04-25.md:88:   - Invariant à vérifier: OrderStatus enum, pas de statut littéral..
reports/runbooks/RUNBOOK_TPE_FAILURE_2026-04-25.md:116:  - Vérification post-action: Comparer incident et ancrage resources/js/components/frontend/kiosk/KioskPaymentComponent.vue:393-414.
reports/runbooks/RUNBOOK_TPE_FAILURE_2026-04-25.md:121:  - Vérification post-action: Conserver captures dashboard et journal lié à resources/js/components/frontend/kiosk/KioskPaymentComponent.vue:473-501.
reports/runbooks/RUNBOOK_TPE_FAILURE_2026-04-25.md:131:  - Vérification post-action: Vérifier la présence de `branch_id` dans resources/js/components/frontend/kiosk/KioskPaymentComponent.vue:393-414.
reports/runbooks/RUNBOOK_TPE_FAILURE_2026-04-25.md:136:  - Vérification post-action: Joindre les références resources/js/components/frontend/kiosk/KioskPaymentComponent.vue:393-414 et resources/js/components/frontend/kiosk/KioskPaymentComponent.vue:473-501.
reports/runbooks/RUNBOOK_TPE_FAILURE_2026-04-25.md:138:- Sortie P0: ticket incident mis à jour avec heure UTC, `branch_id`, owner, et prochaine décision.
reports/runbooks/RUNBOOK_TPE_FAILURE_2026-04-25.md:144:  - Vérification post-action: Comparer les symptômes au fichier resources/js/components/frontend/kiosk/KioskPaymentComponent.vue:393-414.
reports/runbooks/RUNBOOK_TPE_FAILURE_2026-04-25.md:162:  - Précondition: Le contournement dure plus de 30 minutes.
reports/runbooks/RUNBOOK_TPE_FAILURE_2026-04-25.md:166:- Sortie P1: ticket incident mis à jour avec heure UTC, `branch_id`, owner, et prochaine décision.
reports/runbooks/RUNBOOK_TPE_FAILURE_2026-04-25.md:172:  - Vérification post-action: Ancrer le ticket sur resources/js/components/frontend/kiosk/KioskPaymentComponent.vue:393-414.
reports/runbooks/RUNBOOK_TPE_FAILURE_2026-04-25.md:194:- Sortie P2: ticket incident mis à jour avec heure UTC, `branch_id`, owner, et prochaine décision.
reports/runbooks/RUNBOOK_TPE_FAILURE_2026-04-25.md:197:| Niveau | Trigger | Rôle | Canal | Délai max | Action irréversible? |
reports/runbooks/RUNBOOK_TPE_FAILURE_2026-04-25.md:210:- [ ] Aucune logique prix frontend introduite ou recommandée.
reports/runbooks/RUNBOOK_TPE_FAILURE_2026-04-25.md:211:- [ ] `OrderStatus enum` respecté; aucun statut littéral nouveau demandé.
reports/runbooks/RUNBOOK_TPE_FAILURE_2026-04-25.md:218:- [ ] Escalade L3/L4 déclenchée si seuil atteint.
reports/runbooks/RUNBOOK_TPE_FAILURE_2026-04-25.md:219:- [ ] Monitoring revenu au niveau nominal ou contournement documenté.
reports/runbooks/RUNBOOK_TPE_FAILURE_2026-04-25.md:225:- [ ] Preuve 1 reliée à `resources/js/components/frontend/kiosk/KioskPaymentComponent.vue:393-414` dans le ticket ou dashboard.
reports/runbooks/RUNBOOK_TPE_FAILURE_2026-04-25.md:226:- [ ] Preuve 2 reliée à `resources/js/components/frontend/kiosk/KioskPaymentComponent.vue:473-501` dans le ticket ou dashboard.
reports/runbooks/RUNBOOK_TPE_FAILURE_2026-04-25.md:227:- [ ] Preuve 3 reliée à `resources/js/components/frontend/kiosk/KioskPaymentComponent.vue:562-570` dans le ticket ou dashboard.
reports/runbooks/RUNBOOK_TPE_FAILURE_2026-04-25.md:235:## 7. Template post-mortem
reports/runbooks/RUNBOOK_TPE_FAILURE_2026-04-25.md:279:  - Backend pricing is SSOT: ne pas recalculer un montant côté frontend pendant la réponse incident.
reports/runbooks/RUNBOOK_TPE_FAILURE_2026-04-25.md:280:  - OrderStatus enum authoritative: ne pas demander une correction par littéral numérique hors flux existant.
reports/runbooks/RUNBOOK_TPE_FAILURE_2026-04-25.md:289:- `resources/js/components/frontend/kiosk/KioskPaymentComponent.vue:393-414`
reports/runbooks/RUNBOOK_TPE_FAILURE_2026-04-25.md:290:- `resources/js/components/frontend/kiosk/KioskPaymentComponent.vue:473-501`
reports/runbooks/RUNBOOK_TPE_FAILURE_2026-04-25.md:291:- `resources/js/components/frontend/kiosk/KioskPaymentComponent.vue:562-570`
reports/runbooks/RUNBOOK_TPE_FAILURE_2026-04-25.md:305:- Invariant: `OrderStatus enum` authoritative.
reports/runbooks/RUNBOOK_OUTBOX_BLOCKED_2026-04-25.md:3:- Status: DRAFT_SKELETON_NOT_SIGNED
reports/runbooks/RUNBOOK_OUTBOX_BLOCKED_2026-04-25.md:37:- Côté produit: ne jamais compenser par une règle prix frontend ou un changement de statut hors `OrderStatus enum`.
reports/runbooks/RUNBOOK_OUTBOX_BLOCKED_2026-04-25.md:42:   - Commande / observation: Observation dashboard ou comptage read-only L2; aucune commande SQL dans ce runbook.
reports/runbooks/RUNBOOK_OUTBOX_BLOCKED_2026-04-25.md:70:   - Invariant à vérifier: OrderStatus enum source..
reports/runbooks/RUNBOOK_OUTBOX_BLOCKED_2026-04-25.md:110:  - Action: Utiliser uniquement les commandes rescue/retry existantes et escalader L2; aucune écriture brute DB.
reports/runbooks/RUNBOOK_OUTBOX_BLOCKED_2026-04-25.md:138:- Sortie P0: ticket incident mis à jour avec heure UTC, `branch_id`, owner, et prochaine décision.
reports/runbooks/RUNBOOK_OUTBOX_BLOCKED_2026-04-25.md:162:  - Précondition: Le contournement dure plus de 30 minutes.
reports/runbooks/RUNBOOK_OUTBOX_BLOCKED_2026-04-25.md:166:- Sortie P1: ticket incident mis à jour avec heure UTC, `branch_id`, owner, et prochaine décision.
reports/runbooks/RUNBOOK_OUTBOX_BLOCKED_2026-04-25.md:194:- Sortie P2: ticket incident mis à jour avec heure UTC, `branch_id`, owner, et prochaine décision.
reports/runbooks/RUNBOOK_OUTBOX_BLOCKED_2026-04-25.md:197:| Niveau | Trigger | Rôle | Canal | Délai max | Action irréversible? |
reports/runbooks/RUNBOOK_OUTBOX_BLOCKED_2026-04-25.md:210:- [ ] Aucune logique prix frontend introduite ou recommandée.
reports/runbooks/RUNBOOK_OUTBOX_BLOCKED_2026-04-25.md:211:- [ ] `OrderStatus enum` respecté; aucun statut littéral nouveau demandé.
reports/runbooks/RUNBOOK_OUTBOX_BLOCKED_2026-04-25.md:218:- [ ] Escalade L3/L4 déclenchée si seuil atteint.
reports/runbooks/RUNBOOK_OUTBOX_BLOCKED_2026-04-25.md:219:- [ ] Monitoring revenu au niveau nominal ou contournement documenté.
reports/runbooks/RUNBOOK_OUTBOX_BLOCKED_2026-04-25.md:236:## 7. Template post-mortem
reports/runbooks/RUNBOOK_OUTBOX_BLOCKED_2026-04-25.md:280:  - Backend pricing is SSOT: ne pas recalculer un montant côté frontend pendant la réponse incident.
reports/runbooks/RUNBOOK_OUTBOX_BLOCKED_2026-04-25.md:281:  - OrderStatus enum authoritative: ne pas demander une correction par littéral numérique hors flux existant.
reports/runbooks/RUNBOOK_OUTBOX_BLOCKED_2026-04-25.md:287:  - Le comptage stale se fait en lecture seule par L2/DBA; ce runbook ne fournit pas de SQL exécutable.
reports/runbooks/RUNBOOK_OUTBOX_BLOCKED_2026-04-25.md:307:- Invariant: `OrderStatus enum` authoritative.

exec
/bin/zsh -lc 'git diff --name-only -- reports/runbooks missions/CV1-M20-RUNBOOKS-SKELETON' in /Users/1millnonstop/Downloads/projet/foodking-web/web/testttt
 succeeded in 0ms:
exec
/bin/zsh -lc "rg --no-filename -o '[A-Za-z0-9_./-]+\\.(php|js|vue|md|json|yml|yaml|sh):[0-9]+(-[0-9]+)?' reports/runbooks/RUNBOOK_*_2026-04-25.md | sort -u | while IFS= read -r c; do path=\""'${c%:*}"; range="${c##*:}"; end="${range#*-}"; if [ "$end" = "$range" ]; then end="$range"; fi; if [ ! -e "$path" ]; then printf '"'MISSING %s\\n' \""'$c"; else lines=$(wc -l < "$path" | tr -d '"' '); if [ \""'$end" -gt "$lines" ]; then printf '"'LINE_OUT_OF_RANGE %s file_lines=%s\\n' \""'$c" "$lines"; fi; fi; done' in /Users/1millnonstop/Downloads/projet/foodking-web/web/testttt
 succeeded in 123ms:
LINE_OUT_OF_RANGE plans/PLAN_CAISSE_V1_GPT_MASTERPLAY_2026-04-25.md:372 file_lines=
zsh:1: command not found: wc
zsh:1: command not found: tr
LINE_OUT_OF_RANGE plans/PLAN_CAISSE_V1_GPT_MASTERPLAY_2026-04-25.md:437 file_lines=
zsh:1: command not found: wc
zsh:1: command not found: tr
LINE_OUT_OF_RANGE plans/PLAN_CAISSE_V1_GPT_MASTERPLAY_2026-04-25.md:439 file_lines=
zsh:1: command not found: wc
zsh:1: command not found: tr
LINE_OUT_OF_RANGE plans/PLAN_CAISSE_V1_SUPER_MASTER_2026-04-25.md:291-306 file_lines=
zsh:1: command not found: wc
zsh:1: command not found: tr
LINE_OUT_OF_RANGE plans/PLAN_CAISSE_V1_SUPER_MASTER_2026-04-25.md:81-83 file_lines=
zsh:1: command not found: wc
zsh:1: command not found: tr
LINE_OUT_OF_RANGE resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue:130 file_lines=
zsh:1: command not found: wc
zsh:1: command not found: tr
LINE_OUT_OF_RANGE resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue:786-793 file_lines=
zsh:1: command not found: wc
zsh:1: command not found: tr
LINE_OUT_OF_RANGE resources/js/components/frontend/kiosk/KioskPaymentComponent.vue:292 file_lines=
zsh:1: command not found: wc
zsh:1: command not found: tr
LINE_OUT_OF_RANGE resources/js/components/frontend/kiosk/KioskPaymentComponent.vue:297-305 file_lines=
zsh:1: command not found: wc
zsh:1: command not found: tr
LINE_OUT_OF_RANGE resources/js/components/frontend/kiosk/KioskPaymentComponent.vue:393-414 file_lines=
zsh:1: command not found: wc
zsh:1: command not found: tr
LINE_OUT_OF_RANGE resources/js/components/frontend/kiosk/KioskPaymentComponent.vue:473-501 file_lines=
zsh:1: command not found: wc
zsh:1: command not found: tr
LINE_OUT_OF_RANGE resources/js/components/frontend/kiosk/KioskPaymentComponent.vue:562-570 file_lines=
zsh:1: command not found: wc
zsh:1: command not found: tr
LINE_OUT_OF_RANGE resources/js/components/frontend/kiosk/KioskWaitingComponent.vue:195-198 file_lines=
zsh:1: command not found: wc
zsh:1: command not found: tr
LINE_OUT_OF_RANGE resources/js/components/frontend/kiosk/KioskWaitingComponent.vue:258-305 file_lines=
zsh:1: command not found: wc
zsh:1: command not found: tr
LINE_OUT_OF_RANGE resources/js/components/frontend/kiosk/KioskWaitingComponent.vue:388-392 file_lines=
zsh:1: command not found: wc
zsh:1: command not found: tr
LINE_OUT_OF_RANGE resources/js/helpers/kioskOfflineQueue.js:134-145 file_lines=
zsh:1: command not found: wc
zsh:1: command not found: tr
LINE_OUT_OF_RANGE resources/js/helpers/kioskOfflineQueue.js:327-338 file_lines=

exec
/bin/zsh -lc "sed -n '1,120p' app/Console/Commands/PreflightProductionCommand.php; sed -n '1,80p' app/Console/Commands/OutboxRescueCommand.php; sed -n '1,80p' app/Console/Commands/OutboxRetryFailedCommand.php; sed -n '1,110p' app/Console/Commands/FiscalArchiveCommand.php" in /Users/1millnonstop/Downloads/projet/foodking-web/web/testttt
 succeeded in 0ms:
<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * [W9-AUDIT PROD-4] Pre-deployment configuration gate.
 *
 * Run this command BEFORE switching the release symlink in production:
 *
 *     APP_ENV=production php artisan app:preflight-production
 *
 * Exit code 0 = all checks passed, safe to flip the symlink.
 * Exit code 1 = at least one CRITICAL check failed, DO NOT deploy.
 *
 * Checks are grouped by severity:
 *  - CRITICAL: would corrupt fiscal evidence, leak tenant data, or take the
 *    app offline. Blocks deployment.
 *  - WARNING: degraded operation but not data-corrupting. Logged, does not
 *    block.
 *
 * The checks intentionally duplicate the AppServiceProvider boot guards so
 * ops can run them WITHOUT booting the full HTTP stack (cron jobs, deploy
 * scripts, healthcheck wrappers).
 */
class PreflightProductionCommand extends Command
{
    protected $signature = 'app:preflight-production
                            {--strict : Treat warnings as errors (CI/CD gate mode)}';

    protected $description = 'Verify the runtime configuration is production-grade (NF525, multi-tenant, observability).';

    /** @var array<int, array{level: string, key: string, msg: string}> */
    private array $findings = [];

    public function handle(): int
    {
        $this->line('');
        $this->line('=== FoodKing production preflight ===');
        $this->line('');

        $this->checkAppEnv();
        $this->checkAppDebug();
        $this->checkAppKey();
        $this->checkTimezone();
        $this->checkCacheDriver();
        $this->checkQueueConnection();
        $this->checkBroadcastDriver();
        $this->checkSessionDriver();
        $this->checkLogLevel();
        $this->checkLogChannel();
        $this->checkFiscalSecrets();
        $this->checkFiscalVerifyChain();
        $this->checkDatabaseReachable();
        $this->checkCacheReachable();

        return $this->report();
    }

    private function checkAppEnv(): void
    {
        $env = config('app.env');
        if ($env !== 'production') {
            $this->addFinding('WARNING', 'APP_ENV', "APP_ENV='{$env}' (expected 'production'). Continuing in --strict will fail.");
        } else {
            $this->ok('APP_ENV', 'production');
        }
    }

    private function checkAppDebug(): void
    {
        if (config('app.debug')) {
            $this->addFinding('CRITICAL', 'APP_DEBUG', 'APP_DEBUG=true exposes stack traces and PII in error pages.');
        } else {
            $this->ok('APP_DEBUG', 'false');
        }
    }

    private function checkAppKey(): void
    {
        $key = config('app.key');
        if (empty($key) || str_starts_with((string) $key, 'base64:') === false && strlen((string) $key) < 32) {
            $this->addFinding('CRITICAL', 'APP_KEY', 'APP_KEY is missing or too short. Run `php artisan key:generate`.');
        } else {
            $this->ok('APP_KEY', 'set');
        }
    }

    private function checkTimezone(): void
    {
        $tz = config('app.timezone');
        if ($tz === 'UTC') {
            $this->addFinding('WARNING', 'TIMEZONE', "TIMEZONE='UTC' shifts NF525 J-1 archive boundaries. Set TIMEZONE=Europe/Paris for FR.");
        } else {
            $this->ok('TIMEZONE', $tz);
        }
    }

    private function checkCacheDriver(): void
    {
        $driver = config('cache.default');
        if (in_array($driver, ['array', 'null'], true)) {
            $this->addFinding('CRITICAL', 'CACHE_DRIVER', "CACHE_DRIVER='{$driver}' breaks NF525 audit chain locks across workers. Use redis or memcached.");
        } else {
            $this->ok('CACHE_DRIVER', $driver);
        }
    }

    private function checkQueueConnection(): void
    {
        $driver = config('queue.default');
        if ($driver === 'sync') {
            $this->addFinding('CRITICAL', 'QUEUE_CONNECTION', "QUEUE_CONNECTION='sync' executes jobs in-request (latency, blocking). Use redis or database.");
        } else {
            $this->ok('QUEUE_CONNECTION', $driver);
        }
    }
<?php

namespace App\Console\Commands;

use App\Jobs\DispatchDomainEventsJob;
use App\Models\DomainEvent;
use Illuminate\Console\Command;

class OutboxRescueCommand extends Command
{
    protected $signature = 'foodking:outbox:rescue';

    protected $description = 'Re-queue stale pending domain events';

    public function handle(): int
    {
        $events = DomainEvent::query()
            ->stale(2)
            ->where('attempts', '<', 5)
            ->get();

        foreach ($events as $event) {
            // [Audit Claude NEW-03 B7] Queue lane SSOT = job constructor.
            DispatchDomainEventsJob::dispatch($event->id);
        }

        $this->info('Re-queued ' . $events->count() . ' stale domain events.');

        return self::SUCCESS;
    }
}
<?php

namespace App\Console\Commands;

use App\Jobs\DispatchDomainEventsJob;
use App\Models\DomainEvent;
use Carbon\Carbon;
use Illuminate\Console\Command;
use InvalidArgumentException;

class OutboxRetryFailedCommand extends Command
{
    protected $signature = 'foodking:outbox:retry-failed {--since=1h}';

    protected $description = 'Reset and retry failed domain events';

    public function handle(): int
    {
        $cutoff = $this->resolveCutoff((string) $this->option('since'));

        $events = DomainEvent::query()
            ->failed(5)
            ->where('created_at', '>=', $cutoff)
            ->get();

        foreach ($events as $event) {
            $event->forceFill([
                'attempts' => 0,
                'last_error' => null,
                'dispatched_at' => null,
            ])->save();

            // [Audit Claude NEW-03 B7] Queue lane SSOT = job constructor.
            DispatchDomainEventsJob::dispatch($event->id);
        }

        $this->info('Reset and re-queued ' . $events->count() . ' failed domain events.');

        return self::SUCCESS;
    }

    private function resolveCutoff(string $since): Carbon
    {
        $normalized = strtolower(trim($since));

        if (preg_match('/^(?<value>\d+)(?<unit>[smhd])$/', $normalized, $matches) === 1) {
            $value = (int) $matches['value'];

            return match ($matches['unit']) {
                's' => now()->subSeconds($value),
                'm' => now()->subMinutes($value),
                'h' => now()->subHours($value),
                'd' => now()->subDays($value),
                default => throw new InvalidArgumentException('Unsupported --since unit.'),
            };
        }

        try {
            return Carbon::parse($normalized);
        } catch (\Throwable $exception) {
            throw new InvalidArgumentException('Invalid --since value. Use formats like 30m, 1h, 2d, or a date.', 0, $exception);
        }
    }
}
<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\Order;
use App\Models\ZReport;
use App\Services\Fiscal\ZReportService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use ZipArchive;

/**
 * [POS-9.4.11 / POS-GA-F-01] [POS-9-H.3.3 / F-C8]
 *
 * Exports every fiscal artifact required by NF525 for a given branch
 * over a [from; to] window into a single zip bundle:
 *  - closed Z reports (with signature);
 *  - orders included in the window;
 *  - audit_logs rows (INSERT-only, hash-chained).
 *
 * Memory model (H.3.3 hardening):
 *   The previous implementation called `->get()->toArray()` on each
 *   Eloquent relation, assembling a single in-memory `$bundle` array
 *   and then `json_encode`-ing it whole to pass to
 *   `ZipArchive::addFromString()`. On a 6-year archive that can be
 *   100k+ orders + 500k+ audit_log rows, this was blowing past the
 *   PHP memory_limit (128M default on production).
 *
 *   This version streams each dataset with `->lazy()` (yields one
 *   row at a time from a cursor, bounded by `cursor_chunk_size`) to
 *   a temp file on disk, one JSON row per line (JSONL). Each file is
 *   then added to the zip via `ZipArchive::addFile()` (no in-memory
 *   copy). Peak RSS stays O(single-row) regardless of the window.
 *
 * The bundle is deterministic (sorted by id inside each JSON file, no
 * timestamps inside payloads beyond DB values) so a round-trip always
 * recovers the same document.
 *
 * Retention: 6 years per `config('fiscal.archive_retention_years')`.
 */
class FiscalArchiveCommand extends Command
{
    protected $signature = 'foodking:fiscal:archive
                            {branch_id : Branch to archive}
                            {--from= : Start date (YYYY-MM-DD), inclusive}
                            {--to=   : End date   (YYYY-MM-DD), inclusive}
                            {--no-verify : Skip pre-archive verifyChain (ops recovery only)}';

    protected $description = 'Produce a NF525-compliant fiscal archive (zip) for a branch over a period.';

    /** Rows yielded per cursor fetch — bounds DB memory and Eloquent hydration cost. */
    private const CURSOR_CHUNK = 500;

    /*
     * [W9-AUDIT PROD-1] TOCTOU mitigation lock parameters.
     *
     * - LOCK_TTL: max time the lock is held if the process crashes mid-run
     *   without releasing. 600s = 10min covers worst-case archive of a very
     *   large branch (~500k orders, ~1M audit rows) on slow disk.
     * - LOCK_WAIT: how long we wait to acquire the lock if another writer
     *   (open/close/another archive) holds it. 30s tolerates an in-flight
     *   Z close (typically <2s) without falsely failing the run.
     */
    private const ARCHIVE_LOCK_TTL = 600;
    private const ARCHIVE_LOCK_WAIT = 30;

    public function handle(): int
    {
        $branchId = (int) $this->argument('branch_id');
        if ($branchId <= 0) {
            $this->error('branch_id must be a positive integer.');
            return self::FAILURE;
        }

        $from = $this->option('from') ? Carbon::parse((string) $this->option('from'))->startOfDay() : null;
        /*
         * [W9-AUDIT FIX-4] When --to is provided, normalize to end-of-day for inclusive
         * day-grain semantics. When omitted, use end-of-day of "today" (instead of the
         * current instant) so manual runs without explicit bounds capture the whole day
         * coherently with the scheduled J-1 run (which uses subDay()->startOfDay() and
         * subDay()->endOfDay()). Without this, an op running at 14:32 would silently
         * exclude any Z report closed at 14:33+ that day from the archive, producing
         * partial bundles whose `to` border is implicit and irreproducible.
         */
        $to = $this->option('to')
            ? Carbon::parse((string) $this->option('to'))->endOfDay()
            : Carbon::now()->endOfDay();

        /*
         * [W9.A / G2 finding] Defense-in-depth: verify the Z-chain integrity
         * BEFORE producing the bundle. NF525 archives are evidence; shipping
         * a bundle whose chain is broken would propagate corruption into a
         * tamper-evident long-term store.
         *
         * Behavior:
         * - --no-verify CLI flag → skip (ops recovery, marked unverified)
         * - config('fiscal.verify_chain_before_archive') = false → skip
         * - verify OK → record `z_chain_verified=true` in manifest
         * - verify KO → log CRITICAL on `fiscal` channel, ABORT (FAILURE)
         */
        $verifyEnabled = ! $this->option('no-verify')
            && (bool) Config::get('fiscal.verify_chain_before_archive', true);


exec
/bin/zsh -lc 'git status --short -- reports/runbooks missions/CV1-M20-RUNBOOKS-SKELETON' in /Users/1millnonstop/Downloads/projet/foodking-web/web/testttt
exec
/bin/zsh -lc "rg -n 'foodking:outbox:rescue|foodking:outbox:retry-failed|app:preflight-production|foodking:fiscal:archive|horizon:status|queue:failed' app config routes scripts composer.json reports/runbooks/RUNBOOK_*_2026-04-25.md" in /Users/1millnonstop/Downloads/projet/foodking-web/web/testttt
 succeeded in 0ms:
?? missions/CV1-M20-RUNBOOKS-SKELETON/
?? reports/runbooks/

 succeeded in 0ms:
reports/runbooks/RUNBOOK_FISCAL_SEQUENCE_BREAK_2026-04-25.md:54:   - Commande / observation: `php artisan app:preflight-production`
reports/runbooks/RUNBOOK_FISCAL_SEQUENCE_BREAK_2026-04-25.md:78:   - Commande / observation: `php artisan foodking:fiscal:archive <branch_id> --from=<YYYY-MM-DD> --to=<YYYY-MM-DD>`
reports/runbooks/RUNBOOK_FISCAL_SEQUENCE_BREAK_2026-04-25.md:84:   - Commande / observation: `php artisan app:preflight-production`
app/Console/Kernel.php:39:        $schedule->command('foodking:outbox:rescue')
app/Console/Kernel.php:70:                        $exit = Artisan::call('foodking:fiscal:archive', [
reports/runbooks/RUNBOOK_DISPATCH_QUEUE_SATURATED_2026-04-25.md:12:- Commande `queue:failed` liste `DispatchDomainEventsJob` ou voisins récurrents.
reports/runbooks/RUNBOOK_DISPATCH_QUEUE_SATURATED_2026-04-25.md:42:   - Commande / observation: `php artisan horizon:status`
reports/runbooks/RUNBOOK_DISPATCH_QUEUE_SATURATED_2026-04-25.md:48:   - Commande / observation: `php artisan queue:failed`
reports/runbooks/RUNBOOK_DISPATCH_QUEUE_SATURATED_2026-04-25.md:72:   - Commande / observation: `php artisan app:preflight-production`
reports/runbooks/RUNBOOK_DISPATCH_QUEUE_SATURATED_2026-04-25.md:78:   - Commande / observation: `php artisan queue:failed`
reports/runbooks/RUNBOOK_DISPATCH_QUEUE_SATURATED_2026-04-25.md:84:   - Commande / observation: `php artisan queue:failed`
reports/runbooks/RUNBOOK_ROLLBACK_CANARY_2026-04-25.md:84:   - Commande / observation: `php artisan app:preflight-production`
reports/runbooks/RUNBOOK_ROLLBACK_CANARY_2026-04-25.md:90:   - Commande / observation: `php artisan app:preflight-production`
app/Console/Commands/OutboxRescueCommand.php:11:    protected $signature = 'foodking:outbox:rescue';
app/Console/Commands/FiscalArchiveCommand.php:50:    protected $signature = 'foodking:fiscal:archive
reports/runbooks/RUNBOOK_OUTBOX_BLOCKED_2026-04-25.md:13:- `foodking:outbox:rescue` requeue des stale mais backlog revient.
reports/runbooks/RUNBOOK_OUTBOX_BLOCKED_2026-04-25.md:14:- `foodking:outbox:retry-failed` reset/requeue sans progression durable.
reports/runbooks/RUNBOOK_OUTBOX_BLOCKED_2026-04-25.md:48:   - Commande / observation: `php artisan foodking:outbox:rescue`
reports/runbooks/RUNBOOK_OUTBOX_BLOCKED_2026-04-25.md:54:   - Commande / observation: `php artisan foodking:outbox:retry-failed --since=1h`
app/Console/Commands/PreflightProductionCommand.php:14: *     APP_ENV=production php artisan app:preflight-production
app/Console/Commands/PreflightProductionCommand.php:31:    protected $signature = 'app:preflight-production
app/Console/Commands/OutboxRetryFailedCommand.php:13:    protected $signature = 'foodking:outbox:retry-failed {--since=1h}';
reports/runbooks/RUNBOOK_TPE_FAILURE_2026-04-25.md:90:   - Commande / observation: `php artisan app:preflight-production`

exec
/bin/zsh -lc "rg --no-filename -o '[A-Za-z0-9_./-]+\\.(php|js|vue|md|json|yml|yaml|sh):[0-9]+(-[0-9]+)?' reports/runbooks/RUNBOOK_*_2026-04-25.md | sort -u | while IFS= read -r c; do ref_file=\""'${c%:*}"; range="${c##*:}"; end="${range#*-}"; if [ "$end" = "$range" ]; then end="$range"; fi; if [ ! -e "$ref_file" ]; then printf '"'MISSING %s\\n' \""'$c"; else lines=$(/usr/bin/wc -l < "$ref_file" | /usr/bin/tr -d '"' '); if [ \""'$end" -gt "$lines" ]; then printf '"'LINE_OUT_OF_RANGE %s file_lines=%s\\n' \""'$c" "$lines"; fi; fi; done' in /Users/1millnonstop/Downloads/projet/foodking-web/web/testttt
 succeeded in 281ms:
exec
/bin/zsh -lc "rg -n 'DRAFT_SKELETON_NOT_SIGNED|INDEX_STATUS: DRAFT_SKELETON|PENDING_HUMAN_GATE|aucune approbation|aucun gate|ne signe|ne modifie code produit|Aucun code|Aucune logique prix frontend|OrderStatus enum|branch_id isolation|dispatch after commit|FrontendOrderService symmetry|Frozen|frozen' reports/runbooks/RUNBOOK_*_2026-04-25.md" in /Users/1millnonstop/Downloads/projet/foodking-web/web/testttt
 succeeded in 0ms:
reports/runbooks/RUNBOOK_INDEX_2026-04-25.md:4:- INDEX_STATUS: DRAFT_SKELETON
reports/runbooks/RUNBOOK_INDEX_2026-04-25.md:38:4. Action: appliquer P0/P1/P2 selon criticité, escalader immédiatement si gate/fiscal/frozen zone.
reports/runbooks/RUNBOOK_INDEX_2026-04-25.md:40:- Règle commune: aucun runbook ne signe un gate, ne modifie code produit, ou ne contourne `branch_id`.
reports/runbooks/RUNBOOK_INDEX_2026-04-25.md:42:- Règle commune: `OrderStatus enum` reste autoritaire; les statuts numériques existants ne deviennent pas nouveau contrat ops.
reports/runbooks/RUNBOOK_INDEX_2026-04-25.md:43:- Règle commune: dispatch after commit; relances outbox uniquement via commandes existantes citées.
reports/runbooks/RUNBOOK_INDEX_2026-04-25.md:44:- Règle commune: frozen zones non éditées depuis M-20.
reports/runbooks/RUNBOOK_DISPATCH_QUEUE_SATURATED_2026-04-25.md:3:- Status: DRAFT_SKELETON_NOT_SIGNED
reports/runbooks/RUNBOOK_DISPATCH_QUEUE_SATURATED_2026-04-25.md:27:- Aucun seuil de gate n’est approuvé dans ce runbook; les gates restent `PENDING_HUMAN_GATE` quand cités.
reports/runbooks/RUNBOOK_DISPATCH_QUEUE_SATURATED_2026-04-25.md:37:- Côté produit: ne jamais compenser par une règle prix frontend ou un changement de statut hors `OrderStatus enum`.
reports/runbooks/RUNBOOK_DISPATCH_QUEUE_SATURATED_2026-04-25.md:52:   - Invariant à vérifier: dispatch after commit..
reports/runbooks/RUNBOOK_DISPATCH_QUEUE_SATURATED_2026-04-25.md:88:   - Invariant à vérifier: OrderStatus enum..
reports/runbooks/RUNBOOK_DISPATCH_QUEUE_SATURATED_2026-04-25.md:190:  - Précondition: Doute sur un gate ou une frozen zone.
reports/runbooks/RUNBOOK_DISPATCH_QUEUE_SATURATED_2026-04-25.md:201:| L3 | P0 durable, gate, frozen zone, ou impact multi-branches | CTO / humain responsable | Appel direct + canal incident | 30 min | Oui, décision humaine requise |
reports/runbooks/RUNBOOK_DISPATCH_QUEUE_SATURATED_2026-04-25.md:210:- [ ] Aucune logique prix frontend introduite ou recommandée.
reports/runbooks/RUNBOOK_DISPATCH_QUEUE_SATURATED_2026-04-25.md:211:- [ ] `OrderStatus enum` respecté; aucun statut littéral nouveau demandé.
reports/runbooks/RUNBOOK_DISPATCH_QUEUE_SATURATED_2026-04-25.md:212:- [ ] `dispatch after commit` respecté; aucune relance ne contourne l’outbox.
reports/runbooks/RUNBOOK_DISPATCH_QUEUE_SATURATED_2026-04-25.md:281:  - OrderStatus enum authoritative: ne pas demander une correction par littéral numérique hors flux existant.
reports/runbooks/RUNBOOK_DISPATCH_QUEUE_SATURATED_2026-04-25.md:282:  - branch_id isolation: toute preuve, capture, dashboard ou ticket doit porter la branche concernée.
reports/runbooks/RUNBOOK_DISPATCH_QUEUE_SATURATED_2026-04-25.md:283:  - dispatch after commit: ne pas recommander un dispatch manuel qui contourne les garanties after-commit.
reports/runbooks/RUNBOOK_DISPATCH_QUEUE_SATURATED_2026-04-25.md:284:  - OrderService / FrontendOrderService symmetry: toute divergence constatée devient point M-10, pas patch ops.
reports/runbooks/RUNBOOK_DISPATCH_QUEUE_SATURATED_2026-04-25.md:285:  - frozen zones: aucune édition de fichier gelé depuis un runbook non signé.
reports/runbooks/RUNBOOK_DISPATCH_QUEUE_SATURATED_2026-04-25.md:301:- Gate: `(none)` (PENDING_HUMAN_GATE ou à drafter; aucune approbation dans ce runbook).
reports/runbooks/RUNBOOK_DISPATCH_QUEUE_SATURATED_2026-04-25.md:307:- Invariant: `OrderStatus enum` authoritative.
reports/runbooks/RUNBOOK_DISPATCH_QUEUE_SATURATED_2026-04-25.md:309:- Invariant: dispatch after commit.
reports/runbooks/RUNBOOK_DISPATCH_QUEUE_SATURATED_2026-04-25.md:310:- Invariant: OrderService / FrontendOrderService symmetry.
reports/runbooks/RUNBOOK_DISPATCH_QUEUE_SATURATED_2026-04-25.md:311:- Invariant: frozen zones require human gate clearance.
reports/runbooks/RUNBOOK_KIOSK_NETWORK_LOSS_2026-04-25.md:3:- Status: DRAFT_SKELETON_NOT_SIGNED
reports/runbooks/RUNBOOK_KIOSK_NETWORK_LOSS_2026-04-25.md:27:- Aucun seuil de gate n’est approuvé dans ce runbook; les gates restent `PENDING_HUMAN_GATE` quand cités.
reports/runbooks/RUNBOOK_KIOSK_NETWORK_LOSS_2026-04-25.md:37:- Côté produit: ne jamais compenser par une règle prix frontend ou un changement de statut hors `OrderStatus enum`.
reports/runbooks/RUNBOOK_KIOSK_NETWORK_LOSS_2026-04-25.md:82:   - Invariant à vérifier: OrderStatus enum; pas de littéral nouveau..
reports/runbooks/RUNBOOK_KIOSK_NETWORK_LOSS_2026-04-25.md:190:  - Précondition: Doute sur un gate ou une frozen zone.
reports/runbooks/RUNBOOK_KIOSK_NETWORK_LOSS_2026-04-25.md:201:| L3 | P0 durable, gate, frozen zone, ou impact multi-branches | CTO / humain responsable | Appel direct + canal incident | 30 min | Oui, décision humaine requise |
reports/runbooks/RUNBOOK_KIOSK_NETWORK_LOSS_2026-04-25.md:210:- [ ] Aucune logique prix frontend introduite ou recommandée.
reports/runbooks/RUNBOOK_KIOSK_NETWORK_LOSS_2026-04-25.md:211:- [ ] `OrderStatus enum` respecté; aucun statut littéral nouveau demandé.
reports/runbooks/RUNBOOK_KIOSK_NETWORK_LOSS_2026-04-25.md:212:- [ ] `dispatch after commit` respecté; aucune relance ne contourne l’outbox.
reports/runbooks/RUNBOOK_KIOSK_NETWORK_LOSS_2026-04-25.md:281:  - OrderStatus enum authoritative: ne pas demander une correction par littéral numérique hors flux existant.
reports/runbooks/RUNBOOK_KIOSK_NETWORK_LOSS_2026-04-25.md:282:  - branch_id isolation: toute preuve, capture, dashboard ou ticket doit porter la branche concernée.
reports/runbooks/RUNBOOK_KIOSK_NETWORK_LOSS_2026-04-25.md:283:  - dispatch after commit: ne pas recommander un dispatch manuel qui contourne les garanties after-commit.
reports/runbooks/RUNBOOK_KIOSK_NETWORK_LOSS_2026-04-25.md:284:  - OrderService / FrontendOrderService symmetry: toute divergence constatée devient point M-10, pas patch ops.
reports/runbooks/RUNBOOK_KIOSK_NETWORK_LOSS_2026-04-25.md:285:  - frozen zones: aucune édition de fichier gelé depuis un runbook non signé.
reports/runbooks/RUNBOOK_KIOSK_NETWORK_LOSS_2026-04-25.md:301:- Gate: `GATE_OFFLINE_SCOPE_V1` (PENDING_HUMAN_GATE ou à drafter; aucune approbation dans ce runbook).
reports/runbooks/RUNBOOK_KIOSK_NETWORK_LOSS_2026-04-25.md:307:- Invariant: `OrderStatus enum` authoritative.
reports/runbooks/RUNBOOK_KIOSK_NETWORK_LOSS_2026-04-25.md:309:- Invariant: dispatch after commit.
reports/runbooks/RUNBOOK_KIOSK_NETWORK_LOSS_2026-04-25.md:310:- Invariant: OrderService / FrontendOrderService symmetry.
reports/runbooks/RUNBOOK_KIOSK_NETWORK_LOSS_2026-04-25.md:311:- Invariant: frozen zones require human gate clearance.
reports/runbooks/RUNBOOK_PRINTER_FAILURE_2026-04-25.md:3:- Status: DRAFT_SKELETON_NOT_SIGNED
reports/runbooks/RUNBOOK_PRINTER_FAILURE_2026-04-25.md:27:- Aucun seuil de gate n’est approuvé dans ce runbook; les gates restent `PENDING_HUMAN_GATE` quand cités.
reports/runbooks/RUNBOOK_PRINTER_FAILURE_2026-04-25.md:37:- Côté produit: ne jamais compenser par une règle prix frontend ou un changement de statut hors `OrderStatus enum`.
reports/runbooks/RUNBOOK_PRINTER_FAILURE_2026-04-25.md:190:  - Précondition: Doute sur un gate ou une frozen zone.
reports/runbooks/RUNBOOK_PRINTER_FAILURE_2026-04-25.md:201:| L3 | P0 durable, gate, frozen zone, ou impact multi-branches | CTO / humain responsable | Appel direct + canal incident | 30 min | Oui, décision humaine requise |
reports/runbooks/RUNBOOK_PRINTER_FAILURE_2026-04-25.md:210:- [ ] Aucune logique prix frontend introduite ou recommandée.
reports/runbooks/RUNBOOK_PRINTER_FAILURE_2026-04-25.md:211:- [ ] `OrderStatus enum` respecté; aucun statut littéral nouveau demandé.
reports/runbooks/RUNBOOK_PRINTER_FAILURE_2026-04-25.md:212:- [ ] `dispatch after commit` respecté; aucune relance ne contourne l’outbox.
reports/runbooks/RUNBOOK_PRINTER_FAILURE_2026-04-25.md:281:  - OrderStatus enum authoritative: ne pas demander une correction par littéral numérique hors flux existant.
reports/runbooks/RUNBOOK_PRINTER_FAILURE_2026-04-25.md:282:  - branch_id isolation: toute preuve, capture, dashboard ou ticket doit porter la branche concernée.
reports/runbooks/RUNBOOK_PRINTER_FAILURE_2026-04-25.md:283:  - dispatch after commit: ne pas recommander un dispatch manuel qui contourne les garanties after-commit.
reports/runbooks/RUNBOOK_PRINTER_FAILURE_2026-04-25.md:284:  - OrderService / FrontendOrderService symmetry: toute divergence constatée devient point M-10, pas patch ops.
reports/runbooks/RUNBOOK_PRINTER_FAILURE_2026-04-25.md:285:  - frozen zones: aucune édition de fichier gelé depuis un runbook non signé.
reports/runbooks/RUNBOOK_PRINTER_FAILURE_2026-04-25.md:303:- Gate: `(none)` (PENDING_HUMAN_GATE ou à drafter; aucune approbation dans ce runbook).
reports/runbooks/RUNBOOK_PRINTER_FAILURE_2026-04-25.md:309:- Invariant: `OrderStatus enum` authoritative.
reports/runbooks/RUNBOOK_PRINTER_FAILURE_2026-04-25.md:311:- Invariant: dispatch after commit.
reports/runbooks/RUNBOOK_PRINTER_FAILURE_2026-04-25.md:312:- Invariant: OrderService / FrontendOrderService symmetry.
reports/runbooks/RUNBOOK_PRINTER_FAILURE_2026-04-25.md:313:- Invariant: frozen zones require human gate clearance.
reports/runbooks/RUNBOOK_FISCAL_SEQUENCE_BREAK_2026-04-25.md:3:- Status: DRAFT_SKELETON_NOT_SIGNED
reports/runbooks/RUNBOOK_FISCAL_SEQUENCE_BREAK_2026-04-25.md:27:- Aucun seuil de gate n’est approuvé dans ce runbook; les gates restent `PENDING_HUMAN_GATE` quand cités.
reports/runbooks/RUNBOOK_FISCAL_SEQUENCE_BREAK_2026-04-25.md:37:- Côté produit: ne jamais compenser par une règle prix frontend ou un changement de statut hors `OrderStatus enum`.
reports/runbooks/RUNBOOK_FISCAL_SEQUENCE_BREAK_2026-04-25.md:190:  - Précondition: Doute sur un gate ou une frozen zone.
reports/runbooks/RUNBOOK_FISCAL_SEQUENCE_BREAK_2026-04-25.md:201:| L3 | P0 durable, gate, frozen zone, ou impact multi-branches | CTO / humain responsable | Appel direct + canal incident | 30 min | Oui, décision humaine requise |
reports/runbooks/RUNBOOK_FISCAL_SEQUENCE_BREAK_2026-04-25.md:213:- [ ] Aucune logique prix frontend introduite ou recommandée.
reports/runbooks/RUNBOOK_FISCAL_SEQUENCE_BREAK_2026-04-25.md:214:- [ ] `OrderStatus enum` respecté; aucun statut littéral nouveau demandé.
reports/runbooks/RUNBOOK_FISCAL_SEQUENCE_BREAK_2026-04-25.md:215:- [ ] `dispatch after commit` respecté; aucune relance ne contourne l’outbox.
reports/runbooks/RUNBOOK_FISCAL_SEQUENCE_BREAK_2026-04-25.md:284:  - OrderStatus enum authoritative: ne pas demander une correction par littéral numérique hors flux existant.
reports/runbooks/RUNBOOK_FISCAL_SEQUENCE_BREAK_2026-04-25.md:285:  - branch_id isolation: toute preuve, capture, dashboard ou ticket doit porter la branche concernée.
reports/runbooks/RUNBOOK_FISCAL_SEQUENCE_BREAK_2026-04-25.md:286:  - dispatch after commit: ne pas recommander un dispatch manuel qui contourne les garanties after-commit.
reports/runbooks/RUNBOOK_FISCAL_SEQUENCE_BREAK_2026-04-25.md:287:  - OrderService / FrontendOrderService symmetry: toute divergence constatée devient point M-10, pas patch ops.
reports/runbooks/RUNBOOK_FISCAL_SEQUENCE_BREAK_2026-04-25.md:288:  - frozen zones: aucune édition de fichier gelé depuis un runbook non signé.
reports/runbooks/RUNBOOK_FISCAL_SEQUENCE_BREAK_2026-04-25.md:306:- Gate: `GATE_FISCAL_KIOSK_V1` (PENDING_HUMAN_GATE ou à drafter; aucune approbation dans ce runbook).
reports/runbooks/RUNBOOK_FISCAL_SEQUENCE_BREAK_2026-04-25.md:307:- Gate: `GATE_SCHEMA_MIGRATIONS_V1` (PENDING_HUMAN_GATE ou à drafter; aucune approbation dans ce runbook).
reports/runbooks/RUNBOOK_FISCAL_SEQUENCE_BREAK_2026-04-25.md:313:- Invariant: `OrderStatus enum` authoritative.
reports/runbooks/RUNBOOK_FISCAL_SEQUENCE_BREAK_2026-04-25.md:315:- Invariant: dispatch after commit.
reports/runbooks/RUNBOOK_FISCAL_SEQUENCE_BREAK_2026-04-25.md:316:- Invariant: OrderService / FrontendOrderService symmetry.
reports/runbooks/RUNBOOK_FISCAL_SEQUENCE_BREAK_2026-04-25.md:317:- Invariant: frozen zones require human gate clearance.
reports/runbooks/RUNBOOK_TPE_FAILURE_2026-04-25.md:3:- Status: DRAFT_SKELETON_NOT_SIGNED
reports/runbooks/RUNBOOK_TPE_FAILURE_2026-04-25.md:27:- Aucun seuil de gate n’est approuvé dans ce runbook; les gates restent `PENDING_HUMAN_GATE` quand cités.
reports/runbooks/RUNBOOK_TPE_FAILURE_2026-04-25.md:37:- Côté produit: ne jamais compenser par une règle prix frontend ou un changement de statut hors `OrderStatus enum`.
reports/runbooks/RUNBOOK_TPE_FAILURE_2026-04-25.md:82:   - Invariant à vérifier: OrderService / FrontendOrderService symmetry..
reports/runbooks/RUNBOOK_TPE_FAILURE_2026-04-25.md:88:   - Invariant à vérifier: OrderStatus enum, pas de statut littéral..
reports/runbooks/RUNBOOK_TPE_FAILURE_2026-04-25.md:190:  - Précondition: Doute sur un gate ou une frozen zone.
reports/runbooks/RUNBOOK_TPE_FAILURE_2026-04-25.md:201:| L3 | P0 durable, gate, frozen zone, ou impact multi-branches | CTO / humain responsable | Appel direct + canal incident | 30 min | Oui, décision humaine requise |
reports/runbooks/RUNBOOK_TPE_FAILURE_2026-04-25.md:210:- [ ] Aucune logique prix frontend introduite ou recommandée.
reports/runbooks/RUNBOOK_TPE_FAILURE_2026-04-25.md:211:- [ ] `OrderStatus enum` respecté; aucun statut littéral nouveau demandé.
reports/runbooks/RUNBOOK_TPE_FAILURE_2026-04-25.md:212:- [ ] `dispatch after commit` respecté; aucune relance ne contourne l’outbox.
reports/runbooks/RUNBOOK_TPE_FAILURE_2026-04-25.md:280:  - OrderStatus enum authoritative: ne pas demander une correction par littéral numérique hors flux existant.
reports/runbooks/RUNBOOK_TPE_FAILURE_2026-04-25.md:281:  - branch_id isolation: toute preuve, capture, dashboard ou ticket doit porter la branche concernée.
reports/runbooks/RUNBOOK_TPE_FAILURE_2026-04-25.md:282:  - dispatch after commit: ne pas recommander un dispatch manuel qui contourne les garanties after-commit.
reports/runbooks/RUNBOOK_TPE_FAILURE_2026-04-25.md:283:  - OrderService / FrontendOrderService symmetry: toute divergence constatée devient point M-10, pas patch ops.
reports/runbooks/RUNBOOK_TPE_FAILURE_2026-04-25.md:284:  - frozen zones: aucune édition de fichier gelé depuis un runbook non signé.
reports/runbooks/RUNBOOK_TPE_FAILURE_2026-04-25.md:298:- Gate: `GATE_PAYMENT_LEDGER_V1` (PENDING_HUMAN_GATE ou à drafter; aucune approbation dans ce runbook).
reports/runbooks/RUNBOOK_TPE_FAILURE_2026-04-25.md:299:- Gate: `GATE_VERIFY_P0_FROZEN_CONSOLIDATED_2026-04-20` (PENDING_HUMAN_GATE ou à drafter; aucune approbation dans ce runbook).
reports/runbooks/RUNBOOK_TPE_FAILURE_2026-04-25.md:305:- Invariant: `OrderStatus enum` authoritative.
reports/runbooks/RUNBOOK_TPE_FAILURE_2026-04-25.md:307:- Invariant: dispatch after commit.
reports/runbooks/RUNBOOK_TPE_FAILURE_2026-04-25.md:308:- Invariant: OrderService / FrontendOrderService symmetry.
reports/runbooks/RUNBOOK_TPE_FAILURE_2026-04-25.md:309:- Invariant: frozen zones require human gate clearance.
reports/runbooks/RUNBOOK_OUTBOX_BLOCKED_2026-04-25.md:3:- Status: DRAFT_SKELETON_NOT_SIGNED
reports/runbooks/RUNBOOK_OUTBOX_BLOCKED_2026-04-25.md:27:- Aucun seuil de gate n’est approuvé dans ce runbook; les gates restent `PENDING_HUMAN_GATE` quand cités.
reports/runbooks/RUNBOOK_OUTBOX_BLOCKED_2026-04-25.md:37:- Côté produit: ne jamais compenser par une règle prix frontend ou un changement de statut hors `OrderStatus enum`.
reports/runbooks/RUNBOOK_OUTBOX_BLOCKED_2026-04-25.md:64:   - Invariant à vérifier: dispatch after commit..
reports/runbooks/RUNBOOK_OUTBOX_BLOCKED_2026-04-25.md:70:   - Invariant à vérifier: OrderStatus enum source..
reports/runbooks/RUNBOOK_OUTBOX_BLOCKED_2026-04-25.md:89:9. Vérifier dispatch after commit trait
reports/runbooks/RUNBOOK_OUTBOX_BLOCKED_2026-04-25.md:190:  - Précondition: Doute sur un gate ou une frozen zone.
reports/runbooks/RUNBOOK_OUTBOX_BLOCKED_2026-04-25.md:201:| L3 | P0 durable, gate, frozen zone, ou impact multi-branches | CTO / humain responsable | Appel direct + canal incident | 30 min | Oui, décision humaine requise |
reports/runbooks/RUNBOOK_OUTBOX_BLOCKED_2026-04-25.md:210:- [ ] Aucune logique prix frontend introduite ou recommandée.
reports/runbooks/RUNBOOK_OUTBOX_BLOCKED_2026-04-25.md:211:- [ ] `OrderStatus enum` respecté; aucun statut littéral nouveau demandé.
reports/runbooks/RUNBOOK_OUTBOX_BLOCKED_2026-04-25.md:212:- [ ] `dispatch after commit` respecté; aucune relance ne contourne l’outbox.
reports/runbooks/RUNBOOK_OUTBOX_BLOCKED_2026-04-25.md:281:  - OrderStatus enum authoritative: ne pas demander une correction par littéral numérique hors flux existant.
reports/runbooks/RUNBOOK_OUTBOX_BLOCKED_2026-04-25.md:282:  - branch_id isolation: toute preuve, capture, dashboard ou ticket doit porter la branche concernée.
reports/runbooks/RUNBOOK_OUTBOX_BLOCKED_2026-04-25.md:283:  - dispatch after commit: ne pas recommander un dispatch manuel qui contourne les garanties after-commit.
reports/runbooks/RUNBOOK_OUTBOX_BLOCKED_2026-04-25.md:284:  - OrderService / FrontendOrderService symmetry: toute divergence constatée devient point M-10, pas patch ops.
reports/runbooks/RUNBOOK_OUTBOX_BLOCKED_2026-04-25.md:285:  - frozen zones: aucune édition de fichier gelé depuis un runbook non signé.
reports/runbooks/RUNBOOK_OUTBOX_BLOCKED_2026-04-25.md:301:- Gate: `(none)` (PENDING_HUMAN_GATE ou à drafter; aucune approbation dans ce runbook).
reports/runbooks/RUNBOOK_OUTBOX_BLOCKED_2026-04-25.md:307:- Invariant: `OrderStatus enum` authoritative.
reports/runbooks/RUNBOOK_OUTBOX_BLOCKED_2026-04-25.md:309:- Invariant: dispatch after commit.
reports/runbooks/RUNBOOK_OUTBOX_BLOCKED_2026-04-25.md:310:- Invariant: OrderService / FrontendOrderService symmetry.
reports/runbooks/RUNBOOK_OUTBOX_BLOCKED_2026-04-25.md:311:- Invariant: frozen zones require human gate clearance.
reports/runbooks/RUNBOOK_KDS_MULTISCREEN_DESYNC_2026-04-25.md:3:- Status: DRAFT_SKELETON_NOT_SIGNED
reports/runbooks/RUNBOOK_KDS_MULTISCREEN_DESYNC_2026-04-25.md:27:- Aucun seuil de gate n’est approuvé dans ce runbook; les gates restent `PENDING_HUMAN_GATE` quand cités.
reports/runbooks/RUNBOOK_KDS_MULTISCREEN_DESYNC_2026-04-25.md:37:- Côté produit: ne jamais compenser par une règle prix frontend ou un changement de statut hors `OrderStatus enum`.
reports/runbooks/RUNBOOK_KDS_MULTISCREEN_DESYNC_2026-04-25.md:46:   - Invariant à vérifier: OrderStatus enum..
reports/runbooks/RUNBOOK_KDS_MULTISCREEN_DESYNC_2026-04-25.md:64:   - Invariant à vérifier: OrderStatus enum et permissions..
reports/runbooks/RUNBOOK_KDS_MULTISCREEN_DESYNC_2026-04-25.md:94:   - Invariant à vérifier: OrderStatus enum..
reports/runbooks/RUNBOOK_KDS_MULTISCREEN_DESYNC_2026-04-25.md:190:  - Précondition: Doute sur un gate ou une frozen zone.
reports/runbooks/RUNBOOK_KDS_MULTISCREEN_DESYNC_2026-04-25.md:201:| L3 | P0 durable, gate, frozen zone, ou impact multi-branches | CTO / humain responsable | Appel direct + canal incident | 30 min | Oui, décision humaine requise |
reports/runbooks/RUNBOOK_KDS_MULTISCREEN_DESYNC_2026-04-25.md:210:- [ ] Aucune logique prix frontend introduite ou recommandée.
reports/runbooks/RUNBOOK_KDS_MULTISCREEN_DESYNC_2026-04-25.md:211:- [ ] `OrderStatus enum` respecté; aucun statut littéral nouveau demandé.
reports/runbooks/RUNBOOK_KDS_MULTISCREEN_DESYNC_2026-04-25.md:212:- [ ] `dispatch after commit` respecté; aucune relance ne contourne l’outbox.
reports/runbooks/RUNBOOK_KDS_MULTISCREEN_DESYNC_2026-04-25.md:281:  - OrderStatus enum authoritative: ne pas demander une correction par littéral numérique hors flux existant.
reports/runbooks/RUNBOOK_KDS_MULTISCREEN_DESYNC_2026-04-25.md:282:  - branch_id isolation: toute preuve, capture, dashboard ou ticket doit porter la branche concernée.
reports/runbooks/RUNBOOK_KDS_MULTISCREEN_DESYNC_2026-04-25.md:283:  - dispatch after commit: ne pas recommander un dispatch manuel qui contourne les garanties after-commit.
reports/runbooks/RUNBOOK_KDS_MULTISCREEN_DESYNC_2026-04-25.md:284:  - OrderService / FrontendOrderService symmetry: toute divergence constatée devient point M-10, pas patch ops.
reports/runbooks/RUNBOOK_KDS_MULTISCREEN_DESYNC_2026-04-25.md:285:  - frozen zones: aucune édition de fichier gelé depuis un runbook non signé.
reports/runbooks/RUNBOOK_KDS_MULTISCREEN_DESYNC_2026-04-25.md:300:- Gate: `GATE_KDS_BUMP_V1` (PENDING_HUMAN_GATE ou à drafter; aucune approbation dans ce runbook).
reports/runbooks/RUNBOOK_KDS_MULTISCREEN_DESYNC_2026-04-25.md:306:- Invariant: `OrderStatus enum` authoritative.
reports/runbooks/RUNBOOK_KDS_MULTISCREEN_DESYNC_2026-04-25.md:308:- Invariant: dispatch after commit.
reports/runbooks/RUNBOOK_KDS_MULTISCREEN_DESYNC_2026-04-25.md:309:- Invariant: OrderService / FrontendOrderService symmetry.
reports/runbooks/RUNBOOK_KDS_MULTISCREEN_DESYNC_2026-04-25.md:310:- Invariant: frozen zones require human gate clearance.
reports/runbooks/RUNBOOK_ROLLBACK_CANARY_2026-04-25.md:3:- Status: DRAFT_SKELETON_NOT_SIGNED
reports/runbooks/RUNBOOK_ROLLBACK_CANARY_2026-04-25.md:27:- Aucun seuil de gate n’est approuvé dans ce runbook; les gates restent `PENDING_HUMAN_GATE` quand cités.
reports/runbooks/RUNBOOK_ROLLBACK_CANARY_2026-04-25.md:37:- Côté produit: ne jamais compenser par une règle prix frontend ou un changement de statut hors `OrderStatus enum`.
reports/runbooks/RUNBOOK_ROLLBACK_CANARY_2026-04-25.md:58:   - Invariant à vérifier: OrderStatus enum..
reports/runbooks/RUNBOOK_ROLLBACK_CANARY_2026-04-25.md:100:   - Invariant à vérifier: frozen/schema gates..
reports/runbooks/RUNBOOK_ROLLBACK_CANARY_2026-04-25.md:198:  - Précondition: Doute sur un gate ou une frozen zone.
reports/runbooks/RUNBOOK_ROLLBACK_CANARY_2026-04-25.md:209:| L3 | P0 durable, gate, frozen zone, ou impact multi-branches | CTO / humain responsable | Appel direct + canal incident | 30 min | Oui, décision humaine requise |
reports/runbooks/RUNBOOK_ROLLBACK_CANARY_2026-04-25.md:218:- [ ] Aucune logique prix frontend introduite ou recommandée.
reports/runbooks/RUNBOOK_ROLLBACK_CANARY_2026-04-25.md:219:- [ ] `OrderStatus enum` respecté; aucun statut littéral nouveau demandé.
reports/runbooks/RUNBOOK_ROLLBACK_CANARY_2026-04-25.md:220:- [ ] `dispatch after commit` respecté; aucune relance ne contourne l’outbox.
reports/runbooks/RUNBOOK_ROLLBACK_CANARY_2026-04-25.md:289:  - OrderStatus enum authoritative: ne pas demander une correction par littéral numérique hors flux existant.
reports/runbooks/RUNBOOK_ROLLBACK_CANARY_2026-04-25.md:290:  - branch_id isolation: toute preuve, capture, dashboard ou ticket doit porter la branche concernée.
reports/runbooks/RUNBOOK_ROLLBACK_CANARY_2026-04-25.md:291:  - dispatch after commit: ne pas recommander un dispatch manuel qui contourne les garanties after-commit.
reports/runbooks/RUNBOOK_ROLLBACK_CANARY_2026-04-25.md:292:  - OrderService / FrontendOrderService symmetry: toute divergence constatée devient point M-10, pas patch ops.
reports/runbooks/RUNBOOK_ROLLBACK_CANARY_2026-04-25.md:293:  - frozen zones: aucune édition de fichier gelé depuis un runbook non signé.
reports/runbooks/RUNBOOK_ROLLBACK_CANARY_2026-04-25.md:309:- Gate: `GATE_GO_NO_GO_CAISSE_V1` (PENDING_HUMAN_GATE ou à drafter; aucune approbation dans ce runbook).
reports/runbooks/RUNBOOK_ROLLBACK_CANARY_2026-04-25.md:310:- Gate: `GATE_PAYMENT_LEDGER_V1` (PENDING_HUMAN_GATE ou à drafter; aucune approbation dans ce runbook).
reports/runbooks/RUNBOOK_ROLLBACK_CANARY_2026-04-25.md:311:- Gate: `GATE_FISCAL_KIOSK_V1` (PENDING_HUMAN_GATE ou à drafter; aucune approbation dans ce runbook).
reports/runbooks/RUNBOOK_ROLLBACK_CANARY_2026-04-25.md:312:- Gate: `GATE_KDS_BUMP_V1` (PENDING_HUMAN_GATE ou à drafter; aucune approbation dans ce runbook).
reports/runbooks/RUNBOOK_ROLLBACK_CANARY_2026-04-25.md:313:- Gate: `GATE_OFFLINE_SCOPE_V1` (PENDING_HUMAN_GATE ou à drafter; aucune approbation dans ce runbook).
reports/runbooks/RUNBOOK_ROLLBACK_CANARY_2026-04-25.md:319:- Invariant: `OrderStatus enum` authoritative.
reports/runbooks/RUNBOOK_ROLLBACK_CANARY_2026-04-25.md:321:- Invariant: dispatch after commit.
reports/runbooks/RUNBOOK_ROLLBACK_CANARY_2026-04-25.md:322:- Invariant: OrderService / FrontendOrderService symmetry.
reports/runbooks/RUNBOOK_ROLLBACK_CANARY_2026-04-25.md:323:- Invariant: frozen zones require human gate clearance.

exec
/bin/zsh -lc "rg -n 'laravel/horizon|horizon' composer.json config app routes .env.example || true" in /Users/1millnonstop/Downloads/projet/foodking-web/web/testttt
 succeeded in 0ms:
app/Console/Kernel.php:36:        // node when scaled horizontally to avoid double-processing the same outbox

exec
/bin/zsh -lc "for f in reports/runbooks/RUNBOOK_*_2026-04-25.md; do printf '%s H1=' \""'$f"; rg -c '"'"'^# '"' \""'$f"; printf '"'%s Status=' \""'$f"; rg -c '"'Status: DRAFT_SKELETON_NOT_SIGNED|INDEX_STATUS: DRAFT_SKELETON' \""'$f"; printf '"'%s sections=' \""'$f"; rg -c '"'"'^## [0-8]'"\\. ' \""'$f"; done' in /Users/1millnonstop/Downloads/projet/foodking-web/web/testttt
 succeeded in 0ms:
reports/runbooks/RUNBOOK_DISPATCH_QUEUE_SATURATED_2026-04-25.md H1=1
reports/runbooks/RUNBOOK_DISPATCH_QUEUE_SATURATED_2026-04-25.md Status=1
reports/runbooks/RUNBOOK_DISPATCH_QUEUE_SATURATED_2026-04-25.md sections=8
reports/runbooks/RUNBOOK_FISCAL_SEQUENCE_BREAK_2026-04-25.md H1=1
reports/runbooks/RUNBOOK_FISCAL_SEQUENCE_BREAK_2026-04-25.md Status=1
reports/runbooks/RUNBOOK_FISCAL_SEQUENCE_BREAK_2026-04-25.md sections=8
reports/runbooks/RUNBOOK_INDEX_2026-04-25.md H1=1
reports/runbooks/RUNBOOK_INDEX_2026-04-25.md Status=1
reports/runbooks/RUNBOOK_INDEX_2026-04-25.md sections=5
reports/runbooks/RUNBOOK_KDS_MULTISCREEN_DESYNC_2026-04-25.md H1=1
reports/runbooks/RUNBOOK_KDS_MULTISCREEN_DESYNC_2026-04-25.md Status=1
reports/runbooks/RUNBOOK_KDS_MULTISCREEN_DESYNC_2026-04-25.md sections=8
reports/runbooks/RUNBOOK_KIOSK_NETWORK_LOSS_2026-04-25.md H1=1
reports/runbooks/RUNBOOK_KIOSK_NETWORK_LOSS_2026-04-25.md Status=1
reports/runbooks/RUNBOOK_KIOSK_NETWORK_LOSS_2026-04-25.md sections=8
reports/runbooks/RUNBOOK_OUTBOX_BLOCKED_2026-04-25.md H1=1
reports/runbooks/RUNBOOK_OUTBOX_BLOCKED_2026-04-25.md Status=1
reports/runbooks/RUNBOOK_OUTBOX_BLOCKED_2026-04-25.md sections=8
reports/runbooks/RUNBOOK_PRINTER_FAILURE_2026-04-25.md H1=1
reports/runbooks/RUNBOOK_PRINTER_FAILURE_2026-04-25.md Status=1
reports/runbooks/RUNBOOK_PRINTER_FAILURE_2026-04-25.md sections=8
reports/runbooks/RUNBOOK_ROLLBACK_CANARY_2026-04-25.md H1=1
reports/runbooks/RUNBOOK_ROLLBACK_CANARY_2026-04-25.md Status=1
reports/runbooks/RUNBOOK_ROLLBACK_CANARY_2026-04-25.md sections=8
reports/runbooks/RUNBOOK_TPE_FAILURE_2026-04-25.md H1=1
reports/runbooks/RUNBOOK_TPE_FAILURE_2026-04-25.md Status=1
reports/runbooks/RUNBOOK_TPE_FAILURE_2026-04-25.md sections=8

exec
/bin/zsh -lc "for f in reports/runbooks/RUNBOOK_*_2026-04-25.md; do printf '%s tables=' \""'$f"; rg -c '"'"'^'"\\| Niveau \\| Trigger \\| Rôle \\| Canal \\| Délai max \\| Action irréversible\\? \\|' \""'$f"; printf '"'%s pm=' \""'$f"; rg -c '"'"'^## 7'"\\. Template post-mortem' \""'$f"; printf '"'%s refs=' \""'$f"; rg -c '"'"'^## 8'"\\. Références' \""'$f"; done' in /Users/1millnonstop/Downloads/projet/foodking-web/web/testttt
 succeeded in 0ms:
reports/runbooks/RUNBOOK_DISPATCH_QUEUE_SATURATED_2026-04-25.md tables=1
reports/runbooks/RUNBOOK_DISPATCH_QUEUE_SATURATED_2026-04-25.md pm=1
reports/runbooks/RUNBOOK_DISPATCH_QUEUE_SATURATED_2026-04-25.md refs=1
reports/runbooks/RUNBOOK_FISCAL_SEQUENCE_BREAK_2026-04-25.md tables=1
reports/runbooks/RUNBOOK_FISCAL_SEQUENCE_BREAK_2026-04-25.md pm=1
reports/runbooks/RUNBOOK_FISCAL_SEQUENCE_BREAK_2026-04-25.md refs=1
reports/runbooks/RUNBOOK_INDEX_2026-04-25.md tables=reports/runbooks/RUNBOOK_INDEX_2026-04-25.md pm=reports/runbooks/RUNBOOK_INDEX_2026-04-25.md refs=reports/runbooks/RUNBOOK_KDS_MULTISCREEN_DESYNC_2026-04-25.md tables=1
reports/runbooks/RUNBOOK_KDS_MULTISCREEN_DESYNC_2026-04-25.md pm=1
reports/runbooks/RUNBOOK_KDS_MULTISCREEN_DESYNC_2026-04-25.md refs=1
reports/runbooks/RUNBOOK_KIOSK_NETWORK_LOSS_2026-04-25.md tables=1
reports/runbooks/RUNBOOK_KIOSK_NETWORK_LOSS_2026-04-25.md pm=1
reports/runbooks/RUNBOOK_KIOSK_NETWORK_LOSS_2026-04-25.md refs=1
reports/runbooks/RUNBOOK_OUTBOX_BLOCKED_2026-04-25.md tables=1
reports/runbooks/RUNBOOK_OUTBOX_BLOCKED_2026-04-25.md pm=1
reports/runbooks/RUNBOOK_OUTBOX_BLOCKED_2026-04-25.md refs=1
reports/runbooks/RUNBOOK_PRINTER_FAILURE_2026-04-25.md tables=1
reports/runbooks/RUNBOOK_PRINTER_FAILURE_2026-04-25.md pm=1
reports/runbooks/RUNBOOK_PRINTER_FAILURE_2026-04-25.md refs=1
reports/runbooks/RUNBOOK_ROLLBACK_CANARY_2026-04-25.md tables=1
reports/runbooks/RUNBOOK_ROLLBACK_CANARY_2026-04-25.md pm=1
reports/runbooks/RUNBOOK_ROLLBACK_CANARY_2026-04-25.md refs=1
reports/runbooks/RUNBOOK_TPE_FAILURE_2026-04-25.md tables=1
reports/runbooks/RUNBOOK_TPE_FAILURE_2026-04-25.md pm=1
reports/runbooks/RUNBOOK_TPE_FAILURE_2026-04-25.md refs=1

exec
/bin/zsh -lc "sed -n '1,220p' composer.json" in /Users/1millnonstop/Downloads/projet/foodking-web/web/testttt
 succeeded in 0ms:
{
    "name": "laravel/laravel",
    "type": "project",
    "description": "The Laravel Framework.",
    "keywords": [
        "framework",
        "laravel"
    ],
    "license": "MIT",
    "require": {
        "php": "^8.1.0",
        "ext-exif": "*",
        "ext-json": "*",
        "ext-pdo": "*",
        "aws/aws-sdk-php-laravel": "^3.9",
        "barryvdh/laravel-dompdf": "^3.0",
        "dipokhalder/laravel-env-editor": "^1.0",
        "google/apiclient": "^2.16",
        "guzzlehttp/guzzle": "^7.2",
        "laravel/framework": "^9.19",
        "laravel/sanctum": "^3.0",
        "laravel/tinker": "^2.7",
        "laravel/ui": "^4.1",
        "league/flysystem-aws-s3-v3": "^3.29",
        "maatwebsite/excel": "^3.1",
        "maennchen/zipstream-php": "^3.1.2 <3.2",
        "pragmarx/countries": "0.8.2",
        "predis/predis": "^3.4",
        "pusher/pusher-php-server": "^7.2",
        "razorpay/razorpay": "^2.8",
        "simplesoftwareio/simple-qrcode": "^4.2",
        "smartisan/laravel-settings": "^1.0",
        "spatie/laravel-medialibrary": "^10.5",
        "spatie/laravel-permission": "^5.6",
        "srmklive/paypal": "~3.0",
        "stripe/stripe-php": "^10.11",
        "twilio/sdk": "^7.0",
        "vonage/client": "^4.0"
    },
    "require-dev": {
        "barryvdh/laravel-debugbar": "^3.8",
        "beyondcode/laravel-query-detector": "^1.7",
        "fakerphp/faker": "^1.9.1",
        "laravel/pint": "^1.0",
        "mockery/mockery": "^1.4.4",
        "nunomaduro/collision": "^6.1",
        "phpunit/phpunit": "^9.5.10",
        "spatie/laravel-ignition": "^1.0"
    },
    "autoload": {
        "psr-4": {
            "App\\": "app/",
            "Database\\Factories\\": "database/factories/",
            "Database\\Seeders\\": "database/seeders/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "Tests\\": "tests/"
        }
    },
    "scripts": {
        "post-autoload-dump": [
            "Illuminate\\Foundation\\ComposerScripts::postAutoloadDump",
            "@php artisan package:discover --ansi"
        ],
        "post-update-cmd": [
            "@php artisan vendor:publish --tag=laravel-assets --ansi --force"
        ],
        "post-root-package-install": [
            "@php -r \"file_exists('.env') || copy('.env.example', '.env');\""
        ],
        "post-create-project-cmd": [
            "@php artisan key:generate --ansi"
        ],
        "invariants": "bash scripts/check-invariants.sh"
    },
    "suggest": {
        "ext-http": "PECL http — optional; not bundled with Windows PHP builds. Use Guzzle (already required) unless you rely on pecl_http APIs."
    },
    "extra": {
        "laravel": {
            "dont-discover": []
        }
    },
    "config": {
        "optimize-autoloader": true,
        "preferred-install": "dist",
        "sort-packages": true,
        "allow-plugins": {
            "pestphp/pest-plugin": true,
            "php-http/discovery": false
        }
    },
    "minimum-stability": "dev",
    "prefer-stable": true
}

exec
/bin/zsh -lc "rg -n 'Dispatch queue|Horizon|horizon' missions/CV1-M20-RUNBOOKS-SKELETON/execute_brief.md plans/PLAN_CAISSE_V1_GPT_MASTERPLAY_2026-04-25.md plans/PLAN_CAISSE_V1_SUPER_MASTER_2026-04-25.md" in /Users/1millnonstop/Downloads/projet/foodking-web/web/testttt
 succeeded in 0ms:
plans/PLAN_CAISSE_V1_GPT_MASTERPLAY_2026-04-25.md:431:**Allowlist** : `scripts/ops-preflight-caisse-v1.sh` (NEW), `app/Console/Commands/PreflightProductionCommand.php` (NEW), `config/horizon.php` (modify), dashboards configs, tests `OpsPreflightCaisseV1Test.php`, `AfterCommitDispatchTest.php`, `OutboxRescueTest.php`.
missions/CV1-M20-RUNBOOKS-SKELETON/execute_brief.md:55:### Dispatch queue saturated (RUNBOOK_DISPATCH_QUEUE_SATURATED)
missions/CV1-M20-RUNBOOKS-SKELETON/execute_brief.md:97:3. `## 1. Trigger` — conditions **observables**, machine-détectables (alertes Grafana / Horizon / log lines), avec exemples de pattern (ex : `category=queue.dispatch_domain_events.failed` → cf. `DispatchDomainEventsJob.php:216`).
missions/CV1-M20-RUNBOOKS-SKELETON/execute_brief.md:115:- **DISPATCH_QUEUE_SATURATED** : utiliser `php artisan horizon:status`, `php artisan queue:failed`, `app:preflight-production`. Différencier saturation worker vs failed jobs vs lock contention. P1 inclut scaling worker (sans toucher code).

exec
/bin/zsh -lc "rg -n 'horizon:status' ." in /Users/1millnonstop/Downloads/projet/foodking-web/web/testttt
exec
/bin/zsh -lc "rg -n 'Horizon|horizon|queue:failed|QueueFailedCommand|FailedJob' app config routes database composer.lock package.json reports plans missions | head -80" in /Users/1millnonstop/Downloads/projet/foodking-web/web/testttt
 succeeded in 0ms:
app/Console/Kernel.php:36:        // node when scaled horizontally to avoid double-processing the same outbox
reports/knowledge-transfer/05-ROADMAP.md:152:- Redis pub/sub (scale horizontal)
plans/PLAN_TASK_V1_SYNC_BACKBONE_001_2026-04-15.md:24:| `docs/PRODUCTION_SETUP.md` | New — Horizon/Supervisor/Pusher doc | Write | No | No |
plans/PLAN_TASK_V1_SYNC_BACKBONE_001_2026-04-15.md:90:- Horizon section (optional).
missions/T-NEW03-QUEUE-SCALABILITY/input.json:28:    "docs/operations/QUEUE_TOPOLOGY.md — Markdown doc: 3 queues (high, notifications, default), Supervisor command `php artisan queue:work --queue=high,notifications,default --tries=0 --sleep=1`, recommended numprocs (2 high + 2 notifications + 1 default for SMB; 4+4+2 for high-volume), monitoring snippets (queue:monitor, queue:failed), troubleshooting (queue:retry, queue:flush failed). Reference docs/QUEUE_WORKER_SETUP.md as the existing primer; this new file is the topology SSOT.",
reports/antigravity/RAPPORT_FINAL_AUDIT_CAISSE_BORNE_20260310.md:322:3. Style : barre horizontale ou cercles numérotés (comme GUR)
missions/T-AUDIT-NEW03/input.json:12:      "docs/operations/QUEUE_TOPOLOGY.md — SSOT for the 3-queue topology (high / notifications / default), Supervisor program blocks per lane (NOT a combined --queue list, to prevent FCM stalls from starving high), worker order rationale, retry policy table, monitoring snippets (queue:monitor, queue:failed, SQL pending count), troubleshooting (queue:retry, outbox:retry-failed, outbox:rescue, queue:restart, queue:flush), deployment checklist. References docs/QUEUE_WORKER_SETUP.md as the existing primer.",
plans/PLAN_CAISSE_V1_GPT_MASTERPLAY_2026-04-25.md:431:**Allowlist** : `scripts/ops-preflight-caisse-v1.sh` (NEW), `app/Console/Commands/PreflightProductionCommand.php` (NEW), `config/horizon.php` (modify), dashboards configs, tests `OpsPreflightCaisseV1Test.php`, `AfterCommitDispatchTest.php`, `OutboxRescueTest.php`.
reports/antigravity/AUDIT_MASSIF_POS_COMPARAISON_GEANTS_20260312.md:117:| Boutons larges, gauche ou haut | Swiper horizontal, catégories w-28 |
reports/antigravity/AUDIT_MASSIF_POS_COMPARAISON_GEANTS_20260312.md:119:| Toujours visibles | Scroll horizontal si > 1 écran |
missions/CV1-M20-RUNBOOKS-SKELETON/execute_brief.md:97:3. `## 1. Trigger` — conditions **observables**, machine-détectables (alertes Grafana / Horizon / log lines), avec exemples de pattern (ex : `category=queue.dispatch_domain_events.failed` → cf. `DispatchDomainEventsJob.php:216`).
missions/CV1-M20-RUNBOOKS-SKELETON/execute_brief.md:115:- **DISPATCH_QUEUE_SATURATED** : utiliser `php artisan horizon:status`, `php artisan queue:failed`, `app:preflight-production`. Différencier saturation worker vs failed jobs vs lock contention. P1 inclut scaling worker (sans toucher code).
reports/planning/KIOSK_FLOW_PLAN_CLAUDE.md:56:│  • Catégories en haut (scroll horizontal)                  │
missions/POS_V4_FINAL_AUDIT_W0_001/execute_brief.md:150:| 8 | **RTL ar** : `document.documentElement.dir = 'rtl'`, parcours vente simple | PosComponent + ItemComponent + PaymentComponent | (hors invariants métier) | Layout non cassé (pas de débordement horizontal) ; boutons d'action accessibles au Tab |
reports/planning/kimi-plans/PLAN_10_KIOSK_CONFIRMATION.md:114:                  padding: const EdgeInsets.symmetric(horizontal: 32, vertical: 12),
reports/planning/SPLASH_FOODKING_GAP_ANALYSIS_2026-03-27.md:9:- **Navigation catalogue** : conserver la **sidebar verticale + grille produits** (référence UX type McDonald’s / fast-food). Le **carrousel horizontal de catégories** type Splash n’est **pas** dans le backlog : pas de chantier associé.
reports/planning/SPLASH_FOODKING_GAP_ANALYSIS_2026-03-27.md:48:3. **Navigation catégorie carousel** (Slick) horizontal fluide — **non retenu** chez nous (préférence sidebar)
reports/planning/SPLASH_FOODKING_GAP_ANALYSIS_2026-03-27.md:92:| Carousel Slick horizontal | Sidebar vertical + grille produit | **Choix produit** : conserver la sidebar (réf. McDonald’s) — pas d’écart à combler | - | **Hors périmètre** |
reports/planning/SPLASH_FOODKING_GAP_ANALYSIS_2026-03-27.md:166:1. ~~**Carousel catégories horizontal**~~ : **exclu** — sidebar conservée (décision produit).
reports/audit-orchestration/AUDIT_FINAL_PRODUCTION_READY_V14_2026-04-20.md:221:- **Queue lag** : Horizon ou monitor custom — alerte si lag > 60 s
reports/planning/KIOSK_SPLASH_BACKLOG_DEEP_PLAN_2026-03-27.md:4:**Contexte** : suite à l’analyse `SPLASH_FOODKING_GAP_ANALYSIS_2026-03-27.md`. **Décision produit** : ne pas implémenter le carrousel horizontal de catégories ; conserver la **sidebar + grille** (référence type McDonald’s).
reports/antigravity/AUDIT_PRISE_COMMANDE_NOUS_VS_EUX_20260312.md:59:| **Eux :** Boutons gauche ou haut. « Tacos » ou « Burgers » en 1 tap. | **Nous :** Swiper horizontal. Catégories w-28. « Nos Tacos » en 1 tap. |
reports/antigravity/AUDIT_PRISE_COMMANDE_NOUS_VS_EUX_20260312.md:60:| Toujours visibles | Scroll horizontal si > 1 écran |
reports/antigravity/AUDIT_CONSOLIDE_CAISSE_BORNE_GUR_20260310.md:60:│  Barre de progression horizontale : Étape 1 → 2 → 3 → 4 → 5 → 6 → 7         │
reports/antigravity/AUDIT_CONSOLIDE_CAISSE_BORNE_GUR_20260310.md:89:| **Barre de progression** | Horizontale, numérotée (1-7), icônes par étape | Pas de breadcrumb visible "Étape X sur Y" (UX-03) |
reports/planning/AUDIT_UX_POS_CONFIGURATEUR_CAISSIER.md:41:- **Peu de regroupement horizontal:** Beaucoup de sections en pleine largeur, ce qui augmente le scroll.
reports/planning/AUDIT_UX_POS_CONFIGURATEUR_CAISSIER.md:85:  - **Option B:** Garder deux zones mais **sauce frites en ligne horizontale scrollable** (style chips), pas en grille 3 colonnes identique.
reports/planning/AUDIT_UX_POS_CONFIGURATEUR_CAISSIER.md:92:  - Remplacer les 2 grandes cartes par **2 boutons horizontaux** (style segment control) ou **2 chips** sous le titre « Type de pain », même ligne que le titre si possible.
reports/antigravity/AUDIT_ARCHITECTURE_PROFOND_20260312.md:19:| **Scalabilité** | Vertical (serveur unique) | Horizontal (20K orders/sec, <100ms) | Cloud-native | 🔴 **HIGH** — Plafond de performance |
reports/audit-orchestration/RAPPORT_FINAL_PRODUCTION_ALL_GREEN_2026-04-20.md:418:3. **Queue lag** : alerte si Horizon ou metric custom `queue:domain-events` lag > 60 s
reports/review/VERIFY_18_HIDDEN_RISKS_2026-04-20.md:21:8. **Pas de Telescope/Horizon/Ignition** exposés ; **pas de `dd()`/`dump()`** dans `app/` ; **aucun secret** crédible dans `reports/antigravity/`.
reports/review/VERIFY_18_HIDDEN_RISKS_2026-04-20.md:31:3. Pass B `explore` : `branch_id == 0`, `withoutGlobalScope*`, `demo_mode`, `dd/dump`, debug/dev routes, Telescope/Horizon, fuites secrets `reports/antigravity/`, `git log` frozen files vs `docs/gates/`, impersonate.
reports/review/VERIFY_18_HIDDEN_RISKS_2026-04-20.md:147:- **Aucun** Telescope, Horizon, `_ignition`, `/debug`, `/dev`, `/test`, `/sandbox` dans `routes/*.php`.
reports/review/VERIFY_18_HIDDEN_RISKS_2026-04-20.md:148:- `composer.json` ne contient ni `laravel/telescope` ni `laravel/horizon` → **non installés**.
reports/review/VERIFY_18_HIDDEN_RISKS_2026-04-20.md:159:### 5.4 Telescope / Horizon / Ignition
reports/runbooks/RUNBOOK_INDEX_2026-04-25.md:16:| Horizon/backlog/failed jobs, KDS/POS ne reçoivent plus events | `RUNBOOK_DISPATCH_QUEUE_SATURATED_2026-04-25.md` | DevOps | P0 |
reports/runbooks/RUNBOOK_INDEX_2026-04-25.md:28:| Dispatch queue saturated | PLAN-14, PLAN-20, M-20 | (none) | Horizon status, queue failed, dispatch latency p95 |
reports/runbooks/RUNBOOK_DISPATCH_QUEUE_SATURATED_2026-04-25.md:11:- Horizon indique workers arrêtés, saturés ou backlog sur queue `high`.
reports/runbooks/RUNBOOK_DISPATCH_QUEUE_SATURATED_2026-04-25.md:12:- Commande `queue:failed` liste `DispatchDomainEventsJob` ou voisins récurrents.
reports/runbooks/RUNBOOK_DISPATCH_QUEUE_SATURATED_2026-04-25.md:41:1. Lire état Horizon
reports/runbooks/RUNBOOK_DISPATCH_QUEUE_SATURATED_2026-04-25.md:42:   - Commande / observation: `php artisan horizon:status`
reports/runbooks/RUNBOOK_DISPATCH_QUEUE_SATURATED_2026-04-25.md:48:   - Commande / observation: `php artisan queue:failed`
reports/runbooks/RUNBOOK_DISPATCH_QUEUE_SATURATED_2026-04-25.md:78:   - Commande / observation: `php artisan queue:failed`
reports/runbooks/RUNBOOK_DISPATCH_QUEUE_SATURATED_2026-04-25.md:84:   - Commande / observation: `php artisan queue:failed`
reports/runbooks/RUNBOOK_DISPATCH_QUEUE_SATURATED_2026-04-25.md:139:  - Vérification post-action: Horizon backlog baisse et p95 revient sous seuil.
reports/execution/AUDIT_MAX_2026-04-16.md:42:| ❌ Telescope/Horizon/Sentry | — | **Non installés** (mentionnés optionnels en docs uniquement) |
reports/execution/AUDIT_MAX_2026-04-16.md:642:| Horizon | ❌ Non installé | ⚠️ queue worker basique uniquement |
reports/execution/AUDIT_MAX_2026-04-16.md:787:| P3-4 | Clean `docs/GUIDE_DEVELOPPEUR.md` (Telescope/Horizon claims) |
reports/execution/AUDIT_MAX_2026-04-16.md:803:| Queue worker mort | Moyenne | Haute (realtime dead) | `queue:monitor` | **Supervisor + Horizon ou équivalent** |
reports/execution/KIOSK-SPLASH-REBUILD-2026-03-24.md:28:| `resources/js/components/frontend/kiosk/KioskProductListComponent.vue` | Nouveau | Liste produits horizontale avec wizard overlay inline |
reports/audit/AUDIT_FINAL_GO_PROD_W1_W9_2026-04-21.md:199:| 3 | Provisionner workers (horizon ou Supervisor) : queue `high` + queue par défaut | Ops         | `php artisan queue:work --queue=high,default` |
reports/audit/AUDIT_FINAL_GO_PROD_W1_W9_2026-04-21.md:237:php artisan horizon:terminate    # ou supervisorctl restart
reports/audit/AUDIT_FINAL_GO_PROD_W1_W9_2026-04-21.md:302:php artisan horizon:terminate
reports/audit/AUDIT_FINAL_GO_PROD_W1_W9_2026-04-21.md:395:php artisan queue:failed
reports/audit/CLAUDE_SUPER_MASTER_PLAN_REVIEW_CAISSE_V1_2026-04-25.md:382:**I.1 Queue & workers** : driver vérifié (Redis/DB/SQS) chaque env ; workers ≥ N_min, supervision Horizon/Supervisor, restart auto ; retry exponential backoff, max 3, dead-letter queue ; chaos test kill worker → events réémis.
reports/audit/MEGA_AUDIT_SYSTEM_OPERATION_AGENTIC_2026-04-23.md:75:| P1-16 | `app:preflight-production` : **14** dimensions OK mais **trous** prod | Manque **Pusher**, **Redis**, **APP_URL**, disques, **Horizon**, **S3**, **TLS**, mail, cohérence **`config:cache` vs `.env`** | F08 |
reports/audit/MEGA_AUDIT_SYSTEM_OPERATION_AGENTIC_2026-04-23.md:347:**Trous P1** (audit hardening #4) à traiter en **F08** : validation explicite **Pusher**, **Redis** (au-delà du simple driver name), **`APP_URL`**, **disques** writable (`storage`, `bootstrap/cache`), **Horizon** si queues Redis actives, **S3** si driver `s3`, **TLS** termination, **mail** transport, cohérence **`php artisan config:cache`** vs valeurs **`.env`** attendues en prod.
reports/audit/CHALLENGE_CODEX_R1_2026-04-25_TRACE.md:1960:memory/episodes/03_domain_events_sync.jsonl:9:{"name": "Risques résiduels sync identifiés et mitigations", "source": "json", "source_description": "reports/audit-orchestration/RAPPORT_FINAL_PRODUCTION_ALL_GREEN_2026-04-20.md (section sync residual risks)", "episode_body": "{\"risk_R1\":{\"name\":\"Latence broadcast > 5s en pic\",\"impact\":\"KDS reçoit commande tardivement, allonge délai prep\",\"mitigation_in_place\":\"DispatchDomainEventsJob trigger immédiat via Bus::dispatch après afterCommit (pas que scheduler)\",\"residual\":\"si Pusher down, fallback poll 30s côté client (à confirmer V2)\"},\"risk_R2\":{\"name\":\"Désync horloges entre kiosk/POS et server\",\"impact\":\"timestamps incorrects sur tickets fiscaux\",\"mitigation\":\"backend always source timestamps (created_at server); frontend display only\",\"residual\":\"NF525 OK car horodatage serveur fait foi\"},\"risk_R3\":{\"name\":\"Worker queue down silencieux\",\"impact\":\"events s'accumulent en pending, KDS ne reçoit rien\",\"mitigation\":\"Horizon dashboard + alerte si pending > 100 ou > 5min\",\"residual\":\"monitoring renforcé phase 1 prod (14j)\"}}"}
reports/audit/CHALLENGE_CODEX_R1_2026-04-25_TRACE.md:1963:memory/episodes/03_domain_events_sync.jsonl:14:{"name": "Audit synchro — outils MCP / scripts pour debug", "source": "json", "source_description": "reports/audit-orchestration/REPORT_TASK16_OBSERVABILITY_K9_2026-04-20.md + reports/execution/RUN_T16B_OBSERVABILITY_2026-04-20.md", "episode_body": "{\"datadog\":\"si MCP datadog activé : query tags:foodking,branch_id:X type:broadcast pour voir latence end-to-end\",\"laravel_log_grep\":\"grep correlation_id storage/logs/laravel.log → trace bout-en-bout\",\"sentry\":\"breadcrumbs incluent correlation_id (configuré phase observability)\",\"horizon\":\"horizon dashboard /horizon → DispatchDomainEventsJob runs + failures\",\"sql_debug\":\"SELECT type,status,COUNT(*) FROM domain_events WHERE branch_id=X AND created_at > NOW()-INTERVAL 1 HOUR GROUP BY type,status\"}"}
reports/audit/SIM_MASTERPLAY_BREAKDOWN_SYNTH_V0_2026-04-25.md:3:**Horizon** : simulation `SIM-MASTERPLAY-2026-04-25` — **V0** = cartographie + risques + pistes de doublons **à valider** en Round 2 (GPT Pro) et Round 3 (synthèse).  
reports/execution/AUDIT_INTEGRATION_POS_KIOSK.md:158:| **Menu** | Grid + Catégories tabs | Grid horizontal | Kiosk: plus grand |
reports/audit/CHALLENGE_CODEX_R3_2026-04-25_TRACE.md:10991:reports/audit/CHALLENGE_CODEX_R1_2026-04-25_TRACE.md:1960:memory/episodes/03_domain_events_sync.jsonl:9:{"name": "Risques résiduels sync identifiés et mitigations", "source": "json", "source_description": "reports/audit-orchestration/RAPPORT_FINAL_PRODUCTION_ALL_GREEN_2026-04-20.md (section sync residual risks)", "episode_body": "{\"risk_R1\":{\"name\":\"Latence broadcast > 5s en pic\",\"impact\":\"KDS reçoit commande tardivement, allonge délai prep\",\"mitigation_in_place\":\"DispatchDomainEventsJob trigger immédiat via Bus::dispatch après afterCommit (pas que scheduler)\",\"residual\":\"si Pusher down, fallback poll 30s côté client (à confirmer V2)\"},\"risk_R2\":{\"name\":\"Désync horloges entre kiosk/POS et server\",\"impact\":\"timestamps incorrects sur tickets fiscaux\",\"mitigation\":\"backend always source timestamps (created_at server); frontend display only\",\"residual\":\"NF525 OK car horodatage serveur fait foi\"},\"risk_R3\":{\"name\":\"Worker queue down silencieux\",\"impact\":\"events s'accumulent en pending, KDS ne reçoit rien\",\"mitigation\":\"Horizon dashboard + alerte si pending > 100 ou > 5min\",\"residual\":\"monitoring renforcé phase 1 prod (14j)\"}}"}
reports/audit/HYPERREVIEW_CLAUDE_POS_V4_EXEC_FINAL_2026-04-26.md:146:| 8 | **RTL ar** : `document.documentElement.dir = 'rtl'`, parcours vente simple | PosComponent + ItemComponent + PaymentComponent | (hors invariants métier) | Layout non cassé (pas de débordement horizontal) ; boutons d'action accessibles au Tab |
reports/execution/REPORT_SYNC_BACKBONE_001_2026-04-15.md:38:- Added `docs/PRODUCTION_SETUP.md` with `.env` highlights, systemd `queue:work` example, Horizon notes, and diagnostic commands.
reports/execution/RUN_V1_VAGUE1_CLOSURE_2026-04-16.md:213:Deux workers Horizon tirant le même job en parallèle : la garde `dispatched_at !== null` gère le second appel. Pas de *exactly-once* strict mais *at-most-once-visible* aux clients (Pusher trigger idempotent par `broadcast_as` pour un payload identique). Acceptable V1.

 succeeded in 201ms:
exec
/bin/zsh -lc "sed -n '1,120p' reports/runbooks/RUNBOOK_DISPATCH_QUEUE_SATURATED_2026-04-25.md" in /Users/1millnonstop/Downloads/projet/foodking-web/web/testttt
exec
/bin/zsh -lc "sed -n '121,240p' reports/runbooks/RUNBOOK_DISPATCH_QUEUE_SATURATED_2026-04-25.md" in /Users/1millnonstop/Downloads/projet/foodking-web/web/testttt
 succeeded in 0ms:
# RUNBOOK — Queue dispatch saturée / events domaine (2026-04-25)

- Status: DRAFT_SKELETON_NOT_SIGNED
- Owner (DRAFT): DevOps
- Severity ceiling: P0
- Plan source: PLAN-20 (super master) / M-20 (masterplay)
- Linked gates: (none)
- Last reviewed: 2026-04-25 (initial skeleton)

## 1. Trigger
- Horizon indique workers arrêtés, saturés ou backlog sur queue `high`.
- Commande `queue:failed` liste `DispatchDomainEventsJob` ou voisins récurrents.
- Log final failure contient `[DispatchDomainEventsJob] Final failure dispatching domain event`.
- Sentry breadcrumb `category=queue.dispatch_domain_events.failed` présent.
- Dashboard sync montre p95 dispatch au-dessus de 2000 ms.
- KDS/POS ne reçoivent plus d’événements malgré transactions commitées.
- Trigger evidence 1: signal à corréler avec `app/Jobs/DispatchDomainEventsJob.php:62-89`.
- Trigger evidence 2: signal à corréler avec `app/Jobs/DispatchDomainEventsJob.php:153-161`.
- Trigger evidence 3: signal à corréler avec `app/Jobs/DispatchDomainEventsJob.php:177-208`.
- Trigger evidence 4: signal à corréler avec `app/Jobs/DispatchDomainEventsJob.php:208-220`.
- Trigger evidence 5: signal à corréler avec `app/Jobs/CleanupStalePendingKioskOrders.php:19-58`.
- Trigger evidence 6: signal à corréler avec `app/Jobs/SendFcmNotificationJob.php:63-68`.
- Trigger evidence 7: signal à corréler avec `config/queue.php:16-72`.
- Trigger evidence 8: signal à corréler avec `app/Console/Commands/PreflightProductionCommand.php:112-120`.
- Détection attendue: alerte automatique, dashboard ops, journal structuré, ou ticket L1 horodaté UTC.
- Toute alerte cross-branch doit être découpée par `branch_id` avant action.
- Aucun seuil de gate n’est approuvé dans ce runbook; les gates restent `PENDING_HUMAN_GATE` quand cités.

## 2. Symptômes utilisateur / ops
- KDS ne voit plus nouvelles commandes ou transitions.
- POS/borne semblent enregistrer mais l’état temps réel stagne.
- Ops voit backlog, failed jobs, ou latence outbox.
- Notifications FCM peuvent être lentes sans bloquer `high` si queue dédiée fonctionne.
- Plusieurs branches peuvent être touchées; scoper dashboard par `branch_id`.
- Côté ops: pic d’alertes, latence, erreurs récurrentes ou absence de progression dans le dashboard concerné.
- Côté audit: absence de preuve `branch_id` ou absence d’horodatage UTC rend la sortie non recevable.
- Côté produit: ne jamais compenser par une règle prix frontend ou un changement de statut hors `OrderStatus enum`.
- Côté coordination: si un autre agent travaille le même incident, un seul owner L2 tient le journal incident.

## 3. Diagnostic step-by-step
1. Lire état Horizon
   - Commande / observation: `php artisan horizon:status`
   - Fichier:line à inspecter: `config/queue.php:16-72`.
   - Décision de bifurcation: workers down = DevOps P0; workers up + backlog = saturation P1/P0.
   - Preuve à conserver: capture horodatée UTC + branch_id + extrait de journal sans PII.
   - Invariant à vérifier: pas de queue sync production..
2. Lister failed jobs
   - Commande / observation: `php artisan queue:failed`
   - Fichier:line à inspecter: `app/Jobs/DispatchDomainEventsJob.php:177-208`.
   - Décision de bifurcation: final failures dispatch = outbox/event issue; voisins = traiter lane spécifique.
   - Preuve à conserver: capture horodatée UTC + branch_id + extrait de journal sans PII.
   - Invariant à vérifier: dispatch after commit..
3. Vérifier idempotency claim
   - Commande / observation: Observation logs job; aucune commande dédiée.
   - Fichier:line à inspecter: `app/Jobs/DispatchDomainEventsJob.php:62-89`.
   - Décision de bifurcation: skipped concurrent worker = normal; pas incident si pas de backlog.
   - Preuve à conserver: capture horodatée UTC + branch_id + extrait de journal sans PII.
   - Invariant à vérifier: pas de double broadcast..
4. Qualifier envelope contract
   - Commande / observation: Observation logs job; aucune commande dédiée.
   - Fichier:line à inspecter: `app/Jobs/DispatchDomainEventsJob.php:153-161`.
   - Décision de bifurcation: contract violation = L2 BE; scaling worker ne corrige pas payload.
   - Preuve à conserver: capture horodatée UTC + branch_id + extrait de journal sans PII.
   - Invariant à vérifier: ne pas réécrire event..
5. Lire breadcrumb pager
   - Commande / observation: Observation Sentry/logs; aucune commande dédiée.
   - Fichier:line à inspecter: `app/Jobs/DispatchDomainEventsJob.php:208-220`.
   - Décision de bifurcation: breadcrumb failed = P1/P0 selon volume.
   - Preuve à conserver: capture horodatée UTC + branch_id + extrait de journal sans PII.
   - Invariant à vérifier: preuve non destructive..
6. Contrôler preflight queue
   - Commande / observation: `php artisan app:preflight-production`
   - Fichier:line à inspecter: `app/Console/Commands/PreflightProductionCommand.php:112-120`.
   - Décision de bifurcation: QUEUE_CONNECTION sync = CRITICAL; ne pas déployer.
   - Preuve à conserver: capture horodatée UTC + branch_id + extrait de journal sans PII.
   - Invariant à vérifier: dispatch async prod..
7. Comparer FCM voisin
   - Commande / observation: `php artisan queue:failed`
   - Fichier:line à inspecter: `app/Jobs/SendFcmNotificationJob.php:63-68`.
   - Décision de bifurcation: notifications saturées ≠ dispatch high saturé.
   - Preuve à conserver: capture horodatée UTC + branch_id + extrait de journal sans PII.
   - Invariant à vérifier: ne pas affamer high..
8. Contrôler cleanup voisin
   - Commande / observation: `php artisan queue:failed`
   - Fichier:line à inspecter: `app/Jobs/CleanupStalePendingKioskOrders.php:19-58`.
   - Décision de bifurcation: cleanup seul = incident kiosk pending, pas queue dispatch globale.
   - Preuve à conserver: capture horodatée UTC + branch_id + extrait de journal sans PII.
   - Invariant à vérifier: OrderStatus enum..
9. Lire métrique dispatch latency
   - Commande / observation: Observation dashboard sync; aucune commande dédiée.
   - Fichier:line à inspecter: `app/Services/Observability/SyncMetricsRecorder.php:29-63`.
   - Décision de bifurcation: p95 élevé = saturation ou lock contention.
   - Preuve à conserver: capture horodatée UTC + branch_id + extrait de journal sans PII.
   - Invariant à vérifier: metric non bloquante..
10. Lire overview sync
   - Commande / observation: Observation dashboard admin; aucune commande dédiée.
   - Fichier:line à inspecter: `app/Http/Controllers/Admin/Observability/SyncOverviewController.php:140-157`.
   - Décision de bifurcation: recent_failures branch-scoped orientent L2.
   - Preuve à conserver: capture horodatée UTC + branch_id + extrait de journal sans PII.
   - Invariant à vérifier: branch_id strict dashboard..
- Diagnostic stop: si une étape exige une écriture hors `reports/runbooks/`, arrêter et escalader hors M-20.
- Diagnostic stop: si une étape demande une décision fiscale, ouvrir escalade humaine, pas de correction autonome.
- Diagnostic stop: si une étape révèle un gate non signé, documenter les options, ne pas choisir l’option.

## 4. Actions correctives par criticité
### 4.1 P0 — production caissière bloquée
- Délai cible: ≤ 5 min de réponse.
- Action P0-1
  - Précondition: Queue dispatch saturée / events domaine bloque encaissement ou préparation sur une branche.
  - Action: Basculer en procédure dégradée opérationnelle autorisée, sans modifier code ni données.
  - Vérification post-action: Comparer incident et ancrage app/Jobs/DispatchDomainEventsJob.php:62-89.
  - Impact attendu: Flux caisse stabilisé ou escalade L2 active.
- Action P0-2
  - Précondition: Signal P0 confirmé par deux sources ops.
  - Action: Geler les actions irréversibles côté équipe terrain.
  - Vérification post-action: Conserver captures dashboard et journal lié à app/Jobs/DispatchDomainEventsJob.php:153-161.
  - Impact attendu: Preuve intacte pour audit et post-mortem.
- Action P0-3
  - Précondition: Risque de double traitement ou perte de commande.
  - Action: Suspendre toute relance manuelle non documentée.

 succeeded in 0ms:
  - Vérification post-action: Vérifier que les actions restent dans les invariants listés en §8.
  - Impact attendu: Pas de duplication silencieuse.
- Action P0-4
  - Précondition: Une branche unique est touchée.
  - Action: Limiter le traitement et les communications à ce `branch_id`.
  - Vérification post-action: Vérifier la présence de `branch_id` dans app/Jobs/DispatchDomainEventsJob.php:62-89.
  - Impact attendu: Isolation multi-branches préservée.
- Action P0-5
  - Précondition: Le signal persiste après 5 minutes.
  - Action: Escalader L2 puis L3 selon matrice.
  - Vérification post-action: Joindre les références app/Jobs/DispatchDomainEventsJob.php:62-89 et app/Jobs/DispatchDomainEventsJob.php:153-161.
  - Impact attendu: Décision humaine disponible si risque business.
- Sortie P0: ticket incident mis à jour avec heure UTC, `branch_id`, owner, et prochaine décision.
### 4.2 P1 — dégradé mais opérable
- Délai cible: ≤ 30 min.
- Action P1-1
  - Précondition: Backlog avec workers vivants.
  - Action: Scaler les workers ou isoler la queue selon procédure infra, sans toucher code.
  - Vérification post-action: Horizon backlog baisse et p95 revient sous seuil.
  - Impact attendu: Temps réel rétabli sans mutation métier.
- Action P1-2
  - Précondition: Queue dispatch saturée / events domaine dégrade le service sans arrêt total.
  - Action: Maintenir le service avec surveillance renforcée.
  - Vérification post-action: Comparer les symptômes au fichier app/Jobs/DispatchDomainEventsJob.php:62-89.
  - Impact attendu: Pas de passage P0 sans preuve supplémentaire.
- Action P1-3
  - Précondition: Une action temporaire non destructive existe.
  - Action: Appliquer uniquement la mesure documentée dans ce runbook.
  - Vérification post-action: Noter l’heure UTC et le `branch_id` dans le ticket.
  - Impact attendu: Réversibilité conservée.
- Action P1-4
  - Précondition: Le dashboard montre une amélioration.
  - Action: Continuer l’observation sur deux fenêtres de mesure.
  - Vérification post-action: Conserver la capture de la métrique ancrée en §8.
  - Impact attendu: Incident maîtrisé sans correction code.
- Action P1-5
  - Précondition: La cause reste incertaine.
  - Action: Préparer analyse L2, sans élargir aux modules voisins.
  - Vérification post-action: Lister uniquement les références du §8.
  - Impact attendu: Scope M-20 respecté.
- Action P1-6
  - Précondition: Le contournement dure plus de 30 minutes.
  - Action: Reclasser P0 si la caisse devient bloquée.
  - Vérification post-action: Comparer ticket et matrice d’escalade §5.
  - Impact attendu: Priorité alignée sur l’impact réel.
- Sortie P1: ticket incident mis à jour avec heure UTC, `branch_id`, owner, et prochaine décision.
### 4.3 P2 — anomalie collectée pour post-mortem
- Délai cible: ≤ 24 h.
- Action P2-1
  - Précondition: Signal isolé, sans impact utilisateur immédiat.
  - Action: Collecter preuve et laisser le service nominal.
  - Vérification post-action: Ancrer le ticket sur app/Jobs/DispatchDomainEventsJob.php:62-89.
  - Impact attendu: Dette documentée sans intervention risquée.
- Action P2-2
  - Précondition: Anomalie récupérée automatiquement.
  - Action: Marquer comme observation pour post-mortem.
  - Vérification post-action: Renseigner délai de détection dans §7.
  - Impact attendu: Amélioration de monitoring planifiée.
- Action P2-3
  - Précondition: Aucune récurrence sur 24 h.
  - Action: Clore opérationnellement après revue L1.
  - Vérification post-action: Conserver le lien vers ce runbook.
  - Impact attendu: Trace suffisante pour audit ultérieur.
- Action P2-4
  - Précondition: Récurrence faible mais visible.
  - Action: Créer action corrective P2 avec propriétaire.
  - Vérification post-action: Lier aux plans/missions du §8.
  - Impact attendu: Traitement hors urgence.
- Action P2-5
  - Précondition: Doute sur un gate ou une frozen zone.
  - Action: Ne pas intervenir; router en question humaine.
  - Vérification post-action: Référence gate en §8, aucune décision GO/NO-GO.
  - Impact attendu: Pas de self-approval.
- Sortie P2: ticket incident mis à jour avec heure UTC, `branch_id`, owner, et prochaine décision.

## 5. Escalation matrix
| Niveau | Trigger | Rôle | Canal | Délai max | Action irréversible? |
| --- | --- | --- | --- | --- | --- |
| L1 | Alerte initiale ou plainte terrain | Ops | Pager / canal incident | 5 min | Non |
| L2 | Diagnostic confirmé ou P1 > 30 min | BE/DevOps oncall | Canal incident + ticket | 15 min | Non sans accord L3 |
| L3 | P0 durable, gate, frozen zone, ou impact multi-branches | CTO / humain responsable | Appel direct + canal incident | 30 min | Oui, décision humaine requise |
| Retour ops | Sortie technique confirmée | Ops + owner incident | Ticket + rapport post-mortem | 24 h | Non |

## 6. Vérifications de sortie
- [ ] Incident horodaté UTC avec début, détection, prise en charge, mitigation et sortie.
- [ ] `branch_id` concerné documenté; si global, justification explicite par rôle Admin/global.
- [ ] Aucune écriture brute DB effectuée depuis ce runbook.
- [ ] Aucun gate marqué comme approuvé ou signé par ce runbook.
- [ ] Aucune modification de code produit pendant l’intervention ops.
- [ ] Aucune logique prix frontend introduite ou recommandée.
- [ ] `OrderStatus enum` respecté; aucun statut littéral nouveau demandé.
- [ ] `dispatch after commit` respecté; aucune relance ne contourne l’outbox.
- [ ] Captures dashboards jointes au ticket incident.
- [ ] Extraits logs limités au nécessaire, sans PII ni secrets.
- [ ] First responder et owner L2 identifiés.
- [ ] Décision P0/P1/P2 justifiée par symptômes observés.
- [ ] Actions correctives appliquées dans l’ordre du §4.
- [ ] Escalade L3/L4 déclenchée si seuil atteint.
- [ ] Monitoring revenu au niveau nominal ou contournement documenté.
- [ ] Risque fiscal explicitement qualifié si applicable.
- [ ] Lien vers plan/missions M-20 et plans transverses présent.
- [ ] Dette de suivi convertie en action P2 avec owner et deadline.
- [ ] Post-mortem créé ou planifié selon §7.
- [ ] Ticket incident fermé seulement après confirmation L1 + L2.
- [ ] Preuve 1 reliée à `app/Jobs/DispatchDomainEventsJob.php:62-89` dans le ticket ou dashboard.
- [ ] Preuve 2 reliée à `app/Jobs/DispatchDomainEventsJob.php:153-161` dans le ticket ou dashboard.
- [ ] Preuve 3 reliée à `app/Jobs/DispatchDomainEventsJob.php:177-208` dans le ticket ou dashboard.
- [ ] Preuve 4 reliée à `app/Jobs/DispatchDomainEventsJob.php:208-220` dans le ticket ou dashboard.
- [ ] Preuve 5 reliée à `app/Jobs/CleanupStalePendingKioskOrders.php:19-58` dans le ticket ou dashboard.
- [ ] Preuve 6 reliée à `app/Jobs/SendFcmNotificationJob.php:63-68` dans le ticket ou dashboard.
- [ ] Preuve 7 reliée à `config/queue.php:16-72` dans le ticket ou dashboard.
- [ ] Preuve 8 reliée à `app/Console/Commands/PreflightProductionCommand.php:112-120` dans le ticket ou dashboard.
- [ ] Preuve 9 reliée à `app/Services/Observability/SyncMetricsRecorder.php:29-63` dans le ticket ou dashboard.
- [ ] Preuve 10 reliée à `app/Http/Controllers/Admin/Observability/SyncOverviewController.php:140-157` dans le ticket ou dashboard.

## 7. Template post-mortem
- Timeline UTC
  - Champ obligatoire: heure UTC ou valeur mesurée, jamais une date relative.
  - Champ obligatoire: branches touchées, avec `branch_id` explicite ou justification globale.
  - Champ obligatoire: source de détection, automatique ou manuelle, avec délai en minutes.

exec
/bin/zsh -lc "sed -n '241,340p' reports/runbooks/RUNBOOK_DISPATCH_QUEUE_SATURATED_2026-04-25.md" in /Users/1millnonstop/Downloads/projet/foodking-web/web/testttt
 succeeded in 0ms:
  - Champ obligatoire: action prise, owner, canal, et résultat mesuré.
  - Champ obligatoire: preuve attachée, lien ticket, dashboard, journal ou capture.
- Impact (commandes, revenue, fiscal, branches)
  - Champ obligatoire: heure UTC ou valeur mesurée, jamais une date relative.
  - Champ obligatoire: branches touchées, avec `branch_id` explicite ou justification globale.
  - Champ obligatoire: source de détection, automatique ou manuelle, avec délai en minutes.
  - Champ obligatoire: action prise, owner, canal, et résultat mesuré.
  - Champ obligatoire: preuve attachée, lien ticket, dashboard, journal ou capture.
- Cause racine
  - Champ obligatoire: heure UTC ou valeur mesurée, jamais une date relative.
  - Champ obligatoire: branches touchées, avec `branch_id` explicite ou justification globale.
  - Champ obligatoire: source de détection, automatique ou manuelle, avec délai en minutes.
  - Champ obligatoire: action prise, owner, canal, et résultat mesuré.
  - Champ obligatoire: preuve attachée, lien ticket, dashboard, journal ou capture.
- Détection (auto/manuelle/délai)
  - Champ obligatoire: heure UTC ou valeur mesurée, jamais une date relative.
  - Champ obligatoire: branches touchées, avec `branch_id` explicite ou justification globale.
  - Champ obligatoire: source de détection, automatique ou manuelle, avec délai en minutes.
  - Champ obligatoire: action prise, owner, canal, et résultat mesuré.
  - Champ obligatoire: preuve attachée, lien ticket, dashboard, journal ou capture.
- Réponse (ce qui a marché / pas marché)
  - Champ obligatoire: heure UTC ou valeur mesurée, jamais une date relative.
  - Champ obligatoire: branches touchées, avec `branch_id` explicite ou justification globale.
  - Champ obligatoire: source de détection, automatique ou manuelle, avec délai en minutes.
  - Champ obligatoire: action prise, owner, canal, et résultat mesuré.
  - Champ obligatoire: preuve attachée, lien ticket, dashboard, journal ou capture.
- Actions correctives (P0/P1/P2 + propriétaire + deadline)
  - Champ obligatoire: heure UTC ou valeur mesurée, jamais une date relative.
  - Champ obligatoire: branches touchées, avec `branch_id` explicite ou justification globale.
  - Champ obligatoire: source de détection, automatique ou manuelle, avec délai en minutes.
  - Champ obligatoire: action prise, owner, canal, et résultat mesuré.
  - Champ obligatoire: preuve attachée, lien ticket, dashboard, journal ou capture.
- Liens incidents passés
  - Champ obligatoire: heure UTC ou valeur mesurée, jamais une date relative.
  - Champ obligatoire: branches touchées, avec `branch_id` explicite ou justification globale.
  - Champ obligatoire: source de détection, automatique ou manuelle, avec délai en minutes.
  - Champ obligatoire: action prise, owner, canal, et résultat mesuré.
  - Champ obligatoire: preuve attachée, lien ticket, dashboard, journal ou capture.
- Synthèse invariants
  - Backend pricing is SSOT: ne pas recalculer un montant côté frontend pendant la réponse incident.
  - OrderStatus enum authoritative: ne pas demander une correction par littéral numérique hors flux existant.
  - branch_id isolation: toute preuve, capture, dashboard ou ticket doit porter la branche concernée.
  - dispatch after commit: ne pas recommander un dispatch manuel qui contourne les garanties after-commit.
  - OrderService / FrontendOrderService symmetry: toute divergence constatée devient point M-10, pas patch ops.
  - frozen zones: aucune édition de fichier gelé depuis un runbook non signé.
- Notes spécifiques
  - Différencier saturation worker, failed jobs contract, et lock contention.

## 8. Références
- `app/Jobs/DispatchDomainEventsJob.php:62-89`
- `app/Jobs/DispatchDomainEventsJob.php:153-161`
- `app/Jobs/DispatchDomainEventsJob.php:177-208`
- `app/Jobs/DispatchDomainEventsJob.php:208-220`
- `app/Jobs/CleanupStalePendingKioskOrders.php:19-58`
- `app/Jobs/SendFcmNotificationJob.php:63-68`
- `config/queue.php:16-72`
- `app/Console/Commands/PreflightProductionCommand.php:112-120`
- `app/Services/Observability/SyncMetricsRecorder.php:29-63`
- `app/Http/Controllers/Admin/Observability/SyncOverviewController.php:140-157`
- `app/Http/Controllers/Admin/Observability/SyncOverviewController.php:173-204`
- Gate: `(none)` (PENDING_HUMAN_GATE ou à drafter; aucune approbation dans ce runbook).
- Plan: `PLAN-20` — documentation and runbook skeleton.
- Mission: `M-20` — `CV1-M20-RUNBOOKS-SKELETON`.
- Plan transverse: `PLAN-14` — ops runtime observability, si métrique ou preflight concerné.
- Plan transverse: `PLAN-15` — rollout canary rollback, si rollback ou canary concerné.
- Invariant: backend pricing SSOT.
- Invariant: `OrderStatus enum` authoritative.
- Invariant: `branch_id` isolation strict.
- Invariant: dispatch after commit.
- Invariant: OrderService / FrontendOrderService symmetry.
- Invariant: frozen zones require human gate clearance.

exec
/bin/zsh -lc 'test -f config/horizon.php; echo config_horizon=$?; test -d vendor/laravel/horizon; echo vendor_horizon=$?; test -f app/Console/Commands/HorizonStatusCommand.php; echo app_cmd=$?' in /Users/1millnonstop/Downloads/projet/foodking-web/web/testttt
 succeeded in 0ms:
config_horizon=1
vendor_horizon=1
app_cmd=1

codex
# AUTO_AUDIT_GPT — CV1-M20-RUNBOOKS-SKELETON

## 1. Conformité au plan / scope

- OK : 8 runbooks + 1 index créés sous `reports/runbooks/`, exactement dans l’allowlist M-20.
- OK : les line counts du JSON correspondent aux fichiers réels ; H1 unique, sections attendues, matrices d’escalade, post-mortem et références présents.
- OK : aucun code block Markdown détecté ; aucun marqueur de gate approuvé/signé détecté.
- RISQUE / NEEDS_FIX : `RUNBOOK_DISPATCH_QUEUE_SATURATED_2026-04-25.md` et l’index citent Horizon / `php artisan horizon:status`, mais le repo courant ne contient ni `laravel/horizon` dans `composer.json`, ni `config/horizon.php`, ni commande custom équivalente. Cela contredit l’exigence “commandes existantes uniquement / sans inventer”. Correction confinée au runbook + index : remplacer par une observation Supervisor/queue existante ou documenter explicitement comme dépendance future M-14, avec risk associé.
- Note : le worktree global contient des fichiers hors M-20 modifiés, mais le périmètre M-20 vérifié (`reports/runbooks/` + `missions/CV1-M20...`) ne montre que les artefacts mission. Je n’attribue donc pas ces modifications globales à cette proposition.

## 2. Invariants FoodKing

- pricing_ssot : OK — les runbooks rappellent que le frontend ne recalcule pas les prix ; aucun code prix modifié.
- order_status : OK — usage documentaire de `OrderStatus enum`, pas de nouvelle logique/statut métier codé.
- branch_id : OK — les procédures exigent preuves et tri par `branch_id`.
- commit_before_dispatch : OK — aucun code dispatch modifié ; les runbooks rappellent de ne pas contourner l’outbox / after-commit.
- frozen_zones : OK — pas d’édition produit/frozen dans le périmètre M-20 ; gates cités en pending/non signés.
- order_service_symmetry : N/A — aucun `OrderService` / `FrontendOrderService` modifié par M-20.

## 3. Verdict

`VERDICT: NEEDS_FIX` — Le scope fichiers est conforme, mais une commande/observabilité Horizon non installée dans le repo courant est présentée comme outil opérationnel réel. Correction locale attendue dans le runbook dispatch et l’index avant PASS.
2026-04-25T15:32:58.743013Z ERROR codex_core::session: failed to record rollout items: thread 019dc543-8af1-7151-8637-f73354a6a91c not found
tokens used
100 204
# AUTO_AUDIT_GPT — CV1-M20-RUNBOOKS-SKELETON

## 1. Conformité au plan / scope

- OK : 8 runbooks + 1 index créés sous `reports/runbooks/`, exactement dans l’allowlist M-20.
- OK : les line counts du JSON correspondent aux fichiers réels ; H1 unique, sections attendues, matrices d’escalade, post-mortem et références présents.
- OK : aucun code block Markdown détecté ; aucun marqueur de gate approuvé/signé détecté.
- RISQUE / NEEDS_FIX : `RUNBOOK_DISPATCH_QUEUE_SATURATED_2026-04-25.md` et l’index citent Horizon / `php artisan horizon:status`, mais le repo courant ne contient ni `laravel/horizon` dans `composer.json`, ni `config/horizon.php`, ni commande custom équivalente. Cela contredit l’exigence “commandes existantes uniquement / sans inventer”. Correction confinée au runbook + index : remplacer par une observation Supervisor/queue existante ou documenter explicitement comme dépendance future M-14, avec risk associé.
- Note : le worktree global contient des fichiers hors M-20 modifiés, mais le périmètre M-20 vérifié (`reports/runbooks/` + `missions/CV1-M20...`) ne montre que les artefacts mission. Je n’attribue donc pas ces modifications globales à cette proposition.

## 2. Invariants FoodKing

- pricing_ssot : OK — les runbooks rappellent que le frontend ne recalcule pas les prix ; aucun code prix modifié.
- order_status : OK — usage documentaire de `OrderStatus enum`, pas de nouvelle logique/statut métier codé.
- branch_id : OK — les procédures exigent preuves et tri par `branch_id`.
- commit_before_dispatch : OK — aucun code dispatch modifié ; les runbooks rappellent de ne pas contourner l’outbox / after-commit.
- frozen_zones : OK — pas d’édition produit/frozen dans le périmètre M-20 ; gates cités en pending/non signés.
- order_service_symmetry : N/A — aucun `OrderService` / `FrontendOrderService` modifié par M-20.

## 3. Verdict

`VERDICT: NEEDS_FIX` — Le scope fichiers est conforme, mais une commande/observabilité Horizon non installée dans le repo courant est présentée comme outil opérationnel réel. Correction locale attendue dans le runbook dispatch et l’index avant PASS.
