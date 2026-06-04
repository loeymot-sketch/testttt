# PROPOSAL — KioskAppComponent.vue — Offline-conflict CTA button (`Voir`) has no `aria-describedby` linking to entry count — screen reader user has no context

**ID** : PROP-KioskAppComponent-020
**Date** : 2026-05-23
**Frozen file** : `resources/js/components/frontend/kiosk/KioskAppComponent.vue`
**Frozen reason** : Per `CLAUDE.md §7` Frozen Zones — kiosk shell component.
**Existing LOCK** : none.

## Finding (read-only audit)

The offline-conflict CTA at L100–109:

```html
<button
  v-if="showOfflineConflictCta"
  type="button"
  class="kiosk-offline-conflict-cta"
  data-testid="kiosk-offline-conflict-cta"
  @click="openOfflineConflictModal"
>
  Voir
</button>
```

A screen reader user hears only "Voir, button". They have **zero context** for:
- What there is to "see"
- How many entries (`offlineConflictEntries.length` — known to the component)
- Why this CTA exists (post-offline sync conflict, requiring manual resolution)

Compare with the well-annotated cart-bar at L60–63 which sets `:aria-label="cartBarAriaLabel"` (a composite "Mon panier, N articles, Total X €"). The pattern is established in this file — but not applied here.

Additionally, the CTA's text content is the hardcoded French "Voir" (cf. PROP-015 for the i18n gap).

### Personas impacted
- **Screen reader user** (MEDIUM — they cannot decide whether to tap the CTA without context).
- **WCAG 2.1 4.1.2 / 2.4.6** (Name, Role, Value + Headings & Labels — "Voir" is too generic to satisfy 2.4.6 for a critical action).

## Reasoning fort (multi-perspective)

### Chef perspective
No impact.

### Client perspective
Sighted: irrelevant (they see the bright orange pill at top of screen and intuit "tap to investigate"). AT user: opaque.

### Cashier perspective
A11y complaint chain.

### Owner perspective
Trivial fix, fits the established `aria-label` pattern.

### Multi-tenant-future
EAA 2025.

### Adversarial dispute (challenge yourself)
- **False positive?** Marginal — "Voir" + button role IS technically labeled, just not informatively. Strict WCAG passes; spirit of 2.4.6 fails.
- **Goal cares?** EAA 2025 yes; V1 ship not blocking.
- **Scope-minimal?** Yes — 2 LOC.

## Proposed change

```diff
   <button
     v-if="showOfflineConflictCta"
     type="button"
     class="kiosk-offline-conflict-cta"
     data-testid="kiosk-offline-conflict-cta"
+    :aria-label="offlineConflictCtaAriaLabel"
     @click="openOfflineConflictModal"
   >
     Voir
   </button>
```

Plus a computed:

```diff
+    offlineConflictCtaAriaLabel() {
+      const n = this.offlineConflictEntries?.length || 0;
+      // PROP-KioskAppComponent-020: composite label so screen-reader users
+      // know what tapping "Voir" will open. Pattern follows `cartBarAriaLabel`
+      // at L249–253 — see also PROP-015 for full $t() routing.
+      return n === 1
+        ? `Voir la commande en attente de résolution`
+        : `Voir les ${n} commandes en attente de résolution`;
+    },
```

Total source LOC delta : **+8 net** (1 attribute + 7 computed).

## Risk analysis

| Scenario | Risk if applied | Risk if NOT applied |
|---|---|---|
| Sighted | Pixel-identical | Pixel-identical |
| Screen reader | Informative label | "Voir, button" — opaque |
| `offlineConflictEntries` empty | Edge case avoided: CTA only renders when `showOfflineConflictCta` is true (typically also non-empty) | Same |
| Frozen-zone regression | NEGLIGIBLE — attribute + computed; no logic change | None |
| NF525 implication | NONE | NONE |

## LOCK feasibility

- ≤10 LOC, single concern? **YES (+8 net LOC)**
- Architectural redesign needed? **NO**
- Owner gate required? **YES (frozen file)** — borderline minimal.

## Owner recommendation

- [x] **APPLY-WITH-LOCK** (recommended — EAA 2025-relevant, fits established pattern, trivial)
- [ ] DEFER-V1.0.2 (acceptable if owner bundles with PROP-007 + PROP-015 + PROP-008 as a single "a11y sweep" mini-LOCK)
- [ ] DEFER-V2
- [ ] KEEP-AS-IS (acceptable for V1 ship only if owner confirms EAA 2025 not in scope)

**Signed-off-by-owner** : ___________  **Date** : ___________

## References
- `CLAUDE.md §7` Frozen Zones
- File L100–109 (CTA button), L249–253 (`cartBarAriaLabel` precedent)
- WCAG 2.1 2.4.6 (Headings & Labels), 4.1.2 (Name, Role, Value)
