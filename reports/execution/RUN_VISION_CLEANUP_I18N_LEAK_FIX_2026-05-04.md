# RUN — Vision Cleanup I18n Leak Fix — 2026-05-04

## Sommaire
Ajout des 15 clés racine `studio.*` manquantes dans les 5 langues et renforcement du sentinel anti-fuite i18n Studio.

## 15 clés ajoutées par langue
- FR: `eyebrow`, `title`, `subtitle`, `all_categories`, `products_count`, `advanced_settings`, `stock_link`, `quick_create_product`, `stock_parallel_title`, `daily_quota_hint`, `stock_parallel_hint`, `composer_drawer_eyebrow`, `composer_drawer_title`, `open_full_page`, `select_category_first`.
- EN: mêmes 15 clés traduites.
- DE: mêmes 15 clés traduites.
- BN: mêmes 15 clés traduites.
- AR: mêmes 15 clés traduites.

Total: 75 traductions ajoutées sous `studio`, sans écraser les sous-namespaces existants `composer` et `image`.

## Sentinel renforcé
`tests/js/studioFrontendI18nParity.spec.js` scanne désormais les usages `$t/$tc('studio.*')` dans:
- `resources/js/components/admin/items/CatalogStudioComponent.vue`
- `resources/js/components/admin/items/ItemPhotoUpload.vue`
- `resources/js/components/admin/items/composer/ComposerPublishDiffModal.vue`

Extrait:
```js
const regex = /\$(?:t|tc)\(\s*['"]studio\.([^'"]+)['"]/g;
expect(missing, `Missing studio.* keys in fr.json: ${missing.join(', ')}`).toEqual([]);
```

## Vérification fuite borne/caisse
- `resources/js/components/frontend/kiosk/`: aucun match `studio.`.
- `resources/js/components/admin/pos/`: aucun match `studio.`.

Aucune fuite directe détectée dans les composants kiosk/POS. La fuite probablement vue par l'utilisateur était un rendu pré-i18n du Studio admin avant traduction. Avec les 15 clés ajoutées, le rendu Studio doit afficher les labels traduits.

## Tests
- `npx vitest run tests/js/studioFrontendI18nParity.spec.js`: PASS, 1 fichier, 8 tests.
- `npx vitest run`: PASS, 172 fichiers, 1095 tests passed, 2 skipped.
- `.cursor/hooks/post-execute.sh`: PASS, `php artisan test --stop-on-failure` passed; lint skipped (no script); Playwright skipped (no declared strategy for Lot Q).

## Statut
PASS.

