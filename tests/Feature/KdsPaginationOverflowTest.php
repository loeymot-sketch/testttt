<?php

namespace Tests\Feature;

use App\Enums\Ask;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Enums\PosPaymentMethod;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KdsPaginationOverflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
    }

    public function test_kds_list_keeps_server_cap_at_fifty_active_orders(): void
    {
        $branch = Branch::factory()->create();
        $chef = User::factory()->create(['branch_id' => $branch->id]);
        $chef->assignRole('Chef');

        Order::factory()->count(51)->create([
            'branch_id' => $branch->id,
            'order_type' => OrderType::POS,
            'payment_status' => PaymentStatus::PAID,
            'status' => OrderStatus::ACCEPT,
            'is_advance_order' => Ask::NO,
            'order_datetime' => now(),
        ]);

        $response = $this->actingAs($chef, 'sanctum')
            ->getJson('/api/admin/kds-order');

        $response->assertOk();
        $this->assertCount(50, $response->json('data'));
        $this->assertTrue($response->json('meta.overflow'));
        $this->assertSame(50, $response->json('meta.limit'));
    }

    public function test_kds_list_releases_paid_orders_and_pos_cash_exception_only(): void
    {
        $branch = Branch::factory()->create();
        $chef = User::factory()->create(['branch_id' => $branch->id]);
        $chef->assignRole('Chef');

        $paid = Order::factory()->create([
            'branch_id' => $branch->id,
            'order_type' => OrderType::KIOSK,
            'payment_status' => PaymentStatus::PAID,
            'status' => OrderStatus::ACCEPT,
            'is_advance_order' => Ask::NO,
            'order_datetime' => now(),
        ]);

        $unpaidKiosk = Order::factory()->create([
            'branch_id' => $branch->id,
            'order_type' => OrderType::KIOSK,
            'payment_status' => PaymentStatus::UNPAID,
            'status' => OrderStatus::ACCEPT,
            'is_advance_order' => Ask::NO,
            'order_datetime' => now(),
        ]);

        $posCash = Order::factory()->create([
            'branch_id' => $branch->id,
            'order_type' => OrderType::POS,
            'payment_status' => PaymentStatus::UNPAID,
            'pos_payment_method' => PosPaymentMethod::CASH,
            'status' => OrderStatus::ACCEPT,
            'is_advance_order' => Ask::NO,
            'order_datetime' => now(),
        ]);

        $pendingPosCash = Order::factory()->create([
            'branch_id' => $branch->id,
            'order_type' => OrderType::POS,
            'payment_status' => PaymentStatus::UNPAID,
            'pos_payment_method' => PosPaymentMethod::CASH,
            'status' => OrderStatus::PENDING,
            'is_advance_order' => Ask::NO,
            'order_datetime' => now(),
        ]);

        $response = $this->actingAs($chef, 'sanctum')
            ->getJson('/api/admin/kds-order');

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->map(fn ($id) => (int) $id)->all();

        $this->assertContains((int) $paid->id, $ids);
        $this->assertContains((int) $posCash->id, $ids);
        $this->assertNotContains((int) $unpaidKiosk->id, $ids);
        $this->assertNotContains((int) $pendingPosCash->id, $ids);
    }
}
