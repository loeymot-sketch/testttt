# POS AUDIT MASTER PLAN — 2026-05-06

**Cycle parent** : `CAISSE_V1_MASTERPLAY` (Train A V1 release prep)
**Demande utilisateur (2026-05-06)** : audit massif POS seul (frontend + backend + sync central + tests + captures), point par point, étape par étape, jusqu'à correction complète.
**Périmètre** : POS uniquement (admin caisse `/admin/pos-v4`). Kiosk + KDS = cycles dédiés ultérieurs.
**Contrainte critique** : **Wizard popup POS PROTÉGÉ** — interdiction de modifier design / structure / logique. Embellissements purement non-régressifs autorisés UNIQUEMENT sur demande explicite. Voir `feedback_wizard_popup_pos_protected.md`.

---

## 1. Cartographie consolidée (P0 — discovery faite 2026-05-06)

### Frontend
- **Entry** : `routes/web.php` → `/admin/pos-v4/{any?}` → `AdminPosV4Controller@index` → `resources/views/admin-pos-v4.blade.php` → `resources/js/pos-app.js`
- **Shell** : `resources/js/components/admin/pos/PosComponent.vue` (95% logique caisse)
- **Design System POS V5** : `resources/css/foundations/pos-v5-tokens.css` (warm `--pos-v5-bg-app: FFFBF5`, brand red), atoms dans `resources/js/components/admin/pos/v5/PosV5*.vue` (Button, Card, Pill, StatChip, TotalRow, QtyStepper, Numpad, SearchInput)
- **Composants par étape** :
  - Catégories : strip `pos-v5-category-strip` dans `PosComponent.vue` L139-163
  - Produits : `ItemComponent.vue` (grille `pos-v5-grid`) + `SkeletonGrid.vue`
  - **Wizard popup** : `public/js/pos-wizard.js` + `public/css/pos-wizard.css` (Vanilla JS frozen) — **NE PAS TOUCHER**
  - Cart : aside dans `PosComponent.vue` (L196+)
  - Paiement : `PaymentComponent.vue` + `PosV5Numpad.vue`
  - Ticket : `ReceiptComponent.vue` + `ReceiptDuplicataMarker.vue`, builder `helpers/posReceiptBuilder.js`
  - Parking : `ParkedOrdersComponent.vue`
  - Floorplan : `FloorplanComponent.vue`
  - Tracker post-paiement : `PosOrdersTrackerComponent.vue` (Echo live)
- **Stores Vuex** : `posCart`, `posCategory`, `posCustomer`, `posFloorplan`, `posOrder`, `posParked`
- **Helpers** : `posCartLineMath.js`, `posReceiptBuilder.js`, `posCentsArith.js`, `posFormatCents.js`, `posNormalizeIds.js`, `posBarcode.js`, `posA11y.js`
- **Services** : `services/PosSyncService.js`, `services/posNfc.js`, `services/posPrinter.js`

### Backend
- **Routes API** (api.php) :
  - `GET /api/pos/walk-in-customer` → `PosController@walkInCustomer` (throttle: pos-quote)
  - `POST /api/pos/quote` → `PosController@quote` (throttle: pos-quote, 120/min)
  - `POST /api/pos` → `PosController@store` → `OrderService::posOrderStore()` (throttle: pos-order-create, 60/min)
  - `POST /api/pos/orders/{order}/print-receipt` → `PosReceiptPrintController@increment`
  - `GET/POST/DELETE /api/pos/parked-orders` → `ParkedOrderController`
  - `GET/POST /api/pos/floorplan/*` → `FloorplanController`
  - `POST /api/pos/cash-drawer/open` → `CashDrawerController@open`
  - `GET /api/pos-order` etc. → `PosOrderController` (permission: pos-orders|pos)
  - `POST /api/pos-order/change-status/{order}` → `OrderService::changeStatus`
  - `POST /api/pos-order/change-payment-status/{order}` → `OrderService::changePaymentStatus` ⚠️ **sans state machine** (P0 connu)
