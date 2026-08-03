# AUDIT & PLAN MAÎTRE — PARCOURS CAISSE (POS) — Prise de commande à finition

## 0. Métadonnées

Document v1.0 — cible **Codex (codex-extension / gpt-5.5-pro, reasoning xhigh)** pour Vague 2 Caisse V1. Auteur : Claude (architecte produit + audit). Date : 2026-04-25. Branche de travail : celle du dépôt actif (à préciser au moment de l’exécution). Prérequis Wave B livrés et à respecter : `OrderQuoteService` (HMAC + TTL 60s, FK-015/FK-017/FK-032/FK-036/FK-055), `pos_parked_orders` (table), endpoints `pos/parked-orders`, `pos/floorplan`, `pos/cash-drawer/open`, `pos/customers/lookup-by-nfc`, idempotency `X-Idempotency-Key` branch-scopé sur `posOrderStore`, OrderStateMachine V1 (11 transitions), pricing SSOT (`PricingService::calculateOrder` via `PricingRequest::forPos`). SSOT à charger côté Codex avant lecture de plans : `docs/ORDER_FLOW.md`, `docs/DEVICE_FLOW.md`, `docs/BUSINESS_RULES.md`, `docs/PRICING_SSOT.md`, `docs/AUTHZ_MATRIX.md`, `docs/EVENT_CONTRACT.md`, `docs/REALTIME_SETUP.md`, `reports/audit/TRACEABILITY_MATRIX_CAISSE_V1_2026-04-25.md`, `plans/PLAN_CAISSE_V1_GPT_MASTERPLAY_2026-04-25.md` §2 (ancrages). Ce document **n'est pas un patch** : c'est le brief d'orchestration du parcours opérateur POS, étape par étape, prêt à être éclaté en lots Codex.

## 1. Carte du parcours (vue opérateur)

Numérotation linéaire, du login caissier à la commande finalisée. Les états optionnels (park, remise, split) sont des branches référencées en §4.

- **S00** — Auth caissier (login admin + permission `pos`, redirect SPA)
- **S01** — Boot du shell POS (`/admin/pos`, lazy chunk `pos-shell`, settings/branche)
- **S02** — Sélection branche & contexte poste (Admin global vs Manager mono-branche)
- **S03** — Sélection type de commande (`order_type` : DINE_IN / TAKEAWAY / DELIVERY)
- **S04** — (DINE_IN) Sélection table via `FloorplanComponent` (assign / transfer / release)
- **S05** — (DELIVERY/TAKEAWAY) Sélection ou création client (search, créer, NFC lookup)
- **S06** — Catalogue produits (`PosCategory` + `Item`) — recherche, scroll, filtres dispo
- **S07** — Configuration article (variations, extras, addons, note, quantité) → ajout panier
- **S08** — Panier (`posCart` store) — modifier, supprimer, note ligne, **subtotal indicatif**
- **S09** — Remise & coupon (gate manager si > seuil ; raison obligatoire)
- **S10** — **Quote autoritaire** : `POST /admin/pos/quote` → `quote_token` + `quote_signature` + `total_ttc`
- **S11** — Park / reprise (sauvegarde panier nominatif `pos_parked_orders`) — branche
- **S12** — Écran paiement (`PaymentComponent`) — choix méthode (CASH / CARD / TR / WALLET / SPLIT)
- **S13** — Encaissement multi-tender / monnaie rendue / TPE (relais hardware lab)
- **S14** — Soumission `POST /admin/pos` avec `quote_token` + `X-Idempotency-Key` → Order créé en `ACCEPT` / `PAID`
- **S15** — Tirage ticket (`/pos/orders/{order}/print-receipt`) + tiroir-caisse (`/pos/cash-drawer/open`)
- **S16** — Push KDS automatique (`OrderStatusChanged` after-commit → station(s) cuisine)
- **S17** — File caisse temps réel : nouvelles commandes externes (kiosk/web) + statut KDS via Echo
- **S18** — Reprise / réimpression / refund / retour (DELIVERED → RETURNED, raison obligatoire)
- **S19** — Clôture journée : Z-report (`ZReportService`), tiroir, fiscalité

## 2. Matrice écran → composant Vue → route → service backend

