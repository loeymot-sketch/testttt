<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\Item;
use App\Models\StockLevel;
use Illuminate\Database\Eloquent\Factories\Factory;

class StockLevelFactory extends Factory
{
    protected $model = StockLevel::class;

    public function definition(): array
    {
        return [
            'branch_id' => Branch::factory(),
            'stockable_type' => 'item',
            'stockable_id' => Item::factory(),
            'on_hand' => 10,
            'reserved' => 0,
            'threshold_low' => 2,
        ];
    }
}
