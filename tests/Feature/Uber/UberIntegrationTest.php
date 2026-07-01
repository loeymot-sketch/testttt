<?php

namespace Tests\Feature\Uber;

use App\Services\Uber\UberClient;
use App\Services\Uber\UberOrderMapper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * [UBER-EATS 2026-07-01] Couverture de l'intégration Uber Eats (OAuth, signature webhook, mapping).
 * Ne touche PAS l'API réelle (Http::fake). Le flux live sera validé quand Uber accorde l'accès.
 */
class UberIntegrationTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function oauth_token_est_recupere_et_mis_en_cache(): void
    {
        config()->set('uber.client_id', 'cid');
        config()->set('uber.client_secret', 'csecret');

        Http::fake([
            'login.uber.com/*' => Http::response(['access_token' => 'TOK123', 'expires_in' => 2592000], 200),
        ]);

        $token = app(UberClient::class)->accessToken();
        $this->assertSame('TOK123', $token);
        // 2e appel = cache (pas de 2e requête OAuth)
        $this->assertSame('TOK123', app(UberClient::class)->accessToken());
        Http::assertSentCount(1);
    }

    /** @test */
    public function webhook_refuse_une_signature_invalide(): void
    {
        config()->set('uber.webhook_signing_secret', 'shhh');

        $res = $this->postJson('/api/webhooks/uber', ['event_id' => 'x'], [
            'X-Uber-Signature' => 'mauvaise_signature',
        ]);

        $res->assertStatus(401)->assertJson(['status' => 'invalid_signature']);
    }

    /** @test */
    public function webhook_refuse_si_aucun_secret_configure(): void
    {
        config()->set('uber.webhook_signing_secret', '');

        $res = $this->postJson('/api/webhooks/uber', ['event_id' => 'x'], [
            'X-Uber-Signature' => hash_hmac('sha256', '{}', 'peu-importe'),
        ]);

        $res->assertStatus(401); // fail-closed
    }

    /** @test */
    public function webhook_accepte_une_signature_valide_et_est_idempotent(): void
    {
        config()->set('uber.webhook_signing_secret', 'shhh');
        config()->set('uber.client_id', 'cid');
        config()->set('uber.client_secret', 'csecret');
        config()->set('uber.auto_accept', false);

        // Uber n'enverra qu'un event non-commande ici (ack simple) pour isoler la signature+idempotence.
        $body = json_encode(['event_id' => 'evt-1', 'event_type' => 'stores.provisioned', 'meta' => ['resource_id' => 'r1']]);
        $sig = hash_hmac('sha256', $body, 'shhh');

        $first = $this->call('POST', '/api/webhooks/uber', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_UBER_SIGNATURE' => $sig,
        ], $body);
        $first->assertStatus(200);

        // rejeu → idempotent (déjà processed)
        $second = $this->call('POST', '/api/webhooks/uber', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_UBER_SIGNATURE' => $sig,
        ], $body);
        $second->assertStatus(200)->assertJson(['status' => 'already_processed']);

        $this->assertDatabaseHas('webhook_events', ['provider' => 'uber_eats', 'webhook_id' => 'evt-1', 'status' => 'processed']);
    }

    /** @test */
    public function le_mapper_convertit_une_commande_uber_en_snapshot(): void
    {
        $uberOrder = [
            'display_id' => 'AB1234',
            'payment' => ['charges' => ['total' => ['amount' => 1290]]],
            'cart' => ['items' => [[
                'title' => 'Tacos M',
                'quantity' => 2,
                'price' => ['unit_price' => ['amount' => 690], 'total_price' => ['amount' => 1380]],
                'selected_modifier_groups' => [[
                    'selected_items' => [['title' => 'Cheddar', 'quantity' => 1, 'price' => ['amount' => 90]]],
                ]],
            ]]],
        ];

        $mapped = app(UberOrderMapper::class)->map($uberOrder);

        $this->assertSame(12.90, $mapped['total']);
        $this->assertCount(1, $mapped['items']);
        $line = $mapped['items'][0];
        $this->assertSame('Tacos M', $line['name']);
        $this->assertSame(2, $line['quantity']);
        $this->assertSame('uber_eats', $line['composition_snapshot']['source']);
        $this->assertSame('Cheddar', $line['composition_snapshot']['extras'][0]['extra_name']);
        $this->assertSame(0.90, $line['composition_snapshot']['extras'][0]['line_total']);
    }
}
