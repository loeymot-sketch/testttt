# Bref contexte FoodKing — alimentation terminal Claude Code

Généré : 2026-05-02T09:42:57+02:00

## .cursor/ACTIVE_CYCLE.md (extrait, 60 premières lignes)
# Active Cycle – FoodKing

**Méta (SSOT `run-cycle.md` Step 0 + `AGENTS.md` § *Authoritative … cycle state*)** — requis pour que l’orchestrateur ne s’arrête pas sur *« RUNNER_MODE not set »*.

| Champ | Valeur actuelle |
| --- | --- |
| **RUNNER_MODE** | `single-session` |
| **PHASE** | `EXECUTE` |
| **TASK_ID** | `CV1-CATALOG-CONVERGENCE-001` (Sprint 1 / Task 1.4 — Warning `channels=null`) |
| **PLAN_FILE** | `plans/PLAN_CV1-CATALOG-CONVERGENCE-001_2026-05-02.md` |
| **EXECUTION_TIER** | `routine` (S effort, hors invariants critiques — voir `docs/orchestration/MULTI_AGENT_LOOP_2026-05-02.md` §2) |
| **EXECUTE_DELEGATION** | `foodking-routine-implementer` (Composer Max+thinking) |
| **REPORT_FILE** | `reports/post_execute_latest.log` (append — preuve `EXECUTE_DELEGATION` / `AUDIT_*`) |
| **MULTI_AGENT_LOOP** | `docs/orchestration/MULTI_AGENT_LOOP_2026-05-02.md` (SSOT du pivot 2026-05-02) |

> **ACTIVE_PRIMARY** : `CAISSE_V1_MASTERPLAY` (un seul cycle peut être actif à la fois — voir B03 méga-checklist).
> Cycles plus anciens en lecture seule = **archive** déplacée dans **`.cursor/ACTIVE_CYCLE_ARCHIVE.md`** (lecture humaine / forensique uniquement, **non requise** par le parcours obligatoire).

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

