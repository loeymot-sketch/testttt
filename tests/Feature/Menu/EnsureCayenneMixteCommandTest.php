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
 * [OWNER CAYENNE-MIXTE 2026-07-31] Le Cayenne sandwich (mono-viande signature Poulet mariné) doit
 * exposer un CHOIX DE VIANDE LIMITÉ [Poulet mariné (défaut), Mixte (hachée + poulet)] — tous les deux
 * GRATUITS (price 0, pas un supplément) — + « Sans sauce » gratuit. « Mixte » ne transforme PAS le
 * Cayenne en build-your-own : la commande nettoie tout autre choix de viande. Money-path : tout @0
 * → PricingService (SSOT) n'ajoute rien ; la viande EN PLUS reste l'ItemExtra « Viande supplémentaire ».
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

    private function activeMeatNames(int $itemId, int $attrId): array
    {
        return DB::table('item_variations')->where('item_id', $itemId)
            ->where('item_attribute_id', $attrId)->whereNull('deleted_at')
            ->pluck('name')->sort()->values()->all();
    }

    /** @test */
    public function cayenne_recoit_choix_limite_poulet_mixte_gratuits_et_sans_sauce(): void
    {
        $cat = ItemCategory::factory()->create(['name' => 'Sandwichs']);
        $viande = ItemAttribute::factory()->create(['name' => 'Viande 1']);
        $sauce = ItemAttribute::factory()->create(['name' => 'Sauce (1ère Gratuite)']);

        // Cayenne : mono-viande (aucune variation en base), une sauce.
        $cayenne = Item::factory()->create(['name' => 'Cayenne', 'item_category_id' => $cat->id, 'status' => Status::ACTIVE]);
        $this->seedVariation($cayenne->id, $sauce->id, 'Mayonnaise');

        EnsureCayenneMixteCommand::ensure(false);

        // [OWNER 2026-08-01] Choix de viande = EXACTEMENT 3 (pas de build-your-own) :
        // Poulet mariné (défaut) · Mixte · Viande Hachée.
        $this->assertSame(
            ['Mixte (hachée + poulet)', 'Poulet mariné', 'Viande Hachée'],
            $this->activeMeatNames($cayenne->id, $viande->id),
            'Le Cayenne doit offrir exactement 3 choix : Poulet mariné, Mixte, Viande Hachée'
        );

        $hachee = DB::table('item_variations')->where('item_id', $cayenne->id)
            ->where('item_attribute_id', $viande->id)->where('name', EnsureCayenneMixteCommand::HACHEE_NAME)->first();
        $this->assertNotNull($hachee, 'Le Cayenne doit exposer « Viande Hachée »');
        $this->assertEquals(0.0, (float) $hachee->price, 'La Viande Hachée est un CHOIX gratuit, pas un supplément');
        $this->assertSame(['pos'], json_decode((string) $hachee->visible_on, true),
            'Le choix Viande Hachée doit être visible_on=[pos] (caisse), jamais borne');

        $mixte = DB::table('item_variations')->where('item_id', $cayenne->id)
            ->where('item_attribute_id', $viande->id)->where('name', EnsureCayenneMixteCommand::MIXTE_NAME)->first();
        $this->assertEquals(0.0, (float) $mixte->price, 'La viande Mixte doit être GRATUITE');
        // [OWNER 2026-08-01] Le choix Mixte est CAISSE-ONLY : la borne ne le voit pas (comme avant).
        $this->assertSame(['pos'], json_decode((string) $mixte->visible_on, true),
            'Le choix Mixte doit être visible_on=[pos] (caisse), jamais borne');
        $poulet = DB::table('item_variations')->where('item_id', $cayenne->id)
            ->where('item_attribute_id', $viande->id)->where('name', EnsureCayenneMixteCommand::SIGNATURE_MEAT)->first();
        $this->assertSame(['pos'], json_decode((string) $poulet->visible_on, true),
            'Sur le Cayenne sandwich, Poulet mariné aussi caisse-only → borne sans étape viande');

        $sansSauce = DB::table('item_variations')->where('item_id', $cayenne->id)
            ->where('item_attribute_id', $sauce->id)->where('name', EnsureCayenneMixteCommand::SANS_SAUCE_NAME)->first();
        $this->assertNotNull($sansSauce, 'Le Cayenne doit exposer « Sans sauce »');
        $this->assertEquals(0.0, (float) $sansSauce->price);
    }

    /** @test */
    public function nettoie_un_choix_de_viande_trop_large_sur_le_cayenne(): void
    {
        $cat = ItemCategory::factory()->create(['name' => 'Sandwichs']);
        $viande = ItemAttribute::factory()->create(['name' => 'Viande 1']);
        ItemAttribute::factory()->create(['name' => 'Sauce (1ère Gratuite)']);

        // Cayenne avec un « build-your-own » de 7 viandes déjà présent (répare l'ancien backfill).
        $cayenne = Item::factory()->create(['name' => 'Cayenne', 'item_category_id' => $cat->id, 'status' => Status::ACTIVE]);
        foreach (['Poulet mariné', 'Mexicanos', 'Cordon Bleu', 'Viande Hachée', 'Nuggets', 'Tenders', 'Fricadelle'] as $m) {
            $this->seedVariation($cayenne->id, $viande->id, $m);
        }

        EnsureCayenneMixteCommand::ensure(false);

        $this->assertSame(
            ['Mixte (hachée + poulet)', 'Poulet mariné', 'Viande Hachée'],
            $this->activeMeatNames($cayenne->id, $viande->id),
            'Les viandes en trop sont soft-delete ; Poulet + Mixte + Viande Hachée restent actifs'
        );
    }

    /**
     * [OWNER 2026-08-01] Régression : « Viande Hachée » avait été soft-supprimée du Cayenne par les
     * passages précédents (nettoyage whereNotIn). Le nouveau passage doit RESSUSCITER cette ligne,
     * pas en insérer une seconde à côté de la ligne morte.
     *
     * @test
     */
    public function ressuscite_la_viande_hachee_soft_supprimee_sans_creer_de_doublon(): void
    {
        $cat = ItemCategory::factory()->create(['name' => 'Sandwichs']);
        $viande = ItemAttribute::factory()->create(['name' => 'Viande 1']);
        ItemAttribute::factory()->create(['name' => 'Sauce (1ère Gratuite)']);

        $cayenne = Item::factory()->create(['name' => 'Cayenne', 'item_category_id' => $cat->id, 'status' => Status::ACTIVE]);
        $this->seedVariation($cayenne->id, $viande->id, EnsureCayenneMixteCommand::HACHEE_NAME);
        DB::table('item_variations')->where('item_id', $cayenne->id)
            ->where('item_attribute_id', $viande->id)
            ->where('name', EnsureCayenneMixteCommand::HACHEE_NAME)
            ->update(['deleted_at' => now()]);

        EnsureCayenneMixteCommand::ensure(false);

        $rows = DB::table('item_variations')->where('item_id', $cayenne->id)
            ->where('item_attribute_id', $viande->id)
            ->where('name', EnsureCayenneMixteCommand::HACHEE_NAME)->get();
        $this->assertCount(1, $rows, 'Une SEULE ligne « Viande Hachée » — la morte est ressuscitée, pas doublée');
        $this->assertNull($rows->first()->deleted_at, 'La ligne doit être vivante');
        $this->assertSame(['pos'], json_decode((string) $rows->first()->visible_on, true),
            'Et caisse-only comme les deux autres choix');
    }

    /** @test */
    public function galette_cayenne_garde_son_choix_de_viandes_et_recoit_mixte(): void
    {
        $cat = ItemCategory::factory()->create(['name' => 'Galette']);
        $viande = ItemAttribute::factory()->create(['name' => 'Viande 1']);
        ItemAttribute::factory()->create(['name' => 'Sauce (1ère Gratuite)']);

        // La Galette Cayenne offre un vrai choix de 7 viandes → on ne touche pas, on AJOUTE Mixte.
        $galette = Item::factory()->create(['name' => 'Galette Cayenne', 'item_category_id' => $cat->id, 'status' => Status::ACTIVE]);
        foreach (['Poulet mariné', 'Mexicanos', 'Cordon Bleu', 'Viande Hachée', 'Nuggets', 'Tenders', 'Fricadelle'] as $m) {
            $this->seedVariation($galette->id, $viande->id, $m);
        }

        EnsureCayenneMixteCommand::ensure(false);

        $names = $this->activeMeatNames($galette->id, $viande->id);
        $this->assertContains('Mixte (hachée + poulet)', $names, 'La Galette Cayenne reçoit « Mixte »');
        $this->assertContains('Fricadelle', $names, 'La Galette Cayenne garde son choix de viandes');
        $this->assertCount(8, $names, '7 viandes + Mixte');
    }

    /** @test */
    public function est_idempotent(): void
    {
        $cat = ItemCategory::factory()->create(['name' => 'Sandwichs']);
        ItemAttribute::factory()->create(['name' => 'Viande 1']);
        ItemAttribute::factory()->create(['name' => 'Sauce (1ère Gratuite)']);
        Item::factory()->create(['name' => 'Cayenne', 'item_category_id' => $cat->id, 'status' => Status::ACTIVE]);

        EnsureCayenneMixteCommand::ensure(false);
        $this->assertSame(0, EnsureCayenneMixteCommand::ensure(false), 'Un 2e passage ne doit rien créer');
    }
}
