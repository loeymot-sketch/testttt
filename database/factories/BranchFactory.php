<?php

namespace Database\Factories;

use App\Enums\Status;
use App\Models\Branch;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Branch>
 */
class BranchFactory extends Factory
{
    protected $model = Branch::class;

    public function definition()
    {
        return [
            'name' => fake()->company() . ' Branch',
            'email' => fake()->unique()->companyEmail(),
            'phone' => fake()->phoneNumber(),
            'latitude' => fake()->latitude(),
            'longitude' => fake()->longitude(),
            'city' => fake()->city(),
            'state' => fake()->state(),
            'zip_code' => fake()->postcode(),
            'address' => fake()->streetAddress(),
            // [ultra-goal A3 heal 2026-05-13] keep production-aligned literal 1.
            // The codebase has TWO conventions for "active" branch filtering:
            //   • Listeners use `where('status', Status::ACTIVE)` (=5)
            //   • Commands/Services use `where('status', 1)` (literal)
            // Production DB stores status=1 from pre-enum-migration seeding.
            // To stay compatible with BOTH callers, the listeners were updated
            // to `whereIn('status', [Status::ACTIVE, 1])` (see PersistCatalog/
            // ItemAvailability/Coupon listeners + InvalidateMenuProjection).
            // Factory keeps `1` so commands using literal-1 filter still match.
            // Owner data migration TODO: UPDATE branches SET status=5 + align
            // all `where('status', 1)` callers to `Status::ACTIVE`.
            'status' => 1,
        ];
    }
}
