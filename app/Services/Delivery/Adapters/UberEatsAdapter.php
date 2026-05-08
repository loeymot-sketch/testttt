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
 * [PARALLEL-TRACK-1.2 / Delivery Platform Integration — Phase 2]
 *
 * Concrete Uber Eats Direct adapter.
 *
 * Wire format reference (Uber Eats Direct, public):
 *   - Webhook header     : X-Uber-Signature: v=1,t=<unix-ts>,k=<keyId>,h=<hmac-hex>
 *   - HMAC               : SHA-256 over "{timestamp}.{rawBody}" using webhook_secret
 *   - Replay window      : 300s (configurable in config/delivery_platforms.php)
 *   - Status push URL    : PUT https://api.uber.com/v1/eats/orders/{id}/<transition>
 *
 * Uber Eats sends monetary fields as integer cents (`{amount: 1700, currency_code: 'EUR'}`)
 * which we forward verbatim into the NormalizedDeliveryOrder DTO. Conversion to
 * FoodKing's `decimal:6` happens later, at the Order hydration boundary in the
 * IngestionService.
 *
 * Mapping table FoodKing OrderStatus → Uber Eats string:
 *   ACCEPT         (4)  → "ACCEPTED"
 *   PREPARING      (7)  → "IN_PREPARATION"
 *   PREPARED       (8)  → "READY_FOR_PICKUP"
 *   CANCELED       (16) → "RESTAURANT_REJECTED"
 *
 * All other statuses return null (caller MUST NOT push).
 */
final class UberEatsAdapter implements PlatformAdapter
{
    /**
     * Header carrying the signature triple (v, t, k, h).
     */
    public const SIGNATURE_HEADER = 'X-Uber-Signature';

    /**
     * Replay defense window. Uber Eats requires that we reject any signed
     * envelope whose `t` (timestamp) is more than this many seconds away
     * from server-side `now()`. The value is configurable via
     * config('delivery_platforms.platforms.uber_eats.timestamp_window').
     */
    private const DEFAULT_TIMESTAMP_WINDOW = 300;

    /**
     * Status transition table. Keep aligned with the Uber Eats Direct
     * order lifecycle vocabulary — adding a new internal OrderStatus
     * constant here without checking the platform side will silently
     * break the push (we'd send a string the platform rejects).
     */
    private const STATUS_MAP = [
        OrderStatus::ACCEPT    => 'ACCEPTED',
        OrderStatus::PREPARING => 'IN_PREPARATION',
        OrderStatus::PREPARED  => 'READY_FOR_PICKUP',
        OrderStatus::CANCELED  => 'RESTAURANT_REJECTED',
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
            'delivery_platforms.platforms.uber_eats.timestamp_window',
            self::DEFAULT_TIMESTAMP_WINDOW
        );
        $now    = Carbon::now()->getTimestamp();
        $delta  = abs($now - $parts['t']);
        if ($delta > $window) {
            return false;
        }

        // HMAC over "{timestamp}.{rawBody}" — note the dot, not a comma.
        $payloadToSign = $parts['t'] . '.' . $rawBody;
        $expected      = hash_hmac('sha256', $payloadToSign, $webhookSecret);

