# RUN β-PRE-1 Snapshot Foundation — 2026-05-04

## Sommaire

PASS — Option B foundation delivered: insert-only `item_wizard_step_versions` table, Eloquent model, profile relation, publish-time snapshot persistence, and persistence tests.

## Migration créée

- `database/migrations/2026_05_04_000010_create_item_wizard_step_versions_table.php`
- Colonnes : `id`, `profile_id`, `version`, `snapshot`, `published_at`, `published_by_id`, `created_at`, `updated_at`.
- Contraintes : FK `profile_id` → `item_wizard_profiles` cascade delete, FK nullable `published_by_id` → `users` null on delete, unique `item_wizard_step_versions_profile_version_unique`, index `published_at`.
- Validation : `php artisan migrate:fresh --seed` PASS.

## Model créé

- `app/Models/ItemWizardStepVersion.php`
- Casts : `snapshot` array, `published_at` datetime, IDs / `version` integer.
- Relations : `profile()` and `publishedBy()`.
- Insert-only enforcement : `update()` throws `RuntimeException`; direct Eloquent delete remains service-forbidden, while SQL FK cascade is preserved.

## Relation versions() ajoutée

- `app/Models/ItemWizardProfile.php`
- Ajout de `versions(): HasMany`.
- Ajout de `latestVersion(): ?ItemWizardStepVersion`.

## ComposerProfileService::publish() modifié

Snapshot inserted inside the existing `DB::transaction`, after `ItemWizardProfile::publish()` increments the profile version and before `ComposerProfilePublished` / `ComposerProfileChanged` dispatch.

```php
ItemWizardStepVersion::create([
    'profile_id' => $fresh->id,
    'version' => $fresh->version,
    'snapshot' => $fresh->steps
        ->sortBy('position')
        ->map(fn (ItemWizardStep $step): array => $step->toArray())
        ->values()
        ->all(),
    'published_at' => now(),
    'published_by_id' => auth()->id() ?: null,
]);
```

Version choice documented: first publish snapshots the incremented published version (`1 → 2`) because the existing model method increments `version` before the fresh profile is read.

## Tests

- `php artisan test tests/Feature/Composer/ItemWizardStepVersionPersistenceTest.php` → 4/4 PASS.
- Coverage:
  - publish inserts one version row with full snapshot.
  - subsequent publish inserts an additional version row.
  - unique `(profile_id, version)` blocks duplicate inserts.
  - profile delete cascades version rows.

## Suite Composer

- `php artisan test tests/Feature/Composer/` → 57 PASS / 2 SKIPPED / 0 FAIL.
- Existing skipped tests are unchanged pending-plan tests in `ProfilePublishMidCartRejectionTest`.

## Invariants

- I4 dispatch after commit preserved: insert happens before both dispatches, inside the existing transaction; events keep their `DispatchableAfterCommit` trait.
- I3 branch isolation N/A: no cross-branch query added; version rows inherit profile scope through `profile_id`.
- I6 frozen zones respected: no `Pricing`, `Order/Lifecycle`, or `Fiscal` files touched.

## Statut

PASS.

EXECUTE_DELEGATION: foodking-complex-implementer (sub-task `CV1-V2-CATALOG-REWORK-002-E`)