| Étape | Composant `.vue` | Store/Vuex | Route name | API (méthode + path) | Service PHP principal | Données clés | Pricing à cette étape |
|---|---|---|---|---|---|---|---|
| S00 | `LoginComponent` (hors POS) | `auth` | `admin.login` | `POST /api/v1/admin/login` | `LoginController` | token Sanctum, permissions | aucun |
| S01 | `PosComponent` | `frontendSetting`, `defaultAccess` | `admin.pos` | `GET /api/v1/admin/default-access` ; `GET /api/v1/admin/setting/frontend` | `DefaultAccessController` | branch_id auth, paramètres POS | aucun |
| S02 | `PosComponent` (header) | `frontendBranch` | — | `GET /api/v1/admin/branch/{id}` | `BranchController` | `branch_id`, devise | aucun |
| S03 | `PosComponent` (toggle) | `posCart/setScope` | — | (local) | — | `order_type`, `pos_dine_in_enabled` | aucun |
| S04 | `FloorplanComponent` | `diningTable` | `admin.pos.floorplan` | `GET /api/v1/admin/pos/floorplan/state` ; `POST /pos/floorplan/{tableId}/assign\|release` ; `POST /pos/floorplan/transfer` | `FloorplanController` | `dining_table_id`, états table | aucun |
| S05 | `CreateCustomerAddressComponent` | `user` | — | `GET /api/v1/admin/customer` ; `POST /api/v1/admin/customer` ; `POST /api/v1/admin/pos/customers/lookup-by-nfc` | `CustomerController`, `CustomerNfcLookupController` | `customer_id`, addresses | aucun |
| S06 | `PosComponent` + `ItemComponent` (catalogue) | `posCategory`, `item` | — | `GET /api/v1/admin/pos-category` ; `GET /api/v1/admin/item` (filtré branch) | `PosCategoryController`, `ItemController` | items, dispo, prix vitrine | indicatif (vitrine) |
| S07 | `ItemComponent` (modal config) | `posCart/add` | — | (local) — variations/extras servis avec l'item | — | `item_id`, variations[], extras[], qty, note | indicatif |
| S08 | `PosComponent` (cart panel) | `posCart` getters `subtotal`, `discount` | — | (local) | — | lignes panier | **indicatif** (jamais autoritatif) |
| S09 | `PosComponent` (modal remise) | `posCart` | — | (local — applied at quote) | `OrderService::assertPosManualDiscountAllowed` | `discount`, `discount_reason`, `coupon_id` | indicatif |
| S10 | `PaymentComponent.beforePaying()` | — | `pos.quote` | `POST /api/v1/admin/pos/quote` | `OrderQuoteService::quote()` | `quote_token`, `signature`, `total_ttc`, `subtotal`, `discount` | **AUTORITATIF (SSOT)** |
| S11 | `ParkedOrdersComponent` | `posParked` | `pos.parked-orders.*` | `GET/POST/DELETE /api/v1/admin/pos/parked-orders[/{id}]` | `ParkedOrderController` | snapshot panier, label/customer | aucun (snapshot) |
| S12 | `PaymentComponent` | — | — | (locale, attend submit) | — | `pos_payment_method`, `pos_payment_note`, `pos_received_amount` | reprend `quote.total_ttc` |
| S13 | `PaymentComponent` (split) | — | — | À CONFIRMER (`PaymentService::splitTender` non vérifié) | `PaymentService` (FK-019, FK-074) | tenders[], change | autoritatif backend |
| S14 | `PaymentComponent.placeOrder()` | `posCart/clear` | `pos.store` (sans nom explicite) | `POST /api/v1/admin/pos` (header `X-Idempotency-Key`) | `OrderService::posOrderStore` | `Order` créé, status=ACCEPT, payment_status=PAID | **recalcul backend complet via PricingService SSOT** |
| S15 | `ReceiptComponent` + `ReceiptDuplicataMarker` | — | `pos.orders.print-receipt` ; `pos.cash-drawer.open` | `POST /api/v1/admin/pos/orders/{order}/print-receipt` ; `POST /api/v1/admin/pos/cash-drawer/open` | `PosReceiptPrintController`, `CashDrawerController` | numéro ticket, `print_count` | aucun |
| S16 | (back-only) | — | — | événement `OrderStatusChanged` after-commit (outbox) | `OrderStateMachine::recordTransition` + `DispatchDomainEventsJob` | `order_status_transitions` | aucun |
| S17 | `PosComponent` (file/notifications) | `posCart`, broadcasted `kds-poll` | — | Echo channels `branch.{branch_id}` (private) | `OrderCreated`, `OrderStatusChanged` | feed temps réel | aucun |
| S18 | `PosOrderListComponent` (À CONFIRMER : composant exact en `resources/js/components/admin/pos-order/`) | — | `posOrder.*` | `POST /pos-order/change-status/{order}` ; `POST /change-payment-status/{order}` ; `GET /reorder-items/{order}` | `OrderService::changeStatus`, `changePaymentStatus`, `PosOrderController::reorderItems` | OrderStatus, raison | recalcul total au refund (FK-074) |
| S19 | `ZReportComponent` (À CONFIRMER : `resources/js/components/admin/reports/`) | — | `admin.reports.z` | À CONFIRMER (`ZReportController` route exacte) | `ZReportService` | totaux journée, scellement HMAC | autoritatif backend |

> **À CONFIRMER** : noms exacts des composants pour S18/S19 — ouvrir `resources/js/router/modules/` (autres que `posRoutes.js`) et `resources/js/components/admin/pos-order/`, `resources/js/components/admin/reports/`. Codex doit les valider avant tout patch.

## 3. Détail millimétrique par étape

### S00 — Auth caissier
- **But** : authentifier un humain rattaché à une `branch_id` avec permission `pos`.
- **UI** : email + password, branche affichée après connexion ; erreur 401/403 explicite.
- **Front** : action `auth/login`, redirect contrôlé (`POS Operator loses their correct redirect URL after a page refresh` — déjà câblé routes/api.php:208).
- **Back** : `LoginController::login` ; ability check via Spatie permission `pos`.
- **Prix** : N/A.
- **Sync RT** : abonnement Echo conditionné à `branch_id` après login.
- **Tests** : `tests/Feature/Auth/LoginPosOperatorTest.php` (À CRÉER si absent), `tests/e2e/01-pos-login.spec.js`.
- **Abus** : login d'un manager d'une autre branche ; permission révoquée pendant session ; double-connexion sur 2 onglets.
- **Tâche Codex** : [EASY] *Sentinel : ability `pos` requise à l'init du shell POS* — DONE = test feature qui assert 403 si permission retirée pendant boot.

### S01 — Boot shell POS
- **But** : charger le chunk `pos-shell` < 220 KB gz, hydrater stores essentiels.
- **UI** : skeleton (`SkeletonGrid.vue`), loader, erreur si settings ko.
- **Front** : `PosComponent.created()` → dispatch `defaultAccess/show`, `frontendSetting`, `frontendBranch/show`, `posCart/setScope`.
- **Back** : pas de mutation ; renvoie config + settings.
- **Prix** : N/A.
- **Sync RT** : connexion Echo `private-branch.{branch_id}` après boot.
- **Tests** : `tests/js/posBootConfig.spec.js` (FK-035), KPI bundle dans `reports/baseline/POS_V4_PERF_HISTORY.md`.
- **Abus** : boot avec `branch_id=0` non Admin ; Echo qui s'abonne à un canal d'une autre branche.
- **Tâche Codex** : [EASY] *Sentinel : refus d'abonnement Echo cross-branch* — DONE = vitest qui mock `$echo` et assert refus si `branch_id` ≠ user.

### S02 — Sélection branche & contexte
- **But** : pour Admin global, choisir branche active ; sinon verrouillée.
- **UI** : selector désactivé pour non-Admin (FK-008 cible 7 surfaces).
- **Front** : guard côté store sur change branch.
- **Back** : middleware `branch.scope` ; `BranchScope` global.
- **Prix** : N/A.
- **Tests** : `php artisan test --filter=OrderListBranchExactness` (FK-008), `tests/js/posBranchSelectorAuthz.spec.js` (à créer).
- **Abus** : Admin oublie de switcher → encaissement attribué à la mauvaise branche → fiscalité erronée.
- **Tâche Codex** : [MEDIUM] *Verrou UI : afficher badge branche active permanent + warning toast si Admin n'a pas explicitement choisi* — DONE = sentinel vitest + e2e Playwright assertion badge visible avant tout `POST /pos`.

