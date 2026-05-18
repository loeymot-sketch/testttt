<?php

namespace Tests\Feature\Sentinels;

use App\Models\Branch;
use App\Models\PosParkedOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [P0-POS-04 GOAL round-2 2026-05-18] Branch-isolation sentinel for
 * `ParkedOrderController::resolveOperatorContext`.
 *
 * Pre-fix, an Admin user with branch_id=0 (the org-wide "no branch" role)
 * would resolve to a context of (authId, 0) and the downstream service
 * `PosParkedOrderService::listForOperator($userId, 0)` could be invoked
 * with branch_id=0 — exposing every branch's parked orders to the Admin.
 *
 * Post-fix, the resolver asserts `branch_id > 0` and aborts 403 if not.
 * An Admin who wants to operate the POS register must do so from a
 * branch-scoped login (Branch Manager / POS Operator with branch_id > 0).
 *
 * Sentinel coverage:
 *   1. Admin with branch_id=0 calling index → 403
 *   2. Admin with branch_id=0 calling store → 403 + no row created
 *   3. Admin with branch_id=0 calling show → 403 (parent branch's order
 *      stays intact, no cross-branch leak)
 *   4. Admin with branch_id=0 calling destroy → 403 + parked order
 *      from another branch is NOT deleted
 *   5. POS Operator with branch_id > 0 still works (regression guard)
 */
class ParkedOrderAdminBranchZeroSentinelTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;
    private User $admin;            // branch_id = 0
    private User $branchOperator;   // branch_id > 0

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        $this->branch = Branch::factory()->create();

        $this->admin = User::factory()->create([
            'branch_id' => 0,
        ]);
        $this->admin->assignRole('Admin');

        $this->branchOperator = User::factory()->create([
            'branch_id' => $this->branch->id,
        ]);
        $this->branchOperator->assignRole('POS Operator');
    }

    public function test_admin_branch_zero_get_index_is_blocked_403(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/admin/pos/parked-orders');

        $response->assertStatus(403);
        $this->assertStringContainsString(
            'branch',
            (string) $response->json('message'),
            'Error message must mention branch isolation'
        );
    }

    public function test_admin_branch_zero_post_store_is_blocked_and_no_row_created(): void
    {
        $rowsBefore = PosParkedOrder::query()->count();

        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/admin/pos/parked-orders', [
                'payload'           => ['lists' => [['item_id' => 1, 'quantity' => 1]]],
                'label'             => 'Should not stick',
                'idempotency_token' => 'admin-blocked-1',
            ]);

        $response->assertStatus(403);
        $this->assertSame(
            $rowsBefore,
            PosParkedOrder::query()->count(),
            'No parked order row may be created when admin is blocked at 403'
        );
    }

    public function test_admin_branch_zero_cannot_recall_a_branch_parked_order(): void
    {
        $parkedOrder = PosParkedOrder::query()->create([
            'branch_id'         => $this->branch->id,
            'user_id'           => $this->branchOperator->id,
            'label'             => 'Branch-owned',
            'payload_json'      => ['lists' => [['item_id' => 1, 'quantity' => 1]]],
            'preview_total'     => 12.00,
            'items_count'       => 1,
            'idempotency_token' => 'branch-owned-1',
        ]);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/admin/pos/parked-orders/{$parkedOrder->id}");

        $response->assertStatus(403);

        // Cross-branch leak guard: parked order must still exist (not
        // returned to admin, not deleted by the failed attempt).
        $this->assertDatabaseHas('pos_parked_orders', [
            'id'        => $parkedOrder->id,
            'branch_id' => $this->branch->id,
        ]);
    }

    public function test_admin_branch_zero_cannot_destroy_a_branch_parked_order(): void
    {
        $parkedOrder = PosParkedOrder::query()->create([
            'branch_id'         => $this->branch->id,
            'user_id'           => $this->branchOperator->id,
            'label'             => 'Branch-owned',
            'payload_json'      => ['lists' => [['item_id' => 1, 'quantity' => 1]]],
            'preview_total'     => 12.00,
            'items_count'       => 1,
            'idempotency_token' => 'branch-owned-2',
        ]);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->deleteJson("/api/admin/pos/parked-orders/{$parkedOrder->id}");

        $response->assertStatus(403);

        // The parked order must NOT be deleted by the unauthorized attempt.
        $this->assertDatabaseHas('pos_parked_orders', [
            'id' => $parkedOrder->id,
        ]);
    }

    public function test_branch_operator_with_real_branch_still_works(): void
    {
        // Regression guard: the new abort_unless > 0 check must NOT
        // affect normal branch-scoped POS operators.
        $response = $this->actingAs($this->branchOperator, 'sanctum')
            ->postJson('/api/admin/pos/parked-orders', [
                'payload'           => ['lists' => [['item_id' => 1, 'quantity' => 1]]],
                'label'             => 'Regression',
                'idempotency_token' => 'regression-1',
            ]);

        $response->assertCreated();
        $this->assertDatabaseHas('pos_parked_orders', [
            'branch_id' => $this->branch->id,
            'user_id'   => $this->branchOperator->id,
            'label'     => 'Regression',
        ]);
    }
}
