# POS Default Access Bootstrap Rework — 2026-05-01

## Verdict

AUDIT_VERDICT: PASS
RELEASE_DECISION: POS_MENU_RUNTIME_REWORK_CLOSED

Le retour Claude et le symptôme utilisateur étaient corrects : le POS pouvait rester blanc quand le bootstrap dépendait d'un `default_access.branch_id` absent ou nul.
Une deuxième racine a ensuite été reproduite dans l'onglet réel Codex : après utilisation de la borne, le navigateur conservait à la fois `kioskCart.kioskToken` et `auth.authToken`; l'intercepteur Axios global choisissait le token borne en priorité, même sur `/admin/pos`, ce qui empoisonnait les appels admin/POS.

Les correctifs backend et frontend sont appliqués, puis vérifiés via PHPUnit, Vitest, build Mix et navigateur réel sur l'onglet utilisateur.

## Root Cause

- `DefaultAccessService::show()` pouvait retourner un tableau vide pour un utilisateur sans ligne `default_access`.
- `DefaultAccessResource` lisait `branch_id` sans fallback, exposant `branch_id: null`.
- `PosComponent` chargeait les items trop fortement couplé au succès de `defaultAccess/show`.
- Les routes catalogue POS lisaient le menu runtime via une permission historiquement orientée gestion catalogue (`items_show`) alors que le caissier possède `pos`.
- Le POS-only devait forcer son scope branche serveur même si le frontend envoyait un `branch_id` absent ou falsifié.
- `resources/js/shared/axios-setup.js` choisissait `kioskToken || userToken` pour toutes les surfaces. Dans un navigateur ayant servi la borne puis le POS, `/admin/pos` envoyait donc parfois le Bearer token borne au lieu du token staff.
- `resources/js/bootstrap.js` reprenait la même priorité implicite pour Echo/auth realtime, avec le même risque de mauvais token hors surface kiosk.

## Corrections

- `app/Services/DefaultAccessService.php`
  - Fallback branche pour utilisateur authentifié : branche utilisateur si `branch_id > 0`, sinon branche site par défaut.
- `app/Http/Resources/DefaultAccessResource.php`
  - `branch_id` null-safe, plus d'undefined key.
- `database/seeders/UserTableSeeder.php`
  - Seed idempotent de `default_access.branch_id = 1` pour `pos@lecayenne.fr`.
- `app/Http/Controllers/Admin/ItemController.php`
  - Runtime reads `index`, `itemDetails`, `lookupBarcode` autorisés par `items_show` OU `pos`.
  - POS-only force toujours `branch_id` depuis le user serveur et ignore les valeurs client.
  - `itemDetails` rejette les items/categories non visibles sur `surface=pos`.
- `app/Http/Controllers/Admin/PosCategoryController.php`
  - Lecture catégories POS autorisée par `items_show` OU `pos`.
- `resources/js/components/admin/pos/PosComponent.vue`
  - `itemList()` lancé au bootstrap avec branche auth si disponible, sans attendre `defaultAccess/show`.
  - `defaultAccess/show` ne peut plus bloquer le catalogue ; fallback auth branch en cas d'erreur.
  - Cart POS scope synchronisé avec branche + user.
  - Etat vide explicite si aucune catégorie POS n'est disponible.
- `resources/js/components/admin/pos/ItemComponent.vue`
  - Les tuiles produit POS sont maintenant des `<button type="button">` natifs au lieu de `div role="button"`.
  - Ajout de `data-pos-item-id` pour un ciblage stable des tests et du fallback.
  - Suppression du `data-modal` legacy sur la tuile produit : le modal est ouvert uniquement par `variationModalShow()`.
  - Ajout d'un fallback click natif en capture document pour ouvrir l'item exact même si un script legacy intercepte la propagation Vue.
- `resources/js/shared/axios-setup.js`
  - Ajout de `selectSurfaceBearerToken()`.
  - Sur `/kiosk/*`, priorité au token borne.
  - Sur `/admin/*` et toutes les autres surfaces staff, priorité au token utilisateur.
- `resources/js/bootstrap.js`
  - Echo auth utilise la même sélection de token surface-aware que Axios.
- `tests/Feature/Pos/PosMenuRuntimeAccessTest.php`
  - Ajout de la preuve caissier sans ligne `default_access`.
  - Ajout de la preuve anti-forge `branch_id` : le POS utilise la branche du caissier.
- `tests/js/PosComponent.spec.js`
  - Couverture fallback `defaultAccess` et application du scope POS.
- `tests/js/axiosSurfaceTokenSelection.spec.js`
  - Couverture du cas exact : token borne + token staff persistés, page `/admin/pos` doit envoyer le token staff.
  - Couverture inverse : page `/kiosk/*` doit conserver la priorité token borne.
