<?php

namespace Tests\Feature\Branch;

use App\Enums\Ask;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Enums\PosPaymentMethod;
use App\Enums\Source;
use App\Http\Requests\PosOrderRequest;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class OssAdminBranchPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
    }

    public function test_branch_id_zero_non_admin_is_not_global_for_pos_order_store(): void
    {
        $branch = Branch::factory()->create();
        $customer = User::factory()->create(['branch_id' => $branch->id]);
        $misconfiguredChef = User::factory()->create(['branch_id' => 0]);
        $misconfiguredChef->assignRole('Chef');

        $this->actingAs($misconfiguredChef, 'sanctum');

        $request = $this->posOrderRequest([
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
        ]);

        try {
            app(OrderService::class)->posOrderStore($request);
            $this->fail('A non-admin branch_id=0 actor must not be treated as a global admin.');
        } catch (\Exception $exception) {
            $this->assertSame(422, $exception->getCode());
        }

        $this->assertDatabaseMissing('orders', [
            'branch_id' => $branch->id,
            'user_id' => $customer->id,
        ]);
    }

    public function test_branch_id_zero_non_admin_is_not_global_for_destroy(): void
    {
        $branch = Branch::factory()->create();
        $misconfiguredChef = User::factory()->create(['branch_id' => 0]);
        $misconfiguredChef->assignRole('Chef');
        $order = Order::factory()->create([
            'branch_id' => $branch->id,
            'order_type' => OrderType::POS,
            'payment_status' => PaymentStatus::UNPAID,
            'status' => OrderStatus::ACCEPT,
        ]);

        $this->actingAs($misconfiguredChef, 'sanctum');

        try {
            app(OrderService::class)->destroy($order);
            $this->fail('A non-admin branch_id=0 actor must not destroy another branch order.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }

        $this->assertFalse(Order::withTrashed()->findOrFail($order->id)->trashed());
    }

    private function posOrderRequest(array $overrides): PosOrderRequest
    {
        $payload = array_replace([
            'token' => 'A1',
            'customer_id' => User::factory()->create()->id,
            'branch_id' => Branch::factory()->create()->id,
            'subtotal' => 0,
            'discount' => 0,
            'order_type' => OrderType::POS,
            'is_advance_order' => Ask::NO,
            'delivery_charge' => 0,
            'total' => 0,
            'coupon_id' => 0,
            'source' => Source::POS,
            'items' => json_encode([['item_id' => 999999, 'quantity' => 1]]),
            'pos_payment_method' => PosPaymentMethod::CASH,
            'pos_received_amount' => 0,
        ], $overrides);

        $request = PosOrderRequest::create('/api/admin/pos-order', 'POST', $payload);
        $request->setContainer($this->app);
        $request->setRedirector($this->app->make('redirect'));
        $request->setUserResolver(fn () => auth()->user());
        $this->app->instance('request', $request);
        $request->validateResolved();

        return $request;
    }
}
