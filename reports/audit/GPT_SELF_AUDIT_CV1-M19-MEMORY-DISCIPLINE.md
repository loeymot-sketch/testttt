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
session id: 019dc521-900d-79d1-ac30-eba66f07f7ca
--------
user
Tu effectues l’**auto-audit GPT** (2e passe) d’un cycle FoodKing — **pas** l’audit Claude terminal.

**Contexte** : le JSON ci-dessous est la proposition d’implémentation (format agents/codex.prompt.txt) pour la mission `CV1-M19-MEMORY-DISCIPLINE`.


**JSON d’implémentation (à recouper)** :
```json
{
  "name": "caisse_v1_<topic>",
  "group_id": "foodking",
  "episode_body": "PLACEHOLDER — Compléter au CLOSE de la mission. Format attendu : 1) décisions durables (1-3 phrases), 2) fichiers principaux touchés (file:line si possible), 3) tests verts, 4) état gate, 5) symmetry note OS/FOS si applicable.",
  "source": "text",
  "source_description": "FoodKing Caisse V1 — <mission_id>",
  "reference_time": "2026-04-25T00:00:00Z",
  "metadata": {
    "task_id": "<TASK_ID>",
    "mission_id": "<M-XX>",
    "plan_file": "plans/PLAN_CAISSE_V1_GPT_MASTERPLAY_2026-04-25.md",
    "verdict": "PLACEHOLDER_FILL_AT_CLOSE",
    "gate_status": "PLACEHOLDER",
    "symmetry_note": "PLACEHOLDER"
  }
}
```

**Livrable** (Markdown uniquement, en français) :
# AUTO_AUDIT_GPT — CV1-M19-MEMORY-DISCIPLINE

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
Je vais recouper la proposition avec le plan M19 et les règles Masterplay, sans modifier le dépôt. Je lis seulement les artefacts nécessaires pour juger le scope et les invariants.
exec
/bin/zsh -lc "sed -n '1,220p' plans/masterplay/GO.md" in /Users/1millnonstop/Downloads/projet/foodking-web/web/testttt
exec
/bin/zsh -lc "sed -n '1,260p' plans/masterplay/MASTERPLAY_QUEUE.md" in /Users/1millnonstop/Downloads/projet/foodking-web/web/testttt
exec
/bin/zsh -lc "sed -n '1,220p' .cursor/ACTIVE_CYCLE.md" in /Users/1millnonstop/Downloads/projet/foodking-web/web/testttt
exec
/bin/zsh -lc "sed -n '1,240p' plans/masterplay/MASTERPLAY_DISCIPLINE.md" in /Users/1millnonstop/Downloads/projet/foodking-web/web/testttt
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
| 01 | CV1-M19-MEMORY-DISCIPLINE | M-19 | WAVE_A | — | RUNNING | Crée squelettes JSONL pour les 22 missions |
| 02 | CV1-M01-TRACEABILITY-MATRIX | M-01 | WAVE_A | — | PENDING | Matrice findings → tasks → tests → gates |
| 03 | CV1-M02-SENTINEL-BASELINE | M-02 | WAVE_A | CV1-M01 | PENDING | 18 sentinels fail-first + 4 lints |
| 04 | CV1-M12-LEGACY-GUARDS-CI | M-12 | WAVE_A | — | PENDING | Lint imports + bundle scan + workflow |
| 05 | CV1-M16-HARDWARE-LAB | M-16 | WAVE_A | — | PENDING | Checklist hardware signable |
| 06 | CV1-M18-TEST-ARCHITECTURE | M-18 | WAVE_A | CV1-M02 | PENDING | Grille couverture + plan campagne |
| 07 | CV1-M20-RUNBOOKS-SKELETON | M-20 | WAVE_A | — | PENDING | 8 runbooks ops (TPE, printer, kiosk net, dispatch, outbox, fiscal, KDS, rollback) |
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
# GO — Lance la masterplay Caisse V1

> **Intégration obligatoire confirmée (2026-04-25)** : la masterplay est désormais référencée dans :
> - `AGENTS.md` P0 + section "Caisse V1 — Masterplay loop"
> - `.cursor/rules/global.mdc` (alwaysApply) section "Caisse V1 — Masterplay loop"
> - `.cursor/commands/run-cycle.md` Step 0 item 0 (fast-path `^CV1-M…`)
> - `.cursor/ACTIVE_CYCLE.md` section "CAISSE_V1_MASTERPLAY (ACTIVE)"
>
> Tout agent qui ouvre le repo et touche un `TASK_ID` `CV1-MXX-…` est OBLIGÉ de lire `MASTERPLAY_DISCIPLINE.md` + `MASTERPLAY_QUEUE.md` avant d'agir.

## 0. Prérequis (1 fois)

```bash
# Vérifier binaire codex (CLI OpenAI, compte ChatGPT Pro)
npm run codex:doctor || npm install   # installe @openai/codex si absent

# Vérifier binaire claude (Anthropic, audit terminal)
which claude && claude --version       # doit retourner 2.x.x

# Vérifier la boucle FoodKing
npm run verify:boucle
```

Si l'un échoue : voir `AGENTS.md` § "Parcours obligatoire" et `agents/codex-extension-instructions.md`.

## 1. Démarrage propre (boucle longue)

```bash
# Boucle simple — exécute toutes les missions PENDING dans l'ordre, sans audit auto
bash scripts/run-masterplay.sh

# Boucle complète — codex exec + audit Claude terminal + audit Codex final + ingest mémoire
bash scripts/run-masterplay.sh --with-audit --with-final

# Boucle limitée (tester sur 2 missions d'abord)
bash scripts/run-masterplay.sh --with-audit --with-final --max 2
```

