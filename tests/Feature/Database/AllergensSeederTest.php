<?php

namespace Tests\Feature\Database;

use App\Models\Allergen;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class AllergensSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_fr_codes_present(): void
    {
        Artisan::call('db:seed', [
            '--class' => 'Database\\Seeders\\AllergensSeeder',
        ]);

        $this->assertTrue(Allergen::where('code', 'crustaces')->exists());
        $this->assertTrue(Allergen::where('code', 'oeufs')->exists());
        $this->assertFalse(Allergen::where('code', 'crustaceans')->exists());
        $this->assertFalse(Allergen::where('code', 'eggs')->exists());
    }
}
