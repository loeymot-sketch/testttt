<?php

namespace Tests\Feature\Jobs;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentGateway;
use App\Enums\PaymentStatus;
use App\Enums\PosPaymentMethod;
use App\Jobs\CleanupStalePendingKioskOrders;
use App\Models\Branch;
use App\Models\DeliveryStop;
use App\Models\FrontendOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * [CAISSE 2026-10-23 · relecture adverse P1-1] Une livraison « Commande livraison » (payée à la porte) naît à
 * `order_datetime = maintenant`, en préparation, mais sa tournée part dans la fenêtre de service (18:00 → 00:30) :
 * saisie à 11:00, tournée à 18:00. La lane « web » de la purge (TTL 6 h sur `order_datetime`, sans regard pour
 * `order_type`) l'ANNULAIT à 17:00, avant que le livreur parte. Une livraison dont le départ est à venir (ou programmée)
 * ne se purge pas ; une livraison abandonnée longtemps APRÈS son départ prévu se purge toujours.
 */
class CleanupStaleDeliveryDoorOrderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['kiosk.stale_web_collect_ttl_minutes' => 360]);
    }

    private function deliveryOrder(Carbon $orderedAt, array $over = []): FrontendOrder
    {
        $branch = Branch::query()->first() ?? Branch::factory()->create();
        $customer = User::factory()->create(['branch_id' => $branch->id]);

        return FrontendOrder::withoutGlobalScopes()->create(array_merge([
            'order_serial_no' => 'DOOR-' . fake()->unique()->numerify('######'),
            'user_id' => $customer->id, 'branch_id' => $branch->id,
            'subtotal' => 10, 'discount' => 0, 'delivery_charge' => 3.9, 'total_tax' => 1, 'total' => 13.9,
            'order_type' => OrderType::DELIVERY, 'order_datetime' => $orderedAt, 'preparation_time' => 15, 'is_advance_order' => 0,
            'payment_method' => PaymentGateway::CASH_ON_DELIVERY, 'payment_status' => PaymentStatus::PENDING_COUNTER,
            'pos_payment_method' => PosPaymentMethod::COUNTER_DEFERRED, 'status' => OrderStatus::PREPARING,
            'source' => 1, 'source_surface' => 'delivery', 'fiscal_sequence_no' => null,
            'created_at' => $orderedAt, 'updated_at' => $orderedAt,
        ], $over));
    }

    private function stop(FrontendOrder $order, Carbon $waveAt, array $over = []): DeliveryStop
    {
        return DeliveryStop::query()->create(array_merge([
            'branch_id' => $order->branch_id, 'order_id' => $order->id, 'wave_at' => $waveAt, 'sequence' => 1,
        ], $over));
    }

    public function test_a_delivery_whose_tour_has_not_left_yet_is_not_purged_even_after_six_hours(): void
    {
        $order = $this->deliveryOrder(now()->subHours(7)); // saisie à 11:00
        $this->stop($order, now()->addHour());               // tournée à 18:00

        (new CleanupStalePendingKioskOrders())->handle();

        $this->assertSame(OrderStatus::PREPARING, (int) $order->fresh()->status, 'Le livreur n\'est pas encore parti : on ne purge pas.');
        $this->assertNotNull($order->fresh()->pos_payment_method);
    }

    public function test_a_scheduled_delivery_is_not_purged_before_its_slot(): void
    {
        $order = $this->deliveryOrder(now()->subHours(7), ['scheduled_at' => now()->addHours(2), 'status' => OrderStatus::ACCEPT]);

        (new CleanupStalePendingKioskOrders())->handle();

        $this->assertSame(OrderStatus::ACCEPT, (int) $order->fresh()->status);
    }

    public function test_a_delivery_abandoned_long_after_its_planned_departure_is_still_purged(): void
    {
        $order = $this->deliveryOrder(now()->subHours(12));
        $this->stop($order, now()->subHours(8)); // départ prévu il y a 8 h, jamais parti

        (new CleanupStalePendingKioskOrders())->handle();

        $this->assertSame(OrderStatus::CANCELED, (int) $order->fresh()->status, 'Abandon réel : la purge reste active (stock libéré).');
    }

    public function test_a_stale_delivery_without_any_stop_or_slot_is_still_purged(): void
    {
        $order = $this->deliveryOrder(now()->subHours(7));

        (new CleanupStalePendingKioskOrders())->handle();

        $this->assertSame(OrderStatus::CANCELED, (int) $order->fresh()->status, 'Comportement historique conservé.');
    }
}
