<?php

namespace Tests\Feature\Pricing;

use App\Enums\Status;
use App\Models\Branch;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\ItemExtra;
use App\Models\Tax;
use App\Services\CouponService;
use App\Services\Pricing\PricingRequest;
use App\Services\Pricing\PricingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [PLAINTE OWNER 2026-07-29] « les suppléments sont ajoutés mais le prix ne bouge pas »
 *
 * Racine côté BORNE : la 2ᵉ sauce FRITES du menu était affichée « +0,50 € » (étape wizard
 * ET récap) mais ni comptée dans le total local, ni poussée dans `item_extras` → la caisse
 * ne la facturait JAMAIS.
 *
 * Ce test verrouille le côté SSOT : dès que la borne pousse l'ItemExtra « Sauce
 * supplémentaire » du produit parent, `PricingService` (SSOT NF525) le SCELLE au centime,
 * proportionnellement à la quantité. C'est la contrepartie backend du spec front
 * `tests/js/kioskFritesSauceBilling.spec.js` : ensemble ils prouvent affiché == scellé.
 */
class KioskFritesSauceBillingTest extends TestCase
{
    use RefreshDatabase;

    private const ITEM_PRICE = 8.50;

    private const SAUCE_SUPPL_PRICE = 0.50;

    public function test_sauce_supplementaire_est_scellee_par_le_backend_au_centime(): void
    {
        $this->seedMinimalSettings();

        [$branch, $item, $extra] = $this->makeCatalogue();

        $base = $this->sealedLineTotal($branch->id, $item->id, []);
        $one = $this->sealedLineTotal($branch->id, $item->id, [['id' => $extra->id, 'quantity' => 1]]);
        $two = $this->sealedLineTotal($branch->id, $item->id, [['id' => $extra->id, 'quantity' => 2]]);

        // 1ʳᵉ sauce incluse : c'est le wizard qui ne pousse rien — le backend scelle le prix nu.
        $this->assertEqualsWithDelta(self::ITEM_PRICE, $base, 0.0001);

        // Chaque sauce EN PLUS = exactement le prix de l'ItemExtra, ni plus ni moins.
        $this->assertEqualsWithDelta(self::SAUCE_SUPPL_PRICE, $one - $base, 0.0001);
        $this->assertEqualsWithDelta(2 * self::SAUCE_SUPPL_PRICE, $two - $base, 0.0001);
    }

    public function test_le_surcout_suit_la_quantite_de_la_ligne(): void
    {
        $this->seedMinimalSettings();

        [$branch, $item, $extra] = $this->makeCatalogue();

        // qty 2 avec 1 sauce en plus par article : le backend facture le supplément 2×.
        $qty2 = $this->sealedLineTotal(
            $branch->id,
            $item->id,
            [['id' => $extra->id, 'quantity' => 1]],
            2
        );

        $this->assertEqualsWithDelta(
            2 * (self::ITEM_PRICE + self::SAUCE_SUPPL_PRICE),
            $qty2,
            0.0001
        );
    }

    /** @return array{0: Branch, 1: Item, 2: ItemExtra} */
    private function makeCatalogue(): array
    {
        $branch = Branch::forceCreate([
            'name' => 'Frites Sauce Branch',
            'city' => 'Paris',
            'state' => 'IDF',
            'zip_code' => '75000',
            'address' => '1 rue des frites',
            'status' => 1,
        ]);

        $tax = Tax::create([
            'name' => 'TVA 10',
            'code' => 'TVA10',
            'tax_rate' => 10,
            'type' => 2,
            'status' => 1,
        ]);

        $category = ItemCategory::forceCreate([
            'name' => 'Tacos',
            'slug' => 'tacos-frites-sauce',
            'status' => Status::ACTIVE,
        ]);

        $item = Item::forceCreate([
            'name' => 'Tacos M',
            'slug' => 'tacos-m-frites-sauce',
            'price' => self::ITEM_PRICE,
            'status' => Status::ACTIVE,
            'item_category_id' => $category->id,
            'tax_id' => $tax->id,
        ]);

        // L'extra que la borne pousse désormais pour la sauce frites en plus : il appartient
        // au produit PARENT (la prémisse « pas d'ItemExtra sur les frites » était fausse).
        $extra = ItemExtra::query()->create([
            'item_id' => $item->id,
            'name' => 'Sauce supplémentaire',
            'group_label' => 'sauce',
            'price' => self::SAUCE_SUPPL_PRICE,
            'status' => Status::ACTIVE,
        ]);

        return [$branch, $item, $extra];
    }

    private function sealedLineTotal(int $branchId, int $itemId, array $extras, int $qty = 1): float
    {
        $line = json_decode(json_encode([
            'item_id' => $itemId,
            'quantity' => $qty,
            'item_variations' => [],
            'item_extras' => $extras,
            'item_addons' => [],
        ]));

        $result = (new PricingService())->calculateOrder(
            PricingRequest::forKiosk(0, $branchId, [$line], 0, 0, 0.0),
            app(CouponService::class)
        );

        return (float) $result->subtotal;
    }
}
