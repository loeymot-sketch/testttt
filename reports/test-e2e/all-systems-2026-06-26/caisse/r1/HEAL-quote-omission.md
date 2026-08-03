# HEAL — Quote↔Store parity: required variation attribute wholly omitted

**System**: Caisse · **Round**: r1 · **Date**: 2026-06-26 · **Frozen-diff**: 0

## Bug (confirmed)
The DEVIS (quote) accepted a REQUIRED variation attribute (`min_select>=1`) wholly
OMITTED that the STORE rejects in 422. Both `POST /api/admin/pos/quote` and
`POST /api/frontend/order/quote` route to `PosController@quote` → `OrderQuoteService::quoteInsideTransaction`
→ `PricingService::calculateOrder` (FROZEN; its root `assertVariationConstraints` only
inspects attrs PRESENT in the payload). Presence-required lived ONLY in
`MultiVariationConstraint` (store FormRequest path, heal 2026-06-24) → quote≠store,
the preview lied. Blast radius: 14 active items with required legacy attributes
(Tacos M/L, Cayenne, Galette, Chicken, burgers 98-105).

## Files
- **NEW test** `tests/Feature/Pos/PosQuoteVariationConstraintTest.php` (3 tests).
- **FIX (non-frozen)** `app/Services/Order/OrderQuoteService.php` (+80 lines):
  new `assertVariationPresenceConstraints()` invoked in `quoteInsideTransaction`
  right after `$items` decode (so it runs for BOTH pos & kiosk surfaces, before
  pricing). It reuses `\App\Rules\MultiVariationConstraint::validateCollectionKeyedByItemIndex`
  (UNCHANGED) and aggregates failures into a `ValidationException` (keyed
  `items.{i}.item_variations`), which `PosController::quote` already propagates as 422.
  Helper `itemForVariationRule()` normalizes the stdClass quote items (from
  `safeJsonDecode`, which decodes WITHOUT assoc) into the `['item_id', 'item_variations'=>[['id','quantity']]]`
  array shape the rule expects — this shape mismatch is why a naive wiring would
  have silently no-op'd.

## TDD red → green
- RED (before fix): `test_pos_quote_rejects_...` and `test_kiosk_quote_rejects_...`
  → **422 expected, received 200** (quote accepted the omitted-required payload).
  `test_pos_quote_accepts_...present` already passed.
- GREEN (after fix): **PosQuoteVariationConstraintTest 3/3** (7 assertions).
  POS-omitted→422 + FR message «Sélectionnez au moins … {Viande 1}», POS-present→200
  total 6,90 €, kiosk-omitted→422. Quote now mirrors the store on both surfaces.

## Regressions (all green)
`--filter 'MultiVariationValidation|QuoteBinding|PosOrderRequestNoClientTotals|FritesWizardComposer|Composer|PricingParity|PosKiosk'`
→ **149/149 OK** (727 assertions, 2 pre-existing skips). Incl.
`MultiVariationValidationTest 12/12` and `FritesWizardComposerTest 4/4`.

## Composer-bol NON-regression (explicit)
- `FritesWizardComposerTest` (real published composer bol via quote/preview/store) → 4/4.
- Direct proof: `MultiVariationConstraint` over an item with ZERO active
  `ItemVariation` rows + empty payload → **0 errors**. Composer bols carry choices
  in composer steps, not `item_variations`, so `requiredAttributesByOrderedItem`
  (queries `ItemVariation` only) derives nothing → a valid composer order keeps
  passing the quote. Confirmed.

## Frozen-diff = 0
`git diff --stat` over `PricingService.php`, `pos-wizard.js`, `pos-wizard.css`,
`admin-pos-v4.blade.php`, `MultiVariationConstraint.php` → **empty**. Only
`OrderQuoteService.php` (non-frozen) + new test changed. NOT committed.
