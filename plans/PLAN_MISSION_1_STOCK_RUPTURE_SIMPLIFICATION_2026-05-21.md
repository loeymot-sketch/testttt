# PLAN — Mission 1 — Stock-Rupture UI Simplification (Owner Spec 2026-05-21)

> Author: Claude Code orchestrator
> Date: 2026-05-21
> Branch: `heal/cms-pr1-quickwins-2026-05-18`
> Status: PLANNING → EXECUTING

---

## §1 Owner Spec (verbatim → actionable)

Owner verbatim (FR):
> « Je veux qu'un seul accès pour faire ça, qu'un seul bouton pour accéder à
> toutes les catégories. Choisir quelle catégorie — par exemple catégorie de
> tous les produits où on peut voir tous les produits, catégorie des
> suppléments, catégorie de boisson, catégorie de dessert. Pour la version
> une, il n'y aura pas de numéro de quantité en stock, c'est en termes de
> oui/non en stock ou non. Une crudité qui n'est plus disponible doit être
> retirée du wizard caisse et de toutes les autres surfaces. »

Actionable spec:
- **One button** in admin sidebar → "Produits & Stock" (single entry point)
- **Single page** with category picker → product list per category → binary
  in-stock / out-of-stock toggle per product
- **Categories visible** = ALL existing item categories (Burgers, Tacos,
  Bols, Frites, Boissons, Desserts, Menus, …) + ingredient categories
  (Crudités, Sauces, Suppléments) + Variations (tailles)
- **V1 = binary only** — no quantity tracking, no thresholds, no daily
  quotas in the UI (backend may still have them but UI ignores)
- **Sync mandatory** — toggling out-of-stock instantly hides the product
  from POS catalogue + Kiosk catalogue + Kiosk wizard sauce/crudité picker;
  KDS unaffected; future Web + Mobile will read the same `is_available`
  field

Out of scope V1:
- Quantity / on_hand inputs
- Daily quota inputs
- Low-stock alerts UI (backend keeps them, UI hides)
- Bulk multi-select restore (rarely used per owner)
- Manual scan trigger (cron handles auto-86; manual trigger is power-user)
- Reason picker modal (auto-tag `admin_86` is fine — owner doesn't need to
  pick a reason)

---

## §2 What already works (DO NOT TOUCH)

Backend sync chain is **production-grade**, validated across 7+ E2E specs:

- `AvailabilityService` (`app/Services/Menu/AvailabilityService.php`) —
  3 toggle methods (item / extra / variation) + snapshot + idempotent +
  `DB::afterCommit` event dispatch
- 3 events: `ItemAvailabilityChanged`, `ItemExtraAvailabilityChanged`,
  `ItemVariationAvailabilityChanged` + 5+ listeners
- Outbox + Pusher broadcast on `private-branch.{id}` channel
- POS catalogue, Kiosk catalogue, Kiosk wizard all read `is_available`
  correctly today
- 3 existing toggle endpoints:
  - `POST /api/admin/availability/toggle` (items)
  - `POST /api/admin/availability/toggle-extra`
  - `POST /api/admin/availability/toggle-variation`

**Mission 1 backend change = ONE new lightweight read endpoint only.** No
schema migrations. No service refactor. No event refactor. No frozen-zone
touch.

---

## §3 New backend surface

**ONE new endpoint:**

```
GET /api/admin/stock/catalog-overview?branch_id={int}
```

**Controller:** `StockRuptureDashboardController::catalogOverview()`
(new method on existing controller — no new controller class)

**Permission:** `items_show` (read), reuse middleware already on controller

**Response shape (stable, frontend-friendly):**

```json
{
  "branch_id": 1,
  "categories": [
    {
      "id": 1,
      "name": "Burgers",
      "type": "item",
      "items": [
        {
          "id": 42,
          "name": "Big Cayenne",
          "photo_url": "/storage/items/42.jpg",
          "is_available": true,
          "reason": null
        }
      ]
    }
  ],
  "extras": {
    "crudite":     [{ "id": 8, "name": "Tomate", "is_available": true,  "reason": null }],
    "sauce":       [{ "id": 12, "name": "Algérienne", "is_available": false, "reason": "admin_86" }],
    "supplement":  [{ "id": 5, "name": "Fromage", "is_available": true,  "reason": null }]
  },
  "variations": [
    { "id": 3, "name": "Taille Maxi", "is_available": true, "reason": null }
  ],
  "fetched_at": "2026-05-21T..."
}
```

**Implementation:**
- Categories + items via `Category::with('items')` filtered by branch's
  active items
- Extras grouped by their `kind` / category field (TBD — confirmed in
  implementation via reading `ItemExtra` model schema)
