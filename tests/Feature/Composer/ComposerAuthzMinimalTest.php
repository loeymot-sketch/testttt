<?php

namespace Tests\Feature\Composer;

use App\Models\Branch;
use App\Models\Item;
use App\Models\ItemWizardProfile;
use App\Models\ItemWizardStep;
use App\Models\User;
use Database\Seeders\ComposerPermissionsMinimalSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ComposerAuthzMinimalTest extends TestCase
{
    use RefreshDatabase;

    private Item $item;
    private Branch $branchA;
    private Branch $branchB;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('catalog_v15.features.wizard_per_item_demo.enabled', true);

        $this->seedMinimalSettings();
        $this->seedSpatieRoles();

        Role::firstOrCreate(['name' => 'Branch Admin', 'guard_name' => 'sanctum']);
        Role::firstOrCreate(['name' => 'Tenant Admin', 'guard_name' => 'sanctum']);
        Role::firstOrCreate(['name' => 'Delivery Boy', 'guard_name' => 'sanctum']);
        $this->seed(ComposerPermissionsMinimalSeeder::class);

        $this->branchA = Branch::factory()->create();
        $this->branchB = Branch::factory()->create();
        $this->item = Item::factory()->create();
    }

    public function test_branch_admin_can_compose_own_branch(): void
    {
        $user = User::factory()->create(['branch_id' => $this->branchA->id]);
        $user->assignRole('Branch Admin');

        $this->actingAs($user, 'sanctum')
            ->postJson($this->profileUrl(), $this->payload($this->branchA->id))
            ->assertSuccessful();
    }

    public function test_branch_admin_cannot_compose_other_branch(): void
    {
        $user = User::factory()->create(['branch_id' => $this->branchA->id]);
        $user->assignRole('Branch Admin');

        $this->actingAs($user, 'sanctum')
            ->postJson($this->profileUrl(), $this->payload($this->branchB->id))
            ->assertForbidden();
    }

    public function test_branch_admin_cannot_create_or_mutate_global_composer_profiles(): void
    {
        $user = User::factory()->create(['branch_id' => $this->branchA->id]);
        $user->assignRole('Branch Admin');

        $globalProfile = ItemWizardProfile::factory()->create([
            'item_id' => $this->item->id,
            'branch_id_scope' => null,
        ]);
        $globalStep = ItemWizardStep::factory()->create(['profile_id' => $globalProfile->id]);

        $this->actingAs($user, 'sanctum')
            ->postJson($this->profileUrl(), $this->payload(null))
            ->assertForbidden();

        $this->actingAs($user, 'sanctum')
            ->putJson($this->profileUpdateUrl($globalProfile->id), $this->payload($this->branchA->id))
            ->assertForbidden();

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/admin/composer/profiles/{$globalProfile->id}/publish")
            ->assertForbidden();

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/admin/composer/profiles/{$globalProfile->id}/unpublish")
            ->assertForbidden();

        $this->actingAs($user, 'sanctum')
            ->postJson($this->stepsUrlForProfile($globalProfile->id), $this->stepPayload())
            ->assertForbidden();

        $this->actingAs($user, 'sanctum')
            ->putJson($this->stepUrl($globalStep->id), $this->stepPayload('Global step changed', $globalStep->step_key))
            ->assertForbidden();

        $this->actingAs($user, 'sanctum')
            ->deleteJson($this->stepUrl($globalStep->id))
            ->assertForbidden();
    }

    public function test_branch_admin_cannot_update_foreign_profile_by_forging_payload_scope(): void
    {
        $user = User::factory()->create(['branch_id' => $this->branchA->id]);
        $user->assignRole('Branch Admin');

        $foreignProfile = ItemWizardProfile::factory()->create([
            'item_id' => $this->item->id,
            'branch_id_scope' => $this->branchB->id,
        ]);

        // [GOAL-2026-05-29] WizardProfileBranchScope (added eb0f191e2, 2026-05-18)
        // now HIDES the foreign-branch profile at route-model binding, so the
        // controller authz (403) is never reached — Laravel returns 404 first.
        // 404 (hide existence) is strictly MORE secure than 403 (confirm existence);
        // the forbidden mutation still does not land (adversarially probed: foreign
        // profile unchanged across all vectors). Assert the secure 404.
        $this->actingAs($user, 'sanctum')
            ->putJson($this->profileUpdateUrl($foreignProfile->id), $this->payload($this->branchA->id))
            ->assertNotFound();
    }

    public function test_show_defaults_to_actor_branch_and_does_not_leak_foreign_latest_profile(): void
    {
        $user = User::factory()->create(['branch_id' => $this->branchA->id]);
        $user->assignRole('Branch Admin');

        $ownProfile = ItemWizardProfile::factory()->create([
            'item_id' => $this->item->id,
            'branch_id_scope' => $this->branchA->id,
        ]);
        ItemWizardProfile::factory()->create([
            'item_id' => $this->item->id,
            'branch_id_scope' => $this->branchB->id,
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson($this->profileUrl())
            ->assertSuccessful()
            ->assertJsonPath('data.id', $ownProfile->id)
            ->assertJsonPath('data.branch_id_scope', $this->branchA->id);

        // [GOAL-2026-05-29] showForItem runs under WizardProfileBranchScope: the
        // foreign-branch profile is invisible and there is no global fallback in
        // this test, so the controller aborts 404 (no leak — the foreign profile is
        // NOT returned). 404 is the correct secure response (was 403 pre-scope).
        $this->actingAs($user, 'sanctum')
            ->getJson($this->profileUrl().'?branch_id_scope='.$this->branchB->id)
            ->assertNotFound();
    }

    public function test_branch_admin_can_show_global_item_profile_when_no_branch_scoped_profile_exists(): void
    {
        $user = User::factory()->create(['branch_id' => $this->branchA->id]);
        $user->assignRole('Branch Admin');

        $globalProfile = ItemWizardProfile::factory()->create([
            'item_id' => $this->item->id,
            'branch_id_scope' => null,
        ]);
        ItemWizardStep::factory()->create(['profile_id' => $globalProfile->id]);

        $this->actingAs($user, 'sanctum')
            ->getJson($this->profileUrl())
            ->assertSuccessful()
            ->assertJsonPath('data.id', $globalProfile->id)
            ->assertJsonPath('data.branch_id_scope', null);
    }

    public function test_tenant_admin_show_defaults_to_global_profile_not_branch_latest(): void
    {
        $user = User::factory()->create(['branch_id' => $this->branchA->id]);
        $user->assignRole('Tenant Admin');

        $globalProfile = ItemWizardProfile::factory()->create([
            'item_id' => $this->item->id,
            'branch_id_scope' => null,
        ]);
        ItemWizardProfile::factory()->create([
            'item_id' => $this->item->id,
            'branch_id_scope' => $this->branchB->id,
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson($this->profileUrl())
            ->assertSuccessful()
            ->assertJsonPath('data.id', $globalProfile->id)
            ->assertJsonPath('data.branch_id_scope', null);
    }

    public function test_tenant_admin_can_compose_any_branch(): void
    {
        $user = User::factory()->create(['branch_id' => $this->branchA->id]);
        $user->assignRole('Tenant Admin');

        $this->actingAs($user, 'sanctum')
            ->postJson($this->profileUrl(), $this->payload($this->branchB->id))
            ->assertSuccessful();
    }

    public function test_tenant_admin_can_create_global_composer_profile(): void
    {
        $user = User::factory()->create(['branch_id' => $this->branchA->id]);
        $user->assignRole('Tenant Admin');

        $this->actingAs($user, 'sanctum')
            ->postJson($this->profileUrl(), $this->payload(null))
            ->assertSuccessful()
            ->assertJsonPath('data.branch_id_scope', null);
    }

    public function test_pos_operator_and_delivery_boy_are_forbidden(): void
    {
        $pos = User::factory()->create(['branch_id' => $this->branchA->id]);
        $pos->assignRole('POS Operator');
        $delivery = User::factory()->create(['branch_id' => $this->branchA->id]);
        $delivery->assignRole('Delivery Boy');

        $this->actingAs($pos, 'sanctum')
            ->postJson($this->profileUrl(), $this->payload($this->branchA->id))
            ->assertForbidden();

        $this->actingAs($delivery, 'sanctum')
            ->postJson($this->profileUrl(), $this->payload($this->branchA->id))
            ->assertForbidden();
    }

    public function test_branch_admin_cannot_mutate_composer_steps_for_other_branch(): void
    {
        $user = User::factory()->create(['branch_id' => $this->branchA->id]);
        $user->assignRole('Branch Admin');

        $foreignProfile = ItemWizardProfile::factory()->create([
            'item_id' => $this->item->id,
            'branch_id_scope' => $this->branchB->id,
        ]);
        $ownProfile = ItemWizardProfile::factory()->create([
            'item_id' => $this->item->id,
            'branch_id_scope' => $this->branchA->id,
        ]);

        $foreignStep = ItemWizardStep::factory()->create(['profile_id' => $foreignProfile->id]);
        $ownStep = ItemWizardStep::factory()->create(['profile_id' => $ownProfile->id]);

        // [GOAL-2026-05-29] POST to a foreign-branch {profile}'s steps: the profile
        // is hidden by WizardProfileBranchScope at route binding → 404 (secure;
        // the step is NOT created — adversarially probed: zero injected steps).
        $this->actingAs($user, 'sanctum')
            ->postJson($this->stepsUrlForProfile($foreignProfile->id), $this->stepPayload())
            ->assertNotFound();

        // PUT/DELETE on a foreign {step}: ItemWizardStep has NO branch scope, so it
        // binds and reaches the controller's authorizeWritableBranchScope → 403.
        // These stay assertForbidden — they prove controller-level authz executes
        // (the stronger guard; the foreign step remains unmutated per the probe).
        $this->actingAs($user, 'sanctum')
            ->putJson($this->stepUrl($foreignStep->id), $this->stepPayload('Crudités modifié', $foreignStep->step_key))
            ->assertForbidden();

        $this->actingAs($user, 'sanctum')
            ->deleteJson($this->stepUrl($foreignStep->id))
            ->assertForbidden();

        $this->actingAs($user, 'sanctum')
            ->postJson($this->stepsUrlForProfile($ownProfile->id), $this->stepPayload('Assiette'))
            ->assertSuccessful();

        $this->actingAs($user, 'sanctum')
            ->putJson($this->stepUrl($ownStep->id), $this->stepPayload('Crudités', $ownStep->step_key))
            ->assertSuccessful();
    }

    public function test_no_catalog_manager_role_is_created(): void
    {
        $this->assertFalse(Role::query()->where('name', 'Catalog Manager')->exists());
    }

    private function profileUrl(): string
    {
        return "/api/admin/composer/items/{$this->item->id}/profile";
    }

    private function stepsUrlForProfile(int $profileId): string
    {
        return "/api/admin/composer/profiles/{$profileId}/steps";
    }

    private function profileUpdateUrl(int $profileId): string
    {
        return "/api/admin/composer/profiles/{$profileId}";
    }

    private function stepUrl(int $stepId): string
    {
        return "/api/admin/composer/steps/{$stepId}";
    }

    private function payload(?int $branchId): array
    {
        return [
            'template' => 'custom',
            'branch_id_scope' => $branchId,
            'steps' => [
                [
                    'step_key' => 'base',
                    'label' => 'Base',
                    'source_type' => 'item_attribute',
                    'min_select' => 0,
                    'max_select' => 1,
                    'position' => 0,
                ],
            ],
        ];
    }

    private function stepPayload(string $label = 'Crudités', ?string $stepKey = null): array
    {
        return [
            'step_key' => $stepKey ?? 'extras',
            'label' => $label,
            'source_type' => 'item_attribute',
            'min_select' => 0,
            'max_select' => 2,
            'position' => 1,
        ];
    }
}
