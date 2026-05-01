# MEMORY DISCIPLINE — Caisse V1

## 0. Authority

- `AGENTS.md` section Graphiti / MCP.
- `.cursor/rules/graphiti-memory.mdc`.
- `docs/orchestration/MEMORY_MATRIX.md`.
- `memory/INDEX.md` comme catalogue local de secours.
- `plans/PLAN_CAISSE_V1_GPT_MASTERPLAY_2026-04-25.md` pour le perimetre Caisse V1.

Cette procedure ne cree pas un nouveau store. Elle applique les stores autorises par `MEMORY_MATRIX.md` pour la phase Caisse V1.

## 1. Stores autorises

| Store | Role | Ecriture | Lecture |
|-------|------|----------|---------|
| A — Code | Source de verite technique | EXECUTE | Tous |
| B — Graphiti + JSONL local | Memoire inter-cycles | AUDIT / CLOSE pour faits durables | PLAN obligatoire |
| C — Missions | Contexte ephemere par cycle | PLAN / EXECUTE / AUDIT | Mission courante |
| D — Rapports | Trace forensique | EXECUTE / AUDIT / CLOSE | Audit, replanning |

Aucun nouveau store sans gate `GATE_MEMORY_*`.

## 2. Workflow par mission

### Avant PLAN

1. Lire le journal d'activite recent :
   ```bash
   bash scripts/agent-activity-log.sh tail 50
   ```
2. Si le MCP Graphiti est charge, appeler au moins une requete ciblee :
   ```text
   search_memory_facts(group_ids=["foodking"], query="<topic mission>")
   ```
3. Si Graphiti est absent, lire `memory/INDEX.md`, puis 3 a 5 fichiers JSONL locaux maximum sous `memory/episodes/` selon le domaine de la mission.
4. Ne pas bloquer PLAN / EXECUTE pour absence Graphiti : noter seulement que le MCP doit etre active via `~/.cursor/mcp.json`.

### Avant EXECUTE

1. Reserver le scope exact :
   ```bash
   bash scripts/agent-activity-log.sh start codex-extension <TASK_ID> execute "<files CSV>" "<note>"
   ```
2. Verifier que la commande retourne `0`.
3. Si la commande retourne `2`, collision detectee : halt, coordination humaine ou reprise par le runner masterplay.

### A la fin de la mission (CLOSE)

1. Completer le squelette `memory/episodes/caisse_v1_<topic>_2026-04-25.jsonl` correspondant a la mission.
2. Remplacer `PLACEHOLDER_FILL_AT_CLOSE` par `PASS`, `REWORK` ou `BLOCKED`.
3. Remplacer les placeholders de date, gate et symmetry note.
4. Renseigner `episode_body` avec :
   - decisions durables en 1 a 3 phrases ;
   - fichiers principaux touches, avec `file:line` si possible ;
   - tests verts ou validations executees ;
   - etat gate ;
   - note de symetrie OrderService / FrontendOrderService si applicable.
5. Rafraichir la memoire locale :
   ```bash
   bash scripts/after-execute-memory.sh
   ```
6. Si Graphiti est disponible, ingerer le domaine puis verifier :
   ```bash
   bash bin/graphiti-ingest.sh caisse_v1_<topic>
   python3 memory/verify.py
   ```
7. Liberer l'activite :
   ```bash
   bash scripts/agent-activity-log.sh done codex-extension <TASK_ID> done "<resume 1 ligne>"
   ```

Le seuil de verification attendu pour Caisse V1 reste celui du plan actif : `python3 memory/verify.py` doit confirmer une memoire coherente, avec compteur au moins egal au seuil planifie lorsque le cycle le demande.

## 3. Fallback Graphiti

Si Graphiti n'est pas disponible dans la session :

- noter une seule ligne : `Graphiti non charge : activer ~/.cursor/mcp.json` ;
- continuer avec `memory/INDEX.md` et les JSONL locaux pertinents ;
- ne pas modifier `memory/INDEX.md` ;
- ne pas ingester sans verification locale lorsque les outils sont disponibles.

## 4. Squelettes pre-crees

Chaque fichier `memory/episodes/caisse_v1_<topic>_2026-04-25.jsonl` contient une seule ligne JSON valide, prete a completer au CLOSE.

Format de base :

