# PROPOSAL — KioskAppComponent.vue — Top-of-screen indicators (offline, abandoned, conflict-CTA, connection-status, theme-toggle) stack at hardcoded fixed offsets — collisions inevitable on narrow viewports

**ID** : PROP-KioskAppComponent-014
**Date** : 2026-05-23
**Frozen file** : `resources/js/components/frontend/kiosk/KioskAppComponent.vue`
**Frozen reason** : Per `CLAUDE.md §7` Frozen Zones — kiosk shell component.
**Existing LOCK** : none.

## Finding (read-only audit)

Five distinct elements compete for the top region of the kiosk viewport:

| Element | Position (CSS) | Lines |
|---|---|---|
| `ConnectionStatusBanner` | `top: 14px; left: 50%` (centered) | L11 + style L1201–1218 |
| `kiosk-offline-indicator` | `top: 12px; inset-inline-start: 50%` (centered) | L73–81 + style L1310–1327 |
| `kiosk-abandoned-indicator` (when alone) | `top: 12px; inset-inline-start: 50%` (centered) | L84–97 + style L1342–1360 |
| `kiosk-abandoned-indicator` (when offline also visible) | `top: 52px` via `kiosk-abandoned-below-offline` | L88, L1362–1364 |
| `kiosk-offline-conflict-cta` | `top: 92px; inset-inline-start: 50%` | L100–109 + style L1370–1390 |
| `kiosk-theme-toggle` | `top: 24px; inset-inline-start: 24px` (top-left) | L22–31 + style L1272–1307 |

The stacking logic is **ad-hoc and hardcoded**:
- The abandoned indicator has a single `:class="{ 'kiosk-abandoned-below-offline': offlinePending > 0 }"` toggle. But what if `ConnectionStatusBanner` is *also* visible? No defense.
- `kiosk-offline-conflict-cta` is anchored at `top: 92px` — works if abandoned + offline are both visible, but if only conflict-cta is showing (no offline, no abandoned), it sits at 92px with empty space above. Visually weird but not breaking.
- All three centered indicators use `inset-inline-start: 50%; transform: translateX(-50%)` — they would render at the *same* coordinates if simultaneously visible.

Worst case (real, not theoretical): network blip during dinner rush → `ConnectionStatusBanner` is "reconnecting" + customer has 1 offline-pending order + customer abandoned a previous attempt → THREE centered banners overlap at top: 12–14px. The visual is broken.

### Personas impacted
- **Client-impatient** (MEDIUM — confusing visual signal during the worst possible moment, network blip + rush).
- **Cashier-multitask** (LOW — she sees the visual chaos and adds it to the complaint list).

## Reasoning fort (multi-perspective)

### Chef perspective
No impact.

### Client perspective
A kiosk that visually breaks under stress is the worst kind of trust signal.

### Cashier perspective
Indirect.

### Owner perspective
The simplest fix is a vertical stack container: one `<div class="kiosk-indicator-stack">` that takes all three centered banners and `display: flex; flex-direction: column; gap: 8px;`. Each child sits naturally below the previous. The hardcoded `top` math evaporates.

### Multi-tenant-future
Same — at V2 SaaS, additional banners (e.g. promo, kitchen-busy) will compound. A stack container amortizes.

### Adversarial dispute (challenge yourself)
- **False positive?** Probability that all three banners co-occur is low (network is usually stable, and abandoned + offline are correlated to the same event). But "rarely" doesn't justify the brittle math.
- **Visual regression risk?** Substantial — the proposal restructures the template. Visual test mandate (CLAUDE.md §6) applies.
- **Goal cares?** V1 Le Cayenne single-borne — borderline. V1.0.x cloud-prep — yes.
- **Scope-minimal?** Yes if done conservatively (one wrapper div, three children, no other changes).

## Proposed change

