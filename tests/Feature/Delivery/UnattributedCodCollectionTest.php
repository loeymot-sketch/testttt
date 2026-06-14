<?php

namespace Tests\Feature\Delivery;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentGateway;
use App\Enums\PaymentStatus;
use App\Models\Branch;
use App\Models\DeliveryBoyCashSession;
use App\Models\Order;
use App\Models\User;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * G-DELIV-CASH (owner OD-1, 2026-06-15) — record-anyway, NO auto-open.
 *
 * When a driver collects COD cash at the doorstep with NO open driver cash session, the
 * cash is already recorded (the NF525 escrow audit row), but it lands in no session
 * balance. Per owner decision we do NOT auto-open a session; instead we surface a VISIBLE,
 * COUNTABLE "unattributed COD collection" audit signal (previously a silent skip) so ops
 * can reconcile it manually.
 */
class UnattributedCodCollectionTest extends TestCase
{
    use RefreshDatabase;

    private function makeDriver(int $branchId): User
    {
        return User::forceCreate([
            'name' => 'Driver ' . uniqid(), 'email' => 'drv-' . uniqid() . '@gdc.test',
            'username' => 'drv_' . uniqid(), 'password' => bcrypt('secret-passwd'),
            'branch_id' => $branchId, 'email_verified_at' => now(), 'status' => 1,
        ])->fresh();
    }

    public function test_cod_collected_without_open_session_records_unattributed_signal_and_no_auto_open(): void
    {
        $branch = Branch::factory()->create();
        $driver = $this->makeDriver($branch->id);

        $order = Order::factory()->create([
            'branch_id'       => $branch->id,
            'order_type'      => OrderType::DELIVERY,
            'delivery_boy_id' => $driver->id,
            'status'          => OrderStatus::OUT_FOR_DELIVERY,
            'payment_method'  => PaymentGateway::CASH_ON_DELIVERY,
            'payment_status'  => PaymentStatus::UNPAID,
            'total'           => 18.50,
        ]);

        $this->assertSame(0, DeliveryBoyCashSession::query()->count(), 'precondition: no driver cash session exists');

        $this->actingAs($driver, 'sanctum');
        $req = Request::create('/api/frontend/delivery-boy-order/change-status/' . $order->id, 'POST', [
            'status' => OrderStatus::DELIVERED,
        ]);
        app(OrderService::class)->deliveryBoyOrderChangeStatus($order, $req);

        // Record-anyway: the cash is paid + a countable unattributed-collection audit row exists.
        $this->assertSame(PaymentStatus::PAID, (int) $order->fresh()->payment_status, 'COD flips PAID at doorstep');
        $this->assertSame(
            1,
            DB::table('audit_logs')
                ->where('action', 'cash.delivery.unattributed_collection')
                ->where('resource_id', $order->id)
                ->count(),
            'an unattributed COD collection must leave a VISIBLE, countable audit signal (not a silent skip).'
        );

        // NO auto-open: the owner decision forbids auto-opening a driver cash session.
        $this->assertSame(
            0,
            DeliveryBoyCashSession::query()->count(),
            'no driver cash session may be auto-opened by an unattributed collection (OD-1).'
        );
    }
}
