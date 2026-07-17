<?php

namespace Tests\Feature\Menu;

use App\Enums\Status;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\ItemExtra;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * [OWNER 2026-07-17] Menus enfants borne+caisse :
 *  - « Menu Enfant Nuggets » doit demander le CHOIX DE SAUCE ;
 *  - « Menu Enfant Chicken Burger » doit demander les CRUDITÉS (Salade, Tomate,
 *    Oignon) PUIS les suppléments standard, comme les burgers adultes.
 *
 * Mécanisme (cartographie 2026-07-17) : cat 11 est template 'simple' → la borne
 * n'affiche jamais sauce/garnitures via l'heuristique ; le SEUL levier data-only
 * pilotant borne + caisse (flag composer-aware ON) = profil composer PUBLIÉ
 * niveau item + variations attr sauce / extras group crudite|supplement.
 */
class EnsureKidsMenuStepsCommandTest extends TestCase
{
    use RefreshDatabase;

    private int $sauceAttrId;

    private Item $refBurger;

    private Item $nuggets;

    private Item $kidsBurger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sauceAttrId = (int) DB::table('item_attributes')->insertGetId([
            'name' => 'Sauce (1ère Gratuite)',
            'min_select' => 1,
            'max_select' => 1,
            'allow_repeat' => 0,
            'is_available' => 1,
            'status' => Status::ACTIVE,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $burgers = ItemCategory::query()->firstOrCreate(
            ['slug' => 'burgers'],
            ['name' => 'Burgers', 'status' => Status::ACTIVE]
        );
        $kids = ItemCategory::query()->firstOrCreate(
            ['slug' => 'menu-enfant'],
            ['name' => 'Menu enfant', 'status' => Status::ACTIVE]
        );

        $this->refBurger = Item::factory()->create([
            'item_category_id' => $burgers->id,
            'status' => Status::ACTIVE,
            'slug' => 'cheese-burger',
        ]);

        foreach ([
            'Mayonnaise', 'Ketchup', 'Blanche', 'Hannibal', 'Samouraï', 'Algérienne',
            'Andalouse', 'Curry', 'Barbecue', 'Harissa', 'Fromagère maison', 'Spicy maison',
        ] as $sauce) {
            DB::table('item_variations')->insert([
                'item_id' => $this->refBurger->id,
                'item_attribute_id' => $this->sauceAttrId,
                'name' => $sauce,
                'price' => 0,
                'status' => Status::ACTIVE,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        foreach ([
            ['Cheddar', 0.90, 'supplement'],
            ['Œuf', 0.90, 'supplement'],
            ['Viande supplémentaire', 2.50, 'supplement'],
            ['Salade', 0, 'crudite'],
        ] as [$name, $price, $group]) {
            ItemExtra::create([
                'item_id' => $this->refBurger->id,
                'name' => $name,
                'price' => $price,
                'group_label' => $group,
                'status' => Status::ACTIVE,
                'is_available' => 1,
            ]);
        }

        $this->nuggets = Item::factory()->create([
            'item_category_id' => $kids->id,
            'status' => Status::ACTIVE,
            'slug' => 'menu-enfant-nuggets',
        ]);
        $this->kidsBurger = Item::factory()->create([
            'item_category_id' => $kids->id,
            'status' => Status::ACTIVE,
            'slug' => 'menu-enfant-burger',
        ]);
    }

    public function test_nuggets_gets_published_sauce_step_with_full_sauce_list(): void
    {
        $this->artisan('menu:ensure-kids-menu-steps')->assertExitCode(0);

        $variations = DB::table('item_variations')
            ->where('item_id', $this->nuggets->id)
            ->where('item_attribute_id', $this->sauceAttrId)
            ->where('status', Status::ACTIVE)
            ->whereNull('deleted_at')
            ->get();
        $this->assertCount(12, $variations, '12 sauces copiées du burger de référence');
        $this->assertEqualsWithDelta(0.0, (float) $variations->first()->price, 0.001, '1ère sauce gratuite');

        $profile = DB::table('item_wizard_profiles')->where('item_id', $this->nuggets->id)->first();
        $this->assertNotNull($profile, 'profil composer niveau item créé');
        $this->assertSame(1, (int) $profile->is_published, 'profil PUBLIÉ (sinon la borne l\'ignore)');

        $steps = DB::table('item_wizard_steps')
            ->where('profile_id', $profile->id)->where('is_active', 1)
            ->orderBy('position')->get();
        $this->assertSame(['sauce'], $steps->pluck('step_key')->all(), 'une seule étape : sauce');
        $this->assertSame('item_attribute', $steps[0]->source_type);
        $this->assertSame($this->sauceAttrId, (int) $steps[0]->source_item_attribute_id);
        $this->assertSame(1, (int) $steps[0]->min_select, 'sauce obligatoire');
        $this->assertSame(1, (int) $steps[0]->max_select);

        $this->assertSame(
            1,
            ItemExtra::where('item_id', $this->nuggets->id)
                ->where('name', 'Sauce supplémentaire')->where('group_label', 'sauce')->count(),
            'véhicule de facturation 2e sauce présent (@0,50)'
        );
    }

    public function test_kids_burger_gets_crudites_then_standard_supplements(): void
    {
        $this->artisan('menu:ensure-kids-menu-steps')->assertExitCode(0);

        $crudites = ItemExtra::where('item_id', $this->kidsBurger->id)
            ->where('group_label', 'crudite')->orderBy('id')->get();
        $this->assertSame(['Salade', 'Tomate', 'Oignon'], $crudites->pluck('name')->all(), 'crudités owner');
        $this->assertEqualsWithDelta(0.0, (float) $crudites->max('price'), 0.001, 'crudités gratuites');

        $supps = ItemExtra::where('item_id', $this->kidsBurger->id)
            ->where('group_label', 'supplement')->get();
        $this->assertContains('Cheddar', $supps->pluck('name')->all(), 'liste standard copiée du burger réf');
        $this->assertContains('Œuf', $supps->pluck('name')->all());
        $this->assertNotContains(
            'Viande supplémentaire',
            $supps->pluck('name')->all(),
            'pas de « Viande supplémentaire » sur un menu enfant (liste facile = suppléments ≤1 €)'
        );
        $this->assertEqualsWithDelta(0.90, (float) $supps->firstWhere('name', 'Cheddar')->price, 0.001);

        $profile = DB::table('item_wizard_profiles')->where('item_id', $this->kidsBurger->id)->first();
        $this->assertNotNull($profile);
        $this->assertSame(1, (int) $profile->is_published);

        $steps = DB::table('item_wizard_steps')
            ->where('profile_id', $profile->id)->where('is_active', 1)
            ->orderBy('position')->get();
        $this->assertSame(
            ['garnitures', 'supplements'],
            $steps->pluck('step_key')->all(),
            'crudités PUIS suppléments (ordre owner)'
        );
        $this->assertSame('extra_group', $steps[0]->source_type);
        $this->assertSame('crudite', $steps[0]->source_ref);
        $this->assertSame('supplement', $steps[1]->source_ref);
        $this->assertSame(0, (int) $steps[0]->min_select, 'crudités facultatives');
    }

    public function test_idempotent_second_run_changes_nothing(): void
    {
        $this->artisan('menu:ensure-kids-menu-steps')->assertExitCode(0);

        $snapshot = fn () => [
            DB::table('item_variations')->orderBy('id')->get()->toArray(),
            DB::table('item_extras')->orderBy('id')->get(['id', 'item_id', 'name', 'price', 'group_label', 'status', 'deleted_at'])->toArray(),
            DB::table('item_wizard_profiles')->orderBy('id')->get(['id', 'item_id', 'is_published', 'version'])->toArray(),
            DB::table('item_wizard_steps')->orderBy('id')->get(['id', 'profile_id', 'step_key', 'position', 'is_active'])->toArray(),
        ];

        $first = $snapshot();
        $this->artisan('menu:ensure-kids-menu-steps')->assertExitCode(0);
        $this->assertEquals($first, $snapshot(), 'second run = zéro changement (idempotent)');
    }
}
