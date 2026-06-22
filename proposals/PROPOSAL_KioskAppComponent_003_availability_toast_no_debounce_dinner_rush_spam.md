# PROPOSAL — KioskAppComponent.vue — `ItemAvailabilityChanged` toast fires unconditionally — dinner-rush could spam customer with 5–10 toasts in 30 seconds

**ID** : PROP-KioskAppComponent-003
**Date** : 2026-05-23
**Frozen file** : `resources/js/components/frontend/kiosk/KioskAppComponent.vue`
**Frozen reason** : Per `CLAUDE.md §7` Frozen Zones — kiosk shell component.
**Existing LOCK** : none.

## Finding (read-only audit)

`_handleItemAvailabilityChanged` at L662–743 dispatches `this._showToast(label, 'warning', 4500)` on every payload where `is_available === false || is_available === 0` (L708–719). There is **no debounce, no batching, no rate-limit**.

In a real dinner rush at Le Cayenne (cf. `project_wave_polish_final` — Q9 was specifically about live availability propagation), a chef who runs low on multiple ingredients could trigger 5–10 admin toggles in quick succession (algerienne out → samurai out → barbecue out → menu-classique-out, etc.). Each event lands on the kiosk and renders a 4.5-second warning toast (`KioskToastComponent` is a single-stack — if the impl queues toasts, the customer sees 5×4.5s = 22.5 seconds of consecutive warnings; if it overwrites, the customer sees only the last and missed the others).

Separately, the same handler calls `_scheduleStaleToast(payload?.id || payload?.item_id)` at L730 — which DOES debounce (800ms timer at L644). So the **stale-cart-line** toast path already has debounce, but the **mid-browse availability** toast path does NOT. Inconsistent.

