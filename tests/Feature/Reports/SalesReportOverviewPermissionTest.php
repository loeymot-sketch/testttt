<?php

namespace Tests\Feature\Reports;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * @FK-ID CDASH-01 (P1, ultra-audit 2026-06-10) | @plan plans/GOAL_ULTRA_AUDIT_SYSTEMES_2026-06-10.md LOT B
 *
 * GET /api/admin/sales-report/overview returns the global revenue aggregate.
 * The REP-AUTHZ-01 heal (2026-06-01) gated it with
 * `->only(..., 'overview')` — but the dispatched method name is
 * `salesReportOverview` (routes/api.php:1121), so the middleware never
 * matched and ANY authenticated staff (e.g. a cashier with zero report
 * permission) could read total CA. This test pins the corrected gate.
 */
class SalesReportOverviewPermissionTest extends TestCase
{
    use RefreshDatabase;

    public function test_cashier_without_permission_gets_403_on_overview(): void
    {
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        $branch  = Branch::factory()->create();
        $cashier = User::factory()->create(['branch_id' => $branch->id]);
        $cashier->assignRole('POS Operator');

        $this->actingAs($cashier, 'sanctum')
            ->getJson('/api/admin/sales-report/overview')
            ->assertStatus(403);
    }

    public function test_admin_with_permission_still_passes(): void
    {
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        \Spatie\Permission\Models\Permission::firstOrCreate([
            'name'       => 'sales-report',
            'guard_name' => 'sanctum',
        ]);

        $admin = User::factory()->create(['branch_id' => 0]);
        $admin->assignRole('Admin');
        $admin->givePermissionTo('sales-report');

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/sales-report/overview')
            ->assertSuccessful();
    }
}
