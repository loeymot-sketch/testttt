# KIMI SPRINT 7 — GESTION PRODUITS ADMIN COMPLÈTE
**Émetteur :** Claude (Architecte)
**Destinataire :** KIMI (Builder)
**Priorité :** 🟡 P1 — Gestion produits incomplète depuis l'admin
**Test type :** Kimi-test (PHPUnit)
**Dépendances :** Aucune — peut démarrer en parallèle avec Sprint 6

---

## CONTEXTE ARCHITECTURAL (lire avant de coder)

La structure DB est correcte : `item_extras` et `item_addons` existent et sont utilisés par le wizard POS.
**MAIS** : `ItemService::store()` et `update()` ne gèrent que les variations. Les extras et addons ne peuvent pas être créés/modifiés depuis l'interface admin.

De plus, les colonnes `wizard_template`, `has_menu`, `default_menu_kiosk`, `sauce_included_menu` existent en DB (migration `2026_03_12_080617`) mais ne sont pas exposées dans le formulaire de catégorie.

**Règle absolue :** Ne pas modifier la structure des tables. Ne pas créer de nouvelles migrations sauf si absolument nécessaire. Travailler uniquement dans les services, requests et composants Vue.

---

## BUG S7-01 — Extras non gérés dans `ItemService::store()` et `update()`

### Diagnostic précis

**Fichier :** `app/Services/ItemService.php`
**Lignes 116-137 :** `store()` — crée item + variations seulement
**Lignes 142-189 :** `update()` — met à jour item + variations seulement

**Problème :** Les extras (garnitures, suppléments) ne peuvent pas être créés/modifiés depuis l'interface admin. Ils sont créés uniquement par le MenuSeeder.

**Modèle à suivre :** La gestion des variations (lignes 124-129 dans store, lignes 150-180 dans update) — reproduire le même pattern pour les extras.

### Correction exacte — `store()`

**Dans `app/Services/ItemService.php`, dans la méthode `store()`, après le bloc `if ($request->variations)` (après la ligne 129) :**

```php
// [SPRINT 7] Gestion des extras
if ($request->extras) {
    $extras = $this->safeJsonDecode($request->extras, true);
    if ($extras !== null) {
        foreach ($extras as $extra) {
            \App\Models\ItemExtra::create([
                'item_id' => $this->item->id,
                'name'    => $extra['name'],
                'price'   => $extra['price'] ?? 0,
                'status'  => $extra['status'] ?? \App\Enums\Status::ACTIVE,
            ]);
        }
    }
}
```

### Correction exacte — `update()`

**Dans `app/Services/ItemService.php`, dans la méthode `update()`, après le bloc `if ($request->variations)` (après la ligne 181), ajouter :**

```php
// [SPRINT 7] Gestion des extras — diff sync
if ($request->extras !== null) {
    $decodedExtras = $this->safeJsonDecode($request->extras, true);
    if ($decodedExtras !== null) {
        $extraIdsToKeep = [];
        foreach ($decodedExtras as $extra) {
            if (isset($extra['id'])) {
                // Mettre à jour l'extra existant
                \App\Models\ItemExtra::where('id', $extra['id'])->update([
                    'name'   => $extra['name'],
                    'price'  => $extra['price'] ?? 0,
                    'status' => $extra['status'] ?? \App\Enums\Status::ACTIVE,
                ]);
                $extraIdsToKeep[] = $extra['id'];
            } else {
                // Créer un nouvel extra
                $newExtra = \App\Models\ItemExtra::create([
                    'item_id' => $item->id,
                    'name'    => $extra['name'],
                    'price'   => $extra['price'] ?? 0,
                    'status'  => $extra['status'] ?? \App\Enums\Status::ACTIVE,
                ]);
                $extraIdsToKeep[] = $newExtra->id;
            }
        }
        // Supprimer les extras qui ne sont plus dans la liste
        \App\Models\ItemExtra::where('item_id', $item->id)
            ->whereNotIn('id', $extraIdsToKeep)
            ->delete();
    }
}
```

---

## BUG S7-02 — `ItemRequest` — ajouter validation du champ `extras`

**Fichier :** `app/Http/Requests/ItemRequest.php`

**Dans la méthode `rules()`, ajouter :**

