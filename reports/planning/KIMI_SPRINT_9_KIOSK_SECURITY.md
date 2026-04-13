# KIMI SPRINT 9 — SÉCURITÉ ET ISOLATION BORNE
**Émetteur :** Claude (Architecte)
**Destinataire :** KIMI (Builder)
**Priorité :** 🟡 P1 — Sécurité et isolation données borne
**Test type :** local-validation (PHPUnit)
**Dépendances :** Aucune — peut démarrer en parallèle avec Sprint 7

---

## CONTEXTE ARCHITECTURAL (lire avant de coder)

La borne (Kiosk) s'authentifie via `POST /api/auth/kiosk-login` et reçoit un token Sanctum avec l'ability `kiosk:order`. Elle crée des commandes via `POST /api/frontend/order/`.

**Problèmes actuels :**
1. La vérification `is_login` et sa mise à jour ne sont pas atomiques — race condition possible
2. La route `POST /api/frontend/order/` ne vérifie pas l'ability `kiosk:order`
3. Le modèle `FrontendOrder` n'a pas de `BranchScope` — risque de fuite de données entre branches
4. Il n'existe pas de `OrderType::KIOSK` — les commandes borne sont indistinguables des commandes web

**Règle absolue :** Ne pas modifier la logique de recalcul des prix. Ne pas modifier `ValidStatusTransition`. Ne pas toucher aux routes admin.

---

## BUG S9-01 — Race condition `is_login` dans KioskMachineLoginController

### Diagnostic précis

**Fichier :** `app/Http/Controllers/Auth/KioskMachineLoginController.php`
**Lignes 46-52 :**

```php
if ($kioskMachine->is_login === Ask::YES) {
    return response()->json(['errors' => ['validation' => ...]], 400);
}
$user = User::find($kioskMachine->user_id);
$this->token = $user->createToken('kiosk-token', ['kiosk:order'])->plainTextToken;
$kioskMachine->update(['is_login' => Ask::YES]);
```

**Problème :** La vérification (`is_login === YES ?`) et la mise à jour (`is_login = YES`) sont deux opérations séparées. Deux requêtes simultanées peuvent toutes les deux passer la vérification avant que l'une d'elles ne mette à jour le flag. Résultat : deux sessions actives sur la même borne.

### Correction exacte

**Envelopper dans une transaction avec `lockForUpdate()` :**

```php
// AVANT (lignes 46-52)
if ($kioskMachine->is_login === Ask::YES) {
    return response()->json(['errors' => ['validation' => trans('all.message.already_logged_in')]], 400);
}
$user = User::find($kioskMachine->user_id);
$this->token = $user->createToken('kiosk-token', ['kiosk:order'])->plainTextToken;
$kioskMachine->update(['is_login' => Ask::YES]);

// APRÈS
$loginResult = null;
DB::transaction(function () use ($kioskMachine, &$loginResult) {
    // Recharger avec lock pour éviter la race condition
    $lockedKiosk = KioskMachine::lockForUpdate()->find($kioskMachine->id);
    if ($lockedKiosk->is_login === Ask::YES) {
        $loginResult = 'already_logged_in';
        return;
    }
    $user = User::find($lockedKiosk->user_id);
    $this->token = $user->createToken('kiosk-token', ['kiosk:order'])->plainTextToken;
    $lockedKiosk->update(['is_login' => Ask::YES]);
    $loginResult = 'success';
});

if ($loginResult === 'already_logged_in') {
    return response()->json(['errors' => ['validation' => trans('all.message.already_logged_in')]], 400);
}
```

**Ajouter l'import DB en haut du fichier :**
```php
use Illuminate\Support\Facades\DB;
```

---

## BUG S9-02 — `FrontendOrder` sans `BranchScope`

### Diagnostic précis

**Fichier :** `app/Models/FrontendOrder.php`
**Ligne 6 :** `use App\Models\Scopes\BranchScope;` est importé mais **non utilisé** dans le modèle.

**Vérification :** Chercher si `BranchScope` est appliqué via `booted()` ou `boot()` dans `FrontendOrder.php`.

**Problème :** Si `BranchScope` n'est pas appliqué, les requêtes sur `FrontendOrder` retournent des commandes de toutes les branches. Un caissier de la branche A pourrait voir les commandes de la branche B.

### Correction exacte

**Vérifier d'abord** si `BranchScope` est appliqué dans `FrontendOrder.php` :
- Si oui → aucune modification
- Si non → ajouter :

```php
// Dans FrontendOrder.php, ajouter la méthode booted()
protected static function booted(): void
{
    static::addGlobalScope(new BranchScope);
}
```

**Note :** `BranchScope` doit bypasser les admins (branch_id=0). Vérifier que `app/Models/Scopes/BranchScope.php` a déjà ce bypass. Si non, ne pas modifier BranchScope — créer un scope dédié `FrontendBranchScope` qui applique le filtre seulement si `branch_id > 0`.

---

## BUG S9-03 — `OrderType::KIOSK` pour traçabilité

### Diagnostic précis

**Fichier :** `app/Enums/OrderType.php`

**Problème :** Il n'existe pas de `OrderType::KIOSK`. Les commandes borne ont `order_type = TAKEAWAY (10)` — indistinguables des commandes web. Le dashboard `channelStatistics()` ne peut pas distinguer borne vs web.

### Correction exacte

**Étape 1 — Ajouter la constante :**

