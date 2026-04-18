<?php

namespace Tests\Feature\KioskPhase1;

use App\Http\Resources\NormalItemResource;
use App\Models\Allergen;
use App\Models\Item;
use App\Models\ItemCategory;
use Database\Seeders\AllergensSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * Kiosk Phase 9.1.1 — NormalItemResource expose `is_available` + `allergens[]`.
 *
 * Motif. La borne doit pouvoir détecter mid-wizard qu'un item est devenu
 * indisponible (409 Conflict au POST /order sinon) et afficher les allergènes
 * dans le header wizard (safety FIC UE 1169/2011 + EAA 2025).
 *
 * Gate P9.1.1. Cette resource est consommée par `GET /api/frontend/items/{slug}`
 * (wizard fetch) : les 2 champs doivent exister et être du type attendu.
 */
class NormalItemResourceAllergensTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Crée un item lié à une catégorie sans dépendre du nom de relation Laravel (`for()`
     * essaie `itemCategory()` par convention → Item expose `category()`).
     */
    private function makeItem(array $attrs = []): Item
    {
        $category = ItemCategory::factory()->create();

        return Item::factory()->create(array_merge([
            'item_category_id' => $category->id,
        ], $attrs));
    }

    public function test_resource_includes_is_available_boolean(): void
    {
        $item = $this->makeItem(['is_available' => true]);

        $payload = (new NormalItemResource($item->fresh()))
            ->toArray(Request::create('/'));

        $this->assertArrayHasKey('is_available', $payload);
        $this->assertIsBool($payload['is_available']);
        $this->assertTrue($payload['is_available']);
    }

    public function test_is_available_defaults_to_true_when_null(): void
    {
        $item = $this->makeItem();

        // Simule un item legacy où le flag n'a jamais été set (column nullable).
        $item->is_available = null;

        $payload = (new NormalItemResource($item))
            ->toArray(Request::create('/'));

        $this->assertTrue(
            $payload['is_available'],
            'Legacy items (is_available null) doivent être traités comme disponibles par défaut.'
        );
    }

    public function test_is_available_false_when_flag_is_false(): void
    {
        $item = $this->makeItem(['is_available' => false]);

        $payload = (new NormalItemResource($item->fresh()))
            ->toArray(Request::create('/'));

        $this->assertFalse($payload['is_available']);
    }

    public function test_resource_includes_empty_allergens_array_when_none_attached(): void
    {
        $item = $this->makeItem();

        $payload = (new NormalItemResource($item->fresh()))
            ->toArray(Request::create('/'));

        $this->assertArrayHasKey('allergens', $payload);
        $this->assertCount(0, $payload['allergens']);
    }

    public function test_resource_projects_allergens_with_expected_shape(): void
    {
        $this->seed(AllergensSeeder::class);
        $item = $this->makeItem();

        $gluten = Allergen::where('code', 'gluten')->firstOrFail();
        $milk = Allergen::where('code', 'lait')->firstOrFail();

        $item->allergens()->attach($gluten->id, ['is_trace' => false]);
        $item->allergens()->attach($milk->id, ['is_trace' => true]);

        $payload = (new NormalItemResource($item->fresh()->load('allergens')))
            ->toArray(Request::create('/'));

        $this->assertCount(2, $payload['allergens']);

        foreach ($payload['allergens'] as $allergen) {
            $this->assertArrayHasKey('id', $allergen);
            $this->assertArrayHasKey('code', $allergen);
            $this->assertArrayHasKey('name_key', $allergen);
            $this->assertArrayHasKey('icon', $allergen);
            $this->assertArrayHasKey('is_trace', $allergen);
            $this->assertIsBool($allergen['is_trace']);
            $this->assertStringStartsWith(
                'allergens.',
                $allergen['name_key'],
                'name_key doit respecter le format i18n `allergens.*` pour être traduisible côté kiosk.'
            );
        }

        $codes = collect($payload['allergens'])->pluck('code')->all();
        $this->assertContains('gluten', $codes);
        $this->assertContains('lait', $codes);

        $milkPayload = collect($payload['allergens'])->firstWhere('code', 'lait');
        $this->assertTrue(
            $milkPayload['is_trace'],
            'Le pivot `is_trace` doit être propagé pour afficher "traces de lait" dans le kiosk.'
        );
    }

    public function test_resource_shape_is_stable_for_legacy_consumers(): void
    {
        $item = $this->makeItem();

        $payload = (new NormalItemResource($item->fresh()))
            ->toArray(Request::create('/'));

        // Garantie de non-régression : aucun champ historique ne doit disparaître.
        foreach (['id', 'name', 'price', 'item_type', 'status', 'description', 'variations', 'extras'] as $key) {
            $this->assertArrayHasKey(
                $key,
                $payload,
                "Champ legacy `$key` manquant — NormalItemResource doit rester rétrocompatible."
            );
        }
    }
}
