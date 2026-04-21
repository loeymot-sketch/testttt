<?php

namespace Tests\Feature;

use App\Enums\Status;
use App\Models\Branch;
use App\Models\Item;
use App\Models\ItemAttribute;
use App\Models\ItemCategory;
use App\Models\ItemExtra;
use App\Models\ItemVariation;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\Pricing\CompositionSnapshotBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * [T07] composition_snapshot column + builder + resource fallback.
 *
 * Coverage:
 *  1) migration adds the column
 *  2) cast as array works on Eloquent
 *  3) legacy rows (snapshot=null) keep working through resource fallback
 *  4) when snapshot is present, the resource prefers it
 *  5) snapshot remains immutable when DB variation/extra is renamed afterwards
 *  6) builder honours the optional `quantity` field
 *
 * NF525 invariant: once an OrderItem row is inserted with a non-null snapshot,
 * the JSON content is the source of truth for any reprint, and must NOT be
 * recomputed.
 */
class OrderItemCompositionSnapshotTest extends TestCase
{
    use RefreshDatabase;

    private function makeOrderFor(int $itemId): Order
    {
        return Order::factory()->create();
    }

    public function test_column_composition_snapshot_exists_after_migration(): void
    {
        $this->assertTrue(
            Schema::hasColumn('order_items', 'composition_snapshot'),
            'order_items.composition_snapshot must exist after T07 migration.'
        );
    }

    public function test_orderitem_cast_to_array(): void
    {
        $reflection = new \ReflectionClass(OrderItem::class);
        $casts = $reflection->getDefaultProperties()['casts'] ?? [];
        $this->assertSame('array', $casts['composition_snapshot'] ?? null,
            'OrderItem must cast composition_snapshot to array.');
    }

    public function test_legacy_orderitem_with_null_snapshot_works_in_resource_fallback(): void
    {
        $cat = ItemCategory::factory()->create(['wizard_template' => 'tacos', 'has_menu' => true]);
        $item = Item::factory()->create(['item_category_id' => $cat->id, 'price' => 10.0, 'status' => Status::ACTIVE]);
        $order = Order::factory()->create();
        $attr = ItemAttribute::query()->create([
            'name' => 'Sauce', 'status' => Status::ACTIVE,
            'min_select' => 0, 'max_select' => 1, 'allow_repeat' => false,
        ]);
        $var = ItemVariation::query()->create([
            'item_id' => $item->id, 'item_attribute_id' => $attr->id,
            'name' => 'Algerienne', 'price' => 1.0, 'new_price' => 1.0, 'status' => Status::ACTIVE,
        ]);

        // Insert legacy row directly (composition_snapshot = null)
        $row = OrderItem::query()->create([
            'order_id'             => $order->id,
            'branch_id'            => $order->branch_id,
            'item_id'              => $item->id,
            'quantity'             => 1,
            'discount'             => 0,
            'tax_name'             => 'TVA',
            'tax_rate'             => 10,
            'tax_type'             => \App\Enums\TaxType::PERCENTAGE,
            'tax_amount'           => 1.0,
            'price'                => 10.0,
            'item_variations'      => json_encode([['id' => $var->id]]),
            'item_extras'          => json_encode([]),
            'composition_snapshot' => null,
            'item_variation_total' => 1.0,
            'item_extra_total'     => 0.0,
            'total_price'          => 11.0,
        ]);

        $this->assertNull($row->composition_snapshot);

        $resource = new \App\Http\Resources\OrderItemResource($row->fresh());
        $arr = $resource->toArray(request());
        // Legacy fallback : variations resolved from item_variations JSON.
        $this->assertIsArray($arr['item_variations']);
        $this->assertCount(1, $arr['item_variations']);
    }

    public function test_resource_prefers_snapshot_over_legacy_field(): void
    {
        $cat = ItemCategory::factory()->create(['wizard_template' => 'tacos', 'has_menu' => true]);
        $item = Item::factory()->create(['item_category_id' => $cat->id, 'price' => 10.0, 'status' => Status::ACTIVE]);
        $order = Order::factory()->create();
        $attr = ItemAttribute::query()->create([
            'name' => 'Viande', 'status' => Status::ACTIVE,
            'min_select' => 1, 'max_select' => 4, 'allow_repeat' => true,
        ]);
        $var = ItemVariation::query()->create([
            'item_id' => $item->id, 'item_attribute_id' => $attr->id,
            'name' => 'Kebab', 'price' => 2.0, 'new_price' => 2.0, 'status' => Status::ACTIVE,
        ]);

        $snapshot = (new CompositionSnapshotBuilder())->build(
            (object) [
                'item_variations' => [(object) ['id' => $var->id, 'quantity' => 4]],
                'item_extras' => [],
            ],
            collect([$var->id => $var]),
            collect()
        );

        $row = OrderItem::query()->create([
            'order_id'             => $order->id,
            'branch_id'            => $order->branch_id,
            'item_id'              => $item->id,
            'quantity'             => 1,
            'discount'             => 0,
            'tax_name'             => 'TVA',
            'tax_rate'             => 10,
            'tax_type'             => \App\Enums\TaxType::PERCENTAGE,
            'tax_amount'           => 1.8,
            'price'                => 10.0,
            'item_variations'      => json_encode([['id' => $var->id, 'quantity' => 4]]),
            'item_extras'          => json_encode([]),
            // Eloquent ::create() activates the 'array' cast, so pass the raw array.
            // (Mass insert in OrderService/FrontendOrderService bypasses the cast and
            //  pre-encodes via json_encode — see CompositionSnapshotBuilder usage there.)
            'composition_snapshot' => $snapshot,
            'item_variation_total' => 8.0,
            'item_extra_total'     => 0.0,
            'total_price'          => 18.0,
        ]);

        $arr = (new \App\Http\Resources\OrderItemResource($row->fresh()))->toArray(request());
        $this->assertNotEmpty($arr['composition_snapshot']);
        $this->assertSame(1, $arr['composition_snapshot']['schema_version']);
        // Resource resolved variations from snapshot lines (rich shape).
        $this->assertSame('Kebab', $arr['item_variations'][0]['variation_name']);
        $this->assertSame(4, $arr['item_variations'][0]['quantity']);
    }

