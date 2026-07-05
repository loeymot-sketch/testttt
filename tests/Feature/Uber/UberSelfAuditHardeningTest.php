<?php

namespace Tests\Feature\Uber;

use App\Enums\OrderStatus;
use App\Events\OrderCanceled;
use App\Models\Branch;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * [SELF-AUDIT UBER 2026-07-05] Régression des 4 défauts trouvés par l'audit adversaire de MON
 * propre diff go-live (code non-frozen que le TDD initial n'avait pas challengé adversairement) :
 *
 *  P1. queue_number = 'U'+4 derniers du display_id n'est PAS unique par commande → 2 commandes
 *      Uber du même jour aux display_id se terminant pareil COLLISIONNAIENT sur l'index UNIQUE
 *      (branch, business_date, queue_number) → INSERT 2e throw → rollback → 5×503 → 200 give-up →
 *      commande PAYÉE perdue (le bug MÊME que le go-live prétendait tuer). Fix = récupération.
 *  P2. cancelFromUber ne dispatchait PAS OrderCanceled → le décrément stock/dispo d'OrderCreated
 *      restait À VIE (article faussement épuisé borne/POS). Fix = dispatch OrderCanceled.
 *  P3a. Routage « contient fulfillment » avalait orders.fulfillment_issues.resolved (résolution,
 *      PAS annulation) → commande LIVE annulée à tort. Fix = signaux cancel EXPLICITES + noop.
 *  P3b. Aucune garde d'état terminal → un cancel tardif « ré-annulait » une commande DELIVERED
 *      (reporting faussé + 2e release stock). Fix = garde terminale.
 */
class UberSelfAuditHardeningTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'shhh-selfaudit';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();
        config()->set('uber.webhook_signing_secret', self::SECRET);
        config()->set('uber.client_id', 'cid');
        config()->set('uber.client_secret', 'csecret');
        config()->set('uber.auto_accept', true);
        config()->set('uber.branch_id', 1);
        Branch::factory()->create(['id' => 1]);
    }

    private function signedPost(array $payload)
    {
        $body = json_encode($payload);

        return $this->call('POST', '/api/webhooks/uber', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_UBER_SIGNATURE' => hash_hmac('sha256', $body, self::SECRET),
        ], $body);
    }

    private function fakeUberApis(): void
    {
        // Le MÊME détail (display_id 'GL1234') est renvoyé pour TOUTE commande → deux commandes
        // distinctes dérivent le même queue_number de base 'U1234' (reproduit la collision P1).
        $detail = [
            'display_id' => 'GL1234',
            'payment' => ['charges' => ['total' => ['amount' => 690]]],
            'cart' => ['items' => [[
                'title' => 'Tacos M',
                'quantity' => 1,
                'price' => ['unit_price' => ['amount' => 690], 'total_price' => ['amount' => 690]],
            ]]],
        ];
        Http::fake([
            'login.uber.com/*' => Http::response(['access_token' => 'TOK', 'expires_in' => 3600], 200),
            'api.uber.com/v1/eats/orders/*/accept_pos_order' => Http::response(['ok' => true], 200),
            'api.uber.com/v1/eats/orders/*' => Http::response($detail, 200),
        ]);
    }

    /** @test — P1 : deux commandes au même suffixe display_id sont TOUTES DEUX créées (jamais perdue). */
    public function deux_commandes_meme_suffixe_display_id_toutes_deux_creees(): void
    {
        $this->fakeUberApis();

        $this->signedPost(['event_id' => 'evt-c1', 'event_type' => 'orders.notification', 'meta' => ['resource_id' => 'R-C1']])->assertStatus(200);
        $this->signedPost(['event_id' => 'evt-c2', 'event_type' => 'orders.notification', 'meta' => ['resource_id' => 'R-C2']])->assertStatus(200);

        $o1 = Order::withoutGlobalScopes()->where('transaction_id', 'uber:R-C1')->first();
        $o2 = Order::withoutGlobalScopes()->where('transaction_id', 'uber:R-C2')->first();

        $this->assertNotNull($o1, '1re commande Uber créée.');
        $this->assertNotNull($o2, '2e commande Uber (même suffixe display_id) NON perdue — régression P1.');
        $this->assertSame('U1234', $o1->queue_number, 'La 1re prend le queue de base.');
        $this->assertSame('U1234-1', $o2->queue_number, 'La 2e est désambiguïsée par la boucle de récupération.');
        $this->assertNotSame($o1->queue_number, $o2->queue_number, 'Pas de collision silencieuse.');
    }

    /** @test — P1bis : le rejeu du MÊME event reste 1 seule commande (la récupération ne duplique pas). */
    public function rejeu_meme_commande_reste_unique_malgre_la_boucle(): void
    {
        $this->fakeUberApis();

        $this->signedPost(['event_id' => 'evt-r1', 'event_type' => 'orders.notification', 'meta' => ['resource_id' => 'R-UNIQ']])->assertStatus(200);
        // 2e event, MÊME resource_id → dédup d'entrée (transaction_id) : 1 seule commande.
        $this->signedPost(['event_id' => 'evt-r2', 'event_type' => 'orders.notification', 'meta' => ['resource_id' => 'R-UNIQ']])->assertStatus(200);

        $this->assertCount(1, Order::withoutGlobalScopes()->where('transaction_id', 'uber:R-UNIQ')->get(), 'Même commande Uber = 1 seule ligne interne.');
    }

    /** @test — P2 : l'annulation Uber dispatche OrderCanceled (release stock/dispo, pas de fuite). */
    public function annulation_libere_le_stock_via_order_canceled(): void
    {
        $this->fakeUberApis();
        $this->signedPost(['event_id' => 'evt-s1', 'event_type' => 'orders.notification', 'meta' => ['resource_id' => 'R-STK']])->assertStatus(200);

        Event::fake([OrderCanceled::class]);
        $this->signedPost(['event_id' => 'evt-s2', 'event_type' => 'orders.cancel', 'meta' => ['resource_id' => 'R-STK']])->assertStatus(200);

        Event::assertDispatched(OrderCanceled::class, function ($e) {
            $o = $e->order ?? null;

            return $o && $o->transaction_id === 'uber:R-STK';
        });
    }

    /** @test — P3a : orders.fulfillment_issues.resolved n'annule PAS une commande live (noop). */
    public function event_fulfillment_issue_ne_annule_pas_une_commande_live(): void
    {
        $this->fakeUberApis();
        $this->signedPost(['event_id' => 'evt-f1', 'event_type' => 'orders.notification', 'meta' => ['resource_id' => 'R-FUL']])->assertStatus(200);
        $order = Order::withoutGlobalScopes()->where('transaction_id', 'uber:R-FUL')->firstOrFail();
        $this->assertSame(OrderStatus::ACCEPT, (int) $order->status);

        $res = $this->signedPost(['event_id' => 'evt-f2', 'event_type' => 'orders.fulfillment_issues.resolved', 'meta' => ['resource_id' => 'R-FUL']]);
        $res->assertStatus(200);
        $res->assertJsonFragment(['status' => 'ack_order_event_noop']);

        $order->refresh();
        $this->assertSame(OrderStatus::ACCEPT, (int) $order->status, 'Un event fulfillment_issues NE DOIT PAS annuler la commande live.');
    }

    /** @test — R3 P2 : une annulation ARRIVÉE AVANT la création ne laisse pas de commande fantôme LIVE. */
    public function cancel_before_create_leaves_no_phantom_order(): void
    {
        $this->fakeUberApis();

        // Le cancel arrive EN PREMIER (aucune commande interne encore) → pierre tombale, ack.
        $this->signedPost(['event_id' => 'evt-cbc1', 'event_type' => 'orders.cancel', 'meta' => ['resource_id' => 'R-CBC']])->assertStatus(200);
        $this->assertSame(0, Order::withoutGlobalScopes()->where('transaction_id', 'uber:R-CBC')->count());

        // Le create (rejoué après un 503) arrive ENSUITE → il NE DOIT PAS créer (commande déjà annulée).
        $this->signedPost(['event_id' => 'evt-cbc2', 'event_type' => 'orders.notification', 'meta' => ['resource_id' => 'R-CBC']])->assertStatus(200);
        $this->assertSame(
            0,
            Order::withoutGlobalScopes()->where('transaction_id', 'uber:R-CBC')->count(),
            'Une commande annulée AVANT sa création ne doit jamais être ressuscitée en commande LIVE (stock/KDS/ré-accept).'
        );
    }

    /** @test — P3b : un cancel tardif ne « ré-annule » pas une commande déjà REMISE (garde terminale). */
    public function cancel_sur_commande_deja_remise_est_noop(): void
    {
        $this->fakeUberApis();
        $this->signedPost(['event_id' => 'evt-t1', 'event_type' => 'orders.notification', 'meta' => ['resource_id' => 'R-DLV']])->assertStatus(200);
        $order = Order::withoutGlobalScopes()->where('transaction_id', 'uber:R-DLV')->firstOrFail();
        $order->status = OrderStatus::DELIVERED;
        $order->save();

        Event::fake([OrderCanceled::class]);
        $this->signedPost(['event_id' => 'evt-t2', 'event_type' => 'orders.cancel', 'meta' => ['resource_id' => 'R-DLV']])->assertStatus(200);

        $order->refresh();
        $this->assertSame(OrderStatus::DELIVERED, (int) $order->status, 'Un cancel tardif ne ré-annule PAS une commande déjà remise.');
        Event::assertNotDispatched(OrderCanceled::class, 'Pas de 2e release stock sur une commande terminale.');
    }
}
