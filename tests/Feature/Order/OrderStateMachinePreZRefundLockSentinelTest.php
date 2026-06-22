<?php

namespace Tests\Feature\Order;

use App\Domain\Order\OrderStateMachine;
use App\Enums\OrderStatus;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * [LOCK-OSM-PREZ-REFUND 2026-06-04] Blast-radius control sentinel.
 *
 * Pins the EXACT behavior change of the owner-gated frozen edit to
 * OrderStateMachine::allows() — 3 additive, `pos-refund`-gated guards that
 * permit a refund-capable user to RETURN a not-yet-delivered pre-Z order from
 * ACCEPT / PREPARING / PREPARED. Per the LOCK doc §"Blast-radius control":
 *
 *   1. A user WITHOUT `pos-refund` CANNOT do ACCEPT/PREPARING/PREPARED →
 *      RETURNED (the new edges are permission-scoped, closed for regular POS
 *      operators / kiosk tokens / customers).
 *   2. A user WITH `pos-refund` CAN do those 3 edges (the refund edge).
 *   3. EVERY pre-existing transition is byte-for-byte unchanged — zero
 *      collateral behavior change.
 *
 * The sentinel calls OrderStateMachine::allows() DIRECTLY (no HTTP context), so
 * permission resolution happens against the model's default guard. The
 * `pos-refund` permission is created on the `sanctum` guard (matching
 * production) and granted explicitly, exactly mirroring the endpoint test's
 * helper, so hasPermissionTo('pos-refund') resolves true/false (never throws
 * PermissionDoesNotExist).
 */
class OrderStateMachinePreZRefundLockSentinelTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();

        // The new edges are gated on this permission; it must EXIST so the
        // "without" actor resolves to false instead of throwing.
        Permission::firstOrCreate(['name' => 'pos-refund', 'guard_name' => 'sanctum']);
        Permission::firstOrCreate(['name' => 'pos', 'guard_name' => 'sanctum']);

        $this->branch = Branch::factory()->create();
    }

    /** Holds `pos-refund` (Admin / Branch Manager production grant). */
    private function userWithRefund(): User
    {
        $user = User::factory()->create(['branch_id' => $this->branch->id]);
        $user->assignRole('Admin');
        $user->givePermissionTo('pos-refund');
        return $user;
    }

    /** Plain POS Operator: has `pos` but NOT `pos-refund`. */
    private function userWithoutRefund(): User
    {
        $user = User::factory()->create(['branch_id' => $this->branch->id]);
        $user->assignRole('POS Operator');
        $user->givePermissionTo('pos');
        return $user;
    }

    /** Holds `pos` (the POS shortcut gate for ACCEPT/PREPARING → DELIVERED). */
    private function userWithPos(): User
    {
        $user = User::factory()->create(['branch_id' => $this->branch->id]);
        $user->assignRole('POS Operator');
        $user->givePermissionTo('pos');
        return $user;
    }

    /** Admin role holder (for the CANCELED/REJECTED/RETURNED undo override). */
    private function adminUser(): User
    {
        $user = User::factory()->create(['branch_id' => $this->branch->id]);
        $user->assignRole('Admin');
        return $user;
    }

    // ─────────────────────────────────────────────────────────────────────
    // 1. WITHOUT pos-refund → the 3 new edges are STILL illegal.
    // ─────────────────────────────────────────────────────────────────────

    public function test_user_without_pos_refund_cannot_return_from_accept(): void
    {
        $u = $this->userWithoutRefund();
        $this->assertFalse(OrderStateMachine::allows(OrderStatus::ACCEPT, OrderStatus::RETURNED, $u));
    }

    public function test_user_without_pos_refund_cannot_return_from_preparing(): void
    {
        $u = $this->userWithoutRefund();
        $this->assertFalse(OrderStateMachine::allows(OrderStatus::PREPARING, OrderStatus::RETURNED, $u));
    }

    public function test_user_without_pos_refund_cannot_return_from_prepared(): void
    {
        $u = $this->userWithoutRefund();
        $this->assertFalse(OrderStateMachine::allows(OrderStatus::PREPARED, OrderStatus::RETURNED, $u));
    }

    /** A null/unauthenticated actor also cannot take the new edges. */
    public function test_null_user_cannot_return_from_kitchen_release_statuses(): void
    {
        $this->assertFalse(OrderStateMachine::allows(OrderStatus::ACCEPT, OrderStatus::RETURNED, null));
        $this->assertFalse(OrderStateMachine::allows(OrderStatus::PREPARING, OrderStatus::RETURNED, null));
        $this->assertFalse(OrderStateMachine::allows(OrderStatus::PREPARED, OrderStatus::RETURNED, null));
    }

    // ─────────────────────────────────────────────────────────────────────
    // 2. WITH pos-refund → the 3 new edges ARE legal.
    // ─────────────────────────────────────────────────────────────────────

    public function test_user_with_pos_refund_can_return_from_accept(): void
    {
        $u = $this->userWithRefund();
        $this->assertTrue(OrderStateMachine::allows(OrderStatus::ACCEPT, OrderStatus::RETURNED, $u));
    }

    public function test_user_with_pos_refund_can_return_from_preparing(): void
    {
        $u = $this->userWithRefund();
        $this->assertTrue(OrderStateMachine::allows(OrderStatus::PREPARING, OrderStatus::RETURNED, $u));
    }

    public function test_user_with_pos_refund_can_return_from_prepared(): void
    {
        $u = $this->userWithRefund();
        $this->assertTrue(OrderStateMachine::allows(OrderStatus::PREPARED, OrderStatus::RETURNED, $u));
    }

    // ─────────────────────────────────────────────────────────────────────
    // 3. EVERY pre-existing transition is UNCHANGED (zero collateral).
    // ─────────────────────────────────────────────────────────────────────

    public function test_preexisting_transitions_unchanged(): void
    {
        $withRefund = $this->userWithRefund();
        $withPos    = $this->userWithPos();
        $admin      = $this->adminUser();

        // ACCEPT → PREPARING / CANCELED (no permission needed).
        $this->assertTrue(OrderStateMachine::allows(OrderStatus::ACCEPT, OrderStatus::PREPARING, null));
        $this->assertTrue(OrderStateMachine::allows(OrderStatus::ACCEPT, OrderStatus::CANCELED, null));

        // PREPARING → PREPARED / CANCELED.
        $this->assertTrue(OrderStateMachine::allows(OrderStatus::PREPARING, OrderStatus::PREPARED, null));
        $this->assertTrue(OrderStateMachine::allows(OrderStatus::PREPARING, OrderStatus::CANCELED, null));

        // PREPARED → OUT_FOR_DELIVERY / DELIVERED.
        $this->assertTrue(OrderStateMachine::allows(OrderStatus::PREPARED, OrderStatus::OUT_FOR_DELIVERY, null));
        $this->assertTrue(OrderStateMachine::allows(OrderStatus::PREPARED, OrderStatus::DELIVERED, null));

        // DELIVERED → RETURNED (unconditional — the always-legal refund edge).
        $this->assertTrue(OrderStateMachine::allows(OrderStatus::DELIVERED, OrderStatus::RETURNED, null));
        $this->assertTrue(OrderStateMachine::allows(OrderStatus::DELIVERED, OrderStatus::RETURNED, $withRefund));

        // OUT_FOR_DELIVERY → DELIVERED.
        $this->assertTrue(OrderStateMachine::allows(OrderStatus::OUT_FOR_DELIVERY, OrderStatus::DELIVERED, null));

        // PENDING → ACCEPT / CANCELED / REJECTED.
        $this->assertTrue(OrderStateMachine::allows(OrderStatus::PENDING, OrderStatus::ACCEPT, null));
        $this->assertTrue(OrderStateMachine::allows(OrderStatus::PENDING, OrderStatus::CANCELED, null));
        $this->assertTrue(OrderStateMachine::allows(OrderStatus::PENDING, OrderStatus::REJECTED, null));

        // The `pos`-gated ACCEPT/PREPARING → DELIVERED shortcut (needs `pos`).
        $this->assertTrue(OrderStateMachine::allows(OrderStatus::ACCEPT, OrderStatus::DELIVERED, $withPos));
        $this->assertTrue(OrderStateMachine::allows(OrderStatus::PREPARING, OrderStatus::DELIVERED, $withPos));
        // …and is STILL illegal without `pos` (a `pos-refund`-only user can't skip to DELIVERED).
        $this->assertFalse(OrderStateMachine::allows(OrderStatus::ACCEPT, OrderStatus::DELIVERED, null));
        $this->assertFalse(OrderStateMachine::allows(OrderStatus::PREPARING, OrderStatus::DELIVERED, null));

        // Admin undo override from terminal CANCELED / REJECTED / RETURNED.
        $this->assertTrue(OrderStateMachine::allows(OrderStatus::CANCELED, OrderStatus::ACCEPT, $admin));
        $this->assertTrue(OrderStateMachine::allows(OrderStatus::REJECTED, OrderStatus::ACCEPT, $admin));
        $this->assertTrue(OrderStateMachine::allows(OrderStatus::RETURNED, OrderStatus::ACCEPT, $admin));
        // …but NOT for a non-Admin (even one holding pos-refund).
        $this->assertFalse(OrderStateMachine::allows(OrderStatus::CANCELED, OrderStatus::ACCEPT, $withPos));

        // A random illegal transition stays illegal.
        $this->assertFalse(OrderStateMachine::allows(OrderStatus::ACCEPT, OrderStatus::OUT_FOR_DELIVERY, null));
        $this->assertFalse(OrderStateMachine::allows(OrderStatus::ACCEPT, OrderStatus::OUT_FOR_DELIVERY, $withRefund));
        $this->assertFalse(OrderStateMachine::allows(OrderStatus::PENDING, OrderStatus::DELIVERED, $withPos));
    }
}
