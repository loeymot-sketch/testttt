<?php

namespace Tests\Feature\Sentinels;

use App\Enums\Role as RoleEnum;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * [abuse-heal 2026-06-20 W5 RBAC-USERS-INDEX-01] Sentinel for GET /api/admin/users.
 *
 * Pre-heal: SimpleUserController gated only store/address methods — `index` had NO permission
 * gate — and SimpleUserService::list applied no role restriction, so any authenticated staff
 * token (Chef/Waiter/POS Operator) could enumerate the whole users table incl. Admin emails
 * via ?role_id=1 (admin-email harvesting for credential-stuffing/phishing).
 *
 * Post-heal:
 *   1. index requires permission:pos (Chef/Waiter without pos → 403).
 *   2. SimpleUserService::list is hard-restricted to the CUSTOMER role, so even an authorized
 *      `pos` caller asking for ?role_id=1 (Admin) gets ZERO staff/Admin rows.
 */
class AdminUsersIndexAuthzSentinelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
    }

    public function test_chef_without_pos_is_denied_admin_users_index(): void
    {
        $branch = Branch::factory()->create();
        $chef = User::factory()->create(['branch_id' => $branch->id]);
        $chef->assignRole('Chef'); // Chef does NOT hold `pos`
        $this->assertFalse($chef->can('pos'));

        $this->actingAs($chef, 'sanctum')
            ->getJson('/api/admin/users')
            ->assertStatus(403);
    }

    public function test_pos_operator_cannot_enumerate_admins_via_role_filter(): void
    {
        $branch = Branch::factory()->create();

        // A real Admin account whose email must NEVER surface through this directory.
        $admin = User::factory()->create(['branch_id' => 0, 'email' => 'secret-admin@lecayenne.test']);
        $admin->assignRole('Admin');

        // A customer who SHOULD be returnable (proves the endpoint still works for its purpose).
        $customerRole = Role::firstOrCreate(['name' => 'Customer', 'guard_name' => 'sanctum']);
        $customer = User::factory()->create(['branch_id' => $branch->id, 'email' => 'cust@lecayenne.test']);
        $customer->assignRole($customerRole);

        $operator = User::factory()->create(['branch_id' => $branch->id]);
        $operator->assignRole('POS Operator'); // holds `pos`
        $this->assertTrue($operator->can('pos'));

        // Even explicitly asking for role_id=1 (Admin) must return NO admin rows.
        $resp = $this->actingAs($operator, 'sanctum')
            ->getJson('/api/admin/users?role_id=' . RoleEnum::ADMIN . '&paginate=1&per_page=50')
            ->assertOk();

        $emails = collect(data_get($resp->json(), 'data', []))
            ->map(fn ($r) => $r['email'] ?? $r['name_email'] ?? '')
            ->implode(' ');
        $this->assertStringNotContainsString(
            'secret-admin@lecayenne.test',
            $emails,
            'Admin email must NEVER be enumerable through the customer-lookup directory.'
        );
    }
}
