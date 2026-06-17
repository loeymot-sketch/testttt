<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Models\Branch;
use App\Models\Order;
use App\Models\OrderStatusTransition;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Ultrareview remediation — #13.
 * recall() Cap N=1 anchored the "already recalled" dedup on the moving
 * orders.updated_at. An unrelated write advancing updated_at (status stays
 * PREPARED) pushed the window past the prior kitchen_recall row, letting a
 * 2nd recall slip through (2 rows). The fix anchors the dedup to a stable
 * sliding window (now - 60s), immune to updated_at movement.
 */
class KdsRecallCapNTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
    }

    public function test_second_recall_is_409_even_after_updated_at_advances(): void
    {
        $branch = Branch::factory()->create();
        $chef = User::factory()->create(['branch_id' => $branch->id]);
        $chef->assignRole('Chef');

        $order = Order::factory()->create([
            'branch_id' => $branch->id,
            'order_type' => OrderType::POS,
            'payment_status' => PaymentStatus::PAID,
            'status' => OrderStatus::PREPARED,
        ]);

        // [prod-finale 2026-06-17] idempotency-guarded route requires X-Idempotency-Key (frozen middleware; live UI sends it).
        // DISTINCT keys per recall POST: a shared key would replay recall #1's cached 2xx and the controller-level
        // cap-N=1 409 this test asserts on recall #2 would never be reached.
        // Recall #1 — inside the 60s window → 200.
        $this->actingAs($chef, 'sanctum')
            ->withHeader('X-Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/admin/kds-order/recall/'.$order->id)
            ->assertOk();

        // An unrelated write advances updated_at while status stays PREPARED.
        // This is exactly what defeated the old (updated_at-anchored) dedup.
        DB::table('orders')->where('id', $order->id)->update([
            'updated_at' => now()->addSeconds(5),
        ]);

        // Recall #2 — must be refused as already-recalled (cap N=1).
        $this->actingAs($chef, 'sanctum')
            ->withHeader('X-Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/admin/kds-order/recall/'.$order->id)
            ->assertStatus(409);

        // Exactly ONE kitchen_recall transition exists, and orders.status is untouched (NF525 append-only).
        $this->assertSame(1, OrderStatusTransition::query()
            ->where('order_id', $order->id)
            ->where('reason', 'kitchen_recall')
            ->count());
        $this->assertSame(OrderStatus::PREPARED, (int) $order->fresh()->status);
    }
}
