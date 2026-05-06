# RUN V1-FINITION-CONTINUE-DEEP-VALIDATION — 2026-05-05

## Objet

Continuation de l'audit de finition V1 après le paquet Cursor
`reports/audit/order-sync-journey-doc-2026-05-05/`.

Portée couverte dans cette boucle :
- Caisse POS : parcours caisse, paiement, reçu, ordre comptoir, tacos/viandes/extras.
- Borne : verrouillage, retour post-paiement, garde ecran noir, parcours kiosk.
- KDS / OSS : affichage cuisine, transitions, synchronisation instruction POS/KDS/API.
- Admin / centrale : catalogue studio, gestion centrale, dashboard CRUD.
- Invariants backend : prix SSOT backend, OrderStatus, branch_id, dispatch apres commit, stock, parite OrderService / FrontendOrderService.

## Corrections appliquees dans cette continuation

1. `tests/e2e/composer-mega-flow.spec.js`
   - Le test verifiait que tout le body KDS ne contenait plus `PAIEMENT COMPTOIR`.
   - Probleme : le KDS peut afficher d'autres commandes comptoir en attente provenant d'autres specs.
   - Correction : assertion limitee a la carte KDS de la commande confirmee via `queue_number`.

2. `tests/e2e/pos/tacos-4-viandes-cash-flow.spec.ts`
   - Connexion POS alignee sur le helper commun.
   - Recherche produit POS V5 robuste via role button / aria-label.
   - Fallback recherche par nom de fixture.
   - Selecteurs du modal limites a `#item-variation-modal.active` pour eviter les anciens noeuds caches.
   - Total panier et paiement alignes sur les composants POS V5.
   - Recu verifie cote ticket cuisine + ticket client fiscal.

3. `tests/e2e/helpers/pos-tacos-fixture.js`
   - La fixture ne reutilise plus un ancien item dont la categorie etait marquee `tacos` mais sans choix viandes.
   - Elle cree un article E2E Tacos deterministe avec deux viandes, un extra et les stocks branche pour item / variations / extra.

## Validations executees

### Playwright global critique

Commande :
`PLAYWRIGHT_CHANNEL=chrome E2E_BACKEND=1 npx playwright test ... --project=chromium --workers=1`

Resultat final :
- `51 passed`
- duree : `5.5m`

Notes :
- Premier essai avec `PLAYWRIGHT_NO_WEB_SERVER=1` : echec precondition `ERR_CONNECTION_REFUSED http://localhost:8000/login`.
- Deuxieme essai avec serveur Playwright : `49 passed`, `2 failed`.
- Apres corrections : `51 passed`.

### Playwright cible Tacos

Commande :
`PLAYWRIGHT_CHANNEL=chrome E2E_BACKEND=1 npx playwright test tests/e2e/pos/tacos-4-viandes-cash-flow.spec.ts --project=chromium --workers=1`

Resultat final :
- `1 passed`
- sans retry apres correction du modal actif.

### Guards statiques POS

Commandes :
- `npm run pos:lint:pricing`
- `npm run pos:lint:status`

Resultats :
- pricing : OK, warning existant `signoff-pending until 2026-05-10`
- status : OK

### PHPUnit cible invariants

Commande :
`php artisan test --filter='OrderServiceFrontendOrderServiceSymmetryTest|OrderBranchIsolationTest|PricingService|PriceChangeSnapshot|SubmitRevalidatesChoiceAvailabilityThroughPricing|KdsExpectedStatusConflict|PosReceiptTaxLines|KdsSnapshotImmutable|KdsAllergenAggregationSplit|KioskEventBranchIsolation|PosParkedRecallVariationAvailability'`

Resultat :
- `63 passed`
- duree : `7.52s`

### Vitest complet

Commande :
`npm run test`

Resultat :
- `193 passed`
- `1162 passed | 2 skipped`
- duree : `11.80s`

Notes :
- Warnings de test connus : composants stubs Vue, happy-dom `ECONNREFUSED 127.0.0.1:3000`, clefs i18n de settings drawer. Aucun echec.

### PHPUnit complet

Commande :
`php artisan test`

Resultat :
- `1438 passed`
- `24 skipped`
- duree : `202.28s`

Notes :
- Skips documentes : contrats MySQL/SQLite, contraintes planifiees, gaps explicitement marques comme pending.
- Aucun echec.

## Verdict technique

PASS sur la boucle de continuation.

Les deux regressions detectees dans le run 49/51 etaient des problemes de robustesse E2E/fixture, pas des regressions produit backend.
Apres correction, les parcours POS, Kiosk, KDS, OSS, admin/central, pricing, stock, branch isolation et synchronisation passent les validations executees.

## Invariants verifies

- Prix : validations backend `PricingService*`, anti-forgery et guards pricing OK.
- OrderStatus : guard statique OK, state machine PHPUnit OK.
- branch_id : tests branch isolation POS/KDS/OSS/Kiosk OK.
- Dispatch apres commit : PHPUnit complet inclut after-commit/outbox/stock availability.
- Stock : stock decrement, rupture, release, branch stock, composer choices OK.
- Parite OrderService / FrontendOrderService : symmetry tests OK.

## Points a garder en backlog separe

- Warning `pos:lint:pricing` signoff-pending jusqu'au 2026-05-10.
- Skips PHPUnit dependants MySQL/SQLite a valider dans le job MySQL indique par les tests.
- Warnings Vitest de stubs et fetch happy-dom non bloquants.