## 2. Suivre l'avancement

```bash
# Statut temps réel (fichier JSON mis à jour à chaque mission)
cat reports/masterplay/status.json

# Log de la boucle courante
ls -lt reports/masterplay/run_*.log | head -1
tail -f $(ls -t reports/masterplay/run_*.log | head -1)

# File d'attente (à jour)
cat plans/masterplay/MASTERPLAY_QUEUE.md
```

## 3. Pause / Stop

```bash
# Pause (rester en attente, le runner reprend dès suppression)
touch reports/masterplay/PAUSE
# Reprendre
rm reports/masterplay/PAUSE

# Stop propre (à la fin de la mission courante)
touch reports/masterplay/STOP

# Stop immédiat (Ctrl-C dans le terminal du runner)
```

## 4. Missions Vague A prêtes (no-gate, démarrent immédiatement)

| ORDER | TASK_ID | Mission | Livrables clés |
|-------|---------|---------|----------------|
| 01 | `CV1-M19-MEMORY-DISCIPLINE` | M-19 | 22 squelettes JSONL, procédure mémoire |
| 02 | `CV1-M01-TRACEABILITY-MATRIX` | M-01 | Matrice findings .md+.csv, script de check |
| 03 | `CV1-M02-SENTINEL-BASELINE` | M-02 | 18 tests sentinels rouges + 4 lints + index |
| 04 | `CV1-M12-LEGACY-GUARDS-CI` | M-12 | Workflow CI + 4 scripts lint legacy |
| 05 | `CV1-M16-HARDWARE-LAB` | M-16 | Checklist + protocoles + grille acceptation |
| 06 | `CV1-M18-TEST-ARCHITECTURE` | M-18 | Matrice couverture + plan campagne |

## 5. Pendant que ça tourne (de mon côté, la prochaine fois)

Je préparerai en parallèle :
- `CV1-M20-RUNBOOKS-SKELETON` (M-20) — squelettes runbooks ops
- `CV1-M21A-QUICKWINS-LOT0` (M-21a) — quickwins POS (discount v-model + Swiper RTL)
- `CV1-M03-GATES-DRAFT` (M-03) — briefs des 7 gates TO_DRAFT

## 6. Après ces 6 (toi + humain)

1. Audit Claude transversal :
   ```bash
   bash scripts/foodking-claude-orchestrate.sh context
   bash scripts/foodking-claude-orchestrate.sh audit
   ```
2. Signer les gates `PENDING_HUMAN_GATE` (cf. `docs/gates/GATE_LOG.md`).
3. Une fois M-03 livré, signer les 7 gates `TO_DRAFT`.
4. Débloquer la Vague B en passant manuellement les BLOCKED → PENDING au fil des signatures.

## 7. Garde-fous (rappel)

- **Allowlist stricte par mission** : codex ne touche QUE les fichiers listés.
- **Frozen zones** : intouchables sans gate signé.
- **Invariants FoodKing** : violation = REWORK auto.
- **Activity-log** : enforce les collisions (pas de double édition cross-agent).
- **Max 5 REWORK** par mission → BLOCKED + escalation humaine.

Lis `plans/masterplay/MASTERPLAY_DISCIPLINE.md` pour le détail complet.

---

**TL;DR** : `bash scripts/run-masterplay.sh --with-audit --with-final` → laisse tourner → reviens dans X heures → check `cat reports/masterplay/status.json` et `cat plans/masterplay/MASTERPLAY_QUEUE.md`.

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

