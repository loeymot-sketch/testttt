# PLAN_11 — ARCH-01 : Enrichir ItemCategory — wizard_template, has_menu, default_menu_kiosk
**Phase :** P3 — Architecture (long terme)
**Test-Type :** local-validation
**Impact :** 🔵 Architecture — Remplace le hardcode JS par une config pilotée par la DB
**Fichiers :**
- `database/migrations/XXXX_add_wizard_config_to_item_categories.php` (nouvelle migration)
- `app/Models/ItemCategory.php`
- `app/Http/Controllers/Admin/ItemCategoryController.php`
- `app/Http/Requests/ItemCategoryRequest.php`
- Resources/views admin (formulaire catégorie)

---

## 1. Contexte & Problème

**Constat du rapport (section 10.4) :** ~90% de la logique produit est **codée en dur** dans
`pos-wizard.js` via `detectCategory()` (string matching sur le nom de la catégorie).

Pour ajouter une nouvelle catégorie "Wrap", il faut modifier du JavaScript — pas acceptable
en production stable.

### Schéma actuel (problématique)
```javascript
// pos-wizard.js — hardcodé
if (cat.includes('tacos')) return 'tacos';
if (cat.includes('sandwich')) return 'sandwich';
```

### Schéma cible (piloté par DB)
```sql
item_categories:
  + wizard_template     VARCHAR(20)  -- 'tacos' | 'sandwich' | 'assiette' | 'salade' | 'simple'
  + has_menu            BOOLEAN      -- propose frites+boisson ?
  + default_menu_kiosk  BOOLEAN      -- présélectionner menu en Kiosk ?
  + sauce_included_menu BOOLEAN      -- sauce frites incluse dans le menu ?
```

---

## 2. Fichiers à Créer / Modifier

| Fichier | Action |
|---------|--------|
| `database/migrations/...add_wizard_config_to_item_categories.php` | Créer migration |
| `app/Models/ItemCategory.php` | Ajouter fillable + casts |
| `app/Http/Controllers/Admin/ItemCategoryController.php` | CRUD update + resource |
| `app/Http/Requests/ItemCategoryRequest.php` | Validation nouveaux champs |
| Vue admin (formulaire catégorie) | Ajouter les champs dans l'UI |

---

## 3. Implémentation

### 3.1 Migration

```bash
php artisan make:migration add_wizard_config_to_item_categories --table=item_categories
```

**Contenu de la migration :**
```php
public function up(): void
{
    Schema::table('item_categories', function (Blueprint $table) {
        $table->string('wizard_template', 20)->default('simple')
              ->comment('tacos|sandwich|burger|assiette|salade|omelette|snacking|simple')
              ->after('description');
        $table->boolean('has_menu')->default(false)
              ->comment('Cette catégorie propose un menu frites+boisson')
              ->after('wizard_template');
        $table->boolean('default_menu_kiosk')->default(false)
              ->comment('En Kiosk, présélectionner menu par défaut')
              ->after('has_menu');
        $table->boolean('sauce_included_menu')->default(false)
              ->comment('La sauce frites est-elle incluse dans le menu ?')
              ->after('default_menu_kiosk');
    });
}

public function down(): void
{
    Schema::table('item_categories', function (Blueprint $table) {
        $table->dropColumn(['wizard_template', 'has_menu', 'default_menu_kiosk', 'sauce_included_menu']);
    });
}
```

### 3.2 Seeder — Remplir les catégories existantes

**Après la migration**, mettre à jour les catégories existantes :

