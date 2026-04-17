<?php

namespace Tests\Unit\Domain\Events;

use App\Domain\Events\EventContract;
use App\Enums\EventType;
use App\Exceptions\PayloadMismatchException;
use App\Models\DomainEvent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class EventContractUnitTest extends TestCase
{
    public function test_build_envelope_produces_flat_v1_shape(): void
    {
        $occurredAt = Carbon::parse('2026-04-16T10:00:00+00:00');
        $correlationId = (string) Str::uuid();

        $event = new DomainEvent();
        $event->event_type = EventType::ORDER_CREATED;
        $event->aggregate_type = 'App\\Models\\Order';
        $event->aggregate_id = 42;
        $event->branch_id = 3;
        $event->occurred_at = $occurredAt;
        $event->correlation_id = $correlationId;
        $event->payload = ['order_id' => 42, 'queue_number' => 'Q-42'];

        $envelope = EventContract::buildEnvelope($event);

        $this->assertSame(1, $envelope['version']);
        $this->assertSame(EventType::ORDER_CREATED, $envelope['type']);
        $this->assertSame(42, $envelope['aggregate_id']);
        $this->assertSame(3, $envelope['branch_id']);
        $this->assertSame($correlationId, $envelope['correlation_id']);
        $this->assertSame(['order_id' => 42, 'queue_number' => 'Q-42'], $envelope['payload']);
        // occurred_at is always serialized via Carbon::toIso8601String() and may
        // carry the app timezone. The important invariant is the canonical format.
        $this->assertSame($event->occurred_at->toIso8601String(), $envelope['occurred_at']);
        $this->assertSame([
            'version', 'type', 'aggregate_id', 'branch_id', 'occurred_at', 'correlation_id', 'payload',
        ], array_keys($envelope));
    }

    public function test_valid_envelope_passes(): void
    {
        EventContract::assertEnvelopeValid([
            'version'        => 1,
            'type'           => EventType::ORDER_CREATED,
            'aggregate_id'   => 10,
            'branch_id'      => 1,
            'occurred_at'    => '2026-04-16T10:00:00+00:00',
            'correlation_id' => (string) Str::uuid(),
            'payload'        => ['order_id' => 10],
        ]);

        $this->assertTrue(true);
    }

    public function test_envelope_with_wrong_version_throws(): void
    {
        $this->expectException(PayloadMismatchException::class);
        $this->expectExceptionMessageMatches('/version must be 1/');

        EventContract::assertEnvelopeValid([
            'version'        => 2,
            'type'           => EventType::ORDER_CREATED,
            'aggregate_id'   => 1,
            'branch_id'      => null,
            'occurred_at'    => '2026-04-16T10:00:00+00:00',
            'correlation_id' => (string) Str::uuid(),
            'payload'        => ['order_id' => 1],
        ]);
    }

    public function test_envelope_with_unknown_type_throws(): void
    {
        $this->expectException(PayloadMismatchException::class);

        EventContract::assertEnvelopeValid([
            'version'        => 1,
            'type'           => 'loyalty.points_earned',
            'aggregate_id'   => 1,
            'branch_id'      => null,
            'occurred_at'    => '2026-04-16T10:00:00+00:00',
            'correlation_id' => (string) Str::uuid(),
            'payload'        => ['user_id' => 1],
        ]);
    }

    public function test_envelope_missing_aggregate_id_throws(): void
    {
        $this->expectException(PayloadMismatchException::class);
        $this->expectExceptionMessageMatches('/aggregate_id is required/');

        EventContract::assertEnvelopeValid([
            'version'        => 1,
            'type'           => EventType::ORDER_CREATED,
            'aggregate_id'   => null,
            'branch_id'      => 1,
            'occurred_at'    => '2026-04-16T10:00:00+00:00',
            'correlation_id' => (string) Str::uuid(),
            'payload'        => ['order_id' => 1],
        ]);
    }

    public function test_envelope_missing_branch_id_key_throws(): void
    {
        $this->expectException(PayloadMismatchException::class);

        EventContract::assertEnvelopeValid([
            'version'        => 1,
            'type'           => EventType::ORDER_CREATED,
            'aggregate_id'   => 1,
            'occurred_at'    => '2026-04-16T10:00:00+00:00',
            'correlation_id' => (string) Str::uuid(),
            'payload'        => ['order_id' => 1],
        ]);
    }

    public function test_envelope_payload_not_object_throws(): void
    {
        $this->expectException(PayloadMismatchException::class);

        EventContract::assertEnvelopeValid([
            'version'        => 1,
            'type'           => EventType::ORDER_CREATED,
            'aggregate_id'   => 1,
            'branch_id'      => 1,
            'occurred_at'    => '2026-04-16T10:00:00+00:00',
            'correlation_id' => (string) Str::uuid(),
            'payload'        => 'not-an-object',
        ]);
    }

    public function test_payload_validation_catches_missing_required_key_for_status_changed(): void
    {
        $this->expectException(PayloadMismatchException::class);
        $this->expectExceptionMessageMatches('/missing required keys:.*new_status/');

        EventContract::assertEnvelopeValid([
            'version'        => 1,
            'type'           => EventType::ORDER_STATUS_CHANGED,
            'aggregate_id'   => 1,
            'branch_id'      => 1,
            'occurred_at'    => '2026-04-16T10:00:00+00:00',
            'correlation_id' => (string) Str::uuid(),
            'payload'        => ['order_id' => 1, 'old_status' => 2],
        ]);
    }

    public function test_payload_validation_allows_extra_keys(): void
    {
        EventContract::assertEnvelopeValid([
            'version'        => 1,
            'type'           => EventType::MENU_ITEM_AVAILABILITY_CHANGED,
            'aggregate_id'   => 7,
            'branch_id'      => null,
            'occurred_at'    => '2026-04-16T10:00:00+00:00',
            'correlation_id' => (string) Str::uuid(),
            'payload'        => ['item_id' => 7, 'status' => 1, 'price' => 12.5, 'type' => 'status'],
        ]);

        $this->assertTrue(true);
    }

    public function test_broadcast_map_matches_all_v1_types(): void
    {
        foreach (EventContract::BROADCAST_MAP as $broadcastAs => $type) {
            $this->assertContains($type, EventType::all(), "Broadcast map references unknown type for {$broadcastAs}");
        }
    }

    public function test_type_for_broadcast_as_returns_mapped_type_or_identity(): void
    {
        $this->assertSame(EventType::ORDER_CREATED, EventContract::typeForBroadcastAs('OrderCreated'));
        $this->assertSame('UnknownBroadcast', EventContract::typeForBroadcastAs('UnknownBroadcast'));
    }

    public function test_payload_mismatch_exception_exposes_context(): void
    {
        try {
            EventContract::assertEnvelopeValid([
                'version'        => 1,
                'type'           => EventType::ORDER_STATUS_CHANGED,
                'aggregate_id'   => 1,
                'branch_id'      => 1,
                'occurred_at'    => '2026-04-16T10:00:00+00:00',
                'correlation_id' => (string) Str::uuid(),
                'payload'        => ['order_id' => 1],
            ]);
            $this->fail('Expected PayloadMismatchException');
        } catch (PayloadMismatchException $exception) {
            $this->assertSame(EventType::ORDER_STATUS_CHANGED, $exception->eventType);
            $this->assertNotEmpty($exception->errors);
            $this->assertArrayHasKey('event_type', $exception->context());
        }
    }
}
