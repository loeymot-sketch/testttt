# Journey D — GESTION catalogue (round 1) — 2026-07-15

Agent : onde D. Serveur live `http://127.0.0.1:8000`. Toutes les mutations préfixées `RJ-Dcat` (items 141/142, catégorie 58, coupon 24) — créées PUIS nettoyées (soft-delete) en fin de parcours. Aucune donnée partagée touchée.

## Parcours exécuté (preuves clés)

| Étape | Appel | Résultat |
|---|---|---|
| Login | token Sanctum forgé `e2e-Dcatalogue` (pas de /auth/login) | OK |
| Créer produit | `POST /api/admin/item` → id=141 « RJ-Dcat Burger Test » 8.50€ cat Burgers | 200 |
| Propagation création | `GET /api/frontend/item` (kiosk) + `GET /api/admin/item` (POS) | 141 présent 8.50 sur les 2 surfaces |
| Éditer prix | `PUT /api/admin/item/141` price 9.90 | 200 ; `GET /api/frontend/item/details/141` → 9.90 immédiat ✅ |
| Variations 2 attributs | `POST /api/admin/item/variation/141` ×2 (Viande 1 id=576 0€, Sauce id=577 0.50€) | 200 ; visibles kiosk details ✅ |
| Extra | `POST /api/admin/item/extra/141` Cheddar 1.00€ | 200 ; visible kiosk ✅ |
| Contrainte wizard | quote sans variations → 422 « Sélectionnez au moins 1 Viande 1 » ✅ |
| Intégrité montants | quote 2×(9.90+0.50+1.00) → subtotal **22.80** exact ✅ |
| Catégorie | `POST /api/admin/setting/item-category` → id=58 ; DELETE avec item actif dedans → **422 garde OK** ; MAIS voir F2 |
| Coupon | `POST /api/admin/coupon` RJDCAT10 10% cap 5€ min 5€ → coupon-checking (total 20, kiosk, branche 1) → discount 2.00 ; **checkout quote coupon_id=24 → discount 2.28 = 10% de 22.80, total_ttc 20.52** — annoncé = checkout ✅ (voir F4 sur coupon_code) |
| Rupture | `POST /api/admin/menu/availability/toggle` item 141 off branche 1 → **quote 422 « Article 141 indisponible… (RJ-Dcat rupture test) »** ✅ ; liste kiosk/POS `is_available:false` + `availability_reason` OK ✅ ; MAIS endpoint details → voir **F1** |
| Cleanup | toggle back 200, DELETE coupon 24 → 202, DELETE items 141/142 → 202 | fait |

## Findings (gravité décroissante)

### F1 — P1 — La rupture par branche n'est PAS reflétée par l'endpoint détails produit (détection mid-wizard borne aveugle)
- **Fichier** : `app/Http/Resources/NormalItemResource.php:80` (`$isAvailable = $this->is_available === null ? true : (bool) $this->is_available;` — flag GLOBAL `items.is_available`), commentaire l.76-77 : « is_available exposé pour détection mid-wizard ».
- Le toggle rupture (`AvailabilityController@toggle`, `app/Http/Controllers/Admin/AvailabilityController.php:45`) écrit `ItemBranchAvailability` (par branche) ; la LISTE (`SimpleItemResource.php:22`, `effective_is_available`) le voit, les DETAILS non — ni côté kiosk ni côté admin/POS (`GET /api/admin/item/details/141?branch_id=1` → `true` aussi).
- **Repro exécutée** (2 items, reproduit 2×) : toggle off item 142 branche 1 (`{"item_id":142,"branch_id":1,"is_available":false,"unavailable_reason":"RJ rupture frites"}` → 200) puis :
  - `GET /api/frontend/item?branch_id=1` → `(142, is_available=False, availability_reason='RJ rupture frites')` ✅
  - `GET /api/frontend/item/details/142?branch_id=1` → `is_available: True, unavailable_reason: None` ❌
  - quote kiosk du même item → 422 « indisponible pour cette branche » (le backend bloque bien).
- **Impact réel** : le wizard (qui consulte details/`is_available` pour la « détection mid-wizard ») ne voit jamais la rupture posée par le dashboard rupture → le client configure tout son produit puis se prend le refus au moment du quote/checkout.
- **Fix** : dans `ItemService::itemDetails` (app/Services/ItemService.php:662), charger la dispo effective par branche (même mécanique que la liste `effective_is_available`) et l'utiliser dans `NormalItemResource:80` + exposer `availability_reason`.

