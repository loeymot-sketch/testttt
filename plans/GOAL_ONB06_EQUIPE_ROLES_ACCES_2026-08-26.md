# GOAL — ONB-06 ÉQUIPE, RÔLES & ACCÈS
## FoodKing — Onboarding commerçant · créer son personnel et ses rôles métier, comprendre chaque permission, être sûr que l'API refuse ce que l'écran cache

- **Slug** : `ONB06_EQUIPE_ROLES_ACCES_20260826` · **Auteur** : Claude Code (chef de projet + rédacteur) · **Date** : 2026-08-26
- **HEAD** : `43b120c7d` · **Branche de base** : `pos/category-first-caisse-2026-06-23`
- **Voie SYSTEM_MAP** : CENTRAL — sous-voie « utilisateurs & autorisations » (`admin/{administrators,employees,chefs,waiters,customers,deliveryBoys,profile}/**`, `settings/Role/**`)
- **Index parent** : `plans/GOAL_INDEX_ONBOARDING_COMMERCANT_2026-08-26.md` · **Rapport de mission** : `reports/audit/onboarding-commercant-2026-08-26/MISSION_ONB06_EQUIPE_ROLES_ACCES.md`
- **Port de session** : **8806** · **Persona** : Nadia embauche Sami (caisse) et Léa (cuisine) ; Sami ne doit ni annuler une vente payée ni lire les rapports.