codex
La file confirme que M19 est une mission Wave A en cours, avec un livrable très borné : créer des squelettes JSONL et une procédure mémoire. Je vais maintenant vérifier l’allowlist et les détails du plan parent pour savoir si le JSON fourni couvre réellement ce qui était demandé.
exec
/bin/zsh -lc "sed -n '1,240p' missions/CV1-M19-MEMORY-DISCIPLINE/input.json" in /Users/1millnonstop/Downloads/projet/foodking-web/web/testttt
exec
/bin/zsh -lc 'rg -n "M-19|CV1-M19|MEMORY-DISCIPLINE|mémoire|memory" plans/PLAN_CAISSE_V1_GPT_MASTERPLAY_2026-04-25.md plans/PLAN_CAISSE_V1_SUPER_MASTER_2026-04-25.md' in /Users/1millnonstop/Downloads/projet/foodking-web/web/testttt
 succeeded in 0ms:
{
  "task_id": "CV1-M19-MEMORY-DISCIPLINE",
  "mission_id": "M-19",
  "plan_file": "plans/PLAN_CAISSE_V1_GPT_MASTERPLAY_2026-04-25.md",
  "primary_model": "codex-extension",
  "model": "gpt-5.5-pro",
  "reasoning_effort": "xhigh",
  "objective": "Documenter la discipline mémoire Caisse V1 : Graphiti read avant chaque mission (si MCP actif), fallback memory/INDEX.md, ingest CLOSE via after-execute-memory.sh, verify via memory/verify.py. Préparer 22 squelettes JSONL d'épisodes (un par mission M-XX) prêts à remplir.",
  "instruction": "Crée la procédure + squelettes JSONL. Aucun code produit. JSON unique selon agents/codex.prompt.txt.",
  "allowlist": [
    "docs/orchestration/MEMORY_DISCIPLINE_CAISSE_V1_2026-04-25.md",
    "memory/episodes/caisse_v1_traceability_2026-04-25.jsonl",
    "memory/episodes/caisse_v1_sentinels_2026-04-25.jsonl",
    "memory/episodes/caisse_v1_legacy_guards_2026-04-25.jsonl",
    "memory/episodes/caisse_v1_hardware_lab_2026-04-25.jsonl",
    "memory/episodes/caisse_v1_test_arch_2026-04-25.jsonl",
    "memory/episodes/caisse_v1_memory_discipline_2026-04-25.jsonl",
    "memory/episodes/caisse_v1_runbooks_skeleton_2026-04-25.jsonl",
    "memory/episodes/caisse_v1_quickwins_2026-04-25.jsonl",
    "memory/episodes/caisse_v1_branch_isolation_2026-04-25.jsonl",
    "memory/episodes/caisse_v1_pos_guards_2026-04-25.jsonl",
    "memory/episodes/caisse_v1_order_quote_2026-04-25.jsonl",
    "memory/episodes/caisse_v1_payment_ledger_2026-04-25.jsonl",
    "memory/episodes/caisse_v1_payment_pilot_2026-04-25.jsonl",
    "memory/episodes/caisse_v1_fiscal_z_2026-04-25.jsonl",
    "memory/episodes/caisse_v1_kds_release_2026-04-25.jsonl",
    "memory/episodes/caisse_v1_os_fos_symmetry_2026-04-25.jsonl",
    "memory/episodes/caisse_v1_kiosk_runtime_2026-04-25.jsonl",
    "memory/episodes/caisse_v1_web_stripe_2026-04-25.jsonl",
    "memory/episodes/caisse_v1_migrations_2026-04-25.jsonl",
    "memory/episodes/caisse_v1_ops_preflight_2026-04-25.jsonl",
    "memory/episodes/caisse_v1_rollout_canary_2026-04-25.jsonl",
    "memory/episodes/caisse_v1_post_launch_2026-04-25.jsonl"
  ],
  "off_limits": ["app/**", "resources/**", "routes/**", "database/**", "tests/**", "scripts/**", "config/**", ".cursor/**", "AGENTS.md", "memory/INDEX.md"],
  "invariants_at_risk": [],
  "gate_conditions": [],
  "mandatory_tests": [
    "test -f memory/INDEX.md && echo OK"
  ],
  "self_audit_checklist": [
    "Procédure documente : Graphiti query avant PLAN, fallback memory/INDEX.md, ingest CLOSE",
    "22 squelettes JSONL créés (un par mission M-01 à M-22), format Graphiti episode_body valide",
    "Chaque squelette a placeholders {date}, {verdict}, {symmetry_note}, {gate_status}",
    "memory/INDEX.md NON modifié (off_limits)",
    "Aucun fichier produit modifié"
  ],
  "rollback": { "feature_flag": null, "max_window_days": 0, "predicates": [] },
  "graphiti_query": "Caisse V1 memory discipline graphiti fallback",
  "memory_episode_to_write_on_close": "memory/episodes/caisse_v1_memory_discipline_2026-04-25.jsonl",
  "claude_audit_prompt_id": "audit-prompt-memory"
}

 succeeded in 0ms:
plans/PLAN_CAISSE_V1_SUPER_MASTER_2026-04-25.md:15:Product code remains blocked. Work that can begin immediately is limited to no-code, test-only, documentation, traceability, gate preparation, CI/static scans, hardware preparation, memory discipline, and selected quick wins that do not touch frozen zones.
plans/PLAN_CAISSE_V1_SUPER_MASTER_2026-04-25.md:87:| PLAN-19 | MEMORY_DISCIPLINE_GRAPHITI_FALLBACK | Graphiti read/write, memory fallback | PLAN-00 | none | Claude/orchestrator | memory procedure |
plans/PLAN_CAISSE_V1_SUPER_MASTER_2026-04-25.md:147:| PLAN-19 memory discipline | orchestration docs | Graphiti/fallback proof |
plans/PLAN_CAISSE_V1_SUPER_MASTER_2026-04-25.md:343:2. Query Graphiti if available, otherwise document fallback via `memory/INDEX.md`.
plans/PLAN_CAISSE_V1_SUPER_MASTER_2026-04-25.md:425:6. PLAN-19 memory discipline.
plans/PLAN_CAISSE_V1_GPT_MASTERPLAY_2026-04-25.md:178:`M-01` matrice complète · `M-02` sentinels · `M-12` legacy guards CI · `M-16` hardware lab · `M-18` test architecture · `M-19` mémoire · `M-20` runbook squelette · `M-21a` quickwins UX (LOT-0 finitions).
plans/PLAN_CAISSE_V1_GPT_MASTERPLAY_2026-04-25.md:461:### 🟢 M-19 — `CAISSE_V1_MEMORY_DISCIPLINE_2026-04-25` (NO-GATE)
plans/PLAN_CAISSE_V1_GPT_MASTERPLAY_2026-04-25.md:463:**But** : procédure Graphiti + fallback `memory/INDEX.md` ; ingest CLOSE via `bash scripts/after-execute-memory.sh` ; verify `python3 memory/verify.py` (≥ 175).
plans/PLAN_CAISSE_V1_GPT_MASTERPLAY_2026-04-25.md:529:  "memory_episode_to_write_on_close": "memory/episodes/caisse_v1_<topic>.jsonl"
plans/PLAN_CAISSE_V1_GPT_MASTERPLAY_2026-04-25.md:587:J0 (immédiat, parallèle) : M-01, M-02, M-12, M-16, M-18, M-19, M-20, M-21a
plans/PLAN_CAISSE_V1_GPT_MASTERPLAY_2026-04-25.md:626:5. **Revue mémoire** : Graphiti facts ingestés ; `memory/episodes/caisse_v1_*.jsonl` à jour.

exec
/bin/zsh -lc 'rg --files missions/CV1-M19-MEMORY-DISCIPLINE' in /Users/1millnonstop/Downloads/projet/foodking-web/web/testttt
 succeeded in 0ms:
