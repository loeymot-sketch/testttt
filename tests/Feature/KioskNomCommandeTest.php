<?php

namespace Tests\Feature;

use App\Enums\Ask;
use App\Enums\Source;
use App\Enums\Status;
use App\Http\Resources\SimpleOrderResource;
use App\Models\Branch;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\KioskMachine;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithoutMiddleware;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * [NOM-COMMANDE-BORNE 2026-10-19 · owner] « Quand un client vient, il me dit : j'ai la commande au
 * nom de X. » Le nom saisi à la borne, avant de confirmer, est enregistré avec la commande et
 * retrouvé à la caisse.
 */
class KioskNomCommandeTest extends TestCase
{
    use RefreshDatabase, WithoutMiddleware;

    private Branch $branch;
    private array $payload;

    protected function setUp(): void
    {
        parent::setUp();
        \Smartisan\Settings\Facades\Settings::group('pos')->set(['pos_dine_in_enabled' => true]);
        $this->seedMinimalSettings();
        $this->seedSpatieRoles();
        config(['app.api_key' => 'test-api-key', 'pricing.tax_inclusive_prices' => true]);
        $this->withHeaders(['x-api-key' => 'test-api-key', 'Accept' => 'application/json']);

        $this->branch = Branch::forceCreate([
            'name' => 'Branch Cayenne', 'city' => 'Paris', 'state' => 'IDF',
            'zip_code' => '75000', 'address' => '1 rue Test', 'status' => 1,
        ]);
        $user = User::forceCreate([
            'name' => 'Compte Borne Technique', 'email' => 'kioskname@example.com',
            'username' => 'kiosknameuser', 'password' => bcrypt('password'),
            'status' => 5, 'branch_id' => $this->branch->id,
        ]);
        KioskMachine::forceCreate([
            'machine_id' => 'MACHINE_NAME', 'branch_id' => $this->branch->id, 'user_id' => $user->id,
            'username' => 'kiosk_name', 'password' => bcrypt('password123'),
            'status' => Status::ACTIVE, 'is_login' => Ask::NO,
        ]);
        Sanctum::actingAs($user, ['kiosk:order']);

        $category = ItemCategory::forceCreate(['name' => 'Sandwichs', 'slug' => 'sandwichs', 'status' => Status::ACTIVE]);
        $item = Item::forceCreate([
            'name' => 'Cayenne', 'slug' => 'cayenne', 'price' => 7.40,
            'status' => Status::ACTIVE, 'item_category_id' => $category->id,
        ]);

        $this->payload = [
            'branch_id' => $this->branch->id,
            'subtotal' => 7.40,
            'total' => 7.40,
            'order_type' => 25,
            'is_advance_order' => 0,
            'source' => Source::WEB,
            'items' => json_encode([['item_id' => $item->id, 'quantity' => 1, 'item_variations' => [], 'item_extras' => []]]),
        ];
    }

    private function commander(array $extra = []): Order
    {
        $quote = $this->postJson('/api/frontend/order/quote', $this->payload)->assertOk()->json('data');
        $res = $this->postJson('/api/frontend/order', $this->payload + $extra + [
            'quote_token' => $quote['quote_token'],
            'quote_signature' => $quote['signature'],
        ]);
        $res->assertStatus(201);

        return Order::findOrFail($res->json('data.id') ?? Order::latest('id')->value('id'));
    }

    public function test_le_nom_saisi_a_la_borne_est_enregistre_avec_la_commande(): void
    {
        $order = $this->commander(['pos_customer_name' => 'Karim']);

        $this->assertSame('Karim', $order->pos_customer_name);
    }

    public function test_la_caisse_lit_ce_nom_et_non_le_compte_technique_de_la_borne(): void
    {
        $order = $this->commander(['pos_customer_name' => 'Karim']);

        $json = (new SimpleOrderResource($order->fresh()))->toArray(request());

        $this->assertSame('Karim', $json['customer_name']);
        $this->assertNotSame('Compte Borne Technique', $json['customer_name']);
    }

    public function test_le_nom_est_nettoye_espaces_replies_et_caracteres_de_controle_retires(): void
    {
        $order = $this->commander(['pos_customer_name' => "  Ka\x1b@rim \n\t  Ben  "]);

        $this->assertStringNotContainsString("\x1b", (string) $order->pos_customer_name);
        $this->assertSame('Ka @rim Ben', $order->pos_customer_name);
    }

    public function test_le_nom_est_borne_a_60_caracteres(): void
    {
        // La validation refuse > 60 : la borne coupe à 30, un client forgé reçoit un 422 propre.
        $quote = $this->postJson('/api/frontend/order/quote', $this->payload)->assertOk()->json('data');
        $this->postJson('/api/frontend/order', $this->payload + [
            'pos_customer_name' => str_repeat('x', 61),
            'quote_token' => $quote['quote_token'], 'quote_signature' => $quote['signature'],
        ])->assertStatus(422);
    }

    public function test_sans_nom_la_commande_passe_toujours_et_aucun_nom_n_est_invente(): void
    {
        $order = $this->commander();
        $this->assertNull($order->pos_customer_name);

        $json = (new SimpleOrderResource($order->fresh()))->toArray(request());
        $this->assertNull($json['customer_name'], 'la borne n\'emprunte jamais le nom du compte technique');
    }

    public function test_un_nom_compose_uniquement_de_caracteres_invisibles_vaut_aucun_nom(): void
    {
        $order = $this->commander(['pos_customer_name' => "\x07\x1b  \t"]);

        $this->assertNull($order->pos_customer_name);
    }
}
