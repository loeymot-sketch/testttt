<?php

namespace Tests\Feature\Composer;

use App\Models\Item;
use App\Models\User;
use Database\Seeders\ComposerPermissionsMinimalSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * [CV1-WIZARD-COMPOSABLE-001 T-WC-SOURCE-PICKER-01]
 * Sentinels for GET /api/admin/composer/items/{item}/available-sources:
 * always returns the {item_attribute, extra_group, addon} shape (empty arrays
 * are valid) and is gated by `catalog.compose`.
 */
class ComposerAvailableSourcesTest extends TestCase
{
    use RefreshDatabase;

    private Item $item;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('catalog_v15.features.wizard_per_item_demo.enabled', true);

        $this->seedMinimalSettings();
        Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'sanctum']);
        $this->seed(ComposerPermissionsMinimalSeeder::class);

        $this->item = Item::factory()->create();
        $this->admin = User::factory()->create();
        $this->admin->assignRole('Admin');
    }

    public function test_returns_attribute_extra_addon_shape_for_bare_item(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/admin/composer/items/{$this->item->id}/available-sources");

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.item_id', $this->item->id)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'item_id',
                    'item_attribute',
                    'extra_group',
                    'addon',
                ],
            ]);

        $this->assertSame([], $response->json('data.item_attribute'));
        $this->assertSame([], $response->json('data.extra_group'));
        $this->assertSame([], $response->json('data.addon'));
    }

    public function test_requires_authentication(): void
    {
        $response = $this->getJson("/api/admin/composer/items/{$this->item->id}/available-sources");

        $this->assertContains($response->status(), [401, 403, 419]);
    }

    /**
     * [GOAL POLISH T-P1.1 2026-06-10 — R2-NEW-01] Pour un wizard CATÉGORIE,
     * les sources doivent être l'UNION de TOUS les items actifs de la
     * catégorie (dérivées du seul premier item, les attributs absents de
     * celui-ci — Taille, Viande 2… — n'apparaissaient jamais dans le picker).
     */
    public function test_category_sources_aggregate_all_items(): void
    {
        $category = \App\Models\ItemCategory::factory()->create(['status' => 5]);

        $itemA = Item::factory()->create(['item_category_id' => $category->id, 'status' => 5]);
        $itemB = Item::factory()->create(['item_category_id' => $category->id, 'status' => 5]);

        $taille = \App\Models\ItemAttribute::query()->create(['name' => 'Taille']);
        $viande = \App\Models\ItemAttribute::query()->create(['name' => 'Viande 2']);

        // Taille n'existe QUE sur l'item B (pas le représentatif A).
        \App\Models\ItemVariation::query()->create([
            'item_id' => $itemA->id, 'item_attribute_id' => $viande->id,
            'name' => 'Poulet', 'price' => 0, 'status' => 5,
        ]);
        \App\Models\ItemVariation::query()->create([
            'item_id' => $itemB->id, 'item_attribute_id' => $taille->id,
            'name' => 'Grande', 'price' => 1.5, 'status' => 5,
        ]);
        // Groupe d'extras présent uniquement sur B.
        \App\Models\ItemExtra::query()->create([
            'item_id' => $itemB->id, 'name' => 'Cheddar', 'price' => 1.0,
            'group_label' => 'supplement', 'status' => 5,
        ]);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/admin/composer/categories/{$category->id}/available-sources");

        $response->assertOk();

        $attrNames = collect($response->json('data.item_attribute'))->pluck('name');
        $this->assertTrue($attrNames->contains('Taille'), 'attribute from 2nd item missing');
        $this->assertTrue($attrNames->contains('Viande 2'));

        $extraIds = collect($response->json('data.extra_group'))->pluck('id');
        $this->assertTrue($extraIds->contains('supplement'), 'extra group from 2nd item missing');
    }

    /**
     * [GOAL CMS heal P1-4 2026-06-10] Echo prix read-only par option dans le
     * builder : l'endpoint ADMIN available-sources émet `choices` (id, name,
     * price) par source. NF525-safe : ce n'est PAS la projection kiosk (qui
     * reste price-free) — le prix vient du construct catalogue, là où il vit.
     */
    public function test_sources_carry_choices_with_prices(): void
    {
        $attribute = \App\Models\ItemAttribute::query()->create(['name' => 'Taille']);
        \App\Models\ItemVariation::query()->create([
            'item_id' => $this->item->id,
            'item_attribute_id' => $attribute->id,
            'name' => 'Grande',
            'price' => 1.5,
            'status' => 5,
        ]);
        \App\Models\ItemExtra::query()->create([
            'item_id' => $this->item->id,
            'name' => 'Cheddar',
            'price' => 1.0,
            'group_label' => 'supplement',
            'status' => 5,
        ]);
        $addonItem = Item::factory()->create(['price' => 2.5]);
        \App\Models\ItemAddon::query()->create([
            'item_id' => $this->item->id,
            'addon_item_id' => $addonItem->id,
            'addon_item_variation' => [],
            'role' => 'drink',
        ]);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/admin/composer/items/{$this->item->id}/available-sources");

        $response->assertOk();

        $attr = collect($response->json('data.item_attribute'))->firstWhere('name', 'Taille');
        $this->assertNotNull($attr);
        $this->assertSame('Grande', $attr['choices'][0]['name']);
        $this->assertEqualsWithDelta(1.5, (float) $attr['choices'][0]['price'], 0.001);

        $extraGroup = collect($response->json('data.extra_group'))->firstWhere('id', 'supplement');
        $this->assertNotNull($extraGroup);
        $this->assertSame('Cheddar', $extraGroup['choices'][0]['name']);
        $this->assertEqualsWithDelta(1.0, (float) $extraGroup['choices'][0]['price'], 0.001);

        $addon = collect($response->json('data.addon'))->first();
        $this->assertEqualsWithDelta(2.5, (float) $addon['price'], 0.001);
    }
}