```diff
+    <!-- PROP-KioskAppComponent-014: vertical stack so multiple top-of-screen
+         indicators flow naturally instead of colliding at fixed top offsets.
+         The connection banner stays self-contained (its own positioning),
+         the three customer-visible kiosk banners share this stack. -->
+    <div class="kiosk-indicator-stack" v-if="anyKioskIndicatorVisible">
       <transition name="slide-down">
         <div v-if="offlinePending > 0" class="kiosk-offline-indicator">
           ...
         </div>
       </transition>

       <transition name="slide-down">
         <div
           v-if="offlineAbandoned > 0"
           class="kiosk-abandoned-indicator"
-          :class="{ 'kiosk-abandoned-below-offline': offlinePending > 0 }"
         >
           ...
         </div>
       </transition>

       <transition name="slide-down">
         <button
           v-if="showOfflineConflictCta"
           type="button"
           class="kiosk-offline-conflict-cta"
           ...
         >
           Voir
         </button>
       </transition>
+    </div>
```

Plus computed:

```diff
+    anyKioskIndicatorVisible() {
+      return this.offlinePending > 0 || this.offlineAbandoned > 0 || this.showOfflineConflictCta;
+    },
```

Plus CSS (replace absolute positioning on the three children with stack-relative):

```diff
+ .kiosk-indicator-stack {
+   position: absolute;
+   top: 12px;
+   left: 50%;
+   transform: translateX(-50%);
+   z-index: 200;
+   display: flex;
+   flex-direction: column;
+   align-items: center;
+   gap: 8px;
+   max-width: calc(100vw - 32px);
+ }
+ /* Children inside the stack no longer need their own positioning. */
- .kiosk-offline-indicator {
-   position: absolute;
-   top: 12px;
-   inset-inline-start: 50%;
-   transform: translateX(-50%);
-   z-index: 200;
-   ...
- }
+ .kiosk-indicator-stack > .kiosk-offline-indicator,
+ .kiosk-indicator-stack > .kiosk-abandoned-indicator,
+ .kiosk-indicator-stack > .kiosk-offline-conflict-cta {
+   position: static;
+   transform: none;
+ }
- .kiosk-abandoned-below-offline { top: 52px; }
```

Total source LOC delta : **+15 net** in template + computed + CSS, **-3** in CSS (kill `kiosk-abandoned-below-offline`). Net ≈ +12.

## Risk analysis

| Scenario | Risk if applied | Risk if NOT applied |
|----------|-----------------|---------------------|
| One indicator visible | Same visual position (top: 12px, centered) | Same |
| Two indicators | Stack naturally with 8px gap | Stack via hardcoded 52px offset (current "below-offline" rule) |
| Three indicators | Stack naturally — no collision | Collision at top: 12px / 52px / 92px (partial overlap if any banner is tall) |
| ConnectionStatusBanner co-visible | Still its own positioning — separate concern | Same |
| Frozen-zone regression | HIGH — template restructure on a frozen file; visual test mandate (CLAUDE.md §6) MANDATORY | None |
| NF525 implication | NONE | NONE |

## LOCK feasibility

- ≤30 LOC, single concern? **BORDERLINE (+12 net, but template restructure)**
- Architectural redesign needed? **MINIMAL — wrapper div**
- Owner gate required? **YES — frozen file + visual test mandate**
- Test mandatory? **YES — Playwright capture × at least 3 viewports**

## Owner recommendation

- [ ] APPLY-WITH-LOCK (acceptable but visual-test heavy)
- [x] **DEFER-V1.0.2** (recommended — V1 Le Cayenne probability of 3-banner collision is low; defer to broader visual polish pass with mandatory capture round)
- [ ] DEFER-V2
- [ ] KEEP-AS-IS (acceptable for V1 ship)

**Signed-off-by-owner** : ___________  **Date** : ___________

## References
- `CLAUDE.md §6` Visual Test Mandate + §7 Frozen Zones
- File L11, L73–109, L1201–1390 (multiple indicator definitions)
