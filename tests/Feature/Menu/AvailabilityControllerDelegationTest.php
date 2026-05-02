<?php

namespace Tests\Feature\Menu;

use App\Enums\Status;
use App\Events\ItemAvailabilityChanged;
use App\Models\Branch;
use App\Models\Item;
use App\Models\ItemBranchAvailability;
use App\Models\ItemCategory;
use App\Models\Tax;
use App\Models\User;
use App\Services\Menu\AvailabilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Sentinel — CV1-V1-CLOSEOUT-001 T-CENT-DEDUP-AVAIL-01.
 *
 * Verrouille l'équivalence sémantique entre :
 *  - HTTP : POST /api/admin/menu/availability/toggle
 *  - Service direct : AvailabilityService::toggle()
 *
 * Avant ce refactor, le controller dupliquait la logique du service
 * (DB::transaction interne + lockForUpdate + flips manuels + dispatch
 * via DB::afterCommit). Après ce refactor, le controller ne fait que
 *  - auth + scope branche
 *  - délégation à AvailabilityService::toggle() (un seul SSOT)
 *  - shape de réponse JSON inchangée (API publique)
 *
 * Source: reports/audit/CV1_AUDIT_AXE3_ADMIN_ACTIONS_2026-05-02.md cas 4 P1.
 */
class AvailabilityControllerDelegationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedMinimalSettings();
        $this->seedSpatieRoles();
        Permission::firstOrCreate(['name' => 'items_edit', 'guard_name' => 'sanctum']);
    }

    public function test_http_toggle_produces_same_db_state_as_service_call(): void
    {
        Event::fake([ItemAvailabilityChanged::class]);

        [$admin, $branch, $item] = $this->makeAdminBranchItem();
        Sanctum::actingAs($admin, ['*']);

        // Path 1 : HTTP via controller — manual rupture (false).
        $response = $this->postJson('/api/admin/menu/availability/toggle', [
            'item_id' => $item->id,
            'branch_id' => $branch->id,
            'is_available' => false,
            'unavailable_reason' => 'rupture_http',
        ]);
        $response->assertOk();

        $rowAfterHttp = ItemBranchAvailability::query()
            ->where('item_id', $item->id)
            ->where('branch_id', $branch->id)
            ->first();
        $this->assertNotNull(
            $rowAfterHttp,
            'HTTP toggle must materialize the row via AvailabilityService::toggle (delegation invariant).'
        );
        $this->assertFalse((bool) $rowAfterHttp->is_available);
        $this->assertSame('rupture_http', $rowAfterHttp->unavailable_reason);
        $this->assertNotNull($rowAfterHttp->unavailable_since);

        // Path 2 : Service direct — re-availability flip on the same row.
        app(AvailabilityService::class)->toggle($item->id, $branch->id, true, null);

        $rowAfterService = $rowAfterHttp->fresh();
        $this->assertNotNull($rowAfterService);
        $this->assertTrue((bool) $rowAfterService->is_available);
        $this->assertNull($rowAfterService->unavailable_reason);
        $this->assertNull($rowAfterService->unavailable_since);

        // Both paths must dispatch ItemAvailabilityChanged after commit (one toggle = one event).
        Event::assertDispatchedTimes(ItemAvailabilityChanged::class, 2);
    }

    public function test_http_toggle_emits_item_availability_changed_event_with_branch_payload(): void
    {
        Event::fake([ItemAvailabilityChanged::class]);

        [$admin, $branch, $item] = $this->makeAdminBranchItem();
        Sanctum::actingAs($admin, ['*']);

        $this->postJson('/api/admin/menu/availability/toggle', [
            'item_id' => $item->id,
            'branch_id' => $branch->id,
            'is_available' => false,
            'unavailable_reason' => 'rupture_evt',
        ])->assertOk();

        Event::assertDispatched(
            ItemAvailabilityChanged::class,
            function (ItemAvailabilityChanged $event) use ($item, $branch): bool {
                return $event->itemId === (int) $item->id
                    && $event->branchId === (int) $branch->id
                    && $event->isAvailable === false
                    && $event->reason === 'rupture_evt';
            }
        );
    }

    public function test_http_toggle_response_shape_preserved(): void
    {
        Event::fake([ItemAvailabilityChanged::class]);

        [$admin, $branch, $item] = $this->makeAdminBranchItem();
        Sanctum::actingAs($admin, ['*']);

        $response = $this->postJson('/api/admin/menu/availability/toggle', [
            'item_id' => $item->id,
            'branch_id' => $branch->id,
            'is_available' => false,
            'unavailable_reason' => 'shape_test',
        ]);

        $response->assertOk();
        // Public API contract — same keys/types as before the delegation refactor.
        // CatalogStockCentralSyncEndToEndTest and the admin SPA depend on this exact shape.
        $response->assertJsonStructure([
            'ok',
            'item_id',
            'branch_id',
            'is_available',
            'unavailable_reason',
        ]);
        $response->assertExactJson([
            'ok' => true,
            'item_id' => (int) $item->id,
            'branch_id' => (int) $branch->id,
            'is_available' => false,
            'unavailable_reason' => 'shape_test',
        ]);
    }

    /**
     * @return array{0: User, 1: Branch, 2: Item}
     */
    private function makeAdminBranchItem(): array
    {
        $branch = Branch::factory()->create();

        $admin = User::factory()->create(['branch_id' => 0]);
        $admin->assignRole('Admin');
        $admin->givePermissionTo('items_edit');

        $category = ItemCategory::factory()->create(['status' => Status::ACTIVE]);
        $tax = Tax::factory()->create(['status' => Status::ACTIVE]);
        $item = Item::factory()->create([
            'item_category_id' => $category->id,
            'tax_id' => $tax->id,
            'status' => Status::ACTIVE,
        ]);

        return [$admin, $branch, $item];
    }
}
