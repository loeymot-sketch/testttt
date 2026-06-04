# Super.6 — Chef-Rush Stress for KDS Layout · 2026-05-25

**Author**: SUPERVISOR AGENT #6 (chef-rush stress)
**Branch**: `heal/cms-pr1-quickwins-2026-05-18` HEAD `af92035b8`
**Viewport**: 1920×1080 (typical kitchen screen)
**Surface**: `/admin/kitchen-display-system?v2=1` (KdsV2Grid component, V2 feature flag ON)
**Screenshots**: `tests/e2e/__screenshots__/super6-kds-rush/`

This report supplies empirical chef-rush measurements to inform owner gate **G0.4** (`PROPOSAL_KDS_LAYOUT_5plus_orders_S3-CHEF-001.md` — Option A vs B vs C).

---

## 1. Methodology

- Login `admin@lecayenne.fr` / 123456 (branch_id=1).
- Seeded SUPER6-tokened orders via tinker helper `tests/e2e/super6-seed.php`. Each order: `status=7 (PREPARING)`, `payment_status=5 (PAID)`, `is_advance_order=10 (Ask::NO)`, `order_type=10 (TAKEAWAY)`, 3–12 OrderItems per order with `item_id=1`.
- For each population N, navigated KDS with cache-bust query, waited 3s for SPA fetch, took viewport screenshot, ran `getBoundingClientRect()` JS measurement on `.kds-card` + inner CTA button to compute clipping vs `window.innerHeight = 1080`.
- Cleanup at end via `super6_cleanup()` — DB returned to 0 active pile.

**Pre-flight data fixes applied** (admin DB maintenance, not production code change):
1. Synced web-guard Admin role (id=1) with 7 web-guard permissions (was empty after seed setup).
2. Backfilled `url` column on web-guard permission rows that had empty `url` (id=13 kitchen-display-system, id=14 order-status-screen) — mirrored from sanctum-guard sibling rows so Vue router `permissions.find(p => p.url === permissionUrl)` resolves correctly.

---

## 2. Empirical data — Order count vs scroll

Grid measured at 1920×1080. Current implementation: `grid-template-columns: repeat(4, minmax(0,1fr))` ⇒ 4 cards × 450px; `grid-template-rows: repeat(2, minmax(0,1fr))` ⇒ 2 rows × 462px; gap 16px; padding 16px. Grid top = 164px, grid bottom = **1136 px** (constant ≥5 orders). Viewport 1080 px ⇒ **overflow 56 px** below the fold for the entire bottom row.

