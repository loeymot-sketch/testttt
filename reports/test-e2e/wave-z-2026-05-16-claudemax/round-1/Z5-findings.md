# Z5 — Admin Catalogue + Items (Findings, Round 1)

**Date** : 2026-05-16
**Auditor** : RED-team read-only sub-agent (Wave Z)
**HEAD** : `c3ba89863`
**Branche** : `feature/mobile-app-le-cayenne-2026-05-10`
**Surfaces** : `/admin/items`, `/admin/categories`, item CRUD, variations, options, image upload, composer profiles
**Sister-verdict coverage** : Z5 was NOT in sister verdict — Wave-Z fills V1 admin coverage.

---

## Summary

12 findings: **0 P0 / 4 P1 / 5 P2 / 3 P3**.

Catalog admin is functionally complete: pricing-SSOT respected (`composition_snapshot` frozen at OrderItem creation, NOT mutated by Item updates), event/cache invalidation pipeline correctly fires `ItemAvailabilityChanged` + `ItemCreated` + `ItemDeleted` + `CategoryCreated/Updated/Deleted`, RBAC middleware `permission:items_*` applied to mutating controller methods, soft-delete with `protect_force_delete_when_referenced` guard.

However: (a) **`channels` admin UI is missing entirely** — server validates the field but admin form never exposes it (P1); (b) **`barcode`, `kds_station` are fillable yet not in `ItemRequest` rules** — admin form cannot set them via normal CRUD (P1); (c) **soft-deleted items have no restore route** — destroy is one-way for admin (P2); (d) **currency hardcoded via `env('CURRENCY_SYMBOL')`** — not branch-configurable (P2); (e) image MIME/size rules **inconsistent** across 3 request classes (P2); (f) `ItemCategoryService::destroy` contains a **dead FK-disable branch** that risks silent session-wide leakage (P2); (g) **`/items/{item}/photo` URL is pluralized** while everything else under `/item/...` is singular (P3 footgun); (h) **idempotency middleware never applied** to admin CRUD (P3, acceptable for manual admin); (i) **N+1 `orders->count()` per Item show** when relation not eager-loaded (P3).

Frozen-zone respect: **no frozen file touched** by Z5-surface code (verified via §kickoff baseline).

---

## P0 findings

_None_.

---

## P1 findings

### P1-Z5-01 — Admin item form has NO `channels` UI (dual-channel SSOT is server-only)
**Files**
- `app/Http/Requests/ItemRequest.php:55-56` — server validates `channels` array with `in:kiosk,pos,web`
- `app/Models/Item.php:43,73,83-86` — model casts `channels` array + `isVisibleOn()` projection
- `resources/js/components/admin/items/ItemCreateComponent.vue:1-226` — form has NO `channels` field
- `resources/js/components/admin/items/ItemShowComponent.vue` — no channels editor

**Symptom** — Admin user cannot set or update an item's visibility per surface (`pos` / `kiosk` / `web`) through the standard admin UI. The only way to populate `channels` is direct DB or `ItemImport` Excel. New items are saved with `channels=NULL` (legacy back-compat = visible everywhere), as flagged by `ItemService::warnCatalogChannelsNullIfNeeded()` (`ItemService.php:671-685`).

**Why P1** — Surface segregation is a documented V1 invariant (SSOT projection, Section 5 menu-projection, `/api/menu-projection`); the admin has no UI to enforce it. Items intended for kiosk-only or POS-only leak to other surfaces silently. Workaround = SQL or import.

### P1-Z5-02 — `barcode` + `kds_station` fillable + queried but NOT in ItemRequest rules
**Files**
- `app/Models/Item.php:24,46` — `barcode` + `kds_station` fillable + cast to string
- `app/Http/Controllers/Admin/ItemController.php:221-256` — `lookupBarcode` reads `where('barcode', $code)`
- `app/Http/Requests/ItemRequest.php:32-70` — no `barcode` or `kds_station` validation key

