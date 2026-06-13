<?php

namespace Tests\Feature\Ingredients;

use App\Enums\Status;
use App\Models\Item;
use App\Models\ItemAttribute;
use App\Models\ItemWizardProfile;
use App\Models\ItemWizardStep;
use App\Models\User;
use App\Services\Ingredients\IngredientService;
use Database\Seeders\IngredientPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [CENTRAL-RED-P2-02 2026-06-13] Drawer/count usage ingrédient sous-compté sur
 * le chemin ATTRIBUT/VARIATION — même classe de bug que T-R2.4 (D-B1-01) mais
 * côté attribut au lieu d'extra-group.
 *
 * Réalité prouvée sur foodking_e2e (attribut « Sauce bol » id 8) :
 *   - source_item_attribute_id = 8 OR source_ref = '8'  → 7 steps (matchés)
 *   - source_item_attribute_id NULL AND source_ref = 'sauce bol' (le NOM) → 4 steps (RATÉS)
 * Le drawer affichait 7 alors que l'usage réel est 11. Les wizard steps legacy
 * référencent l'attribut par son NOM (source_ref texte, FK NULL), jamais résolu
 * par usedByRowsForAttribute() / usageCountForAttribute() qui ne matchent que la
 * FK numérique ou le source_ref == id-numérique-stringifié.
 *
 * Heal : fallback by-name — quand la FK est NULL/0 et que source_ref == nom de
 * l'attribut (insensible à la casse), le step compte comme usage. La garde
 * FK-NULL empêche le double-comptage des steps déjà matchés par FK (la liste
 * groupe les attributs par nom et somme usageCountForAttribute sur chaque id du
 * groupe — un by-name sans garde compterait N fois).
 */