> **En cinq lignes.** Le problème : la page « Rôle & Autorisations » est **cachée** du menu, ses 80+ permissions sont libellées **en anglais et en jargon**
> (« POS Destroy Paid Order », « Stuff »), modifier un employé sans téléphone renvoie une **erreur SQL brute**, huit types de personnes existent pour une
> équipe de quatre, et l'enforcement direct de l'API n'a jamais été prouvé pour tous les endpoints (le repli client est **permissif** pour une permission
> inconnue). Preuve : `recon/Z3_utilisateurs_rbac.md` (40 appels API, 24 captures). Ce qui marche déjà (mot de passe 12 car., révocation, plafond 10, garde-fous
> d'auto-suppression) est **prouvé** et à ne pas casser. FINI = Nadia crée Sami et Léa avec des rôles lisibles, et un test parcourt chaque endpoint admin avec chaque
> rôle (C1..C6). Hors : visibilité du menu (→ 05), `BranchScope`/`DeviceTokenService` (jamais). Premier geste : W0 puis rejouer `tmp/recon/Z3/z3_api.js` sur :8806 **avec l'URL `role` au singulier**.

# §0 — PRÉAMBULE

## §0.1 — Décision arbre de travail + PRÉ-VOL DE SESSION
- **Worktree dédié** `.claude/worktrees/onb06-equipe`, branche `goal/onb06-equipe-2026-08-26`, depuis **HEAD**.
- Pré-vol : `.env` → `APP_URL=http://127.0.0.1:8806` ; `.env.testing` ; liens durs ; `ReflectionClass(App\Models\User::class)` → worktree ; serveur 8806 ; `PLAYWRIGHT_BASE_URL`.
- Base partagée : comptes de test `GOAL-ONB06 …` avec e-mails `goal-onb06-*@lecayenne.test`, mots de passe forts, **supprimés définitivement** (`forceDelete` + `model_has_roles` + `personal_access_tokens`) en fin de vague
  — un compte soft-supprimé garde son e-mail unique (leçon 2026-08-26) ; ⛔ ne jamais modifier les rôles seedés (Admin, Branch Manager, POS Operator, Chef, Stuff, Waiter) ni un vrai compte ;
  jamais `migrate:fresh` ; `safe-test.sh --phpunit "Security|Sentinels|Auth|Employee|Administrator|Role|Permission"`.
- ⚠️ Compte E2E `chef@lecayenne.fr / 123456` refusé en local (« Identifiants invalides ou compte bloqué », `users.status=5`) — dérive locale : le réparer via `php artisan foodking:ensure-admin` équivalent **ou** créer un chef de test, ne pas en faire un constat produit.
- Filet : `git branch backup/pre-onb06-2026-08-26` + `mysqldump foodking_e2e users roles permissions model_has_roles role_has_permissions`.

## §0.2 — Périmètre : DANS / HORS / voisins
| DANS | Fichiers POSSÉDÉS |
|---|---|
| S1 Créer son équipe | `resources/js/components/admin/{administrators,employees,chefs,waiters,customers,deliveryBoys}/**` (+ `address/`), `app/Http/Controllers/Admin/{Administrator,Employee,Chef,Waiter,Customer,DeliveryBoy}Controller.php` (+ `*AddressController`), `app/Http/Requests/{Administrator,Employee,Chef,Waiter,Customer,DeliveryBoy}Request.php` (+ `*AddressRequest`), `app/Services/{Employee,Chef,Waiter,DeliveryBoy,Customer,Administrator}Service.php` (à confirmer `ls`), `app/Services/Concerns/EnforcesOwnBranchScope.php` |
| S2 Rôles métier lisibles | `settings/Role/{RoleComponent,RoleListComponent,RoleShowComponent,RoleCreateComponent}.vue`, `app/Http/Controllers/Admin/{RoleController,PermissionController}.php`, `app/Http/Requests/{RoleRequest,PermissionRequest}.php`, `database/seeders/{PermissionTableSeeder,RolePermissionTableSeeder,RoleTableSeeder,SpatieRoleLookup}.php` (titres/descriptions, rôles socle), `docs/AUTHZ_MATRIX.md`, `lang/fr/permissions.php` (À CRÉER) |
| S3 Enforcement réel | `resources/js/shared/permission-match.js`, `resources/js/router/index.js` (**fonction d'accès `:106-133` seulement**), `tests/Feature/Security/**`, `tests/Feature/Sentinels/{RouteCoverage_AdminPermissionGateSentinelTest,FormRequestAuthzDriftSentinelTest}.php`, `tests/Feature/Security/AdminApiEnforcementDirectCallTest.php` (existant, à étendre), (À CRÉER) `tests/Feature/Security/AdminApiEnforcementMatrixTest.php` |
| S4 Sessions, appareils, profil | `admin/profile/**`, `app/Http/Controllers/Auth/DeviceSessionController.php`, `app/Http/Controllers/Frontend/ProfileController.php`, `app/Http/Requests/{ProfileRequest,ChangePasswordRequest,UserChangePasswordRequest}.php` |

| HORS | Porté par |
|---|---|
| Visibilité de « Rôle & Autorisations » et « État du système » dans le menu | **ONB-05** (fiche de renvoi) |
| `app/Models/Scopes/BranchScope.php` (gelé), scoping réel de `User` par filiale (V2, CLAUDE.md §9) | jamais ici |
| `app/Services/Auth/DeviceTokenService.php` révocation par appareil (« ne pas réparer », CLAUDE.md §9) | jamais (lecture seule) |
| PIN borne, comptes de bornes (`kiosk-borne-b{id}@`), jetons de bornes non révoqués | ONB-10 |
| Journal des changements (qui a modifié un rôle) | ONB-13 |
| Vocabulaire global (« Filiales », « Stuff ») | ONB-11 propose ; ce GOAL applique sur ses écrans |

Zones à coordonner : `routes/api.php` (aucune route nouvelle prévue), `fr.json` (bloc `label.*` utilisateurs/rôles), `DatabaseSeeder.php` (rôles socle, avec ONB-12).

## §0.3 — Drapeaux d'expansion
SCOPE-1 gelé (`BranchScope`, `IdempotencyKeyMiddleware`) · SCOPE-2 3 boucles · SCOPE-3 migration non prévue (aucune ; `users.phone` : décision de règle, pas de schéma → sinon G-DATA) · SCOPE-4 NF525 · SCOPE-5 : toute permission liée au fiscal (`pos-manage-fiscal`, `pos-reopen-z`, `pos-destroy-paid`, `pos-refund`) ne change **jamais** de périmètre sans gate.

## §0.4 — Pipeline
`ultra-audit-profond` · `test-e2e` · `verify-before-report` · TDD · `systematic-debugging`. Non redécrit.

## §0.5 — Convergence et critères chiffrés
Rejets Axe 6 · **deux cycles consécutifs P0+P1 = 0 aux constats identiques**.

| # | Critère | Mesure | Seuil |
|---|---|---|---|
| C1 | Matrice d'enforcement | pour chaque route admin mutatrice (`routes/api.php`, groupe `admin`) × 6 rôles seedés : réponse attendue 403 sauf autorisée ; générée par test, pas à la main | **0** 2xx indu |
| C2 | Repli client fail-closed | permission absente de la table → entrée invisible + accès refusé (`router/index.js:106-133`) | **VRAI** |
| C3 | Zéro erreur brute | 15 payloads invalides (téléphone vide, e-mail dupliqué, rôle réservé, statut) → 422 FR, aucun `SQLSTATE` | **0** |
| C4 | Permissions lisibles | 100 % des permissions avec titre FR + description d'une ligne + groupe métier | **100 %** |
| C5 | Parcours « embaucher Sami » | clics pour créer un employé avec un rôle et vérifier son menu | **≤ 8**, 1 écran |
| C6 | Garde-fous prouvés | auto-suppression, dernier admin, auto-rétrogradation, auto-désactivation → refus FR ; désactivation ⇒ 401 immédiat | **5/5** |

## §0.6 — Base héritée
PHPUnit 5 194 · Vitest 3 644 · gelé 0 · `tests/Feature/Security/` = **37** · `tests/Feature/Sentinels/` = **102** (dont `FormRequestAuthzDriftSentinelTest` `RETURN_TRUE_BASELINE = 62`, `RouteCoverage_AdminPermissionGateSentinelTest`) · `tests/Feature/Auth/` = 14 (`MultiDeviceLoginTest`) ·
`users` = 526 · permissions seedées **80** + hors seeder (`ingredients_manage`, `availability_toggle`, `catalog.compose`, `catalog.publish`) ; jeton admin = 88 permissions (mesuré) · rôles seedés 6.
**Prouvé Z3 (à ne pas casser)** : mot de passe ≥ 12 (422), ancien mot de passe exigé, changement ⇒ autres appareils révoqués, plafond 10 appareils avec éviction, révocation d'appareil ⇒ 401 immédiat, isolation des appareils entre comptes (404), rôle « Tenant Admin » réservé, retrait de permission ⇒ 403 immédiat sur le même jeton, auto-suppression / suppression de `administrator/1` / auto-rétrogradation ⇒ 422 « La permission est refusée. », fail-closed client pour permissions connues (toast « Permission requise pour accéder à cette page : … »).

## §0.7 — Contradictions tranchées
- **C-CONST** (index) : G0.
- **C-INSTR** — Z3 avait conclu « page Rôles introuvable » : **erreur d'instrument** (URL `roles/list` au lieu de `role/list`, `settingRoutes.js:460-494`). Tranché : la page existe, cachée ; le constat retenu est « cachée » (P2), pas « cassée ». Leçon CLAUDE.md §3ter.
- **C-USER-SCOPE** — CLAUDE.md §9 (corrigé 2026-08-14) : `User` **n'est pas** isolé par filiale (no-op explicite dans `BranchScope::apply()`), chantier V2. Tranché : ce GOAL ne le rouvre pas ; `EnforcesOwnBranchScope` (création) est prouvé, le reste documenté.
- **C-PHONE** — migration `2026_05_16_140100_make_user_phone_required.php` a rendu `users.phone` obligatoire alors que `EmployeeRequest.php:60-65` ne l'exige pas en mise à jour → erreur SQL. Tranché : règle de validation cohérente (obligatoire à la création, `sometimes|required` à la mise à jour) — pas de migration.
- **C-8-TYPES** — Administrateurs / Employés / Chefs / Serveurs / Clients / Livreurs (+ bornes, admins) : héritage SaaS multi-rôle. Tranché : un écran **Équipe** unique (onglet par type ou filtre) sans supprimer les routes (V2 : livreurs/serveurs) — G-EQUIPE.

## §0.8 — Le commerçant-type et ses questions
Nadia : 1. « Où j'ajoute Sami, et comment je décide ce qu'il peut faire ? » 2. « C'est quoi "POS Destroy Paid Order" ? » 3. « Si je vire Sami, il est déconnecté tout de suite ? »
4. « Quelqu'un peut-il faire par l'API ce que l'écran lui cache ? » 5. « Pourquoi il y a des "Serveurs", des "Livreurs", des "Clients" ? Je n'ai ni service ni livraison. »

# §1 — CARTE DU SYSTÈME (ancrages vérifiés)

| Sous-système | Maturité | Ancrage réel | Tests |
|---|---|---|---|
| S1 Équipe | **FONCTIONNELLE, bords cassants** | `admin/employees/{EmployeeComponent,EmployeeCreateComponent,…}.vue` · `admin/administrators/{AdministratorComponent,AdministratorCreateComponent,AdministratorListComponent,AdministratorShowComponent,AdministratorOrderDetailsComponent}.vue` · `EmployeeRequest.php:60-65` (`phone` unique) · migration `2026_05_16_140100_make_user_phone_required.php` · `EnforcesOwnBranchScope.php:19-26` · routes `routes/api.php:757-775` (employé), `:724-742` (chef), `:1444-1466` (admin), `:704-722`, `:684-702`, `:777-808` | `tests/Feature/Security/` (partiel : `EmployeeRequestAuthorizeTest`, `EmployeePeerManagementGuardTest`, `AdministratorBranchZeroMintBypassSentinelTest` — cités 13/08, vérifier `ls`) |
| S2 Rôles | **CACHÉE, anglaise** | `settingRoutes.js:33-35,460-494` (`/admin/settings/role/list`, `show/:id`) · `settings/Role/*.vue` (4) · `RoleController`, `PermissionController` (`routes/api.php:625-636`) · `PermissionTableSeeder.php:18-120,731-739` (`title` anglais : « Dashboard », « Items », « Items Create ») · `RolePermissionTableSeeder.php:18-19,25-97,99-126,128-138,144-151,157-165,169-178,199-208` · `docs/AUTHZ_MATRIX.md` | (À CRÉER) |
| S3 Enforcement | **PARTIEL** | `router/index.js:58-62,106-133,230,277-289` · `shared/permission-match.js` · `BackendMenuComponent.vue:172-221` · `DashboardComponent.vue:143-145` (repli permissif) · `FormRequestAuthzDriftSentinelTest.php:67` (62) · `RouteCoverage_AdminPermissionGateSentinelTest.php` | 37 + 102 |
| S4 Sessions | **PROUVÉE** | `Auth/DeviceSessionController.php` (`routes/api.php:279-283`) · `Frontend/ProfileController.php` (`:332-336` — chemin réel vérifié `find`, la carte Z0 disait `Auth/`) · `app/Services/Auth/DeviceTokenService.php` (lecture) · `config/auth.php` `max_devices_per_user` 10 · `admin/profile/{ProfileEditProfileComponent,ProfileChangePasswordComponent,ProfileDevicesComponent}.vue` | `tests/Feature/Auth/MultiDeviceLoginTest.php` |

**Sortie d'ancrage brute** : `sed -n 455,496p settingRoutes.js` → `path: "role"`, enfants `list`, `show/:id` · `ls settings/Role` → 4 composants · `grep -n "'title'" PermissionTableSeeder.php | head -3` → `Dashboard`, `Items`, `Items Create` ·
`grep -rn phone database/migrations -l | grep user` → `2014_10_12 (nullable)`, `2026_05_16_140100_make_user_phone_required`, `2026_08_19_140000_add_contact_phone_to_users_table` · `ls tests/Feature/Security | wc -l` → 37 · `ls tests/Feature/Sentinels | wc -l` → 102 ·
Z3 `api_results_phase2.json` : 40 étapes (422 `phone cannot be null` ×2 ; titres anglais ×44 ; garde-fous 422 ×3).

# §2 — ÉTAT MESURÉ LE 2026-08-26 (extrait de `recon/Z3_utilisateurs_rbac.md`)
**Marche** : voir §0.6 « prouvé ». **Constats** : [P1] `PUT employee/{id}` sans `phone` → « SQLSTATE[23000] Column 'phone' cannot be null » ; idem auto-désactivation d'un admin (`status=10`) · [P1] 80+ permissions en anglais/jargon ·
[P2] page Rôles cachée (`v1-hidden-modules.js:37-38`) · [P2] admin auto-désactivé garde un jeton valide (200 après) ; réactivation → 422 vide · [P2] « État du système » visible d'un caissier (perm `dashboard`) · [P2] `chef@` E2E refusé (dérive locale ; message ne distingue pas mot de passe faux / compte bloqué — anti-énumération voulue ?) · [P3] `docs/AUTHZ_MATRIX.md` non confronté aux permissions réelles.
**Non mesuré (W1)** : repli permissif avec une permission absente de la table ; page Rôles à la bonne URL ; formulaires en navigateur (création employé/chef/admin, adresses).

# §3 — SOUS-SYSTÈME 1 : CRÉER SON ÉQUIPE

## Sub 1.1 — Formulaires cohérents et sans erreur brute
**Ancrages** : `EmployeeRequest.php:60-65`, `ChefRequest.php`, `AdministratorRequest.php`, `EmployeeController::update`, migration `2026_05_16_140100`, `EnforcesOwnBranchScope.php:19-26`.
**Tâches**
- **T-1.1.1** — ROUGE d'abord : 15 payloads (téléphone vide à la mise à jour, e-mail dupliqué, téléphone dupliqué, rôle réservé, `status` hors liste, `branch_id` étranger, nom 191, mot de passe faible) sur employé / chef / admin → consigner (SQL brut attendu sur `phone`).
  • test : (À CRÉER à `tests/Feature/Security/TeamRequestsEdgeCasesTest.php`)
- **T-1.1.2** — Règles cohérentes création/mise à jour (`phone` `required` création, `sometimes|required` mise à jour ; formats FR ; messages `lang/fr/validation.php`) ; FormRequest sur **toutes** les mises à jour (dont `status`) ; aucune requête SQL ne fuit (réponse générique 422/500 FR).
  • test : le même, VERT · au-delà : deux onglets modifiant Sami ; rechargement ; double clic.
- **T-1.1.3** — `EnforcesOwnBranchScope` : un employé créé par un compte sans `settings` reçoit la filiale du créateur (prouvé) ; Admin peut choisir ; documenter que `User` n'est pas isolé en lecture (CLAUDE.md §9) — pas de changement.
  • test : `tests/Feature/Security/EmployeeRequestAuthorizeTest.php` (existant, vérifier) + (À CRÉER à `tests/Feature/Security/TeamBranchAssignmentTest.php`)
**Acceptation** : C3 = 0 · 2 tests VERTS · captures des messages lues.

## Sub 1.2 — Un écran « Équipe »
**Tâches**
- **T-1.2.1** — G-EQUIPE : un écran Équipe (liste unique, colonne « type/rôle », filtres) au-dessus des 6 types existants, sans supprimer les routes ; « Serveurs », « Livreurs », « Clients » restent cachés (ONB-05) et apparaissent comme types si activés.
  • test : (À CRÉER à `tests/js/teamUnifiedList.spec.js`) · visuel : `http://127.0.0.1:8806/admin/employees` (ou route Équipe) à 3 gabarits
- **T-1.2.2** — Parcours « embaucher Sami » : créer + rôle + mot de passe provisoire (politique 12 car. respectée, génération proposée) + résumé « Sami pourra : … » (lisible, issu des titres FR de S2).
  • test : (À CRÉER à `tests/js/teamCreateEmployeeFlow.spec.js`) · C5 ≤ 8 clics
- **T-1.2.3** — Désactiver / réactiver / supprimer : effets prouvés (désactivation ⇒ jetons révoqués ⇒ 401 immédiat ; réactivation possible sans erreur vide ; suppression = soft, e-mail réutilisable ? → trancher : libérer l'e-mail à la suppression définitive uniquement).
  • test : (À CRÉER à `tests/Feature/Security/TeamDeactivationRevokesTokensTest.php`) · C6
**Acceptation** : questions 1 et 3 de Nadia = OUI · 3 tests VERTS.

# §4 — SOUS-SYSTÈME 2 : RÔLES MÉTIER LISIBLES

## Sub 2.1 — Permissions traduites et regroupées
**Ancrages** : `PermissionTableSeeder.php` (`title`), `PermissionController`, `RoleShowComponent.vue`, `lang/fr/`.
**Tâches**
- **T-2.1.1** — Table de traduction des 80+ permissions : titre FR, description d'une ligne (« Annuler une vente déjà encaissée — réservé au gérant »), groupe métier (Caisse / Cuisine / Catalogue / Stock / Rapports / Équipe / Réglages / Fiscal), niveau de risque (fiscal ⚠️).
  • livrable : `lang/fr/permissions.php` (À CRÉER) consommé par `PermissionController` (résolution par `name`, pas par `title` seedé) · test : (À CRÉER à `tests/Feature/Security/PermissionsAllHaveFrenchTitlesSentinelTest.php` — cliquet : toute permission sans entrée FR = rouge)
- **T-2.1.2** — Écran Rôles : groupes dépliables, recherche, badge « fiscal », compteur d'utilisateurs, aperçu « ce rôle pourra : … » ; page à `/admin/settings/role/list` (dé-cachage via ONB-05).
  • test : (À CRÉER à `tests/js/rolePermissionsGrouped.spec.js`) · visuel : `/admin/settings/role/show/7`
- **T-2.1.3** — Rôles socle pour une installation neuve : Gérant, Caissier, Cuisine, Livreur (permissions listées, dérivées des rôles seedés Branch Manager / POS Operator / Chef / Waiter) ; « Stuff » renommé « Équipe salle » ou fusionné — G-ROLES ; seeder socle coordonné avec ONB-12.
  • test : (À CRÉER à `tests/Feature/Security/SeededRolesMatrixTest.php`)
**Acceptation** : C4 = 100 % · 3 tests VERTS · question 2 de Nadia = OUI.

## Sub 2.2 — Matrice générée, jamais écrite à la main
**Tâches**
- **T-2.2.1** — Générer `docs/AUTHZ_MATRIX.md` depuis la base (rôles × permissions × routes) par une commande artisan (`foodking:authz-matrix` À CRÉER) exécutée par un test de fraîcheur (le fichier commité = la sortie).
  • test : (À CRÉER à `tests/Feature/Sentinels/AuthzMatrixFreshnessSentinelTest.php`)
**Acceptation** : matrice régénérée, diff nul ⇒ vert.

# §5 — SOUS-SYSTÈME 3 : ENFORCEMENT RÉEL (l'écran cache, l'API refuse)

## Sub 3.1 — Matrice d'enforcement par rôle
**Ancrages** : `routes/api.php` groupe `admin` (`:378` middlewares `installed, apiKey, auth:sanctum, block_kiosk_token_admin, localization, throttle:admin-mutation`), `RouteCoverage_AdminPermissionGateSentinelTest.php`, `FormRequestAuthzDriftSentinelTest.php:67`.
**Tâches**
- **T-3.1.1** — Lire intégralement `RouteCoverage_AdminPermissionGateSentinelTest.php` : teste-t-il l'enforcement (appel réel) ou la déclaration ? (question posée le 13/08, jamais tranchée). Consigner.
- **T-3.1.2** — Test matriciel : énumérer les routes admin mutatrices (`Route::getRoutes()`), pour chacun des 6 rôles seedés appeler avec un payload minimal valide → attendu 403 sauf liste blanche dérivée de `RolePermissionTableSeeder` ; tout 2xx hors liste = rouge nommé.
  • test : (À CRÉER à `tests/Feature/Security/AdminApiEnforcementMatrixTest.php`) · C1 · au-delà : jeton de borne (`kiosk:order`) sur une route admin → 403 (`block_kiosk_token_admin`) ; jeton expiré ; jeton d'un compte désactivé.
- **T-3.1.3** — Resserrer `RETURN_TRUE_BASELINE` (62) d'au moins les FormRequests de cette voie (`authorize()` réel) ; documenter chaque `return true` restant.
  • test : `FormRequestAuthzDriftSentinelTest.php` (existant, cliquet ajusté)
**Acceptation** : C1 = 0 · 2 tests VERTS · cliquet ≤ 60.

## Sub 3.2 — Repli client fail-closed
**Ancrages** : `router/index.js:106-133` (permission inconnue ⇒ accès accordé), `DashboardComponent.vue:143-145`, `BackendMenuComponent.vue:172-221`, `shared/permission-match.js`.
**Tâches**
- **T-3.2.1** — ROUGE : ajouter une entrée de menu avec `permissionUrl` inexistante → aujourd'hui visible par `chef@` (à mesurer W1, scénario (b) non fait).
  • test : (À CRÉER à `tests/js/permissionUnknownIsDenied.spec.js`)
- **T-3.2.2** — Passer le repli en **fail-closed** (`hasPermissionAccess` inconnu ⇒ `false`) + sentinelle « toute `permissionUrl` du routeur existe dans `PermissionTableSeeder` ou les seeders annexes » ; coordination `router/index.js` (fichier registre : déclarer).
  • test : (À CRÉER à `tests/js/sentinels/routerPermissionUrlsExistSentinel.spec.js`) · C2
**Acceptation** : C2 VRAI · 2 tests VERTS · aucune régression sur les 25 entrées visibles (captures Admin / POS Operator / Chef).

# §6 — SOUS-SYSTÈME 4 : SESSIONS, APPAREILS, PROFIL (consolider le prouvé)

**Tâches**
- **T-4.1.1** — Figer par tests ce que Z3 a prouvé à la main : mot de passe ≥ 12, ancien mot de passe, révocation des autres appareils, plafond 10 + éviction, isolation inter-comptes, révocation ⇒ 401.
  • test : `tests/Feature/Auth/MultiDeviceLoginTest.php` (existant, étendre) + (À CRÉER à `tests/Feature/Auth/PasswordPolicyAndSessionRevocationTest.php`)
- **T-4.1.2** — Message de connexion : « Identifiants invalides ou compte bloqué » — trancher (G-MSG) : garder l'anti-énumération (recommandé) mais offrir « mot de passe oublié » et, pour un compte désactivé par le gérant, un message distinct **après** authentification réussie (pas d'oracle).
  • test : (À CRÉER à `tests/Feature/Auth/LoginMessagesNoEnumerationTest.php`)
- **T-4.1.3** — Page « Appareils connectés » : libellés (« Administration » = navigateur ?), appareil courant, bouton « déconnecter les autres » ; captures lues.
  • test : (À CRÉER à `tests/js/profileDevicesPage.spec.js`)
**Acceptation** : 3 tests VERTS · rien du prouvé n'a bougé.

# §S — SCÉNARIOS ADVERSES OBLIGATOIRES
| Fonction \ scénario | annulation | rechargement | double soumission | deux onglets | rôle inférieur (API) | données vides | volume | réseau coupé | effet caisse / cuisine | retour arrière | valeurs limites |
|---|---|---|---|---|---|---|---|---|---|---|---|
| Créer employé | `teamCreateEmployeeFlow.spec.js` | idem | e-mail dupliqué 422 (mesuré) | `TeamRequestsEdgeCasesTest` | `pos@` → 403 (`AdminApiEnforcementMatrixTest`) | téléphone vide → 422 FR (**pas SQL**) | 526 users (pagination) | — | Sami se connecte à la caisse, menu conforme | supprimer → e-mail ? | mot de passe 11/12 car., nom 190, e-mail existant, `branch_id` 99 |
| Rôle | `rolePermissionsGrouped.spec.js` | idem | doublon 422 (mesuré) | idem | 403 | rôle sans permission → utilisateur voit le tableau de bord seul | 84 permissions | — | retrait de permission ⇒ 403 immédiat (mesuré) | supprimer un rôle avec utilisateurs (202 mesuré) → prévenir | « Tenant Admin » réservé (mesuré), nom 190 |
| Désactivation | `TeamDeactivationRevokesTokensTest` | — | — | — | 403 | — | — | — | caisse ouverte par Sami → 401 | réactiver sans erreur vide | dernier admin refusé (mesuré) |
| Enforcement | — | — | — | — | **matrice 6 rôles × routes** | payload vide 422 | ~150 routes | — | jeton borne sur admin → 403 | — | jeton expiré / révoqué |
| Repli client | — | menu recalculé | — | — | permission inconnue ⇒ invisible | — | — | — | — | — | `permissionUrl` avec casse différente |

# §A — ARMÉE D'AGENTS
Architecte (frontière écran/API, matrice générée) · **Sécurité** (rôle central ici : matrice, oracle de connexion, jetons, IDOR sur `employee/{id}`) · UX/A11y (écran Équipe, groupes de permissions) ·
**Psychologie commerçant** (une permission = une phrase de conséquence ; peur de donner trop ; « qui peut annuler une vente ») · DBA (`users.phone`, `model_has_roles`, index) · SRE (cache Spatie, révocation) ·
Implémenteur unique · ROUGE (rejoue `z3_api.js` corrigé après chaque vague, cherche le 2xx indu) · QA visuel + ROUGE visuel · **Jalonneur**.
Disque `reports/test-e2e/ONB06_EQUIPE_ROLES_ACCES/<round>/wave-<W>-<rôle>.json` ; contrat de constat ; ~1 200-1 500 mots.

# §X — VAGUES DE CONVERGENCE
| Vague | Portée | Parallélisme | Bloquée par |
|---|---|---|---|
| **W0** | Pré-vol, filet, bases, réparation du compte `chef@` local ou compte de test | séquentiel | — |
| **W1** | Reconnaissance : page Rôles à la bonne URL (captures), scénario repli permissif, lecture de `RouteCoverage_*` (T-3.1.1), formulaires en navigateur | fan-out lecture seule | — |
| **W2** | S3 enforcement (T-3.1.2, T-3.1.3, T-3.2.*) — **avant l'ergonomie** : on ne dessine pas un écran Équipe sur une API perméable | séquentiel | — |
| **W3** | S1 formulaires (T-1.1.*), désactivation (T-1.2.3) | séquentiel | — |
| **W4** | S2 permissions FR, écran Rôles, rôles socle (T-2.*) | séquentiel | G-ROLES ; dé-cachage via ONB-05 |
| **W5** | S1.2 écran Équipe (T-1.2.1, T-1.2.2) + S4 (T-4.*) | séquentiel | G-EQUIPE, G-MSG |
| **W6** | Convergence : deux cycles, `safe-test.sh --phpunit "Security|Sentinels|Auth"`, Vitest, Playwright `tests/e2e/onb06-*.spec.js` (À CRÉER), `docs/AUTHZ_MATRIX.md` régénéré, BRAIN | séquentiel | — |
**§X.8** 6 points · **§X.9** STOP/`STUCK_*`/4 options · **§X.10** `wip`/`INTERRUPT_*`/BRAIN.

# §G — GATES PROPRIÉTAIRE
| Gate | Description | QUI | QUOI | OÙ | Statut |
|---|---|---|---|---|---|
| **G0** | Amendement constitutionnel (index) | Propriétaire | ligne | `CONSTITUTION.md` | EN ATTENTE — ne bloque pas |
| **G-ROLES** | Rôles socle (Gérant, Caissier, Cuisine, Livreur), sort de « Stuff », périmètre des permissions fiscales par rôle | Propriétaire | tableau signé | MISSION §6 + `docs/gates/GATE_LOG.md` | EN ATTENTE — bloque T-2.1.3 |
| **G-EQUIPE** | Écran Équipe unique au-dessus des 6 types | Propriétaire | choix | MISSION §6 | EN ATTENTE — bloque T-1.2.1 |
| **G-MSG** | Message de connexion : anti-énumération conservée | Propriétaire | choix | MISSION §6 | EN ATTENTE — bloque T-4.1.2 |
| **G-CACHE** | Dé-cacher Rôle & Autorisations ; réserver « État du système » (exécuté par ONB-05) | Propriétaire | tableau | `MISSION_ONB05` §6 | EN ATTENTE — hors de ce GOAL |
| **G-FISCAL-PERM** | Tout changement du périmètre de `pos-manage-fiscal`, `pos-reopen-z`, `pos-destroy-paid`, `pos-refund` | Propriétaire | accord par permission | `GATE_LOG.md` | EN ATTENTE — bloque toute modification de ces 4 |

# §R — RÉFÉRENCES
`ultra-audit-profond` · `test-e2e` · `verify-before-report` · `CLAUDE.md §9` (Sanctum, Spatie, idempotence, `User` non scopé) · `docs/AUTHZ_MATRIX.md` · `SYSTEM_MAP.md §5-6` ·
`plans/GOAL_INDEX_ONBOARDING_COMMERCANT_2026-08-26.md` · `_FICHES_GOAL.md` (ONB-06) · `recon/Z3_utilisateurs_rbac.md` · `recon/Z0_carte_dashboard.md §0, §5, §7` · `recon/Z0_modele_catalogue_wizard_reglages.md §G` · `tmp/recon/Z3/{z3_api.js,z3_browser.js,api_results_phase2.json,browser_results.json}` ·
`plans/GOAL_COMMERCANT_BACKEND_ACCES_2026-08-13.md` (S1.1 enforcement direct, S1.2 `User`) · `plans/GOAL_ADMIN_NAV_BREADTH_CONVERGENCE_2026-08-13.md` (Wave 3 Users/RBAC) · `tests/Feature/Auth/MultiDeviceLoginTest.php`.

# §F — RÈGLE FINALE
TERMINÉ quand et seulement quand : 1. 6 vagues closes ; 2. C1..C6 VRAIS ; 3. PHPUnit ≥ 5 194 + ≥ 14 tests créés VERTS, Vitest ≥ 3 644 ; 4. diff gelé 0 (`BranchScope`, `IdempotencyKeyMiddleware`) ; 5. NF525 ajout seul, permissions fiscales inchangées sans G-FISCAL-PERM ;
6. gates tranchés ou différés ; 7. `docs/AUTHZ_MATRIX.md` générée, BRAIN vrai ; 8. deux cycles identiques ; 9. fiches de renvoi écrites (ONB-05 dé-cachage, ONB-10 jetons de bornes, ONB-13 journal, ONB-11 vocabulaire, ONB-12 rôles socle).
**Interdit** : modifier un rôle seedé ou un vrai compte · « réparer » la révocation par appareil · scoper `User` par filiale · déclarer l'API sûre sans la matrice · approuver un gate.
> Le sens : Nadia embauche Sami en huit clics, lit en français ce qu'il pourra faire, et personne — ni Sami, ni l'API — ne peut faire ce que l'écran lui cache.
