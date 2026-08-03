<?php

namespace Tests\Feature\OrderHistory;

use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * [GOAL_MGMT_TESTPLAN Wave B / HIST-08 — security/branch-isolation, adversarial]
 *
 * Guards BranchScope isolation on the unified order-history DETAIL endpoint
 * GET /api/admin/order-history/show/{order} (OrderHistoryController::show).
 *
 * The controller deliberately loads the order WITHOUT the BranchScope global
 * scope (Order::withoutGlobalScope(BranchScope::class)->findOrFail) so the row
 * is always found, then enforces isolation explicitly:
 *   OrderHistoryController.php:85-89
 *     abort_unless(
 *         auth()->user()?->branch_id === 0 || $order->branch_id === auth()->user()?->branch_id,
 *         403, 'Cross-branch access denied'
 *     );
 * → admin (branch_id=0) bypasses; a branch operator may ONLY see their branch.
 * Cross-branch access therefore returns **403** (NOT the other branch's data,
 * NOT 404 — ModelNotFound is also unified to 403 to prevent enumeration).
 *
 * Adversarial intent: a genuine RED here = a real cross-branch data leak (P0/P1
 * security). The own-branch 200 control pins the cross-branch 403 to the
 * BranchScope check specifically (so a permission-gate 403 cannot create a
 * false GREEN).
 */
class OrderHistoryShowCrossBranch403Test extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch1;
    protected Branch $branch2;
    protected User $admin;            // branch_id=0 — bypasses BranchScope
    protected User $branch1Manager;   // branch_id=branch1 — scoped, has pos perms

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        $this->branch1 = Branch::factory()->create();
        $this->branch2 = Branch::factory()->create();

        // Admin: branch_id=0 → BranchScope bypass + Admin role (positive control).
        $this->admin = User::factory()->create([
            'branch_id' => 0,
            'password'  => Hash::make('pwd'),
        ]);
        $this->admin->assignRole('Admin');

        // Branch-1 operator WITH pos/pos-orders permission (Branch Manager role)
        // so the controller's permission gate (line 71) is cleared and the test
        // genuinely exercises the cross-branch isolation check (line 85), not the
        // permission gate. branch_id>0 → BranchScope scoped to branch 1 only.
        $this->branch1Manager = User::factory()->create([
            'branch_id' => $this->branch1->id,
            'password'  => Hash::make('pwd'),
        ]);
        $this->branch1Manager->assignRole('Branch Manager');
    }

    private function makeOrder(int $branchId): Order
    {
        return Order::factory()->create([
            'branch_id'      => $branchId,
            'order_type'     => OrderType::POS,
            'source_surface' => 'pos',
            'payment_status' => PaymentStatus::PAID,
            'total'          => 10.00,
        ]);
    }

    private function showUrl(int $orderId): string
    {
        return "/api/admin/order-history/show/{$orderId}";
    }

    /**
     * Discriminator control: a branch-1 manager CAN see their OWN branch order.
     * This proves the permission gate is cleared and the endpoint is reachable —
     * so the cross-branch 403 below is attributable to the BranchScope check,
     * not to the permission gate (which would be a false-GREEN security test).
     */
    public function test_branch1_manager_can_view_own_branch_order(): void
    {
        $ownOrder = $this->makeOrder($this->branch1->id);

        $this->actingAs($this->branch1Manager, 'sanctum');
        $res = $this->getJson($this->showUrl($ownOrder->id));

        $res->assertOk();
        $res->assertJsonPath('data.id', $ownOrder->id);
    }

    /**
     * THE ISOLATION GUARD. A branch-1 manager MUST NOT access a branch-2 order's
     * detail. Source enforces 403 'Cross-branch access denied'
     * (OrderHistoryController.php:85-89). A 200 here = real cross-branch leak.
     */
    public function test_branch1_manager_cannot_view_branch2_order_detail(): void
    {
        $foreignOrder = $this->makeOrder($this->branch2->id);

        $this->actingAs($this->branch1Manager, 'sanctum');
        $res = $this->getJson($this->showUrl($foreignOrder->id));

        // Protective status the app enforces = 403 (NOT 404, NOT 200).
        $res->assertForbidden();
        $res->assertStatus(403);

        // Hard negative: the branch-2 order's data MUST NOT leak in the body.
        $body = $res->getContent();
        $this->assertStringNotContainsString(
            (string) $foreignOrder->order_serial_no,
            $body,
            'Branch-2 order serial leaked to a branch-1 operator — cross-branch isolation breach.'
        );
        $this->assertNull(
            $res->json('data.id'),
            'Branch-2 order id leaked in detail payload — cross-branch isolation breach.'
        );
    }

    /**
     * Positive control: admin (branch_id=0) CAN see the branch-2 order detail,
     * and the data actually comes back (id matches) — proving the 403 above is
     * isolation, not a blanket lockout of the endpoint.
     */
    public function test_admin_can_view_branch2_order_detail(): void
    {
        $foreignOrder = $this->makeOrder($this->branch2->id);

        $this->actingAs($this->admin, 'sanctum');
        $res = $this->getJson($this->showUrl($foreignOrder->id));

        $res->assertOk();
        $res->assertJsonPath('data.id', $foreignOrder->id);
    }
}
