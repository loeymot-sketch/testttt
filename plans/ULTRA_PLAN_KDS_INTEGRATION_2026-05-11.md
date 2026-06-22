# ULTRA_PLAN_KDS_INTEGRATION_2026-05-11.md

> KDS V1 Integration — Single-Queue Card-Grid Redesign with Multi-Source Convergence
> Date: 2026-05-11
> Authority: validated owner direction post `PLAN_KDS_IMPROVEMENT_2026-05-11.md` + `RESEARCH_KDS_MODERN_2024_2026.md`
> Branch (suggested): `feature/kds-redesign-2026-05-11`
> Owner-gate: NON validated (requires sign-off on §13 questions before execution)

---

## 14. Executive Summary (read first)

FoodKing V1 KDS will be rebuilt as **a single FIFO grid of 8 fully-loaded cards (4×2), source-as-chip, items always inline, allergens in orange-bold-italic à la Fresh KDS, 3-step age coloring (neutral → orange@3min → red@6min pulse), 60 px Prêt CTA with 3 s undo Toast, and conditional auto-transition `ACCEPT → PREPARING` fired client-side when no other order is in PREPARING.** Multi-source convergence happens at a **single Eloquent resource layer** (`KDSOrderDetailsResource` augmented, not rewritten) emitting a canonical `KdsOrder` shape consumed by **one Vue component** that has zero per-source rendering logic — source is metadata on every card, not a layout axis.

**The three architectural decisions worth flagging:** (1) Auto-transition lives **client-side** in Vue/Vuex, not in `OrderStateMachine.php` (frozen zone), routed through the existing `POST /admin/kds-order/change-status/{order}` endpoint — fully reversible by feature flag and degrades cleanly to manual the day a second chef appears. (2) Delivery (`source_surface='delivery'`) is **plumbed as enum + chip + i18n now**, but the *order ingestion path* is owner-decision (manual / aggregator-webhook / dedicated dashboard) — we ship the rail, owner picks the train. (3) Adaptive customization rendering (sandwich vs taco vs assiette vs menu-formule) is centralized in a new pure helper `kdsCustomization.js` extending `kdsLineSemantics.js`, so the Vue template stays declarative — *one* rendering path, *zero* duplication.

**Risk shortlist:** R1 NF525 immutability of `composition_snapshot` (read-only on KDS — never modify). R2 frozen-zone discipline on `OrderStateMachine.php` / kiosk wizard / pos-wizard (read-only). R5 race between client-side auto-transition and chef manual bump (mitigated by server-side `OrderStateMachine::apply` lockForUpdate already in place).

**Three blocking questions for owner:** (Q1) delivery ingestion path (manual / Uber Eats webhook / dedicated dashboard); (Q2) Menu Formule storage — confirmed as **single OrderItem with `addons[]` role-tagged in composition_snapshot** (NOT nested sub-OrderItems) — does owner accept rendering the parent + indented children from a single line?; (Q3) Sprint 1 SKIP list confirmation — owner wants only the quick-wins orthogonal to redesign (QW-1, QW-9, QW-10), the rest deferred to Sprint 2 to avoid double-work.

**Immediate next step:** owner answers Q1/Q2/Q3, we open `feature/kds-redesign-2026-05-11`, run Sprint 1 orthogonal QW (≤2 h), open owner-gate to Sprint 2 with proof of zero regression.

---

## 1. Codebase Audit Findings (ground truth)

Each finding is grounded in actual files read for this plan — file path + line number where applicable.

### 1.1 Models & schema

- `app/Models/Order.php` — fillable includes `source_surface` (line 47). Field type: nullable `string(20)`, comment `'kiosk | pos | web | mobile'`. Migration: `database/migrations/2026_03_26_075905_add_source_surface_to_orders_table.php:17`.
- `app/Models/FrontendOrder.php` — same `source_surface` fillable (line 54), explicit `[AUDIT-P50-BUG3]` comment requiring it for analytics/tracing.
- `app/Models/OrderItem.php` — line 49: `allergens_snapshot` (json). Line 44: `composition_snapshot` (json). Both cast to `array` (lines 71, 76). Both branch-scoped via `BranchScope` (line 27).
- `app/Domain/Order/OrderStateMachine.php` — FROZEN per `CLAUDE.md §7`. Status enum values (numeric, `app/Enums/OrderStatus.php`):
  - `PENDING=1`, `ACCEPT=4`, `PREPARING=7`, `PREPARED=8`, `OUT_FOR_DELIVERY=10`, `DELIVERED=13`, `CANCELED=16`, `REJECTED=19`, `RETURNED=22`.
  - Legal KDS transitions (from `OrderStateMachine::allows` lines 36-74):
    - `ACCEPT → PREPARING | CANCELED | DELIVERED(POS)`
    - `PREPARING → PREPARED | CANCELED | DELIVERED(POS)`
    - `PREPARED → OUT_FOR_DELIVERY | DELIVERED`
  - Reason required for `CANCELED / REJECTED / RETURNED` (line 262).
  - `apply()` (line 179) wraps `lockForUpdate` + idempotent early-return + audit → safe under concurrent client + auto-transition.

### 1.2 source_surface — actual usage map

`source_surface` values currently set in code:
- `'pos'` — `app/Services/OrderService.php:907` (POS V4 caisse store flow)
- `'kiosk'` — `app/Services/FrontendOrderService.php:522, 859`
- `'web'` — `app/Services/FrontendOrderService.php:522` (fallback)
- `'admin'` — `app/Http/Controllers/Frontend/LoyaltyController.php:227`
- `'mobile'` — declared in DB comment, no writer today

**Gap identified for V1 delivery:** no writer sets `'delivery'`. To add it: a small additive PR to `OrderService::resolveDeliverySurface()` and an `OrderRequest` rule extension. **Zero migration needed** — the column is a nullable string.

`app/Enums/OrderType.php`:
- `DELIVERY=5`, `TAKEAWAY=10`, `POS=15`, `DINING_TABLE=20`, `KIOSK=25`

In V1, kiosk orders are stored as `order_type=TAKEAWAY` *and* `source_surface='kiosk'` (per `OrderRequest:200` enforcing TAKEAWAY for all kiosk orders since `pos.dine_in_enabled=false`). The KDS Vue component **already** correctly buckets by `source_surface` first, falling back to `order_type` (lines 1563–1579).

### 1.3 composition_snapshot schema (NF525 immutable)

Defined by `app/Services/Pricing/CompositionSnapshotBuilder.php:154-160`:

```php
[
  'schema_version' => 1,
  'captured_at'    => ISO8601,
  'lines'   => [['variation_id', 'attribute_id', 'attribute_name', 'variation_name', 'quantity', 'unit_price', 'line_total']],
  'extras'  => [['extra_id', 'extra_name', 'quantity', 'unit_price', 'line_total']],
  'addons'  => [['addon_id', 'addon_item_id', 'addon_name', 'role', 'quantity', 'unit_price', 'line_total', 'catalog_price']],
]
```

- `role` on addons takes values: `'menu_full'`, `'menu_frites'`, `'menu_boisson'`, or `null`. This is THE signal that an addon is part of a Menu Formule. **Critical for adaptive rendering of "Menu Burger Le Cayenne" parent + indented children.**
- Writers: `PricingService::calculateOrder` (line 266) and `OrderService` / `FrontendOrderService` via mass insert.
- Readers: `KDSOrderItemsResource::resolveAddonsForKds()` (lines 33-41) returns `$snapshot['addons']`. Receipt printers also read the same snapshot.
- **NF525 contract:** never overwrite. KDS only reads. No risk on the KDS side.

### 1.4 allergens_snapshot

- Column: `order_items.allergens_snapshot` (json, nullable), migration `2026_04_18_140004_add_allergens_snapshot_to_order_items.php`.
- Writer: `app/Services/Orders/OrderItemAllergenSnapshot::hydrate()` (line 67). Merges item-level `allergens` pivot codes + extra-level `item_extra_allergens` codes.
- Format: JSON array of string codes (FR codes after backfill migration `2026_04_20_131600_backfill_fr_codes_in_order_items_allergens_snapshot.php`).
- Reader on KDS side: `resources/js/helpers/kdsAllergens.js:19-30` — `orderHasAllergens()` walks `order.orderItems[].allergens_snapshot`. Backend hash mirror in `KitchenDisplaySystemOrderService::normalizeAllergensForHash` (line 274).

### 1.5 KDS API endpoints

From `routes/api.php:994-1000`:
- `GET /api/v1/admin/kds-order/` → `KitchenDisplaySystemController::index` → list (KDSOrderDetailsResource)
- `POST /api/v1/admin/kds-order/change-status/{order}` → `KitchenDisplaySystemController::changeStatus` (returns 202)
- `GET /api/v1/admin/kds-order/items` → orderItems (items-board view)
- `GET /api/v1/admin/kds-order/sync` → `KdsSyncController::sync` (delta polling, line 32)

