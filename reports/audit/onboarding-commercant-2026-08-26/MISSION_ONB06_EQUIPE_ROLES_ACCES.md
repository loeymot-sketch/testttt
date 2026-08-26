# MISSION ONB-06 — ÉQUIPE, RÔLES & ACCÈS · Rapport de mission
- GOAL : `plans/GOAL_ONB06_EQUIPE_ROLES_ACCES_2026-08-26.md` · Index : `plans/GOAL_INDEX_ONBOARDING_COMMERCANT_2026-08-26.md`
- État des lieux daté du **2026-08-26** (HEAD `43b120c7d`, `:8766`, base `foodking_e2e`)
- Port : **8806** · Voie : CENTRAL « utilisateurs & autorisations » · Parallèle avec : 01, 02, 05, 07, 08, 09, 10 (vague A)

## 0. COMMENT LANCER
```
Tu es le chef de mission du GOAL ONB-06 (équipe, rôles, accès). Lis : CONSTITUTION.md, PROJECT_BRAIN.md §2, SYSTEM_MAP.md, PARALLEL_PROTOCOL.md,
plans/GOAL_INDEX_ONBOARDING_COMMERCANT_2026-08-26.md (§2, §3, §5), reports/audit/onboarding-commercant-2026-08-26/MISSION_ONB06_EQUIPE_ROLES_ACCES.md,
plans/GOAL_ONB06_EQUIPE_ROLES_ACCES_2026-08-26.md, puis recon/Z3_utilisateurs_rbac.md, recon/Z0_carte_dashboard.md (§0, §5, §7) et
recon/Z0_modele_catalogue_wizard_reglages.md (§G), CLAUDE.md §9. Pré-vol §0.1 : worktree .claude/worktrees/onb06-equipe depuis HEAD,
APP_URL=http://127.0.0.1:8806, .env.testing, liens durs, serveur 8806, PLAYWRIGHT_BASE_URL, filet backup/pre-onb06 + dump users/roles/permissions/
model_has_roles/role_has_permissions. ⚠️ La page Rôles est à /admin/settings/role/list (SINGULIER) : l'auditeur du 26/08 a visé « roles » et conclu à
tort qu'elle n'existait pas. ⛔ Ne modifie jamais un rôle seedé ni un vrai compte ; comptes de test goal-onb06-*@lecayenne.test supprimés en
forceDelete (+ roles + jetons). Puis « lance le GOAL » : W0 → W1 (rejoue /Users/1millnonstop/.claude/jobs/06c6b42a/tmp/recon/Z3/z3_api.js avec l'URL
corrigée, scénario « permission inconnue », lecture de RouteCoverage_AdminPermissionGateSentinelTest) → W2 enforcement AVANT ergonomie → W3..W6.
Pipeline ultra-audit-profond, spécialistes lecture seule en un message (Sécurité en tête), implémenteur unique, ROUGE avant tout « fini », Jalonneur,
matrice §S, deux cycles identiques. Fichiers possédés = §0.2 ; menu → ONB-05, bornes → ONB-10, journal → ONB-13 : fiches de renvoi §8.
Jamais de push. Gates §G : proposer, ne pas trancher. Compte rendu : FIXÉ / VÉRIFIÉ / BLOQUÉ.
```

## 1. CONTEXTE ET VISION
Troisième heure du commerçant : son équipe. Le mandat exige que le patron **contrôle qui fait quoi** sans développeur. L'essentiel de la mécanique est sain et **prouvé**
(mot de passe, révocation, garde-fous), mais la couche « commerçant » manque : page Rôles cachée, permissions en anglais/jargon, erreurs SQL brutes, huit types de personnes,
et surtout **aucune preuve exhaustive** que l'API refuse ce que l'écran cache (repli client permissif). Persona Nadia embauche Sami (caisse) et Léa (cuisine).

