# PROPOSAL — KioskWizardComponent.vue — Hidden scrollbar on `.kiosk-step-content` + `.kiosk-live-composition-list` (discoverability + claustrophobic persona)

**ID** : PROP-KWZ-009
**Author** : PROPOSAL AGENT (Phase B.5)
**Date** : 2026-05-23
**Status** : Awaiting owner gate
**Severity** : **P1 borderline P2** — Direct persona impact (50-ans presbyote + claustrophobe + impatient), no functional break but real UX risk.
**Frozen file** : `resources/js/components/frontend/kiosk/KioskWizardComponent.vue`
**Touch** : ≤10 LOC across `<style scoped>` lines 2528-2529 + 2752-2755 + 2845-2853.

---

## 1. Finding (read-only audit)

Three vertical/horizontal scroll-containers in the wizard **hide their scrollbar UI**:

**`.kiosk-step-visuals`** (lines 2518-2529) — horizontal scroll of step thumbnails:
```css
overflow-x: auto;
scrollbar-width: none;
```
```css
.kiosk-step-visuals::-webkit-scrollbar { display: none; }
```

**`.kiosk-live-composition-list`** (lines 2745-2755) — horizontal scroll of selected-options chips:
```css
overflow-x: auto;
scrollbar-width: none;
```
```css
.kiosk-live-composition-list::-webkit-scrollbar { display: none; }
```

**`.kiosk-step-content`** (lines 2845-2853) — vertical scroll of the current step's body (sauce list, viande grid, garniture grid, etc.):
```css
flex: 1;
overflow-y: auto;
scrollbar-width: none;
```
```css
.kiosk-step-content::-webkit-scrollbar { display: none; }
```

The intent is design-minimalism (per `feedback_design_flat_organized.md`). The cost: **no visual cue that content scrolls**.

For step content (vertical) on a 1080-tall borne with a long viande list (e.g. 6+ items) or a long sauce list (Le Cayenne catalog has 13 canonical sauces per Wave Y), the bottom items are below the fold AND the customer has no scroll indicator.

For the composition-chip strip (horizontal) when 5+ chips are selected, the chips overflow right with no indicator.

For the step-visuals strip (horizontal) when 6+ wizard steps are active (composer profile), the future steps are hidden right.

**The persona impact verbatim from CLAUDE.md and the GOAL brief**:

> "Client-impatient persona primary (50 ans, claustrophobic, mal aux pieds, faim)"

A 50-year-old presbyote will **NOT** discover hidden scroll content. A claustrophobic customer feels trapped if they can't see the affordance "there's more". A hungry impatient customer abandons.

This is essentially the **same architectural issue as the KDS chef-rush mandate** (`PROPOSAL_KDS_LAYOUT_5plus_orders_S3-CHEF-001.md`):

> "Chaque commande doit être lisible sans scroll. Sinon le chef sous stress va sortir une commande incomplète."

The same logic applies to the wizard customer: **chaque step doit être lisible sans scroll, sinon le customer sous stress va abandonner ou choisir l'incomplet.**

---

## 2. Why this matters

### Persona impact — client-impatient (the PRIMARY persona for this audit)
**HIGH.** Every step where content overflows the viewport without visible scrollbar = an abandonment risk OR an "I'll just take the default" partial-composition. Direct revenue impact.

### Chef perspective
None (chef sees KDS, not the wizard).

### Cashier perspective
None — cashier uses POS wizard (separate `pos-wizard.js`).

### Owner perspective
"No useless complexity V1" — visible scrollbars are NOT complexity, they're an affordance. Their absence is **complexity that customer must overcome**.

### WCAG 2.1
SC 2.4.3 Focus Order, SC 2.4.7 Focus Visible: scroll containers with hidden overflow indicators force users to discover content by accident. Keyboard users (Tab-only) can scroll via Tab, but the visual cue is absent.

### V2 SaaS readiness
Each SaaS tenant ships their own catalog size — some will have 5 sauces, some will have 50. The component is fragile to catalog growth without explicit scroll indicators.

---

## 3. Adversarial dispute

- **False positive? Is the customer really blocked?**
  - The kiosk borne is touch-only. Touch users instinctively swipe up/down to scroll. They might discover the overflow even without a visible scrollbar.
  - **Counter**: 50-year-old presbyotes don't always have touch instincts — many treat the borne like a kiosk-as-screen (tap-only). Empirical observation in the GOAL brief explicitly calls this out.