### S03 — Type de commande
- **But** : positionner `order_type` (DINE_IN/TAKEAWAY/DELIVERY) avant ajout au panier.
- **UI** : trois boutons exclusifs ; DINE_IN désactivé si `pos_dine_in_enabled=false`.
- **Front** : `posCart/setScope({ order_type, branch_id, customer_id })`.
- **Back** : valeur sera revalidée côté `PosOrderRequest`.
- **Prix** : N/A (peut influer delivery_charge plus tard).
- **Tests** : `tests/js/posOrderTypeToggle.spec.js` (à créer), `tests/Feature/PosOrderRequestTypeTest.php`.
- **Abus** : changer `order_type` en cours de prise → quote périmée non rejouée ; cohérence delivery_charge.
- **Tâche Codex** : [EASY] *Reset `quote_token` quand `order_type` change* — DONE = vitest qui prouve l'invalidation.

### S04 — Sélection table (DINE_IN)
- **But** : assigner une table libre / transférer / libérer.
- **UI** : grid floorplan (`FloorplanComponent`, 284 lignes) ; états : `free`, `occupied`, `reserved`, `cleaning`.
- **Front** : axios `pos/floorplan/{id}/assign|release`, `pos/floorplan/transfer`.
- **Back** : `FloorplanController` ; transitions atomiques sur `dining_tables` ; release sur `posOrderStore` réussi (FK-071).
- **Prix** : N/A.
- **Sync RT** : changement table broadcast pour autres terminaux (À CONFIRMER : event `FloorplanUpdated` existe ?).
- **Tests** : `tests/Feature/Pos/FloorplanReleaseAfterPosOrderTest.php` (FK-071 mappé, à créer).
- **Abus** : 2 caisses assignent la même table en concurrence ; release manqué si `posOrderStore` échoue après assign ; transfert sans permission.
- **Tâche Codex** : [HARD] *Lock pessimiste sur assign + release après commit `posOrderStore`* — DONE = `DiningTableReleaseAfterPosOrderTest` ✓ + e2e concurrence 2 onglets.

### S05 — Client (search / créer / NFC)
- **But** : associer un `customer_id` (DELIVERY obligatoire) ou commande invité.
- **UI** : autocomplete, modal create, bouton scan NFC.
- **Front** : `user/lists` getter ; `POST /pos/customers/lookup-by-nfc`.
- **Back** : `CustomerNfcLookupController::lookup` ; `CustomerController::store`.
- **Prix** : peut activer pricing « membre » / loyalty (À CONFIRMER : `PricingService` lit-il customer_id pour tarif ? Voir `app/Services/PricingService.php` règle membre).
- **Tests** : `tests/Feature/Pos/CustomerNfcLookupTest.php` (À CRÉER), `tests/e2e/pos-customer-create.spec.js`.
- **Abus** : NFC duplicate ; client d'une autre branche affiché ; PII leak via search.
- **Tâche Codex** : [MEDIUM] *Filtrer search clients par `branch_id` (sauf Admin)* — DONE = sentinel `PosCustomerBranchScopeSentinelTest` + audit log NFC scan.

### S06 — Catalogue
- **But** : afficher items disponibles sur la branche courante avec recherche et catégorie.
- **UI** : grid (`ItemComponent`, 1276 lignes), recherche, scroll virtualisé (À CONFIRMER), filtre indispo.
- **Front** : `posCategory/lists`, `item/lists` ; computed `posItems` filtre stock + dispo.
- **Back** : `PosCategoryController::index`, `ItemController::index` (avec `BranchScope`).
- **Prix** : prix vitrine d'item (jamais utilisé pour total final).
- **Tests** : `tests/Feature/Pos/PosCatalogBranchScopeTest.php`, `tests/js/posCatalogPerf.spec.js`.
- **Abus** : items d'une autre branche listés ; item soft-deleted apparaît ; image lourde casse perf.
- **Tâche Codex** : [MEDIUM] *Sentinel : 0 item d'une autre branche dans la réponse `pos-category`* — DONE = test feature.

### S07 — Configuration article
- **But** : choisir variations/extras/addons + qté + note ; ajouter au panier.
- **UI** : modal article ; prix par variation affiché ; bouton + désactivé si combinaison invalide.
- **Front** : push dans `posCart` avec `cart_line_uid` unique ; recompute subtotal indicatif.
- **Back** : N/A à ce stade ; servi par `Item`+relations ; **PricingService est seul juge final**.
- **Prix** : indicatif uniquement.
- **Tests** : `tests/js/posCartLineDedup.spec.js`, `tests/Feature/Pricing/PricingItemSnapshotTest.php`.
- **Abus** : double-clic ajoute 2× la ligne ; modifier prix via DevTools ; combinaison illégale variations/extras.
- **Tâche Codex** : [MEDIUM] *Snapshot d'allergènes + price freeze sur la ligne au moment de l'ajout (déjà fait via `OrderItemAllergenSnapshot::hydrate`, voir OrderService.php:668) — sentinel front empêchant tampering UI* — DONE = vitest qui assert que toute mutation manuelle de `unit_price` côté store est ignorée par le quote serveur.

### S08 — Panier
- **But** : éditer panier (modifier qty, supprimer, note ligne, vider).
- **UI** : liste, total indicatif, bouton "Park", "Remise", "Payer".
- **Front** : `posCart` mutations ; subtotal computed via `posCart/subtotal` getter.
- **Back** : N/A.
- **Prix** : indicatif. **Aucune confiance**.
- **Tests** : `tests/js/posCartCrud.spec.js`.
- **Abus** : modification après quote sans regen → divergence.
- **Tâche Codex** : [EASY] *Invalider `quote_token` à toute mutation panier* — DONE = vitest, et erreur explicite côté UI si tentative de paiement avec quote périmée.

