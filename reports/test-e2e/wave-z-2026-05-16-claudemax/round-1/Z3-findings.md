# Z3 — KDS V2 default + Delivery enrichment — RED-team verification

- **Wave**: Z, Round 1
- **Date**: 2026-05-16
- **Branch**: feature/mobile-app-le-cayenne-2026-05-10
- **HEAD audited**: c3ba89863
- **Heal commits in scope**: 5f48856f9, a8b363dd6, 80dbc79c2
- **Method**: Read-only static evidence (`git show`, file:line reads). No tests executed.
- **Frozen-zone diff (5f48856f9 + a8b363dd6)**: 0 lines (verified via `git diff -- <frozen-paths>` → no output)

---

## 0. Commit reality

`git show --stat` reveals a commit-trail oddity that the verdict needs to flag:

- **80dbc79c2** subject = "feat(kds): Sprint 2A+3C — V2 layout default + delivery address/phone/name enrichment". Actual files touched: `Senangpay.php`, `Stripe.php`, `CashDrawerSessionNotOpenException.php`, `PosCashTrailTest.php`, `SenangpayWebhookIdempotencyTest.php`, `StripeWebhookIdempotencyTest.php`, `VerifyCsrfToken.php`, `config/services.php`, `stripe.php` route. **Zero KDS files touched.** The KDS body was attached to an index staged by a sibling agent (POS/webhook/Sprint 1B+C work).
- **5f48856f9** = the test file only (`tests/Feature/KDS/KDSDeliveryEnrichmentTest.php`, +274 lines).
- **a8b363dd6** = the actual KDS source-file changes (7 files, +263 / -11 lines) — the *real* heal.

Net effect on production: V2 flip + delivery enrichment **did land** (via a8b363dd6). The git log narrative is just unreliable: anyone trusting `git log --oneline` to find when V2 went default will land on 80dbc79c2 and see zero KDS code.

Filed as **Z3-AT-001** (audit-trail / release hygiene) below — not a code defect, but a release-process flag worth owner attention before the next NF525 incident postmortem inherits a misleading commit history.

---

## 1. Heal verification (originally P0)

### KDS-W3-002 — V2 default flip — **HEALED**
File `resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue:1105-1128`:
- `useV2Layout()` returns `true` by default (line 1124, 1109 SSR/test branch, 1127 catch).
- `?v2=0` → `false` (line 1113-1115); `?v2=1` → `true` (line 1116-1118).
- `localStorage 'kds.v2_enabled' === '0'` → `false` (line 1119-1122). Any other stored value → V2.
- Header comment lines 1088-1104 explicitly documents the inverted precedence.

Template root at line 9-21 renders `<KdsV2Grid v-if="useV2Layout">`, with `<template v-else>` (line 22) wrapping the entire legacy 4-column layout (lines 22-950). The flip is real.

### KDS-W3-001 — Accordion hardcoded closed — **HEALED via inertness**
`style="height: 0px"` accordion blocks still present at:
- `KitchenDisplaySystemComponent.vue:328` (dineinOrder)
- `KitchenDisplaySystemComponent.vue:512` (onlineOrder)
- `KitchenDisplaySystemComponent.vue:657` (takeawayOrder)
- `KitchenDisplaySystemComponent.vue:799` (kioskOrder)

All four sit inside `<template v-else>` (line 22), so they render **only** in the `?v2=0` rollback path. The V2 default (`KdsV2Grid` → `KdsOrderCard`) has no equivalent collapsed pattern — `KdsOrderCard.vue` template (lines 79-130) shows items unconditionally via `<template v-for="(item, idx) in order.order_items">` at line 106. Bug is now unreachable in normal production. Owner who chooses `?v2=0` rollback still hits the broken accordion — see Z3-NEW-003 below.

