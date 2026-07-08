<?php

namespace Tests\Feature\Stripe;

use App\Http\Middleware\VerifyCsrfToken;
use App\Models\GatewayOption;
use App\Models\PaymentGateway;
use App\Models\WebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * [GOAL-SYNC 2026-07-08] Régression du fix P1 « constructeur Stripe crashe
 * avec stripe_secret vide » (finding w1/by-spec/backend_stripe-gateway.json).
 *
 * État V1 LOCAL Le Cayenne : la row payment_gateways slug=stripe existe mais
 * gateway_options.stripe_secret est VIDE. Avant le fix, StripeClient jetait
 * « api_key cannot be the empty string » dès __construct → tout POST
 * /payment/stripe-webhook/ répondait 500 AVANT la vérification de signature.
 *
 * Invariants verrouillés ici :
 *  - stripe_secret vide → 503 JSON {status:false, message:'Stripe non configuré.'}
 *    (jamais 500), aucune row webhook_events créée.
 *  - option stripe_secret totalement ABSENTE → même 503 propre.
 *  - stripe_secret non-vide → le garde ne se déclenche PAS (le flux durci
 *    existant — signature, idempotence, DLQ — reste le chemin actif).
 */
class StripeWebhookUnconfiguredTest extends TestCase
{
    use RefreshDatabase;

    private bool $createdInstalledFlag = false;

    protected function setUp(): void
    {
        parent::setUp();

        // Même avec un webhook secret env configuré, le garde « gateway non
        // configurée » doit primer — il court-circuite AVANT le check secret.
        Config::set('services.stripe.webhook_secret', 'whsec_test_goal_sync');

        $this->withoutMiddleware(VerifyCsrfToken::class);
        $this->ensureInstalledFlag();
    }

    protected function tearDown(): void
    {
        if ($this->createdInstalledFlag && file_exists(storage_path('installed'))) {
            unlink(storage_path('installed'));
        }

        parent::tearDown();
    }

    public function test_webhook_with_empty_stripe_secret_returns_503_json_not_500(): void
    {
        $this->seedStripeGateway('');

        $response = $this->postWebhook('evt_unconfigured_1');

        $response->assertStatus(503);
        $response->assertExactJson([
            'status'  => false,
            'message' => 'Stripe non configuré.',
        ]);

        // Aucun événement enregistré — la gateway n'est pas opérationnelle.
        $this->assertSame(0, WebhookEvent::where('webhook_id', 'evt_unconfigured_1')->count());
    }

    public function test_webhook_with_missing_stripe_secret_option_returns_503_json(): void
    {
        // Row gateway présente mais AUCUNE option stripe_secret du tout.
        PaymentGateway::create([
            'name'   => 'Stripe',
            'slug'   => 'stripe',
            'misc'   => json_encode([]),
            'status' => 10,
        ]);

        $response = $this->postWebhook('evt_unconfigured_2');

        $response->assertStatus(503);
        $response->assertExactJson([
            'status'  => false,
            'message' => 'Stripe non configuré.',
        ]);

        $this->assertSame(0, WebhookEvent::count());
    }

    public function test_webhook_with_non_empty_secret_does_not_hit_unconfigured_guard(): void
    {
        // Régression : gateway configurée → le garde 503 ne s'active PAS ;
        // la requête atteint la vérification de signature existante (400
        // invalid_signature ici puisque la signature est forgée au hasard).
        $this->seedStripeGateway('sk_test_stub');

        $response = $this->postWebhook('evt_configured_1', 'invalid');

        $response->assertStatus(400);
        $response->assertJsonFragment(['error' => 'invalid_signature']);
    }

    private function seedStripeGateway(string $secretValue): void
    {
        $gateway = PaymentGateway::create([
            'name'   => 'Stripe',
            'slug'   => 'stripe',
            'misc'   => json_encode([]),
            'status' => 10,
        ]);

        GatewayOption::create([
            'model_id'   => $gateway->id,
            'model_type' => PaymentGateway::class,
            'option'     => 'stripe_secret',
            'value'      => $secretValue,
            'type'       => 1,
            'activities' => '',
        ]);
    }

    /**
     * POST brut vers la route webhook avec un header Stripe-Signature
     * syntaxiquement plausible (jamais valide — hors scope de ce test).
     */
    private function postWebhook(string $eventId, string $sigMode = 'plausible')
    {
        $rawPayload = json_encode([
            'id'      => $eventId,
            'object'  => 'event',
            'type'    => 'charge.succeeded',
            'created' => time(),
            'data'    => ['object' => ['id' => 'ch_' . $eventId, 'object' => 'charge']],
        ]);

        $signature = $sigMode === 'invalid'
            ? 't=' . time() . ',v1=deadbeef_invalid_hex'
            : 't=' . time() . ',v1=' . hash_hmac('sha256', 'x', 'y');

        return $this->call(
            'POST',
            '/payment/stripe-webhook/',
            [],
            [],
            [],
            [
                'CONTENT_TYPE'          => 'application/json',
                'HTTP_STRIPE_SIGNATURE' => $signature,
            ],
            $rawPayload
        );
    }

    private function ensureInstalledFlag(): void
    {
        if (file_exists(storage_path('installed'))) {
            return;
        }

        touch(storage_path('installed'));
        $this->createdInstalledFlag = true;
    }
}
