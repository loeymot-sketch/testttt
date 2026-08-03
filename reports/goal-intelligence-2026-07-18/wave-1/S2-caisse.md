# S2 CAISSE — FINDER adversaire (GOAL intelligence totale 2026-07-18)

Wave 1 · READ-ONLY strict · verify-before-report (file:line lu + repro + evidence DB dev)
Périmètre : encaissement / annulation-refund / park-resume / files caisse / remises / duplicata / clôture / structure v5.

---

## Findings

### S2-01 [P2] `OnlineOrderController::changePaymentStatus` — arête `→REFUNDED` NON gardée (rupture de parité twin-route)
**file:** `app/Http/Controllers/Admin/OnlineOrderController.php:162-169`
**Repro / evidence :**
- La route sœur POS `PosOrderController::changePaymentStatus:372-378` gate explicitement `payment_status===REFUNDED` sur `can('pos-refund')` (heal NUIT-A 2026-07-03, commentaire : « un POS Operator POSSÈDE le groupe → il pouvait marquer REMBOURSÉE sans le droit »). La méthode online **n'a AUCUN gate** : elle délègue directement à `OrderService::changePaymentStatus`.
- La route `online-order/change-payment-status/{order}` (`routes/api.php:1077`) est gardée par `permission:online-orders` (constructeur `OnlineOrderController.php:34`).
- Matrice de rôles (tinker DB dev) : **`POS Operator` possède `online-orders` + `pos` mais PAS `pos-refund`** (Admin & Branch Manager ont les 3).
- `PaymentStateMachine` (`app/Domain/Order/PaymentStateMachine.php:13-16`) autorise `PENDING_COUNTER → REFUNDED`. Or il existe **2 commandes web `payment_status=15 (PENDING_COUNTER)`** en base (ids 5721/5722), et le heal `SYNC-WEB-KDS-01` (`OnlineOrderController.php:146-151`) bascule toute commande online ACCEPT+UNPAID → PENDING_COUNTER.
**Impact :** un POS Operator (sans droit de remboursement) peut `POST online-order/change-payment-status/{order}` avec `payment_status=REFUNDED` sur une commande web/online **PENDING_COUNTER** → la vente est passée REFUNDED (statut off-book + retirée de la file de traitement) hors autorité de remboursement. `OrderService::changePaymentStatus` sur REFUNDED n'appelle PAS `cashBack` (aucun mouvement d'argent ici puisque jamais collectée), donc impact monétaire nul mais **contournement d'autorisation réel** que le dev a explicitement voulu fermer côté POS et côté `changeStatus` (le twin `OnlineOrderController::changeStatus:126-136` gate bien CANCELED/REJECTED/RETURNED payés — seule `changePaymentStatus→REFUNDED` reste ouverte).
**Reco :** miroir exact du gate `PosOrderController::changePaymentStatus:372-378` en tête de `OnlineOrderController::changePaymentStatus`, hors try (fail-fast, 403 non masqué en 422).

---

### S2-02 [P2] `PosOrderRequest` — `delivery_charge` CLIENT non recalculé serveur pour une commande DELIVERY sans `delivery_distance_km`
**file:** `app/Http/Requests/PosOrderRequest.php:35-51` (+ `rules()` `delivery_charge`), consommé `app/Services/OrderService.php:788-821` puis `PricingRequest::forPos(...,(float)$this->order->delivery_charge)` `OrderService.php:858`
**Repro / evidence :**
- `prepareForValidation()` ne recalcule `delivery_charge` depuis la distance **QUE** si `order_type===DELIVERY && filled('delivery_distance_km')`. Sinon (DELIVERY **sans** distance) → aucun merge, la valeur client passe. Pour non-DELIVERY il force `0`, mais le cas DELIVERY-sans-distance **fait confiance au client**.
- `posOrderStore` unset seulement `total/subtotal/discount` (`OrderService.php:721`) — **PAS `delivery_charge`** → la valeur client persiste sur l'`Order` (`Order::create($validated...)`), puis alimente le moteur SSOT.
- `normalizePosRuntimePayload` (`PosController.php:230-240`) applique la MÊME condition (recalcul seulement si distance présente) → aucun garde-fou de repli.
- Le seul recalcul serveur est le heal « livraison offerte ≥ seuil » (`OrderService.php:863-887`) qui met le fee à **0** au-dessus du seuil ; **en dessous du seuil, un `delivery_charge` forgé est facturé tel quel**.
**Impact :** un payload POS DELIVERY forgé (ou une désync UI qui omet `delivery_distance_km`) avec `delivery_charge` arbitraire → total facturé au client sur/sous-évalué (le client paie un fee non recalculé, encaissé en COD). Contredit l'invariant « prix 100 % backend » (CLAUDE.md §8) que le reste du fichier applique rigoureusement (item/variation/extra tous re-lus DB). Chemin étroit (livraison au comptoir) mais réel.
**Reco :** en DELIVERY, toujours dériver `delivery_charge` du couple (distance, branch) côté serveur (repli `DeliveryFeeService` même sans distance = 0 ou config), ne jamais persister la valeur client.