missions/CV1-M19-MEMORY-DISCIPLINE/execute_brief.md
missions/CV1-M19-MEMORY-DISCIPLINE/input.json

exec
/bin/zsh -lc "sed -n '1,260p' missions/CV1-M19-MEMORY-DISCIPLINE/execute_brief.md" in /Users/1millnonstop/Downloads/projet/foodking-web/web/testttt
 succeeded in 0ms:
# EXECUTE BRIEF — CV1-M19-MEMORY-DISCIPLINE (M-19)

## INVIOLABLE
1. Lis `AGENTS.md`, `missions/CV1-M19-MEMORY-DISCIPLINE/input.json`, `plans/PLAN_CAISSE_V1_GPT_MASTERPLAY_2026-04-25.md` (mission M-19), `memory/INDEX.md` (read-only), `.cursor/rules/graphiti-memory.mdc`.
2. Allowlist : 1 procédure `.md` + 22 squelettes JSONL.
3. **Tu ne modifies pas `memory/INDEX.md`** (off_limits).
4. **Pas de code, pas de script.** Documentation + squelettes JSONL.

## OBJECTIF EXACT

1. Procédure mémoire claire et unique pour Caisse V1.
2. **22 squelettes JSONL pré-remplis** (un par mission M-01..M-22), format Graphiti `episode_body`, prêts à compléter par chaque mission au CLOSE via `after-execute-memory.sh`.

## STRUCTURE — `docs/orchestration/MEMORY_DISCIPLINE_CAISSE_V1_2026-04-25.md`

```
# MEMORY DISCIPLINE — Caisse V1

## 0. Authority
- AGENTS.md § Graphiti
- .cursor/rules/graphiti-memory.mdc
- docs/orchestration/MEMORY_MATRIX.md
- memory/INDEX.md (catalogue local)

## 1. Stores autorisés
| Store | Rôle | Ecriture | Lecture |
|-------|------|----------|---------|
| A — Code | source de vérité technique | EXECUTE | tous |
| B — Graphiti + JSONL local | mémoire inter-cycles | PLAN/AUDIT/CLOSE | PLAN obligatoire |
| C — Missions | éphémère par cycle | PLAN/EXECUTE/AUDIT | mission courante |
| D — Rapports | trace forensique | EXECUTE/AUDIT | audit, replanning |

Aucun nouveau store sans `GATE_MEMORY_*`.

## 2. Workflow par mission

### Avant PLAN
1. `bash scripts/agent-activity-log.sh tail 50` (~500 tokens)
2. Si MCP Graphiti chargé : `search_memory_facts(group_ids=["foodking"], query="<topic mission>")`
3. Si Graphiti absent : lire les épisodes pertinents de `memory/INDEX.md` (~3-5 fichiers max)

### Avant EXECUTE
1. `bash scripts/agent-activity-log.sh start codex-extension <TASK_ID> execute "<files CSV>" "<note>"`
2. Vérifier exit 0 (sinon collision → halt)

### À la fin (CLOSE)
1. Compléter `memory/episodes/caisse_v1_<topic>_<date>.jsonl` (squelettes créés par cette mission M-19)
2. `bash scripts/after-execute-memory.sh` (rafraîchit manifest SHA + rappelle ingest)
3. Si Graphiti UP : `bash bin/graphiti-ingest.sh caisse_v1_<topic>` puis `python3 memory/verify.py`
4. `bash scripts/agent-activity-log.sh done codex-extension <TASK_ID> done "<résumé 1 ligne>"`

## 3. Fallback (Graphiti absent dans la session)
- Mention 1 ligne : "Graphiti non chargé : activer ~/.cursor/mcp.json"
- Continuer avec memory/INDEX.md + JSONL local
- Ne pas bloquer le PLAN/EXECUTE pour cette raison

## 4. Squelettes pré-créés (M-01..M-22)
Chaque mission a son JSONL placeholder dans memory/episodes/caisse_v1_<topic>_2026-04-25.jsonl. Format :

{"name":"caisse_v1_<topic>","group_id":"foodking","episode_body":"...","source":"text","source_description":"FoodKing Caisse V1 — <mission>","reference_time":"2026-04-25T00:00:00Z","metadata":{"task_id":"CV1-MXX-...","mission_id":"M-XX","plan_file":"plans/PLAN_CAISSE_V1_GPT_MASTERPLAY_2026-04-25.md","verdict":"PLACEHOLDER_FILL_AT_CLOSE","gate_status":"<n/a|signed|pending>","symmetry_note":"<n/a|filled|resolved>"}}

À CLOSE :
- remplacer "PLACEHOLDER_FILL_AT_CLOSE" par PASS|REWORK|BLOCKED
- remplir episode_body avec : décisions durables, fichiers touchés, tests verts, gate signé

## 5. Anti-patterns
- ❌ Réécrire memory/INDEX.md à chaque mission (gate)
- ❌ Créer un nouveau store hors stores autorisés
- ❌ Skipper l'activity-log start (collisions silencieuses)
- ❌ Ingest sans verify (compteurs faux)
```

## SQUELETTES JSONL — 22 fichiers

Pour chaque fichier `memory/episodes/caisse_v1_<topic>_2026-04-25.jsonl` listé en allowlist, contenu = **une seule ligne JSON** :

```json
{"name":"caisse_v1_<topic>","group_id":"foodking","episode_body":"PLACEHOLDER — Compléter au CLOSE de la mission. Format attendu : 1) décisions durables (1-3 phrases), 2) fichiers principaux touchés (file:line si possible), 3) tests verts, 4) état gate, 5) symmetry note OS/FOS si applicable.","source":"text","source_description":"FoodKing Caisse V1 — <mission_id>","reference_time":"2026-04-25T00:00:00Z","metadata":{"task_id":"<TASK_ID>","mission_id":"<M-XX>","plan_file":"plans/PLAN_CAISSE_V1_GPT_MASTERPLAY_2026-04-25.md","verdict":"PLACEHOLDER_FILL_AT_CLOSE","gate_status":"PLACEHOLDER","symmetry_note":"PLACEHOLDER"}}
```

