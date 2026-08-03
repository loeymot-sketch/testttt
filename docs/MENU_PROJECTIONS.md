# Menu Projections — Dual-channel SSOT (section 5)

**Status** : legacy projection foundation doc. VA-SYS-06 moved POS/Kiosk runtime
projection further: published composer profiles and branch choice availability are
now exposed to POS/Kiosk paths. Canonical Version A sync docs live under
`docs/sync/`.
**Related** : MENU_86 (`docs/MENU_AVAILABILITY.md`), section 5 of `reports/execution/AUDIT_MASSIF_FR_2026-04-16.md`.

---

## 1. Why a single catalog

FoodKing runs two customer-facing surfaces — POS (cashier) and Kiosk (customer self-order) — and plans a public website. Historically the temptation is to give each surface its own catalog. We explicitly **reject that pattern** : two CMS sources = duplicated data, divergence risk, no single source of truth.

Instead, a **single `items` + `item_categories` catalog** is projected per surface. Every projection is derived from the same rows; only **visibility**, **ordering**, **labels** and **presentation extras** (emoji, allergen flags) differ per channel.

## 2. Schema additions (migration `2026_04_16_200000_add_channel_columns_to_items_and_categories`)

| Table              | Column           | Type           | Nullable | Default      | Purpose                                                 |
| ------------------ | ---------------- | -------------- | -------- | ------------ | ------------------------------------------------------- |
| `items`            | `channels`       | `JSON`         | yes      | `NULL`       | Restrict item to a subset of surfaces. `NULL` = all.    |
| `items`            | `allergen_flags` | `JSON`         | yes      | `NULL`       | Free-form list e.g. `["halal","gluten-free"]`.          |
| `items`            | `kiosk_emoji`    | `VARCHAR(8)`   | yes      | `NULL`       | Glyph rendered by the kiosk UI; ignored on POS.         |
| `item_categories`  | `channels`       | `JSON`         | yes      | `NULL`       | Same semantics as items.                                |
| `item_categories`  | `kiosk_sort`     | `INT UNSIGNED` | yes      | `NULL`       | Override category ordering on kiosk (fallback `sort`).  |
| `item_categories`  | `pos_sort`       | `INT UNSIGNED` | yes      | `NULL`       | Override category ordering on POS (fallback `sort`).    |
| `item_categories`  | `kiosk_label`    | `VARCHAR(255)` | yes      | `NULL`       | Alternate display name on kiosk (fallback `name`).      |

**Back-compat guarantee** : every existing row keeps `NULL` for each new column, which means "unchanged". Controllers that do not read these columns keep working. No backfill required.

## 3. Channel values

`'pos'`, `'kiosk'`, `'web'` — defined in `MenuProjectionService::SUPPORTED_CHANNELS`. Adding a surface in the future is purely additive : add the string to that constant and start storing it in `channels` JSON.

## 4. Services

### `App\Services\Menu\MenuProjectionService`

```php
$projection = app(MenuProjectionService::class);
$payload = $projection->forChannel('kiosk', branchId: 1);
```

Returns :

```jsonc
{
  "categories": [
    {
      "id": 5,
      "slug": "nos-tacos",
      "name": "Nos Tacos Borne", // kiosk_label override if set on kiosk
      "sort": 2,                   // kiosk_sort || sort
      "wizard_template": "tacos",
      "items": [
        {
          "id": 42,
          "name": "Tacos M",
          "slug": "tacos-m",
          "price": 6.5,
          "available": true,       // resolved from item_branch_availability
          "is_upsell": false,
          "is_featured": false,
          "allergens": ["halal"],
          "emoji": "🌯"            // kiosk only
        }
      ]
    }
  ],
  "snapshot_version": 42,
  "branch_id": 1,
  "channel": "kiosk"
}
```

Contract guarantees :

1. **Channel filter** — categories/items with `channels = NULL` are visible everywhere; otherwise only on listed channels.
2. **Availability** — resolved from `item_branch_availability` for the requested `branch_id`. Absent row = available (V1 MENU_86 rule).
3. **Branch isolation** — availability rows for other branches do NOT leak into the response.
4. **Sort** — `kiosk_sort` / `pos_sort` are optional overrides, `sort` is the canonical fallback.
5. **Label** — `kiosk_label` overrides `name` on `channel='kiosk'`; POS and Web use the canonical `name`.
6. **Emoji** — surfaced only on `channel='kiosk'` and only when `kiosk_emoji` is set.
7. **Allergens** — pass-through of `allergen_flags` (NULL → `[]`).
8. **Unavailable reason** — attached to the item only when it is unavailable.

