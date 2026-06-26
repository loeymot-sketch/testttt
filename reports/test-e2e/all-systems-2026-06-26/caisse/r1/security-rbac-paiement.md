# CAISSE r1 — Lentille SÉCURITÉ / RBAC — Sous-système « Paiement / encaissement / split »

Auditeur: lentille sécurité/RBAC. DB live `foodking_e2e` (read-only). Méthode: Read des fichiers ancrés + SELECT preuves réelles + PHPUnit filters existants (base test isolée). Anti-hallucination: chaque file:line vérifié par Read, chaque finding reproductible.

---

## [P1] app/Domain/Order/OrderStateMachine.php:76-77 (root, FROZEN) + app/Http/Controllers/Admin/PosOrderController.php:312-321 (fix non-frozen) — Refund-bypass: un POS Operator SANS `pos-refund` peut REMBOURSER une commande DELIVERED+PAID via `change-status → RETURNED`

repro (HTTP, reachable):
- Acteur: rôle **POS Operator** (role_id=7). Permissions live confirmées: a `pos`(12), `pos-orders`(13), `pos.redeem-loyalty`(20) ; **n'a PAS `pos-refund`(21)**.
  `mysql -u root foodking_e2e -e "SELECT p.name FROM role_has_permissions rhp JOIN permissions p ON p.id=rhp.permission_id WHERE rhp.role_id=7 AND p.name IN ('pos','pos-orders','pos-refund');"` → renvoie pos, pos-orders (PAS pos-refund).
- Cible: n'importe quelle commande **DELIVERED(13)+PAID(5)**. Live: `mysql -u root foodking_e2e -e "SELECT COUNT(*) FROM orders WHERE source_surface='pos' AND status=13 AND payment_status=5 AND deleted_at IS NULL;"` → **1411**.
- Appel: `POST /api/v1/admin/pos-order/change-status/{order}` body `{"status":22,"reason":"x"}` (route `routes/api.php:983`, gate = `permission:pos-orders` seul — `PosOrderController::__construct:28-37`).
- Chemin: `PosOrderController::changeStatus:312` → `OrderService::changeStatus($order,$req,$auth=false)`. Validation transition = `ValidStatusTransition` → `OrderStateMachine::allows(13,22,$user)`. À la **ligne 77 `case DELIVERED: return $to===RETURNED;`** → **true INCONDITIONNEL** (aucun `hasPermissionTo('pos-refund')`, contrairement aux cas ACCEPT/PREPARING/PREPARED lignes 48/59/67). Puis `OrderService::changeStatus:2150-2196` exécute l'effet refund: cashBack() (si Transaction 'payment' existe → crédit balance + `RefundCreated` = release stock + audit), `LoyaltyService::refundPoints()` **inconditionnel**, status→RETURNED, audit `order.returned`.

evidence:
- `OrderStateMachine.php:76-77` lu (edge DELIVERED→RETURNED inconditionnel).
- `PosOrderController.php` lu: l'UNIQUE `can('pos-refund')` est dans `refundWithCounterEntry:59` ; **absent de `changeStatus:312-321`** (grep `can(` → seulement lignes 59, 221(commentaire), 253).
- Sentinelle `tests/Feature/Order/OrderStateMachinePreZRefundLockSentinelTest.php:163-165` ÉPINGLE `allows(DELIVERED,RETURNED,null)===true` (« always-legal refund edge »). `vendor/bin/phpunit --filter OrderStateMachinePreZRefundLockSentinelTest` → 8/8 OK (comportement confirmé).
- Intention de sécurité documentée CONTRADICTOIRE: `tests/Feature/PosRefundUiPermissionSentinelTest.php:23-42` — le heal HEAL-4 (2026-05-26) a fermé `refund-with-counter-entry` à `pos-refund` car « a cashier could issue refunds at will… mass-refund vector by junior cashier ». LOCK `plans/LOCK_ORDERSTATEMACHINE_PREZ_REFUND_2026-06-04.md:24` a fermé les edges pré-Z à `pos-refund` (« closed for regular POS operators »). Les DEUX heals visent « POS Operator ne doit PAS rembourser » — mais l'edge DELIVERED reste OUVERT à l'operator = sibling non-fermé.
- Portée Z: pré-Z DELIVERED uniquement (post-Z bloqué par `OrderService::changeStatus:2160-2183` SealedOrderGuard qui force la route `refund-with-counter-entry`, elle-même gated `pos-refund`).
- Magnitude argent: effet cashBack crédit-balance LIMITÉ pour ventes POS cash directes (1 seule des 1411 a une `transactions` type='payment' ; `pos_received_amount` ne crée pas de Transaction). Mais (a) l'autorisation est contournée sans ambiguïté, (b) `refundPoints` rembourse les points sans `pos-refund`, (c) pour commandes counter-collect/online-payées delivered, la Transaction existe → crédit balance + release stock réels. RETURNED est la représentation canonique « remboursée » en codebase.

lentille: commerçant (un caissier junior peut frauder/annuler une vente encaissée et livrée, exactement le « mass-refund vector » que 2 autres heals ont voulu bloquer).

