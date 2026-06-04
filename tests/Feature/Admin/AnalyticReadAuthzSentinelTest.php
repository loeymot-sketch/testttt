<?php

/**
 * [GOAL_V1_LOCAL_GOLIVE W2.2 — REP-ANALYTIC-01 heal 2026-06-01]
 *
 * AnalyticController@index/@show (third-party tracking scripts) were ungated — any
 * authenticated admin-area token could read them. Consumer-check confirmed the only
 * caller is the Settings>Analytics page (settings-perm area), so reads are now gated
 * permission:settings (mirrors SET-01). Dashboard analytics widget uses DashboardService.
 *
 * @group sentinel
 * @group security
 */

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class AnalyticReadAuthzSentinelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
        Permission::findOrCreate('settings', 'sanctum');
    }

    public function test_analytic_index_requires_settings_permission(): void
    {
        $nonSettings = User::factory()->create(['branch_id' => 1]);
        $nonSettings->assignRole('POS Operator');
        $this->actingAs($nonSettings, 'sanctum')
            ->getJson('/api/admin/setting/analytic')
            ->assertForbidden();

        $settingsHolder = User::factory()->create(['branch_id' => 0]);
        $settingsHolder->givePermissionTo('settings');
        $resp = $this->actingAs($settingsHolder, 'sanctum')->getJson('/api/admin/setting/analytic');
        $this->assertNotSame(403, $resp->status(), 'settings-holder must NOT be forbidden from reading analytics.');
    }
}
