# RED-Z8 — Refund + Loyalty Cascade Audit
**Date**: 2026-05-19 · **Mode**: read-only adversarial · **Agent**: RED-Z8
**Scope** : Refund cascade (counter-entry + pre-Z cashBack) → loyalty refund → KDS recall → upstream webhook. Signed QR redeem.
**Branch HEAD** : `v1-0-1-hardening-2026-05-17` · primary anchors all read this session.

---

## A. Anchors verified (file:line)

| Concern | File | Line(s) | What it does |
|---|---|---|---|
| Counter-entry service | `app/Services/Order/RefundWithCounterEntryService.php` | 52-252 | Mirror RTN-* order, negated totals/items/payments, fresh fiscal_sequence_no, audit row, `refundPoints` companion (L241), `RefundCreated::dispatch($parent)` (L248) — ALL inside one `DB::transaction` |
| Sealed-Z gate (post-Z mandatory) | `app/Services/Order/RefundWithCounterEntryService.php` | 70-71 ; `app/Services/Order/SealedOrderGuard.php` | `assertSealed` refuses pre-Z parents (forces standard RETURNED path) |
| Duplicate-mirror guard | `app/Services/Order/RefundWithCounterEntryService.php` | 73-78 | `if ((int) $parent->status === OrderStatus::RETURNED) throw` — see Finding P0-1 |
| RefundCreated event | `app/Events/RefundCreated.php` | 18-30 | `DispatchableAfterCommit`, carries `$refundedItems` (line-item map, default `[]` = full) |
| Listener — stock release | `app/Listeners/ReleaseStockOnRefundCreated.php` | 10-13 | `StockService::releaseForOrder($order, 'refund', $refundedItems)` |
| Listener — availability release | `app/Listeners/ReleaseAvailabilityOnRefundCreated.php` | 23-72 | empty `refundedItems` → full release; idempotent via ledger |
| Listener — broadcast bridge (WG-1 P1-1) | `app/Listeners/PersistOrderPaymentStatusChangedOnRefundCreated.php` | 56-139 | Persists `payment_status=REFUNDED` if mutable, dispatches `OrderPaymentStatusChanged`; LAST in array per failure-isolation pattern |
| Listener registration order | `app/Providers/EventServiceProvider.php` | 166-176 | `[ReleaseStock, ReleaseAvailability, PersistOrderPaymentStatusChangedOnRefundCreated]` — all sync, NO `ShouldQueue` |
| Outbox bridge for refund broadcast | `app/Listeners/PersistOrderPaymentStatusChangedToOutbox.php` | 23-118 | Writes `DomainEvent`, dispatches `DispatchDomainEventsJob`, swallows broadcast errors |
| Pre-Z refund cashBack path | `app/Services/PaymentService.php` | 90-156 | Dispatch `RefundCreated::dispatch($order)` L152 — gated by `if ($transaction)` existing transaction check (idempotent) |
| Refund route | `routes/api.php` | 913-915 | `POST /admin/pos-order/{order}/refund-with-counter-entry` + `throttle:pos-order-update` + `idempotency` |
| Refund controller authz | `app/Http/Controllers/Admin/PosOrderController.php` | 28-36, 47-91 | `permission:pos-orders` only; cross-branch check L57-61 |
| FSM transitions involving RETURNED | `app/Domain/Order/OrderStateMachine.php` | 61, 65, 265, 309 | Multiple terminal states (CANCELED/REJECTED/RETURNED) — RETURNED is fiscal counter-entry transition |
| `loyalty_customer_code` set at redeem | `app/Services/Loyalty/PosRedemptionService.php` | 188-202 | `loyalty_customer_code` written so `LoyaltyService::refundPoints` can find customer |
| Loyalty refund — service | `app/Services/LoyaltyService.php` | 21-79 | Looks up `type='redeem'` ledger rows, increments `loyalty_points`, writes `type='manual_add'` reversal |
| Loyalty redeem authz | `app/Http/Requests/PosLoyaltyRedeemRequest.php` | 22-25 | `permission:pos.redeem-loyalty` |
| Loyalty redeem controller | `app/Http/Controllers/Admin/PosLoyaltyController.php` | 35-90 | Uses `Order::withoutGlobalScopes()->find($orderId)` — see Finding P0-3 |
| Signed-QR HMAC + nonce | `app/Services/Loyalty/LoyaltyQrSigner.php` | 50-160 | HMAC-SHA256, payload contains `v/cust/code/nonce/iat/exp`, INSERT-then-catch race-safe consumption (L142-157), TTL 300s (L207), 30s leeway (L213) |
| Nonce table | `database/migrations/2026_05_19_100000_create_loyalty_qr_nonces_consumed_table.php` | 37-68 | UNIQUE GLOBAL on `nonce` |
| Loyalty UNIQUE constraint | `database/migrations/2026_03_26_075919_add_unique_to_loyalty_transactions.php` | 28-31 | UNIQUE `(user_id, order_id, type)` |
| KDS "recall" wiring (FRONTEND ONLY) | `resources/js/store/modules/kds.js` | 66-85 | Pure client-side localStorage state (60 s grace) — NO backend route, NO server event, NO outbox broadcast. See Finding P0-4 |
| WebhookEvent idempotency | `app/Models/WebhookEvent.php` ; `app/Http/PaymentGateways/Gateways/Stripe.php:255` ; `app/Http/PaymentGateways/Gateways/Senangpay.php:125` | — | `firstOrCreate(provider, webhook_id)` — INBOUND only; no outbound refund webhook wired |
| WH-2 CASH_ON_DELIVERY gate | `app/Services/OrderService.php` | 1582-1639 | PAID flip gated on DELIVERED status; legitimate. See Finding P2 |

