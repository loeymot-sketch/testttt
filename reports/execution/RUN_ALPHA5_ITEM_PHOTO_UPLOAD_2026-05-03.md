# RUN_ALPHA5_ITEM_PHOTO_UPLOAD_2026-05-03

TASK_ID: CV1-V2-REMAINING-MISSIONS-001  
CHANTIER: alpha5 — endpoint upload photo produit  
EXECUTE_DELEGATION: foodking-complex-implementer (codex-extension-fallback)

## Verdict

ESCALATION_REQUIRED: schema mismatch blocks implementation.

The requested endpoint must persist `$item->image = $path`, but the current migration-defined `items` table has no `image` column. The mission explicitly forbids a DB migration because it assumes the column already exists. Implementing the endpoint without resolving this mismatch leaves the route non-executable in test and any migration-built environment.

## Evidence

- `app/Models/Item.php`: no `image` fillable/cast/accessor; current product images are served through Spatie MediaLibrary (`item` collection) and fallback config images.
- `app/Http/Controllers/Admin/ItemController.php`: existing `changeImage` delegates to `ItemService::changeImage()` and uses Spatie MediaLibrary, not an `items.image` column.
- `database/migrations/2022_11_17_110514_create_items_table.php`: base `items` table has no `image` column.
- `rg` over `database/migrations`: no later migration adds `items.image`.

## Validation Attempt

Command:

```bash
php artisan test tests/Feature/Items/ItemPhotoUploadTest.php
```

Result: failed 3/5 pass, 2/5 fail during prototype validation.

Failure:

```text
SQLSTATE[HY000]: General error: 1 no such column: image
```

The failing prototype was removed so no known-broken product endpoint remains in the tree.

## Middleware / Scope

The intended route location was the existing admin API group:

```text
installed, apiKey, auth:sanctum, localization, throttle:admin-mutation
```

The intended mutation permission was:

```text
permission:items_edit
```

No route was retained because the persistence target is blocked.

## Technical Choice That Needs Human Decision

Choose one before implementation resumes:

1. Approve a schema migration adding nullable `items.image` and then implement the requested local public-disk endpoint.
2. Change the alpha5 requirement to reuse the existing Spatie MediaLibrary `changeImage` path and return the media URL/resource instead of writing `items.image`.
3. Cancel alpha5.

## Invariants

- Pricing SSOT: not touched.
- OrderStatus: not touched.
- branch_id: no new query/mutation retained; `Item` has no branch scope or `branch_id` column in the inspected model.
- Dispatch after DB commit: no dispatch path retained.
- Frozen zones: safety-check passed; no frozen-zone edit retained.

## Reprise α5-bis 2026-05-03

La reprise utilise la solution réelle déjà présente dans le domaine : Spatie Media Library est installé, `App\Models\Item` implémente `HasMedia` / `InteractsWithMedia`, et les photos produit sont stockées dans la collection `item` avec les conversions existantes `thumb`, `cover`, `preview`. Aucune migration ni modification de schéma n'est nécessaire.

Fichiers créés/modifiés :
- `app/Http/Requests/ItemPhotoUploadRequest.php` — validation `photo` requise, image jpg/jpeg/png/webp, 4 Mo max, messages français.
- `app/Http/Controllers/Admin/ItemPhotoController.php` — endpoint dédié qui remplace la collection `item` puis retourne les URLs de conversions Spatie.
- `routes/api.php` — ajout de `POST /api/admin/items/{item}/photo` dans le groupe admin existant.
- `tests/Feature/Items/ItemPhotoUploadTest.php` — couverture upload, remplacement, taille, mime invalide, photo manquante.
- `reports/execution/RUN_ALPHA5_ITEM_PHOTO_UPLOAD_2026-05-03.md` — présente section de reprise.

Validation :

```bash
php artisan test tests/Feature/Items/ItemPhotoUploadTest.php
```

Résultat : PASS 5/5.

Escalation : Aucune escalation.

