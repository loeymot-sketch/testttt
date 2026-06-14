# MASSIVE TEST-E2E 2.0 — CONVERGENCE REPORT (Round 2 — heal wave)

**Date:** 2026-06-14 · **Tree:** `release/v1-integration-2026-06-12` (spine) · base `7247e2533` → heals.
**Harness:** `foodking_2dot0` @ :8780 (ENV=2dot0, soketi+queue+redis UP). Op `foodking` untouched.

## Scope
Round-1 found 0 P0/P1 + 18 P2/P3. Round-2 heals the top non-frozen P2 (food-safety + correctness), re-runs all gates, and adversarially re-verifies (2nd cycle for convergence).

## HEALS (5 total — #3 from R1, #9/#15/#12/#1 this round) — all TDD red→green, frozen=0

| # | Severity | System | Fix | Test (red→green) | Commit |
|---|---|---|---|---|---|
| #3 | P2 security | BORNE auth | kiosk-login constant-time bcrypt on not-found (timing oracle) | `KioskLoginEnumerationTest::unknown_username_still_pays_bcrypt_cost` 5/5 | `656888c7f` |
| #9 | P2 **food-safety** | KDS | allergen hash defensive double-decode (double-encoded snapshot no longer collapses to []) | `KdsAllergenAggregationSplitTest::double_encoded...merges` 6/6 | `41d1e8ba7` |
| #15 | P2 correctness | CAISSE/POS | barcode lookup blocks item ruptured at operator branch (404), server-side (pos-wizard frozen) | `PosMenuRuntimeAccessTest::cannot_lookup_barcode_of_item_ruptured` 7/7 | `0cc50af95` |
| #12 | P2 numbers | CENTRAL | dashboard "Top du jour" nets refunds (realizedRevenue) | `DashboardRevenueNettingSentinelTest::top_items_of_day_nets_refunded` 3/3 | `c20cf33b7` |
| #1 | P2 **food-safety** | BORNE | allergen collision alert case-insensitive (both sides lowercased) | `ksAllergenBadgeCollision` 3/3 | `8ebcb6c7a` |

