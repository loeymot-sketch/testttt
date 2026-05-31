# GO-LIVE VAT-10 + Discretionary-Discount Dormancy — Convergence Book

**Cycle:** make Le Cayenne production-ready (10% VAT-included TTC + go-live blockers)
**Branch:** `heal/cms-pr1-quickwins-2026-05-18`
**Heal HEAD (round-3 P1):** `784c84d17`
**Date:** 2026-05-31
**Heal HEAD (round-4 P0):** `59b13bdec`
**Verdict (fiscal convergence):** 🟢 **GO** — round-4 found a real P0 (ungated SSOT coupon on web+table `OrderService`); it was healed and **round-5 (3 independent adversarial angles) confirms 0 ungated discount-persisting order paths remain.** Full suite 2749/0, frozen-zone 0, NF525 CHAIN OK. _Non-fiscal go-live items remain as owner decisions (§9)._

---

## 1. Scope recap (what this cycle delivered, across rounds 1→3)

| ID | Blocker | Status |
|----|---------|--------|
| **B1** | 10% VAT-inclusive (TTC) pricing — 45 Le Cayenne items, prices unchanged, behavioral Z verified | ✅ committed (earlier rounds) |
| **P0** | Seed-parity preflight gate (MENU_VAT / MENU_COUNT) + runbook truth (preserve SSOT, don't reseed fictional menu) | ✅ committed |
| **S1** | Offers mischarge — disabled across store/update/changeImage + item-assign | ✅ committed |
| **P1 / F1-dormancy** | Discretionary discounts (manual + coupon + loyalty) refused in V1 at every order-creation chokepoint until F1 is fixed under a lock-plan | ✅ **completed this session** — last path (FrontendOrderService) closed at `784c84d17` |

The F1-dormancy P1 was healed across the order paths in rounds 1–2 (OrderService POS/web/table + PosRedemptionService admin loyalty). **Round-3's adversary found the last ungated path: `FrontendOrderService` — the kiosk/web CUSTOMER order flow.** This book documents closing it + the round-4 convergence proof.

---

## 2. Round-3 P1 heal — FrontendOrderService customer-facing discount gate

**Defect:** the kiosk/web customer order flow (`FrontendOrderService::myOrderStore`) applied coupon **and** kiosk-loyalty-redeem discounts with no V1 gate. Loyalty **auto-accrues** (10 pts/€, redeem floor 50/100), so the discount was reachable with **zero admin action** → a fiscally-incorrect NF525 Z reachable by any customer.

**Fix (`app/Services/FrontendOrderService.php`):** a single gate at the convergence point (line 502), after both the SSOT branch (`:293`) and the legacy branch (`:465–476`) flow through `applyKioskLoyaltyDiscount` (`:485`) and before the save (`:504`). Mirrors `OrderService::assertDiscretionaryDiscountAllowed`:

```php
private function assertDiscretionaryDiscountAllowed(float $discount): void
{
    if ($discount > 0.0 && config('pos.manual_discount_enabled') !== true) {
        throw \Illuminate\Validation\ValidationException::withMessages([
            'discount' => "Les remises (coupon, fidélité) sont désactivées en V1 (correction fiscale TVA/HT en attente).",
        ]);
    }
}
```

**No points-loss verified:** the loyalty deduction (`:899` `DB::table('users')->update`) and the gate (`:502`) share **one** `DB::transaction` (`:177`). The gate's `ValidationException` rolls back points + ledger + order atomically. Proven by the new sentinel's rollback assertions.

---

## 3. Test changes (faithful realignment — NOT green-washing)

The new gate broke 7 tests that created a frontend/kiosk order **with a discount** and asserted success. Each was triaged **calc-vs-policy** against the canonical conventions (`PosDiscountTest:46`, `ManualDiscountDisabledV1SentinelTest`):

| File | Tests | Action | Rationale |
|------|-------|--------|-----------|
| `FrontendDiscountIntegrityTest` | valid_coupon, forged_totals, coupon-priority | enable `pos.manual_discount_enabled=true` per-test | subject = discount CALC / anti-forgery / priority — not the V1 on/off policy |
| `FrontendDiscountIntegrityTest` | **+ new** `test_discretionary_discount_disabled_by_default_on_frontend_v1` | **dormancy sentinel** | locks BOTH sub-paths (coupon + loyalty) at flag-OFF (prod default) → 422 + full rollback proof (no points burned, no ledger, no order) |
| `KioskLoyaltyLedgerAtomicTest` | nominal-redeem, duplicate-ledger | enable flag in `setUp` | subject = redeem mechanics (decrement / ledger atomicity / idempotency) |
| `KioskLoyaltyDoubleRedeemRefusedTest` | redeem-then-join, direct-redeem | enable flag in `setUp` | subject = redeem mechanics (single decrement / pending-join) |

The 422/403-asserting tests that **stayed green with the flag on** do so because their exception fires *before* the gate (ledger-mock throw at `:920`, pending-amount mismatch at `:877`, IDOR auth) — confirming the realignment did not mask anything.

---

## 4. Exhaustive gate enumeration (advisor #1 — complete list, not "nothing new")

The discretionary-discount master flag `config('pos.manual_discount_enabled')` (default **false** in V1) is enforced at **5** points. Every site that applies a discount to a **persisted order** or writes an `OrderCoupon` is proven to route through one of them:

| Enforcement point | File:line |
|---|---|
| `assertDiscretionaryDiscountAllowed` (manual+coupon+loyalty) | `OrderService.php:2812` (called @ 519/813/988/1514) |
| `assertManualDiscountAllowed` (manual-only) | `OrderService.php:2835` |
| `assertDiscretionaryDiscountAllowed` (kiosk/web) | `FrontendOrderService.php:805` (called @ 502) **[this heal]** |
| `applyToOrder` inline gate (admin POS loyalty) | `PosRedemptionService.php:72` |
| Prod boot preflight (warns if enabled in prod) | `PreflightProductionCommand.php:140` |

| Discount entry point | Discount write | Gate | Fiscal-Z relevant |
|---|---|---|---|
| Kiosk/web customer (FrontendOrderService) | `:508` + `OrderCoupon:582` | `:502` ✅ | yes |
| POS direct/quote (OrderService) | `:1010/1015` + `OrderCoupon:1089` | `:988` ✅ | yes |
| Table (OrderService) | `:1530/1535` + `OrderCoupon:1554` | `:1514` ✅ | yes |
| POS earlier paths (OrderService) | `OrderCoupon:556` | `:519/:813` ✅ | yes |
| Admin POS loyalty (PosRedemptionService) | post-create | inline `:72` ✅ | yes |
| Pre-redeem `/api/frontend/loyalty/redeem` | pending ledger only (`order_id=null`) | **none** | **no** — reserves points, creates no order/Z (see §6) |

_(Round-4 judge confirms/extends this table — section 8.)_

---

## 5. Load-bearing assumption (advisor #5) — surfaced for owner veto

This entire dormancy heal rests on **one premise**:

> At a non-zero VAT rate, the **frozen** `PricingService` / `ZReportService` compute per-line TVA on the **pre-discount** base. Therefore any order carrying a discretionary discount signs a **fiscally-incorrect NF525 Z** (the F1 defect).

The chosen V1 remedy is to **refuse discretionary discounts** (manual + coupon + loyalty) for everyone, including customers, until F1 is fixed under a lock-plan. **This is a real product reduction, not just a code gate** — customers cannot use coupons or redeem loyalty in V1. The owner may veto the *scope* (e.g., prefer to fix F1 properly now under a lock-plan, keeping discounts live) rather than the implementation. Round-4's `vat10` lens independently verifies the premise against the actual frozen code (section 8).

---

## 6. Non-fiscal go-live items (advisor #4) — owner decision needed

The fiscal blocker is closed at the order-creation chokepoint. Two **non-fiscal** completion items remain (they do **not** sign an incorrect Z):

1. **UI dead-end.** Customer-facing surfaces on this backend still render discount entry:
   - Web checkout `frontend/checkout/CouponComponent.vue` (in `CheckoutComponent.vue:219`, no gate) — route `/checkout`.
   - Kiosk `KioskLoyaltyComponent.vue` (sends `loyalty_code`) — route in `kioskRoutes.js:192`.
   A customer who uses them now hits a **generic 422** (the gate's `ValidationException` is re-wrapped by `myOrderStore`'s `catch(Exception)` at `:653` → field-error structure flattened). Per the S1 offers-display precedent, the consistent move is to **hide the display** when the flag is off — but whether the web frontend is even a V1-live Le Cayenne surface is a scope question for the owner.

2. **Pre-redeem points-stranding.** `/api/frontend/loyalty/redeem` (`Frontend/LoyaltyController`) is **ungated** and immediately deducts points into a pending redemption. With the order path now refusing discounts, a customer could pre-redeem (lose points) then be refused at order time — points stranded for ≤10 min (pending redemptions older than 10 min are ignored; assumed cron-refunded). **Fiscally harmless** (no order, no Z) but a V1 UX gap.

3. _(latent, noted)_ Kiosk/web loyalty lookup in `FrontendOrderService:835` filters `status=1` (legacy), not `Status::ACTIVE(5)` — so loyalty redeem silently won't apply for canonical status-5 customers. Moot under the V1 disable; flagged for the eventual F1 lock-plan.

---

## 7. Gate evidence (this session)

- **Full PHP suite:** `2748 passed, 0 failed` (1 risky / 2 incomplete / 29 skipped — all pre-existing).
- **Frozen-zone diff:** empty across all 13 §7 files (kiosk Vue, POS payment, pos-wizard, Fiscal services, BranchScope, Idempotency, PricingService, OrderStateMachine).
- **NF525 chain:** `SWEEP COMPLETE — CHAIN OK on every active branch (1 total)`.
- **Commit:** `784c84d17` — `app/Services/FrontendOrderService.php` (gate only) + 3 test files. No push.

---

## 8. Round-4 exhaustive-enumeration adversarial verdict

Workflow `wf_4a2d246e-d36` ran 18 agents (6 enumeration lenses → 11 adversarial verifiers → judge). The judge agent failed on a structured-output tooling error (oversized schema), but all 6 enumerations + 11 verifiers produced their structured findings — recovered from the journal. **The orchestrator synthesizes the verdict directly** (the convergence verdict is the orchestrator's responsibility; the insights report flagged delegated-judge payload failures as a known friction).

### 8.1 NEW P0 (fiscal) — CONFIRMED REAL + verified against code

> **Ungated SSOT coupon discount on `OrderService::myOrderStore` (web) + `tableOrderStore` (table).**
> With `pos.manual_discount_enabled=false` **and** `pricing.use_ssot_service=true` (both V1 defaults), a valid `coupon_id` makes `PricingService` compute a non-zero discount that is persisted to `orders.discount` **with no gate**. The `assertDiscretionaryDiscountAllowed` call exists only in the **non-SSOT `else`** block (web `:519`, table `:1514`), which is dead when SSOT is on. `posOrderStore` correctly gates inside its SSOT branch (`:813`) — that asymmetry is what masked the gap. A counter-paid such order reaches a signed NF525 Z where TVA sits on the pre-discount base (the F1 defect) at 10% VAT.

**Verification (orchestrator, against the actual code — not agent-trust):**
- Web: `OrderService.php:364` `if (use_ssot)` → `:379` `$calculatedDiscount = $res->discount` (coupon via `PricingRequest::forWeb`, `coupon_id` @ `:370`) → **no gate** → persisted `:528`. Gate `:519` is inside the `else` (`:383–521`). ✅ confirmed.
- Table: `OrderService.php:1337` `if (use_ssot)` → `:1353` `$calculatedDiscount = $tableSsotPricingResult->discount` (coupon @ `:1343`) → **no gate** → persisted `:1530`. Gate `:1514` inside the `else`. ✅ confirmed. (Manual discount is separately zeroed at `:1314`, so only the coupon path leaks.)
- My round-3 enumeration false-negatived this because `grep "->discount = "` (single space) missed `:528`'s multi-space alignment — **the adversarial pass caught what my own grep missed.**

**Reachability (severity nuance, honest):**
- **Web `OrderService::myOrderStore`** — **no controller/route caller** (the web endpoint `Frontend/OrderController:49` uses the *gated* `FrontendOrderService::myOrderStore`). So this path is **dormant/dead-code** in V1; gated defensively.
- **Table `OrderService::tableOrderStore`** — **wired** (`Table/OrderController:27`, unauthenticated QR `table-order` route). **Live fiscal hole if table ordering is enabled.**

**Fix (committed in section 8.3):** added `assertDiscretionaryDiscountAllowed((float) $calculatedDiscount)` inside both SSOT branches (web after `:379`, table after `:1353`), mirroring `posOrderStore:813`.

### 8.2 Other round-4 findings (all verified, NON-fiscal — confirm §6)

| # | Finding | real | fiscal blocker | disposition |
|---|---------|------|----------------|-------------|
| F1 premise | TVA computed on pre-discount base in frozen PricingService/ZReportService | ✅ true | no (gated) | **load-bearing assumption CONFIRMED** in frozen code (PricingService `:317`/`:353`; totalTax finalized before discount) |
| VAT-10 TTC | config correct + default; `TaxCalculator::lineTaxAmountFromTTC` correct | ✅ true | no | B1 sound |
| Pre-redeem stranding | `/api/frontend/loyalty/redeem` decrements points in its own txn; order refusal doesn't restore them | ✅ true | **no** (reserves points, no order/Z) | §6.2 — owner UX decision |
| UI dead-end | kiosk loyalty + web coupon entry reachable; raw 422 toast, retry/cash re-fail | ✅ true (P2) | no (gate precedes save) | §6.1 — owner UX decision |
| Table manual self-discount | refuted — neutralized to 0 at `:1314` | ❌ false | no | already safe |
| Frozen-zone violation | refuted — no NF525 file modified | ❌ false | no | frozen intact |
| status=1 vs ACTIVE(5) loyalty lookup | refuted as a blocker | ❌ false | no | latent, moot under disable |

### 8.3 Re-heal + re-converge — DONE (`59b13bdec`)

- **Fix:** `assertDiscretionaryDiscountAllowed((float) $calculatedDiscount)` added inside both SSOT branches — web (now `:387`, after `$calculatedDiscount = $res->discount`) and table (now `:1368`, after `$tableSsotPricingResult->discount`), mirroring `posOrderStore:821`.
- **Sentinel:** `TableOrderNegativeTotalTest::test_table_dining_order_refuses_server_validated_coupon_in_v1` — posts a valid coupon to the live QR endpoint with the flag OFF → **422 + 0 persisted order** (transaction rollback). Passed in isolation → proves the table path was live and is now blocked. (Web `myOrderStore` is dead-code with no route, so no HTTP sentinel is possible; it keeps the defensive gate + comment.)
- **No regressions:** the gates broke 0 existing tests (no test posts a web/table coupon order expecting success with SSOT on).
- **Gates:** full PHP suite **2749/0**, frozen-zone diff **0**, NF525 **CHAIN OK**.

### 8.4 Round-5 convergence confirmation — `{converged:true, realRemaining:[]}`

Workflow `wf_99b7c4db-0cf` — 3 diverse adversarial bypass-hunters (control-flow / data-flow / exploit-construction), JS synthesis (no LLM judge). **All 3 returned `converged:true` with zero ungated paths.** Verified:
- All **8** discount-persist gate sites present + correct: web SSOT `:387`, web else `:527`, POS SSOT `:809`/`:821`, POS else `:996`/`:1000`, table SSOT `:1368` (+ manual zeroed at entry `:1322`), table else `:1529`; `FrontendOrderService:502`; `PosRedemptionService:72`.
- `PricingService` confirmed **side-effect-free** (no DB writes) → the gate-after-`calculateOrder` is atomic; a throw rolls the order row back (proven by `FrontendOrder::count()===0`).
- Codebase-wide sweep: the **only** order-row discount writes are the gated creation closures + `PosRedemptionService`. No admin/OSS post-create endpoint mutates `discount`. Refund mirror (`RefundWithCounterEntryService`) sets order discount=0 and only negates a pre-existing item discount.
- Exploit-construction angle found **no** working request/sequence that persists a discounted order with the flag OFF across web / QR table / kiosk / POS / admin loyalty.

**→ Fiscal convergence confirmed. Every discount-persisting order path is gated; no order carrying a discretionary discount can sign a fiscally-incorrect NF525 Z in V1.**

---

## 9. Owner decisions requested

1. **Discount-dormancy scope (§5):** keep discretionary discounts (coupon + loyalty) disabled for V1, OR fix F1 properly now under a lock-plan and keep them live?
2. **UI dead-end (§6.1):** hide the kiosk-loyalty + web-checkout-coupon entry points now (UI work + visual test), OR is the web frontend not a V1-live surface (document + proceed)?
3. **Pre-redeem (§6.2):** gate `/api/frontend/loyalty/redeem` too (refuse pre-redeem when discounts off), OR accept the bounded ≤10-min stranding?