Mapping topic ↔ mission ↔ TASK_ID :

| Fichier JSONL | mission_id | task_id |
|---------------|------------|---------|
| caisse_v1_traceability_2026-04-25.jsonl | M-01 | CV1-M01-TRACEABILITY-MATRIX |
| caisse_v1_sentinels_2026-04-25.jsonl | M-02 | CV1-M02-SENTINEL-BASELINE |
| caisse_v1_legacy_guards_2026-04-25.jsonl | M-12 | CV1-M12-LEGACY-GUARDS-CI |
| caisse_v1_hardware_lab_2026-04-25.jsonl | M-16 | CV1-M16-HARDWARE-LAB |
| caisse_v1_test_arch_2026-04-25.jsonl | M-18 | CV1-M18-TEST-ARCHITECTURE |
| caisse_v1_memory_discipline_2026-04-25.jsonl | M-19 | CV1-M19-MEMORY-DISCIPLINE |
| caisse_v1_runbooks_skeleton_2026-04-25.jsonl | M-20 | CV1-M20-RUNBOOKS-SKELETON |
| caisse_v1_quickwins_2026-04-25.jsonl | M-21a | CV1-M21A-QUICKWINS-LOT0 |
| caisse_v1_branch_isolation_2026-04-25.jsonl | M-09 | CV1-M09-BRANCH-ISOLATION |
| caisse_v1_pos_guards_2026-04-25.jsonl | M-06 | CV1-M06-POS-REVENUE-GUARDS |
| caisse_v1_order_quote_2026-04-25.jsonl | M-05 | CV1-M05-ORDER-QUOTE |
| caisse_v1_payment_ledger_2026-04-25.jsonl | M-04A | CV1-M04A-PAYMENT-LEDGER-FULL |
| caisse_v1_payment_pilot_2026-04-25.jsonl | M-04B | CV1-M04B-PAYMENT-PILOT-RESTRICT |
| caisse_v1_fiscal_z_2026-04-25.jsonl | M-08 | CV1-M08-FISCAL-Z-NF525 |
| caisse_v1_kds_release_2026-04-25.jsonl | M-07 | CV1-M07-KDS-RELEASE |
| caisse_v1_os_fos_symmetry_2026-04-25.jsonl | M-10 | CV1-M10-OS-FOS-SYMMETRY |
| caisse_v1_kiosk_runtime_2026-04-25.jsonl | M-11 | CV1-M11-KIOSK-RUNTIME |
| caisse_v1_web_stripe_2026-04-25.jsonl | M-17 | CV1-M17-WEB-STRIPE-SCOPE |
| caisse_v1_migrations_2026-04-25.jsonl | M-13 | CV1-M13-MIGRATIONS-SAFETY |
| caisse_v1_ops_preflight_2026-04-25.jsonl | M-14 | CV1-M14-OPS-PREFLIGHT |
| caisse_v1_rollout_canary_2026-04-25.jsonl | M-15 | CV1-M15-ROLLOUT-CANARY |
| caisse_v1_post_launch_2026-04-25.jsonl | M-22 | CV1-M22-POST-LAUNCH-OBSERVABILITY |

## RÈGLES

- JSONL = **une ligne par fichier**, pas multiline.
- JSON valide (échappement quotes dans episode_body).
- `group_id: "foodking"` obligatoire (cf. graphiti-memory.mdc).
- `reference_time` ISO 8601 UTC.

## INTERDITS

- Modifier `memory/INDEX.md`.
- Modifier `bin/graphiti-ingest.sh`, `memory/verify.py`, `memory/ingest.py`.
- Inventer une mission qui n'est pas dans le masterplay.

## SI BLOCAGE

- Si Graphiti est absent et tu ne peux pas valider le format → écris quand même, marque dans `notes`.

codex
Le brief M19 demande deux livrables distincts : un document de procédure et 22 fichiers JSONL à une ligne, chacun avec le mapping réel mission/TASK_ID. Le JSON audité ressemble au contenu générique d’un seul squelette, pas à une proposition complète de livraison.
exec
/bin/zsh -lc "sed -n '70,100p' plans/PLAN_CAISSE_V1_SUPER_MASTER_2026-04-25.md" in /Users/1millnonstop/Downloads/projet/foodking-web/web/testttt
exec
/bin/zsh -lc "sed -n '450,540p' plans/PLAN_CAISSE_V1_GPT_MASTERPLAY_2026-04-25.md" in /Users/1millnonstop/Downloads/projet/foodking-web/web/testttt
 succeeded in 0ms:
