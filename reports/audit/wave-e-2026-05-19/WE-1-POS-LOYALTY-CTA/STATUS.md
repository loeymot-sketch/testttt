# Wave E-1 — POS Cashier Loyalty CTA Main-Page Placement — STATUS

**Mission:** Add a 2nd loyalty redeem CTA on the POS main page so the cashier can trigger redemption without navigating to PosOrderShow. Owner direction 2026-05-19: V1 has `pos.dine_in_enabled=false` so the floorplan slot is freed up — surface "Add Loyalty" there. Loyalty applies during this order; on completion → reset.

**Branch:** `heal/cms-pr1-quickwins-2026-05-18`
**Wave:** E-1 (parallel with E-2 web-demo-badges, E-3 spinboost-plan, E-4 final-convergence)
**Status:** GREEN — ready to commit + ship.

---

## What Shipped

### Files changed (3 + 1 modified)

1. **`resources/js/helpers/posLoyaltyMainCta.js`** (NEW, +95 lines)
   - Pure helper module: `extractLoyaltyOrderInfo(order)` + `canShowLoyaltyMainCta({dineInEnabled, currentOrder, paidStatus, terminalStatuses})`.
   - Mirrors the `tests/js/posDineInFlag.spec.js` extraction pattern so gating logic is independently unit-testable without mounting the 4 000-line PosComponent.

2. **`tests/js/posLoyaltyMainPageCta.spec.js`** (NEW, +175 lines)
   - **13 Vitest tests covering**:
     - `extractLoyaltyOrderInfo` payload parsing (null guards, NaN guards, string→number coercion).
     - `canShowLoyaltyMainCta` state matrix: dine-in flag, no order, PAID order, all 4 terminal statuses, V1-default-active, missing id defensive, missing terminalStatuses fail-safe, custom paidStatus enum.
     - Reset semantics contract documented inline.
   - **All 13 GREEN.**

3. **`tests/e2e/wave-E-1-pos-loyalty-main-cta-capture.spec.js`** (NEW, +60 lines)
   - Playwright spec: logs in as POS operator, verifies CTA renders, is disabled with no active order, floorplan link is NOT rendered (mutual exclusivity), modal does not open on disabled click.
   - **Captures `capture-01-pos-main-loyalty-cta-disabled.png` for visual evidence.**
   - **GREEN — 1/1 PASS in 8.5s.**

4. **`resources/js/components/admin/pos/PosComponent.vue`** (MODIFIED, +162/-1)
   - Imports: `paymentStatusEnum`, `PosLoyaltyRedeemModal`, `extractLoyaltyOrderInfo`, `resolveCanShowLoyaltyMainCta`.
   - Component registry: `PosLoyaltyRedeemModal` added.
   - Data: `currentLoyaltyOrder: null`, `loyaltyRedeemMainOpen: false`.
   - Computed: `canShowLoyaltyMainCta` (delegates to pure helper).
   - Methods: `triggerSuccessFlash(orderPayload)` extended to capture order info via `extractLoyaltyOrderInfo`. Added `openLoyaltyMainModal`, `closeLoyaltyMainModal`, `onLoyaltyMainApplied`.
   - `resetCart` + `resetPaymentForm`: both clear `currentLoyaltyOrder = null`.
   - Template: floorplan router-link wrapped in `v-if="dineInEnabled"`, new `<PosV5Button v-else>` with `data-testid="pos-loyalty-redeem-main-cta-open"`, 🎁 icon, label via `$t('pos.loyalty.redeem.title')`, disabled gate + tone='ready' when CTA active.
   - Template (modal): `<PosLoyaltyRedeemModal>` mounted next to `<PaymentComponent>` with `:open`, `:order-id`, `@close`, `@applied` bindings.

---

## TDD + Verification Evidence

### Vitest

```
tests/js/posLoyaltyMainPageCta.spec.js  (13 tests) ✓
tests/js/posLoyaltyRedeemModal.spec.js  (11 tests) ✓
tests/js/posDineInFlag.spec.js          (11 tests) ✓
                                         35 / 35 GREEN
```

Pre-existing failures in `kioskOfflineQueueV2.spec.js`, `posWizardComposerProfile.spec.js`, `sentinels/f004KioskCancelReasonSent.spec.js` (8 tests, 3 files) confirmed pre-existing on previous HEAD (verified via `git stash` + re-run). Not caused by this commit.

