<?php

namespace Tests\Feature\Sentinels;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentGateway;
use App\Enums\PaymentStatus;
use App\Jobs\CleanupStalePendingKioskOrders;
use App\Models\ActionLog;
use App\Models\Branch;
use App\Models\KioskMachine;
use App\Models\Order;
use App\Models\Scopes\BranchScope;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * @FK-ID FK-029/FK-044 | @source reports/audit/TRACEABILITY_MATRIX_CAISSE_V1_2026-04-25.md | @fix-mission CV1-M06-POS-REVENUE-GUARDS | @reason a late TPE confirm after stale cleanup must be rejected and audited, not re-paid.
 */
class CleanupVsConfirmRaceSentinelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
    }

    public function test_late_payment_confirm_after_cleanup_is_rejected_and_audited(): void
    {
        Event::fake();

        $branch = Branch::factory()->create();
        $kioskUser = User::factory()->create(['branch_id' => $branch->id]);
        KioskMachine::factory()->create(['user_id' => $kioskUser->id, 'branch_id' => $branch->id]);

        $order = Order::factory()->create([
            'user_id' => $kioskUser->id,
            'branch_id' => $branch->id,
            'order_type' => OrderType::KIOSK,
            'payment_method' => PaymentGateway::CARD,
            'payment_status' => PaymentStatus::UNPAID,
            'status' => OrderStatus::PENDING,
            'source_surface' => 'kiosk',
            'order_datetime' => now()->subMinutes(30),
            'created_at' => now()->subMinutes(30),
            'total' => 50.00,
            'subtotal' => 50.00,
        ]);

        Order::withoutGlobalScope(BranchScope::class)
            ->whereKey($order->id)
            ->update(['created_at' => now()->subMinutes(30), 'order_datetime' => now()->subMinutes(30)]);

        (new CleanupStalePendingKioskOrders())->handle();

        Sanctum::actingAs($kioskUser, ['kiosk:order']);
        $response = $this->withHeaders(['X-Idempotency-Key' => 'sentinel-cvcr-' . uniqid()])
            ->postJson('/api/frontend/order/' . $order->id . '/payment-confirm', [
                'transaction_id' => 'FK-SENTINEL-LATE-TPE',
                'card_type' => 'visa',
                'payment_method' => PaymentGateway::CARD,
                'amount_cents' => 5000,
            ]);

        $response->assertStatus(422);
        $this->assertDatabaseHas(ActionLog::class, [
            'action' => 'payment_late_after_cleanup',
        ]);
    }
}
