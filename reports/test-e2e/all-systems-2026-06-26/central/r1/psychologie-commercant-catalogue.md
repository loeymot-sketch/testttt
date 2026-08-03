# CENTRAL r1 — Lentille PSYCHOLOGIE COMMERÇANT — Sous-système « Catalogue + Composer + Stock » (Sub 5.b)

Date: 2026-06-26 · DB live `foodking_e2e` · serveur :8766 · READ-ONLY (0 écriture, 0 mutation).
Posture : le GÉRANT — « fais-je confiance aux chiffres ? un employé bas-privilège peut-il frauder/abuser/se tromper ? »

## VERDICT : 0 P0 · 0 P1 · 0 P2 · 2 P3 (informational, sans impact commerçant dans cette DB)

Tous les VECTEURS principaux assignés sont **CLOS** et prouvés. Le cœur Catalogue/Composer/Stock
inspire confiance au commerçant : prix négatifs impossibles, snapshot fige les commandes contre
les éditions de prix (NF525), RBAC mure le caissier hors du catalogue. Les 2 P3 sont des
durcissements latents (régex 6-décimales, statut sans borne enum) sans aucune occurrence réelle
ni impact fraude/fuite/chiffres.

---

## HOLDS VÉRIFIÉS (verified-clean — pourquoi le commerçant peut faire confiance)

### H1 — Prix NÉGATIF refusé aux 3 surfaces (variation / extra / item)
- `app/Rules/IniAmount.php:35-45` — items+extras `new IniAmount()` rejette `$value <= 0` ;
  variations `new IniAmount(true)` (`ItemVariationRequest.php:40`) rejette `$value < 0` (zéro autorisé,
  normal pour viande gratuite). `:55` la régex `/^\d{1,10}(\.\d{1,6})?$/` n'admet PAS de `-` → double-blocage.
- repro DB: `SELECT SUM(price<0) FROM item_variations WHERE deleted_at IS NULL` → **0** ;
  `item_extras` → **0** ; `items` actifs → **0**. Aucune injection de remise déguisée possible.
- lentille: commerçant — un employé ne peut PAS créer un « extra à -5€ » pour sous-facturer.

### H2 (NF525, cœur) — Le snapshot fige la commande en cours contre une édition de prix
- `app/Services/ItemService.php:306-423 (update)` mute bien `items.price` / `item_variations.price` LIVE,
  MAIS la commande stocke son prix dénormalisé : `order_items.price` + `order_items.composition_snapshot`
  (JSON, `captured_at`+`schema_version`) capturés à la création.
- Le Z **ne relit jamais le prix live** : `ZReportService.php:663 applyOrderToTotals` lit `$order->total`
  (colonne `orders.total decimal(19,6)`) ; `:710-714 taxBreakdownForOrders` somme `order_items.tax_amount`
  / `tax_rate` (dénormalisés par-commande). Zéro JOIN vers `items.price`.
- repro DB: `order_items` récents montrent `oi_price` stocké == `live_price` AUJOURD'HUI uniquement parce
  qu'aucune édition n'a eu lieu ; l'architecture les stocke séparément → une re-tarification admin
  n'altère PAS rétroactivement le total fiscal d'une commande historique/en-cours.
- evidence test: `Catalog/ItemUpdateInvalidatesKioskCacheSentinel` + suite Sub 5.b **15/15 OK** (61 assertions).
- lentille: commerçant — « si je change un prix à midi, mes tickets du matin restent justes ». ✓

### H3 — Supprimer un item avec historique : protégé (snapshot intact)
- `ItemService.php:435-442` — `OrderItem::where('item_id',$id)->count() > 0` ⇒ `Exception 409`
  (force-delete refusé) ; sinon soft-delete (`:467-471`) qui PRÉSERVE la ligne. Le `composition_snapshot`
  de la commande survit à la suppression du catalogue.
- evidence test: `Catalog/ItemDeletionWithOrderHistory` PASS (dans les 15/15).
- lentille: commerçant — l'historique de vente reste lisible même après retrait d'un produit du menu.

### H4 — Toggle ingrédient propage bien au cache kiosk/POS
- `IngredientAvailabilityService.php:23-71` — cascade **par nom** (32× « Jambon » répartis) +
  `IngredientAvailabilityChanged::dispatch(...)`. Listeners présents :
  `InvalidateMenuProjectionOnIngredientChange`, `InvalidateKioskMenuCacheOnItemAvailabilityChanged`,
  `InvalidateKioskMenuCacheOnCatalogChange`, `BumpMenuSnapshotOnItemAvailabilityChanged`.
