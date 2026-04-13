# Rapport d'Exécution 008

## Date
2026-03-10

## Agent
Kimi (Builder)

## Références
- Plan: Plan 007 — Sprint 5 : Fix Final 3 Tests (T01, T05, T06)
- QA Report: report-008.md (15/18 tests verts, 3 échecs)

---

## Tâches exécutées

### Task 1 — Corriger le seeding ThemeSetting dans la bonne table

**Fichier** : `tests/TestCase.php`

**Root cause CRITIQUE** :
- `ThemeSetting` model utilise `protected $table = "settings"` (ligne 13 de ThemeSetting.php)
- Mais `TestCase::seedMinimalSettings()` seedait dans `theme_settings` (mauvaise table)
- Les rows étaient écrites dans une table orpheline jamais lue
- `ThemeSetting::where('theme_favicon_logo')->first()` retournait toujours `null`

**Solution** :
1. Supprimé le bloc lignes 52-58 qui seedait `theme_settings`
2. Ajouté les 3 keys theme dans le bloc principal (table `settings`) :
   - `theme_favicon_logo`
   - `theme_logo`
   - `theme_footer_logo`

**Résultat** : Les theme settings sont maintenant dans la bonne table et seront trouvées par le modèle.

---

### Task 2 — Corriger User::role(integer) dans les 3 notification builders

**Fichiers** :
- `app/Services/OrderGotMailNotificationBuilder.php` lignes 30-32
- `app/Services/OrderGotSmsNotificationBuilder.php` lignes 28-30
- `app/Services/OrderGotPushNotificationBuilder.php` lignes 29-37

**Problème** : Spatie `User::role()` attend un **string** (nom du rôle), pas un **integer** (ID de l'enum).

**Remplacements effectués** :
| Avant | Après |
|-------|-------|
| `Role::ADMIN` (1) | `'Admin'` |
| `Role::BRANCH_MANAGER` (6) | `'Branch Manager'` |
| `Role::POS_OPERATOR` (7) | `'POS Operator'` |

**Impact** : Bug de production — les admins/managers/operators ne recevaient jamais les notifications de nouvelles commandes car `User::role(1)` retournait toujours une collection vide.

---

### Task 3 — Corriger CustomerAppResource null-safe

**Fichier** : `app/Http/Resources/CustomerAppResource.php`

**Lignes** : 30-31

**Fix** :
```php
// Avant:
"customer_app_logo" => $this->appImage('customer_app_logo')->logo,
"customer_app_splash_screen_logo" => $this->appImage(...)->splashScreenLogo,

// Après:
"customer_app_logo" => $this->appImage('customer_app_logo')?->logo ?? asset('images/customer-app/logo.png'),
"customer_app_splash_screen_logo" => $this->appImage(...)?->splashScreenLogo ?? asset('images/customer-app/splash.png'),
```

**Note** : Ce fichier n'était pas utilisé dans les tests actuels, mais aurait crashé en production si `CustomerAppSetting` était vide.

---

### Task 4 — Corriger T01 : structure JSON

**Fichier** : `tests/Feature/AntiGravityTest.php`

**Ligne** : 78

**Root cause** :
- Le test cherchait `$response->json('data')['token']`
- Mais `KioskMachineLoginController::login()` retourne `{message, token, kiosk}` à la racine
- `$response->json('data')` retournait `null` → PHPUnit crash

**Fix** : Remplacé `$response->json('data')` par `$response->json()` pour chercher à la racine.

---

## Résumé des changements

| Fichier | Changement | Lignes |
|---------|------------|--------|
| `tests/TestCase.php` | Supprimé seeding `theme_settings`, ajouté 3 keys theme dans `settings` | 35-58 |
| `app/Services/OrderGotMailNotificationBuilder.php` | `Role::ADMIN` → `'Admin'`, `Role::BRANCH_MANAGER` → `'Branch Manager'` | 30-32 |
| `app/Services/OrderGotSmsNotificationBuilder.php` | Même remplacements | 28-30 |
| `app/Services/OrderGotPushNotificationBuilder.php` | Même remplacements + `Role::POS_OPERATOR` → `'POS Operator'` | 29-37 |
| `app/Http/Resources/CustomerAppResource.php` | Null-safe sur logo et splashScreenLogo | 30-31 |
| `tests/Feature/AntiGravityTest.php` | Fix assertion T01 : `json('data')` → `json()` | 78 |

---

## Bugs de production corrigés

1. **Notifications par email/SMS/push** : Les admins recevront maintenant les notifications de nouvelles commandes (le `User::role(integer)` retournait toujours vide)
2. **CustomerAppResource** : Ne crashera plus si la table `customer_app_settings` est vide

---

## Vérification attendue par Playwright / E2E verification

```bash
php artisan test tests/Feature/AntiGravityTest.php --verbose
```

**Objectif** : **18/18 tests verts** (100%)

---

## Notes

Si T05/T06 continuent de crasher malgré ce fix, vérifier que :
1. La table `settings` est bien créée par les migrations
2. `config('settings.repositories.database.table')` retourne bien `'settings'`
3. Les keys `theme_favicon_logo`, `theme_logo`, `theme_footer_logo` sont bien insérées avec `payload = json_encode(null)`
