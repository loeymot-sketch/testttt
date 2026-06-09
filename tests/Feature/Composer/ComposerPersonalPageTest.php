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

    /**
     * [W7 audit P1] A bare label that slugs to a kiosk STEP_KEY_REGISTRY key ("Sauce" -> 'sauce')
     * would route the page to a FROZEN specialized component that ignores its options. The
     * generated step_key must escape the registry so the page reaches the generic component.
     */
    public function test_step_key_never_collides_with_frozen_kiosk_registry(): void
    {
        [, , $profile] = $this->categoryProfile(1);

        foreach (['Sauce', 'Menu', 'Suppléments', 'Garnitures', 'Boisson'] as $colliding) {
            $resp = $this->actingAs($this->admin, 'sanctum')->postJson(
                "/api/admin/composer/profiles/{$profile->id}/personal-page",
                ['label' => $colliding, 'options' => [['name' => 'Opt', 'price' => '0']]]
            )->assertStatus(201);

            $key = $resp->json('data.step_key');
            $this->assertNotContains(
                $key,
                \App\Http\Controllers\Admin\ComposerProfileController::RESERVED_KIOSK_STEP_KEYS,
                "step_key '{$key}' for label '{$colliding}' must not be a reserved kiosk key"
            );
        }
    }

    /** [W7 audit P2] A mutating write must be write-tier guarded: a non-admin Branch Manager
     *  (holds catalog.compose) must NOT mutate a global/null-scope catalog. */
    public function test_branch_manager_cannot_mutate_global_profile(): void
    {
        [, , $profile] = $this->categoryProfile(1); // forCategory => branch_id_scope null (global)
        Role::firstOrCreate(['name' => 'Branch Manager', 'guard_name' => 'sanctum']);
        $bm = User::factory()->create(['branch_id' => 2]);
        $bm->assignRole('Admin'); // ensure compose perm exists in test, then swap to BM-only
        $bm->syncRoles(['Branch Manager']);

        $this->actingAs($bm, 'sanctum')->postJson(
            "/api/admin/composer/profiles/{$profile->id}/personal-page",
            ['label' => 'Maison', 'options' => [['name' => 'Opt', 'price' => '0']]]
        )->assertStatus(403);
    }

    /**
     * [W5 conservative guard] Create-only: re-POSTing the SAME label is REJECTED (the group now
     * exists). The original options are untouched (no in-place overwrite). This is the robust-by-
     * construction contract that replaced the unsound idempotent-re-edit ownership exception
     * (3 adversarial rounds proved every provenance-marker variant overwrite-able). In-builder
     * re-edit restoration is an owner design decision (WIZARD_W5_PERSONAL_PAGE_REEDIT_DESIGN_GATE.md).
     */
    public function test_resubmit_same_label_rejected_and_original_preserved(): void
    {
        [, $items, $profile] = $this->categoryProfile(1);
        $url = "/api/admin/composer/profiles/{$profile->id}/personal-page";

        $this->actingAs($this->admin, 'sanctum')->postJson($url, [
            'label' => 'Sauces Maison',
            'options' => [['name' => 'Algérienne', 'price' => '0.50']],
        ])->assertStatus(201);

        // Re-submit with a different price for the same label → rejected, original kept.
        $this->actingAs($this->admin, 'sanctum')->postJson($url, [
            'label' => 'Sauces Maison',
            'options' => [['name' => 'Algérienne', 'price' => '1.50']],
        ])->assertStatus(422);

        $this->assertSame(1, ItemWizardStep::query()->where('profile_id', $profile->id)
            ->where('source_ref', 'Sauces Maison')->count(), 'rejected re-submit must not duplicate the step');
        $this->assertEquals(0.5, (float) ItemExtra::query()->where('item_id', $items->first()->id)
            ->where('name', 'Algérienne')->value('price'), 'rejected re-submit must NOT overwrite the original price');
    }

    /**
     * [W5 conservative guard] A label that matches an EXISTING catalog group_label is rejected —
     * the builder can only create NEW groups, never overwrite a real catalog extra group's prices.
     */
    public function test_rejects_label_colliding_with_existing_catalog_group(): void
    {
        [, $items, $profile] = $this->categoryProfile(2);

        ItemExtra::create([
            'item_id' => $items->first()->id,
            'name' => 'Algérienne',
            'group_label' => 'Sauces',
            'price' => 0.80,
            'status' => Status::ACTIVE,
            'visible_on' => ['pos', 'kiosk'],
        ]);

        $this->actingAs($this->admin, 'sanctum')->postJson(
            "/api/admin/composer/profiles/{$profile->id}/personal-page",
            ['label' => 'Sauces', 'options' => [['name' => 'Blanche', 'price' => '0']]]
        )->assertStatus(422);

        $this->assertEquals(0.80, (float) ItemExtra::query()->where('item_id', $items->first()->id)
            ->where('name', 'Algérienne')->value('price'), 'existing catalog price must be preserved');
        $this->assertSame(0, ItemExtra::query()->where('group_label', 'Sauces')->where('name', 'Blanche')->count(),
            'collision must not inject the personal-page option into the existing group');
    }

    /**
     * [W5 conservative guard — adversarial regression] The collision guard must reject a colliding
     * label REGARDLESS of whether a normal composed step is already bound to that group (the earlier
     * provenance-marker guard was disarmable by such a step; the conservative guard keys only on the
     * existence of the catalog group, so it cannot be disarmed).
     */
    public function test_collision_guard_holds_even_when_a_normal_step_is_bound(): void
    {
        [, $items, $profile] = $this->categoryProfile(2);

        ItemExtra::create([
            'item_id' => $items->first()->id,
            'name' => 'Ketchup',
            'group_label' => 'Sauces',
            'price' => 0.80,
            'status' => Status::ACTIVE,
            'visible_on' => ['pos', 'kiosk'],
        ]);

        // A normal composed step bound to that real group.
        app(\App\Services\Composer\ComposerStepService::class)->create($profile, [
            'step_key' => 'sauces_catalog',
            'label' => 'Sauces',
            'source_type' => 'extra_group',
            'source_ref' => 'Sauces',
            'min_select' => 0,
            'max_select' => 2,
            'is_active' => true,
            'visible_on' => ['pos', 'kiosk'],
            'position' => 1,
        ]);

        $this->actingAs($this->admin, 'sanctum')->postJson(
            "/api/admin/composer/profiles/{$profile->id}/personal-page",
            ['label' => 'Sauces', 'options' => [['name' => 'Ketchup', 'price' => '9.99'], ['name' => 'Blanche', 'price' => '0']]]
        )->assertStatus(422);

        $this->assertEquals(0.80, (float) ItemExtra::query()->where('item_id', $items->first()->id)
            ->where('name', 'Ketchup')->value('price'), 'catalog price must be preserved despite the bound step');
        $this->assertSame(0, ItemExtra::query()->where('group_label', 'Sauces')->where('name', 'Blanche')->count(),
            'guard must not let the personal page inject into the real group');
    }

    /**
     * [W5 conservative guard — case-folding parity] The collision guard must fold case with the SAME
     * algorithm the kiosk projection uses (mb_strtolower), NOT the DB collation. A SQL
     * `where('group_label', $label)` is case-sensitive on SQLite but the projection matches
     * group_label case-insensitively (mb_strtolower) — so a case-variant label ("sauces" vs an
     * existing catalog "Sauces") used to PASS the guard yet project a DUPLICATE kiosk step whose
     * render cross-contaminated the pre-existing group's options. The guard now compares in PHP so
     * guard ⊇ projection on any database: the case-variant is rejected and no duplicate page projects.
     */
    public function test_case_variant_of_existing_group_is_rejected_no_duplicate_projection(): void
    {
        [, $items, $profile] = $this->categoryProfile(1);
        $item = $items->first();

        ItemExtra::create([
            'item_id' => $item->id, 'name' => 'Ketchup', 'group_label' => 'Sauces',
            'price' => 0.80, 'status' => Status::ACTIVE, 'visible_on' => ['pos', 'kiosk'],
        ]);
        app(\App\Services\Composer\ComposerStepService::class)->create($profile, [
            'step_key' => 'sauces_catalog', 'label' => 'Sauces', 'source_type' => 'extra_group',
            'source_ref' => 'Sauces', 'min_select' => 0, 'max_select' => 2, 'is_active' => true,
            'visible_on' => ['pos', 'kiosk'], 'position' => 1,
        ]);

        $this->actingAs($this->admin, 'sanctum')->postJson(
            "/api/admin/composer/profiles/{$profile->id}/personal-page",
            ['label' => 'sauces', 'options' => [['name' => 'Mayo Maison', 'price' => '0']]]
        )->assertStatus(422);

        $fresh = $item->fresh(['variations.itemAttribute', 'extras', 'addons.addonItem']);
        $projected = app(ComposerProfileProjection::class)->project($profile->fresh('steps'), $fresh, 'kiosk');
        $sauceSteps = collect($projected['steps'])->filter(
            fn ($s) => mb_strtolower((string) $s['source_ref']) === 'sauces'
        );
        $this->assertSame(1, $sauceSteps->count(), 'a case-variant must not create a second projected step for the same group');
        $this->assertNotContains('Mayo Maison', collect($sauceSteps->first()['choices'])->pluck('name')->all(),
            'the personal-page option must not leak into the pre-existing catalog group render');
        $this->assertEquals(0.80, (float) ItemExtra::query()->where('item_id', $item->id)
            ->where('name', 'Ketchup')->value('price'), 'catalog price preserved');
    }
}
