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
}
