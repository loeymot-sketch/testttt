<?php

namespace Tests\Feature;

use App\Enums\Status;
use App\Models\Branch;
use App\Models\Item;
use App\Models\ItemAttribute;
use App\Models\ItemCategory;
use App\Models\ItemVariation;
use App\Models\PosParkedOrder;
use App\Models\Tax;
use App\Models\User;
use App\Enums\TaxType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PosParkedRecallVariationAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;
    private User $operator;
    private Item $item;
    private ItemAttribute $attr;
    private ItemVariation $v1;
    private ItemVariation $v2;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        $this->branch = Branch::factory()->create();
        $this->operator = User::factory()->create([
            'branch_id' => $this->branch->id,
        ]);
        $this->operator->assignRole('POS Operator');

        $tax = Tax::factory()->create([
            'name' => 'TVA 10%',
            'code' => 'TVA10',
            'tax_rate' => 10,
            'type' => TaxType::PERCENTAGE,
            'status' => Status::ACTIVE,
        ]);

        $category = ItemCategory::factory()->create([
            'name' => 'Parity Cat',
            'wizard_template' => 'tacos',
            'has_menu' => true,
        ]);

        $this->item = Item::factory()->create([
            'item_category_id' => $category->id,
            'tax_id' => $tax->id,
            'price' => 10.00,
            'status' => Status::ACTIVE,
            'is_available' => true,
        ]);

        $this->attr = ItemAttribute::query()->create([
            'name' => 'Viande ' . uniqid(),
            'status' => Status::ACTIVE,
            'min_select' => 1,
            'max_select' => 4,
            'allow_repeat' => true,
        ]);

        $this->v1 = ItemVariation::query()->create([
            'item_id' => $this->item->id,
            'item_attribute_id' => $this->attr->id,
            'name' => 'Kebab',
            'price' => 2.00,
            'new_price' => 2.00,
            'status' => Status::ACTIVE,
        ]);

        $this->v2 = ItemVariation::query()->create([
            'item_id' => $this->item->id,
            'item_attribute_id' => $this->attr->id,
            'name' => 'Merguez',
            'price' => 2.50,
            'new_price' => 2.50,
            'status' => Status::ACTIVE,
        ]);
    }

    public function test_recall_drops_unavailable_variation_and_returns_warning(): void
    {
        $this->v2->status = Status::INACTIVE;
        $this->v2->save();

        $parked = PosParkedOrder::query()->create([
            'branch_id' => $this->branch->id,
            'user_id' => $this->operator->id,
            'label' => 'Recall G3',
            'payload_json' => $this->payloadWithVariations([
                ['id' => $this->v1->id, 'item_attribute_id' => $this->attr->id, 'quantity' => 1, 'name' => 'Kebab'],
                ['id' => $this->v2->id, 'item_attribute_id' => $this->attr->id, 'quantity' => 1, 'name' => 'Merguez'],
            ]),
            'preview_total' => 99.00,
            'items_count' => 1,
            'idempotency_token' => 'g3-1',
        ]);

        $response = $this->actingAs($this->operator, 'sanctum')
            ->getJson("/api/admin/pos/parked-orders/{$parked->id}");

        $response->assertOk();
        $vars = $response->json('data.payload_json.lists.0.item_variations');
        $this->assertCount(1, $vars);
        $this->assertSame($this->v1->id, $vars[0]['id']);

        $warnings = $response->json('data.warnings.unavailable_variations');
        $this->assertCount(1, $warnings);
        $this->assertSame($this->item->id, $warnings[0]['item_id']);
        $this->assertSame($this->v2->id, $warnings[0]['variation_id']);
        $this->assertSame('Merguez', $warnings[0]['name']);
    }

    public function test_recall_succeeds_unchanged_when_all_variations_available(): void
    {
        $parked = PosParkedOrder::query()->create([
            'branch_id' => $this->branch->id,
            'user_id' => $this->operator->id,
            'label' => 'Recall OK',
            'payload_json' => $this->payloadWithVariations([
                ['id' => $this->v1->id, 'item_attribute_id' => $this->attr->id, 'quantity' => 2, 'name' => 'Kebab'],
            ]),
            'preview_total' => 99.00,
            'items_count' => 1,
            'idempotency_token' => 'g3-2',
        ]);

        $response = $this->actingAs($this->operator, 'sanctum')
            ->getJson("/api/admin/pos/parked-orders/{$parked->id}");

        $response->assertOk();
        $vars = $response->json('data.payload_json.lists.0.item_variations');
        $this->assertCount(1, $vars);
        $this->assertSame(2, (int) $vars[0]['quantity']);
        $this->assertSame([], $response->json('data.warnings.unavailable_variations'));
    }

    public function test_recall_returns_warning_count_matching_dropped_variations(): void
    {
        $v3 = ItemVariation::query()->create([
            'item_id' => $this->item->id,
            'item_attribute_id' => $this->attr->id,
            'name' => 'Third',
            'price' => 1.00,
            'new_price' => 1.00,
            'status' => Status::INACTIVE,
        ]);

        $parked = PosParkedOrder::query()->create([
            'branch_id' => $this->branch->id,
            'user_id' => $this->operator->id,
            'label' => 'Recall multi-drop',
            'payload_json' => $this->payloadWithVariations([
                ['id' => $this->v2->id, 'item_attribute_id' => $this->attr->id, 'quantity' => 1, 'name' => 'Merguez'],
                ['id' => $v3->id, 'item_attribute_id' => $this->attr->id, 'quantity' => 1, 'name' => 'Third'],
            ]),
            'preview_total' => 99.00,
            'items_count' => 1,
            'idempotency_token' => 'g3-3',
        ]);

        $this->v2->status = Status::INACTIVE;
        $this->v2->save();

        $response = $this->actingAs($this->operator, 'sanctum')
            ->getJson("/api/admin/pos/parked-orders/{$parked->id}");

        $response->assertOk();
        $warnings = $response->json('data.warnings.unavailable_variations');
        $this->assertCount(2, $warnings);
        $this->assertCount(0, $response->json('data.payload_json.lists.0.item_variations'));
    }

    /**
     * @param  array<int, array<string, mixed>>  $variations
     * @return array<string, mixed>
     */
    private function payloadWithVariations(array $variations): array
    {
        return [
            'lists' => [
                [
                    'item_id' => $this->item->id,
                    'name' => $this->item->name,
                    'quantity' => 1,
                    'instruction' => '',
                    'item_variations' => $variations,
                    'item_extras' => [],
                ],
            ],
            'subtotal' => 28.50,
            'discount' => 0,
            'total' => 28.50,
            'checkout_form' => [
                'customer_id' => 22,
                'order_type' => 10,
                'dining_table_id' => null,
                'address_id' => null,
                'delivery_charge' => 0,
            ],
        ];
    }
}