```php
// AVANT (ligne 44)
'variations' => ['nullable', 'json'],

// APRÈS
'variations' => ['nullable', 'json'],
'extras'     => ['nullable', 'json'],  // [SPRINT 7]
```

---

## BUG S7-03 — `ItemAddon` cast mismatch

### Diagnostic précis

**Fichier :** `app/Models/ItemAddon.php`
**Ligne 19 :** `'addon_item_variation' => 'string',`

**Problème :** La colonne `addon_item_variation` est définie comme `json` dans la migration mais castée comme `string` dans le modèle. Quand on lit un `ItemAddon`, le JSON n'est pas auto-décodé — on obtient une chaîne brute.

### Correction exacte

```php
// AVANT (ligne 19)
'addon_item_variation' => 'string',

// APRÈS
'addon_item_variation' => 'array',
```

---

## BUG S7-04 — Champs wizard dans le formulaire de catégorie

### Diagnostic précis

**Fichier :** `resources/js/components/admin/settings/ItemCategory/ItemCategoryCreateComponent.vue`

**Problème :** Les colonnes `wizard_template`, `has_menu`, `default_menu_kiosk`, `sauce_included_menu` existent en DB mais ne sont pas dans le formulaire. Impossible de configurer le wizard d'une catégorie depuis l'admin.

**Vérification préalable :** Vérifier que `ItemCategoryRequest.php` accepte ces champs. Si non, les ajouter.

### Correction exacte — Vue Component

**Dans `ItemCategoryCreateComponent.vue`, dans le `<form>`, après le champ `description` (avant les boutons) :**

```html
<!-- [SPRINT 7] Wizard template pour le POS et la borne -->
<div class="form-col-12 sm:form-col-6">
    <label for="wizard_template" class="db-field-title">{{ $t('label.wizard_template') || 'Type de wizard' }}</label>
    <select v-model="props.form.wizard_template" id="wizard_template" class="db-field-control">
        <option value="simple">Simple (pas de wizard)</option>
        <option value="tacos">Tacos</option>
        <option value="sandwich">Sandwich</option>
        <option value="burger">Burger</option>
        <option value="assiette">Assiette</option>
        <option value="salade">Salade</option>
        <option value="omelette">Omelette</option>
        <option value="snacking">Snacking (Wings/Tenders)</option>
    </select>
</div>

<div class="form-col-12 sm:form-col-6">
    <label class="db-field-title">{{ $t('label.has_menu') || 'Propose un menu (frites+boisson)' }}</label>
    <div class="db-field-radio-group">
        <div class="db-field-radio">
            <input :value="1" v-model="props.form.has_menu" type="radio" id="has_menu_yes" class="custom-radio-field">
            <label for="has_menu_yes" class="db-field-label">{{ $t('label.yes') }}</label>
        </div>
        <div class="db-field-radio">
            <input :value="0" v-model="props.form.has_menu" type="radio" id="has_menu_no" class="custom-radio-field">
            <label for="has_menu_no" class="db-field-label">{{ $t('label.no') }}</label>
        </div>
    </div>
</div>
```

**Dans le script du composant, dans `props.form`, ajouter les champs :**

```javascript
// Dans l'objet form initial (là où sont définis name, status, description, etc.)
wizard_template: 'simple',
has_menu: 0,
default_menu_kiosk: 0,
sauce_included_menu: 0,
```

### Correction exacte — ItemCategoryRequest

**Vérifier `app/Http/Requests/ItemCategoryRequest.php` et ajouter si absent :**

```php
'wizard_template'    => ['nullable', 'string', 'in:simple,tacos,sandwich,burger,assiette,salade,omelette,snacking'],
'has_menu'           => ['nullable', 'boolean'],
'default_menu_kiosk' => ['nullable', 'boolean'],
'sauce_included_menu'=> ['nullable', 'boolean'],
```

---

## TESTS OBLIGATOIRES Sprint 7

### Tests PHPUnit à créer

**Fichier :** `tests/Feature/ItemExtraManagementTest.php`

