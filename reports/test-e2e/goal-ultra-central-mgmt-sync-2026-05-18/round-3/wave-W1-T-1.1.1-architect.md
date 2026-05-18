# T-1.1.1 PricingService Callsite Audit — ARCHITECT Report — Round 3

## Verdict: NO-GO on literal sentinel — GO-CONDITIONAL on production correctness

Sentinel ("0 occurrences of `* total *= *` / `* unit_price *= *` outside `PricingService`") **fails literally**. ~17 lines of line-math arithmetic live in `OrderService.php` and `FrontendOrderService.php` under the `config('pricing.use_ssot_service', true)` legacy branch (5 callsites). In production `PRICING_USE_SSOT` defaults `true` (`config/pricing.php:8`), so the runtime invariant holds — but a single env line reactivates ~400 lines of duplicated math with no parity test. The flag was a 2025-Q4 migration ramp; it is now structural debt. PricingService itself (`app/Services/Pricing/PricingService.php:36-369`) is canonical, deterministic, side-effect-free. The three findings defend the SSOT boundary.

Cross-reference: Round 1 `wave-W2-T-1.2.1-architect.md:12` named "PricingService recompute mismatch" as a trigger for the fiscal-sequence leak. This audit identifies where the mismatch originates: legacy fallback branches under the flag.

## Top findings

### [P1] Dual code paths under `PRICING_USE_SSOT` flag — sentinel literally unsatisfiable

trigger:
  load_mode: "Five callsites in `OrderService.php:329` (web), `:645` (POS), `:1119` (table) and `FrontendOrderService.php:277` (kiosk) gate SSOT on `config('pricing.use_ssot_service', true)`. Matching `else` branches reimplement line-math at `OrderService.php:423,459,779,814,1235,1270` (`$dbVar->price * $varQuantity` and `($itemPrice + $variationTotal + $extraTotal) * $verifiedQuantity`). Legacy writes `subtotal/total_tax/discount/total` via callback at `OrderService.php:487-497,866-872,1311-1319` and `FrontendOrderService.php:493-500`. Flag flipped via `.env`, no CI assertion against drift."
  failure_mode: "Three silent divergences: (a) `roundOrderTotalTax`/`roundSubtotal` per-request flags respected by PricingService, hard-coded `round(…,2)` on partial intermediates in legacy → penny drift on borderline carts. (b) `PricingService::assertComposerStepConstraints:110` has NO legacy equivalent — disabling SSOT silently accepts invalid wizard carts at full price. (c) Coupon discount applied against rounded `subtotalForDiscount` (PricingService:326-329) vs unrounded `$realSubtotal` (legacy `couponService->calculateDiscountAmount`) — penny drift on boundary."

v2_saas_impact:
  blocks: "Multi-tenant cannot guarantee NF525 invariants under tenant-misconfigured env. SaaS provisioning has no policy enforcement on this env var."
  enables: "Deleting legacy branches makes PricingService the sole path, shrinks audit surface ~400 lines."

cost_of_delay_if_v1_ships:
  customer: "Zero observable under current default. Drift = 1-2 cents on borderline carts if flag flipped."
  fiscal: "Under flag=false, composer-step guard absent + snapshot rounding differs → DGFiP recompute on flag=false deploy would find inconsistent `composition_snapshot` vs reproducible recompute."
  business: "Legacy branch encourages 'just one more inline tweak' (TTC mode + allergen snapshot already duplicated twice). Code maintenance debt compounds."

recommendation:
  scope: "**Phase 1 V1.0.1:** CI sentinel asserting `pricing.use_ssot_service=true` in prod + parity regression test running both branches on a 30-cart fixture asserting bit-exact `subtotal/discount/totalTax/total` (3h). **Phase 2 V1.0.2:** Delete `else` branches, ~400 LOC removed (1d). **Phase 3 V1.1.0:** Delete flag entirely (30min)."
  rollback: "Phase 1 read-only. Phase 2 reverts via `git revert`. Phase 3 trivial."
  owner_gate: "N for Phase 1. Y for Phase 2 (POS-critical files; LOCK or owner sign-off)."

### [P1] `app/Services/Kiosk/PricingPreviewService.php:71-97` — kiosk_promo preview recomputes total OUTSIDE PricingService

