<?php

namespace Tests\Feature;

use App\Models\ActionLog;
use App\Models\Branch;
use App\Models\User;
use App\Services\DashboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * [POS-9.1.4] action_logs.branch_id + DashboardService::auditTrail scope.
 *
 * - Every ActionLog row written by an authenticated user must carry branch_id.
 * - auditTrail() scopes to actor's branch when non-zero, cross-tenant leakage
 *   is forbidden. Admin (branch_id = 0) sees everything.
 */
class ActionLogBranchIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branchA;
    protected Branch $branchB;
    protected User $userA;
    protected User $userB;
    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        $this->branchA = Branch::factory()->create();
        $this->branchB = Branch::factory()->create();

        $this->userA = User::factory()->create(['branch_id' => $this->branchA->id, 'password' => Hash::make('pwd')]);
        $this->userA->assignRole('POS Operator');

        $this->userB = User::factory()->create(['branch_id' => $this->branchB->id, 'password' => Hash::make('pwd')]);
        $this->userB->assignRole('POS Operator');

        $this->admin = User::factory()->create(['branch_id' => 0, 'password' => Hash::make('pwd')]);
        $this->admin->assignRole('Admin');
    }

    public function test_creating_log_authenticated_auto_sets_branch_id(): void
    {
        $this->actingAs($this->userA, 'sanctum');

        $log = ActionLog::create([
            'user_id' => $this->userA->id,
            'action'  => 'test.action',
            'resource'=> 'Test #1',
        ]);

        $this->assertEquals($this->branchA->id, $log->fresh()->branch_id);
    }

    public function test_creating_log_unauthenticated_falls_back_to_user_branch(): void
    {
        $log = ActionLog::create([
            'user_id' => $this->userB->id,
            'action'  => 'system.action',
            'resource'=> 'System #1',
        ]);

        $this->assertEquals($this->branchB->id, $log->fresh()->branch_id);
    }

    /**
     * [GOAL-2026-05-29] DashboardService::auditTrail() reads the NF525 audit_logs
     * table (deliberately re-pointed from action_logs at GAP-FIX-02 / 52e015197,
     * 2026-05-25, + locked by AuditTrailUsesAuditLogSentinelTest). Seed via the real
     * AuditLogService so rows land in audit_logs with a valid hash chain. The legacy
     * "null branch" concept is the SYSTEM chain (branch_id=0): the service REJECTS
     * null, and branch_id=0 is admin-only in auditTrail() (the staff filter
     * where('branch_id', X>0) never matches 0) — preserving the exact F-A3 isolation
     * invariant these tests guard (staff-own-branch-only; admin sees all/system).
     */
    private function seedAudit(?int $userId, string $action, int $branchId): void
    {
        app(\App\Services\Fiscal\AuditLogService::class)->write([
            'user_id'   => $userId,
            'action'    => $action,
            'branch_id' => $branchId,
        ]);
    }

    public function test_audit_trail_scoped_to_actor_branch(): void
    {
        // 2 rows for branch A, 2 for branch B, 1 legacy/system (branch_id=0).
        $this->seedAudit($this->userA->id, 'A.1', $this->branchA->id);
        $this->seedAudit($this->userA->id, 'A.2', $this->branchA->id);
        $this->seedAudit($this->userB->id, 'B.1', $this->branchB->id);
        $this->seedAudit($this->userB->id, 'B.2', $this->branchB->id);
        $this->seedAudit(null, 'LEGACY.1', 0); // legacy/system chain = branch_id 0 (admin-only)

        $this->actingAs($this->userA, 'sanctum');
        $service = app(DashboardService::class);
        $trail = $service->auditTrail()->pluck('action')->all();

        $this->assertContains('A.1', $trail);
        $this->assertContains('A.2', $trail);
        $this->assertNotContains('B.1', $trail);
        $this->assertNotContains('B.2', $trail);
        // [POS-9-H.1.3] F-A3 fix: NULL-branch rows are legacy/system-only and
        // must NOT leak to branch-scoped staff. Only Admin sees them.
        $this->assertNotContains('LEGACY.1', $trail,
            'Branch staff must not see NULL-branch audit rows (F-A3 fix).');
    }

    public function test_audit_trail_admin_sees_null_branch_legacy_rows(): void
    {
        $this->seedAudit(null, 'LEGACY.1', 0); // legacy/system chain = branch_id 0
        $this->seedAudit($this->userA->id, 'A.1', $this->branchA->id);

        $this->actingAs($this->admin, 'sanctum');
        $service = app(DashboardService::class);
        $trail = $service->auditTrail()->pluck('action')->all();

        $this->assertContains('LEGACY.1', $trail,
            'Admin must still see legacy NULL-branch rows.');
        $this->assertContains('A.1', $trail);
    }

    public function test_admin_action_log_gets_branch_id_zero_not_null(): void
    {
        // [POS-9-H.1.3] Before the booted() is_null fix, Admin's branch_id=0 was
        // treated as "empty" and the row fell back to branch_id=NULL → cross-tenant leak.
        $this->actingAs($this->admin, 'sanctum');

        $log = ActionLog::create([
            'user_id' => $this->admin->id,
            'action'  => 'admin.ping',
            'resource'=> 'Admin #1',
        ]);

        $fresh = $log->fresh();
        $this->assertNotNull($fresh->branch_id,
            'Admin-authored row must carry a concrete branch_id (0), not NULL.');
        $this->assertSame(0, (int) $fresh->branch_id);
    }

    public function test_audit_trail_admin_sees_every_branch(): void
    {
        $this->seedAudit($this->userA->id, 'A.1', $this->branchA->id);
        $this->seedAudit($this->userB->id, 'B.1', $this->branchB->id);

        $this->actingAs($this->admin, 'sanctum');
        $service = app(DashboardService::class);
        $trail = $service->auditTrail()->pluck('action')->all();

        $this->assertContains('A.1', $trail);
        $this->assertContains('B.1', $trail);
    }
}
