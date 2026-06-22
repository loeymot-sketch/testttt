# T05 — Allergènes UE codes FR : audit chaîne complète

**Date** : 2026-04-20  **Statut** : PENDING  **Subagent** : `explore`

## Objectif unique

Le seeder a été migré aux codes FR (`crustaces`, `lait`, `sulfites`, `mollusques`, etc.).
Vérifier que **toute la chaîne** suit : modèle, ressources API, snapshot order_items,
KDS, kiosk filters, i18n keys, tests, migrations.

## Subagent à lancer (prompt prêt à coller)

```
Tu es un sous-agent `explore`. Racines :
A = /Users/1millnonstop/Downloads/projet/foodking-web/web/testttt
B = /Users/1millnonstop/Downloads/projet/foodking-web/web/testttt-kiosk-p93

Mission : auditer la migration des codes allergènes EN→FR (gluten, crustaces, oeufs,
poisson, arachides, soja, lait, fruits_a_coque, celeri, moutarde, sesame, sulfites, lupin,
mollusques) à travers TOUTE la chaîne sur les DEUX clones.

Étapes :
1) Vérifier seeder identique sur A et B :
   - database/seeders/AllergensSeeder.php
2) Rechercher anciens codes anglais (qui doivent disparaître ou être migrés) :
   `rg -n "'milk'|'crustaceans'|'eggs'|'fish'|'peanuts'|'soy'|'tree_nuts'|'celery'|'mustard'|'sulphites'|'molluscs'" -g '!node_modules' -g '!*.lock'`
3) Vérifier traductions :
   - resources/js/languages/fr.json, en.json, ar.json
   - resources/lang/*/allergens.php (s'il existe)
   Les clés `allergens.lait`, `allergens.crustaces`, etc., sont-elles présentes dans
   les 3 langues ?
4) Resources API : `app/Http/Resources/{Normal,Variant,Combo}ItemResource.php`,
   `app/Http/Resources/Kds*Resource.php`. Sérialisent-elles `code` brut ou name traduit ?
5) Snapshot order_items : `app/Services/FrontendOrderService.php` (allergens_snapshot) +
   migration `<TS>_add_allergens_snapshot_to_order_items.php`. Le snapshot stocke `code`
   FR maintenant ? Cohérence avec données existantes en DB ?
6) Tests :
   - tests/Feature/KioskPhase1/AllergensSeederTest.php (les 14 codes)
   - tests/Feature/Resources/{Normal,Variant,Combo}ItemResourceAllergensTest.php
   - tests/Feature/Orders/OrderAllergenSnapshotTest.php
   - tests/Feature/Orders/KDSAllergenVisibilityTest.php
   - tests/Feature/Menu/MenuProjectionServiceTest.php
   Ces tests utilisent-ils tous les codes FR ? Aucun reliquat anglais ?
7) Migration de données existantes : y a-t-il un `<TS>_rename_allergen_codes_to_fr.php`
   pour migrer les rows déjà en DB ?
8) Composants Vue qui filtrent par code allergène : `rg -n "allergens?\." resources/js`.

Sortie : /Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/reports/audit-orchestration/REPORT_TASK05_ALLERGEN_RENAME_FR_2026-04-20.md

Format : tableau "Couche | OK / KO | Preuve | Action".
```

## Lecture obligatoire

- A et B : `database/seeders/AllergensSeeder.php`
- A et B : `database/migrations/*allergen*`, `*allergens*`
- A et B : `app/Http/Resources/NormalItemResource.php`, `KdsOrderResource.php`
- A et B : `resources/js/languages/{fr,en,ar}.json`
- A et B : `tests/Feature/KioskPhase1/AllergensSeederTest.php`

## Checklist multi-points

- [ ] V1. Seeder identique A↔B avec 14 codes FR
- [ ] V2. Aucun reliquat anglais dans le code applicatif (hors seeder migration)
- [ ] V3. Traductions FR / EN / AR cohérentes
- [ ] V4. Resources API exposent codes corrects + traductions
- [ ] V5. Snapshot `allergens_snapshot` ↔ codes FR
- [ ] V6. Migration des données existantes prévue (script ou non)
- [ ] V7. Tous les tests allergènes ciblent codes FR (pas d'oubli)
- [ ] V8. Composants Vue (filter, badge) sans hardcode anglais

## Critères PASS / FAIL

- **PASS** : 8 V cochées, aucun reliquat anglais en prod path.
- **FAIL** : ≥ 1 référence anglaise en prod path → corruption snapshot ou affichage cassé.

## Output

`reports/audit-orchestration/REPORT_TASK05_ALLERGEN_RENAME_FR_2026-04-20.md`

## Si FAIL → action

→ T05b `generalPurpose` : produire la liste des fichiers à patcher + script de migration
SQL pour `allergens.code` existants. Patch proposé, non appliqué.
