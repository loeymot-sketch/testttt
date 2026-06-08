<?php

namespace Tests\Feature\Catalog;

use App\Enums\Status;
use App\Http\Requests\ItemExtraRequest;
use App\Http\Requests\ItemVariationRequest;
use App\Http\Resources\ItemExtraResource;
use App\Http\Resources\ItemVariationResource;
use App\Models\Item;
use App\Models\ItemAttribute;
use App\Models\ItemExtra;
use App\Models\ItemVariation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * [GOAL_WIZARD_DYNAMIC_BUILDER Wave 1] Per-option media on catalog constructs.
 *
 * Photo + description persist to the CONSTRUCT (source-binding contract) — never
 * to the wizard step. PRICE stays NF525 SSOT (unchanged). These columns are
 * additive non-fiscal catalog metadata.
 */
class ConstructMediaPersistenceTest extends TestCase
{
    use RefreshDatabase;

    private function makeItem(): Item
    {
        return Item::factory()->create(['status' => Status::ACTIVE]);
    }

    public function test_variation_persists_description_and_image_path(): void
    {
        $item = $this->makeItem();
        $attribute = ItemAttribute::factory()->create(['name' => 'Viande', 'status' => Status::ACTIVE]);

        $variation = ItemVariation::query()->create([
            'item_id'           => $item->id,
            'item_attribute_id' => $attribute->id,
            'name'              => 'Poulet mariné',
            'price'             => 0,
            'status'            => Status::ACTIVE,
            'visible_on'        => ['kiosk', 'pos'],
            'description'       => 'Poulet halal mariné 24h aux épices maison',
            'image_path'        => 'https://cdn.example.test/poulet.png',
        ]);

        $fresh = $variation->fresh();
        $this->assertSame('Poulet halal mariné 24h aux épices maison', $fresh->description);
        $this->assertSame('https://cdn.example.test/poulet.png', $fresh->image_path);
    }

    public function test_extra_persists_description_and_image_path(): void
    {
        $item = $this->makeItem();

        $extra = ItemExtra::query()->create([
            'item_id'     => $item->id,
            'name'        => 'Cheddar fondu',
            'price'       => 1.50,
            'status'      => Status::ACTIVE,
            'visible_on'  => ['kiosk', 'pos'],
            'group_label' => 'Suppléments',
            'description' => 'Tranche de cheddar fondu',
            'image_path'  => 'https://cdn.example.test/cheddar.png',
        ]);

        $fresh = $extra->fresh();
        $this->assertSame('Tranche de cheddar fondu', $fresh->description);
        $this->assertSame('https://cdn.example.test/cheddar.png', $fresh->image_path);
    }

    public function test_stored_image_path_wins_over_config_name_map(): void
    {
        $item = $this->makeItem();
        // Sauce attribute would normally resolve through config/menu_images.php.
        $attribute = ItemAttribute::factory()->create(['name' => 'Sauce', 'status' => Status::ACTIVE]);

        $withStored = ItemVariation::query()->create([
            'item_id'           => $item->id,
            'item_attribute_id' => $attribute->id,
            'name'              => 'Algérienne',
            'price'             => 0,
            'status'            => Status::ACTIVE,
            'image_path'        => 'https://cdn.example.test/algerienne.png',
        ]);
        // Stored URL is returned as-is (no config lookup, no cache-bust on URLs).
        $this->assertSame('https://cdn.example.test/algerienne.png', $withStored->fresh()->thumb);

        $noStored = ItemVariation::query()->create([
            'item_id'           => $item->id,
            'item_attribute_id' => $attribute->id,
            'name'              => 'Andalouse',
            'price'             => 0,
            'status'            => Status::ACTIVE,
        ]);
        // Without a stored image, thumb falls back to the config/default resolver
        // (non-null URL string) — legacy behaviour preserved.
        $thumb = $noStored->fresh()->thumb;
        $this->assertTrue($thumb === null || str_contains((string) $thumb, '/images/menu/'));
        $this->assertStringNotContainsString('cdn.example.test', (string) $thumb);
    }

    public function test_extra_stored_image_path_wins_over_config(): void
    {
        $item = $this->makeItem();
        $extra = ItemExtra::query()->create([
            'item_id'    => $item->id,
            'name'       => 'Sauce Harissa',
            'price'      => 0,
            'status'     => Status::ACTIVE,
            'image_path' => 'https://cdn.example.test/harissa.png',
        ]);
        $this->assertSame('https://cdn.example.test/harissa.png', $extra->fresh()->thumb);
    }

    public function test_requests_accept_nullable_media_fields(): void
    {
        $variationRules = (new ItemVariationRequest())->rules();
        $this->assertArrayHasKey('description', $variationRules);
        $this->assertArrayHasKey('image_path', $variationRules);

        $extraRules = (new ItemExtraRequest())->rules();
        $this->assertArrayHasKey('description', $extraRules);
        $this->assertArrayHasKey('image_path', $extraRules);

        // Empty media is valid (nullable) — only the non-media rules apply.
        $payload = ['name' => 'X', 'price' => 1, 'status' => 1, 'description' => null, 'image_path' => null];
        $this->assertFalse(
            Validator::make($payload, ['description' => $variationRules['description'], 'image_path' => $variationRules['image_path']])->fails()
        );
    }

    public function test_resources_emit_media_fields(): void
    {
        $item = $this->makeItem();
        $variation = ItemVariation::query()->create([
            'item_id' => $item->id, 'item_attribute_id' => ItemAttribute::factory()->create()->id,
            'name' => 'V', 'price' => 0, 'status' => Status::ACTIVE,
            'description' => 'desc-v', 'image_path' => 'https://cdn.example.test/v.png',
        ]);
        $extra = ItemExtra::query()->create([
            'item_id' => $item->id, 'name' => 'E', 'price' => 0, 'status' => Status::ACTIVE,
            'description' => 'desc-e', 'image_path' => 'https://cdn.example.test/e.png',
        ]);

        $vArr = (new ItemVariationResource($variation))->toArray(request());
        $this->assertSame('desc-v', $vArr['description']);
        $this->assertSame('https://cdn.example.test/v.png', $vArr['image_path']);

        $eArr = (new ItemExtraResource($extra))->toArray(request());
        $this->assertSame('desc-e', $eArr['description']);
        $this->assertSame('https://cdn.example.test/e.png', $eArr['image_path']);
    }
}
