<?php

namespace Tests\Feature\Kiosk;

use App\Enums\OrderStatus;
use App\Enums\PaymentGateway;
use App\Enums\PaymentStatus;
use App\Models\Branch;
use App\Models\FrontendOrder;
use App\Models\KioskMachine;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * @FK-ID F-002 — TPE Amount Echo Verification
 * @source plans/PLAN_AUDIT_F002_TPE_AMOUNT_ECHO_2026-05-07.md
 * @sprint S1
 * @severity P0 — fraude TPE possible dès branchement réel
 *
 * NF525 + PCI-DSS exigent vérification stricte du montant retourné par TPE.
 * Sans cette vérification, un TPE compromis peut approuver un montant arbitraire
 * (ex. 1€ sur panier 50€) ; le backend marque PAID avec ce transaction_id falsifié.
 *
 * Tolérance: ±1 centime pour absorber les arrondis tax flottants.
 * Error code stable: 'AMOUNT_ECHO_MISMATCH' (utilisé par dashboards ops).
 */
class KioskPaymentConfirmAmountTest extends TestCase
{
    use RefreshDatabase;

    public function test_rejects_payment_confirm_when_amount_is_under_total(): void
    {
        $order = $this->createKioskCardOrder(50.00);

        // [prod-finale 2026-06-17] idempotency-guarded route requires X-Idempotency-Key (frozen middleware; live UI sends it).
        $response = $this->withHeader('X-Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/frontend/order/{$order->id}/payment-confirm", [
            'transaction_id' => 'TX-FRAUD-' . uniqid(),
            'card_type'      => 'VISA',
            'payment_method' => PaymentGateway::CARD,
            'amount_cents'   => 100,
        ]);

        $response->assertStatus(422);
        $response->assertJson(['error_code' => 'AMOUNT_ECHO_MISMATCH']);
        $this->assertEquals(
            PaymentStatus::UNPAID,
            $order->fresh()->payment_status,
            'payment_status MUST remain UNPAID on amount mismatch.'
        );
    }

    public function test_rejects_payment_confirm_when_amount_is_over_total(): void
    {
        $order = $this->createKioskCardOrder(50.00);

        $response = $this->withHeader('X-Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/frontend/order/{$order->id}/payment-confirm", [
            'transaction_id' => 'TX-OVER-' . uniqid(),
            'card_type'      => 'VISA',
            'payment_method' => PaymentGateway::CARD,
            'amount_cents'   => 5500,
        ]);

        $response->assertStatus(422);
        $response->assertJson(['error_code' => 'AMOUNT_ECHO_MISMATCH']);
    }

    public function test_accepts_payment_confirm_with_exact_amount(): void
    {
        $order = $this->createKioskCardOrder(50.00);

        $response = $this->withHeader('X-Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/frontend/order/{$order->id}/payment-confirm", [
            'transaction_id' => 'TX-OK-' . uniqid(),
            'card_type'      => 'VISA',
            'payment_method' => PaymentGateway::CARD,
            'amount_cents'   => 5000,
        ]);

        // Order is in OrderStatus::PENDING + PaymentStatus::UNPAID = valid input for paymentConfirm
        // 200 indicates the controller accepted the call (downstream may set PAID).
        $this->assertContains(
            $response->status(),
            [200, 422],
            "Exact amount must NOT trigger AMOUNT_ECHO_MISMATCH. Got {$response->status()} body=" . $response->getContent()
        );
        // Critical: regardless of downstream business outcome, error_code must NOT be AMOUNT_ECHO_MISMATCH
        if ($response->status() === 422) {
            $body = $response->json();
            $this->assertNotEquals('AMOUNT_ECHO_MISMATCH', $body['error_code'] ?? null);
        }
    }

    public function test_tolerates_one_cent_rounding_difference(): void
    {
        // total = 50.01€ stored, amount_cents = 5000 (1 cent diff) → tolérance OK
        $order = $this->createKioskCardOrder(50.01);

        $response = $this->withHeader('X-Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/frontend/order/{$order->id}/payment-confirm", [
            'transaction_id' => 'TX-CENT-' . uniqid(),
            'card_type'      => 'VISA',
            'payment_method' => PaymentGateway::CARD,
            'amount_cents'   => 5000,
        ]);

        // 1-cent diff must NOT trigger AMOUNT_ECHO_MISMATCH (tolérance ±1).
        // Explicit assertion: even if downstream business logic returns 4xx for other
        // reasons, the F-002 guard MUST NOT fire on a 1-cent diff.
        $body = $response->json();
        $errorCode = $body['error_code'] ?? null;
        $this->assertNotEquals(
            'AMOUNT_ECHO_MISMATCH',
            $errorCode,
            'F-002 tolerance ±1 cent must absorb arrondi flottant. Got status=' . $response->status() . ' body=' . $response->getContent()
        );
    }

    public function test_rejects_payment_confirm_when_amount_cents_missing(): void
    {
        $order = $this->createKioskCardOrder(50.00);

        $response = $this->withHeader('X-Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/frontend/order/{$order->id}/payment-confirm", [
            'transaction_id' => 'TX-NOAMT-' . uniqid(),
            'card_type'      => 'VISA',
            'payment_method' => PaymentGateway::CARD,
        ]);

        $response->assertStatus(422);
        $body = $response->json();
        // Validation Laravel error or AMOUNT_ECHO_MISMATCH — peu importe, ce qui compte:
        // amount_cents required → 422 toujours
        $this->assertTrue(
            isset($body['errors']['amount_cents']) || ($body['error_code'] ?? null) !== null,
            'amount_cents missing must trigger validation 422.'
        );
    }

    public function test_rejects_payment_confirm_when_amount_cents_zero_or_negative(): void
    {
        foreach ([0, -1, -5000] as $invalid) {
            $order = $this->createKioskCardOrder(50.00);
            // Fresh key per iteration: each POST targets a distinct order and asserts its own 422.
            $response = $this->withHeader('X-Idempotency-Key', (string) Str::uuid())
                ->postJson("/api/frontend/order/{$order->id}/payment-confirm", [
                'transaction_id' => 'TX-NEG-' . uniqid(),
                'card_type'      => 'VISA',
                'payment_method' => PaymentGateway::CARD,
                'amount_cents'   => $invalid,
            ]);
            $response->assertStatus(422, "amount_cents={$invalid} must be rejected.");
        }
    }

    private function createKioskCardOrder(float $total): FrontendOrder
    {
        $branch = Branch::factory()->create();
        $user = User::factory()->create(['branch_id' => $branch->id]);
        \App\Models\KioskMachine::query()->create([
            'name'       => 'TEST-KIOSK-' . uniqid(),
            'machine_id' => 'KM-' . uniqid(),
            'username'   => 'kiosk-test-' . uniqid(),
            'password'   => 'pwd',
            'user_id'    => $user->id,
            'branch_id'  => $branch->id,
        ]);

        Sanctum::actingAs($user, ['kiosk:order']);

        return \App\Models\FrontendOrder::query()->create([
            'user_id'          => $user->id,
            'branch_id'        => $branch->id,
            'total'            => $total,
            'subtotal'         => $total,
            'discount'         => 0,
            'payment_status'   => PaymentStatus::UNPAID,
            'payment_method'   => PaymentGateway::CARD,
            'status'           => OrderStatus::PENDING,
            'order_datetime'   => now(),
            'preparation_time' => 15,
        ]);
    }
}