- **Services** :
  - `OrderService::posOrderStore()` — création POS, fiscal seq, events
  - `OrderService::changeStatus / changePaymentStatus` — transitions
  - `OrderQuoteService::quote()` — devis HMAC + intent_hash + quote_token (TTL 60s)
  - `PricingService::calculateOrder()` — **SSOT pricing backend** (flag `PRICING_USE_SSOT=true`)
  - `FiscalSequenceService::next(branchId)` — séquence monotone (cache lock 5s + UNIQUE)
  - `ChoiceAvailabilityResolver::snapshotForItems()` — dispo variations/extras/addons
  - `IngredientAvailabilityService::toggle()` — cascade par nom + event
  - `EscPosPrinterService` + `PrinterTransport` — hardware
  - `ReceiptDataService::buildForOrder(int $orderId)` / `buildForOrderModel(Order $order)` — SSOT for the six NF525-receipt fields (fiscal_sequence_no, register_id, siret, vat_intra, legal_footer, operator_name). Consumed by `OrderDetailsResource` (delegation, see `ReceiptDataServiceWireInTest`) so the HTTP resource and the JS-side `posReceiptBuilder` converge on a single source of truth.
  - `AuditLogService::write()` — audit NF525 best-effort
  - `ZReportService::open / close` — Z fiscal HMAC chainé
- **FormRequests** : `PosOrderRequest` (token, branch_id, order_type, items JSON, pos_payment_method, etc.)
- **Models** : `Order`, `OrderItem`, `OrderItemVariation/Extra/Addon`, `OrderItemAllergenSnapshot`, `Item`, `ItemExtra`, `ItemAddon`, `ItemWizardProfile/Step/Version`, `Printer`, `ZReport`

### Sync central
- **Pattern** : Outbox `domain_events` (table) → listener post-commit → `DispatchDomainEventsJob` (queue `high`, retries [1,5,30,300]s) → Pusher
- **Events POS** : `OrderCreated`, `OrderStatusChanged`, `ItemAvailabilityChanged`
- **Validations** : `EventContract::assertEnvelopeValid` (version, type, branch_id, occurred_at, correlation_id) avant Pusher trigger
- **Channel auth** : `routes/channels.php` token-scoped (kiosk:order, staff branch_id, admin wildcard)
- **Idempotency** : pré-check `Order::where('idempotency_key')` + UNIQUE composite `(branch_id, idempotency_key)` + catch `QueryException 23000`
- **Branch isolation** : `BranchScope` global + defense-in-depth `abort(403)` dans mutations cross-branch
- **Anti-dédup tables** : UNIQUE `(branch_id, idempotency_key)`, `(branch_id, fiscal_sequence_no)`, `(branch_id, business_date, queue_number)`, `(user_id, order_id, type)` loyalty, `(branch_id, sequence_no)` Z, `(branch_id, prev_hash)` audit
- **Rate limits** : `pos-order-create` 60/min, `pos-order-update` 120/min, `pos-quote` 120/min, login 10/10min
- **Fiscal NF525** : séquence DB-enforced + HMAC chain + audit immutable (triggers MySQL/SQLite + Eloquent guards)

### Couverture tests existants
- 13 Playwright (critical-flow, kiosk, sentinels, 1 POS)
- 57 specs JS unit (`tests/js/pos*.spec.js`)
- 92 Feature PHP (Pos, Pos/, KDS, Order, Orders, Outbox, Fiscal, Sentinels)
- 9 Unit PHP (Services, Pricing, Domain, Rules)
- 20 e2e (`tests/e2e/`)
- 21 sentinels (anti-régression FK-ID)
- **Trous identifiés** : paiement split, drawer session Feature, KDS Playwright post-status complet, ticket impression hardware réelle

---

## 2. Faiblesses connues à reverifier (depuis VERIFY 2026-04-20)

