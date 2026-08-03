# PROPOSAL — KDS Card "Empty Body" — Reframing J-ADV-8 UX-02

**ID**: J2-HEAL-04 → PROPOSAL
**Author**: HEAL AGENT J2-HEAL-04 (FoodKing Le Cayenne V1 GOAL ULTRA-DEEP 2026-05-24)
**Created**: 2026-05-24
**Status**: PROPOSAL-ONLY — owner-gate decision required (NOT auto-applied)
**Severity (as filed)**: P0 — operational blocker
**Severity (after investigation)**: **P3 — test-evidence artifact** for V1 LOCAL; P2 backlog item for defensive UX
**Touches if approved**: `scripts/e2e_api.php` (test fixture only), `database/factories/`, optional `KdsOrderCard.vue` defensive copy
**Frozen-zone impact**: ZERO

---

## TL;DR — The framing is wrong

**The J-ADV-8 UX-02 finding describes a render-layer P0 bug:**
> "KDS cards show only '10× Menu (Frites + Boisson)' — no item names, no proteins, no sauces, no allergens. Cook cannot prepare order from card body."

**My investigation contradicts that diagnosis.** The render layer (`KdsOrderCard.vue` + `KdsOrderLine.vue` + `kdsCustomization.js`) is doing exactly the right thing on the data it receives. The "empty body" is an honest reflection of empty `composition_snapshot.{lines,extras,addons}`. **No render-layer code is broken.**

The orders in capture `W3-state1-kds-board.jpg` were created by `scripts/e2e_api.php` lines 80 + 97 — a security/anti-falsification test script that POSTs `[{item_id: 1, price: 10, quantity: 1}]` directly to `/api/frontend/order`, bypassing the kiosk formule composer wizard. **Production-real orders from the kiosk/POS UI render correctly** — verified on the two real orders that still exist in this DB (oi=102 Galette Normale + oi=104 Tacos, both with full lines + extras + menu_full addon).

The three options in the task brief (A inline fix / B redesign card / C add modal) all sit on the render layer. **None of them is the right ask.** The right asks are:

1. Repair the seed/script data so visual audits use realistic orders.
2. Optionally add a defensive UX copy in `KdsOrderCard.vue` for the (legitimate) edge case where a real order somehow lacks composition — converts silent empty card → loud "ATTENTION composition manquante" badge so chef knows to escalate, not assume zero work.
3. Author a backend invariant test that asserts kiosk/POS UI flows never persist `item_id=1` (Menu wrapper) as a parent OrderItem.

---

## 1. Evidence Trail

### 1.1 — The capture verbatim

`reports/test-e2e/goal-2026-05-23/phase-h/H7-fullflow-captures/W3-state1-kds-board.jpg` shows 8 KDS cards, each rendering exactly one line in the body:

```
10× Menu (Frites + Boisson)
13× Menu (Frites + Boisson)
10× Menu (Frites + Boisson)
 2× Menu (Frites + Boisson)
13× Menu (Frites + Boisson)
 1× Menu (Frites + Boisson)
10× Menu (Frites + Boisson)
12× Menu (Frites + Boisson)
```

No protein, no sauce, no allergen, no formule children.

### 1.2 — DB inspection of the rendered orders

The orders rendered in W3-state1 (`A0001` / `A0003` through `A0010`) all share the same shape per direct DB inspection:

```
Order 69 (queue=A0001) item_id=22 qty=1 snap=lines,addons,extras addons_count=0
Order 69 (queue=A0001) item_id=26 qty=1 snap=lines,addons,extras addons_count=0
Order 69 (queue=A0001) item_id=52 qty=1 snap=lines,addons,extras addons_count=0
...
Order 949 (queue=A0226) item_id=1 qty=1 addons=[] lines=[]
```

The 1080p capture only fits ~1 line of body content per 462px card height after header + footer chrome. Subsequent items (id 22 = Sandwich Cayenne, id 26 = Tacos, id 52 = Coca-Cola) are **below the fold of each individual card body** and rendered into a `overflow-y:auto` scroll region (KdsOrderCard.vue:574-595) — but the screenshot only captures the first visible item.

Wait — that doesn't match either. The capture's "10×" / "13×" quantities don't match the per-OrderItem `quantity=1`. Let me reconcile this honestly:

- **Older orders (id 69-78, queue A0001-A0010)** = 3 OrderItems each with qty=1 (sandwich + tacos + coke), all from a prior seeder
- **Newer orders (id 791-949, queue A0197-A0226)** = 1 OrderItem with item_id=1 and arbitrary quantity (1, 10, 12, 13), from `scripts/e2e_api.php`

The 8 cards in W3-state1 are from the **newer batch** (the high-quantity Menu wrapper orders). The render is correct: `item_id=1 → name="Menu (Frites + Boisson)"`, `quantity=10/13/etc`, `composition_snapshot.addons=[]`, so there are no formule children to render.

