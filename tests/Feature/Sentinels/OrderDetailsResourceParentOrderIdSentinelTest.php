<?php

namespace Tests\Feature\Sentinels;

use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Enums\PosPaymentMethod;
use App\Http\Resources\OrderDetailsResource;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Sentinel — Phase G.5 finding G5-F-001 (P0 dead UI) / heal G2-HEAL-01.
 *
 * F2-HEAL-03 (commit 8ebbd057a) shipped ReceiptRemboursementMarker.vue
 * which renders a "REMBOURSEMENT" badge on refund-counter-entry receipts
 * (NF525 visual distinction requirement — Loi de Finance France).
 *
 * The marker keys exclusively on `order.parent_order_id` (truthy positive
 * int) to decide whether to render. If the backend `OrderDetailsResource`
 * stops exposing `parent_order_id` in its `toArray` payload, the marker
 * silently disappears and refund tickets become visually indistinguishable
 * from normal sale tickets — an NF525 fiscal-receipt incident.
 *
 * This sentinel locks the contract:
 *   - `parent_order_id` MUST be present in the resource payload (key exists)
 *   - For a refund counter-entry order: value MUST match the parent FK
 *   - For a normal sale order: value MUST be null (no false-positive badge)
 */
class OrderDetailsResourceParentOrderIdSentinelTest extends TestCase
{
    use RefreshDatabase;

    private function makePosOrder(?int $parentId = null, ?Branch $branch = null): Order
    {
        $branch ??= Branch::factory()->create();
        $user = User::factory()->create(['branch_id' => $branch->id]);
        $attrs = [
            'branch_id' => $branch->id,
            'user_id' => $user->id,
            'order_type' => OrderType::POS,
            'pos_payment_method' => PosPaymentMethod::CASH,
            'pos_received_amount' => 20.00,
            'total' => 12.50,
        ];
        if ($parentId !== null) {
            $attrs['parent_order_id'] = $parentId;
            $attrs['payment_status'] = PaymentStatus::REFUNDED;
        }
        $order = Order::factory()->create($attrs);
        $order->refresh();
        $order->load(['branch', 'user', 'orderItems']);

        return $order;
    }

    public function test_parent_order_id_key_is_present_for_normal_sale_and_null(): void
    {
        $order = $this->makePosOrder(null);

        $data = (new OrderDetailsResource($order))->toArray(request());

        $this->assertArrayHasKey(
            'parent_order_id',
            $data,
            'OrderDetailsResource MUST expose parent_order_id (NF525 refund marker contract — G5-F-001).'
        );
        $this->assertNull(
            $data['parent_order_id'],
            'Normal sale order MUST report parent_order_id=null so the REMBOURSEMENT badge does not render falsely.'
        );
    }

    public function test_parent_order_id_is_exposed_for_refund_counter_entry_order(): void
    {
        $branch = Branch::factory()->create();
        $parent = $this->makePosOrder(null, $branch);
        $refund = $this->makePosOrder($parent->id, $branch);

        $data = (new OrderDetailsResource($refund))->toArray(request());

        $this->assertArrayHasKey('parent_order_id', $data);
        $this->assertSame(
            (int) $parent->id,
            (int) $data['parent_order_id'],
            'Refund counter-entry order MUST surface the parent FK so ReceiptRemboursementMarker.vue renders the badge.'
        );
        $this->assertTrue(
            (int) $data['parent_order_id'] > 0,
            'Marker `isRefund` computed prop requires a truthy positive int.'
        );
    }
}