| PLAN-03 | HUMAN_GATES_RESOLUTION | Sign 10 gates | PLAN-00, PLAN-02 | all gates | Human | `docs/gates/GATE_*.md` |
| PLAN-04A | PAYMENT_LEDGER_FULL | Ledger + state machine | PLAN-03 | ledger=A, schema, frozen | Codex | ledger implementation plan |
| PLAN-04B | PAYMENT_RESTRICT_PILOT | Restricted V1 payment pilot | PLAN-03 | ledger=B | Codex | restrictions + backend guards |
| PLAN-05 | ORDER_QUOTE_BACKEND_SSOT | signed quote, TTL, replay defense | PLAN-02, PLAN-03 | schema | Codex | quote implementation |
| PLAN-06 | POS_REVENUE_GUARDS | payment-confirm, cash route, cleanup race, no-op side effects | PLAN-02, PLAN-03 | frozen, prop mutation | Codex | P0 POS/payment fixes |
| PLAN-07 | KDS_RELEASE_AND_TRANSITIONS | release predicate, whitelist, expected_status, overflow | PLAN-02, PLAN-03 | KDS bump | Codex | KDS safe transitions |
| PLAN-08 | FISCAL_Z_RECONCILIATION | fiscal policy, Z, refunds, voids, HMAC | PLAN-03 | fiscal, schema | Codex + QA NF525 | fiscal proof |
| PLAN-09 | BRANCH_ISOLATION_HARDENING | branch isolation across 7+ surfaces | PLAN-02, PLAN-03 | frozen | Codex | branch isolation fixes/tests |
| PLAN-10 | OS_FOS_SYMMETRY_AND_CONTRACTS | OrderService / FrontendOrderService parity | PLAN-06, PLAN-09 | frozen signed | Codex + Claude audit | symmetry report/tests |
| PLAN-11 | KIOSK_RUNTIME_OFFLINE_POLICY | kiosk offline, enum, menu, machine, admin PIN | PLAN-03 | offline, fiscal | Codex | kiosk runtime safe |
| PLAN-12 | LEGACY_CUTOVER_AND_GUARDS | archive markings, CI lint, bundle/route guards | PLAN-00 | none | Codex + DevOps | CI/static guards |
| PLAN-13 | MIGRATION_DATA_SAFETY | dry-run, rehearsal, backups, rollback | PLAN-03 | schema | Codex + DBA | migration runbooks |
| PLAN-14 | OPS_RUNTIME_OBSERVABILITY | queue, workers, scheduler, broadcast, cache, outbox | PLAN-13 | none | DevOps | ops preflight |
| PLAN-15 | ROLLOUT_CANARY_ROLLBACK | feature flags, canary, rollback predicates | PLAN-04, PLAN-08 | none | DevOps + BE | rollout runbook |
| PLAN-16 | HARDWARE_QUALIFICATION | TPE, printer, drawer, kiosk, scanner | PLAN-00 | none | Ops/human | hardware report |
| PLAN-17 | STRIPE_AND_WEB_PAYMENT_GATE | Stripe cents, signed web payment, or disable | PLAN-03 | web payment, Stripe active | Codex | web/Stripe decision |
| PLAN-18 | TEST_ARCHITECTURE_AND_COVERAGE | test coverage matrix and campaign | PLAN-02 | none | QA | coverage report |
| PLAN-19 | MEMORY_DISCIPLINE_GRAPHITI_FALLBACK | Graphiti read/write, memory fallback | PLAN-00 | none | Claude/orchestrator | memory procedure |
| PLAN-20 | DOCUMENTATION_AND_RUNBOOK | ORDER_FLOW, BUSINESS_RULES, runbooks | PLAN-04..PLAN-08 | none | Tech writer + Claude | docs/runbooks |
| PLAN-21 | UX_FINITIONS_POS_KDS_KIOSK | discount v-model, RTL, i18n, focustrap, locale | PLAN-00 | prop mutation only for payment component | FE/Codex | UX finish tests |
| PLAN-22 | POST_LAUNCH_OBSERVABILITY_AND_ANOMALY | anomaly detection and post-launch cadence | PLAN-14, PLAN-15 | none | DevOps + QA | dashboards/on-call |

## 5. Dependency Graph

