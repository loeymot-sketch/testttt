<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Services\EmployeeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * [GOAL_MGMT_TESTPLAN 2026-06-01 — USR-RBAC-01 heal] Role-grant privilege escalation.
 *
 * Adversarial audit (wf6dhhn09) found EmployeeService::store/update only blocked the 5
 * core roles then `assignRole($role_id)` with NO caller-entitlement check — a Branch
 * Manager (employees_create, no `settings`) could mint another Branch Manager / POS
 * Operator, propagating pos-refund / pos-manage-fiscal / pos-reopen-z it cannot delegate.
 *
 * Owner policy (2026-06-01, "défauts sûrs"): a `settings` holder may grant any non-core
 * role; a NON-settings staff may grant ONLY a role whose permission set is a STRICT SUBSET
 * of its own (blocks escalation AND peer-cloning). Asserted on EmployeeService::callerMayGrantRole().
 *
 * @group sentinel
 * @group security
 */
class EmployeeRoleGrantEntitlementTest extends TestCase
{
    use RefreshDatabase;

    private function perm(string $n): Permission
    {
        return Permission::firstOrCreate(['name' => $n, 'guard_name' => 'sanctum']);
    }

    private function role(string $n, array $perms): Role
    {
        $r = Role::firstOrCreate(['name' => $n, 'guard_name' => 'sanctum']);
        $r->syncPermissions(collect($perms)->map(fn ($p) => $this->perm($p))->all());
        return $r;
    }

    private function service(): EmployeeService
    {
        return $this->app->make(EmployeeService::class);
    }

    public function test_non_settings_manager_cannot_grant_a_peer_role(): void
    {
        $mgr  = $this->role('mgr-a', ['perm.a', 'perm.b', 'perm.c']);
        $peer = $this->role('peer-a', ['perm.a', 'perm.b', 'perm.c']); // == caller
        $caller = User::factory()->create();
        $caller->assignRole($mgr);

        $this->assertFalse(
            $this->service()->callerMayGrantRole($caller, $peer->id),
            'A non-settings manager must NOT grant a peer role (same perm set = privilege clone).'
        );
    }

    public function test_non_settings_manager_cannot_grant_an_escalated_role(): void
    {
        $mgr = $this->role('mgr-b', ['perm.a', 'perm.b']);
        $up  = $this->role('up-b', ['perm.a', 'perm.b', 'perm.c']); // has a perm caller lacks
        $caller = User::factory()->create();
        $caller->assignRole($mgr);

        $this->assertFalse(
            $this->service()->callerMayGrantRole($caller, $up->id),
            'A non-settings manager must NOT grant a role with a permission it does not hold (escalation).'
        );
    }

    public function test_non_settings_manager_can_grant_strictly_subordinate_role(): void
    {
        $mgr = $this->role('mgr-c', ['perm.a', 'perm.b', 'perm.c']);
        $sub = $this->role('sub-c', ['perm.a', 'perm.b']); // strict subset
        $caller = User::factory()->create();
        $caller->assignRole($mgr);

        $this->assertTrue(
            $this->service()->callerMayGrantRole($caller, $sub->id),
            'A non-settings manager MUST be able to grant a strictly-subordinate role (hire staff).'
        );
    }

    public function test_settings_holder_may_grant_any_role(): void
    {
        $this->perm('settings');
        $admin = $this->role('admin-d', ['settings', 'perm.a']);
        $any   = $this->role('any-d', ['perm.a', 'perm.b', 'perm.c', 'perm.x']); // superset — still allowed for settings holder
        $caller = User::factory()->create();
        $caller->assignRole($admin);

        $this->assertTrue(
            $this->service()->callerMayGrantRole($caller, $any->id),
            'A settings holder (admin) may grant any non-core role at discretion.'
        );
    }

    public function test_anonymous_caller_cannot_grant(): void
    {
        $r = $this->role('r-e', ['perm.a']);
        $this->assertFalse(
            $this->service()->callerMayGrantRole(null, $r->id),
            'No caller resolved → no grant (fail-closed).'
        );
    }

    // ── USR-RBAC-02: non-settings caller forced to own branch ──

    public function test_non_settings_caller_is_forced_to_own_branch(): void
    {
        $mgr = $this->role('mgr-br', ['perm.a']); // no `settings`
        $caller = User::factory()->create(['branch_id' => 1]);
        $caller->assignRole($mgr);

        $this->assertSame(
            1,
            $this->service()->effectiveBranchId($caller, 2),
            'A non-settings caller must be forced to its own branch (cannot assign cross-branch).'
        );
    }

    public function test_settings_holder_keeps_requested_branch(): void
    {
        $this->perm('settings');
        $admin = $this->role('admin-br', ['settings']);
        $caller = User::factory()->create(['branch_id' => 0]);
        $caller->assignRole($admin);

        $this->assertSame(
            2,
            $this->service()->effectiveBranchId($caller, 2),
            'A settings holder (admin) keeps cross-branch discretion via the requested branch_id.'
        );
    }
}
