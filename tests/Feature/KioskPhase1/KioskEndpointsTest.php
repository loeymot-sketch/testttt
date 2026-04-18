<?php

namespace Tests\Feature\KioskPhase1;

use App\Enums\Ask;
use App\Enums\Status;
use App\Models\Branch;
use App\Models\Coupon;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\ItemExtra;
use App\Models\ItemVariation;
use App\Models\KioskMachine;
use App\Models\KioskPromo;
use App\Models\Tax;
use App\Models\UpsellRule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Kiosk Design V1 — Phase 1.10
 *
 * Suite d'intégration des 4 endpoints Phase 1 :
 *   GET  /api/frontend/menu
 *   POST /api/frontend/pricing/preview
 *   POST /api/frontend/promo/validate
 *   GET  /api/frontend/upsell
 *
 * Invariants vérifiés :
 *   - Auth Sanctum ability kiosk:order obligatoire
 *   - branch_id serveur (via KioskMachine), jamais payload
 *   - SSOT prix (prix clients ignorés, recalc serveur)
 *   - Kiosk promo branch-scoped, fallback coupons globaux
 *   - Upsell via upsell_rules, fallback legacy
 */
class KioskEndpointsTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;
    private User $user;
    private string $token;
    private Item $cayenne;
    private Item $fries;
    private ItemExtra $cheddar;
    private ItemVariation $sizeXL;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();

        $this->branch = Branch::forceCreate([
            'name' => 'Aubervilliers', 'city' => 'Aubervilliers', 'state' => 'IDF',
            'zip_code' => '93300', 'address' => '1 rue test', 'status' => 1,
            'available_locales' => ['fr', 'en', 'ar'],
        ]);

        $tax = Tax::create(['name' => 'TVA 10', 'code' => 'TVA10', 'tax_rate' => 10, 'type' => 2, 'status' => 1]);

        // FK parent requise pour item_variations.item_attribute_id
        $attrId = \DB::table('item_attributes')->insertGetId([
            'name' => 'Taille', 'status' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $cat = ItemCategory::forceCreate([
            'name' => 'Burgers', 'slug' => 'burgers-'.uniqid(),
            'description' => null, 'status' => Status::ACTIVE, 'sort' => 1,
            'channels' => ['kiosk'],
        ]);

        $this->cayenne = Item::forceCreate([
            'name' => 'Cayenne XXL', 'slug' => 'cayenne-'.uniqid(),
            'item_category_id' => $cat->id, 'tax_id' => $tax->id,
            'item_type' => 1, 'price' => 9.90,
            'status' => Status::ACTIVE, 'order' => 1, 'is_featured' => 1,
            'is_upsell' => 0, 'is_chef_pick' => true,
            'channels' => ['kiosk'], 'kiosk_emoji' => '🍔',
        ]);

        $catFries = ItemCategory::forceCreate([
            'name' => 'Sides', 'slug' => 'sides-'.uniqid(),
            'description' => null, 'status' => Status::ACTIVE, 'sort' => 2,
            'channels' => ['kiosk'],
        ]);
        $this->fries = Item::forceCreate([
            'name' => 'Frites Cheddar', 'slug' => 'fries-'.uniqid(),
            'item_category_id' => $catFries->id, 'tax_id' => $tax->id,
            'item_type' => 1, 'price' => 3.50,
            'status' => Status::ACTIVE, 'order' => 1, 'is_featured' => 0,
            'is_upsell' => 1, 'is_chef_pick' => false,
            'channels' => ['kiosk'],
        ]);

        $this->sizeXL = ItemVariation::create([
            'item_id' => $this->cayenne->id, 'item_attribute_id' => $attrId,
            'name' => 'XL', 'price' => 1.50, 'status' => Status::ACTIVE,
            'visible_on' => ['kiosk'],
        ]);

        $this->cheddar = ItemExtra::create([
            'item_id' => $this->cayenne->id,
            'name' => 'Cheddar', 'price' => 1.00, 'status' => Status::ACTIVE,
            'visible_on' => ['kiosk'], 'group_label' => 'supplements',
        ]);

        $this->user = User::factory()->create([
            'username' => 'kiosk_'.uniqid(),
            'branch_id' => $this->branch->id,
        ]);
        KioskMachine::create([
            'machine_id' => 'test-'.uniqid(),
            'branch_id' => $this->branch->id,
            'user_id' => $this->user->id,
            'username' => 'kiosk-test',
            'password' => bcrypt('x'),
            'is_login' => Ask::NO,
            'status' => Status::ACTIVE,
        ]);
        $this->token = $this->user->createToken('kiosk', ['kiosk:order'])->plainTextToken;
    }

    private function authed(): self
    {
        $this->withHeaders(['Authorization' => "Bearer {$this->token}"]);
        return $this;
    }

    // =====================================================================
    //  GET /api/frontend/menu
    // =====================================================================

    public function test_menu_requires_auth(): void
    {
        $this->getJson('/api/frontend/menu')->assertStatus(401);
    }

    public function test_menu_returns_unified_structure(): void
    {
        $r = $this->authed()->getJson('/api/frontend/menu');
        $r->assertStatus(200)
          ->assertJsonStructure([
              'status',
              'data' => [
                  'branch' => ['id', 'name', 'available_locales', 'currency'],
                  'categories' => [['id', 'parent_id', 'slug', 'name', 'child_ids']],
                  'items' => [['id', 'category_id', 'slug', 'name', 'price', 'is_chef_pick', 'allergens', 'variations', 'extras']],
                  'upsell_rules',
              ],
          ]);
        $this->assertSame($this->branch->id, $r->json('data.branch.id'));
        $this->assertSame(['fr','en','ar'], $r->json('data.branch.available_locales'));
    }

    public function test_menu_returns_503_when_no_kiosk_machine(): void
    {
        // Nouvel utilisateur sans KioskMachine
        $orphanUser = User::factory()->create(['branch_id' => $this->branch->id]);
        $orphanToken = $orphanUser->createToken('k', ['kiosk:order'])->plainTextToken;

        $this->withHeaders(['Authorization' => "Bearer {$orphanToken}"])
            ->getJson('/api/frontend/menu')
            ->assertStatus(503)
            ->assertJsonPath('code', 'KIOSK_MACHINE_NOT_FOUND');
    }

    public function test_menu_includes_chef_pick_flag(): void
    {
        $r = $this->authed()->getJson('/api/frontend/menu');
        $items = collect($r->json('data.items'));
        $cayenne = $items->firstWhere('id', $this->cayenne->id);
        $this->assertTrue($cayenne['is_chef_pick'], 'Flag admin statique propagé.');
    }

    // =====================================================================
    //  POST /api/frontend/pricing/preview
    // =====================================================================

    public function test_preview_requires_auth(): void
    {
        $this->postJson('/api/frontend/pricing/preview', [])->assertStatus(401);
    }

    public function test_preview_ignores_client_prices_ssot(): void
    {
        $r = $this->authed()->postJson('/api/frontend/pricing/preview', [
            'items' => [
                [
                    'item_id' => $this->cayenne->id,
                    'quantity' => 1,
                    'price' => 0.01,     // <-- tentative injection : ignoré
                    'total' => 0.01,     // <-- idem, FormRequest strip
                    'item_variations' => [['id' => $this->sizeXL->id]],
                    'item_extras'     => [['id' => $this->cheddar->id]],
                ],
            ],
        ]);

        $r->assertStatus(200);
        $lineTotal = (float) $r->json('data.lines.0.line_subtotal');
        $this->assertSame(
            round(9.90 + 1.50 + 1.00, 2),
            $lineTotal,
            'Prix recalculé serveur-side depuis la DB, jamais depuis le payload.'
        );
    }

    public function test_preview_guards_cross_item_variation(): void
    {
        // Crée un item distinct ; on essaye d'attacher sa variation à cayenne.
        $otherItem = Item::forceCreate([
            'name' => 'Other', 'slug' => 'other-'.uniqid(),
            'item_category_id' => $this->cayenne->item_category_id, 'tax_id' => 1,
            'item_type' => 1, 'price' => 5, 'status' => Status::ACTIVE, 'order' => 1,
            'is_featured' => 0, 'is_upsell' => 0, 'is_chef_pick' => false,
            'channels' => ['kiosk'],
        ]);
        $attrId = (int) \DB::table('item_attributes')->value('id');
        $rogueVariation = ItemVariation::create([
            'item_id' => $otherItem->id, 'item_attribute_id' => $attrId,
            'name' => 'Rogue', 'price' => 99, 'status' => Status::ACTIVE,
            'visible_on' => ['kiosk'],
        ]);

        $r = $this->authed()->postJson('/api/frontend/pricing/preview', [
            'items' => [[
                'item_id' => $this->cayenne->id,
                'quantity' => 1,
                'item_variations' => [['id' => $rogueVariation->id]],
            ]],
        ]);

        $r->assertStatus(422);
    }

    public function test_preview_applies_kiosk_promo_priority(): void
    {
        KioskPromo::create([
            'branch_id' => $this->branch->id,
            'code' => 'KIOSK10', 'type' => 'percent', 'value' => 10,
            'active' => true,
        ]);

        $r = $this->authed()->postJson('/api/frontend/pricing/preview', [
            'items' => [['item_id' => $this->cayenne->id, 'quantity' => 1]],
            'kiosk_promo_code' => 'KIOSK10',
        ]);

        $r->assertStatus(200)
          ->assertJsonPath('data.discount_source', 'kiosk_promo');
        $this->assertGreaterThan(0, $r->json('data.discount'));
    }

    // =====================================================================
    //  POST /api/frontend/promo/validate
    // =====================================================================

    public function test_promo_validate_prioritizes_kiosk_promo_over_coupon(): void
    {
        KioskPromo::create([
            'branch_id' => $this->branch->id,
            'code' => 'WELCOME', 'type' => 'percent', 'value' => 15,
            'active' => true,
        ]);
        Coupon::create([
            'name' => 'Welcome global', 'code' => 'WELCOME',
            'discount' => 5, 'discount_type' => 2,
            'minimum_order' => 0, 'maximum_discount' => 0, 'limit_per_user' => 0,
        ]);

        $r = $this->authed()->postJson('/api/frontend/promo/validate', [
            'code' => 'WELCOME', 'cart_total' => 20,
        ]);

        $r->assertStatus(200)
          ->assertJsonPath('data.source', 'kiosk_promo');
    }

    public function test_promo_validate_falls_back_to_coupon(): void
    {
        Coupon::create([
            'name' => 'Fallback', 'code' => 'FALLBACK',
            'discount' => 2, 'discount_type' => 2,
            'minimum_order' => 0, 'maximum_discount' => 0, 'limit_per_user' => 0,
        ]);

        $r = $this->authed()->postJson('/api/frontend/promo/validate', [
            'code' => 'FALLBACK', 'cart_total' => 20,
        ]);

        $r->assertStatus(200)
          ->assertJsonPath('data.source', 'coupon');
    }

    public function test_promo_validate_rejects_unknown_code(): void
    {
        $r = $this->authed()->postJson('/api/frontend/promo/validate', [
            'code' => 'DOESNOTEXIST', 'cart_total' => 10,
        ]);
        $r->assertStatus(422);
    }

    public function test_promo_validate_kiosk_branch_isolation(): void
    {
        $otherBranch = Branch::forceCreate([
            'name' => 'Other', 'city' => 'Lyon', 'state' => 'AURA',
            'zip_code' => '69000', 'address' => '2', 'status' => 1,
        ]);
        KioskPromo::create([
            'branch_id' => $otherBranch->id,
            'code' => 'OTHERBRANCH', 'type' => 'amount', 'value' => 5,
            'active' => true,
        ]);

        $r = $this->authed()->postJson('/api/frontend/promo/validate', [
            'code' => 'OTHERBRANCH', 'cart_total' => 20,
        ]);

        $r->assertStatus(422);
    }

    // =====================================================================
    //  GET /api/frontend/upsell
    // =====================================================================

    public function test_upsell_returns_rule_based_suggestion_when_matches(): void
    {
        UpsellRule::create([
            'branch_id' => $this->branch->id,
            'trigger_type' => UpsellRule::TRIGGER_CART_TOTAL_GTE,
            'trigger_value' => ['amount' => 5],
            'suggested_item_id' => $this->fries->id,
            'active' => true, 'priority' => 10,
        ]);

        $r = $this->authed()->getJson('/api/frontend/upsell?cart_total=10&cart_item_ids='.$this->cayenne->id);

        $r->assertStatus(200);
        $data = $r->json('data');
        $this->assertNotEmpty($data);
        $this->assertSame($this->fries->id, (int) $data[0]['id']);
        $this->assertNotNull($data[0]['upsell_rule']);
    }

    public function test_upsell_falls_back_to_legacy_when_no_rule(): void
    {
        // 0 upsell_rules configurées → fallback sur items.is_upsell=1
        $r = $this->authed()->getJson('/api/frontend/upsell?cart_total=10&cart_item_ids='.$this->cayenne->id);

        $r->assertStatus(200);
        $data = $r->json('data');
        $this->assertNotEmpty($data, 'Fallback legacy doit retourner les items is_upsell=1');
        $this->assertSame($this->fries->id, (int) $data[0]['id']);
        $this->assertNull($data[0]['upsell_rule']);
    }

    public function test_upsell_excludes_items_already_in_cart(): void
    {
        UpsellRule::create([
            'branch_id' => $this->branch->id,
            'trigger_type' => UpsellRule::TRIGGER_CART_TOTAL_GTE,
            'trigger_value' => ['amount' => 1],
            'suggested_item_id' => $this->fries->id,
            'active' => true, 'priority' => 10,
        ]);

        // Fries déjà dans le cart → doit pas être resuggéré
        $r = $this->authed()->getJson('/api/frontend/upsell?cart_total=10&cart_item_ids='.$this->fries->id);

        $r->assertStatus(200);
        $ids = collect($r->json('data'))->pluck('id');
        $this->assertFalse($ids->contains($this->fries->id));
    }
}
