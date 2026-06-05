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

## Decision (this session)
**Deferred to a dedicated full-context careful pass.** Empirical evidence shows the naive ItemAttribute-layer
fix would break live bowl ordering (#28-32) and kiosk price previews — false-rejection at the counter is
worse than the latent omitted-attr bug, which the client wizards already prevent (the steps are mandatory
client-side). The frozen wizard + kiosk wizard enforce composition today; this server check is defense-in-depth
and must be done right, not rushed in a continued/depleted context. Tracked here with the exact recipe above.