```text
PLAN-00
  -> PLAN-01
  -> PLAN-02
  -> PLAN-03
  -> PLAN-19
  -> PLAN-12

 succeeded in 0ms:

**But** : selon gate, désactiver chemins publics (`/payment/{order}/pay` raw id) **ou** sécuriser via `PaymentIntent` signé + Stripe cents fix.

---

### 🟢 M-18 — `CAISSE_V1_TEST_ARCHITECTURE_2026-04-25` (NO-GATE)

**But** : grille de couverture POS/Kiosk/KDS (PHPUnit/Vitest/Playwright/charge) ; cibles minimales POS 80%, KDS 80%, Kiosk 70%.

---

### 🟢 M-19 — `CAISSE_V1_MEMORY_DISCIPLINE_2026-04-25` (NO-GATE)

**But** : procédure Graphiti + fallback `memory/INDEX.md` ; ingest CLOSE via `bash scripts/after-execute-memory.sh` ; verify `python3 memory/verify.py` (≥ 175).

---

### 🟢 M-20 — `CAISSE_V1_RUNBOOKS_SKELETON_2026-04-25` (NO-GATE)

**But** : squelette `docs/runbooks/CAISSE_V1_*` (ORDER_FLOW, BUSINESS_RULES, AUTHZ_MATRIX). Pas de contenu inventé — pointeurs vers code/services.

---

### 🟢 M-21a — *quickwins LOT-0* (NO-GATE)

Rebadge de `POS_KDS_FINITIONS_LOT0_QUICKWINS_2026-04-26` :
- **FIND-01** : `v-model="discountReason"` à ajouter dans `PosComponent.vue` (cf. §2.7 — actuellement absent du template).
- **FIND-09** : `<Swiper :dir="swiperDir">` dans `KitchenDisplaySystemComponent.vue:130`.

**Exécutant PRIMARY** : `codex-extension` (alignement `AGENTS.md` finishing cycles).

---

### 🟠 M-21b — *finitions UX restantes* (mix gate / no-gate)

Mappe `LOT-2`, `LOT-5a`, `LOT-3`, `LOT-7`, `LOT-8` du master finitions. Détail : `plans/PLAN_MASTER_FINITIONS_POS_KDS_AVANT_LANCEMENT_2026-04-26.md`.

---

### 🟠 M-22 — `CAISSE_V1_POST_LAUNCH_OBSERVABILITY_2026-04-25` (NO-GATE après M-15)

**But** : KPI LCP POS/kiosk/KDS, anomaly rules (payment-confirm sans ability, branch crossover, no-op double-trigger, Z mismatch, sceau invalid), cadence post-mortem J+1 / J+7 / J+30.

---

## 5. Template `missions/<TASK_ID>/input.json` — *à coller pour CHAQUE M-XX*

```json
{
  "task_id": "<TASK_ID>",
  "plan_file": "plans/PLAN_CAISSE_V1_GPT_MASTERPLAY_2026-04-25.md",
  "mission_id": "<M-XX>",
  "primary_model": "codex-extension",
  "model": "gpt-5.5-pro",
  "reasoning_effort": "xhigh",
  "objective": "<copier l'OBJECTIF de M-XX>",
  "subsystems_touched": [
    {"path": "<file>", "intent": "read|write", "branch_scoped": true, "dispatch_after_commit": true}
  ],
  "subsystems_off_limits": ["<paths frozen sans gate>"],
  "invariants_at_risk": ["pricing-ssot", "order-status-enum", "branch-id-isolation", "dispatch-after-commit", "os-fos-symmetry", "frozen-zones"],
  "gate_conditions": ["GATE_*"],
  "mandatory_tests": [
    "php artisan test --filter=<TestName>",
    "npx vitest run tests/js/<spec>.spec.js"
  ],
  "self_audit_checklist": [
    "0 file outside allowlist touched",
    "0 invariant violated (LIKE on branch_id, status literal, dispatch in tx)",
    "SYMMETRY_NOTE filled if OS/FOS touched",
    "all mandatory_tests green",
    "diff minimal — no opportunistic refactor"
  ],
  "rollback": {
    "feature_flag": "<flag>",
    "max_window_days": 7,
    "predicates": ["payment_success_rate < 95% / 5min", "fiscal_anomaly > 0"]
  },
  "graphiti_query": "<topic>",
  "memory_episode_to_write_on_close": "memory/episodes/caisse_v1_<topic>.jsonl"
}
```

---

## 6. Template `missions/<TASK_ID>/execute_brief.md` — *prompt-discipline GPT*

```
Tu es l'exécuteur FoodKing pour {TASK_ID} ({M-XX} du PLAN_CAISSE_V1_GPT_MASTERPLAY_2026-04-25.md).

[INVIOLABLE]

exec
/bin/zsh -lc "sed -n '130,155p' plans/PLAN_CAISSE_V1_SUPER_MASTER_2026-04-25.md" in /Users/1millnonstop/Downloads/projet/foodking-web/web/testttt
 succeeded in 0ms:
PLAN-14 + PLAN-15 + PLAN-16 + PLAN-18
  -> PLAN-22
  -> GO/NO-GO
