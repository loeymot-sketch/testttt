# Méga-checklist — robustesse opérationnelle + autonomie agentique + Graphiti continu

> **Usage** : cocher au fil des cycles ; une session peut prendre un lot **P0** (bloquant) puis des lots **P1/P2**.  
> **Audit v2 (preuves chiffrées)** : [`reports/audit/MEGA_AUDIT_SYSTEM_OPERATION_AGENTIC_2026-04-23.md`](../../reports/audit/MEGA_AUDIT_SYSTEM_OPERATION_AGENTIC_2026-04-23.md).  
> **Mémoire** : toute décision durable → `memory/episodes/*.jsonl` + ingest ciblé (`bin/graphiti-ingest.sh` / procédure équivalente).

**Légende** : `[ ]` à faire · `[x]` fait (référence date / rapport si besoin)

---

## A — Graphiti & mémoire (23)

- [x] A01 — SSOT JSONL versionné sous `memory/episodes/` (**14** fichiers, **184** lignes valides post-2026-04-23, diff Git)
- [x] A02 — Ingestion longue drain P0 (`bin/graphiti-p0-long-drain.sh`) jusqu’à count Neo4j ≈ JSONL
- [x] A03 — `memory/verify.py` domaine + `--json` → `reports/memory/verify_snapshot.json`
- [x] A04 — Manifest SHA256 JSONL (`scripts/memory-jsonl-manifest.sh`)
- [x] A05 — Baseline `reports/memory/jsonl_manifest.json` + CI **`memory-jsonl-manifest.sh --check`** (même commit si JSONL bouge) (RUN_2026-04-24)
- [ ] A06 — Job CI optionnel : `verify.py` post-merge sur runner avec secrets Neo4j (non bloquant merge puis mode strict)
- [ ] A07 — Après chaque ADR / gate : **1 ligne** `12_decisions_log.jsonl` + ingest ciblé `12_decisions`
- [ ] A08 — Après chaque invariant nouveau : ligne `02_architecture_invariants.jsonl` + ingest
- [ ] A09 — Trimestriel : relire `03_` / `05_` / `07_` vs code (sync, fiscal, POS)
- [ ] A10 — `search_memory_nodes` dans `verify.py` (option) pour frozen zones
- [x] A11 — Documenter `@graphiti clear_graph` politique → **`memory/POLICIES.md`** (RUN_BATCH3 2026-04-23)
- [ ] A12 — Métrique **`add_episode`** / durée totale d’ingest par fichier (observabilité pipeline Graphiti)
- [x] A13 — Politique **`duplicate_facts`** → **`memory/POLICIES.md`** (cap, dédup, idempotence sémantique) (RUN_BATCH3 2026-04-23)
- [ ] A14 — **`memory/ingest.py`** : idempotence cassée si re-run sans `clear_graph` → ticket correctif + doc « re-ingest safe »
- [ ] A15 — Plafond **`max_episodes=500`** : revue risque + alerte si file approche du plafond
- [ ] A16 — Runbook **« JSONL a bougé mais Neo4j non »** + réconciliation manuelle (preuve drift `memory/INDEX.md`)
- [~] A17 — Schéma JSONL documenté → **`memory/JSONL_SCHEMA.md`** (RUN_BATCH3) ; validation CI restante à brancher
- [x] A18 — Auth / sessions invariants ajoutés à **`02_architecture_invariants.jsonl`** (12→16 lignes, +`auth_invariants` + `sessions_invariants`) (RUN_BATCH3 2026-04-23)
- [ ] A19 — **`memory/verify.py`** : remplacer heuristique sous-chaîne `"uuid"` par comparaison réelle Neo4j ↔ JSONL quand runner + secrets disponibles
- [ ] A20 — Retry / backoff sur échecs `add_memory` dans le chemin d’ingest (pas de silence total)
- [ ] A21 — **Aucun déclencheur auto** aujourd’hui (0 workflow `.github/workflows/`, 0 hook git, 0 cron) → définir hook **post-merge** ingest ciblé
- [x] A22 — **`13_agents_roles.jsonl`** enrichi 8 → **20** lignes (planner, browser-use, generalPurpose, shell, cursor-guide, best-of-n, routing matrix, EXECUTE_DELEGATION sentinel, PRIOR_CONTEXT, anti-patterns, hard halts) (RUN_BATCH3 2026-04-23)
- [ ] A23 — Playbook **Graphiti down** enrichi : INDEX + fichiers JSONL secours + policy duplicates

