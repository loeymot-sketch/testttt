<?php

namespace Tests\Feature;

use App\Enums\Ask;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use App\Services\KitchenDisplaySystemOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * [POS-9.1.5] KDS list() integer-ID filters use `=` instead of `LIKE '%X%'`.
 *
 * Regression case: branch_id=1 was filtered by LIKE '%1%' matching 1, 10, 11,
 * 12, 21, 100 … Cross-branch data leak in the KDS panel.
 */
class KdsBranchFilterExactTest extends TestCase
{
    use RefreshDatabase;

    public function test_branch_id_filter_does_not_match_substring_branches(): void
    {
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        $branch1  = Branch::factory()->create(['id' => 1]);
        $branch10 = Branch::factory()->create(['id' => 10]);
        $branch12 = Branch::factory()->create(['id' => 12]);

        // Ensure there is ≥1 active order per branch so LIKE '%1%' would leak 3 rows.
        foreach ([$branch1, $branch10, $branch12] as $b) {
            Order::factory()->create([
                'branch_id'        => $b->id,
                'order_type'       => OrderType::POS,
                'status'           => OrderStatus::ACCEPT,
                'is_advance_order' => Ask::NO,
                'order_datetime'   => now(),
            ]);
        }

        $admin = User::factory()->create(['branch_id' => 0]);
        $admin->assignRole('Admin');
        $this->actingAs($admin, 'sanctum');

        $service = app(KitchenDisplaySystemOrderService::class);
        $request = Request::create('/admin/kitchen-display-system', 'GET', ['branch_id' => 1]);
        $results = $service->list($request);

        $branchIds = collect($results)->pluck('branch_id')->unique()->values()->all();
        $this->assertEquals([1], $branchIds,
            'KDS branch_id=1 filter must only return branch 1, got: ' . json_encode($branchIds));
    }
}
