<?php

namespace Tests\Feature\Kiosk;

use App\Enums\Status;
use App\Models\Branch;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Services\Kiosk\KioskMenuService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [UIUX-W4 K4 2026-06-11] — Catégorie vide = cul-de-sac écran blanc borne.
 *
 * `KioskMenuService::build()` projetait TOUTES les catégories actives
 * visibles sur le canal kiosk, même celles sans aucun item visible
 * (ex. « Tacos Signature » dont l'unique item est soft-deleted). La borne
 * affichait alors une entrée sidebar menant à une grille produits vide,
 * sans issue pour le client.
 *
 * Contrat verrouillé ici :
 *  - catégorie sans item → exclue du payload
 *  - catégorie dont l'unique item est soft-deleted → exclue
 *  - catégorie dont l'unique item est INACTIVE → exclue
 *  - catégorie parente sans item direct mais dont un enfant a un item
 *    visible → CONSERVÉE (hiérarchie sidebar intacte)
 *  - catégorie avec item actif → conservée
 */
class KioskMenuEmptyCategoryTest extends TestCase
{
    use RefreshDatabase;

    private function buildPayloadCategoryIds(Branch $branch): array
    {
        $payload = (new KioskMenuService())->build($branch->fresh());

        return collect($payload['categories'])
            ->map(fn (array $c): int => (int) $c['id'])
            ->all();
    }

    public function test_category_without_any_item_is_excluded_from_kiosk_payload(): void
    {
        $branch = Branch::factory()->create();

        $withItem = ItemCategory::factory()->create([
            'name' => 'Sandwichs', 'status' => Status::ACTIVE,
        ]);
        Item::factory()->create([
            'item_category_id' => $withItem->id,
            'status'           => Status::ACTIVE,
        ]);

        $empty = ItemCategory::factory()->create([
            'name' => 'Catégorie vide', 'status' => Status::ACTIVE,
        ]);

        $ids = $this->buildPayloadCategoryIds($branch);

        $this->assertContains((int) $withItem->id, $ids);
        $this->assertNotContains(
            (int) $empty->id,
            $ids,
            'A category with zero visible items must not reach the kiosk (dead-end white screen).'
        );
    }

    public function test_category_whose_only_item_is_soft_deleted_is_excluded(): void
    {
        $branch = Branch::factory()->create();

        $cat = ItemCategory::factory()->create([
            'name' => 'Tacos Signature', 'status' => Status::ACTIVE,
        ]);
        $item = Item::factory()->create([
            'item_category_id' => $cat->id,
            'status'           => Status::ACTIVE,
        ]);
        $item->delete(); // soft delete

        // Au moins une catégorie peuplée pour un payload non vide réaliste.
        $alive = ItemCategory::factory()->create([
            'name' => 'Boissons', 'status' => Status::ACTIVE,
        ]);
        Item::factory()->create([
            'item_category_id' => $alive->id,
            'status'           => Status::ACTIVE,
        ]);

        $ids = $this->buildPayloadCategoryIds($branch);

        $this->assertNotContains((int) $cat->id, $ids);
        $this->assertContains((int) $alive->id, $ids);
    }

    public function test_category_whose_only_item_is_inactive_is_excluded(): void
    {
        $branch = Branch::factory()->create();

        $cat = ItemCategory::factory()->create([
            'name' => 'Inactifs', 'status' => Status::ACTIVE,
        ]);
        Item::factory()->create([
            'item_category_id' => $cat->id,
            'status'           => Status::INACTIVE,
        ]);

        $ids = $this->buildPayloadCategoryIds($branch);

        $this->assertNotContains((int) $cat->id, $ids);
    }

    public function test_parent_category_with_populated_child_is_kept(): void
    {
        $branch = Branch::factory()->create();

        $parent = ItemCategory::factory()->create([
            'name' => 'Sandwichs (parent)', 'status' => Status::ACTIVE,
        ]);
        $child = ItemCategory::factory()->create([
            'name'      => 'Sandwichs Cayenne',
            'status'    => Status::ACTIVE,
            'parent_id' => $parent->id,
        ]);
        Item::factory()->create([
            'item_category_id' => $child->id,
            'status'           => Status::ACTIVE,
        ]);

        $ids = $this->buildPayloadCategoryIds($branch);

        $this->assertContains((int) $child->id, $ids);
        $this->assertContains(
            (int) $parent->id,
            $ids,
            'A parent category whose child holds visible items must stay in the payload (sidebar hierarchy).'
        );
    }
}
