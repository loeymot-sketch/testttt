<?php

namespace Tests\Feature\Uber;

use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * [AUDIT-5SYS 2026-08-12 P1] UberOrderIngestor se déclare "chemin de création UNIQUE" entre le
 * webhook et la capture photo — mais UberWebhookController::createFromUber portait sa propre
 * logique dupliquée et ne l'appelait jamais. Preuve concrète de la divergence : pos_customer_name
 * n'était jamais renseigné par le webhook. Ce test verrouille la parité.
 */
class UberWebhookIngestorParityTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'shhh-parity';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();
        config()->set('uber.webhook_signing_secret', self::SECRET);
        config()->set('uber.client_id', 'cid');
        config()->set('uber.client_secret', 'csecret');
        config()->set('uber.auto_accept', false);
        config()->set('uber.branch_id', 1);
        \App\Models\Branch::factory()->create(['id' => 1]);
    }

    private function signedPost(array $payload)
    {
        $body = json_encode($payload);

        return $this->call('POST', '/api/webhooks/uber', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_UBER_SIGNATURE' => hash_hmac('sha256', $body, self::SECRET),
        ], $body);
    }

    /** @test */
    public function le_webhook_passe_par_le_chemin_de_creation_unique_et_pose_le_nom_client(): void
    {
        Http::fake([
            'login.uber.com/*' => Http::response(['access_token' => 'TOK', 'expires_in' => 3600], 200),
            'api.uber.com/v1/eats/orders/*' => Http::response([
                'display_id' => 'PARITY1',
                'eater' => ['first_name' => 'Mathieu'],
                'payment' => ['charges' => ['total' => ['amount' => 1200]]],
                'cart' => ['items' => [[
                    'title' => 'Tacos M',
                    'quantity' => 1,
                    'price' => ['unit_price' => ['amount' => 1200], 'total_price' => ['amount' => 1200]],
                ]]],
            ], 200),
        ]);

        $this->signedPost(['event_id' => 'evt-parity', 'event_type' => 'orders.notification', 'meta' => ['resource_id' => 'R-PARITY']])
            ->assertStatus(200);

        $order = Order::withoutGlobalScopes()->where('transaction_id', 'uber:R-PARITY')->first();
        $this->assertNotNull($order, 'La commande doit être créée.');
        $this->assertSame(
            'Mathieu',
            $order->pos_customer_name,
            'Le webhook doit poser pos_customer_name exactement comme la capture photo (même chemin unique, App\Services\Uber\UberOrderIngestor).'
        );
    }
}
