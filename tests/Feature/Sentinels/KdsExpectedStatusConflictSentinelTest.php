<?php

namespace Tests\Feature\Sentinels;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * @FK-ID FK-068 | @source reports/audit/TRACEABILITY_MATRIX_CAISSE_V1_2026-04-25.md | @fix-mission CV1-M07-KDS-RELEASE | @reason OrderStatusRequest ignores expected_status from the body, so stale KDS tabs do not receive 409 conflicts.
 */
class KdsExpectedStatusConflictSentinelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
    }

    public function test_second_kds_bump_with_same_expected_status_returns_conflict(): void
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

        $payload = [
            'status' => OrderStatus::PREPARED,
            'expected_status' => OrderStatus::PREPARING,
        ];

        // [prod-finale 2026-06-17] idempotency-guarded route requires X-Idempotency-Key (frozen middleware; live UI sends it).
        // DISTINCT keys per change-status POST: a shared key would replay the first bump's cached 2xx and the
        // controller-level optimistic-lock 409 asserted on the second (same expected_status) bump would never be reached.
        $this->actingAs($chef, 'sanctum')
            ->withHeader('X-Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/admin/kds-order/change-status/' . $order->id, $payload)
            ->assertSuccessful();

        $this->actingAs($chef, 'sanctum')
            ->withHeader('X-Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/admin/kds-order/change-status/' . $order->id, $payload)
            ->assertStatus(409);
    }
}
