# Cartographie — SYSTÈME CAISSE (POS terminal principal)
Vague 01-structure · lecteur-cartographe `caisse-pos` · 2026-07-02 · branche `pos/category-first-caisse-2026-06-23`

Tout ce qui suit a été vu via Read/Grep/ls dans cette session. Aucune supposition.

---

## 1. Vue d'ensemble architecture

La caisse est un **hybride 3 couches** :

1. **Vue 3 SPA** (`PosComponent.vue` 5378 l. + enfants) montée soit par le bundle complet `app.js` (route `/admin/pos`, `resources/js/router/modules/posRoutes.js`), soit par l'entrée dédiée slim **`resources/js/pos-app.js`** (223 l., route `/admin/pos-v4`, Blade `resources/views/admin-pos-v4.blade.php` — FROZEN). Les deux chargent le chunk webpack `pos-shell`.
2. **Wizard Vanilla JS FROZEN** `public/js/pos-wizard.js` (5999 l., version S25-SinglePage, non-Mix) : IIFE qui **intercepte le modal Vue** `#item-variation-modal` et le remplace par un formulaire single-page (viandes/pain/crudités/sauce/suppléments/formule/commentaire), puis **re-synchronise ses choix dans le DOM du modal Vue** (`syncAndSubmit`) pour que la logique Vue (SSOT) fasse la sérialisation.
3. **Backend Laravel** : `PosController` (quote+store), route-closures `pos.counter-collect.*` dans `routes/api.php:799-944`, `PaymentService`, `OrderService::posOrderStore`, `OrderQuoteService` (devis HMAC), `CashDrawerService`, `SplitPaymentService`.

Impression : le serveur rend les octets ESC/POS (NF525-fidèles) et le frontend les POSTe au **pont local** `http://127.0.0.1:9100/raw` (`posLocalPrinter.js`, `PosTicketBytesController`).

## 2. Frontend — fichiers et rôles

### resources/js/components/admin/pos/
| Fichier | Rôle |
|---|---|
| `PosComponent.vue` (5378 l.) | Shell caisse : catalogue, **category-first landing** (`posBrowseMode`/`showCategoryGrid` :2079-2087, helper pur `helpers/posBrowseView.js`), panier Vuex `posCart`, `orderSubmit` (:3958) qui assemble items JSON + idempotency_key `${Date.now()}_${rand}_${branchId}` (:4041, hard-stop si branch_id null), file counter-collect (`GET admin/pos/counter-collect/pending` :3272), offline queue (navigator.onLine → `enqueueOrder` :4055), loyalty CTA main-page (:1172-1184), walk-in (`GET /admin/pos/walk-in-customer` :2766) |
| `ItemComponent.vue` (1842 l.) | Modal produit Vue (host du wizard). Pont wizard→Vue : checkbox extras `@change="onWizardBridgeExtra"` (:227), qui lit `data-wizard-qty` posé par pos-wizard.js (:714-722) → `setExtraQuantity` (:740, SSOT extras facturés). Lit aussi `modal.dataset.wizardTotal` (:450) |
| `PaymentComponent.vue` (1478 l., **FROZEN**) | Modal paiement : modes cash/card(TPE)/mobile/ticket, numpad partagé `PosV5Numpad`, sélection TPE actif (fetch payment-terminals :504-506), **devis SSOT** `axios.post('admin/pos/quote')` (:726) → patch `quote_token/quote_signature/total_ttc` sur le form + auto-refresh 60 s (:467-472) ; puis POST `admin/pos` (création commande) |
| `PosCounterCollectModal.vue` (848 l.) | Modal encaissement différé (borne + walk-in déféré) : 4 modes (:251), numpad readonly `inputmode="none"` (:108-132, anti clavier Windows), input « Montant carte » pour CARD (commit `594eb92f5`), POST `admin/pos/counter-collect/{id}/confirm` body `{mode:int, received, note}` (:212-213), idempotency-key `pos-counter-collect-{orderId}-{modeInt}-{minuteBucket}` (:30) |
| `ReceiptComponent.vue` + `ReceiptDuplicataMarker/RemboursementMarker` | Ticket client (pont ESC/POS, fallback window.print) |
| `ParkedOrdersComponent.vue` | Commandes en attente (park/recall) |
| `PosRefundModal.vue`, `PosLoyaltyRedeemModal.vue`, `PosOrdersTrackerComponent.vue`, `FloorplanComponent.vue`, `CreateCustomerAddressComponent.vue`, `SkeletonGrid.vue` | remboursement contre-écriture NF525, redeem fidélité, tracker, plan de salle, adresse livraison, skeleton |
| `v5/` (9 atoms) | `PosV5Numpad/Button/Card/Pill/QtyStepper/SearchInput/StatChip/TotalRow/TrancheRow` (**TrancheRow FROZEN** — split payment) |

