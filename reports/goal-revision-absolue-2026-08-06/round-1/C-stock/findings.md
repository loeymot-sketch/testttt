# AUDIT C — STOCK de bout en bout (round 1)

- **Date** : 2026-08-06 · **HEAD** : `a13e1e65672c9214a515fa6fd3a7e48a5abc4e4e` (branche `pos/category-first-caisse-2026-06-23`)
- **Méthode** : lecture code + 76 tests DB-safe exécutés (sqlite `:memory:`, phpunit.xml:52-53) — **76/76 verts** (StockDecrement*, StockReleaseOnCancel/Refund, PosStockOutflow, MeatPortionCalculator, RawMaterialConsumption, ManualEightySixSticky, StockCrossSurfaceSync).

## Q1 — Décrément à la création / re-crédit à l'annulation-expiration : **OUI partout, sémantique Uber plus faible**

**Décrément création** — cœur unique `StockService::decrementForOrder` (`app/Services/Stock/StockService.php:44`), item + variations + extras + addons du `composition_snapshot` (:382-434), clé d'idempotence par ligne (:466-483).
- **Caisse + téléphone** : appel direct DANS la transaction de création (`app/Services/OrderService.php:1157`) → stock insuffisant = `StockUnavailableException` **409** (`app/Exceptions/Stock/StockUnavailableException.php:11`) → la vente ÉCHOUE (dur). Test `StockDecrementOrderServiceTest` (rejet prouvé).
- **Borne + web + livraison** : idem, `app/Services/FrontendOrderService.php:655` (même transaction que la commande — rollback atomique, cf. :692).
- **Uber** : listener seul (`UberWebhookController.php:279` → `DecrementStockOnOrderCreated.php:35`), échec ISOLÉ (log + `StockDecrementFailedEvent`, :36-60) — la commande Uber existe toujours (on ne peut pas la refuser). Voir P3-1.
- Les 6 sites `OrderCreated::dispatch` (OrderService:664/1409/1764, FrontendOrderService:742/1651, Uber:279) rejouent le listener → **no-op idempotent** (clé `order_created` sans seed, StockService:476).

**Re-crédit** — `releaseForOrder` (:52), plafonné par : existence du mouvement `order_created` d'origine (:547), ledger `released_qty` (maintenu par `AvailabilityService::releaseForOrderItems`, `app/Services/Menu/AvailabilityService.php:971-973`), et clé de mouvement `released:N:delta:M` (:551-558) → jamais de sur-crédit même si le listener sibling échoue. `withTrashed()` couvre le destroy post-commit (:498, :509). Chemins TOUS câblés sur `OrderCanceled` → `ReleaseStockOnOrderCanceled` (EventServiceProvider:203) :
1. Annulation admin/POS CANCELED/**REJECTED**/**RETURNED** (`OrderService.php:2596`), self-cancel client (:2325), **destroy** (dispatch after-commit DANS la tx, :3176).
2. Annulation borne/web (`FrontendOrderService.php:941`), **annulation système paiement Mollie échoué** (:1021, `cancelForFailedOnlinePayment` couvre `web`+`delivery` :946).
3. Uber cancel (`UberWebhookController.php:369`).
4. **Expiration** `CleanupStalePendingKioskOrders` : 5 lanes → `OrderCanceled::dispatch` (:397, :492) — kiosk 180 min (:67-87), **web-carte abandonnée 60 min** (:103-127), fantôme PREPARED soft-delete kiosk/web/phone/delivery (:145-168), téléphone 360 min (:190-221), web/delivery 360 min (:240-269). La lane **delivery** (P1 F-2 PROCUREUR 2026-08-05) est bien présente aux :115 et :252. Aucune lane 'pos' nécessaire (walk-in payé immédiatement ; COUNTER_DEFERRED = phone, couvert).
5. **Filet de sécurité** : `foodking:reconcile-releases` schedulé (`app/Console/Kernel.php:47`) rattrape les libérations perdues (crash post-commit) sur 48 h.

**Verdict Q1 : SAIN.** Aucun chemin d'annulation/expiration sans re-crédit trouvé.

## Q2 — Refund partiel : **OUI, re-crédite au prorata**