`grep -rn "kdsrecall\|kds.recall\|kds-recall"` in `app/` / `routes/` returned **EMPTY**. No backend cascade exists.
`grep -rn "RefundCreated::dispatch"` returned EXACTLY 2 production callsites : `PaymentService.php:152` and `RefundWithCounterEntryService.php:248`. Both pass `$order` only, NO `$refundedItems` → partial refund is plumbed but never invoked.

---

## B. Findings P0 → P3

### P0-1 — Double counter-entry mirror NOT prevented at DB level
- **Anchor**: `RefundWithCounterEntryService.php:73-78` guard predicate is `$parent->status === OrderStatus::RETURNED`.
- **Bug**: counter-entry path NEVER mutates `parent.status` (NF525 immutability — L29-30 docblock). So the guard checks a condition that, in normal counter-entry flow, will always be false. Migration `2026_05_06_200000_add_parent_order_id_to_orders.php:25` adds INDEX but **NO UNIQUE** on `parent_order_id`. The ONLY operational deduplication is the route-level `idempotency` middleware scoped on `(branch_id, user_id, sha256(X-Idempotency-Key))` (`IdempotencyKeyMiddleware.php:76-81`). A buggy/malicious POS client sending TWO different idempotency keys for the same parent → two distinct mirrors → DOUBLE refund on the Z report (two negative tranches against one positive sale).
- **Cited evidence**: read this session — guard at L73-78 + L99 inserts `parent_order_id` without UNIQUE.
- **Severity**: P0 NF525 — double-refund leaks money out via Z aggregate.
- **Owner ask**: gate-required — UNIQUE `parent_order_id` (partial UNIQUE WHERE NOT NULL) + check before INSERT. The sentinel test `RefundMirrorSplitPaymentTest::test_refund_idempotent_no_double_mirror_payments` (L189) ALREADY DEMONSTRATES the workaround by forcing `parent->status=RETURNED` — i.e. the live test acknowledges the guard is brittle.