### P0 — délivrables Train A
1. **F-VERIFY-09-02** : middleware `Idempotency-Key` HTTP réutilisable absent — protection uniquement applicative, perte si client tiers omet header
2. **F-VERIFY-09-01** : `changePaymentStatus` sans guard transition (`PAID→PAID` accepté, multiplie ActionLog/AuditLog HMAC, ré-jeu trivial)
3. **F-VERIFY-08-01** : `Z.open` ne vérifie pas signature Z précédent ni chaîne audit_logs avant nouvelle séquence

### P1 — durcissement
4. **F-VERIFY-09-03** : Outbox insert hors transaction d'origine `posOrderStore` → perte event possible si crash 2ᵉ connexion
5. **F-VERIFY-10-1** : routes fiscales sans middleware `permission:pos-manage-fiscal` group-level (guard in-method only)
6. **F-VERIFY-18-03** : double pricing path subsiste (flag `PRICING_USE_SSOT=false` → legacy recalc) — dette critique

### P2-P3 — backlog
7. **F-VERIFY-09-07** : idempotency_key non-UUID front (entropie 36⁹ par ms par branche)
8. **F-VERIFY-13** : table `transactions` pas de `branch_id` ni FK `order_id`
9. **F-VERIFY-08-02** : pas de guard contre `changeStatus → RETURNED` post-Z fermé
10. **F-VERIFY-09-10** : aucun event domaine `PaymentStatusChanged` (perte signal KDS/Z si reversion)

---

## 3. Étapes POS à auditer (chaîne complète)

