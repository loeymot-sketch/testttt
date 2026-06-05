# M3-01 — server-side mandatory-composition enforcement — CAREFUL-PASS SPEC (do NOT rush)

**Date** 2026-06-05 · **Branch** `heal/pre-cloud-exec-2026-06-05` · **Status** NOT IMPLEMENTED — the
catalog recipe is **empirically proven unsafe as written**. This doc captures the exact landmines so a
dedicated, full-context pass can land it correctly. (Non-frozen file `app/Rules/MultiVariationConstraint.php`,
but blast-radius = ALL order creation → false-rejection at the counter is strictly worse than the latent bug.)

## The defect (real, reachable)
`MultiVariationConstraint::validateCollectionKeyedByItemIndex` validates **present-but-short** mandatory
attributes (`min>0 && totalQty<min`, line 118) but builds `$byAttr` only from attributes PRESENT in the
payload (lines 90-104) and skips items with empty `item_variations` (line 64). → A mandatory attribute
**entirely omitted** from the payload is never failed. DB ground truth (this session):
3 `ItemAttribute` with `min_select>0` — **#7 Base bol (1/1)**, **#8 Sauce bol (1/…)**, **#9 Style frites (1/1)**;
15 items carry one: bowls #28-32 (attrs 7+8), frites #33-34 (attr 9), bowl-frites/riz #41-48 (attr 8).

## Why the catalog recipe (enforce on `ItemAttribute.min_select`) is UNSAFE — two verified vectors
A green 2857-test PHPUnit run does **NOT** catch either; both were found by empirical DB inspection + advisor review.

### Vector B (fatal) — enforcing at the wrong layer vs. what shapes the payload
Payloads are driven by **`ItemWizardStep`**, not `ItemAttribute`. Verified counts:
- `srcAttr=8` → **8** wizard steps with `min_select>0`. `srcAttr=9` → **2** steps. ✅ backed.
- `srcAttr=7` (Base bol) → **0** wizard steps (0 total). ❌ **mandatory at the attribute level but NO backing
  required wizard step.** Bowls #28-32 carry attr#7 that the wizard never surfaces → an
  `ItemAttribute.min_select` "absent → reject" check would **false-reject every bowl #28-32 order**.
- (Also a known max-drift: attr#8 attribute-level max=1 vs step-level max=2. The present-but-short check is
  drift-safe because it only fires when the attribute is present; an absent-check removes that safety.)

### Vector A — the live pricing-preview path shares the validation trait
`PricingPreviewController` (`routes/api.php:1453`) uses `PricingPreviewRequest`, which uses the SAME
`ValidatesOrderItemVariations::validateOrderItemVariationsAfter` hook. The kiosk drives a live debounced
`createKioskPricingPreview` (`KioskWizardComponent.vue:2171`) on partial compositions. Gating the shared hook
with an absent-required check would **422 every mid-build price preview** and break the wizard's running total.
No test simulates a mid-composition preview → suite stays green while production breaks.

## Correct fix shape (for the dedicated pass)
1. **Separate method, order-creation only.** Add a NEW check invoked ONLY by `PosOrderRequest` /
   `OrderRequest` / `TableOrderRequest` — NEVER by `PricingPreviewRequest`. Leave preview on the existing
   lenient (present-but-short) path. (Sidesteps Vector A.)
2. **Enforce at the wizard-step layer, not the attribute layer.** Required composition = `ItemWizardStep`
   with `min_select>0` for the item's **published** profile (`WizardProfileBranchScope` global-or-branch),
   resolved via `item → ItemWizardProfile → ItemWizardStep.source_item_attribute_id`. Only fail when a
   step-required `source_item_attribute_id` is absent from the payload. (Sidesteps Vector B: attr#7 has no
   step → not enforced → bowls #28-32 not false-rejected; attrs 8/9 have steps → enforced.)
3. **Items with no profile / no required steps pass untouched** (drinks, simple items).
4. **TDD**: RED = omitted step-required attr → reject; GREEN. Plus explicit guards: (a) a no-required-attr
   item (a drink) still passes; (b) a partial pricing preview still passes (different request path).
   THEN full PHPUnit regression + a manual production-flow review of every submit path
   (POS submit, kiosk submit, table QR, order edit, parked-recall restore).

## ✅ RESOLUTION (2026-06-05) — M3-01 server-side is a FALSE POSITIVE (already enforced at the correct layer)
Deeper trace (the discipline the naive fix skipped) found the server **already rejects an omitted mandatory
composition** — at the CORRECT layer the advisor prescribed (per-item published `ItemWizardStep`), on EVERY
order-creation path. The catalog (like M8-01) inspected the wrong layer (`MultiVariationConstraint` / the frozen
wizard) and missed the real enforcement:

- **`PricingService::assertComposerStepConstraints` (PricingService.php:557-657)** loads each line's PUBLISHED,
  branch-scoped `ItemWizardProfile` + active steps, projects them, and for each step computes
  `$total = array_sum($counts)` (**= 0 when the step is entirely OMITTED**) then **`if ($total < $min) throw 422`**
  (line 618). This is invoked at **`calculateOrder` line 110** — the pricing SSOT — so `OrderService`,
  `FrontendOrderService`, and `OrderQuoteService` (POS quote gate) ALL enforce it.
- **Correct-layer proof (clears advisor Blocker B):** enforcement is by published wizard STEP, not
  `ItemAttribute.min_select`. Verified: items #33/#34 (frites) have a published profile with required step
  `style`(attr#9,min=1); items #41/#45 (bowls) with required step `sauce`(attr#8,min=1) → omitting either = 422.
  Item #28 (Base bol, attr#7) has **NO published profile** → attr#7 is correctly NOT enforced — confirming a
  code-level `ItemAttribute` check WOULD have false-rejected it (Blocker B was real; the existing design avoids it).
- **Already regression-locked (no new test needed):**
  - `ComposerStepConstraintTest::test_required_composer_variation_step_is_enforced_server_side` — calls
    `calculateOrder` with an **empty variations array** → asserts `InvalidArgumentException "minimum 1 sélection"`.
    (+ the addon-step omitted variant.) **13/13 PASS.**
  - `FritesWizardComposerTest::test_frites_without_required_sauce_returns_422` — a real frites item, sauce omitted,
    via `/api/admin/pos/quote` → 422 "Sauce". **4/4 PASS.**

**Verdict: M3-01 RESOLVED (false-positive).** No production code changed — writing the catalog's
`MultiVariationConstraint` check would have DUPLICATED existing enforcement and (per Blocker A: shared preview
trait; Blocker B: attr#7) introduced false-rejections. The residual is purely the FROZEN frontend UX
(`pos-wizard.js` lets an incomplete item reach the cart before the server rejects it) — cosmetic, server is the
authority, and it's behind the §7 wall. The legacy attr#7 "Base bol" on items #28-32 (no profile, no step) is a
data-hygiene note (P3), not a validation bug. The analysis above is retained as the rationale for why no code fix
was the correct, disciplined outcome.
