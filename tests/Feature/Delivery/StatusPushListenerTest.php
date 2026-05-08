<?php

namespace Tests\Feature\Delivery;

use App\Domain\Delivery\PlatformAdapterRegistry;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Enums\Source;
use App\Events\OrderStatusChanged;
use App\Listeners\PushStatusToDeliveryPlatform;
use App\Models\Branch;
use App\Models\DeliveryPlatform;
use App\Models\DeliveryPlatformExternalOrder;
use App\Models\FrontendOrder;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * [PARALLEL-TRACK-1.3 / Delivery Platform Integration — Phase 3]
 *
 * Coverage for the outbound listener PushStatusToDeliveryPlatform.
 *
 * Discipline:
 *   - Http::fake() so no real HTTP escapes.
 *   - Carbon::setTestNow() for deterministic last_pushed_at.
 *   - Tests INVOKE the listener directly (not via Event::dispatch) to
 *     avoid coupling with the EventServiceProvider's listener-list at
 *     suite time. The wiring itself is exercised by E2E tests.
 *   - Sync queue does NOT honour $tries — under QUEUE_CONNECTION=sync a
 *     single throw escapes once. So we assert the static $tries value
 *     and behaviour-level outcomes (DB row + Http::fake call shapes)
 *     rather than trying to walk N retries inline.
 */
class StatusPushListenerTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;
    private DeliveryPlatform $cfg;
    private FrontendOrder $order;
    private DeliveryPlatformExternalOrder $external;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-05-08T10:30:00Z');

        $this->branch = Branch::factory()->create();

        $this->cfg = DeliveryPlatform::create([
            'branch_id'         => $this->branch->id,
            'platform'          => 'uber_eats',
            'enabled'           => true,
            'external_store_id' => 'store_uuid_42',
            'credentials'       => ['webhook_secret' => 'wh', 'api_key' => 'tok_xxx'],
        ]);

        // Set up a delivery-platform-originated FrontendOrder.
        $bot = User::factory()->create([
            'branch_id' => $this->branch->id,
            'email'     => 'delivery-bot+listener@foodking.local',
        ]);

        $this->order = FrontendOrder::create([
            'user_id'        => $bot->id,
            'branch_id'      => $this->branch->id,
            'order_type'     => OrderType::DELIVERY,
            'source'         => Source::DELIVERY_PLATFORM,
            'source_surface' => 'uber_eats',
            'status'         => OrderStatus::ACCEPT,
            'payment_status' => PaymentStatus::PAID,
            'payment_method' => 100,
            'subtotal' => 10, 'discount' => 0, 'delivery_charge' => 1, 'total_tax' => 0, 'total' => 11,
            'order_datetime' => now(),
        ]);

        $this->external = DeliveryPlatformExternalOrder::create([
            'platform'             => 'uber_eats',
            'external_id'          => 'ue-listener-test-1',
            'frontend_order_id'    => $this->order->id,
            'branch_id'            => $this->branch->id,
            'delivery_platform_id' => $this->cfg->id,
            'raw_payload'          => ['data' => ['id' => 'ue-listener-test-1']],
            'received_at'          => now(),
            'ingested_at'          => now(),
            'internal_status'      => OrderStatus::ACCEPT,
            'external_status'      => 'created',
            'push_attempts'        => 0,
        ]);
    }

    public function test_preparing_pushes_in_preparation_to_uber(): void
    {
        Http::fake([
            'api.uber.com/*' => Http::response(['status' => 'IN_PREPARATION'], 200),
        ]);

        $this->invokeListener(OrderStatus::ACCEPT, OrderStatus::PREPARING);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/v1/eats/orders/ue-listener-test-1/status')
                && $request['status'] === 'IN_PREPARATION';
        });

        $this->external->refresh();
        $this->assertSame(OrderStatus::PREPARING, $this->external->internal_status);
        $this->assertSame('IN_PREPARATION', $this->external->external_status);
        $this->assertNotNull($this->external->last_pushed_at);
        $this->assertSame(1, $this->external->push_attempts);
        $this->assertNull($this->external->last_push_error);
    }

    public function test_ignores_orders_without_external_mapping(): void
    {
        Http::fake();

        // A pure-internal order (kiosk) — no external mapping row.
        $kioskUser  = User::factory()->create(['branch_id' => $this->branch->id]);
        $kioskOrder = FrontendOrder::create([
            'user_id'        => $kioskUser->id,
            'branch_id'      => $this->branch->id,
            'order_type'     => OrderType::TAKEAWAY ?? 1,
            'source'         => Source::POS,
            'status'         => OrderStatus::ACCEPT,
            'payment_status' => PaymentStatus::PAID,
            'payment_method' => 1,
            'subtotal' => 10, 'discount' => 0, 'delivery_charge' => 0, 'total_tax' => 0, 'total' => 10,
            'order_datetime' => now(),
        ]);

        $event = new OrderStatusChanged($kioskOrder, OrderStatus::ACCEPT, OrderStatus::PREPARING);
        $this->resolveListener()->handle($event);

        // No HTTP traffic — listener short-circuited.
        Http::assertNothingSent();
    }

    public function test_unmapped_internal_status_does_not_push(): void
    {
        Http::fake();

        // Uber Eats has no mapping for OUT_FOR_DELIVERY (the courier owns
        // that on the platform side). Listener must skip silently.
        $this->invokeListener(OrderStatus::ACCEPT, OrderStatus::OUT_FOR_DELIVERY);

        Http::assertNothingSent();

        // The external row must remain at its pre-event state.
        $this->external->refresh();
        $this->assertSame(OrderStatus::ACCEPT, $this->external->internal_status);
        $this->assertSame(0, $this->external->push_attempts);
    }

    public function test_throws_on_5xx_for_queue_retry(): void
    {
        Http::fake([
            'api.uber.com/*' => Http::response(['error' => 'platform down'], 500),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/transient push failure/');

        try {
            $this->invokeListener(OrderStatus::ACCEPT, OrderStatus::PREPARING);
        } finally {
            // Even though the listener re-throws (queue retry), it MUST have
            // recorded the attempt + error before bailing.
            $this->external->refresh();
            $this->assertSame(1, $this->external->push_attempts);
            $this->assertNotNull($this->external->last_push_error);
            $this->assertNotNull($this->external->last_pushed_at);
        }
    }

    public function test_accepts_4xx_no_retry(): void
    {
        Http::fake([
            'api.uber.com/*' => Http::response(['error' => 'invalid status'], 400),
        ]);

        // 4xx → no exception, listener accepts the platform's verdict and
        // records the error without re-throwing (queue does not retry).
        $this->invokeListener(OrderStatus::ACCEPT, OrderStatus::PREPARING);

        $this->external->refresh();
        $this->assertSame(1, $this->external->push_attempts);
        $this->assertNotNull($this->external->last_push_error);
        $this->assertSame('IN_PREPARATION', $this->external->external_status);
    }

    public function test_respects_disabled_platform(): void
    {
        Http::fake();

        $this->cfg->forceFill(['enabled' => false])->save();

        $this->invokeListener(OrderStatus::ACCEPT, OrderStatus::PREPARING);

        // No traffic, no push_attempts increment — listener bailed at the
        // config check.
        Http::assertNothingSent();

        $this->external->refresh();
        $this->assertSame(0, $this->external->push_attempts);
    }

    public function test_queue_metadata_matches_phase_2_profile(): void
    {
        $listener = $this->resolveListener();
        $this->assertSame(5, $listener->tries);
        $this->assertSame([10, 30, 90, 300, 900], $listener->backoff);
    }

    /**
     * Invoke the listener directly with an OrderStatusChanged event for the
     * canonical test order. Returns the listener so callers can chain
     * assertions on its state if needed.
     */
    private function invokeListener(int $oldStatus, int $newStatus): PushStatusToDeliveryPlatform
    {
        $event = new OrderStatusChanged($this->order, $oldStatus, $newStatus);
        $listener = $this->resolveListener();
        $listener->handle($event);
        return $listener;
    }

    private function resolveListener(): PushStatusToDeliveryPlatform
    {
        return new PushStatusToDeliveryPlatform(app(PlatformAdapterRegistry::class));
    }
}