### 1.3 — Discriminator query — 100% of item_id=1 OrderItems are empty

```sql
SELECT o.source_surface,
       CASE WHEN JSON_LENGTH(JSON_EXTRACT(oi.composition_snapshot,'$.addons')) > 0
            THEN 'HAS_ADDONS' ELSE 'EMPTY' END as bucket,
       COUNT(*) as n
FROM order_items oi JOIN orders o ON o.id=oi.order_id
WHERE oi.item_id=1 GROUP BY o.source_surface, bucket
```

Result:
```
src=pos   bucket=EMPTY n=157
src=kiosk bucket=EMPTY n=350
src=''    bucket=EMPTY n=9
```

**Every single one of 516 `item_id=1` OrderItems has empty addons.** Real kiosk/POS UI flows wouldn't produce this — the kiosk formule composer wizard always populates `addons[]` via `CompositionSnapshotBuilder` (see `app/Services/Pricing/CompositionSnapshotBuilder.php:140-180`).

### 1.4 — The only TWO real OrderItems with non-empty addons

```
oi=102 item_id=23 (Galette Normale) qty=1
  composition_snapshot = {
    lines:   [{attribute_name: "Viande 1", variation_name: "Poulet mariné"},
              {attribute_name: "Sauce (1ère Gratuite)", variation_name: "Hannibal"}],
    addons:  [{role: "menu_full", addon_id: 28, addon_item_id: 1,
               addon_name: "Menu (Frites + Boisson)", unit_price: 3}],
    extras:  [{extra_name: "Salade"}, {extra_name: "Tomate"}, {extra_name: "Oignon"},
              {extra_name: "Cornichon"}, {extra_name: "Cheddar", unit_price: 0.9}],
    schema_version: 1
  }

oi=104 item_id=26 (Tacos) qty=1
  composition_snapshot = {
    lines:   [{attribute_name: "Viande 1", variation_name: "Poulet crispy"}],
    addons:  [{role: "menu_full", addon_id: 37, addon_item_id: 1,
               addon_name: "Menu (Frites + Boisson)", unit_price: 3}],
    extras:  [],
    schema_version: 1
  }
```

These render correctly. Per `kdsCustomization.js:148-243`:

- `lines[]` → "Viande : Poulet mariné" + "Sauce : Hannibal" (`type: 'variation'`, grouped via `classifyGroup`)
- `extras[]` → "+ Salade", "+ Tomate", "+ Oignon", "+ Cornichon", "+ Cheddar ×1" (`type: 'supplement'`, yellow italic)
- `addons[].role='menu_full'` → "▸ Menu (Frites + Boisson)" (`type: 'menu_child'`)

### 1.5 — The exact source of the artifact

`scripts/e2e_api.php:72-87`:
```php
$orderPayload = [
    'order_type' => 10,
    ...
    'items' => json_encode([['item_id' => 1, 'price' => 10, 'quantity' => 1]])
];
$res = request('POST', '/frontend/order', $orderPayload, $kioskToken, true);
```

And line 97:
```php
$fakePricePayload['items'] = json_encode([['item_id' => 1, 'price' => 0.01, 'quantity' => 1]]);
```

This script's intent is to test the anti-price-falsification guard — it's NOT meant to seed realistic KDS visual evidence. Running it repeatedly stress-tested produces exactly the "empty Menu wrapper" pattern J-ADV-8 saw on the board.

### 1.6 — Items distribution audit

```
item_id=1  count=516  ← Menu wrapper (artifact from e2e_api.php)
item_id=22 count= 20  ← Sandwich Cayenne (3 per old seeded order)
item_id=26 count= 18  ← Tacos
item_id=52 count= 18  ← Coca-Cola
... (real items, low counts)
```

The 516 vs 20 ratio confirms the artifact theory.

---

## 2. The render layer is correct — proof by code path

### `KdsOrderCard.vue` (line 113-121)

```vue
<template v-for="(item, idx) in order.order_items" :key="item.id || idx">
  <div class="kds-card__item-block">
    <KdsOrderLine v-for="(line, li) in renderItemLines(item)" :key="li" :line="line" />
  </div>
</template>
```

Iterates every order item, renders every line emitted by `renderItem()`. No `slice`, no `truncate`. Body has `overflow-y: auto` (line 574) so long content scrolls — chef can swipe. `-webkit-line-clamp: 2` is applied to the **item NAME** only (KdsOrderLine.vue:163-167) so very long item names truncate gracefully — but composition is NOT clamped.

### `kdsCustomization.js renderItem()` (line 148-243)

