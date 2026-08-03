# Prompt Claude Terminal — Super Audit + Orchestration Finale FoodKing — 2026-04-28

Tu es Claude Opus en mode audit/orchestration maximum. Je veux une analyse tres profonde, pas un resume rapide. Consomme le contexte largement, lis les fichiers listes, croise les preuves, identifie les trous reels, puis produis un plan de finition executable par Codex.

Objectif global: terminer la validation FoodKing POS + Kiosk + KDS + OSS + Dashboard central + stock + catalogue + product composer + fiscal + sync avant hardware UAT et mise en live. Le code a beaucoup avance depuis ton dernier audit; tu dois refaire un master review complet sur l'etat actuel, verifier les preuves, et redonner a Codex une orchestration de correction/finition precise, mission par mission.

## Regles non negociables

- Ne fais pas confiance aux anciens verdicts sans relire les preuves actuelles.
- Le code courant gagne contre la memoire conversationnelle.
- Aucun PASS global si une zone critique reste seulement "testee par contrat statique" alors que le business demande un flux runtime reel.
- Distingue clairement:
  - `VALIDATED_STRONG`: prouve par tests/reports relus et coherent avec le code.
  - `PARTIAL`: prouve partiellement mais pas encore au niveau production.
  - `NOT_VALIDATED`: pas encore teste ou preuve insuffisante.
  - `REWORK`: bug, risque, incoherence ou test trompeur.
- Respecte les invariants FoodKing:
  - Backend pricing SSOT.
  - `OrderStatus` / `PaymentStatus` enums, pas de chaines magiques.
  - Isolation stricte `branch_id`.
  - Dispatch events/jobs apres commit DB.
  - Symetrie `OrderService` / `FrontendOrderService` si flux commande touche les deux.
  - NF525: pas de sequence fiscale avant encaissement reel.
  - Kiosk client locked down: pas d'acces admin/caisse depuis borne.
- Ne propose pas de gros refactor hors besoin. Donne des missions bornees, allowlist, tests, verdict PASS/REWORK.

## Lecture obligatoire gouvernance

Lis d'abord:
- `AGENTS.md`
- `.cursor/ACTIVE_CYCLE.md`
- `.cursor/rules/global.mdc`
- `.cursor/rules/project-invariants.mdc`
- `.cursor/rules/cross-agent-sync.mdc`
- `docs/orchestration/GLOBAL_SYSTEM_PRIMER.md`
- `docs/orchestration/MEMORY_MATRIX.md`
- `plans/masterplay/MASTERPLAY_DISCIPLINE.md`
- `plans/masterplay/MASTERPLAY_QUEUE.md`

## Rapports a relire integralement

Relis ces rapports et compare-les au code actuel:
- `reports/audit/CLAUDE_MASTER_REVIEW_PRODUCT_COMPOSER_SYNC_2026-04-27.md`
- `reports/audit/CLAUDE_MEGA_AUDIT_PLAN_PROCESS_AND_SYNC_2026-04-27.md`
- `reports/audit/CLAUDE_ORDERS_TO_CODEX_MEGA_TEST_ORCHESTRATION_2026-04-27.md`
- `reports/audit/CODEX_C0_KIOSK_AUTO_RETURN_EXECUTION_2026-04-27.md`
- `reports/audit/CODEX_C1_C2_PROCESS_AUDIT_2026-04-27.md`
- `reports/audit/CODEX_DEEP_BACKEND_SYNC_AUDIT_C0_C1_C2_2026-04-27.md`
- `reports/audit/CODEX_D1_D2_D3_DESIGN_EXECUTION_2026-04-27.md`
- `reports/audit/CODEX_GLOBAL_VERIFICATION_CONTINUATION_2026-04-28.md`
- `reports/audit/CODEX_HANDOFF_TO_CLAUDE_MASTER_REVIEW_PRODUCT_COMPOSER_SYNC_2026-04-27.md`
- `reports/audit/MASTER_REVIEW_POS_KDS_FINITIONS_CLAUDE_2026-04-26.md`
- `reports/audit/MASTER_REVIEW_POS_KDS_FINITIONS_BRIEF_2026-04-26.md`

