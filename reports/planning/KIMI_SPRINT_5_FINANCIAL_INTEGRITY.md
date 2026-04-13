# KIMI SPRINT 5 — INTÉGRITÉ FINANCIÈRE POS
**Émetteur :** Claude (Architecte)
**Destinataire :** KIMI (Builder)
**Priorité :** 🔴 P0 — Bloquant financier — les montants enregistrés en DB sont faux
**Test type :** local-validation (PHPUnit)
**Dépendances :** Aucune — démarrer immédiatement

---

## CONTEXTE ARCHITECTURAL (lire avant de coder)

Le système POS crée des commandes avec `status=ACCEPT` et `payment_status=PAID` directement.
Les prix sont recalculés côté serveur depuis la DB — c'est correct.
**MAIS** : le lookup de la taxe est cassé dans `posOrderStore`, et les sauces supplémentaires du wizard ne sont jamais facturées.

Ces deux bugs font que :
1. La TVA est toujours 0 sur toutes les commandes POS
2. Une commande avec 2 sauces est facturée comme si elle n'en avait qu'une

---

## BUG S5-01 — Tax lookup cassé dans `posOrderStore`

### Diagnostic précis

**Fichier :** `app/Services/OrderService.php`
**Ligne 449 :** `$dbItems = Item::select('id', 'price')->whereIn('id', $requestedItemIds)->pluck('price', 'id');`
**Ligne 507 :** `$taxId = isset($dbItems[$item->item_id]) ? $dbItems[$item->item_id] : 0;`

**Problème :** `$dbItems` est un dictionnaire `{ item_id => price }`. À la ligne 507, `$dbItems[$item->item_id]` retourne le **prix** de l'item (ex: `6.50`), pas son `tax_id`. Le système cherche ensuite une taxe avec l'id `6.50` — qui n'existe pas — donc `$taxRate = 0` et `$taxPrice = 0` pour tous les items.

**Résultat :** Toutes les commandes POS ont `total_tax = 0.00` même si des taxes sont configurées.

### Correction exacte

**Étape 1 — Modifier la requête DB (ligne 449)**

```php
// AVANT (ligne 449)
$dbItems = Item::select('id', 'price')
    ->whereIn('id', $requestedItemIds)
    ->pluck('price', 'id');

// APRÈS — ajouter tax_id dans le select, stocker objet complet
$dbItems = Item::select('id', 'price', 'tax_id')
    ->whereIn('id', $requestedItemIds)
    ->get()
    ->keyBy('id');
```

**Étape 2 — Corriger l'accès au prix (ligne 465)**

```php
// AVANT (ligne 465)
$itemPrice = $dbItems[$item->item_id]; // retournait un float

// APRÈS
$itemPrice = (float) $dbItems[$item->item_id]->price;
```

**Étape 3 — Corriger le tax lookup (ligne 507)**

```php
// AVANT (ligne 507)
$taxId = isset($dbItems[$item->item_id]) ? $dbItems[$item->item_id] : 0;

// APRÈS
$taxId = isset($dbItems[$item->item_id]) ? ($dbItems[$item->item_id]->tax_id ?? 0) : 0;
```

**Étape 4 — Vérifier la ligne 459 (check existence)**

```php
// AVANT (ligne 459)
if (!isset($dbItems[$item->item_id])) {

// APRÈS — inchangé, fonctionne avec keyBy aussi
if (!isset($dbItems[$item->item_id])) {
```

---

## BUG S5-02 — `PaymentStatusRequest` sans contrôle de rôle

### Diagnostic précis

**Fichier :** `app/Http/Requests/PaymentStatusRequest.php`
**Ligne 14-16 :** `authorize()` retourne `true` inconditionnellement.

**Problème :** N'importe quel utilisateur authentifié (même un client kiosk) peut appeler `POST /api/admin/pos-order/{id}/change-payment-status` et modifier le statut de paiement d'une commande.

**Modèle à suivre :** `OrderStatusRequest.php` (déjà corrigé en Sprint 1-A) — lignes 17-25.

### Correction exacte

**Fichier :** `app/Http/Requests/PaymentStatusRequest.php`

```php
// AVANT
public function authorize(): bool
{
    return true;
}

// APRÈS
public function authorize(): bool
{
    if (!auth()->check()) {
        return false;
    }
    return auth()->user()->hasAnyRole(['Admin', 'Manager', 'Cashier']);
}
```

---

## BUG S5-03 — Vérification `FrontendOrderService` — même bug tax

### Diagnostic précis

