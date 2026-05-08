<?php

namespace Tests\Unit\Services\Delivery;

use App\Domain\Delivery\Exceptions\OrderMappingException;
use App\Domain\Delivery\NormalizedDeliveryOrder;
use App\Domain\Delivery\PushResult;
use App\Enums\OrderStatus;
use App\Models\DeliveryPlatform;
use App\Services\Delivery\Adapters\UberEatsAdapter;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * [PARALLEL-TRACK-1.2 / Delivery Platform Integration — Phase 2]
 *
 * Pure-unit coverage for the Uber Eats wire format. No DB, no HTTP.
 * Fixture-driven so any future drift in the platform schema is caught
 * by a failing snapshot rather than a runtime mapping exception.
 */
class UberEatsAdapterTest extends TestCase
{
    private UberEatsAdapter $adapter;
    private array $createFixture;
    private array $cancelFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->adapter = new UberEatsAdapter();

        $this->createFixture = $this->loadFixture('order_created.json');
        $this->cancelFixture = $this->loadFixture('order_cancelled.json');
    }

    public function test_parse_creates_dto_from_fixture(): void
    {
        $dto = $this->adapter->parseOrder($this->createFixture, branchId: 1);

        $this->assertInstanceOf(NormalizedDeliveryOrder::class, $dto);

        // Customer
        $this->assertSame('Marie Curie', $dto->customer['name']);
        $this->assertSame('+33612345678', $dto->customer['phone']);

        // Address
        $this->assertSame('Paris', $dto->address['city']);
        $this->assertSame('FR', $dto->address['country']);
        $this->assertSame('75002', $dto->address['zip']);
        $this->assertEquals(48.870556, $dto->address['latitude']);

        // Items (cents)
        $this->assertCount(2, $dto->items);
        $this->assertSame('FK-BURGER-CHEESE', $dto->items[0]->external_sku);
        $this->assertSame('Cheeseburger', $dto->items[0]->name);
        $this->assertSame(2, $dto->items[0]->qty);
        $this->assertSame(850, $dto->items[0]->unit_price_cents);
        $this->assertSame('FK-FRIES-MED', $dto->items[1]->external_sku);
        $this->assertSame(450, $dto->items[1]->unit_price_cents);

        // Modifiers carried through from selected_modifier_groups
        $this->assertCount(1, $dto->items[0]->modifiers);
        $this->assertSame('FK-SAUCE-KETCHUP', $dto->items[0]->modifiers[0]['sku']);

        // Totals (cents)
        $this->assertSame(2150, $dto->totals['subtotal']);
        $this->assertSame(250, $dto->totals['delivery_charge']);
        $this->assertSame(2615, $dto->totals['total']);

        // Pickup type
        $this->assertSame('delivery', $dto->pickup_type);

        // Timestamps via Carbon
        $this->assertInstanceOf(Carbon::class, $dto->placed_at);
        $this->assertSame('2026-05-08T10:15:00+00:00', $dto->placed_at->toIso8601String());
        $this->assertInstanceOf(Carbon::class, $dto->expected_ready_at);
    }

    public function test_parse_throws_on_missing_data_envelope(): void
    {
        $this->expectException(OrderMappingException::class);
        $this->adapter->parseOrder(['event_type' => 'orders.notification'], branchId: 1);
    }

    public function test_external_id_extracts_from_data_id(): void
    {
        $this->assertSame(
            '87a2b9b8-1111-2222-3333-444455556666',
            $this->adapter->externalIdFrom($this->createFixture)
        );
    }

    public function test_external_id_falls_back_to_meta_resource_id(): void
    {
        $payload = ['meta' => ['resource_id' => 'resource-fallback-1']];
        $this->assertSame('resource-fallback-1', $this->adapter->externalIdFrom($payload));
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

    public function test_event_type_returns_unknown_when_neither_create_nor_cancel(): void
    {
        $payload = ['event_type' => 'courier.assigned', 'data' => ['order_state' => 'in_progress']];
        $this->assertSame('order.unknown', $this->adapter->eventTypeFrom($payload));
    }

    public function test_verify_signature_valid(): void
    {
        Carbon::setTestNow('2026-05-08T10:15:30Z');

        $body      = json_encode(['event_type' => 'orders.notification', 'data' => ['id' => 'x']]);
        $secret    = 'whsec_test_padding_padding_padding_padding';
        $timestamp = Carbon::now()->getTimestamp();
        $expected  = hash_hmac('sha256', $timestamp . '.' . $body, $secret);
        $header    = sprintf('v=1,t=%d,k=key_1,h=%s', $timestamp, $expected);

        $request = Request::create('/api/webhooks/delivery/uber_eats/order.created', 'POST', [], [], [], [
            'HTTP_X_UBER_SIGNATURE' => $header,
        ], $body);

        $this->assertTrue($this->adapter->verifySignature($request, $body, $secret));
    }

    public function test_verify_signature_rejects_byte_flipped_hash(): void
    {
        Carbon::setTestNow('2026-05-08T10:15:30Z');

        $body      = '{"x":1}';
        $secret    = 'whsec_test_padding_padding_padding_padding';
        $timestamp = Carbon::now()->getTimestamp();
        $expected  = hash_hmac('sha256', $timestamp . '.' . $body, $secret);
        // Flip first hex char — keeps length+charset valid but breaks HMAC
        $tampered = ($expected[0] === '0' ? '1' : '0') . substr($expected, 1);
        $header    = sprintf('v=1,t=%d,k=key_1,h=%s', $timestamp, $tampered);

        $request = Request::create('/x', 'POST', [], [], [], [
            'HTTP_X_UBER_SIGNATURE' => $header,
        ], $body);

        $this->assertFalse($this->adapter->verifySignature($request, $body, $secret));
    }

    public function test_verify_signature_rejects_expired_timestamp(): void
    {
        Carbon::setTestNow('2026-05-08T10:15:30Z');

        $body      = '{"x":1}';
        $secret    = 'whsec_test_padding_padding_padding_padding';
        // Sign with a timestamp 400s in the past — outside default 300s window.
        $timestamp = Carbon::now()->subSeconds(400)->getTimestamp();
        $expected  = hash_hmac('sha256', $timestamp . '.' . $body, $secret);
        $header    = sprintf('v=1,t=%d,k=key_1,h=%s', $timestamp, $expected);

        $request = Request::create('/x', 'POST', [], [], [], [
            'HTTP_X_UBER_SIGNATURE' => $header,
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
            'HTTP_X_UBER_SIGNATURE' => 'not-a-valid-uber-format',
        ], '{"x":1}');
        $this->assertFalse($this->adapter->verifySignature($request, '{"x":1}', 'secret'));
    }

    public function test_map_internal_status_complete_table(): void
    {
        $this->assertSame('ACCEPTED',           $this->adapter->mapInternalStatus(OrderStatus::ACCEPT));
        $this->assertSame('IN_PREPARATION',     $this->adapter->mapInternalStatus(OrderStatus::PREPARING));
        $this->assertSame('READY_FOR_PICKUP',   $this->adapter->mapInternalStatus(OrderStatus::PREPARED));
        $this->assertSame('RESTAURANT_REJECTED',$this->adapter->mapInternalStatus(OrderStatus::CANCELED));

        // Statuses with no Uber equivalent → null (caller must NOT push).
        $this->assertNull($this->adapter->mapInternalStatus(OrderStatus::PENDING));
        $this->assertNull($this->adapter->mapInternalStatus(OrderStatus::OUT_FOR_DELIVERY));
        $this->assertNull($this->adapter->mapInternalStatus(OrderStatus::DELIVERED));
        $this->assertNull($this->adapter->mapInternalStatus(OrderStatus::REJECTED));
        $this->assertNull($this->adapter->mapInternalStatus(OrderStatus::RETURNED));
        $this->assertNull($this->adapter->mapInternalStatus(99999)); // unknown int
    }

    /**
     * Pickup-type discriminator: when fulfillment_type ≠ delivery the DTO
     * surfaces 'customer_pickup' so the IngestionService skips the
     * delivery_charge column and never persists an OrderAddress row.
     */
    public function test_pickup_type_customer_pickup(): void
    {
        $payload = $this->createFixture;
        $payload['data']['fulfillment_type'] = 'PICKUP_BY_CUSTOMER';
        $payload['data']['deliveries'] = [];

        $dto = $this->adapter->parseOrder($payload, branchId: 1);
        $this->assertSame('customer_pickup', $dto->pickup_type);
        $this->assertSame([], $dto->address);
    }

    public function test_push_status_returns_success_on_2xx_response(): void
    {
        Http::fake([
            'api.uber.com/*' => Http::response(['status' => 'ACCEPTED'], 200),
        ]);

        $cfg = new DeliveryPlatform();
        $cfg->credentials = ['api_key' => 'test_token_xxx'];

        $result = $this->adapter->pushStatus($cfg, 'order-123', OrderStatus::ACCEPT);

        $this->assertInstanceOf(PushResult::class, $result);
        $this->assertTrue($result->success);
        $this->assertSame(200, $result->http_status);
    }

    public function test_push_status_returns_failure_for_unmapped_internal_status(): void
    {
        $cfg = new DeliveryPlatform();
        $cfg->credentials = ['api_key' => 'test_token_xxx'];

        // PENDING has no Uber equivalent — pushStatus must refuse to call.
        $result = $this->adapter->pushStatus($cfg, 'order-123', OrderStatus::PENDING);

        $this->assertFalse($result->success);
        $this->assertNull($result->http_status, 'no HTTP call should have been made');
    }

    public function test_push_status_returns_failure_when_credentials_missing(): void
    {
        $cfg = new DeliveryPlatform();
        $cfg->credentials = []; // no api_key

        $result = $this->adapter->pushStatus($cfg, 'order-123', OrderStatus::ACCEPT);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('api_key', (string) $result->error);
    }

    private function loadFixture(string $name): array
    {
        $path = __DIR__ . '/../../../Fixtures/uber_eats/' . $name;
        $json = file_get_contents($path);
        $this->assertNotFalse($json, "Failed to read fixture {$name}");
        return json_decode($json, true);
    }
}