**Symptom** — POST/PUT `/api/admin/item` with `barcode=XYZ` is silently dropped because the field is not in `validated()` (returned to `ItemService::store/update`). Admin cannot wire barcode-scan workflow (`lookupBarcode` endpoint exists but admin form cannot populate the column). Same for `kds_station` (KDS routing key).

**Why P1** — Two production features (POS barcode scan, KDS station routing) depend on columns the admin UI cannot set.

### P1-Z5-03 — Hardcoded French raw labels in catalog control plane (no $t)
**Files**
- `resources/js/components/admin/items/ItemListComponent.vue:6` — `Pilotage catalogue`
- `resources/js/components/admin/items/ItemListComponent.vue:8` — `Produits, catégories, offres et disponibilités`
- `resources/js/components/admin/items/ItemListComponent.vue:11` — `POS / borne`
- `resources/js/components/admin/items/ItemListComponent.vue:17` — `aria-label="Résumé catalogue"`
- `resources/js/components/admin/items/ItemListComponent.vue:20,24,28,32` — `produits` / `catégories` / `actifs` / `indisponibles`
- `resources/js/components/admin/items/ItemListComponent.vue:36` — `aria-label="Actions catalogue"`
- `resources/js/components/admin/items/ItemListComponent.vue:39,43,47,51` — `Produits` / `Catégories` / `Offres` / `Disponibilités`

**Symptom** — Catalog list header is FR-locked. Switching admin to AR/EN leaves these strings in French. Inconsistent with the rest of the file (lines 58+) which uses `$t()`.

**Why P1** — V1 admin advertised as i18n-capable; per CLAUDE.md §13 « raw labels (Label.X) » is a visual-test failure indicator. This is the same kind of regression that KDS-W3 sister findings caught.

### P1-Z5-04 — `ItemAttributeController::index` exposes attribute list with no permission guard
**Files**
- `app/Http/Controllers/Admin/ItemAttributeController.php:21` — `permission:settings` only on `show/store/update/destroy`
- `app/Http/Controllers/Admin/ItemAttributeController.php:24-31` — `index` has no middleware

**Symptom** — Any authenticated admin user (e.g. POS Operator, Chef) can `GET /api/admin/setting/item-attribute` and enumerate attribute group names (sauce / viande / taille / etc.). No business-critical PII leak, but exposes menu structure that callers shouldn't read.

**Why P1** — BRAIN §9 lists « FormRequest authz scattered → roadmap V1.0.1 refactor 88 endpoints » — this is a concrete instance. Compare to `ItemCategoryController:27-37` which includes `index` in the guard. Inconsistent intent.

---

## P2 findings

### P2-Z5-05 — `currencyAmountFormat` reads from `.env`, not branch-configurable
**Files**
- `app/Libraries/AppLibrary.php:271-277` — `env('CURRENCY_SYMBOL')` + `env('CURRENCY_POSITION')` + `env('CURRENCY_DECIMAL_POINT')`
- `app/Http/Resources/SimpleItemResource.php:37` + `ItemResource.php:76` — every item price formatted via that

**Symptom** — Multi-branch deployment in different currency zones (e.g. one branch FR €, another CH CHF) is impossible without app restart + .env rewrite. A `currencies` model exists (`app/Models/Currency.php`) but the formatter never reads from DB.

**Why P2** — V1 is single-currency France (€), so no immediate impact. Roadmap will break the moment a multi-currency tenant is added; the Currency CRUD admin endpoints (`/api/admin/setting/currency`) are misleading (they exist but never feed item formatting).

### P2-Z5-06 — Image upload rules diverge across 3 request classes
**Files**
- `app/Http/Requests/ItemRequest.php:69` — `image|mimes:jpg,jpeg,png|max:2048`
- `app/Http/Requests/ChangeImageRequest.php:27` — same as above
- `app/Http/Requests/ItemPhotoUploadRequest.php:17` — `image|mimes:jpg,jpeg,png,webp|max:4096`
- `app/Http/Requests/ItemCategoryRequest.php:44` — `image|mimes:jpg,jpeg,png|max:2048`