All routes guarded by middleware `permission:kitchen-display-system` (controller __construct line 22).

### 1.6 Echo / WebSocket

Channel **already shared** across POS, Kiosk, KDS:

- Channel: `private-branch.{branchId}` (single, branch-scoped)
- Defined in `routes/channels.php:23` with kiosk-token guard + admin bypass
- Events: `OrderCreated`, `OrderStatusChanged`, `OrderPaymentStatusChanged`, `OrderPaidAtCounter`, `OrderTableChanged`
- Outbox pattern: `PersistOrderCreatedToOutbox:43` writes `DomainEvent` row, `DispatchDomainEventsJob` broadcasts after commit
- broadcast_as: `'OrderCreated'` (line 44)

**No new channel needed.** Adding `'delivery'` source rides the same channel. The KDS subscribes to one channel per branch and receives all sources.

### 1.7 Frontend — KdsSyncService.js (470 lines, SOLID)

`resources/js/services/KdsSyncService.js` per audit cross-validated finding. Polling backoff, version-gate, cleanup confirmed solid. **Do not touch.** Only consume its events from the new Vue component.

### 1.8 Frontend — KitchenDisplaySystemComponent.vue (2353 lines)

Confirmed god-component. Current data flow:
- `mounted()` (line 1173) → `refreshOrderList()` + `startAutoRefresh()` + `subscribeEcho()` + `kdsSyncService.start()` (line 1235)
- Computed `orders` (line 1080) reads `kitchenDisplaySystemOrder/lists` getter
- `_applyOrderBuckets(rows)` (line 1555) fans orders into 4 buckets: `dineinOrders`, `onlineOrders`, `takeawayOrders`, `kioskOrders` (lines 1571-1578) — **this is the 4-column layout that the redesign eliminates**
- Watcher on `orders` (line 1116) plays new-order chime (ID-based diff post RED-R4)
- Axios response interceptor (line 1194) triggers immediate refresh on successful `change-status` POST
- **Bug confirmed at line 1290:** `this.allergenModal` (without 's') vs data property `allergensModal` (line 1280 sets it correctly with 's'). Modal close silently writes to a phantom property and the real `allergensModal.open` stays `true`. QW-1 of existing plan.

### 1.9 Frontend helpers

- `kdsAllergens.js` (53 lines) — pure: `orderHasAllergens`, `sortedAllergens`, `normalizeAllergensForHash`
- `kdsDisplay.js` (85 lines) — `getKdsEscalationClass` (current thresholds 5/10 min — must change to 3/6 min per validated direction), `parseOrderCreatedMs`, `filterOrdersByStation`, `shouldPlayKdsNewOrderSound`
- `kdsLineSemantics.js` (72 lines) — `isLikelyExclusionOrHoldInstruction`, `kdsInstructionVisualClass`. **Allergen keyword regex already covers `allerg / intol / gluten / arach / cacahu / lactos / soja`** — needs Arabic extension for V1 RTL.

### 1.10 Store

- `resources/js/store/modules/kds.js` (87 lines) — item-level bump persistence in localStorage, 60s grace recall. Confirmed: `recallItem` returns `grace_expired` after 60 s (line 71).
- `resources/js/store/modules/kitchenDisplaySystemOrder.js` (70 lines) — thin axios wrapper. `lists` action calls `admin/kds-order`, `changeStatus` calls `admin/kds-order/change-status/{id}`.
- `resources/js/store/modules/kdsInflight.js` — exists per audit memory (not read here, presumed solid pending tests).

### 1.11 i18n — actual key inventory

Naming convention confirmed: `label.kds_*` (underscore), NOT `label.kds.*` (dot). **Critical:** the task brief used `label.kds.*` — we keep the existing snake-style to avoid renaming 47 keys + breaking translation memory.

Verified KDS keys in `resources/js/languages/fr.json` (sample, lines 498-530):
- `label.kds_station_filter`, `label.kds_all_stations`, `label.kds_bar`, `label.kds_cuisine_chaude`, `label.kds_cuisine_froide`, `label.kds_group_by_table`, `label.kds_sound`, `label.kds_volume`
- `label.kds_admin_polling_hint`, `label.kds_order_cap_warning`, `label.kds_order_list_full_warning`, `label.kds_bump_local_only_notice`, `label.kds_items_board_scope`
- `label.kds_dismiss_hint`, `label.kds_fallback_banner`, `label.kds_see_more`
- `label.kds_sync_stamp`, `label.kds_sync_never`
- `label.kds_allergens_badge`, `label.kds_allergens_badge_aria`, `label.kds_allergens_modal_title`, `label.kds_allergens_modal_intro`, `label.kds_allergens_for_item`, `label.kds_allergens_modal_none`
- `label.kds_oos_warning_tooltip`, `label.kds_oos_warning_aria`
- `label.kds_aria_live_preparing/ready/accepted`
- `label.kds_toggle_items`, `button.kds_bump`, `button.kds_recall`, `button.kds_allergens_modal_close`
- `label.kds_connection_lost`, `label.kds_recall_grace_expired`, `label.kds_status_conflict`

**Raw labels in template/JS confirmed present** (sample, line 219 `placeholder="Rechercher une commande"`, line 234 `"Aucune commande sur place en cours."`).

### 1.12 Delivery infrastructure

No aggregator webhook scaffolding exists. Only **in-house delivery boy** (`app/Http/Controllers/Frontend/DeliveryBoyOrderController.php`), `DeliveryFeeService.php`, `DeliveryQuoteService.php`. **No `'delivery'` value is set on `source_surface` anywhere today.** This is the V1 gap to resolve (§13 Q1).

### 1.13 POS V4 & Kiosk wizards (frozen)

- `public/js/pos-wizard.js` (~296 KB, S25-SinglePage Vanilla JS) — frozen, read-only.
- `resources/js/components/frontend/kiosk/KioskWizardComponent.vue` — frozen.

These produce orders that already land correctly on the KDS via the events listed in §1.6. **No changes needed** to integrate the redesign.

---

## 2. Data Model Contract (canonical KDS projection)

### 2.1 Target TypeScript shape consumed by the new Vue component

```ts
type KdsSource = 'POS' | 'KIOSK' | 'DELIVERY' | 'ONLINE' | 'APP' | 'DINE_IN';

type KdsState = 'NEW' | 'PREPARING' | 'READY' | 'DONE' | 'CANCELLED';

type KdsOrder = {
  id: number,
  queueNo: string,            // e.g. "A0024" — already in queue_number column
  source: KdsSource,          // mapped from source_surface (lowercase) → uppercase enum
  rawSource: string,          // 'pos'|'kiosk'|'delivery'|... for diagnostics
  state: KdsState,            // mapped from numeric OrderStatus (see §2.3)
  rawStatus: number,          // 4|7|8|13|16 for diagnostics + state-machine PATCH
  createdAt: string,          // ISO8601, from created_at
  scheduledFor: string | null,// from order_datetime when is_advance_order=YES
  ageSeconds: number,         // computed client-side, ticks every 30s
  ageColor: 'neutral' | 'orange' | 'red',  // derived
  items: KdsOrderLine[],
  hasAllergen: boolean,
  allergenCodes: string[],    // union across items, sorted unique
  isPriority: boolean,        // future: priority queue
  customerNote: string | null,
  paymentPendingCounter: boolean,  // already on KDSOrderDetailsResource (line 39)
  tableName: string | null,   // future dine-in
}

type KdsOrderLine = {
  id: number,                 // order_items.id
  itemId: number,             // order_items.item_id
  qty: number,
  name: string,               // localized item name
  category: KdsCategory,      // 'sandwich'|'taco'|'burger'|'assiette'|'menu_formule'|'side'|'drink'|'dessert'|'other'
  customizations: KdsCustomization[],  // flat list, ordered
  children: KdsOrderLine[],   // populated when category='menu_formule'
  instructionText: string | null,
  instructionClass: 'note' | 'exclusion' | 'allergen',
  hasAllergen: boolean,
  allergenCodes: string[],
}

type KdsCustomization = {
  kind: 'variation' | 'extra' | 'addon',
  group: 'bread' | 'crudites' | 'sauce' | 'supplement' | 'cooking' | 'drink_choice' | 'side_choice' | 'menu_full' | 'menu_frites' | 'menu_boisson' | 'allergen' | 'other',
  label: string,              // localized
  qty: number,
  isAllergen: boolean,
  isPaidSupplement: boolean,  // for yellow italic supplement styling
  isMenuChild: boolean,       // true when role startsWith 'menu_'
}

type KdsCategory = 'sandwich' | 'taco' | 'burger' | 'assiette' | 'menu_formule' | 'side' | 'drink' | 'dessert' | 'other';
```

