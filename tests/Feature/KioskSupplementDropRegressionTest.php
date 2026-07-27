<?php

namespace Tests\Feature;

use App\Enums\Ask;
use App\Enums\Source;
use App\Enums\Status;
use App\Models\Branch;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\ItemExtra;
use App\Models\KioskMachine;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithoutMiddleware;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * [B1 GOAL 2026-07-21] Régression du bug owner : « borne — au paiement final tous
 * les suppléments s'annulent, le prix baisse, le ticket n'imprime que le produit
 * de base ». Reproduit la structure Cayenne (base 7,40 € + suppléments payants +
 * crudités gratuites) et prouve que la commande borne SCELLE tous les suppléments
 * au bon prix ET que le composition_snapshot (SSOT NF525 du ticket) les contient.
 * Si un supplément est largué en silence → total sous-facturé + snapshot incomplet
 * = ce test DOIT échouer.
 */
class KioskSupplementDropRegressionTest extends TestCase
{
    use RefreshDatabase, WithoutMiddleware;

    protected function setUp(): void
    {
        parent::setUp();
        \Smartisan\Settings\Facades\Settings::group('pos')->set(['pos_dine_in_enabled' => true]); // [2026-07-27] garde V1 sur-place (47f3ad545) : OFF par défaut — ce test exerce un flux sur-place/table derrière son flag
        $this->seedMinimalSettings();
        $this->seedSpatieRoles();
        config(['app.api_key' => 'test-api-key']);
        config(['pricing.tax_inclusive_prices' => true]);
        $this->withHeaders([
            'x-api-key' => 'test-api-key',
            'Accept' => 'application/json',
        ]);
    }

    public function test_kiosk_seals_all_paid_supplements_and_snapshot_is_complete(): void
    {
        $branch = Branch::forceCreate([
            'name' => 'Branch Cayenne', 'city' => 'Paris', 'state' => 'IDF',
            'zip_code' => '75000', 'address' => '1 rue Test', 'status' => 1,
        ]);
        $user = User::forceCreate([
            'name' => 'Kiosk User', 'email' => 'kioskdrop@example.com',
            'username' => 'kioskdropuser', 'password' => bcrypt('password'),
            'status' => 5, 'branch_id' => $branch->id,
        ]);
        KioskMachine::forceCreate([
            'machine_id' => 'MACHINE_DROP', 'branch_id' => $branch->id, 'user_id' => $user->id,
            'username' => 'kiosk_drop', 'password' => bcrypt('password123'),
            'status' => Status::ACTIVE, 'is_login' => Ask::NO,
        ]);
        Sanctum::actingAs($user, ['kiosk:order']);

        $category = ItemCategory::forceCreate([
            'name' => 'Sandwichs', 'slug' => 'sandwichs', 'status' => Status::ACTIVE,
        ]);
        // Cayenne-like : base 7,40 €
        $item = Item::forceCreate([
            'name' => 'Cayenne', 'slug' => 'cayenne', 'price' => 7.40,
            'status' => Status::ACTIVE, 'item_category_id' => $category->id,
        ]);
        $mk = fn (string $name, float $price, string $group) => ItemExtra::forceCreate([
            'item_id' => $item->id, 'name' => $name, 'price' => $price,
            'group_label' => $group, 'status' => Status::ACTIVE, 'is_available' => 1,
        ]);
        // Crudités gratuites (garnitures) + suppléments payants
        $salade  = $mk('Salade', 0.00, 'crudite');
        $cheddar = $mk('Cheddar', 0.90, 'supplement');
        $viande  = $mk('Viande supplémentaire', 2.50, 'supplement');
        $sauce   = $mk('Sauce supplémentaire', 0.50, 'sauce');

        // 7,40 + 0,90 + 2,50 + 0,50 = 11,30 €
        $expectedTotal = 11.30;

        $items = [[
            'item_id' => $item->id,
            'quantity' => 1,
            'item_variations' => [],
            'item_extras' => [
                ['id' => $salade->id],
                ['id' => $cheddar->id],
                ['id' => $viande->id],
                ['id' => $sauce->id],
            ],
        ]];

        $payload = [
            'branch_id' => $branch->id,
            'subtotal' => $expectedTotal,
            'total' => $expectedTotal,
            'order_type' => 25, // KIOSK sur place
            'is_advance_order' => 0,
            'source' => Source::WEB,
            'items' => json_encode($items),
        ];

        // Quote (sceau) puis order — flux borne réel.
        $quote = $this->postJson('/api/frontend/order/quote', $payload)
            ->assertOk()->json('data');

        $sealed = $payload + [
            'quote_token' => $quote['quote_token'],
            'quote_signature' => $quote['signature'],
        ];

        $response = $this->postJson('/api/frontend/order', $sealed);
        $response->assertStatus(201);

        // 1) Le total scellé DOIT inclure tous les suppléments (pas de baisse silencieuse)
        $orderId = $response->json('data.id') ?? Order::latest('id')->first()->id;
        $order = Order::find($orderId);
        $this->assertEqualsWithDelta(
            $expectedTotal, (float) $order->total,
            0.01,
            "Le total scellé ({$order->total}) doit égaler l'affichage borne ({$expectedTotal}). Baisse = suppléments largués."
        );

        // 2) Le composition_snapshot (SSOT ticket/KDS NF525) DOIT contenir les 3 payants
        $orderItem = OrderItem::where('order_id', $orderId)->first();
        $snapshot = $orderItem->composition_snapshot;
        $snapshot = is_string($snapshot) ? json_decode($snapshot, true) : (array) $snapshot;
        $snapExtraNames = collect($snapshot['extras'] ?? [])->pluck('extra_name')->all();

        foreach (['Cheddar', 'Viande supplémentaire', 'Sauce supplémentaire'] as $must) {
            $this->assertContains(
                $must,
                $snapExtraNames,
                "Supplément « {$must} » ABSENT du composition_snapshot → ticket base-only (bug owner)."
            );
        }

        // 3) item_extra_total facturé = 3,90 €
        $this->assertEqualsWithDelta(3.90, (float) $orderItem->item_extra_total, 0.01);
    }
}
