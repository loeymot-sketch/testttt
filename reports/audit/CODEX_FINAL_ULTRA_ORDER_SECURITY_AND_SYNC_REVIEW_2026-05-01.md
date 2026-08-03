# Codex — Final Ultra Order Security & Sync Review — 2026-05-01

## Verdict

`FINAL_ULTRA_REVIEW_VERDICT: PASS_READY_FOR_CLAUDE_EXTERNAL_ATTACK_REVIEW`

`SOFTWARE_DECISION: READY_FOR_MANUAL_SOFTWARE_CHECK_THEN_HARDWARE_UAT`

`BLOCKING_SOFTWARE_FINDINGS: NONE`

Cette review finale consolide les reviews precedentes et ajoute une derniere passe adversariale centree sur les parcours de prise de commande caisse et borne: paiement, forge de prix, quote HMAC, branch isolation, KDS, stock, queue number, catalog/composer/wizard, rupture, outbox et persistance.

Position honnete: je ne peux pas garantir qu'un autre auditeur ne trouvera jamais rien. Je peux en revanche dire que les failles qui casseraient le logiciel avant test materiel ont ete attaquees par tests runtime et sentinelles ciblees, et qu'aucun P0/P1/P2 logiciel n'est reste ouvert dans cette derniere passe.

## Scope Final

Surfaces verifiees:

- POS / caisse: creation devis, commande, paiement cash, KDS, statut prepare, fiscal sequence POS.
- Kiosk / borne: catalogue client, composer wizard, quote scellee, commande, paiement carte simule, auto-return, KDS.
- Kiosk cash-at-counter: commande envoyee KDS, paiement comptoir confirme ou annule POS, sequence fiscale seulement au confirm.
- KDS: reception des commandes, affichage composition, transitions controlees, refus des transitions stale/invalides.
- Dashboard central: categorie, produit, photo, variation, extra, addon, composer profile, publication, projection POS/Kiosk.
- Backend data: pricing SSOT, branch isolation, quote HMAC, stock atomique, queue unique, outbox, snapshots immuables.

## Derniere Simulation Runtime Reelle

Commande relancee pendant cette review:

```bash
npx playwright test tests/e2e/global-pos-kiosk-order-trace.spec.js --project=chromium --workers=1 --timeout=240000 --retries=0
```

Resultat: `1 passed`.

Artifact: `reports/antigravity/global-pos-kiosk-order-trace.json`

Dernier run:

- `verdict`: `PASS_GLOBAL_POS_KIOSK_TRACE`
- POS order: `735`, queue `A0001`, total `12.50`, fiscal_sequence_no `26`
- Kiosk order: `736`, queue `A0002`, total `12.50`, TPE simule `PW-GLOBAL-TRACE-TPE-SIM-*`
- KDS: POS recu en `3887 ms`, kiosk recu en `2 ms`, addon visible en `2 ms`
- Statut final: POS `PREPARED`, kiosk `PREPARED`
- Stock apres 2 commandes: item `18`, variation `18`, extra `18`, addon item `18`
- Snapshots: variation + extra + addon conserves sur les deux commandes
- Events: `OrderCreated`, `OrderStatusChanged`
- Nettoyage final DB: `global_items=0`, `global_order_items=0`, `dashboard_items=0`, `dashboard_order_items=0`

## Tests Rejoues Dans Cette Passe Finale

### Preflight

- `bash .cursor/hooks/safety-check.sh` -> PASS
- `git diff --check -- tests/e2e/global-pos-kiosk-order-trace.spec.js reports/audit/CODEX_GLOBAL_POS_KIOSK_SECOND_REVIEW_2026-05-01.md reports/audit/CODEX_GLOBAL_POS_KIOSK_ORDER_TRACE_AUDIT_2026-05-01.md reports/post_execute_latest.log app/Services/Menu/MenuProjectionService.php` -> PASS

### Paiement / Kiosk Card Confirm / Cross-Branch

