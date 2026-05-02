<?php

namespace Tests\Feature\Catalog;

use App\Enums\Status;
use App\Models\Item;
use App\Models\ItemCategory;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Sentinel — Mission #1 Vague 1 action 1.4.
 *
 * Asserts ItemService emits [catalog.channels-null] when channels=NULL,
 * and ItemController::show exposes CatalogWarningService channels_null
 * when catalog_v15.warnings.expose_to_admin_show is enabled.
 *
 * Category API logging is out of scope for task 1.4 (authorized files:
 * ItemService + ItemController only for HTTP item surface).
 *
 * Audit: reports/audit/CLAUDE_ULTRA_REVIEW_REQUEST_MISSION_1_CATALOG_SYNC_2026-05-02.md
 * Plan : plans/PLAN_CV1-CATALOG-CONVERGENCE-001_2026-05-02.md task 1.4
 */
class ChannelsNullWarningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();
        $this->seedSpatieRoles();
        foreach (['items', 'items_create', 'items_edit', 'items_show'] as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'sanctum']);
        }
    }

    private function adminWithItemPerms(): \App\Models\User
    {
        $admin = \Database\Factories\UserFactory::new()->create(['branch_id' => 0]);
        $admin->assignRole('Admin');
        $admin->givePermissionTo(['items', 'items_create', 'items_edit', 'items_show']);

        return $admin;
    }

    private function baseItemPayload(ItemCategory $category, array $overrides = []): array
    {
        return array_merge([
            'name'             => 'Channel Test Item ' . uniqid('', true),
            'item_category_id' => $category->id,
            'item_type'        => 1,
            'price'            => 10.00,
            'is_featured'      => 1,
            'status'           => Status::ACTIVE,
            'order'            => 1,
        ], $overrides);
    }

    public function test_item_store_with_null_channels_emits_warning(): void
    {
        Log::spy();
        config(['catalog_v15.channels_filter.warn_on_null' => true]);

        $admin = $this->adminWithItemPerms();
        Sanctum::actingAs($admin, ['*']);

        $category = ItemCategory::create([
            'name'   => 'Cat A',
            'slug'   => 'cat-a-' . uniqid(),
            'status' => Status::ACTIVE,
        ]);

        $payload = $this->baseItemPayload($category, ['channels' => null]);

        $response = $this->postJson('/api/admin/item', $payload);
        $response->assertStatus(201);

        $itemId = (int) $response->json('data.id');
        $this->assertGreaterThan(0, $itemId);

        Log::shouldHaveReceived('warning')
            ->once()
            ->with(
                '[catalog.channels-null]',
                Mockery::on(function (array $context) use ($itemId, $admin): bool {
                    return ($context['item_id'] ?? null) === $itemId
                        && ($context['user_id'] ?? null) === $admin->id
                        && ($context['action'] ?? null) === 'store';
                })
            );
    }

    public function test_item_update_with_null_channels_emits_warning(): void
    {
        Log::spy();
        config(['catalog_v15.channels_filter.warn_on_null' => true]);

        $admin = $this->adminWithItemPerms();
        Sanctum::actingAs($admin, ['*']);

        $category = ItemCategory::create([
            'name'   => 'Cat B',
            'slug'   => 'cat-b-' . uniqid(),
            'status' => Status::ACTIVE,
        ]);

        $item = Item::create([
            'name'             => 'Upd Item',
            'slug'             => 'upd-item-' . uniqid(),
            'item_category_id' => $category->id,
            'item_type'        => 1,
            'price'            => 10.00,
            'is_featured'      => 1,
            'status'           => Status::ACTIVE,
            'order'            => 1,
            'channels'         => ['pos'],
        ]);

        $payload = $this->baseItemPayload($category, [
            'name'     => 'Upd Item',
            'channels' => null,
        ]);

        $response = $this->postJson("/api/admin/item/{$item->id}", $payload);
        $response->assertSuccessful();

        Log::shouldHaveReceived('warning')
            ->once()
            ->with(
                '[catalog.channels-null]',
                Mockery::on(function (array $context) use ($item, $admin): bool {
                    return (int) ($context['item_id'] ?? 0) === (int) $item->id
                        && ($context['user_id'] ?? null) === $admin->id
                        && ($context['action'] ?? null) === 'update';
                })
            );
    }

    public function test_item_store_with_non_null_channels_does_not_emit_channels_null_log(): void
    {
        Log::spy();
        config(['catalog_v15.channels_filter.warn_on_null' => true]);

        $admin = $this->adminWithItemPerms();
        Sanctum::actingAs($admin, ['*']);

        $category = ItemCategory::create([
            'name'   => 'Cat C',
            'slug'   => 'cat-c-' . uniqid(),
            'status' => Status::ACTIVE,
        ]);

        $payload = $this->baseItemPayload($category, ['channels' => ['pos']]);

        $response = $this->postJson('/api/admin/item', $payload);
        $response->assertStatus(201);

        Log::shouldNotHaveReceived('warning', ['[catalog.channels-null]', Mockery::type('array')]);
    }

    public function test_warning_disabled_when_flag_off(): void
    {
        Log::spy();
        config(['catalog_v15.channels_filter.warn_on_null' => false]);

        $admin = $this->adminWithItemPerms();
        Sanctum::actingAs($admin, ['*']);

        $category = ItemCategory::create([
            'name'   => 'Cat D',
            'slug'   => 'cat-d-' . uniqid(),
            'status' => Status::ACTIVE,
        ]);

        $payload = $this->baseItemPayload($category, ['channels' => null]);

        $response = $this->postJson('/api/admin/item', $payload);
        $response->assertStatus(201);

        Log::shouldNotHaveReceived('warning', ['[catalog.channels-null]', Mockery::type('array')]);
    }

    public function test_admin_show_includes_channels_null_warning_when_expose_enabled(): void
    {
        config([
            'catalog_v15.warnings.expose_to_admin_show' => true,
        ]);

        $admin = $this->adminWithItemPerms();
        Sanctum::actingAs($admin, ['*']);

        $category = ItemCategory::create([
            'name'   => 'Cat E',
            'slug'   => 'cat-e-' . uniqid(),
            'status' => Status::ACTIVE,
        ]);

        $item = Item::create([
            'name'             => 'Null Chan Show',
            'slug'             => 'null-chan-show-' . uniqid(),
            'item_category_id' => $category->id,
            'item_type'        => 1,
            'price'            => 10.00,
            'is_featured'      => 1,
            'status'           => Status::ACTIVE,
            'order'            => 1,
            'channels'         => null,
        ]);

        $response = $this->getJson("/api/admin/item/show/{$item->id}");
        $response->assertSuccessful();

        $warnings = $response->json('warnings');
        $this->assertIsArray($warnings);
        $codes = array_column($warnings, 'code');
        $this->assertContains('channels_null', $codes);
    }

    public function test_admin_show_warnings_empty_or_absent_for_explicit_channels_when_expose_enabled(): void
    {
        config([
            'catalog_v15.warnings.expose_to_admin_show' => true,
        ]);

        $admin = $this->adminWithItemPerms();
        Sanctum::actingAs($admin, ['*']);

        $category = ItemCategory::create([
            'name'   => 'Cat F',
            'slug'   => 'cat-f-' . uniqid(),
            'status' => Status::ACTIVE,
        ]);

        $item = Item::create([
            'name'             => 'Pos Only Show',
            'slug'             => 'pos-only-show-' . uniqid(),
            'item_category_id' => $category->id,
            'item_type'        => 1,
            'price'            => 10.00,
            'is_featured'      => 1,
            'status'           => Status::ACTIVE,
            'order'            => 1,
            'channels'         => ['pos'],
        ]);

        $response = $this->getJson("/api/admin/item/show/{$item->id}");
        $response->assertSuccessful();

        $warnings = $response->json('warnings');
        $this->assertIsArray($warnings);
        foreach ($warnings as $w) {
            $this->assertNotSame('channels_null', $w['code'] ?? null);
        }
    }

    public function test_admin_show_omits_warnings_key_when_expose_disabled(): void
    {
        config([
            'catalog_v15.warnings.expose_to_admin_show' => false,
        ]);

        $admin = $this->adminWithItemPerms();
        Sanctum::actingAs($admin, ['*']);

        $category = ItemCategory::create([
            'name'   => 'Cat G',
            'slug'   => 'cat-g-' . uniqid(),
            'status' => Status::ACTIVE,
        ]);

        $item = Item::create([
            'name'             => 'No Expose',
            'slug'   => 'no-expose-' . uniqid(),
            'item_category_id' => $category->id,
            'item_type'        => 1,
            'price'            => 10.00,
            'is_featured'      => 1,
            'status'           => Status::ACTIVE,
            'order'            => 1,
            'channels'         => null,
        ]);

        $response = $this->getJson("/api/admin/item/show/{$item->id}");
        $response->assertSuccessful();
        $this->assertArrayNotHasKey('warnings', $response->json());
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
