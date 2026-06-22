# PROPOSAL 007 — Direct revisit to `/kiosk/upsell` re-rolls suggestions
and loses selections silently

**Component**: `resources/js/components/frontend/kiosk/KioskUpsellComponent.vue`
**Phase**: B.5 — Frozen-zone audit (no edit, proposal only)
**Severity**: P3 (UX continuity · edge-case state hygiene)
**Reasoning angle**: Cart manipulation correctness

---

## Observation

The intended flow is:

1. Cart → tap *"Valider"* → `quoteOrder` → if not yet shown, navigate to
   `kiosk.upsell` (`KioskCartComponent.vue:684`).
2. `markUpsellShown()` is committed (`kioskCart.js:537–539`).
3. On the upsell screen, user picks items + *Ajouter et continuer* → goes
   to `kiosk.payment`.
4. If user navigates back (browser back, or some future "modifier"
   button), they hit the cart again. From there, `Valider` sees
   `upsellShown === true` → skips upsell, goes straight to payment
   (`KioskCartComponent.vue:675–678`).

This works for the cart → upsell → payment forward path. But:

- **Manual URL revisit** (e.g. attendant pastes `/kiosk/upsell` to debug,
  or a routing guard refresh): `mounted()` calls `loadSuggestions` again
  → backend returns a **different random shuffle** (`inRandomOrder()` in
  `ItemController::kioskUpsell` at line 84). The user's previous
  selections (kept only in component `data().selectedItems`) are wiped
  because the component re-mounts.
- **Browser back from payment** (some kiosk shells trap back, others
  don't — depends on hardware): if back navigation revisits the upsell
  route directly without re-passing through cart, the user loses their
  selections AND sees a new set of suggestions.

The component-local `selectedItems` array is never persisted to
`kioskCart` Vuex state. The cart's `upsellShown` flag tracks
*"have we shown the screen?"* but not *"what did the user select?"*.

## Risks

- Customer rage at *"I picked the cookie, now it's gone"* — known
  fast-food kiosk friction pattern, surveyed in McDonald's UX research
  (CLAUDE feedback ref `feedback_kds_modern_research_required.md`
  context).
- Attendant debugging confusion if random suggestions change on every
  refresh — hard to diagnose backend behaviour.

## Proposed fix

### A — Persist `selectedUpsellItems` in Vuex (preferred for the loss issue)

Add to `kioskCart` state:
```js
selectedUpsellItems: [],  // mirror of component-local until addAndContinue
upsellSuggestions: null,  // snapshot of last fetched suggestions (TTL ~5min)
```

Component:
```js
data() {
  return {
    suggestions: this.$store.state.kioskCart.upsellSuggestions || [],
    selectedItems: this.$store.state.kioskCart.selectedUpsellItems || [],
    ...
  };
},
methods: {
  async loadSuggestions() {
    // Skip fetch if we have a fresh snapshot
    if (this.$store.state.kioskCart.upsellSuggestions?.length) {
      this.suggestions = this.$store.state.kioskCart.upsellSuggestions;
      return;
    }
    // ... existing fetch ...
    this.$store.commit('kioskCart/SET_UPSELL_SUGGESTIONS', this.suggestions);
  },
  toggleItem(item) {
    // ... existing ...
    this.$store.commit('kioskCart/SET_SELECTED_UPSELL', this.selectedItems);
  },
  addAndContinue() {
    // ... existing ...
    this.$store.commit('kioskCart/SET_SELECTED_UPSELL', []);
    this.$store.commit('kioskCart/SET_UPSELL_SUGGESTIONS', null);
  },
  skip() {
    // ... existing ...
    this.$store.commit('kioskCart/SET_SELECTED_UPSELL', []);
    this.$store.commit('kioskCart/SET_UPSELL_SUGGESTIONS', null);
  },
}
```

Don't forget to clear these on `RESET` (kioskCart line 414 region).

### B — Block direct revisit via router guard

Add a `beforeEnter` guard on `kiosk.upsell` that redirects to
`kiosk.payment` if `upsellShown === true`. Currently, `requireCart`
covers empty-cart redirect, but doesn't enforce the "show once" contract.

```js
// router/modules/kioskRoutes.js
beforeEnter: (to, from, next) => {
  if (!store.getters['kioskCart/items'].length) return next({ name: 'kiosk.cart' });
  if (store.getters['kioskCart/upsellShown'] && from.name !== 'kiosk.cart') {
    return next({ name: 'kiosk.payment' });
  }
  next();
},
```

Combine A + B: prevent the orphan revisit + preserve selections within
the intended single-visit lifecycle.

## Scope estimate

- ~30 LOC across `kioskCart.js` (non-frozen) + `kioskRoutes.js`
  (non-frozen) + `KioskUpsellComponent.vue` (frozen — LOCK doc).
- Vitest: mount → toggle 2 cards → unmount → remount → assert selections
  restored.
- E2E (Playwright): cart → upsell → toggle → browser back → cart → forward
  → assert same suggestions.

## Acceptance criteria

- Direct GET `/kiosk/upsell` when `upsellShown === true` redirects to
  `/kiosk/payment`.
- Selections survive component re-mount within the same cart session.
- After successful `addAndContinue` or `skip`, selections + suggestion
  cache are cleared.

## Rollback

Three-file revert. Backward compatible because new state keys default to
empty.
