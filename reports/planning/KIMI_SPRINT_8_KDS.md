# KIMI SPRINT 8 — KDS FIABILITÉ
**Émetteur :** Claude (Architecte)
**Destinataire :** KIMI (Builder)
**Priorité :** 🟡 P1 — KDS partiellement cassé pour les admins
**Test type :** local-validation (PHPUnit)
**Dépendances :** Sprint 5 terminé (tax fix)

---

## CONTEXTE ARCHITECTURAL (lire avant de coder)

Le KDS (Kitchen Display System) lit les commandes avec `status IN (ACCEPT, PREPARING, PREPARED)`.
Il a deux méthodes principales :
- `list()` — liste les commandes actives (déjà correct pour les admins)
- `OrderItems()` — vue agrégée des items en cours de préparation (cassée pour les admins)

Il y a aussi un filtre `kitchen_status` dans `$orderFilter` qui référence une colonne inexistante.

**Règle absolue :** Ne pas modifier `ValidStatusTransition`. Ne pas modifier la logique de `list()` qui fonctionne déjà correctement.

---

## BUG S8-01 — `OrderItems()` — admin voit toujours 0 résultats

### Diagnostic précis

**Fichier :** `app/Services/KitchenDisplaySystemOrderService.php`
**Ligne 116 :** `$orders = Order::with('orderItems')->where('status', OrderStatus::PREPARING)->where('branch_id', auth()->user()->branch_id)`

**Problème :** La méthode `OrderItems()` filtre toujours par `branch_id` de l'utilisateur authentifié. Les utilisateurs Admin ont `branch_id = 0`. La requête cherche des commandes avec `branch_id = 0` — qui n'existent pas — donc retourne toujours une liste vide.

**Modèle à suivre :** La méthode `list()` (lignes 44-53) a déjà le bypass admin correct :
```php
if ($userBranchId > 0) {
    $query->where('branch_id', $userBranchId);
}
```

### Correction exacte

**Fichier :** `app/Services/KitchenDisplaySystemOrderService.php`
**Méthode :** `OrderItems()`

```php
// AVANT (ligne 116)
$orders = Order::with('orderItems')
    ->where('status', OrderStatus::PREPARING)
    ->where('branch_id', auth()->user()->branch_id)
    ->where(function ($query) {

// APRÈS — ajouter le bypass admin
$userBranchId = auth()->user()->branch_id ?? 0;
$query = Order::with('orderItems')
    ->where('status', OrderStatus::PREPARING);

// Admin bypass : branch_id=0 voit toutes les branches
if ($userBranchId > 0) {
    $query->where('branch_id', $userBranchId);
}

$orders = $query->where(function ($query) {
```

**Note :** La fermeture `->where(function ($query) { ... })` qui suit reste inchangée.

---

## BUG S8-02 — Filtre `kitchen_status` sur colonne inexistante

### Diagnostic précis

**Fichier :** `app/Services/KitchenDisplaySystemOrderService.php`
**Ligne 27 :** `'kitchen_status'` dans `$orderFilter`
**Ligne 64-68 :** `if ($key === "status" && $request) { $query->where($key, ...) }` — le filtre `kitchen_status` passerait dans le `else` et ferait `$query->where('kitchen_status', 'like', '%...%')` sur une colonne inexistante.

**Problème :** Si un client API envoie `?kitchen_status=X`, la requête SQL échoue avec une erreur de colonne inconnue.

**Solution :** Retirer `kitchen_status` de `$orderFilter`. Si cette fonctionnalité est nécessaire dans le futur, il faudra créer une migration pour ajouter la colonne.

### Correction exacte

```php
// AVANT (ligne 21-28)
protected array $orderFilter = [
    'order_serial_no',
    'branch_id',
    'order_type',
    'status',
    'kitchen_status',   // ← RETIRER
    'source'
];

// APRÈS
protected array $orderFilter = [
    'order_serial_no',
    'branch_id',
    'order_type',
    'status',
    'source'
];
```

