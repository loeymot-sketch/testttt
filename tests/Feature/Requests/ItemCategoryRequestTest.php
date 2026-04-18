<?php

namespace Tests\Feature\Requests;

use App\Http\Requests\ItemCategoryRequest;
use App\Models\ItemCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class ItemCategoryRequestTest extends TestCase
{
    use RefreshDatabase;

    public function test_accepts_hierarchy_and_channels(): void
    {
        $parent = ItemCategory::factory()->create([
            'status' => 1,
        ]);

        $payload = [
            'name' => 'Menus midi',
            'parent_id' => $parent->id,
            'description' => 'Selection borne et POS',
            'status' => 1,
            'channels' => ['kiosk', 'pos'],
            'kiosk_sort' => 12,
            'pos_sort' => 4,
            'kiosk_label' => 'Menus',
            'wizard_template' => 'simple',
        ];

        $validator = Validator::make($payload, (new ItemCategoryRequest())->rules());

        $this->assertFalse(
            $validator->fails(),
            json_encode($validator->errors()->toArray(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
        );
    }
}
