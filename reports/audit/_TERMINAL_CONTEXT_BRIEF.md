# Bref contexte FoodKing — alimentation terminal Claude Code

Généré : 2026-08-23T23:43:49+02:00

## .cursor/ACTIVE_CYCLE.md (extrait, 60 premières lignes)
# Active Cycle – FoodKing

**Méta (SSOT `run-cycle.md` Step 0 + `AGENTS.md` § *Authoritative … cycle state*)** — requis pour que l’orchestrateur ne s’arrête pas sur *« RUNNER_MODE not set »*.

| Champ | Valeur actuelle |
| --- | --- |
| **RUNNER_MODE** | `single-session` |
| **PHASE** | `AUDIT` |
| **MASTER_TASK_ID** | |
| **TASK_ID** | `CAISSE-SUPERVISOR-CONTROL-20260823` |
| **PLAN_FILE** | `plans/PLAN_CAISSE-SUPERVISOR-CONTROL-20260823_2026-08-23.md` |
| **REPORT_FILE** | `reports/execution/RUN_CAISSE-SUPERVISOR-CONTROL-20260823_2026-08-23.md` |
| **AUDIT_SOURCE** | `pending` |
| **PARENT_CYCLE** | `GOAL-WHEEL-EXPERIENCE-20260823 parked at human UX gate; not approved or closed` |
| **SUBSYSTEMS_TOUCHED** | `POS system health/offline/a11y, dashboard SLA/date presets, kiosk idle/product keyboard activation, Playwright critical sync harness and safe E2E cleanup` |
| **INVARIANTS_AT_RISK** | `branch_id fiscal health exactness; fail-closed observability; no unsigned offline order replay` |
| **GATE_CONDITIONS** | `Wheel UX gate remains out of scope and pending; stop on frozen fiscal service, migration, pricing, payment or OrderStatus requirement` |
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


