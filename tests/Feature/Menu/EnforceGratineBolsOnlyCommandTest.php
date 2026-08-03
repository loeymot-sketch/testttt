<?php

namespace Tests\Feature\Menu;

use App\Enums\Status;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\ItemExtra;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [OWNER 2026-07-17] « Le supplément gratiné est seulement fait pour les bols,
 * et il est à 2 € pas 1 €. »
 *
 * menu:enforce-gratine-bols-only :
 *  - retire (soft-delete) tout extra gratiné vivant des items HORS catégorie bols ;
 *  - garantit « Option Gratiné » @2,00 (group supplement_bol) sur chaque bol ACTIF ;
 *  - normalise prix/groupe d'un gratiné bol existant (1 € legacy → 2 €) ;
 *  - idempotent.
 */
class EnforceGratineBolsOnlyCommandTest extends TestCase
{
    use RefreshDatabase;

    private function makeCategory(string $slug, string $name): ItemCategory
    {
        return ItemCategory::query()->firstOrCreate(
            ['slug' => $slug],
            ['name' => $name, 'status' => Status::ACTIVE]
        );
    }

    private function makeItem(ItemCategory $cat, int $status = Status::ACTIVE): Item
    {
        return Item::factory()->create(['item_category_id' => $cat->id, 'status' => $status]);
    }

    private function addGratine(Item $item, string $name, float $price, string $group): ItemExtra
    {
        return ItemExtra::create([
            'item_id'      => $item->id,
            'name'         => $name,
            'price'        => $price,
            'status'       => Status::ACTIVE,
            'group_label'  => $group,
            'is_available' => 1,
        ]);
    }

    public function test_removes_gratine_from_non_bol_items(): void
    {
        $galettes = $this->makeCategory('galette', 'Galette');
        $galette = $this->makeItem($galettes);
        $this->addGratine($galette, 'Boule gratinée', 1.00, 'supplement');

        $this->artisan('menu:enforce-gratine-bols-only')->assertExitCode(0);

        $this->assertSame(
            0,
            ItemExtra::where('item_id', $galette->id)->where('name', 'like', '%gratin%')->count(),
            'gratiné hors bols → soft-deleted (plus vivant)'
        );
        $trashed = ItemExtra::withTrashed()
            ->where('item_id', $galette->id)->where('name', 'Boule gratinée')->first();
        $this->assertNotNull($trashed?->deleted_at, 'soft-delete, pas de hard delete');
    }

    public function test_ensures_option_gratine_2eur_on_active_bols_only(): void
    {
        $bols = $this->makeCategory('bols', 'Bols');
        $bolActif = $this->makeItem($bols);
        $bolInactif = $this->makeItem($bols, Status::INACTIVE);

        $this->artisan('menu:enforce-gratine-bols-only')->assertExitCode(0);

        $row = ItemExtra::where('item_id', $bolActif->id)->where('name', 'Option Gratiné')->first();
        $this->assertNotNull($row, 'bol actif sans gratiné → Option Gratiné créé');
        $this->assertEqualsWithDelta(2.00, (float) $row->price, 0.001, 'prix owner = 2 €');
        $this->assertSame('supplement_bol', $row->group_label);
        $this->assertSame((int) Status::ACTIVE, (int) $row->status);

        $this->assertSame(
            0,
            ItemExtra::where('item_id', $bolInactif->id)->where('name', 'like', '%gratin%')->count(),
            'bol INACTIF ignoré'
        );
    }

    public function test_normalizes_existing_bol_gratine_to_2eur_without_duplicate(): void
    {
        $bols = $this->makeCategory('bols-gourmands', 'Bols Gourmands');
        $bol = $this->makeItem($bols);
        $legacy = $this->addGratine($bol, 'Boule gratinée', 1.00, 'supplement');

        $this->artisan('menu:enforce-gratine-bols-only')->assertExitCode(0);

        $legacy->refresh();
        $this->assertEqualsWithDelta(2.00, (float) $legacy->price, 0.001, 'prix normalisé 1 € → 2 €');
        $this->assertSame('supplement_bol', $legacy->group_label, 'groupe normalisé');
        $this->assertSame(
            1,
            ItemExtra::where('item_id', $bol->id)->where('name', 'like', '%gratin%')->count(),
            'pas de doublon Option Gratiné : le gratiné existant vivant suffit'
        );
    }

    public function test_dedupes_double_gratine_on_same_bol_keeping_option_gratine(): void
    {
        $bols = $this->makeCategory('bols', 'Bols');
        $bol = $this->makeItem($bols);
        $this->addGratine($bol, 'Boule gratinée', 2.00, 'supplement_bol');
        $kept = $this->addGratine($bol, 'Option Gratiné', 2.00, 'supplement_bol');

        $this->artisan('menu:enforce-gratine-bols-only')->assertExitCode(0);

        $live = ItemExtra::where('item_id', $bol->id)->where('name', 'like', '%ratin%')->get();
        $this->assertCount(1, $live, 'un seul gratiné vivant après dédup (sinon 2×2 € cumulables)');
        $this->assertSame($kept->id, $live->first()->id, 'préférence « Option Gratiné »');
    }

    public function test_idempotent_second_run_changes_nothing(): void
    {
        $bols = $this->makeCategory('bols', 'Bols');
        $galettes = $this->makeCategory('galette', 'Galette');
        $bol = $this->makeItem($bols);
        $galette = $this->makeItem($galettes);
        $this->addGratine($galette, 'Boule gratinée', 1.00, 'supplement');

        $this->artisan('menu:enforce-gratine-bols-only')->assertExitCode(0);
        $first = ItemExtra::withTrashed()->orderBy('id')
            ->get(['id', 'item_id', 'name', 'price', 'group_label', 'status', 'deleted_at'])->toArray();

        $this->artisan('menu:enforce-gratine-bols-only')->assertExitCode(0);
        $second = ItemExtra::withTrashed()->orderBy('id')
            ->get(['id', 'item_id', 'name', 'price', 'group_label', 'status', 'deleted_at'])->toArray();

        $this->assertSame($first, $second, 'second run = zéro changement');
        $this->assertSame(
            1,
            ItemExtra::where('item_id', $bol->id)->where('name', 'Option Gratiné')->count(),
            'toujours exactement 1 Option Gratiné sur le bol'
        );
    }
}