### S09 — Remise & coupon
- **But** : appliquer remise (% ou fixe) avec raison + coupon code.
- **UI** : modal, input remise, input raison (obligatoire si remise > seuil), saisie code coupon.
- **Front** : champs `discount`, `discount_reason`, `coupon_id` ; v-model corrigé (FK-079 sentinel).
- **Back** : `OrderQuoteService::quote()` valide ; `OrderService::assertPosManualDiscountAllowed` (cap par rôle, FK-018 PosSubtotalForgery sentinel).
- **Prix** : recalcul **uniquement par PricingService SSOT** au quote ; champ client ignoré pour total.
- **Tests** : `PosSubtotalForgerySentinelTest`, `tests/js/sentinels/PosDiscountReasonBindingSentinelTest.spec.js` (FK-079).
- **Abus** : forger `subtotal` côté client → bypass cap ; coupon expiré ; cumul coupon+remise non autorisé.
- **Tâche Codex** : [HARD] *Backend rejette toute remise au-delà du cap rôle (server-authoritative), audit log obligatoire avec actor_id+reason* — DONE = `PosSubtotalForgerySentinelTest` vert + log entry vérifiable.

### S10 — Quote autoritaire (SSOT)
- **But** : produire `quote_token`, `quote_signature`, `total_ttc` signés HMAC, TTL 60s.
- **UI** : appel automatique avant ouverture paiement (`PaymentComponent.beforePaying`).
- **Front** : `axios.post('admin/pos/quote', form)` → assigne `quote_token`/`quote_signature` au form.
- **Back** : `OrderQuoteService::quote($request, 'pos')` (FK-015) ; persist `OrderQuote`.
- **Prix** : **SSOT autoritaire** (PricingService).
- **Sync RT** : N/A.
- **Tests** : `QuoteTamperTest`, `QuoteExpirationTest`, `QuoteReplayIdempotencyTest`, `QuoteCurrencyOriginTest`, `QuoteDiscountAuthoritativeTest` (mission CV1-M05 livrée).
- **Abus** : rejouer un token consommé ; manipuler payload entre quote et store ; quote d'une autre branche.
- **Tâche Codex** : [HARD] *Bind `quote_token` à `branch_id` + `actor_id` + hash items à la consommation* — DONE = tests existants verts + nouveau test `QuoteBranchActorBindingTest`.

### S11 — Park / reprise
- **But** : sauvegarder un panier nominatif puis le restaurer ailleurs.
- **UI** : `ParkedOrdersComponent` (345 lignes), bouton "Park" sur PosComponent, modal reprise (`@restored="applyParkedSnapshot"`).
- **Front** : `posParked` store ; CRUD via `pos/parked-orders`.
- **Back** : `ParkedOrderController` ; table `pos_parked_orders` (FK-088 manque `expires_at`).
- **Prix** : snapshot panier brut, **pas** de total figé ; quote regénérée à la reprise.
- **Tests** : `ParkedOrderExpirationTest` (FK-088), `tests/Feature/Pos/ParkedOrderBranchScopeTest.php` (à créer).
- **Abus** : reprise d'un park d'une autre branche ; explosion table sans purge ; concurrence 2 caissiers récupérant le même park.
- **Tâche Codex** : [MEDIUM] *Ajouter `expires_at` (24h défaut) + job purge + lock optimiste reprise (delete + return atomic)* — DONE = migration safe + `ParkedOrderExpirationTest`.

### S12 — Écran paiement
- **But** : choisir méthode et montant payé.
- **UI** : `PaymentComponent` (332 lignes) ; boutons CASH/CARD ; champ "reçu" (cash) ; affiche `total_ttc` du quote.
- **Front** : `selectMethod`, `beforePaying` (réquote), `placeOrder`.
- **Back** : N/A jusqu'au submit.
- **Prix** : reprend `quote.total_ttc` autoritatif. **Bug FK-081** : composant mute directement `props.form` (`this.$props.props.form.X = …`) → cible refactor (gate `GATE_PAYMENT_PROP_MUTATION_2026-04-26`).
- **Tests** : `PaymentComponentPropMutationSentinelTest` (FK-081), `PaymentConfirmAbilitySentinelTest` (FK-029), `tests/js/payment-401-retry.spec.js` (FK-089).
- **Abus** : double submit ; déconnexion mid-paiement ; payment_method côté client ≠ enum backend (FK-073).
- **Tâche Codex** : [HARD] *Refactor PaymentComponent : `emit('update:form')` ou wrapper local — fin des mutations directes de props* — DONE = sentinel verte + e2e payment golden path + `payment-401-retry`.

### S13 — Encaissement multi-tender / TPE
- **But** : permettre split (cash + card + TR + wallet), enregistrer monnaie rendue, intégrer TPE.
- **UI** : ajouts tenders successifs jusqu'à `total_ttc` atteint ; bouton "Annuler dernier tender" ; intégration TPE (statuts EN_COURS / OK / ÉCHEC / TIMEOUT).
- **Front** : appels TPE via service hardware (À CONFIRMER : `resources/js/services/hardware/`) ; idempotence par `tender_uid`.
- **Back** : `PaymentService` (FK-019, FK-074, FK-028) — **non encore migré sur ledger** (PLAN-04A pilote ou full).
- **Prix** : reste serveur-autoritatif via quote.
- **Sync RT** : statut TPE renvoyé à la caisse.
- **Tests** : `PaymentLedgerStateMachineTest` (FK-019), `PartialRefundLedgerTest` (FK-074), `HardwareTpeTimeoutTest` (FK-025).
- **Abus** : double-charge TPE sur retry ; sum tenders ≠ total ; tender approuvé hors ligne sans rapprochement.
- **Tâche Codex** : [HARD] *Multi-tender ledger côté `PaymentService` + idempotency `provider_reference` UNIQUE (PaymentProviderReferenceUnique, FK-028) ; gate `GATE_PAYMENT_LEDGER_V1`* — DONE = `PaymentLedgerStateMachineTest` + assertion sum tenders == total.

