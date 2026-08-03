# RED-TEAM VERIFIER — Refund-bypass via change-status (CAISSE r1)

Rôle : vérificateur adversaire (défaut = REFUTED). Verdict : **CONFIRMÉ — P1 (réel, reproduit indépendamment).**

---

## [P1] app/Http/Controllers/Admin/PosOrderController.php:312 — Refund-bypass: POS Operator sans `pos-refund` rembourse en cash via change-status→RETURNED

### repro (PHPUnit probe isolé sqlite :memory:, faithful au pattern de PreZRefundViaEndpointTest)
Acteur : User role `POS Operator` (guard sanctum) avec `pos` + `pos-orders`, SANS `pos-refund`.
Commande : POS, status=DELIVERED(13), payment_status=PAID(5), fiscal_sequence_no=500, transaction type=`payment` préexistante, Z NON scellé (pré-Z, créée il y a 5 min).
- (A) `POST /api/admin/pos-order/{id}/refund-with-counter-entry {reason}` → **403** (gate OK).
- (B) `POST /api/admin/pos-order/change-status/{id} {status:22,reason:'x'}` → **200** : order→RETURNED(22) + `cash_back` Transaction de 30.00 créée.

### evidence (sortie probe verbatim — reproduite, PAS recopiée du finding)
```
[PROBE] op can pos-orders = YES | op can pos-refund = NO
[PROBE] dedicated refund-with-counter-entry status = 403
[PROBE] change-status->RETURNED status = 200
[PROBE] orderB status_after = 22 | cash_back_txn = YES amount=30.000000
```
Live foodking_e2e :
- Grants : `POS Operator={pos,pos-orders}` (PAS pos-refund) ; `Admin`+`Branch Manager`=+pos-refund. (SELECT role_has_permissions confirmé.)
- 44 utilisateurs POS Operator existants (acteurs réels exploitables).
- 1411 commandes `source_surface=pos` status=13 + payment_status=5 ciblables tant que Z non clôturé.

### chaîne de code (toutes lignes re-Read)
1. Route `routes/api.php:983` `/change-status/{order}` → `PosOrderController::changeStatus`. Middleware constructeur `PosOrderController.php:28-36` = `permission:pos-orders` UNIQUEMENT (POS Operator l'a). **Aucun gate `pos-refund`.**
2. `OrderStatusRequest::authorize()` (app/Http/Requests/OrderStatusRequest.php:24-26) : `hasAnyRole([...,'POS Operator',...]) → true`. Aucun check refund.
3. `OrderService::changeStatus` re-valide via `ValidStatusTransition` (OrderService.php:2145) → `OrderStateMachine::allows(13,22,user)`.
4. `OrderStateMachine.php:76-77` case DELIVERED : `return $to === RETURNED;` **INCONDITIONNEL** (les cases ACCEPT/PREPARING/PREPARED exigent `pos-refund` pour RETURNED — la post-livraison non).
5. Branche RETURNED : `SealedOrderGuard` (OrderService.php:2160-2183) bloque seulement post-Z ; pré-Z passe → `if ($locked->transaction) PaymentService::cashBack(...)` (**OrderService.php:2188-2193**) + `LoyaltyService::refundPoints` (2195).

### lentille
commerçant — un employé abuse ses droits : remboursement cash (mouvement d'argent NF525) sans la permission `pos-refund` dédiée à cet effet, via la voie sœur non gardée.

### racine frozen ?
La racine state-machine `OrderStateMachine.php:76-77` est **FROZEN + sentinelée** : `tests/Feature/Order/OrderStateMachinePreZRefundLockSentinelTest.php:164` verrouille `allows(DELIVERED,RETURNED,null)=true` (LOCK-OSM-PREZ-REFUND 2026-06-04, owner-gated). ⇒ NE PAS toucher la state-machine. Le gate manquant est au niveau **contrôleur** (`PosOrderController` = NON-frozen, absent de toute liste frozen).

### pourquoi P1 et non P0
Mouvement d'argent + contrôle d'autorisation contourné = sévère. MAIS : (a) requiert un acteur authentifié (insider, pas anonyme) ; (b) le cashBack est entièrement audité (`order.returned` AuditLog HMAC OrderService.php:2230-2245 + ActionLog 2211 + order_status_transitions 2202) ; (c) bloqué post-Z par SealedOrderGuard → pas de gap fiscal silencieux, seulement les remboursements pré-Z (même jour) sont atteignables. ⇒ faiblesse de contrôle d'autorisation = **P1**, pas P0.

### corroboration historique (le finding n'est pas une hallucination)
`tests/Feature/PosRefundUiPermissionSentinelTest.php:24-36` docstring : « Pre-heal, the route was permission-guarded only via `permission:pos-orders` (POS Operator HAD pos-orders) — a cashier could issue NF525 counter-entry refunds at will ». Le heal `pos-refund` a couvert **uniquement** `refundWithCounterEntry` (abort_unless PosOrderController.php:58-62) ; la voie sœur `change-status → RETURNED` pré-Z a été oubliée. Aucun test n'assert qu'un POS Operator est bloqué sur `change-status → RETURNED` (grep tests : seulement cross-branch 403/404 + sealed-Z).

### reco (NON-frozen, TDD)
Dans `PosOrderController::changeStatus` (NON-frozen), avant de déléguer à OrderService :
```php
if ((int) $request->input('status') === \App\Enums\OrderStatus::RETURNED) {
    abort_unless(\Illuminate\Support\Facades\Auth::user()?->can('pos-refund') ?? false,
        403, 'Insufficient permission to issue refund.');
}
```
(même gate que `refundWithCounterEntry:58-62`). Laisser le 403 bubble : `changeStatus` masque tout en 422 (catch ligne 318) → soit re-lancer `HttpException` comme `destroy`/`selectDeliveryBoy`, soit gater AVANT le try. Attention : le chemin LÉGITIME `refundPreZ` (PosOrderController.php:215-229) appelle `OrderService::changeStatus` directement (PAS via le contrôleur HTTP) — il reste fonctionnel car il a déjà gaté `pos-refund` en amont (ligne 58). Le gate ajouté est dans `changeStatus()` HTTP seul, donc n'affecte pas refundPreZ.
TDD : `RefundBypassGuardTest` — Operator→change-status RETURNED = 403 + 0 cash_back ; Admin/Branch Manager = 200 + cash_back.

### lentille jumeau-systémique (round suivant)
Voies sœurs partageant `OrderService::changeStatus`→cashBack : `OnlineOrderController::changeStatus` (gate `permission:online-orders`), `TableOrderController` / `Admin/AdminTableOrderController`, `Frontend/OrderController::changeStatus`. Vérifier si leurs acteurs peuvent atteindre →RETURNED+cashBack sans `pos-refund`. NON-bloquant pour CE finding.
