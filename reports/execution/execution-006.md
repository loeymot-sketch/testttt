# Rapport d'Exécution 006

## Date
Mars 2026

## Agent
Kimi (Builder)

## Références
- Plan: Plan 005 — Fix Null-Safe Complet + Régression T01
- QA Report: report-006.md (14/18 tests verts)

---

## Tâches exécutées

### Task 1 — SettingResource null-safe complet

**Fichier modifié** : `app/Http/Resources/SettingResource.php`

**Lignes corrigées** : 46-48

**Problème** : Les accessors `theme_logo` et `theme_footer_logo` utilisaient `->logo` et `->footerLogo` sans opérateur null-safe, causant des crashes "Attempt to read property on null" quand la DB est vide.

**Solution** : Appliquer le pattern `?->` avec fallback `?? asset(...)` cohérent avec `faviconLogo` ligne 48.

```php
// Avant:
'theme_logo'         => $this->themeImage('theme_logo')->logo,
'theme_footer_logo'  => $this->themeImage('theme_footer_logo')->footerLogo,
'theme_favicon_logo' => $this->themeImage('theme_favicon_logo')?->faviconLogo ?? asset(...),

// Après:
'theme_logo'         => $this->themeImage('theme_logo')?->logo ?? asset('images/theme/theme-logo.png'),
'theme_footer_logo'  => $this->themeImage('theme_footer_logo')?->footerLogo ?? asset('images/theme/theme-footer-logo.png'),
'theme_favicon_logo' => $this->themeImage('theme_favicon_logo')?->faviconLogo ?? asset('images/theme/theme-favicon-logo.png'),
```

---

### Task 2 — ThemeResource null-safe complet

**Fichier modifié** : `app/Http/Resources/ThemeResource.php`

**Lignes corrigées** : 29-31

**Problème identique** : `->logo` et `->footerLogo` sans protection null-safe.

**Solution** : Même pattern appliqué avec fallbacks appropriés.

---

### Task 3 — Correction régression T01 (Kiosk login 400)

**Fichier modifié** : `tests/Feature/AntiGravityTest.php`

**Lignes corrigées** : 69-78 (test_t01), 80-87 (test_t02)

**Root cause** : Le middleware `ApiKeyMiddleware` exige le header `x-api-key` sur toutes les routes `/api/auth/*`. Les tests T01 et T02 ne l'incluaient pas, causant un 400 avant d'atteindre le controller.

**Solution** : Ajouter `->withHeader('x-api-key', $this->apiKey())` aux appels de test.

```php
// Avant:
$response = $this->postJson('/api/auth/kiosk-login', [...]);

// Après:
$response = $this->withHeader('x-api-key', $this->apiKey())
    ->postJson('/api/auth/kiosk-login', [...]);
```

---

### Task 4 — Correction T13 (PENDING→ACCEPT crash)

**Fichier modifié** : `tests/TestCase.php`

**Root cause analysée** : `OrderService::changeStatus()` crée un `ActionLog` et dispatch des events de notification (SendOrderMail, SendOrderSms, SendOrderPush). Ces opérations nécessitent:
1. La table `action_logs` existe (migration OK)
2. Des company settings pour les templates de notification

**Solution** : Ajouter les settings requis dans `seedMinimalSettings()`:
- `company_name` — pour les templates email/SMS
- `company_email` — pour l'expéditeur
- `company_phone` — pour le contact
- `site_copyright` — pour le footer

---

## Résumé des changements

| Fichier | Changement | Lignes |
|---------|------------|--------|
| `app/Http/Resources/SettingResource.php` | Null-safe sur theme_logo, theme_footer_logo | 46-48 |
| `app/Http/Resources/ThemeResource.php` | Null-safe sur theme_logo, theme_footer_logo | 29-31 |
| `tests/Feature/AntiGravityTest.php` | Ajout header x-api-key à T01, T02 | 69-87 |
| `tests/TestCase.php` | Ajout company settings pour notifications | 42-45 |

---

## Statut attendu post-exécution

- **T05/T06** : PASS (plus de crash faviconLogo/theme_logo)
- **T01** : PASS (header api-key présent)
- **T02** : PASS (header api-key présent)
- **T13** : PASS (settings notifications disponibles)

Objectif : **18/18 tests verts** (100%)

---

## Notes pour Anti-Gravity

```bash
php artisan test tests/Feature/AntiGravityTest.php --verbose
```

Si T13 continue de crasher, vérifier que:
1. La migration `create_action_logs_table` est bien dans `database/migrations/`
2. Les listeners de notification (SendOrderMail, etc.) ne lèvent pas d'exception non catchée
3. Le champ `order_serial_no` est bien peuplé par OrderFactory