### S14 — Soumission `posOrderStore`
- **But** : créer `Order` en `ACCEPT` + `PAID`, recalcul total backend, dispatch événements après commit.
- **UI** : loader bloquant ; succès → reçu ; erreur → toast + restauration panier.
- **Front** : `axios.post('admin/pos', form, { headers:{ 'X-Idempotency-Key': uuid }})`.
- **Back** : `PosController::store` → `OrderService::posOrderStore` (OrderService.php:570) ; idempotency branch-scopée (OK), unset `total/subtotal/discount` client (OK), `PricingService::calculateOrder` autoritatif (OK), `OrderItemAllergenSnapshot::hydrate` (OK), audit `OrderStateMachine::recordTransition`, dispatch `OrderCreated`+`OrderStatusChanged` after-commit.
- **Prix** : **recalcul intégral SSOT** ; `assertPosManualDiscountAllowed`.
- **Sync RT** : `OrderStatusChanged` → KDS station(s), OSS, file caisse.
- **Tests** : `PosTotalServerAuthoritativeSentinelTest` (FK-017/032), `PosIdempotencyBranchScopeTest` (FK-021), `AfterCommitDispatchTest` (FK-070), `OrderServiceFrontendOrderServiceContract` (FK-016).
- **Abus** : double-clic submit ; payload tampered post-quote ; tentative create cross-branch ; `branch_id=0` sans Admin ; `OrderItem::insert` qui contourne mutators.
- **Tâche Codex** : [HARD] *Forcer consommation `quote_token` dans `posOrderStore` (rejet si absent/expiré/replayé) + binding `branch_id`/`actor_id`/items hash* — DONE = `PosTotalServerAuthoritativeSentinelTest` + `QuoteReplayIdempotencyTest` ✓ sur chemin POS réel.

### S15 — Tirage ticket + tiroir
- **But** : imprimer ticket fiscal (NF525), ouvrir tiroir cash si méthode CASH.
- **UI** : `ReceiptComponent` (479 lignes) ; bouton "Reprint" → `ReceiptDuplicataMarker` ajoute marquage "DUPLICATA".
- **Front** : `axios.post('admin/pos/orders/{id}/print-receipt')` ; bouton tiroir.
- **Back** : `PosReceiptPrintController::increment` (compteur prints) ; `CashDrawerController::open` (audit log).
- **Prix** : reprend snapshot order_items.
- **Tests** : `ReceiptAuditFailureAlertTest` (FK-075), `ReceiptDuplicataIncrementTest` (à créer).
- **Abus** : reprint sans marquage → fraude fiscale ; tiroir ouvert sans transaction ; échec imprimante silencieux.
- **Tâche Codex** : [MEDIUM] *Audit alert si `printer.print` échoue (FK-075) + assertion duplicata après 1er print* — DONE = test feature + alert observable.

### S16 — Push KDS (back-only)
- **But** : émettre tickets en cuisine après commit DB.
- **UI** : N/A (côté KDS).
- **Back** : `OrderStateMachine::recordTransition` + outbox `DispatchDomainEventsJob` ; règle release explicite (FK-037) ciblée.
- **Prix** : N/A.
- **Sync RT** : `OrderCreated` (kitchen-eligible filter) → channel station ; `OrderStatusChanged`.
- **Tests** : `KdsTransitionWhitelistSentinelTest` (FK-037), `KdsExpectedStatusConflictSentinelTest` (FK-068), `KdsOrderItemsListParityTest` (FK-069), `KitchenReleaseRuleTest` (FK-037).
- **Abus** : event avant commit (FK-070) ; event hors branche ; doublon sur retry job.
- **Tâche Codex** : [HARD] *Implémenter `KitchenReleaseRule` explicite (ticket release ≠ status seul) + `expected_status` côté KDS + dedupe outbox* — DONE = tests FK-037/068/069 verts + gate `GATE_KDS_BUMP_V1`.

### S17 — File caisse temps réel
- **But** : voir nouvelles commandes (kiosk/web/table) et statut KDS sans recharger.
- **UI** : panel "À encaisser" + "En préparation" ; badge nouveau ; son optionnel.
- **Front** : abonnement Echo `private-branch.{branch_id}` ; dedupe per-tab par `order_id+updated_at` (FK-076).
- **Back** : pas de polling — Echo/Pusher (`docs/REALTIME_SETUP.md`).
- **Tests** : `OrderListBranchExactnessSentinelTest` (FK-008), `tests/js/realtime-dedupe.spec.js` (FK-076), `KdsAdminGlobalRealtimeTest` (FK-040).
- **Abus** : tab inactif loupe events (Echo reconnect) ; cross-branch broadcast ; flood d'events.
- **Tâche Codex** : [MEDIUM] *Resync au focus tab : appel `pos-order` filtré branch_id + last_updated_at + reconcile* — DONE = vitest, e2e Playwright multi-onglets.

### S18 — Refund / retour / annulation post-livraison
- **But** : annuler/retourner commande, rembourser, recréditer wallet.
- **UI** : modal "Changer statut" avec raison obligatoire (CANCELED/REJECTED/RETURNED) ; modal "Refund" (full/partiel).
- **Front** : `axios.post('pos-order/change-status/{id}', { status, reason })`.
- **Back** : `OrderService::changeStatus` (OrderService.php:1517) ; cashback via `PaymentService::cashBack` ; loyalty refund ; events après commit.
- **Prix** : recalcul partiel (refund partiel : FK-074).
- **Tests** : `tests/Feature/Pos/PosChangeStatusBranchTest.php`, `PartialRefundLedgerTest` (FK-074), `tests/e2e/pos-refund.spec.js`.
- **Abus** : refund > total payé ; double cashback (FK-034 wallet) ; bypass raison ; transition non-légale.
- **Tâche Codex** : [HARD] *Refund partiel ledger-aware + rejet sum > paid + raison obligatoire serveur* — DONE = `PartialRefundLedgerTest` + `CreditWalletIdempotencyTest`.

### S19 — Clôture journée (Z)
- **But** : produire un Z scellé NF525, totaux par méthode, stamp temporel.
- **UI** : page "Z report" ; bouton "Clôturer" (gate manager).
- **Front** : composant À CONFIRMER (`resources/js/components/admin/reports/`).
- **Back** : `ZReportService` (FK-010, gate `GATE_FISCAL_KIOSK_V1`) ; HMAC sealing.
- **Prix** : agrégats autoritatifs.
- **Tests** : `FiscalSealingHmacTest` (FK-010), `ZAggregationKioskRoutingTest` (FK-062).
- **Abus** : double Z sur même journée ; gap ticket ; agrégation cross-branch.
- **Tâche Codex** : [HARD] *Idempotence Z par (branch_id, business_date) + scellement HMAC + audit alert sur gap* — DONE = `FiscalSealingHmacTest` + `ZAggregationKioskRoutingTest`.