## 2. ÉTAT MESURÉ LE 2026-08-26 (`recon/Z3_utilisateurs_rbac.md`, `tmp/recon/Z3/{api_results_phase1.json (351 Ko), api_results_phase2.json, browser*_results.json}`, 24 captures)
**2.1** : navigateur avec un employé POS Operator de test (12 URL), `chef@` (refusé), admin (5 URL) ; ~40 appels API (appareils, mot de passe, rôles, permissions, garde-fous).
**2.2 Ce qui marche (prouvé, à figer par tests)** : fail-closed client pour permissions connues (toast « Permission requise pour accéder à cette page : administrators/employees/chefs/waiters/customers/delivery-boys/settings ») ;
mot de passe : sans ancien → 422, ancien faux → 422, 8 caractères → 422 « au moins 12 », changement ⇒ **tous** les autres appareils 401 ; appareils : liste + courant, révocation ⇒ 401, isolation inter-comptes (404), plafond 10 avec éviction ;
rôles : « Tenant Admin » réservé (422), doublon (422), création 201, **retrait de permission ⇒ 403 immédiat sur le même jeton**, suppression d'un rôle utilisé (202) ; garde-fous : auto-suppression, suppression `administrator/1`,
auto-rétrogradation ⇒ 422 « La permission est refusée. » ; e-mail dupliqué ⇒ 422 ; menu de l'employé conforme (POS, commandes, historique, encaissement, ticket promo, Uber, roue, KDS, OSS, État du système).
**2.3 Constats**
| Sév. | Constat | Preuve |
|---|---|---|
| P1 | `PUT employee/{id}` (rôle) sans `phone` → 422 dont le message est « SQLSTATE[23000] … Column 'phone' cannot be null » ; idem `PUT administrator status=10` | `api_results_phase2.json` étapes (d)/(e) ; migration `2026_05_16_140100_make_user_phone_required.php` ; `EmployeeRequest.php:60-65` |
| P1 | 44 titres de permissions renvoyés en anglais/jargon (« Dashboard », « Items », « POS Discount up to 10% », « POS Destroy Paid Order », « POS Manage Fiscal (Z/X reports) ») | `api_results_phase2.json` (d) ; `PermissionTableSeeder.php:20,28,36` |
| P2 | Page « Rôle & Autorisations » cachée du menu (`v1-hidden-modules.js:37-38`) — existe à `/admin/settings/role/list` (`settingRoutes.js:460-494`) | correction d'instrument du chef de projet |
| P2 | Admin auto-désactivé (erreur SQL) garde un jeton valide ; réactivation → 422 vide | `api_results_phase2.json` (e) |
| P2 | « État du système » (perm `dashboard`) visible du caissier, page exposant files/sauvegardes/planificateur | `browser_results.json` menu employé |
| P2 | `chef@lecayenne.fr` refusé « Identifiants invalides ou compte bloqué » (status 5) : dérive locale ; message non discriminant (voulu ?) | `browser_results.json chef` |
| P3 | `docs/AUTHZ_MATRIX.md` jamais confronté aux permissions réelles | — |
**2.4 Angles morts** : créer un rôle « Gérant » (page cachée) ; comprendre `pos-discount-over-10-requires-manager` ; « Stuff » ; 8 types de personnes pour 4 employés.
**2.5 Cayenne** : comptes `@lecayenne.fr` (`config/app.php:123,129`), `LeCayenneRoleLandingUrlSeeder`.
**2.6 Non mesuré (W1)** : repli permissif avec permission absente de la table ; page Rôles à la bonne URL ; formulaires en navigateur ; adresses.

## 3. CE QUI A DÉJÀ ÉTÉ FAIT
- 2026-08-13 `GOAL_COMMERCANT_BACKEND_ACCES` S1 : enforcement direct planifié ; `tests/Feature/Security/AdminApiEnforcementDirectCallTest.php` **existe** (vérifié `ls` le 26/08) — à LIRE en W1 : il couvre quelques endpoints représentatifs, pas la matrice complète rôles × routes (T-3.1.2) ; lecture de `RouteCoverage_*` demandée, contradiction `User`/BranchScope tranchée le 14/08 (documentation). Autres tests existants utiles : `AdminRoutePermissionFloorTest`, `KioskTokenAdminBlockSentinelTest`, `KioskMachineAndTerminalIndexGatedTest`, `UserSuperAdminDisableHardenedSentinelTest`, `LoginPasswordValidationParityTest`, `ApiRateLimitPerDeviceTest`, `ThrottleKeysArePerDeviceTest` (37 fichiers dans `tests/Feature/Security/`).
- 2026-08-13 `GOAL_ADMIN_NAV_BREADTH` Wave 3 (8 types de personnes, adresses) : planifié, non exécuté.
- 2026-08-07 révocation **par appareil** (`DeviceTokenService`, `MultiDeviceLoginTest`) — ne jamais revenir à la révocation globale (CLAUDE.md §9).
- 2026-08-25 `RolePermissionTableSeeder::permissionsForRole()` filtre par guard (`:199-208`) ; cliquet FormRequest 64 → 62.
- Tests existants : `tests/Feature/Security/` (37), `tests/Feature/Sentinels/` (102), `tests/Feature/Auth/` (14).