### PHPUnit

```
./vendor/bin/phpunit --filter PosLoyaltyRedeem  → 6/6 GREEN, 28 assertions
./vendor/bin/phpunit tests/Feature/Pos/         → 72/72 GREEN, 366 assertions
```

Zero backend touch — POS suite unchanged.

### Mix build

```
✔ Compiled Successfully in 8.77s
✔ Mix: Compiled successfully in 10.71s
```

No Vue compile errors, no webpack warnings (existing). All bundles built.

### Playwright visual

```
tests/e2e/wave-E-1-pos-loyalty-main-cta-capture.spec.js
  ✓ CTA renders in operator-bar, disabled with no active order (8.5s)
```

Screenshot: `reports/audit/wave-e-2026-05-19/WE-1-POS-LOYALTY-CTA/capture-01-pos-main-loyalty-cta-disabled.png`

Visual analysis (per CLAUDE.md §6 Visual Test Mandate):
- CTA visible in operator-bar nav, between "Écran client" and "Ouvrir tiroir".
- 🎁 icon + "Appliquer une réduction fidélité" label (proper i18n FR).
- Disabled state visually clear (greyed ghost tone).
- Floorplan link NOT rendered (mutual exclusivity confirmed visually).
- No raw labels (`Label.X`, `pos.loyalty.X`). All i18n resolved.
- No layout break: cart panel intact, items grid intact, branding intact.

---

## Frozen-Zone + Constraint Compliance

| Constraint                           | Status | Evidence                                                                                                                                       |
| ------------------------------------ | ------ | ---------------------------------------------------------------------------------------------------------------------------------------------- |
| Frozen zone touch (CLAUDE.md §7)     | PASS   | PosComponent.vue, PosLoyaltyRedeemModal.vue, PaymentComponent.vue NOT in frozen list. Only pos-wizard.js + pos-wizard.css + admin-pos-v4.blade.php frozen — untouched. |
| DIRTY file touch (PosController.php) | PASS   | PosController.php observed only, not written. Zero backend changes.                                                                            |
| Existing CTA preserved               | PASS   | PosOrderShowComponent.vue:227-239 + 285-290 unchanged (canonical CTA stays as defense in depth).                                               |
| NF525 invariants                     | PASS   | No fiscal sequence touched, no audit chain mutation, no Z report change. PosRedemptionService unchanged.                                       |
| Branch isolation                     | PASS   | Existing BranchScope on Order model + PosLoyaltyController routes scoped server-side.                                                          |
| Reset on order completion            | PASS   | 3 reset hooks: (a) computed gate hides CTA when order PAID/terminal, (b) resetCart clears currentLoyaltyOrder, (c) resetPaymentForm clears currentLoyaltyOrder. |

---

## Known Limitation (per Architect specialist finding)

**CASH-via-wizard flow remains unreachable for loyalty redemption.**

- Backend `PosRedemptionService::assertOrderRedeemable()` rejects orders with `payment_status=PAID`.
- The POS wizard (pos-wizard.js, frozen) creates a CASH order that lands with `payment_status=PAID` immediately on confirmation.
- Therefore both the main-page CTA AND the canonical PosOrderShow CTA hide for CASH orders — no UI path enables CASH loyalty redemption.

**The main-page CTA does, however, enable**:
- CARD / TPE flows (when the order is created non-PAID and pending payment).
- PARK / split-payment flows (order id known, payment pending).
- Any non-CASH order that lives in a non-terminal state for a few seconds before payment finalizes.

**Recommended V1.0.2 backlog item**: `pos-loyalty-pre-checkout-staging` — backend support for staging a loyalty intent on the cart before order creation, materialized in the `posOrder/save` payload. Out of scope per mission constraint ("no backend changes needed").

---

## Adversarial / RED-team Coverage

8 threat scenarios examined (see `03_red_team.json`):

