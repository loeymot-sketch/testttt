# PLAN_01 — D-001 : Sécurité Prix Fallback (Items Inexistants)
**Phase :** P0 — Critique
**Test-Type :** Kimi-test
**Risque :** 🔴 Critique — Attaque prix possible via item_id inexistant
**Fichiers concernés :** `app/Services/FrontendOrderService.php`, `app/Services/OrderService.php`

---

## 1. Contexte & Problème

### Description du bug
Dans `FrontendOrderService.php` (ligne ~127-128) et `OrderService.php` (méthodes `orderStore`,
`tableOrderStore`), si `Item::find($item->item_id)` retourne `null` (item inexistant), le code
utilise le prix fourni **par le client** (`$item->item_price`).

**Vecteur d'attaque :**
```json
POST /api/frontend/order
{
  "cart_items": [
    {"item_id": 999999, "item_price": 0.01, "quantity": 5}
  ]
}
```
→ Commande créée à 0.05€ au lieu d'un rejet.

### Cause racine
```php
// AVANT (vulnérable) — FrontendOrderService.php ~L127
$dbItem = Item::find($item->item_id);
$itemPrice = $dbItem ? $dbItem->price : $item->item_price; // ← DANGER
```

---

## 2. Fichiers à Modifier

| Fichier | Lignes concernées | Action |
|---------|------------------|--------|
| `app/Services/FrontendOrderService.php` | ~L120-140 | Throw si $dbItem = null |
| `app/Services/OrderService.php` | `orderStore()` ~L200-250 | Idem |
| `app/Services/OrderService.php` | `tableOrderStore()` ~L500-600 | Idem |

---

## 3. Implémentation

### 3.1 FrontendOrderService.php

**Localiser la boucle de traitement des items** et remplacer :

```php
// AVANT
$dbItem = Item::find($item->item_id);
$itemPrice = $dbItem ? $dbItem->price : $item->item_price;

// APRÈS — rejeter si item introuvable
$dbItem = Item::find($item->item_id);
if (!$dbItem) {
    throw new \InvalidArgumentException(
        "Item ID {$item->item_id} introuvable. Commande rejetée."
    );
}
$itemPrice = $dbItem->price; // ← prix TOUJOURS depuis la DB
```

### 3.2 OrderService.php — orderStore()

Même pattern dans la boucle `foreach($request->cart_items as $item)` :

```php
// AVANT
$dbItem = Item::find($item['item_id'] ?? $item->item_id);
$price = $dbItem ? $dbItem->price : ($item['price'] ?? 0);

// APRÈS
$itemId = $item['item_id'] ?? ($item->item_id ?? null);
$dbItem = $itemId ? Item::find($itemId) : null;
if (!$dbItem) {
    throw new \InvalidArgumentException("Item {$itemId} non trouvé.");
}
$price = $dbItem->price;
```

### 3.3 Même chose dans tableOrderStore()

Chercher le même pattern dans `tableOrderStore()` et appliquer la même correction.

### 3.4 Gestion de l'exception dans les Controllers

Dans les controllers qui appellent ces services, s'assurer que `\InvalidArgumentException`
est attrapée et retourne un **422** :

```php
// Dans FrontendOrderController ou OrderController
try {
    $order = $this->orderService->frontendOrderStore($request);
} catch (\InvalidArgumentException $e) {
    return response()->json(['message' => $e->getMessage()], 422);
}
```

---

## 4. Tests

### 4.1 Test PHPUnit à créer
**Fichier :** `tests/Unit/Services/FrontendOrderServiceTest.php`

```php
/** @test */
public function it_rejects_order_with_nonexistent_item_id()
{
    $request = new FakeOrderRequest([
        'cart_items' => [
            ['item_id' => 999999, 'item_price' => 0.01, 'quantity' => 1]
        ]
    ]);

    $this->expectException(\InvalidArgumentException::class);
    $service = new FrontendOrderService();
    $service->myOrderStore($request);
}

/** @test */
public function it_uses_db_price_not_client_price()
{
    // Créer un item en DB avec price=10.00
    $item = Item::factory()->create(['price' => 10.00]);

    // Appel avec item_price=0.01 (tentative de fraude)
    $request = new FakeOrderRequest([
        'cart_items' => [
            ['item_id' => $item->id, 'item_price' => 0.01, 'quantity' => 1]
        ]
    ]);

    $service = new FrontendOrderService();
    $result = $service->myOrderStore($request);

    // Prix doit être 10.00, pas 0.01
    $this->assertEquals('10.00', $result->total);
}
```

### 4.2 Test HTTP (Feature test)
```php
/** @test */
public function frontend_order_returns_422_for_invalid_item()
{
    $response = $this->postJson('/api/frontend/order', [
        'cart_items' => [['item_id' => 999999, 'item_price' => 0.01, 'quantity' => 1]]
    ]);
    $response->assertStatus(422);
}
```

### 4.3 Commande d'exécution
```bash
php artisan test --filter="rejects_order_with_nonexistent_item\|uses_db_price"
```

---

## 5. Critères de Succès

- [ ] `Item::find(999999)` → `null` → exception levée, pas de commande créée
- [ ] Prix DB utilisé même si le client envoie un prix différent
- [ ] Tests PHPUnit passent (verts)
- [ ] `php artisan test` : 0 régression sur AntiGravityTest et POSComprehensiveTest

---

## 6. NE PAS Toucher

- La logique de calcul des taxes (réside après la récupération du prix)
- Les addons et variations (traités dans PLAN_02)
- La validation ValidJsonOrder (traitée dans PLAN_03)
- Les colonnes `item_price` dans le modèle Order (champ legacy, ne pas supprimer)
