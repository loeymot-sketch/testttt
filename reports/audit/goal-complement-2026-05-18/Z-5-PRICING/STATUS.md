# Z-5 Pricing SSOT — STATUS

**Zone**: Z-5 Pricing SSOT (AUDIT-ONLY, FROZEN strict per CLAUDE.md §7)
**Wave**: goal-complement-2026-05-18 / round-1
**Audit date**: 2026-05-18
**HEAD**: 575a046527ed2d93fecbba7b1352aa1c607cdd03
**Branch**: pr/mobile-app-real-e2e-heal-2026-05-18
**Master sub-agent**: autonomous (single message internal track 1→11)

---

## Verdict

**AUDIT-ONLY PASS** — Wave 1 NF525 fiscal audit verdict (Z-5 invariants all PASS) is CONFIRMED.

- P0 in frozen file (`app/Services/Pricing/PricingService.php`): **0**
- P1 in frozen file: **0**
- P2: **0**
- P3 V1.1 backlog items: **2** (documented, deferred-heal — non-blocking for V1)
- Owner gate G3 escalation: **NOT triggered** (no P0 in frozen file)
- LOCK_PRICING_SSOT plan: **NOT required**
- Tech-test sentinels: **109/109 PASS** across 16 test classes (Pricing|PosPricing|PosKiosk|SubmitRevalidates filter) + **10/10 PASS** across 4 classes for T-6.3.3 kiosk SSOT proof family (F001KioskFiscal|KioskQuoteIntegrity|KioskFullFlowE2E|KioskIdsOnlyPayload filter)

---

## Specialists track (Step 2 fan-out, single-message)

All 4 specialist reports persisted with Read-verified file:line evidence:

| Role | Report | Verdict | Findings (severity) |
|---|---|---|---|
| Architect | `reports/audit/goal-complement-2026-05-18/round-1/Z-5-PRICING/architect.json` | GREEN | 7 INFO + 1 P3 (KioskMenuRatio extract) |
| Security | `reports/audit/goal-complement-2026-05-18/round-1/Z-5-PRICING/security.json` | GREEN | 11 PASS attestations + 1 P3 (DB trigger missing) |
| DBA | `reports/audit/goal-complement-2026-05-18/round-1/Z-5-PRICING/dba.json` | GREEN | 5 INFO + 1 P3 (DB-level BEFORE UPDATE trigger) |
| RED-team | `reports/audit/goal-complement-2026-05-18/round-1/Z-5-PRICING/red.json` | GREEN | 15 attack vectors enumerated, all DEFEATED |

---

## SSOT invariants attested

| Invariant | Status | Evidence |
|---|---|---|
| PR01 — Backend re-reads `Item::price` from DB; frontend `price` field discarded | PASS | `Zone5PricingSsotConvergenceSentinelTest::test_pr01_*` |
| PR02 — PricingService overwrites client-forged `total_price` and `subtotal` | PASS | `PosPricingSsotProofTest` + `Zone5...::test_pr02_*` |
| PR03 — `composition_snapshot` has ZERO UPDATE/save/assign call sites in `app/` | PASS | `Zone5...::test_pr03_*` (grep-asserted) |
| PR03-bis — Schema: column exists, model casts as array | PASS | `Zone5...::test_pr03bis_*` |
| PR04 — Snapshot frozen across mid-day `items.price` repricing | PASS | `Zone5...::test_pr04_*` |
| PR07 — Stripe round-before-cast: `€9.99 → 999 cents` | PASS | `Zone5...::test_pr07_*` |
| `pricing.use_ssot_service` flag production stable = `true` | PASS | `PricingSsotFlagProductionStableSentinelTest` (5 tests pin config + 2 env templates + 2 CI workflows) |
| No `env()` bypass in `app/Services/Pricing/` namespace | PASS | `grep -rn 'env(' app/Services/Pricing/` → ZERO matches |
| TTC tax-inclusive mode default = `true` | PASS | `tests/Feature/Pricing/TaxInclusivePricesTest` |
| Cross-item ID injection rejected (variation/extra/addon ↔ item parentage) | PASS | `CrossItemGuardTest` + `PricingServiceTest::cross_item_*` |
| Manual discount scoped to `pos|table` (kiosk/web factories hardcode 0.0) | PASS | `PricingServiceTest::manual_discount_*` |
| Per-surface option visibility (`isVisibleOn($surface)`) | PASS | enforced in `assertOptionsOrderable` |

