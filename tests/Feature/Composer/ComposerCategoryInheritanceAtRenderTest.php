<?php

namespace Tests\Feature\Composer;

use App\Enums\Status;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\ItemWizardProfile;
use App\Models\ItemWizardStep;
use App\Services\Kiosk\KioskMenuService;
use App\Services\Menu\MenuProjectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

/**
 * [WIZARD-STUDIO WS-1 2026-06-15] Locks the category-inheritance-at-render fix: a published
 * CATEGORY-owned composer profile must resolve onto EVERY item of the category (item-owned wins),
 * so a category wizard authored in the Studio actually reaches the live borne / menu. Before the
 * fix both services keyed by item_id only and category wizards never reached customers.
 */
class ComposerCategoryInheritanceAtRenderTest extends TestCase
{
    use RefreshDatabase;

    private function categoryWithTwoItems(): array
    {
        $this->seedMinimalSettings();
        $category = ItemCategory::factory()->create(['status' => Status::ACTIVE, 'name' => 'Galette', 'channels' => ['pos', 'kiosk']]);
        $a = Item::factory()->create(['item_category_id' => $category->id, 'status' => Status::ACTIVE, 'channels' => ['pos', 'kiosk']]);
        $b = Item::factory()->create(['item_category_id' => $category->id, 'status' => Status::ACTIVE, 'channels' => ['pos', 'kiosk']]);

        return [$category, $a, $b];
    }

    private function publishCategoryProfile(ItemCategory $category): ItemWizardProfile
    {
        $profile = ItemWizardProfile::create([
            'item_category_id' => $category->id,
            'template' => 'custom',
            'is_published' => true,
            'version' => 1,
            'branch_id_scope' => null,
        ]);
        ItemWizardStep::create([
            'profile_id' => $profile->id, 'step_key' => 'pain', 'label' => 'Pain',
            'source_type' => 'item_attribute', 'source_ref' => '', 'min_select' => 1, 'max_select' => 1,
            'position' => 0, 'is_active' => true,
        ]);

        return $profile;
    }

    /** @return \Illuminate\Support\Collection<int, ItemWizardProfile> */
    private function resolve(object $service, $items): \Illuminate\Support\Collection
    {
        $m = new ReflectionMethod($service, 'publishedComposerProfiles');
        $m->setAccessible(true);

        return $m->invoke($service, collect($items), 1);
    }

    public function test_kiosk_menu_inherits_category_profile_onto_every_item(): void
    {
        [$category, $a, $b] = $this->categoryWithTwoItems();
        $profile = $this->publishCategoryProfile($category);

        $resolved = $this->resolve(app(KioskMenuService::class), [$a->fresh(), $b->fresh()]);

        $this->assertSame($profile->id, $resolved->get($a->id)?->id, 'item A inherits the category profile');
        $this->assertSame($profile->id, $resolved->get($b->id)?->id, 'item B inherits the category profile');
    }

    public function test_menu_projection_inherits_category_profile(): void
    {
        [$category, $a] = $this->categoryWithTwoItems();
        $profile = $this->publishCategoryProfile($category);

        $resolved = $this->resolve(app(MenuProjectionService::class), [$a->fresh()]);

        $this->assertSame($profile->id, $resolved->get($a->id)?->id);
    }

    public function test_item_owned_profile_wins_over_category(): void
    {
        [$category, $a, $b] = $this->categoryWithTwoItems();
        $catProfile = $this->publishCategoryProfile($category);

        $itemProfile = ItemWizardProfile::create([
            'item_id' => $a->id, 'template' => 'custom', 'is_published' => true, 'version' => 1, 'branch_id_scope' => null,
        ]);
        ItemWizardStep::create([
            'profile_id' => $itemProfile->id, 'step_key' => 'sauce', 'label' => 'Sauce',
            'source_type' => 'item_attribute', 'source_ref' => '', 'min_select' => 0, 'max_select' => 1,
            'position' => 0, 'is_active' => true,
        ]);

        $resolved = $this->resolve(app(KioskMenuService::class), [$a->fresh(), $b->fresh()]);

        $this->assertSame($itemProfile->id, $resolved->get($a->id)?->id, 'item-owned wins for item A');
        $this->assertSame($catProfile->id, $resolved->get($b->id)?->id, 'item B still inherits the category');
    }
}
