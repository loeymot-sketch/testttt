<?php

namespace Tests\Feature\Profile;

use App\Enums\Ask;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Models\Branch;
use App\Models\Item;
use App\Models\ItemAddon;
use App\Models\ItemCategory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * [SUPERVISION 2026-11-13] « Recommander » ne contrôlait que l'article principal : un menu dont la boisson (« Boisson
 * menu: Coca-Cola », dans l'instruction) est aujourd'hui en rupture était annoncé DISPONIBLE, ajouté au panier, puis
 * refusé au paiement (422, FrontendOrderService::sansFormuleNonPayee) — sans aucune issue pour le client sur une ligne
 * « Recommander ». L'aperçu applique désormais la même garde que la commande.
 */
class RecommanderMenuBoissonEnRuptureTest extends TestCase
{
    use RefreshDatabase;

    private function scenario(bool $cocaDisponible): array
    {
        Cache::flush();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
        config(['app.api_key' => 'test-api-key']);
        $branch = Branch::factory()->create();

        $catPlats = ItemCategory::firstOrCreate(['slug' => 'plats-x'], ['name' => 'Plats', 'status' => 5]);
        $catBoissons = ItemCategory::firstOrCreate(['slug' => 'boissons-x'], ['name' => 'Boissons', 'status' => 5]);
        $tacos = Item::forceCreate(['name' => 'Tacos X', 'slug' => 'tacos-x', 'price' => 8, 'status' => 5, 'item_category_id' => $catPlats->id]);
        $menuItem = Item::forceCreate(['name' => 'Menu (Frites + Boisson)', 'slug' => 'menu-fb-x', 'price' => 3, 'status' => 5, 'item_category_id' => $catPlats->id]);
        $coca = Item::forceCreate(['name' => 'Coca-Cola', 'slug' => 'coca-x', 'price' => 2, 'status' => 5, 'item_category_id' => $catBoissons->id]);
        $addon = ItemAddon::create(['item_id' => $tacos->id, 'addon_item_id' => $menuItem->id, 'role' => 'menu_component']);
        $coca->forceFill(['is_available' => $cocaDisponible])->save();

        $u = User::factory()->create(['branch_id' => 0, 'phone' => '0611223344', 'email' => 'x@example.test', 'is_guest' => Ask::YES, 'status' => 5]);
        $u->assignRole('Customer');
        $o = Order::create([
            'order_serial_no' => 'X-1', 'branch_id' => $branch->id, 'user_id' => $u->id,
            'status' => OrderStatus::DELIVERED, 'payment_status' => PaymentStatus::PAID, 'subtotal' => 11, 'total' => 11,
            'order_type' => OrderType::TAKEAWAY, 'order_datetime' => now(), 'is_advance_order' => Ask::NO, 'source' => 5,
        ]);
        OrderItem::create([
            'order_id' => $o->id, 'branch_id' => $branch->id, 'item_id' => $tacos->id, 'quantity' => 1, 'price' => 8, 'discount' => 0,
            'item_variation_total' => 0, 'item_extra_total' => 0, 'total_price' => 11, 'tax_amount' => 0,
            'item_variations' => '[]', 'item_extras' => '[]',
            'instruction' => 'Boisson menu: Coca-Cola',
            'composition_snapshot' => json_encode(['lines' => [], 'extras' => [], 'addons' => [['addon_id' => $addon->id, 'addon_name' => 'Menu (Frites + Boisson)', 'role' => 'menu_full', 'quantity' => 1]]]),
        ]);

        $ligne = $this->actingAs($u, 'sanctum')->withHeader('x-api-key', 'test-api-key')
            ->getJson('/api/frontend/order/' . $o->id . '/reorder')->assertOk()->json('data.lines.0');

        return $ligne;
    }

    public function test_un_menu_dont_la_boisson_est_en_rupture_n_est_pas_annonce_disponible(): void
    {
        $ligne = $this->scenario(false);

        $this->assertFalse($ligne['available'], 'menu annoncé disponible alors que sa boisson est en rupture');
        $this->assertNull($ligne['payload']);
        $this->assertStringContainsString('Coca-Cola', (string) $ligne['message']);
    }

    public function test_le_meme_menu_avec_sa_boisson_disponible_reste_recommandable(): void
    {
        $ligne = $this->scenario(true);

        $this->assertTrue($ligne['available']);
        $this->assertSame('Boisson menu: Coca-Cola', $ligne['payload']['instruction'] ?? null);
    }
}
