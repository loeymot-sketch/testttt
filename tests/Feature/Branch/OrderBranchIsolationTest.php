<?php

namespace Tests\Feature\Branch;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Http\Requests\PaginateRequest;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use App\Services\FrontendOrderService;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderBranchIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
    }

    public function test_order_and_frontend_branch_filters_are_exact_not_prefix_matches(): void
    {
        foreach ([1, 10, 100] as $id) {
            Branch::factory()->create(['id' => $id]);
        }

        $admin = User::factory()->create(['branch_id' => 0]);
        $admin->assignRole('Admin');
        $this->actingAs($admin, 'sanctum');

        Order::factory()->create([
            'branch_id' => 1,
            'user_id' => $admin->id,
            'delivery_boy_id' => $admin->id,
            'order_type' => OrderType::POS,
            'status' => OrderStatus::ACCEPT,
            'total' => 11,
        ]);
        Order::factory()->create([
            'branch_id' => 10,
            'user_id' => $admin->id,
            'delivery_boy_id' => $admin->id,
            'order_type' => OrderType::POS,
            'status' => OrderStatus::ACCEPT,
            'total' => 110,
        ]);
        Order::factory()->create([
            'branch_id' => 100,
            'user_id' => $admin->id,
            'delivery_boy_id' => $admin->id,
            'order_type' => OrderType::POS,
            'status' => OrderStatus::ACCEPT,
            'total' => 1000,
        ]);
        Order::factory()->create([
            'branch_id' => 1,
            'user_id' => $admin->id,
            'delivery_boy_id' => $admin->id,
            'order_type' => OrderType::TAKEAWAY,
            'status' => OrderStatus::ACCEPT,
            'total' => 12,
        ]);
        Order::factory()->create([
            'branch_id' => 10,
            'user_id' => $admin->id,
            'delivery_boy_id' => $admin->id,
            'order_type' => OrderType::TAKEAWAY,
            'status' => OrderStatus::ACCEPT,
            'total' => 120,
        ]);

        $orderService = app(OrderService::class);
        $branchFilter = $this->paginateRequest(['branch_id' => 1]);

        $this->assertOnlyBranch($orderService->list($branchFilter), 1);
        $this->assertOnlyBranch($orderService->userOrder($branchFilter, $admin), 1);
        $this->assertOnlyBranch($orderService->deliveredOrder($branchFilter, $admin), 1);
        $this->assertOnlyBranch($orderService->deliveryBoyOrder($branchFilter), 1);
        $this->assertOnlyBranch(app(FrontendOrderService::class)->myOrder($branchFilter), 1);

        $overview = $orderService->salesReportOverview($branchFilter);
        $this->assertSame(2, $overview['total_orders']);
    }

    private function assertOnlyBranch($orders, int $branchId): void
    {
        $collection = collect($orders);

        $this->assertNotCount(0, $collection);
        $this->assertSame(
            [$branchId],
            $collection->pluck('branch_id')->map(fn ($id) => (int) $id)->unique()->sort()->values()->all()
        );
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