```php
<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Item;
use App\Models\ItemExtra;
use App\Models\ItemCategory;
use App\Enums\Status;
use Illuminate\Foundation\Testing\RefreshDatabase;

class ItemExtraManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();
        $this->seedSpatieRoles();
    }

    public function test_item_store_creates_extras(): void
    {
        $branch = \Database\Factories\BranchFactory::new()->create();
        $admin = \Database\Factories\UserFactory::new()->create(['branch_id' => $branch->id]);
        $admin->assignRole('Admin');
        $token = $admin->createToken('test')->plainTextToken;

        $category = ItemCategory::create(['name' => 'Test Cat', 'slug' => 'test-cat', 'status' => Status::ACTIVE]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
            'x-api-key' => config('app.api_key', 'test-api-key'),
        ])->postJson('/api/v1/admin/item', [
            'name' => 'Test Item',
            'item_category_id' => $category->id,
            'item_type' => 1,
            'price' => 10.00,
            'is_featured' => 1,
            'status' => Status::ACTIVE,
            'order' => 1,
            'extras' => json_encode([
                ['name' => 'Supplément Fromage', 'price' => 1.00, 'status' => Status::ACTIVE],
                ['name' => 'Supplément Jambon', 'price' => 1.00, 'status' => Status::ACTIVE],
            ]),
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('item_extras', ['name' => 'Supplément Fromage']);
        $this->assertDatabaseHas('item_extras', ['name' => 'Supplément Jambon']);
    }

    public function test_item_update_syncs_extras(): void
    {
        $branch = \Database\Factories\BranchFactory::new()->create();
        $admin = \Database\Factories\UserFactory::new()->create(['branch_id' => $branch->id]);
        $admin->assignRole('Admin');
        $token = $admin->createToken('test')->plainTextToken;

        $category = ItemCategory::create(['name' => 'Test Cat 2', 'slug' => 'test-cat-2', 'status' => Status::ACTIVE]);
        $item = Item::create([
            'name' => 'Test Item 2', 'slug' => 'test-item-2',
            'item_category_id' => $category->id, 'item_type' => 1,
            'price' => 10.00, 'is_featured' => 1, 'status' => Status::ACTIVE, 'order' => 1,
        ]);
        $extra = ItemExtra::create(['item_id' => $item->id, 'name' => 'Old Extra', 'price' => 1.00, 'status' => Status::ACTIVE]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
            'x-api-key' => config('app.api_key', 'test-api-key'),
        ])->putJson("/api/v1/admin/item/{$item->id}", [
            'name' => 'Test Item 2',
            'item_category_id' => $category->id,
            'item_type' => 1,
            'price' => 10.00,
            'is_featured' => 1,
            'status' => Status::ACTIVE,
            'order' => 1,
            'extras' => json_encode([
                ['name' => 'New Extra', 'price' => 2.00, 'status' => Status::ACTIVE],
            ]),
        ]);

        $response->assertStatus(200);
        // Old extra should be deleted
        $this->assertDatabaseMissing('item_extras', ['id' => $extra->id, 'deleted_at' => null]);
        // New extra should exist
        $this->assertDatabaseHas('item_extras', ['item_id' => $item->id, 'name' => 'New Extra']);
    }
}
```

---

## VÉRIFICATIONS DE SYNCHRONISATION Sprint 7

1. `php artisan test --filter=ItemExtraManagementTest` — 2 tests PASS
2. `php artisan test` — 0 régression
3. Vérifier que `ItemAddon::find(1)->addon_item_variation` retourne un array (pas une string)
4. Vérifier dans l'UI admin que le champ `wizard_template` apparaît dans le formulaire de catégorie
5. Créer une catégorie avec `wizard_template=tacos` et vérifier en DB que la valeur est sauvegardée

---

## RÉSUMÉ DES FICHIERS À MODIFIER

| Fichier | Modification |
|---------|--------------|
| `app/Services/ItemService.php` | Extras dans store() et update() |
| `app/Http/Requests/ItemRequest.php` | Validation champ extras |
| `app/Models/ItemAddon.php` | Cast array pour addon_item_variation |
| `app/Http/Requests/ItemCategoryRequest.php` | Validation champs wizard |
| `resources/js/components/admin/settings/ItemCategory/ItemCategoryCreateComponent.vue` | Champs wizard dans le formulaire |
| `tests/Feature/ItemExtraManagementTest.php` | NOUVEAU — tests |
