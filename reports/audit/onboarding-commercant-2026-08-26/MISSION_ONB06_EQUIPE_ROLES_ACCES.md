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

Audit adverse en lecture seule le 2026-08-28, chaque verdict adosse a un
`fichier:ligne`. Il a **refute** un constat et trouve deux P1 absents du rapport.

### 8.1 Corrige

| Defaut | Ce que ca coutait | Preuve |
|---|---|---|
| **Un compte sans permission tournait en BOUCLE de redirection.** `handlePermissionDenied` se rabattait toujours sur `admin.dashboard`, route qui exige la permission `dashboard` : la garde se redeclenchait sur sa propre cible. `RolePermissionTableSeeder` n'appelle `givePermissionTo` ni pour **Delivery Boy** ni pour **Customer**, et le Livreur atterrit sur `delivery-boys` | **Un livreur qui se connectait ne voyait jamais d'ecran** | `tests/js/aucuneBoucleDeRedirectionSurUnRoleSansDroit.spec.js` (4) |
| **Le telephone declare facultatif sur une colonne NOT NULL**, dans SIX FormRequest (Administrator, Chef, Customer, DeliveryBoy, Employee, Waiter) | Le patron embauche son premier employe, laisse le champ vide — rien ne disait qu'il etait obligatoire — et lit « erreur de base de donnees ». Assainir un message ne remplace pas une regle juste | `UnChampObligatoireEnBaseLEstAussiDansLaRegleTest` (14) |

Le correctif de la boucle est **general** : donner des droits au Livreur traiterait le
symptome, mais la boucle reviendrait le jour ou quelqu'un cree un role vide depuis
l'ecran des roles — ce que le produit permet.

Les six formulaires portent desormais l'asterisque : rendre la regle stricte sans le
dire deplacerait seulement le probleme.

### 8.2 Refute

**« Un admin desactive garde un jeton valide »** — FAUX. `app/Http/Kernel.php:63,91`
enregistre `EnsureUserStatusActive` dans le groupe `api` : il relit `users.status` a
CHAQUE requete, supprime le jeton et rend 401. Le compte tombe a l'appel suivant.

### 8.3 Encore vrai

| Sev. | Constat | Preuve |
|---|---|---|
| **P1** | **Une cle de permission orpheline, invisible du banc cense la voir.** Le lien « Etat du systeme » — files, sauvegardes, planificateur — est montre a TOUT LE MONDE : `permissionUrlForSidebarPath('observability/system')` ne trouve aucune correspondance et retombe sur la chaine brute, que le repli permissif accepte | `BackendMenuComponent.vue:203-224` |
| **P1** | **Le banc anti-orphelines est au mauvais perimetre** : `AucuneCleDePermissionOrphelineTest:51-68` n'extrait que les litteraux `permissionUrl: '...'`. Or les cles de la barre laterale sont DERIVEES des `menu.url` en base, jamais ecrites en dur. Il verrouille le cas deja corrige et rate le seul cas ouvert | idem |
| P2 | Page « Role & Autorisations » cachee (`v1-hidden-modules.js:44`) : creer un role « Gerant » exige de taper une URL | gate G-CACHE |
| P2 | **Deux permissions MORTES** : `pos-reopen-z` (aucun point d'appel, mais le semoir l'accorde au Responsable, et le libelle promet « Rouvrir une cloture Z ») et `push-notifications_edit` (pas de methode `update`) | Un droit fiscal affiche qui ne commande rien est pire que pas de droit |
| P2 | « Stuff » et « POS Operator » affiches bruts dans la LISTE et le filtre des employes — `rolesLibelles()` n'est appele qu'a la creation | `EmployeeListComponent.vue:74,115` |
| P3 | `docs/AUTHZ_MATRIX.md` : 4 mois et ~15 permissions de retard | dernier commit 2026-04-18 |

### 8.4 Chiffres mesures

- **84 permissions** livrees par les semoirs ; **75** portes `permission:` en route ou controleur ; **0** orpheline cote routeur et boutons, **1** cote barre laterale.
- **7 permissions sans porte declarative**, dont **5 sont bien appliquees** par `$user->can()` (les trois paliers de remise, `pos-destroy-paid`, l'ecart de caisse) et **2 sont mortes**.
- **8 roles** livres, tous generiques : **rien de Cayenne n'est herite** — seules deux `landing_url` pointent vers un ecran que le role n'a pas le droit d'ouvrir.
- **3 types de personnel sur 6** sont atteignables depuis le menu ; Serveur, Livreur et Client existent mais seulement par URL directe.

Garde-fous verifies et **sains** : `callerMayGrantRole` interdit d'accorder plus que ce
qu'on detient ; on ne peut pas modifier son propre role ; « Tenant Admin » est interdit
a la creation.

### 8.5 Ce qui reste

1. **Mapper `observability/system`** vers `settings` — le lien files/sauvegardes/planificateur est visible du caissier.
2. **Elargir le banc anti-orphelines aux `menu.url` de la base** : sinon il restera vert en ratant precisement le cas ouvert.
3. **De-cacher la page Roles** (G-CACHE).
4. **Trancher les deux permissions mortes** — `pos-reopen-z` d'abord (G-FISCAL-PERM).
5. **Traduire les roles dans la liste des employes** (3 emplacements).
6. **Confronter ou retirer `docs/AUTHZ_MATRIX.md`.**

**Etat final ONB-06 : deux P1 corriges — dont un qui empechait purement et simplement un livreur d'utiliser le produit. Un constat refute. Restent une cle de permission orpheline, un banc au mauvais perimetre qui la rate, et deux permissions mortes dont une promet un pouvoir fiscal.**
