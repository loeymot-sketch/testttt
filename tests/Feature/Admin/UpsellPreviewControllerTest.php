<?php

namespace Tests\Feature\Admin;

use App\Enums\Status;
use App\Models\Branch;
use App\Models\Item;
use App\Models\ItemBranchAvailability;
use App\Models\ItemCategory;
use App\Models\Tax;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * V2-3 Phase A — Admin upsell preview tool tests.
 *
 * Couvre :
 *   - admin (permission settings) peut prévisualiser avec rule_based
 *   - admin peut prévisualiser avec ml_placeholder (délègue à rule_based)
 *   - staff sans `settings` reçoit 403
 *   - latency_ms est mesurée et présente dans la réponse
 *
 * Voir plans/PLAN_DESIGN_V2_3_AI_UPSELL_2026-05-08.md (preview tool).
 */
class UpsellPreviewControllerTest extends TestCase
{
    use RefreshDatabase;

    private const HEADER_KEY = 'x-api-key';

    private Branch $branch;
    private ItemCategory $catBurgers;
    private ItemCategory $catSides;
    private Item $burger;
    private Item $fries;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();
        $this->seedSpatieRoles();

        $this->branch = Branch::forceCreate([
            'name' => 'Branch Preview', 'city' => 'Paris', 'state' => 'IDF',
            'zip_code' => '75001', 'address' => '1 rue test', 'status' => 1,
            'available_locales' => ['fr', 'en'],
        ]);

        $tax = Tax::forceCreate([
            'name' => 'TVA 10', 'code' => 'TVA10',
            'tax_rate' => 10, 'type' => 2, 'status' => 1,
        ]);

        $this->catBurgers = ItemCategory::forceCreate([
            'name' => 'Burgers', 'slug' => 'burgers-' . uniqid(),
            'status' => Status::ACTIVE, 'sort' => 1, 'channels' => ['kiosk'],
        ]);
        $this->catSides = ItemCategory::forceCreate([
            'name' => 'Sides', 'slug' => 'sides-' . uniqid(),
            'status' => Status::ACTIVE, 'sort' => 2, 'channels' => ['kiosk'],
        ]);

        $this->burger = Item::forceCreate([
            'name' => 'Big Burger', 'slug' => 'big-burger-' . uniqid(),
            'item_category_id' => $this->catBurgers->id, 'tax_id' => $tax->id,
            'item_type' => 1, 'price' => 9.50, 'status' => Status::ACTIVE,
            'order' => 1, 'channels' => ['kiosk'],
        ]);

        $this->fries = Item::forceCreate([
            'name' => 'Fries M', 'slug' => 'fries-m-' . uniqid(),
            'item_category_id' => $this->catSides->id, 'tax_id' => $tax->id,
            'item_type' => 1, 'price' => 2.50, 'status' => Status::ACTIVE,
            'order' => 1, 'channels' => ['kiosk'],
        ]);

        foreach ([$this->burger, $this->fries] as $i) {
            ItemBranchAvailability::create([
                'item_id' => $i->id, 'branch_id' => $this->branch->id,
                'is_available' => true,
                'daily_reset_at' => now()->toDateString(),
            ]);
        }
    }

    private function actingAsAdmin(int $branchId = 0): User
    {
        $admin = User::factory()->create(['branch_id' => $branchId]);
        $admin->assignRole('Admin');
        Sanctum::actingAs($admin, ['*']);
        return $admin;
    }

    private function withApiKey(): self
    {
        return $this->withHeader(
            self::HEADER_KEY,
            config('app.api_key', env('MIX_API_KEY', 'test-api-key'))
        );
    }

    public function test_admin_can_preview_with_rule_based_strategy(): void
    {
        $this->actingAsAdmin();

        $response = $this->withApiKey()
            ->postJson('/api/admin/upsell-preview', [
                'cart_items' => [
                    ['item_id' => $this->burger->id, 'quantity' => 1, 'price' => 9.50],
                ],
                'branch_id' => $this->branch->id,
                'strategy'  => 'rule_based',
            ]);

        $response->assertOk();
        $response->assertJsonStructure([
            'recommendations',
            'latency_ms',
            'strategy_used',
            'cart_size',
        ]);
        $response->assertJsonPath('strategy_used', 'rule_based');
        $response->assertJsonPath('cart_size', 1);
    }

    public function test_admin_can_preview_with_ml_placeholder_strategy(): void
    {
        $this->actingAsAdmin();

        $response = $this->withApiKey()
            ->postJson('/api/admin/upsell-preview', [
                'cart_items' => [
                    ['item_id' => $this->burger->id, 'quantity' => 1, 'price' => 9.50],
                ],
                'branch_id' => $this->branch->id,
                'strategy'  => 'ml_placeholder',
            ]);

        $response->assertOk();
        $response->assertJsonPath('strategy_used', 'ml_placeholder');
        $response->assertJsonPath('cart_size', 1);
    }

    public function test_admin_default_strategy_is_rule_based(): void
    {
        $this->actingAsAdmin();

        $response = $this->withApiKey()
            ->postJson('/api/admin/upsell-preview', [
                'cart_items' => [
                    ['item_id' => $this->burger->id, 'quantity' => 1],
                ],
                'branch_id' => $this->branch->id,
            ]);

        $response->assertOk();
        $response->assertJsonPath('strategy_used', 'rule_based');
    }

    public function test_staff_cannot_access_upsell_preview(): void
    {
        // Stuff role explicitly does NOT have 'settings' (cf. seedSpatieRoles).
        $stuff = User::factory()->create(['branch_id' => 0]);
        $stuff->assignRole('Stuff');
        Sanctum::actingAs($stuff, ['*']);

        $response = $this->withApiKey()
            ->postJson('/api/admin/upsell-preview', [
                'cart_items' => [
                    ['item_id' => $this->burger->id, 'quantity' => 1],
                ],
                'branch_id' => $this->branch->id,
            ]);

        $response->assertStatus(403);
    }

    public function test_preview_validates_invalid_strategy_returns_422(): void
    {
        $this->actingAsAdmin();

        $response = $this->withApiKey()
            ->postJson('/api/admin/upsell-preview', [
                'cart_items' => [
                    ['item_id' => $this->burger->id, 'quantity' => 1],
                ],
                'branch_id' => $this->branch->id,
                'strategy'  => 'evil_class_injection_attempt',
            ]);

        $response->assertStatus(422);
    }

    public function test_preview_validates_empty_cart_returns_422(): void
    {
        $this->actingAsAdmin();

        $response = $this->withApiKey()
            ->postJson('/api/admin/upsell-preview', [
                'cart_items' => [],
                'branch_id' => $this->branch->id,
            ]);

        $response->assertStatus(422);
    }

    public function test_preview_returns_latency_measurement(): void
    {
        $this->actingAsAdmin();

        $response = $this->withApiKey()
            ->postJson('/api/admin/upsell-preview', [
                'cart_items' => [
                    ['item_id' => $this->burger->id, 'quantity' => 1, 'price' => 9.50],
                ],
                'branch_id' => $this->branch->id,
                'strategy'  => 'rule_based',
            ]);

        $response->assertOk();
        $latency = $response->json('latency_ms');
        $this->assertIsNumeric($latency);
        $this->assertGreaterThanOrEqual(0, $latency);
        // Realistic ceiling for SQLite + cold-start CI; production target is
        // <200ms but this contract is "we measure it and surface it".
        $this->assertLessThan(5000, $latency, 'Latency should be sub-5s even under SQLite + cold-start.');
    }
}
