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
