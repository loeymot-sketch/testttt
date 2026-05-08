<?php

namespace Tests\Unit\Services\Delivery;

use App\Domain\Delivery\Exceptions\OrderMappingException;
use App\Domain\Delivery\NormalizedDeliveryOrder;
use App\Domain\Delivery\PushResult;
use App\Enums\OrderStatus;
use App\Models\DeliveryPlatform;
use App\Services\Delivery\Adapters\DelicityAdapter;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * [PARALLEL-TRACK-1.3 / Delivery Platform Integration — Phase 3]
 *
 * Pure-unit coverage for the Delicity wire format. Mirrors UberEatsAdapter
 * pattern (timestamp window + Stripe-style header).
 */
class DelicityAdapterTest extends TestCase
{
    private DelicityAdapter $adapter;
    private array $createFixture;
    private array $cancelFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->adapter = new DelicityAdapter();

        $this->createFixture = $this->loadFixture('order_created.json');
        $this->cancelFixture = $this->loadFixture('order_cancelled.json');
    }

    public function test_parse_creates_dto_from_fixture(): void
    {
        $dto = $this->adapter->parseOrder($this->createFixture, branchId: 1);

        $this->assertInstanceOf(NormalizedDeliveryOrder::class, $dto);

        // Customer
        $this->assertSame('Pierre de Fermat', $dto->customer['name']);
        $this->assertSame('+33655667788', $dto->customer['phone']);

        // Address
        $this->assertSame('Paris', $dto->address['city']);
        $this->assertSame('FR', $dto->address['country']);
        $this->assertSame('75009', $dto->address['zip']);
        $this->assertEquals(48.876389, $dto->address['latitude']);

        // Items — euros converted to cents at the boundary.
        $this->assertCount(2, $dto->items);
        $this->assertSame('FK-MENU-MAXI', $dto->items[0]->external_sku);
        $this->assertSame('Menu Maxi Burger', $dto->items[0]->name);
        $this->assertSame(1, $dto->items[0]->qty);
        $this->assertSame(1250, $dto->items[0]->unit_price_cents);
        $this->assertSame('FK-DESSERT-COOKIE', $dto->items[1]->external_sku);
        $this->assertSame(2, $dto->items[1]->qty);
        $this->assertSame(220, $dto->items[1]->unit_price_cents);

        // Modifiers (also euros → cents)
        $this->assertCount(1, $dto->items[0]->modifiers);
        $this->assertSame('FK-SAUCE-BBQ', $dto->items[0]->modifiers[0]['sku']);
        $this->assertSame(50, $dto->items[0]->modifiers[0]['unit_price_cents']);

        // Totals (cents)
        $this->assertSame(1740, $dto->totals['subtotal']);
        $this->assertSame(199,  $dto->totals['delivery_charge']);
        $this->assertSame(1939, $dto->totals['total']);

        // Pickup type
        $this->assertSame('delivery', $dto->pickup_type);

        // Timestamps
        $this->assertInstanceOf(Carbon::class, $dto->placed_at);
        $this->assertSame('2026-05-08T10:15:00+00:00', $dto->placed_at->toIso8601String());
        $this->assertInstanceOf(Carbon::class, $dto->expected_ready_at);
    }

    public function test_parse_throws_on_empty_payload(): void
    {
        $this->expectException(OrderMappingException::class);
        $this->adapter->parseOrder([], branchId: 1);
    }

    public function test_external_id_extracts_from_data_id(): void
    {
        $this->assertSame(
            'delicity-order-9876',
            $this->adapter->externalIdFrom($this->createFixture)
        );
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
        Carbon::setTestNow('2026-05-08T10:15:30Z');

        $body      = json_encode(['data' => ['id' => 'x']]);
        $secret    = 'whsec_delicity_test_padding_padding';
        $timestamp = Carbon::now()->getTimestamp();
        $expected  = hash_hmac('sha256', $timestamp . '.' . $body, $secret);
        $header    = sprintf('t=%d,v1=%s', $timestamp, $expected);

        $request = Request::create('/x', 'POST', [], [], [], [
            'HTTP_X_DELICITY_SIGNATURE' => $header,
        ], $body);

        $this->assertTrue($this->adapter->verifySignature($request, $body, $secret));
    }

    public function test_verify_signature_rejects_byte_flipped_hash(): void
    {
        Carbon::setTestNow('2026-05-08T10:15:30Z');

        $body      = '{"x":1}';
        $secret    = 'whsec_delicity_test_padding_padding';
        $timestamp = Carbon::now()->getTimestamp();
        $expected  = hash_hmac('sha256', $timestamp . '.' . $body, $secret);
        $tampered  = ($expected[0] === '0' ? '1' : '0') . substr($expected, 1);
        $header    = sprintf('t=%d,v1=%s', $timestamp, $tampered);

        $request = Request::create('/x', 'POST', [], [], [], [
            'HTTP_X_DELICITY_SIGNATURE' => $header,
        ], $body);

        $this->assertFalse($this->adapter->verifySignature($request, $body, $secret));
    }

    public function test_verify_signature_rejects_expired_timestamp(): void
    {
        Carbon::setTestNow('2026-05-08T10:15:30Z');

        $body      = '{"x":1}';
        $secret    = 'whsec_delicity_test_padding_padding';
        // Sign with a timestamp 600s in the past — outside the default 300s window.
        $timestamp = Carbon::now()->subSeconds(600)->getTimestamp();
        $expected  = hash_hmac('sha256', $timestamp . '.' . $body, $secret);
        $header    = sprintf('t=%d,v1=%s', $timestamp, $expected);

        $request = Request::create('/x', 'POST', [], [], [], [
            'HTTP_X_DELICITY_SIGNATURE' => $header,
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
            'HTTP_X_DELICITY_SIGNATURE' => 'not-a-valid-format',
        ], '{"x":1}');
        $this->assertFalse($this->adapter->verifySignature($request, '{"x":1}', 'secret'));
    }

    public function test_map_internal_status_complete_table(): void
    {
        $this->assertSame('accepted',         $this->adapter->mapInternalStatus(OrderStatus::ACCEPT));
        $this->assertSame('preparing',        $this->adapter->mapInternalStatus(OrderStatus::PREPARING));
        $this->assertSame('ready',            $this->adapter->mapInternalStatus(OrderStatus::PREPARED));
        $this->assertSame('out_for_delivery', $this->adapter->mapInternalStatus(OrderStatus::OUT_FOR_DELIVERY));
        $this->assertSame('cancelled',        $this->adapter->mapInternalStatus(OrderStatus::CANCELED));
        $this->assertSame('cancelled',        $this->adapter->mapInternalStatus(OrderStatus::REJECTED));

        // No mapping for these.
        $this->assertNull($this->adapter->mapInternalStatus(OrderStatus::PENDING));
        $this->assertNull($this->adapter->mapInternalStatus(OrderStatus::DELIVERED));
        $this->assertNull($this->adapter->mapInternalStatus(OrderStatus::RETURNED));
        $this->assertNull($this->adapter->mapInternalStatus(99999));
    }

    public function test_pickup_type_customer_pickup(): void
    {
        $payload = $this->createFixture;
        $payload['data']['pickup_type'] = 'takeaway';

        $dto = $this->adapter->parseOrder($payload, branchId: 1);
        $this->assertSame('customer_pickup', $dto->pickup_type);
    }

    public function test_push_status_returns_success_on_2xx(): void
    {
        Http::fake([
            'api.delicity.com/*' => Http::response(['ok' => true], 200),
        ]);

        $cfg = new DeliveryPlatform();
        $cfg->credentials = ['api_key' => 'tok_dlc_xxx'];

        $result = $this->adapter->pushStatus($cfg, 'order-zzz', OrderStatus::PREPARING);

        $this->assertInstanceOf(PushResult::class, $result);
        $this->assertTrue($result->success);
        $this->assertSame(200, $result->http_status);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/v1/orders/order-zzz/status')
                && $request['status'] === 'preparing';
        });
    }

    public function test_push_status_returns_failure_for_unmapped_status(): void
    {
        $cfg = new DeliveryPlatform();
        $cfg->credentials = ['api_key' => 'tok'];

        $result = $this->adapter->pushStatus($cfg, 'order-x', OrderStatus::PENDING);

        $this->assertFalse($result->success);
        $this->assertNull($result->http_status);
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
        $path = __DIR__ . '/../../../Fixtures/delicity/' . $name;
        $json = file_get_contents($path);
        $this->assertNotFalse($json, "Failed to read fixture {$name}");
        return json_decode($json, true);
    }
}