---

## B — Cycle borné & état (17)

- [x] B01 — `run-cycle.md` Step 0.5 Graphiti avant PLAN
- [x] B02 — `EXECUTE_DELEGATION:` exigé Step 2/4 (sentinel exacte)
- [x] B03 — **`ACTIVE_PRIMARY` bandeau ajouté** en tête + HOTFIX_W8.5 renommé `CLOSED_PENDING_CI_MONITORING` → 1 seul `IN_PROGRESS` (RUN_BATCH1 2026-04-23)
- [ ] B04 — Rétro-remplir **`EXECUTE_DELEGATION:`** sur `reports/**/RUN_*.md` : **113** fichiers au total, **30/113 = 26,5 %** avec sentinel stricte aujourd’hui
- [~] B05 — Script `scripts/check-execute-delegation.sh` (warn-only, baseline mesurée **18/114 = 15 %**) (RUN_BATCH2 2026-04-23) ; passage CI strict restant
- [~] B06 — Script détection `scripts/check-active-cycle.sh` (compte `IN_PROGRESS`, alerte > 1) (RUN_BATCH2 2026-04-23) ; auto-reset hook restant
- [ ] B07 — Refactor **template `RUN_*.md`** : bloc sentinel + lien plan + `SUBSYSTEMS_TOUCHED`
- [ ] B08 — **Archivage** `CYCLE_*.md` / append cycles clos en fin de fichier (éviter tête illisible)
- [ ] B09 — `plans/*.md` (**38** fichiers) : **`## PRIOR_CONTEXT` obligatoire** si le plan touche `app/` — baseline **17/38 = 44,7 %** aujourd’hui
- [ ] B10 — Plans d’exécution : **8/38 = 21 %** mentionnent explicitement `foodking-routine-implementer` ou `foodking-complex-implementer` → couverture cible élevée
- [ ] B11 — `tasks/**/*.md` (**~176** fichiers) : **0/176 = 0 %** référencent `GLOBAL_SYSTEM_PRIMER.md` → ajouter lien obligatoire ou script de lint
- [ ] B12 — `SCOPE_PRESSURE` / `ESCALATION` : mesurer adoption réelle (rappel mid-cycle)
- [ ] B13 — Interdiction modifier `.cursor/routing.md` mid-cycle (rappel hook / revue)
- [ ] B14 — `PHASE` transitions loggées en une ligne dans `REPORT_FILE`
- [ ] B15 — `run-cycle` : idempotence « relancer même `TASK_ID` » documentée
- [ ] B16 — `VALIDATE` : échec 2× sans remediation AUDIT → halt (rappel sentinelle doc)
- [x] B17 — Ligne **`EXECUTE_DELEGATION:`** rétro sur les 3 RUN listés (RUN_2026-04-24)

---

## C — Sub-agents & intelligence (14)

- [x] C01 — Profils `foodking-routine-implementer` / `foodking-complex-implementer` documentés
- [x] C02 — Matrice **routine vs complexe** : `docs/orchestration/ROUTING_MATRIX.md` (RUN_2026-04-24)
- [ ] C03 — Quota parallélisme **explore** (éviter > N agents sans plan fichier)
- [ ] C04 — `foodking-planner-orchestrator` : usage obligatoire audit final cycles **MEGA** / prod
- [ ] C05 — **Snippet `PRIOR_CONTEXT` minimal** par domaine (sync, fiscal, POS, CI, mémoire)
- [ ] C06 — Anti-pattern : implémenter dans **chat parent** sans délégation → checklist AUDIT KO
- [ ] C07 — Session longue : résumés phase `context-hygiene.mdc` §4 sans tronquer plan
- [ ] C08 — Token discipline : Graphiti avant réouverture N rapports — mesurer obéissance
- [ ] C09 — Sub-agent **sans MCP** : fichier `plans/` doit inclure extrait `search_memory_facts` textuel
- [ ] C10 — **Post-mortem** sous-agent : template **3 leçons** (ce qui a marché / raté / prochaine garde)
- [ ] C11 — **Audit mensuel** : % conformité délégation sur l’ensemble des `RUN_*.md` + plans récents
- [ ] C12 — **Matrice responsabilités** routine/complexe dans onboarding orchestrateur (1 table)
- [ ] C13 — Interdire **expansion de scope** sans replan explicite (`ESCALATION`)
- [ ] C14 — Registre **incidents de délégation** (qualité des prompts, oublis PRIOR_CONTEXT)

