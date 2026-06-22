# RUN_BETA_PRE_1_INTEGRITY_SENTINELS — 2026-05-04

**Sommaire.** Lot G : cinq tests sentinelles nouveaux (immutabilité + contrainte d’unicité) et ADR documentaire pour `ItemWizardStepVersion`, sans modification du code produit.

## Tests créés (5)

| Fichier | Test |
|---------|------|
| `ItemWizardStepVersionImmutabilityTest.php` | `test_update_method_throws_runtime_exception` |
| `ItemWizardStepVersionImmutabilityTest.php` | `test_cascade_delete_via_profile_removes_versions` |
| `ItemWizardStepVersionImmutabilityTest.php` | `test_snapshot_is_cast_to_array_on_read` |
| `ItemWizardStepVersionUniqueConstraintTest.php` | `test_unique_profile_version_blocks_duplicate` |
| `ItemWizardStepVersionUniqueConstraintTest.php` | `test_same_version_for_different_profiles_is_allowed` |

## ADR

- `docs/architecture/ADR-WIZARD-STEP-VERSION-2026-05-04.md`

## Résultat tests

- **Sentinelles Lot G :** 5/5 PASS  
- **`php artisan test tests/Feature/Composer/` :** 62 passed, 2 skipped (inchangé vs baseline : tests gelés `ProfilePublishMidCartRejectionTest`)

## Statut

**PASS**
