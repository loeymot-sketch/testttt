<?php

namespace Tests\Feature\Webhooks;

use App\Http\Middleware\VerifyCsrfToken;
use App\Models\CapturePaymentNotification;
use App\Models\GatewayOption;
use App\Models\PaymentGateway;
use App\Models\WebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [Sprint 3A — Webhook idempotency 2026-05-16]
 *
 * Regression suite for the SenangPay webhook handler at
 * `app/Http/PaymentGateways/Gateways/Senangpay.php::webhook` (replaces
 * the iter15 501 stub).
 *
 * Validates the P1-SYNC-01 audit closure invariants for SenangPay:
 *  - First valid signature → 200 OK + WebhookEvent + CapturePaymentNotification
 *    bridge row when status_id="1" (success).
 *  - Replay with same transaction_id → 200 OK + `duplicate_ignored`,
 *    no duplicate CapturePaymentNotification.
 *  - Invalid signature → 400 (no row created, provider stops retrying).
 *  - Missing required fields → 400.
 *  - Failed payment (status_id="0") → 200 + event row recorded, no
 *    CapturePaymentNotification bridge.
 *
 * SenangPay v2 HMAC-SHA-256 spec used here:
 *   canonical = status_id|order_id|transaction_id|msg
 *   hash      = hash_hmac('sha256', canonical, merchant_secret_key)
 */
class SenangpayWebhookIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    private const MERCHANT_SECRET = 'senangpay_test_secret_sprint3a';

    private bool $createdInstalledFlag = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(VerifyCsrfToken::class);
        $this->ensureInstalledFlag();
        $this->seedSenangpayGateway();
    }

    protected function tearDown(): void
    {
        if ($this->createdInstalledFlag && file_exists(storage_path('installed'))) {
            unlink(storage_path('installed'));
        }

        parent::tearDown();
    }

    public function test_first_valid_webhook_returns_200_creates_event_and_bridges_payment(): void
    {
        $payload = $this->signedPayload([
            'status_id'      => '1',
            'order_id'       => '42',
            'transaction_id' => 'TXN-NEW-1',
            'msg'            => 'Payment successful',
        ]);

        $response = $this->post('/payment/senangpay-webhook/', $payload);

        $response->assertStatus(200);
        $response->assertJson(['status' => 'ok']);

        $event = WebhookEvent::where([
            'provider'   => 'senangpay',
            'webhook_id' => 'TXN-NEW-1',
        ])->first();

        $this->assertNotNull($event);
        $this->assertSame('payment_success', $event->event_type);
        $this->assertSame(WebhookEvent::STATUS_PROCESSED, $event->status);
        $this->assertSame(42, $event->order_id);

        $this->assertSame(1, CapturePaymentNotification::where([
            'order_id' => 42,
            'token'    => 'TXN-NEW-1',
        ])->count());
    }

    public function test_duplicate_transaction_id_returns_200_duplicate_ignored(): void
    {
        $payload = $this->signedPayload([
            'status_id'      => '1',
            'order_id'       => '99',
            'transaction_id' => 'TXN-DUP-1',
            'msg'            => 'Payment successful',
        ]);

        $first = $this->post('/payment/senangpay-webhook/', $payload);
        $first->assertStatus(200);
        $first->assertJson(['status' => 'ok']);

        $second = $this->post('/payment/senangpay-webhook/', $payload);
        $second->assertStatus(200);
        $second->assertJson(['status' => 'duplicate_ignored']);

        $this->assertSame(1, WebhookEvent::where('webhook_id', 'TXN-DUP-1')->count());
        $this->assertSame(1, CapturePaymentNotification::where('order_id', 99)->count());
    }

    public function test_invalid_signature_returns_400_no_row_created(): void
    {
        $payload = [
            'status_id'      => '1',
            'order_id'       => '7',
            'transaction_id' => 'TXN-BAD-SIG',
            'msg'            => 'Payment successful',
            'hash'           => 'deadbeef_invalid_hex_signature',
        ];

        $response = $this->post('/payment/senangpay-webhook/', $payload);

        $response->assertStatus(400);
        $response->assertJsonFragment(['error' => 'invalid_signature']);

        $this->assertSame(0, WebhookEvent::where('webhook_id', 'TXN-BAD-SIG')->count());
    }

    public function test_missing_required_fields_returns_400(): void
    {
        // transaction_id missing
        $response = $this->post('/payment/senangpay-webhook/', [
            'status_id' => '1',
            'order_id'  => '8',
            'msg'       => 'whatever',
            'hash'      => 'irrelevant',
        ]);

        $response->assertStatus(400);
        $response->assertJsonFragment(['error' => 'invalid_payload']);
    }

    public function test_failed_payment_records_event_but_no_capture_bridge(): void
    {
        $payload = $this->signedPayload([
            'status_id'      => '0',
            'order_id'       => '55',
            'transaction_id' => 'TXN-FAIL-1',
            'msg'            => 'Card declined',
        ]);

        $response = $this->post('/payment/senangpay-webhook/', $payload);

        $response->assertStatus(200);

        $event = WebhookEvent::where('webhook_id', 'TXN-FAIL-1')->first();
        $this->assertNotNull($event);
        $this->assertSame('payment_failed', $event->event_type);
        $this->assertSame(WebhookEvent::STATUS_PROCESSED, $event->status);

        $this->assertSame(0, CapturePaymentNotification::where('order_id', 55)->count());
    }

    public function test_misconfigured_gateway_returns_500(): void
    {
        // Drop the secret to simulate misconfiguration.
        GatewayOption::where('option', 'senangpay_secret_key')->delete();

        $payload = $this->signedPayload([
            'status_id'      => '1',
            'order_id'       => '11',
            'transaction_id' => 'TXN-MISCONFIG',
            'msg'            => 'Payment successful',
        ]);

        $response = $this->post('/payment/senangpay-webhook/', $payload);

        $response->assertStatus(500);
        $response->assertJsonFragment(['error' => 'misconfigured']);
    }

    /**
     * Build a SenangPay webhook payload with a valid HMAC-SHA-256 hash
     * computed against the seeded merchant secret. The canonical input
     * mirrors the production handler:
     *   status_id|order_id|transaction_id|msg
     */
    private function signedPayload(array $fields): array
    {
        $canonical = $fields['status_id'] . '|'
            . $fields['order_id'] . '|'
            . $fields['transaction_id'] . '|'
            . $fields['msg'];

        $fields['hash'] = hash_hmac('sha256', $canonical, self::MERCHANT_SECRET);

        return $fields;
    }

    /**
     * Seed the SenangPay PaymentGateway row + merchant secret option so
     * the handler can resolve the secret without going through the
     * package installer flow.
     */
    private function seedSenangpayGateway(): void
    {
        $gateway = PaymentGateway::create([
            'name'   => 'SenangPay',
            'slug'   => 'senangpay',
            'misc'   => json_encode([]),
            'status' => 1,
        ]);

        GatewayOption::create([
            'model_id'   => $gateway->id,
            'model_type' => PaymentGateway::class,
            'option'     => 'senangpay_secret_key',
            'value'      => self::MERCHANT_SECRET,
            'type'       => 1,
            'activities' => '',
        ]);

        GatewayOption::create([
            'model_id'   => $gateway->id,
            'model_type' => PaymentGateway::class,
            'option'     => 'senangpay_merchant_id',
            'value'      => 'TESTMERCHANT',
            'type'       => 1,
            'activities' => '',
        ]);
    }

    private function ensureInstalledFlag(): void
    {
        if (file_exists(storage_path('installed'))) {
            return;
        }

        touch(storage_path('installed'));
        $this->createdInstalledFlag = true;
    }
}
