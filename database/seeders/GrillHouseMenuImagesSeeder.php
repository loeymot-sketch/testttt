<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use App\Models\ItemCategory;
use App\Models\Item;

class GrillHouseMenuImagesSeeder extends Seeder
{
    public function run()
    {
        // On va associer des images via Spatie Media Library
        // 1. Catégories
        $catImages = [
            'Nos Tacos' => 'https://i.ibb.co/3mbM11J/tacos.png',
            'Nos Sandwichs' => 'https://i.ibb.co/6y4tDBc/sandwich.png',
            'Nos Burgers' => 'https://i.ibb.co/zXjN2Sg/burger.png',
            'Nos Assiettes' => 'https://i.ibb.co/XZZ4kX7/assiette.png',
            'Nos Salades & Omelettes' => 'https://i.ibb.co/8YZZ67x/salade.png',
            'Nos Desserts' => 'https://i.ibb.co/2n9mPzY/dessert.png',
            'Nos Boissons' => 'https://i.ibb.co/xL7C5V8/coca.png',
        ];

        foreach ($catImages as $name => $url) {
            $cat = ItemCategory::where('name', $name)->first();
            if ($cat) {
                try {
                    $cat->clearMediaCollection('item-category');
                    $cat->addMediaFromUrl($url)->toMediaCollection('item-category');
                } catch (\Exception $e) { /* silent fail on network error */
                }
            }
        }

        // 2. Items
        $itemImages = [
            'Tacos M (1 Viande)' => 'https://i.ibb.co/3mbM11J/tacos.png',
            'Le Terminator (2 Viandes)' => 'https://i.ibb.co/6y4tDBc/sandwich.png',
            'Double Cheese' => 'https://i.ibb.co/zXjN2Sg/burger.png',
            'Assiette Mixte (3 Viandes)' => 'https://i.ibb.co/XZZ4kX7/assiette.png',
            'Coca-Cola 33cl' => 'https://i.ibb.co/xL7C5V8/coca.png',
            'Frites (Grande)' => 'https://i.ibb.co/fNX9LZy/frites.png',
            'Tiramisu Speculoos' => 'https://i.ibb.co/2n9mPzY/dessert.png',
        ];

        foreach ($itemImages as $name => $url) {
            $item = Item::where('name', $name)->first();
            if ($item) {
                try {
                    $item->clearMediaCollection('item');
                    $item->addMediaFromUrl($url)->toMediaCollection('item');
                } catch (\Exception $e) { /* silent fail on network */
                }
            }
        }
    }
}