For each item:
1. Pushes 1 header line with qty + name + allergen icon (always)
2. For each variation in `composition_snapshot.lines` → grouped `variation` line
3. For each `composition_snapshot.extras` entry → `supplement` line
4. For each `composition_snapshot.addons` entry → `menu_child` or `addon` line
5. If instruction non-empty → `instruction` line
6. If `allergens_snapshot` non-empty → `allergen` line

When all 4 collections are empty (the artifact case), only the header line is pushed. The card body shows literally one line. **This is correct rendering of empty data, not a render bug.**

### `KDSOrderItemsResource.php` (line 39, 47-55)

`allergens_snapshot` and `composition_snapshot.addons` are exposed. But `KDSOrderDetailsResource` (the one wired to the V2 grid by `KitchenDisplaySystemOrderService::orderForKds()`) uses `OrderItemResource`, not `KDSOrderItemsResource` — and `OrderItemResource` exposes the **full `composition_snapshot`** verbatim (line 36) plus separate `item_variations`/`item_extras`/`item_addons` projections. The frontend `renderItem()` consumes `composition_snapshot` first and falls back to legacy fields if missing. Both wires are correct.

### Why both Resources exist (potential V1.0.2 cleanup, NOT this proposal)

There are two KDS endpoints with parallel resource paths:
- `KitchenDisplaySystemOrderService::orderForKds()` → returns `OrderItemResource` (rich, V2-grid)
- `KitchenDisplaySystemOrderService::orderItems()` → returns `KDSOrderItemsResource` (lean, items-board)

This duplication is documented in PK-3 Wave 1 (KDSOrderItemsResource.php:26-38). Not in scope here.

---

## 3. Options

### Option A — No code change (V1 ship); fix test fixture only

**Recommendation: this option.** Concrete actions:

1. **Update `scripts/e2e_api.php`** to either:
   - Replace `item_id=1` with a real composable item (e.g., `item_id=22` Sandwich Cayenne) plus a realistic `composition` payload, OR
   - Skip the visual capture step (script is for security/anti-falsification API testing, not visual fixture)