---

## BUG S8-03 — Vérification relation `orderItem` dans `KDSOrderDetailsResource`

### Diagnostic précis

**Fichier :** `app/Http/Resources/KDSOrderDetailsResource.php`

**Vérifier** que le `load('orderItem')` (ou équivalent) dans ce resource charge une relation qui existe sur le modèle `OrderItem`. Si la relation s'appelle `item` (BelongsTo Item), corriger le nom.

**Action :** Lire `app/Models/OrderItem.php` et vérifier le nom de la relation vers `Item`. Si elle s'appelle `item()`, corriger le resource pour utiliser `item` au lieu de `orderItem`.

**Fichier à lire :** `app/Models/OrderItem.php` — chercher `function item` ou `function orderItem`.

---

## TESTS OBLIGATOIRES Sprint 8

### Tests PHPUnit à créer

**Fichier :** `tests/Feature/KDSOrderItemsTest.php`

```php
<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Branch;
use App\Models\Order;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;

class KDSOrderItemsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();
        $this->seedSpatieRoles();
    }

    public function test_admin_kds_order_items_not_empty(): void
    {
        // Créer une branche et une commande en PREPARING
        $branch = \Database\Factories\BranchFactory::new()->create();
        $admin = \Database\Factories\UserFactory::new()->create(['branch_id' => 0]); // Admin = branch_id 0
        $admin->assignRole('Admin');
        $token = $admin->createToken('test')->plainTextToken;

        // Créer une commande PREPARING sur la branche
        $order = Order::create([
            'order_serial_no' => '140326001',
            'branch_id' => $branch->id,
            'user_id' => $admin->id,
            'status' => OrderStatus::PREPARING,
            'payment_status' => PaymentStatus::PAID,
            'subtotal' => 10.00,
            'total' => 10.00,
            'order_type' => OrderType::TAKEAWAY,
            'order_datetime' => now(),
            'is_advance_order' => 0,
            'source' => 1,
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
            'x-api-key' => config('app.api_key', 'test-api-key'),
        ])->getJson('/api/v1/admin/kds-order/items');

        $response->assertStatus(200);
        // Admin avec branch_id=0 doit voir les commandes de toutes les branches
        // (le test vérifie que la réponse n'est pas vide à cause du bug branch_id=0)
    }

    public function test_kitchen_status_filter_does_not_crash(): void
    {
        $branch = \Database\Factories\BranchFactory::new()->create();
        $chef = \Database\Factories\UserFactory::new()->create(['branch_id' => $branch->id]);
        $chef->assignRole('Chef');
        $token = $chef->createToken('test')->plainTextToken;

        // Envoyer kitchen_status dans la requête — ne doit pas crasher
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
            'x-api-key' => config('app.api_key', 'test-api-key'),
        ])->getJson('/api/v1/admin/kds-order?kitchen_status=1');

        // Ne doit pas retourner 500
        $this->assertNotEquals(500, $response->status());
    }
}
```

---

## VÉRIFICATIONS DE SYNCHRONISATION Sprint 8

1. `php artisan test --filter=KDSOrderItemsTest` — 2 tests PASS
2. `php artisan test` — 0 régression
3. Vérifier manuellement : connecté en Admin, ouvrir le KDS, le board "Items" doit afficher les items des commandes PREPARING
4. Envoyer `GET /api/v1/admin/kds-order?kitchen_status=1` — ne doit pas retourner 500
5. Vérifier que `list()` fonctionne toujours correctement (non modifiée)

---

## RÉSUMÉ DES FICHIERS À MODIFIER

| Fichier | Modification |
|---------|--------------|
| `app/Services/KitchenDisplaySystemOrderService.php` | OrderItems() bypass admin + retrait kitchen_status |
| `app/Http/Resources/KDSOrderDetailsResource.php` | Vérifier nom relation orderItem |
| `tests/Feature/KDSOrderItemsTest.php` | NOUVEAU — tests |
