<?php

namespace App\Domain\Delivery;

/**
 * [PARALLEL-TRACK-1.1 / Delivery Platform Integration — Phase 1]
 *
 * Single line item inside a {@see NormalizedDeliveryOrder}.
 *
 * `unit_price_cents` is INTEGER cents — chosen deliberately over
 * decimal to avoid float-drift during fan-in from three different
 * platforms. Rounding to FoodKing's decimal:6 happens later, at
 * the boundary where we hydrate the internal Order.
 *
 * `modifiers` is an opaque array: each adapter is responsible for
 * normalising its own platform-specific modifier shape, but the
 * downstream Order builder treats it as "extra data to attach to
 * the OrderItem" rather than peeking into it directly.
 */
final class DeliveryItem
{
    /**
     * @param  array<int, array<string, mixed>>  $modifiers
     */
    public function __construct(
        public readonly string $external_sku,
        public readonly string $name,
        public readonly int    $qty,
        public readonly int    $unit_price_cents,
        public readonly array  $modifiers = [],
    ) {
    }
}
