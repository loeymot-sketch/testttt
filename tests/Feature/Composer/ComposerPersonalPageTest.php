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
     * [CPC-01 regression 2026-06-11] A label that differs from an existing catalog group ONLY by
     * accents/case must ALSO be rejected — the guard folds accents (Str::ascii) so it is a SUPERSET
     * of MySQL utf8mb4_unicode_ci. Before the heal, "Supplément" passed the accent-SENSITIVE guard
     * yet the accent-INSENSITIVE removal sweep then soft-deleted the real "supplement" group's
     * options (proven end-to-end 9/9). The existing options MUST remain intact.
     */
    public function test_rejects_accent_variant_label_and_preserves_real_group(): void
    {
        [, $items, $profile] = $this->categoryProfile(1);
        $itemId = $items->first()->id;

        foreach (['Cheddar', 'Raclette', 'Emmental'] as $name) {
            ItemExtra::create([
                'item_id' => $itemId,
                'name' => $name,
                'group_label' => 'supplement', // unaccented singular = the real catalog key
                'price' => 0.90,
                'status' => Status::ACTIVE,
                'visible_on' => ['pos', 'kiosk'],
            ]);
        }

        // Accent + case variant of the existing key → must be blocked (guard ⊇ DB collation).
        foreach (['Supplément', 'SUPPLÉMENT', 'supplément'] as $variant) {
            $this->actingAs($this->admin, 'sanctum')->postJson(
                "/api/admin/composer/profiles/{$profile->id}/personal-page",
                ['label' => $variant, 'options' => [['name' => 'Mon option', 'price' => '1.50']]]
            )->assertStatus(422);
        }

        // The 3 real catalog options must be untouched (none soft-deleted, prices intact).
        $this->assertSame(3, ItemExtra::query()->where('item_id', $itemId)
            ->where('group_label', 'supplement')->whereNull('deleted_at')->count(),
            'the real "supplement" group must survive an accent-variant collision attempt');

        // A genuinely different word (plural) folds to a distinct token → correctly ALLOWED.
        $this->actingAs($this->admin, 'sanctum')->postJson(
            "/api/admin/composer/profiles/{$profile->id}/personal-page",
            ['label' => 'Suppléments Maison', 'options' => [['name' => 'Spécial', 'price' => '2.00']]]
        )->assertStatus(201);
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

    // ── W5 re-edit (option A: edit by server-trusted step PK) ─────────────────────────────────

    /** Create a personal page and return [profile, items, stepId, url]. */
    private function makePage(array $options, string $label = 'Sauces Maison', int $items = 1): array
    {
        [, $made, $profile] = $this->categoryProfile($items);
        $resp = $this->actingAs($this->admin, 'sanctum')->postJson(
            "/api/admin/composer/profiles/{$profile->id}/personal-page",
            ['label' => $label, 'options' => $options, 'min_select' => 0, 'max_select' => count($options)]
        )->assertStatus(201);

        return [$profile, $made, (int) $resp->json('data.step_id')];
    }

    /** [W5 re-edit] Editing by step-id updates prices, ADDS new options, REMOVES (soft-deletes) absent
     *  ones, and updates the step's display label + min/max. */
    public function test_reedit_updates_prices_adds_and_removes_options(): void
    {
        [$profile, $items, $stepId] = $this->makePage([
            ['name' => 'Algérienne', 'price' => '0.50'],
            ['name' => 'Blanche', 'price' => '0.50'],
        ], 'Sauces Maison', 2); // 2 category items → re-edit must replicate across both
        $item = $items->first();

        $this->actingAs($this->admin, 'sanctum')->putJson(
            "/api/admin/composer/profiles/{$profile->id}/personal-page/{$stepId}",
            [
                'label' => 'Sauces Maison V2',
                'options' => [
                    ['name' => 'Algérienne', 'price' => '1.20'],      // updated price
                    ['name' => 'Samouraï', 'price' => '0.80'],        // added
                ],                                                     // 'Blanche' omitted → removed
                'min_select' => 1, 'max_select' => 2,
            ]
        )->assertStatus(200)->assertJsonPath('data.items_touched', 2);

        // Re-edit replicates across ALL category items (update + add + remove on each).
        foreach ($items as $sibling) {
            $this->assertEquals(1.20, (float) ItemExtra::query()->where('item_id', $sibling->id)
                ->where('name', 'Algérienne')->where('group_label', 'Sauces Maison')->value('price'), 'price updated on each item');
            $this->assertSame(1, ItemExtra::query()->where('item_id', $sibling->id)
                ->where('name', 'Samouraï')->where('group_label', 'Sauces Maison')->count(), 'new option added on each item');
            $this->assertSame(0, ItemExtra::query()->where('item_id', $sibling->id)
                ->where('name', 'Blanche')->where('group_label', 'Sauces Maison')->count(), 'absent option soft-deleted on each item');
        }

        $step = ItemWizardStep::query()->find($stepId);
        $this->assertSame('Sauces Maison V2', $step->label, 'display label updated');
        $this->assertSame('Sauces Maison', $step->source_ref, 'group binding (source_ref) is immutable on re-edit');
        $this->assertSame(1, (int) $step->min_select);
        $this->assertSame(2, (int) $step->max_select);
    }

    /**
     * [W5 re-edit — THE safety property] Re-edit targets the STEP's own server-stored group
     * (source_ref), NEVER the body label. Even when the body label equals a DIFFERENT pre-existing
     * catalog group, that other group is untouched — collision-free by construction.
     */
    public function test_reedit_targets_steps_own_group_not_body_label(): void
    {
        [$profile, $items, $stepId] = $this->makePage([['name' => 'Algérienne', 'price' => '0.50']], 'Sauces Maison');
        $item = $items->first();

        // A DIFFERENT, pre-existing real catalog group "Sauces".
        ItemExtra::create([
            'item_id' => $item->id, 'name' => 'Ketchup', 'group_label' => 'Sauces',
            'price' => 0.80, 'status' => Status::ACTIVE, 'visible_on' => ['pos', 'kiosk'],
        ]);

        // Re-edit the "Sauces Maison" step but send body label="Sauces" (the OTHER group's name) +
        // an option named "Ketchup" @ 9.99. The edit must hit "Sauces Maison", NOT "Sauces".
        $this->actingAs($this->admin, 'sanctum')->putJson(
            "/api/admin/composer/profiles/{$profile->id}/personal-page/{$stepId}",
            ['label' => 'Sauces', 'options' => [['name' => 'Ketchup', 'price' => '9.99']]]
        )->assertStatus(200)->assertJsonPath('data.group_label', 'Sauces Maison');

        // The REAL "Sauces" group is completely untouched.
        $this->assertEquals(0.80, (float) ItemExtra::query()->where('item_id', $item->id)
            ->where('name', 'Ketchup')->where('group_label', 'Sauces')->value('price'),
            'the body label must NOT redirect the edit onto the real "Sauces" group');
        // The edit landed in "Sauces Maison": Ketchup@9.99 added there, Algérienne removed.
        $this->assertEquals(9.99, (float) ItemExtra::query()->where('item_id', $item->id)
            ->where('name', 'Ketchup')->where('group_label', 'Sauces Maison')->value('price'));
        $this->assertSame(0, ItemExtra::query()->where('item_id', $item->id)
            ->where('name', 'Algérienne')->where('group_label', 'Sauces Maison')->count());
    }

    /** [W5 re-edit] A step belonging to another profile cannot be edited through this profile (404). */
    public function test_reedit_404_for_step_of_other_profile(): void
    {
        [$profileA] = $this->makePage([['name' => 'A', 'price' => '0']], 'Page A');
        [$profileB, , $stepB] = $this->makePage([['name' => 'B', 'price' => '0']], 'Page B');

        $this->actingAs($this->admin, 'sanctum')->putJson(
            "/api/admin/composer/profiles/{$profileA->id}/personal-page/{$stepB}",
            ['label' => 'Page B', 'options' => [['name' => 'B', 'price' => '1.00']]]
        )->assertStatus(404);
    }

    /** [W5 re-edit] Only extra_group (option-page) steps are editable here. */
    public function test_reedit_422_for_non_extra_group_step(): void
    {
        [, , $profile] = $this->categoryProfile(1);
        $step = app(\App\Services\Composer\ComposerStepService::class)->create($profile, [
            'step_key' => 'taille', 'label' => 'Taille', 'source_type' => 'item_attribute',
            'source_ref' => '1', 'min_select' => 1, 'max_select' => 1, 'is_active' => true,
            'visible_on' => ['pos', 'kiosk'], 'position' => 1,
        ]);

        $this->actingAs($this->admin, 'sanctum')->putJson(
            "/api/admin/composer/profiles/{$profile->id}/personal-page/{$step->id}",
            ['label' => 'Taille', 'options' => [['name' => 'GM', 'price' => '0']]]
        )->assertStatus(422);
    }

    /** [W5 re-edit] NF525: a page-level price is prohibited on re-edit too. */
    public function test_reedit_rejects_page_level_price_nf525(): void
    {
        [$profile, , $stepId] = $this->makePage([['name' => 'X', 'price' => '0.50']], 'Page X');

        $this->actingAs($this->admin, 'sanctum')->putJson(
            "/api/admin/composer/profiles/{$profile->id}/personal-page/{$stepId}",
            ['label' => 'Page X', 'options' => [['name' => 'X', 'price' => '1.00']], 'price' => '5.00']
        )->assertStatus(422)->assertJsonValidationErrors(['price']);
    }

    /** [W5 re-edit] A non-admin Branch Manager must NOT re-edit a global/null-scope catalog. */
    public function test_reedit_branch_manager_cannot_mutate_global_profile(): void
    {
        [$profile, , $stepId] = $this->makePage([['name' => 'X', 'price' => '0.50']], 'Page X');
        Role::firstOrCreate(['name' => 'Branch Manager', 'guard_name' => 'sanctum']);
        $bm = User::factory()->create(['branch_id' => 2]);
        $bm->assignRole('Admin');
        $bm->syncRoles(['Branch Manager']);

        $this->actingAs($bm, 'sanctum')->putJson(
            "/api/admin/composer/profiles/{$profile->id}/personal-page/{$stepId}",
            ['label' => 'Page X', 'options' => [['name' => 'X', 'price' => '9.99']]]
        )->assertStatus(403);

        // Price untouched by the rejected write.
        $this->assertEquals(0.50, (float) ItemExtra::query()->where('group_label', 'Page X')
            ->where('name', 'X')->value('price'));
    }

    /** [W1 re-edit pre-fill] GET returns the bound group's options WITH price + the step's label/min/max
     *  so the builder modal opens pre-filled, then PUTs back to the same step. */
    public function test_show_personal_page_returns_editable_state_with_prices(): void
    {
        [$profile, , $stepId] = $this->makePage([
            ['name' => 'Algérienne', 'price' => '0.50', 'description' => 'Maison'],
            ['name' => 'Blanche', 'price' => '0'],
        ], 'Sauces Maison', 2);

        $resp = $this->actingAs($this->admin, 'sanctum')->getJson(
            "/api/admin/composer/profiles/{$profile->id}/personal-page/{$stepId}"
        )->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.step_id', $stepId)
            ->assertJsonPath('data.label', 'Sauces Maison')
            ->assertJsonPath('data.group_label', 'Sauces Maison');

        // Options carry price (admin edits the construct; this is NOT the price-free projection).
        $options = collect($resp->json('data.options'));
        $this->assertCount(2, $options);
        $this->assertEqualsCanonicalizing(['Algérienne', 'Blanche'], $options->pluck('name')->all());
        $this->assertEquals(0.50, (float) $options->firstWhere('name', 'Algérienne')['price']);
        $this->assertSame('Maison', $options->firstWhere('name', 'Algérienne')['description']);
    }

    /** [W1] GET pre-fill rejects a step of another profile (404) and a non-extra_group step (422). */
    public function test_show_personal_page_guards_step_ownership_and_type(): void
    {
        [$profileA, , $stepA] = $this->makePage([['name' => 'X', 'price' => '0.50']], 'Page A');
        [$profileB] = $this->makePage([['name' => 'Y', 'price' => '0.50']], 'Page B');

        // Step of profile A queried under profile B → 404.
        $this->actingAs($this->admin, 'sanctum')->getJson(
            "/api/admin/composer/profiles/{$profileB->id}/personal-page/{$stepA}"
        )->assertStatus(404);

        // A non-extra_group step → 422.
        $attrStep = ItemWizardStep::query()->create([
            'profile_id' => $profileA->id, 'step_key' => 'attr_x', 'label' => 'Attr',
            'source_type' => 'item_attribute', 'source_ref' => '7', 'min_select' => 0,
            'max_select' => 1, 'is_active' => true, 'visible_on' => ['pos', 'kiosk'], 'position' => 9,
        ]);
        $this->actingAs($this->admin, 'sanctum')->getJson(
            "/api/admin/composer/profiles/{$profileA->id}/personal-page/{$attrStep->id}"
        )->assertStatus(422);
    }

    /**
     * [W5 adversarial-finding lock] Re-edit must work on a CATALOG-TEMPLATE-origin extra_group step
     * (one NOT created via createPersonalPage), since the builder UI exposes "edit options" on every
     * extra_group step. Prior tests only exercised personal-page-origin steps (makePage always POSTs).
     * Proves: edits the step's own group + collision-safe (a DIFFERENT group is untouched).
     */
    public function test_reedit_works_on_catalog_template_origin_step_and_leaves_other_group_intact(): void
    {
        [, $items, $profile] = $this->categoryProfile(2);

        // Simulate a TEMPLATE-seeded catalog group "Crudités" + a DIFFERENT group "Sauces" — neither
        // created through createPersonalPage. The step below is bound like a template step would be.
        foreach ($items as $item) {
            ItemExtra::create(['item_id' => $item->id, 'name' => 'Salade', 'group_label' => 'Crudités', 'price' => 0, 'status' => Status::ACTIVE, 'visible_on' => ['pos', 'kiosk']]);
            ItemExtra::create(['item_id' => $item->id, 'name' => 'Tomate', 'group_label' => 'Crudités', 'price' => 0, 'status' => Status::ACTIVE, 'visible_on' => ['pos', 'kiosk']]);
            ItemExtra::create(['item_id' => $item->id, 'name' => 'Ketchup', 'group_label' => 'Sauces', 'price' => 0.5, 'status' => Status::ACTIVE, 'visible_on' => ['pos', 'kiosk']]);
        }
        $step = ItemWizardStep::query()->create([
            'profile_id' => $profile->id, 'step_key' => 'crudites', 'label' => 'Crudités',
            'source_type' => 'extra_group', 'source_ref' => 'Crudités', 'min_select' => 0,
            'max_select' => 2, 'is_active' => true, 'visible_on' => ['pos', 'kiosk'], 'position' => 1,
        ]);

        // Re-edit the catalog-origin step: keep Salade, ADD Oignon, omit Tomate (→ removed).
        $this->actingAs($this->admin, 'sanctum')->putJson(
            "/api/admin/composer/profiles/{$profile->id}/personal-page/{$step->id}",
            ['label' => 'Crudités V2', 'options' => [
                ['name' => 'Salade', 'price' => '0'],
                ['name' => 'Oignon', 'price' => '0'],
            ], 'min_select' => 1, 'max_select' => 2]
        )->assertStatus(200)->assertJsonPath('data.group_label', 'Crudités');

        foreach ($items as $item) {
            $this->assertSame(1, ItemExtra::query()->where('item_id', $item->id)->where('group_label', 'Crudités')->where('name', 'Oignon')->count(), 'added on each item');
            $this->assertSame(0, ItemExtra::query()->where('item_id', $item->id)->where('group_label', 'Crudités')->where('name', 'Tomate')->count(), 'Tomate removed on each item');
            // The OTHER group "Sauces" is completely untouched (collision-free by construction).
            $this->assertSame(1, ItemExtra::query()->where('item_id', $item->id)->where('group_label', 'Sauces')->where('name', 'Ketchup')->count(), 'Sauces group untouched');
            $this->assertEquals(0.5, (float) ItemExtra::query()->where('item_id', $item->id)->where('group_label', 'Sauces')->where('name', 'Ketchup')->value('price'), 'Sauces price untouched');
        }
        $fresh = ItemWizardStep::query()->find($step->id);
        $this->assertSame('Crudités V2', $fresh->label, 'display label updated');
        $this->assertSame('Crudités', $fresh->source_ref, 'group binding (source_ref) immutable on re-edit');
    }

    /**
     * [W5 adversarial-fix P1 lock] showPersonalPage must pre-fill the UNION of options across ALL
     * category items, not one representative. updatePersonalPage soft-deletes absent options across
     * every item; if a heterogeneous sibling's extra option were hidden from the modal it would be
     * silently destroyed on save. Union ⇒ every option is on-screen ⇒ no surprise deletion.
     */
    public function test_show_personal_page_unions_options_across_heterogeneous_siblings(): void
    {
        [, $items, $profile] = $this->categoryProfile(2);
        $a = $items->first();
        $b = $items->last();

        // Heterogeneous group "Supp": both have X; only A has Y; only B has Z.
        foreach ([$a, $b] as $it) {
            ItemExtra::create(['item_id' => $it->id, 'name' => 'X', 'group_label' => 'Supp', 'price' => 1, 'status' => Status::ACTIVE, 'visible_on' => ['pos', 'kiosk']]);
        }
        ItemExtra::create(['item_id' => $a->id, 'name' => 'Y', 'group_label' => 'Supp', 'price' => 2, 'status' => Status::ACTIVE, 'visible_on' => ['pos', 'kiosk']]);
        ItemExtra::create(['item_id' => $b->id, 'name' => 'Z', 'group_label' => 'Supp', 'price' => 3, 'status' => Status::ACTIVE, 'visible_on' => ['pos', 'kiosk']]);

        $step = ItemWizardStep::query()->create([
            'profile_id' => $profile->id, 'step_key' => 'supp', 'label' => 'Supp',
            'source_type' => 'extra_group', 'source_ref' => 'Supp', 'min_select' => 0,
            'max_select' => 3, 'is_active' => true, 'visible_on' => ['pos', 'kiosk'], 'position' => 1,
        ]);

        $resp = $this->actingAs($this->admin, 'sanctum')->getJson(
            "/api/admin/composer/profiles/{$profile->id}/personal-page/{$step->id}"
        )->assertStatus(200);

        $names = collect($resp->json('data.options'))->pluck('name')->all();
        // Without the union fix this would be only ['X','Y'] (item A) — and saving would soft-delete Z.
        $this->assertEqualsCanonicalizing(['X', 'Y', 'Z'], $names, 'pre-fill shows the UNION across heterogeneous siblings');
    }
}
