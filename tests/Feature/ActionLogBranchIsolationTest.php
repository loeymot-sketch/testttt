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

    public function test_audit_trail_scoped_to_actor_branch(): void
    {
        // 2 rows for branch A, 2 for branch B, 1 legacy (null branch)
        ActionLog::create(['user_id' => $this->userA->id, 'action' => 'A.1', 'branch_id' => $this->branchA->id]);
        ActionLog::create(['user_id' => $this->userA->id, 'action' => 'A.2', 'branch_id' => $this->branchA->id]);
        ActionLog::create(['user_id' => $this->userB->id, 'action' => 'B.1', 'branch_id' => $this->branchB->id]);
        ActionLog::create(['user_id' => $this->userB->id, 'action' => 'B.2', 'branch_id' => $this->branchB->id]);
        ActionLog::create(['user_id' => null, 'action' => 'LEGACY.1', 'branch_id' => null]);

        $this->actingAs($this->userA, 'sanctum');
        $service = app(DashboardService::class);
        $trail = $service->auditTrail()->pluck('action')->all();

        $this->assertContains('A.1', $trail);
        $this->assertContains('A.2', $trail);
        $this->assertContains('LEGACY.1', $trail);
        $this->assertNotContains('B.1', $trail);
        $this->assertNotContains('B.2', $trail);
    }

    public function test_audit_trail_admin_sees_every_branch(): void
    {
        ActionLog::create(['user_id' => $this->userA->id, 'action' => 'A.1', 'branch_id' => $this->branchA->id]);
        ActionLog::create(['user_id' => $this->userB->id, 'action' => 'B.1', 'branch_id' => $this->branchB->id]);

        $this->actingAs($this->admin, 'sanctum');
        $service = app(DashboardService::class);
        $trail = $service->auditTrail()->pluck('action')->all();

        $this->assertContains('A.1', $trail);
        $this->assertContains('B.1', $trail);
    }
}
