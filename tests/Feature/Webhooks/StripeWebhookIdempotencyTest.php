<?php

namespace Tests\Feature\Webhooks;

use App\Http\Middleware\VerifyCsrfToken;
use App\Models\GatewayOption;
use App\Models\PaymentGateway;
use App\Models\WebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * [Sprint 3A — Webhook idempotency 2026-05-16]
 *
 * Regression suite for the Stripe webhook handler at
 * `app/Http/PaymentGateways/Gateways/Stripe.php::handleWebhook`.
 *
 * Validates the P1-SYNC-01 audit closure invariants:
 *  - First inbound webhook → 200 OK + WebhookEvent row created.
 *  - Replay with same event.id → 200 OK + `duplicate_ignored` + no new row.
 *  - New event.id → 200 OK + new row, independent of prior events.
 *  - Invalid Stripe-Signature → 400 (and no row created).
 *  - Missing webhook secret config → 500 (misconfiguration explicit).
 *
 * Note: Stripe webhook signatures use the canonical
 * `t={timestamp},v1=hash_hmac('sha256', "{timestamp}.{payload}", $secret)`
 * scheme. We forge a valid signature locally so tests don't depend on
 * external Stripe SDK state beyond `\Stripe\Webhook::constructEvent`.
 */
class StripeWebhookIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'whsec_test_secret_sprint3a';

    private bool $createdInstalledFlag = false;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('services.stripe.webhook_secret', self::SECRET);

        $this->withoutMiddleware(VerifyCsrfToken::class);
        $this->ensureInstalledFlag();
        $this->seedStripeGateway();
    }

    /**
     * The Stripe controller's __construct() loads the Stripe PaymentGateway
     * row + options eagerly. PaymentAbstract types `paymentGateway` as
     * `object` (non-nullable in PHP 8). Without this seed the constructor
     * throws TypeError before our handler even runs.
     */
    private function seedStripeGateway(): void
    {
        $gateway = PaymentGateway::create([
            'name'   => 'Stripe',
            'slug'   => 'stripe',
            'misc'   => json_encode([]),
            'status' => 1,
        ]);

        GatewayOption::create([
            'model_id'   => $gateway->id,
            'model_type' => PaymentGateway::class,
            'option'     => 'stripe_secret',
            'value'      => 'sk_test_stub',
            'type'       => 1,
            'activities' => '',
        ]);
    }

    protected function tearDown(): void
    {
        if ($this->createdInstalledFlag && file_exists(storage_path('installed'))) {
            unlink(storage_path('installed'));
        }

        parent::tearDown();
    }

    public function test_first_webhook_creates_event_and_returns_200(): void
    {
        $payload = $this->stripePayload('evt_first_1', 'charge.succeeded');

        $response = $this->postWebhook($payload);

        $response->assertStatus(200);
        $response->assertJson(['status' => 'ok']);

        $this->assertSame(1, WebhookEvent::where([
            'provider'   => 'stripe',
            'webhook_id' => 'evt_first_1',
        ])->count());

        $event = WebhookEvent::where('webhook_id', 'evt_first_1')->first();
        $this->assertSame(WebhookEvent::STATUS_PROCESSED, $event->status);
        $this->assertSame('charge.succeeded', $event->event_type);
    }

    public function test_duplicate_event_id_returns_200_duplicate_ignored_and_no_new_row(): void
    {
        $payload = $this->stripePayload('evt_dup_1', 'charge.succeeded');

        $first = $this->postWebhook($payload);
        $first->assertStatus(200);

        $second = $this->postWebhook($payload);
        $second->assertStatus(200);
        $second->assertJson(['status' => 'duplicate_ignored']);

        $this->assertSame(1, WebhookEvent::where('webhook_id', 'evt_dup_1')->count());
    }

    public function test_different_event_ids_create_independent_rows(): void
    {
        $payloadA = $this->stripePayload('evt_distinct_a', 'charge.succeeded');
        $payloadB = $this->stripePayload('evt_distinct_b', 'charge.succeeded');

        $this->postWebhook($payloadA)->assertStatus(200);
        $this->postWebhook($payloadB)->assertStatus(200);

        $this->assertSame(2, WebhookEvent::where('provider', 'stripe')->count());
        $this->assertTrue(WebhookEvent::where('webhook_id', 'evt_distinct_a')->exists());
        $this->assertTrue(WebhookEvent::where('webhook_id', 'evt_distinct_b')->exists());
    }

    public function test_invalid_signature_returns_400_and_no_row_created(): void
    {
        $payload = $this->stripePayload('evt_bad_sig', 'charge.succeeded');
        $rawPayload = json_encode($payload);

        $response = $this->call(
            'POST',
            '/payment/stripe-webhook/',
            [],
            [],
            [],
            [
                'CONTENT_TYPE'           => 'application/json',
                'HTTP_STRIPE_SIGNATURE'  => 't=' . time() . ',v1=deadbeef_invalid_hex',
            ],
            $rawPayload
        );

        $response->assertStatus(400);
        $response->assertJsonFragment(['error' => 'invalid_signature']);

        $this->assertSame(0, WebhookEvent::where('webhook_id', 'evt_bad_sig')->count());
    }

    public function test_missing_webhook_secret_returns_500(): void
    {
        Config::set('services.stripe.webhook_secret', '');

        $payload = $this->stripePayload('evt_no_secret', 'charge.succeeded');
        $rawPayload = json_encode($payload);

        // Even with a "valid-looking" signature, the handler short-circuits
        // before signature verification when no secret is configured.
        $response = $this->call(
            'POST',
            '/payment/stripe-webhook/',
            [],
            [],
            [],
            [
                'CONTENT_TYPE'           => 'application/json',
                'HTTP_STRIPE_SIGNATURE'  => 't=' . time() . ',v1=anything',
            ],
            $rawPayload
        );

        $response->assertStatus(500);
        $response->assertJsonFragment(['error' => 'misconfigured']);
    }

    public function test_non_capture_event_records_row_but_skips_payment_bridge(): void
    {
        $payload = $this->stripePayload('evt_intent_1', 'payment_intent.created');

        $response = $this->postWebhook($payload);

        $response->assertStatus(200);

        $event = WebhookEvent::where('webhook_id', 'evt_intent_1')->first();
        $this->assertNotNull($event);
        $this->assertSame('payment_intent.created', $event->event_type);
        $this->assertSame(WebhookEvent::STATUS_PROCESSED, $event->status);
        $this->assertNull($event->order_id);
        // No CapturePaymentNotification row should be created for non-charge events.
        $this->assertSame(0, \App\Models\CapturePaymentNotification::count());
    }

    /**
     * Build a minimal Stripe event payload that
     * `\Stripe\Webhook::constructEvent` can hydrate into a
     * `\Stripe\Event` object.
     */
    private function stripePayload(string $eventId, string $type): array
    {
        return [
            'id'      => $eventId,
            'object'  => 'event',
            'type'    => $type,
            'created' => time(),
            'data'    => [
                'object' => [
                    'id'                  => 'ch_test_' . $eventId,
                    'object'              => 'charge',
                    'balance_transaction' => 'txn_test_' . $eventId,
                    'metadata'            => (object) ['order_id' => '42'],
                ],
            ],
        ];
    }

    /**
     * Post a webhook with a forged-but-valid Stripe signature header.
     */
    private function postWebhook(array $payload)
    {
        $rawPayload = json_encode($payload);
        $timestamp  = time();
        $signedPayload = $timestamp . '.' . $rawPayload;
        $signature  = hash_hmac('sha256', $signedPayload, self::SECRET);

        return $this->call(
            'POST',
            '/payment/stripe-webhook/',
            [],
            [],
            [],
            [
                'CONTENT_TYPE'           => 'application/json',
                'HTTP_STRIPE_SIGNATURE'  => 't=' . $timestamp . ',v1=' . $signature,
            ],
            $rawPayload
        );
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
