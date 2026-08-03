# TRAIN 2 - Dashboard Product Composer

TASK_ID: PRODUCT-COMPOSER-SYNC-02-DASHBOARD-COMPOSER  
MODE: execute after Train 1  
GOAL: fournir une interface centrale utilisable en reel pour composer/modifier produits.

## 1. UX cible

Sur `admin/items/{id}` ajouter un onglet `Composition`:

- resume produit: photo, nom, categorie, prix source DB, disponibilite.
- preset: simple/tacos/sandwich/burger/assiette/menu/custom.
- liste des etapes actives dans l'ordre.
- pour chaque etape: libelle, source, min/max, repetition, surfaces POS/kiosk/web.
- choix associes: variation, extra group, addon, boisson catalogue.
- actions rapides: creer variation, creer extra, lier addon, preview POS, preview borne.

## 2. Regles UX

- Interface dense, operationnelle, pas landing page.
- Pas de carte dans carte.
- Photos produit visibles et modifiables.
- Les boutons utilisent les icons existantes/lab; lucide seulement si deja disponible.
- Pas de texte pedagogique long dans l'app.
- Les erreurs API sont visibles pres des champs.

## 3. Backend attendu

Endpoints recommandes:

- `GET /api/admin/item/{item}/composer`
- `PUT /api/admin/item/{item}/composer`
- `POST /api/admin/item/{item}/composer/step`
- `PATCH /api/admin/item/{item}/composer/step/{step}`
- `DELETE /api/admin/item/{item}/composer/step/{step}`
- Reutiliser endpoints variations/extras/addons existants pour les choix.

## 4. Allowlist indicative

- `app/Http/Controllers/Admin/ProductComposerController.php`
- `app/Http/Requests/ProductComposerProfileRequest.php`
- `app/Http/Requests/ProductComposerStepRequest.php`
- `app/Http/Resources/ProductComposerResource.php`
- `app/Services/Catalog/ProductComposerService.php`
- `resources/js/components/admin/items/wizard/ProductComposerComponent.vue`
- `resources/js/components/admin/items/wizard/ProductComposerStepEditor.vue`
- `resources/js/components/admin/items/wizard/ProductComposerPreview.vue`
- `resources/js/store/modules/productComposer.js`
- `resources/js/components/admin/items/ItemShowComponent.vue`
- `routes/api.php`

## 5. Tests

- `ProductComposerAuthzSentinelTest`
- `ProductComposerCrudTest`
- `ProductComposerNoFrontendPriceMutationTest`
- `catalogProductComposerComponentSpec.js`
- `productComposerPreviewParitySpec.js`

## 6. Exit

Un manager peut modifier une assiette:

- activer crudites,
- activer sauces,
- desactiver pain,
- ajouter supplement payant,
- upload photo,
- sauvegarder,
- voir preview POS/kiosk.