Relis aussi les documents process/design:
- `docs/process/KIOSK_FULL_PROCESS_2026-04-27.md`
- `docs/process/POS_FULL_PROCESS_2026-04-27.md`
- `docs/design/KIOSK_DESIGN_VALIDATION_2026-04-27.md`
- `docs/design/POS_DESIGN_VALIDATION_2026-04-27.md`
- `docs/design/KDS_OSS_DESIGN_VALIDATION_2026-04-27.md`

Relis les JSON de preuve design:
- `reports/antigravity/d1-kiosk-design-audit.json`
- `reports/antigravity/d2-pos-design-audit.json`
- `reports/antigravity/d3-kds-oss-design-audit.json`

## Fichiers code critiques a auditer

### Kiosk runtime / paiement / waiting
- `resources/js/components/frontend/kiosk/KioskAppComponent.vue`
- `resources/js/components/frontend/kiosk/KioskWaitingComponent.vue`
- `resources/js/components/frontend/kiosk/KioskConfirmationComponent.vue`
- `resources/js/components/frontend/kiosk/KioskPaymentComponent.vue`
- `resources/js/components/frontend/kiosk/KioskCashInstructionComponent.vue`
- `resources/js/components/frontend/kiosk/KioskCategoriesComponent.vue`
- `resources/js/components/frontend/kiosk/KioskWizardComponent.vue`
- `resources/js/components/frontend/kiosk/KioskPosWizardComponent.vue`
- `resources/js/store/modules/kioskCart.js`
- `resources/js/store/modules/kioskMenu.js`
- `resources/js/router/modules/kioskRoutes.js`

### POS / caisse / counter collect
- `resources/js/components/admin/pos/PosComponent.vue`
- `resources/js/components/admin/pos/PaymentComponent.vue`
- `resources/js/components/admin/pos/FloorplanComponent.vue`
- `resources/js/components/admin/pos/ItemComponent.vue`
- `resources/js/store/modules/posOrder.js`
- `routes/api.php`
- `app/Http/Controllers/Admin/PosController.php`
- `app/Http/Controllers/Admin/PosOrderController.php`
- `app/Http/Requests/PosOrderRequest.php`

### KDS / OSS / realtime
- `resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue`
- `resources/js/components/admin/orderStatusScreen/PreparingAndReadyComponent.vue`
- `resources/js/components/admin/orderStatusScreen/PopularItemComponent.vue`
- `resources/js/store/modules/kds.js`
- `resources/js/store/modules/orderStatusScreenOrder.js`
- `app/Http/Controllers/Admin/KitchenDisplaySystemController.php`
- `app/Http/Controllers/Admin/OrderStatusScreenController.php`
- `app/Services/KitchenDisplaySystemOrderService.php`
- `app/Services/OrderStatusScreenOrderService.php`

### Services commande / paiement / fiscal
- `app/Services/OrderService.php`
- `app/Services/FrontendOrderService.php`
- `app/Services/PaymentService.php`
- `app/Domain/Order/OrderStateMachine.php`
- `app/Domain/Order/PaymentStateMachine.php`
- `app/Enums/OrderStatus.php` si present, sinon les enums equivalents actuels.
- `app/Enums/PaymentStatus.php`
- `app/Enums/PosPaymentMethod.php`
- `app/Services/Fiscal/ZReportService.php`
- `app/Services/Fiscal/FiscalSealingService.php`

### Stock / catalogue / composer / dashboard
- `app/Services/Stock/StockService.php`
- `app/Models/StockLevel.php`
- `app/Models/StockMovement.php`
- `app/Listeners/DecrementStockOnOrderCreated.php`
- `app/Listeners/ReleaseStockOnOrderCanceled.php`
- `app/Listeners/ReleaseStockOnRefundCreated.php`
- `app/Services/Menu/MenuProjectionService.php`
- `app/Services/Menu/AvailabilityService.php`
- `app/Services/Kiosk/KioskMenuService.php`
- `app/Http/Controllers/Admin/ItemController.php`
- `app/Http/Controllers/Admin/ComposerProfileController.php`
- `app/Http/Controllers/Admin/ComposerStepController.php`
- `app/Http/Requests/ComposerProfileRequest.php`
- `app/Http/Requests/ComposerStepRequest.php`
- `app/Services/Composer/*`
- `resources/js/components/admin/items/ItemListComponent.vue`
- `resources/js/components/admin/items/ItemShowComponent.vue`
- `resources/js/components/admin/items/ProductComposerSummaryComponent.vue`
- `resources/js/components/admin/items/composer/*`
- `resources/js/store/modules/composer.js`

