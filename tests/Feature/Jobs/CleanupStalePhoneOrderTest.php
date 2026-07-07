<?php

namespace Tests\Feature\Jobs;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentGateway;
use App\Enums\PaymentStatus;
use App\Enums\PosPaymentMethod;
use App\Enums\Status;
use App\Events\OrderCanceled;
use App\Events\OrderStatusChanged;
use App\Events\SendOrderMail;
use App\Events\SendOrderPush;
use App\Events\SendOrderSms;
use App\Jobs\CleanupStalePendingKioskOrders;
use App\Models\Branch;
use App\Models\FrontendOrder;
use App\Models\LoyaltyTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * [C4-CAISSE-TELEPHONE FIX-2 / P3 2026-07-07] Purge des COMMANDES TÉLÉPHONE abandonnées.
 *
 * Repro du gap (au HEAD avant fix) : le cron CleanupStalePendingKioskOrders ne ciblait
 * QUE source_surface='kiosk'. Une commande téléphone (source_surface='phone') dont le
 * client ne vient JAMAIS restait PENDING_COUNTER indéfiniment (file d'encaissement + KDS
 * pollués à vie).
 *
 * Ce test prouve :
 *   1. une commande téléphone stale (PREPARING/ACCEPT, PENDING_COUNTER, COUNTER_DEFERRED,
 *      pas de fiscal) est annulée (→CANCELED), son marqueur counter-deferred est cassé,
 *      et ses points fidélité redeem sont remboursés ;
 *   2. une commande téléphone JEUNE (dans le TTL) n'est PAS purgée ;
 *   3. une commande téléphone PROGRAMMÉE pour plus tard (order_datetime futur) n'est PAS
 *      purgée même si created_at est ancien (« ne purge pas trop tôt ») ;
 *   4. une commande téléphone ENCAISSÉE (fiscal_sequence_no) n'est JAMAIS touchée (NF525) ;
 *   5. régression : borne + téléphone purgées ensemble en un seul run.
 */
class CleanupStalePhoneOrderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // TTL déterministes pour les fixtures ci-dessous.
        config([
            'kiosk.stale_phone_collect_ttl_minutes' => 180,
            'kiosk.stale_collect_ttl_minutes' => 180,
        ]);
    }

    public function test_stale_phone_order_is_canceled_marker_broken_and_loyalty_refunded(): void
    {
        Event::fake([
            SendOrderMail::class, SendOrderSms::class, SendOrderPush::class,
            OrderStatusChanged::class, OrderCanceled::class,
        ]);

        $branch = Branch::factory()->create();
        $customer = User::factory()->create([
            'branch_id' => $branch->id,
            'status' => Status::ACTIVE,
            'loyalty_code' => 'FK-PHONE-STALE-A',
            'loyalty_points' => 100,
        ]);

        // Commande téléphone réelle : status=PREPARING (auto-accept + board-release), 4 h.
        $order = $this->makePhoneOrder($branch->id, $customer->id, now()->subMinutes(240), OrderStatus::PREPARING, 'FK-PHONE-STALE-A');
        LoyaltyTransaction::create([
            'user_id' => $customer->id, 'loyalty_code' => 'FK-PHONE-STALE-A', 'order_id' => $order->id,
            'type' => 'redeem', 'points' => -50, 'balance_after' => 100, 'source_surface' => 'phone',
            'description' => 'Redeem téléphone',
        ]);

        (new CleanupStalePendingKioskOrders())->handle();

        $fresh = $order->fresh();
        $this->assertSame(OrderStatus::CANCELED, (int) $fresh->status, 'commande téléphone abandonnée → CANCELED (PREPARING→CANCELED légal)');
        $this->assertNull($fresh->pos_payment_method, 'marqueur COUNTER_DEFERRED cassé (collect tardif refusé)');
        $this->assertNull($fresh->fiscal_sequence_no, 'aucune séquence fiscale (NF525)');

        // Points remboursés (100 → 150), source_surface=pos (canal non-kiosk).
        $this->assertSame(150, (int) $customer->fresh()->loyalty_points, 'points fidélité re-crédités');
        $refund = LoyaltyTransaction::where('order_id', $order->id)->where('type', 'manual_add')->first();
        $this->assertNotNull($refund, 'ligne de remboursement manual_add attendue');
        $this->assertSame('pos', $refund->source_surface, 'refund surface = pos pour une commande téléphone');

        Event::assertDispatchedTimes(OrderCanceled::class, 1);
        Event::assertDispatched(OrderStatusChanged::class, fn (OrderStatusChanged $e) => (int) $e->order->id === (int) $order->id
            && $e->oldStatus === OrderStatus::PREPARING && $e->newStatus === OrderStatus::CANCELED);
    }

    public function test_stale_phone_order_in_accept_status_is_also_canceled(): void
    {
        $branch = Branch::factory()->create();
        $customer = User::factory()->create(['branch_id' => $branch->id, 'status' => Status::ACTIVE]);

        $order = $this->makePhoneOrder($branch->id, $customer->id, now()->subMinutes(240), OrderStatus::ACCEPT, null);

        (new CleanupStalePendingKioskOrders())->handle();

        $this->assertSame(OrderStatus::CANCELED, (int) $order->fresh()->status, 'phone ACCEPT abandonnée → CANCELED');
        $this->assertNull($order->fresh()->pos_payment_method);
    }

    public function test_young_phone_order_is_not_purged(): void
    {
        Event::fake([OrderCanceled::class, OrderStatusChanged::class]);

        $branch = Branch::factory()->create();
        $customer = User::factory()->create(['branch_id' => $branch->id, 'status' => Status::ACTIVE]);

        // 10 min : bien dans le TTL de 180 min.
        $order = $this->makePhoneOrder($branch->id, $customer->id, now()->subMinutes(10), OrderStatus::PREPARING, null);

        (new CleanupStalePendingKioskOrders())->handle();

        $this->assertSame(OrderStatus::PREPARING, (int) $order->fresh()->status, 'commande téléphone jeune non purgée (TTL respecté)');
        $this->assertSame(PosPaymentMethod::COUNTER_DEFERRED, (int) $order->fresh()->pos_payment_method);
        Event::assertNotDispatched(OrderCanceled::class);
    }

    public function test_future_scheduled_phone_order_is_not_purged_even_if_created_long_ago(): void
    {
        // « Ne purge pas trop tôt » : commande prise il y a 7 h mais programmée dans 2 h.
        // created_at ancien MAIS order_datetime futur → NON purgée (garde AND sur les 2 dates).
        $branch = Branch::factory()->create();
        $customer = User::factory()->create(['branch_id' => $branch->id, 'status' => Status::ACTIVE]);

        $order = FrontendOrder::withoutGlobalScopes()->create($this->phoneAttributes(
            $branch->id, $customer->id, now()->subMinutes(420), OrderStatus::PREPARING, null,
            ['order_datetime' => now()->addMinutes(120)]
        ));

        (new CleanupStalePendingKioskOrders())->handle();

        $this->assertSame(OrderStatus::PREPARING, (int) $order->fresh()->status, 'commande programmée dans le futur ne doit PAS être purgée avant son créneau');
    }

    public function test_fiscalized_phone_order_is_never_touched(): void
    {
        $branch = Branch::factory()->create();
        $customer = User::factory()->create(['branch_id' => $branch->id, 'status' => Status::ACTIVE]);

        $order = $this->makePhoneOrder($branch->id, $customer->id, now()->subMinutes(240), OrderStatus::PREPARING, null);
        FrontendOrder::withoutGlobalScopes()->whereKey($order->id)->update([
            'payment_status' => PaymentStatus::PAID,
            'fiscal_sequence_no' => 7,
            'pos_payment_method' => PosPaymentMethod::CASH,
        ]);

        (new CleanupStalePendingKioskOrders())->handle();

        $fresh = $order->fresh();
        $this->assertSame(OrderStatus::PREPARING, (int) $fresh->status, 'une commande téléphone encaissée ne doit jamais être annulée');
        $this->assertSame(7, (int) $fresh->fiscal_sequence_no, 'séquence fiscale intouchée (NF525)');
    }

    public function test_kiosk_and_phone_orders_are_both_purged_in_one_run(): void
    {
        Event::fake([
            SendOrderMail::class, SendOrderSms::class, SendOrderPush::class,
            OrderStatusChanged::class, OrderCanceled::class,
        ]);

        $branch = Branch::factory()->create();
        $customer = User::factory()->create(['branch_id' => $branch->id, 'status' => Status::ACTIVE]);

        // Borne abandonnée (ACCEPT) + téléphone abandonnée (PREPARING), toutes deux stale.
        $kiosk = FrontendOrder::withoutGlobalScopes()->create($this->phoneAttributes(
            $branch->id, $customer->id, now()->subMinutes(240), OrderStatus::ACCEPT, null,
            ['source_surface' => 'kiosk', 'order_type' => OrderType::KIOSK]
        ));
        $phone = $this->makePhoneOrder($branch->id, $customer->id, now()->subMinutes(240), OrderStatus::PREPARING, null);

        (new CleanupStalePendingKioskOrders())->handle();

        $this->assertSame(OrderStatus::CANCELED, (int) $kiosk->fresh()->status, 'borne abandonnée purgée');
        $this->assertSame(OrderStatus::CANCELED, (int) $phone->fresh()->status, 'téléphone abandonnée purgée');
        Event::assertDispatchedTimes(OrderCanceled::class, 2);
    }

    /**
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private function phoneAttributes(int $branchId, int $userId, Carbon $createdAt, int $status, ?string $loyaltyCode, array $overrides = []): array
    {
        // NB : `created_at` n'est PAS fillable → `create()` le repose à now() ; le signal
        // d'ancienneté réellement respecté (et utilisé par la purge téléphone) est
        // `order_datetime` (= l'heure de création côté posOrderStore). On le backdate ici.
        return array_merge([
            'order_serial_no' => 'PHONE-C4-' . fake()->unique()->numerify('######'),
            'user_id' => $userId,
            'branch_id' => $branchId,
            'subtotal' => 10,
            'discount' => 0,
            'delivery_charge' => 0,
            'total_tax' => 1,
            'total' => 11,
            'order_type' => OrderType::TAKEAWAY,
            'order_datetime' => $createdAt,
            'preparation_time' => 15,
            'is_advance_order' => 0,
            'payment_method' => PaymentGateway::CASH_ON_DELIVERY,
            'payment_status' => PaymentStatus::PENDING_COUNTER,
            'pos_payment_method' => PosPaymentMethod::COUNTER_DEFERRED,
            'status' => $status,
            'source' => 1,
            'source_surface' => 'phone',
            'fiscal_sequence_no' => null,
            'loyalty_customer_code' => $loyaltyCode,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ], $overrides);
    }

    private function makePhoneOrder(int $branchId, int $userId, Carbon $createdAt, int $status, ?string $loyaltyCode, array $overrides = []): FrontendOrder
    {
        return FrontendOrder::withoutGlobalScopes()->create($this->phoneAttributes($branchId, $userId, $createdAt, $status, $loyaltyCode, $overrides));
    }
}
