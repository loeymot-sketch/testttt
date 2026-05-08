<?php

namespace Tests\Feature\Delivery;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Enums\Source;
use App\Models\Branch;
use App\Models\DeliveryPlatform;
use App\Models\DeliveryPlatformExternalOrder;
use App\Models\DeliveryWebhookEvent;
use App\Models\FrontendOrder;
use App\Models\OrderStatusTransition;
use App\Models\User;
use App\Services\Delivery\DeliveryOrderCancellationService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * [PARALLEL-TRACK-1.3 / Delivery Platform Integration — Phase 3]
 *
 * Cancel-route coverage. The Phase-2 IngestionService skips non-create
 * events; cancellations land in the new DeliveryOrderCancellationService
 * which transitions the existing FrontendOrder via OrderStateMachine::apply
 * and records the cancellation reason in `order_status_transitions`.
 */
class CancelOrderTest extends TestCase
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
            'platform'          => 'deliveroo',
            'enabled'           => true,
            'external_store_id' => 'fk-paris-republique',
            'credentials'       => ['webhook_secret' => 'wh', 'api_key' => 'k'],
        ]);

        $bot = User::factory()->create([
            'branch_id' => $this->branch->id,
            'email'     => 'delivery-bot+cancel@foodking.local',
        ]);

        // Pre-existing order from a prior `order.created` webhook.
        $this->order = FrontendOrder::create([
            'user_id'        => $bot->id,
            'branch_id'      => $this->branch->id,
            'order_type'     => OrderType::DELIVERY,
            'source'         => Source::DELIVERY_PLATFORM,
            'source_surface' => 'deliveroo',
            'status'         => OrderStatus::ACCEPT,
            'payment_status' => PaymentStatus::PAID,
            'payment_method' => 100,
            'subtotal' => 10, 'discount' => 0, 'delivery_charge' => 1, 'total_tax' => 0, 'total' => 11,
            'order_datetime' => now(),
        ]);

        $this->external = DeliveryPlatformExternalOrder::create([
            'platform'             => 'deliveroo',
            'external_id'          => 'deliveroo-cancel-1111-2222',
            'frontend_order_id'    => $this->order->id,
            'branch_id'            => $this->branch->id,
            'delivery_platform_id' => $this->cfg->id,
            'raw_payload'          => ['data' => ['order_id' => 'deliveroo-cancel-1111-2222']],
            'received_at'          => now(),
            'ingested_at'          => now(),
            'internal_status'      => OrderStatus::ACCEPT,
            'external_status'      => 'created',
        ]);
    }

    public function test_platform_cancellation_webhook_transitions_order(): void
    {
        Event::fake([\App\Events\OrderStatusChanged::class]);

        $payload = json_decode(
            file_get_contents(base_path('tests/Fixtures/deliveroo/order_cancelled.json')),
            true,
        );

        $event = $this->buildEvent($payload, 'deliveroo');

        $service = app(DeliveryOrderCancellationService::class);
        $result  = $service->cancel($event);

        // (a) Result envelope.
        $this->assertTrue($result['transitioned']);
        $this->assertSame($this->order->id, $result['frontend_order_id']);
        $this->assertSame($this->external->id, $result['external_order_id']);
        $this->assertNotEmpty($result['reason']);

        // (b) FrontendOrder transitioned to CANCELED via OrderStateMachine.
        $this->order->refresh();
        $this->assertSame(OrderStatus::CANCELED, (int) $this->order->status);

        // (c) Reason persisted in order_status_transitions.
        $transition = OrderStatusTransition::query()
            ->where('order_id', $this->order->id)
            ->where('to_status', OrderStatus::CANCELED)
            ->latest('id')
            ->first();
        $this->assertNotNull($transition, 'transition row must exist');
        $this->assertSame(OrderStatus::ACCEPT, (int) $transition->from_status);
        $this->assertNotEmpty($transition->reason);
        $this->assertSame(
            $payload['data']['cancellation']['reason'],
            $transition->reason,
        );

        // (d) DeliveryPlatformExternalOrder mapping closed with cancelled state.
        $this->external->refresh();
        $this->assertSame(OrderStatus::CANCELED, $this->external->internal_status);
        $this->assertSame('cancelled', $this->external->external_status);

        // (e) OrderStatusChanged dispatched after commit (so KDS / outbox /
        //     PushStatusToDeliveryPlatform fan-out fires).
        Event::assertDispatched(\App\Events\OrderStatusChanged::class, function ($e) {
            return (int) $e->newStatus === OrderStatus::CANCELED
                && (int) $e->oldStatus === OrderStatus::ACCEPT
                && (int) $e->order->id === $this->order->id;
        });
    }

    public function test_idempotent_when_already_cancelled(): void
    {
        Event::fake([\App\Events\OrderStatusChanged::class]);

        // Pre-mark the order CANCELED (e.g. previous webhook already did).
        $this->order->forceFill(['status' => OrderStatus::CANCELED])->save();

        $payload = json_decode(
            file_get_contents(base_path('tests/Fixtures/deliveroo/order_cancelled.json')),
            true,
        );
        $event = $this->buildEvent($payload, 'deliveroo');

        $result = app(DeliveryOrderCancellationService::class)->cancel($event);

        $this->assertFalse($result['transitioned']);
        // No transition row should have been created in this idempotent pass.
        $this->assertSame(
            0,
            OrderStatusTransition::query()->where('order_id', $this->order->id)->count(),
        );
        Event::assertNotDispatched(\App\Events\OrderStatusChanged::class);
    }

    public function test_unknown_external_id_is_ignored(): void
    {
        Event::fake();

        $payload = [
            'event' => 'order.cancelled',
            'data'  => [
                'order_id' => 'definitely-not-in-our-db',
                'status'   => 'cancelled',
            ],
        ];
        $event = $this->buildEvent($payload, 'deliveroo');

        $result = app(DeliveryOrderCancellationService::class)->cancel($event);

        $this->assertFalse($result['transitioned']);
        $this->assertNull($result['external_order_id']);

        // Untouched.
        $this->order->refresh();
        $this->assertSame(OrderStatus::ACCEPT, (int) $this->order->status);
    }

    public function test_cancellation_via_uber_eats_payload(): void
    {
        Event::fake([\App\Events\OrderStatusChanged::class]);

        // Switch context to a uber_eats config + external order so we can
        // verify the per-platform reason extractor (uber uses
        // `cancellation.details` rather than `cancellation.reason`).
        $this->cfg->update(['platform' => 'uber_eats']);
        $this->external->forceFill([
            'platform'    => 'uber_eats',
            'external_id' => 'cancel-uuid-1111-2222-3333-4444',
        ])->save();

        $payload = json_decode(
            file_get_contents(base_path('tests/Fixtures/uber_eats/order_cancelled.json')),
            true,
        );
        $event = $this->buildEvent($payload, 'uber_eats');

        $result = app(DeliveryOrderCancellationService::class)->cancel($event);

        $this->assertTrue($result['transitioned']);
        $this->assertSame(
            $payload['data']['cancellation']['details'],
            $result['reason'],
        );

        $this->order->refresh();
        $this->assertSame(OrderStatus::CANCELED, (int) $this->order->status);
    }

    private function buildEvent(array $payload, string $platform): DeliveryWebhookEvent
    {
        return DeliveryWebhookEvent::create([
            'platform'         => $platform,
            'event_type'       => 'order.cancelled',
            'signature_header' => 'x',
            'signature_valid'  => true,
            'nonce'            => 'cancel-nonce-' . uniqid('', true),
            'headers'          => ['Content-Type' => 'application/json'],
            'body'             => $payload,
            'branch_id'        => $this->branch->id,
            'received_at'      => now(),
        ]);
    }
}
