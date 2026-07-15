<?php

namespace Tests\Feature\Order;

use App\Domain\Order\AutoPrepareOnPaidPolicy;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentGateway;
use App\Enums\PaymentStatus;
use App\Enums\PosPaymentMethod;
use App\Events\OrderCreated;
use App\Events\OrderPaidAtCounter;
use App\Events\OrderStatusChanged;
use App\Models\Branch;
use App\Models\FrontendOrder;
use App\Models\KioskMachine;
use App\Models\Order;
use App\Models\User;
use App\Services\FrontendOrderService;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Wave S-1 — owner decision 2026-05-20:
 *   ACCEPT (status=4) auto-transitions to PREPARING (status=7) the moment
 *   payment is confirmed, so cashiers don't have to tap a second button on
 *   the KDS to release a paid order into the kitchen.
 *
 *   EXCEPTION (Wave S-5 sister mission): kiosk orders paid in CASH at the
 *   counter (mode=PosPaymentMethod::CASH on confirmCounterPayment) must NOT
 *   auto-prepare. They stay in ACCEPT until the cashier explicitly validates
 *   cash collection through the S-5 cash-pending UI.
 *
 *   Config knob `pos.auto_prepare_on_paid` (env `POS_AUTO_PREPARE_ON_PAID`)
 *   defaults to TRUE in production; setting it to FALSE preserves the legacy
 *   behaviour for emergency rollback.
 *
 * The policy is centralised in {@see AutoPrepareOnPaidPolicy} so the three
 * payment paths (POS direct, kiosk paid TPE finalize, counter-collect)
 * cannot drift. The test asserts policy + each integration.
 */
class AutoPrepareOnPaidTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
        Config::set('pos.auto_prepare_on_paid', true);
        Config::set('pos.simulation_hardware', true);
    }

    // ---------------------------------------------------------------------
    // Policy unit checks — single source of truth for the three paths
    // ---------------------------------------------------------------------

    public function test_policy_promotes_pos_direct_path(): void
    {
        $this->assertTrue(AutoPrepareOnPaidPolicy::shouldPromote(
            surface: 'pos',
            posPaymentMethod: PosPaymentMethod::CASH
        ));
        $this->assertTrue(AutoPrepareOnPaidPolicy::shouldPromote(
            surface: 'pos',
            posPaymentMethod: PosPaymentMethod::CARD
        ));
    }

    public function test_policy_promotes_kiosk_paid_tpe(): void
    {
        $this->assertTrue(AutoPrepareOnPaidPolicy::shouldPromote(
            surface: 'kiosk',
            posPaymentMethod: null // kiosk paid online → no pos_payment_method set
        ));
    }

    public function test_policy_promotes_counter_collect_non_cash(): void
    {
        foreach ([PosPaymentMethod::CARD, PosPaymentMethod::MOBILE_BANKING,
                  PosPaymentMethod::OTHER, PosPaymentMethod::TICKET_RESTAURANT] as $mode) {
            $this->assertTrue(
                AutoPrepareOnPaidPolicy::shouldPromote(
                    surface: 'kiosk',
                    posPaymentMethod: $mode,
                    isCounterCollect: true
                ),
                "Counter-collect mode={$mode} must auto-promote"
            );
        }
    }

    public function test_policy_skips_kiosk_cash_at_counter(): void
    {
        $this->assertFalse(
            AutoPrepareOnPaidPolicy::shouldPromote(
                surface: 'kiosk',
                posPaymentMethod: PosPaymentMethod::CASH,
                isCounterCollect: true
            ),
            'S-5 EXCEPTION: kiosk cash-at-counter must NOT auto-promote'
        );
    }

    /**
     * [Wave T R3 F2 — 2026-05-20] Regression sentinel.
     *
     * Wave T R2 Wave B adversarial flagged a DELIVERY-order (#70) appearing
     * on KDS at status=PREPARED (8) and mis-read it as "auto-PREPA promoted
     * to PREPARED instead of PREPARING". Investigation (see commit message)
     * proved the actual cause was test-data carryover: the fixture pointed
     * at an order already bumped through PREPARING → PREPARED by a prior
     * E2E cycle. The policy itself is and must remain ORDER-TYPE-AGNOSTIC:
     * the only inputs that matter are (surface, posPaymentMethod,
     * isCounterCollect). This test pins that invariant — DELIVERY,
     * TAKEAWAY, KIOSK and DINE_IN must all promote to PREPARING (7),
     * never PREPARED (8).
     */
    public function test_policy_next_status_is_preparing_never_prepared(): void
    {
        $this->assertSame(
            OrderStatus::PREPARING,
            AutoPrepareOnPaidPolicy::nextStatus(),
            'Auto-PREPA target must be PREPARING (7), NEVER PREPARED (8).'
        );
        $this->assertNotSame(
            OrderStatus::PREPARED,
            AutoPrepareOnPaidPolicy::nextStatus(),
            'Wave T R3 F2 sentinel: nextStatus() must not return PREPARED.'
        );
    }

    public function test_policy_order_type_agnostic_delivery_promotes_like_takeaway(): void
    {
        // The policy intentionally does NOT consume $order->order_type — kiosk
        // online TPE delivery orders, POS counter-collect TPE delivery orders
        // and POS direct CASH takeaway orders must all yield the same
        // shouldPromote() answer when surface + payment-mode + isCounterCollect
        // match. This sentinel prevents a future refactor from sneaking a
        // delivery-only carve-out that lands DELIVERY orders in PREPARED
        // (the Wave T R3 F2 false-positive shape).
        foreach ([
            ['pos', PosPaymentMethod::CASH, false],
            ['pos', PosPaymentMethod::CARD, false],
            ['kiosk', null, false],
            ['kiosk', PosPaymentMethod::CARD, true],
        ] as [$surface, $mode, $isCC]) {
            $this->assertTrue(
                AutoPrepareOnPaidPolicy::shouldPromote(
                    surface: $surface,
                    posPaymentMethod: $mode,
                    isCounterCollect: $isCC,
                ),
                "Path surface={$surface} mode=" . ($mode ?? 'null') . ' isCounterCollect=' . ($isCC ? 'true' : 'false') . ' must promote regardless of order_type'
            );
        }
    }

    public function test_policy_respects_config_flag(): void
    {
        Config::set('pos.auto_prepare_on_paid', false);
        $this->assertFalse(AutoPrepareOnPaidPolicy::shouldPromote(
            surface: 'pos',
            posPaymentMethod: PosPaymentMethod::CASH
        ));
        $this->assertFalse(AutoPrepareOnPaidPolicy::shouldPromote(
            surface: 'kiosk',
            posPaymentMethod: PosPaymentMethod::CARD,
            isCounterCollect: true
        ));
    }

    // ---------------------------------------------------------------------
    // Integration: finalizePaidKioskOrder — kiosk paid TPE path
    // ---------------------------------------------------------------------

    public function test_kiosk_paid_tpe_finalize_lands_in_preparing(): void
    {
        Queue::fake();
        Event::fake([OrderCreated::class, OrderStatusChanged::class]);

        [$branch, $kioskUser] = $this->branchKioskUser();
        $frontendOrder = $this->pendingKioskTpeOrder($branch, $kioskUser);

        $frontendOrder->payment_status = PaymentStatus::PAID;
        $frontendOrder->save();

        $promoted = app(FrontendOrderService::class)->finalizePaidKioskOrder(
            $frontendOrder->fresh()
        );

        $this->assertTrue($promoted, 'finalizePaidKioskOrder must promote the order');
        $this->assertSame(
            OrderStatus::PREPARING,
            (int) $frontendOrder->fresh()->status,
            'Kiosk paid TPE must land in PREPARING after finalize when flag on'
        );
        Event::assertDispatched(OrderCreated::class);

        // [Wave S-1] Dual-broadcast pattern — both legs of the transition
        // must hit OrderStatusChanged so realtime subscribers see the full
        // ladder. PERSISTORDERSTATUSCHANGEDTOOUTBOX reads (oldStatus, newStatus)
        // straight from the event so passing only the final status would
        // omit the ACCEPT visibility window.
        Event::assertDispatched(OrderStatusChanged::class, function ($event) {
            return (int) $event->oldStatus === OrderStatus::PENDING
                && (int) $event->newStatus === OrderStatus::ACCEPT;
        });
        Event::assertDispatched(OrderStatusChanged::class, function ($event) {
            return (int) $event->oldStatus === OrderStatus::ACCEPT
                && (int) $event->newStatus === OrderStatus::PREPARING;
        });
    }

    public function test_kiosk_paid_tpe_stays_accept_when_flag_off(): void
    {
        Config::set('pos.auto_prepare_on_paid', false);
        Queue::fake();
        Event::fake([OrderCreated::class]);

        [$branch, $kioskUser] = $this->branchKioskUser();
        $frontendOrder = $this->pendingKioskTpeOrder($branch, $kioskUser);
        $frontendOrder->payment_status = PaymentStatus::PAID;
        $frontendOrder->save();

        app(FrontendOrderService::class)->finalizePaidKioskOrder($frontendOrder->fresh());

        $this->assertSame(
            OrderStatus::ACCEPT,
            (int) $frontendOrder->fresh()->status,
            'Env flag OFF preserves legacy ACCEPT (rollback safety)'
        );
    }

    // ---------------------------------------------------------------------
    // Integration: confirmCounterPayment — counter-collect path
    // ---------------------------------------------------------------------

    public function test_counter_collected_card_lands_in_preparing(): void
    {
        Queue::fake();
        Event::fake([OrderPaidAtCounter::class, OrderStatusChanged::class]);

        [$branch, $operator] = $this->branchOperator();
        $order = $this->pendingCounterOrder($branch);

        $this->actingAs($operator);
        app(PaymentService::class)->confirmCounterPayment(
            $order->fresh(),
            PosPaymentMethod::CARD
        );

        $this->assertSame(
            OrderStatus::PREPARING,
            (int) $order->fresh()->status,
            'Counter card payment auto-promotes to PREPARING'
        );
        $this->assertSame(PaymentStatus::PAID, (int) $order->fresh()->payment_status);
        // [Wave S-1] OrderStatusChanged broadcast surfaces the new column
        // movement for the realtime Suivi / KDS UIs.
        Event::assertDispatched(OrderStatusChanged::class, function ($event) {
            return (int) $event->oldStatus === OrderStatus::ACCEPT
                && (int) $event->newStatus === OrderStatus::PREPARING;
        });
    }

    public function test_counter_collected_cash_stays_accept(): void
    {
        Queue::fake();
        Event::fake([OrderPaidAtCounter::class, OrderStatusChanged::class]);

        [$branch, $operator] = $this->branchOperator();
        $order = $this->pendingCounterOrder($branch);

        $this->actingAs($operator);
        app(PaymentService::class)->confirmCounterPayment(
            $order->fresh(),
            PosPaymentMethod::CASH,
            (float) $order->total
        );

        $this->assertSame(
            OrderStatus::ACCEPT,
            (int) $order->fresh()->status,
            'S-5 EXCEPTION: kiosk cash-at-counter must stay in ACCEPT'
        );
        $this->assertSame(PaymentStatus::PAID, (int) $order->fresh()->payment_status);
        // [Wave S-1] No OrderStatusChanged broadcast for the S-5 cash carve-out
        // — status didn't move, no signal should land on the realtime bus.
        Event::assertNotDispatched(OrderStatusChanged::class);
    }

    public function test_counter_collected_card_no_double_promote_on_replay(): void
    {
        Queue::fake();
        Event::fake([OrderPaidAtCounter::class]);

        [$branch, $operator] = $this->branchOperator();
        $order = $this->pendingCounterOrder($branch);

        $this->actingAs($operator);
        app(PaymentService::class)->confirmCounterPayment(
            $order->fresh(),
            PosPaymentMethod::CARD
        );
        $this->assertSame(OrderStatus::PREPARING, (int) $order->fresh()->status);

        // Second call: payment_status === PAID short-circuit at line 226-229 of
        // PaymentService — no transition fires, status stays PREPARING.
        app(PaymentService::class)->confirmCounterPayment(
            $order->fresh(),
            PosPaymentMethod::CARD
        );
        $this->assertSame(OrderStatus::PREPARING, (int) $order->fresh()->status);
    }

    /**
     * [F-COUNTER-PHANTOM-TRANSITION 2026-07-15 / P2] Une commande différée (téléphone/POS)
     * créée DIRECTEMENT en PREPARING — jamais passée par ACCEPT — ne doit émettre AUCUN
     * OrderStatusChanged(ACCEPT→PREPARING) à l'encaissement. Avant, la garde testait l'état
     * FINAL (status===PREPARING, toujours vrai) → faux event à chaque encaissement.
     */
    public function test_deferred_order_already_preparing_emits_no_phantom_transition(): void
    {
        Queue::fake();
        Event::fake([OrderPaidAtCounter::class, OrderStatusChanged::class]);

        [$branch, $operator] = $this->branchOperator();
        $order = $this->pendingCounterOrder($branch, [
            'status'         => OrderStatus::PREPARING, // créée directement en PREPARING
            'source_surface' => 'pos',
        ]);

        $this->actingAs($operator);
        app(PaymentService::class)->confirmCounterPayment(
            $order->fresh(),
            PosPaymentMethod::CARD
        );

        $this->assertSame(OrderStatus::PREPARING, (int) $order->fresh()->status);
        $this->assertSame(PaymentStatus::PAID, (int) $order->fresh()->payment_status);
        Event::assertNotDispatched(OrderStatusChanged::class);
    }

    // ---------------------------------------------------------------------
    // Fixtures
    // ---------------------------------------------------------------------

    /** @return array{0: Branch, 1: User} */
    private function branchOperator(): array
    {
        $branch = Branch::factory()->create();
        $operator = User::factory()->create(['branch_id' => $branch->id]);
        $operator->assignRole('POS Operator');
        return [$branch, $operator];
    }

    /** @return array{0: Branch, 1: User} */
    private function branchKioskUser(): array
    {
        $branch = Branch::factory()->create();
        $kioskUser = User::factory()->create(['branch_id' => $branch->id]);
        KioskMachine::query()->create([
            'branch_id' => $branch->id,
            'user_id' => $kioskUser->id,
            'machine_id' => 'k-' . uniqid(),
            'username' => 'kiosk-' . uniqid(),
            'password' => bcrypt('secret'),
            'status' => 1,
        ]);
        return [$branch, $kioskUser];
    }

    private function pendingKioskTpeOrder(Branch $branch, User $kioskUser): FrontendOrder
    {
        return FrontendOrder::query()->create([
            'order_serial_no' => 'FK-' . uniqid(),
            'user_id' => $kioskUser->id,
            'branch_id' => $branch->id,
            'order_type' => OrderType::KIOSK,
            'subtotal' => 15.00,
            'total' => 15.00,
            'discount' => 0,
            'delivery_charge' => 0,
            'order_datetime' => now(),
            'payment_method' => PaymentGateway::CARD,
            'payment_status' => PaymentStatus::UNPAID,
            'status' => OrderStatus::PENDING,
            'source_surface' => 'kiosk',
        ]);
    }

    private function pendingCounterOrder(Branch $branch, array $overrides = []): Order
    {
        return Order::factory()->create($overrides + [
            'branch_id' => $branch->id,
            'user_id' => User::factory()->create(['branch_id' => $branch->id])->id,
            'order_type' => OrderType::KIOSK,
            'payment_method' => PaymentGateway::CASH_ON_DELIVERY,
            'payment_status' => PaymentStatus::PENDING_COUNTER,
            'pos_payment_method' => PosPaymentMethod::COUNTER_DEFERRED,
            'status' => OrderStatus::ACCEPT,
            'source_surface' => 'kiosk',
            'subtotal' => 12.00,
            'total' => 12.00,
            'discount' => 0,
            'delivery_charge' => 0,
            'fiscal_sequence_no' => null,
        ]);
    }
}
