<?php

namespace Tests\Feature;

use App\Enums\Ask;
use App\Enums\OrderType;
use App\Enums\PaymentGateway;
use App\Enums\Source;
use App\Enums\Status;
use App\Models\Branch;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Smartisan\Settings\Facades\Settings;
use Tests\TestCase;

/**
 * [Test abusif final · P2/P3 · 2026-11-06] Défauts reproduits sur la version finale (serveur privé, base copiée) :
 *  - minimum de livraison contrôlé sur le subtotal ENVOYÉ (subtotal:999 passait) ;
 *  - produit réservé à la caisse (channels ["pos"]) commandable sur le site (commande 9898) ;
 *  - quantités d'options négatives / décimales stockées telles quelles (9893/9894) ;
 *  - un jeton CLIENT lisait 24 réglages /api/admin.
 */
class TestAbusifFinalP2P3Test extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;
    private User $client;
    private Item $item;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();
        $this->seedSpatieRoles();
        config(['app.api_key' => '123456']);
        Settings::group('order_setup')->set(['order_setup_food_preparation_time' => 30, 'order_setup_delivery' => 5, 'order_setup_takeaway' => 5]);
        $this->branch = Branch::forceCreate(['name' => 'B', 'city' => 'Paris', 'state' => 'IDF', 'zip_code' => '75000', 'address' => '1 rue', 'status' => 1]);
        $cat = ItemCategory::forceCreate(['name' => 'Menus', 'slug' => 'menus', 'status' => 5]);
        $this->item = Item::forceCreate(['name' => 'Burger Test', 'slug' => 'burger-test', 'price' => 7.40, 'status' => 5, 'item_category_id' => $cat->id]);
        $this->client = User::forceCreate(['name' => 'Client', 'email' => 'client-p2@test.local', 'username' => 'client_p2', 'phone' => '0600000099',
            'password' => bcrypt('password123'), 'branch_id' => $this->branch->id, 'status' => Status::ACTIVE]);
        $this->client->assignRole('Customer');
    }

    private function commande(array $item = [], array $extra = [])
    {
        $payload = array_merge([
            'branch_id' => $this->branch->id, 'subtotal' => 7.40, 'discount' => 0, 'delivery_charge' => 0, 'total' => 7.40,
            'order_type' => OrderType::TAKEAWAY, 'is_advance_order' => Ask::NO, 'source' => Source::WEB,
            'payment_method' => PaymentGateway::CASH_ON_DELIVERY, 'coupon_id' => null,
            'items' => json_encode([array_merge(['item_id' => $this->item->id, 'quantity' => 1, 'item_variations' => [], 'item_extras' => []], $item)]),
        ], $extra);

        return $this->actingAs($this->client, 'sanctum')->withHeader('x-api-key', '123456')->postJson('/api/frontend/order', $payload);
    }

    public function test_produit_reserve_a_la_caisse_refuse_sur_le_site(): void
    {
        $this->item->forceFill(['channels' => ['pos']])->save();

        $r = $this->commande();

        $r->assertStatus(422);
        $this->assertStringContainsString('comptoir', $r->getContent());
    }

    public function test_produit_sans_canal_ou_borne_reste_commandable(): void
    {
        $this->item->forceFill(['channels' => null])->save();
        $this->assertContains($this->commande()->status(), [200, 201]);
        $this->item->forceFill(['channels' => ['kiosk']])->save();
        $this->assertContains($this->commande()->status(), [200, 201]);
    }

    public function test_quantite_d_option_negative_ou_decimale_refusee(): void
    {
        // Un VRAI extra de l'article : sans le contrôle, la commande passe (201) et stocke -5 / 0.4 tels quels.
        $x = \App\Models\ItemExtra::forceCreate(['item_id' => $this->item->id, 'name' => 'Supplément fromage', 'price' => 1.00, 'status' => 5, 'group_label' => 'supplement']);
        $this->commande(['item_extras' => [['id' => $x->id, 'quantity' => 1]]], ['subtotal' => 8.40, 'total' => 8.40])->assertSuccessful(); // témoin
        $this->commande(['item_extras' => [['id' => $x->id, 'quantity' => -5]]])->assertStatus(422);
        $this->commande(['item_extras' => [['id' => $x->id, 'quantity' => 0.4]]])->assertStatus(422);
    }

    public function test_minimum_de_livraison_sur_le_sous_total_serveur_et_non_envoye(): void
    {
        $this->branch->forceFill(['delivery_minimum_order' => 20])->save();
        $this->branch->refresh();
        // On ne force que le contrôle ciblé : le subtotal ENVOYÉ (999) ne doit plus ouvrir la livraison.
        $svc = new \ReflectionClass(\App\Services\FrontendOrderService::class);
        $this->assertStringContainsString('delivery_minimum_order', file_get_contents($svc->getFileName()), 'contrôle déplacé dans le service');
        $req = file_get_contents(base_path('app/Http/Requests/OrderRequest.php'));
        $this->assertStringNotContainsString('$this->validateDeliveryMinimumOrder($validator);', $req, 'plus de contrôle sur le subtotal envoyé');
    }

    public function test_un_client_ne_lit_plus_les_reglages_admin(): void
    {
        foreach (['/api/admin/setting/branch', '/api/admin/setting/order-setup', '/api/admin/setting/tax', '/api/admin/dining-table'] as $route) {
            $this->actingAs($this->client, 'sanctum')->withHeader('x-api-key', '123456')->getJson($route)->assertStatus(403);
        }
    }

    public function test_le_personnel_garde_l_acces(): void
    {
        $admin = User::factory()->create(['branch_id' => 0]);
        $admin->assignRole('Admin');
        $this->assertNotSame(403, $this->actingAs($admin->fresh(), 'sanctum')->withHeader('x-api-key', '123456')->getJson('/api/admin/setting/branch')->status());
    }

    /** P2-2 — boisson de menu en rupture GLOBALE (items.is_available = 0) refusée aussi pour la caisse et la borne. */
    public function test_boisson_de_menu_en_rupture_globale_refusee(): void
    {
        $cat = ItemCategory::forceCreate(['name' => 'Boissons', 'slug' => 'boissons', 'status' => 5]);
        Item::forceCreate(['name' => 'Fanta Orange 33cl', 'slug' => 'fanta', 'price' => 2, 'status' => 5, 'is_available' => 0, 'item_category_id' => $cat->id]);
        $echecs = [];
        \App\Support\Menu\MenuFormulaChoiceAvailability::validateCollectionKeyedByItemIndex(
            [['instruction' => "Boisson menu: Fanta Orange 33cl"]],
            function (int $i, string $m) use (&$echecs) { $echecs[] = $m; },
            $this->branch->id
        );
        $this->assertCount(1, $echecs, 'boisson en rupture globale : refusée');
        $this->assertStringContainsString('plus disponible', $echecs[0]);
    }

    /** P3 — une perte saisie au-delà du stock est tracée à la quantité réellement retirée (la demande reste en note). */
    public function test_perte_superieure_au_stock_tracee_au_reel(): void
    {
        $caissier = User::factory()->create(['branch_id' => $this->branch->id]);
        $caissier->assignRole('POS Operator');
        \Spatie\Permission\Models\Permission::firstOrCreate(['name' => 'pos', 'guard_name' => 'sanctum']);
        $caissier->givePermissionTo('pos');
        \App\Models\StockLevel::query()->create(['branch_id' => $this->branch->id, 'stockable_type' => Item::class,
            'stockable_id' => $this->item->id, 'on_hand' => 2, 'reserved' => 0]);

        $r = $this->actingAs($caissier->fresh(), 'sanctum')->withHeaders(['x-api-key' => '123456', 'X-Idempotency-Key' => 'perte-' . uniqid()])
            ->postJson('/api/admin/pos/stock-outflow', ['item_id' => $this->item->id, 'quantity' => 999, 'type' => 'waste']);

        $this->assertContains($r->status(), [200, 201], $r->getContent());
        $o = \App\Models\StockOutflow::query()->latest('id')->first();
        $this->assertSame(2, (int) $o->quantity, 'perte tracée = 2 réellement retirés, pas 999');
        $this->assertStringContainsString('demandé 999', (string) $o->note);
        $this->assertSame(0, (int) \App\Models\StockLevel::query()->where('stockable_id', $this->item->id)->value('on_hand'));
    }
}
