<?php

namespace Tests\Feature\Resources;

use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Enums\PosPaymentMethod;
use App\Http\Resources\KDSOrderDetailsResource;
use App\Http\Resources\OrderDetailsResource;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Sentinel — Wave N N-HEAL-02 (M-KDS-4 F-01 P1 + K.5 NEW-1 P2).
 *
 * Two contracts pinned in one place because they share the same shape of
 * regression ("Resource silently drops a field that a Vue component depends
 * on, and the UI degrades to an empty / unusable cell without throwing"):
 *
 *  1. `KDSOrderDetailsResource` MUST expose `updated_at` (ISO8601).
 *     KdsHistoryDrawer.vue renders the bumped-at timestamp via
 *     `<time :datetime="order.updated_at">{{ formatTime(order.updated_at) }}</time>`
 *     — drop the key and every Historique row's time cell goes blank.
 *
 *  2. `OrderDetailsResource` MUST expose `parent_order_serial_no` (string|null).
 *     ReceiptRemboursementMarker.vue (line ~53) renders the human-readable
 *     parent serial on refund tickets, falling back to the bare
 *     `parent_order_id` if the serial is missing — but auditors expect the
 *     printable trace-back line. Test both NULL parent (normal sale) and
 *     non-null parent (refund counter-entry).
 *
 * Both fields are 2-LOC additions to their respective Resources; this
 * sentinel ensures they cannot silently regress.
 */
class OrderResourceCompletenessSentinel extends TestCase
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

    // ---------------------------------------------------------------------
    // KDSOrderDetailsResource :: updated_at (M-KDS-4 F-01)
    // ---------------------------------------------------------------------

    public function test_kds_resource_exposes_updated_at_iso8601(): void
    {
        $order = $this->makePosOrder(null);

        $data = (new KDSOrderDetailsResource($order))->toArray(request());

        $this->assertArrayHasKey(
            'updated_at',
            $data,
            'KDSOrderDetailsResource MUST expose updated_at — KdsHistoryDrawer renders <time :datetime="order.updated_at">.'
        );
        $this->assertIsString(
            $data['updated_at'],
            'updated_at MUST be a string (ISO8601 wire format) so the client <time :datetime> attribute is valid.'
        );
        // ISO8601 starts with YYYY-MM-DDTHH:MM:SS (and ends with timezone like +00:00 / +02:00 / Z).
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/',
            $data['updated_at'],
            'updated_at MUST be ISO8601-formatted (toIso8601String) so KDS client `formatTime()` and `:datetime` attr work.'
        );
    }

    // ---------------------------------------------------------------------
    // OrderDetailsResource :: parent_order_serial_no (K.5 NEW-1)
    // ---------------------------------------------------------------------

    public function test_parent_order_serial_no_key_present_and_null_on_normal_sale(): void
    {
        $order = $this->makePosOrder(null);

        $data = (new OrderDetailsResource($order))->toArray(request());

        $this->assertArrayHasKey(
            'parent_order_serial_no',
            $data,
            'OrderDetailsResource MUST expose parent_order_serial_no (NF525 refund trace-back contract — K.5 NEW-1).'
        );
        $this->assertNull(
            $data['parent_order_serial_no'],
            'Normal sale order (parent_order_id=null) MUST report parent_order_serial_no=null.'
        );
    }

    public function test_parent_order_serial_no_resolves_to_parent_serial_on_refund(): void
    {
        $branch = Branch::factory()->create();
        $parent = $this->makePosOrder(null, $branch);
        $refund = $this->makePosOrder($parent->id, $branch);

        $data = (new OrderDetailsResource($refund))->toArray(request());

        $this->assertArrayHasKey('parent_order_serial_no', $data);
        $this->assertSame(
            (string) $parent->order_serial_no,
            (string) $data['parent_order_serial_no'],
            'Refund counter-entry order MUST surface the parent order_serial_no so receipt trace-back line renders.'
        );
        // Sanity: parent_order_id stays present alongside (G2-HEAL-01 contract).
        $this->assertSame(
            (int) $parent->id,
            (int) $data['parent_order_id'],
            'parent_order_id contract from G2-HEAL-01 MUST remain alongside parent_order_serial_no.'
        );
    }
}