### KDS-W3-003 — 5 stacked banners — **HEALED via inertness**
Banners at `KitchenDisplaySystemComponent.vue:44/55/70/77/84/92` are all inside `<template v-else>`. Default render path is `KdsV2Grid` which has a single consolidated `<KdsStatusBanner>` (verified import in KdsV2Grid.vue + grid template). Banner stack only re-surfaces in `?v2=0` rollback.

### KDS-W3-004 — `allergens_snapshot` exposure on Items Board — **PARTIAL / RECONTEXTUALIZED**
- `OrderItemResource.php:37` exposes `allergens_snapshot` via `safeJsonDecode`. This resource is what `KDSOrderDetailsResource.php:45` wraps via `OrderItemResource::collection(...)`. So allergens reach the V2 card (`KdsOrderCard.vue:135` imports `orderHasAnyAllergen`; line 47-54 renders the allergen pill).
- BUT: V2 layout (`KdsV2Grid`) **does not render an Items Board pane** — see Z3-NEW-001 below. The original W3-004 description targeted the legacy Items Board pane; that pane is now hidden by default (V2 ON). The food-safety concern shifts from "Items Board missing allergens" to "Items Board missing entirely". Owner decision.

### DEL-3 — Resources expose order_address + customer — **HEALED (V2 path) / PARTIAL (rollback path)**
- `app/Http/Resources/KDSOrderDetailsResource.php:55-61` ships `order_address` via `whenLoaded('address')` with label/address/apartment/latitude/longitude.
- `app/Http/Resources/KDSOrderDetailsResource.php:62-67` ships `customer` via `whenLoaded('user')` with name + phone.
- `app/Http/Resources/SimpleOrderResource.php:45-51` ships `order_address` via `whenLoaded`; line 52 ships `customer_phone` unconditionally (`$this->user?->phone`).
- Eager-loads applied at `app/Services/KitchenDisplaySystemOrderService.php:70` (`Order::with(['orderItems', 'address', 'user'])`) and `app/Services/KdsSyncService.php:60`.
- `Order::address()` is `hasOne(OrderAddress::class)` (`app/Models/Order.php:147-150`).
- `Order::user()` is `belongsTo(User::class)->withTrashed()` (`app/Models/Order.php:142-145`). BranchScope is correctly exempted for the User model at `app/Models/Scopes/BranchScope.php:21-23` (`if ($model instanceof User) return;`). Commit body claim is accurate.
- V2 `KdsOrderCard.vue:87-105` renders the delivery block (address line, name, `tel:` phone link) gated on `isDeliveryOrder` (line 287-294 — `source_surface === 'delivery'` OR `order_type === 5`).
- Legacy `KitchenDisplaySystemComponent.vue:478-499` renders a matching delivery block — but **only in the onlineOrder lane**. See Z3-NEW-002 below.

---

## 2. NEW issues — red-team

### Z3-NEW-001 — P0 — Items Board feature dropped in V2 default
**File**: `resources/js/components/admin/kitchenDisplaySystem/KdsV2Grid.vue` (entire file, 297 lines).

Grep for items-aggregation tokens (`item-order`, `items_board`, `mergedItems`, `kds_items_board`, `orderItems`) returns **zero matches** in `KdsV2Grid.vue`. The component renders a flat FIFO grid of `<KdsOrderCard>` and nothing else.

Legacy `KitchenDisplaySystemComponent.vue:115` opens `<div id="item-order">` (the Items Board pane) — visible on desktop (`lg:block`) and toggled on mobile (`lg:hidden` tab strip at line 107-114). Its content is the aggregated, mergedItems list produced by `KitchenDisplaySystemOrderService.php:238-300` (mergedItems via `pluck('orderItems')->flatten()->groupBy(...)` with allergen-hash split per Lot 2.I / G-5 at line 274-279).

V2 default flip removes this pane entirely. Items Board served as the **station-level aggregation view** ("Burger × 5, Frites × 8, …") that legacy chefs used for batch prep — a different workflow from the per-card order queue. The endpoint is still alive (`Service::orderItems()` line 238) but no V2 surface consumes it.

