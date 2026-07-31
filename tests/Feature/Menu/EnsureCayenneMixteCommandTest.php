<?php

namespace Tests\Feature\Menu;

use App\Console\Commands\EnsureCayenneMixteCommand;
use App\Enums\Status;
use App\Models\Item;
use App\Models\ItemAttribute;
use App\Models\ItemCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * [OWNER CAYENNE-MIXTE 2026-07-31] Le Cayenne doit exposer « Mixte (hachée + poulet) » comme un
 * CHOIX DE VIANDE GRATUIT (price 0, pas un supplément) + « Sans sauce » comme choix de sauce
 * gratuit, et le Cayenne pain (0 variation viande en base) doit être backfillé avec les viandes
 * canoniques (sinon la viande choisie côté web/borne est droppée au scellement — pickVariation par
 * nom ne trouve rien). Money-path : tout @0 → PricingService n'ajoute rien.
 */
class EnsureCayenneMixteCommandTest extends TestCase
{
    use RefreshDatabase;

    private function seedVariation(int $itemId, int $attrId, string $name, float $price = 0): void
    {
        DB::table('item_variations')->insert([
            'item_id' => $itemId, 'item_attribute_id' => $attrId, 'name' => $name,
            'price' => $price, 'status' => Status::ACTIVE, 'visible_on' => null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @test */
    public function cayenne_recoit_mixte_gratuit_sans_sauce_et_backfill_viandes(): void
    {
        $cat = ItemCategory::factory()->create(['name' => 'Sandwichs']);
        $viande = ItemAttribute::factory()->create(['name' => 'Viande 1']);
        $sauce = ItemAttribute::factory()->create(['name' => 'Sauce (1ère Gratuite)']);

        // Cayenne pain : AUCUNE viande, une sauce (comme #22 en prod).
        $cayenne = Item::factory()->create(['name' => 'Cayenne', 'item_category_id' => $cat->id, 'status' => Status::ACTIVE]);
        $this->seedVariation($cayenne->id, $sauce->id, 'Mayonnaise');

        // Frère porteur des 7 viandes canoniques (source du backfill), ex. Méga.
        $sibling = Item::factory()->create(['name' => 'Méga', 'item_category_id' => $cat->id, 'status' => Status::ACTIVE]);
        foreach (['Poulet mariné', 'Viande Hachée', 'Cordon Bleu'] as $m) {
            $this->seedVariation($sibling->id, $viande->id, $m);
        }

        $created = EnsureCayenneMixteCommand::ensure(false);
        $this->assertGreaterThan(0, $created);

        $mixte = DB::table('item_variations')->where('item_id', $cayenne->id)
            ->where('item_attribute_id', $viande->id)->where('name', EnsureCayenneMixteCommand::MIXTE_NAME)->first();
        $this->assertNotNull($mixte, 'Le Cayenne doit avoir « Mixte (hachée + poulet) » en choix de viande');
        $this->assertEquals(0.0, (float) $mixte->price, 'La viande Mixte doit être GRATUITE (choix, pas supplément)');
        $this->assertSame(Status::ACTIVE, (int) $mixte->status);

        $sansSauce = DB::table('item_variations')->where('item_id', $cayenne->id)
            ->where('item_attribute_id', $sauce->id)->where('name', EnsureCayenneMixteCommand::SANS_SAUCE_NAME)->first();
        $this->assertNotNull($sansSauce, 'Le Cayenne doit exposer « Sans sauce »');
        $this->assertEquals(0.0, (float) $sansSauce->price, '« Sans sauce » ne coûte rien');

        // Backfill : les viandes du frère sont copiées (sinon la viande web est droppée au scellement).
        foreach (['Poulet mariné', 'Viande Hachée', 'Cordon Bleu'] as $m) {
            $this->assertTrue(
                DB::table('item_variations')->where('item_id', $cayenne->id)
                    ->where('item_attribute_id', $viande->id)->where('name', $m)->exists(),
                "La viande « {$m} » doit être backfillée sur le Cayenne"
            );
        }
    }

    /** @test */
    public function est_idempotent(): void
    {
        $cat = ItemCategory::factory()->create(['name' => 'Sandwichs']);
        $viande = ItemAttribute::factory()->create(['name' => 'Viande 1']);
        ItemAttribute::factory()->create(['name' => 'Sauce (1ère Gratuite)']);
        $cayenne = Item::factory()->create(['name' => 'Cayenne', 'item_category_id' => $cat->id, 'status' => Status::ACTIVE]);
        $sibling = Item::factory()->create(['name' => 'Méga', 'item_category_id' => $cat->id, 'status' => Status::ACTIVE]);
        $this->seedVariation($sibling->id, $viande->id, 'Poulet mariné');

        EnsureCayenneMixteCommand::ensure(false);
        $this->assertSame(0, EnsureCayenneMixteCommand::ensure(false), 'Un 2e passage ne doit rien créer');
    }
}
