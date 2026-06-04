<?php

namespace Tests\Feature\Webhooks;

use App\Enums\PaymentStatus;
use App\Events\RefundCreated;
use App\Http\Middleware\VerifyCsrfToken;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\GatewayOption;
use App\Models\Order;
use App\Models\PaymentGateway;
use App\Models\WebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * [GOAL-K2-HEAL-04 2026-05-24] Phase K.8 K8-F-03 P1 sentinel.
 *
 * Verifies the `charge.refunded` webhook handler at
 * `app/Http/PaymentGateways/Gateways/Stripe.php` bridges Stripe-dashboard
 * refunds into the FoodKing RefundCreated cascade.
 *
 * Invariants under test:
 *  1) First receipt of `charge.refunded` with a known order id (metadata)
 *     → 200 OK + WebhookEvent row PROCESSED + audit row
 *     `order.refunded.stripe_dashboard` appended + RefundCreated event
 *     dispatched.
 *  2) Replay of the same Stripe event.id → 200 OK `duplicate_ignored`
 *     (handled at the WebhookEvent firstOrCreate layer, never reaches the
 *     cascade branch).
 *  3) A second DISTINCT event.id targeting the same order whose payment
 *     status has been flipped to REFUNDED (by the listener cascade or
 *     ops) → 200 OK + audit row NOT duplicated + RefundCreated NOT
 *     re-dispatched.
 *  4) `charge.refunded` for an unknown order (no metadata link) → 200 OK
 *     + WebhookEvent PROCESSED + NO audit row + NO event dispatched (we
 *     never want to retry forever on legacy / out-of-band charges).
 *
 * The signing scheme mirrors `StripeWebhookIdempotencyTest::postWebhook`
 * so we stay decoupled from Stripe SDK fixtures.
 */
class StripeChargeRefundedCascadeSentinelTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'whsec_test_secret_k2_heal_04';

    private bool $createdInstalledFlag = false;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('services.stripe.webhook_secret', self::SECRET);
        // Fiscal secret is provisioned by phpunit.xml; double-set defensively
        // so this sentinel never depends on env ordering.
        Config::set('fiscal.audit_secret', str_repeat('a', 48));

        $this->withoutMiddleware(VerifyCsrfToken::class);
        $this->ensureInstalledFlag();
        $this->seedStripeGateway();
    }

    protected function tearDown(): void
    {
        if ($this->createdInstalledFlag && file_exists(storage_path('installed'))) {
            unlink(storage_path('installed'));
        }

        parent::tearDown();
    }

    public function test_charge_refunded_dispatches_cascade_writes_audit_and_marks_event_processed(): void
    {
        Event::fake([RefundCreated::class]);

        $branch = Branch::factory()->create();
        $order  = Order::factory()->create([
            'branch_id'      => $branch->id,
            'payment_status' => PaymentStatus::PAID,
        ]);

        $payload = $this->stripeRefundPayload('evt_refund_first_1', 'ch_refund_first_1', $order->id, 1599);

        $response = $this->postWebhook($payload);

        $response->assertStatus(200);
        $response->assertJson(['status' => 'ok']);

        // WebhookEvent row exists and is PROCESSED.
        $event = WebhookEvent::where('webhook_id', 'evt_refund_first_1')->first();
        $this->assertNotNull($event, 'WebhookEvent row must be persisted on first receipt.');
        $this->assertSame(WebhookEvent::STATUS_PROCESSED, $event->status);
        $this->assertSame($order->id, (int) $event->order_id);

        // Audit row appended via AuditLogService::write.
        $audit = AuditLog::where('action', 'order.refunded.stripe_dashboard')
            ->where('resource_id', $order->id)
            ->first();
        $this->assertNotNull($audit, 'Audit row order.refunded.stripe_dashboard must be appended.');
        $this->assertSame($branch->id, (int) $audit->branch_id);
        $payloadJson = is_string($audit->payload) ? json_decode($audit->payload, true) : (array) $audit->payload;
        $this->assertSame('ch_refund_first_1', $payloadJson['charge_id'] ?? null);
        $this->assertSame('stripe_dashboard_webhook', $payloadJson['source'] ?? null);
        $this->assertSame(15.99, $payloadJson['amount_refunded'] ?? null);

        // RefundCreated event dispatched — listener cascade ownership.
        Event::assertDispatched(RefundCreated::class, function (RefundCreated $e) use ($order) {
            return $e->order instanceof Order
                && (int) $e->order->id === (int) $order->id;
        });
    }

    public function test_duplicate_event_id_is_idempotent_via_webhook_event_layer(): void
    {
        Event::fake([RefundCreated::class]);

        $branch = Branch::factory()->create();
        $order  = Order::factory()->create([
            'branch_id'      => $branch->id,
            'payment_status' => PaymentStatus::PAID,
        ]);

        $payload = $this->stripeRefundPayload('evt_refund_replay_1', 'ch_refund_replay_1', $order->id, 999);

        $this->postWebhook($payload)->assertStatus(200);
        $this->postWebhook($payload)
            ->assertStatus(200)
            ->assertJson(['status' => 'duplicate_ignored']);

        // Exactly one audit row, exactly one RefundCreated dispatch.
        $this->assertSame(1, AuditLog::where('action', 'order.refunded.stripe_dashboard')
            ->where('resource_id', $order->id)
            ->count());
        Event::assertDispatchedTimes(RefundCreated::class, 1);
    }

    public function test_distinct_event_for_already_refunded_order_skips_cascade(): void
    {
        Event::fake([RefundCreated::class]);

        $branch = Branch::factory()->create();
        $order  = Order::factory()->create([
            'branch_id'      => $branch->id,
            // Simulate the listener cascade already flipped this order
            // (or ops handled the refund manually) — second webhook must
            // skip the cascade rather than re-fire ClawbackLoyalty etc.
            'payment_status' => PaymentStatus::REFUNDED,
        ]);

        $payload = $this->stripeRefundPayload('evt_refund_already_done_1', 'ch_refund_already_done_1', $order->id, 500);

        $this->postWebhook($payload)->assertStatus(200);

        // WebhookEvent processed, no audit row, no dispatch.
        $event = WebhookEvent::where('webhook_id', 'evt_refund_already_done_1')->first();
        $this->assertNotNull($event);
        $this->assertSame(WebhookEvent::STATUS_PROCESSED, $event->status);

        $this->assertSame(0, AuditLog::where('action', 'order.refunded.stripe_dashboard')
            ->where('resource_id', $order->id)
            ->count());
        Event::assertNotDispatched(RefundCreated::class);
    }

    public function test_charge_refunded_for_unknown_order_logs_and_skips_cascade(): void
    {
        Event::fake([RefundCreated::class]);

        // No order seeded; metadata points to id 99999.
        $payload = $this->stripeRefundPayload('evt_refund_unknown_1', 'ch_refund_unknown_1', 99999, 250);

        $this->postWebhook($payload)->assertStatus(200);

        $event = WebhookEvent::where('webhook_id', 'evt_refund_unknown_1')->first();
        $this->assertNotNull($event);
        $this->assertSame(WebhookEvent::STATUS_PROCESSED, $event->status);

        $this->assertSame(0, AuditLog::where('action', 'order.refunded.stripe_dashboard')->count());
        Event::assertNotDispatched(RefundCreated::class);
    }

    /**
     * The Stripe controller's __construct() loads the Stripe PaymentGateway
     * row + options eagerly. Without this seed the constructor throws
     * TypeError before our handler even runs (mirrors
     * StripeWebhookIdempotencyTest::seedStripeGateway).
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

    /**
     * Build a minimal `charge.refunded` event payload that
     * `\Stripe\Webhook::constructEvent` can hydrate.
     */
    private function stripeRefundPayload(string $eventId, string $chargeId, int $orderId, int $amountRefundedCents): array
    {
        return [
            'id'      => $eventId,
            'object'  => 'event',
            'type'    => 'charge.refunded',
            'created' => time(),
            'data'    => [
                'object' => [
                    'id'               => $chargeId,
                    'object'           => 'charge',
                    'amount_refunded'  => $amountRefundedCents,
                    'metadata'         => (object) ['order_id' => (string) $orderId],
                ],
            ],
        ];
    }

    /**
     * Post a webhook with a forged-but-valid Stripe signature header.
     * Mirrors StripeWebhookIdempotencyTest::postWebhook so the two suites
     * stay drift-free.
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
                'CONTENT_TYPE'          => 'application/json',
                'HTTP_STRIPE_SIGNATURE' => 't=' . $timestamp . ',v1=' . $signature,
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
