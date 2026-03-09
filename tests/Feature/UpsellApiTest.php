<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Tax;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithoutMiddleware;

class UpsellApiTest extends TestCase
{
    use RefreshDatabase, WithoutMiddleware;

    public function test_item_upsell_returns_data()
    {
        $tax = Tax::forceCreate(['name' => 'TVA', 'code' => 'TVA', 'tax_rate' => 20, 'type' => 5, 'status' => 1]);
        $category1 = ItemCategory::forceCreate(['name' => 'Burgers', 'slug' => 'burgers', 'status' => 1]);
        $category2 = ItemCategory::forceCreate(['name' => 'Drinks', 'slug' => 'drinks', 'status' => 1]);

        // L'item cible
        $targetItem = Item::forceCreate([
            'name' => 'Big Burger',
            'slug' => 'big-burger',
            'item_category_id' => $category1->id,
            'tax_id' => $tax->id,
            'price' => 10,
            'status' => 5
        ]);

        // Items pour upsell même catégorie
        for ($i = 0; $i < 2; $i++) {
            Item::forceCreate([
                'name' => 'Other Burger ' . $i,
                'slug' => 'other-burger-' . $i,
                'item_category_id' => $category1->id,
                'tax_id' => $tax->id,
                'price' => 8,
                'status' => 5
            ]);
        }

        // Complément
        Item::forceCreate([
            'name' => 'Coke',
            'slug' => 'coke',
            'item_category_id' => $category2->id,
            'tax_id' => $tax->id,
            'price' => 3,
            'status' => 5
        ]);

        $response = $this->getJson('/api/frontend/item/upsell/' . $targetItem->id);

        if ($response->status() !== 200) {
            dump('Upsell Failure Response:', $response->json());
        }

        $response->assertStatus(200);
        $this->assertCount(3, $response->json('data')); // Doit retourner 3 suggestions mixées
    }
}
