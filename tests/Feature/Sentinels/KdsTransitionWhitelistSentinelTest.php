<?php

namespace Tests\Feature\Sentinels;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * @FK-ID FK-037 | @source reports/audit/TRACEABILITY_MATRIX_CAISSE_V1_2026-04-25.md | @fix-mission CV1-M07-KDS-RELEASE | @reason KDS endpoints must whitelist kitchen transitions and reject CANCELED from KDS.
 */
class KdsTransitionWhitelistSentinelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
    }

    public function test_kds_cannot_cancel_order_from_kitchen_screen(): void
    {
        $branch = Branch::factory()->create();
        $chef = User::factory()->create(['branch_id' => $branch->id]);
        $chef->assignRole('Chef');

        $order = Order::factory()->create([
            'branch_id' => $branch->id,
            'order_type' => OrderType::POS,
            'payment_status' => PaymentStatus::PAID,
            'status' => OrderStatus::PREPARING,
        ]);

        $response = $this->actingAs($chef, 'sanctum')
            ->postJson('/api/admin/kds-order/change-status/' . $order->id, [
                'status' => OrderStatus::CANCELED,
            ]);

        $response->assertStatus(422);
    }
}
