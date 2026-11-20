<?php

namespace Tests\Feature\Menu;

use App\Enums\Status;
use App\Http\Resources\ItemResource;
use App\Http\Resources\NormalItemResource;
use App\Models\Branch;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Services\Kiosk\KioskMenuService;
use App\Support\Menu\IncludedSauceCount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * [OWNER 2026-11-11] « Deux sauces offertes » sur un sandwich ; partout ailleurs la 1ʳᵉ seule.
 *
 * Le nombre est publié en `sauce_included` par les TROIS projections (caisse, borne, site) à partir d'une
 * seule règle (config/pos_sauces.php `included`). Si l'une des trois divergeait, une surface facturerait
 * la 2ᵉ sauce d'un sandwich et pas l'autre — le même produit à deux prix selon l'écran.
 *
 * @group menu
 */
class SaucesOffertesDecisionProprietaireTest extends TestCase
{
    use RefreshDatabase;

    private const ATTENDU = [
        'Cayenne' => 2,
        'Terminator' => 2,
        'Sandwich 2 Viandes' => 2,
        'Tacos L' => 1,
        'Cheese Burger' => 1,
        'Petite Frites' => 1,
        'Galette Cayenne' => 1,
        'Tiramisu' => 1,
    ];

    private function catalogue(): Branch
    {
        $branch = Branch::firstOrCreate(['id' => 1], [
            'name' => 'Filiale de test', 'email' => 't@example.test', 'phone' => '+33600000000', 'city' => 'Lille',
            'state' => 'Hauts-de-France', 'zip_code' => '59000', 'address' => '1 rue de Test', 'status' => Status::ACTIVE,
        ]);

        $cat = fn (string $nom) => ItemCategory::factory()->create(['name' => $nom, 'status' => Status::ACTIVE])->id;
        $categories = [
            'Sandwichs' => $cat('Sandwichs'), 'Tacos' => $cat('Tacos'), 'Burgers' => $cat('Burgers'),
            'Frites' => $cat('Frites'), 'Galette' => $cat('Galette'), 'Desserts' => $cat('Desserts'),
        ];
        $plan = [
            'Cayenne' => 'Sandwichs', 'Terminator' => 'Sandwichs', 'Sandwich 2 Viandes' => 'Sandwichs',
            'Tacos L' => 'Tacos', 'Cheese Burger' => 'Burgers', 'Petite Frites' => 'Frites',
            'Galette Cayenne' => 'Galette', 'Tiramisu' => 'Desserts',
        ];
        foreach ($plan as $nom => $categorie) {
            Item::factory()->create(['name' => $nom, 'item_category_id' => $categories[$categorie], 'status' => Status::ACTIVE]);
        }

        return $branch;
    }

    public function test_la_regle_sandwich_deux_ailleurs_une(): void
    {
        $this->assertSame(2, IncludedSauceCount::resolve('Méga', 'Sandwichs'));
        $this->assertSame(2, IncludedSauceCount::resolve('Suprême', 'sandwichs'));
        $this->assertSame(1, IncludedSauceCount::resolve('Tacos M', 'Tacos'));
        $this->assertSame(1, IncludedSauceCount::resolve('Inconnu', null));
    }

    public function test_les_trois_surfaces_publient_la_meme_valeur(): void
    {
        $menu = app(KioskMenuService::class)->build($this->catalogue());
        $borne = collect($menu['items'])->pluck('sauce_included', 'name')->all();

        foreach (self::ATTENDU as $nom => $attendu) {
            $this->assertSame($attendu, $borne[$nom] ?? null, "borne : « $nom »");

            $item = Item::query()->where('name', $nom)->firstOrFail()->fresh();
            $site = (new NormalItemResource($item))->toArray(Request::create('/', 'GET', ['surface' => 'web']));
            $this->assertSame($attendu, $site['sauce_included'], "site : « $nom »");

            $caisse = (new ItemResource($item))->toArray(Request::create('/', 'GET', ['surface' => 'pos']));
            $this->assertSame($attendu, $caisse['sauce_included'], "caisse : « $nom »");
        }
    }

    public function test_vider_la_regle_revient_a_une_sauce_offerte_partout(): void
    {
        config(['pos_sauces.included.by_category' => []]);

        $this->assertSame(1, IncludedSauceCount::resolve('Cayenne', 'Sandwichs'));
    }
}
