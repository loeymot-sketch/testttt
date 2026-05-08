<?php

namespace App\Domain\Delivery;

use Carbon\Carbon;

/**
 * [PARALLEL-TRACK-1.1 / Delivery Platform Integration — Phase 1]
 *
 * Immutable normalised view of an inbound delivery-platform order.
 *
 * This DTO is the seam between platform-specific adapters
 * (UberEats / Deliveroo / Delicity) and the FoodKing Order
 * factory. Adapters parse their own payload shape into this
 * canonical form; the Order factory only consumes this DTO.
 *
 * Money invariant: ALL monetary fields are integer cents. The
 * adapter is responsible for the platform-side conversion (e.g.
 * Uber Eats sends cents already, Deliveroo sends a string with
 * two decimals, etc.). FoodKing's Order layer (decimal:6) does
 * the int-to-decimal cast at hydration.
 *
 * Pickup model:
 *   - 'delivery'        : platform-driven delivery (courier).
 *   - 'customer_pickup' : customer comes in person (Uber Eats
 *                         "pickup" mode, Deliveroo collection,
 *                         Delicity emporter).
 *
 * @property-read array<string,mixed>          $customer
 * @property-read array<string,mixed>          $address
 * @property-read array<int,DeliveryItem>      $items
 * @property-read array<string,int>            $totals
 */
final class NormalizedDeliveryOrder
{
    /**
     * @param  array{name:string, phone:string|null}  $customer
     * @param  array<string,mixed>                    $address
     * @param  array<int,DeliveryItem>                $items
     * @param  array{subtotal:int, delivery_charge:int, total:int}  $totals
     */
    public function __construct(
        public readonly array   $customer,
        public readonly array   $address,
        public readonly array   $items,
        public readonly array   $totals,
        public readonly Carbon  $placed_at,
        public readonly ?Carbon $expected_ready_at,
        public readonly string  $pickup_type,
    ) {
    }
}
