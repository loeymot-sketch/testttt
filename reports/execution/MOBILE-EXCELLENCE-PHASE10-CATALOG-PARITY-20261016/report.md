# Rapport — MOBILE-EXCELLENCE-PHASE10-CATALOG-PARITY-20261016

Date : 2026-10-16  
Périmètre : preuve de parité du catalogue boissons de l’application mobile Le Cayenne.

## Verdict de la phase

`PASS` pour la vérification structurelle. `NEEDS_FIX` pour la release produit à cause des visuels manquants et des références demandées qui ne sont pas encore présentes dans le backend.

## Vérifications exécutées

```text
node mobile/tests/mobile-catalog-parity.spec.js
PASS: 15 mobile drinks match the canonical names/prices.
PASS: 15 formula drink choices match the catalogue.
VISUAL_BLOCKERS: Orangina 33cl, Fuze Tea 33cl, Hawaï 33cl, Perrier 33cl

node mobile/tests/mobile-catalog-visual.spec.js
PASS: 39 product visuals, 9 categories, 15 drinks + wizard parity, canonical copies, allergens and business invariants

node mobile/tests/mobile-preview-freshness.spec.js
PASS: 24 local resources versioned; CDN order, integrity and runtime config preserved

node mobile/tests/mobile-ui-excellence.spec.js
PASS: Atelier Cayenne home structure, real-image wiring, prices and callable branch phone are guarded

node mobile/tests/mobile-identity.spec.js
PASS: canonical logo, branch phone, callable contact, safe fallback, and catalog invariants

node mobile/tests/navigation.spec.js
PASS: scroll lifecycle, screen wiring, 8 product slots, hero fit and click-layer guards

npx playwright test tests/mobile-e2e/auth-otp-local.spec.js tests/mobile-e2e/catalog-visual-critical.spec.js --config=tests/mobile-e2e/playwright.config.js
4 passed (55.6s)
```

## Résultats catalogue

Le fixture serveur `reports/goal-web-app-sync/catalog-canonical.json` contient 15 boissons actives : Coca-Cola, Coca-Cola Zero, Fanta Orange, Sprite, Oasis Tropical, Orangina, Eau Plate, Capri-Sun, Coca Cherry, Tropico, Ice Tea Pêche, Fanta Citron, Fuze Tea, Hawaï et Perrier.

L’application mobile expose exactement ces 15 références, avec les mêmes noms et prix. L’alias technique mobile `ice-tea-peche` correspond au slug serveur `ice-tea`. Le sélecteur de boisson des formules expose également les 15 choix.

## Blockers visuels

Les assets suivants sont neutres dans l’app et ne doivent pas être remplacés par une autre marque :

- Orangina 33cl
- Fuze Tea 33cl
- Hawaï 33cl
- Perrier 33cl

Le dépôt contient des visuels corrects pour plusieurs références, mais pas de visuels propriétaires/validés pour ces quatre produits. Il faut fournir ou valider les quatre fichiers avant soumission ; l’image du backend est aussi documentée comme image de repli pour Fuze Tea, Hawaï et Perrier.

## Écart avec la liste demandée

Oasis Cassis, Schweppes Agrumes, bouteille de Coca et « Fusty » ne sont pas présents dans le catalogue backend canonique actuel, ni dans le lot d’assets fourni. Leur ajout doit être fait dans la source métier serveur avec nom, slug, prix, disponibilité, image et présence borne/web/app ; aucune valeur n’a été inventée côté mobile.

## Contrôles d’invariants

- Prix : aucune logique de prix métier ajoutée ; comparaison contre le fixture serveur.
- Branche : `branch_id=1` conservé ; aucune lecture cross-branch.
- `OrderStatus`, dispatch et services de commande : non touchés.
- Zones gelées et migrations : non touchées.
- Paiement Mollie, loyalty et SMS : hors périmètre de cette phase.

## Limitation connue

`git diff --check` global rencontre un espace final préexistant dans `reports/post_execute_latest.log:382`. Le diff ciblé de cette phase est propre.

## Suite obligatoire avant App Store

1. Décider le catalogue backend exact, notamment les quatre références non canoniques.
2. Fournir les quatre visuels de marque manquants et le visuel final du tiramisu si une nouvelle recette est retenue.
3. Rejouer la parité sur backend, borne, web et mobile après mise à jour serveur.
4. Traiter séparément les gates loyalty, Mollie, SMS de production et packaging natif iOS.

`AUDIT_VERDICT: PASS`

`GPT_FINAL_AUDIT_VERDICT: PASS`