---

## D — Gates & humain (12)

- [x] D01 — `scripts/list-gates.sh` créé → baseline **OPEN 0 / CLOSED 12 / UNKNOWN 4 / TOTAL 16** (RUN_BATCH2 2026-04-23)
- [ ] D02 — **SLA gate** N jours + procédure **escalation** si non-réponse (contact décideur)
- [ ] D03 — Gate brief template unifié (champs D1–D11 — figer dans `docs/gates/README.md`)
- [ ] D04 — Aucune auto-approbation gate (sentinel dans `human-gates.mdc` — revue trimestrielle)
- [ ] D05 — Frozen zones listées dans plan **avant** EXECUTE (orchestrateur)
- [ ] D06 — `safety-check.sh` : couverture chemins frozen à jour avec `scope` / LOCK
- [ ] D07 — G14-B (compta/DPO) : critères d’entrée / sortie MVP documentés
- [ ] D08 — Schema migrations : double review humain inchangé
- [ ] D09 — `clear_graph` Graphiti : **double** confirmation humaine loggée
- [ ] D10 — Export juridique / DPO : lien procédure dans `11_production_plan.jsonl`
- [ ] D11 — **`docs/AUTHZ_MATRIX.md`** resynchronisé (clarifier admin `branch_id=0`, divergences routes)
- [ ] D12 — Gate brief : champ **escalation** / backup décideur documenté

---

## E — Tests & CI (20)

- [x] E01 — PHPUnit Fiscal archive + verifyChain verts
- [x] E02 — Outbox + DispatchAfterCommit verts (sentinelles connues)
- [x] E03 — `check-invariants.sh` **6/6 vert** (P11 / KI-001) : `OrderCreated`/`OrderStatusChanged` passent par `DispatchableAfterCommit` ; `event(new OrderStatusChanged` **interdit** (contourne le trait) — corrigé `FrontendOrderService` ; script **filter_dispatchableaftercommit_traits** (RUN_2026-04-23)
- [x] E04 — **CI** : job `invariants-grep` **bloque** le merge (plus de `continue-on-error`). **Local** : `composer invariants` (exit 0 quand 6/6)
- [x] E05 — `phpunit.yml` : migration drift + delegation warn + manifest (tel que livré)
- [x] E06 — **Vitest** GHA : **push `develop`** aligné sur PRs (`.github/workflows/vitest.yml`) (RUN_2026-04-24)
- [ ] E07 — **Playwright** : documenter / aligner politique label PR **`e2e-required`** vs exécution sur **`main`** sans label
- [ ] E08 — **Secret scan** CI (ex. gitleaks) — baseline 0 aujourd’hui
- [ ] E09 — `composer audit` en CI (phase warn puis strict)
- [ ] E10 — `npm audit` en CI
- [ ] E11 — **Laravel Pint** en CI (format PHP)
- [ ] E12 — **PHPStan** progressif (baseline niveau, montée en charge)
- [ ] E13 — **husky** ou **lefthook** pre-commit (`.git/hooks/` non versionnés aujourd’hui)
- [ ] E14 — `php artisan app:preflight-production --strict` sur **staging** en gate CD (job dédié)
- [x] E15 — `IdempotencyTest` → **`IdempotencyBranchScopedTest`** (2 occurrences fixées) (RUN_BATCH1 2026-04-23)
- [ ] E16 — Vitest budget temps par PR (alerte si > X min)
- [ ] E17 — Couverture sentinels cross-vagues (liste dans `10_tests_coverage.jsonl` à jour)
- [ ] E18 — MySQL CI obligatoire pour surfaces JSON (documenter skip SQLite)
- [ ] E19 — `composer test` alias documenté README racine
- [ ] E20 — Flaky test registry `reports/ci/FLAKY.md` + contract tests noms canaux Pusher vs `routes/channels.php`

---

## F — Fiscal & prod (13)

