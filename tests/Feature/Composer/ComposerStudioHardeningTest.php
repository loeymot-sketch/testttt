<?php

namespace Tests\Feature\Composer;

use App\Events\ComposerProfileChanged;
use App\Models\Branch;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\ItemWizardProfile;
use App\Models\User;
use App\Services\Composer\ComposerProfileService;
use Database\Seeders\ComposerPermissionsMinimalSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Adversarial-audit heals for the Wizard Studio (2026-06-15):
 *   - SEC-01 (P2): mutating an already-PUBLISHED profile broadcasts live to every borne,
 *     so it must require catalog.publish — not be reachable with catalog.compose alone.
 *   - GAP-3 (P2): createForCategory must be idempotent so a double-submit cannot orphan a
 *     duplicate profile + repoint category.wizard_profile_id away from what the borne renders.
 */
class ComposerStudioHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();
        $this->seedSpatieRoles();
        $this->seed(ComposerPermissionsMinimalSeeder::class);
    }

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'template' => 'custom',
            'branch_id_scope' => 1,
            'steps' => [],
            'version' => 1,
        ], $overrides);
    }

    public function test_sec01_compose_only_user_cannot_mutate_a_published_profile(): void
    {
        Event::fake([ComposerProfileChanged::class]); // isolate from sync listeners
        Branch::factory()->create(['id' => 1]);

        // A principal with catalog.compose but NOT catalog.publish, scoped to branch 1.
        $composeOnly = Role::firstOrCreate(['name' => 'ComposeOnly', 'guard_name' => 'sanctum']);
        $composeOnly->givePermissionTo(Permission::findByName('catalog.compose', 'sanctum'));
        $user = User::factory()->create(['branch_id' => 1]);
        $user->assignRole('ComposeOnly');

        $published = ItemWizardProfile::factory()->create([
            'item_id' => null,
            'item_category_id' => ItemCategory::factory(),
            'is_published' => true,
            'branch_id_scope' => 1,
            'version' => 1,
        ]);

        $this->actingAs($user, 'sanctum')
            ->putJson("/api/admin/composer/profiles/{$published->id}", $this->validPayload())
            ->assertForbidden(); // SEC-01 gate
    }

    public function test_sec01_compose_only_user_may_still_edit_a_draft(): void
    {
        Branch::factory()->create(['id' => 1]);

        $composeOnly = Role::firstOrCreate(['name' => 'ComposeOnly', 'guard_name' => 'sanctum']);
        $composeOnly->givePermissionTo(Permission::findByName('catalog.compose', 'sanctum'));
        $user = User::factory()->create(['branch_id' => 1]);
        $user->assignRole('ComposeOnly');

        $draft = ItemWizardProfile::factory()->create([
            'item_id' => null,
            'item_category_id' => ItemCategory::factory(),
            'is_published' => false,
            'branch_id_scope' => 1,
            'version' => 1,
        ]);

        $this->actingAs($user, 'sanctum')
            ->putJson("/api/admin/composer/profiles/{$draft->id}", $this->validPayload())
            ->assertSuccessful(); // drafts stay a compose-level action (not over-restricted)
    }

    public function test_sec01_publish_capable_user_may_mutate_a_published_profile(): void
    {
        Event::fake([ComposerProfileChanged::class]);
        Branch::factory()->create(['id' => 1]);

        // Branch Manager holds both catalog.compose AND catalog.publish (seeder), scoped to branch 1.
        $user = User::factory()->create(['branch_id' => 1]);
        $user->assignRole('Branch Manager');

        $published = ItemWizardProfile::factory()->create([
            'item_id' => null,
            'item_category_id' => ItemCategory::factory(),
            'is_published' => true,
            'branch_id_scope' => 1,
            'version' => 1,
        ]);

        $this->actingAs($user, 'sanctum')
            ->putJson("/api/admin/composer/profiles/{$published->id}", $this->validPayload())
            ->assertSuccessful();
    }

    public function test_wspub_publish_honours_the_optimistic_version_lock(): void
    {
        // [WS-PUB-VERSION] The Studio sends {version} on publish; the controller now forwards it so
        // publishing over a concurrent edit 409s (assertVersionMatches runs before assertPublishable).
        Event::fake([ComposerProfileChanged::class]);
        Branch::factory()->create(['id' => 1]);
        $user = User::factory()->create(['branch_id' => 1]);
        $user->assignRole('Branch Manager'); // holds catalog.publish

        $draft = ItemWizardProfile::factory()->create([
            'item_id' => null,
            'item_category_id' => ItemCategory::factory(),
            'is_published' => false,
            'branch_id_scope' => 1,
            'version' => 3,
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/admin/composer/profiles/{$draft->id}/publish", ['version' => 1]) // stale
            ->assertStatus(409);
        $this->assertFalse((bool) $draft->fresh()->is_published, 'a stale-version publish must not take the wizard live');
    }

    public function test_gap3_idempotent_create_returns_same_profile_on_double_submit(): void
    {
        $category = ItemCategory::factory()->create();
        $svc = app(ComposerProfileService::class);

        // The Studio CTA path passes idempotent=true (storeForCategory).
        $first = $svc->createForCategory($category, ['template' => 'custom', 'branch_id_scope' => null, 'steps' => []], true);
        $second = $svc->createForCategory($category, ['template' => 'custom', 'branch_id_scope' => null, 'steps' => []], true);

        $this->assertSame($first->id, $second->id, 'second idempotent create must return the same profile, not a duplicate');
        $this->assertSame(
            1,
            ItemWizardProfile::query()->where('item_category_id', $category->id)->count(),
            'a second idempotent create must not orphan a duplicate profile row'
        );
        $this->assertDatabaseHas('item_categories', [
            'id' => $category->id,
            'wizard_profile_id' => $first->id, // pointer still anchored to the live profile
        ]);
    }

    public function test_gap3_non_idempotent_create_still_applies_steps_when_a_profile_exists(): void
    {
        // Regression guard: apply-template uses the DEFAULT (non-idempotent) path. The idempotency
        // fix must NOT swallow the template's steps when the category already has a profile.
        $category = ItemCategory::factory()->create();
        $svc = app(ComposerProfileService::class);

        $svc->createForCategory($category, ['template' => 'custom', 'branch_id_scope' => null, 'steps' => []], true); // pre-existing draft

        $withSteps = $svc->createForCategory($category, [
            'template' => 'tacos',
            'branch_id_scope' => null,
            'steps' => [
                ['step_key' => 'base', 'label' => 'Base', 'source_type' => 'item_attribute', 'source_ref' => 'Base'],
                ['step_key' => 'sauce', 'label' => 'Sauce', 'source_type' => 'extra_group', 'source_ref' => 'sauce'],
            ],
        ]); // default $idempotent=false → applyTemplate behaviour

        $this->assertSame(2, $withSteps->steps()->count(), 'template steps must be applied, not silently discarded');
        $this->assertSame('tacos', $withSteps->template);
    }

    public function test_sec01_sibling_compose_only_user_cannot_add_step_to_published_profile(): void
    {
        Event::fake([ComposerProfileChanged::class]);
        Branch::factory()->create(['id' => 1]);

        $composeOnly = Role::firstOrCreate(['name' => 'ComposeOnly', 'guard_name' => 'sanctum']);
        $composeOnly->givePermissionTo(Permission::findByName('catalog.compose', 'sanctum'));
        $user = User::factory()->create(['branch_id' => 1]);
        $user->assignRole('ComposeOnly');

        $published = ItemWizardProfile::factory()->create([
            'item_id' => null,
            'item_category_id' => ItemCategory::factory(),
            'is_published' => true,
            'branch_id_scope' => 1,
            'version' => 1,
        ]);

        // The step endpoints broadcast live exactly like update() — must require catalog.publish.
        $this->actingAs($user, 'sanctum')
            ->postJson("/api/admin/composer/profiles/{$published->id}/steps", [
                'step_key' => 'sauce', 'label' => 'Sauce', 'source_type' => 'extra_group', 'source_ref' => 'sauce',
            ])
            ->assertForbidden();
    }

    public function test_sec01_sibling_compose_only_user_may_add_step_to_a_draft(): void
    {
        Branch::factory()->create(['id' => 1]);

        $composeOnly = Role::firstOrCreate(['name' => 'ComposeOnly', 'guard_name' => 'sanctum']);
        $composeOnly->givePermissionTo(Permission::findByName('catalog.compose', 'sanctum'));
        $user = User::factory()->create(['branch_id' => 1]);
        $user->assignRole('ComposeOnly');

        $draft = ItemWizardProfile::factory()->create([
            'item_id' => null,
            'item_category_id' => ItemCategory::factory(),
            'is_published' => false,
            'branch_id_scope' => 1,
            'version' => 1,
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/admin/composer/profiles/{$draft->id}/steps", [
                'step_key' => 'sauce', 'label' => 'Sauce', 'source_type' => 'extra_group', 'source_ref' => 'sauce',
            ])
            ->assertSuccessful(); // editing a draft's steps stays compose-level
    }

    public function test_sec01_sibling_publish_capable_user_may_add_step_to_published_profile(): void
    {
        Event::fake([ComposerProfileChanged::class]);
        Branch::factory()->create(['id' => 1]);

        $user = User::factory()->create(['branch_id' => 1]);
        $user->assignRole('Branch Manager'); // holds catalog.publish

        $published = ItemWizardProfile::factory()->create([
            'item_id' => null,
            'item_category_id' => ItemCategory::factory(),
            'is_published' => true,
            'branch_id_scope' => 1,
            'version' => 1,
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/admin/composer/profiles/{$published->id}/steps", [
                'step_key' => 'sauce', 'label' => 'Sauce', 'source_type' => 'extra_group', 'source_ref' => 'sauce',
            ])
            ->assertSuccessful();
    }
}