- `ItemService.php:391-393` (event `ItemUpdated` afterCommit) + `:402-415` broadcast `ItemAvailabilityChanged`
  type `full` si prix/variations/extras changent → refetch kiosk immédiat (pas d'attente TTL 60s).
- lentille: commerçant — « rupture sauce » se voit instantanément sur la borne ET la caisse.

### H5 — Composer profile : MIN/MAX viandes valides + prix INTERDIT (SSOT NF525)
- `ComposerProfileRequest.php:27-28` `min_select`/`max_select` → `min:0` ; `:55-66` règle croisée
  `max < min` ⇒ erreur. `:20,36` `price => prohibited` sur le profil ET chaque step → le composer
  **ne peut pas injecter de tarif** (le prix reste 100 % `PricingService` côté backend).
- evidence test: `ComposerSchema` + `AddonRolePersistence` PASS (15/15).
- lentille: commerçant — un manager ne peut pas glisser un « supplément +X€ » via le composer hors SSOT.

### H6 (RBAC, cœur commerçant) — Le caissier (POS Operator) est muré hors du catalogue
- Mapping DB prouvé : `POS Operator` ne possède dans la famille catalogue/réglages QUE la permission `pos`
  (aucune de `items_create/edit/delete/show`, `ingredients_manage`, `catalog.compose`, `settings`).
  repro: `SELECT p.name … r.name='POS Operator' AND p.name LIKE 'items%'…` → **uniquement `pos`**.
- Garde route, par-verbe : `ItemController.php:31-35` (`items_create` store/import/duplicate,
  `items_edit` update/changeImage, `items_delete` destroy, `items_show` show) ;
  `ItemVariationController.php:23` + `ItemExtraController.php:22` (`items_edit` store/update/destroy) ;
  `routes/api.php:753` ingredients `permission:ingredients_manage` ; `:767` composer `permission:catalog.compose`.
  Tout le bloc vit dans le BIG admin group `:289` (`auth:sanctum` + `block_kiosk_token_admin`).
- repro LIVE (0 donnée créée) — endpoints de mutation non-authentifiés :
  `POST /api/admin/item` → **401** · `DELETE /api/admin/item/22` → **401** ·
  `POST /api/admin/item/variation/22` → **401** · `PUT /api/admin/ingredients/extra:12/availability` → **401** ·
  `POST /api/admin/stock/scan-rupture/run` → **401** · `POST /api/admin/composer/items/22/profile` → **401**.
- evidence test: `CentralManagementAuthzMatrix` PASS (15/15) — la matrice asserte le 403 du caissier authentifié.
- lentille: commerçant — « un employé en caisse ne peut ni changer un prix, ni un menu, ni une dispo, ni voir les réglages ». ✓

### H7 — Stock : pas de négatif/oversell dans la DB live
- repro DB: `SELECT SUM(on_hand<0), MIN(on_hand) FROM stock_levels` → **0 négatif**, min 982 (2 lignes seulement).
- `StockRuptureDashboardController.php:46-48` lecture gated `permission:items_show`, run gated `items_create`
  + `authorizeWritableBranchScope` + double garde prod `:223`.
- Toggle rupture : `ToggleVariationAvailabilityRequest`/`ToggleExtraAvailabilityRequest` exigent
  `exists:…,id` + `branch_id exists` + `reason ∈ MANUAL_UNAVAILABLE_REASONS` quand indispo.

---

## FINDINGS P3 (informational — aucun impact commerçant dans cette DB, listés pour traçabilité)

[P3] app/Rules/IniAmount.php:55 — Régex prix admet jusqu'à 6 décimales (`(\.\d{1,6})?`)
  repro: un prix `7.999999` passerait la validation variation/extra/item (théorique).
  evidence: DB live — `ROUND(price,2) <> price` → **0** item / 0 variation / 0 extra (aucune occurrence réelle) ;
  l'affichage FR money tronque/arrondit à 2 décimales en aval.
  lentille: technique (hygiène donnée). Exige `items_edit` (admin de confiance) ; non-fraude, non-fuite.
  reco: si jamais durci, `(\.\d{1,2})?` ; sinon laisser — 0 risque V1-LOCAL. NE PAS healer (hors-scope, cosmétique).

[P3] app/Http/Requests/ItemRequest.php:79 (+ItemVariationRequest:42, ItemExtraRequest:36) — `status` sans borne enum
  repro: `'status' => ['required','numeric','max:24']` — pas de `min` ni `Rule::in([5,10])` ; un POST
  `status=0`/`24` serait accepté (statut hors enum ACTIVE=5/INACTIVE=10).
  evidence: les requêtes catalogue lisent `status = Status::ACTIVE` partout → un statut hors-enum rend
  simplement l'item invisible (pas un mauvais chiffre, pas une fuite). Exige `items_edit`.
  lentille: technique (défense en profondeur). Pattern répété dans tout le codebase (`max:24`).
  reco: optionnel `Rule::in([Status::ACTIVE, Status::INACTIVE])`. Faible priorité, pas un bug commerçant.

---

## CE QUI A ÉTÉ ABUSÉ ET A TENU (anti-faux-positif)
- Prix négatif extra/variation → **bloqué** (rule + régex + 0 en DB).
- Édition prix pendant commande → **n'altère pas le fiscal** (total dénormalisé, Z ne relit pas le live).
- Caissier tente catalogue/composer/ingredient/settings → **muré** (perm `pos` seule + routes gated + 401 live).
- Composer injecte un prix → **prohibited**.
- Force-delete item vendu → **409**.
- Overflow prix (10 chiffres) → tient dans `decimal(19,6)` ; auto-typo admin, visible au catalogue, non-fraude.

Tests verts à la clôture : Sub 5.b sentinels **15/15 (61 assertions)**.
Frozen touché : **aucun** (audit read-only).
