# FoodKing — Passation Finale Pour Claude — Trajectoire Complete + Demande D'Audit Externe

Date: 2026-05-01  
Auteur: Codex  
Destinataire: Claude Opus / audit externe adversarial  
Statut Codex: `PASS_READY_FOR_CLAUDE_EXTERNAL_ATTACK_REVIEW`

## 1. Objectif De Cette Passation

Ce document est le dossier unique a donner a Claude pour qu'il audite toute la trajectoire recente FoodKing:

- prise de commande POS / caisse;
- prise de commande kiosk / borne;
- KDS / OSS / realtime;
- synchronisation centrale des donnees;
- dashboard gestion produits, categories, photos, stock, composer wizard;
- securite pricing / paiement / branch isolation;
- stock, queue number, fiscal cash-at-counter;
- tests massifs et rapports produits pendant la boucle Codex.

Claude ne doit pas faire confiance aux conclusions Codex. Il doit relire les rapports, verifier les fichiers critiques, attaquer les assumptions, puis sortir un verdict `PASS` ou `REWORK`.

## 2. Verdict Codex A Challenger

Verdict actuel Codex:

```text
FINAL_ULTRA_REVIEW_VERDICT: PASS_READY_FOR_CLAUDE_EXTERNAL_ATTACK_REVIEW
SOFTWARE_DECISION: READY_FOR_MANUAL_SOFTWARE_CHECK_THEN_HARDWARE_UAT
BLOCKING_SOFTWARE_FINDINGS: NONE
```

Position precise:

- Le logiciel local est considere pret pour verification manuelle.
- Le hardware/provider UAT reste obligatoire avant production reelle.
- Les appareils reels ne sont pas couverts par le PASS logiciel: TPE, imprimante fiscale, kiosk OS lockdown, Google Maps live, ecrans physiques KDS, coupures reseau physiques.

## 3. Resume De La Trajectoire

### Phase B — Product Composer / Catalogue / Stock / POS-Kiosk Sync

Objectif: centraliser la composition produit et aligner dashboard, POS, kiosk, KDS et stock.

Points fermes:

- B0: hotfix pricing SSOT / delivery / cast order_type / kiosk lockdown.
- B2: schema composer + stock + addon roles.
- B3: dashboard composer write + authz minimal.
- B4: runtime wizard migration, composer profile prioritaire, fallback legacy seulement sans profil publie.
- B5a: Stock V2 atomique, decrement POS et kiosk, rupture et release.
- B5b: cash-at-counter lifecycle, fiscal sequence allouee seulement au confirm POS.
- B6: catalog eventing + photo E2E.
- B7: kiosk lockdown / bundle hygiene.
- B8: delivery/maps hardening, 422 geocode fail.
- B9: E2E hardware signoff local, hardware reste a signer humainement.
- B-FIX-1: branch isolation sur ComposerStepController.
- B-FIX-2: cancel-before-confirm + tests rupture UX.

### Phase C/D — Process Massif Et Design

Objectif: prouver les flows utilisateur et la qualite visuelle.

Points fermes:

- C0: kiosk post-payment auto-return apres paiement simule.
- C1: kiosk full process.
- C2: POS full process.
- C3: multi-surface runtime sync POS/Kiosk/KDS/OSS local.
- C4/C5: stock et queue concurrency, incluant prod-like MySQL/Redis.
- C6: fiscal/outbox/persistence cash-at-counter.
- C9: dashboard gestion catalogue/composer.
- D1: design kiosk.
- D2: design POS.
- D3: design KDS/OSS.

### Phase VA-SYS — Centralisation Et Synchronisation Systeme

Objectif: finir le coeur logiciel avant materiel.

Points fermes:

- VA-SYS-00: scope software-only verrouille, hardware/provider reporte.
- VA-SYS-01: discovery dashboard/workflows/selectors.
- VA-SYS-02: composer contract hardening.
- VA-SYS-03: wizard runtime contract.
- VA-SYS-04: hooks dashboard management.
- VA-SYS-05: central management E2E runtime.
- VA-SYS-06: stockable product/choice rupture: produit, variation, extra, addon.
- VA-SYS-07/07B: authz dashboard/catalog/photo/composer.
- VA-SYS-08: outbox/realtime resilience + C3 runtime.
- VA-SYS-09: documentation API vs MCP et central sync.
- VA-SYS-10: validation massive finale.

### Reviews Finales Codex

Objectif: fermer les doutes restants avant audit externe.

