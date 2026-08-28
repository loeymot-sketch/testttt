<?php

namespace Tests\Feature\Items;

use App\Enums\Ask;
use App\Enums\ItemType;
use App\Enums\Status;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Tax;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Sentinel — Item create contract: Catalog Studio quick-create vs legacy create payload (CV1-V2-CATALOG-REWORK-001 β-PRE-S1).
 *
 * Quick payload mirrors CatalogStudioComponent::buildQuickProductPayload (JSON equivalent, no image).
 * Full payload mirrors ItemCreateComponent::save FormData fields plus optional ItemRequest fields
 * (channels, flags) to exercise the wider contract; legacy list UX uses ItemCreateComponent drawer.
 */
class ItemCreateContractTest extends TestCase
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

    // [ONB-02 T-2.1.3 2026-08-27] tax_id est désormais obligatoire (article sans taxe = facturé 0 % en silence)
    private function taxeDeTest(): Tax
    {
        return Tax::firstOrCreate(
            ['code' => 'TEST-VAT-10'],
            ['name' => 'TVA 10 % (test)', 'tax_rate' => 10,
             'type' => \App\Enums\TaxType::PERCENTAGE, 'status' => Status::ACTIVE]
        );
    }

    public function test_quick_payload_from_catalog_studio_creates_valid_item(): void
    {
        $admin = $this->adminWithItemPerms();
        Sanctum::actingAs($admin, ['*']);

        $category = ItemCategory::create([
            'name'   => 'Studio Cat ' . uniqid(),
            'slug'   => 'studio-cat-' . uniqid(),
            'status' => Status::ACTIVE,
        ]);

        // Mirrors CatalogStudioComponent::buildQuickProductPayload (no file upload).
        $payload = [
            'name'             => 'Quick Studio Item ' . uniqid('', true),
            'price'            => 12.50,
            'description'      => 'Quick description',
            'caution'          => '',
            'is_featured'      => Ask::YES,
            'is_upsell'        => Ask::NO,
            'item_type'        => ItemType::VEG,
            'item_category_id' => $category->id,
            'tax_id'           => $this->taxeDeTest()->id,
            'status'           => Status::ACTIVE,
            'order'            => 42,
        ];

        $response = $this->postJson('/api/admin/item', $payload);
        $response->assertCreated();

        $itemId = (int) $response->json('data.id');
        $this->assertGreaterThan(0, $itemId);
        $this->assertNotNull(Item::query()->find($itemId));
    }

    public function test_full_payload_from_legacy_item_list_creates_valid_item(): void
    {
        $admin = $this->adminWithItemPerms();
        Sanctum::actingAs($admin, ['*']);

        $category = ItemCategory::create([
            'name'   => 'Legacy Cat ' . uniqid(),
            'slug'   => 'legacy-cat-' . uniqid(),
            'status' => Status::ACTIVE,
        ]);

        $tax = Tax::factory()->create(['status' => Status::ACTIVE]);

        // Legacy ItemCreateComponent::save core fields + extended ItemRequest options.
        $payload = [
            'name'             => 'Full Legacy Item ' . uniqid('', true),
            'price'            => 24.99,
            'item_category_id' => $category->id,
            'tax_id'           => $tax->id,
            'item_type'        => ItemType::NON_VEG,
            'is_featured'      => Ask::NO,
            'is_upsell'        => Ask::YES,
            'description'      => 'Full description',
            'caution'          => 'May contain traces of nuts.',
            'order'            => 1,
            'status'           => Status::ACTIVE,
            'is_chef_pick'     => true,
            'is_new'           => true,
            'is_available'     => true,
            'is_spicy'         => true,
            'is_vegetarian'    => false,
            'is_pork_free'     => true,
            'is_halal'         => false,
            'is_gluten_free'   => false,
            'chef_pick_order'  => 3,
            'channels'         => ['kiosk', 'pos', 'web'],
            'kiosk_emoji'      => '🍔',
        ];

        $response = $this->postJson('/api/admin/item', $payload);
        $response->assertCreated();

        $itemId = (int) $response->json('data.id');
        $this->assertGreaterThan(0, $itemId);
        $item = Item::query()->find($itemId);
        $this->assertNotNull($item);
    }
}