**Impact**: chefs who relied on the aggregation pane lose a production feature on a flip-of-a-switch deploy. No comm to ops, no owner-gate visible in the commit body, no migration UX. Sister session's W3 findings did not call this out because they were focused on the per-card accordion bug.

**Reproduce**: open `/admin/kds` on production HEAD → no Items Board pane is visible. Append `?v2=0` → pane returns.

### Z3-NEW-002 — P0 — Legacy delivery block scoped to ONE of FOUR lanes
**File**: `resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue`.

`kdsLegacyShouldShowDelivery` helper is defined at line 1348-1358 (V1 mirror of `KdsOrderCard.isDeliveryOrder`). It is **called exactly once** in the template, at line 479, inside the **onlineOrder** lane (`<div data-testid="kds-legacy-delivery">` line 481). `grep -c` count = 3 occurrences total in the file (definition + v-if + data-testid).

The legacy template has four order lanes:
- dineinOrder (header ~line 257, accordion line 328)
- onlineOrder (line 512 accordion, delivery block added line 478-499) ← only lane covered
- takeawayOrder (line 657 accordion) — **no delivery block**
- kioskOrder (line 799 accordion) — **no delivery block**

DELIVERY orders (`order_type === 5` / `source_surface === 'delivery'`) could be routed via `kdsBuckets`/`buildLaneAssignment` to any of these four lanes depending on the source-surface mapping. If the routing puts a DELIVERY order in the takeaway or kiosk lane in the rollback path, the livreur block silently disappears — partial heal of DEL-3 in the path the commit body explicitly says it covers ("Legacy online lane in KitchenDisplaySystemComponent.vue: matching delivery block in the ?v2=0 rollback path so the livreur still sees address + phone + name regardless of which UI variant runs"). Per the source: NOT regardless. Only when the legacy bucketing happens to drop the delivery order into the online column.

**Reproduce**: open `/admin/kds?v2=0`, place a DELIVERY order via mobile/web with `source_surface='delivery'` but `order_type=2` (takeaway) → check lane bucketing; if it lands in takeaway or kiosk, no delivery info renders.

**Risk-of-error mitigation**: in the V2 default path this issue does not surface — `KdsOrderCard` is a single canonical card so the delivery block always renders for `isDeliveryOrder`. The issue is strict-rollback-only.

### Z3-NEW-003 — P1 — Rollback path remains visibly broken
The W3-001 accordion-closed bug, W3-003 5-banner stack, and Z3-NEW-002 dropped delivery block all sit in `<template v-else>` (the rollback path that owners would activate via `?v2=0` if a V2 regression surfaces). The "emergency rollback" therefore lands on a UI that is independently known-broken in three places. There is no owner-visible warning when `?v2=0` is applied — `useV2Layout()` returns `false` silently.

**Recommendation**: either (a) heal the accordion + banners + delivery-block-in-three-lanes inside v-else even though it's a rollback, or (b) downgrade `?v2=0` to a developer-only dev-tools flag and remove `localStorage 'kds.v2_enabled' === '0'` as a user-accessible escape hatch. As shipped, the rollback is a footgun.

### Z3-NEW-004 — P1 — Privacy: `customer_phone` exposed unconditionally on admin endpoints
**File**: `app/Http/Resources/SimpleOrderResource.php:52`.

`'customer_phone' => $this->user?->phone` is unconditional — no `whenLoaded` gate, no `order_type` filter, no role check. SimpleOrderResource is collected by:
- `app/Http/Controllers/Admin/SalesReportController.php:43` (sales report viewer)
- `app/Http/Controllers/Admin/OnlineOrderController.php:48` (online orders list)
- `app/Http/Controllers/Admin/PosOrderController.php:98` (POS history)
- `app/Http/Controllers/Admin/AdministratorController.php` (imports, line 5)

Consequence: every staff member with route access to admin orders/sales reports now reads every customer's phone in the JSON payload, regardless of whether the order is delivery or in-store cash. Sprint 2A scope was "expose delivery context to KDS + admin orders" — but blanket exposure on dine-in cash orders is a privacy widening that exceeds the brief.

