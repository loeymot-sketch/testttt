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
 * [D-1 HEAL 2026-07-24 · reports/audit-sync-gestion-2026-07-23/D-caisse-kds-findings.md §D-1]
 *
 * OrderStateMachine::allows() (FROZEN §7, lines 79-86) grants an Admin a
 * BLANKET terminal→ANY override: from CANCELED/REJECTED/RETURNED, hasRole('Admin')
 * returns true toward ANY target. That let an Admin RESURRECT a dead order —
 * CANCELED→DELIVERED, RETURNED→PREPARING — a real state-integrity hole (285 live
 * terminal orders). There is NO legitimate un-cancel/reopen flow in V1:
 * Order::restoring() is hard-disabled (app/Models/Order.php:157-165 — "To reopen
 * an order, create a new one").
 *
 * The fix is a NON-frozen guard in OrderService::changeStatus
 * (assertNotResurrectingTerminalOrder) that refuses a terminal→ACTIVE transition
 * for EVERY actor, mirroring the existing SealedOrderGuard defense on terminal
 * edges. Terminal→terminal stays out of scope (owned by SealedOrderGuard + the
 * reason gate). Legitimate forward edges and the DELIVERED→RETURNED refund edge
 * are unaffected — their `from` status is not terminal. Non-admins were already
 * blocked by allows(); this pins that they stay blocked.
 */
class TerminalOrderResurrectionGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
    }

    private function makeGlobalAdmin(): User
    {
        $admin = User::factory()->create(['branch_id' => 0]);
        $admin->assignRole('Admin'); // by NAME — hasRole('Admin') is the override key
        return $admin;
    }

    private function makeOrder(int $branchId, int $status, array $over = []): Order
    {
        // Status set EXPLICITLY (OrderFactory defaults diverge from OrderStatus enum).
        return Order::factory()->create(array_merge([
            'branch_id'      => $branchId,
            'order_type'     => OrderType::POS,
            'status'         => $status,
            'payment_status' => PaymentStatus::UNPAID,
            'total'          => 20.00,
        ], $over));
    }

    private function callChangeStatus(Order $order, int $to, ?string $reason = null): void
    {
        $request = new OrderStatusRequest();
        $payload = ['status' => $to];
        if ($reason !== null) {
            $payload['reason'] = $reason;
        }
        $request->merge($payload);
        app(OrderService::class)->changeStatus($order, $request, false);
    }

    /**
     * @test
     * @dataProvider terminalToActiveResurrections
     */
    public function admin_cannot_resurrect_a_terminal_order_into_an_active_status(int $from, int $to): void
    {
        $branch = Branch::factory()->create();
        Auth::setUser($this->makeGlobalAdmin());
        $order = $this->makeOrder($branch->id, $from);

        $threw = null;
        try {
            $this->callChangeStatus($order, $to);
        } catch (\Throwable $e) {
            $threw = $e;
        }

        $this->assertNotNull(
            $threw,
            "Terminal({$from})→active({$to}) resurrection MUST throw, even for an Admin."
        );
        $fresh = Order::withoutGlobalScopes()->find($order->id);
        $this->assertSame(
            $from,
            (int) $fresh->status,
            "A terminal order must NOT be resurrected to an active status (from={$from}, attempted to={$to})."
        );
    }

    public static function terminalToActiveResurrections(): array
    {
        return [
            'CANCELED→DELIVERED' => [OrderStatus::CANCELED, OrderStatus::DELIVERED],
            'RETURNED→PREPARING' => [OrderStatus::RETURNED, OrderStatus::PREPARING],
            'REJECTED→ACCEPT'    => [OrderStatus::REJECTED, OrderStatus::ACCEPT],
            'CANCELED→ACCEPT'    => [OrderStatus::CANCELED, OrderStatus::ACCEPT],
        ];
    }

    /** @test legit forward edge PENDING→ACCEPT is unaffected by the guard */
    public function legit_pending_to_accept_is_still_allowed(): void
    {
        $branch = Branch::factory()->create();
        Auth::setUser($this->makeGlobalAdmin());
        $order = $this->makeOrder($branch->id, OrderStatus::PENDING);

        $this->callChangeStatus($order, OrderStatus::ACCEPT);

        $fresh = Order::withoutGlobalScopes()->find($order->id);
        $this->assertSame(OrderStatus::ACCEPT, (int) $fresh->status);
    }

    /** @test legit forward edge ACCEPT→PREPARING is unaffected by the guard */
    public function legit_accept_to_preparing_is_still_allowed(): void
    {
        $branch = Branch::factory()->create();
        Auth::setUser($this->makeGlobalAdmin());
        $order = $this->makeOrder($branch->id, OrderStatus::ACCEPT);

        $this->callChangeStatus($order, OrderStatus::PREPARING);

        $fresh = Order::withoutGlobalScopes()->find($order->id);
        $this->assertSame(OrderStatus::PREPARING, (int) $fresh->status);
    }

    /**
     * @test legit DELIVERED→RETURNED (refund edge) is unaffected — `from` is not
     * terminal, so the guard is a no-op for it. UNPAID + no transaction keeps the
     * refund machinery a near no-op so the assertion isolates the guard.
     */
    public function legit_delivered_to_returned_refund_edge_is_still_allowed(): void
    {
        $branch = Branch::factory()->create();
        Auth::setUser($this->makeGlobalAdmin());
        $order = $this->makeOrder($branch->id, OrderStatus::DELIVERED);

        $this->callChangeStatus($order, OrderStatus::RETURNED, 'Retour client (test D-1)');

        $fresh = Order::withoutGlobalScopes()->find($order->id);
        $this->assertSame(
            OrderStatus::RETURNED,
            (int) $fresh->status,
            'DELIVERED→RETURNED refund edge must remain allowed (from is not terminal).'
        );
    }

    /** @test a non-admin was already blocked from terminal→active — it stays blocked */
    public function non_admin_terminal_to_active_stays_blocked(): void
    {
        $branch = Branch::factory()->create();
        $staff = User::factory()->create(['branch_id' => $branch->id]);
        $staff->assignRole('POS Operator');
        Auth::setUser($staff);
        $order = $this->makeOrder($branch->id, OrderStatus::CANCELED);

        $threw = null;
        try {
            $this->callChangeStatus($order, OrderStatus::ACCEPT);
        } catch (\Throwable $e) {
            $threw = $e;
        }

        $this->assertNotNull($threw, 'Non-admin terminal→active must stay blocked.');
        $fresh = Order::withoutGlobalScopes()->find($order->id);
        $this->assertSame(OrderStatus::CANCELED, (int) $fresh->status);
    }
}
