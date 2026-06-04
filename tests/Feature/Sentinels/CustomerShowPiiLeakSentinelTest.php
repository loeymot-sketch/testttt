<?php

namespace Tests\Feature\Sentinels;

use App\Enums\Role as EnumRole;
use App\Models\User;
use App\Services\CustomerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * @FK-ID S10-01 (read-side extension of WAVE5-SEC-001)
 *
 * CustomerService::show() relied on the tautological `!in_array(CUSTOMER,
 * blockRoles=[ADMIN])` predicate which NEVER checked the route-bound target's
 * role. The route binds `{customer}` to ANY `User` and the middleware only
 * checks the CALLER's `customers_show` permission — so a staffer with that
 * permission could GET /customer/show/{any_user_id} and read an admin's (or
 * any user's) PII. The mutating siblings (update/changePassword/changeImage,
 * destroy) were already guarded by `assertTargetRole()`; show() was missed.
 *
 * Fix: call `assertTargetRole()` in show() (read-side defense-in-depth).
 */
class CustomerShowPiiLeakSentinelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Mirror production role IDs (Spatie hasRole(int) matches by PK).
        foreach ([
            ['id' => EnumRole::ADMIN,    'name' => 'Admin'],
            ['id' => EnumRole::CUSTOMER, 'name' => 'Customer'],
        ] as $row) {
            Role::firstOrCreate(['id' => $row['id']], ['name' => $row['name'], 'guard_name' => 'sanctum']);
        }
    }

    private function makeUserWithRole(int $roleId): User
    {
        $user = User::forceCreate([
            'name'              => 'S1001 User R' . $roleId . ' ' . uniqid(),
            'email'             => 's1001-' . uniqid() . '@sentinel.test',
            'username'          => 's1001_' . uniqid(),
            'password'          => bcrypt('secret-passwd'),
            'branch_id'         => 0,
            'email_verified_at' => now(),
            'status'            => 1,
        ]);
        $user->assignRole($roleId);

        return $user->fresh();
    }

    public function test_show_rejects_non_customer_target_to_prevent_pii_leak(): void
    {
        $admin = $this->makeUserWithRole(EnumRole::ADMIN);

        $this->expectException(HttpException::class);

        app(CustomerService::class)->show($admin);
    }

    public function test_show_returns_a_genuine_customer(): void
    {
        $customer = $this->makeUserWithRole(EnumRole::CUSTOMER);

        $result = app(CustomerService::class)->show($customer);

        $this->assertSame($customer->id, $result->id);
    }
}
