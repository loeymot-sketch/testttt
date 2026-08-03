<?php

namespace Tests\Feature\Catalog;

use App\Enums\Status;
use App\Http\Resources\NormalItemResource;
use App\Models\Branch;
use App\Models\Item;
use App\Models\ItemBranchAvailability;
use App\Models\ItemCategory;
use App\Models\Tax;
use App\Services\ItemService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [F-DETAILS-BRANCH-AVAIL 2026-07-15 / P1] L'endpoint DÉTAILS produit (NormalItemResource, via
 * ItemService::itemDetails) exposait le flag GLOBAL is_available et IGNORAIT la rupture PAR
 * BRANCHE (ItemBranchAvailability, posée par le dashboard rupture) — alors que la LISTE
 * (SimpleItemResource) la reflétait. Le wizard borne consulte les détails pour la détection
 * mid-wizard → il laissait configurer un produit en rupture, refusé au quote. Le fix rend les
 * détails branch-aware quand un branch_id est fourni (rétro-compatible sinon).
 */
class ItemDetailsBranchAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    private function makeAvailableItem(): Item
    {
        $cat = ItemCategory::create(['name' => 'Boissons', 'slug' => 'boissons-'.uniqid(), 'sort' => 1, 'status' => Status::ACTIVE]);
        $tax = Tax::factory()->create();

        return Item::create([
            'name' => 'Coca-Cola 33cl',
            'slug' => 'coca-'.uniqid(),
            'item_category_id' => $cat->id,
            'tax_id' => $tax->id,
            'item_type' => 1,
            'price' => 1.90,
            'is_available' => true, // GLOBALEMENT disponible
            'status' => Status::ACTIVE,
            'order' => 1,
        ]);
    }

    private function rupture(Branch $branch, Item $item): void
    {
        ItemBranchAvailability::create([
            'branch_id' => $branch->id,
            'item_id' => $item->id,
            'is_available' => false,
            'unavailable_reason' => 'Rupture frigo',
            'daily_reset_at' => now()->toDateString(),
        ]);
    }

    public function test_details_reflect_branch_rupture_when_branch_id_provided(): void
    {
        $branch = Branch::factory()->create();
        $item = $this->makeAvailableItem();
        $this->rupture($branch, $item);

        $detailed = app(ItemService::class)->itemDetails($item->fresh(), $branch->id);

        $this->assertFalse((bool) $detailed->effective_is_available,
            'Les détails doivent refléter la rupture PAR BRANCHE (pas seulement le flag global).');

        $payload = (new NormalItemResource($detailed))->toArray(request());
        $this->assertFalse($payload['is_available'], 'La ressource détails doit exposer is_available=false en rupture branche.');
        $this->assertSame('Rupture frigo', $payload['availability_reason'], 'Le motif de rupture doit être exposé.');
    }

    public function test_details_stay_available_on_a_branch_without_rupture(): void
    {
        $branchA = Branch::factory()->create();
        $branchB = Branch::factory()->create();
        $item = $this->makeAvailableItem();
        $this->rupture($branchA, $item); // rupture sur A uniquement

        // Branche B : pas de rupture → disponible.
        $detailed = app(ItemService::class)->itemDetails($item->fresh(), $branchB->id);
        $payload = (new NormalItemResource($detailed))->toArray(request());

        $this->assertTrue($payload['is_available'], 'Une branche sans rupture doit rester disponible.');
        $this->assertNull($payload['availability_reason']);
    }

    public function test_details_fall_back_to_global_when_no_branch_id(): void
    {
        $item = $this->makeAvailableItem();

        // Sans branch_id (ex. admin branche 0) → comportement global inchangé (rétro-compatible).
        $detailed = app(ItemService::class)->itemDetails($item->fresh(), null);
        $payload = (new NormalItemResource($detailed))->toArray(request());

        $this->assertTrue($payload['is_available'], 'Sans branch_id, le flag global s’applique (rétro-compatible).');
    }
}
