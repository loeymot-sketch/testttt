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
 * Concrete Delicity adapter.
 *
 * Wire format reference (Delicity Marketplace API, Stripe-style):
 *   - Webhook header     : X-Delicity-Signature: t=<unix-ts>,v1=<hex>
 *   - HMAC               : SHA-256 over "{timestamp}.{rawBody}" using
 *                          the per-branch webhook_secret (Stripe parity).
 *   - Replay window      : 300s (configurable via
 *                          config/delivery_platforms.platforms.delicity.timestamp_window)
 *   - Status push URL    : POST {base_url}/v1/orders/{external_id}/status
 *                          with body {"status": "<mapped>"}
 *
 * Money convention: Delicity expresses unit prices in EUROS (decimal
 * `unit_price_eur: 8.50`) — the adapter converts each value to integer
 * cents via round() at the boundary so that the DTO stays consistent
 * with the rest of the pipeline (cents everywhere).
 *
 * Mapping table FoodKing OrderStatus → Delicity string:
 *   ACCEPT            (4)  → "accepted"
 *   PREPARING         (7)  → "preparing"
 *   PREPARED          (8)  → "ready"
 *   OUT_FOR_DELIVERY  (10) → "out_for_delivery"
 *   CANCELED          (16) → "cancelled"
 *   REJECTED          (19) → "cancelled"
 *
 * All other statuses return null (caller MUST NOT push).
 */
final class DelicityAdapter implements PlatformAdapter
{
    /**
     * Header carrying the Stripe-style {t,v1} signature pair.
     */
    public const SIGNATURE_HEADER = 'X-Delicity-Signature';

    /**
     * Default replay window in seconds. Overridable via config —
     * adapter never hardcodes the window once config has been loaded
     * (mirrors UberEatsAdapter::DEFAULT_TIMESTAMP_WINDOW).
     */
    private const DEFAULT_TIMESTAMP_WINDOW = 300;

    /**
     * Status transition table. Right-hand side is lower_snake_case —
     * matches the Delicity API contract.
     */
    private const STATUS_MAP = [
        OrderStatus::ACCEPT           => 'accepted',
        OrderStatus::PREPARING        => 'preparing',
        OrderStatus::PREPARED         => 'ready',
        OrderStatus::OUT_FOR_DELIVERY => 'out_for_delivery',
        OrderStatus::CANCELED         => 'cancelled',
        OrderStatus::REJECTED         => 'cancelled',
    ];

    public function verifySignature(Request $request, string $rawBody, string $webhookSecret): bool
    {
        $header = (string) $request->header(self::SIGNATURE_HEADER, '');
        if ($header === '') {
            return false;
        }

        $parts = $this->parseSignatureHeader($header);
        if ($parts === null) {
            return false;
        }

        // Replay defense — reject envelopes too far from now().
        $window = (int) config(
            'delivery_platforms.platforms.delicity.timestamp_window',
            self::DEFAULT_TIMESTAMP_WINDOW
        );
        $now    = Carbon::now()->getTimestamp();
        $delta  = abs($now - $parts['t']);
        if ($delta > $window) {
            return false;
        }

        // HMAC over "{timestamp}.{rawBody}" — the dot is part of the
        // string, mirroring Stripe's signing canonicalisation.
        $payloadToSign = $parts['t'] . '.' . $rawBody;
        $expected      = hash_hmac('sha256', $payloadToSign, $webhookSecret);

        return hash_equals($expected, $parts['v1']);
    }

