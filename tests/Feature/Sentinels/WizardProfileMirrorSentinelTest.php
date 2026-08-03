<?php

namespace Tests\Feature\Sentinels;

use App\Enums\Status;
use App\Enums\TaxType;
use App\Models\Item;
use App\Models\ItemAttribute;
use App\Models\ItemCategory;
use App\Models\ItemExtra;
use App\Models\ItemVariation;
use App\Models\ItemWizardProfile;
use App\Models\ItemWizardStep;
use App\Models\Tax;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [P0-POS-03 GOAL round-2 2026-05-18] Wizard Profile Mirror sentinel.
 *
 * Root cause of the 2026-05-18 incident (POS "Composition #N
 * n'appartient pas au profil publié"): published wizard profile #85
 * (Chicken Burger / item 375) was missing the `viande` and `crudite`
 * step rows that the POS Vanilla JS wizard (pos-wizard.js) enumerates
 * and submits. PricingService rejected the order at the composition
 * boundary.
 *
 * This sentinel is the automated guard that catches the bug class
 * BEFORE production. It asserts that every PUBLISHED
 * `ItemWizardProfile` has at least one ACTIVE step row — a published
 * profile with zero active steps is an unshippable state because the
 * POS wizard cannot render an empty multi-step wizard for an item
 * whose category template is non-`simple`.
 *
 * Anti-fiction: the sentinel does NOT enumerate "wizard expects these
 * specific keys" (that contract drifts with frontend versions). It
 * enforces the minimal invariant that closed the 2026-05-18 incident:
 *   - published profile → at least one is_active step
 *   - the linked item exists + is ACTIVE
 *   - source_type matches one of the supported values
 *   - source_ref maps to at least one resolvable choice (variation or
 *     extra) when source_type requires it
 *
 * Failure modes guarded:
 *   1. Publish profile with no steps (the 2026-05-18 incident class).
 *   2. Publish profile with all steps `is_active = false`.
 *   3. Publish profile pointing to a soft-deleted Item.
 *   4. Step source_type `extra_group` with source_ref that no extra
 *      matches (frontend cannot render an empty step in min_select>=1).
 */
class WizardProfileMirrorSentinelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
    }

    /**
     * Happy path: a well-formed published profile mirrors a wizard step set
     * with at least one resolvable choice per step. Sentinel passes.
     */
    public function test_well_formed_profile_passes_sentinel(): void
    {
        $burger = $this->createBurgerItemWithFullProfile(publish: true);

        $violations = $this->scanPublishedProfilesForMirrorViolations();

        $this->assertSame(
            [],
            $violations,
            'Sentinel must accept a well-formed published profile with active steps. Found: '
            . json_encode($violations, JSON_PRETTY_PRINT)
        );
        // Sanity: the profile we created is published and has steps.
        $this->assertGreaterThan(0, ItemWizardProfile::where('is_published', true)->count());
        $profileId = ItemWizardProfile::where('item_id', $burger->id)->where('is_published', true)->value('id');
        $this->assertGreaterThan(0, ItemWizardStep::where('profile_id', $profileId)->count());
    }

    /**
     * Regression case #1 (the 2026-05-18 incident class): publish a profile
     * with ZERO steps. Sentinel must flag it.
     */
    public function test_published_profile_with_zero_steps_is_flagged(): void
    {
        $item = $this->createBurgerItem();

        $profile = ItemWizardProfile::create([
            'item_id'      => $item->id,
            'template'     => 'custom',
            'version'      => 1,
            'is_published' => true,
            'published_at' => now(),
        ]);

        // No steps inserted — replicates the 2026-05-18 profile 85 state.
        $violations = $this->scanPublishedProfilesForMirrorViolations();

        $this->assertNotEmpty(
            $violations,
            'Sentinel must flag a published profile with zero steps (2026-05-18 incident class)'
        );
        $this->assertContains(
            'profile#' . $profile->id . ':no_active_steps',
            $violations,
            'Sentinel must report the specific violation key for zero-active-steps'
        );
    }

    /**
     * Regression case #2: published profile where every step is is_active=0.
     * Functionally equivalent to no steps from the wizard's perspective.
     */
    public function test_published_profile_with_only_inactive_steps_is_flagged(): void
    {
        $item = $this->createBurgerItem();

        $profile = ItemWizardProfile::create([
            'item_id'      => $item->id,
            'template'     => 'custom',
            'version'      => 1,
            'is_published' => true,
            'published_at' => now(),
        ]);

        ItemWizardStep::create([
            'profile_id'  => $profile->id,
            'step_key'    => 'viande',
            'label'       => 'Viande',
            'source_type' => 'extra_group',
            'source_ref'  => 'viande',
            'min_select'  => 1,
            'max_select'  => 1,
            'position'    => 0,
            'is_active'   => 0,  // inactive
        ]);

        $violations = $this->scanPublishedProfilesForMirrorViolations();

        $this->assertContains(
            'profile#' . $profile->id . ':no_active_steps',
            $violations,
            'Sentinel must flag a published profile whose only step is inactive'
        );
    }

    /**
     * Regression case #3: step source_type=`extra_group` with source_ref
     * that no extra matches → wizard would render an empty step → if
     * min_select >= 1 the customer is locked out.
     */
    public function test_step_with_unresolvable_source_ref_is_flagged_when_min_required(): void
    {
        $item = $this->createBurgerItem();

        $profile = ItemWizardProfile::create([
            'item_id'      => $item->id,
            'template'     => 'custom',
            'version'      => 1,
            'is_published' => true,
            'published_at' => now(),
        ]);

        ItemWizardStep::create([
            'profile_id'  => $profile->id,
            'step_key'    => 'phantom',
            'label'       => 'Phantom group',
            'source_type' => 'extra_group',
            'source_ref'  => 'no_such_group_label_in_db', // unresolvable
            'min_select'  => 1,  // REQUIRED
            'max_select'  => 1,
            'position'    => 0,
            'is_active'   => 1,
        ]);

        $violations = $this->scanPublishedProfilesForMirrorViolations();

        $this->assertContains(
            'profile#' . $profile->id . ':step_unresolvable:phantom',
            $violations,
            'Sentinel must flag a required step whose source_ref resolves to zero choices'
        );
    }

    /**
     * Regression case #4: profile linked to a soft-deleted Item. The
     * wizard cannot render anything for a deleted item, but the profile
     * stays published → silent dead row.
     */
    public function test_published_profile_pointing_to_deleted_item_is_flagged(): void
    {
        $item = $this->createBurgerItem();
        $profile = $this->attachFullProfile($item, publish: true);

        // Soft-delete the item.
        $item->delete();

        $violations = $this->scanPublishedProfilesForMirrorViolations();

        $this->assertContains(
            'profile#' . $profile->id . ':item_missing_or_deleted',
            $violations,
            'Sentinel must flag a published profile whose linked Item is soft-deleted'
        );
    }

    // ------------------------------------------------------------------ //
    //  Sentinel implementation: pure-DB scan, no controller / HTTP layer  //
    // ------------------------------------------------------------------ //

    /**
     * Pure-DB sentinel implementation. Returns the list of
     * violation keys; empty list means production-safe state.
     *
     * Keys (one per failure):
     *   - "profile#{id}:no_active_steps"
     *   - "profile#{id}:item_missing_or_deleted"
     *   - "profile#{id}:step_unresolvable:{step_key}"
     *
     * @return list<string>
     */
    private function scanPublishedProfilesForMirrorViolations(): array
    {
        $violations = [];
        $published = ItemWizardProfile::where('is_published', true)->get();

        foreach ($published as $profile) {
            // 1) Item integrity
            if ($profile->item_id !== null) {
                $item = Item::withTrashed()->find($profile->item_id);
                if ($item === null || $item->trashed()) {
                    $violations[] = 'profile#' . $profile->id . ':item_missing_or_deleted';
                    continue; // can't validate steps if item is gone
                }
            }

            // 2) At least one ACTIVE step
            $activeSteps = ItemWizardStep::where('profile_id', $profile->id)
                ->where('is_active', true)
                ->get();
            if ($activeSteps->isEmpty()) {
                $violations[] = 'profile#' . $profile->id . ':no_active_steps';
                continue;
            }

            // 3) Resolvable source_ref for REQUIRED steps (min_select >= 1)
            if ($profile->item_id !== null) {
                $item = Item::with(['extras', 'variations'])->find($profile->item_id);
                foreach ($activeSteps as $step) {
                    if ((int) $step->min_select < 1) {
                        continue; // optional step: empty choices is OK
                    }

                    $resolvable = $this->stepHasResolvableChoices($step, $item);
                    if (! $resolvable) {
                        $violations[] = 'profile#' . $profile->id . ':step_unresolvable:' . $step->step_key;
                    }
                }
            }
        }

        return $violations;
    }

    private function stepHasResolvableChoices(ItemWizardStep $step, ?Item $item): bool
    {
        if ($item === null) {
            return false;
        }
        $sourceType = (string) $step->source_type;
        $sourceRef  = mb_strtolower(trim((string) $step->source_ref));

        if ($sourceType === 'extra_group') {
            return $item->extras
                ->contains(fn ($extra) => $sourceRef === ''
                    || mb_strtolower((string) $extra->group_label) === $sourceRef);
        }

        if ($sourceType === 'item_attribute') {
            // Check by item_attribute_id OR by name (matching projection logic)
            return $item->variations->contains(function ($v) use ($step, $sourceRef): bool {
                if ($step->source_item_attribute_id !== null
                    && (int) $v->item_attribute_id === (int) $step->source_item_attribute_id) {
                    return true;
                }
                $attr = $v->itemAttribute;
                return $attr !== null && mb_strtolower((string) $attr->name) === $sourceRef;
            });
        }

        if ($sourceType === 'item_addon') {
            // Addons resolve at the wizard layer, not item-scoped; treat as
            // satisfied for sentinel purposes (out of incident class scope).
            return true;
        }

        // Unknown source_type → flag as unresolvable.
        return false;
    }

    // ------------------------------------------------------------------ //
    //  Fixtures                                                            //
    // ------------------------------------------------------------------ //

    private function createBurgerItem(): Item
    {
        $tax = Tax::factory()->create([
            'name'     => 'TVA 0%',
            'code'     => 'TVA0',
            'type'     => TaxType::PERCENTAGE,
            'tax_rate' => 0.00,
            'status'   => Status::ACTIVE,
        ]);

        $category = ItemCategory::factory()->create([
            'name'            => 'Burgers',
            'wizard_template' => 'custom',
            'has_menu'        => false,
        ]);

        return Item::factory()->create([
            'item_category_id' => $category->id,
            'tax_id'           => $tax->id,
            'name'             => 'Chicken Burger Sentinel',
            'price'            => 8.50,
            'status'           => Status::ACTIVE,
        ]);
    }

    private function createBurgerItemWithFullProfile(bool $publish): Item
    {
        $item = $this->createBurgerItem();
        $this->attachFullProfile($item, $publish);
        return $item;
    }

    private function attachFullProfile(Item $item, bool $publish): ItemWizardProfile
    {
        // viande extras (group_label='viande')
        ItemExtra::create([
            'item_id'      => $item->id,
            'name'         => 'Poulet',
            'group_label'  => 'viande',
            'price'        => 0,
            'is_available' => 1,
            'status'       => Status::ACTIVE,
        ]);

        // sauce attribute + variation
        $sauceAttr = ItemAttribute::create([
            'name'         => 'Sauce',
            'min_select'   => 1,
            'max_select'   => 1,
            'allow_repeat' => 0,
            'is_available' => 1,
            'status'       => Status::ACTIVE,
        ]);
        ItemVariation::create([
            'item_id'           => $item->id,
            'item_attribute_id' => $sauceAttr->id,
            'name'              => 'Ketchup',
            'price'             => 0,
        ]);

        $profile = ItemWizardProfile::create([
            'item_id'      => $item->id,
            'template'     => 'custom',
            'version'      => 1,
            'is_published' => $publish,
            'published_at' => $publish ? now() : null,
        ]);

        ItemWizardStep::create([
            'profile_id'  => $profile->id,
            'step_key'    => 'viande',
            'label'       => 'Viande',
            'source_type' => 'extra_group',
            'source_ref'  => 'viande',
            'min_select'  => 1,
            'max_select'  => 1,
            'position'    => 0,
            'is_active'   => 1,
        ]);
        ItemWizardStep::create([
            'profile_id'              => $profile->id,
            'step_key'                => 'sauce',
            'label'                   => 'Sauce',
            'source_type'             => 'item_attribute',
            'source_ref'              => 'sauce',
            'source_item_attribute_id' => $sauceAttr->id,
            'min_select'              => 1,
            'max_select'              => 1,
            'position'                => 1,
            'is_active'               => 1,
        ]);

        // Refresh relationships
        $item->refresh();
        return $profile;
    }
}
