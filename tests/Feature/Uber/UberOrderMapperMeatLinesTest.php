<?php

namespace Tests\Feature\Uber;

use App\Enums\Status;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Services\Kitchen\MeatPortionCalculator;
use App\Services\Uber\UberOrderMapper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [FIX CUISSON-UBER 2026-08-14 owner] « ça calcule pas les viande pour cuisson en haut comme
 * tout les autre commande » — avant ce fix, `UberOrderMapper::mapLine()` laissait
 * `composition_snapshot.lines` TOUJOURS vide (toute la viande finissait en extra, un format
 * que MeatPortionCalculator/kdsSymbolic.js ne lisent jamais pour le comptage). Une commande
 * Uber avec un choix de viande (tacos, sandwich...) ne comptait donc JAMAIS dans le bandeau
 * de cuisson, contrairement à la même commande prise borne/caisse/web.
 */
class UberOrderMapperMeatLinesTest extends TestCase
{
    use RefreshDatabase;

    private function makeCatalogItem(string $name): Item
    {
        $cat = ItemCategory::query()->firstOrCreate(
            ['slug' => 'tacos'],
            ['name' => 'Tacos', 'status' => Status::ACTIVE, 'channels' => []]
        );

        return Item::query()->create([
            'item_category_id' => $cat->id,
            'slug' => 'tacos-'.\Illuminate\Support\Str::slug($name),
            'name' => $name,
            'price' => 8.5,
            'status' => Status::ACTIVE,
            'is_available' => 1,
            'order' => 0,
        ]);
    }

    private function mapLineWithModifierGroup(string $groupTitle, string $modTitle): array
    {
        $this->makeCatalogItem('Tacos M');
        $mapper = app(UberOrderMapper::class);
        $mapped = $mapper->map([
            'display_id' => 'ABC-1234',
            'cart' => [
                'items' => [[
                    'title' => 'Tacos M',
                    'quantity' => 1,
                    'price' => ['unit_price' => ['amount' => 850], 'total_price' => ['amount' => 850]],
                    'selected_modifier_groups' => [[
                        'title' => $groupTitle,
                        'selected_items' => [[
                            'title' => $modTitle,
                            'quantity' => 1,
                            'price' => ['amount' => 0],
                        ]],
                    ]],
                ]],
            ],
        ]);

        return $mapped['items'][0];
    }

    public function test_meat_modifier_group_populates_composition_snapshot_lines(): void
    {
        $line = $this->mapLineWithModifierGroup('Choix de la viande', 'Bœuf haché');

        $this->assertSame(
            [['attribute_name' => 'Viande', 'variation_name' => 'Bœuf haché']],
            $line['composition_snapshot']['lines'],
            'le choix de viande Uber doit atterrir dans `lines`, pas seulement `extras`'
        );
        $this->assertSame(
            ['extra_name' => 'Bœuf haché', 'quantity' => 1, 'line_total' => 0.0],
            $line['composition_snapshot']['extras'][0],
            'extras reste peuplé à l\'identique (additif, rien de cassé côté ticket existant)'
        );
    }

    public function test_non_meat_modifier_group_does_not_pollute_lines(): void
    {
        $line = $this->mapLineWithModifierGroup('Sauces', 'Sauce Andalouse');

        $this->assertSame([], $line['composition_snapshot']['lines'], 'un groupe non-viande ne doit jamais alimenter `lines`');
        $this->assertCount(1, $line['composition_snapshot']['extras']);
    }

    public function test_meat_line_is_actually_counted_by_the_cuisson_calculator(): void
    {
        $line = $this->mapLineWithModifierGroup('Choix de la viande', 'Bœuf haché');

        $calc = app(MeatPortionCalculator::class);
        $result = $calc->forLine('Tacos M', $line['composition_snapshot'], $line['quantity'], $line['instruction']);

        $this->assertNotEmpty($result['pieces'], 'preuve bout-en-bout : le bandeau de cuisson compte maintenant la viande Uber');
        $this->assertArrayHasKey('K', $result['pieces'], 'Bœuf haché doit résoudre au symbole K, comme pour les autres canaux');
    }