## Dernières entrées — memory/episodes/12_decisions_log.jsonl
{"name":"Channels NULL = visible everywhere — bombe à retardement multi-branche","source":"text","source_description":"app/Models/Item.php:83-85 + app/Models/ItemCategory.php:54-56 + docs/MENU_PROJECTIONS.md:30","episode_body":"Item::isVisibleOn et ItemCategory::isVisibleOn court-circuitent à TRUE quand channels === null. C'est la politique 'back-compat' documentée dans docs/MENU_PROJECTIONS.md §2. En V1 mono-branche c'est sans conséquence ; en multi-branches en prod, tout produit créé par un admin qui oublie de cocher channels apparaît automatiquement sur kiosk + POS + web ET sur toutes les branches. Mitigation Vague 1 : warning serveur log [catalog.channels-null] à la création/modification. Vague 3 : gate humain pour passer à channels=required avec migration backfill."}
{"name":"Lifecycle audit Mission 2 — UX-bound debt, not functional","source":"text","source_description":"reports/audit/CLAUDE_ULTRA_REVIEW_MISSION_2_STOCK_COMPOSITION_2026-05-02.md","episode_body":"L'audit Mission 2 (2026-05-02, Claude Opus 4.7 xhigh) confirme que le lifecycle produit V1 est fonctionnellement solide : composition_snapshot immuable, StockService::releaseForOrder idempotent via released_qty ledger, auto-86 réactif sur on_hand<=0 et max_daily_qty, branch isolation respectée sur 4 chemins cancel. Le ressenti restaurateur ('rien ne marche dans la gestion') est UX, pas fonctionnel : workflow admin morcelé en 9 étapes sans wizard guidé, pas d'avertissement composer non-publié, pas de prévisualisation surfacique inline. Verdict READY_WITH_DEBT_TICKET. Cycle suivant CV1-LIFECYCLE-UX-001 (Vague 1 quick wins UX) ; Vague 2 hardening (auto-86 préventif cron + profile_version check au submit derrière gate brief frozen pricing + wizard admin guidé multi-step) ; Vague 3 schema (channels=required, modèle stock unifié, composer_profile_version sur order_items)."}
{"name":"Auto-86 mechanism — réactif uniquement, pas préventif","source":"text","source_description":"app/Services/Menu/AvailabilityService.php:191-236 + app/Services/Stock/StockService.php:179-215 + app/Console/Kernel.php:21-96 (no scheduled stock command)","episode_body":"Auto-86 V1 est déclenché à la commande qui consomme la dernière unité, jamais en amont. Aucun job scheduled qui scrute stock_levels. Conséquences opérationnelles : si une période sans commandes laisse le stock vide, les opérateurs n'ont pas d'alerte préventive ; un item peut rester 'is_available=true' quelques minutes après que sa dernière variation stockable est tombée à 0 (jusqu'à la prochaine décrémentation déclenchée par une commande). À ajouter Vague 2 (action 2.1) : cron 'php artisan stock:scan-rupture' toutes les 5 min."}
{"name":"Profile version race — wizard v1 ouvert, publish v2 admin, submit panier","source":"text","source_description":"PricingService::validateComposerSelections + ChoiceAvailabilityResolver::assertSelectionsOrderable file:line in audit report Mission 2","episode_body":"Aucun composer_profile_version check à la soumission. Le rejet stale-choice se fait par effet de bord (option_id retiré du profil v2 absent dans projection courante → assertSelectionsOrderable jette). Pas de message UX dédié côté kiosk — seulement 422 générique. À durcir Vague 2 (action 2.2) : ajouter composer_profile_version_at_open dans OrderRequest + 409 Conflict UX-friendly. Cette modification touche PricingService (frozen zone) → gate brief requis."}
{"name":"CV1 foundations layered for Codex executor — 2026-05-02","source":"text","source_description":"reports/handoff/HANDOFF_CODEX_CV1_FOUNDATIONS_2026-05-02.md + plans/PLAN_CV1-CATALOG-CONVERGENCE-001_2026-05-02.md + plans/PLAN_CV1-LIFECYCLE-UX-001_2026-05-02.md","episode_body":"Suite aux audits ultra-review Mission 1+2, Claude a posé en une session 7-batches les fondations structurelles que Codex (gpt-5.5-pro xhigh) doit compléter : 1 config feature-flag (config/catalog_v15.php avec defaults safe-no-op), 1 shim PosMenuProjection à 3 modes (legacy/shadow/unified) + kill-switch, 1 CatalogWarningService non-bloquant, 1 stub StockScanRupture cron, 11 sentinels PHPUnit skipped (contract+plan-task documentés en docstring), 5 composants Vue squelettes (ItemPreview, ComposerProfileWarningBadge, ProductCreateWizard, CatalogChangeToast, StockRuptureDashboard), 1 composable useCatalogChangeNotifier, 1 service PosSyncService fallback polling, design tokens cv1-tokens.css + WCAG 2.1 AA checklist + design system doc, 2 plans master CV1-CATALOG-CONVERGENCE-001 et CV1-LIFECYCLE-UX-001 (Vagues 1+2+3 avec tâches atomiques effort/risque/gate), HANDOFF_CODEX explicit. Frozen zones intactes. Build npm run dev OK. Sentinels skipped propres (4/4 vérifiés sur PosCategoryBranchScopeTest). Cursor (orchestrateur PR session) a vérifié l'inventaire, ingéré la mémoire, et signe la livraison foundations comme prête à être consommée par Codex sans clarification supplémentaire."}

## memory/INDEX.md (début)
# FoodKing — Index de la mémoire d'intelligence

> Table des matières navigable des épisodes Graphiti.
> Chaque fichier = un domaine. Chaque ligne JSONL = un fact atomique.
> **2026-05-02** — `12_decisions_log.jsonl` enrichi (+7 entries) et `09_tasks_history.jsonl` (+1 entry) : audits ultra-review Mission 1 (catalog sync POS↔Kiosk) + Mission 2 (lifecycle stock+composition) — verdict `READY_WITH_DEBT_TICKET` sur les deux. Fondations posées en 7 batches par Claude (terminal opus xhigh) + relais Cursor : 4 services backend stub + 11 sentinels PHPUnit skipped + 5 composants Vue squelettes + 1 composable + 1 service JS + design tokens + a11y WCAG checklist + 2 plans master + handoff Codex. Sources : `reports/audit/CLAUDE_ULTRA_REVIEW_MISSION_{1,2}_*.md`, `plans/PLAN_CV1-{CATALOG-CONVERGENCE-001,LIFECYCLE-UX-001}_2026-05-02.md`, `reports/handoff/HANDOFF_CODEX_CV1_FOUNDATIONS_2026-05-02.md`. Cycles à ouvrir : `CV1-CATALOG-CONVERGENCE-001` (Mission 1) et `CV1-LIFECYCLE-UX-001` (Mission 2). Gate frozen pricing requis avant M2 V2 task 2.2.
> **2026-04-26** — `caisse_v1_masterplay_codex_close_2026-04-26.jsonl` : clôture masterplay GPT/Codex, M-04A bloqué Option B, prochaine gate W2 / release (voir `reports/audit/CLAUDE_AUDIT_BRIEF_CODEX_MASTERPLAY_CLOSE_2026-04-26.md`).
> **2026-04-26** — `caisse_v1_wave2_option_b_2026-04-26.jsonl` : 36 missions `CV1-LOT-*` préparées (Option B) ; 4 lots bloqués (K-05, P-06, P-10, P-13) ; prochain run `CV1-LOT-D01-CLIENT-TOTAL-INVARIANT` — `missions/W2_LOT_CODEX_RUN_ORDER_OPTION_B_2026-04-26.md` + `reports/audit/W2_LOT_MISSION_PREP_OPTION_B_2026-04-26.md`.
> **2026-04-26** — Train A V1 release prep : Caisse V1 / POS+Kiosk est l'`ACTIVE_PRIMARY`, W10 passe en lecture seule, et la politique mémoire devient ciblée : tracker uniquement les décisions durables V1, pas les outputs bruités. Sources : `docs/gates/GATE_PHASE2_TRAIN_A_HUMAN_DECISIONS_2026-04-26.md`, `docs/PHASE_A_CLOSED.md`, `reports/audit/PHASE2_PLAN_TRAINS_REWORKED_2026-04-27.md`.

