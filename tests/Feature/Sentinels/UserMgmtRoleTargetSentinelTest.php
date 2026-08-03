<?php

namespace Tests\Feature\Sentinels;

use App\Enums\Role as EnumRole;
use App\Models\User;
use App\Services\ChefService;
use App\Services\CustomerService;
use App\Services\DeliveryBoyService;
use App\Services\WaiterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * @FK-ID WAVE5-SEC-001
 * @source reports/execution/audit_2026-05-07/FINAL_REPORT_v3_WAVE5_CONSOLIDATED_2026-05-08.md §1.1
 *
 * Privilege escalation guard — Customer/Waiter/Chef/DeliveryBoy services were
 * relying on a tautological `if (!in_array(EnumRole::X, $blockRoles[ADMIN]))`
 * predicate that NEVER checked the route-bound user's actual role. A Branch
 * Manager with `customers_edit` could PUT /api/admin/customer/{admin_user_id}
 * with a fresh password and rotate any user's credentials cross-role.
 *
 * The fix adds a private `assertTargetRole()` BEFORE each service's try/catch
 * to throw HttpException(403). Because each service catches Exception and
 * rethrows via QueryExceptionLibrary, AND each controller catches Exception
 * and returns 422, the HTTP response is 422 (codebase-wide pattern). The
 * security guarantee is the TRANSACTION ROLLBACK — DB stays unchanged.
 *
 * 4 services × 3 mutating methods (update, changePassword, changeImage) = 12
 * negative cases + 4 happy-path cases = 16 sentinel cases.
 */
class UserMgmtRoleTargetSentinelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // [WAVE5-SEC-001 sentinel] Seed roles with EXPLICIT IDs aligned to
        // App\Enums\Role (Admin=1, Customer=2, DeliveryBoy=3, Waiter=4, Chef=5)
        // — Spatie's hasRole(int) matches by primary key, so we must mirror
        // the production RoleTableSeeder ordering here.
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
        $name = 'Sentinel User R' . $roleId . ' ' . uniqid();
        $user = User::forceCreate([
            'name'              => $name,
            'email'             => 'sec001-' . uniqid() . '@sentinel.test',
            'username'          => 'sec001_' . uniqid(),
            'password'          => bcrypt('secret-passwd'),
            'branch_id'         => 0,
            'email_verified_at' => now(),
            'status'            => 1,
        ]);
        $user->assignRole($roleId);
        return $user->fresh();
    }

    /**
     * Sanity check — confirms the role-ID/Spatie alignment we depend on works.
     */
    public function test_sanity_role_seed_alignment(): void
    {
        $customer = $this->makeUserWithRole(EnumRole::CUSTOMER);
        $admin    = $this->makeUserWithRole(EnumRole::ADMIN);

        $this->assertTrue($customer->hasRole(EnumRole::CUSTOMER), 'role seed misaligned: Customer should hasRole(2)');
        $this->assertFalse($customer->hasRole(EnumRole::ADMIN), 'cross-talk: Customer must not hasRole(1)');
        $this->assertTrue($admin->hasRole(EnumRole::ADMIN), 'role seed misaligned: Admin should hasRole(1)');
        $this->assertFalse($admin->hasRole(EnumRole::CUSTOMER), 'cross-talk: Admin must not hasRole(2)');
    }

    // ───────────────────────────────────────────────
    // CustomerService — Branch Manager can NOT pivot
    // a non-Customer (Admin) target via the customer
    // service. Update / changePassword / changeImage.
    // ───────────────────────────────────────────────

    public function test_customer_update_rejects_non_customer_target(): void
    {
        $admin = $this->makeUserWithRole(EnumRole::ADMIN);
        $originalEmail = $admin->email;

        $request = \App\Http\Requests\CustomerRequest::createFromBase(
            \Symfony\Component\HttpFoundation\Request::create('/api/admin/customer/' . $admin->id, 'PUT', [
                'name' => 'Pivoted Name',
                'email' => 'pivoted@evil.test',
                'phone' => '+33000000001',
                'status' => 1,
                'country_code' => 'FR',
                'password' => 'new-password-evil',
            ])
        );

        try {
            app(CustomerService::class)->update($request, $admin);
            $this->fail('CustomerService::update must throw on non-Customer target.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
            $this->assertStringContainsString('outside expected role', $e->getMessage());
        }

        // No mutation reached the DB.
        $this->assertSame($originalEmail, $admin->fresh()->email);
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('secret-passwd', $admin->fresh()->password));
    }

    public function test_customer_change_password_rejects_non_customer_target(): void
    {
        $admin = $this->makeUserWithRole(EnumRole::ADMIN);

        $request = \App\Http\Requests\UserChangePasswordRequest::createFromBase(
            \Symfony\Component\HttpFoundation\Request::create('/api/admin/customer/change-password/' . $admin->id, 'POST', [
                'password' => 'rotated-by-attacker',
            ])
        );

        try {
            app(CustomerService::class)->changePassword($request, $admin);
            $this->fail('CustomerService::changePassword must throw on non-Customer target.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('secret-passwd', $admin->fresh()->password),
            'Original Admin password must remain intact.');
    }

    public function test_customer_change_image_rejects_non_customer_target(): void
    {
        $admin = $this->makeUserWithRole(EnumRole::ADMIN);

        $request = \App\Http\Requests\ChangeImageRequest::createFromBase(
            \Symfony\Component\HttpFoundation\Request::create('/api/admin/customer/change-image/' . $admin->id, 'POST')
        );

        try {
            app(CustomerService::class)->changeImage($request, $admin);
            $this->fail('CustomerService::changeImage must throw on non-Customer target.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    public function test_customer_update_allows_customer_target(): void
    {
        $customer = $this->makeUserWithRole(EnumRole::CUSTOMER);

        $request = \App\Http\Requests\CustomerRequest::createFromBase(
            \Symfony\Component\HttpFoundation\Request::create('/api/admin/customer/' . $customer->id, 'PUT', [
                'name' => 'Renamed',
                'email' => 'renamed-' . uniqid() . '@valid.test',
                'phone' => '+33111111111',
                'status' => 1,
                'country_code' => 'FR',
            ])
        );

        $result = app(CustomerService::class)->update($request, $customer);
        $this->assertSame('Renamed', $result->name);
    }

    // ───────────────────────────────────────────────
    // WaiterService — symmetric pattern
    // ───────────────────────────────────────────────

    public function test_waiter_update_rejects_non_waiter_target(): void
    {
        $admin = $this->makeUserWithRole(EnumRole::ADMIN);
        $request = \App\Http\Requests\WaiterRequest::createFromBase(
            \Symfony\Component\HttpFoundation\Request::create('/api/admin/waiter/' . $admin->id, 'PUT', [
                'name' => 'Pivoted',
                'email' => 'pivoted@evil.test',
                'phone' => '+33000000002',
                'status' => 1,
                'country_code' => 'FR',
                'branch_id' => 1,
                'password' => 'new-password',
            ])
        );

        try {
            app(WaiterService::class)->update($request, $admin);
            $this->fail('WaiterService::update must throw on non-Waiter target.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('secret-passwd', $admin->fresh()->password));
    }

    public function test_waiter_change_password_rejects_non_waiter_target(): void
    {
        $customer = $this->makeUserWithRole(EnumRole::CUSTOMER);

        $request = \App\Http\Requests\UserChangePasswordRequest::createFromBase(
            \Symfony\Component\HttpFoundation\Request::create('/api/admin/waiter/change-password/' . $customer->id, 'POST', [
                'password' => 'rotated',
            ])
        );

        try {
            app(WaiterService::class)->changePassword($request, $customer);
            $this->fail('WaiterService::changePassword must throw on non-Waiter target.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('secret-passwd', $customer->fresh()->password));
    }

    public function test_waiter_change_image_rejects_non_waiter_target(): void
    {
        $admin = $this->makeUserWithRole(EnumRole::ADMIN);

        $request = \App\Http\Requests\ChangeImageRequest::createFromBase(
            \Symfony\Component\HttpFoundation\Request::create('/api/admin/waiter/change-image/' . $admin->id, 'POST')
        );

        try {
            app(WaiterService::class)->changeImage($request, $admin);
            $this->fail('WaiterService::changeImage must throw on non-Waiter target.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    // ───────────────────────────────────────────────
    // ChefService — symmetric pattern
    // ───────────────────────────────────────────────

    public function test_chef_update_rejects_non_chef_target(): void
    {
        $admin = $this->makeUserWithRole(EnumRole::ADMIN);
        $request = \App\Http\Requests\ChefRequest::createFromBase(
            \Symfony\Component\HttpFoundation\Request::create('/api/admin/chef/' . $admin->id, 'PUT', [
                'name' => 'Pivoted',
                'email' => 'pivoted-chef@evil.test',
                'phone' => '+33000000003',
                'status' => 1,
                'country_code' => 'FR',
                'branch_id' => 1,
                'password' => 'new-password',
            ])
        );

        try {
            app(ChefService::class)->update($request, $admin);
            $this->fail('ChefService::update must throw on non-Chef target.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('secret-passwd', $admin->fresh()->password));
    }

    public function test_chef_change_password_rejects_non_chef_target(): void
    {
        $waiter = $this->makeUserWithRole(EnumRole::WAITER);

        $request = \App\Http\Requests\UserChangePasswordRequest::createFromBase(
            \Symfony\Component\HttpFoundation\Request::create('/api/admin/chef/change-password/' . $waiter->id, 'POST', [
                'password' => 'rotated',
            ])
        );

        try {
            app(ChefService::class)->changePassword($request, $waiter);
            $this->fail('ChefService::changePassword must throw on non-Chef target.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('secret-passwd', $waiter->fresh()->password));
    }

    public function test_chef_change_image_rejects_non_chef_target(): void
    {
        $admin = $this->makeUserWithRole(EnumRole::ADMIN);

        $request = \App\Http\Requests\ChangeImageRequest::createFromBase(
            \Symfony\Component\HttpFoundation\Request::create('/api/admin/chef/change-image/' . $admin->id, 'POST')
        );

        try {
            app(ChefService::class)->changeImage($request, $admin);
            $this->fail('ChefService::changeImage must throw on non-Chef target.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    // ───────────────────────────────────────────────
    // DeliveryBoyService — symmetric pattern
    // ───────────────────────────────────────────────

    public function test_delivery_boy_update_rejects_non_delivery_boy_target(): void
    {
        $admin = $this->makeUserWithRole(EnumRole::ADMIN);
        $request = \App\Http\Requests\DeliveryBoyRequest::createFromBase(
            \Symfony\Component\HttpFoundation\Request::create('/api/admin/delivery-boy/' . $admin->id, 'PUT', [
                'name' => 'Pivoted',
                'email' => 'pivoted-db@evil.test',
                'phone' => '+33000000004',
                'status' => 1,
                'country_code' => 'FR',
                'branch_id' => 1,
                'password' => 'new-password',
            ])
        );

        try {
            app(DeliveryBoyService::class)->update($request, $admin);
            $this->fail('DeliveryBoyService::update must throw on non-DeliveryBoy target.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('secret-passwd', $admin->fresh()->password));
    }

    public function test_delivery_boy_change_password_rejects_non_delivery_boy_target(): void
    {
        $chef = $this->makeUserWithRole(EnumRole::CHEF);

        $request = \App\Http\Requests\UserChangePasswordRequest::createFromBase(
            \Symfony\Component\HttpFoundation\Request::create('/api/admin/delivery-boy/change-password/' . $chef->id, 'POST', [
                'password' => 'rotated',
            ])
        );

        try {
            app(DeliveryBoyService::class)->changePassword($request, $chef);
            $this->fail('DeliveryBoyService::changePassword must throw on non-DeliveryBoy target.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('secret-passwd', $chef->fresh()->password));
    }

    public function test_delivery_boy_change_image_rejects_non_delivery_boy_target(): void
    {
        $admin = $this->makeUserWithRole(EnumRole::ADMIN);

        $request = \App\Http\Requests\ChangeImageRequest::createFromBase(
            \Symfony\Component\HttpFoundation\Request::create('/api/admin/delivery-boy/change-image/' . $admin->id, 'POST')
        );

        try {
            app(DeliveryBoyService::class)->changeImage($request, $admin);
            $this->fail('DeliveryBoyService::changeImage must throw on non-DeliveryBoy target.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }
}
