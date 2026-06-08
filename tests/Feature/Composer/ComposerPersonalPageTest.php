<?php

namespace Tests\Feature\Composer;

use App\Enums\Status;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\ItemExtra;
use App\Models\ItemWizardProfile;
use App\Models\ItemWizardStep;
use App\Models\User;
use App\Services\Composer\ComposerProfileProjection;
use Database\Seeders\ComposerPermissionsMinimalSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * [GOAL_WIZARD_DYNAMIC_BUILDER Wave 5] "Add a personal/free page" = create a catalog
 * construct (ItemExtra group) on the fly + bind one extra_group step, atomically.
 * For a CATEGORY profile the construct is replicated onto every category item so the
 * page actually renders on each sibling (projection reads each item's own extras).
 * Price lives on the construct (NF525 SSOT) — never on the wizard step.
 */
class ComposerPersonalPageTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();
        Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'sanctum']);
        $this->seed(ComposerPermissionsMinimalSeeder::class);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('Admin');
    }

    private function categoryProfile(int $items = 2): array
    {
        $category = ItemCategory::factory()->create(['status' => Status::ACTIVE]);
        $made = collect(range(1, $items))->map(fn () => Item::factory()->create([
            'item_category_id' => $category->id, 'status' => Status::ACTIVE,
        ]));
        $profile = ItemWizardProfile::factory()->forCategory($category)->create();

        return [$category, $made, $profile];
    }

    public function test_category_personal_page_replicates_construct_across_items_and_binds_step(): void
    {
        [$category, $items, $profile] = $this->categoryProfile(2);

        $resp = $this->actingAs($this->admin, 'sanctum')->postJson(
            "/api/admin/composer/profiles/{$profile->id}/personal-page",
            [
                'label' => 'Suppléments Maison',
                'options' => [
                    ['name' => 'Cheddar maison', 'price' => '1.50', 'description' => 'Fondant'],
                    ['name' => 'Oeuf', 'price' => '0'],
                ],
                'min_select' => 0,
                'max_select' => 2,
                'visible_on' => ['pos', 'kiosk'],
            ]
        );

        $resp->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.items_touched', 2)
            ->assertJsonPath('data.options_created', 2);

        // Construct replicated onto BOTH items, price ON the construct.
        foreach ($items as $item) {
            $cheddar = ItemExtra::query()->where('item_id', $item->id)->where('name', 'Cheddar maison')->first();
            $this->assertNotNull($cheddar, 'option replicated to sibling');
            $this->assertSame('Suppléments Maison', $cheddar->group_label);
            $this->assertEquals(1.5, (float) $cheddar->price);
            $this->assertSame('Fondant', $cheddar->description);
            $this->assertEquals(0.0, (float) ItemExtra::query()->where('item_id', $item->id)->where('name', 'Oeuf')->value('price'));
        }

        // One bound extra_group step; NO price on the step (NF525).
        $steps = ItemWizardStep::query()->where('profile_id', $profile->id)->where('source_type', 'extra_group')->get();
        $this->assertCount(1, $steps);
        $this->assertSame('Suppléments Maison', $steps->first()->source_ref);

        // Renders on a sibling via the projection (inheritance path), price-free.
        $item = $items->last()->fresh(['variations.itemAttribute', 'extras', 'addons.addonItem']);
        $projected = app(ComposerProfileProjection::class)->project($profile->fresh('steps'), $item, 'kiosk');
        $page = collect($projected['steps'])->firstWhere('source_ref', 'Suppléments Maison');
        $this->assertNotNull($page, 'the personal page renders on the sibling');
        $this->assertEqualsCanonicalizing(['Cheddar maison', 'Oeuf'], collect($page['choices'])->pluck('name')->all());
        $this->assertArrayNotHasKey('price', $page['choices'][0]);
        $this->assertArrayNotHasKey('convert_price', $page['choices'][0]);
    }

    public function test_rejects_page_level_price_nf525(): void
    {
        [, , $profile] = $this->categoryProfile(1);

        $this->actingAs($this->admin, 'sanctum')->postJson(
            "/api/admin/composer/profiles/{$profile->id}/personal-page",
            ['label' => 'X', 'options' => [['name' => 'Y', 'price' => '1.00']], 'price' => '5.00']
        )->assertStatus(422)->assertJsonValidationErrors(['price']);
    }

    public function test_rejects_xss_image_path_in_option(): void
    {
        [, , $profile] = $this->categoryProfile(1);

        $this->actingAs($this->admin, 'sanctum')->postJson(
            "/api/admin/composer/profiles/{$profile->id}/personal-page",
            ['label' => 'X', 'options' => [['name' => 'Y', 'price' => '0', 'image_path' => 'x.png" onerror="alert(1)']]]
        )->assertStatus(422)->assertJsonValidationErrors(['options.0.image_path']);
    }

    public function test_requires_compose_permission(): void
    {
        [, , $profile] = $this->categoryProfile(1);
        $noPerm = User::factory()->create();

        $this->actingAs($noPerm, 'sanctum')->postJson(
            "/api/admin/composer/profiles/{$profile->id}/personal-page",
            ['label' => 'X', 'options' => [['name' => 'Y', 'price' => '0']]]
        )->assertStatus(403);
    }
}