- `tests/Feature/PaymentConfirmAbilityTest.php` -> 1 pass
- `tests/Feature/PaymentConfirmCrossBranchTest.php` -> 5 pass
- `tests/Feature/PaymentConfirmMachineResolverTest.php` -> 1 pass
- `tests/Feature/Sentinels/PaymentConfirmAbilitySentinelTest.php` -> 1 pass
- `tests/Feature/Sentinels/PaymentConfirmCashOrderSentinelTest.php` -> 1 pass
- `tests/Feature/Sentinels/PaymentConfirmConcurrencySentinelTest.php` -> 1 pass
- `tests/Feature/Sentinels/PaymentConfirmCrossBranchSentinelTest.php` -> 1 pass

Ce que ca prouve:

- Un token non-kiosk ne peut pas confirmer une commande kiosk.
- Une borne ne peut pas confirmer une commande d'une autre branche.
- Une commande cash kiosk ne peut pas etre confirmee comme carte.
- Une reference TPE ne peut pas payer deux commandes.
- Une commande deja payee n'accepte l'idempotence qu'avec la meme reference.
- La branche est resolue depuis la machine kiosk meme si l'utilisateur est global.

### Pricing SSOT / Quote HMAC / Anti-Forgery

- `tests/Feature/Sentinels/PosSubtotalForgerySentinelTest.php` -> 1 pass
- `tests/Feature/QuoteTamperTest.php` -> 3 pass
- `tests/Feature/OrderQuoteHmacKeyRequiredTest.php` -> 2 pass
- `tests/Feature/PosPricingSsotProofTest.php` -> 1 pass
- `tests/Feature/KioskQuoteIntegrityTest.php` -> 2 pass
- `tests/Feature/KioskQuoteTokenRequiredOnCommitTest.php` -> 4 pass
- `tests/Feature/KioskQuoteForgesBranchIdSilentlyOverriddenTest.php` -> 5 pass
- `tests/Feature/QuoteDiscountAuthoritativeTest.php` -> 1 pass
- `tests/Feature/QuoteReplayIdempotencyTest.php` -> 3 pass
- `tests/Feature/QuoteExpirationTest.php` -> 2 pass
- `tests/Feature/QuoteCurrencyOriginTest.php` -> 2 pass

Ce que ca prouve:

- Le frontend ne peut pas imposer `total`, `subtotal`, `discount` ou `branch_id`.
- Le commit POS/kiosk exige un couple `quote_token` + `quote_signature`.
- Le replay modifie, expire ou cross-branch est refuse.
- La remise et la devise viennent du backend.
- L'idempotence du quote replay est controlee.

### Branch Isolation / Symetrie Services / Kiosk State Machine

- `tests/Feature/Branch/OrderBranchIsolationTest.php` -> 1 pass
- `tests/Feature/BranchIsolationTest.php` -> 6 pass
- `tests/Feature/Stock/StockBranchIsolationTest.php` -> 1 pass
- `tests/Feature/Sentinels/OrderListBranchExactnessSentinelTest.php` -> 1 pass
- `tests/Feature/Sentinels/OrderShowBranchGuardSentinelTest.php` -> 1 pass
- `tests/Feature/Sentinels/TransactionBranchExactnessSentinelTest.php` -> 1 pass
- `tests/Feature/Sentinels/OssAdminBranchPolicySentinelTest.php` -> 1 pass
- `tests/Feature/Symmetry/OrderServicesContractTest.php` -> 5 pass
- `tests/Feature/KioskPaymentStateMachineTest.php` -> 5 pass

Ce que ca prouve:

- Les listes, details, transactions, KDS et OSS ne fuient pas entre branches.
- Les filtres `branch_id` sont exacts, pas des matches prefix/substring.
- `OrderService` et `FrontendOrderService` gardent leurs contrats critiques alignes.
- Une commande kiosk carte reste pending avant confirm, puis peut etre promue correctement.
- Une commande kiosk cash part KDS en `PENDING_COUNTER` et reste fiscalement decouplee.

### KDS / Transitions / No-op / Cash-at-Counter Fiscal