**KDS-side parallel concern**: `KDSOrderDetailsResource.php:62-67` uses `whenLoaded('user')` (privacy-safer), but `KitchenDisplaySystemOrderService.php:70` eager-loads `['orderItems', 'address', 'user']` for **every** order in the KDS list — including dine-in/takeaway/kiosk. So `whenLoaded` triggers, and chef screens display `customer.phone` on non-delivery orders too. The V2 `KdsOrderCard.vue:87` `v-if="isDeliveryOrder"` only gates the **rendering** of the delivery block — the phone is still in the JSON payload, recoverable via DevTools or any script injected into the kitchen tablet.

**Recommendation**: scope the eager-load + Resource field to `order_type === DELIVERY` server-side (e.g. wrap `user` eager-load with `when($order->isDelivery())` in the service, and gate the resource field on `$this->order_type === OrderType::DELIVERY`). Spatie role check optional but advisable for SalesReport surface.

### Z3-NEW-005 — P1 — `allergens_snapshot` null for legacy orders, no UI signal
**Schema**: `database/migrations/2026_04_18_140004_add_allergens_snapshot_to_order_items.php:13` — column added `nullable`, no backfill migration. Orders created before 2026-04-18 (and any code path that bypassed `OrderItemAllergenSnapshot::hydrate`) carry `allergens_snapshot = NULL`.

**Resource**: `OrderItemResource.php:37` calls `safeJsonDecode($this->allergens_snapshot)`. Line 107-118 returns `[]` for null/empty/invalid. **Empty array == "no allergens declared"** from the chef's POV. There is no separate signal for "snapshot missing".

**Frontend**: `resources/js/helpers/kdsCustomization.js:152-162` reads `allergens_snapshot` and `hasAllergen` flips false for an empty array. `KdsOrderCard.vue:48` `v-if="hasAllergen"` — pill never shows. Chef sees a clean card.

**Risk**: legacy DINE_IN/TAKEAWAY orders re-bumped via recall path (`RefundWithCounterEntryService.php:140` preserves the original snapshot) will silently display "no allergens" even if the underlying Item carries allergens in the live catalog. NF525-tolerable but food-safety-relevant.

**Recommendation**: either (a) backfill `allergens_snapshot` for in-flight pre-cutover orders, or (b) expose a `allergens_snapshot_present` boolean discriminator so the UI can render "❓ Allergènes non garantis" instead of "no allergens".

### Z3-NEW-006 — P2 — No org-wide kill switch
`useV2Layout()` reads URL param and localStorage only. There is no:
- env flag (`config/kds.php` or `.env`)
- Settings model row (`spatie/settings`)
- Branch-scoped feature flag
- Owner-toggleable UI control in admin

If V2 misbehaves in production (regression surfaces 24h after deploy), the only rollback path is one of:
1. Push a new release that flips the default
2. Walk to every kitchen tablet and visit `?v2=0` once to seed localStorage
3. Re-deploy with `useV2Layout()` hardcoded false

Each takes minutes to hours; meanwhile every chef is on a freshly-defaulted UI without owner control. CLAUDE.md §10 "human gate" doctrine arguably wanted an admin-toggleable flag here.

**Recommendation**: add a `kds_v2_enabled` boolean to `Setting` table with admin UI toggle (one route, one Vue widget); `useV2Layout()` reads it before falling through to defaults. Same pattern as `pos.dine_in_enabled` (cf. CLAUDE.md feedback_v1_dine_in_disabled).

### Z3-NEW-007 — P3 — Raw FR strings in template
- `KdsOrderCard.vue:100` `:aria-label="`Appeler ${customerName || ''} ${customerPhone}`.trim()"` — French in code (aria-only).
- `KitchenDisplaySystemComponent.vue:321, 505, 650, 792` aria-label fallback `|| 'Afficher les articles'` (legacy v-else only — aria-only, only when key resolution fails).