        return hash_equals($expected, $parts['h']);
    }

    public function parseOrder(array $payload, int $branchId): NormalizedDeliveryOrder
    {
        $data = $payload['data'] ?? null;
        if (!is_array($data)) {
            throw new OrderMappingException('Uber Eats payload missing `data` envelope.');
        }

        // ---- Customer ----
        $customerNode = Arr::first($data['customers'] ?? []) ?? [];
        $customer = [
            'name'  => Arr::get($customerNode, 'name.display_name')
                ?? trim(
                    (string) Arr::get($customerNode, 'name.first_name', '')
                    . ' '
                    . (string) Arr::get($customerNode, 'name.last_name', '')
                ),
            'phone' => Arr::get($customerNode, 'contact.phone.number'),
        ];

        // ---- Address (delivery only) ----
        $deliveries = $data['deliveries'] ?? [];
        $location   = Arr::first($deliveries)['location'] ?? null;
        $address = [];
        if (is_array($location)) {
            $address = [
                'address'   => trim(
                    (string) Arr::get($location, 'street_address_line_one', '')
                    . ' '
                    . (string) Arr::get($location, 'street_address_line_two', '')
                ),
                'apartment' => Arr::get($location, 'street_address_line_two'),
                'city'      => Arr::get($location, 'city'),
                'country'   => Arr::get($location, 'country'),
                'zip'       => Arr::get($location, 'postal_code'),
                'latitude'  => Arr::get($location, 'latitude'),
                'longitude' => Arr::get($location, 'longitude'),
            ];
        }

        // ---- Items ----
        $items = [];
        foreach (Arr::get($data, 'cart.items', []) as $rawItem) {
            $items[] = new DeliveryItem(
                external_sku:     (string) (Arr::get($rawItem, 'external_data')
                    ?? Arr::get($rawItem, 'id', '')),
                name:             (string) (Arr::get($rawItem, 'title', '')),
                qty:              max(1, (int) Arr::get($rawItem, 'quantity.amount', 1)),
                unit_price_cents: (int) Arr::get($rawItem, 'price.unit_price.amount', 0),
                modifiers:        $this->collectModifiers(Arr::get($rawItem, 'selected_modifier_groups', [])),
            );
        }

        // ---- Totals (cents) ----
        $totals = [
            'subtotal'        => (int) Arr::get($data, 'payment.charges.sub_total.amount', 0),
            'delivery_charge' => (int) Arr::get($data, 'payment.charges.delivery_fee.amount', 0),
            'total'           => (int) Arr::get(
                $data,
                'payment.charges.total.amount',
                Arr::get($data, 'payment.payment_detail.order_total.amount', 0)
            ),
        ];

        // ---- Timestamps ----
        $placedAt = $this->parseTimestamp(
            Arr::get($data, 'placed_at')
                ?? Arr::get($payload, 'event_time')
        ) ?? Carbon::now();

        $expectedReadyAt = $this->parseTimestamp(
            Arr::get($data, 'estimated_ready_for_pickup_at')
        );

        // ---- Pickup type ----
        $fulfillment = strtolower((string) Arr::get($data, 'fulfillment_type', ''));
        $pickupType = match (true) {
            str_contains($fulfillment, 'pickup'), str_contains($fulfillment, 'collection')
                => 'customer_pickup',
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
        $candidates = [
            $payload['data']['id'] ?? null,
            $payload['meta']['resource_id'] ?? null,
            $payload['order_id'] ?? null,
            $payload['id'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && $candidate !== '') {
                return $candidate;
            }
        }

        throw new OrderMappingException(
            'Uber Eats payload missing order id (data.id / meta.resource_id / order_id / id).'
        );
    }

    public function eventTypeFrom(array $payload): string
    {
        $eventType = (string) ($payload['event_type'] ?? '');
        $state     = strtolower((string) (
            $payload['data']['order_state']
            ?? $payload['data']['current_state']
            ?? $payload['meta']['status']
            ?? ''
        ));

        // Map Uber's vocabulary onto ours; strict whitelist —
        // unknowns become 'order.unknown' so the controller can
        // skip routing without dispatching a malformed job.
        if (str_contains($eventType, 'cancel') || $state === 'cancelled' || $state === 'canceled') {
            return 'order.cancelled';
        }

        if (
            str_contains($eventType, 'created')
            || str_contains($eventType, 'notification') // Uber's catch-all event_type
            || $state === 'created'
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
                error:   "No Uber Eats mapping for internal status {$internalStatus}.",
            );
        }

        $credentials = $cfg->credentials ?? [];
        $apiKey      = (string) ($credentials['api_key'] ?? '');
        if ($apiKey === '') {
            return new PushResult(
                success: false,
                error:   'Uber Eats credentials missing api_key.',
            );
        }

        $base = (string) config(
            'delivery_platforms.platforms.uber_eats.base_url',
            'https://api.uber.com'
        );
        $url  = sprintf('%s/v1/eats/orders/%s/status', rtrim($base, '/'), urlencode($externalId));

        try {
            $response = Http::withToken($apiKey)
                ->acceptJson()
                ->asJson()
                ->timeout(8)
                ->retry(2, 250, throw: false)
                ->put($url, ['status' => $platformStatus]);

            return new PushResult(
                success:      $response->successful(),
                http_status:  $response->status(),
                error:        $response->successful() ? null : (string) $response->body(),
                raw_response: is_array($response->json()) ? $response->json() : null,
            );
        } catch (\Throwable $e) {
            Log::warning('[UberEatsAdapter] pushStatus failed', [
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
     * Parse the Uber Eats signature header.
     * Format : "v=1,t=<unix-ts>,k=<keyId>,h=<hex>"
     *
     * @return array{v:string,t:int,k:string,h:string}|null
     */
    private function parseSignatureHeader(string $header): ?array
    {
        $out = [];
        foreach (explode(',', $header) as $segment) {
            $segment = trim($segment);
            if ($segment === '' || !str_contains($segment, '=')) {
                continue;
            }
            [$k, $v] = explode('=', $segment, 2);
            $out[trim($k)] = trim($v);
        }

        if (!isset($out['v'], $out['t'], $out['h'])) {
            return null;
        }

        if (!ctype_digit($out['t'])) {
            return null;
        }

        // h must be hex; reject anything else early so hash_equals
        // never sees odd-length input.
        if (!preg_match('/^[a-f0-9]{64}$/i', $out['h'])) {
            return null;
        }

        return [
            'v' => (string) $out['v'],
            't' => (int) $out['t'],
            'k' => (string) ($out['k'] ?? ''),
            'h' => strtolower((string) $out['h']),
        ];
    }

    /**
     * Flatten Uber's nested modifier groups into a simple array
     * the OrderItem builder can attach to `item_extras`. Returns an
     * array of {sku, name, qty, unit_price_cents} maps.
     *
     * @param  array<int, array<string,mixed>>  $groups
     * @return array<int, array<string,mixed>>
     */
    private function collectModifiers(array $groups): array
    {
        $flat = [];
        foreach ($groups as $group) {
            foreach (Arr::get($group, 'selected_items', []) as $mod) {
                $flat[] = [
                    'sku'              => (string) (Arr::get($mod, 'external_data')
                        ?? Arr::get($mod, 'id', '')),
                    'name'             => (string) Arr::get($mod, 'title', ''),
                    'group'            => (string) Arr::get($group, 'title', ''),
                    'qty'              => max(1, (int) Arr::get($mod, 'quantity.amount', 1)),
                    'unit_price_cents' => (int) Arr::get($mod, 'price.unit_price.amount', 0),
                ];
            }
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
