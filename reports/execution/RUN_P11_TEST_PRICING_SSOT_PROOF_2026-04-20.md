# RUN — P11_TEST_PRICING_SSOT_PROOF (2026-04-20)

**Statut : SUCCESS**

**PRIMARY_MODEL :** Composer (foodking-routine-implementer)

## Route & auth (discovery)

- **POST** cible : `/api/admin/pos` (groupe `Route::prefix('pos')` sous `routes/api.php` ~L624, **pas** `pos-order` qui est le préfixe CRUD `PosOrderController`).
- **Middleware chaîne** (groupe `admin` ~L229) : `installed`, `apiKey`, `auth:sanctum`, `localization`, `throttle:admin-mutation` ; la route POST ajoute `throttle:pos-order-create`.

## PHPUnit (sortie complète)

```
PHPUnit 9.6.29 by Sebastian Bergmann and contributors.

.                                                                   1 / 1 (100%)

Time: 00:00.410, Memory: 61.00 MB

OK (1 test, 5 assertions)
```

**Commande :** `vendor/bin/phpunit --filter PosPricingSsotProofTest tests/Feature/PosPricingSsotProofTest.php`

## check-invariants.sh

```
== POS invariants CI guard (POS_INVARIANTS_AND_GATES.md §3) ==
  [1/6 SSOT pricing (no payload pricing)] ... OK
  [2/6 branch_id server-side only] ... OK
  [3/6 status via OrderStateMachine] ... OK
  [4/6 App\Events\* dispatch afterCommit] ... OK
  [5/6 EventContract envelope] ... OK
  [6/6 audit log on sensitive actions] ... OK

==> All 6 POS invariants clean.
```

**Confirmation : 6/6 OK**

## Fichier test créé

`tests/Feature/PosPricingSsotProofTest.php` (inline ci-dessous).

```php
<?php

namespace Tests\Feature;

use App\Enums\Ask;
use App\Enums\OrderType;
use App\Enums\PosPaymentMethod;
use App\Enums\Status;
use App\Enums\TaxType;
use App\Models\Branch;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Tax;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Sentinelle SSOT : prouve que le POS authentifié recalcule total / sous-total / prix ligne
 * et n'accepte pas des montants client truqués (contrairement à PricingIntegrityTest qui ne
 * couvre que le rejet frontend).
 */
class PosPricingSsotProofTest extends TestCase
{
    use RefreshDatabase;

    public function test_pos_order_overwrites_client_forged_pricing_with_ssot(): void
    {
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        $branch = Branch::factory()->create();

        $posUser = User::factory()->create([
            'branch_id' => $branch->id,
            'email' => 'pos-ssot-proof@test.com',
            'password' => Hash::make('password123'),
        ]);
        $posUser->assignRole('POS Operator');
        $posUser->givePermissionTo('pos');

        $customer = User::factory()->create([
            'branch_id' => $branch->id,
            'email' => 'customer-ssot-proof@test.com',
            'password' => Hash::make('password123'),
        ]);
        $customer->assignRole('Customer');

        $tax = Tax::factory()->create([
            'name' => 'No tax',
            'code' => 'ZERO-SSOT',
            'tax_rate' => 0,
            'type' => TaxType::PERCENTAGE,
            'status' => Status::ACTIVE,
        ]);

        $category = ItemCategory::factory()->create([
            'name' => 'SSOT Proof Category',
            'wizard_template' => 'simple',
            'has_menu' => false,
        ]);

        $item = Item::factory()->create([
            'item_category_id' => $category->id,
            'tax_id' => $tax->id,
            'name' => 'SSOT Proof Item',
            'price' => 10.00,
            'status' => Status::ACTIVE,
        ]);

        $this->actingAs($posUser, 'sanctum');

        $forgedSubtotal = 0.01;
        $forgedTotal = 0.01;
        $serverExpectedTotal = 20.00;

        $payload = [
            'token' => null,
            'customer_id' => $customer->id,
            'branch_id' => $branch->id,
            'subtotal' => $forgedSubtotal,
            'discount' => 0,
            'coupon_id' => 0,
            'total' => $forgedTotal,
            'order_type' => OrderType::TAKEAWAY,
            'is_advance_order' => Ask::NO,
            'source' => 1,
            'pos_payment_method' => PosPaymentMethod::CASH,
            'pos_received_amount' => $serverExpectedTotal,
            'items' => json_encode([
                [
                    'item_id' => $item->id,
                    'item_price' => 0.01,
                    'price' => 0.01,
                    'quantity' => 2,
                    'total_price' => 0.01,
                    'item_variations' => [],
                    'item_extras' => [],
                ],
            ]),
        ];

        $response = $this->postJson('/api/admin/pos', $payload);

        $this->assertContains(
            $response->status(),
            [200, 201],
            'La commande POS authentifiée ne doit pas être rejetée en 422 pour des montants truqués ; statut reçu : '.$response->status().' — '.$response->getContent()
        );

        $order = Order::latest()->first();
        $this->assertNotNull($order);

        $this->assertEqualsWithDelta(
            20.00,
            (float) $order->total,
            0.02,
            'Le total persisté doit refléter le prix SSOT (2 × 10), pas le total client truqué.'
        );

        $line = OrderItem::where('order_id', $order->id)->first();
        $this->assertNotNull($line);
        $this->assertEqualsWithDelta(
            10.00,
            (float) $line->price,
            0.01,
            'Le prix unitaire ligne doit venir de la DB (10.00), pas du payload (0.01).'
        );
    }
}
```

## Notes setup

- `items` doit être une **chaîne JSON** (`PosOrderRequest` + `ValidJsonOrder`), aligné sur `PosUITest`.
- Paiement **CASH** : `pos_received_amount` doit être **≥ total recalculé serveur** (`OrderService::posOrderStore` rejette sinon en 422) — d’où `pos_received_amount = 20.00` alors que `total` / `subtotal` payload restent truqués à `0.01`.
- Aucune modification `app/`, `PricingIntegrityTest.php`, ni commit.

## Risque résiduel / suivi validateur

- Aucun : test vert, invariants 6/6, pas de `BUG_FOUND_INVARIANT_BROKEN`.

---

## AUDIT (Claude orchestrateur) — 2026-04-20
**Verdict : CLOSED — PASSED — 0 remediation**

| # | Check | Résultat |
|---|---|---|
| 1 | Re-run isolé `vendor/bin/phpunit --filter PosPricingSsotProofTest` | **OK (1 test, 5 assertions)** en 0.491s |
| 2 | Fichier créé | `tests/Feature/PosPricingSsotProofTest.php` 4171 octets |
| 3 | Aucune modif `app/`, `routes/`, `tests/Feature/PricingIntegrityTest.php` | confirmé via `git status` |
| 4 | check-invariants.sh post-cycle | 6/6 OK |
| 5 | Route réelle vérifiée | `POST /api/admin/pos` (subagent a corrigé l'inexactitude du plan, route `pos-order` → `pos`) |

**Valeur produite** : sentinelle anti-régression SSOT pricing **runtime** (pas seulement statique). Toute future régression dans `OrderService::posOrderStore` ou `PricingService` qui laisserait passer un `form.total` truqué fera échouer ce test au prochain `phpunit`. Couvre directement F-VERIFY-16-02 et bloque la "bombe latente" F-VERIFY-18-* (pricing front).

**Note métier intelligente** captée par le subagent : `pos_received_amount` doit couvrir le total **recalculé** (20 €), pas le truqué (0.01 €) — preuve indirecte supplémentaire que le serveur calcule en SSOT.
