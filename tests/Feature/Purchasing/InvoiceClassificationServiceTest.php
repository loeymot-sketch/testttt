<?php

namespace Tests\Feature\Purchasing;

use App\Enums\Status;
use App\Models\Branch;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\PurchaseLine;
use App\Models\RawMaterial;
use App\Services\Purchasing\InvoiceClassificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * [ARCH_STOCK_INTELLIGENT_BOM_2026-07-23 / P3b] Classification des lignes lues.
 *
 * Prouve les 3 cibles + le repli inconnu, par fuzzy-match déterministe :
 *  - Poulet → matière (raw_material, target_id) ;
 *  - Coca (nom partiel « Coca 33cl ») → boisson (stock_item, target_id) ;
 *  - Sac papier → charge (mots-clés, target null) ;
 *  - libellé inconnu → repli charge NON confirmé (matched=false, score 0).
 * Aucune écriture stock (le service ne fait que PROPOSER).
 *
 * NF525 : domaine ADDITIF — aucune assertion fiscale.
 */
class InvoiceClassificationServiceTest extends TestCase
{
    use RefreshDatabase;

    private ItemCategory $boissons;

    protected function setUp(): void
    {
        parent::setUp();

        if (! Branch::query()->whereKey(1)->exists()) {
            Branch::factory()->create(['id' => 1]);
        }

        $this->boissons = ItemCategory::create([
            'name' => 'Boissons',
            'slug' => 'boissons-'.Str::random(6),
            'status' => Status::ACTIVE,
        ]);
    }

    private function service(): InvoiceClassificationService
    {
        return app(InvoiceClassificationService::class);
    }

    private function material(string $name): RawMaterial
    {
        return RawMaterial::create([
            'branch_id' => 1, 'name' => $name, 'unit' => 'g', 'is_active' => true,
        ]);
    }

    private function drink(string $name): Item
    {
        return Item::forceCreate([
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::random(6),
            'item_category_id' => $this->boissons->id,
            'item_type' => 1,
            'price' => 2,
            'status' => Status::ACTIVE,
        ]);
    }

    /** @param string $label */
    private function line(string $label): array
    {
        return ['raw_label' => $label, 'qty' => 1, 'unit' => 'piece', 'unit_price' => 1.0, 'tva_rate' => 20];
    }

    public function test_matches_raw_material_by_fuzzy_name(): void
    {
        $poulet = $this->material('Poulet');

        $out = $this->service()->propose([$this->line('Poulet frais 3kg')], 1);

        $this->assertSame(PurchaseLine::TARGET_RAW_MATERIAL, $out[0]['target_type']);
        $this->assertSame($poulet->id, $out[0]['target_id']);
        $this->assertTrue($out[0]['matched']);
        $this->assertGreaterThanOrEqual(0.5, $out[0]['score']);
    }

    public function test_matches_boisson_item_by_partial_name(): void
    {
        $this->material('Poulet'); // présent mais ne doit PAS gagner
        $coca = $this->drink('Coca 33cl');

        $out = $this->service()->propose([$this->line('Coca cola 24 canettes')], 1);

        $this->assertSame(PurchaseLine::TARGET_STOCK_ITEM, $out[0]['target_type']);
        $this->assertSame($coca->id, $out[0]['target_id']);
        $this->assertTrue($out[0]['matched']);
    }

    public function test_charge_by_keyword_when_no_stock_match(): void
    {
        $this->material('Poulet');
        $this->drink('Coca 33cl');

        $out = $this->service()->propose([$this->line('Sac papier kraft 500')], 1);

        $this->assertSame(PurchaseLine::TARGET_CHARGE, $out[0]['target_type']);
        $this->assertNull($out[0]['target_id']);
        $this->assertTrue($out[0]['matched'], 'Mot-clé charge reconnu → matched.');
    }

    public function test_unknown_label_falls_back_to_unconfirmed_charge(): void
    {
        $this->material('Poulet');

        $out = $this->service()->propose([$this->line('Reference obscure ZX9')], 1);

        $this->assertSame(PurchaseLine::TARGET_CHARGE, $out[0]['target_type']);
        $this->assertNull($out[0]['target_id']);
        $this->assertFalse($out[0]['matched'], 'Inconnu → à confirmer par l\'owner.');
        $this->assertSame(0.0, $out[0]['score']);
    }

    public function test_classifies_a_full_four_line_invoice_at_once(): void
    {
        $poulet = $this->material('Poulet');
        $cheddar = $this->material('Cheddar');
        $coca = $this->drink('Coca 33cl');

        $out = $this->service()->propose([
            $this->line('Poulet frais 3kg'),
            $this->line('Cheddar 100 tranches'),
            $this->line('Coca cola 24 canettes'),
            $this->line('Sac papier kraft 500'),
        ], 1);

        $this->assertSame([PurchaseLine::TARGET_RAW_MATERIAL, $poulet->id], [$out[0]['target_type'], $out[0]['target_id']]);
        $this->assertSame([PurchaseLine::TARGET_RAW_MATERIAL, $cheddar->id], [$out[1]['target_type'], $out[1]['target_id']]);
        $this->assertSame([PurchaseLine::TARGET_STOCK_ITEM, $coca->id], [$out[2]['target_type'], $out[2]['target_id']]);
        $this->assertSame([PurchaseLine::TARGET_CHARGE, null], [$out[3]['target_type'], $out[3]['target_id']]);
    }

    public function test_material_scope_is_branch_isolated(): void
    {
        // Matière d'une AUTRE branche → ne doit pas être proposée pour la branche 1.
        RawMaterial::create(['branch_id' => 2, 'name' => 'Poulet', 'unit' => 'g', 'is_active' => true]);

        $out = $this->service()->propose([$this->line('Poulet frais 3kg')], 1);

        // Aucune matière branche 1 → repli charge non confirmé.
        $this->assertSame(PurchaseLine::TARGET_CHARGE, $out[0]['target_type']);
        $this->assertFalse($out[0]['matched']);
    }
}
