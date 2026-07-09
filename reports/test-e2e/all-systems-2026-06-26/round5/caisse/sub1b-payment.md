# Sub-1.b — CAISSE Payment / encaissement / split / idempotency / refund authz
Round 5 · 2026-06-27 · READ-ONLY adversarial audit (CLIENT@payment + CASHIER lens)
Repo HEAD clean for all payment files (git status porcelain = empty on PaymentService / SplitPaymentService / PosOrderController / PaymentStateMachine / routes/api.php).

## Verdict: 0 P0 · 0 P1 · 0 P2 · 2 P3 (1 product-cosmetic, 1 test-harness). Refund-bypass P1 from prior rounds is HEALED **and committed** (commit 10e462149).

## Findings

### [P3] app/Services/Payments/SplitPaymentService.php:230-231,254 — `change_amount` is client-trusted, never server-recomputed
- Repro: `validateBreakdown`/`persistTranches` accept `{mode:CASH, amount:10, tendered:50, change:0}`; the row persists `change_amount=0` (truth = tendered−amount = 40). The value comes straight from `$t['change'] ?? $t['change_amount'] ?? 0`.
- Evidence: `grep change_amount app/` → consumed ONLY by `app/Http/Resources/OrderDetailsResource.php:168,188` (display). NOT read by any Fiscal / Cash / Reconciliation path. The cash-drawer IN movement uses `amount`, not change (SplitPaymentService.php:280-288). So a forged change_amount moves no money, touches no Z, no fiscal_sequence.
- Lens: cashier (drawer display only). Reco: server-compute `change = max(0, tendered-amount)` for CASH tranches — cosmetic robustness, low priority. Non-load-bearing.

### [P3 / HARNESS — not a product defect] Test false-RED via `--env=testing`
- Repro: `php artisan test --env=testing tests/Feature/Payment/PosCounterCollectRaceProtectionSentinelTest.php` → FAIL "AuditLogService: fiscal.audit_secret is not configured" (AuditLogService.php:288). Cause: `.env.testing:180` `FISCAL_AUDIT_SECRET=` (empty) OVERRIDES `phpunit.xml:82` when the `--env` flag forces the dotenv load. Every audit-writing path (confirmCounterPayment line 403, kiosk finalize, POS create) then throws → cascades into Split happy-path 422 + concurrency 0-event + race 422.
- Canonical invocation (NO `--env` flag → phpunit.xml `<env>` applies) → all GREEN (see below).
- Reco: other auditors must NOT pass `--env=testing` for fiscal/audit tests; or set a non-empty secret in `.env.testing`.

## Verified-CLEAN

- **T-1.b.1 confirmCounterPayment mode whitelist** — PaymentService.php:203-215. Allowed ⊆ {CASH=1,CARD=2,MOBILE_BANKING=3,OTHER=4,TICKET_RESTAURANT=5}; `in_array(...,true)` strict. Out-of-list `mode=99` AND non-tender `COUNTER_DEFERRED=6` → ValidationException (proven live via tinker on the byte-identical twin whitelist in SplitPaymentService.validateBreakdown: BAD-MODE BLOCKED, COUNTER_DEFERRED BLOCKED). `lockForUpdate` (line 220-223) + `PaymentStateMachine::assertCanTransition(→PAID)` (line 313).

- **T-1.b.2 Split breakdown** — SplitPaymentService.php:51-167. Σtranches < total → 422 (line 147); CASH tendered < amount → 422 (line 104); amount≤0 → 422 (line 90); overpay > 1.00€ tolerance → 422 (line 157); CARD requires branch-scoped ACTIVE terminal_id (line 117-139, BranchScope bypassed + explicit branch_id check). Live tinker: SUM-BELOW / TENDERED<AMT / BAD-MODE / COUNTER_DEFERRED / CARD-no-terminal all BLOCKED. Tests: SplitPaymentEndToEndTest 6/6, TerminalIdWireInTest 5/5 (canonical).

- **T-1.b.3 Change / negative-receive** — single-tender: `received < total` → ValidationException (PaymentService.php:329-333); split CASH: `tendered < amount` → 422 (SplitPaymentService.php:104-108). No negative change accepted; no server-side negative change emitted. `pos_received_amount` stored only for CASH (line 341-343).

- **T-1.b.4 Double-collect concurrency** — `lockForUpdate` (line 220-223); on re-read PAID: same-cashier replay (audit-row user_id == current) → 200 no-op (line 293-298); different/unknown collector → `PaymentAlreadyCollectedException` (line 305) → route closure typed-catch ABOVE generic 422 → **409 + error_code=payment_already_collected** (routes/api.php:858-875). 409 not cached by idempotency (2xx-only). The historical "UNHEALED two cashiers silent-200" trap is GONE. Proven: PosCounterCollectRaceProtectionSentinelTest 4/4 (service typed-exception, same-cashier no-op, route 409, route never-200-no-resource) + PaymentConfirmConcurrencySentinelTest 1/1 (duplicate TPE transaction_id pays exactly one order, second → 409/422, 2 status events not 4). Data integrity: cashier B adds 0 cash_movement / 0 audit / 0 Transaction; fiscal_sequence unchanged.

- **T-1.b.5 Refund authorization** — dedicated `refundWithCounterEntry` gated `abort_unless can('pos-refund')` (PosOrderController.php:58-62). **TWIN route** `POST /api/admin/pos-order/change-status/{order}` with `status=22 RETURNED` gated `abort_unless can('pos-refund')` BEFORE delegating (PosOrderController.php:328-334) — **GUARD PRESENT in working tree AND committed** (commit 10e462149; prompt's "uncommitted" note is stale). RefundBypassGuardTest 4/4: POS Operator (pos+pos-orders, no pos-refund) → 403 + 0 cash_back + status stays DELIVERED; Admin/Branch Manager → 200 + cash_back fires; non-refund transitions (ACCEPT→PREPARING, ACCEPT→DELIVERED) still 200 (no over-block). OrderStateMachine DELIVERED→RETURNED edge stays unconditional (owner-locked); authz lives at controller. **No P1 refund-bypass.**

- **T-1.b.6 No double-collect on terminal orders / no REFUNDED flip** — confirmCounterPayment refuses CANCELED/REJECTED/RETURNED (line 323-327) and already-PAID (line 278). PaymentStateMachine `PAID => []` (PaymentStateMachine.php:17) → a PAID order can NEVER transition to REFUNDED; enforced at confirmCounterPayment:313, cancelCounterPayment:654, changePaymentStatus (OrderService.php:2384,2417 + SealedOrderGuard at 2354-2360). Refund is represented as status=RETURNED + cashBack + audit while payment_status stays PAID (documented PosOrderController.php:94-103). cashBack is idempotent (PaymentService.php:97-103). REFUNDED reachable only from PENDING_COUNTER via cancelCounterPayment.

## Test evidence (canonical phpunit.xml env, sqlite :memory:)
- PosCounterCollectRaceProtectionSentinelTest: 4 passed
- PaymentConfirmConcurrencySentinelTest: 1 passed
- SplitPaymentEndToEndTest: 6 passed
- TerminalIdWireInTest: 5 passed
- RefundBypassGuardTest: 4 passed
(With `--env=testing` these false-RED due to empty `.env.testing` FISCAL_AUDIT_SECRET — see P3 harness note.)