## 4. Parcours alternatifs

- **Park / reprise** : §S11 — branche depuis S08/S10, reprise vers S08 puis re-quote forcée.
- **Annulation pré-livraison** : depuis S14 réussi, transition `ACCEPT → CANCELED` via `pos-order/change-status` (raison obligatoire). Stock release via `OrderCanceled` event (FK-034).
- **Remise opérateur** : §S09 — gate cap par rôle ; raison obligatoire au-delà du seuil ; audit log.
- **Coupon** : §S09 — `coupon_id` ; ne se cumule pas avec remise manuelle (À CONFIRMER règle exacte dans `CouponService`).
- **Commande invité vs connecté** : `customer_id` nullable pour TAKEAWAY ; obligatoire DELIVERY ; loyalty seulement si connecté.
- **Multi-tender** : §S13 — séquence `tender:add` jusqu'à atteinte `total_ttc` ; ledger garantit somme.
- **Split de note (par item / par part)** : À CONFIRMER — fonctionnalité existe-t-elle ? Si non, marquer hors V1 et déférer V2 (note dans handoff).
- **Erreur réseau au submit** : retry MUST réutiliser même `X-Idempotency-Key` ; UI doit empêcher modification panier tant que retry en cours.
- **Reprise d'une commande passée** : `GET /pos-order/reorder-items/{order}` (PosOrderController.php:125) repeuple le panier avec snapshot — re-quote obligatoire avant paiement (les prix peuvent avoir changé).

## 5. Synchronisation globale (POS comme hub)

- **Visibilité commandes externes** : kiosk crée via `POST /api/frontend/order` → `FrontendOrderService` → status PENDING (cash kiosk) ou ACCEPT (paid kiosk). POS voit la commande via :
  1. **Liste `pos-order`** filtrée `branch_id` (BranchScope).
  2. **Echo `private-branch.{branch_id}`** : event `OrderCreated` après commit.
  3. **File "À encaisser"** : commandes kiosk PENDING cash en attente d'un caissier qui clique `pos/collect-kiosk-cash/{order}` (FK-023/FK-042 : couplage à découpler).
- **Filtre `branch_id`** : `BranchScope` global Eloquent + sentinels (`OrderListBranchExactnessSentinelTest` FK-008, `OrderShowBranchGuardSentinelTest` FK-033). Admin global = `branch_id=0` mais doit explicitement pouvoir choisir branche d'écoute (FK-040).
- **WebSocket** : Echo/Pusher (pas Firebase pour le flux caisse, voir `DEVICE_FLOW.md` §2). Channel privé authentifié via Sanctum + `CheckBranchAccess`.
- **Cohérence KDS / OSS** :
  - **KDS (Chef)** : reçoit ticket via release rule explicite (FK-037 ciblé). Bump = passage `ACCEPT → PREPARING → PREPARED`. Retour POS via Echo.
  - **OSS (écran client)** : lecture seule, listage `PREPARING`/`PREPARED` ; bip + clignote sur `PREPARED`. Branche-scopé via `branch_id` du token kiosk machine.
  - **POS** : reçoit `PREPARED` → caissier sait que ticket peut être remis si DINE_IN/TAKEAWAY ; raccourci `ACCEPT/PREPARING → DELIVERED` (permission `pos`).
- **Cohérence offline** : POS V1 = online uniquement ; kiosk peut faire CB/TR offline mais routes refusent par défaut (FK-030/044, gate `GATE_OFFLINE_SCOPE_V1`).
- **Diagnostique** : `order_status_transitions` (audit), `sync_metrics` (FK-087 manque purge), `correlation_id` propagé.

## 6. Checklist d'audit 360° POS (avant "GO")

| Fonctionnalité | Fonctionnel | Logique métier | Sécurité / Authz | Sync RT | Tests | Owner |
|---|---|---|---|---|---|---|
| Auth caissier (S00) | OK | OK | ability `pos` ✓ | N/A | À COMPLÉTER (login per-permission) | BE |
| Boot shell (S01) | OK | OK | branch boot ✓ | Echo subscribe | KPI bundle ✓ (perf history) | FE |
| Sélection branche (S02) | OK | À CONFIRMER warning Admin | OrderListBranchExactness ✓ | OK | sentinels FK-008 | BE+FE |
| Type commande (S03) | OK | reset quote À FAIRE | N/A | N/A | À CRÉER | FE |
| Floorplan (S04) | OK | release post-store À FIXER (FK-071) | À CONFIRMER concurrent assign | À CONFIRMER event broadcast | À CRÉER | BE+FE |
| Client / NFC (S05) | OK | branch scope À RENFORCER | NFC audit log À CRÉER | N/A | À CRÉER | BE |
| Catalogue (S06) | OK | branch scope ✓ | OK | N/A | À CRÉER | BE+FE |
| Config item (S07) | OK | snapshot allergens ✓ | OK | N/A | À CRÉER | FE |
| Panier (S08) | OK | invalidation quote À FIXER | client price ignoré ✓ | N/A | À CRÉER | FE |
| Remise (S09) | bug FK-079 v-model | server-cap À FORCER | discount-reason audit | N/A | FK-079 sentinel | BE+FE |
| Quote (S10) | OK (M-05 livré) | OK | HMAC TTL replay ✓ | N/A | QuoteTamper/Expiration ✓ | BE |
| Park (S11) | OK | expires_at À AJOUTER | branch scope À CRÉER | N/A | FK-088 | BE |
| Paiement UI (S12) | OK | mut props bug FK-081 | gate ability ✓ | N/A | FK-081 sentinel | FE |
| Multi-tender (S13) | manquant | À IMPLÉMENTER (PLAN-04A) | idempotency provider_ref unique | N/A | FK-019/074 | BE |
| Submit POS (S14) | OK | quote-bound À FORCER | idempotency branch ✓ | OrderCreated after commit ✓ (FK-070 cible) | FK-017/021/070 | BE+FE |
| Ticket / tiroir (S15) | OK | print audit À RENFORCER | À CRÉER cashdrawer audit | N/A | FK-075 | BE |
| KDS push (S16) | partiel | release rule explicite À CRÉER | branch ✓ | dedupe outbox À RENFORCER | FK-037/068/069 | BE |
| File RT (S17) | OK | resync au focus À AJOUTER | cross-branch refusé ✓ | OK | FK-040/076 | FE |
| Refund / retour (S18) | partiel | partial-refund ledger À AJOUTER | wallet idempotency À CRÉER | N/A | FK-034/074 | BE |
| Z-report (S19) | partiel | scellement HMAC ✓ | Z idempotent par jour À FIXER | alerts gaps À CRÉER | FK-010/062 | BE |