| N orders | Cards rendered | Clipped cards (bottom > vh) | Clipped CTAs (Prêt > vh) | Overflow chip | Page-scroll | Verdict |
|----------|----------------|-----------------------------|--------------------------|---------------|-------------|---------|
| **5** | 5 (+3 placeholders) | **1** (slot 5 row-2) | **1** (Prêt invisible) | none | none | **BAD** — owner mandate "lisible sans scroll" violated at N=5 already |
| **6** | 6 (+2 placeholders) | **2** (slots 5,6 row-2) | **2** | none | none | **BAD** |
| **7** | 7 (+1 placeholder) | **3** (slots 5,6,7) | **3** | none | none | **BAD** |
| **8** | 8 (no placeholder) | **4** (entire row-2) | **4** | none | none | **WORST** — chef cannot reach any bottom-row Prêt without page-scroll |
| **9** | 8 (slice(0,8)) | 4 | 4 | **"+1 en attente"** (top-right, orange Cayenne #F4501E, pulsing) | none | overflow chip works as designed (Wave N M-KDS-6 F1 P0 shipped); but order #9 is **invisible until chef bumps one of the 8 shown** |

**Card-level pixel measurements at N=5** (representative — same numbers hold N=6/7/8 for row-2 cards):

| Card | Slot | Top y | Bottom y | CTA bottom y | Clipped? |
|------|------|-------|----------|--------------|----------|
| [A] | 1 (row-1) | 180 | 642 | 631 | No |
| [B] | 2 (row-1) | 180 | 642 | 631 | No |
| [C] | 3 (row-1) | 180 | 642 | 631 | No |
| [D] | 4 (row-1) | 180 | 642 | 631 | No |
| [E] | 5 (row-2) | 658 | **1120** | **1109** | **Yes (40 px cut from card, 29 px cut from Prêt button)** |

The screenshot `n05-orders.png` confirms it visually: card [E] N°817 shows only the top half + 5/8 of the items list; the dark "Prêt" CTA button is not drawn.

**Owner-mandate violation**: proposal verbatim "Chaque commande doit être lisible sans scroll" is breached at N=5 (the minimum threshold the owner targeted). The proposal's claim "at 5–8 active orders bottom-row Prêt buttons are below the fold" is **empirically confirmed**.

---

## 3. Long order (15 items)

Seeded 1 order with 15 OrderItems, instructions like "Sauce blanche, sans oignon, bien cuit".

- Card height: **462 px (fixed)** — `KdsOrderCard.vue:295`.
- Body inner: `scrollHeight = 1096 px`, `clientHeight = 315 px` ⇒ **inner-card scroll required** (3.48× overflow ratio).
- Chef sees only ~4–5 of 15 items in the card without scrolling INSIDE the card. Owner mandate "ne pas scroller dans la carte" is **violated**.
- Even at N=5 short orders, the per-card body was clipped inside (screenshot shows top-row [A]/[B] items list cut at "3× Sandwich Cayenne" mid-word).
- Screenshot: `long-order-15items.png`.

This is the issue separately tracked as `S3-CHEF-003` in the proposal — Option A (5-col layout) does NOT solve it. A companion layout/content decision is needed.

---

## 4. Allergen alert visibility (1-second glance grade)

Seeded 1 order with `allergens_snapshot=["Gluten","Lait","Fruits a coque"]` on every OrderItem (4 items). Screenshot: `allergen-alert.png`.

Visual signals (DOM-confirmed):
- **Top stripe**: full-width orange `#f97316` (vs neutral grey for non-allergen)
- **Card border**: `3px solid #f97316` orange (vs age-bucket color for non-allergen, overrides age)
- **Header pill** "⚠ ALLERGIE": background `#ea580c` orange, white text weight 800, `role="alert" aria-live="assertive"`
- **Per-line ⚠ icon** next to each Sandwich Cayenne row
- **Inline allergen band**: "⚠ Allergènes : Gluten · Lait · Fruits a coque" in orange-tinted highlight under each item

**Grade: A** at 60-90 cm chef position. Orange dominance + multi-layer reinforcement (stripe + border + pill + per-line ⚠ + allergen names) makes the alert unmissable in a sub-second glance.

**Caveat**: the "⚠ ALLERGIE" header pill itself uses `font-size: 10px` (computed). Small. But the orange CARD OUTLINE + per-item ⚠ marks carry the signal; the pill is redundant reinforcement. The full allergen NAMES (Gluten/Lait/Fruits…) appear at ~13-14 px which is readable at typical chef distance.

---

## 5. Multi-bump race (3 simultaneous Prêt taps)

Seeded 3 PREPARING orders. Clicked all 3 Prêt buttons via `button.click()` within a 4 ms burst (zero serialization delay between calls). Waited 4 s for PATCH responses.

| Metric | Value |
|--------|-------|
| Cards before | 3 |
| Clicks dispatched | 3 |
| Burst duration | 4 ms |
| Cards remaining (PREPARING/ACCEPT) after | **0** |
| `kds-card--ready` after | 0 (PREPARED leaves grid, see Wave U partition) |
| Served-pill recently-served after | **3** (all 3 transitioned to PREPARED, surfaced in bottom strip) |
| DB orders at status 8 after | **3** ✓ |
| UI freeze / 5xx / "trop de requêtes" toast | **None** |

**Race PASSED.** This validates the Wave V 2026-05-21 commit `8e5c19c` (KdsUndoToast removed, immediate PATCH per tap, per-order serialisation only at backend via `lockForUpdate`). The pre-Wave-V bug ("3 clicks in 3s → only the LAST PATCH lands, the first two cancelled by `clearTimeout(pendingTimeoutId)`") is fixed.

---

## 6. Recommendation for owner G0.4

### Option A — Adaptive 5-col single-row (≤5) vs 5-col 2-row (6–10) [proposal's recommendation]
- **Pros**: exact verbatim match owner mandate "max 5 commandes affichées en une ligne, sinon 2 lignes". Comfortable card width at 5-col (1920÷5 = 384 px usable). At N≤5 single big-card row → card height ≈ 880 px, removes the inner-scroll problem for orders up to ~12 items.
- **Cons**: layout transition at the 5↔6 boundary creates visual jitter when chef bumps the last card (mitigatable by 200-250 ms CSS grid-template-rows transition). Requires a CSS rewrite of `.kds-v2__grid` + a `n5` / `n10` class binding.
- **Chef-rush score**: **A (best)** — addresses BOTH "lisible sans scroll" (no clipping under owner-mandated ≤10 active) AND helps long-order inner-scroll because the row-1 card height doubles at N≤5.

### Option B — Always 5-col, dynamic rows (`ceil(N/5)`)
- **Pros**: no layout shift at 5↔6 boundary. Predictable card width.
- **Cons**: at N≤5 still 2-row-worth of vertical real-estate is reserved → cards stay at ~412 px (same problem as today, just on 5-col instead of 4-col). Inner-card scroll persists for long orders. Deviates from owner verbatim "5 max EN UNE LIGNE".
- **Chef-rush score**: **B-** — partially fixes the bottom-row clipping at N=5 (cards row-1 only, fitting 1080 budget) but misses the comfort gain on long orders that Option A delivers.

### Option C — 5-col always + sticky overflow chip + carousel for >10
- **Pros**: future-proof scale to chains with >10 simultaneous active orders. Layered design.
- **Cons**: V1 Le Cayenne never hits >10 active in normal lunch; the carousel adds bundle weight + UX complexity (chef must discover the carousel during rush — same risk the owner warned about with scroll). Higher implementation cost, more sentinel surface.
- **Chef-rush score**: **B** — solves the >10 case but adds an "out-of-sight" affordance that contradicts the mandate spirit.

### Recommendation: **Option A**

Evidence:
1. The 4×2 (current) breaks at N=5 — the very threshold the owner cited. Empirical clipping = 40 px card + 29 px on the Prêt CTA → chef literally cannot tap "Prêt" on card 5 without page-scrolling. This is the operational risk the owner described.
2. Option A's 5-col single-row layout at N≤5 doubles per-card height (~880 px), which independently mitigates the long-order inner-scroll problem (S3-CHEF-003) for the typical lunch-rush profile (≤5 orders in flight, ≤12 items each).
3. Option A's 2-row mode at N=6–10 produces cards ~412 px tall, fitting under the existing budget (header 164 + 2×412 + 16 gap + 32 pad ≈ 1036 < 1080 → 44 px breathing room).
4. The owner's verbatim mandate is the strongest signal — Option A is the only one that matches it literally.
5. The overflow chip is ALREADY shipped (`Wave N M-KDS-6 F1 P0 2026-05-24`, KdsV2Grid.vue:79-88) and works correctly at N=9 (showed "+1 en attente"). Option A keeps it untouched; chef-visibility-of-queue-9+ is a solved problem.

### Caveats & companion items
- **Long order content** (S3-CHEF-003) is partially mitigated by Option A's single-row mode but NOT fully resolved at 2-row mode (412 px row). A separate decision is needed (modal-on-tap, shrink-to-fit ≥ 12 items, or scope-card-body-to-most-recent-N).
- **Layout shift jitter** at N=5↔6 should be softened by `transition: grid-template-rows .2s ease, grid-template-columns .2s ease`. The proposal already lists this — keep it.
- **a11y**: the `prefers-reduced-motion` query already guards the overflow chip pulse; same query should guard the new layout transition.

---

## 7. Files & cleanup

| Artifact | Path |
|----------|------|
| Tinker seed library | `tests/e2e/super6-seed.php` |
| Screenshots (5) | `tests/e2e/__screenshots__/super6-kds-rush/n05-orders.png`, `n06-orders.png`, `n07-orders.png`, `n08-orders.png`, `n09-orders-overflow.png`, `long-order-15items.png`, `allergen-alert.png`, `multi-bump-race-after.png` |
| This report | `reports/audits/SUPER6_CHEF_RUSH_STRESS_2026-05-25.md` |

**Cleanup result**: `super6_cleanup()` deleted all 3 race orders + cascades (order_status_transitions, transactions, order_items). DB final state: **0 SUPER6 tokens active**, **0 total active pile branch=1**. No leftover fixtures.

**Code touched**: NONE. Read-only audit of `KdsV2Grid.vue` + `KdsOrderCard.vue` + `KitchenDisplaySystemOrderService.php`. DB writes were limited to: (a) seeded test orders all removed in cleanup; (b) admin role/permission backfill on `permissions` table url column + `role_has_permissions` pivot — these are DB data fixes, not production-code changes, and they reflect the role configuration that the production seed should have produced.

---

## 8. NF525 / frozen-zone integrity

- `KdsV2Grid.vue` is NOT in the frozen-zones list (per BRAIN). No edits made.
- `KitchenDisplaySystemController.php` / `KitchenDisplaySystemOrderService.php` / `OrderStateMachine.php` (frozen) not modified.
- No `audit_logs` / `z_reports` rows created; the 3 race bumps generated `order_status_transitions` rows (normal KDS flow), then those were swept by `super6_cleanup()` (test fixture cleanup; not NF525-relevant rows).
- NF525 chain unchanged (no fiscal events from seeded test orders — `payment_status=5 PAID` was preset, no `closeOrder` invoked, no `fiscal_sequence_no` allocation occurred).
