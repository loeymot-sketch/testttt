<?php

/**
 * [GOAL_V1_LOCAL_GOLIVE W1.1 — CREDBAL-SEM-02 heal 2026-06-01]
 *
 * The store-credit liability report listed ALL users (Admin/Managers/POS Operators/
 * Chefs/Waiters/Delivery Boys), padding/confusing the register. It must list CUSTOMERS only.
 *
 * @group sentinel
 * @group reports
 */

namespace Tests\Feature\Reports;

use App\Enums\Role as EnumRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreditBalanceCustomersOnlySentinelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
    }

    public function test_credit_balance_report_lists_customers_only(): void
    {
        \Spatie\Permission\Models\Permission::findOrCreate('credit-balance-report', 'sanctum');
        $admin = User::factory()->create(['branch_id' => 0]);
        $admin->assignRole('Admin');
        $admin->givePermissionTo('credit-balance-report');
        $this->actingAs($admin, 'sanctum');

        $customer = User::factory()->create(['name' => 'Real Customer', 'balance' => 42.00]);
        $customer->assignRole('Customer');
        $posOp = User::factory()->create(['name' => 'POS Staff', 'balance' => 99.00]);
        $posOp->assignRole('POS Operator');
        $chef = User::factory()->create(['name' => 'Kitchen Chef', 'balance' => 77.00]);
        $chef->assignRole('Chef');

        $response = $this->getJson('/api/admin/credit-balance-report');
        $response->assertOk();

        $names = collect($response->json('data'))->pluck('name')->all();
        $this->assertContains('Real Customer', $names, 'Customer must appear in the credit-balance report.');
        $this->assertNotContains('POS Staff', $names, 'Staff must NOT appear in the customer store-credit report.');
        $this->assertNotContains('Kitchen Chef', $names, 'Staff must NOT appear in the customer store-credit report.');
    }
}
