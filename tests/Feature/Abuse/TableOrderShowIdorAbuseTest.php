<?php

namespace Tests\Feature\Abuse;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Models\FrontendOrder;
use Database\Factories\BranchFactory;
use Database\Factories\UserFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [abuse-heal 2026-06-19 table-show-idor]
 *
 * BOLA / IDOR (PII enumeration) on the UNAUTHENTICATED table read endpoint
 *   GET /api/table/dining-order/show/{frontendOrder}
 * (routes/api.php — group middleware ['installed','apiKey','localization'],
 *  NO auth:sanctum, NO ownership) → Table\OrderController::show.
 *
 * Route-model-binding resolves ANY FrontendOrder by its default key (id), and
 * EVERY kiosk/borne/web/table order is a FrontendOrder. Before the heal the
 * controller returned `new OrderDetailsResource($frontendOrder)` with ZERO
 * authorization, so any holder of the shared apiKey could enumerate
 * show/1, show/2, … and read full PII (customer name/phone/email, delivery
 * address, items, totals, payment_status) of every order.
 *
 * Heal: require the caller to present the order's own per-order `token`
 * (returned to the legit client at creation) as a constant-time capability.
 * An id-enumerating attacker has the id but NOT each order's token.
 *
 * NOTE: dine-in is DISABLED in V1 (pos_dine_in_enabled=false) so the table
 * create flow is killswitch-blocked and this read flow is dormant — but the
 * show route is live regardless of the killswitch, so the BOLA is real.
 */
class TableOrderShowIdorAbuseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();
        $this->seedSpatieRoles();
    }

    private function apiKey(): string
    {
        return config('app.api_key', env('MIX_API_KEY', 'test-api-key'));
    }

    /**
     * Create a FrontendOrder owning identifiable PII (the "victim").
     */
    private function makeVictimOrder(?string $token, int $orderType = OrderType::DINING_TABLE): FrontendOrder
    {
        // NOTE: users.first_name / last_name are computed accessors derived from
        // `name` (User::getFirstNameAttribute), NOT columns — set the real
        // PII columns only (name / phone / email).
        $branch = BranchFactory::new()->create();
        $victim = UserFactory::new()->create([
            'branch_id' => $branch->id,
            'name'      => 'Victime Cible',
            'phone'     => '0612345678',
            'email'     => 'victime.cible@example.test',
        ]);

        return FrontendOrder::forceCreate([
            'user_id'         => $victim->id,
            'branch_id'       => $branch->id,
            'order_serial_no' => 'FO-IDOR-'.uniqid(),
            'order_type'      => $orderType,
            'status'          => OrderStatus::PENDING,
            'payment_status'  => PaymentStatus::PAID,
            'subtotal'        => 20,
            'total'           => 20,
            'discount'        => 0,
            'delivery_charge' => 0,
            'order_datetime'  => now(),
            'token'           => $token,
        ]);
    }

    /**
     * ENUMERATE — no token → must be refused (403), PII must NOT leak.
     */
    public function test_id_enumeration_without_token_is_refused_and_leaks_no_pii(): void
    {
        $order = $this->makeVictimOrder('secret-cap-token-AAA');

        $response = $this->withHeader('x-api-key', $this->apiKey())
            ->getJson('/api/table/dining-order/show/'.$order->id);

        $response->assertStatus(403);

        // The PII of the victim must NOT appear anywhere in the body.
        $body = $response->getContent();
        $this->assertStringNotContainsString('Victime Cible', $body, 'Customer name leaked via id enumeration.');
        $this->assertStringNotContainsString('0612345678', $body, 'Customer phone leaked via id enumeration.');
        $this->assertStringNotContainsString('victime.cible@example.test', $body, 'Customer email leaked via id enumeration.');
        $this->assertStringNotContainsString('secret-cap-token-AAA', $body, 'Order token leaked via id enumeration.');
    }

    /**
     * ENUMERATE — wrong token → must be refused (403), PII must NOT leak.
     */
    public function test_id_enumeration_with_wrong_token_is_refused_and_leaks_no_pii(): void
    {
        $order = $this->makeVictimOrder('secret-cap-token-BBB');

        $response = $this->withHeader('x-api-key', $this->apiKey())
            ->getJson('/api/table/dining-order/show/'.$order->id.'?token=not-the-right-token');

        $response->assertStatus(403);

        $body = $response->getContent();
        $this->assertStringNotContainsString('Victime Cible', $body);
        $this->assertStringNotContainsString('victime.cible@example.test', $body);
        $this->assertStringNotContainsString('secret-cap-token-BBB', $body);
    }

    /**
     * CAPABILITY (control) — the legit client presents the order's OWN token
     * (received at creation) → 200 + the order. Legit view must still work.
     */
    public function test_correct_token_returns_the_order(): void
    {
        $token = 'secret-cap-token-CCC';
        $order = $this->makeVictimOrder($token);

        $response = $this->withHeader('x-api-key', $this->apiKey())
            ->getJson('/api/table/dining-order/show/'.$order->id.'?token='.$token);

        $response->assertStatus(200);
        $response->assertJsonPath('data.id', $order->id);
        $response->assertJsonPath('data.token', $token);
    }

    /**
     * A null-token order must NOT be viewable, even with an empty token param —
     * a missing capability can never be satisfied by an empty provided token.
     */
    public function test_order_with_null_token_is_not_viewable_even_with_empty_token(): void
    {
        $order = $this->makeVictimOrder(null);

        // empty token param
        $r1 = $this->withHeader('x-api-key', $this->apiKey())
            ->getJson('/api/table/dining-order/show/'.$order->id.'?token=');
        $r1->assertStatus(403);

        // no token param at all
        $r2 = $this->withHeader('x-api-key', $this->apiKey())
            ->getJson('/api/table/dining-order/show/'.$order->id);
        $r2->assertStatus(403);

        $this->assertStringNotContainsString('Victime Cible', $r1->getContent());
        $this->assertStringNotContainsString('Victime Cible', $r2->getContent());
    }

    /**
     * DEFENSE-IN-DEPTH — a NON-table FrontendOrder (e.g. a KIOSK order) routed
     * through the table show endpoint must be refused (404) even with a valid
     * token: the table flow must only ever expose DINING_TABLE orders.
     */
    public function test_non_table_order_via_table_route_is_refused_even_with_valid_token(): void
    {
        $token = 'secret-cap-token-DDD';
        $kioskOrder = $this->makeVictimOrder($token, OrderType::KIOSK);

        $response = $this->withHeader('x-api-key', $this->apiKey())
            ->getJson('/api/table/dining-order/show/'.$kioskOrder->id.'?token='.$token);

        $response->assertStatus(404);

        $body = $response->getContent();
        $this->assertStringNotContainsString('Victime Cible', $body, 'Kiosk-order PII leaked via the table show route.');
        $this->assertStringNotContainsString('victime.cible@example.test', $body);
    }
}