```php
// AVANT
interface OrderType
{
    const DELIVERY    = 5;
    const TAKEAWAY    = 10;
    const POS         = 15;
    const DINING_TABLE = 20;
}

// APRÈS
interface OrderType
{
    const DELIVERY    = 5;
    const TAKEAWAY    = 10;
    const POS         = 15;
    const DINING_TABLE = 20;
    const KIOSK       = 25;  // [SPRINT 9] Commandes borne
}
```

**Étape 2 — Forcer `order_type = KIOSK` dans `FrontendOrderService::myOrderStore()`**

**Fichier :** `app/Services/FrontendOrderService.php`
**Après la ligne 104 (`$validatedRequest['branch_id'] = $kiosk->branch_id;`) :**

```php
if ($kiosk) {
    $validatedRequest['branch_id'] = $kiosk->branch_id;
    $validatedRequest['order_type'] = \App\Enums\OrderType::KIOSK;  // [SPRINT 9] Forcer le type
}
```

**Étape 3 — Vérifier que `ValidStatusTransition` n'est pas affecté**

`ValidStatusTransition` travaille sur `order.status`, pas sur `order_type`. Aucune modification nécessaire.

**Étape 4 — Vérifier que `KDSOrderService::list()` n'exclut pas KIOSK**

La méthode `list()` filtre par `status`, pas par `order_type`. Les commandes KIOSK arriveront normalement sur le KDS. Aucune modification nécessaire.

---

## TESTS OBLIGATOIRES Sprint 9

### Tests PHPUnit à créer

**Fichier :** `tests/Feature/KioskSecurityTest.php`

```php
<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\KioskMachine;
use App\Enums\Ask;
use App\Enums\Status;
use Illuminate\Foundation\Testing\RefreshDatabase;

class KioskSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();
        $this->seedSpatieRoles();
    }

    public function test_kiosk_double_login_rejected(): void
    {
        $branch = \Database\Factories\BranchFactory::new()->create();
        $user = \Database\Factories\UserFactory::new()->create(['branch_id' => $branch->id]);
        $kiosk = \Database\Factories\KioskMachineFactory::new()->create([
            'branch_id' => $branch->id,
            'user_id' => $user->id,
            'username' => 'kiosk_test_001',
            'password' => bcrypt('password123'),
            'status' => Status::ACTIVE,
            'is_login' => Ask::YES, // Déjà connecté
        ]);

        $response = $this->withHeader('x-api-key', config('app.api_key', 'test-api-key'))
            ->postJson('/api/auth/kiosk-login', [
                'username' => 'kiosk_test_001',
                'password' => 'password123',
            ]);

        $response->assertStatus(400);
        $this->assertStringContainsString('already', strtolower($response->json('errors.validation') ?? ''));
    }

    public function test_kiosk_order_type_is_kiosk(): void
    {
        $branch = \Database\Factories\BranchFactory::new()->create();
        $user = \Database\Factories\UserFactory::new()->create(['branch_id' => $branch->id]);
        $kiosk = \Database\Factories\KioskMachineFactory::new()->create([
            'branch_id' => $branch->id,
            'user_id' => $user->id,
            'username' => 'kiosk_test_002',
            'password' => bcrypt('password123'),
            'status' => Status::ACTIVE,
            'is_login' => Ask::NO,
        ]);

        // Login
        $loginResponse = $this->withHeader('x-api-key', config('app.api_key', 'test-api-key'))
            ->postJson('/api/auth/kiosk-login', [
                'username' => 'kiosk_test_002',
                'password' => 'password123',
            ]);
        $loginResponse->assertStatus(201);
        $kioskToken = $loginResponse->json('token');

        // Créer une commande
        $item = \App\Models\Item::factory()->create(['status' => \App\Enums\Status::ACTIVE]);
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $kioskToken,
            'x-api-key' => config('app.api_key', 'test-api-key'),
        ])->postJson('/api/frontend/order', [
            'branch_id' => $branch->id,
            'subtotal' => $item->price,
            'total' => $item->price,
            'order_type' => 10, // Client envoie TAKEAWAY
            'is_advance_order' => 0,
            'source' => 1,
            'items' => json_encode([
                ['item_id' => $item->id, 'quantity' => 1, 'item_variations' => [], 'item_extras' => []]
            ]),
        ]);

        $response->assertStatus(201);
        // La commande doit avoir order_type = KIOSK (25), pas TAKEAWAY (10)
        $this->assertDatabaseHas('orders', [
            'branch_id' => $branch->id,
            'order_type' => \App\Enums\OrderType::KIOSK,
        ]);
    }
}
```

---

## VÉRIFICATIONS DE SYNCHRONISATION Sprint 9

1. `php artisan test --filter=KioskSecurityTest` — 2 tests PASS
2. `php artisan test` — 0 régression
3. Vérifier que les commandes borne ont bien `order_type = 25` en DB
4. Vérifier que le KDS affiche toujours les commandes borne (order_type=25 n'est pas filtré)
5. Vérifier que `FrontendOrder` avec BranchScope ne casse pas les tests existants

---

## RÉSUMÉ DES FICHIERS À MODIFIER

| Fichier | Modification |
|---------|--------------|
| `app/Http/Controllers/Auth/KioskMachineLoginController.php` | Transaction atomique is_login |
| `app/Models/FrontendOrder.php` | Vérifier/ajouter BranchScope |
| `app/Enums/OrderType.php` | Ajouter KIOSK = 25 |
| `app/Services/FrontendOrderService.php` | Forcer order_type = KIOSK pour les bornes |
| `tests/Feature/KioskSecurityTest.php` | NOUVEAU — tests |