## Dernières entrées — memory/episodes/12_decisions_log.jsonl
{"name":"CV1-V1.5B-DRILLDOWN-INGREDIENTS-MASTER CLOSED PASS — drill-down ingrédients UX (E1 backend usage endpoint + E2 frontend drawer enrichi)","source":"text","source_description":"plans/PLAN_CV1-V1.5B-DRILLDOWN-INGREDIENTS-MASTER_2026-05-04.md + 3× reports/execution/RUN_CV1-V1.5B-DRILLDOWN-*_2026-05-04.md + docs/orchestration/cycles/CYCLE_CV1-V1.5B-DRILLDOWN-INGREDIENTS-MASTER_2026-05-04.md","episode_body":"V1.5b drill-down ingrédients livré 2026-05-04 ~17:35 suite parent V1.5 dettes CLOSED PASS. AUDIT_VERDICT PASS via fallback foodking-planner-orchestrator (terminal Claude quota encore down, reset 18h10). Décision orchestrator post-délégation user 2026-05-04 17:08 (\"prends à ma place les bonnes décisions et continue\"). E1 (complex) : nouveau endpoint GET /api/admin/ingredients/{globalId}/usage via IngredientService::usageDetailsForGlobalId (helpers usedByRowsForAttribute|Extra|Addon + mapStepsToUsedBy + sortUsedBy category puis item puis alphabétique owner_name) + IngredientController::usage retournant 404 JSON ou IngredientUsageResource avec used_by détaillé (owner_type category|item, owner_id, owner_name, step_key, step_label, wizard_profile_id, admin_url) + route enregistrée AVANT /{globalId} (Laravel route ordering critical) + permission ingredients_manage + 7 tests PHPUnit. Détail technique : ItemWizardStep::profile() est belongsTo(ItemWizardProfile::class, 'profile_id') et NON 'item_wizard_profile_id' malgré naming Laravel — documenté pour futurs sub-agents. E2 (routine) : IngredientUsageDrawer.vue enrichi (marqueur 'Drill-down différé V1.5' ligne 52 retiré + en-tête nom + badge En rupture si !isAvailable + empty state + ul role='list' avec entrées li cliquables a:href=admin_url avec focus visible Tailwind + 7 data-testid pour E2E + loading/error aria-live='polite') + getIngredientUsage axios + 4 nouvelles clés i18n (label.ingredient.status_unavailable, usage_empty, owner_category, owner_item) × 5 langues (fr/en/de/bn/ar) + 8 tests Vitest. Baselines : PHPUnit 1421→1428 (+7 tests E1), Vitest 1157→1162 (+5 net après nettoyage cache ENOSPC). Invariants I1-I6 respectés. EXECUTE_DELEGATION 2/2. Cohérence : a11y H3 V1-FINISH préservé (focus trap + esc + aria-modal inchangés), i18n parity H2 V1-FINISH élargie 5 dossiers reste verte. Risques résiduels : disque poste local 99% (211 Mi libre, ENOSPC reproduit puis résolu via rm -rf node_modules/.vite /tmp/vitest-*) — ops user, drill-down addon limité 1 owner item (acceptable structure 1/1), tri pas sur step_label (V1 acceptable), GPT final audit cumulé V1 (4 masters) non exécuté car V1.5/V1.5b sans setup mission codex-extension. Cumulé V1-PIVOT+V1-FINISH+V1.5+V1.5b = V1 prêt prod fonctionnel modulo gate humain prod-cutover Q1-Q4. Plan : plans/PLAN_CV1-V1.5B-DRILLDOWN-INGREDIENTS-MASTER_2026-05-04.md."}
{"name":"AUDIT INDÉPENDANT V1 cumulé 4 masters + GATE PRODUCTION CUTOVER APPROVED — 2026-05-04","source":"text","source_description":"audit terminal Claude post-quota indépendant 2026-05-04 ~19:30 + gate brief docs/gates/GATE_CV1-V1-PIVOT-PRODUCTION-CUTOVER_2026-05-04.md approved + GATE_LOG.md entry + healings G1+H1+H2 appliqués 2026-05-04 19:50 UTC+2","episode_body":"Audit indépendant terminal Claude post-quota 2026-05-04 ~19:30 UTC+2 sur 4 masters cumulés V1 (V1-PIVOT + V1-FINISH + V1.5-DEBT-CLEANUP + V1.5B-DRILLDOWN-INGREDIENTS) — verdict PASS_WITH_HEALING / PROD_READINESS GO_WITH_CONDITIONS, 0 finding CRITICAL, 3 HIGH (F1 MySQL<8.0 mitigé Q2=9.6.0 + F2 rollback fragile déjà documenté gate + G1 sentinelle parity multi-préfixe regex), 6 MEDIUM (A2 middleware catch silencieux, A3 ownerType orphan, B1 PHPDoc branchId asymétrique, D1 GPT final audit non exécuté, E1 drill-down addon sémantique limitée, F3 smoketest rollback partiel), 7 LOW. Vérifications 40 points file:line confirmées : 6/6 invariants FoodKing tenus (I1 pricing SSOT, I2 OrderStatus, I3 branch_id mono-filiale V1 documenté, I4 dispatch après commit DispatchableAfterCommit, I5 OrderService symmetry préservée, I6 frozen zones 0 édition depuis 2026-05-04 13:00), middleware EnsureProfileNotItemOwnedUnlessDemoEnabled couvre exactement 6/6 routes shared profile/step (routes/api.php:670-676), fix D1 ChoiceAvailabilityResolver::availabilityForVariation câblé l.63 snapshotForItems + l.144 assertSelectionsOrderable symétrie order-guard, tests sentinelles non tautologiques. GATE_CV1-V1-PIVOT-PRODUCTION-CUTOVER approved 2026-05-04 19:43 UTC+2 par Kossay/user kossayelbenna8 (réponses chat) + transcription Claude orchestrator : Q1=A cohabitation transitoire, Q2 MySQL 9.6.0 ≥ 8.0 vérifié SSH local terminals/1.txt:30-32 mitigeant finding HIGH F1, Q3 ASAP no-prod-yet (1er resto sera déploiement initial — pas de fenêtre maintenance imposée), Q4 .env ABSENT default false vérifié terminals/1.txt:36-38. GATE_LOG.md ligne ajoutée avec audit trail complet. Healings appliqués post-approval 2026-05-04 19:50 UTC+2 : G1 (HIGH BLOCKING) tests/js/labelKeyParityFrontend.spec.js regex étendue de label.* à 5 préfixes (label, studio, message, demo_wizard_advanced, menu) via TRACKED_PREFIXES array — sentinelle PASS, 1162/1162 Vitest 0 régression ; H1 (LOW) gate brief checklist ops complétée (warmup cache step 9, invalidation CDN step 10, communication kiosks reload step 12, drill-down validation step 13) ; H2 (LOW) docs/orchestration/V1_PIVOT_STAGING_SMOKETEST_PROCEDURE.md procédure manuelle 5e bullet drill-down ingrédient ajoutée. PHPUnit ciblé V1 41/41 PASS post-healings. Conditions GO_WITH_CONDITIONS satisfaites côté code/doctrine ; reste : smoketest staging humain (script bash scripts/v1-pivot-staging-smoketest.sh) à exécuter avant 1er restaurateur réel + GPT final audit codex:final-audit recommandé non bloquant (D1 medium audit indépendant). V1 = PRÊT PROD FONCTIONNEL. Backlog V1.5c+ : healing E1 sémantique drill-down addon par role + healing F3 smoketest rollback post-data + R-T7 preview admin studio branchId + cleanup AGENT_ACTIVITY_LOG orphan starts + OpenAPI doc routes admin ingredients + asymétrie C1 strict vs lax is_available."}
{"name":"AUDIT TECHNIQUE FINAL V1 + 5 healings post-audit (A2/A4/F3/H3/F4) — 2026-05-04","source":"text","source_description":"audit terminal Claude technique final 2026-05-04 ~20:00 + healings appliqués 20:00-20:10 UTC+2 sur tests/js/labelKeyParityFrontend.spec.js + database/seeders/IngredientPermissionSeeder.php + docs/gates/GATE_CV1-V1-PIVOT-PRODUCTION-CUTOVER_2026-05-04.md","episode_body":"Audit technique final V1 FoodKing post-healings 2026-05-04 ~20:00 UTC+2 par Claude terminal indépendant — verdict PASS_WITH_HEALING / GO_WITH_CONDITIONS, 0 finding CRITICAL, 2 HIGH (F4 closures route:cache + D2 partial rollback hybride), 9 MEDIUM, 10 LOW. F4 finding réfuté par test local 2026-05-04 19:55 : php artisan route:cache PASSE sur ce repo (Laravel 9+ Opis Closure sérialise les closures routes/api.php:141 /login + :196 /authcheck) — finding HIGH dégradé en MEDIUM théorique non bloquant. D2 partial rollback inchangé (déjà documenté gate brief). 5 healings appliqués 2026-05-04 20:00-20:10 UTC+2 : (1) A2 sentinelle G1 regex tests/js/labelKeyParityFrontend.spec.js élargie de \\) à [,)] capturant désormais $t('key', { params }) — couvre label.ingredient.usage_count (V1.5b), studio.products_count, studio.daily_quota_hint, studio.composer_drawer_title, label.composer.preview_required_n, label.composer.preview_min_max ; (2) A4 gate brief checklist warmup cache déplacé d'étape 9 (avant up) à étape 11 (après up — sinon hits HTTP retournent 503 maintenance et n'amorcent rien) ; (3) F3 gate brief étape 8 ajoute npm ci avant npm run prod (sur env vierge node_modules absent → fail sans ce préfixe) ; (4) H3 IngredientPermissionSeeder::run() ajoute app(PermissionRegistrar::class)->forgetCachedPermissions() en fin de méthode pour invalider le cache Spatie post-création de la permission ingredients_manage (sinon 403 transitoire sur /admin/ingredients) ; (5) F4 gate brief documente test local route:cache OK + procédure fallback si Laravel future divergent. A7/A8 doctrine : note explicite ajoutée au gate brief précisant que la case [x] Approved a été cochée par orchestrator avec transcription humaine (preuves traçables terminals/1.txt:30-32 + 36-38 et chat 19:43 UTC+2), recommandation Kossay co-signe par commit pour fermer doctrine stricte ; statut actuel = option pragmatique active. Tests post-healings : Vitest 1162/1162 PASS (193 fichiers, 0 régression), PHPUnit V1 ciblé 41/41 PASS (Ingredient + WizardPerItem + ChoiceAvailability + ComposerProfileProjection + IngredientPermissionSeeder via filter), build OK. Confiance technique cutover chez 1er restaurateur réel (env vierge, no legacy data) : 9/10. Conditions BLOCKING avant cutover toutes satisfaites côté code/doctrine. Reste : (a) smoketest staging humain `bash scripts/v1-pivot-staging-smoketest.sh` à exécuter avant 1er resto ; (b) signoff humain optionnel co-signé Kossay pour fermer A7/A8. Backlog V1.5c+ : sentinelle CI sur nouvelles routes composer non gatées (C1 MEDIUM), test parallel WizardPerItemProfileGuard flag_on_allows_step_creation (G2 MEDIUM), PHPDoc branchId asymétrique IngredientService::usageDetailsForGlobalId (E3 MEDIUM), drill-down addon sémantique par role (E4 LOW), preview admin Studio rupture sans branchId (B3/R-T7 LOW), seeder auto-run dans DatabaseSeeder (F5 LOW), test négatif explicite projection (G3 LOW), feedback frontend flag flip mid-session (C3 LOW). Cumulé : 4 masters CV1-V1-PIVOT + V1-FINISH + V1.5-DEBT-CLEANUP + V1.5B-DRILLDOWN-INGREDIENTS CLOSED PASS + 2 audits indépendants Claude terminal post-quota + 5 healings post-final = V1 PRÊT PROD chez 1er restaurateur réel modulo smoketest staging humain."}
{"name":"CV1-V1.5C-SYNC-STOCK-HEAL-MASTER — R3+R1+R2 livrés (broadcast sentinel + SSOT submit sentinel + WS reconnect menu refresh)","source":"text","source_description":"reports/execution/RUN_CV1-V1.5C-SYNC-STOCK-HEAL-MASTER_2026-05-04.md + ultra audit sync/stock HEAL R1-R3","episode_body":"Master V1.5c sync/stock heal : R3 tests/Feature/Config/BroadcastDriverConfiguredTest.php — en prod-like (production/staging via detectEnvironment) assert broadcasting.default ∈ {pusher,ably,redis} et cas négatif log driver doit assertion-fail (documente déploiement silencieux interdit). R1 TRACE : calculateOrder appelle assertOptionsOrderable puis ChoiceAvailabilityResolver::assertSelectionsOrderable (PricingService.php ~109 + ~512-520). Décision NO_OP patch OrderService/FrontendOrderService (frozen) — contrat gelé par SubmitRevalidatesChoiceAvailabilityThroughPricingTest.php (ingredient_rupture même avec stock>0, PricingRequest::forPos identique submit POS). R2 : KioskAppComponent.vue on ws connected dispatch kioskMenu/fetchMenu force:true si branchId ; PosComponent.vue _onWsConnected itemList(1,{overlay:false}). Evidence : php artisan test nouveaux fichiers PASS + npm run production PASS. R4 (full suite audit final + CLOSE ACTIVE_CYCLE) reste pour session suivante si besoin double PASS formel."}
{"name":"E2E MASSIVE AUDIT P0-P5 RUN 20260504_1956 — PHPUnit GO Playwright partial NO-GO","source":"text","source_description":"plans/E2E_MASSIVE_AUDIT_MASTER_2026-05-04.md + reports/e2e-massive/20260504_1956_E2E_MASSIVE/RAPPORT_CONSOLIDE_P0_P5.md","episode_body":"Exécution non-stop demandée 2026-05-04: P0 PHPUnit Config+Order SSOT+Stock+Ingredients = 71 passed 4 skipped (StockMovementIdempotency pending plan). Playwright tests/Playwright sans E2E_BACKEND: 9 pass 9 skip. Avec E2E_BACKEND_AVAILABLE=1 critical-flow: 2 pass 3 fail — ingredient rupture spec no toggleable row (seed/UI), ingredients a11y requires @axe-core/playwright package missing, sidebar cleanup fails .db-sidebar not found after login. Screenshots copied to reports/e2e-massive/20260504_1956_E2E_MASSIVE/screenshots/. P4 OSS P5 cross-surface not automated (no specs). Consolidated RAPPORT_CONSOLIDE_P0_P5.md + manifest.md + antigravity/latest.md pointer. Next: seed admin ingredients with switches, npm i @axe-core/playwright or ALLOW_AXE_SKIP, fix admin sidebar selector or test login path, implement P5 playwright suite per plan."}

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