    public function parseOrder(array $payload, int $branchId): NormalizedDeliveryOrder
    {
        // Delicity uses `data` (newer) or top-level (older) — accept both.
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : $payload;
        if (!is_array($data) || empty($data)) {
            throw new OrderMappingException('Delicity payload missing order data.');
        }

        // ---- Customer ----
        $customerNode = is_array($data['customer'] ?? null) ? $data['customer'] : [];
        $customer = [
            'name'  => trim((string) Arr::get($customerNode, 'name', '')),
            'phone' => Arr::get($customerNode, 'phone')
                ?? Arr::get($customerNode, 'phone_number'),
        ];

        // ---- Address (delivery only) ----
        $addrNode = is_array($data['delivery_address'] ?? null)
            ? $data['delivery_address']
            : (is_array($data['address'] ?? null) ? $data['address'] : []);
        $address = [];
        if (!empty($addrNode)) {
            $address = [
                'address'   => trim((string) (Arr::get($addrNode, 'street')
                    ?? Arr::get($addrNode, 'line1', ''))),
                'apartment' => Arr::get($addrNode, 'apartment'),
                'city'      => Arr::get($addrNode, 'city'),
                'country'   => Arr::get($addrNode, 'country', 'FR'),
                'zip'       => Arr::get($addrNode, 'zip')
                    ?? Arr::get($addrNode, 'postcode'),
                'latitude'  => Arr::get($addrNode, 'latitude'),
                'longitude' => Arr::get($addrNode, 'longitude'),
            ];
        }

        // ---- Items ----
        // Delicity sends `unit_price_eur` as a decimal (string|float). We
        // convert at the boundary to keep the DTO's cents-only contract.
        $items = [];
        foreach ((array) ($data['items'] ?? []) as $rawItem) {
            $unitEur = $this->eurToCents(Arr::get($rawItem, 'unit_price_eur'));
            // Allow legacy `unit_price` in cents as fallback.
            if ($unitEur === 0) {
                $unitEur = (int) Arr::get($rawItem, 'unit_price', 0);
            }
            $items[] = new DeliveryItem(
                external_sku:     (string) (Arr::get($rawItem, 'sku')
                    ?? Arr::get($rawItem, 'pos_id')
                    ?? Arr::get($rawItem, 'id', '')),
                name:             (string) Arr::get($rawItem, 'name', ''),
                qty:              max(1, (int) Arr::get($rawItem, 'quantity', 1)),
                unit_price_cents: $unitEur,
                modifiers:        $this->collectModifiers((array) Arr::get($rawItem, 'modifiers', [])),
            );
        }

        // ---- Totals (cents) — convert from euros where present ----
        $totalsNode = is_array($data['totals'] ?? null) ? $data['totals'] : [];
        $totals = [
            'subtotal'        => $this->resolveAmount($totalsNode, 'subtotal'),
            'delivery_charge' => $this->resolveAmount($data, 'delivery_fee')
                ?: $this->resolveAmount($totalsNode, 'delivery_fee'),
            'total'           => $this->resolveAmount($totalsNode, 'total')
                ?: $this->resolveAmount($data, 'total'),
        ];

        // ---- Timestamps ----
        $placedAt = $this->parseTimestamp(
            Arr::get($data, 'placed_at')
                ?? Arr::get($data, 'created_at')
                ?? Arr::get($payload, 'event_time')
        ) ?? Carbon::now();

        $expectedReadyAt = $this->parseTimestamp(
            Arr::get($data, 'pickup_at')
                ?? Arr::get($data, 'ready_at')
                ?? Arr::get($data, 'expected_pickup_at')
        );

        // ---- Pickup type ----
        $pickup = strtolower((string) Arr::get($data, 'pickup_type', 'delivery'));
        $pickupType = match (true) {
            str_contains($pickup, 'pickup'),
            str_contains($pickup, 'takeaway'),
            str_contains($pickup, 'collection'),
            str_contains($pickup, 'emporter') => 'customer_pickup',
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
            $data['id']           ?? null,
            $data['external_id']  ?? null,
            $data['order_id']     ?? null,
            $payload['id']        ?? null,
            $payload['order_id']  ?? null,
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
            'Delicity payload missing order id (data.id / data.external_id / data.order_id).'
        );
    }

    public function eventTypeFrom(array $payload): string
    {
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : $payload;

        $eventType = strtolower((string) ($payload['event'] ?? $payload['event_type'] ?? ''));
        $state     = strtolower((string) (
            $data['status']
            ?? $data['state']
            ?? ''
        ));

        if (str_contains($eventType, 'cancel') || $state === 'cancelled' || $state === 'canceled') {
            return 'order.cancelled';
        }

        if (
            str_contains($eventType, 'created')
            || str_contains($eventType, 'placed')
            || str_contains($eventType, 'new')
            || $state === 'created'
            || $state === 'placed'
            || $state === 'new'
            || $state === 'pending'
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
                error:   "No Delicity mapping for internal status {$internalStatus}.",
            );
        }

