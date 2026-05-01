# FoodKing Version A — Sync Validation Matrix — 2026-04-30

## Etat global logiciel

| Mission | Etat | Commentaire |
| --- | --- | --- |
| VA-SYS-06 | PASS_LOCAL | Produit + choix wizard stockables, rupture et backend pricing reject |
| VA-SYS-07 | PASS_LOCAL_STRONG | Authz gestion centrale, branch scope, photo globale, composer show |
| VA-SYS-08 | PASS_RUNTIME_LOCAL_STRONG | Outbox prod-like, realtime contracts, C3 multi-surface local |
| VA-SYS-09 | PASS_DOCS_MEMORY | Runbooks et memoire de centralisation synchronisation |
| VA-SYS-10 | PASS_FINAL_SOFTWARE_CLOSE_POST_VA_SYS_05_RERUN | Validation finale massive relancee apres fermeture VA-SYS-01..05; MySQL surface filtering 6/6 PASS |

## Ce qui est valide fortement

| Fonction | Preuve principale | Niveau |
| --- | --- | --- |
| Rupture produit | Stock/Menu/Pricing/JS tests VA-SYS-06 | Fort local |
| Rupture choix wizard | ChoiceAvailabilityResolver + Pricing reject + POS/Kiosk UX tests | Fort local |
| Backend pricing SSOT | ComposerStepConstraint + anti-forge flows | Fort local |
| Branch isolation gestion | VA-SYS-07B authz matrix | Fort local |
| Photo produit globale | ProductPhotoAuthz + Photo E2E invalidation | Fort local |
| Composer branch profile show | ComposerAuthzMinimalTest et controller hardening | Fort local |
| Outbox failure/retry | OutboxProductionLikeSimulationTest | Fort local |
| Event contract/dedupe | EventContract PHPUnit + Vitest realtime | Fort local |
| C3 multi-surface | Playwright C3 repeat local | Runtime local |
| Surface filtering MySQL | `FrontendSurfaceFilteringTest` on isolated MySQL DB | Fort local |

## Points logiciels fermes avant `VERSION_A_SYSTEM_SOFTWARE: PASS_READY_FOR_HARDWARE_INDUSTRIAL_UAT`

| Point | Priorite | Preuve | Mission |
| --- | --- | --- | --- |
| Dashboard workflow discovery complet | P1 | Selector map and workflow discovery closed | VA-SYS-01 |
| Composer request contract hardening | P1 | Request/service/publish/pricing guards and Composer/Menu/JS tests PASS | VA-SYS-02 |
| Wizard runtime contract final | P1 | Simple product no-wizard lock + published composer priority + fallback tests PASS | VA-SYS-03 |
| Dashboard builder UX hardening | P1/P2 | Stable dashboard test hooks + production build PASS | VA-SYS-04 |
| Full dashboard-to-kiosk/POS/KDS E2E | P1 high | Central product/category/composer projection + POS order + KDS + stock/history Playwright 3/3 PASS | VA-SYS-05 |
| Final massive validation | P0 close | Core sync validation pack PASS, MySQL surface filtering PASS, runtime E2E final artifacts frozen | VA-SYS-10 |

## Points restants hors perimetre logiciel

| Point | Priorite | Pourquoi | Gate |
| --- | --- | --- | --- |
| TPE reel | Hardware UAT | Refus/timeouts/montant terminal ne peuvent pas etre prouves localement | Industrial UAT |
| Imprimante fiscale | Hardware UAT | Sortie papier, sequence et reprint physique | Industrial UAT |
| OS kiosk lockdown industriel | Hardware UAT | Comportement tactile/URL bar/device policy | Industrial UAT |
| Provider realtime cloud | Provider UAT | Latence et quotas hors runtime local | Staging/UAT |
| Google Maps live | Provider UAT | Quotas, geocode reel, erreurs provider | Staging/UAT |

## Matrice finale recommandee pour VA-SYS-10

### Backend PHP

```bash
php artisan test tests/Feature/Services/Menu
php artisan test tests/Feature/Services/Pricing/ComposerStepConstraintTest.php
php artisan test tests/Feature/Stock
php artisan test tests/Feature/Composer
php artisan test tests/Feature/Catalog
php artisan test tests/Feature/Menu
php artisan test tests/Feature/Outbox
php artisan test tests/Feature/EventContractTest.php
php artisan test tests/Unit/Domain/Events/EventContractUnitTest.php
php artisan test tests/Feature/AfterCommitDispatchTest.php
php artisan test tests/Feature/DispatchAfterCommitTest.php
php artisan test tests/Feature/SyncComprehensiveTest.php
php artisan test tests/Feature/KioskRealtimeBroadcastTest.php
```

### Frontend JS

```bash
npx vitest run \
  tests/js/posRuptureUx.spec.js \
  tests/js/kioskRuptureUx.spec.js \
  tests/js/kioskWizardGenericComposer.spec.js \
  tests/js/posWizardComposerProfile.spec.js \
  tests/js/eventContractDedupe.spec.js \
  tests/js/correlationDedupePersistence.spec.js \
  tests/js/correlationDedupeCapacity.spec.js \
  tests/js/realtimeBroadcastFallback.spec.js \
  tests/js/kdsReactsToReconnectStorm.spec.js \
  tests/js/kdsBackoffOn5xx.spec.js \
  tests/js/kdsSyncCadence.spec.js \
  tests/js/kdsVersionGate.spec.js
```

### Runtime local

```bash
php artisan serve --host=127.0.0.1 --port=8000
npx playwright test tests/e2e/c3-runtime-multi-surface.spec.js --repeat-each=2 --retries=0
```

### Hygiene

```bash
npm run production
git diff --check
```

## Go / no-go logiciel

`GO_LOCAL_SOFTWARE` exigeait et a maintenant:

- VA-SYS-00..09 PASS;
- VA-SYS-05 E2E central complet PASS;
- VA-SYS-10 final close post-VA-SYS-05 rerun PASS;
- aucune regression P0/P1 sur pricing, branch_id, fiscal, stock, queue number, outbox.

`VERSION_A_SYSTEM_SOFTWARE: PASS_READY_FOR_HARDWARE_INDUSTRIAL_UAT`

`HOLD_HARDWARE_UAT` reste normal tant que:

- TPE reel non teste;
- imprimante fiscale non testee;
- device lockdown non teste;
- provider realtime cloud non teste;
- maps live non teste.
