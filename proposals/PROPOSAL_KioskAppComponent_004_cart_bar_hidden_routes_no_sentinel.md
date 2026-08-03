# PROPOSAL — KioskAppComponent.vue — `showCartBar` hard-coded route allowlist with no sentinel — new routes silently regress

**ID** : PROP-KioskAppComponent-004
**Date** : 2026-05-23
**Frozen file** : `resources/js/components/frontend/kiosk/KioskAppComponent.vue`
**Frozen reason** : Per `CLAUDE.md §7` Frozen Zones — kiosk shell component.
**Existing LOCK** : none.

## Finding (read-only audit)

`showCartBar` at L254–273 returns `!hiddenRoutes.includes(this.$route.name)` with `hiddenRoutes` being a 9-entry hardcoded array of kiosk route names. The inline comment (cluster-3 E-002/E-003 fix 2026-05-10) documents that `kiosk.cash-instruction` and `kiosk.loyalty` were added retroactively because the floating cart-bar overlapped page-local CTAs.

This is a **brittle pattern**:
1. Whenever a new kiosk route is added with its own primary CTA/total at the bottom of the viewport, the cart-bar will overlap silently until manually visually-tested.
2. There is no unit test or sentinel that locks the relationship between "route has bottom-fixed CTA" → "route in hiddenRoutes".
3. The router definition file (`resources/js/router/kiosk.js` or similar) is the natural source of truth, but `showCartBar` does not consult any per-route meta flag — it hardcodes the names.

A cleaner pattern: each route declares `meta.kioskHideCartBar: true` (similar to the existing `meta.kioskStableShell` used at L284) and `showCartBar` reads `!this.$route.meta?.kioskHideCartBar`. Adding a new route forces the dev to think about the cart-bar at definition time, not after a regression report.

### Personas impacted
- **Client-impatient** (LOW per occurrence, HIGH cumulatively — overlap regressions degrade trust slowly).
- **Dev / future-contributor** (HIGH — invisible footgun for whoever adds the next kiosk route).

## Reasoning fort (multi-perspective)

### Chef perspective
No direct impact.

### Client perspective
A customer whose CTA is hidden under a 100px-tall floating cart-bar cannot proceed. Direct conversion blocker. The 2026-05-10 fix already saw this happen *twice* (E-002 + E-003) — proof the pattern recurs.

### Cashier perspective
She receives the "I can't tap pay" complaint.

### Owner perspective
"No useless complexity V1" — but the proposed pattern is *less* complex than the current one. A single `meta.kioskHideCartBar` field on each route is closer to the existing `meta.kioskStableShell` convention. Net complexity decrease.

### Multi-tenant-future
A V2 SaaS tenant could add custom kiosk screens (e.g. a survey, a delivery-confirmation modal route). If they don't know about the magic string list in this file, they will hit the same regression.

### Adversarial dispute (challenge yourself)
- **False positive?** Possible — if the kiosk router is final-locked (no new routes anticipated), the brittle list is acceptable. But owner roadmap (V1.0.1+ + V2) does plan new screens (cf. `project_v1_0_1_hardening_2026-05-17`).
- **Sentinel-only fix?** Alternative: add a unit/Vitest assertion that every route with `data-testid="kiosk-bottom-cta"` is in `hiddenRoutes`. Lower-touch but more brittle (requires DOM crawl).
- **Goal cares?** V1 cares about not regressing E-002/E-003. The cleanest forward-protection is route-meta.

## Proposed change

```diff
     showCartBar() {
-      // [cluster-3 E-002/E-003 fix 2026-05-10] Cart-bar is hidden on routes
-      // that already provide their own primary CTA / total. Adding
-      // `kiosk.cash-instruction` closes E-002 P0 (the floating "Mon panier
-      // 23,70€" no longer overlaps the "Montant à régler 22,50€" CTA on the
-      // cash-instruction screen) and `kiosk.loyalty` closes E-003 P1 (the
-      // cart-bar no longer overlaps the loyalty form input).
-      const hiddenRoutes = [
-        'kiosk.idle',
-        'kiosk.categories',
-        'kiosk.cart',
-        'kiosk.payment',
-        'kiosk.waiting',
-        'kiosk.confirmation',
-        'kiosk.upsell',
-        'kiosk.loyalty',
-        'kiosk.cash-instruction',
-      ];
-      return !hiddenRoutes.includes(this.$route.name);
+      // PROP-KioskAppComponent-004: read `meta.kioskHideCartBar` from the
+      // router definition so adding a new route forces a per-route decision
+      // instead of silently regressing E-002 / E-003 style overlaps.
+      // Fallback to historical hardcoded list (kept identical) for any route
+      // whose meta is not yet annotated.
+      if (this.$route.meta?.kioskHideCartBar === true) return false;
+      if (this.$route.meta?.kioskHideCartBar === false) return true;
+      const legacyHiddenRoutes = [
+        'kiosk.idle', 'kiosk.categories', 'kiosk.cart', 'kiosk.payment',
+        'kiosk.waiting', 'kiosk.confirmation', 'kiosk.upsell',
+        'kiosk.loyalty', 'kiosk.cash-instruction',
+      ];
+      return !legacyHiddenRoutes.includes(this.$route.name);
     },
```

Plus a router-side annotation in `resources/js/router/kiosk*.js`:

```diff
+ // PROP-KioskAppComponent-004: explicit per-route flag. Default-undefined
+ // falls through to KioskAppComponent.showCartBar legacy allowlist for
+ // back-compat — but new routes MUST set this explicitly.
   {
     path: '/kiosk/idle',
     name: 'kiosk.idle',
+    meta: { kioskHideCartBar: true },
     component: () => import(...)
   },
   // ... etc for each route (9 entries to annotate)
```

Total source LOC delta : **+12 in component / +9 in router = +21 net** (single concern, additive, dual fallback layer).

## Risk analysis

| Scenario | Risk if applied | Risk if NOT applied |
|----------|-----------------|---------------------|
| Existing 9 routes | Behavior preserved by legacy fallback even if router-meta annotation forgotten | None |
| New route added with bottom CTA | Dev sees `meta` field, sets `true` → cart-bar hidden | Silent regression until visual audit |
| Frozen-zone regression | LOW — additive logic, dual fallback, original behavior preserved | None |
| Touches router file | YES — router file may not be in §7 frozen-zone (verify) | None |
| NF525 implication | NONE | NONE |

## LOCK feasibility

- ≤25 LOC, single concern? **YES (+21 net LOC, dual-file)**
- Architectural redesign needed? **NO — additive meta layer + fallback**
- Owner gate required? **YES** (KioskAppComponent.vue is frozen). Router file may or may not be — confirm.

## Owner recommendation

- [ ] APPLY-WITH-LOCK
- [x] **DEFER-V1.0.2** (recommended — V1 is shippable as-is; this is forward-protection, not a current bug. The cluster-3 regressions are already fixed inline. Best applied when next kiosk route is needed.)
- [ ] DEFER-V2
- [ ] KEEP-AS-IS (acceptable — risk is dormant until next route addition)

**Signed-off-by-owner** : ___________  **Date** : ___________

## References
- `CLAUDE.md §7` Frozen Zones
- File L254–273 (`showCartBar`), L282–290 (`meta.kioskStableShell` precedent)
- Cluster-3 E-002 / E-003 fixes (cited inline)
