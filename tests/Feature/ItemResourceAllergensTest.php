<?php

namespace Tests\Feature;

use App\Enums\Status;
use App\Models\Allergen;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [POS-9.1.14] Admin ItemResource exposes allergens (POS-GA-F-36).
 *
 * Without this projection, the cashier — and the admin item editor — had
 * no allergen signal. FIC compliance (UE 1169/2011) and EAA 2025 require
 * any food-information surface to be able to render the allergen list.
 */
class ItemResourceAllergensTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_item_show_returns_allergens_array(): void
    {
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        $cat = ItemCategory::factory()->create([
            'has_menu' => true,
            'wizard_template' => 'tacos',
        ]);

        // Allergen rows MUST exist before the Item is persisted, otherwise
        // ItemObserver::saving() → AllergenService::projectFlags() will
        // resolve the `allergen_flags` JSON against an empty allergens table
        // and silently clear the user-supplied codes.
        $gluten  = Allergen::create(['code' => 'gluten',  'name_key' => 'allergen.gluten',  'icon' => null, 'sort' => 1]);
        $lait    = Allergen::create(['code' => 'lait',    'name_key' => 'allergen.lait',    'icon' => null, 'sort' => 2]);
        $sesame  = Allergen::create(['code' => 'sesame',  'name_key' => 'allergen.sesame',  'icon' => null, 'sort' => 3]);

        $item = Item::factory()->create([
            'item_category_id' => $cat->id,
            'name' => 'Tacos M',
            'price' => 6.50,
            'status' => Status::ACTIVE,
            'allergen_flags' => ['gluten', 'lait'],
        ]);

        // Use sync() instead of attach(): the ItemObserver saving hook has
        // already attached gluten/lait via AllergenService::projectFlags(),
        // and attach() would raise a UNIQUE(item_id, allergen_id) violation.
        // sync() overwrites with the exact pivot state the test cares about
        // (including sesame as a trace allergen).
        $item->allergens()->sync([
            $gluten->id => ['is_trace' => false],
            $lait->id   => ['is_trace' => false],
            $sesame->id => ['is_trace' => true],
        ]);

        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        $this->actingAs($admin, 'sanctum');

        $resp = $this->withHeader('x-api-key', config('app.api_key'))
            ->getJson('/api/admin/item/show/' . $item->id);

        $resp->assertStatus(200);
        $payload = $resp->json('data');

        $this->assertArrayHasKey('allergens', $payload, 'admin ItemResource must expose `allergens` (POS-GA-F-36)');
        $this->assertIsArray($payload['allergens']);
        $this->assertCount(3, $payload['allergens']);

        $byCode = collect($payload['allergens'])->keyBy('code');
        $this->assertEqualsCanonicalizing(['gluten', 'lait', 'sesame'], $byCode->keys()->all());

        $this->assertSame('allergen.gluten', $byCode['gluten']['name_key']);
        $this->assertFalse($byCode['gluten']['is_trace']);
        $this->assertTrue($byCode['sesame']['is_trace'], 'is_trace flag must round-trip through the resource.');

        // allergen_flags JSON cache also exposed for back-compat consumers.
        $this->assertArrayHasKey('allergen_flags', $payload);
        $this->assertEqualsCanonicalizing(['gluten', 'lait'], $payload['allergen_flags']);
    }

    public function test_item_with_no_allergens_returns_empty_array(): void
    {
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        $cat = ItemCategory::factory()->create(['has_menu' => true, 'wizard_template' => 'tacos']);
        $item = Item::factory()->create([
            'item_category_id' => $cat->id,
            'price' => 5.00,
            'status' => Status::ACTIVE,
            'allergen_flags' => [],
        ]);

        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        $this->actingAs($admin, 'sanctum');

        $resp = $this->withHeader('x-api-key', config('app.api_key'))
            ->getJson('/api/admin/item/show/' . $item->id);

        $resp->assertStatus(200);
        $payload = $resp->json('data');
        $this->assertArrayHasKey('allergens', $payload);
        $this->assertSame([], $payload['allergens']);
    }
}
