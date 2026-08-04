<?php

namespace Tests\Feature\Kiosk;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentGateway;
use App\Enums\PaymentStatus;
use App\Enums\PosPaymentMethod;
use App\Enums\Source;
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
 * [C36 2026-07-06] Symétrie remboursement fidélité sur la purge des commandes
 * borne abandonnées.
 *
 * Repro du bug (au HEAD avant fix) : une commande borne créée avec un
 * loyalty_code débite les points fidélité à la création (LoyaltyTransaction
 * type=redeem, points<0). Si elle est abandonnée (PENDING_COUNTER jamais
 * encaissée), CleanupStalePendingKioskOrders l'annule (ACCEPT→CANCELED) SANS
 * rembourser les points → points perdus définitivement. Les chemins normaux
 * (OrderService::changeStatus + FrontendOrderService::changeStatus) remboursent
 * déjà via LoyaltyService::refundPoints ; le job de purge, non → asymétrie
 * exploitable en griefing.
 *
 * Ce test prouve :
 *   (a) une commande borne PENDING_COUNTER avec redeem fidélité, purgée →
 *       points remboursés (ledger manual_add + users.loyalty_points restauré)
 *       ET commande CANCELED ;
 *   (b) une commande SANS fidélité purgée → AUCUN remboursement parasite
 *       (no-op prouvé) ;
 *   (c) idempotence : re-run du job ne double-rembourse pas.
 */
class CleanupStaleLoyaltyRefundTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['kiosk.stale_collect_ttl_minutes' => 180]);
    }

    public function test_abandoned_kiosk_order_with_redeem_refunds_loyalty_points_on_cleanup(): void
    {
        Event::fake([
            SendOrderMail::class,
            SendOrderSms::class,
            SendOrderPush::class,
            OrderStatusChanged::class,
            OrderCanceled::class,
        ]);

        $branch = Branch::factory()->create();

        // Client fidélité : a redeem 50 pts au checkout → solde courant 100
        // (débit déjà appliqué à la création de commande). Le remboursement doit
        // le ramener à 150.
        $customer = User::factory()->create([
            'branch_id'      => $branch->id,
            'status'         => Status::ACTIVE,
            'loyalty_code'   => 'FK-CLEANUP-A',
            'loyalty_points' => 100,
        ]);

        $order = $this->makeAbandonedKioskCounterOrder(
            $branch->id,
            $customer->id,
            now()->subMinutes(240),
            'FK-CLEANUP-A'
        );

        // Ligne redeem écrite à la création (points négatifs).
        LoyaltyTransaction::create([
            'user_id'        => $customer->id,
            'loyalty_code'   => 'FK-CLEANUP-A',
            'order_id'       => $order->id,
            'type'           => 'redeem',
            'points'         => -50,
            'balance_after'  => 100,
            'source_surface' => 'kiosk',
            'description'    => 'Redeem au checkout borne',
        ]);

        (new CleanupStalePendingKioskOrders())->handle();

        $fresh = $order->fresh();

        // Commande bien annulée.
        $this->assertSame(
            OrderStatus::CANCELED,
            (int) $fresh->status,
            'La commande borne abandonnée doit être annulée (ACCEPT→CANCELED).'
        );

        // Points restaurés sur le solde utilisateur.
        $this->assertSame(
            150,
            (int) $customer->fresh()->loyalty_points,
            'Le solde fidélité doit être re-crédité de 50 pts (100 → 150).'
        );

        // Ligne de reversal écrite dans le ledger (type manual_add, la valeur
        // réelle utilisée par LoyaltyService::refundPoints).
        $refund = LoyaltyTransaction::where('order_id', $order->id)
            ->where('user_id', $customer->id)
            ->where('type', 'manual_add')
            ->first();

        $this->assertNotNull(
            $refund,
            'Une ligne de remboursement (manual_add) doit exister après la purge.'
        );
        $this->assertSame(50, (int) $refund->points, 'Le remboursement doit re-créditer 50 pts.');
        $this->assertSame(150, (int) $refund->balance_after, 'balance_after doit refléter 150.');
        $this->assertSame('kiosk', $refund->source_surface, 'source_surface doit être kiosk (aligné borne).');

        Event::assertDispatchedTimes(OrderCanceled::class, 1);
    }

    public function test_abandoned_kiosk_order_without_loyalty_is_canceled_with_no_refund(): void
    {
        Event::fake([
            SendOrderMail::class,
            SendOrderSms::class,
            SendOrderPush::class,
            OrderStatusChanged::class,
            OrderCanceled::class,
        ]);

        $branch = Branch::factory()->create();
        $customer = User::factory()->create([
            'branch_id'      => $branch->id,
            'status'         => Status::ACTIVE,
            'loyalty_code'   => 'FK-CLEANUP-B',
            'loyalty_points' => 42,
        ]);

        // Commande SANS loyalty_customer_code (aucune fidélité utilisée).
        $order = $this->makeAbandonedKioskCounterOrder(
            $branch->id,
            $customer->id,
            now()->subMinutes(240),
            null
        );

        (new CleanupStalePendingKioskOrders())->handle();

        $this->assertSame(
            OrderStatus::CANCELED,
            (int) $order->fresh()->status,
            'La commande sans fidélité doit tout de même être annulée.'
        );

        // Aucun mouvement fidélité créé, solde inchangé (no-op prouvé).
        $this->assertSame(
            0,
            LoyaltyTransaction::where('order_id', $order->id)->count(),
            'Aucune ligne fidélité ne doit être créée pour une commande sans fidélité.'
        );
        $this->assertSame(
            42,
            (int) $customer->fresh()->loyalty_points,
            'Le solde fidélité ne doit pas bouger (pas de remboursement parasite).'
        );
    }

    public function test_cleanup_refund_is_idempotent_on_rerun(): void
    {
        Event::fake([
            SendOrderMail::class,
            SendOrderSms::class,
            SendOrderPush::class,
            OrderStatusChanged::class,
            OrderCanceled::class,
        ]);

        $branch = Branch::factory()->create();
        $customer = User::factory()->create([
            'branch_id'      => $branch->id,
            'status'         => Status::ACTIVE,
            'loyalty_code'   => 'FK-CLEANUP-C',
            'loyalty_points' => 100,
        ]);

        $order = $this->makeAbandonedKioskCounterOrder(
            $branch->id,
            $customer->id,
            now()->subMinutes(240),
            'FK-CLEANUP-C'
        );

        LoyaltyTransaction::create([
            'user_id'        => $customer->id,
            'loyalty_code'   => 'FK-CLEANUP-C',
            'order_id'       => $order->id,
            'type'           => 'redeem',
            'points'         => -50,
            'balance_after'  => 100,
            'source_surface' => 'kiosk',
            'description'    => 'Redeem au checkout borne',
        ]);

        // Premier run : rembourse.
        (new CleanupStalePendingKioskOrders())->handle();
        // Second run : la commande est déjà CANCELED donc hors périmètre ; même
        // si elle repassait, refundPoints est idempotent (manual_add existe déjà).
        (new CleanupStalePendingKioskOrders())->handle();

        // Une seule ligne de remboursement, jamais deux.
        $this->assertSame(
            1,
            LoyaltyTransaction::where('order_id', $order->id)
                ->where('type', 'manual_add')
                ->count(),
            'Le remboursement ne doit exister qu\'une seule fois (pas de double-crédit).'
        );
        $this->assertSame(
            150,
            (int) $customer->fresh()->loyalty_points,
            'Le solde ne doit être crédité qu\'une seule fois (150, pas 200).'
        );
    }

    /**
     * [P1-2 CUMUL 2026-08-04] Une commande borne JAMAIS PAYÉE qui a atteint PREPARED (l'award
     * crédite dès PREPARED pour la borne) puis est purgée par le janitor doit voir ses points
     * CUMULÉS repris — sinon le client garde des points gagnés sur une vente inexistante
     * (« scanner QR + faire préparer + repartir sans payer »). refundPoints ne rend que les
     * points DÉPENSÉS ; il fallait AUSSI clawbackEarnedPoints.
     */
    public function test_phantom_prepared_kiosk_order_claws_back_earned_points_on_purge(): void
    {
        Event::fake([SendOrderMail::class, SendOrderSms::class, SendOrderPush::class, OrderStatusChanged::class, OrderCanceled::class]);
        $branch = Branch::factory()->create();
        $customer = User::factory()->create([
            'branch_id' => $branch->id, 'status' => Status::ACTIVE,
            'loyalty_code' => 'FK-PHANTOM', 'loyalty_points' => 300, // 300 pts crédités par l'award au PREPARED
        ]);

        $order = FrontendOrder::withoutGlobalScopes()->create([
            'order_serial_no' => 'PHANTOM-'.fake()->unique()->numerify('######'),
            'user_id' => $customer->id, 'branch_id' => $branch->id,
            'subtotal' => 30, 'discount' => 0, 'delivery_charge' => 0, 'total_tax' => 0, 'total' => 30,
            'order_type' => OrderType::KIOSK, 'order_datetime' => now()->subMinutes(240), 'preparation_time' => 15,
            'is_advance_order' => 0, 'payment_method' => PaymentGateway::CASH_ON_DELIVERY,
            'payment_status' => PaymentStatus::PENDING_COUNTER, 'pos_payment_method' => PosPaymentMethod::COUNTER_DEFERRED,
            'status' => OrderStatus::PREPARED, 'source' => 10, 'source_surface' => 'kiosk',
            'fiscal_sequence_no' => null, 'loyalty_customer_code' => 'FK-PHANTOM',
            'loyalty_points_awarded' => 300, // points GAGNÉS sur cette commande jamais payée
            'created_at' => now()->subMinutes(240), 'updated_at' => now()->subMinutes(240),
        ]);
        LoyaltyTransaction::create([
            'user_id' => $customer->id, 'loyalty_code' => 'FK-PHANTOM', 'order_id' => $order->id,
            'type' => 'earn', 'points' => 300, 'balance_after' => 300, 'source_surface' => 'kiosk', 'description' => 'earn au PREPARED',
        ]);

        (new CleanupStalePendingKioskOrders())->handle();

        $this->assertNotNull($order->fresh()->deleted_at ?? FrontendOrder::withTrashed()->find($order->id)->deleted_at, 'fantôme purgé');
        $this->assertSame(0, (int) $customer->fresh()->loyalty_points, 'points cumulés repris (0), la maison ne paie pas une vente inexistante');
        $this->assertSame(1, LoyaltyTransaction::where('order_id', $order->id)->where('type', 'manual_deduct')->count());
    }

        /**
     * [P1-3 CUMUL 2026-08-04 · cycle1] Fantôme WEB PREPARED impayé : même faille que le kiosk
     * mais sur source_surface='web' (le fix P1-2 était kiosk-only). Purge + clawback des points GAGNÉS.
     */
    public function test_phantom_prepared_WEB_order_claws_back_earned_points_on_purge(): void
    {
        Event::fake([SendOrderMail::class, SendOrderSms::class, SendOrderPush::class, OrderStatusChanged::class, OrderCanceled::class]);
        $branch = Branch::factory()->create();
        $customer = User::factory()->create([
            'branch_id' => $branch->id, 'status' => Status::ACTIVE,
            'loyalty_code' => 'FK-PHWEB', 'loyalty_points' => 300,
        ]);
        $order = FrontendOrder::withoutGlobalScopes()->create([
            'order_serial_no' => 'PHWEB-'.fake()->unique()->numerify('######'),
            'user_id' => $customer->id, 'branch_id' => $branch->id,
            'subtotal' => 30, 'discount' => 0, 'delivery_charge' => 0, 'total_tax' => 0, 'total' => 30,
            'order_type' => OrderType::TAKEAWAY, 'order_datetime' => now()->subMinutes(240), 'preparation_time' => 15,
            'is_advance_order' => 0, 'payment_method' => PaymentGateway::CASH_ON_DELIVERY,
            'payment_status' => PaymentStatus::PENDING_COUNTER, 'pos_payment_method' => PosPaymentMethod::COUNTER_DEFERRED,
            'status' => OrderStatus::PREPARED, 'source' => Source::WEB, 'source_surface' => 'web',
            'fiscal_sequence_no' => null, 'loyalty_customer_code' => 'FK-PHWEB',
            'loyalty_points_awarded' => 300,
            'created_at' => now()->subMinutes(240), 'updated_at' => now()->subMinutes(240),
        ]);
        LoyaltyTransaction::create([
            'user_id' => $customer->id, 'loyalty_code' => 'FK-PHWEB', 'order_id' => $order->id,
            'type' => 'earn', 'points' => 300, 'balance_after' => 300, 'source_surface' => 'web', 'description' => 'earn',
        ]);

        (new CleanupStalePendingKioskOrders())->handle();

        $this->assertNotNull(FrontendOrder::withTrashed()->find($order->id)->deleted_at, 'fantôme web purgé');
        $this->assertSame(0, (int) $customer->fresh()->loyalty_points, 'points cumulés repris (web aussi)');
        $this->assertSame(1, LoyaltyTransaction::where('order_id', $order->id)->where('type', 'manual_deduct')->count());
    }

    private function makeAbandonedKioskCounterOrder(
        int $branchId,
        int $userId,
        Carbon $createdAt,
        ?string $loyaltyCode
    ): FrontendOrder {
        return FrontendOrder::withoutGlobalScopes()->create([
            'order_serial_no'       => 'KIOSK-C36-' . fake()->unique()->numerify('######'),
            'user_id'               => $userId,
            'branch_id'             => $branchId,
            'subtotal'              => 10,
            'discount'              => 0,
            'delivery_charge'       => 0,
            'total_tax'             => 1,
            'total'                 => 11,
            'order_type'            => OrderType::KIOSK,
            'order_datetime'        => $createdAt,
            'preparation_time'      => 15,
            'is_advance_order'      => 0,
            'payment_method'        => PaymentGateway::CASH_ON_DELIVERY,
            'payment_status'        => PaymentStatus::PENDING_COUNTER,
            'pos_payment_method'    => PosPaymentMethod::COUNTER_DEFERRED,
            'status'                => OrderStatus::ACCEPT,
            'source'                => 10,
            'source_surface'        => 'kiosk',
            'fiscal_sequence_no'    => null,
            'loyalty_customer_code' => $loyaltyCode,
            'created_at'            => $createdAt,
            'updated_at'            => $createdAt,
        ]);
    }
}
