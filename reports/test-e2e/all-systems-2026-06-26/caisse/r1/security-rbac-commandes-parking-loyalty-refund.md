# CAISSE r1 — Lentille SÉCURITÉ/RBAC (Commandes / parking / loyalty / refund)

Lentille : commerçant — un employé peut-il abuser ses droits / frauder ?
DB live : foodking_e2e (lecture seule). Probe : PHPUnit sqlite :memory: (isolé, ZÉRO écriture sur la DB op). Probe supprimé après preuve.

---

## FINDING P1 — refund-bypass : Operator sans `pos-refund` rembourse en cash via `change-status → RETURNED`

**[P1]** `app/Services/OrderService.php:2188-2195` (+ route `routes/api.php:983`, gate manquant dans `app/Http/Controllers/Admin/PosOrderController.php:312`)
— Le caissier POS (POS Operator, sans `pos-refund`) déclenche un remboursement cash complet (cashBack + crédit balance client + sortie tiroir + reversal fidélité) sur une commande **DELIVERED + PAID** en passant par `change-status → RETURNED`, contournant le gate `pos-refund` que l'endpoint dédié `refund-with-counter-entry` applique.

### repro (PHPUnit probe, sqlite :memory: — isolé de foodking_e2e)
Acteur : `User` rôle `POS Operator` (a `pos`,`pos-orders`,`pos.redeem-loyalty` ; **n'a PAS `pos-refund`** — confirmé live, cf. evidence). Commande POS `status=DELIVERED(13)`, `payment_status=PAID(5)`, `fiscal_sequence_no` non nul, Z OUVERT (aucun ZReport closed → non-sealed), avec une `transaction type=payment` préexistante.

1. `POST /api/admin/pos-order/{id}/refund-with-counter-entry {reason}` → **403** (gate `abort_unless(can('pos-refund'))` à `PosOrderController.php:58-62` — OK, attendu).
2. `POST /api/admin/pos-order/change-status/{id} {status:22, reason:"x"}` → **200 OK**.
   - `ValidStatusTransition(13)->passes(22)` → `OrderStateMachine::allows(13,22,$operator)` → `case DELIVERED: return $to === RETURNED;` → **TRUE sans aucun check de permission** (`OrderStateMachine.php:76-78`).
   - `OrderService::changeStatus` branche RETURNED (`:2150,2188`) → `PaymentService::cashBack()` fire → `Transaction type=cash_back amount=30.00` créé, `User->balance += 30`, `CashMovement` direction=out, audit `payment.cash_back_issued`, puis `LoyaltyService::refundPoints` (`:2195`).

Sortie probe (verbatim) :
```
[PROBE] dedicated refund-with-counter-entry status = 403
[PROBE] change-status->RETURNED status = 200
[PROBE] orderB status_after = 22 | cash_back_txn = YES amount=30.000000
```

### evidence
- Grants live (foodking_e2e) :
  `SELECT r.name,p.name FROM roles r JOIN role_has_permissions rp ON rp.role_id=r.id JOIN permissions p ON p.id=rp.permission_id WHERE p.name IN ('pos','pos-orders','pos-refund') ORDER BY 1,2;`
  → POS Operator = {pos, pos-orders} ; **pas pos-refund**. Admin + Branch Manager = +pos-refund.
- Surface d'attaque live : `SELECT source_surface,status,payment_status,COUNT(*) ...` → **1411 commandes `pos` DELIVERED(13)+PAID(5)** (toutes ciblables tant que le Z courant n'est pas clôturé — predicate non-sealed `SealedOrderGuard`).
- Le sentinel `tests/Feature/PosRefundUiPermissionSentinelTest.php:24-36` documente NOIR SUR BLANC ce vecteur (« a cashier could issue NF525 counter-entry refunds at will … mass-refund vector by junior cashier ») et prétend l'avoir fermé — mais **uniquement sur l'endpoint dédié post-Z**. La voie sœur pré-Z `change-status` n'est pas couverte.
- `OrderStateMachine.php:76-78` (DELIVERED→RETURNED inconditionnel) est **verrouillé/sentinelé** (`OrderStateMachinePreZRefundLockSentinelTest.php:164`, `LOCK-OSM-PREZ-REFUND`) → la racine n'est PAS dans la state-machine (intentionnelle pour le chemin Admin/BM) ; le **gate manquant est au niveau contrôleur** `changeStatus`.