### Autres surfaces caisse
- `posOrders/` : `PosOrderListComponent`, `PosOrderShowComponent`, `PosOrderComponent`, `PosOrderReceiptComponent`, `PosOrderMapComponent` — historique commandes POS.
- `encaissement/EncaissementComponent.vue` (315 l.) : page unifiée `/admin/encaissement` (`router/modules/encaissementRoutes.js:11`) — grille de tickets `GET admin/pos/counter-collect/pending`, badge origine borne/caisse, réutilise `PosCounterCollectModal` ; à l'encaissement imprime le ticket client via `printEscPosViaCaisseBridge` (import :78).
- `cash/PosCashDrawerSessionDialog.vue`, `cashOverview/CashOverviewComponent.vue`, `cashSessionReport/CashSessionReportListComponent.vue`.
- Helpers : `posReceiptBuilder.js` (HTML ticket pur), `posLocalPrinter.js` (pont RAW 127.0.0.1:9100, octets fiscaux exacts passthrough), `posBrowseView.js` (category-first pur), `posOfflineQueue(+Db).js`, `posSplitPayment.js`, `posCentsArith.js`, `posCartLineMath.js`, `posFormatCents.js`, `posNormalizeIds.js`, `posLoyaltyMainCta.js`, `posA11y.js`, `posBarcode.js`.

### Entrée dédiée pos-app.js / admin-pos-v4.blade.php (FROZEN)
- `pos-app.js` : router 2 vraies routes + stubs de compat (hard-redirect window.location pour tracker/pos-orders :111-134) ; 401→ `/login` (:54-67) ; refresh token Sanctum toutes les 2 h (:198-205) ; sync rotation token cross-tab via event `storage` (:211-223).
- Blade : injecte `window.foodkingConfig` minimum (:96-113) + `window.POS_WIZARD_CONFIG` prix wizard depuis Settings (:129-134) + `pos-wizard.js?v=9-time()` (:136) + hide Dine-In (:139-161).

## 3. Wizard Vanilla FROZEN — structure (public/js/pos-wizard.js)

- IIFE `(function(){...})()` (:10), `'use strict'`.
- Config : `VIANDES` (10) :49-60, `ALL_SAUCES` (17) :65-83, prix injectés `window.POS_WIZARD_CONFIG` (:85-91, fallback 0.50/2.50/1.00/1.00).
- Sections : STATE (:32), render wizard (:1043), step renderers (:1171), combined steps Sprint 4 (:1576), buildWizardInstruction pour KDS (:4317, format "VIANDES: X. SAUCE: Y."), **`syncAndSubmit()` (:3723)** — réécrit qty (`.indec-value` + native setter + events), clique radios variation sauce, pose `data-wizard-qty` sur la checkbox « Viande supplémentaire » (:3905-3937 → `ItemComponent.onWizardBridgeExtra` → `setExtraQuantity(N)`, décision owner 2026-07-01), wizard open/close (:5175), navigation/validation (:5320 `canProceedFromStep`), event binders (:5667, :5918), **observers** (:5990-) : MutationObserver body-level détecte le mount/re-mount de `#item-variation-modal` (SPA-safe), observer d'attributs `class` déclenche `openWizard` quand le modal devient `active` ; fallback : si data jamais arrivée, le modal Vue reste visible.
- Il n'y a PAS de fonction `buildCartItem` dans ce fichier (vérifié grep — `buildCartItem` est dans le wizard kiosk, hors périmètre) ; la sérialisation panier caisse est faite côté Vue après `syncAndSubmit`.

