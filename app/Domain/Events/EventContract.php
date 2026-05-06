<?php

namespace App\Domain\Events;

use App\Enums\EventType;
use App\Exceptions\PayloadMismatchException;
use App\Models\DomainEvent;

/**
 * V1 canonical event envelope — single source of truth used by the outbox job
 * and by every listener that writes to the `domain_events` outbox.
 *
 * Envelope shape (frozen for V1):
 *   {
 *     version        : int   (always 1),
 *     type           : string (dotted, matches EventType::*),
 *     aggregate_id   : int|string,
 *     branch_id      : int|null,
 *     occurred_at    : string (ISO-8601),
 *     correlation_id : string (UUID),
 *     payload        : object
 *   }
 *
 * @see docs/EVENT_CONTRACT.md
 */
final class EventContract
{
    public const ENVELOPE_VERSION = 1;

    /**
     * Mapping broadcast-as names ↔ canonical dotted event types.
     * Keep in sync with resources/js/services/eventContract.js (BROADCAST_MAP).
     */
    public const BROADCAST_MAP = [
        'OrderCreated'              => EventType::ORDER_CREATED,
        'OrderStatusChanged'        => EventType::ORDER_STATUS_CHANGED,
        'OrderPaidAtCounter'        => EventType::ORDER_PAYMENT_CONFIRMED,
        'OrderItemAdded'            => EventType::ORDER_ITEM_ADDED,
        'OrderCancelled'            => EventType::ORDER_CANCELLED,
        // [F-02] KDS table reassignment fan-out.
        'OrderTableChanged'         => EventType::ORDER_TABLE_CHANGED,
        'ItemAvailabilityChanged'   => EventType::MENU_ITEM_AVAILABILITY_CHANGED,
        'CatalogChanged'            => EventType::CATALOG_CHANGED,
        'StockLow'                  => EventType::STOCK_LOW,
        // [PROMO-DASH-2026-05-06] Coupon mutations broadcast.
        'CouponChanged'             => EventType::COUPON_CHANGED,
        // [P13 — F-VERIFY-09-01 / F-VERIFY-09-10] payment_status transitions.
        'OrderPaymentStatusChanged' => EventType::ORDER_PAYMENT_STATUS_CHANGED,
    ];

    /**
     * Required payload keys for each canonical event type.
     * Enforced by assertPayloadValid() before a row reaches the outbox.
     */
    public const REQUIRED_PAYLOAD_KEYS = [
        EventType::ORDER_CREATED                  => ['order_id', 'queue_number', '_origin', 'payment_method'],
        EventType::ORDER_STATUS_CHANGED           => ['order_id', 'queue_number', '_origin', 'payment_method', 'old_status', 'new_status'],
        EventType::ORDER_PAYMENT_CONFIRMED        => ['order_id', 'queue_number', '_origin', 'payment_method', 'payment_status', 'fiscal_sequence_no'],
        EventType::ORDER_ITEM_ADDED               => ['order_id', 'item_id'],
        EventType::ORDER_CANCELLED                => ['order_id'],
        // [F-02] OrderTableChanged minimum payload — see PersistOrderTableChangedToOutbox.
        EventType::ORDER_TABLE_CHANGED            => ['order_id', 'new_table_id'],
        EventType::MENU_ITEM_AVAILABILITY_CHANGED => ['item_id', 'status'],
        EventType::CATALOG_CHANGED                => ['entity_type', 'entity_id', 'change_type', 'snapshot_version'],
        EventType::STOCK_LOW                      => ['item_id'],
        // [PROMO-DASH-2026-05-06] Minimum coupon-changed payload — see PersistCouponChangedToOutbox.
        EventType::COUPON_CHANGED                 => ['coupon_id', 'change_type'],
        // [P13 — F-VERIFY-09-01 / F-VERIFY-09-10] payment_status transition payload.
        EventType::ORDER_PAYMENT_STATUS_CHANGED   => ['order_id', 'branch_id', 'old_status', 'new_status', 'total', 'fiscal_sequence_no'],
    ];