### F2 — P2 — La garde « suppression catégorie » est contournable en suivant son propre message d'erreur → item orphelin réactivable
- **Fichiers** : `app/Services/ItemCategoryService.php:176` (`$itemCategory->items()->whereNull('deleted_at')->count()`) + `app/Models/ItemCategory.php:147` (la relation `items()` filtre `status = ACTIVE`).
- Le message dit « … ou désactivez-les d'abord » ; or un item DÉSACTIVÉ (status=10) sort du count → la suppression passe et laisse l'item pointer sur une catégorie soft-deleted. L'item est ensuite RÉACTIVABLE sans garde (voir F3) → item actif avec `category_name:null` sur toutes les surfaces = invisible des grilles category-first (exactement le bug que ce même patch CAISSE-LOGIC-HEAL 2026-07-11 voulait fermer).
- **Repro exécutée** : cat 58 + item 141 dedans → `DELETE /api/admin/setting/item-category/58` → 422 garde OK ; PUT item 141 `status=10` → re-DELETE → **HTTP 202** ; tinker : `item 141 cat=58 status=10` / `cat 58 deleted_at=2026-07-15 15:00:36` ; PUT item 141 `status=5` (toujours cat 58) → **HTTP 200** ; `GET /api/admin/item` → `(141, category_name=None, category=None)` ; `GET /api/frontend/item` → `(141, category_name=None)`.
- **Fix** : à `ItemCategoryService.php:176`, compter TOUS les items non-supprimés indépendamment du status (`Item::where('item_category_id', $categoryId)->whereNull('deleted_at')->count()`) — ou re-parenter les items inactifs dans la même transaction — et aligner le message.

### F3 — P2 — L'update produit accepte une catégorie soft-deleted (aucune règle exists) ; id inexistant → 422 « erreur base de données » générique
- **Fichier** : `app/Http/Requests/ItemRequest.php:52` — `'item_category_id' => ['required','numeric','not_in:0']`, pas de `Rule::exists('item_categories','id')->whereNull('deleted_at')`.
- **Repro exécutée** : `PUT /api/admin/item/141` avec `item_category_id=58` (soft-deleted) → **HTTP 200**, réponse `"category":null, "wizard_template":"simple", "has_menu":false` (le comportement wizard de l'item change silencieusement) ; `item_category_id=999999` → 422 mais message générique `{"status":false,"message":"Une erreur de base de données s'est produite."}` (FK, pas de message de validation exploitable par le formulaire).
- **Fix** : ajouter `Rule::exists('item_categories','id')->whereNull('deleted_at')` sur `item_category_id` (store + update).

### F4 — P3 — Le quote/checkout ignore silencieusement `coupon_code` (contrat : uniquement `coupon_id`)
- **Fichier** : `app/Services/Order/OrderQuoteService.php:294,310,350` — seul `coupon_id` (int) est lu ; aucun 422 si un client envoie `coupon_code`.
- **Repro exécutée** : quote kiosk avec `"coupon_code":"RJDCAT10"` → 200 `discount: 0` (silencieux) ; même panier avec `"coupon_id":24` → 200 `discount: 2.28`. `coupon-checking` de son côté prend `code` (`CouponCheckRequest.php:28`). Le flux officiel (checking → id) est cohérent, mais toute intégration qui passe le code au checkout perd la remise SANS erreur — état d'erreur silencieux côté client.
- **Fix** : au choix — accepter `coupon_code` dans le quote (résolution id serveur), ou rejeter 422 un payload contenant `coupon_code` sans `coupon_id`.

## Vérifié sain (pas de finding)
- Propagation prix create/update → kiosk + POS instantanée (8.50 → 9.90).
- Intégrité numérique panier=quote : 22.80 exact, coupon annoncé (10%) = checkout (2.28, cap 5€ non atteint), TTC 20.52.
- min_select variations imposé au quote (422 explicite « Sélectionnez au moins 1 Viande 1 »).
- Garde catégorie avec items ACTIFS → 422 message clair ; garde sous-catégories présente (`ItemCategoryService.php:188`).
- Rupture branche : liste kiosk/POS + quote (422 avec raison) OK ; `availability_reason` bien exposé en liste (`SimpleItemResource`).
- Unicité variation scopée (item, attribut) conforme au fix F-VARIATION-ATTR-SCOPE.

## Notes opérationnelles
- Le token kiosk a été révoqué en cours de parcours (401 sur quote) — design « old tokens revoked à chaque relogin » + agents parallèles qui re-login la borne. Pas un défaut, mais à connaître pour les ondes suivantes.
