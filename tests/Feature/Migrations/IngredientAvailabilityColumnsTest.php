<?php

namespace Tests\Feature\Migrations;

use App\Models\Item;
use App\Models\ItemAttribute;
use App\Models\ItemExtra;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class IngredientAvailabilityColumnsTest extends TestCase
{
    use RefreshDatabase;

    public function test_availability_columns_exist_on_ingredient_tables(): void
    {
        $this->assertTrue(Schema::hasColumn('item_attributes', 'is_available'));
        $this->assertTrue(Schema::hasColumn('item_attributes', 'unavailable_reason'));
        $this->assertTrue(Schema::hasColumn('item_extras', 'is_available'));
        $this->assertTrue(Schema::hasColumn('item_extras', 'unavailable_reason'));
    }

    public function test_is_available_defaults_to_true_for_new_ingredients(): void
    {
        $attribute = ItemAttribute::create([
            'name' => 'Sauce',
            'status' => 1,
        ]);

        $extra = ItemExtra::create([
            'item_id' => Item::factory()->create()->id,
            'name' => 'Supplement',
            'status' => 1,
            'price' => 1.50,
        ]);

        $this->assertTrue($attribute->refresh()->is_available);
        $this->assertTrue($extra->refresh()->is_available);
    }
}