    /**
     * Build the canonical V1 envelope from a persisted DomainEvent row.
     */
    public static function buildEnvelope(DomainEvent $event): array
    {
        return [
            'version'        => self::ENVELOPE_VERSION,
            'type'           => $event->event_type,
            'aggregate_id'   => $event->aggregate_id,
            'branch_id'      => $event->branch_id,
            'occurred_at'    => $event->occurred_at?->toIso8601String(),
            'correlation_id' => $event->correlation_id,
            'payload'        => is_array($event->payload) ? $event->payload : [],
        ];
    }

    /**
     * Hard-validate the envelope shape. Throws if any required key is missing
     * or has the wrong scalar shape. This is the last guard before broadcast,
     * catching drift introduced by a new listener or schema change.
     */
    public static function assertEnvelopeValid(array $envelope, ?string $eventType = null): void
    {
        $errors = [];

        if (($envelope['version'] ?? null) !== self::ENVELOPE_VERSION) {
            $errors[] = 'version must be ' . self::ENVELOPE_VERSION;
        }

        $type = $envelope['type'] ?? null;
        if (!is_string($type) || $type === '' || !in_array($type, EventType::all(), true)) {
            $errors[] = 'type must be one of ' . implode('|', EventType::all());
        }

        if (!array_key_exists('aggregate_id', $envelope) || $envelope['aggregate_id'] === null) {
            $errors[] = 'aggregate_id is required';
        }

        if (array_key_exists('branch_id', $envelope) === false) {
            $errors[] = 'branch_id is required (may be null)';
        } elseif ($envelope['branch_id'] !== null && !is_int($envelope['branch_id'])) {
            $errors[] = 'branch_id must be integer or null';
        }

        $occurredAt = $envelope['occurred_at'] ?? null;
        if (!is_string($occurredAt) || $occurredAt === '') {
            $errors[] = 'occurred_at must be a non-empty ISO-8601 string';
        }

        $correlationId = $envelope['correlation_id'] ?? null;
        if (!is_string($correlationId) || $correlationId === '') {
            $errors[] = 'correlation_id must be a non-empty string (UUID)';
        }

        $payload = $envelope['payload'] ?? null;
        if (!is_array($payload)) {
            $errors[] = 'payload must be an object';
        }

        if ($errors !== []) {
            throw new PayloadMismatchException(
                'Event envelope violates V1 contract: ' . implode('; ', $errors),
                $errors,
                $eventType ?? (is_string($type) ? $type : null)
            );
        }

        if (is_array($payload)) {
            self::assertPayloadValid((string) $type, $payload);
        }
    }

    /**
     * Check that a payload contains the minimal keys expected for its type.
     * Unknown types pass (forward-compatibility): additions happen via
     * REQUIRED_PAYLOAD_KEYS in an explicit PR.
     */
    public static function assertPayloadValid(string $type, array $payload): void
    {
        $required = self::REQUIRED_PAYLOAD_KEYS[$type] ?? null;

        if ($required === null) {
            return;
        }

        $missing = [];
        foreach ($required as $key) {
            if (!array_key_exists($key, $payload)) {
                $missing[] = $key;
            }
        }

        if ($missing !== []) {
            throw new PayloadMismatchException(
                sprintf('Event "%s" payload missing required keys: %s', $type, implode(', ', $missing)),
                array_map(fn (string $k): string => 'missing:' . $k, $missing),
                $type
            );
        }
    }

    /**
     * Resolve the dotted canonical type from a broadcast-as name. Falls back to
     * the raw broadcast-as name if the map does not cover it — the outbox row's
     * event_type column remains the authoritative source.
     */
    public static function typeForBroadcastAs(string $broadcastAs): string
    {
        return self::BROADCAST_MAP[$broadcastAs] ?? $broadcastAs;
    }
}