## 4. Backend — flux critiques (chaînes file:line)

### F1 — Prise de commande caisse (vente directe payée inline)
`PosComponent.orderSubmit` (:3958, items JSON + idempotency) → `PaymentComponent` quote `POST admin/pos/quote` (:726) → `routes/api.php:803` → `PosController::quote` (:164, gate `permission:pos` :172-174, normalize walk-in :224-228) → `OrderQuoteService::quote` (:56, transactionnel, `calculatePricing` :286 → **PricingService** ; HMAC quote_token/signature, TTL config, replay :410) → POST `admin/pos` (`routes/api.php:806`, throttle `pos-order-create` + `idempotency`) → `PosController::store` (:54) → garde tiroir `assertCashDrawerSessionOpenIfCashInvolved` (:90, **bypass si `config('pos.simulation_hardware')===true`** :95-97) → `OrderService::posOrderStore` (:657) : Cache::lock idempotency (:682-685), strip totaux client `unset total/subtotal/discount` (:706 GAP-20-3), garde branche cashier (:735-741), statut initial `AutoPrepareOnPaidPolicy` (:762), `payment_status = PAID` sauf déféré (:783), `OrderQuoteService::sealForCommit` (:1059), **fiscal_sequence_no alloué à la création SAUF déféré** (:1114-1117), split `SplitPaymentService` dans la même transaction (:1238-1252).

### F2 — Walk-in déféré + encaissement borne (file unifiée counter-collect)
Création : kiosk Plan B (FrontendOrderService, hors périmètre) OU POS avec `pos.walkin_route_to_counter`/`defer_to_counter` (`OrderService:721-725` → `PENDING_COUNTER` + `pos_payment_method=COUNTER_DEFERRED` + `payment_method=CASH_ON_DELIVERY`, **fiscal seq NON alloué**). File : `GET /admin/pos/counter-collect/pending` (route-closure `routes/api.php:807-853`) : `payment_status=PENDING_COUNTER` + `status != CANCELED` (:822) + (kiosk KIOSK/TAKEAWAY | pos COUNTER_DEFERRED | **filet source_surface NULL** :833-838), FIFO created_at, cap 200, scoping branch (:842-845). Confirm : `POST counter-collect/{order}/confirm` (:854) → `PaymentService::confirmCounterPayment` (:193) : modes autorisés (:203-209), `lockForUpdate` (:220-223), **déjà-PAID → replay même caissier = no-op 200, autre caissier = `PaymentAlreadyCollectedException` → 409** (:278-309, route :880-895), refus statut terminal (:323-327), refus received < total en CASH (:329-333), **alloc fiscal_sequence_no à l'encaissement** (:335-337), PAID + auto-prepare ACCEPT→PREPARING via `AutoPrepareOnPaidPolicy` (:366-374, carve-out CASH kiosk), `Transaction` firstOrCreate (:390-401), audit_logs HMAC (:403-415) ; post-commit : event `OrderPaidAtCounter` (:423), broadcast `OrderStatusChanged` best-effort (:437), mouvement tiroir CASH (:456-458). Cancel : `cancelCounterPayment` (:635). Legacy : `POST collect-kiosk-cash/{order}` → `OrderService::collectKioskCash` (:2511).

### F3 — Tiroir-caisse (sessions + mouvements)
Routes `pos/cash-drawer/*` (`routes/api.php:947-965`) → `Pos/CashDrawerSessionController` (open :58 / close :128 / reconcile :162 / current :217 / movements :255) → `CashDrawerService` : invariants I1-I6 documentés (:20-44) — 1 session OPEN par (branch,user), reconcile `expected = opening + Σmovements`, variance > seuil ⇒ raison + permission `cash.reconcile.variance.override`, chaque event → audit_logs HMAC best-effort. Hardware : `Pos/CashDrawerController::open` (kick physique).

### F4 — Impression silencieuse
`GET admin/pos/orders/{id}/escpos` (`routes/api.php:938`) → `PosTicketBytesController::show` (b64) → `posLocalPrinter.js` POST RAW `127.0.0.1:9100/raw` (octets fiscaux exacts). Compteur réimpression + duplicata : `POST orders/{id}/print-receipt` → `PosReceiptPrintController::increment` (:40, audit), ticket cuisine `::kitchen` (:98, best-effort sans audit fiscal). Afficheur client : `POST pos/customer-display` (:941).

