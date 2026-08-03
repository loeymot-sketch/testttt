# CENTRAL r1 — Lentille SÉCURITÉ / RBAC / SECRETS
## Sous-système : Catalogue + Composer + Stock (Sub 5.b + 5.c partiel)

DB live : `foodking_e2e` · Serveur :8766 · READ-ONLY (0 écriture, 0 mutation) ·
Token-proof remplacé par résolution de gate `$user->can()` en tinker (aucun
`personal_access_tokens` créé, aucun ordre placé). 0 fichier modifié.

---

## VERDICT GLOBAL : SOLIDE — 0 P0, 0 P1 sur cette lentille

Le partitionnement RBAC catalogue/composer/ingrédients/stock est **correctement
appliqué et prouvé**. Le snapshot NF525 est doublement immuable. Aucune fuite de
secret ni de coût via les surfaces catalogue. Les défauts connus du plan
(license_key/FCM read-gate, user-enumeration) **appartiennent au sous-système
Settings/Users (5.c) et NON à mes fichiers ancrés** — je ne les double pas ici.

Findings : **2× P3 hygiène** (artefacts RBAC inertes). Rien à healer en sécurité.

---

## CE QUI A ÉTÉ PROUVÉ (HOLDS — pas de finding)

### H1 — POS Operator ne peut RIEN écrire au catalogue/composer/stock (RBAC partition)
- **Preuve DB** : rôle id=7 « POS Operator » = exactement 7 permissions :
  `dashboard, kitchen-display-system, order-status-screen, pos,
  pos-discount-up-to-10, pos-orders, pos.redeem-loyalty`
  (`role_has_permissions` JOIN, foodking_e2e). PAS de `items_*`, `catalog.*`,
  `ingredients_manage`, `settings`.
- **Preuve gate (tinker, user id=3 pos@lecayenne.fr)** :
  `items_show/items_edit/items_create/items_delete/catalog.compose/
  catalog.publish/ingredients_manage/settings` = **deny** ; seul `pos` = ALLOW.
