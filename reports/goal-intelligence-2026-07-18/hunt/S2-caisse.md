# S2 — Chasse LOGIQUE CAISSE (POS) — 2026-07-18 (READ-ONLY)

DB `foodking_e2e`. Aucune modif. Ancres lues : PosOrderController, OnlineOrderController,
OrderHistoryController, OrderSetupController, AdminPosV4Controller, PosController, PosLoyaltyController,
TableOrderController ; OrderService (posOrderStore/changeStatus/changePaymentStatus/deliveryBoyOrderChangeStatus/destroy),
PaymentService, SplitPaymentService, CashDrawerService, PosParkedOrderService, RefundWithCounterEntryService,
PosReceiptPrintController, PaymentStateMachine, PosOrderRequest/PaymentStatusRequest, routes/api.php (pos),
OnlineOrderShowComponent.vue, PosComponent.vue.

---

## [P1] OnlineOrderController.php:146 + routes/api.php:804 + OrderService.php:2646 — Commande WEB « Acceptée » (sans encaisser) tombe dans un état PENDING_COUNTER SANS AUCUN chemin d'encaissement → vente préparée jamais fiscalisée (off-book) ou incollectable

### Le mécanisme
`SYNC-WEB-KDS-01` (OnlineOrderController::changeStatus, lignes 146-151) bascule **inconditionnellement**
toute commande web `UNPAID` en `PENDING_COUNTER` dès l'« Accepter » :
```php
if ($request->status === OrderStatus::ACCEPT
    && $order->payment_status === PaymentStatus::UNPAID
    && $order->order_type !== OrderType::POS) {
    $order->payment_status = PaymentStatus::PENDING_COUNTER;  // web/livraison → dû comptoir
    $order->save();
}
```
But annoncé : rendre la commande visible au board cuisine (`KitchenReleaseRule` admet PENDING_COUNTER).
Effet de bord : la commande devient `source_surface='web'` + `PENDING_COUNTER` + `fiscal_sequence_no NULL`.

Or **aucun chemin d'encaissement ne couvre ce triplet** :

1. **File `/pos/counter-collect/pending`** (routes/api.php:825-846) filtre `source_surface IN (kiosk,pos,phone)`
   ou NULL type kiosk/takeaway. **`'web'` n'est jamais matché** → la commande n'apparaît PAS dans le panneau
   « à encaisser borne » de la caisse.
2. **`changePaymentStatus` PENDING_COUNTER→PAID** est **bloqué 422** par la garde ULTRA-AUDIT-V4
   (OrderService.php:2646-2650) tant que `fiscal_sequence_no === null` :
   « Une commande différée (borne Plan B) doit être marquée payée via l'encaissement… ».
3. **UI OnlineOrderShowComponent** : le bouton « Encaisser & Valider » (`confirmCashPayment`, l.61) est en
   `v-if order.status === PENDING`. Après l'Accept, `status=ACCEPT` → **le bouton disparaît**. Le dropdown
   de statut paiement restant n'offre que PAID/UNPAID → PAID retombe sur la garde 422.
4. **Livraison COD** : `deliveryBoyOrderChangeStatus` n'auto-encaisse au doorstep que si
   `payment_status === UNPAID` (OrderService.php:1915 `$wasUnpaidCash = (!$transaction) && payment_status===UNPAID`).
   Après le flip en PENDING_COUNTER, `$wasUnpaidCash=false` → **pas de flip PAID, pas d'alloc fiscale, pas de
   cash-escrow** à DELIVERED. La commande livrée + encaissée physiquement par le livreur reste PENDING_COUNTER
   à vie → **vente hors chaîne NF525** (exclue du Z signé car `ZReportService` filtre `whereNotNull(fiscal_sequence_no)`).

### Conséquence
Le flux voulu est « Encaisser & Valider » AVANT d'accepter. Mais le bouton vert primaire **« Accepter »**
est tout aussi visible ; s'il est cliqué en premier (geste naturel), la commande :
- est **envoyée en cuisine** (board-released, préparée),
- ne peut **plus jamais** être marquée PAID via aucune UI (takeaway : incollectable ; livraison COD : livrée puis jamais fiscalisée = **off-book**),
- ne peut qu'être **annulée** (changeStatus CANCELED passe : fiscal null → assertMutable OK).