### P0-2 — `LoyaltyService::refundPoints` lacks try/catch on duplicate `manual_add`
- **Anchor**: `LoyaltyService.php:62-71` — `LoyaltyTransaction::create` for `type='manual_add'` is bare.
- **DB constraint**: UNIQUE `(user_id, order_id, type)` (`2026_03_26_075919_add_unique_to_loyalty_transactions.php:29`).
- **Bug**: a second `refundPoints` call (e.g. owner re-triggers the post-Z refund flow against a parent that already has a mirror) throws `QueryException 23000`. Inside `RefundWithCounterEntryService` transaction this DOES roll back the WHOLE mirror creation — defensive — BUT the controller catches `Throwable` and returns generic 500 (`PosOrderController:81-89`). UX is opaque; the operator does not learn "already refunded" semantics. Even worse on the pre-Z path : `OrderService:1856` calls `refundPoints` outside a sub-transaction nested in the larger `changeStatus` transaction; uncaught QueryException ABORTS the changeStatus transaction. Result : status flip rolls back, but cashBack ledger row may have committed (cashBack is inside its own audit + transaction write).
- **Severity**: P0 functional — bad UX + possible state-divergence cross-cascade.
- **Owner ask**: wrap `LoyaltyTransaction::create` in try/catch on `errorInfo[0] === '23000'` → return silent no-op (idempotent semantic). Mirror pattern at `PosRedemptionService.php:177-184` (which ALREADY catches 23000 cleanly for `redeem` insert).

### P0-3 — `PosLoyaltyController::redeem` bypasses BranchScope on cross-branch order lookup
- **Anchor**: `PosLoyaltyController.php:41` — `Order::withoutGlobalScopes()->find($orderId)` with NO explicit branch check downstream.
- **Bug**: comment L37-40 claims "permission gate already filtered" — but Spatie `permission:pos.redeem-loyalty` does NOT discriminate target branch. A branch-A cashier whose role grants `pos.redeem-loyalty` could call `POST /admin/pos-order/{branchB_order_id}/redeem-loyalty` and the request succeeds (Order resolved via withoutGlobalScopes, PosRedemptionService at `PosRedemptionService.php:144-151` only looks up open cashier session on `$order->branch_id` — null session is tolerated). Cross-branch loyalty redemption is therefore possible.
- **Severity**: P0 multi-tenant — violates §9 Branch Isolation invariant. Z6 owns BranchScope sentinel but this is on the loyalty surface so flagging.
- **Owner ask**: add explicit `abort_unless($order->branch_id === $user->branch_id || $user->hasRole('Admin'), 403)` before service call. Mirror `PosOrderController::show:117-121` shape.

### P0-4 — KDS "recall" cascade does NOT exist server-side
- **Anchor**: brief states "KDS recall event" as part of refund cascade. Verified: `grep -rn "KdsRecallEvent\|kds.recall\|recallItem"` in `app/` = **EMPTY**. The recall is entirely client-side in `resources/js/store/modules/kds.js:66-85` — purely local Vuex state, 60s hard-coded grace window in JS (line 71), localStorage persistence, no backend route, no broadcast, no outbox row, no audit log.
- **Implication for refund cascade**: a refund (PaymentService::cashBack OR RefundWithCounterEntryService) does NOT trigger any "recall" signal to KDS. Connected KDS stations only see the order update via `OrderPaymentStatusChanged` broadcast (post WG-1 P1-1 heal at L120-138 of the listener) → the KDS UI relies on a refresh trigger to re-fetch order list. If the kitchen has already STARTED cooking a refunded item (PREPARING), there is no UX banner / alert telling them to stop.
- **Severity**: P0 product — refund signal is invisible to kitchen for items already in flight. Money refunded, but food still being made / wasted.
- **Owner ask**: gate-required — either (a) add server-side `KdsRecallEvent` event + outbox + KDS card "RECALLED — DO NOT SERVE" banner, OR (b) document this is acceptable for V1 because Le Cayenne always reaches out to kitchen verbally on refund. The brief explicitly listed this as part of "Z8 cascade" — so today the cascade IS BROKEN.

### P1-1 — Refund-amount validation entirely absent — always full refund of `$parent->total`
- **Anchor**: `RefundWithCounterEntryService.php:102-104` negates entire `$parent->subtotal/total_tax/total`. The mirror's `total = -1 * $parent->total`. Controller validates only `reason` (`PosOrderController.php:52-54`). No "amount" param, no "refundedItems" param.
- **Bug**: no support for partial refund (e.g. "only refund the cold burger, not the drink"). `RefundCreated::dispatch($parent)` at L248 sends EMPTY `$refundedItems` → `ReleaseAvailabilityOnRefundCreated.php:33-48` releases ALL line items. There is NO way for the cashier to refund a single line. The PRD `Event::__construct` parameter is plumbed end-to-end (event signature, listener handles partial) but UNUSED in production.
- **Severity**: P1 product — Le Cayenne staff cannot do partial refunds. Workaround : refund entire order + sell remaining items as a fresh order. Awkward but not broken.
- **Owner ask**: confirm V1 acceptance of full-refund-only semantics, or schedule partial refund for V1.0.2.

