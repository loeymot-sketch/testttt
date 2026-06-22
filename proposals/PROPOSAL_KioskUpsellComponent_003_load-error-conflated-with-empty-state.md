# PROPOSAL 003 — `load_error` analytics signal is dead code; backend errors
masquerade as `no_suggestions`

**Component**: `resources/js/components/frontend/kiosk/KioskUpsellComponent.vue`
**Phase**: B.5 — Frozen-zone audit (no edit, proposal only)
**Severity**: P2 (analytics drift · observability blind spot)
**Reasoning angle**: Client-impatient persona · backend hygiene

---

## Observation

`loadSuggestions` (lines 160–185):

```js
async loadSuggestions() {
  this.loading = true;
  try {
    const res = await this.$store.dispatch('kioskCart/fetchUpsellItems');
    const items = res?.data?.data || [];
    this.suggestions = items.slice(0, 6);
    if (this.suggestions.length === 0) {
      this.skip('no_suggestions');
      return;
    }
    // ...
    this.startAutoSkip();
  } catch (_) {
    this.skip('load_error');  // ← dead code
    return;
  } finally {
    this.loading = false;
  }
},
```

Store action (`kioskCart.js:820–833`):

```js
fetchUpsellItems({ state }) {
  return new Promise((resolve) => {
    axios.get(url)
      .then(resolve)
      .catch(() => resolve({ data: { data: [] } }));  // ← never rejects
  });
},
```

The store **swallows every error** and resolves with an empty payload.
Result:

- The `try/catch` in `loadSuggestions` is **unreachable**.
- `skip('load_error')` is **never emitted** — the analytics whitelist
  accepts it but no event will ever be produced.
- A 500 error, 404 controller bug, network timeout, expired Sanctum token,
  CSRF mismatch, or branch mis-routing all look identical to *"backend
  legitimately has 0 upsell items"*.

## Risks

- Cannot distinguish *legitimately empty upsell catalogue* (call to action:
  configure `is_upsell` items) from *broken endpoint* (call to action: page
  the dev team).
- The Phase 6.4 analytics spec
  (`docs/design/KIOSK_ANALYTICS_EVENTS.md`) expects `upsell_rejected.reason`
  ∈ {`user`, `auto_timer`, `no_suggestions`, `load_error`} — runtime always
  produces the first three, never the fourth.
- Silent failures in production are exactly the class of bug FoodKing
  Principles §13 (Evidence Rules) wants to surface, not bury.

## Proposed fix

Two options, ordered by preference:

### Option A (preferred) — Surface the error in the store

```js
fetchUpsellItems({ state }) {
  return axios.get(url).then(res => ({ ok: true, data: res?.data?.data || [] }))
    .catch(err => ({ ok: false, error: err, data: [] }));
},
```

Component:
```js
const res = await this.$store.dispatch('kioskCart/fetchUpsellItems');
if (!res.ok) {
  this.skip('load_error');
  return;
}
this.suggestions = res.data.slice(0, 6);
if (!this.suggestions.length) {
  this.skip('no_suggestions');
  return;
}
```

### Option B (minimal) — Re-throw in store, catch in component

Change the store's `.catch(() => resolve(...))` to `.catch(reject)` and
let the component's existing `try/catch` (which is currently dead) do the
job. **Caveat**: other call sites must be audited — none today, but verify.

## Scope estimate

- 1 file diff in `kioskCart.js` (~5 LOC) — non-frozen.
- 1 file diff in `KioskUpsellComponent.vue` (~5 LOC) — frozen, requires
  LOCK doc.
- 1 Vitest case ("backend rejects → `load_error` analytics emitted").

## Acceptance criteria

- Mock axios to reject with a network error → component navigates to
  payment AND emits `upsell_rejected{reason: 'load_error'}`.
- Mock axios to return `{ data: { data: [] } }` → component emits
  `upsell_rejected{reason: 'no_suggestions'}`.
- Both events trackable in analytics dashboards as distinct signals.

## Rollback

Two-file revert. Backward compatible because the store API contract
becomes "may resolve with `{ok: false}`" — old callers (none today) still
work if they destructure `data`.