class IngredientUsageAttributeByNameTest extends TestCase
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

    private function makeStep(ItemWizardProfile $profile, array $overrides = []): ItemWizardStep
    {
        return ItemWizardStep::factory()->create(array_merge([
            'profile_id' => $profile->id,
            'step_key' => 'sauce',
            'label' => 'Choix sauce',
            'source_type' => 'item_attribute',
            'source_item_attribute_id' => null,
            'source_ref' => null,
        ], $overrides));
    }

    public function test_drawer_counts_attribute_referenced_by_name_when_fk_null(): void
    {
        $attribute = ItemAttribute::factory()->create(['name' => 'Sauce bol']);

        // One step via numeric FK (already worked).
        $itemFk = Item::factory()->create(['name' => 'Bol FK', 'status' => Status::ACTIVE]);
        $profileFk = ItemWizardProfile::factory()->create(['item_id' => $itemFk->id, 'item_category_id' => null]);
        $this->makeStep($profileFk, [
            'source_item_attribute_id' => $attribute->id,
            'source_ref' => (string) $attribute->id,
        ]);

        // One step that references the attribute BY NAME (FK NULL) — the legacy
        // shape proven in foodking_e2e. RED pre-heal: this row is invisible to
        // the drawer → count 1 instead of 2.
        $itemName = Item::factory()->create(['name' => 'Bol by-name', 'status' => Status::ACTIVE]);
        $profileName = ItemWizardProfile::factory()->create(['item_id' => $itemName->id, 'item_category_id' => null]);
        $this->makeStep($profileName, [
            'source_item_attribute_id' => null,
            'source_ref' => 'Sauce bol',
        ]);

        $globalId = IngredientService::globalId(IngredientService::TYPE_ATTRIBUTE, (int) $attribute->id);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/admin/ingredients/{$globalId}/usage")
            ->assertOk()
            ->assertJsonPath('data.used_by_count', 2);

        $names = array_column($response->json('data.used_by'), 'owner_name');
        sort($names);
        self::assertSame(['Bol FK', 'Bol by-name'], $names);
    }

    public function test_by_name_match_is_case_insensitive(): void
    {
        // foodking_e2e: attributs stockés « Sauce bol », steps « sauce bol ».
        $attribute = ItemAttribute::factory()->create(['name' => 'Sauce bol']);

        $item = Item::factory()->create(['name' => 'Bol lower', 'status' => Status::ACTIVE]);
        $profile = ItemWizardProfile::factory()->create(['item_id' => $item->id, 'item_category_id' => null]);
        $this->makeStep($profile, [
            'source_item_attribute_id' => null,
            'source_ref' => 'sauce bol', // lower-case mismatch vs stored "Sauce bol"
        ]);

        $globalId = IngredientService::globalId(IngredientService::TYPE_ATTRIBUTE, (int) $attribute->id);

        $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/admin/ingredients/{$globalId}/usage")
            ->assertOk()
            ->assertJsonPath('data.used_by_count', 1)
            ->assertJsonPath('data.used_by.0.owner_name', 'Bol lower');
    }

    public function test_list_count_matches_drawer_for_by_name_attribute_usage(): void
    {
        $attribute = ItemAttribute::factory()->create(['name' => 'Viande 1']);

        $item = Item::factory()->create(['name' => 'Tacos M', 'status' => Status::ACTIVE]);
        $profile = ItemWizardProfile::factory()->create(['item_id' => $item->id, 'item_category_id' => null]);
        $this->makeStep($profile, [
            'step_key' => 'viande',
            'source_item_attribute_id' => null,
            'source_ref' => 'Viande 1',
        ]);

        $service = app(IngredientService::class);

        // List side (used_by_count via usageCountForAttribute).
        $listRow = $service->listByType(IngredientService::TYPE_ATTRIBUTE)
            ->firstWhere('name', 'Viande 1');
        self::assertNotNull($listRow);
        self::assertSame(1, $listRow['used_by_count']);

        // Drawer side must agree (parity).
        $details = $service->usageDetailsForGlobalId((string) $listRow['global_id']);
        self::assertNotNull($details);
        self::assertCount(1, $details['used_by']);
    }

    public function test_by_name_fallback_does_not_double_count_fk_step(): void
    {
        // A step that has BOTH the FK and a name source_ref must be counted once,
        // not twice (FK match + name match). The FK-NULL guard prevents that.
        $attribute = ItemAttribute::factory()->create(['name' => 'Sauce bol']);

        $item = Item::factory()->create(['name' => 'Bol both', 'status' => Status::ACTIVE]);
        $profile = ItemWizardProfile::factory()->create(['item_id' => $item->id, 'item_category_id' => null]);
        $this->makeStep($profile, [
            'source_item_attribute_id' => $attribute->id,
            'source_ref' => 'Sauce bol', // name in source_ref AND FK set
        ]);

        $globalId = IngredientService::globalId(IngredientService::TYPE_ATTRIBUTE, (int) $attribute->id);

        $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/admin/ingredients/{$globalId}/usage")
            ->assertOk()
            ->assertJsonPath('data.used_by_count', 1);
    }

    public function test_by_name_fallback_does_not_match_other_attribute_names(): void
    {
        // Regression: a by-name step for "Sauce bol" must not leak into the usage
        // of a different attribute "Style frites".
        $sauce = ItemAttribute::factory()->create(['name' => 'Sauce bol']);
        $style = ItemAttribute::factory()->create(['name' => 'Style frites']);

        $item = Item::factory()->create(['name' => 'Bol sauce-only', 'status' => Status::ACTIVE]);
        $profile = ItemWizardProfile::factory()->create(['item_id' => $item->id, 'item_category_id' => null]);
        $this->makeStep($profile, [
            'source_item_attribute_id' => null,
            'source_ref' => 'Sauce bol',
        ]);

        $globalId = IngredientService::globalId(IngredientService::TYPE_ATTRIBUTE, (int) $style->id);

        $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/admin/ingredients/{$globalId}/usage")
            ->assertOk()
            ->assertJsonPath('data.used_by_count', 0);
    }
}
