<?php

namespace Tests\Feature\Abuse;

use App\Enums\Status;
use App\Models\Item;
use App\Models\ItemAttribute;
use App\Models\ItemCategory;
use App\Models\ItemExtra;
use App\Models\ItemVariation;
use App\Models\Tax;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * ABUSE #1 — nested variation/extra creation bypassed validation (P2, money-adjacent).
 *
 * ItemService::store()/update() built nested variations/extras straight from the
 * request `variations`/`extras` JSON blob WITHOUT validating each object. The
 * ItemRequest only typed those fields as ['nullable','json'] and its
 * withValidator() checked ONLY `visible_on`. So a variation/extra with
 * price = -5.00 (or null) persisted and then fed PricingService — yielding a
 * wrong (negative-modifier) POS/Kiosk price.
 *
 * The dedicated POST /variation/{item} + POST /extra/{item} endpoints are
 * already guarded (ItemVariationRequest / ItemExtraRequest via IniAmount), so
 * this targets the UNGUARDED bulk-nested path on POST /item/.
 *
 * @see app/Http/Requests/ItemRequest.php
 * @see app/Services/ItemService.php
 */
class CatalogNestedPriceValidationAbuseTest extends TestCase
{
    use RefreshDatabase;

    private ItemCategory $category;
    private Tax $tax;
    private ItemAttribute $attribute;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();
        $this->seedSpatieRoles();

        $admin = User::factory()->create(['branch_id' => 0]);
        $admin->assignRole('Admin');
        Sanctum::actingAs($admin, ['*']);

        $this->category = ItemCategory::factory()->create();
        $this->tax = Tax::factory()->create();
        $this->attribute = ItemAttribute::factory()->create();
    }

    /** A fully-valid item payload; pass a `variations`/`extras` JSON string to override. */
    private function itemPayload(array $overrides = []): array
    {
        return array_merge([
            'name'             => 'Abuse Burger ' . uniqid(),
            'item_category_id' => $this->category->id,
            'tax_id'           => $this->tax->id,
            'item_type'        => 1,
            'price'            => 10.00,
            'is_featured'      => 1,
            'status'           => Status::ACTIVE,
            'order'            => 1,
        ], $overrides);
    }

    /** @test */
    public function it_rejects_a_nested_variation_with_a_negative_price(): void
    {
        $payload = $this->itemPayload([
            'variations' => json_encode([
                [
                    'name'              => 'Maxi',
                    'item_attribute_id' => $this->attribute->id,
                    'price'             => -5.00, // <-- the abuse
                    'status'            => Status::ACTIVE,
                ],
            ]),
        ]);

        $response = $this->postJson('/api/admin/item', $payload);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['variations.0.price']);

        // Nothing must have been persisted — neither the item nor the poisoned variation.
        $this->assertDatabaseCount('item_variations', 0);
        $this->assertDatabaseMissing('items', ['name' => $payload['name']]);
    }

    /** @test */
    public function it_rejects_a_nested_extra_with_a_negative_price(): void
    {
        $payload = $this->itemPayload([
            'extras' => json_encode([
                ['name' => 'Bacon', 'price' => -2.50, 'status' => Status::ACTIVE],
            ]),
        ]);

        $response = $this->postJson('/api/admin/item', $payload);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['extras.0.price']);

        $this->assertDatabaseCount('item_extras', 0);
        $this->assertDatabaseMissing('items', ['name' => $payload['name']]);
    }

    /** @test */
    public function it_rejects_a_nested_variation_with_a_non_numeric_price(): void
    {
        $payload = $this->itemPayload([
            'variations' => json_encode([
                [
                    'name'              => 'Bogus',
                    'item_attribute_id' => $this->attribute->id,
                    'price'             => 'free',
                    'status'            => Status::ACTIVE,
                ],
            ]),
        ]);

        $response = $this->postJson('/api/admin/item', $payload);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['variations.0.price']);
        $this->assertDatabaseCount('item_variations', 0);
    }

    /** @test */
    public function it_rejects_a_nested_variation_missing_its_name(): void
    {
        $payload = $this->itemPayload([
            'variations' => json_encode([
                [
                    'item_attribute_id' => $this->attribute->id,
                    'price'             => 3.00,
                    'status'            => Status::ACTIVE,
                ],
            ]),
        ]);

        $response = $this->postJson('/api/admin/item', $payload);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['variations.0.name']);
        $this->assertDatabaseCount('item_variations', 0);
    }

    /** @test */
    public function it_accepts_a_valid_nested_variation_and_extra(): void
    {
        $payload = $this->itemPayload([
            'variations' => json_encode([
                [
                    'name'              => 'Maxi',
                    'item_attribute_id' => $this->attribute->id,
                    'price'             => 2.50,
                    'status'            => Status::ACTIVE,
                ],
            ]),
            'extras' => json_encode([
                ['name' => 'Cheddar', 'price' => 1.00, 'status' => Status::ACTIVE],
            ]),
        ]);

        $response = $this->postJson('/api/admin/item', $payload);

        // 200/201 created — the happy path must still work.
        $this->assertContains($response->status(), [200, 201], 'Valid nested objects must be accepted. Body: ' . $response->getContent());
        $this->assertDatabaseHas('item_variations', ['name' => 'Maxi', 'price' => 2.50]);
        $this->assertDatabaseHas('item_extras', ['name' => 'Cheddar', 'price' => 1.00]);
    }

    /** @test */
    public function it_accepts_a_nested_variation_priced_at_zero(): void
    {
        // A zero-priced variation is legitimate (no modifier delta); must NOT be rejected.
        $payload = $this->itemPayload([
            'variations' => json_encode([
                [
                    'name'              => 'Standard',
                    'item_attribute_id' => $this->attribute->id,
                    'price'             => 0,
                    'status'            => Status::ACTIVE,
                ],
            ]),
        ]);

        $response = $this->postJson('/api/admin/item', $payload);

        $this->assertContains($response->status(), [200, 201], 'A zero-priced variation must be accepted. Body: ' . $response->getContent());
        $this->assertDatabaseHas('item_variations', ['name' => 'Standard']);
    }
}
