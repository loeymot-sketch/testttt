<?php

namespace Tests\Feature\Composer;

use App\Models\Branch;
use App\Models\Item;
use App\Models\ItemWizardProfile;
use App\Models\User;
use Database\Seeders\ComposerPermissionsMinimalSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * [CV1-WIZARD-COMPOSABLE-001 T-WC-EDITOR-01-FIX-BRANCH-SCOPE]
 *
 * Closes the GPT_SELF_AUDIT NEEDS_FIX on T-WC-EDITOR-01: the admin composer UI
 * exposes a branch_id_scope selector but POST /admin/composer/items/{id}/apply-template
 * was previously hard-coding a global (null) scope, silently dropping the admin's
 * branch selection. These sentinels lock the contract:
 *  - branch_id_scope is honoured when supplied (branch-scoped profile created),
 *  - omitted scope still produces a global profile (back-compat),
 *  - unknown branch ids are rejected with 422.
 */
class ComposerTemplateBranchScopedTest extends TestCase
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

    public function test_apply_template_with_branch_id_scope_creates_branch_scoped_profile(): void
    {
        $branch = Branch::factory()->create();

        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/admin/composer/items/{$this->item->id}/apply-template", [
                'template' => 'tacos',
                'branch_id_scope' => $branch->id,
            ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.template', 'tacos')
            ->assertJsonPath('data.branch_id_scope', $branch->id);

        $profile = ItemWizardProfile::query()
            ->where('item_id', $this->item->id)
            ->where('branch_id_scope', $branch->id)
            ->first();

        $this->assertNotNull($profile, 'Profile should be created with branch_id_scope set to the chosen branch');
        $this->assertSame($branch->id, (int) $profile->branch_id_scope);
        $this->assertFalse((bool) $profile->is_published);
    }

    public function test_apply_template_without_branch_id_scope_creates_global_profile(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/admin/composer/items/{$this->item->id}/apply-template", [
                'template' => 'sandwich',
            ]);

        $response->assertOk()->assertJsonPath('data.template', 'sandwich');

        $profile = ItemWizardProfile::query()
            ->where('item_id', $this->item->id)
            ->first();

        $this->assertNotNull($profile);
        $this->assertNull($profile->branch_id_scope, 'Omitting branch_id_scope must keep the legacy global behaviour');
    }

    public function test_apply_template_rejects_unknown_branch_id_scope(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/admin/composer/items/{$this->item->id}/apply-template", [
                'template' => 'simple',
                'branch_id_scope' => 999999,
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['branch_id_scope']);

        $this->assertSame(0, ItemWizardProfile::query()->where('item_id', $this->item->id)->count());
    }
}
