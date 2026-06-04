# PROPOSAL — KioskAppComponent.vue — Floating `kiosk-cart-bar` does not respect `env(safe-area-inset-bottom)` — overlap risk on touch-borne with home indicator

**ID** : PROP-KioskAppComponent-005
**Date** : 2026-05-23
**Frozen file** : `resources/js/components/frontend/kiosk/KioskAppComponent.vue`
**Frozen reason** : Per `CLAUDE.md §7` Frozen Zones — kiosk shell component.
**Existing LOCK** : none.

## Finding (read-only audit)

`.kiosk-cart-bar` CSS at L1393–1416 uses `bottom: 24px`. The production borne (typical 1080×1920 portrait industrial Android-on-USB touch screen) does not have a visible OS-level home indicator, so this is *currently safe* on the Le Cayenne hardware target. However:

1. If the kiosk runs in PWA / Chrome kiosk mode on any iPad-class tablet for V1.0.x / V2 deployments, iOS Safari renders a ~34px home indicator that overlaps `bottom: 24px` content.
2. The 9-route hiddenRoutes guard does NOT cover *transitional* states (slide-down enter/leave at L1483–1484) — during the 0.3s transition, the cart-bar may briefly render at native position even on hidden routes.
3. No `padding-bottom: env(safe-area-inset-bottom)` or `bottom: calc(24px + env(safe-area-inset-bottom))` defense.

This is purely preventative; no current customer hits it on the Le Cayenne hardware. But the kiosk's positioning math (L1393–1414) is the single source of truth for the cart-bar's bottom anchor — fixing it here cascades to all future hardware variants.

### Personas impacted
- **Client-impatient** (LOW today on industrial borne, MEDIUM on tablet-class V2 deployments — overlap on home indicator reduces tappable region by ~34px).
- **A11y / touch-target** (LOW — 24px is comfortable on industrial borne).

## Reasoning fort (multi-perspective)

### Chef perspective
No impact.

### Client perspective
On current Le Cayenne hardware: zero impact. On a iPad-deployed kiosk in V2: cart-bar overlaps the home indicator, customer's tap on the bar bottom edge sometimes triggers iOS home gesture instead.

### Cashier perspective
No impact.

### Owner perspective
Pure preventative hardening. Adds 1 LOC of `env()` defense. No cost.

### Multi-tenant-future
At V2 SaaS, tenant hardware varies. A single defensive `env()` token avoids per-tenant CSS hacks.

### Adversarial dispute (challenge yourself)
- **False positive?** For V1 Le Cayenne industrial borne, YES — this is dormant. The proposal is genuinely V1.0.x+ forward-protection.
- **Goal cares?** "No useless complexity V1" — this is a 1-LOC CSS additive. The "complexity" is one `env()` token that most modern frontend CSS already uses.
- **Scope-minimal?** Yes — single CSS rule change.

## Proposed change

```diff
 .kiosk-cart-bar {
   position: absolute;
-  bottom: 24px;
+  /* PROP-KioskAppComponent-005: respect device safe-area on tablet-class
+      deployments (iPad, iOS PWA). On industrial borne the env() resolves
+      to 0px so no current-hardware behavior change. */
+  bottom: calc(24px + env(safe-area-inset-bottom, 0px));
   inset-inline-start: 50%;
   ...
 }
```

Optional symmetric add on `.kiosk-offline-conflict-cta` (L1370–1390, also `position: absolute` at `top: 92px`) — top-side does not generally need safe-area on borne.

Total source LOC delta : **+1 / -1 = 0 net** (single CSS value, +3 LOC comment).

## Risk analysis

| Scenario | Risk if applied | Risk if NOT applied |
|----------|-----------------|---------------------|
| V1 Le Cayenne industrial borne | `env()` resolves to `0px` — pixel-identical | Pixel-identical |
| V1.0.x iPad PWA deployment | Cart-bar lifted above home indicator — overlap fixed | Bottom edge of bar partially overlaps home indicator |
| Browsers not supporting `env()` (none in production target) | Fallback `0px` — pixel-identical | N/A |
| Frozen-zone regression | NEGLIGIBLE — single CSS value, fallback identical to current | None |
| NF525 implication | NONE | NONE |

## LOCK feasibility

- ≤5 LOC, single concern? **YES (+1 actual code LOC)**
- Architectural redesign needed? **NO**
- Owner gate required? **YES (frozen file)** — but borderline given the changeset is one number widened with a CSS function.

## Owner recommendation

- [ ] APPLY-WITH-LOCK
- [x] **DEFER-V1.0.2** (recommended — V1 hardware doesn't need it; bundle with other minor CSS hardening when next polish pass lands)
- [ ] DEFER-V2
- [ ] KEEP-AS-IS (acceptable for V1 ship)

**Signed-off-by-owner** : ___________  **Date** : ___________

## References
- `CLAUDE.md §7` Frozen Zones
- File L1393–1416 (`.kiosk-cart-bar`)
- MDN `env(safe-area-inset-bottom)`
