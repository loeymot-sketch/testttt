<?php

namespace Tests\Feature\Composer;

use App\Enums\Status;
use App\Models\Branch;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\ItemExtra;
use App\Models\ItemWizardProfile;
use App\Models\ItemWizardStep;
use App\Services\Composer\ComposerProfileProjection;
use App\Services\CouponService;
use App\Services\Pricing\PricingRequest;
use App\Services\Pricing\PricingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [GOAL_WIZARD_DYNAMIC_BUILDER Wave 6] Box = FULL-PRICE BUNDLE (owner decision G-0b=A).
 *
 * The non-frozen, fully-correct box renders via the generic escape-hatch: a step with a
 * NON-registry step_key + source_type 'extra_group' falls through to the non-frozen
 * KioskStepGenericChoicesComponent (verified against KioskWizardComponent's STEP_KEY_REGISTRY
 * / ADDON_ROLE_TO_TYPE). Its components are ItemExtras carrying their OWN full price, so:
 *  - the kiosk DISPLAY total sums them (composerExtraTotal), and
 *  - the backend SSOT (PricingService) sums them at FULL price (extras get no menu_* ratio).
 * No frozen edit needed. (The addon-role box path under-displays — see the W6 gate doc.)
 */
class BoxFullPriceBundleTest extends TestCase
{
    use RefreshDatabase;

    public function test_box_components_as_extras_price_at_full_bundle_total(): void
    {
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        $branch = Branch::factory()->create();
        $category = ItemCategory::factory()->create(['name' => 'Menu enfant', 'status' => Status::ACTIVE]);
        $box = Item::factory()->create([
            'item_category_id' => $category->id,
            'name' => 'Box Test',
            'price' => 5.00,
            'status' => Status::ACTIVE,
        ]);
        $frites = ItemExtra::query()->create(['item_id' => $box->id, 'name' => 'Frites', 'price' => 2.50, 'status' => Status::ACTIVE, 'group_label' => 'Composants']);
        $boisson = ItemExtra::query()->create(['item_id' => $box->id, 'name' => 'Boisson', 'price' => 2.00, 'status' => Status::ACTIVE, 'group_label' => 'Composants']);

        $line = (object) [
            'item_id' => $box->id,
            'quantity' => 1,
            'item_variations' => [],
            'item_extras' => [
                (object) ['id' => $frites->id, 'quantity' => 1],
                (object) ['id' => $boisson->id, 'quantity' => 1],
            ],
            'instruction' => null,
        ];

        $pricing = (new PricingService())->calculateOrder(
            PricingRequest::forPos(0, (int) $branch->id, [$line], 0, 0, 0.0, 0.0),
            app(CouponService::class)
        );

        // Full-price bundle: base 5.00 + Frites 2.50 + Boisson 2.00 = 9.50 (no menu ratio).
        $this->assertEqualsWithDelta(9.50, (float) $pricing->subtotal, 0.001, 'box components must bill at FULL price (no ratio)');
    }

    public function test_box_generic_extra_step_projects_components_without_price_leak(): void
    {
        $category = ItemCategory::factory()->create(['status' => Status::ACTIVE]);
        $box = Item::factory()->create(['item_category_id' => $category->id, 'price' => 0, 'status' => Status::ACTIVE]);
        ItemExtra::query()->create(['item_id' => $box->id, 'name' => 'Frites', 'price' => 2.50, 'status' => Status::ACTIVE, 'group_label' => 'Composants']);
        ItemExtra::query()->create(['item_id' => $box->id, 'name' => 'Boisson', 'price' => 2.00, 'status' => Status::ACTIVE, 'group_label' => 'Composants']);

        $profile = ItemWizardProfile::factory()->create(['item_id' => $box->id, 'is_published' => true]);
        // NON-registry step_key + extra_group => escape-hatch generic render (never frozen menu component).
        ItemWizardStep::query()->create([
            'profile_id' => $profile->id,
            'step_key' => 'box_composants',
            'label' => 'Composez votre box',
            'source_type' => 'extra_group',
            'source_ref' => 'Composants',
            'min_select' => 0,
            'max_select' => 2,
            'is_active' => true,
            'visible_on' => ['kiosk', 'pos'],
            'position' => 1,
        ]);

        $item = $box->fresh(['variations.itemAttribute', 'extras', 'addons.addonItem']);
        $projected = app(ComposerProfileProjection::class)->project($profile->fresh('steps'), $item, 'kiosk');
        $step = collect($projected['steps'])->firstWhere('step_key', 'box_composants');

        $this->assertNotNull($step);
        $this->assertSame('extra_group', $step['source_type']);
        $this->assertEqualsCanonicalizing(['Frites', 'Boisson'], collect($step['choices'])->pluck('name')->all());
        // Price stays out of the projection (NF525 anti-duplication) — joined by id downstream.
        $this->assertArrayNotHasKey('price', $step['choices'][0]);
        $this->assertArrayNotHasKey('convert_price', $step['choices'][0]);
    }
}