### P1-2 — `cashBack` `audit_log_row` + `transaction` rows are NOT inside the same DB::transaction as `RefundCreated::dispatch`
- **Anchor**: `PaymentService.php:90-152`. No `DB::transaction` wrapper. The Transaction::create (L104), User->save() (L116), AuditLogService::write (L123-137), recordCashBackMovement (L142), and `RefundCreated::dispatch` (L152) are sequential but NOT atomic.
- **Bug**: an exception between L104 and L152 (e.g. AuditLogService throws because chain head missing in fresh DB, or recordCashBackMovement DB failure) leaves a committed Transaction row but NO RefundCreated → no stock release, no payment_status flip, no broadcast. State divergence : Transaction says refunded, Order still PAID.
- **Mitigation**: when called FROM `OrderService::changeStatus` (L1747, L1850) the OUTER `DB::transaction` is in scope — `DispatchableAfterCommit` defers the event. But OUTER doesn't cover the Transaction::create + audit row directly — they participate in the outer txn via Laravel implicit shared connection. Possible. But the `recordCashBackMovement` may run outside the outer txn (it self-manages — verify).
- **Severity**: P1 fiscal-adjacent.
- **Owner ask**: explicit `DB::transaction` wrapper around the whole cashBack flow + dispatch in afterCommit. The COD escrow flow at OrderService.php:1614-1629 sets a SEPARATE escrow path (not under cashBack) — verify that escrow→refund doesn't double-count.

### P2-1 — Self-cancel race in `OrderService::changeStatus(auth=true)` triggers `refundPoints` BEFORE `RefundCreated::dispatch`
- **Anchor**: `OrderService.php:1747-1754` (self-cancel for kiosk customer). `cashBack` happens at L1747 with transaction created, dispatches `RefundCreated` via L152 of PaymentService. THEN `refundPoints` runs at L1753 — INSIDE the lockForUpdate transaction.
- **Bug**: the call ordering is `cashBack` (which dispatches RefundCreated → fires PersistOrderPaymentStatusChangedOnRefundCreated which does `DB::transaction` for payment_status persist) BEFORE `refundPoints` (which writes manual_add ledger). If `refundPoints` throws (P0-2), the outer lockForUpdate transaction rolls back — but `RefundCreated::dispatch` was deferred to afterCommit and NEVER fires since the outer txn rolled back. So `payment_status` was never flipped to REFUNDED, Transaction row never committed, AND loyalty refund failed. State : the customer's order remains CANCELED status (oh wait, also rolled back) — actually consistent rollback. False alarm BUT the cleanup is brittle.
- **Severity**: P2 — rollback semantics correct but the failure mode is opaque.

### P2-2 — Stock release listener can mask exceptions silently (Laravel sync listener chain halts on throw)
- **Anchor**: `EventServiceProvider.php:166-176` — listeners are sync (no `ShouldQueue`). Order is `ReleaseStockOnRefundCreated` then `ReleaseAvailabilityOnRefundCreated` then `PersistOrderPaymentStatusChangedOnRefundCreated`. By Laravel default, **a `Throwable` from listener N halts listeners N+1..** in the same dispatch.
- **Bug**: if `ReleaseStockOnRefundCreated.php:12` `StockService::releaseForOrder` throws (e.g. branch-scope violation in StockMovement insert), the broadcast listener `PersistOrderPaymentStatusChangedOnRefundCreated` (recent WG-1 P1-1 heal) NEVER runs. POS staff never see the realtime refund signal. The whole point of WG-1 (broadcast hole) re-opens silently.
- **Severity**: P2 reliability — WG-1 heal claims "best-effort" pattern but does not isolate other listeners' failures.
- **Owner ask**: switch listener registration to use `Event::listen` with try/catch wrapper OR mark all 3 listeners as `ShouldQueue` so each runs in isolation. Note : making them ShouldQueue requires careful re-test of the `DispatchableAfterCommit` interaction.

