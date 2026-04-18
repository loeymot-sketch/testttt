<?php

namespace Tests\Feature\Seeders;

use App\Enums\Role as EnumRole;
use Database\Seeders\PermissionTableSeeder;
use Database\Seeders\RolePermissionTableSeeder;
use Database\Seeders\RoleTableSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * [POS-9-H.1.2] F-A2 regression guard.
 *
 * Prior to the fix, `RolePermissionTableSeeder` used `->whereIn('name', [['name' => 'x']])`
 * which matched zero rows. Branch Manager / POS Operator / Chef ended up with no
 * permissions from this seeder (only Admin was granted via `Permission::all()`).
 *
 * This test runs the real production seeders (Role → Permission → RolePermission)
 * and asserts the expected role ↔ permission bindings, so a future accidental
 * refactor back to the broken shape fails CI loudly.
 */
class RolePermissionSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Do NOT call $this->seedSpatieRoles() here — that helper is a test-only
        // convenience that must stay in lock-step with the real seeders. The
        // point of this test is to verify the REAL seeders are correct.
        $this->seed(RoleTableSeeder::class);
        $this->seed(PermissionTableSeeder::class);
        $this->seed(RolePermissionTableSeeder::class);
    }

    public function test_branch_manager_receives_expected_permissions(): void
    {
        $role = Role::find(EnumRole::BRANCH_MANAGER);
        $this->assertNotNull($role, 'Branch Manager role must be seeded.');

        $names = $role->permissions->pluck('name')->all();

        // Representative keys that must be present after the fix.
        $this->assertContains('dashboard', $names);
        $this->assertContains('pos', $names);
        $this->assertContains('pos-orders', $names);
        $this->assertContains('pos-discount-up-to-10', $names);
        $this->assertContains('pos-discount-over-10-requires-manager', $names);
        $this->assertContains('pos-manage-fiscal', $names);
        $this->assertContains('pos-reopen-z', $names);
        $this->assertContains('sales-report', $names);

        // Sanity: at least 30 entries — the old buggy version produced 0.
        $this->assertGreaterThanOrEqual(30, count($names),
            'Branch Manager must receive its full permission list, got ' . count($names));
    }

    public function test_pos_operator_receives_expected_permissions(): void
    {
        $role = Role::find(EnumRole::POS_OPERATOR);
        $this->assertNotNull($role);

        $names = $role->permissions->pluck('name')->all();

        $this->assertContains('dashboard', $names);
        $this->assertContains('pos', $names);
        $this->assertContains('pos-orders', $names);
        $this->assertContains('pos-discount-up-to-10', $names);
        // KDS + OSS added in GAP-19-5 (second block — always worked).
        $this->assertContains('kitchen-display-system', $names);
        $this->assertContains('order-status-screen', $names);

        // POS Operator MUST NOT receive fiscal or over-10% discount permissions.
        $this->assertNotContains('pos-manage-fiscal', $names,
            'POS Operator must not be able to manage fiscal reports.');
        $this->assertNotContains('pos-reopen-z', $names,
            'POS Operator must not be able to reopen a Z report.');
        $this->assertNotContains('pos-discount-over-10-requires-manager', $names,
            'POS Operator must not bypass the manager-approval threshold.');
    }

    public function test_chef_receives_expected_permissions(): void
    {
        $role = Role::find(EnumRole::CHEF);
        $this->assertNotNull($role);

        $names = $role->permissions->pluck('name')->all();

        $this->assertContains('dashboard', $names);
        $this->assertContains('kitchen-display-system', $names);
        $this->assertContains('order-status-screen', $names);

        // Chef must not access POS / fiscal endpoints.
        $this->assertNotContains('pos-manage-fiscal', $names);
        $this->assertNotContains('pos', $names);
    }
}
