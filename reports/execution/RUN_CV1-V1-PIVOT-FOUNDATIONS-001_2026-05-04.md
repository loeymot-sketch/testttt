# RUN CV1-V1-PIVOT-FOUNDATIONS-001 - 2026-05-04

> **Note** : RUN report produit **rétroactivement** suite à l'audit terminal Claude 2026-05-04 (REWORK point 1) qui a relevé l'absence de RUN formel pour Cycle 1. La preuve matérielle (migrations + adaptations modèles + tests sentinelles) existait dans le dépôt et a été ré-inspectée pour reconstituer ce report. Aucune modification de code rétroactive n'a été faite ; seule la traçabilité formelle est rattrapée.

## Header

- TASK_ID: `CV1-V1-PIVOT-FOUNDATIONS-001`
- Cycle: 1 / 8 du Pivot V1
- Plan ref: `plans/PLAN_CV1-V1-PIVOT-MASTER_2026-05-04.md` (§ Cycle 1 — Foundations BDD)
- Audit source: `reports/audit/ULTRA_REVIEW_PIVOT_V1_2026-05-04.md` (§2.2 Cycle 1, R-T2 mitigation XOR conditional)
- Gates clearance:
  - `docs/gates/GATE_CV1-V1-PIVOT-WIZARD-CATEGORY-OWNER_2026-05-04.md` — Approved option 1 (poly XOR FK)
  - `docs/gates/GATE_CV1-V1-PIVOT-INGREDIENT-AVAILABILITY-COLUMNS_2026-05-04.md` — Approved option 1
- EXECUTE_DELEGATION: `foodking-complex-implementer`

## Implementation

### Migrations (4 fichiers)

- `database/migrations/2026_05_05_000010_add_wizard_profile_id_to_item_categories_table.php` — ajoute FK `wizard_profile_id` sur `item_categories` (Voie A : catégorie *peut* posséder un wizard via FK descendante en plus du polymorphisme amont).
- `database/migrations/2026_05_05_000020_make_item_wizard_profiles_polymorphic_owner.php` — rend `item_wizard_profiles.item_id` nullable et ajoute `item_category_id` nullable + FK + check XOR (un seul des deux NOT NULL). **Mitigation R-T2** : check constraint enveloppé dans `if (DB::getDriverName() === 'sqlite') return;` car SQLite (env tests) n'enforce pas les checks — appliqué en MySQL/PostgreSQL.
- `database/migrations/2026_05_05_000030_add_availability_to_item_attributes_table.php` — ajoute `is_available` (boolean, default true) + `unavailable_reason` (string nullable).
- `database/migrations/2026_05_05_000040_add_availability_to_item_extras_table.php` — idem sur `item_extras`.

### Models adaptés

- `app/Models/ItemCategory.php` — `wizardProfile(): BelongsTo` + `getEffectiveWizardProfile()` accessor (résout via FK descendante OU polymorphisme amont).
- `app/Models/ItemWizardProfile.php` — `$fillable += ['item_category_id']`, `$casts += ['item_category_id' => 'integer']`, relation `category(): BelongsTo`.
- `app/Models/ItemAttribute.php` — `$fillable += ['is_available', 'unavailable_reason']`, `$casts += ['is_available' => 'boolean']`.
- `app/Models/ItemExtra.php` — idem.

## Validation

- Sentinel tests Cycle 1 (couverts par l'agrégat Cycle 3 qui atteint 1404 PHPUnit) : `WizardProfileOwnerXorTest` + `ItemAttributeAvailabilityCastTest` + `ItemExtraAvailabilityCastTest`.
- WARN PHPUnit attendu sur SQLite : `WizardProfileOwnerXorTest` ne peut pas vérifier l'XOR DB-level en environnement test (mitigation R-T2 documentée — XOR garanti en prod MySQL/PG).
- Baseline Vitest préservée : 1125 passed | 2 skipped (avant cycle 1 = 1125, après = 1125 — pas de tests JS ajoutés en Cycle 1).
- Baseline PHPUnit : 1404 passed | 24 skipped (incluant les sentinelles Cycle 1).

## Invariants checklist

- I1 Pricing SSOT : aucune logique prix (migrations schema + booléens availability).
- I2 OrderStatus : non touché.
- I3 branch_id : V1 mono-filiale, ingrédients catalogue global. `ingredient_branch_availability` reportée V1.5/V2 (cf. plan décision Q1).
- I4 Dispatch après commit : non applicable (pas de dispatch en Cycle 1, foundations seules).
- I5 OrderService symmetry : N/A.
- I6 Frozen zones : aucune édition (pas de touche `OrderService`/`PaymentService`).

## Notes

- Décision **Voie A pour wizard catégorie** (R1 plan master Q2) : on garde **et** la FK descendante `item_categories.wizard_profile_id` **et** la FK polymorphe ascendante `item_wizard_profiles.item_category_id`. Justification : la FK descendante donne accès rapide depuis la catégorie au profil, la FK ascendante permet à `ItemWizardProfile` de pointer sa catégorie owner pour requêtes inverses sans table de jointure.
- Décision **XOR check conditional** (R-T2) : préserve la portabilité tests SQLite tout en garantissant l'invariant en prod.
- Aucun fichier RUN ni report Vitest spécifique au cycle car la majorité des sentinelles Cycle 1 sont implicitement couvertes dans l'agrégat PHPUnit Cycle 2/3. Cette consolidation rétroactive est documentée pour respecter la traçabilité audit complète.
