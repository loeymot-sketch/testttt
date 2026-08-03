# Product Wizard + Catalogue Central — Audit POS, Borne, Stock, Configuration Produit

Date: 2026-04-27  
Mode: audit + tests + browser inspection only. Aucun patch produit.  
Scope: caisse POS, borne live/prototype, catalogue central, stock branche, configuration produit/wizard.  
Verdict: `PASS_WITH_RELEASE_REWORK_REQUIRED`

## 1. Verdict court

Le coeur V1 est fonctionnel pour la prise de commande, le prix serveur et la protection stock:

- le POS charge un catalogue caisse filtre par surface et branche;
- la borne consomme le catalogue central via `frontend/menu` / endpoints frontend;
- le backend bloque les produits inactifs, invisibles, indisponibles globalement ou en rupture branche;
- les variations/supplements sont recalcules cote backend;
- les regles multi-choix `min_select`, `max_select`, `allow_repeat` sont bien validees par le moteur.

Mais le systeme n'est pas encore complet pour ton objectif metier: "je peux ajouter/modifier un produit dans le dashboard, choisir ses questions de wizard, ses viandes, ses crudites, ses sauces, ses supplements, ses options menu/boisson, puis le voir correctement sur caisse et borne sans modifier le code".

Le manque principal n'est pas le moteur. C'est l'interface de configuration:

1. `ItemCreateComponent` cree seulement le produit de base: nom, prix, categorie, image, statut.
2. Le type de wizard est sur la categorie, pas sur un builder produit.
3. Les attributs existent, mais l'ecran admin ne permet pas de regler `min_select`, `max_select`, `allow_repeat`.
4. `ItemAttributeRequest` et `ItemAttributeResource` ne valident/exposent pas ces champs, alors que la base et le moteur les utilisent.
5. Les extras ont `group_label`, mais les donnees actuelles ont `530` extras avec `group_label` vide; le wizard depend donc encore trop des noms/heuristiques.
6. La route kiosk live sous session admin affiche l'habillage backend autour de `/kiosk/idle`, car `DefaultComponent.applyThemeFromRoute()` ignore `meta.isKiosk`.

Conclusion: commande/stock/prix = robuste; configuration produit/wizard = a industrialiser avant de dire "gestion centrale parfaite".

## 2. Parcours POS audite

Browser: `http://127.0.0.1:8000/admin/pos-v4`.

Constats ecran:

- POS affiche les categories FoodKing: Nos Tacos, Nos Sandwichs, Nos Burgers, Nos Assiettes, Ojja, Omelettes, Nos Salades, Poulet croustillant, Menus enfants, Desserts, Boissons.
- Un ticket etait deja charge avec `Le Terminator`, quantite 1, total 9.00 EUR.
- Le ticket affichait les choix wizard: `Viandes: Merguez, Kefta`, `Pain: Pain`, `Crudités: Salade, Tomate, Oignon`.
- Le POS affichait aussi une alerte rouge `Connexion perdue — hors ligne`.

Evaluation:

- La prise de commande POS peut afficher et modifier une composition produit.
- Le POS garde un panier structure avec variations/extras.
- Le bandeau hors ligne est un risque UX/temps reel: il ne prouve pas que la commande backend est cassee, mais il signale que les broadcasts/live refresh peuvent ne pas etre fiables pendant cette session.

Reference audit deja produit: `reports/audit/POS_CATALOG_CENTRAL_SYNC_ULTRA_AUDIT_2026-04-27.md`.

## 3. Parcours borne audite

### Prototype Claude Design

Browser: `file:///Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/borne%20(Remix)/Borne%20FoodKing.html`.

Constats DOM:

- Prototype autonome `Borne FoodKing — Prototype`.
- Navigation interne de prototype: `Idle`, `Menu`, `Wizard`, `Panier`, `Upsell`, `Paiement`, `TPE`, `Espèces`, `Waiting`, `Confirm`, `Erreur`.
- Idle prototype: branding FoodKing plein ecran, boutons langue FR/EN/AR, statut TPE/IMPRIMANTE/QR.

Evaluation:

- Bon support visuel/design pour continuer le portage.
- Ce prototype n'est pas le runtime produit: il ne prouve pas la synchronisation stock/prix.

### Borne live

Browser: `http://127.0.0.1:8000/kiosk/idle`.

Constats DOM:

- La borne live affiche l'ecran de demarrage: theme toggle, `Bienvenue !`, choix `Sur place` / `À emporter`.
- Sous session admin, l'habillage backend est present autour de la borne: navbar, sidebar, profil admin.
- Clic `À emporter` n'a pas fait avancer le parcours pendant l'audit; l'ecran reste sur idle avec `Reconnexion en cours…`.

