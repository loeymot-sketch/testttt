# PROPOSAL — KDS Layout 5+ Orders Without Scroll

**ID**: S3-CHEF-001
**Author**: MEGA-SYSTEM AGENT S3 (round 1, GOAL ULTRA-DEEP 2026-05-23 Phase B.1)
**Created**: 2026-05-23
**Status**: Awaiting owner gate
**Severity**: P0 BLOCKER-IF-RUSH (chef-rush persona, production-real risk)
**Touch**: `resources/js/components/admin/kitchenDisplaySystem/KdsV2Grid.vue` (not in frozen-zone list, but architectural — owner gate required)

---

## 1. Problem (Owner-Mandate Verbatim Violation)

Owner mandate, transcribed from this audit cycle prompt:

> « il doit y avoir maximum 5 commandes affichées en une ligne, sinon faire 2 lignes. Chaque commande doit être lisible sans scroll. Sinon le chef sous stress va sortir une commande incomplète. »

> « chef devrait scroller parfois, il ferait pas attention il va sortir la commande pas complète »

Current state of `KdsV2Grid.vue` (verified by direct DOM measurement at 1920×1080):

| Property                       | Value                            | Source                          |
|--------------------------------|----------------------------------|---------------------------------|
| Grid `grid-template-columns`   | `repeat(4, 1fr)` ⇒ 450px × 4     | KdsV2Grid.vue:355                |
| Grid `grid-template-rows`      | `repeat(2, 1fr)` ⇒ 462px × 2     | KdsV2Grid.vue:356                |
| Max rendered cards             | `activeOrders.slice(0, 8)`       | KdsV2Grid.vue:55                 |
| Grid top offset                | 164 px (header + banner)         | DOM measurement                  |
| Grid bottom y                  | **1136 px**                      | 164 + 2×462 + 16 (gap) + 32 (pad)|
| Viewport height                | 1080 px (typical kitchen screen) |                                  |
| **Overflow**                   | **56 px below the fold**         | 1136 − 1080                      |
| Bottom-row card box            | top=658, bottom=1120, h=462      | DOM measurement                  |
| **Bottom-row "Prêt" button**   | **clipped (y ≈ 1080–1120)**      | Visual evidence                  |

Two distinct breakages from a single root cause:

1. **At 5–8 active orders**: cards render but bottom-row `Prêt` action buttons are below the fold. Chef must scroll the page to tap (owner: « ferait pas attention, il va sortir la commande pas complète »).

2. **At 9+ active orders**: `slice(0, 8)` silently drops orders 9, 10, … There is NO overflow indicator chip. Orders queued past 8 are invisible to the chef and unservable from the KDS surface until earlier ones are bumped to PREPARED.

Visual proof (in `reports/test-e2e/goal-2026-05-23/round-1/captures/`):

- `S3-R1-8orders-1920x1080-truncated.png` — 4×2 grid, 8 cards, bottom Prêt buttons clipped at fold.
- `S3-R1-6orders-1920x1080.png` — same 4×2 layout even at 7 orders, identical overflow.
- `S3-R2-long-order-1920x1080.png` — combined with R2 long-order issue (separate proposal needed for content scroll).

---

## 2. Root Cause

The V2 grid was designed for 4×2 = 8 slots ("kds/sprint-2 V-5 wrapper"). The 4K media query (`min-width: 2560px`) bumps to 5×2 = 10. But typical kitchen screens are 1080p, and the owner mandate explicitly requires:

- 1 row × 5 columns for ≤ 5 orders
- 2 rows × 5 columns for 6–10 orders
- Both layouts fit within 1080p without page scroll AND without inner-card scroll for short orders

The current code (`KdsV2Grid.vue:55`):
```html
<KdsOrderCard
  v-for="(o, idx) in activeOrders.slice(0, 8)"
  ...
/>
```

silently truncates beyond 8. There is no `+N` overflow chip and no page-scroll affordance.

---

## 3. Design Options

### Option A — Adaptive Single-Row vs Double-Row (closest match to owner verbatim)

Match owner mandate literally:

- `activeOrders.length ≤ 5` → 1 row × 5 cols. Card height ≈ 880px (single big card). Comfortable glance.
- `5 < activeOrders.length ≤ 10` → 2 rows × 5 cols. Card height ≈ 432px (≈ same as today). Fits in 1080p without overflow.
- `activeOrders.length > 10` → 2 rows × 5 cols (10 visible) + bottom-right overflow chip "+N en attente".

CSS sketch (`.kds-v2__grid--n5`, `.kds-v2__grid--n10`):

```css
.kds-v2__grid--n5 {
  grid-template-columns: repeat(5, minmax(0, 1fr));
  grid-template-rows: 1fr;
}
.kds-v2__grid--n10 {
  grid-template-columns: repeat(5, minmax(0, 1fr));
  grid-template-rows: repeat(2, minmax(0, 1fr));
}
/* row-height calculation: viewport - header(164) - footer (60 served strip if present) - padding ≈ 856 px ⇒ ÷2 rows = 428 px per row */
```

**Pros:**
- Exact match to owner verbatim.
- Single-row × 5 cards = comfortable glance for normal lunch (most days ≤ 5 in-flight).
- Predictable layout transition at the 6-order boundary.

