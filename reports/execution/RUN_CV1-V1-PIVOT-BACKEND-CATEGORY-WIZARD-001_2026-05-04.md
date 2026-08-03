# RUN CV1-V1-PIVOT-BACKEND-CATEGORY-WIZARD-001 - 2026-05-04

> **Note** : RUN report produit **rétroactivement** suite à l'audit terminal Claude 2026-05-04 (REWORK point 2) qui a relevé l'absence de RUN formel pour Cycle 2. La preuve matérielle (services + endpoints + permissions + tests) existe dans le dépôt et a été ré-inspectée pour reconstituer ce report. Aucune modification de code rétroactive n'a été faite ; seule la traçabilité formelle est rattrapée.

## Header

- TASK_ID: `CV1-V1-PIVOT-BACKEND-CATEGORY-WIZARD-001`
- Cycle: 2 / 8 du Pivot V1
- Plan ref: `plans/PLAN_CV1-V1-PIVOT-MASTER_2026-05-04.md` (§ Cycle 2 — Backend Composer category-aware + IngredientService)
- Audit source: `reports/audit/ULTRA_REVIEW_PIVOT_V1_2026-05-04.md` (§2.2 Cycle 2)
- EXECUTE_DELEGATION: `foodking-complex-implementer` (2 sub-agents lancés en parallèle pour Volet A composer category + Volet B IngredientService — workspace réservé via `agent-activity-log start`)

## Implementation

### Volet A — ComposerProfileService category-aware

- `app/Services/Composer/ComposerProfileService.php` — ajoute :
  - `createForCategory(ItemCategory $category, ?int $sourceTemplateId = null): ItemWizardProfile`
  - `showForCategory(ItemCategory $category): ?ItemWizardProfile`
  - `applyTemplateToCategory(ItemCategory $category, int $templateId, bool $replace = false): ItemWizardProfile`
  - Symétriques aux méthodes existantes item-based, partagent les helpers internes `cloneSteps`, `bumpVersion`, `snapshotStepsToVersionTable`.
- `app/Http/Controllers/Admin/ComposerProfileController.php` — actions `showForCategory`, `storeForCategory`, `applyTemplateToCategory` exposées sous prefix `/api/admin/composer/categories/{category}/...`.
- `routes/api.php` — nouvelles routes :
  - `GET /api/admin/composer/categories/{category}/profile`
  - `POST /api/admin/composer/categories/{category}/profile`
  - `POST /api/admin/composer/categories/{category}/apply-template`
- Routes `/api/admin/composer/profiles/{profile}/steps/...` (CRUD step-level, publish, diff) **conservées partagées** entre item et category, car elles opèrent sur l'`ItemWizardProfile` (peu importe son owner).

### Volet B — IngredientService + endpoints admin

- `app/Services/Ingredients/IngredientService.php` (NEW) — agrège `ItemAttribute` + `ItemExtra` + `ItemAddon` sous une vue unique `{global_id, type, id, name, is_available, unavailable_reason, used_by_count}`. Constants `TYPE_ATTRIBUTE/EXTRA/ADDON`, helpers `globalId(string,int):string` + `parseGlobalId(string):?array`. Méthodes `listAll(?int branchId=null)`, `listByType(string)`, `findByGlobalId(string)`. `usageCountForAttribute()` lit `item_wizard_steps.source_item_attribute_id` (Cycle V2 source-FK) avec fallback `source_ref` legacy ; `usageCountForExtra()` filtre par `extra_group` + `group_label`. **Note** : paramètre `branchId` accepté pour stabilité de signature mais NON appliqué en V1 mono-filiale (cf. plan Q1, annotation PHPDoc dans le service ajoutée 2026-05-04 audit REWORK point 3).
- `app/Services/Ingredients/IngredientAvailabilityService.php` (NEW) — `toggle(string $type, int $id, bool $isAvailable, ?string $reason = null): bool`. Wrappé en `DB::transaction`, persiste `is_available` + `unavailable_reason` (raison auto = `manual_admin` si non fournie sur OFF, NULL sur ON). Le dispatch event est ajouté en Cycle 3 (`IngredientAvailabilityChanged`).
- `app/Http/Controllers/Admin/IngredientController.php` (NEW) — actions `index`, `show`, `availability` (PUT/PATCH `/admin/ingredients/{globalId}/availability`).
- `app/Http/Resources/IngredientResource.php` (NEW) — shape API.
- `database/seeders/IngredientPermissionSeeder.php` (NEW) — crée permission `ingredients_manage` et l'attache aux rôles `Admin` et `Manager` (mapping permission documenté `STUDIO_PERMISSIONS_TO_SPATIE_MAP_2026-05-04.md`).
- `routes/api.php` — nouvelles routes :
  - `GET /api/admin/ingredients` (filtre optionnel `?type=attribute|extra|addon`)
  - `GET /api/admin/ingredients/{globalId}` (read)
  - `PUT|PATCH /api/admin/ingredients/{globalId}/availability` (toggle, `{is_available: bool, reason?: string}`)

## Validation

Sentinelles PHPUnit Cycle 2 (couvertes par l'agrégat Cycle 3 qui atteint 1404 PHPUnit) :
- `tests/Feature/Composer/ComposerProfileServiceCategoryTest.php` — création / show / applyTemplate pour catégorie.
- `tests/Feature/Ingredients/IngredientServiceListTest.php` — agrégation 3 types + filtre.
- `tests/Feature/Ingredients/IngredientControllerToggleTest.php` — endpoint toggle + permission `ingredients_manage`.
- `php artisan test` final post-Cycle 3 : 1404 passed | 24 skipped.

Vitest baseline préservée (1125 passed | 2 skipped) — Cycle 2 backend-only, pas de touche frontend.

## Invariants checklist

- I1 Pricing SSOT : aucune logique prix (le service liste juste availability + usage count).
- I2 OrderStatus : non touché.
- I3 branch_id : `IngredientService::listAll(?int $branchId = null)` accepte le paramètre (stabilité signature V2 multi-filiale) mais ne filtre pas en V1 — décision Q1 plan master + annotation PHPDoc explicite (2026-05-04 audit REWORK).
- I4 Dispatch après commit : Cycle 2 ne dispatche pas encore (toggle service set just `save()`, dispatch ajouté Cycle 3 via `DispatchableAfterCommit`).
- I5 OrderService symmetry : N/A.
- I6 Frozen zones : aucune édition (création services neufs + extensions Composer non-frozen).

## Notes

- **Délégation parallèle** : 2 sub-agents complex implementer ont travaillé simultanément (Volet A vs Volet B) avec réservations workspace disjointes — Volet A = `app/Services/Composer/`, `app/Http/Controllers/Admin/ComposerProfileController.php`, `routes/api.php` (section composer) ; Volet B = `app/Services/Ingredients/`, `app/Http/Controllers/Admin/IngredientController.php`, `app/Http/Resources/IngredientResource.php`, `database/seeders/IngredientPermissionSeeder.php`, `routes/api.php` (section ingredients). Coordination via `agent-activity-log` start/done pairs.
- **Permission `ingredients_manage`** : créée Cycle 2, mappée Admin + Manager. Cycle 6 a confirmé que la sidebar Ingrédients respecte cette permission via meta route.
- **Routes `/admin/composer/profiles/{profile}/...` non touchées** : continueront de servir wizard item ET category (l'`ItemWizardProfile` poly-owner Cycle 1 garantit la cohérence).
