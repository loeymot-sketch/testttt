<?php

namespace Tests\Feature\Order;

use App\Enums\OrderType;
use App\Enums\Source;
use App\Enums\Status;
use App\Models\Branch;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * [W7-D D-015 2026-11-04] `order_type` et `source` n'étaient que `numeric` : un client pouvait déclarer sa commande
 * web comme POS (type 15 → absente de son historique, comptée vente caisse au tableau de bord), comme un type
 * inconnu (99 → invisible à l'écran d'attente) ou venue de la caisse (source 15). Seuls livraison / à emporter /
 * table / borne et site / application sont recevables ; tout le reste → 422 sans commande.
 */
class CommandeTypeEtSourceBornesTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branche;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();
        $this->seedSpatieRoles();
        config(['app.api_key' => 'test-api-key']);
        $this->withHeaders(['x-api-key' => 'test-api-key', 'Accept' => 'application/json']);

        $this->branche = Branch::forceCreate(['name' => 'Le Cayenne', 'city' => 'Hénin', 'state' => 'HdF', 'zip_code' => '62110', 'address' => '1 rue', 'status' => Status::ACTIVE]);
        $u = User::forceCreate([
            'name' => 'Client W7', 'email' => 'client-w7-bornes@example.test', 'username' => 'client-w7-bornes',
            'password' => bcrypt('x'), 'status' => 5, 'branch_id' => 0,
        ]);
        Sanctum::actingAs($u, ['kiosk:order']);
    }

    private function charge(int $orderType, int $source): array
    {
        $category = ItemCategory::firstOrCreate(['slug' => 'cat-w7'], ['name' => 'Cat', 'status' => Status::ACTIVE]);
        $item = Item::firstOrCreate(['slug' => 'article-w7'], [
            'name' => 'Article W7', 'price' => 10.00, 'status' => Status::ACTIVE, 'item_category_id' => $category->id,
        ]);

        return [
            'branch_id'        => $this->branche->id,
            'subtotal'         => 10,
            'total'            => 10,
            'order_type'       => $orderType,
            'is_advance_order' => 0,
            'source'           => $source,
            'items'            => json_encode([
                ['item_id' => $item->id, 'quantity' => 1, 'item_variations' => [], 'item_extras' => []],
            ]),
        ];
    }

    /** @test */
    public function un_type_pos_ou_inconnu_est_refuse_sans_commande(): void
    {
        foreach ([OrderType::POS, 99, 0] as $type) {
            $this->postJson('/api/frontend/order', $this->charge($type, Source::WEB))
                ->assertStatus(422)
                ->assertJsonValidationErrors(['order_type']);
        }

        $this->assertDatabaseMissing('orders', ['order_type' => OrderType::POS]);
        $this->assertDatabaseMissing('orders', ['order_type' => 99]);
    }

    /** @test */
    public function une_source_pos_ou_inconnue_est_refusee_sans_commande(): void
    {
        foreach ([Source::POS, 1, 42] as $source) {
            $this->postJson('/api/frontend/order', $this->charge(OrderType::TAKEAWAY, $source))
                ->assertStatus(422)
                ->assertJsonValidationErrors(['source']);
        }

        $this->assertDatabaseCount('orders', 0);
    }

    /** @test */
    public function le_site_et_l_application_restent_commandables(): void
    {
        $this->postJson('/api/frontend/order', $this->charge(OrderType::TAKEAWAY, Source::WEB))->assertStatus(201);
        $this->postJson('/api/frontend/order', $this->charge(OrderType::TAKEAWAY, Source::APP))->assertStatus(201);

        $this->assertDatabaseHas('orders', ['order_type' => OrderType::TAKEAWAY, 'source' => Source::WEB]);
        $this->assertDatabaseHas('orders', ['order_type' => OrderType::TAKEAWAY, 'source' => Source::APP]);
    }
}
