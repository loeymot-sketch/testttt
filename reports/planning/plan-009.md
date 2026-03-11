# Plan 009 — Sprint 6 : Fix Définitif T05/T06 (faviconLogo + group seeding)

## Date
2026-03-10

## Auteur
Claude (Lead Architect & Planning Agent)

## Contexte
Score actuel : **16/18**. Les 2 tests restants (T05 et T06) crashent avec `Attempt to read property "faviconLogo" on null`.

## Analyse Root Cause (Claude)

Après audit complet de la chaîne d'exécution, deux causes racines distinctes ont été identifiées :

### Cause 1 — Seeding sans `group` (Root Cause Principale)

Le package `smartisan/laravel-settings` filtre **obligatoirement** par la colonne `group` dans toutes ses requêtes SQL (voir `DatabaseRepository.php` ligne 154 : `->where('group', $group)`).

Notre `TestCase::seedMinimalSettings()` insère toutes les rows avec `group = null`. Cela signifie que :
- `Settings::group('theme')->all()` retourne un tableau vide
- `Settings::group('company')->all()` retourne un tableau vide
- `Settings::group('order_setup')->all()` retourne un tableau vide
- etc.

Quand `SettingResource::toArray()` accède à `$this->info['company_name']` (ligne 29), si le tableau est vide, PHP lève une `ErrorException: Undefined array key "company_name"`. Cette exception, non catchée dans le `Handler.php` (qui ne gère pas `ErrorException`), remonte jusqu'au `parent::render()` de Laravel qui peut rendre une vue HTML — et cette vue HTML peut contenir `$faviconLogo->faviconLogo` sans null-safe.

**Fix requis** : Ajouter la colonne `group` correcte à chaque row dans `TestCase::seedMinimalSettings()`.

### Cause 2 — Blade views non null-safe (Bug de Production)

Les 3 Blade views suivantes accèdent à `$faviconLogo->faviconLogo` sans opérateur null-safe :
- `resources/views/payment.blade.php` ligne 10
- `resources/views/paymentSuccess.blade.php` ligne 8
- `resources/views/paymentGateways/cashfree/cashfreeJs.blade.php` ligne 10

`PaymentController` passe maintenant `(object)['faviconLogo' => ...]` donc ces vues sont protégées en pratique, mais si jamais `$faviconLogo` est `null` (ex: erreur en amont), le crash se produit. Ce sont des bugs de production latents.

**Fix requis** : Utiliser `$faviconLogo?->faviconLogo ?? ''` dans ces 3 vues.

### Cause 3 — SettingResource accès direct sans null-coalescing (Bug de Production)

`SettingResource::toArray()` accède directement à `$this->info['company_name']`, `$this->info['company_email']`, etc. sans `?? null` ou `?? ''`. Si le tableau est vide (groupe non trouvé), PHP lève une `ErrorException`.

**Fix requis** : Ajouter `?? null` à chaque accès de tableau dans `SettingResource::toArray()`.

---

## Tâches pour Kimi

### Task 1 — Fix `TestCase::seedMinimalSettings()` : ajouter la colonne `group`

**Fichier** : `tests/TestCase.php`

**Changement** : Dans la méthode `seedMinimalSettings()`, ajouter la colonne `'group'` à chaque row insérée avec la valeur correcte correspondant au groupe `smartisan/settings` attendu.

Mapping des groupes :
- `site_title`, `favicon_logo`, `site_logo`, `site_copyright` → `group = 'site'`
- `currency`, `currency_symbol` → `group = 'site'` (ou le groupe réel — voir `ThemeTableSeeder` et `CompanyTableSeeder` pour confirmation)
- `order_prefix`, `order_setup_food_preparation_time`, `order_setup_takeaway`, `order_setup_delivery` → `group = 'order_setup'`
- `company_name`, `company_email`, `company_phone` → `group = 'company'`
- `site_copyright` → `group = 'site'`
- `theme_favicon_logo`, `theme_logo`, `theme_footer_logo` → `group = 'theme'`

**Avant** (exemple pour theme_favicon_logo) :
```php
['key' => 'theme_favicon_logo', 'payload' => json_encode(null), 'created_at' => now(), 'updated_at' => now()],
```

**Après** :
```php
['key' => 'theme_favicon_logo', 'payload' => json_encode(null), 'group' => 'theme', 'created_at' => now(), 'updated_at' => now()],
```

Kimi doit vérifier les groupes réels en lisant `database/seeders/ThemeTableSeeder.php`, `database/seeders/CompanyTableSeeder.php`, `database/seeders/SiteTableSeeder.php`, `database/seeders/OrderSetupTableSeeder.php` pour confirmer les noms de groupes exacts utilisés en production.

### Task 2 — Fix Blade views : null-safe sur `$faviconLogo`

**Fichiers** :
- `resources/views/payment.blade.php`
- `resources/views/paymentSuccess.blade.php`
- `resources/views/paymentGateways/cashfree/cashfreeJs.blade.php`

**Changement** : Remplacer `{{ $faviconLogo->faviconLogo }}` par `{{ $faviconLogo?->faviconLogo ?? '' }}` dans chaque fichier.

Note : En Blade PHP, `?->` est supporté depuis PHP 8. Vérifier que le projet utilise PHP 8+.

### Task 3 — Fix `SettingResource::toArray()` : null-coalescing sur les accès tableau

**Fichier** : `app/Http/Resources/SettingResource.php`

**Changement** : Ajouter `?? null` à chaque accès `$this->info['key']` pour éviter les `ErrorException: Undefined array key` si un groupe de settings est absent en test.

**Exemple** :
```php
// Avant
'company_name' => $this->info['company_name'],

// Après
'company_name' => $this->info['company_name'] ?? null,
```

Appliquer ce pattern à **toutes** les clés de `$this->info[...]` dans `toArray()`.

---

## Ordre d'exécution recommandé

1. **Task 1 en premier** (fix seeding) — c'est la root cause principale
2. **Task 3** (fix SettingResource) — défense en profondeur si le seeding est incomplet
3. **Task 2** (fix Blade views) — bug de production latent, moins urgent pour les tests

---

## Risques

- **Task 1** : Risque faible. Ajouter `group` au seeding ne casse rien. Si les noms de groupes sont incorrects, les settings ne seront toujours pas trouvés — mais l'erreur sera différente (plus de crash, juste des valeurs null).
- **Task 3** : Risque faible. Ajouter `?? null` ne change pas le comportement en production (les clés existent toujours en prod). Cela rend le code plus défensif en test.
- **Task 2** : Risque nul. Les Blade views ne sont pas appelées par les tests T05/T06.

---

## Tests suggérés

Après implémentation, Anti-Gravity doit :
1. Relancer la suite complète `AntiGravityTest`
2. Vérifier que T05 retourne 401 ou 403 (pas 500)
3. Vérifier que T06 retourne 200 ou 201 (pas 500)
4. Confirmer que les 16 tests déjà verts restent verts

---

## Fichiers à modifier

| Fichier | Type de changement |
|---|---|
| `tests/TestCase.php` | Ajouter colonne `group` au seeding |
| `app/Http/Resources/SettingResource.php` | Null-coalescing sur accès tableau |
| `resources/views/payment.blade.php` | Null-safe sur `$faviconLogo` |
| `resources/views/paymentSuccess.blade.php` | Null-safe sur `$faviconLogo` |
| `resources/views/paymentGateways/cashfree/cashfreeJs.blade.php` | Null-safe sur `$faviconLogo` |
