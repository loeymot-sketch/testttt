# Sub-system 1.a — CAISSE composition / wizard / quote / store / snapshot SSOT
Round 5 · 2026-06-27 · READ-ONLY adversarial audit · DB foodking_e2e · live :8766

## Findings

### [P2-ESCALADE] public/js/pos-wizard.js:3008,3886-3894,1292 — "Fantôme-upcharge viande +2,50" confirmed in the *per-meat toggle* sub-UI (FROZEN)
- **Repro / evidence**: The viandes section renders a per-meat selector
  ("➕ Viande supplémentaire (+2,50€/viande)", lines 2996-3023). The +/- handler
  (5704-5722) stores `selections.viandeSupplItems['v_' + variation.id]` where
  `variation.id` is the **meat ItemVariation id** (e.g. 361/564). The preview total
  adds `supplTotal * VIANDE_SUPPL_PRICE` (line 1292; `VIANDE_SUPPL_PRICE` defaults
  to **2.50** — `order_setup_viande_suppl_price` is **NULL** in DB, confirmed via
  tinker → blade fallback `?? 2.50` at master.blade.php:292 / admin-pos-v4.blade.php:131).
  Serialization (3886-3894) maps it to `allSelectedExtras[variation.id]=true`, but
  the extra checkboxes are keyed by **ItemExtra id** (63–400 for item 23). Meat
  variation ids (361, 564…) are NOT extra ids → no checkbox matches → the per-meat
  surcharge is **neither billed nor recorded** while the preview shows +2,50€.
  Backend proof (PricingService, item 23): base+meat+sauce = **6.50**; same +
  the *real* extra 400 "Viande supplémentaire" (2.50) = **9.00** → the 2.50 exists
  and WOULD bill if the correct extra id were sent.
- **Direction**: undercharge of preview vs backend (merchant revenue loss + kitchen
  omission). NOT a fiscal violation — Z reflects the actual amount charged.
- **Important nuance (verify-before-report)**: a *parallel correct path* exists.
  The generic "Viande supplémentaire" ItemExtra (per-item id 392–400, price 2.50)
  passes the supplements filter (pos-wizard.js:2883-2886 only excludes 'sauce') and
  renders as a normal supplement checkbox → charged correctly. **5 real POS orders
  (source=15)** prove it: OI#4928/4914/4911/4910/4894 each recorded `extra "Viande
  supplémentaire" x1 line_total=2.5`. So the discrepancy is confined to the
  per-meat toggle; a cashier using the generic Suppléments checkbox bills correctly.
- **Lens**: CASHIER (preview lies) + CLIENT (kitchen ticket misses chosen extra meat).
- **Reco**: ESCALADE — frozen Vanilla wizard + business decision. Either remove the
  broken per-meat toggle or map `v_<variation.id>` to the "Viande supplémentaire"
  ItemExtra id with quantity. Not reproducible from DB alone (DB shows the working
  path); a live browser capture of the per-meat-toggle payload would seal it.

## Verified CLEAN

- **T-1.a.1 quote = no order/fiscal/stock side-effect, total from backend**:
  `OrderQuoteService::quoteInsideTransaction` (OrderQuoteService.php:61-109) runs in a
  DB transaction, totals come entirely from `PricingService::calculateOrder` (frozen).
  It persists an `OrderQuote` signed row (by design, for replay) but creates NO Order,
  no stock decrement, no fiscal allocation. `consume` only fires on the `consume` flag
  / `consumeOrderId`. PosController::quote (164-215) gates on `permission:pos` for
  non-kiosk. CLEAN.
- **T-1.a.2 composer-aware bridge present & wired**: commit `065ab8ace` IS in history;
  bridge handlers `onWizardBridgeSelect`/`onWizardBridgeExtra` →
  `setVariationQuantity`/`setExtraQuantity` (ItemComponent.vue:661-714);
  `posWizardComposerAware.enabled` exposed in BOTH master.blade.php:152 (V5, non-frozen)
  and admin-pos-v4.blade.php:109 (frozen). Frozen diff vs HEAD = **0 lines**
  (pos-wizard.js / pos-wizard.css / admin-pos-v4.blade.php / PaymentComponent.vue /
  PosV5TrancheRow.vue all untouched).
- **T-1.a.3 Pricing SSOT (forge → backend wins)**: forged `price=99.99 / total_price=99.99`
  → backend total **6.50** (= clean baseline). Cross-item option forge (extra 382 from
  item 45 sent on item 23) → backend **throws** `InvalidArgumentException: Extra ID 382
  n'appartient pas à l'article 23`. Backend recomputes from item_id + option_ids only.
- **T-1.a.4 MAX/MIN multi-variation constraints**: `MultiVariationConstraint.php`
  enforces min/max/allow_repeat for present attrs AND rejects wholly-omitted required
  attrs (`requiredAttributesByOrderedItem`, lines 51-162). **Quote↔store parity heal
  PRESENT & wired**: `OrderQuoteService::assertVariationPresenceConstraints` (line 69
  call, 220-239 def) reuses the same rule → quote rejects an omitted required attr in
  422, matching the store. PHPUnit: 27/27 pass (QuoteBinding, PosOrderRequestNoClientTotals,
  FritesWizardComposer, PosQuoteVariationConstraint [pos+kiosk omitted→422], MultiVariation).
- **T-1.a.5 category-first landing + auto-return**: `posBrowseView.js`
  (`resolvePosBrowseMode` / `browseCategoryTiles` drops id=0 sentinel /
  `activeBrowseCategory`) — pure, unit-tested. CLEAN.
- **composition_snapshot SSOT (real DB read, OI#5059)**: shape is correct —
  `attribute_name`="Viande 1" (GROUP) vs `variation_name`="Mexicanos" (VALUE), **no
  inversion**, two distinct meats (Viande 1=Mexicanos / Viande 2=Cordon Bleu), sauce
  present, addon Menu 2.50, extra "Viande supplémentaire" 393@2.50. No blanks, no
  duplication, no undercharge in the recorded snapshot. schema_version=1, captured_at set.

## Notes / data verified
- Source enum: WEB=5, APP=10, POS=15. Order 5305 (the suppl-meat snapshot) was a WEB
  order; 5 corroborating suppl-meat orders are POS (source=15), 2026-06-24.
- Attribute config canonical: Viande 1/2 min=1 max=1; Sauce(1ère gratuite) min=1 max=1;
  meats are free variations (price 0); "Viande supplémentaire" is a priced ItemExtra (2.50).