- [x] F01 — `FiscalArchiveCommand` verifyChain + lock `z_report_b{n}`
- [x] F02 — Manifest schema v3 + `z_chain_verified`
- [ ] F03 — Monitoring `fiscal:archive` lock_timeout events (dashboard)
- [ ] F04 — Runbook J-1 archive échec + retry (`.env.example` — compléter lien ops)
- [ ] F05 — Alert si `verify_chain_before_archive=false` en prod (anomalie)
- [ ] F06 — **Bundle ZIP** test E2E export fiscal (jeu de preuve NF525 reproductible)
- [ ] F07 — **NTP** / discipline horloge stricte sur nœuds prod (alignement Z / séquences)
- [ ] F08 — Compléter **`app:preflight-production`** : **Pusher**, **Redis**, **APP_URL**, **disques** writable, **Horizon**, **S3**, **TLS**, **mail**, cohérence **`config:cache` vs `.env`**
- [ ] F09 — Corriger lecture **`LOG_LEVEL`** via `env(...)` direct dans preflight (piège post-`config:cache`) — utiliser `config()`
- [ ] F10 — Revue annuelle rotation clés HMAC (procédure gate)
- [ ] F11 — Drill restaurateur : simulation archive indisponible + recovery
- [ ] F12 — Surveillance taille / intégrité archives (local vs S3)
- [ ] F13 — Documenter **118 migrations** `database/migrations/` : politique double-review + drift CI (rappel)

---

## G — Sync & broadcast (13)

- [x] G01 — `DispatchDomainEventsJob` utilise `BroadcastManager::connection()`
- [x] G02 — JSONL `03_` corrigé (canaux, job par id)
- [ ] G03 — P11 : `ShouldDispatchAfterCommit` sur events + suppression hits 4/6 invariants
- [ ] G04 — Test double `DispatchDomainEventsJob` même `domain_event_id` (idempotence serveur)
- [ ] G05 — Métrique latence outbox : `dispatched_at - created_at` par branche
- [ ] G06 — Alert backlog `domain_events` pending > seuil
- [ ] G07 — E2E Pusher mock vs log driver (doc limites)
- [ ] G08 — `EventContract` versioning v2 planifié si schéma envelope change
- [ ] G09 — Corrélation `correlation_id` dans logs Laravel + search runbook
- [ ] G10 — KDS / POS / Kiosk : matrice « qui écoute quel event » (1 table doc)
- [ ] G11 — Events **`ItemCreated`**, **`ItemDeleted`**, **`CategoryCreated` / `Updated` / `Deleted`** : étendre **`DispatchableAfterCommit`** (ou auditer tous call-sites `DB::afterCommit` — risque oubli nouveau call-site)
- [ ] G12 — Alerte si **`domain_events.last_error`** non vide + dashboard backlog outbox **réel** (pas seulement SQL ad hoc)
- [ ] G13 — Runbook : `DispatchDomainEventsJob` log **`warning`** en `failed()` **sans alerte** deployée → silence prod possible

---

## H — Sécurité & multi-tenant (13)

- [ ] H01 — Audit annuel `AUTHZ_MATRIX.md` vs routes
- [ ] H02 — Kiosk token `kiosk:order` branch scope tests exhaustifs (étendre partiel existant)
- [ ] H03 — Admin `branch_id=0` : journaliser accès données par branche
- [ ] H04 — Idempotency cross-branch (PROD-2) — sentinels additionnels si nouveaux endpoints
- [ ] H05 — Secrets : rotation playbook + `memory/README` rappel chmod 600
- [ ] H06 — Rate limits kiosk merge (W8) — revue valeurs par charge
- [ ] H07 — OWASP ASVS scan trimestriel POS web
- [ ] H08 — DOMPurify usages audit `v-html` grep
- [ ] H09 — `branch_id` jamais depuis request sur order flow (invariant — étendre grep si nouveaux controllers)
- [ ] H10 — RGPD export données client (backlog produit — épisode `11_` quand spec)
- [x] H11 — Épisodes `auth_invariants` + `sessions_invariants` ajoutés (`02_architecture_invariants.jsonl` 12→16) (RUN_BATCH3 2026-04-23)
- [ ] H12 — Étendre **`.cursor/hooks/safety-check.sh`** au-delà des **2** fichiers actuels (`OrderService`, `FrontendOrderService`) pour refléter **toutes** les frozen zones documentées
- [ ] H13 — Revue **multi-tenant** : grep contrôleurs nouveaux + patterns `branch_id`

---

## I — Observabilité & ops (12)