---

## Write-sites reconciliation (task §6 Sub 6.2 "5 sites")

5 builder-driven INSERT sites for fresh snapshots:

1. `app/Services/Pricing/PricingService.php:291` (SSOT path — primary)
2. `app/Services/OrderService.php:466` (legacy fallback #1, gated by `!config('pricing.use_ssot_service')`)
3. `app/Services/OrderService.php:821` (legacy fallback #2)
4. `app/Services/OrderService.php:1277` (legacy fallback #3)
5. `app/Services/FrontendOrderService.php:441` (legacy frontend path)

Plus 1 copy-forward INSERT site (does NOT recompute, preserves immutability):

6. `app/Services/Order/RefundWithCounterEntryService.php:136` — refund mirror order_items row clones parent's already-frozen snapshot verbatim. NF525-compliant (6-year reprint reconciles).

Sentinel `Zone5...::test_pr03_*` grep-asserts ZERO UPDATE/save/assign call sites across all 6 sites + entire `app/`.

---

## Step 6 — Tech test attestation

```
$ php artisan test --filter "Pricing|PosPricing|PosKiosk|SubmitRevalidates"

PASS Tests\Unit\Services\Pricing\DiscountCalculatorTest
PASS Tests\Unit\Services\Pricing\MenuRoleAdjustedAddonPriceTest
PASS Tests\Unit\Services\Pricing\TaxCalculatorTest
PASS Tests\Feature\Menu\PosKioskProjectionParityTest
PASS Tests\Feature\Order\SubmitRevalidatesChoiceAvailabilityThroughPricingTest
PASS Tests\Feature\Orders\CrossItemGuardTest
PASS Tests\Feature\PosKioskPricingParityTest               (4 tests — POS↔Kiosk parity cases A/B/C/D)
PASS Tests\Feature\PosPricingSsotProofTest
PASS Tests\Feature\Pricing\TaxInclusivePricesTest
PASS Tests\Feature\PricingIntegrityTest
PASS Tests\Feature\Sentinels\PosReorderHistoricalPricingSentinelTest
PASS Tests\Feature\Sentinels\PricingSsotFlagProductionStableSentinelTest  (5 tests — config + 2 env + 2 CI workflows)
PASS Tests\Feature\Sentinels\Zone5PricingSsotConvergenceSentinelTest      (6 tests — PR01/PR02/PR03/PR03bis/PR04/PR07)
PASS Tests\Feature\Services\Pricing\ComposerStepConstraintTest
PASS Tests\Feature\Services\Pricing\PricingServiceMultiQtyTest
PASS Tests\Feature\Services\Pricing\PricingServiceTest

Tests:  109 passed
Time:   13.32s
```

All 9 anchor tests listed in `plans/GOAL_PRODUCTION_READINESS_COMPLEMENT_2026-05-18.md §6` are present and GREEN. The `PricingSsotFlagProductionStableSentinelTest` re-verified PASS (no env bypass introduced).

### T-6.3.3 — Kiosk SSOT proof family (companion attestation)

The task §6 Sub 6.3 T-6.3.3 references `KioskFiscalAtCreationGuardTest.php` (verify). Actual filenames on disk are not that exact name but the equivalent guard coverage is provided by:

```
$ php artisan test --filter "F001KioskFiscal|KioskQuoteIntegrity|KioskFullFlowE2E|KioskIdsOnlyPayload"

PASS Tests\Feature\KioskQuoteIntegrityTest                                  (2 tests — quote consumed + items-change rejection)
PASS Tests\Feature\OrderPipeline\KioskFullFlowE2ETest                       (1 test — kiosk → KDS E2E with variations + extras + allergens)
PASS Tests\Feature\Orders\KioskIdsOnlyPayloadTest                           (1 test — ids-only payload accepted, total recomputed server-side)
PASS Tests\Feature\Sentinels\F001KioskFiscalSequenceInvariantSentinelTest   (6 tests — Path A counter-deferred + Path B PAID-at-creation + Z aggregate filter)

Tests:  10 passed
Time:   1.60s
```

`KioskQuoteIntegrityTest` is the kiosk-side equivalent of `PosPricingSsotProofTest` — client-supplied totals ignored, server quote consumed atomically. `KioskIdsOnlyPayloadTest` enforces the ids-only contract (item_id + qty + option_ids only). `F001KioskFiscalSequenceInvariantSentinelTest` pins kiosk fiscal allocation at creation (PAID path) + deferred-counter (cash path). All 10 GREEN — T-6.3.3 covered.

---

## RED team adversarial summary (Step 5)

15 attack vectors enumerated and **all DEFEATED** (see `red.json`):

V1 frontend price forgery / V2 total_price forgery / V3 cross-item ID injection / V4 hidden-surface option leak / V5 manual discount injection on kiosk-web / V6 coupon abuse / V7 snapshot tampering / V8 env bypass / V9 Stripe cent rounding / V10 mid-cart profile swap / V11 menu-formula ratio drift / V12 multi-currency (out-of-scope V1) / V13 branch-scoped pricing leak / V14 discount overflow / V15 TTC/HT double-count.

No P0/P1 surface in the frozen `PricingService.php`.

---

## Step 7 — E2E (not applicable)

Z-5 is an invariant zone (no direct UI surface). Skipped per task contract.

---

## Step 8 — Validation gate (Step 11)

| Gate | Status |
|---|---|
| `findings.json` complete and persisted | PASS (`deferred-heal/Z-5-PRICING/findings.json`) |
| All 4 specialist JSONs persisted with Read-verified file:line | PASS |
| All sentinels GREEN exact count 109/109 | PASS |
| `PricingSsotFlagProductionStableSentinelTest` re-verified PASS | PASS |
| RED dispute closed (no vector left open) | PASS |
| No surprise P0 in frozen `PricingService.php` | PASS |
| Frozen-zone write count = 0 | PASS (`git diff --stat app/Services/Pricing/PricingService.php` = unchanged) |
| Wave 1 verdict confirmation (not invention) | PASS |
| `STUCK_Z-5_*.md` triggered for owner gate G3 | NOT TRIGGERED (no P0 in frozen) |

---

## V1.1 backlog (deferred-heal P3 items)

| ID | Title | Severity | File ref |
|---|---|---|---|
| Z5-P3-01 | DB-level BEFORE UPDATE trigger on `order_items.composition_snapshot` missing (analogous to `audit_logs` + `z_reports` triggers per CLAUDE.md §8) | P3 | new migration `database/migrations/2026_xx_xx_add_composition_snapshot_immutability_trigger.php` |
| Z5-P3-02 | `menuRoleAdjustedAddonPrice` duplicated between `PricingService.php:793-813` and `CompositionSnapshotBuilder.php:171-191` (documented as intentional for layer purity — extract `KioskMenuRatio` value object) | P3 | refactor target |

Both items are non-blocking for V1 ship. Both acknowledged in Wave 1 NF525 audit + Zone5 sentinel docblock.

---

## Closing summary

Z-5 Pricing SSOT is the most rigorously sentinel-pinned subsystem in V1. The FROZEN `PricingService.php` is untouched on this branch and untouched in the audit. The 5+1 INSERT sites for `composition_snapshot` were verified by direct Read of file:line in `app/Services/Pricing/PricingService.php:291`, `app/Services/OrderService.php:{466,821,1277}`, `app/Services/FrontendOrderService.php:441`, and `app/Services/Order/RefundWithCounterEntryService.php:136`. The Zone5 convergence sentinel (added 2026-05-18) pins PR01/PR02/PR03/PR03-bis/PR04/PR07 in CI permanently.

**Recommendation**: proceed to global convergence (Phase 2). No owner gate. No LOCK plan.