Minor — both are aria-only and only one is in the V2 default path.

### Z3-AT-001 — audit-trail — Commit body/index mismatch on 80dbc79c2
See §0 above. The commit body announces a Sprint 2A+3C KDS deliverable; the diff is entirely POS-cash + Senangpay/Stripe + tests. The actual KDS code arrived 7 minutes earlier (5f48856f9 test-only) and 4 minutes later (a8b363dd6 source-only) under near-identical subject lines. Anyone reading `git log --oneline` to triage a KDS regression will be misled.

**Recommendation**: amend or annotate. The existing 5f48856f9 + a8b363dd6 bodies acknowledge the error in prose ("commit 80dbc79c2 carried this Sprint 2A+3C subject line in error"), so the audit trail is recoverable via reading those bodies — but git tooling (bisect, grep on subject) will still mislead.

---

## 3. Frozen-zone verification

`git diff 5f48856f9^ 5f48856f9 -- <frozen-paths>` and `git diff a8b363dd6^ a8b363dd6 -- <frozen-paths>` both produce **zero output** for:
- `app/Services/Fiscal/*` (FiscalSequenceService, ZReportService, AuditLogService)
- `app/Services/Pricing/*` (PricingService — SSOT)
- `app/Models/Scopes/BranchScope.php`
- `app/Domain/Order/OrderStateMachine.php`
- `app/Http/Middleware/IdempotencyKeyMiddleware.php`
- `public/js/pos-wizard.js`, `public/css/pos-wizard.css`
- `resources/js/components/frontend/kiosk/Kiosk*`

Frozen-zone discipline: **clean**.

---

## 4. Summary verdict

| Original P0 | Status |
|---|---|
| KDS-W3-001 accordion closed | HEALED-via-inertness (V2 default; bug still in rollback) |
| KDS-W3-002 V2 default flip | HEALED |
| KDS-W3-003 5-banner stack | HEALED-via-inertness (V2 default; banners still in rollback) |
| KDS-W3-004 Items Board allergens | RECONTEXTUALIZED (Items Board dropped entirely in V2 — see Z3-NEW-001) |
| DEL-3 delivery enrichment | HEALED in V2 path; PARTIAL in rollback (Z3-NEW-002) |

| NEW finding | Severity | File:line anchor |
|---|---|---|
| Z3-NEW-001 Items Board dropped in V2 | P0 | KdsV2Grid.vue (entire file, no items-aggregation pane) |
| Z3-NEW-002 Legacy delivery block only in onlineOrder lane | P0 | KitchenDisplaySystemComponent.vue:479 (only call site of 3 lanes missing) |
| Z3-NEW-003 Rollback path independently broken | P1 | KitchenDisplaySystemComponent.vue:328/512/657/799 + 44-92 banner stack |
| Z3-NEW-004 customer_phone broadcast unscoped | P1 | SimpleOrderResource.php:52 + KitchenDisplaySystemOrderService.php:70 |
| Z3-NEW-005 allergens_snapshot null for legacy orders | P1 | migration 2026_04_18_140004:13 + OrderItemResource.php:37 |
| Z3-NEW-006 No org-wide V2 kill switch | P2 | KitchenDisplaySystemComponent.vue:1105-1128 |
| Z3-NEW-007 Raw FR aria-label fragments | P3 | KdsOrderCard.vue:100, KitchenDisplaySystemComponent.vue:321/505/650/792 |
| Z3-AT-001 Commit 80dbc79c2 subject/diff mismatch | release-hygiene | git log |

**Net verdict**: the W3 P0s are no longer reachable in default production traffic, but the V2 flip surfaced one production feature regression (Items Board) and one privacy-widening side effect (customer_phone broadcast) that the original W3 audit did not anticipate. The "rollback path" advertised as the safety valve is itself broken in three places. Owner gate recommended on Z3-NEW-001, Z3-NEW-002, Z3-NEW-004 before next promotion.