| # | Étape | Frontend | Backend | Sync | Test |
|---|---|---|---|---|---|
| 1 | Login caissier | Blade `/login` | `LoginController` | session sanctum | login E2E |
| 2 | Ouverture POS | `pos-app.js` boot | `AdminPosV4Controller@index` | hydrate menu/categories | guard auth |
| 3 | Sélection branch/device | header `PosComponent` | branch from session | — | branch isolation sentinel |
| 4 | Type commande (dine-in/takeaway/delivery) | order-type selector | `posOrderStore.order_type` | — | `posDineInFlag.spec.js` |
| 5 | Sélection table (si dine-in) | `FloorplanComponent` | `FloorplanController` | `floorplan/state` | `posFloorplan.spec.js` |
| 6 | Browse catégories | `pos-v5-category-strip` | `PosCategoryController`, `PosMenuProjection` | menu push event | `posComponentMenuFiltering.spec.js` |
| 7 | Affichage produits | `ItemComponent` grille | `MenuProjectionService` | `ItemAvailabilityChanged` event | `posSkeletonGrid.spec.js`, `posRuptureUx.spec.js` |
| 8 | Item simple add | click button | cart Vuex | — | `posCart.spec.js` |
| 9 | Item avec variations | wizard popup | `ItemWizardProfile` projection | — | `posVariationMultiQty.spec.js` |
| 10 | **Wizard composer** (PROTÉGÉ) | `public/js/pos-wizard.js` | `ComposerProfileService` | — | `posWizardComposerAware.spec.js` |
| 11 | Cart edit (qty, suppr) | aside `PosComponent` | client only | — | `posCart.spec.js`, `posCartPrune.spec.js` |
| 12 | Discount (manuel/coupon) | discount UI | `DiscountCalculator` + `pos-discount-up-to-10` | audit log | `PosDiscountTest.php` |
| 13 | Park / Recall | `ParkedOrdersComponent` | `ParkedOrderController` | `parked-orders` API | `posParked.spec.js`, `PosParkedOrderTest.php` |
| 14 | Customer (walk-in / sélection) | customer selector | `WalkInCustomerResolver` | — | `PosWalkInCustomerApiTest.php` |
| 15 | Quote / preview pricing | preview totaux | `PricingService::calculateOrder` SSOT | — | `PosPricingSsotProofTest.php`, `PosKioskPricingParityTest.php` |
| 16 | Confirmation paiement | `PaymentComponent` + `PosV5Numpad` | `OrderService::posOrderStore` + `FiscalSequenceService::next` | `OrderCreated` event | `posPaymentComponentContract.spec.js`, sentinels FK-001/025/029/058 |
| 17 | Méthode CASH | numpad cash | `posOrderStore` payment_method=CASH | drawer open call | `posCashDrawerOpen.spec.js`, `EscPosOpenDrawerTest.php` |
| 18 | Méthode CARD | TPE bridge | `posOrderStore` payment_method=CARD | — | `02-pos-cash.spec.js`, `05-pos-card.spec.js` |
| 19 | Méthode SPLIT | split UI | multi-paiement | — | **TROU — pas de test** |
| 20 | Méthode NFC | NDEFReader | `posNfc` service | — | `posNfc.spec.js` |
| 21 | Ticket-restaurant | TR UI | `PosTicketRestaurantPaymentTest.php` | — | feature OK |
| 22 | Ouverture tiroir caisse | `kioskHardware.openDrawer` | `CashDrawerController@open` + ESC/POS | hardware | `posCashDrawerOpen.spec.js` |
| 23 | Génération ticket caisse | `ReceiptComponent` + `posReceiptBuilder` | `ReceiptDataService::buildForOrderModel` ⇐ delegated by `OrderDetailsResource` (NF525 wire-in 2026-05-18) | audit `pos.receipt.print` chained | `posReceiptBuilder.spec.js`, `PosReceiptTaxLinesTest.php`, `ReceiptDataServiceWireInTest.php` |
| 24 | Impression ticket | `PosV5Button` print | `PosReceiptPrintController@increment` + ESC/POS | audit `pos.receipt.print` | `posReceiptPrintFlow.spec.js`, `EscPosOpenDrawerTest.php` |
| 25 | Duplicata ticket | `ReceiptDuplicataMarker` | `receipt_print_count >= 2` | audit `pos.receipt.reprint` | `posReceiptDuplicataMarker.spec.js` |
| 26 | Envoi cuisine (KDS) | — | `OrderCreated` event → outbox → KDS subscribe | Pusher `private-branch.{id}` | `KDSFlowTest.php`, `audit-pos-multiproduct-kds-journey.spec.js` |
| 27 | Tracker post-paiement | `PosOrdersTrackerComponent` Echo | `OrderStatusChanged` events | live | `pos-receives-kiosk-realtime.spec.js` |
| 28 | Change status (ACCEPTED→PREPARING etc.) | tracker actions | `OrderService::changeStatus` | event status | `OrderStateMachineTest.php` |
| 29 | Refund / void | refund UI | `OrderService::changePaymentStatus` ⚠️ no state machine | audit | `RefundPostZTest.php` |
| 30 | Z fiscal open/close | Z report UI | `ZReportService::open/close` | — | `OrderFiscalSequenceSchemaTest.php`, `FiscalZBranchExactnessSentinelTest.php` |
| 31 | Idempotence retry submit | front single-flight | `posOrderStore` pré-check + UNIQUE | — | `posOrderIdempotency.spec.js`, `IdempotencyBranchScopedTest.php` |
| 32 | Branch isolation | scope auto | `BranchScope` + abort(403) | channel auth | `OrderListBranchExactnessSentinelTest.php` |

**Total : 32 étapes** (étape 10 = ZONE PROTÉGÉE pour le design, audit fonctionnel autorisé sans modif visuelle).

---

## 4. Plan d'exécution P1→P8

### P1 — Audit FRONTEND par étape (design + affichage + UX)
- Pour chaque étape 1-32 : capture Playwright (état nominal + 1-2 edge cases : empty, loading, error, mobile)
- Inspection design vs `pos-v5-tokens.css` (cohérence warm tokens, brand red, typographie Inter)
- A11y : aria-labels, contrastes, focus visible, sr-only regions
- Responsive : 1280, 1920, tablette
- **Zone wizard** : capture seulement, aucune modif design proposée
- Sortie : `docs/audit/POS_FRONTEND_AUDIT_2026-05-06.md` + screenshots dans `tests/e2e/screenshots/audit-pos-2026-05-06/`

