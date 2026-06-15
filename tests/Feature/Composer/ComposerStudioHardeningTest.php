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

    public function test_gap3_create_for_category_is_idempotent_on_double_submit(): void
    {
        $category = ItemCategory::factory()->create();
        $svc = app(ComposerProfileService::class);

        $first = $svc->createForCategory($category, ['template' => 'custom', 'branch_id_scope' => null, 'steps' => []]);
        $second = $svc->createForCategory($category, ['template' => 'custom', 'branch_id_scope' => null, 'steps' => []]);

        $this->assertSame($first->id, $second->id, 'second create must return the same profile, not a duplicate');
        $this->assertSame(
            1,
            ItemWizardProfile::query()->where('item_category_id', $category->id)->count(),
            'a second create must not orphan a duplicate profile row'
        );
        $this->assertDatabaseHas('item_categories', [
            'id' => $category->id,
            'wizard_profile_id' => $first->id, // pointer still anchored to the live profile
        ]);
    }
}