## GATES (re-run fresh, Round-2)
- **Vitest full: 371 files, 2511 passed | 3 skipped, 0 failed** (incl. 3 new allergen specs; no regression from KsAllergenBadge change).
- **PHPUnit (heal areas, fresh): Kds 45/0 · KDS 45/0 · Dashboard 33/0 · Pos 92/0 · Fiscal 218/0 (3 skip) · Kiosk-login 5/0** — all GREEN.
- **Frozen-zone diff: 0 lines** across all 5 heals (pos-wizard / Pricing / Fiscal / OrderStateMachine / BranchScope / Kiosk{Wizard,App,Upsell} / PaymentComponent).
- **NF525 chain: CHAIN OK** (heals touch no fiscal service; chain re-verified).
- **Frontend rebuild: `npm run production` webpack compiled successfully** (app.js + pos-app.js fresh, carry the #1 fix). **Visual smoke: kiosk idle renders clean** (logo, "Bienvenue !", Cayenne orange, FR, no raw labels, 0 console errors) — `heal/kiosk-post-rebuild.jpeg`.

## ADVERSARIAL RE-VERIFY (Round-2 2nd cycle — workflow `wf_bbbca518-e9d`, 9 agents) — `round-2/W4-heal-reverify.json`

| Heal | Verdict | Residual | Note |
|---|---|---|---|
| #3 timing oracle | **CORRECT_COMPLETE** | NONE | parity holds, no residual oracle, placeholder valid cost-12, throttle-backed |
| #1 allergen case-sensitivity | **CORRECT_COMPLETE** | NONE | both sides lowercased, alert fires |
| #12 dashboard refund-netting | **CORRECT_COMPLETE** | P3 | #12 itself correct; siblings #13/#14 still un-netted (deferred) |
| #9 allergen double-encode (hash) | **INCOMPLETE→REFUTED** | — | agent claimed display leg blanks; **empirical test REFUTES it** — resource cast(1)+safeJsonDecodeArray(2) unwraps double-encode end-to-end. Sentinel `8b7f40191` locks it. Double-gate caught a FALSE adversarial claim. |
| #15 POS barcode rupture | **INCOMPLETE (escalated P1)** | **P1** | barcode fix robust + correct, BUT a ruptured SKU is **still sellable** via `/item/details/{id}` (no branch overlay) + order-create (`PricingService`/`OrderService` never check `ItemBranchAvailability`). Point-fix ≠ systemic. → **OWNER GATE** |

**Re-sweep for NEW P0/P1: 0.** 2 new minor: P3 (`normalizeAllergensForHash` array-of-objects edge — non-occurring, pre-existing risk) + P2 (#15 `forcePosRuntimeBranchScope` `abort(403)` swallowed→422 by the method's `catch(Exception)` — POS users always have branch≥1, edge only; documented).

### 🚪 ESCALATION — #15 systemic P1 (OWNER GATE: G-RUPTURE)
A branch-ruptured item (`ItemBranchAvailability.is_available=0`) can still be **ordered and billed** via the order-create path because the validation SSOT does not check per-branch rupture. The barcode entry is now closed (heal #15), but `/item/details/{id}` and POST `/api/admin/pos` are not. The correct enforcement point is order-create validation — which is **owner-"intouchable" core flow** + frozen `PricingService`. It is also a **product decision**: hard-block vs allow override-sell (rupture data can be stale; owner may want to sell remaining stock). **Not auto-healed.** Recommendation: add a branch-rupture check in `OrderService` (non-frozen) BEFORE pricing, configurable (`pos.block_ruptured_items`, default-warn or default-block per owner). WHO=owner, WHAT=decision + LOCK if PricingService touched, WHERE=`plans/LOCK_*` / commit.

## REMAINING BACKLOG (13 P2/P3 — deferred / gated)
**P2:** OSS per-listener isolation (#8), post-commit Throwable re-wrap bump→422 (#11), WCAG orange contrast (#4 = owner brand gate), AuditLogService env() (#16 = FROZEN, UNI-03 cloud-gate).
**P3 (10):** allergen vocab poisson(s), cash received=null guard, cast inconsistency, parked-order UNIQUE branch_id (V2), allergen non-string drop, hourly-chart mirror, sales-report discount mirror, dead broadcast lane, DispatchDomainEventsJob.failed() connection log. All V2/cosmetic/single-box-safe.

## VERDICT (Round-2): **HEAL — strong progress, NOT yet full-converged (honest).**
- **5 heals landed**: #3/#1/#12/#9-hash CORRECT_COMPLETE (adversary-verified), #9-display REFUTED-as-already-correct (sentinel added). 2 food-safety + 1 security + 1 numbers + 1 correctness.
- **All deterministic gates GREEN**: Vitest 2511/0, PHPUnit heal-areas (Kds/Dashboard/Pos 92/Fiscal 218) green, frozen 0, NF525 chain OK, kiosk rebuilt + visual clean.
- **1 NEW P1 surfaced & ESCALATED** (not a regression — a deeper pre-existing gap my barcode point-fix exposed): **G-RUPTURE** — ruptured item sellable via order-create (core/frozen → owner gate).
- **Convergence NOT claimed**: the 2nd adversarial cycle changed the finding set (surfaced G-RUPTURE P1) → by the flake/convergence rule this is NOT "2 identical green cycles". Round-3 = owner decision on G-RUPTURE, then re-converge.
- **Owner gates**: **G-RUPTURE (new P1)**, G-WCAG (#4), G-FROZEN (#16 AuditLogService env), G-PUSH, G-OVH.
- Discipline honored: no frozen/core-flow touched without gate; adversary caught 1 incomplete heal (#15) + 1 false claim (#9-display) — double-gate working.
