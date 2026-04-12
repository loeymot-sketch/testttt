# PLAN_04 — MA-001 + MA-002 : Null-Safe payment.blade.php + SettingResource
**Phase :** P1 — Haute
**Test-Type :** local-validation
**Risque :** 🔴 Rupture d'affichage en production si settings non configurés
**Fichiers :**
- `resources/views/payment.blade.php`
- `app/Http/Resources/SettingResource.php`

---

## 1. Contexte & Problème

### MA-001 — payment.blade.php faviconLogo null
Le template de paiement accède à `$settings->faviconLogo` (ou similaire) sans null-check,
ce qui provoque une erreur PHP si la valeur n'est pas définie en base.

**Symptôme :** Page de paiement crashe ou affiche une erreur PHP.

### MA-002 — SettingResource null
`SettingResource.php` retourne des valeurs null pour certaines clés sans fallback,
causant des erreurs JavaScript côté frontend.

---

## 2. Fichiers à Modifier

| Fichier | Problème | Fix |
|---------|---------|-----|
| `resources/views/payment.blade.php` | `$settings->faviconLogo` sans null check | `$settings->faviconLogo ?? ''` |
| `app/Http/Resources/SettingResource.php` | Valeurs null retournées | Fallbacks sur toutes les clés |

---

## 3. Implémentation

### 3.1 payment.blade.php — Null-Safe

Chercher toutes les occurrences de `$settings->` dans ce fichier et ajouter `?? ''` ou `?? null`.

**Pattern général :**
```php
// AVANT
<link rel="icon" href="{{ $settings->faviconLogo }}">
<title>{{ $settings->companyName }}</title>

// APRÈS — null-safe
<link rel="icon" href="{{ $settings->faviconLogo ?? asset('images/favicon.png') }}">
<title>{{ $settings->companyName ?? 'LeCayenne' }}</title>
```

**Toutes les occurrences à sécuriser :**
- `$settings->faviconLogo` → `$settings->faviconLogo ?? ''`
- `$settings->logo` → `$settings->logo ?? ''`
- `$settings->companyName` → `$settings->companyName ?? config('app.name', 'Restaurant')`
- `$settings->companyEmail` → `$settings->companyEmail ?? ''`
- `$settings->currency` → `$settings->currency ?? '€'`
- `$settings->currencySymbol` → `$settings->currencySymbol ?? '€'`

### 3.2 SettingResource.php — Fallbacks complets

Trouver `toArray()` dans SettingResource.php et ajouter des valeurs par défaut :

```php
public function toArray($request): array
{
    return [
        // Theme
        'theme_logo'          => $this->theme_logo ?? '',
        'theme_favicon_logo'  => $this->theme_favicon_logo ?? '',
        'theme_footer_logo'   => $this->theme_footer_logo ?? '',

        // Company
        'company_name'        => $this->company_name ?? config('app.name', 'Restaurant'),
        'company_email'       => $this->company_email ?? '',
        'company_phone'       => $this->company_phone ?? '',
        'company_address'     => $this->company_address ?? '',

        // Currency
        'currency_symbol'     => $this->currency_symbol ?? '€',
        'currency_code'       => $this->currency_code ?? 'EUR',

        // All other existing fields...
        // ... garder TOUTES les clés existantes, juste ajouter ?? '' pour les strings, ?? 0 pour les numbers
    ];
}
```

---

## 4. Tests

### 4.1 Test Feature
```php
/** @test */
public function payment_page_renders_without_settings_configured()
{
    // Vider les settings (simule un fresh install)
    Settings::forget('theme_favicon_logo');

    $response = $this->get('/payment');
    $response->assertStatus(200); // Pas de 500
    $response->assertDontSee('ErrorException');
}

/** @test */
public function setting_resource_has_no_null_values_in_required_fields()
{
    $response = $this->getJson('/api/settings');
    $response->assertStatus(200);
    $data = $response->json('data');
    $this->assertNotNull($data['currency_symbol']);
    $this->assertNotNull($data['company_name']);
}
```

### 4.2 Commande
```bash
php artisan test --filter="payment_page_renders\|setting_resource_has_no_null"
```

---

## 5. Critères de Succès

- [ ] Page `/payment` charge sans erreur même sans settings en DB
- [ ] `SettingResource` ne retourne jamais `null` pour les champs critiques (currency_symbol, company_name)
- [ ] Tests passent
- [ ] `php artisan test` : 0 régression

---

## 6. NE PAS Toucher

- La logique de récupération des settings depuis la DB (Smartisan Settings)
- Les autres views Blade (ne modifier que payment.blade.php)
- Les valeurs des settings en base de données
