<?php

namespace Tests\Feature\Kiosk;

use App\Enums\Ask;
use App\Enums\OrderType;
use App\Enums\PaymentGateway;
use App\Enums\Source;
use App\Enums\Status;
use App\Enums\TaxType;
use App\Models\Branch;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\KioskMachine;
use App\Models\Tax;
use App\Models\User;
use App\Services\Menu\AvailabilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [HEAL dispute-r3 C-R2-NEW-2 2026-06-12] Rupture produit en session borne.
 *
 * R2 adversarial (C30) : item passé indisponible mi-session → checkout rejeté
 * en boucle « Article 34 indisponible dans le catalogue. Commande rejetée. »
 * — ID interne DB exposé au client, AUCUN identifiant structuré dans la
 * réponse pour que la borne marque/retire la ligne fautive → cul-de-sac.
 *
 * Contrat verrouillé ici :
 *  1. Le message FR porte le NOM de l'article (« Grande Frites »), jamais
 *     l'ID interne nu (« Article 34 »).
 *  2. La 422 porte un payload structuré { code: ITEM_UNAVAILABLE, item_id,
 *     item_name } pour que KioskCartComponent marque la ligne + CTA retrait.
 *  3. Les deux chemins (quote ET order) renvoient le même contrat.
 */
class KioskRuptureCheckoutMessageTest extends TestCase
{
    use RefreshDatabase;

    public function test_quote_rejects_catalog_rupture_with_item_name_and_structured_payload(): void
    {
        [$kioskUser, $payload, $item] = $this->kioskFixture();

        // Rupture catalogue en session (le cas C30: items.is_available = false).
        $item->update(['is_available' => false]);

        $response = $this->actingAs($kioskUser, 'sanctum')
            ->postJson('/api/frontend/order/quote', $payload)
            ->assertStatus(422);

        $json = $response->json();
        $this->assertSame('ITEM_UNAVAILABLE', $json['code'] ?? null, 'structured code required for kiosk line-marking');
        $this->assertSame($item->id, (int) ($json['item_id'] ?? 0));
        $this->assertSame('Grande Frites', $json['item_name'] ?? null);
        $this->assertStringContainsString('Grande Frites', (string) ($json['message'] ?? ''), 'message must carry the item NAME');
        $this->assertStringNotContainsString("Article {$item->id} ", (string) ($json['message'] ?? ''), 'raw internal id must not leak');
    }

    public function test_quote_rejects_branch_rupture_with_item_name_and_structured_payload(): void
    {
        [$kioskUser, $payload, $item, $branch] = $this->kioskFixture();

        // Rupture branche (86 manuel) — l'autre voie de rejet.
        app(AvailabilityService::class)->toggle($item->id, $branch->id, false, 'rupture_test');

        $response = $this->actingAs($kioskUser, 'sanctum')
            ->postJson('/api/frontend/order/quote', $payload)
            ->assertStatus(422);

        $json = $response->json();
        $this->assertSame('ITEM_UNAVAILABLE', $json['code'] ?? null);
        $this->assertSame($item->id, (int) ($json['item_id'] ?? 0));
        $this->assertStringContainsString('Grande Frites', (string) ($json['message'] ?? ''));
    }

    public function test_order_store_rejects_rupture_with_same_structured_contract(): void
    {
        config(['app.api_key' => 'test-api-key']);
        [$kioskUser, $payload, $item] = $this->kioskFixture();

        // Quote AVANT rupture (panier validé), rupture, puis commit → rejet.
        $quote = $this->actingAs($kioskUser, 'sanctum')
            ->postJson('/api/frontend/order/quote', $payload)
            ->assertOk()
            ->json('data');

        $item->update(['is_available' => false]);

        $response = $this->actingAs($kioskUser, 'sanctum')
            ->withHeader('x-api-key', 'test-api-key')
            ->postJson('/api/frontend/order', $payload + [
                'quote_token' => $quote['quote_token'],
                'quote_signature' => $quote['signature'],
                'total' => $quote['total_ttc'],
            ])
            ->assertStatus(422);

        $json = $response->json();
        $this->assertSame('ITEM_UNAVAILABLE', $json['code'] ?? null, 'order path must carry the same structured contract');
        $this->assertSame($item->id, (int) ($json['item_id'] ?? 0));
        $this->assertStringContainsString('Grande Frites', (string) ($json['message'] ?? ''));
    }

    /**
     * @return array{0: User, 1: array<string, mixed>, 2: Item, 3: Branch}
     */
    private function kioskFixture(): array
    {
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        $branch = Branch::factory()->create();
        $kioskUser = User::factory()->create(['branch_id' => $branch->id]);
        KioskMachine::factory()->create([
            'branch_id' => $branch->id,
            'user_id' => $kioskUser->id,
        ]);

        $tax = Tax::factory()->create([
            'tax_rate' => 0,
            'type' => TaxType::PERCENTAGE,
            'status' => Status::ACTIVE,
        ]);
        $category = ItemCategory::factory()->create(['status' => Status::ACTIVE]);
        $item = Item::factory()->create([
            'name' => 'Grande Frites',
            'slug' => 'grande-frites-rupture-test',
            'item_category_id' => $category->id,
            'tax_id' => $tax->id,
            'price' => 4.00,
            'status' => Status::ACTIVE,
        ]);

        return [$kioskUser, [
            'branch_id' => $branch->id,
            'order_type' => OrderType::KIOSK,
            'is_advance_order' => Ask::NO,
            'source' => Source::WEB,
            'payment_method' => PaymentGateway::CARD,
            'items' => json_encode([[
                'item_id' => $item->id,
                'quantity' => 1,
                'item_variations' => [],
                'item_extras' => [],
            ]]),
        ], $item, $branch];
    }
}