- **False positive? The "live composition chip" strip has horizontal scroll, would a customer ever exceed 4 chips visible?**
  - Tacos Famille has: taille + pain + 4 viandes + 2 sauces + 5 garnitures + 2 supplements + menu = **15 chips**. Visible chips at one time on 1080-wide kiosk = ~4-5. **Overflow is real.**

- **Goal cares?** V1 production-ready: YES, this is the primary persona's primary pain point.
- **Scope-minimal?** YES — see Option A.

---

## 4. Proposed change

### Option A (RECOMMENDED) — Show subtle scrollbars + add fade-edge gradient affordance

```diff
   .kiosk-step-content {
     flex: 1;
     overflow-y: auto;
     background: transparent;
-    scrollbar-width: none;
+    scrollbar-width: thin;
+    scrollbar-color: var(--kiosk-border-strong, #D9C9B8) transparent;
     padding: 0 8px 8px;
   }

-  .kiosk-step-content::-webkit-scrollbar { display: none; }
+  .kiosk-step-content::-webkit-scrollbar {
+    width: 6px;
+  }
+  .kiosk-step-content::-webkit-scrollbar-track {
+    background: transparent;
+  }
+  .kiosk-step-content::-webkit-scrollbar-thumb {
+    background: var(--kiosk-border-strong, #D9C9B8);
+    border-radius: 999px;
+  }
```

Same treatment to `.kiosk-step-visuals` and `.kiosk-live-composition-list` (horizontal — show 4px-tall scrollbar with same color rule).

**Add visual fade-edge gradient** to indicate horizontal overflow:

```diff
   .kiosk-live-composition-list {
     ...
+    /* [PROP-KWZ-009] Right-edge fade gradient hints overflow without
+       relying on visible scrollbar (which would steal vertical space on
+       a 42px-min-height row). */
+    -webkit-mask-image: linear-gradient(90deg, #000 calc(100% - 32px), transparent);
+            mask-image: linear-gradient(90deg, #000 calc(100% - 32px), transparent);
   }
```

**Total**: ~16 LOC CSS change.

### Option B — Affordance arrows at the edges (clickable to scroll)

More invasive — adds DOM nodes. Better discoverability but bigger LOC. **V1.0.2 batched UX wave.**

### Option C — Keep hidden, add a "more below ↓" hint chip when overflow detected

Requires JS to compute `scrollHeight > clientHeight`. ~25 LOC. **V1.0.2.**

---

## 5. Risk analysis

| Scenario | Option A | KEEP-AS-IS |
|----------|----------|------------|
| 50-year-old customer w/ long catalog | Sees scrollbar, discovers content | Abandons or partial composition |
| Brand minimalism | Slightly less flat — but scrollbar is 4-6px thin, brand-colored | Pure flat |
| Frozen-zone diff | ~16 LOC CSS | NONE |
| Existing visual tests | One golden master may need re-baseline | NONE |

---

## 6. LOCK feasibility

16 LOC CSS scoped — moderate. **LOCK_KIOSK_WIZARD_SCROLLBAR_2026-05-23.md** lightweight.

---

## 7. Verification plan

- Re-capture wizard screen at 1080-portrait with long viande list → scrollbar visible.
- Re-capture wizard with 5+ chips → fade gradient visible on right edge.
- axe-core: no new warning.
- Frozen-zone diff = 16 LOC.

---

## 8. Owner sign-off

- [ ] APPLY-WITH-LOCK Option A (recommended — visible scrollbars + fade gradient)
- [ ] DEFER-V1.0.2 (batch with PROP-KWZ-005 a11y wave)
- [ ] KEEP-AS-IS (accept the persona-impact risk)

**Signed** : ___________ **Date** : ___________

---

## 9. References

- WCAG 2.1 SC 1.4.13 Content on Hover or Focus
- WCAG 2.1 SC 2.4.3 Focus Order
- `proposals/PROPOSAL_KDS_LAYOUT_5plus_orders_S3-CHEF-001.md` — analogous chef-rush "lisible sans scroll" mandate
- `feedback_design_flat_organized.md`
