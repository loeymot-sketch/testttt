<?php

namespace Tests\Feature\Delivery;

use App\Jobs\DispatchDomainEventsJob;
use App\Jobs\SendFcmNotificationJob;
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
 * [PARALLEL-TRACK-1.3 / Delivery Platform Integration — Phase 3]
 *
 * Mirrors UberEats_E2EBroadcastTest. Same pipeline assertion:
 *   webhook ingress → middleware → controller → sync queue worker →
 *   IngestionService → OrderCreated → PersistOrderCreatedToOutbox →
 *   domain_events row written.
 */
class Deliveroo_E2EBroadcastTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;
    private string $webhookSecret = 'whsec_dlv_padding_padding_padding_xxx';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        // Fake auxiliary jobs only — keep ProcessDeliveryPlatformWebhookJob
        // OFF the fake list so it executes inline as in production.
        Bus::fake([
            SendFcmNotificationJob::class,
            DispatchDomainEventsJob::class,
        ]);

        $this->branch = Branch::factory()->create();

        DeliveryPlatform::create([
            'branch_id'         => $this->branch->id,
            'platform'          => 'deliveroo',
            'enabled'           => true,
            'external_store_id' => 'fk-paris-republique',
            'credentials'       => ['webhook_secret' => $this->webhookSecret, 'api_key' => 'dlv_key'],
        ]);

        Carbon::setTestNow('2026-05-08T10:15:30Z');
    }

    public function test_webhook_to_domain_event_full_pipeline(): void
    {
        $body = file_get_contents(base_path('tests/Fixtures/deliveroo/order_created.json'));
        $sig  = $this->signed($body);

        $response = $this->call(
            'POST',
            '/api/webhooks/delivery/deliveroo/order.created',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT'  => 'application/json',
                'HTTP_X_DELIVEROO_HMAC_SHA256' => $sig,
                'HTTP_X_DELIVEROO_SEQUENCE_GUID' => 'seq-guid-' . uniqid('', true),
            ],
            $body,
        );

        $response->assertStatus(202);

        // (a) FrontendOrder created with platform marker.
        $order = FrontendOrder::withoutGlobalScopes()
            ->where('source_surface', 'deliveroo')
            ->latest('id')
            ->first();
        $this->assertNotNull($order, 'FrontendOrder must exist after Deliveroo webhook flow');
        $this->assertSame($this->branch->id, $order->branch_id);
        $this->assertSame('dp:deliveroo:deliveroo-order-1234-5678', $order->idempotency_key);

        // (b) domain_events outbox row.
        $domainEvent = DomainEvent::query()
            ->where('aggregate_id', $order->id)
            ->where('broadcast_as', 'OrderCreated')
            ->first();
        $this->assertNotNull(
            $domainEvent,
            'OrderCreated must produce a domain_events row (outbox)'
        );

        // (c) Channel name carries branch isolation.
        $channels = json_decode($domainEvent->channel, true);
        $this->assertIsArray($channels);
        $this->assertContains('private-branch.' . $order->branch_id, $channels);
    }

    private function signed(string $body): string
    {
        return hash_hmac('sha256', $body, $this->webhookSecret);
    }
}
