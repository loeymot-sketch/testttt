<?php

namespace Tests\Feature\Ingredients;

use App\Enums\Status;
use App\Events\IngredientAvailabilityChanged;
use App\Models\Item;
use App\Models\ItemAttribute;
use App\Models\ItemExtra;
use App\Services\Ingredients\IngredientAvailabilityService;
use App\Services\Ingredients\IngredientService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class IngredientAvailabilityChangedAfterCommitTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_dispatches_after_commit_only(): void
    {
        $attribute = ItemAttribute::factory()->create(['is_available' => true]);
        Event::fake([IngredientAvailabilityChanged::class]);

        DB::transaction(function () use ($attribute): void {
            app(IngredientAvailabilityService::class)->toggle(
                IngredientService::TYPE_ATTRIBUTE,
                (int) $attribute->id,
                false,
                'rupture'
            );
        });

        Event::assertDispatched(IngredientAvailabilityChanged::class);
    }

    public function test_it_does_not_fire_inside_uncommitted_transaction(): void
    {
        $attribute = ItemAttribute::factory()->create(['is_available' => true]);
        Event::fake([IngredientAvailabilityChanged::class]);

        DB::beginTransaction();
        try {
            app(IngredientAvailabilityService::class)->toggle(
                IngredientService::TYPE_ATTRIBUTE,
                (int) $attribute->id,
                false,
                'rupture'
            );
        } finally {
            DB::rollBack();
        }

        Event::assertNotDispatched(IngredientAvailabilityChanged::class);
    }

    public function test_it_carries_correct_payload(): void
    {
        $extra = $this->createExtra(['id' => 7, 'is_available' => true]);
        Event::fake([IngredientAvailabilityChanged::class]);

        app(IngredientAvailabilityService::class)->toggle(
            IngredientService::TYPE_EXTRA,
            (int) $extra->id,
            false,
            'rupture_manuelle'
        );

        Event::assertDispatched(IngredientAvailabilityChanged::class, function (IngredientAvailabilityChanged $event): bool {
            return $event->type === IngredientService::TYPE_EXTRA
                && $event->id === 7
                && $event->isAvailable === false
                && $event->reason === 'rupture_manuelle'
                && $event->branchId === null;
        });
    }

    public function test_it_returns_false_for_addon_type(): void
    {
        Event::fake([IngredientAvailabilityChanged::class]);

        $result = app(IngredientAvailabilityService::class)->toggle(
            IngredientService::TYPE_ADDON,
            123,
            false,
            'rupture'
        );

        $this->assertFalse($result);
        Event::assertNotDispatched(IngredientAvailabilityChanged::class);
    }

    private function createExtra(array $attributes = []): ItemExtra
    {
        return ItemExtra::unguarded(fn (): ItemExtra => ItemExtra::query()->create(array_merge([
            'item_id' => Item::factory()->create()->id,
            'name' => 'Sauce blanche',
            'price' => 0.50,
            'status' => Status::ACTIVE,
            'group_label' => 'sauces',
        ], $attributes)));
    }
}
