<?php

namespace Tests\Feature\Services\Pricing;

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
use App\Services\CouponService;
use App\Services\Menu\AvailabilityService;
use App\Services\Order\OrderQuoteService;
use App\Services\Pricing\PricingRequest;
use App\Services\Pricing\PricingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ComposerStepConstraintTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;
    private ItemCategory $category;
    private Tax $tax;
    private PricingService $pricing;
    private CouponService $couponService;

    protected function setUp(): void
    {
        parent::setUp();

        // [iter15-BUG-NF525] Legacy HT-add fixture. Opt back into HT mode.
        config(['pricing.tax_inclusive_prices' => false]);

        $this->seedMinimalSettings();
        $this->branch = Branch::factory()->create();
        $this->category = ItemCategory::factory()->create(['status' => Status::ACTIVE]);
        $this->tax = Tax::factory()->create([
            'name' => 'TVA Composer',
            'type' => TaxType::PERCENTAGE,
            'tax_rate' => 10,
            'status' => Status::ACTIVE,
        ]);
        $this->pricing = new PricingService();
        $this->couponService = app(CouponService::class);
    }

    public function test_required_composer_variation_step_is_enforced_server_side(): void
    {
        [$item] = $this->makeItemWithVariationComposer(min: 1, max: 1, allowRepeat: false);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('minimum 1 sélection');

        $this->pricing->calculateOrder(
            PricingRequest::forKiosk(1, $this->branch->id, [$this->line($item->id, [])], 0, 0, 0.0),
            $this->couponService
        );
    }

    public function test_valid_composer_variation_step_is_accepted_server_side(): void
    {
        [$item, $variation] = $this->makeItemWithVariationComposer(min: 1, max: 1, allowRepeat: false);

        $result = $this->pricing->calculateOrder(
            PricingRequest::forKiosk(1, $this->branch->id, [$this->line($item->id, [['id' => $variation->id]])], 0, 0, 0.0),
            $this->couponService
        );

        $this->assertSame(1, count($result->orderItemInsertRows));
        $this->assertSame($item->id, $result->orderItemInsertRows[0]['item_id']);
        $this->assertSame(9.90, $result->orderItemInsertRows[0]['price']);
    }

    public function test_composer_extra_step_rejects_over_max_and_repeat_when_not_allowed(): void
    {
        $item = $this->makeItem();
        $extra = ItemExtra::query()->create([
            'item_id' => $item->id,
            'name' => 'Cheddar Composer',
            'price' => 1.50,
            'status' => Status::ACTIVE,
            'visible_on' => ['kiosk', 'pos'],
            'group_label' => 'Options',
        ]);
        $this->publishProfile($item, [
            [
                'step_key' => 'options',
                'label' => 'Options',
                'source_type' => 'extra_group',
                'source_ref' => 'Options',
                'min_select' => 0,
                'max_select' => 1,
                'allow_repeat' => false,
            ],
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('maximum 1 sélection');

        $this->pricing->calculateOrder(
            PricingRequest::forKiosk(
                1,
                $this->branch->id,
                [$this->line($item->id, [], [['id' => $extra->id, 'quantity' => 2]])],
                0,
                0,
                0.0
            ),
            $this->couponService
        );
    }

    public function test_required_composer_addon_step_is_enforced_server_side(): void
    {
        $item = $this->makeItem();
        $addonItem = $this->makeItem(['price' => 2.50]);
        $addon = ItemAddon::query()->create([
            'item_id' => $item->id,
            'addon_item_id' => $addonItem->id,
            'addon_item_variation' => null,
            'role' => 'drink',
        ]);
        $this->publishProfile($item, [
            [
                'step_key' => 'boisson',
                'label' => 'Boisson',
                'source_type' => 'addon',
                'source_ref' => 'drink',
                'min_select' => 1,
                'max_select' => 1,
                'allow_repeat' => false,
                'addon_role' => 'drink',
            ],
        ]);

        try {
            $this->pricing->calculateOrder(
                PricingRequest::forKiosk(1, $this->branch->id, [$this->line($item->id, [])], 0, 0, 0.0),
                $this->couponService
            );
            $this->fail('Required composer addon step should reject an order without item_addons payload.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertStringContainsString('Boisson', $exception->getMessage());
        }

        $result = $this->pricing->calculateOrder(
            PricingRequest::forKiosk(1, $this->branch->id, [$this->line($item->id, [], [], [['id' => $addon->id]])], 0, 0, 0.0),
            $this->couponService
        );

        $this->assertSame(1, count($result->orderItemInsertRows));
        $this->assertSame($item->id, $result->orderItemInsertRows[0]['item_id']);
        $this->assertSame(12.40, $result->orderItemInsertRows[0]['total_price']);
        $this->assertSame(12.40, $result->subtotal);
        $this->assertSame(13.64, $result->total);

        $snapshot = json_decode($result->orderItemInsertRows[0]['composition_snapshot'], true);
        $this->assertSame($addon->id, $snapshot['addons'][0]['addon_id']);
        $this->assertSame($addonItem->id, $snapshot['addons'][0]['addon_item_id']);
        $this->assertSame('drink', $snapshot['addons'][0]['role']);
        $this->assertSame(1, $snapshot['addons'][0]['quantity']);
        $this->assertSame(2.5, $snapshot['addons'][0]['unit_price']);
        $this->assertSame(2.5, $snapshot['addons'][0]['line_total']);
    }

    public function test_composer_addon_step_rejects_branch_unavailable_addon_item(): void
    {
        $item = $this->makeItem();
        $addonItem = $this->makeItem();
        $addon = ItemAddon::query()->create([
            'item_id' => $item->id,
            'addon_item_id' => $addonItem->id,
            'addon_item_variation' => null,
            'role' => 'drink',
        ]);
        app(AvailabilityService::class)->toggle($addonItem->id, $this->branch->id, false, 'rupture_boisson');
        $this->publishProfile($item, [
            [
                'step_key' => 'boisson',
                'label' => 'Boisson',
                'source_type' => 'addon',
                'source_ref' => 'drink',
                'min_select' => 1,
                'max_select' => 1,
                'allow_repeat' => false,
                'addon_role' => 'drink',
            ],
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('indisponible pour cette branche');

        $this->pricing->calculateOrder(
            PricingRequest::forKiosk(1, $this->branch->id, [$this->line($item->id, [], [], [['id' => $addon->id]])], 0, 0, 0.0),
            $this->couponService
        );
    }

    public function test_composer_addon_step_rejects_inactive_or_hidden_addon_item(): void
    {
        $item = $this->makeItem();
        $inactiveAddonItem = $this->makeItem(['status' => Status::INACTIVE]);
        $addon = ItemAddon::query()->create([
            'item_id' => $item->id,
            'addon_item_id' => $inactiveAddonItem->id,
            'addon_item_variation' => null,
            'role' => 'drink',
        ]);
        $this->publishProfile($item, [
            [
                'step_key' => 'boisson',
                'label' => 'Boisson',
                'source_type' => 'addon',
                'source_ref' => 'drink',
                'min_select' => 1,
                'max_select' => 1,
                'allow_repeat' => false,
                'addon_role' => 'drink',
            ],
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('inactif');

        $this->pricing->calculateOrder(
            PricingRequest::forKiosk(1, $this->branch->id, [$this->line($item->id, [], [], [['id' => $addon->id]])], 0, 0, 0.0),
            $this->couponService
        );
    }

    public function test_stockable_variation_and_extra_steps_reject_branch_stock_rupture(): void
    {
        $item = $this->makeItem();
        $attribute = ItemAttribute::factory()->create(['name' => 'Cuisson', 'status' => Status::ACTIVE]);
        $variation = ItemVariation::query()->create([
            'item_id' => $item->id,
            'item_attribute_id' => $attribute->id,
            'name' => 'Bien cuit',
            'price' => 0,
            'status' => Status::ACTIVE,
            'visible_on' => ['kiosk', 'pos'],
        ]);
        $extra = ItemExtra::query()->create([
            'item_id' => $item->id,
            'name' => 'Cheddar',
            'price' => 1.50,
            'status' => Status::ACTIVE,
            'visible_on' => ['kiosk', 'pos'],
            'group_label' => 'Options',
        ]);
        $this->publishProfile($item, [
            [
                'step_key' => 'cuisson',
                'label' => 'Cuisson',
                'source_type' => 'item_attribute',
                'source_ref' => (string) $attribute->id,
                'min_select' => 0,
                'max_select' => 1,
                'allow_repeat' => false,
                'stockable_choices' => true,
            ],
            [
                'step_key' => 'options',
                'label' => 'Options',
                'source_type' => 'extra_group',
                'source_ref' => 'Options',
                'min_select' => 0,
                'max_select' => 1,
                'allow_repeat' => false,
                'stockable_choices' => true,
            ],
        ], $this->branch->id);

        StockLevel::query()->create([
            'branch_id' => $this->branch->id,
            'stockable_type' => ItemVariation::class,
            'stockable_id' => $variation->id,
            'on_hand' => 0,
            'reserved' => 0,
        ]);
        StockLevel::query()->create([
            'branch_id' => $this->branch->id,
            'stockable_type' => ItemExtra::class,
            'stockable_id' => $extra->id,
            'on_hand' => 0,
            'reserved' => 0,
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('indisponible');

        $this->pricing->calculateOrder(
            PricingRequest::forKiosk(
                1,
                $this->branch->id,
                [$this->line($item->id, [['id' => $variation->id]], [['id' => $extra->id]])],
                0,
                0,
                0.0
            ),
            $this->couponService
        );
    }

    public function test_stockable_addon_step_rejects_branch_stock_rupture(): void
    {
        $item = $this->makeItem();
        $addonItem = $this->makeItem(['price' => 2.50]);
        $addon = ItemAddon::query()->create([
            'item_id' => $item->id,
            'addon_item_id' => $addonItem->id,
            'addon_item_variation' => null,
            'role' => 'drink',
        ]);
        $this->publishProfile($item, [
            [
                'step_key' => 'boisson',
                'label' => 'Boisson',
                'source_type' => 'addon',
                'source_ref' => 'drink',
                'min_select' => 1,
                'max_select' => 1,
                'allow_repeat' => false,
                'addon_role' => 'drink',
                'stockable_choices' => true,
            ],
        ]);

        StockLevel::query()->create([
            'branch_id' => $this->branch->id,
            'stockable_type' => Item::class,
            'stockable_id' => $addonItem->id,
            'on_hand' => 0,
            'reserved' => 0,
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('indisponible');

        $this->pricing->calculateOrder(
            PricingRequest::forKiosk(1, $this->branch->id, [$this->line($item->id, [], [], [['id' => $addon->id]])], 0, 0, 0.0),
            $this->couponService
        );
    }

    public function test_order_quote_canonical_modifiers_include_addons(): void
    {
        $service = app(OrderQuoteService::class);
        $method = new \ReflectionMethod($service, 'canonicalModifiers');
        $method->setAccessible(true);

        $modifiers = $method->invoke($service, [
            $this->line(99, [['id' => 10, 'quantity' => 2]], [['id' => 20]], [['id' => 30, 'quantity' => 2]]),
        ]);

        $this->assertSame(30.0, $modifiers[0]['addons'][0]['id']);
        $this->assertSame(2.0, $modifiers[0]['addons'][0]['quantity']);
    }

    public function test_composer_addon_step_rejects_repeat_when_not_allowed(): void
    {
        $item = $this->makeItem();
        $addonItem = $this->makeItem();
        $addon = ItemAddon::query()->create([
            'item_id' => $item->id,
            'addon_item_id' => $addonItem->id,
            'addon_item_variation' => null,
            'role' => 'drink',
        ]);
        $this->publishProfile($item, [
            [
                'step_key' => 'boisson',
                'label' => 'Boisson',
                'source_type' => 'addon',
                'source_ref' => 'drink',
                'min_select' => 0,
                'max_select' => 2,
                'allow_repeat' => false,
                'addon_role' => 'drink',
            ],
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('ne peut être sélectionné');

        $this->pricing->calculateOrder(
            PricingRequest::forKiosk(1, $this->branch->id, [$this->line($item->id, [], [], [['id' => $addon->id, 'quantity' => 2]])], 0, 0, 0.0),
            $this->couponService
        );
    }

    public function test_branch_scoped_composer_profile_wins_for_pricing_constraints(): void
    {
        $item = $this->makeItem();
        $attribute = ItemAttribute::factory()->create(['name' => 'Cuisson', 'status' => Status::ACTIVE]);
        $variation = ItemVariation::query()->create([
            'item_id' => $item->id,
            'item_attribute_id' => $attribute->id,
            'name' => 'Bien cuit',
            'price' => 0,
            'status' => Status::ACTIVE,
            'visible_on' => ['pos', 'kiosk'],
        ]);

        $this->publishProfile($item, [
            [
                'step_key' => 'global_optional',
                'label' => 'Global optional',
                'source_type' => 'item_attribute',
                'source_ref' => (string) $attribute->id,
                'min_select' => 0,
                'max_select' => 1,
                'allow_repeat' => false,
            ],
        ], null);
        $this->publishProfile($item, [
            [
                'step_key' => 'branch_required',
                'label' => 'Branch required',
                'source_type' => 'item_attribute',
                'source_ref' => (string) $attribute->id,
                'min_select' => 1,
                'max_select' => 1,
                'allow_repeat' => false,
            ],
        ], $this->branch->id);

        try {
            $this->pricing->calculateOrder(
                PricingRequest::forPos(1, $this->branch->id, [$this->line($item->id, [])], 0, 0, 0.0, 0.0),
                $this->couponService
            );
            $this->fail('Branch scoped composer profile should require the branch-specific step.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertStringContainsString('Branch required', $exception->getMessage());
        }

        $result = $this->pricing->calculateOrder(
            PricingRequest::forPos(1, $this->branch->id, [$this->line($item->id, [['id' => $variation->id]])], 0, 0, 0.0, 0.0),
            $this->couponService
        );

        $this->assertSame(1, count($result->orderItemInsertRows));
        $this->assertSame($item->id, $result->orderItemInsertRows[0]['item_id']);
    }

    public function test_published_profile_rejects_stale_choice_ids_not_in_current_projection(): void
    {
        [$item, $variation] = $this->makeItemWithVariationComposer(min: 1, max: 1, allowRepeat: false);
        $oldAttribute = ItemAttribute::factory()->create(['name' => 'Ancienne garniture', 'status' => Status::ACTIVE]);
        $oldVariation = ItemVariation::query()->create([
            'item_id' => $item->id,
            'item_attribute_id' => $oldAttribute->id,
            'name' => 'Ancien choix actif',
            'price' => 0,
            'status' => Status::ACTIVE,
            'visible_on' => ['kiosk', 'pos'],
        ]);

        try {
            $this->pricing->calculateOrder(
                PricingRequest::forKiosk(1, $this->branch->id, [$this->line($item->id, [['id' => $variation->id], ['id' => $oldVariation->id]])], 0, 0, 0.0),
                $this->couponService
            );
            $this->fail('Published composer profile should reject stale active choices missing from the current projection.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertStringContainsString("choix #{$oldVariation->id}", $exception->getMessage());
            $this->assertStringContainsString('profil publié', $exception->getMessage());
        }
    }

    public function test_pricing_rejects_legacy_published_profile_with_unsupported_source_type(): void
    {
        $item = $this->makeItem();
        $this->publishProfile($item, [
            [
                'step_key' => 'legacy_fixed',
                'label' => 'Legacy fixed',
                'source_type' => 'fixed',
                'source_ref' => 'manual',
                'min_select' => 1,
                'max_select' => 1,
                'allow_repeat' => false,
            ],
        ], $this->branch->id);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('type de source non supporté');

        $this->pricing->calculateOrder(
            PricingRequest::forKiosk(1, $this->branch->id, [$this->line($item->id)], 0, 0, 0.0),
            $this->couponService
        );
    }

    /**
     * [GOAL_WIZARD_DYNAMIC W7 — GATE-G discriminator 2026-06-08]
     *
     * ASYMMETRY PROOF (the confirmed P1): the render paths
     * (MenuProjectionService / KioskMenuService / ItemResource /
     * NormalItemResource) now resolve a category-owned published profile for
     * an item that has no own profile (committed 0ad1906ff) — so the wizard
     * RENDERS for inherited-category items. But PricingService's
     * `assertComposerStepConstraints` (FROZEN ZONE, CLAUDE.md §7) still
     * resolves `whereIn('item_id', ...)` ONLY (PricingService.php:564-576) —
     * so its server-side composer validation (step membership + min/max +
     * allow_repeat) is SILENTLY SKIPPED for the very same inherited items.
     *
     * Severity = P1 (validation-completeness), NOT a fiscal P0: price is
     * computed from catalog (PricingService.php:134-226) and the menu-formula
     * ratio is keyed on the DB `addons.role` column + payload role
     * (CompositionSnapshotBuilder::resolveEffectiveAddonRole) — NEITHER reads
     * ItemWizardProfile — so the bypass cannot mis-price. What it loses is the
     * "did the customer satisfy the required step?" guard.
     *
     * This test ENCODES THE CURRENT (incorrect) behavior so it is runnable
     * proof today and a regression anchor. WHEN THE GATE-G HEAL LANDS (mirror
     * the category fallback inside assertComposerStepConstraints), FLIP the
     * inherited-case assertion below from "accepted" to expectException
     * ('minimum 1 sélection' / 'Boisson').
     */
    public function test_GAP_category_inherited_composer_constraints_are_not_enforced_pending_gate_g(): void
    {
        // ---- Item A: relies on an INHERITED category profile (no own profile)
        $inheritedItem = $this->makeItem();
        $inheritedAddonItem = $this->makeItem(['price' => 2.50]);
        $inheritedAddon = ItemAddon::query()->create([
            'item_id' => $inheritedItem->id,
            'addon_item_id' => $inheritedAddonItem->id,
            'addon_item_variation' => null,
            'role' => 'drink',
        ]);
        // Required addon step published at the CATEGORY level (item_id = null).
        $this->publishCategoryProfile($this->category, [
            [
                'step_key' => 'boisson',
                'label' => 'Boisson',
                'source_type' => 'addon',
                'source_ref' => 'drink',
                'min_select' => 1,
                'max_select' => 1,
                'allow_repeat' => false,
                'addon_role' => 'drink',
            ],
        ]);

        // INVALID composition: required "Boisson" step left empty.
        // CURRENT (buggy) behavior = accepted because the category profile is
        // never resolved for this item. Post-GATE-G this must throw.
        $inheritedResult = $this->pricing->calculateOrder(
            PricingRequest::forKiosk(1, $this->branch->id, [$this->line($inheritedItem->id, [])], 0, 0, 0.0),
            $this->couponService
        );
        $this->assertSame(
            1,
            count($inheritedResult->orderItemInsertRows),
            'GAP CONFIRMED: an invalid composition on a category-inherited item is accepted server-side '
            .'(assertComposerStepConstraints bypassed). FLIP to expectException once GATE-G heal lands.'
        );

        // ---- Item B: identical step published on the ITEM itself -> enforced.
        $ownedItem = $this->makeItem();
        $ownedAddonItem = $this->makeItem(['price' => 2.50]);
        ItemAddon::query()->create([
            'item_id' => $ownedItem->id,
            'addon_item_id' => $ownedAddonItem->id,
            'addon_item_variation' => null,
            'role' => 'drink',
        ]);
        $this->publishProfile($ownedItem, [
            [
                'step_key' => 'boisson',
                'label' => 'Boisson',
                'source_type' => 'addon',
                'source_ref' => 'drink',
                'min_select' => 1,
                'max_select' => 1,
                'allow_repeat' => false,
                'addon_role' => 'drink',
            ],
        ]);

        // Same invalid composition on an item-OWNED profile IS rejected — this
        // is the asymmetry that proves the bug is the missing inheritance in
        // PricingService, not a missing guard in general.
        try {
            $this->pricing->calculateOrder(
                PricingRequest::forKiosk(1, $this->branch->id, [$this->line($ownedItem->id, [])], 0, 0, 0.0),
                $this->couponService
            );
            $this->fail('Item-owned composer profile must reject an order missing the required Boisson step.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertStringContainsString('Boisson', $exception->getMessage());
        }
    }

    private function makeItem(array $overrides = []): Item
    {
        return Item::factory()->create(array_merge([
            'item_category_id' => $this->category->id,
            'tax_id' => $this->tax->id,
            'price' => 9.90,
            'status' => Status::ACTIVE,
        ], $overrides));
    }

    private function makeItemWithVariationComposer(int $min, int $max, bool $allowRepeat): array
    {
        $item = $this->makeItem();
        $attribute = ItemAttribute::factory()->create(['name' => 'Accompagnement', 'status' => Status::ACTIVE]);
        $variation = ItemVariation::query()->create([
            'item_id' => $item->id,
            'item_attribute_id' => $attribute->id,
            'name' => 'Riz',
            'price' => 0,
            'status' => Status::ACTIVE,
            'visible_on' => ['kiosk', 'pos'],
        ]);

        $this->publishProfile($item, [
            [
                'step_key' => 'accompagnement',
                'label' => 'Accompagnement',
                'source_type' => 'item_attribute',
                'source_ref' => (string) $attribute->id,
                'min_select' => $min,
                'max_select' => $max,
                'allow_repeat' => $allowRepeat,
            ],
        ], $this->branch->id);

        return [$item, $variation];
    }

    private function publishProfile(Item $item, array $steps, ?int $branchIdScope = null): ItemWizardProfile
    {
        $profile = ItemWizardProfile::query()->create([
            'item_id' => $item->id,
            'template' => 'custom',
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
                'min_select' => $step['min_select'],
                'max_select' => $step['max_select'],
                'allow_repeat' => $step['allow_repeat'],
                'visible_on' => ['pos', 'kiosk'],
                'stockable_choices' => (bool) ($step['stockable_choices'] ?? false),
                'position' => $position,
                'is_active' => true,
                'addon_role' => $step['addon_role'] ?? null,
            ]);
        }

        return $profile;
    }

    private function publishCategoryProfile(ItemCategory $category, array $steps, ?int $branchIdScope = null): ItemWizardProfile
    {
        $profile = ItemWizardProfile::query()->create([
            'item_id' => null,
            'item_category_id' => $category->id,
            'template' => 'custom',
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
                'min_select' => $step['min_select'],
                'max_select' => $step['max_select'],
                'allow_repeat' => $step['allow_repeat'],
                'visible_on' => ['pos', 'kiosk'],
                'stockable_choices' => (bool) ($step['stockable_choices'] ?? false),
                'position' => $position,
                'is_active' => true,
                'addon_role' => $step['addon_role'] ?? null,
            ]);
        }

        return $profile;
    }

    private function line(int $itemId, array $variations = [], array $extras = [], array $addons = []): object
    {
        return (object) [
            'item_id' => $itemId,
            'quantity' => 1,
            'item_variations' => array_map(fn (array $row): object => (object) $row, $variations),
            'item_extras' => array_map(fn (array $row): object => (object) $row, $extras),
            'item_addons' => array_map(fn (array $row): object => (object) $row, $addons),
            'instruction' => null,
        ];
    }
}