reco (NON-frozen — ne PAS toucher OrderStateMachine ni la sentinelle):
Ajouter un gate fail-fast dans `PosOrderController::changeStatus` (et symétriquement le mirror via `OrderService` si appelé ailleurs), AVANT d'appeler `orderService->changeStatus`, quand `(int)$request->status === OrderStatus::RETURNED`:
`abort_unless(auth()->user()?->can('pos-refund') ?? false, 403, 'Insufficient permission to issue refund.');`
— miroir exact de `refundWithCounterEntry:58-62`. TDD: nouveau `tests/Feature/Pos/RefundBypassGuardTest.php` (POS Operator + DELIVERED → POST change-status status=22 → **403**, no cashBack/refundPoints ; Branch Manager/Admin → 200). Laisse l'edge state-machine + sa sentinelle intacts (le contrôle d'autorisation vit à la couche contrôleur, ce qui est cohérent avec le pattern déjà en place). Si owner préfère fermer à la racine state-machine → ESCALADE (frozen + sentinelle à mettre à jour + gate owner).

---

## VECTEURS TESTÉS — TENUE PROUVÉE (pas de finding)

**Double-encaissement (race 2 caissiers)** — `PaymentService.php:278-310` : le commentaire `:227-249` dit « UNHEALED » mais le CODE est healé. lockForUpdate (`:220-223`) + sur réacquisition si déjà PAID → discrimine collector via audit `order.counter_payment_confirmed` : même caissier = no-op 200 (`:293-298`), caissier différent/inconnu = `PaymentAlreadyCollectedException` → route `api.php:858-875` convertit en **409** `error_code:payment_already_collected` (409 non-caché par idempotency). evidence: `vendor/bin/phpunit --filter 'CounterCollect|PaymentAlreadyCollected'` inclus dans run 50/50 OK. → TENUE. (Note: le commentaire prose « UNHEALED » est trompeur/stale — cosmétique, pas un bug.)

**Mode hors-liste injecté** — `confirmCounterPayment:203-215` `in_array($mode,[CASH,CARD,MOBILE_BANKING,OTHER,TICKET_RESTAURANT],true)` sinon 422 ; `validateBreakdown:66-87` idem par tranche. `COUNTER_DEFERRED(6)` exclu (ne peut être injecté comme mode d'encaissement). → TENUE.

**Split Σ≠total / tendered<amount** — `SplitPaymentService::validateBreakdown:51` : Σ<total → 422 (`:147`), overpay>1€ tolérance → 422 (`:157`), tranche CASH tendered manquant/≤0 → 422 (`:99`), `(int)round(tendered*100) < (int)round(amount*100)` → 422 (`:104`, comparaison en cents, pas de float-drift). CARD sans terminal_id ACTIVE/branch → 422 (`:117-138`, BranchScope bypass + branch_id vérifié explicitement = pas de fuite TPE cross-branch). evidence: `SplitPaymentEndToEndTest` 50/50 OK. → TENUE.

**Montant reçu < dû (monnaie négative)** — `confirmCounterPayment:315-319` : `mode===CASH && received!==null && received<total` → 422. Si `received===null` → `pos_received_amount=total` (`:327-329`, pas d'underpayment). → TENUE.

**Alloc fiscale au PAID comptoir** — `confirmCounterPayment:321-323` alloc `FiscalSequenceService::next(branch_id)` seulement si null, DANS la transaction (rollback = pas de gap). Live `foodking_e2e` fiscal max 2573, audit_logs head ffe782b9f42fedca (inchangé, read-only). → TENUE (séquence gap-free conservée).

**IDOR / cross-branch counter-collect** — routes `api.php:838/880/900` bindent `\App\Models\Order $order` (route-model-binding) → `Order` a `BranchScope` (`app/Models/Order.php:123`) → staff branch>0 ne peut binder une commande d'une autre branche (404). Admin branch=0 bypass scope mais `assertCounterOrderVisible:692-698` ne restreint que actorBranchId>0 (cohérent). Défense en profondeur. → TENUE.

**Loyalty redeem cross-branch / solde** — `PosLoyaltyController::redeem:45-56` bypass BranchScope (singulier, préserve SoftDeletingScope) + check branch explicite → 403 cross-branch ; gate `pos.redeem-loyalty` via FormRequest. evidence: `PosLoyaltyRedeemTest` (run 23/23 OK). → TENUE.

**`PaymentService::payment()` forge état PAID** — `:47` `assertGatewayContext()` exige un frame `PaymentAbstract` dans la backtrace (non-spoofable hors hiérarchie gateway) → 403 sinon. → TENUE.

Runs verts: `vendor/bin/phpunit --filter 'SplitPayment|CounterCollect|PosCashTrail|PaymentAlreadyCollected|ConfirmCounter'` = 50/50 ; `'PosLoyaltyRedeem|CashDrawerSessionOwnership|PosSimulationHardware|PosWalkinDeferred'` = 23/23 ; `OrderStateMachinePreZRefundLockSentinelTest` = 8/8.
