<?php

namespace Tests\Feature\Ingredients;

use App\Enums\Status;
use App\Models\Item;
use App\Models\ItemAddon;
use App\Models\ItemAttribute;
use App\Models\ItemExtra;
use App\Services\Ingredients\IngredientService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IngredientServiceListTest extends TestCase
{
    use RefreshDatabase;

    private IngredientService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedMinimalSettings();
        $this->service = app(IngredientService::class);
    }

    public function test_it_lists_attributes_extras_and_addons_unified(): void
    {
        [$attributes, $extras, $addon] = $this->seedIngredients();

        $ingredients = $this->service->listAll();
        $globalIds = $ingredients->pluck('global_id')->all();

        $this->assertCount(5, $ingredients);
        foreach ($attributes as $attribute) {
            $this->assertContains(IngredientService::globalId(IngredientService::TYPE_ATTRIBUTE, $attribute->id), $globalIds);
        }
        foreach ($extras as $extra) {
            $this->assertContains(IngredientService::globalId(IngredientService::TYPE_EXTRA, $extra->id), $globalIds);
        }
        $this->assertContains(IngredientService::globalId(IngredientService::TYPE_ADDON, $addon->id), $globalIds);
    }

    public function test_it_filters_by_type(): void
    {
        $this->seedIngredients();

        $attributes = $this->service->listByType(IngredientService::TYPE_ATTRIBUTE);
        $extras = $this->service->listByType(IngredientService::TYPE_EXTRA);
        $addons = $this->service->listByType(IngredientService::TYPE_ADDON);

        $this->assertCount(2, $attributes);
        $this->assertCount(2, $extras);
        $this->assertCount(1, $addons);
        $this->assertTrue($attributes->every(fn (array $ingredient): bool => $ingredient['type'] === IngredientService::TYPE_ATTRIBUTE));
        $this->assertTrue($extras->every(fn (array $ingredient): bool => $ingredient['type'] === IngredientService::TYPE_EXTRA));
        $this->assertTrue($addons->every(fn (array $ingredient): bool => $ingredient['type'] === IngredientService::TYPE_ADDON));
    }

    public function test_it_finds_by_global_id(): void
    {
        $attribute = ItemAttribute::factory()->create([
            'id' => 42,
            'name' => 'Sauce samourai',
        ]);

        $found = $this->service->findByGlobalId('attribute:42');

        $this->assertNotNull($found);
        $this->assertSame($attribute->id, $found['id']);
        $this->assertSame('attribute:42', $found['global_id']);
        $this->assertNull($this->service->findByGlobalId('invalid'));
    }

    private function seedIngredients(): array
    {
        $item = Item::factory()->create();
        $addonItem = Item::factory()->create(['name' => 'Cola']);
        $attributes = ItemAttribute::factory()->count(2)->create();
        $extras = collect([
            ItemExtra::query()->create([
                'item_id' => $item->id,
                'name' => 'Sauce blanche',
                'price' => 0.50,
                'status' => Status::ACTIVE,
                'group_label' => 'sauces',
                'is_available' => true,
            ]),
            ItemExtra::query()->create([
                'item_id' => $item->id,
                'name' => 'Sauce algerienne',
                'price' => 0.50,
                'status' => Status::ACTIVE,
                'group_label' => 'sauces',
                'is_available' => true,
            ]),
        ]);
        $addon = ItemAddon::query()->create([
            'item_id' => $item->id,
            'addon_item_id' => $addonItem->id,
            'role' => 'drink',
        ]);

        return [$attributes, $extras, $addon];
    }
}
