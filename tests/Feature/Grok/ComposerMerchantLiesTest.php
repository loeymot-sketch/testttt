<?php

namespace Tests\Feature\Grok;

use App\Enums\Status;
use App\Models\Item;
use App\Models\ItemAddon;
use App\Models\ItemAttribute;
use App\Models\ItemCategory;
use App\Models\ItemExtra;
use App\Models\ItemVariation;
use App\Models\ItemWizardProfile;
use App\Models\ItemWizardStep;
use App\Models\User;
use App\Services\Composer\ComposerProfileProjection;
use Database\Seeders\ComposerPermissionsMinimalSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Mensonges composeur : le restaurateur croit avoir un wizard en caisse.
 * La caisse ne lit que les profils publiés avec item_id, version la plus haute.
 */
class ComposerMerchantLiesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('catalog_v15.features.wizard_per_item_demo.enabled', true);

        $this->seedMinimalSettings();
        $this->seedSpatieRoles();
        $this->seed(ComposerPermissionsMinimalSeeder::class);

        $this->admin = User::factory()->create(['branch_id' => 0]);
        $this->admin->assignRole('Admin');
    }

    public function test_apply_template_on_published_item_creates_higher_version_draft_pos_keeps_old_until_publish(): void
    {
        $item = Item::factory()->create(['status' => Status::ACTIVE]);
        $published = ItemWizardProfile::factory()->create([
            'item_id' => $item->id,
            'template' => 'custom',
            'version' => 1,
            'is_published' => true,
            'published_at' => now(),
        ]);
        ItemWizardStep::factory()->create([
            'profile_id' => $published->id,
            'step_key' => 'ancien',
            'label' => 'Ancien wizard',
            'source_type' => 'item_attribute',
            'source_ref' => 'x',
            'min_select' => 0,
            'max_select' => 1,
            'is_active' => true,
            'position' => 1,
        ]);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/admin/composer/items/{$item->id}/apply-template", [
                'template' => 'snacking',
            ])
            ->assertOk()
            ->assertJsonPath('data.template', 'snacking');

        $draft = ItemWizardProfile::query()
            ->where('item_id', $item->id)
            ->where('is_published', false)
            ->latest('id')
            ->first();

        $this->assertNotNull($draft, 'Appliquer un template sur un wizard déjà en caisse doit créer un brouillon.');
        $this->assertGreaterThan(
            (int) $published->version,
            (int) $draft->version,
            'Un brouillon version=1 est ignoré par la caisse si un profil publié version=1 existe déjà.'
        );
        $this->assertSame(
            (int) $published->id,
            (int) $this->posPublishedProfile($item->id)?->id,
            'Tant que le restaurateur n’a pas publié, la caisse doit garder l’ancien wizard.'
        );

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/admin/composer/profiles/{$draft->id}/publish")
            ->assertOk();

        $live = $this->posPublishedProfile($item->id);
        $this->assertNotNull($live);
        $this->assertSame((int) $draft->id, (int) $live->id);
        $this->assertTrue((bool) $live->is_published);
        $this->assertGreaterThan((int) $published->version, (int) $live->version);
    }

    public function test_publishing_category_wizard_copies_published_item_profiles_for_pos(): void
    {
        $category = ItemCategory::factory()->create(['status' => Status::ACTIVE]);
        $itemA = Item::factory()->create([
            'item_category_id' => $category->id,
            'status' => Status::ACTIVE,
        ]);
        $itemB = Item::factory()->create([
            'item_category_id' => $category->id,
            'status' => Status::ACTIVE,
        ]);

        $apply = $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/admin/composer/categories/{$category->id}/apply-template", [
                'template' => 'snacking',
            ])
            ->assertOk();

        $categoryProfileId = (int) $apply->json('data.id');
        $this->assertNull($this->posPublishedProfile($itemA->id));
        $this->assertNull($this->posPublishedProfile($itemB->id));

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/admin/composer/profiles/{$categoryProfileId}/publish")
            ->assertOk();

        $liveA = $this->posPublishedProfile($itemA->id);
        $liveB = $this->posPublishedProfile($itemB->id);
        $this->assertNotNull($liveA, 'Publier le wizard catégorie doit poser un profil item_id sur chaque produit — sinon la caisse ne le voit pas.');
        $this->assertNotNull($liveB);
        $this->assertTrue((bool) $liveA->is_published);
        $this->assertTrue((bool) $liveB->is_published);
        $this->assertSame('snacking', (string) $liveA->template);
        $this->assertGreaterThan(0, $liveA->steps()->count());
        $this->assertGreaterThan(0, $liveB->steps()->count());
    }

    public function test_category_publish_does_not_eat_product_draft_or_old_published_row(): void
    {
        $category = ItemCategory::factory()->create(['status' => Status::ACTIVE]);
        $item = Item::factory()->create([
            'item_category_id' => $category->id,
            'status' => Status::ACTIVE,
        ]);

        $oldPublished = ItemWizardProfile::factory()->create([
            'item_id' => $item->id,
            'template' => 'custom',
            'version' => 1,
            'is_published' => true,
            'published_at' => now(),
        ]);
        ItemWizardStep::factory()->create([
            'profile_id' => $oldPublished->id,
            'step_key' => 'maison',
            'label' => 'Wizard produit',
            'source_type' => 'item_attribute',
            'source_ref' => 'viande',
            'min_select' => 0,
            'max_select' => 1,
            'is_active' => true,
            'position' => 1,
        ]);

        $draft = ItemWizardProfile::factory()->create([
            'item_id' => $item->id,
            'template' => 'custom',
            'version' => 2,
            'is_published' => false,
        ]);
        ItemWizardStep::factory()->create([
            'profile_id' => $draft->id,
            'step_key' => 'brouillon',
            'label' => 'Brouillon produit',
            'source_type' => 'item_attribute',
            'source_ref' => 'sauce',
            'min_select' => 0,
            'max_select' => 1,
            'is_active' => true,
            'position' => 1,
        ]);

        $apply = $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/admin/composer/categories/{$category->id}/apply-template", [
                'template' => 'snacking',
            ])
            ->assertOk();

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/admin/composer/profiles/'.$apply->json('data.id').'/publish')
            ->assertOk();

        $this->assertNotNull(ItemWizardProfile::query()->find($draft->id));
        $this->assertFalse((bool) $draft->fresh()->is_published);
        $this->assertSame('brouillon', $draft->fresh('steps')->steps->first()->step_key);

        $this->assertNotNull(ItemWizardProfile::query()->find($oldPublished->id));
        $this->assertTrue((bool) $oldPublished->fresh()->is_published);
        $this->assertSame('maison', $oldPublished->fresh('steps')->steps->first()->step_key);

        $live = $this->posPublishedProfile($item->id);
        $this->assertNotNull($live);
        $this->assertSame('snacking', (string) $live->template);
        $this->assertNotSame((int) $oldPublished->id, (int) $live->id);
        $this->assertGreaterThan((int) $draft->version, (int) $live->version);
    }

    public function test_unpublishing_category_wizard_removes_clones_from_pos(): void
    {
        $category = ItemCategory::factory()->create(['status' => Status::ACTIVE]);
        $item = Item::factory()->create([
            'item_category_id' => $category->id,
            'status' => Status::ACTIVE,
        ]);

        $apply = $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/admin/composer/categories/{$category->id}/apply-template", [
                'template' => 'snacking',
            ])
            ->assertOk();

        $categoryProfileId = (int) $apply->json('data.id');
        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/admin/composer/profiles/{$categoryProfileId}/publish")
            ->assertOk();

        $this->assertNotNull($this->posPublishedProfile($item->id));

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/admin/composer/profiles/{$categoryProfileId}/unpublish")
            ->assertOk();

        $this->assertNull(
            $this->posPublishedProfile($item->id),
            'Dépublier le wizard catégorie laissait la caisse avec le clone publié.'
        );
        $this->assertFalse(
            (bool) ItemWizardProfile::query()->find($categoryProfileId)?->is_published
        );
    }

    public function test_cannot_publish_unbound_viande_page_when_item_has_several_attributes(): void
    {
        $item = Item::factory()->create(['status' => Status::ACTIVE]);
        $viande = ItemAttribute::factory()->create(['name' => 'Viande 1', 'status' => Status::ACTIVE]);
        $sauce = ItemAttribute::factory()->create(['name' => 'Sauce', 'status' => Status::ACTIVE]);
        ItemVariation::query()->create([
            'item_id' => $item->id,
            'item_attribute_id' => $viande->id,
            'name' => 'Poulet',
            'price' => 0,
            'status' => Status::ACTIVE,
        ]);
        ItemVariation::query()->create([
            'item_id' => $item->id,
            'item_attribute_id' => $sauce->id,
            'name' => 'Algérienne',
            'price' => 0,
            'status' => Status::ACTIVE,
        ]);
        $profile = ItemWizardProfile::factory()->create([
            'item_id' => $item->id,
            'template' => 'custom',
            'is_published' => false,
        ]);
        ItemWizardStep::factory()->create([
            'profile_id' => $profile->id,
            'step_key' => 'viande',
            'label' => 'Viande',
            'source_type' => 'item_attribute',
            'source_ref' => '',
            'min_select' => 1,
            'max_select' => 1,
            'is_active' => true,
            'position' => 1,
        ]);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/admin/composer/profiles/{$profile->id}/publish")
            ->assertStatus(422);
    }

    public function test_cannot_publish_category_tacos_template_when_products_have_no_viande(): void
    {
        $category = ItemCategory::factory()->create(['status' => Status::ACTIVE]);
        Item::factory()->create([
            'item_category_id' => $category->id,
            'status' => Status::ACTIVE,
        ]);

        $apply = $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/admin/composer/categories/{$category->id}/apply-template", [
                'template' => 'tacos',
            ])
            ->assertOk();

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/admin/composer/profiles/'.$apply->json('data.id').'/publish')
            ->assertStatus(422)
            ->assertJsonPath('errors.steps.0', 'Composer profile contains a required step without available choices.');
    }

    public function test_reapplying_template_on_published_category_creates_higher_version(): void
    {
        $category = ItemCategory::factory()->create(['status' => Status::ACTIVE]);
        Item::factory()->create([
            'item_category_id' => $category->id,
            'status' => Status::ACTIVE,
        ]);

        $first = $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/admin/composer/categories/{$category->id}/apply-template", [
                'template' => 'snacking',
            ])
            ->assertOk();

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/admin/composer/profiles/'.$first->json('data.id').'/publish')
            ->assertOk();

        $publishedVersion = (int) ItemWizardProfile::query()
            ->where('item_category_id', $category->id)
            ->where('is_published', true)
            ->max('version');

        $second = $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/admin/composer/categories/{$category->id}/apply-template", [
                'template' => 'sandwich',
            ])
            ->assertOk();

        $draft = ItemWizardProfile::query()->findOrFail((int) $second->json('data.id'));
        $this->assertFalse((bool) $draft->is_published);
        $this->assertGreaterThan(
            $publishedVersion,
            (int) $draft->version,
            'Ré-appliquer un template sur une catégorie déjà publiée recréait un profil version=1, ignoré ensuite.'
        );
        $this->assertSame('sandwich', (string) $draft->template);
    }

    public function test_tacos_template_binds_source_ref_so_viande_and_sauce_do_not_mix(): void
    {
        $item = Item::factory()->create(['status' => Status::ACTIVE]);
        $attrViande = ItemAttribute::factory()->create(['name' => 'Viande 1', 'status' => Status::ACTIVE]);
        $attrSauce = ItemAttribute::factory()->create(['name' => 'Sauce (1ère Gratuite)', 'status' => Status::ACTIVE]);

        ItemVariation::query()->create([
            'item_id' => $item->id,
            'item_attribute_id' => $attrViande->id,
            'name' => 'Poulet',
            'price' => 0,
            'status' => Status::ACTIVE,
            'visible_on' => ['pos', 'kiosk'],
        ]);
        ItemVariation::query()->create([
            'item_id' => $item->id,
            'item_attribute_id' => $attrSauce->id,
            'name' => 'Algérienne',
            'price' => 0,
            'status' => Status::ACTIVE,
            'visible_on' => ['pos', 'kiosk'],
        ]);
        ItemExtra::query()->create([
            'item_id' => $item->id,
            'name' => 'Salade',
            'price' => 0,
            'status' => Status::ACTIVE,
            'group_label' => 'crudite',
            'visible_on' => ['pos', 'kiosk'],
        ]);
        ItemExtra::query()->create([
            'item_id' => $item->id,
            'name' => 'Cheddar',
            'price' => 0.9,
            'status' => Status::ACTIVE,
            'group_label' => 'supplement',
            'visible_on' => ['pos', 'kiosk'],
        ]);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/admin/composer/items/{$item->id}/apply-template", [
                'template' => 'tacos',
            ])
            ->assertOk();

        $profile = ItemWizardProfile::query()->where('item_id', $item->id)->latest('id')->first();
        $this->assertNotNull($profile);
        $steps = $profile->steps()->get()->keyBy('step_key');

        $this->assertNotSame(
            '',
            trim((string) $steps['viande']->source_ref),
            'source_ref vide = toutes les variantes dans chaque page (viande + sauce mélangées).'
        );
        $this->assertNotSame('', trim((string) $steps['sauce']->source_ref));
        $this->assertSame('crudite', mb_strtolower((string) $steps['garnitures']->source_ref));
        $this->assertSame('supplement', mb_strtolower((string) $steps['supplements']->source_ref));

        $projected = app(ComposerProfileProjection::class)->project(
            $profile->fresh('steps'),
            $item->fresh(['variations.itemAttribute', 'extras', 'addons']),
            'pos',
        );

        $byKey = collect($projected['steps'])->keyBy('step_key');
        $viandeNames = collect($byKey['viande']['choices'])->pluck('name')->all();
        $sauceNames = collect($byKey['sauce']['choices'])->pluck('name')->all();
        $garnitureNames = collect($byKey['garnitures']['choices'])->pluck('name')->all();
        $suppNames = collect($byKey['supplements']['choices'])->pluck('name')->all();

        $this->assertContains('Poulet', $viandeNames);
        $this->assertNotContains('Algérienne', $viandeNames);
        $this->assertContains('Algérienne', $sauceNames);
        $this->assertNotContains('Poulet', $sauceNames);
        $this->assertContains('Salade', $garnitureNames);
        $this->assertNotContains('Cheddar', $garnitureNames);
        $this->assertContains('Cheddar', $suppNames);
        $this->assertNotContains('Salade', $suppNames);
    }

    public function test_category_available_sources_come_from_first_item(): void
    {
        $category = ItemCategory::factory()->create(['status' => Status::ACTIVE]);
        $item = Item::factory()->create([
            'item_category_id' => $category->id,
            'status' => Status::ACTIVE,
        ]);
        $attribute = ItemAttribute::factory()->create(['name' => 'Viande 1', 'status' => Status::ACTIVE]);
        ItemVariation::query()->create([
            'item_id' => $item->id,
            'item_attribute_id' => $attribute->id,
            'name' => 'Poulet',
            'price' => 0,
            'status' => Status::ACTIVE,
            'visible_on' => ['pos', 'kiosk'],
        ]);

        $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/admin/composer/categories/{$category->id}/available-sources")
            ->assertOk()
            ->assertJsonPath('data.item_attribute.0.name', 'Viande 1');
    }

    public function test_extra_group_default_includes_extras_with_null_group_label(): void
    {
        $item = Item::factory()->create(['status' => Status::ACTIVE]);
        ItemExtra::query()->create([
            'item_id' => $item->id,
            'name' => 'Cheddar',
            'price' => 0.9,
            'status' => Status::ACTIVE,
            'group_label' => null,
            'visible_on' => ['pos', 'kiosk'],
        ]);

        $profile = ItemWizardProfile::factory()->create([
            'item_id' => $item->id,
            'template' => 'snacking',
            'is_published' => true,
            'published_at' => now(),
        ]);
        ItemWizardStep::factory()->create([
            'profile_id' => $profile->id,
            'step_key' => 'supplements',
            'label' => 'Suppléments',
            'source_type' => 'extra_group',
            'source_ref' => 'default',
            'min_select' => 0,
            'max_select' => 5,
            'is_active' => true,
            'position' => 1,
            'visible_on' => ['pos', 'kiosk'],
        ]);

        $projected = app(ComposerProfileProjection::class)->project(
            $profile->fresh('steps'),
            $item->fresh(['variations', 'extras', 'addons']),
            'pos',
        );

        $names = collect($projected['steps'][0]['choices'] ?? [])->pluck('name')->all();
        $this->assertContains(
            'Cheddar',
            $names,
            'Le picker admin envoie source_ref=default pour un extra sans groupe ; la caisse ne le trouvait pas.'
        );
    }

    public function test_saving_a_published_item_profile_does_not_change_pos_until_publish(): void
    {
        $item = Item::factory()->create(['status' => Status::ACTIVE]);
        $published = ItemWizardProfile::factory()->create([
            'item_id' => $item->id,
            'template' => 'custom',
            'version' => 4,
            'is_published' => true,
            'published_at' => now(),
        ]);
        $oldStep = ItemWizardStep::factory()->create([
            'profile_id' => $published->id,
            'step_key' => 'viande',
            'label' => 'Ancienne viande',
            'source_type' => 'item_attribute',
            'source_ref' => 'viande',
            'min_select' => 0,
            'max_select' => 1,
            'is_active' => true,
            'position' => 1,
        ]);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/admin/composer/profiles/{$published->id}", [
                'template' => 'custom',
                'version' => 4,
                'steps' => [[
                    'step_key' => 'viande',
                    'label' => 'Nouvelle viande',
                    'source_type' => 'item_attribute',
                    'source_ref' => 'viande',
                    'min_select' => 0,
                    'max_select' => 1,
                    'position' => 1,
                    'is_active' => true,
                    'visible_on' => ['pos', 'kiosk'],
                ]],
            ]);

        $response->assertOk();
        $this->assertFalse((bool) $response->json('data.is_published'));
        $this->assertNotSame((int) $published->id, (int) $response->json('data.id'));
        $this->assertSame('Ancienne viande', $oldStep->fresh()->label);
        $this->assertTrue((bool) $published->fresh()->is_published);
        $this->assertSame(
            (int) $published->id,
            (int) $this->posPublishedProfile($item->id)?->id,
            'Enregistrer un brouillon ne doit pas remplacer le wizard en caisse.'
        );

        $draftId = (int) $response->json('data.id');
        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/admin/composer/profiles/{$draftId}/publish")
            ->assertOk();

        $live = $this->posPublishedProfile($item->id);
        $this->assertSame($draftId, (int) $live->id);
        $this->assertSame('Nouvelle viande', $live->fresh('steps')->steps->first()->label);
    }

    public function test_saving_a_published_category_profile_does_not_refresh_pos_clones(): void
    {
        $category = ItemCategory::factory()->create(['status' => Status::ACTIVE]);
        $item = Item::factory()->create([
            'item_category_id' => $category->id,
            'status' => Status::ACTIVE,
        ]);

        $apply = $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/admin/composer/categories/{$category->id}/apply-template", [
                'template' => 'snacking',
            ])
            ->assertOk();

        $categoryProfileId = (int) $apply->json('data.id');
        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/admin/composer/profiles/{$categoryProfileId}/publish")
            ->assertOk();

        $cloneBefore = $this->posPublishedProfile($item->id);
        $this->assertNotNull($cloneBefore);
        $beforeStepCount = $cloneBefore->steps()->count();

        $save = $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/admin/composer/profiles/{$categoryProfileId}", [
                'template' => 'snacking',
                'version' => (int) ItemWizardProfile::query()->find($categoryProfileId)->version,
                'steps' => [[
                    'step_key' => 'supplements',
                    'label' => 'Extras renommés',
                    'source_type' => 'extra_group',
                    'source_ref' => 'default',
                    'min_select' => 0,
                    'max_select' => 5,
                    'position' => 1,
                    'is_active' => true,
                    'visible_on' => ['pos', 'kiosk'],
                ]],
            ]);

        $save->assertOk();
        $this->assertFalse((bool) $save->json('data.is_published'));
        $this->assertSame(
            (int) $cloneBefore->id,
            (int) $this->posPublishedProfile($item->id)?->id
        );
        $this->assertSame($beforeStepCount, $this->posPublishedProfile($item->id)->steps()->count());
        $this->assertNotSame(
            'Extras renommés',
            $this->posPublishedProfile($item->id)->fresh('steps')->steps->first()->label
        );
    }

    public function test_patching_a_step_on_a_published_profile_does_not_mutate_pos(): void
    {
        $item = Item::factory()->create(['status' => Status::ACTIVE]);
        $published = ItemWizardProfile::factory()->create([
            'item_id' => $item->id,
            'template' => 'custom',
            'version' => 1,
            'is_published' => true,
            'published_at' => now(),
        ]);
        $step = ItemWizardStep::factory()->create([
            'profile_id' => $published->id,
            'step_key' => 'viande',
            'label' => 'Viande caisse',
            'source_type' => 'item_attribute',
            'source_ref' => 'viande',
            'min_select' => 0,
            'max_select' => 1,
            'is_active' => true,
            'position' => 1,
            'visible_on' => ['pos', 'kiosk'],
        ]);

        $this->actingAs($this->admin, 'sanctum')
            ->patchJson("/api/admin/composer/steps/{$step->id}", [
                'step_key' => 'viande',
                'label' => 'Viande secrète',
                'source_type' => 'item_attribute',
                'source_ref' => 'viande',
                'min_select' => 0,
                'max_select' => 1,
                'position' => 1,
                'is_active' => true,
                'visible_on' => ['pos', 'kiosk'],
            ])
            ->assertStatus(422);

        $this->assertSame('Viande caisse', $step->fresh()->label);
        $this->assertSame(
            (int) $published->id,
            (int) $this->posPublishedProfile($item->id)?->id
        );
    }

    public function test_admin_item_profile_prefers_product_draft_after_category_publish(): void
    {
        $category = ItemCategory::factory()->create(['status' => Status::ACTIVE]);
        $item = Item::factory()->create([
            'item_category_id' => $category->id,
            'status' => Status::ACTIVE,
        ]);
        $draft = ItemWizardProfile::factory()->create([
            'item_id' => $item->id,
            'template' => 'custom',
            'version' => 2,
            'is_published' => false,
        ]);
        ItemWizardStep::factory()->create([
            'profile_id' => $draft->id,
            'step_key' => 'brouillon',
            'label' => 'Brouillon produit',
            'source_type' => 'item_attribute',
            'source_ref' => 'sauce',
            'min_select' => 0,
            'max_select' => 1,
            'is_active' => true,
            'position' => 1,
        ]);

        $apply = $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/admin/composer/categories/{$category->id}/apply-template", [
                'template' => 'snacking',
            ])
            ->assertOk();
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/admin/composer/profiles/'.$apply->json('data.id').'/publish')
            ->assertOk();

        $this->assertNotNull($this->posPublishedProfile($item->id));
        $this->assertNotSame((int) $draft->id, (int) $this->posPublishedProfile($item->id)->id);

        $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/admin/composer/items/{$item->id}/profile")
            ->assertOk()
            ->assertJsonPath('data.id', $draft->id)
            ->assertJsonPath('data.is_published', false);
    }

    public function test_vue_does_not_patch_or_delete_steps_while_profile_is_published(): void
    {
        $src = file_get_contents(
            base_path('resources/js/components/admin/items/composer/ProductComposerEditorComponent.vue')
        );
        $this->assertSame(
            1,
            preg_match('/async onStepsReordered\(value\) \{([\s\S]*?)\},\s*requestRemoveStep/', $src, $reorder)
        );
        $this->assertStringContainsString('is_published', $reorder[1]);
        $this->assertSame(
            1,
            preg_match('/async confirmRemoveStep\(\) \{([\s\S]*?)\},\s*profilePayload/', $src, $remove)
        );
        $this->assertStringContainsString('is_published', $remove[1]);
    }

    public function test_empty_source_ref_does_not_mix_viande_and_sauce_on_till(): void
    {
        $item = Item::factory()->create(['status' => Status::ACTIVE]);
        $viande = ItemAttribute::factory()->create(['name' => 'Viande']);
        $sauce = ItemAttribute::factory()->create(['name' => 'Sauce']);
        ItemVariation::query()->create([
            'item_id' => $item->id,
            'item_attribute_id' => $viande->id,
            'name' => 'Poulet',
            'price' => 0,
            'status' => Status::ACTIVE,
        ]);
        ItemVariation::query()->create([
            'item_id' => $item->id,
            'item_attribute_id' => $sauce->id,
            'name' => 'Algérienne',
            'price' => 0,
            'status' => Status::ACTIVE,
        ]);
        $profile = ItemWizardProfile::factory()->create([
            'item_id' => $item->id,
            'is_published' => true,
            'published_at' => now(),
        ]);
        ItemWizardStep::factory()->create([
            'profile_id' => $profile->id,
            'step_key' => 'viande',
            'label' => 'Viande',
            'source_type' => 'item_attribute',
            'source_ref' => '',
            'min_select' => 1,
            'max_select' => 1,
            'is_active' => true,
            'position' => 1,
            'visible_on' => ['pos'],
        ]);

        $projected = app(ComposerProfileProjection::class)->project(
            $profile->fresh('steps'),
            $item->fresh(['variations.itemAttribute', 'extras', 'addons']),
            'pos',
        );
        $names = collect($projected['steps'][0]['choices'] ?? [])->pluck('name')->all();
        $this->assertNotContains('Poulet', $names);
        $this->assertNotContains('Algérienne', $names);
    }

    public function test_addon_numeric_source_ref_is_that_row_not_every_addon(): void
    {
        $item = Item::factory()->create(['status' => Status::ACTIVE]);
        $drink = Item::factory()->create(['name' => 'Coca', 'status' => Status::ACTIVE]);
        $fries = Item::factory()->create(['name' => 'Frites', 'status' => Status::ACTIVE]);
        $coca = ItemAddon::query()->create([
            'item_id' => $item->id,
            'addon_item_id' => $drink->id,
            'role' => 'drink',
            'status' => Status::ACTIVE,
        ]);
        ItemAddon::query()->create([
            'item_id' => $item->id,
            'addon_item_id' => $fries->id,
            'role' => 'side',
            'status' => Status::ACTIVE,
        ]);
        $profile = ItemWizardProfile::factory()->create([
            'item_id' => $item->id,
            'is_published' => true,
            'published_at' => now(),
        ]);
        ItemWizardStep::factory()->create([
            'profile_id' => $profile->id,
            'step_key' => 'boisson',
            'label' => 'Boisson',
            'source_type' => 'addon',
            'source_ref' => (string) $coca->id,
            'addon_role' => null,
            'min_select' => 1,
            'max_select' => 1,
            'is_active' => true,
            'position' => 1,
            'visible_on' => ['pos'],
        ]);

        $projected = app(ComposerProfileProjection::class)->project(
            $profile->fresh('steps'),
            $item->fresh(['variations', 'extras', 'addons.addonItem']),
            'pos',
        );
        $names = collect($projected['steps'][0]['choices'] ?? [])->pluck('name')->all();
        $this->assertSame(['Coca'], $names);
    }

    public function test_empty_extra_group_ref_uses_step_key_not_every_extra(): void
    {
        $item = Item::factory()->create(['status' => Status::ACTIVE]);
        ItemExtra::query()->create([
            'item_id' => $item->id,
            'name' => 'Cheddar',
            'price' => 0.9,
            'status' => Status::ACTIVE,
            'group_label' => 'supplement',
        ]);
        ItemExtra::query()->create([
            'item_id' => $item->id,
            'name' => 'Salade',
            'price' => 0,
            'status' => Status::ACTIVE,
            'group_label' => 'garniture',
        ]);
        $profile = ItemWizardProfile::factory()->create([
            'item_id' => $item->id,
            'is_published' => true,
            'published_at' => now(),
        ]);
        ItemWizardStep::factory()->create([
            'profile_id' => $profile->id,
            'step_key' => 'supplements',
            'label' => 'Suppléments',
            'source_type' => 'extra_group',
            'source_ref' => '',
            'min_select' => 0,
            'max_select' => 5,
            'is_active' => true,
            'position' => 1,
            'visible_on' => ['pos'],
        ]);

        $projected = app(ComposerProfileProjection::class)->project(
            $profile->fresh('steps'),
            $item->fresh(['variations', 'extras', 'addons']),
            'pos',
        );
        $names = collect($projected['steps'][0]['choices'] ?? [])->pluck('name')->all();
        $this->assertSame(['Cheddar'], $names);
        $this->assertNotContains('Salade', $names);
    }

    public function test_inactive_step_is_absent_from_till_projection(): void
    {
        $item = Item::factory()->create(['status' => Status::ACTIVE]);
        $profile = ItemWizardProfile::factory()->create([
            'item_id' => $item->id,
            'is_published' => true,
            'published_at' => now(),
        ]);
        ItemWizardStep::factory()->create([
            'profile_id' => $profile->id,
            'step_key' => 'pain',
            'label' => 'Choisis ton pain',
            'source_type' => 'item_attribute',
            'source_ref' => '',
            'min_select' => 1,
            'max_select' => 1,
            'is_active' => false,
            'position' => 1,
            'visible_on' => ['pos'],
        ]);
        ItemWizardStep::factory()->create([
            'profile_id' => $profile->id,
            'step_key' => 'sauce',
            'label' => 'Sauce',
            'source_type' => 'item_attribute',
            'source_ref' => 'Sauce (1ère Gratuite)',
            'min_select' => 1,
            'max_select' => 1,
            'is_active' => true,
            'position' => 2,
            'visible_on' => ['pos'],
        ]);

        $projected = app(ComposerProfileProjection::class)->project(
            $profile->fresh('steps'),
            $item->fresh(['variations', 'extras', 'addons']),
            'pos',
        );
        $keys = collect($projected['steps'])->pluck('step_key')->all();
        $this->assertNotContains('pain', $keys);
        $this->assertContains('sauce', $keys);
    }

    /**
     * Même requête que MenuProjection / Pricing / Kiosk : item_id + publié + version max.
     * On ne touche pas ces services (voie Claude / gelés).
     */
    private function posPublishedProfile(int $itemId): ?ItemWizardProfile
    {
        return ItemWizardProfile::query()
            ->where('item_id', $itemId)
            ->where('is_published', true)
            ->orderByDesc('version')
            ->orderByDesc('id')
            ->first();
    }
}
