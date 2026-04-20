# Rapport global — cycles P implémentés (branche `feat/ton-sujet`)

_Date de synthèse : 2026-04-19._  
_Mode : synthèse à partir des livraisons documentées en session et des commits sur la branche de travail._

## P1 — Stock / disponibilité (POS ↔ Kiosk ↔ KDS)

- **Backend :** `AvailabilityService::assertItemsOrderableForBranch()` ; intégration aux chemins commande (`PricingService`, chemins legacy) ; preview sans verrouillage destructif.
- **Front kiosk :** `pruneUnavailableLines` sur événement de rupture.
- **Tests :** ex. `Menu/OrderRejectsUnavailableBranchItemTest.php`.
- **Doc projet :** `plans/PLAN_P1_STOCK_SYNC_HANDOFF.md` (si présent dans le dépôt).

## P2 — Multi-tender incrémental

- **Enum :** `PosPaymentMethod::TICKET_RESTAURANT` aligné gateway.
- **API :** `PosOrderRequest` — note obligatoire pour TR.
- **i18n :** fichiers `lang/*/pos_payment_method.php`.
- **Tests :** `PosTicketRestaurantPaymentTest.php`.

## P3 — Refund / retour lifecycle

- **`OrderService::changeStatus` (staff) :** `RETURNED` avec motif obligatoire ; cashback / points selon patterns annulation ; audit `order.returned`.
- **Tests :** extension `PosOrderBL2AuditCallSitesTest.php` (dont 422 sans motif).
- **`Order::restore()` :** volontairement **bloqué** (voir `Order.php` boot `restoring`).

## P4 — KDS cohérence temps réel

- **`KitchenDisplaySystemOrderService::changeStatus` :** ligne verrouillée (`lockForUpdate`), comparaison statut « attendu » vs ligne, **409** si dérive ; `OrderStateMachine::allows` sur ligne verrouillée ; `recordTransition` dans la transaction ; événements après commit.
- **Controller :** propagation `HttpException` (403/409).
- **Vuex :** rafraîchissement liste sur **409**.
- **Tests :** `KdsChangeStatusConcurrencyTest.php` (service avec modèle mémoire obsolète — le binding HTTP recharge un modèle frais).

## P5 — Validation montants (kiosk / `OrderRequest`)

- **`total` :** `min:0` si envoyé.
- **Tests :** `OrderRequestNegativeTotalTest.php`.

## P6 — Validation commande table QR (`TableOrderRequest`)

- **`subtotal` / `total` :** `min:0`.
- **Tests :** `TableOrderNegativeTotalTest.php`.

## P7 — Extension champs monétaires (kiosk, table, POS)

- **`OrderRequest` :** `subtotal`, `discount`, `delivery_charge` avec `min:0` (logique livraison inchangée).
- **`TableOrderRequest` :** `discount`, `delivery_charge` typés + `min:0`.
- **`PosOrderRequest` :** `subtotal`, `delivery_charge`, `pos_received_amount` bornés.
- **Tests :** extensions des fichiers ci-dessus + `PosOrderRequestNullableTotalTest.php`.

## P8 — Coupon public (`CouponCheckRequest`)

- **`total` :** `min:0` sur `POST /api/frontend/coupon/coupon-checking`.
- **Tests :** `CouponCheckNegativeTotalTest.php`.

## P9 — Coupons admin (`CouponRequest`)

- **`discount`, `minimum_order`, `maximum_discount`, `limit_per_user` :** `min:0` où applicable.
- **Tests :** `CouponRequestNegativeAmountsTest.php`.

## P10 — Paramètres commande / livraison (`OrderSetupRequest`)

- Tous les champs numériques d’order-setup : `min:0`.
- **Tests :** `OrderSetupRequestNegativeValuesTest.php`.

---

## Vue d’ensemble

| Cycle | Domaine principal                     | Type de changement      |
|-------|----------------------------------------|-------------------------|
| P1–P3 | Métier commande / paiement / retour    | Backend + tests         |
| P4    | KDS concurrence                      | Backend + front + tests |
| P5–P10| Hygiène validation montants / config   | Requests + tests        |

**Limite explicite :** les cycles P5–P10 ne modifient pas la logique fiscale NF525 (Z/X, chaîne audit) ; ils réduisent les entrées client/serveur manifestement invalides avant logique métier.
