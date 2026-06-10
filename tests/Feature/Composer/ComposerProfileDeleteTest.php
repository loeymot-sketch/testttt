<?php

namespace Tests\Feature\Composer;

use App\Events\ComposerProfileChanged;
use App\Models\ItemCategory;
use App\Models\ItemWizardProfile;
use App\Models\ItemWizardStep;
use App\Services\Composer\ComposerProfileService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * [GOAL CMS GESTION 2026-06-10 — P3 reliquat, T-W5b — RED P1-2]
 *
 * Suppression d'un wizard ENTIER (demande owner explicite, inexistante avant) :
 *  - un profil PUBLIÉ ne peut pas être supprimé (409 sémantique — dépublier
 *    d'abord, évite de casser la borne par accident).
 *  - un profil dépublié se supprime : steps + step versions cascadent (FK),
 *    le lien `item_categories.wizard_profile_id` passe à NULL (nullOnDelete),
 *    et `ComposerProfileChanged(changeType='deleted')` est dispatché.
 *  - résout aussi le deadlock C1.2 : catégorie supprimable après suppression
 *    de son wizard.
 */
class ComposerProfileDeleteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();
        $this->seedSpatieRoles();
        Event::fake([ComposerProfileChanged::class]);
    }

    private function categoryProfile(bool $published): array
    {
        $category = ItemCategory::factory()->create(['status' => 5]);
        $profile = ItemWizardProfile::factory()->create([
            'item_id'          => null,
            'item_category_id' => $category->id,
            'is_published'     => $published,
        ]);
        $category->update(['wizard_profile_id' => $profile->id]);
        ItemWizardStep::factory()->create([
            'profile_id'  => $profile->id,
            'step_key'    => 'sauce',
            'label'       => 'Sauces',
            'source_type' => 'extra_group',
            'source_ref'  => 'sauce',
            'position'    => 1,
        ]);

        return [$category, $profile];
    }

    public function test_published_profile_cannot_be_deleted(): void
    {
        [, $profile] = $this->categoryProfile(true);

        try {
            app(ComposerProfileService::class)->destroy($profile);
            $this->fail('expected destroy to refuse a published profile');
        } catch (\Exception $exception) {
            $this->assertSame(409, (int) $exception->getCode());
        }

        $this->assertDatabaseHas('item_wizard_profiles', ['id' => $profile->id]);
    }

    public function test_delete_route_works_for_category_owned_profile(): void
    {
        $this->seed(\Database\Seeders\ComposerPermissionsMinimalSeeder::class);
        $admin = \App\Models\User::factory()->create();
        $admin->assignRole('Admin');

        [$category, $profile] = $this->categoryProfile(false);

        $this->actingAs($admin, 'sanctum')
            ->deleteJson('/api/admin/composer/profiles/' . $profile->id)
            ->assertSuccessful();

        $this->assertDatabaseMissing('item_wizard_profiles', ['id' => $profile->id]);
        $this->assertNull($category->fresh()->wizard_profile_id);
    }

    public function test_delete_route_returns_404_for_item_owned_profile_while_demo_flag_off(): void
    {
        // [PIN — gate G-5] EnsureProfileNotItemOwnedUnlessDemoEnabled gates the
        // whole per-item profile lifecycle (update/unpublish/DELETE) behind
        // FEATURE_WIZARD_PER_ITEM_DEMO (default false). Deleting the 10
        // item-level wizards therefore requires the G-5 owner flip — pinned
        // here so any change to that behaviour is an explicit decision.
        $this->seed(\Database\Seeders\ComposerPermissionsMinimalSeeder::class);
        $admin = \App\Models\User::factory()->create();
        $admin->assignRole('Admin');

        $item = \App\Models\Item::factory()->create();
        $profile = ItemWizardProfile::factory()->create([
            'item_id'          => $item->id,
            'item_category_id' => null,
            'is_published'     => false,
        ]);

        $this->actingAs($admin, 'sanctum')
            ->deleteJson('/api/admin/composer/profiles/' . $profile->id)
            ->assertStatus(404);

        $this->assertDatabaseHas('item_wizard_profiles', ['id' => $profile->id]);
    }

    public function test_unpublished_profile_delete_cascades_and_detaches_category(): void
    {
        [$category, $profile] = $this->categoryProfile(false);
        $stepId = (int) $profile->steps()->first()->id;

        app(ComposerProfileService::class)->destroy($profile);

        $this->assertDatabaseMissing('item_wizard_profiles', ['id' => $profile->id]);
        $this->assertDatabaseMissing('item_wizard_steps', ['id' => $stepId]);
        $this->assertNull($category->fresh()->wizard_profile_id, 'category link must be detached');

        Event::assertDispatched(ComposerProfileChanged::class, function (ComposerProfileChanged $e) use ($profile): bool {
            return $e->profileId === (int) $profile->id && $e->changeType === 'deleted';
        });
    }
}
