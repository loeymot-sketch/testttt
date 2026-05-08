<?php

namespace Tests\Feature\Delivery;

use App\Jobs\SendFcmNotificationJob;
use App\Jobs\DispatchDomainEventsJob;
use App\Models\Branch;
use App\Models\DeliveryPlatform;
use App\Models\DomainEvent;
use App\Models\FrontendOrder;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * [PARALLEL-TRACK-1.2 / Delivery Platform Integration — Phase 2]
 *
 * Full pipeline assertion: webhook ingress → middleware → controller →
 * sync queue worker → IngestionService → OrderCreated event listener →
 * domain_events row created (the outbox entry every realtime client
 * relies on for KDS/POS broadcasts).
 *
 * The phpunit.xml runs QUEUE_CONNECTION=sync, so the dispatched
 * ProcessDeliveryPlatformWebhookJob fires inline. We do NOT call
 * Bus::fake() here — that's the whole point: the test exercises the
 * job for real to prove the contract is honoured end-to-end.
 */
class UberEats_E2EBroadcastTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;
    private string $webhookSecret = 'whsec_padding_padding_padding_padding_xxx';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        // Fake the auxiliary jobs only — the FCM job throws under
        // sync queue when no real Firebase credentials are present
        // (test env), and the Pusher dispatcher would actually try
        // to broadcast. We keep ProcessDeliveryPlatformWebhookJob OFF
        // the fake list so it executes inline as in production.
        Bus::fake([
            SendFcmNotificationJob::class,
            DispatchDomainEventsJob::class,
        ]);

        $this->branch = Branch::factory()->create();

        DeliveryPlatform::create([
            'branch_id'         => $this->branch->id,
            'platform'          => 'uber_eats',
            'enabled'           => true,
            'external_store_id' => 'store_uuid_42',
            'credentials'       => ['webhook_secret' => $this->webhookSecret, 'api_key' => 'k'],
        ]);

        Carbon::setTestNow('2026-05-08T10:15:30Z');
    }

    public function test_webhook_to_domain_event_full_pipeline(): void
    {
        $body = file_get_contents(base_path('tests/Fixtures/uber_eats/order_created.json'));
        $sig  = $this->signed($body);

        // We DO NOT fake the bus — sync queue runs the job inline,
        // so the IngestionService and OrderCreated listeners fire as
        // they would in production with Reverb/Pusher.
        $response = $this->call('POST', '/api/webhooks/delivery/uber_eats/order.created', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT'  => 'application/json',
            'HTTP_X_UBER_SIGNATURE' => $sig,
        ], $body);

        $response->assertStatus(202);

        // (a) FrontendOrder created.
        $order = FrontendOrder::withoutGlobalScopes()
            ->where('source_surface', 'uber_eats')
            ->latest('id')
            ->first();
        $this->assertNotNull($order, 'FrontendOrder must exist after E2E webhook flow');

        // (b) domain_events row written by the OrderCreated listener
        //     (PersistOrderCreatedToOutbox). This is the outbox entry the
        //     DispatchDomainEventsJob will later turn into a Pusher event.
        $domainEvent = DomainEvent::query()
            ->where('aggregate_id', $order->id)
            ->where('broadcast_as', 'OrderCreated')
            ->first();

        $this->assertNotNull(
            $domainEvent,
            'OrderCreated must produce a domain_events row (outbox)'
        );

        // (c) Payload shape — order_id matches, branch matches.
        $this->assertSame($order->id,         (int) $domainEvent->payload['order_id']);
        $this->assertSame($order->branch_id,  $domainEvent->branch_id);

        // (d) Channel name for branch isolation.
        $channels = json_decode($domainEvent->channel, true);
        $this->assertIsArray($channels);
        $this->assertContains('private-branch.' . $order->branch_id, $channels);
    }

    private function signed(string $body): string
    {
        $timestamp = Carbon::now()->getTimestamp();
        $expected  = hash_hmac('sha256', $timestamp . '.' . $body, $this->webhookSecret);
        return sprintf('v=1,t=%d,k=key_1,h=%s', $timestamp, $expected);
    }
}