### 2.2 Mapping from current API to canonical contract

The current `KDSOrderDetailsResource` (lines 20-43) and `KDSOrderItemsResource` (lines 18-27) already emit 90 % of what we need. **Augment, do not rewrite.** Per advisor confirmation: avoid a brand-new server-side `KdsOrderTransformer` class — extend the existing Eloquent resource layer.

| KdsOrder field        | Source (today)                                                            | Action                                |
|-----------------------|---------------------------------------------------------------------------|---------------------------------------|
| `id`                  | `KDSOrderDetailsResource.id`                                              | keep                                  |
| `queueNo`             | `queue_number` (Resource line 40)                                         | keep, rename to camelCase on FE       |
| `source`              | `source_surface` (line 28) → uppercase mapping client-side                | keep, add mapping helper              |
| `rawSource`           | `source_surface` raw                                                      | keep                                  |
| `state`               | `status` (line 36) → mapping table §2.3                                   | keep, add mapping helper              |
| `rawStatus`           | `status`                                                                  | keep                                  |
| `createdAt`           | currently `order_datetime` formatted — needs ISO8601 alongside            | **ADD** `created_at_iso` to resource  |
| `scheduledFor`        | derived from `is_advance_order` + `order_datetime` (line 33)              | keep                                  |
| `items`               | `order_items` collection via `OrderItemResource` (line 41)                | needs adaptive transformer (§3)       |
| `hasAllergen`         | computed client from items                                                | keep client-side                      |
| `allergenCodes`       | derived from `order_items[].allergens_snapshot`                           | keep client-side helper               |
| `customerNote`        | `OrderItem.instruction` (per-line) and/or `Order.note`                    | per-line on KdsOrderLine              |
| `paymentPendingCounter` | already on resource (line 39)                                            | keep                                  |
| `tableName`           | `diningTable?->name` (line 42)                                            | keep                                  |

**ADDITIONS to `KDSOrderDetailsResource` (single PR, additive, no breaking change):**
- `created_at_iso` — `$this->created_at?->toIso8601String()` (ISO8601 for age math)
- `composition_snapshot` projected per OrderItem (already in `KDSOrderItemsResource::resolveAddonsForKds`, line 33)
- `allergens_snapshot` per OrderItem (already on OrderItem; expose via `OrderItemResource`)

The `composition_snapshot.addons[].role` field is THE menu-formule signal — feed it directly into the FE `kdsCustomization.js` transformer.

### 2.3 Status mapping table (canonical)

| UI label (FR/EN/AR) | KdsState     | OrderStatus const          | Numeric |
|---------------------|--------------|----------------------------|---------|
| `Nouvelle` / `New`  | `NEW`        | `ACCEPT`                   | 4       |
| `En préparation`    | `PREPARING`  | `PREPARING`                | 7       |
| `Prête` / `Ready`   | `READY`      | `PREPARED`                 | 8       |
| `Servie` / `Done`   | `DONE`       | `DELIVERED`                | 13      |
| `Annulée`           | `CANCELLED`  | `CANCELED`                 | 16      |

This mapping lives in **one place**: a new constant module `resources/js/helpers/kdsState.js` exporting `KDS_STATE_FROM_STATUS`, `KDS_STATUS_FROM_STATE`, `KDS_STATE_LABELS`. Vue components consume only this module.

### 2.4 Source mapping table

| UI chip | KdsSource    | source_surface | Currently written by                                  |
|---------|--------------|----------------|-------------------------------------------------------|
| `CAISSE`| `POS`        | `'pos'`        | `OrderService.php:907`                                |
| `BORNE` | `KIOSK`      | `'kiosk'`      | `FrontendOrderService.php:522,859`                    |
| `LIVRAISON` | `DELIVERY` | `'delivery'` | **NEW V1** — see §13 Q1                              |
| `WEB`   | `ONLINE`     | `'web'`        | `FrontendOrderService.php:522` fallback              |
| `APP`   | `APP`        | `'mobile'`     | not yet written                                       |
| `SALLE` | `DINE_IN`    | `'dinein'` (proposed; **not yet present**) | future, when dine-in flag flips |

Mapping helper lives in `resources/js/helpers/kdsSource.js` — single source of truth.

### 2.5 Backwards compatibility

- The new `composition_snapshot` projection on the FE is **additive on top of legacy `item_variations` / `item_extras` JSON strings**. Pre-snapshot orders (rare; only legacy data) fall through to the legacy renderer path.
- `KDSOrderItemsResource::resolveAddonsForKds()` (line 33) already does this gracefully: returns `[]` when snapshot is missing.
- No DB writes, no schema changes — **NF525 immutability preserved by construction**.

---

## 3. Adaptive Display Strategy (concrete card-rendering rules)

### 3.1 Single rendering helper: `resources/js/helpers/kdsCustomization.js` (NEW)

The Vue template MUST NOT contain any per-category branching. It calls `renderItem(item)` returning a flat list of typed display lines, then renders them with a single `<KdsOrderLine>` sub-component (created in Sprint 2 — see §9 Sprint 2).

```js
// resources/js/helpers/kdsCustomization.js (V1 design)
//
// Pure function: maps composition_snapshot + item_variations + item_extras + instruction
// to a flat list of typed display nodes consumed by <KdsOrderCard> template.
//
// Rendering rules per category (driven by Item.kds_category column or fallback heuristic):
//
// - SANDWICH:  parent line + grouped variations (Pain/Crudités/Sauce/Cuisson) + extras (italic neutral)
//              + addons.role=menu_full|menu_frites|menu_boisson rendered as indented "Menu" children
// - TACO:      same as sandwich, omit Pain group
// - BURGER:    same as sandwich
// - ASSIETTE:  parent line + comma-joined variations on one line ("Avec : 2 Merguez, 1 Brochette")
// - MENU_FORMULE: parent line ("Menu Burger Le Cayenne") + addons indented as children
// - SIDE/DRINK/DESSERT/OTHER: simple line with optional supplement

export function categorize(orderItem) {
  // Prefer explicit Item.kds_category if backend exposes it (Sprint 3 reversibility column).
  // Fallback: heuristic on item.name or item_category_id.
  const cat = String(orderItem.kds_category || '').toLowerCase();
  if (['sandwich','taco','burger','assiette','menu_formule','side','drink','dessert'].includes(cat)) return cat;
  // Heuristic fallback:
  const name = (orderItem.item_name || '').toLowerCase();
  if (/menu|formule/.test(name)) return 'menu_formule';
  if (/sandwich|kafteji|brick|merguez/.test(name)) return 'sandwich';
  if (/tacos?/.test(name)) return 'taco';
  if (/burger|cayenne/.test(name)) return 'burger';
  if (/assiette|couscous|ojja|lablabi/.test(name)) return 'assiette';
  return 'other';
}

export function renderItem(orderItem) {
  const category = categorize(orderItem);
  const lines = [{ type: 'header', label: orderItem.item_name, qty: orderItem.quantity, category }];

  if (Array.isArray(orderItem.item_variations) && orderItem.item_variations.length) {
    if (category === 'assiette') {
      // Flat one-liner
      const joined = orderItem.item_variations.map(v => `${v.quantity > 1 ? v.quantity + ' ' : ''}${v.name}`).join(', ');
      lines.push({ type: 'variation', group: 'avec', label: joined });
    } else {
      // Group by attribute_name (Pain, Crudités, Sauce, Cuisson)
      const byGroup = {};
      for (const v of orderItem.item_variations) {
        const g = String(v.variation_name || v.attribute_name || 'autre').toLowerCase();
        (byGroup[g] = byGroup[g] || []).push(v);
      }
      for (const [group, vals] of Object.entries(byGroup)) {
        lines.push({
          type: 'variation',
          group,
          label: vals.map(v => v.name).join(', '),
        });
      }
    }
  }

  // Paid supplements (item_extras) — yellow italic, prefixed with "+"
  if (Array.isArray(orderItem.item_extras)) {
    for (const e of orderItem.item_extras) {
      lines.push({
        type: 'supplement',
        label: `+ ${e.name}${e.quantity > 1 ? ' ×' + e.quantity : ''}`,
        isPaidSupplement: true,
      });
    }
  }

  // Menu Formule children — addons with role startsWith 'menu_'
  if (Array.isArray(orderItem.item_addons)) {
    for (const a of orderItem.item_addons) {
      const role = String(a.role || '').toLowerCase();
      const isMenuChild = role.startsWith('menu_');
      lines.push({
        type: isMenuChild ? 'menu_child' : 'addon',
        label: a.addon_name || a.name || '',
        qty: a.quantity || 1,
        role,
        isMenuChild,
      });
    }
  }

  // Free-text instruction — keyword-classified
  if (orderItem.instruction) {
    lines.push({
      type: 'instruction',
      label: orderItem.instruction,
      visualClass: kdsInstructionVisualClass(orderItem.instruction), // from kdsLineSemantics.js
    });
  }

  // Allergens — separate row, orange-bold-italic styling
  const codes = Array.isArray(orderItem.allergens_snapshot) ? orderItem.allergens_snapshot : [];
  if (codes.length) {
    lines.push({ type: 'allergen', codes });
  }

  return { category, lines };
}
```

