<?php

namespace Tests\Feature\KioskPhase1;

use App\Models\Branch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Kiosk Design V1 — Phase 1.2 : Branch.available_locales.
 */
class BranchAvailableLocalesTest extends TestCase
{
    use RefreshDatabase;

    public function test_available_locales_cast_to_array(): void
    {
        $branch = Branch::forceCreate([
            'name' => 'B', 'city' => 'Paris', 'state' => 'IDF',
            'zip_code' => '75000', 'address' => '1', 'status' => 1,
            'available_locales' => ['fr', 'en', 'ar'],
        ]);

        $fresh = Branch::find($branch->id);
        $this->assertSame(['fr', 'en', 'ar'], $fresh->available_locales);
    }

    public function test_active_locales_falls_back_to_default_when_null(): void
    {
        $branch = Branch::forceCreate([
            'name' => 'B', 'city' => 'Paris', 'state' => 'IDF',
            'zip_code' => '75000', 'address' => '1', 'status' => 1,
        ]);

        $locales = $branch->activeLocales();
        $this->assertIsArray($locales);
        $this->assertNotEmpty($locales, 'Toujours au moins 1 locale même sans config.');
    }

    public function test_active_locales_returns_branch_field_when_set(): void
    {
        $branch = Branch::forceCreate([
            'name' => 'B', 'city' => 'Paris', 'state' => 'IDF',
            'zip_code' => '75000', 'address' => '1', 'status' => 1,
            'available_locales' => ['fr'],
        ]);

        $this->assertSame(['fr'], $branch->activeLocales());
    }
}
