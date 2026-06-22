<?php

namespace Database\Seeders;

use App\Enums\ItemType;
use App\Enums\Status;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Tax;
use Illuminate\Database\Seeder;

/**
 * Idempotent rows for Playwright catalog studio (category sidebar + optional product).
 * Blocked in production.
 */
class E2EPlaywrightCatalogStudioSeeder extends Seeder
{
    public const CATEGORY_NAME = 'E2E_PLAYWRIGHT_STUDIO_CATEGORY';

    public const CATEGORY_SLUG = 'e2e-playwright-studio-category';

    public const ITEM_NAME = 'E2E_PLAYWRIGHT_STUDIO_ITEM';

    public const ITEM_SLUG = 'e2e-playwright-studio-item';

    public function run(): void
    {
        if (app()->environment('production')) {
            return;
        }

        $category = ItemCategory::query()->updateOrCreate(
            ['slug' => self::CATEGORY_SLUG],
            [
                'name' => self::CATEGORY_NAME,
                'status' => Status::ACTIVE,
                'sort' => 9998,
                'description' => '',
            ]
        );

        $taxId = Tax::query()->orderBy('id')->value('id');

        Item::query()->updateOrCreate(
            ['slug' => self::ITEM_SLUG],
            [
                'name' => self::ITEM_NAME,
                'item_category_id' => $category->id,
                'tax_id' => $taxId !== null ? (int) $taxId : null,
                'price' => 1.00,
                'description' => 'E2E catalog studio seed',
                'status' => Status::ACTIVE,
                'order' => 9998,
                'item_type' => ItemType::VEG,
            ]
        );
    }
}
