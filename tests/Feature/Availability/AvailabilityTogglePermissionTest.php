<?php

namespace Tests\Feature\Availability;

use App\Models\Branch;
use App\Models\Item;
use App\Models\User;
use Database\Seeders\AvailabilityTogglePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [GOAL RUPTURE-CARNET 2026-07-15 / W1] Permission dédiée `availability_toggle` :
 * la caisse (POS Operator) et la cuisine (Chef) peuvent marquer un produit en
 * rupture SANS recevoir `items_edit` (qui ouvrirait prix + composition = surface
 * NF525-adjacente trop large). `setMaxDailyQty` reste réservé à `items_edit`
 * (paramétrage catalogue, pas opération de service).
 */
class AvailabilityTogglePermissionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedMinimalSettings();
        $this->seedSpatieRoles();
        $this->seed(AvailabilityTogglePermissionSeeder::class);
    }

    public function test_availability_toggle_permission_allows_toggle_but_not_max_daily_qty(): void
    {
        $branch = Branch::factory()->create();
        $item = Item::factory()->create();
        $user = User::factory()->create(['branch_id' => $branch->id]);
        $user->givePermissionTo('availability_toggle');

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/admin/menu/availability/toggle', $this->togglePayload($item, $branch))
            ->assertOk();

        $this->assertDatabaseHas('item_branch_availability', [
            'item_id' => $item->id,
            'branch_id' => $branch->id,
            'is_available' => false,
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/admin/menu/availability/max-daily-qty', [
                'item_id' => $item->id,
                'branch_id' => $branch->id,
                'max_daily_qty' => 5,
            ])
            ->assertForbidden();
    }

    public function test_seeder_grants_availability_toggle_to_pos_operator_and_chef(): void
    {
        $branch = Branch::factory()->create();
        $item = Item::factory()->create();

        foreach (['POS Operator', 'Chef', 'Branch Manager'] as $roleName) {
            $user = User::factory()->create(['branch_id' => $branch->id]);
            $user->assignRole($roleName);

            $this->actingAs($user, 'sanctum')
                ->postJson('/api/admin/menu/availability/toggle', $this->togglePayload($item, $branch))
                ->assertOk();
        }
    }

    public function test_availability_toggle_grants_item_list_for_the_panel(): void
    {
        // [W6 heal P1] Le Chef (KDS) doit pouvoir LISTER les items — le panel
        // rupture fetch GET /api/admin/item ; sans ce droit le panel était mort
        // (403) pour son persona cible.
        $branch = Branch::factory()->create();
        $user = User::factory()->create(['branch_id' => $branch->id]);
        $user->assignRole('Chef');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/admin/item')
            ->assertOk();
    }

    public function test_user_without_any_permission_is_still_forbidden(): void
    {
        $branch = Branch::factory()->create();
        $item = Item::factory()->create();
        $user = User::factory()->create(['branch_id' => $branch->id]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/admin/menu/availability/toggle', $this->togglePayload($item, $branch))
            ->assertForbidden();
    }

    public function test_items_edit_alone_still_allows_toggle_no_regression(): void
    {
        $branch = Branch::factory()->create();
        $item = Item::factory()->create();
        $user = User::factory()->create(['branch_id' => $branch->id]);
        $user->givePermissionTo('items_edit');

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/admin/menu/availability/toggle', $this->togglePayload($item, $branch))
            ->assertOk();
    }

    private function togglePayload(Item $item, Branch $branch): array
    {
        return [
            'item_id' => $item->id,
            'branch_id' => $branch->id,
            'is_available' => false,
            'unavailable_reason' => 'stock_rupture',
        ];
    }
    public function test_availability_toggle_covers_extra_variation_and_branch_show(): void
    {
        // [BRAIN-SUPERVISOR] L'OR-gate couvre les 3 routes sœurs, pas seulement toggle.
        $branch = Branch::factory()->create();
        $user = User::factory()->create(['branch_id' => $branch->id]);
        $user->givePermissionTo('availability_toggle');

        // 403 interdits ; toute autre réponse (422 payload, 200) prouve que le gate passe.
        $this->actingAs($user, 'sanctum')
            ->postJson('/api/admin/menu/availability/extra/toggle', [])
            ->assertStatus(422);
        $this->actingAs($user, 'sanctum')
            ->postJson('/api/admin/menu/availability/variation/toggle', [])
            ->assertStatus(422);
        $this->actingAs($user, 'sanctum')
            ->getJson('/api/admin/menu/availability/branch/'.$branch->id)
            ->assertOk();
    }
}
