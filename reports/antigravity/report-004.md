# Anti-Gravity Report 004

## Date
2026-03-10

## Environment
- branch: main
- DB: SQLite In-Memory (Automated QA Script)
- Tool: PHPUnit Feature Tests `AntiGravityTest`

## Résumé du Cycle (Sprint 3.1)
L'utilisateur a pris le relais de Kimi pour implémenter la correction logicielle des rôles Spatie (passage au guard `sanctum` et noms en PascalCase `Admin`, `Chef`, etc.), ainsi que l'adaptation du payload de création de commande (ajout de `is_advance_order`, `source`, et encodage JSON des `items`).

**Résultat : 12 réussites, 6 échecs. Les crashs Spatie `RoleDoesNotExist` ont totalement DISPARU ! 🎉**

## Passed (12)
✅ T01, T02, T03, T04, T05, T08, T09, T10, T11, T13?, T22, T23.
- L'authentification Kiosk et l'isolation fonctionnent.
- **AMÉLIORATION** : Les tests T08, T09 et T10 (intégrité des prix / requêtes forgées) passent désormais avec les nouveaux payloads ! L'API backend recalcule bien les totaux depuis la base de données. 

## Failed / Errors (6)
De nouveaux blocages métier se révèlent maintenant que les permissions Spatie fonctionnent :

1. 💥 **T06 (Kiosk can create order)** : Crash 500 : `Attempt to read property "faviconLogo" on null`.
   - **Diagnostic (For Claude)** : Lors du processus de création de commande (ou de l'envoi de notification/email lié à la commande), l'application essaie de lire le logo du site via les Settings, mais l'objet setting retourné semble être null ou mal formaté dans notre base SQLite de test (`seedMinimalSettings()`).

2. ❌ **T07 (Kiosk cannot read POS orders)** : Échec de l'assertion HTTP 401/403.
   - **Diagnostic (For Claude)** : Le Kiosk parvient à appeler l'endpoint POS en recevant probablement un 200, ce qui révèle une faille d'isolation. Les middlewares ou les policies de cet endpoint ne bloquent pas le rôle manquant du Kiosk.

3. ❌ **T12 (Pending order visible in pos) / T18 (KDS sees only own branch)** : Échec de l'assertion HTTP 200/403.
   - **Diagnostic (For Claude)** : Soit la validation API échoue, soit l'assignation du rôle n'est pas suffisante pour autoriser l'accès, soit l'endpoint retourne une Data Structure inattendue.

## Suggested Next Tasks (For Claude Planning - Sprint 4)
1. **[Claude] Audit de la gestion des Settings** : Pourquoi la méthode `seedMinimalSettings` de `TestCase` (qui insère des lignes SQL) ne suffit-elle pas à prévenir le crash `faviconLogo on null` lors de la création d'ordre ? Y a-t-il un cache de settings à vider ou un autre helper utilisé (ex: `Setting::first()`) ?
2. **[Claude] Audit de Sécurité (T07)** : Inspecter l'endpoint `GET /api/admin/pos-order` pour rajouter la vérification de rôle/permission stricte empêchant un simple token `User` (Kiosk) d'y accéder.
3. **[Claude] Audit des endpoints POS/KDS (T12, T18)** : Analyser les controllers `OnlineOrderController` et `KdsOrderController` pour comprendre ce qu'ils attendent exactement comme Rôles ou données.