trigger:
  load_mode: "Customer enters `kiosk_promo_code` → `POST /api/frontend/pricing/preview` → `PricingPreviewService::preview` branch at line 67-99. Calls PricingService for gross `$draft`, then locally computes `$kioskDiscount = KioskPromo::computeDiscount(...)`, then **manually reconstructs** total at line 96: `total = subtotal + totalTax - kioskDiscount`. Kiosk promo discount **never enters PricingService** as a first-class input — `PricingRequest::forKiosk` has no `kioskPromoCode` field."
  failure_mode: "Preview→commit drift. Customer sees X on wizard. `POST /api/frontend/order` → `FrontendOrderService:277` (SSOT) — but `kiosk_promo_code` is never read on commit. Order commits gross total → `OrderQuoteService::sealForCommit:120-122` throws 409 ('Order total does not match sealed quote total'). UX = 'cart changed at payment'. Worse: quote built with kiosk_promo branch (`OrderQuoteService:416 kiosk_promo_code` echoed but not applied to `$pricing`) and order without → systematic 409 on every kiosk_promo cart."

v2_saas_impact:
  blocks: "Kiosk conversion funnel A/B testing unreliable — payment-screen drop-off attributed to UX is actually arithmetic mismatch."
  enables: "Promoting `kioskPromoCode` to first-class `PricingRequest` field unifies SSOT + unlocks shared promo-stacking semantics with coupons."

cost_of_delay_if_v1_ships:
  customer: "User-visible 409 retry on every valid kiosk promo entry. Low V1 cost (Le Cayenne self-serve promos ≈ 0.1% kiosk traffic)."
  fiscal: "None — commit happens at gross total, audit chain signs gross. Promo discount is lost, not invalid."
  business: "Lost promo revenue. Owner unaware unless `kiosk_promos.usage_count` reconciled against actual order totals."

recommendation:
  scope: "Extend `PricingRequest::forKiosk` with `?string $kioskPromoCode = null`. In `PricingService::calculateOrder` after line 332-345 coupon branch, add priority-1 `kiosk_promo` branch delegating to `DiscountCalculator::kioskPromoDiscount` (new method, mirrors `KioskPromo::computeDiscount`). Remove `PricingPreviewService:71-97` local recompute. ~50 LOC + unit test per priority case. ~3h."
  rollback: "Additive — keep local branch behind `config('pricing.kiosk_promo_via_ssot', true)` for one cycle, then delete."
  owner_gate: "N — `PricingService` is CLAUDE.md §7 frozen, but additive parameter with default = existing behaviour. Regression test for 12 existing `tests/Feature/Kiosk/PricingPreview*` tests required."

### [P2] `app/Services/Order/RefundWithCounterEntryService.php:88-142` — counter-entry bypasses PricingService by design but inherits parent unverified

trigger:
  load_mode: "Post-Z refund → `RefundWithCounterEntryService::execute(Order $parent)`. Mirror created at line 95-112 with negated `$parent->subtotal/total_tax/total`. OrderItems duplicated with negated `total_price/tax_amount` from parent. PricingService **never called** — by NF525 design (parent immutable post-Z, mirror must be exact negation for chain integrity)."
  failure_mode: "Correctness inheritance — if parent was created via legacy flag=false branch with buggy rounding, refund **compounds** the bug. No read-back attestation that parent's stored `total` matches what PricingService would recompute from frozen `composition_snapshot`. Across thousands of orders, sub-cent drift accumulates; Z aggregates can disagree with sum-of-items by 1-3 cents per Z window."

v2_saas_impact:
  blocks: "DGFiP can request bit-exact recompute from `composition_snapshot`. Today we cannot prove parity because no job verifies it."
  enables: "Read-only `PricingReconciliationJob` unlocks self-attestation and makes refund inheritance provably safe."

cost_of_delay_if_v1_ships:
  customer: "None — receipts are what customers paid."
  fiscal: "Sub-cent drift silent. `ZReportService::aggregate` sums `orders.total/total_tax` — does NOT recompute from snapshot. Invisible unless external auditor recomputes."
  business: "Le Cayenne: low cost — drift bounded by legacy branch usage ≈ 0 in prod (flag default true)."

recommendation:
  scope: "Add `php artisan fiscal:reconcile-pricing --date=YYYY-MM-DD` console command. Read-only: iterates paid orders for Z window, rebuilds `PricingRequest` from `order_items.composition_snapshot`, runs `PricingService::calculateOrder(orderId=0,...)`, asserts `recomputed.total === stored.total` within ±0.005. Mismatches → new `pricing_reconciliation_drift` table + `Log::channel('fiscal')->critical`. NO mutation. ~150 LOC + integration test. ~4h."
  rollback: "Pure additive read-only command + new table. Drop the table + remove command = zero impact."
  owner_gate: "N — no frozen-zone touch. PricingService called in preview mode (`orderId=0`) does not write."

## Coverage map

