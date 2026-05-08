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
 * Mirrors UberEats_E2EBroadcastTest for Delicity (Stripe-style signature).
 */
class Delicity_E2EBroadcastTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;
    private string $webhookSecret = 'whsec_dlc_padding_padding_padding_xxx';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        Bus::fake([
            SendFcmNotificationJob::class,
            DispatchDomainEventsJob::class,
        ]);

        $this->branch = Branch::factory()->create();

        DeliveryPlatform::create([
            'branch_id'         => $this->branch->id,
            'platform'          => 'delicity',
            'enabled'           => true,
            'external_store_id' => 'fk-paris-republique',
            'credentials'       => ['webhook_secret' => $this->webhookSecret, 'api_key' => 'dlc_key'],
        ]);

        Carbon::setTestNow('2026-05-08T10:15:30Z');
    }

    public function test_webhook_to_domain_event_full_pipeline(): void
    {
        $body = file_get_contents(base_path('tests/Fixtures/delicity/order_created.json'));
        $sig  = $this->signed($body);

        $response = $this->call(
            'POST',
            '/api/webhooks/delivery/delicity/order.created',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT'  => 'application/json',
                'HTTP_X_DELICITY_SIGNATURE' => $sig,
            ],
            $body,
        );

        $response->assertStatus(202);

        // (a) FrontendOrder created with platform marker.
        $order = FrontendOrder::withoutGlobalScopes()
            ->where('source_surface', 'delicity')
            ->latest('id')
            ->first();
        $this->assertNotNull($order, 'FrontendOrder must exist after Delicity webhook flow');
        $this->assertSame($this->branch->id, $order->branch_id);
        $this->assertSame('dp:delicity:delicity-order-9876', $order->idempotency_key);

        // (b) domain_events outbox row.
        $domainEvent = DomainEvent::query()
            ->where('aggregate_id', $order->id)
            ->where('broadcast_as', 'OrderCreated')
            ->first();
        $this->assertNotNull(
            $domainEvent,
            'OrderCreated must produce a domain_events row (outbox)'
        );

        // (c) Branch isolation channel.
        $channels = json_decode($domainEvent->channel, true);
        $this->assertIsArray($channels);
        $this->assertContains('private-branch.' . $order->branch_id, $channels);
    }

    private function signed(string $body): string
    {
        $timestamp = Carbon::now()->getTimestamp();
        $expected  = hash_hmac('sha256', $timestamp . '.' . $body, $this->webhookSecret);
        return sprintf('t=%d,v1=%s', $timestamp, $expected);
    }
}
