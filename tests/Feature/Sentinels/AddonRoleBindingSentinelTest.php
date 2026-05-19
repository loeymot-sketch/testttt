<?php

namespace Tests\Feature\Sentinels;

use App\Enums\Status;
use App\Http\Requests\Concerns\ValidatesAddonRoles;
use App\Models\Item;
use App\Models\ItemAddon;
use App\Models\ItemCategory;
use App\Services\Pricing\CompositionSnapshotBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Validator as ValidatorContract;
use Tests\TestCase;

/**
 * Sentinel — `ValidatesAddonRoles` FormRequest trait + `CompositionSnapshotBuilder`
 * defense-in-depth pinning for RED-Z4 P0-Z4-01.
 *
 * # Attack closed
 *
 * The kiosk wizard pushes a parent menu addon row tagged with one of three
 * payload-only roles ('menu_full' / 'menu_frites' / 'menu_boisson') so
 * `PricingService::menuRoleAdjustedAddonPrice` (FROZEN §7) applies the matching
 * config('kiosk.menu_pricing') ratio (1.0 / 0.6 / 0.4). Pre-heal, any payload
 * could forward the same role on ANY addon id — drink-tagged, NULL, side, etc.
 * — and bill 60% less than catalog. Of 220 production rows on Le Cayenne, 177
 * NULL + 23 'drink' = >90% attack surface.
 *
 * Heal:
 *  - Layer 1 (primary, this test): `ValidatesAddonRoles` trait wired into
 *    OrderRequest, PosOrderRequest, Kiosk\PricingPreviewRequest — rejects
 *    422 with a `items.{i}.item_addons.{j}.role` error key.
 *  - Layer 2 (defense-in-depth, this test): `CompositionSnapshotBuilder`
 *    refuses to seal a ratio'd price into the NF525 composition_snapshot
 *    when the DB role doesn't authorize the payload role — falls back to
 *    DB role and bills catalog.
 *
 * # Frozen-zone discipline
 *
 * 0 lines of diff on `app/Services/Pricing/PricingService.php` (verified by
 * the heal commit). All gates live in non-frozen layers.
 *
 * # Semantic rule pinned
 *
 *   Payload role          | DB role (column)       | Result
 *   ----------------------|------------------------|------------------
 *   ''/null               | any                    | accept (no ratio)
 *   menu_full/frites/     | 'menu_component'       | accept (ratio)
 *   boisson               | otherwise (incl. NULL) | 422
 *   drink/side/dessert/   | exact match            | accept (no ratio)
 *   menu_component/upsell | otherwise (incl. NULL) | 422
 *   any other string      | any                    | 422 (unknown)
 *
 * @see app/Http/Requests/Concerns/ValidatesAddonRoles.php
 * @see app/Services/Pricing/CompositionSnapshotBuilder.php:130-180
 * @see app/Services/Pricing/PricingService.php:793-813 (FROZEN — read-only)
 * @see reports/audit/v1-sync-deep-audit-2026-05-19/HEAL-PLAN-D-refund-security.md §C
 * @see reports/audit/v1-sync-deep-audit-2026-05-19/RED-Z4-pricing-ssot.md §B P0-Z4-01
 */
class AddonRoleBindingSentinelTest extends TestCase
{
    use RefreshDatabase;

    private Item $parentItem;
    private Item $drinkItem;
    private Item $menuItem;
    private Item $sideItem;

    /** drink-category addon with DB role='drink'. */
    private ItemAddon $drinkAddon;

    /** menu_component-category addon (the only one eligible for ratio). */
    private ItemAddon $menuAddon;

    /** side-category addon, DB role='side'. */
    private ItemAddon $sideAddon;

    /** Legacy untagged addon (DB role NULL — 80% of production). */
    private ItemAddon $untaggedAddon;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedSpatieRoles();

        $cat = ItemCategory::factory()->create([
            'name' => 'Sentinel Cat',
        ]);

