# RUN — P_MEGA_W3_REMEDIATION_2026-04-20

```
EXECUTE_DELEGATION: foodking-routine-implementer
```

## REMEDIATION_ATTEMPT_1 (W3 bugs invisibles)

```
REMEDIATION_ATTEMPT_1:
  outcome: PASSED
  delegated_to: foodking-routine-implementer
  bug_signatures_addressed:
    - de_bn_missing_kiosk_section
    - kioskFilter_init_deep_link
    - allergen_code_case_norm
    - extractAllergenCodes_string
    - setCustomerAllergens_dead (conservé + couverture test)
    - sentinel_phpunit_fixture
```

## Tests

- Vitest global : **540 / 540** (baseline 535 + 5 nouveaux cas : kioskAllergenMerge 9–11, kioskFilterPersist 7–8).
- PHPUnit `OrderAllergenSnapshotComposedTest` : **FAILED** (intention sentinelle). Après fixture : `allergens_snapshot` reste `[]` car `OrderItemAllergenSnapshot::resolveSnapshot` ne lit pas encore le pivot `item_extra_allergens` / extras — écart attendu jusqu’au fix backend.

## Décisions

- **SEV-1** : section `kiosk` complète copiée depuis `resources/js/languages/fr.json` vers `de.json` et `bn.json` (baseline FR acceptable vs clés brutes).
- **SEV-2** : init `kioskFilter` hoistée dans `requireKioskAuth` (guard parent `/kiosk`) — `store` déjà importé dans `kioskRoutes.js`, pas de dynamic import nécessaire.
- **LOW-5** : `setCustomerAllergens` **conservé** ; commentaires `RESERVED` + tests Vitest cas 8 (roundtrip persistance).

## Findings

- **FINDING_DE_BN_FR_BASELINE_TRANSLATIONS** : les chaînes `kiosk.*` en `de.json` / `bn.json` sont actuellement identiques au FR ; revue traducteur natif requise avant prod locale réelle.

## Fichiers touchés

- `resources/js/languages/de.json`
- `resources/js/languages/bn.json`
- `resources/js/router/modules/kioskRoutes.js`
- `resources/js/helpers/kioskFilters.js`
- `resources/js/store/modules/kioskFilter.js`
- `tests/js/kioskAllergenMerge.spec.js`
- `tests/js/kioskFilterPersist.spec.js`
- `tests/Feature/Orders/OrderAllergenSnapshotComposedTest.php`
- `reports/execution/RUN_P_MEGA_W3_REMEDIATION_2026-04-20.md`
