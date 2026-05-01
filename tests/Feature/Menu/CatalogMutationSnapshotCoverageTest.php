<?php

namespace Tests\Feature\Menu;

use App\Enums\Status;
use App\Events\ItemAvailabilityChanged;
use App\Http\Requests\ItemAddonRequest;
use App\Http\Requests\ItemExtraRequest;
use App\Http\Requests\ItemVariationRequest;
use App\Models\Item;
use App\Models\ItemAttribute;
use App\Services\ItemAddonService;
use App\Services\ItemExtraService;
use App\Services\ItemVariationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class CatalogMutationSnapshotCoverageTest extends TestCase
{
    use RefreshDatabase;

    public function test_variation_mutations_emit_full_item_refresh(): void
    {
        $item = Item::factory()->create(['status' => Status::ACTIVE, 'price' => 9.90]);
        $attribute = ItemAttribute::factory()->create();
        Event::fake([ItemAvailabilityChanged::class]);

        $variation = app(ItemVariationService::class)->store($this->variationRequest([
            'name' => 'Double viande',
            'item_attribute_id' => $attribute->id,
            'price' => 2.50,
            'status' => Status::ACTIVE,
        ]), $item);

        app(ItemVariationService::class)->update($this->variationRequest([
            'name' => 'Triple viande',
            'item_attribute_id' => $attribute->id,
            'price' => 3.50,
            'status' => Status::ACTIVE,
        ]), $item, $variation);

        app(ItemVariationService::class)->destroy($item, $variation->refresh());

        Event::assertDispatchedTimes(ItemAvailabilityChanged::class, 3);
        Event::assertDispatched(ItemAvailabilityChanged::class, fn (ItemAvailabilityChanged $event): bool =>
            $event->itemId === (int) $item->id && $event->type === 'full'
        );
    }

    public function test_extra_mutations_emit_full_item_refresh(): void
    {
        $item = Item::factory()->create(['status' => Status::ACTIVE, 'price' => 9.90]);
        Event::fake([ItemAvailabilityChanged::class]);

        $extra = app(ItemExtraService::class)->store($this->extraRequest([
            'name' => 'Sauce maison',
            'price' => 1.00,
            'status' => Status::ACTIVE,
        ]), $item);

        app(ItemExtraService::class)->update($this->extraRequest([
            'name' => 'Sauce maison XL',
            'price' => 1.50,
            'status' => Status::ACTIVE,
        ]), $item, $extra);

        app(ItemExtraService::class)->destroy($item, $extra->refresh());

        Event::assertDispatchedTimes(ItemAvailabilityChanged::class, 3);
        Event::assertDispatched(ItemAvailabilityChanged::class, fn (ItemAvailabilityChanged $event): bool =>
            $event->itemId === (int) $item->id && $event->type === 'full'
        );
    }

    public function test_addon_mutations_emit_full_item_refresh(): void
    {
        $item = Item::factory()->create(['status' => Status::ACTIVE, 'price' => 9.90]);
        $addonItem = Item::factory()->create(['status' => Status::ACTIVE, 'price' => 4.90]);
        Event::fake([ItemAvailabilityChanged::class]);

        $addon = app(ItemAddonService::class)->store($this->addonRequest([
            'addon_item_id' => $addonItem->id,
            'addon_item_variation' => null,
        ]), $item);

        app(ItemAddonService::class)->destroy($item, $addon);

        Event::assertDispatchedTimes(ItemAvailabilityChanged::class, 2);
        Event::assertDispatched(ItemAvailabilityChanged::class, fn (ItemAvailabilityChanged $event): bool =>
            $event->itemId === (int) $item->id && $event->type === 'full'
        );
    }

    private function variationRequest(array $validated): ItemVariationRequest
    {
        $request = new class extends ItemVariationRequest {
            public array $testValidated = [];

            public function validated($key = null, $default = null)
            {
                if ($key !== null) {
                    return data_get($this->testValidated, $key, $default);
                }

                return $this->testValidated;
            }
        };
        $request->testValidated = $validated;

        return $request;
    }

    private function extraRequest(array $validated): ItemExtraRequest
    {
        $request = new class extends ItemExtraRequest {
            public array $testValidated = [];

            public function validated($key = null, $default = null)
            {
                if ($key !== null) {
                    return data_get($this->testValidated, $key, $default);
                }

                return $this->testValidated;
            }
        };
        $request->testValidated = $validated;

        return $request;
    }

    private function addonRequest(array $validated): ItemAddonRequest
    {
        $request = new class extends ItemAddonRequest {
            public array $testValidated = [];

            public function validated($key = null, $default = null)
            {
                if ($key !== null) {
                    return data_get($this->testValidated, $key, $default);
                }

                return $this->testValidated;
            }
        };
        $request->testValidated = $validated;

        return $request;
    }
}
