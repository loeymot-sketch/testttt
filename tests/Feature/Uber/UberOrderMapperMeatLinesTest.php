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
}