- Variations as a single flat list
- `is_available` per leaf computed via existing `AvailabilityService`
  helpers: `isAvailable()`, `isExtraAvailable()`, `isVariationAvailable()`
  (already snapshot-friendly per service docblock)

**Performance:** Single SELECT per entity bucket + in-memory join with
`ItemBranchAvailability` rows for branch. No N+1.

---

## §4 New frontend component

**Rewrite:** `resources/js/components/admin/stock/StockRuptureDashboardComponent.vue`

Drop old features:
- ❌ "Currently 86" reactive list
- ❌ Low alerts list
- ❌ Bulk multi-select bar
- ❌ Reason picker modal
- ❌ Scan-now trigger button
- ❌ Cron status badge

Keep:
- ✅ Branch filter (admin sees all branches; staff pinned to own branch)
- ✅ Echo live sync (private-branch.{id} on 3 events)
- ✅ 60s polling fallback
- ✅ Optimistic toggle pattern (snapshot/rollback)
- ✅ Permission gate (`items_edit` for toggle)

New layout (mobile-first, flat per CLAUDE.md design discipline):

```
┌────────────────────────────────────────────────────────────────┐
│ Produits & Stock                              [Branche: Toutes]│
├──────────────┬─────────────────────────────────────────────────┤
│              │  🍔 Burgers (8)                                 │
│ 🍔 Burgers   │                                                 │
│ 🌮 Tacos     │  ┌─────────────────────────┐  ┌─────────────┐  │
│ 🥗 Bols      │  │ 🖼  Big Cayenne          │  │ ✅ EN STOCK │  │
│ 🍟 Frites    │  │ #42                     │  │             │  │
│ 🥤 Boissons  │  └─────────────────────────┘  └─────────────┘  │
│ 🍰 Desserts  │  ┌─────────────────────────┐  ┌─────────────┐  │
│ ─────────    │  │ 🖼  Cheese Burger        │  │ ❌ RUPTURE  │  │
│ 🥬 Crudités  │  │ #43                     │  │             │  │
│ 🧂 Sauces    │  └─────────────────────────┘  └─────────────┘  │
│ ➕ Suppléms  │                                                 │
│ 📏 Tailles   │                                                 │
└──────────────┴─────────────────────────────────────────────────┘
```

Each toggle = a single click flips state with optimistic UI + rollback on
error. State persisted via existing 3 toggle endpoints (no payload change).

A11y:
- ARIA `role="switch"` + `aria-checked`
- Keyboard SPACE to toggle
- Visible focus ring
- Status announced via aria-live region

---

## §5 What gets removed (additive-first, deletion in P3 commit)

**P1 commit** — Build new (additive, no removal yet):
- New endpoint + new component rewrite
- Sidebar item "Stock & Rupture" routes to the new page
- Old `StockRuptureDashboardComponent` archived to `_legacy/` (or kept under
  feature flag) until new one validated by owner

**P2 commit** — Test loop (visual + technical GREEN):
- /test-e2e loop on the new page
- POS catalogue + Kiosk catalogue + Kiosk wizard rupture-skip E2E
- Owner manual sign-off

**P3 commit** — Removal of duplicate surfaces:
- Drop `AvailabilityToggleComponent` usage from `ItemListComponent`
- Drop `IngredientAvailabilityToggleComponent` usage from `IngredientListComponent`
- Drop `StockLowAlertsWidget` from admin dashboard
- Drop link in `CatalogStudioComponent`
- Delete `_legacy/` archive
- One sidebar item, one page, one path

---

## §6 Files touched (estimated)

| File | Action | Touch |
|------|--------|-------|
| `app/Http/Controllers/Admin/StockRuptureDashboardController.php` | Add `catalogOverview()` method | +~80 LOC |
| `routes/api.php` | Add 1 route line | +1 LOC |
| `resources/js/components/admin/stock/StockRuptureDashboardComponent.vue` | Rewrite | ~709 → ~400 LOC |
| `resources/lang/fr.json` + `en.json` + `ar.json` | i18n keys for new UI | +~20 keys × 3 |
| `tests/Feature/Admin/StockCatalogOverviewControllerTest.php` | NEW endpoint tests | +~150 LOC |
| `tests/js/stockRuptureDashboardComponent.spec.js` | Update spec for new layout | ~rewrite |
| `tests/e2e/wave-stock-m1-2026-05-21.spec.js` | NEW E2E rupture cascade | +~200 LOC |
| `tests/js/sentinels/stockRuptureV2Sentinel.spec.js` | Lock new layout contract | +~80 LOC |
| `resources/js/components/layouts/backend/BackendMenuComponent.vue` | Rename label, single entry | ~5 LOC |

