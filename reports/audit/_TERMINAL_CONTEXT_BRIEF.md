# Bref contexte FoodKing — alimentation terminal Claude Code

Généré : 2026-09-26T19:34:57+02:00

## .cursor/ACTIVE_CYCLE.md (extrait, 60 premières lignes)
# Active Cycle – FoodKing

## Closure audit — 2026-09-17 (session f99fe4fa)

Propriétaire a demandé de retrouver où toutes les missions en cours s'étaient arrêtées et de
clôturer celles qui ne le sont pas. Constat, sourcé sur `reports/AGENT_ACTIVITY_LOG.md` +
`git log` (pas de supposition) :

- **ORDER-INTEGRITY-KDS-LOYALTY-20260914** — DÉPLOYÉ. `reports/AGENT_ACTIVITY_LOG.md` ligne
  2026-09-17T15:05:30Z : « Committed 3e7e3236c, pushed and deployed ; migration, fiscal chain,
  trigger and dedicated browser paths verified. » Le `report.md` de la mission datait d'AVANT ce
  déploiement et affirmait encore « no deployment attempted » — corrigé ci-dessous (addendum daté,
  rien effacé). PHASE repassée à CLOSED.
- **KIOSK-SESSION-RESILIENCE-20260917** — DÉPLOYÉ (commit `e75c46e3b`). Reste un point produit,
  PAS technique : notifications de queue à activer ou non — décision propriétaire en attente.
- **DRAWER-BRIDGE-VISIBILITY-20260917** — NON CLÔTURÉ. Modifie `PaymentComponent.vue` (zone
  gelée CLAUDE.md §7) mais reste **non committé** dans l'arbre de travail, sans LOCK doc. Aucun
  hook mécanique ne l'a bloqué (le script `.cursor/hooks/safety-check.sh` ne liste pas ce fichier
  dans `FROZEN_ZONES` — dérive à corriger séparément). Ne PAS committer sans gate explicite
  propriétaire (§10 : « Frozen-zone touch needed » = STOP).
- **LOCK_CAISSE_CRUDITES_PAYANTES_2026-09-05** — **CORRECTION** : d'abord signalé à tort comme
  bloqué ici. En réalité CLÔTURÉ depuis `a5720abe9` (2026-09-06), sentinelle SHA-256 vérifiée
  verte sur `pos-wizard.js`. La tentative annulée `dafb9c776` (2026-09-05) a simplement été
  suivie d'un second essai réussi le lendemain, jamais relu avant d'écrire cette ligne.

Voir `PROJECT_BRAIN.md` §2 entrée 2026-09-17 pour le détail complet (autocorrection incluse).

---

## Current order-integrity continuation — 2026-09-14 (CLOSED 2026-09-17)

TASK_ID: ORDER-INTEGRITY-KDS-LOYALTY-20260914
PHASE: CLOSED — deployed `3e7e3236c` 2026-09-17 (see closure audit above)
RUNNER_MODE: single-session
PRIMARY_EXECUTION_MODEL: gpt-5.5-pro
PLAN_FILE: plans/PLAN_ORDER-INTEGRITY-KDS-LOYALTY-20260914_2026-09-14.md
REPORT_FILE: reports/execution/ORDER-INTEGRITY-KDS-LOYALTY-20260914/report.md
EXECUTE_DELEGATION:
Previous wheel cycle is retained in its plan/report and replaced by explicit owner instruction.

**Méta (SSOT `run-cycle.md` Step 0 + `AGENTS.md` § *Authoritative … cycle state*)** — requis pour que l’orchestrateur ne s’arrête pas sur *« RUNNER_MODE not set »*.

| Champ | Valeur actuelle |
| --- | --- |
| **RUNNER_MODE** | `single-session` |
| **PHASE** | `EXECUTE` — PLAN_REVIEW_VERDICT: PASS. |
| **MASTER_TASK_ID** | |
| **TASK_ID** | `ORDER-INTEGRITY-KDS-LOYALTY-20260914` |
| **PLAN_FILE** | `plans/PLAN_ORDER-INTEGRITY-KDS-LOYALTY-20260914_2026-09-14.md` |
| **REPORT_FILE** | `reports/execution/ORDER-INTEGRITY-KDS-LOYALTY-20260914/report.md` |
| **AUDIT_SOURCE** | `Pending — claude-terminal after validation` |
| **CONTINUATION_HANDOFF** | `missions/ORDER-INTEGRITY-KDS-LOYALTY-20260914/execute_brief.md` |
| **PARENT_CYCLE** | `Previous WHEEL-JOURNEY-UX-20260913 superseded by explicit owner instruction; its artefacts remain untouched.` |
| **SUBSYSTEMS_TOUCHED** | `Pricing/order sealing, POS composition and checkout, receipt/KDS, kiosk loyalty, tests, web information copy` |
| **INVARIANTS_AT_RISK** | `backend pricing SSOT; branch_id isolation; fiscal snapshot immutability; PII loyalty; frozen POS/pricing services` |
| **GATE_CONDITIONS** | `Approved frozen/pricing/auth/schema gate; no scope expansion without a new brief.` |
| **GATE_FILE** | `docs/gates/GATE_ORDER-INTEGRITY-KDS-LOYALTY-20260914_2026-09-14.md` |

