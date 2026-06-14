<?php

namespace Tests\Feature\Delivery;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentGateway;
use App\Enums\PaymentStatus;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * DV-T1 (P0, NF525 exhaustivity — twin of G-DELIV-FISCAL, ultra-review 2026-06-14).
 *
 * G-DELIV-FISCAL allocated a fiscal_sequence_no when a CASH_ON_DELIVERY order is
 * paid at the doorstep. But deliveryBoyOrderChangeStatus also auto-flips a NON-COD
 * order (CARD / E_WALLET / TICKET_RESTAURANT that reached the driver still UNPAID —
 * "late card capture", per the method's own comment) to PAID via the `else` branch
 * — and that branch did NOT allocate a seq. A realized PAID sale with seq=NULL
 * escapes every daily Z (ZReportService aggregates whereNotNull('fiscal_sequence_no')).
 * This locks that ANY auto-flip-to-PAID in the driver path carries a seq.
 */
class DeliveryNonCodFiscalAllocTest extends TestCase
{
    use RefreshDatabase;

    private function makeDeliveryBoy(int $branchId): User
    {
        // deliveryBoyOrderChangeStatus authorizes on delivery_boy_id === Auth::id(),
        // not on a Spatie role — so a plain branch-scoped user is sufficient.
        return User::forceCreate([
            'name'              => 'Driver ' . uniqid(),
            'email'             => 'driver-' . uniqid() . '@dvt1.test',
            'username'          => 'driver_' . uniqid(),
            'password'          => bcrypt('secret-passwd'),
            'branch_id'         => $branchId,
            'email_verified_at' => now(),
            'status'            => 1,
        ])->fresh();
    }

    /**
     * A non-COD (CARD) delivery still UNPAID at the doorstep flips PAID at DELIVERED.
     * It MUST receive a gap-free fiscal_sequence_no so it enters the Z.
     */
    public function test_non_cod_delivery_flip_to_paid_allocates_fiscal_sequence(): void
    {
        $branch = Branch::factory()->create();
        $driver = $this->makeDeliveryBoy($branch->id);

        $order = Order::factory()->create([
            'branch_id'       => $branch->id,
            'order_type'      => OrderType::DELIVERY,
            'delivery_boy_id' => $driver->id,
            'status'          => OrderStatus::OUT_FOR_DELIVERY,
            'payment_method'  => PaymentGateway::CARD,        // non-COD
            'payment_status'  => PaymentStatus::UNPAID,
            'total'           => 23.50,
        ]);

        $this->assertNull($order->fiscal_sequence_no, 'precondition: no seq yet');

        $this->actingAs($driver, 'sanctum');
        $req = Request::create('/api/frontend/delivery-boy-order/change-status/' . $order->id, 'POST', [
            'status' => OrderStatus::DELIVERED,
        ]);
        app(OrderService::class)->deliveryBoyOrderChangeStatus($order, $req);

        $fresh = $order->fresh();
        $this->assertSame(PaymentStatus::PAID, (int) $fresh->payment_status, 'non-COD flips PAID at delivery');
        $this->assertNotNull(
            $fresh->fiscal_sequence_no,
            'A non-COD delivery flipped to PAID MUST carry a fiscal_sequence_no (NF525 exhaustivity — else it escapes the Z).'
        );
        $this->assertNull($fresh->fiscal_alloc_error_at, 'allocation succeeded, no error flag');
    }
}
