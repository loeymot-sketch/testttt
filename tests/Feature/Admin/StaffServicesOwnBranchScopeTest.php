<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Services\ChefService;
use App\Services\DeliveryBoyService;
use App\Services\EmployeeService;
use App\Services\WaiterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * [GOAL_MGMT_TESTPLAN 2026-06-01 — USR-RBAC-02 cross-service] Own-branch scoping.
 *
 * EmployeeService was healed in the first pass; the audit (wf6dhhn09) found the SAME
 * request-supplied branch_id pattern in Chef/Waiter/DeliveryBoy services. The shared
 * EnforcesOwnBranchScope trait now forces a non-`settings` caller to its OWN branch on
 * all 4 staff-creation services (CustomerService hardcodes branch 0, exempt).
 *
 * @group sentinel
 * @group security
 */
class StaffServicesOwnBranchScopeTest extends TestCase
{
    use RefreshDatabase;

    private function services(): array
    {
        return [
            EmployeeService::class,
            ChefService::class,
            WaiterService::class,
            DeliveryBoyService::class,
        ];
    }

    public function test_non_settings_caller_is_forced_to_own_branch_on_all_staff_services(): void
    {
        $role = Role::firstOrCreate(['name' => 'mgr-branch', 'guard_name' => 'sanctum']);
        $caller = User::factory()->create(['branch_id' => 3]);
        $caller->assignRole($role); // no `settings` permission

        foreach ($this->services() as $svcClass) {
            $svc = $this->app->make($svcClass);
            $this->assertSame(
                3,
                $svc->effectiveBranchId($caller, 7),
                "{$svcClass}: a non-settings caller must be forced to its own branch (3), not the requested 7."
            );
        }
    }

    public function test_settings_holder_keeps_requested_branch_on_all_staff_services(): void
    {
        Permission::firstOrCreate(['name' => 'settings', 'guard_name' => 'sanctum']);
        $admin = Role::firstOrCreate(['name' => 'admin-branch', 'guard_name' => 'sanctum']);
        $admin->givePermissionTo('settings');
        $caller = User::factory()->create(['branch_id' => 0]);
        $caller->assignRole($admin);

        foreach ($this->services() as $svcClass) {
            $svc = $this->app->make($svcClass);
            $this->assertSame(
                7,
                $svc->effectiveBranchId($caller, 7),
                "{$svcClass}: a settings holder keeps cross-branch discretion (requested branch 7)."
            );
        }
    }
}
