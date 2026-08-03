<?php

namespace Tests\Feature\Mobile;

use App\Enums\Status;
use App\Events\ItemExtraAvailabilityChanged;
use App\Events\ItemVariationAvailabilityChanged;
use App\Models\Branch;
use App\Models\Item;
use App\Models\ItemAttribute;
use App\Models\ItemCategory;
use App\Models\ItemExtra;
use App\Models\ItemVariation;
use App\Models\StockLevel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * [HEAL F3 2026-07-24] Depuis /m (stock mobile PIN-gated), l'owner doit pouvoir
 * couper un INGRÉDIENT — sauce/supplément (ItemExtra) ou variation (ItemVariation) —
 * pas seulement un produit entier (« plus d'Andalouse », « plus de merguez »),
 * exactement comme la caisse/cuisine le font déjà.
 *
 * SSOT strict : le catalog lit l'état via
 * {@see \App\Services\Menu\AvailabilityService::getUnavailableExtraIdsForBranch()} /
 * {@see \App\Services\Menu\AvailabilityService::getUnavailableVariationIdsForBranch()},
 * et le toggle délègue à {@see \App\Services\Menu\AvailabilityService::toggleExtra()} /
 * {@see \App\Services\Menu\AvailabilityService::toggleVariation()} (verrou + idempotence
 * + dispatch after-commit vers borne/POS/KDS). AUCUN chemin d'écriture parallèle.
 * Raison manuelle 'out_of_stock_manual' — identique au panneau admin/caisse
 * (StockLevel::MANUAL_UNAVAILABLE_REASONS). HORS NF525 (stock uniquement).
 */
class MobileStockIngredientToggleTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;
    private Item $item;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();
        config(['mobile_stock.pin' => '2580']);

        $this->branch = Branch::factory()->create();
        $category = ItemCategory::factory()->create(['status' => Status::ACTIVE]);
        $this->item = Item::factory()->create([
            'item_category_id' => $category->id,
            'status' => Status::ACTIVE,
            'is_available' => true,
        ]);
    }

    private function unlock(): void
    {
        $this->postJson('/m/api/pin', ['pin' => '2580'])->assertOk();
    }

    private function makeExtra(string $name, string $group = 'sauce_supp'): ItemExtra
    {
        return ItemExtra::query()->create([
            'item_id' => $this->item->id,
            'name' => $name,
            'price' => 0.50,
            'status' => Status::ACTIVE,
            'group_label' => $group,
            'is_available' => true,
        ]);
    }

    private function makeVariation(string $name): ItemVariation
    {
        $attribute = ItemAttribute::factory()->create(['is_available' => true]);

        return ItemVariation::query()->create([
            'item_id' => $this->item->id,
            'item_attribute_id' => $attribute->id,
            'name' => $name,
            'price' => 2.00,
            'status' => Status::ACTIVE,
        ]);
    }

    /** (1) Le catalog /m expose les extras ET variations toggables (groupés, ids[], état). */
    public function test_catalog_exposes_toggleable_extras_and_variations(): void
    {
        $this->makeExtra('Andalouse');
        $this->makeVariation('Grand format');
        $this->unlock();

        $res = $this->getJson('/m/api/catalog')
            ->assertOk()
            ->assertJsonStructure([
                'ingredients' => [['group', 'kind', 'items' => [['name', 'ids', 'is_available']]]],
            ]);

        $ingredients = collect($res->json('ingredients'));

        $this->assertTrue(
            $ingredients->contains(fn ($g): bool => $g['kind'] === 'extra'
                && collect($g['items'])->contains(fn ($it): bool => $it['name'] === 'Andalouse' && $it['is_available'] === true)),
            'Le catalog doit exposer la sauce Andalouse (extra) disponible.'
        );

        $this->assertTrue(
            $ingredients->contains(fn ($g): bool => $g['kind'] === 'variation'
                && collect($g['items'])->contains(fn ($it): bool => $it['name'] === 'Grand format')),
            'Le catalog doit exposer la variation Grand format.'
        );
    }

    /** (2) toggle-extra PIN-gaté marque un ItemExtra en rupture (stock_levels) + propage l'event. */
    public function test_toggle_extra_marks_stock_level_rupture_and_propagates(): void
    {
        Event::fake([ItemExtraAvailabilityChanged::class]);
        $extra = $this->makeExtra('Samouraï');
        $this->unlock();

        $this->postJson('/m/api/toggle-extra', [
            'kind' => 'extra',
            'ids' => [$extra->id],
            'is_available' => false,
        ])->assertOk()->assertJson([
            'ok' => true,
            'kind' => 'extra',
            'is_available' => false,
            'ids' => [$extra->id],
        ]);

        $this->assertDatabaseHas('stock_levels', [
            'branch_id' => $this->branch->id,
            'stockable_type' => ItemExtra::class,
            'stockable_id' => $extra->id,
            'manual_unavailable_reason' => 'out_of_stock_manual',
        ]);

        Event::assertDispatched(
            ItemExtraAvailabilityChanged::class,
            fn (ItemExtraAvailabilityChanged $e): bool => $e->extraId === (int) $extra->id
                && $e->branchId === (int) $this->branch->id
                && $e->isAvailable === false
        );
    }

    /** (3) « plus d'Andalouse PARTOUT » : le toggle cascade sur TOUS les ids d'un même nom. */
    public function test_toggle_extra_cascades_to_all_ids_of_same_name(): void
    {
        $a = $this->makeExtra('Andalouse');
        $b = $this->makeExtra('Andalouse'); // même sauce, autre ligne produit
        $this->unlock();

        // Le catalog dédoublonne par nom → une tuile « Andalouse » avec ids=[a,b].
        $res = $this->getJson('/m/api/catalog')->assertOk();
        $group = collect($res->json('ingredients'))->firstWhere('kind', 'extra');
        $tile = collect($group['items'])->firstWhere('name', 'Andalouse');
        $this->assertEqualsCanonicalizing([$a->id, $b->id], $tile['ids']);

        $this->postJson('/m/api/toggle-extra', [
            'kind' => 'extra',
            'ids' => $tile['ids'],
            'is_available' => false,
        ])->assertOk();

        foreach ([$a, $b] as $e) {
            $this->assertDatabaseHas('stock_levels', [
                'branch_id' => $this->branch->id,
                'stockable_type' => ItemExtra::class,
                'stockable_id' => $e->id,
                'manual_unavailable_reason' => 'out_of_stock_manual',
            ]);
        }
    }

    /** (4) toggle-extra kind=variation marque la variation en rupture + propage l'event dédié. */
    public function test_toggle_variation_marks_rupture_and_propagates(): void
    {
        Event::fake([ItemVariationAvailabilityChanged::class]);
        $variation = $this->makeVariation('Grand format');
        $this->unlock();

        $this->postJson('/m/api/toggle-extra', [
            'kind' => 'variation',
            'ids' => [$variation->id],
            'is_available' => false,
        ])->assertOk()->assertJson(['ok' => true, 'kind' => 'variation', 'is_available' => false]);

        $this->assertDatabaseHas('stock_levels', [
            'branch_id' => $this->branch->id,
            'stockable_type' => ItemVariation::class,
            'stockable_id' => $variation->id,
            'manual_unavailable_reason' => 'out_of_stock_manual',
        ]);

        Event::assertDispatched(ItemVariationAvailabilityChanged::class);
    }

    /** (5) Idempotent : re-toggler la même rupture = no-op (un seul row, aucun event dupliqué). */
    public function test_toggle_extra_is_idempotent(): void
    {
        $extra = $this->makeExtra('Harissa');
        $this->unlock();

        $this->postJson('/m/api/toggle-extra', ['kind' => 'extra', 'ids' => [$extra->id], 'is_available' => false])->assertOk();

        Event::fake([ItemExtraAvailabilityChanged::class]);
        $this->postJson('/m/api/toggle-extra', ['kind' => 'extra', 'ids' => [$extra->id], 'is_available' => false])->assertOk();
        Event::assertNotDispatched(ItemExtraAvailabilityChanged::class);

        $this->assertSame(1, StockLevel::query()
            ->where('stockable_type', ItemExtra::class)
            ->where('stockable_id', $extra->id)
            ->count());
    }

    /** (6) Un ingrédient rupturé remonte dans « À acheter » (shopping) et sa tuile passe indisponible. */
    public function test_ruptured_ingredient_appears_in_shopping(): void
    {
        $extra = $this->makeExtra('Biggy');
        $this->unlock();

        $this->postJson('/m/api/toggle-extra', ['kind' => 'extra', 'ids' => [$extra->id], 'is_available' => false])->assertOk();

        $res = $this->getJson('/m/api/catalog')->assertOk();

        $this->assertTrue(
            collect($res->json('shopping'))->contains(fn ($s): bool => ($s['name'] ?? '') === 'Biggy'),
            'La sauce rupturée doit apparaître dans « À acheter ».'
        );

        $group = collect($res->json('ingredients'))->firstWhere('kind', 'extra');
        $tile = collect($group['items'])->firstWhere('name', 'Biggy');
        $this->assertFalse($tile['is_available'], 'La tuile de la sauce rupturée doit être indisponible.');
    }

    /** (7) Remise en stock => la rupture est effacée (row supprimé, absent = disponible). */
    public function test_toggle_extra_back_to_available_clears_rupture(): void
    {
        $extra = $this->makeExtra('Ketchup');
        $this->unlock();

        $this->postJson('/m/api/toggle-extra', ['kind' => 'extra', 'ids' => [$extra->id], 'is_available' => false])->assertOk();
        $this->postJson('/m/api/toggle-extra', ['kind' => 'extra', 'ids' => [$extra->id], 'is_available' => true])->assertOk();

        $this->assertDatabaseMissing('stock_levels', [
            'branch_id' => $this->branch->id,
            'stockable_type' => ItemExtra::class,
            'stockable_id' => $extra->id,
            'manual_unavailable_reason' => 'out_of_stock_manual',
        ]);
    }

    /** (8) Sans session PIN => 401 (verrouillé, même middleware que /m/api/toggle). */
    public function test_toggle_extra_requires_pin_session(): void
    {
        $extra = $this->makeExtra('NoPin');

        $this->postJson('/m/api/toggle-extra', [
            'kind' => 'extra',
            'ids' => [$extra->id],
            'is_available' => false,
        ])->assertStatus(401);
    }

    /** (9) Le toggle produit ENTIER reste fonctionnel (non-régression du chemin existant). */
    public function test_whole_item_toggle_still_works(): void
    {
        $this->unlock();

        $this->postJson('/m/api/toggle', [
            'item_id' => $this->item->id,
            'is_available' => false,
        ])->assertOk()->assertJson(['ok' => true, 'unavailable_reason' => 'stock_rupture']);

        $this->assertDatabaseHas('item_branch_availability', [
            'item_id' => $this->item->id,
            'branch_id' => $this->branch->id,
            'is_available' => 0,
            'unavailable_reason' => 'stock_rupture',
        ]);
    }
}