> **ACTIVE_PRIMARY** : `ORDER-INTEGRITY-KDS-LOYALTY-20260914` (cycle standard non-`CV1-MXX`; l'ancienne section Masterplay ci-dessous reste une référence historique, pas un second cycle actif).
> Dernier cycle archivé : `docs/orchestration/cycles/CYCLE_CV1-V1.5C-SYNC-STOCK-HEAL-MASTER_2026-05-04.md`

## Dernières entrées — memory/episodes/12_decisions_log.jsonl
{"name":"AUDIT INDÉPENDANT V1 cumulé 4 masters + GATE PRODUCTION CUTOVER APPROVED — 2026-05-04","source":"text","source_description":"audit terminal Claude post-quota indépendant 2026-05-04 ~19:30 + gate brief docs/gates/GATE_CV1-V1-PIVOT-PRODUCTION-CUTOVER_2026-05-04.md approved + GATE_LOG.md entry + healings G1+H1+H2 appliqués 2026-05-04 19:50 UTC+2","episode_body":"Audit indépendant terminal Claude post-quota 2026-05-04 ~19:30 UTC+2 sur 4 masters cumulés V1 (V1-PIVOT + V1-FINISH + V1.5-DEBT-CLEANUP + V1.5B-DRILLDOWN-INGREDIENTS) — verdict PASS_WITH_HEALING / PROD_READINESS GO_WITH_CONDITIONS, 0 finding CRITICAL, 3 HIGH (F1 MySQL<8.0 mitigé Q2=9.6.0 + F2 rollback fragile déjà documenté gate + G1 sentinelle parity multi-préfixe regex), 6 MEDIUM (A2 middleware catch silencieux, A3 ownerType orphan, B1 PHPDoc branchId asymétrique, D1 GPT final audit non exécuté, E1 drill-down addon sémantique limitée, F3 smoketest rollback partiel), 7 LOW. Vérifications 40 points file:line confirmées : 6/6 invariants FoodKing tenus (I1 pricing SSOT, I2 OrderStatus, I3 branch_id mono-filiale V1 documenté, I4 dispatch après commit DispatchableAfterCommit, I5 OrderService symmetry préservée, I6 frozen zones 0 édition depuis 2026-05-04 13:00), middleware EnsureProfileNotItemOwnedUnlessDemoEnabled couvre exactement 6/6 routes shared profile/step (routes/api.php:670-676), fix D1 ChoiceAvailabilityResolver::availabilityForVariation câblé l.63 snapshotForItems + l.144 assertSelectionsOrderable symétrie order-guard, tests sentinelles non tautologiques. GATE_CV1-V1-PIVOT-PRODUCTION-CUTOVER approved 2026-05-04 19:43 UTC+2 par Kossay/user kossayelbenna8 (réponses chat) + transcription Claude orchestrator : Q1=A cohabitation transitoire, Q2 MySQL 9.6.0 ≥ 8.0 vérifié SSH local terminals/1.txt:30-32 mitigeant finding HIGH F1, Q3 ASAP no-prod-yet (1er resto sera déploiement initial — pas de fenêtre maintenance imposée), Q4 .env ABSENT default false vérifié terminals/1.txt:36-38. GATE_LOG.md ligne ajoutée avec audit trail complet. Healings appliqués post-approval 2026-05-04 19:50 UTC+2 : G1 (HIGH BLOCKING) tests/js/labelKeyParityFrontend.spec.js regex étendue de label.* à 5 préfixes (label, studio, message, demo_wizard_advanced, menu) via TRACKED_PREFIXES array — sentinelle PASS, 1162/1162 Vitest 0 régression ; H1 (LOW) gate brief checklist ops complétée (warmup cache step 9, invalidation CDN step 10, communication kiosks reload step 12, drill-down validation step 13) ; H2 (LOW) docs/orchestration/V1_PIVOT_STAGING_SMOKETEST_PROCEDURE.md procédure manuelle 5e bullet drill-down ingrédient ajoutée. PHPUnit ciblé V1 41/41 PASS post-healings. Conditions GO_WITH_CONDITIONS satisfaites côté code/doctrine ; reste : smoketest staging humain (script bash scripts/v1-pivot-staging-smoketest.sh) à exécuter avant 1er restaurateur réel + GPT final audit codex:final-audit recommandé non bloquant (D1 medium audit indépendant). V1 = PRÊT PROD FONCTIONNEL. Backlog V1.5c+ : healing E1 sémantique drill-down addon par role + healing F3 smoketest rollback post-data + R-T7 preview admin studio branchId + cleanup AGENT_ACTIVITY_LOG orphan starts + OpenAPI doc routes admin ingredients + asymétrie C1 strict vs lax is_available."}
{"name":"AUDIT TECHNIQUE FINAL V1 + 5 healings post-audit (A2/A4/F3/H3/F4) — 2026-05-04","source":"text","source_description":"audit terminal Claude technique final 2026-05-04 ~20:00 + healings appliqués 20:00-20:10 UTC+2 sur tests/js/labelKeyParityFrontend.spec.js + database/seeders/IngredientPermissionSeeder.php + docs/gates/GATE_CV1-V1-PIVOT-PRODUCTION-CUTOVER_2026-05-04.md","episode_body":"Audit technique final V1 FoodKing post-healings 2026-05-04 ~20:00 UTC+2 par Claude terminal indépendant — verdict PASS_WITH_HEALING / GO_WITH_CONDITIONS, 0 finding CRITICAL, 2 HIGH (F4 closures route:cache + D2 partial rollback hybride), 9 MEDIUM, 10 LOW. F4 finding réfuté par test local 2026-05-04 19:55 : php artisan route:cache PASSE sur ce repo (Laravel 9+ Opis Closure sérialise les closures routes/api.php:141 /login + :196 /authcheck) — finding HIGH dégradé en MEDIUM théorique non bloquant. D2 partial rollback inchangé (déjà documenté gate brief). 5 healings appliqués 2026-05-04 20:00-20:10 UTC+2 : (1) A2 sentinelle G1 regex tests/js/labelKeyParityFrontend.spec.js élargie de \\) à [,)] capturant désormais $t('key', { params }) — couvre label.ingredient.usage_count (V1.5b), studio.products_count, studio.daily_quota_hint, studio.composer_drawer_title, label.composer.preview_required_n, label.composer.preview_min_max ; (2) A4 gate brief checklist warmup cache déplacé d'étape 9 (avant up) à étape 11 (après up — sinon hits HTTP retournent 503 maintenance et n'amorcent rien) ; (3) F3 gate brief étape 8 ajoute npm ci avant npm run prod (sur env vierge node_modules absent → fail sans ce préfixe) ; (4) H3 IngredientPermissionSeeder::run() ajoute app(PermissionRegistrar::class)->forgetCachedPermissions() en fin de méthode pour invalider le cache Spatie post-création de la permission ingredients_manage (sinon 403 transitoire sur /admin/ingredients) ; (5) F4 gate brief documente test local route:cache OK + procédure fallback si Laravel future divergent. A7/A8 doctrine : note explicite ajoutée au gate brief précisant que la case [x] Approved a été cochée par orchestrator avec transcription humaine (preuves traçables terminals/1.txt:30-32 + 36-38 et chat 19:43 UTC+2), recommandation Kossay co-signe par commit pour fermer doctrine stricte ; statut actuel = option pragmatique active. Tests post-healings : Vitest 1162/1162 PASS (193 fichiers, 0 régression), PHPUnit V1 ciblé 41/41 PASS (Ingredient + WizardPerItem + ChoiceAvailability + ComposerProfileProjection + IngredientPermissionSeeder via filter), build OK. Confiance technique cutover chez 1er restaurateur réel (env vierge, no legacy data) : 9/10. Conditions BLOCKING avant cutover toutes satisfaites côté code/doctrine. Reste : (a) smoketest staging humain `bash scripts/v1-pivot-staging-smoketest.sh` à exécuter avant 1er resto ; (b) signoff humain optionnel co-signé Kossay pour fermer A7/A8. Backlog V1.5c+ : sentinelle CI sur nouvelles routes composer non gatées (C1 MEDIUM), test parallel WizardPerItemProfileGuard flag_on_allows_step_creation (G2 MEDIUM), PHPDoc branchId asymétrique IngredientService::usageDetailsForGlobalId (E3 MEDIUM), drill-down addon sémantique par role (E4 LOW), preview admin Studio rupture sans branchId (B3/R-T7 LOW), seeder auto-run dans DatabaseSeeder (F5 LOW), test négatif explicite projection (G3 LOW), feedback frontend flag flip mid-session (C3 LOW). Cumulé : 4 masters CV1-V1-PIVOT + V1-FINISH + V1.5-DEBT-CLEANUP + V1.5B-DRILLDOWN-INGREDIENTS CLOSED PASS + 2 audits indépendants Claude terminal post-quota + 5 healings post-final = V1 PRÊT PROD chez 1er restaurateur réel modulo smoketest staging humain."}
{"name":"CV1-V1.5C-SYNC-STOCK-HEAL-MASTER — R3+R1+R2 livrés (broadcast sentinel + SSOT submit sentinel + WS reconnect menu refresh)","source":"text","source_description":"reports/execution/RUN_CV1-V1.5C-SYNC-STOCK-HEAL-MASTER_2026-05-04.md + ultra audit sync/stock HEAL R1-R3","episode_body":"Master V1.5c sync/stock heal : R3 tests/Feature/Config/BroadcastDriverConfiguredTest.php — en prod-like (production/staging via detectEnvironment) assert broadcasting.default ∈ {pusher,ably,redis} et cas négatif log driver doit assertion-fail (documente déploiement silencieux interdit). R1 TRACE : calculateOrder appelle assertOptionsOrderable puis ChoiceAvailabilityResolver::assertSelectionsOrderable (PricingService.php ~109 + ~512-520). Décision NO_OP patch OrderService/FrontendOrderService (frozen) — contrat gelé par SubmitRevalidatesChoiceAvailabilityThroughPricingTest.php (ingredient_rupture même avec stock>0, PricingRequest::forPos identique submit POS). R2 : KioskAppComponent.vue on ws connected dispatch kioskMenu/fetchMenu force:true si branchId ; PosComponent.vue _onWsConnected itemList(1,{overlay:false}). Evidence : php artisan test nouveaux fichiers PASS + npm run production PASS. R4 (full suite audit final + CLOSE ACTIVE_CYCLE) reste pour session suivante si besoin double PASS formel."}
{"name":"E2E MASSIVE AUDIT P0-P5 RUN 20260504_1956 — PHPUnit GO Playwright partial NO-GO","source":"text","source_description":"plans/E2E_MASSIVE_AUDIT_MASTER_2026-05-04.md + reports/e2e-massive/20260504_1956_E2E_MASSIVE/RAPPORT_CONSOLIDE_P0_P5.md","episode_body":"Exécution non-stop demandée 2026-05-04: P0 PHPUnit Config+Order SSOT+Stock+Ingredients = 71 passed 4 skipped (StockMovementIdempotency pending plan). Playwright tests/Playwright sans E2E_BACKEND: 9 pass 9 skip. Avec E2E_BACKEND_AVAILABLE=1 critical-flow: 2 pass 3 fail — ingredient rupture spec no toggleable row (seed/UI), ingredients a11y requires @axe-core/playwright package missing, sidebar cleanup fails .db-sidebar not found after login. Screenshots copied to reports/e2e-massive/20260504_1956_E2E_MASSIVE/screenshots/. P4 OSS P5 cross-surface not automated (no specs). Consolidated RAPPORT_CONSOLIDE_P0_P5.md + manifest.md + antigravity/latest.md pointer. Next: seed admin ingredients with switches, npm i @axe-core/playwright or ALLOW_AXE_SKIP, fix admin sidebar selector or test login path, implement P5 playwright suite per plan."}
{"name":"VOICE-ORDER-ASSIST-V1-20260830 CLOSED PASS — copilot téléphone humain, activation gelée","source":"text","source_description":"plans/PLAN_VOICE-ORDER-ASSIST-V1-20260830_2026-08-30.md + reports/execution/RUN_VOICE-ORDER-ASSIST-V1-20260830_2026-08-30.md + docs/gates/GATE_VOICE-ORDER-ASSIST-V1-20260830_REAL_CALL_2026-08-30.md","episode_body":"Décision durable 2026-08-30 : V1 assistance commandes téléphone = Free Pro SIP vers Asterisk local, Deepgram Flux Multilingual endpoint UE via WebSocket direct, extraction catalogue optionnelle gpt-5.6-luna et déterministe par défaut. L'employé reste l'unique interlocuteur et validateur ; aucun TTS/avatar ni soumission autonome. Consentement explicite par appel avant création du listener RTP/flux Deepgram, aucun audio stocké, transcript texte branch-scoped avec rétention configurable. Pricing backend SSOT et flux phoneOrderSubmit/KDS/encaissement existant inchangés ; OrderService et FrontendOrderService non touchés. Implémentation désactivée par défaut VOICE_ORDER_ENABLED=false, double audit PASS ; activation production reste bloquée par la gate humaine exigeant wording approuvé et appel Free Pro réel avec latence, deux voix, corrections, KDS, isolation et mode dégradé vérifiés."}

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
