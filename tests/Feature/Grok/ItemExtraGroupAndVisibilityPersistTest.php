<?php

namespace Tests\Feature\Grok;

use App\Enums\Status;
use App\Models\Item;
use App\Models\ItemExtra;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Gestes commerçant : sur un extra, cocher « Visible sur / Borne » et
 * remplir « Groupe (Sauce) ». L'écran doit réécrire ça en base, pas juste
 * afficher les cases.
 */
class ItemExtraGroupAndVisibilityPersistTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();
        $this->seedSpatieRoles();
    }

    public function test_store_persists_group_label_and_visible_on(): void
    {
        $admin = \Database\Factories\UserFactory::new()->create(['branch_id' => 0]);
        $admin->assignRole('Admin');
        Sanctum::actingAs($admin, ['*']);

        $item = Item::factory()->create(['status' => Status::ACTIVE]);

        $response = $this->postJson('/api/admin/item/extra/'.$item->id, [
            'name' => 'Salade',
            'price' => 0,
            'status' => Status::ACTIVE,
            'group_label' => 'Garniture',
            'visible_on' => ['kiosk', 'pos'],
        ]);

        $response->assertSuccessful();
        $extra = ItemExtra::query()->where('item_id', $item->id)->where('name', 'Salade')->first();
        $this->assertNotNull($extra);
        $this->assertSame('Garniture', $extra->group_label);
        $this->assertSame(['kiosk', 'pos'], $extra->visible_on);
    }

    public function test_update_can_clear_restriction_to_all_surfaces(): void
    {
        $admin = \Database\Factories\UserFactory::new()->create(['branch_id' => 0]);
        $admin->assignRole('Admin');
        Sanctum::actingAs($admin, ['*']);

        $item = Item::factory()->create(['status' => Status::ACTIVE]);
        $extra = ItemExtra::query()->create([
            'item_id' => $item->id,
            'name' => 'Tomate',
            'price' => 0,
            'status' => Status::ACTIVE,
            'group_label' => 'Garniture',
            'visible_on' => ['kiosk'],
        ]);

        $response = $this->putJson('/api/admin/item/extra/'.$item->id.'/'.$extra->id, [
            'name' => 'Tomate',
            'price' => 0,
            'status' => Status::ACTIVE,
            'group_label' => 'Garniture',
            'visible_on' => null,
        ]);

        $response->assertSuccessful();
        $this->assertNull($extra->fresh()->visible_on);
    }
}
