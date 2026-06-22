<?php

namespace Tests\Feature\Menu;

use App\Enums\Ask;
use App\Enums\OrderType;
use App\Enums\PaymentGateway;
use App\Enums\Source;
use App\Enums\Status;
use App\Models\Branch;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\KioskMachine;
use App\Models\Tax;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Middlewares\PermissionMiddleware;
use Tests\TestCase;

class CatalogStockCentralSyncEndToEndTest extends TestCase
{
    use RefreshDatabase;

    public function test_central_stock_toggle_syncs_to_kiosk_pos_and_order_guard(): void
    {
        $this->seedMinimalSettings();
        $this->seedSpatieRoles();
        config(['app.api_key' => '123456']);

        $branch = Branch::forceCreate([
            'name' => 'Central Sync Branch',
            'city' => 'Paris',
            'state' => 'IDF',
            'zip_code' => '75000',
            'address' => '1 sync rue',
            'status' => 1,
        ]);

        $tax = Tax::create([
            'name' => 'TVA 10 Sync',
            'code' => 'TVASYNC' . uniqid(),
            'tax_rate' => 10,
            'type' => 2,
            'status' => 1,
        ]);

        $category = ItemCategory::forceCreate([
            'name' => 'Sync Category',
            'slug' => 'sync-category-' . uniqid(),
            'status' => Status::ACTIVE,
            'channels' => null,
        ]);

        $item = Item::forceCreate([
            'name' => 'Sync Burger',
            'slug' => 'sync-burger-' . uniqid(),
            'price' => 9.90,
            'status' => Status::ACTIVE,
            'is_available' => true,
            'channels' => null,
            'item_category_id' => $category->id,
            'tax_id' => $tax->id,
        ]);

        $kioskUser = User::factory()->create([
            'username' => 'sync_kiosk_' . uniqid(),
            'branch_id' => $branch->id,
        ]);
        $posOperator = User::factory()->create([
            'username' => 'sync_pos_' . uniqid(),
            'branch_id' => $branch->id,
        ]);
        $posOperator->assignRole('POS Operator');

        KioskMachine::create([
            'machine_id' => 'sync-kiosk-' . uniqid(),
            'branch_id' => $branch->id,
            'user_id' => $kioskUser->id,
            'username' => 'sync-kiosk-user',
            'password' => bcrypt('secret'),
            'is_login' => Ask::NO,
            'status' => Status::ACTIVE,
        ]);

        Cache::put("kiosk.menu.branch.{$branch->id}", ['stale' => true], 120);

        // [GOAL-2026-05-29] The admin catalog toggle MUST be driven by a legitimate
        // ADMIN actor. BlockKioskTokenFromAdminRoutes (J-ADV-6 PATH-1, 2026-05-24)
        // correctly refuses a kiosk-only token on /api/admin/* with 403 (closes a real
        // privilege-escalation). A session/guard admin yields a TransientToken (passes
        // the kiosk guard) and the Admin role satisfies permission:items_edit — so the
        // old withoutMiddleware(PermissionMiddleware) hack is dropped. The kiosk token
        // is used only for the /api/frontend/* surfaces below.
        $adminUser = User::factory()->create(['branch_id' => 0]);
        $adminUser->assignRole('Admin');

        $this
            ->actingAs($adminUser, 'sanctum')
            ->withHeader('x-api-key', '123456')
            ->postJson('/api/admin/menu/availability/toggle', [
                'item_id' => $item->id,
                'branch_id' => $branch->id,
                'is_available' => false,
                'unavailable_reason' => 'stock_rupture',
            ])
            ->assertOk()
            ->assertJsonPath('is_available', false);

        $this->assertDatabaseHas('item_branch_availability', [
            'item_id' => $item->id,
            'branch_id' => $branch->id,
            'is_available' => 0,
            'unavailable_reason' => 'stock_rupture',
        ]);
        $this->assertFalse(Cache::has("kiosk.menu.branch.{$branch->id}"));

        // Frontend kiosk surface uses the legitimate kiosk:order token (allowed on
        // /api/frontend/*; the admin actor above was only for the admin toggle).
        Sanctum::actingAs($kioskUser, ['kiosk:order']);
        $kioskMenu = $this
            ->withHeader('x-api-key', '123456')
            ->getJson('/api/frontend/menu')
            ->assertOk()
            ->json('data.items');

        $kioskRow = collect($kioskMenu)->firstWhere('id', $item->id);
        $this->assertFalse($kioskRow['is_available']);
        $this->assertSame('stock_rupture', $kioskRow['unavailable_reason']);

        $posRows = $this
            ->actingAs($posOperator, 'sanctum')
            ->withHeader('x-api-key', '123456')
            // Keep the real POS surface in the contract. ItemService provides
            // the SQLite fallback for JSON channels; MySQL still uses JSON_CONTAINS.
            ->getJson('/api/admin/item?paginate=0&status=' . Status::ACTIVE . '&surface=pos&branch_id=' . $branch->id)
            ->assertOk()
            ->json('data');

        $posRow = collect($posRows)->firstWhere('id', $item->id);
        $this->assertFalse($posRow['is_available']);
        $this->assertSame('stock_rupture', $posRow['availability_reason']);

        $payload = [
            'order_type' => OrderType::TAKEAWAY,
            'is_advance_order' => Ask::NO,
            'source' => Source::APP,
            'payment_method' => PaymentGateway::CASH_ON_DELIVERY,
            'items' => json_encode([[
                'item_id' => $item->id,
                'quantity' => 1,
                'instruction' => '',
                'item_variations' => [],
                'item_extras' => [],
            ]]),
        ];

        $this
            ->actingAs($kioskUser, 'sanctum')
            ->withHeader('x-api-key', '123456')
            ->postJson('/api/frontend/order/quote', $payload)
            ->assertStatus(422);
    }
}
