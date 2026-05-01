<?php

namespace Tests\Feature\Sentinels;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Http\Requests\PaginateRequest;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * @FK-ID FK-008 | @source reports/audit/TRACEABILITY_MATRIX_CAISSE_V1_2026-04-25.md | @fix-mission CV1-M09-BRANCH-ISOLATION | @reason OrderService::list applies LIKE to branch_id and can match 1, 10, and 100.
 */
class OrderListBranchExactnessSentinelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
    }

    public function test_order_list_branch_id_filter_is_exact_not_substring(): void
    {
        foreach ([1, 10, 100] as $id) {
            Branch::factory()->create(['id' => $id]);
        }

        $admin = User::factory()->create(['branch_id' => 0]);
        $admin->assignRole('Admin');
        $this->actingAs($admin, 'sanctum');

        $expected = Order::factory()->create([
            'branch_id' => 1,
            'order_type' => OrderType::POS,
            'status' => OrderStatus::ACCEPT,
        ]);
        Order::factory()->create(['branch_id' => 10, 'order_type' => OrderType::POS, 'status' => OrderStatus::ACCEPT]);
        Order::factory()->create(['branch_id' => 100, 'order_type' => OrderType::POS, 'status' => OrderStatus::ACCEPT]);

        $result = app(OrderService::class)->list($this->paginateRequest(['branch_id' => 1]));
        $ids = $result->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
        $branches = $result->pluck('branch_id')->map(fn ($id) => (int) $id)->unique()->values()->all();

        $this->assertSame([$expected->id], $ids, 'branch_id=1 must not include branch_id 10 or 100.');
        $this->assertSame([1], $branches);
    }

    private function paginateRequest(array $query): PaginateRequest
    {
        $request = PaginateRequest::create('/api/admin/pos-order', 'GET', $query);
        $request->setContainer($this->app);
        $request->setRedirector($this->app->make('redirect'));
        $request->validateResolved();

        return $request;
    }
}