---

### S2-03 [P3] Encaissement ESPÈCES d'une commande online (CASH-01) sans précondition de session tiroir
**file:** `app/Services/OrderService.php:2699-2818` (garde `$didCounterCash` + hook `recordCashOrderMovement` post-commit)
**Repro / evidence :**
- La vente POS directe cash est **bloquée** en amont si aucune `CashDrawerSession` OPEN (`PosController::assertCashDrawerSessionOpenIfCashInvolved:90-143` + `recordCashOrderMovement(strict:true)` `OrderService.php:1357-1361`).
- Le chemin online-cash (`changePaymentStatus` UNPAID→PAID + `collect_counter_cash`, câblé `OnlineOrderShowComponent.vue:501`) alloue `fiscal_sequence_no` DANS la tx (heal Wave 2, `OrderService.php:2670-2682`) donc **scelle la vente dans le Z**, puis appelle `recordCashOrderMovement($order,...)` **best-effort (strict=false)** post-commit (`OrderService.php:2801`). Sans session ouverte → `flagCashMovementSkipped` (`PaymentService.php:646-649`), la ligne tiroir est sautée, `pos_payment_method` reste **null** (posé seulement si la ligne IN a réellement été écrite, `OrderService.php:2807-2810`).
- Bucketing Z : `applyOrderToTotals` (`ZReportService.php:792`) ventile par `pos_payment_method ?: payment_method` → sans mouvement, la vente cash tombe dans le bucket `payment_method=CASH_ON_DELIVERY(1)` au lieu de `pos_payment_method=CASH(1)` : montant présent dans le Z mais **espèces physiques hors trail tiroir** → sous-comptage à la réconciliation, alors que le caissier reçoit un toast de succès.
**Impact :** cohérent avec le compromis best-effort déjà assumé du counter-collect kiosk (`recordCashOrderMovement` §522-539), mais **étendu à un NOUVEAU chemin cash** sans la garde stricte que la vente POS directe applique. Variance de caisse silencieuse (le flag `cash_movement_skipped` est loggé, non bloquant). `web PAID+cash` en base dev = 0 aujourd'hui (chemin récent), donc non encore matérialisé.
**Reco :** aligner sur la vente POS directe — soit exiger une session OPEN avant de sceller un encaissement espèces online (fail-closed), soit surfacer un avertissement bloquant côté modal quand `cash_movement_skipped=true`.

---

## Chemins VÉRIFIÉS SAINS (adversaire réfuté)