- `tests/Feature/KdsExpectedStatusConflictTest.php` -> 3 pass
- `tests/Feature/KdsTransitionWhitelistTest.php` -> 1 pass
- `tests/Feature/Sentinels/KdsExpectedStatusConflictSentinelTest.php` -> 1 pass
- `tests/Feature/Sentinels/KdsTransitionWhitelistSentinelTest.php` -> 1 pass
- `tests/Feature/OrderStatusNoopSideEffectsTest.php` -> 1 pass
- `tests/Feature/Sentinels/OrderStatusNoopSideEffectsSentinelTest.php` -> 1 pass
- `tests/Feature/CleanupVsConfirmRaceTest.php` -> 1 pass
- `tests/Feature/Sentinels/CleanupVsConfirmRaceSentinelTest.php` -> 1 pass
- `tests/Feature/PosCashEndpointSentinelTest.php` -> 1 pass
- `tests/Feature/PosCollectKioskCashRouteTest.php` -> 1 pass
- `tests/Feature/Fiscal/FiscalCashAtCounterLifecycleTest.php` -> 3 pass
- `tests/Feature/Payment/CounterDeferredPaymentLifecycleTest.php` -> 5 pass

Ce que ca prouve:

- KDS refuse les bumps stale via `expected_status`.
- KDS ne peut pas annuler une commande depuis l'ecran cuisine.
- Les no-op de statut ne creent pas d'effets secondaires ou refunds multiples.
- La race cleanup vs payment confirm est refusee et auditee.
- Le cash kiosk est collecte par route POS dediee.
- `fiscal_sequence_no` reste `NULL` a la creation kiosk cash, est alloue seulement au confirm POS, reste absent au cancel, et le confirm est idempotent.

### Catalog / Composer / Photo / Projection / Outbox

- `tests/Feature/Services/Menu/MenuProjectionServiceTest.php` -> 13 pass
- `tests/Feature/Services/Menu/MenuProjectionComposerProfileTest.php` -> 5 pass
- `tests/Feature/Services/Menu/MenuProjectionParitySentinelTest.php` -> 6 pass
- `tests/Feature/Menu/CatalogStockCentralSyncEndToEndTest.php` -> 1 pass
- `tests/Feature/Menu/CatalogMutationSnapshotCoverageTest.php` -> 3 pass
- `tests/Feature/Menu/ItemImageCatalogRefreshTest.php` -> 1 pass
- `tests/Feature/Catalog/PhotoEndToEndKioskInvalidationTest.php` -> 1 pass
- `tests/Feature/Catalog/CatalogChangedDispatchTest.php` -> 2 pass
- `tests/Feature/Catalog/CatalogOutboxIdempotencyTest.php` -> 1 pass
- `tests/Feature/Catalog/CentralManagementAuthzMatrixTest.php` -> 3 pass
- `tests/Feature/Catalog/ProductPhotoAuthzTest.php` -> 1 pass
- `tests/Feature/Http/Admin/MenuProjectionControllerTest.php` -> 9 pass
- `tests/Feature/KDS/KdsSnapshotImmutableTest.php` -> 4 pass
- `tests/Feature/Outbox/OutboxProductionLikeSimulationTest.php` -> 5 pass
- `tests/Feature/Outbox/OutboxConcurrentWorkerDedupeTest.php` -> 9 pass
- `tests/Feature/OutboxRescueTest.php` -> 2 pass

Ce que ca prouve:

- Le catalogue central projette POS et kiosk sans drift d'identite, prix, disponibilite ou composition.
- Les composer profiles publies pilotent POS/kiosk, avec contraintes de selection et rupture variation/extra/addon.
- Les modifications variation/extra/addon/photo emettent les refresh catalogues attendus.
- L'upload photo invalide bien le menu kiosk et change le snapshot.
- Les droits dashboard catalog/photo sont bornes par role.
- Les outbox events sont idempotents, rescuables, et ne diffusent pas de payload invalide.
- Les commandes deja prises conservent leur snapshot meme si le catalogue change ensuite.