**Cons:**
- Layout changes mid-service (5→6→5 oscillation) when chef bumps the last card. Visual jitter risk; mitigatable via 250 ms CSS transition.
- Card width at 5-col is wider than today's 4-col — text size needs to be re-tuned (or kept identical to today's card design and let extra width breathe).

### Option B — Always 5 cols, dynamic rows (smoother but breaks owner verbatim slightly)

Always render 5-col grid. Row count = `ceil(N/5)`. Card-height clamp `380 ≤ h ≤ 600`. Beyond 2 rows (>10), overflow chip.

**Pros:**
- No mid-service layout shift between 5 and 6.
- Predictable card width across all counts.

**Cons:**
- Owner explicitly said "5 max en 1 ligne sinon 2 lignes" → Option A is the verbatim match. Option B deviates by always rendering 2 rows worth of vertical space (placeholder area when N ≤ 5).

### Option C — Single-page caisse-style 5×N with sticky overflow chip (richer UX)

5-col always, ≤ 5 ⇒ 1 row + bottom served-strip kept. 6–10 ⇒ 2 rows. 10+ ⇒ overflow chip with on-tap reveal of next 5 in a horizontal carousel/drawer.

**Pros:**
- Scales beyond 10 without abandoning the queue.
- Chef can flick through carousel during real-rush.

**Cons:**
- More complexity, more bundle weight, more sentinel surface area.
- Carousel discovery friction = potentially same issue owner warned about.

---

## 4. Recommendation

**Option A**, with:

1. `kds-v2__grid` becomes `kds-v2__grid kds-v2__grid--n5` (≤5) OR `kds-v2__grid--n10` (6–10).
2. Replace `slice(0, 8)` with `slice(0, 10)`.
3. Add overflow chip `+{N − 10} commande(s) en attente` floating bottom-right when N > 10. Tap = no-op for V1 (V1.0.2 can add carousel).
4. Add CSS transition `transition: grid-template-rows .2s ease, grid-template-columns .2s ease` to soften the 5→6 layout shift.
5. Keep the existing 4K (`min-width: 2560px`) breakpoint intact for chains with bigger displays — at 2560+ width, card heights at 5-col layout are even more comfortable.
6. Compute row-height available: `viewport.height − 164 (header) − 60 (served strip, conditional) − 32 (padding) = ~824 px ÷ 2 rows = ~412 px / row`. Card content must still fit ≤ 8 short items without scroll (separate proposal for long orders, S3-CHEF-003).

---

## 5. Out-of-Scope (Tracked Separately)

This proposal addresses **slot count + grid layout**. Two adjacent issues live in companion findings, NOT in this proposal:

- **S3-CHEF-002**: Overflow chip when N > rendered slots. (This proposal *adds* the chip, so it overlaps — but the chip placement decision is here, the semantic of "+N en attente" wording belongs to S3-CHEF-002.)
- **S3-CHEF-003**: Long-order (15+ items) content does NOT fit within a single card height even after grid layout fix. Card-body `overflow-y: auto` (KdsOrderCard.vue:574) remains. Owner mandate is also against inside-card scroll. Needs separate UX decision (modal expansion / shrink-to-fit / hybrid).

---

## 6. Files Touched (when implementation greenlit)

| File                                                                                       | Change kind                       |
|--------------------------------------------------------------------------------------------|-----------------------------------|
| `resources/js/components/admin/kitchenDisplaySystem/KdsV2Grid.vue`                          | template + CSS + computed         |
| `resources/lang/fr/label.php`                                                              | `kds_overflow_chip_n_pending` key |
| `resources/lang/en/label.php` (if maintained)                                              | same key                          |
| `resources/lang/ar/label.php` (if maintained, RTL)                                         | same key                          |
| `tests/Unit/Frontend/Vue/Kds/KdsV2GridLayout.spec.js` (new vitest)                          | layout class + slice math         |
| `tests/e2e/admin/kds/kds-layout-overflow.spec.js` (Playwright)                              | 5/6/10/11 order scenarios @1080p  |

No frozen-zone file touched. No backend migration. No NF525 invariant impacted.

---

## 7. Rollback

Single Vue commit. Revert restores 4×2 layout. localStorage `kds.v2_enabled='0'` rollback path (existing) bypasses the V2 grid entirely.

---

## 8. Verification Plan (post-implement)

- Vitest `KdsV2GridLayout.spec.js`: assert correct class applied at N=0,1,5,6,10,11. Assert overflow chip rendered iff N>10.
- Playwright `kds-layout-overflow.spec.js`:
  - Seed 5 orders, viewport 1920×1080, assert `grid.bottom < viewport.height` AND `cards.length === 5`.
  - Seed 6 orders, assert `grid.bottom < viewport.height` AND `cards.length === 6` (in 2-row layout).
  - Seed 11 orders, assert `cards.length === 10` AND overflow chip text contains "1".
  - All scenarios: `document.documentElement.scrollHeight === window.innerHeight` (no page scroll).
- Visual: re-capture R1 at 5 / 6 / 10 / 11 orders. Read each PNG ⇒ no clipping, all CTAs above fold.
- A11y: re-run axe-core on KDS surface — no `scrollable-region-focusable` regression.

---

## 9. Owner Sign-Off Block

| Approver | Date | Option chosen (A / B / C / custom) | Notes |
|----------|------|------------------------------------|-------|
|          |      |                                    |       |

Awaiting owner countersign before implementation. Per MEGA-AGENT mandate, this is a layout architectural change ⇒ NOT auto-applied.