`RefundCreated` porte `refundedItems` (`PaymentService.php:228`, `RefundWithCounterEntryService.php:465`, Mollie:722, Stripe:504) → `ReleaseStockOnRefundCreated.php:15` → `releaseForOrder('refund', refundedItems)` → par ligne `min(qty demandée, quantity − released_qty)` (StockService:518-527). Test « decrement and partial refund track addon target stock » vert. **Doit-il ?** Pour le stock UNITAIRE (boissons revendables) oui — comportement actuel correct. Les **matières premières ne sont PAS reversées sur refund** (aucun listener raw-material sur `RefundCreated`, EventServiceProvider:219-233 ; reprise uniquement sur `OrderCanceled` via `ReverseRawMaterialsOnOrderCanceled`:209) — cohérent métier (nourriture cuite = consommée), mais nulle part documenté comme décision : voir P3-4.

## Q3 — 86 extra/variation : **toutes les surfaces internes bloquées ; Uber non**

- **Garde de commit (SSOT toutes surfaces POS/borne/web)** : `PricingService::calculateOrder` → `assertItemsOrderableForBranch` (:50, :102 pour les addons) + `ChoiceAvailabilityResolver::assertSelectionsOrderable` (:546 → `ChoiceAvailabilityResolver.php:124-202`, 422 nommé). Le fallback legacy web est aussi gardé (`FrontendOrderService.php:410-426`).
- **Propagation UI** : item → `ItemAvailabilityChanged` (bump MenuSnapshot + invalidation cache kiosk + outbox, EventServiceProvider:238-246) ; extra/variation → events dédiés + `PersistCatalogChangedToOutbox` + invalidation cache kiosk (:251-272). **Pas de bump MenuSnapshot sur extra/variation = DESIGN documenté** (:247-250 : le refresh passe par le broadcast dédié) — le doute B8 est levé. Test `StockCrossSurfaceSyncTest` (10 assertions caisse/borne/KDS/mobile) vert.
- **Retour de stock** : réactivation AUTO uniquement, 86 manuel sticky (`StockService:318-327`, `isManuallyUnavailable`:356) — `ManualEightySixStickyThroughRestockTest` vert. Réception d'achat → `syncAvailabilityAfterExternalMutation` (StockService:180 ; `PurchaseService.php:248`) lève la rupture auto + notifie.
- **[P2-1] Uber Eats hors du périmètre 86** : `UberClient` n'a AUCUN endpoint menu/availability (`accessToken/fetchOrder/acceptOrder/denyOrder/storeStatus` seulement, UberClient.php:21-118) et la création webhook ne passe par aucune garde dispo (forceFill direct, UberWebhookController:234-245). Un article/extra 86 reste vendable sur Uber jusqu'à action manuelle dans le dashboard Uber. Le décrément compense a posteriori (ou droppe, cf. P3-1).

## Q4 — stock_outflows : **append-only triple-défense, décrément cohérent, visibilité minimale**

- Append-only : modèle (`StockOutflow.php:50-57` throw sur update/delete) + **triggers DB** no_delete/no_update (`2026_07_31_150000_add_stock_outflows_immutability_triggers.php:29-44`) + `stock_movements` eux-mêmes trigger-protégés (`2026_05_18_140000`:108-143).
- Décrément : `recordManualOutflow` (`StockService.php:196-249`) — lock, plancher 0 (jamais négatif :220-221), pas de mouvement delta=0 ni de clé brûlée (:228-230), motif enum `manual_out`, auto-86 si 0, **atomique avec la trace** (`PosStockOutflowController.php:58`), idempotence via `X-Idempotency-Key` + middleware (routes/api.php:974).
- **[P3-2] Visibilité** : uniquement la modale caisse « 50 dernières » (`PosStockOutflowModal.vue`, routes:969-974). Aucun rapport agrégé (food-cost, DailyBook, Z, dashboard admin) ne lit `stock_outflows` — la trace existe mais personne ne la totalise.

## Q5 — Inventaire physique : **ABSENT pour stock_levels ; adjust() matières JAMAIS exposé — [P2-2]**

