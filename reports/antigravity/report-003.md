# Anti-Gravity Report 003

## Date
2026-03-10

## Environment
- branch: main
- DB: SQLite In-Memory (Automated QA Script)
- Tool: PHPUnit Feature Tests `AntiGravityTest`

## Résumé du Cycle
Kimi a effectué un second passage sur les correctifs. Les payloads de test pour l'API Frontend Order ont été modifiés (ajout de `is_advance_order`, `source`, et encodage JSON des `items`). Kimi a également ajusté le seeding Spatie.

**Résultat : 9 réussites, 4 échecs d'assertion, 5 erreurs (crashs). Le test est encore bloqué.**

## Passed (9)
✅ T01, T02, T03, T04, T05, T06, T11, T22, T23.
L'authentification Kiosk et l'isolation de base fonctionnent correctement. La création de commande valide (T06) passe.

## Failed (4) - Assertions API
Les appels à l'API de création de commande continuent d'échouer face aux requêtes forgées ou frauduleuses, ou renvoient des erreurs inattendues (probablement des 500 dûs à la logique complexe de validation/calcul) :
- ❌ **T07** (Kiosk cannot read POS orders) : Rejeté, l'API ne bloque pas explicitement avec 401/403 ou échoue différemment.
- ❌ **T08** (Order forged price) : Le prix forgé n'est pas silencieusement écrasé par la base de données ou la requête est rejetée avec une mauvaise erreur.
- ❌ **T09** (Order forged total rejected) : La protection du `total` côté serveur ne réagit pas avec le bon code HTTP ou plante.
- ❌ **T10** (Invalid coupon rejected) : L'ID de coupon invalide cause un comportement inattendu (pas 200/400/422).

## Errors (5) - Crashs Fatals de Permissions
- 💥 **T12, T13, T14, T18, T20** : `Spatie\Permission\Exceptions\RoleDoesNotExist: There is no role named admin.`
  - **Détails & Problème** : Kimi a forcé la création du rôle avec `guard_name => 'web'`. Dans la méthode `setupAdmin()`, la fonction `$admin->assignRole('admin')` est appelée. Par défaut, Laravel/Spatie injecte le guard par défaut du modèle `User`. Sur ce projet d'API, le guard du modèle est manifestement `sanctum` ou `api`. Spatie essaie donc de trouver le rôle `admin` pour le guard `api/sanctum`, et lance "RoleDoesNotExist" car seul le guard `web` existe.

## Suggested Next Tasks (For Claude/Kimi - Sprint 3)
1. **[Kimi] Guard Spatie Universel ou Explicite** : Lors de la création des rôles dans `TestCase`, il faut omettre le `guard_name` pour utiliser celui par défaut du modèle DB, ou bien spécifier explicitement le bon guard dans le code (`$admin->assignRole('admin', 'sanctum')`). Ou bien créer le rôle sur le guard par défaut.
2. **[Claude] Audit de la Logique POS/Pricing** : Une fois les rôles fixés, il faudra plonger dans le code métier des controllers (par ex. `FrontendOrderController`) pour comprendre pourquoi T08 à T10 échouent toujours sur la structure ou la vérification du prix de la commande.
