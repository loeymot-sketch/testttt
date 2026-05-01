# Product Wizard Composer Delivery Audit — 2026-04-27

AUDIT_TASK: verify last product composer implementation
AUDIT_VERDICT: PASS_FOR_FOUNDATION__NOT_COMPLETE_FOR_FULL_SHOPIFY_COMPOSER
AUDITOR: codex-extension

## 1. Reponse directe

La derniere demande n'est pas totalement terminee si on la lit comme :

> creer un configurateur complet de produits type Shopify, capable de definir les etapes du wizard par produit/categorie, leurs choix, les prix de supplement, le stock, puis de les voir automatiquement dans le wizard caisse et borne.

Cette vision complete n'est pas encore livree.

Ce qui est bien livre et verifie : la fondation critique qui rend configurables les contraintes de composition d'un attribut wizard :

- `min_select`
- `max_select`
- `allow_repeat`

Ces contraintes sont maintenant :

- validees cote backend,
- exposees dans l'API admin,
- visibles/editables dans le back-office attributs,
- propagees dans les payloads catalogue POS,
- propagees dans les payloads catalogue kiosk,
- testees contre les regressions de prix et parite POS/Kiosk.

## 2. Impact reel du diff

| Zone | Fichiers | Impact |
| --- | --- | --- |
| Validation API | `app/Http/Requests/ItemAttributeRequest.php` | Accepte et valide les contraintes composer. |
| Ressource attribut | `app/Http/Resources/ItemAttributeResource.php` | Retourne les contraintes au back-office. |
| Ressource admin/POS | `app/Http/Resources/ItemResource.php` | Conserve les contraintes quand les `itemAttributes` sont reconstruits depuis les variations. |
| Ressource kiosk | `app/Http/Resources/NormalItemResource.php` | Meme propagation pour la borne. |
| UI admin attributs | `resources/js/components/admin/settings/ItemAttribute/*` | Ajout des champs minimum, maximum, repetition. |
| Tests | `tests/Feature/Requests/ItemAttributeRequestTest.php`, `tests/Feature/ItemAttributeComposerResourceTest.php` | Sentinelles ajoutees. |
| Build assets | `public/js/pos-app.js`, `public/js/kiosk-shell.js`, `public/mix-manifest.json` | Generes par `npm run production`; changement attendu apres modification Vue. |

## 3. Audit "rien casse"

| Invariant | Resultat | Preuve |
| --- | --- | --- |
| Pricing SSOT backend | Respecte | Aucun calcul de prix ajoute cote Vue. Les tests `PricingServiceMultiQtyTest` passent. |
| Parite POS/Kiosk | Respecte | `PosKioskPricingParityTest` passe et les deux ressources exposent les memes contraintes. |
| Branch isolation | Non affecte | Aucun controller/order scope branche modifie. |
| Order lifecycle | Non affecte | Aucun service commande, statut, paiement, outbox, KDS touche. |
| Stock/catalog central | Non degrade | Aucun changement de stock; la demande complete stock->composer reste a faire. |
| D-M13 | Non touche | Aucune migration queue number ou unicite touchee. |
| Safety-check global | Bloque hors scope | `app/Services/OrderService.php` est deja staged en zone frozen; ce blocage preexistait et n'appartient pas a ce patch. |

## 4. Tests executes pendant cet audit

### Sentinelles composer

`php artisan test tests/Feature/Requests/ItemAttributeRequestTest.php`

Resultat : 2 PASS.

`php artisan test --filter='MultiVariationValidationTest|PricingServiceMultiQtyTest|PosKioskPricingParityTest|ItemAttribute|ItemRequestTest|FrontendSurfaceFilteringTest'`

Resultat : 33 PASS, 6 SKIPPED attendus. Les skips viennent de `FrontendSurfaceFilteringTest` qui depend de MySQL `JSON_CONTAINS` alors que l'environnement local courant est SQLite.

### Wizard / POS / Kiosk JS

`npx vitest run tests/js/posVariationMultiQty.spec.js tests/js/posKioskVariationParity.spec.js tests/js/KioskWizard.spec.js tests/js/kioskWizardNavigation.spec.js tests/js/kioskDrinkAddons.spec.js`

Resultat : 121 PASS.

### Build

`npm run production`

Resultat : PASS. Laravel Mix compile correctement.

### Hygiene

`git diff --check` sur les fichiers touches : PASS.

## 5. Ce qui fonctionne maintenant

Un attribut `Viande`, `Sauce`, `Crudites`, `Supplements`, etc. peut porter des regles :

- choix optionnel ou obligatoire,
- nombre maximum,
- repetition autorisee ou interdite.

Ces regles sont le socle necessaire pour que les wizards POS et borne comprennent :

- "1 viande obligatoire",
- "4 viandes maximum",
- "steak x2 autorise",
- "sauce optionnelle",
- "supplement repetable ou non".

La partie prix existe deja dans les mecanismes variations/extras et reste calculee cote backend. Les tests de prix multi-quantite et parite POS/Kiosk sont verts.

## 6. Ce qui manque encore pour le jackpot complet

Pour atteindre exactement la vision demandee, il faut une mission suivante, plus large :

### PRODUCT-COMPOSER-BUILDER-V1

Objectif : depuis l'admin, creer ou modifier un produit avec une composition complete, sans toucher au code.

Fonctions attendues :

1. Creer une categorie ou utiliser une categorie existante.
2. Choisir un preset : sandwich, tacos, assiette, menu, boisson, dessert, produit simple.
3. Definir les etapes du wizard du produit :
   - Pain
   - Viande
   - Sauces
   - Crudites
   - Supplements
   - Menu
   - Boisson
4. Pour chaque etape :
   - afficher ou non l'etape,
   - min/max,
   - repetition autorisee,
   - choix inclus ou payants,
   - prix supplement,
   - visible POS, kiosk, web.
5. Relier les choix a de vrais objets catalogue/stock quand necessaire, surtout boissons.
6. Tester un parcours complet :
   - admin cree une assiette custom,
   - POS voit le wizard correct,
   - kiosk voit le wizard correct,
   - rupture stock retire le choix,
   - prix backend identique POS/Kiosk,
   - commande valide jusqu'au recap.

## 7. Risque restant

Le risque principal n'est pas dans le patch livre. Il est dans l'etape suivante : si on construit le builder complet sans schema clair, on risque de dupliquer de la logique entre :

- `item_attributes`,
- `item_variations`,
- `item_extras`,
- `item_addons`,
- `item_categories.wizard_template`,
- les composants wizard POS/kiosk.

Decision recommandee : avant implementation large, faire un audit de modele de donnees de 1 a 2 heures et choisir si on reutilise uniquement les tables existantes ou si on ajoute une table de profil wizard explicite.

## 8. Verdict final

La livraison actuelle est propre pour une fondation. Elle n'a pas casse les chemins critiques testes.

Mais elle ne doit pas etre vendue comme "composer complet". Elle doit etre consideree comme Phase 1 du composer :

> Phase 1 : contraintes de choix administrables et propagees POS/Kiosk — PASS.
> Phase 2 : builder produit complet, prix/stock/etapes custom et E2E admin -> POS -> kiosk — A FAIRE.