### lentille
commerçant (fraude employé) + NF525 (mouvement d'argent/tiroir déclenché par un rôle non autorisé au refund).

### reco (scope-minimal, NON-frozen)
Dans `PosOrderController::changeStatus` (NON-frozen) : avant de déléguer à `OrderService`, si `(int)$request->status === OrderStatus::RETURNED`, faire `abort_unless(Auth::user()?->can('pos-refund') ?? false, 403, 'Insufficient permission to issue refund.')` — exactement le même gate que `refundWithCounterEntry:58-62`. Idéalement aussi sur `OnlineOrderController`/`AdminTableOrderController`/`FrontendOrderController::changeStatus` (mêmes voies sœurs `→RETURNED` déclenchant cashBack — vérifier au round suivant ; lentille jumeau-systémique). NE PAS toucher `OrderStateMachine.php` (frozen + sentinelé). TDD : nouveau test `RefundBypassGuardTest` qui pingle Operator→change-status RETURNED = 403 + 0 cash_back, et Admin/BM = 200.
**Frozen** : la racine state-machine est frozen+lockée ⇒ on ne la touche pas ; le fix vit dans le contrôleur non-frozen. Pas d'escalade frozen nécessaire pour le fix lui-même.

---

## VÉRIFIÉS — défenses qui TIENNENT (REFUTÉ, pas de finding)

- **Endpoint refund dédié gated** : `PosOrderController::refundWithCounterEntry:58-62` `abort_unless(can('pos-refund'))` → Operator = 403 (probe). OK.
- **Refund cross-branch** : `PosOrderController:69-73` (non-Admin & branch≠ → 403) + `PosLoyaltyController:53-56` (idem) + `OrderService::changeStatus:2122-2127` (abort 403 cross-branch dans la tx). Robuste.
- **Double-refund mirror ×2** : `UNIQUE(orders.parent_order_id)` (migration 2026_05_19_200000) → 2ᵉ mirror = 409 `MIRROR_ALREADY_EXISTS` (`PosOrderController:170-176`) ; + garde `RefundWithCounterEntryService:86-91` (parent déjà RETURNED → 422). Robuste.
- **Loyalty redeem > solde** : `PosRedemptionService:135-141` `INSUFFICIENT_BALANCE` (422) dans `lockForUpdate`. Robuste.
- **Loyalty redeem après refund / sur PAID / terminal** : `assertOrderRedeemable:271-296` rejette PAID(409) + DELIVERED/CANCELED/REJECTED/RETURNED(409) ; discount≤subtotal (`:144-150`) ; single-redemption `UNIQUE(user_id,order_id,type)`. Robuste.
- **Loyalty gate** : `pos.redeem-loyalty` via `PosLoyaltyRedeemRequest::authorize()` (route `:1002`). Operator l'a, attendu.
- **PAID→REFUNDED bloqué** : `PaymentStateMachine.php:17` `PAID => []` ⇒ `changePaymentStatus` ne peut pas flipper REFUNDED (pas de voie de refund alternative par ce contrôleur).
- **Parking** : `ParkedOrderController:77-98` exige `(int)user.id===auth.id` + `branch_id>0` (Admin branch=0 → 403, anti-leak cross-branch). Resume = recall scoping (user+branch). Pas de chemin « resume un order déjà payé » ici (parking = payload panier, pas un Order fiscal). OK pour la lentille RBAC.

---

## Notes méthode / anti-hallucination
- Ancres du plan corrigées : contrôleurs sont sous `app/Http/Controllers/Admin/` (PAS `Admin/Pos/`) ; `ParkedOrderController` est sous `Admin/Pos/`. Lignes citées re-vérifiées par Read.
- Probe = harnais éphémère (sqlite :memory:), supprimé ; `git status` = 0 modif app/tests de ma part.
