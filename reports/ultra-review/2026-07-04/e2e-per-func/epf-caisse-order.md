# E2E per-functionality audit — CAISSE (prise commande + paiement inline + tiroir)

- HEAD `3c7145bf4` · LIVE `127.0.0.1:8766` · DB `foodking_e2e` · POS_SIMULATION_HARDWARE=true
- Auth: real Sanctum tokens (admin `admin@lecayenne.fr`, POS operator `pos@lecayenne.fr` branch_id=1 role 7).
- Item under test: id=34 "Grande Frites" 4.00€ (simple, no required variation).
- Every POS commit requires: `X-Idempotency-Key` header + a sealed quote (`quote_token`+`quote_signature` from `/api/admin/pos/quote`). This is the SSOT binding — POS surface hard-fails without it (`OrderQuoteService::sealForCommit` :115-118).

## Verdict: ALL functionalities e2e-proven OK. 0 P0/P1/P2. 1 by-design PARTIAL (func 8, simulation).

| # | Functionality | Status | Live proof |
|---|---|---|---|
| 1 | CASH inline → PAID + fiscal + cash_movement | OK | order 5459 PAID(status5) fiscal=2613 + cash_movement#400 (session32); operator order 5469 PAID + cash_movement#404 session=20 amt=4 dir=in |
| 2 | CARD inline → PAID + fiscal, no cash_movement | OK | order 5463 PAID pm=2 fiscal=2615 note=1234, cash_movements=0 |
| 3 | Quote binding (SSOT, client totals ignored) | OK | tampered signature → HTTP 401; client subtotal/total=999 → server recomputes 4 (order 5467) |
| 4 | Cash-drawer open/close/reconcile | OK | duplicate open → blocked; current→open; movements→72 rows; close(389.46)→closed; reconcile→expected=389.46 variance=0 (session 20) |
| 5 | Loyalty discount (amount SSOT) | OK | 100 pts → discount_eur=1.00 exactly, total 7.90→6.90, balance 500→400 (order 5470, cust 64); 150 pts → 422 POINTS_NOT_MULTIPLE; paid order → 409 ORDER_ALREADY_FINALIZED |
| 6 | Parked orders (park/resume/discard) | OK | park#31→destroy 204; park#32→resume(show) 200 pop-consumes (index 0 after) |
| 7 | Idempotency double-POST | OK | same key ×2 → same order 5474 (no dup); same key + different payload → 409 |
| 8 | Session tiroir requise (CASH_NO_OPEN_SESSION) | PARTIAL (by-design) | guard code correct (`PosController::assertCashDrawerSessionOpenIfCashInvolved` :90-143) but returns early when `pos.simulation_hardware===true`; CASH order 5474 succeeded with NO open session → 0 cash_movement. 422 not reproducible while simulation ON. Prod boot-guard forbids simulation (AppServiceProvider). |

## Detail

### 1. CASH inline
`POST /api/admin/pos` pm=1, pos_received_amount=10, sealed quote → order 5459.
DB: payment_status=5 (PAID), pos_payment_method=1, fiscal_sequence_no=2613 (monotonic from 2612), total=4, total_tax=0.36. 1 cash_movement (type=order_payment, amount=4.00, dir=in) attached to the cashier's open session. Fiscal seqs branch 1 = 2610..2615 contiguous (gap-free). `fiscal:verify-chain --branch=1` → CHAIN OK.

### 2. CARD inline
pm=2 requires `terminal_id` + 4-digit `pos_payment_note` (PosOrderRequest rules). order 5463 PAID, fiscal=2615, note persisted, cash_movements=0 (correct — card is not cash). NB: `terminal_id` is not a column on `orders` (attribution lives in the Z-report/payment layer) — not a defect.

### 3. Quote SSOT
- Tampered `quote_signature` (all-zeros) → HTTP 401 "Order quote token and signature are required together." — commit refused.
- Client-forged `subtotal:999,total:999,pos_received_amount:1000` with a valid quote → server ignored them, order 5467 persisted subtotal=4/total=4. Backend is SSOT.

### 4. Cash-drawer session
POS operator (branch 1) session 20. `sessions/open` duplicate → "already open" (correct guard). `sessions/current`→open float 50. `sessions/{id}/movements`→72 rows. `sessions/20/close` closing_amount=389.46 → status=closed. `sessions/20/reconcile` → expected=389.46, variance=0, status=reconciled. (Admin branch_id=0 correctly refused open: "without a branch context".)

### 5. Loyalty redeem SSOT
`POST /api/admin/pos-order/{order}/redeem-loyalty` accepts `{points, loyalty_code}` only — no euro field, so the discount euro is impossible to forge. Server: `discountEur = round(points/rate,2)`, rate=100 (`PosRedemptionService` :96-108). Redeem 100 pts on PENDING order 5470 → discount_eur=1.00, order.discount=1, order.total 7.90→6.90, customer balance 500→400. Guards proven live: not-multiple-of-rate → 422 POINTS_NOT_MULTIPLE; already-paid order → 409 ORDER_ALREADY_FINALIZED (pre-payment guard :assertOrderRedeemable). Feature also master-gated on `pos.manual_discount_enabled` (=true here).

### 6. Parked orders
`park` (store) creates PosParkedOrder; `show` = `recall` which is POP semantics — returns a snapshot and DELETES the row (service :99), i.e. "resume". `destroy` = `discard` (delete without resume). park#31→DELETE→204; park#32→show→200 then index count 0. Both paths correct; the initial 404-on-destroy in testing was because show had already consumed the row — not a bug.

### 7. Idempotency
Same `X-Idempotency-Key` replayed → identical cached response (order 5474 both times, no second order created). Same key + mutated payload (qty 1→2) → HTTP 409 (payload-conflict). Matches dual-layer idempotency contract.

### 8. CASH_NO_OPEN_SESSION (simulation posture)
The controller-level guard and the OrderService defense-in-depth exist and are correct, but `pos.simulation_hardware===true` short-circuits the precondition (PosController :95-97). Live consequence observed: after closing session 20, an operator CASH order (5474) was still accepted as PAID+fiscal but produced **0 cash_movement** — the cash is not tied to any drawer session, so a Z-report cash reconciliation would not see it. This is the documented V1 "TPE simulé / single-box" posture (CLAUDE.md §8), and the production boot-guard REFUSES to boot with simulation on, where simulation=false re-enables the 422 CASH_NO_OPEN_SESSION enforcement. Therefore: not a V1 P0/P1 — flagged informational only.

## e2e artifacts created in foodking_e2e (test data, no schema/config writes)
Orders 5459, 5462/5463, 5467, 5469, 5472, 5474; loyalty redeem on 5470; drawer session 20 closed+reconciled; parked 30/31/32 created+consumed. Fiscal chain re-verified OK after all writes.
