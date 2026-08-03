# Audit final restauration catalogue + wizard POS — 2026-05-06

## Verdict

PASS.

Le probleme manuel constate venait de la base locale polluee par les fixtures E2E/audit, pas d'un catalogue metier volontairement remplace.

## Cause racine

1. Les tests Playwright avaient injecte des categories et produits de test directement dans la base utilisee en manuel :
   - `PW-E2E Tacos ...`
   - `AUDIT-KIOSK-MULTI ...`
   - `AUDIT-POS-MULTI ...`
   - `E2E Cat ...`
2. Les specs nettoyaient surtout au debut du run, pas systematiquement a la fin. Apres audit, les fixtures restaient visibles dans la caisse et la borne.
3. Le produit `Tacos L E2E Menu ...` ouvrait un flux de test minimal, ce qui donnait l'impression que le wizard caisse historique avait ete remplace.
4. Apres `menu reset`, plusieurs conversions image Spatie etaient absentes ; le modele renvoyait une URL `*-thumb.png` inexistante, ce qui cassait des images produit.

## Corrections appliquees

- Nettoyage applique sur la base locale :
  - `PW-` : 135 categories, 141 produits et donnees liees supprimes.
  - `AUDIT-` : categories/produits/commandes audit supprimes.
  - `E2E` : categories/items E2E restants supprimes.
- Catalogue officiel restaure avec `php artisan menu reset --force`.
- Verification officielle : `php artisan menu verify` PASS.
- Comptes visibles renommes :
  - `Caissier E2E` -> `Caissier`
  - `Chef E2E` -> `Chef cuisine`
- Helper POS E2E corrige :
  - nettoyage `PW-E2E` avant creation fixture.
  - nettoyage `PW-E2E` en fin de spec POS.
  - suppression des attributs temporaires `Viande A/B ...`.
- Spec borne corrigee :
  - nettoyage `AUDIT-KIOSK-MULTI` en fin de test.
- Images produit corrigees :
  - `Item::thumb/cover/preview` retombe sur l'image originale si la conversion media manque.

## Validations

```bash
php artisan menu verify
PLAYWRIGHT_NO_WEB_SERVER=1 PLAYWRIGHT_CHANNEL=chrome E2E_BACKEND=1 npx playwright test tests/e2e/audit-pos-multiproduct-kds-journey.spec.js --project=chromium --workers=1
PLAYWRIGHT_NO_WEB_SERVER=1 PLAYWRIGHT_CHANNEL=chrome E2E_BACKEND=1 npx playwright test tests/e2e/audit-kiosk-multiproduct-kds-journey.spec.js --project=chromium --workers=1
```

Resultats :

- POS E2E : 1 passed.
- Borne E2E : 1 passed.
- Re-run POS apres patch nettoyage : 1 passed.
- Etat DB final : `bad_categories=0`, `bad_items=0`, `bad_attributes=0`, `categories=13`, `items=63`, `attributes=6`.

## Audit visuel

Rapport visuel :

- `reports/audit/catalog-wizard-restore-2026-05-06/RAPPORT_AUDIT_CATALOGUE_WIZARD_RESTAURE.md`
- Captures :
  - `01-pos-catalogue-restaure.png`
  - `02-pos-nos-tacos.png`
  - `03-pos-wizard-tacos-l-restaure.png`
  - `04-kiosk-accueil.png`
  - `05-kiosk-categories-restaurees.png`

Assertions visuelles :

- `pos_bad_fixture_text=false`
- `pos_header_clean=true`
- `pos_wizard_bad_fixture_text=false`
- `pos_wizard_contains_official_tacos=true`
- `pos_wizard_contains_viande_sauce=true`
- `pos_images_loaded=true`
- `kiosk_bad_fixture_text=false`
- `kiosk_official_categories_visible=true`
- runtime errors : `[]`

## Etat final attendu

- La caisse affiche le vrai catalogue Le Cayenne.
- La borne affiche les categories officielles.
- Le wizard caisse s'ouvre sur le vrai `Tacos L (2 Viandes)` avec viandes, crudites, sauces, supplements et formule.
- Les futurs audits POS/borne ne doivent plus laisser de produits ou categories `PW/AUDIT/E2E` dans la base manuelle.