| #     | Threat                                       | Defense                                                                | Severity |
| ----- | -------------------------------------------- | ---------------------------------------------------------------------- | -------- |
| RED-1 | Double-click race on CTA                     | Modal loading guard + 60s idempotency key bucket. Sufficient.          | P3       |
| RED-2 | Redeem-after-payment race                    | Backend assertOrderRedeemable rejects PAID with 422 ORDER_ALREADY_FINALIZED. | P2       |
| RED-3 | Stale order id between order cycles          | resetCart + resetPaymentForm clear currentLoyaltyOrder. Mitigated.     | P2       |
| RED-4 | Permission downgrade mid-session             | Backend 403 surfaces via translateErrorCode → permission_denied i18n.  | P3       |
| RED-5 | Double-apply (same order, 2 redemptions)     | Backend already_redeemed invariant (one redemption per order).         | P3       |
| RED-6 | Subtotal mutation post-redeem                | Out of scope — PosOrderShow edit flow concern, V1.0.2.                 | P2       |
| RED-7 | Empty cart loyalty intent                    | CTA disabled when currentLoyaltyOrder=null. Mitigated.                 | P3       |
| RED-8 | Modal leak on route change                   | Vue lifecycle handles cleanup.                                          | P3       |

**Zero P0/P1 blockers. All P2/P3 mitigated or out of scope.**

---

## Files in Deliverable

```
reports/audit/wave-e-2026-05-19/WE-1-POS-LOYALTY-CTA/
├── STATUS.md                                         (this file)
├── 01_architect.json                                 (~850 words)
├── 02_ux_a11y.json                                   (~920 words)
├── 03_red_team.json                                  (~1050 words)
└── capture-01-pos-main-loyalty-cta-disabled.png      (1440×900, visual evidence)
```

---

## Commit Message (proposed)

```
feat(pos-loyalty-redeem-V1 wave-E-1): add main-page CTA where floorplan disabled

Owner direction 2026-05-19: V1 has pos.dine_in_enabled=false (BRAIN §1) so
the operator-bar slot that hosts the floorplan router-link is freed up.
Surface the loyalty redeem CTA there so the cashier can trigger redemption
WITHOUT navigating to PosOrderShow.

Wire-up:
- New pure helper resources/js/helpers/posLoyaltyMainCta.js exports
  extractLoyaltyOrderInfo + canShowLoyaltyMainCta (13/13 Vitest GREEN).
- PosComponent.vue: imports modal + paymentStatusEnum + helper, adds
  currentLoyaltyOrder + loyaltyRedeemMainOpen state, computed gate, modal
  mount, CTA in operator-bar gated by !dineInEnabled. triggerSuccessFlash
  captures the order:confirmed payload so the CTA reactivates on every
  non-PAID order cycle. resetCart + resetPaymentForm clear the tracked
  order (per owner direction — "applies during this order, reset on end").

Constraints:
- 0 frozen-zone touch (PosComponent.vue + modal + helper outside §7 list).
- 0 backend change (PosRedemptionService unchanged; 72/72 PHPUnit GREEN).
- Existing PosOrderShow CTA preserved (defense in depth, both paths reachable).
- Mix build PASS, Playwright capture PASS, visual analysis OK.

Known limitation:
CASH-via-wizard creates PAID immediately → both CTAs hide for CASH (backend
guard, not UI). Non-CASH (CARD/TPE/PARK) flows now have a discoverable
in-page entry point. V1.0.2 follow-up: pos-loyalty-pre-checkout-staging
for the CASH path.

Specialist audit: 3 reports in reports/audit/wave-e-2026-05-19/WE-1-POS-LOYALTY-CTA/.
```

---

## Reflect

- **What worked**: TDD-first via pure helper extraction — avoided mounting the 4 000-line PosComponent in Vitest. 13 tests cover the entire state matrix in ~3ms.
- **What was tricky**: PaymentComponent emits `order:confirmed` AFTER `payment-form:reset`. Sequencing analysis caught the right reset semantic — clear in resetPaymentForm because the next event repopulates it.
- **What's a follow-up**: CASH-via-wizard pre-checkout staging is the only remaining loyalty UX gap. Should be V1.0.2 if owner prioritizes.
- **Architecture observation**: The mutual-exclusivity gate `v-if="dineInEnabled" / v-else` for floorplan↔loyalty makes the operator-bar slot future-proof — flipping `pos.dine_in_enabled=1` swaps the buttons cleanly. No layout shift, no migration needed.
