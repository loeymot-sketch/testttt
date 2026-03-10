# Anti-Gravity Report 002

## Date
2026-03-10

## Environment
- branch: main
- DB: SQLite In-Memory (Automated QA Script)
- Tool: PHPUnit Feature Tests `AntiGravityTest`

## Résumé
Kimi a effectué le premier round de correctifs (Sprint 2 - T6 à T8). 
**Résultat : 9 réussites, 4 échecs d'assertion, 5 erreurs fatales.**

## Passed (9)
✅ T01, T02, T03, T04, T05, T06, T11, T22, T23.
Les endpoints Kiosk Auth et la création de commande simple (T06) fonctionnent désormais grâce à la correction de `ItemFactory` qui ne génère plus de crash SQL 500 sur `discount_price`.

## Failed (4) - Assertions non respectées
Ces tests ne crashent plus en SQL, mais le comportement de l'API ne correspond pas aux règles de sécurité (code HTTP inattendu) :
- ❌ **T07** (Kiosk cannot read POS orders) : Attendu 401/403. Reçu un autre statut (probablement 200 ou 500). L'isolation Kiosk/Admin sur cette route manque ou plante.
- ❌ **T08** (Order forged price) : Attendu 200 ou 400. Reçoit probablement un 500 (erreur interne liée au calcul du prix/panier).
- ❌ **T09** (Order forged total rejected) : Attendu 400/422. L'API laisse passer (200) ou plante (500).
- ❌ **T10** (Invalid coupon rejected) : Attendu 400/422/404. L'API renvoie autre chose.

## Errors (5) - Crash Fatals
Ces tests n'ont pas pu s'exécuter jusqu'au bout à cause de crashs PHP :
- 💥 **T12, T13, T14, T18, T20** : `Spatie\Permission\Exceptions\RoleDoesNotExist: There is no role named admin.`
  - **Raison (Clue for Claude)** : Kimi a seedé les rôles dans `TestCase::seedSpatieRoles()` en forçant `'guard_name' => 'web'`. Cependant, l'API Sanctum/Foodking utilise probablement le guard `api` ou `sanctum`. Lorsque la méthode `$admin->assignRole('admin')` est appelée, Spatie cherche le rôle pour le guard par défaut du Model de l'Application (qui n'est pas `web`), ce qui provoque l'exception.

## Suggested Next Tasks (For Claude Planning - Sprint 3)
1. **[Kimi] Repair Role Seeding Default Guard** : Mettre à jour `seedSpatieRoles()` pour créer les rôles en omettant le paramètre `guard_name` ou en seedant explicitement pour le guard "api"/"sanctum".
2. **[Claude/Kimi] Fix API Logic for T07 to T10** : Maintenant que l'environnement de test est instancié sans crash SQL, enquêter sur pourquoi les endpoints de forge de prix et de POS Orders ne respectent pas les assertions HTTP attendues (T07 à T10). La protection KDS/Kiosk est incomplète sur certains endpoints.