```php
// database/seeders/ItemCategoryWizardSeeder.php
$configs = [
    'Nos Tacos'                  => ['wizard_template' => 'tacos',     'has_menu' => true,  'default_menu_kiosk' => true],
    'Nos Sandwichs'              => ['wizard_template' => 'sandwich',  'has_menu' => true,  'default_menu_kiosk' => false],
    'Nos Burgers'                => ['wizard_template' => 'burger',    'has_menu' => true,  'default_menu_kiosk' => false],
    'Nos Assiettes'              => ['wizard_template' => 'assiette',  'has_menu' => false, 'default_menu_kiosk' => false],
    'Ojja'                       => ['wizard_template' => 'simple',    'has_menu' => false],
    'Omelettes'                  => ['wizard_template' => 'omelette',  'has_menu' => false],
    'Nos Salades'                => ['wizard_template' => 'salade',    'has_menu' => false],
    'Chicken & Tenders'          => ['wizard_template' => 'snacking',  'has_menu' => false],
    'Frites & Accompagnements'   => ['wizard_template' => 'simple',    'has_menu' => false],
    'Nos Desserts'               => ['wizard_template' => 'simple',    'has_menu' => false],
    'Nos Boissons'               => ['wizard_template' => 'simple',    'has_menu' => false],
];

foreach ($configs as $name => $config) {
    DB::table('item_categories')
        ->where('name', $name)
        ->update(array_merge(['updated_at' => now()], $config));
}
```

```bash
php artisan make:seeder ItemCategoryWizardSeeder
php artisan db:seed --class=ItemCategoryWizardSeeder
```

### 3.3 Model ItemCategory.php

```php
// Ajouter dans $fillable
protected $fillable = [
    // ... existants
    'wizard_template',
    'has_menu',
    'default_menu_kiosk',
    'sauce_included_menu',
];

// Ajouter $casts
protected $casts = [
    // ... existants
    'has_menu'            => 'boolean',
    'default_menu_kiosk'  => 'boolean',
    'sauce_included_menu' => 'boolean',
];
```

### 3.4 Controller/Request — Ajouter validation

```php
// Dans ItemCategoryRequest rules()
'wizard_template'     => ['required', 'string', Rule::in(['tacos','sandwich','burger','assiette','salade','omelette','snacking','simple'])],
'has_menu'            => ['boolean'],
'default_menu_kiosk'  => ['boolean'],
'sauce_included_menu' => ['boolean'],
```

### 3.5 API — Exposer les champs dans ItemCategoryResource

```php
// Dans app/Http/Resources/ItemCategoryResource.php
return [
    // ... existants
    'wizard_template'     => $this->wizard_template,
    'has_menu'            => $this->has_menu,
    'default_menu_kiosk'  => $this->default_menu_kiosk,
    'sauce_included_menu' => $this->sauce_included_menu,
];
```

---

## 4. Tests

### 4.1 Test migration
```bash
php artisan migrate
php artisan db:seed --class=ItemCategoryWizardSeeder
php artisan tinker --execute="echo json_encode(DB::table('item_categories')->get(['name','wizard_template','has_menu','default_menu_kiosk']));"
```

### 4.2 Test PHPUnit
```php
/** @test */
public function item_category_has_wizard_template_field()
{
    $category = ItemCategory::where('name', 'Nos Tacos')->first();
    $this->assertEquals('tacos', $category->wizard_template);
    $this->assertTrue($category->has_menu);
    $this->assertTrue($category->default_menu_kiosk);
}

/** @test */
public function item_category_api_returns_wizard_config()
{
    $response = $this->getJson('/api/admin/item-category');
    $response->assertStatus(200);
    $response->assertJsonFragment(['wizard_template' => 'tacos']);
}
```

---

## 5. Critères de Succès

- [ ] Migration passe sans erreur
- [ ] Seeder rempli 11 catégories avec `wizard_template` correct
- [ ] API `/api/admin/item-category` retourne les nouveaux champs
- [ ] Admin peut modifier `wizard_template` via l'UI (ou direct DB pour l'instant)
- [ ] Tests PHPUnit passent
- [ ] 0 régression sur les catégories existantes

---

## 6. NE PAS Toucher (encore)

- La logique de `detectCategory()` dans `pos-wizard.js` — c'est PLAN_12 qui la remplacera
- La logique Flutter du Kiosk — PLAN_12 également
- Les items existants — pas de modification sur la table `items`