    public function test_snapshot_is_immutable_after_variation_rename(): void
    {
        $cat = ItemCategory::factory()->create(['wizard_template' => 'tacos', 'has_menu' => true]);
        $item = Item::factory()->create(['item_category_id' => $cat->id, 'price' => 10.0, 'status' => Status::ACTIVE]);
        $order = Order::factory()->create();
        $attr = ItemAttribute::query()->create([
            'name' => 'Sauce', 'status' => Status::ACTIVE,
            'min_select' => 0, 'max_select' => 1, 'allow_repeat' => false,
        ]);
        $var = ItemVariation::query()->create([
            'item_id' => $item->id, 'item_attribute_id' => $attr->id,
            'name' => 'Algerienne', 'price' => 1.0, 'new_price' => 1.0, 'status' => Status::ACTIVE,
        ]);

        $snapshot = (new CompositionSnapshotBuilder())->build(
            (object) ['item_variations' => [(object) ['id' => $var->id]], 'item_extras' => []],
            collect([$var->id => $var]),
            collect()
        );

        $row = OrderItem::query()->create([
            'order_id'             => $order->id,
            'branch_id'            => $order->branch_id,
            'item_id'              => $item->id,
            'quantity'             => 1,
            'discount'             => 0,
            'tax_name'             => 'TVA',
            'tax_rate'             => 10,
            'tax_type'             => \App\Enums\TaxType::PERCENTAGE,
            'tax_amount'           => 1.1,
            'price'                => 10.0,
            'item_variations'      => json_encode([['id' => $var->id]]),
            'item_extras'          => json_encode([]),
            'composition_snapshot' => $snapshot,
            'item_variation_total' => 1.0,
            'item_extra_total'     => 0.0,
            'total_price'          => 11.0,
        ]);

        // Now rename / reprice the variation in the catalog AFTER the order is sealed.
        $var->update(['name' => 'Sauce Blanche', 'price' => 99.99]);

        // Reload OrderItem and read snapshot — the old name + price must remain.
        $reloaded = OrderItem::query()->find($row->id);
        $this->assertSame('Algerienne', $reloaded->composition_snapshot['lines'][0]['variation_name']);
        // JSON round-trip collapses 1.0 → 1, so use assertEquals for numeric type tolerance.
        $this->assertEquals(1.0, $reloaded->composition_snapshot['lines'][0]['unit_price']);
    }

    public function test_builder_supports_quantity_field(): void
    {
        $cat = ItemCategory::factory()->create(['wizard_template' => 'tacos', 'has_menu' => true]);
        $item = Item::factory()->create(['item_category_id' => $cat->id, 'price' => 10.0, 'status' => Status::ACTIVE]);
        $attr = ItemAttribute::query()->create([
            'name' => 'Viande', 'status' => Status::ACTIVE,
            'min_select' => 1, 'max_select' => 4, 'allow_repeat' => true,
        ]);
        $kebab = ItemVariation::query()->create([
            'item_id' => $item->id, 'item_attribute_id' => $attr->id,
            'name' => 'Kebab', 'price' => 2.0, 'new_price' => 2.0, 'status' => Status::ACTIVE,
        ]);
        $merguez = ItemVariation::query()->create([
            'item_id' => $item->id, 'item_attribute_id' => $attr->id,
            'name' => 'Merguez', 'price' => 2.5, 'new_price' => 2.5, 'status' => Status::ACTIVE,
        ]);
        $cheddar = ItemExtra::query()->create([
            'item_id' => $item->id,
            'name' => 'Cheddar', 'price' => 1.0, 'new_price' => 1.0, 'status' => Status::ACTIVE,
        ]);

        $snapshot = (new CompositionSnapshotBuilder())->build(
            (object) [
                'item_variations' => [
                    (object) ['id' => $kebab->id, 'quantity' => 3],
                    (object) ['id' => $merguez->id, 'quantity' => 1],
                ],
                'item_extras' => [
                    (object) ['id' => $cheddar->id, 'quantity' => 2],
                ],
            ],
            collect([$kebab->id => $kebab, $merguez->id => $merguez]),
            collect([$cheddar->id => $cheddar])
        );

        $this->assertSame(1, $snapshot['schema_version']);
        $this->assertCount(2, $snapshot['lines']);
        $this->assertCount(1, $snapshot['extras']);

        $kebabLine = collect($snapshot['lines'])->firstWhere('variation_id', $kebab->id);
        $this->assertSame(3, $kebabLine['quantity']);
        $this->assertSame(6.0, $kebabLine['line_total']);

        $cheddarLine = $snapshot['extras'][0];
        $this->assertSame(2, $cheddarLine['quantity']);
        $this->assertSame(2.0, $cheddarLine['line_total']);
    }
}
