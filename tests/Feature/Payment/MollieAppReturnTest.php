<?php

namespace Tests\Feature\Payment;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentGateway;
use App\Enums\PaymentStatus;
use App\Enums\Source;
use App\Events\OrderCreated;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use App\Models\WebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * [GOAL APP HYBRIDE · W4 · 2026-11-03] Paiement DANS l'application des stores.
 *
 * L'application (site empaqueté Capacitor) ne peut pas faire revenir le client d'une
 * authentification forte DANS sa vue web (app/PUBLICATION.md §9). Elle ouvre donc la page
 * Mollie HÉBERGÉE dans le navigateur du système (SFSafariViewController / Custom Tabs), et
 * Mollie doit renvoyer le client vers une page qui ROUVRE l'application — pas vers le site.
 *
 * Ce que la suite verrouille, par ordre d'importance (argent = P0) :
 *  1. la page de retour de l'application NE CHANGE AUCUN STATUT : elle ne lit même pas la base.
 *     Seul le webhook (vérité re-lue chez Mollie) fait foi, y compris quand le client revient
 *     avant lui, deux fois, ou jamais ;
 *  2. `client=app` envoie à Mollie la page de retour de l'APPLICATION ; le site garde EXACTEMENT
 *     son retour actuel (absent ou `web`) ; toute autre valeur est refusée AVANT tout appel Mollie ;
 *  3. un interrupteur serveur (`app_mobile.online_payment_enabled`, FAUX par défaut) coupe le
 *     paiement dans l'application à distance — publié par /api/frontend/app/config ET appliqué
 *     par le serveur lui-même (une application qui l'ignorerait est refusée quand même).
 *
 * Suite 100 % MOCKÉE : `Http::preventStrayRequests()` fait échouer le test si une requête non
 * simulée part — aucune requête ne peut atteindre api.mollie.com.
 */
class MollieAppReturnTest extends TestCase
{
    use RefreshDatabase;

    private bool $createdInstalledFlag = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
        $this->ensureInstalledFlag();
        Http::preventStrayRequests();
        Config::set('payment.mollie.enabled', true);
        Config::set('payment.mollie.api_key', 'test_dummyMollieKey123');
        Config::set('payment.mollie.redirect_url', 'https://www.lecayenne.fr/');
        Config::set('app.url', 'https://api.exemple-cayenne.test');
        Config::set('app_mobile.online_payment_enabled', true);
    }

    protected function tearDown(): void
    {
        if ($this->createdInstalledFlag && file_exists(storage_path('installed'))) {
            unlink(storage_path('installed'));
        }
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // 1. Création du paiement : bon retour selon le client
    // ------------------------------------------------------------------

    public function test_client_app_envoie_a_mollie_la_page_de_retour_de_l_application(): void
    {
        [$customer, $order] = $this->webCardOrder(['total' => 13.70, 'subtotal' => 13.70]);
        $this->fakeCreation('tr_APPRET01', $order->id, '13.70');

        $this->actingAs($customer, 'sanctum')
            ->postJson("/api/frontend/order/{$order->id}/mollie-checkout", ['client' => 'app', 'amount' => '0.01'])
            ->assertOk()
            ->assertJsonPath('status', true)
            ->assertJsonPath('reason', 'hosted')
            ->assertJsonPath('inline', false)
            ->assertJsonPath('checkout_url', 'https://www.mollie.com/checkout/select-method/tr_APPRET01');

        Http::assertSent(function (ClientRequest $request) use ($order): bool {
            $body = $request->data();

            return str_ends_with($request->url(), '/v2/payments')
                && ($body['redirectUrl'] ?? null) === 'https://api.exemple-cayenne.test/paiement/retour-app?order=' . $order->id
                // le webhook ne change pas : c'est lui, et lui seul, qui paie la commande
                && str_contains((string) ($body['webhookUrl'] ?? ''), '/api/webhook/mollie')
                // montant = total scellé backend, jamais le 0.01 du client
                && ($body['amount']['value'] ?? null) === '13.70'
                && ($body['metadata']['order_id'] ?? null) === (string) $order->id
                // page hébergée : ni jeton carte ni moyen imposé
                && ! array_key_exists('cardToken', $body)
                && ! array_key_exists('method', $body);
        });
        Http::assertSentCount(1);

        $fresh = $order->fresh();
        $this->assertSame(PaymentStatus::UNPAID, (int) $fresh->payment_status, 'créer un paiement ne paie rien');
        $this->assertNull($fresh->fiscal_sequence_no);
    }

    public function test_le_site_garde_exactement_son_retour_actuel_client_absent(): void
    {
        $this->assertRetourSite(null);
    }

    public function test_le_site_garde_exactement_son_retour_actuel_client_web(): void
    {
        $this->assertRetourSite('web');
    }

    public function test_le_retour_site_retombe_sur_app_url_si_aucun_retour_configure(): void
    {
        Config::set('payment.mollie.redirect_url', '');
        [$customer, $order] = $this->webCardOrder();
        $this->fakeCreation('tr_WEBRET03', $order->id, '11.80');

        $this->actingAs($customer, 'sanctum')
            ->postJson("/api/frontend/order/{$order->id}/mollie-checkout")
            ->assertOk();

        Http::assertSent(fn (ClientRequest $r): bool => ($r->data()['redirectUrl'] ?? null)
            === 'https://api.exemple-cayenne.test?order=' . $order->id);
    }

    public function test_une_valeur_de_client_hors_liste_est_refusee_sans_appeler_mollie(): void
    {
        [$customer, $order] = $this->webCardOrder();
        Http::fake();

        foreach (['ios', 'android', 'APP', 'kiosk', 'fr.lecayenne.app://x', 'https://evil.test'] as $valeur) {
            $this->actingAs($customer, 'sanctum')
                ->postJson("/api/frontend/order/{$order->id}/mollie-checkout", ['client' => $valeur])
                ->assertStatus(422)
                ->assertJsonPath('status', false)
                ->assertJsonPath('message', 'Client de paiement inconnu.');
        }
        foreach ([['app'], 42, true] as $valeur) {
            $this->actingAs($customer, 'sanctum')
                ->postJson("/api/frontend/order/{$order->id}/mollie-checkout", ['client' => $valeur])
                ->assertStatus(422);
        }

        Http::assertNothingSent();
        $this->assertSame(PaymentStatus::UNPAID, (int) $order->fresh()->payment_status);
    }

    public function test_l_application_ne_passe_que_par_la_page_hebergee(): void
    {
        [$customer, $order] = $this->webCardOrder();
        Http::fake();

        foreach ([['card_token' => 'tkn_inline_abc12345'], ['method' => 'applepay'], ['method' => 'googlepay']] as $extra) {
            $this->actingAs($customer, 'sanctum')
                ->postJson("/api/frontend/order/{$order->id}/mollie-checkout", ['client' => 'app'] + $extra)
                ->assertStatus(422)
                ->assertJsonPath('status', false);
        }

        Http::assertNothingSent();
    }

    // ------------------------------------------------------------------
    // 2. Interrupteur serveur
    // ------------------------------------------------------------------

    public function test_la_configuration_publie_le_paiement_dans_l_application_coupe_par_defaut(): void
    {
        // Valeur RÉELLE du fichier de configuration (et non celle posée par setUp).
        Config::set('app_mobile.online_payment_enabled', (require base_path('config/app_mobile.php'))['online_payment_enabled']);

        config(['app.api_key' => '123456']);
        $this->withHeader('x-api-key', '123456')
            ->getJson('/api/frontend/app/config')
            ->assertOk()
            ->assertJsonPath('online_payment_enabled', false)
            // la version minimale reste publiée à côté (contrat existant)
            ->assertJsonStructure(['min_version', 'message', 'ios_url', 'android_url', 'online_payment_enabled']);
    }

    public function test_la_configuration_publie_le_paiement_dans_l_application_quand_il_est_ouvert(): void
    {
        config(['app.api_key' => '123456']);
        $this->withHeader('x-api-key', '123456')
            ->getJson('/api/frontend/app/config')
            ->assertOk()
            ->assertJsonPath('online_payment_enabled', true);
    }

    public function test_drapeau_coupe_le_serveur_refuse_le_paiement_de_l_application_sans_appeler_mollie(): void
    {
        Config::set('app_mobile.online_payment_enabled', false);
        [$customer, $order] = $this->webCardOrder();
        Http::fake();

        $this->actingAs($customer, 'sanctum')
            ->postJson("/api/frontend/order/{$order->id}/mollie-checkout", ['client' => 'app'])
            ->assertStatus(503)
            ->assertJsonPath('status', false)
            ->assertJsonPath('message', "Le paiement dans l'application est désactivé.");

        Http::assertNothingSent();
    }

    public function test_drapeau_coupe_le_site_n_est_pas_concerne(): void
    {
        Config::set('app_mobile.online_payment_enabled', false);
        [$customer, $order] = $this->webCardOrder();
        $this->fakeCreation('tr_WEBOFF01', $order->id, '11.80');

        $this->actingAs($customer, 'sanctum')
            ->postJson("/api/frontend/order/{$order->id}/mollie-checkout")
            ->assertOk()
            ->assertJsonPath('status', true);
    }

    // ------------------------------------------------------------------
    // 3. Page de retour de l'application
    // ------------------------------------------------------------------

    public function test_la_page_de_retour_rouvre_l_application_et_ne_change_aucun_statut(): void
    {
        [, $order] = $this->webCardOrder(['total' => 21.40, 'subtotal' => 21.40]);
        $etat = fn (): array => $order->fresh()->only(['status', 'payment_status', 'transaction_id', 'fiscal_sequence_no'])
            + ['updated_at' => (string) $order->fresh()->getRawOriginal('updated_at')];
        $avant = $etat();

        $r = $this->get('/paiement/retour-app?order=' . $order->id);

        $r->assertOk()
            ->assertSee('fr.lecayenne.app://paiement/retour?order=' . $order->id, false)
            ->assertSee("Revenir dans l'application", false)
            ->assertHeader('Cache-Control')
            ->assertHeader('Referrer-Policy', 'no-referrer');
        $this->assertStringContainsString('no-store', (string) $r->headers->get('Cache-Control'));
        $this->assertStringContainsString('noindex', (string) $r->headers->get('X-Robots-Tag'));
        // Aucune donnée de la commande : ni montant, ni nom, ni statut « payé ».
        $r->assertDontSee('21,40', false)->assertDontSee('21.40', false);
        $this->assertDoesNotMatchRegularExpression('/pay[ée]e?\b/iu', strip_tags((string) $r->getContent()));

        $this->assertSame($avant, $etat(), 'la page de retour ne doit RIEN écrire sur la commande');
        $this->assertSame(0, WebhookEvent::query()->count());
        Http::assertNothingSent();
    }

    public function test_la_page_de_retour_n_injecte_jamais_un_identifiant_non_numerique(): void
    {
        foreach (['abc', '12"><script>alert(1)</script>', '-5', '1e3', '12&x=1', str_repeat('9', 30)] as $valeur) {
            $r = $this->get('/paiement/retour-app?order=' . rawurlencode($valeur));
            $r->assertOk()->assertSee('fr.lecayenne.app://paiement/retour', false);
            $this->assertStringNotContainsString('<script>alert', (string) $r->getContent());
            $this->assertStringNotContainsString('retour?order=', (string) $r->getContent(), $valeur);
        }
        // sans paramètre : même page, sans identifiant
        $this->get('/paiement/retour-app')->assertOk()->assertSee("Revenir dans l'application", false);
        Http::assertNothingSent();
    }

    public function test_le_webhook_seul_fait_foi_meme_quand_le_client_revient_avant_lui(): void
    {
        [, $order] = $this->webCardOrder(['total' => 11.80]);
        Event::fake([OrderCreated::class]);

        // Le client revient AVANT le webhook : la commande reste impayée.
        $this->get('/paiement/retour-app?order=' . $order->id)->assertOk();
        $this->assertSame(PaymentStatus::UNPAID, (int) $order->fresh()->payment_status);
        $this->assertNull($order->fresh()->fiscal_sequence_no);

        // Le webhook arrive, la vérité est re-lue chez Mollie : payée.
        Http::fake(['https://api.mollie.com/v2/payments/tr_APPWH001' => Http::response(
            $this->molliePaymentPayload('tr_APPWH001', $order->id, 'paid', '11.80'), 200)]);
        $this->postJson('/api/webhook/mollie', ['id' => 'tr_APPWH001'])
            ->assertOk()->assertJsonPath('status', 'paid_confirmed');

        $fresh = $order->fresh();
        $this->assertSame(PaymentStatus::PAID, (int) $fresh->payment_status);
        $this->assertSame('mollie:tr_APPWH001', (string) $fresh->transaction_id);
        $this->assertNotNull($fresh->fiscal_sequence_no);
    }

    public function test_un_double_retour_et_un_webhook_rejoue_ne_produisent_qu_un_seul_effet(): void
    {
        [, $order] = $this->webCardOrder(['total' => 11.80]);
        Event::fake([OrderCreated::class]);
        Http::fake(['https://api.mollie.com/v2/payments/tr_APPDBL01' => Http::response(
            $this->molliePaymentPayload('tr_APPDBL01', $order->id, 'paid', '11.80'), 200)]);

        $this->get('/paiement/retour-app?order=' . $order->id)->assertOk();
        $this->postJson('/api/webhook/mollie', ['id' => 'tr_APPDBL01'])->assertOk()->assertJsonPath('status', 'paid_confirmed');
        $apres = $order->fresh()->only(['status', 'payment_status', 'transaction_id', 'fiscal_sequence_no']);

        // Deuxième retour (le client touche « Revenir dans l'application » une seconde fois) + rejeu Mollie.
        $this->get('/paiement/retour-app?order=' . $order->id)->assertOk();
        $this->get('/paiement/retour-app?order=' . $order->id)->assertOk();
        $this->postJson('/api/webhook/mollie', ['id' => 'tr_APPDBL01'])->assertOk()->assertJsonPath('status', 'duplicate_ignored');

        $this->assertSame($apres, $order->fresh()->only(['status', 'payment_status', 'transaction_id', 'fiscal_sequence_no']));
        $this->assertSame(1, WebhookEvent::query()->where('webhook_id', 'tr_APPDBL01:paid')->count());
        Http::assertSentCount(2); // deux re-lectures Mollie (une par webhook), zéro par la page de retour
    }

    // ------------------------------------------------------------------
    // Fixtures (miroir MollieWalletMethodTest)
    // ------------------------------------------------------------------

    private function assertRetourSite(?string $client): void
    {
        [$customer, $order] = $this->webCardOrder();
        $this->fakeCreation('tr_WEBRET0' . ($client === null ? '1' : '2'), $order->id, '11.80');
        $corps = $client === null ? [] : ['client' => $client];

        $this->actingAs($customer, 'sanctum')
            ->postJson("/api/frontend/order/{$order->id}/mollie-checkout", $corps)
            ->assertOk()
            ->assertJsonPath('reason', 'hosted');

        Http::assertSent(function (ClientRequest $request) use ($order): bool {
            $body = $request->data();

            return ($body['redirectUrl'] ?? null) === 'https://www.lecayenne.fr/?order=' . $order->id
                && ! str_contains((string) ($body['redirectUrl'] ?? ''), 'retour-app');
        });
    }

    private function fakeCreation(string $paymentId, int $orderId, string $value): void
    {
        Http::fake([
            'https://api.mollie.com/v2/payments' => Http::response(
                $this->molliePaymentPayload($paymentId, $orderId, 'open', $value),
                201
            ),
        ]);
    }

    /** @return array{0: User, 1: Order} */
    private function webCardOrder(array $overrides = []): array
    {
        $branch = Branch::factory()->create();
        $customer = User::factory()->create(['branch_id' => $branch->id]);

        $order = Order::factory()->create($overrides + [
            'branch_id' => $branch->id,
            'user_id' => $customer->id,
            'order_type' => OrderType::TAKEAWAY,
            'source' => Source::WEB,
            'source_surface' => 'web',
            'payment_method' => PaymentGateway::CARD,
            'payment_status' => PaymentStatus::UNPAID,
            'status' => OrderStatus::PENDING,
            'transaction_id' => null,
            'fiscal_sequence_no' => null,
            'subtotal' => 11.80,
            'total' => 11.80,
        ]);

        return [$customer, $order];
    }

    private function molliePaymentPayload(string $id, int $orderId, string $status, string $value): array
    {
        return [
            'resource' => 'payment',
            'id' => $id,
            'mode' => 'test',
            'status' => $status,
            'amount' => ['value' => $value, 'currency' => 'EUR'],
            'description' => 'Le Cayenne — Commande test',
            'metadata' => ['order_id' => (string) $orderId],
            '_links' => [
                'checkout' => ['href' => 'https://www.mollie.com/checkout/select-method/' . $id],
            ],
        ];
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
