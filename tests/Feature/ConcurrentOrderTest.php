<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Order;
use App\Models\FrontendOrder;
use App\Services\Order\OrderQuoteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;

class ConcurrentOrderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();
        $this->seedSpatieRoles();
    }

    private function setupKiosk(): array
    {
        $branch = \Database\Factories\BranchFactory::new()->create();
        $user = \Database\Factories\UserFactory::new()->create([
            'branch_id' => $branch->id,
            'status' => \App\Enums\Status::ACTIVE,
        ]);
        $kiosk = \Database\Factories\KioskMachineFactory::new()->create([
            'branch_id' => $branch->id,
            'user_id' => $user->id,
            'username' => 'kiosk_concurrent',
            'password' => bcrypt('password123'),
            'status' => \App\Enums\Status::ACTIVE,
            'is_login' => \App\Enums\Ask::NO,
        ]);
        return [$branch, $user, $kiosk];
    }

    private function apiKey(): string
    {
        return config('app.api_key', env('MIX_API_KEY', 'test-api-key'));
    }

    private function makeOrderPayload(int $itemId, int $branchId, ?string $idempotencyKey = null): array
    {
        return [
            'order_type' => 10,
            'branch_id' => $branchId,
            'subtotal' => 10,
            'total' => 10,
            'delivery_charge' => 0,
            'is_advance_order' => 0,
            'source' => 1,
            'items' => json_encode([['item_id' => $itemId, 'price' => 10, 'quantity' => 1]]),
        ];
    }

    /**
     * Test A — Idempotency: sending the same key twice creates only one order.
     */
    public function test_idempotency_prevents_duplicate_order(): void
    {
        [$branch, $user, $kiosk] = $this->setupKiosk();
        $item = \Database\Factories\ItemFactory::new()->create(['price' => 10]);
        $payload = $this->makeOrderPayload($item->id, $branch->id);
        $payloadWithQuote = $this->withQuote($user, $payload);
        $idempotencyKey = 'test-idempotency-' . uniqid();

        $response1 = $this->actingAs($user)
            ->withHeader('x-api-key', $this->apiKey())
            ->withHeader('X-Idempotency-Key', $idempotencyKey)
            ->postJson('/api/frontend/order', $payloadWithQuote);

        $this->assertTrue(in_array($response1->status(), [200, 201]));

        $response2 = $this->actingAs($user)
            ->withHeader('x-api-key', $this->apiKey())
            ->withHeader('X-Idempotency-Key', $idempotencyKey)
            ->postJson('/api/frontend/order', $payloadWithQuote);

        $this->assertTrue(in_array($response2->status(), [200, 201]));

        $this->assertSame(1, FrontendOrder::count(), 'Duplicate idempotency key must not create a second order');
    }

    /**
     * Test B — Two distinct idempotency keys create two separate orders.
     *
     * NOTE: Queue number uniqueness across frontend_orders is a known gap —
     * the allocator queries the `orders` table (POS), not `frontend_orders`.
     * This test validates that two orders are created, each with a queue number assigned.
     */
    public function test_two_orders_created_with_different_keys(): void
    {
        [$branch, $user, $kiosk] = $this->setupKiosk();
        $item = \Database\Factories\ItemFactory::new()->create(['price' => 10]);
        $payload = $this->makeOrderPayload($item->id, $branch->id);

        $response1 = $this->actingAs($user)
            ->withHeader('x-api-key', $this->apiKey())
            ->withHeader('X-Idempotency-Key', 'key-a-' . uniqid())
            ->postJson('/api/frontend/order', $this->withQuote($user, $payload));

        $response2 = $this->actingAs($user)
            ->withHeader('x-api-key', $this->apiKey())
            ->withHeader('X-Idempotency-Key', 'key-b-' . uniqid())
            ->postJson('/api/frontend/order', $this->withQuote($user, $payload));

        $this->assertTrue(in_array($response1->status(), [200, 201]));
        $this->assertTrue(in_array($response2->status(), [200, 201]));
        $this->assertSame(2, FrontendOrder::count(), 'Two different keys must create two orders');

        $orders = FrontendOrder::orderBy('id')->get();
        $this->assertNotNull($orders[0]->queue_number, 'First order must have a queue number');
        $this->assertNotNull($orders[1]->queue_number, 'Second order must have a queue number');
    }

    /**
     * Test C — Loyalty: concurrent redemptions do not overdraw points.
     */
    public function test_loyalty_concurrent_redemption_does_not_overdraw(): void
    {
        [$branch, $user, $kiosk] = $this->setupKiosk();
        $item = \Database\Factories\ItemFactory::new()->create(['price' => 10]);

        $customer = \Database\Factories\UserFactory::new()->create([
            'branch_id' => $branch->id,
            'status' => \App\Enums\Status::ACTIVE,
            'loyalty_code' => 'LOYAL_TEST_123',
            'loyalty_points' => 100,
        ]);

        $basePayload = $this->makeOrderPayload($item->id, $branch->id);
        $basePayload['loyalty_code'] = 'LOYAL_TEST_123';
        $basePayload['discount'] = 0.50;

        $response1 = $this->actingAs($user)
            ->withHeader('x-api-key', $this->apiKey())
            ->withHeader('X-Idempotency-Key', 'loyalty-a-' . uniqid())
            ->postJson('/api/frontend/order', $this->withQuote($user, $basePayload));

        $response2 = $this->actingAs($user)
            ->withHeader('x-api-key', $this->apiKey())
            ->withHeader('X-Idempotency-Key', 'loyalty-b-' . uniqid())
            ->postJson('/api/frontend/order', $this->withQuote($user, $basePayload));

        // [AUDIT FIDÉLITÉ 2026-08-01] AVANT le heal, ce test passait pour une MAUVAISE raison :
        // la recherche du porteur filtrait `status=1`, donc ce client ACTIVE(5) était
        // INTROUVABLE → la remise était silencieusement ignorée → les 2 commandes passaient,
        // identiques, sans jamais toucher aux points. Le client croyait payer avec ses points
        // et payait plein tarif. Depuis le heal, la 1ʳᵉ commande consomme réellement les points
        // et la 2ᵉ est REFUSÉE par la garde de devis (le total recalculé serveur ne correspond
        // plus à l'intention du devis) : c'est le comportement voulu — mieux vaut refuser que
        // débiter deux fois.
        $this->assertTrue(in_array($response1->status(), [200, 201]), 'La 1ʳᵉ commande doit aboutir.');
        $this->assertNotContains($response2->status(), [200, 201],
            'La 2ᵉ commande concurrente ne doit PAS aboutir en réutilisant le même devis fidélité.');
        $this->assertLessThan(500, $response2->status(),
            'Le refus doit être une garde métier explicite, jamais une erreur serveur.');

        $customer->refresh();
        $this->assertGreaterThanOrEqual(0, $customer->loyalty_points, 'Points must not go negative');
        $this->assertSame(1, \App\Models\LoyaltyTransaction::where('user_id', $customer->id)->where('type', 'redeem')->count(),
            'Exactement UN débit de points : aucune double dépense sous concurrence.');
    }

    private function withQuote(User $user, array $payload): array
    {
        $request = Request::create('/api/frontend/order/quote', 'POST', $payload);
        $request->setUserResolver(fn (?string $guard = null): User => $user);

        $quote = app(OrderQuoteService::class)->quote($request, 'kiosk');

        return $payload + [
            'quote_token' => $quote->quote_token,
            'quote_signature' => $quote->hmac_signature,
        ];
    }
}
