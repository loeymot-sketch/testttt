<?php

namespace App\Services\Delivery\Adapters;

use App\Domain\Delivery\DeliveryItem;
use App\Domain\Delivery\Exceptions\OrderMappingException;
use App\Domain\Delivery\NormalizedDeliveryOrder;
use App\Domain\Delivery\PlatformAdapter;
use App\Domain\Delivery\PushResult;
use App\Enums\OrderStatus;
use App\Models\DeliveryPlatform;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * [PARALLEL-TRACK-1.3 / Delivery Platform Integration — Phase 3]
 *
 * Concrete Deliveroo Restaurant Hub adapter.
 *
 * Wire format reference (Deliveroo Order API, public docs §5.2):
 *   - Webhook header     : X-Deliveroo-Hmac-Sha256: <hex>
 *   - Replay header      : X-Deliveroo-Sequence-Guid: <uuid>
 *                          (consumed by VerifyDeliverySignature middleware
 *                          as the per-platform replay nonce)
 *   - HMAC               : SHA-256 over the raw request body, using the
 *                          per-branch webhook_secret. NO timestamp prefix
 *                          — Deliveroo's signature is timestamp-free, so
 *                          replay defense relies entirely on the sequence
 *                          GUID + the middleware's Cache::add() race.
 *   - Status push URL    : POST {base_url}/orders/{external_id}/sync_status
 *                          with body {"status": "<mapped>"}
 *
 * Money convention: Deliveroo expresses every monetary field as integer
 * minor units (`pricing.delivery_fee = 250` for €2.50, like Stripe). We
 * surface them through the DTO unchanged — the IngestionService converts
 * cents → decimal:6 at the persistence boundary.
 *
 * Mapping table FoodKing OrderStatus → Deliveroo string:
 *   ACCEPT     (4)  → "accepted"
 *   PREPARING  (7)  → "in_preparation"
 *   PREPARED   (8)  → "ready_for_collection"
 *   CANCELED   (16) → "cancelled"
 *   REJECTED   (19) → "cancelled"
 *
 * Anything else → null (caller MUST NOT push). The mapping is intentionally
 * narrow — Deliveroo's order lifecycle on the merchant side is shorter than
 * Uber Eats's, so we never echo PREPARING-style intermediate states beyond
 * what the platform expects.
 */
final class DeliverooAdapter implements PlatformAdapter
{
    /**
     * Header carrying the flat HMAC-SHA256 hex digest of the raw body.
     */
    public const SIGNATURE_HEADER = 'X-Deliveroo-Hmac-Sha256';

    /**
     * Per-envelope sequence GUID. The middleware already feeds this header
     * into the replay-cache key — exposed here as a constant so that any
     * future adapter-level need (e.g. attaching the GUID to a debug log)
     * does not silently drift from the middleware's expectation.
     */
    public const SEQUENCE_HEADER = 'X-Deliveroo-Sequence-Guid';

    /**
     * Status transition table. Adding a new internal OrderStatus constant
     * here without checking the platform side will silently break the push
     * (we'd send a string the platform rejects). Keep the right-hand side
     * snake_case — Deliveroo uses lower-case enum-style values.
     */
    private const STATUS_MAP = [
        OrderStatus::ACCEPT    => 'accepted',
        OrderStatus::PREPARING => 'in_preparation',
        OrderStatus::PREPARED  => 'ready_for_collection',
        OrderStatus::CANCELED  => 'cancelled',
        OrderStatus::REJECTED  => 'cancelled',
    ];

    public function verifySignature(Request $request, string $rawBody, string $webhookSecret): bool
    {
        $header = trim((string) $request->header(self::SIGNATURE_HEADER, ''));
        if ($header === '') {
            return false;
        }

        // Reject anything that isn't a 64-char lowercase hex digest before
        // hash_equals() sees it — defensive against malformed inputs that
        // could otherwise generate an even-length false positive.
        if (!preg_match('/^[a-f0-9]{64}$/i', $header)) {
            return false;
        }

        // Deliveroo signs the raw body without a timestamp prefix.
        $expected = hash_hmac('sha256', $rawBody, $webhookSecret);

        return hash_equals($expected, strtolower($header));
    }

