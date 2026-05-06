<?php

namespace Tests\Feature\Composer;

use App\Models\ItemCategory;
use App\Models\ItemWizardProfile;
use App\Models\User;
use Database\Seeders\ComposerPermissionsMinimalSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ComposerProfileVersionConflictTest extends TestCase
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

    public function test_update_with_matching_version_succeeds(): void
    {
        $profile = $this->profile(['version' => 3]);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->putJson($this->profileUpdateUrl($profile), $this->payload([
                'version' => 3,
            ]));

        $response->assertOk()
            ->assertJsonPath('data.version', 4)
            ->assertJsonPath('data.template', 'sandwich');
    }

    public function test_update_with_stale_version_returns_409_with_expected_body(): void
    {
        $profile = $this->profile(['version' => 3]);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->putJson($this->profileUpdateUrl($profile), $this->payload([
                'version' => 1,
            ]));

        $response->assertStatus(409)
            ->assertJsonPath('message', 'Profile version conflict')
            ->assertJsonPath('expected', 3)
            ->assertJsonPath('got', 1);

        $this->assertSame(3, (int) $profile->fresh()->version);
    }

    public function test_update_without_version_field_still_succeeds_for_back_compat(): void
    {
        $profile = $this->profile(['version' => 3]);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->putJson($this->profileUpdateUrl($profile), $this->payload());

        $response->assertOk()
            ->assertJsonPath('data.version', 4)
            ->assertJsonPath('data.template', 'sandwich');
    }

    private function profile(array $overrides = []): ItemWizardProfile
    {
        $category = ItemCategory::factory()->create();

        return ItemWizardProfile::factory()->create(array_merge([
            'item_id' => null,
            'item_category_id' => $category->id,
            'template' => 'custom',
            'branch_id_scope' => null,
            'version' => 1,
            'is_published' => false,
        ], $overrides));
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'template' => 'sandwich',
            'branch_id_scope' => null,
        ], $overrides);
    }

    private function profileUpdateUrl(ItemWizardProfile $profile): string
    {
        return "/api/admin/composer/profiles/{$profile->id}";
    }
}