| # | Fichier | Domaine | Épisodes | Pour qui |
|---|---------|---------|----------|----------|
| 01 | `01_project_overview.jsonl` | Vision, business, stack, surfaces | ~10 | Tout LLM/dev qui découvre le projet |
| 02 | `02_architecture_invariants.jsonl` | Invariants techniques, frozen zones, multi-tenant | ~16 | Avant toute modification backend |
| 03 | `03_domain_events_sync.jsonl` | Outbox, DispatchableAfterCommit, Echo, dédup | ~14 | Travail sur sync borne↔POS↔KDS |
| 04 | `04_pricing_ssot.jsonl` | Single Source of Truth pricing, formules, edge cases | ~10 | Avant toute modif PricingService |
| 05 | `05_fiscal_nf525.jsonl` | Conformité fiscale FR, chain hash, Z, audit_log | ~12 | Conformité, compta, fiscaliste |
| 06 | `06_kiosk_features.jsonl` | Wizard tacos, multi-quantité, allergens, offline, a11y | ~14 | Dev frontend Kiosk |
| 07 | `07_pos_features.jsonl` | Park orders, multi-tender, refund, floorplan, ESC/POS, NFC | ~16 | Dev frontend POS |
| 08 | `08_kds_features.jsonl` | Bump/recall, station filter, timers, item availability | ~10 | Dev KDS |
| 09 | `09_tasks_history.jsonl` | 22 tasks V14 + Vague D + cross-wave findings (G-1, G-2, G-3, SYNC-001/002) | 24 | Audit, planning, debug régression |
| 10 | `10_tests_coverage.jsonl` | Sentinels Vitest 707 + PHPUnit 825, par domaine | ~12 | Avant tout refactor |
| 11 | `11_production_plan.jsonl` | Sync-first rollout phases 0-5, monitoring, V2 plan | ~12 | Préparation prod, ops |
| 12 | `12_decisions_log.jsonl` | ADRs, gates passed/blocked, choix d'architecture | 25 | Comprendre POURQUOI |
| 13 | `13_agents_roles.jsonl` | Multi-agents (Claude/GPT-5.4/Composer), orchestration | ~20 | Reprendre orchestration |
| 14 | `14_conventions.jsonl` | Naming, scope, safety, paths critiques, hooks | ~10 | Tout dev |

> Voir aussi : `memory/JSONL_SCHEMA.md` (schéma strict), `memory/POLICIES.md` (clear_graph + duplicates).

## Politique épisodes Train A / V1

- Tracker les décisions durables : gates humaines, choix release, invariants corrigés, blocages D-M13, décisions paiement V1, i18n FR, hardware UAT.
- Ne pas tracker les sorties transitoires : logs volumineux, outputs de tests complets, fichiers temporaires de runner, brouillons non validés.

## Rappel
- Neo4j/Graphiti : **pas** branché sur ce script ; lire `memory/INDEX.md` + JSONL, ou le MCP `search_memory_facts` dans **Cursor**.
- Ce fichier évite de recoller tout le chat : **réutilisé** par `audit-brief`.

## Post-implémentation (ordre — alimentation base + abonnement utile)
1) `bash scripts/after-execute-memory.sh` — manifeste + rappel `graphiti-ingest` (si JSONL touchés).
2) `bash /Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/scripts/foodking-claude-orchestrate.sh context` (ce bref) ; option utile 3) `audit-brief` = audit claude -p ciblé.