### Stock / Queue / Prod-like Concurrency

- `tests/Feature/Stock/StockDecrementOrderServiceTest.php` -> 2 pass
- `tests/Feature/Stock/StockDecrementFrontendOrderServiceTest.php` -> 1 pass
- `tests/Feature/Stock/StockRuptureAvailabilitySyncTest.php` -> 3 pass
- `tests/Feature/QueueNumberConcurrencyTest.php` -> 5 pass
- `tests/Feature/Sentinels/QueueNumberUniquenessSentinelTest.php` -> 1 pass
- `tests/Feature/ProdLike/ProdLikeConcurrencyTest.php` sur base MySQL temporaire + Redis -> 2 pass

Commande prod-like finale:

```bash
APP_ENV=testing DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3306 DB_DATABASE=fk_codex_prodlike_<temp> DB_USERNAME=root DB_PASSWORD= CACHE_DRIVER=redis REDIS_CLIENT=predis php artisan test tests/Feature/ProdLike/ProdLikeConcurrencyTest.php --stop-on-failure
```

Resultat: 2 pass, base temporaire supprimee ensuite.

Ce que ca prouve:

- 50 workers concurrents sur stock: 20 succes, 30 rejets, pas de stock negatif.
- 50 workers concurrents POS/kiosk sur queue: sequence unique `A0001` a `A0050`.
- Les locks Redis/MySQL protegent les deux zones critiques qui ne sont pas prouvables correctement en SQLite.

## Total Derniere Passe

- PHPUnit/Pest cible: `159` tests/methods PASS dans cette passe finale.
- Playwright global POS+kiosk+KDS: `1` test PASS.
- Safety check: PASS.
- DB fixtures finales: nettoyees.
- Serveur local: arrete apres test.
- Base MySQL temporaire prod-like: supprimee.

## Invariants FoodKing Revalides

| Invariant | Verdict | Preuve principale |
| --- | --- | --- |
| Backend pricing SSOT | PASS | Quote HMAC + PricingService + anti-forge POS/kiosk |
| Pas de prix frontend autoritaire | PASS | Tests forge total/subtotal/discount + commit quote scelle |
| OrderStatus enum/state machine | PASS | KDS transition whitelist, expected status, kiosk state machine |
| branch_id isolation | PASS | Order list/show/KDS/OSS/transactions/payment confirm/catalog projection |
| Dispatch after DB commit / outbox | PASS | Outbox production-like + dedupe + rescue + domain events in global trace |
| OrderService / FrontendOrderService symmetry | PASS | Contract tests + runtime POS/kiosk same fixture |
| Stock atomique | PASS | lockForUpdate + prod-like MySQL/Redis 50 workers |
| Queue unique | PASS | DB unique sentinel + prod-like POS/kiosk 50 workers |
| Fiscal cash-at-counter | PASS | no sequence at kiosk create, sequence only at POS confirm, cancel safe |
| Snapshot immuable | PASS | KDS snapshot tests + global trace composition_snapshot |

## Fichiers Critiques A Donner A Claude

### Rapports Codex / Claude existants

- `reports/audit/CODEX_FINAL_ULTRA_ORDER_SECURITY_AND_SYNC_REVIEW_2026-05-01.md`
- `reports/audit/CODEX_GLOBAL_POS_KIOSK_SECOND_REVIEW_2026-05-01.md`
- `reports/audit/CODEX_GLOBAL_POS_KIOSK_ORDER_TRACE_AUDIT_2026-05-01.md`
- `reports/audit/CODEX_FINAL_SYNC_SECURITY_SOFTWARE_AUDIT_BEFORE_HARDWARE_UAT_2026-04-30.md`
- `reports/audit/CODEX_VERSION_A_SYSTEM_SOFTWARE_FINAL_CLOSE_2026-04-30.md`
- `reports/audit/CODEX_VA_SYS_10_FINAL_MASSIVE_VALIDATION_2026-04-30.md`
- `reports/audit/_CENTRAL_SYNC_ORCHESTRATION_CLAUDE_AUDIT_PROMPT_2026-04-30.txt`
- `reports/antigravity/global-pos-kiosk-order-trace.json`

