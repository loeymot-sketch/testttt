<?php

namespace Tests\Feature\Pos;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Branch;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Tax;
use App\Models\User;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * [SELF-AUDIT R5 P2 2026-07-05] OrderService::destroy soft-supprimait la commande SANS dispatcher
 * OrderCanceled → le décrément du quota journalier (daily_consumed_qty) posé à la création n'était JAMAIS
 * compensé → faux auto-86 (article marqué épuisé sans vente). Ce test verrouille : détruire une commande
 * libère le quota, comme l'annulation.
 */
class DestroyReleasesAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
    }

    public function test_destroy_releases_daily_availability_quota(): void
    {
        $branch = Branch::factory()->create();
        // Staff de la MÊME branche que la commande → passe la garde d'isolation (actorBranch===orderBranch).
        $admin = User::factory()->create(['branch_id' => $branch->id]);
        $admin->assignRole('POS Operator');
        $this->actingAs($admin);

        $category = ItemCategory::factory()->create();
        $tax = Tax::factory()->create();
        $item = Item::factory()->create(['item_category_id' => $category->id, 'tax_id' => $tax->id, 'price' => 10.00]);

        $order = Order::factory()->create([
            'branch_id' => $branch->id,
            'user_id' => $admin->id,
            'status' => OrderStatus::ACCEPT,
            'payment_status' => PaymentStatus::UNPAID, // pas besoin de pos-destroy-paid
        ]);

        OrderItem::forceCreate([
            'order_id' => $order->id,
            'branch_id' => $branch->id,
            'item_id' => $item->id,
            'quantity' => 3,
            'discount' => 0,
            'tax_name' => null,
            'tax_rate' => 0,
            'tax_type' => 1,
            'tax_amount' => 0,
            'price' => 10.00,
            'item_variation_total' => 0,
            'item_extra_total' => 0,
            'total_price' => 30.00,
            'released_qty' => 0,
            'released_at' => null,
        ]);

        DB::table('item_branch_availability')->insert([
            'branch_id' => $branch->id,
            'item_id' => $item->id,
            'is_available' => true,
            'unavailable_reason' => null,
            'max_daily_qty' => 10,
            'daily_consumed_qty' => 3, // décrémenté à la création
            'daily_reset_at' => now()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        app(OrderService::class)->destroy($order);

        // Le quota consommé est rendu (comme sur une annulation) — plus de faux auto-86.
        $this->assertDatabaseHas('item_branch_availability', [
            'branch_id' => $branch->id,
            'item_id' => $item->id,
            'daily_consumed_qty' => 0,
        ]);
    }
}