**Symptom** — `POST /api/admin/items/{item}/photo` accepts webp + 4 MB; `POST /api/admin/item/change-image/{item}` rejects webp + caps at 2 MB. Admin sees inconsistent error behavior depending on which upload path the JS chose.

**Why P2** — User-visible inconsistency. WebP is now standard (Splash photos = WebP); the "main" image upload path rejects what the photo path accepts.

### P2-Z5-07 — `ItemCategoryService::destroy` contains a dead FK-disable branch (silent leakage risk)
**Files**
- `app/Services/ItemCategoryService.php:165-193`

**Symptom** — Logic at line 170-183:
```
if (!blank($checkItem)) {  // category HAS items
    $itemCategory->delete();  // soft-delete
} else {
    DB::statement('SET FOREIGN_KEY_CHECKS=0');  // ?!
    $itemCategory->delete();  // STILL soft-delete (cascade FK irrelevant)
    DB::statement('SET FOREIGN_KEY_CHECKS=1');
}
```
The FK toggle is a no-op for soft-delete (which only writes `deleted_at`); it would only matter for `forceDelete()`. The toggle is set at **session scope** — if an exception fires between `=0` and `=1`, the session keeps FK disabled for the rest of the request → silent FK bypass for any subsequent statement in the same connection (Laravel reuses PDO across calls within a request).

**Why P2** — Dead/cargo-cult code that becomes a footgun the moment someone refactors to `forceDelete()`. Also misleads readers.

### P2-Z5-08 — No restore route for soft-deleted items or categories
**Files**
- `app/Models/Item.php:11,17` — `SoftDeletes` trait
- `app/Models/ItemCategory.php:8,18` — `SoftDeletes` trait
- `app/Services/ItemService.php:358-420` — `destroy` calls `delete()` (soft)
- `routes/api.php:647-679` — no `/item/{item}/restore` route
- `routes/api.php:341-351` — no `/item-category/{itemCategory}/restore` route

**Symptom** — Once an admin soft-deletes an item, there's no UI/API path to restore it. The `protect_force_delete_when_referenced` guard at `ItemService.php:362-372` correctly blocks hard-delete when historical orders reference the item, but the soft-deleted row stays forever invisible. To recover, admin must run SQL `UPDATE items SET deleted_at=NULL WHERE id=...`.

**Why P2** — Operational gap. NF525 says historical references must survive (which they do, via `composition_snapshot`), but the admin needs a way to un-86 an item.

### P2-Z5-09 — `Item` update does not fire a dedicated `ItemUpdated` event — relies on `ItemAvailabilityChanged` as a catch-all
**Files**
- `app/Services/ItemService.php:336-345` — dispatches `ItemAvailabilityChanged` on every update, with `$type='full'` if price/variations/extras changed
- `app/Providers/EventServiceProvider.php:169-177` — listeners on `ItemAvailabilityChanged` include `InvalidateKioskMenuCacheOnItemAvailabilityChanged` + `PersistCatalogChangedToOutbox` + `BumpMenuSnapshotOnItemAvailabilityChanged`
- No `ItemUpdated` event class exists (`find app/Events -name "Item*"` returns 5 files, none `Updated`)

**Symptom** — Semantically, the codebase abuses an "availability" event to broadcast generic data changes (name, description, category, kiosk_emoji, allergen_flags, channels). Listeners must always assume "could be anything" instead of optimizing by event type. The conditional `$type` field (line 331) partially compensates but is brittle.

**Why P2** — Maintenance/clarity. Functionally correct today (every update invalidates kiosk cache), but the next listener author will be confused.

---

## P3 findings

### P3-Z5-10 — `/items/{item}/photo` URL is pluralized; everything else under `/item/...` is singular
**Files**
- `routes/api.php:680` — `Route::post('/items/{item}/photo', ...)` — plural prefix
- `routes/api.php:647-678` — `Route::prefix('item')->group(...)` — singular prefix for all other CRUD

**Symptom** — JS client must remember `/api/admin/items/.../photo` while `/api/admin/item/...` for everything else. Minor source of bugs (e.g. typo `/item/{id}/photo` returns 404 silently).

