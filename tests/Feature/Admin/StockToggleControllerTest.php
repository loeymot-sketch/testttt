<?php

namespace Tests\Feature\Admin;

use App\Enums\Status;
use App\Models\ActionLog;
use App\Models\Branch;
use App\Models\Item;
use App\Models\ItemAttribute;
use App\Models\ItemBranchAvailability;
use App\Models\ItemCategory;
use App\Models\ItemExtra;
use App\Models\ItemVariation;
use App\Models\Tax;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * [F-016b minimal]
 *
 * Covers the three actions exposed by {@see \App\Http\Controllers\Admin\StockToggleController}
 * plus the per-branch isolation rule (admin@branch=0 = head-office, admin@branch=N
 * pinned to branch N) and ActionLog write-on-toggle.
 */
class StockToggleControllerTest extends TestCase
{
    use RefreshDatabase;

    private const HEADER_KEY = 'x-api-key';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedMinimalSettings();
        $this->seedSpatieRoles();
        Permission::firstOrCreate(['name' => 'items_edit', 'guard_name' => 'sanctum']);
    }

    /**
     * Admin (branch_id=0) can list every entity for an arbitrary branch.
     */
    public function test_admin_can_list_stock_items_for_branch(): void
    {
        $branch = Branch::factory()->create();
        $admin  = $this->actingAsAdmin(branchId: 0);

        $category = ItemCategory::factory()->create(['status' => Status::ACTIVE]);
        $tax      = Tax::factory()->create(['status' => Status::ACTIVE]);
        $item     = Item::factory()->create([
            'item_category_id' => $category->id,
            'tax_id'           => $tax->id,
            'status'           => Status::ACTIVE,
        ]);

        $extra = ItemExtra::create([
            'item_id' => $item->id,
            'name'    => 'Sauce Algérienne',
            'price'   => 0.50,
            'status'  => Status::ACTIVE,
        ]);

        $attribute = ItemAttribute::create(['name' => 'Sauce', 'status' => Status::ACTIVE]);
        $variation = ItemVariation::create([
            'item_id'           => $item->id,
            'item_attribute_id' => $attribute->id,
            'name'              => 'Mayo',
            'price'             => 0,
            'status'            => Status::ACTIVE,
        ]);

        $response = $this->withApiKey()
            ->getJson('/api/admin/stock?branch_id=' . $branch->id);

        $response->assertOk();
        $response->assertJsonPath('branch_id', $branch->id);
        $response->assertJsonPath('extras_toggle_supported', false);
        $response->assertJsonPath('variations_toggle_supported', false);

        // Items / extras / variations all surface our seeded rows.
        $body = $response->json();
        $this->assertNotEmpty($body['items']);
        $this->assertSame((int) $item->id, $body['items'][0]['id']);

        $this->assertCount(1, $body['extras']);
        $this->assertSame((int) $extra->id, $body['extras'][0]['id']);
        $this->assertSame($item->name, $body['extras'][0]['item_name']);

        $this->assertCount(1, $body['variations']);
        $this->assertSame((int) $variation->id, $body['variations'][0]['id']);
        $this->assertSame('Sauce', $body['variations'][0]['attribute_name']);
    }

    public function test_admin_can_toggle_item_unavailable_branch(): void
    {
        $branch = Branch::factory()->create();
        $this->actingAsAdmin(branchId: 0);

        $category = ItemCategory::factory()->create(['status' => Status::ACTIVE]);
        $tax      = Tax::factory()->create(['status' => Status::ACTIVE]);
        $item     = Item::factory()->create([
            'item_category_id' => $category->id,
            'tax_id'           => $tax->id,
            'status'           => Status::ACTIVE,
        ]);

        $response = $this->withApiKey()
            ->postJson('/api/admin/stock/toggle', [
                'entity_type'  => 'item',
                'entity_id'    => $item->id,
                'branch_id'    => $branch->id,
                'is_available' => false,
                'reason'       => 'rupture',
            ]);

        $response->assertOk();
        $response->assertJson([
            'status'             => 'ok',
            'entity_type'        => 'item',
            'entity_id'          => $item->id,
            'branch_id'          => $branch->id,
            'is_available'       => false,
            'unavailable_reason' => 'rupture',
        ]);

        // The canonical AvailabilityService row is persisted.
        $this->assertDatabaseHas('item_branch_availability', [
            'item_id'            => $item->id,
            'branch_id'          => $branch->id,
            'is_available'       => 0,
            'unavailable_reason' => 'rupture',
        ]);
    }

    public function test_admin_cannot_toggle_extra_returns_501_until_f016a_bis(): void
    {
        $branch = Branch::factory()->create();
        $this->actingAsAdmin(branchId: 0);

        $category = ItemCategory::factory()->create(['status' => Status::ACTIVE]);
        $tax      = Tax::factory()->create(['status' => Status::ACTIVE]);
        $item     = Item::factory()->create([
            'item_category_id' => $category->id,
            'tax_id'           => $tax->id,
            'status'           => Status::ACTIVE,
        ]);
        $extra = ItemExtra::create([
            'item_id' => $item->id,
            'name'    => 'Cheddar',
            'price'   => 0.50,
            'status'  => Status::ACTIVE,
        ]);

        $response = $this->withApiKey()
            ->postJson('/api/admin/stock/toggle', [
                'entity_type'  => 'extra',
                'entity_id'    => $extra->id,
                'branch_id'    => $branch->id,
                'is_available' => false,
                'reason'       => 'seasonal',
            ]);

        $response->assertStatus(501);
        $response->assertJsonPath('pending_feature', 'F-016a-BIS');
        // No row is written when the feature is gated.
        $this->assertDatabaseCount('item_branch_availability', 0);
    }

    public function test_admin_cannot_toggle_variation_returns_501_until_f016a_bis(): void
    {
        $branch = Branch::factory()->create();
        $this->actingAsAdmin(branchId: 0);

        $category  = ItemCategory::factory()->create(['status' => Status::ACTIVE]);
        $tax       = Tax::factory()->create(['status' => Status::ACTIVE]);
        $item      = Item::factory()->create([
            'item_category_id' => $category->id,
            'tax_id'           => $tax->id,
            'status'           => Status::ACTIVE,
        ]);
        $attribute = ItemAttribute::create(['name' => 'Sauce', 'status' => Status::ACTIVE]);
        $variation = ItemVariation::create([
            'item_id'           => $item->id,
            'item_attribute_id' => $attribute->id,
            'name'              => 'Mayo',
            'price'             => 0,
            'status'            => Status::ACTIVE,
        ]);

        $response = $this->withApiKey()
            ->postJson('/api/admin/stock/toggle', [
                'entity_type'  => 'variation',
                'entity_id'    => $variation->id,
                'branch_id'    => $branch->id,
                'is_available' => false,
                'reason'       => 'seasonal',
            ]);

        $response->assertStatus(501);
        $response->assertJsonPath('pending_feature', 'F-016a-BIS');
    }

    public function test_staff_without_items_edit_cannot_toggle_returns_403(): void
    {
        $branch = Branch::factory()->create();
        $stuff  = User::factory()->create(['branch_id' => 0]);
        // Stuff role has no items_edit (see seedSpatieRoles).
        $stuff->assignRole('Stuff');
        Sanctum::actingAs($stuff, ['*']);

        $category = ItemCategory::factory()->create(['status' => Status::ACTIVE]);
        $tax      = Tax::factory()->create(['status' => Status::ACTIVE]);
        $item     = Item::factory()->create([
            'item_category_id' => $category->id,
            'tax_id'           => $tax->id,
            'status'           => Status::ACTIVE,
        ]);

        $response = $this->withApiKey()
            ->postJson('/api/admin/stock/toggle', [
                'entity_type'  => 'item',
                'entity_id'    => $item->id,
                'branch_id'    => $branch->id,
                'is_available' => false,
                'reason'       => 'rupture',
            ]);

        $response->assertStatus(403);
        $this->assertDatabaseCount('item_branch_availability', 0);
    }

    public function test_audit_log_written_on_toggle(): void
    {
        $branch = Branch::factory()->create();
        $admin  = $this->actingAsAdmin(branchId: 0);

        $category = ItemCategory::factory()->create(['status' => Status::ACTIVE]);
        $tax      = Tax::factory()->create(['status' => Status::ACTIVE]);
        $item     = Item::factory()->create([
            'item_category_id' => $category->id,
            'tax_id'           => $tax->id,
            'status'           => Status::ACTIVE,
        ]);

        $this->withApiKey()
            ->postJson('/api/admin/stock/toggle', [
                'entity_type'  => 'item',
                'entity_id'    => $item->id,
                'branch_id'    => $branch->id,
                'is_available' => false,
                'reason'       => 'rupture',
            ])
            ->assertOk();

        $this->assertDatabaseHas('action_logs', [
            'user_id'   => $admin->id,
            'branch_id' => $branch->id,
            'action'    => 'stock.toggle.unavailable',
            'resource'  => 'item:' . $item->id,
        ]);

        // Toggling back to available writes the inverse action.
        $this->withApiKey()
            ->postJson('/api/admin/stock/toggle', [
                'entity_type'  => 'item',
                'entity_id'    => $item->id,
                'branch_id'    => $branch->id,
                'is_available' => true,
            ])
            ->assertOk();

        $this->assertDatabaseHas('action_logs', [
            'branch_id' => $branch->id,
            'action'    => 'stock.toggle.available',
            'resource'  => 'item:' . $item->id,
        ]);
    }

    public function test_branch_isolation_admin_branch_a_cannot_toggle_branch_b(): void
    {
        $branchA = Branch::factory()->create();
        $branchB = Branch::factory()->create();

        // Branch admin pinned to branch A.
        $branchAdmin = User::factory()->create(['branch_id' => $branchA->id]);
        $branchAdmin->assignRole('Branch Manager');
        $branchAdmin->givePermissionTo('items_edit');
        Sanctum::actingAs($branchAdmin, ['*']);

        $category = ItemCategory::factory()->create(['status' => Status::ACTIVE]);
        $tax      = Tax::factory()->create(['status' => Status::ACTIVE]);
        $item     = Item::factory()->create([
            'item_category_id' => $category->id,
            'tax_id'           => $tax->id,
            'status'           => Status::ACTIVE,
        ]);

        $response = $this->withApiKey()
            ->postJson('/api/admin/stock/toggle', [
                'entity_type'  => 'item',
                'entity_id'    => $item->id,
                'branch_id'    => $branchB->id, // <-- foreign branch
                'is_available' => false,
                'reason'       => 'rupture',
            ]);

        $response->assertStatus(403);
        $this->assertDatabaseMissing('item_branch_availability', [
            'item_id'   => $item->id,
            'branch_id' => $branchB->id,
        ]);
    }

    public function test_audit_endpoint_returns_recent_stock_logs_for_branch(): void
    {
        $branch = Branch::factory()->create();
        $this->actingAsAdmin(branchId: 0);

        // Hand-write two stock.* rows + one unrelated row (must be filtered out).
        ActionLog::create([
            'branch_id' => $branch->id,
            'action'    => 'stock.toggle.unavailable',
            'resource'  => 'item:1',
            'details'   => json_encode(['reason' => 'rupture']),
        ]);
        ActionLog::create([
            'branch_id' => $branch->id,
            'action'    => 'stock.toggle.available',
            'resource'  => 'item:1',
            'details'   => json_encode([]),
        ]);
        ActionLog::create([
            'branch_id' => $branch->id,
            'action'    => 'order.created', // <-- filtered out
            'resource'  => 'order:42',
            'details'   => json_encode([]),
        ]);

        $response = $this->withApiKey()
            ->getJson('/api/admin/stock/audit?branch_id=' . $branch->id);

        $response->assertOk();
        $response->assertJsonCount(2, 'logs');
        // Newest first.
        $response->assertJsonPath('logs.0.action', 'stock.toggle.available');
        $response->assertJsonPath('logs.1.action', 'stock.toggle.unavailable');
    }

    /**
     * Helper — log in as an Admin user pinned to the requested branch.
     * `Admin` already has `items_edit` via {@see Tests\TestCase::seedSpatieRoles()};
     * we don't re-grant it explicitly to keep the role wiring authoritative.
     */
    private function actingAsAdmin(int $branchId): User
    {
        $admin = User::factory()->create(['branch_id' => $branchId]);
        $admin->assignRole('Admin');
        Sanctum::actingAs($admin, ['*']);

        return $admin;
    }

    private function withApiKey(): self
    {
        return $this->withHeader(self::HEADER_KEY, config('app.api_key', env('MIX_API_KEY', 'test-api-key')));
    }
}