```

## 6. Immediate Parallel Work Before Gates

These can proceed without product code changes in frozen zones:

| Work | Type | Output |
| --- | --- | --- |
| PLAN-01 traceability matrix | no-code | finding/task/test/gate matrix |
| PLAN-02 sentinel skeletons | test-only | red/green evidence baseline |
| PLAN-03 gate dossiers | docs/human | 10 gate files ready for signature |
| PLAN-12 legacy guard design | CI/static design | lint/bundle scan plan |
| PLAN-16 hardware prep | ops/human | hardware checklist and lab booking |
| PLAN-18 coverage architecture | QA docs | test matrix |
| PLAN-19 memory discipline | orchestration docs | Graphiti/fallback proof |
| PLAN-20 runbook skeleton | docs | runbook table of contents |
| PLAN-21 LOT-0 quick wins | limited code only if non-frozen and separately planned | discount v-model/RTL tests |

No frozen product change is allowed through this list.

## 7. Critical Plan Details

### PLAN-01 — Traceability Matrix

exec
/bin/zsh -lc "sed -n '1,220p' .cursor/rules/graphiti-memory.mdc" in /Users/1millnonstop/Downloads/projet/foodking-web/web/testttt
 succeeded in 0ms:
---
description: Graphiti MCP — présence obligatoire en session quand le serveur est chargé ; requêtes automatiques au début et fin des tâches non triviales.
globs: ["**/*"]
alwaysApply: true
---

# Graphiti — mémoire projet (toutes sessions, tous modèles)

## Prérequis humain (une fois par machine Cursor)

Le dépôt **ne peut pas** enregistrer automatiquement un MCP dans Cursor : le serveur Graphiti doit être déclaré dans **`~/.cursor/mcp.json`** (ou équivalent Settings → MCP). Modèle recommandé : copier le bloc `graphiti` depuis **`.cursor/mcp/graphiti.json.example`** et fusionner avec les autres serveurs MCP déjà présents. Dépannage : **`.cursor/mcp/GRAPHITI_TROUBLESHOOTING.md`**.

**Group ID obligatoire** : `foodking` sur toutes les requêtes d’écriture et de lecture.

## Comportement agent (automatique dès que Graphiti est disponible)

1. **Au début de toute tâche non triviale** (plan, audit, sync, fiscal, pricing, multi-surface) : appeler **au moins une** requête Graphiti avant d’écrire du code ou un plan long :
   - `search_memory_facts` avec une requête en langage naturel ciblant le domaine (ex. sync, NF525, kiosk, POS).
   - Optionnel : `search_memory_nodes` pour invariants / frozen zones.
2. **Après une décision durable** (ADR, gate résolu, invariant clarifié, fin de cycle AUDIT/CLOSE) : si l’outil `add_memory` est disponible, enregistrer un épisode court (nom + `episode_body` + `group_id="foodking"`). **Ne pas omettre** : une mémoire non écrite = prochain agent **aveugle** sur la décision.
3. **Évolution du projet (nombreux cycles / nouveaux exécuteurs)** : toute vérité nouvelle qui doit survivre aux sessions doit finir soit dans **`memory/episodes/*.jsonl`** + `bash bin/graphiti-ingest.sh` (fichier ciblé), soit dans `add_memory` immédiat. Voir **`docs/orchestration/GLOBAL_SYSTEM_PRIMER.md` §4.2** (checklist « ne pas oublier »).
4. **Si Graphiti n’est pas dans la liste des MCPs de la session** : ne pas bloquer — indiquer une seule ligne « Graphiti non chargé : activer `~/.cursor/mcp.json` » puis continuer avec README / `memory/INDEX.md` en secours.

## Index local (secours sans MCP)

`memory/INDEX.md` décrit les 14 fichiers JSONL sous `memory/episodes/`. En absence de MCP, lire au moins les épisodes du domaine concerné avant d’implémenter.

## Outils MCP attendus (noms stables)

- `search_memory_facts`, `search_memory_nodes`, `get_episodes`, `add_memory` (lecture/écriture selon politique AGENTS.md)
- `clear_graph` : **uniquement** sur instruction humaine explicite (jamais autonome)

codex
# AUTO_AUDIT_GPT — CV1-M19-MEMORY-DISCIPLINE

## 1. Conformité au plan / scope

- Le JSON fourni n’est pas le JSON d’implémentation attendu : il manque `files_to_modify`, `implementation_steps`, `code_blocks`, `risks`, `notes`, `execution_trace.delegation` et `invariants_considered`.
- Il correspond seulement au contenu générique d’un squelette JSONL, pas à la livraison M-19 complète.
- Manques bloquants : procédure `docs/orchestration/MEMORY_DISCIPLINE_CAISSE_V1_2026-04-25.md` absente du JSON, 22 squelettes JSONL non démontrés, mapping réel `topic ↔ mission_id ↔ TASK_ID` non rempli.
- Les placeholders `<TASK_ID>`, `<M-XX>`, `<mission_id>` restent génériques alors que les fichiers doivent être pré-remplis mission par mission.
- Test obligatoire non reporté : `test -f memory/INDEX.md && echo OK`.
- Pas de dérive de scope visible, mais conformité allowlist non vérifiable car aucun chemin de fichier n’est déclaré.

## 2. Invariants FoodKing

- pricing_ssot : N/A — aucun prix ni logique frontend/backend de pricing.
- order_status : N/A — aucun statut de commande manipulé.
- branch_id : N/A — aucune donnée métier branch-scoped ; `group_id: "foodking"` concerne Graphiti, pas `branch_id`.
- commit_before_dispatch : N/A — aucun job/event/transaction DB.
- frozen_zones : RISQUE — aucune modification frozen visible, mais le JSON ne fournit pas les chemins modifiés donc l’allowlist/off_limits n’est pas prouvée.
- order_service_symmetry : N/A — `OrderService` / `FrontendOrderService` non touchés.

## 3. Verdict

`VERDICT: NEEDS_FIX` — La proposition est incomplète pour M-19 : elle doit livrer la procédure mémoire, les 22 JSONL mappés et la trace d’exécution au format attendu. Pas d’escalade à ce stade, car aucune modification hors scope ou frozen zone n’est démontrée.
2026-04-25T14:54:32.210390Z ERROR codex_core::session: failed to record rollout items: thread 019dc521-900d-79d1-ac30-eba66f07f7ca not found
tokens used
43 655
# AUTO_AUDIT_GPT — CV1-M19-MEMORY-DISCIPLINE

## 1. Conformité au plan / scope

- Le JSON fourni n’est pas le JSON d’implémentation attendu : il manque `files_to_modify`, `implementation_steps`, `code_blocks`, `risks`, `notes`, `execution_trace.delegation` et `invariants_considered`.
- Il correspond seulement au contenu générique d’un squelette JSONL, pas à la livraison M-19 complète.
- Manques bloquants : procédure `docs/orchestration/MEMORY_DISCIPLINE_CAISSE_V1_2026-04-25.md` absente du JSON, 22 squelettes JSONL non démontrés, mapping réel `topic ↔ mission_id ↔ TASK_ID` non rempli.
- Les placeholders `<TASK_ID>`, `<M-XX>`, `<mission_id>` restent génériques alors que les fichiers doivent être pré-remplis mission par mission.
- Test obligatoire non reporté : `test -f memory/INDEX.md && echo OK`.
- Pas de dérive de scope visible, mais conformité allowlist non vérifiable car aucun chemin de fichier n’est déclaré.

## 2. Invariants FoodKing

- pricing_ssot : N/A — aucun prix ni logique frontend/backend de pricing.
- order_status : N/A — aucun statut de commande manipulé.
- branch_id : N/A — aucune donnée métier branch-scoped ; `group_id: "foodking"` concerne Graphiti, pas `branch_id`.
- commit_before_dispatch : N/A — aucun job/event/transaction DB.
- frozen_zones : RISQUE — aucune modification frozen visible, mais le JSON ne fournit pas les chemins modifiés donc l’allowlist/off_limits n’est pas prouvée.
- order_service_symmetry : N/A — `OrderService` / `FrontendOrderService` non touchés.

## 3. Verdict

`VERDICT: NEEDS_FIX` — La proposition est incomplète pour M-19 : elle doit livrer la procédure mémoire, les 22 JSONL mappés et la trace d’exécution au format attendu. Pas d’escalade à ce stade, car aucune modification hors scope ou frozen zone n’est démontrée.
