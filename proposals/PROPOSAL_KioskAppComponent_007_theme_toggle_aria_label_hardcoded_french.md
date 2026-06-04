# PROPOSAL — KioskAppComponent.vue — Theme toggle `aria-label` is hardcoded French — bypasses $t() i18n; RTL/AR variant unsupported

**ID** : PROP-KioskAppComponent-007
**Date** : 2026-05-23
**Frozen file** : `resources/js/components/frontend/kiosk/KioskAppComponent.vue`
**Frozen reason** : Per `CLAUDE.md §7` Frozen Zones — kiosk shell component.
**Existing LOCK** : none.

## Finding (read-only audit)

`themeToggleAriaLabel` computed at L300–304:

```js
themeToggleAriaLabel() {
  return this.themeMode === 'dark'
    ? 'Passer en mode clair'
    : 'Passer en mode sombre';
},
```

These two strings are **hardcoded French literals**, not `$t()`-routed. Consequences:

1. The kiosk shell-level a11y label bypasses the i18n system used everywhere else in the kiosk (cf. L37, L45, L46, L47, L62, L77–78, L92–93 all use `$t()`).
2. The RTL/AR variant of the kiosk (referenced via `[dir="rtl"]` CSS at L1304–1307, L1466–1469, and `applyKioskA11yFromStore` at L184/L331) inherits the French label — screen reader users in Arabic mode hear French.
3. `ADR-007 / iter15-P1a` (cited inline at L182, L474–478) declared kiosk runtime "FR-immutable", which would *justify* hardcoding French — but the RTL CSS hooks suggest at least the *visual* RTL is supported, creating a contract gap.

The fix is trivial: add `kiosk.app.theme_toggle_to_light` / `kiosk.app.theme_toggle_to_dark` i18n keys and route the computed through `$t()`.

### Personas impacted
- **Screen reader user (any locale)** (MEDIUM — they hear a French label out of context; semantic value preserved if their reader can pronounce FR, broken if not).
- **A11y audit / WCAG 2.1 success criterion 3.1.2 "Language of Parts"** (LOW — the page lang is FR, but if AR mode is later turned on, this label becomes a language-of-parts violation).

## Reasoning fort (multi-perspective)

### Chef perspective
No impact.

### Client perspective
Sighted French customer: no impact (the visual ☀/☾ glyph is universal). Sighted AR customer (V2 SaaS): the language mismatch on the screen-reader-only label feels neglected; minor trust signal.

### Cashier perspective
No impact.

### Owner perspective
"FR-immutable runtime" is the stated policy (ADR-007). If that holds, this proposal is moot — *but* the RTL CSS hooks elsewhere contradict the policy. The cheapest reconciliation is to honor `$t()` everywhere and let the future locale-switch (if ever enabled) work transparently.

### Multi-tenant-future
V2 SaaS multi-tenant: a US tenant deploying the kiosk in English will need `$t()` here. Cheaper to fix once than to discover at integration time.

### Adversarial dispute (challenge yourself)
- **False positive?** Borderline — if FR-immutable is *truly* the policy and the RTL CSS is dead code / aspirational, then the hardcoded label is consistent. **However**, `applyKioskA11yFromStore` at L184 reads `kioskSettings` for `lang` and `dir`, and L331 calls it on mount. There IS at least a partial RTL pipeline. So the contract is not coherent.
- **Goal cares?** V1 ships FR-only, so the practical impact is zero. The proposal is purely about contract coherence.
- **Scope-minimal?** Yes — 4 LOC.

## Proposed change

```diff
     themeToggleAriaLabel() {
-      return this.themeMode === 'dark'
-        ? 'Passer en mode clair'
-        : 'Passer en mode sombre';
+      // PROP-KioskAppComponent-007: route through $t() for contract coherence
+      // with the rest of the kiosk shell (every other a11y label in this file
+      // uses $t()). Falls back to French literal if the key is missing.
+      const key = this.themeMode === 'dark'
+        ? 'kiosk.app.theme_toggle_to_light'
+        : 'kiosk.app.theme_toggle_to_dark';
+      const localized = this.$t(key);
+      // If $t returned the key itself (no translation found), fall back to FR.
+      if (localized === key) {
+        return this.themeMode === 'dark' ? 'Passer en mode clair' : 'Passer en mode sombre';
+      }
+      return localized;
     },
```

Plus the i18n keys (out of this file, in `resources/lang/fr/kiosk.php` or equivalent JSON):

```diff
+ // in fr.json / kiosk.app subtree
+ "theme_toggle_to_light": "Passer en mode clair",
+ "theme_toggle_to_dark":  "Passer en mode sombre",
```

Total source LOC delta in *this* file : **+8 / -3 = +5 net**. Plus 2 i18n entries per supported locale (initially FR only).

## Risk analysis

| Scenario | Risk if applied | Risk if NOT applied |
|----------|-----------------|---------------------|
| FR-only deployment, keys present | Localized label identical to current hardcode | Hardcoded label, no $t routing |
| FR-only deployment, keys missing | Fallback to hardcoded literal — pixel-identical UX | N/A |
| AR deployment (V2 hypothetical) | Localized AR label | French label bleeding into AR runtime |
| Frozen-zone regression | NEGLIGIBLE — additive logic, behavior preserved | None |
| NF525 implication | NONE | NONE |

## LOCK feasibility

- ≤10 LOC, single concern? **YES (+5 net LOC + 2 i18n keys)**
- Architectural redesign needed? **NO**
- Owner gate required? **YES (frozen file)** — borderline given the additive nature.

## Owner recommendation

- [ ] APPLY-WITH-LOCK
- [x] **DEFER-V1.0.2** (recommended — V1 FR-immutable, practical impact zero, defer to next polish pass)
- [ ] DEFER-V2
- [ ] KEEP-AS-IS (acceptable if owner reaffirms ADR-007 FR-immutable as binding and accepts the contract gap with RTL CSS)

**Signed-off-by-owner** : ___________  **Date** : ___________

## References
- `CLAUDE.md §7` Frozen Zones
- File L300–304 (`themeToggleAriaLabel`), L184 / L331 (`applyKioskA11yFromStore` — RTL pipeline reference), L1304–1307 (`[dir="rtl"]` CSS hooks)
- ADR-007 / iter15-P1a (cited inline at L182)
