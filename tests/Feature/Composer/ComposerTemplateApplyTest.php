<?php

namespace Tests\Feature\Composer;

use App\Models\Item;
use App\Models\ItemWizardProfile;
use App\Models\User;
use Database\Seeders\ComposerPermissionsMinimalSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * [CV1-WIZARD-COMPOSABLE-001 T-WC-TEMPLATES-01]
 * Sentinels for POST /api/admin/composer/items/{item}/apply-template:
 * named templates expand to the expected step skeletons; unknown templates 422.
 */
class ComposerTemplateApplyTest extends TestCase
{
    use RefreshDatabase;

    private Item $item;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedMinimalSettings();
        Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'sanctum']);
        $this->seed(ComposerPermissionsMinimalSeeder::class);

        $this->item = Item::factory()->create();
        $this->admin = User::factory()->create();
        $this->admin->assignRole('Admin');
    }

    public function test_applies_tacos_template_creates_six_steps(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/admin/composer/items/{$this->item->id}/apply-template", ['template' => 'tacos']);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.template', 'tacos');

        $profile = ItemWizardProfile::query()->where('item_id', $this->item->id)->first();
        $this->assertNotNull($profile);
        $this->assertSame(6, $profile->steps()->count());
        $this->assertFalse((bool) $profile->is_published);
    }

    public function test_applies_simple_template_creates_zero_steps(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/admin/composer/items/{$this->item->id}/apply-template", ['template' => 'simple']);

        $response->assertOk()->assertJsonPath('data.template', 'simple');

        $profile = ItemWizardProfile::query()->where('item_id', $this->item->id)->first();
        $this->assertNotNull($profile);
        $this->assertSame(0, $profile->steps()->count());
    }

    public function test_applies_assiette_template_has_meat_no_pain_no_menu(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/admin/composer/items/{$this->item->id}/apply-template", ['template' => 'assiette'])
            ->assertOk();

        $profile = ItemWizardProfile::query()->where('item_id', $this->item->id)->first();
        $stepKeys = $profile->steps->pluck('step_key')->all();

        $this->assertContains('viande', $stepKeys);
        $this->assertNotContains('pain', $stepKeys);
        $this->assertNotContains('menu', $stepKeys);
    }

    public function test_applies_menu_template_uses_addon_roles(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/admin/composer/items/{$this->item->id}/apply-template", ['template' => 'menu'])
            ->assertOk();

        $profile = ItemWizardProfile::query()->where('item_id', $this->item->id)->first();
        $steps = $profile->steps->keyBy('step_key');

        $this->assertSame('addon', (string) $steps['plat']->source_type);
        $this->assertSame('menu_component', (string) $steps['plat']->addon_role);
        $this->assertSame('drink', (string) $steps['boisson']->addon_role);
        $this->assertSame('dessert', (string) $steps['dessert']->addon_role);
    }

    public function test_rejects_unknown_template_with_422(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/admin/composer/items/{$this->item->id}/apply-template", ['template' => 'unknown'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['template']);

        $this->assertSame(0, ItemWizardProfile::query()->where('item_id', $this->item->id)->count());
    }

    public function test_requires_authentication(): void
    {
        $response = $this->postJson(
            "/api/admin/composer/items/{$this->item->id}/apply-template",
            ['template' => 'simple']
        );

        $this->assertContains($response->status(), [401, 403, 419]);
    }
}
