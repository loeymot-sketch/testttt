# Z3ter — Équipe, rôles & accès : lecture seule (W1 ONB-06)
Session port 8800 (le GOAL prévoyait 8806 ; conservé 8800 tel qu'assigné). DB `foodking_e2e`, aucune écriture. Toutes les valeurs ci-dessous sont vérifiées `grep`/`Read`/requête `SELECT` en lecture seule le 2026-08-27.

## 1. Rôles livrés — `database/seeders/RoleTableSeeder.php`
8 rôles insérés (`Role::insert`, ligne 17) : `Admin` (l.19), `Customer` (l.25), `Delivery Boy` (l.31), `Waiter` (l.38), `Chef` (l.44), `Branch Manager` (l.50), `POS Operator` (l.56), **`Stuff`** (l.62) — nom incompréhensible pour un restaurateur (probable coquille de « Staff »), confirmé code réel, pas une invention.

Comptage réel en base (`Role::withCount('permissions')`, guard `sanctum`) : Admin 84 · Branch Manager 54 · POS Operator 10 · Waiter 4 · Chef 4 · Stuff 3 · Customer 0 · Delivery Boy 0. Total permissions en base = 88 (au-delà des 80 nommées dans `PermissionTableSeeder.php` : +`ingredients_manage`, `availability_toggle`, `catalog.compose`, `catalog.publish`, avec doublons de guard). DB contient aussi des rôles résiduels de sessions E2E précédentes (`Branch Manager` guard `web`, un rôle nommé `3`, `E2ERolePerm32048`) — pollution de données de test, pas un défaut du seeder ; non touché.

## 2. Permissions — lisibilité
80 permissions nommées dans `PermissionTableSeeder.php` (`grep -c "'title'"` = 80), toutes avec un `title` en **anglais brut**, affiché tel quel côté client : `resources/js/components/admin/settings/Role/RoleShowComponent.vue:34` → `{{ permission.title }}`, aucune traduction. Aucun fichier `lang/fr/permissions.php` n'existe (`find` négatif).
10 libellés réels (`grep -n "'title'"`) : « Dashboard » (l.20), « Items » (l.28), « Items Create » (l.36), « POS Discount up to 10% » (l.140), « POS Discount 10%-50% (manager) » (l.148), « POS Discount above 50% (owner) » (l.156), « POS Destroy Paid Order » (l.165), « POS Manage Fiscal (Z/X reports) » (l.174), « POS Reopen Closed Z Report » (l.183), « POS Refund (Counter-Entry NF525) » (l.211).

## 3. Créer un employé
1 seul écran (drawer latéral) : `resources/js/components/admin/employees/EmployeeCreateComponent.vue`. Champs : name*, email*, phone, status* (radio), password*, password_confirmation*, role_id* (select), branch_id (conditionnel), + `country_code` (champ caché, requis, auto-rempli). FormRequest : `app/Http/Requests/EmployeeRequest.php` — `authorize()` réel (l.17-29, `can('employees_create')||can('employees_edit')`, pas un `return true`). Piège confirmé : `phone` est `nullable` (l.60-66) côté validation alors que la colonne `users.phone` est `NOT NULL` en DB depuis `2026_05_16_140100_make_user_phone_required.php` → source de l'erreur SQL brute en mise à jour (P1 déjà connu Z3, non re-testé ici car lecture seule stricte).

## 4. Matrice d'enforcement (8 routes, `routes/api.php` groupe `admin`, middleware de base `installed, apiKey, auth:sanctum, block_kiosk_token_admin, localization, throttle:admin-mutation` — **aucune permission** à ce niveau)
| # | Route | Contrôleur | Mécanisme réel | file:line |
|---|---|---|---|---|
| 1 | POST `employee` | `EmployeeController::store` | middleware constructeur `permission:employees_create` | `EmployeeController.php:29` |
| 2 | PUT/PATCH `employee/{id}` | `::update` | `permission:employees_edit` | `EmployeeController.php:30` |
| 3 | DELETE `employee/{id}` | `::destroy` | `permission:employees_delete` | `EmployeeController.php:31` |
| 4 | PUT/PATCH `permission/{role}` | `PermissionController::update` (donne les droits) | `permission:settings` | `PermissionController.php:33` |
| 5 | POST/PUT/DELETE `role` | `RoleController` | `permission:settings` | `RoleController.php:21` |
| 6 | DELETE `pos-order/{order}` | `PosOrderController::destroy` | `permission:pos-orders` (route) **+** `pos-destroy-paid` conditionnel si commande payée, vérifié en service, pas en middleware | `PosOrderController.php:28` + `OrderService.php:3269` |
| 7 | GET `cash-overview` | `CashOverviewController::index` | **aucun middleware, ni route ni constructeur** — gardée par `abort_unless($user->can('cash-sessions-report'),403,…)` **inline dans la méthode** | `CashOverviewController.php:85-89` (constructeur vide : l.72-75) |
| 8 | PUT/PATCH `ingredients/{id}/availability` | `IngredientController::toggleAvailability` | **aucune permission dans le contrôleur** (grep négatif) — gardée au niveau du **groupe de routes** `permission:ingredients_manage` | `routes/api.php:901` |

Les 8 sont gardées, mais par 4 mécanismes différents (middleware contrôleur, middleware route-groupe, check service, check inline méthode) — un `grep "permission:"` sur un seul niveau (ex. contrôleur seul) donne de faux négatifs, piège méthodologique confirmé sur #7 et #8. Recherche ciblée de routes **sans aucune garde** : aucune trouvée dans l'échantillon audité ; `RouteCoverage_AdminPermissionGateSentinelTest.php` (lu intégralement, 261 lignes) documente 3 groupes qui ÉTAIENT non gardés avant heal (`menu-template`, `default-access`, `analytic-section`) et teste par **appel HTTP réel** (`actingAs()->postJson()`, assertion sur code 403/200/201) — c'est de l'enforcement réel, pas de la déclaration. Portée : 3 groupes de routes, 7 scénarios — PAS la matrice complète 6 rôles × toutes routes exigée par C1 (T-3.1.2 reste à créer).

## 5. `return true` — sentinelle
`tests/Feature/Sentinels/FormRequestAuthzDriftSentinelTest.php:67` → `RETURN_TRUE_BASELINE = 64`. **Recompté moi-même** avec la regex exacte du test (script PHP autonome, lecture seule sur `app/Http/Requests/`) → **64**, confirmé identique. La valeur 62 citée dans MISSION §0.6/§5 est fausse ; 64 est correcte au HEAD `e2c6fa4cc`. Liste des 64 fichiers obtenue et vérifiée (ex. `EmployeeAddressRequest.php`, `MenuTemplateRequest.php`, `AnalyticSectionRequest.php` — ces deux derniers renvoient `true` nu mais sont couverts par une garde route/contrôleur séparée, cf. §4).

## Réponse à la question directrice
Nadia peut donner un rôle en 1 écran, mais lit des libellés anglais/jargon (« POS Destroy Paid Order ») sans traduction ni description — elle ne comprend PAS ce qu'elle donne. Côté serveur, l'échantillon de 8 routes sensibles est réellement gardé, mais par des mécanismes hétérogènes et non uniformément visibles dans `routes/api.php` ; aucune preuve exhaustive (matrice 6×N) n'existe encore.