### F5 — Historique / statuts / remboursement / loyalty
`pos-order.*` (`routes/api.php:1018-1067`) → `PosOrderController` : index/show/destroy/export, `changeStatus` (:312, idempotency+throttle, OrderStateMachine), `refundWithCounterEntry` (:47, contre-écriture miroir NF525 dans le Z courant, parent immutable), `reorderItems` (:379). Loyalty : `POST pos-order/{order}/redeem-loyalty` → `PosLoyaltyController::redeem` (:36) — bypass BranchScope singulier puis check branche explicite (:45-56, anti cross-branch), `PosRedemptionService`. Parked : `pos/parked-orders` CRUD → `Pos/ParkedOrderController` → `app/Services/PosParkedOrderService` (park :16, listForOperator :62, recall :72, discard :195, purge :204).

### F6 — Catégories (category-first) & composer
`GET pos-category` (`routes/api.php:1179-1181`) → `PosCategoryController::index` (:36) : allowlist slugs `config('pos.featured_category_slugs')` — **vide = tout featured** (:204-206 via `$featuredSet===null`), sentinel id=0 « Toutes » (:195-202), tri featured-first (:220-225), shim `PosMenuProjection` (:230-237). Frontend : landing = grille catégories (`posBrowseView.js`, owner /goal 2026-06-23). Composer (profils wizard produits) : `composer.*` (`routes/api.php:773-797`) → `ComposerProfileController` (show/store/byCategory/publish/diff/applyTemplate) + `ComposerStepController`.

### F7 — Cash overview / rapports sessions
`cash-sessions-report.*` (:1136-1139) → `CashSessionReportController::index` (:60) ; `cash-overview.*` (:1147) → `CashOverviewController::index` (:80, buckets par méthode :542, cash non-enregistré :302, session ouverte :397). Permission `cash-sessions-report` partagée.

## 5. Config (config/pos.php)
- `simulation_hardware` (:37) — bypass gardes matérielles uniquement, NF525 préservé ; interdit en prod (boot guard AppServiceProvider per CLAUDE.md §8).
- `rate_limit.quote/order_create/order_update` = 120/60/120 (:59-63).
- `featured_category_slugs`/`featured_category_ids` (:104-115) — vide = toutes.
- `auto_prepare_on_paid` = true (:141-145) — ACCEPT→PREPARING au paiement (exception CASH kiosk).
- `manual_discount_enabled` = true (:172-176) — kill-switch F1 (TVA sur base nette, fixé sous LOCK).
- `walkin_route_to_counter` = false défaut (:202-206) — owner gate ; `defer_to_counter` per-request possible.

## 6. Invariants observés
1. Pricing SSOT backend : totaux client strippés avant create (`OrderService.php:706`) ; devis HMAC signé/scellé (`OrderQuoteService::sealForCommit:111`).
2. Fiscal gap-free : seq alloué à la création POS inline (`OrderService.php:1114-1117`) XOR à l'encaissement pour le déféré (`PaymentService.php:335-337`) — jamais les deux.
3. Encaissement double-caissier : lockForUpdate + discriminant audit-row → 409 typé (`PaymentService.php:278-309`, `routes/api.php:880-895`) ; 409 non caché par idempotency (2xx-only).
4. Cash sans session tiroir = 422 `CASH_NO_OPEN_SESSION` (`PosController.php:62-69`) sauf simulation_hardware.
5. Isolation branche : garde création (`OrderService.php:735-741`), file counter-collect scoped (`routes/api.php:842-845`), loyalty cross-branch 403 (`PosLoyaltyController.php:54-56`), idempotency scopée (branch, customer, key) (`OrderService.php:684-691`).
6. Statut terminal inencaissable (`PaymentService.php:323-327`) + annulées exclues de la file (`routes/api.php:822`).
7. Wizard frozen ne calcule aucun prix facturé : il re-clique le DOM Vue (`syncAndSubmit:3723`) ; extras facturés = `setExtraQuantity` SSOT (`ItemComponent.vue:740`).

