# PROPOSAL — KioskAppComponent.vue — `reviewCatalogChangedCart` uses raw `document.querySelector` + setTimeout(0) — fragile cross-component focus management

**ID** : PROP-KioskAppComponent-017
**Date** : 2026-05-23
**Frozen file** : `resources/js/components/frontend/kiosk/KioskAppComponent.vue`
**Frozen reason** : Per `CLAUDE.md §7` Frozen Zones — kiosk shell component.
**Existing LOCK** : none.

## Finding (read-only audit)

`reviewCatalogChangedCart()` at L604–619:

```js
reviewCatalogChangedCart() {
  this.dismissCatalogChangeToast();
  this.goToCart();
  this.$nextTick(() => {
    setTimeout(() => {
      const panel = document.querySelector('[data-testid="kiosk-cart-root"], .kiosk-cart');
      if (!panel || typeof panel.focus !== 'function') {
        return;
      }
      if (!panel.hasAttribute('tabindex')) {
        panel.setAttribute('tabindex', '-1');
      }
      panel.focus({ preventScroll: true });
    }, 0);
  });
},
```

Concerns:
1. **Reaches across components** — the shell directly DOM-queries `[data-testid="kiosk-cart-root"], .kiosk-cart`, which couples the shell to a child component's internal markup. If the cart component renames its root selector, this silently breaks.
2. **`$nextTick` + `setTimeout(0)` chain** — combines Vue's tick queue with the browser task queue. This works empirically but is fragile; on slower hardware the cart child may not be fully mounted by the time the timeout fires. No retry / `MutationObserver`.
3. **Mutates the queried element** — adds `tabindex="-1"` if absent. The cart child component should own its own focus contract; if it later adds `tabindex` itself, this shell-side mutation becomes dead code.
4. **No focus return** — when the customer dismisses the catalog change toast and reviews the cart, they may not realize keyboard focus jumped. WAI-ARIA recommends managing the focus *back* to the prior trigger on dismissal — but this method only manages forward focus.

Cleaner pattern: the cart component itself exposes a `setFocus()` method via `defineExpose` (Vue 3) / `ref`, and the shell calls `this.$refs.cartView?.setFocus()`. This keeps the cart's focus contract local.

### Personas impacted
- **Keyboard / screen reader user** (MEDIUM — focus may not land on the cart if DOM query fails; user has to find the cart manually).
- **A11y audit** (LOW — current behavior likely passes axe-core; the gap is more about robustness than conformance).
- **Future maintainer** (HIGH — cross-component DOM query is a footgun for renames).

## Reasoning fort (multi-perspective)

### Chef perspective
No impact.

### Client perspective
Sighted: irrelevant. AT: minor — they tab through to find the cart after dismissing the toast.

### Cashier perspective
No impact.

### Owner perspective
This is more of an architectural smell than a bug. V1 ships fine. Cleanup deferred.

### Multi-tenant-future
Same — at V2 SaaS, cleaner contracts amortize.

### Adversarial dispute (challenge yourself)
- **False positive?** Borderline — the `setTimeout(0)` + `$nextTick` combo IS a common Vue pattern for "wait for the next route component to mount". Not strictly wrong.
- **Production stability?** Has been in for some cycles and not broken. Honesty: this is polish, not a bug.
- **Goal cares?** No, not at V1.
- **Scope-minimal?** Yes if redesigned to expose `setFocus()` from cart.

## Proposed change

(Recommended for a future architectural pass, not for V1 ship.)

Option A (low-risk inline hardening):

```diff
   reviewCatalogChangedCart() {
     this.dismissCatalogChangeToast();
     this.goToCart();
     this.$nextTick(() => {
-      setTimeout(() => {
-        const panel = document.querySelector('[data-testid="kiosk-cart-root"], .kiosk-cart');
-        if (!panel || typeof panel.focus !== 'function') {
-          return;
-        }
-        if (!panel.hasAttribute('tabindex')) {
-          panel.setAttribute('tabindex', '-1');
-        }
-        panel.focus({ preventScroll: true });
-      }, 0);
+      // PROP-KioskAppComponent-017: retry up to 5 frames if cart mount is
+      // slow (e.g. low-end industrial borne CPU). Still uses DOM query but
+      // tolerates timing variability.
+      let frames = 0;
+      const tryFocus = () => {
+        const panel = document.querySelector('[data-testid="kiosk-cart-root"], .kiosk-cart');
+        if (panel && typeof panel.focus === 'function') {
+          if (!panel.hasAttribute('tabindex')) panel.setAttribute('tabindex', '-1');
+          panel.focus({ preventScroll: true });
+          return;
+        }
+        if (++frames < 5) requestAnimationFrame(tryFocus);
+      };
+      requestAnimationFrame(tryFocus);
     });
   },
```

Option B (clean architectural pass — out of single-proposal scope):

Refactor cart component to expose `setFocus()` via `ref`, shell calls `this.$refs.cartView.setFocus()`.

Total source LOC delta : **+8 / -7 = +1 net** for Option A.

## Risk analysis

| Scenario | Risk if applied | Risk if NOT applied |
|---|---|---|
| Cart mounts within 1 frame | Same UX, slightly more code | Works today |
| Cart mounts slowly | Up to 5 frame retries (~80ms) — focus still lands | DOM query returns null, focus never lands |
| Cart child renames root selector | Same break as today (still uses selector) — Option B is the real fix | Same break |
| Frozen-zone regression | LOW — single method body change, additive retry | None |
| NF525 implication | NONE | NONE |

## LOCK feasibility

- ≤10 LOC, single concern? **YES (+1 net LOC for Option A)**
- Architectural redesign needed? **YES for Option B, NO for Option A**
- Owner gate required? **YES (frozen file)** — but borderline for Option A.

## Owner recommendation

- [ ] APPLY-WITH-LOCK (Option A acceptable as cheap hardening)
- [ ] DEFER-V1.0.2
- [x] **DEFER-V2** (recommended — V1 has not broken on this; the cleanup is architectural. Pair with broader Vue 3 / Composition API migration in V2.)
- [ ] KEEP-AS-IS (acceptable — pattern is stable in production)

**Signed-off-by-owner** : ___________  **Date** : ___________

## References
- `CLAUDE.md §7` Frozen Zones
- File L604–619 (`reviewCatalogChangedCart`)
- Vue 3 `defineExpose` / `ref.value.setFocus()` pattern (architectural reference)