### P2 — Audit BACKEND par étape
- Pour chaque endpoint/service : code review (validation FormRequest, transactions DB, pricing SSOT, locks)
- Vérifier flag `PRICING_USE_SSOT=true` réellement appliqué
- Re-scan idempotency, branch isolation, fiscal seq, audit log
- Sortie : `docs/audit/POS_BACKEND_AUDIT_2026-05-06.md`

### P3 — Audit SYNC central
- Re-vérifier les 3 P0 (idempotency middleware, payment state machine, Z.open chain check) → status delta depuis 2026-04-20
- Test outbox transactionnel (P15)
- Vérifier branch isolation post-changements 2026-04-21→05-06 (50+ fichiers M)
- Sortie : `docs/audit/POS_SYNC_SECURITY_AUDIT_2026-05-06.md`

### P4 — Audit CATALOGUE
- Lister TOUTES les catégories en DB (`PosCategory` model)
- Pour chaque catégorie : tester affichage, ordre, badges, dispo
- Pour chaque type produit (simple, variation, addon, composer) : flow ajout cart
- Vérifier `MenuProjectionService` cohérence
- Sortie : `docs/audit/POS_CATALOG_AUDIT_2026-05-06.md`

### P5 — Audit hand-off KDS
- Trace complet : POS submit → OrderCreated → outbox → DispatchDomainEventsJob → Pusher → KDS Echo
- Vérifier payload envelope (correlation_id, branch_id, items, allergens, dine-in flag)
- Test scenarios : dine-in, takeaway, multi-item, item composé wizard, modifications
- Sortie : `docs/audit/POS_KDS_HANDOFF_AUDIT_2026-05-06.md`

### P6 — Audit ticket caisse
- Capture ticket nominal, duplicata, refund, void
- Conformité fiscale NF525 (champs obligatoires, séquence, branche)
- Hardware : test `EscPosPrinterService` codepage 19, drawer open
- Sortie : `docs/audit/POS_RECEIPT_AUDIT_2026-05-06.md`

### P7 — Tests massifs Playwright
- Suite dédiée `tests/e2e/audit-pos-2026-05-06.spec.js` couvrant les 32 étapes
- Captures à chaque étape critique
- Combler trous : split payment, drawer feature, KDS post-status
- Lancer aussi tous les sentinels existants (régression)
- Sortie : `reports/antigravity/AUDIT_POS_MASSIVE_2026-05-06.md` + screenshots

### P8 — Synthèse + corrections par étape
- Tableau étape × dimension × verdict (continue / heal / block / escalate)
- Corrections triviales : appliquées + retest
- Corrections P0/P1 connues (Train A) : SURFACE + plan de fix, **NE PAS toucher frozen zones sans gate** (cf. `feedback_cv1_mode_operatoire.md` : fondations + plans pour Codex)
- Corrections wizard popup : **REFUSÉES** par défaut (rapport seulement)
- Sortie : `docs/audit/POS_AUDIT_FINAL_REPORT_2026-05-06.md`

---

## 5. Garde-fous

- **NE PAS** modifier `public/js/pos-wizard.js`, `public/css/pos-wizard.css`, ou les composants wizard
- **NE PAS** modifier les frozen zones (`OrderService` lifecycle, `FrontendOrderService::finalizePaidKioskOrder`, `PaymentService`, `routes/api.php`) sans gate humaine
- Pour chaque correction proposée : passer par plan détaillé pour Codex (mode opératoire CV1) plutôt qu'implémenter directement la logique métier
- Captures = evidence — pas de "ça a l'air OK" sans capture ou test
- Décisions par étape selon CLAUDE.md §8 : continue / heal / block / escalate / human

---

## 6. État actuel

- ✅ P0 cartographie : DONE (4 agents Explore parallèles, 2026-05-06)
- 🟡 P1-P7 : à lancer
- 🔴 P8 : bloqué tant que P1-P7 incomplets

**Prochain step** : appel advisor pour valider l'approche, puis lancer P1 (frontend audit avec captures Playwright).
