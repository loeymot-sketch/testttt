<?php

namespace Tests\Feature;

use App\Enums\Ask;
use App\Enums\OrderType;
use App\Enums\PaymentGateway;
use App\Enums\Source;
use App\Enums\Status;
use App\Enums\TaxType;
use App\Models\Branch;
use App\Models\FrontendOrder;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\KioskMachine;
use App\Models\OrderQuote;
use App\Models\Tax;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KioskQuoteIntegrityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        \Smartisan\Settings\Facades\Settings::group('pos')->set(['pos_dine_in_enabled' => true]); // [2026-07-27] garde V1 sur-place (47f3ad545) : OFF par défaut — ce test exerce un flux sur-place/table derrière son flag
    }


    public function test_kiosk_commit_consumes_quote_and_ignores_client_totals(): void
    {
        config(['app.api_key' => 'test-api-key']);
        [$kioskUser, $payload] = $this->kioskFixture();

        $quote = $this->kioskToken($kioskUser)
            ->postJson('/api/frontend/order/quote', $payload)
            ->assertOk()
            ->json('data');

        $response = $this->kioskToken($kioskUser)
            ->withHeader('x-api-key', 'test-api-key')
            ->postJson('/api/frontend/order', $payload + [
                'quote_token' => $quote['quote_token'],
                'quote_signature' => $quote['signature'],
                'subtotal' => 9999.99,
                'discount' => 9999.99,
                'total' => 0.01,
            ]);

        $this->assertContains($response->status(), [200, 201], $response->getContent());
        $order = FrontendOrder::findOrFail((int) $response->json('data.id'));

        $this->assertEqualsWithDelta((float) $quote['total_ttc'], (float) $order->total, 0.001);
        $this->assertNotEquals(0.01, (float) $order->total);
        $this->assertDatabaseHas('order_quotes', [
            'quote_token' => $quote['quote_token'],
            'consumed_order_id' => $order->id,
            'consumed_by_user_id' => $kioskUser->id,
        ]);
        $this->assertNotNull(OrderQuote::where('quote_token', $quote['quote_token'])->value('consumed_at'));
    }

    public function test_kiosk_commit_rejects_quote_when_items_change(): void
    {
        config(['app.api_key' => 'test-api-key']);
        [$kioskUser, $payload] = $this->kioskFixture();

        $quote = $this->kioskToken($kioskUser)
            ->postJson('/api/frontend/order/quote', $payload)
            ->assertOk()
            ->json('data');

        $tamperedPayload = $payload;
        $tamperedPayload['items'] = json_encode([[
            'item_id' => json_decode($payload['items'])[0]->item_id,
            'quantity' => 2,
            'item_variations' => [],
            'item_extras' => [],
        ]]);

        $this->kioskToken($kioskUser)
            ->withHeader('x-api-key', 'test-api-key')
            ->postJson('/api/frontend/order', $tamperedPayload + [
                'quote_token' => $quote['quote_token'],
                'quote_signature' => $quote['signature'],
                'total' => $quote['total_ttc'],
            ])
            ->assertStatus(401);

        $this->assertNull(OrderQuote::where('quote_token', $quote['quote_token'])->value('consumed_at'));
    }

    /**
     * @return array{0: User, 1: array<string, mixed>}
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
            'item_category_id' => $category->id,
            'tax_id' => $tax->id,
            'price' => 8.25,
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
        ]];
    }

    /**
     * [ULTRA-AUDIT 2026-07-02] Authentifie comme une VRAIE borne enregistrée : un
     * PersonalAccessToken réel (ability kiosk:order). Le guard KIOSK (OrderRequest
     * kioskMachineForToken) exige un vrai token — TransientToken/session rejeté ;
     * NE PAS revert le guard. Reflète la production (borne physique enregistrée).
     */
    private function kioskToken(User $kioskUser): self
    {
        $plain = $kioskUser->createToken('kiosk-e2e', ['kiosk:order'])->plainTextToken;
        return $this->withHeader('Authorization', 'Bearer ' . $plain);
    }
}