## 7. Risques préliminaires (à vérifier vagues suivantes — PAS certifiés)
- R1 · `PaymentService.php:341-343` : `pos_received_amount` persisté **seulement si mode=CASH** ; or `PosCounterCollectModal` (commit `594eb92f5`) fait saisir un « Montant carte » et l'envoie en `received`. Le montant carte tapé semble donc perdu (Transaction.amount = total, pas le saisi). Vérifier l'intention compta « X carte / X espèces ».
- R2 · `PosOrderRequest.php:117-118` : `request('pos_payment_method') === PosPaymentMethod::CARD` — strict `===` string-vs-int ; les règles conditionnelles (note 4 chiffres, received requis) pourraient ne jamais s'armer selon le type envoyé (bug déjà noté « laissé exprès » en mémoire projet — à confirmer sur payloads réels).
- R3 · Le token de commande séquentiel est généré en **localStorage par poste** (`PosComponent.vue:4012-4018`) — collision possible multi-postes (V1 mono-poste = OK, à documenter).
- R4 · `admin-pos-v4.blade.php:35,136` : cache-busting `?v=…{{ time() }}` à chaque requête — jamais de cache navigateur pour pos-wizard.js/css (perf, pas un bug).
- R5 · File counter-collect = route-closures (~150 l. dans routes/api.php:807-944) plutôt que controller — testabilité/lisibilité.
- R6 · `PosController::quote` bypass permission si `api/frontend/*` (:172) — dépend du montage de route ; croiser avec W4 sécurité.

## 8. Couverture de tests observée (ls réels)
- **PHP** `tests/Feature/Pos/` (24 fichiers) : `CounterCollectQueueRobustTest`, `PosWalkinDeferredCreateTest`, `PosCashTrailTest`, `PosSimulationHardware4ScenariosTest`, `SplitPaymentEndToEndTest`, `PosTicketBytesEndpointTest`, `PosReceiptPrintFlowTest`, `PosLoyaltyRedeemTest`, `QuoteBindingTest`, `PosQuoteVariationConstraintTest`, `CashDrawerSessionOwnershipTest`, `FloorplanControllerTest`, `RefundBypassGuardTest`, etc.
- **PHP** `tests/Feature/Cash/` (12) : `CashDrawerServiceTest`, `CashDrawerConcurrentSessionTest`, `CashVarianceGateTest`, `RecordMovementLockTest`, `ZReportCashEnrichmentTest`, `PaymentServiceCashHookTest`, etc.
- **PHP** racine Feature : `PosPricingSsotProofTest`, `PosKioskPricingParityTest`, `QuoteTamperTest`, `QuoteReplayIdempotencyTest`, `PaymentNoopIdempotencyTest`, `PosDiscountForgeryTest`, `POSComprehensiveTest`, `PosParkedOrderTest`, …
- **JS** `tests/js/` : `posBrowseView.spec.js`, `posCart*.spec.js` (5), `posSplitPayment*.spec.js` (2), `posWizardBridgeExtraQuantity.spec.js`, `posOrderIdempotency.spec.js`, `posReceiptBuilder.spec.js`, `receiptNf525FiscalRender.spec.js`, `PosComponent.spec.js`, `PosCashDrawerSessionDialog.spec.js`, `posOfflineQueue*.spec.js`, `posPaymentComponentContract.spec.js`, etc.

## 9. Questions ouvertes
1. Le « Montant carte » saisi (R1) doit-il être persisté quelque part d'autre que `received` en payload d'audit (`PaymentService.php:412`) pour la ventilation compta ?
2. `walkin_route_to_counter` est-il activé en prod VPS (.env) ? Le comportement de la caisse change radicalement (encaissement différé pour tout walk-in).
3. Les 4 sections `viande/pain/…` hardcodées du wizard frozen (VIANDES :49, ALL_SAUCES :65) vs composer profiles (`posWizardComposerAware.enabled`, blade :110) — quel chemin est actif en prod ?
4. `/admin/pos` (app.js) vs `/admin/pos-v4` (pos-app.js) : lequel est la surface canonique utilisée par l'owner aujourd'hui ?