### 3.2 ASCII renderings (Tunisian fast-food sample data)

**Card 1 — Sandwich with full customization + paid supplements + drink choice:**
```
┌───────────────────────────────────────────────────────────────────┐
│ A0024                                            [CAISSE] · 02:14 │  ← header, source chip, age
│───────────────────────────────────────────────────────────────────│
│ 1×  Sandwich Kafteji                                              │
│      Pain : Baguette traditionnelle                               │
│      Crudités : Salade, Tomate, Oignon                            │
│      Sauce : Harissa                                              │
│      + Cheddar                                       (yellow ital)│
│      + Œuf                                           (yellow ital)│
│      Boisson : Coca 33cl                                          │
│                                                                   │
│ 1×  Frites                                                        │
│      Taille : Grande                                              │
│      + Sauce algérienne                              (yellow ital)│
│───────────────────────────────────────────────────────────────────│
│  [ Prêt ]   60px violet #4C1A96                            [A]    │  ← CTA + bump-bar hint
└───────────────────────────────────────────────────────────────────┘
```

**Card 2 — Tacos XXL, allergen present (orange border override):**
```
┌═══════════════════════════════════════════════════════════════════┐ ← orange 4px border (allergen)
│ A0025                                            [BORNE] · 04:32  │
│───────────────────────────────────────────────────────────────────│
│ 1×  Tacos XXL                                                     │
│      Viande : Cheese-Naan, Steak Haché                            │
│      Sauce : Algérienne, Fromagère                                │
│      + Bacon                                         (yellow ital)│
│      ⚠ ALLERGÈNES : gluten · lait        (orange-bold-italic bg)  │
│      Note : sans oignon (italique grise)                          │
│                                                                   │
│ 2×  Coca 33cl                                                     │
│───────────────────────────────────────────────────────────────────│
│  [ Prêt ]                                                  [B]    │
└═══════════════════════════════════════════════════════════════════┘
```

**Card 3 — Assiette (flat rendering):**
```
┌───────────────────────────────────────────────────────────────────┐
│ A0026                                            [CAISSE] · 03:18 │
│───────────────────────────────────────────────────────────────────│
│ 1×  Assiette Couscous Royal                                       │
│      Avec : 2 Merguez, 1 Brochette de bœuf, Légumes vapeur        │
│                                                                   │
│ 1×  Brick à l'œuf                                                 │
│                                                                   │
│ 1×  Boisson : Eau plate 50cl                                      │
│───────────────────────────────────────────────────────────────────│
│  [ Prêt ]                                                  [C]    │
└───────────────────────────────────────────────────────────────────┘
```

**Card 4 — Menu Formule (parent + indented children):**
```
┌═══════════════════════════════════════════════════════════════════┐ ← red border + pulse (>6 min)
│ A0027                                          [BORNE] · 07:45 ⚠  │
│───────────────────────────────────────────────────────────────────│
│ 1×  Menu Burger Le Cayenne                                        │
│      ▸ Burger Le Cayenne                            (menu_full)   │
│         Cuisson : À point                                         │
│         + Cheddar                                  (yellow ital)  │
│      ▸ Frites Moyennes                              (menu_frites) │
│      ▸ Coca 33cl                                    (menu_boisson)│
│                                                                   │
│ 1×  Lablabi                                                       │
│      + Cumin                                       (yellow ital)  │
│───────────────────────────────────────────────────────────────────│
│  [ Prêt ]   PULSE 1Hz                                      [D]    │
└═══════════════════════════════════════════════════════════════════┘
```

**Card 5 — POS Caisse, simple side + drink:**
```
┌───────────────────────────────────────────────────────────────────┐
│ A0028                                            [CAISSE] · 00:42 │
│───────────────────────────────────────────────────────────────────│
│ 1×  Frites                                                        │
│      Taille : Petite                                              │
│      + Sauce harissa                                (yellow ital) │
│                                                                   │
│ 2×  Fanta Orange 33cl                                             │
│───────────────────────────────────────────────────────────────────│
│  [ Prêt ]                                                  [E]    │
└───────────────────────────────────────────────────────────────────┘
```

**Card 6 — Delivery (V1 future chip):**
```
┌───────────────────────────────────────────────────────────────────┐
│ A0029                                       [LIVRAISON] · 01:55   │ ← turquoise chip (reserved)
│───────────────────────────────────────────────────────────────────│
│ 2×  Sandwich Kafteji                                              │
│      Pain : Baguette                                              │
│      Sauce : Harissa                                              │
│                                                                   │
│ Prévu : 12h45  (scheduledFor)                                     │
│───────────────────────────────────────────────────────────────────│
│  [ Prêt ]                                                  [F]    │
└───────────────────────────────────────────────────────────────────┘
```

### 3.3 Edge case rules

| Case                                  | Rule                                                                 |
|---------------------------------------|----------------------------------------------------------------------|
| Quantity ≥ 2 of same item, same customs | Single line, `qty×` prefix, full customization once               |
| Quantity ≥ 2, **different** customs   | Two separate KdsOrderLines (already how DB stores them)              |
| Item with 6+ customization lines      | Card internal scroll (`overflow-y:auto` on card body), visible scrollbar styled |
| Modifier label > 30 chars             | Wrap 2 lines + ellipsis on 3rd                                       |
| Adaptive font sizing                  | **NO** — constant 22 px item / 14 px modifier / 12 px supplement     |
| 27" 1080p (1920×1080)                 | Grid 4×2, 8 cards, ~448×500 px each                                  |
| 32" 4K (3840×2160)                    | Grid 5×3 or 4×3, 12-15 cards via media query `@media (min-width:2560px)` |

### 3.4 Color tokens (no new palette, brand-preserving)

| Token                | Hex          | Usage                                          |
|----------------------|--------------|------------------------------------------------|
| `--kds-primary`      | `#4C1A96`    | Prêt CTA (brand violet, validated)             |
| `--kds-allergen`     | `#C2410C`    | Allergen text (bold italic)                    |
| `--kds-allergen-border` | `#F97316` | Card border override when allergen present     |
| `--kds-supplement`   | `#CA8A04`    | Paid supplement (italic)                       |
| `--kds-age-orange`   | `#F97316`    | Border + bg tint @ 3-6 min                     |
| `--kds-age-red`      | `#DC2626`    | Border + bg tint @ >6 min + pulse              |
| `--kds-source-pos`   | `#1F2937`    | CAISSE chip                                    |
| `--kds-source-kiosk` | `#0E7490`    | BORNE chip                                     |
| `--kds-source-delivery` | `#0F766E` | LIVRAISON chip (reserved V1)                  |
| `--kds-source-online` | `#7C3AED`   | reserved future                                |
| `--kds-source-app`   | `#BE185D`    | reserved future                                |
| `--kds-source-dinein` | `#92400E`   | reserved future (when flag flips)              |

---

## 4. Source Orchestration Plan (multi-channel)

### 4.1 Existing ingestion paths (confirmed working)

| Source | Path                                                                                                  | Sets source_surface     |
|--------|--------------------------------------------------------------------------------------------------------|--------------------------|
| POS    | `pos-wizard.js` → `POST /admin/order` → `OrderController::store` → `OrderService.php:907`              | `'pos'`                  |
| KIOSK  | `KioskWizardComponent.vue` → `POST /frontend/order` → `FrontendOrderController::store` → `FrontendOrderService.php:522,859` | `'kiosk'` |

Both fire `OrderCreated` event → `PersistOrderCreatedToOutbox:43` writes domain event → `DispatchDomainEventsJob` broadcasts on `private-branch.{branchId}` channel as `OrderCreated` event.

### 4.2 NEW V1 — Delivery ingestion (owner-decision §13 Q1)

**Three candidate paths, owner picks ONE for V1:**

| Path | Pros | Cons | Effort |
|------|------|------|--------|
| **A. Manual entry** by POS staff (new "Livraison" tab in POS wizard) | Zero infra, ready Sprint 2 | Cashier overhead, no real aggregator | 0.5 day |
| **B. Aggregator webhook** scaffold (Uber Eats / Deliveroo) | Real ops value | Per-aggregator OAuth, signature, dead-letter retry | 3-4 days Sprint 3 |
| **C. Dedicated dashboard** (admin/delivery-intake) | UI-first, flexible | Builds duplicate of POS wizard | 1-2 days Sprint 3 |

