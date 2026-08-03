<?php

namespace Tests\Feature\Admin;

use App\Enums\Status;
use App\Models\Branch;
use App\Models\Item;
use App\Models\ItemAttribute;
use App\Models\ItemVariation;
use App\Models\StockLevel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StockRuptureDashboardEndpointsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedMinimalSettings();
        $this->seedSpatieRoles();
        Cache::flush();
        config(['catalog_v15.auto_86_preventive_cron.enabled' => true]);
    }

    public function test_dashboard_endpoints_return_summary_alerts_and_manual_run_result(): void
    {
        $branch = Branch::factory()->create();
        $admin = User::factory()->create(['branch_id' => 0]);
        $admin->assignRole('Admin');
        $admin->givePermissionTo(['items_show', 'items_create']);
        Sanctum::actingAs($admin, ['*']);

        [$item, $first, $second] = $this->itemWithTwoVariations();
        $this->stock($branch, $first->id, 0);
        $this->stock($branch, $second->id, 0);

        Cache::put("stock_scan_rupture:last_summary:branch:{$branch->id}", [
            'branch_id' => $branch->id,
            'items_scanned' => 1,
            'items_flipped' => 0,
            'items_would_flip' => 0,
            'items_already_unavailable' => 0,
            'items_skipped_partial_stock' => 0,
            'duration_ms' => 1,
            'ran_at' => now()->toIso8601String(),
            'dry_run' => false,
        ], now()->addDay());

        $this->getJson("/api/admin/stock/scan-rupture/last-summary?branch_id={$branch->id}")
            ->assertOk()
            ->assertJsonPath('cron_enabled', true)
            ->assertJsonPath('branches.0.branch_id', $branch->id)
            ->assertJsonPath('branches.0.summary.items_scanned', 1);

        $this->getJson("/api/admin/stock/low-alerts?branch_id={$branch->id}")
            ->assertOk()
            ->assertJsonPath('alerts.0.branch_id', $branch->id)
            ->assertJsonPath('alerts.0.stockable_id', $first->id);

        $this->postJson('/api/admin/stock/scan-rupture/run', [
            'branch_id' => $branch->id,
            'dry_run' => true,
        ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('summary.branch_id', $branch->id)
            ->assertJsonPath('summary.items_would_flip', 1);
    }

    /**
     * @return array{0: Item, 1: ItemVariation, 2: ItemVariation}
     */
    private function itemWithTwoVariations(): array
    {
        $item = Item::factory()->create(['status' => Status::ACTIVE]);
        $attribute = ItemAttribute::factory()->create(['status' => Status::ACTIVE]);

        $first = ItemVariation::query()->create([
            'item_id' => $item->id,
            'item_attribute_id' => $attribute->id,
            'name' => 'Endpoint Choice A',
            'price' => 0,
            'status' => Status::ACTIVE,
        ]);
        $second = ItemVariation::query()->create([
            'item_id' => $item->id,
            'item_attribute_id' => $attribute->id,
            'name' => 'Endpoint Choice B',
            'price' => 0,
            'status' => Status::ACTIVE,
        ]);

        return [$item, $first, $second];
    }

    private function stock(Branch $branch, int $variationId, int $onHand): StockLevel
    {
        return StockLevel::query()->create([
            'branch_id' => $branch->id,
            'stockable_type' => ItemVariation::class,
            'stockable_id' => $variationId,
            'on_hand' => $onHand,
            'reserved' => 0,
            'threshold_low' => 2,
        ]);
    }
}
