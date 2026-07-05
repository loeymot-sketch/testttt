<?php

namespace Tests\Feature\Frontend;

use App\Enums\Status;
use App\Http\Requests\PaginateRequest;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Tax;
use App\Services\ItemService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [SELF-AUDIT R6 P3 2026-07-05] Le catalogue PUBLIC (ItemService::simpleList → /api/frontend/item, borne/
 * web/mobile, x-api-key public) exposait les articles INACTIFS (désactivés par l'admin), y compris via un
 * `?status=10` forgé. Ce test verrouille la visibilité ACTIVE-only côté serveur.
 */
class PublicCatalogActiveOnlyTest extends TestCase
{
    use RefreshDatabase;

    private function item(int $status): Item
    {
        $cat = ItemCategory::factory()->create();
        $tax = Tax::factory()->create();

        return Item::factory()->create(['item_category_id' => $cat->id, 'tax_id' => $tax->id, 'status' => $status, 'price' => 9.90]);
    }

    public function test_public_catalog_returns_active_but_not_inactive(): void
    {
        $active = $this->item(Status::ACTIVE);
        $inactive = $this->item(Status::INACTIVE);

        $ids = collect(app(ItemService::class)->simpleList(new PaginateRequest))->pluck('id');

        $this->assertTrue($ids->contains($active->id), 'Un article ACTIF est visible.');
        $this->assertFalse($ids->contains($inactive->id), 'Un article INACTIF ne doit PAS fuiter sur le catalogue public.');
    }

    public function test_forged_status_param_cannot_reveal_inactive_items(): void
    {
        $inactive = $this->item(Status::INACTIVE);

        $req = new PaginateRequest;
        $req->merge(['status' => Status::INACTIVE]); // l'attaquant tente de forcer la visibilité des INACTIFS

        $ids = collect(app(ItemService::class)->simpleList($req))->pluck('id');

        $this->assertFalse($ids->contains($inactive->id), 'Un `?status=10` forgé ne doit PAS révéler les INACTIFS.');
    }
}