### P2-3 — Outbox: NO outbound webhook to upstream gateway on refund
- **Anchor**: `grep -rn "outbound.*webhook\|stripe.*refund\|senangpay.*refund"` returned no service emitting a refund-back-to-gateway POST. WebhookEvent (`app/Models/WebhookEvent.php`) is INBOUND-only — gateway → us. The Z8 brief stated "Outbox webhook to upstream gateway" but the only outbox row written on refund is `OrderPaymentStatusChanged` → `DispatchDomainEventsJob` → Pusher broadcast (internal `private-branch.{id}` channel). There is NO outbound Stripe refund API call, NO Senangpay refund call. Refund is fiscal/local only.
- **Severity**: P2 — if a customer paid by Stripe and was refunded in POS, no money flows back to the customer's card. Money refunded in cash drawer only.
- **Owner ask**: confirm Le Cayenne V1 always refunds CASH (no card refund). Stripe/Senangpay are kiosk-only. The product team needs to clarify that "card refund" requires a manual back-office gateway action.

### P3-1 — `OrderPaymentStatusChanged` payload contains synthetic `oldPaymentStatus` for sealed parents
- **Anchor**: `PersistOrderPaymentStatusChangedOnRefundCreated.php:67, 120-124`. For post-Z sealed parent, `$oldPaymentStatus` is the in-memory value before any mutation — but we don't mutate. The event payload signals `PAID → REFUNDED` while the DB row still has `PAID`. Connected clients refetching the parent see `PAID` again; clients holding the broadcast see `REFUNDED`. UI must reconcile by fetching the mirror.
- **Severity**: P3 — design tradeoff; docblock acknowledges (L41).

### P3-2 — Signed-QR `LoyaltyQrSigner::verifyAndConsume` accepts `source_surface` as STRING, no whitelist
- **Anchor**: `LoyaltyQrSigner.php:94, 146`. `$sourceSurface` defaults to 'kiosk' but trusts caller. Audit-only, but a 200-char string could land in DB if caller passes raw request input → bloats `loyalty_qr_nonces_consumed.source_surface VARCHAR(32)`. Truncation behavior depends on DB strict mode.
- **Severity**: P3 hardening.

---

## C. Hard questions for owner (20)

