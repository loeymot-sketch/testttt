<?php

namespace Tests\Feature;

use App\Enums\Ask;
use App\Enums\Status;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Tax;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase A — kiosk upsell respects item_categories.kiosk_upsell_include.
 */
class KioskUpsellCategoryTest extends TestCase
{
    use RefreshDatabase;

    private function seedTax(): Tax
    {
        $this->seedMinimalSettings();

        return Tax::create([
            'name'      => 'VAT-0',
            'code'      => 'VAT0',
            'tax_rate'  => 0,
            'type'      => 5,
            'status'    => Status::ACTIVE,
        ]);
    }

    public function test_kiosk_upsell_excludes_items_from_category_with_pool_disabled(): void
    {
        $tax = $this->seedTax();

        $catExcluded = ItemCategory::create([
            'name'                 => 'Drinks',
            'slug'                 => 'drinks-upsell-test',
            'status'               => Status::ACTIVE,
            'wizard_template'      => 'simple',
            'has_menu'             => false,
            'default_menu_kiosk'   => false,
            'sauce_included_menu'  => false,
            'kiosk_upsell_include' => false,
        ]);

        $catIncluded = ItemCategory::create([
            'name'                 => 'Desserts',
            'slug'                 => 'desserts-upsell-test',
            'status'               => Status::ACTIVE,
            'wizard_template'      => 'simple',
            'has_menu'             => false,
            'default_menu_kiosk'   => false,
            'sauce_included_menu'  => false,
            'kiosk_upsell_include' => true,
        ]);

        $itemHidden = Item::create([
            'name'             => 'Hidden Upsell Soda',
            'slug'             => 'hidden-soda',
            'item_category_id' => $catExcluded->id,
            'tax_id'           => $tax->id,
            'price'            => 2.50,
            'status'           => Status::ACTIVE,
            'is_upsell'        => Ask::YES,
            'is_featured'      => Ask::NO,
        ]);

        $itemShown = Item::create([
            'name'             => 'Visible Upsell Cake',
            'slug'             => 'visible-cake',
            'item_category_id' => $catIncluded->id,
            'tax_id'           => $tax->id,
            'price'            => 4.00,
            'status'           => Status::ACTIVE,
            'is_upsell'        => Ask::YES,
            'is_featured'      => Ask::NO,
        ]);

        $response = $this->getJson('/api/frontend/item/kiosk-upsell?limit=12');

        $response->assertStatus(200);
        $ids = collect($response->json('data'))->pluck('id')->map(fn ($id) => (int) $id)->all();

        $this->assertContains($itemShown->id, $ids);
        $this->assertNotContains($itemHidden->id, $ids);
    }
}
