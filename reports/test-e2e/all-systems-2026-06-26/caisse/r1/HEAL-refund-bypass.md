# HEAL — Refund-bypass P1 (twin-route authz drift) — Caisse r1

**Date** : 2026-06-26
**Sévérité** : P1 (vrai bypass d'autorisation — vecteur mass-refund par opérateur junior)
**Statut** : ✅ FERMÉ (TDD rouge→vert, non-frozen, 0 frozen-diff, NON committé)

---

## 1. Le bug (triple-confirmé, reproduit en test)

Un **POS Operator** (rôle 7 : a `pos` + `pos-orders`, **PAS** `pos-refund`) pouvait
**rembourser** une commande POS **DELIVERED(13) + PAID(5)** via la route sœur
`POST /api/admin/pos-order/change-status/{order}` avec `{"status":22}` (RETURNED).

- Route `routes/api.php:983` gardée seulement par `permission:pos-orders` (que l'opérateur a).
- → `PosOrderController::changeStatus` (`:312`) déléguait **sans aucune vérification** à
  `OrderService::changeStatus`.
- → `OrderStateMachine::allows(13,22,$user)` ligne 76-77 `case DELIVERED: return $to===RETURNED;`
  **inconditionnel**, actor-agnostique.
- → exécutait `PaymentService::cashBack()` (argent rendu + crédit solde + release stock,
  ligne `transactions` `type='cash_back'`) + `LoyaltyService::refundPoints()`.

L'endpoint dédié `refundWithCounterEntry` EST gardé (`PosOrderController.php:58-62`
`abort_unless(can('pos-refund'),403)`) — mais la route sœur `change-status` ne l'était PAS.
= **twin-route authz drift** ré-ouvrant exactement le vecteur que la garde dédiée
(PROPOSAL_POS_REFUND_UI_2026-05-25 §8 risk #1) avait fermé.

---

## 2. Le heal (non-frozen, couche CONTRÔLEUR)

`app/Http/Controllers/Admin/PosOrderController.php::changeStatus` (+25 lignes) — **miroir
EXACT** de la garde de `refundWithCounterEntry` :

```php
if ((int) $request->status === \App\Enums\OrderStatus::RETURNED) {
    abort_unless(
        auth()->user()?->can('pos-refund') ?? false,
        403,
        'Permission insuffisante pour effectuer un remboursement.'
    );
}
```

+ ajout d'un `catch (HttpException $http) { throw $http; }` avant le `catch (Exception)`
générique (les 403 cross-branch venant de `OrderService::changeStatus` atteignent le
client intacts, plus masqués en 422).

**OrderStateMachine.php = NON touché** (frozen + owner-locked
LOCK_ORDERSTATEMACHINE_PREZ_REFUND). L'arête `DELIVERED→RETURNED` reste inconditionnelle ;
l'autorisation vit au contrôleur. Sentinelle frozen `OrderStateMachinePreZRefundLockSentinelTest`
= **8/8 inchangée** (`allows(DELIVERED,RETURNED,null)===true` reste vrai).

### Correction de harnais (légitime, non un affaiblissement)
`tests/Feature/Fiscal/PosOrderBL2AuditCallSitesTest.php` (+8) :
`test_change_status_to_returned_writes_order_returned_audit` passait **uniquement grâce au
bypass** — son acteur `Admin` n'avait pas `pos-refund` car `seedSpatieRoles()` construit une
liste fixe qui l'omet. En **production** l'Admin a `pos-refund` via `Permission::all()`
(`RolePermissionTableSeeder:19`). On accorde donc `pos-refund` à l'acteur Admin pour qu'il
exerce un remboursement **LÉGITIME** (et non l'arête fermée). Faithful-to-prod, pas un patch
du test pour cacher la régression.

---

## 3. Nouveau test (TDD) — `tests/Feature/Pos/RefundBypassGuardTest.php` (4 cas)

1. **POS Operator (no pos-refund) + DELIVERED+PAID → change-status RETURNED**
   → **403** + **0 ligne `transactions` type='cash_back'** + statut **INCHANGÉ (reste 13)**.
2. **Admin (pos-refund)** + même commande → **200** + ligne `cash_back` (refund légitime passe).
3. **Branch Manager (pos-refund)** + même commande → **200** (statut RETURNED).
4. **Non-refund par l'Operator** → OK : `ACCEPT→PREPARING` **200** ET le raccourci
   `pos` `ACCEPT→DELIVERED` **200** (aucun sur-blocage).

L'ordre porte une `Transaction` `type='payment'` préalable pour que `cashBack` se déclenche
réellement (preuve que de l'argent BOUGERAIT) — sinon early-return `PaymentService:132`.

### Probe `ZZ_RefundBypassProbe_Test.php`
**Inexistant** dans l'arbre (`find tests -iname '*RefundBypassProbe*'` = vide). Le probe du
prompt était hypothétique ; son assertion inversée est désormais portée — et durcie — par
le cas 1 de `RefundBypassGuardTest` (assertion 403 + 0 cash_back + statut figé).

---

## 4. Résultats tests — AVANT (rouge) / APRÈS (vert)

**AVANT le fix** (`vendor/bin/phpunit tests/Feature/Pos/RefundBypassGuardTest.php`) :
```
F...                                                  4 / 4
1) test_pos_operator_cannot_refund_via_change_status_returned
Expected response status code [403] but received 200.
Tests: 4, Assertions: 10, Failures: 1.
```
→ Bug reproduit : l'opérateur rembourse (200) via change-status.

**APRÈS le fix** :
```
....                                                  4 / 4
OK (4 tests, 12 assertions)
```

**Régressions** (filtre demandé `OrderStateMachinePreZRefundLock|PosRefundUiPermission|PreZRefund|ChangeStatus|PosOrder`) :
```
OK (61 tests, 227 assertions)
```
(dont sentinelle frozen 8/8, PosRefundUiPermission, PreZRefundViaEndpoint, ChangeStatusIdempotency)

**Filets élargis** (sécurité non-régression du `throw HttpException` + side-effects) :
```
CancelReasonEnforce|DeliveryStatusTransitionWhitelist : OK (14 tests, 37 assertions)
BranchIsolation|OrderStatusNoopSideEffects|PosCashTrail|OrderFlow : OK (42 tests, 135 assertions)
```

---

## 5. Frozen-diff gate

`git diff --stat` sur les 13 fichiers frozen (OrderStateMachine.php + sa sentinelle,
pos-wizard.js, pos-wizard.css, admin-pos-v4.blade.php, PaymentComponent.vue,
PosV5TrancheRow.vue, PricingService, FiscalSequenceService, ZReportService, AuditLogService,
BranchScope, IdempotencyKeyMiddleware) = **VIDE (0 ligne)**.

Fichiers réellement touchés par ce heal (tous NON-frozen) :
- `app/Http/Controllers/Admin/PosOrderController.php` (+25) — la garde
- `tests/Feature/Fiscal/PosOrderBL2AuditCallSitesTest.php` (+8) — grant pos-refund Admin (prod-faithful)
- `tests/Feature/Pos/RefundBypassGuardTest.php` (nouveau, untracked) — le test rouge→vert

(Le `git diff --stat` global montre du bruit working-tree pré-existant d'autres sessions —
playwright snaps supprimés, images menu, OrderQuoteService.php — sans rapport ni frozen.)

## 6. DB
DB de test phpunit = **sqlite `:memory:`** (phpunit.xml:52-53). `foodking_e2e` jamais touchée.

## 7. Gates
- TDD rouge prouvé → vert prouvé ✅
- Régressions : aucun test cassé (61 + 14 + 42 verts) ✅
- Sentinelle frozen 8/8 ✅ — arête state-machine inchangée
- Frozen-diff = 0 ✅
- RIEN committé ✅
