<?php

namespace Tests\Feature\Composer;

use App\Events\ComposerProfileChanged;
use App\Events\ComposerProfilePublished;
use App\Models\Branch;
use App\Models\Item;
use App\Models\ItemWizardProfile;
use App\Models\ItemWizardStep;
use App\Models\User;
use Database\Seeders\ComposerPermissionsMinimalSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ComposerProfileApiTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;
    private User $branchAdmin;
    private Item $item;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('catalog_v15.features.wizard_per_item_demo.enabled', true);

        $this->seedMinimalSettings();
        $this->branch = Branch::factory()->create();
        $this->item = Item::factory()->create();

        Role::firstOrCreate(['name' => 'Branch Admin', 'guard_name' => 'sanctum']);
        $this->seed(ComposerPermissionsMinimalSeeder::class);

        $this->branchAdmin = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->branchAdmin->assignRole('Branch Admin');
    }

    public function test_profile_crud_publish_unpublish_and_price_payload_rejection(): void
    {
        $payload = [
            'template' => 'sandwich',
            'branch_id_scope' => $this->branch->id,
            'steps' => [
                [
                    'step_key' => 'crudites',
                    'label' => 'Crudites',
                    'source_type' => 'item_attribute',
                    'source_ref' => 'crudites',
                    'min_select' => 0,
                    'max_select' => 3,
                    'position' => 1,
                    'visible_on' => ['pos', 'kiosk'],
                ],
                [
                    'step_key' => 'drink',
                    'label' => 'Boisson',
                    'source_type' => 'addon',
                    'source_ref' => 'drinks',
                    'addon_role' => 'drink',
                    'min_select' => 0,
                    'max_select' => 1,
                    'position' => 2,
                ],
            ],
        ];

        $response = $this->actingAs($this->branchAdmin, 'sanctum')
            ->postJson("/api/admin/composer/items/{$this->item->id}/profile", $payload);

        $response->assertSuccessful()
            ->assertJsonPath('data.template', 'sandwich')
            ->assertJsonPath('data.steps.0.step_key', 'crudites')
            ->assertJsonPath('data.steps.1.addon_role', 'drink');

        $profileId = (int) $response->json('data.id');
        $this->assertDatabaseHas('item_wizard_profiles', [
            'id' => $profileId,
            'item_id' => $this->item->id,
            'branch_id_scope' => $this->branch->id,
        ]);

        $this->actingAs($this->branchAdmin, 'sanctum')
            ->postJson("/api/admin/composer/items/{$this->item->id}/profile", $payload + ['price' => 99])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['price']);

        Event::fake([ComposerProfileChanged::class, ComposerProfilePublished::class]);
        $this->actingAs($this->branchAdmin, 'sanctum')
            ->postJson("/api/admin/composer/profiles/{$profileId}/publish")
            ->assertSuccessful()
            ->assertJsonPath('data.is_published', true);
        Event::assertDispatched(ComposerProfilePublished::class);
        Event::assertDispatched(ComposerProfileChanged::class);

        $this->actingAs($this->branchAdmin, 'sanctum')
            ->postJson("/api/admin/composer/profiles/{$profileId}/unpublish")
            ->assertSuccessful()
            ->assertJsonPath('data.is_published', false);
    }

    public function test_step_endpoint_rejects_price_payload(): void
    {
        $profile = ItemWizardProfile::factory()->create([
            'item_id' => $this->item->id,
            'branch_id_scope' => $this->branch->id,
        ]);

        $this->actingAs($this->branchAdmin, 'sanctum')
            ->postJson("/api/admin/composer/profiles/{$profile->id}/steps", [
                'step_key' => 'priced',
                'label' => 'Priced',
                'source_type' => 'item_attribute',
                'price' => 12,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['price']);
    }

    public function test_profile_and_step_endpoints_reject_unsupported_fixed_source_type(): void
    {
        $payload = [
            'template' => 'custom',
            'branch_id_scope' => $this->branch->id,
            'steps' => [
                [
                    'step_key' => 'fixed',
                    'label' => 'Fixed',
                    'source_type' => 'fixed',
                    'min_select' => 1,
                    'max_select' => 1,
                ],
            ],
        ];

        $this->actingAs($this->branchAdmin, 'sanctum')
            ->postJson("/api/admin/composer/items/{$this->item->id}/profile", $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['steps.0.source_type']);

        $profile = ItemWizardProfile::factory()->create([
            'item_id' => $this->item->id,
            'branch_id_scope' => $this->branch->id,
        ]);

        $this->actingAs($this->branchAdmin, 'sanctum')
            ->postJson("/api/admin/composer/profiles/{$profile->id}/steps", [
                'step_key' => 'fixed',
                'label' => 'Fixed',
                'source_type' => 'fixed',
                'min_select' => 1,
                'max_select' => 1,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['source_type']);
    }

    public function test_profile_rejects_invalid_step_surface_and_selection_range(): void
    {
        $payload = [
            'template' => 'custom',
            'branch_id_scope' => $this->branch->id,
            'steps' => [
                [
                    'step_key' => 'crudites',
                    'label' => 'Crudites',
                    'source_type' => 'item_attribute',
                    'source_ref' => 'crudites',
                    'min_select' => 2,
                    'max_select' => 1,
                    'visible_on' => ['kiosk', 'backoffice'],
                ],
            ],
        ];

        $this->actingAs($this->branchAdmin, 'sanctum')
            ->postJson("/api/admin/composer/items/{$this->item->id}/profile", $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors([
                'steps.0.max_select',
                'steps.0.visible_on.1',
            ]);
    }

    public function test_step_endpoint_rejects_invalid_surface_and_selection_range(): void
    {
        $profile = ItemWizardProfile::factory()->create([
            'item_id' => $this->item->id,
            'branch_id_scope' => $this->branch->id,
        ]);

        $this->actingAs($this->branchAdmin, 'sanctum')
            ->postJson("/api/admin/composer/profiles/{$profile->id}/steps", [
                'step_key' => 'invalid',
                'label' => 'Invalid',
                'source_type' => 'item_attribute',
                'min_select' => 3,
                'max_select' => 1,
                'visible_on' => ['terminal'],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors([
                'max_select',
                'visible_on.0',
            ]);
    }

    public function test_publish_rejects_empty_profile_and_legacy_unsupported_step(): void
    {
        $emptyProfile = ItemWizardProfile::factory()->create([
            'item_id' => $this->item->id,
            'branch_id_scope' => $this->branch->id,
        ]);

        $this->actingAs($this->branchAdmin, 'sanctum')
            ->postJson("/api/admin/composer/profiles/{$emptyProfile->id}/publish")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['steps']);

        $legacyProfile = ItemWizardProfile::factory()->create([
            'item_id' => $this->item->id,
            'branch_id_scope' => $this->branch->id,
        ]);
        ItemWizardStep::query()->create([
            'profile_id' => $legacyProfile->id,
            'step_key' => 'legacy_fixed',
            'label' => 'Legacy fixed',
            'source_type' => 'fixed',
            'source_ref' => 'manual',
            'min_select' => 1,
            'max_select' => 1,
            'allow_repeat' => false,
            'visible_on' => ['pos', 'kiosk'],
            'stockable_choices' => false,
            'position' => 0,
            'is_active' => true,
        ]);

        $this->actingAs($this->branchAdmin, 'sanctum')
            ->postJson("/api/admin/composer/profiles/{$legacyProfile->id}/publish")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['steps']);
    }
}