### Tests runtime / E2E

- `tests/e2e/global-pos-kiosk-order-trace.spec.js`
- `tests/e2e/central-management-dashboard-crud.spec.js`
- `tests/e2e/c3-runtime-multi-surface.spec.js`
- `tests/e2e/design/kiosk/d1-kiosk-design-audit.spec.js`
- `tests/e2e/design/pos/d2-pos-design-audit.spec.js`
- `tests/e2e/design/kds/d3-kds-oss-design-audit.spec.js`
- `tests/e2e/kiosk-post-payment-auto-return.spec.js`
- `tests/e2e/kiosk-full-process/`
- `tests/e2e/pos-full-process/`

### Tests backend prioritaires

- `tests/Feature/ProdLike/ProdLikeConcurrencyTest.php`
- `tests/Feature/PaymentConfirmAbilityTest.php`
- `tests/Feature/PaymentConfirmCrossBranchTest.php`
- `tests/Feature/PaymentConfirmMachineResolverTest.php`
- `tests/Feature/QuoteTamperTest.php`
- `tests/Feature/KioskQuoteIntegrityTest.php`
- `tests/Feature/KioskQuoteTokenRequiredOnCommitTest.php`
- `tests/Feature/KioskQuoteForgesBranchIdSilentlyOverriddenTest.php`
- `tests/Feature/OrderQuoteHmacKeyRequiredTest.php`
- `tests/Feature/PosPricingSsotProofTest.php`
- `tests/Feature/Payment/CounterDeferredPaymentLifecycleTest.php`
- `tests/Feature/Fiscal/FiscalCashAtCounterLifecycleTest.php`
- `tests/Feature/QueueNumberConcurrencyTest.php`
- `tests/Feature/Sentinels/QueueNumberUniquenessSentinelTest.php`
- `tests/Feature/Stock/StockDecrementOrderServiceTest.php`
- `tests/Feature/Stock/StockDecrementFrontendOrderServiceTest.php`
- `tests/Feature/Stock/StockRuptureAvailabilitySyncTest.php`
- `tests/Feature/Services/Menu/MenuProjectionServiceTest.php`
- `tests/Feature/Services/Menu/MenuProjectionComposerProfileTest.php`
- `tests/Feature/Services/Menu/MenuProjectionParitySentinelTest.php`
- `tests/Feature/Menu/CatalogStockCentralSyncEndToEndTest.php`
- `tests/Feature/Menu/CatalogMutationSnapshotCoverageTest.php`
- `tests/Feature/Menu/ItemImageCatalogRefreshTest.php`
- `tests/Feature/Catalog/PhotoEndToEndKioskInvalidationTest.php`
- `tests/Feature/Catalog/CentralManagementAuthzMatrixTest.php`
- `tests/Feature/Outbox/OutboxProductionLikeSimulationTest.php`
- `tests/Feature/Outbox/OutboxConcurrentWorkerDedupeTest.php`
- `tests/Feature/KDS/KdsSnapshotImmutableTest.php`
- `tests/Feature/Symmetry/OrderServicesContractTest.php`

### Code critique

- `app/Services/OrderService.php`
- `app/Services/FrontendOrderService.php`
- `app/Services/Order/OrderQuoteService.php`
- `app/Services/Pricing/PricingService.php`
- `app/Services/Pricing/CompositionSnapshotBuilder.php`
- `app/Services/PaymentService.php`
- `app/Domain/Order/PaymentStateMachine.php`
- `app/Domain/Order/OrderStateMachine.php`
- `app/Services/Stock/StockService.php`
- `app/Services/Menu/MenuProjectionService.php`
- `app/Services/Kiosk/KioskMenuService.php`
- `app/Services/KitchenDisplaySystemOrderService.php`
- `app/Services/OrderStatusScreenOrderService.php`
- `app/Http/Controllers/Admin/PosOrderController.php`
- `app/Http/Controllers/Frontend/OrderController.php`
- `app/Http/Controllers/Frontend/PaymentController.php`
- `app/Http/Controllers/Admin/KitchenDisplaySystemController.php`
- `app/Http/Controllers/Admin/MenuProjectionController.php`
- `app/Http/Requests/Frontend/PaymentConfirmRequest.php`
- `app/Models/OrderQuote.php`
- `database/migrations/2026_04_25_190000_create_order_quotes_table.php`
- `database/migrations/2026_04_26_213800_add_unique_branch_queue_number_to_orders.php`