## 4. ANCRAGES CODE
| Rôle | Fichier | Lignes | Note |
|---|---|---|---|
| Rôles UI | `settings/Role/{RoleComponent,RoleListComponent,RoleShowComponent,RoleCreateComponent}.vue` · `settingRoutes.js:33-35,460-494` | `path: "role"` | cachée |
| Rôles API | `RoleController`, `PermissionController` (`routes/api.php:625-636`) · `RoleRequest`, `PermissionRequest` | titres anglais | |
| Seeders | `PermissionTableSeeder.php:18-120,731-739` · `RolePermissionTableSeeder.php:18-19,25-97,99-126,128-138,144-151,157-165,169-178,199-208` · `RoleTableSeeder.php` · `SpatieRoleLookup.php` | 80 permissions, 6 rôles | + `IngredientPermissionSeeder`, `AvailabilityTogglePermissionSeeder`, `ComposerPermissionsMinimalSeeder` |
| Équipe UI | `admin/employees/{EmployeeComponent,EmployeeCreateComponent,…}.vue` · `admin/administrators/{AdministratorComponent,AdministratorCreateComponent,AdministratorListComponent,AdministratorShowComponent,AdministratorOrderDetailsComponent}.vue` · `admin/{chefs,waiters,customers,deliveryBoys}/**` | | |
| Équipe API | `routes/api.php:757-775` employé · `:724-742` chef · `:1444-1466` admin · `:704-722` serveur · `:684-702` client · `:777-808` livreur · `:744-755` my-order | | |
| Requêtes | `EmployeeRequest.php:60-65` (phone unique) · `Chef/Waiter/Administrator/Customer/DeliveryBoyRequest` · `ChangePasswordRequest`, `UserChangePasswordRequest`, `ProfileRequest` | | |
| Téléphone | `2014_10_12_000000_create_users_table.php:22` nullable → `2026_05_16_140100_make_user_phone_required.php` → `2026_08_19_140000_add_contact_phone_to_users_table.php` | | le P1 |
| Filiale | `app/Services/Concerns/EnforcesOwnBranchScope.php:19-26` · `app/Models/Scopes/BranchScope.php` (gelé, no-op `User`) | | CLAUDE.md §9 |
| Client | `resources/js/router/index.js:58-62,106-133,230,277-289` · `resources/js/shared/permission-match.js` · `BackendMenuComponent.vue:172-221` · `DashboardComponent.vue:143-145` | repli permissif | registre : déclarer |
| Sentinelles | `tests/Feature/Sentinels/FormRequestAuthzDriftSentinelTest.php:67` (62) · `RouteCoverage_AdminPermissionGateSentinelTest.php` | | à lire (déclaration vs enforcement) |
| Sessions | `Auth/DeviceSessionController.php` (`api.php:279-283`) · `Frontend/ProfileController.php` (`:332-336` — chemin réel, pas `Auth/`) · `app/Services/Auth/DeviceTokenService.php` · `config/auth.php` (`max_devices_per_user` 10) · `config/sanctum.php` (480 min) | | lecture seule sur DeviceTokenService |

## 5. BASES CHIFFRÉES
`safe-test.sh --phpunit "Security|Sentinels|Auth"` → figer W0 · `users` 526 · permissions 80 (+4 hors seeder) · jeton admin = 88 permissions · rôles 6 (7 avec Tenant Admin ?) · cliquet FormRequest 62.

## 6. DÉCISIONS PROPRIÉTAIRE EN ATTENTE
| Gate | Question | Recommandation | Si non tranché |
|---|---|---|---|
| G-ROLES | Rôles socle Gérant / Caissier / Cuisine / Livreur ; sort de « Stuff » ; périmètre fiscal par rôle | dériver des rôles seedés ; « Stuff » → « Équipe salle » | T-2.1.3 bloquée |
| G-EQUIPE | Écran Équipe unique | oui | 6 écrans restent |
| G-MSG | Message de connexion anti-énumération | conserver + « mot de passe oublié » | inchangé |
| G-FISCAL-PERM | Toute modification de `pos-manage-fiscal`, `pos-reopen-z`, `pos-destroy-paid`, `pos-refund` | jamais sans accord | inchangé |
| G-CACHE (ONB-05) | Dé-cacher Rôles ; réserver État du système | oui | page par URL seulement |

## 7. RISQUES, PIÈGES, INSTRUMENTS
- **URL de la page Rôles = `role` (singulier)** ; `roles` = catch-all « Page non trouvée » (erreur d'instrument du 26/08).
- Un compte soft-supprimé garde son e-mail unique → recréation 422 = faux positif ; `forceDelete` + `model_has_roles` + `personal_access_tokens`.
- `chef@lecayenne.fr` refusé en local : dérive de données, pas un défaut — ne pas le « corriger » dans le code.
- Le retrait de permission est immédiat côté serveur (mesuré) ; le **menu** client ne se met à jour qu'à la reconnexion (bundle `permission` chargé au login) — documenter, pas un bug.
- Les erreurs SQL brutes remontent via `message` de la réponse 422 : chercher `SQLSTATE` dans tous les corps de réponse (sentinelle).
- `:8000` = autre worktree ; ta session = **:8806**.

## 8. JOURNAL DE MISSION (rempli par la session)
| Date/heure | Vague | Tâche | Action | Preuve | Verdict | Commit |
|---|---|---|---|---|---|---|
| | W0 | | | | | |

Fiches de renvoi : ONB-05 (dé-cacher Rôles ; « État du système » réservé à `settings`) · ONB-10 (jetons de bornes non révoqués à la suppression — Z7 P1) · ONB-13 (journal des changements de rôle, IDOR `employee/{id}`) · ONB-11 (« Filiales », « Stuff », vocabulaire) · ONB-12 (rôles socle, comptes par défaut `@lecayenne.fr`) · État final : —