    public function test_english_meat_group_title_is_also_detected(): void
    {
        $line = $this->mapLineWithModifierGroup('Choose your meat', 'Grilled Chicken');

        $this->assertSame(
            [['attribute_name' => 'Viande', 'variation_name' => 'Grilled Chicken']],
            $line['composition_snapshot']['lines']
        );
    }

    /**
     * [D6 2026-08-15 · GOAL_CONFORT_MAX] « Sans poulet » n'est PAS un choix de poulet. Nos
     * canaux maison sont additifs (rien coché = rien) ; un ticket Uber s'écrit en négatif —
     * exactement la leçon déjà tirée pour les crudités le 2026-08-12
     * (KitchenTicketSymbolicFormatter::cruditeSymbol) et RE-INTRODUITE ici par erreur le
     * 2026-08-14 (ce fix lui-même). Sans la garde, meatSymbol('Sans poulet') matche /poulet/
     * → 'P' : la cuisine cuit ce que le client vient de refuser.
     */
    public function test_negated_meat_modifier_does_not_pollute_lines(): void
    {
        $line = $this->mapLineWithModifierGroup('Choix de la viande', 'Sans poulet');

        $this->assertSame(
            [],
            $line['composition_snapshot']['lines'],
            '« Sans poulet » ne doit JAMAIS devenir une ligne de viande — sinon la cuisine en cuit'
        );
        // extras reste peuplé (visibilité ticket inchangée, additif strict).
        $this->assertSame('Sans poulet', $line['composition_snapshot']['extras'][0]['extra_name']);
    }

    /** @dataProvider negationPhrasesProvider */
    public function test_negation_variants_fr_and_en_are_all_ignored(string $negatedModTitle): void
    {
        $line = $this->mapLineWithModifierGroup('Choix de la viande', $negatedModTitle);

        $this->assertSame([], $line['composition_snapshot']['lines'], "« $negatedModTitle » ne doit pas alimenter lines");
    }

    public static function negationPhrasesProvider(): array
    {
        return [
            'sans' => ['Sans poulet'],
            'sans accent' => ['sans boeuf'],
            "pas de" => ['Pas de poulet'],
            "pas d'" => ["Pas d'agneau"],
            'no' => ['No chicken'],
            'without' => ['Without beef'],
            'w/o' => ['w/o chicken'],
        ];
    }

    /**
     * Contre-exemple : la négation n'est reconnue qu'en TÊTE (même contrat que
     * cruditeSymbol) — un modificateur qui contient « sans » ailleurs que comme premier mot
     * reste un VRAI ajout de viande et doit continuer à compter.
     */
    public function test_negation_word_mid_string_is_not_treated_as_refusal(): void
    {
        $line = $this->mapLineWithModifierGroup('Choix de la viande', 'Poulet sans gluten');

        $this->assertSame(
            [['attribute_name' => 'Viande', 'variation_name' => 'Poulet sans gluten']],
            $line['composition_snapshot']['lines'],
            '« sans » au milieu (pas en tête) ne doit pas effacer un vrai choix de viande'
        );
    }

    /**
     * Preuve bout-en-bout : même en passant par MeatPortionCalculator (pas seulement en
     * inspectant `lines`), une viande refusée ne produit ni symbole ni portion fantôme.
     */
    public function test_negated_meat_produces_no_phantom_portion_via_calculator(): void
    {
        $line = $this->mapLineWithModifierGroup('Choix de la viande', 'Sans poulet');

        $calc = app(MeatPortionCalculator::class);
        $result = $calc->forLine('Tacos M', $line['composition_snapshot'], $line['quantity'], $line['instruction']);

        $this->assertSame([], $result['pieces'], 'un refus ne doit produire AUCUNE pièce à cuire — ni P, ni un autre symbole');
    }
}
