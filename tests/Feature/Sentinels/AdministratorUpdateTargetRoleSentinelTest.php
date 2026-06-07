<?php

namespace Tests\Feature\Sentinels;

use App\Enums\Role as EnumRole;
use App\Models\User;
use App\Services\AdministratorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * @FK-ID CENTRAL-01 (parity with WAVE5-SEC-001 / UserMgmtRoleTargetSentinelTest)
 *
 * AdministratorService::update() was the LONE mutating method in that service
 * WITHOUT the assertTargetRole guard its siblings enforce (changePassword /
 * changeImage / show / destroy all gate on hasRole(ADMIN)). Without the guard a
 * Branch Manager with `administrators_edit` could PUT
 * /api/admin/administrator/{non_admin_id} and mutate a Customer/Chef/etc. through
 * the admin update path — an IDOR / cross-role type-confusion.
 *
 * The fix mirrors CustomerService::assertTargetRole exactly: a private
 * assertTargetRole() placed BEFORE update()'s try/catch that throws
 * HttpException(403, 'Cannot mutate user outside expected role.'). Placement
 * before the try is required so the 403 is not swallowed/rethrown as 422 by the
 * service's catch(Exception) block.
 *
 * This file lives outside the shared WAVE5-SEC-001 sentinel to keep the
 * CENTRAL-01 lane disjoint (scope-minimal — the existing sentinel stays at its
 * baseline). It carries the SAME explicit role-ID seeding the sibling sentinel
 * uses, because the guard relies on Spatie's hasRole(int) matching by primary
 * key (App\Enums\Role: Admin=1, Customer=2, ...).
 */
class AdministratorUpdateTargetRoleSentinelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Seed roles with EXPLICIT IDs aligned to App\Enums\Role — Spatie's
        // hasRole(int) matches by primary key, so we mirror the production
        // RoleTableSeeder ordering (identical to UserMgmtRoleTargetSentinelTest).
        $rows = [
            ['id' => EnumRole::ADMIN,        'name' => 'Admin'],
            ['id' => EnumRole::CUSTOMER,     'name' => 'Customer'],
            ['id' => EnumRole::DELIVERY_BOY, 'name' => 'Delivery Boy'],
            ['id' => EnumRole::WAITER,       'name' => 'Waiter'],
            ['id' => EnumRole::CHEF,         'name' => 'Chef'],
        ];
        foreach ($rows as $row) {
            Role::firstOrCreate(
                ['id' => $row['id']],
                ['name' => $row['name'], 'guard_name' => 'sanctum']
            );
        }
    }

    private function makeUserWithRole(int $roleId): User
    {
        $user = User::forceCreate([
            'name'              => 'Central01 User R' . $roleId . ' ' . uniqid(),
            'email'             => 'central01-' . uniqid() . '@sentinel.test',
            'username'          => 'central01_' . uniqid(),
            'password'          => bcrypt('secret-passwd'),
            'branch_id'         => 0,
            'email_verified_at' => now(),
            'status'            => 1,
        ]);
        $user->assignRole($roleId);
        return $user->fresh();
    }

    /** Sanity — confirms the role-ID/Spatie alignment the guard depends on. */
    public function test_sanity_role_seed_alignment(): void
    {
        $customer = $this->makeUserWithRole(EnumRole::CUSTOMER);
        $admin    = $this->makeUserWithRole(EnumRole::ADMIN);

        $this->assertTrue($admin->hasRole(EnumRole::ADMIN), 'Admin must hasRole(1)');
        $this->assertFalse($customer->hasRole(EnumRole::ADMIN), 'Customer must NOT hasRole(1)');
    }

    /**
     * THE GUARD (negative): a non-Admin target (a Customer) routed through the
     * admin update path must be rejected with 403 and NO mutation reaching the DB.
     */
    public function test_administrator_update_rejects_non_admin_target(): void
    {
        $customer = $this->makeUserWithRole(EnumRole::CUSTOMER);
        $originalName  = $customer->name;
        $originalEmail = $customer->email;

        $request = \App\Http\Requests\AdministratorRequest::createFromBase(
            \Symfony\Component\HttpFoundation\Request::create('/api/admin/administrator/' . $customer->id, 'PUT', [
                'name'         => 'Pivoted Admin Name',
                'email'        => 'pivoted-admin@evil.test',
                'phone'        => '+33000000009',
                'status'       => 1,
                'country_code' => 'FR',
                'branch_id'    => 0,
                'password'     => 'new-password-evil',
            ])
        );

        try {
            app(AdministratorService::class)->update($request, $customer);
            $this->fail('AdministratorService::update must throw on non-Admin target.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
            $this->assertStringContainsString('outside expected role', $e->getMessage());
        }

        // No mutation reached the DB — name/email unchanged, password intact.
        $fresh = $customer->fresh();
        $this->assertSame($originalName, $fresh->name, 'Non-Admin target name must NOT be mutated via admin update.');
        $this->assertSame($originalEmail, $fresh->email, 'Non-Admin target email must NOT be mutated via admin update.');
        $this->assertTrue(
            \Illuminate\Support\Facades\Hash::check('secret-passwd', $fresh->password),
            'Non-Admin target password must remain intact.'
        );
    }

    /** THE GUARD (happy path): a genuine Admin target is still mutable. */
    public function test_administrator_update_allows_admin_target(): void
    {
        $admin = $this->makeUserWithRole(EnumRole::ADMIN);

        $request = \App\Http\Requests\AdministratorRequest::createFromBase(
            \Symfony\Component\HttpFoundation\Request::create('/api/admin/administrator/' . $admin->id, 'PUT', [
                'name'         => 'Renamed Admin',
                'email'        => 'renamed-admin-' . uniqid() . '@valid.test',
                'phone'        => '+33222222222',
                'status'       => 1,
                'country_code' => 'FR',
                'branch_id'    => 0,
            ])
        );

        $result = app(AdministratorService::class)->update($request, $admin);
        $this->assertSame('Renamed Admin', $result->name);
    }
}
