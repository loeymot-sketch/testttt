# KIMI SPRINT 11 — CORRECTION FIXTURES DE TESTS

**Architecte :** Claude (Lead)
**Destinataire :** KIMI (Builder)
**Priorite :** P1 — Qualite / CI
**Risque applicatif :** NUL — toutes les corrections sont dans les tests, factories et phpunit.xml

---

## Contexte

41 tests PHP echouent. L'analyse de Claude identifie **7 causes racines**, toutes dans l'infrastructure de test. Aucune ne necessite de modifier le code applicatif.

```
Fichiers applicatifs a modifier : 0
Fichiers de test/config a modifier : 7-8
Fichiers a creer : 1 (CouponFactory)
```

---

## Causes racines et corrections

### CR1 — ItemFactory : colonnes inexistantes

**Fichiers concernes :** `tests/Feature/SecurityComprehensiveTest.php`, `tests/Feature/AdminCrudComprehensiveTest.php`

Les tests passent `'branch_id'` et `'category_id'` a `ItemFactory::new()->create([...])`. La table `items` n'a pas de colonne `branch_id` ni `category_id` — la colonne correcte est `item_category_id`.

**Correction :** Dans chaque appel `Item::factory()->create([...])` de ces fichiers, remplacer `'category_id' => ...` par `'item_category_id' => ...` et supprimer `'branch_id' => ...`.

---

### CR2 — UserFactory : colonne `role` inexistante

**Fichier concerne :** `tests/Feature/SecurityComprehensiveTest.php` methode `setupCustomer()` ligne ~59

La table `users` n'a pas de colonne `role`. Les roles sont geres par Spatie via la table pivot `model_has_roles`.

**Correction :** Supprimer `'role' => \App\Enums\Role::CUSTOMER` de l'appel factory dans `setupCustomer()`. Le `$customer->assignRole('Customer')` a la ligne suivante est deja correct.

---

### CR3 — `MIX_API_KEY` absent de phpunit.xml

**Fichier concerne :** `phpunit.xml`

Le middleware `ApiKeyMiddleware` lit `env('MIX_API_KEY')` qui retourne `null` en test. Les tests envoient `x-api-key: test-api-key` mais le middleware compare avec `null` → rejet 400.

**Correction :** Ajouter dans la section `<php>` de `phpunit.xml` :

```xml
<env name="MIX_API_KEY" value="test-api-key"/>
```

---

### CR4 — Tests AntiGravity avec emails hardcodes de production

**Fichiers concernes :**
- `tests/Feature/AntiGravityFinalTest.php`
- `tests/Feature/AntiGravityLoginRedirectionTest.php`
- `tests/Feature/AntiGravityManualTest.php`

Ces tests font `User::where('email', 'admin@lecayenne-henin-beaumont.fr')->first()` — ils cherchent des utilisateurs qui existent en production mais pas dans la DB SQLite in-memory.

**Correction :** Remplacer les lookups par email hardcode par des creations via factory :

```php
// Avant
$admin = User::where('email', 'admin@lecayenne-henin-beaumont.fr')->first();

// Apres
$admin = User::factory()->create(['email' => 'admin@test.com']);
$admin->assignRole('Admin');
```

---

### CR5 — `TestCase::seedSpatieRoles()` : permissions Admin insuffisantes

**Fichier concerne :** `tests/TestCase.php` methode `seedSpatieRoles()`

En test, Admin n'a que 4 permissions. En production, Admin a `Permission::all()`. Les controllers verifient des permissions granulaires (`items_create`, `settings`, `dining_tables_create`, etc.) qui ne sont pas assignees en test.

**Correction :** Dans `seedSpatieRoles()`, apres avoir cree toutes les permissions, ajouter :

```php
$adminRole->givePermissionTo(Permission::all());
```

---

### CR6 — `CouponFactory` et `CouponDiscountType` inexistants

**Fichiers concernes :** `tests/Feature/AdminCrudComprehensiveTest.php`

**Correction :**

Creer `database/factories/CouponFactory.php` :

```php
<?php
namespace Database\Factories;
use App\Enums\Status;
use Illuminate\Database\Eloquent\Factories\Factory;

class CouponFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name'          => $this->faker->words(2, true),
            'code'          => strtoupper($this->faker->lexify('????-????')),
            'discount'      => $this->faker->randomFloat(2, 5, 50),
            'discount_type' => 'percentage',
            'minimum_order' => 0,
            'maximum_discount' => 0,
            'start_date'    => now(),
            'end_date'      => now()->addDays(30),
            'status'        => Status::ACTIVE,
        ];
    }
}
```

