# Mission — Refonte mobile Le Cayenne — Phase 1

## TASK_ID
MOBILE-EXCELLENCE-PHASE1-20261016

## Mandat
Améliorer la vraie application mobile Le Cayenne uniquement, en commençant par la
parité visuelle du catalogue avec la borne : catégories illustrées, cartes produits
avec vraies images, boissons et desserts cohérents, et allergènes retirés des cartes
compactes tout en restant accessibles dans le détail produit.

## Hors périmètre de cette tranche
- Site web, borne, caisse, KDS et projet FoodKing générique.
- Modification de la tarification backend, de la base de données, des migrations,
  des services de commande ou des middlewares d'authentification.
- Activation Mollie ou changement de la politique de fidélité : ces sujets feront
  l'objet de tranches dédiées après audit des contrats API existants.

## Livrable attendu
- Les catégories et produits mobiles utilisent les assets locaux réels quand ils existent.
- Les boissons connues du catalogue sont distinguées par leur vrai visuel et ne sont
  plus présentées sous un mauvais asset réutilisé.
- La home et le menu affichent des visuels produits cohérents, sans badge allergène
  parasite dans les cartes compactes.
- Les informations allergènes restent disponibles dans le détail/recap produit pour
  conserver la conformité d'information.

## Vérification
- `node mobile/tests/menu.spec.js`
- `node mobile/tests/mobile-catalog-visual.spec.js`
- `node mobile/tests/navigation.spec.js`
- `git diff --check`
- Test navigateur de l'application mobile sur `http://127.0.0.1:8088/?dev` : accueil,
  catégories, menu, boissons, détail produit et panier.
