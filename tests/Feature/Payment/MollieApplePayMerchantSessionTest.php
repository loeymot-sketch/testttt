<?php

namespace Tests\Feature\Payment;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use App\Models\User;
use Tests\TestCase;

/**
 * Apple Pay est affiché sur www.lecayenne.fr, mais l'API vit sur le VPS.
 * La session marchand DOIT donc déclarer le domaine public au prestataire :
 * transmettre l'hôte de la requête API fait apparaître puis refermer la feuille.
 */
class MollieApplePayMerchantSessionTest extends TestCase
{
    use RefreshDatabase;

    private bool $flagCree = false;

    protected function setUp(): void
    {
        parent::setUp();
        if (! file_exists(storage_path('installed'))) {
            touch(storage_path('installed'));
            $this->flagCree = true;
        }
        Config::set('payment.mollie.enabled', true);
        Config::set('payment.mollie.api_key', 'test_cleFactice1234567890');
        Config::set('payment.mollie.apple_pay_domain', 'www.lecayenne.fr');
        $this->seedSpatieRoles();
        $this->actingAs(User::factory()->create(), 'sanctum');
    }

    protected function tearDown(): void
    {
        if ($this->flagCree && file_exists(storage_path('installed'))) {
            unlink(storage_path('installed'));
        }
        parent::tearDown();
    }

    public function test_merchant_session_uses_the_public_checkout_domain_not_the_api_host(): void
    {
        Http::fake(['api.mollie.com/*' => Http::response(['merchantSessionIdentifier' => 'session-test'], 201)]);

        $this->withServerVariables(['HTTP_HOST' => 'vps-418872ac.vps.ovh.net'])
            ->postJson('/api/frontend/order/applepay-session', [
                'validation_url' => 'https://apple-pay-gateway.apple.com/paymentservices/paymentSession',
            ])
            ->assertOk()
            ->assertJsonPath('session.merchantSessionIdentifier', 'session-test');

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.mollie.com/v2/wallets/applepay/sessions'
                && $request['domain'] === 'www.lecayenne.fr';
        });
    }

    public function test_invalid_public_domain_fails_without_contacting_mollie(): void
    {
        Config::set('payment.mollie.apple_pay_domain', 'not a domain');
        Http::fake();

        $this->postJson('/api/frontend/order/applepay-session', [
            'validation_url' => 'https://apple-pay-gateway.apple.com/paymentservices/paymentSession',
        ])->assertStatus(503);

        Http::assertNothingSent();
    }
}