        $this->parentItem = Item::factory()->create([
            'item_category_id' => $cat->id,
            'name' => 'Parent Sandwich',
            'price' => 8.00,
            'status' => Status::ACTIVE,
        ]);
        $this->drinkItem = Item::factory()->create([
            'item_category_id' => $cat->id,
            'name' => 'Coca-Cola 33cl',
            'price' => 3.00,
            'status' => Status::ACTIVE,
        ]);
        $this->menuItem = Item::factory()->create([
            'item_category_id' => $cat->id,
            'name' => 'Menu (Frites + Boisson)',
            'price' => 3.00,
            'status' => Status::ACTIVE,
        ]);
        $this->sideItem = Item::factory()->create([
            'item_category_id' => $cat->id,
            'name' => 'Frites L',
            'price' => 2.50,
            'status' => Status::ACTIVE,
        ]);

        $this->drinkAddon = ItemAddon::query()->create([
            'item_id' => $this->parentItem->id,
            'addon_item_id' => $this->drinkItem->id,
            'role' => 'drink',
        ]);
        $this->menuAddon = ItemAddon::query()->create([
            'item_id' => $this->parentItem->id,
            'addon_item_id' => $this->menuItem->id,
            'role' => 'menu_component',
        ]);
        $this->sideAddon = ItemAddon::query()->create([
            'item_id' => $this->parentItem->id,
            'addon_item_id' => $this->sideItem->id,
            'role' => 'side',
        ]);
        $this->untaggedAddon = ItemAddon::query()->create([
            'item_id' => $this->parentItem->id,
            'addon_item_id' => $this->drinkItem->id,
            'role' => null,
        ]);
    }

    // ---- LAYER 1 — FormRequest trait validation ----

    /**
     * Primary exploit reproduction: payload role='menu_boisson' on a
     * drink-tagged addon (the most direct P0-Z4-01 attack) MUST 422.
     */
    public function test_exploit_menu_boisson_payload_role_on_drink_db_addon_is_rejected(): void
    {
        $errors = $this->runTraitValidator([
            ['item_id' => $this->parentItem->id, 'item_addons' => [
                ['id' => $this->drinkAddon->id, 'role' => 'menu_boisson'],
            ]],
        ]);

        $this->assertArrayHasKey(
            'items.0.item_addons.0.role',
            $errors,
            'AddonRoleBinding BREACH: payload menu_boisson on a drink DB addon must 422.'
        );
        $this->assertStringContainsString('menu_component', implode(' ', (array) $errors['items.0.item_addons.0.role']));
    }

    /**
     * Exploit variant: payload role='menu_boisson' on the 80%-NULL surface
     * (untagged addon) MUST 422. This is the largest attack surface on
     * Le Cayenne (177/220 = 80% rows).
     */
    public function test_exploit_menu_boisson_payload_role_on_null_db_addon_is_rejected(): void
    {
        $errors = $this->runTraitValidator([
            ['item_id' => $this->parentItem->id, 'item_addons' => [
                ['id' => $this->untaggedAddon->id, 'role' => 'menu_boisson'],
            ]],
        ]);

        $this->assertArrayHasKey(
            'items.0.item_addons.0.role',
            $errors,
            'AddonRoleBinding BREACH: payload menu_boisson on a NULL-role DB addon must 422.'
        );
    }

    /**
     * Exploit variant: payload role='menu_frites' on a side-tagged addon
     * MUST 422. Closes the 'side' (10 rows) and 'dessert' surfaces too.
     */
    public function test_exploit_menu_frites_payload_role_on_side_db_addon_is_rejected(): void
    {
        $errors = $this->runTraitValidator([
            ['item_id' => $this->parentItem->id, 'item_addons' => [
                ['id' => $this->sideAddon->id, 'role' => 'menu_frites'],
            ]],
        ]);

        $this->assertArrayHasKey(
            'items.0.item_addons.0.role',
            $errors,
            'AddonRoleBinding BREACH: payload menu_frites on a side DB addon must 422.'
        );
    }

    /**
     * Happy path: legitimate kiosk menu flow. Payload role='menu_boisson'
     * on the menu_component DB addon MUST pass. This is the captured
     * production flow (KioskWizardComponent.vue:1937-1945 +
     * test-e2e/borne E-001 fix). Rejecting this would break the V1 ship.
     */
    public function test_happy_path_menu_boisson_on_menu_component_db_addon_is_accepted(): void
    {
        $errors = $this->runTraitValidator([
            ['item_id' => $this->parentItem->id, 'item_addons' => [
                ['id' => $this->menuAddon->id, 'role' => 'menu_boisson'],
            ]],
        ]);

        $this->assertArrayNotHasKey(
            'items.0.item_addons.0.role',
            $errors,
            'AddonRoleBinding BREACH: legitimate kiosk menu_boisson on menu_component addon was rejected.'
        );
    }

    /**
     * Happy path: native DB-vocabulary payload role on a matching DB
     * addon. Mirrors KioskWizardComponent.vue:1960-1965 legacy "drink"
     * boisson push.
     */
    public function test_happy_path_drink_payload_role_on_drink_db_addon_is_accepted(): void
    {
        $errors = $this->runTraitValidator([
            ['item_id' => $this->parentItem->id, 'item_addons' => [
                ['id' => $this->drinkAddon->id, 'role' => 'drink'],
            ]],
        ]);

        $this->assertArrayNotHasKey(
            'items.0.item_addons.0.role',
            $errors,
            'AddonRoleBinding BREACH: legitimate drink payload on drink DB addon was rejected.'
        );
    }

    /**
     * Happy path: most addons go through with no payload role at all
     * (80% of production rows). MUST NOT 422.
     */
    public function test_no_payload_role_is_accepted_for_any_db_role(): void
    {
        $errors = $this->runTraitValidator([
            ['item_id' => $this->parentItem->id, 'item_addons' => [
                ['id' => $this->drinkAddon->id], // no role key
                ['id' => $this->untaggedAddon->id, 'role' => null],
                ['id' => $this->menuAddon->id, 'role' => ''],
            ]],
        ]);

        $this->assertEmpty(
            $errors,
            'AddonRoleBinding BREACH: addons without payload role were rejected (must be no-op).'
        );
    }

    /**
     * Forward-compat: an unknown role string (e.g. typo "menu_xxl") MUST
     * 422. Default-deny so a future role addition stays explicit.
     */
    public function test_unknown_payload_role_is_rejected_by_whitelist(): void
    {
        $errors = $this->runTraitValidator([
            ['item_id' => $this->parentItem->id, 'item_addons' => [
                ['id' => $this->menuAddon->id, 'role' => 'menu_xxl'],
            ]],
        ]);

        $this->assertArrayHasKey(
            'items.0.item_addons.0.role',
            $errors,
            'AddonRoleBinding BREACH: unknown role "menu_xxl" must 422 (default-deny whitelist).'
        );
    }

    /**
     * Payload supports JSON-string `items` (HTTP form contract used by
     * OrderRequest + PosOrderRequest where `items` is `required|json`).
     * Trait must decode it the same way as the array case.
     */
    public function test_trait_decodes_json_string_items_field(): void
    {
        $jsonItems = json_encode([
            ['item_id' => $this->parentItem->id, 'item_addons' => [
                ['id' => $this->drinkAddon->id, 'role' => 'menu_boisson'],
            ]],
        ]);

        $errors = $this->runTraitValidator($jsonItems);

        $this->assertArrayHasKey(
            'items.0.item_addons.0.role',
            $errors,
            'AddonRoleBinding BREACH: JSON-string items payload must be decoded and validated.'
        );
    }

    // ---- LAYER 2 — CompositionSnapshotBuilder defense-in-depth ----

    /**
     * Defense-in-depth: even if an internal caller (queue, console) bypasses
     * the FormRequest and feeds a forged payload role='menu_boisson' on a
     * drink-tagged addon to the snapshot builder, the builder MUST refuse
     * to honor the ratio and seal the catalog price into the immutable
     * NF525 composition_snapshot.
     */
    public function test_snapshot_builder_ignores_forged_menu_role_on_drink_db_addon(): void
    {
        $builder = $this->app->make(CompositionSnapshotBuilder::class);
        $snapshot = $builder->build(
            (object) [
                'item_addons' => [
                    (object) ['id' => $this->drinkAddon->id, 'role' => 'menu_boisson'],
                ],
            ],
            collect(), // dbVariations
            collect()  // dbExtras
        );

        $this->assertNotEmpty($snapshot['addons'], 'snapshot must contain the addon row');
        $addonRow = $snapshot['addons'][0];

        // The forged role must NOT have made it into the snapshot.
        $this->assertNotSame(
            'menu_boisson',
            $addonRow['role'],
            'NF525 BREACH: forged payload role menu_boisson sealed into composition_snapshot on a drink DB addon.'
        );
        // Effective role MUST be DB role ('drink') -> no ratio applied.
        $this->assertSame(
            'drink',
            $addonRow['role'],
            'Defense-in-depth: snapshot must fall back to DB role when payload role is forged.'
        );
        // Catalog price (3.00€) MUST seal — NOT the ratio'd 1.20€ (= 3.0 × 0.4).
        $this->assertEqualsWithDelta(
            3.0,
            (float) $addonRow['unit_price'],
            0.001,
            'NF525 BREACH: snapshot persisted ratio\'d price (1.20€) instead of catalog (3.00€) on forged role.'
        );
    }

    /**
     * Defense-in-depth: forged payload role='menu_boisson' on a NULL-role
     * DB addon (the 80% surface). Snapshot MUST seal catalog price.
     */
    public function test_snapshot_builder_ignores_forged_menu_role_on_null_db_addon(): void
    {
        $builder = $this->app->make(CompositionSnapshotBuilder::class);
        $snapshot = $builder->build(
            (object) [
                'item_addons' => [
                    (object) ['id' => $this->untaggedAddon->id, 'role' => 'menu_boisson'],
                ],
            ],
            collect(),
            collect()
        );

        $addonRow = $snapshot['addons'][0];
        $this->assertNull(
            $addonRow['role'],
            'Defense-in-depth: snapshot must fall back to DB role (NULL) when payload role is forged on an untagged addon.'
        );
        // 3.00€ catalog, no ratio.
        $this->assertEqualsWithDelta(
            3.0,
            (float) $addonRow['unit_price'],
            0.001,
            'NF525 BREACH: snapshot persisted ratio\'d price on a NULL-role addon.'
        );
    }

    /**
     * Defense-in-depth: legitimate kiosk flow (payload menu_boisson +
     * DB menu_component) MUST still produce the ratio'd snapshot price.
     */
    public function test_snapshot_builder_honors_menu_role_on_menu_component_db_addon(): void
    {
        $builder = $this->app->make(CompositionSnapshotBuilder::class);
        $snapshot = $builder->build(
            (object) [
                'item_addons' => [
                    (object) ['id' => $this->menuAddon->id, 'role' => 'menu_boisson'],
                ],
            ],
            collect(),
            collect()
        );

        $addonRow = $snapshot['addons'][0];
        // The payload role wins on the menu_component DB addon.
        $this->assertSame(
            'menu_boisson',
            $addonRow['role'],
            'Snapshot must honor menu_boisson payload role on menu_component DB addon (legitimate kiosk flow).'
        );
        // Ratio applied: 3.0 × 0.4 = 1.20.
        $this->assertEqualsWithDelta(
            1.20,
            (float) $addonRow['unit_price'],
            0.001,
            'Snapshot must persist ratio\'d price (1.20€) on legitimate menu_component + menu_boisson combo.'
        );
    }

    // ---- Helpers ----

    /**
     * Build a Laravel Validator that uses the trait the way the real
     * FormRequest does (via $validator->after()). We mirror the exact
     * codepath of `ValidatesAddonRoles::validateAddonRolesAfter` so the
     * test exercises the production class.
     *
     * @param  array|string  $items  the `items` payload (array or JSON string)
     * @return array<string, array<int, string>> validator->errors()->messages()
     */
    private function runTraitValidator($items): array
    {
        $validator = Validator::make(['items' => $items], []);

        $harness = new class () {
            use ValidatesAddonRoles {
                validateAddonRolesAfter as public;
            }

            public mixed $items = null;

            public function input(string $key, $default = null): mixed
            {
                return $key === 'items' ? $this->items : $default;
            }
        };
        $harness->items = $items;

        $harness->validateAddonRolesAfter($validator);

        return $validator->errors()->messages();
    }
}
