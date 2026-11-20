# Z3 — UTILISATEURS, RÔLES, CONTRÔLE D'ACCÈS — reconnaissance web réelle (2026-08-26)

> Cible `http://127.0.0.1:8766` (arbre principal, HEAD `43b120c7d` + non commité), base locale `foodking_e2e`.
> Passage 03:40-03:56 (API `api_results_phase1.json` 351 Ko, `api_results_phase2.json`, navigateur `browser*_results.json`,
> 24 captures), coupé avant rédaction ; relance coupée avant toute mesure. Consolidé par le chef de projet.
> **Non mesuré** : scénario (b) repli permissif avec une permission absente de la table (à rejouer en W1 de ONB-06).

## 1. Périmètre parcouru
Navigateur, compte employé de test (POS Operator) : `/admin/administrators`, `employees`, `chefs`, `waiters`, `customers`, `delivery-boys`,
`/admin/settings/roles/list`, `/admin/settings/company`, `/admin/observability/system`, `/admin/profile/devices`, `/admin/profile/edit-profile`.
Compte `chef@` : connexion **refusée** (« Identifiants invalides ou compte bloqué », 400) — dérive locale du compte E2E. Compte admin :
`/admin/settings/roles/list`, `/admin/settings/roles/show/7`, `/admin/employees`, `/admin/profile/devices`, `/admin/settings/kiosk-setup`.
API (jeton employé, jeton admin) : ~40 appels (appareils, mot de passe, rôles, permissions, garde-fous).

## 2. CE QUI MARCHE (preuves)
- **Fail-closed côté client pour les permissions connues** : l'employé qui tape `/admin/administrators`, `employees`, `chefs`, `waiters`, `customers`, `delivery-boys`, `settings/company` est renvoyé au tableau de bord avec le toast « Permission requise pour accéder à cette page : <perm> » (captures 02-09).
- **Mot de passe** : changement sans ancien mot de passe → 422 ; ancien faux → 422 ; nouveau de 8 caractères → 422 « au moins 12 caractères » ; changement correct → **tous les autres appareils révoqués** (401), y compris celui qui a changé.
- **Appareils** : liste avec appareil courant, révocation d'un autre appareil → 401 immédiat ; un employé ne peut pas révoquer un appareil admin (404 « Session appareil introuvable ») ; plafond 10 : après 10 connexions, les plus anciens jetons sont évincés (401).
- **Rôles** : nom « Tenant Admin » réservé → 422 ; doublon « Admin » → 422 ; création `AUDIT-ONB-Z3 Manager` 201 ; retrait d'une permission → **403 immédiat sur le même jeton** (pas de cache serveur bloquant) ; suppression d'un rôle avec 1 utilisateur → 202, l'utilisateur se reconnecte (sans rôle).
- **Garde-fous** : un admin ne peut ni se supprimer, ni supprimer `administrator/1`, ni se rétrograder (422 « La permission est refusée. ») ; e-mail déjà utilisé → 422.
- Menu de l'employé cohérent avec ses permissions (POS, commandes, historique, encaissement, ticket promo, Uber, roue, KDS, OSS, État du système).

