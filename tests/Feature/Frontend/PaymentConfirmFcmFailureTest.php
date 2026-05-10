<?php

namespace Tests\Feature\Frontend;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentGateway;
use App\Enums\PaymentStatus;
use App\Events\OrderCreated;
use App\Models\Branch;
use App\Models\KioskMachine;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * [test-e2e fix B-001 round-2 2026-05-10]
 * Wave B rush-hour-50x50 audit observed 35/35 kiosk orders returning HTTP 422
 * "FCM send failed — will retry" on /api/frontend/order/{id}/payment-confirm
 * while payment_status=PAID + transaction_id were persisted in DB. Root cause:
 * the outer catch(Exception) in OrderController::paymentConfirm swallowed the
 * SendFcmNotificationJob RuntimeException that bubbles up through the event
 * dispatcher under QUEUE_CONNECTION=sync.
 *
 * The fix narrows the catch around the post-commit side-effect chain
 * (finalizePaidKioskOrder + OrderCreated event listeners) so a side-effect
 * failure does NOT invalidate the HTTP success contract that
 * payment_status=PAID + transaction_id are committed.
 *
 * This test simulates a listener throwing (the same shape as FCM RuntimeException
 * propagating through the sync event dispatcher) and asserts the API still
 * returns 200 with the payment data committed.
 */
class PaymentConfirmFcmFailureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
    }

    public function test_payment_confirm_returns_200_when_post_commit_side_effect_throws(): void
    {
        $branch = Branch::factory()->create();
        $kioskUser = User::factory()->create(['branch_id' => $branch->id]);
        KioskMachine::factory()->create(['user_id' => $kioskUser->id, 'branch_id' => $branch->id]);

        $order = Order::factory()->create([
            'user_id'        => $kioskUser->id,
            'branch_id'      => $branch->id,
            'order_type'     => OrderType::KIOSK,
            'payment_method' => PaymentGateway::CARD,
            'payment_status' => PaymentStatus::UNPAID,
            'status'         => OrderStatus::PENDING,
            'total'          => 50.00,
            'source_surface' => 'kiosk',
        ]);

        // Inject a listener that throws the exact RuntimeException shape that
        // SendFcmNotificationJob::handle() throws on FCM send failure (line 98:
        // 'FCM send failed — will retry'). With QUEUE_CONNECTION=sync this
        // bubbles up through the event dispatcher to the controller catch.
        Event::listen(OrderCreated::class, function ($event) {
            throw new \RuntimeException('FCM send failed — will retry');
        });

        $token = $kioskUser->createToken('kiosk-order', ['kiosk:order'])->plainTextToken;

        $response = $this->withToken($token)->postJson(
            '/api/frontend/order/'.$order->id.'/payment-confirm',
            [
                'transaction_id' => 'FK-B001-FCM-TEST',
                'card_type'      => 'visa',
                'payment_method' => PaymentGateway::CARD,
                'amount_cents'   => 5000,
            ]
        );

        // Contract: payment-confirm returns 200 once payment is committed,
        // regardless of post-commit side-effect health.
        $response->assertStatus(200);
        $response->assertJsonPath('status', true);

        // And the DB state must reflect the commit.
        $persisted = Order::withoutGlobalScopes()->findOrFail($order->id);
        $this->assertSame(PaymentStatus::PAID, (int) $persisted->payment_status);
        $this->assertSame('FK-B001-FCM-TEST', $persisted->transaction_id);
    }
}