Régression introduite le 2026-07-15 par SYNC-WEB-KDS-01 (le flip PENDING_COUNTER a cassé la précondition
`UNPAID` du doorstep-collect et n'a pas ajouté 'web' à la file counter-collect).

### Repro (DB, read-only)
```
orders source_surface='web' groupées (payment_status,status) :
 {"payment_status":10,"status":1,...}   ← UNPAID/PENDING (avant accept)
 {"payment_status":15,"status":19,"c":2,"no_fiscal":"2"}  ← 2 commandes PENDING_COUNTER, fiscal NULL, finies REJECTED (annulées, pas payées)
 {"payment_status":5,"status":4,...}    ← PAID/ACCEPT via le bon bouton (fiscal alloué)
```
Les 2 lignes `payment_status=15` (PENDING_COUNTER) `no_fiscal=2` matérialisent des commandes web qui, une
fois en PENDING_COUNTER, n'ont pu être **que rejetées** (jamais encaissées) — trace du piège.

### Correctif suggéré (hors scope, à valider)
Soit ajouter une clause `source_surface='web'` à la file `/pos/counter-collect/pending` + accepter 'web'
dans `assertCounterDeferredOrder`, soit NE PAS flipper 'web' en PENDING_COUNTER (utiliser un autre signal
de board-release pour le web). Toucher NF525-adjacent → gate owner.

---

## [P3] OrderService.php:3181 / salesReportOverview — `total_orders` compte les commandes annulées-mais-non-remboursées dans le volume, mais `total_earnings` PAID-only : léger écart volume/CA (cohérence de rapport, pas d'argent perdu)

`total_orders = orders.reject(parent_order_id != null).count()` (exclut les miroirs de refund) mais **inclut**
les commandes CANCELED/REJECTED. `total_earnings/discounts` filtrent `isRealizedRevenueRow` (PAID net).
Écart attendu et documenté (volume placé vs CA réalisé) — signalé pour complétude, **non un défaut argent**.
Écarté comme finding actionnable.

---

## Pistes VÉRIFIÉES puis ÉCARTÉES (pas de défaut)

- **Split multi-tender (somme/rendu/rejet)** — SplitPaymentService::validateBreakdown est solide :
  `SUM(tranches) >= total` (l.167), `SUM(non-cash) <= total` (garde-fou carte/TR sans rendu, l.179),
  tolérance overpay = `min(1€, cash)` (l.165, ne peut absorber que ce que le tiroir rend), rendu **recalculé
  serveur** `tendered-amount` clampé ≥0 (l.268, jamais pris du client). Tranche CARD exige terminal_id ACTIVE
  branch-scopé (l.121). Cash tranche exige `tendered >= amount`. RAS.
- **Double cash-in création vs encaissement** — commande différée : gardes `! $deferToCounter` sur
  SplitPaymentService (OrderService:1326) ET recordCashOrderMovement legacy (OrderService:1354). Split interdit
  sur commande différée (PosOrderRequest::withValidator:198). RAS.
- **Refund split / cash-portion** — cashBack + recordCashRefundMovement sortent UNIQUEMENT
  `refundCashTranchePortion` (PaymentService:694, SUM order_payments mode=CASH), repli total si mono-tender cash,
  0 si carte. Miroir counter-entry négate tranches + pose CASHBACK/OUT (RefundWithCounterEntryService 4-bis/ter/quater). RAS.
- **Double encaissement borne (2 caissiers)** — confirmCounterPayment : lockForUpdate + discrimination
  collecteur via audit `order.counter_payment_confirmed` → même caissier=no-op 200, autre=409 typé
  (PaymentService:306-339). Terminal-status collect bloqué (l.352). RAS.
- **Réimpression ticket** — PosReceiptPrintController::increment n'alloue PAS de fiscal_sequence_no ; incrémente
  un compteur + audit HMAC (`pos.receipt.reprint`) + marque DUPLICATA à partir de 2. Pas de double fiscal. RAS.
- **Park/resume double-décrément stock** — le stock n'est décrémenté qu'à `posOrderStore`
  (StockService::decrementForOrder). `park()` ne stocke qu'un payload JSON ; `recall()` supprime la ligne +
  renvoie un snapshot en élaguant les variations indisponibles. Aucun décrément au park → pas de double. RAS.
- **Remises (borne/cumul/authz/log)** — assertPosManualDiscountAllowed applique l'échelle RBAC
  (>50% owner, >10% manager, sinon pos-discount-up-to-10) + motif ≥3 + discount ≤ subtotal serveur ;
  audit NF525 `OrderDiscountLog` écrit. `manual_discount_enabled=true` (défaut, pas d'override .env) donc actif.
  Coupon saute l'échelle % mais est pré-défini admin (couponService valide). RAS.
- **Refund twin-route authz** — changeStatus (RETURNED + CANCELED/REJECTED si PAID), changePaymentStatus
  (REFUNDED), sur PosOrder/OnlineOrder/TableOrder : tous gatés `pos-refund` (POS Operator ne l'a pas). RAS.
- **Off-book PENDING_COUNTER→PAID direct** — bloqué (OrderService:2646). Scellage UNPAID→PAID alloue fiscal
  (2670, delivery doorstep 2010, confirmCounterPayment 364). RAS *sauf* le trou web ci-dessus (P1).
- **Idempotency POS create** — Cache::lock + recovery scoping (branch,customer,key) + catch 23000. RAS.