    public function parseOrder(array $payload, int $branchId): NormalizedDeliveryOrder
    {
        // Deliveroo wraps the order under either `data` (RHub v3) or as a
        // top-level object (legacy). We accept both so we don't break when
        // the platform rolls out v3 → v4.
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : $payload;
        if (!is_array($data) || empty($data)) {
            throw new OrderMappingException('Deliveroo payload missing order data.');
        }

        // ---- Customer ----
        $customerNode = is_array($data['customer'] ?? null) ? $data['customer'] : [];
        $customer = [
            'name'  => trim((string) (Arr::get($customerNode, 'name')
                ?? Arr::get($customerNode, 'first_name', '')
                . ' '
                . Arr::get($customerNode, 'last_name', ''))),
            'phone' => Arr::get($customerNode, 'phone_number')
                ?? Arr::get($customerNode, 'contact_number'),
        ];

        // ---- Address (delivery only) ----
        $addrNode = is_array($data['delivery_address'] ?? null)
            ? $data['delivery_address']
            : (is_array($data['address'] ?? null) ? $data['address'] : []);
        $address = [];
        if (!empty($addrNode)) {
            $address = [
                'address'   => trim(
                    (string) Arr::get($addrNode, 'line1', '')
                    . ' '
                    . (string) Arr::get($addrNode, 'line2', '')
                ),
                'apartment' => Arr::get($addrNode, 'line2'),
                'city'      => Arr::get($addrNode, 'city'),
                'country'   => Arr::get($addrNode, 'country', 'FR'),
                'zip'       => Arr::get($addrNode, 'postcode')
                    ?? Arr::get($addrNode, 'zip'),
                'latitude'  => Arr::get($addrNode, 'latitude'),
                'longitude' => Arr::get($addrNode, 'longitude'),
            ];
        }

        // ---- Items ----
        $items = [];
        foreach ((array) ($data['items'] ?? []) as $rawItem) {
            $items[] = new DeliveryItem(
                external_sku:     (string) (Arr::get($rawItem, 'pos_id')
                    ?? Arr::get($rawItem, 'menu_item_id')
                    ?? Arr::get($rawItem, 'id', '')),
                name:             (string) Arr::get($rawItem, 'name', ''),
                qty:              max(1, (int) Arr::get($rawItem, 'quantity', 1)),
                unit_price_cents: (int) (Arr::get($rawItem, 'unit_price')
                    ?? Arr::get($rawItem, 'price', 0)),
                modifiers:        $this->collectModifiers((array) Arr::get($rawItem, 'modifiers', [])),
            );
        }

        // ---- Totals (cents) ----
        $pricing = is_array($data['pricing'] ?? null) ? $data['pricing'] : [];
        $totals = [
            'subtotal'        => (int) Arr::get($pricing, 'subtotal', 0),
            'delivery_charge' => (int) Arr::get($pricing, 'delivery_fee', 0),
            'total'           => (int) Arr::get($pricing, 'total', 0),
        ];

        // ---- Timestamps ----
        $placedAt = $this->parseTimestamp(
            Arr::get($data, 'created_at')
                ?? Arr::get($data, 'placed_at')
                ?? Arr::get($payload, 'event_time')
        ) ?? Carbon::now();

        $expectedReadyAt = $this->parseTimestamp(
            Arr::get($data, 'expected_pickup_at')
                ?? Arr::get($data, 'ready_at')
        );

        // ---- Pickup type ----
        // Deliveroo uses `fulfillment_type` (collection / delivery) — fall
        // back to the legacy `pickup_type` field so we never crash on a
        // payload version difference.
        $fulfillment = strtolower((string) (
            Arr::get($data, 'fulfillment_type')
                ?? Arr::get($data, 'pickup_type', 'delivery')
        ));
        $pickupType = match (true) {
            str_contains($fulfillment, 'collection'),
            str_contains($fulfillment, 'pickup'),
            str_contains($fulfillment, 'takeaway'),
            str_contains($fulfillment, 'click_and_collect') => 'customer_pickup',
            default => 'delivery',
        };

        return new NormalizedDeliveryOrder(
            customer:          $customer,
            address:           $address,
            items:             $items,
            totals:            $totals,
            placed_at:         $placedAt,
            expected_ready_at: $expectedReadyAt,
            pickup_type:       $pickupType,
        );
    }