- Review sync/security du 2026-04-30: `PASS_SOFTWARE_READY_FOR_HARDWARE_UAT`, avec P2/P3 ensuite fermes.
- P2 dashboard CRUD navigateur: ferme par `central-management-dashboard-crud.spec.js`.
- P3 commentaire `MenuProjectionService`: corrige.
- Global POS/kiosk order trace: runtime POS + kiosk + KDS + backend audit.
- Second review: re-runs Playwright, backend, design, vitest, cleanup DB.
- Final ultra review: passe adversariale securite/sync/paiement/stock/queue/catalog/outbox.

## 4. Rapports A Lire Dans Cet Ordre

Claude doit lire ces rapports avant de rendre verdict:

1. `reports/audit/CODEX_FINAL_ULTRA_ORDER_SECURITY_AND_SYNC_REVIEW_2026-05-01.md`
2. `reports/audit/CODEX_GLOBAL_POS_KIOSK_SECOND_REVIEW_2026-05-01.md`
3. `reports/audit/CODEX_GLOBAL_POS_KIOSK_ORDER_TRACE_AUDIT_2026-05-01.md`
4. `reports/audit/CODEX_FINAL_SYNC_SECURITY_SOFTWARE_AUDIT_BEFORE_HARDWARE_UAT_2026-04-30.md`
5. `reports/audit/CODEX_VERSION_A_SYSTEM_SOFTWARE_FINAL_CLOSE_2026-04-30.md`
6. `reports/audit/CODEX_VA_SYS_10_FINAL_MASSIVE_VALIDATION_2026-04-30.md`
7. `reports/antigravity/global-pos-kiosk-order-trace.json`
8. `reports/post_execute_latest.log`
9. `reports/AGENT_ACTIVITY_LOG.md`

Prompt court deja prepare, si Claude doit recevoir une version ciblee:

- `reports/audit/_CLAUDE_FINAL_ULTRA_REVIEW_PROMPT_2026-05-01.txt`

## 5. Preuves Runtime Principales

### Dernier test global POS + Kiosk + KDS

Test:

```bash
npx playwright test tests/e2e/global-pos-kiosk-order-trace.spec.js --project=chromium --workers=1 --timeout=240000 --retries=0
```

Resultat: `1 passed`.

Artifact:

- `reports/antigravity/global-pos-kiosk-order-trace.json`

Dernier run trace:

- POS order: `735`
- Kiosk order: `736`
- POS queue: `A0001`
- Kiosk queue: `A0002`
- POS total: `12.50`
- Kiosk total: `12.50`
- KDS POS recu: `3887 ms`
- KDS kiosk recu: `2 ms`
- Addon visible KDS: `2 ms`
- Status final POS: `PREPARED`
- Status final kiosk: `PREPARED`
- Stock apres 2 commandes:
  - item: `18`
  - variation: `18`
  - extra: `18`
  - addon item: `18`
- DB cleanup final:
  - `global_items=0`
  - `global_order_items=0`
  - `dashboard_items=0`
  - `dashboard_order_items=0`

### Derniere passe backend adversariale

Total final Codex:

- PHPUnit/Pest cible: `159` tests/methods PASS.
- Playwright global POS/kiosk/KDS: `1` PASS.
- Prod-like MySQL/Redis: `2` PASS sur base temporaire dediee, supprimee ensuite.
- Safety check: PASS.
- `git diff --check` cible: PASS.

## 6. Tests Critiques A Relire

### E2E / Runtime

- `tests/e2e/global-pos-kiosk-order-trace.spec.js`
- `tests/e2e/central-management-dashboard-crud.spec.js`
- `tests/e2e/c3-runtime-multi-surface.spec.js`
- `tests/e2e/kiosk-post-payment-auto-return.spec.js`
- `tests/e2e/kiosk-full-process/`
- `tests/e2e/pos-full-process/`
- `tests/e2e/design/kiosk/d1-kiosk-design-audit.spec.js`
- `tests/e2e/design/pos/d2-pos-design-audit.spec.js`
- `tests/e2e/design/kds/d3-kds-oss-design-audit.spec.js`

### Paiement / Fiscal / State Machine

