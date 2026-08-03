<?php

namespace Tests\Feature\Admin;

use App\Enums\Status;
use App\Models\Branch;
use App\Models\Item;
use App\Models\ItemAttribute;
use App\Models\ItemExtra;
use App\Models\ItemVariation;
use App\Models\StockLevel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * BUILD-4 Round 4 — N+1 sentinel for StockRuptureDashboardController::lowAlerts.
 *
 * Before the heal, `stockableLabel()` was invoked TWICE per row (lines 82+83 of the
 * pre-heal controller). For 200 rows × 3 polymorphic types that meant up to 1200
 * queries on a single endpoint hit. After the heal we bucket by stockable_type,
 * issue at most ONE whereIn per type, and resolve labels from an in-memory map.
 *
 * This sentinel asserts: with 60 mixed-type rows the endpoint must run with a
 * single-digit number of label-resolution queries (we allow ≤ 15 total queries
 * to cover auth + branches lookup + 3 whereIn + headroom for Laravel framework
 * overhead).
 */
class StockRuptureDashboardLowAlertsN1Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedMinimalSettings();
        $this->seedSpatieRoles();
        Cache::flush();
    }

    public function test_low_alerts_endpoint_does_not_trigger_per_row_label_query(): void
    {
        $branch = Branch::factory()->create();
        $admin = User::factory()->create(['branch_id' => 0]);
        $admin->assignRole('Admin');
        $admin->givePermissionTo(['items_show']);
        Sanctum::actingAs($admin, ['*']);

        $attribute = ItemAttribute::factory()->create(['status' => Status::ACTIVE]);

        // Seed 20 of each polymorphic type below the low-stock threshold (60 total).
        for ($i = 0; $i < 20; $i++) {
            $item = Item::factory()->create(['status' => Status::ACTIVE]);
            $variation = ItemVariation::query()->create([
                'item_id' => $item->id,
                'item_attribute_id' => $attribute->id,
                'name' => "Variation #{$i}",
                'price' => 0,
                'status' => Status::ACTIVE,
            ]);
            $extra = ItemExtra::query()->create([
                'item_id' => $item->id,
                'name' => "Extra #{$i}",
                'price' => 0,
                'status' => Status::ACTIVE,
            ]);

            StockLevel::query()->create([
                'branch_id' => $branch->id,
                'stockable_type' => Item::class,
                'stockable_id' => $item->id,
                'on_hand' => 1,
                'reserved' => 0,
                'threshold_low' => 5,
            ]);
            StockLevel::query()->create([
                'branch_id' => $branch->id,
                'stockable_type' => ItemVariation::class,
                'stockable_id' => $variation->id,
                'on_hand' => 1,
                'reserved' => 0,
                'threshold_low' => 5,
            ]);
            StockLevel::query()->create([
                'branch_id' => $branch->id,
                'stockable_type' => ItemExtra::class,
                'stockable_id' => $extra->id,
                'on_hand' => 1,
                'reserved' => 0,
                'threshold_low' => 5,
            ]);
        }

        DB::enableQueryLog();
        DB::flushQueryLog();
        $response = $this->getJson("/api/admin/stock/low-alerts?branch_id={$branch->id}");
        $response->assertOk();

        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        DB::flushQueryLog();

        // 60 rows total. With the N+1 heal we expect a single-digit number of
        // label-resolution queries (1 per stockable_type) + Laravel/auth framework
        // queries. Old behaviour would issue 120 finds (60 rows × 2 calls). We
        // cap the total query count at 15 to leave headroom for framework
        // overhead but still flag any per-row find re-introduction.
        // Dump count for evidence-gathering when running individually.
        fwrite(STDERR, "[stock-n1-heal] lowAlerts query count = " . count($queries) . PHP_EOL);

        $this->assertLessThanOrEqual(
            15,
            count($queries),
            'lowAlerts triggered too many queries — N+1 regression suspected. Got ' . count($queries)
        );

        // Response shape sanity — we got our 60 alerts back, all labelled.
        $payload = $response->json();
        $this->assertCount(60, $payload['alerts']);
        foreach ($payload['alerts'] as $alert) {
            $this->assertNotEmpty(
                $alert['stockable_name'],
                'stockable_name should be resolved (non-empty) for every alert'
            );
        }
    }
}
