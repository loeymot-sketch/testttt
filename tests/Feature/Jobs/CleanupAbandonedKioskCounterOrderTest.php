<?php

namespace Tests\Feature\Jobs;

use App\Domain\Order\OrderStateMachine;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentGateway;
use App\Enums\PaymentStatus;
use App\Enums\PosPaymentMethod;
use App\Events\OrderCanceled;
use App\Events\OrderStatusChanged;
use App\Events\SendOrderMail;
use App\Events\SendOrderPush;
use App\Events\SendOrderSms;
use App\Jobs\CleanupStalePendingKioskOrders;
use App\Models\Branch;
use App\Models\FrontendOrder;
use App\Models\Scopes\BranchScope;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * [TRAP-2 2026-06-04] Real abandoned-kiosk cleanup.
 *
 * The deep-review trap: a walk-away kiosk Plan-B cash order AUTO-ACCEPTS to
 * status=ACCEPT + payment_status=PENDING_COUNTER + pos_payment_method=
 * COUNTER_DEFERRED (FrontendOrderService:208,266-267,590-593). The previous
 * cleanup gate filtered status=PENDING ONLY → it matched ZERO real kiosk
 * orders and was DEAD CODE.
 *
 * This test uses the REAL fixture shape (status=ACCEPT) — NOT the artificial
 * status=PENDING that the older sentinel locked — and proves:
 *   1. an OLD uncollected ACCEPT/PENDING_COUNTER kiosk order IS cleaned
 *      (ACCEPT→CANCELED, a legal non-fiscal transition);
 *   2. its COUNTER_DEFERRED marker is broken so a late collect is refused
 *      (NF525-safe: a canceled row can never be fiscalized + paid);
 *   3. a COLLECTED + fiscalized kiosk order is NOT touched (NF525 invariant —
 *      a row with a fiscal_sequence_no is never mutated).
 */
class CleanupAbandonedKioskCounterOrderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Deterministic TTL for the fixtures below.
        config(['kiosk.stale_collect_ttl_minutes' => 180]);
    }

    public function test_old_abandoned_kiosk_counter_order_is_canceled_marker_broken(): void
    {
        Event::fake([
            SendOrderMail::class,
            SendOrderSms::class,
            SendOrderPush::class,
            OrderStatusChanged::class,
            OrderCanceled::class,
        ]);

        $branch = Branch::factory()->create();
        $customer = User::factory()->create(['branch_id' => $branch->id]);

        // REAL walk-away kiosk cash order: auto-accepted, never collected, 4 h old.
        $abandoned = $this->makeAbandonedKioskCounterOrder(
            $branch->id,
            $customer->id,
            now()->subMinutes(240)
        );

        (new CleanupStalePendingKioskOrders())->handle();

        $fresh = $abandoned->fresh();

        $this->assertSame(
            OrderStatus::CANCELED,
            (int) $fresh->status,
            'Abandoned ACCEPT/PENDING_COUNTER kiosk order must be auto-canceled (ACCEPT→CANCELED legal, KDS-clearing).'
        );

        // Counter-deferred marker broken → a late collect can never fiscalize it.
        $this->assertNull(
            $fresh->pos_payment_method,
            'COUNTER_DEFERRED marker must be cleared so confirmCounterPayment refuses the canceled order (NF525-safe).'
        );

        // No fiscal sequence ever allocated → NF525 chain untouched.
        $this->assertNull(
            $fresh->fiscal_sequence_no,
            'Canceled abandoned order must carry NO fiscal sequence.'
        );

        Event::assertDispatchedTimes(OrderStatusChanged::class, 1);
        Event::assertDispatchedTimes(OrderCanceled::class, 1);
        Event::assertDispatched(OrderStatusChanged::class, function (OrderStatusChanged $event) use ($abandoned): bool {
            return (int) $event->order->id === (int) $abandoned->id
                && $event->oldStatus === OrderStatus::ACCEPT
                && $event->newStatus === OrderStatus::CANCELED;
        });
    }

    /**
     * [CLUSTER-5 2026-07-09] A kiosk Plan-B cash order is bumped ACCEPT→PREPARING on the
     * KDS BEFORE the counter collects the cash. The old lane only matched ACCEPT, so an
     * abandoned order already at PREPARING was NEVER reaped (stuck in "à encaisser" + KDS
     * forever). The lane is now symmetric to the phone lane: PREPARING is reaped via the
     * legal PREPARING→CANCELED transition.
     */
    public function test_old_abandoned_kiosk_order_in_preparing_is_canceled(): void
    {
        Event::fake([
            SendOrderMail::class,
            SendOrderSms::class,
            SendOrderPush::class,
            OrderStatusChanged::class,
            OrderCanceled::class,
        ]);

        $branch = Branch::factory()->create();
        $customer = User::factory()->create(['branch_id' => $branch->id]);

        // Same abandoned kiosk cash order, but the KDS already bumped it to PREPARING.
        $abandoned = $this->makeAbandonedKioskCounterOrder(
            $branch->id,
            $customer->id,
            now()->subMinutes(240)
        );
        FrontendOrder::withoutGlobalScopes()
            ->whereKey($abandoned->id)
            ->update(['status' => OrderStatus::PREPARING]);

        (new CleanupStalePendingKioskOrders())->handle();

        $fresh = $abandoned->fresh();

        $this->assertSame(
            OrderStatus::CANCELED,
            (int) $fresh->status,
            'Abandoned PREPARING/PENDING_COUNTER kiosk order must be auto-canceled (PREPARING→CANCELED legal).'
        );
        $this->assertNull(
            $fresh->pos_payment_method,
            'COUNTER_DEFERRED marker must be cleared so a late collect is refused (NF525-safe).'
        );
        $this->assertNull(
            $fresh->fiscal_sequence_no,
            'Canceled abandoned order must carry NO fiscal sequence.'
        );

        Event::assertDispatched(OrderStatusChanged::class, function (OrderStatusChanged $event) use ($abandoned): bool {
            return (int) $event->order->id === (int) $abandoned->id
                && $event->oldStatus === OrderStatus::PREPARING
                && $event->newStatus === OrderStatus::CANCELED;
        });
    }

    /**
     * [CLUSTER-5 2026-07-09] A collected (PAID) + fiscalized kiosk order that is ALSO
     * bumped to PREPARING must remain immune: the fiscal_sequence_no guard wins over the
     * newly-widened status set. Proves the wider lane never reaps a sealed row.
     */
    public function test_collected_fiscalized_preparing_kiosk_order_is_never_touched(): void
    {
        Event::fake([
            SendOrderMail::class,
            SendOrderSms::class,
            SendOrderPush::class,
            OrderStatusChanged::class,
            OrderCanceled::class,
        ]);

        $branch = Branch::factory()->create();
        $customer = User::factory()->create(['branch_id' => $branch->id]);

        $collected = $this->makeAbandonedKioskCounterOrder(
            $branch->id,
            $customer->id,
            now()->subMinutes(240)
        );
        FrontendOrder::withoutGlobalScopes()
            ->whereKey($collected->id)
            ->update([
                'status'             => OrderStatus::PREPARING,
                'payment_status'     => PaymentStatus::PAID,
                'fiscal_sequence_no' => 1,
                'pos_payment_method' => PosPaymentMethod::CASH,
            ]);

        (new CleanupStalePendingKioskOrders())->handle();

        $fresh = $collected->fresh();

        $this->assertSame(
            OrderStatus::PREPARING,
            (int) $fresh->status,
            'A collected (PAID+fiscalized) PREPARING order must NEVER be auto-canceled.'
        );
        $this->assertSame(1, (int) $fresh->fiscal_sequence_no, 'Fiscalized order sequence untouched.');

        Event::assertNotDispatched(OrderStatusChanged::class);
        Event::assertNotDispatched(OrderCanceled::class);
    }

    /**
     * [CLUSTER-5-reste 2026-07-11] PHANTOM PREPARED PURGÉ PAR SOFT-DELETE.
     * Un fantôme borne UNPAID + non-fiscalisé + périmé est SOFT-DELETE : il quitte
     * « à encaisser » + KDS (SoftDeletingScope). Ce test épingle : le job ne throw
     * PAS, et le fantôme est trashed().
     *
     * [LOCK-OSM-CANCEL-AFTER-READY 2026-08-19, owner-gated] Ce cas affirmait
     * auparavant `assertFalse(allows(PREPARED, CANCELED))` et en déduisait la
     * nécessité du soft-delete. **Cette prémisse n'est plus vraie** : la transition
     * a été ouverte sous gate propriétaire (le patron ne pouvait plus annuler une
     * commande dès que la cuisine la déclarait prête). L'assertion a donc été
     * retirée — elle épinglait une règle abolie, pas le comportement du janitor.
     *
     * Le COMPORTEMENT du job, lui, est inchangé et reste vérifié ci-dessous : sa
     * requête code en dur `whereIn('status', [PENDING, ACCEPT, PREPARING])`
     * (CleanupStalePendingKioskOrders.php:66) et la branche PREPARED appelle
     * `->delete()` sans jamais consulter `allows()`.
     *
     * ARBITRAGE OUVERT (propriétaire) : maintenant que la transition est légale, le
     * janitor POURRAIT annuler proprement (transition auditée, motif, événements)
     * au lieu d'un soft-delete muet qui laisse `status=PREPARED` pour toujours.
     * Non fait ici : ce serait un changement de comportement hors périmètre du LOCK.
     */
    public function test_stale_prepared_kiosk_phantom_is_soft_deleted_and_job_does_not_throw(): void
    {
        $branch = Branch::factory()->create();
        $customer = User::factory()->create(['branch_id' => $branch->id]);

        $prepared = $this->makeAbandonedKioskCounterOrder(
            $branch->id,
            $customer->id,
            now()->subMinutes(240)
        );
        FrontendOrder::withoutGlobalScopes()
            ->whereKey($prepared->id)
            ->update(['status' => OrderStatus::PREPARED]);

        // Must NOT throw an IllegalTransitionException.
        (new CleanupStalePendingKioskOrders())->handle();

        // Soft-deleted → invisible to the default (KDS + collect-queue) scope.
        $this->assertNull(
            FrontendOrder::withoutGlobalScope(BranchScope::class)->find($prepared->id),
            'Stale PREPARED kiosk phantom must be soft-deleted (gone from « à encaisser » + KDS).'
        );

        // Still present WITH the soft-delete filter lifted, and marked trashed.
        $trashed = FrontendOrder::withoutGlobalScope(BranchScope::class)->withTrashed()->find($prepared->id);
        $this->assertNotNull($trashed, 'Soft-deleted phantom row still exists (deleted_at set, not hard-deleted).');
        $this->assertTrue($trashed->trashed(), 'Phantom PREPARED order must be trashed() after purge.');

        // NF525: never fiscalized. The counter-deferred marker is broken so a late collect is refused.
        $this->assertNull($trashed->fiscal_sequence_no, 'Purged phantom carries NO fiscal sequence.');
        $this->assertNull($trashed->pos_payment_method, 'COUNTER_DEFERRED marker cleared so a late collect is refused.');
        // Status is left as-is (no illegal transition applied to the frozen state machine).
        $this->assertSame(OrderStatus::PREPARED, (int) $trashed->status, 'Status untouched — purge is a soft-delete, not a transition.');
    }

    /**
     * [CLUSTER-5-reste 2026-07-11] GARDE ABSOLUE NF525. Un fantôme au statut PREPARED qui est
     * PAYÉ (PAID) ou FISCALISÉ (fiscal_sequence_no non-null) ne doit JAMAIS être soft-deleté :
     * la garde fiscal_sequence_no + payment_status gagne sur le statut PREPARED périmé.
     */
    public function test_paid_or_fiscalized_prepared_kiosk_order_is_never_soft_deleted(): void
    {
        $branch = Branch::factory()->create();
        $customer = User::factory()->create(['branch_id' => $branch->id]);

        // (a) PAID + fiscalisée.
        $fiscalized = $this->makeAbandonedKioskCounterOrder($branch->id, $customer->id, now()->subMinutes(240));
        FrontendOrder::withoutGlobalScopes()->whereKey($fiscalized->id)->update([
            'status'             => OrderStatus::PREPARED,
            'payment_status'     => PaymentStatus::PAID,
            'fiscal_sequence_no' => 1,
            'pos_payment_method' => PosPaymentMethod::CASH,
        ]);

        // (b) fiscalisée mais toujours PENDING_COUNTER (fiscal_sequence_no seul suffit à immuniser).
        $sealedOnly = $this->makeAbandonedKioskCounterOrder($branch->id, $customer->id, now()->subMinutes(240));
        FrontendOrder::withoutGlobalScopes()->whereKey($sealedOnly->id)->update([
            'status'             => OrderStatus::PREPARED,
            'fiscal_sequence_no' => 2,
        ]);

        (new CleanupStalePendingKioskOrders())->handle();

        foreach ([$fiscalized->id, $sealedOnly->id] as $id) {
            $fresh = FrontendOrder::withoutGlobalScope(BranchScope::class)->find($id);
            $this->assertNotNull($fresh, 'A PAID/fiscalized PREPARED order must NEVER be soft-deleted.');
            $this->assertFalse($fresh->trashed(), 'A PAID/fiscalized PREPARED order must NEVER be trashed.');
            $this->assertSame(OrderStatus::PREPARED, (int) $fresh->status, 'Sealed order status untouched.');
            $this->assertNotNull($fresh->fiscal_sequence_no, 'Fiscal sequence untouched (NF525 chain).');
        }
    }

    public function test_collected_fiscalized_kiosk_order_is_never_touched(): void
    {
        Event::fake([
            SendOrderMail::class,
            SendOrderSms::class,
            SendOrderPush::class,
            OrderStatusChanged::class,
            OrderCanceled::class,
        ]);

        $branch = Branch::factory()->create();
        $customer = User::factory()->create(['branch_id' => $branch->id]);

        // Same age, but already COLLECTED at the counter: payment_status=PAID and
        // a fiscal_sequence_no allocated (PaymentService seal). Must be immune.
        $collected = $this->makeAbandonedKioskCounterOrder(
            $branch->id,
            $customer->id,
            now()->subMinutes(240)
        );
        FrontendOrder::withoutGlobalScopes()
            ->whereKey($collected->id)
            ->update([
                'payment_status'     => PaymentStatus::PAID,
                'fiscal_sequence_no' => 1,
                'pos_payment_method' => PosPaymentMethod::CASH,
            ]);

        (new CleanupStalePendingKioskOrders())->handle();

        $fresh = $collected->fresh();

        $this->assertSame(
            OrderStatus::ACCEPT,
            (int) $fresh->status,
            'A collected (PAID) order must NEVER be auto-canceled.'
        );
        $this->assertSame(
            PaymentStatus::PAID,
            (int) $fresh->payment_status,
            'Collected order payment_status must remain PAID.'
        );
        $this->assertSame(
            1,
            (int) $fresh->fiscal_sequence_no,
            'Fiscalized order fiscal_sequence_no must be untouched (NF525 chain).'
        );

        Event::assertNotDispatched(OrderStatusChanged::class);
        Event::assertNotDispatched(OrderCanceled::class);
    }

    public function test_cancel_transition_is_legal_without_frozen_state_machine_edit(): void
    {
        // Guards that the chosen terminal action uses only LEGAL transitions —
        // ACCEPT→REJECTED is illegal, ACCEPT→CANCELED is legal.
        $this->assertFalse(
            OrderStateMachine::allows(OrderStatus::ACCEPT, OrderStatus::REJECTED),
            'ACCEPT→REJECTED must be illegal (so the job must NOT reject auto-accepted kiosk orders).'
        );
        $this->assertTrue(
            OrderStateMachine::allows(OrderStatus::ACCEPT, OrderStatus::CANCELED),
            'ACCEPT→CANCELED must be legal (the terminal action the job uses).'
        );
    }

    private function makeAbandonedKioskCounterOrder(int $branchId, int $userId, Carbon $createdAt): FrontendOrder
    {
        return FrontendOrder::withoutGlobalScopes()->create([
            'order_serial_no'    => 'KIOSK-TRAP2-' . fake()->unique()->numerify('######'),
            'user_id'            => $userId,
            'branch_id'          => $branchId,
            'subtotal'           => 10,
            'discount'           => 0,
            'delivery_charge'    => 0,
            'total_tax'          => 1,
            'total'              => 11,
            'order_type'         => OrderType::KIOSK,
            'order_datetime'     => $createdAt,
            'preparation_time'   => 15,
            'is_advance_order'   => 0,
            // Real Plan-B cash markers set by FrontendOrderService at creation.
            'payment_method'     => PaymentGateway::CASH_ON_DELIVERY,
            'payment_status'     => PaymentStatus::PENDING_COUNTER,
            'pos_payment_method' => PosPaymentMethod::COUNTER_DEFERRED,
            // Auto-accepted (the trap): real abandoned orders sit at ACCEPT.
            'status'             => OrderStatus::ACCEPT,
            'source'             => 10,
            'source_surface'     => 'kiosk',
            'fiscal_sequence_no' => null,
            'created_at'         => $createdAt,
            'updated_at'         => $createdAt,
        ]);
    }
}