        $credentials = $cfg->credentials ?? [];
        $apiKey      = (string) ($credentials['api_key'] ?? '');
        if ($apiKey === '') {
            return new PushResult(
                success: false,
                error:   'Delicity credentials missing api_key.',
            );
        }

        $base = (string) config(
            'delivery_platforms.platforms.delicity.base_url',
            'https://api.delicity.com'
        );
        $url  = sprintf('%s/v1/orders/%s/status', rtrim($base, '/'), urlencode($externalId));

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
            Log::warning('[DelicityAdapter] pushStatus failed', [
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
     * Parse the Delicity signature header. Format:
     *   "t=<unix-ts>,v1=<hex>"
     * Whitespace tolerated around commas / equals signs.
     *
     * @return array{t:int, v1:string}|null
     */
    private function parseSignatureHeader(string $header): ?array
    {
        $parts = [];
        foreach (explode(',', $header) as $segment) {
            $segment = trim($segment);
            if ($segment === '' || !str_contains($segment, '=')) {
                continue;
            }
            [$k, $v] = explode('=', $segment, 2);
            $parts[trim($k)] = trim($v);
        }

        if (!isset($parts['t'], $parts['v1'])) {
            return null;
        }
        if (!ctype_digit((string) $parts['t'])) {
            return null;
        }
        if (!preg_match('/^[a-f0-9]{64}$/i', (string) $parts['v1'])) {
            return null;
        }

        return [
            't'  => (int) $parts['t'],
            'v1' => strtolower((string) $parts['v1']),
        ];
    }

    /**
     * Convert a euro decimal value to integer cents. Tolerates string,
     * float, or null inputs — null returns 0 so the DTO never carries
     * a negative-or-undefined amount through to the IngestionService.
     */
    private function eurToCents(mixed $eur): int
    {
        if ($eur === null || $eur === '') {
            return 0;
        }
        if (is_int($eur)) {
            // Already in cents (integer).
            return $eur;
        }
        // Round to the nearest cent — defensive against float drift coming
        // from JSON deserialisation of Delicity's two-decimal representation.
        return (int) round(((float) $eur) * 100);
    }

    /**
     * Resolve a monetary key from a node. Tries:
     *   - "{key}_cents" → already in cents
     *   - "{key}_eur"   → euros, convert to cents
     *   - "{key}"       → assume cents (legacy)
     */
    private function resolveAmount(array $node, string $key): int
    {
        if (isset($node[$key . '_cents'])) {
            return (int) $node[$key . '_cents'];
        }
        if (isset($node[$key . '_eur'])) {
            return $this->eurToCents($node[$key . '_eur']);
        }
        if (isset($node[$key])) {
            $val = $node[$key];
            // If the value contains a decimal point treat it as euros,
            // otherwise assume cents (Delicity's payloads are inconsistent
            // here across versions so we accept both).
            if (is_string($val) && str_contains($val, '.')) {
                return $this->eurToCents($val);
            }
            if (is_float($val) && (int) $val !== $val) {
                return $this->eurToCents($val);
            }
            return (int) $val;
        }
        return 0;
    }

    /**
     * Flatten Delicity's modifier list. Mirror Deliveroo/UberEats shape
     * so the OrderItem builder stays platform-agnostic.
     *
     * @param  array<int, array<string,mixed>>  $modifiers
     * @return array<int, array<string,mixed>>
     */
    private function collectModifiers(array $modifiers): array
    {
        $flat = [];
        foreach ($modifiers as $mod) {
            $flat[] = [
                'sku'              => (string) (Arr::get($mod, 'sku')
                    ?? Arr::get($mod, 'pos_id')
                    ?? Arr::get($mod, 'id', '')),
                'name'             => (string) Arr::get($mod, 'name', ''),
                'group'            => (string) Arr::get($mod, 'group_name', ''),
                'qty'              => max(1, (int) Arr::get($mod, 'quantity', 1)),
                'unit_price_cents' => $this->eurToCents(Arr::get($mod, 'unit_price_eur'))
                    ?: (int) Arr::get($mod, 'unit_price', 0),
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