### `App\Services\Menu\MenuSnapshot`

Branch-scoped monotonic version counter :

```php
$snap = app(MenuSnapshot::class);
$v = $snap->current($branchId);  // int, starts at 1
$next = $snap->bump($branchId);  // atomic INCR, returns new value
```

Storage : the default cache store (Redis in prod, array in tests). Keys follow `menu:snapshot_version:branch:{id}` with a 7-day TTL.

The projection includes `snapshot_version` so clients can skip re-rendering if the value has not changed. The listener `BumpMenuSnapshotOnItemAvailabilityChanged` bumps the relevant branch(es) whenever an `ItemAvailabilityChanged` event fires :

- **Branch-scoped event** (rupture toggle, auto-86) → bump that branch only.
- **Global event** (admin edits item status/price) → bump every active branch.

Bump failures never block the outbox — they are swallowed and logged as warnings.

## 5. Model helpers

```php
$item->isVisibleOn('kiosk');                 // bool (NULL channels = always true)
$category->isVisibleOn('pos');               // bool
$category->displayNameFor('kiosk');          // kiosk_label ?? name
$category->sortFor('kiosk');                 // kiosk_sort ?? sort
$category->sortFor('pos');                   // pos_sort ?? sort
```

## 6. HTTP contract

```
GET /api/admin/menu-projection?channel={pos|kiosk|web}&branch_id={int}
```

Middleware stack inherited from the `admin` group :

- `installed` + `apiKey` (header `x-api-key`)
- `auth:sanctum`
- `throttle:admin-mutation` (120 rpm / user)
- `localization`

Validation rules :

| Query arg   | Rule                                                        |
| ----------- | ----------------------------------------------------------- |
| `channel`   | `required`, `string`, in `pos / kiosk / web`                |
| `branch_id` | `required`, `integer`, `min:1`                              |

Invalid `channel` values raise 422 with `errors.channel` rather than 500.

## 7. Access control (V1)

| Role             | Can call `/api/admin/menu-projection` ? |
| ---------------- | --------------------------------------- |
| Admin            | yes                                     |
| Branch Manager   | yes                                     |
| POS Operator     | no — POS still reads its legacy endpoint |
| Chef (KDS)       | no                                      |
| Customer         | no                                      |

## 8. Migration path to V1.5

The endpoint is introduced in read-only mode; existing POS / Kiosk controllers (`Admin\PosController`, frontend menu controllers) continue to serve the live traffic. V1.5 tasks :

1. Switch kiosk bootstrap to `/api/admin/menu-projection?channel=kiosk`.
2. Switch POS bootstrap to `/api/admin/menu-projection?channel=pos`.
3. Add admin UI (Vue) with channel tabs + 1-click rupture.
4. Add `price_overrides` table for happy-hour-style temporal overrides.

Each step is independent — rolling back any of them does not impact the others.

## 9. Observability

- Structured logs emitted by the controller & service rely on the standard Laravel stack (no new channels).
- The correlation ID attached by `CorrelationIdMiddleware` is propagated automatically via `Log::withContext()`.
- Snapshot bump warnings (`MenuSnapshot bump failed`) are a safe-to-ignore signal that the cache store is unreachable; the next manual refresh will succeed once the store is back online.

## 10. Test coverage

| File                                                                  | Tests | Scope                                               |
| --------------------------------------------------------------------- | ----- | --------------------------------------------------- |
| `tests/Unit/Services/Menu/MenuSnapshotTest.php`                       | 6     | Monotonicity, branch isolation, atomic bump         |
| `tests/Feature/Services/Menu/MenuProjectionServiceTest.php`           | 13    | Channel filter, sort, labels, availability, envelope|
| `tests/Feature/Http/Admin/MenuProjectionControllerTest.php`           | 5     | Auth, validation, HTTP contract, channel-aware JSON |
| `tests/Feature/Menu/BumpMenuSnapshotListenerTest.php`                 | 2     | Branch-scoped + global event propagation            |

Total section 5 coverage : **26 tests, 72 assertions**.
