<?php

namespace Tests\Feature\Delivery;

use App\Jobs\ProcessDeliveryPlatformWebhookJob;
use App\Models\Branch;
use App\Models\DeliveryPlatform;
use App\Models\DeliveryWebhookEvent;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * [PARALLEL-TRACK-1.2 / Delivery Platform Integration — Phase 2]
 *
 * Behavioural tests for the controller layer: latency target, dispatch
 * shape, and idempotent persistence.
 */
class WebhookEndpointTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;
    private string $webhookSecret = 'whsec_padding_padding_padding_padding_xxx';

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
        Cache::flush();

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

    public function test_post_returns_202_with_event_id_quickly(): void
    {
        $body  = file_get_contents(base_path('tests/Fixtures/uber_eats/order_created.json'));

        // Warm-up call: container resolution, route compile, autoload
        // — measure the second request which mirrors steady-state
        // production where the worker is already JITted and connections
        // are pooled. Use a different store id payload mutation per
        // call so the (platform, nonce) UNIQUE index doesn't collide.
        $payload = json_decode($body, true);
        $payload['data']['id'] = 'warmup-order-id';
        $payload['event_id']   = 'warmup-' . uniqid();
        $warmupBody = json_encode($payload);
        $this->call('POST', '/api/webhooks/delivery/uber_eats/order.created', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT'  => 'application/json',
            'HTTP_X_UBER_SIGNATURE' => $this->signed($warmupBody),
        ], $warmupBody);

        $sig = $this->signed($body);

        $started = microtime(true);
        $response = $this->call('POST', '/api/webhooks/delivery/uber_eats/order.created', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT'  => 'application/json',
            'HTTP_X_UBER_SIGNATURE' => $sig,
        ], $body);
        $elapsedMs = (microtime(true) - $started) * 1000;

        $response->assertStatus(202);
        $response->assertJsonStructure(['event_id', 'status']);
        $response->assertJson(['status' => 'queued']);

        // Controller target ≤ 200ms in production. Tests run with
        // SQLite :memory: + sync queue + Bus::fake(), so the pure
        // controller path is much faster — but a 500ms ceiling absorbs
        // CI jitter.
        $this->assertLessThan(
            500,
            $elapsedMs,
            sprintf('webhook controller must return under 500ms, took %.2fms', $elapsedMs)
        );

        // Surface the measured value so the deliverable report can
        // quote a real latency number rather than "passes the threshold."
        if (getenv('PHPUNIT_VERBOSE_LATENCY') !== false) {
            fwrite(STDERR, sprintf(
                "\n[delivery webhook latency] elapsed=%.2fms\n",
                $elapsedMs
            ));
        }
    }

    public function test_webhook_event_row_is_created_with_resolved_branch(): void
    {
        $body = file_get_contents(base_path('tests/Fixtures/uber_eats/order_created.json'));
        $sig  = $this->signed($body);

        $this->call('POST', '/api/webhooks/delivery/uber_eats/order.created', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT'  => 'application/json',
            'HTTP_X_UBER_SIGNATURE' => $sig,
        ], $body);

        $row = DeliveryWebhookEvent::where('platform', 'uber_eats')
            ->where('signature_valid', true)
            ->latest('id')
            ->first();

        $this->assertNotNull($row);
        $this->assertSame($this->branch->id, $row->branch_id);
        $this->assertSame('order.created', $row->event_type);
        $this->assertIsArray($row->body);
        $this->assertSame('87a2b9b8-1111-2222-3333-444455556666', $row->body['data']['id']);
    }

    public function test_post_dispatches_process_job_on_high_queue(): void
    {
        $body = file_get_contents(base_path('tests/Fixtures/uber_eats/order_created.json'));
        $sig  = $this->signed($body);

        $this->call('POST', '/api/webhooks/delivery/uber_eats/order.created', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT'  => 'application/json',
            'HTTP_X_UBER_SIGNATURE' => $sig,
        ], $body);

        Bus::assertDispatched(ProcessDeliveryPlatformWebhookJob::class, function ($job) {
            return $job->queue === 'high';
        });
    }

    public function test_unknown_store_id_returns_202_without_persisting_signature_valid_row(): void
    {
        $payload = json_decode(file_get_contents(base_path('tests/Fixtures/uber_eats/order_created.json')), true);
        $payload['data']['store']['id'] = 'no-such-store';
        $body = json_encode($payload);
        $sig  = $this->signed($body);

        $response = $this->call('POST', '/api/webhooks/delivery/uber_eats/order.created', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT'  => 'application/json',
            'HTTP_X_UBER_SIGNATURE' => $sig,
        ], $body);
        $response->assertStatus(202);

        $this->assertSame(
            0,
            DeliveryWebhookEvent::where('platform', 'uber_eats')->where('signature_valid', true)->count(),
            'must not record a signature_valid row for an unknown store id'
        );
    }

    public function test_malformed_json_body_does_not_500(): void
    {
        $response = $this->call('POST', '/api/webhooks/delivery/uber_eats/order.created', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT'  => 'application/json',
            'HTTP_X_UBER_SIGNATURE' => 'v=1,t=1,k=key_1,h=' . str_repeat('0', 64),
        ], '{this is not valid json');

        $response->assertStatus(202);
    }

    private function signed(string $body): string
    {
        $timestamp = Carbon::now()->getTimestamp();
        $expected  = hash_hmac('sha256', $timestamp . '.' . $body, $this->webhookSecret);
        return sprintf('v=1,t=%d,k=key_1,h=%s', $timestamp, $expected);
    }
}