## 7. Plan découpé pour Codex (lots ordonnés)

> Format aligné `missions/CV1-MXX-…/input.json` (Codex extension, gpt-5.5-pro xhigh, JSON unique). Numéros `SUGGESTED_LOT` ; les TASK_ID définitifs seront alloués par l'orchestrateur quand les missions seront créées. Chaque lot DOIT lister `mandatory_tests`, `allowlist`, `off_limits`, `gate_dependencies`.

| Lot | Objectif | Allowlist (indicatif) | Tests obligatoires | Dépend de | Difficulté | Risque si raté |
|---|---|---|---|---|---|---|
| **P-01** SUGGESTED_LOT_POS_W2_QUOTE_BIND | Forcer consommation `quote_token` dans `posOrderStore` (binding `branch_id`+`actor_id`+items_hash, rejet expiré/replayé) | `app/Services/OrderService.php`, `app/Services/Order/OrderQuoteService.php`, `app/Http/Requests/PosOrderRequest.php`, `tests/Feature/Pos/QuoteBindingTest.php` | `PosTotalServerAuthoritativeSentinelTest`, `QuoteReplayIdempotencyTest`, `QuoteBranchActorBindingTest` | Wave B (M-05) | HARD | Pricing forgeable → revenu erroné, NF525 KO |
| **P-02** SUGGESTED_LOT_POS_W2_DISCOUNT_GUARD | Server-authoritative caps remise + raison obligatoire serveur + audit log | `app/Services/OrderService.php` (assertPosManualDiscountAllowed), `app/Models/OrderDiscountLog.php` (À CRÉER) | `PosSubtotalForgerySentinelTest`, `PosManualDiscountAuditTest` | P-01 | MEDIUM | Sur-remise non tracée, fraude opérateur |
| **P-03** SUGGESTED_LOT_POS_W2_DISCOUNT_REASON_BIND | Fix v-model `discountReason` + binding S09 | `resources/js/components/admin/pos/PosComponent.vue`, `tests/js/sentinels/PosDiscountReasonBindingSentinelTest.spec.js` | FK-079 sentinel | aucune | EASY | Remise impossible (bloquant UI) |
| **P-04** SUGGESTED_LOT_POS_W2_PAYMENT_REFACTOR | Refactor `PaymentComponent` : éliminer mutations directes de props (gate `GATE_PAYMENT_PROP_MUTATION_2026-04-26`) | `resources/js/components/admin/pos/PaymentComponent.vue`, `resources/js/components/admin/pos/PosComponent.vue` (emit handler), `tests/js/sentinels/PaymentComponentPropMutationSentinelTest.spec.js`, `tests/js/payment-401-retry.spec.js` | FK-081, FK-089 | aucune | HARD | Vue warns, état incohérent paiement |
| **P-05** SUGGESTED_LOT_POS_W2_FLOORPLAN_RELEASE | Release table après `posOrderStore` succès + lock pessimiste assign | `app/Services/OrderService.php` (hook release), `app/Http/Controllers/Admin/Pos/FloorplanController.php`, `tests/Feature/Pos/DiningTableReleaseAfterPosOrderTest.php` | `DiningTableReleaseAfterPosOrderTest` (FK-071) | P-01 | MEDIUM | Tables fantômes occupées |
| **P-06** SUGGESTED_LOT_POS_W2_PARK_TTL | `expires_at` sur `pos_parked_orders` + job purge + branch scope reprise | `database/migrations/2026_..._add_expires_at_to_pos_parked_orders.php`, `app/Models/ParkedOrder.php`, `app/Jobs/PurgeStaleParkedOrders.php`, `app/Http/Controllers/Admin/Pos/ParkedOrderController.php` | `ParkedOrderExpirationTest` (FK-088), `ParkedOrderBranchScopeTest` | aucune | MEDIUM | Bloat table + reprise cross-branch |
| **P-07** SUGGESTED_LOT_POS_W2_KIOSK_CASH_DECOUPLE | Découpler encaissement cash kiosk de la transition cuisine (FK-023, FK-042) | `app/Services/OrderService.php` (collectKioskCash), `app/Services/PaymentService.php`, `routes/api.php` | `PosCashEndpointSentinelTest`, `PosCollectKioskCashRouteTest` | P-01 | HARD | Confusion cuisine vs caisse, double release |
| **P-08** SUGGESTED_LOT_POS_W2_KDS_RELEASE_RULE | Règle release explicite KDS (FK-037), `expected_status`, dedupe outbox (FK-068, FK-069) | `app/Domain/Kds/KitchenReleaseRule.php` (À CRÉER), `app/Listeners/DispatchKdsTicket.php`, `app/Services/KitchenDisplaySystemOrderService.php` | `KdsTransitionWhitelistSentinelTest`, `KdsExpectedStatusConflictSentinelTest`, `KdsOrderItemsListParityTest`, `KitchenReleaseRuleTest` | P-01 | HARD | Tickets perdus / dupliqués cuisine |
| **P-09** SUGGESTED_LOT_POS_W2_AFTER_COMMIT_DISPATCH | Tous events POS après commit (FK-070), idempotence outbox | `app/Services/OrderService.php` (posOrderStore + changeStatus), `app/Jobs/DispatchDomainEventsJob.php` | `AfterCommitDispatchTest` | P-01, P-08 | MEDIUM | Events fantômes, KDS qui voit avant DB |
| **P-10** SUGGESTED_LOT_POS_W2_REFUND_LEDGER | Refund partiel ledger + wallet idempotency + raison serveur | `app/Services/PaymentService.php`, `app/Services/OrderService.php` (changePaymentStatus + RETURNED guard), `database/migrations/...payment_ledger.php` | `PartialRefundLedgerTest` (FK-074), `CreditWalletIdempotencyTest` (FK-034), `PaymentProviderReferenceUniqueTest` (FK-028) | P-01 | HARD | Double remboursement, fraude wallet |
| **P-11** SUGGESTED_LOT_POS_W2_PRINT_AUDIT | Audit alert sur échec impression + duplicata explicite (FK-075) | `app/Http/Controllers/Admin/Pos/PosReceiptPrintController.php`, `app/Services/Receipt/ReceiptAuditService.php` | `ReceiptAuditFailureAlertTest`, `ReceiptDuplicataIncrementTest` | aucune | MEDIUM | Trous fiscaux silencieux |
| **P-12** SUGGESTED_LOT_POS_W2_RT_RESYNC | Resync caisse au focus tab + dedupe per-tab fiable (FK-076) | `resources/js/services/realtime/PosFeed.js` (À CONFIRMER), `resources/js/store/modules/posCart.js`, `tests/js/realtime-dedupe.spec.js` | FK-076, e2e multi-tabs | aucune | MEDIUM | Caisse aveugle si Echo a coupé |
| **P-13** SUGGESTED_LOT_POS_W2_ZREPORT_HARDEN | Z idempotent par (branch_id, business_date) + alert sur gap ticket | `app/Services/Fiscal/ZReportService.php`, `database/migrations/...add_unique_z_per_branch_day.php` | `FiscalSealingHmacTest` (FK-010), `ZAggregationKioskRoutingTest` (FK-062), `ZIdempotencyPerDayTest` (à créer) | P-09 | HARD | Double Z, scellement KO, NF525 invalide |
| **P-14** SUGGESTED_LOT_POS_W2_BRANCH_BADGE | Badge branche permanent + warning Admin sans choix explicite (FK-008 surface élargie) | `resources/js/components/admin/pos/PosComponent.vue`, `tests/e2e/pos-branch-badge.spec.js` | `OrderListBranchExactnessSentinelTest` étendu | aucune | EASY | Encaissement branche erronée |
| **P-15** SUGGESTED_LOT_POS_W2_E2E_MATRIX | Matrice E2E complète page-par-page jusqu'au KDS (FK-049) | `tests/e2e/pos-full-journey/*.spec.js` | suite e2e CI verte | tous | HARD | Régressions invisibles |

