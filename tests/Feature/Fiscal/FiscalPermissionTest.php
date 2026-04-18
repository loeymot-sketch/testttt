<?php

namespace Tests\Feature\Fiscal;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * [POS-9.4.12 / POS-GA-F-38]
 *
 * Permissions gating for fiscal operations:
 *  - `pos-manage-fiscal` is required for every Z/X endpoint.
 *  - `pos-reopen-z` is the *separate* permission that, in a future wave,
 *    will gate a future reopen endpoint; the permission must exist and
 *    be granted to Branch Manager + Admin only.
 *  - POS Operator must NOT carry either fiscal permission by default.
 */
class FiscalPermissionTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
        Config::set('fiscal.z_report_secret', 'unit-test-z-secret');

        $this->branch = Branch::factory()->create();
    }

    private function makeUser(?string $role = null): User
    {
        $u = User::factory()->create([
            'branch_id' => $this->branch->id,
            'password'  => Hash::make('pwd'),
        ]);
        if ($role !== null) {
            $u->assignRole($role);
        }
        return $u;
    }

    public function test_pos_operator_cannot_manage_fiscal(): void
    {
        $u = $this->makeUser('POS Operator');
        $this->assertFalse($u->can('pos-manage-fiscal'),
            'POS Operator must NOT carry pos-manage-fiscal.');
        $this->assertFalse($u->can('pos-reopen-z'),
            'POS Operator must NOT carry pos-reopen-z.');

        $this->actingAs($u, 'sanctum');
        $this->withHeader('x-api-key', config('app.api_key'))
            ->postJson('/api/admin/fiscal/z-report/open')
            ->assertStatus(403);
    }

    public function test_branch_manager_can_manage_fiscal(): void
    {
        $u = $this->makeUser('Branch Manager');
        $this->assertTrue($u->can('pos-manage-fiscal'),
            'Branch Manager must carry pos-manage-fiscal (NF525 daily close is a manager duty).');
        $this->assertTrue($u->can('pos-reopen-z'),
            'Branch Manager must carry pos-reopen-z (with audit log on use).');

        $this->actingAs($u, 'sanctum');
        $this->withHeader('x-api-key', config('app.api_key'))
            ->postJson('/api/admin/fiscal/z-report/open')
            ->assertStatus(201);
    }

    public function test_unrooled_user_has_no_fiscal_permission(): void
    {
        $u = $this->makeUser(null);
        $this->assertFalse($u->can('pos-manage-fiscal'));
        $this->assertFalse($u->can('pos-reopen-z'));
    }
}
