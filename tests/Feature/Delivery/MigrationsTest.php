<?php

namespace Tests\Feature\Delivery;

use App\Models\Branch;
use App\Models\DeliveryPlatform;
use App\Models\DeliveryPlatformExternalOrder;
use App\Models\DeliveryWebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * [PARALLEL-TRACK-1.1 / Delivery Platform Integration — Phase 1]
 *
 * End-to-end migration smoke test. Asserts the three new tables
 * exist with their expected columns, and uses behavioural probes
 * (insert + expect failure) for the UNIQUE constraints — much
 * more portable than introspecting indexes via raw SQL.
 */
class MigrationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_three_delivery_tables_exist(): void
    {
        $this->assertTrue(Schema::hasTable('delivery_platforms'));
        $this->assertTrue(Schema::hasTable('delivery_platform_external_orders'));
        $this->assertTrue(Schema::hasTable('delivery_webhook_events'));
    }

    public function test_delivery_platforms_has_expected_columns(): void
    {
        foreach ([
            'id',
            'branch_id',
            'platform',
            'enabled',
            'external_store_id',
            'credentials',
            'webhook_secret_rotated_at',
            'last_webhook_received_at',
            'last_status_pushed_at',
            'created_at',
            'updated_at',
        ] as $col) {
            $this->assertTrue(
                Schema::hasColumn('delivery_platforms', $col),
                "delivery_platforms missing column [$col]"
            );
        }
    }

    public function test_delivery_platform_external_orders_has_expected_columns(): void
    {
        foreach ([
            'id',
            'platform',
            'external_id',
            'frontend_order_id',
            'branch_id',
            'delivery_platform_id',
            'external_status',
            'internal_status',
            'raw_payload',
            'last_pushed_at',
            'last_push_error',
            'push_attempts',
            'received_at',
            'ingested_at',
            'created_at',
            'updated_at',
        ] as $col) {
            $this->assertTrue(
                Schema::hasColumn('delivery_platform_external_orders', $col),
                "delivery_platform_external_orders missing column [$col]"
            );
        }
    }

    public function test_delivery_webhook_events_has_expected_columns(): void
    {
        foreach ([
            'id',
            'platform',
            'event_type',
            'signature_header',
            'signature_valid',
            'nonce',
            'headers',
            'body',
            'branch_id',
            'delivery_platform_external_order_id',
            'processed_at',
            'processing_error',
            'received_at',
            'created_at',
            'updated_at',
        ] as $col) {
            $this->assertTrue(
                Schema::hasColumn('delivery_webhook_events', $col),
                "delivery_webhook_events missing column [$col]"
            );
        }
    }

    public function test_delivery_platforms_unique_platform_store_id_holds(): void
    {
        $branchA = Branch::factory()->create();
        $branchB = Branch::factory()->create();

        DeliveryPlatform::create([
            'branch_id'         => $branchA->id,
            'platform'          => 'uber_eats',
            'enabled'           => true,
            'external_store_id' => 'shared-store',
            'credentials'       => ['k' => 'v'],
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);
        DeliveryPlatform::create([
            'branch_id'         => $branchB->id,
            'platform'          => 'uber_eats',
            'enabled'           => true,
            'external_store_id' => 'shared-store', // duplicate (platform, store)
            'credentials'       => ['k' => 'v'],
        ]);
    }

    public function test_external_orders_unique_platform_external_id_holds(): void
    {
        $branch = Branch::factory()->create();

        $platform = DeliveryPlatform::create([
            'branch_id'         => $branch->id,
            'platform'          => 'deliveroo',
            'enabled'           => true,
            'external_store_id' => 'r-1',
            'credentials'       => ['k' => 'v'],
        ]);

        DeliveryPlatformExternalOrder::create([
            'platform'             => 'deliveroo',
            'external_id'          => 'duplicate-key',
            'branch_id'            => $branch->id,
            'delivery_platform_id' => $platform->id,
            'raw_payload'          => [],
            'received_at'          => now(),
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);
        DeliveryPlatformExternalOrder::create([
            'platform'             => 'deliveroo',
            'external_id'          => 'duplicate-key',
            'branch_id'            => $branch->id,
            'delivery_platform_id' => $platform->id,
            'raw_payload'          => [],
            'received_at'          => now(),
        ]);
    }

    public function test_webhook_events_persist_signature_invalid_with_null_branch(): void
    {
        // A bogus webhook hits us before we can resolve the platform/branch.
        $event = DeliveryWebhookEvent::create([
            'platform'         => 'uber_eats',
            'event_type'       => 'order.notification',
            'signature_header' => 'sha256=deadbeef',
            'signature_valid'  => false,
            'nonce'            => 'nonce-001',
            'headers'          => ['X-Foo' => 'bar'],
            'body'             => ['order_id' => 'whatever'],
            'branch_id'        => null,
            'received_at'      => now(),
        ]);

        $this->assertNotNull($event->id);
        $this->assertFalse($event->signature_valid);
        $this->assertNull($event->branch_id);
    }

    public function test_webhook_events_unique_platform_nonce_holds(): void
    {
        DeliveryWebhookEvent::create([
            'platform'         => 'delicity',
            'event_type'       => 'order.created',
            'signature_header' => 'sha256=ok',
            'signature_valid'  => true,
            'nonce'            => 'unique-nonce-1',
            'headers'          => [],
            'body'             => [],
            'received_at'      => now(),
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);
        DeliveryWebhookEvent::create([
            'platform'         => 'delicity',
            'event_type'       => 'order.created',
            'signature_header' => 'sha256=ok',
            'signature_valid'  => true,
            'nonce'            => 'unique-nonce-1', // duplicate
            'headers'          => [],
            'body'             => [],
            'received_at'      => now(),
        ]);
    }

    public function test_webhook_events_allow_multiple_null_nonce_rows(): void
    {
        // Some platforms (Delicity legacy) do not send a nonce.
        // The UNIQUE(platform, nonce) index treats NULL as distinct
        // on MySQL/SQLite/Postgres, so multiple null-nonce rows are OK.
        DeliveryWebhookEvent::create([
            'platform'         => 'delicity',
            'event_type'       => 'ping',
            'signature_header' => null,
            'signature_valid'  => true,
            'nonce'            => null,
            'headers'          => [],
            'body'             => [],
            'received_at'      => now(),
        ]);

        $second = DeliveryWebhookEvent::create([
            'platform'         => 'delicity',
            'event_type'       => 'ping',
            'signature_header' => null,
            'signature_valid'  => true,
            'nonce'            => null,
            'headers'          => [],
            'body'             => [],
            'received_at'      => now(),
        ]);

        $this->assertNotNull($second->id);
    }

    public function test_external_order_frontend_order_id_fk_is_enforced(): void
    {
        // Sanity: the FK on frontend_order_id rejects pointers to a
        // nonexistent Order. Distinct from the SET-NULL behavioural
        // test, which lives in DeliveryPlatformExternalOrderTest.
        $branch = Branch::factory()->create();

        $platform = DeliveryPlatform::create([
            'branch_id'         => $branch->id,
            'platform'          => 'uber_eats',
            'enabled'           => true,
            'external_store_id' => 'fk-probe',
            'credentials'       => ['k' => 'v'],
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);
        DeliveryPlatformExternalOrder::create([
            'platform'             => 'uber_eats',
            'external_id'          => 'fk-probe-1',
            'frontend_order_id'    => 999_999_999, // does not exist
            'branch_id'            => $branch->id,
            'delivery_platform_id' => $platform->id,
            'raw_payload'          => [],
            'received_at'          => now(),
        ]);
    }
}