- `tests/Feature/PaymentConfirmAbilityTest.php`
- `tests/Feature/PaymentConfirmCrossBranchTest.php`
- `tests/Feature/PaymentConfirmMachineResolverTest.php`
- `tests/Feature/Sentinels/PaymentConfirmAbilitySentinelTest.php`
- `tests/Feature/Sentinels/PaymentConfirmCashOrderSentinelTest.php`
- `tests/Feature/Sentinels/PaymentConfirmConcurrencySentinelTest.php`
- `tests/Feature/Sentinels/PaymentConfirmCrossBranchSentinelTest.php`
- `tests/Feature/Payment/CounterDeferredPaymentLifecycleTest.php`
- `tests/Feature/Fiscal/FiscalCashAtCounterLifecycleTest.php`
- `tests/Feature/KioskPaymentStateMachineTest.php`

### Pricing / Quote / Anti-Forgery

- `tests/Feature/QuoteTamperTest.php`
- `tests/Feature/OrderQuoteHmacKeyRequiredTest.php`
- `tests/Feature/PosPricingSsotProofTest.php`
- `tests/Feature/KioskQuoteIntegrityTest.php`
- `tests/Feature/KioskQuoteTokenRequiredOnCommitTest.php`
- `tests/Feature/KioskQuoteForgesBranchIdSilentlyOverriddenTest.php`
- `tests/Feature/QuoteDiscountAuthoritativeTest.php`
- `tests/Feature/QuoteReplayIdempotencyTest.php`
- `tests/Feature/QuoteExpirationTest.php`
- `tests/Feature/QuoteCurrencyOriginTest.php`
- `tests/Feature/Sentinels/PosSubtotalForgerySentinelTest.php`

### Stock / Queue / Concurrency

- `tests/Feature/ProdLike/ProdLikeConcurrencyTest.php`
- `tests/Feature/Stock/StockDecrementOrderServiceTest.php`
- `tests/Feature/Stock/StockDecrementFrontendOrderServiceTest.php`
- `tests/Feature/Stock/StockRuptureAvailabilitySyncTest.php`
- `tests/Feature/Stock/StockBranchIsolationTest.php`
- `tests/Feature/QueueNumberConcurrencyTest.php`
- `tests/Feature/Sentinels/QueueNumberUniquenessSentinelTest.php`

### Branch Isolation / Authz

- `tests/Feature/Branch/OrderBranchIsolationTest.php`
- `tests/Feature/BranchIsolationTest.php`
- `tests/Feature/Sentinels/OrderListBranchExactnessSentinelTest.php`
- `tests/Feature/Sentinels/OrderShowBranchGuardSentinelTest.php`
- `tests/Feature/Sentinels/TransactionBranchExactnessSentinelTest.php`
- `tests/Feature/Sentinels/OssAdminBranchPolicySentinelTest.php`
- `tests/Feature/Catalog/CentralManagementAuthzMatrixTest.php`
- `tests/Feature/Catalog/ProductPhotoAuthzTest.php`

### Catalog / Composer / Projection / Outbox

- `tests/Feature/Services/Menu/MenuProjectionServiceTest.php`
- `tests/Feature/Services/Menu/MenuProjectionComposerProfileTest.php`
- `tests/Feature/Services/Menu/MenuProjectionParitySentinelTest.php`
- `tests/Feature/Menu/CatalogStockCentralSyncEndToEndTest.php`
- `tests/Feature/Menu/CatalogMutationSnapshotCoverageTest.php`
- `tests/Feature/Menu/ItemImageCatalogRefreshTest.php`
- `tests/Feature/Catalog/PhotoEndToEndKioskInvalidationTest.php`
- `tests/Feature/Catalog/CatalogChangedDispatchTest.php`
- `tests/Feature/Catalog/CatalogOutboxIdempotencyTest.php`
- `tests/Feature/Http/Admin/MenuProjectionControllerTest.php`
- `tests/Feature/KDS/KdsSnapshotImmutableTest.php`
- `tests/Feature/Outbox/OutboxProductionLikeSimulationTest.php`
- `tests/Feature/Outbox/OutboxConcurrentWorkerDedupeTest.php`
- `tests/Feature/OutboxRescueTest.php`
- `tests/Feature/Symmetry/OrderServicesContractTest.php`

## 7. Code Critique A Auditer

### Order / Pricing / Quote

- `app/Services/OrderService.php`
- `app/Services/FrontendOrderService.php`
- `app/Services/Order/OrderQuoteService.php`
- `app/Services/Pricing/PricingService.php`
- `app/Services/Pricing/CompositionSnapshotBuilder.php`
- `app/Models/OrderQuote.php`
- `database/migrations/2026_04_25_190000_create_order_quotes_table.php`

### Paiement / Fiscal / State Machines