P3 deletion commit (later):
| `resources/js/components/admin/items/ItemListComponent.vue` | Remove inline toggle | ~5 LOC |
| `resources/js/components/admin/ingredients/IngredientListComponent.vue` | Remove inline toggle | ~5 LOC |
| `resources/js/components/admin/dashboard/StockLowAlertsWidget.vue` | Remove from dashboard | ~5 LOC |
| `resources/js/components/admin/items/CatalogStudioComponent.vue` | Remove link | ~3 LOC |

---

## §7 Frozen-zone discipline

✅ Zero touch on:
- `app/Services/Menu/AvailabilityService.php` (read-only consumption)
- `app/Models/ItemBranchAvailability.php`
- `app/Models/StockLevel.php`
- All `app/Events/Item*AvailabilityChanged.php`
- All `app/Listeners/Persist*ToOutbox.php`
- `app/Http/Middleware/IdempotencyKeyMiddleware.php`
- `app/Services/Pricing/PricingService.php`
- `PaymentComponent.vue` / `PosV5TrancheRow.vue` / `pos-wizard.js`
- Kiosk wizard components

The 3 toggle endpoints are already idempotent + branch-scoped + outbox-wired.

---

## §8 NF525 invariants

✅ Stock toggling does not interact with fiscal sequence / Z report /
audit log chain. No NF525 risk.

---

## §9 Test plan (Mission 1 acceptance gate)

**Technical (PHPUnit + Vitest):**
- StockCatalogOverviewControllerTest: 6+ test cases (auth, branch isolation,
  empty branch, all categories returned, photo_url resolution, perms)
- StockRuptureDashboardComponent.spec: mount + toggle + Echo handler
- stockRuptureV2Sentinel: locks new layout contract

**E2E Playwright loop via /test-e2e skill:**
- Scenario 1: Admin opens "Stock & Rupture" sidebar → page loads with all
  categories → toggle Big Cayenne to rupture → confirms RUPTURE badge
- Scenario 2: Open POS catalogue in another tab → Big Cayenne hidden (or
  greyed)
- Scenario 3: Open Kiosk catalogue → Big Cayenne hidden
- Scenario 4: Toggle a crudité (e.g. Tomate) to rupture → open Kiosk wizard
  for a Burger → Tomate absent from crudité picker
- Scenario 5: Toggle a sauce (e.g. Algérienne) to rupture → open POS wizard
  for a Tacos → Algérienne absent
- Scenario 6: Toggle variation "Maxi" to rupture → POS shows only standard
  variation
- Scenario 7: Restore — toggle back to in-stock → product re-appears
  everywhere within debounce window

**Visual gate** (mandatory per CLAUDE.md §6):
- Read each PNG screenshot via Read tool
- Verify: layout intact, no raw labels, toggle states clear, branding intact

**Loop discipline:** /test-e2e in loop until 2 consecutive rounds GREEN
(visual + technical), no caveats. Max 3 heal rounds before escalation per
CLAUDE.md §5 step 7.

---

## §10 Risk register

| Risk | Mitigation |
|------|-----------|
| Removing low-alerts UI breaks ops who monitor low stock | Backend keeps the data; future V1.0.X can re-add as optional widget |
| Removing inline toggles in ItemListComponent surprises owner | P3 deletion gated on owner sign-off post P1+P2 |
| New endpoint N+1 on large catalogues | Single SELECT per bucket + in-memory map (mirror BUILD-4 pattern in same controller line 112) |
| Crudités/sauces grouping field unclear in DB | Read ItemExtra schema in implementation; if no `kind` field, group via `category_id` or attached category name |
| Sentinel drift on existing component spec | Update spec to new layout in same P1 commit |

---

## §11 Owner gates

- **Gate G-M1-1** — after P1 commit (new page built, not yet replacing
  old): owner opens `/admin/stock/rupture-v2` (or feature-flagged route)
  and confirms UX matches spec → unlocks P2 test loop
- **Gate G-M1-2** — after P2 test loop GREEN: owner confirms POS + Kiosk
  sync visually → unlocks P3 deletion commit
- **Gate G-M1-3** — after P3 deletion: owner confirms no missed surface →
  Mission 1 CLOSED → Mission 2 unblocked

---

## §12 Definition of Done

Mission 1 DONE when:
- ✅ ONE sidebar entry point, ONE page
- ✅ All categories browsable (items + extras + variations)
- ✅ Binary toggle per product, optimistic + reliable
- ✅ Sync to POS + Kiosk verified visually + technically
- ✅ Wizard skip ruptured crudités/sauces verified
- ✅ 2 consecutive E2E rounds GREEN with visual analysis
- ✅ 0 frozen-zone diff lines
- ✅ PROJECT_BRAIN.md §3 LAST DONE updated
- ✅ Owner physical sign-off on G-M1-3

---

END PLAN