```json
{"name":"caisse_v1_<topic>","group_id":"foodking","episode_body":"PLACEHOLDER - Completer au CLOSE de la mission. Format attendu : 1) decisions durables (1-3 phrases), 2) fichiers principaux touches (file:line si possible), 3) tests verts, 4) etat gate, 5) symmetry note OS/FOS si applicable, 6) date CLOSE.","source":"text","source_description":"FoodKing Caisse V1 - <mission_id>","reference_time":"2026-04-25T00:00:00Z","metadata":{"task_id":"<TASK_ID>","mission_id":"<M-XX>","plan_file":"plans/PLAN_CAISSE_V1_GPT_MASTERPLAY_2026-04-25.md","verdict":"PLACEHOLDER_FILL_AT_CLOSE","gate_status":"PLACEHOLDER","symmetry_note":"PLACEHOLDER","close_date":"PLACEHOLDER_DATE_AT_CLOSE"}}
```

Mapping cree par M-19 :

| Fichier JSONL | mission_id | task_id |
|---------------|------------|---------|
| `caisse_v1_traceability_2026-04-25.jsonl` | M-01 | `CV1-M01-TRACEABILITY-MATRIX` |
| `caisse_v1_sentinels_2026-04-25.jsonl` | M-02 | `CV1-M02-SENTINEL-BASELINE` |
| `caisse_v1_legacy_guards_2026-04-25.jsonl` | M-12 | `CV1-M12-LEGACY-GUARDS-CI` |
| `caisse_v1_hardware_lab_2026-04-25.jsonl` | M-16 | `CV1-M16-HARDWARE-LAB` |
| `caisse_v1_test_arch_2026-04-25.jsonl` | M-18 | `CV1-M18-TEST-ARCHITECTURE` |
| `caisse_v1_memory_discipline_2026-04-25.jsonl` | M-19 | `CV1-M19-MEMORY-DISCIPLINE` |
| `caisse_v1_runbooks_skeleton_2026-04-25.jsonl` | M-20 | `CV1-M20-RUNBOOKS-SKELETON` |
| `caisse_v1_quickwins_2026-04-25.jsonl` | M-21a | `CV1-M21A-QUICKWINS-LOT0` |
| `caisse_v1_branch_isolation_2026-04-25.jsonl` | M-09 | `CV1-M09-BRANCH-ISOLATION` |
| `caisse_v1_pos_guards_2026-04-25.jsonl` | M-06 | `CV1-M06-POS-REVENUE-GUARDS` |
| `caisse_v1_order_quote_2026-04-25.jsonl` | M-05 | `CV1-M05-ORDER-QUOTE` |
| `caisse_v1_payment_ledger_2026-04-25.jsonl` | M-04A | `CV1-M04A-PAYMENT-LEDGER-FULL` |
| `caisse_v1_payment_pilot_2026-04-25.jsonl` | M-04B | `CV1-M04B-PAYMENT-PILOT-RESTRICT` |
| `caisse_v1_fiscal_z_2026-04-25.jsonl` | M-08 | `CV1-M08-FISCAL-Z-NF525` |
| `caisse_v1_kds_release_2026-04-25.jsonl` | M-07 | `CV1-M07-KDS-RELEASE` |
| `caisse_v1_os_fos_symmetry_2026-04-25.jsonl` | M-10 | `CV1-M10-OS-FOS-SYMMETRY` |
| `caisse_v1_kiosk_runtime_2026-04-25.jsonl` | M-11 | `CV1-M11-KIOSK-RUNTIME` |
| `caisse_v1_web_stripe_2026-04-25.jsonl` | M-17 | `CV1-M17-WEB-STRIPE-SCOPE` |
| `caisse_v1_migrations_2026-04-25.jsonl` | M-13 | `CV1-M13-MIGRATIONS-SAFETY` |
| `caisse_v1_ops_preflight_2026-04-25.jsonl` | M-14 | `CV1-M14-OPS-PREFLIGHT` |
| `caisse_v1_rollout_canary_2026-04-25.jsonl` | M-15 | `CV1-M15-ROLLOUT-CANARY` |
| `caisse_v1_post_launch_2026-04-25.jsonl` | M-22 | `CV1-M22-POST-LAUNCH-OBSERVABILITY` |

## 5. Anti-patterns

- Reecrire `memory/INDEX.md` a chaque mission.
- Creer un store hors A / B / C / D.
- Skipper `agent-activity-log.sh start`.
- Ingerer Graphiti sans verifier ensuite.
- Copier des rapports longs dans `episode_body` au lieu d'y inscrire des decisions durables courtes.
- Completer une mission avec un verdict audite non obtenu.
