# Audit sécurité CAISSE (POS) — 2026-07-15

Scope: `PosController`, `PosOrderController`, `Admin/Pos/*`, routes `pos.` / `pos-order.` / `pos-category.`.
Axes: IDOR, RBAC endpoints mutating (refund/cancel/drawer/loyalty), mass-assignment, tampering montant/prix, replay/idempotency.

## Verdict
1 finding confirmé (**P1** — contournement d'autorisation de remboursement via ANNULATION, sortie tiroir réelle sans droit `pos-refund`). Reste de la surface = solide (voir §Coverage).

---

## FINDING P1 — Annulation (CANCELED) d'une commande PAYÉE = sortie tiroir sans `pos-refund` (twin-route authz gap)

**Fichier:** `app/Http/Controllers/Admin/PosOrderController.php:328` (garde trop étroite dans `changeStatus`)
**Route:** `POST /api/v1/admin/pos-order/change-status/{order}` (routes/api.php:1052, groupe `permission:pos-orders`)

### Le défaut
`PosOrderController::changeStatus` ne verrouille sur `pos-refund` QUE la transition `RETURNED` (22) :

```php
if ((int) $request->status === \App\Enums\OrderStatus::RETURNED) {
    abort_unless(auth()->user()?->can('pos-refund') ?? false, 403, ...);
}
```

Or `OrderService::changeStatus` déclenche une **sortie d'argent du tiroir** aussi pour `CANCELED` (16) et `REJECTED` (19) :
- `OrderService.php:2314` → `cashBack(...)` si l'ordre a une `transaction` ;
- `OrderService.php:2320-2329` → `recordCashRefundMovement($locked, total)` pour une **vente POS cash directe** (`pos_payment_method=CASH` + `payment_status=PAID`, sans ligne Transaction — cas standard de la caisse).
  `recordCashRefundMovement` → `recordCashBackMovement` écrit un `CashMovement` `TYPE_CASHBACK` / `DIRECTION_OUT` = le tiroir attendu baisse du montant total (`PaymentService.php:637-673`).

Le rôle **POS Operator** possède `pos-orders` mais **PAS** `pos-refund` (RolePermissionTableSeeder.php:98-107 ; `pos-refund` n'est donné qu'au Branch Manager, l.89). Le gate `pos-refund` a été posé sur RETURNED (REFUND-BYPASS-GUARD 2026-06-26) et sur REFUNDED (NUIT-A 2026-07-03) explicitement pour bloquer le « mass-refund vector ». **CANCELED est le troisième jumeau qui bouge l'argent mais n'a jamais été gardé.**

### Atteignabilité (prouvée par le code)
- Une vente POS directe est créée `status=PREPARING` (défaut `pos.auto_prepare_on_paid=true`) ou `ACCEPT`, `payment_status=PAID`, `pos_payment_method=CASH` (OrderService.php:774-815).
- `OrderStateMachine::allows` autorise `ACCEPT→CANCELED` (l.60) et `PREPARING→CANCELED` (l.68) **sans aucune permission** (seules les arêtes `DELIVERED`/`RETURNED` sont permission-gated). La fenêtre existe tant que le chef n'a pas passé la commande `PREPARED`.
- `SealedOrderGuard::assertMutable` ne bloque QUE les commandes fiscalisées dans un Z **clos** → une vente fraîche pré-Z passe.

### Repro (click-path caissier)
1. Login **POS Operator** (branch_id=1), ouvrir une session tiroir.
2. `POST /api/v1/admin/pos` — `pos_payment_method=1` (CASH), `pos_received_amount >= total`, items valides. → commande créée `PREPARING` + `PAID`.
3. `POST /api/v1/admin/pos-order/change-status/{order}` body `{"status":16,"reason":"erreur"}`.
4. Réponse **200**. Un `CashMovement` `TYPE_CASHBACK` `DIRECTION_OUT` `amount=total` est écrit sur la session ouverte → le tiroir attendu chute du total. Aucune permission `pos-refund` requise, aucune validation manager. Le caissier peut retirer physiquement le cash : la réconciliation le voit comme une « annulation » légitime (pas une variance).

Effet net = équivalent fonctionnel d'un remboursement (drainage tiroir + revenu annulé) obtenu par un rôle qui n'a PAS le droit de rembourser. Sur les commandes cash directes, `cancel` = refund déguisé.

### Correctif scope-minimal (NON-frozen — controller)
Dans `PosOrderController::changeStatus`, étendre le gate `pos-refund` à CANCELED/REJECTED **quand la commande est PAYÉE** (une annulation d'une commande UNPAID/PENDING_COUNTER ne bouge pas d'argent → reste un geste caissier légitime, non gardé) :

```php
$isRefundLike = in_array((int) $request->status, [
    \App\Enums\OrderStatus::RETURNED,
    \App\Enums\OrderStatus::CANCELED,
    \App\Enums\OrderStatus::REJECTED,
], true);
// RETURNED garde son gate inconditionnel (parité historique) ; CANCELED/REJECTED
// ne déclenchent une sortie tiroir que sur une commande PAYÉE.
if ($isRefundLike
    && ((int) $request->status === \App\Enums\OrderStatus::RETURNED
        || (int) $order->payment_status === \App\Enums\PaymentStatus::PAID)) {
    abort_unless(
        auth()->user()?->can('pos-refund') ?? false,
        403,
        'Permission insuffisante pour effectuer un remboursement.'
    );
}
```

Note parité: `OnlineOrderController::changeStatus` et `TableOrderController::changeStatus` partagent le même trou pour CANCELED (ils ne gate que RETURNED) — à traiter dans le lot GESTION si les commandes en-ligne/table portent une Transaction remboursable. Le vecteur CAISSE (cash direct) est le plus directement exploitable.

---

## Coverage — surfaces vérifiées SOLIDES (pas de finding)
- **Pricing/tampering (`PosController::store`→`OrderService::posOrderStore`)**: `total`/`subtotal`/`discount` client sont `unset` avant `Order::create` (OrderService.php:715) ; prix 100% recalculés via `PricingService` SSOT ; items/variations/extras revalidés depuis la DB avec garde cross-item ; remise gated `assertPosManualDiscountAllowed` + `assertDiscretionaryDiscountAllowed` ; `delivery_charge` forcé à 0 hors DELIVERY (PosOrderRequest:36-51).
- **Branch ownership sur create**: OrderService.php:744-754 rejette `branch_id != auth.branch_id` pour non-Admin.
- **Fiscal bypass (NF525)**: `changePaymentStatus` bloque `PENDING_COUNTER→PAID` direct (OrderService.php:2584-2588) et alloue une séquence fiscale sur `UNPAID→PAID` (2608-2620). `confirmCounterPayment` valide `mode` (allowedModes) + `received>=total` + lock + garde double-encaissement (409).
- **IDOR**: `show` + `PosLoyaltyController::redeem` = `withoutGlobalScope(BranchScope)` + check branche explicite ; `changeStatus`/`changePaymentStatus`/`destroy`/`reorderItems` = route-model binding sous BranchScope ; `ParkedOrderController`/`FloorplanController` = `resolveOperatorContext` (owner + branch>0) ; `CashDrawerSessionController::assertSessionVisibleToUser` = branch + ownership + manager-override ; `DiningTableService::occupy` valide `order_id` même-branche.
- **RBAC routes annexes**: `orders/{order}/escpos` (`can('pos')` + branch), `customers/lookup-by-nfc` (`permission:pos` + branch), `customer-display` (`permission:pos`), `print-receipt`/`print-kitchen` (`permission:pos-orders|pos`) — toutes gardées.
- **Mass-assignment**: `PosOrderRequest`/`PaymentStatusRequest`/`OrderStatusRequest` = whitelists strictes ; `payment_status`/`status` non exposés à un merge client.
- **Replay/idempotency**: `idempotency` middleware sur toutes les routes mutating POS + lock Cache + `X-Idempotency-Key` scopé (branch,key) ; garde double-mirror refund (UNIQUE parent_order_id → 409).
