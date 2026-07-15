# Audit SÉCURITÉ — GESTION (admin central, 94 contrôleurs)
Date : 2026-07-15 · Périmètre : `app/Http/Controllers/Admin/**` + services + FormRequests + seeders RBAC
Contexte : FoodKing V1 LOCAL Le Cayenne — mono-poste, 1 branche (branch_id=1), rôles Spatie.

## Verdict global
La surface GESTION est **fortement durcie** (produit de ~170 sessions d'audit : USR-RBAC-01/02/03,
WAVE5-SEC-001, C09, GAP-19-2, SET-01/02, NC-MSG, IDOR my-order healé). **Aucun trou P0/P1
exploitable par un rôle faible** (POS Operator / Chef / Waiter / Stuff) n'a été trouvé :

- Les mutations catalogue/prix (`Item`, `ItemCategory`, `Coupon`, `Offer`, `Composer`, `Availability`,
  `Stock`) sont toutes gardées par `items_*` / `settings` / `coupons_*` / `catalog.compose` — que les
  rôles faibles n'ont PAS (POS Operator = `pos, pos-orders, pos-discount-up-to-10, pos.redeem-loyalty,
  kds, oss` ; Chef = `dashboard, kds, oss`).
- Mass-assignment de `role` **bloqué** : `role` n'est pas dans `User::$fillable` ; chaque service staff
  hardcode le rôle (`assignRole(ADMIN/CUSTOMER/…)`) ou passe par `callerMayGrantRole` (sous-ensemble strict).
- Mass-assignment de `branch_id` **scoppé** : `EnforcesOwnBranchScope::effectiveBranchId` force un appelant
  non-`settings` à sa propre branche (pas de `branch_id=0` escalade).
- IDOR adresses **cross-check** OK (`UserAddressService` vérifie `user_id == address->user_id`).
- Édition/mot-de-passe cross-rôle **bloqué** sur Customer/Waiter/Chef/DeliveryBoy via `assertTargetRole`.

Restent 3 défauts résiduels (P2/P3), aucun n'escalade vers Admin.

---

## FINDING 1 — P2 — EmployeeService : changePassword/destroy/changeImage/show contournent `callerMayGrantRole`
**Fichier** : `app/Services/EmployeeService.php:230` (changePassword), `:199` (destroy), `:249` (changeImage), `:181` (show)
**Garde route** : `EmployeeController.php:28` → `permission:employees` / `:31` → `permission:employees_delete`

### Le défaut
`store()` (L104-105) et `update()` (L141-145) enforcent DEUX gardes : `blockRoles` **ET**
`callerMayGrantRole()` — ce dernier existe explicitement pour « Prevents privilege escalation +
peer-cloning » et « blocks cloning a peer » (docblock L74-81). Il exige que le rôle cible soit un
sous-ensemble STRICT des permissions de l'appelant.

Mais `changePassword`/`destroy`/`changeImage`/`show` ne vérifient QUE `blockRoles`
(`[ADMIN, CUSTOMER, DELIVERY_BOY, WAITER, CHEF]` = rôles 1-5). Les rôles **BRANCH_MANAGER(6),
POS_OPERATOR(7), STUFF(8)** ne sont PAS bloqués et `callerMayGrantRole` n'est PAS rappelé.

Conséquence : un **Branch Manager** (rôle 6, possède `employees` + `employees_delete`, seeder L58/61)
peut **réinitialiser le mot de passe** ou **supprimer le compte** d'un **PAIR Branch Manager** (rôle 6) —
prise de contrôle / destruction horizontale d'un compte de privilège égal — alors que la création d'un
pair est justement refusée par `callerMayGrantRole` (perms égales ⇒ `count < count` faux). Incohérence
directe avec l'invariant que le code prétend appliquer.

Pas d'escalade VERS Admin (rôle 1 bloqué). Impact V1 LOCAL faible (probable = 1 seul manager), d'où P2.

### Repro
1. Auth Branch Manager A (token sanctum).
2. `POST /api/admin/employee` avec `role_id=6` → **422 permission_denied** (callerMayGrantRole refuse le clonage de pair). Bien.
3. `POST /api/admin/employee/change-password/{id_du_Branch_Manager_B}` body `{password, password_confirmation}` → **200**, mot de passe de B réinitialisé → login as B (account takeover).
4. `DELETE /api/admin/employee/{id_du_Branch_Manager_B}` → **202**, compte B supprimé.

### Fix (scope-minimal, non-frozen)
Ajouter `assertTargetRole`/`callerMayGrantRole` symétrique sur `changePassword`, `destroy`, `changeImage`,
`show` : refuser si `!callerMayGrantRole(auth()->user(), optional($employee->roles[0])->id)` (même règle
sous-ensemble-strict que store/update). Sentinelle : étendre le test RBAC employee au chemin change-password/destroy.

---

## FINDING 2 — P3 — Écritures d'adresses gardées par la permission de LECTURE `*_show` (5 contrôleurs)
**Fichiers** : `EmployeeAddressController.php:22`, `CustomerAddressController.php:22`,
`WaiterAddressController.php:22`, `ChefAddressController.php:22`, `AdministratorAddressController.php:22`
**Contre-exemple correct** : `DeliveryBoyAddressController.php:29-32` (split `_show`/`_create`/`_edit`/`_delete`)

### Le défaut
Ces 5 contrôleurs gardent `index, store, update, destroy, show` **tous** derrière `permission:X_show`
(une permission de LECTURE). Le sibling DeliveryBoy prouve le pattern voulu : `_show` pour la lecture,
`_create/_edit/_delete` pour les écritures. Donc un principal détenant seulement `X_show` peut
**créer/modifier/supprimer** des adresses staff/clients.

Non exploitable avec les rôles seedés (Branch Manager a tout le bundle CRUD ; les rôles faibles n'ont
aucun `*_show`). Latent : un Admin qui crée via RoleController un rôle « auditeur lecture seule » avec
`customers_show` seul lui donne par surprise l'écriture d'adresses. Class-of-bug sur 5 fichiers.

### Repro
1. Admin crée un rôle « Auditeur » avec UNIQUEMENT `customers_show`.
2. Auth Auditeur → `POST /api/admin/customer/address/{customer}` body adresse → **201** (attendu 403).
3. `DELETE /api/admin/customer/address/{customer}/{address}` → **202** (attendu 403).

### Fix
Aligner les 5 contrôleurs sur DeliveryBoyAddressController : `->only('index','show')` sous `X_show`,
`->only('store')` sous `X_create`, `->only('update')` sous `X_edit`, `->only('destroy')` sous `X_delete`.

---

## FINDING 3 — P3 — Info-disclosure : lectures `setting/*` (index) non gardées
**Fichiers** : `SiteController.php:19`, `CompanyController.php:19`, `OrderSetupController.php:19`,
`ThemeController.php`, `SocialMediaController.php`, `OtpController.php:19`, `CookiesController.php`,
`NotificationAlertController.php` (tous `->only('update')` uniquement)
**Route** : `routes/api.php:337-340` (`GET /api/admin/setting/site` sans middleware permission)

### Le défaut
Le groupe `setting` (`routes/api.php:331`) et le groupe parent `admin` (`:300`) n'ont AUCUN
`permission:` — seulement `auth:sanctum` + `block_kiosk_token_admin`. Chaque contrôleur doit s'auto-garder.
Ces 8 contrôleurs ne gardent que `update`, donc leur `index` est lisible par **n'importe quel staff
authentifié** (ex. un Chef sans aucune permission `settings`). `SiteResource` (`SiteResource.php`) expose
`site_google_map_key`, `site_app_debug` ; `CompanyResource` expose les coordonnées business (email/tél/adresse).

Incohérent avec le heal GAP-19-2 qui a précisément gardé `index+update` sur les contrôleurs porteurs de
secrets (Mail/Sms/Payment/License/KioskSetup/LoyaltySetup). **Possiblement intentionnel** (la clé Maps
navigateur est de toute façon exposée côté client) → faible sévérité / confiance moyenne.

### Repro
1. Auth Chef (rôle 5 : `dashboard, kds, oss` — 0 permission settings).
2. `GET /api/admin/setting/site` → **200** + `site_google_map_key` (attendu 403).
3. Contraste : `GET /api/admin/setting/mail` → **403** (index gardé). Preuve de l'incohérence.

### Fix
Ajouter `'index'` aux `->only(...)` des 8 contrôleurs (mirroir Mail/Payment/Sms), OU confirmer
explicitement en commentaire que ces reads sont non-sensibles par design.

---

## Zones vérifiées SAINES (pas de finding)
- `ItemController` / `ItemCategoryController` / `CouponController` / `OfferController` /
  `AdministratorController` / `TransactionController` / `MessageController` / `AvailabilityController` /
  `StockRuptureDashboardController` / `PosCategoryController` — toutes mutations gardées.
- `CashOverviewController` / `CashSessionReportController` — garde inline `can('cash-sessions-report')` (__construct).
- `IngredientController` / `ComposerProfileController` / `ComposerStepController` — gardés au niveau route
  (`ingredients_manage` / `catalog.compose` / `catalog.publish`).
- Customer/Waiter/Chef/DeliveryBoy services — `assertTargetRole` strict sur update/changePassword/changeImage.
- `AdministratorService::update` sans assertTargetRole MAIS appelant forcément Admin (`administrators_edit`
  = Admin only) ⇒ pas exploitable, non retenu.
