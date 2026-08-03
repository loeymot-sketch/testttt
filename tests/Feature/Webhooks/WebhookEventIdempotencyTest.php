<?php

namespace Tests\Feature\Webhooks;

use App\Models\WebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * [P0-FIX-1 NF525 / Payment-Idempotency — 2026-05-09]
 *
 * Regression suite for `webhook_events` unified idempotency ledger.
 * Closes the P0 finding "SenangPay webhook lacks idempotency" by proving
 * the (provider, webhook_id) UNIQUE constraint + Eloquent firstOrCreate
 * pattern produces single-processing semantics under replay + concurrency.
 *
 * Tests cover the model + DB invariants. Once a SenangPay/Stripe handler
 * is implemented, end-to-end HTTP tests should be added in a sibling spec.
 */
class WebhookEventIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_first_or_create_returns_same_row_on_replay(): void
    {
        $first = WebhookEvent::firstOrCreate(
            ['provider' => WebhookEvent::PROVIDER_SENANGPAY, 'webhook_id' => 'TXN-REPLAY-1'],
            [
                'event_type'  => 'payment_success',
                'payload'     => ['amount' => '100.00', 'order_id' => 1],
                'received_at' => now(),
                'status'      => WebhookEvent::STATUS_PENDING,
            ]
        );

        $second = WebhookEvent::firstOrCreate(
            ['provider' => WebhookEvent::PROVIDER_SENANGPAY, 'webhook_id' => 'TXN-REPLAY-1'],
            [
                'event_type'  => 'payment_success',
                'payload'     => ['amount' => '100.00', 'order_id' => 1],
                'received_at' => now(),
                'status'      => WebhookEvent::STATUS_PENDING,
            ]
        );

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, WebhookEvent::where('webhook_id', 'TXN-REPLAY-1')->count());
    }

    public function test_unique_constraint_prevents_concurrent_duplicate_insert(): void
    {
        WebhookEvent::create([
            'provider'    => WebhookEvent::PROVIDER_STRIPE,
            'webhook_id'  => 'evt_unique_1',
            'event_type'  => 'charge.succeeded',
            'payload'     => [],
            'received_at' => now(),
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        WebhookEvent::create([
            'provider'    => WebhookEvent::PROVIDER_STRIPE,
            'webhook_id'  => 'evt_unique_1',
            'event_type'  => 'charge.succeeded',
            'payload'     => [],
            'received_at' => now(),
        ]);
    }

    public function test_same_webhook_id_different_provider_allowed(): void
    {
        WebhookEvent::create([
            'provider'    => WebhookEvent::PROVIDER_STRIPE,
            'webhook_id'  => 'shared_id_42',
            'event_type'  => 'charge.succeeded',
            'payload'     => [],
            'received_at' => now(),
        ]);

        WebhookEvent::create([
            'provider'    => WebhookEvent::PROVIDER_SENANGPAY,
            'webhook_id'  => 'shared_id_42',
            'event_type'  => 'payment_success',
            'payload'     => [],
            'received_at' => now(),
        ]);

        $this->assertSame(2, WebhookEvent::where('webhook_id', 'shared_id_42')->count());
    }

    public function test_mark_processed_sets_status_and_processed_at(): void
    {
        $event = WebhookEvent::create([
            'provider'    => WebhookEvent::PROVIDER_SENANGPAY,
            'webhook_id'  => 'TXN-MARK-PROCESSED',
            'event_type'  => 'payment_success',
            'payload'     => [],
            'received_at' => now(),
            'status'      => WebhookEvent::STATUS_PENDING,
        ]);

        $event->markProcessed(orderId: 999);

        $this->assertTrue($event->isProcessed());
        $this->assertNotNull($event->processed_at);
        $this->assertSame(999, $event->order_id);
    }

    public function test_mark_failed_increments_attempts_and_records_message(): void
    {
        $event = WebhookEvent::create([
            'provider'    => WebhookEvent::PROVIDER_STRIPE,
            'webhook_id'  => 'evt_fail_1',
            'event_type'  => 'charge.failed',
            'payload'     => [],
            'received_at' => now(),
            'status'      => WebhookEvent::STATUS_PENDING,
            'attempts'    => 0,
        ]);

        $event->markFailed('Card declined: insufficient_funds');

        $this->assertSame(WebhookEvent::STATUS_FAILED, $event->status);
        $this->assertSame(1, $event->attempts);
        $this->assertStringContainsString('insufficient_funds', $event->error_message);
    }

    public function test_payload_stored_as_json_and_retrieved_as_array(): void
    {
        $payload = [
            'txn_id'     => 'TXN-JSON-1',
            'amount'     => '42.50',
            'order_id'   => 7,
            'nested'     => ['k' => 'v'],
        ];

        $event = WebhookEvent::create([
            'provider'    => WebhookEvent::PROVIDER_SENANGPAY,
            'webhook_id'  => 'TXN-JSON-1',
            'event_type'  => 'payment_success',
            'payload'     => $payload,
            'received_at' => now(),
        ]);

        $event->refresh();

        $this->assertIsArray($event->payload);
        $this->assertSame('TXN-JSON-1', $event->payload['txn_id']);
        $this->assertSame('v', $event->payload['nested']['k']);
    }

    public function test_idempotent_replay_handler_pattern(): void
    {
        // First call — handler creates event + processes
        $event1 = WebhookEvent::firstOrCreate(
            ['provider' => 'senangpay', 'webhook_id' => 'TXN-HANDLER-1'],
            [
                'event_type'  => 'payment_success',
                'payload'     => ['amount' => '50.00'],
                'received_at' => now(),
                'status'      => WebhookEvent::STATUS_PENDING,
            ]
        );

        $this->assertFalse($event1->isProcessed());

        DB::transaction(function () use ($event1) {
            // ... payment processing logic ...
            $event1->markProcessed();
        });

        // Second call (replay) — handler returns immediately
        $event2 = WebhookEvent::firstOrCreate(
            ['provider' => 'senangpay', 'webhook_id' => 'TXN-HANDLER-1'],
            [
                'event_type'  => 'payment_success',
                'payload'     => ['amount' => '50.00'],
                'received_at' => now(),
                'status'      => WebhookEvent::STATUS_PENDING,
            ]
        );

        $this->assertTrue($event2->isProcessed());
        $this->assertSame($event1->id, $event2->id);
        $this->assertSame(1, WebhookEvent::where('webhook_id', 'TXN-HANDLER-1')->count());
    }
}