- **Split / partiels (SplitPaymentService)** : recalcul serveur intégral — `validateBreakdown` re-somme en cents contre `$order->total` SSOT, tolérance overpay **cash-only** (`min(1€, Σcash)`, garde non-cash ≤ total, `SplitPaymentService.php:154-198`), `change` recalculé serveur (`:262-270`), jamais pris du client. DB : **0 écart `SUM(order_payments) vs orders.total`** (>1,01€) sur toute la base dev.
- **Double-encaissement counter-collect** : `confirmCounterPayment` verrouille (`lockForUpdate`), discrimine same-cashier replay (200 no-op) vs autre caissier (409 typé `PaymentAlreadyCollectedException`, non caché car middleware ne cache que 2xx) via l'audit `order.counter_payment_confirmed` (`PaymentService.php:306-339`). File web (`source_surface='web'`) exclue de la file counter-collect (`routes/api.php:825-846`) → pas de collecte par deux routes.
- **Vente off-book** : `changePaymentStatus` refuse `PENDING_COUNTER→PAID` direct sans passer par l'encaissement (`OrderService.php:2646-2650`) ET alloue `fiscal_sequence_no` sur toute arête `→PAID` non terminale non-uber (`:2670-2682`). Les 2 kiosk PAID-sans-seq résiduels (ids 5493/5501, 06/07) sont **antérieurs** à ce heal (updated_at 2026-07-06) — dette historique, pas régression ; `warnOnOrphanedPaidOrders` (`ZReportService.php:730`) les signale à la clôture, `aggregate` les exclut (`whereNotNull('fiscal_sequence_no')` `:425`) → chaîne gap-free préservée.
- **Refund cash-trail** : sortie tiroir = portion CASH réelle uniquement (`refundCashTranchePortion` sur `order_payments.mode=CASH`, `PaymentService.php:694-700`), symétrique split ; slug dérivé de l'origine (`cash`→tiroir, sinon `credit`→avoir) `OrderService.php:2333` ; clawback fidélité idempotent sur tout état terminal (`:2375-2396`).
- **Park / resume** : `PosParkedOrderService::recall` verrouille + supprime atomiquement (lockForUpdate + delete, pas de double-recall), scope strict `(user_id, branch_id)`, purge des variations devenues indisponibles avec warnings (`pruneUnavailableParkedVariations`). Recall renvoie un payload re-price par `posOrderStore` (prix jamais restaurés depuis le park). Branch-isolation `resolveOperatorContext` refuse branch_id≤0.
- **Remises** : globalement OFF en V1 (`assertDiscretionaryDiscountAllowed` + `assertPosManualDiscountAllowed` refusent tout discount tant que `pos.manual_discount_enabled=false`, `OrderService.php:3248-3320`) ; plancher `max(0,...)` sur le total ; discount>subtotal ignoré. Paliers 10/50 %/illimité + motif ≥3 déjà prêts si réactivé.
- **Duplicata NF525** : `PosReceiptPrintController::increment` incrémente atomiquement `receipt_print_count` scoped branche, `is_duplicata = count>=2`, audit HMAC `pos.receipt.print` vs `pos.receipt.reprint` (`:181-208`). Endpoints `/escpos` de service (pré-encaissement, réimpression modal) sont **lecture seule** et **n'incrémentent PAS** le compteur fiscal (correct : ce n'est pas le reçu officiel).
- **Clôture X/Z pendant commandes ouvertes** : `ZReportService::close` sous `Cache::lock` + `lockForUpdate` sur le Z OPEN, partition continue `(closed_{n-1}, closed_n]` sur `COALESCE(fiscal_dated_at, created_at)`, statuts terminaux exclus, mirrors counter-entry nettés. Une commande ouverte non fiscalisée (fiscal_seq null) n'entre jamais dans l'agrégat → close sûr avec commandes en cours.
- **Cash drawer** : `recordMovement`/`close`/`reconcile` tous lockForUpdate + idempotents ; ownership renforcé (`assertSessionVisibleToUser:317-338` — un caissier ne ferme pas le tiroir d'un autre sans permission manager) ; gate variance > seuil.
- **Cross-branch** : `PosOrderController::show`/`OrderHistoryController::show` unifient ModelNotFound+cross-branch en 403 (anti-énumération) ; `confirmCounterPayment`/`cancelCounterPayment` `assertCounterOrderVisible` 403 hors-branche.

## Connu / déjà escaladé (non recompté)
- `PaymentService::cashBack:146-155` : crédit d'avoir wallet **INCONDITIONNEL** même sur remboursement ESPÈCES → double-remboursement potentiel d'un client enregistré. **Déjà flaggé + escaladé gate owner** dans le code (changement de contrat CashBackAtomicityTest) — pas un nouveau finding.

---
**Bilan S2 :** 3 findings réels (2×P2 autorisation/pricing, 1×P3 cash-trail), 0 P0/P1. Money-path split/counter-collect/clôture/duplicata prouvés SAINS. Aucun double-encaissement possible. Chemins périmés (dev/janitor), reorder 422, wizard frozen, ticket 900px : hors périmètre / non recyclés.
