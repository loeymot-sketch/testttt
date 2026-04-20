# T07 — Idempotency branch-scoped (audit)

**Date.** 2026-04-20  
**Racine.** `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt`  
**Verdict.** **PASS** (avec 1 réserve admin)

## Constats

1. **`FrontendOrderService::myOrderStore`** — `Cache::lock` avec clé `sha1($lockBranchId . '|' . $idempotencyKey)` ; `lockBranchId` résolu **avant** le lock via `KioskMachine::where('user_id', Auth::id())->value('branch_id')` puis fallback `Auth::user()?->branch_id`. **Aligné** P9.5.5.
2. **`OrderService::posOrderStore`** — pas de `Cache::lock` dédié à l'idempotence ; pré-contrôle + insertion sous transaction + gestion `SQLSTATE 23000` ; les `Order::where('idempotency_key', …)` sont soumises au **`BranchScope`** (isolation branche pour personnel non-admin). Équivalent acceptable au mutex distribué.
3. **Migration** — `database/migrations/2026_04_18_140003_scope_idempotency_key_to_branch.php` : index unique composite `orders_branch_id_idempotency_key_unique` sur `(branch_id, idempotency_key)`.
4. **Front** — clé d'idempotence générée façon UUID dans `kioskCart.js` ; envoyée via header `X-Idempotency-Key`.
5. **Tests** — `tests/Feature/IdempotencyBranchScopedTest.php` (index composite) ; `tests/Feature/OrderPipeline/KioskFullFlowE2ETest.php` (rejeu même clé sur 2 branches → 2 commandes distinctes).
6. **Audit historique** — `tasks/phase9/P9_5_BLOCKER_9.5.5_frontend_order_idempotency_lock_scope.md` : lock global → lock branch-scoped, **résolu** dans le code actuel.
7. **TPE / paiement carte** — `OrderController::paymentConfirm` : idempotence documentée sur `transaction_id` (pas un second `X-Idempotency-Key`).

## Réserve

Compte **admin** (`branch_id === 0`) : `BranchScope` ne filtre pas → un `where('idempotency_key', …)` peut théoriquement cibler une commande d'une autre branche (cas marginal, opérationnel).

## Actions optionnelles (non-bloquantes)

- Garder une trace explicite : si `Auth::user()->branch_id === 0`, refuser l'usage du raccourci `idempotency_key` ou exiger un `branch_id` en query.
- Documenter l'invariant « `paymentConfirm` ≠ idempotency commande » dans `docs/`.

## Décision

**T07 PASS** — invariant idempotency branch-scoped tenu. Réserve admin → backlog hardening optionnel.
