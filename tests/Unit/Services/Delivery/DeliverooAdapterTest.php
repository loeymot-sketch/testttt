<?php

namespace Tests\Unit\Services\Delivery;

use App\Domain\Delivery\Exceptions\OrderMappingException;
use App\Domain\Delivery\NormalizedDeliveryOrder;
use App\Domain\Delivery\PushResult;
use App\Enums\OrderStatus;
use App\Models\DeliveryPlatform;
use App\Services\Delivery\Adapters\DeliverooAdapter;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * [PARALLEL-TRACK-1.3 / Delivery Platform Integration — Phase 3]
 *
 * Pure-unit coverage for the Deliveroo wire format. No DB, no HTTP except
 * Http::fake() probes around pushStatus.
 *
 * Replay-via-sequence-GUID is intentionally NOT tested here: the
 * VerifyDeliverySignature middleware owns that responsibility — the adapter
 * itself only verifies the body HMAC. The middleware-level replay test
 * lives under Tests\Feature\Delivery (existing Phase-2 coverage applies to
 * Deliveroo via the same middleware code path).
 */
class DeliverooAdapterTest extends TestCase
{
    private DeliverooAdapter $adapter;
    private array $createFixture;
    private array $cancelFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->adapter = new DeliverooAdapter();

        $this->createFixture = $this->loadFixture('order_created.json');
        $this->cancelFixture = $this->loadFixture('order_cancelled.json');
    }

    public function test_parse_creates_dto_from_fixture(): void
    {
        $dto = $this->adapter->parseOrder($this->createFixture, branchId: 1);

        $this->assertInstanceOf(NormalizedDeliveryOrder::class, $dto);

        // Customer
        $this->assertSame('Henri Becquerel', $dto->customer['name']);
        $this->assertSame('+33611112233', $dto->customer['phone']);

        // Address (delivery)
        $this->assertSame('Paris', $dto->address['city']);
        $this->assertSame('FR', $dto->address['country']);
        $this->assertSame('75009', $dto->address['zip']);
        $this->assertEquals(48.876111, $dto->address['latitude']);

        // Items (cents — Deliveroo sends minor units already)
        $this->assertCount(2, $dto->items);
        $this->assertSame('FK-BURGER-CLASSIC', $dto->items[0]->external_sku);
        $this->assertSame('Hamburger classic', $dto->items[0]->name);
        $this->assertSame(2, $dto->items[0]->qty);
        $this->assertSame(720, $dto->items[0]->unit_price_cents);
        $this->assertSame('FK-DRINK-COKE', $dto->items[1]->external_sku);
        $this->assertSame(280, $dto->items[1]->unit_price_cents);

        // Modifiers normalised
        $this->assertCount(1, $dto->items[0]->modifiers);
        $this->assertSame('FK-EXTRA-CHEESE', $dto->items[0]->modifiers[0]['sku']);
        $this->assertSame('Extras', $dto->items[0]->modifiers[0]['group']);
        $this->assertSame(100, $dto->items[0]->modifiers[0]['unit_price_cents']);

        // Totals (cents)
        $this->assertSame(2100, $dto->totals['subtotal']);
        $this->assertSame(199,  $dto->totals['delivery_charge']);
        $this->assertSame(2299, $dto->totals['total']);

        // Pickup type
        $this->assertSame('delivery', $dto->pickup_type);

        // Timestamps via Carbon
        $this->assertInstanceOf(Carbon::class, $dto->placed_at);
        $this->assertSame('2026-05-08T10:15:00+00:00', $dto->placed_at->toIso8601String());
        $this->assertInstanceOf(Carbon::class, $dto->expected_ready_at);
    }

    public function test_parse_throws_on_empty_payload(): void
    {
        $this->expectException(OrderMappingException::class);
        $this->adapter->parseOrder([], branchId: 1);
    }

    public function test_external_id_extracts_from_data_order_id(): void
    {
        $this->assertSame(
            'deliveroo-order-1234-5678',
            $this->adapter->externalIdFrom($this->createFixture)
        );
    }

    public function test_external_id_falls_back_to_top_level(): void
    {
        $payload = ['order_id' => 'top-level-fallback'];
        $this->assertSame('top-level-fallback', $this->adapter->externalIdFrom($payload));
    }

    public function test_external_id_throws_on_empty_payload(): void
    {
        $this->expectException(OrderMappingException::class);
        $this->adapter->externalIdFrom([]);
    }

    public function test_event_type_classifies_create_and_cancel(): void
    {
        $this->assertSame('order.created',   $this->adapter->eventTypeFrom($this->createFixture));
        $this->assertSame('order.cancelled', $this->adapter->eventTypeFrom($this->cancelFixture));
    }

    public function test_event_type_returns_unknown_when_neither(): void
    {
        $payload = ['event' => 'courier.assigned', 'data' => ['status' => 'in_progress']];
        $this->assertSame('order.unknown', $this->adapter->eventTypeFrom($payload));
    }

    public function test_verify_signature_valid(): void
    {
        $body   = json_encode(['data' => ['order_id' => 'x']]);
        $secret = 'whsec_deliveroo_test_padding_padding';
        // Deliveroo signs the body directly — no timestamp prefix.
        $expected = hash_hmac('sha256', $body, $secret);

        $request = Request::create('/x', 'POST', [], [], [], [
            'HTTP_X_DELIVEROO_HMAC_SHA256' => $expected,
        ], $body);

        $this->assertTrue($this->adapter->verifySignature($request, $body, $secret));
    }

    public function test_verify_signature_rejects_byte_flipped_hash(): void
    {
        $body   = '{"x":1}';
        $secret = 'whsec_deliveroo_test_padding_padding';
        $expected = hash_hmac('sha256', $body, $secret);
        // Flip first hex char while keeping length valid.
        $tampered = ($expected[0] === '0' ? '1' : '0') . substr($expected, 1);

        $request = Request::create('/x', 'POST', [], [], [], [
            'HTTP_X_DELIVEROO_HMAC_SHA256' => $tampered,
        ], $body);

        $this->assertFalse($this->adapter->verifySignature($request, $body, $secret));
    }

    public function test_verify_signature_rejects_missing_header(): void
    {
        $request = Request::create('/x', 'POST', [], [], [], [], '{"x":1}');
        $this->assertFalse($this->adapter->verifySignature($request, '{"x":1}', 'secret'));
    }

    public function test_verify_signature_rejects_malformed_header(): void
    {
        $request = Request::create('/x', 'POST', [], [], [], [
            'HTTP_X_DELIVEROO_HMAC_SHA256' => 'not-hex-not-64chars',
        ], '{"x":1}');
        $this->assertFalse($this->adapter->verifySignature($request, '{"x":1}', 'secret'));
    }

    public function test_verify_signature_rejects_wrong_secret(): void
    {
        $body = '{"data":{"order_id":"x"}}';
        $expected = hash_hmac('sha256', $body, 'wrong_secret');

        $request = Request::create('/x', 'POST', [], [], [], [
            'HTTP_X_DELIVEROO_HMAC_SHA256' => $expected,
        ], $body);

        $this->assertFalse($this->adapter->verifySignature($request, $body, 'real_secret'));
    }

    public function test_map_internal_status_complete_table(): void
    {
        $this->assertSame('accepted',             $this->adapter->mapInternalStatus(OrderStatus::ACCEPT));
        $this->assertSame('in_preparation',       $this->adapter->mapInternalStatus(OrderStatus::PREPARING));
        $this->assertSame('ready_for_collection', $this->adapter->mapInternalStatus(OrderStatus::PREPARED));
        $this->assertSame('cancelled',            $this->adapter->mapInternalStatus(OrderStatus::CANCELED));
        $this->assertSame('cancelled',            $this->adapter->mapInternalStatus(OrderStatus::REJECTED));

        // Statuses with no Deliveroo equivalent → null.
        $this->assertNull($this->adapter->mapInternalStatus(OrderStatus::PENDING));
        $this->assertNull($this->adapter->mapInternalStatus(OrderStatus::OUT_FOR_DELIVERY));
        $this->assertNull($this->adapter->mapInternalStatus(OrderStatus::DELIVERED));
        $this->assertNull($this->adapter->mapInternalStatus(OrderStatus::RETURNED));
        $this->assertNull($this->adapter->mapInternalStatus(99999));
    }

    public function test_pickup_type_customer_pickup(): void
    {
        $payload = $this->createFixture;
        $payload['data']['fulfillment_type'] = 'collection';

        $dto = $this->adapter->parseOrder($payload, branchId: 1);
        $this->assertSame('customer_pickup', $dto->pickup_type);
    }

    public function test_push_status_returns_success_on_2xx(): void
    {
        Http::fake([
            'api.deliveroo.com/*' => Http::response(['status' => 'accepted'], 200),
        ]);

        $cfg = new DeliveryPlatform();
        $cfg->credentials = ['api_key' => 'tok_dlv_xxx'];

        $result = $this->adapter->pushStatus($cfg, 'order-123', OrderStatus::ACCEPT);

        $this->assertInstanceOf(PushResult::class, $result);
        $this->assertTrue($result->success);
        $this->assertSame(200, $result->http_status);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/orders/order-123/sync_status')
                && $request['status'] === 'accepted';
        });
    }

    public function test_push_status_returns_failure_for_unmapped_status(): void
    {
        $cfg = new DeliveryPlatform();
        $cfg->credentials = ['api_key' => 'tok'];

        $result = $this->adapter->pushStatus($cfg, 'order-x', OrderStatus::PENDING);

        $this->assertFalse($result->success);
        $this->assertNull($result->http_status);
        $this->assertStringContainsString('Deliveroo mapping', (string) $result->error);
    }

    public function test_push_status_returns_failure_when_credentials_missing(): void
    {
        $cfg = new DeliveryPlatform();
        $cfg->credentials = [];

        $result = $this->adapter->pushStatus($cfg, 'order-x', OrderStatus::ACCEPT);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('api_key', (string) $result->error);
    }

    private function loadFixture(string $name): array
    {
        $path = __DIR__ . '/../../../Fixtures/deliveroo/' . $name;
        $json = file_get_contents($path);
        $this->assertNotFalse($json, "Failed to read fixture {$name}");
        return json_decode($json, true);
    }
}