1. **(P0-1)** Owner — is `parent_order_id` UNIQUE constraint acceptable to add now, or is V1.0.1 frozen? Without it, two distinct idempotency keys = double-mirror = double Z-negative.
2. **(P0-1)** What's the operational POS UX that would cause two refund clicks on the SAME order? (Slow network + impatient cashier → likely.) Is this currently observed?
3. **(P0-2)** Should `refundPoints` second call be silent no-op (catch 23000) or surface as `409 ALREADY_REFUNDED`? V1 product decision.
4. **(P0-3)** Cross-branch POS loyalty redeem — does Le Cayenne single-resto care today? If not, V1.0.2 OK. But the sentinel `BranchScopeCoverageSentinelTest` is supposed to lock this — was a recent BUILD-6 refactor missed?
5. **(P0-4)** KDS recall on refund — Le Cayenne policy says staff calls kitchen verbally → no server signal needed? Or do you want a "REFUNDED" banner on KDS for in-flight items?
6. **(P0-4)** If yes — should the banner expire after a grace window (60 s like client-side bump grace) or persist until kitchen ACKs?
7. **(P1-1)** Partial refund — V1 acceptance of "full refund only" + workaround?
8. **(P1-1)** If partial refund is needed pre-launch — composition_snapshot semantics on partial : do we negate the per-line tax separately, or do we re-prorate?
9. **(P1-2)** PaymentService::cashBack atomicity — is current behavior (rely on outer caller transaction) acceptable, or wrap explicitly?
10. **(P1-2)** When `recordCashBackMovement` (cash drawer) is called outside outer txn, is the drawer's own transaction nested?
11. **(P2-1)** Self-cancel race rollback — is the rollback semantically correct from product POV (customer click → "transient error, retry" UX)?
12. **(P2-2)** ListenerChain isolation — V1.0.1 ship-blocker? If WG-1 P1-1 broadcast hole regresses silently on a stock-release exception, what's the alerting?
13. **(P2-3)** Refund TO upstream gateway — confirm V1 = no card refund, cash only? If kiosk Stripe order is refunded, customer expectation must be set ("we'll refund manually within 5d").
14. **(P3-1)** OrderPaymentStatusChanged synthetic old/new on sealed parent — does the POS UI properly reconcile by fetching the mirror? Or does it just trust the broadcast and show "REFUNDED" even though DB says PAID?
15. **(P3-2)** `loyalty_qr_nonces_consumed.source_surface` 32-char limit — any path that sets it > 32?
16. **General** — there's no Spatie permission `pos.refund`. `permission:pos-orders` is used (PosOrderController.php:28). This bundles refund + reorder + delivery-boy-select. Is this intentional, or should refund have its own permission to gate cashier-grade staff away from refunds?
17. **General** — `RefundWithCounterEntryService` requires sealed Z (assertSealed L70). The pre-Z RETURNED path uses standard changeStatus + cashBack. Two distinct entry points = two cascades = two test surfaces. Owner aware?
18. **General** — `RefundCreated::dispatch` is at L248 of the counter-entry service, AFTER the audit + log + loyalty refund. If `Log::channel('fiscal')` fails (silently absorbed at L220), and the `app(LoyaltyService::class)->refundPoints` throws (P0-2 case), the audit row WAS already written before the throw rolls back via DB::transaction → audit log is also rolled back since same transaction → fine, but the fiscal log channel write is NOT atomic with DB.
19. **General** — `RefundWithCounterEntryService::execute` `Auth::id()` (L85) is the cashier — but if called from a queue worker (no auth session), `$userId` defaults to null. The audit row payload has `'user_id' => null` — fiscal auditor would not know WHO refunded.
20. **General** — `RefundCreated::dispatch($parent)` passes the PARENT (positive qty) per L246-247 comment. But the mirror has NEGATED qty. The listeners iterate `$order->orderItems`. If the parent's `orderItems` collection was eager-loaded with mirror items leaked in (relation cache stale), the release would over-release. Verified: `$parent->loadMissing('orderItems')` at L120 of service is BEFORE mirror items are inserted, so the parent's cached collection only contains positive-qty parent items. Safe — but fragile if a refactor reorders.

---

## D. Sync invariants verified GREEN

- **Sealed-Z gate**: `RefundWithCounterEntryService::execute` L70-71 correctly refuses pre-Z parents via `SealedOrderGuard::assertSealed`. Single source of truth for "sealed" semantics across `destroy`, `changeStatus`, `aggregate`, `refund` (verified L66-69 commentary).
- **DispatchableAfterCommit**: trait at `app/Events/Concerns/DispatchableAfterCommit.php` defers `RefundCreated::dispatch` to commit. Inside `RefundWithCounterEntryService` outer `DB::transaction` (L88), the dispatch at L248 is queued via `DB::afterCommit`. Listener `PersistOrderPaymentStatusChangedOnRefundCreated` opens its OWN nested DB::transaction (L80) but at that point the outer is already committed. No `OrderSealedException` leak (L101-113 wraps payment_status persist in try/catch).
- **Sentinel — loyalty refund on counter-entry**: `tests/Feature/Refund/RefundWithCounterEntryRefundsLoyaltyPointsTest.php` exists, covers both presence and absence of `loyalty_customer_code`.
- **Sentinel — broadcast bridge**: `tests/Feature/Refund/RefundBroadcastsPaymentStatusChangedTest.php` covers pre-Z mutation, post-Z immutability, and end-to-end outbox row write.
- **Sentinel — split-payment mirror**: `tests/Feature/Refund/RefundMirrorSplitPaymentTest.php` covers per-mode net = 0 after refund and idempotency (with parent-status-flip workaround acknowledging P0-1).
- **WebhookEvent idempotency** (inbound): `WebhookEvent::firstOrCreate(provider, webhook_id)` correctly prevents duplicate Stripe/Senangpay webhook processing. Branch isolation deliberately bypassed (global scope absent — `WebhookEvent.php:43`).
- **Signed-QR**: HMAC-SHA256 + nonce + UNIQUE GLOBAL + TTL 300s + 30s leeway + production-secret-strength guard (L229-244). Race-safe consume via INSERT-then-catch (L142-157). Sentinel `LoyaltyQrSigningSentinelTest` covers valid / expired / tampered / replay / legacy plaintext / production boot guard.
- **CASH_ON_DELIVERY gate** (WH-2 commit `5e906658d`): PAID flip delayed to DELIVERED (OrderService.php:1611-1639) — correctly anchors NF525 escrow row to legal anchor.
- **PosRedemptionService DUPLICATE redeem guard**: `(user_id, order_id, type='redeem')` UNIQUE catches duplicate redeem with proper 409 surface (PosRedemptionService.php:177-184).

