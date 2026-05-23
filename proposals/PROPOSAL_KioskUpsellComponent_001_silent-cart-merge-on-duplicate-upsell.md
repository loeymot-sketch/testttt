# PROPOSAL 001 — Silent cart merge when upsell item already in cart

**Component**: `resources/js/components/frontend/kiosk/KioskUpsellComponent.vue`
**Phase**: B.5 — Frozen-zone audit (no edit, proposal only)
**Severity**: P1 (UX truthfulness + analytics integrity)
**Reasoning angle**: Cart manipulation correctness · Client-impatient persona

---

## Observation

`addAndContinue` (lines 220–257) iterates `selectedItems` and dispatches
`kioskCart/addItem` once per item, with **empty variations/extras**:

```js
this.addItem({
  item_id: item.id,
  ...
  item_variations: { variations: {}, names: {} },
  item_extras: { extras: [], names: [] },
  instruction: null,
});
```

`kioskCart.ADD_ITEM` (`store/modules/kioskCart.js:256–289`) merges any incoming
line whose `(item_id, item_variations, item_extras, instruction)` tuple
matches an existing line:

```js
const existing = state.items.findIndex(i =>
    i.item_id === item.item_id &&
    JSON.stringify(i.item_variations) === JSON.stringify(item.item_variations) &&
    JSON.stringify(i.item_extras) === JSON.stringify(item.item_extras) &&
    (i.instruction || '') === (item.instruction || '')
);
if (existing >= 0) { /* increments quantity */ }
```

Upsell items always carry the empty-extras signature, so **any upsell item
already present in cart will silently bump that line's quantity by +1**.

The UI then fires `toast_added_one` / `toast_added_many` (lines 241–246)
which says *"Coca ajouté !"* — implying a **new line**. The user thinks
"I added one drink"; the cart actually shows "Coca ×2".

## Concrete failure

1. Customer adds 1 Coca during wizard.
2. Upsell screen suggests Coca (backend filter on `excludeIds = cart item_ids`
   exists at `ItemController.php:73` — so this *should* exclude). **But** the
   exclude filter only triggers when `state.items.length > 0` and ids are
   sent via `?item_ids=` query (`kioskCart.js:824–827`); if the
   `frontend/item/kiosk-upsell` controller fails to apply `whereNotIn` (e.g.
   request without `item_ids`, or controller bug → covered by
   `KioskUpsellCategoryTest.php` but not for the `cart-already-contains`
   variant), the duplicate slips through.
3. Customer taps Coca on upsell screen → toast *"Coca ajouté !"* → cart now
   has Coca ×2 with no warning, no qty indicator, no preview.

## Risks

- **Customer surprise at checkout total** (frustration, complaint, refund).
- **Analytics drift**: `upsell_accepted{items_count: 1}` emitted while two
  units were billed.
- **Suggestions reveal cart leakage**: backend `excludeIds` is a defense in
  depth; if it breaks, the frontend has no second line of defense.

## Proposed fix

Make the frontend resilient to backend mis-exclusion **and** truthful when
merging:

1. Before dispatching `addItem`, peek at `kioskCart/items` for an existing
   merge target (same item_id + empty variations + empty extras + null
   instruction). If found, prefer one of:
   - Show inline disabled state on the card (*"Déjà dans votre panier"*)
     and refuse selection — explicit, no surprise.
   - Or dispatch `updateQuantity` and tweak the toast to
     *"+1 Coca (total ×2 dans votre panier)"*.
2. Add a Vitest case to `tests/js/KioskUpsellOrderSummaryRestyle.spec.js`
   asserting: "selecting an item already in the cart triggers the
   already-in-cart branch, not the silent merge". Mock the store with a
   pre-existing line carrying empty variations/extras.
3. Keep backend `excludeIds` as primary defense; this is belt-and-suspenders.

## Scope estimate

- 1 file diff (`KioskUpsellComponent.vue`) — frozen zone, requires LOCK doc.
- 1 new spec file (or extend existing).
- Risk: introduce `cartItems` mapState dependency; ensure no SSR/hydration
  side-effect since component is mounted only inside the kiosk SPA.

## Acceptance criteria

- Repro: pre-fill cart with `{item_id: 42, item_variations: {}, item_extras: {}}`,
  mount component with a suggestion containing `id: 42`. Tap the card,
  observe toast wording reflects the merge.
- Backend exclude regression test still green.
- Vitest: new "already-in-cart" branch covered.
- Visual: card carries a "déjà ajouté" badge OR is hidden from suggestions
  client-side as last-resort guard.

## Rollback

Single-file revert — purely additive client guard.