**Whichever path is picked, the unifying point is the same:**
- All three set `source_surface = 'delivery'`
- All three set `order_type = OrderType::DELIVERY` (=5, already in enum)
- All three call `OrderService::store()` or `FrontendOrderService::store()` → fire `OrderCreated` → outbox → broadcast on `private-branch.{branchId}` → KDS picks up automatically

**No new event class, no new channel.** The plan ships Sprint 2 with the chip + i18n + UI fully ready for `'delivery'` orders, even if zero land in V1.

### 4.3 Unified Echo subscription (already in place)

The KDS Vue component already subscribes to `private-branch.{branchId}` via `subscribeEcho()` (line 1178). It receives **all sources on one channel**. No fragmentation needed.

### 4.4 Idempotency

`X-Idempotency-Key` middleware (`app/Http/Middleware/IdempotencyKeyMiddleware.php`, frozen) scope `(branch_id, user_id, hash(key))` handles dedup on `POST /order` and `POST /frontend/order`. Replays return cached 2xx response. **Reuses for delivery POST.** Zero new code.

### 4.5 NF525 fiscal_sequence_no allocation timing

Per `CLAUDE.md §8`:
- Kiosk paid → fiscal_sequence_no at create (transaction-bound)
- POS cash → allocation at close
- **Delivery prepaid (aggregator) → at create** (same as kiosk paid)
- **Delivery COD (cash on delivery) → at delivery moment**

Allocation lives in `FiscalSequenceService.php` (frozen). KDS only reads; no changes.

### 4.6 Branch isolation

Every source-handler already applies `branch_id` correctly per audit (`CLAUDE.md §9.1`, BranchScope on 11 models including `Order`, `FrontendOrder`, `OrderItem`). Adding `'delivery'` does not change this.

### 4.7 Duplications to eliminate

After audit:
- **No echo channel duplication today** (single `private-branch.{branchId}` confirmed).
- **One duplication on FE**: `_applyOrderBuckets` (line 1555) fans into 4 buckets used by 4 template blocks (~550 lines duplicated). Sprint 2 collapses to **one** bucket `unifiedQueue` → one `<KdsOrderCard>` repeated.

### 4.8 Gaps identified

- `source_surface = 'delivery'` writer (none today) — Sprint 2 adds it for the chosen ingestion path.
- `'dinein'` value not yet defined for `source_surface` — out of V1 scope, reserved.
- `'mobile'` declared in column comment but no writer — out of V1 scope, reserved.

---

## 5. State Machine Integration

### 5.1 Auto-transition NEW → PREPARING — client-side

**Decision (per advisor + frozen-zone discipline):** auto-transition lives client-side in Vue. The frozen `OrderStateMachine.php` already supports `ACCEPT → PREPARING` as a normal transition (line 45) and is concurrent-safe via `apply()` + `lockForUpdate` (line 210). The client merely fires the existing endpoint earlier.

**Rule (single source of truth in `resources/js/helpers/kdsAutoTransition.js`, NEW):**

```js
// resources/js/helpers/kdsAutoTransition.js
//
// Single-chef takeaway-only V1 justification (RESEARCH §4.3):
// only ONE order can physically be in prep at once. The system infers
// "started prep" with near-zero false-positive risk.
//
// Rule: when a new ACCEPT order enters the queue, if zero other orders are
// PREPARING, auto-PATCH that order to PREPARING. Otherwise leave at ACCEPT.

export function shouldAutoTransition(orderJustEntered, currentQueue, featureFlag) {
  if (!featureFlag) return false;
  if (orderJustEntered.rawStatus !== ORDER_STATUS_ACCEPT) return false;
  const othersPreparing = currentQueue.filter(o => o.rawStatus === ORDER_STATUS_PREPARING);
  return othersPreparing.length === 0;
}
```

Wiring (in `resources/js/store/modules/kitchenDisplaySystemOrder.js` or new mixin):
- On every `lists` action commit, evaluate `shouldAutoTransition` on newcomers.
- If true → dispatch `changeStatus({ id, status: PREPARING })` → existing endpoint serializes via `OrderStateMachine::apply` lockForUpdate.

**Feature flag:** `config('kds.auto_transition_enabled', true)`. Owner can disable.

**Idempotency:** `OrderStateMachine::apply` (line 215) idempotent early-return — concurrent dispatches harmless. UI also debounces 300 ms via the existing `_debouncedRefresh` machinery (line 1227).

### 5.2 Bump CTA "Prêt" → READY

UI flow:
1. Chef taps `Prêt` button (60 px CTA, brand violet) OR presses bump-bar key A-H corresponding to card slot
2. Optimistic UI: card fades out 150 ms
3. POST `admin/kds-order/change-status/{order}` with `{ status: PREPARED, expected_status: order.status }` (already in `kdsStatusPayload` helper, `kds.js:3`)
4. Existing axios response interceptor (line 1194) triggers refresh on 2xx
5. **3-second undo Toast** shows: `[Annuler]` button → POSTs reverse transition `PREPARED → PREPARING` (legal per state machine? **NO** — `PREPARED` only goes to `OUT_FOR_DELIVERY` or `DELIVERED` per `OrderStateMachine::allows` line 55).

**Reverse transition complication:** the state machine does not allow `PREPARED → PREPARING`. Two options:
- (a) For the 3 s grace window, undo is **client-only** — the optimistic UI restores the card client-side, but server PATCH has not yet fired. We delay the actual POST by 3 s in a setTimeout, cancelable by undo button. **This is the recommended path.** Already half-implemented via `kdsInflight` queue.
- (b) Bend the state machine to allow `PREPARED → PREPARING` with role gate. Touches frozen zone → owner gate required, NOT V1.

**Per-item bump grace** (existing 60 s recall in `kds.js:71`) — keep as-is, that's a separate localStorage feature.

### 5.3 Cancel handling

When POS or admin cancels an order already in `PREPARING`:
- Order broadcast triggers `OrderStatusChanged` event → outbox → broadcast
- KDS receives, refreshes, finds order with `status=CANCELED` (=16)
- Card renders with **red strikethrough overlay + "ANNULÉ" banner**
- Card stays visible until chef taps an explicit "Dismiss" action (no auto-disappear, to enforce chef awareness)
- After dismiss, card is filtered out client-side; no server PATCH needed (terminal state)

### 5.4 Recall (un-bump)

Two distinct recall mechanisms — must not be conflated:

| Mechanism                  | Scope          | Duration | Already implemented      |
|----------------------------|----------------|----------|--------------------------|
| Card-level Prêt undo Toast | Whole order    | 3 s      | Sprint 2 NEW             |
| Per-item bump recall       | Single line item | 60 s   | `kds.js:71` (keep)       |

Sprint 3 may add a "Recently completed" tray (Fresh KDS pattern) for after-3s server-side recall, but that requires state-machine extension and is gated on owner approval.

---

## 6. Sync + Real-time Architecture

### 6.1 Echo channel structure

- **Single channel per branch:** `private-branch.{branchId}` (existing, shared with POS and Kiosk).
- **Events delivered:** `OrderCreated`, `OrderStatusChanged`, `OrderPaymentStatusChanged`, `OrderCancelled` (via OrderStatusChanged), `OrderTableChanged`.
- **Future reservations:** `kds.station.{stationId}` for Sprint 4+ multi-station — NOT created in V1.

### 6.2 KdsSyncService.js — confirmed solid, do not touch

Existing service (470 lines) handles:
- Adaptive polling fallback when WS down (pauses when WS=CONNECTED, accelerates when degraded)
- Backoff on 5xx (verified by `kdsBackoffOn5xx.spec`)
- Reconnect storm safety (verified by `kdsReactsToReconnectStorm.spec`)
- Per-order version-gate (verified by `kdsVersionGate.spec`)
- Memory cleanup on unmount

**The integration plan must not modify this service.** New Vue component just consumes `kdsSyncService.on('sync')` / `on('error')` events.

### 6.3 Conflict resolution

Server is SSOT. Client reconciles via:
- `KDSOrderDetailsResource` returns `updated_at` Unix seconds as `version` (already done, `KdsSyncService.php:135-142`).
- Vue component compares incoming version to local version per order; drops stale updates.
- HTTP 409 from `changeStatus` (per `kitchenDisplaySystemOrder.js:42`) triggers full refresh.

### 6.4 Offline handling

When WS is down AND polling fails repeatedly:
- `KdsSyncService` exposes `degraded=true` event
- Existing `kds-sync-mode-banner` (line 23) shows the fallback message
- `kdsInflight` queue buffers user PATCH actions locally until reconnect
- Card UI continues rendering last-known queue (offline-friendly per RESEARCH §1.2 Lightspeed pattern)

---

## 7. i18n Migration Plan

### 7.1 Convention (corrected from task brief)

Task brief proposed `label.kds.{component}.{element}` (dot notation). **The codebase uses `label.kds_*` (snake)** — keep existing convention to avoid renaming 47 keys + breaking translation memory. New keys follow the same pattern.

