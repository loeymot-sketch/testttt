# Playwright / E2E verification Report 001

## Date
2026-03-10

## Environment
- branch: main
- DB: SQLite In-Memory (Automated QA Script)
- Tool: PHPUnit Feature Tests `AntiGravityTest`

## Passed
- **T01** : Login Kiosk valide → 200 + token
- **T02** : Login Kiosk invalide → 4xx
- **T03** : Login Kiosk déjà connecté → Rejeté
- **T04** : Login Kiosk inactif → Rejeté
- **T05** : Kiosk ne peut pas accéder aux routes Admin → 401/403
- **T07** : Kiosk ne peut pas lire les commandes POS → 401/403
- **T11** : Création commande sans Auth → 401
- **T22** : OSS POST interdit → 405 Method Not Allowed
- **T23** : OSS accès sans Token → 401/403

## Failed & Issues Detected

- **T06, T08, T09, T10 (Intégrité Prix / Commande)** : Crash SQL `discount_price` inexistant. La base de données de test ne possède pas la colonne `discount_price` sur la table `items`, causant des 500 erreurs lors de la création d'Items avec les Factories.
- **T12 (PENDING order visible in POS)** : Échec de l'assertion HTTP 200 sur `GET /api/admin/online-order`. Le controller semble requérir des permissions ou dépendances (settings/branch) supplémentaires non satisfaites ou renvoie 500/401.
- **T13, T14, T20 (Transitions d'état POS/KDS)** : Les appels POST sur `change-status` crashent ou n'aboutissent pas au statut attendu, potentiellement liés aux factory manquantes ou permissions complexes non assignées au vol (rôle `kds`, `admin`).
- **T18 (KDS voit uniquement sa propre Branch)** : Échec de la liste `GET /api/admin/kds-order`. Raison probable : le token admin local ne mappe pas les données branch sans rôle explicite ou setting manquant.

## Technical Clues (For Claude)
1. **Model Factories** : `KioskMachine`, `Order`, `Item` etc. n'utilisaient pas le trait `HasFactory`. L'appel direct via `ModelFactory::new()` est requis.
2. **Database Schema Drift** : La Factory `ItemFactory` que nous avons créée assigne `discount_price`, mais la vraie base SQLite de `migrations` n'a pas cette colonne. *Action Kimi* : Retirer `discount_price` de `ItemFactory.php`.
3. **Roles & Permissions (Spatie)** : Les tests POS/KDS nécessitent que les test users aient les permissions validées en DB via `assignRole()`. Comme SQLite boot vide, les rôles Spatie (ex: `admin`, `kds`) n'existent pas en base mémoire, causant des crashs "RoleDoesNotExist". *Action Kimi*: Seeder les rôles de base dans le Setup de test.

## Priority Issues
1. **[CRITICAL]** Incohérence Schema / Factory pour `discount_price` sur `items`.
2. **[HIGH]** Seed Spatie manquant en Test (`kds`, `admin` roles) pour permettre le flow de commande complet.

## Suggested Next Tasks (For Claude Planning)
1. Planifier la correction de `ItemFactory.php` (retrait de `discount_price`).
2. Planifier le Seeding des Rôles Spatie dans `TestCase::setUp()` pour déverrouiller les tests ACL E2E (T12 à T20).
3. Réparer la route Kiosk Auth qui bloque avant l'auth si des settings globaux manquent.