Finding P1: le composant global route les pages kiosk vers le theme backend si l'utilisateur est connecte.

Preuve code:

- `resources/js/router/modules/kioskRoutes.js` marque bien les routes avec `meta: { isKiosk: true }`.
- `resources/js/components/DefaultComponent.vue:112` applique seulement `isFrontend` et `isTable`, puis tombe sur `backend` par defaut.

Impact:

- En profil admin/developpement, l'ecran borne n'est pas visuellement isole.
- En borne physique avec session propre, ce bug peut etre masque si le token kiosk/login contourne la session admin, mais le code reste fragile.

Correction recommandee:

- Ajouter un theme `kiosk` dans `DefaultComponent` ou traiter `route.meta.isKiosk === true` avant `backend`.
- Rendre les routes kiosk hors chrome backend, quel que soit l'etat `authStatus`.
- Ajouter test JS sentinel: route `/kiosk/idle` + authStatus true ne rend pas `BackendNavbarComponent` / `BackendMenuComponent`.

## 4. Stock et synchronisation catalogue

Etat valide:

- `AvailabilityController` ecrit la disponibilite par `(item_id, branch_id)` et emet `ItemAvailabilityChanged`.
- `AvailabilityService::assertItemsOrderableForBranch()` bloque les commandes si produit inactif, indisponible globalement ou indisponible branche.
- `ItemService::simpleList()` applique le filtre `surface` puis l'overlay de disponibilite branche pour les listes POS.
- La borne `kioskMenu` accepte les updates `ItemAvailabilityChanged` et invalide le cache sur `type='full'`.

Tests executes:

```bash
php artisan test --filter='PricingServiceMultiQtyTest|MultiVariationValidationTest|ItemRequestTest|ItemCategoryRequestTest|ItemCategoryHierarchyTest|ItemExtraManagementTest|FrontendSurfaceFilteringTest|CatalogStockCentralSyncEndToEndTest|OrderRejectsUnavailableBranchItemTest'
```

Resultat: `36 passed`, `7 skipped`. Les skips sont locaux SQLite/MySQL-only connus.

```bash
npx vitest run tests/js/posVariationMultiQty.spec.js tests/js/posKioskVariationParity.spec.js tests/js/KioskWizard.spec.js tests/js/kioskWizardNavigation.spec.js tests/js/kioskDrinkAddons.spec.js tests/js/KioskCategoriesRestyle.spec.js
```

Resultat: `130 passed`.

Warnings non bloquants:

- `KioskCategoriesRestyle.spec.js`: `unknown action type: kioskFilter/init` dans le mock de test.
- `KioskWizard.spec.js`: `pricing preview failed: axios unavailable` dans certains tests unitaires, fallback local attendu.

## 5. Donnees catalogue observees

Lecture DB read-only:

```json
{
  "categories": {
    "tacos": 1,
    "sandwich": 1,
    "burger": 1,
    "assiette": 1,
    "simple": 7,
    "omelette": 1,
    "salade": 1,
    "snacking": 1
  },
  "menu_categories": 3,
  "attributes": [
    {"id":1,"name":"Viande 1","min_select":0,"max_select":1,"allow_repeat":false},
    {"id":2,"name":"Viande 2","min_select":0,"max_select":1,"allow_repeat":false},
    {"id":3,"name":"Viande 3","min_select":0,"max_select":1,"allow_repeat":false},
    {"id":4,"name":"Viande 4","min_select":0,"max_select":1,"allow_repeat":false},
    {"id":5,"name":"Sauce (1ère Gratuite)","min_select":0,"max_select":1,"allow_repeat":false},
    {"id":6,"name":"Type de Pain","min_select":0,"max_select":1,"allow_repeat":false}
  ],
  "items": 64,
  "active_items": 63,
  "variations": 772,
  "extras": 530,
  "extras_grouped": {"":530}
}
```

Interpretation:

- Le catalogue est riche: 64 produits, 772 variations, 530 extras.
- Les templates categorie existent.
- Aucun extra n'a encore de `group_label` renseigne, alors que les helpers kiosk utilisent ce champ pour eviter les heuristiques sur les noms.
- Les attributs ont tous `max_select=1` / `allow_repeat=false`; donc les cas "Tacos 4 viandes meme viande x4" ne peuvent pas etre pilotes depuis les donnees actuelles sans correction UI/data.

## 6. Configuration produit actuelle

### Ce qui existe

Article:

- `resources/js/components/admin/items/ItemCreateComponent.vue`
- Champs: nom, prix, categorie, taxe, image, type, mis en avant, suggestion caisse/borne, statut, attention, description.