## 3. CONSTATS (P0 → P3)
```
[RETIRÉ — ERREUR D'INSTRUMENT, corrigé par le chef de projet le 2026-08-26 18:50] « Page Rôle & Autorisations introuvable »
  L'auditeur a visé `/admin/settings/roles/list` (pluriel). La route réelle est `path: "role"` → `/admin/settings/role/list` et
  `/admin/settings/role/show/:id` (`resources/js/router/modules/settingRoutes.js:460-494`, composants `settings/Role/{RoleComponent,RoleListComponent,RoleShowComponent}.vue`).
  Le « Page Non Trouvée » (captures 08, 24, 30, 31) est donc le catch-all sur une mauvaise URL, pas un défaut produit. La page est CACHÉE du menu
  (`v1-hidden-modules.js:37-38` : `settings.permission`, `settings.role`) mais atteignable. **Non mesurée à l'écran** : à rejouer en W1 de ONB-06 avec la bonne URL.
  Leçon consignée (CLAUDE.md §3ter) : la carte Z0 §4 ligne 26 citait `/admin/settings/roles/list` par inférence — corrigée.

[P2] resources/js/config/v1-hidden-modules.js:37-38 — La page « Rôle & Autorisations » est cachée du menu alors qu'un commerçant doit composer ses rôles
  reproduction : sous-menu Réglages : 11 entrées visibles, « Rôle & Autorisations » absente ; URL directe `/admin/settings/role/list` seule voie
  impact commerçant : ne découvrira jamais qu'il peut créer un rôle « Gérant »
  recommandation : dé-cacher via ONB-05 (G-CACHE) après relecture des libellés (P1 ci-dessous)

[P1] app/Http/Controllers/Admin/EmployeeController.php (update) + app/Services/EmployeeService.php — Modifier un employé sans téléphone renvoie une erreur SQL brute
  reproduction : PUT /api/admin/employee/{id} role_id=… sans `phone` → 422 { role: { message: "SQLSTATE[23000]: … Column 'phone' cannot be null (SQL: update `users` set `phone` = ? …)" } } ; idem PUT status=10 sur un administrateur (api_results_phase2.json)
  impact commerçant : l'assignation d'un rôle échoue avec un message technique ; l'auto-désactivation « réussit » côté jeton (200 ensuite)
  recommandation : `phone` nullable ou `required` cohérent entre création et mise à jour ; FormRequest sur update ; message FR

[P1] database/seeders/PermissionTableSeeder.php (title) + settings/Role/RoleShowComponent.vue — Les 80 permissions sont libellées en anglais et en jargon
  reproduction : GET /api/admin/setting/permission/{role} → « Dashboard », « Items », « Dining Tables », « POS Discount up to 10% », « POS Destroy Paid Order », « POS Manage Fiscal (Z/X reports) »… (api_results_phase2.json, 44 titres)
  impact commerçant : impossible de composer un rôle « caissier » sans comprendre l'anglais et la caisse
  recommandation : titres FR + description d'une ligne + regroupement métier (Caisse / Cuisine / Catalogue / Rapports / Réglages)

[P2] app/Services/Auth/DeviceTokenService.php (révocation à la désactivation) — Un administrateur qui se désactive garde un jeton valide
  reproduction : PUT status=10 → erreur SQL ci-dessus mais la suite « jeton admin test après auto-désactivation » → 200 ; réactivation par admin@ → 422 vide
  recommandation : après correction du P1 phone, prouver que status=10 révoque les jetons (ONB-06)

[P2] Menu latéral — L'entrée « État du système » (permission `dashboard`) est visible d'un caissier ; l'écran expose files, sauvegardes, planificateur (Z7)
  recommandation : réserver à `settings` ou à un rôle Gérant

[P2] Compte `chef@lecayenne.fr` (E2E) refusé « Identifiants invalides ou compte bloqué » alors que `users.status=5` : le message ne distingue pas mot de passe faux et compte bloqué (voulu ? anti-énumération) — dérive locale à documenter dans MISSION_ONB06

[P3] `docs/AUTHZ_MATRIX.md` non confronté aux 80 permissions réelles (à générer par test — ONB-06)
```

## 4. ANGLES MORTS d'un nouveau commerçant
Créer un rôle « Gérant » ou « Cuisine » : page introuvable ; comprendre `pos-discount-over-10-requires-manager` : impossible sans glossaire ; « Stuff » (rôle seedé) ; huit types de personnes (administrateurs, employés, chefs, serveurs, clients, livreurs…) pour une équipe de quatre.

## 5. « CAYENNE » EN DUR
`admin@lecayenne.fr`, `pos@lecayenne.fr`, `chef@lecayenne.fr` (`config/app.php:123,129`, seeders) ; rôle-landing `LeCayenneRoleLandingUrlSeeder`.

## 6. QUESTIONS PROPRIÉTAIRE
1. Rôles préconfigurés livrés (Gérant, Caissier, Cuisine, Livreur) ? 2. Regrouper les 8 types de personnes en un écran « Équipe » ? 3. « État du système » visible du caissier ?

## 7. NETTOYAGE (preuve DB)
Comptes 653-656 (dont le caissier soft-supprimé par l'agent) et rôle 64 : supprimés définitivement par le chef de projet ; jetons `z3-caissier-*` révoqués ; `users WHERE email LIKE '%audit-onb%' = 0`, `model_has_roles` propre.

## 8. Captures (`recon/screens/Z3/`)
`02..09` employé renvoyé au tableau de bord avec toast (fail-closed) · `08`, `24`, `30`, `31` « Page Non Trouvée » sur Rôles (employé, chef, admin liste, admin show/7) · `10`, `11`, `12` pages atteintes par l'employé (État du système, appareils, profil) · `21..23` chef renvoyé au login · `33`, `35`, `36` admin (employés, appareils, configuration borne).
