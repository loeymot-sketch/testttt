<?php

namespace Database\Factories;

use App\Enums\Status;
use App\Models\WizardPage;
use App\Models\WizardPageChoice;
use Illuminate\Database\Eloquent\Factories\Factory;

class WizardPageChoiceFactory extends Factory
{
    protected $model = WizardPageChoice::class;

    public function definition(): array
    {
        return [
            'wizard_page_id' => WizardPage::factory(),
            'name' => ucfirst(fake()->unique()->word()),
            'price' => 0,
            'addon_item_id' => null,
            'sort' => 0,
            'status' => Status::ACTIVE,
            'visible_on' => null,
        ];
    }
}
