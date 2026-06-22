<?php

namespace Tests\Feature\Sentinels;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\Ask;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * @FK-ID FK-033/FK-040 | @source reports/audit/TRACEABILITY_MATRIX_CAISSE_V1_2026-04-25.md | @fix-mission CV1-M09-BRANCH-ISOLATION | @reason branch_id=0/global OSS access must be reserved to global Admin only.
 */
class OssAdminBranchPolicySentinelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
    }

    public function test_branch_staff_cannot_request_global_oss_scope_but_global_admin_can(): void
    {
        $branch = Branch::factory()->create();
        $chef = User::factory()->create(['branch_id' => $branch->id]);
        $chef->assignRole('Chef');

        Order::factory()->create([
            'branch_id' => $branch->id,
            'order_type' => OrderType::KIOSK,
            'status' => OrderStatus::PREPARING,
            'queue_number' => 'A0001',
            'order_datetime' => now(),
            'is_advance_order' => Ask::NO,
        ]);

        $staffResponse = $this->actingAs($chef, 'sanctum')
            ->getJson('/api/admin/oss-order?branch_id=0');

        $staffResponse->assertStatus(403);

        $admin = User::factory()->create(['branch_id' => 0]);
        $admin->assignRole('Admin');

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/oss-order?branch_id=0')
            ->assertOk();
    }
}
