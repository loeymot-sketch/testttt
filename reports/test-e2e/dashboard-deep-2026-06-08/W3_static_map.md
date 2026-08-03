# Wave 3 — Static Code-Map (Catalogue + Stock) — LIVE branch
~50 controls across 6 surfaces, every row grep/Read-confirmed.

## Inventory correction
- ITEMS list (`ItemListComponent.vue`) = "control-plane" of router-links to sibling pages, NOT 4 in-page tabs. The 4-tab structure lives in **Catalog Studio**. (UI evolution, not a defect.)

## Surfaces → endpoints (all OK / RBAC-gated)
1. ITEMS: Ajouter→`item.store`(items_create), Filtrer(client), Exporter→`/item/export`(items), Sample→download-sample, Importer→`/item/import/file`(items_create, local Excel no-external), row edit/duplicate/delete(items_edit/create/delete), pagination. Nav→Produits/Catégories/Offres/Disponibilités.
2. CATALOG STUDIO/Composer: category CRUD(settings), product CRUD, composer load/save-draft/steps CRUD/apply-template/available-sources(catalog.compose), **publish=catalog.publish (stricter)**/unpublish/diff. Variations/Extras/Addons CRUD (items_edit). 
3. INGREDIENTS (`permission:ingredients_manage`): list/usage/show/availability-toggle — all OK.
4. ITEM-ATTRIBUTES (`permission:settings`): list/create/update/show/delete — all OK.
5. ITEM-CATEGORIES (`permission:settings`): list/create/update/show/delete/sort/export/sample/import/show-page — all OK (import=local Excel).
6. STOCK (`items_show` read / `items_create` run / `items_edit` toggle): catalog-overview, toggle item/extra/variation, bucket filter, scan-rupture/low-alerts/branch-avail endpoints exist.

## FINDINGS (all P3)
- [P3] Dead addon-update path — `resources/js/store/modules/itemAddon.js:60-61` PUTs `/admin/item/addon/{id}/{temp}` but NO route (api.php:747-749 = GET/POST/DELETE only) + no `ItemAddonController@update`. UNREACHABLE (list renders delete-only, `isEditing` never true). Latent if edit button added.
- [P3] Orphan `ItemPhotoUpload.vue` — posts `/admin/items/{item}/photo` (`ItemPhotoController@store`, gated items_edit + hasRole Admin|Tenant Admin); registered NOWHERE. Live photo path = `change-image` (items_edit only). API-layer gate inconsistency, moot via UI.
- [P3] Allergens NOT editable in admin catalogue — no UI, no endpoint (`grep allergen` empty in components/admin/items/). Read-only consumed in kiosk (KsAllergenBadge) + KDS (KdsOrderLine). **Confirms known un-healed Wave-2 item.**

Counts: P0=0 P1=0 P2=0 P3=3. Dead controls: 1 latent. External side-effects: 0. RBAC gaps: 0 (1 P3 inconsistency, moot).
