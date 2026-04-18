<?php

namespace Tests\Feature\KioskPhase1;

use App\Models\Allergen;
use Database\Seeders\AllergensSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Kiosk Design V1 — Phase 1.3 : seeder allergens Annexe II UE 1169/2011.
 */
class AllergensSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_inserts_14_rows(): void
    {
        $this->seed(AllergensSeeder::class);

        $this->assertSame(14, Allergen::count());
    }

    public function test_seeder_is_idempotent_on_code(): void
    {
        $this->seed(AllergensSeeder::class);
        $this->seed(AllergensSeeder::class);
        $this->seed(AllergensSeeder::class);

        $this->assertSame(14, Allergen::count());
    }

    public function test_all_14_eu_codes_present(): void
    {
        $this->seed(AllergensSeeder::class);

        $expected = [
            'gluten', 'crustaces', 'oeufs', 'poisson', 'arachides', 'soja',
            'lait', 'fruits_a_coque', 'celeri', 'moutarde', 'sesame',
            'sulfites', 'lupin', 'mollusques',
        ];

        foreach ($expected as $code) {
            $this->assertTrue(
                Allergen::where('code', $code)->exists(),
                "Allergen code '$code' (Annexe II UE 1169/2011) missing"
            );
        }
    }

    public function test_name_keys_are_i18n_ready(): void
    {
        $this->seed(AllergensSeeder::class);

        Allergen::all()->each(function (Allergen $a) {
            $this->assertStringStartsWith(
                'allergens.',
                $a->name_key,
                "Allergen '{$a->code}' must use i18n key format 'allergens.*'"
            );
        });
    }
}
