<?php

namespace Tests\Feature\Sentinels;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * @FK-ID FK-033 | @source reports/audit/TRACEABILITY_MATRIX_CAISSE_V1_2026-04-25.md | @fix-mission CV1-M09-BRANCH-ISOLATION | @reason cross-branch order show must deny explicitly instead of returning foreign data or masking the policy.
 */
class OrderShowBranchGuardSentinelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
    }

    public function test_branch_staff_cannot_show_foreign_branch_order(): void
    {
        $branchA = Branch::factory()->create();
        $branchB = Branch::factory()->create();

        $staff = User::factory()->create(['branch_id' => $branchA->id]);
        $staff->assignRole('POS Operator');

        $foreignOrder = Order::factory()->create([
            'branch_id' => $branchB->id,
            'order_type' => OrderType::POS,
            'status' => OrderStatus::ACCEPT,
        ]);

        $response = $this->actingAs($staff, 'sanctum')
            ->getJson('/api/admin/pos-order/show/' . $foreignOrder->id);

        $response->assertStatus(403);
    }
}
