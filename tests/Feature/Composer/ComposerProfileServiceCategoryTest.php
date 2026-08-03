<?php

namespace Tests\Feature\Composer;

use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\ItemWizardProfile;
use App\Services\Composer\ComposerProfileService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ComposerProfileServiceCategoryTest extends TestCase
{
    use RefreshDatabase;

    private ComposerProfileService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedMinimalSettings();
        $this->service = app(ComposerProfileService::class);
    }

    public function test_create_for_category_creates_profile_with_category_owner(): void
    {
        $category = ItemCategory::factory()->create();

        $profile = $this->service->createForCategory($category, [
            'template' => 'custom',
            'steps' => [
                [
                    'step_key' => 'base',
                    'label' => 'Base',
                    'source_type' => 'item_attribute',
                    'min_select' => 0,
                    'max_select' => 1,
                    'position' => 1,
                ],
            ],
        ]);

        $this->assertNull($profile->item_id);
        $this->assertSame($category->id, $profile->item_category_id);
        $this->assertSame(1, $profile->steps()->count());
        $this->assertDatabaseHas('item_categories', [
            'id' => $category->id,
            'wizard_profile_id' => $profile->id,
        ]);
    }

    public function test_show_for_category_returns_latest_profile(): void
    {
        $category = ItemCategory::factory()->create();
        ItemWizardProfile::factory()->forCategory($category)->create(['template' => 'sandwich']);
        $latest = ItemWizardProfile::factory()->forCategory($category)->create(['template' => 'tacos']);

        $found = $this->service->showForCategory($category);

        $this->assertSame($latest->id, $found?->id);
        $this->assertSame('tacos', $found?->template);
    }

    public function test_resolve_for_item_prefers_category_profile_over_item_profile(): void
    {
        $category = ItemCategory::factory()->create();
        $item = Item::factory()->create(['item_category_id' => $category->id]);
        $categoryProfile = ItemWizardProfile::factory()->forCategory($category)->create(['template' => 'menu']);
        ItemWizardProfile::factory()->create(['item_id' => $item->id, 'template' => 'sandwich']);

        $found = $this->service->resolveForItem($item);

        $this->assertSame($categoryProfile->id, $found?->id);
        $this->assertSame('menu', $found?->template);
    }

    public function test_resolve_for_item_falls_back_to_item_profile_when_category_has_no_profile(): void
    {
        $item = Item::factory()->create();
        $itemProfile = ItemWizardProfile::factory()->create(['item_id' => $item->id, 'template' => 'sandwich']);

        $found = $this->service->resolveForItem($item);

        $this->assertSame($itemProfile->id, $found?->id);
        $this->assertSame('sandwich', $found?->template);
    }
}
