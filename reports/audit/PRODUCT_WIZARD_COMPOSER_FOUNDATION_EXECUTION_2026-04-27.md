# Product Wizard Composer Foundation Execution — 2026-04-27

TASK_ID: PRODUCT-WIZARD-COMPOSER-FOUNDATION-2026-04-27
EXECUTE_DELEGATION: codex-extension
AUDIT_SCOPE: central product composition configuration, item attributes, POS/kiosk catalog resources
AUDIT_VERDICT: PASS_FOUNDATION_DELIVERED_WITH_NEXT_PHASE_REQUIRED

## 1. Objectif

Mettre une premiere fondation concrete au besoin "composer produit" : permettre au back-office de configurer les regles de choix d'une etape de wizard, puis garantir que ces regles sortent dans les payloads utilises par l'admin/POS et par la borne.

Le besoin final reste plus large : un configurateur type Shopify pour categories, produits, profils de composition, choix, stock et presets par type de produit. Cette tranche livre le socle le plus critique deja present en base mais non expose correctement : `min_select`, `max_select`, `allow_repeat`.

## 2. Audit avant implementation

| Couche | Etat avant | Risque |
| --- | --- | --- |
| DB / modele `item_attributes` | Les colonnes `min_select`, `max_select`, `allow_repeat` existaient deja et le modele les castait. | Socle inutilisable si le back-office ne peut pas les editer. |
| `ItemAttributeRequest` | La validation historique acceptait seulement `name` + `status`. | Les regles composer pouvaient etre ignorees ou rejetees par l'API. |
| `ItemAttributeResource` | La ressource ne renvoyait pas les contraintes. | L'UI admin ne pouvait pas relire proprement les valeurs. |
| `ItemResource` / `NormalItemResource` | Les listes `itemAttributes` etaient reconstruites manuellement depuis les variations avec seulement `id`, `name`, `status`. | POS/kiosk pouvaient recevoir des contraintes par defaut au lieu de la verite catalogue. |
| UI back-office attributs | Le formulaire ne montrait pas min/max/repeat. | Le restaurateur ne pouvait pas parametrer "1 viande", "4 viandes", "repeat autorise" sans intervention technique. |

Conclusion avant patch : la logique metier multi-selection existait deja partiellement, mais la configuration centrale n'etait pas assez exposable pour que le produit soit administrable.

## 3. Implementation livree

| Fichier | Changement |
| --- | --- |
| `app/Http/Requests/ItemAttributeRequest.php` | Validation ajoutee pour `min_select`, `max_select` avec `gte:min_select`, et `allow_repeat` booleen. |
| `app/Http/Resources/ItemAttributeResource.php` | Projection API des trois contraintes composer. |
| `app/Http/Resources/ItemResource.php` | Le payload admin/POS garde les contraintes quand il deduit `itemAttributes` depuis les variations. |
| `app/Http/Resources/NormalItemResource.php` | Le payload kiosk garde les memes contraintes, avec parite POS/kiosk. |
| `resources/js/components/admin/settings/ItemAttribute/ItemAttributeCreateComponent.vue` | Formulaire admin enrichi : minimum, maximum, repetition autorisee. |
| `resources/js/components/admin/settings/ItemAttribute/ItemAttributeListComponent.vue` | Liste admin enrichie : affichage des bornes de choix et de la repetition. |
| `tests/Feature/Requests/ItemAttributeRequestTest.php` | Sentinel validation : contraintes acceptes et max < min rejete. |
| `tests/Feature/ItemAttributeComposerResourceTest.php` | Sentinel ressource : contraintes visibles dans la ressource attribut et conservees dans les payloads POS/kiosk. |

## 4. Raisonnement produit

Dans le modele cible, un `ItemAttribute` represente une question/etape de composition :

- `Viande` : min 1, max 1/2/3/4 selon le produit, `allow_repeat` selon le cas.
- `Sauce` : min 0 ou 1, max N, repetition souvent interdite.
- `Crudites` : min 0, max N, repetition interdite.
- `Supplements` : min 0, max N, repetition potentiellement autorisee si le restaurant veut "fromage x2".

Cette tranche rend ces contraintes administrables et transmissibles. Elle ne cree pas encore le builder complet categorie -> produit -> profil wizard -> groupes de choix -> stock. C'est la prochaine tranche, car elle peut demander un schema dedie ou une reutilisation precise des structures existantes.

## 5. Invariants verifies

| Invariant FoodKing | Validation |
| --- | --- |
| Backend pricing SSOT | Aucun calcul de prix ajoute cote frontend. Les champs ajoutes ne sont que des contraintes de selection. |
| POS/kiosk parity | `ItemResource` et `NormalItemResource` exposent les memes contraintes. |
| Branch isolation | Aucun scope branche ou commande modifie. |
| Dispatch after commit | Aucun event/job touche. |
| D-M13 | Non touche. |
| Frozen OrderService | Non touche dans cette tranche. Le safety-check global reste bloque par un fichier staged preexistant hors scope. |

## 6. Validation executee

### PHP cible

`php artisan test tests/Feature/Requests/ItemAttributeRequestTest.php`

Resultat : 2 PASS.

`php artisan test tests/Feature/ItemAttributeComposerResourceTest.php`

Resultat : 2 PASS.

`php artisan test tests/Feature/ItemAttributeMultiSelectMigrationTest.php`

Resultat : 3 PASS.

### PHP regression metier courte

`php artisan test --filter='MultiVariationValidationTest|PricingServiceMultiQtyTest|ItemAttribute|ItemRequestTest|FrontendSurfaceFilteringTest'`

Resultat : 29 PASS, 6 SKIPPED attendus. Les skips viennent du contrat existant `FrontendSurfaceFilteringTest`, qui depend de MySQL `JSON_CONTAINS` alors que l'environnement courant utilise SQLite.

### JS wizard / POS

`npx vitest run tests/js/posVariationMultiQty.spec.js tests/js/posKioskVariationParity.spec.js tests/js/KioskWizard.spec.js tests/js/kioskWizardNavigation.spec.js`

Resultat : 118 PASS.

### Build frontend

`npm run production`

Resultat : PASS, Laravel Mix compile avec succes.

### Diff hygiene

`git diff --check` sur les fichiers touches : PASS.

## 7. Limites restantes

Le configurateur complet demande encore des missions separees :

1. Construire une matrice d'administration category/product -> wizard profile -> attributs -> variations/extras/addons.
2. Ajouter les presets metier : sandwich, tacos, assiette, menu, boisson, dessert, simple product.
3. Verifier si les champs existants suffisent ou si un schema `composition_profiles` / `wizard_steps` est necessaire.
4. Relier les boissons de menu a de vrais items stockes et indisponibles si rupture.
5. Ajouter une simulation E2E : admin cree une categorie + produit + choix, puis POS/kiosk commandent le produit avec les contraintes.
6. Revoir l'UX wizard pour utiliser partout le comportement demande : clic sur la carte = premier choix, bouton plus seulement apres selection pour repeter.

## 8. Verdict

Cette tranche est livree et testee comme fondation technique. Elle ne clot pas le besoin "composer complet", mais elle ferme un vrai trou : les contraintes de composition existent maintenant dans l'API, le back-office, les ressources POS/kiosk et les tests.