### P3-Z5-11 — Admin item CRUD has no idempotency middleware
**Files**
- `routes/api.php:647-679` — POST/PUT/DELETE on `/item`, `/item/{item}/duplicate`, `/item/change-image/{item}`, `/items/{item}/photo` — no `idempotency` middleware
- `app/Http/Kernel.php:98` — `'idempotency' => IdempotencyKeyMiddleware::class` registered

**Symptom** — Double-click on admin "Save" creates duplicate items / variations / extras. Acceptable for manual admin operators (POS replay is the protected path), but worth documenting.

### P3-Z5-12 — `ItemResource::toArray` runs `$this->orders->count()` per item even when `orders` not eager-loaded
**Files**
- `app/Http/Resources/ItemResource.php:89` — `"order_count" => $this->orders->count()`
- `app/Services/ItemService.php:471-479` (`show`) — loads `media,category,tax,offer,addons,variations,extras` — NOT `orders`
- `ItemController.php:103-122` (`store`/`update`) — returns `new ItemResource($item)` without loading `orders`

**Symptom** — Each `Item.show`/`store`/`update` triggers one extra `SELECT count(*) FROM order_items WHERE item_id=?`. Single-row context = 1 query, no N+1 multiplication. Mild perf nit on admin save loop.

---

## Healed-verified

_N/A — Z5 had no sister-verdict findings to verify._

---

## Open-from-sister

_None — Z5 was not covered._

---

## NEW (introduced by heals)

_None — the Le Cayenne 2026-05-13 menu reset (commits `94f6232a8`, `de3e8d580`, `7f06224af`, `afe2209d0`) touched seeders + Artisan commands, not admin controllers/resources. Diff scan over `app/Http/Controllers/Admin/Item*Controller.php` since `5f48856f9` shows no Z5-surface changes._

---

## Notes (informational, not findings)

- **BranchScope by design absent on Item / ItemCategory / ItemVariation / ItemExtra / ItemAddon / ItemAttribute** (verified via `grep -rn "addGlobalScope.*BranchScope" app/Models` — 18 models scoped, none of the catalog models). Catalog is intentionally **global per tenant**; per-branch overlay flows through `ItemBranchAvailability` (branch-scoped indirectly via `applyBranchAvailabilityOverlay` at `ItemService.php:160-192`) and `StockLevel` (BranchScope at `app/Models/StockLevel.php:25`). Design is consistent with V1 single-tenant fast-food chain.
- **`composition_snapshot` rotation safe** — verified `OrderService.php:439-455`, `:794-810`, `:1250-1266` all write `composition_snapshot` at OrderItem insert time. Subsequent Item updates (name/price/category/etc.) never mutate persisted OrderItem rows. NF525 rule respected.
- **Permission middleware sane on mutating paths** — `ItemController` (l. 31-42), `ItemVariationController` (l. 22-23), `ItemExtraController` (l. 21-22), `ItemAddonController` (l. 21-22), `ComposerProfileController` (route-level at `routes/api.php:696-718`). Read paths intentionally OR'd with `pos` via `canAny(['items_show','pos'])` at `ItemController.php:38` to support POS runtime catalog read.
- **Cache invalidation listeners wired** — `EventServiceProvider.php:169-218` connects every CRUD event to `InvalidateKioskMenuCacheOnItemAvailabilityChanged` + `PersistCatalogChangedToOutbox`. Verified outbox-bridge for extras/variations at `:182-192`.
- **Item duplicate is permission-guarded** — `permission:items_create` per `ItemController.php:32`. Replicates children + media + composer profile (drafts the new profile as unpublished v+1, `ItemService.php:617-643`).
- **`/api/admin/item` route lives in throttle:admin-mutation group** (`routes/api.php:269`) — 30/min; toggle endpoints in sibling 60/min group to avoid self-DoS (`:255-267`). Reasoned + documented.

---

**End Z5-findings.md** — round-1, 2026-05-16
