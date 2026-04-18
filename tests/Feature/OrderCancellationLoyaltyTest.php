<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\LoyaltyTransaction;
use App\Models\Order;
use App\Services\LoyaltyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderCancellationLoyaltyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();
        $this->seedSpatieRoles();
    }

    private function createOrderWithLoyalty(int $discount, int $pointsRedeemed): array
    {
        $branch = \Database\Factories\BranchFactory::new()->create();

        $customer = \Database\Factories\UserFactory::new()->create([
            'branch_id' => $branch->id,
            'status' => \App\Enums\Status::ACTIVE,
            // users.loyalty_code is VARCHAR(15) on MySQL — keep within limit for phpunit-mysql CI.
            'loyalty_code' => strtoupper(substr(md5(uniqid((string) mt_rand(), true)), 0, 15)),
            'loyalty_points' => 50,
        ]);

        $staff = \Database\Factories\UserFactory::new()->create([
            'branch_id' => $branch->id,
            'status' => \App\Enums\Status::ACTIVE,
        ]);
        $staff->assignRole('Admin');

        $order = Order::create([
            'user_id' => $staff->id,
            'branch_id' => $branch->id,
            'order_type' => 5,
            'order_serial_no' => 'TEST-'.uniqid(),
            'subtotal' => 20.00,
            'total' => 20.00 - $discount,
            'discount' => $discount,
            'delivery_charge' => 0,
            'status' => OrderStatus::PENDING,
            'payment_method' => 1,
            'payment_status' => 1,
            'source' => 1,
            'loyalty_customer_code' => $customer->loyalty_code,
        ]);

        if ($pointsRedeemed > 0) {
            LoyaltyTransaction::create([
                'user_id' => $customer->id,
                'loyalty_code' => $customer->loyalty_code,
                'order_id' => $order->id,
                'type' => 'redeem',
                'points' => -$pointsRedeemed,
                'balance_after' => $customer->loyalty_points,
                'source_surface' => 'pos',
                'description' => 'Test redeem',
            ]);
        }

        return [$order, $customer, $staff];
    }

    public function test_cancel_order_refunds_loyalty_points(): void
    {
        [$order, $customer, $staff] = $this->createOrderWithLoyalty(5.00, 100);
        $this->assertSame(50, $customer->loyalty_points);

        $redeemCheck = LoyaltyTransaction::where('order_id', $order->id)->where('type', 'redeem')->count();
        $this->assertSame(1, $redeemCheck, 'Redeem transaction must exist before refund');

        $service = new LoyaltyService;
        $service->refundPoints($order, 'pos');

        $customer->refresh();
        $this->assertSame(150, $customer->loyalty_points);

        $refundTxn = LoyaltyTransaction::where('order_id', $order->id)
            ->where('type', 'manual_add')
            ->first();

        $this->assertNotNull($refundTxn, 'Refund transaction should be created');
        $this->assertSame(100, $refundTxn->points);
        $this->assertStringContains('Remboursement', $refundTxn->description);
    }

    public function test_cancel_order_without_loyalty_does_nothing(): void
    {
        $branch = \Database\Factories\BranchFactory::new()->create();
        $staff = \Database\Factories\UserFactory::new()->create([
            'branch_id' => $branch->id,
            'status' => \App\Enums\Status::ACTIVE,
        ]);

        $order = Order::create([
            'user_id' => $staff->id,
            'branch_id' => $branch->id,
            'order_type' => 5,
            'order_serial_no' => 'TEST-NOLOYALTY-'.uniqid(),
            'subtotal' => 20.00,
            'total' => 20.00,
            'discount' => 0,
            'delivery_charge' => 0,
            'status' => OrderStatus::PENDING,
            'payment_method' => 1,
            'payment_status' => 1,
            'source' => 1,
            'loyalty_customer_code' => null,
        ]);

        $service = new LoyaltyService;
        $service->refundPoints($order, 'pos');

        $this->assertSame(0, LoyaltyTransaction::where('order_id', $order->id)->count());
    }

    private function assertStringContains(string $needle, ?string $haystack): void
    {
        $this->assertNotNull($haystack);
        $this->assertTrue(
            str_contains($haystack, $needle),
            "Expected string to contain '{$needle}', got: '{$haystack}'"
        );
    }
}
