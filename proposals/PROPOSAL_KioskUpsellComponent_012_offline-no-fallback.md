# PROPOSAL 012 — No offline fallback for upsell endpoint; floods
`no_suggestions` analytics during outages

**Component**: `resources/js/components/frontend/kiosk/KioskUpsellComponent.vue`
**Phase**: B.5 — Frozen-zone audit (no edit, proposal only)
**Severity**: P3 (resilience · analytics drift)
**Reasoning angle**: Client-impatient persona · backend hygiene

---

## Observation

Per the project's offline strategy (`offline.queued`,
`offline.replayed`, `offline.recovered` events in
`kioskAnalytics.js:97–100`), the kiosk is built to survive network blips
via offline queue. But the upsell endpoint `GET
/frontend/item/kiosk-upsell` has **no offline cache**.

When the kiosk loses connectivity:

1. `axios.get(url)` (kioskCart.js:828) fails → store action swallows the
   error (see PROPOSAL 003) → resolves with `{ data: { data: [] } }`.
2. Component sees empty → `skip('no_suggestions')` → navigates to
   payment.
3. Analytics receives a stream of `upsell_rejected{reason:
   'no_suggestions'}` indistinguishable from a legitimate empty
   catalogue.
4. The upsell screen — a revenue-driver — silently disappears for the
   entire outage window. Customer experience degrades **without any
   visible signal** to anyone.

Combined with PROPOSAL 003's fix (`load_error` distinct from
`no_suggestions`), this gap shrinks. But a more robust pattern is to
**cache the last successful suggestions list** and serve from cache
during outages.

## Risks

- Lost upsell revenue during network blips (which the kiosk otherwise
  weathers via offline queue).
- Analytics drift conceals the outage from ops dashboards.

## Proposed fix

### A — Cache last suggestions in localStorage with TTL

```js
// helpers/kioskUpsellCache.js (new file, ~30 LOC)
const KEY = 'kiosk_upsell_suggestions_cache';
const TTL_MS = 5 * 60 * 1000; // 5 min

export function readCache(branchId, itemIds) {
  try {
    const raw = localStorage.getItem(KEY);
    if (!raw) return null;
    const obj = JSON.parse(raw);
    if (obj.branchId !== branchId) return null;
    if (Date.now() - obj.savedAt > TTL_MS) return null;
    if (obj.itemIdsHash !== hashIds(itemIds)) return null;
    return obj.suggestions;
  } catch { return null; }
}

export function writeCache(branchId, itemIds, suggestions) {
  try {
    localStorage.setItem(KEY, JSON.stringify({
      branchId, itemIdsHash: hashIds(itemIds),
      suggestions, savedAt: Date.now(),
    }));
  } catch { /* quota exceeded — best effort */ }
}
```

Store action (after PROPOSAL 003 fix):
```js
fetchUpsellItems({ state }) {
  return axios.get(url)
    .then(res => {
      writeCache(state.branchId, state.items.map(i => i.item_id), res.data.data);
      return { ok: true, data: res.data.data };
    })
    .catch(err => {
      const cached = readCache(state.branchId, state.items.map(i => i.item_id));
      if (cached?.length) {
        kioskAnalytics.track('upsell_served_from_cache', { count: cached.length });
        return { ok: true, fromCache: true, data: cached };
      }
      return { ok: false, error: err, data: [] };
    });
},
```

Component adapts to distinguish *served-from-cache* (display normal) vs.
*no suggestions* (skip).

### B — Track offline status explicitly

Listen to `navigator.onLine` + ping endpoint; if offline, **don't even
try** — go straight to payment without consuming the network attempt.
Combine with cached suggestions only when offline.

## Scope estimate

- 1 new helper file `kioskUpsellCache.js` (~30 LOC).
- ~15 LOC in `kioskCart.js` action.
- ~5 LOC in `KioskUpsellComponent.vue` (frozen — LOCK doc).
- 1 new analytics event `upsell_served_from_cache` (frontend + backend
  whitelist).

## Acceptance criteria

- First load online: cache populated, suggestions displayed.
- Subsequent offline reload (within 5 min, same cart contents):
  suggestions served from cache, analytics emits
  `upsell_served_from_cache`.
- Cold-cache offline: `skip('load_error')` (per PROPOSAL 003) emits, not
  `no_suggestions`.
- Cache invalidated on cart change (different item_ids hash) and on
  TTL expiry.

## Rollback

New file removable; store-action change reverts to PROPOSAL 003 baseline;
component file revert.