### 7.2 New keys for V1 redesign (~30-40 net new)

State labels:
```
label.kds_state_new          / "Nouvelle" / "New" / "جديدة"
label.kds_state_preparing    / "En préparation" / "Preparing" / "قيد التحضير"
label.kds_state_ready        / "Prête" / "Ready" / "جاهزة"
label.kds_state_done         / "Servie" / "Done" / "تم"
label.kds_state_cancelled    / "Annulée" / "Cancelled" / "ملغاة"
```

Source chip labels:
```
label.kds_source_pos         / "Caisse" / "POS" / "كاشير"
label.kds_source_kiosk       / "Borne" / "Kiosk" / "كشك"
label.kds_source_delivery    / "Livraison" / "Delivery" / "توصيل"
label.kds_source_online      / "En ligne" / "Online" / "أونلاين"   (reserved)
label.kds_source_app         / "App" / "App" / "تطبيق"             (reserved)
label.kds_source_dinein      / "Sur place" / "Dine-in" / "في المحل" (reserved)
```

Card customization group labels:
```
label.kds_group_bread        / "Pain" / "Bread" / "خبز"
label.kds_group_crudites     / "Crudités" / "Vegetables" / "خضار"
label.kds_group_sauce        / "Sauce" / "Sauce" / "صلصة"
label.kds_group_supplement   / "Supplément" / "Supplement" / "إضافة"
label.kds_group_cooking      / "Cuisson" / "Cooking" / "نضج"
label.kds_group_drink        / "Boisson" / "Drink" / "مشروب"
label.kds_group_avec         / "Avec" / "With" / "مع"
label.kds_group_menu         / "Menu" / "Menu" / "قائمة"
```

Toast / actions:
```
label.kds_undo_bump          / "Annuler le bump" / "Undo bump" / "تراجع"
label.kds_undo_bump_aria     / aria-label
label.kds_card_cta_ready     / "Prêt" / "Ready" / "جاهز"
label.kds_card_cancelled_overlay / "ANNULÉ" / "CANCELLED" / "ملغاة"
label.kds_card_cancelled_dismiss / "Confirmer" / "Confirm" / "تأكيد"
label.kds_age_short          / "{m}min {s}s" — FR/EN; AR: "{s}ث {m}د" (RTL-safe)
```

Settings menu (replaces "Vider l'écran"):
```
label.kds_settings_title     / "Paramètres" / "Settings" / "الإعدادات"
label.kds_settings_sound     / "Son nouvelle commande" / "New order sound" / "صوت طلب جديد"
label.kds_settings_volume    / "Volume" / "Volume" / "مستوى الصوت"
label.kds_settings_theme     / "Thème sombre" / "Dark theme" / "السمة الداكنة"  (Sprint 3)
label.kds_settings_auto_transition / "Démarrage auto" / "Auto start" / "بدء تلقائي"
```

Allergen icon + text (FR/EN/AR) — extends `kdsLineSemantics.js` allergen regex:
```
label.kds_allergen_warning_prefix / "⚠ Allergènes : " / "⚠ Allergens: " / "⚠ مسببات حساسية: "
```

### 7.3 Raw labels to extract (Sprint 1 QW-10)

Confirmed from `KitchenDisplaySystemComponent.vue`:
- L219 `placeholder="Rechercher une commande"` → `label.kds_search_placeholder`
- L234 `"Aucune commande sur place en cours."` → `label.kds_empty_dinein`
- L545 `"Aucune commande à emporter en cours."` → `label.kds_empty_takeaway`
- L696 `"Aucune commande borne en cours."` → `label.kds_empty_kiosk`
- L403 `"Aucune commande en ligne en cours."` → `label.kds_empty_online`
- 5 raw labels in `printKitchenTicket()` JS — `Sur place / Livraison / À emporter / Caisse / Borne` → `label.kds_ticket_*`
- "Print ticket" appearances ×4 → `button.kds_print_ticket`
- "Payment pending" ×2 → `label.kds_payment_pending`
- "Queue number" ×2 → `label.kds_queue_number_label`

Total extracted: 18 raw labels → 14 net new keys (some are reusable).

### 7.4 FR/EN/AR completeness check

Every NEW key must land simultaneously in `resources/js/languages/fr.json`, `en.json`, and `ar.json`. **No fallback to FR for AR** — `CLAUDE.md §16` final rule. CI script `scripts/i18n_check.sh` (existing) enforces parity.

### 7.5 RTL specifics for Arabic

- Time format: `1د 23ث` (minutes-seconds reversed) — handled by ICU MessageFormat.
- Order number prefix: `#A0024` works in RTL (no special treatment).
- Card layout: `dir="rtl"` toggled at root when locale=ar. Source chip moves to left edge, age moves to right edge automatically via flex.
- Allergen warning prefix uses `⚠` icon — Unicode bidi-neutral, renders correctly.
- Swiper `:dir="direction"` already wired (line 184) — keep.

---

## 8. Frozen-Zone Respect (HARD CONSTRAINT)

### 8.1 DO NOT TOUCH

Per `CLAUDE.md §7` and confirmed by direct read:
- `resources/js/services/KdsSyncService.js` (470 lines) — solid, audit cross-validated
- `app/Services/Fiscal/FiscalSequenceService.php`
- `app/Services/Fiscal/ZReportService.php`
- `app/Services/Fiscal/AuditLogService.php`
- `app/Models/Scopes/BranchScope.php`
- `app/Http/Middleware/IdempotencyKeyMiddleware.php`
- `app/Services/Pricing/PricingService.php`
- `app/Services/Pricing/CompositionSnapshotBuilder.php` (NF525 immutability constraint)
- `app/Domain/Order/OrderStateMachine.php` — but `apply()` is **safe to call** from new code paths
- `public/js/pos-wizard.js` (296 KB Vanilla JS)
- `public/css/pos-wizard.css`
- `resources/views/admin-pos-v4.blade.php`
- `resources/js/components/frontend/kiosk/KioskWizardComponent.vue`
- `resources/js/components/frontend/kiosk/KioskAppComponent.vue`
- `resources/js/components/frontend/kiosk/KioskUpsellComponent.vue`

### 8.2 TOUCHABLE (this plan modifies)

- `resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue` — orchestrator, **target of refacto** in Sprint 2 (down from 2353 lines to ~600)
- `resources/js/store/modules/kds.js`
- `resources/js/store/modules/kdsInflight.js`
- `resources/js/store/modules/kitchenDisplaySystemOrder.js`
- `resources/js/helpers/kdsAllergens.js`
- `resources/js/helpers/kdsDisplay.js`
- `resources/js/helpers/kdsLineSemantics.js`
- `resources/js/languages/{fr,en,ar}.json`
- `app/Http/Resources/KDSOrderDetailsResource.php` (additive: add `created_at_iso`)
- `app/Http/Resources/KDSOrderItemsResource.php` (additive: expose `allergens_snapshot`)
- `app/Http/Resources/OrderItemResource.php` (additive: expose `composition_snapshot` per item)

### 8.3 NEW FILES TO CREATE (V1 scope)

- `resources/js/helpers/kdsCustomization.js` — adaptive renderer
- `resources/js/helpers/kdsState.js` — state ↔ status mapping
- `resources/js/helpers/kdsSource.js` — source ↔ source_surface mapping
- `resources/js/helpers/kdsAutoTransition.js` — auto-transition rule
- `resources/js/components/admin/kitchenDisplaySystem/KdsOrderCard.vue` — single canonical card (Sprint 2)
- `resources/js/components/admin/kitchenDisplaySystem/KdsOrderLine.vue` — single line renderer (Sprint 2)
- `resources/js/components/admin/kitchenDisplaySystem/KdsStatusBanner.vue` — banner consolidator
- `resources/js/components/admin/kitchenDisplaySystem/KdsSettingsMenu.vue` — replace "Vider l'écran" (Sprint 3)

### 8.4 SCHEMA — additive only, nullable, reversible

Per RESEARCH §4.9 reversibility plan, add NOW (cheap insurance):
- `items.kds_category` nullable VARCHAR(32) — populated lazily by admin per item
- `items.kds_station_id` nullable BIGINT — Sprint 4+ multi-station readiness
- `order_items.fulfilled_at` nullable TIMESTAMP — Sprint 4+ item-level expediter readiness

Three additive nullable migrations, zero behavior change for V1 readers. Owner-gate required because schema touches.

---

## 9. Implementation Phasing

### Sprint 1 — Orthogonal quick wins (≤2 h, risk ~0)

ONLY items that won't be rewritten in Sprint 2. Skip everything else.

