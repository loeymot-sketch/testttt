# Execution — Post-Stabilisation Robustness Cycle

**Date:** 2026-03-31  
**Type:** Claude deep hardening + QA stabilization  
**Status:** READY_FOR_HEADLESS_VALIDATION / NEEDS_DEVICE_VALIDATION

## Summary

- Le socle kiosk/POS/KDS/OSS a été stabilisé après audit :
  - permissions/roles legacy réalignés sur les contrôleurs réels
  - fixtures/tests incomplets réparés
  - routes, statuts HTTP et payloads de test réalignés sur les contrats actuels
  - `OrderStatusChanged` ajouté sur le path client self-cancel pour éviter un délai de 30s côté KDS/OSS
  - `BranchScope` et `DefaultAccess` remis en cohérence en environnement de test
  - `CompanyResource` et `DiningTableService` durcis pour mieux tolérer les données/config partielles

- La validation PHP monolithique n’est plus bloquée par une vague d’échecs métier, mais par une limite mémoire du runner.
- En compensation, une stratégie de validation PHP **par lots** a été mise en place via scripts dédiés.

## Validation exécutée

### JS

```bash
npm test
npm test -- --run tests/js/KioskWizard.spec.js
npm run production
```

**Résultats :**
- Vitest complet : **108 passed**
- `KioskWizard.spec.js` isolé : **66 passed**
- Build production : **OK**

### PHP ciblé critique

Suites repassées en vert individuellement :
- `tests/Feature/AddressSecurityTest.php`
- `tests/Feature/AdminCrudComprehensiveTest.php`
- `tests/Feature/AntiGravityFinalTest.php`
- `tests/Feature/AntiGravityLoginRedirectionTest.php`
- `tests/Feature/AntiGravityManualTest.php`
- `tests/Feature/AntiGravityTest.php`
- `tests/Feature/BranchScopeTest.php`
- `tests/Feature/KDSFlowTest.php`
- `tests/Feature/KDSScopeRestrictionTest.php`
- `tests/Feature/KioskScopeIsolationTest.php`
- `tests/Feature/KioskSecurityTest.php`
- `tests/Feature/LoyaltyApiTest.php`
- `tests/Feature/OrderFlowTest.php`
- `tests/Feature/PosDiscountTest.php`
- `tests/Feature/PosUITest.php`
- `tests/Feature/SecurityComprehensiveTest.php`
- `tests/Feature/SyncComprehensiveTest.php`

### Validation par lots

```bash
bash scripts/run_php_feature_batches.sh all
bash scripts/profile_php_memory.sh
```

**Résultats :**
- `auth-security` : OK
- `kiosk-pos-sync` : OK
- `admin-seeders-reports` : OK
- Profil mémoire écrit dans `reports/execution/php_memory_profile_latest.md`

## Outils ajoutés

- `scripts/run_php_feature_batches.sh`
- `scripts/profile_php_memory.sh`

## Documentation mise à jour

- `docs/TEST_PLAN.md`
- `docs/API_MAP.md`
- `scripts/README.md`

## Residual Risks

- `php artisan test` complet reste sensible à la mémoire du runner malgré les suites vertes isolées et par lots.
- Le flux réel borne/TPE/device n’a pas encore été validé sur un environnement browser/device configuré.
- Le runtime local actuel indique :
  - `broadcast=pusher`
  - `queue=database`
  - `kiosk_auto=no`
  Ce dernier point bloque un tunnel borne browser réellement autonome sans préparation runtime supplémentaire.

## Next Step

- Lire `reports/antigravity/latest.md` pour la synthèse de validation headless sync
- Lire `reports/review/latest.md` pour le verdict readiness actualisé
- Utiliser `scripts/run_php_feature_batches.sh` comme pipeline PHP de référence tant que le run monolithique reste limité par la mémoire