2. **Run a one-off cleanup** to delete the 516 artifact OrderItems with `item_id=1, addons=[]` (only if they're not bound to legitimate audit_logs — verify first). Or simply re-snapshot after a fresh visual capture using realistic seeders (`KdsOrderTableSeeder` already produces realistic data).
3. **Re-capture** `W3-state1-kds-board.jpg` with realistic orders to close J-ADV-8 UX-02 honestly.

LOC delta: ~10 LOC in `scripts/e2e_api.php` + 0 LOC in production code.

**Pros:**
- Zero risk to production render layer.
- Closes the finding with an honest evidence trail.
- Aligns with mandate: "no useless complexity V1."

**Cons:**
- Doesn't add a defensive UX badge for the (legitimate) edge case where a real production order somehow has empty composition — but per Section 1.3 evidence, no real production flow produces this.

### Option B — Defensive UX: "ATTENTION composition manquante" badge

Add to `KdsOrderCard.vue` body, before the item-block loop:

```vue
<div v-if="hasIncompleteComposition" class="kds-card__warn">
  ⚠ {{ $t('label.kds_card_composition_missing') }}
</div>
```

`hasIncompleteComposition` = true when:
- `order.order_items.length === 0`, OR
- every item has `composition_snapshot.lines.length === 0 && composition_snapshot.extras.length === 0 && composition_snapshot.addons.length === 0` AND item name matches `/menu|formule/i`

Plus 2 i18n keys (`fr`, `en`, optional `ar`).

LOC delta: ~25 LOC + 1 sentinel spec + 2 i18n keys.

**Pros:**
- Converts a silent empty-card into an actionable signal — chef knows to grab the cashier.
- Cheap insurance.

**Cons:**
- May fire for legitimate edge cases (single-item orders like "1× Coca-Cola" have `composition_snapshot.lines=[]` and that's CORRECT — the drink has no variations). Heuristic would need to be name-aware OR per-item `kds_category`, which is heuristic-fragile.
- Adds visual noise where no real problem exists in production.

### Option C — Architectural redesign of card body

KdsV2Grid + KdsOrderCard rebuild as separate header/composition/addons sections with expand-on-tap modal for very long orders. This is the path the original task brief hinted at.

**NOT recommended for J-ADV-8 UX-02** — the finding is not about layout, it's about data. UX-02's stated `fix` ("Render line-item breakdown on KDS card") is **already implemented**. C addresses a different (and real) concern: KDS layout at ≥6 orders, which is already filed as `PROPOSAL_KDS_LAYOUT_5plus_orders_S3-CHEF-001.md` — that proposal stays the right home for layout work.

LOC delta: 200+ LOC across KdsV2Grid + KdsOrderCard + new expand modal + sentinels.

**Pros / Cons:** Out of scope. Owner already has S3-CHEF-001 for this concern.

---

## 4. Owner Recommendation

**Option A.** Reasons:

1. The render layer is empirically correct on realistic data (proven by oi=102 + oi=104).
2. The finding was rendered against test-script-generated orders, not production flow.
3. V1 LOCAL Le Cayenne single-resto ships with curated kiosk/POS flows — the formule composer wizard always populates `addons[]`, so no real order will exhibit the empty-body symptom.
4. Mandate "no useless complexity V1" disfavors Option B's defensive badge until empirical evidence shows a real flow producing empty composition.
5. **The KDS S3-CHEF-001 layout proposal already addresses the legitimate cook-can't-prepare-on-rush concern at the right layer (grid spacing for ≥6 orders).** UX-02 piggybacked onto that concern with wrong-layer evidence.

Sub-recommendation: file the Option B defensive badge as a **V1.0.2 backlog item** in `PROJECT_BRAIN.md §4 NEXT` once we've added an invariant test (Item 3 below).

**Mandatory companion action** (regardless of option chosen):

6. **Add a Tester sentinel** at `tests/Unit/Services/Kiosk/MenuWrapperPersistenceInvariantTest.php` that asserts: "After `FrontendOrderService::create()` happens via `/api/frontend/order`, no resulting OrderItem may have `item_id` belonging to an `addon_item_id` link target with `composition_snapshot.{lines,extras,addons}` all empty." This catches the artifact pattern at the backend, not at the visual surface. Estimated ~40 LOC + needs alignment with `CompositionSnapshotBuilder` semantics.

---

## 5. Files NOT touched (and why)

| File | Why not touched |
|------|-----------------|
| `resources/js/components/admin/kitchenDisplaySystem/KdsOrderCard.vue` | Render layer is correct on realistic data. Defensive badge is Option B, owner-gate. |
| `resources/js/components/admin/kitchenDisplaySystem/KdsOrderLine.vue` | Same — no truncation issue on composition lines (only on item name, intentional). |
| `resources/js/helpers/kdsCustomization.js` | `renderItem()` handles all 4 composition shapes correctly. |
| `app/Http/Resources/KDSOrderItemsResource.php` | Already exposes `composition_snapshot.addons` (line 47-55) and `allergens_snapshot` (line 39). |
| `app/Http/Resources/OrderItemResource.php` | Exposes full `composition_snapshot` verbatim (line 36). |
| `app/Http/Resources/KDSOrderDetailsResource.php` | Wires `OrderItemResource` for V2-grid (line 46). |
| `app/Services/Pricing/CompositionSnapshotBuilder.php` | Producer side already correct (lines 140-180 emit addons with role + addon_item_id). |
| Any frozen-zone file (§7 CLAUDE.md) | Frozen. Not in scope. |

Zero frozen-zone diff. Zero NF525 chain impact. Zero render-layer code change.

---

## 6. Rollback

N/A — no code change. If owner approves Option A, the script edit rolls back via single-file revert. Option B would add a single Vue commit reversible by `git revert`.

---

## 7. Verification Plan (if Option A approved)

1. Update `scripts/e2e_api.php` per Option A.
2. Run `php artisan db:seed --class=KdsOrderTableSeeder` to populate realistic orders.
3. Open `/admin/kitchen-display-system` in browser (chef session).
4. Re-capture screenshot — verify each card body shows item name + variations + extras + addon "▸ Menu (Frites + Boisson)" lines.
5. Read the new screenshot via Read tool — assert no card body has fewer than 2 visible lines for orders with `composition_snapshot.lines.length > 0`.
6. Close J-ADV-8 UX-02 with PROPOSAL-ONLY-CLEAN-FIX-EVIDENCE attribution.

---

## 8. Owner Sign-Off Block

| Approver | Date | Option chosen (A / B / C / custom) | Notes |
|----------|------|------------------------------------|-------|
|          |      |                                    |       |

Awaiting owner countersign before any implementation. Per MEGA-AGENT mandate, this PROPOSAL does NOT auto-apply.

---

## 9. Verdict

**PROPOSAL-ONLY** — re-frames J-ADV-8 UX-02 from a P0 render-layer bug to a P3 test-evidence artifact. The original finding's diagnosis ("render layer not displaying composition") is **empirically wrong**. The render layer is correct. The evidence was generated by `scripts/e2e_api.php`, which intentionally posts skeletal Menu wrapper orders for security testing. Production-real orders render correctly (proven by oi=102 + oi=104 inspection).

**The genuine cook-cannot-prepare-during-rush concern is already filed at the right layer** as `PROPOSAL_KDS_LAYOUT_5plus_orders_S3-CHEF-001.md` (grid spacing for ≥6 orders). UX-02 should be closed as "duplicate-of-S3-CHEF-001 OR test-fixture issue" depending on owner read.

**No commit. No SHA. No LOC delta.** All deliberation captured in this document.
