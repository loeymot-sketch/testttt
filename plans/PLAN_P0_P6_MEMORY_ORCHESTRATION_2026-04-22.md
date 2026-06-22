# PLAN — P0 → P6 mémoire + orchestration (exécution 2026-04-22)

**TASK_ID** : `P_P0_P6_MEMORY_ORCHESTRATION_2026-04-22`  
**PRIMARY_MODEL** : exécution repo (Composer-equivalent) + audit final **foodking-planner-orchestrator**  
**SUBSYSTEMS_TOUCHED** : `memory/episodes/`, `memory/verify.py`, `scripts/`, `bin/`, `.github/workflows/phpunit.yml`, `app/Services/OrderService.php`, `.gitignore`  
**SUBSYSTEMS_OFF_LIMITS** : pricing core, auth schema, frozen POS components hors scope explicite  
**GATE_CONDITIONS** : None (docs + guardrails + JSONL éditoriaux)  
**INVARIANTS_AT_RISK** : aucun invariant métier OrderService modifié (commentaire `// allow:` uniquement sur ligne idempotency PROD-2)

## Objectif

Exécuter **à la lettre** la chaîne P0–P6 issue de l’audit 3ᵉ passe, avec livrables traçables dans le dépôt.

## P0 — Neo4j ingest long drain

**Statut** : script + doc livrés ; **exécution runtime** = machine avec `~/.cursor/mcp-graphiti.env` + temps long.

- Script : `bin/graphiti-p0-long-drain.sh` (`DRAIN_TIMEOUT=7200`, `DRAIN_STALL_ITERS=120` par défaut).
- Commande humaine : `nohup bash bin/graphiti-p0-long-drain.sh > /tmp/foodking-p0-ingest.log 2>&1 &`
- Gate mémoire inchangé : `python3 memory/verify.py` → count **≥ 175** (idéal = nombre d’épisodes JSONL courant, **182** après ajouts `12_`).

## P1 — Drift JSONL 03 + invariant 5/6

- Corrigé `memory/episodes/03_domain_events_sync.jsonl` (ligne 1 steps 4–5, épisode DispatchDomainEventsJob, épisode Channels) : alignement **code réel** (`private-branch.{branchId}`, `branch.{branchId}`, job par `domain_event_id`).
- `scripts/check-invariants.sh` : exclude `Concerns/DispatchableAfterCommit` pour **5/6** (faux positif trait `broadcast()`).
- `OrderService::posOrderStore` : `// allow:` sur ligne idempotency **2/6** (PROD-2).

## P2 — verify.py + ADR JSONL

- `memory/verify.py` : 14 requêtes domaine + 3 smoke ; option `--json` → `reports/memory/verify_snapshot.json`.
- `memory/episodes/12_decisions_log.jsonl` : +2 épisodes (PROD-1 fiscal archive lock, PROD-4 preflight).
- `memory/INDEX.md` : comptes 09 (24) et 12 (17).

## P3 — CI delegation warn

- `scripts/check-run-delegation-warn.sh` : scan `reports/execution/RUN_*.md` **mtime < 14j** ; `::warning::` GitHub si ligne `EXECUTE_DELEGATION:` absente.
- `.github/workflows/phpunit.yml` : step **non bloquant** après PHPUnit (`if: success() || failure()`).

## P4 — Manifest JSONL

- `scripts/memory-jsonl-manifest.sh` → `reports/memory/jsonl_manifest.json` ; mode `--check` pour comparer.
- `.gitignore` : `verify_snapshot.json` + `jsonl_manifest.json` (régénérables ; commit baseline si besoin).
- `reports/memory/README.md` : description artefacts.

## P5 — Qualité tests ciblés (V10 dine-in flag)

- `tests/js/posDineInFlag.spec.js` : garde `typeof` + cas limites (user diff) — **Vitest 11/11 verts** localement.

## P6 — Audit final (Claude sub-agent)

- Délégation : **foodking-planner-orchestrator** — rapport synthétique attendu (forces, gaps résiduels, ordre P0 runtime).

## VALIDATE (local)

- `bash scripts/check-invariants.sh` → **1/6 fail** attendu (4/6 `P11_DISPATCH_AFTER_COMMIT_REMEDIATION` inchangé).
- `npx vitest run tests/js/posDineInFlag.spec.js` → **11/11** OK.
- `python3 -m py_compile memory/verify.py` OK.
- JSONL `03` + `12` : parse Python OK.

## PRIOR_CONTEXT (subagent)

- SSOT mémoire = **182** épisodes JSONL valides post-P2 ; Neo4j doit rattraper via **P0** sur machine outillée.
- Canaux Pusher : doc corrigée = `private-branch.{branchId}` + auth `branch.{branchId}` (pas de `.surface` dans le nom PHP).
- Invariants CI : seul **4/6** reste rouge jusqu’à gate remédiation dispatch-after-commit.