---

## E. Out-of-scope or unverifiable

- **Pusher broadcast end-to-end** — not testable without live broadcast. Read confirms the outbox row write and `DispatchDomainEventsJob::dispatch` (PersistOrderPaymentStatusChangedToOutbox.php:87) but actual realtime delivery to POS client is not verifiable in this session.
- **KDS UI behavior on refund broadcast arrival** — frontend behavior of the POS/KDS Vue components in response to a `OrderPaymentStatusChanged` broadcast was NOT inspected this session (out of Z8 lane — Z3 sync would cover).
- **Cash drawer reconciliation** (`recordCashBackMovement`) — was not deep-dived; bounded to confirming the cashback movement records `direction=out` (L139-143 comment claim).
- **Pre-Z cash-on-delivery refund flow specifically initiated by Driver** — DriverCash flow rolling-back stock and re-funding loyalty was not explicitly modeled in code; the standard `OrderService::changeStatus` path covers driver flows via the `cashBack($order, ...)` line 1850 — but the COD-specific UI/handler wasn't traced this session.
- **The exact upstream Outbox→gateway compensating action on Stripe webhook rejection** — the brief mentioned "gateway rejects refund (already-refunded, expired) — compensating action?" — confirmed NO outbound refund call exists, so this question is moot for V1.

---

## F. RED verdict

**Score**: 6 / 10.

**Shippable V1 Le Cayenne (single resto, cash-only)** : conditional GO **only if** owner explicitly accepts:
1. Double-mirror refund risk is operationally mitigated by cashier discipline + idempotency key (no DB UNIQUE guard).
2. KDS staff are verbally informed of refunds for in-flight items (no server-side KDS recall cascade).
3. Partial refund is not needed at launch.
4. Card refunds are not attempted at the POS (cash drawer only — Stripe / Senangpay refunds handled out-of-band).

**Top 3 risks**:
1. **P0-1** Double counter-entry mirror — direct money leak on Z report if cashier double-clicks with two idempotency keys. Add UNIQUE `parent_order_id` partial constraint pre-launch (1 migration, 0 frozen-zone diff).
2. **P0-3** Cross-branch loyalty redeem in `PosLoyaltyController` — multi-tenant invariant violation. V1 single-resto (Le Cayenne) reduces blast radius to "any cashier can debit any customer's points on any order" — still bad. 3-line fix at controller.
3. **P0-4** KDS recall cascade does not exist server-side — the brief described it as part of the cascade but it isn't. Either acknowledge it as out-of-scope V1 + document the manual workaround, or ship a small `KdsOrderRecalled` outbox event + KDS UI banner. Today, kitchen wastes food on refunded items they've already started.

**Not blockers** : split-payment mirror is solid, signed-QR is tight, sealed-Z gate is consistent across surfaces, WG-1 P1-1 / P1-2 heals do close real holes (broadcast + loyalty refund on counter-entry).

**Confidence**: read-only audit, ~25 file:line citations all anchored this session. No claim made without code grep. Two anchor failures (KDS recall server-side, outbound refund webhook) explicitly stated as EMPTY grep results per anti-hallucination rule.