| ID    | Action                                                                  | File                                                | Effort |
|-------|-------------------------------------------------------------------------|------------------------------------------------------|--------|
| QW-1  | Fix `allergenModal` → `allergensModal` typo at line 1290               | KitchenDisplaySystemComponent.vue                   | 5 min  |
| QW-9  | `@media (prefers-reduced-motion: reduce)` guard on pulse + Swiper transitions | KitchenDisplaySystemComponent.vue `<style scoped>` | 15 min |
| QW-10 | Extract 18 raw labels → i18n keys FR/EN/AR (template + `printKitchenTicket` JS) | template + JSON files                              | 1.5 h  |

**Skipped from existing PLAN_KDS_IMPROVEMENT (deferred to Sprint 2 rewrite):** QW-2 accordion (cards open by default — irrelevant after redesign), QW-3 bump 32→60 px (CTA reshaped Sprint 2), QW-4 wait class (age coloring reshaped Sprint 2), QW-5 hide 2 cols (whole layout reshaped Sprint 2), QW-6 banner consolidation (Sprint 2 redesign collapses banners), QW-7 aria-expanded (no more accordion in Sprint 2), QW-8 grey contrast (palette reshaped Sprint 2).

**Sprint 1 acceptance:** all existing Vitest specs green + 1 new vitest spec for allergens modal close (regression for QW-1) + visual capture confirms no UI break + 3 raw labels disappear from rendered FR.

---

### Sprint 2 — Full redesign (1.5–2 days, risk modéré)

#### 2.1 Backend (additive, non-breaking, ~2 h)

| Task | File | Action |
|------|------|--------|
| B-1  | `app/Http/Resources/KDSOrderDetailsResource.php` | Add `created_at_iso` field |
| B-2  | `app/Http/Resources/OrderItemResource.php` | Expose `composition_snapshot`, `allergens_snapshot`, `instruction` |
| B-3  | `database/migrations/2026_05_xx_add_kds_category_to_items.php` | Nullable VARCHAR(32) — Sprint 3 populates |
| B-4  | (if owner picks delivery path A) `app/Services/OrderService.php` add `'delivery'` source-set helper | additive |

#### 2.2 Frontend helpers (NEW, pure, unit-tested, ~3 h)

| Task | File | Action |
|------|------|--------|
| F-1  | `resources/js/helpers/kdsState.js` | NEW — state ↔ status mapping |
| F-2  | `resources/js/helpers/kdsSource.js` | NEW — source ↔ source_surface mapping |
| F-3  | `resources/js/helpers/kdsCustomization.js` | NEW — adaptive renderer |
| F-4  | `resources/js/helpers/kdsAutoTransition.js` | NEW — auto-transition rule |
| F-5  | `resources/js/helpers/kdsDisplay.js` | UPDATE — change thresholds 5/10 → 3/6 min in `getKdsEscalationClass` |
| F-6  | `resources/js/helpers/kdsLineSemantics.js` | UPDATE — extend allergen regex with Arabic codes |
| F-7  | `resources/js/helpers/kdsAllergens.js` | KEEP, no change |

#### 2.3 Vue components (refacto, ~6 h)

| Task | File | Action |
|------|------|--------|
| V-1  | `KdsOrderCard.vue` | NEW — single canonical card, allergen border override, age coloring, bump CTA 60 px |
| V-2  | `KdsOrderLine.vue` | NEW — single line renderer consuming `renderItem()` output |
| V-3  | `KdsStatusBanner.vue` | NEW — consolidate 5 banners into one priority-ranked banner |
| V-4  | `KdsUndoToast.vue` | NEW — 3s undo Toast component |
| V-5  | `KitchenDisplaySystemComponent.vue` | REFACTO — eliminate 4-column layout, become orchestrator (~600 lines) consuming `unifiedQueue` |

#### 2.4 Store (~1 h)

| Task | File | Action |
|------|------|--------|
| S-1  | `kitchenDisplaySystemOrder.js` | Add `unifiedQueue` getter sorting by `created_at` ascending |
| S-2  | `kdsInflight.js` | Add 3s-pending bump queue with cancellation handle |

#### 2.5 i18n (~2 h)

Add the ~40 keys from §7.2 across FR/EN/AR. CI script `scripts/i18n_check.sh` enforces parity.

#### 2.6 Tests (~3 h)

- Vitest new: `kdsCustomization.spec.js`, `kdsAutoTransition.spec.js`, `kdsState.spec.js`, `kdsSource.spec.js`, `unifiedQueueFifo.spec.js`
- Vitest update: `kdsDisplay.spec.js` for new 3/6 min thresholds
- Playwright E2E new: `kds-single-queue-fifo.spec.js`, `kds-allergen-border-override.spec.js`, `kds-bump-undo-3s.spec.js`, `kds-auto-transition.spec.js`

**Sprint 2 acceptance:** all specs green (existing + new), visual baseline match for both 1080p + 4K, owner-gate sign-off on captured screens.

---

### Sprint 3 — Advanced (3-4 days, risk haut, owner-gate required)

| ID    | Action                                                                                              | Effort |
|-------|-----------------------------------------------------------------------------------------------------|--------|
| RF-1  | Production list view (Lightspeed Items List View parity) toggleable from header                     | 1 d    |
| RF-2  | Recall tray (Fresh KDS) — "Recently completed" panel with after-3s-server-side recall (needs state-machine extension OR DELIVERED→PREPARING admin-only rule via `OrderStateMachine::allows` line 67 — owner-gate frozen-zone touch) | 1 d    |
| RF-3  | Settings menu (replaces "Vider l'écran" trash icon) — wraps sound, volume, dark mode toggle, auto-transition flag | 0.5 d  |
| RF-4  | Dark mode scaffolding — `@media (prefers-color-scheme: dark)` + toggle, fond `#0F0F10`              | 0.5 d  |
| RF-5  | Delivery aggregator webhook (if owner picks path B Sprint 3) — Uber Eats + Deliveroo OAuth + signed webhook + retry queue + dead-letter | 2 d |
| RF-6  | Keyboard + bump-bar full integration: [A]-[H] keys, [Enter]=bump first card, [Esc]=open settings   | 0.5 d  |
| RF-7  | Schema add `kds_station_id` + `fulfilled_at` nullable cols (multi-station readiness)                | 0.5 d  |

**Sprint 3 acceptance:** owner-gate per RF, visual capture at both resolutions, mock aggregator webhook for RF-5 with retry test.

---

## 10. Testing Strategy

### 10.1 Vitest unit (new + updated)

| Spec | Asserts |
|------|---------|
| `kdsCustomization.spec.js` (NEW) | per-category rendering rules: sandwich groups Pain/Crudités/Sauce; assiette flattens to "Avec : 2 Merguez, 1 Brochette"; menu_formule emits parent + indented children; allergen line emitted last |
| `kdsAutoTransition.spec.js` (NEW) | `shouldAutoTransition` returns true only when no other order PREPARING + flag enabled |
| `kdsState.spec.js` (NEW) | mapping table 4↔NEW, 7↔PREPARING, 8↔READY, 13↔DONE |
| `kdsSource.spec.js` (NEW) | mapping `'kiosk'` → `'KIOSK'`, `'delivery'` → `'DELIVERY'`, etc. |
| `unifiedQueueFifo.spec.js` (NEW) | created_at ASC, stable id tiebreaker |
| `kdsDisplay.spec.js` (UPDATE) | thresholds 0-3 min neutral, 3-6 orange, >6 red |
| `kdsAllergens.spec.js` (KEEP green) | already covers `orderHasAllergens`, `sortedAllergens` |
| `kdsLineSemantics.spec.js` (KEEP green) | already covers exclusion / allergen / note classification |
| `kdsSyncCadence`, `kdsStationFilter`, `kdsBumpRecall`, `kdsVersionGate`, `kdsTimerEscalation`, `kdsBackoffOn5xx`, `kdsReactsToReconnectStorm` (KEEP green) | regression suite |

### 10.2 Playwright E2E (new + existing)

NEW for V1:
- `kds-single-queue-fifo.spec.js` — POS + Kiosk both fire orders, KDS shows unified ordered grid
- `kds-allergen-border-override.spec.js` — order with `allergens_snapshot=['gluten']` → orange border regardless of age
- `kds-bump-undo-3s.spec.js` — bump → toast visible → click undo within 3 s → card returns
- `kds-auto-transition.spec.js` — first ACCEPT order auto-PATCHes to PREPARING; second concurrent ACCEPT stays
- `kds-source-chip-rendering.spec.js` — POS→`CAISSE`, KIOSK→`BORNE`, simulated delivery→`LIVRAISON`
- `kds-customization-adaptive.spec.js` — sandwich, taco, burger, assiette, menu_formule rendering snapshots
- `kds-bump-bar-keys.spec.js` — `[A]` bumps card 1, `[B]` card 2, …
- `kds-rtl-arabic.spec.js` — full Arabic render, RTL layout, allergen prefix renders correctly
- `kds-cancel-overlay.spec.js` — POS cancels order in PREPARING → KDS shows red strikethrough + ANNULÉ banner

