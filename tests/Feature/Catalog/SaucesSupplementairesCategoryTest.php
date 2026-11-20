<?php

namespace Tests\Feature\Catalog;

use App\Enums\Status;
use App\Models\Branch;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\ItemExtra;
use App\Models\Tax;
use App\Models\User;
use App\Services\CouponService;
use App\Services\Kiosk\KioskMenuService;
use App\Services\Pricing\PricingRequest;
use App\Services\Pricing\PricingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * [GOAL CAISSE/CUISINE #1 2026-10-02] Catégorie « Sauces supplémentaires », HORS MENU, vendable
 * seule ou en plus d'un produit. Data uniquement : aucun produit existant n'est modifié, aucune
 * zone gelée n'est touchée, le prix passe par le chemin ordinaire (PricingService).
 */
class SaucesSupplementairesCategoryTest extends TestCase
{
    use RefreshDatabase;

    private Item $cayenne;

    private Tax $tax;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
        $this->branch = Branch::factory()->create(['status' => Status::ACTIVE]);
        $this->tax = Tax::factory()->create(['tax_rate' => 10, 'status' => Status::ACTIVE]);
        $cat = ItemCategory::factory()->create(['name' => 'Sandwichs', 'status' => Status::ACTIVE]);
        $this->cayenne = Item::factory()->create(['item_category_id' => $cat->id, 'tax_id' => $this->tax->id, 'name' => 'Cayenne', 'price' => 7.40, 'status' => Status::ACTIVE]);
        ItemExtra::create(['item_id' => $this->cayenne->id, 'name' => 'Sauce supplémentaire', 'price' => 0.50, 'group_label' => 'sauce', 'status' => Status::ACTIVE]);
    }

    private function migrate(): void
    {
        (require base_path('database/migrations/2026_10_02_090000_add_sauces_supplementaires_category.php'))->up();
    }

    private function category(): ItemCategory
    {
        return ItemCategory::where('slug', 'sauces-supplementaires')->firstOrFail();
    }

    private function canonicalNames(): array
    {
        return collect(config('pos_sauces.catalog'))
            ->reject(fn ($s) => $s['key'] === 'sans_sauce')
            ->map(fn ($s) => 'Sauce '.$s['name'])
            ->values()->all();
    }

    public function test_cree_la_categorie_hors_menu_et_les_sauces_canoniques(): void
    {
        $this->migrate();

        $cat = $this->category();
        $this->assertSame('Sauces supplémentaires', $cat->name);
        $this->assertSame(['pos'], $cat->channels, 'hors menu : caisse seulement');

        $items = Item::where('item_category_id', $cat->id)->get();
        $this->assertEqualsCanonicalizing($this->canonicalNames(), $items->pluck('name')->all(), 'noms = catalogue canonique, rien d\'inventé');
        $this->assertNotContains('Sauce Sans sauce', $items->pluck('name')->all());
        foreach ($items as $it) {
            $this->assertSame(['pos'], $it->channels);
            $this->assertEqualsWithDelta(0.50, (float) $it->price, 0.0001);
            $this->assertSame($this->tax->id, (int) $it->tax_id, 'TVA des produits à sauces');
            $this->assertSame(Status::ACTIVE, (int) $it->status);
        }
    }

    public function test_ne_touche_a_aucun_produit_existant(): void
    {
        $avant = DB::table('items')->orderBy('id')->get()->toJson();
        $extrasAvant = DB::table('item_extras')->orderBy('id')->get()->toJson();
        $catsAvant = DB::table('item_categories')->orderBy('id')->get()->toJson();

        $this->migrate();

        $this->assertSame($avant, DB::table('items')->where('item_category_id', '!=', $this->category()->id)->orderBy('id')->get()->toJson(), 'aucun autre produit modifié');
        $this->assertSame($extrasAvant, DB::table('item_extras')->orderBy('id')->get()->toJson());
        $this->assertSame($catsAvant, DB::table('item_categories')->where('id', '!=', $this->category()->id)->orderBy('id')->get()->toJson());
    }

    public function test_idempotente_et_respecte_un_prix_modifie_dans_l_admin(): void
    {
        $this->migrate();
        $ketchup = Item::where('slug', 'sauce-ketchup')->firstOrFail();
        $ketchup->update(['price' => 0.70]);

        $this->migrate();

        $this->assertSame(count($this->canonicalNames()), Item::where('item_category_id', $this->category()->id)->count(), 'pas de doublon');
        $this->assertSame(1, ItemCategory::where('slug', 'sauces-supplementaires')->count());
        $this->assertEqualsWithDelta(0.70, (float) $ketchup->fresh()->price, 0.0001, 'le prix du propriétaire n\'est jamais écrasé');
    }

    public function test_restaure_une_sauce_supprimee_sans_doublon(): void
    {
        $this->migrate();
        Item::where('slug', 'sauce-curry')->firstOrFail()->delete();

        $this->migrate();

        $this->assertNotNull(Item::where('slug', 'sauce-curry')->first(), 'restaurée');
        $this->assertSame(1, Item::withTrashed()->where('slug', 'sauce-curry')->count());
    }

    public function test_visible_en_caisse_et_absente_de_la_borne(): void
    {
        $this->migrate();

        $kiosk = app(KioskMenuService::class)->build($this->branch);
        $json = json_encode($kiosk, JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('Sauces supplémentaires', $json, 'hors menu : absente de la borne');
        $this->assertStringNotContainsString('Sauce Ketchup', $json);

        $admin = User::factory()->create(['branch_id' => 0]);
        $admin->assignRole('Admin');
        $res = $this->actingAs($admin, 'sanctum')->withHeader('x-api-key', config('app.api_key'))->getJson('/api/admin/pos-category');
        $res->assertOk();
        $this->assertContains('Sauces supplémentaires', collect($res->json('data'))->pluck('name')->all(), 'visible dans la caisse');
    }

    private function sealed(array $lines): float
    {
        $decoded = json_decode(json_encode($lines));

        return (float) (new PricingService())->calculateOrder(
            PricingRequest::forPos(0, $this->branch->id, $decoded, 0, 0, 0.0, 0.0),
            app(CouponService::class)
        )->subtotal;
    }

    public function test_vendable_seule_et_en_plus_d_un_produit_au_prix_backend(): void
    {
        $this->migrate();
        $ketchup = Item::where('slug', 'sauce-ketchup')->firstOrFail();
        $mayo = Item::where('slug', 'sauce-mayonnaise')->firstOrFail();
        $line = fn (Item $i, int $q) => ['item_id' => $i->id, 'quantity' => $q, 'item_variations' => [], 'item_extras' => [], 'item_addons' => []];

        // Seule : 5 sauces = 5 × 0,50.
        $this->assertEqualsWithDelta(2.50, $this->sealed([$line($ketchup, 5)]), 0.0001);
        // En plus d'un produit : Cayenne 7,40 + 2 sauces différentes dans le même panier.
        $this->assertEqualsWithDelta(7.40 + 0.50 + 0.50, $this->sealed([$line($this->cayenne, 1), $line($ketchup, 1), $line($mayo, 1)]), 0.0001);
    }
}