    public function externalIdFrom(array $payload): string
    {
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : $payload;

        $candidates = [
            $data['order_id']    ?? null,
            $data['id']          ?? null,
            $payload['order_id'] ?? null,
            $payload['id']       ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && $candidate !== '') {
                return $candidate;
            }
            if (is_int($candidate) && $candidate > 0) {
                return (string) $candidate;
            }
        }

        throw new OrderMappingException(
            'Deliveroo payload missing order id (data.order_id / data.id / order_id / id).'
        );
    }

    public function eventTypeFrom(array $payload): string
    {
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : $payload;

        $eventType = strtolower((string) ($payload['event'] ?? $payload['event_type'] ?? ''));
        $state     = strtolower((string) (
            $data['status']
            ?? $data['order_state']
            ?? ''
        ));

        if (str_contains($eventType, 'cancel') || $state === 'cancelled' || $state === 'canceled') {
            return 'order.cancelled';
        }

        if (
            str_contains($eventType, 'created')
            || str_contains($eventType, 'new')
            || str_contains($eventType, 'placed')
            || $state === 'created'
            || $state === 'placed'
            || $state === 'new'
        ) {
            return 'order.created';
        }

        return 'order.unknown';
    }

    public function pushStatus(DeliveryPlatform $cfg, string $externalId, int $internalStatus): PushResult
    {
        $platformStatus = $this->mapInternalStatus($internalStatus);
        if ($platformStatus === null) {
            return new PushResult(
                success: false,
                error:   "No Deliveroo mapping for internal status {$internalStatus}.",
            );
        }

        $credentials = $cfg->credentials ?? [];
        $apiKey      = (string) ($credentials['api_key'] ?? '');
        if ($apiKey === '') {
            return new PushResult(
                success: false,
                error:   'Deliveroo credentials missing api_key.',
            );
        }

        $base = (string) config(
            'delivery_platforms.platforms.deliveroo.base_url',
            'https://api.deliveroo.com'
        );
        $url  = sprintf('%s/orders/%s/sync_status', rtrim($base, '/'), urlencode($externalId));

        try {
            $response = Http::withToken($apiKey)
                ->acceptJson()
                ->asJson()
                ->timeout(8)
                ->retry(2, 250, throw: false)
                ->post($url, ['status' => $platformStatus]);

            return new PushResult(
                success:      $response->successful(),
                http_status:  $response->status(),
                error:        $response->successful() ? null : (string) $response->body(),
                raw_response: is_array($response->json()) ? $response->json() : null,
            );
        } catch (\Throwable $e) {
            Log::warning('[DeliverooAdapter] pushStatus failed', [
                'external_id'    => $externalId,
                'internal_status'=> $internalStatus,
                'error'          => $e->getMessage(),
            ]);
            return new PushResult(
                success: false,
                error:   $e->getMessage(),
            );
        }
    }

    public function mapInternalStatus(int $internalStatus): ?string
    {
        return self::STATUS_MAP[$internalStatus] ?? null;
    }

    /**
     * Flatten Deliveroo's modifier list into the simple shape the OrderItem
     * builder consumes (mirrors UberEatsAdapter::collectModifiers). Deliveroo
     * sends a flat array (no group nesting), so we keep it simple.
     *
     * @param  array<int, array<string,mixed>>  $modifiers
     * @return array<int, array<string,mixed>>
     */
    private function collectModifiers(array $modifiers): array
    {
        $flat = [];
        foreach ($modifiers as $mod) {
            $flat[] = [
                'sku'              => (string) (Arr::get($mod, 'pos_id')
                    ?? Arr::get($mod, 'id', '')),
                'name'             => (string) Arr::get($mod, 'name', ''),
                'group'            => (string) Arr::get($mod, 'group_name', ''),
                'qty'              => max(1, (int) Arr::get($mod, 'quantity', 1)),
                'unit_price_cents' => (int) (Arr::get($mod, 'unit_price')
                    ?? Arr::get($mod, 'price', 0)),
            ];
        }
        return $flat;
    }

    private function parseTimestamp(?string $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }
        try {
            return Carbon::parse($value);
        } catch (\Throwable $e) {
            return null;
        }
    }
}