KEEP green:
- `04-kds-status.spec.js`, `audit-kds-cycle1..4.spec.js`, `red-team-r4-kds-reception.spec.js`, `test-e2e-pos-kds-sync-D/E/F.spec.js`, `audit-kiosk-multiproduct-kds-journey.spec.js`, `audit-pos-multiproduct-kds-journey.spec.js`

### 10.3 Visual baseline

Compare new captures vs `tests/e2e/__screenshots__/kds/kds-kds-grid-iter-5-3840x2160.png` baseline + new `kds-redesign-2026-05-11-3840x2160.png` and `kds-redesign-2026-05-11-1920x1080.png`.

### 10.4 Multi-language smoke

Run KDS E2E suite with each locale (FR / EN / AR) via Playwright `test.use({ locale: 'fr-FR' })` etc. Verify zero raw labels (regex `/Label\./` and `/label\.kds_/` against rendered DOM).

### 10.5 Cross-resolution

Playwright matrix:
- 1920×1080 (27" Full HD) — primary target, grid 4×2
- 2560×1440 (32" 1440p) — grid 4×2 with bigger cards
- 3840×2160 (32" 4K) — grid 5×3 via media query

---

## 11. Anti-Drift Rules

The hard discipline that prevents "ULTRA-PLAN → execution drift":

1. **Single canonical contract.** The `KdsOrder` / `KdsOrderLine` / `KdsCustomization` types in `kdsState.js` / `kdsSource.js` / `kdsCustomization.js` are the **only** representation Vue components consume. **No per-source rendering branches in Vue.** Reviewer rejects any `if (source === 'KIOSK')` in `<template>` block.

2. **Customization rendering = ONE place.** `kdsCustomization.js::renderItem` is the only function that maps composition_snapshot to displayable lines. Vue calls it; templates iterate; never inline.

3. **Auto-transition rule = ONE place.** `kdsAutoTransition.js::shouldAutoTransition`. Vue watcher imports this function. Not duplicated.

4. **i18n keys convention.** `label.kds_{group}_{item}` (snake) — existing pattern preserved. New keys MUST land in FR + EN + AR simultaneously. CI parity check enforces.

5. **Echo channel = ONE.** `private-branch.{branchId}`. Reviewer rejects any new channel introduced for KDS specifically.

6. **Backend canonical projection = ONE.** `KDSOrderDetailsResource` extended additively. NO new `KdsOrderTransformer` class.

7. **State enum = centralized.** Numeric `OrderStatus` const on PHP side; `kdsState.js` exports the mapping. Any new UI state requires updating exactly two files.

8. **Source enum = centralized.** Lowercase `source_surface` strings on PHP/DB; `kdsSource.js` exports `SOURCE_FROM_SURFACE` mapping. Adding a new source = update one map + one mapping table + one i18n key set.

9. **Frozen zones = inviolable.** Pre-commit hook `scripts/safety-check.sh` runs git diff vs frozen list (`memory/reference_frozen_zones.md`). Any change requires a `plans/LOCK_<id>.md` doc.

10. **No accordion, ever.** Items always inline. Reviewer rejects any `v-show` on item list.

---

## 12. Risk Register

| ID | Risk | Likelihood | Impact | Mitigation |
|----|------|-----------|--------|------------|
| R1 | `composition_snapshot` schema evolution breaks NF525 immutability | LOW (we only read) | HIGH | KDS code is read-only on this field; no writers in scope |
| R2 | Adding `'delivery'` to `source_surface` breaks switch statements | LOW | MEDIUM | Grep all `case 'pos'|'kiosk'|'web'|'mobile'|'admin'` and add `'delivery'` arm; FE/BE search before merge |
| R3 | Echo channel changes break existing kiosk fiscal flow | LOW (additive only) | HIGH | Do NOT rename `OrderCreated` / `OrderStatusChanged` events; channel stays `private-branch.{id}` |
| R4 | i18n key renames break translations | LOW (no renames planned) | MEDIUM | Add new keys; keep all 47 existing `label.kds_*` as-is |
| R5 | Auto-transition + manual bump race conditions | LOW (state machine `apply()` lockForUpdate) | MEDIUM | Server-side `OrderStateMachine::apply` idempotent early-return covers it; client-side 300 ms debounce |
| R6 | 4K vs 1080p card density tuning | MEDIUM | LOW | Media query breakpoint at 2560 px + visual baseline at both resolutions |
| R7 | Frozen-zone violation during integration | LOW | HIGH | Pre-commit `safety-check.sh` + manual review checkpoint per `CLAUDE.md §10` human gate |
| R8 | Delivery aggregator webhook reliability (if owner picks path B) | HIGH | MEDIUM | Retry queue + dead-letter (existing outbox patterns); start with Deliveroo only V1 |
| R9 | Single-chef auto-transition assumption breaks day-2 multi-chef | LOW (long horizon) | LOW | Feature flag `kds.auto_transition_enabled`; degrades cleanly when condition "zero PREPARING" rarely satisfied |
| R10 | Vue refacto introduces regression in production KDS used live by chef | MEDIUM | HIGH | Sprint 1 ≤2 h orthogonal; Sprint 2 behind feature flag `kds.v2_enabled`; rollback in <1 min via Tailwind class swap |
| R11 | Allergen text styling unreadable on 4K from 2 m distance | MEDIUM | MEDIUM | Visual capture at 4K + chef test session; bold-italic at 18 px minimum with 4 px orange border |
| R12 | RTL Arabic breaks card layout in unexpected ways | MEDIUM | MEDIUM | Dedicated Playwright AR spec + chef session test if available |

---

## 13. Open Questions for Owner (numbered, blocking)

1. **Delivery ingestion path for V1** — pick one:
   - (a) Manual entry by POS staff (V1 Sprint 2, 0.5 d)
   - (b) Uber Eats / Deliveroo webhook integration (Sprint 3, 3-4 d)
   - (c) Dedicated admin/delivery-intake dashboard (Sprint 3, 1-2 d)
   - (d) Defer V1, ship UI ready for chips only

2. **Menu Formule storage confirmation.** Audit shows Menu Formule is **one OrderItem** with `composition_snapshot.addons[]` where children are tagged `role='menu_full'|'menu_frites'|'menu_boisson'`. The plan renders this as **parent line + indented children from a single OrderItem**. Does owner confirm, or is there a planned migration to nested sub-OrderItems?

3. **Sprint 1 SKIP list.** Existing `PLAN_KDS_IMPROVEMENT_2026-05-11.md` defines 10 QWs; the ultra-plan keeps only QW-1 (bug fix), QW-9 (reduced-motion), QW-10 (i18n) — the rest is deferred to Sprint 2 to avoid double-work. Owner confirms?

4. **Auto-cancel UX.** When POS cancels an in-PREPARING order, the KDS shows red-strikethrough + ANNULÉ overlay. **Auto-dismiss after 30 s** or **require explicit chef dismiss tap**?

5. **Bump-bar physical hardware.** Targeting **Logic Controls LBE** (USB keyboard wedge with A-H tactile keys), **Bematech KB-1700**, **generic USB numpad**? V1 ship with bump-bar key shortcuts in CSS hints, physical hardware test Sprint 3?

6. **Settings menu access control.** Visible to **all chefs** or **admin only**? Currently the KDS is gated by `permission:kitchen-display-system` which most kitchen roles have.

7. **Recall window 3 s vs 60 s.** The card-level Prêt undo Toast is set to **3 s** (Wingstop-style instant) — owner OK or prefer **60 s grace** like Fresh KDS / Otter (requires `OrderStateMachine` extension to allow `PREPARED → PREPARING` for chef role only)?

8. **`kds_category` column on Items.** Sprint 2 schema migration adds `items.kds_category` nullable VARCHAR(32). Should Catalog admin UI gain a category picker in Sprint 2 (~2 h) or Sprint 3?

9. **Dark mode (Sprint 3 RF-4).** **Toggle in settings**, **auto by time of day** (19 h–08 h), or **mandatory dark** for kitchen panels?

10. **Branch isolation in dev/test.** Sprint 2 E2E will spawn POS + Kiosk + (simulated) Delivery orders in **same branch**. Acceptable, or should test isolate per source?

---

## Critical Files for Implementation

1. `resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue` — orchestrator refacto target
2. `resources/js/helpers/kdsCustomization.js` — NEW canonical adaptive renderer (single source of truth for cards)
3. `app/Http/Resources/KDSOrderDetailsResource.php` — backend canonical projection, additive extensions
4. `resources/js/helpers/kdsAutoTransition.js` — NEW auto-transition rule
5. `resources/js/languages/{fr,en,ar}.json` — i18n key parity files
