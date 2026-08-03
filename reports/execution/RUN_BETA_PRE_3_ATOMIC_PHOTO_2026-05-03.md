# RUN — β-PRE-3 Atomic Photo Upload — 2026-05-03

## Strategy

Chosen strategy: **Option B**.

`spatie/laravel-medialibrary` is declared as `^10.5`, and the package supports multiple media records in the same collection. The controller now uploads the new photo into the existing `item` collection first, without clearing the old media. Only after the new media is confirmed does it delete the previous media records. If Spatie fails late after creating a media record, the catch block removes only media not present before the attempt and returns a stable 500 JSON error.

## Files Touched

- `app/Http/Controllers/Admin/ItemPhotoController.php`
- `tests/Feature/Items/ItemPhotoUploadAtomicityTest.php`
- `reports/execution/RUN_BETA_PRE_3_ATOMIC_PHOTO_2026-05-03.md`

`app/Models/Item.php` was read only. No `registerMediaCollections()` method exists, and no `item_pending` collection was needed.

## Test Result

Command:

```bash
php artisan test tests/Feature/Items/
```

Result: **9/9 PASS**.

Required β-PRE-3 subset:

- Existing `ItemPhotoUploadTest`: **5/5 PASS**
- New `ItemPhotoUploadAtomicityTest`: **2/2 PASS**

The folder also contained `ItemCreateContractTest`: **2/2 PASS**.

## Atomicity Evidence

Previous unsafe order:

```php
$item->clearMediaCollection('item');
$media = $item->addMediaFromRequest('photo')->toMediaCollection('item');
```

New safe order:

```php
$originalMediaIds = $item->getMedia('item')->pluck('id')->all();
$media = $this->addPhotoMedia($item);
$item->getMedia('item')
    ->reject(fn ($existingMedia) => (int) $existingMedia->id === (int) $media->id)
    ->each(fn ($oldMedia) => $oldMedia->delete());
```

Failure sentinel:

```php
parent::addPhotoMedia($item);
throw new RuntimeException('Simulated storage failure after media write');
```

The test confirms the item still has exactly one media record after failure and that its `file_name` remains `first.jpg`.

## Invariants

- I3 `branch_id`: N/A per plan.
- I6 frozen zones: no frozen files touched.
- Return JSON signature preserved: `id`, `thumb_url`, `cover_url`, `preview_url`.

## Status

PASS