Categorie:

- `resources/js/components/admin/settings/ItemCategory/ItemCategoryCreateComponent.vue`
- Champs wizard: `wizard_template`, `has_menu`, flags upsell.

Attribut:

- `resources/js/components/admin/settings/ItemAttribute/ItemAttributeCreateComponent.vue`
- Champs actuels UI: nom, statut.

Variation:

- `resources/js/components/admin/items/variation/ItemVariationCreateComponent.vue`
- Champs: nom, prix additionnel, attribut, statut, visible sur kiosk/pos/web.

Extra:

- `resources/js/components/admin/items/extra/ItemExtraCreateComponent.vue`
- Champs: nom, prix additionnel, statut, `group_label`, visible sur kiosk/pos/web.

Backend:

- DB contient `item_attributes.min_select`, `max_select`, `allow_repeat`.
- `PricingService` et `MultiVariationConstraint` valident ces contraintes.

### Ce qui manque

1. Le produit n'a pas un "wizard builder" central.
2. Les champs `min_select`, `max_select`, `allow_repeat` ne sont pas configurables depuis l'UI attribut.
3. `ItemAttributeRequest` ne valide pas ces champs.
4. `ItemAttributeResource` ne les expose pas aux wizards POS/Kiosk.
5. Le regroupement intelligent des extras depend de `group_label`, mais la base actuelle n'en renseigne aucun.
6. Les boissons du menu sont detectees par addons + heuristiques; il faut les lier explicitement au catalogue central des boissons pour la borne.

## 7. Reponse metier: comment doit fonctionner l'ajout produit ideal

Objectif utilisateur: ajouter/modifier un produit sans code.

Flux cible recommande:

1. Dashboard > Articles > Ajouter un produit.
2. Step "Base": nom, prix, categorie, photo, statut, surfaces visibles: caisse, borne, web.
3. Step "Template": choisir `Simple`, `Tacos`, `Sandwich`, `Burger`, `Assiette`, `Salade`, `Omelette`, `Snacking`.
4. Step "Questions du wizard":
   - Activer/desactiver pages: Viandes, Pain, Crudites, Sauces, Supplements, Menu/Frites/Boisson.
   - Regler texte client court et clair.
   - Regler min/max/repetition par page.
5. Step "Choix":
   - Viandes: variations gratuites + viandes supplement payantes.
   - Pain: variations simples.
   - Crudites: extras gratuits, selectionnes par defaut, clic = retirer.
   - Sauces: variations catalogue, premiere gratuite ou quota configurable, surplus payant.
   - Supplements: extras payants, repetition autorisee avec quantite.
   - Menu: options `Menu complet`, `Frites seules`, `Boisson seule`, `Sans menu`.
   - Boisson: produits/addons de categorie boisson, filtres par stock/surface/branche.
6. Step "Preview": previsualisation POS + borne avec le meme payload.
7. Step "Publier": invalide cache kiosk, refresh POS, et laisse le backend recalculer les prix.

Regle non negociable:

- Le wizard frontend peut afficher un total indicatif, mais le prix final reste `PricingService` + quote backend.

## 8. Plan d'implementation recommande

### Mission A — KIOSK-LAYOUT-ISOLATION

But: supprimer l'habillage backend des routes `/kiosk/*`.

Allowlist:

- `resources/js/components/DefaultComponent.vue`
- `tests/js/DefaultComponentKioskLayout.spec.js` nouveau

Actions:

- `applyThemeFromRoute()` traite `route.meta.isKiosk === true` avant `isFrontend` / `isTable`.
- Ajouter rendu sans `BackendNavbarComponent` / `BackendMenuComponent`.
- Test: authStatus true + route kiosk => pas de chrome admin.

Critere:

- `/kiosk/idle` est full-screen borne meme en session admin.

### Mission B — ITEM-ATTRIBUTE-CONSTRAINTS-ADMIN

But: exposer les contraintes deja presentes en DB et moteur.

Allowlist:

- `resources/js/components/admin/settings/ItemAttribute/ItemAttributeCreateComponent.vue`
- `resources/js/components/admin/settings/ItemAttribute/ItemAttributeListComponent.vue`
- `resources/js/store/modules/itemAttribute.js`
- `app/Http/Requests/ItemAttributeRequest.php`
- `app/Http/Resources/ItemAttributeResource.php`
- tests request/resource + JS admin

Actions:

- Ajouter champs UI: minimum, maximum, repetition autorisee.
- Valider `min_select >= 0`, `max_select >= min_select`, `allow_repeat boolean`.
- Exposer les champs dans `ItemAttributeResource`.

Critere:

- POS/Kiosk recoivent les contraintes catalogue; plus besoin de mocks front.

### Mission C — PRODUCT-WIZARD-BUILDER-V1

But: ecran proprietaire unique pour configurer un produit compose.

Allowlist initiale:

- `resources/js/components/admin/items/ItemShowComponent.vue`
- nouveau `resources/js/components/admin/items/wizard/ProductWizardBuilderComponent.vue`
- stores `item`, `itemAttribute`, `itemVariation`, `itemExtra`
- endpoints existants uniquement si possible
- tests JS builder

Actions:

- Ajouter un onglet "Wizard" dans la fiche produit.
- Montrer les pages actives derivees categorie + variations/extras.
- Permettre creation rapide de variation/extra depuis le builder.
- Preview POS/Kiosk read-only.

Critere:

- Un restaurateur peut configurer un sandwich complet sans ouvrir 4 menus differents.

### Mission D — EXTRAS-GROUP-LABEL-MIGRATION-DATA

But: remplacer les heuristiques par des groupes explicites.

Allowlist:

- seeder/data repair dedie
- tests classification helpers
- rapport data migration

Groupes recommandes:

- `garniture`
- `supplement`
- `sauce`
- `viande`
- `frites_upgrade`
- `boisson`

Critere:

- `extras_grouped` ne doit plus etre `{"": 530}`.
- Les helpers kiosk partitionnent par `group_label` en priorite.

### Mission E — DRINK-CATALOG-CENTRAL-LINK

But: la boisson du menu vient du catalogue central, pas d'un libelle generique.

Allowlist:

- `resources/js/helpers/kioskDrinkAddons.js`
- `resources/js/components/frontend/kiosk/steps/KioskStepMenuComponent.vue`
- eventuels champs metadata addon/item si necessaire
- tests `kioskDrinkAddons`, `KioskWizard`, `OrderRejectsUnavailableBranchItemTest`

Actions:

- Les boissons proposees sont des produits actifs, visibles kiosk, disponibles branche.
- Le bouton "Boisson seule" est une option de formule, puis la liste boisson affiche les vrais produits disponibles.

Critere:

- Si une boisson est en rupture centrale/branche, elle disparait ou devient indisponible sur borne et caisse.

### Mission F — POS-KIOSK-CATALOG-LIVE-REFRESH

But: central update => POS/borne voient creation/suppression/categories sans F5.

Allowlist:

- events/listeners catalogue
- `resources/js/components/admin/pos/PosComponent.vue`
- `resources/js/store/modules/kioskMenu.js`
- tests event + JS handlers

Actions:

- Standardiser un signal `CatalogChanged` ou reutiliser `ItemAvailabilityChanged(type='full')`.
- Debounce refresh categories/items.

Critere:

- Ajouter/supprimer un produit ou categorie central declenche refresh POS + invalidation borne.

## 9. Priorite release

Ordre conseille:

1. `KIOSK-LAYOUT-ISOLATION` — rapide, visible, corrige le chrome admin sur borne.
2. `ITEM-ATTRIBUTE-CONSTRAINTS-ADMIN` — debloque la vraie configuration des viandes/sauces.
3. `EXTRAS-GROUP-LABEL-MIGRATION-DATA` — rend les wizards robustes et moins dependants des noms.
4. `DRINK-CATALOG-CENTRAL-LINK` — corrige la logique boisson menu.
5. `PRODUCT-WIZARD-BUILDER-V1` — ergonomie proprietaire, plus large.
6. `POS-KIOSK-CATALOG-LIVE-REFRESH` — synchronisation live complete create/delete/category.

## 10. Auto-audit

Invariants verifies:

- Prix: backend SSOT conserve; aucune recommandation ne deplace le calcul autoritaire vers le frontend.
- Branch isolation: stock lu/ecrit par branche; aucune recommandation n'elargit `branch_id`.
- POS wizard: audit uniquement; aucune modification proposee au fonctionnement POS actuel sans mission separee.
- Kiosk wizard: les composants actifs ont deja la logique click-card puis plus/minus pour viandes/supplements; l'effort restant est surtout data/config/admin.
- Stock: item-level branch stock valide; option-level stock reste enhancement metier.

Risques residuels:

- D-M13 queue number reste hors scope et gate humain.
- Screenshots browser du prototype ont timeout CDP; l'audit s'appuie sur DOM snapshot + code + tests.
- La session browser etait authentifiee admin, ce qui a revele le bug de chrome backend sur kiosk, mais il faut retester sur profil borne physique.

`PRODUCT_WIZARD_CATALOG_CONFIGURATION_VERDICT: PASS_WITH_RELEASE_REWORK_REQUIRED`
