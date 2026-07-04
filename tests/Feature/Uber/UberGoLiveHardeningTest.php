<?php

namespace Tests\Feature\Uber;

use App\Events\OrderCreated;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\Uber\UberClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * [ULTRA GO-LIVE UBER 2026-07-04] Durcissement pré-activation — les 6 défauts confirmés par les
 * Waves 2/3/5 (adversaire + verify), tous non-frozen, implémentés+testés EN BLOC (le webhook est
 * fail-closed en prod tant que le secret n'est pas posé → zéro risque live) :
 *
 *  1. Article Uber non mappé → la commande PAYÉE ne doit JAMAIS être perdue (avant : item_id NULL
 *     → violation NOT NULL → rollback → 5×503 → 200 give-up → commande invisible cuisine).
 *  2. Dédup commande : business_date posé → l'index UNIQUE (branch, business_date, queue_number)
 *     devient un vrai verrou anti-doublon concurrent (avant : business_date NULL = NULLs distincts).
 *  3. is_advance_order=NO explicite (avant : défaut DB YES → KDS affichait « demain »).
 *  4. OrderCreated dispatché → décrément stock + broadcast temps réel KDS/OSS/caisse
 *     (avant : survente silencieuse — un article épuisé sur Uber restait dispo borne/POS).
 *  5. Les events d'ANNULATION n'appellent plus createFromUber+acceptOrder : commande interne
 *     → CANCELED (avant : la cuisine préparait une commande annulée, ré-acceptée côté Uber).
 *  6. Token OAuth : 401 → invalidation cache + refresh + retry (avant : token révoqué collé
 *     jusqu'à 30 j → toutes les commandes perdues pendant la fenêtre).
 */
class UberGoLiveHardeningTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'shhh-golive';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();
        config()->set('uber.webhook_signing_secret', self::SECRET);
        config()->set('uber.client_id', 'cid');
        config()->set('uber.client_secret', 'csecret');
        config()->set('uber.auto_accept', true);
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

    private function fakeUberApis(array $orderDetail): void
    {
        Http::fake([
            'login.uber.com/*' => Http::response(['access_token' => 'TOK', 'expires_in' => 3600], 200),
            'api.uber.com/v1/eats/orders/*/accept_pos_order' => Http::response(['ok' => true], 200),
            'api.uber.com/v1/eats/orders/*' => Http::response($orderDetail, 200),
        ]);
    }

    private function uberDetail(string $title = 'Tacos M', int $unitCents = 690): array
    {
        return [
            'display_id' => 'GL1234',
            'payment' => ['charges' => ['total' => ['amount' => $unitCents]]],
            'cart' => ['items' => [[
                'title' => $title,
                'quantity' => 1,
                'price' => ['unit_price' => ['amount' => $unitCents], 'total_price' => ['amount' => $unitCents]],
            ]]],
        ];
    }

    /** @test — 1. la commande PAYÉE survit à un article non mappé (dégradation gracieuse). */
    public function commande_payee_jamais_perdue_sur_article_non_mappe(): void
    {
        // Titre introuvable au catalogue + map vide → resolveItemId NULL avant le fix.
        $this->fakeUberApis($this->uberDetail('ZZZ Menu Maxi Uber Special 999', 1490));

        $res = $this->signedPost(['event_id' => 'evt-gl1', 'event_type' => 'orders.notification', 'meta' => ['resource_id' => 'R-GL1']]);
        $res->assertStatus(200);

        $order = Order::withoutGlobalScopes()->where('transaction_id', 'uber:R-GL1')->first();
        $this->assertNotNull($order, 'La commande PAYÉE doit être créée même avec un article non mappé (jamais perdue).');

        $line = OrderItem::withoutGlobalScopes()->where('order_id', $order->id)->first();
        $this->assertNotNull($line, 'La ligne doit exister (ancrée sur l\'item placeholder technique).');
        $this->assertNotNull($line->item_id, 'item_id ancré (placeholder), pas NULL.');
        $snap = is_string($line->composition_snapshot) ? json_decode($line->composition_snapshot, true) : $line->composition_snapshot;
        $this->assertSame('ZZZ Menu Maxi Uber Special 999', $snap['uber_title'] ?? null, 'Le titre Uber réel est préservé dans le snapshot (cuisine le voit).');
        $this->assertStringContainsString('ZZZ Menu Maxi', (string) $line->instruction, 'Le titre réel est visible en instruction cuisine.');
    }

    /** @test — 2+3. dédup 2 events même commande + business_date/is_advance_order posés. */
    public function dedup_deux_events_meme_commande_et_champs_kds_poses(): void
    {
        $this->fakeUberApis($this->uberDetail());

        $this->signedPost(['event_id' => 'evt-a', 'event_type' => 'orders.notification', 'meta' => ['resource_id' => 'R-DUP']])->assertStatus(200);
        $this->signedPost(['event_id' => 'evt-b', 'event_type' => 'orders.notification', 'meta' => ['resource_id' => 'R-DUP']])->assertStatus(200);

        $orders = Order::withoutGlobalScopes()->where('transaction_id', 'uber:R-DUP')->get();
        $this->assertCount(1, $orders, 'Deux events (event_id distincts) pour la MÊME commande Uber = 1 seule commande interne.');

        $o = $orders->first();
        $this->assertSame(\App\Enums\Ask::NO, (int) $o->is_advance_order, 'Commande Uber = immédiate, PAS une précommande (KDS n\'affiche plus « demain »).');
        $this->assertNotNull($o->business_date, 'business_date posé → l\'index UNIQUE (branch,business_date,queue_number) verrouille les doublons concurrents.');
    }

    /** @test — 4. OrderCreated dispatché (stock + temps réel). */
    public function order_created_est_dispatche_pour_stock_et_temps_reel(): void
    {
        Event::fake([OrderCreated::class]);
        $this->fakeUberApis($this->uberDetail());

        $this->signedPost(['event_id' => 'evt-oc', 'event_type' => 'orders.notification', 'meta' => ['resource_id' => 'R-OC']])->assertStatus(200);

        Event::assertDispatched(OrderCreated::class, function ($e) {
            $order = $e->order ?? null;
            return $order && $order->source_surface === 'uber_eats';
        });
    }

    /** @test — 4bis. le dédup (2e event) ne re-dispatche PAS OrderCreated. */
    public function le_dedup_ne_redispatche_pas_order_created(): void
    {
        $this->fakeUberApis($this->uberDetail());
        $this->signedPost(['event_id' => 'evt-d1', 'event_type' => 'orders.notification', 'meta' => ['resource_id' => 'R-RD']])->assertStatus(200);

        Event::fake([OrderCreated::class]);
        $this->signedPost(['event_id' => 'evt-d2', 'event_type' => 'orders.notification', 'meta' => ['resource_id' => 'R-RD']])->assertStatus(200);
        Event::assertNotDispatched(OrderCreated::class);
    }

    /** @test — 5. un event d'annulation annule la commande interne, ne crée rien, ne ré-accepte pas. */
    public function event_annulation_annule_sans_creer_ni_reaccepter(): void
    {
        $this->fakeUberApis($this->uberDetail());

        // Création normale.
        $this->signedPost(['event_id' => 'evt-c1', 'event_type' => 'orders.notification', 'meta' => ['resource_id' => 'R-CXL']])->assertStatus(200);
        $order = Order::withoutGlobalScopes()->where('transaction_id', 'uber:R-CXL')->firstOrFail();
        $acceptCallsAfterCreate = collect(Http::recorded())->filter(fn ($pair) => str_contains($pair[0]->url(), 'accept_pos_order'))->count();

        // Annulation Uber.
        $this->signedPost(['event_id' => 'evt-c2', 'event_type' => 'orders.cancel', 'meta' => ['resource_id' => 'R-CXL']])->assertStatus(200);

        $order->refresh();
        $this->assertSame(\App\Enums\OrderStatus::CANCELED, (int) $order->status, 'La commande interne passe CANCELED (la cuisine ne prépare plus une commande annulée).');
        $this->assertCount(1, Order::withoutGlobalScopes()->where('transaction_id', 'uber:R-CXL')->get(), 'Aucune commande créée par l\'event cancel.');

        $acceptCallsAfterCancel = collect(Http::recorded())->filter(fn ($pair) => str_contains($pair[0]->url(), 'accept_pos_order'))->count();
        $this->assertSame($acceptCallsAfterCreate, $acceptCallsAfterCancel, 'L\'event cancel ne doit PAS ré-appeler acceptOrder côté Uber.');
    }

    /** @test — 5bis. annulation d'une commande INCONNUE → ack simple, rien créé. */
    public function annulation_commande_inconnue_ack_sans_creation(): void
    {
        $this->fakeUberApis($this->uberDetail());
        $this->signedPost(['event_id' => 'evt-cu', 'event_type' => 'orders.cancel', 'meta' => ['resource_id' => 'R-GHOST']])->assertStatus(200);
        $this->assertSame(0, Order::withoutGlobalScopes()->where('transaction_id', 'uber:R-GHOST')->count());
    }

    /** @test — 6. token 401 → cache invalidé + refresh + retry (l'intégration ne reste plus bloquée). */
    public function token_401_invalide_le_cache_et_retente(): void
    {
        Cache::put('uber_eats_access_token', 'STALE-TOKEN', 3600);
        Http::fake([
            'login.uber.com/*' => Http::response(['access_token' => 'FRESH', 'expires_in' => 3600], 200),
            'api.uber.com/*' => Http::sequence()
                ->push(['error' => 'unauthorized'], 401)   // 1er appel avec STALE → 401
                ->push($this->uberDetail(), 200),           // retry avec FRESH → 200
        ]);

        $detail = app(UberClient::class)->fetchOrder('R-401');
        $this->assertIsArray($detail, 'Après un 401, le client doit rafraîchir le token et réussir le retry.');
        $this->assertSame('FRESH', Cache::get('uber_eats_access_token'), 'Le token périmé est remplacé en cache.');
    }
}