- **Câblage vérifié** :
  - `ItemController.php:32-35` store→`items_create`, update→`items_edit`,
    destroy→`items_delete`.
  - `ItemVariationController.php:22-23` / `ItemExtraController.php:21-22`
    store/update/destroy→`items_edit`.
  - `routes/api.php:753` ingredients group→`permission:ingredients_manage`.
  - `routes/api.php:767/789` composer→`permission:catalog.compose` (+ publish
    →`catalog.publish`).
  - `AvailabilityController.php:21-28` toggle/toggleExtra/toggleVariation
    →`items_edit` (l'opérateur ne peut pas 86 un produit).
- **Acceptance verte** : `CentralManagementAuthzMatrixTest` (3/3) —
  « items_show can read modifiers but cannot mutate them ».

### H2 — Aucun prix NÉGATIF acceptable (variation/extra/item)
- `IniAmount.php:35-45` rejette `< 0` (mode zero=true) et `<= 0` (mode défaut).
  `ItemVariationRequest.php:40` = `IniAmount(true)` (0 permis pour variation
  gratuite) ; `ItemExtraRequest.php:35` + `ItemRequest.php:52` = `IniAmount()`
  (>0 strict). Le composer interdit carrément le prix : `ComposerProfileRequest.php:20,36`
  `price => prohibited` (NF525 : le profil ne peut pas injecter de prix).
- **Preuve DB** : 0 prix négatif — items 59/0neg, variations 420/0neg,
  extras 353/0neg (`WHERE deleted_at IS NULL`).

### H3 — Composer MIN/MAX viandes validé (bornes)
- `ComposerProfileRequest.php:27-28` `min_select`/`max_select` `min:0` +
  `withValidator` ligne 55-67 : `max < min` → erreur 422.
- **Acceptance** : `ComposerSchemaTest::step_rejects_invalid_selection_bounds` PASS.

### H4 — Snapshot NF525 immuable (édition prix pendant commande en cours protège l'ordre)
- Double défense vérifiée : (1) garde applicative `OrderItem.php:51-54`
  (`updating` → exception si `composition_snapshot` dirty et original non-null),
  (2) trigger DB `2026_05_24_040211_add_composition_snapshot_immutability_trigger`.
- **Preuve data réelle** : `order_items.composition_snapshot` (OI #4933) fige
  `unit_price`/`line_total` + `variation_name`/`extra_name` au moment de la
  commande (ex. extra Cheddar `unit_price: 0.9`). Le prix de ligne facturé vit
  aussi dans `order_items.price` (immuable). Éditer le prix de l'item APRÈS ne
  touche ni le snapshot ni `order_items.price` → l'ordre garde l'ancien prix.
- **Acceptance** : `ItemDeletionWithOrderHistoryTest` (4/4) — supprimer un item
  avec historique laisse le snapshot intact (force-delete bloqué 409).

### H5 — Toggle ingredient propage le cache kiosk/pos
- `ItemUpdateInvalidatesKioskCacheSentinelTest` (2/2) + `IngredientControllerToggleTest`
  (5/5, dont « non_admin_cannot_toggle ») + `IngredientAvailabilityChangedAfterCommitTest`
  (4/4, dispatch after-commit). `IngredientController.php:72` interdit le toggle
  des ingrédients addon (read-only) → 422.

### H6 — Pas d'oversell / stock négatif
- `StockConcurrentDecrementTest` (3/3) : garde atomique « allows only 20 successes
  across 50 attempts ». `StockMovementsAppendOnlyTest` (3/3) append-only.
- **Preuve DB** : `stock_levels` 2 lignes, 0 négatif, min on_hand 982.

### H7 — Pas de fuite de coût/marge aux opérateurs via le catalogue
- `SimpleItemResource` (champs lignes 28-50) expose seulement
  prix-de-vente/nom/catégorie/disponibilité — AUCUN champ cost/margin/supplier/
  secret. Un POS Operator (`items_show OR pos`, `ItemController.php:38`) lit le
  menu sans donnée commerciale confidentielle.
- Aucun endpoint catalogue/composer/stock n'expose `license_key` / FCM /
  gateway secret (ceux-ci vivent dans Settings → hors mes ancres).

---

## FINDINGS

### [P3] base de données `permissions`/`roles` — artefacts RBAC inertes (hygiène)
- **repro** :
  `mysql -u root foodking_e2e -e "SELECT id,name,guard_name FROM permissions WHERE name='ingredients_manage';"`
  → 2 lignes : id=82 (sanctum), id=83 (**web**).
  `mysql -u root foodking_e2e -e "SELECT id,name,guard_name FROM roles WHERE name='3' OR guard_name<>'sanctum';"`
  → role id=14 name='3' (sanctum) + id=9 « Branch Manager » (web).
- **evidence** : perm id=83 (web) n'est attachée à AUCUN rôle ;
  `SELECT role_id,COUNT(*) FROM model_has_roles WHERE role_id IN (9,14)` → 0 ligne
  (aucun utilisateur). L'API passe par `auth:sanctum` → ne résout que le guard
  sanctum (id=82 → Admin + Branch Manager). Aucune escalade possible.
- **lentille** : commerçant (technique) — duplication cross-guard d'un seeder.
- **reco** : nettoyage seeder (supprimer perm web orpheline id=83, rôle junk
  id=14 name='3', rôle web id=9 sans user). **NON-bloquant V1-LOCAL** : 0 risque
  de sécurité, mono-poste sanctum-only. Différable V1.0.X.

### [P3] `app/Http/Controllers/Admin/ItemController.php:38` — read catalogue ouvert à `pos` (intentionnel, à documenter)
- **repro** : POS Operator (perm `pos`, sans `items_show`) → `GET /api/admin/item`
  retourne le catalogue (gate `items_show OR pos`).
- **evidence** : c'est **voulu et nécessaire** (la caisse a besoin du menu) ;
  `forcePosRuntimeBranchScope` + `applyDefaultPosSurfaceForPosRuntimeUser`
  (lignes 290-323) forcent `surface=pos` + branch de l'opérateur → pas de fuite
  de SKU kiosk-only ni d'autre branche. `SimpleItemResource` ne fuit aucun coût
  (cf. H7). Aucun champ sensible.
- **lentille** : commerçant (technique).
- **reco** : aucune action sécurité requise — note d'architecture seulement
  (asymétrie read large mais sortie volontairement réduite aux champs de vente).
  **NON un défaut.**

---

## HORS-SCOPE (appartient à Settings/Users 5.c — NE PAS me les attribuer)
- license_key read-gate (`LicenseController::index` / `api.php:431`) — P2 plan §85.
- FCM secret (`SettingResource:66`) — plan §86.
- user-enumeration (`/admin/customers` emails) — plan §87.
Ces 3 vecteurs ne touchent AUCUN de mes fichiers ancrés (Catalogue/Composer/Stock).

---

## TESTS REJOUÉS (verts)
- Catalog : `ItemUpdateInvalidatesKioskCacheSentinel(2) ItemDeletionWithOrderHistory(4)
  CentralManagementAuthzMatrix(3) ComposerSchema(2) AddonRolePersistence(4)` = 15/15.
- Stock/Ingredients : `StockConcurrentDecrement(3) AvailabilityDecrementConcurrency(4)
  StockMovementsAppendOnly(3) StockBranchIsolation(1) IngredientControllerToggle(5)
  IngredientAvailabilityChangedAfterCommit(4)` = 20/20.
- **35/35 PASS.** Frozen : 0 ligne touchée (audit read-only).
