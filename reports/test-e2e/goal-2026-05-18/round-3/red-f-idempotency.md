# RED-Visual F — Idempotency Sweep Validator (Round 3)

**Date** : 2026-05-18
**Branche** : `v1-0-1-hardening-2026-05-17`
**HEAD** : `8d7a06665` (docs evidence backfill) / inspected commit `bcc84c0c0`
**Role** : adversarial validator (READ-ONLY)
**Source of truth** : `agent-10-red-fiscal.md` (R1) + `impl-f-idempotency-evidence.md` (R2)

---

## 1. Four GAP fixes verification

All 4 routes inspected directly in `routes/api.php` (post-commit) — `'idempotency'` is **CONFIRMED PRESENT** in each middleware chain.

| Route | File:Line | Middleware chain | Verdict |
|---|---|---|---|
| `POST .../counter-collect/{order}/confirm` | `routes/api.php:768` | `['throttle:pos-order-update', 'idempotency']` (closure-style, ends with `})->middleware(...)->name('counter-collect.confirm');`) | ✅ PRESENT |
| `POST .../counter-collect/{order}/cancel` | `routes/api.php:788` | `['throttle:pos-order-update', 'idempotency']` ending `->name('counter-collect.cancel')` | ✅ PRESENT |
| `POST .../collect-kiosk-cash/{order}` | `routes/api.php:799` | `['throttle:pos-order-update', 'idempotency']` ending `->name('collect-kiosk-cash')` | ✅ PRESENT |
| `POST .../orders/{order}/print-receipt` | `routes/api.php:800` | `['idempotency']` (inline `->middleware('idempotency')->name('orders.print-receipt')`) | ✅ PRESENT |

Total post-PR routes carrying `idempotency` = **17** (13 pre-existing + 4 new), confirmed via `grep -n idempotency routes/api.php` (17 hit lines including the 4 new ones). Matches Impl F's claim exactly.

---

## 2. Re-sweep precision — missed routes audit

Performed independent enumeration : `grep -E "Route::(post|put|patch|delete|match)"` = **258** declarations (153 POST + 46 DELETE + 59 MATCH). Impl F's "199" counted only POST+DELETE (excluding `match`). Note that `match(['put','patch'], ...)` admin update endpoints (~59 routes) are HTTP-spec idempotent by overwrite semantics, so the omission is acceptable, **but** Impl F's "199 mutating routes" claim is technically incomplete.

High-risk POSTs lacking idempotency, cross-checked against Impl F's SAFE/DEFERRED table :
- **`POST /api/frontend/loyalty/redeem`** (L1224) — Impl F claims "transaction_id dedup at service level". **Actual mechanism** : `lockForUpdate` on `loyalty_code` row at `LoyaltyController.php:274` + throttle 5/min. Atomic write, single-shot per code (code becomes consumed). SAFE call holds, but Impl F's rationale wording was imprecise.
- **`POST /api/admin/pos/parked-orders`** (L803) — Impl F's SAFE is verified : `pos_parked_user_idem_uniq` DB UNIQUE + `idempotency_token` request field.
- **`POST /api/admin/fiscal/z-report/{open,close}`** (L1048, 1050) — Frozen-adjacent NF525, `z_reports.sequence_no` UNIQUE + HMAC chain provide stronger dedup. Throttle 10/min. SAFE confirmed.
- **`POST /api/frontend/payment/reconcile-pending`** (L1141) — Webhook UNIQUE on `pending_payment_confirmations.transaction_id`. SAFE.
- **`POST /api/admin/coupon/`** + **`toggle-status/{coupon}`** (L625, L630) — Admin CRUD, by-id, throttled `admin-mutation`. SAFE by HTTP semantics.

**No missed mutating route with credible double-submit hazard found.** Re-sweep concurs with Impl F's classification.

---

## 3. Agent 10 false-positive confirmation

Direct inspection of `git show bcc84c0c0~1:routes/api.php` (pre-PR state) :
- **Line 858** (`change-payment-status/{order}`) — middleware chain at L859 already contained `['throttle:pos-order-update', 'idempotency']` BEFORE Impl F touched anything. **FALSE POSITIVE CONFIRMED.**
- **Line 867** (`{order}/refund-with-counter-entry`) — middleware chain at L868 already contained `['throttle:pos-order-update', 'idempotency']` BEFORE Impl F touched anything. **FALSE POSITIVE CONFIRMED.**
- **Line 769** (`counter-collect.../cancel`) — was missing idempotency pre-PR, fixed in this commit. Agent 10 was correct on this one only.

**Impl F's claim "2 of 3 false positive" is exact.** Agent 10's pattern-match was 33% precision on the named lines.

---

## 4. Test quality assessment (5 NEW tests)

File : `tests/Feature/Idempotency/CounterCollectAndPrintIdempotencyTest.php` (164 lines).

| Test | Tier | What it proves | Quality |
|---|---|---|---|
| `test_counter_collect_confirm_route_is_wrapped_with_idempotency_middleware` | WIRING | Route name resolves + `gatherMiddleware()` contains `'idempotency'` | OK — contract assertion only, NOT dedup proof |
| `test_counter_collect_cancel_route_is_wrapped_with_idempotency_middleware` | WIRING | Same | OK — contract only |
| `test_collect_kiosk_cash_route_is_wrapped_with_idempotency_middleware` | WIRING | Same | OK — contract only |
| `test_print_receipt_route_is_wrapped_with_idempotency_middleware` | WIRING | Same | OK — contract only |
| `test_print_receipt_is_idempotent_on_replay_no_double_count` | BEHAVIORAL | POST twice w/ same key → second has `Idempotency-Replayed: true` AND `receipt_print_count` STILL == 1 | STRONG — proves NF525 duplicata count does NOT advance on replay |

