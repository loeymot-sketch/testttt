# RUN_UX_CLEANUP_NAV_2026-05-04 — Lot O navigation Catalog

**Sommaire** : « Attributs d'articles » retiré du menu Paramètres (reste sous Articles), route `admin.settings.itemCategory.show` redirige vers Catalog Studio avec `item_category_id`, tests Vitest verts.

## Tâche 1 — Attributs caché sous Paramètres

**`resources/js/config/v1-hidden-modules.js`** — ajout de la clé de menu :

```js
'settings.item-attributes',
```

**`resources/js/components/admin/settings/MenuComponent.vue`** — `v-if="!isSettingHidden('itemAttributes')"` sur le lien Attributs + entrée `'settings.item-attributes': 'itemAttributes'` dans `HIDDEN_KEY_TO_LOCAL_SETTING` (mapping identique aux autres `settings.*` → clé locale camelCase).

## Tâche 2 — ItemCategory.show → Catalog Studio

**`resources/js/router/modules/settingRoutes.js`** :

- Route `show/:id` avec `name: "admin.settings.itemCategory.show"` : `redirect: (to) => ({ name: "admin.items.studio", query: { item_category_id: to.params.id } })`, meta inchangée.
- Import lazy `ItemCategoryShowComponent` retiré (composant non branché au routeur ; fichier Vue conservé).

## Tests

| Cible | Résultat |
|-------|----------|
| `tests/js/v1HiddenMenuModules.spec.js` | 5 PASS |
| `tests/js/itemCategoryShowRedirect.spec.js` | 4 PASS (assertions sur le **fichier** source du router — import direct du module casse la résolution Vite des SFC lazys) |

**Vitest globale** : 170 fichiers, **1088 PASS**, 2 skipped, 0 échec.

## Suivi hors périmètre Lot O

`CatalogStudioComponent.vue` **n’initialise pas** `selectedCategoryId` depuis `$route.query.item_category_id` au montage ; la redirection transmet bien la query pour un rattachement UI ultérieur (allowlist Lot O uniquement).

## Statut

**PASS** — allowlist respectée ; 0 fichier hors périmètre modifié.
