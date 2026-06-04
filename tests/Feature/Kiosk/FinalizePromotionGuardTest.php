<?php

namespace Tests\Feature\Kiosk;

use App\Enums\Ask;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentGateway;
use App\Enums\PaymentStatus;
use App\Enums\Source;
use App\Enums\Status;
use App\Events\OrderCreated;
use App\Events\OrderStatusChanged;
use App\Models\Branch;
use App\Models\FrontendOrder;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\KioskMachine;
use App\Models\User;
use App\Services\FrontendOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Smartisan\Settings\Facades\Settings;
use Tests\TestCase;

/**
 * @FK-ID F-013 — finalizePaidKioskOrder state guard whitelist
 * @source .claude/worktrees/blissful-mclean-c915c2/plans/PLAN_AUDIT_F013_FINALIZE_STATE_GUARD_2026-05-07.md
 * @sprint Backlog (P3 — pattern hardening, not active bug)
 *
 * Functional regression pinning for the whitelist guard introduced by F-013.
 *
 * IMPORTANT — Honest framing of red/green :
 *   The pre-fix check `if ((int) $locked->status >= OrderStatus::ACCEPT) return;`
 *   was numerically equivalent to the new whitelist `[OrderStatus::PENDING]`
 *   for every status currently defined in App\Enums\OrderStatus
 *   (PENDING=1 is the only value strictly less than ACCEPT=4).
 *   Therefore the three scenarios below were ALREADY GREEN on the pre-fix
 *   source and are not a TDD red→green proof. Their purpose is to PIN the
 *   behavior contract so any future regression (e.g. accidental revert to
 *   `>= ACCEPT` combined with insertion of an intermediate status code)
 *   would surface as a functional failure.
 *
 *   The genuine RED→GREEN evidence for F-013 lives in
 *   tests/Feature/Sentinels/F013FinalizeStateGuardSentinelTest.php which
 *   asserts the structural whitelist pattern in source.
 *
 * @see app/Services/FrontendOrderService.php finalizePaidKioskOrder
 */
class FinalizePromotionGuardTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;
    protected User $kioskUser;
    protected Item $item;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();
        $this->seedSpatieRoles();

        config(['app.api_key' => '123456']);

        Settings::group('order_setup')->set([
            'order_setup_food_preparation_time' => 30,
            'order_setup_schedule_order_slot_duration' => 30,
            'order_setup_delivery' => 5,
            'order_setup_takeaway' => 5,
        ]);

        $this->branch = Branch::forceCreate([
            'name' => 'F013 Branch',
            'city' => 'Paris',
            'state' => 'IDF',
            'zip_code' => '75000',
            'address' => '13 rue audit',
            'status' => 1,
        ]);

        $category = ItemCategory::forceCreate([
            'name' => 'Burgers F013',
            'slug' => 'burgers-f013',
            'status' => 5,
        ]);

        $this->item = Item::forceCreate([
            'name' => 'F013 Burger',
            'slug' => 'f013-burger',
            'price' => 12.50,
            'status' => 5,
            'item_category_id' => $category->id,
        ]);

        $this->kioskUser = User::forceCreate([
            'name' => 'Kiosk F013 User',
            'email' => 'kiosk-f013@test.local',
            'username' => 'kiosk_f013',
            'phone' => '0600000013',
            'password' => bcrypt('password123'),
            'branch_id' => $this->branch->id,
        ]);

        KioskMachine::forceCreate([
            'user_id' => $this->kioskUser->id,
            'branch_id' => $this->branch->id,
            'machine_id' => 'machine-f013-01',
            'username' => 'kiosk-f013',
            'password' => bcrypt('kiosk123'),
            'is_login' => Ask::NO,
            'status' => Status::ACTIVE,
        ]);
    }

    private function makeOrder(int $status, int $paymentStatus, string $serial, ?string $queue = null): FrontendOrder
    {
        return FrontendOrder::forceCreate([
            'order_serial_no'  => $serial,
            'user_id'          => $this->kioskUser->id,
            'branch_id'        => $this->branch->id,
            'status'           => $status,
            'payment_status'   => $paymentStatus,
            'payment_method'   => PaymentGateway::CARD,
            'order_type'       => OrderType::TAKEAWAY,
            'source'           => Source::APP,
            'subtotal'         => 12.50,
            'total'            => 12.50,
            'discount'         => 0,
            'delivery_charge'  => 0,
            'order_datetime'   => now(),
            'preparation_time' => 30,
            'queue_number'     => $queue ?? ('F0'.substr(md5($serial), 0, 4)),
        ]);
    }

    /**
     * Positive control — PENDING + PAID is the only state that promotes.
     */
    public function test_pending_paid_order_is_promoted_to_accept(): void
    {
        Event::fake([OrderCreated::class, OrderStatusChanged::class]);

        $order = $this->makeOrder(OrderStatus::PENDING, PaymentStatus::PAID, '310513F013P');

        $promoted = app(FrontendOrderService::class)->finalizePaidKioskOrder($order);

        // [Wave S-1 — 2026-05-20] Owner P-OWNER Wave S-1: PENDING+PAID kiosk
        // promotion goes through ACCEPT in-tx and then immediately advances
        // to PREPARING per the auto-prepare policy. Post-commit DB state
        // observes the final status only.
        $this->assertTrue($promoted, 'PENDING+PAID kiosk card order must be promoted (S-1: lands in PREPARING).');
        $this->assertDatabaseHas('orders', [
            'id'             => $order->id,
            'status'         => OrderStatus::PREPARING,
            'payment_status' => PaymentStatus::PAID,
        ]);
    }

    /**
     * Whitelist guard — ACCEPT (re-entrance) must early-return without side effects.
     * Belt-and-suspenders : the fiscal_seq null guard at line ~1050 already
     * blocks re-allocation, but the whitelist makes the intent explicit at the
     * status-machine layer.
     */
    public function test_already_accept_order_short_circuits_without_side_effects(): void
    {
        Event::fake([OrderCreated::class, OrderStatusChanged::class]);

        $order = $this->makeOrder(OrderStatus::ACCEPT, PaymentStatus::PAID, '310513F013A');
        $order->fiscal_sequence_no = 9999;
        $order->save();

        $promoted = app(FrontendOrderService::class)->finalizePaidKioskOrder($order);

        $this->assertFalse($promoted, 'Already-ACCEPT order must short-circuit (no re-promotion).');
        $this->assertDatabaseHas('orders', [
            'id'                 => $order->id,
            'status'             => OrderStatus::ACCEPT,
            'fiscal_sequence_no' => 9999,
        ]);

        // The dispatchOrderStatusSignals path must NOT fire on a no-op.
        Event::assertNotDispatched(OrderCreated::class);
        Event::assertNotDispatched(OrderStatusChanged::class);
    }

    /**
     * Whitelist guard — terminal CANCELED order must not be re-promoted.
     * Pre-fix the `>= ACCEPT (4)` check happened to also skip CANCELED (16),
     * REJECTED (19), RETURNED (22) by numeric coincidence. The new whitelist
     * preserves that protection by intent rather than accident.
     */
    public function test_canceled_order_is_not_promoted(): void
    {
        Event::fake([OrderCreated::class, OrderStatusChanged::class]);

        $order = $this->makeOrder(OrderStatus::CANCELED, PaymentStatus::PAID, '310513F013C');

        $promoted = app(FrontendOrderService::class)->finalizePaidKioskOrder($order);

        $this->assertFalse($promoted, 'CANCELED order must never be re-promoted to ACCEPT.');
        $this->assertDatabaseHas('orders', [
            'id'     => $order->id,
            'status' => OrderStatus::CANCELED,
        ]);

        Event::assertNotDispatched(OrderCreated::class);
        Event::assertNotDispatched(OrderStatusChanged::class);
    }
}
