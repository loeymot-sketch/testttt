# RUN β-PRE-1 Diff Refactor — 2026-05-04

## Sommaire

PASS — `ComposerDiffService` now diffs against real persisted `ItemWizardStepVersion` snapshots instead of the non-existent `published_steps_snapshot` attribute.

## Refactor `ComposerDiffService`

Before, the service read an in-memory/non-schema attribute and fell back to a synthetic projection:

```php
$snapshot = $profile->getAttribute('published_steps_snapshot');
if (is_array($snapshot)) {
    return [$this->arrayRowsByKey($snapshot), true];
}

$projected = $this->projectPublishedProfile($profile);
```

After, the service reads the latest real version row and removes the projection fallback entirely:

```php
private function publishedRowsByKey(ItemWizardProfile $profile): array
{
    $latestVersion = $profile->versions()->orderByDesc('version')->first();

    return $this->arrayRowsByKey($latestVersion?->snapshot ?? []);
}

private function hasHistoricalSnapshot(ItemWizardProfile $profile): bool
{
    return $profile->versions()->exists();
}
```

`projectPublishedProfile()` was deleted. Rationale: it created a fake `Item` with empty relations and could silently report clean diffs; persisted `item_wizard_step_versions` is now the real source of truth.

## Migration `ComposerDiffServiceTest`

Before, tests injected a non-persisted attribute:

```php
$profile->setAttribute('published_steps_snapshot', $steps);
```

After, the helper inserts a real DB row:

```php
return ItemWizardStepVersion::create([
    'profile_id' => $profile->id,
    'version' => $profile->version,
    'snapshot' => $steps,
    'published_at' => now(),
    'published_by_id' => null,
]);
```

## New `ComposerDiffServiceProductionPathTest`

Added 3 production-path tests:

- `test_diff_against_real_persisted_snapshot_detects_real_changes` proves a real publish snapshot catches a `max_select` draft mutation.
- `test_diff_for_unpublished_profile_returns_consistent_structure` locks the existing first-publish contract: draft steps are reported as `added`.
- `test_diff_after_two_publishes_compares_against_latest_version_only` proves the diff compares against the latest version row, not an older publish.

## Résultat tests

- `php artisan test tests/Feature/Composer/ComposerDiffServiceTest.php` → 6/6 PASS.
- `php artisan test tests/Feature/Composer/ComposerDiffServiceProductionPathTest.php` → 3/3 PASS.
- `php artisan test tests/Feature/Composer/` → 65 PASS / 2 SKIPPED / 0 FAIL.

## Vérification grep

- `published_steps_snapshot` in `app/` → 0 reference.
- `published_steps_snapshot` in `database/` → 0 reference.

## Invariants

- I4 dispatch after commit: not touched; Lot F only reads `versions()`.
- I3 branch isolation: no cross-branch query added.
- I6 frozen zones: no Pricing, Order/Lifecycle, or Fiscal files touched.

## Statut

PASS.

EXECUTE_DELEGATION: foodking-complex-implementer (sub-task `CV1-V2-CATALOG-REWORK-002-F`)
