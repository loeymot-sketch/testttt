# PLAN_03 — D-004 : ValidJsonOrder — Rejeter items sans item_id
**Phase :** P0 — Critique
**Test-Type :** local-validation
**Risque :** 🔴 Haute — Validation laisse passer `{"quantity":1}` sans item_id
**Fichier :** `app/Rules/ValidJsonOrder.php`

---

## 1. Contexte & Problème

La règle `ValidJsonOrder` valide le champ `items` (JSON stringifié) dans les requêtes
POS et frontend. Elle vérifie actuellement que le champ est un JSON valide, mais n'exige pas
la présence de `item_id` dans chaque élément.

**Payload qui passe aujourd'hui (incorrect) :**
```json
[{"quantity": 1, "item_price": 5.00}]
```
→ Accepté → comportement indéfini en backend (Item::find(null) → null → bug silencieux)

**Payload qui DOIT passer :**
```json
[{"item_id": 4, "quantity": 2, "item_price": 8.50}]
```

---

## 2. Fichiers à Modifier

| Fichier | Méthode | Action |
|---------|---------|--------|
| `app/Rules/ValidJsonOrder.php` | `passes()` | Ajouter validation item_id + quantity |

---

## 3. Implémentation

### 3.1 Ouvrir `app/Rules/ValidJsonOrder.php`

Chercher la méthode `passes($attribute, $value)` et remplacer par :

```php
public function passes($attribute, $value): bool
{
    // Étape 1 : Vérifier que c'est un JSON valide
    if (!is_string($value)) {
        $this->message = 'Le champ ' . $attribute . ' doit être une chaîne JSON.';
        return false;
    }

    $decoded = json_decode($value, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        $this->message = 'Le champ ' . $attribute . ' contient du JSON invalide.';
        return false;
    }

    // Étape 2 : Vérifier que c'est un tableau non vide
    if (!is_array($decoded) || empty($decoded)) {
        $this->message = 'La commande doit contenir au moins un article.';
        return false;
    }

    // Étape 3 : Vérifier chaque item
    foreach ($decoded as $index => $item) {
        // item_id obligatoire et numérique
        if (!isset($item['item_id']) || !is_numeric($item['item_id']) || (int)$item['item_id'] <= 0) {
            $this->message = "L'article à l'index {$index} n'a pas d'item_id valide.";
            return false;
        }

        // quantity obligatoire, numérique, > 0
        if (!isset($item['quantity']) || !is_numeric($item['quantity']) || (int)$item['quantity'] <= 0) {
            $this->message = "L'article à l'index {$index} n'a pas de quantité valide.";
            return false;
        }
    }

    return true;
}

public function message(): string
{
    return $this->message ?? 'Le format de la commande est invalide.';
}

private string $message = '';
```

---

## 4. Tests

### 4.1 Tests unitaires de la règle
**Fichier à créer :** `tests/Unit/Rules/ValidJsonOrderTest.php`

```php
<?php

namespace Tests\Unit\Rules;

use Tests\TestCase;
use App\Rules\ValidJsonOrder;

class ValidJsonOrderTest extends TestCase
{
    private function rule(): ValidJsonOrder
    {
        return new ValidJsonOrder();
    }

    /** @test */
    public function it_rejects_items_without_item_id()
    {
        $rule = $this->rule();
        $result = $rule->passes('items', json_encode([['quantity' => 1, 'item_price' => 5.00]]));
        $this->assertFalse($result);
        $this->assertStringContainsString('item_id', $rule->message());
    }

    /** @test */
    public function it_rejects_items_with_zero_quantity()
    {
        $rule = $this->rule();
        $result = $rule->passes('items', json_encode([['item_id' => 4, 'quantity' => 0]]));
        $this->assertFalse($result);
    }

    /** @test */
    public function it_rejects_items_with_negative_item_id()
    {
        $rule = $this->rule();
        $result = $rule->passes('items', json_encode([['item_id' => -1, 'quantity' => 1]]));
        $this->assertFalse($result);
    }

    /** @test */
    public function it_rejects_empty_array()
    {
        $rule = $this->rule();
        $result = $rule->passes('items', json_encode([]));
        $this->assertFalse($result);
    }

    /** @test */
    public function it_rejects_invalid_json()
    {
        $rule = $this->rule();
        $result = $rule->passes('items', 'not-json');
        $this->assertFalse($result);
    }

    /** @test */
    public function it_accepts_valid_item_array()
    {
        $rule = $this->rule();
        $result = $rule->passes('items', json_encode([
            ['item_id' => 4, 'quantity' => 2, 'item_price' => 8.50]
        ]));
        $this->assertTrue($result);
    }

    /** @test */
    public function it_accepts_multiple_valid_items()
    {
        $rule = $this->rule();
        $result = $rule->passes('items', json_encode([
            ['item_id' => 4, 'quantity' => 1],
            ['item_id' => 7, 'quantity' => 3],
        ]));
        $this->assertTrue($result);
    }
}
```

### 4.2 Commande
```bash
php artisan test tests/Unit/Rules/ValidJsonOrderTest.php
```

---

## 5. Critères de Succès

- [ ] `[{"quantity":1}]` → `passes()` retourne `false`
- [ ] `[{"item_id":4,"quantity":2}]` → `passes()` retourne `true`
- [ ] Message d'erreur clair inclut l'index de l'article invalide
- [ ] 7 tests unitaires passent
- [ ] 0 régression sur les requêtes POS existantes

---

## 6. NE PAS Toucher

- La règle existante dans `PosOrderRequest.php` qui utilise `ValidJsonOrder` — ne pas changer son usage
- La logique de décodage si elle existe déjà partiellement (merger proprement)
- Les autres champs de la requête (token, type, etc.)