### Frontend critique

- `resources/js/components/admin/pos/PosComponent.vue`
- `resources/js/components/admin/pos/ItemComponent.vue`
- `resources/js/components/admin/pos/PaymentComponent.vue`
- `resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue`
- `resources/js/components/frontend/kiosk/KioskCategoriesComponent.vue`
- `resources/js/components/frontend/kiosk/KioskWizardComponent.vue`
- `resources/js/components/frontend/kiosk/KioskPosWizardComponent.vue`
- `resources/js/components/frontend/kiosk/KioskPaymentComponent.vue`
- `resources/js/components/frontend/kiosk/KioskWaitingComponent.vue`
- `resources/js/components/frontend/kiosk/KioskConfirmationComponent.vue`
- `resources/js/store/modules/kds.js`
- `resources/js/store/modules/kioskMenu.js`
- `resources/js/store/modules/kioskCart.js`
- `resources/js/store/modules/posOrder.js`
- `resources/js/services/KdsSyncService.js`

## Synthese Fonctionnelle Approuvee

| Fonctionnalite | Statut | Niveau de preuve |
| --- | --- | --- |
| Commande caisse complete | APPROVED | Runtime global + backend POS tests |
| Commande borne carte simulee complete | APPROVED | Runtime global + kiosk quote/payment tests |
| Commande borne cash comptoir | APPROVED | Feature fiscal/payment + POS counter collect |
| KDS reception et preparation | APPROVED | Runtime global + C3 + transition sentinels |
| Stock produit/variation/extra/addon | APPROVED | Runtime global + stock feature + prod-like 50 workers |
| Queue number cross POS/kiosk | APPROVED | Runtime global + queue feature + prod-like 50 workers |
| Composer wizard modifiable | APPROVED | Projection composer + dashboard CRUD + JS wizard tests precedents |
| Rupture produit et choix wizard | APPROVED | Stock rupture + projection choice availability + UX tests precedents |
| Photo produit -> kiosk refresh | APPROVED | Photo E2E invalidation + catalog refresh |
| Dashboard gestion restaurateur | APPROVED | `central-management-dashboard-crud.spec.js` precedent + authz tests |
| Outbox/realtime resilience | APPROVED_LOCAL | Outbox prod-like + C3 runtime local |
| Design Kiosk/POS/KDS/OSS | APPROVED | D1/D2/D3 design audits precedents |

## Ce Qui Reste Hors Logiciel Local

Pas de correction logicielle bloquante trouvee. Les validations restantes sont materiel/provider:

- TPE reel: succes, refus, timeout, doublon reference provider.
- Imprimante fiscale/recu reel: impression, reprint non-fiscal, coupure imprimante.
- Kiosk OS lockdown physique: URL bar, F5, alt-tab, redemarrage Windows.
- KDS ecran reel: lisibilite badge, latence reseau local, reconnect.
- Google Maps live: quota, latence, adresse ambiguë, provider down.
- Supervision production: queue worker, Redis, logs outbox, alertes.

## Conclusion

Le logiciel est en etat `PASS` pour ta verification manuelle puis Hardware UAT.

Je recommande de donner le prompt `reports/audit/_CLAUDE_FINAL_ULTRA_REVIEW_PROMPT_2026-05-01.txt` a Claude comme audit externe d'attaque. Claude doit essayer de prouver le contraire avec fichiers et lignes. Si Claude ne trouve que des points hardware/provider ou polish documentaire, le logiciel peut passer au test sur machines reelles.

