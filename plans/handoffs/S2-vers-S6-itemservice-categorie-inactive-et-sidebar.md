# Handoff S2 → S6 (intégrateur / CENTRAL) — 2 demandes hors voie S2 (2026-07-29)

Contexte : GOAL S2 V1, findings RED confirmés (rapport `reports/goal-s2-caisse-stock/`,
dispute F3/G3). Les fixes sont HORS de l'ownership S2 → demande de prise en charge.

## 1. `app/Services/ItemService.php` — items de catégorie INACTIVE fuient dans la grille POS
- Preuve : items techniques 1-3 (« Menu (Frites + Boisson) », « Frites Seules », « Boisson
  Seule »), catégorie 27 « Technique (interne — upsell) » **status=10 INACTIVE**, items
  status=5 ACTIVE → sortent en tête de la vue « Toutes les catégories »/recherche du POS
  (tri id asc). `ItemService::list` (~`:58-79`) filtre `status` de l'ITEM mais pas celui
  de sa catégorie.
- Diff proposé (scope-minimal, conditionné surface pour ne PAS cacher ces items de
  l'admin catalogue) : dans `ItemService::list`, si `$request->get('surface') === 'pos'`
  (et éventuellement `'kiosk'` — à valider avec S1), ajouter
  `->whereHas('category', fn($q) => $q->where('status', Status::ACTIVE))`.
- Non fait par S2 : ItemService = backend catalogue CENTRAL (SYSTEM_MAP §5), utilisé
  aussi par borne/admin — coordination requise.
- Note liée : S2 a déjà francisé les descriptions « Upsell item » → « Complément de
  commande » (DB + `MenuSeeder.php:505`) ; et REJETÉ le passage du tri POS à
  `order_column:'order'` (colonne non curée : Tacos M=0, Cheddar=0, techniques=1 —
  l'ordre deviendrait pire). Si vous curez `items.order` un jour, re-proposer.

## 2. `BackendMenuComponent.vue` — `V1_PRIMARY_SIDEBAR_MENUS` court-circuite `V1_HIDDEN_MENU_MODULES`
- Preuve (dispute G3) : `buildMergedSidebarMenus()` (`:325-327`) pousse les entrées
  hardcodées sans consulter `hiddenMenuUrls` (filtre appliqué seulement aux menus DB,
  `:201`, `:332`). Résultat : `delivery-boy-cash-sessions` (`:122`) visible pour TOUS
  alors que le module `deliveryBoys` est masqué (`v1-hidden-modules.js:20`) ; en plus
  `permissionUrlForSidebarPath` ne mappe pas cette URL → lien affiché même sans
  permission `delivery-boys` (éjection au clic).
- Diff proposé : (a) supprimer la ligne `:122` (doctrine V1 : accessible par URL directe) ;
  (b) défaut de fond : faire passer `V1_PRIMARY_SIDEBAR_MENUS` par le même filtre
  `hiddenMenuUrls` + mapping permission (`MENU_URL_TO_PERMISSION_URL`).
- Non fait par S2 : `layouts/backend/BackendMenuComponent.vue` = voie CENTRAL.

## 3. En-tête admin — `<img alt="flag">` SANS attribut `src` (P3, toutes les pages)
- Fait mesuré (sonde DOM sur `/admin/pos`, `/admin/historique`, `/admin/stock/rupture`) :
  `<img alt="flag" class="w-4 h-4 rounded-full">` — **aucun `src`** → image cassée rendue
  comme une pastille vide de 16 px dans le sélecteur de langue de l'en-tête, sur **toutes**
  les pages admin. Seule image cassée subsistant après la vague V6 de S2.
- Diff proposé : soit alimenter le `src` avec le drapeau de la locale active, soit rendre
  l'`<img>` conditionnel (`v-if`) et retomber sur le libellé texte — le nom de la langue est
  déjà affiché à côté.
- Non fait par S2 : en-tête partagé, voie CENTRAL.
