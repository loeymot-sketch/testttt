<?php

namespace Tests\Feature\Order;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Http\Requests\OrderStatusRequest;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

/**
 * [GOAL-2026-05-29 F3] OrderService::changeStatus validated the transition against
 * the PRE-LOCK (route-bound) $order->status, then inside the lockForUpdate block
 * only checked same-status idempotency — never re-validated the transition against
 * the FRESH locked status. So if a concurrent process moved the row between
 * route-binding and the lock, an originally-valid transition could be persisted as
 * an ILLEGAL state-machine edge (e.g. CANCELED->PREPARED).
 *
 * The fix re-validates inside the lock. This test simulates the race
 * deterministically: a stale in-memory status (PREPARING, valid->PREPARED) while
 * the DB row was concurrently moved to CANCELED (CANCELED->PREPARED is illegal).
 * The illegal transition must NOT persist. (A true multi-process TOCTOU harness is
 * a follow-up per the audit's own caveat; this pins the in-lock re-check logic.)
 */
class ChangeStatusRaceGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
    }

    public function test_in_lock_revalidation_blocks_illegal_transition_after_concurrent_move(): void
    {
        $branch = Branch::factory()->create();
        // NON-admin, same-branch staff: the state machine enforces transitions for
        // them (admin would bypass via the Admin override at OrderStateMachine:66).
        // PREPARING->PREPARED is allowed for any user (line 52); CANCELED->PREPARED
        // is false for non-admin (line 63-70) — so the in-lock re-check must bite.
        $staff = User::factory()->create(['branch_id' => $branch->id]);
        $staff->assignRole('POS Operator');
        Auth::setUser($staff);

        // Route-bound model shows PREPARING (so the pre-lock check PREPARING->PREPARED passes).
        $order = Order::factory()->create([
            'branch_id'      => $branch->id,
            'order_type'     => OrderType::POS,
            'status'         => OrderStatus::PREPARING,
            'payment_status' => PaymentStatus::PAID,
            'total'          => 20.00,
        ]);

        // Simulate a CONCURRENT transition: another actor cancels the DB row AFTER
        // route-binding. $order (in-memory) still reads PREPARING; the DB is CANCELED.
        Order::withoutGlobalScopes()->where('id', $order->id)
            ->update(['status' => OrderStatus::CANCELED]);

        $request = new OrderStatusRequest();
        $request->merge(['status' => OrderStatus::PREPARED]);

        try {
            app(OrderService::class)->changeStatus($order, $request, false);
        } catch (\Throwable $e) {
            // Expected: 422 invalid transition — the in-lock re-check rejected
            // CANCELED->PREPARED. (Catch is tolerant; the DB assertion is the proof.)
        }

        // The illegal transition must NOT have persisted — the row stays CANCELED.
        $fresh = Order::withoutGlobalScopes()->find($order->id);
        $this->assertSame(
            OrderStatus::CANCELED,
            (int) $fresh->status,
            'In-lock re-validation MUST block the illegal CANCELED->PREPARED transition after a concurrent move.'
        );
    }
}