> Minimum demandé : 12. Livré : 15 lots. Codex doit traiter P-01 → P-15 dans cet ordre, avec gates humains intercalés (`GATE_PAYMENT_LEDGER_V1` avant P-10 ; `GATE_KDS_BUMP_V1` avant P-08 ; `GATE_FISCAL_KIOSK_V1` avant P-13).

## 8. Exigences d'audit en cascade pour Codex

Pour **chaque lot** P-XX, Codex DOIT produire :

1. **Mini-rapport JSON** dans `reports/execution/EXEC_<TASK_ID>_<DATE>.json` contenant : `task_id`, `files_changed[]`, `lines_added/removed`, `tests_run[]`, `tests_pass`, `tests_fail`, `gate_dependencies[]`, `risk_self_score (0-10)`, `unresolved_questions[]`.
2. **`mandatory_tests` exécutés** (artisan + vitest + Playwright si listés au lot) — sortie brute archivée dans `reports/execution/EXEC_<TASK_ID>_<DATE>.raw.log`.
3. **Self-audit GPT** : pré-rework + post-rework checklist (ancrages SSOT respectés ? allowlist respectée ? off-limits non touchée ? sentinel test couvert l'invariant ?). Format : `reports/audit/GPT_SELF_AUDIT_<TASK_ID>.md`.
4. **Piste audit Claude ciblée** : Claude relit uniquement le diff + le rapport JSON + la sortie tests, produit `reports/audit/CLAUDE_REVIEW_<TASK_ID>_<DATE>.md` avec verdict `continue|heal|block|escalate|human`. **Claude ne remplace jamais** un gate humain (`GATE_*` listés en `human_gate_decisions`).
5. **Gate humain** : si lot dépend d'un gate `Pending`, Codex DOIT s'arrêter sur `human` et ne pas patcher la zone dépendante.
6. **Healing rule** : ≤ 3 cycles consécutifs sur le même lot — au 4e, escalade obligatoire.

## 9. Prochaines 5 actions immédiates

1. **Vérifier les "À CONFIRMER"** : ouvrir `resources/js/components/admin/pos-order/`, `resources/js/components/admin/reports/`, `app/Services/PaymentService.php` (split tender ?), `app/Services/CouponService.php` (cumul coupon+remise ?). Commande : `ls resources/js/components/admin/pos-order/ resources/js/components/admin/reports/ ; grep -n "splitTender\|tenders" app/Services/PaymentService.php`.
2. **Créer les TASK_ID Codex P-01 à P-15** : pour chaque lot, instancier `missions/<TASK_ID>/input.json` calqué sur `missions/CV1-M05-ORDER-QUOTE/input.json`, en respectant l'allowlist proposée et en référant les FK-IDs de §6.
3. **Lancer P-03** (EASY, sans dépendance, FK-079 bloquant UI) en premier pour quick win mesurable. Commande : `php artisan test --filter=PosDiscountReasonBinding` après patch Codex.
4. **Verrouiller les gates pending** avant P-08, P-10, P-13 : `docs/gates/GATE_KDS_BUMP_V1.md`, `docs/gates/GATE_PAYMENT_LEDGER_V1.md`, `docs/gates/GATE_FISCAL_KIOSK_V1.md`. Statut humain requis avant Codex.
5. **Établir la baseline E2E** (P-15 dépend de tout) en mode `--reporter=line --workers=1` puis archiver dans `reports/baseline/POS_V4_E2E_BASELINE_<DATE>.md` — sera la référence de non-régression pour la Vague 2.