**8 PricingService callsites enumerated** (all in `app/`):
1. `app/Services/OrderService.php:329` — web order
2. `app/Services/OrderService.php:645` — POS order
3. `app/Services/OrderService.php:1119` — table order (DINE_IN)
4. `app/Services/FrontendOrderService.php:277` — kiosk paid + counter-deferred
5. `app/Services/Order/OrderQuoteService.php:209` — kiosk quote
6. `app/Services/Order/OrderQuoteService.php:224` — POS quote
7. `app/Services/Kiosk/PricingPreviewService.php:71` — wizard preview gross draft (kiosk_promo branch)
8. `app/Services/Kiosk/PricingPreviewService.php:107` — wizard preview coupon branch

**Bypass paths investigated:**
- ✓ Clean: `PosOrderController.php:208,240,253,271,283` (read-only reorder projection), `OrderDetailsResource.php:133` (display), `ItemAddonResource.php:45` (display), `OrderRequest.php:275` (`subtotal` input read but never persisted — server recomputes per `:151,:258` doc), `SplitPaymentService.php` (validates tranches, not totals), `app/Http/Controllers/Admin/*` (`grep "->total\s*=" = 0 matches` — no admin endpoint overrides totals).
- ✗ **Legacy `PRICING_USE_SSOT=false` branches**: `OrderService.php:423,459,779,814,1235,1270` and `FrontendOrderService.php:410,445` — Finding #1.
- ✗ **Total reconstruction in callbacks**: `OrderService.php:487-497,866-872,1311-1319` and `FrontendOrderService.php:493-500`. **In SSOT path mathematically equivalent to `PricingService:351-355`** (verified line-by-line). Not a finding — flagged as equivalent.
- ✗ **RefundWithCounterEntryService**: by-design NF525 negation — Finding #3.

**PricingService determinism:**
- ✓ Final class, readonly deps, no instance state. Idempotent.
- ✓ Read-only inside `calculateOrder`: `Item/ItemVariation/ItemExtra/ItemAddon/Tax/ItemAttribute` selects. No writes.
- ✓ Throws on invalid input (422). No silent fallback.
- ✓ Multi-thread safe (no static state; transactions managed by callers).

**Files Read**: `PricingService.php` (1-130,320-370), `PricingResult.php` (full), `OrderService.php` (320-498,630-880,1110-1330), `FrontendOrderService.php` (260-525,760-845), `PricingPreviewService.php` (full), `OrderQuoteService.php` (full), `RefundWithCounterEntryService.php` (full), `PosOrderController.php` (180-300), `config/pricing.php` (full), R1 `wave-W2-T-1.2.1-architect.md` (full).

## Open questions for cross-agent synthesis

**For DBA agent (R3):**
- `composition_snapshot` JSON column indexable for reconciliation job? Without GIN/JSON index, `fiscal:reconcile-pricing` over 6y × N branches is unacceptably slow.
- Do legacy manual `round(…,2)` placements yield identical stored decimals as PricingService's flag-driven rounding? Dump 100 prod rows from flag=true + replay flag=false on identical inputs.

**For Security agent (R3):**
- Kiosk_promo bypass (Finding #2) — confirmed `OrderRequest` does not whitelist `kiosk_promo_code` and `FrontendOrderService:277` path ignores it entirely. Bypass = missed discount (revenue gain for owner), not privilege escalation. Confirm no other code path applies a post-PricingService discount from this field.
- `PRICING_USE_SSOT=false` deploy: audit_log row should include `pricing_path=ssot|legacy` to forensically reconstruct.

**For Fiscal agent (R3):**
- Cross-ref R1 T-1.2.1: removing legacy branches (Finding #1 Phase 2) — does it close R1 P1 'PricingService recompute mismatch' entirely, or only narrow it?
- Refund mirror inheritance (Finding #3) — DGFiP-required parity attestation or defensive belt-and-suspenders? If required by law, reclassify P1.

**For Tester agent (R3):**
- `tests/Feature/Pricing/*` — any test pins flag=false + asserts parity with =true? Required before Finding #1 Phase 2 deletion.
- Quote/order loyalty-discount math duplicated: `OrderQuoteService::withKioskLoyaltyDiscount:238-286` vs `FrontendOrderService::applyKioskLoyaltyDiscount:769-845`. Two impls of same semantic; HMAC seal catches mismatch (409) but no positive parity test. Add `KioskLoyaltyQuoteVsCommitParityTest`.
- `PricingPreviewService::projectLines:132-145` emits `unit_price` in projection (projection, not write). Sentinel regex must exclude resource/projection layers or false-positives.

**For SRE agent (R3):**
- `PRICING_USE_SSOT` is single-knob risk. Add boot-time guard (mirror `POS_SIMULATION_HARDWARE` pattern per `project_v1_cloud_prep_2026-05-17` memory): refuse boot if `APP_ENV=production && PRICING_USE_SSOT=false`.
- No `Log::channel('pricing')` exists; PricingService logs nothing. Add `Log::info('pricing.calculate', duration+cartSize)` for prod p95 — required V2 SaaS SLA observability.