### Events / outbox / sync
- `app/Domain/Events/EventContract.php`
- `app/Jobs/DispatchDomainEventsJob.php`
- `app/Listeners/PersistOrderCreatedToOutbox.php`
- `app/Listeners/PersistOrderStatusChangedToOutbox.php`
- `app/Listeners/PersistOrderPaidAtCounterToOutbox.php`
- `app/Listeners/PersistCatalogChangedToOutbox.php`
- `app/Events/CatalogChanged.php`
- `app/Events/ComposerProfilePublished.php`
- `app/Events/OrderPaidAtCounter.php`
- `app/Events/StockLevelChanged.php`
- `docs/orchestration/ORDER_EVENT_OUTBOX_CHANNEL_MAP_2026-04-26.md`
- `docs/EVENT_CONTRACT.md`
- `docs/OUTBOX_PATTERN.md`
- `docs/REALTIME_SETUP.md`

### Delivery / Google Maps / fees
- `resources/js/helpers/deliveryCharge.js`
- `app/Services/Delivery/DeliveryFeeService.php`
- `app/Http/Requests/OrderRequest.php`
- `resources/js/components/frontend/checkout/CheckoutComponent.vue`
- `resources/js/components/admin/pos/PosComponent.vue`

### Assets/design touched by Codex on 2026-04-28
- `resources/js/components/layouts/frontend/FrontendNavBarComponent.vue`
- `resources/js/components/admin/pos/FloorplanComponent.vue`
- `resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue`
- `resources/js/components/admin/orderStatusScreen/PreparingAndReadyComponent.vue`
- `resources/js/components/frontend/search/SearchItemComponent.vue`
- `resources/js/components/frontend/otherPage/NotFoundComponent.vue`
- `resources/js/components/frontend/otherPage/ExceptionComponent.vue`
- `resources/js/components/frontend/account/myOrder/MyOrderComponent.vue`
- `resources/css/app.css`
- `tests/e2e/design/_shared/design-audit-helpers.js`

## Tests a lire et classifier

Relis les tests suivants pour determiner ce qu'ils prouvent vraiment, ce qu'ils ne prouvent pas, et quels tests manquent:

### Process C0/C1/C2
- `tests/e2e/kiosk-post-payment-auto-return.spec.js`
- `tests/e2e/kiosk-full-process/c1-kiosk-process-audit.spec.js`
- `tests/e2e/pos-full-process/c2-pos-process-audit.spec.js`
- `tests/e2e/helpers/process-audit.js`
- `tests/e2e/composer-mega-flow.spec.js`

### Design D1/D2/D3
- `tests/e2e/design/kiosk/d1-kiosk-design-audit.spec.js`
- `tests/e2e/design/pos/d2-pos-design-audit.spec.js`
- `tests/e2e/design/kds/d3-kds-oss-design-audit.spec.js`
- `tests/e2e/design/_shared/design-audit-helpers.js`

### Sync / KDS / realtime
- `tests/Feature/KioskRealtimeBroadcastTest.php`
- `tests/Playwright/pos-receives-kiosk-realtime.spec.js`
- `tests/Playwright/KdsMultiScreenPlaywrightTest.spec.js`
- `tests/js/realtimeBroadcastFallback.spec.js`
- `tests/js/kdsSyncCadence.spec.js`
- `tests/js/kdsDedupeByIdVersion.spec.js`
- `tests/js/kdsBackoffOn5xx.spec.js`
- `tests/js/kdsReactsToReconnectStorm.spec.js`

