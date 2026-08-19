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
        // et payait plein tarif.
        //
        // [SUPERVISION 2026-08-19] ET IL PASSAIT ENCORE POUR UNE MAUVAISE RAISON, LA DEUXIÈME FOIS.
        //
        // Le heal du 01/08 l'avait fait reposer sur le REFUS de la 2ᵉ commande. Or ce refus
        // n'était pas une garde : c'était un DÉFAUT. Les points étaient débités AVANT que
        // `sealForCommit` recalcule le devis ; ce recalcul relisait le solde vivant, le trouvait
        // diminué, concluait « remise 0 » et refusait la vente — « Order quote intent mismatch ».
        // Le même défaut bloquait de VRAIES ventes au comptoir (reproduit en HTTP le 19/08 :
        // client à 2000 points rachetant 1500, vente refusée). Le corriger — déplacer l'ÉCRITURE
        // après le sceau, le CALCUL restant avant — a donc fait tomber ce refus avec lui.
        //
        // MESURÉ sur le code fusionné, pas déduit : les 2 commandes aboutissent, chacune avec SON
        // PROPRE devis (`withQuote` en régénère un à chaque appel — il n'y a jamais eu de devis
        // « réutilisé »), solde 100 → 50 → 0, deux lignes de grand-livre `redeem −50`, chacune
        // rattachée à sa commande. Cent points pour 1,00 € de remise : le taux exact. Aucun
        // découvert, aucune double dépense. Refuser cette 2ᵉ vente serait le vrai défaut — on
        // refuserait le second achat légitime d'un client qui a encore des points.
        //
        // Ce test épingle donc désormais ce que son NOM promet : on ne découvre jamais.
        $this->assertTrue(in_array($response1->status(), [200, 201]), 'La 1ʳᵉ commande doit aboutir.');
        $this->assertTrue(in_array($response2->status(), [200, 201]),
            'La 2ᵉ commande doit aboutir : le client a encore 50 points, c\'est un achat légitime.');

        $customer->refresh();
        $this->assertSame(0, (int) $customer->loyalty_points,
            'Solde exact après deux rachats de 50 points sur 100 : zéro, jamais négatif.');

        $lignes = \App\Models\LoyaltyTransaction::where('user_id', $customer->id)
            ->where('type', 'redeem')->orderBy('id')->get();
        $this->assertCount(2, $lignes, 'Un débit par commande — ni plus (double dépense), ni moins (remise offerte).');
        $this->assertSame(-100, (int) $lignes->sum('points'), 'Somme débitée = exactement les points possédés.');
        $this->assertCount(2, $lignes->pluck('order_id')->unique(),
            'Chaque débit est rattaché à SA commande : deux lignes sur la même commande = double dépense.');
    }

    /**
     * Test C bis — LE VRAI DÉCOUVERT. [SUPERVISION 2026-08-19]
     *
     * Le test ci-dessus mesure deux rachats que le solde COUVRE. Celui-ci éprouve le cas que son
     * titre promettait sans jamais l'atteindre : un client qui n'a PAS de quoi payer le second
     * rachat. C'est le seul scénario où un découvert peut naître, et donc le seul qui prouve la
     * garde. Sans lui, « does not overdraw » n'était qu'une intention.
     */
    public function test_loyalty_second_redemption_without_enough_points_never_overdraws(): void
    {
        [$branch, $user] = $this->setupKiosk();
        $item = \Database\Factories\ItemFactory::new()->create(['price' => 10]);

        // 50 points = 0,50 € : de quoi payer UN rachat, pas deux.
        $customer = \Database\Factories\UserFactory::new()->create([
            'branch_id' => $branch->id,
            'status' => \App\Enums\Status::ACTIVE,
            'loyalty_code' => 'LOYAL_SHORT_1',
            'loyalty_points' => 50,
        ]);

        $basePayload = $this->makeOrderPayload($item->id, $branch->id);
        $basePayload['loyalty_code'] = 'LOYAL_SHORT_1';
        $basePayload['discount'] = 0.50;

        $this->actingAs($user)->withHeader('x-api-key', $this->apiKey())
            ->withHeader('X-Idempotency-Key', 'short-a-' . uniqid())
            ->postJson('/api/frontend/order', $this->withQuote($user, $basePayload));

        $reponse2 = $this->actingAs($user)->withHeader('x-api-key', $this->apiKey())
            ->withHeader('X-Idempotency-Key', 'short-b-' . uniqid())
            ->postJson('/api/frontend/order', $this->withQuote($user, $basePayload));

        $customer->refresh();

        // L'INVARIANT ABSOLU : quoi qu'il arrive à la 2ᵉ commande — acceptée au prix plein ou
        // refusée — le solde ne descend JAMAIS sous zéro et aucun second débit n'est écrit.
        $this->assertGreaterThanOrEqual(0, (int) $customer->loyalty_points,
            'Le solde de points ne doit JAMAIS devenir négatif.');
        $this->assertSame(0, (int) $customer->loyalty_points, 'Les 50 points ont été dépensés une seule fois.');
        $this->assertLessThan(500, $reponse2->status(),
            'Un refus doit être une garde métier explicite, jamais une erreur serveur.');

        $lignes = \App\Models\LoyaltyTransaction::where('user_id', $customer->id)->where('type', 'redeem')->get();
        $this->assertCount(1, $lignes, 'Un seul débit : le second rachat n\'a pas de quoi être payé.');
        $this->assertSame(-50, (int) $lignes->sum('points'), 'On ne débite jamais plus que ce que le client possède.');
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