- [ ] I01 — Dashboard Horizon / queue high (runbook)
- [ ] I02 — SLO dispatch outbox (métrique réelle, pas seulement JSONL)
- [ ] I03 — Logs structurés JSON en prod (option Laravel)
- [ ] I04 — Corrélation trace_id front ↔ back (backlog)
- [ ] I05 — Alertes MySQL slow query fiscal tables
- [ ] I06 — Disk space fiscal archives S3/local
- [ ] I07 — Runbook incident « Neo4j Graphiti indisponible »
- [ ] I08 — Runbook incident « OpenRouter quota »
- [ ] I09 — **Health endpoint** prod/staging (liveness/readiness documentés)
- [ ] I10 — **Post-mortem template** ops (timeline, impact, garde-fous)
- [ ] I11 — Alerte **`DispatchDomainEventsJob::failed()`** (monitoring / SIEM)
- [ ] I12 — Corrélation **`correlation_id`** partout (API, jobs, logs support)

---

## J — Documentation & onboarding (15)

- [x] J01 — `GLOBAL_SYSTEM_PRIMER.md`
- [ ] J02 — Diagramme Mermaid « flux cycle + Graphiti » dans Primer annexe
- [ ] J03 — **Loom** (ou vidéo courte équivalente) — parcours orchestration + mémoire
- [ ] J04 — « Jour 1 dev » checklist 1 page `docs/CONTRIBUTING_ONBOARDING.md`
- [x] J05 — Lier méga-checklist depuis `AGENTS.md` § extended
- [ ] J06 — Glossaire FoodKing (Z, outbox, park, 86, …) `docs/GLOSSARY.md`
- [ ] J07 — **Revue trimestrielle** SSOT (primer, matrices, TEST_PLAN)
- [ ] J08 — Archiver rapports > 90j vers `_archive/` policy
- [ ] J09 — **Index rapports** semi-automatique (script ou générateur README `reports/`)
- [ ] J10 — Traduction **EN** des sections clés orchestration (onboarding international)
- [x] J11 — **9 occurrences** fixées dans **6 fichiers** (`global.mdc`, `project-continuity.mdc`, `app-planner-orchestrator.md`, `app-routine-implementer.md`, `GLOBAL_SYSTEM_PRIMER.md`, `01_EXECUTE_P11_RETURNED_IDEMPOTENCY.md`) (RUN_BATCH1 2026-04-23)
- [ ] J12 — Politique **dédoublonnage** règles `.cursor/rules/*.mdc` **repo** vs `~/Downloads/.cursor/rules/` **global utilisateur** (12 doublons observés)
- [x] J13 — `project-handoff/SKILL.md` ligne 29 corrigée : `(alwaysApply)` → `(chargement à la demande)` (RUN_BATCH1 2026-04-23)
- [ ] J14 — `workflows/report-format.md` : en-tête YAML qui **mime** une règle Cursor — corriger (déplacer sous `.cursor/rules/` ou retirer le front-matter trompeur)
- [ ] J15 — `docs/TEST_PLAN.md` : réduire dette cases `[ ]` / `[~]` + inventorier gaps

---

## K — Produit & dette transverse (10)

- [ ] K01 — i18n **FR JSON gap** (`FINDING_VUE_FR_JSON_GAP` — parité clés)
- [ ] K02 — **P11** dispatch backlog : fermer dette `ShouldDispatchAfterCommit` / invariants 4/6
- [ ] K03 — **G-3** recall : parité dispo **variation** POS ↔ Kiosk (rappel audit V14)
- [ ] K04 — Parité **POS / Kiosk** sur surfaces critiques (hors zones gated figées)
- [ ] K05 — **DUPLICATA** E2E bout-en-bout (UI + API + audit) hors MVP partiel
- [ ] K06 — Kiosk **offline v2** (file d’attente, réconciliation)
- [ ] K07 — **Branch theming** (`GATE_P_MEGA_19` — décisions métier + schema)
- [ ] K08 — **JET XML** DGFiP (spec officielle — cycle différé)
- [ ] K09 — Perf **bundle** / lazy chunks (suite mesures LCP/TBT admin)
- [ ] K10 — **WCAG** AA suivi continu (régression a11y)

---

## L — Discipline lecture & règles machine (9)