- `tests/js/posComponentA11y.spec.js`, `tests/js/posAvailabilityLiveGuard.spec.js`, `tests/js/posRuptureUx.spec.js`
  - Adaptés au contrat bouton natif + `data-pos-item-id` + fallback natif.
- `tests/e2e/pos/tacos-4-viandes-cash-flow.spec.ts`
  - Sélecteur catalogue mis à jour de `[data-modal="#item-variation-modal"]` vers `[data-pos-item-id]`.

## Validation

- `php artisan test tests/Feature/Pos/PosMenuRuntimeAccessTest.php` — PASS, 6 tests.
- `php artisan test tests/Feature/Menu/CatalogStockCentralSyncEndToEndTest.php` — PASS.
- `php artisan test tests/Feature/Menu/AdminItemBranchAvailabilityProjectionTest.php` — PASS.
- `php artisan test tests/Feature/PosUITest.php` — PASS, 3 tests.
- `npx vitest run tests/js/authLogoutInterceptor.spec.js tests/js/staffOnlyLandingRedirect.spec.js tests/js/PosComponent.spec.js tests/js/posAvailabilityLiveGuard.spec.js tests/js/posWizardComposerProfile.spec.js tests/js/posRuptureUx.spec.js` — PASS, 20 tests.
- `node tools/lint/pos_pricing_guard.mjs && node tools/lint/pos_orderstatus_guard.mjs` — PASS.
- `php -l` sur les fichiers PHP modifies — PASS.
- `php artisan permission:cache-reset && php artisan cache:clear && php artisan optimize:clear` — PASS.
- `npm run development` — PASS, assets reconstruits.
- `npx vitest run tests/js/axiosSurfaceTokenSelection.spec.js tests/js/authLogoutInterceptor.spec.js tests/js/PosComponent.spec.js tests/js/staffOnlyLandingRedirect.spec.js` — PASS, 9 tests.
- Rebuild final `npm run development` après correction token surface-aware — PASS.
- `npx vitest run tests/js/posAvailabilityLiveGuard.spec.js tests/js/posComponentA11y.spec.js tests/js/posRuptureUx.spec.js` — PASS, 14 tests.
- Playwright Chromium headless, compte `pos@lecayenne.fr` :
  - `/admin/pos` charge le catalogue.
  - Clic `[data-pos-item-id]` sur `Tacos M`.
  - Reponse `/api/admin/item/details/4?surface=pos&branch_id=1`: 200.
  - `#item-variation-modal.active = true`.
  - Bouton `Ajouter au panier` visible.
  - `pageerror`: 0.

## Browser Proof

Compte `pos@lecayenne.fr` :

- Login API: 201.
- `/api/admin/default-access`: 200, `branch_id=1`.
- `/api/admin/pos-category?...surface=pos`: 200.
- `/api/admin/item?...surface=pos&branch_id=1`: 200.
- Landing POS : 13 categories visibles, 5 best sellers visibles.
- Clic `Nos Tacos` : reponse `/api/admin/item?...item_category_id=1...`: 200.
- Liste filtree : `Tacos M`, `Tacos L`, `Tacos XL`, `Tacos XXL` uniquement.
- Clic `Tacos M`: `/api/admin/item/details/4?surface=pos&branch_id=1`: 200, popup ouverte.
- Onglet réel Codex après reproduction du blanc utilisateur :
  - URL `http://127.0.0.1:8000/admin/pos`.
  - Apres reload avec assets reconstruits : `hasEmptyState=false`, `categoryButtons=13`, `addButtons=6`.
  - Clic catégorie `Nos Sandwichs` : liste filtrée visible avec 8 produits + bouton client, pas d'état vide.
  - Le plugin navigateur intégré Codex a produit un faux négatif sur les clics de tuile produit après rebuild ; la validation Playwright Chromium réelle confirme le runtime applicatif : détail item 200, modal actif, bouton panier visible.

Compte `admin@lecayenne.fr` :

- Meme preuve : categories visibles, filtre `Nos Tacos` exact, popup produit ouverte.

## Residual Notes

- Les erreurs WebSocket `127.0.0.1:6001 refused` restent attendues en local si le serveur WS n'est pas lance ; elles ne bloquent pas le fallback polling ni le catalogue POS.
- Le warning du guard pricing indique `signoff-pending until 2026-05-10`, deja connu ; le scan reste OK.
- Si le navigateur manuel reste blanc apres ce patch, les causes probables sont maintenant uniquement environnementales : asset/cache navigateur ancien, session non rechargee, ou serveur PHP pointant vers un autre worktree. Faire hard refresh, logout/login, puis verifier que `public/mix-manifest.json` correspond au build courant.
