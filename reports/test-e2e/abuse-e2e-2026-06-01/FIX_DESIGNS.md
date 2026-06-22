# FIX_DESIGNS — abuse-e2e-2026-06-01 (verified read-only agent designs, 2026-06-02)

> Exact, contrast-proven diffs prepared while Round 1 D/E/F captured. APPLY ONLY
> after Round 1 (A-F) fully completes (editing KDS/kiosk components + rebuild mid-round
> would flake the running captures). All targets confirmed NOT frozen (§7 = Wizard/App/
> Upsell + PaymentComponent + PosV5TrancheRow + pos-wizard vanilla only).

## FIX-1 — A-001 (P1, BLOCKING) kiosk idle text contrast
File: `resources/js/components/frontend/kiosk/KioskIdleScreenComponent.vue` (NOT frozen)
Root cause (corrected): NO hero image — the "light hero" is a CSS gradient
`linear-gradient(180deg,#FFFFFF,#FFE8DD 55%,#F4501E)` (tokens-bold.css:277-284 forces
`.kiosk-idle-fallback`→that gradient and `.kiosk-idle-overlay`→transparent in light mode).
So cream text (#FFF5E8 / rgba(255,245,232,.88)) measures ~1.0:1. **Title ALSO fails**
(1.078:1) — not just subtitle. One scrim fixes title+subtitle+tap-hint together.

**Edit A — add `::before` scrim after `.kiosk-idle-content` rule (~L404-416):**
```css
.kiosk-idle-content::before {
  content: '';
  position: absolute;
  inset: -4% -6%;
  z-index: -1;
  background-color: rgba(15, 12, 10, 0.62);   /* flat color → survives AAA background-image:none */
  border-radius: 40px;
  -webkit-mask-image: radial-gradient(ellipse 75% 70% at 50% 50%, #000 55%, transparent 100%);
          mask-image: radial-gradient(ellipse 75% 70% at 50% 50%, #000 55%, transparent 100%);
  pointer-events: none;
}
```
(`.kiosk-idle-content` already has position:relative + z-index:2, so the scrim sits behind
its own children but above the z=0/z=1 background siblings.)

**Edit B — subtitle opaque (L476):** `color: rgba(255,245,232,0.88)` → `color: #FFF5E8;`
(keep the text-shadow as belt-and-braces; update the L477-481 comment to cite A-001 scrim).

CONTRAST PROOF (text #FFF5E8 over scrim rgba(15,12,10,.62) composited):
- light gradient worst stop #FFFFFF → effective rgb(106,104,103) → **5.119:1 PASS**
- mid #FFE8DD → 5.692:1 PASS · bottom #F4501E → 10.535:1 PASS
- dark fallback #1A1410 → 17.674:1 PASS (improved, no regression)
- tap-hint (.85 alpha) worst ≈4.6:1 PASS (set to #FFF5E8 for symmetry → 5.119:1)
Risk: none — no DOM/template change, scrim is absolute ::before, no opacity animation,
pointer-events:none keeps tap working, AAA-safe (background-color not image), reduced-motion safe.

## FIX-2 — C-003 (P2, free) KDS allergen pill contrast (FOOD-SAFETY)
File: `resources/js/components/admin/kds/KdsOrderCard.vue` (NOT frozen; reviewer's "KdsV2Card.vue" was WRONG)
L542: `background: #EA580C;` → `background: #C2410C;`  (white-on-#C2410C = **5.18:1 PASS**, was 3.56)
Optional L548 halo: `rgba(234,88,12,0.30)` → `rgba(194,65,12,0.30)` (consistency, no contrast impact)
#EA580C/#C2410C NOT brand colors → brand-safe.

## FIX-3 — C-004 (P2, free) KDS overflow-chip text contrast (brand bg kept)
File: `resources/js/components/admin/kds/KdsV2Grid.vue` (NOT frozen)
L443: `color: white;` → `color: #1A1A1A;`  (#1A1A1A on brand #F4501E = **4.98:1 PASS**, was 3.49)
Brand bg #F4501E UNCHANGED. `.kds-overflow-chip__icon` (L454-458) has no color → inherits → one edit covers both.

## FIX-4 — C-002 (P2) KDS overflow-chip overlaps LOCAL banner tag (2-file)
chip = `KdsV2Grid.vue:437` (absolute top:16 right:16 z:100); tag = `KdsStatusBanner.vue:191` (child cmp).
Naive chip reposition REJECTED (top:48 would overlap slot-D allergen pill). Instead reserve a right
gutter on the banner only when overflow active:
- `KdsV2Grid.vue` template (<KdsStatusBanner> ~L22-29): add `:reserve-right-gutter="overflowActiveCount > 0"`
- `KdsStatusBanner.vue`: declare prop `reserveRightGutter: { type: Boolean, default: false }`; add class
  `{ 'kds-banner--reserve-gutter': reserveRightGutter }` on root (L18); scoped CSS:
  `.kds-banner--reserve-gutter { padding-right: 152px; }` (chip ~120px + right:16 + gap)
⚠️ 152px is an ESTIMATE of i18n-dependent chip width → MUST visually confirm in fix-wave (§6/§13).
Lower priority than FIX-1/2/3; apply only if visual confirmation is cheap this round.

## FIX-5 — adjacent (NOT cited by audit, free, interactive) KDS recall button contrast
File: `resources/js/components/admin/kds/KdsHistoryDrawer.vue:680` `.kds-history-drawer__recall-btn`
#ffffff on #F4501E ~14.4px/700 = ~3.49:1 (same white-on-brand pattern, but it's an interactive button).
Fix: `color: #ffffff` → `color: #1A1A1A` (passes AA on brand, no brand change). Include in C-004 cluster.

## FIX CLUSTERS (parallel, disjoint files):
- Cluster 1 (kiosk): {KioskIdleScreenComponent.vue} — FIX-1
- Cluster 2 (kds): {KdsOrderCard.vue, KdsV2Grid.vue, KdsStatusBanner.vue, KdsHistoryDrawer.vue} — FIX-2/3/4/5
Then: `npm run dev` rebuild → commit (explicit `git add` per file, NEVER `git add .`) → Round 2 re-capture.