Aucun flux « comptage physique vs théorique » pour les unités (`stock_levels`). `RawMaterialStockService::adjust()` (:91 — « AJUSTEMENT INVENTAIRE vers une CIBLE absolue ») existe mais **zéro appelant** (aucune route/controller/commande ne l'invoque — grep app/ + routes/). Le mobile affiche `on_hand` READ-ONLY (`MobileStockController.php:79-91`). **Manque structurel P2** — mais tout est prêt pour le construire : ledger append-only `stock_movements` (motif enum à étendre `inventory_adjust`), `on_hand`, `adjust()` service côté matières, `UnifiedStockViewService::overview` (« à acheter »), pattern idempotence outflow réutilisable tel quel.

## Q6 — MeatPortionCalculator : **branché 3 endroits, SSOT portions, risques nommés**

Branchements : (a) **stock matière** `RawMaterialConsumptionService` (:139 injection, :358 étape 0, :430-441 étape 4) — désormais SEUL propriétaire des quantités viandes/frites via exclusion `VIANDES_PILOTEES` (:129-135, reprise conditionnelle :571-600, jamais globale) ; (b) **ticket cuisine** (`OrderReceiptEscPosRenderer`) ; (c) **KDS** via jumeau JS `kdsSymbolic.js:602+` (« JUMEAU STRICT » déclaré, `RECETTES_FIXES` dupliquées :621). Il ne touche PAS `stock_levels` (unités) → pas de divergence avec le stock réel unitaire ; il pilote le ledger THÉORIQUE matière.
- **[P3-3] Recettes fixes par REGEX sur le NOM d'item** (:69-79) : renommer « Fish Burger » → « Filet de poisson » = consommation viande silencieusement nulle SANS même le « ? » (les motifs `RECETTE_INCONNUE`:88-92 ne matchent plus). Même fragilité côté jumeau JS (double maintenance manuelle — le motif d'échec n° 1 du projet, reconnu dans le code lui-même :16-17).
- **[P3-5]** `normalizeSnapshot` (:608-627) pose toujours la clé `lines` → le repli `variations` de `forLine` (:118) est mort sur le chemin consommation (payloads anciens ⇒ retombée recette historique — bénin, mais asymétrique avec le ticket qui lit le snapshot brut).
- Gardes saines : inconnu → « ? » affiché + skip loggé, matière en grammes sans `piece_weight_g` loggée (jamais consommée à 0 silencieusement, `MeatMaterialResolver` doc :29-31).

## COHÉRENCE globale (un événement → tous les ledgers)

Trois ledgers (stock_levels / quota dispo / matières) réagissent au MÊME couple d'événements avec idempotence propre chacun ; ordre des listeners commenté et testé ; garde de course async consume-après-cancel (`RawMaterialConsumptionService:164-185`, tests « out of order leaves no drift »). `reverseForOrder` en `withTrashed` (:249) couvre le destroy. RAS.

## Défauts confirmés (aucun P0/P1)

| ID | Sév | Constat | Trace |
|----|-----|---------|-------|
| P2-1 | P2 | 86 non propagé vers Uber Eats (pas d'API menu/availability) + commandes entrantes sans garde dispo | UberClient.php:21-118 ; UberWebhookController.php:234-245 |
| P2-2 | P2 | Aucun inventaire physique ; `RawMaterialStockService::adjust()` (:91) sans appelant ; rien pour stock_levels | grep app/+routes = 0 appel |
| P3-1 | P3 | Uber stock insuffisant : décrément TOUT-ou-rien (rollback tx) → commande vendue sans AUCUN décrément même partiel ; seulement loggé | DecrementStockOnOrderCreated.php:34-60 ; StockService:128-129 |
| P3-2 | P3 | stock_outflows invisibles hors modale POS (50 dernières) — aucun rapport agrégé | routes/api.php:969-974 |
| P3-3 | P3 | Recettes viande fixes par regex nom item (rename = trou silencieux) + jumeau JS dupliqué à la main | MeatPortionCalculator.php:69-92 ; kdsSymbolic.js:602-642 |
| P3-4 | P3 | Matières premières non reversées sur REFUND (cancel seulement) — cohérent métier mais décision non documentée | EventServiceProvider:219-233 |
| P3-5 | P3 | Repli snapshot `variations` mort sur le chemin consommation (normalizeSnapshot force `lines`) | RawMaterialConsumptionService.php:608-627 ; MeatPortionCalculator.php:118 |
