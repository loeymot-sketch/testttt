<?php

namespace Tests\Feature\Sentinels;

use App\Enums\Role as EnumRole;
use App\Models\Branch;
use App\Models\DeliveryBoyCashMovement;
use App\Models\DeliveryBoyCashSession;
use App\Models\User;
use App\Services\Delivery\DeliveryBoyCashSessionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * [V1.0.2 Sub-6.3 — 2026-05-18] Branch isolation sentinel — BranchScope enforcement.
 *
 * CLAUDE.md §9 invariant : DeliveryBoyCashSession + DeliveryBoyCashMovement must
 * apply BranchScope global scope. Cross-branch leak = NF525 + multi-tenant breach.
 *
 * Asserts :
 *   - Staff (branch_id > 0) acting on branch A cannot SEE sessions of branch B.
 *   - Staff acting on branch A cannot SEE movements of branch B sessions.
 *   - Admin (branch_id = 0) sees all branches (BranchScope::apply L33).
 *   - Explicit `withoutGlobalScopes()` bypass works as designed for service layer.
 */
class DeliveryBoyCashSessionBranchIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branchA;
    private Branch $branchB;
    private User $livreurA;
    private User $livreurB;
    private User $adminA;       // staff on branch A (branch_id = A)
    private User $globalAdmin;  // super admin (branch_id = 0)
    private DeliveryBoyCashSessionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
        Config::set('fiscal.audit_secret', 'test-fiscal-secret-'.str_repeat('a', 40));

        Role::firstOrCreate(
            ['id' => EnumRole::DELIVERY_BOY],
            ['name' => 'Delivery Boy', 'guard_name' => 'sanctum']
        );

        $this->branchA = Branch::factory()->create();
        $this->branchB = Branch::factory()->create();

        $this->livreurA = $this->makeUser($this->branchA->id, EnumRole::DELIVERY_BOY);
        $this->livreurB = $this->makeUser($this->branchB->id, EnumRole::DELIVERY_BOY);

        // Staff admin scoped to branch A only.
        $this->adminA = $this->makeUser($this->branchA->id, EnumRole::CUSTOMER);
        $this->adminA->assignRole('Branch Manager');

        // Global admin (branch_id = 0 → cross-branch per BranchScope L33 fix-54-8).
        $this->globalAdmin = $this->makeUser(0, EnumRole::CUSTOMER);
        $this->globalAdmin->assignRole('Admin');

        $this->service = app(DeliveryBoyCashSessionService::class);
    }

    private function makeUser(int $branchId, int $primaryRoleId): User
    {
        $user = User::forceCreate([
            'name'              => 'U-'.uniqid(),
            'email'             => 'u-'.uniqid().'@sentinel.test',
            'username'          => 'u_'.uniqid(),
            'password'          => bcrypt('secret-passwd'),
            'branch_id'         => $branchId,
            'email_verified_at' => now(),
            'status'            => 1,
        ]);
        if ($primaryRoleId === EnumRole::DELIVERY_BOY) {
            $user->assignRole(EnumRole::DELIVERY_BOY);
        }

        return $user->fresh();
    }

    public function test_branch_A_staff_cannot_see_branch_B_sessions(): void
    {
        // Seed one session per branch via the service (skipping auth).
        $sessionA = $this->service->openSession($this->branchA->id, $this->livreurA->id, 50.00, $this->livreurA->id);
        $sessionB = $this->service->openSession($this->branchB->id, $this->livreurB->id, 75.00, $this->livreurB->id);

        // Branch-A staff queries → should see only sessionA.
        $this->actingAs($this->adminA, 'sanctum');
        $visible = DeliveryBoyCashSession::query()->get();
        $this->assertCount(1, $visible, 'Branch-A staff must see ONLY their branch sessions.');
        $this->assertSame($sessionA->id, $visible->first()->id);

        // Cross-branch query for sessionB must return null (not found / scope-filtered).
        $crossLeak = DeliveryBoyCashSession::query()->find($sessionB->id);
        $this->assertNull(
            $crossLeak,
            "Branch-A staff MUST NOT see sessionB (id={$sessionB->id}) — BranchScope leak."
        );
    }

    public function test_branch_A_staff_cannot_see_branch_B_movements(): void
    {
        $sessionA = $this->service->openSession($this->branchA->id, $this->livreurA->id, 50.00, $this->livreurA->id);
        $sessionB = $this->service->openSession($this->branchB->id, $this->livreurB->id, 75.00, $this->livreurB->id);

        $this->service->recordMovement($sessionA->id, DeliveryBoyCashMovement::TYPE_ORDER_COLLECT, 10.00, DeliveryBoyCashMovement::DIRECTION_IN);
        $this->service->recordMovement($sessionB->id, DeliveryBoyCashMovement::TYPE_ORDER_COLLECT, 20.00, DeliveryBoyCashMovement::DIRECTION_IN);

        $this->actingAs($this->adminA, 'sanctum');
        $visibleMovements = DeliveryBoyCashMovement::query()->get();
        $this->assertCount(1, $visibleMovements, 'Branch-A staff must see ONLY their branch movements.');
        $this->assertSame((int) $this->branchA->id, (int) $visibleMovements->first()->branch_id);
    }

    public function test_global_admin_sees_all_branches(): void
    {
        $sessionA = $this->service->openSession($this->branchA->id, $this->livreurA->id, 50.00, $this->livreurA->id);
        $sessionB = $this->service->openSession($this->branchB->id, $this->livreurB->id, 75.00, $this->livreurB->id);

        $this->actingAs($this->globalAdmin, 'sanctum');
        $visible = DeliveryBoyCashSession::query()->get();
        $this->assertCount(2, $visible, 'Global admin (branch_id=0) must see all branches.');
        $ids = $visible->pluck('id')->sort()->values()->toArray();
        $this->assertSame([$sessionA->id, $sessionB->id], $ids);
    }

    public function test_withoutGlobalScopes_explicit_bypass_works_for_service_layer(): void
    {
        // Pattern : service layer queries inside DeliveryBoyCashSessionService use
        // withoutGlobalScopes() explicitly (branch enforcement passed via arg).
        $sessionA = $this->service->openSession($this->branchA->id, $this->livreurA->id, 50.00, $this->livreurA->id);
        $sessionB = $this->service->openSession($this->branchB->id, $this->livreurB->id, 75.00, $this->livreurB->id);

        $this->actingAs($this->adminA, 'sanctum');
        $all = DeliveryBoyCashSession::query()->withoutGlobalScopes()->get();
        $this->assertCount(2, $all, 'withoutGlobalScopes() must bypass BranchScope as designed.');
    }
}
