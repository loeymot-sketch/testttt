<?php

namespace Tests\Unit\Services;

use App\Models\Allergen;
use App\Models\Item;
use App\Services\AllergenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AllergenServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_pivot_sync_with_legacy_json(): void
    {
        $gluten = Allergen::query()->create([
            'code' => 'gluten',
            'name_key' => 'allergens.gluten',
            'icon' => 'g',
            'sort' => 1,
        ]);
        $oeufs = Allergen::query()->create([
            'code' => 'oeufs',
            'name_key' => 'allergens.oeufs',
            'icon' => 'o',
            'sort' => 2,
        ]);

        $item = Item::factory()->create([
            'allergen_flags' => ['gluten', 'oeufs'],
        ]);

        app(AllergenService::class)->projectFlags($item);

        $this->assertEqualsCanonicalizing(
            [$gluten->id, $oeufs->id],
            $item->allergens()->pluck('allergens.id')->all()
        );
    }
}
