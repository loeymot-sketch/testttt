<?php

namespace Tests\Feature\Menu;

use App\Console\Commands\EnsureFritesSauceStepCommand;
use App\Console\Commands\EnsureViandeSupplementExtrasCommand;
use App\Enums\Status;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\ItemExtra;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * [OWNER 2026-07-28] Verrous des 3 plaintes wizard (test live) — data-only, 0 frozen :
 *  - P1 FRITES : chaque item cat 7 doit exposer une étape SAUCE multi (profil composer publié
 *    + 12 variations attr sauce + « Sauce supplémentaire » @0,50) pilotée à l'identique borne+caisse ;
 *  - P3 VIANDE : chaque item à attribut viande (« Viande 1/2 ») doit porter « Viande supplémentaire »
 *    @2,50 (sinon KioskStepViande.viandeSupplementsEnabled=false → supplément impossible, silencieux).
 */
class WizardSauceViandeStepsCommandTest extends TestCase
{
    use RefreshDatabase;

    private int $sauceAttrId;

    private function seedSauceAttrAndRef(): void
    {
        $this->sauceAttrId = (int) DB::table('item_attributes')->insertGetId([
            'name' => 'Sauce (1ère Gratuite)',
            'min_select' => 1, 'max_select' => 1, 'allow_repeat' => 0,
            'is_available' => 1, 'status' => Status::ACTIVE,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $burgers = ItemCategory::query()->firstOrCreate(
            ['slug' => 'burgers'],
            ['name' => 'Burgers', 'status' => Status::ACTIVE]
        );
        $ref = Item::factory()->create([
            'item_category_id' => $burgers->id, 'status' => Status::ACTIVE, 'slug' => 'ref-burger',
        ]);
        foreach ([
            'Mayonnaise', 'Ketchup', 'Blanche', 'Hannibal', 'Samouraï', 'Algérienne',
            'Andalouse', 'Curry', 'Barbecue', 'Harissa', 'Fromagère maison', 'Spicy maison',
        ] as $sauce) {
            DB::table('item_variations')->insert([
                'item_id' => $ref->id, 'item_attribute_id' => $this->sauceAttrId,
                'name' => $sauce, 'price' => 0, 'status' => Status::ACTIVE,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    private function makeFritesItem(string $slug = 'petite-frites'): Item
    {
        // La commande cible la catégorie id = EnsureFritesSauceStepCommand::FRITES_CATEGORY_ID (7).
        // Insert brut : Eloquent guard l'id (non-fillable) → firstOrCreate ne fixerait pas l'id 7.
        $catId = EnsureFritesSauceStepCommand::FRITES_CATEGORY_ID;
        if (! DB::table('item_categories')->where('id', $catId)->exists()) {
            DB::table('item_categories')->insert([
                'id' => $catId, 'name' => 'Frites', 'slug' => 'frites',
                'status' => Status::ACTIVE, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return Item::factory()->create([
            'item_category_id' => $catId,
            'status' => Status::ACTIVE, 'slug' => $slug,
        ]);
    }

    // ─────────────────────────────── P1 FRITES ───────────────────────────────

    public function test_frites_gets_sauce_data_and_snacking_template_WITHOUT_published_profile(): void
    {
        $this->seedSauceAttrAndRef();
        $frites = $this->makeFritesItem();

        $this->artisan('menu:ensure-frites-sauce-step')->assertExitCode(0);

        // 12 variations sauce (prix 0) copiées de la référence.
        $vars = DB::table('item_variations')
            ->where('item_id', $frites->id)->where('item_attribute_id', $this->sauceAttrId)
            ->where('status', Status::ACTIVE)->whereNull('deleted_at')->get();
        $this->assertCount(12, $vars, '12 sauces sur les frites');
        $this->assertEqualsWithDelta(0.0, (float) $vars->max('price'), 0.001, 'toutes les variations gratuites (1ère gratuite via ordre)');

        // AUCUN profil composer publié (l'approche à-profil faisait 422 sur la 2ᵉ sauce).
        $published = DB::table('item_wizard_profiles')
            ->where('item_id', $frites->id)->where('is_published', 1)->count();
        $this->assertSame(0, $published, 'AUCUN profil publié — sinon régression 422 (belongs-to-profile)');

        // La borne affiche l'étape sauce via le template catégorie 'snacking'.
        $tmpl = DB::table('item_categories')
            ->where('id', EnsureFritesSauceStepCommand::FRITES_CATEGORY_ID)->value('wizard_template');
        $this->assertSame('snacking', $tmpl, 'catégorie frites en template snacking (borne data-gate sauce)');

        // Véhicule de facturation de la sauce en plus (@0,50) — routé sans profil.
        $this->assertSame(
            1,
            ItemExtra::where('item_id', $frites->id)
                ->where('name', 'Sauce supplémentaire')->where('group_label', 'sauce')->count(),
            '« Sauce supplémentaire » @0,50 présent (facturation 2e sauce)'
        );
    }

    public function test_frites_command_idempotent(): void
    {
        $this->seedSauceAttrAndRef();
        $this->makeFritesItem();

        $this->artisan('menu:ensure-frites-sauce-step')->assertExitCode(0);
        $second = EnsureFritesSauceStepCommand::ensure(false);
        $this->assertSame(0, $second['variations'], 'aucune variation ré-ajoutée');
        $this->assertSame(0, $second['category'], 'template catégorie déjà à jour');
        $this->assertSame(0, $second['profiles_unpublished'], 'aucun profil à dépublier au 2e run');
    }

    public function test_frites_command_unpublishes_a_pre_existing_profile(): void
    {
        // Garde-fou : si un profil publié traîne (ancienne approche), la commande le DÉPUBLIE
        // pour restaurer le chemin sans-profil (sinon la 2ᵉ sauce re-ferait 422).
        $this->seedSauceAttrAndRef();
        $frites = $this->makeFritesItem();
        $pid = DB::table('item_wizard_profiles')->insertGetId([
            'item_id' => $frites->id, 'template' => 'custom', 'version' => 1,
            'is_published' => 1, 'published_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $r = EnsureFritesSauceStepCommand::ensure(false);
        $this->assertGreaterThanOrEqual(1, $r['profiles_unpublished'], 'profil pré-existant dépublié');
        $this->assertSame(0, (int) DB::table('item_wizard_profiles')->where('id', $pid)->value('is_published'));
    }

    // ─────────────────────────────── P3 VIANDE ───────────────────────────────

    public function test_viande_item_gets_supplement_extra_at_250(): void
    {
        $cat = ItemCategory::factory()->create(['status' => Status::ACTIVE]);
        $item = Item::factory()->create(['item_category_id' => $cat->id, 'status' => Status::ACTIVE]);

        $viandeAttrId = (int) DB::table('item_attributes')->insertGetId([
            'name' => 'Viande 1', 'min_select' => 1, 'max_select' => 1, 'allow_repeat' => 0,
            'is_available' => 1, 'status' => Status::ACTIVE, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('item_variations')->insert([
            'item_id' => $item->id, 'item_attribute_id' => $viandeAttrId,
            'name' => 'Poulet', 'price' => 0, 'status' => Status::ACTIVE,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $created = EnsureViandeSupplementExtrasCommand::ensure();
        $this->assertSame(1, $created, 'un item complété');

        $extra = ItemExtra::where('item_id', $item->id)
            ->where('name', 'Viande supplémentaire')->whereNull('deleted_at')->first();
        $this->assertNotNull($extra, '« Viande supplémentaire » créé');
        $this->assertEqualsWithDelta(2.50, (float) $extra->price, 0.001, '@2,50');
        $this->assertSame('supplement', $extra->group_label, 'group standard (non-bol)');
    }

    public function test_viande_command_skips_item_without_viande_attribute(): void
    {
        $cat = ItemCategory::factory()->create(['status' => Status::ACTIVE]);
        $item = Item::factory()->create(['item_category_id' => $cat->id, 'status' => Status::ACTIVE]);
        // attribut NON-viande → aucun extra ne doit être créé.
        $otherAttrId = (int) DB::table('item_attributes')->insertGetId([
            'name' => 'Cuisson', 'min_select' => 1, 'max_select' => 1, 'allow_repeat' => 0,
            'is_available' => 1, 'status' => Status::ACTIVE, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('item_variations')->insert([
            'item_id' => $item->id, 'item_attribute_id' => $otherAttrId,
            'name' => 'Bien cuit', 'price' => 0, 'status' => Status::ACTIVE,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $created = EnsureViandeSupplementExtrasCommand::ensure();
        $this->assertSame(0, $created, 'aucun item sans attribut viande complété');
        $this->assertSame(0, ItemExtra::where('item_id', $item->id)->where('name', 'Viande supplémentaire')->count());
    }

    public function test_viande_command_idempotent(): void
    {
        $cat = ItemCategory::factory()->create(['status' => Status::ACTIVE]);
        $item = Item::factory()->create(['item_category_id' => $cat->id, 'status' => Status::ACTIVE]);
        $viandeAttrId = (int) DB::table('item_attributes')->insertGetId([
            'name' => 'Viande 1', 'min_select' => 1, 'max_select' => 1, 'allow_repeat' => 0,
            'is_available' => 1, 'status' => Status::ACTIVE, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('item_variations')->insert([
            'item_id' => $item->id, 'item_attribute_id' => $viandeAttrId,
            'name' => 'Poulet', 'price' => 0, 'status' => Status::ACTIVE,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertSame(1, EnsureViandeSupplementExtrasCommand::ensure());
        $this->assertSame(0, EnsureViandeSupplementExtrasCommand::ensure(), 'second run = zéro changement');
        $this->assertSame(1, ItemExtra::where('item_id', $item->id)->where('name', 'Viande supplémentaire')->count(), 'un seul extra (pas de doublon)');
    }
}
