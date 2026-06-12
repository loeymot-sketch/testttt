<?php

namespace Tests\Feature\Ingredients;

use App\Enums\Status;
use App\Models\Item;
use App\Models\ItemExtra;
use App\Models\User;
use App\Services\Ingredients\IngredientService;
use Database\Seeders\IngredientPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [W-REM T-R2.4 — D-B1-01 2026-06-12] Drawer ingrédients menteur.
 *
 * Finding D-B1-01 (CONFIRMÉ par refuter, repro API+UI) : la LISTE
 * ingrédients groupe les extras par name||group_label et affiche
 * used_by_count = nb de rows ItemExtra (« Utilisé dans 8 produit(s) »),
 * mais le DRAWER (usedByRowsForExtra) ne résout l'usage QUE via les wizard
 * steps source_type=extra_group matchés sur group_label → pour un extra
 * SANS group_label (48 rows legacy / 6 noms en e2e) le drawer répond
 * « Non utilisé » à 100 %. Donnée contradictoire dans la feature dont le
 * rôle est précisément de répondre « où est utilisé cet ingrédient » —
 * risque de suppression cassante.
 *
 * Heal : fallback par NOM — quand group_label est null, l'usage = les
 * items propriétaires des rows ItemExtra de même nom (group_label null),
 * en parité avec le used_by_count de la liste.
 */
class IngredientUsageDrawerParityTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedMinimalSettings();
        $this->seedSpatieRoles();
        $this->seed(IngredientPermissionSeeder::class);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('Admin');
    }

    private function makeExtra(Item $item, string $name): ItemExtra
    {
        return ItemExtra::query()->create([
            'item_id' => $item->id,
            'name' => $name,
            'price' => 1.00,
            'status' => Status::ACTIVE,
            'group_label' => null,
            'is_available' => true,
        ]);
    }

    public function test_extra_without_group_label_used_by_one_item_is_listed_in_drawer(): void
    {
        $item = Item::factory()->create(['name' => 'Le Cayenne Burger']);
        $extra = $this->makeExtra($item, 'Jambon de dinde');

        $globalId = IngredientService::globalId(IngredientService::TYPE_EXTRA, (int) $extra->id);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/admin/ingredients/{$globalId}/usage")
            ->assertOk();

        // RED pre-heal: used_by=[] / used_by_count=0 ("Non utilisé") while the
        // list says "Utilisé dans 1 produit(s)".
        $response->assertJsonPath('data.used_by_count', 1);
        $usedBy = $response->json('data.used_by');
        self::assertSame('item', $usedBy[0]['owner_type']);
        self::assertSame('Le Cayenne Burger', $usedBy[0]['owner_name']);
        self::assertSame($item->id, $usedBy[0]['owner_id']);
    }

    public function test_drawer_count_matches_list_count_for_groupless_extra(): void
    {
        $itemA = Item::factory()->create(['name' => 'Burger A']);
        $itemB = Item::factory()->create(['name' => 'Burger B']);
        $extraA = $this->makeExtra($itemA, 'Boursin');
        $this->makeExtra($itemB, 'Boursin');

        $service = app(IngredientService::class);

        // List side: one grouped row "Boursin||" with used_by_count=2.
        $listRow = $service->listByType(IngredientService::TYPE_EXTRA)
            ->firstWhere('name', 'Boursin');
        self::assertNotNull($listRow);
        self::assertSame(2, $listRow['used_by_count']);

        // Drawer side must agree (parity — the lie was 2 vs 0).
        $globalId = (string) $listRow['global_id'];
        $details = $service->usageDetailsForGlobalId($globalId);
        self::assertNotNull($details);
        self::assertCount(2, $details['used_by']);

        $names = array_column($details['used_by'], 'owner_name');
        sort($names);
        self::assertSame(['Burger A', 'Burger B'], $names);

        // Both rows are item-owned with a composer admin URL.
        foreach ($details['used_by'] as $row) {
            self::assertSame('item', $row['owner_type']);
            self::assertArrayHasKey('admin_url', $row);
        }

        // Representative id sanity (list groups by lowest id).
        self::assertSame((int) $extraA->id, (int) $listRow['id']);
    }

    public function test_grouped_extra_path_still_resolves_via_wizard_steps(): void
    {
        // Regression hold: the group_label path must stay wizard-step-driven
        // (existing behaviour locked by IngredientUsageDrillDownTest) — the
        // by-name fallback must NOT hijack extras that have a group_label.
        $item = Item::factory()->create(['name' => 'Tacos M']);
        $extra = ItemExtra::query()->create([
            'item_id' => $item->id,
            'name' => 'Sauce fromagère',
            'price' => 0.50,
            'status' => Status::ACTIVE,
            'group_label' => 'tacos.sauces',
            'is_available' => true,
        ]);

        $globalId = IngredientService::globalId(IngredientService::TYPE_EXTRA, (int) $extra->id);

        // No wizard step references 'tacos.sauces' → genuinely unused on the
        // wizard side; the by-name fallback must not fabricate item usage.
        $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/admin/ingredients/{$globalId}/usage")
            ->assertOk()
            ->assertJsonPath('data.used_by_count', 0);
    }
}
