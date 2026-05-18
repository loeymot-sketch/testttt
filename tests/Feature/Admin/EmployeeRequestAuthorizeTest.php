<?php

namespace Tests\Feature\Admin;

use App\Enums\Status;
use App\Http\Requests\EmployeeRequest;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * [Wave 1 P1 W-2 / 2026-05-18] EmployeeRequest::authorize() defense-in-depth.
 *
 * Symmetric with Wave 5H fix on AdministratorRequest / CurrencyRequest /
 * TaxRequest / BranchRequest / RoleRequest. The Wave 1 architect audit
 * (`reports/audit/critical-focus-2026-05-18/wave-1/admin-architect.md` §W-2)
 * caught that EmployeeRequest was skipped by Wave 5H — `return true;`
 * unconditional. Controller-level middleware on EmployeeController
 * (employees_create / employees_edit) still gates the live route, but a
 * future route mis-wire bypassing that constructor gate would leave only
 * the FormRequest layer between the request and `users` table mutation.
 *
 * This test exercises authorize() directly (no HTTP round-trip) so the
 * FormRequest defense-in-depth contract is asserted in isolation from
 * the controller middleware (already covered elsewhere). Pattern mirrors
 * `tests/Feature/Frontend/OrderRequestKioskAbilityTest.php`.
 *
 * CLAUDE.md §9 multi-tenant + auth invariants.
 */
class EmployeeRequestAuthorizeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        // employees_* permissions are NOT covered by seedSpatieRoles() — seed here.
        foreach (['employees_create', 'employees_edit'] as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'sanctum']);
        }
    }

    /** @test */
    public function test_user_without_permission_is_denied(): void
    {
        $branch = Branch::factory()->create();
        $user = User::factory()->create([
            'branch_id' => $branch->id,
            'status' => Status::ACTIVE,
        ]);

        $request = EmployeeRequest::create('/api/admin/employee', 'POST');
        $request->setUserResolver(fn () => $user);

        $this->assertFalse(
            $request->authorize(),
            'User without employees_create or employees_edit permission must be denied.'
        );
    }

    /** @test */
    public function test_user_with_employees_create_is_allowed(): void
    {
        $branch = Branch::factory()->create();
        $user = User::factory()->create([
            'branch_id' => $branch->id,
            'status' => Status::ACTIVE,
        ]);
        $user->givePermissionTo('employees_create');

        $request = EmployeeRequest::create('/api/admin/employee', 'POST');
        $request->setUserResolver(fn () => $user);

        $this->assertTrue(
            $request->authorize(),
            'User with employees_create must pass authorize() (store verb).'
        );
    }

    /** @test */
    public function test_user_with_employees_edit_is_allowed(): void
    {
        $branch = Branch::factory()->create();
        $user = User::factory()->create([
            'branch_id' => $branch->id,
            'status' => Status::ACTIVE,
        ]);
        $user->givePermissionTo('employees_edit');

        $request = EmployeeRequest::create('/api/admin/employee/1', 'PUT');
        $request->setUserResolver(fn () => $user);

        $this->assertTrue(
            $request->authorize(),
            'User with employees_edit must pass authorize() (update verb).'
        );
    }

    /** @test */
    public function test_anonymous_request_is_denied(): void
    {
        $request = EmployeeRequest::create('/api/admin/employee', 'POST');
        $request->setUserResolver(fn () => null);

        $this->assertFalse(
            $request->authorize(),
            'Anonymous request (no user resolved) must be denied — no fail-open.'
        );
    }
}