- `app/Services/PaymentService.php`
- `app/Domain/Order/PaymentStateMachine.php`
- `app/Domain/Order/OrderStateMachine.php`
- `app/Services/Fiscal/FiscalSequenceService.php`
- `app/Services/Fiscal/AuditLogService.php`
- `app/Services/Fiscal/ZReportService.php`
- `app/Http/Requests/Frontend/PaymentConfirmRequest.php`
- `app/Http/Controllers/Frontend/PaymentController.php`

### Stock / Queue

- `app/Services/Stock/StockService.php`
- `app/Models/StockLevel.php`
- `app/Models/StockMovement.php`
- `database/migrations/2026_04_26_213800_add_unique_branch_queue_number_to_orders.php`
- `scripts/prodlike-concurrency-worker.php`

### Catalog / Composer / Dashboard

- `app/Services/Menu/MenuProjectionService.php`
- `app/Services/Kiosk/KioskMenuService.php`
- `app/Services/Composer/ComposerProfileProjection.php`
- `app/Http/Controllers/Admin/MenuProjectionController.php`
- `app/Http/Controllers/Admin/ComposerProfileController.php`
- `app/Http/Controllers/Admin/ComposerStepController.php`
- `app/Http/Controllers/Admin/ItemController.php`
- `app/Http/Controllers/Admin/ItemCategoryController.php`
- `app/Http/Controllers/Admin/ItemVariationController.php`
- `app/Http/Controllers/Admin/ItemExtraController.php`
- `app/Http/Controllers/Admin/ItemAddonController.php`

### KDS / OSS / Realtime

- `app/Services/KitchenDisplaySystemOrderService.php`
- `app/Services/OrderStatusScreenOrderService.php`
- `app/Http/Controllers/Admin/KitchenDisplaySystemController.php`
- `resources/js/services/KdsSyncService.js`
- `resources/js/store/modules/kds.js`
- `resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue`

### Frontend POS / Kiosk

- `resources/js/components/admin/pos/PosComponent.vue`
- `resources/js/components/admin/pos/ItemComponent.vue`
- `resources/js/components/admin/pos/PaymentComponent.vue`
- `resources/js/store/modules/posOrder.js`
- `resources/js/components/frontend/kiosk/KioskCategoriesComponent.vue`
- `resources/js/components/frontend/kiosk/KioskWizardComponent.vue`
- `resources/js/components/frontend/kiosk/KioskPosWizardComponent.vue`
- `resources/js/components/frontend/kiosk/KioskPaymentComponent.vue`
- `resources/js/components/frontend/kiosk/KioskWaitingComponent.vue`
- `resources/js/components/frontend/kiosk/KioskConfirmationComponent.vue`
- `resources/js/store/modules/kioskMenu.js`
- `resources/js/store/modules/kioskCart.js`

## 8. Questions D'Audit Precises Pour Claude

Claude doit repondre explicitement a ces questions:

1. Une commande POS peut-elle encore forger prix, taxe, remise, total, customer, branch ou quote?
2. Une commande kiosk peut-elle forger branch_id, total, quote, payment_status ou transaction_id?
3. Le couple `quote_token` + `quote_signature` couvre-t-il assez de payload pour empecher tamper, replay et cross-branch?
4. Le paiement kiosk carte simule est-il idempotent et strictement borne a la machine kiosk autorisee?
5. Le cash-at-counter respecte-t-il la regle fiscale: aucune sequence a la creation kiosk, allocation seulement au confirm POS, cancel sans sequence, reprint sans nouvelle sequence?
6. POS et kiosk partagent-ils vraiment la meme sequence `queue_number` par branche/date, meme sous concurrence?
7. Le stock est-il decremente exactement une fois par commande pour item, variation, extra et addon?
8. La rupture produit/variation/extra/addon se propage-t-elle au POS et au kiosk sans modifier les commandes deja prises?
9. KDS peut-il recevoir ou manipuler une commande hors branche?
10. KDS peut-il accepter une transition stale, invalide, ou une annulation depuis cuisine?
11. Les snapshots `composition_snapshot` et affichage KDS restent-ils immuables apres mutation catalogue/photo/stock?
12. Les events/outbox sont-ils emis apres commit, idempotents, recuperables, et sans payload invalide?
13. Le dashboard CRUD navigateur prouve-t-il vraiment categorie -> produit -> photo -> variation -> extra -> addon -> composer profile -> publish -> projection POS/Kiosk/KDS/stock?
14. Les permissions dashboard/catalog/photo/menu projection empechent-elles mutation ou lecture hors role / hors branche?
15. Y a-t-il un chemin legacy dans `OrderService` ou `FrontendOrderService` qui contourne quote/pricing/stock/queue?
16. Les tests Playwright utilisent-ils de vrais appels backend ou des mocks qui masqueraient le risque?
17. Les validations MySQL/Redis sont-elles suffisantes pour les locks stock/queue?
18. Reste-t-il un P0/P1/P2 logiciel qui doit bloquer la verification manuelle?