Dans `AdminCrudComprehensiveTest`, remplacer `CouponDiscountType::PERCENTAGE` par la string `'percentage'` ou l'enum existant `DiscountType::PERCENTAGE`.

---

### CR7 — `SyncComprehensiveTest` : payload table order incomplet

**Fichier concerne :** `tests/Feature/SyncComprehensiveTest.php` test `test_table_order_appears_in_kds`

La route `POST /api/table/dining-order` requiert `customer_id`, `branch_id`, `is_advance_order`.

**Correction :** Ajouter ces champs dans le payload du test :

```php
$payload = [
    // ... champs existants ...
    'customer_id'      => $customer->id,
    'branch_id'        => $branch->id,
    'is_advance_order' => 0,
];
```

---

### CR7b — `AdminCrudComprehensiveTest` : mauvaise URL pour items

**Fichier concerne :** `tests/Feature/AdminCrudComprehensiveTest.php`

Le test utilise `POST /api/admin/setting/item` mais la route correcte est `POST /api/admin/item`.

**Correction :** Remplacer `/api/admin/setting/item` par `/api/admin/item` dans le test.

---

### CR8 — `DiningTableFactory` : branch_id hardcode

**Fichier concerne :** `database/factories/DiningTableFactory.php`

`'branch_id' => 1` hardcode echoue si la branch 1 n'existe pas dans la DB de test.

**Correction :** Remplacer par `'branch_id' => \App\Models\Branch::factory()`.

---

## Ordre d'execution pour Kimi

```
Etape 1 : phpunit.xml (CR3) — debloque ~8 tests immediatement
    ↓
Etape 2 : tests/TestCase.php seedSpatieRoles (CR5) — debloque ~10 tests CRUD
    ↓
Etape 3 : SecurityComprehensiveTest.php (CR1 + CR2)
Etape 4 : SyncComprehensiveTest.php (CR7)
Etape 5 : AdminCrudComprehensiveTest.php (CR1 + CR6 + CR7b)
Etape 6 : database/factories/CouponFactory.php (CR6) — NOUVEAU
Etape 7 : database/factories/DiningTableFactory.php (CR8)
Etape 8 : AntiGravityFinalTest.php + AntiGravityLoginRedirectionTest.php + AntiGravityManualTest.php (CR4)
```

---

## Tests de validation

Apres chaque etape, Kimi doit executer :

```bash
php artisan test --filter=SecurityComprehensiveTest
php artisan test --filter=SyncComprehensiveTest
php artisan test --filter=AdminCrudComprehensiveTest
php artisan test --filter=KDSFlowTest
php artisan test --filter=KioskSecurityTest
```

**Objectif final :** `php artisan test` → 0 failures, 0 errors

---

## Regles strictes pour Kimi

- NE PAS modifier de fichiers dans `app/` (controllers, services, models, enums)
- NE PAS modifier de fichiers dans `routes/`
- NE PAS modifier `phpunit.xml` au-dela de l'ajout de `MIX_API_KEY`
- Corrections uniquement dans `tests/`, `database/factories/`, `phpunit.xml`
- Si un test necessite une modification de logique applicative, STOP et signaler a Claude

---

## Fichiers a modifier/creer

| Fichier | Action | Cause |
|---------|--------|-------|
| `phpunit.xml` | Modifier | CR3 |
| `tests/TestCase.php` | Modifier | CR5 |
| `tests/Feature/SecurityComprehensiveTest.php` | Modifier | CR1, CR2 |
| `tests/Feature/SyncComprehensiveTest.php` | Modifier | CR7 |
| `tests/Feature/AdminCrudComprehensiveTest.php` | Modifier | CR1, CR6, CR7b |
| `tests/Feature/AntiGravityFinalTest.php` | Modifier | CR4 |
| `tests/Feature/AntiGravityLoginRedirectionTest.php` | Modifier | CR4 |
| `tests/Feature/AntiGravityManualTest.php` | Modifier | CR4 |
| `database/factories/CouponFactory.php` | CREER | CR6 |
| `database/factories/DiningTableFactory.php` | Modifier | CR8 |
