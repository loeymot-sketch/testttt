<?php

namespace Tests\Feature;

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
use Tests\TestCase;

class CleanupVsConfirmRaceTest extends TestCase
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
        ]);

        Order::withoutGlobalScope(BranchScope::class)
            ->whereKey($order->id)
            ->update(['created_at' => now()->subMinutes(30), 'order_datetime' => now()->subMinutes(30)]);

        (new CleanupStalePendingKioskOrders())->handle();

        $token = $kioskUser->createToken('kiosk', ['kiosk:order'])->plainTextToken;

        $this->withToken($token)->postJson('/api/frontend/order/'.$order->id.'/payment-confirm', [
            'transaction_id' => 'FK-M06-LATE-TPE',
            'card_type' => 'visa',
            'payment_method' => PaymentGateway::CARD,
        ])->assertStatus(422);

        $this->assertDatabaseHas(ActionLog::class, [
            'action' => 'payment_late_after_cleanup',
        ]);
        $this->assertSame(PaymentStatus::UNPAID, (int) Order::withoutGlobalScopes()->findOrFail($order->id)->payment_status);
    }
}