**Fichier :** `app/Services/FrontendOrderService.php`
**Ligne 122 :** `$items = Item::select('id', 'tax_id')->whereIn('id', $requestedItemIds)->pluck('tax_id', 'id');`

**Observation :** Ce fichier a déjà le bon pattern — il sélectionne `tax_id` séparément. Le `$taxId` à la ligne 160 est correct : `$taxId = isset($items[$item->item_id]) ? $items[$item->item_id] : 0;` — ici `$items` contient bien des `tax_id`.

**Action :** Vérifier uniquement que ce fichier est correct — **aucune modification nécessaire**.

---

## TESTS OBLIGATOIRES Sprint 5

### Test PHPUnit à créer

**Fichier :** `tests/Feature/PosOrderTaxTest.php`

```php
<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Tax;
use App\Models\Item;
use App\Models\User;
use App\Models\Branch;
use App\Enums\TaxType;
use App\Enums\Status;
use Illuminate\Foundation\Testing\RefreshDatabase;

class PosOrderTaxTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();
        $this->seedSpatieRoles();
    }

    public function test_pos_order_tax_is_calculated_from_db(): void
    {
        // Créer une taxe de 10%
        $tax = Tax::create([
            'name' => 'TVA 10%',
            'code' => 'TVA10',
            'tax_rate' => 10,
            'type' => TaxType::PERCENTAGE,
            'status' => Status::ACTIVE,
        ]);

        // Créer un item avec cette taxe
        $branch = \Database\Factories\BranchFactory::new()->create();
        $item = Item::create([
            'name' => 'Test Item',
            'slug' => 'test-item',
            'price' => 10.00,
            'tax_id' => $tax->id,
            'status' => Status::ACTIVE,
            'item_category_id' => 1,
        ]);

        // Créer un admin
        $admin = \Database\Factories\UserFactory::new()->create(['branch_id' => $branch->id]);
        $admin->assignRole('Admin');
        $token = $admin->createToken('test')->plainTextToken;

        // Passer une commande POS
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
            'x-api-key' => config('app.api_key', 'test-api-key'),
        ])->postJson('/api/v1/admin/pos', [
            'customer_id' => $admin->id,
            'branch_id' => $branch->id,
            'subtotal' => 10.00,
            'total' => 11.00,
            'order_type' => 10, // TAKEAWAY
            'is_advance_order' => 0,
            'source' => 1,
            'pos_payment_method' => 1, // CASH
            'pos_received_amount' => 11.00,
            'items' => json_encode([
                ['item_id' => $item->id, 'quantity' => 1, 'item_variations' => [], 'item_extras' => []]
            ]),
        ]);

        $response->assertStatus(201);
        $data = $response->json();
        // La taxe doit être > 0
        $this->assertGreaterThan(0, $data['data']['total_tax'] ?? 0,
            'total_tax doit être > 0 quand une taxe est configurée sur l\'item');
    }

    public function test_payment_status_change_requires_role(): void
    {
        // Utilisateur sans rôle
        $branch = \Database\Factories\BranchFactory::new()->create();
        $user = \Database\Factories\UserFactory::new()->create(['branch_id' => $branch->id]);
        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
            'x-api-key' => config('app.api_key', 'test-api-key'),
        ])->postJson('/api/v1/admin/pos-order/change-payment-status/1', [
            'payment_status' => 5,
        ]);

        $response->assertStatus(403);
    }
}
```

---

## VÉRIFICATIONS DE SYNCHRONISATION Sprint 5

Avant de marquer ce sprint DONE, vérifier :

1. `php artisan test --filter=PosOrderTaxTest` — 2 tests PASS
2. `php artisan test` — 0 régression sur les tests existants
3. Vérifier manuellement que `$dbItems[$item->item_id]->price` retourne bien un float (pas un objet)
4. Vérifier que la ligne 459 (`if (!isset($dbItems[$item->item_id]))`) fonctionne toujours avec `keyBy`
5. Vérifier que `FrontendOrderService.php` n'a PAS besoin de modification (déjà correct)

---

## RÉSUMÉ DES FICHIERS À MODIFIER

| Fichier | Lignes | Modification |
|---------|--------|--------------|
| `app/Services/OrderService.php` | 449, 465, 507 | Tax lookup corrigé |
| `app/Http/Requests/PaymentStatusRequest.php` | 14-16 | Contrôle de rôle ajouté |
| `tests/Feature/PosOrderTaxTest.php` | NOUVEAU | Tests créés |

**Ne pas toucher :** `FrontendOrderService.php` (déjà correct), `OrderStatusRequest.php` (déjà corrigé Sprint 1-A)