### Stock / catalogue / rupture / photos / menu
- `tests/Feature/Stock/StockBranchIsolationTest.php`
- `tests/Feature/Stock/StockConcurrentDecrementTest.php`
- `tests/Feature/Stock/StockDecrementFrontendOrderServiceTest.php`
- `tests/Feature/Stock/StockDecrementOrderServiceTest.php`
- `tests/Feature/Stock/StockLevelSchemaTest.php`
- `tests/Feature/Stock/StockMovementsAppendOnlyTest.php`
- `tests/Feature/Stock/StockReleaseOnCancelTest.php`
- `tests/Feature/Stock/StockReleaseOnRefundTest.php`
- `tests/Feature/Stock/StockRuptureAvailabilitySyncTest.php`
- `tests/Feature/Stock/StockSymmetryDiffTest.php`
- `tests/Feature/Menu/CatalogStockCentralSyncEndToEndTest.php`
- `tests/Feature/Menu/ItemImageCatalogRefreshTest.php`
- `tests/Feature/Menu/AdminItemBranchAvailabilityProjectionTest.php`
- `tests/Feature/Menu/CatalogMutationSnapshotCoverageTest.php`
- `tests/Feature/Menu/FrontendSurfaceFilteringTest.php`
- `tests/Feature/Menu/OrderRejectsUnavailableBranchItemTest.php`
- `tests/Feature/Menu/AvailabilityServiceTest.php`
- `tests/Feature/Menu/BumpMenuSnapshotListenerTest.php`
- `tests/js/posRuptureUx.spec.js`
- `tests/js/kioskRuptureUx.spec.js`

### Delivery / maps
- `tests/js/deliveryCharge.spec.js`
- `tests/js/checkoutGeocodeError.spec.js`
- `tests/Feature/Delivery/*`
- `tests/Feature/Frontend/OrderRequestDeliveryFeeAuthorityTest.php` si present.

### Queue / fiscal / payment
- `tests/Feature/QueueNumberConcurrencyTest.php`
- `tests/Feature/Payment/CounterDeferredPaymentLifecycleTest.php`
- `tests/Feature/Payment/PaymentStateMachineTransitionsTest.php`
- `tests/Feature/KioskPaymentStateMachineTest.php`
- `tests/Feature/Fiscal/*`

## Etat actuel que tu dois verifier

Selon les derniers runs Codex, a confirmer par toi:

### Valide fortement localement
- C0 kiosk auto-return: PASS.
- C1 kiosk full process: PASS 5/5 par scenario.
- C2 POS full process: PASS 5/5 par scenario.
- D1 kiosk design: `90` audits, `seriousTotal=0`.
- D2 POS design: `30` audits, `seriousTotal=0`.
- D3 KDS/OSS design: `20` audits, `seriousTotal=0`.
- Stock suite: `17 passed`.
- Queue number suite: `4 passed`.
- Menu/catalog suite: `20 passed`, `6 skipped` sous SQLite.
- JS sync/rupture/delivery/geocode: `27 passed`.
- Playwright static contracts realtime/KDS: `10 passed` en repeat-each=5.

### Pas encore fini / pas assez prouve
- C3 complet runtime: Kiosk + POS + KDS + OSS simultanes, propagation sans reload, mesures de delai, reconnect, multi-branch. Les tests actuels sont plutot contrats statiques / process isole.
- C4 stress stock massif: 50/100 commandes concurrentes, rollback multi-lignes, rupture live et release en concurrence.
- C5 stress queue number massif: create POS + kiosk en parallele, unicite branch+date, pas de double numero.
- C6 persistence/history/fiscal/outbox: audit logs, HMAC chain, fiscal monotonicite, replay outbox, idempotence, Z-report.
- C9 dashboard management: vrai parcours UI complet ajout/modif/suppression categorie, produit, photo, stock, composer, publication et propagation kiosk/POS.
- D4-D13 campagne prod-live massive: fonctionnel Kiosk/POS/KDS, sync, data, stock extreme, pricing forge, authz matrix, chaos, consolidation.
- Hardware UAT: borne physique, TPE, imprimante, KDS reel, network loss, Google Maps reel.
- MySQL validation: `FrontendSurfaceFilteringTest` skippe sous SQLite; doit etre valide sur MySQL 8.
- Source du toast transient `Too Many Attempts.` observe pendant D3 intermediaire: corrige cote harness pour design, mais a auditer cote runtime/ratelimit pendant C3/D12.

## Questions auxquelles tu dois repondre

