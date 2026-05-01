<?php

namespace Tests\Feature\Sentinels;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentGateway;
use App\Enums\PaymentStatus;
use App\Models\Branch;
use App\Models\KioskMachine;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * @FK-ID FK-029/FK-008 | @source reports/audit/TRACEABILITY_MATRIX_CAISSE_V1_2026-04-25.md | @fix-mission CV1-M06-POS-REVENUE-GUARDS | @reason payment-confirm does not resolve the kiosk machine branch_id before mutating the order.
 */
class PaymentConfirmCrossBranchSentinelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
    }

    public function test_kiosk_machine_cannot_confirm_order_from_another_branch(): void
    {
        $branchA = Branch::factory()->create();
        $branchB = Branch::factory()->create();
        $kioskUser = User::factory()->create(['branch_id' => 0]);

        KioskMachine::factory()->create([
            'user_id' => $kioskUser->id,
            'branch_id' => $branchA->id,
        ]);

        $foreignOrder = Order::factory()->create([
            'user_id' => $kioskUser->id,
            'branch_id' => $branchB->id,
            'order_type' => OrderType::KIOSK,
            'payment_method' => PaymentGateway::CARD,
            'payment_status' => PaymentStatus::UNPAID,
            'status' => OrderStatus::PENDING,
            'source_surface' => 'kiosk',
        ]);

        Sanctum::actingAs($kioskUser, ['kiosk:order']);

        $response = $this->postJson('/api/frontend/order/' . $foreignOrder->id . '/payment-confirm', [
            'transaction_id' => 'FK-SENTINEL-CROSS-BRANCH',
            'card_type' => 'visa',
            'payment_method' => PaymentGateway::CARD,
        ]);

        $response->assertStatus(403);
        $this->assertSame(
            PaymentStatus::UNPAID,
            (int) Order::withoutGlobalScopes()->findOrFail($foreignOrder->id)->payment_status,
            'Cross-branch kiosk confirm must leave payment_status unchanged.'
        );
    }
}