## 9. Commandes De Verification Recommandees Pour Claude

### Preflight

```bash
bash .cursor/hooks/safety-check.sh
```

### Runtime global

```bash
php artisan serve --host=127.0.0.1 --port=8000
npx playwright test tests/e2e/global-pos-kiosk-order-trace.spec.js --project=chromium --workers=1 --timeout=240000 --retries=0
```

Puis arreter le serveur.

### Backend adversarial minimal

```bash
php artisan test \
  tests/Feature/PaymentConfirmCrossBranchTest.php \
  tests/Feature/QuoteTamperTest.php \
  tests/Feature/KioskQuoteIntegrityTest.php \
  tests/Feature/Payment/CounterDeferredPaymentLifecycleTest.php \
  tests/Feature/Fiscal/FiscalCashAtCounterLifecycleTest.php \
  tests/Feature/QueueNumberConcurrencyTest.php \
  tests/Feature/Stock/StockRuptureAvailabilitySyncTest.php \
  --stop-on-failure
```

### Prod-like MySQL/Redis

Important: ne jamais lancer `migrate:fresh` sur la base principale. Creer une base temporaire:

```bash
db="fk_claude_prodlike_$(date +%s)"
mysql -h127.0.0.1 -uroot -e "CREATE DATABASE \`$db\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
APP_ENV=testing DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3306 DB_DATABASE="$db" DB_USERNAME=root DB_PASSWORD= CACHE_DRIVER=redis REDIS_CLIENT=predis php artisan test tests/Feature/ProdLike/ProdLikeConcurrencyTest.php --stop-on-failure
rc=$?
mysql -h127.0.0.1 -uroot -e "DROP DATABASE IF EXISTS \`$db\`;"
exit $rc
```

## 10. Format De Verdict Demande A Claude

Claude doit rendre exactement ce type de verdict:

```text
AUDIT_VERDICT: PASS | REWORK
RELEASE_DECISION: PASS_TO_MANUAL_SOFTWARE_CHECK_THEN_HARDWARE_UAT | REWORK_BEFORE_MANUAL_CHECK

P0:
- Aucun, ou liste fichier:ligne + risque + correction.

P1:
- Aucun, ou liste fichier:ligne + risque + correction.

P2:
- Aucun, ou liste fichier:ligne + risque + correction.

PREUVES PASS:
- Les fichiers/lignes/tests qui prouvent les invariants.

TESTS A RELANCER:
- Commandes exactes, uniquement si necessaire.

CONCLUSION:
- Si PASS: "Je n'ai pas trouve de bloquant logiciel avant verification manuelle et hardware UAT."
- Si REWORK: plan minimal par ordre de risque.
```

## 11. Prompt Pret A Copier Dans Claude

Depuis la racine du depot:

```bash
claude -p "Lis reports/audit/CLAUDE_FINAL_HANDOFF_FULL_TRAJECTORY_AND_AUDIT_REQUEST_2026-05-01.md, puis execute l'audit externe adversarial demande. Ne fais pas confiance aux verdicts Codex; relis les fichiers critiques, les rapports, les tests, et rends un verdict PASS ou REWORK avec P0/P1/P2, preuves, tests a relancer et decision finale."
```

Si Claude a assez de quota/contexte, version plus forte:

```bash
claude -p "$(cat reports/audit/CLAUDE_FINAL_HANDOFF_FULL_TRAJECTORY_AND_AUDIT_REQUEST_2026-05-01.md)"
```

## 12. Decision Attendues Apres Audit Claude

Si Claude sort `PASS`:

- lancer verification manuelle logicielle;
- ensuite brancher materiel et lancer Hardware UAT;
- production commerciale seulement apres signature hardware/provider.

Si Claude sort `REWORK`:

- traiter uniquement les P0/P1 et les P2 qui touchent securite, paiement, branch isolation, stock, queue ou fiscal;
- relancer les tests cibles;
- refaire audit Codex puis audit Claude.

