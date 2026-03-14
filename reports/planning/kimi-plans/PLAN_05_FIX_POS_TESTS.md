# PLAN_05 — D-005 + A-002 : Corriger POSComprehensiveTest (6/8 → 8/8)
**Phase :** P1 — Haute
**Test-Type :** Kimi-test
**Risque :** 🟡 Moyen — Suite de tests incomplète masque des régressions
**Fichiers :**
- `tests/Feature/PosOrderTest.php` (ou équivalent POSComprehensiveTest)
- `database/factories/ItemFactory.php`
- `app/Http/Controllers/Admin/PosOrderController.php` (export)

---

## 1. Contexte & Problème

Le rapport mentionne `POSComprehensiveTest : 6/8 🟡` avec 2 échecs :

### Échec 1 — D-005 : `branch_id` dans ItemFactory
`ItemFactory` crée des items sans `branch_id` valide ou avec une valeur qui ne correspond
à aucune branche en base, causant des FK violations ou des items invisibles dans le POS.

### Échec 2 — A-002 : Export BinaryFileResponse::status
Le test d'export POS échoue car `BinaryFileResponse` n'a pas de méthode `status()`.

---

## 2. Fichiers à Modifier

| Fichier | Description | Action |
|---------|-------------|--------|
| `database/factories/ItemFactory.php` | branch_id absent ou invalide | Utiliser branch réelle |
| `tests/Feature/PosOrderTest.php` | Assertion export incorrecte | Corriger l'assertion |

---

## 3. Implémentation

### 3.1 Fix ItemFactory — branch_id

**Localiser :** `database/factories/ItemFactory.php`

```php
// AVANT — branch_id non défini ou invalide
public function definition(): array
{
    return [
        'name'  => $this->faker->word,
        'price' => $this->faker->randomFloat(2, 1, 50),
        // branch_id absent → erreur selon les tests
    ];
}

// APRÈS — utiliser une branche existante ou en créer une
public function definition(): array
{
    return [
        'name'             => $this->faker->word,
        'price'            => $this->faker->randomFloat(2, 1, 50),
        'item_category_id' => \App\Models\ItemCategory::inRandomOrder()->first()?->id ?? 1,
        'status'           => 1,
        // Si branch_id est une colonne dans items :
        // 'branch_id' => \App\Models\Branch::inRandomOrder()->first()?->id ?? 1,
        // Sinon l'omettre si items n'a pas de branch_id (la DB réelle n'a pas ce champ)
    ];
}
```

> **Note IMPORTANT :** Vérifier d'abord si `items` a une colonne `branch_id` :
> ```bash
> php artisan tinker --execute="echo implode(',', array_column(DB::select('SHOW COLUMNS FROM items'), 'Field'));"
> ```
> Si NON → retirer `branch_id` du factory ET des assertions de test.

### 3.2 Fix Export — BinaryFileResponse assertion

**Localiser :** le test qui fait une assertion sur l'export Excel/CSV

```php
// AVANT — problématique
$response = $this->actingAs($admin)->get('/admin/pos/export');
$this->assertEquals(200, $response->status()); // BinaryFileResponse n'a pas ->status()

// APRÈS — assertion correcte pour un téléchargement
$response = $this->actingAs($admin)->get('/admin/pos/export');
$response->assertStatus(200);
$response->assertHeader('Content-Type'); // Vérifier que c'est bien un fichier
// OU vérifier uniquement que ça ne crash pas :
$response->assertSuccessful();
```

### 3.3 Vérification complète de la suite

Après les deux fixes, lancer :
```bash
php artisan test tests/Feature/PosOrderTest.php -v
```

Si d'autres tests échouent, les noter dans `reports/execution/latest.md` pour review Claude.

---

## 4. Tests

### 4.1 Commandes
```bash
# Identifier exactement les 2 tests qui échouent
php artisan test --filter="POSComprehensive" -v

# Après fix
php artisan test --filter="POSComprehensive" -v
# Doit afficher : 8 passed, 0 failed
```

### 4.2 Suite complète
```bash
php artisan test
# AntiGravityTest : 20/20
# POSComprehensiveTest : 8/8 (objectif)
```

---

## 5. Critères de Succès

- [ ] `POSComprehensiveTest` : 8/8 (tous passent)
- [ ] `AntiGravityTest` : 20/20 (pas de régression)
- [ ] ItemFactory crée des items compatibles avec le schéma réel
- [ ] Test export n'échoue plus sur `BinaryFileResponse::status()`

---

## 6. NE PAS Toucher

- La logique de l'export en elle-même (Controller)
- Les autres factories (UserFactory, OrderFactory)
- Les tests AntiGravityTest (ne pas les modifier)
- La structure de la table `items` en production