- [ ] L01 — Sentinel CI « **Read `AGENTS.md` extended SSOT** » avant merge doc/plan sensible
- [x] L02 — **`post-edit-check.sh`** muscler : warn frozen zones (Order/Frontend/Payment/Pricing/PosReceiptPrint/migrations) + warn routing.md mid-cycle + info JSONL mémoire (RUN_BATCH2 2026-04-23)
- [ ] L03 — **pre-commit** (lefthook/husky) : forcer lecture routing / checklist pour commits touchant `plans/` ou `memory/`
- [ ] L04 — Résoudre contradiction **`context-hygiene.mdc` PLAN** (« ne pas charger code/rapports ») vs lecture documentation produit en phase PLAN
- [ ] L05 — `.cursor/context/plan-context.md` : obligation explicite des **22 fichiers** « Source of truth (extended) » dans `AGENTS.md` ou stratégie de chargement paresseux équivalente
- [ ] L06 — **Skills** `.agents/` avec `disable-model-invocation: true` : documenter invocation manuelle obligatoire
- [ ] L07 — **`check-run-delegation-warn.sh`** : documenter **warn-only** (toujours exit 0) — ne remplace pas garde-fou bloquant
- [ ] L08 — Objectif : porter **note discipline machine** de **3,5/10** (audit 2026-04-23) vers **≥ 8/10** via instrumentation L01–L07
- [x] L09 — Liens morts résolus via J11 (renommage en place — alias inutile) (RUN_BATCH1 2026-04-23)

---

## M — Mémoire continue & enrichissement (9)

- [ ] M01 — Hook **post-merge** : ingest ciblé si `memory/episodes/*.jsonl` modifiés
- [ ] M02 — Prompt agent **standardisé** pour transformer ADR / gate en ligne JSONL + champs obligatoires
- [ ] M03 — **Schéma JSONL strict** en CI (rejeter ligne invalide avant merge)
- [ ] M04 — Politique **déduplication** faits Graphiti (merge stratégie, pas seulement `clear_graph`)
- [ ] M05 — Runbook **Graphiti down** : réconciliation **Neo4j** vs JSONL + contacts on-call
- [ ] M06 — **Baseline manifest** `jsonl_manifest.json` bloquante sur drift post-merge (aligné A05)
- [ ] M07 — Baseline **couverture domaines** : épisodes **CI/invariants** aujourd’hui quasi absents → quota trimestriel
- [ ] M08 — Ticket engineering : **ingest idempotent** sans `clear_graph` (lien A14) + tests hors prod
- [ ] M09 — Rapport semestriel : densité fichiers (**14 JSONL**, **184** lignes totales après décision 2026-04-23) vs objectifs par domaine

---

**Total : 180 tâches** — état après lot E06 + A05 + B17 + C02 (2026-04-24) :

- **`[x]` faits** : 34 (+4 : A05, B17, C02, E06)
- **`[~]` partiels** : 3 (A17 · B05 · B06)
- **`[ ]` restants** : 143

Détail livraison 2026-04-24 (E06 + A05 + B17 + C02) :
- `.github/workflows/vitest.yml` — `push` sur `main` + `develop`
- `.github/workflows/phpunit.yml` — step **Memory JSONL manifest** : `bash scripts/memory-jsonl-manifest.sh --check reports/memory/jsonl_manifest.json`
- `reports/memory/jsonl_manifest.json` — regénéré (SHA suite `12_decisions_log.jsonl` +1 ligne)
- Rétro **B17** : `RUN_V14_GLOBAL_AUDIT_REMEDIATION`, `RUN_P13_LOG_HYGIENE`, `RUN_P_MEGA_W6_A_A11Y_EXECUTE` — `EXECUTE_DELEGATION:`
- `docs/orchestration/ROUTING_MATRIX.md` + liens `AGENTS.md` / `GLOBAL_SYSTEM_PRIMER.md`
- `memory/episodes/12_decisions_log.jsonl` + ingest Graphiti `12_decisions` (24 épisodes)
- `reports/execution/RUN_P_MEGA_CHECKLIST_BATCH4_E06_A05_B17_C02_2026-04-24.md`

Détail livraison 2026-04-23 :
- `scripts/foodking-claude-orchestrate.sh` — `check` / `audit` / `repl` (AGENTS.md)
- `.github/workflows/phpunit.yml` — job `invariants-grep` (E04)
- `reports/execution/RUN_P_MEGA_CHECKLIST_BATCH1_DATA_QUALITY_2026-04-23.md` (Lot 1)
- `reports/execution/RUN_P_MEGA_CHECKLIST_BATCH2_MACHINE_GUARDS_2026-04-23.md` (Lot 2)
- `reports/execution/RUN_P_MEGA_CHECKLIST_BATCH3_GRAPHITI_ENRICHMENT_2026-04-23.md` (Lot 3)