1. Est-ce que les tests C0/C1/C2 prouvent vraiment un flux runtime complet, ou seulement des fixtures/process suffisamment representatifs?
2. Est-ce que D1/D2/D3 sont maintenant un PASS design suffisant pour UAT, ou faut-il encore captures baseline/visual regression plus strictes?
3. Est-ce que le `clearTransientUi()` ajoute au helper design masque seulement du bruit de toast ou peut cacher un vrai probleme UX a traiter ailleurs?
4. Est-ce que le toast `Too Many Attempts.` indique une vraie limite de rate limit trop basse pour OSS/KDS en audit runtime?
5. Est-ce que les routes counter-collect inline dans `routes/api.php` restent P2 ou deviennent P1 avant release?
6. Est-ce que le dashboard composer/product management est assez mature pour un restaurateur reel: photo, prix, stock, categorie, steps wizard, roles addon?
7. Est-ce que les tests actuels couvrent la propagation image/photo produit jusqu'a la borne et la caisse, pas seulement l'event backend?
8. Est-ce que le stock est decrement/release exactement une fois dans toutes les branches: POS cash/card, kiosk card, kiosk cash-at-counter confirm/cancel, refund?
9. Est-ce que le queue number est assez prouve en forte concurrence ou seulement par contrainte DB?
10. Est-ce que la fiscalite NF525 cash-at-counter est totalement correcte: fiscal null a creation, allocation atomique confirm, cancel sans fiscal, reprint non-fiscal?
11. Est-ce que la livraison Google Maps doit bloquer a 422 si geocode fail partout, et est-ce coherent POS + web + kiosk?
12. Est-ce que la matrice authz dashboard/composer/stock/catalog est assez stricte par role et branch?
13. Est-ce qu'une vraie mission C3 doit etre implementation + tests ou seulement test/audit?
14. Quels fichiers exacts Codex doit modifier en priorite, et quels fichiers il ne doit pas toucher?
15. Quelle est la decision go/no-go pour hardware UAT apres cette revue?

## Livrables attendus de Claude

Produis un fichier de reponse structure en Markdown avec:

1. `MASTER_AUDIT_VERDICT: PASS | REWORK | HOLD`
2. `RELEASE_DECISION: PROCEED_TO_HARDWARE_UAT | REWORK_BEFORE_UAT | HOLD_FOR_ARCHITECTURE`
3. Table `VALIDATED_STRONG / PARTIAL / NOT_VALIDATED / REWORK` pour:
   - C0, C1, C2, C3, C4, C5, C6, C7, C8, C9, C10
   - D0 a D13
   - B0 a B9 product composer sync
4. Findings classes P0/P1/P2/P3 avec file:line, cause, risque business, test manquant, correction.
5. Evaluation des tests existants: ce que chaque suite prouve vraiment et ce qu'elle ne prouve pas.
6. Plan de finition executable par Codex, missions courtes:
   - `TASK_ID`
   - objectif
   - preconditions/gates
   - allowlist fichiers
   - interdictions
   - implementation steps
   - tests obligatoires
   - run-many attendu
   - PASS/REWORK criteria
   - self-audit attendu
7. Plan priorise:
   - Piste 1: C3 runtime multi-surface live.
   - Piste 2: C4/C5 stress stock + queue.
   - Piste 3: C6 fiscal/outbox/persistence.
   - Piste 4: C9 dashboard management réel.
   - Piste 5: D4-D13 prod-live validation.
   - Piste 6: Hardware UAT checklist.
8. Donne un prompt Codex pret a coller pour la premiere mission a executer.
9. Donne une liste des commandes exactes de validation a lancer localement.
10. Donne la liste des questions humaines restantes, uniquement si elles bloquent vraiment.

## Discipline de conclusion

Ne conclus pas "PASS global" si les preuves runtime live multi-surface ne sont pas suffisantes. Sois dur. Je prefere un `REWORK` precis et executable qu'un PASS optimiste.

Ne redemande pas a Codex de refaire ce qui est deja solidement valide, sauf si tu detectes un test trompeur. Concentre-toi sur les angles non prouves et les risques production.

Sortie attendue: un audit tres detaille et un plan de finition qui permet a Codex d'executer sans ambiguite jusqu'a UAT.
