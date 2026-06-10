<?php

namespace Tests\Feature\Services\Menu;

use App\Enums\Status;
use App\Enums\TaxType;
use App\Models\Branch;
use App\Models\Item;
use App\Models\ItemAddon;
use App\Models\ItemAttribute;
use App\Models\ItemCategory;
use App\Models\ItemExtra;
use App\Models\ItemVariation;
use App\Models\ItemWizardProfile;
use App\Models\StockLevel;
use App\Models\Tax;
use App\Services\Composer\ComposerProfileProjection;
use App\Services\Kiosk\KioskMenuService;
use App\Services\Menu\MenuProjectionService;
use App\Services\Menu\MenuSnapshot;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MenuProjectionComposerProfileTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;
    private Tax $tax;
    private MenuProjectionService $projection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::factory()->create();
        $this->tax = Tax::factory()->create([
            'name' => 'TVA Composer Projection',
            'type' => TaxType::PERCENTAGE,
            'tax_rate' => 10,
            'status' => Status::ACTIVE,
        ]);
        $this->projection = new MenuProjectionService(new MenuSnapshot(new Repository(new ArrayStore())));
    }

    public function test_pos_and_kiosk_projection_consume_published_composer_profile_without_price_duplication(): void
    {
        $ctx = $this->makeComposerFixture();
        $profile = $this->publishProfile($ctx['item'], [
            ['step_key' => 'viandes', 'label' => 'Viandes', 'source_type' => 'item_attribute', 'source_ref' => (string) $ctx['attribute']->id, 'visible_on' => ['pos', 'kiosk']],
            ['step_key' => 'sauces', 'label' => 'Sauces', 'source_type' => 'extra_group', 'source_ref' => 'Sauces', 'visible_on' => ['kiosk']],
            ['step_key' => 'boisson_menu', 'label' => 'Boisson', 'source_type' => 'addon', 'source_ref' => 'drink', 'visible_on' => ['pos', 'kiosk'], 'addon_role' => 'drink'],
        ]);

        $posProfile = $this->projectedComposerProfile('pos', $ctx['item']->id);
        $kioskProfile = $this->projectedComposerProfile('kiosk', $ctx['item']->id);

        $this->assertSame((int) $profile->id, $posProfile['id']);
        $this->assertSame((int) $profile->id, $kioskProfile['id']);
        $this->assertSame(['viandes', 'boisson_menu'], collect($posProfile['steps'])->pluck('step_key')->all());
        $this->assertSame(['viandes', 'sauces', 'boisson_menu'], collect($kioskProfile['steps'])->pluck('step_key')->all());

        $posViandes = collect($posProfile['steps'])->firstWhere('step_key', 'viandes');
        $kioskViandes = collect($kioskProfile['steps'])->firstWhere('step_key', 'viandes');
        $this->assertSame([$ctx['sharedVariation']->id, $ctx['posVariation']->id], collect($posViandes['choices'])->pluck('id')->all());
        $this->assertSame([$ctx['sharedVariation']->id, $ctx['kioskVariation']->id], collect($kioskViandes['choices'])->pluck('id')->all());

        $kioskBoisson = collect($kioskProfile['steps'])->firstWhere('step_key', 'boisson_menu');
        $this->assertSame([$ctx['addon']->id], collect($kioskBoisson['choices'])->pluck('id')->all());
        $this->assertSame(['drink'], collect($kioskBoisson['choices'])->pluck('role')->unique()->values()->all());

        $this->assertNoPriceKeys($posProfile);
        $this->assertNoPriceKeys($kioskProfile);
    }

    public function test_kiosk_legacy_menu_and_canonical_projection_share_composer_profile_steps(): void
    {
        $ctx = $this->makeComposerFixture();
        $profile = $this->publishProfile($ctx['item'], [
            ['step_key' => 'viandes', 'label' => 'Viandes', 'source_type' => 'item_attribute', 'source_ref' => (string) $ctx['attribute']->id, 'visible_on' => ['pos', 'kiosk']],
            ['step_key' => 'sauces', 'label' => 'Sauces', 'source_type' => 'extra_group', 'source_ref' => 'Sauces', 'visible_on' => ['pos', 'kiosk']],
        ]);

        $legacy = app(KioskMenuService::class)->build($this->branch);
        $legacyProfile = collect($legacy['items'])->firstWhere('id', $ctx['item']->id)['composer_profile'];
        $canonicalProfile = $this->projectedComposerProfile('kiosk', $ctx['item']->id);

        $this->assertSame((int) $profile->id, $legacyProfile['id']);
        $this->assertSame(
            collect($canonicalProfile['steps'])->pluck('id')->all(),
            collect($legacyProfile['steps'])->pluck('id')->all()
        );
    }

    public function test_branch_scoped_profile_wins_over_global_profile(): void
    {
        $ctx = $this->makeComposerFixture();
        $this->publishProfile($ctx['item'], [
            ['step_key' => 'global_sauce', 'label' => 'Global sauce', 'source_type' => 'extra_group', 'source_ref' => 'Sauces', 'visible_on' => ['pos', 'kiosk']],
        ]);

        $branchProfile = $this->publishProfile($ctx['item'], [
            ['step_key' => 'branch_viande', 'label' => 'Branch viande', 'source_type' => 'item_attribute', 'source_ref' => (string) $ctx['attribute']->id, 'visible_on' => ['pos', 'kiosk']],
        ], $this->branch->id, 'tacos');

        $projected = $this->projectedComposerProfile('kiosk', $ctx['item']->id);

        $this->assertSame((int) $branchProfile->id, $projected['id']);
        $this->assertSame('tacos', $projected['template']);
        $this->assertSame(['branch_viande'], collect($projected['steps'])->pluck('step_key')->all());
    }

    public function test_composer_projection_filters_inactive_variation_and_extra_choices(): void
    {
        $ctx = $this->makeComposerFixture();
        $inactiveVariation = $this->makeVariation($ctx['item'], $ctx['attribute'], 'Inactive Kefta', null);
        $inactiveVariation->forceFill(['status' => Status::INACTIVE]);
        $inactiveExtra = $this->makeExtra($ctx['item'], 'Inactive Sauce', null);
        $inactiveExtra->forceFill(['status' => Status::INACTIVE]);
        $profile = $this->publishProfile($ctx['item'], [
            ['step_key' => 'viandes', 'label' => 'Viandes', 'source_type' => 'item_attribute', 'source_ref' => (string) $ctx['attribute']->id, 'visible_on' => ['kiosk']],
            ['step_key' => 'sauces', 'label' => 'Sauces', 'source_type' => 'extra_group', 'source_ref' => 'Sauces', 'visible_on' => ['kiosk']],
        ]);

        $item = $ctx['item']->fresh();
        $item->setRelation('variations', collect([$ctx['sharedVariation'], $inactiveVariation]));
        $item->setRelation('extras', collect([$ctx['sharedExtra'], $inactiveExtra]));

        $projected = app(ComposerProfileProjection::class)->project($profile, $item, 'kiosk');
        $viandes = collect($projected['steps'])->firstWhere('step_key', 'viandes');
        $sauces = collect($projected['steps'])->firstWhere('step_key', 'sauces');

        $this->assertSame([$ctx['sharedVariation']->id], collect($viandes['choices'])->pluck('id')->all());
        $this->assertSame([$ctx['sharedExtra']->id], collect($sauces['choices'])->pluck('id')->all());
    }

    public function test_stockable_composer_choices_expose_branch_stock_rupture_for_variations_extras_and_addons(): void
    {
        $ctx = $this->makeComposerFixture();
        $profile = $this->publishProfile($ctx['item'], [
            ['step_key' => 'viandes', 'label' => 'Viandes', 'source_type' => 'item_attribute', 'source_ref' => (string) $ctx['attribute']->id, 'visible_on' => ['kiosk'], 'stockable_choices' => true],
            ['step_key' => 'sauces', 'label' => 'Sauces', 'source_type' => 'extra_group', 'source_ref' => 'Sauces', 'visible_on' => ['kiosk'], 'stockable_choices' => true],
            ['step_key' => 'boisson_menu', 'label' => 'Boisson', 'source_type' => 'addon', 'source_ref' => 'drink', 'visible_on' => ['kiosk'], 'addon_role' => 'drink', 'stockable_choices' => true],
        ], $this->branch->id);

        StockLevel::query()->create([
            'branch_id' => $this->branch->id,
            'stockable_type' => ItemVariation::class,
            'stockable_id' => $ctx['sharedVariation']->id,
            'on_hand' => 0,
            'reserved' => 0,
        ]);
        StockLevel::query()->create([
            'branch_id' => $this->branch->id,
            'stockable_type' => ItemExtra::class,
            'stockable_id' => $ctx['sharedExtra']->id,
            'on_hand' => 0,
            'reserved' => 0,
        ]);
        StockLevel::query()->create([
            'branch_id' => $this->branch->id,
            'stockable_type' => Item::class,
            'stockable_id' => $ctx['addonItem']->id,
            'on_hand' => 0,
            'reserved' => 0,
        ]);

        $projected = app(ComposerProfileProjection::class)->project(
            $profile,
            $ctx['item']->fresh(['variations.itemAttribute', 'extras', 'addons.addonItem']),
            'kiosk',
            $this->branch->id
        );
        $steps = collect($projected['steps']);

        $viandeChoice = collect($steps->firstWhere('step_key', 'viandes')['choices'])->firstWhere('id', $ctx['sharedVariation']->id);
        $extraChoice = collect($steps->firstWhere('step_key', 'sauces')['choices'])->firstWhere('id', $ctx['sharedExtra']->id);
        $addonChoice = collect($steps->firstWhere('step_key', 'boisson_menu')['choices'])->firstWhere('id', $ctx['addon']->id);

        $this->assertFalse($viandeChoice['is_available']);
        $this->assertSame('stock_rupture', $viandeChoice['unavailable_reason']);
        $this->assertFalse($extraChoice['is_available']);
        $this->assertSame('stock_rupture', $extraChoice['unavailable_reason']);
        $this->assertFalse($addonChoice['is_available']);
        $this->assertSame('stock_rupture', $addonChoice['unavailable_reason']);
    }

    // [GOAL_WIZARD_DYNAMIC W7 / category-inheritance] The category builder UI promises
    // "Tous les produits de cette catégorie hériteront automatiquement de ce wizard."
    // The render resolution must honour that: an item with no OWN published profile must
    // inherit its category's published profile, projected against the item's own sources.
    public function test_category_owned_profile_is_inherited_by_category_item_without_own_profile(): void
    {
        $ctx = $this->makeComposerFixture();
        $catProfile = $this->publishCategoryProfile($ctx['category'], [
            ['step_key' => 'cat_viandes', 'label' => 'Viandes', 'source_type' => 'item_attribute', 'source_ref' => (string) $ctx['attribute']->id, 'visible_on' => ['pos', 'kiosk']],
        ]);

        $kioskProfile = $this->projectedComposerProfile('kiosk', $ctx['item']->id);

        $this->assertSame((int) $catProfile->id, $kioskProfile['id']);
        $this->assertSame(['cat_viandes'], collect($kioskProfile['steps'])->pluck('step_key')->all());
        // Choices resolve against the ITEM's own variations (inheritance, not copy).
        $catViandes = collect($kioskProfile['steps'])->firstWhere('step_key', 'cat_viandes');
        $this->assertNotEmpty($catViandes['choices']);
        $this->assertContains($ctx['sharedVariation']->id, collect($catViandes['choices'])->pluck('id')->all());
    }

    public function test_kiosk_menu_service_inherits_category_owned_profile_for_item_without_own_profile(): void
    {
        // The borne actually fetches KioskMenuService (MenuController), which has its OWN
        // composer resolution — prove inheritance on the path the owner will really use.
        $ctx = $this->makeComposerFixture();
        $catProfile = $this->publishCategoryProfile($ctx['category'], [
            ['step_key' => 'cat_viandes', 'label' => 'Viandes', 'source_type' => 'item_attribute', 'source_ref' => (string) $ctx['attribute']->id, 'visible_on' => ['kiosk']],
        ]);

        $legacy = app(KioskMenuService::class)->build($this->branch);
        $legacyItem = collect($legacy['items'])->firstWhere('id', $ctx['item']->id);

        $this->assertIsArray($legacyItem['composer_profile'], 'category-owned profile must be inherited by the kiosk menu (borne path)');
        $this->assertSame((int) $catProfile->id, $legacyItem['composer_profile']['id']);
        $this->assertSame(['cat_viandes'], collect($legacyItem['composer_profile']['steps'])->pluck('step_key')->all());
    }

    public function test_item_owned_profile_wins_over_category_owned_profile(): void
    {
        $ctx = $this->makeComposerFixture();
        $this->publishCategoryProfile($ctx['category'], [
            ['step_key' => 'cat_step', 'label' => 'Cat', 'source_type' => 'extra_group', 'source_ref' => 'Sauces', 'visible_on' => ['kiosk']],
        ]);
        $itemProfile = $this->publishProfile($ctx['item'], [
            ['step_key' => 'item_step', 'label' => 'Item', 'source_type' => 'item_attribute', 'source_ref' => (string) $ctx['attribute']->id, 'visible_on' => ['kiosk']],
        ]);

        $kioskProfile = $this->projectedComposerProfile('kiosk', $ctx['item']->id);

        $this->assertSame((int) $itemProfile->id, $kioskProfile['id']);
        $this->assertSame(['item_step'], collect($kioskProfile['steps'])->pluck('step_key')->all());
    }

    /**
     * [T-COMPO-3 2026-06-10] item_attribute step resolves by the STABLE FK
     * source_item_attribute_id, NOT the fuzzy name. Two homonym attributes ("Sauce"
     * twice) on the same item: a name match would be ambiguous (both match), but the
     * FK disambiguates → only the targeted attribute's variations are projected.
     */
    public function test_item_attribute_step_resolves_by_fk_not_ambiguous_name(): void
    {
        $category = ItemCategory::factory()->create(['name' => 'FK Cat', 'status' => Status::ACTIVE, 'channels' => null]);
        $item = Item::factory()->create([
            'item_category_id' => $category->id, 'tax_id' => $this->tax->id,
            'name' => 'Assiette FK', 'slug' => 'assiette-fk-' . uniqid(), 'price' => 9.90,
            'status' => Status::ACTIVE, 'channels' => null,
        ]);

        // Two attributes with the IDENTICAL name "Sauce" (homonym) on the same item.
        $sauceA = ItemAttribute::factory()->create(['name' => 'Sauce', 'status' => Status::ACTIVE, 'min_select' => 0, 'max_select' => 1, 'allow_repeat' => false]);
        $sauceB = ItemAttribute::factory()->create(['name' => 'Sauce', 'status' => Status::ACTIVE, 'min_select' => 0, 'max_select' => 1, 'allow_repeat' => false]);
        $this->makeVariation($item, $sauceA, 'Algerienne A', ['kiosk']);
        $this->makeVariation($item, $sauceB, 'Samourai B', ['kiosk']);

        // Step targets sauceB by FK; source_ref kept as the ambiguous name on purpose.
        $this->publishProfile($item, [[
            'step_key' => 'sauce', 'label' => 'Sauce', 'source_type' => 'item_attribute',
            'source_ref' => 'sauce', 'source_item_attribute_id' => $sauceB->id, 'visible_on' => ['kiosk'],
        ]]);

        $proj = $this->projectedComposerProfile('kiosk', $item->id);
        $choices = collect($proj['steps'])->firstWhere('step_key', 'sauce')['choices'];

        // FK disambiguation: ONLY sauceB's variation, never sauceA's (name alone would take both).
        $this->assertSame(['Samourai B'], collect($choices)->pluck('name')->all());
        $this->assertSame([(int) $sauceB->id], collect($choices)->pluck('item_attribute_id')->unique()->values()->all());
    }

    private function publishCategoryProfile(ItemCategory $category, array $steps, ?int $branchIdScope = null, string $template = 'tacos'): ItemWizardProfile
    {
        $profile = ItemWizardProfile::query()->create([
            'item_id' => null,
            'item_category_id' => $category->id,
            'template' => $template,
            'version' => 1,
            'is_published' => true,
            'published_at' => now(),
            'branch_id_scope' => $branchIdScope,
        ]);

        foreach ($steps as $position => $step) {
            $profile->steps()->create([
                'step_key' => $step['step_key'],
                'label' => $step['label'],
                'source_type' => $step['source_type'],
                'source_ref' => $step['source_ref'],
                'source_item_attribute_id' => $step['source_item_attribute_id'] ?? null,
                'source_item_attribute_id' => $step['source_item_attribute_id'] ?? null,
                'min_select' => 0,
                'max_select' => 2,
                'allow_repeat' => false,
                'visible_on' => $step['visible_on'],
                'stockable_choices' => (bool) ($step['stockable_choices'] ?? false),
                'position' => $position,
                'is_active' => true,
                'addon_role' => $step['addon_role'] ?? null,
            ]);
        }

        return $profile->fresh('steps');
    }

    private function makeComposerFixture(): array
    {
        $category = ItemCategory::factory()->create([
            'name' => 'Composer Runtime',
            'status' => Status::ACTIVE,
            'channels' => null,
        ]);

        $item = Item::factory()->create([
            'item_category_id' => $category->id,
            'tax_id' => $this->tax->id,
            'name' => 'Assiette Composer',
            'slug' => 'assiette-composer-' . uniqid(),
            'price' => 12.90,
            'status' => Status::ACTIVE,
            'channels' => null,
        ]);

        $addonItem = Item::factory()->create([
            'item_category_id' => $category->id,
            'tax_id' => $this->tax->id,
            'name' => 'Coca Composer',
            'price' => 2.50,
            'status' => Status::ACTIVE,
            'channels' => null,
        ]);

        $attribute = ItemAttribute::factory()->create([
            'name' => 'Viandes',
            'status' => Status::ACTIVE,
            'min_select' => 1,
            'max_select' => 2,
            'allow_repeat' => false,
        ]);

        $sharedVariation = $this->makeVariation($item, $attribute, 'Kefta', null);
        $posVariation = $this->makeVariation($item, $attribute, 'Merguez POS', ['pos']);
        $kioskVariation = $this->makeVariation($item, $attribute, 'Poulet Kiosk', ['kiosk']);

        $sharedExtra = $this->makeExtra($item, 'Samourai', null);
        $kioskExtra = $this->makeExtra($item, 'Algerienne', ['kiosk']);

        $addon = ItemAddon::query()->create([
            'item_id' => $item->id,
            'addon_item_id' => $addonItem->id,
            'addon_item_variation' => null,
            'role' => 'drink',
        ]);
        $inactiveAddonItem = Item::factory()->create([
            'item_category_id' => $category->id,
            'tax_id' => $this->tax->id,
            'name' => 'Inactive Composer Drink',
            'price' => 2.50,
            'status' => Status::INACTIVE,
            'channels' => null,
        ]);
        ItemAddon::query()->create([
            'item_id' => $item->id,
            'addon_item_id' => $inactiveAddonItem->id,
            'addon_item_variation' => null,
            'role' => 'drink',
        ]);
        $hiddenKioskAddonItem = Item::factory()->create([
            'item_category_id' => $category->id,
            'tax_id' => $this->tax->id,
            'name' => 'POS Only Composer Drink',
            'price' => 2.50,
            'status' => Status::ACTIVE,
            'channels' => ['pos'],
        ]);
        ItemAddon::query()->create([
            'item_id' => $item->id,
            'addon_item_id' => $hiddenKioskAddonItem->id,
            'addon_item_variation' => null,
            'role' => 'drink',
        ]);
        $unavailableAddonItem = Item::factory()->create([
            'item_category_id' => $category->id,
            'tax_id' => $this->tax->id,
            'name' => 'Unavailable Composer Drink',
            'price' => 2.50,
            'status' => Status::ACTIVE,
            'is_available' => false,
            'channels' => null,
        ]);
        ItemAddon::query()->create([
            'item_id' => $item->id,
            'addon_item_id' => $unavailableAddonItem->id,
            'addon_item_variation' => null,
            'role' => 'drink',
        ]);

        return compact(
            'category',
            'item',
            'addonItem',
            'attribute',
            'sharedVariation',
            'posVariation',
            'kioskVariation',
            'sharedExtra',
            'kioskExtra',
            'addon'
        );
    }

    private function makeVariation(Item $item, ItemAttribute $attribute, string $name, ?array $visibleOn): ItemVariation
    {
        return ItemVariation::query()->create([
            'item_id' => $item->id,
            'item_attribute_id' => $attribute->id,
            'name' => $name,
            'price' => 1.50,
            'status' => Status::ACTIVE,
            'visible_on' => $visibleOn,
        ]);
    }

    private function makeExtra(Item $item, string $name, ?array $visibleOn): ItemExtra
    {
        return ItemExtra::query()->create([
            'item_id' => $item->id,
            'name' => $name,
            'price' => 0.50,
            'status' => Status::ACTIVE,
            'visible_on' => $visibleOn,
            'group_label' => 'Sauces',
        ]);
    }

    private function publishProfile(Item $item, array $steps, ?int $branchIdScope = null, string $template = 'assiette'): ItemWizardProfile
    {
        $profile = ItemWizardProfile::query()->create([
            'item_id' => $item->id,
            'template' => $template,
            'version' => 1,
            'is_published' => true,
            'published_at' => now(),
            'branch_id_scope' => $branchIdScope,
        ]);

        foreach ($steps as $position => $step) {
            $profile->steps()->create([
                'step_key' => $step['step_key'],
                'label' => $step['label'],
                'source_type' => $step['source_type'],
                'source_ref' => $step['source_ref'],
                'source_item_attribute_id' => $step['source_item_attribute_id'] ?? null,
                'source_item_attribute_id' => $step['source_item_attribute_id'] ?? null,
                'min_select' => 0,
                'max_select' => 2,
                'allow_repeat' => false,
                'visible_on' => $step['visible_on'],
                'stockable_choices' => (bool) ($step['stockable_choices'] ?? false),
                'position' => $position,
                'is_active' => true,
                'addon_role' => $step['addon_role'] ?? null,
            ]);
        }

        return $profile->fresh('steps');
    }

    private function projectedComposerProfile(string $channel, int $itemId): array
    {
        $projection = $this->projection->forChannel($channel, $this->branch->id);
        $item = collect($projection['categories'])
            ->flatMap(fn (array $category): array => $category['items'])
            ->firstWhere('id', $itemId);

        $this->assertIsArray($item);
        $this->assertIsArray($item['composer_profile']);

        return $item['composer_profile'];
    }

    private function assertNoPriceKeys(array $payload): void
    {
        foreach ($payload as $key => $value) {
            $this->assertNotContains($key, ['price', 'flat_price', 'currency_price', 'convert_price', 'total']);
            if (is_array($value)) {
                $this->assertNoPriceKeys($value);
            }
        }
    }
}
