<?php

namespace Tests\Feature\Requests;

use App\Http\Requests\ItemRequest;
use App\Models\ItemCategory;
use App\Models\Tax;
use Database\Seeders\AllergensSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class ItemRequestTest extends TestCase
{
    use RefreshDatabase;

    public function test_accepts_all_kiosk_flags(): void
    {
        $this->seed(AllergensSeeder::class);

        $category = ItemCategory::factory()->create();
        $tax = Tax::factory()->create();

        $payload = [
            'name' => 'Tacos signature',
            'item_category_id' => $category->id,
            'tax_id' => $tax->id,
            'item_type' => 1,
            'price' => '12.50',
            'is_featured' => 1,
            'is_chef_pick' => true,
            'is_new' => true,
            'is_available' => true,
            'is_spicy' => false,
            'is_vegetarian' => false,
            'is_pork_free' => true,
            'is_halal' => true,
            'is_gluten_free' => false,
            'chef_pick_order' => 7,
            'channels' => ['kiosk', 'web'],
            'allergen_flags' => ['gluten', 'oeufs'],
            'kiosk_emoji' => '🌮',
            'status' => 1,
            'order' => 1,
        ];

        $validator = Validator::make($payload, (new ItemRequest)->rules());

        $this->assertFalse(
            $validator->fails(),
            json_encode($validator->errors()->toArray(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
        );
    }

    public function test_rejects_invalid_nested_modifier_surface_contract(): void
    {
        $this->seed(AllergensSeeder::class);

        $category = ItemCategory::factory()->create();
        $tax = Tax::factory()->create();

        $payload = $this->validPayload($category->id, $tax->id, [
            'variations' => json_encode([
                [
                    'name' => 'Poulet',
                    'item_attribute_id' => 1,
                    'price' => 0,
                    'status' => 1,
                    'visible_on' => ['kiosk', 'terminal'],
                ],
            ]),
            'extras' => json_encode([
                [
                    'name' => 'Cheddar',
                    'price' => 1.5,
                    'status' => 1,
                    'visible_on' => 'kiosk',
                ],
            ]),
        ]);

        $request = ItemRequest::create('/api/admin/item', 'POST', $payload);
        $validator = Validator::make($payload, $request->rules());
        $request->withValidator($validator);

        $this->assertTrue($validator->fails());
        $errors = $validator->errors()->toArray();
        $this->assertArrayHasKey('variations.0.visible_on.1', $errors);
        $this->assertArrayHasKey('extras.0.visible_on', $errors);
    }

    /**
     * [abuse-heal 2026-06-20 W5 CAT-MOD-PRICE-CAP-01] The bulk nested-modifier price guard rejected
     * negatives but NOT out-of-range magnitudes — a 13+ digit price persisted into the catalog +
     * the frozen PricingService and overflowed order_quotes.subtotal (SQLSTATE 22003) at quote time.
     * The dedicated /variation,/extra endpoints already cap this via IniAmount; the bulk path now does too.
     */
    public function test_rejects_out_of_range_nested_modifier_price(): void
    {
        $this->seed(AllergensSeeder::class);
        $category = ItemCategory::factory()->create();
        $tax = Tax::factory()->create();

        $payload = $this->validPayload($category->id, $tax->id, [
            'extras' => json_encode([
                ['name' => 'Huge Extra', 'price' => 9999999999999.00, 'status' => 1, 'visible_on' => 'kiosk'],
            ]),
        ]);

        $request = ItemRequest::create('/api/admin/item', 'POST', $payload);
        $validator = Validator::make($payload, $request->rules());
        $request->withValidator($validator);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('extras.0.price', $validator->errors()->toArray());

        // Control: an in-range price passes the magnitude cap (no extras.0.price error).
        $okPayload = $this->validPayload($category->id, $tax->id, [
            'extras' => json_encode([
                ['name' => 'Normal Extra', 'price' => 1.5, 'status' => 1, 'visible_on' => 'kiosk'],
            ]),
        ]);
        $okRequest = ItemRequest::create('/api/admin/item', 'POST', $okPayload);
        $okValidator = Validator::make($okPayload, $okRequest->rules());
        $okRequest->withValidator($okValidator);
        $okValidator->fails(); // run the validation
        $this->assertArrayNotHasKey('extras.0.price', $okValidator->errors()->toArray());
    }

    public function test_accepts_valid_nested_modifier_surface_contract(): void
    {
        $this->seed(AllergensSeeder::class);

        $category = ItemCategory::factory()->create();
        $tax = Tax::factory()->create();

        $payload = $this->validPayload($category->id, $tax->id, [
            'variations' => json_encode([
                [
                    'name' => 'Poulet',
                    'item_attribute_id' => 1,
                    'price' => 0,
                    'status' => 1,
                    'visible_on' => ['kiosk', 'pos'],
                ],
            ]),
            'extras' => json_encode([
                [
                    'name' => 'Cheddar',
                    'price' => 1.5,
                    'status' => 1,
                    'visible_on' => ['web'],
                ],
            ]),
        ]);

        $request = ItemRequest::create('/api/admin/item', 'POST', $payload);
        $validator = Validator::make($payload, $request->rules());
        $request->withValidator($validator);

        $this->assertFalse(
            $validator->fails(),
            json_encode($validator->errors()->toArray(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
        );
    }

    private function validPayload(int $categoryId, int $taxId, array $overrides = []): array
    {
        return array_merge([
            'name' => 'Produit central ' . uniqid(),
            'item_category_id' => $categoryId,
            'tax_id' => $taxId,
            'item_type' => 1,
            'price' => '12.50',
            'is_featured' => 1,
            'status' => 1,
            'order' => 1,
        ], $overrides);
    }
}
