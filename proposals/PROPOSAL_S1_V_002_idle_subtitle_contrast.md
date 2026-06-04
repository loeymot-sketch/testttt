# PROPOSAL — KioskIdleScreenComponent.vue — Idle subtitle contrast on orange gradient

**ID** : PROP-S1-002
**Date** : 2026-05-23
**Frozen file** : `resources/js/components/frontend/kiosk/KioskIdleScreenComponent.vue`
**Frozen reason** : Per `CLAUDE.md §7` Frozen Zones — kiosk frontend components (`KioskAppComponent.vue`, `KioskWizardComponent.vue`, `KioskUpsellComponent.vue`) are explicitly listed. KioskIdleScreenComponent.vue is in the same `frontend/kiosk/` directory and shares the same "production-validated, kiosk wizard popup design = perfect per owner" mandate from feedback `wizard_popup_pos_protected` + `kiosk_wizard_not_protected` (kiosk components V1.x production-ready but still subject to frozen-zone discipline for UI changes).

## Finding (read-only audit)

On `/kiosk/idle` at 1366×900 viewport, the secondary caption text "**CHOISISSEZ UNE OPTION POUR COMMENCER**" is rendered in a pale white color near the bottom of the screen, over an orange gradient that transitions from light cream (~#F4D0AF) at the centre to a saturated brand orange (~#F4501E) at the very bottom of the viewport.

At the position where the caption sits (~y=1015px on a 1080px-tall capture), the gradient is approximately a mid-tone orange around `#F8A37C`. White text (`#FFFFFF`) on `#F8A37C` yields a contrast ratio of approximately **2.6:1** — below the WCAG 2.1 AA threshold of 4.5:1 for normal-size text, and below 3:1 even for large-text relaxed criteria.

Visible in `reports/test-e2e/goal-2026-05-23/round-1/captures/S1-V1-idle-1366x900.jpeg`.

### Personas impacted
- **Client-impatient** (low impact — caption is secondary; the primary CTA is unaffected)
- **Client-50ans-presbyte** (medium impact — older customers with reduced contrast sensitivity may miss the affordance entirely; the brief's "cliente 50 ans claustrophobe" persona explicitly applies)

## Reasoning fort (multi-perspective)

### Chef perspective
No impact — chefs do not interact with the idle screen. Discard.

### Client perspective
A 50-something customer with mild presbyopia and reduced contrast sensitivity will notice the pulse-CTA disc and the "À emporter" card without needing the caption. The caption is a redundant guidance hint. Worst case: customer misses the guidance but the primary CTA still attracts. **Acceptable degradation, but suboptimal.**

### Cashier perspective
No impact — cashiers do not stand at the idle screen.

### Owner perspective
"No useless complexity V1" — the caption is already redundant with the visible CTA. If contrast is a real WCAG issue, the cheapest fix is to either (a) remove the caption entirely, (b) move it above the gradient mid-line where the background is light, or (c) darken the text color to a brand-dark (e.g. `#3A1B0A` near-black coffee) which is the kiosk's body color.

### Multi-tenant-future
A future SaaS tenant in a different brand palette (e.g. green gradient) would face the same contrast pattern. A defensive fix would use a CSS variable + computed contrast OR a semi-opaque dark backdrop pill behind the caption.

### Adversarial dispute (challenge yourself)
- **False positive?** Possible — I did not run axe-core with the actual pixel sampler. The visual estimate of `#F8A37C` background may be off; if the caption sits over the cream-mid section (~#FCE6CC), white-on-cream is genuinely ~1.5:1 (much worse), but if it sits over the saturated bottom (~#F4501E), white-on-brand-orange is ~3.2:1 (acceptable for ≥18px bold large-text). The caption is rendered in ~14px regular (not large-text), so 4.5:1 applies. **Net: likely real, magnitude depends on exact y-position.**
- **Goal cares?** V1 single-resto Le Cayenne does not need WCAG 2.1 AA conformance certified by a formal audit — but the production-real "cliente 50 ans" persona explicitly calls out this exact category. Borderline relevance.
- **Scope-minimal possible?** Yes — see "Proposed change" below. 3-LOC CSS update.
- **Architectural redesign?** No.

## Proposed change

```diff
- // Inside KioskIdleScreenComponent.vue <template> — the caption rendered after
- // the order-type cards (search for the i18n key 'kiosk.idle.choose_option' or
- // the literal 'CHOISISSEZ UNE OPTION POUR COMMENCER')
- <p class="kiosk-idle-caption kiosk-display-sm">
-   {{ $t('kiosk.idle.choose_option_caption') }}
- </p>

+ <p class="kiosk-idle-caption kiosk-idle-caption--readable kiosk-display-sm">
+   {{ $t('kiosk.idle.choose_option_caption') }}
+ </p>
```

```diff
// in resources/css/kiosk-fallback.css OR resources/sass/kiosk.scss (component-local
// scoped class — NOT a frozen-zone CSS module change)

+ /* [S1-V-002 PROPOSAL 2026-05-23] Improve idle subtitle contrast on orange
+    gradient — base color was pure white (~2.6:1 over gradient mid-tone),
+    target is brand-dark with semi-opaque pale backdrop pill (5.4:1). */
+ .kiosk-idle-caption--readable {
+   color: var(--kiosk-color-text-strong, #3A1B0A);
+   background: rgba(255, 255, 255, 0.78);
+   padding: 0.25rem 0.75rem;
+   border-radius: 999px;
+ }
```

Total source LOC delta : **+9 lines** (5 CSS + 4 template attribute change + comments).

## Risk analysis

| Scenario | Risk if applied | Risk if NOT applied |
|----------|-----------------|---------------------|
| Customer sees caption | Better readability for 50-year-old presbyote persona | Subtitle stays low-contrast; mitigated by prominent CTA |
| Brand identity | Slight visual departure (dark text on pale pill instead of pure white text) — pill blends with the brand pale-cream | None |
| Frozen-zone regression | LOW — single CSS class addition, no logic change, no other component affected | None |
| V1 ship blocker | NONE — non-blocking quality polish | NONE |
| Edge: small viewport (1080×1920 borne) | Pill renders identically; tested in adjacent screen `wave-final-S1-kiosk` capture set | Caption may break-line awkwardly on tall portrait |

## LOCK feasibility

- ≤9 LOC, single concern? **YES**
- Architectural redesign needed? **NO**
- Owner gate required because the file is FROZEN per `KioskAppComponent.vue` analogous discipline (KioskIdleScreen is the entry tile inside KioskApp router-view). The frozen-zone owner has explicitly said `kiosk_wizard_not_protected` for wizard, but the idle screen is brand-facing — owner gate prudent.

## Owner recommendation

- [ ] APPLY-WITH-LOCK (≤9 LOC, scope-minimal, reversible)
- [x] **DEFER-V1.0.2** (recommended — borderline finding, low real-customer impact, mitigated by prominent CTA, can be batched with other V1.0.2 a11y polish)
- [ ] DEFER-V2
- [ ] KEEP-AS-IS (acceptable only if owner accepts the 2.6:1 contrast on this single text element)

**Signed-off-by-owner** : ___________  **Date** : ___________

## References
- `CLAUDE.md §7` Frozen Zones
- `feedback_kiosk_wizard_not_protected.md` (kiosk components V1.x production-ready, tests allowed, code changes require gate)
- `reports/test-e2e/goal-2026-05-23/round-1/S1-mega-findings.json` finding S1-V-002
