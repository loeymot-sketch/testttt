<?php

namespace Tests\Feature\KioskPhase1;

use App\Enums\Ask;
use App\Enums\Status;
use App\Models\Branch;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\ItemExtra;
use App\Models\KioskMachine;
use App\Models\KioskPromo;
use App\Models\Tax;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Kiosk Design V1 — Phase 1 Audit sécurité (hardening SSOT).
 *
 * Vérifie que les endpoints Phase 1 rejettent / ignorent toute tentative
 * d'injection de données métier depuis le client :
 *   - branch_id   : lu SERVEUR-ONLY (KioskMachine), jamais payload.
 *   - price/total : ignorés (FormRequest whitelist + PricingService SSOT).
 *   - subtotal    : idem.
 *   - discount    : idem.
 *   - arbitrary keys in payload : strippées.
 */
class SsotInjectionHardeningTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;
    private Branch $otherBranch;
    private User $user;
    private string $token;
    private Item $item;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();

        $this->branch = Branch::forceCreate([
            'name' => 'Main', 'city' => 'Paris', 'state' => 'IDF',
            'zip_code' => '75', 'address' => '1', 'status' => 1,
        ]);
        $this->otherBranch = Branch::forceCreate([
            'name' => 'Other', 'city' => 'Lyon', 'state' => 'AURA',
            'zip_code' => '69', 'address' => '2', 'status' => 1,
        ]);

        $tax = Tax::create(['name' => 'T', 'code' => 'T'.uniqid(), 'tax_rate' => 10, 'type' => 2, 'status' => 1]);
        $cat = ItemCategory::forceCreate([
            'name' => 'C', 'slug' => 'c-'.uniqid(),
            'status' => Status::ACTIVE, 'sort' => 1, 'channels' => ['kiosk'],
        ]);
        $this->item = Item::forceCreate([
            'name' => 'Item', 'slug' => 'i-'.uniqid(),
            'item_category_id' => $cat->id, 'tax_id' => $tax->id,
            'item_type' => 1, 'price' => 10.00,
            'status' => Status::ACTIVE, 'order' => 1,
            'is_featured' => 0, 'is_upsell' => 0, 'is_chef_pick' => false,
            'channels' => ['kiosk'],
        ]);

        $this->user = User::factory()->create([
            'username' => 'kiosk_'.uniqid(), 'branch_id' => $this->branch->id,
        ]);
        KioskMachine::create([
            'machine_id' => 't', 'branch_id' => $this->branch->id,
            'user_id' => $this->user->id, 'username' => 'k', 'password' => bcrypt('x'),
            'is_login' => Ask::NO, 'status' => Status::ACTIVE,
        ]);
        $this->token = $this->user->createToken('k', ['kiosk:order'])->plainTextToken;
    }

    private function authed(): self
    {
        $this->withHeaders(['Authorization' => "Bearer {$this->token}"]);
        return $this;
    }

    // ====================================================================
    //  POST /pricing/preview — branch_id injection
    // ====================================================================

    public function test_preview_injection_branch_id_is_ignored(): void
    {
        // Attaquant tente d'injecter branch_id d'une autre branche
        // ET un code promo qui n'existe QUE sur cette autre branche.
        KioskPromo::create([
            'branch_id' => $this->otherBranch->id,
            'code' => 'LYONONLY', 'type' => 'percent', 'value' => 50,
            'active' => true,
        ]);

        $r = $this->authed()->postJson('/api/frontend/pricing/preview', [
            'branch_id' => $this->otherBranch->id,   // <-- injection
            'items' => [['item_id' => $this->item->id, 'quantity' => 1]],
            'kiosk_promo_code' => 'LYONONLY',
        ]);

        $r->assertStatus(200);
        // Le promo appartient à otherBranch → ne doit PAS s'appliquer
        // puisque le serveur ignore le branch_id payload et utilise le sien.
        $this->assertNull($r->json('data.discount_source'),
            'branch_id serveur-side doit être immuable — promo étranger ignoré.');
        $this->assertEquals(0.0, (float) $r->json('data.discount'));
    }

    public function test_preview_injection_price_is_ignored(): void
    {
        $r = $this->authed()->postJson('/api/frontend/pricing/preview', [
            'items' => [[
                'item_id' => $this->item->id,
                'quantity' => 1,
                'price' => 0.01,     // injection
                'total' => 0.01,     // injection
                'subtotal' => 0.01,  // injection
                'discount' => 999,   // injection
            ]],
        ]);

        $r->assertStatus(200);
        $this->assertEquals(10.00, (float) $r->json('data.lines.0.unit_price'),
            'Prix recalculé depuis la DB, pas du payload.');
    }

    public function test_preview_arbitrary_keys_are_stripped(): void
    {
        $r = $this->authed()->postJson('/api/frontend/pricing/preview', [
            'items' => [['item_id' => $this->item->id, 'quantity' => 1]],
            '__admin_bypass' => true,
            'customer_user_id' => 99999,
            'deliveryCharge' => 100,
            'sql' => "1; DROP TABLE users;",
        ]);

        $r->assertStatus(200);
        $this->assertEquals(10.00, (float) $r->json('data.subtotal'));
        $this->assertDatabaseHas('users', ['id' => $this->user->id]);
    }

    // ====================================================================
    //  POST /promo/validate — branch isolation hardening
    // ====================================================================

    public function test_promo_validate_ignores_injected_branch_id(): void
    {
        KioskPromo::create([
            'branch_id' => $this->otherBranch->id,
            'code' => 'LYONONLY', 'type' => 'amount', 'value' => 5,
            'active' => true,
        ]);

        $r = $this->authed()->postJson('/api/frontend/promo/validate', [
            'branch_id' => $this->otherBranch->id,
            'code' => 'LYONONLY',
            'cart_total' => 100,
        ]);

        $r->assertStatus(422,
            'branch_id payload ignoré, promo otherBranch doit rester invisible.');
    }

    // ====================================================================
    //  GET /menu — branch inference server-only
    // ====================================================================

    public function test_menu_ignores_injected_branch_id_query_string(): void
    {
        $r = $this->authed()->getJson('/api/frontend/menu?branch_id='.$this->otherBranch->id);

        $r->assertStatus(200);
        $this->assertSame($this->branch->id, $r->json('data.branch.id'),
            'GET /menu résout la branche via KioskMachine, jamais via query string.');
    }

    // ====================================================================
    //  GET /upsell — branch inference server-only
    // ====================================================================

    public function test_upsell_ignores_injected_branch_id(): void
    {
        // Si l'attaquant pouvait injecter branch_id, il verrait les upsell
        // d'une autre branche. On vérifie que c'est impossible.
        \App\Models\UpsellRule::create([
            'branch_id' => $this->otherBranch->id,
            'trigger_type' => 'cart_total_gte',
            'trigger_value' => ['amount' => 1],
            'suggested_item_id' => $this->item->id,
            'active' => true, 'priority' => 100,
        ]);

        $r = $this->authed()->getJson('/api/frontend/upsell?cart_total=10&cart_item_ids=&branch_id='.$this->otherBranch->id);

        $r->assertStatus(200);
        $data = $r->json('data');
        // La règle existe sur otherBranch — elle NE DOIT PAS apparaître
        // dans la réponse faite par un kiosk de $this->branch.
        foreach ($data as $suggestion) {
            if (isset($suggestion['upsell_rule']) && $suggestion['upsell_rule'] !== null) {
                $this->fail('Règle upsell d\'une autre branche leaked : '.json_encode($suggestion));
            }
        }
        $this->assertTrue(true, 'Aucune règle upsell étrangère exposée.');
    }
}
