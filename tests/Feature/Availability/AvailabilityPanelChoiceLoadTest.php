<?php

namespace Tests\Feature\Availability;

use App\Enums\Status;
use App\Models\Branch;
use App\Models\Item;
use App\Models\ItemAttribute;
use App\Models\ItemExtra;
use App\Models\ItemVariation;
use App\Models\User;
use Database\Seeders\AvailabilityTogglePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [GOAL PARITE-SYNC 2026-07-18 / chantier 2 D1 + D2]
 *
 * Le panneau rupture partagé (caisse + cuisine) charge les extras/variations
 * d'un item via l'endpoint EXISTANT `GET /api/admin/item/details/{item}`
 * (NormalItemResource) pour permettre le 86 ciblé — PAS via `item/show`, qui
 * exige `items_show` que le caissier (POS Operator) et le chef n'ont pas.
 *
 * On prouve ici la dépendance data-load du panel pour la persona cible :
 *  - un user `availability_toggle` (sans items_show/items_edit) PEUT charger
 *    item/details (pas 403) — l'exemption ItemController le couvre ;
 *  - la rupture 86 posée sur un extra / une variation se REFLÈTE dans le
 *    payload que le panel relit (temps réel, branch-aware).
 *
 * D2 : ces routes sont `auth:sanctum` et le SPA admin envoie un Bearer token
 * (aucun chemin session/cookie web — EnsureFrontendRequestsAreStateful est
 * désactivé dans le groupe `api`). La résolution de permission passe donc par
 * le guard sanctum, prouvée verte par `actingAs($user, 'sanctum')` ci-dessous.
 */
class AvailabilityPanelChoiceLoadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedMinimalSettings();
        $this->seedSpatieRoles();
        $this->seed(AvailabilityTogglePermissionSeeder::class);
    }

    public function test_availability_toggle_user_can_load_item_details_for_panel(): void
    {
        [$user, $branch, $item, $extra, $variation] = $this->makeUserAndItemWithChoices();

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/admin/item/details/' . $item->id . '?branch_id=' . $branch->id);

        $response->assertOk()
            ->assertJsonStructure(['data' => ['id', 'extras', 'variations']]);

        $data = $response->json('data');
        $this->assertContains($extra->id, collect($data['extras'])->pluck('id')->all());
        $variationIds = collect($data['variations'])->flatten(1)->pluck('id')->all();
        $this->assertContains($variation->id, $variationIds);
    }

    public function test_item_details_reflects_extra_rupture_for_panel(): void
    {
        [$user, $branch, $item, $extra] = $this->makeUserAndItemWithChoices();

        // Le caissier/chef met l'extra en rupture depuis le panel (endpoint réutilisé).
        $this->actingAs($user, 'sanctum')->postJson('/api/admin/menu/availability/extra/toggle', [
            'extra_id' => $extra->id,
            'branch_id' => $branch->id,
            'is_available' => false,
            'reason' => 'out_of_stock_manual',
        ])->assertOk();

        // Le panel relit item/details : l'extra doit apparaître indisponible.
        $data = $this->actingAs($user, 'sanctum')
            ->getJson('/api/admin/item/details/' . $item->id . '?branch_id=' . $branch->id)
            ->assertOk()
            ->json('data');

        $extraRow = collect($data['extras'])->firstWhere('id', $extra->id);
        $this->assertNotNull($extraRow);
        $this->assertFalse($extraRow['is_available']);
    }

    public function test_item_details_reflects_variation_rupture_for_panel(): void
    {
        [$user, $branch, $item, , $variation] = $this->makeUserAndItemWithChoices();

        $this->actingAs($user, 'sanctum')->postJson('/api/admin/menu/availability/variation/toggle', [
            'variation_id' => $variation->id,
            'branch_id' => $branch->id,
            'is_available' => false,
            'reason' => 'out_of_stock_manual',
        ])->assertOk();

        $data = $this->actingAs($user, 'sanctum')
            ->getJson('/api/admin/item/details/' . $item->id . '?branch_id=' . $branch->id)
            ->assertOk()
            ->json('data');

        $variationRow = collect($data['variations'])->flatten(1)->firstWhere('id', $variation->id);
        $this->assertNotNull($variationRow);
        $this->assertFalse($variationRow['is_available']);
    }

    public function test_user_without_any_menu_permission_cannot_load_item_details(): void
    {
        [, $branch, $item] = $this->makeUserAndItemWithChoices();
        $stranger = User::factory()->create(['branch_id' => $branch->id]);

        $this->actingAs($stranger, 'sanctum')
            ->getJson('/api/admin/item/details/' . $item->id . '?branch_id=' . $branch->id)
            ->assertForbidden();
    }

    /**
     * @return array{0: User, 1: Branch, 2: Item, 3: ItemExtra, 4: ItemVariation}
     */
    private function makeUserAndItemWithChoices(): array
    {
        $branch = Branch::factory()->create();
        // Persona cible : droit de rupture SEUL (ni items_show ni items_edit ni pos).
        $user = User::factory()->create(['branch_id' => $branch->id]);
        $user->givePermissionTo('availability_toggle');

        $item = Item::factory()->create(['status' => Status::ACTIVE]);
        $extra = ItemExtra::query()->create([
            'item_id' => $item->id,
            'name' => 'Sauce Algérienne',
            'price' => 0.50,
            'status' => Status::ACTIVE,
            'group_label' => 'sauce',
            'is_available' => true,
        ]);
        $attribute = ItemAttribute::factory()->create(['is_available' => true]);
        $variation = ItemVariation::query()->create([
            'item_id' => $item->id,
            'item_attribute_id' => $attribute->id,
            'name' => 'Maxi',
            'price' => 1.50,
            'status' => Status::ACTIVE,
        ]);

        return [$user, $branch, $item, $extra, $variation];
    }
}
