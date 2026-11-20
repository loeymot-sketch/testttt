<?php

namespace Tests\Feature\Pos;

use App\Enums\Status;
use App\Http\Resources\ItemAddonResource;
use App\Models\Branch;
use App\Models\Item;
use App\Models\ItemAddon;
use App\Models\ItemCategory;
use App\Models\ItemExtra;
use App\Models\Tax;
use App\Services\CouponService;
use App\Services\Pricing\PricingRequest;
use App\Services\Pricing\PricingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [GOAL CAISSE/CUISINE #6 2026-10-02] « Les sauces/suppléments des frites s'affichent payants
 * mais ne sont jamais facturés. »
 *
 * CAUSE (vérifiée) : PricingService facture correctement tout id reçu — il n'est PAS en cause.
 * Mais le wizard caisse (gelé) affiche « Grande Portion +1,00 », « Cheddar Fondu +1,00 » et
 * « 2ᵉ sauce frites +0,50 » d'une FORMULE, puis ne les envoie que comme TEXTE (`menu_extras`) :
 * la ligne addon part avec `item_extras: []`. Le total affiché dépassait donc le total scellé.
 *
 * Correctif (hors zones gelées) : l'API des addons expose les extras de l'article de formule
 * (ids), le panier les rattache à la ligne addon. Ce test verrouille les deux moitiés côté
 * serveur : (1) l'id est disponible, (2) il est scellé au centime sur la ligne addon.
 */
class FormuleFritesFacturationPosTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private Item $parent;

    private Item $formule;

    private ItemExtra $grande;

    private ItemExtra $cheddar;

    private ItemAddon $pivot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();

        $this->branch = Branch::forceCreate(['name' => 'Formule Branch', 'city' => 'Paris', 'state' => 'IDF', 'zip_code' => '75000', 'address' => '1 rue', 'status' => 1]);
        $tax = Tax::create(['name' => 'TVA 10', 'code' => 'TVA10', 'tax_rate' => 10, 'type' => 2, 'status' => 1]);
        $cat = ItemCategory::forceCreate(['name' => 'Tacos', 'slug' => 'tacos-formule-pos', 'status' => Status::ACTIVE]);
        $tech = ItemCategory::forceCreate(['name' => 'Technique', 'slug' => 'technique-formule-pos', 'status' => Status::ACTIVE]);

        $this->parent = Item::forceCreate(['name' => 'Tacos M', 'slug' => 'tacos-m-formule-pos', 'price' => 8.50, 'status' => Status::ACTIVE, 'item_category_id' => $cat->id, 'tax_id' => $tax->id]);
        $this->formule = Item::forceCreate(['name' => 'Menu (Frites + Boisson)', 'slug' => 'menu-formule-pos', 'price' => 2.50, 'status' => Status::ACTIVE, 'item_category_id' => $tech->id, 'tax_id' => $tax->id]);

        $this->grande = ItemExtra::create(['item_id' => $this->formule->id, 'name' => 'Grande Portion', 'price' => 1.00, 'status' => Status::ACTIVE]);
        $this->cheddar = ItemExtra::create(['item_id' => $this->formule->id, 'name' => 'Cheddar Fondu', 'price' => 1.00, 'status' => Status::ACTIVE]);

        $this->pivot = ItemAddon::create(['item_id' => $this->parent->id, 'addon_item_id' => $this->formule->id, 'role' => 'menu_component']);
    }

    private function formuleLineTotal(array $extras): float
    {
        $line = json_decode(json_encode([
            'item_id' => $this->formule->id, 'quantity' => 1, 'item_variations' => [], 'item_extras' => $extras, 'item_addons' => [],
        ]));

        return (float) (new PricingService())->calculateOrder(
            PricingRequest::forPos(0, $this->branch->id, [$line], 0, 0, 0.0, 0.0),
            app(CouponService::class)
        )->subtotal;
    }

    public function test_l_api_des_addons_expose_les_extras_de_la_formule_avec_leurs_ids(): void
    {
        $pivot = ItemAddon::with(['addonItem.extras'])->find($this->pivot->id);

        $data = (new ItemAddonResource($pivot))->toArray(request());

        $this->assertArrayHasKey('addon_item_extras', $data);
        $byName = collect($data['addon_item_extras'])->keyBy('name');
        $this->assertSame($this->grande->id, (int) $byName['Grande Portion']['id']);
        $this->assertSame($this->cheddar->id, (int) $byName['Cheddar Fondu']['id']);
        $this->assertEqualsWithDelta(1.00, (float) $byName['Grande Portion']['convert_price'], 0.0001);
    }

    public function test_le_backend_scelle_grande_portion_et_cheddar_fondu_sur_la_ligne_formule(): void
    {
        $base = $this->formuleLineTotal([]);
        $grande = $this->formuleLineTotal([['id' => $this->grande->id, 'quantity' => 1]]);
        $deux = $this->formuleLineTotal([['id' => $this->grande->id, 'quantity' => 1], ['id' => $this->cheddar->id, 'quantity' => 1]]);

        $this->assertEqualsWithDelta(2.50, $base, 0.0001, 'sans option : le prix nu de la formule');
        $this->assertEqualsWithDelta(1.00, $grande - $base, 0.0001, 'Grande Portion = +1,00 €');
        $this->assertEqualsWithDelta(2.00, $deux - $base, 0.0001, 'Grande Portion + Cheddar Fondu = +2,00 € (les deux options affichées en caisse)');
    }
}
