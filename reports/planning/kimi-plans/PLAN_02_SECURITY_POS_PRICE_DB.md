# PLAN_02 — D-002 : POS Sécurité Prix DB pour Variations et Extras
**Phase :** P0 — Critique
**Test-Type :** local-validation
**Risque :** 🔴 Critique — Caissier ou attaquant peut falsifier prix des variations/suppléments POS
**Fichiers :** `app/Services/OrderService.php` méthode `posOrderStore()`

---

## 1. Contexte & Problème

### Différence Kiosk vs POS
| Méthode | Variations/Extras | Sécurisé ? |
|---------|-----------------|------------|
| `tableOrderStore()` | `ItemVariation::find($var->id)` puis `$dbVar->price` | ✅ OUI |
| `orderStore()` (frontend) | `ItemVariation::find(...)` | ✅ OUI |
| `posOrderStore()` | `$variation->price` (depuis le payload du caissier) | ❌ NON |

### Vecteur d'attaque POS
Un caissier malveillant ou un proxy entre le navigateur et l'API peut modifier la requête :
```json
{
  "item_variations": [{"id": 5, "price": 0.00}],
  "item_extras":     [{"id": 3, "price": 0.00}]
}
```
→ La variation qui vaut réellement 2.50€ est comptée 0.00€.

### Localisation dans le code
**Fichier :** `app/Services/OrderService.php`
**Méthode :** `posOrderStore()` — ligne ~398 et suivantes

```php
// AVANT (vulnérable) — L432-446 approximatif
foreach ($request->item_variations as $variation) {
    $variationTotal += $variation->price; // ← prix client non vérifié
}
foreach ($request->item_extras as $extra) {
    $extraTotal += $extra->price; // ← idem
}
```

---

## 2. Fichiers à Modifier

| Fichier | Méthode | Lignes approx. |
|---------|---------|----------------|
| `app/Services/OrderService.php` | `posOrderStore()` | ~430-460 |

---

## 3. Implémentation

### Localiser `posOrderStore()` dans OrderService.php

Chercher : `public function posOrderStore(PosOrderRequest $request)`

Dans cette méthode, trouver les boucles de calcul des prix des variations et extras.

### 3.1 Fix Variations

```php
// AVANT
foreach ($request->item_variations as $variation) {
    $variationTotal += (float)($variation->price ?? 0);
}

// APRÈS — valider contre la DB
foreach ($request->item_variations as $variation) {
    $varId = $variation->id ?? null;
    if (!$varId) continue; // pas d'ID → ignorer (pas de supplément)
    
    $dbVar = \App\Models\ItemVariation::find($varId);
    if (!$dbVar) {
        throw new \InvalidArgumentException(
            "Variation ID {$varId} introuvable."
        );
    }
    $variationTotal += (float)$dbVar->price;
}
```

### 3.2 Fix Extras

```php
// AVANT
foreach ($request->item_extras as $extra) {
    $extraTotal += (float)($extra->price ?? 0);
}

// APRÈS
foreach ($request->item_extras as $extra) {
    $extraId = $extra->id ?? null;
    if (!$extraId) continue;
    
    $dbExt = \App\Models\ItemExtra::find($extraId);
    if (!$dbExt) {
        throw new \InvalidArgumentException(
            "Extra ID {$extraId} introuvable."
        );
    }
    $extraTotal += (float)$dbExt->price;
}
```

### 3.3 Imports à ajouter en haut (si pas déjà présents)

```php
use App\Models\ItemVariation;
use App\Models\ItemExtra;
```

### 3.4 Controller POS — gestion exception

Dans `app/Http/Controllers/Admin/PosOrderController.php`, méthode `store()` :

```php
try {
    $order = $this->orderService->posOrderStore($request);
} catch (\InvalidArgumentException $e) {
    return response()->json([
        'status' => false,
        'message' => $e->getMessage(),
    ], 422);
}
```

---

## 4. Tests

### 4.1 Test PHPUnit
```php
/** @test */
public function pos_order_uses_db_price_for_variations_not_client_price()
{
    $admin = User::factory()->create(['role' => 'admin']);
    $item = Item::factory()->create(['price' => 8.00]);
    $variation = ItemVariation::factory()->create([
        'item_id' => $item->id,
        'price'   => 2.50
    ]);

    $this->actingAs($admin)->postJson('/api/admin/pos', [
        'items' => json_encode([
            ['item_id' => $item->id, 'item_price' => 8.00, 'quantity' => 1,
             'item_variations' => [['id' => $variation->id, 'price' => 0.00]]]
        ]),
        // ... autres champs requis
    ])->assertStatus(200);

    // La commande doit avoir le total = 8.00 + 2.50, pas 8.00 + 0.00
    $order = Order::latest()->first();
    $this->assertEquals('10.50', $order->total);
}
```

### 4.2 Commande
```bash
php artisan test --filter="pos_order_uses_db_price_for_variations"
```

---

## 5. Critères de Succès

- [ ] `posOrderStore()` ne lit plus `$variation->price` de la requête
- [ ] `ItemVariation::find($id)` utilisé avec rejet si null
- [ ] `ItemExtra::find($id)` utilisé avec rejet si null
- [ ] Test PHPUnit : variation à 2.50€ → total correct même si payload envoie 0€
- [ ] `php artisan test` : 0 régression

---

## 6. NE PAS Toucher

- Les prix des items principaux (déjà corrigés dans PLAN_01)
- La logique des addons (frites/boisson) — traitement séparé + via DB addons
- La logique des taxes — après calcul du prix total
- `tableOrderStore()` — déjà sécurisé, ne pas modifier
