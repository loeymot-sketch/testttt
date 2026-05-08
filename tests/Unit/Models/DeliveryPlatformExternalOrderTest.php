<?php

namespace Tests\Unit\Models;

use App\Models\Branch;
use App\Models\DeliveryPlatform;
use App\Models\DeliveryPlatformExternalOrder;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [PARALLEL-TRACK-1.1 / Delivery Platform Integration — Phase 1]
 *
 * Verifies the DeliveryPlatformExternalOrder model:
 *   - UNIQUE(platform, external_id) holds at the DB layer;
 *   - frontend_order_id FK ON DELETE SET NULL fires correctly;
 *   - raw_payload is array-cast (json roundtrip).
 *
 * Note on FK SET NULL: SQLite has foreign_key_constraints=true in
 * config/database.php, so this assertion is meaningful in tests.
 */
class DeliveryPlatformExternalOrderTest extends TestCase
{
    use RefreshDatabase;

    private function makePlatform(?Branch $branch = null): DeliveryPlatform
    {
        $branch ??= Branch::factory()->create();

        return DeliveryPlatform::create([
            'branch_id'         => $branch->id,
            'platform'          => 'uber_eats',
            'enabled'           => true,
            'external_store_id' => 'store_'.$branch->id,
            'credentials'       => ['api_key' => 'k'],
        ]);
    }

    public function test_unique_platform_external_id_constraint_holds(): void
    {
        $platform = $this->makePlatform();

        DeliveryPlatformExternalOrder::create([
            'platform'             => 'uber_eats',
            'external_id'          => 'ext-001',
            'branch_id'            => $platform->branch_id,
            'delivery_platform_id' => $platform->id,
            'raw_payload'          => ['k' => 'v'],
            'received_at'          => now(),
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);
        DeliveryPlatformExternalOrder::create([
            'platform'             => 'uber_eats',
            'external_id'          => 'ext-001', // duplicate
            'branch_id'            => $platform->branch_id,
            'delivery_platform_id' => $platform->id,
            'raw_payload'          => ['k' => 'v2'],
            'received_at'          => now(),
        ]);
    }

    public function test_same_external_id_on_different_platforms_is_allowed(): void
    {
        $branch = Branch::factory()->create();

        $platformA = DeliveryPlatform::create([
            'branch_id'         => $branch->id,
            'platform'          => 'uber_eats',
            'enabled'           => true,
            'external_store_id' => 'a',
            'credentials'       => ['k' => 'v'],
        ]);

        $platformB = DeliveryPlatform::create([
            'branch_id'         => $branch->id,
            'platform'          => 'deliveroo',
            'enabled'           => true,
            'external_store_id' => 'b',
            'credentials'       => ['k' => 'v'],
        ]);

        DeliveryPlatformExternalOrder::create([
            'platform'             => 'uber_eats',
            'external_id'          => 'shared-id',
            'branch_id'            => $branch->id,
            'delivery_platform_id' => $platformA->id,
            'raw_payload'          => [],
            'received_at'          => now(),
        ]);

        // Same external_id under a different platform: no collision.
        $second = DeliveryPlatformExternalOrder::create([
            'platform'             => 'deliveroo',
            'external_id'          => 'shared-id',
            'branch_id'            => $branch->id,
            'delivery_platform_id' => $platformB->id,
            'raw_payload'          => [],
            'received_at'          => now(),
        ]);

        $this->assertNotNull($second->id);
    }

    public function test_raw_payload_is_array_cast(): void
    {
        $platform = $this->makePlatform();

        $payload = ['order' => ['id' => 'abc', 'items' => [['sku' => 'X', 'qty' => 2]]]];

        $row = DeliveryPlatformExternalOrder::create([
            'platform'             => 'uber_eats',
            'external_id'          => 'ext-payload-001',
            'branch_id'            => $platform->branch_id,
            'delivery_platform_id' => $platform->id,
            'raw_payload'          => $payload,
            'received_at'          => now(),
        ]);

        $this->assertSame($payload, $row->fresh()->raw_payload);
    }

    public function test_branch_scope_is_registered_on_model(): void
    {
        $scopes = (new DeliveryPlatformExternalOrder())->getGlobalScopes();
        $this->assertArrayHasKey(\App\Models\Scopes\BranchScope::class, $scopes);
    }

    public function test_frontend_order_id_is_set_to_null_when_referenced_order_is_force_deleted(): void
    {
        $platform = $this->makePlatform();

        // Use Order (not FrontendOrder) for the factory because OrderFactory
        // is bound to App\Models\Order. Both models share the same
        // `orders` table — the FK targets that table directly, so a row
        // created via Order is fine for the FK probe.
        $order = Order::factory()->create([
            'branch_id' => $platform->branch_id,
        ]);

        $external = DeliveryPlatformExternalOrder::create([
            'platform'             => 'uber_eats',
            'external_id'          => 'fk-set-null-1',
            'frontend_order_id'    => $order->id,
            'branch_id'            => $platform->branch_id,
            'delivery_platform_id' => $platform->id,
            'raw_payload'          => [],
            'received_at'          => now(),
        ]);

        $this->assertSame($order->id, $external->fresh()->frontend_order_id);

        // forceDelete (hard delete) is required because Order uses
        // SoftDeletes — soft-delete only updates deleted_at and never
        // triggers the SQL-level FK action.
        $order->forceDelete();

        $reloaded = $external->fresh();
        $this->assertNotNull($reloaded, 'External order row must still exist after parent delete (SET NULL, not CASCADE).');
        $this->assertNull(
            $reloaded->frontend_order_id,
            'frontend_order_id must be SET NULL when the referenced Order is force-deleted.'
        );
    }
}