### Personas impacted
- **Client-impatient** (HIGH — being bombarded with "X indisponible / Y indisponible / Z indisponible" toasts while browsing the menu is jarring and signals that the restaurant is in chaos. Direct trust-erosion.)
- **Client-50ans-presbyte** (HIGH — toasts dismiss too fast for slower readers; 5 consecutive toasts = they read 0 of them but feel the borne is "blinking errors".)
- **Cashier-multitask** (LOW — she's not at the kiosk, but customer complaints land on her.)

## Reasoning fort (multi-perspective)

### Chef perspective
Chef should be able to toggle freely without worrying about kiosk UX. The current code punishes the kiosk customer for chef-side rapid mutations, which is backwards — chef multi-toggle is a *normal* dinner-rush behavior, not an edge case.

### Client perspective
A customer browsing the menu at 19:30 wants the kiosk to feel **calm and confident**. Toast spam reads as "this kiosk is broken" or "this restaurant doesn't know what it has". A single batched toast — "3 ingrédients sont devenus indisponibles, votre menu a été mis à jour" — is far more reassuring.

### Cashier perspective
Reduced complaints to handle.

### Owner perspective
"No useless complexity V1" — but the *current* code is over-complex (one toast per event). The proposal **simplifies** UX by batching. Net complexity may actually decrease if the existing `_scheduleStaleToast` debouncer pattern is reused (already 14 LOC of debounce machinery at L638–660).

### Multi-tenant-future
A high-volume tenant (4-borne branch, 10+ chef toggles per rush) compounds the spam. Batching now scales for free.

### Adversarial dispute (challenge yourself)
- **False positive?** Possible — `KioskToastComponent` may already cap concurrent toasts at 1 with last-wins or first-wins semantics. **I did not inspect `KioskToastComponent.vue`.** If first-wins with 4.5s TTL, the customer would see only the first toast and the rest are silently dropped — that's *worse* UX (customer thinks only one item is out when actually three are).
- **A11y angle?** Each toast emits `role="alert"` (per L376 inline comment). Five rapid alerts is an axe-core failure on `aria-live` flood control.
- **Goal cares?** Q9 was about sync correctness, not toast UX. But the customer-perceived quality is a direct dependency of Q9 — silent fast sync is great; spammy fast sync is worse than silent slow sync.
- **Scope-minimal possible?** Yes — reuse the existing `_pendingStaleItemIds` / `_staleToastDebounceTimer` pattern OR add a parallel debouncer for the mid-browse toast.

## Proposed change

```diff
-      try {
-        const flipUnavailable =
-          payload.is_available === false || payload.is_available === 0;
-        if (flipUnavailable) {
-          const itemName = payload.name || payload.item_name || null;
-          const reason = payload.reason || null;
-          const label = itemName
-            ? (reason ? `${itemName} indisponible — ${reason}` : `${itemName} indisponible`)
-            : 'Un article vient de devenir indisponible';
-          this._showToast(label, 'warning', 4500);
-        }
-      } catch (_e) { /* defensive — never block menu update */ }
+      // PROP-KioskAppComponent-003: batch mid-browse availability toasts so
+      // dinner-rush chef multi-toggle cannot spam the customer. Reuses the
+      // 800ms debouncer pattern from _scheduleStaleToast for consistency.
+      try {
+        const flipUnavailable =
+          payload.is_available === false || payload.is_available === 0;
+        if (flipUnavailable) {
+          this._scheduleAvailabilityFlipToast({
+            id: payload?.id || payload?.item_id,
+            name: payload.name || payload.item_name || null,
+            reason: payload.reason || null,
+          });
+        }
+      } catch (_e) { /* defensive — never block menu update */ }
```

Plus add a new method (kept tight to mirror `_scheduleStaleToast` / `_flushStaleToast` pair):

```diff
+    _scheduleAvailabilityFlipToast(itemMeta) {
+      if (!this._pendingFlipItems) this._pendingFlipItems = new Map();
+      this._pendingFlipItems.set(itemMeta.id || `unknown_${Date.now()}`, itemMeta);
+      if (this._flipToastDebounceTimer) clearTimeout(this._flipToastDebounceTimer);
+      this._flipToastDebounceTimer = setTimeout(() => {
+        this._flushAvailabilityFlipToast();
+      }, 800);
+    },
+
+    _flushAvailabilityFlipToast() {
+      const n = this._pendingFlipItems?.size || 0;
+      this._flipToastDebounceTimer = null;
+      if (n === 0) return;
+      let label;
+      if (n === 1) {
+        const only = Array.from(this._pendingFlipItems.values())[0];
+        label = only.name
+          ? (only.reason ? `${only.name} indisponible — ${only.reason}` : `${only.name} indisponible`)
+          : 'Un article vient de devenir indisponible';
+      } else {
+        label = `${n} articles viennent de devenir indisponibles, la carte a été mise à jour`;
+      }
+      this._showToast(label, 'warning', 4500);
+      this._pendingFlipItems.clear();
+    },
```

Plus `beforeUnmount` cleanup:

```diff
+      if (this._flipToastDebounceTimer) {
+        clearTimeout(this._flipToastDebounceTimer);
+        this._flipToastDebounceTimer = null;
+      }
+      this._pendingFlipItems?.clear();
```

Total source LOC delta : **+30 / -8 = +22 net** (one inline edit + one new pair of methods + cleanup hook).

## Risk analysis

| Scenario | Risk if applied | Risk if NOT applied |
|----------|-----------------|---------------------|
| Single chef toggle | Customer sees one toast 800ms after the event (vs immediate) — imperceptible delay | Same UX |
| 3 chef toggles in 2s | Customer sees ONE batched toast "3 articles indisponibles" | Customer sees 3 toasts (or 1 last-wins, dropping context) |
| 10 chef toggles in 30s | Customer sees ONE batched toast | Toast spam — borne reads as broken |
| Frozen-zone regression | LOW — additive method + reuse of existing pattern; original logic preserved if rolled back | None |
| Memory leak | LOW — `Map.clear()` in `beforeUnmount`; same hygiene as `_pendingStaleItemIds` (verified L660) | None |
| NF525 implication | NONE | NONE |

## LOCK feasibility

- ≤30 LOC, single concern? **YES (+22 net LOC)**
- Architectural redesign needed? **NO — reuse of established debouncer pattern**
- New imports? **NO**
- Owner gate required? **YES**

## Owner recommendation

- [ ] APPLY-WITH-LOCK (acceptable — improves UX during dinner rush, low risk)
- [x] **DEFER-V1.0.2** (recommended — V1 Le Cayenne is single-borne + small menu, chef multi-toggle is rare; impact materializes at V1.0.x cloud-prep / V2 SaaS multi-borne)
- [ ] DEFER-V2
- [ ] KEEP-AS-IS

**Pre-condition** : if owner confirms `KioskToastComponent` already enqueues + caps concurrent toasts at 1 with batching semantics, this proposal is redundant. Quick read of `KioskToastComponent.vue` settles this.

**Signed-off-by-owner** : ___________  **Date** : ___________

## References
- `CLAUDE.md §7` Frozen Zones
- File L662–743 (`_handleItemAvailabilityChanged`)
- File L638–660 (`_scheduleStaleToast` / `_flushStaleToast` — pattern to reuse)
- `project_wave_polish_final_2026-05-21.md` (Q9-S1 sync context)
