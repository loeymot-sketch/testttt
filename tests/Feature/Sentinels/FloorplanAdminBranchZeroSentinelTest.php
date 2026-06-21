<?php

namespace Tests\Feature\Sentinels;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [abuse-heal 2026-06-20 W1b W1B-FLOORPLAN-02] Branch-isolation sentinel for
 * `FloorplanController::resolveOperatorContext`.
 *
 * Parity with ParkedOrderAdminBranchZeroSentinelTest (P0-POS-04): the floorplan
 * is a per-branch cashier workflow, but FloorplanController lacked the
 * `branch_id > 0` guard its sibling ParkedOrderController enforces. An Admin
 * (branch_id=0) resolved to (authId, 0) and silently no-op'd every floorplan
 * query/mutation against branch_id=0 (empty view, no assign/release/transfer).
 * Post-fix the resolver aborts 403, making the failure explicit and V2-safe.
 *
 * Sentinel coverage:
 *   1. Admin branch_id=0 GET /floorplan/state            → 403
 *   2. Admin branch_id=0 POST /floorplan/{id}/assign     → 403
 *   3. POS Operator branch_id > 0 GET /floorplan/state   → 200 (regression guard)
 */
class FloorplanAdminBranchZeroSentinelTest extends TestCase
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

        $this->admin = User::factory()->create(['branch_id' => 0]);
        $this->admin->assignRole('Admin');

        $this->branchOperator = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->branchOperator->assignRole('POS Operator');
    }

    public function test_admin_branch_zero_get_state_is_blocked_403(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/admin/pos/floorplan/state');

        $response->assertStatus(403);
        $this->assertStringContainsString(
            'branch',
            (string) $response->json('message'),
            'Error message must mention branch isolation'
        );
    }

    public function test_admin_branch_zero_post_assign_is_blocked_403(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/admin/pos/floorplan/1/assign', ['order_id' => 1]);

        $response->assertStatus(403);
    }

    public function test_branch_operator_with_real_branch_can_read_floorplan(): void
    {
        // Regression guard: the new abort_unless > 0 check must NOT affect a
        // normal branch-scoped POS operator reading the floorplan.
        $response = $this->actingAs($this->branchOperator, 'sanctum')
            ->getJson('/api/admin/pos/floorplan/state');

        $response->assertStatus(200);
    }
}
