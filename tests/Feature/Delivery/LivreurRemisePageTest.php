<?php

namespace Tests\Feature\Delivery;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Delivery\Concerns\HandoverFixtures;
use Tests\TestCase;

/**
 * [LIVRAISON QR 2026-11-01] La page /livreur/remise : statique, identique pour tous, sans donnée, qui ne garde rien.
 *
 * @group delivery
 */
class LivreurRemisePageTest extends TestCase
{
    use RefreshDatabase;
    use HandoverFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpHandover();
    }

    protected function tearDown(): void
    {
        $this->tearDownHandover();
        parent::tearDown();
    }

    public function test_the_page_is_identical_whatever_the_token_and_never_cached_or_leaked(): void
    {
        $stop = $this->departed($this->delivery());
        $qr = $this->qrToken($stop);

        // Le fragment n'est jamais envoyé au serveur : on simule néanmoins des chemins/requêtes hostiles.
        $plain = $this->get('/livreur/remise');
        $withQuery = $this->get('/livreur/remise?token='.$qr);
        $plain->assertOk();
        $this->assertSame($plain->getContent(), $withQuery->getContent(), 'la réponse ne dépend d\'AUCUN paramètre');

        $h = $plain->headers;
        $this->assertStringContainsString('no-store', (string) $h->get('Cache-Control'));
        $this->assertSame('no-referrer', $h->get('Referrer-Policy'));
        $this->assertStringContainsString('noindex', (string) $h->get('X-Robots-Tag'));
        $this->assertSame('DENY', $h->get('X-Frame-Options'));

        $c = $plain->getContent();
        foreach (['Karim', '0612345678', 'Rue Test', $qr, 'll=50.43'] as $leak) {
            $this->assertStringNotContainsString($leak, $c, "la page ne contient aucune donnée de commande ($leak)");
        }
        $this->assertStringContainsString('noindex', $c);
        $this->assertStringContainsString('name="referrer" content="no-referrer"', $c);
    }

    public function test_the_script_clears_the_fragment_and_never_reads_any_stored_session(): void
    {
        $c = $this->get('/livreur/remise')->getContent();

        $this->assertStringContainsString('history.replaceState', $c);
        $this->assertStringContainsString('location.hash', $c);
        // Elle ne lit AUCUN stockage : ni la session /livreur (lc_livreur_token), ni localStorage/sessionStorage/cookies.
        foreach (['lc_livreur_token', 'localStorage', 'sessionStorage', 'document.cookie', 'indexedDB'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $c, "la page ne doit pas toucher à $forbidden");
        }
        // Redirection FIXE, jamais construite depuis l'URL ou la réponse.
        $this->assertStringContainsString("var NEXT = '/livreur';", $c);
        $this->assertStringNotContainsString('location.href =', $c);
        $this->assertSame(0, preg_match('/location\.(replace|assign)\((?!NEXT\))/', $c), 'seule la cible NEXT est utilisée pour naviguer');
        // Le jeton est validé par regex avant tout usage.
        $this->assertStringContainsString('/^[A-Za-z0-9_-]{43}$/', $c);
        // Aucune insertion HTML sans échappement du jeton lui-même.
        $this->assertStringNotContainsString('esc(qr)', $c);
        $this->assertStringNotContainsString('+ qr +', $c);
    }

    public function test_the_page_calls_only_the_dedicated_handover_endpoints(): void
    {
        $c = $this->get('/livreur/remise')->getContent();

        $this->assertStringContainsString('/api/frontend/delivery-handover/', $c);
        $this->assertStringNotContainsString('/api/auth/login', $c, 'pas la connexion générique');
        $this->assertStringNotContainsString('delivery-run', $c);
    }
}
