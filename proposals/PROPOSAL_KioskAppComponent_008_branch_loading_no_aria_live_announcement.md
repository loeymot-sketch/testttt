# PROPOSAL — KioskAppComponent.vue — `kiosk-init-overlay` (branch loading + error) has no `role="status"` / `aria-live` — screen reader user gets zero announcement

**ID** : PROP-KioskAppComponent-008
**Date** : 2026-05-23
**Frozen file** : `resources/js/components/frontend/kiosk/KioskAppComponent.vue`
**Frozen reason** : Per `CLAUDE.md §7` Frozen Zones — kiosk shell component.
**Existing LOCK** : none.

## Finding (read-only audit)

Branch loading overlay at L34–39:

```html
<transition name="fade">
  <div v-if="branchLoading" class="kiosk-init-overlay">
    <div class="kiosk-init-spinner"></div>
    <p class="kiosk-init-label">{{ $t('kiosk.app.init_loading') }}</p>
  </div>
</transition>
```

Branch error overlay at L42–49:

```html
<transition name="fade">
  <div v-if="branchError && !branchLoading" class="kiosk-init-overlay kiosk-init-error">
    <div class="kiosk-init-error-icon">⚠️</div>
    <p class="kiosk-init-error-title">{{ $t('kiosk.app.service_unavailable') }}</p>
    <p class="kiosk-init-error-sub">{{ branchError }}</p>
    <button type="button" class="kiosk-init-retry-btn" @click="loadBranch">{{ $t('kiosk.app.retry') }}</button>
  </div>
</transition>
```

Neither overlay carries `role="status"`, `role="alert"`, or `aria-live` attributes. The spinner is purely visual (no `aria-busy="true"` on the parent). A screen reader user hits the borne, hears nothing for the duration of `loadBranch()` (could be 0.5–3s on slow LAN), and then either the categories screen mounts silently OR an error overlay appears silently with no announcement.

For an industrial borne where a sighted user sees the spinner / error icon, this is acceptable degradation. For an accessibility-rated borne (V2 / EAA 2025 compliance), this is a clear WAI-ARIA 1.2 gap.

Adjacent good example in this same file: L139–144 (`KioskInactivityOverlayComponent`) is documented as "Conforme WAI-ARIA 1.2 alertdialog" — the same level of care should apply to the init/error overlays.

### Personas impacted
- **Blind / low-vision customer using screen reader** (MEDIUM today on industrial borne, HIGH under EAA 2025 compliance).
- **A11y audit (axe-core, Lighthouse)** (MEDIUM — automated audits will flag "loading state announced" missing).

## Reasoning fort (multi-perspective)

### Chef perspective
No impact.

### Client perspective
Sighted: zero impact (spinner / icon are universal). Screen reader user: feels like the borne has hung; may walk away.

### Cashier perspective
A11y complaints are rare but high-severity when they arrive.

### Owner perspective
EAA 2025 (European Accessibility Act) is binding in France from 2025-06-28 for retail kiosks above a certain size threshold. Borderline; legal counsel call. The fix is trivial.

### Multi-tenant-future
A V2 SaaS tenant in a regulated market (EU, US ADA) will require this. Cheap to add now.

### Adversarial dispute (challenge yourself)
- **False positive?** Possible if a wrapping component (e.g. `ConnectionStatusBanner`) already announces "loading" — but `ConnectionStatusBanner` is for network state, not branch boot. **They're different concerns.**
- **`role="status"` vs `role="alert"`?** Use `status` for loading (polite) and `alert` for error (assertive). Don't conflate.
- **Scope-minimal?** Yes — 4 attribute additions, no JS changes.
- **Goal cares?** V1 is French market = EAA 2025 territory. Yes.

## Proposed change

```diff
     <!-- Initialisation : overlay pendant le chargement de la branche -->
     <transition name="fade">
-      <div v-if="branchLoading" class="kiosk-init-overlay">
+      <div
+        v-if="branchLoading"
+        class="kiosk-init-overlay"
+        role="status"
+        aria-live="polite"
+        aria-busy="true"
+        :aria-label="$t('kiosk.app.init_loading')"
+      >
         <div class="kiosk-init-spinner"></div>
         <p class="kiosk-init-label">{{ $t('kiosk.app.init_loading') }}</p>
       </div>
     </transition>

     <!-- Erreur critique : branche non disponible -->
     <transition name="fade">
-      <div v-if="branchError && !branchLoading" class="kiosk-init-overlay kiosk-init-error">
+      <div
+        v-if="branchError && !branchLoading"
+        class="kiosk-init-overlay kiosk-init-error"
+        role="alert"
+        aria-live="assertive"
+      >
         <div class="kiosk-init-error-icon">⚠️</div>
         <p class="kiosk-init-error-title">{{ $t('kiosk.app.service_unavailable') }}</p>
         <p class="kiosk-init-error-sub">{{ branchError }}</p>
         <button type="button" class="kiosk-init-retry-btn" @click="loadBranch">{{ $t('kiosk.app.retry') }}</button>
       </div>
     </transition>
```

Total source LOC delta : **+8 attribute lines** (no JS, no logic, no new imports).

## Risk analysis

| Scenario | Risk if applied | Risk if NOT applied |
|----------|-----------------|---------------------|
| Sighted customer | Pixel-identical UX | Pixel-identical |
| Screen reader user, loading | Hears "Chargement…" (or i18n equivalent) within 100ms | Silence — feels hung |
| Screen reader user, error | Hears "Service indisponible…" within 100ms | Silence — gives up |
| `role="alert"` over-fire | None (overlay mounts once on real error, not repeated) | N/A |
| Frozen-zone regression | NEGLIGIBLE — attribute-only, no semantic logic change | None |
| NF525 implication | NONE | NONE |

## LOCK feasibility

- ≤10 LOC, single concern? **YES (+8 attribute LOC, no JS)**
- Architectural redesign needed? **NO**
- Owner gate required? **YES (frozen file)** but borderline minimal-risk.

## Owner recommendation

- [x] **APPLY-WITH-LOCK** (recommended — EAA 2025 binding, fix is trivial, pixel-identical sighted UX)
- [ ] DEFER-V1.0.2
- [ ] DEFER-V2
- [ ] KEEP-AS-IS (acceptable only if owner confirms EAA 2025 does not apply to Le Cayenne kiosk scope, in which case this proposal becomes V2-only)

**Signed-off-by-owner** : ___________  **Date** : ___________

## References
- `CLAUDE.md §7` Frozen Zones
- File L34–49 (init / error overlays), L139–144 (sibling KioskInactivityOverlayComponent "alertdialog" precedent)
- EAA 2025 (European Accessibility Act, kiosk applicability)
- WAI-ARIA 1.2 `role="status"`, `role="alert"`
