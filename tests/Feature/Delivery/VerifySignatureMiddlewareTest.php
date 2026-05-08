<?php

namespace Tests\Feature\Delivery;

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
 * Trust-boundary tests for VerifyDeliverySignature.
 *
 * Every failure path returns 202 (mirroring the success shape) and
 * persists a forensic row with `signature_valid=false`. The middleware
 * never returns 401/403 — that would leak "branch exists" / "secret
 * shape valid" to a probing attacker.
 */
class VerifySignatureMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;
    private DeliveryPlatform $platform;
    private string $webhookSecret = 'whsec_padding_padding_padding_padding_xxx';

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
        Cache::flush();

        $this->branch = Branch::factory()->create();
        $this->platform = DeliveryPlatform::create([
            'branch_id'         => $this->branch->id,
            'platform'          => 'uber_eats',
            'enabled'           => true,
            'external_store_id' => 'store_uuid_42',
            'credentials'       => ['webhook_secret' => $this->webhookSecret, 'api_key' => 'ue_key'],
        ]);

        Carbon::setTestNow('2026-05-08T10:15:30Z');
    }

    public function test_bad_signature_returns_202_and_persists_forensic_row(): void
    {
        $body = $this->orderCreatedFixture();
        $timestamp = Carbon::now()->getTimestamp();
        $tampered = str_repeat('0', 64); // valid hex shape, wrong HMAC.
        $header = sprintf('v=1,t=%d,k=key_1,h=%s', $timestamp, $tampered);

        $response = $this->postJson(
            '/api/webhooks/delivery/uber_eats/order.created',
            json_decode($body, true),
            ['X-Uber-Signature' => $header],
        );

        $response->assertStatus(202);

        // Forensic row persisted with signature_valid=false.
        $row = DeliveryWebhookEvent::where('platform', 'uber_eats')
            ->where('signature_valid', false)
            ->latest('id')
            ->first();
        $this->assertNotNull($row, 'expected a forensic row for the bad signature');
        $this->assertSame('bad_signature', $row->processing_error);

        // No queue dispatch — controller never ran.
        Bus::assertNotDispatched(\App\Jobs\ProcessDeliveryPlatformWebhookJob::class);
    }

    public function test_good_signature_passes_through_to_controller(): void
    {
        $body = $this->orderCreatedFixture();
        $timestamp = Carbon::now()->getTimestamp();
        $expected = hash_hmac('sha256', $timestamp . '.' . $body, $this->webhookSecret);
        $header = sprintf('v=1,t=%d,k=key_1,h=%s', $timestamp, $expected);

        $response = $this->call(
            'POST',
            '/api/webhooks/delivery/uber_eats/order.created',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT'  => 'application/json',
                'HTTP_X_UBER_SIGNATURE' => $header,
            ],
            $body,
        );

        $response->assertStatus(202);
        $response->assertJson(['status' => 'queued']);

        // The controller should have created exactly one webhook event row,
        // tagged signature_valid=true, branch_id resolved.
        $row = DeliveryWebhookEvent::where('platform', 'uber_eats')
            ->where('signature_valid', true)
            ->latest('id')
            ->first();
        $this->assertNotNull($row);
        $this->assertSame($this->branch->id, $row->branch_id);

        Bus::assertDispatched(\App\Jobs\ProcessDeliveryPlatformWebhookJob::class);
    }

    public function test_replay_nonce_is_swallowed(): void
    {
        $body = $this->orderCreatedFixture();
        $timestamp = Carbon::now()->getTimestamp();
        $expected = hash_hmac('sha256', $timestamp . '.' . $body, $this->webhookSecret);
        $header = sprintf('v=1,t=%d,k=key_1,h=%s', $timestamp, $expected);

        // First call lands fine.
        $first = $this->call('POST', '/api/webhooks/delivery/uber_eats/order.created', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT'  => 'application/json',
            'HTTP_X_UBER_SIGNATURE' => $header,
        ], $body);
        $first->assertStatus(202);

        // Second call (identical body+sig+timestamp): replay defense kicks in
        // BEFORE the controller. Response is 202 but no new persisted row.
        $countBefore = DeliveryWebhookEvent::count();
        $second = $this->call('POST', '/api/webhooks/delivery/uber_eats/order.created', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT'  => 'application/json',
            'HTTP_X_UBER_SIGNATURE' => $header,
        ], $body);
        $second->assertStatus(202);

        $this->assertSame(
            $countBefore,
            DeliveryWebhookEvent::count(),
            'replay nonce must NOT trigger an additional persisted row'
        );
    }

    public function test_unknown_store_id_returns_202_no_info_leak(): void
    {
        // Mutate the body so the embedded store id is not in our DB.
        $payload = json_decode($this->orderCreatedFixture(), true);
        $payload['data']['store']['id'] = 'unknown_store_999';
        $body = json_encode($payload);

        $timestamp = Carbon::now()->getTimestamp();
        // Sign with a "valid" secret format — but the middleware can't even
        // get to verifySignature because the lookup fails first.
        $expected = hash_hmac('sha256', $timestamp . '.' . $body, $this->webhookSecret);
        $header = sprintf('v=1,t=%d,k=key_1,h=%s', $timestamp, $expected);

        $response = $this->call('POST', '/api/webhooks/delivery/uber_eats/order.created', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT'  => 'application/json',
            'HTTP_X_UBER_SIGNATURE' => $header,
        ], $body);

        $response->assertStatus(202);
        // Forensic row tagged unknown_store_id.
        $row = DeliveryWebhookEvent::where('platform', 'uber_eats')
            ->where('signature_valid', false)
            ->latest('id')
            ->first();
        $this->assertNotNull($row);
        $this->assertSame('unknown_store_id', $row->processing_error);
    }

    public function test_malformed_json_returns_202_and_logs_event(): void
    {
        $response = $this->call('POST', '/api/webhooks/delivery/uber_eats/order.created', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT'  => 'application/json',
            'HTTP_X_UBER_SIGNATURE' => 'v=1,t=1,k=key_1,h=' . str_repeat('0', 64),
        ], '{not valid json');

        $response->assertStatus(202);

        $row = DeliveryWebhookEvent::where('platform', 'uber_eats')
            ->where('signature_valid', false)
            ->latest('id')
            ->first();
        $this->assertNotNull($row);
        $this->assertSame('malformed_json', $row->processing_error);
    }

    private function orderCreatedFixture(): string
    {
        $path = base_path('tests/Fixtures/uber_eats/order_created.json');
        return file_get_contents($path);
    }
}
