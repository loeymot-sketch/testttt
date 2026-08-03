<?php

namespace Database\Seeders;

use App\Enums\Status;
use App\Models\Item;
use App\Models\ItemAttribute;
use App\Models\ItemExtra;
use Illuminate\Database\Seeder;

/**
 * Idempotent rows for Playwright critical-flow (ingredient list with toggles).
 * Safe for local/staging — blocked in production.
 */
class E2EPlaywrightIngredientRowsSeeder extends Seeder
{
    public const ATTRIBUTE_NAME = 'E2E_PLAYWRIGHT_ATTRIBUTE_TOGGLE';

    public const EXTRA_NAME = 'E2E_PLAYWRIGHT_EXTRA_TOGGLE';

    public function run(): void
    {
        if (app()->environment('production')) {
            return;
        }

        ItemAttribute::query()->updateOrCreate(
            ['name' => self::ATTRIBUTE_NAME],
            [
                'status' => Status::ACTIVE,
                'min_select' => 0,
                'max_select' => 1,
                'allow_repeat' => false,
                'is_available' => true,
                'unavailable_reason' => null,
            ]
        );

        $item = Item::query()->where('status', Status::ACTIVE)->orderBy('id')->first();
        if (! $item) {
            $item = Item::factory()->create(['status' => Status::ACTIVE]);
        }

        ItemExtra::query()->updateOrCreate(
            [
                'item_id' => $item->id,
                'name' => self::EXTRA_NAME,
            ],
            [
                'price' => 1.00,
                'status' => Status::ACTIVE,
                'is_available' => true,
                'unavailable_reason' => null,
            ]
        );
    }
}
