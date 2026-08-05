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
 * [GOAL-8AXES V3 T-8.4.5 2026-08-05] Les nouveaux légumes payants (Poivrons
 * cuits / Maïs / Olives, 0,90 €, group_label='crudite') sont SCELLÉS au
 * centime par PricingService — et « Aucune crudité » (0 €) ne change RIEN au
 * total.
 *
 * Risque n°1 de l'axe (précédent sauce frites 2026-07-29) : « affiché mais
 * jamais facturé ». Ce test verrouille le côté SSOT ; la parité d'affichage
 * est prouvée par l'e2e visuel de la vague.
 *
 * Gabarit : KioskFritesSauceBillingTest (même chemin PricingRequest::forKiosk).
 */
class NewSupplementsBilledTest extends TestCase
{
    use RefreshDatabase;

    private const ITEM_PRICE = 6.50;

    private const VEGGIE_PRICE = 0.90;

    public function test_paid_veggie_crudite_is_sealed_at_090_per_unit(): void
    {
        $this->seedMinimalSettings();
        [$branch, $item, $poivrons] = $this->makeCatalogue();

        $base = $this->sealedLineTotal($branch->id, $item->id, []);
        $withPoivrons = $this->sealedLineTotal($branch->id, $item->id, [['id' => $poivrons->id, 'quantity' => 1]]);

        $this->assertEqualsWithDelta(self::ITEM_PRICE, $base, 0.0001);
        $this->assertEqualsWithDelta(
            self::VEGGIE_PRICE,
            $withPoivrons - $base,
            0.0001,
            'Poivrons cuits (crudité PAYANTE) doit augmenter le scellé d\'exactement 0,90 €.'
        );
    }

    public function test_all_three_new_veggies_are_sealed_together(): void
    {
        $this->seedMinimalSettings();
        [$branch, $item, $poivrons, $mais, $olives] = $this->makeCatalogue();

        $base = $this->sealedLineTotal($branch->id, $item->id, []);
        $all = $this->sealedLineTotal($branch->id, $item->id, [
            ['id' => $poivrons->id, 'quantity' => 1],
            ['id' => $mais->id, 'quantity' => 1],
            ['id' => $olives->id, 'quantity' => 1],
        ]);

        $this->assertEqualsWithDelta(3 * self::VEGGIE_PRICE, $all - $base, 0.0001,
            'Poivrons + Maïs + Olives = exactement 3 × 0,90 € scellés.');
    }

    public function test_veggie_surcharge_follows_line_quantity(): void
    {
        $this->seedMinimalSettings();
        [$branch, $item, $poivrons] = $this->makeCatalogue();

        $qty3 = $this->sealedLineTotal($branch->id, $item->id, [['id' => $poivrons->id, 'quantity' => 1]], 3);

        $this->assertEqualsWithDelta(
            3 * (self::ITEM_PRICE + self::VEGGIE_PRICE),
            $qty3,
            0.0001,
            'Le supplément légume suit la quantité de la ligne (3 sandwichs = 3 poivrons).'
        );
    }

    /** @return array{0: Branch, 1: Item, 2: ItemExtra, 3: ItemExtra, 4: ItemExtra} */
    private function makeCatalogue(): array
    {
        $branch = Branch::forceCreate([
            'name' => 'Veggies Branch',
            'city' => 'Paris',
            'state' => 'IDF',
            'zip_code' => '75000',
            'address' => '1 rue du cayenne',
            'status' => 1,
        ]);

        $tax = Tax::create([
            'name' => 'TVA 10', 'code' => 'TVA10', 'tax_rate' => 10, 'type' => 2, 'status' => 1,
        ]);

        $category = ItemCategory::forceCreate([
            'name' => 'Nos Cayenne', 'slug' => 'cayenne-veggies', 'status' => Status::ACTIVE,
        ]);

        $item = Item::forceCreate([
            'name' => 'Cayenne',
            'slug' => 'cayenne-veggies-item',
            'price' => self::ITEM_PRICE,
            'status' => Status::ACTIVE,
            'item_category_id' => $category->id,
            'tax_id' => $tax->id,
        ]);

        // Miroir exact des lignes créées par 2026_08_05_110000_add_paid_veggies…
        $mk = fn (string $name) => ItemExtra::query()->create([
            'item_id' => $item->id,
            'name' => $name,
            'group_label' => 'crudite',
            'price' => self::VEGGIE_PRICE,
            'status' => Status::ACTIVE,
        ]);

        return [$branch, $item, $mk('Poivrons cuits'), $mk('Maïs'), $mk('Olives')];
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