**RED-team verdict** : The 4 wiring tests are **NOT dedup proof** — they only verify route binding. They DO close Agent 10's pattern-match gap (which itself was a wiring problem). The 1 behavioral test on `print-receipt` is genuine end-to-end dedup with a *negative assertion* (`assertSame(1, ...count)` after 2 POSTs), which is the strongest possible attestation. The 3 non-print routes (counter-collect confirm/cancel, collect-kiosk-cash) lack behavioral coverage in this NEW file, but Impl F correctly points to existing `IdempotencyMiddlewareTest.php` (8 tests, 35 assertions) which exercises the same middleware via a synthetic route harness. **Acceptable two-tier design.**

Test execution verified live : `./vendor/bin/phpunit tests/Feature/Idempotency/CounterCollectAndPrintIdempotencyTest.php` → **5/5 GREEN (12 assertions, 1.072s)**. Full suite : `tests/Feature/Idempotency/` → **13/13 GREEN (47 assertions, 2.680s)**.

---

## 5. DEFERRED routes risk assessment

| Route | Deferral target | Risk hand-waved? |
|---|---|---|
| 6× `change-status/{order}` (PosOrder L856, OnlineOrder L878, AdminTable L888, KDS L1007, FrontendOrder L1132, FrontendDeliveryBoy L1209) | V1.0.2 | **NO — justified.** Status transition X→X is throttle-defended (`pos-order-update` 60/min or default), idempotent-by-overwrite when target state == current state. OrderStateMachine guards transitions on most paths. Real risk = state A→B→A inconsistency would manifest as 1 advance + 1 throttle-block, not a double-spend. Defensible deferral. |
| 1× `change-status/{order}` (PosOrder L856 specifically) | V1.0.2 | Same — throttled, state machine. |
| `Item duplicate` (L653) | V1.0.2 | **NO — justified.** Admin-supervised, low frequency, double-click = 2 clones (operator can delete one). Not rush-hour mutation. Defensible. |
| 3× floorplan `transfer/assign/release` (L809-811) + `table-order/token-create` (L890) | V1.x dine-in | **NO — justified.** V1 has `pos.dine_in_enabled=false` per Graphiti `feedback_v1_dine_in_disabled_2026-05-06`. These endpoints are dormant. Will be addressed when dine-in is reactivated post-V1. |

**Total deferred** : 7 V1.0.2 + 4 V1.x = 11 routes. **NO hand-waving detected.** Each deferral has documented rationale aligned with CLAUDE.md §10 (continue/heal/block/escalate/human framework) and BRAIN §4 V1.x backlog.

**One minor RED note** : Impl F should add these 11 deferred routes to a concrete `plans/backlog/V1_0_2_BACKLOG.md` (or BRAIN §4 NEXT) rather than leaving them inline in the evidence doc. Visibility risk = future agent may not find them. Recommend P3 follow-up.

---

## 6. VERDICT

# **PASS — GO for merge**

**Rationale** :
- 4 GAP fixes verified at exact file:line locations with correct middleware chain.
- Agent 10's 2 false positives (L858, L867) confirmed via pre-PR git diff.
- Re-sweep concurs with Impl F's SAFE/DEFERRED classification (no missed credible double-submit hazard).
- 5 NEW tests + 8 pre-existing = 13/13 GREEN, 47 assertions; behavioral dedup proven for `print-receipt` via NF525 duplicata count negative assertion.
- DEFERRED routes (7 V1.0.2 + 4 V1.x) carry documented and defensible rationale; no hand-waving.
- Frozen-zone diff = 0 lines (verified by Impl F, no contradicting evidence).

**P3 follow-up (non-blocking)** : extract the 11 DEFERRED routes into a concrete `plans/backlog/V1_0_2_BACKLOG.md` so they don't drift out of sight.

**Minor RED notes (acknowledge, not block)** :
1. Impl F's "199 mutating routes" count excludes 59 `match(['put','patch'])` routes; total is actually 258. The omission is acceptable (HTTP PUT/PATCH semantically idempotent) but the wording is imprecise.
2. Impl F's `loyalty/redeem` SAFE rationale calls the mechanism "transaction_id dedup at service level"; actual code uses `lockForUpdate` on `loyalty_code` row. Same SAFE outcome, imprecise wording.

These do not affect the GAP-closure attestation.

---

## 150-word RED summary

Impl F's precision sweep is **correctly executed and verified**. The 4 GAP fixes (counter-collect confirm/cancel L768, L788; collect-kiosk-cash L799; print-receipt L800) all carry `'idempotency'` in their middleware chains, confirmed by direct inspection of `routes/api.php`. Agent 10's 2 of 3 false positives (L858, L867 already had idempotency pre-PR) confirmed via git show. Re-sweep of 258 mutating routes (153 POST + 46 DELETE + 59 MATCH; Impl F counted only the 199 POST+DELETE) found no missed credible double-submit hazard — all SAFE rationales hold (UNIQUE indexes, lockForUpdate, throttle, HTTP idempotent semantics). 5 NEW tests = 4 wiring + 1 strong behavioral (print-receipt double-POST → receipt_print_count stays at 1, replay header set). Full Idempotency suite 13/13 GREEN. DEFERRED routes (7 V1.0.2 + 4 V1.x dine-in) carry documented rationale, not hand-waved. **VERDICT : PASS — GO for merge.**

— END red-f-idempotency.md —
