<?php

namespace Tests\Unit\Domain\Delivery;

use App\Domain\Delivery\DeliveryItem;
use App\Domain\Delivery\NormalizedDeliveryOrder;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

/**
 * [PARALLEL-TRACK-1.1 / Delivery Platform Integration — Phase 1]
 *
 * Pure unit test for the NormalizedDeliveryOrder DTO. No DB,
 * no Laravel app — extends PHPUnit\Framework\TestCase directly
 * so it runs in <1ms.
 *
 * Invariants under test:
 *   - all monetary fields are integer cents (NOT float / decimal);
 *   - readonly props enforce immutability (assignment throws);
 *   - items array preserves DeliveryItem typing;
 *   - expected_ready_at can be null while placed_at cannot.
 */
class NormalizedDeliveryOrderTest extends TestCase
{
    public function test_constructs_with_all_required_fields(): void
    {
        $placed   = Carbon::parse('2026-05-08 12:00:00');
        $ready    = Carbon::parse('2026-05-08 12:30:00');

        $item = new DeliveryItem(
            external_sku: 'SKU-1',
            name: 'Cheeseburger',
            qty: 2,
            unit_price_cents: 850,
            modifiers: [['name' => 'No onion', 'price_cents' => 0]],
        );

        $dto = new NormalizedDeliveryOrder(
            customer:           ['name' => 'Alice', 'phone' => '+33600000000'],
            address:            ['line1' => '1 rue Test', 'city' => 'Paris', 'zip' => '75001'],
            items:              [$item],
            totals:             ['subtotal' => 1700, 'delivery_charge' => 250, 'total' => 1950],
            placed_at:          $placed,
            expected_ready_at:  $ready,
            pickup_type:        'delivery',
        );

        $this->assertSame('Alice', $dto->customer['name']);
        $this->assertSame(1, count($dto->items));
        $this->assertInstanceOf(DeliveryItem::class, $dto->items[0]);
        $this->assertSame(850, $dto->items[0]->unit_price_cents);
        $this->assertIsInt($dto->items[0]->unit_price_cents);
        $this->assertSame(1700, $dto->totals['subtotal']);
        $this->assertIsInt($dto->totals['subtotal']);
        $this->assertSame(250, $dto->totals['delivery_charge']);
        $this->assertSame(1950, $dto->totals['total']);
        $this->assertSame('delivery', $dto->pickup_type);
        $this->assertTrue($dto->placed_at->equalTo($placed));
        $this->assertTrue($dto->expected_ready_at->equalTo($ready));
    }

    public function test_expected_ready_at_can_be_null(): void
    {
        $dto = new NormalizedDeliveryOrder(
            customer:           ['name' => 'Bob', 'phone' => null],
            address:            [],
            items:              [],
            totals:             ['subtotal' => 0, 'delivery_charge' => 0, 'total' => 0],
            placed_at:          Carbon::parse('2026-05-08 13:00:00'),
            expected_ready_at:  null,
            pickup_type:        'customer_pickup',
        );

        $this->assertNull($dto->expected_ready_at);
        $this->assertSame('customer_pickup', $dto->pickup_type);
    }

    public function test_dto_is_immutable_readonly(): void
    {
        $dto = new NormalizedDeliveryOrder(
            customer:           ['name' => 'Imm', 'phone' => null],
            address:            [],
            items:              [],
            totals:             ['subtotal' => 0, 'delivery_charge' => 0, 'total' => 0],
            placed_at:          Carbon::now(),
            expected_ready_at:  null,
            pickup_type:        'delivery',
        );

        $this->expectException(\Error::class);
        // PHP 8.1 readonly: assignment throws Error("Cannot modify readonly property").
        $dto->pickup_type = 'customer_pickup'; // @phpstan-ignore-line
    }

    public function test_delivery_item_modifiers_default_empty(): void
    {
        $item = new DeliveryItem(
            external_sku: 'X',
            name: 'Soda',
            qty: 1,
            unit_price_cents: 250,
        );

        $this->assertSame([], $item->modifiers);
        $this->assertIsInt($item->unit_price_cents);
    }
}
