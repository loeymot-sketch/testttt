# Task — MOBILE-EXCELLENCE-PHASE6-VISUAL-ALIGNMENT-20261016

## Demande

Renforcer la correspondance visuelle de l’app mobile Le Cayenne avec le menu
réel : photos de catégories et de nouveautés, packshots boissons exacts quand
ils existent localement, logo, numéro de contact et présentation des allergènes.

## Périmètre borné

- `mobile/data/menu.js`
- `mobile/screens-main.jsx`
- `mobile/tests/mobile-catalog-visual.spec.js`
- `tests/mobile-e2e/catalog-visual-critical.spec.js`
- `reports/execution/MOBILE-EXCELLENCE-PHASE6-VISUAL-ALIGNMENT-20261016/report.md`

## Hors périmètre

- Aucun changement de prix, d’identifiant métier, de règle de composition ou de
  payload de commande.
- Aucun remplacement d’un packshot absent par une autre marque/flavour.
- Aucun changement backend, migration, paiement Mollie, fidélité persistante,
  auth, branch_id, POS, borne ou KDS.
- Aucun ajout d’image générée présentée comme une photo officielle de marque.

## Invariants

- Le backend reste la source de vérité des prix.
- Les noms, IDs, slugs, catégories et flags métier restent inchangés.
- Les images de marque doivent être exactes ; sinon fallback neutre explicite.
- Les catégories et nouveautés utilisent de vrais assets locaux et accessibles.
- Les allergènes restent consultables dans le détail/recap légal, mais aucun
  badge d’alerte ne doit apparaître sur les cartes compactes ou sur les photos.
- Le contact affiché par l’app doit provenir du numéro canonique de la branche,
  aligné sur la valeur borne/backend `03 65 67 82 91`; le runtime doit garder
  `window.LC.config.branchId === window.LC.menu.branch.id`.

## Validation prévue

- Test Node catalogue : baseline SHA-256 métier immuable
  `dba1d13ba410398c0429ad164d937e28cffb91336cf176b0d384c20b313ae91d`, images locales, Orangina exact,
  tiramisu réaliste, catégories et phone canoniques.
- Playwright : accueil → menu → boissons, photos chargées, absence d’emoji ou
  badge allergène sur toutes les cartes compactes, disclosure conservée dans le
  détail, numéro de branche rendu, `branchId` cohérent et aucun débordement
  horizontal.
- Commandes prévues : `node mobile/tests/mobile-catalog-visual.spec.js`,
  `node mobile/tests/mobile-identity.spec.js`, `node mobile/tests/navigation.spec.js`,
  `npx playwright test tests/mobile-e2e/catalog-visual-critical.spec.js --config=tests/mobile-e2e/playwright.config.js`
  sur `127.0.0.1:8087`, puis `git diff --check` sur le périmètre.
